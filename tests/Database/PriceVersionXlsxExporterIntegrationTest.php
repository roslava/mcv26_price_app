<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Database;

use Mcv26\Price\Admin\ArchivedVersionRestorer;
use Mcv26\Price\Admin\PriceVersionXlsxExporter;
use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\MigrationRunner;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Exception\PriceVersionExportException;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\UploadValidator;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PriceVersionXlsxExporterIntegrationTest extends TestCase
{
    private PDO $pdo;
    private DatabasePriceRepository $repository;
    private PriceVersionXlsxExporter $exporter;
    /** @var list<string> */
    private array $temporaryFiles = [];
    /** @var array<string, mixed> */
    private array $realData;

    protected function setUp(): void
    {
        $dsn = getenv('MCV26_TEST_DB_DSN');
        if (!is_string($dsn) || $dsn === '' || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Set MCV26_TEST_DB_DSN and enable pdo_mysql to run MySQL integration tests.');
        }
        $this->pdo = PdoConnectionFactory::create(new DatabaseConfig(
            $dsn,
            (string) (getenv('MCV26_TEST_DB_USER') ?: ''),
            (string) (getenv('MCV26_TEST_DB_PASSWORD') ?: '')
        ));
        (new MigrationRunner($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec('DELETE FROM price_versions');
        $this->repository = new DatabasePriceRepository($this->pdo);
        $this->exporter = new PriceVersionXlsxExporter($this->repository);
        $this->realData = (new PriceImporter(new UploadValidator()))->import(
            dirname(__DIR__, 2) . '/storage/uploads/current.xlsx'
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
    }

    public function testRealGraphRoundTripsSavedPricesAndWorkbookStructure(): void
    {
        $versionId = $this->createRealVersion();
        $serviceIds = $this->pdo->query('SELECT id FROM services ORDER BY id LIMIT 4')->fetchAll(PDO::FETCH_COLUMN);
        $savedPrices = [47000, 37050, 1, 99999999];
        $update = $this->pdo->prepare('UPDATE services SET current_price_minor=? WHERE id=?');
        foreach ($savedPrices as $offset => $price) {
            $update->execute([$price, $serviceIds[$offset]]);
        }

        $path = $this->temporaryXlsx();
        $result = $this->exporter->export($versionId, $path);
        self::assertSame('mcv26_price_2025-04-01.xlsx', $result['filename']);
        self::assertSame('2025-04-01', $result['price_date']);
        self::assertSame(22, $result['categories']);
        self::assertSame(271, $result['services']);

        (new UploadValidator())->validateWorkbook($path);
        $book = IOFactory::load($path);
        self::assertSame(1, $book->getSheetCount());
        $sheet = $book->getActiveSheet();
        self::assertSame('A1:D1', $sheet->getMergeCells()['A1:D1']);
        self::assertSame(
            'Прайс ООО «Медицинский Центр Власова» от 01. 04. 2025 г.',
            $sheet->getCell('A1')->getValue()
        );
        self::assertSame(['№ услуги', 'Код услуги', 'Наименование услуги', '₽'], $sheet->rangeToArray('A2:D2')[0]);
        self::assertCount(23, $sheet->getMergeCells());
        self::assertSame('A3:D3', $sheet->getMergeCells()['A3:D3']);
        self::assertSame('470.00', sprintf('%.2f', $sheet->getCell('D4')->getValue()));
        $book->disconnectWorksheets();

        $parsed = (new PriceImporter(new UploadValidator()))->import($path, $result['filename']);
        self::assertSame(['sections' => 22, 'items' => 271], $parsed['stats']);
        self::assertSame('2025-04-01', $parsed['source']['price_date']);
        self::assertSame('RUB', $parsed['currency']);
        self::assertSame($savedPrices, array_slice($this->flattenPrices($parsed), 0, 4));
        $expectedPrices = $this->flattenPrices($this->realData);
        foreach ($savedPrices as $offset => $price) {
            $expectedPrices[$offset] = $price;
        }
        self::assertSame($expectedPrices, $this->flattenPrices($parsed));
        self::assertSame(47000, $parsed['sections'][0]['items'][0]['price_minor']);
        self::assertSame(37000, $this->realData['sections'][0]['items'][0]['price_minor']);
        self::assertGraphIdentity($this->realData, $parsed);
        $duplicateWarnings = array_values(array_filter(
            $parsed['warnings'],
            static fn (array $warning): bool => $warning['code'] === 'duplicate_service_number'
                && str_contains($warning['message'], '269')
        ));
        self::assertCount(1, $duplicateWarnings);
    }

    #[DataProvider('immutableStatuses')]
    public function testPublishedAndArchivedExportWithoutStateChanges(string $status): void
    {
        $versionId = $this->createRealVersion($status, 7);
        $before = $this->state($versionId);
        $result = $this->exporter->export($versionId, $this->temporaryXlsx());
        self::assertSame(271, $result['services']);
        self::assertSame($before, $this->state($versionId));
    }

    public static function immutableStatuses(): array
    {
        return [['published'], ['archived']];
    }

    public function testRestoredDraftExportsAsNormalWorkbookWithoutLineage(): void
    {
        $archivedId = $this->createRealVersion('archived', 3);
        $restored = (new ArchivedVersionRestorer($this->pdo, $this->repository))->restore($archivedId);
        $draftId = $restored['draft_version_id'];
        $firstService = (int) $this->pdo->query(
            'SELECT s.id FROM services s JOIN categories c ON c.id=s.category_id '
            . 'WHERE c.price_version_id=' . $draftId . ' ORDER BY c.position,s.position LIMIT 1'
        )->fetchColumn();
        $this->pdo->exec('UPDATE services SET current_price_minor=47000 WHERE id=' . $firstService);

        $path = $this->temporaryXlsx();
        $this->exporter->export($draftId, $path);
        $parsed = (new PriceImporter(new UploadValidator()))->import($path);
        self::assertSame(47000, $parsed['sections'][0]['items'][0]['price_minor']);
        self::assertSame(['sections' => 22, 'items' => 271], $parsed['stats']);
        $book = IOFactory::load($path);
        self::assertSame('D', $book->getActiveSheet()->getHighestDataColumn());
        self::assertStringNotContainsString('restored', json_encode(
            $book->getActiveSheet()->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));
        $book->disconnectWorksheets();
        self::assertSame('archived', $this->repository->loadVersion($archivedId)['status']);
        self::assertSame($archivedId, (int) $this->repository->loadVersion($draftId)['restored_from_version_id']);
    }

    public function testMissingVersionAndUndatedFilenameAreDeterministic(): void
    {
        try {
            $this->exporter->export(999999, $this->temporaryXlsx());
            self::fail('Expected missing version rejection.');
        } catch (PriceVersionExportException $exception) {
            self::assertSame('Price version was not found.', $exception->getMessage());
        }

        $versionId = $this->createRealVersion(priceDate: null);
        $path = $this->temporaryXlsx();
        $result = $this->exporter->export($versionId, $path);
        self::assertSame('mcv26_price_undated.xlsx', $result['filename']);
        self::assertNull($result['price_date']);
        $parsed = (new PriceImporter(new UploadValidator()))->import($path);
        self::assertNull($parsed['source']['price_date']);
    }

    public function testInvalidIdAndWriterFailureDoNotMutateVersion(): void
    {
        try {
            $this->exporter->export(0, $this->temporaryXlsx());
            self::fail('Expected invalid ID rejection.');
        } catch (PriceVersionExportException) {
            $this->addToAssertionCount(1);
        }

        $versionId = $this->createRealVersion(revision: 5);
        $before = $this->state($versionId);
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        $writerFailed = false;
        try {
            $this->exporter->export($versionId, sys_get_temp_dir());
        } catch (\Throwable) {
            $writerFailed = true;
        } finally {
            restore_error_handler();
        }
        self::assertTrue($writerFailed, 'Expected writer failure for directory destination.');
        self::assertSame($before, $this->state($versionId));
    }

    private function createRealVersion(string $status = 'draft', int $revision = 0, ?string $priceDate = '2025-04-01'): int
    {
        $versionId = $this->repository->createVersion([
            'title' => $this->realData['source']['title'],
            'price_date' => $priceDate,
            'original_filename' => 'current.xlsx',
            'stored_xlsx_name' => 'current.xlsx',
            'imported_at' => '2025-04-01 00:00:00.000000',
        ]);
        foreach ($this->realData['sections'] as $categoryOffset => $section) {
            $categoryId = $this->repository->createCategory($versionId, $categoryOffset + 1, $section['name']);
            foreach ($section['items'] as $serviceOffset => $item) {
                $this->repository->createService($categoryId, [
                    'position' => $serviceOffset + 1,
                    'service_number' => $item['number'],
                    'code' => $item['code'],
                    'name' => $item['name'],
                    'imported_price_minor' => $item['price_minor'],
                    'current_price_minor' => $item['price_minor'],
                ]);
            }
        }
        $statement = $this->pdo->prepare('UPDATE price_versions SET status=?, revision=? WHERE id=?');
        $statement->execute([$status, $revision, $versionId]);
        return $versionId;
    }

    /** @return array{status: string, revision: int, audit_count: int} */
    private function state(int $versionId): array
    {
        $row = $this->pdo->query(
            'SELECT status, revision FROM price_versions WHERE id=' . $versionId
        )->fetch();
        return [
            'status' => $row['status'],
            'revision' => (int) $row['revision'],
            'audit_count' => (int) $this->pdo->query(
                'SELECT COUNT(*) FROM price_changes WHERE version_id=' . $versionId
            )->fetchColumn(),
        ];
    }

    /** @return list<int> */
    private function flattenPrices(array $data): array
    {
        $prices = [];
        foreach ($data['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $prices[] = $item['price_minor'];
            }
        }
        return $prices;
    }

    private function assertGraphIdentity(array $expected, array $actual): void
    {
        self::assertSame(array_column($expected['sections'], 'name'), array_column($actual['sections'], 'name'));
        foreach ($expected['sections'] as $categoryOffset => $section) {
            foreach ($section['items'] as $serviceOffset => $item) {
                $exported = $actual['sections'][$categoryOffset]['items'][$serviceOffset];
                self::assertSame($item['number'], $exported['number']);
                self::assertSame($item['code'], $exported['code']);
                self::assertSame($item['name'], $exported['name']);
            }
        }
    }

    private function temporaryXlsx(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'mcv26_export_test_');
        self::assertNotFalse($base);
        @unlink($base);
        return $this->temporaryFiles[] = $base . '.xlsx';
    }
}
