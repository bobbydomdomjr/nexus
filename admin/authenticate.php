<?php
session_start();
require '../config/db.php';

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    header("Location: login.php?error=1");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
$stmt->execute([$username]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if ($admin && password_verify($password, $admin['password'])) {

    if (isset($admin['status']) && $admin['status'] !== 'active') {
        header('Location: login.php?error=inactive');
        exit;
    }

    $role = $admin['role'] ?? 'admin';
    if (!in_array($role, ['admin', 'staff', 'viewer'], true)) {
        $role = 'admin';
    }

    $_SESSION['admin_logged_in']   = true;
    $_SESSION['admin_id']           = $admin['id'];
    $_SESSION['admin_name']         = $admin['name'] ?? $admin['full_name'] ?? '';
    $_SESSION['admin_username']     = $admin['username'] ?? '';
    $_SESSION['admin_email']        = $admin['email'] ?? '';
    $_SESSION['admin_role']         = $role;

    header("Location: index.php");
    exit;

} else {
    header("Location: login.php?error=1");
    exit;
}
