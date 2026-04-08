<?php
/**
 * certificates.php — Certificate Generator UI
 * Upload your certificate template image as background.
 * Customize logos, cert type, content, name, signatory, and e-signature.
 */
session_start();
require '../config/db.php';
require __DIR__ . '/_rbac.php';
nexus_require_role_page($pdo, ['admin', 'staff']);
$activePage='certificates'; $pageTitle='Certificates'; $pageSubtitle='Certificate Generator'; $docTitle='Nexus Platform';
require '_sidebar.php';

$certDir = __DIR__ . '/cert_logos/';
if (!is_dir($certDir)) mkdir($certDir, 0755, true);
$settingsFile = $certDir . 'cert_settings.json';

/* ═══════════════════════════════════════
   AJAX: UPLOAD TEMPLATE
═══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='upload_template') {
    header('Content-Type: application/json');
    if (!isset($_FILES['template'])||$_FILES['template']['error']!==UPLOAD_ERR_OK) {
        echo json_encode(['success'=>false,'message'=>'Upload failed.']); exit;
    }
    $mime = mime_content_type($_FILES['template']['tmp_name']);
    if (!in_array($mime,['image/png','image/jpeg'])) {
        echo json_encode(['success'=>false,'message'=>'Only PNG or JPG allowed.']); exit;
    }
    if ($_FILES['template']['size'] > 15*1024*1024) {
        echo json_encode(['success'=>false,'message'=>'Max 15 MB.']); exit;
    }
    $ext  = strtolower(pathinfo($_FILES['template']['name'], PATHINFO_EXTENSION));
    foreach (glob($certDir.'template.*') as $old) @unlink($old);
    $dest = $certDir . 'template.' . $ext;
    move_uploaded_file($_FILES['template']['tmp_name'], $dest);
    echo json_encode(['success'=>true,'path'=>'cert_logos/template.'.$ext,'ts'=>time()]);
    exit;
}

/* ═══════════════════════════════════════
   AJAX: UPLOAD LOGO (slots 1-3)
═══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='upload_logo') {
    header('Content-Type: application/json');
    $slot=(int)($_POST['slot']??1);
    if ($slot<1||$slot>3){echo json_encode(['success'=>false,'message'=>'Invalid slot.']);exit;}
    if (!isset($_FILES['logo'])||$_FILES['logo']['error']!==UPLOAD_ERR_OK){
        echo json_encode(['success'=>false,'message'=>'Upload failed.']);exit;}
    $mime=mime_content_type($_FILES['logo']['tmp_name']);
    if (!in_array($mime,['image/png','image/jpeg'])){
        echo json_encode(['success'=>false,'message'=>'PNG or JPG only.']);exit;}
    if ($_FILES['logo']['size']>5*1024*1024){
        echo json_encode(['success'=>false,'message'=>'Max 5 MB.']);exit;}
    $ext=strtolower(pathinfo($_FILES['logo']['name'],PATHINFO_EXTENSION));
    foreach (glob($certDir."logo_{$slot}.*") as $old) @unlink($old);
    $dest=$certDir."logo_{$slot}.".$ext;
    move_uploaded_file($_FILES['logo']['tmp_name'],$dest);
    echo json_encode(['success'=>true,'path'=>"cert_logos/logo_{$slot}.{$ext}",'slot'=>$slot,'ts'=>time()]);
    exit;
}

/* ═══════════════════════════════════════
   AJAX: UPLOAD E-SIGNATURE
═══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='upload_esig') {
    header('Content-Type: application/json');
    if (!isset($_FILES['esig'])||$_FILES['esig']['error']!==UPLOAD_ERR_OK) {
        echo json_encode(['success'=>false,'message'=>'Upload failed.']); exit;
    }
    $mime = mime_content_type($_FILES['esig']['tmp_name']);
    if (!in_array($mime,['image/png','image/jpeg'])) {
        echo json_encode(['success'=>false,'message'=>'Only PNG or JPG allowed. PNG with transparency recommended.']); exit;
    }
    if ($_FILES['esig']['size'] > 5*1024*1024) {
        echo json_encode(['success'=>false,'message'=>'Max 5 MB.']); exit;
    }
    $ext = strtolower(pathinfo($_FILES['esig']['name'], PATHINFO_EXTENSION));
    foreach (glob($certDir.'esig.*') as $old) @unlink($old);
    $dest = $certDir . 'esig.' . $ext;
    move_uploaded_file($_FILES['esig']['tmp_name'], $dest);
    echo json_encode(['success'=>true,'path'=>'cert_logos/esig.'.$ext,'ts'=>time()]);
    exit;
}

/* ═══════════════════════════════════════
   AJAX: DELETE ASSET
═══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete_asset') {
    header('Content-Type: application/json');
    $target = $_POST['target'] ?? '';
    if ($target === 'template') {
        foreach (glob($certDir.'template.*') as $f) @unlink($f);
    } elseif ($target === 'esig') {
        foreach (glob($certDir.'esig.*') as $f) @unlink($f);
    } elseif (is_numeric($target)) {
        foreach (glob($certDir."logo_{$target}.*") as $f) @unlink($f);
    }
    echo json_encode(['success'=>true]); exit;
}

/* ═══════════════════════════════════════
   AJAX: SAVE SETTINGS
═══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_settings') {
    header('Content-Type: application/json');
    $data = [
        'signatory'  => trim($_POST['signatory']  ?? ''),
        'sign_title' => trim($_POST['sign_title'] ?? ''),
    ];
    file_put_contents($settingsFile, json_encode($data, JSON_PRETTY_PRINT));
    echo json_encode(['success'=>true]); exit;
}

/* ─── Load state ─── */
$settings = file_exists($settingsFile)
    ? (json_decode(file_get_contents($settingsFile), true) ?? [])
    : [];

$templateUrl = null;
foreach (['template.jpg','template.jpeg','template.png'] as $fn) {
    if (file_exists($certDir.$fn)) {
        $templateUrl = 'cert_logos/'.$fn.'?v='.filemtime($certDir.$fn);
        break;
    }
}

$logoUrls = [];
for ($i=1;$i<=3;$i++) {
    $files = glob($certDir."logo_{$i}.*");
    $logoUrls[$i] = !empty($files) ? 'cert_logos/'.basename($files[0]).'?v='.filemtime($files[0]) : null;
}

$esigUrl = null;
$esigFiles = glob($certDir.'esig.*');
if (!empty($esigFiles)) {
    $esigUrl = 'cert_logos/'.basename($esigFiles[0]).'?v='.filemtime($esigFiles[0]);
}

$events = $pdo->query("
    SELECT es.id, es.agenda, es.start_date, es.end_date,
           es.venue, COUNT(er.id) AS reg_count
    FROM event_settings es
    LEFT JOIN event_registrations er ON TRIM(er.agenda)=TRIM(es.agenda)
    GROUP BY es.id, es.agenda, es.start_date, es.end_date, es.venue
    ORDER BY es.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);
$totalRegs = array_sum(array_column($events,'reg_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<?= nexusHead() ?>
<style>
/* ── Layout ── */
.cert-layout{display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start;}
@media(max-width:1100px){.cert-layout{grid-template-columns:1fr;}}
.form-panel{background:var(--panel);border:1px solid var(--border-hi);border-radius:var(--radius);overflow:hidden;}
.form-section{padding:16px 20px;border-bottom:1px solid var(--border);}
.form-section:last-child{border-bottom:none;}
.sec-label{font-family:var(--mono);font-size:10px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.sec-label::after{content:'';flex:1;height:1px;background:var(--border);}
.ff{display:flex;flex-direction:column;gap:5px;margin-bottom:12px;}
.ff:last-child{margin-bottom:0;}
.ff label{font-family:var(--mono);font-size:10px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.08em;}
.ff input,.ff select,.ff textarea{background:var(--bg);border:1px solid var(--border-hi);border-radius:7px;padding:8px 12px;font-family:var(--mono);font-size:12px;color:var(--text-pri);outline:none;transition:border .15s,box-shadow .15s;width:100%;}
.ff input:focus,.ff select:focus,.ff textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow);}
.ff textarea{resize:vertical;min-height:56px;}
.fr2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.fpf{padding:14px 20px;display:flex;flex-direction:column;gap:8px;}

/* ── Upload drop zones ── */
.upload-zone{border:2px dashed var(--border-hi);border-radius:10px;padding:16px 12px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;position:relative;background:var(--bg);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;}
.upload-zone:hover{border-color:var(--accent);background:rgba(79,110,247,.04);}
.upload-zone.has-file{border-style:solid;border-color:rgba(79,110,247,.4);}
.upload-zone input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;z-index:1;}
.upload-zone-icon{font-size:22px;color:var(--text-dim);}
.upload-zone-label{font-family:var(--mono);font-size:11px;color:var(--text-dim);}
.upload-zone-name{font-family:var(--mono);font-size:11px;color:var(--accent-hi);font-weight:600;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.upload-del{position:absolute;top:6px;right:6px;z-index:2;width:22px;height:22px;border-radius:50%;background:var(--danger);color:#fff;border:none;cursor:pointer;font-size:10px;display:none;align-items:center;justify-content:center;}
.upload-zone.has-file .upload-del{display:flex;}

/* ── Template / esig zone ── */
.template-zone{border:2px dashed var(--border-hi);border-radius:10px;padding:20px;text-align:center;cursor:pointer;transition:border-color .2s;position:relative;background:var(--bg);min-height:120px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;}
.template-zone:hover{border-color:var(--accent);}
.template-zone.has-file{border-style:solid;border-color:rgba(79,110,247,.4);padding:10px;}
.template-zone input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;z-index:1;}
.template-thumb{max-width:100%;max-height:140px;border-radius:6px;object-fit:contain;display:none;}
.template-thumb.visible{display:block;}
.template-del{position:absolute;top:8px;right:8px;z-index:2;width:26px;height:26px;border-radius:50%;background:var(--danger);color:#fff;border:none;cursor:pointer;font-size:12px;display:none;align-items:center;justify-content:center;}
.template-zone.has-file .template-del{display:flex;}

/* ── Logo slots row ── */
.logo-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.logo-slot-mini{border:2px dashed var(--border-hi);border-radius:8px;padding:8px 6px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;position:relative;background:var(--bg);min-height:70px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;}
.logo-slot-mini:hover{border-color:var(--accent);background:rgba(79,110,247,.04);}
.logo-slot-mini.has-logo{border-style:solid;border-color:rgba(79,110,247,.35);}
.logo-slot-mini input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;z-index:1;}
.logo-slot-mini img{max-width:44px;max-height:40px;object-fit:contain;pointer-events:none;}
.logo-slot-mini .lsm-icon{font-size:16px;color:var(--text-dim);}
.logo-slot-mini .lsm-lbl{font-family:var(--mono);font-size:9px;color:var(--text-dim);}
.logo-slot-mini .lsm-del{position:absolute;top:4px;right:4px;z-index:2;width:18px;height:18px;border-radius:50%;background:var(--danger);color:#fff;border:none;cursor:pointer;font-size:9px;display:none;align-items:center;justify-content:center;}
.logo-slot-mini.has-logo .lsm-del{display:flex;}

/* ── Certificate preview panel ── */
.preview-panel{background:var(--panel);border:1px solid var(--border-hi);border-radius:var(--radius);overflow:hidden;position:sticky;top:20px;}
.preview-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}

.cert-preview-wrap{padding:16px;background:var(--bg);border-radius:0 0 var(--radius) var(--radius);}
.cert-preview-box{
    width:100%; aspect-ratio:297/210;
    position:relative; overflow:hidden;
    border-radius:6px; border:1px solid var(--border-hi);
    box-shadow:0 4px 20px rgba(0,0,0,.25);
    background:#f5f6fa;
}
.cert-preview-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:fill;}
.cert-preview-overlay{
    position:absolute;inset:0;
    display:flex;flex-direction:column;
    align-items:center;
    font-family:Arial,Helvetica,sans-serif;
    padding:0;
}
.pv-logos{
    position:absolute;
    left:50%;transform:translateX(-50%);
    display:flex;gap:1.5%;align-items:center;
    justify-content:center;
}
.pv-logos img{object-fit:contain;}
.pv-text{position:absolute;width:100%;text-align:center;left:0;}

.no-template{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:8px;color:var(--text-dim);}
.no-template i{font-size:32px;opacity:.4;}
.no-template p{font-family:var(--mono);font-size:11px;opacity:.6;}

.ctb{display:inline-flex;align-items:center;gap:4px;font-family:var(--mono);font-size:10px;padding:2px 8px;border-radius:20px;font-weight:600;}
.ctb-p{background:rgba(79,110,247,.1);color:var(--accent-hi);border:1px solid rgba(79,110,247,.2);}
.ctb-r{background:rgba(245,166,35,.1);color:var(--warning);border:1px solid rgba(245,166,35,.2);}
.ctb-c{background:rgba(52,211,153,.1);color:var(--success);border:1px solid rgba(52,211,153,.2);}

.gen-loading{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(4px);}
.gen-loading.show{display:flex;}
.gen-box{background:var(--panel);border:1px solid var(--border-hi);border-radius:14px;padding:32px 40px;text-align:center;min-width:260px;}
.gen-spin{width:40px;height:40px;border:3px solid var(--border-hi);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 14px;}
@keyframes spin{to{transform:rotate(360deg);}}

.alert-warn{display:flex;align-items:center;gap:8px;background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.25);border-radius:8px;padding:10px 14px;font-family:var(--mono);font-size:11px;color:var(--warning);margin-bottom:12px;}
</style>
</head>
<body>
<?= nexusSidebar() ?>
<div class="main">
<?= nexusTopbar() ?>
<div class="content">

<div class="page-header">
    <div>
        <h1>Certificate Generator</h1>
        <p>Upload your certificate template · Customize text content · Generate PDFs</p>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
    <div class="stat-card c1"><div class="stat-icon"><i class="fas fa-certificate"></i></div><div class="stat-label">Total Events</div><div class="stat-value"><?= count($events) ?></div><div class="stat-sub">Available for generation</div></div>
    <div class="stat-card c2"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-label">Total Registrants</div><div class="stat-value"><?= number_format($totalRegs) ?></div><div class="stat-sub">Across all events</div></div>
    <div class="stat-card c3"><div class="stat-icon"><i class="fas fa-file-pdf"></i></div><div class="stat-label">Types</div><div class="stat-value">3</div><div class="stat-sub">Participation · Recognition · Completion</div></div>
</div>

<div class="cert-layout">

    <!-- ══ LEFT: SETTINGS ══ -->
    <div class="form-panel">
        <div style="padding:16px 20px;border-bottom:1px solid var(--border)">
            <div style="font-size:14px;font-weight:700;color:var(--text-pri)">
                <i class="fas fa-sliders" style="color:var(--accent);margin-right:6px"></i>Certificate Settings
            </div>
            <div style="font-size:11px;color:var(--text-dim);margin-top:2px;font-family:var(--mono)">
                Upload template, add logos, set content
            </div>
        </div>

        <!-- TEMPLATE UPLOAD -->
        <div class="form-section">
            <div class="sec-label"><i class="fas fa-image" style="margin-right:4px"></i>Certificate Template Background</div>
            <div class="template-zone <?= $templateUrl?'has-file':'' ?>" id="templateZone">
                <input type="file" accept="image/png,image/jpeg" onchange="uploadTemplate(this)">
                <button class="template-del" type="button" onclick="deleteAsset('template',event)" title="Remove template"><i class="fas fa-xmark"></i></button>
                <img src="<?= $templateUrl ? htmlspecialchars($templateUrl) : '' ?>"
                     class="template-thumb <?= $templateUrl?'visible':'' ?>"
                     id="templateThumb" alt="Template preview">
                <div id="templatePlaceholder" <?= $templateUrl?'style="display:none"':'' ?>>
                    <i class="fas fa-cloud-arrow-up" style="font-size:28px;color:var(--text-dim)"></i>
                    <div style="font-family:var(--mono);font-size:12px;color:var(--text-dim);margin-top:4px">Click or drag your certificate template here</div>
                    <div style="font-family:var(--mono);font-size:10px;color:var(--text-dim);opacity:.6">PNG or JPG · Landscape A4 recommended · Max 15 MB</div>
                </div>
            </div>
            <?php if (!$templateUrl): ?>
            <div class="alert-warn" style="margin-top:10px;margin-bottom:0">
                <i class="fas fa-triangle-exclamation"></i>
                No template uploaded. Upload your certificate image above to generate PDFs.
            </div>
            <?php endif; ?>
        </div>

        <!-- ORG LOGOS -->
        <div class="form-section">
            <div class="sec-label"><i class="fas fa-shield-halved" style="margin-right:4px"></i>Organization Logos (optional)</div>
            <p style="font-family:var(--mono);font-size:10px;color:var(--text-dim);margin-bottom:10px">
                If your template already has logos baked in, leave these empty. Upload here to overlay logos on top of the template.
            </p>
            <div class="logo-row">
                <?php for ($s=1;$s<=3;$s++): ?>
                <div class="logo-slot-mini <?= $logoUrls[$s]?'has-logo':'' ?>" id="lsm<?= $s ?>">
                    <input type="file" accept="image/png,image/jpeg" onchange="uploadLogo(<?= $s ?>,this)">
                    <button class="lsm-del" type="button" onclick="deleteAsset('<?= $s ?>',event)"><i class="fas fa-xmark"></i></button>
                    <?php if ($logoUrls[$s]): ?>
                        <img src="<?= htmlspecialchars($logoUrls[$s]) ?>" id="lsmImg<?= $s ?>" alt="Logo <?= $s ?>">
                    <?php else: ?>
                        <i class="fas fa-image lsm-icon" id="lsmIcon<?= $s ?>"></i>
                    <?php endif; ?>
                    <span class="lsm-lbl">Logo <?= $s ?></span>
                </div>
                <?php endfor; ?>
            </div>
            <p style="font-family:var(--mono);font-size:10px;color:var(--text-dim);margin-top:8px">PNG or JPG · Max 5 MB each</p>
        </div>

        <!-- CERT TYPE & EVENT -->
        <div class="form-section">
            <div class="sec-label"><i class="fas fa-certificate" style="margin-right:4px"></i>Certificate Type &amp; Event</div>
            <div class="ff">
                <label>Certificate Type</label>
                <select id="certType" onchange="updatePreview()">
                    <option value="participation">Certificate of Participation</option>
                    <option value="recognition">Certificate of Recognition</option>
                    <option value="completion">Certificate of Completion</option>
                </select>
            </div>
            <div class="ff">
                <label>Event / Agenda <span style="color:var(--danger)">*</span></label>
                <select id="eventSelect" onchange="onEventChange()">
                    <option value="">— Select event —</option>
                    <?php foreach ($events as $ev): ?>
                    <option value="<?= $ev['id'] ?>"
                        data-agenda="<?= htmlspecialchars($ev['agenda']) ?>"
                        data-from="<?= $ev['start_date'] ?>"
                        data-to="<?= $ev['end_date'] ?>"
                        data-count="<?= $ev['reg_count'] ?>"
                        data-venue="<?= htmlspecialchars($ev['venue'] ?? '') ?>">
                        <?= htmlspecialchars($ev['agenda']) ?> (<?= $ev['reg_count'] ?> registrants)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fr2">
                <div class="ff"><label>Date From</label><input type="date" id="dateFrom" oninput="updatePreview()"></div>
                <div class="ff"><label>Date To</label><input type="date" id="dateTo" oninput="updatePreview()"></div>
            </div>
        </div>

        <!-- SIGNATORY -->
        <div class="form-section">
            <div class="sec-label"><i class="fas fa-signature" style="margin-right:4px"></i>Signatory</div>
            <div class="ff">
                <label>Name</label>
                <input type="text" id="signatory" value="<?= htmlspecialchars($settings['signatory'] ?? 'CAPT LEO AMOR A VIDAL PN(GSC)') ?>" oninput="updatePreview()">
            </div>
            <div class="ff">
                <label>Title / Position</label>
                <input type="text" id="signTitle" value="<?= htmlspecialchars($settings['sign_title'] ?? 'AC of NS for Naval Systems Engineering, N11') ?>" oninput="updatePreview()">
            </div>
            <button class="btn btn-ghost" style="margin-top:4px" onclick="saveSettings()">
                <i class="fas fa-floppy-disk"></i> Save as Default
            </button>
        </div>

        <!-- E-SIGNATURE UPLOAD -->
        <div class="form-section">
            <div class="sec-label"><i class="fas fa-pen-nib" style="margin-right:4px"></i>E-Signature</div>
            <p style="font-family:var(--mono);font-size:10px;color:var(--text-dim);margin-bottom:10px">
                Upload a signature image. PNG with transparent background recommended.
                It will be centered above the signatory name on the certificate.
            </p>
            <div class="template-zone <?= $esigUrl?'has-file':'' ?>" id="esigZone" style="min-height:90px;padding:12px;">
                <input type="file" accept="image/png,image/jpeg" onchange="uploadEsig(this)">
                <button class="template-del" type="button" onclick="deleteAsset('esig',event)" title="Remove e-signature">
                    <i class="fas fa-xmark"></i>
                </button>
                <img src="<?= $esigUrl ? htmlspecialchars($esigUrl) : '' ?>"
                     class="template-thumb <?= $esigUrl?'visible':'' ?>"
                     id="esigThumb" alt="E-Signature preview"
                     style="max-height:70px;filter:drop-shadow(0 1px 3px rgba(0,0,0,.15))">
                <div id="esigPlaceholder" <?= $esigUrl?'style="display:none"':'' ?>>
                    <i class="fas fa-signature" style="font-size:24px;color:var(--text-dim)"></i>
                    <div style="font-family:var(--mono);font-size:11px;color:var(--text-dim);margin-top:4px">
                        Click or drag e-signature here
                    </div>
                    <div style="font-family:var(--mono);font-size:10px;color:var(--text-dim);opacity:.6">
                        PNG (transparent) or JPG · Max 5 MB
                    </div>
                </div>
            </div>
        </div>

        <!-- PREVIEW NAME -->
        <div class="form-section" style="border-bottom:none">
            <div class="sec-label"><i class="fas fa-user" style="margin-right:4px"></i>Preview Recipient</div>
            <div class="ff">
                <label>Sample name (live preview only)</label>
                <input type="text" id="previewName" value="CAPT JUAN D. DELA CRUZ PN(GSC)" oninput="updatePreview()">
            </div>
        </div>

        <div class="fpf">
            <button class="btn btn-primary" onclick="generateBulk()" id="bulkBtn" disabled>
                <i class="fas fa-file-pdf"></i> <span id="bulkBtnLabel">Generate All Certificates</span>
            </button>
            <button class="btn btn-ghost" onclick="generatePreviewPDF()" id="previewPdfBtn" <?= !$templateUrl?'disabled':'' ?>>
                <i class="fas fa-eye"></i> Preview as PDF
            </button>
        </div>
    </div>

    <!-- ══ RIGHT: LIVE PREVIEW ══ -->
    <div class="preview-panel">
        <div class="preview-header">
            <span style="font-size:14px;font-weight:700;color:var(--text-pri)">
                <i class="fas fa-id-card" style="color:var(--accent);margin-right:6px"></i>Live Preview
            </span>
            <span id="pvTypeBadge" class="ctb ctb-p">Participation</span>
            <span style="font-family:var(--mono);font-size:10px;color:var(--text-dim);margin-left:auto">
                <i class="fas fa-circle-info" style="margin-right:4px"></i>Text overlaid on your template
            </span>
        </div>
        <div class="cert-preview-wrap">
            <div class="cert-preview-box" id="certPreviewBox">

                <?php if ($templateUrl): ?>
                    <img src="<?= htmlspecialchars($templateUrl) ?>" class="cert-preview-bg" id="pvBgImg" alt="Certificate template">
                    <div class="cert-preview-overlay" id="pvOverlay">
                        <div class="pv-logos" id="pvLogosRow" style="top:5%;height:20%;"></div>
                        <div class="pv-text" id="pvSub"      style="top:25%;font-size:1.4em;color:#0B2C6B;letter-spacing:.1em;">CERTIFICATE OF</div>
                        <div class="pv-text" id="pvMain"     style="top:33%;font-size:3.2em;font-weight:bold;color:#0B2C6B;letter-spacing:.04em;line-height:1.05;">PARTICIPATION</div>
                        <div class="pv-text" id="pvAwarded"  style="top:45%;font-size:.95em;color:#666;font-style:italic;">is awarded to</div>
                        <div class="pv-text" id="pvName"     style="top:52%;font-size:1.7em;font-weight:bold;color:#0B2C6B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding:0 5%;">CAPT JUAN D. DELA CRUZ PN(GSC)</div>
                        <div class="pv-text" id="pvBody"     style="top:62%;font-size:.85em;color:#222;line-height:1.7;padding:0 12%;"></div>
                        <!-- E-signature preview — injected by JS if uploaded -->
                        <div class="pv-text" id="pvSigName"  style="top:84%;font-size:.9em;font-weight:bold;color:#0B2C6B;letter-spacing:.05em;text-transform:uppercase;">CAPT LEO AMOR A VIDAL PN(GSC)</div>
                        <div class="pv-text" id="pvSigTitle" style="top:88%;font-size:.75em;color:#666;">AC of NS for Naval Systems Engineering, N11</div>
                    </div>
                <?php else: ?>
                    <div class="no-template" id="noTemplateMsg">
                        <i class="fas fa-image"></i>
                        <p>Upload a certificate template to see the preview</p>
                    </div>
                <?php endif; ?>

            </div>
            <p style="font-family:var(--mono);font-size:10px;color:var(--text-dim);margin-top:10px;text-align:center">
                Text positions match the generated PDF · Upload your template image on the left to begin
            </p>
        </div>
    </div>
</div>

<!-- ══ REGISTRANT TABLE ══ -->
<div style="margin-top:20px">
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">Individual Certificates</div>
                <div class="card-subtitle">Preview or download per registrant after selecting an event</div>
            </div>
            <div class="card-tools">
                <div style="display:flex;align-items:center;gap:8px;background:var(--bg);border:1px solid var(--border-hi);border-radius:7px;padding:6px 12px">
                    <i class="fas fa-search" style="color:var(--text-dim);font-size:12px"></i>
                    <input type="text" id="regSearch" placeholder="Search registrants…" oninput="filterRegs()"
                           style="background:none;border:none;outline:none;font-family:var(--mono);font-size:12px;color:var(--text-pri);width:180px">
                </div>
            </div>
        </div>
        <div class="table-wrap">
            <table class="nx-table">
                <thead>
                    <tr><th>#</th><th>Name</th><th>Unit / Office</th><th>Designation</th><th>Serial No.</th><th class="no-sort">Actions</th></tr>
                </thead>
                <tbody id="regTbody">
                    <tr><td colspan="6">
                        <div class="empty-state"><i class="fas fa-arrow-up"></i><p>Select an event above to load registrants.</p></div>
                    </td></tr>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <span class="pagination-info" id="regCount">No event selected</span>
        </div>
    </div>
</div>

</div><!-- /content -->
<?= nexusFooter() ?>
</div><!-- /main -->

<!-- Loading overlay -->
<div class="gen-loading" id="genLoading">
    <div class="gen-box">
        <div class="gen-spin"></div>
        <div style="font-size:14px;font-weight:700;color:var(--text-pri);margin-bottom:4px">Generating Certificates…</div>
        <div style="font-size:12px;color:var(--text-dim);font-family:var(--mono)" id="genMsg">Please wait.</div>
    </div>
</div>

<?= nexusToast() ?>
<?= nexusJS() ?>

<script>
/* ════════════════════════════════════
   TEMPLATE UPLOAD
════════════════════════════════════ */
let hasTemplate = <?= $templateUrl ? 'true' : 'false' ?>;

function uploadTemplate(input) {
    const file = input.files[0]; if (!file) return;
    if (file.size > 15*1024*1024) { showToast('Max 15 MB.','error'); return; }
    const fd = new FormData();
    fd.append('action','upload_template'); fd.append('template',file);
    const zone = document.getElementById('templateZone');
    zone.style.opacity = '.5';
    showToast('Uploading template…','info');
    fetch('certificates.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{
            zone.style.opacity='1';
            if (res.success) {
                const url = res.path+'?v='+res.ts;
                const thumb = document.getElementById('templateThumb');
                const ph    = document.getElementById('templatePlaceholder');
                thumb.src = url; thumb.classList.add('visible');
                if (ph) ph.style.display='none';
                zone.classList.add('has-file');
                hasTemplate = true;
                injectPreviewBg(url);
                document.getElementById('previewPdfBtn').disabled=false;
                showToast('Template uploaded.','success');
            } else showToast(res.message||'Upload failed.','error');
        })
        .catch(()=>{zone.style.opacity='1'; showToast('Network error.','error');});
}

function injectPreviewBg(url) {
    const box = document.getElementById('certPreviewBox');
    const nt = document.getElementById('noTemplateMsg');
    if (nt) nt.remove();

    let bg = document.getElementById('pvBgImg');
    if (!bg) {
        bg = document.createElement('img');
        bg.id='pvBgImg'; bg.className='cert-preview-bg'; bg.alt='Certificate template';
        box.insertBefore(bg, box.firstChild);
    }
    bg.src = url;

    let ov = document.getElementById('pvOverlay');
    if (!ov) {
        ov = document.createElement('div');
        ov.id='pvOverlay'; ov.className='cert-preview-overlay';
        ov.innerHTML = `
            <div class="pv-logos" id="pvLogosRow" style="top:11%;height:10%;"></div>
            <div class="pv-text" id="pvSub"      style="top:36%;font-size:1.4em;color:#0B2C6B;letter-spacing:.1em;">CERTIFICATE OF</div>
            <div class="pv-text" id="pvMain"     style="top:43%;font-size:3.2em;font-weight:bold;color:#0B2C6B;letter-spacing:.04em;line-height:1.05;">PARTICIPATION</div>
            <div class="pv-text" id="pvAwarded"  style="top:57%;font-size:.95em;color:#666;font-style:italic;">is awarded to</div>
            <div class="pv-text" id="pvName"     style="top:67%;font-size:1.7em;font-weight:bold;color:#0B2C6B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding:0 5%;">CAPT JUAN D. DELA CRUZ PN(GSC)</div>
            <div class="pv-text" id="pvBody"     style="top:76%;font-size:.85em;color:#222;line-height:1.7;padding:0 12%;"></div>
            <div class="pv-text" id="pvSigName"  style="top:88%;font-size:.9em;font-weight:bold;color:#0B2C6B;letter-spacing:.05em;text-transform:uppercase;">CAPT LEO AMOR A VIDAL PN(GSC)</div>
            <div class="pv-text" id="pvSigTitle" style="top:92%;font-size:.75em;color:#666;">AC of NS for Naval Systems Engineering, N11</div>
        `;
        box.appendChild(ov);
    }
    renderLogosInPreview();
    renderEsigInPreview();
    updatePreview();
}

/* ════════════════════════════════════
   LOGO UPLOAD
════════════════════════════════════ */
const logoState = {
<?php for($i=1;$i<=3;$i++): ?>
    <?=$i?>: <?=$logoUrls[$i]?json_encode($logoUrls[$i]):'null'?>,
<?php endfor; ?>
};

function uploadLogo(slot, input) {
    const file = input.files[0]; if (!file) return;
    if (file.size > 5*1024*1024) { showToast('Max 5 MB.','error'); return; }
    const fd = new FormData();
    fd.append('action','upload_logo'); fd.append('slot',slot); fd.append('logo',file);
    const wrap = document.getElementById('lsm'+slot);
    wrap.style.opacity='.5';
    showToast('Uploading logo '+slot+'…','info');
    fetch('certificates.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{
            wrap.style.opacity='1';
            if (res.success) {
                const url = res.path+'?v='+res.ts;
                logoState[slot] = url;
                let img = document.getElementById('lsmImg'+slot);
                const icon = document.getElementById('lsmIcon'+slot);
                if (!img) {
                    img=document.createElement('img');
                    img.id='lsmImg'+slot; img.alt='Logo '+slot;
                    wrap.insertBefore(img, wrap.querySelector('.lsm-del').nextSibling);
                }
                img.src=url; wrap.classList.add('has-logo');
                if(icon) icon.style.display='none';
                renderLogosInPreview();
                showToast('Logo '+slot+' uploaded.','success');
            } else showToast(res.message||'Failed.','error');
        })
        .catch(()=>{wrap.style.opacity='1'; showToast('Network error.','error');});
}

/* ════════════════════════════════════
   E-SIGNATURE UPLOAD
════════════════════════════════════ */
let esigUrl = <?= $esigUrl ? json_encode($esigUrl) : 'null' ?>;

function uploadEsig(input) {
    const file = input.files[0]; if (!file) return;
    if (file.size > 5*1024*1024) { showToast('Max 5 MB.','error'); return; }
    const fd = new FormData();
    fd.append('action','upload_esig'); fd.append('esig', file);
    const zone = document.getElementById('esigZone');
    zone.style.opacity = '.5';
    showToast('Uploading e-signature…','info');
    fetch('certificates.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{
            zone.style.opacity='1';
            if (res.success) {
                const url = res.path+'?v='+res.ts;
                esigUrl = url;
                const thumb = document.getElementById('esigThumb');
                const ph    = document.getElementById('esigPlaceholder');
                thumb.src = url; thumb.classList.add('visible');
                if (ph) ph.style.display='none';
                zone.classList.add('has-file');
                renderEsigInPreview();
                showToast('E-signature uploaded.','success');
            } else showToast(res.message||'Upload failed.','error');
        })
        .catch(()=>{ zone.style.opacity='1'; showToast('Network error.','error'); });
}

function renderEsigInPreview() {
    const existing = document.getElementById('pvEsig');
    if (existing) existing.remove();
    if (!esigUrl) return;
    const overlay = document.getElementById('pvOverlay');
    if (!overlay) return;
    const img = document.createElement('img');
    img.id = 'pvEsig';
    img.src = esigUrl;
    Object.assign(img.style, {
        position:      'absolute',
        left:          '50%',
        transform:     'translateX(-50%)',
        top:           '77%',      // sits above sig name at ~84%
        height:        '10%',
        maxWidth:      '25%',
        objectFit:     'contain',
        pointerEvents: 'none',
        filter:        'drop-shadow(0 1px 2px rgba(0,0,0,.08))',
        zIndex:        '5',
    });
    overlay.appendChild(img);
}

/* ════════════════════════════════════
   DELETE ASSET (template, logo, esig)
════════════════════════════════════ */
function deleteAsset(target, e) {
    e.preventDefault(); e.stopPropagation();
    const fd=new FormData(); fd.append('action','delete_asset'); fd.append('target',target);
    fetch('certificates.php',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
        if (!res.success) return;
        if (target==='template') {
            document.getElementById('templateThumb').classList.remove('visible');
            document.getElementById('templateThumb').src='';
            document.getElementById('templatePlaceholder').style.display='';
            document.getElementById('templateZone').classList.remove('has-file');
            const bg=document.getElementById('pvBgImg');
            if(bg) bg.remove();
            hasTemplate=false;
            document.getElementById('previewPdfBtn').disabled=true;
            showToast('Template removed.','info');
        } else if (target==='esig') {
            esigUrl = null;
            const thumb = document.getElementById('esigThumb');
            const ph    = document.getElementById('esigPlaceholder');
            thumb.classList.remove('visible'); thumb.src='';
            if (ph) ph.style.display='';
            document.getElementById('esigZone').classList.remove('has-file');
            const pvEsig = document.getElementById('pvEsig');
            if (pvEsig) pvEsig.remove();
            showToast('E-signature removed.','info');
        } else {
            const slot=parseInt(target);
            logoState[slot]=null;
            const img=document.getElementById('lsmImg'+slot);
            if(img)img.remove();
            const icon=document.getElementById('lsmIcon'+slot);
            if(icon)icon.style.display='';
            document.getElementById('lsm'+slot).classList.remove('has-logo');
            renderLogosInPreview();
            showToast('Logo '+slot+' removed.','info');
        }
    });
}

function renderLogosInPreview() {
    const row = document.getElementById('pvLogosRow'); if (!row) return;
    row.innerHTML='';
    let hasAny=false;
    for (let i=1;i<=3;i++) {
        if (logoState[i]) {
            const img=document.createElement('img');
            img.src=logoState[i]; img.style.height='100%'; img.style.maxWidth='15%'; img.style.objectFit='contain';
            row.appendChild(img); hasAny=true;
        }
    }
    row.style.display=hasAny?'flex':'none';
}

/* ════════════════════════════════════
   CERT TYPES
════════════════════════════════════ */
const CT = {
    participation: {
        main:'PARTICIPATION', badge:'ctb-p', lbl:'Participation',
        body:(a,d,v)=>`is hereby awarded this certificate in recognition of<br>active participation in the <b>${a}</b><br>held on <b>${d}${v?' at '+v+'.':'.'}` 
    },
    recognition: {
        main:'RECOGNITION', badge:'ctb-r', lbl:'Recognition',
        body:(a)=>`for his/her selfless dedication of time, effort, and expertise as a Lecturer during the<br><b>${a}</b><br>whose invaluable contribution significantly enhanced the knowledge, competence, and operational<br>effectiveness of personnel from various Philippine Navy units and offices.`
    },
    completion: {
        main:'COMPLETION', badge:'ctb-c', lbl:'Completion',
        body:(a,d)=>`has successfully completed all the requirements of the<br><b>${a}</b><br>held on <b>${d}</b>.`
    },
};

function fmtDate(d){
    if(!d)return'—';
    const m=['January','February','March','April','May','June','July','August','September','October','November','December'];
    const p=d.split('-');
    return parseInt(p[2])+' '+m[parseInt(p[1])-1]+' '+p[0];
}

function updatePreview(){
    const type  = document.getElementById('certType').value;
    const sig   = document.getElementById('signatory').value.trim()||'—';
    const sigt  = document.getElementById('signTitle').value.trim()||'—';
    const name  = document.getElementById('previewName').value.trim()||'—';
    const from  = document.getElementById('dateFrom').value;
    const to    = document.getElementById('dateTo').value;
    const agenda = document.getElementById('eventSelect').selectedOptions[0]?.dataset.agenda||'— select an event —';
    const venue  = document.getElementById('eventSelect').selectedOptions[0]?.dataset.venue||'';
    const dateStr= from&&to&&from!==to ? fmtDate(from)+' – '+fmtDate(to) : (from?fmtDate(from):'—');
    const cfg = CT[type];

    const b=document.getElementById('pvTypeBadge');
    b.className='ctb '+cfg.badge; b.textContent=cfg.lbl;

    const setTxt=(id,v)=>{ const el=document.getElementById(id); if(el)el.textContent=v; };
    const setHtml=(id,v)=>{ const el=document.getElementById(id); if(el)el.innerHTML=v; };

    setTxt('pvSub','CERTIFICATE OF');
    setTxt('pvMain', cfg.main);
    setTxt('pvName', name);
    setTxt('pvAwarded','is awarded to');
    setHtml('pvBody', cfg.body(agenda, dateStr, venue));
    setTxt('pvSigName',  sig.toUpperCase());
    setTxt('pvSigTitle', sigt);
}

/* ════════════════════════════════════
   SAVE SETTINGS
════════════════════════════════════ */
function saveSettings(){
    const fd=new FormData();
    fd.append('action','save_settings');
    fd.append('signatory', document.getElementById('signatory').value.trim());
    fd.append('sign_title',document.getElementById('signTitle').value.trim());
    fetch('certificates.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{ if(res.success) showToast('Default signatory saved.','success'); });
}

/* ════════════════════════════════════
   EVENT CHANGE / LOAD REGISTRANTS
════════════════════════════════════ */
let curEventId=null, curRegs=[];

function onEventChange(){
    const sel=document.getElementById('eventSelect'), opt=sel.selectedOptions[0];
    curEventId=sel.value||null;
    if(opt&&sel.value){
        document.getElementById('dateFrom').value=opt.dataset.from||'';
        document.getElementById('dateTo').value=opt.dataset.to||'';
        const cnt=parseInt(opt.dataset.count)||0;
        document.getElementById('bulkBtnLabel').textContent='Generate All '+cnt+' Certificates';
        document.getElementById('bulkBtn').disabled= (!hasTemplate || cnt===0);
        loadRegs(sel.value);
    } else {
        document.getElementById('bulkBtn').disabled=true;
        document.getElementById('regTbody').innerHTML='<tr><td colspan="6"><div class="empty-state"><i class="fas fa-arrow-up"></i><p>Select an event above.</p></div></td></tr>';
        document.getElementById('regCount').textContent='No event selected';
    }
    updatePreview();
}

function loadRegs(eid){
    document.getElementById('regTbody').innerHTML='<tr><td colspan="6" style="padding:32px;text-align:center;font-family:var(--mono);font-size:12px;color:var(--text-dim)"><i class="fas fa-circle-notch fa-spin" style="margin-right:8px"></i>Loading…</td></tr>';
    fetch('fetch_registrations.php',{method:'POST',body:new URLSearchParams({event_id:eid,draw:1,start:0,length:9999})})
        .then(r=>r.json()).then(res=>{curRegs=res.data||[]; renderRegs(curRegs);})
        .catch(()=>{ document.getElementById('regTbody').innerHTML='<tr><td colspan="6"><div class="empty-state"><i class="fas fa-triangle-exclamation"></i><p>Failed.</p></div></td></tr>'; });
}

function esc(v){return String(v||'—').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function renderRegs(data){
    const type = document.getElementById('certType').value;
    const sig  = encodeURIComponent(document.getElementById('signatory').value.trim());
    const sigt = encodeURIComponent(document.getElementById('signTitle').value.trim());
    if(!data.length){
        document.getElementById('regTbody').innerHTML='<tr><td colspan="6"><div class="empty-state"><i class="fas fa-users-slash"></i><p>No registrants found.</p></div></td></tr>';
        document.getElementById('regCount').textContent='0 registrants'; return;
    }
    document.getElementById('regTbody').innerHTML=data.map((r,i)=>`
        <tr data-s="${esc(r.fullname).toLowerCase()} ${esc(r.unit_office).toLowerCase()}">
            <td class="mono">${i+1}</td>
            <td class="td-main">${esc(r.fullname)}</td>
            <td>${esc(r.unit_office)}</td>
            <td>${esc(r.designation)}</td>
            <td class="mono">${esc(r.serial_number)}</td>
            <td><div class="row-actions">
                <a href="generate_certificate.php?reg_id=${r.id}&type=${type}&signatory=${sig}&sign_title=${sigt}&preview=1"
                   target="_blank" class="act-btn view" title="Preview PDF"
                   style="color:var(--accent-hi)" ${!hasTemplate?'onclick="return noTemplate()"':''}>
                   <i class="fas fa-eye"></i></a>
                <a href="generate_certificate.php?reg_id=${r.id}&type=${type}&signatory=${sig}&sign_title=${sigt}"
                   class="act-btn" title="Download PDF" style="color:var(--success)"
                   ${!hasTemplate?'onclick="return noTemplate()"':''}
                   onclick="showToast('Generating certificate…','info')">
                   <i class="fas fa-download"></i></a>
            </div></td>
        </tr>`).join('');
    document.getElementById('regCount').textContent=data.length+' registrant'+(data.length!==1?'s':'');
}

function filterRegs(){
    const q=document.getElementById('regSearch').value.toLowerCase(); let v=0;
    document.querySelectorAll('#regTbody tr[data-s]').forEach(r=>{
        const ok=!q||r.dataset.s.includes(q); r.style.display=ok?'':'none'; if(ok)v++;
    });
    document.getElementById('regCount').textContent=v+' registrant'+(v!==1?'s':'');
}

function noTemplate(){
    showToast('Please upload a certificate template first.','error'); return false;
}

/* ════════════════════════════════════
   GENERATE
════════════════════════════════════ */
function generateBulk(){
    if(!curEventId){ showToast('Select an event first.','error'); return; }
    if(!hasTemplate){ showToast('Upload a certificate template first.','error'); return; }
    const type = document.getElementById('certType').value;
    const sig  = encodeURIComponent(document.getElementById('signatory').value.trim());
    const sigt = encodeURIComponent(document.getElementById('signTitle').value.trim());
    document.getElementById('genMsg').textContent='Building '+curRegs.length+' certificate'+(curRegs.length!==1?'s':'')+'…';
    document.getElementById('genLoading').classList.add('show');
    const a=document.createElement('a');
    a.href='generate_certificate.php?event_id='+curEventId+'&type='+type+'&signatory='+sig+'&sign_title='+sigt;
    a.download=''; document.body.appendChild(a); a.click(); document.body.removeChild(a);
    setTimeout(()=>{ document.getElementById('genLoading').classList.remove('show'); showToast(curRegs.length+' certificates generated.','success'); },4000);
}

function generatePreviewPDF(){
    if(!hasTemplate){ showToast('Upload a certificate template first.','error'); return; }
    if(!curEventId||!curRegs.length){ showToast('Select an event with registrants first.','info'); return; }
    const type = document.getElementById('certType').value;
    const sig  = encodeURIComponent(document.getElementById('signatory').value.trim());
    const sigt = encodeURIComponent(document.getElementById('signTitle').value.trim());
    window.open('generate_certificate.php?reg_id='+curRegs[0].id+'&type='+type+'&signatory='+sig+'&sign_title='+sigt+'&preview=1','_blank');
}

/* Init */
renderLogosInPreview();
renderEsigInPreview();
updatePreview();
</script>
</body>
</html>