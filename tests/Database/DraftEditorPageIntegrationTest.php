<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Database;

use Mcv26\Price\Admin\DraftEditorPage;
use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\MigrationRunner;
use Mcv26\Price\Database\PdoConnectionFactory;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('integration')]
final class DraftEditorPageIntegrationTest extends TestCase
{
    private DatabasePriceRepository $repository;
    private DraftEditorPage $page;

    protected function setUp(): void
    {
        $dsn = getenv('MCV26_TEST_DB_DSN');
        if (!is_string($dsn) || $dsn === '' || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Set MCV26_TEST_DB_DSN and enable pdo_mysql to run MySQL integration tests.');
        }
        $pdo = PdoConnectionFactory::create(new DatabaseConfig(
            $dsn,
            (string) (getenv('MCV26_TEST_DB_USER') ?: ''),
            (string) (getenv('MCV26_TEST_DB_PASSWORD') ?: '')
        ));
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $pdo->exec('DELETE FROM price_versions');
        $this->repository = new DatabasePriceRepository($pdo);
        $this->page = new DraftEditorPage($this->repository);
    }

    public function testLoadsOnlyDraftWithOrderedGraphAndMetadata(): void
    {
        $versionId = $this->createVersion('Draft title', 'upload.xlsx');
        $secondCategory = $this->repository->createCategory($versionId, 2, 'Second category');
        $firstCategory = $this->repository->createCategory($versionId, 1, 'First category');
        $this->service($firstCategory, 2, 2, 'Second service');
        $this->service($firstCategory, 1, 1, 'First service');
        $this->service($secondCategory, 1, 3, 'Third service');

        $draft = $this->page->loadDraft($versionId);
        self::assertSame('Draft title', $draft['title']);
        self::assertSame('2025-04-01', $draft['price_date']);
        self::assertSame('upload.xlsx', $draft['original_filename']);
        self::assertSame('draft', $draft['status']);
        self::assertSame(['First category', 'Second category'], array_column($draft['categories'], 'name'));
        self::assertSame(
            ['First service', 'Second service'],
            array_column($draft['categories'][0]['services'], 'name')
        );
    }

    public function testRejectsPublishedVersion(): void
    {
        $versionId = $this->createVersion('Published', 'published.xlsx');
        $this->repository->publishVersion($versionId);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only draft');
        $this->page->loadDraft($versionId);
    }

    public function testRejectsMissingVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->page->loadDraft(999999);
    }

    private function createVersion(string $title, string $filename): int
    {
        return $this->repository->createVersion([
            'title' => $title,
            'price_date' => '2025-04-01',
            'original_filename' => $filename,
            'stored_xlsx_name' => 'price_20250801_000000_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.xlsx',
            'imported_at' => '2025-04-01 00:00:00.000000',
        ]);
    }

    private function service(int $categoryId, int $position, int $number, string $name): void
    {
        $this->repository->createService($categoryId, [
            'position' => $position,
            'service_number' => $number,
            'code' => 'CODE-' . $number,
            'name' => $name,
            'imported_price_minor' => 10000 * $number,
            'current_price_minor' => 10000 * $number,
        ]);
    }
}
