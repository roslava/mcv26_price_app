<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Database;

use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\MigrationRunner;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Admin\AdminUploadImporter;
use Mcv26\Price\Exception\ImportException;
use Mcv26\Price\Import\DraftVersionImporter;
use Mcv26\Price\Migration\CurrentPublicationMigrator;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\Storage\OriginalXlsxStorage;
use Mcv26\Price\Tests\Support\XlsxFixture;
use Mcv26\Price\UploadValidator;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class DraftVersionImporterIntegrationTest extends TestCase
{
    private PDO $pdo;
    private DatabasePriceRepository $repository;
    private DraftVersionImporter $draftImporter;
    private CurrentPublicationMigrator $currentMigrator;
    private ?string $storageRoot = null;
    private string $xlsxPath;
    private string $jsonPath;
    /** @var list<string> */
    private array $temporaryFiles = [];

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

        $base = tempnam(sys_get_temp_dir(), 'mcv26_draft_storage_');
        self::assertNotFalse($base);
        unlink($base);
        mkdir($base);
        mkdir($base . '/public');
        mkdir($base . '/originals');
        $this->storageRoot = $base;
        $validator = new UploadValidator();
        $storage = new OriginalXlsxStorage($base . '/originals', $base . '/public', $validator);
        $importer = new PriceImporter($validator);
        $this->draftImporter = new DraftVersionImporter(
            $this->pdo,
            $this->repository,
            $validator,
            $importer,
            $storage
        );
        $this->currentMigrator = new CurrentPublicationMigrator(
            $this->pdo,
            $this->repository,
            $importer,
            $storage
        );
        $this->xlsxPath = dirname(__DIR__, 2) . '/storage/uploads/current.xlsx';
        $this->jsonPath = dirname(__DIR__, 2) . '/storage/data/price.json';
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        if ($this->storageRoot !== null && is_dir($this->storageRoot)) {
            foreach (glob($this->storageRoot . '/originals/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->storageRoot . '/originals');
            @rmdir($this->storageRoot . '/public');
            @rmdir($this->storageRoot);
        }
    }

    public function testValidRealXlsxCreatesOrderedDraftWithoutChangingPublishedVersion(): void
    {
        $published = $this->currentMigrator->migrate($this->xlsxPath, $this->jsonPath);
        $before = $this->repository->loadVersion($published['version_id']);

        $result = $this->draftImporter->import($this->xlsxPath, 'clinic-upload.xlsx');

        self::assertTrue($result['created']);
        self::assertSame('draft', $result['status']);
        self::assertSame(22, $result['categories']);
        self::assertSame(271, $result['services']);
        self::assertNotSame($published['version_id'], $result['version_id']);
        $draft = $this->repository->loadVersion($result['version_id']);
        self::assertSame('clinic-upload.xlsx', $draft['original_filename']);
        self::assertNotSame('clinic-upload.xlsx', $draft['stored_xlsx_name']);
        self::assertFileExists($this->storageRoot . '/originals/' . $draft['stored_xlsx_name']);
        self::assertSame(hash_file('sha256', $this->xlsxPath), hash_file(
            'sha256',
            $this->storageRoot . '/originals/' . $draft['stored_xlsx_name']
        ));
        self::assertCount(22, $draft['categories']);
        self::assertSame('грязь', $draft['categories'][0]['name']);
        self::assertSame(1, (int) $draft['categories'][0]['position']);
        self::assertSame('A20.03.001', $draft['categories'][0]['services'][0]['code']);
        self::assertSame('ФДТ', $draft['categories'][21]['name']);
        self::assertSame('A22.24.003.09', $draft['categories'][21]['services'][8]['code']);
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM services WHERE imported_price_minor <> current_price_minor'
        )->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM services s JOIN categories c ON c.id=s.category_id '
            . 'WHERE c.price_version_id=' . (int) $result['version_id'] . ' AND s.service_number=269'
        )->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM price_changes')->fetchColumn());
        self::assertSame($before, $this->repository->loadVersion($published['version_id']));
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM price_versions WHERE status='published'"
        )->fetchColumn());
    }

    public function testAdminUploadCompositionCreatesDraftWithoutChangingPublication(): void
    {
        $published = $this->currentMigrator->migrate($this->xlsxPath, $this->jsonPath);
        $beforeXlsx = hash_file('sha256', $this->xlsxPath);
        $beforeJson = hash_file('sha256', $this->jsonPath);

        $result = (new AdminUploadImporter($this->pdo, $this->storageRoot, $this->storageRoot . '/public'))
            ->import($this->xlsxPath, 'admin-controller-upload.xlsx');

        self::assertTrue($result['created']);
        self::assertSame('draft', $result['status']);
        self::assertNotSame($published['version_id'], $result['version_id']);
        self::assertSame($published['version_id'], $this->repository->publishedVersionId());
        self::assertSame('published', $this->repository->loadVersion($published['version_id'])['status']);
        self::assertSame('draft', $this->repository->loadVersion($result['version_id'])['status']);
        self::assertSame($beforeXlsx, hash_file('sha256', $this->xlsxPath));
        self::assertSame($beforeJson, hash_file('sha256', $this->jsonPath));
    }

    public function testExactDuplicateReturnsExistingDraftWithoutNewGraphOrFile(): void
    {
        $published = $this->currentMigrator->migrate($this->xlsxPath, $this->jsonPath);
        $first = $this->draftImporter->import($this->xlsxPath, 'first-name.xlsx');
        $fileCount = count(glob($this->storageRoot . '/originals/*.xlsx') ?: []);
        $second = $this->draftImporter->import($this->xlsxPath, 'second-name.xlsx');

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['version_id'], $second['version_id']);
        self::assertNotSame($published['version_id'], $second['version_id']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM price_versions')->fetchColumn());
        self::assertSame(44, (int) $this->pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn());
        self::assertSame(542, (int) $this->pdo->query('SELECT COUNT(*) FROM services')->fetchColumn());
        self::assertSame($fileCount, count(glob($this->storageRoot . '/originals/*.xlsx') ?: []));
    }

    public function testInvalidWorkbookCreatesNothing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mcv26_invalid_') . '.xlsx';
        file_put_contents($path, 'not xlsx');
        $this->temporaryFiles[] = $path;

        try {
            $this->draftImporter->import($path, 'invalid.xlsx');
            self::fail('Expected invalid workbook rejection.');
        } catch (ImportException) {
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM price_versions')->fetchColumn());
            self::assertCount(0, glob($this->storageRoot . '/originals/*.xlsx') ?: []);
        }
    }

    public function testDatabaseFailureAfterStorageRollsBackGraphAndRemovesFile(): void
    {
        $tooLongFilename = str_repeat('a', 300) . '.xlsx';
        try {
            $this->draftImporter->import($this->xlsxPath, $tooLongFilename);
            self::fail('Expected database metadata failure.');
        } catch (PDOException) {
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM price_versions')->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM services')->fetchColumn());
            self::assertCount(0, glob($this->storageRoot . '/originals/*.xlsx') ?: []);
        }
    }

    public function testServiceFailureRollsBackPartialGraphAndRemovesFile(): void
    {
        $path = XlsxFixture::create([[1, 'CODE', str_repeat('n', 1100), 12.34]]);
        $this->temporaryFiles[] = $path;
        try {
            $this->draftImporter->import($path, 'long-service.xlsx');
            self::fail('Expected service persistence failure.');
        } catch (PDOException) {
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM price_versions')->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM services')->fetchColumn());
            self::assertCount(0, glob($this->storageRoot . '/originals/*.xlsx') ?: []);
        }
    }
}
