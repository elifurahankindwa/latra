-- Apply with: php scripts/migrate-delivery.php (checks index names before each DDL).
-- The operator should schedule index creation for the actual DB engine/table sizes.
ALTER TABLE message_log ADD INDEX idx_delivery_vehicle_time (vehicle_id, wialon_msg_time);
ALTER TABLE outbox ADD INDEX idx_delivery_message_status (message_id, status);
ALTER TABLE wialon_events ADD INDEX idx_delivery_message_status (message_log_id, status);
ALTER TABLE push_log ADD INDEX idx_delivery_export (created_at, id);
ALTER TABLE automation_run_items ADD INDEX idx_delivery_message_status (message_log_id, status);
ALTER TABLE rate_limits ADD INDEX idx_delivery_expiry (window_start);
