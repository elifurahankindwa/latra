<?php
/**
 * Authentication, session guarding, CSRF protection, rate limiting and audit.
 */
class Auth {

    public static function ensureTables(): void {
        Database::query("CREATE TABLE IF NOT EXISTS app_users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(100) NOT NULL DEFAULT '',
            role VARCHAR(20) NOT NULL DEFAULT 'operator',
            failed_logins INT NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        Database::query("CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NULL,
            username VARCHAR(50) NULL,
            action VARCHAR(50) NOT NULL,
            target VARCHAR(120) NOT NULL DEFAULT '',
            detail TEXT DEFAULT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_created (created_at),
            INDEX idx_audit_user (user_id)
        ) ENGINE=InnoDB");
        Database::query("CREATE TABLE IF NOT EXISTS rate_limits (
            rate_key VARCHAR(64) NOT NULL,
            window_start BIGINT NOT NULL,
            cnt INT NOT NULL DEFAULT 0,
            PRIMARY KEY (rate_key, window_start)
        ) ENGINE=InnoDB");
    }

    /** Verify credentials; on success populate the session. */
    public static function attempt(string $username, string $password, array $extra = []): bool {
        self::ensureTables();
        $user = Database::fetch("SELECT * FROM app_users WHERE username = ? AND is_active = 1", [$username]);
        if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            self::audit('login_locked', 'user', $username);
            self::logLoginAttempt($username, 'locked', $extra);
            return false;
        }
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        if (!$user || !password_verify($password, $user['password_hash'])) {
            if ($user) {
                $fails = (int) ($user['failed_logins'] ?? 0) + 1;
                $lock = $fails >= 8 ? date(DATE_FORMAT, time() + 900) : null;
                Database::update('app_users', ['failed_logins' => $fails, 'locked_until' => $lock], 'id = ?', [(int) $user['id']]);
            }
            self::audit('login_failed', 'user', $username);
            self::logLoginAttempt($username, 'failed', $extra);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['auth'] = [
            'uid' => (int) $user['id'],
            'username' => $user['username'],
            'display_name' => $user['display_name'] ?: $user['username'],
            'role' => $user['role'] ?? 'operator',
        ];
        $_SESSION['auth_started'] = time();
        $_SESSION['last_activity'] = time();
        Database::update('app_users', [
            'last_login_at' => date(DATE_FORMAT),
            'failed_logins' => 0,
            'locked_until' => null,
        ], 'id = ?', [(int) $user['id']]);
        self::audit('login', 'user', $user['username']);
        self::logLoginAttempt($user['username'], 'success', $extra);
        return true;
    }

    public static function check(): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['auth'])) {
            return false;
        }
        if (isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > SESSION_TIMEOUT) {
            self::logout();
            return false;
        }
        if (isset($_SESSION['auth_started']) && (time() - (int) $_SESSION['auth_started']) > 43200) {
            self::logout();
            return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }

    public static function requireLogin(): void {
        if (!self::check()) {
            header('Location: login');
            exit;
        }
        if (class_exists('Schema')) {
            try { Schema::ensure(); } catch (Throwable $e) { error_log('schema ensure: ' . $e->getMessage()); }
        }
    }

    public static function logout(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    public static function user(): array {
        return $_SESSION['auth'] ?? ['uid' => 0, 'username' => '', 'display_name' => '', 'role' => ''];
    }

    public static function role(): string {
        return self::user()['role'] ?? '';
    }

    public static function isAdmin(): bool {
        return self::role() === 'admin';
    }

    public static function canWrite(): bool {
        return in_array(self::role(), ['admin', 'operator'], true);
    }

    /** View (read-only) access: any authenticated internal or LATRA role. */
    public static function canView(): bool {
        return self::role() !== '';
    }

    public static function requireRole(array $roles): void {
        self::requireLogin();
        if (!in_array(self::role(), $roles, true)) {
            self::deny(403);
        }
    }

    /** Require login + the given permission; used for page access. */
    public static function requireView(string $perm): void {
        self::requireLogin();
        if (!self::hasPermission($perm)) {
            self::deny(403);
        }
    }

    /**
     * Refuse a page request with the branded error page.
     * API-ish requests still receive their JSON/text error instead of HTML.
     */
    private static function deny(int $code = 403): void {
        if (class_exists('ErrorPage')) {
            ErrorPage::reject($code);
        } else {
            http_response_code($code);
            echo 'Forbidden';
        }
        exit;
    }

    public static function requireWrite(): void {
        if (!self::canWrite()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Read-only role']);
            exit;
        }
    }

    /**
     * LATRA-facing restricted roles. Internal roles (admin/operator) hold every permission.
     * Permission is enforced server-side — the UI only hides what it offers.
     */
    public static function roleLabels(): array {
        return [
            'admin'          => 'Administrator',
            'operator'       => 'Operator',
            'viewer'         => 'Viewer (read-only)',
            'latra_user'     => 'LATRA user',
            'latra_viewer'   => 'LATRA viewer',
            'latra_inspector'=> 'LATRA inspector',
            'latra_admin'    => 'LATRA administrator',
        ];
    }

    /** Read-only permission set shared by the internal viewer role and LATRA viewers. */
    public static function viewerPermissions(): array {
        return [
            'dashboard.view', 'live.view', 'vehicles.view', 'devices.view',
            'gps.view', 'events.view', 'integration.view', 'reports.view',
            'audit.view', 'device.parameters.view',
        ];
    }

    public static function latraPermissions(): array {
        return [
            'latra_user' => [
                'dashboard.view', 'live.view', 'vehicles.view', 'devices.view',
                'gps.view', 'events.view', 'integration.view', 'reports.view',
                'device.parameters.view',
            ],
            'latra_viewer' => [
                'dashboard.view', 'live.view', 'vehicles.view', 'devices.view',
                'gps.view', 'events.view', 'integration.view', 'reports.view',
                'device.parameters.view',
            ],
            'latra_inspector' => [
                'dashboard.view', 'live.view', 'vehicles.view', 'devices.view',
                'gps.view', 'events.view', 'integration.view', 'reports.view',
                'audit.view', 'device.interrogate', 'device.parameters.view',
            ],
            'latra_admin' => [
                'dashboard.view', 'live.view', 'vehicles.view', 'devices.view',
                'gps.view', 'events.view', 'integration.view', 'reports.view',
                'audit.view', 'device.interrogate', 'device.parameters.view',
                'device.parameters.update', 'device.commands.view',
            ],
        ];
    }

    public static function isLatraRole(?string $role = null): bool {
        $role = $role ?? self::role();
        return isset(self::latraPermissions()[$role]);
    }

    public static function hasPermission(string $perm): bool {
        $role = self::role();
        if (in_array($role, ['admin', 'operator'], true)) {
            return true;
        }
        if ($role === 'viewer') {
            return in_array($perm, self::viewerPermissions(), true);
        }
        return in_array($perm, self::latraPermissions()[$role] ?? [], true);
    }

    public static function requirePermission(string $perm): void {
        self::requireLogin();
        if (!self::hasPermission($perm)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'This action requires the "' . $perm . '" permission.']);
            exit;
        }
    }

    public static function csrfToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfField(): string {
        return '<input type="hidden" name="_csrf" value="' . self::csrfToken() . '">';
    }

    /** Validate a CSRF token from POST body, header or query string. */
    public static function verifyCsrf(?string $token): bool {
        if (!$token) {
            return false;
        }
        return hash_equals(self::csrfToken(), $token);
    }

    /** State-changing POST? performs the token check. */
    public static function requireCsrf(): void {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['_csrf'] ?? '';
        if (!self::verifyCsrf((string) $token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
    }

    // Write a simple audit line.
    public static function audit(string $action, string $target = '', string $detail = '', $actor = null, $before = null, $after = null): void {
        try {
            $u = is_array($actor) ? $actor : self::user();
            if ($actor === 'system') {
                $u = ['uid' => 0, 'username' => 'system'];
            }
            Database::insert('audit_log', [
                'user_id' => $u['uid'] ?: null,
                'username' => $u['username'] ?: null,
                'action' => $action,
                'target' => $target,
                'detail' => $detail !== '' ? substr($detail, 0, 2000) : null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'before_json' => $before !== null ? json_encode($before) : null,
                'after_json' => $after !== null ? json_encode($after) : null,
            ]);
        } catch (Throwable $e) {
            error_log('audit write skipped: ' . $e->getMessage());
        }
    }

    // Simple fixed-window rate limit.
    public static function rateLimit(string $key, int $max, int $windowSeconds): bool {
        self::ensureTables();
        $windowStart = intdiv((int) time(), $windowSeconds) * $windowSeconds;
        Database::query(
            "INSERT INTO rate_limits (rate_key, window_start, cnt) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE cnt = cnt + 1",
            [$key, $windowStart]
        );
        $row = Database::fetch("SELECT cnt FROM rate_limits WHERE rate_key = ? AND window_start = ?", [$key, $windowStart]);
        return (int) ($row['cnt'] ?? 1) <= $max;
    }

    public static function clientKey(string $prefix = 'ip'): string {
        return $prefix . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }

    /**
     * Append a full device-detail line for a login attempt to logs/login.log.
     * Never fatal: failures are silently dropped. Server-side values always
     * win over anything a client submits in $extra.
     *
     * @param string $username attempted username
     * @param string $result   success|failed|locked|rate_limited|invalid
     * @param array  $extra    optional client-supplied fingerprint fields
     */
    public static function logLoginAttempt(string $username, string $result, array $extra = []): void {
        if (!defined('LOGS_DIR')) {
            return;
        }
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $dev = DeviceDetect::parse($ua);

        $row = array_merge($extra, [
            'ts'               => gmdate('Y-m-d\TH:i:s\Z'),
            'event'            => 'login.attempt',
            'username'         => $username,
            'result'           => $result,
            'ip'               => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'x_forwarded_for'  => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            'x_real_ip'        => (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
            'cf_connecting_ip' => (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
            'os'               => $dev['os'],
            'os_version'       => $dev['os_version'],
            'browser'          => $dev['browser'],
            'browser_version'  => $dev['browser_version'],
            'device'           => $dev['device'],
            'ua'               => $ua,
            'accept_language'  => (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),
            'referer'          => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
            'origin'           => (string) ($_SERVER['HTTP_ORIGIN'] ?? ''),
            'https'            => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'http_version'     => (string) ($_SERVER['SERVER_PROTOCOL'] ?? ''),
            'method'           => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        ]);

        // Optional reverse-DNS hostname of the client IP (adds latency; opt-in).
        if (envValue('LOGIN_LOG_DNS', 'off') === 'on' && $row['ip'] !== '') {
            $dns = @gethostbyaddr($row['ip']);
            $row['dns_hostname'] = ($dns && $dns !== $row['ip']) ? $dns : '';
        }

        @file_put_contents(LOGS_DIR . '/login.log', json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Sanitize the client-side device fingerprint into a whitelisted array
     * that is safe to merge into a login.attempt log line.
     */
    public static function deviceExtra(string $json): array {
        $out = [];
        if ($json === '') {
            return $out;
        }
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return $out;
        }
        foreach (['timezone', 'local_time', 'screen', 'languages', 'platform', 'cores', 'memory', 'connection', 'vendor'] as $k) {
            if (!isset($d[$k])) {
                continue;
            }
            $v = $d[$k];
            if (is_string($v) || is_int($v) || is_float($v)) {
                $out[$k] = $v;
            } elseif (is_array($v)) {
                $out[$k] = implode(',', array_map('strval', $v));
            }
        }
        return $out;
    }
}