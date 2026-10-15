<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

if (isset($_GET['logout'])) {
    if (Auth::verifyCsrf($_GET['_csrf'] ?? '')) {
        Auth::logout();
    }
    header('Location: login');
    exit;
}

if (Auth::check()) {
    header('Location: dashboard');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $extra = Auth::deviceExtra($_POST['device_json'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!Auth::rateLimit('login:' . Auth::clientKey(), 8, 300)) {
        Auth::logLoginAttempt($username, 'rate_limited', $extra);
        $error = 'Too many attempts. Try again in a few minutes.';
    } elseif ($username === '' || $password === '') {
        Auth::logLoginAttempt($username, 'invalid', $extra);
        $error = 'Enter both username and password.';
    } elseif (Auth::attempt($username, $password, $extra)) {
        header('Location: dashboard');
        exit;
    } else {
        $error = 'Invalid credentials.';
        sleep(1);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in | <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="icon" type="image/x-icon" href="<?= ASSETS_URL ?>/logo/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ASSETS_URL ?>/logo/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= ASSETS_URL ?>/logo/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ASSETS_URL ?>/logo/apple-touch-icon.png">
    <link rel="manifest" href="<?= ASSETS_URL ?>/logo/site.webmanifest">
    <meta name="theme-color" content="#E8920C">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@1.42.0/dist/umd/lucide.min.js"></script>
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/style.css?v=<?= APP_VERSION ?>">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--canvas); }
        .login-wrap { width: 100%; max-width: 360px; padding: 0 16px; }
        .login-card { background: #fff; border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 24px 60px rgba(32,37,43,.15); padding: 26px 26px 22px; }
        .login-brand { text-align: center; margin-bottom: 20px; }
        .login-brand .logo-ico { width: 44px; height: 44px; border-radius: 9px; background: #E8920C; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; margin-bottom: 10px; }
        .login-brand h1 { font-size: 18px; font-weight: 700; color: #14171C; margin: 0 0 3px; letter-spacing: .02em; }
        .login-brand p { color: #6D737A; font-size: 12px; margin: 0; }
        .form-footer { text-align: center; color: #9AA0A6; font-size: 11px; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="login-wrap">
        <div class="login-card">
            <div class="login-brand">
                <div class="logo-ico"><i data-lucide="map-pin"></i></div>
                <h1>LATRA</h1>
                <p>Fleet Compliance Hub · Wialon → LATRA</p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-error"><i data-lucide="triangle-alert"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST" autocomplete="off">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="device_json" id="device_json" value="">
                <div class="form-group">
                    <label>Username</label>
                    <input class="form-input" type="text" name="username" required autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input class="form-input" type="password" name="password" required autocomplete="current-password">
                </div>
                <button class="btn btn-primary w-100" type="submit"><i data-lucide="log-in"></i> Sign in</button>
            </form>
            <div class="form-footer">Restricted access &middot; all actions are audited</div>
        </div>
    </div>
    <script>
        if (window.lucide) lucide.createIcons();

        // Device fingerprint collected client-side for the login.attempt log.
        (function () {
            function collect() {
                var d = {};
                try { d.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
                try { d.local_time = new Date().toISOString(); } catch (e) {}
                try { d.screen = window.screen ? (screen.width + 'x' + screen.height) : ''; } catch (e) {}
                try { d.languages = (navigator.languages || [navigator.language || '']).join(','); } catch (e) {}
                try { d.platform = navigator.platform || ''; } catch (e) {}
                try { d.cores = navigator.hardwareConcurrency || 0; } catch (e) {}
                try { d.memory = navigator.deviceMemory || 0; } catch (e) {}
                try { if (navigator.connection) d.connection = navigator.connection.effectiveType || ''; } catch (e) {}
                try { d.vendor = navigator.vendor || ''; } catch (e) {}
                var f = document.getElementById('device_json');
                if (f) f.value = JSON.stringify(d);
            }
            var form = document.querySelector('form');
            if (form) { form.addEventListener('submit', collect); collect(); }
        })();
    </script>
</body>
</html>