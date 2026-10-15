<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Layout.php';
Auth::requireLogin();
Auth::requireWrite();

$tab = $_GET['tab'] ?? 'account';
if (!in_array($tab, ['account', 'integrations', 'reference'], true)) {
    $tab = 'account';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    if (isset($_POST['save_profile'])) {
        $displayName = trim($_POST['display_name'] ?? '');
        if ($displayName === '' || mb_strlen($displayName) > 100) {
            header('Location: settings?tab=account&err=name'); exit;
        }
        Database::update('app_users', ['display_name' => $displayName], 'id = ?', [Auth::user()['uid']]);
        $_SESSION['auth']['display_name'] = $displayName;
        header('Location: settings?tab=account&updated=profile'); exit;
    }

    if (isset($_POST['change_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $me      = Database::fetch('SELECT * FROM app_users WHERE id = ?', [Auth::user()['uid']]);
        if (!$me || !password_verify($current, $me['password_hash'])) {
            header('Location: settings?tab=account&err=current'); exit;
        }
        if (strlen($new) < 8) {
            header('Location: settings?tab=account&err=short'); exit;
        }
        if ($new !== $confirm) {
            header('Location: settings?tab=account&err=mismatch'); exit;
        }
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        Database::update('app_users', [
            'password_hash'  => password_hash($new, $algo),
            'failed_logins'  => 0,
            'locked_until'   => null,
        ], 'id = ?', [(int) $me['id']]);
        Auth::audit('password_changed', 'user', $me['username'] ?? '', 'system');
        header('Location: settings?tab=account&updated=password'); exit;
    }

    header('Location: settings?tab=account'); exit;
}

$me = Database::fetch('SELECT id, username, display_name, role, last_login_at, created_at FROM app_users WHERE id = ?', [Auth::user()['uid']]) ?? [];
$roleLabel = ['admin' => 'Administrator', 'operator' => 'Operator'][$me['role'] ?? ''] ?? ($me['role'] ?? '');

function cfgBadge(string $value): string {
    return $value !== ''
        ? '<span class="badge badge-success"><i data-lucide="lock"></i> Configured</span>'
        : '<span class="badge badge-danger"><i data-lucide="triangle-alert"></i> Missing</span>';
}
function maskSecret(string $value): string {
    $v = (string) $value;
    if ($v === '') {
        return '<span class="text-muted">not set</span>';
    }
    return '••••••••' . e(substr($v, -4));
}
function hostOf(string $url): string {
    $u = parse_url($url);
    $port = $u['port'] ?? null;
    return ($u['scheme'] ?? '') . '://' . ($u['host'] ?? '') . ($port !== null ? ':' . $port : '');
}

Layout::renderHead('Settings');
?>

<div class="page-header">
    <h1><i data-lucide="settings"></i> Settings</h1>
    <p class="text-muted">Manage your account and review integration health.</p>
</div>

<?php if (isset($_GET['updated'])):
    $msg = $_GET['updated'] === 'profile' ? 'Profile updated successfully' : 'Password changed successfully';
    echo Layout::alert('success', $msg);
endif;
if (isset($_GET['err'])):
    $errs = [
        'name'      => 'Display name cannot be empty (max 100 characters).',
        'current'   => 'Current password is incorrect.',
        'short'     => 'New password must be at least 8 characters.',
        'mismatch'  => 'New password and confirmation do not match.',
    ];
    echo Layout::alert('danger', $errs[$_GET['err']] ?? 'Invalid request.');
endif; ?>

<div class="feed-tabs settings-tabs" role="tablist">
    <button class="feed-tab <?= $tab === 'account' ? 'active' : '' ?>" data-stab="account" type="button" role="tab"><i data-lucide="user-cog"></i> Account</button>
    <button class="feed-tab <?= $tab === 'integrations' ? 'active' : '' ?>" data-stab="integrations" type="button" role="tab"><i data-lucide="plug"></i> Integrations</button>
    <button class="feed-tab <?= $tab === 'reference' ? 'active' : '' ?>" data-stab="reference" type="button" role="tab"><i data-lucide="book-open"></i> Activity reference</button>
</div>

<!-- ===================== Account ===================== -->
<section data-spanel="account" <?= $tab === 'account' ? '' : 'style="display:none"' ?>>
    <div class="settings-grid">
        <div class="settings-stack">
            <div class="card">
                <div class="card-header"><h3><i data-lucide="badge-check"></i> Profile</h3></div>
                <div class="card-body">
                    <div class="detail-item">
                        <span class="detail-label">Username</span>
                        <span class="detail-value"><?= e($me['username'] ?? '') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Role</span>
                        <span class="detail-value"><span class="badge badge-info"><?= e($roleLabel) ?></span></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Member since</span>
                        <span class="detail-value"><?= e(toDisplay((string) ($me['created_at'] ?? ''))) ?></span>
                    </div>
                    <div class="settings-sep"></div>
                    <form method="post" class="settings-form">
                        <?= Auth::csrfField() ?>
                        <div class="form-group">
                            <label for="displayName">Display name</label>
                            <input class="form-input" type="text" id="displayName" name="display_name" maxlength="100" value="<?= e($me['display_name'] ?? '') ?>" required>
                        </div>
                        <div class="form-group form-actions">
                            <button class="btn btn-primary" type="submit" name="save_profile" value="1"><i data-lucide="save"></i> Save profile</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="settings-stack">
            <div class="card">
                <div class="card-header"><h3><i data-lucide="key-round"></i> Change password</h3></div>
                <div class="card-body">
                    <form method="post" class="settings-form">
                        <?= Auth::csrfField() ?>
                        <div class="form-group">
                            <label for="currentPassword">Current password</label>
                            <input class="form-input" type="password" id="currentPassword" name="current_password" autocomplete="current-password" required>
                        </div>
                        <div class="form-group">
                            <label for="newPassword">New password <span class="text-muted">(min 8 characters)</span></label>
                            <input class="form-input" type="password" id="newPassword" name="new_password" autocomplete="new-password" minlength="8" required>
                        </div>
                        <div class="form-group">
                            <label for="confirmPassword">Confirm new password</label>
                            <input class="form-input" type="password" id="confirmPassword" name="confirm_password" autocomplete="new-password" minlength="8" required>
                        </div>
                        <div class="form-group form-actions">
                            <button class="btn btn-primary" type="submit" name="change_password" value="1"><i data-lucide="shield-check"></i> Update password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== Integrations ===================== -->
<section data-spanel="integrations" <?= $tab === 'integrations' ? '' : 'style="display:none"' ?>>
    <div class="settings-grid">
        <div class="settings-stack">
            <div class="card">
                <div class="card-header">
                    <h3><i data-lucide="satellite-dish"></i> Wialon API</h3>
                    <button type="button" class="btn btn-secondary btn-sm" id="btnTestWialon"><i data-lucide="activity"></i> Test connection</button>
                </div>
                <div class="card-body">
                    <div class="detail-item">
                        <span class="detail-label">Base URL</span>
                        <span class="detail-value font-mono"><?= e(hostOf(WIALON_BASE_URL) ?: WIALON_BASE_URL) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Access token</span>
                        <span class="detail-value"><?= maskSecret(WIALON_TOKEN) ?> <?= cfgBadge(WIALON_TOKEN) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <span class="detail-value" id="testResultWialon"><span class="text-muted">Not tested this session</span></span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i data-lucide="server"></i> LATRA API</h3>
                    <button type="button" class="btn btn-secondary btn-sm" id="btnTestLatra"><i data-lucide="activity"></i> Test connection</button>
                </div>
                <div class="card-body">
                    <div class="detail-item">
                        <span class="detail-label">Endpoint</span>
                        <span class="detail-value font-mono"><?= e(hostOf(LATRA_API_URL) ?: (LATRA_API_URL !== '' ? LATRA_API_URL : 'not set')) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Username</span>
                        <span class="detail-value"><?= e(LATRA_USERNAME) ?: '<span class="text-muted">not set</span>' ?> <?= cfgBadge(LATRA_USERNAME) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Password</span>
                        <span class="detail-value"><?= maskSecret(LATRA_PASSWORD) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <span class="detail-value" id="testResultLatra"><span class="text-muted">Not tested this session</span></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="settings-stack">
            <div class="card">
                <div class="card-header"><h3><i data-lucide="bot"></i> AI assistant</h3></div>
                <div class="card-body">
                    <div class="detail-item">
                        <span class="detail-label">Model</span>
                        <span class="detail-value"><?= e(envValue('OPENAI_MODEL', 'gpt-4o')) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">API key</span>
                        <span class="detail-value"><?= maskSecret(envValue('OPENAI_API_KEY', '')) ?> <?= cfgBadge(envValue('OPENAI_API_KEY', '')) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Notes</span>
                        <span class="detail-value text-muted">Integration credentials are loaded from <span class="font-mono">.env</span> and are never stored in the database.</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3><i data-lucide="shield-check"></i> Security</h3></div>
                <div class="card-body">
                    <div class="detail-item">
                        <span class="detail-label">Session timeout</span>
                        <span class="detail-value"><?= (int) SESSION_TIMEOUT / 60 ?> minutes of inactivity</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Account lockout</span>
                        <span class="detail-value">After 8 failed login attempts</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Last login</span>
                        <span class="detail-value"><?= e(toDisplay((string) ($me['last_login_at'] ?? ''))) ?: '<span class="text-muted">never</span>' ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Password hashing</span>
                        <span class="detail-value"><?= defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt' ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== Activity reference ===================== -->
<section data-spanel="reference" <?= $tab === 'reference' ? '' : 'style="display:none"' ?>>
    <div class="settings-single">
        <div class="card">
            <div class="card-header"><h3><i data-lucide="book-open"></i> Activity reference</h3></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead><tr><th>ID</th><th>Activity</th><th>Requires addon</th></tr></thead>
                        <tbody>
                            <?php foreach (LatraMapper::ACTIVITIES as $id => $name): ?>
                            <tr>
                                <td><?= (int) $id ?></td>
                                <td><?= e($name) ?></td>
                                <td><?= LatraMapper::activityRequiresAddon((int) $id) ? '<span class="badge badge-warning">Yes</span>' : '<span class="badge badge-secondary">No</span>' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
(function () {
    const T = document.querySelectorAll('[data-stab]');
    const P = document.querySelectorAll('[data-spanel]');
    function show(name) {
        T.forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-stab') === name); });
        P.forEach(function (p) { p.style.display = p.getAttribute('data-spanel') === name ? '' : 'none'; });
    }
    T.forEach(function (t) {
        t.addEventListener('click', function () { show(t.getAttribute('data-stab')); });
    });

    function wireTest(btnId, resId, url) {
        const btn = document.getElementById(btnId);
        if (!btn) return;
        btn.addEventListener('click', function () {
            const res = document.getElementById(resId);
            const prev = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader-circle"></i> Testing…';
            res.innerHTML = '<span class="text-muted">Running…</span>';
            fetch(url).then(function (r) { return r.json(); }).then(function (j) {
                res.innerHTML = (j.ok === true || j.success === true)
                    ? '<span class="badge badge-success"><i data-lucide="circle-check"></i> ' + escapeHtml(j.message) + '</span>'
                    : '<span class="badge badge-danger"><i data-lucide="triangle-alert"></i> ' + escapeHtml(j.message) + '</span>';
            }).catch(function () {
                res.innerHTML = '<span class="badge badge-danger"><i data-lucide="triangle-alert"></i> Check failed</span>';
            }).finally(function () {
                btn.disabled = false;
                btn.innerHTML = prev;
            });
        });
    }
    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }
    wireTest('btnTestWialon', 'testResultWialon', 'api.php?action=test_wialon');
    wireTest('btnTestLatra', 'testResultLatra', 'api.php?action=test_latra');
})();
</script>

<?php Layout::renderFoot(); ?>