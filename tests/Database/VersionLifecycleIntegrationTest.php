<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Database;

use Mcv26\Price\Admin\ArchivedVersionRestorer;
use Mcv26\Price\Admin\VersionPublisher;
use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\MigrationRunner;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Exception\VersionActionException;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('integration')]
final class VersionLifecycleIntegrationTest extends TestCase
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

    public function testPublishingArchivesPreviousAndRejectsStaleViews(): void
    {
        $first = $this->version('Published', 110000);
        $second = $this->version('Replacement', 220000);
        $this->repository->publishVersion($first);
        $publisher = new VersionPublisher($this->pdo, $this->repository);

        self::assertSame([
            'published_version_id' => $second,
            'archived_version_id' => $first,
            'revision' => 0,
        ], $publisher->publish($second, 0, $first));
        self::assertSame('archived', $this->versionStatus($first));
        self::assertSame('published', $this->versionStatus($second));

        $third = $this->version('Stale candidate', 330000);
        $this->expectException(VersionActionException::class);
        try {
            $publisher->publish($third, 0, $first);
        } finally {
            self::assertSame('draft', $this->versionStatus($third));
            self::assertSame('published', $this->versionStatus($second));
        }
    }

    public function testStaleRevisionCannotPublish(): void
    {
        $draft = $this->version('Edited', 100000);
        $this->pdo->exec('UPDATE price_versions SET revision=1 WHERE id=' . $draft);
        try {
            (new VersionPublisher($this->pdo, $this->repository))->publish($draft, 0, null);
            self::fail('Expected stale revision conflict.');
        } catch (VersionActionException $exception) {
            self::assertSame('version_conflict', $exception->errorCode);
            self::assertSame('draft', $this->versionStatus($draft));
        }
    }

    public function testPublicationFailureAfterArchiveRollsBackBothStates(): void
    {
        $first = $this->version('Published', 100000);
        $second = $this->version('Draft', 200000);
        $this->repository->publishVersion($first);
        $publisher = new VersionPublisher($this->pdo, $this->repository, static function (): void {
            throw new RuntimeException('simulated interruption');
        });
        try {
            $publisher->publish($second, 0, $first);
            self::fail('Expected simulated interruption.');
        } catch (RuntimeException) {
            self::assertSame('published', $this->versionStatus($first));
            self::assertSame('draft', $this->versionStatus($second));
        }
    }

    public function testArchivedVersionIsClonedAsIndependentDraft(): void
    {
        $archived = $this->version('Archive', 123456);
        $this->pdo->exec("UPDATE price_versions SET status='archived', revision=4 WHERE id=" . $archived);
        $result = (new ArchivedVersionRestorer($this->pdo, $this->repository))->restore($archived);
        $draft = $this->repository->loadVersion($result['draft_version_id']);

        self::assertSame($archived, $result['restored_from_version_id']);
        self::assertSame('draft', $draft['status']);
        self::assertSame(0, (int) $draft['revision']);
        self::assertSame($archived, (int) $draft['restored_from_version_id']);
        self::assertSame(123456, (int) $draft['categories'][0]['services'][0]['imported_price_minor']);
        self::assertSame(123456, (int) $draft['categories'][0]['services'][0]['current_price_minor']);
        self::assertNotSame(
            (int) $this->repository->loadVersion($archived)['categories'][0]['services'][0]['id'],
            (int) $draft['categories'][0]['services'][0]['id']
        );
    }

    public function testOnlyArchivedVersionsCanBeRestored(): void
    {
        $draft = $this->version('Draft', 100000);
        $this->expectException(VersionActionException::class);
        (new ArchivedVersionRestorer($this->pdo, $this->repository))->restore($draft);
    }

    private function version(string $title, int $price): int
    {
        $id = $this->repository->createVersion([
            'title' => $title, 'price_date' => '2026-08-29', 'original_filename' => 'source.xlsx',
            'stored_xlsx_name' => 'stored.xlsx', 'imported_at' => '2026-08-29 00:00:00.000000',
        ]);
        $category = $this->repository->createCategory($id, 1, 'Category');
        $this->repository->createService($category, [
            'position' => 1, 'service_number' => 1, 'code' => 'A', 'name' => 'Service',
            'imported_price_minor' => $price, 'current_price_minor' => $price,
        ]);
        return $id;
    }

    private function versionStatus(int $id): string
    {
        return (string) $this->pdo->query('SELECT status FROM price_versions WHERE id=' . $id)->fetchColumn();
    }
}
