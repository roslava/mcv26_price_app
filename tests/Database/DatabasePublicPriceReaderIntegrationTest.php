<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Database;

use Mcv26\Price\Admin\ArchivedVersionRestorer;
use Mcv26\Price\Admin\DraftPriceSaver;
use Mcv26\Price\Admin\VersionPublisher;
use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\DatabasePublicPriceReader;
use Mcv26\Price\Database\MigrationRunner;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Import\DraftVersionImporter;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\UploadValidator;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('integration')]
final class DatabasePublicPriceReaderIntegrationTest extends TestCase
{
    private PDO $pdo;
    private DatabasePriceRepository $repository;
    private DatabasePublicPriceReader $reader;
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
        $this->reader = new DatabasePublicPriceReader($this->pdo);
        $this->realData = (new PriceImporter(new UploadValidator()))->import(
            dirname(__DIR__, 2) . '/storage/uploads/current.xlsx'
        );
    }

    public function testPublishedCurrentPricesAndGraphArePublic(): void
    {
        $version = $this->createRealVersion();
        $this->pdo->exec('UPDATE services SET current_price_minor=47000 WHERE id=' . $this->firstServiceId($version));
        $this->repository->publishVersion($version);
        $data = $this->reader->read();

        self::assertSame('2025-04-01', $data['source']['price_date']);
        self::assertSame(['sections' => 22, 'items' => 271], $data['stats']);
        self::assertSame(47000, $data['sections'][0]['items'][0]['price_minor']);
        self::assertSame('470', $data['sections'][0]['items'][0]['price']);
        self::assertSame('грязь', $data['sections'][0]['name']);
        self::assertSame('A20.03.001', $data['sections'][0]['items'][0]['code']);
        self::assertArrayNotHasKey('id', $data['sections'][0]['items'][0]);
        self::assertArrayNotHasKey('imported_price_minor', $data['sections'][0]['items'][0]);
    }

    public function testDraftAndArchivedVersionsNeverLeakAndPublicationSwitches(): void
    {
        $a = $this->createRealVersion();
        $this->repository->publishVersion($a);
        $b = $this->createRealVersion();
        $this->assertPublicVersionTitle($this->realData['source']['title']);
        $this->pdo->exec('UPDATE services SET current_price_minor=47000 WHERE id=' . $this->firstServiceId($b));
        $this->assertPublicVersionTitle($this->realData['source']['title']);

        (new VersionPublisher($this->pdo, $this->repository))->publish($b, 0, $a);
        self::assertSame('archived', $this->versionStatus($a));
        self::assertSame('published', $this->versionStatus($b));
        $data = $this->reader->read();
        self::assertSame(47000, $data['sections'][0]['items'][0]['price_minor']);
        self::assertSame($this->realData['source']['title'], $data['source']['title']);
    }

    public function testRestoredDraftCannotLeakUntilPublished(): void
    {
        $a = $this->createRealVersion();
        $this->repository->publishVersion($a);
        $b = $this->createRealVersion();
        $this->pdo->exec('UPDATE services SET current_price_minor=47000 WHERE id=' . $this->firstServiceId($b));
        (new VersionPublisher($this->pdo, $this->repository))->publish($b, 0, $a);
        $c = (new ArchivedVersionRestorer($this->pdo, $this->repository))->restore($a)['draft_version_id'];
        $this->pdo->exec('UPDATE services SET current_price_minor=99999 WHERE id=' . $this->firstServiceId($c));
        self::assertSame($b, $this->publishedId());
        self::assertSame(47000, $this->reader->read()['sections'][0]['items'][0]['price_minor']);
        (new DraftPriceSaver($this->pdo, $this->repository))->save(
            $c,
            0,
            $this->pricesFor($c, 99999),
            'test'
        );
        self::assertSame($b, $this->publishedId());
        self::assertSame(47000, $this->reader->read()['sections'][0]['items'][0]['price_minor']);
    }

    public function testZeroMultipleAndMalformedGraphsFailClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->reader->read();
    }

    public function testDatabaseFailurePropagatesForPublic503Boundary(): void
    {
        $killer = PdoConnectionFactory::create(new DatabaseConfig(
            (string) getenv('MCV26_TEST_DB_DSN'),
            (string) (getenv('MCV26_TEST_DB_USER') ?: ''),
            (string) (getenv('MCV26_TEST_DB_PASSWORD') ?: '')
        ));
        $connectionId = (int) $this->pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $killer->exec('KILL ' . $connectionId);
        $this->expectException(PDOException::class);
        $this->reader->read();
    }

    public function testMultiplePublishedAndInvalidGraphFailClosed(): void
    {
        $a = $this->createRealVersion();
        $b = $this->createRealVersion();
        $this->pdo->exec("UPDATE price_versions SET status='published' WHERE id IN ($a,$b)");
        try {
            $this->reader->read();
            self::fail('Expected multiple-publication failure.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->pdo->exec("UPDATE price_versions SET status='draft' WHERE id=$b");
        $invalid = $this->createRealVersion();
        $this->pdo->exec("UPDATE price_versions SET status='published' WHERE id=$invalid");
        $this->pdo->exec('DELETE FROM services WHERE id=' . $this->firstServiceId($invalid));
        $this->expectException(RuntimeException::class);
        $this->reader->read();
    }

    public function testKopecksAndEscapingArePreserved(): void
    {
        $version = $this->createSimpleVersion('Title <unsafe>', '2025-04-01', 'Code&', '<Name>');
        $this->pdo->exec('UPDATE services SET current_price_minor=37050 WHERE id=' . $this->firstServiceId($version));
        $this->repository->publishVersion($version);
        $data = $this->reader->read();
        self::assertSame(37050, $data['sections'][0]['items'][0]['price_minor']);
        self::assertSame('370.50', $data['sections'][0]['items'][0]['price']);
        self::assertSame('Title <unsafe>', $data['source']['title']);
        self::assertSame('Code&', $data['sections'][0]['items'][0]['code']);
    }

    private function createRealVersion(string $status = 'draft'): int
    {
        $id = $this->repository->createVersion([
            'title' => $this->realData['source']['title'], 'price_date' => $this->realData['source']['price_date'],
            'original_filename' => 'current.xlsx', 'stored_xlsx_name' => 'current.xlsx',
            'imported_at' => '2025-04-01 00:00:00.000000',
        ]);
        foreach ($this->realData['sections'] as $ci => $section) {
            $category = $this->repository->createCategory($id, $ci + 1, $section['name']);
            foreach ($section['items'] as $si => $item) {
                $this->repository->createService($category, [
                    'position' => $si + 1, 'service_number' => $item['number'], 'code' => $item['code'],
                    'name' => $item['name'], 'imported_price_minor' => $item['price_minor'],
                    'current_price_minor' => $item['price_minor'],
                ]);
            }
        }
        if ($status !== 'draft') {
            $this->pdo->exec("UPDATE price_versions SET status='" . $status . "' WHERE id=$id");
        }
        return $id;
    }

    private function createSimpleVersion(string $title, string $date, string $code, string $name): int
    {
        $id = $this->repository->createVersion([
            'title' => $title, 'price_date' => $date, 'original_filename' => 'simple.xlsx',
            'stored_xlsx_name' => 'simple.xlsx', 'imported_at' => '2025-04-01 00:00:00.000000',
        ]);
        $category = $this->repository->createCategory($id, 1, 'Section');
        $this->repository->createService($category, [
            'position' => 1, 'service_number' => 1, 'code' => $code, 'name' => $name,
            'imported_price_minor' => 37000, 'current_price_minor' => 37000,
        ]);
        return $id;
    }

    private function assertPublicVersionTitle(string $title): void
    {
        self::assertSame($title, $this->reader->read()['source']['title']);
    }

    private function versionStatus(int $id): string
    {
        return (string) $this->pdo->query('SELECT status FROM price_versions WHERE id=' . $id)->fetchColumn();
    }

    private function publishedId(): int
    {
        return (int) $this->pdo->query("SELECT id FROM price_versions WHERE status='published'")->fetchColumn();
    }

    private function firstServiceId(int $versionId): int
    {
        return (int) $this->pdo->query(
            'SELECT s.id FROM services s JOIN categories c ON c.id=s.category_id '
            . 'WHERE c.price_version_id=' . $versionId . ' ORDER BY c.position,s.position,s.id LIMIT 1'
        )->fetchColumn();
    }

    /** @return list<array{service_id: int, current_price_minor: string}> */
    private function pricesFor(int $versionId, int $firstPrice): array
    {
        $services = $this->pdo->query(
            'SELECT s.id FROM services s JOIN categories c ON c.id=s.category_id '
            . 'WHERE c.price_version_id=' . $versionId . ' ORDER BY c.position,s.position,s.id'
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map(static fn (int|string $id, int $offset): array => [
            'service_id' => (int) $id,
            'current_price_minor' => (string) ($offset === 0 ? $firstPrice : 100),
        ], $services, array_keys($services));
    }
}
