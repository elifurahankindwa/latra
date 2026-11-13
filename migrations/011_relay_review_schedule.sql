-- Review rows require an explicit operator action, not a scheduled retry.
ALTER TABLE relay_pending MODIFY next_attempt BIGINT NULL DEFAULT 0;
UPDATE relay_pending SET next_attempt = NULL WHERE status = 'review';
