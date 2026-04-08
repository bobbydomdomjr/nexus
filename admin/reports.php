<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}
require '../config/db.php';

$activePage   = 'reports';
$pageTitle    = 'Reports';
$pageSubtitle = 'Analytics Overview';
$docTitle     = 'Nexus Platform';

require '_sidebar.php';

/* ─────────────────────────────────────────────
   HANDLE REPORT FORM SUBMISSION (AJAX-aware)
───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_report'])) {
    $errors      = [];
    $reportName  = trim($_POST['report_name']   ?? '');
    $reportType  = trim($_POST['report_type']   ?? '');
    $reportEvent = trim($_POST['report_event']  ?? '');
    $dateFrom    = trim($_POST['date_from']     ?? '');
    $dateTo      = trim($_POST['date_to']       ?? '');
    $requestedBy = trim($_POST['requested_by']  ?? '');
    $outputFmt   = trim($_POST['output_format'] ?? 'PDF');
    $status      = trim($_POST['status']        ?? 'Queued');
    $notes       = trim($_POST['notes']         ?? '');

    if (!$reportName)  $errors[] = 'Report name is required.';
    if (!$reportType)  $errors[] = 'Report type is required.';
    if (!$reportEvent) $errors[] = 'Event / Agenda is required.';
    if (!$dateFrom)    $errors[] = 'Date From is required.';
    if (!$dateTo)      $errors[] = 'Date To is required.';
    if ($dateFrom && $dateTo && $dateTo < $dateFrom)
        $errors[] = 'Date To cannot be earlier than Date From.';

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO report_queue
                (report_name, report_type, event_agenda, date_from, date_to,
                 requested_by, output_format, status, notes, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,NOW())
        ");
        $stmt->execute([
            $reportName, $reportType, $reportEvent, $dateFrom, $dateTo,
            $requestedBy ?: 'admin', $outputFmt, $status, $notes
        ]);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Report queued successfully.']);
            exit;
        }
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }
    }
}

/* ── AJAX: status change ── */
if (isset($_GET['set_status'], $_GET['report_id']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $allowed   = ['Queued','Processing','Ready','Failed'];
    $newStatus = $_GET['set_status'];
    $rid       = (int)$_GET['report_id'];
    if (in_array($newStatus, $allowed) && $rid) {
        $pdo->prepare("UPDATE report_queue SET status=? WHERE id=?")->execute([$newStatus, $rid]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => "Status set to {$newStatus}.", 'status' => $newStatus, 'id' => $rid]);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

/* ── AJAX: delete ── */
if (isset($_GET['delete_report']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $rid = (int)$_GET['delete_report'];
    if ($rid) {
        // Grab name before deleting for the toast message
        $nameRow = $pdo->prepare("SELECT report_name FROM report_queue WHERE id=?");
        $nameRow->execute([$rid]);
        $rName = $nameRow->fetchColumn() ?: 'Report';
        $pdo->prepare("DELETE FROM report_queue WHERE id=?")->execute([$rid]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => htmlspecialchars($rName) . ' has been deleted.']);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
    exit;
}

/* ── Non-AJAX fallbacks ── */
if (isset($_GET['set_status'], $_GET['report_id'])) {
    $allowed = ['Queued','Processing','Ready','Failed'];
    $ns = $_GET['set_status']; $rid = (int)$_GET['report_id'];
    if (in_array($ns, $allowed) && $rid)
        $pdo->prepare("UPDATE report_queue SET status=? WHERE id=?")->execute([$ns, $rid]);
    header("Location: reports.php"); exit;
}
if (isset($_GET['delete_report'])) {
    $rid = (int)$_GET['delete_report'];
    if ($rid) $pdo->prepare("DELETE FROM report_queue WHERE id=?")->execute([$rid]);
    header("Location: reports.php"); exit;
}

/* ─────────────────────────────────────────────
   DATA QUERIES
───────────────────────────────────────────── */
$totalRegistrations = (int)$pdo->query("SELECT COUNT(*) FROM event_registrations")->fetchColumn();
$totalEvents        = (int)$pdo->query("SELECT COUNT(*) FROM event_settings")->fetchColumn();

$regThisMonth = (int)$pdo->query("
    SELECT COUNT(*) FROM event_registrations
    WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())
")->fetchColumn();

$regLastMonth = (int)$pdo->query("
    SELECT COUNT(*) FROM event_registrations
    WHERE MONTH(created_at)=MONTH(CURDATE()-INTERVAL 1 MONTH)
      AND YEAR(created_at)=YEAR(CURDATE()-INTERVAL 1 MONTH)
")->fetchColumn();

$momDelta = $regLastMonth > 0
    ? round((($regThisMonth - $regLastMonth) / $regLastMonth) * 100, 1)
    : ($regThisMonth > 0 ? 100 : 0);

$upcomingEvents = (int)$pdo->query("SELECT COUNT(*) FROM event_settings WHERE start_date>=CURDATE()")->fetchColumn();

$eventStats = $pdo->query("
    SELECT es.id, es.agenda, es.start_date, es.end_date, es.venue, COUNT(er.id) AS reg_count
    FROM event_settings es
    LEFT JOIN event_registrations er ON TRIM(er.agenda)=TRIM(es.agenda)
    GROUP BY es.id, es.agenda, es.start_date, es.end_date, es.venue
    ORDER BY es.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$monthlyTrend = $pdo->query("
    SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label,
           DATE_FORMAT(created_at,'%Y-%m') AS month_sort,
           COUNT(*) AS total
    FROM event_registrations
    WHERE created_at >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
    GROUP BY month_sort, month_label
    ORDER BY month_sort ASC
")->fetchAll(PDO::FETCH_ASSOC);

$topUnits = $pdo->query("
    SELECT unit_office, COUNT(*) AS cnt
    FROM event_registrations
    WHERE unit_office IS NOT NULL AND TRIM(unit_office)!=''
    GROUP BY unit_office ORDER BY cnt DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$maxUnitCount = !empty($topUnits) ? max(array_column($topUnits,'cnt')) : 1;
// WITH this:
$eventAgendas = $pdo->query("
    SELECT agenda, MIN(start_date) AS start_date, MAX(end_date) AS end_date
    FROM event_settings
    GROUP BY agenda
    ORDER BY agenda ASC
")->fetchAll(PDO::FETCH_ASSOC);

$reportQueue = [];
try {
    $reportQueue = $pdo->query("SELECT * FROM report_queue ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$qc = ['total'=>count($reportQueue),'ready'=>0,'processing'=>0,'queued'=>0,'failed'=>0];
foreach ($reportQueue as $r) { $k=strtolower($r['status']); if(isset($qc[$k])) $qc[$k]++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= nexusHead() ?>
    <style>
        /* ── TREND CHART ── */
        .trend-chart { display:flex; align-items:flex-end; gap:10px; height:120px; padding:8px 0 0; }
        .trend-bar-wrap { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; height:100%; justify-content:flex-end; }
        .trend-bar { width:100%; background:var(--accent); border-radius:4px 4px 0 0; min-height:4px; transition:height .4s ease; position:relative; cursor:pointer; }
        .trend-bar:hover { background:var(--accent-hi); }
        .trend-bar-val { position:absolute; top:-18px; left:50%; transform:translateX(-50%); font-family:var(--mono); font-size:9px; color:var(--accent-hi); white-space:nowrap; }
        .trend-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); text-align:center; white-space:nowrap; }

        /* ── UNIT BARS ── */
        .unit-bars { display:flex; flex-direction:column; gap:10px; margin-top:8px; }
        .unit-bar-row { display:flex; align-items:center; gap:10px; }
        .unit-bar-label { font-size:11px; color:var(--text-sec); width:180px; flex-shrink:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .unit-bar-track { flex:1; height:10px; background:var(--border); border-radius:6px; overflow:hidden; }
        .unit-bar-fill { height:100%; background:linear-gradient(90deg,var(--accent),var(--accent-hi)); border-radius:6px; transition:width .5s ease; }
        .unit-bar-count { font-family:var(--mono); font-size:11px; color:var(--text-dim); width:28px; text-align:right; flex-shrink:0; }

        .badge-count { display:inline-flex; align-items:center; justify-content:center; min-width:28px; padding:2px 8px; border-radius:20px; font-family:var(--mono); font-size:11px; font-weight:700; background:rgba(79,110,247,.12); color:var(--accent-hi); }
        .charts-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
        @media(max-width:900px){.charts-grid{grid-template-columns:1fr;}}
        .delta-pos{color:var(--success);} .delta-neg{color:var(--danger);}
        .section-heading { font-size:11px; font-weight:600; letter-spacing:.07em; text-transform:uppercase; color:var(--text-dim); margin:24px 0 10px; }

        /* ── STATUS PILLS ── */
        .queue-pills { display:flex; gap:8px; flex-wrap:wrap; }
        .q-pill { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-family:var(--mono); padding:3px 10px; border-radius:20px; font-weight:600; }
        .q-pill.ready      { background:rgba(34,197,94,.12); color:var(--success); }
        .q-pill.processing { background:rgba(234,179,8,.12); color:#ca8a04; }
        .q-pill.queued     { background:var(--bg2); color:var(--text-dim); border:1px solid var(--border); }
        .q-pill.failed     { background:rgba(239,68,68,.12); color:var(--danger); }
        .dot { width:6px; height:6px; border-radius:50%; display:inline-block; }
        .dot-ready{background:var(--success);} .dot-processing{background:#eab308;} .dot-queued{background:var(--text-dim);} .dot-failed{background:var(--danger);}

        /* ── STATUS BADGE ── */
        .status-badge { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; white-space:nowrap; }
        .status-ready      { background:rgba(34,197,94,.12); color:var(--success); }
        .status-processing { background:rgba(234,179,8,.12); color:#ca8a04; }
        .status-queued     { background:var(--bg2); color:var(--text-dim); border:1px solid var(--border); }
        .status-failed     { background:rgba(239,68,68,.12); color:var(--danger); }
        .shimmer-bar { display:inline-block; width:52px; height:4px; border-radius:2px; background:linear-gradient(90deg,var(--border) 25%,#eab308 50%,var(--border) 75%); background-size:200% 100%; animation:shimmer 1.4s infinite; }
        @keyframes shimmer{to{background-position:-200% 0;}}

        /* ── FILTERS ── */
        .rq-filters { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .rq-filters select, .rq-filters input { font-size:12px; font-family:var(--mono); background:var(--bg); border:1px solid var(--border-hi); border-radius:7px; color:var(--text-pri); padding:5px 10px; outline:none; }
        .rq-filters select:focus, .rq-filters input:focus { border-color:var(--accent); }

        /* ── ACTION DROPDOWN ── */
        .status-dd { position:relative; display:inline-block; }
        .status-dd .dd-menu { display:none; position:absolute; right:0; top:100%; margin-top:4px; background:var(--bg); border:1px solid var(--border-hi); border-radius:8px; z-index:200; min-width:148px; overflow:hidden; box-shadow:0 8px 24px rgba(0,0,0,.18); }
        .status-dd.open .dd-menu { display:block; }
        .dd-menu button { display:flex; align-items:center; gap:7px; width:100%; padding:7px 14px; font-size:12px; color:var(--text-sec); background:none; border:none; cursor:pointer; text-align:left; font-family:var(--mono); transition:background .1s; }
        .dd-menu button:hover { background:var(--bg2); color:var(--text-pri); }
        .dd-menu .dd-div { border-top:1px solid var(--border); margin:3px 0; }
        .dd-menu button.danger { color:var(--danger); }
        .dd-menu button.danger:hover { background:rgba(239,68,68,.08); }

        /* ── ROW FADE OUT ON DELETE ── */
        .row-removing { animation: rowFade .35s ease forwards; pointer-events:none; }
        @keyframes rowFade { to { opacity:0; transform:translateX(14px); } }

        /* ══════════════════════
           REPORT MODAL
        ══════════════════════ */
        .nx-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.52);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
        }
        .nx-modal-overlay.open { display: flex; }
        .nx-modal {
            background: var(--bg);
            border: 1px solid var(--border-hi);
            border-radius: 14px;
            width: 680px;
            max-width: calc(100vw - 32px);
            max-height: calc(100vh - 48px);
            overflow-y: auto;
            box-shadow: 0 32px 80px rgba(0,0,0,.4);
            animation: modalIn .18s cubic-bezier(.22,.68,0,1.2);
        }
        @keyframes modalIn { from{opacity:0;transform:translateY(-16px) scale(.97);} to{opacity:1;transform:translateY(0) scale(1);} }
        .nx-modal-header { display:flex; align-items:flex-start; justify-content:space-between; padding:20px 20px 16px; border-bottom:1px solid var(--border); position:sticky; top:0; background:var(--bg); z-index:1; }
        .nx-modal-title { font-size:15px; font-weight:600; color:var(--text-pri); }
        .nx-modal-sub   { font-size:12px; color:var(--text-dim); margin-top:3px; }
        .nx-modal-close { width:30px; height:30px; border:1px solid var(--border); background:none; border-radius:7px; cursor:pointer; color:var(--text-dim); font-size:17px; display:flex; align-items:center; justify-content:center; transition:background .15s,color .15s; flex-shrink:0; }
        .nx-modal-close:hover { background:var(--bg2); color:var(--text-pri); }
        .nx-modal-body { padding:20px; }
        .nx-modal-footer { display:flex; justify-content:flex-end; gap:8px; padding:14px 20px; border-top:1px solid var(--border); position:sticky; bottom:0; background:var(--bg); }
        .modal-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .modal-form-grid .full { grid-column:1/-1; }
        @media(max-width:600px){ .modal-form-grid{grid-template-columns:1fr;} .modal-form-grid .full{grid-column:1;} }
        .mfg label { display:block; font-size:11px; font-weight:600; color:var(--text-dim); text-transform:uppercase; letter-spacing:.05em; margin-bottom:5px; }
        .mfg label .req { color:var(--danger); }
        .mfg input, .mfg select, .mfg textarea { width:100%; padding:8px 11px; font-size:13px; font-family:var(--mono); background:var(--bg2); border:1px solid var(--border-hi); border-radius:7px; color:var(--text-pri); outline:none; transition:border .15s,box-shadow .15s; }
        .mfg input:focus, .mfg select:focus, .mfg textarea:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(79,110,247,.12); }
        .mfg input.field-error, .mfg select.field-error { border-color:var(--danger)!important; box-shadow:0 0 0 3px rgba(239,68,68,.1)!important; }
        .mfg textarea { resize:vertical; min-height:68px; }
        .modal-alert { display:flex; align-items:flex-start; gap:8px; padding:10px 13px; border-radius:8px; font-size:12px; margin-bottom:14px; }
        .modal-alert.success { background:rgba(34,197,94,.1); color:var(--success); border:1px solid rgba(34,197,94,.25); }
        .modal-alert.danger  { background:rgba(239,68,68,.08); color:var(--danger);  border:1px solid rgba(239,68,68,.2); }
        .modal-alert.hidden  { display:none; }
        @keyframes spin{to{transform:rotate(360deg);}}
        .spin-icon { display:inline-block; animation:spin .65s linear infinite; }

        /* ══════════════════════
           DELETE CONFIRM MODAL
        ══════════════════════ */
        .del-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.55);
            z-index: 1100;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }
        .del-modal-overlay.open { display: flex; }
        .del-modal {
            background: var(--bg);
            border: 1px solid var(--border-hi);
            border-radius: 14px;
            width: 420px;
            max-width: calc(100vw - 32px);
            box-shadow: 0 32px 80px rgba(0,0,0,.45);
            animation: modalIn .18s cubic-bezier(.22,.68,0,1.2);
            overflow: hidden;
        }
        .del-modal-top {
            padding: 28px 24px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 12px;
        }
        .del-modal-icon {
            width: 52px; height: 52px;
            border-radius: 50%;
            background: rgba(239,68,68,.1);
            border: 1px solid rgba(239,68,68,.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
            color: var(--danger);
        }
        .del-modal-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-pri);
            margin: 0;
        }
        .del-modal-body {
            font-size: 13px;
            color: var(--text-sec);
            line-height: 1.55;
            padding: 0 24px 6px;
            text-align: center;
        }
        .del-modal-name {
            display: inline-block;
            font-family: var(--mono);
            font-size: 12px;
            font-weight: 600;
            color: var(--text-pri);
            background: var(--bg2);
            border: 1px solid var(--border-hi);
            border-radius: 6px;
            padding: 3px 10px;
            margin-top: 6px;
            max-width: 340px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .del-modal-footer {
            display: flex;
            gap: 10px;
            padding: 16px 20px 20px;
            justify-content: center;
        }
        .del-modal-footer .btn { min-width: 120px; justify-content: center; }
        .btn-danger {
            background: var(--danger) !important;
            color: #fff !important;
            border-color: var(--danger) !important;
        }
        .btn-danger:hover { opacity: .88; }
        .btn-danger:disabled { opacity: .6; cursor:not-allowed; }
    </style>
</head>
<body>

<?= nexusSidebar() ?>

<div class="main">
    <?= nexusTopbar() ?>

    <div class="content">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>Analytics &amp; Reports</h1>
                <p>Platform-wide statistics &nbsp;·&nbsp; <?= date('F j, Y') ?></p>
            </div>
            <div class="header-actions">
                <button class="btn btn-ghost" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button class="btn btn-primary" onclick="openReportModal()">
                    <i class="fas fa-plus"></i> New Report
                </button>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="stats-grid">
            <div class="stat-card c1">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-label">Total Registrations</div>
                <div class="stat-value"><?= number_format($totalRegistrations) ?></div>
                <div class="stat-sub">All time</div>
            </div>
            <div class="stat-card c2">
                <div class="stat-icon"><i class="fas fa-calendar-days"></i></div>
                <div class="stat-label">Total Events</div>
                <div class="stat-value"><?= $totalEvents ?></div>
                <div class="stat-sub"><?= $upcomingEvents ?> upcoming</div>
            </div>
            <div class="stat-card c3">
                <div class="stat-icon"><i class="fas fa-user-plus"></i></div>
                <div class="stat-label">This Month</div>
                <div class="stat-value"><?= number_format($regThisMonth) ?></div>
                <div class="stat-sub <?= $momDelta >= 0 ? 'delta-pos' : 'delta-neg' ?>">
                    <?= $momDelta >= 0 ? '↑' : '↓' ?> <?= abs($momDelta) ?>% vs last month
                </div>
            </div>
            <div class="stat-card c4">
                <div class="stat-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="stat-label">Avg per Event</div>
                <div class="stat-value"><?= $totalEvents > 0 ? round($totalRegistrations / $totalEvents) : 0 ?></div>
                <div class="stat-sub">registrants / event</div>
            </div>
        </div>

        <!-- Charts row -->
        <div class="charts-grid">
            <div class="card">
                <div class="card-header">
                    <div><div class="card-title">Registration Trend</div><div class="card-subtitle">Last 6 months</div></div>
                </div>
                <div style="padding:0 16px 16px">
                    <?php if(empty($monthlyTrend)): ?>
                        <div class="empty-state"><i class="fas fa-chart-line"></i><p>No data yet</p></div>
                    <?php else: $maxTrend=max(array_column($monthlyTrend,'total'))?:1; ?>
                    <div class="trend-chart">
                        <?php foreach($monthlyTrend as $m): ?>
                        <div class="trend-bar-wrap">
                            <div class="trend-bar" style="height:<?= round(($m['total']/$maxTrend)*100) ?>%">
                                <span class="trend-bar-val"><?= $m['total'] ?></span>
                            </div>
                            <div class="trend-label"><?= htmlspecialchars($m['month_label']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div><div class="card-title">Top Units / Offices</div><div class="card-subtitle">By registration count</div></div>
                </div>
                <div style="padding:0 16px 16px">
                    <?php if(empty($topUnits)): ?>
                        <div class="empty-state"><i class="fas fa-building"></i><p>No data yet</p></div>
                    <?php else: ?>
                    <div class="unit-bars">
                        <?php foreach($topUnits as $u): ?>
                        <div class="unit-bar-row">
                            <div class="unit-bar-label" title="<?= htmlspecialchars($u['unit_office']) ?>"><?= htmlspecialchars($u['unit_office']) ?></div>
                            <div class="unit-bar-track"><div class="unit-bar-fill" style="width:<?= round(($u['cnt']/$maxUnitCount)*100) ?>%"></div></div>
                            <div class="unit-bar-count"><?= $u['cnt'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Event breakdown table -->
        <div class="card" style="margin-bottom:24px">
            <div class="card-header">
                <div>
                    <div class="card-title">Event Registration Breakdown</div>
                    <div class="card-subtitle"><?= count($eventStats) ?> event<?= count($eventStats)!=1?'s':'' ?> total</div>
                </div>
                <div class="card-tools">
                    <div class="filter-search" style="display:flex;align-items:center;gap:8px;background:var(--bg);border:1px solid var(--border-hi);border-radius:7px;padding:6px 12px">
                        <i class="fas fa-search" style="color:var(--text-dim);font-size:12px"></i>
                        <input type="text" id="eventSearch" placeholder="Search events..." oninput="filterEvents()"
                               style="background:none;border:none;outline:none;font-family:var(--mono);font-size:12px;color:var(--text-pri);width:180px">
                    </div>
                </div>
            </div>
            <div class="table-wrap">
                <table class="nx-table">
                    <thead>
                        <tr>
                            <th>#</th><th>Event / Agenda</th><th>Start Date</th><th>End Date</th><th>Venue</th><th>Registrants</th><th class="no-sort">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="eventTbody">
                        <?php if(empty($eventStats)): ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-calendar-xmark"></i><p>No events found.</p></div></td></tr>
                        <?php else: foreach($eventStats as $i=>$ev): ?>
                        <tr>
                            <td class="mono"><?= $i+1 ?></td>
                            <td class="td-main"><?= htmlspecialchars($ev['agenda']) ?></td>
                            <td class="mono"><?= $ev['start_date'] ?></td>
                            <td class="mono"><?= $ev['end_date'] ?></td>
                            <td><?= htmlspecialchars($ev['venue']??'—') ?></td>
                            <td><span class="badge-count"><?= $ev['reg_count'] ?></span></td>
                            <td>
<div class="row-actions">
    <a href="audience.php?event_id=<?= $ev['id'] ?>"
       class="act-btn view" title="View audience">
        <i class="fas fa-users"></i>
    </a>
    <a href="export_attendance_pdf.php?event_id=<?= $ev['id'] ?>"
       class="act-btn" title="Export PDF" target="_blank"
       style="color:var(--danger)">
        <i class="fas fa-file-pdf"></i>
    </a>
    <a href="export_attendance_excel.php?event_id=<?= $ev['id'] ?>"
       class="act-btn" title="Export Excel"
       style="color:var(--success)">
        <i class="fas fa-file-excel"></i>
    </a>
</div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                <span class="pagination-info" id="eventCount">Showing <?= count($eventStats) ?> event<?= count($eventStats)!=1?'s':'' ?></span>
            </div>
        </div>

        <!-- ═══════════════ REPORT QUEUE ═══════════════ -->
        <div class="section-heading">
            <i class="fas fa-layer-group" style="margin-right:6px"></i> Report Queue
        </div>

        <div class="card">
            <div class="card-header" style="flex-wrap:wrap;gap:10px">
                <div>
                    <div class="card-title">Generated Reports</div>
                    <div class="card-subtitle"><?= $qc['total'] ?> report<?= $qc['total']!=1?'s':'' ?> total</div>
                </div>
                <div class="queue-pills">
                    <span class="q-pill ready">    <span class="dot dot-ready"></span><span id="cnt-ready"><?= $qc['ready'] ?></span>&nbsp;Ready</span>
                    <span class="q-pill processing"><span class="dot dot-processing"></span><span id="cnt-processing"><?= $qc['processing'] ?></span>&nbsp;Processing</span>
                    <span class="q-pill queued">   <span class="dot dot-queued"></span><span id="cnt-queued"><?= $qc['queued'] ?></span>&nbsp;Queued</span>
                    <span class="q-pill failed">   <span class="dot dot-failed"></span><span id="cnt-failed"><?= $qc['failed'] ?></span>&nbsp;Failed</span>
                </div>
                <div class="rq-filters" style="margin-left:auto">
                    <select id="rqStatus" onchange="filterQueue()">
                        <option value="">All statuses</option>
                        <option>Ready</option><option>Processing</option><option>Queued</option><option>Failed</option>
                    </select>
                    <select id="rqType" onchange="filterQueue()">
                        <option value="">All types</option>
                        <option>Attendance</option><option>Registration</option><option>Demographics</option><option>Unit Summary</option><option>Custom</option>
                    </select>
                    <input type="text" id="rqSearch" placeholder="Search reports…" oninput="filterQueue()" style="width:180px">
                </div>
            </div>

            <div class="table-wrap">
                <table class="nx-table">
                    <thead>
                        <tr>
                            <th style="width:36px">#</th>
                            <th>Report Name</th>
                            <th style="width:110px">Type</th>
                            <th>Event / Agenda</th>
                            <th style="width:100px">Date From</th>
                            <th style="width:100px">Date To</th>
                            <th style="width:90px">By</th>
                            <th style="width:68px">Format</th>
                            <th style="width:122px">Status</th>
                            <th style="width:90px">Created</th>
                            <th class="no-sort" style="width:76px">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="queueTbody">
                        <?php if(empty($reportQueue)): ?>
                        <tr id="emptyRow"><td colspan="11">
                            <div class="empty-state">
                                <i class="fas fa-file-circle-xmark"></i>
                                <p>No reports yet. Click <strong>New Report</strong> to get started.</p>
                            </div>
                        </td></tr>
                        <?php else: foreach($reportQueue as $i=>$rq):
                            $sKey    = strtolower($rq['status']);
                            $created = date('M j, Y', strtotime($rq['created_at']));
                        ?>
                        <tr id="row-<?= $rq['id'] ?>"
                            data-status="<?= htmlspecialchars($rq['status']) ?>"
                            data-type="<?= htmlspecialchars($rq['report_type']) ?>"
                            data-name="<?= htmlspecialchars($rq['report_name']) ?>">
                            <td class="mono row-num"><?= $i+1 ?></td>
                            <td class="td-main">
                                <?= htmlspecialchars($rq['report_name']) ?>
                                <?php if($rq['notes']): ?>
                                <div class="mono" style="font-size:10px;color:var(--text-dim);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px"
                                     title="<?= htmlspecialchars($rq['notes']) ?>"><?= htmlspecialchars($rq['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="mono" style="font-size:11px"><?= htmlspecialchars($rq['report_type']) ?></td>
                            <td style="font-size:12px;color:var(--text-sec);max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                                title="<?= htmlspecialchars($rq['event_agenda']) ?>"><?= htmlspecialchars($rq['event_agenda']) ?></td>
                            <td class="mono"><?= htmlspecialchars($rq['date_from']) ?></td>
                            <td class="mono"><?= htmlspecialchars($rq['date_to']) ?></td>
                            <td class="mono" style="font-size:11px"><?= htmlspecialchars($rq['requested_by']?:'—') ?></td>
                            <td class="mono" style="font-size:11px"><?= htmlspecialchars($rq['output_format']) ?></td>
                            <td class="status-cell">
                                <?php if($sKey==='processing'): ?>
                                    <span class="status-badge status-processing"><span class="shimmer-bar"></span> Processing</span>
                                <?php else: ?>
                                    <span class="status-badge status-<?= $sKey ?>"><?= htmlspecialchars($rq['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="mono" style="font-size:10px;color:var(--text-dim)"><?= $created ?></td>
                            <td>
                                <div class="row-actions">
                                    <?php if($sKey==='ready'): ?>
                                    <a href="export_report.php?id=<?= $rq['id'] ?>"
                                       class="act-btn dl-btn" title="Download <?= htmlspecialchars($rq['output_format']) ?>"
                                       style="color:var(--success)"
                                       onclick="nxToast('Preparing <?= htmlspecialchars($rq['output_format']) ?> download…','info')">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <?php endif; ?>
                                    <div class="status-dd" id="dd-<?= $rq['id'] ?>">
                                        <button class="act-btn" title="Actions" onclick="toggleDd(<?= $rq['id'] ?>,event)">
                                            <i class="fas fa-ellipsis-vertical"></i>
                                        </button>
                                        <div class="dd-menu">
                                            <button onclick="setStatus(<?= $rq['id'] ?>,'Queued')">
                                                <i class="fas fa-clock" style="width:12px;color:var(--text-dim)"></i> Set Queued
                                            </button>
                                            <button onclick="setStatus(<?= $rq['id'] ?>,'Processing')">
                                                <i class="fas fa-spinner" style="width:12px;color:#ca8a04"></i> Set Processing
                                            </button>
                                            <button onclick="setStatus(<?= $rq['id'] ?>,'Ready')">
                                                <i class="fas fa-circle-check" style="width:12px;color:var(--success)"></i> Set Ready
                                            </button>
                                            <button onclick="setStatus(<?= $rq['id'] ?>,'Failed')">
                                                <i class="fas fa-circle-xmark" style="width:12px;color:var(--danger)"></i> Set Failed
                                            </button>
                                            <div class="dd-div"></div>
                                            <button class="danger" onclick="openDeleteModal(<?= $rq['id'] ?>)">
                                                <i class="fas fa-trash-can" style="width:12px"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                <span class="pagination-info" id="queueCount">Showing <?= $qc['total'] ?> report<?= $qc['total']!=1?'s':'' ?></span>
            </div>
        </div>

    </div><!-- /content -->
    <?= nexusFooter() ?>
</div><!-- /main -->

<!-- ══════════════════════════════════════
     CREATE REPORT MODAL
══════════════════════════════════════ -->
<div class="nx-modal-overlay" id="reportModal" onclick="overlayClick(event)">
    <div class="nx-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="nx-modal-header">
            <div>
                <div class="nx-modal-title" id="modalTitle">
                    <i class="fas fa-file-plus" style="margin-right:8px;color:var(--accent)"></i>Create New Report
                </div>
                <div class="nx-modal-sub">Fill in the details to queue a report for generation</div>
            </div>
            <button class="nx-modal-close" onclick="closeReportModal()" aria-label="Close">&times;</button>
        </div>
        <div class="nx-modal-body">
            <div class="modal-alert hidden" id="modalAlert">
                <i class="fas fa-circle-exclamation" style="flex-shrink:0;margin-top:1px"></i>
                <div id="modalAlertMsg"></div>
            </div>
            <form id="reportModalForm" novalidate>
                <div class="modal-form-grid">
                    <div class="mfg">
                        <label>Report Name <span class="req">*</span></label>
                        <input type="text" name="report_name" id="f-name" placeholder="e.g. Q2 Attendance Summary">
                    </div>
                    <div class="mfg">
                        <label>Report Type <span class="req">*</span></label>
                        <select name="report_type" id="f-type">
                            <option value="">— Select type —</option>
                            <option>Attendance</option><option>Registration</option><option>Demographics</option><option>Unit Summary</option><option>Custom</option>
                        </select>
                    </div>
                    <div class="mfg">
                        <label>Event / Agenda <span class="req">*</span></label>
                        <input type="text" name="report_event" id="f-event" placeholder="e.g. Leadership Forum 2025" list="agendaList">
                        <datalist id="agendaList">
                            <?php foreach($eventAgendas as $ag): ?>
                            <option value="<?= htmlspecialchars($ag['agenda']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="mfg">
                        <label>Requested By</label>
                        <input type="text" name="requested_by" id="f-by" placeholder="e.g. admin">
                    </div>
                    <div class="mfg">
                        <label>Date From <span class="req">*</span></label>
                        <input type="date" name="date_from" id="f-from">
                    </div>
                    <div class="mfg">
                        <label>Date To <span class="req">*</span></label>
                        <input type="date" name="date_to" id="f-to">
                    </div>
                    <div class="mfg">
                        <label>Output Format</label>
                        <select name="output_format" id="f-format">
                            <option value="PDF">PDF</option>
                            <option value="Excel">Excel</option>
                            <option value="CSV">CSV</option>
                        </select>
                    </div>
                    <div class="mfg">
                        <label>Initial Status</label>
                        <select name="status" id="f-status">
                            <option value="Queued">Queued</option>
                            <option value="Processing">Processing</option>
                            <option value="Ready">Ready</option>
                            <option value="Failed">Failed</option>
                        </select>
                    </div>
                    <div class="mfg full">
                        <label>Notes</label>
                        <textarea name="notes" id="f-notes" placeholder="Optional filters, scope, or instructions…"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="nx-modal-footer">
            <button class="btn btn-ghost" type="button" onclick="closeReportModal()">Cancel</button>
            <button class="btn btn-primary" type="button" id="submitBtn" onclick="submitReport()">
                <span id="btnLabel"><i class="fas fa-plus"></i> Generate Report</span>
                <span id="btnSpinner" style="display:none"><i class="fas fa-circle-notch spin-icon"></i> Saving…</span>
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════
     DELETE CONFIRMATION MODAL
══════════════════════════════════════ -->
<div class="del-modal-overlay" id="deleteModal" onclick="delOverlayClick(event)">
    <div class="del-modal" role="alertdialog" aria-modal="true" aria-labelledby="delModalTitle">

        <div class="del-modal-top">
            <div class="del-modal-icon">
                <i class="fas fa-trash-can"></i>
            </div>
            <p class="del-modal-title" id="delModalTitle">Delete Report?</p>
        </div>

        <div class="del-modal-body">
            <p>This action is permanent and cannot be undone. The following report will be removed from the queue.</p>
            <span class="del-modal-name" id="delModalName">—</span>
        </div>

        <div class="del-modal-footer">
            <button class="btn btn-ghost" id="delCancelBtn" onclick="closeDeleteModal()">
                <i class="fas fa-xmark"></i> Cancel
            </button>
            <button class="btn btn-danger" id="delConfirmBtn" onclick="confirmDelete()">
                <span id="delBtnLabel"><i class="fas fa-trash-can"></i> Yes, Delete</span>
                <span id="delBtnSpinner" style="display:none"><i class="fas fa-circle-notch spin-icon"></i> Deleting…</span>
            </button>
        </div>
    </div>
</div>

<?= nexusToast() ?>
<?= nexusJS() ?>

<script>
    /* ══════════════════════════════════════════
   AGENDA → DATE AUTO-FILL
══════════════════════════════════════════ */
const AGENDA_DATES = {
<?php foreach($eventAgendas as $ag): ?>
    <?= json_encode($ag['agenda']) ?>: {
        from: <?= json_encode($ag['start_date']) ?>,
        to:   <?= json_encode($ag['end_date'])   ?>
    },
<?php endforeach; ?>
};

document.getElementById('f-event').addEventListener('input', function() {
    const entry = AGENDA_DATES[this.value.trim()];
    if (entry) {
        document.getElementById('f-from').value = entry.from;
        document.getElementById('f-to').value   = entry.to;
        // Clear any date validation errors since they're now filled
        document.getElementById('f-from').classList.remove('field-error');
        document.getElementById('f-to').classList.remove('field-error');
    }
});

// Also fire on 'change' to catch datalist selection via mouse click
document.getElementById('f-event').addEventListener('change', function() {
    const entry = AGENDA_DATES[this.value.trim()];
    if (entry) {
        document.getElementById('f-from').value = entry.from;
        document.getElementById('f-to').value   = entry.to;
        document.getElementById('f-from').classList.remove('field-error');
        document.getElementById('f-to').classList.remove('field-error');
    }
});
/* ══════════════════════════════════════════
   NEXUS TOAST  (uses native nexusShowToast
   if available, self-contained fallback otherwise)
══════════════════════════════════════════ */
function nxToast(msg, type = 'success', duration = 3500) {
    // Try native Nexus toast first
    if (typeof nexusShowToast === 'function') {
        nexusShowToast(msg, type);
        return;
    }
    // Try nx-show-toast custom event (some Nexus builds)
    const evt = new CustomEvent('nx-toast', { detail: { msg, type, duration } });
    if (document.dispatchEvent(evt) === false) return;

    // Self-contained fallback — matches Nexus card/border aesthetic
    let stack = document.getElementById('_nx_toast_stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.id = '_nx_toast_stack';
        Object.assign(stack.style, {
            position:'fixed', bottom:'24px', right:'24px',
            zIndex:'9999', display:'flex', flexDirection:'column-reverse',
            gap:'10px', pointerEvents:'none'
        });
        document.body.appendChild(stack);
    }

    const ICONS = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info', danger:'fa-circle-xmark' };
    const COLORS = {
        success: { border:'rgba(34,197,94,.35)',  icon:'rgba(34,197,94,.15)',  text:'var(--success)',   bar:'var(--success)' },
        error:   { border:'rgba(239,68,68,.35)',   icon:'rgba(239,68,68,.12)',  text:'var(--danger)',    bar:'var(--danger)'  },
        danger:  { border:'rgba(239,68,68,.35)',   icon:'rgba(239,68,68,.12)',  text:'var(--danger)',    bar:'var(--danger)'  },
        warning: { border:'rgba(234,179,8,.35)',   icon:'rgba(234,179,8,.12)',  text:'#ca8a04',          bar:'#eab308'        },
        info:    { border:'rgba(79,110,247,.35)',  icon:'rgba(79,110,247,.12)', text:'var(--accent-hi)', bar:'var(--accent)'  },
    };
    const c = COLORS[type] || COLORS.info;
    const ic = ICONS[type]  || ICONS.info;

    const el = document.createElement('div');
    el.style.cssText = `
        display:flex;align-items:flex-start;gap:10px;
        min-width:260px;max-width:360px;padding:11px 13px;
        border-radius:10px;font-size:13px;font-family:var(--mono);
        pointer-events:all;background:var(--bg);
        border:1px solid ${c.border};color:var(--text-pri);
        box-shadow:0 8px 28px rgba(0,0,0,.22);
        position:relative;overflow:hidden;
        animation:_nxTIn .2s cubic-bezier(.22,.68,0,1.2);
    `;
    if (!document.getElementById('_nx_toast_kf')) {
        const s = document.createElement('style');
        s.id = '_nx_toast_kf';
        s.textContent = `
            @keyframes _nxTIn{from{opacity:0;transform:translateY(12px) scale(.95)}to{opacity:1;transform:none}}
            @keyframes _nxTOut{to{opacity:0;transform:translateY(8px) scale(.96)}}
            @keyframes _nxTBar{from{width:100%}to{width:0}}
        `;
        document.head.appendChild(s);
    }
    el.innerHTML = `
        <div style="width:20px;height:20px;border-radius:50%;background:${c.icon};color:${c.text};display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;margin-top:1px">
            <i class="fas ${ic}"></i>
        </div>
        <div style="flex:1;line-height:1.45;font-size:12px;color:var(--text-sec)">${msg}</div>
        <button onclick="this.closest('div[style]').remove()" style="border:none;background:none;cursor:pointer;color:var(--text-dim);font-size:14px;padding:0;width:16px;height:16px;display:flex;align-items:center;justify-content:center;flex-shrink:0">&times;</button>
        <div style="position:absolute;bottom:0;left:0;height:2px;background:${c.bar};border-radius:0 0 10px 10px;animation:_nxTBar ${duration}ms linear forwards"></div>
    `;
    stack.prepend(el);

    const tid = setTimeout(() => {
        el.style.animation = '_nxTOut .2s ease forwards';
        setTimeout(() => el.remove(), 210);
    }, duration);
    el._tid = tid;

    // max 5 toasts
    const all = stack.querySelectorAll('div[style]');
    if (all.length > 5) { clearTimeout(all[all.length-1]._tid); all[all.length-1].remove(); }
}

/* ══════════════════════════════════════════
   CREATE REPORT MODAL
══════════════════════════════════════════ */
function openReportModal() {
    document.getElementById('reportModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('f-name').focus(), 120);
}
function closeReportModal() {
    document.getElementById('reportModal').classList.remove('open');
    document.body.style.overflow = '';
    document.getElementById('reportModalForm').reset();
    clearAlert(); clearFieldErrors();
}
function overlayClick(e) { if (e.target === document.getElementById('reportModal')) closeReportModal(); }
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (document.getElementById('deleteModal').classList.contains('open')) closeDeleteModal();
        else closeReportModal();
    }
});

function showAlert(msgs, type = 'danger') {
    const box = document.getElementById('modalAlert');
    box.className = 'modal-alert ' + type;
    document.getElementById('modalAlertMsg').innerHTML = Array.isArray(msgs)
        ? msgs.map(m => `<div>${m}</div>`).join('') : msgs;
}
function clearAlert()       { document.getElementById('modalAlert').className = 'modal-alert hidden'; }
function clearFieldErrors() { document.querySelectorAll('#reportModalForm .field-error').forEach(el => el.classList.remove('field-error')); }
function markError(id)      { document.getElementById(id).classList.add('field-error'); }

function submitReport() {
    clearAlert(); clearFieldErrors();
    const name  = document.getElementById('f-name').value.trim();
    const type  = document.getElementById('f-type').value;
    const event = document.getElementById('f-event').value.trim();
    const from  = document.getElementById('f-from').value;
    const to    = document.getElementById('f-to').value;

    const errs = [];
    if (!name)  { errs.push('Report name is required.');    markError('f-name'); }
    if (!type)  { errs.push('Report type is required.');    markError('f-type'); }
    if (!event) { errs.push('Event / Agenda is required.'); markError('f-event'); }
    if (!from)  { errs.push('Date From is required.');      markError('f-from'); }
    if (!to)    { errs.push('Date To is required.');        markError('f-to'); }
    if (from && to && to < from) { errs.push('Date To cannot be earlier than Date From.'); markError('f-to'); }
    if (errs.length) { showAlert(errs); return; }

    document.getElementById('btnLabel').style.display   = 'none';
    document.getElementById('btnSpinner').style.display = 'inline-flex';
    document.getElementById('submitBtn').disabled       = true;

    const fd = new FormData(document.getElementById('reportModalForm'));
    fd.append('create_report', '1');

    fetch('reports.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            closeReportModal();
            nxToast(res.message, 'success');
            setTimeout(() => location.reload(), 900);
        } else {
            showAlert(res.errors || ['An error occurred.']);
        }
    })
    .catch(() => showAlert(['Network error. Please try again.']))
    .finally(() => {
        document.getElementById('btnLabel').style.display   = 'inline-flex';
        document.getElementById('btnSpinner').style.display = 'none';
        document.getElementById('submitBtn').disabled       = false;
    });
}

/* ══════════════════════════════════════════
   DELETE CONFIRMATION MODAL
══════════════════════════════════════════ */
let _pendingDeleteId = null;

function openDeleteModal(id) {
    // Close any open dropdown first
    document.querySelectorAll('.status-dd.open').forEach(el => el.classList.remove('open'));

    _pendingDeleteId = id;
    const row  = document.getElementById('row-' + id);
    const name = row ? (row.dataset.name || 'this report') : 'this report';

    document.getElementById('delModalName').textContent = name;
    document.getElementById('delBtnLabel').style.display   = 'inline-flex';
    document.getElementById('delBtnSpinner').style.display = 'none';
    document.getElementById('delConfirmBtn').disabled      = false;
    document.getElementById('delCancelBtn').disabled       = false;

    document.getElementById('deleteModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('delConfirmBtn').focus(), 120);
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    document.body.style.overflow = '';
    _pendingDeleteId = null;
}
function delOverlayClick(e) {
    if (e.target === document.getElementById('deleteModal')) closeDeleteModal();
}

function confirmDelete() {
    const id = _pendingDeleteId;
    if (!id) return;

    document.getElementById('delBtnLabel').style.display   = 'none';
    document.getElementById('delBtnSpinner').style.display = 'inline-flex';
    document.getElementById('delConfirmBtn').disabled      = true;
    document.getElementById('delCancelBtn').disabled       = true;

    fetch(`reports.php?delete_report=${id}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        closeDeleteModal();
        if (res.success) {
            const row = document.getElementById('row-' + id);
            if (row) {
                row.classList.add('row-removing');
                setTimeout(() => {
                    row.remove();
                    renumberRows();
                    recountPills();
                    const tbody = document.getElementById('queueTbody');
                    if (!tbody.querySelector('tr[id^="row-"]')) {
                        tbody.innerHTML = `<tr id="emptyRow"><td colspan="11">
                            <div class="empty-state">
                                <i class="fas fa-file-circle-xmark"></i>
                                <p>No reports yet. Click <strong>New Report</strong> to get started.</p>
                            </div>
                        </td></tr>`;
                    }
                }, 370);
            }
            nxToast(res.message, 'error');
        } else {
            nxToast(res.message || 'Failed to delete report.', 'error');
        }
    })
    .catch(() => {
        closeDeleteModal();
        nxToast('Network error. Please try again.', 'error');
    });
}

/* ══════════════════════════════════════════
   DROPDOWN TOGGLE
══════════════════════════════════════════ */
function toggleDd(id, e) {
    e.stopPropagation();
    const dd = document.getElementById('dd-' + id);
    const isOpen = dd.classList.contains('open');
    document.querySelectorAll('.status-dd.open').forEach(el => el.classList.remove('open'));
    if (!isOpen) dd.classList.add('open');
}
document.addEventListener('click', () => {
    document.querySelectorAll('.status-dd.open').forEach(el => el.classList.remove('open'));
});

/* ══════════════════════════════════════════
   STATUS CHANGE (AJAX, no reload)
══════════════════════════════════════════ */
const STATUS_BADGE_HTML = {
    Ready:      `<span class="status-badge status-ready"><i class="fas fa-circle-check" style="font-size:9px"></i> Ready</span>`,
    Processing: `<span class="status-badge status-processing"><span class="shimmer-bar"></span> Processing</span>`,
    Queued:     `<span class="status-badge status-queued"><i class="fas fa-clock" style="font-size:9px"></i> Queued</span>`,
    Failed:     `<span class="status-badge status-failed"><i class="fas fa-circle-xmark" style="font-size:9px"></i> Failed</span>`,
};
const STATUS_TOAST = { Ready:'success', Processing:'warning', Queued:'info', Failed:'error' };

function setStatus(id, newStatus) {
    document.querySelectorAll('.status-dd.open').forEach(el => el.classList.remove('open'));

    fetch(`reports.php?set_status=${encodeURIComponent(newStatus)}&report_id=${id}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            const row = document.getElementById('row-' + id);
            if (row) {
                const cell = row.querySelector('.status-cell');
                if (cell) cell.innerHTML = STATUS_BADGE_HTML[newStatus] || newStatus;
                row.dataset.status = newStatus;

                // Show / hide download button
                const actions    = row.querySelector('.row-actions');
                const existingDl = actions.querySelector('.dl-btn');
                if (newStatus === 'Ready' && !existingDl) {
                    const dl = document.createElement('a');
                    dl.href      = `export_report.php?id=${id}`;
                    dl.className = 'act-btn dl-btn';
                    dl.title     = 'Download';
                    dl.style.color = 'var(--success)';
                    dl.innerHTML = '<i class="fas fa-download"></i>';
                    dl.onclick   = () => nxToast('Preparing download…', 'info');
                    actions.insertBefore(dl, actions.firstChild);
                } else if (newStatus !== 'Ready' && existingDl) {
                    existingDl.remove();
                }

                // Brief row highlight
                row.style.transition = 'background .25s';
                row.style.background = 'rgba(79,110,247,.07)';
                setTimeout(() => row.style.background = '', 700);
            }
            recountPills();
            nxToast(`Status set to ${newStatus}.`, STATUS_TOAST[newStatus] || 'info');
        } else {
            nxToast(res.message || 'Failed to update status.', 'error');
        }
    })
    .catch(() => nxToast('Network error. Please try again.', 'error'));
}

/* ── Renumber rows ── */
function renumberRows() {
    document.querySelectorAll('#queueTbody tr[id^="row-"] .row-num').forEach((cell, i) => {
        cell.textContent = i + 1;
    });
}

/* ── Recount status pills ── */
function recountPills() {
    const counts = { ready:0, processing:0, queued:0, failed:0 };
    document.querySelectorAll('#queueTbody tr[data-status]').forEach(row => {
        const k = row.dataset.status.toLowerCase();
        if (counts[k] !== undefined) counts[k]++;
    });
    const total = Object.values(counts).reduce((a,b) => a+b, 0);
    ['ready','processing','queued','failed'].forEach(k => {
        const el = document.getElementById('cnt-' + k);
        if (el) el.textContent = counts[k];
    });
    document.getElementById('queueCount').textContent = `Showing ${total} report${total!==1?'s':''}`;
}

/* ══════════════════════════════════════════
   FILTERS
══════════════════════════════════════════ */
function filterEvents() {
    const q = document.getElementById('eventSearch').value.toLowerCase();
    let vis = 0;
    document.querySelectorAll('#eventTbody tr').forEach(r => {
        const show = !q || r.textContent.toLowerCase().includes(q);
        r.style.display = show ? '' : 'none'; if(show) vis++;
    });
    document.getElementById('eventCount').textContent = `Showing ${vis} event${vis!==1?'s':''}`;
}

function filterQueue() {
    const q  = document.getElementById('rqSearch').value.toLowerCase();
    const st = document.getElementById('rqStatus').value;
    const ty = document.getElementById('rqType').value;
    let vis  = 0;
    document.querySelectorAll('#queueTbody tr[data-status]').forEach(r => {
        const ok = (!st||r.dataset.status===st) && (!ty||r.dataset.type===ty) && (!q||r.textContent.toLowerCase().includes(q));
        r.style.display = ok ? '' : 'none'; if(ok) vis++;
    });
    document.getElementById('queueCount').textContent = `Showing ${vis} report${vis!==1?'s':''}`;
}
</script>
</body>
</html>