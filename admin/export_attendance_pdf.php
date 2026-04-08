<?php
require '../config/db.php';
require 'fpdf/fpdf.php';

/* =========================
   HELPERS
========================= */
function nbLines($pdf, $w, $txt)
{
    if (trim($txt) === '') return 1;
    $w   -= 2;
    $words = explode(' ', str_replace("\n", ' ', trim($txt)));
    $lines = 1;
    $lineW = 0;
    foreach ($words as $word) {
        if ($word === '') continue;
        $wordW = $pdf->GetStringWidth($word . ' ');
        if ($lineW + $wordW > $w && $lineW > 0) {
            $lines++;
            $lineW = $wordW;
        } else {
            $lineW += $wordW;
        }
    }
    return $lines;
}

function drawTableHeader($pdf, $COLS)
{
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(30, 58, 138);
    $pdf->SetTextColor(255, 255, 255);
    foreach ($COLS as $col) {
        $pdf->Cell($col['w'], 8, $col['h'], 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetTextColor(0, 0, 0);
}

function checkPageBreak($pdf, $h, $COLS)
{
    if ($pdf->GetY() + $h > ($pdf->GetPageHeight() - 18)) {
        $pdf->AddPage();
        drawTableHeader($pdf, $COLS);
    }
}

/* =========================
   COLUMNS
========================= */
$COLS = [
    ['w' => 10,  'h' => '#',             'a' => 'C', 'k' => null],
    ['w' => 40,  'h' => 'Unit / Office', 'a' => 'L', 'k' => 'unit_office'],
    ['w' => 58,  'h' => 'Name',          'a' => 'L', 'k' => 'fullname'],
    ['w' => 25,  'h' => 'Serial No.',    'a' => 'C', 'k' => 'serial_number'],
    ['w' => 40,  'h' => 'Designation',   'a' => 'L', 'k' => 'designation'],
    ['w' => 55,  'h' => 'Email Address', 'a' => 'L', 'k' => 'email'],
    ['w' => 30,  'h' => 'Contact No.',   'a' => 'C', 'k' => 'contact_number'],
    ['w' => 19,  'h' => 'Signature',     'a' => 'C', 'k' => null],
];

/* =========================
   INPUT
========================= */
$agenda    = trim($_GET['agenda']    ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$venue     = trim($_GET['venue']     ?? '');
$eventId   = (int)($_GET['event_id'] ?? 0);

// Fall back to looking up by event_id if agenda not passed directly
if ($agenda === '' && $eventId > 0) {
    $r = $pdo->prepare("SELECT agenda, start_date, end_date, venue FROM event_settings WHERE id = ?");
    $r->execute([$eventId]);
    if ($ev = $r->fetch(PDO::FETCH_ASSOC)) {
        $agenda    = $ev['agenda'];
        $date_from = $date_from ?: $ev['start_date'];
        $date_to   = $date_to   ?: $ev['end_date'];
        $venue     = ($venue !== '') ? $venue : ($ev['venue'] ?: 'N/A');
    }
}

if ($venue === '') $venue = 'N/A';
if (!$agenda) die('Missing agenda parameter.');

/* =========================
   FETCH — active records only
   Checks if the active column exists first to avoid
   crashing on databases that haven't run the migration yet.
========================= */
$existingCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM event_registrations")->fetchAll(PDO::FETCH_ASSOC) as $col) {
    $existingCols[] = $col['Field'];
}
$hasActive = in_array('active', $existingCols);

// Only filter by active=1 if the column exists
$activeFilter = $hasActive ? "AND (active IS NULL OR active = 1)" : "";

$dateFilter = '';
$params     = [trim($agenda)];

if ($date_from && $date_to) {
    $dateFilter = "AND DATE(start_date) BETWEEN ? AND ?";
    $params[]   = $date_from;
    $params[]   = $date_to;
} elseif ($date_from) {
    $dateFilter = "AND DATE(start_date) >= ?";
    $params[]   = $date_from;
} elseif ($date_to) {
    $dateFilter = "AND DATE(start_date) <= ?";
    $params[]   = $date_to;
}

$stmt = $pdo->prepare("
    SELECT
        unit_office,
        CONCAT(
            COALESCE(TRIM(rank),''), ' ',
            COALESCE(TRIM(first_name),''), ' ',
            COALESCE(TRIM(last_name),''),
            COALESCE(CONCAT(', ', NULLIF(TRIM(middle_initial),'')), ''),
            COALESCE(CONCAT(' ',  NULLIF(TRIM(ext_name),'')),'')
        ) AS fullname,
        serial_number,
        designation,
        email,
        contact_number
    FROM event_registrations
    WHERE TRIM(agenda) = ?
    {$dateFilter}
    {$activeFilter}
    ORDER BY last_name ASC, first_name ASC
");
$stmt->execute($params);
$attendees = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   PDF SETUP
========================= */
$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 18);
$pdf->SetMargins(12, 10, 12);
$pdf->AddPage();

/* =========================
   LETTERHEAD
========================= */
if (file_exists('assets/img/bagong_pilipinas.png'))
    $pdf->Image('assets/img/bagong_pilipinas.png', 12, 8, 22);
if (file_exists('assets/img/pn_seal.png'))
    $pdf->Image('assets/img/pn_seal.png', 263, 8, 22);

$pdf->SetY(9);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 6, 'PUNONGHIMPILAN HUKBONG DAGAT NG PILIPINAS', 0, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 5, '(Headquarters Philippine Navy)', 0, 1, 'C');
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 5, 'Office of the AC of NS for Naval Systems Engineering, N11', 0, 1, 'C');
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(0, 4, 'Naval Station Jose Andrada, 2335 Roxas Boulevard, Manila', 0, 1, 'C');

$pdf->Ln(5);

/* =========================
   INFO BOX
========================= */
$totalW = array_sum(array_column($COLS, 'w'));
$labelW = 22;
$valueW = $totalW - $labelW;

$infoRows = [
    ['AGENDA', $agenda],
    ['DATE',   ($date_from && $date_to) ? "$date_from  to  $date_to" : 'All Dates'],
    ['VENUE',  $venue],
    ['TOTAL',  count($attendees) . ' registrant' . (count($attendees) !== 1 ? 's' : '')],
];

$pdf->SetX(12);
foreach ($infoRows as [$label, $value]) {
    $pdf->SetX(12);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell($labelW, 6, $label . ':', 0, 0, 'R', false);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell($valueW, 6, '  ' . $value, 0, 1, 'L', false);
}

$pdf->Ln(4);

/* =========================
   TABLE HEADER
========================= */
$pdf->SetX(12);
drawTableHeader($pdf, $COLS);

/* =========================
   TABLE ROWS
========================= */
$pdf->SetFont('Arial', '', 8);
$lineH   = 5;
$rowNum  = 1;
$fillRow = false;
$startX  = 12;

if (empty($attendees)) {
    $pdf->SetX($startX);
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell($totalW, 10, 'No active registration records found for this event.', 1, 1, 'C');
} else {
    foreach ($attendees as $row) {

        $maxLines = 1;
        foreach ($COLS as $col) {
            if ($col['k'] === null) continue;
            $n = nbLines($pdf, $col['w'], $row[$col['k']] ?? '');
            if ($n > $maxLines) $maxLines = $n;
        }
        $rowH = $maxLines * $lineH;

        checkPageBreak($pdf, $rowH, $COLS);

        $rowY = $pdf->GetY();
        $curX = $startX;

        if ($fillRow) {
            $pdf->SetFillColor(245, 246, 252);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }

        foreach ($COLS as $col) {
            $pdf->SetXY($curX, $rowY);

            if ($col['k'] === null) {
                $label = ($col['h'] === '#') ? $rowNum : '';
                $pdf->Cell($col['w'], $rowH, $label, 1, 0, 'C', $fillRow);

            } elseif ($maxLines === 1) {
                $pdf->Cell($col['w'], $rowH, $row[$col['k']] ?? '', 1, 0, $col['a'], $fillRow);

            } else {
                $cellY    = $rowY;
                $cellText = $row[$col['k']] ?? '';
                $pdf->MultiCell($col['w'], $lineH, $cellText, 0, $col['a'], $fillRow);
                $usedH = $pdf->GetY() - $cellY;

                $pdf->Rect($curX, $cellY, $col['w'], $rowH);

                if ($usedH < $rowH) {
                    $pdf->SetXY($curX, $cellY + $usedH);
                    $pdf->Cell($col['w'], $rowH - $usedH, '', 0, 0, 'L', $fillRow);
                }
            }

            $curX += $col['w'];
        }

        $pdf->SetXY($startX, $rowY + $rowH);
        $rowNum++;
        $fillRow = !$fillRow;
    }
}

/* =========================
   FOOTER
========================= */
$pdf->Ln(5);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(130, 130, 130);
$pdf->Cell(0, 5, 'Generated: ' . date('F d, Y  h:i A') . '   |   Nexus Platform', 0, 1, 'R');

/* =========================
   OUTPUT
========================= */
$safeAgenda = preg_replace('/[^A-Za-z0-9_\-]/', '_', $agenda);
$pdf->Output('D', "Attendance_{$safeAgenda}_{$date_from}_{$date_to}.pdf");