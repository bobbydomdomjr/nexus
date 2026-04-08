<?php
/* ── Resolve error message from query string ── */
$errorCode    = $_GET['error']        ?? '';
$attemptsLeft = (int)($_GET['attempts_left'] ?? 5);
$lockMins     = (int)($_GET['mins']   ?? 15);

$errorMsg = '';
if ($errorCode === '1') {
    $errorMsg = $attemptsLeft < 5
        ? "Invalid credentials &mdash; {$attemptsLeft} attempt" . ($attemptsLeft === 1 ? '' : 's') . " remaining."
        : "Invalid username or password.";
} elseif ($errorCode === 'locked') {
    $errorMsg = "Too many failed attempts. Account locked for {$lockMins} minute" . ($lockMins === 1 ? '' : 's') . ".";
} elseif ($errorCode === 'inactive') {
    $errorMsg = "This account has been deactivated. Contact your administrator.";
}

/* ── AJAX: return live panel data as JSON ── */
if (isset($_GET['live'])) {
    require '../config/db.php';
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    // Total registrations
    $totalReg = (int)$pdo->query("SELECT COUNT(*) FROM event_registrations")->fetchColumn();

    // Registrations today
    $todayReg = (int)$pdo->query("
        SELECT COUNT(*) FROM event_registrations WHERE DATE(created_at) = CURDATE()
    ")->fetchColumn();

    // Registrations yesterday
    $yestReg = (int)$pdo->query("
        SELECT COUNT(*) FROM event_registrations WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY
    ")->fetchColumn();

    // Today delta vs yesterday
    $regDelta = $yestReg > 0 ? round((($todayReg - $yestReg) / $yestReg) * 100, 1) : ($todayReg > 0 ? 100 : 0);

    // Active / upcoming events
    $activeEvents = (int)$pdo->query("
        SELECT COUNT(*) FROM event_settings WHERE start_date <= CURDATE() AND end_date >= CURDATE()
    ")->fetchColumn();
    $upcomingEvents = (int)$pdo->query("
        SELECT COUNT(*) FROM event_settings WHERE start_date > CURDATE()
    ")->fetchColumn();

    // Report queue counts
    $reportCounts = ['total'=>0,'ready'=>0,'processing'=>0,'queued'=>0,'failed'=>0];
    try {
        $rows = $pdo->query("SELECT status, COUNT(*) AS c FROM report_queue GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $k = strtolower($r['status']);
            $reportCounts['total'] += (int)$r['c'];
            if (isset($reportCounts[$k])) $reportCounts[$k] = (int)$r['c'];
        }
    } catch (Exception $e) {}

    // Top unit this month
    $topUnit = $pdo->query("
        SELECT unit_office, COUNT(*) AS cnt
        FROM event_registrations
        WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())
          AND unit_office IS NOT NULL AND TRIM(unit_office)!=''
        GROUP BY unit_office ORDER BY cnt DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    // Registrations this month
    $thisMonthReg = (int)$pdo->query("
        SELECT COUNT(*) FROM event_registrations
        WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())
    ")->fetchColumn();

    // Last month
    $lastMonthReg = (int)$pdo->query("
        SELECT COUNT(*) FROM event_registrations
        WHERE MONTH(created_at)=MONTH(CURDATE()-INTERVAL 1 MONTH)
          AND YEAR(created_at)=YEAR(CURDATE()-INTERVAL 1 MONTH)
    ")->fetchColumn();
    $monthDelta = $lastMonthReg > 0
        ? round((($thisMonthReg - $lastMonthReg) / $lastMonthReg) * 100, 1)
        : ($thisMonthReg > 0 ? 100 : 0);

    // Recent registrations (activity feed)
    $recentRegs = $pdo->query("
        SELECT
            CONCAT(COALESCE(TRIM(first_name),''),' ',COALESCE(TRIM(last_name),'')) AS name,
            unit_office,
            agenda,
            created_at
        FROM event_registrations
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Pending report queue items
    $pendingReports = [];
    try {
        $pendingReports = $pdo->query("
            SELECT report_name, status, created_at
            FROM report_queue
            ORDER BY created_at DESC LIMIT 4
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    echo json_encode([
        'ts'            => date('H:i:s'),
        'total_reg'     => $totalReg,
        'today_reg'     => $todayReg,
        'reg_delta'     => $regDelta,
        'active_events' => $activeEvents,
        'upcoming'      => $upcomingEvents,
        'this_month'    => $thisMonthReg,
        'month_delta'   => $monthDelta,
        'top_unit'      => $topUnit ? $topUnit['unit_office'] : '—',
        'top_unit_cnt'  => $topUnit ? (int)$topUnit['cnt'] : 0,
        'reports'       => $reportCounts,
        'recent_regs'   => $recentRegs,
        'pending_rpts'  => $pendingReports,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Portal — Login</title>
    <script>(function(){try{var d=localStorage.getItem('nx_dark');if(d==='false')document.documentElement.classList.add('light');}catch(e){}})();</script>
    <link rel="stylesheet" href="../css/public-theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:          #0a0c10;
            --panel:       #0f1117;
            --panel-hi:    #141720;
            --border:      #1e2230;
            --border-hi:   #2e3450;
            --accent:      #4f6ef7;
            --accent-hi:   #6e87ff;
            --accent-glow: rgba(79,110,247,0.25);
            --text-pri:    #eef0f8;
            --text-sec:    #8890aa;
            --text-dim:    #454d66;
            --danger:      #e05c6a;
            --danger-bg:   rgba(224,92,106,0.08);
            --success:     #34d399;
            --warn:        #f5a623;
            --mono:        'DM Mono', monospace;
            --sans:        'Syne', sans-serif;
        }
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }

        body {
            background: var(--bg);
            font-family: var(--sans);
            color: var(--text-pri);
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1fr 480px 1fr;
            grid-template-rows: 1fr auto 1fr;
            align-items: center;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: fixed; inset: 0;
            background-image:
                linear-gradient(var(--border) 1px, transparent 1px),
                linear-gradient(90deg, var(--border) 1px, transparent 1px);
            background-size: 48px 48px;
            opacity: 0.35;
            pointer-events: none;
        }
        body::after {
            content: '';
            position: fixed;
            top: -20%; left: 50%;
            transform: translateX(-50%);
            width: 800px; height: 600px;
            background: radial-gradient(ellipse at center, rgba(79,110,247,0.12) 0%, transparent 70%);
            pointer-events: none;
        }

        /* ── SIDE PANELS ── */
        .side-left, .side-right {
            grid-row: 1 / 4;
            height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 48px 28px;
            gap: 14px;
            opacity: 0;
            animation: fadeIn 0.8s ease 0.6s forwards;
            overflow: hidden;
        }
        .side-left { align-items:flex-end; grid-column:1; }
        .side-right { align-items:flex-start; grid-column:3; }

        /* ── PANEL CARDS ── */
        .p-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 16px;
            min-width: 172px;
            max-width: 200px;
            transition: border-color .3s;
        }
        .p-card.pulse-update { border-color: var(--accent) !important; }

        .p-label {
            font-family: var(--mono);
            font-size: 10px;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.12em;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .p-label-live {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--success);
            animation: pulse 2s ease infinite;
            flex-shrink: 0;
        }

        .p-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-pri);
            letter-spacing: -0.03em;
            transition: color .25s;
        }
        .p-value.flash { color: var(--accent-hi); }

        .p-delta {
            font-family: var(--mono);
            font-size: 11px;
            margin-top: 3px;
            transition: color .25s;
        }
        .p-delta.pos { color: var(--success); }
        .p-delta.neg { color: var(--danger); }
        .p-delta.neu { color: var(--text-dim); }

        /* ── ACTIVITY FEED ── */
        .p-feed {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            min-width: 172px;
            max-width: 200px;
            flex: 1;
            overflow: hidden;
        }
        .p-feed-title {
            font-family: var(--mono);
            font-size: 10px;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .feed-rows { display:flex; flex-direction:column; gap:0; }
        .feed-row {
            display: flex;
            align-items: flex-start;
            gap: 7px;
            padding: 5px 0;
            border-bottom: 1px solid var(--border);
            font-family: var(--mono);
            font-size: 10px;
            color: var(--text-sec);
            line-height: 1.35;
            transition: background .2s;
            overflow: hidden;
        }
        .feed-row:last-child { border-bottom:none; }
        .feed-row.new-item {
            animation: feedSlide .35s ease;
        }
        @keyframes feedSlide {
            from { opacity:0; transform:translateY(-6px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .feed-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 3px;
        }
        .fd-acc  { background: var(--accent); }
        .fd-ok   { background: var(--success); }
        .fd-warn { background: var(--warn); }
        .fd-dim  { background: var(--text-dim); }
        .fd-danger { background: var(--danger); }

        .feed-main { flex:1; overflow:hidden; }
        .feed-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text-pri);
            font-size: 10px;
        }
        .feed-sub {
            color: var(--text-dim);
            font-size: 9px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ── TIMESTAMP TICKER ── */
        .p-ts {
            font-family: var(--mono);
            font-size: 10px;
            color: var(--text-dim);
            text-align: center;
            letter-spacing: 0.08em;
            padding: 4px 0 0;
        }

        /* ── REPORT STATUS MINI ── */
        .rpt-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin-top: 4px;
        }
        .rpt-cell {
            background: rgba(255,255,255,.03);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 5px 8px;
            text-align: center;
        }
        .rpt-cell-val {
            font-family: var(--mono);
            font-size: 15px;
            font-weight: 600;
            transition: color .3s;
        }
        .rpt-cell-lbl {
            font-family: var(--mono);
            font-size: 9px;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: .06em;
        }
        .rpt-ready      { color: var(--success); }
        .rpt-processing { color: var(--warn); }
        .rpt-queued     { color: var(--text-sec); }
        .rpt-failed     { color: var(--danger); }

        /* ══════════════
           LOGIN FORM (unchanged)
        ══════════════ */
        .login-wrap {
            grid-column:2; grid-row:2;
            position:relative; z-index:10;
            opacity:0; transform:translateY(20px);
            animation:slideUp 0.7s cubic-bezier(0.22,1,0.36,1) 0.2s forwards;
        }
        .top-bar { display:flex; align-items:center; justify-content:space-between; margin-bottom:2px; }
        .org-logo { display:flex; align-items:center; gap:10px; }
        .logo-mark { width:32px; height:32px; background:var(--accent); border-radius:6px; display:grid; place-items:center; }
        .logo-mark svg { width:18px; height:18px; }
        .org-name { font-size:13px; font-weight:700; letter-spacing:.04em; color:var(--text-pri); text-transform:uppercase; }
        .env-badge { font-family:var(--mono); font-size:10px; color:var(--accent-hi); background:rgba(79,110,247,0.12); border:1px solid rgba(79,110,247,0.25); border-radius:4px; padding:3px 8px; letter-spacing:.06em; }
        .card {
            background:var(--panel); border:1px solid var(--border-hi); border-radius:12px;
            padding:40px 40px 36px; margin-top:16px; position:relative;
            box-shadow: 0 0 0 1px rgba(255,255,255,.03) inset, 0 32px 64px rgba(0,0,0,.5), 0 0 80px var(--accent-glow);
        }
        .card::before { content:''; position:absolute; top:0; left:40px; right:40px; height:1px; background:linear-gradient(90deg,transparent,var(--accent),transparent); border-radius:1px; }
        .card-header { margin-bottom:32px; }
        .card-title { font-size:26px; font-weight:800; letter-spacing:-0.03em; color:var(--text-pri); line-height:1.1; }
        .card-sub { font-family:var(--mono); font-size:12px; color:var(--text-sec); margin-top:8px; letter-spacing:.02em; }
        .alert-error { display:flex; align-items:center; gap:10px; background:var(--danger-bg); border:1px solid rgba(224,92,106,.3); border-radius:8px; padding:12px 14px; margin-bottom:24px; font-family:var(--mono); font-size:12px; color:var(--danger); transition:opacity .5s; }
        .alert-error svg { flex-shrink:0; }
        .alert-error.fade-out { opacity:0; }
        .field { margin-bottom:18px; }
        .field label { display:block; font-family:var(--mono); font-size:11px; color:var(--text-dim); text-transform:uppercase; letter-spacing:.1em; margin-bottom:8px; }
        .field-wrap { position:relative; }
        .field-icon { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--text-dim); pointer-events:none; }
        .field input { width:100%; background:var(--bg); border:1px solid var(--border-hi); border-radius:8px; padding:13px 14px 13px 42px; font-family:var(--mono); font-size:13px; color:var(--text-pri); outline:none; transition:border-color .2s,box-shadow .2s; letter-spacing:.02em; }
        .field input::placeholder { color:var(--text-dim); }
        .field input:focus { border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-glow); }
        .toggle-pw { position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-dim); cursor:pointer; padding:4px; display:grid; place-items:center; }
        .toggle-pw:hover { color:var(--text-sec); }
        .options-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:28px; margin-top:-4px; }
        .checkbox-label { display:flex; align-items:center; gap:8px; font-family:var(--mono); font-size:12px; color:var(--text-sec); cursor:pointer; user-select:none; }
        .checkbox-label input[type="checkbox"] { appearance:none; width:16px; height:16px; background:var(--bg); border:1px solid var(--border-hi); border-radius:4px; cursor:pointer; position:relative; transition:background .15s,border-color .15s; flex-shrink:0; }
        .checkbox-label input[type="checkbox"]:checked { background:var(--accent); border-color:var(--accent); }
        .checkbox-label input[type="checkbox"]:checked::after { content:''; position:absolute; left:4px; top:1px; width:5px; height:9px; border:2px solid #fff; border-top:none; border-left:none; transform:rotate(45deg); }
        .forgot-link { font-family:var(--mono); font-size:12px; color:var(--accent); text-decoration:none; }
        .forgot-link:hover { color:var(--accent-hi); }
        .btn-login { width:100%; background:var(--accent); border:none; border-radius:8px; padding:14px; font-family:var(--sans); font-size:14px; font-weight:700; color:#fff; letter-spacing:.04em; text-transform:uppercase; cursor:pointer; position:relative; overflow:hidden; transition:background .2s,box-shadow .2s; }
        .btn-login::after { content:''; position:absolute; inset:0; background:linear-gradient(180deg,rgba(255,255,255,.1) 0%,transparent 60%); }
        .btn-login:hover { background:var(--accent-hi); box-shadow:0 8px 24px rgba(79,110,247,.4); }
        .btn-login:active { transform:translateY(1px); }
        .card-footer-note { margin-top:24px; text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); display:flex; align-items:center; justify-content:center; gap:6px; }
        .bottom-bar { margin-top:20px; display:flex; align-items:center; justify-content:space-between; }
        .session-id { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:.06em; }
        .status-pill { display:flex; align-items:center; gap:6px; font-family:var(--mono); font-size:10px; color:var(--success); }
        .status-dot { width:6px; height:6px; background:var(--success); border-radius:50%; animation:pulse 2s ease infinite; }

        @keyframes slideUp { to { opacity:1; transform:translateY(0); } }
        @keyframes fadeIn  { to { opacity:1; } }
        @keyframes pulse   { 0%,100%{opacity:1} 50%{opacity:.4} }
        @keyframes numFlip { 0%{transform:translateY(-4px);opacity:0} 100%{transform:translateY(0);opacity:1} }

        .num-update { animation: numFlip .25s ease; }

        @media (max-width:1100px) {
            body { grid-template-columns:1fr; grid-template-rows:auto; justify-items:center; padding:40px 20px; overflow:auto; }
            .side-left, .side-right { display:none; }
            .login-wrap { grid-column:1; }
        }
    </style>
</head>
<body>

<div class="theme-toggle-wrap">
    <div class="theme-toggle-hints" aria-hidden="true">
        <span class="theme-hint-day">Day</span>
        <span class="theme-hint-night">Night</span>
    </div>
    <button type="button" class="theme-toggle" id="theme-toggle-btn" role="switch" onclick="nexusPublicThemeToggle()" aria-checked="true" aria-label="Theme">
        <span class="theme-toggle-track">
            <span class="theme-toggle-thumb"><i class="fas fa-moon fa-fw" data-theme-icon aria-hidden="true"></i></span>
        </span>
    </button>
</div>

<!-- ══════════════════════════
     LEFT PANEL — Registrations
══════════════════════════ -->
<aside class="side-left">

    <div class="p-card" id="lc-today">
        <div class="p-label">
            Registered Today
            <span class="p-label-live"></span>
        </div>
        <div class="p-value" id="lv-today">—</div>
        <div class="p-delta neu" id="ld-today">Loading…</div>
    </div>

    <div class="p-card" id="lc-month">
        <div class="p-label">
            This Month
            <span class="p-label-live"></span>
        </div>
        <div class="p-value" id="lv-month">—</div>
        <div class="p-delta neu" id="ld-month">vs last month</div>
    </div>

    <div class="p-card" id="lc-total">
        <div class="p-label">Total Registrations</div>
        <div class="p-value" id="lv-total">—</div>
        <div class="p-delta neu" id="ld-total">All time</div>
    </div>

    <div class="p-feed">
        <div class="p-feed-title">
            <span class="feed-dot fd-ok" style="animation:pulse 2s infinite"></span>
            Recent Registrations
        </div>
        <div class="feed-rows" id="left-feed"></div>
    </div>

    <div class="p-ts" id="left-ts">—</div>
</aside>

<!-- ══════════════════════
     LOGIN PANEL (unchanged)
══════════════════════ -->
<main class="login-wrap">
    <div class="top-bar">
        <div class="org-logo">
            <div class="logo-mark">
                <svg viewBox="0 0 18 18" fill="none">
                    <rect x="1" y="1" width="7" height="7" rx="1.5" fill="white"/>
                    <rect x="10" y="1" width="7" height="7" rx="1.5" fill="white" opacity="0.6"/>
                    <rect x="1" y="10" width="7" height="7" rx="1.5" fill="white" opacity="0.6"/>
                    <rect x="10" y="10" width="7" height="7" rx="1.5" fill="white" opacity="0.3"/>
                </svg>
            </div>
            <span class="org-name">Nexus Platform</span>
        </div>
        <span class="env-badge">PRODUCTION</span>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title">Administrator<br>Sign In</div>
            <div class="card-sub">Restricted access — authorised personnel only</div>
        </div>

        <?php if (!empty($_GET['error'])): ?>
        <div id="error-msg" class="alert-error">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
                <path d="M8 5v3.5M8 11h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <?= $errorMsg ?: 'Authentication failed.' ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="authenticate.php">
            <div class="field">
                <label for="username">Username</label>
                <div class="field-wrap">
                    <span class="field-icon">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="5.5" r="2.5" stroke="currentColor" stroke-width="1.4"/>
                            <path d="M2 13c0-3.314 2.686-5 6-5s6 1.686 6 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <input type="text" name="username" id="username" placeholder="admin" required autocomplete="username">
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="field-wrap">
                    <span class="field-icon">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <rect x="3" y="7" width="10" height="7" rx="1.5" stroke="currentColor" stroke-width="1.4"/>
                            <path d="M5.5 7V5a2.5 2.5 0 015 0v2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <input type="password" name="password" id="password" placeholder="••••••••••••" required autocomplete="current-password">
                    <button type="button" class="toggle-pw" onclick="togglePassword()" aria-label="Toggle password">
                        <svg id="eye-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M1 8s2.5-5 7-5 7 5 7 5-2.5 5-7 5-7-5-7-5z" stroke="currentColor" stroke-width="1.4"/>
                            <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.4"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="options-row">
                <label class="checkbox-label">
                    <input type="checkbox" name="remember">
                    Keep me signed in
                </label>
                <a href="forgot.php" class="forgot-link">Forgot password?</a>
            </div>

            <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div class="card-footer-note">
            <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
                <path d="M8 1.5L2 4v4c0 3.5 2.5 6 6 7 3.5-1 6-3.5 6-7V4L8 1.5z" stroke="currentColor" stroke-width="1.4"/>
                <path d="M5.5 8l1.5 1.5 3-3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            TLS 1.3 · 256-bit AES · MFA enforced
        </div>
    </div>

    <div class="bottom-bar">
        <span class="session-id">SID: <?= strtoupper(substr(md5(uniqid()), 0, 12)) ?></span>
        <span class="status-pill"><span class="status-dot"></span>Systems Operational</span>
    </div>
</main>

<!-- ══════════════════════════
     RIGHT PANEL — Events & Reports
══════════════════════════ -->
<aside class="side-right">

    <div class="p-card" id="rc-events">
        <div class="p-label">
            Active Events
            <span class="p-label-live"></span>
        </div>
        <div class="p-value" id="rv-active">—</div>
        <div class="p-delta neu" id="rd-upcoming">Loading…</div>
    </div>

    <div class="p-card" id="rc-topunit">
        <div class="p-label">Top Unit (Month)</div>
        <div style="font-family:var(--mono);font-size:12px;color:var(--text-pri);margin:4px 0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;transition:color .25s" id="rv-topunit">—</div>
        <div class="p-delta neu" id="rd-topunit">— registrations</div>
    </div>

    <div class="p-card" id="rc-reports">
        <div class="p-label">
            Report Queue
            <span class="p-label-live"></span>
        </div>
        <div class="rpt-grid">
            <div class="rpt-cell">
                <div class="rpt-cell-val rpt-ready"    id="rpt-ready">—</div>
                <div class="rpt-cell-lbl">Ready</div>
            </div>
            <div class="rpt-cell">
                <div class="rpt-cell-val rpt-processing" id="rpt-proc">—</div>
                <div class="rpt-cell-lbl">Running</div>
            </div>
            <div class="rpt-cell">
                <div class="rpt-cell-val rpt-queued" id="rpt-queue">—</div>
                <div class="rpt-cell-lbl">Queued</div>
            </div>
            <div class="rpt-cell">
                <div class="rpt-cell-val rpt-failed" id="rpt-fail">—</div>
                <div class="rpt-cell-lbl">Failed</div>
            </div>
        </div>
    </div>

    <div class="p-feed">
        <div class="p-feed-title">
            <span class="feed-dot fd-acc" style="animation:pulse 2s infinite"></span>
            Report Activity
        </div>
        <div class="feed-rows" id="right-feed"></div>
    </div>

    <div class="p-ts" id="right-ts">—</div>
</aside>

<script>
/* ═══════════════════════════════════════════
   LIVE DATA ENGINE
═══════════════════════════════════════════ */
let _prev = {};

function fmt(n) {
    if (n === undefined || n === null) return '—';
    return Number(n).toLocaleString();
}

function setVal(id, val, flashOnChange = true) {
    const el = document.getElementById(id);
    if (!el) return;
    const newStr = String(val);
    if (flashOnChange && el.textContent !== newStr && el.textContent !== '—') {
        el.classList.remove('num-update');
        void el.offsetWidth; // reflow
        el.classList.add('num-update');

        // Briefly tint parent card border
        const card = el.closest('.p-card');
        if (card) {
            card.classList.add('pulse-update');
            setTimeout(() => card.classList.remove('pulse-update'), 600);
        }
    }
    el.textContent = newStr;
}

function setDelta(id, val, suffix = '') {
    const el = document.getElementById(id);
    if (!el) return;
    if (val === null || val === undefined) { el.textContent = suffix; el.className = 'p-delta neu'; return; }
    const v = parseFloat(val);
    const sign = v > 0 ? '↑ +' : (v < 0 ? '↓ ' : '→ ');
    el.textContent = `${sign}${Math.abs(v)}%${suffix ? ' ' + suffix : ''}`;
    el.className = 'p-delta ' + (v > 0 ? 'pos' : v < 0 ? 'neg' : 'neu');
}

/* ── Feed renderers ── */
const STATUS_DOT = { Ready:'fd-ok', Processing:'fd-warn', Queued:'fd-dim', Failed:'fd-danger' };

function renderLeftFeed(regs) {
    const feed = document.getElementById('left-feed');
    if (!regs || !regs.length) {
        feed.innerHTML = '<div class="feed-row"><div class="feed-main"><div class="feed-name" style="color:var(--text-dim)">No registrations yet</div></div></div>';
        return;
    }
    const prevIds = Array.from(feed.querySelectorAll('.feed-row')).map(r => r.dataset.key);
    feed.innerHTML = '';
    regs.forEach((r, i) => {
        const name  = (r.name || '').trim() || 'Unknown';
        const unit  = r.unit_office || '—';
        const ago   = timeAgo(r.created_at);
        const isNew = !prevIds.includes(r.created_at);
        const row   = document.createElement('div');
        row.className = 'feed-row' + (isNew && prevIds.length ? ' new-item' : '');
        row.dataset.key = r.created_at;
        row.innerHTML = `
            <span class="feed-dot fd-ok"></span>
            <div class="feed-main">
                <div class="feed-name">${esc(name)}</div>
                <div class="feed-sub">${esc(unit)} · ${ago}</div>
            </div>
        `;
        feed.appendChild(row);
    });
}

function renderRightFeed(reports) {
    const feed = document.getElementById('right-feed');
    if (!reports || !reports.length) {
        feed.innerHTML = '<div class="feed-row"><div class="feed-main"><div class="feed-name" style="color:var(--text-dim)">No reports queued</div></div></div>';
        return;
    }
    const prevIds = Array.from(feed.querySelectorAll('.feed-row')).map(r => r.dataset.key);
    feed.innerHTML = '';
    reports.forEach(r => {
        const dot   = STATUS_DOT[r.status] || 'fd-dim';
        const ago   = timeAgo(r.created_at);
        const isNew = !prevIds.includes(r.created_at);
        const row   = document.createElement('div');
        row.className = 'feed-row' + (isNew && prevIds.length ? ' new-item' : '');
        row.dataset.key = r.created_at;
        row.innerHTML = `
            <span class="feed-dot ${dot}"></span>
            <div class="feed-main">
                <div class="feed-name">${esc(r.report_name)}</div>
                <div class="feed-sub">${esc(r.status)} · ${ago}</div>
            </div>
        `;
        feed.appendChild(row);
    });
}

/* ── Time ago helper ── */
function timeAgo(ts) {
    if (!ts) return '—';
    const diff = Math.floor((Date.now() - new Date(ts.replace(' ','T'))) / 1000);
    if (diff < 60)    return diff + 's ago';
    if (diff < 3600)  return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
}

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

/* ── Main fetch ── */
function fetchLiveData() {
    fetch('login.php?live=1')
        .then(r => r.json())
        .then(d => {
            const ts = 'Updated ' + d.ts;

            // LEFT PANEL
            setVal('lv-today', fmt(d.today_reg));
            setDelta('ld-today', d.reg_delta, 'vs yesterday');
            setVal('lv-month', fmt(d.this_month));
            setDelta('ld-month', d.month_delta, 'vs last month');
            setVal('lv-total', fmt(d.total_reg));
            renderLeftFeed(d.recent_regs);
            document.getElementById('left-ts').textContent = ts;

            // RIGHT PANEL
            setVal('rv-active', fmt(d.active_events));
            const upTxt = d.upcoming > 0 ? `${d.upcoming} upcoming` : 'No upcoming events';
            document.getElementById('rd-upcoming').textContent = upTxt;
            document.getElementById('rd-upcoming').className = 'p-delta ' + (d.active_events > 0 ? 'pos' : 'neu');

            const topUnitEl = document.getElementById('rv-topunit');
            const prevTop   = topUnitEl.textContent;
            if (prevTop !== d.top_unit && prevTop !== '—') topUnitEl.style.color = 'var(--accent-hi)';
            setTimeout(() => topUnitEl.style.color = '', 600);
            topUnitEl.textContent = d.top_unit || '—';
            document.getElementById('rd-topunit').textContent = d.top_unit_cnt ? `${d.top_unit_cnt} registrations` : '—';

            setVal('rpt-ready', d.reports.ready);
            setVal('rpt-proc',  d.reports.processing);
            setVal('rpt-queue', d.reports.queued);
            setVal('rpt-fail',  d.reports.failed);

            renderRightFeed(d.pending_rpts);
            document.getElementById('right-ts').textContent = ts;
        })
        .catch(() => {
            document.getElementById('left-ts').textContent  = 'Connection error…';
            document.getElementById('right-ts').textContent = 'Connection error…';
        });
}

// Initial load then refresh every 10 seconds
fetchLiveData();
setInterval(fetchLiveData, 10000);

// Refresh "X ago" labels every 30s without a server round-trip
setInterval(() => {
    document.querySelectorAll('.feed-row').forEach(row => {
        const sub = row.querySelector('.feed-sub');
        const key = row.dataset.key;
        if (sub && key) {
            const parts = sub.textContent.split(' · ');
            if (parts.length === 2) parts[1] = timeAgo(key);
            sub.textContent = parts.join(' · ');
        }
    });
}, 30000);

/* ── Error dismiss ── */
const errEl = document.getElementById('error-msg');
if (errEl) {
    setTimeout(() => {
        errEl.classList.add('fade-out');
        setTimeout(() => errEl.remove(), 500);
    }, 4000);
}

/* ── Password toggle ── */
function togglePassword() {
    const pw   = document.getElementById('password');
    const icon = document.getElementById('eye-icon');
    const isText = pw.type === 'text';
    pw.type = isText ? 'password' : 'text';
    icon.innerHTML = isText
        ? '<path d="M1 8s2.5-5 7-5 7 5 7 5-2.5 5-7 5-7-5-7-5z" stroke="currentColor" stroke-width="1.4"/><circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.4"/>'
        : '<path d="M2 2l12 12M6.5 6.6A2 2 0 0010.3 10M4.3 4.4C2.8 5.5 1 8 1 8s2.5 5 7 5c1.4 0 2.7-.4 3.8-1M7 3.1C7.3 3 7.7 3 8 3c4.5 0 7 5 7 5s-.7 1.4-2 2.7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>';
}
</script>
<script src="../js/theme-toggle-public.js"></script>
</body>
</html>