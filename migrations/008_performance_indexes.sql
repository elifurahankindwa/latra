-- ============================================================
-- Performance indexes for the dashboard / time-window queries
-- ============================================================
-- Backs the dashboard's cold-load COUNT(*) queries (message_log,
-- wialon_events, push_log, outbox), the map / down-equipment scans on
-- vehicles, and the LATRA-facing date filters.
--
-- Without these, created_at predicates are residual filters, so a cold
-- dashboard load walks whole per-vehicle histories and can exceed
-- Cloudflare's 100s origin timeout (HTTP 524) once message_log is large.
--
-- Idempotent: every index is skipped when it already exists. The same set is
-- applied automatically by Schema::ensure() (schema v10+) on upgrade, so this
-- file is only needed for a manual/DB-side provisioning step.
--
-- Run:  mysql -u root <your_database> < migrations/008_performance_indexes.sql
-- ============================================================

-- ------------------------------------------------------------
-- message_log
-- ------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log' AND INDEX_NAME = 'idx_ml_created') = 0,
    'ALTER TABLE message_log ADD INDEX idx_ml_created (created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log' AND INDEX_NAME = 'idx_ml_vehicle_created') = 0,
    'ALTER TABLE message_log ADD INDEX idx_ml_vehicle_created (vehicle_id, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log' AND INDEX_NAME = 'idx_ml_status_created') = 0,
    'ALTER TABLE message_log ADD INDEX idx_ml_status_created (latra_status, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_log' AND INDEX_NAME = 'idx_ml_vehicle_status_created') = 0,
    'ALTER TABLE message_log ADD INDEX idx_ml_vehicle_status_created (vehicle_id, latra_status, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- push_log
-- ------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_log' AND INDEX_NAME = 'idx_pl_vehicle_created') = 0,
    'ALTER TABLE push_log ADD INDEX idx_pl_vehicle_created (vehicle_id, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_log' AND INDEX_NAME = 'idx_pl_status_created') = 0,
    'ALTER TABLE push_log ADD INDEX idx_pl_status_created (response_status, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_log' AND INDEX_NAME = 'idx_pl_vehicle_status_created') = 0,
    'ALTER TABLE push_log ADD INDEX idx_pl_vehicle_status_created (vehicle_id, response_status, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- outbox
-- ------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbox' AND INDEX_NAME = 'idx_ob_created') = 0,
    'ALTER TABLE outbox ADD INDEX idx_ob_created (created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbox' AND INDEX_NAME = 'idx_ob_vehicle_created') = 0,
    'ALTER TABLE outbox ADD INDEX idx_ob_vehicle_created (vehicle_id, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbox' AND INDEX_NAME = 'idx_ob_status_created') = 0,
    'ALTER TABLE outbox ADD INDEX idx_ob_status_created (status, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbox' AND INDEX_NAME = 'idx_ob_vehicle_status_created') = 0,
    'ALTER TABLE outbox ADD INDEX idx_ob_vehicle_status_created (vehicle_id, status, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- wialon_events
-- ------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wialon_events' AND INDEX_NAME = 'idx_we_created') = 0,
    'ALTER TABLE wialon_events ADD INDEX idx_we_created (created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wialon_events' AND INDEX_NAME = 'idx_we_vehicle_created') = 0,
    'ALTER TABLE wialon_events ADD INDEX idx_we_vehicle_created (vehicle_id, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wialon_events' AND INDEX_NAME = 'idx_we_status_created') = 0,
    'ALTER TABLE wialon_events ADD INDEX idx_we_status_created (status, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wialon_events' AND INDEX_NAME = 'idx_we_vehicle_status_created') = 0,
    'ALTER TABLE wialon_events ADD INDEX idx_we_vehicle_status_created (vehicle_id, status, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- validation_errors
-- ------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'validation_errors' AND INDEX_NAME = 'idx_ve_vehicle_created') = 0,
    'ALTER TABLE validation_errors ADD INDEX idx_ve_vehicle_created (vehicle_id, created_at)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- vehicles
-- ------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND INDEX_NAME = 'idx_veh_last_message_time') = 0,
    'ALTER TABLE vehicles ADD INDEX idx_veh_last_message_time (last_message_time)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND INDEX_NAME = 'idx_veh_lat_lng') = 0,
    'ALTER TABLE vehicles ADD INDEX idx_veh_lat_lng (lat, lng)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
