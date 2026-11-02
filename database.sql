-- LATRA-Wialon Integration Database Schema
-- Created: 2026-08-24

CREATE DATABASE IF NOT EXISTS latra_wialon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE latra_wialon;

-- ============================================================
-- CONFIGURATION TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT,
    description VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO config (config_key, config_value, description) VALUES
('system_name', 'LATRA-Wialon Integration Hub', 'Application name'),
('cron_enabled', '0', 'Enable automated tasks'),
('last_sync', '0', 'Last successful sync timestamp');

-- ============================================================
-- VEHICLES TABLE (Synced from Wialon)
-- ============================================================
CREATE TABLE IF NOT EXISTS vehicles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    wialon_unit_id BIGINT NOT NULL UNIQUE,
    vehicle_reg_no VARCHAR(50) NOT NULL,
    name VARCHAR(100),
    imei VARCHAR(20),
    hw_type VARCHAR(50),
    phone VARCHAR(20),
    lat DECIMAL(10, 8) DEFAULT NULL,
    lng DECIMAL(11, 8) DEFAULT NULL,
    altitude INT DEFAULT NULL,
    speed DECIMAL(8, 2) DEFAULT NULL,
    bearing DECIMAL(6, 2) DEFAULT NULL,
    satellite_count INT DEFAULT NULL,
    hdop DECIMAL(6, 2) DEFAULT NULL,
    d2d3 INT DEFAULT NULL,
    rssi INT DEFAULT NULL,
    lac INT DEFAULT NULL,
    cell_id INT DEFAULT NULL,
    mcc INT DEFAULT NULL,
    mgs_id BIGINT DEFAULT NULL,
    last_message_time BIGINT DEFAULT NULL,
    last_sync_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    is_mapped TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_reg_no (vehicle_reg_no),
    INDEX idx_active (is_active),
    INDEX idx_mapped (is_mapped),
    INDEX idx_veh_last_message_time (last_message_time),
    INDEX idx_veh_lat_lng (lat, lng)
);

-- ============================================================
-- ACTIVITY MAPPINGS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_mappings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id INT NOT NULL,
    activity_id INT NOT NULL COMMENT '1-24 per LATRA spec',
    is_enabled TINYINT(1) DEFAULT 0,
    wialon_sensor_name VARCHAR(100) DEFAULT NULL COMMENT 'Wialon sensor/param name',
    mapping_rule TEXT DEFAULT NULL COMMENT 'JSON mapping rule',
    last_pushed_at TIMESTAMP NULL,
    push_count INT DEFAULT 0,
    fail_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vehicle_activity (vehicle_id, activity_id),
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
);

-- ============================================================
-- ACTIVITY DEFINITIONS (LATRA 24 Activities)
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_definitions (
    id INT PRIMARY KEY,
    activity_name VARCHAR(100) NOT NULL,
    description TEXT,
    requires_addon TINYINT(1) DEFAULT 0,
    addon_schema TEXT DEFAULT NULL COMMENT 'JSON schema for addon_info',
    category VARCHAR(50) DEFAULT 'general',
    icon VARCHAR(50) DEFAULT 'activity'
);

INSERT INTO activity_definitions (id, activity_name, description, requires_addon, addon_schema, category, icon) VALUES
(1, 'Movement/Logging', 'Regular GPS position logging', 0, NULL, 'tracking', 'map-pin'),
(2, 'Engine ON', 'Engine started event', 1, '{"idleTime":"seconds","v_driver_identification_no":"16-digit hex"}', 'engine', 'power'),
(3, 'Engine OFF', 'Engine stopped event (Trip End)', 1, '{"distance_travelled":"km","trip_duration":"minutes","avgSpeed":"km/h","maxSpeed":"km/h"}', 'engine', 'power-off'),
(4, 'Speeding', 'Vehicle exceeded speed limit', 0, NULL, 'alert', 'alert-triangle'),
(5, 'Hash Braking', 'Harsh braking detected', 0, NULL, 'alert', 'alert-circle'),
(6, 'Hash Turning', 'Harsh turning detected', 0, NULL, 'alert', 'corner-up-left'),
(7, 'Hash Acceleration', 'Harsh acceleration detected', 0, NULL, 'alert', 'trending-up'),
(8, 'Panic Button (Driver)', 'Driver pressed panic button', 0, NULL, 'emergency', 'alert-octagon'),
(9, 'Internal Battery Low', 'Device internal battery low voltage', 1, '{"ext_power_voltage":"V","int_battery_voltage":"V"}', 'power', 'battery'),
(10, 'External Power Disconnected', 'External power disconnected', 1, '{"ext_power_voltage":"V","int_battery_voltage":"V"}', 'power', 'battery-charging'),
(11, 'Excessive Idle', 'Vehicle idling excessively', 0, NULL, 'alert', 'clock'),
(12, 'Accident', 'Accident detected', 0, NULL, 'emergency', 'shield-off'),
(13, 'Panic Button (Passenger)', 'Passenger pressed panic button', 0, NULL, 'emergency', 'alert-octagon'),
(14, 'Device Tampering', 'GPS antenna disconnected or tampered', 0, NULL, 'security', 'lock'),
(15, 'Black Box Data Logging', 'Black box data logging event', 0, NULL, 'data', 'hard-drive'),
(16, 'Fuel Data Report', 'Fuel level data report', 1, '{"validFlag":"0/1","signalLevel":"0-99","softStatus":"0=normal","hardFault":"0=normal","fuelLevel":"mm","rtFuelLevel":"mm","tankTemp":"Celsius*10","channel":"1"}', 'fuel', 'droplet'),
(17, 'Invalid Scan', 'Invalid iButton/driver scan', 1, '{"v_driver_identification_no":"16-digit hex"}', 'driver', 'x-circle'),
(18, 'Engine Start', 'Engine start event', 0, NULL, 'engine', 'play'),
(19, 'Engine Stop', 'Engine stop event', 0, NULL, 'engine', 'square'),
(20, 'Enter Boundary', 'Vehicle entered geofence boundary', 0, NULL, 'geo', 'map'),
(21, 'Leave Boundary', 'Vehicle left geofence boundary', 0, NULL, 'geo', 'map-off'),
(22, 'Enter Checkpoint', 'Vehicle entered checkpoint', 0, NULL, 'geo', 'check-circle'),
(23, 'Leave Checkpoint', 'Vehicle left checkpoint', 0, NULL, 'geo', 'check-circle'),
(24, 'iButton Scan (Regular)', 'Regular iButton/driver identification scan', 1, '{"v_driver_identification_no":"16-digit hex"}', 'driver', 'user-check');

-- ============================================================
-- MESSAGE LOG TABLE (Raw Wialon messages stored locally)
-- ============================================================
CREATE TABLE IF NOT EXISTS message_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id INT NOT NULL,
    wialon_msg_time BIGINT NOT NULL,
    raw_data JSON NOT NULL,
    lat DECIMAL(10, 8) DEFAULT NULL,
    lng DECIMAL(11, 8) DEFAULT NULL,
    altitude INT DEFAULT NULL,
    speed DECIMAL(8, 2) DEFAULT NULL,
    bearing DECIMAL(6, 2) DEFAULT NULL,
    satellite_count INT DEFAULT NULL,
    hdop DECIMAL(6, 2) DEFAULT NULL,
    d2d3 INT DEFAULT NULL,
    rssi INT DEFAULT NULL,
    lac INT DEFAULT NULL,
    cell_id INT DEFAULT NULL,
    mcc INT DEFAULT NULL,
    mgs_id BIGINT DEFAULT NULL,
    activity_id INT DEFAULT 1,
    addon_info JSON DEFAULT NULL,
    fuel_info JSON DEFAULT NULL,
    latra_payload JSON DEFAULT NULL,
    latra_status VARCHAR(10) DEFAULT 'pending',
    latra_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    latra_response TEXT DEFAULT NULL,
    pushed_at TIMESTAMP NULL,
    latra_manual_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_time (wialon_msg_time),
    INDEX idx_status (latra_status),
    INDEX idx_activity (activity_id),
    INDEX idx_ml_created (created_at),
    INDEX idx_ml_vehicle_created (vehicle_id, created_at),
    INDEX idx_ml_status_created (latra_status, created_at),
    INDEX idx_ml_vehicle_status_created (vehicle_id, latra_status, created_at),
    INDEX idx_ml_vehicle_time (vehicle_id, wialon_msg_time),
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
);

-- ============================================================
-- AUTOMATION / SCHEDULER TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS automation_jobs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_name VARCHAR(100) NOT NULL,
    vehicle_id INT DEFAULT NULL COMMENT 'NULL = all vehicles',
    activity_id INT DEFAULT NULL COMMENT 'NULL = all activities',
    cron_expression VARCHAR(100) NOT NULL COMMENT 'e.g. */5 * * * *',
    is_enabled TINYINT(1) DEFAULT 1,
    last_run_at TIMESTAMP NULL,
    next_run_at TIMESTAMP NULL,
    run_count INT DEFAULT 0,
    fail_count INT DEFAULT 0,
    last_error TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL
);

-- ============================================================
-- BUCKET AUTOMATION TABLES
-- ============================================================
CREATE TABLE IF NOT EXISTS automation_buckets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    bucket_name VARCHAR(100) NOT NULL,
    activity_id INT NOT NULL,
    interval_seconds INT NOT NULL DEFAULT 300,
    cooldown_seconds INT NOT NULL DEFAULT 30,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at TIMESTAMP NULL,
    next_run_at TIMESTAMP NULL,
    run_count INT NOT NULL DEFAULT 0,
    fail_count INT NOT NULL DEFAULT 0,
    last_error TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (activity_id) REFERENCES activity_definitions(id)
);

CREATE TABLE IF NOT EXISTS bucket_vehicles (
    bucket_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    PRIMARY KEY (bucket_id, vehicle_id),
    FOREIGN KEY (bucket_id) REFERENCES automation_buckets(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS automation_runs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    bucket_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    total_count INT NOT NULL DEFAULT 0,
    success_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    manual_count INT NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    FOREIGN KEY (bucket_id) REFERENCES automation_buckets(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS automation_run_items (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    run_id BIGINT NOT NULL,
    bucket_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    activity_id INT NOT NULL,
    message_log_id BIGINT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    last_error TEXT DEFAULT NULL,
    completed_at DATETIME NULL,
    FOREIGN KEY (run_id) REFERENCES automation_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (bucket_id) REFERENCES automation_buckets(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (message_log_id) REFERENCES message_log(id) ON DELETE SET NULL
);


-- ============================================================
-- PUSH LOG TABLE (Audit trail)
-- ============================================================
CREATE TABLE IF NOT EXISTS push_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id INT NOT NULL,
    activity_id INT NOT NULL,
    message_log_id BIGINT DEFAULT NULL,
    payload JSON NOT NULL,
    response_status VARCHAR(10) DEFAULT NULL,
    response_body TEXT DEFAULT NULL,
    http_code INT DEFAULT NULL,
    latency_ms INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_activity (activity_id),
    INDEX idx_created (created_at),
    INDEX idx_pl_vehicle_created (vehicle_id, created_at),
    INDEX idx_pl_status_created (response_status, created_at),
    INDEX idx_pl_vehicle_status_created (vehicle_id, response_status, created_at),
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (message_log_id) REFERENCES message_log(id) ON DELETE SET NULL
);

-- ============================================================
-- HARDWARE FAULT CODE REFERENCE
-- ============================================================
CREATE TABLE IF NOT EXISTS hardware_fault_codes (
    code INT PRIMARY KEY,
    description VARCHAR(255) NOT NULL
);

INSERT INTO hardware_fault_codes (code, description) VALUES
(0, 'No Fault'),
(1, 'Probe Cable Failed (connection fail)'),
(2, 'Probe Cable Intermediate (connection lose)'),
(3, 'Reserved'),
(4, 'Sensor Coupling Layer abnormal'),
(5, 'Detected fuel level zero'),
(6, 'Reserved'),
(7, 'Reserved'),
(8, 'Reserved'),
(9, 'Reserved');

-- ============================================================
-- DATA LIFECYCLE (schema v11)
-- ============================================================
-- Remaining lifecycle tables (jobs, job_runs, outbox, validation_errors,
-- emergency_queue/events, wialon_events, rate_limits, archives, ...) are
-- created at runtime by Schema::ensure(). These two are listed here so a fresh
-- install has the full retention/background schema up front. Their indexes,
-- config windows and jobs are also applied by Schema::ensure() / migration 009.
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
);

CREATE TABLE IF NOT EXISTS retention_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    ran_at DATETIME NOT NULL,
    duration_ms INT NOT NULL DEFAULT 0,
    rows_deleted INT NOT NULL DEFAULT 0,
    dry_run TINYINT(1) NOT NULL DEFAULT 0,
    summary JSON NULL,
    INDEX idx_retention_ran (ran_at)
);
