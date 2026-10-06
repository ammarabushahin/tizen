CREATE TABLE IF NOT EXISTS xanalytica_refresh_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    trigger_type VARCHAR(20) NOT NULL DEFAULT 'cron',
    status VARCHAR(20) NOT NULL,
    users_found INT UNSIGNED NOT NULL DEFAULT 0,
    issued INT UNSIGNED NOT NULL DEFAULT 0,
    failed INT UNSIGNED NOT NULL DEFAULT 0,
    error_text TEXT NULL,
    result_json LONGTEXT NULL,
    PRIMARY KEY (id),
    KEY idx_xanalytica_runs_started (started_at),
    KEY idx_xanalytica_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xanalytica_refresh_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    user_ref VARCHAR(191) NOT NULL,
    platform VARCHAR(30) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    http_status INT UNSIGNED NOT NULL DEFAULT 0,
    success TINYINT(1) NOT NULL DEFAULT 0,
    error_text TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_xanalytica_items_run (run_id),
    KEY idx_xanalytica_items_success (success),
    CONSTRAINT fk_xanalytica_items_run
        FOREIGN KEY (run_id) REFERENCES xanalytica_refresh_runs(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
