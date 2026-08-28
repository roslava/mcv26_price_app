CREATE TABLE IF NOT EXISTS price_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    status VARCHAR(16) NOT NULL,
    title VARCHAR(512) NOT NULL,
    price_date DATE NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_xlsx_name VARCHAR(255) NOT NULL,
    imported_at DATETIME(6) NOT NULL,
    published_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_price_versions_status (status),
    INDEX idx_price_versions_price_date (price_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    price_version_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    name VARCHAR(512) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE INDEX uq_categories_version_position (price_version_id, position),
    INDEX idx_categories_version (price_version_id),
    CONSTRAINT fk_categories_version FOREIGN KEY (price_version_id)
        REFERENCES price_versions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    service_number INT UNSIGNED NOT NULL,
    code VARCHAR(191) NOT NULL,
    name VARCHAR(1024) NOT NULL,
    imported_price_minor BIGINT UNSIGNED NOT NULL,
    current_price_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE INDEX uq_services_category_position (category_id, position),
    INDEX idx_services_category (category_id),
    INDEX idx_services_number (service_number),
    INDEX idx_services_code (code),
    CONSTRAINT fk_services_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id BIGINT UNSIGNED NOT NULL,
    previous_price_minor BIGINT UNSIGNED NOT NULL,
    current_price_minor BIGINT UNSIGNED NOT NULL,
    changed_at DATETIME(6) NOT NULL,
    changed_by VARCHAR(191) NULL,
    PRIMARY KEY (id),
    INDEX idx_price_changes_service (service_id),
    INDEX idx_price_changes_changed_at (changed_at),
    CONSTRAINT fk_price_changes_service FOREIGN KEY (service_id)
        REFERENCES services (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
