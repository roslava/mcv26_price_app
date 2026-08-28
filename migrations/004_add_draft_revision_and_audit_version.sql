SET @add_revision = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'price_versions' AND column_name = 'revision') = 0,
    'ALTER TABLE price_versions ADD COLUMN revision BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER status',
    'DO 0'
);
PREPARE migration_statement FROM @add_revision;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @add_audit_version = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'price_changes' AND column_name = 'version_id') = 0,
    'ALTER TABLE price_changes ADD COLUMN version_id BIGINT UNSIGNED NULL AFTER id',
    'DO 0'
);
PREPARE migration_statement FROM @add_audit_version;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @rename_old_price = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'price_changes' AND column_name = 'old_price_minor') = 0
    AND
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'price_changes' AND column_name = 'previous_price_minor') = 1,
    'ALTER TABLE price_changes CHANGE previous_price_minor old_price_minor BIGINT UNSIGNED NOT NULL',
    'DO 0'
);
PREPARE migration_statement FROM @rename_old_price;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @rename_new_price = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'price_changes' AND column_name = 'new_price_minor') = 0
    AND
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'price_changes' AND column_name = 'current_price_minor') = 1,
    'ALTER TABLE price_changes CHANGE current_price_minor new_price_minor BIGINT UNSIGNED NOT NULL',
    'DO 0'
);
PREPARE migration_statement FROM @rename_new_price;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE price_changes pc
JOIN services s ON s.id = pc.service_id
JOIN categories c ON c.id = s.category_id
SET pc.version_id = c.price_version_id
WHERE pc.version_id IS NULL;

SET @require_audit_version = IF(
    (SELECT is_nullable FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'price_changes' AND column_name = 'version_id') = 'YES',
    'ALTER TABLE price_changes MODIFY version_id BIGINT UNSIGNED NOT NULL',
    'DO 0'
);
PREPARE migration_statement FROM @require_audit_version;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @add_audit_version_index = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'price_changes'
       AND index_name = 'idx_price_changes_version') = 0,
    'CREATE INDEX idx_price_changes_version ON price_changes (version_id)',
    'DO 0'
);
PREPARE migration_statement FROM @add_audit_version_index;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @add_audit_version_fk = IF(
    (SELECT COUNT(*) FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE() AND table_name = 'price_changes'
       AND constraint_name = 'fk_price_changes_version') = 0,
    'ALTER TABLE price_changes ADD CONSTRAINT fk_price_changes_version FOREIGN KEY (version_id) REFERENCES price_versions (id) ON DELETE CASCADE',
    'DO 0'
);
PREPARE migration_statement FROM @add_audit_version_fk;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
