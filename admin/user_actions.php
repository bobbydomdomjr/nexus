<?php
session_start();

/* ─────────────────────────────────────────────
   ALWAYS output JSON — catch ALL errors early
───────────────────────────────────────────── */
header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
    exit;
});

set_error_handler(function (int $errno, string $errstr) {
    throw new ErrorException($errstr, $errno);
});

/* ─────────────────────────────────────────────
   DB CONNECTION
───────────────────────────────────────────── */
try {
    require '../config/db.php';
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

require __DIR__ . '/_rbac.php';

/* ─────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────── */
function respond(string $status, string $message, array $extra = []): void
{
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
    exit;
}

function sanitize(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function validatePassword(string $pw): ?string
{
    if (strlen($pw) < 8)                     return 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $pw))         return 'Password must contain at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $pw))         return 'Password must contain at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $pw)) return 'Password must contain at least one special character.';
    return null;
}

function userExists(PDO $pdo, string $username, string $email, ?int $excludeId = null): array
{
    $errors = [];

    // Check username
    $sql    = 'SELECT id FROM admin_users WHERE username = :username';
    $params = [':username' => $username];
    if ($excludeId !== null) { $sql .= ' AND id != :id'; $params[':id'] = $excludeId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) $errors[] = 'Username already exists.';

    // Check email (only if email column exists)
    try {
        $sql    = 'SELECT id FROM admin_users WHERE email = :email';
        $params = [':email' => $email];
        if ($excludeId !== null) { $sql .= ' AND id != :id'; $params[':id'] = $excludeId; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch()) $errors[] = 'Email address already in use.';
    } catch (PDOException $e) {
        // email column may not exist yet — skip check
    }

    return $errors;
}

function countActiveAdmins(PDO $pdo, ?int $excludeId = null): int
{
    try {
        $sql    = "SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND status = 'active'";
        $params = [];
        if ($excludeId !== null) { $sql .= ' AND id != :id'; $params[':id'] = $excludeId; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        // role/status columns may not exist — assume safe
        return 99;
    }
}

/* ─────────────────────────────────────────────
   INPUT
───────────────────────────────────────────── */
$action = trim($_POST['action'] ?? '');

/* ═══════════════════════════════════════════
   SELF-SERVICE (staff / viewer — own row only)
═══════════════════════════════════════════ */
if ($action === 'update_self') {
    nexus_require_role_json($pdo, ['staff', 'viewer']);

    $myId = (int) ($_SESSION['admin_id'] ?? 0);
    if (!$myId) {
        respond('error', 'Invalid session.');
    }

    $name     = sanitize($_POST['name'] ?? '');
    $username = sanitize($_POST['username'] ?? '');
    $email    = sanitize($_POST['email'] ?? '');

    if (!$name || !$username) {
        respond('error', 'Name and username are required.');
    }

    try {
        $colsStmt = $pdo->query('DESCRIBE admin_users');
        $hasEmail = in_array('email', array_column($colsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field'), true);
    } catch (PDOException $e) {
        $hasEmail = false;
    }
    if ($hasEmail) {
        if ($email === '') {
            respond('error', 'Email is required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond('error', 'Invalid email address.');
        }
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond('error', 'Invalid email address.');
    }

    $dupes = userExists($pdo, $username, $email, $myId);
    if ($dupes) {
        respond('error', implode(' ', $dupes));
    }

    try {
        $stmt = $pdo->prepare('
            UPDATE admin_users
               SET username   = :username,
                   name       = :name,
                   email      = :email,
                   updated_at = NOW()
             WHERE id = :id
        ');
        $stmt->execute([
            ':username' => $username,
            ':name'     => $name,
            ':email'    => $email,
            ':id'       => $myId,
        ]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            $stmt = $pdo->prepare('UPDATE admin_users SET username = :username, name = :name WHERE id = :id');
            $stmt->execute([':username' => $username, ':name' => $name, ':id' => $myId]);
        } else {
            throw $e;
        }
    }

    $_SESSION['admin_name']     = $name;
    $_SESSION['admin_username'] = $username;
    $_SESSION['admin_email']    = $email;

    respond('success', 'Your profile was updated.');
}

if ($action === 'change_password_self') {
    nexus_require_role_json($pdo, ['staff', 'viewer']);

    $myId    = (int) ($_SESSION['admin_id'] ?? 0);
    $current = $_POST['current_password'] ?? '';
    $newPw   = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$myId) {
        respond('error', 'Invalid session.');
    }
    if ($current === '' || $newPw === '') {
        respond('error', 'Current and new password are required.');
    }
    if ($newPw !== $confirm) {
        respond('error', 'Passwords do not match.');
    }

    $pwError = validatePassword($newPw);
    if ($pwError) {
        respond('error', $pwError);
    }

    $check = $pdo->prepare('SELECT username, password FROM admin_users WHERE id = :id');
    $check->execute([':id' => $myId]);
    $user = $check->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        respond('error', 'User not found.');
    }
    if (!password_verify($current, $user['password'])) {
        respond('error', 'Current password is incorrect.');
    }
    if (password_verify($newPw, $user['password'])) {
        respond('error', 'New password must differ from the current password.');
    }

    $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        $stmt = $pdo->prepare('UPDATE admin_users SET password = :password, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':password' => $hash, ':id' => $myId]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            $stmt = $pdo->prepare('UPDATE admin_users SET password = :password WHERE id = :id');
            $stmt->execute([':password' => $hash, ':id' => $myId]);
        } else {
            throw $e;
        }
    }

    respond('success', 'Password updated successfully.');
}

nexus_require_role_json($pdo, ['admin']);

if (empty($action)) {
    respond('error', 'No action specified.');
}

/* ═══════════════════════════════════════════
   ADD USER
═══════════════════════════════════════════ */
if ($action === 'add') {

    $name     = sanitize($_POST['name']          ?? '');
    $username = sanitize($_POST['username']      ?? '');
    $email    = sanitize($_POST['email']         ?? '');
    $role     = sanitize($_POST['role']          ?? 'admin');
    $status   = sanitize($_POST['status']        ?? 'active');
    $password = $_POST['password']               ?? '';
    $confirm  = $_POST['confirm_password']       ?? '';

    if (!$name || !$username || !$email || !$password)
        respond('error', 'All fields are required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        respond('error', 'Invalid email address.');
    if (!in_array($role,   ['admin','staff','viewer']))
        respond('error', 'Invalid role selected.');
    if (!in_array($status, ['active','inactive']))
        respond('error', 'Invalid status selected.');
    if ($password !== $confirm)
        respond('error', 'Passwords do not match.');

    $pwError = validatePassword($password);
    if ($pwError) respond('error', $pwError);

    $dupes = userExists($pdo, $username, $email);
    if ($dupes) respond('error', implode(' ', $dupes));

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_users
                (username, password, name, email, role, status, created_at, updated_at)
            VALUES
                (:username, :password, :name, :email, :role, :status, NOW(), NOW())
        ");
        $stmt->execute([
            ':username' => $username,
            ':password' => $hash,
            ':name'     => $name,
            ':email'    => $email,
            ':role'     => $role,
            ':status'   => $status,
        ]);
    } catch (PDOException $e) {
        // Fallback: try without new columns if migration not yet run
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            $stmt = $pdo->prepare("
                INSERT INTO admin_users (username, password, name, created_at)
                VALUES (:username, :password, :name, NOW())
            ");
            $stmt->execute([
                ':username' => $username,
                ':password' => $hash,
                ':name'     => $name,
            ]);
            respond('success', "User @{$username} created (partial — run migration to enable all fields).", [
                'id'      => (int) $pdo->lastInsertId(),
                'warning' => 'Database migration required. Run migrate_admin_users.sql.',
            ]);
        }
        throw $e;
    }

    respond('success', "User @{$username} created successfully.", [
        'id' => (int) $pdo->lastInsertId()
    ]);
}

/* ═══════════════════════════════════════════
   UPDATE USER
═══════════════════════════════════════════ */
if ($action === 'update') {

    $id       = (int) ($_POST['id']       ?? 0);
    $name     = sanitize($_POST['name']     ?? '');
    $username = sanitize($_POST['username'] ?? '');
    $email    = sanitize($_POST['email']    ?? '');
    $role     = sanitize($_POST['role']     ?? 'admin');
    $status   = sanitize($_POST['status']   ?? 'active');

    if (!$id)                                           respond('error', 'Invalid user ID.');
    if (!$name || !$username || !$email)                respond('error', 'Name, username, and email are required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))     respond('error', 'Invalid email address.');
    if (!in_array($role,   ['admin','staff','viewer'])) respond('error', 'Invalid role.');
    if (!in_array($status, ['active','inactive']))      respond('error', 'Invalid status.');

    $check = $pdo->prepare("SELECT id, role FROM admin_users WHERE id = :id");
    $check->execute([':id' => $id]);
    $current = $check->fetch(PDO::FETCH_ASSOC);
    if (!$current) respond('error', 'User not found.');

    $dupes = userExists($pdo, $username, $email, $id);
    if ($dupes) respond('error', implode(' ', $dupes));

    // Guard: prevent removing the last admin role
    $currentRole = $current['role'] ?? 'admin';
    if ($currentRole === 'admin' && $role !== 'admin') {
        if (countActiveAdmins($pdo, $id) < 1) {
            respond('error', 'Cannot change role — this is the last active admin account.');
        }
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE admin_users
               SET username   = :username,
                   name       = :name,
                   email      = :email,
                   role       = :role,
                   status     = :status,
                   updated_at = NOW()
             WHERE id = :id
        ");
        $stmt->execute([
            ':username' => $username,
            ':name'     => $name,
            ':email'    => $email,
            ':role'     => $role,
            ':status'   => $status,
            ':id'       => $id,
        ]);
    } catch (PDOException $e) {
        // Fallback if new columns don't exist
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            $stmt = $pdo->prepare("
                UPDATE admin_users SET username = :username, name = :name WHERE id = :id
            ");
            $stmt->execute([':username' => $username, ':name' => $name, ':id' => $id]);
            respond('success', "User @{$username} updated (partial — run migration to enable all fields).", [
                'warning' => 'Database migration required. Run migrate_admin_users.sql.',
            ]);
        }
        throw $e;
    }

    respond('success', "User @{$username} updated successfully.");
}

/* ═══════════════════════════════════════════
   CHANGE PASSWORD
═══════════════════════════════════════════ */
if ($action === 'change_password') {

    $id      = (int) ($_POST['id']             ?? 0);
    $newPw   = $_POST['new_password']           ?? '';
    $confirm = $_POST['confirm_password']       ?? '';

    if (!$id)                respond('error', 'Invalid user ID.');
    if (!$newPw)             respond('error', 'Password is required.');
    if ($newPw !== $confirm) respond('error', 'Passwords do not match.');

    $pwError = validatePassword($newPw);
    if ($pwError) respond('error', $pwError);

    $check = $pdo->prepare("SELECT username, password FROM admin_users WHERE id = :id");
    $check->execute([':id' => $id]);
    $user = $check->fetch(PDO::FETCH_ASSOC);
    if (!$user) respond('error', 'User not found.');

    if (password_verify($newPw, $user['password'])) {
        respond('error', 'New password must differ from the current password.');
    }

    $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        $stmt = $pdo->prepare("
            UPDATE admin_users SET password = :password, updated_at = NOW() WHERE id = :id
        ");
        $stmt->execute([':password' => $hash, ':id' => $id]);
    } catch (PDOException $e) {
        // Fallback without updated_at
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            $stmt = $pdo->prepare("UPDATE admin_users SET password = :password WHERE id = :id");
            $stmt->execute([':password' => $hash, ':id' => $id]);
        } else {
            throw $e;
        }
    }

    respond('success', "Password updated for @{$user['username']}.");
}

/* ═══════════════════════════════════════════
   TOGGLE STATUS
═══════════════════════════════════════════ */
if ($action === 'toggle_status') {

    $id     = (int) ($_POST['id']       ?? 0);
    $status = sanitize($_POST['status'] ?? '');

    if (!$id) respond('error', 'Invalid user ID.');
    if (!in_array($status, ['active','inactive'])) respond('error', 'Invalid status value.');

    // Self-deactivation guard
    if (isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] === $id && $status === 'inactive') {
        respond('error', 'You cannot deactivate your own account.');
    }

    $check = $pdo->prepare("SELECT username, role FROM admin_users WHERE id = :id");
    $check->execute([':id' => $id]);
    $user = $check->fetch(PDO::FETCH_ASSOC);
    if (!$user) respond('error', 'User not found.');

    $userRole = $user['role'] ?? 'admin';
    if ($userRole === 'admin' && $status === 'inactive') {
        if (countActiveAdmins($pdo, $id) < 1) {
            respond('error', 'Cannot deactivate the last active admin account.');
        }
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE admin_users SET status = :status, updated_at = NOW() WHERE id = :id
        ");
        $stmt->execute([':status' => $status, ':id' => $id]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            respond('error', 'Migration required: status column does not exist. Run migrate_admin_users.sql first.');
        }
        throw $e;
    }

    $label = $status === 'active' ? 'activated' : 'deactivated';
    respond('success', "User @{$user['username']} has been {$label}.");
}

/* ═══════════════════════════════════════════
   DELETE USER
═══════════════════════════════════════════ */
if ($action === 'delete') {

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) respond('error', 'Invalid user ID.');

    // Self-deletion guard
    if (isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] === $id) {
        respond('error', 'You cannot delete your own account.');
    }

    $check = $pdo->prepare("SELECT username, role FROM admin_users WHERE id = :id");
    $check->execute([':id' => $id]);
    $user = $check->fetch(PDO::FETCH_ASSOC);
    if (!$user) respond('error', 'User not found.');

    $userRole = $user['role'] ?? 'admin';
    if ($userRole === 'admin') {
        if (countActiveAdmins($pdo, $id) < 1) {
            respond('error', 'Cannot delete the last active admin account.');
        }
    }

    $stmt = $pdo->prepare("DELETE FROM admin_users WHERE id = :id");
    $stmt->execute([':id' => $id]);

    respond('success', "User @{$user['username']} has been permanently deleted.");
}

/* ─────────────────────────────────────────────
   UNKNOWN ACTION
───────────────────────────────────────────── */
respond('error', 'Unknown action: ' . sanitize($action));