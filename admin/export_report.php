<?php
/**
 * export_report.php
 * Downloads a report_queue entry as PDF / Excel / CSV.
 * Active registrations only.
 */

session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

require '../config/db.php';
require 'fpdf/fpdf.php';

$autoload       = __DIR__ . '/../vendor/autoload.php';
$hasSpreadsheet = false;
if (file_exists($autoload)) {
    require $autoload;
    $hasSpreadsheet = class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet');
}

/* ── LOAD REPORT QUEUE RECORD ── */
$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Invalid report ID.'); }

$rqStmt = $pdo->prepare("SELECT * FROM report_queue WHERE id = ? LIMIT 1");
$rqStmt->execute([$id]);
$rq = $rqStmt->fetch(PDO::FETCH_ASSOC);

if (!$rq)                                  { http_response_code(404); exit('Report not found.'); }
if (strtolower($rq['status']) !== 'ready') { http_response_code(403); exit('Report is not ready for download.'); }

/* ── REPORT META ── */
$reportName  = $rq['report_name'];
$reportType  = $rq['report_type'];
$eventAgenda = $rq['event_agenda'];
$dateFrom    = $rq['date_from'];
$dateTo      = $rq['date_to'];
$requestedBy = $rq['requested_by'] ?: 'admin';
$fmt         = strtolower($rq['output_format']);

/* ── DETECT OPTIONAL COLUMNS ── */
$existingCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM event_registrations")->fetchAll(PDO::FETCH_ASSOC) as $col) {
    $existingCols[] = $col['Field'];
}
$hasActive    = in_array('active', $existingCols);
// NULL treated as active for rows imported before migration
$activeFilter = $hasActive ? "AND (active IS NULL OR active = 1)" : "";

/* ── FETCH — active only ── */
$stmt = $pdo->prepare("
    SELECT
        unit_office,
        CONCAT(
            COALESCE(TRIM(rank),''),' ',
            COALESCE(TRIM(first_name),''),' ',
            COALESCE(TRIM(last_name),''),
            COALESCE(CONCAT(', ', NULLIF(TRIM(middle_initial),'')), ''),
            COALESCE(CONCAT(' ',  NULLIF(TRIM(ext_name),'')),'')
        ) AS fullname,
        serial_number,
        designation,
        email,
        contact_number,
        created_at
    FROM event_registrations
    WHERE TRIM(agenda) = ?
      AND DATE(start_date) BETWEEN ? AND ?
      {$activeFilter}
    ORDER BY last_name ASC, first_name ASC
");
$stmt->execute([trim($eventAgenda), $dateFrom, $dateTo]);
$attendees  = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalCount = count($attendees);

/* ── SHARED ── */
$safeTitle   = preg_replace('/[^A-Za-z0-9_\-]/', '_', $reportName);
$filename    = $safeTitle . '_' . date('Ymd');
$dateDisplay = ($dateFrom && $dateTo) ? "$dateFrom  to  $dateTo" : 'All Dates';

$csvHeaders = ['#','Unit / Office','Name','Serial No.','Designation','Email Address','Contact No.','Registered On'];

function flatRow(int $n, array $row): array {
    return [
        $n,
        $row['unit_office']    ?? '',
        trim($row['fullname']  ?? ''),
        $row['serial_number']  ?? '',
        $row['designation']    ?? '',
        $row['email']          ?? '',
        $row['contact_number'] ?? '',
        isset($row['created_at']) ? date('Y-m-d H:i', strtotime($row['created_at'])) : '',
    ];
}

function colLtr(int $n): string {
    $s = '';
    while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); }
    return $s;
}

/* ═══════════════════════════════════════════
   CSV
═══════════════════════════════════════════ */
if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Pragma: no-cache'); header('Expires: 0');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Report Name:',   $reportName]);
    fputcsv($out, ['Report Type:',   $reportType]);
    fputcsv($out, ['Event/Agenda:',  $eventAgenda]);
    fputcsv($out, ['Date Range:',    $dateFrom . ' to ' . $dateTo]);
    fputcsv($out, ['Requested By:',  $requestedBy]);
    fputcsv($out, ['Generated:',     date('F d, Y  h:i A')]);
    fputcsv($out, ['Total Records:', $totalCount . ' (active only)']);
    fputcsv($out, []);
    fputcsv($out, $csvHeaders);
    foreach ($attendees as $i => $row) fputcsv($out, flatRow($i + 1, $row));
    fclose($out);
    exit;
}

/* ═══════════════════════════════════════════
   EXCEL
═══════════════════════════════════════════ */
if ($fmt === 'excel') {

    /* ── PATH A: PhpSpreadsheet ── */
    if ($hasSpreadsheet) {
        $ss    = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Report');

        $AL = \PhpOffice\PhpSpreadsheet\Style\Alignment::class;
        $BR = \PhpOffice\PhpSpreadsheet\Style\Border::class;
        $FL = \PhpOffice\PhpSpreadsheet\Style\Fill::class;

        $numCols = count($csvHeaders);
        $lastCol = colLtr($numCols);
        $r = 1;

        $lh = function(int $r, string $txt, int $sz, bool $bold) use ($sheet, $lastCol, $AL) {
            $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
            $sheet->setCellValue("A{$r}", $txt);
            $sheet->getStyle("A{$r}")->applyFromArray([
                'font'      => ['name'=>'Arial','size'=>$sz,'bold'=>$bold,'color'=>['rgb'=>'1E3A8A']],
                'alignment' => ['horizontal'=> constant("{$AL}::HORIZONTAL_CENTER")],
            ]);
            $sheet->getRowDimension($r)->setRowHeight($sz + 4);
        };

        $lh($r++, 'PUNONGHIMPILAN HUKBONG DAGAT NG PILIPINAS',               13, true);
        $lh($r++, '(Headquarters Philippine Navy)',                            10, false);
        $lh($r++, 'Office of the AC of NS for Naval Systems Engineering, N11', 9, true);
        $lh($r++, 'Naval Station Jose Andrada, 2335 Roxas Boulevard, Manila',  8, false);
        $r++;

        foreach ([
            ['AGENDA',    $eventAgenda],
            ['DATE',      $dateDisplay],
            ['VENUE',     $rq['notes'] ?: 'N/A'],
            ['TYPE',      $reportType],
            ['REQUESTED', $requestedBy],
            ['TOTAL',     $totalCount . ' registrant' . ($totalCount !== 1 ? 's' : '') . ' (active only)'],
        ] as [$lbl, $val]) {
            $sheet->mergeCells("A{$r}:B{$r}");
            $sheet->setCellValue("A{$r}", $lbl . ':');
            $sheet->getStyle("A{$r}")->applyFromArray([
                'font'      => ['name'=>'Arial','size'=>9,'bold'=>true,'color'=>['rgb'=>'334155']],
                'alignment' => ['horizontal'=> constant("{$AL}::HORIZONTAL_RIGHT")],
            ]);
            $sheet->mergeCells("C{$r}:{$lastCol}{$r}");
            $sheet->setCellValue("C{$r}", '  ' . $val);
            $sheet->getStyle("C{$r}")->getFont()->setName('Arial')->setSize(9);
            $sheet->getRowDimension($r)->setRowHeight(15);
            $r++;
        }
        $r++;

        $hr = $r;
        foreach ($csvHeaders as $ci => $lbl) {
            $cl = colLtr($ci + 1);
            $sheet->setCellValue("{$cl}{$r}", $lbl);
            $sheet->getStyle("{$cl}{$r}")->applyFromArray([
                'font'      => ['name'=>'Arial','size'=>9,'bold'=>true,'color'=>['rgb'=>'FFFFFF']],
                'fill'      => ['fillType'=>constant("{$FL}::FILL_SOLID"),'startColor'=>['rgb'=>'1E3A8A']],
                'alignment' => ['horizontal'=>constant("{$AL}::HORIZONTAL_CENTER"),'vertical'=>constant("{$AL}::VERTICAL_CENTER")],
                'borders'   => ['allBorders'=>['borderStyle'=>constant("{$BR}::BORDER_THIN"),'color'=>['rgb'=>'CBD5E1']]],
            ]);
            $sheet->getColumnDimension($cl)->setAutoSize(true);
        }
        $sheet->getRowDimension($r)->setRowHeight(18);
        $r++;

        if (empty($attendees)) {
            $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
            $sheet->setCellValue("A{$r}", 'No active registration records found for this event and date range.');
            $sheet->getStyle("A{$r}")->applyFromArray([
                'font'      => ['name'=>'Arial','size'=>9,'italic'=>true,'color'=>['rgb'=>'94A3B8']],
                'alignment' => ['horizontal'=>constant("{$AL}::HORIZONTAL_CENTER")],
            ]);
            $sheet->getRowDimension($r)->setRowHeight(20);
            $r++;
        } else {
            foreach ($attendees as $i => $row) {
                $flat = flatRow($i + 1, $row);
                $fill = ($i % 2 === 1) ? 'F5F6FC' : 'FFFFFF';
                foreach ($flat as $ci => $val) {
                    $cl   = colLtr($ci + 1);
                    $cell = "{$cl}{$r}";
                    $sheet->setCellValue($cell, $val);
                    $sheet->getStyle($cell)->applyFromArray([
                        'font'      => ['name'=>'Arial','size'=>9],
                        'fill'      => ['fillType'=>constant("{$FL}::FILL_SOLID"),'startColor'=>['rgb'=>$fill]],
                        'borders'   => ['allBorders'=>['borderStyle'=>constant("{$BR}::BORDER_THIN"),'color'=>['rgb'=>'CBD5E1']]],
                    ]);
                }
                $sheet->getRowDimension($r)->setRowHeight(-1);
                $r++;
            }
        }

        $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
        $sheet->setCellValue("A{$r}", 'Generated: ' . date('F d, Y  h:i A') . '   |   Nexus Platform');
        $sheet->getStyle("A{$r}")->applyFromArray([
            'font'      => ['name'=>'Arial','size'=>8,'italic'=>true,'color'=>['rgb'=>'94A3B8']],
            'alignment' => ['horizontal'=>constant("{$AL}::HORIZONTAL_RIGHT")],
        ]);
        $sheet->freezePane('A' . ($hr + 1));

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
        exit;
    }

    /* ── PATH B: SpreadsheetML fallback ── */
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
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
  <Style ss:ID="sTitle"><Font ss:Bold="1" ss:Size="13" ss:Color="#1E3A8A" ss:FontName="Arial"/><Alignment ss:Horizontal="Center"/></Style>
  <Style ss:ID="sSub"><Font ss:Size="10" ss:Color="#1E3A8A" ss:FontName="Arial"/><Alignment ss:Horizontal="Center"/></Style>
  <Style ss:ID="sSubB"><Font ss:Bold="1" ss:Size="9" ss:Color="#1E3A8A" ss:FontName="Arial"/><Alignment ss:Horizontal="Center"/></Style>
  <Style ss:ID="sAddr"><Font ss:Size="8" ss:Color="#475569" ss:FontName="Arial"/><Alignment ss:Horizontal="Center"/></Style>
  <Style ss:ID="sLbl"><Font ss:Bold="1" ss:Size="9" ss:Color="#334155" ss:FontName="Arial"/><Alignment ss:Horizontal="Right"/></Style>
  <Style ss:ID="sVal"><Font ss:Size="9" ss:Color="#1E293B" ss:FontName="Arial"/><Alignment ss:Horizontal="Left"/></Style>
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
  <Style ss:ID="sDL"><Font ss:Size="9" ss:FontName="Arial"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders></Style>
  <Style ss:ID="sDC"><Font ss:Size="9" ss:FontName="Arial"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders></Style>
  <Style ss:ID="sDLA"><Font ss:Size="9" ss:FontName="Arial"/><Interior ss:Color="#F5F6FC" ss:Pattern="Solid"/><Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders></Style>
  <Style ss:ID="sDCA"><Font ss:Size="9" ss:FontName="Arial"/><Interior ss:Color="#F5F6FC" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders></Style>
  <Style ss:ID="sFooter"><Font ss:Italic="1" ss:Size="8" ss:Color="#94A3B8" ss:FontName="Arial"/><Alignment ss:Horizontal="Right"/></Style>
</Styles>
<Worksheet ss:Name="Report">
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
    $mc = count($csvHeaders) - 1;
    echo '<Row ss:Height="18">' . cell('sTitle', 'PUNONGHIMPILAN HUKBONG DAGAT NG PILIPINAS', $mc) . '</Row>' . "\n";
    echo '<Row ss:Height="14">' . cell('sSub',   '(Headquarters Philippine Navy)', $mc) . '</Row>' . "\n";
    echo '<Row ss:Height="14">' . cell('sSubB',  'Office of the AC of NS for Naval Systems Engineering, N11', $mc) . '</Row>' . "\n";
    echo '<Row ss:Height="13">' . cell('sAddr',  'Naval Station Jose Andrada, 2335 Roxas Boulevard, Manila', $mc) . '</Row>' . "\n";
    echo '<Row ss:Height="6"></Row>' . "\n";

    foreach ([
        ['AGENDA:',    $eventAgenda],
        ['DATE:',      $dateDisplay],
        ['VENUE:',     $rq['notes'] ?: 'N/A'],
        ['TYPE:',      $reportType],
        ['REQUESTED:', $requestedBy],
        ['TOTAL:',     $totalCount . ' registrant' . ($totalCount !== 1 ? 's' : '') . ' (active only)'],
    ] as [$l, $v]) {
        echo '<Row ss:Height="15">';
        echo cell('sLbl', $l, 1);
        echo cell('sVal', '  ' . $v, 5);
        echo '</Row>' . "\n";
    }
    echo '<Row ss:Height="6"></Row>' . "\n";

    echo '<Row ss:Height="18">';
    foreach ($csvHeaders as $h) echo cell('sHdr', $h);
    echo '</Row>' . "\n";

    if (empty($attendees)) {
        echo '<Row ss:Height="18">' . cell('sDC', 'No active records found for this event and date range.', $mc) . '</Row>' . "\n";
    } else {
        $alt = false; $rn = 1;
        foreach ($attendees as $a) {
            $flat   = flatRow($rn, $a);
            $sL     = $alt ? 'sDLA' : 'sDL';
            $sC     = $alt ? 'sDCA' : 'sDC';
            $styles = [$sC, $sL, $sL, $sC, $sL, $sL, $sC, $sC];
            echo '<Row ss:Height="16">';
            foreach ($flat as $ci => $val) echo cell($styles[$ci], (string)$val);
            echo '</Row>' . "\n";
            $rn++; $alt = !$alt;
        }
    }

    echo '<Row ss:Height="6"></Row>' . "\n";
    echo '<Row ss:Height="12">' . cell('sFooter', 'Generated: ' . date('F d, Y  h:i A') . '   |   Nexus Platform', $mc) . '</Row>' . "\n";
?>
</Table>
<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
  <PageSetup><Layout x:Orientation="Landscape"/><PageMargins x:Bottom="0.4" x:Left="0.4" x:Right="0.4" x:Top="0.4"/></PageSetup>
  <FitToPage/><Print><FitHeight>0</FitHeight></Print>
</WorksheetOptions>
</Worksheet>
</Workbook>
<?php
    exit;
}

/* ═══════════════════════════════════════════
   PDF (FPDF)
═══════════════════════════════════════════ */
function nbLines($pdf, $w, $txt) {
    if (trim($txt) === '') return 1;
    $w -= 2; $words = explode(' ', str_replace("\n",' ',trim($txt)));
    $lines = 1; $lineW = 0;
    foreach ($words as $word) {
        if ($word === '') continue;
        $ww = $pdf->GetStringWidth($word . ' ');
        if ($lineW + $ww > $w && $lineW > 0) { $lines++; $lineW = $ww; } else $lineW += $ww;
    }
    return $lines;
}
function drawTableHeader($pdf, $COLS) {
    $pdf->SetFont('Arial','B',8); $pdf->SetFillColor(30,58,138); $pdf->SetTextColor(255,255,255); $pdf->SetX(12);
    foreach ($COLS as $col) $pdf->Cell($col['w'],8,$col['h'],1,0,'C',true);
    $pdf->Ln(); $pdf->SetTextColor(0,0,0);
}
function checkPageBreak($pdf, $h, $COLS) {
    if ($pdf->GetY()+$h > ($pdf->GetPageHeight()-18)) { $pdf->AddPage(); drawTableHeader($pdf,$COLS); }
}

$COLS = [
    ['w'=>10, 'h'=>'#',             'a'=>'C','k'=>null],
    ['w'=>40, 'h'=>'Unit / Office', 'a'=>'L','k'=>'unit_office'],
    ['w'=>58, 'h'=>'Name',          'a'=>'L','k'=>'fullname'],
    ['w'=>25, 'h'=>'Serial No.',    'a'=>'C','k'=>'serial_number'],
    ['w'=>40, 'h'=>'Designation',   'a'=>'L','k'=>'designation'],
    ['w'=>55, 'h'=>'Email Address', 'a'=>'L','k'=>'email'],
    ['w'=>30, 'h'=>'Contact No.',   'a'=>'C','k'=>'contact_number'],
    ['w'=>19, 'h'=>'Signature',     'a'=>'C','k'=>null],
];
$totalW = array_sum(array_column($COLS,'w'));
$startX = 12; $lineH = 5;

$pdf = new FPDF('L','mm','A4');
$pdf->SetAutoPageBreak(true,18);
$pdf->SetMargins(12,10,12);
$pdf->AddPage();

if (file_exists('assets/img/bagong_pilipinas.png')) $pdf->Image('assets/img/bagong_pilipinas.png',12,8,22);
if (file_exists('assets/img/pn_seal.png'))          $pdf->Image('assets/img/pn_seal.png',263,8,22);

$pdf->SetY(9);
$pdf->SetFont('Arial','B',11); $pdf->Cell(0,6,'PUNONGHIMPILAN HUKBONG DAGAT NG PILIPINAS',0,1,'C');
$pdf->SetFont('Arial','',9);   $pdf->Cell(0,5,'(Headquarters Philippine Navy)',0,1,'C');
$pdf->SetFont('Arial','B',9);  $pdf->Cell(0,5,'Office of the AC of NS for Naval Systems Engineering, N11',0,1,'C');
$pdf->SetFont('Arial','',8);   $pdf->Cell(0,4,'Naval Station Jose Andrada, 2335 Roxas Boulevard, Manila',0,1,'C');
$pdf->Ln(3);

$pdf->SetDrawColor(30,58,138); $pdf->SetLineWidth(0.6);
$pdf->Line(12,$pdf->GetY(),285,$pdf->GetY());
$pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0,0,0); $pdf->Ln(2);

$pdf->SetFillColor(30,58,138); $pdf->SetTextColor(255,255,255); $pdf->SetFont('Arial','B',10); $pdf->SetX(12);
$pdf->Cell($totalW,7,strtoupper($reportName),0,1,'C',true);
$pdf->SetTextColor(0,0,0); $pdf->Ln(2);

$labelW = 24; $valueW = $totalW - $labelW;
foreach ([
    ['AGENDA',    $eventAgenda],
    ['DATE',      $dateDisplay],
    ['TYPE',      $reportType],
    ['REQUESTED', $requestedBy],
    ['TOTAL',     $totalCount . ' registrant' . ($totalCount !== 1 ? 's' : '') . ' (active only)'],
] as [$lbl,$val]) {
    $pdf->SetX(12);
    $pdf->SetFont('Arial','B',8); $pdf->Cell($labelW,6,$lbl.':',0,0,'R');
    $pdf->SetFont('Arial','',8);  $pdf->Cell($valueW,6,'  '.$val,0,1,'L');
}
$pdf->Ln(3);

$pdf->SetX(12);
drawTableHeader($pdf,$COLS);
$pdf->SetFont('Arial','',8);
$rowNum = 1; $fillRow = false;

if (empty($attendees)) {
    $pdf->SetX($startX); $pdf->SetFont('Arial','I',9);
    $pdf->Cell($totalW,10,'No active registration records found for this event and date range.',1,1,'C');
} else {
    foreach ($attendees as $row) {
        $maxLines = 1;
        foreach ($COLS as $col) {
            if ($col['k']===null) continue;
            $n = nbLines($pdf,$col['w'],$row[$col['k']]??'');
            if ($n>$maxLines) $maxLines=$n;
        }
        $rowH = $maxLines*$lineH;
        checkPageBreak($pdf,$rowH,$COLS);
        $rowY=$pdf->GetY(); $curX=$startX;
        $pdf->SetFillColor($fillRow?245:255,$fillRow?246:255,$fillRow?252:255);
        foreach ($COLS as $col) {
            $pdf->SetXY($curX,$rowY);
            if ($col['k']===null) {
                $pdf->Cell($col['w'],$rowH,$col['h']==='#'?$rowNum:'',1,0,'C',$fillRow);
            } elseif ($maxLines===1) {
                $pdf->Cell($col['w'],$rowH,$row[$col['k']]??'',1,0,$col['a'],$fillRow);
            } else {
                $cellY=$rowY; $cellText=$row[$col['k']]??'';
                $pdf->MultiCell($col['w'],$lineH,$cellText,0,$col['a'],$fillRow);
                $usedH=$pdf->GetY()-$cellY;
                $pdf->Rect($curX,$cellY,$col['w'],$rowH);
                if ($usedH<$rowH) { $pdf->SetXY($curX,$cellY+$usedH); $pdf->Cell($col['w'],$rowH-$usedH,'',0,0,'L',$fillRow); }
            }
            $curX+=$col['w'];
        }
        $pdf->SetXY($startX,$rowY+$rowH);
        $rowNum++; $fillRow=!$fillRow;
    }
}

$pdf->Ln(4);
$pdf->SetDrawColor(30,58,138); $pdf->SetLineWidth(0.3);
$pdf->Line(12,$pdf->GetY(),285,$pdf->GetY()); $pdf->Ln(2);
$pdf->SetFont('Arial','I',7); $pdf->SetTextColor(130,130,130);
$pdf->Cell(0,5,'Report: '.$reportName.'  |  Type: '.$reportType.'  |  Generated: '.date('F d, Y  h:i A').'  |  Nexus Platform',0,1,'R');

$pdf->Output('D',$filename.'.pdf');
exit;