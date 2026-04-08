<?php
/**
 * Role-based access for admin area.
 * Expects session_start() and $pdo (PDO) from ../config/db.php.
 *
 * admin: full access
 * staff: all except user list / user_actions
 * viewer: all except manage events, user list, certificates (incl. generate)
 */

function nexus_admin_role(PDO $pdo): string
{
    if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
        return '';
    }

    $allowed = ['admin', 'staff', 'viewer'];

    $row = null;
    try {
        $stmt = $pdo->prepare('SELECT role, status FROM admin_users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['admin_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $row = null;
    }

    if (!$row) {
        try {
            $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $_SESSION['admin_id']]);
            if ($stmt->fetch()) {
                $_SESSION['admin_role'] = 'admin';
                return 'admin';
            }
        } catch (PDOException $e) {
        }
        return '';
    }

    if (isset($row['status']) && $row['status'] !== 'active') {
        return '';
    }

    $r = $row['role'] ?? 'admin';
    if (!in_array($r, $allowed, true)) {
        $r = 'admin';
    }
    $_SESSION['admin_role'] = $r;
    return $r;
}

function nexus_require_role_page(PDO $pdo, array $allowedRoles): void
{
    if (empty($_SESSION['admin_logged_in'])) {
        header('Location: login.php');
        exit;
    }

    $role = nexus_admin_role($pdo);
    if ($role === '') {
        header('Location: login.php');
        exit;
    }
    if (!in_array($role, $allowedRoles, true)) {
        header('Location: index.php?denied=1');
        exit;
    }
}

function nexus_require_role_json(PDO $pdo, array $allowedRoles): void
{
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in again.']);
        exit;
    }

    $role = nexus_admin_role($pdo);
    if ($role === '') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Session expired or account inactive.']);
        exit;
    }
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
        exit;
    }
}
