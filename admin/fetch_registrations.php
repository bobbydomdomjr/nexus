<?php
// Always output JSON — catch everything before any output
ini_set('display_errors', '0');
error_reporting(0);

set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    header('Content-Type: application/json');
    echo json_encode([
        'draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [],
        'error' => "PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}"
    ]);
    exit;
});

register_shutdown_function(function(): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            echo json_encode([
                'draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [],
                'error' => "Fatal: {$err['message']} in {$err['file']}:{$err['line']}"
            ]);
        }
    }
});

header('Content-Type: application/json');

require '../config/db.php';

$draw   = (int)($_POST['draw']   ?? 1);
$start  = (int)($_POST['start']  ?? 0);
$length = (int)($_POST['length'] ?? 10);

$selected_event_id = trim($_POST['event_id']  ?? '');
$date_from         = trim($_POST['date_from'] ?? '');
$date_to           = trim($_POST['date_to']   ?? '');

$where  = [];
$params = [];

try {
    if ($selected_event_id !== '' && is_numeric($selected_event_id)) {
    $stmtEvent = $pdo->prepare("SELECT agenda FROM event_settings WHERE id = ?");
    $stmtEvent->execute([(int)$selected_event_id]);
    $eventRow = $stmtEvent->fetch(PDO::FETCH_ASSOC);

    if ($eventRow) {
        $where[]  = "TRIM(agenda) = ?";
        $params[] = trim($eventRow['agenda']);
    }
}

// Always apply date filter against start_date when provided
if (!empty($date_from) && !empty($date_to)) {
    $where[]  = "DATE(start_date) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
} elseif (!empty($date_from)) {
    $where[]  = "DATE(start_date) >= ?";
    $params[] = $date_from;
} elseif (!empty($date_to)) {
    $where[]  = "DATE(start_date) <= ?";
    $params[] = $date_to;
}

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // ── Detect which optional columns actually exist ──────────────────
    $existingCols = [];
    $colCheck = $pdo->query("SHOW COLUMNS FROM event_registrations");
    foreach ($colCheck->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $existingCols[] = $col['Field'];
    }

    $hasActive    = in_array('active',        $existingCols);
    $hasUpdatedAt = in_array('updated_at',    $existingCols);
    $hasMajorSvc  = in_array('major_service', $existingCols);

    $selectActive    = $hasActive    ? 'active,'        : '1 AS active,';
    $selectUpdatedAt = $hasUpdatedAt ? 'updated_at,'    : 'NULL AS updated_at,';
    $majorSvcField   = $hasMajorSvc  ? 'major_service,' : "'' AS major_service,";
    $majorSvcConcat  = $hasMajorSvc
        ? "IF(major_service IS NOT NULL AND TRIM(major_service) != '', CONCAT(' - ', TRIM(major_service)), '')"
        : "''";

    // ── Counts ────────────────────────────────────────────────────────
    $totalRecords = (int)$pdo->query("SELECT COUNT(*) FROM event_registrations")->fetchColumn();

    $filteredStmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrations $whereSQL");
    $filteredStmt->execute($params);
    $filteredRecords = (int)$filteredStmt->fetchColumn();

    // ── Main query ────────────────────────────────────────────────────
    $sql = "
        SELECT
            id,
            rank,
            first_name,
            last_name,
            middle_name,
            middle_initial,
            ext_name,
            {$majorSvcField}
            unit_office,
            serial_number,
            designation,
            email,
            contact_number,
            {$selectActive}
            created_at,
            {$selectUpdatedAt}
            TRIM(CONCAT(
                COALESCE(TRIM(rank), ''),
                IF(first_name IS NOT NULL AND TRIM(first_name) != '', CONCAT(' ', TRIM(first_name)), ''),
                IF(last_name  IS NOT NULL AND TRIM(last_name)  != '', CONCAT(' ', TRIM(last_name)),  ''),
                IF(middle_initial IS NOT NULL AND TRIM(middle_initial) != '', CONCAT(', ', TRIM(middle_initial)), ''),
                IF(ext_name IS NOT NULL AND TRIM(ext_name) != '', CONCAT(' ', TRIM(ext_name)), ''),
                {$majorSvcConcat}
            )) AS fullname
        FROM event_registrations
        $whereSQL
        ORDER BY last_name ASC, first_name ASC
        LIMIT ?, ?
    ";

    $stmt = $pdo->prepare($sql);

    $i = 1;
    foreach ($params as $val) {
        $stmt->bindValue($i++, $val);
    }
    $stmt->bindValue($i++, $start,  PDO::PARAM_INT);
    $stmt->bindValue($i++, $length, PDO::PARAM_INT);
    $stmt->execute();

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data'            => $data,
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => 0,
        'recordsFiltered' => 0,
        'data'            => [],
        'error'           => 'DB error: ' . $e->getMessage(),
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => 0,
        'recordsFiltered' => 0,
        'data'            => [],
        'error'           => 'Error: ' . $e->getMessage(),
    ]);
}