<?php
/**
 * nexus_notif_action.php
 * Handles per-user notification read/dismiss state.
 *
 * POST params:
 *   action  — 'read' | 'dismiss'
 *   keys[]  — one or more notif_key strings
 */

session_start();
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require '../config/db.php';

$action  = $_POST['action'] ?? '';
$keys    = $_POST['keys']   ?? [];
$adminId = (int)($_SESSION['admin_id'] ?? 0);

if (!in_array($action, ['read', 'dismiss'], true) || empty($keys) || !$adminId) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

if (!is_array($keys)) $keys = [$keys];

// Sanitize keys — alphanumeric + underscore only
$keys = array_filter(array_map(fn($k) => preg_replace('/[^a-z0-9_]/i', '', $k), $keys));
if (empty($keys)) {
    echo json_encode(['success' => false, 'message' => 'No valid keys.']);
    exit;
}

try {
    // Ensure table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nexus_notif_read (
            admin_id   INT          NOT NULL,
            notif_key  VARCHAR(120) NOT NULL,
            read_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (admin_id, notif_key)
        )
    ");

    // INSERT IGNORE so duplicate reads are silently skipped
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO nexus_notif_read (admin_id, notif_key, read_at)
        VALUES (?, ?, NOW())
    ");

    foreach ($keys as $key) {
        $stmt->execute([$adminId, $key]);
    }

    echo json_encode(['success' => true, 'marked' => count($keys)]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}