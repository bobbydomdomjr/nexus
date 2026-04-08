<?php
/**
 * My account — staff & viewers: edit own profile and password only.
 */
session_start();
require '../config/db.php';
require __DIR__ . '/_rbac.php';
nexus_require_role_page($pdo, ['staff', 'viewer']);

$activePage   = 'account';
$pageTitle    = 'My account';
$pageSubtitle = 'Your profile';
$docTitle     = 'Nexus Platform';

require '_sidebar.php';

$myId = (int) ($_SESSION['admin_id'] ?? 0);
$colsStmt = $pdo->query('DESCRIBE admin_users');
$cols     = array_column($colsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
$hasEmail = in_array('email', $cols, true);
$hasRole  = in_array('role', $cols, true);

$select = 'id, username, password, created_at';
if (in_array('name', $cols)) {
    $select .= ', name';
}
if ($hasEmail) {
    $select .= ', email';
}
if ($hasRole) {
    $select .= ', role';
}
if (in_array('status', $cols)) {
    $select .= ', status';
}
if (in_array('last_login', $cols)) {
    $select .= ', last_login';
}

$stmt = $pdo->prepare("SELECT {$select} FROM admin_users WHERE id = ? LIMIT 1");
$stmt->execute([$myId]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) {
    header('Location: login.php');
    exit;
}

$displayName = $me['name'] ?? $_SESSION['admin_name'] ?? '';
$displayUser = $me['username'] ?? '';
$displayEmail = $hasEmail ? ($me['email'] ?? '') : '';
$displayRole = $hasRole ? ($me['role'] ?? 'staff') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= nexusHead() ?>
    <style>
        .acct-grid {
    display: grid;
    grid-template-columns: 1fr 1fr; /* two equal columns */
    gap: 20px;
}

/* Optional: responsive (stack on mobile) */
@media (max-width: 768px) {
    .acct-grid {
        grid-template-columns: 1fr;
    }
}
        .acct-card { background: var(--panel); border: 1px solid var(--border-hi); border-radius: var(--radius); padding: 22px 24px; }
        .acct-card h2 { font-size: 15px; font-weight: 700; margin: 0 0 6px; color: var(--text-pri); }
        .acct-card > p.sub { font-family: var(--mono); font-size: 11px; color: var(--text-dim); margin: 0 0 18px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-family: var(--mono); font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 6px; }
        .form-group input { width: 100%; box-sizing: border-box; background: var(--bg); border: 1px solid var(--border-hi); border-radius: 8px; padding: 10px 12px; font-size: 13px; color: var(--text-pri); outline: none; }
        .form-group input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
        .form-group input:disabled { opacity: .65; cursor: not-allowed; }
        .role-badge { display: inline-block; font-family: var(--mono); font-size: 10px; padding: 4px 10px; border-radius: 20px; margin-top: 4px; }
        .role-staff { background: rgba(56,189,248,.1); color: var(--info); border: 1px solid rgba(56,189,248,.2); }
        .role-viewer { background: rgba(245,166,35,.1); color: var(--warning); border: 1px solid rgba(245,166,35,.2); }
        .acct-actions { margin-top: 18px; display: flex; gap: 10px; align-items: center; }
    </style>
</head>
<body>

<?= nexusSidebar() ?>

<div class="main">
    <?= nexusTopbar() ?>

    <div class="content">
        <div class="page-header">
            <div>
                <h1>My account</h1>
                <p>Update your name, login, email, and password</p>
            </div>
        </div>

        <div class="acct-grid">
            <div class="acct-card">
                <h2>Profile</h2>
                <p class="sub">Changes apply only to your account.</p>
                <form id="profileForm">
                    <input type="hidden" name="action" value="update_self">
                    <div class="form-group">
                        <label for="acc_name">Full name</label>
                        <input type="text" id="acc_name" name="name" required value="<?= htmlspecialchars($displayName) ?>">
                    </div>
                    <div class="form-group">
                        <label for="acc_user">Username</label>
                        <input type="text" id="acc_user" name="username" required value="<?= htmlspecialchars($displayUser) ?>" autocomplete="username">
                    </div>
                    <?php if ($hasEmail): ?>
                    <div class="form-group">
                        <label for="acc_email">Email</label>
                        <input type="email" id="acc_email" name="email" required value="<?= htmlspecialchars($displayEmail) ?>" autocomplete="email">
                    </div>
                    <?php endif; ?>
                    <?php if ($hasRole): ?>
                    <div class="form-group">
                        <label>Role</label>
                        <div><span class="role-badge role-<?= htmlspecialchars($displayRole) ?>"><?= htmlspecialchars(ucfirst($displayRole)) ?></span></div>
                    </div>
                    <?php endif; ?>
                    <div class="acct-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save profile</button>
                    </div>
                </form>
            </div>

            <div class="acct-card">
                <h2>Password</h2>
                <p class="sub">You must enter your current password to set a new one.</p>
                <form id="passwordSelfForm">
                    <input type="hidden" name="action" value="change_password_self">
                    <div class="form-group">
                        <label for="acc_cur">Current password</label>
                        <input type="password" id="acc_cur" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label for="acc_new">New password</label>
                        <input type="password" id="acc_new" name="new_password" required autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label for="acc_conf">Confirm new password</label>
                        <input type="password" id="acc_conf" name="confirm_password" required autocomplete="new-password">
                    </div>
                    <div class="acct-actions">
                        <button type="submit" class="btn btn-ghost"><i class="fas fa-key"></i> Update password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?= nexusToast() ?>
<?= nexusJS() ?>

<script>
document.getElementById('profileForm').addEventListener('submit', function (e) {
    e.preventDefault();
    fetch('user_actions.php', { method: 'POST', body: new FormData(this) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.status === 'success') showToast(d.message || 'Saved');
            else showToast(d.message || 'Could not save', 'error');
        })
        .catch(function () { showToast('Network error', 'error'); });
});

document.getElementById('passwordSelfForm').addEventListener('submit', function (e) {
    e.preventDefault();
    fetch('user_actions.php', { method: 'POST', body: new FormData(this) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.status === 'success') {
                showToast(d.message || 'Password updated');
                document.getElementById('passwordSelfForm').reset();
            } else {
                showToast(d.message || 'Could not update password', 'error');
            }
        })
        .catch(function () { showToast('Network error', 'error'); });
});
</script>
</body>
</html>
