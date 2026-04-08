<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}
require '../config/db.php';

$activePage   = 'audience';
$pageTitle    = 'Audience';
$pageSubtitle = 'Audience List';
$docTitle     = 'Nexus Platform';

require '_sidebar.php';

$stmt   = $pdo->query("SELECT * FROM event_settings ORDER BY start_date DESC");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$preselectedEventId = (int)($_GET['event_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= nexusHead() ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.6.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>

    <style>
        /* ── FILTERS ── */
        .filter-card { background:var(--panel); border:1px solid var(--border-hi); border-radius:var(--radius); padding:20px 24px; margin-bottom:16px; }
        .filter-row  { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; }
        .filter-group { display:flex; flex-direction:column; gap:6px; }
        .filter-group label { font-family:var(--mono); font-size:10px; color:var(--text-dim); text-transform:uppercase; letter-spacing:.1em; }
        .filter-group select,
        .filter-group input[type="date"] { background:var(--bg); border:1px solid var(--border-hi); border-radius:8px; padding:8px 12px; font-family:var(--mono); font-size:12px; color:var(--text-pri); outline:none; transition:border-color .2s; min-width:180px; }
        .filter-group select:focus, .filter-group input:focus { border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-glow); }
        .event-preview { display:none; align-items:center; gap:10px; padding:8px 14px; background:rgba(79,110,247,.07); border:1px solid rgba(79,110,247,.2); border-radius:8px; font-family:var(--mono); font-size:11px; color:var(--text-sec); flex-wrap:wrap; margin-top:14px; }
        .event-preview.visible { display:flex; }
        .event-preview strong { color:var(--accent-hi); }

        /* ── TABLE ── */
        .tbl-search { display:flex; align-items:center; gap:8px; background:var(--bg); border:1px solid var(--border-hi); border-radius:7px; padding:6px 12px; }
        .tbl-search input { background:none; border:none; outline:none; font-family:var(--mono); font-size:12px; color:var(--text-pri); width:200px; }
        .tbl-search input::placeholder { color:var(--text-dim); }
        .tbl-search i { color:var(--text-dim); font-size:12px; }
        table.aud-table { width:100%; border-collapse:collapse; }
        table.aud-table thead th { font-family:var(--mono); font-size:10px; color:var(--text-dim); text-transform:uppercase; letter-spacing:.1em; padding:10px 16px; background:var(--bg); border-bottom:1px solid var(--border); text-align:left; white-space:nowrap; cursor:pointer; user-select:none; }
        table.aud-table thead th .sort-icon { margin-left:4px; opacity:.4; }
        table.aud-table thead th.sorted-asc  .sort-icon,
        table.aud-table thead th.sorted-desc .sort-icon { opacity:1; color:var(--accent-hi); }
        table.aud-table tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
        table.aud-table tbody tr:last-child { border-bottom:none; }
        table.aud-table tbody tr:hover { background:rgba(79,110,247,.04); }
        table.aud-table tbody td { padding:12px 16px; font-size:13px; color:var(--text-sec); vertical-align:middle; }
        table.aud-table tbody td.td-main { color:var(--text-pri); font-weight:600; }
        table.aud-table tbody td.mono    { font-family:var(--mono); font-size:12px; }
        .avatar-cell { display:flex; align-items:center; gap:10px; }
        .row-avatar  { width:32px; height:32px; border-radius:8px; display:grid; place-items:center; font-size:12px; font-weight:700; color:#fff; flex-shrink:0; }
        .tbl-loading { display:none; padding:48px 0; text-align:center; }
        .tbl-loading.active { display:block; }
        .spinner { width:32px; height:32px; border:3px solid var(--border-hi); border-top-color:var(--accent); border-radius:50%; animation:spin .7s linear infinite; margin:0 auto 12px; }
        .tbl-loading p { font-family:var(--mono); font-size:12px; color:var(--text-dim); }
        @keyframes spin { to{transform:rotate(360deg);} }

        /* ── EXPORT DROPDOWN ── */
        .export-dropdown { position:relative; }
        .export-menu { display:none; position:absolute; top:calc(100% + 6px); right:0; background:var(--panel-hi); border:1px solid var(--border-hi); border-radius:8px; overflow:hidden; min-width:180px; box-shadow:0 8px 24px rgba(0,0,0,.4); z-index:50; }
        .export-menu.open { display:block; }
        .export-item { display:flex; align-items:center; gap:10px; padding:10px 16px; font-family:var(--mono); font-size:12px; color:var(--text-sec); cursor:pointer; transition:all .15s; border:none; background:none; width:100%; }
        .export-item:hover { background:rgba(79,110,247,.08); color:var(--text-pri); }

        /* ── ROW ACTIONS ── */
        .row-actions { display:flex; gap:4px; align-items:center; }
        .act-btn { width:28px; height:28px; border-radius:6px; border:1px solid var(--border-hi); background:none; cursor:pointer; display:grid; place-items:center; font-size:11px; transition:all .15s; color:var(--text-dim); }
        .act-btn:hover { transform:scale(1.08); }
        .act-btn.view   { color:var(--accent-hi); border-color:rgba(79,110,247,.25); }
        .act-btn.view:hover { background:rgba(79,110,247,.12); border-color:var(--accent); }
        .act-btn.edit   { color:#f5a623; border-color:rgba(245,166,35,.25); }
        .act-btn.edit:hover { background:rgba(245,166,35,.12); border-color:#f5a623; }
        .act-btn.toggle-on  { color:var(--success); border-color:rgba(52,211,153,.25); }
        .act-btn.toggle-on:hover  { background:rgba(52,211,153,.12); border-color:var(--success); }
        .act-btn.toggle-off { color:var(--text-dim); border-color:var(--border-hi); }
        .act-btn.toggle-off:hover { background:rgba(255,255,255,.05); border-color:var(--border-hi); }
        .act-btn.del    { color:var(--danger); border-color:rgba(224,92,106,.25); }
        .act-btn.del:hover { background:rgba(224,92,106,.12); border-color:var(--danger); }

        /* ── STATUS BADGE ── */
        .badge-active   { background:rgba(52,211,153,.1); color:var(--success); border:1px solid rgba(52,211,153,.25); font-family:var(--mono); font-size:10px; padding:2px 8px; border-radius:20px; white-space:nowrap; }
        .badge-inactive { background:rgba(69,77,102,.15); color:var(--text-dim); border:1px solid var(--border); font-family:var(--mono); font-size:10px; padding:2px 8px; border-radius:20px; white-space:nowrap; }

        /* ══════════════════════════════
           SHARED MODAL BASE
        ══════════════════════════════ */
        .aud-overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.55); z-index:900;
            align-items:center; justify-content:center;
            backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);
        }
        .aud-overlay.open { display:flex; }
        .aud-modal {
            background:var(--bg); border:1px solid var(--border-hi);
            border-radius:14px; width:600px; max-width:calc(100vw - 32px);
            max-height:calc(100vh - 48px); overflow-y:auto;
            box-shadow:0 32px 80px rgba(0,0,0,.45);
            animation:audIn .18s cubic-bezier(.22,.68,0,1.2);
        }
        .aud-modal.sm { width:420px; }
        @keyframes audIn { from{opacity:0;transform:translateY(-14px) scale(.97);} to{opacity:1;transform:translateY(0) scale(1);} }
        .aud-mhdr {
            display:flex; align-items:flex-start; justify-content:space-between;
            padding:18px 22px 14px; border-bottom:1px solid var(--border);
            position:sticky; top:0; background:var(--bg); z-index:1;
        }
        .aud-mtitle { font-size:15px; font-weight:700; color:var(--text-pri); display:flex; align-items:center; gap:8px; }
        .aud-msub   { font-size:12px; color:var(--text-dim); margin-top:3px; font-family:var(--mono); }
        .aud-mclose { width:30px; height:30px; border:1px solid var(--border); background:none; border-radius:7px; cursor:pointer; color:var(--text-dim); font-size:17px; display:flex; align-items:center; justify-content:center; transition:all .15s; flex-shrink:0; }
        .aud-mclose:hover { background:var(--panel); color:var(--text-pri); }
        .aud-mbody  { padding:20px 22px; }
        .aud-mfoot  { display:flex; justify-content:flex-end; gap:8px; padding:14px 22px; border-top:1px solid var(--border); position:sticky; bottom:0; background:var(--bg); }

        /* ── View modal: detail rows ── */
        .reg-detail-wrap { display:flex; gap:14px; margin-bottom:16px; align-items:center; }
        .reg-avatar-lg { width:52px; height:52px; border-radius:12px; display:grid; place-items:center; font-size:18px; font-weight:700; color:#fff; flex-shrink:0; }
        .reg-name-block .reg-fullname { font-size:16px; font-weight:700; color:var(--text-pri); }
        .reg-name-block .reg-meta     { font-family:var(--mono); font-size:11px; color:var(--text-dim); margin-top:3px; }
        .detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; border:1px solid var(--border); border-radius:10px; overflow:hidden; }
        .detail-cell { padding:11px 16px; border-bottom:1px solid var(--border); border-right:1px solid var(--border); }
        .detail-cell:nth-child(even) { border-right:none; }
        .detail-cell:nth-last-child(-n+2) { border-bottom:none; }
        .detail-cell-key { font-family:var(--mono); font-size:10px; color:var(--text-dim); text-transform:uppercase; letter-spacing:.08em; margin-bottom:4px; }
        .detail-cell-val { font-size:13px; color:var(--text-pri); font-weight:600; word-break:break-word; }
        .detail-cell-val.mono { font-family:var(--mono); font-size:12px; }

        /* ── Edit modal: form fields ── */
        .edit-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .edit-field { display:flex; flex-direction:column; gap:5px; }
        .edit-field.full { grid-column:1/-1; }
        .edit-field label { font-family:var(--mono); font-size:10px; color:var(--text-dim); text-transform:uppercase; letter-spacing:.08em; }
        .edit-field input, .edit-field select {
            background:var(--panel); border:1px solid var(--border-hi); border-radius:7px;
            padding:8px 11px; font-family:var(--mono); font-size:12px; color:var(--text-pri);
            outline:none; transition:border .15s;
        }
        .edit-field input:focus, .edit-field select:focus { border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-glow); }

        /* ── Delete modal ── */
        .del-warning { display:flex; gap:14px; align-items:flex-start; }
        .del-warning-icon { width:42px; height:42px; border-radius:10px; background:rgba(224,92,106,.1); border:1px solid rgba(224,92,106,.2); display:grid; place-items:center; font-size:18px; color:var(--danger); flex-shrink:0; }
        .del-warning p  { font-size:13px; color:var(--text-sec); line-height:1.65; }
        .del-warning strong { color:var(--text-pri); }

        /* ══════════════════════════════
           IMPORT MODAL (unchanged)
        ══════════════════════════════ */
        .imp-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px); }
        .imp-overlay.open { display:flex; }
        .imp-modal { background:var(--bg); border:1px solid var(--border-hi); border-radius:14px; width:760px; max-width:calc(100vw - 32px); max-height:calc(100vh - 48px); overflow-y:auto; box-shadow:0 32px 80px rgba(0,0,0,.45); animation:impIn .18s cubic-bezier(.22,.68,0,1.2); }
        @keyframes impIn { from{opacity:0;transform:translateY(-14px) scale(.97);} to{opacity:1;transform:translateY(0) scale(1);} }
        .imp-header { display:flex; align-items:flex-start; justify-content:space-between; padding:20px 22px 16px; border-bottom:1px solid var(--border); position:sticky; top:0; background:var(--bg); z-index:1; }
        .imp-title { font-size:15px; font-weight:600; color:var(--text-pri); }
        .imp-sub   { font-size:12px; color:var(--text-dim); margin-top:3px; }
        .imp-close { width:30px; height:30px; border:1px solid var(--border); background:none; border-radius:7px; cursor:pointer; color:var(--text-dim); font-size:17px; display:flex; align-items:center; justify-content:center; transition:background .15s, color .15s; flex-shrink:0; }
        .imp-close:hover { background:var(--panel); color:var(--text-pri); }
        .imp-body   { padding:20px 22px; }
        .imp-footer { display:flex; justify-content:flex-end; gap:8px; padding:14px 22px; border-top:1px solid var(--border); position:sticky; bottom:0; background:var(--bg); }
        .imp-steps { display:flex; align-items:center; gap:0; margin-bottom:20px; border-radius:8px; overflow:hidden; border:1px solid var(--border); }
        .imp-step { flex:1; padding:9px 14px; font-family:var(--mono); font-size:11px; color:var(--text-dim); background:var(--panel); text-align:center; border-right:1px solid var(--border); transition:all .2s; display:flex; align-items:center; justify-content:center; gap:6px; }
        .imp-step:last-child { border-right:none; }
        .imp-step.active { background:rgba(79,110,247,.1); color:var(--accent-hi); }
        .imp-step.done   { background:rgba(52,211,153,.07); color:var(--success); }
        .imp-step-num { width:18px; height:18px; border-radius:50%; font-size:10px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; background:var(--border); color:var(--text-dim); }
        .imp-step.active .imp-step-num { background:var(--accent); color:#fff; }
        .imp-step.done   .imp-step-num { background:var(--success); color:#fff; }
        .drop-zone { border:2px dashed var(--border-hi); border-radius:10px; padding:40px 24px; text-align:center; cursor:pointer; transition:border-color .2s, background .2s; position:relative; }
        .drop-zone:hover, .drop-zone.drag-over { border-color:var(--accent); background:rgba(79,110,247,.04); }
        .drop-zone input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; }
        .drop-icon { font-size:36px; color:var(--text-dim); margin-bottom:10px; }
        .drop-title { font-size:15px; font-weight:600; color:var(--text-pri); margin-bottom:4px; }
        .drop-sub   { font-family:var(--mono); font-size:12px; color:var(--text-dim); }
        .drop-badge { display:inline-block; font-family:var(--mono); font-size:10px; padding:2px 8px; border-radius:4px; margin:4px 2px; border:1px solid var(--border-hi); color:var(--text-sec); }
        .file-selected { display:none; align-items:center; gap:12px; background:rgba(79,110,247,.07); border:1px solid rgba(79,110,247,.2); border-radius:8px; padding:12px 16px; margin-top:12px; }
        .file-selected.visible { display:flex; }
        .file-icon { font-size:22px; }
        .file-info { flex:1; }
        .file-name { font-size:13px; font-weight:600; color:var(--text-pri); }
        .file-meta { font-family:var(--mono); font-size:11px; color:var(--text-dim); margin-top:2px; }
        .imp-form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px; }
        .imp-form-group { display:flex; flex-direction:column; gap:5px; }
        .imp-form-group label { font-family:var(--mono); font-size:11px; color:var(--text-dim); text-transform:uppercase; letter-spacing:.06em; }
        .imp-form-group select, .imp-form-group input { background:var(--panel); border:1px solid var(--border-hi); border-radius:7px; padding:7px 10px; font-family:var(--mono); font-size:12px; color:var(--text-pri); outline:none; transition:border .15s; }
        .imp-form-group select:focus, .imp-form-group input:focus { border-color:var(--accent); }
        .col-mapper { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .col-map-row { display:flex; flex-direction:column; gap:4px; }
        .col-map-row label { font-family:var(--mono); font-size:10px; color:var(--text-dim); text-transform:uppercase; letter-spacing:.06em; }
        .col-map-row select { background:var(--panel); border:1px solid var(--border-hi); border-radius:6px; padding:6px 9px; font-family:var(--mono); font-size:12px; color:var(--text-pri); outline:none; }
        .col-map-row select:focus { border-color:var(--accent); }
        .preview-wrap { overflow-x:auto; border-radius:8px; border:1px solid var(--border); margin-top:14px; }
        .preview-table { width:100%; border-collapse:collapse; font-size:11px; font-family:var(--mono); }
        .preview-table th { background:rgba(79,110,247,.1); color:var(--accent-hi); padding:7px 12px; text-align:left; white-space:nowrap; border-bottom:1px solid var(--border); }
        .preview-table td { padding:6px 12px; color:var(--text-sec); border-bottom:1px solid var(--border); white-space:nowrap; max-width:160px; overflow:hidden; text-overflow:ellipsis; }
        .preview-table tr:last-child td { border-bottom:none; }
        .preview-table tr:nth-child(even) td { background:rgba(255,255,255,.02); }
        .import-result { display:flex; flex-direction:column; align-items:center; padding:32px 24px; text-align:center; gap:12px; }
        .result-icon { font-size:48px; }
        .result-title { font-size:18px; font-weight:700; color:var(--text-pri); }
        .result-sub   { font-family:var(--mono); font-size:12px; color:var(--text-sec); }
        .result-stats { display:flex; gap:20px; margin-top:8px; }
        .result-stat  { text-align:center; }
        .result-stat-val { font-size:24px; font-weight:700; font-family:var(--mono); }
        .result-stat-lbl { font-family:var(--mono); font-size:10px; color:var(--text-dim); text-transform:uppercase; letter-spacing:.06em; }
        .result-stat-val.green { color:var(--success); }
        .result-stat-val.amber { color:#f5a623; }
        .result-stat-val.red   { color:var(--danger); }
        .import-errors { background:rgba(224,92,106,.06); border:1px solid rgba(224,92,106,.2); border-radius:8px; padding:12px 14px; margin-top:12px; max-height:140px; overflow-y:auto; }
        .import-errors p { font-family:var(--mono); font-size:11px; color:var(--danger); line-height:1.6; }
        .imp-section-label { font-family:var(--mono); font-size:10px; color:var(--text-dim); text-transform:uppercase; letter-spacing:.1em; margin-bottom:10px; display:flex; align-items:center; gap:8px; }
        .imp-section-label::after { content:''; flex:1; height:1px; background:var(--border); }
        .imp-alert { display:flex; align-items:flex-start; gap:8px; padding:10px 13px; border-radius:8px; font-size:12px; font-family:var(--mono); margin-bottom:14px; }
        .imp-alert.info    { background:rgba(79,110,247,.08); color:var(--accent-hi); border:1px solid rgba(79,110,247,.2); }
        .imp-alert.danger  { background:rgba(224,92,106,.08); color:var(--danger);    border:1px solid rgba(224,92,106,.2); }
        .imp-alert.success { background:rgba(52,211,153,.08); color:var(--success);   border:1px solid rgba(52,211,153,.2); }
        .imp-alert.hidden  { display:none; }
        .spin-icon { display:inline-block; animation:spinBtn .65s linear infinite; }
        @keyframes spinBtn { to{transform:rotate(360deg);} }
    </style>
</head>
<body>

<?= nexusSidebar() ?>

<div class="main">
    <?= nexusTopbar('handleSearch') ?>

    <div class="content">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>Audience List</h1>
                <p>Event registration records &nbsp;·&nbsp; <?= count($events) ?> event<?= count($events) != 1 ? 's' : '' ?> available</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-ghost" onclick="openImportModal()">
                    <i class="fas fa-file-import"></i> Import
                </button>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="stats-grid">
            <div class="stat-card c1">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-label">Total Registrations</div>
                <div class="stat-value" id="statTotal">—</div>
                <div class="stat-sub">Loaded below</div>
            </div>
            <div class="stat-card c2">
                <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-label">Selected Event</div>
                <div class="stat-value" id="statEvent" style="font-size:14px;line-height:1.3;padding-top:4px">All Events</div>
            </div>
            <div class="stat-card c3">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Date Range</div>
                <div class="stat-value" id="statRange" style="font-size:14px;line-height:1.3;padding-top:4px">—</div>
            </div>
            <div class="stat-card c4">
                <div class="stat-icon"><i class="fas fa-table-list"></i></div>
                <div class="stat-label">Showing Page</div>
                <div class="stat-value" id="statPage">1</div>
                <div class="stat-sub" id="statPageOf">of 1</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-calendar-days" style="margin-right:4px"></i>Event</label>
                    <select id="eventFilter" onchange="onEventChange()">
                        <option value="">All Events</option>
                        <?php foreach ($events as $ev): ?>
                        <option value="<?= $ev['id'] ?>"
                                data-start="<?= $ev['start_date'] ?>"
                                data-end="<?= $ev['end_date'] ?>"
                                data-agenda="<?= htmlspecialchars($ev['agenda']) ?>"
                                data-venue="<?= htmlspecialchars($ev['venue'] ?? '') ?>"
                                <?= $preselectedEventId === (int)$ev['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ev['agenda']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-minus" style="margin-right:4px"></i>From</label>
                    <input type="date" id="dateFrom" onchange="loadData()">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-plus" style="margin-right:4px"></i>To</label>
                    <input type="date" id="dateTo" onchange="loadData()">
                </div>
                <div class="filter-group" style="justify-content:flex-end">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary" onclick="loadData()">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
                <div class="filter-group" style="justify-content:flex-end">
                    <label>&nbsp;</label>
                    <button class="btn btn-ghost" onclick="clearFilters()">
                        <i class="fas fa-rotate-left"></i> Reset
                    </button>
                </div>
            </div>
            <div id="eventPreview" class="event-preview">
                <i class="fas fa-circle-info" style="color:var(--accent-hi)"></i>
                <span>Event: <strong id="previewAgenda">—</strong></span>
                <span style="color:var(--border-hi)">|</span>
                <span>Period: <strong id="previewDates">—</strong></span>
            </div>
        </div>

        <!-- Table card -->
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Registration Records</div>
                    <div class="card-subtitle" id="tableSubtitle">Select an event or apply filters to load data</div>
                </div>
                <div class="card-tools">
                    <div class="tbl-search">
                        <i class="fas fa-search"></i>
                        <input type="text" id="tableSearch" placeholder="Search records..." oninput="handleSearch()">
                    </div>
                    <select class="filter-select" id="pageSizeSelect" onchange="changePageSize()">
                        <option value="10">10 / page</option>
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>
                    <div class="export-dropdown">
                        <button class="tool-btn" onclick="toggleExportMenu()" id="exportToggle">
                            <i class="fas fa-file-export"></i> Export <i class="fas fa-chevron-down" style="font-size:9px"></i>
                        </button>
                        <div class="export-menu" id="exportMenu">
                            <button class="export-item" onclick="exportExcel(); toggleExportMenu()">
                                <i class="fas fa-file-excel" style="color:var(--success)"></i> Export to Excel
                            </button>
                            <button class="export-item" onclick="exportPDF(); toggleExportMenu()">
                                <i class="fas fa-file-pdf" style="color:var(--danger)"></i> Export to PDF
                            </button>
                            <button class="export-item" onclick="exportCSV(); toggleExportMenu()">
                                <i class="fas fa-file-csv" style="color:var(--warning)"></i> Export to CSV
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tbl-loading" id="tblLoading">
                <div class="spinner"></div>
                <p>Fetching registration data…</p>
            </div>

            <div class="table-wrap" id="tblWrap">
                <table class="aud-table" id="audTable">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)">Rank / Name / Major Service <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(1)">Unit / Office <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(2)">Serial Number <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(3)">Designation <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(4)">Email <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(5)">Contact Number <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th style="cursor:default">Status</th>
                            <th style="cursor:default">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="audBody">
                        <tr><td colspan="8">
                            <div class="empty-state">
                                <i class="fas fa-users-slash"></i>
                                <p>No records loaded. Apply filters above to view registration data.</p>
                            </div>
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <div class="table-footer">
                <span class="pagination-info" id="paginationInfo">No data</span>
                <div class="pagination" id="pagination"></div>
            </div>
        </div>

    </div><!-- /content -->
    <?= nexusFooter() ?>
</div><!-- /main -->

<!-- ══════════════════════════════════════════
     VIEW MODAL
══════════════════════════════════════════ -->
<div class="aud-overlay" id="viewModal" onclick="if(event.target===this)closeAudModal('viewModal')">
    <div class="aud-modal">
        <div class="aud-mhdr">
            <div>
                <div class="aud-mtitle"><i class="fas fa-id-card" style="color:var(--accent-hi)"></i> Registration Details</div>
                <div class="aud-msub" id="viewModalSub">—</div>
            </div>
            <button class="aud-mclose" onclick="closeAudModal('viewModal')">&times;</button>
        </div>
        <div class="aud-mbody" id="viewModalBody">
            <!-- Filled by JS -->
        </div>
        <div class="aud-mfoot">
            <button class="btn btn-ghost" onclick="closeAudModal('viewModal')">Close</button>
            <button class="btn btn-primary" id="viewEditBtn"><i class="fas fa-pencil"></i> Edit</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     EDIT MODAL
══════════════════════════════════════════ -->
<div class="aud-overlay" id="editModal" onclick="if(event.target===this)closeAudModal('editModal')">
    <div class="aud-modal">
        <div class="aud-mhdr">
            <div>
                <div class="aud-mtitle"><i class="fas fa-pencil" style="color:#f5a623"></i> Edit Registration</div>
                <div class="aud-msub">Changes are saved immediately to the database</div>
            </div>
            <button class="aud-mclose" onclick="closeAudModal('editModal')">&times;</button>
        </div>
        <div class="aud-mbody">
            <form id="editRegForm" autocomplete="off">
                <input type="hidden" id="editRegId">
                <div class="edit-grid">
                    <div class="edit-field">
                        <label>Rank</label>
                        <input type="text" id="editRank" placeholder="e.g. BGEN, COL">
                    </div>
                    <div class="edit-field">
                        <label>Major Service</label>
                        <input type="text" id="editMajorService" placeholder="e.g. PA, PN, PAF">
                    </div>
                    <div class="edit-field">
                        <label>First Name <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="editFirstName" required>
                    </div>
                    <div class="edit-field">
                        <label>Last Name <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="editLastName" required>
                    </div>
                    <div class="edit-field">
                        <label>Middle Name</label>
                        <input type="text" id="editMiddleName">
                    </div>
                    <div class="edit-field">
                        <label>Middle Initial</label>
                        <input type="text" id="editMiddleInitial" maxlength="5">
                    </div>
                    <div class="edit-field">
                        <label>Ext. Name (Jr/Sr)</label>
                        <input type="text" id="editExtName" maxlength="10">
                    </div>
                    <div class="edit-field">
                        <label>Serial Number</label>
                        <input type="text" id="editSerialNumber">
                    </div>
                    <div class="edit-field">
                        <label>Unit / Office</label>
                        <input type="text" id="editUnitOffice">
                    </div>
                    <div class="edit-field">
                        <label>Designation</label>
                        <input type="text" id="editDesignation">
                    </div>
                    <div class="edit-field">
                        <label>Email</label>
                        <input type="email" id="editEmail">
                    </div>
                    <div class="edit-field">
                        <label>Contact Number</label>
                        <input type="text" id="editContactNumber">
                    </div>
                    <div class="edit-field full">
                        <label>Status</label>
                        <select id="editStatus">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="aud-mfoot">
            <button class="btn btn-ghost" onclick="closeAudModal('editModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitEditReg()"><i class="fas fa-floppy-disk"></i> Save Changes</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     DELETE MODAL
══════════════════════════════════════════ -->
<div class="aud-overlay" id="deleteModal" onclick="if(event.target===this)closeAudModal('deleteModal')">
    <div class="aud-modal sm">
        <div class="aud-mhdr">
            <div class="aud-mtitle" style="color:var(--danger)">
                <i class="fas fa-triangle-exclamation"></i> Delete Registration
            </div>
            <button class="aud-mclose" onclick="closeAudModal('deleteModal')">&times;</button>
        </div>
        <div class="aud-mbody">
            <div class="del-warning">
                <div class="del-warning-icon"><i class="fas fa-trash"></i></div>
                <div>
                    <p>You are about to permanently delete the registration for <strong id="deleteRegName">this record</strong>.</p>
                    <p style="margin-top:8px">This action <strong>cannot be undone</strong> and will remove all associated data.</p>
                </div>
            </div>
        </div>
        <div class="aud-mfoot">
            <button class="btn btn-ghost" onclick="closeAudModal('deleteModal')">Cancel</button>
            <button class="btn btn-danger-ghost" id="confirmDeleteRegBtn"><i class="fas fa-trash"></i> Delete Permanently</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     IMPORT MODAL
══════════════════════════════════════════ -->
<div class="imp-overlay" id="importModal" onclick="impOverlayClick(event)">
    <div class="imp-modal" role="dialog" aria-modal="true" aria-labelledby="impTitle">

        <div class="imp-header">
            <div>
                <div class="imp-title" id="impTitle">
                    <i class="fas fa-file-import" style="margin-right:8px;color:var(--accent)"></i>Import Registrations
                </div>
                <div class="imp-sub">Upload a CSV or Excel file to bulk-import registration records</div>
            </div>
            <button class="imp-close" onclick="closeImportModal()" aria-label="Close">&times;</button>
        </div>

        <div style="padding:14px 22px 0">
            <div class="imp-steps">
                <div class="imp-step active" id="step-ind-1"><span class="imp-step-num">1</span> Upload File</div>
                <div class="imp-step"        id="step-ind-2"><span class="imp-step-num">2</span> Map Columns</div>
                <div class="imp-step"        id="step-ind-3"><span class="imp-step-num">3</span> Preview</div>
                <div class="imp-step"        id="step-ind-4"><span class="imp-step-num">4</span> Done</div>
            </div>
        </div>

        <div class="imp-body">
            <div class="imp-alert hidden" id="impAlert">
                <i class="fas fa-circle-exclamation" style="flex-shrink:0;margin-top:1px"></i>
                <span id="impAlertMsg"></span>
            </div>

            <div id="imp-step-1">
                <div class="imp-section-label">Select event &amp; file</div>
                    <div class="imp-form-row">
                        <div class="imp-form-group">
                            <label>Target Event <span style="color:var(--danger)">*</span></label>
                            <select id="impEventSelect">
                                <option value="">— Choose event —</option>
                                <?php foreach ($events as $ev): ?>
                                <option value="<?= $ev['id'] ?>" data-agenda="<?= htmlspecialchars($ev['agenda']) ?>">
                                    <?= htmlspecialchars($ev['agenda']) ?> (<?= $ev['start_date'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="imp-form-group">
                            <label>Header Row</label>
                            <select id="impSkipHeader">
                                <option value="1">First row is header (skip it)</option>
                                <option value="0">No header — import all rows</option>
                            </select>
                        </div>
                    </div>
                <div class="drop-zone" id="dropZone"
                     ondragover="e=>{e.preventDefault();document.getElementById('dropZone').classList.add('drag-over')}"
                     ondragleave="document.getElementById('dropZone').classList.remove('drag-over')"
                     ondrop="handleDrop(event)">
                    <input type="file" id="impFileInput" accept=".csv,.xlsx,.xls" onchange="handleFileSelect(event)">
                    <div class="drop-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                    <div class="drop-title">Drop your file here or click to browse</div>
                    <div class="drop-sub" style="margin-top:4px">Accepted formats:</div>
                    <div style="margin-top:6px">
                        <span class="drop-badge">.CSV</span>
                        <span class="drop-badge">.XLSX</span>
                        <span class="drop-badge">.XLS</span>
                    </div>
                </div>
                <div class="file-selected" id="fileSelectedBanner">
                    <span class="file-icon" id="fileTypeIcon"><i class="fas fa-file-csv" style="color:var(--success)"></i></span>
                    <div class="file-info">
                        <div class="file-name" id="fileSelectedName">—</div>
                        <div class="file-meta" id="fileSelectedMeta">—</div>
                    </div>
                    <button class="btn btn-ghost btn-sm" onclick="clearFile()" style="flex-shrink:0">
                        <i class="fas fa-xmark"></i> Remove
                    </button>
                </div>
                <div class="imp-alert info" style="margin-top:14px;margin-bottom:0">
                    <i class="fas fa-circle-info" style="flex-shrink:0"></i>
                    <span>Your CSV/Excel columns can be in any order — you'll map them to fields in the next step.
                        Suggested column names: <strong>First Name, Last Name, Rank, Unit/Office, Designation, Serial Number, Email, Contact Number</strong></span>
                </div>
            </div>

            <div id="imp-step-2" style="display:none">
                <div class="imp-section-label">Map your file's columns to registration fields</div>
                <div class="imp-alert info" id="imp-col-info" style="margin-bottom:14px">
                    <i class="fas fa-circle-info" style="flex-shrink:0"></i>
                    <span id="imp-col-info-text">—</span>
                </div>
                <div class="col-mapper" id="colMapper"></div>
            </div>

            <div id="imp-step-3" style="display:none">
                <div class="imp-section-label">Preview — first 8 rows from your file</div>
                <div id="previewSummary" style="font-family:var(--mono);font-size:12px;color:var(--text-sec);margin-bottom:10px"></div>
                <div class="preview-wrap">
                    <table class="preview-table" id="previewTable">
                        <thead id="previewThead"></thead>
                        <tbody id="previewTbody"></tbody>
                    </table>
                </div>
            </div>

            <div id="imp-step-4" style="display:none">
                <div class="import-result" id="importResult"></div>
            </div>
        </div>

        <div class="imp-footer" id="impFooter">
            <button class="btn btn-ghost" id="impBackBtn" style="display:none" onclick="impBack()">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <button class="btn btn-ghost" onclick="closeImportModal()">Cancel</button>
            <button class="btn btn-primary" id="impNextBtn" onclick="impNext()">
                <span id="impNextLabel">Next <i class="fas fa-arrow-right"></i></span>
                <span id="impNextSpinner" style="display:none"><i class="fas fa-circle-notch spin-icon"></i> Processing…</span>
            </button>
        </div>

    </div>
</div>

<?= nexusToast() ?>
<?= nexusJS() ?>

<script>
/* ═══════════════════════════════════════════
   AUDIENCE TABLE
═══════════════════════════════════════════ */
let allData=[], filteredData=[], currentPage=1, pageSize=10, sortCol=-1, sortDir='asc';

window.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('eventFilter');
    if (sel.value) onEventChange();
});

function onEventChange() {
    const sel=document.getElementById('eventFilter'), opt=sel.options[sel.selectedIndex];
    const start=opt.dataset.start||'', end=opt.dataset.end||'', agenda=opt.dataset.agenda||'';
    if (sel.value) {
        document.getElementById('dateFrom').value=start; document.getElementById('dateTo').value=end;
        document.getElementById('eventPreview').classList.add('visible');
        document.getElementById('previewAgenda').textContent=agenda;
        document.getElementById('previewDates').textContent=`${start} → ${end}`;
        document.getElementById('statEvent').textContent=agenda;
    } else {
        document.getElementById('dateFrom').value=''; document.getElementById('dateTo').value='';
        document.getElementById('eventPreview').classList.remove('visible');
        document.getElementById('statEvent').textContent='All Events';
    }
    setTimeout(loadData, 0);
}

function clearFilters() {
    document.getElementById('eventFilter').value=''; document.getElementById('dateFrom').value='';
    document.getElementById('dateTo').value=''; document.getElementById('tableSearch').value='';
    document.getElementById('eventPreview').classList.remove('visible');
    document.getElementById('statEvent').textContent='All Events'; document.getElementById('statRange').textContent='—';
    allData=[]; filteredData=[]; renderTable();
}

function loadData() {
    const eventId=document.getElementById('eventFilter').value, dateFrom=document.getElementById('dateFrom').value, dateTo=document.getElementById('dateTo').value;
    document.getElementById('tblLoading').classList.add('active'); document.getElementById('tblWrap').style.opacity='.3';
    const fd=new FormData(); fd.append('event_id',eventId); fd.append('date_from',dateFrom); fd.append('date_to',dateTo); fd.append('draw',1); fd.append('start',0); fd.append('length',9999);
    fetch('fetch_registrations.php',{method:'POST',body:fd})
        .then(r=>{ if(!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
        .then(resp=>{
            document.getElementById('tblLoading').classList.remove('active'); document.getElementById('tblWrap').style.opacity='1';
            allData=resp.data||[]; filteredData=[...allData]; currentPage=1;
            document.getElementById('statTotal').textContent=allData.length;
            const from=document.getElementById('dateFrom').value, to=document.getElementById('dateTo').value;
            document.getElementById('statRange').textContent=(from&&to)?`${from} → ${to}`:(from||to||'—');
            applySearch(); showToast(`${allData.length} record${allData.length!==1?'s':''} loaded`,'info');
        })
        .catch(err=>{ document.getElementById('tblLoading').classList.remove('active'); document.getElementById('tblWrap').style.opacity='1'; showToast('Failed to fetch data: '+err.message,'error'); });
}

function handleSearch() {
    const topQ=document.getElementById('topbarSearch')?.value||'', tblQ=document.getElementById('tableSearch').value||'';
    if(document.activeElement?.id==='topbarSearch') document.getElementById('tableSearch').value=topQ;
    else if(document.getElementById('topbarSearch')) document.getElementById('topbarSearch').value=tblQ;
    applySearch();
}

function applySearch() {
    const q=(document.getElementById('tableSearch').value||'').toLowerCase();
    filteredData=q?allData.filter(row=>Object.values(row).some(v=>String(v||'').toLowerCase().includes(q))):[...allData];
    currentPage=1; renderTable();
}

const COLS=['fullname','unit_office','serial_number','designation','email','contact_number'];

function sortTable(col) {
    const ths=document.querySelectorAll('#audTable thead th');
    ths.forEach(th=>th.classList.remove('sorted-asc','sorted-desc'));
    if(sortCol===col) sortDir=sortDir==='asc'?'desc':'asc'; else { sortCol=col; sortDir='asc'; }
    ths[col].classList.add(sortDir==='asc'?'sorted-asc':'sorted-desc');
    const key=COLS[col];
    filteredData.sort((a,b)=>{ const av=String(a[key]||''),bv=String(b[key]||''); return sortDir==='asc'?av.localeCompare(bv,undefined,{numeric:true}):bv.localeCompare(av,undefined,{numeric:true}); });
    currentPage=1; renderTable();
}

function changePageSize() { pageSize=parseInt(document.getElementById('pageSizeSelect').value); currentPage=1; renderTable(); }

function esc(v) { return String(v||'—').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escRaw(v) { return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function getInitials(n) { return(n||'?').split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase(); }
const AV_COLORS=['#4f6ef7','#34d399','#f5a623','#e05c6a','#a78bfa','#38bdf8'];
function avatarColor(n) { let h=0; for(const c of(n||'')) h=(h*31+c.charCodeAt(0))%AV_COLORS.length; return AV_COLORS[h]; }

function renderTable() {
    const tbody=document.getElementById('audBody'), total=filteredData.length;
    const pages=Math.max(1,Math.ceil(total/pageSize));
    if(currentPage>pages) currentPage=pages;
    const start=(currentPage-1)*pageSize, slice=filteredData.slice(start,start+pageSize);
    document.getElementById('statPage').textContent=currentPage; document.getElementById('statPageOf').textContent=`of ${pages}`;
    if(!total) {
        tbody.innerHTML=`<tr><td colspan="8"><div class="empty-state"><i class="fas fa-users-slash"></i><p>No records match your search or filters.</p></div></td></tr>`;
        document.getElementById('paginationInfo').textContent='No records found'; document.getElementById('pagination').innerHTML=''; document.getElementById('tableSubtitle').textContent='No matching records'; return;
    }
    tbody.innerHTML=slice.map(row=>{
        const ini=getInitials(row.fullname), col=avatarColor(row.fullname);
        const active = row.active == null ? 1 : parseInt(row.active); // default active if field missing
        const statusBadge = active
            ? `<span class="badge-active"><i class="fas fa-circle" style="font-size:7px;margin-right:4px"></i>Active</span>`
            : `<span class="badge-inactive"><i class="fas fa-circle" style="font-size:7px;margin-right:4px"></i>Inactive</span>`;
        const toggleIcon  = active ? 'fa-toggle-on'  : 'fa-toggle-off';
        const toggleClass = active ? 'toggle-on'     : 'toggle-off';
        const toggleTitle = active ? 'Disable'        : 'Enable';
        const rowData = encodeURIComponent(JSON.stringify(row));
        return `<tr>
            <td class="td-main"><div class="avatar-cell"><div class="row-avatar" style="background:${col}">${ini}</div><span>${esc(row.fullname)}</span></div></td>
            <td>${esc(row.unit_office)}</td>
            <td class="mono">${esc(row.serial_number)}</td>
            <td>${esc(row.designation)}</td>
            <td class="mono">${esc(row.email)}</td>
            <td class="mono">${esc(row.contact_number)}</td>
            <td>${statusBadge}</td>
            <td>
                <div class="row-actions">
                    <button class="act-btn view"   title="View details"  onclick="openViewModal(decodeRow(this))"><i class="fas fa-eye"></i></button>
                    <button class="act-btn edit"   title="Edit"          onclick="openEditModal(decodeRow(this))"><i class="fas fa-pencil"></i></button>
                    <button class="act-btn ${toggleClass}" title="${toggleTitle}" onclick="toggleRegStatus(decodeRow(this), this)"><i class="fas ${toggleIcon}"></i></button>
                    <button class="act-btn del"    title="Delete"        onclick="openDeleteModal(decodeRow(this))"><i class="fas fa-trash"></i></button>
                </div>
            </td>
        </tr>`.replace('decodeRow(this)', `decodeRow(this,'${rowData}')`);
    }).join('');

    // Fix the decodeRow calls — embed data via data-attr instead
    tbody.innerHTML=slice.map(row=>{
        const ini=getInitials(row.fullname), col=avatarColor(row.fullname);
        const active = row.active == null ? 1 : parseInt(row.active);
        const statusBadge = active
            ? `<span class="badge-active"><i class="fas fa-circle" style="font-size:7px;margin-right:4px"></i>Active</span>`
            : `<span class="badge-inactive"><i class="fas fa-circle" style="font-size:7px;margin-right:4px"></i>Inactive</span>`;
        const toggleIcon  = active ? 'fa-toggle-on'  : 'fa-toggle-off';
        const toggleClass = active ? 'toggle-on'     : 'toggle-off';
        const toggleTitle = active ? 'Disable'        : 'Enable';
        const safeJson = JSON.stringify(row).replace(/'/g,"\\'");
        return `<tr>
            <td class="td-main"><div class="avatar-cell"><div class="row-avatar" style="background:${col}">${ini}</div><span>${esc(row.fullname)}</span></div></td>
            <td>${esc(row.unit_office)}</td>
            <td class="mono">${esc(row.serial_number)}</td>
            <td>${esc(row.designation)}</td>
            <td class="mono">${esc(row.email)}</td>
            <td class="mono">${esc(row.contact_number)}</td>
            <td>${statusBadge}</td>
            <td>
                <div class="row-actions">
                    <button class="act-btn view"   title="View details"  data-row='${safeJson.replace(/'/g,"&apos;")}' onclick='openViewModal(JSON.parse(this.dataset.row))'><i class="fas fa-eye"></i></button>
                    <button class="act-btn edit"   title="Edit"          data-row='${safeJson.replace(/'/g,"&apos;")}' onclick='openEditModal(JSON.parse(this.dataset.row))'><i class="fas fa-pencil"></i></button>
                    <button class="act-btn ${toggleClass}" title="${toggleTitle}" data-row='${safeJson.replace(/'/g,"&apos;")}' onclick='toggleRegStatus(JSON.parse(this.dataset.row),this)'><i class="fas ${toggleIcon}"></i></button>
                    <button class="act-btn del"    title="Delete"        data-row='${safeJson.replace(/'/g,"&apos;")}' onclick='openDeleteModal(JSON.parse(this.dataset.row))'><i class="fas fa-trash"></i></button>
                </div>
            </td>
        </tr>`;
    }).join('');

    const from=start+1, to=Math.min(start+pageSize,total);
    document.getElementById('paginationInfo').textContent=`Showing ${from}–${to} of ${total} records`;
    document.getElementById('tableSubtitle').textContent=`${total} registration${total!==1?'s':''} found`;
    buildPagination(total, pages);
}

function buildPagination(total, pages) {
    const pg=document.getElementById('pagination'); pg.innerHTML='';
    const mkBtn=(lbl,page,dis,active)=>{ const b=document.createElement('button'); b.className='page-btn'+(active?' active':''); b.innerHTML=lbl; b.disabled=dis; b.onclick=()=>{currentPage=page;renderTable();}; return b; };
    pg.appendChild(mkBtn('<i class="fas fa-chevron-left"></i>',currentPage-1,currentPage===1,false));
    let rs=Math.max(1,currentPage-2), re=Math.min(pages,rs+4); if(re-rs<4) rs=Math.max(1,re-4);
    if(rs>1){ pg.appendChild(mkBtn('1',1,false,false)); if(rs>2){const d=document.createElement('span');d.className='page-btn';d.style.cursor='default';d.textContent='…';pg.appendChild(d);} }
    for(let p=rs;p<=re;p++) pg.appendChild(mkBtn(p,p,false,p===currentPage));
    if(re<pages){ if(re<pages-1){const d=document.createElement('span');d.className='page-btn';d.style.cursor='default';d.textContent='…';pg.appendChild(d);} pg.appendChild(mkBtn(pages,pages,false,false)); }
    pg.appendChild(mkBtn('<i class="fas fa-chevron-right"></i>',currentPage+1,currentPage===pages,false));
}

/* ── Exports ── */
function exportCSV() {
    if(!filteredData.length){showToast('No data to export','error');return;}
    const h=['Rank/Name/Major Service','Unit/Office','Serial Number','Designation','Email','Contact Number'];
    const lines=[h.join(',')];
    filteredData.forEach(r=>lines.push([r.fullname,r.unit_office,r.serial_number,r.designation,r.email,r.contact_number].map(v=>`"${String(v||'').replace(/"/g,'""')}"`).join(',')));
    const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([lines.join('\n')],{type:'text/csv'})); a.download=`audience_${new Date().toISOString().slice(0,10)}.csv`; a.click(); showToast('CSV exported successfully');
}
function exportExcel() {
    if(!filteredData.length){showToast('No data to export','error');return;}
    const eventId=document.getElementById('eventFilter').value;
    if(!eventId){showToast('Please select an event first','error');return;}
    const params=new URLSearchParams({event_id:eventId,date_from:document.getElementById('dateFrom').value,date_to:document.getElementById('dateTo').value});
    window.location.href='export_attendance_excel.php?'+params.toString(); showToast('Excel export started');
}
function exportPDF() {
    if(!filteredData.length){showToast('No data to export','error');return;}
    const sel=document.getElementById('eventFilter'), eventId=sel.value;
    if(!eventId){clientPDF();return;}
    const opt=sel.options[sel.selectedIndex], agenda=opt.dataset.agenda||'', venue=opt.dataset.venue||'';
    const params=new URLSearchParams({agenda,venue,date_from:document.getElementById('dateFrom').value,date_to:document.getElementById('dateTo').value});
    showToast('Preparing PDF download…','info');
    setTimeout(() => {
        window.location.href = 'export_attendance_pdf.php?' + params.toString();
    }, 300);
}
function clientPDF() {
    const{jsPDF}=window.jspdf, doc=new jsPDF({orientation:'landscape'});
    doc.setFontSize(14); doc.setTextColor(40,40,80); doc.text('Audience Registration List',14,16);
    doc.setFontSize(9); doc.setTextColor(120,130,160); doc.text(`Generated: ${new Date().toLocaleString()}`,14,22);
    doc.autoTable({startY:28,head:[['Rank / Name / Major Service','Unit / Office','Serial Number','Designation','Email','Contact Number']],body:filteredData.map(r=>[r.fullname,r.unit_office,r.serial_number,r.designation,r.email,r.contact_number]),styles:{fontSize:8,cellPadding:3},headStyles:{fillColor:[79,110,247],textColor:255,fontStyle:'bold'},alternateRowStyles:{fillColor:[245,246,250]}});
    doc.save(`audience_${new Date().toISOString().slice(0,10)}.pdf`); showToast('PDF exported successfully');
}
function toggleExportMenu() { document.getElementById('exportMenu').classList.toggle('open'); }
document.addEventListener('click',e=>{ if(!e.target.closest('.export-dropdown')) document.getElementById('exportMenu').classList.remove('open'); });

/* ═══════════════════════════════════════════
   ROW ACTIONS — VIEW / EDIT / TOGGLE / DELETE
═══════════════════════════════════════════ */
function closeAudModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow='';
}
function openAudModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow='hidden';
}
document.addEventListener('keydown', e => {
    if(e.key==='Escape') ['viewModal','editModal','deleteModal'].forEach(closeAudModal);
});

/* ── VIEW ── */
function openViewModal(row) {
    const active = row.active == null ? 1 : parseInt(row.active);
    document.getElementById('viewModalSub').textContent = `ID #${row.id || '—'} · Registered ${row.created_at ? new Date(row.created_at).toLocaleDateString() : '—'}`;
    const col = avatarColor(row.fullname);
    const ini = getInitials(row.fullname);
    const statusHtml = active
        ? `<span class="badge-active"><i class="fas fa-circle" style="font-size:7px;margin-right:4px"></i>Active</span>`
        : `<span class="badge-inactive"><i class="fas fa-circle" style="font-size:7px;margin-right:4px"></i>Inactive</span>`;

    document.getElementById('viewModalBody').innerHTML = `
        <div class="reg-detail-wrap">
            <div class="reg-avatar-lg" style="background:${col}">${ini}</div>
            <div class="reg-name-block">
                <div class="reg-fullname">${escRaw(row.fullname)}</div>
                <div class="reg-meta">${escRaw(row.designation||'—')} &nbsp;·&nbsp; ${escRaw(row.unit_office||'—')}</div>
            </div>
        </div>
        <div class="detail-grid">
            <div class="detail-cell"><div class="detail-cell-key">Rank</div><div class="detail-cell-val">${escRaw(row.rank||'—')}</div></div>
            <div class="detail-cell"><div class="detail-cell-key">Major Service</div><div class="detail-cell-val">${escRaw(row.major_service||'—')}</div></div>
            <div class="detail-cell"><div class="detail-cell-key">Serial Number</div><div class="detail-cell-val mono">${escRaw(row.serial_number||'—')}</div></div>
            <div class="detail-cell"><div class="detail-cell-key">Unit / Office</div><div class="detail-cell-val">${escRaw(row.unit_office||'—')}</div></div>
            <div class="detail-cell"><div class="detail-cell-key">Designation</div><div class="detail-cell-val">${escRaw(row.designation||'—')}</div></div>
            <div class="detail-cell"><div class="detail-cell-key">Email</div><div class="detail-cell-val mono">${escRaw(row.email||'—')}</div></div>
            <div class="detail-cell"><div class="detail-cell-key">Contact Number</div><div class="detail-cell-val mono">${escRaw(row.contact_number||'—')}</div></div>
            <div class="detail-cell"><div class="detail-cell-key">Status</div><div class="detail-cell-val">${statusHtml}</div></div>
        </div>
    `;
    document.getElementById('viewEditBtn').onclick = () => { closeAudModal('viewModal'); openEditModal(row); };
    openAudModal('viewModal');
}

/* ── EDIT ── */
function openEditModal(row) {
    document.getElementById('editRegId').value         = row.id || '';
    document.getElementById('editRank').value          = row.rank          || '';
    document.getElementById('editMajorService').value  = row.major_service || '';
    document.getElementById('editFirstName').value     = row.first_name    || '';
    document.getElementById('editLastName').value      = row.last_name     || '';
    document.getElementById('editMiddleName').value    = row.middle_name   || '';
    document.getElementById('editMiddleInitial').value = row.middle_initial|| '';
    document.getElementById('editExtName').value       = row.ext_name      || '';
    document.getElementById('editSerialNumber').value  = row.serial_number || '';
    document.getElementById('editUnitOffice').value    = row.unit_office   || '';
    document.getElementById('editDesignation').value   = row.designation   || '';
    document.getElementById('editEmail').value         = row.email         || '';
    document.getElementById('editContactNumber').value = row.contact_number|| '';
    document.getElementById('editStatus').value        = row.active == null ? 1 : row.active;
    openAudModal('editModal');
}

function submitEditReg() {
    const id = document.getElementById('editRegId').value;
    if (!id) { showToast('Missing record ID','error'); return; }
    const firstName = document.getElementById('editFirstName').value.trim();
    const lastName  = document.getElementById('editLastName').value.trim();
    if (!firstName && !lastName) { showToast('First or Last name is required','error'); return; }

    const fd = new FormData();
    fd.append('action',         'update');
    fd.append('id',             id);
    fd.append('rank',           document.getElementById('editRank').value.trim());
    fd.append('major_service',  document.getElementById('editMajorService').value.trim());
    fd.append('first_name',     firstName);
    fd.append('last_name',      lastName);
    fd.append('middle_name',    document.getElementById('editMiddleName').value.trim());
    fd.append('middle_initial', document.getElementById('editMiddleInitial').value.trim());
    fd.append('ext_name',       document.getElementById('editExtName').value.trim());
    fd.append('serial_number',  document.getElementById('editSerialNumber').value.trim());
    fd.append('unit_office',    document.getElementById('editUnitOffice').value.trim());
    fd.append('designation',    document.getElementById('editDesignation').value.trim());
    fd.append('email',          document.getElementById('editEmail').value.trim());
    fd.append('contact_number', document.getElementById('editContactNumber').value.trim());
    fd.append('active',         document.getElementById('editStatus').value);

    fetch('audience_actions.php', { method:'POST', body:fd })
        .then(r=>r.json())
        .then(res=>{
            if(res.success){
                showToast('Registration updated successfully','success');
                closeAudModal('editModal');
                loadData();
            } else {
                showToast(res.message||'Update failed','error');
            }
        })
        .catch(err=>showToast('Network error: '+err.message,'error'));
}

/* ── TOGGLE STATUS ── */
function toggleRegStatus(row, btn) {
    const currentActive = row.active == null ? 1 : parseInt(row.active);
    const newActive = currentActive ? 0 : 1;
    const fd = new FormData();
    fd.append('action', 'toggle');
    fd.append('id',     row.id);
    fd.append('active', newActive);

    fetch('audience_actions.php', { method:'POST', body:fd })
        .then(r=>r.json())
        .then(res=>{
            if(res.success){
                showToast(`Registration ${newActive ? 'enabled' : 'disabled'} successfully`,'success');
                loadData();
            } else {
                showToast(res.message||'Status update failed','error');
            }
        })
        .catch(err=>showToast('Network error: '+err.message,'error'));
}

/* ── DELETE ── */
let _pendingDeleteRow = null;
function openDeleteModal(row) {
    _pendingDeleteRow = row;
    document.getElementById('deleteRegName').textContent = row.fullname || 'this record';
    openAudModal('deleteModal');
}
document.getElementById('confirmDeleteRegBtn').addEventListener('click', () => {
    if (!_pendingDeleteRow) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id',     _pendingDeleteRow.id);

    fetch('audience_actions.php', { method:'POST', body:fd })
        .then(r=>r.json())
        .then(res=>{
            closeAudModal('deleteModal');
            if(res.success){
                showToast('Registration deleted permanently','success');
                _pendingDeleteRow = null;
                loadData();
            } else {
                showToast(res.message||'Delete failed','error');
            }
        })
        .catch(err=>showToast('Network error: '+err.message,'error'));
});

/* ═══════════════════════════════════════════
   IMPORT MODAL
═══════════════════════════════════════════ */
let impStep=1, impFile=null, impHeaders=[], impColMap={}, impColCount=0, impTotalRows=0;

function openImportModal() { impReset(); document.getElementById('importModal').classList.add('open'); document.body.style.overflow='hidden'; }
function closeImportModal() { document.getElementById('importModal').classList.remove('open'); document.body.style.overflow=''; }
function impOverlayClick(e) { if(e.target===document.getElementById('importModal')) closeImportModal(); }

function impReset() {
    impStep=1; impFile=null; impHeaders=[]; impColMap={}; impColCount=0; impTotalRows=0;
    document.getElementById('impFileInput').value='';
    document.getElementById('fileSelectedBanner').classList.remove('visible');
    document.getElementById('impEventSelect').value='';
    document.getElementById('impSkipHeader').value='1';
    document.getElementById('dropZone').classList.remove('drag-over');
    ['imp-step-1','imp-step-2','imp-step-3','imp-step-4'].forEach((id,i)=>{ document.getElementById(id).style.display=i===0?'block':'none'; });
    impUpdateStepIndicator(); impSetAlert('hidden');
    document.getElementById('impNextLabel').innerHTML='Next <i class="fas fa-arrow-right"></i>';
    document.getElementById('impNextSpinner').style.display='none';
    document.getElementById('impNextBtn').disabled=false;
    document.getElementById('impBackBtn').style.display='none';
}

function impUpdateStepIndicator() {
    for(let i=1;i<=4;i++) {
        const el=document.getElementById(`step-ind-${i}`);
        el.classList.remove('active','done');
        if(i===impStep) el.classList.add('active');
        else if(i<impStep) el.classList.add('done');
    }
}

function impSetAlert(type, msg='') {
    const box=document.getElementById('impAlert');
    if(type==='hidden'){box.className='imp-alert hidden';return;}
    box.className=`imp-alert ${type}`;
    document.getElementById('impAlertMsg').innerHTML=msg;
}

function handleFileSelect(e) { if(e.target.files[0]) setFile(e.target.files[0]); }
function handleDrop(e) { e.preventDefault(); document.getElementById('dropZone').classList.remove('drag-over'); const f=e.dataTransfer?.files[0]; if(f) setFile(f); }
function setFile(f) {
    const ext=f.name.split('.').pop().toLowerCase();
    if(!['csv','xlsx','xls'].includes(ext)) { impSetAlert('danger','Unsupported file type.'); return; }
    impFile=f; impSetAlert('hidden');
    document.getElementById('fileSelectedName').textContent=f.name;
    document.getElementById('fileSelectedMeta').textContent=`${(f.size/1024).toFixed(1)} KB · ${ext.toUpperCase()}`;
    document.getElementById('fileTypeIcon').innerHTML=ext==='csv'?'<i class="fas fa-file-csv" style="color:var(--success);font-size:22px"></i>':'<i class="fas fa-file-excel" style="color:#1d6f42;font-size:22px"></i>';
    document.getElementById('fileSelectedBanner').classList.add('visible');
}
function clearFile() { impFile=null; document.getElementById('impFileInput').value=''; document.getElementById('fileSelectedBanner').classList.remove('visible'); impSetAlert('hidden'); }

function impBack() {
    if(impStep<=1) return;
    impStep--;
    ['imp-step-1','imp-step-2','imp-step-3','imp-step-4'].forEach((id,i)=>{ document.getElementById(id).style.display=i===impStep-1?'block':'none'; });
    impUpdateStepIndicator(); impSetAlert('hidden');
    document.getElementById('impBackBtn').style.display=impStep>1?'':'none';
    document.getElementById('impNextLabel').innerHTML=impStep===3?'Import <i class="fas fa-upload"></i>':'Next <i class="fas fa-arrow-right"></i>';
}

function impNext() {
    if(impStep===1) impStep1Next();
    else if(impStep===2) impStep2Next();
    else if(impStep===3) impStep3Import();
    else closeImportModal();
}

function impStep1Next() {
    if(!document.getElementById('impEventSelect').value){impSetAlert('danger','Please select a target event.');return;}
    if(!impFile){impSetAlert('danger','Please select a file to import.');return;}
    impSetLoading(true,'Reading file…');
    const fd=new FormData(); fd.append('file',impFile); fd.append('event_id',document.getElementById('impEventSelect').value); fd.append('skip_header',document.getElementById('impSkipHeader').value); fd.append('mode','preview');
    fetch('import_registrations.php',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
        impSetLoading(false);
        if(!res.success){impSetAlert('danger',res.message||'Preview failed.');return;}
        impHeaders=res.headers||[]; impColMap=res.col_map||{}; impColCount=res.col_count||0; impTotalRows=res.total_rows||0;
        buildColMapper();
        document.getElementById('imp-col-info-text').textContent=`${res.col_count} column${res.col_count!==1?'s':''} detected · ${impTotalRows} data row${impTotalRows!==1?'s':''} · Auto-mapped fields shown below. Adjust if needed.`;
        impStep=2; impSetAlert('hidden');
        ['imp-step-1','imp-step-2','imp-step-3','imp-step-4'].forEach((id,i)=>{document.getElementById(id).style.display=i===1?'block':'none';});
        impUpdateStepIndicator(); document.getElementById('impBackBtn').style.display=''; document.getElementById('impNextLabel').innerHTML='Next <i class="fas fa-arrow-right"></i>';
    }).catch(err=>{impSetLoading(false);impSetAlert('danger','Network error: '+err.message);});
}

function impStep2Next() {
    document.querySelectorAll('#colMapper .col-map-sel').forEach(sel=>{ impColMap[sel.dataset.field]=parseInt(sel.value); });
    impSetLoading(true,'Building preview…');
    const fd=new FormData(); fd.append('file',impFile); fd.append('event_id',document.getElementById('impEventSelect').value); fd.append('skip_header',document.getElementById('impSkipHeader').value); fd.append('mode','preview');
    Object.entries(impColMap).forEach(([k,v])=>fd.append('col_'+k,v));
    fetch('import_registrations.php',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
        impSetLoading(false);
        if(!res.success){impSetAlert('danger',res.message||'Preview failed.');return;}
        buildPreviewTable(res.preview,res.col_map);
        document.getElementById('previewSummary').innerHTML=`<i class="fas fa-circle-info" style="color:var(--accent-hi);margin-right:5px"></i><strong style="color:var(--text-pri)">${impTotalRows}</strong> row${impTotalRows!==1?'s':''} will be imported into <strong style="color:var(--accent-hi)">${esc(res.event?.agenda||'selected event')}</strong>. Showing first ${Math.min(8,impTotalRows)} rows.`;
        impStep=3; impSetAlert('hidden');
        ['imp-step-1','imp-step-2','imp-step-3','imp-step-4'].forEach((id,i)=>{document.getElementById(id).style.display=i===2?'block':'none';});
        impUpdateStepIndicator(); document.getElementById('impNextLabel').innerHTML='Import <i class="fas fa-upload"></i>';
    }).catch(err=>{impSetLoading(false);impSetAlert('danger','Network error: '+err.message);});
}

function impStep3Import() {
    impSetLoading(true,'Importing records…');
    const fd=new FormData(); fd.append('file',impFile); fd.append('event_id',document.getElementById('impEventSelect').value); fd.append('skip_header',document.getElementById('impSkipHeader').value); fd.append('mode','import');
    Object.entries(impColMap).forEach(([k,v])=>fd.append('col_'+k,v));
    fetch('import_registrations.php',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
        impSetLoading(false); impStep=4;
        ['imp-step-1','imp-step-2','imp-step-3','imp-step-4'].forEach((id,i)=>{document.getElementById(id).style.display=i===3?'block':'none';});
        impUpdateStepIndicator(); document.getElementById('impBackBtn').style.display='none'; document.getElementById('impNextLabel').innerHTML='Close';
        const resultEl=document.getElementById('importResult');
        if(res.success){
            let errHtml=''; if(res.errors&&res.errors.length) errHtml=`<div class="import-errors">${res.errors.map(e=>`<p>⚠ ${esc(e)}</p>`).join('')}</div>`;
            resultEl.innerHTML=`<div class="result-icon">✅</div><div class="result-title">Import Successful</div><div class="result-sub">${esc(res.message)}</div><div class="result-stats"><div class="result-stat"><div class="result-stat-val green">${res.inserted||0}</div><div class="result-stat-lbl">Inserted</div></div><div class="result-stat"><div class="result-stat-val amber">${res.skipped||0}</div><div class="result-stat-lbl">Skipped</div></div><div class="result-stat"><div class="result-stat-val ${(res.errors?.length||0)>0?'red':'green'}">${res.errors?.length||0}</div><div class="result-stat-lbl">Warnings</div></div></div>${errHtml}`;
            showToast(res.message,'success');
            const curEvent=document.getElementById('eventFilter').value, impEvent=document.getElementById('impEventSelect').value;
            if(curEvent===impEvent) setTimeout(loadData,800);
        } else {
            resultEl.innerHTML=`<div class="result-icon">❌</div><div class="result-title">Import Failed</div><div class="result-sub">${esc(res.message||'An error occurred.')}</div>`;
            showToast(res.message||'Import failed.','error');
        }
    }).catch(err=>{impSetLoading(false);impSetAlert('danger','Network error during import: '+err.message);});
}

const FIELD_LABELS = {
    rank:'Rank', first_name:'First Name *', last_name:'Last Name *', middle_name:'Middle Name',
    middle_initial:'Middle Initial', ext_name:'Ext. Name (Jr/Sr)', unit_office:'Unit / Office',
    major_service:'Major Service', designation:'Designation', serial_number:'Serial Number',
    email:'Email', contact_number:'Contact Number',
    // ADD THESE:
    start_date:'Start Date', end_date:'End Date', event_day:'Event Day',
};

function buildColMapper() {
    const mapper=document.getElementById('colMapper'); mapper.innerHTML='';
    const noneOpt=`<option value="-1">— Skip this field —</option>`;
    const colOpts=impHeaders.map((h,i)=>`<option value="${i}">${esc(h)||'Column '+(i+1)}</option>`).join('');
    Object.entries(FIELD_LABELS).forEach(([field,label])=>{
        const div=document.createElement('div'); div.className='col-map-row';
        div.innerHTML=`<label>${label}</label><select class="col-map-sel" data-field="${field}">${noneOpt}${colOpts}</select>`;
        const sel=div.querySelector('select'); sel.value=impColMap[field]!==-1?impColMap[field]:-1;
        mapper.appendChild(div);
    });
}

function buildPreviewTable(rows,colMap) {
    const activeCols=Object.entries(colMap).filter(([,idx])=>idx!==-1).sort(([,a],[,b])=>a-b);
    const shownIdxs=activeCols.map(([,idx])=>idx);
    const shownLabels=activeCols.map(([field])=>FIELD_LABELS[field]?.replace(' *','')||field);
    document.getElementById('previewThead').innerHTML=`<tr>${shownLabels.map(l=>`<th>${esc(l)}</th>`).join('')}</tr>`;
    document.getElementById('previewTbody').innerHTML=rows.map(row=>`<tr>${shownIdxs.map(i=>`<td>${esc(row[i])}</td>`).join('')}</tr>`).join('');
}

function impSetLoading(on,label='') {
    const nl=document.getElementById('impNextLabel'),ns=document.getElementById('impNextSpinner'),btn=document.getElementById('impNextBtn'),back=document.getElementById('impBackBtn');
    if(on){nl.style.display='none';ns.style.display='inline-flex';if(label)ns.innerHTML=`<i class="fas fa-circle-notch spin-icon"></i> ${label}`;btn.disabled=true;back.disabled=true;}
    else{nl.style.display='';ns.style.display='none';btn.disabled=false;back.disabled=false;}
}

function esc(v){ return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
</body>
</html>