<?php
/**
 * import_registrations.php
 * Accepts a CSV or Excel file and bulk-inserts into event_registrations.
 *
 * POST params:
 *   file        — uploaded file (.csv or .xlsx/.xls)
 *   event_id    — int, links to event_settings
 *   mode        — 'preview' | 'import'
 *   skip_header — '1' | '0'
 *   col_*       — column mapping (col_first_name, col_last_name, etc.)
 */

session_start();

/* ─────────────────────────────────────────────
   Always output JSON — suppress HTML error pages
───────────────────────────────────────────── */
ini_set('display_errors', '0');
error_reporting(0);

set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}"]);
    exit;
});

register_shutdown_function(function(): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => "Fatal error: {$err['message']} in {$err['file']}:{$err['line']}"]);
        }
    }
});

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require '../config/db.php';

/* ─────────────────────────────────────────────
   AUTOLOADER (PhpSpreadsheet — optional)
───────────────────────────────────────────── */
$autoload       = __DIR__ . '/../vendor/autoload.php';
$hasSpreadsheet = false;
if (file_exists($autoload)) {
    require_once $autoload;
    $hasSpreadsheet = class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory');
}

/* ─────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────── */
function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

function getCol(array $row, int $idx): string {
    return ($idx >= 0 && isset($row[$idx])) ? trim($row[$idx]) : '';
}

/**
 * Safely parse a date string into Y-m-d format.
 * Returns empty string if parsing fails — never returns 1970-01-01 on bad input.
 */
function parseDateSafe(string $value): string {
    $value = trim($value);
    if ($value === '') return '';

    // Already Y-m-d
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value;

    // Try explicit formats — most common in CSV/Excel exports
    $formats = [
        'Y-m-d',        // 2026-03-22
        'm/d/Y',        // 03/22/2026
        'd/m/Y',        // 22/03/2026
        'm-d-Y',        // 03-22-2026
        'd-m-Y',        // 22-03-2026
        'Y/m/d',        // 2026/03/22
        'n/j/Y',        // 3/22/2026  (no leading zeros)
        'j/n/Y',        // 22/3/2026
        'm d Y',        // 03 22 2026
        'd M Y',        // 22 Mar 2026
        'M d Y',        // Mar 22 2026
        'd-M-Y',        // 22-Mar-2026
        'M-d-Y',        // Mar-22-2026
        'F j, Y',       // March 22, 2026
        'j F Y',        // 22 March 2026
        'Ymd',          // 20260322
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt !== false) {
            // Verify no date overflow (e.g. Feb 30)
            $errors = DateTime::getLastErrors();
            if (empty($errors['warning_count']) && empty($errors['error_count'])) {
                return $dt->format('Y-m-d');
            }
        }
    }

    // Last resort: strtotime — but only accept if it doesn't land on epoch
    $ts = strtotime($value);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }

    return ''; // Unparseable — caller will fall back to event default
}

/* ─────────────────────────────────────────────
   INPUT VALIDATION
───────────────────────────────────────────── */
$mode         = $_POST['mode']            ?? 'preview';
$eventId      = (int)($_POST['event_id']  ?? 0);
$skipHeader   = ($_POST['skip_header']    ?? '1') === '1';
$eventDay     = $_POST['event_day']       ?? '01';
$eventDayDate = $_POST['event_day_date']  ?? null;

if (!in_array($mode, ['preview', 'import'])) jsonErr('Invalid mode.');
if (!$eventId) jsonErr('No event selected.');

$evStmt = $pdo->prepare("SELECT id, agenda, start_date, end_date FROM event_settings WHERE id = ?");
$evStmt->execute([$eventId]);
$event = $evStmt->fetch(PDO::FETCH_ASSOC);
if (!$event) jsonErr('Event not found.');

/* ─────────────────────────────────────────────
   COLUMN MAPPING
   Keys match actual event_registrations columns.
   Each value is the 0-based index of the source
   column in the uploaded file, or -1 = not mapped.
───────────────────────────────────────────── */
$colMap = [
    'start_date'     => isset($_POST['col_start_date'])     ? (int)$_POST['col_start_date']     : -1,
    'end_date'       => isset($_POST['col_end_date'])       ? (int)$_POST['col_end_date']       : -1,
    'event_day'      => isset($_POST['col_event_day'])      ? (int)$_POST['col_event_day']      : -1,
    'rank'           => isset($_POST['col_rank'])           ? (int)$_POST['col_rank']           : -1,
    'first_name'     => isset($_POST['col_first_name'])     ? (int)$_POST['col_first_name']     : -1,
    'last_name'      => isset($_POST['col_last_name'])      ? (int)$_POST['col_last_name']      : -1,
    'middle_name'    => isset($_POST['col_middle_name'])    ? (int)$_POST['col_middle_name']    : -1,
    'middle_initial' => isset($_POST['col_middle_initial']) ? (int)$_POST['col_middle_initial'] : -1,
    'ext_name'       => isset($_POST['col_ext_name'])       ? (int)$_POST['col_ext_name']       : -1,
    'major_service'  => isset($_POST['col_major_service'])  ? (int)$_POST['col_major_service']  : -1,
    'unit_office'    => isset($_POST['col_unit_office'])    ? (int)$_POST['col_unit_office']    : -1,
    'designation'    => isset($_POST['col_designation'])    ? (int)$_POST['col_designation']    : -1,
    'serial_number'  => isset($_POST['col_serial_number'])  ? (int)$_POST['col_serial_number']  : -1,
    'email'          => isset($_POST['col_email'])          ? (int)$_POST['col_email']          : -1,
    'contact_number' => isset($_POST['col_contact_number']) ? (int)$_POST['col_contact_number'] : -1,
];

/* ─────────────────────────────────────────────
   FILE UPLOAD CHECK
───────────────────────────────────────────── */
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $uploadErr = $_FILES['file']['error'] ?? -1;
    $uploadMsg = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in the form.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
    ];
    jsonErr($uploadMsg[$uploadErr] ?? "Upload error (code {$uploadErr}).");
}

$tmpPath  = $_FILES['file']['tmp_name'];
$origName = $_FILES['file']['name'];
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
    jsonErr('Unsupported file type. Please upload .csv, .xlsx, or .xls.');
}

/* ─────────────────────────────────────────────
   PARSE FILE INTO ROWS
───────────────────────────────────────────── */
$rows = [];

/* ── CSV ── */
if ($ext === 'csv') {
    $handle = fopen($tmpPath, 'r');
    if (!$handle) jsonErr('Cannot open uploaded CSV file.');

    // Strip UTF-8 BOM if present
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    while (($line = fgetcsv($handle, 0, ',')) !== false) {
        $trimmed = array_map('trim', $line);
        if (count(array_filter($trimmed)) === 0) continue;
        $rows[] = $trimmed;
    }
    fclose($handle);
}

/* ── Excel (XLSX / XLS) ── */
if ($ext === 'xlsx' || $ext === 'xls') {
    if (!$hasSpreadsheet) {
        jsonErr('PhpSpreadsheet is not installed. Upload a CSV file instead, or run: composer require phpoffice/phpspreadsheet');
    }
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheet       = $spreadsheet->getActiveSheet();
        foreach ($sheet->getRowIterator() as $excelRow) {
            $cellIter = $excelRow->getCellIterator();
            $cellIter->setIterateOnlyExistingCells(false);
            $cells = [];
            foreach ($cellIter as $cell) {
                // Use formatted value for dates so they come out as strings, not serial numbers
                $cells[] = trim((string)(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::toFormattedString(
                    $cell->getValue() ?? '',
                    $cell->getStyle()->getNumberFormat()->getFormatCode()
                )));
            }
            if (count(array_filter($cells)) === 0) continue;
            $rows[] = $cells;
        }
    } catch (\Exception $e) {
        jsonErr('Failed to read Excel file: ' . $e->getMessage());
    }
}

if (empty($rows)) jsonErr('The uploaded file appears to be empty.');

/* ─────────────────────────────────────────────
   HEADER ROW & DATA ROWS
───────────────────────────────────────────── */
$headerRow = $rows[0];
$dataRows  = $skipHeader ? array_slice($rows, 1) : $rows;

if (empty($dataRows)) jsonErr('No data rows found after the header.');

/* ─────────────────────────────────────────────
   AUTO-DETECT COLUMN MAPPING from header row
───────────────────────────────────────────── */
$autoDetectMap = [
    'start_date'     => ['start date', 'start_date', 'startdate', 'from', 'date from', 'date start'],
    'end_date'       => ['end date', 'end_date', 'enddate', 'to', 'date to', 'date end'],
    'event_day'      => ['event day', 'event_day', 'day', 'day no', 'attendance day'],
    'rank'           => ['rank', 'mil rank', 'military rank'],
    'first_name'     => ['first name', 'firstname', 'given name', 'fname', 'first'],
    'last_name'      => ['last name', 'lastname', 'surname', 'lname', 'family name', 'last', 'lastname'],
    'middle_name'    => ['middle name', 'middlename', 'middle'],
    'middle_initial' => ['mi', 'middle initial', 'm.i.', 'middle initital', 'middle initial'],
    'ext_name'       => ['ext', 'ext name', 'suffix', 'jr', 'sr', 'extension', 'ext. name (jr/sr)'],
    'major_service'  => ['major service', 'major_service', 'majorservice', 'service', 'branch of service', 'armed service'],
    'unit_office'    => ['unit', 'office', 'unit/office', 'unit / office', 'unit office', 'command', 'branch'],
    'designation'    => ['designation', 'position', 'title', 'job title', 'role'],
    'serial_number'  => ['serial', 'serial no', 'serial number', 'serial no.', 'sn', 'service number'],
    'email'          => ['email', 'e-mail', 'email address', 'e-mail address', 'mail'],
    'contact_number' => ['contact', 'contact no', 'contact number', 'phone', 'mobile', 'tel', 'telephone', 'cell'],
];

// Auto-detect only when no mapping was explicitly sent (all still -1)
$needsAutoDetect = (array_sum(array_values($colMap)) === (-1 * count($colMap)));
if ($needsAutoDetect && $skipHeader) {
    foreach ($headerRow as $idx => $header) {
        $h = strtolower(trim($header));
        foreach ($autoDetectMap as $field => $keywords) {
            if ($colMap[$field] === -1 && in_array($h, $keywords)) {
                $colMap[$field] = $idx;
                break;
            }
        }
    }
}

/* ─────────────────────────────────────────────
   PREVIEW MODE
───────────────────────────────────────────── */
if ($mode === 'preview') {
    echo json_encode([
        'success'    => true,
        'event'      => $event,
        'total_rows' => count($dataRows),
        'headers'    => $headerRow,
        'preview'    => array_slice($dataRows, 0, 8),
        'col_map'    => $colMap,
        'col_count'  => count($headerRow),
    ]);
    exit;
}

/* ─────────────────────────────────────────────
   IMPORT MODE
───────────────────────────────────────────── */
$inserted = 0;
$skipped  = 0;
$errors   = [];

$insertSql = "
    INSERT INTO event_registrations
        (agenda, start_date, end_date, event_day,
         rank, first_name, last_name,
         middle_name, middle_initial, ext_name,
         major_service, unit_office, designation,
         serial_number, email, contact_number, created_at)
    VALUES (?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, NOW())
";
$insertStmt = $pdo->prepare($insertSql);

$pdo->beginTransaction();
try {
    foreach ($dataRows as $lineNum => $row) {

        // Skip blank rows
        if (count(array_filter(array_map('trim', $row))) === 0) {
            $skipped++;
            continue;
        }

        $firstName = getCol($row, $colMap['first_name']);
        $lastName  = getCol($row, $colMap['last_name']);

        // Must have at least a first or last name
        if ($firstName === '' && $lastName === '') {
            $skipped++;
            $realLine = $lineNum + ($skipHeader ? 2 : 1);
            $errors[] = "Row {$realLine}: skipped — no name found.";
            continue;
        }

        // Pull raw date strings from the mapped columns
        $rowStartDate = getCol($row, $colMap['start_date']);
        $rowEndDate   = getCol($row, $colMap['end_date']);
        $rowEventDay  = getCol($row, $colMap['event_day']);

        // parseDateSafe() handles all common formats and never returns 1970-01-01
        $resolvedStart = parseDateSafe($rowStartDate) ?: ($eventDayDate ?: $event['start_date']);
        $resolvedEnd   = parseDateSafe($rowEndDate)   ?: $event['end_date'];
        $resolvedDay   = $rowEventDay !== ''
            ? str_pad($rowEventDay, 2, '0', STR_PAD_LEFT)
            : $eventDay;

        $insertStmt->execute([
            $event['agenda'],
            $resolvedStart,
            $resolvedEnd,
            $resolvedDay,
            getCol($row, $colMap['rank']),
            $firstName,
            $lastName,
            getCol($row, $colMap['middle_name']),
            getCol($row, $colMap['middle_initial']),
            getCol($row, $colMap['ext_name']),
            getCol($row, $colMap['major_service']),
            getCol($row, $colMap['unit_office']),
            getCol($row, $colMap['designation']),
            getCol($row, $colMap['serial_number']),
            getCol($row, $colMap['email']),
            getCol($row, $colMap['contact_number']),
        ]);
        $inserted++;
    }
    $pdo->commit();
} catch (\Exception $e) {
    $pdo->rollBack();
    jsonErr('Import failed: ' . $e->getMessage(), 500);
}

echo json_encode([
    'success'  => true,
    'inserted' => $inserted,
    'skipped'  => $skipped,
    'errors'   => array_slice($errors, 0, 20),
    'message'  => "{$inserted} record" . ($inserted !== 1 ? 's' : '') . " imported successfully."
        . ($skipped ? " {$skipped} row(s) skipped." : ''),
]);