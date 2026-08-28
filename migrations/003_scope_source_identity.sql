SET @add_source_identity = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'price_versions'
       AND column_name = 'source_identity') = 0,
    'ALTER TABLE price_versions ADD COLUMN source_identity VARCHAR(80) NULL AFTER source_json_sha256',
    'DO 0'
);
PREPARE migration_statement FROM @add_source_identity;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE price_versions
SET source_identity = CONCAT(
    IF(source_json_sha256 IS NULL, 'draft:', 'initial:'),
    source_xlsx_sha256
)
WHERE source_identity IS NULL AND source_xlsx_sha256 IS NOT NULL;

SET @drop_global_hash_index = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'price_versions'
       AND index_name = 'uq_price_versions_source_xlsx_sha256') > 0,
    'DROP INDEX uq_price_versions_source_xlsx_sha256 ON price_versions',
    'DO 0'
);
PREPARE migration_statement FROM @drop_global_hash_index;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @add_hash_index = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'price_versions'
       AND index_name = 'idx_price_versions_source_xlsx_sha256') = 0,
    'CREATE INDEX idx_price_versions_source_xlsx_sha256 ON price_versions (source_xlsx_sha256)',
    'DO 0'
);
PREPARE migration_statement FROM @add_hash_index;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @add_source_identity_index = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'price_versions'
       AND index_name = 'uq_price_versions_source_identity') = 0,
    'CREATE UNIQUE INDEX uq_price_versions_source_identity ON price_versions (source_identity)',
    'DO 0'
);
PREPARE migration_statement FROM @add_source_identity_index;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
