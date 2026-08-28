<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Database;

use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\MigrationRunner;
use Mcv26\Price\Database\PdoConnectionFactory;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('integration')]
final class DatabasePriceRepositoryIntegrationTest extends TestCase
{
    private PDO $pdo;
    private DatabasePriceRepository $repository;

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
    }

    public function testMigrationCreatesExpectedSchema(): void
    {
        $tables = $this->pdo->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach (['price_versions', 'categories', 'services', 'price_changes'] as $table) {
            self::assertContains($table, $tables);
        }
    }

    public function testAppliedMigrationsAreSkippedOnNormalRerun(): void
    {
        $applied = (new MigrationRunner($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        self::assertSame([], $applied);
    }

    public function testMigration002RecoversWhenDdlExistsWithoutBookkeeping(): void
    {
        $this->pdo->exec("DELETE FROM schema_migrations WHERE version = '002_add_publication_fingerprints'");
        $runner = new MigrationRunner($this->pdo, dirname(__DIR__, 2) . '/migrations');

        self::assertSame(['002_add_publication_fingerprints'], $runner->migrate());
        self::assertSame([], $runner->migrate());
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() "
            . "AND table_name = 'price_versions' AND column_name = 'source_xlsx_sha256'"
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() "
            . "AND table_name = 'price_versions' AND column_name = 'source_json_sha256'"
        )->fetchColumn());
        self::assertGreaterThanOrEqual(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() "
            . "AND table_name = 'price_versions' AND index_name = 'uq_price_versions_source_xlsx_sha256'"
        )->fetchColumn());
    }

    public function testTransactionRollsBack(): void
    {
        try {
            $this->repository->transactional(function (): void {
                $this->pdo->exec(
                    "INSERT INTO price_versions "
                    . "(status,title,original_filename,stored_xlsx_name,imported_at,created_at) VALUES "
                    . "('draft','Rollback','a.xlsx','generated.xlsx',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))"
                );
                throw new RuntimeException('rollback');
            });
            self::fail('Expected rollback exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM price_versions')->fetchColumn());
    }

    public function testCreatesAndLoadsVersionCategoryAndService(): void
    {
        $versionId = $this->version('One');
        $this->repository->transactional(function () use ($versionId): void {
            $categoryId = $this->repository->createCategory($versionId, 1, 'Category');
            $this->repository->createService($categoryId, [
                'position' => 1,
                'service_number' => 1,
                'code' => 'A1',
                'name' => 'Service',
                'imported_price_minor' => 1234,
                'current_price_minor' => 1234,
            ]);
        });
        $loaded = $this->repository->loadVersion($versionId);
        self::assertSame('One', $loaded['title']);
        self::assertSame('Category', $loaded['categories'][0]['name']);
        self::assertSame(1234, (int) $loaded['categories'][0]['services'][0]['current_price_minor']);
    }

    public function testOnlyOneVersionCanBePublished(): void
    {
        $first = $this->version('First');
        $second = $this->version('Second');
        $this->repository->publishVersion($first);
        $this->expectException(RuntimeException::class);
        $this->repository->publishVersion($second);
    }

    public function testForeignKeysRejectOrphanCategory(): void
    {
        $this->expectException(PDOException::class);
        $this->repository->createCategory(999999, 1, 'Orphan');
    }

    private function version(string $title): int
    {
        return $this->repository->createVersion([
            'title' => $title,
            'price_date' => '2025-04-01',
            'original_filename' => 'original.xlsx',
            'stored_xlsx_name' => 'generated.xlsx',
            'imported_at' => '2025-04-01 00:00:00.000000',
        ]);
    }
}
