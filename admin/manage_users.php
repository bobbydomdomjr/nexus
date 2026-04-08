<?php
session_start();
require '../config/db.php';
require __DIR__ . '/_rbac.php';
nexus_require_role_page($pdo, ['admin']);

/* ─────────────────────────────────────────────
   Sidebar component setup
───────────────────────────────────────────── */
$activePage   = 'users';
$pageTitle    = 'Users';
$pageSubtitle = 'Manage Users';
$docTitle     = 'Nexus Platform';

require '_sidebar.php';

/* ─────────────────────────────────────────────
   Detect which columns exist (pre/post migration)
───────────────────────────────────────────── */
$colsStmt    = $pdo->query("DESCRIBE admin_users");
$cols        = array_column($colsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
$hasMigrated = in_array('email', $cols) && in_array('role', $cols) && in_array('status', $cols);

/* ─────────────────────────────────────────────
   Fetch all users — select only existing cols
───────────────────────────────────────────── */
$select = 'id, username, password, created_at';
if (in_array('name',       $cols)) $select .= ', name';
if (in_array('email',      $cols)) $select .= ', email';
if (in_array('role',       $cols)) $select .= ', role';
if (in_array('status',     $cols)) $select .= ', status';
if (in_array('last_login', $cols)) $select .= ', last_login';
if (in_array('updated_at', $cols)) $select .= ', updated_at';

$stmt  = $pdo->query("SELECT {$select} FROM admin_users ORDER BY created_at DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ─────────────────────────────────────────────
   Stats
───────────────────────────────────────────── */
$total         = count($users);
$activeCount   = count(array_filter($users, fn($u) => ($u['status'] ?? 'active') === 'active'));
$inactiveCount = $total - $activeCount;
$adminCount    = count(array_filter($users, fn($u) => ($u['role'] ?? 'admin') === 'admin'));

/* ─────────────────────────────────────────────
   PHP helpers
───────────────────────────────────────────── */
function getInitials(string $name): string {
    $words = explode(' ', trim($name));
    return strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', array_slice($words, 0, 2))));
}
function avatarColor(string $name): string {
    $colors = ['#4f6ef7','#34d399','#f5a623','#e05c6a','#a78bfa','#38bdf8','#fb7185','#4ade80'];
    $h = 0;
    foreach (str_split($name ?: 'U') as $c) $h = ($h * 31 + ord($c)) % count($colors);
    return $colors[$h];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= nexusHead() ?>
    <style>
        /* ── Page-specific styles only ── */
        .migration-banner {
            display: flex; align-items: center; gap: 12px;
            background: rgba(245,166,35,.07); border: 1px solid rgba(245,166,35,.3);
            border-radius: var(--radius); padding: 14px 20px; margin-bottom: 20px;
        }
        .migration-banner i { color: var(--warning); font-size: 16px; flex-shrink: 0; }
        .migration-banner p { font-family: var(--mono); font-size: 12px; color: var(--warning); line-height: 1.5; }
        .migration-banner strong { color: var(--text-pri); }

        .user-cell { display: flex; align-items: center; gap: 12px; }
        .u-avatar  { width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0; }
        .u-name    { font-size: 13px; font-weight: 700; color: var(--text-pri); }
        .u-username{ font-family: var(--mono); font-size: 11px; color: var(--text-dim); margin-top: 1px; }

        .badge-inactive { background: rgba(69,77,102,.2);   color: var(--text-dim); border: 1px solid var(--border-hi); }
        .badge-admin    { background: rgba(79,110,247,.12); color: var(--accent-hi); border: 1px solid rgba(79,110,247,.25); }
        .badge-staff    { background: rgba(56,189,248,.1);  color: var(--info);      border: 1px solid rgba(56,189,248,.2); }
        .badge-viewer   { background: rgba(245,166,35,.1);  color: var(--warning);   border: 1px solid rgba(245,166,35,.2); }

        .tbl-search { display: flex; align-items: center; gap: 8px; background: var(--bg); border: 1px solid var(--border-hi); border-radius: 7px; padding: 6px 12px; }
        .tbl-search input { background: none; border: none; outline: none; font-family: var(--mono); font-size: 12px; color: var(--text-pri); width: 200px; }
        .tbl-search input::placeholder { color: var(--text-dim); }
        .tbl-search i { color: var(--text-dim); font-size: 12px; }

        .pw-strength-bar   { height: 4px; border-radius: 2px; background: var(--border-hi); margin-top: 6px; overflow: hidden; }
        .pw-strength-fill  { height: 100%; border-radius: 2px; transition: width .3s, background .3s; width: 0; }
        .pw-strength-label { font-family: var(--mono); font-size: 10px; color: var(--text-dim); margin-top: 4px; }
        .pw-wrap           { position: relative; }
        .pw-wrap input     { padding-right: 40px; }
        .pw-eye { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-dim); cursor: pointer; font-size: 13px; transition: color .15s; }
        .pw-eye:hover { color: var(--text-sec); }

        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
        .detail-row  { display: flex; flex-direction: column; gap: 4px; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .detail-row.full { grid-column: 1 / -1; }
        .detail-row:last-child { border-bottom: none; }
        .detail-key { font-family: var(--mono); font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .08em; }
        .detail-val { font-size: 13px; color: var(--text-pri); font-weight: 600; }
        .detail-val.mono { font-family: var(--mono); font-size: 12px; }

        table.usr-table { width: 100%; border-collapse: collapse; }
        table.usr-table thead th { font-family: var(--mono); font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .1em; padding: 10px 16px; background: var(--bg); border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; cursor: pointer; user-select: none; }
        table.usr-table thead th .sort-icon { margin-left: 4px; opacity: .4; }
        table.usr-table thead th.sorted-asc .sort-icon,
        table.usr-table thead th.sorted-desc .sort-icon { opacity: 1; color: var(--accent-hi); }
        table.usr-table thead th.no-sort { cursor: default; }
        table.usr-table tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
        table.usr-table tbody tr:last-child { border-bottom: none; }
        table.usr-table tbody tr:hover { background: rgba(79,110,247,.04); }
        table.usr-table tbody td { padding: 13px 16px; font-size: 13px; color: var(--text-sec); vertical-align: middle; }
        table.usr-table tbody td.td-main { color: var(--text-pri); font-weight: 600; }
        table.usr-table tbody td.mono { font-family: var(--mono); font-size: 12px; }
    </style>
</head>
<body>

<?= nexusSidebar() ?>

<div class="main">
    <?= nexusTopbar('syncSearch') ?>

    <div class="content">

        <?php if (!$hasMigrated): ?>
        <div class="migration-banner">
            <i class="fas fa-triangle-exclamation"></i>
            <p>
                <strong>Database migration required.</strong>
                Your <code>admin_users</code> table is missing columns (<code>email</code>, <code>role</code>, <code>status</code>, <code>updated_at</code>).
                Run <strong>migrate_admin_users.sql</strong> in phpMyAdmin to unlock all features.
                Basic username/password/name operations still work.
            </p>
        </div>
        <?php endif; ?>

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>User Management</h1>
                <p><?= $total ?> user<?= $total != 1 ? 's' : '' ?> registered
                <?php if ($hasMigrated): ?>&nbsp;·&nbsp; <?= $activeCount ?> active<?php endif; ?></p>
            </div>
            <div class="header-actions">
                <button class="btn btn-ghost" onclick="exportCSV()"><i class="fas fa-download"></i> Export</button>
                <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-user-plus"></i> Add User</button>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="stats-grid">
            <div class="stat-card c1">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-label">Total Users</div>
                <div class="stat-value"><?= $total ?></div>
            </div>
            <div class="stat-card c2">
                <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
                <div class="stat-label">Active</div>
                <div class="stat-value"><?= $hasMigrated ? $activeCount : '—' ?></div>
            </div>
            <div class="stat-card c3">
                <div class="stat-icon"><i class="fas fa-ban"></i></div>
                <div class="stat-label">Inactive</div>
                <div class="stat-value"><?= $hasMigrated ? $inactiveCount : '—' ?></div>
            </div>
            <div class="stat-card c4">
                <div class="stat-icon"><i class="fas fa-shield-halved"></i></div>
                <div class="stat-label">Admins</div>
                <div class="stat-value"><?= $hasMigrated ? $adminCount : '—' ?></div>
            </div>
        </div>

        <!-- Table card -->
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">User Registry</div>
                    <div class="card-subtitle">All platform accounts and credentials</div>
                </div>
                <div class="card-tools">
                    <div class="tbl-search">
                        <i class="fas fa-search"></i>
                        <input type="text" id="tableSearch" placeholder="Filter users..." oninput="syncSearch('table')">
                    </div>
                    <?php if ($hasMigrated): ?>
                    <select class="filter-select" id="roleFilter" onchange="applyFilters()">
                        <option value="">All Roles</option>
                        <option value="admin">Admin</option>
                        <option value="staff">Staff</option>
                        <option value="viewer">Viewer</option>
                    </select>
                    <select class="filter-select" id="statusFilter" onchange="applyFilters()">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <?php endif; ?>
                    <select class="filter-select" id="pageSizeSelect" onchange="changePageSize()">
                        <option value="10">10 / page</option>
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                    </select>
                    <button class="tool-btn" onclick="exportCSV()"><i class="fas fa-file-csv"></i> CSV</button>
                </div>
            </div>

            <div class="table-wrap">
                <table class="usr-table" id="usersTable">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)">User <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <?php if ($hasMigrated): ?>
                            <th onclick="sortTable(1)">Email <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(2)">Role <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(3)">Status <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(4)">Last Login <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <?php endif; ?>
                            <th onclick="sortTable(<?= $hasMigrated ? 5 : 1 ?>)">Created <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th class="no-sort">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersBody">
                        <?php if (empty($users)): ?>
                        <tr><td colspan="<?= $hasMigrated ? 7 : 3 ?>">
                            <div class="empty-state">
                                <i class="fas fa-user-slash"></i>
                                <p>No users found. Click "Add User" to create the first account.</p>
                            </div>
                        </td></tr>
                        <?php else: foreach ($users as $u):
                            $role        = $u['role']       ?? 'admin';
                            $status      = $u['status']     ?? 'active';
                            $lastLogin   = $u['last_login'] ?? null;
                            $createdAt   = $u['created_at'] ?? null;
                            $displayName = $u['name']       ?? $u['username'];
                        ?>
                        <tr data-role="<?= htmlspecialchars($role) ?>" data-status="<?= htmlspecialchars($status) ?>">
                            <td class="td-main">
                                <div class="user-cell">
                                    <div class="u-avatar" style="background:<?= avatarColor($displayName) ?>">
                                        <?= getInitials($displayName) ?>
                                    </div>
                                    <div>
                                        <div class="u-name"><?= htmlspecialchars($displayName) ?></div>
                                        <div class="u-username">@<?= htmlspecialchars($u['username']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <?php if ($hasMigrated): ?>
                            <td class="mono"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                            <td>
                                <?php if ($role === 'admin'): ?>
                                    <span class="badge badge-admin">Admin</span>
                                <?php elseif ($role === 'staff'): ?>
                                    <span class="badge badge-staff">Staff</span>
                                <?php else: ?>
                                    <span class="badge badge-viewer">Viewer</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($status === 'active'): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="mono"><?= $lastLogin ? date('M d, Y H:i', strtotime($lastLogin)) : '—' ?></td>
                            <?php endif; ?>
                            <td class="mono"><?= $createdAt ? date('M d, Y', strtotime($createdAt)) : '—' ?></td>
                            <td>
                                <div class="row-actions">
                                    <button class="act-btn view"   title="View"            onclick='viewUser(<?= json_encode($u) ?>)'><i class="fas fa-eye"></i></button>
                                    <button class="act-btn edit"   title="Edit"            onclick='openEditModal(<?= json_encode($u) ?>)'><i class="fas fa-pencil"></i></button>
                                    <button class="act-btn pw"     title="Change Password" onclick="openPasswordModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>')"><i class="fas fa-key"></i></button>
                                    <?php if ($hasMigrated): ?>
                                    <button class="act-btn toggle" title="Toggle Status"   onclick="toggleStatus(<?= (int)$u['id'] ?>, '<?= $status ?>')"><i class="fas fa-power-off"></i></button>
                                    <?php endif; ?>
                                    <button class="act-btn del"    title="Delete"          onclick="deleteUser(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>')"><i class="fas fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-footer">
                <span class="pagination-info" id="paginationInfo"></span>
                <div class="pagination" id="pagination"></div>
            </div>
        </div>

    </div><!-- /content -->

    <?= nexusFooter() ?>
</div><!-- /main -->

<!-- ADD / EDIT MODAL -->
<div class="modal-overlay" id="userModal">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="userModalTitle">Add New User</span>
            <button class="modal-close" onclick="closeModal('userModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="userForm" autocomplete="off">
                <input type="hidden" id="userId"     name="id">
                <input type="hidden" id="userAction" name="action" value="add">
                <div class="form-grid">
                    <div class="form-row-2">
                        <div class="field">
                            <label>Full Name</label>
                            <input type="text" id="uName" name="name" placeholder="John Doe" required>
                        </div>
                        <div class="field">
                            <label>Username</label>
                            <input type="text" id="uUsername" name="username" placeholder="johndoe" required autocomplete="off">
                        </div>
                    </div>
                    <div class="field">
                        <label>Email Address</label>
                        <input type="email" id="uEmail" name="email" placeholder="john@company.com" required>
                    </div>
                    <?php if ($hasMigrated): ?>
                    <div class="form-row-2">
                        <div class="field">
                            <label>Role</label>
                            <select id="uRole" name="role">
                                <option value="admin">Admin</option>
                                <option value="staff">Staff</option>
                                <option value="viewer">Viewer</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Status</label>
                            <select id="uStatus" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div id="passwordSection">
                        <div class="modal-divider"></div>
                        <div class="field">
                            <label>Password</label>
                            <div class="pw-wrap">
                                <input type="password" id="uPassword" name="password" placeholder="Min. 8 characters" autocomplete="new-password" oninput="checkStrength(this.value)">
                                <button type="button" class="pw-eye" onclick="togglePw('uPassword',this)"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="pw-strength-bar"><div class="pw-strength-fill" id="strengthBar"></div></div>
                            <div class="pw-strength-label" id="strengthLabel">Enter a password</div>
                        </div>
                        <div class="field" style="margin-top:12px">
                            <label>Confirm Password</label>
                            <div class="pw-wrap">
                                <input type="password" id="uConfirmPassword" name="confirm_password" placeholder="Re-enter password" autocomplete="new-password">
                                <button type="button" class="pw-eye" onclick="togglePw('uConfirmPassword',this)"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('userModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitUserForm()"><i class="fas fa-floppy-disk"></i> Save User</button>
        </div>
    </div>
</div>

<!-- CHANGE PASSWORD MODAL -->
<div class="modal-overlay" id="passwordModal">
    <div class="modal-box sm">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-key" style="margin-right:8px;color:var(--info)"></i>Change Password</span>
            <button class="modal-close" onclick="closeModal('passwordModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-family:var(--mono);font-size:12px;color:var(--text-sec);margin-bottom:20px">
                Updating password for: <strong id="pwUsername" style="color:var(--accent-hi)"></strong>
            </p>
            <form id="passwordForm" autocomplete="off">
                <input type="hidden" id="pwUserId" name="id">
                <input type="hidden" name="action" value="change_password">
                <div class="form-grid">
                    <div class="field">
                        <label>New Password</label>
                        <div class="pw-wrap">
                            <input type="password" id="newPassword" name="new_password" placeholder="Min. 8 characters" required oninput="checkStrength2(this.value)">
                            <button type="button" class="pw-eye" onclick="togglePw('newPassword',this)"><i class="fas fa-eye"></i></button>
                        </div>
                        <div class="pw-strength-bar"><div class="pw-strength-fill" id="strengthBar2"></div></div>
                        <div class="pw-strength-label" id="strengthLabel2">Enter a password</div>
                    </div>
                    <div class="field">
                        <label>Confirm New Password</label>
                        <div class="pw-wrap">
                            <input type="password" id="confirmNewPassword" name="confirm_password" placeholder="Re-enter password" required>
                            <button type="button" class="pw-eye" onclick="togglePw('confirmNewPassword',this)"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('passwordModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitPasswordForm()"><i class="fas fa-lock"></i> Update Password</button>
        </div>
    </div>
</div>

<!-- VIEW MODAL -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title">User Profile</span>
            <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="viewModalBody"></div>
        <div class="modal-footer">
            <button class="btn btn-ghost"   onclick="closeModal('viewModal')">Close</button>
            <button class="btn btn-warn"    id="viewEditBtn"><i class="fas fa-pencil"></i> Edit</button>
            <button class="btn btn-primary" id="viewPwBtn"><i class="fas fa-key"></i> Change Password</button>
        </div>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box sm">
        <div class="modal-header">
            <span class="modal-title danger"><i class="fas fa-triangle-exclamation" style="margin-right:8px"></i>Delete User</span>
            <button class="modal-close" onclick="closeModal('deleteModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px;color:var(--text-sec);line-height:1.7">
                Permanently delete <strong id="deleteUsername" style="color:var(--text-pri)"></strong>?<br>
                This action <strong style="color:var(--danger)">cannot be undone</strong>.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost"  onclick="closeModal('deleteModal')">Cancel</button>
            <button class="btn btn-danger-ghost" id="confirmDeleteBtn"><i class="fas fa-trash"></i> Delete Permanently</button>
        </div>
    </div>
</div>

<?= nexusToast() ?>
<?= nexusJS() ?>

<script>
const MIGRATED = <?= $hasMigrated ? 'true' : 'false' ?>;

/* ── Password eye ── */
function togglePw(id, btn) {
    nexusPwToggle(id, btn);
}

/* ── Password strength ── */
function checkStrength(v)  { nexusPwStrength(v, 'strengthBar',  'strengthLabel'); }
function checkStrength2(v) { nexusPwStrength(v, 'strengthBar2', 'strengthLabel2'); }

/* ── Add user ── */
function openAddModal() {
    document.getElementById('userForm').reset();
    document.getElementById('userId').value     = '';
    document.getElementById('userAction').value = 'add';
    document.getElementById('userModalTitle').textContent = 'Add New User';
    document.getElementById('passwordSection').style.display = 'block';
    document.getElementById('uPassword').required = true;
    nexusPwStrength('', 'strengthBar', 'strengthLabel');
    openModal('userModal');
}

/* ── Edit user ── */
function openEditModal(user) {
    document.getElementById('userForm').reset();
    document.getElementById('userAction').value   = 'update';
    document.getElementById('userModalTitle').textContent = 'Edit User';
    document.getElementById('userId').value       = user.id;
    document.getElementById('uName').value        = user.name     || '';
    document.getElementById('uUsername').value    = user.username || '';
    document.getElementById('uEmail').value       = user.email    || '';
    if (MIGRATED) {
        document.getElementById('uRole').value   = user.role   || 'admin';
        document.getElementById('uStatus').value = user.status || 'active';
    }
    document.getElementById('passwordSection').style.display = 'none';
    document.getElementById('uPassword').required = false;
    openModal('userModal');
}

/* ── Submit user form ── */
function submitUserForm() {
    const form   = document.getElementById('userForm');
    const action = document.getElementById('userAction').value;
    if (!form.checkValidity()) { form.reportValidity(); return; }
    if (action === 'add') {
        const pw  = document.getElementById('uPassword').value;
        const cpw = document.getElementById('uConfirmPassword').value;
        if (!pw)           { showToast('Password is required', 'error'); return; }
        if (pw.length < 8) { showToast('Password must be at least 8 characters', 'error'); return; }
        if (pw !== cpw)    { showToast('Passwords do not match', 'error'); return; }
    }
    fetch('user_actions.php', { method: 'POST', body: new FormData(form) })
        .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
        .then(d => {
            if (d.status === 'success') {
                showToast(d.message || 'User saved successfully');
                if (d.warning) showToast(d.warning, 'warning');
                closeModal('userModal');
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(d.message || 'An error occurred', 'error');
            }
        })
        .catch(err => showToast('Server error: ' + err.message, 'error'));
}

/* ── Change password ── */
function openPasswordModal(id, username) {
    document.getElementById('passwordForm').reset();
    document.getElementById('pwUserId').value         = id;
    document.getElementById('pwUsername').textContent = username;
    nexusPwStrength('', 'strengthBar2', 'strengthLabel2');
    openModal('passwordModal');
}
function submitPasswordForm() {
    const pw  = document.getElementById('newPassword').value;
    const cpw = document.getElementById('confirmNewPassword').value;
    if (!pw)           { showToast('Password is required', 'error'); return; }
    if (pw.length < 8) { showToast('Password must be at least 8 characters', 'error'); return; }
    if (pw !== cpw)    { showToast('Passwords do not match', 'error'); return; }
    fetch('user_actions.php', { method: 'POST', body: new FormData(document.getElementById('passwordForm')) })
        .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
        .then(d => {
            if (d.status === 'success') { showToast(d.message || 'Password updated'); closeModal('passwordModal'); }
            else showToast(d.message || 'Failed to update password', 'error');
        })
        .catch(err => showToast('Server error: ' + err.message, 'error'));
}

/* ── View user ── */
function esc(v) { return String(v||'—').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function avatarColor(name) {
    const colors=['#4f6ef7','#34d399','#f5a623','#e05c6a','#a78bfa','#38bdf8','#fb7185','#4ade80'];
    let h=0; for(const c of String(name||'')) h=(h*31+c.charCodeAt(0))%colors.length; return colors[h];
}
function getInitials(name) { return String(name||'?').split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase(); }

function viewUser(user) {
    const roleBadge = {
        admin:  '<span class="badge badge-admin">Admin</span>',
        staff:  '<span class="badge badge-staff">Staff</span>',
        viewer: '<span class="badge badge-viewer">Viewer</span>'
    }[user.role||'admin'] || '—';
    const statusBadge = (user.status||'active')==='active'
        ? '<span class="badge badge-success">Active</span>'
        : '<span class="badge badge-inactive">Inactive</span>';
    const displayName = user.name || user.username;
    document.getElementById('viewModalBody').innerHTML = `
        <div class="detail-grid">
            <div class="detail-row full" style="display:flex;align-items:center;gap:16px;padding:16px 0">
                <div class="u-avatar" style="width:52px;height:52px;font-size:18px;border-radius:12px;background:${avatarColor(displayName)}">${getInitials(displayName)}</div>
                <div>
                    <div style="font-size:18px;font-weight:800;color:var(--text-pri)">${esc(displayName)}</div>
                    <div style="font-family:var(--mono);font-size:12px;color:var(--text-dim)">@${esc(user.username)}</div>
                </div>
            </div>
            <div class="detail-row"><div class="detail-key">Email</div><div class="detail-val mono">${esc(user.email||'—')}</div></div>
            <div class="detail-row"><div class="detail-key">Role</div><div class="detail-val">${MIGRATED ? roleBadge : '—'}</div></div>
            <div class="detail-row"><div class="detail-key">Status</div><div class="detail-val">${MIGRATED ? statusBadge : '—'}</div></div>
            <div class="detail-row"><div class="detail-key">Last Login</div><div class="detail-val mono">${esc(user.last_login||'—')}</div></div>
            <div class="detail-row"><div class="detail-key">Created</div><div class="detail-val mono">${esc(user.created_at||'—')}</div></div>
            <div class="detail-row"><div class="detail-key">Updated</div><div class="detail-val mono">${esc(user.updated_at||'—')}</div></div>
            <div class="detail-row"><div class="detail-key">User ID</div><div class="detail-val mono">#${user.id}</div></div>
        </div>`;
    document.getElementById('viewEditBtn').onclick = () => { closeModal('viewModal'); openEditModal(user); };
    document.getElementById('viewPwBtn').onclick   = () => { closeModal('viewModal'); openPasswordModal(user.id, user.username); };
    openModal('viewModal');
}

/* ── Toggle status ── */
function toggleStatus(id, current) {
    const newStatus = current === 'active' ? 'inactive' : 'active';
    const fd = new FormData();
    fd.append('action','toggle_status'); fd.append('id',id); fd.append('status',newStatus);
    fetch('user_actions.php', { method:'POST', body:fd })
        .then(r => { if(!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
        .then(d => {
            if (d.status==='success') { showToast(d.message); setTimeout(()=>location.reload(),600); }
            else showToast(d.message||'Error updating status','error');
        })
        .catch(err => showToast('Server error: '+err.message,'error'));
}

/* ── Delete ── */
let pendingDeleteId = null;
function deleteUser(id, username) {
    pendingDeleteId = id;
    document.getElementById('deleteUsername').textContent = '@' + username;
    openModal('deleteModal');
}
document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
    if (!pendingDeleteId) return;
    const fd = new FormData();
    fd.append('action','delete'); fd.append('id',pendingDeleteId);
    fetch('user_actions.php', { method:'POST', body:fd })
        .then(r => { if(!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
        .then(d => {
            closeModal('deleteModal');
            if (d.status==='success') { showToast(d.message); setTimeout(()=>location.reload(),600); }
            else showToast(d.message||'Error deleting user','error');
        })
        .catch(err => showToast('Server error: '+err.message,'error'));
});

/* ── Sort / Filter / Paginate ── */
let pageSize=10, currentPage=1, sortCol=-1, sortDir='asc';
function getAllRows() { return Array.from(document.querySelectorAll('#usersBody tr[data-role]')); }

function syncSearch(src) {
    // src may be 'topbar' (called from nexusTopbar search) or 'table'
    const srcId = (src === 'topbar') ? 'topbarSearch' : 'tableSearch';
    // nexusTopbar renders the search input without an id — sync via value
    const q = (typeof src === 'string' && src.length > 1 && src !== 'topbar' && src !== 'table')
        ? src  // called directly with the value string from nexusTopbar oninput
        : document.getElementById(srcId)?.value || '';
    const tbl = document.getElementById('tableSearch');
    if (tbl) tbl.value = q;
    applyFilters();
}

function applyFilters() {
    const q      = document.getElementById('tableSearch')?.value.toLowerCase() || '';
    const role   = MIGRATED ? (document.getElementById('roleFilter')?.value   || '') : '';
    const status = MIGRATED ? (document.getElementById('statusFilter')?.value || '') : '';
    getAllRows().forEach(row => {
        const matchQ = !q      || row.textContent.toLowerCase().includes(q);
        const matchR = !role   || row.dataset.role   === role;
        const matchS = !status || row.dataset.status === status;
        row.style.display = (matchQ && matchR && matchS) ? '' : 'none';
    });
    currentPage = 1; renderPagination();
}
function changePageSize() {
    pageSize = parseInt(document.getElementById('pageSizeSelect').value);
    currentPage = 1; renderPagination();
}
function sortTable(col) {
    const ths = document.querySelectorAll('#usersTable thead th');
    ths.forEach(th => th.classList.remove('sorted-asc','sorted-desc'));
    if (sortCol===col) sortDir = sortDir==='asc'?'desc':'asc'; else { sortCol=col; sortDir='asc'; }
    ths[col].classList.add(sortDir==='asc'?'sorted-asc':'sorted-desc');
    const tbody = document.getElementById('usersBody');
    const rows  = getAllRows();
    rows.sort((a,b) => {
        const at=a.cells[col]?.textContent.trim()||'', bt=b.cells[col]?.textContent.trim()||'';
        return sortDir==='asc' ? at.localeCompare(bt,undefined,{numeric:true}) : bt.localeCompare(at,undefined,{numeric:true});
    });
    rows.forEach(r => tbody.appendChild(r));
    applyFilters();
}
function renderPagination() {
    const visible = getAllRows().filter(r=>r.style.display!=='none');
    const total   = visible.length;
    const pages   = Math.max(1, Math.ceil(total/pageSize));
    if (currentPage>pages) currentPage=pages;
    visible.forEach((r,i) => { r.style.display=(i>=(currentPage-1)*pageSize&&i<currentPage*pageSize)?'':'none'; });
    const from=total===0?0:(currentPage-1)*pageSize+1, to=Math.min(currentPage*pageSize,total);
    document.getElementById('paginationInfo').textContent=`Showing ${from}–${to} of ${total} users`;
    const pg=document.getElementById('pagination'); pg.innerHTML='';
    const mkBtn=(lbl,pg_,dis,act) => {
        const b=document.createElement('button');
        b.className='page-btn'+(act?' active':'');
        b.innerHTML=lbl; b.disabled=dis;
        b.onclick=()=>{currentPage=pg_;renderPagination();}; return b;
    };
    pg.appendChild(mkBtn('<i class="fas fa-chevron-left"></i>',currentPage-1,currentPage===1,false));
    let rs=Math.max(1,currentPage-2),re=Math.min(pages,rs+4);
    if(re-rs<4) rs=Math.max(1,re-4);
    if(rs>1){ pg.appendChild(mkBtn('1',1,false,false)); if(rs>2) pg.appendChild(Object.assign(document.createElement('span'),{className:'page-btn',style:'cursor:default',textContent:'…'})); }
    for(let p=rs;p<=re;p++) pg.appendChild(mkBtn(p,p,false,p===currentPage));
    if(re<pages){ if(re<pages-1) pg.appendChild(Object.assign(document.createElement('span'),{className:'page-btn',style:'cursor:default',textContent:'…'})); pg.appendChild(mkBtn(pages,pages,false,false)); }
    pg.appendChild(mkBtn('<i class="fas fa-chevron-right"></i>',currentPage+1,currentPage===pages,false));
}

/* ── Export CSV ── */
function exportCSV() {
    const rows=getAllRows().filter(r=>r.style.display!=='none');
    if(!rows.length){ showToast('No data to export','error'); return; }
    const headers=['Name','Username','Email','Role','Status','Created'];
    const lines=[headers.join(',')];
    rows.forEach(r => {
        const cells=Array.from(r.cells).slice(0,6);
        lines.push(cells.map(c=>`"${c.textContent.trim().replace(/"/g,'""')}"`).join(','));
    });
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([lines.join('\n')],{type:'text/csv'}));
    a.download=`users_${new Date().toISOString().slice(0,10)}.csv`;
    a.click(); showToast('CSV exported successfully');
}

document.addEventListener('DOMContentLoaded', () => renderPagination());
</script>
</body>
</html>