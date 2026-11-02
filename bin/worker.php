
<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/WialonAPI.php';
require_once __DIR__ . '/../includes/LatraMapper.php';
require_once __DIR__ . '/../includes/Automation.php';
require_once __DIR__ . '/../includes/GpsValidator.php';
require_once __DIR__ . '/../includes/EventDetector.php';
require_once __DIR__ . '/../includes/GpsMonitor.php';
require_once __DIR__ . '/../includes/DeviceMonitor.php';
require_once __DIR__ . '/../includes/EmergencyHandler.php';

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () { fwrite(STDERR, "worker stop\n"); exit(0); });
    pcntl_signal(SIGINT, static function () { exit(0); });
}

Schema::ensure();
fwrite(STDOUT, "[" . date(DATE_FORMAT) . "] worker started pid=" . getmypid() . "\n");

// Keep the original 15-second cadence without requiring an exact wall-clock match.
$nextJobsAt = hrtime(true) + 15 * 1_000_000_000;
while (true) {
    try {
        Scheduler::tickBuckets();
    } catch (Throwable $e) {
        fwrite(STDERR, "[" . date(DATE_FORMAT) . "] bucket tick error: " . $e->getMessage() . "\n");
        Logger::error('worker.bucket_tick', ['error' => $e->getMessage()]);
    }
    try {
        EventEngine::runCycle();
    } catch (Throwable $e) {
        fwrite(STDERR, "[" . date(DATE_FORMAT) . "] event engine error: " . $e->getMessage() . "\n");
        Logger::error('worker.event_engine', ['error' => $e->getMessage()]);
    }
    $active = Database::fetch("SELECT COUNT(*) AS c FROM emergency_events WHERE status = 'active'");
    $speed = ((int) ($active['c'] ?? 0) > 0) ? 2 : 5;
    sleep($speed);

    if (hrtime(true) >= $nextJobsAt) {
        $nextJobsAt = hrtime(true) + 15 * 1_000_000_000;
        try {
            Scheduler::tickJobs();
        } catch (Throwable $e) {
            fwrite(STDERR, "[" . date(DATE_FORMAT) . "] job tick error: " . $e->getMessage() . "\n");
            Logger::error('worker.job_tick', ['error' => $e->getMessage()]);
        }
    }
}
