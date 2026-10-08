CREATE TABLE IF NOT EXISTS xa_refresh_slots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slot_key VARCHAR(40) NOT NULL,
    scheduled_for_utc DATETIME NOT NULL,
    scheduled_for_local VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    completed_at DATETIME NULL,
    issued INT UNSIGNED NOT NULL DEFAULT 0,
    failed INT UNSIGNED NOT NULL DEFAULT 0,
    error_text TEXT NULL,
    result_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_xa_slot_key (slot_key),
    KEY idx_xa_slot_status_time (status, scheduled_for_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xa_refresh_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slot_id BIGINT UNSIGNED NULL,
    trigger_type VARCHAR(20) NOT NULL DEFAULT 'cron',
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    status VARCHAR(20) NOT NULL,
    users_found INT UNSIGNED NOT NULL DEFAULT 0,
    issued INT UNSIGNED NOT NULL DEFAULT 0,
    failed INT UNSIGNED NOT NULL DEFAULT 0,
    error_text TEXT NULL,
    result_json LONGTEXT NULL,
    PRIMARY KEY (id),
    KEY idx_xa_runs_started (started_at),
    KEY idx_xa_runs_slot (slot_id),
    CONSTRAINT fk_xa_runs_slot
        FOREIGN KEY (slot_id) REFERENCES xa_refresh_slots(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xa_refresh_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    user_ref VARCHAR(191) NOT NULL,
    platform VARCHAR(30) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    http_status INT UNSIGNED NOT NULL DEFAULT 0,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    error_text TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_xa_items_run (run_id),
    KEY idx_xa_items_success (success),
    CONSTRAINT fk_xa_items_run
        FOREIGN KEY (run_id) REFERENCES xa_refresh_runs(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
