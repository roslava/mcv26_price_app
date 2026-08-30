<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Database;

use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\MigrationRunner;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Migration\CurrentPublicationMigrator;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\Storage\OriginalXlsxStorage;
use Mcv26\Price\UploadValidator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('integration')]
final class CurrentPublicationMigratorIntegrationTest extends TestCase
{
    private PDO $pdo;
    private DatabasePriceRepository $repository;
    private CurrentPublicationMigrator $migrator;
    private string $xlsxPath;
    private string $jsonPath;
    private ?string $storageRoot = null;
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
        $base = tempnam(sys_get_temp_dir(), 'mcv26_migration_storage_');
        self::assertNotFalse($base);
        unlink($base);
        mkdir($base);
        mkdir($base . '/public');
        mkdir($base . '/originals');
        $this->storageRoot = $base;
        $validator = new UploadValidator();
        $this->migrator = new CurrentPublicationMigrator(
            $this->pdo,
            $this->repository,
            new PriceImporter($validator),
            new OriginalXlsxStorage($base . '/originals', $base . '/public', $validator)
        );
        $this->xlsxPath = dirname(__DIR__, 2) . '/storage/uploads/current.xlsx';
        $this->jsonPath = dirname(__DIR__, 2) . '/storage/data/price.json';
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        if ($this->storageRoot === null) {
            return;
        }
        foreach (glob($this->storageRoot . '/originals/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->storageRoot . '/originals');
        rmdir($this->storageRoot . '/public');
        rmdir($this->storageRoot);
    }

    public function testMigratesRealCurrentPublicationExactly(): void
    {
        $legacyXlsxHash = hash_file('sha256', $this->xlsxPath);
        $legacyJsonHash = hash_file('sha256', $this->jsonPath);
        $result = $this->migrator->migrate($this->xlsxPath, $this->jsonPath);

        self::assertTrue($result['created']);
        self::assertSame('Прайс ООО «Медицинский Центр Власова» от 01. 04. 2025 г.', $result['title']);
        self::assertSame('2025-04-01', $result['price_date']);
        self::assertSame(22, $result['categories']);
        self::assertSame(271, $result['services']);

        $version = $this->repository->loadVersion($result['version_id']);
        self::assertSame('published', $version['status']);
        self::assertSame('current.xlsx', $version['original_filename']);
        self::assertNotSame('current.xlsx', $version['stored_xlsx_name']);
        self::assertMatchesRegularExpression(
            '/^price_\d{8}_\d{6}_[a-f0-9]{32}\.xlsx$/',
            $version['stored_xlsx_name']
        );
        self::assertFileExists($this->storageRoot . '/originals/' . $version['stored_xlsx_name']);
        self::assertCount(1, glob($this->storageRoot . '/originals/*.xlsx') ?: []);
        self::assertSame(hash_file('sha256', $this->xlsxPath), $version['source_xlsx_sha256']);
        self::assertSame(hash_file('sha256', $this->jsonPath), $version['source_json_sha256']);
        self::assertCount(22, $version['categories']);
        self::assertSame('грязь', $version['categories'][0]['name']);
        self::assertSame(1, (int) $version['categories'][0]['position']);
        self::assertSame('ФДТ', $version['categories'][21]['name']);
        self::assertSame(22, (int) $version['categories'][21]['position']);
        self::assertSame('A20.03.001', $version['categories'][0]['services'][0]['code']);
        self::assertSame(1, (int) $version['categories'][0]['services'][0]['service_number']);
        self::assertSame(37000, (int) $version['categories'][0]['services'][0]['current_price_minor']);
        self::assertSame('A22.24.003.09', $version['categories'][21]['services'][8]['code']);
        self::assertSame(270, (int) $version['categories'][21]['services'][8]['service_number']);
        self::assertSame(9, (int) $version['categories'][21]['services'][8]['position']);

        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM services WHERE imported_price_minor <> current_price_minor'
        )->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM services WHERE service_number = 269'
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM price_versions WHERE status = 'published'"
        )->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM price_changes')->fetchColumn());
        self::assertSame($legacyXlsxHash, hash_file('sha256', $this->xlsxPath));
        self::assertSame($legacyJsonHash, hash_file('sha256', $this->jsonPath));
    }

    public function testSecondRunIsIdempotent(): void
    {
        $first = $this->migrator->migrate($this->xlsxPath, $this->jsonPath);
        $second = $this->migrator->migrate($this->xlsxPath, $this->jsonPath);

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['version_id'], $second['version_id']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM price_versions')->fetchColumn());
        self::assertSame(22, (int) $this->pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn());
        self::assertSame(271, (int) $this->pdo->query('SELECT COUNT(*) FROM services')->fetchColumn());
        self::assertCount(1, glob($this->storageRoot . '/originals/*.xlsx') ?: []);
        $stored = $this->repository->loadVersion($first['version_id']);
        self::assertFileExists($this->storageRoot . '/originals/' . $stored['stored_xlsx_name']);
    }

    public function testMismatchAbortsBeforeDatabaseWrite(): void
    {
        $json = json_decode((string) file_get_contents($this->jsonPath), true, 512, JSON_THROW_ON_ERROR);
        $json['sections'][0]['items'][0]['price'] = 371;
        $path = tempnam(sys_get_temp_dir(), 'mcv26_mismatch_');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->temporaryFiles[] = $path;

        try {
            $this->migrator->migrate($this->xlsxPath, $path);
            self::fail('Expected publication mismatch.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('different price data', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM price_versions')->fetchColumn());
    }

    public function testPublishConflictRollsBackNewVersionGraph(): void
    {
        $existingId = $this->repository->createVersion([
            'title' => 'Existing',
            'price_date' => null,
            'original_filename' => 'existing.xlsx',
            'stored_xlsx_name' => 'existing.xlsx',
            'imported_at' => '2025-01-01 00:00:00.000000',
        ]);
        $this->repository->publishVersion($existingId);

        try {
            $this->migrator->migrate($this->xlsxPath, $this->jsonPath);
            self::fail('Expected published-version conflict.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already published', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM price_versions')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM services')->fetchColumn());
        self::assertCount(0, glob($this->storageRoot . '/originals/*.xlsx') ?: []);
    }

    public function testExistingStoredOriginalIsNotRemovedWhenLaterVerificationFails(): void
    {
        $first = $this->migrator->migrate($this->xlsxPath, $this->jsonPath);
        $version = $this->repository->loadVersion($first['version_id']);
        $storedPath = $this->storageRoot . '/originals/' . $version['stored_xlsx_name'];

        $json = json_decode((string) file_get_contents($this->jsonPath), true, 512, JSON_THROW_ON_ERROR);
        $path = tempnam(sys_get_temp_dir(), 'mcv26_reformatted_');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->temporaryFiles[] = $path;
        try {
            $this->migrator->migrate($this->xlsxPath, $path);
            self::fail('Expected fingerprint verification failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('does not match', $exception->getMessage());
        }
        self::assertFileExists($storedPath);
        self::assertCount(1, glob($this->storageRoot . '/originals/*.xlsx') ?: []);
    }

    public function testExistingMigrationFailsIfStoredOriginalIsMissing(): void
    {
        $first = $this->migrator->migrate($this->xlsxPath, $this->jsonPath);
        $version = $this->repository->loadVersion($first['version_id']);
        unlink($this->storageRoot . '/originals/' . $version['stored_xlsx_name']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match');
        $this->migrator->migrate($this->xlsxPath, $this->jsonPath);
    }
}
