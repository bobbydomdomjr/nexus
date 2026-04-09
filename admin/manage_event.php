<?php
session_start();
require '../config/db.php';
require __DIR__ . '/_rbac.php';
nexus_require_role_page($pdo, ['admin', 'staff']);

/* ─────────────────────────────────────────────
   Sidebar component setup
───────────────────────────────────────────── */
$activePage   = 'events';
$pageTitle    = 'Events';
$pageSubtitle = 'Manage Events';
$docTitle     = 'Nexus Platform';

require '_sidebar.php';

/* ─────────────────────────────────────────────
   Events data
───────────────────────────────────────────── */
$stmt   = $pdo->query("SELECT * FROM event_settings ORDER BY start_date DESC");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total    = count($events);
$active   = count(array_filter($events, fn($e) => $e['active'] == 1));
$inactive = $total - $active;
$upcoming = count(array_filter($events, fn($e) => strtotime($e['start_date']) > time()));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= nexusHead() ?>

    <!-- QR Code library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <style>
        /* ── Page-specific styles only ── */

        /* TABLE */
        .filter-search { display: flex; align-items: center; gap: 8px; background: var(--bg); border: 1px solid var(--border-hi); border-radius: 7px; padding: 6px 12px; }
        .filter-search input { background: none; border: none; outline: none; font-family: var(--mono); font-size: 12px; color: var(--text-pri); width: 180px; }
        .filter-search input::placeholder { color: var(--text-dim); }
        .filter-search i { color: var(--text-dim); font-size: 12px; }

        table.evt-table { width: 100%; border-collapse: collapse; }
        table.evt-table thead th { font-family: var(--mono); font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .1em; padding: 10px 16px; background: var(--bg); border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; cursor: pointer; user-select: none; }
        table.evt-table thead th:hover { color: var(--text-sec); }
        table.evt-table thead th .sort-icon { margin-left: 4px; opacity: .4; }
        table.evt-table thead th.sorted-asc .sort-icon,
        table.evt-table thead th.sorted-desc .sort-icon { opacity: 1; color: var(--accent-hi); }
        table.evt-table tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
        table.evt-table tbody tr:last-child { border-bottom: none; }
        table.evt-table tbody tr:hover { background: rgba(79,110,247,.04); }
        table.evt-table tbody td { padding: 13px 16px; font-size: 13px; color: var(--text-sec); vertical-align: middle; }
        table.evt-table tbody td.td-main { color: var(--text-pri); font-weight: 600; max-width: 220px; }
        table.evt-table tbody td.mono { font-family: var(--mono); font-size: 12px; }

        .badge-upcoming { background: rgba(167,139,250,.1); color: #a78bfa; border: 1px solid rgba(167,139,250,.2); }
        .badge-ongoing  { background: rgba(245,166,35,.1);  color: var(--warning); border: 1px solid rgba(245,166,35,.2); }
        .badge-past     { background: rgba(69,77,102,.15);  color: var(--text-dim); border: 1px solid var(--border); }

        /* MODALS */
        .form-grid-3 { grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .detail-row { display: flex; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border); align-items: flex-start; }
        .detail-row:last-child { border-bottom: none; }
        .detail-key { font-family: var(--mono); font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .08em; min-width: 110px; padding-top: 1px; }
        .detail-val { font-size: 13px; color: var(--text-pri); font-weight: 600; }

        /* ══════════════════════════════
           QR CODE MODAL
        ══════════════════════════════ */
        .qr-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.6);
            z-index: 1000;
            align-items: center; justify-content: center;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }
        .qr-overlay.open { display: flex; }

        .qr-modal {
            background: var(--bg);
            border: 1px solid var(--border-hi);
            border-radius: 18px;
            width: 480px;
            max-width: calc(100vw - 32px);
            box-shadow: 0 40px 100px rgba(0,0,0,.55);
            animation: qrIn .2s cubic-bezier(.22,.68,0,1.2);
            overflow: hidden;
        }
        @keyframes qrIn {
            from { opacity:0; transform:translateY(-18px) scale(.95); }
            to   { opacity:1; transform:translateY(0)     scale(1);   }
        }

        /* Header band */
        .qr-header {
            padding: 20px 22px 16px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: flex-start; justify-content: space-between;
        }
        .qr-header-left { display:flex; align-items:center; gap:12px; }
        .qr-header-icon {
            width: 40px; height: 40px; border-radius: 10px;
            background: rgba(79,110,247,.12);
            display: grid; place-items: center;
            color: var(--accent-hi); font-size: 18px;
            flex-shrink: 0;
        }
        .qr-title     { font-size: 15px; font-weight: 700; color: var(--text-pri); }
        .qr-subtitle  { font-family: var(--mono); font-size: 11px; color: var(--text-dim); margin-top: 2px; }
        .qr-close {
            width:30px; height:30px; border:1px solid var(--border); background:none;
            border-radius:7px; cursor:pointer; color:var(--text-dim); font-size:17px;
            display:flex; align-items:center; justify-content:center;
            transition:background .15s, color .15s; flex-shrink:0;
        }
        .qr-close:hover { background:var(--panel); color:var(--text-pri); }

        /* QR display area */
        .qr-body { padding: 24px 22px; }

        .qr-canvas-wrap {
            display: flex; flex-direction: column; align-items: center;
            gap: 18px;
        }

        /* The white QR frame */
        .qr-frame {
            position: relative;
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,.25), 0 0 0 1px rgba(255,255,255,.06);
        }
        /* Corner accent lines */
        .qr-frame::before,
        .qr-frame::after {
            content: '';
            position: absolute;
            width: 28px; height: 28px;
            border-color: var(--accent);
            border-style: solid;
        }
        .qr-frame::before { top: -1px; left: -1px; border-width: 3px 0 0 3px; border-radius: 16px 0 0 0; }
        .qr-frame::after  { bottom: -1px; right: -1px; border-width: 0 3px 3px 0; border-radius: 0 0 16px 0; }

        #qrCodeCanvas { display: block; }

        /* Event info strip */
        .qr-event-info {
            width: 100%; background: var(--panel);
            border: 1px solid var(--border-hi);
            border-radius: 10px; padding: 12px 16px;
        }
        .qr-event-name {
            font-size: 14px; font-weight: 700; color: var(--text-pri);
            margin-bottom: 6px; line-height: 1.3;
        }
        .qr-event-meta {
            display: flex; flex-wrap: wrap; gap: 10px;
        }
        .qr-meta-item {
            display: flex; align-items: center; gap: 5px;
            font-family: var(--mono); font-size: 11px; color: var(--text-sec);
        }
        .qr-meta-item i { color: var(--accent-hi); font-size: 10px; }

        /* URL bar */
        .qr-url-bar {
            display: flex; align-items: center; gap: 8px;
            background: var(--panel); border: 1px solid var(--border-hi);
            border-radius: 8px; padding: 8px 12px;
            width: 100%;
        }
        .qr-url-bar i { color: var(--text-dim); font-size: 11px; flex-shrink:0; }
        .qr-url-text {
            flex: 1; font-family: var(--mono); font-size: 11px; color: var(--text-sec);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .qr-copy-btn {
            background: none; border: 1px solid var(--border-hi); border-radius: 5px;
            padding: 3px 8px; font-family: var(--mono); font-size: 10px;
            color: var(--text-dim); cursor: pointer; transition: all .15s; flex-shrink:0;
        }
        .qr-copy-btn:hover { background: rgba(79,110,247,.1); color: var(--accent-hi); border-color: var(--accent); }
        .qr-copy-btn.copied { background: rgba(52,211,153,.1); color: var(--success); border-color: var(--success); }

        /* Footer actions */
        .qr-footer {
            display: flex; gap: 8px; justify-content: flex-end;
            padding: 14px 22px; border-top: 1px solid var(--border);
        }

        /* QR act button for table */
        .act-btn.qr {
            color: var(--accent-hi);
            border-color: rgba(79,110,247,.2);
            background: rgba(79,110,247,.05);
        }
        .act-btn.qr:hover {
            background: rgba(79,110,247,.15);
            border-color: var(--accent);
        }

        /* Loading state inside QR frame */
        .qr-loading {
            width: 200px; height: 200px;
            display: flex; align-items: center; justify-content: center;
            flex-direction: column; gap: 10px;
        }
        .qr-spinner {
            width: 32px; height: 32px;
            border: 3px solid rgba(79,110,247,.2);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: qrSpin .6s linear infinite;
        }
        @keyframes qrSpin { to { transform: rotate(360deg); } }
        .qr-loading p { font-family: var(--mono); font-size: 11px; color: #999; }
    </style>
</head>
<body>

<?= nexusSidebar() ?>

<div class="main">
    <?= nexusTopbar('filterTable') ?>

    <div class="content">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>Manage Events</h1>
                <p><?= $total ?> total event<?= $total != 1 ? 's' : '' ?> &nbsp;·&nbsp; <?= $active ?> active</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-ghost" onclick="exportCSV()"><i class="fas fa-download"></i> Export CSV</button>
                <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Event</button>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="stats-grid">
            <div class="stat-card c1">
                <div class="stat-icon"><i class="fas fa-calendar-days"></i></div>
                <div class="stat-label">Total Events</div>
                <div class="stat-value"><?= $total ?></div>
            </div>
            <div class="stat-card c2">
                <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
                <div class="stat-label">Active</div>
                <div class="stat-value"><?= $active ?></div>
            </div>
            <div class="stat-card c3">
                <div class="stat-icon"><i class="fas fa-calendar-plus"></i></div>
                <div class="stat-label">Upcoming</div>
                <div class="stat-value"><?= $upcoming ?></div>
            </div>
            <div class="stat-card c4">
                <div class="stat-icon"><i class="fas fa-ban"></i></div>
                <div class="stat-label">Inactive</div>
                <div class="stat-value"><?= $inactive ?></div>
            </div>
        </div>

        <!-- Table card -->
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Event Registry</div>
                    <div class="card-subtitle">All scheduled and past events</div>
                </div>
                <div class="card-tools">
                    <div class="filter-search">
                        <i class="fas fa-search"></i>
                        <input type="text" id="tableSearch" placeholder="Filter table..." oninput="filterTable()">
                    </div>
                    <select class="filter-select" id="statusFilter" onchange="filterTable()">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <select class="filter-select" id="timeFilter" onchange="filterTable()">
                        <option value="">All Time</option>
                        <option value="upcoming">Upcoming</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="past">Past</option>
                    </select>
                    <button class="tool-btn" onclick="exportCSV()"><i class="fas fa-file-csv"></i> CSV</button>
                    <button class="tool-btn" onclick="printTable()"><i class="fas fa-print"></i> Print</button>
                </div>
            </div>

            <div class="table-wrap">
                <table class="evt-table" id="eventsTable">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)">Agenda <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(1)">Venue <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(2)">Start Date <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(3)">End Date <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(4)">Days <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th>Timeline</th>
                            <th onclick="sortTable(6)">Status <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th onclick="sortTable(7)">Updated <span class="sort-icon"><i class="fas fa-sort"></i></span></th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="eventsBody">
                        <?php if (empty($events)): ?>
                        <tr><td colspan="9">
                            <div class="empty-state">
                                <i class="fas fa-calendar-xmark"></i>
                                <p>No events found. Click "Add Event" to create one.</p>
                            </div>
                        </td></tr>
                        <?php else: foreach ($events as $row):
                            $now   = time();
                            $start = strtotime($row['start_date']);
                            $end   = strtotime($row['end_date']);
                            if ($start > $now)    { $timeline = 'upcoming'; $tLabel = 'Upcoming'; }
                            elseif ($end >= $now) { $timeline = 'ongoing';  $tLabel = 'Ongoing'; }
                            else                  { $timeline = 'past';     $tLabel = 'Past'; }
                        ?>
                        <tr data-status="<?= $row['active'] ? 'active' : 'inactive' ?>" data-timeline="<?= $timeline ?>">
                            <td class="td-main"><?= htmlspecialchars($row['agenda']) ?></td>
                            <td><?= htmlspecialchars($row['venue']) ?></td>
                            <td class="mono"><?= date('M d, Y', $start) ?></td>
                            <td class="mono"><?= date('M d, Y', $end) ?></td>
                            <td class="mono" style="text-align:center"><?= $row['event_days'] ?></td>
                            <td><span class="badge badge-<?= $timeline ?>"><?= $tLabel ?></span></td>
                            <td><?= $row['active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-inactive">Inactive</span>' ?></td>
                            <td class="mono"><?= $row['updated_at'] ? date('M d, Y', strtotime($row['updated_at'])) : '—' ?></td>
                            <td>
                                <div class="row-actions">
                                    <button class="act-btn view"   title="View"          onclick="viewEvent(<?= $row['id'] ?>)"><i class="fas fa-eye"></i></button>
                                    <button class="act-btn edit"   title="Edit"          onclick="openEditModal(<?= $row['id'] ?>)"><i class="fas fa-pencil"></i></button>
                                    <!-- ✅ NEW: QR Code button -->
                                    <button class="act-btn qr"     title="Share QR Code" onclick="openQRModal(<?= $row['id'] ?>, <?= htmlspecialchars(json_encode([
                                        'agenda'     => $row['agenda'],
                                        'venue'      => $row['venue'],
                                        'start_date' => date('M d, Y', $start),
                                        'end_date'   => date('M d, Y', $end),
                                        'event_days' => $row['event_days'],
                                        'timeline'   => $timeline,
                                    ]), ENT_QUOTES) ?>)"><i class="fas fa-qrcode"></i></button>
                                    <button class="act-btn toggle" title="Toggle Status" onclick="toggleStatus(<?= $row['id'] ?>, <?= $row['active'] ?>)"><i class="fas fa-power-off"></i></button>
                                    <button class="act-btn del"    title="Delete"        onclick="deleteEvent(<?= $row['id'] ?>)"><i class="fas fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-footer">
                <span class="pagination-info" id="paginationInfo"></span>
                <div class="pagination" id="pagination"></div>
            </div>
        </div>

    </div><!-- /content -->

    <?= nexusFooter() ?>
</div><!-- /main -->

<!-- ADD / EDIT MODAL -->
<div class="modal-overlay" id="eventModal">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="modalTitle">Add New Event</span>
            <button class="modal-close" onclick="closeModal('eventModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="eventForm">
                <input type="hidden" name="id"     id="event_id">
                <input type="hidden" name="action" id="action" value="add">
                <div class="form-grid">
                    <div class="field">
                        <label>Agenda</label>
                        <input type="text" name="agenda" id="agenda" placeholder="Event agenda or title" required>
                    </div>
                    <div class="field">
                        <label>Venue</label>
                        <input type="text" name="venue" id="venue" placeholder="Location or platform" required>
                    </div>
                    <div class="form-grid form-grid-3">
                        <div class="field">
                            <label>Start Date</label>
                            <input type="date" name="start_date" id="start_date_modal" required>
                        </div>
                        <div class="field">
                            <label>End Date</label>
                            <input type="date" name="end_date" id="end_date_modal" required>
                        </div>
                        <div class="field">
                            <label>Total Days</label>
                            <input type="text" name="event_days" id="event_days_modal" readonly placeholder="Auto">
                        </div>
                    </div>
                    <div class="field" style="max-width:200px">
                        <label>Status</label>
                        <select name="active" id="active">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('eventModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitEventForm()"><i class="fas fa-floppy-disk"></i> Save Event</button>
        </div>
    </div>
</div>

<!-- VIEW MODAL -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title">Event Details</span>
            <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="viewModalBody"></div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('viewModal')">Close</button>
            <button class="btn btn-primary" id="viewEditBtn"><i class="fas fa-pencil"></i> Edit Event</button>
        </div>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box sm">
        <div class="modal-header">
            <span class="modal-title danger"><i class="fas fa-triangle-exclamation" style="margin-right:8px"></i>Delete Event</span>
            <button class="modal-close" onclick="closeModal('deleteModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px;color:var(--text-sec);line-height:1.6">
                This action is <strong style="color:var(--text-pri)">permanent</strong> and cannot be undone.
                The event and all associated data will be removed.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
            <button class="btn btn-danger-ghost" id="confirmDeleteBtn"><i class="fas fa-trash"></i> Delete Permanently</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     QR CODE MODAL
══════════════════════════════════════════ -->
<div class="qr-overlay" id="qrModal" onclick="qrOverlayClick(event)">
    <div class="qr-modal" role="dialog" aria-modal="true" aria-labelledby="qrModalTitle">

        <div class="qr-header">
            <div class="qr-header-left">
                <div class="qr-header-icon"><i class="fas fa-qrcode"></i></div>
                <div>
                    <div class="qr-title" id="qrModalTitle">Event QR Code</div>
                    <div class="qr-subtitle">Scan to open the registration link</div>
                </div>
            </div>
            <button class="qr-close" onclick="closeQRModal()" aria-label="Close">&times;</button>
        </div>

        <div class="qr-body">
            <div class="qr-canvas-wrap">

                <!-- QR frame -->
                <div class="qr-frame">
                    <div id="qrCodeCanvas">
                        <!-- QR renders here -->
                        <div class="qr-loading">
                            <div class="qr-spinner"></div>
                            <p>Generating…</p>
                        </div>
                    </div>
                </div>

                <!-- Event info -->
                <div class="qr-event-info">
                    <div class="qr-event-name" id="qrEventName">—</div>
                    <div class="qr-event-meta">
                        <span class="qr-meta-item"><i class="fas fa-map-pin"></i><span id="qrEventVenue">—</span></span>
                        <span class="qr-meta-item"><i class="fas fa-calendar"></i><span id="qrEventDates">—</span></span>
                        <span class="qr-meta-item"><i class="fas fa-sun"></i><span id="qrEventDays">—</span></span>
                    </div>
                </div>

                <!-- URL bar -->
                <div class="qr-url-bar">
                    <i class="fas fa-link"></i>
                    <span class="qr-url-text" id="qrUrlText">—</span>
                    <button class="qr-copy-btn" id="qrCopyBtn" onclick="copyQRUrl()">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>

            </div>
        </div>

        <div class="qr-footer">
            <button class="btn btn-ghost" onclick="closeQRModal()">Close</button>
            <button class="btn btn-ghost" onclick="printQR()">
                <i class="fas fa-print"></i> Print
            </button>
            <button class="btn btn-primary" onclick="downloadQR()">
                <i class="fas fa-download"></i> Download PNG
            </button>
        </div>

    </div>
</div>

<?= nexusToast() ?>
<?= nexusJS() ?>

<script>
/* ── Date → Days auto-calc ── */
function calcDays() {
    const s = document.getElementById('start_date_modal').value;
    const e = document.getElementById('end_date_modal').value;
    if (!s || !e) { document.getElementById('event_days_modal').value = ''; return; }
    const d = Math.round((new Date(e) - new Date(s)) / 86400000) + 1;
    document.getElementById('event_days_modal').value = d > 0 ? d : '';
}
document.getElementById('start_date_modal').addEventListener('change', calcDays);
document.getElementById('end_date_modal').addEventListener('change', calcDays);

/* ── Add modal ── */
function openAddModal() {
    document.getElementById('eventForm').reset();
    document.getElementById('event_id').value = '';
    document.getElementById('action').value   = 'add';
    document.getElementById('event_days_modal').value = '';
    document.getElementById('modalTitle').textContent = 'Add New Event';
    openModal('eventModal');
}

/* ── Edit modal ── */
function openEditModal(id) {
    fetch(`fetch_event.php?id=${id}`)
        .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
        .then(data => {
            if (data.error) { showToast(data.error, 'error'); return; }
            document.getElementById('modalTitle').textContent    = 'Edit Event';
            document.getElementById('action').value             = 'update';
            document.getElementById('event_id').value           = data.id;
            document.getElementById('agenda').value             = data.agenda;
            document.getElementById('venue').value              = data.venue;
            document.getElementById('start_date_modal').value   = data.start_date;
            document.getElementById('end_date_modal').value     = data.end_date;
            document.getElementById('event_days_modal').value   = data.event_days;
            document.getElementById('active').value             = data.active;
            openModal('eventModal');
        })
        .catch(err => showToast('Error loading event: ' + err.message, 'error'));
}

/* ── Submit form ── */
function submitEventForm() {
    const form = document.getElementById('eventForm');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const days = document.getElementById('event_days_modal').value;
    if (!days || days <= 0) { showToast('Invalid date range', 'error'); return; }
    fetch('event_actions_modal.php', { method: 'POST', body: new FormData(form) })
        .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
        .then(d => {
            if (d.status === 'success') {
                showToast(d.message || 'Event saved successfully');
                closeModal('eventModal');
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(d.message || 'An error occurred', 'error');
            }
        })
        .catch(err => showToast('Server error: ' + err.message, 'error'));
}

/* ── View event ── */
function viewEvent(id) {
    fetch(`fetch_event.php?id=${id}`)
        .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
        .then(d => {
            const now = Date.now(), s = new Date(d.start_date).getTime(), e = new Date(d.end_date).getTime();
            const tl = s > now
                ? '<span class="badge badge-upcoming">Upcoming</span>'
                : e >= now
                    ? '<span class="badge badge-ongoing">Ongoing</span>'
                    : '<span class="badge badge-past">Past</span>';
            const st = d.active == 1
                ? '<span class="badge badge-success">Active</span>'
                : '<span class="badge badge-inactive">Inactive</span>';
            document.getElementById('viewModalBody').innerHTML = `
                <div class="detail-row"><span class="detail-key">Agenda</span><span class="detail-val">${d.agenda}</span></div>
                <div class="detail-row"><span class="detail-key">Venue</span><span class="detail-val">${d.venue}</span></div>
                <div class="detail-row"><span class="detail-key">Start Date</span><span class="detail-val" style="font-family:var(--mono)">${d.start_date}</span></div>
                <div class="detail-row"><span class="detail-key">End Date</span><span class="detail-val" style="font-family:var(--mono)">${d.end_date}</span></div>
                <div class="detail-row"><span class="detail-key">Total Days</span><span class="detail-val" style="font-family:var(--mono)">${d.event_days}</span></div>
                <div class="detail-row"><span class="detail-key">Timeline</span><span class="detail-val">${tl}</span></div>
                <div class="detail-row"><span class="detail-key">Status</span><span class="detail-val">${st}</span></div>`;
            document.getElementById('viewEditBtn').onclick = () => { closeModal('viewModal'); openEditModal(id); };
            openModal('viewModal');
        })
        .catch(err => showToast('Error loading event: ' + err.message, 'error'));
}

/* ── Toggle status ── */
function toggleStatus(id, current) {
    const fd = new FormData();
    fd.append('action', 'toggle'); fd.append('id', id); fd.append('active', current ? 0 : 1);
    fetch('event_actions_modal.php', { method: 'POST', body: fd })
        .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
        .then(d => {
            if (d.status === 'success') {
                showToast(`Event ${current ? 'deactivated' : 'activated'} successfully`);
                setTimeout(() => location.reload(), 600);
            } else {
                showToast(d.message || 'Error updating status', 'error');
            }
        })
        .catch(err => showToast('Server error: ' + err.message, 'error'));
}

/* ── Delete ── */
let pendingDeleteId = null;
function deleteEvent(id) {
    pendingDeleteId = id;
    openModal('deleteModal');
}
document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
    if (!pendingDeleteId) return;
    const fd = new FormData();
    fd.append('action', 'delete'); fd.append('id', pendingDeleteId);
    fetch('event_actions_modal.php', { method: 'POST', body: fd })
        .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
        .then(d => {
            closeModal('deleteModal');
            if (d.status === 'success') {
                showToast('Event deleted permanently');
                setTimeout(() => location.reload(), 600);
            } else {
                showToast(d.message || 'Error deleting event', 'error');
            }
        })
        .catch(err => showToast('Server error: ' + err.message, 'error'));
});

/* ═══════════════════════════════════════════
   QR CODE MODAL
═══════════════════════════════════════════ */
let _qrInstance  = null;   // QRCode object — reused across opens
let _qrCurrentId = null;   // currently displayed event ID
let _qrCurrentUrl = '';    // currently displayed URL

/**
 * openQRModal(id, eventData)
 *
 * eventData shape:
 *   { agenda, venue, start_date, end_date, event_days, timeline }
 *
 * The QR encodes a registration URL:
 *   <origin>/register.php?event_id=<id>
 *
 * Adjust the URL pattern to match your actual registration page.
 */
function openQRModal(id, eventData) {
    _qrCurrentId  = id;

        // ── Build the registration URL ──────────────────────────────────
    // Replace with your actual server IP or domain
    const base = 'http://192.168.1.21/nexus/'; 

    _qrCurrentUrl = `${base}register.php?event_id=${id}`;

    // ── Populate event info panel ───────────────────────────────────
    document.getElementById('qrEventName').textContent  = eventData.agenda  || '—';
    document.getElementById('qrEventVenue').textContent = eventData.venue   || '—';
    document.getElementById('qrEventDates').textContent =
        `${eventData.start_date} – ${eventData.end_date}`;
    document.getElementById('qrEventDays').textContent  =
        `${eventData.event_days} day${eventData.event_days != 1 ? 's' : ''}`;
    document.getElementById('qrUrlText').textContent    = _qrCurrentUrl;

    // Reset copy button
    const copyBtn = document.getElementById('qrCopyBtn');
    copyBtn.classList.remove('copied');
    copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy';

    // ── Render QR ───────────────────────────────────────────────────
    const container = document.getElementById('qrCodeCanvas');
    container.innerHTML = ''; // clear previous

    try {
        _qrInstance = new QRCode(container, {
            text:           _qrCurrentUrl,
            width:          200,
            height:         200,
            colorDark:      '#0f172a',   // dark modules
            colorLight:     '#ffffff',   // light modules
            correctLevel:   QRCode.CorrectLevel.H,  // high error correction
        });
    } catch(e) {
        container.innerHTML = `<div class="qr-loading"><p style="color:var(--danger)">QR generation failed.</p></div>`;
    }

    // ── Show overlay ────────────────────────────────────────────────
    document.getElementById('qrModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeQRModal() {
    document.getElementById('qrModal').classList.remove('open');
    document.body.style.overflow = '';
}

function qrOverlayClick(e) {
    if (e.target === document.getElementById('qrModal')) closeQRModal();
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeQRModal();
});

/* ── Copy URL ── */
function copyQRUrl() {
    if (!_qrCurrentUrl) return;
    navigator.clipboard.writeText(_qrCurrentUrl).then(() => {
        const btn = document.getElementById('qrCopyBtn');
        btn.classList.add('copied');
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(() => {
            btn.classList.remove('copied');
            btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
        }, 2000);
        showToast('Link copied to clipboard');
    }).catch(() => showToast('Could not copy — please copy manually', 'error'));
}

/* ── Download QR as PNG ── */
function downloadQR() {
    const container = document.getElementById('qrCodeCanvas');
    const canvas    = container.querySelector('canvas');
    const img       = container.querySelector('img');

    if (canvas) {
        const a = document.createElement('a');
        a.href     = canvas.toDataURL('image/png');
        a.download = `event_qr_${_qrCurrentId}.png`;
        a.click();
        showToast('QR code downloaded');
    } else if (img) {
        // QRCode.js may render an <img> in some browsers
        const tmp = document.createElement('canvas');
        tmp.width  = img.naturalWidth  || 200;
        tmp.height = img.naturalHeight || 200;
        tmp.getContext('2d').drawImage(img, 0, 0);
        const a = document.createElement('a');
        a.href     = tmp.toDataURL('image/png');
        a.download = `event_qr_${_qrCurrentId}.png`;
        a.click();
        showToast('QR code downloaded');
    } else {
        showToast('QR not ready yet — please try again', 'error');
    }
}

/* ── Print QR ── */
function printQR() {
    const container = document.getElementById('qrCodeCanvas');
    const canvas    = container.querySelector('canvas');
    const img       = container.querySelector('img');

    let imgSrc = '';
    if (canvas) {
        imgSrc = canvas.toDataURL('image/png');
    } else if (img) {
        imgSrc = img.src;
    } else {
        showToast('QR not ready yet', 'error'); return;
    }

    const eventName  = document.getElementById('qrEventName').textContent;
    const eventVenue = document.getElementById('qrEventVenue').textContent;
    const eventDates = document.getElementById('qrEventDates').textContent;
    const url        = _qrCurrentUrl;

    const win = window.open('', '_blank');
    win.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>QR Code – ${eventName}</title>
            <style>
                * { margin:0; padding:0; box-sizing:border-box; }
                body { font-family: 'Segoe UI', sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; background:#f8f9fc; }
                .card { background:#fff; border-radius:16px; padding:36px 40px; text-align:center; box-shadow:0 4px 24px rgba(0,0,0,.1); max-width:360px; }
                h2   { font-size:17px; font-weight:700; color:#1e1e2e; margin-bottom:4px; }
                .meta { font-size:12px; color:#666; margin-bottom:20px; }
                img  { width:200px; height:200px; display:block; margin:0 auto 16px; }
                .url { font-family:monospace; font-size:10px; color:#888; word-break:break-all; margin-top:12px; }
                .hint{ font-size:11px; color:#aaa; margin-top:8px; }
            </style>
        </head>
        <body>
            <div class="card">
                <h2>${eventName}</h2>
                <p class="meta">${eventVenue} &nbsp;·&nbsp; ${eventDates}</p>
                <img src="${imgSrc}" alt="QR Code">
                <p class="hint">Scan this QR code to register</p>
                <p class="url">${url}</p>
            </div>
            <script>window.onload=()=>{window.print();}<\/script>
        </body>
        </html>
    `);
    win.document.close();
}

/* ── Filter + Sort + Paginate ── */
const PAGE_SIZE = 10;
let currentPage = 1, sortCol = -1, sortDir = 'asc';

function getAllRows() {
    return Array.from(document.querySelectorAll('#eventsBody tr[data-status]'));
}

function filterTable() {
    const q      = (document.getElementById('tableSearch')?.value || '').toLowerCase();
    const status = document.getElementById('statusFilter').value;
    const time   = document.getElementById('timeFilter').value;
    getAllRows().forEach(row => {
        const matchQ = !q      || row.textContent.toLowerCase().includes(q);
        const matchS = !status || row.dataset.status   === status;
        const matchT = !time   || row.dataset.timeline === time;
        row.style.display = (matchQ && matchS && matchT) ? '' : 'none';
    });
    currentPage = 1;
    renderPagination();
}

function sortTable(col) {
    const ths = document.querySelectorAll('#eventsTable thead th');
    ths.forEach(th => th.classList.remove('sorted-asc', 'sorted-desc'));
    if (sortCol === col) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    else { sortCol = col; sortDir = 'asc'; }
    ths[col].classList.add(sortDir === 'asc' ? 'sorted-asc' : 'sorted-desc');
    const rows  = getAllRows();
    const tbody = document.getElementById('eventsBody');
    rows.sort((a, b) => {
        const at = a.cells[col]?.textContent.trim() || '';
        const bt = b.cells[col]?.textContent.trim() || '';
        return sortDir === 'asc'
            ? at.localeCompare(bt, undefined, { numeric: true })
            : bt.localeCompare(at, undefined, { numeric: true });
    });
    rows.forEach(r => tbody.appendChild(r));
    filterTable();
}

function renderPagination() {
    const visible = getAllRows().filter(r => r.style.display !== 'none');
    const total   = visible.length;
    const pages   = Math.max(1, Math.ceil(total / PAGE_SIZE));
    if (currentPage > pages) currentPage = pages;
    visible.forEach((r, i) => {
        r.style.display = (i >= (currentPage - 1) * PAGE_SIZE && i < currentPage * PAGE_SIZE) ? '' : 'none';
    });
    const from = total === 0 ? 0 : (currentPage - 1) * PAGE_SIZE + 1;
    const to   = Math.min(currentPage * PAGE_SIZE, total);
    document.getElementById('paginationInfo').textContent = `Showing ${from}–${to} of ${total} events`;

    const pg = document.getElementById('pagination');
    pg.innerHTML = '';
    const mkBtn = (lbl, p, dis, act) => {
        const b = document.createElement('button');
        b.className = 'page-btn' + (act ? ' active' : '');
        b.innerHTML = lbl; b.disabled = dis;
        b.onclick   = () => { currentPage = p; renderPagination(); };
        return b;
    };
    pg.appendChild(mkBtn('<i class="fas fa-chevron-left"></i>', currentPage - 1, currentPage === 1, false));
    let rs = Math.max(1, currentPage - 2), re = Math.min(pages, rs + 4);
    if (re - rs < 4) rs = Math.max(1, re - 4);
    if (rs > 1) { pg.appendChild(mkBtn('1', 1, false, false)); if (rs > 2) pg.appendChild(Object.assign(document.createElement('span'), { className: 'page-btn', style: 'cursor:default', textContent: '…' })); }
    for (let p = rs; p <= re; p++) pg.appendChild(mkBtn(p, p, false, p === currentPage));
    if (re < pages) { if (re < pages - 1) pg.appendChild(Object.assign(document.createElement('span'), { className: 'page-btn', style: 'cursor:default', textContent: '…' })); pg.appendChild(mkBtn(pages, pages, false, false)); }
    pg.appendChild(mkBtn('<i class="fas fa-chevron-right"></i>', currentPage + 1, currentPage === pages, false));
}

function exportCSV() {
    const headers = ['Agenda', 'Venue', 'Start Date', 'End Date', 'Days', 'Timeline', 'Status', 'Updated'];
    const rows    = getAllRows().filter(r => r.style.display !== 'none');
    if (!rows.length) { showToast('No data to export', 'error'); return; }
    const lines = [headers.join(',')];
    rows.forEach(r => {
        const cols = Array.from(r.cells).slice(0, 8).map(c => `"${c.textContent.trim().replace(/"/g, '""')}"`);
        lines.push(cols.join(','));
    });
    const a = document.createElement('a');
    a.href     = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
    a.download = `events_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    showToast('CSV exported successfully');
}

function printTable() { window.print(); }

document.addEventListener('DOMContentLoaded', () => renderPagination());
</script>
</body>
</html>