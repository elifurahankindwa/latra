<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Layout.php';
Auth::requireRole(['admin']);

$selfId = Auth::user()['uid'];
$roles = ['admin', 'operator', 'viewer'];
$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

$ok  = '';
$err = '';

if (isset($_POST['add_user'])) {
    Auth::requireCsrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $display  = trim((string) ($_POST['display_name'] ?? ''));
    $role     = $_POST['role'] ?? '';
    $password = (string) ($_POST['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $err = 'Username must be 3-50 characters (letters, digits, _ . -).';
    } elseif (strlen($password) < 8) {
        $err = 'Password must be at least 8 characters.';
    } elseif (!in_array($role, $roles, true)) {
        $err = 'Unknown role.';
    } elseif (Database::fetch('SELECT id FROM app_users WHERE username = ?', [$username])) {
        $err = 'Username already exists.';
    } else {
        Database::insert('app_users', [
            'username'      => $username,
            'password_hash' => password_hash($password, $algo),
            'display_name'  => mb_strlen($display) > 100 ? mb_substr($display, 0, 100) : $display,
            'role'          => $role,
            'is_active'     => 1,
        ]);
        Auth::audit('user_create', 'user', $username . ' (' . $role . ')');
        $ok = 'User "' . $username . '" created.';
    }
}

if (isset($_POST['edit_user'])) {
    Auth::requireCsrf();
    $id    = (int) ($_POST['id'] ?? 0);
    $role  = $_POST['role'] ?? '';
    $disp  = trim((string) ($_POST['display_name'] ?? ''));
    $u     = $id ? Database::fetch('SELECT * FROM app_users WHERE id = ?', [$id]) : null;
    if (!$u) {
        $err = 'User not found.';
    } elseif (!in_array($role, $roles, true)) {
        $err = 'Unknown role.';
    } elseif ($id === $selfId && $role !== 'admin') {
        $err = 'You cannot demote your own account.';
    } else {
        $before = ['role' => $u['role'], 'display_name' => $u['display_name'], 'is_active' => $u['is_active']];
        Database::update('app_users', [
            'role'         => $role,
            'display_name' => $disp !== '' ? $disp : $u['display_name'],
        ], 'id = ?', [$id]);
        Auth::audit('user_update', 'user', $u['username'] . ' -> role ' . $role, null, $before, ['role' => $role]);
        $ok = 'User "' . $u['username'] . '" updated.';
    }
}

if (isset($_POST['toggle_active'])) {
    Auth::requireCsrf();
    $id  = (int) ($_POST['id'] ?? 0);
    $act = (int) ($_POST['is_active'] ?? 0);
    $u   = $id ? Database::fetch('SELECT username FROM app_users WHERE id = ?', [$id]) : null;
    if (!$u) {
        $err = 'User not found.';
    } elseif ($id === $selfId) {
        $err = 'You cannot deactivate your own account.';
    } else {
        Database::update('app_users', ['is_active' => $act ? 1 : 0], 'id = ?', [$id]);
        Auth::audit($act ? 'user_activate' : 'user_deactivate', 'user', $u['username'] ?? '');
        $ok = 'User "' . ($u['username'] ?? '') . '" ' . ($act ? 'activated' : 'deactivated') . '.';
    }
}

if (isset($_POST['unlock_user'])) {
    Auth::requireCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $u  = $id ? Database::fetch('SELECT username FROM app_users WHERE id = ?', [$id]) : null;
    if (!$u) {
        $err = 'User not found.';
    } else {
        Database::update('app_users', ['failed_logins' => 0, 'locked_until' => null], 'id = ?', [$id]);
        Auth::audit('user_unlock', 'user', $u['username'] ?? '');
        $ok = 'User "' . ($u['username'] ?? '') . '" unlocked.';
    }
}

if (isset($_POST['reset_pw'])) {
    Auth::requireCsrf();
    $id       = (int) ($_POST['id'] ?? 0);
    $password = (string) ($_POST['password'] ?? '');
    $u        = $id ? Database::fetch('SELECT username FROM app_users WHERE id = ?', [$id]) : null;
    if (!$u) {
        $err = 'User not found.';
    } elseif (strlen($password) < 8) {
        $err = 'Password must be at least 8 characters.';
    } else {
        Database::update('app_users', [
            'password_hash' => password_hash($password, $algo),
            'failed_logins' => 0,
            'locked_until'  => null,
        ], 'id = ?', [$id]);
        Auth::audit('user_password_reset', 'user', $u['username'] ?? '');
        $ok = 'Password reset for "' . ($u['username'] ?? '') . '".';
    }
}

if (isset($_POST['delete_user'])) {
    Auth::requireCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $u  = $id ? Database::fetch('SELECT * FROM app_users WHERE id = ?', [$id]) : null;
    if (!$u) {
        $err = 'User not found.';
    } elseif ($id === $selfId) {
        $err = 'You cannot delete your own account.';
    } else {
        Database::query('DELETE FROM app_users WHERE id = ?', [$id]);
        Auth::audit('user_delete', 'user', $u['username'] ?? '');
        $ok = 'User "' . ($u['username'] ?? '') . '" deleted.';
    }
}

if (!$ok && !$err && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $err = 'Unknown action.';
}

$users = Database::fetchAll("SELECT * FROM app_users ORDER BY is_active DESC, role ASC, username ASC");

Layout::renderHead('Users');
?>

<div class="page-header">
    <h1><i data-lucide="users"></i> Users</h1>
    <p class="text-muted">Manage application accounts and roles.</p>
</div>

<?php if ($ok): ?>
    <?= Layout::alert('success', $ok) ?>
<?php endif; ?>
<?php if ($err): ?>
    <?= Layout::alert('error', $err) ?>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><h3>Accounts</h3></div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Display name</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr data-id="<?= (int) $u['id'] ?>">
                    <td><strong><?= e($u['username']) ?></strong><?= (int) $u['id'] === $selfId ? ' <span class="text-muted">(you)</span>' : '' ?></td>
                    <td><?= e($u['display_name'] ?: '—') ?></td>
                    <td>
                        <form method="post" class="role-inline">
                            <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <input type="hidden" name="edit_user" value="1">
                            <select class="form-input form-input-sm role-select" name="role"<?= (int) $u['id'] === $selfId ? ' disabled' : '' ?>>
                                <?php foreach ($roles as $r): ?>
                                <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(Auth::roleLabels()[$r] ?? $r) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="role-saved" aria-hidden="true">saved</span>
                        </form>
                    </td>
                    <td>
                        <span class="status-dot <?= $u['is_active'] ? 'active' : 'inactive' ?>"></span>
                        <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                        <?= $u['locked_until'] && strtotime($u['locked_until']) > time() ? '<span class="badge badge-danger">Locked</span>' : '' ?>
                        <?= (int) $u['failed_logins'] > 0 ? '<span class="badge badge-warning">' . (int) $u['failed_logins'] . ' fails</span>' : '' ?>
                    </td>
                    <td class="text-muted"><?= $u['last_login_at'] ? e(toDisplay((string) $u['last_login_at'])) : 'Never' ?></td>
                    <td>
                        <?php if ((int) $u['id'] === $selfId): ?>
                            <a class="btn btn-sm btn-ghost" href="settings?tab=account">Edit profile</a>
                        <?php else: ?>
                            <?php if (!$u['is_active']): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <input type="hidden" name="toggle_active" value="1">
                                <input type="hidden" name="is_active" value="1">
                                <button class="btn btn-sm btn-ghost" type="submit" title="Activate"><i data-lucide="user-check"></i></button>
                            </form>
                            <?php elseif (isset($u['locked_until']) && strtotime((string) $u['locked_until']) > time()): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <input type="hidden" name="unlock_user" value="1">
                                <button class="btn btn-sm btn-ghost" type="submit" title="Unlock"><i data-lucide="lock-open"></i></button>
                            </form>
                            <?php endif; ?>
                            <button class="btn-icon-sm" onclick="resetUserPw(<?= (int) $u['id'] ?>, <?= e(json_encode($u['username'])) ?>)" title="Reset password"><i data-lucide="key-round"></i></button>
                            <form method="post" class="inline-form" onsubmit="return confirm('Delete user <?= e(json_encode($u['username'])) ?>?')">
                                <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <input type="hidden" name="delete_user" value="1">
                                <button class="btn-icon-sm btn-icon-danger" type="submit" title="Delete"><i data-lucide="trash-2"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header">
        <div class="card-title"><h3><i data-lucide="user-plus"></i> Create user</h3></div>
    </div>
    <form method="post" class="settings-form">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="add_user" value="1">
        <div class="form-group">
            <label for="nu_username">Username</label>
            <input class="form-input" type="text" id="nu_username" name="username" maxlength="50" pattern="[A-Za-z0-9_.-]+" required placeholder="e.g. ops.mike">
        </div>
        <div class="form-group">
            <label for="nu_display">Display name</label>
            <input class="form-input" type="text" id="nu_display" name="display_name" maxlength="100" placeholder="e.g. Mike Ops">
        </div>
        <div class="form-group">
            <label for="nu_role">Role</label>
            <select class="form-input" id="nu_role" name="role" required>
                <?php foreach ($roles as $r): ?>
                <option value="<?= $r ?>" <?= $r === 'operator' ? 'selected' : '' ?>><?= e(Auth::roleLabels()[$r] ?? $r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="nu_password">Password</label>
            <input class="form-input" type="password" id="nu_password" name="password" minlength="8" required placeholder="At least 8 characters">
        </div>
        <div class="form-group form-actions">
            <button class="btn btn-primary" type="submit"><i data-lucide="user-plus"></i> Create user</button>
        </div>
    </form>
</div>

<form method="post" id="resetPwForm" style="display:none">
    <input type="hidden" name="_csrf" value="<?= e(Auth::csrfToken()) ?>">
    <input type="hidden" name="id" id="resetPwId" value="">
    <input type="hidden" name="password" id="resetPwPassword" value="">
    <input type="hidden" name="reset_pw" value="1">
</form>

<script>
document.querySelectorAll('.role-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
        const form = sel.closest('form');
        const saved = form.querySelector('.role-saved');
        const data = new URLSearchParams(new FormData(form));
        fetch('users.php', { method: 'POST', body: data })
            .then(r => r.text())
            .then(() => {
                saved.classList.add('shown');
                setTimeout(() => saved.classList.remove('shown'), 1500);
            });
    });
});

function resetUserPw(id, username) {
    Swal.fire({
        title: 'Reset password for ' + username + '?',
        input: 'password',
        inputLabel: 'New password (min 8 characters)',
        inputAttributes: { minlength: 8, autocomplete: 'new-password' },
        showCancelButton: true,
        confirmButtonText: 'Reset',
        inputValidator: (v) => (v && v.length >= 8) ? undefined : 'At least 8 characters',
    }).then((result) => {
        if (!result.isConfirmed) return;
        document.getElementById('resetPwId').value = id;
        document.getElementById('resetPwPassword').value = result.value;
        document.getElementById('resetPwForm').submit();
    });
}
</script>