<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Database;

use PHPUnit\Framework\TestCase;

final class MigrationSqlTest extends TestCase
{
    private string $sql;

    protected function setUp(): void
    {
        $this->sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/001_create_price_storage.sql');
    }

    public function testDefinesRequiredTablesAndMinorUnitColumns(): void
    {
        foreach (['price_versions', 'categories', 'services', 'price_changes'] as $table) {
            self::assertMatchesRegularExpression('/CREATE TABLE IF NOT EXISTS ' . $table . '\s*\(/i', $this->sql);
        }
        self::assertMatchesRegularExpression('/imported_price_minor\s+BIGINT\s+UNSIGNED\s+NOT NULL/i', $this->sql);
        self::assertMatchesRegularExpression('/current_price_minor\s+BIGINT\s+UNSIGNED\s+NOT NULL/i', $this->sql);
    }

    public function testDefinesForeignKeysAndOrdinaryIndexesWithoutPartialUniqueness(): void
    {
        self::assertSame(3, preg_match_all('/FOREIGN KEY/i', $this->sql));
        self::assertGreaterThanOrEqual(7, preg_match_all('/\bINDEX\s+[a-z_]+/i', $this->sql));
        self::assertStringNotContainsStringIgnoringCase('WHERE status', $this->sql);
    }

    public function testAddsPublicationFingerprintsForIdempotency(): void
    {
        $sql = (string) file_get_contents(
            dirname(__DIR__, 2) . '/migrations/002_add_publication_fingerprints.sql'
        );
        self::assertStringContainsString('source_xlsx_sha256 CHAR(64)', $sql);
        self::assertStringContainsString('source_json_sha256 CHAR(64)', $sql);
        self::assertStringContainsString('UNIQUE INDEX uq_price_versions_source_xlsx_sha256', $sql);
    }
}
