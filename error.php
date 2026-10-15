<?php
/**
 * HTTP error page endpoint.
 *
 * Target of the ErrorDocument directives in .htaccess. Apache passes the
 * original status through $_SERVER['REDIRECT_STATUS'].
 *
 * Deliberately does NOT include config.php: this page must still render when
 * the app config, database or session layer is what broke. All it needs is
 * includes/ErrorPage.php, plus a minimal inline fallback if even that fails.
 */

$renderer = __DIR__ . '/includes/ErrorPage.php';

if (is_readable($renderer)) {
    require_once $renderer;
}

if (class_exists('ErrorPage')) {
    ErrorPage::serve();
    exit;
}

// --- Last-resort fallback (renderer file missing / unreadable) -------------
$code = 404;
if (isset($_SERVER['REDIRECT_STATUS'])
    && ctype_digit((string) $_SERVER['REDIRECT_STATUS'])
    && (int) $_SERVER['REDIRECT_STATUS'] >= 400
    && (int) $_SERVER['REDIRECT_STATUS'] <= 599) {
    $code = (int) $_SERVER['REDIRECT_STATUS'];
}
if (isset($_GET['code']) && ctype_digit((string) $_GET['code'])) {
    $code = (int) $_GET['code'];
}
if ($code < 400 || $code > 599) {
    $code = 404;
}

if (!headers_sent()) {
    header('HTTP/1.1 ' . $code . ' Error', true, $code);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
}
echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
    . '<title>' . $code . ' · Error | LATRA</title>'
    . '<style>body{font-family:Montserrat,Arial,sans-serif;background:#F5F6F8;color:#20252B;'
    . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
    . 'div{background:#fff;border:1px solid #E1E3E6;border-radius:10px;padding:28px;max-width:480px;text-align:center}'
    . 'b{font-size:46px;display:block;color:#2F6BEB}</style></head><body><div>'
    . '<b>' . $code . '</b><p>The server could not complete this request.</p>'
    . '</div></body></html>';
