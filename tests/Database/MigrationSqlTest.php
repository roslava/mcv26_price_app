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

    public function testScopesSourceIdentityBetweenInitialMigrationAndDraftImports(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/003_scope_source_identity.sql');
        self::assertStringContainsString("'draft:'", $sql);
        self::assertStringContainsString("'initial:'", $sql);
        self::assertStringContainsString('UNIQUE INDEX uq_price_versions_source_identity', $sql);
        self::assertStringContainsString('DROP INDEX uq_price_versions_source_xlsx_sha256', $sql);
    }

    public function testAddsDraftRevisionAndVersionedAuditHistory(): void
    {
        $sql = (string) file_get_contents(
            dirname(__DIR__, 2) . '/migrations/004_add_draft_revision_and_audit_version.sql'
        );
        self::assertStringContainsString('revision BIGINT UNSIGNED NOT NULL DEFAULT 0', $sql);
        self::assertStringContainsString('version_id BIGINT UNSIGNED', $sql);
        self::assertStringContainsString('CHANGE previous_price_minor old_price_minor', $sql);
        self::assertStringContainsString('CHANGE current_price_minor new_price_minor', $sql);
        self::assertStringContainsString('fk_price_changes_version', $sql);
        self::assertStringContainsString('information_schema.columns', $sql);
    }
}
