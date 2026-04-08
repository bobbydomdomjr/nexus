<?php
session_start();
require '../config/db.php';
require __DIR__ . '/_rbac.php';
nexus_require_role_json($pdo, ['admin', 'staff']);

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

/* =========================
   ADD EVENT
========================= */
if ($action === 'add') {
    $required = ['agenda', 'venue', 'start_date', 'end_date', 'event_days'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(['status' => 'error', 'message' => "Missing field: $field"]);
            exit;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO event_settings
            (agenda, venue, start_date, end_date, event_days, active)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        trim($_POST['agenda']),
        trim($_POST['venue']),
        $_POST['start_date'],
        $_POST['end_date'],
        (int)$_POST['event_days'],
        isset($_POST['active']) ? (int)$_POST['active'] : 1,
    ]);

    echo json_encode([
        'status'  => 'success',
        'message' => 'Event added successfully',
        'id'      => (int)$pdo->lastInsertId(),
    ]);
    exit;
}

/* =========================
   UPDATE EVENT
========================= */
if ($action === 'update') {
    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid event ID']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE event_settings
        SET agenda = ?, venue = ?, start_date = ?, end_date = ?, event_days = ?, active = ?
        WHERE id = ?
    ");
    $stmt->execute([
        trim($_POST['agenda']),
        trim($_POST['venue']),
        $_POST['start_date'],
        $_POST['end_date'],
        (int)$_POST['event_days'],
        isset($_POST['active']) ? (int)$_POST['active'] : 1,
        $id,
    ]);

    echo json_encode(['status' => 'success', 'message' => 'Event updated successfully']);
    exit;
}

/* =========================
   TOGGLE ACTIVE STATUS
========================= */
if ($action === 'toggle') {
    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid event ID']);
        exit;
    }

    // ✅ Each event toggles independently — no "only one active" restriction
    $stmt = $pdo->prepare("UPDATE event_settings SET active = NOT active WHERE id = ?");
    $stmt->execute([$id]);

    // Return the new state so the frontend can update the button
    $row = $pdo->prepare("SELECT active FROM event_settings WHERE id = ?");
    $row->execute([$id]);
    $newActive = (int)$row->fetchColumn();

    echo json_encode([
        'status'  => 'success',
        'active'  => $newActive,
        'message' => 'Event ' . ($newActive ? 'activated' : 'deactivated') . ' successfully',
    ]);
    exit;
}

/* =========================
   DELETE EVENT
========================= */
if ($action === 'delete') {
    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid event ID']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM event_settings WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['status' => 'success', 'message' => 'Event deleted successfully']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);