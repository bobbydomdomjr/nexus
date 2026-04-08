<?php
/**
 * export_attendance_excel.php
 * Produces an .xlsx attendance sheet — active registrations only.
 */

require '../config/db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$autoload       = __DIR__ . '/../vendor/autoload.php';
$hasSpreadsheet = false;
if (file_exists($autoload)) {
    require $autoload;
    $hasSpreadsheet = class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet');
}

/* ── INPUT ── */
$agenda    = trim($_GET['agenda']    ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$venue     = trim($_GET['venue']     ?? 'N/A');
$eventId   = (int)($_GET['event_id'] ?? 0);

if ($agenda === '' && $eventId > 0) {
    $r = $pdo->prepare("SELECT agenda, start_date, end_date, venue FROM event_settings WHERE id=?");
    $r->execute([$eventId]);
    if ($ev = $r->fetch(PDO::FETCH_ASSOC)) {
        $agenda    = $ev['agenda'];
        $date_from = $date_from ?: $ev['start_date'];
        $date_to   = $date_to   ?: $ev['end_date'];
        $venue     = ($venue !== 'N/A') ? $venue : ($ev['venue'] ?: 'N/A');
    }
}
if ($agenda === '') { http_response_code(400); die('Missing agenda parameter.'); }

/* ── DETECT OPTIONAL COLUMNS ── */
$existingCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM event_registrations")->fetchAll(PDO::FETCH_ASSOC) as $col) {
    $existingCols[] = $col['Field'];
}
$hasActive = in_array('active', $existingCols);

// Only active records (NULL treated as active for pre-migration rows)
$activeFilter = $hasActive ? "AND (active IS NULL OR active = 1)" : "";

/* ── FETCH — active only ── */
$sql = "
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
    {$activeFilter}
";
$params = [trim($agenda)];

if ($date_from !== '' && $date_to !== '') {
    $sql .= " AND DATE(start_date) BETWEEN ? AND ?";
    $params[] = $date_from; $params[] = $date_to;
} elseif ($date_from !== '') {
    $sql .= " AND DATE(start_date) >= ?"; $params[] = $date_from;
} elseif ($date_to !== '') {
    $sql .= " AND DATE(start_date) <= ?"; $params[] = $date_to;
}
$sql .= " ORDER BY last_name ASC, first_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$attendees = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total     = count($attendees);

/* ── HELPERS ── */
function colLtr(int $n): string {
    $s = '';
    while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); }
    return $s;
}

$safeAgenda  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $agenda);
$dateDisplay = ($date_from && $date_to)
    ? "$date_from  to  $date_to"
    : ($date_from ?: ($date_to ?: 'All Dates'));

/* ═══════════════════════════════════════════
   PATH A — PhpSpreadsheet (.xlsx)
═══════════════════════════════════════════ */
if ($hasSpreadsheet) {

    $COLS = [
        ['#',             5,   null],
        ['Unit / Office', 22,  'unit_office'],
        ['Name',          30,  'fullname'],
        ['Serial No.',    13,  'serial_number'],
        ['Designation',   22,  'designation'],
        ['Email Address', 30,  'email'],
        ['Contact No.',   16,  'contact_number'],
        ['Signature',     18,  null],
    ];
    $numCols       = count($COLS);
    $lastColLetter = colLtr($numCols);

    $NAVY     = '1E3A8A';
    $ALT_ROW  = 'F5F6FC';
    $BDR      = 'CBD5E1';
    $LBL_CLR  = '334155';
    $VAL_CLR  = '1E293B';
    $FTR_CLR  = '94A3B8';
    $TTL_CLR  = '1E293B';

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Attendance');

    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
        ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageMargins()
        ->setTop(0.4)->setBottom(0.4)->setLeft(0.4)->setRight(0.4)
        ->setHeader(0.2)->setFooter(0.2);

    $r = 1;

// Logos
$r = 1;

    // Letterhead — taller rows so logos have room
    $lhRows = [
        [14, true,  'PUNONGHIMPILAN HUKBONG DAGAT NG PILIPINAS',               20],
        [10, false, '(Headquarters Philippine Navy)',                            16],
        [10, true,  'Office of the AC of NS for Naval Systems Engineering, N11', 16],
        [9,  false, 'Naval Station Jose Andrada, 2335 Roxas Boulevard, Manila',  14],
    ];
    foreach ($lhRows as [$sz, $bold, $txt, $rowH]) {
        $cell = "A{$r}";
        $sheet->mergeCells("{$cell}:{$lastColLetter}{$r}");
        $sheet->setCellValue($cell, $txt);
        $sheet->getStyle($cell)->getFont()->setName('Arial')->setSize($sz)->setBold($bold)
              ->getColor()->setARGB('FF' . $TTL_CLR);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($r)->setRowHeight($rowH);
        $r++;
    }
    $r++;

    // Logos — placed AFTER rows are built so coordinates are stable
    $logoBagong = 'assets/img/bagong_pilipinas.png';
    $logoPN     = 'assets/img/pn_seal.png';

    if (file_exists($logoBagong)) {
        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $drawing->setPath($logoBagong);
        $drawing->setHeight(54);
        $drawing->setCoordinates('A1');
        $drawing->setOffsetX(6);
        $drawing->setOffsetY(6);
        $drawing->setWorksheet($sheet);
    }
    if (file_exists($logoPN)) {
        $drawing2 = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $drawing2->setPath($logoPN);
        $drawing2->setHeight(54);
        // Anchor to second-to-last column so it floats near the right edge
        $drawing2->setCoordinates(colLtr($numCols - 1) . '1');
        $drawing2->setOffsetX(8);
        $drawing2->setOffsetY(6);
        $drawing2->setWorksheet($sheet);
    }

    // Info block
    foreach ([
        ['AGENDA', $agenda],
        ['DATE',   $dateDisplay],
        ['VENUE',  $venue],
        ['TOTAL',  $total . ' registrant' . ($total !== 1 ? 's' : '') . ' (active only)'],
    ] as [$lbl, $val]) {
        $lc = "A{$r}";
        $sheet->mergeCells("{$lc}:B{$r}");
        $sheet->setCellValue($lc, $lbl . ':');
        $sheet->getStyle($lc)->getFont()->setName('Arial')->setSize(9)->setBold(true)
              ->getColor()->setARGB('FF' . $LBL_CLR);
        $sheet->getStyle($lc)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $vc = "C{$r}";
        $sheet->mergeCells("{$vc}:{$lastColLetter}{$r}");
        $sheet->setCellValue($vc, '  ' . $val);
        $sheet->getStyle($vc)->getFont()->setName('Arial')->setSize(9)
              ->getColor()->setARGB('FF' . $VAL_CLR);
        $sheet->getStyle($vc)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getRowDimension($r)->setRowHeight(15);
        $r++;
    }
    $r++;

    // Header row
    $headerRow = $r;
    foreach ($COLS as $ci => [$lbl, $w, $key]) {
        $cl   = colLtr($ci + 1);
        $cell = "{$cl}{$r}";
        $sheet->setCellValue($cell, $lbl);
        $sheet->getColumnDimension($cl)->setWidth($w);
        $sheet->getStyle($cell)->applyFromArray([
            'font'      => ['name'=>'Arial','size'=>9,'bold'=>true,'color'=>['argb'=>'FFFFFFFF']],
            'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF'.$NAVY]],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF'.$BDR]]],
        ]);
    }
    $sheet->getRowDimension($r)->setRowHeight(18);
    $r++;

    // Data rows
    if (empty($attendees)) {
        $cell = "A{$r}";
        $sheet->mergeCells("{$cell}:{$lastColLetter}{$r}");
        $sheet->setCellValue($cell, 'No active registration records found for this event.');
        $sheet->getStyle($cell)->applyFromArray([
            'font'      => ['name'=>'Arial','size'=>9,'italic'=>true,'color'=>['argb'=>'FF94A3B8']],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF'.$BDR]]],
        ]);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r++;
    } else {
        $rowNum = 1; $alt = false;
        foreach ($attendees as $aRow) {
            $fill = $alt ? $ALT_ROW : 'FFFFFF';
            foreach ($COLS as $ci => [$lbl, $w, $key]) {
                $cl   = colLtr($ci + 1);
                $cell = "{$cl}{$r}";
                if ($key === null) {
                    $sheet->setCellValue($cell, $lbl === '#' ? $rowNum : '');
                    $ha = Alignment::HORIZONTAL_CENTER;
                } else {
                    $sheet->setCellValue($cell, $aRow[$key] ?? '');
                    $ha = in_array($key, ['serial_number','contact_number'])
                        ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_LEFT;
                }
                $sheet->getStyle($cell)->applyFromArray([
                    'font'      => ['name'=>'Arial','size'=>9],
                    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF'.$fill]],
                    'alignment' => ['horizontal'=>$ha,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
                    'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF'.$BDR]]],
                ]);
            }
            $sheet->getRowDimension($r)->setRowHeight(-1);
            $r++; $rowNum++; $alt = !$alt;
        }
    }
    $r++;

    // Footer
    $fc = "A{$r}";
    $sheet->mergeCells("{$fc}:{$lastColLetter}{$r}");
    $sheet->setCellValue($fc, 'Generated: ' . date('F d, Y  h:i A') . '   |   Nexus Platform');
    $sheet->getStyle($fc)->applyFromArray([
        'font'      => ['name'=>'Arial','size'=>8,'italic'=>true,'color'=>['argb'=>'FF'.$FTR_CLR]],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_RIGHT],
    ]);
    $sheet->getRowDimension($r)->setRowHeight(12);
    $sheet->freezePane('A' . ($headerRow + 1));

    $filename = "Attendance_{$safeAgenda}_{$date_from}_{$date_to}.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache'); header('Expires: 0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

/* ═══════════════════════════════════════════
   PATH B — Pure-PHP SpreadsheetML fallback (.xls)
═══════════════════════════════════════════ */
$filename = "Attendance_{$safeAgenda}_{$date_from}_{$date_to}.xls";
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

function xe(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function cell(string $style, string $val, int $merge = 0): string {
    $m = $merge > 0 ? ' ss:MergeAcross="' . $merge . '"' : '';
    return '<Cell ss:StyleID="' . $style . '"' . $m . '>'
         . '<Data ss:Type="String">' . xe($val) . '</Data></Cell>';
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:x="urn:schemas-microsoft-com:office:excel">

<Styles>
  <Style ss:ID="sDefault"/>
  <Style ss:ID="sTitle">
    <Font ss:Bold="1" ss:Size="14" ss:Color="#1E293B" ss:FontName="Arial"/>
    <Alignment ss:Horizontal="Center"/>
  </Style>
  <Style ss:ID="sSub">
    <Font ss:Size="10" ss:Color="#1E293B" ss:FontName="Arial"/>
    <Alignment ss:Horizontal="Center"/>
  </Style>
  <Style ss:ID="sSubB">
    <Font ss:Bold="1" ss:Size="10" ss:Color="#1E293B" ss:FontName="Arial"/>
    <Alignment ss:Horizontal="Center"/>
  </Style>
  <Style ss:ID="sLbl">
    <Font ss:Bold="1" ss:Size="9" ss:Color="#334155" ss:FontName="Arial"/>
    <Alignment ss:Horizontal="Right"/>
  </Style>
  <Style ss:ID="sVal">
    <Font ss:Size="9" ss:Color="#1E293B" ss:FontName="Arial"/>
    <Alignment ss:Horizontal="Left"/>
  </Style>
  <Style ss:ID="sHdr">
    <Font ss:Bold="1" ss:Size="9" ss:Color="#FFFFFF" ss:FontName="Arial"/>
    <Interior ss:Color="#1E3A8A" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
    </Borders>
  </Style>
  <Style ss:ID="sDL">
    <Font ss:Size="9" ss:FontName="Arial"/>
    <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
    </Borders>
  </Style>
  <Style ss:ID="sDC">
    <Font ss:Size="9" ss:FontName="Arial"/>
    <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
    </Borders>
  </Style>
  <Style ss:ID="sDLA">
    <Font ss:Size="9" ss:FontName="Arial"/>
    <Interior ss:Color="#F5F6FC" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
    </Borders>
  </Style>
  <Style ss:ID="sDCA">
    <Font ss:Size="9" ss:FontName="Arial"/>
    <Interior ss:Color="#F5F6FC" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
      <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
    </Borders>
  </Style>
  <Style ss:ID="sFooter">
    <Font ss:Italic="1" ss:Size="8" ss:Color="#94A3B8" ss:FontName="Arial"/>
    <Alignment ss:Horizontal="Right"/>
  </Style>
</Styles>

<Worksheet ss:Name="Attendance">
<Table ss:DefaultColumnWidth="60">
  <Column ss:Index="1" ss:Width="30"/>
  <Column ss:Index="2" ss:Width="110"/>
  <Column ss:Index="3" ss:Width="150"/>
  <Column ss:Index="4" ss:Width="75"/>
  <Column ss:Index="5" ss:Width="110"/>
  <Column ss:Index="6" ss:Width="165"/>
  <Column ss:Index="7" ss:Width="90"/>
  <Column ss:Index="8" ss:Width="90"/>

<?php
// Letterhead
echo '<Row ss:Height="18">' . cell('sTitle', 'PUNONGHIMPILAN HUKBONG DAGAT NG PILIPINAS', 7) . '</Row>' . "\n";
echo '<Row ss:Height="14">' . cell('sSub',   '(Headquarters Philippine Navy)', 7) . '</Row>' . "\n";
echo '<Row ss:Height="14">' . cell('sSubB',  'Office of the AC of NS for Naval Systems Engineering, N11', 7) . '</Row>' . "\n";
echo '<Row ss:Height="13">' . cell('sSub',   'Naval Station Jose Andrada, 2335 Roxas Boulevard, Manila', 7) . '</Row>' . "\n";
echo '<Row ss:Height="6"></Row>' . "\n";

// Info block — note "active only" in TOTAL
foreach ([
    ['AGENDA:', $agenda],
    ['DATE:',   $dateDisplay],
    ['VENUE:',  $venue],
    ['TOTAL:',  $total . ' registrant' . ($total !== 1 ? 's' : '') . ' (active only)'],
] as [$l, $v]) {
    echo '<Row ss:Height="15">';
    echo cell('sLbl', $l, 1);
    echo cell('sVal', '  ' . $v, 5);
    echo '</Row>' . "\n";
}
echo '<Row ss:Height="6"></Row>' . "\n";

// Header
echo '<Row ss:Height="18">';
foreach (['#','Unit / Office','Name','Serial No.','Designation','Email Address','Contact No.','Signature'] as $h) {
    echo cell('sHdr', $h);
}
echo '</Row>' . "\n";

// Data rows
if (empty($attendees)) {
    echo '<Row ss:Height="18">' . cell('sDC', 'No active registration records found for this event.', 7) . '</Row>' . "\n";
} else {
    $alt = false; $rn = 1;
    foreach ($attendees as $a) {
        $sL = $alt ? 'sDLA' : 'sDL';
        $sC = $alt ? 'sDCA' : 'sDC';
        echo '<Row ss:Height="16">';
        echo cell($sC, (string)$rn);
        echo cell($sL, $a['unit_office']    ?? '');
        echo cell($sL, trim($a['fullname']  ?? ''));
        echo cell($sC, $a['serial_number']  ?? '');
        echo cell($sL, $a['designation']    ?? '');
        echo cell($sL, $a['email']          ?? '');
        echo cell($sC, $a['contact_number'] ?? '');
        echo cell($sC, '');
        echo '</Row>' . "\n";
        $rn++; $alt = !$alt;
    }
}

// Footer
echo '<Row ss:Height="6"></Row>' . "\n";
echo '<Row ss:Height="12">' . cell('sFooter', 'Generated: ' . date('F d, Y  h:i A') . '   |   Nexus Platform', 7) . '</Row>' . "\n";
?>

</Table>
<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
  <PageSetup>
    <Layout x:Orientation="Landscape"/>
    <PageMargins x:Bottom="0.4" x:Left="0.4" x:Right="0.4" x:Top="0.4"/>
  </PageSetup>
  <FitToPage/>
  <Print><FitHeight>0</FitHeight></Print>
</WorksheetOptions>
</Worksheet>
</Workbook>