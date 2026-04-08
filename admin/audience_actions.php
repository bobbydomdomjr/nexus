<?php
/**
 * audience_actions.php
 * Handles per-row actions on event_registrations:
 *   action=update  — edit all fields
 *   action=toggle  — enable / disable (active flag)
 *   action=delete  — permanent delete
 */

session_start();

ini_set('display_errors', '0');
error_reporting(0);

// Catch fatal errors before any output
register_shutdown_function(function(): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => "Fatal: {$err['message']} in {$err['file']}:{$err['line']}"]);
        }
    }
});

set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}"]);
    exit;
});

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require '../config/db.php';

function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$action = trim($_POST['action'] ?? '');
$id     = (int)($_POST['id']    ?? 0);

if (!$action) jsonErr('No action specified.');
if (!$id)     jsonErr('Invalid record ID.');

try {

    /* ── Verify record exists ── */
    $check = $pdo->prepare("SELECT id FROM event_registrations WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetch()) jsonErr('Record not found.', 404);

    /* ── Detect which optional columns exist ── */
    $existingCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM event_registrations")->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $existingCols[] = $col['Field'];
    }
    $hasActive    = in_array('active',        $existingCols);
    $hasUpdatedAt = in_array('updated_at',    $existingCols);
    $hasMajorSvc  = in_array('major_service', $existingCols);

    /* ════════════════════════════
       UPDATE
    ════════════════════════════ */
    if ($action === 'update') {

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');

        if ($firstName === '' && $lastName === '') {
            jsonErr('First name or last name is required.');
        }

        $rank          = trim($_POST['rank']           ?? '');
        $majorService  = trim($_POST['major_service']  ?? '');
        $middleName    = trim($_POST['middle_name']    ?? '');
        $middleInitial = trim($_POST['middle_initial'] ?? '');
        $extName       = trim($_POST['ext_name']       ?? '');
        $unitOffice    = trim($_POST['unit_office']    ?? '');
        $designation   = trim($_POST['designation']    ?? '');
        $serialNumber  = trim($_POST['serial_number']  ?? '');
        $email         = trim($_POST['email']          ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $active        = (int)($_POST['active']        ?? 1);

        // Build SET clause dynamically based on existing columns
        $setClauses = [
            'rank           = ?',
            'first_name     = ?',
            'last_name      = ?',
            'middle_name    = ?',
            'middle_initial = ?',
            'ext_name       = ?',
            'unit_office    = ?',
            'designation    = ?',
            'serial_number  = ?',
            'email          = ?',
            'contact_number = ?',
        ];
        $bindings = [
            $rank, $firstName, $lastName,
            $middleName, $middleInitial, $extName,
            $unitOffice, $designation, $serialNumber,
            $email, $contactNumber,
        ];

        if ($hasMajorSvc) {
            $setClauses[] = 'major_service = ?';
            $bindings[]   = $majorService;
        }
        if ($hasActive) {
            $setClauses[] = 'active = ?';
            $bindings[]   = $active;
        }
        if ($hasUpdatedAt) {
            $setClauses[] = 'updated_at = NOW()';
        }

        $bindings[] = $id; // WHERE id = ?

        $stmt = $pdo->prepare("UPDATE event_registrations SET " . implode(', ', $setClauses) . " WHERE id = ?");
        $stmt->execute($bindings);

        echo json_encode(['success' => true, 'message' => 'Registration updated successfully.']);
        exit;
    }

    /* ════════════════════════════
       TOGGLE
    ════════════════════════════ */
    if ($action === 'toggle') {
        if (!$hasActive) {
            // Column doesn't exist yet — inform admin
            jsonErr('The "active" column does not exist. Run: ALTER TABLE event_registrations ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1;');
        }

        $newActive = (int)($_POST['active'] ?? 0);

        $setClause = $hasUpdatedAt ? 'active = ?, updated_at = NOW()' : 'active = ?';
        $bindings  = $hasUpdatedAt ? [$newActive, $id] : [$newActive, $id];

        $stmt = $pdo->prepare("UPDATE event_registrations SET {$setClause} WHERE id = ?");
        $stmt->execute($bindings);

        $label = $newActive ? 'enabled' : 'disabled';
        echo json_encode(['success' => true, 'message' => "Registration {$label} successfully.", 'active' => $newActive]);
        exit;
    }

    /* ════════════════════════════
       DELETE
    ════════════════════════════ */
    if ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM event_registrations WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Registration deleted permanently.']);
        exit;
    }

    jsonErr('Unknown action.');

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}