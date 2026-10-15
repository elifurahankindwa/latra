<?php
/**
 * Branded HTTP error pages.
 *
 * Dependency-free on purpose: this class is the ErrorDocument target and the
 * fatal-error renderer, i.e. it runs exactly when config.php, the database,
 * the autoloader or the session may be broken. It must never require them.
 *
 * Usage from PHP:      ErrorPage::emit(403);
 * Usage as 500 guard:  ErrorPage::fatal(500, 'json');
 * Usage from Apache:   error.php (ErrorDocument target)
 */
final class ErrorPage
{
    /** Friendly copy for the codes this app realistically produces. */
    private const CATALOG = [
        400 => ['Bad request', 'The browser sent a request this server could not understand.', 'triangle-alert', 'Check the address for typos or invalid characters, then try again.'],
        401 => ['Authentication required', 'You need to sign in before opening this page.', 'lock', 'Sign in to continue.'],
        403 => ['Access denied', 'Your account does not have permission to open this page.', 'shield', 'If you believe you should have access, ask an administrator to review your role.'],
        404 => ['Page not found', 'The page you asked for does not exist, or it has been moved.', 'search', 'Check the address, or use the dashboard to find what you need.'],
        405 => ['Action not allowed', 'This address does not support the requested action.', 'x', 'Go back and use the navigation inside the app instead.'],
        408 => ['Request timed out', 'The server gave up waiting for this request to finish.', 'clock', 'Check your connection and try again.'],
        410 => ['Content removed', 'This page has been permanently removed.', 'trash-2', 'Use the dashboard to find the current page.'],
        413 => ['Upload too large', 'The data sent was larger than this server accepts.', 'upload', 'Reduce the size of the upload and try again.'],
        414 => ['Address too long', 'The address requested is too long for this server to process.', 'route', 'Use the navigation inside the app instead of a very long link.'],
        429 => ['Too many requests', 'You have made too many requests in a short time, so this one was blocked.', 'gauge', 'Wait a minute, then try again.'],
        500 => ['Something went wrong', 'An unexpected error stopped this page from loading. The problem has been logged.', 'server', 'Try again in a moment. If it keeps happening, contact support with the reference below.'],
        501 => ['Not implemented', 'The server does not support the action needed to serve this page.', 'wrench', 'Contact support if you reached this page from inside the app.'],
        502 => ['Bad gateway', 'A service this page depends on returned an invalid response.', 'plug-zap', 'Try again in a moment.'],
        503 => ['Service unavailable', 'The service is temporarily unavailable — usually maintenance or an overloaded upstream service.', 'hourglass', 'Try again shortly.'],
        504 => ['Gateway timeout', 'A service this page depends on did not respond in time.', 'timer', 'Try again in a moment.'],
    ];

    private const REASONS = [
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required',
        403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
        406 => 'Not Acceptable', 407 => 'Proxy Authentication Required',
        408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone',
        411 => 'Length Required', 412 => 'Precondition Failed',
        413 => 'Payload Too Large', 414 => 'URI Too Long',
        415 => 'Unsupported Media Type', 416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed', 418 => "I'm a teapot",
        421 => 'Misdirected Request', 422 => 'Unprocessable Content',
        425 => 'Too Early', 426 => 'Upgrade Required',
        428 => 'Precondition Required', 429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported', 506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage', 508 => 'Loop Detected', 510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    /** Allowed ErrorDocument codes (see .htaccess). */
    public const HANDLED = [
        400, 401, 403, 404, 405, 408, 410, 413, 414, 429,
        500, 501, 502, 503, 504,
    ];

    /** @var array<int,string> Rendered error references, for log correlation. */
    private static array $resolved = [];

    /** Map anything to a real error status (defaults to 404). */
    public static function normalise(?int $code): int
    {
        $code = (int) $code;
        return ($code >= 400 && $code <= 599) ? $code : 404;
    }

    /** Title / message / icon / hint for a status code. */
    public static function info(int $code): array
    {
        if (isset(self::CATALOG[$code])) {
            [$title, $message, $icon, $hint] = self::CATALOG[$code];
        } else {
            $reason = self::REASONS[$code] ?? 'Error';
            $title  = $reason;
            $message = $code >= 500
                ? 'The server could not complete this request.'
                : 'This request could not be completed.';
            $icon = 'triangle-alert';
            $hint = $code >= 500 ? 'Try again in a moment.' : 'Check the address and try again.';
        }

        return [
            'code'    => $code,
            'reason'  => self::REASONS[$code] ?? 'Error',
            'title'   => $title,
            'message' => $message,
            'icon'    => $icon,
            'hint'    => $hint,
            'class'   => $code >= 500 ? '5xx' : '4xx',
        ];
    }

    /**
     * True when Apache called us as the ErrorDocument for a failed request.
     * mod_rewrite internal redirects also set REDIRECT_STATUS (to 200), so only
     * an error status counts here.
     */
    private static function fromErrorDocument(): bool
    {
        $status = $_SERVER['REDIRECT_STATUS'] ?? null;
        if ($status === null || !ctype_digit((string) $status)) {
            return false;
        }
        $status = (int) $status;
        return $status >= 400 && $status <= 599;
    }

    /** How this request expects errors: HTML page, JSON payload or plain text. */
    public static function mode(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if (preg_match('~/(api|preview|ai_chat)(\.php)?$~i', $path)) {
            return 'json';
        }
        if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            return 'json';
        }
        if (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'xmlhttprequest') === 0) {
            return 'json';
        }
        if (isset($_GET['export'])) {
            return 'text';
        }
        return 'html';
    }

    /** Emit an error in whichever format the current request expects. */
    public static function reject(int $code): void
    {
        $mode = self::mode();
        if ($mode === 'html') {
            self::emit($code);
            return;
        }
        self::fatal($code, $mode);
    }

    /**
     * Send the HTTP status line explicitly.
     * php's http_response_code() maps codes it has no reason phrase for
     * (418, 419, 425, …) to 500 under mod_php, so the raw status line is used.
     */
    private static function sendStatus(int $code): void
    {
        $reason = self::REASONS[$code] ?? 'Error';
        header('HTTP/1.1 ' . $code . ' ' . $reason, true, $code);
    }

    /** Status of the current request: REDIRECT_STATUS (ErrorDocument) or ?code=. */
    public static function currentCode(): int
    {
        $code = null;
        if (self::fromErrorDocument()) {
            $code = (int) $_SERVER['REDIRECT_STATUS'];
        }
        if (isset($_GET['code']) && ctype_digit((string) $_GET['code'])) {
            $code = (int) $_GET['code'];   // explicit override (also handy for testing)
        }
        return self::normalise($code);
    }

    /** Application base path, derived from the executing script only. */
    public static function basePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/error.php');
        $script = str_replace('\\', '/', $script);
        if (preg_match('~^(.*?)/error\.php(?:/.*)?$~i', $script, $m)) {
            return rtrim($m[1], '/');
        }
        return rtrim(dirname($script), '/.');
    }

    /** Entry point for error.php (Apache ErrorDocument target). */
    public static function serve(): void
    {
        self::emit(self::currentCode());
    }

    /**
     * Render an error that PHP itself hit (uncaught exception / fatal error).
     * $mode: 'html' for pages, 'json' for fetch/XHR endpoints, 'text' for exports.
     */
    public static function fatal(int $code = 500, string $mode = 'html', array $ctx = []): void
    {
        $code = self::normalise($code);
        $ref  = self::reference($code, $ctx);
        $verb = 'PHP error';

        if ($mode === 'json') {
            self::discardBuffers();
            if (!headers_sent()) {
                self::sendStatus($code);
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-store, must-revalidate');
            }
            if (!defined('ERROR_PAGE_RENDERED')) {
                define('ERROR_PAGE_RENDERED', true);
                echo json_encode([
                    'success' => false,
                    'error'   => $code >= 500 ? 'Internal server error' : self::info($code)['title'],
                    'ref'     => $ref,
                ]);
            }
            return;
        }

        if ($mode === 'text') {
            self::discardBuffers();
            if (!headers_sent()) {
                self::sendStatus($code);
                header('Content-Type: text/plain; charset=UTF-8');
                header('Cache-Control: no-store, must-revalidate');
            }
            if (!defined('ERROR_PAGE_RENDERED')) {
                define('ERROR_PAGE_RENDERED', true);
                echo self::info($code)['title'] . ' (' . $code . ') — reference ' . $ref . "\n";
            }
            return;
        }

        self::emit($code, $ctx + ['ref' => $ref, 'verb' => $verb]);
    }

    /** Send an HTML error page with the right status code. */
    public static function emit(int $code, array $ctx = []): void
    {
        $code = self::normalise($code);
        self::discardBuffers();

        if (!headers_sent()) {
            // When Apache invoked us as ErrorDocument the status is already set;
            // only a direct hit (or an app-raised error) needs it applied here.
            if (!self::fromErrorDocument()) {
                self::sendStatus($code);
            }
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Robots-Tag: noindex, nofollow');
            header('Cache-Control: no-store, must-revalidate');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }

        if (headers_sent()) {
            echo self::lateBanner($code, $ctx);   // page already partially streamed
        } else {
            echo self::render($code, $ctx);
        }
        if (!defined('ERROR_PAGE_RENDERED')) {
            define('ERROR_PAGE_RENDERED', true);
        }
    }

    /** Full standalone page (works without app CSS, but uses it when present). */
    public static function render(int $code, array $ctx = []): string
    {
        $i     = self::info($code);
        $base  = htmlspecialchars(self::basePath(), ENT_QUOTES, 'UTF-8');
        $assetV = @filemtime(__DIR__ . '/../assets/style.css') ?: 0;
        $ref   = (string) ($ctx['ref'] ?? '');
        $verb  = (string) ($ctx['verb'] ?? '');
        $title = $i['title'] . ' (' . $code . ')';

        $path = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (strlen($path) > 160) {
            $path = substr($path, 0, 157) . '...';
        }

        $actions = '<a class="btn btn-primary" href="' . $base . '/dashboard"><i data-lucide="layout-grid"></i> Back to dashboard</a>'
            . '<button class="btn btn-secondary" type="button" onclick="if(history.length>1){history.back()}else{location.href=\'' . $base . '/dashboard\'}"><i data-lucide="arrow-left"></i> Go back</button>';
        if ($code === 401 || $code === 403) {
            $actions = '<a class="btn btn-primary" href="' . $base . '/login"><i data-lucide="log-in"></i> Sign in</a>' . $actions;
        }

        $detail = (string) ($ctx['detail'] ?? '');
        if ($detail !== '') {
            $detail = '<pre class="err-detail">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>';
        }

        $meta = '<p class="err-path">Requested: <code>' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '</code></p>';
        if ($code >= 500 && $ref !== '') {
            $meta .= '<p class="err-ref">Reference <strong>' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</strong>'
                . ($verb !== '' ? ' (' . htmlspecialchars($verb, ENT_QUOTES, 'UTF-8') . ')' : '') . '</p>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>{$code} · {$i['title']} | LATRA</title>
<link rel="icon" type="image/x-icon" href="{$base}/assets/logo/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="{$base}/assets/logo/favicon-32x32.png">
<link rel="apple-touch-icon" sizes="180x180" href="{$base}/assets/logo/apple-touch-icon.png">
<meta name="theme-color" content="#E8920C">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@1.42.0/dist/umd/lucide.min.js"></script>
<link rel="stylesheet" href="{$base}/assets/style.css?v={$assetV}">
<style>
    body.err-body { display:flex; align-items:center; justify-content:center; min-height:100vh; height:auto; overflow:auto; padding:24px 16px; background:var(--canvas); }
    .err-card { width:100%; max-width:540px; background:#fff; border:1px solid var(--border); border-radius:10px; box-shadow:0 24px 60px rgba(32,37,43,.15); padding:28px 26px 22px; text-align:center; }
    .err-brand { display:flex; align-items:center; justify-content:center; gap:9px; margin-bottom:18px; }
    .err-brand .logo-ico { width:32px; height:32px; border-radius:8px; background:#E8920C; color:#fff; display:inline-flex; align-items:center; justify-content:center; }
    .err-brand .logo-ico svg { width:17px; height:17px; }
    .err-brand span { font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); font-weight:600; }
    .err-code { font-size:58px; font-weight:700; line-height:1; letter-spacing:-.03em; }
    .err-code.is-4xx { color:var(--accent); }
    .err-code.is-5xx { color:var(--danger); }
    .err-ico { margin:6px 0 2px; color:var(--faint); }
    .err-ico svg { width:26px; height:26px; }
    .err-card h1 { font-size:19px; font-weight:700; color:#14171C; margin:6px 0 8px; }
    .err-msg { color:var(--muted); font-size:14px; }
    .err-hint { margin-top:12px; display:inline-flex; gap:7px; align-items:flex-start; text-align:left; background:var(--accent-bg); color:var(--accent-ink); border-radius:var(--radius); padding:9px 12px; font-size:12.5px; }
    .err-hint svg { width:14px; height:14px; flex:0 0 auto; margin-top:2px; }
    .err-path { margin-top:16px; font-size:11.5px; color:var(--faint); word-break:break-all; }
    .err-path code { font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background:var(--canvas); border:1px solid var(--border); border-radius:var(--radius-sm); padding:2px 5px; }
    .err-ref { margin-top:6px; font-size:11.5px; color:var(--faint); }
    .err-detail { margin-top:14px; text-align:left; font-size:11.5px; background:var(--danger-bg); color:#7A1F1A; border:1px solid var(--border); border-radius:var(--radius); padding:10px 12px; overflow:auto; max-height:220px; white-space:pre-wrap; }
    .err-actions { display:flex; gap:9px; justify-content:center; flex-wrap:wrap; margin-top:18px; }
    .err-foot { margin-top:18px; font-size:11px; color:var(--faint); }
</style>
</head>
<body class="err-body">
    <main class="err-card" role="alert" aria-labelledby="errTitle">
        <div class="err-brand">
            <span class="logo-ico"><i data-lucide="map-pin"></i></span>
            <span>LATRA · Fleet Compliance Hub</span>
        </div>
        <div class="err-code is-{$i['class']}">{$code}</div>
        <div class="err-ico"><i data-lucide="{$i['icon']}"></i></div>
        <h1 id="errTitle">{$i['title']}</h1>
        <p class="err-msg">{$i['message']}</p>
        <p class="err-hint"><i data-lucide="info"></i> <span>{$i['hint']}</span></p>
        {$detail}
        {$meta}
        <div class="err-actions">{$actions}</div>
        <div class="err-foot">{$code} {$i['reason']} &middot; LATRA-Wialon Integration</div>
    </main>
    <script>if (window.lucide) { lucide.createIcons(); }</script>
</body>
</html>
HTML;
    }

    /** Compact card appended when the response was already streaming. */
    private static function lateBanner(int $code, array $ctx = []): string
    {
        $i    = self::info($code);
        $ref  = (string) ($ctx['ref'] ?? '');
        $base = htmlspecialchars(self::basePath(), ENT_QUOTES, 'UTF-8');

        return '<div style="position:fixed;inset:0;z-index:2147483647;background:#F5F6F8;display:flex;align-items:center;justify-content:center;padding:24px;font-family:Montserrat,-apple-system,Segoe UI,Roboto,Arial,sans-serif;">'
            . '<div style="max-width:520px;width:100%;background:#fff;border:1px solid #E1E3E6;border-radius:10px;box-shadow:0 24px 60px rgba(32,37,43,.15);padding:26px;text-align:center;color:#20252B;">'
            . '<div style="font-size:52px;font-weight:700;line-height:1;color:' . ($code >= 500 ? '#E0433C' : '#2F6BEB') . ';">' . $code . '</div>'
            . '<h1 style="font-size:18px;margin:8px 0 8px;">' . htmlspecialchars($i['title'], ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p style="color:#6D737A;font-size:14px;margin:0;">' . htmlspecialchars($i['message'], ENT_QUOTES, 'UTF-8') . '</p>'
            . ($ref !== '' ? '<p style="color:#9AA0A6;font-size:11.5px;margin-top:12px;">Reference <strong>' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</strong></p>' : '')
            . '<p style="margin-top:18px;"><a href="' . $base . '/dashboard" style="display:inline-block;background:#2F6BEB;color:#fff;text-decoration:none;padding:9px 16px;border-radius:6px;font-size:14px;font-weight:600;">Back to dashboard</a></p>'
            . '</div></div>';
    }

    /** Reference id used in the page and in the log, for support correlation. */
    private static function reference(int $code, array $ctx): string
    {
        if (isset(self::$resolved[$code])) {
            return self::$resolved[$code];
        }
        $ref = (string) ($ctx['ref'] ?? '');
        if ($ref === '') {
            try {
                $ref = strtoupper(bin2hex(random_bytes(4)));
            } catch (Throwable $e) {
                $ref = strtoupper(substr(md5((string) microtime(true)), 0, 8));
            }
        }
        self::$resolved[$code] = $ref;
        return $ref;
    }

    /** Drop anything PHP already rendered so the error page stands alone. */
    private static function discardBuffers(): void
    {
        $guard = 0;
        while (ob_get_level() > 0 && $guard < 32) {
            if (!@ob_end_clean()) {
                break;
            }
            $guard++;
        }
    }
}
