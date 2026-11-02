-- ============================================================
-- Data lifecycle: background task queue, retention audit log,
-- retention-supporting indexes, config and jobs (schema v11)
-- ============================================================
-- Companion to includes/Retention.php and includes/BackgroundTasks.php.
--
-- * background_tasks : large imports/pushes run in the worker, not in HTTP.
-- * retention_log    : one row per retention cycle (bounded by the engine).
-- * indexes          : back the chunked purge predicates (age columns) and the
--                      per-vehicle MAX(wialon_msg_time) high-water mark.
-- * config/jobs      : new retention windows + the emergency_reap and
--                      background_tasks scheduler jobs.
--
-- Idempotent: safe to re-run. The same DDL/index/config are applied
-- automatically by Schema::ensure() (schema v11) on upgrade, so this file is
-- only needed for a manual / DB-side provisioning step.
--
-- Run:  mysql -u root <your_database> < migrations/009_lifecycle.sql
-- ============================================================

-- ------------------------------------------------------------
-- background_tasks
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS background_tasks (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    task_type VARCHAR(60) NOT NULL,
    params JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    priority TINYINT NOT NULL DEFAULT 0,
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    batch_token CHAR(32) NULL,
    run_after DATETIME NULL,
    locked_until DATETIME NULL,
    result TEXT NULL,
    error TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    INDEX idx_bg_due (status, run_after, priority),
    INDEX idx_bg_token (batch_token)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- retention_log
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS retention_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    ran_at DATETIME NOT NULL,
    duration_ms INT NOT NULL DEFAULT 0,
    rows_deleted INT NOT NULL DEFAULT 0,
    dry_run TINYINT(1) NOT NULL DEFAULT 0,
    summary JSON NULL,
    INDEX idx_retention_ran (ran_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- message_log: per-vehicle time high-water mark
-- ------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log' AND INDEX_NAME = 'idx_ml_vehicle_time') = 0,
    'ALTER TABLE message_log ADD INDEX idx_ml_vehicle_time (vehicle_id, wialon_msg_time)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- emergency_queue: join/age index for the reaper and retention
-- ------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emergency_queue' AND INDEX_NAME = 'idx_eq_event_created') = 0,
    'ALTER TABLE emergency_queue ADD INDEX idx_eq_event_created (emergency_event_id, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- Operational-history age indexes
-- ------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_runs' AND INDEX_NAME = 'idx_jobruns_started') = 0,
    'ALTER TABLE job_runs ADD INDEX idx_jobruns_started (started_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'automation_runs' AND INDEX_NAME = 'idx_ar_started') = 0,
    'ALTER TABLE automation_runs ADD INDEX idx_ar_started (started_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND INDEX_NAME = 'idx_al_created') = 0,
    'ALTER TABLE audit_log ADD INDEX idx_al_created (created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND INDEX_NAME = 'idx_wh_created') = 0,
    'ALTER TABLE webhook_events ADD INDEX idx_wh_created (created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_limits' AND INDEX_NAME = 'idx_rl_window') = 0,
    'ALTER TABLE rate_limits ADD INDEX idx_rl_window (window_start)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- Retention config windows
-- ------------------------------------------------------------
INSERT IGNORE INTO config (config_key, config_value, description) VALUES
    ('rejected_retention_days', '30', 'Keep rejected message_log / validation_errors this many days'),
    ('stale_pending_retention_days', '7', 'Delete pending/failed rows not queued in the outbox after this many days'),
    ('validation_error_retention_days', '30', 'Keep validation_errors this many days'),
    ('emergency_retention_days', '7', 'Keep ended emergency events and their buffered records this many days'),
    ('emergency_idle_seconds', '900', 'Auto-close an emergency event idle for this many seconds'),
    ('push_log_retention_days', '30', 'Keep LATRA push receipts this many days'),
    ('webhook_retention_days', '14', 'Keep webhook delivery attempts this many days'),
    ('rate_limit_retention_days', '14', 'Keep expired rate-limit buckets this many days'),
    ('job_run_retention_days', '60', 'Keep job/automation run history this many days'),
    ('audit_retention_days', '365', 'Keep audit_log this many days'),
    ('ai_retention_days', '180', 'Keep AI chat history this many days'),
    ('archive_days', '180', 'Keep archived message/push rows this many days'),
    ('archive_hot_messages', '1', 'Move message_log older than retention_days into the archive before deleting'),
    ('background_task_batch', '5', 'Background tasks claimed per worker tick'),
    ('background_task_seconds', '120', 'Wall-clock budget for the background worker per tick'),
    ('bulk_sse_max_seconds', '90', 'Hard wall-clock budget for a bulk SSE request before it hands off to the background');

-- ------------------------------------------------------------
-- Scheduler jobs
-- ------------------------------------------------------------
INSERT IGNORE INTO jobs (job_key, label, interval_seconds, next_run_at) VALUES
    ('emergency_reap', 'Close idle emergency events', 300, NOW()),
    ('background_tasks', 'Run queued background imports', 15, NOW());

-- ------------------------------------------------------------
-- Schema version
-- ------------------------------------------------------------
INSERT INTO config (config_key, config_value, description)
VALUES ('schema_version', '11', 'Platform schema version')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
