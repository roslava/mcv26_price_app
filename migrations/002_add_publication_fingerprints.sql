SET @add_xlsx_hash = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'price_versions'
       AND column_name = 'source_xlsx_sha256') = 0,
    'ALTER TABLE price_versions ADD COLUMN source_xlsx_sha256 CHAR(64) NULL AFTER stored_xlsx_name',
    'DO 0'
);
PREPARE migration_statement FROM @add_xlsx_hash;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @add_json_hash = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'price_versions'
       AND column_name = 'source_json_sha256') = 0,
    'ALTER TABLE price_versions ADD COLUMN source_json_sha256 CHAR(64) NULL AFTER source_xlsx_sha256',
    'DO 0'
);
PREPARE migration_statement FROM @add_json_hash;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @add_xlsx_hash_index = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'price_versions'
       AND index_name = 'uq_price_versions_source_xlsx_sha256') = 0,
    'CREATE UNIQUE INDEX uq_price_versions_source_xlsx_sha256 ON price_versions (source_xlsx_sha256)',
    'DO 0'
);
PREPARE migration_statement FROM @add_xlsx_hash_index;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
