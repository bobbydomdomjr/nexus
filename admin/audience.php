<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}
require '../config/db.php';

/* ─────────────────────────────────────────────
   Sidebar component setup
───────────────────────────────────────────── */
$activePage   = 'audience';
$pageTitle    = 'Audience';
$pageSubtitle = 'Audience List';
$docTitle     = 'Nexus Platform';

require '_sidebar.php';

/* ─────────────────────────────────────────────
   Fetch events for filter dropdown
───────────────────────────────────────────── */
$stmt   = $pdo->query("SELECT * FROM event_settings ORDER BY start_date DESC");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ─────────────────────────────────────────────
   Pre-select event if coming from reports.php
───────────────────────────────────────────── */
$preselectedEventId = (int)($_GET['event_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= nexusHead() ?>

    <!-- jsPDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.6.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <!-- SheetJS -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>

    <style>
        /* ── FILTERS CARD ── */
        .filter-card { background: var(--panel); border: 1px solid var(--border-hi); border-radius: var(--radius); padding: 20px 24px; margin-bottom: 16px; }
        .filter-row  { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-family: var(--mono); font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .1em; }
        .filter-group select,
        .filter-group input[type="date"] {
            background: var(--bg); border: 1px solid var(--border-hi); border-radius: 8px;
            padding: 8px 12px; font-family: var(--mono); font-size: 12px; color: var(--text-pri);
            outline: none; transition: border-color .2s; min-width: 180px;
        }
        .filter-group select:focus,
        .filter-group input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
        .filter-group select option { background: var(--panel); }

        /* event preview pill */
        .event-preview {
            display: none; align-items: center; gap: 10px;
            padding: 8px 14px;
            background: rgba(79,110,247,.07); border: 1px solid rgba(79,110,247,.2);
            border-radius: 8px; font-family: var(--mono); font-size: 11px;
            color: var(--text-sec); flex-wrap: wrap; margin-top: 14px;
        }
        .event-preview.visible { display: flex; }
        .event-preview strong  { color: var(--accent-hi); }

        /* ── TABLE ── */
        .tbl-search { display: flex; align-items: center; gap: 8px; background: var(--bg); border: 1px solid var(--border-hi); border-radius: 7px; padding: 6px 12px; }
        .tbl-search input { background: none; border: none; outline: none; font-family: var(--mono); font-size: 12px; color: var(--text-pri); width: 200px; }
        .tbl-search input::placeholder { color: var(--text-dim); }
        .tbl-search i { color: var(--text-dim); font-size: 12px; }

        table.aud-table { width: 100%; border-collapse: collapse; }
        table.aud-table thead th {
            font-family: var(--mono); font-size: 10px; color: var(--text-dim);
            text-transform: uppercase; letter-spacing: .1em;
            padding: 10px 16px; background: var(--bg);
            border-bottom: 1px solid var(--border);
            text-align: left; white-space: nowrap;
            cursor: pointer; user-select: none;
        }
        table.aud-table thead th .sort-icon { margin-left: 4px; opacity: .4; }
        table.aud-table thead th.sorted-asc  .sort-icon,
        table.aud-table thead th.sorted-desc .sort-icon { opacity: 1; color: var(--accent-hi); }
        table.aud-table tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
        table.aud-table tbody tr:last-child  { border-bottom: none; }
        table.aud-table tbody tr:hover       { background: rgba(79,110,247,.04); }
        table.aud-table tbody td { padding: 12px 16px; font-size: 13px; color: var(--text-sec); vertical-align: middle; }
        table.aud-table tbody td.td-main { color: var(--text-pri); font-weight: 600; }
        table.aud-table tbody td.mono    { font-family: var(--mono); font-size: 12px; }

        .avatar-cell { display: flex; align-items: center; gap: 10px; }
        .row-avatar  { width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 12px; font-weight: 700; color: #fff; flex-shrink: 0; }

        /* loading */
        .tbl-loading { display: none; padding: 48px 0; text-align: center; }
        .tbl-loading.active { display: block; }
        .spinner { width: 32px; height: 32px; border: 3px solid var(--border-hi); border-top-color: var(--accent); border-radius: 50%; animation: spin .7s linear infinite; margin: 0 auto 12px; }
        .tbl-loading p { font-family: var(--mono); font-size: 12px; color: var(--text-dim); }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* export dropdown */
        .export-dropdown { position: relative; }
        .export-menu {
            display: none; position: absolute; top: calc(100% + 6px); right: 0;
            background: var(--panel-hi); border: 1px solid var(--border-hi);
            border-radius: 8px; overflow: hidden; min-width: 180px;
            box-shadow: 0 8px 24px rgba(0,0,0,.4); z-index: 50;
        }
        .export-menu.open { display: block; }
        .export-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 16px; font-family: var(--mono); font-size: 12px;
            color: var(--text-sec); cursor: pointer; transition: all .15s;
            border: none; background: none; width: 100%;
        }
        .export-item:hover { background: rgba(79,110,247,.08); color: var(--text-pri); }
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

            <!-- Selected event preview pill -->
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

                    <!-- Export dropdown -->
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

            <!-- Loading spinner -->
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
                        </tr>
                    </thead>
                    <tbody id="audBody">
                        <tr><td colspan="6">
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

<?= nexusToast() ?>
<?= nexusJS() ?>

<script>
// ══════════════════════════════════════════
// STATE
// ══════════════════════════════════════════
let allData      = [];
let filteredData = [];
let currentPage  = 1;
let pageSize     = 10;
let sortCol      = -1;
let sortDir      = 'asc';

// ══════════════════════════════════════════
// INIT — auto-load if preselected from reports
// ══════════════════════════════════════════
window.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('eventFilter');
    if (sel.value) {
        onEventChange();
    }
});

// ══════════════════════════════════════════
// EVENT FILTER CHANGE
// ══════════════════════════════════════════
function onEventChange() {
    const sel    = document.getElementById('eventFilter');
    const opt    = sel.options[sel.selectedIndex];
    const start  = opt.dataset.start  || '';
    const end    = opt.dataset.end    || '';
    const agenda = opt.dataset.agenda || '';

    // Remove min/max BEFORE setting values
    document.getElementById('dateFrom').removeAttribute('min');
    document.getElementById('dateFrom').removeAttribute('max');
    document.getElementById('dateTo').removeAttribute('min');
    document.getElementById('dateTo').removeAttribute('max');

    if (sel.value) {
        document.getElementById('dateFrom').value = start;
        document.getElementById('dateTo').value   = end;
        document.getElementById('eventPreview').classList.add('visible');
        document.getElementById('previewAgenda').textContent = agenda;
        document.getElementById('previewDates').textContent  = `${start} → ${end}`;
        document.getElementById('statEvent').textContent     = agenda;
    } else {
        document.getElementById('dateFrom').value = '';
        document.getElementById('dateTo').value   = '';
        document.getElementById('eventPreview').classList.remove('visible');
        document.getElementById('statEvent').textContent = 'All Events';
    }

    // Defer so DOM values settle before fetch reads them
    setTimeout(loadData, 0);
}

function clearFilters() {
    document.getElementById('eventFilter').value = '';
    document.getElementById('dateFrom').value    = '';
    document.getElementById('dateTo').value      = '';
    document.getElementById('tableSearch').value = '';
    document.getElementById('eventPreview').classList.remove('visible');
    document.getElementById('statEvent').textContent = 'All Events';
    document.getElementById('statRange').textContent = '—';
    allData = []; filteredData = [];
    renderTable();
}

// ══════════════════════════════════════════
// LOAD DATA (AJAX)
// ══════════════════════════════════════════
function loadData() {
    const eventId  = document.getElementById('eventFilter').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo   = document.getElementById('dateTo').value;

    document.getElementById('tblLoading').classList.add('active');
    document.getElementById('tblWrap').style.opacity = '.3';

    const fd = new FormData();
    fd.append('event_id',  eventId);
    fd.append('date_from', dateFrom);
    fd.append('date_to',   dateTo);
    fd.append('draw',      1);
    fd.append('start',     0);
    fd.append('length',    9999);

    fetch('fetch_registrations.php', { method: 'POST', body: fd })
        .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
        .then(resp => {
            document.getElementById('tblLoading').classList.remove('active');
            document.getElementById('tblWrap').style.opacity = '1';

            allData      = resp.data || [];
            filteredData = [...allData];
            currentPage  = 1;

            document.getElementById('statTotal').textContent = allData.length;
            const from = document.getElementById('dateFrom').value;
            const to   = document.getElementById('dateTo').value;
            document.getElementById('statRange').textContent =
                (from && to) ? `${from} → ${to}` : (from || to || '—');

            applySearch();
            showToast(`${allData.length} record${allData.length !== 1 ? 's' : ''} loaded`, 'info');
        })
        .catch(err => {
            document.getElementById('tblLoading').classList.remove('active');
            document.getElementById('tblWrap').style.opacity = '1';
            showToast('Failed to fetch data: ' + err.message, 'error');
        });
}

// ══════════════════════════════════════════
// SEARCH
// ══════════════════════════════════════════
function handleSearch() {
    const topQ = document.getElementById('topbarSearch')?.value || '';
    const tblQ = document.getElementById('tableSearch').value   || '';
    if (document.activeElement?.id === 'topbarSearch') {
        document.getElementById('tableSearch').value = topQ;
    } else {
        if (document.getElementById('topbarSearch'))
            document.getElementById('topbarSearch').value = tblQ;
    }
    applySearch();
}

function applySearch() {
    const q = (document.getElementById('tableSearch').value || '').toLowerCase();
    filteredData = q
        ? allData.filter(row => Object.values(row).some(v => String(v || '').toLowerCase().includes(q)))
        : [...allData];
    currentPage = 1;
    renderTable();
}

// ══════════════════════════════════════════
// SORT
// ══════════════════════════════════════════
const COLS = ['fullname','unit_office','serial_number','designation','email','contact_number'];

function sortTable(col) {
    const ths = document.querySelectorAll('#audTable thead th');
    ths.forEach(th => th.classList.remove('sorted-asc','sorted-desc'));

    if (sortCol === col) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    else { sortCol = col; sortDir = 'asc'; }

    ths[col].classList.add(sortDir === 'asc' ? 'sorted-asc' : 'sorted-desc');

    const key = COLS[col];
    filteredData.sort((a, b) => {
        const av = String(a[key] || ''), bv = String(b[key] || '');
        return sortDir === 'asc'
            ? av.localeCompare(bv, undefined, { numeric: true })
            : bv.localeCompare(av, undefined, { numeric: true });
    });
    currentPage = 1;
    renderTable();
}

// ══════════════════════════════════════════
// PAGE SIZE
// ══════════════════════════════════════════
function changePageSize() {
    pageSize = parseInt(document.getElementById('pageSizeSelect').value);
    currentPage = 1;
    renderTable();
}

// ══════════════════════════════════════════
// RENDER TABLE
// ══════════════════════════════════════════
function esc(v) {
    return String(v || '—').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function getInitials(name) {
    return (name || '?').split(' ').slice(0,2).map(w => w[0]).join('').toUpperCase();
}
const AVATAR_COLORS = ['#4f6ef7','#34d399','#f5a623','#e05c6a','#a78bfa','#38bdf8'];
function avatarColor(name) {
    let h = 0;
    for (const c of (name || '')) h = (h * 31 + c.charCodeAt(0)) % AVATAR_COLORS.length;
    return AVATAR_COLORS[h];
}

function renderTable() {
    const tbody = document.getElementById('audBody');
    const total = filteredData.length;
    const pages = Math.max(1, Math.ceil(total / pageSize));
    if (currentPage > pages) currentPage = pages;

    const start = (currentPage - 1) * pageSize;
    const slice = filteredData.slice(start, start + pageSize);

    document.getElementById('statPage').textContent   = currentPage;
    document.getElementById('statPageOf').textContent = `of ${pages}`;

    if (total === 0) {
        tbody.innerHTML = `<tr><td colspan="6">
            <div class="empty-state">
                <i class="fas fa-users-slash"></i>
                <p>No records match your search or filters.</p>
            </div>
        </td></tr>`;
        document.getElementById('paginationInfo').textContent = 'No records found';
        document.getElementById('pagination').innerHTML       = '';
        document.getElementById('tableSubtitle').textContent  = 'No matching records';
        return;
    }

    tbody.innerHTML = slice.map(row => {
        const initials = getInitials(row.fullname);
        const color    = avatarColor(row.fullname);
        return `<tr>
            <td class="td-main">
                <div class="avatar-cell">
                    <div class="row-avatar" style="background:${color}">${initials}</div>
                    <span>${esc(row.fullname)}</span>
                </div>
            </td>
            <td>${esc(row.unit_office)}</td>
            <td class="mono">${esc(row.serial_number)}</td>
            <td>${esc(row.designation)}</td>
            <td class="mono">${esc(row.email)}</td>
            <td class="mono">${esc(row.contact_number)}</td>
        </tr>`;
    }).join('');

    const from = start + 1;
    const to   = Math.min(start + pageSize, total);
    document.getElementById('paginationInfo').textContent = `Showing ${from}–${to} of ${total} records`;
    document.getElementById('tableSubtitle').textContent  = `${total} registration${total !== 1 ? 's' : ''} found`;

    // Pagination
    const pg = document.getElementById('pagination');
    pg.innerHTML = '';

    const mkBtn = (label, page, disabled, active) => {
        const b = document.createElement('button');
        b.className = 'page-btn' + (active ? ' active' : '');
        b.innerHTML = label;
        b.disabled  = disabled;
        b.onclick   = () => { currentPage = page; renderTable(); };
        return b;
    };

    pg.appendChild(mkBtn('<i class="fas fa-chevron-left"></i>', currentPage - 1, currentPage === 1, false));

    let rs = Math.max(1, currentPage - 2);
    let re = Math.min(pages, rs + 4);
    if (re - rs < 4) rs = Math.max(1, re - 4);

    if (rs > 1) {
        pg.appendChild(mkBtn('1', 1, false, false));
        if (rs > 2) {
            const dots = document.createElement('span');
            dots.className = 'page-btn'; dots.style.cursor = 'default'; dots.textContent = '…';
            pg.appendChild(dots);
        }
    }
    for (let p = rs; p <= re; p++) pg.appendChild(mkBtn(p, p, false, p === currentPage));
    if (re < pages) {
        if (re < pages - 1) {
            const dots = document.createElement('span');
            dots.className = 'page-btn'; dots.style.cursor = 'default'; dots.textContent = '…';
            pg.appendChild(dots);
        }
        pg.appendChild(mkBtn(pages, pages, false, false));
    }
    pg.appendChild(mkBtn('<i class="fas fa-chevron-right"></i>', currentPage + 1, currentPage === pages, false));
}

// ══════════════════════════════════════════
// EXPORT — CSV
// ══════════════════════════════════════════
function exportCSV() {
    if (!filteredData.length) { showToast('No data to export', 'error'); return; }
    const headers = ['Rank/Name/Major Service','Unit/Office','Serial Number','Designation','Email','Contact Number'];
    const lines   = [headers.join(',')];
    filteredData.forEach(r => {
        lines.push([r.fullname, r.unit_office, r.serial_number, r.designation, r.email, r.contact_number]
            .map(v => `"${String(v || '').replace(/"/g,'""')}"`).join(','));
    });
    const a = document.createElement('a');
    a.href     = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
    a.download = `audience_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    showToast('CSV exported successfully');
}

// ══════════════════════════════════════════
// EXPORT — Excel (server-side)
// ══════════════════════════════════════════
function exportExcel() {
    if (!filteredData.length) { showToast('No data to export', 'error'); return; }
    const eventId = document.getElementById('eventFilter').value;
    if (!eventId) { showToast('Please select an event first', 'error'); return; }
    const params = new URLSearchParams({
        event_id:  eventId,
        date_from: document.getElementById('dateFrom').value,
        date_to:   document.getElementById('dateTo').value
    });
    window.location.href = 'export_attendance_excel.php?' + params.toString();
    showToast('Excel export started');
}

// ══════════════════════════════════════════
// EXPORT — PDF (server-side)
// ══════════════════════════════════════════
function exportPDF() {
    if (!filteredData.length) { showToast('No data to export', 'error'); return; }

    const sel     = document.getElementById('eventFilter');
    const eventId = sel.value;

    if (!eventId) {
        clientPDF();
        return;
    }

    const opt    = sel.options[sel.selectedIndex];
    const agenda = opt.dataset.agenda || '';
    const venue  = opt.dataset.venue  || '';
    const params = new URLSearchParams({
        agenda,
        venue,
        date_from: document.getElementById('dateFrom').value,
        date_to:   document.getElementById('dateTo').value
    });
    window.open('export_attendance_pdf.php?' + params.toString(), '_blank');
    showToast('PDF export started');
}

function clientPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape' });

    doc.setFontSize(14);
    doc.setTextColor(40, 40, 80);
    doc.text('Audience Registration List', 14, 16);
    doc.setFontSize(9);
    doc.setTextColor(120, 130, 160);
    doc.text(`Generated: ${new Date().toLocaleString()}`, 14, 22);

    doc.autoTable({
        startY: 28,
        head: [['Rank / Name / Major Service','Unit / Office','Serial Number','Designation','Email','Contact Number']],
        body: filteredData.map(r => [r.fullname, r.unit_office, r.serial_number, r.designation, r.email, r.contact_number]),
        styles:           { fontSize: 8, cellPadding: 3 },
        headStyles:       { fillColor: [79,110,247], textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [245,246,250] }
    });

    doc.save(`audience_${new Date().toISOString().slice(0,10)}.pdf`);
    showToast('PDF exported successfully');
}

// ══════════════════════════════════════════
// EXPORT DROPDOWN
// ══════════════════════════════════════════
function toggleExportMenu() {
    document.getElementById('exportMenu').classList.toggle('open');
}
document.addEventListener('click', e => {
    if (!e.target.closest('.export-dropdown'))
        document.getElementById('exportMenu').classList.remove('open');
});
</script>
</body>
</html>