<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}
require '../config/db.php';

$userName = $_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Admin';

$activePage   = 'dashboard';
$pageTitle    = 'Dashboard';
$pageSubtitle = 'Overview';
$docTitle     = 'Nexus Platform';

require '_sidebar.php';

$nexusRole = $NEXUS['userRole'] ?? 'admin';
if (!in_array($nexusRole, ['admin', 'staff', 'viewer'], true)) {
    $nexusRole = 'admin';
}

/* ─────────────────────────────────────────────
   EVENTS
───────────────────────────────────────────── */
$evtStmt      = $pdo->query("SELECT * FROM event_settings ORDER BY start_date DESC");
$events       = $evtStmt->fetchAll(PDO::FETCH_ASSOC);
$totalEvents  = count($events);
$now          = time();
$upcomingEvts = count(array_filter($events, fn($e) => strtotime($e['start_date']) > $now));
$ongoingEvts  = count(array_filter($events, fn($e) => strtotime($e['start_date']) <= $now && strtotime($e['end_date']) >= $now));
$pastEvts     = count(array_filter($events, fn($e) => strtotime($e['end_date']) < $now));

// active column may not exist — safe fallback
$activeEvents = 0;
try {
    $activeEvents = (int)$pdo->query("SELECT COUNT(*) FROM event_settings WHERE active = 1")->fetchColumn();
} catch (PDOException $e) {
    $activeEvents = $totalEvents;
}

/* ─────────────────────────────────────────────
   USERS
───────────────────────────────────────────── */
$colsStmt    = $pdo->query("DESCRIBE admin_users");
$cols        = array_column($colsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
$hasMigrated = in_array('status', $cols) && in_array('role', $cols);
$usrStmt     = $pdo->query("SELECT * FROM admin_users ORDER BY created_at DESC");
$users       = $usrStmt->fetchAll(PDO::FETCH_ASSOC);
$totalUsers  = count($users);
$activeUsers = $hasMigrated
    ? count(array_filter($users, fn($u) => ($u['status'] ?? 'active') === 'active'))
    : $totalUsers;

/* ─────────────────────────────────────────────
   TOTAL REGISTRATIONS — event_registrations table
───────────────────────────────────────────── */
$totalRegistrations = (int)$pdo->query("SELECT COUNT(*) FROM event_registrations")->fetchColumn();

/* ─────────────────────────────────────────────
   RECENT REGISTRATIONS
   — join via TRIM(agenda) match, no event_id FK
───────────────────────────────────────────── */
$recentRegistrations = $pdo->query("
    SELECT
        CONCAT(
            COALESCE(TRIM(rank),''), ' ',
            COALESCE(TRIM(first_name),''), ' ',
            COALESCE(TRIM(last_name),''),
            COALESCE(CONCAT(', ', NULLIF(TRIM(middle_initial),'')), ''),
            COALESCE(CONCAT(' ', NULLIF(TRIM(ext_name),'')),'')
        ) AS fullname,
        unit_office,
        designation,
        email,
        agenda,
        created_at
    FROM event_registrations
    ORDER BY created_at DESC
    LIMIT 7
")->fetchAll(PDO::FETCH_ASSOC);

/* ─────────────────────────────────────────────
   REGISTRATIONS PER EVENT (bar chart)
   — group by agenda text, join to event_settings
   for display order
───────────────────────────────────────────── */
$eventChartLabels = [];
$eventChartData   = [];

$ecRows = $pdo->query("
    SELECT
        es.agenda,
        COUNT(er.id) AS total
    FROM event_settings es
    LEFT JOIN event_registrations er ON TRIM(er.agenda) = TRIM(es.agenda)
    GROUP BY es.id, es.agenda
    ORDER BY es.start_date DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($ecRows as $row) {
    $eventChartLabels[] = mb_strimwidth($row['agenda'], 0, 22, '…');
    $eventChartData[]   = (int)$row['total'];
}

/* ─────────────────────────────────────────────
   DAILY REGISTRATIONS — last 7 days
───────────────────────────────────────────── */
$dailyLabels = [];
$dailyData   = [];
for ($i = 6; $i >= 0; $i--) {
    $date          = date('Y-m-d', strtotime("-{$i} days"));
    $dailyLabels[] = date('M d', strtotime($date));
    $ds = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE DATE(created_at) = ?");
    $ds->execute([$date]);
    $dailyData[] = (int)$ds->fetchColumn();
}

/* ─────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────── */
$today     = date('l, F j, Y');
$firstName = explode(' ', $userName)[0];

function getInitials(string $name): string {
    $words = explode(' ', trim($name));
    return strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', array_slice($words, 0, 2))));
}
function avatarColor(string $name): string {
    $colors = ['#4f6ef7','#34d399','#f5a623','#e05c6a','#a78bfa','#38bdf8','#fb7185','#4ade80'];
    $h = 0;
    foreach (str_split($name ?: 'U') as $c) $h = ($h * 31 + ord($c)) % count($colors);
    return $colors[$h];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= nexusHead() ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .row      { display: grid; gap: 16px; margin-bottom: 16px; }
        .row-8-4  { grid-template-columns: 1fr 340px; }
        .row-4-8  { grid-template-columns: 320px 1fr; }
        .row-2col { grid-template-columns: 1fr 1fr; }

        .chart-wrap    { position: relative; height: 260px; }
        .chart-wrap-sm { position: relative; height: 160px; }

        .stat-delta    { font-family: var(--mono); font-size: 11px; margin-top: 6px; }
        .delta-up      { color: var(--success); }
        .delta-neutral { color: var(--text-dim); }

        .metric-big   { font-size: 32px; font-weight: 800; letter-spacing: -.04em; color: var(--text-pri); margin-bottom: 4px; }
        .metric-label { font-family: var(--mono); font-size: 11px; color: var(--text-dim); margin-bottom: 16px; }

        .dash-table          { width: 100%; border-collapse: collapse; }
        .dash-table thead th { font-family: var(--mono); font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .1em; padding: 10px 16px; background: var(--bg); border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; }
        .dash-table thead th.text-end { text-align: right; }
        .dash-table tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
        .dash-table tbody tr:last-child { border-bottom: none; }
        .dash-table tbody tr:hover { background: rgba(79,110,247,.04); }
        .dash-table tbody td { padding: 12px 16px; font-size: 13px; color: var(--text-sec); vertical-align: middle; }
        .dash-table tbody td.td-main { color: var(--text-pri); font-weight: 600; }
        .dash-table tbody td.mono    { font-family: var(--mono); font-size: 12px; }
        .dash-table tbody td.text-end { text-align: right; }

        .avatar-cell { display: flex; align-items: center; gap: 10px; }
        .row-avatar  { width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 12px; font-weight: 700; color: #fff; flex-shrink: 0; }

        .quick-links { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 4px; }
        .quick-link  { display: flex; align-items: center; gap: 10px; padding: 12px 14px; background: var(--bg); border: 1px solid var(--border-hi); border-radius: 8px; text-decoration: none; color: var(--text-sec); font-size: 13px; font-weight: 600; transition: all .15s; }
        .quick-link i { width: 16px; text-align: center; font-size: 13px; color: var(--text-dim); }
        .quick-link:hover { color: var(--accent-hi); border-color: var(--accent); background: rgba(79,110,247,.04); }
        .quick-link:hover i { color: var(--accent-hi); }

        .timeline { padding: 4px 0; }
        .tl-item  { display: flex; gap: 14px; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .tl-item:last-child { border-bottom: none; }
        .tl-dot   { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; }
        .tl-dot.upcoming { background: #a78bfa; box-shadow: 0 0 6px rgba(167,139,250,.5); }
        .tl-dot.ongoing  { background: var(--warning); box-shadow: 0 0 6px rgba(245,166,35,.5); }
        .tl-dot.past     { background: var(--border-hi); }
        .tl-body  { flex: 1; min-width: 0; }
        .tl-title { font-size: 13px; font-weight: 700; color: var(--text-pri); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tl-meta  { font-family: var(--mono); font-size: 11px; color: var(--text-dim); margin-top: 2px; }

        .empty-state   { padding: 48px 0; text-align: center; }
        .empty-state i { font-size: 40px; color: var(--text-dim); margin-bottom: 12px; display: block; }
        .empty-state p { font-family: var(--mono); font-size: 12px; color: var(--text-dim); }

        @media (max-width: 960px) {
            .row-8-4,
            .row-4-8,
            .row-2col { grid-template-columns: 1fr; }
        }

        @media (max-width: 640px) {
            .row { gap: 12px; margin-bottom: 12px; }
            .quick-links { grid-template-columns: 1fr; }
            .quick-link { padding: 11px 12px; }
            .chart-wrap { height: 220px; }
            .chart-wrap-sm { height: 140px; }
            .metric-big { font-size: 28px; }
            .dash-table thead th,
            .dash-table tbody td { padding-left: 12px; padding-right: 12px; }
        }
    </style>
</head>
<body>

<?= nexusSidebar() ?>

<div class="main">
    <?= nexusTopbar() ?>

    <div class="content">
        <?php if (!empty($_GET['denied'])): ?>
        <div style="margin-bottom:16px;padding:12px 16px;border-radius:8px;background:rgba(245,166,35,.12);border:1px solid rgba(245,166,35,.35);font-size:13px;color:var(--text-pri);">
            You do not have access to that page.
        </div>
        <script>history.replaceState(null,'','index.php');</script>
        <?php endif; ?>

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>Admin Dashboard</h1>
                <p><?= $today ?> &nbsp;·&nbsp; Welcome back, <strong style="color:var(--text-pri)"><?= htmlspecialchars($firstName) ?></strong></p>
            </div>
            <div class="header-actions">
                <?php if ($nexusRole !== 'viewer'): ?>
                <a href="manage_event.php" class="btn btn-ghost"><i class="fas fa-calendar-plus"></i> Add Event</a>
                <?php endif; ?>
                <?php if ($nexusRole === 'admin'): ?>
                <a href="manage_users.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add User</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="stats-grid">
            <div class="stat-card c1">
                <div class="stat-icon"><i class="fas fa-calendar-days"></i></div>
                <div class="stat-label">Total Events</div>
                <div class="stat-value"><?= $totalEvents ?></div>
                <div class="stat-delta delta-up"><?= $activeEvents ?> active &nbsp;·&nbsp; <?= $upcomingEvts ?> upcoming</div>
            </div>
            <div class="stat-card c2">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-label">Registrations</div>
                <div class="stat-value"><?= number_format($totalRegistrations) ?></div>
                <div class="stat-delta delta-up">Across <?= $totalEvents ?> event<?= $totalEvents != 1 ? 's' : '' ?></div>
            </div>
            <div class="stat-card c3">
                <div class="stat-icon"><i class="fas fa-user-shield"></i></div>
                <div class="stat-label">Admin Users</div>
                <div class="stat-value"><?= $totalUsers ?></div>
                <div class="stat-delta delta-up"><?= $activeUsers ?> active account<?= $activeUsers != 1 ? 's' : '' ?></div>
            </div>
            <div class="stat-card c4">
                <div class="stat-icon"><i class="fas fa-circle-play"></i></div>
                <div class="stat-label">Ongoing Events</div>
                <div class="stat-value"><?= $ongoingEvts ?></div>
                <div class="stat-delta delta-neutral"><?= $pastEvts ?> past &nbsp;·&nbsp; <?= $upcomingEvts ?> upcoming</div>
            </div>
        </div>

        <!-- Row 1: Bar chart + sparkline + quick links -->
        <div class="row row-8-4">

            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Registrations per Event</div>
                        <div class="card-subtitle">Total attendees by event (latest <?= count($eventChartLabels) ?>)</div>
                    </div>
                    <div class="card-tools">
                        <a href="audience.php" class="tool-btn"><i class="fas fa-arrow-up-right-from-square"></i> Full List</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($eventChartLabels)): ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-bar"></i>
                        <p>No registration data yet.</p>
                    </div>
                    <?php else: ?>
                    <div class="chart-wrap"><canvas id="eventsChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:16px">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Daily Registrations</div>
                            <div class="card-subtitle">Last 7 days</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="metric-big"><?= number_format(array_sum($dailyData)) ?></div>
                        <div class="metric-label">Total this week</div>
                        <div class="chart-wrap-sm"><canvas id="dailySparkline"></canvas></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><div class="card-title">Quick Access</div></div>
                    <div class="card-body">
                        <div class="quick-links">
                            <a href="manage_event.php"    class="quick-link"><i class="fa-solid fa-file-lines"></i> Events</a>
                            <a href="audience.php"        class="quick-link"><i class="fas fa-users"></i> Audience</a>
                            <a href="manage_users.php"    class="quick-link"><i class="fas fa-user-plus"></i> Users</a>
                            <a href="reports.php"         class="quick-link"><i class="fas fa-chart-bar"></i> Reports</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 2: Event timeline + Recent registrations -->
        <div class="row row-4-8">

            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Event Timeline</div>
                        <div class="card-subtitle"><?= $totalEvents ?> total events</div>
                    </div>
                    <a href="manage_event.php" class="tool-btn"><i class="fas fa-arrow-up-right-from-square"></i></a>
                </div>
                <div class="card-body">
                    <?php if (empty($events)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-xmark"></i>
                        <p>No events found.</p>
                    </div>
                    <?php else: ?>
                    <div class="timeline">
                        <?php foreach (array_slice($events, 0, 6) as $ev):
                            $eStart = strtotime($ev['start_date']);
                            $eEnd   = strtotime($ev['end_date']);
                            if ($eStart > $now)    { $tl = 'upcoming'; $tlLabel = 'Upcoming'; }
                            elseif ($eEnd >= $now) { $tl = 'ongoing';  $tlLabel = 'Ongoing'; }
                            else                   { $tl = 'past';     $tlLabel = 'Past'; }
                        ?>
                        <div class="tl-item">
                            <div class="tl-dot <?= $tl ?>"></div>
                            <div class="tl-body">
                                <div class="tl-title"><?= htmlspecialchars($ev['agenda']) ?></div>
                                <div class="tl-meta">
                                    <?= date('M d', $eStart) ?> – <?= date('M d, Y', $eEnd) ?>
                                    &nbsp;·&nbsp;<span class="badge badge-<?= $tl ?>"><?= $tlLabel ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Recent Registrations</div>
                        <div class="card-subtitle">Latest attendees across all events</div>
                    </div>
                    <a href="audience.php" class="tool-btn"><i class="fas fa-arrow-up-right-from-square"></i> View All</a>
                </div>
                <div class="table-wrap">
                    <?php if (empty($recentRegistrations)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users-slash"></i>
                        <p>No registration records found yet.</p>
                    </div>
                    <?php else: ?>
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>Name / Unit</th>
                                <th>Event</th>
                                <th>Designation</th>
                                <th class="text-end">Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRegistrations as $reg):
                                $initials = getInitials(trim($reg['fullname'] ?? '?'));
                                $color    = avatarColor(trim($reg['fullname'] ?? ''));
                            ?>
                            <tr>
                                <td class="td-main">
                                    <div class="avatar-cell">
                                        <div class="row-avatar" style="background:<?= $color ?>"><?= $initials ?></div>
                                        <div>
                                            <div><?= htmlspecialchars(trim($reg['fullname'] ?? '—')) ?></div>
                                            <div style="font-family:var(--mono);font-size:11px;color:var(--text-dim);margin-top:1px">
                                                <?= htmlspecialchars($reg['unit_office'] ?? '—') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                    <?= htmlspecialchars($reg['agenda'] ?? '—') ?>
                                </td>
                                <td><?= htmlspecialchars($reg['designation'] ?? '—') ?></td>
                                <td class="mono text-end">
                                    <?= $reg['created_at'] ? date('M d, Y', strtotime($reg['created_at'])) : '—' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Row 3: Users + Event status donut -->
        <div class="row row-2col">

            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Admin Users</div>
                        <div class="card-subtitle"><?= $totalUsers ?> registered account<?= $totalUsers != 1 ? 's' : '' ?></div>
                    </div>
                    <a href="manage_users.php" class="tool-btn"><i class="fas fa-arrow-up-right-from-square"></i> Manage</a>
                </div>
                <div class="card-body">
                    <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-slash"></i>
                        <p>No users found.</p>
                    </div>
                    <?php else:
                        foreach (array_slice($users, 0, 5) as $u):
                            $displayName = $u['name'] ?? $u['username'];
                            $role        = $u['role']   ?? 'admin';
                            $uColor      = avatarColor($displayName);
                            $uInitials   = getInitials($displayName);
                    ?>
                    <div class="avatar-cell" style="padding:10px 0;border-bottom:1px solid var(--border)">
                        <div class="row-avatar" style="background:<?= $uColor ?>"><?= $uInitials ?></div>
                        <div style="flex:1;min-width:0">
                            <div style="font-size:13px;font-weight:700;color:var(--text-pri);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                <?= htmlspecialchars($displayName) ?>
                            </div>
                            <div style="font-family:var(--mono);font-size:11px;color:var(--text-dim)">
                                @<?= htmlspecialchars($u['username']) ?>
                            </div>
                        </div>
                        <?php if ($hasMigrated): ?>
                        <span class="badge <?= $role === 'admin' ? 'badge-accent' : ($role === 'staff' ? 'badge-info' : 'badge-secondary') ?>">
                            <?= htmlspecialchars($role) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; endif; ?>
                    <?php if ($totalUsers > 5): ?>
                    <div style="padding-top:12px;text-align:center">
                        <a href="manage_users.php" style="font-family:var(--mono);font-size:11px;color:var(--accent-hi);text-decoration:none">
                            View all <?= $totalUsers ?> users →
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Event Status Breakdown</div>
                        <div class="card-subtitle">Distribution by timeline</div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($totalEvents === 0): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-xmark"></i>
                        <p>No events to display.</p>
                    </div>
                    <?php else: ?>
                    <div class="chart-wrap"><canvas id="statusDonut"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /content -->
    <?= nexusFooter() ?>
</div><!-- /main -->

<?= nexusToast() ?>
<?= nexusJS() ?>

<script>
Chart.defaults.color       = '#8890aa';
Chart.defaults.font.family = "'DM Mono', monospace";
Chart.defaults.font.size   = 11;
const GRID_COLOR = 'rgba(46,52,80,0.6)';

/* ── Registrations per Event (bar) ── */
<?php if (!empty($eventChartLabels)): ?>
new Chart(document.getElementById('eventsChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($eventChartLabels) ?>,
        datasets: [{
            label: 'Registrations',
            data: <?= json_encode($eventChartData) ?>,
            backgroundColor: 'rgba(79,110,247,0.5)',
            hoverBackgroundColor: '#4f6ef7',
            borderRadius: 5,
            borderSkipped: false
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false } },
            y: { grid: { color: GRID_COLOR }, border: { dash: [4,4] }, beginAtZero: true, ticks: { precision: 0 } }
        }
    }
});
<?php endif; ?>

/* ── Daily Sparkline (line) ── */
new Chart(document.getElementById('dailySparkline'), {
    type: 'line',
    data: {
        labels: <?= json_encode($dailyLabels) ?>,
        datasets: [{
            data: <?= json_encode($dailyData) ?>,
            borderColor: '#34d399',
            backgroundColor: 'rgba(52,211,153,0.1)',
            fill: true, tension: .4, borderWidth: 2,
            pointRadius: 3, pointBackgroundColor: '#34d399', pointHoverRadius: 5
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: { grid: { color: GRID_COLOR }, border: { dash: [4,4] }, beginAtZero: true, ticks: { precision: 0 } }
        }
    }
});

/* ── Event Status Donut ── */
<?php if ($totalEvents > 0): ?>
new Chart(document.getElementById('statusDonut'), {
    type: 'doughnut',
    data: {
        labels: ['Upcoming', 'Ongoing', 'Past'],
        datasets: [{
            data: [<?= $upcomingEvts ?>, <?= $ongoingEvts ?>, <?= $pastEvts ?>],
            backgroundColor: ['#a78bfa', '#f5a623', '#454d66'],
            hoverOffset: 6, borderWidth: 0
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, pointStyleWidth: 8 } }
        }
    }
});
<?php endif; ?>
</script>
</body>
</html>
