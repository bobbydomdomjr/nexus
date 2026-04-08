<?php
/**
 * ═══════════════════════════════════════════════════════════
 *  Nexus Platform — Certificate Generator
 *  Uses an uploaded certificate template image as the background.
 *  Only overlays: logos, cert type text, recipient name,
 *  body text, signatory, e-signature, and QR code (bottom right).
 *
 *  GET params:
 *    reg_id     — single registration ID
 *    event_id   — bulk: all registrants for this event
 *    type       — 'participation' | 'recognition' | 'completion'
 *    signatory  — signatory name
 *    sign_title — signatory title
 *    preview    — '1' = inline browser view
 * ═══════════════════════════════════════════════════════════
 */

session_start();
ini_set('display_errors', '0');
error_reporting(0);

require '../config/db.php';
require __DIR__ . '/_rbac.php';
nexus_require_role_json($pdo, ['admin', 'staff']);
require 'fpdf/fpdf.php';
require 'fpdf/phpqrcode/qrlib.php';

/* ─────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────── */
function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

function buildFullName(array $row): string {
    $ms = trim($row['major_service'] ?? '');
    $parts = array_filter([
        trim($row['rank']           ?? ''),
        trim($row['first_name']     ?? ''),
        trim($row['middle_initial'] ?? '') ? trim($row['middle_initial']) . '.' : '',
        trim($row['last_name']      ?? ''),
        trim($row['ext_name']       ?? ''),
        trim($row['serial_number']  ?? ''),
        $ms ? $ms : '',
    ]);
    return implode(' ', $parts);
}

function fmtDate(string $d): string {
    $ts = strtotime($d);
    return $ts ? date('d F Y', $ts) : $d;
}

function generateQrTempFile(string $content): ?string {
    $tmpPath = sys_get_temp_dir() . '/cert_qr_' . uniqid() . '.png';
    try {
        QRcode::png($content, $tmpPath, QR_ECLEVEL_M, 6, 2);
        return file_exists($tmpPath) ? $tmpPath : null;
    } catch (Exception $e) {
        return null;
    }
}

/* ─────────────────────────────────────────────
   INPUT
───────────────────────────────────────────── */
$regId     = (int)($_GET['reg_id']     ?? 0);
$eventId   = (int)($_GET['event_id']   ?? 0);
$certType  = $_GET['type']             ?? 'participation';
$signatory = urldecode(trim($_GET['signatory']  ?? ''));
$signTitle = urldecode(trim($_GET['sign_title'] ?? ''));
$preview   = ($_GET['preview']         ?? '0') === '1';

if (!in_array($certType, ['participation','recognition','completion'])) $certType = 'participation';

/* ─────────────────────────────────────────────
   LOAD CERT SETTINGS
───────────────────────────────────────────── */
$settingsFile = __DIR__ . '/cert_logos/cert_settings.json';
$settings = [];
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true) ?? [];
}

if (!$signatory) $signatory = $settings['signatory']  ?? 'SIGNATORY NAME';
if (!$signTitle) $signTitle = $settings['sign_title'] ?? 'Position / Title';

/* ─────────────────────────────────────────────
   TEMPLATE IMAGE
───────────────────────────────────────────── */
$certDir      = __DIR__ . '/cert_logos/';
$templatePath = null;
foreach (['template.jpg','template.jpeg','template.png'] as $fn) {
    if (file_exists($certDir . $fn)) { $templatePath = $certDir . $fn; break; }
}
if (!$templatePath) {
    jsonErr('No certificate template uploaded. Please upload a template image in Certificate Settings.');
}
$templateExt  = strtolower(pathinfo($templatePath, PATHINFO_EXTENSION));
$templateType = ($templateExt === 'png') ? 'PNG' : 'JPEG';

$imgInfo = @getimagesize($templatePath);
if (!$imgInfo) jsonErr('Cannot read template image.');

/* ─────────────────────────────────────────────
   FETCH REGISTRATIONS
───────────────────────────────────────────── */
$registrations = [];
if ($regId) {
    $stmt = $pdo->prepare("SELECT * FROM event_registrations WHERE id = ? LIMIT 1");
    $stmt->execute([$regId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonErr('Registration not found.');
    $registrations[] = $row;
} elseif ($eventId) {
    $stmt = $pdo->prepare("
        SELECT er.* FROM event_registrations er
        INNER JOIN event_settings es ON TRIM(es.agenda) = TRIM(er.agenda)
        WHERE es.id = ? ORDER BY er.last_name ASC, er.first_name ASC
    ");
    $stmt->execute([$eventId]);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($registrations)) jsonErr('No registrations found for this event.');
} else {
    jsonErr('Provide reg_id or event_id.');
}

/* ─────────────────────────────────────────────
   EVENT META
───────────────────────────────────────────── */
$agenda    = $registrations[0]['agenda']     ?? 'Event';
$startDate = $registrations[0]['start_date'] ?? date('Y-m-d');
$endDate   = $registrations[0]['end_date']   ?? $startDate;

$dateStr = (fmtDate($startDate) === fmtDate($endDate))
    ? fmtDate($startDate)
    : fmtDate($startDate) . ' - ' . fmtDate($endDate);

$venueStr = '';
try {
    $vStmt = $pdo->prepare("SELECT venue FROM event_settings WHERE TRIM(agenda) = TRIM(?) LIMIT 1");
    $vStmt->execute([$agenda]);
    $venueRow = $vStmt->fetch(PDO::FETCH_ASSOC);
    $venueStr = trim($venueRow['venue'] ?? '');
} catch (Exception $e) {}

$certTitles = [
    'participation' => 'PARTICIPATION',
    'recognition'   => 'RECOGNITION',
    'completion'    => 'COMPLETION',
];
$certMainTitle = $certTitles[$certType];

$bodyTexts = [
    'participation' => [
        'is hereby awarded this certificate in recognition of active participation in the',
        $agenda,
        'held on ' . $dateStr . ($venueStr !== '' ? ' at ' . $venueStr . '.' : '.'),
    ],
    'recognition' => [
        'for his/her selfless dedication of time, effort, and expertise as a Lecturer during the',
        $agenda,
        'whose invaluable contribution significantly enhanced the knowledge, competence,',
        'and operational effectiveness of personnel from various Philippine Navy units and offices.',
    ],
    'completion' => [
        'has successfully completed all the requirements of the',
        $agenda,
        'held on ' . $dateStr . '.',
    ],
];
$bodyLines = $bodyTexts[$certType];

/* ─────────────────────────────────────────────
   OVERLAY POSITIONS
───────────────────────────────────────────── */
$pos = array_merge([
    'logos_y_pct'       => 11.0,
    'logos_max_h_pct'   => 10.0,
    'cert_sub_y_pct'    => 26.0,
    'cert_sub_size'     => 25,
    'cert_main_y_pct'   => 33.0,
    'cert_main_size'    => 50,
    'awarded_y_pct'     => 43,
    'name_y_pct'        => 52,
    'name_size'         => 30,
    'body_y_pct'        => 60,
    'body_size'         => 14,
    'body_line_h'       => 8.0,
    // E-signature — centered above signatory
    'esig_y_pct'        => 77.0,   // Y start as % of page height
    'esig_h_pct'        =>  8.0,   // height as % of page height
    'esig_max_w_pct'    => 25.0,   // max width as % of page width
    // Signatory
    'sig_y_pct'         => 82,
    'sig_name_size'     => 16,
    'sig_title_size'    => 12,
    // QR code — bottom right
    'qr_x_pct'         => 85.0,
    'qr_y_pct'         => 77.0,
    'qr_size_pct'       => 10.0,
], $settings['positions'] ?? []);

/* ─────────────────────────────────────────────
   LOGO PATHS
───────────────────────────────────────────── */
$logoPaths = [];
for ($i = 1; $i <= 3; $i++) {
    $files = glob($certDir . "logo_{$i}.*");
    if (!empty($files)) {
        $ext = strtolower(pathinfo($files[0], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png'])) {
            $logoPaths[$i] = $files[0];
        }
    }
}

/* ─────────────────────────────────────────────
   E-SIGNATURE PATH
───────────────────────────────────────────── */
$esigPath  = null;
$esigFiles = glob($certDir . 'esig.*');
if (!empty($esigFiles)) {
    $ext = strtolower(pathinfo($esigFiles[0], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png'])) {
        $esigPath = $esigFiles[0];
    }
}

/* ═══════════════════════════════════════════════
   FPDF EXTENDED CLASS
═══════════════════════════════════════════════ */
class CertPDF extends FPDF
{
    public function Ellipse(float $x, float $y, float $rx, float $ry, string $style = 'D'): void {
        $op = ($style==='F') ? 'f' : (($style==='FD'||$style==='DF') ? 'b' : 's');
        $lx = 4/3*(M_SQRT2-1)*$rx; $ly = 4/3*(M_SQRT2-1)*$ry;
        $h = $this->h; $k = $this->k;
        $cx = $x*$k; $cy = ($h-$y)*$k;
        $rx *= $k; $ry *= $k; $lx *= $k; $ly *= $k;
        $this->_out(sprintf(
            '%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %s',
            $cx+$rx,$cy,
            $cx+$rx,$cy+$ly,$cx+$lx,$cy+$ry,$cx,$cy+$ry,
            $cx-$lx,$cy+$ry,$cx-$rx,$cy+$ly,$cx-$rx,$cy,
            $cx-$rx,$cy-$ly,$cx-$lx,$cy-$ry,$cx,$cy-$ry,
            $cx+$lx,$cy-$ry,$cx+$rx,$cy-$ly,$cx+$rx,$cy,
            $op
        ));
    }

    private function safeImage(string $path, float $x, float $y,
                                float $w = 0, float $h = 0): void {
        if (!file_exists($path)) return;
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $type = ($ext === 'png') ? 'PNG' : 'JPEG';
        try { $this->Image($path, $x, $y, $w, $h, $type); }
        catch (Exception $e) { /* skip */ }
    }

    public function buildCert(
        string  $templatePath,
        string  $templateType,
        string  $fullName,
        string  $agenda,
        string  $certMainTitle,
        array   $bodyLines,
        string  $signatory,
        string  $signTitle,
        array   $logoPaths,
        array   $pos,
        int     $regId,
        string  $certType,
        string  $dateStr,
        ?string $esigPath = null
    ): void {
        $this->AddPage('L', 'A4');
        $W = $this->GetPageWidth();   // 297 mm
        $H = $this->GetPageHeight();  // 210 mm

        /* ── STEP 1: Template background ── */
        $this->Image($templatePath, 0, 0, $W, $H, $templateType);

        /* ── STEP 2: Logos ── */
        $logoMaxH = $H * ($pos['logos_max_h_pct'] / 100);
        $logoGap  = 5;
        $logoTopY = $H * ($pos['logos_y_pct'] / 100);

        $logoData = [];
        for ($i = 1; $i <= 3; $i++) {
            $path = $logoPaths[$i] ?? null;
            if ($path && file_exists($path)) {
                $info = @getimagesize($path);
                if ($info && $info[1] > 0) {
                    $ratio = $info[0] / $info[1];
                    $lh = $logoMaxH;
                    $lw = $lh * $ratio;
                    $logoData[] = ['path' => $path, 'w' => $lw, 'h' => $lh];
                }
            }
        }
        if (!empty($logoData)) {
            $totalW = array_sum(array_column($logoData, 'w')) + $logoGap * (count($logoData) - 1);
            $cx = ($W - $totalW) / 2;
            foreach ($logoData as $logo) {
                $this->safeImage($logo['path'], $cx, $logoTopY, $logo['w'], $logo['h']);
                $cx += $logo['w'] + $logoGap;
            }
        }

        /* ── STEP 3: "CERTIFICATE OF" ── */
        $this->SetTextColor(11, 44, 107);
        $this->SetFont('Helvetica', '', $pos['cert_sub_size']);
        $this->SetXY(0, $H * ($pos['cert_sub_y_pct'] / 100));
        $this->Cell($W, 7, 'CERTIFICATE OF', 0, 1, 'C');

        /* ── STEP 4: Main title ── */
        $this->SetFont('Helvetica', 'B', $pos['cert_main_size']);
        $this->SetXY(0, $H * ($pos['cert_main_y_pct'] / 100));
        $this->Cell($W, 13, $certMainTitle, 0, 1, 'C');

        /* ── STEP 5: "is awarded to" ── */
        $this->SetTextColor(90, 90, 90);
        $this->SetFont('Helvetica', 'I', 14);
        $this->SetXY(0, $H * ($pos['awarded_y_pct'] / 100));
        $this->Cell($W, 6, 'is awarded to', 0, 1, 'C');

        /* ── STEP 6: Recipient name ── */
        $nameY = $H * ($pos['name_y_pct'] / 100);
        $nameW = 200;
        $this->SetTextColor(11, 44, 107);
        $fs = $pos['name_size'];
        $this->SetFont('Helvetica', 'B', $fs);
        while ($this->GetStringWidth($fullName) > $nameW - 6 && $fs > 10) {
            $fs -= 0.5;
            $this->SetFont('Helvetica', 'B', $fs);
        }
        $this->SetXY(0, $nameY);
        $this->Cell($W, 8, $fullName, 0, 1, 'C');

        /* ── STEP 7: Body text ── */
        $bodyY = $H * ($pos['body_y_pct'] / 100);
        $lineH = $pos['body_line_h'];
        foreach ($bodyLines as $line) {
            $isBold = ($line === $agenda);
            $this->SetTextColor(30, 30, 30);
            $this->SetFont('Helvetica', $isBold ? 'B' : '', $pos['body_size']);
            $margin = 35;
            if ($this->GetStringWidth($line) > $W - ($margin * 2)) {
                $this->SetXY($margin, $bodyY);
                $this->MultiCell($W - ($margin * 2), $lineH, $line, 0, 'C');
                $bodyY += $lineH * ceil($this->GetStringWidth($line) / ($W - $margin * 2) * 1.1);
            } else {
                $this->SetXY(0, $bodyY);
                $this->Cell($W, $lineH, $line, 0, 1, 'C');
                $bodyY += $lineH;
            }
        }

        /* ── STEP 8: E-Signature (centered above signatory) ── */
        if ($esigPath && file_exists($esigPath)) {
            $esigExt  = strtolower(pathinfo($esigPath, PATHINFO_EXTENSION));
            $esigType = ($esigExt === 'png') ? 'PNG' : 'JPEG';
            $esigInfo = @getimagesize($esigPath);
            if ($esigInfo && $esigInfo[1] > 0) {
                $ratio  = $esigInfo[0] / $esigInfo[1];
                $esigH  = $H * ($pos['esig_h_pct']     / 100);
                $maxW   = $W * ($pos['esig_max_w_pct']  / 100);
                $esigW  = $esigH * $ratio;
                // Cap width so it doesn't span too wide
                if ($esigW > $maxW) {
                    $esigW = $maxW;
                    $esigH = $esigW / $ratio;
                }
                $esigX = ($W - $esigW) / 2;   // horizontally centered
                $esigY = $H * ($pos['esig_y_pct'] / 100);
                try {
                    $this->Image($esigPath, $esigX, $esigY, $esigW, $esigH, $esigType);
                } catch (Exception $e) { /* skip if unreadable */ }
            }
        }

        /* ── STEP 9: Signatory ── */
        $sigY = $H * ($pos['sig_y_pct'] / 100);
        $this->SetTextColor(11, 44, 107);
        $this->SetFont('Helvetica', 'B', $pos['sig_name_size']);
        $this->SetXY(0, $sigY);
        $this->Cell($W, 6, strtoupper($signatory), 0, 1, 'C');

        $this->SetTextColor(80, 80, 80);
        $this->SetFont('Helvetica', '', $pos['sig_title_size']);
        $this->SetXY(0, $sigY + 7);
        $this->Cell($W, 5, $signTitle, 0, 1, 'C');

        /* ── STEP 10: QR Code (bottom right) ── */
        $qrContent = implode("\n", [
            'Reg ID : ' . $regId,
            'Name   : ' . $fullName,
            'Event  : ' . $agenda,
            'Date   : ' . $dateStr,
        ]);

        $qrTmp = generateQrTempFile($qrContent);
        if ($qrTmp) {
            $qrSize = $W * ($pos['qr_size_pct'] / 100);
            $qrX    = $W * ($pos['qr_x_pct']    / 100);
            $qrY    = $H * ($pos['qr_y_pct']    / 100);
            $this->Image($qrTmp, $qrX, $qrY, $qrSize, $qrSize, 'PNG');
            @unlink($qrTmp);
        }
    }
}

/* ─────────────────────────────────────────────
   BUILD PDF
───────────────────────────────────────────── */
$pdf = new CertPDF();
$pdf->SetTitle('Certificate of ' . ucfirst($certType));
$pdf->SetAuthor('Nexus Platform');
$pdf->SetCreator('Nexus Platform');
$pdf->SetAutoPageBreak(false);

foreach ($registrations as $reg) {
    $pdf->buildCert(
        $templatePath,
        $templateType,
        buildFullName($reg),
        $agenda,
        $certMainTitle,
        $bodyLines,
        $signatory,
        $signTitle,
        $logoPaths,
        $pos,
        (int)($reg['id'] ?? 0),
        $certType,
        $dateStr,
        $esigPath       // e-signature path, null if not uploaded
    );
}

/* ─────────────────────────────────────────────
   OUTPUT
───────────────────────────────────────────── */
$isBulk   = $eventId && count($registrations) > 1;
$lastName = $registrations[0]['last_name'] ?? 'cert';
$safeAg   = preg_replace('/[^a-zA-Z0-9_-]/', '_', substr($agenda, 0, 40));
$filename = $isBulk
    ? "certificates_{$safeAg}.pdf"
    : "certificate_{$lastName}_{$certType}.pdf";

$pdf->Output($preview ? 'I' : 'D', $filename);
exit;