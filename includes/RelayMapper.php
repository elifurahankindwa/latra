<?php
require_once __DIR__ . '/autoload.php';

// Legacy global name kept for existing scripts/tests; the implementation now lives in Application.
if (!class_exists('RelayMapper', false)) {
    class_alias(\Latra\Application\Collection\RelayPayloadMapper::class, 'RelayMapper');
}
