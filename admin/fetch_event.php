<?php
session_start();
require '../config/db.php';
require __DIR__ . '/_rbac.php';
nexus_require_role_json($pdo, ['admin', 'staff']);

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Missing ID']);
    exit;
}

$id = (int) $_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM event_settings WHERE id = ?");
$stmt->execute([$id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    echo json_encode(['error' => 'Event not found']);
    exit;
}

echo json_encode($event);
