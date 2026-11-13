-- Persist the cross-stream source identity needed when held reports are recovered.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'relay_identity' AND COLUMN_NAME = 'source_key') = 0,
    'ALTER TABLE relay_identity ADD COLUMN source_key CHAR(64) NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
