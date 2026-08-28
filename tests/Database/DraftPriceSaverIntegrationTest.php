<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Database;

use Mcv26\Price\Admin\DraftPriceSaver;
use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\MigrationRunner;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Exception\DraftSaveException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class DraftPriceSaverIntegrationTest extends TestCase
{
    private PDO $pdo;
    private DatabasePriceRepository $repository;
    private DraftPriceSaver $saver;
    private int $versionId;
    /** @var list<int> */
    private array $serviceIds;

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
        $this->pdo->exec('DROP TRIGGER IF EXISTS test_reject_price_change');
        $this->pdo->exec('DELETE FROM price_versions');
        $this->repository = new DatabasePriceRepository($this->pdo);
        $this->saver = new DraftPriceSaver($this->pdo, $this->repository);
        $this->versionId = $this->repository->createVersion([
            'title' => 'Draft',
            'price_date' => '2025-04-01',
            'original_filename' => 'draft.xlsx',
            'stored_xlsx_name' => 'price_20250101_000000_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.xlsx',
            'imported_at' => '2025-04-01 00:00:00.000000',
        ]);
        $categoryId = $this->repository->createCategory($this->versionId, 1, 'Category');
        $this->serviceIds = [];
        foreach ([100000, 200000, 300000] as $offset => $price) {
            $this->serviceIds[] = $this->repository->createService($categoryId, [
                'position' => $offset + 1,
                'service_number' => $offset + 1,
                'code' => 'CODE-' . ($offset + 1),
                'name' => 'Service ' . ($offset + 1),
                'imported_price_minor' => $price,
                'current_price_minor' => $price,
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TRIGGER IF EXISTS test_reject_price_change');
        }
    }

    public function testSavesOneServiceAndRecordsServerIdentity(): void
    {
        $result = $this->saver->save($this->versionId, 0, $this->prices([110000, 200000, 300000]), 'admin');

        self::assertSame(['revision' => 1, 'changed' => 1], $result);
        self::assertSame([110000, 200000, 300000], $this->currentPrices());
        self::assertSame([100000, 200000, 300000], $this->importedPrices());
        $audit = $this->pdo->query('SELECT * FROM price_changes')->fetch();
        self::assertSame($this->versionId, (int) $audit['version_id']);
        self::assertSame($this->serviceIds[0], (int) $audit['service_id']);
        self::assertSame(100000, (int) $audit['old_price_minor']);
        self::assertSame(110000, (int) $audit['new_price_minor']);
        self::assertSame('admin', $audit['changed_by']);
        self::assertSame(1, $this->revision());
    }

    public function testMultipleAndRepeatedSavesRecordActualTransitions(): void
    {
        $first = $this->saver->save($this->versionId, 0, $this->prices([110000, 210000, 300000]), 'editor');
        $second = $this->saver->save($this->versionId, 1, $this->prices([120000, 210000, 300000]), 'editor');

        self::assertSame(2, $first['changed']);
        self::assertSame(1, $second['changed']);
        self::assertSame(2, $second['revision']);
        self::assertSame([120000, 210000, 300000], $this->currentPrices());
        self::assertSame([100000, 200000, 300000], $this->importedPrices());
        $history = $this->pdo->query(
            'SELECT old_price_minor, new_price_minor FROM price_changes '
            . 'WHERE service_id=' . $this->serviceIds[0] . ' ORDER BY id'
        )->fetchAll();
        self::assertSame([
            ['old_price_minor' => 100000, 'new_price_minor' => 110000],
            ['old_price_minor' => 110000, 'new_price_minor' => 120000],
        ], array_map(static fn (array $row): array => [
            'old_price_minor' => (int) $row['old_price_minor'],
            'new_price_minor' => (int) $row['new_price_minor'],
        ], $history));
    }

    public function testUnchangedCompleteStateIncrementsOnceWithoutAudit(): void
    {
        $result = $this->saver->save($this->versionId, 0, $this->prices([100000, 200000, 300000]), 'admin');
        self::assertSame(['revision' => 1, 'changed' => 0], $result);
        self::assertSame(0, $this->auditCount());
    }

    public function testStaleRevisionChangesNothing(): void
    {
        $this->saver->save($this->versionId, 0, $this->prices([110000, 200000, 300000]), 'tab-a');
        try {
            $this->saver->save($this->versionId, 0, $this->prices([120000, 200000, 300000]), 'tab-b');
            self::fail('Expected stale revision conflict.');
        } catch (DraftSaveException $exception) {
            self::assertSame('revision_conflict', $exception->errorCode);
            self::assertSame(409, $exception->httpStatus);
        }
        self::assertSame([110000, 200000, 300000], $this->currentPrices());
        self::assertSame(1, $this->auditCount());
        self::assertSame(1, $this->revision());
    }

    public function testInvalidUnknownDuplicateOrMissingServiceRollsBackEverything(): void
    {
        $invalidCases = [
            [['service_id' => $this->serviceIds[0], 'current_price_minor' => '0']],
            $this->prices([110000, 200000, 300000], 999999),
            [
                ['service_id' => $this->serviceIds[0], 'current_price_minor' => '110000'],
                ['service_id' => $this->serviceIds[0], 'current_price_minor' => '120000'],
            ],
            array_slice($this->prices([110000, 200000, 300000]), 0, 2),
        ];
        foreach ($invalidCases as $prices) {
            try {
                $this->saver->save($this->versionId, 0, $prices, 'admin');
                self::fail('Expected validation failure.');
            } catch (DraftSaveException $exception) {
                self::assertSame('invalid_request', $exception->errorCode);
            }
            self::assertSame([100000, 200000, 300000], $this->currentPrices());
            self::assertSame(0, $this->auditCount());
            self::assertSame(0, $this->revision());
        }
    }

    public function testPublishedAndArchivedVersionsCannotBeSaved(): void
    {
        foreach (['published', 'archived'] as $status) {
            $this->pdo->exec(
                "UPDATE price_versions SET status=" . $this->pdo->quote($status) . ' WHERE id=' . $this->versionId
            );
            try {
                $this->saver->save($this->versionId, 0, $this->prices([110000, 200000, 300000]), 'admin');
                self::fail('Expected non-draft rejection.');
            } catch (DraftSaveException $exception) {
                self::assertSame('not_draft', $exception->errorCode);
            }
        }
        self::assertSame([100000, 200000, 300000], $this->currentPrices());
        self::assertSame(0, $this->auditCount());
        self::assertSame(0, $this->revision());
    }

    public function testAuditFailureRollsBackServiceAndRevision(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER test_reject_price_change BEFORE INSERT ON price_changes "
            . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test audit failure'"
        );
        try {
            $this->saver->save($this->versionId, 0, $this->prices([110000, 200000, 300000]), 'admin');
            self::fail('Expected audit failure.');
        } catch (PDOException) {
            self::assertSame([100000, 200000, 300000], $this->currentPrices());
            self::assertSame(0, $this->auditCount());
            self::assertSame(0, $this->revision());
        }
    }

    /** @param list<int> $values @return list<array{service_id: int, current_price_minor: string}> */
    private function prices(array $values, ?int $replaceLastId = null): array
    {
        return array_map(function (int $value, int $offset) use ($replaceLastId): array {
            $id = $replaceLastId !== null && $offset === count($this->serviceIds) - 1
                ? $replaceLastId
                : $this->serviceIds[$offset];
            return ['service_id' => $id, 'current_price_minor' => (string) $value];
        }, $values, array_keys($values));
    }

    /** @return list<int> */
    private function currentPrices(): array
    {
        return array_map('intval', $this->pdo->query(
            'SELECT current_price_minor FROM services ORDER BY position'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    private function importedPrices(): array
    {
        return array_map('intval', $this->pdo->query(
            'SELECT imported_price_minor FROM services ORDER BY position'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    private function revision(): int
    {
        return (int) $this->pdo->query(
            'SELECT revision FROM price_versions WHERE id=' . $this->versionId
        )->fetchColumn();
    }

    private function auditCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM price_changes')->fetchColumn();
    }
}
