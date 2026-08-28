SET @add_restored_from = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'price_versions'
       AND column_name = 'restored_from_version_id') = 0,
    'ALTER TABLE price_versions ADD COLUMN restored_from_version_id BIGINT UNSIGNED NULL AFTER revision',
    'DO 0'
);
PREPARE migration_statement FROM @add_restored_from;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @add_restored_from_index = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'price_versions'
       AND index_name = 'idx_price_versions_restored_from') = 0,
    'CREATE INDEX idx_price_versions_restored_from ON price_versions (restored_from_version_id)',
    'DO 0'
);
PREPARE migration_statement FROM @add_restored_from_index;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @add_restored_from_fk = IF(
    (SELECT COUNT(*) FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE() AND table_name = 'price_versions'
       AND constraint_name = 'fk_price_versions_restored_from') = 0,
    'ALTER TABLE price_versions ADD CONSTRAINT fk_price_versions_restored_from FOREIGN KEY (restored_from_version_id) REFERENCES price_versions (id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE migration_statement FROM @add_restored_from_fk;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
