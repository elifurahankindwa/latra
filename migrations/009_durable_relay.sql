-- Opt-in relay only. Does not migrate/delete source history or change schedules/settings.
CREATE TABLE IF NOT EXISTS relay_units (
            unit VARCHAR(64) PRIMARY KEY, collected_through BIGINT NULL, delivered_time BIGINT NULL,
            attempted_time BIGINT NULL, state_json TEXT NULL
        ) ENGINE=InnoDB;

-- Enrollment is explicit. Unenrolled vehicles do not fall back to legacy source writes.
CREATE TABLE IF NOT EXISTS relay_vehicle_settings (
    vehicle_id INT PRIMARY KEY,
    profile VARCHAR(32) NOT NULL,
    max_records INT NOT NULL,
    max_bytes INT NOT NULL,
    max_pending_records INT NOT NULL,
    max_pending_bytes INT NOT NULL,
    is_enabled TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS relay_identity (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, delivery_key CHAR(64) NOT NULL UNIQUE, unit VARCHAR(64) NOT NULL,
            source_time BIGINT NOT NULL, state VARCHAR(16) NOT NULL, source_key CHAR(64) NULL
        ) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS relay_pending (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, delivery_key CHAR(64) NOT NULL UNIQUE,
            unit VARCHAR(64) NOT NULL, source_time BIGINT NOT NULL, payload MEDIUMTEXT NOT NULL,
            status VARCHAR(16) NOT NULL, attempts INT NOT NULL DEFAULT 0,
            next_attempt BIGINT NOT NULL DEFAULT 0, last_error VARCHAR(500) NULL,
            INDEX unit_head (unit, source_time, id)
        ) ENGINE=InnoDB;

-- Activity identity is shared across portal, bucket and event collection streams.
CREATE TABLE IF NOT EXISTS relay_activity_identity (
    source_key CHAR(64) NOT NULL,
    activity_id INT NOT NULL,
    delivery_key CHAR(64) NOT NULL,
    PRIMARY KEY (source_key, activity_id)
) ENGINE=InnoDB;
