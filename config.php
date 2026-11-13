<?php
/**
 * LATRA-Wialon Integration Hub - Configuration
 */

function loadEnvFile(string $path): void {
    if (!is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function envValue(string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null) ? $default : (string) $value;
}

loadEnvFile(__DIR__ . '/.env');

define('LOGS_DIR', __DIR__ . '/logs');
if (!is_dir(LOGS_DIR)) {
    @mkdir(LOGS_DIR, 0755, true);
}

define('APP_ENV', envValue('APP_ENV', 'production'));
// Relay is opt-in: flipping the default before migrations 009/010 and explicit vehicle enrollment
// would make every unenrolled vehicle fail. Enable it per deployment after those steps.
define('DELIVERY_ARCHITECTURE', envValue('DELIVERY_ARCHITECTURE', 'legacy'));
if (!in_array(DELIVERY_ARCHITECTURE, ['relay', 'legacy'], true)) {
    throw new RuntimeException('Unsupported delivery architecture');
}

// Error reporting: safe off-by-default, verbose only in development.
error_reporting(E_ALL);
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', LOGS_DIR . '/error.log');

// Optional HTTPS enforcement once TLS is terminated at Apache.
define('FORCE_HTTPS', envValue('FORCE_HTTPS', 'off') === 'on');
if (FORCE_HTTPS
    && empty($_SERVER['HTTPS'])
    && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''));
    exit;
}

// Database Configuration
define('DB_HOST', envValue('DB_HOST', '127.0.0.1'));
define('DB_NAME', envValue('DB_NAME', 'latra_wialon'));
define('DB_USER', envValue('DB_USER', 'root'));
define('DB_PASS', envValue('DB_PASS', ''));
define('DB_CHARSET', envValue('DB_CHARSET', 'utf8mb4'));

// Wialon API Configuration
// Get token from: https://help.wialon.com/en/api/user-guide/getting-an-access-token
define('WIALON_BASE_URL', envValue('WIALON_BASE_URL', 'https://hst-api.wialon.com/wialon/ajax.html'));
define('WIALON_TOKEN', envValue('WIALON_TOKEN', ''));

// LATRA API Configuration
define('LATRA_API_URL', envValue('LATRA_API_URL', ''));
define('LATRA_USERNAME', envValue('LATRA_USERNAME', ''));
define('LATRA_PASSWORD', envValue('LATRA_PASSWORD', ''));

// Application Settings
define('APP_NAME', 'LATRA-Wialon');
define('APP_VERSION', '2.0.0');
define('APP_KEY', envValue('APP_KEY', ''));
define('TIMEZONE', 'Africa/Dar_es_Salaam');
define('DATE_FORMAT', 'Y-m-d H:i:s');

// Pagination
define('ITEMS_PER_PAGE', 15);

// Session
define('SESSION_TIMEOUT', 3600);

// Colors Theme
define('COLOR_BG', '#F8FAFC');
define('COLOR_CARD', '#FFFFFF');
define('COLOR_TEXT', '#64748B');
define('COLOR_PRIMARY', '#3B82F6');
define('COLOR_SUCCESS', '#10B981');
define('COLOR_WARNING', '#F59E0B');
define('COLOR_DANGER', '#EF4444');

// Paths
define('BASE_URL', rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'));
define('ASSETS_URL', BASE_URL . '/assets');

date_default_timezone_set('UTC');
if (!defined('DISPLAY_TZ')) {
    define('DISPLAY_TZ', TIMEZONE);
}

/** Convert a UTC datetime string to the display timezone. */
function toDisplay(string $utcDatetime): string {
    if ($utcDatetime === '' || $utcDatetime === '0000-00-00 00:00:00') {
        return $utcDatetime;
    }
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone(DISPLAY_TZ);
    }
    try {
        $dt = new DateTime($utcDatetime, new DateTimeZone('UTC'));
        $dt->setTimezone($tz);
        return $dt->format(DATE_FORMAT);
    } catch (Throwable $e) {
        return $utcDatetime;
    }
}

/** HTML-escape helper for output. Never double-wrap values already escaped. */
function e($value): string {
    if ($value === null || $value === false) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://*.tile.openstreetmap.org https://server.arcgisonline.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com https://unpkg.com; connect-src 'self' https://nominatim.openstreetmap.org");
    if (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Hardened session bootstrap (shared by pages, api.php and login.php)
if (session_status() === PHP_SESSION_NONE) {
    session_name('LATRA_SID');
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    ]);
    session_start();
}

require_once __DIR__ . '/includes/autoload.php';

// Create includes directory if not exists
if (!is_dir(__DIR__ . '/includes')) {
    mkdir(__DIR__ . '/includes', 0755, true);
}
