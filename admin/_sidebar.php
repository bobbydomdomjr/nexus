<?php
/**
 * ═══════════════════════════════════════════════════════════
 *  Nexus Platform — Reusable Sidebar + Topbar Component
 *  File: _sidebar.php
 * ═══════════════════════════════════════════════════════════
 */

if (!isset($_nexus_user_loaded)) {
    $_nexus_user_loaded = true;

    $__u = null;
    try {
        $__s = $pdo->prepare("SELECT * FROM admin_users WHERE id = ? LIMIT 1");
        $__s->execute([$_SESSION['admin_id']]);
        $__u = $__s->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    $NEXUS['userName']     = $__u['name']     ?? $_SESSION['admin_name']     ?? 'Administrator';
    $NEXUS['userUsername'] = $__u['username'] ?? $_SESSION['admin_username'] ?? 'admin';
    $NEXUS['userEmail']    = $__u['email']    ?? $_SESSION['admin_email']    ?? '';
    $NEXUS['userRole']     = $__u['role']     ?? $_SESSION['admin_role']     ?? 'admin';
    $NEXUS['userInitials'] = strtoupper(implode('', array_map(
        fn($w) => $w[0] ?? '',
        array_slice(explode(' ', trim($NEXUS['userName'])), 0, 2)
    )));

    $__colors = ['#4f6ef7','#34d399','#f5a623','#e05c6a','#a78bfa','#38bdf8'];
    $__h = 0;
    foreach (str_split($NEXUS['userName']) as $c) $__h = ($__h * 31 + ord($c)) % count($__colors);
    $NEXUS['avatarBg'] = $__colors[$__h];

    /* ─────────────────────────────────────────────
       NOTIFICATIONS
       Combines: new admin users, event updates,
       and new event_registrations (last 24 hrs).
       Read state stored in nexus_notif_read table.
    ───────────────────────────────────────────── */
    $NEXUS['notifications'] = [];
    $adminId = (int)($_SESSION['admin_id'] ?? 0);

    // Ensure the read-tracking table exists (auto-create, no migration needed)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS nexus_notif_read (
                admin_id   INT          NOT NULL,
                notif_key  VARCHAR(120) NOT NULL,
                read_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (admin_id, notif_key)
            )
        ");
    } catch (PDOException $e) {}

    // Fetch already-read keys for this admin
    $__readKeys = [];
    try {
        $__rs = $pdo->prepare("SELECT notif_key FROM nexus_notif_read WHERE admin_id = ?");
        $__rs->execute([$adminId]);
        $__readKeys = array_column($__rs->fetchAll(PDO::FETCH_ASSOC), 'notif_key');
    } catch (PDOException $e) {}

    // 1. New admin users (last 7 days)
    try {
        $__ns = $pdo->query("SELECT id, name, username, created_at FROM admin_users ORDER BY created_at DESC LIMIT 4");
        foreach ($__ns->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = 'user_' . $row['id'];
            $NEXUS['notifications'][] = [
                'key'    => $key,
                'icon'   => 'fa-user-plus',
                'color'  => 'accent',
                'text'   => 'New admin user <strong>' . htmlspecialchars($row['name'] ?: $row['username']) . '</strong> registered',
                'time'   => $row['created_at'],
                'unread' => !in_array($key, $__readKeys),
                'href'   => 'manage_users.php',
            ];
        }
    } catch (PDOException $e) {}

    // 2. Event updates (last 7 days)
    try {
        $__ns = $pdo->query("SELECT id, agenda, updated_at, start_date FROM event_settings ORDER BY updated_at DESC LIMIT 3");
        foreach ($__ns->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = 'event_' . $row['id'];
            $NEXUS['notifications'][] = [
                'key'    => $key,
                'icon'   => 'fa-calendar-plus',
                'color'  => 'success',
                'text'   => 'Event <strong>' . htmlspecialchars($row['agenda']) . '</strong> was updated',
                'time'   => $row['updated_at'] ?? $row['start_date'],
                'unread' => !in_array($key, $__readKeys),
                'href'   => 'manage_event.php',
            ];
        }
    } catch (PDOException $e) {}

    // 3. New individual registrations (last 48 hours) — one notification per person
    try {
        $__ns = $pdo->query("
            SELECT
                r.id,
                r.first_name,
                r.last_name,
                r.rank,
                r.major_service,
                r.agenda,
                r.created_at,
                es.id AS event_id
            FROM event_registrations r
            LEFT JOIN event_settings es ON es.agenda = r.agenda
            WHERE r.created_at >= NOW() - INTERVAL 48 HOUR
            ORDER BY r.created_at DESC
            LIMIT 10
        ");
        foreach ($__ns->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key      = 'reg_' . $row['id'];
            $fullname = trim(implode(' ', array_filter([
                $row['rank'],
                $row['first_name'],
                $row['last_name'],
            ])));
            $evParam  = $row['event_id'] ? '?event_id=' . (int)$row['event_id'] : '';
            $NEXUS['notifications'][] = [
                'key'    => $key,
                'icon'   => 'fa-user-check',
                'color'  => 'warning',
                'text'   => '<strong>' . htmlspecialchars($fullname) . '</strong> registered for <strong>' . htmlspecialchars($row['agenda']) . '</strong>',
                'time'   => $row['created_at'],
                'unread' => !in_array($key, $__readKeys),
                'href'   => 'manage_audience.php' . $evParam,
            ];
        }
    } catch (PDOException $e) {}

    // Sort newest first, cap at 15
    usort($NEXUS['notifications'], fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));
    $NEXUS['notifications'] = array_slice($NEXUS['notifications'], 0, 15);
    $NEXUS['notifCount']    = count(array_filter($NEXUS['notifications'], fn($n) => $n['unread']));
}

$activePage   = $activePage   ?? 'dashboard';
$pageTitle    = $pageTitle    ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? 'Overview';
$docTitle     = $docTitle     ?? 'Nexus Platform';

function nexus_time_ago(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return floor($d / 60) . ' min ago';
    if ($d < 86400)  return floor($d / 3600) . ' hr ago';
    if ($d < 604800) return floor($d / 86400) . 'd ago';
    return date('M d', strtotime($dt));
}

function _nx_nav_link(string $key, string $href, string $icon, string $label, string $activePage): string
{
    $isActive    = ($key === $activePage);
    $activeClass = $isActive ? ' active' : '';
    $indicator   = $isActive ? '<span class="nav-indicator"></span>' : '';
    return '<a href="' . $href . '" class="nav-item' . $activeClass . '">'
         .   '<span class="nav-icon"><i class="' . $icon . '"></i></span>'
         .   '<span class="nav-label">' . $label . '</span>'
         .   $indicator
         . '</a>' . "\n";
}

/* ════════════════════════════════════════════
   nexusHead()
════════════════════════════════════════════ */
function nexusHead(): string
{
    global $docTitle, $pageTitle;
    $title = htmlspecialchars($docTitle . ($pageTitle ? ' — ' . $pageTitle : ''));
    return '<title>' . $title . '</title>' . "\n"
         . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
         . '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
         . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
         . '<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">' . "\n"
         . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">' . "\n"
         . '<style>' . nexusCSS() . '</style>';
}

/* ════════════════════════════════════════════
   nexusCSS()
════════════════════════════════════════════ */
function nexusCSS(): string
{
    return <<<'CSS'

/* ═══════════════════════════════════════
   VARIABLES & RESET
═══════════════════════════════════════ */
:root {
    --bg:          #0a0c10;
    --panel:       #0f1117;
    --panel-hi:    #141720;
    --border:      #1e2230;
    --border-hi:   #2e3450;
    --accent:      #4f6ef7;
    --accent-hi:   #6e87ff;
    --accent-glow: rgba(79,110,247,0.2);
    --text-pri:    #eef0f8;
    --text-sec:    #8890aa;
    --text-dim:    #454d66;
    --success:     #34d399;
    --warning:     #f5a623;
    --danger:      #e05c6a;
    --info:        #38bdf8;
    --sidebar-w:   240px;
    --topbar-h:    60px;
    --mono:        'DM Mono', monospace;
    --sans:        'Syne', sans-serif;
    --radius:      10px;
}

/* ═══════════════════════════════════════
   LIGHT THEME
═══════════════════════════════════════ */
body.light {
    --bg:        #f0f2f8;
    --panel:     #ffffff;
    --panel-hi:  #f5f6fa;
    --border:    #dde1ef;
    --border-hi: #c8cde0;
    --text-pri:  #0f1117;
    --text-sec:  #4a5068;
    --text-dim:  #9399b0;
    --accent-glow: rgba(79,110,247,0.15);
}
body.light::before { opacity: .12; }
body.light .sidebar,
body.light .topbar,
body.light .footer { background: #ffffff; }
body.light .card,
body.light .stat-card { background: #ffffff; }
body.light .dp { background: #ffffff; box-shadow: 0 20px 50px rgba(0,0,0,.12); }
body.light .search-bar,
body.light .topbar-btn,
body.light .filter-select { background: #f5f6fa; }
body.light table.nx-table thead th,
body.light table.aud-table thead th { background: #f5f6fa; }
body.light .notif-row.unread { background: rgba(79,110,247,.05); }
body.light .brand-name { color: #0f1117; }
body.light .modal-box { background: #ffffff; }
body.light .field input,
body.light .field select,
body.light .field textarea { background: #f5f6fa; }
body.light .page-btn { background: #f5f6fa; }
body.light .export-menu { background: #ffffff; }
body.light .filter-card { background: #ffffff; }
body.light .filter-group select,
body.light .filter-group input[type="date"] { background: #f5f6fa; }

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; overflow: hidden; }
body { font-family: var(--sans); background: var(--bg); color: var(--text-pri); display: flex; }
body::before {
    content: ''; position: fixed; inset: 0;
    background-image: linear-gradient(var(--border) 1px, transparent 1px),
                      linear-gradient(90deg, var(--border) 1px, transparent 1px);
    background-size: 48px 48px; opacity: .3; pointer-events: none; z-index: 0;
}

/* ═══════════════════════════════════════
   SIDEBAR
═══════════════════════════════════════ */
.sidebar {
    width: var(--sidebar-w); height: 100vh;
    background: var(--panel); border-right: 1px solid var(--border-hi);
    display: flex; flex-direction: column; flex-shrink: 0;
    position: relative; z-index: 100;
}
.sidebar-brand {
    height: var(--topbar-h); display: flex; align-items: center; gap: 10px;
    padding: 0 18px; border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.brand-mark {
    width: 30px; height: 30px; background: var(--accent);
    border-radius: 6px; display: grid; place-items: center; flex-shrink: 0;
}
.brand-mark svg { width: 16px; height: 16px; }
.brand-name { font-size: 13px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; }
.brand-env {
    margin-left: auto; font-family: var(--mono); font-size: 9px;
    color: var(--accent-hi); background: rgba(79,110,247,.12);
    border: 1px solid rgba(79,110,247,.25); border-radius: 3px; padding: 2px 6px;
}
.sidebar-close,
.topbar-menu {
    display: none; width: 36px; height: 36px;
    border: 1px solid var(--border-hi); border-radius: 8px;
    background: var(--bg); color: var(--text-sec); cursor: pointer;
    align-items: center; justify-content: center; flex-shrink: 0;
    transition: all .15s;
}
.sidebar-close:hover,
.topbar-menu:hover,
.topbar-menu.active {
    color: var(--text-pri); border-color: var(--accent); background: rgba(79,110,247,.08);
}
.sidebar-scroll { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 10px 0 16px; }
.sidebar-scroll::-webkit-scrollbar { width: 4px; }
.sidebar-scroll::-webkit-scrollbar-thumb { background: var(--border-hi); border-radius: 2px; }
.nav-section-label {
    font-family: var(--mono); font-size: 10px; color: var(--text-dim);
    text-transform: uppercase; letter-spacing: .14em; padding: 14px 18px 6px;
}
.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 7px 10px; margin: 2px 8px;
    font-size: 13px; font-weight: 600; color: var(--text-sec);
    text-decoration: none; border-radius: 8px; border: 1px solid transparent;
    position: relative; transition: color .15s, background .15s, border-color .15s;
}
.nav-icon {
    width: 30px; height: 30px; border-radius: 7px;
    display: grid; place-items: center; font-size: 13px; flex-shrink: 0;
    transition: background .15s, color .15s, transform .15s;
}
.nav-label { flex: 1; white-space: nowrap; }
.nav-indicator {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--accent); box-shadow: 0 0 8px rgba(79,110,247,.6); flex-shrink: 0;
}
.nav-item:hover { color: var(--text-pri); background: rgba(255,255,255,.04); }
.nav-item:hover .nav-icon { background: rgba(79,110,247,.1); color: var(--accent-hi); transform: scale(1.05); }
.nav-item.active { color: var(--accent-hi); background: rgba(79,110,247,.1); border-color: rgba(79,110,247,.2); }
.nav-item.active .nav-icon { background: rgba(79,110,247,.18); color: var(--accent-hi); }
.sidebar-footer { padding: 14px 16px; border-top: 1px solid var(--border); flex-shrink: 0; }
.sidebar-user { display: flex; align-items: center; gap: 10px; }
.s-avatar {
    width: 32px; height: 32px; border-radius: 8px;
    display: grid; place-items: center; font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.s-info { flex: 1; min-width: 0; }
.s-name { font-size: 13px; font-weight: 700; color: var(--text-pri); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.s-role { font-family: var(--mono); font-size: 11px; color: var(--text-dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.logout-btn { background: none; border: none; color: var(--text-dim); cursor: pointer; padding: 4px; border-radius: 4px; transition: color .15s; }
.logout-btn:hover { color: var(--danger); }
.sidebar-overlay {
    position: fixed; inset: 0; background: rgba(10,12,16,.68);
    opacity: 0; visibility: hidden; pointer-events: none;
    transition: opacity .18s ease, visibility .18s ease;
    z-index: 90; backdrop-filter: blur(2px);
}

/* ═══════════════════════════════════════
   MAIN LAYOUT
═══════════════════════════════════════ */
.main { flex: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; position: relative; z-index: 1; }

/* ═══════════════════════════════════════
   TOPBAR
═══════════════════════════════════════ */
.topbar {
    height: var(--topbar-h); background: var(--panel);
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; padding: 0 28px; gap: 12px;
    flex-shrink: 0; position: relative;
}
.topbar-title { font-size: 15px; font-weight: 800; letter-spacing: -.01em; }
.bc-sep { color: var(--text-dim); font-size: 13px; }
.bc-cur { font-family: var(--mono); font-size: 12px; color: var(--text-sec); }
.topbar-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
.topbar-action { position: relative; display: inline-flex; }
.search-bar {
    display: flex; align-items: center; gap: 8px;
    background: var(--bg); border: 1px solid var(--border-hi); border-radius: 7px; padding: 6px 12px;
}
.search-bar input { background: none; border: none; outline: none; font-family: var(--mono); font-size: 12px; color: var(--text-pri); width: 160px; }
.search-bar input::placeholder { color: var(--text-dim); }
.search-bar i { color: var(--text-dim); font-size: 12px; }
.topbar-btn {
    position: relative; min-width: 36px; height: 36px; padding: 0 8px;
    background: var(--bg); border: 1px solid var(--border-hi); border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    color: var(--text-sec); cursor: pointer; font-size: 13px;
    transition: all .15s; font-family: var(--sans); font-weight: 600;
}
.topbar-btn:hover, .topbar-btn.active { color: var(--text-pri); border-color: var(--accent); background: rgba(79,110,247,.08); }
.notif-dot { position: absolute; top: 5px; right: 5px; width: 8px; height: 8px; background: var(--danger); border-radius: 50%; border: 2px solid var(--panel); }
.notif-dot.hidden { display: none; }
.topbar-status {
    display: flex; align-items: center; gap: 6px;
    font-family: var(--mono); font-size: 11px; color: var(--success);
    padding: 6px 12px; background: rgba(52,211,153,.06);
    border: 1px solid rgba(52,211,153,.2); border-radius: 20px;
}
.status-dot { width: 6px; height: 6px; background: var(--success); border-radius: 50%; animation: pulse 2s ease infinite; }

/* ═══════════════════════════════════════
   DROPDOWN PANELS
═══════════════════════════════════════ */
.dp {
    position: absolute; top: calc(100% + 8px);
    background: var(--panel); border: 1px solid var(--border-hi); border-radius: 12px;
    box-shadow: 0 20px 50px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.03) inset;
    z-index: 500; display: none; overflow: hidden; animation: dropIn .18s cubic-bezier(.22,1,.36,1);
}
.dp.open { display: block; }
.topbar-action .dp { right: 0; }
.dp-notif    { width: 360px; }
.dp-settings { width: 300px; }
.dp-user     { width: 260px; }
.dp-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--border); }
.dp-head-title { font-size: 13px; font-weight: 700; color: var(--text-pri); display: flex; align-items: center; gap: 8px; }
.dp-count { font-family: var(--mono); font-size: 10px; background: rgba(224,92,106,.1); color: var(--danger); border: 1px solid rgba(224,92,106,.2); padding: 1px 6px; border-radius: 4px; }
.dp-link { font-family: var(--mono); font-size: 11px; color: var(--accent-hi); background: none; border: none; cursor: pointer; transition: color .15s; }
.dp-link:hover { color: var(--text-pri); }
.notif-scroll { max-height: 340px; overflow-y: auto; }
.notif-scroll::-webkit-scrollbar { width: 4px; }
.notif-scroll::-webkit-scrollbar-thumb { background: var(--border-hi); border-radius: 2px; }
.notif-row {
    display: flex; align-items: flex-start; gap: 11px;
    padding: 13px 18px; border-bottom: 1px solid var(--border);
    transition: background .15s; cursor: pointer; text-decoration: none;
    position: relative;
}
.notif-row:last-child { border-bottom: none; }
.notif-row:hover { background: rgba(255,255,255,.03); }
.notif-row.unread { background: rgba(79,110,247,.04); }
.notif-row.unread::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0;
    width: 3px; background: var(--accent); border-radius: 0 2px 2px 0;
}
.notif-row-dismiss {
    margin-left: auto; padding: 2px 6px; border-radius: 4px; border: none;
    background: none; color: var(--text-dim); font-size: 11px; cursor: pointer;
    opacity: 0; transition: opacity .15s, color .15s, background .15s;
    flex-shrink: 0; align-self: center;
}
.notif-row:hover .notif-row-dismiss { opacity: 1; }
.notif-row-dismiss:hover { color: var(--text-pri); background: rgba(255,255,255,.06); }
.ni { width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; font-size: 13px; flex-shrink: 0; }
.ni.accent  { background: rgba(79,110,247,.12);  color: var(--accent-hi); }
.ni.success { background: rgba(52,211,153,.12);  color: var(--success); }
.ni.warning { background: rgba(245,166,35,.12);  color: var(--warning); }
.ni.danger  { background: rgba(224,92,106,.12);  color: var(--danger); }
.notif-body { flex: 1; font-size: 12px; color: var(--text-sec); line-height: 1.5; }
.notif-body strong { color: var(--text-pri); font-weight: 600; }
.notif-ts { font-family: var(--mono); font-size: 10px; color: var(--text-dim); margin-top: 2px; }
.notif-empty { padding: 36px 18px; text-align: center; }
.notif-empty i { font-size: 32px; color: var(--text-dim); display: block; margin-bottom: 10px; }
.notif-empty p { font-family: var(--mono); font-size: 12px; color: var(--text-dim); }
.dp-footer { padding: 10px 18px; border-top: 1px solid var(--border); text-align: center; }
.dp-footer a { font-family: var(--mono); font-size: 11px; color: var(--accent-hi); text-decoration: none; }
.s-section { padding: 14px 18px; border-bottom: 1px solid var(--border); }
.s-section:last-child { border-bottom: none; }
.s-section-title { font-family: var(--mono); font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .1em; margin-bottom: 10px; }
.s-row { display: flex; align-items: center; justify-content: space-between; padding: 7px 0; }
.s-label { font-size: 13px; color: var(--text-sec); display: flex; align-items: center; gap: 8px; }
.s-label i { width: 14px; color: var(--text-dim); font-size: 12px; }
.toggle { position: relative; width: 36px; height: 20px; flex-shrink: 0; }
.toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.toggle-track { position: absolute; inset: 0; background: var(--border-hi); border-radius: 20px; cursor: pointer; transition: background .2s; }
.toggle-track::after { content: ''; position: absolute; left: 3px; top: 3px; width: 14px; height: 14px; background: #fff; border-radius: 50%; transition: transform .2s; }
.toggle input:checked + .toggle-track { background: var(--accent); }
.toggle input:checked + .toggle-track::after { transform: translateX(16px); }
.session-info { font-family: var(--mono); font-size: 11px; color: var(--text-dim); line-height: 1.8; }
.dp-user-hero { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--border); }
.dp-user-av { width: 42px; height: 42px; border-radius: 10px; display: grid; place-items: center; font-size: 16px; font-weight: 700; color: #fff; flex-shrink: 0; }
.dp-user-name { font-size: 13px; font-weight: 700; color: var(--text-pri); }
.dp-user-sub { font-family: var(--mono); font-size: 11px; color: var(--text-dim); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px; }
.role-pill { display: inline-block; font-family: var(--mono); font-size: 9px; padding: 2px 7px; border-radius: 3px; text-transform: uppercase; letter-spacing: .06em; margin-top: 4px; }
.role-pill.admin  { background: rgba(79,110,247,.12);  color: var(--accent-hi); border: 1px solid rgba(79,110,247,.25); }
.role-pill.staff  { background: rgba(56,189,248,.1);   color: var(--info);      border: 1px solid rgba(56,189,248,.2); }
.role-pill.viewer { background: rgba(245,166,35,.1);   color: var(--warning);   border: 1px solid rgba(245,166,35,.2); }
.dp-divider { height: 1px; background: var(--border); margin: 4px 0; }
.dp-item { display: flex; align-items: center; gap: 10px; padding: 10px 18px; font-size: 13px; color: var(--text-sec); cursor: pointer; transition: all .15s; text-decoration: none; border: none; background: none; width: 100%; }
.dp-item i { width: 14px; text-align: center; font-size: 12px; color: var(--text-dim); }
.dp-item:hover { color: var(--text-pri); background: rgba(255,255,255,.02); }
.dp-item:hover i { color: var(--text-sec); }
.dp-item.danger { color: var(--danger); }
.dp-item.danger i { color: var(--danger); }
.dp-item.danger:hover { background: rgba(224,92,106,.06); }

/* ═══════════════════════════════════════
   CONTENT AREA
═══════════════════════════════════════ */
.content { flex: 1; overflow-y: auto; padding: 28px; }
.content::-webkit-scrollbar { width: 6px; }
.content::-webkit-scrollbar-thumb { background: var(--border-hi); border-radius: 3px; }

/* ═══════════════════════════════════════
   PAGE HEADER
═══════════════════════════════════════ */
.page-header { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 28px; }
.page-header h1 { font-size: 28px; font-weight: 800; letter-spacing: -.03em; line-height: 1; }
.page-header p { font-family: var(--mono); font-size: 12px; color: var(--text-sec); margin-top: 6px; }
.header-actions { display: flex; gap: 8px; }

/* ═══════════════════════════════════════
   BUTTONS
═══════════════════════════════════════ */
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 8px; font-family: var(--sans); font-size: 12px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; cursor: pointer; border: 1px solid transparent; transition: all .15s; text-decoration: none; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: var(--accent-hi); box-shadow: 0 6px 20px rgba(79,110,247,.35); }
.btn-ghost { background: transparent; color: var(--text-sec); border-color: var(--border-hi); }
.btn-ghost:hover { color: var(--text-pri); border-color: var(--accent); }
.btn-danger-ghost { background: transparent; color: var(--danger); border-color: rgba(224,92,106,.3); }
.btn-danger-ghost:hover { background: rgba(224,92,106,.08); border-color: var(--danger); }
.btn-warn { background: transparent; color: var(--warning); border-color: rgba(245,166,35,.3); }
.btn-warn:hover { background: rgba(245,166,35,.08); border-color: var(--warning); }

/* ═══════════════════════════════════════
   STAT CARDS
═══════════════════════════════════════ */
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.stat-card { background: var(--panel); border: 1px solid var(--border-hi); border-radius: var(--radius); padding: 20px 22px 16px; position: relative; overflow: hidden; transition: border-color .2s; }
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; }
.stat-card.c1::before { background: linear-gradient(90deg, var(--accent), var(--accent-hi)); }
.stat-card.c2::before { background: linear-gradient(90deg, #34d399, #6ee7b7); }
.stat-card.c3::before { background: linear-gradient(90deg, #f5a623, #fbbf24); }
.stat-card.c4::before { background: linear-gradient(90deg, #e05c6a, #fb7185); }
.stat-card.c5::before { background: linear-gradient(90deg, #a78bfa, #c4b5fd); }
.stat-card:hover { border-color: var(--accent); }
.stat-icon { width: 36px; height: 36px; border-radius: 8px; display: grid; place-items: center; font-size: 14px; margin-bottom: 14px; }
.c1 .stat-icon { background: rgba(79,110,247,.12);  color: var(--accent-hi); }
.c2 .stat-icon { background: rgba(52,211,153,.12);  color: #34d399; }
.c3 .stat-icon { background: rgba(245,166,35,.12);  color: #f5a623; }
.c4 .stat-icon { background: rgba(224,92,106,.12);  color: #e05c6a; }
.c5 .stat-icon { background: rgba(167,139,250,.12); color: #a78bfa; }
.stat-label { font-family: var(--mono); font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .12em; margin-bottom: 4px; }
.stat-value { font-size: 26px; font-weight: 800; letter-spacing: -.03em; color: var(--text-pri); line-height: 1; }
.stat-delta { font-family: var(--mono); font-size: 11px; margin-top: 6px; }
.delta-up   { color: var(--success); }
.delta-down { color: var(--danger); }

/* ═══════════════════════════════════════
   CARDS
═══════════════════════════════════════ */
.card { background: var(--panel); border: 1px solid var(--border-hi); border-radius: var(--radius); overflow: hidden; }
.card-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px; border-bottom: 1px solid var(--border); flex-wrap: wrap; gap: 12px; }
.card-title { font-size: 14px; font-weight: 700; color: var(--text-pri); }
.card-subtitle { font-family: var(--mono); font-size: 11px; color: var(--text-dim); margin-top: 2px; }
.card-body { padding: 22px; }
.card-tools { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.tool-btn { display: flex; align-items: center; gap: 5px; padding: 6px 12px; background: transparent; border: 1px solid var(--border-hi); border-radius: 6px; font-family: var(--mono); font-size: 11px; color: var(--text-sec); cursor: pointer; transition: all .15s; text-decoration: none; }
.tool-btn:hover { color: var(--text-pri); border-color: var(--accent); }

/* ═══════════════════════════════════════
   BADGES
═══════════════════════════════════════ */
.badge { font-family: var(--mono); font-size: 10px; font-weight: 500; padding: 3px 8px; border-radius: 4px; letter-spacing: .06em; text-transform: uppercase; }
.badge-success   { background: rgba(52,211,153,.1);   color: var(--success);   border: 1px solid rgba(52,211,153,.2); }
.badge-danger    { background: rgba(224,92,106,.1);   color: var(--danger);    border: 1px solid rgba(224,92,106,.2); }
.badge-warning   { background: rgba(245,166,35,.1);   color: var(--warning);   border: 1px solid rgba(245,166,35,.2); }
.badge-info      { background: rgba(56,189,248,.1);   color: var(--info);      border: 1px solid rgba(56,189,248,.2); }
.badge-secondary { background: rgba(69,77,102,.2);    color: var(--text-dim);  border: 1px solid var(--border-hi); }
.badge-accent    { background: rgba(79,110,247,.12);  color: var(--accent-hi); border: 1px solid rgba(79,110,247,.25); }
.badge-upcoming  { background: rgba(167,139,250,.1);  color: #a78bfa;          border: 1px solid rgba(167,139,250,.2); }
.badge-ongoing   { background: rgba(245,166,35,.1);   color: var(--warning);   border: 1px solid rgba(245,166,35,.2); }
.badge-past      { background: rgba(69,77,102,.15);   color: var(--text-dim);  border: 1px solid var(--border); }

/* ═══════════════════════════════════════
   TABLES
═══════════════════════════════════════ */
.table-wrap { overflow-x: auto; }
table.nx-table { width: 100%; border-collapse: collapse; }
table.nx-table thead th { font-family: var(--mono); font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .1em; padding: 10px 16px; background: var(--bg); border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; cursor: pointer; user-select: none; }
table.nx-table thead th .sort-icon { margin-left: 4px; opacity: .4; }
table.nx-table thead th.sorted-asc .sort-icon,
table.nx-table thead th.sorted-desc .sort-icon { opacity: 1; color: var(--accent-hi); }
table.nx-table thead th.no-sort { cursor: default; }
table.nx-table tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
table.nx-table tbody tr:last-child { border-bottom: none; }
table.nx-table tbody tr:hover { background: rgba(79,110,247,.04); }
table.nx-table tbody td { padding: 13px 16px; font-size: 13px; color: var(--text-sec); vertical-align: middle; }
table.nx-table tbody td.td-main { color: var(--text-pri); font-weight: 600; }
table.nx-table tbody td.mono { font-family: var(--mono); font-size: 12px; }
.row-actions { display: flex; gap: 4px; }
.act-btn { width: 30px; height: 30px; border-radius: 6px; border: 1px solid var(--border-hi); background: transparent; display: grid; place-items: center; font-size: 12px; cursor: pointer; color: var(--text-dim); transition: all .15s; }
.act-btn.view:hover   { color: var(--accent-hi); border-color: var(--accent); }
.act-btn.edit:hover   { color: var(--warning);   border-color: var(--warning); }
.act-btn.del:hover    { color: var(--danger);     border-color: var(--danger); }
.act-btn.toggle:hover { color: var(--success);    border-color: var(--success); }
.act-btn.pw:hover     { color: var(--info);        border-color: var(--info); }

/* ═══════════════════════════════════════
   PAGINATION
═══════════════════════════════════════ */
.table-footer { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 10px; }
.pagination-info { font-family: var(--mono); font-size: 11px; color: var(--text-dim); }
.pagination { display: flex; gap: 4px; }
.page-btn { min-width: 30px; height: 30px; padding: 0 8px; border-radius: 6px; background: transparent; border: 1px solid var(--border-hi); font-family: var(--mono); font-size: 11px; color: var(--text-sec); cursor: pointer; transition: all .15s; display: grid; place-items: center; }
.page-btn:hover, .page-btn.active { background: rgba(79,110,247,.12); border-color: var(--accent); color: var(--accent-hi); }
.page-btn:disabled { opacity: .3; cursor: not-allowed; }

/* ═══════════════════════════════════════
   MODALS
═══════════════════════════════════════ */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.72); backdrop-filter: blur(4px); z-index: 1000; display: none; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal-box { background: var(--panel); border: 1px solid var(--border-hi); border-radius: 14px; width: 100%; max-width: 540px; box-shadow: 0 32px 64px rgba(0,0,0,.6), 0 0 80px var(--accent-glow); position: relative; overflow: hidden; animation: modalIn .25s cubic-bezier(.22,1,.36,1); }
.modal-box.sm { max-width: 420px; }
.modal-box::before { content: ''; position: absolute; top: 0; left: 40px; right: 40px; height: 1px; background: linear-gradient(90deg, transparent, var(--accent), transparent); }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 24px 28px 0; }
.modal-title { font-size: 18px; font-weight: 800; letter-spacing: -.02em; }
.modal-title.danger { color: var(--danger); }
.modal-close { width: 32px; height: 32px; border-radius: 8px; background: var(--bg); border: 1px solid var(--border-hi); display: grid; place-items: center; font-size: 13px; color: var(--text-sec); cursor: pointer; transition: all .15s; }
.modal-close:hover { color: var(--danger); border-color: var(--danger); }
.modal-body { padding: 24px 28px; }
.modal-footer { padding: 0 28px 24px; display: flex; gap: 8px; justify-content: flex-end; }

/* ═══════════════════════════════════════
   FORMS
═══════════════════════════════════════ */
.form-grid  { display: grid; gap: 16px; }
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field label { font-family: var(--mono); font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .1em; }
.field input, .field select, .field textarea { width: 100%; background: var(--bg); border: 1px solid var(--border-hi); border-radius: 8px; padding: 10px 14px; font-family: var(--mono); font-size: 12px; color: var(--text-pri); outline: none; transition: border-color .2s, box-shadow .2s; }
.field input::placeholder, .field textarea::placeholder { color: var(--text-dim); }
.field input:focus, .field select:focus, .field textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
.field input[readonly] { color: var(--text-dim); cursor: not-allowed; }
.field select option { background: var(--panel); }
.field textarea { resize: vertical; min-height: 70px; }
.modal-divider { height: 1px; background: var(--border); margin: 4px 0 8px; }
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 40px; }
.pw-eye { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-dim); cursor: pointer; font-size: 13px; transition: color .15s; }
.pw-eye:hover { color: var(--text-sec); }
.pw-strength-bar { height: 4px; border-radius: 2px; background: var(--border-hi); margin-top: 6px; overflow: hidden; }
.pw-strength-fill { height: 100%; border-radius: 2px; transition: width .3s, background .3s; width: 0; }
.pw-strength-label { font-family: var(--mono); font-size: 10px; color: var(--text-dim); margin-top: 4px; }

/* ═══════════════════════════════════════
   DETAIL ROWS
═══════════════════════════════════════ */
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
.detail-row { display: flex; flex-direction: column; gap: 4px; padding: 12px 0; border-bottom: 1px solid var(--border); }
.detail-row.full { grid-column: 1 / -1; }
.detail-row:last-child { border-bottom: none; }
.detail-key { font-family: var(--mono); font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .08em; }
.detail-val { font-size: 13px; color: var(--text-pri); font-weight: 600; }
.detail-val.mono { font-family: var(--mono); font-size: 12px; }

/* ═══════════════════════════════════════
   FILTER BAR
═══════════════════════════════════════ */
.filter-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.filter-search { display: flex; align-items: center; gap: 8px; background: var(--bg); border: 1px solid var(--border-hi); border-radius: 7px; padding: 6px 12px; }
.filter-search input { background: none; border: none; outline: none; font-family: var(--mono); font-size: 12px; color: var(--text-pri); width: 180px; }
.filter-search input::placeholder { color: var(--text-dim); }
.filter-search i { color: var(--text-dim); font-size: 12px; }
.filter-select { background: var(--bg); border: 1px solid var(--border-hi); border-radius: 7px; padding: 6px 10px; font-family: var(--mono); font-size: 12px; color: var(--text-sec); cursor: pointer; outline: none; }
.filter-select:focus { border-color: var(--accent); }
.filter-select option { background: var(--panel); }

/* ═══════════════════════════════════════
   TOAST
═══════════════════════════════════════ */
.toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; pointer-events: none; }
.toast { display: flex; align-items: center; gap: 12px; background: var(--panel); border: 1px solid var(--border-hi); border-radius: 10px; padding: 14px 18px; box-shadow: 0 8px 32px rgba(0,0,0,.4); font-size: 13px; color: var(--text-pri); animation: slideInRight .3s cubic-bezier(.22,1,.36,1); min-width: 280px; pointer-events: all; }
.toast.success { border-left: 3px solid var(--success); }
.toast.error   { border-left: 3px solid var(--danger); }
.toast.info    { border-left: 3px solid var(--accent); }
.toast.warning { border-left: 3px solid var(--warning); }
.toast i { font-size: 16px; flex-shrink: 0; }
.toast.success i { color: var(--success); }
.toast.error   i { color: var(--danger); }
.toast.info    i { color: var(--accent-hi); }
.toast.warning i { color: var(--warning); }

/* ═══════════════════════════════════════
   EMPTY STATE
═══════════════════════════════════════ */
.empty-state { padding: 64px 0; text-align: center; }
.empty-state i { font-size: 48px; color: var(--text-dim); margin-bottom: 16px; display: block; }
.empty-state p { font-family: var(--mono); font-size: 13px; color: var(--text-dim); }

/* ═══════════════════════════════════════
   FOOTER
═══════════════════════════════════════ */
.footer { height: 44px; background: var(--panel); border-top: 1px solid var(--border); display: flex; align-items: center; padding: 0 28px; gap: 16px; flex-shrink: 0; }
.footer-text { font-family: var(--mono); font-size: 11px; color: var(--text-dim); }
.footer-links { margin-left: auto; display: flex; gap: 16px; }
.footer-link { font-family: var(--mono); font-size: 11px; color: var(--text-dim); text-decoration: none; transition: color .15s; }
.footer-link:hover { color: var(--accent-hi); }

/* ═══════════════════════════════════════
   STAT SUB
═══════════════════════════════════════ */
.stat-sub { font-family: var(--mono); font-size: 11px; color: var(--text-dim); margin-top: 4px; }

/* ═══════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════ */
@media (max-width: 960px) {
    .sidebar {
        position: fixed; top: 0; left: 0; bottom: 0;
        width: min(86vw, 320px); height: 100dvh;
        transform: translateX(-100%);
        transition: transform .22s cubic-bezier(.22,1,.36,1);
        box-shadow: 0 24px 48px rgba(0,0,0,.45);
        z-index: 120;
    }
    body.nx-sidebar-open .sidebar { transform: translateX(0); }
    body.nx-sidebar-open .sidebar-overlay {
        opacity: 1; visibility: visible; pointer-events: auto;
    }
    .sidebar-close,
    .topbar-menu { display: inline-flex; }
    .main { width: 100%; min-width: 0; }
    .topbar {
        height: auto; min-height: var(--topbar-h);
        padding: 12px 16px; flex-wrap: wrap;
    }
    .topbar-title { flex: 1; min-width: 0; }
    .topbar-right {
        margin-left: auto;
        width: auto;
        flex: 0 1 auto;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    .search-bar {
        width: 100%;
        flex: 0 0 100%;
        order: 10;
        margin-top: 8px;
    }
    .search-bar input { width: 100%; min-width: 0; }
    .topbar-status {
        margin-left: 0;
        order: 9;
    }
    .content { padding: 16px; }
    .page-header {
        flex-direction: column; align-items: flex-start;
        gap: 12px; margin-bottom: 20px;
    }
    .header-actions {
        width: 100%;
        flex-wrap: wrap;
    }
    .header-actions .btn {
        justify-content: center;
    }
    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .footer {
        height: auto; padding: 12px 16px;
        align-items: flex-start; flex-wrap: wrap;
    }
    .footer-links {
        margin-left: 0; width: 100%;
        flex-wrap: wrap; gap: 12px;
    }
    .topbar-action { position: static; }
    .dp {
        position: fixed; left: 12px; right: 12px;
        top: 72px; width: auto; max-width: none;
    }
    .dp-notif,
    .dp-settings,
    .dp-user { right: 12px; width: auto; }
    .notif-scroll { max-height: min(50vh, 340px); }
}

@media (max-width: 640px) {
    .bc-sep,
    .bc-cur,
    .topbar-status,
    #btnUser span,
    #btnUser .fa-chevron-down { display: none; }
    .topbar { gap: 10px; }
    .topbar-right {
        gap: 6px;
        flex: 0 1 auto;
    }
    .topbar-btn { min-width: 40px; }
    .dp { top: 68px; }
    .page-header h1 { font-size: 24px; }
    .page-header p {
        line-height: 1.6;
        word-break: break-word;
    }
    .header-actions .btn {
        flex: 1 1 calc(50% - 4px);
        min-width: 0;
        padding-inline: 12px;
    }
    .stats-grid {
        grid-template-columns: 1fr;
    }
    .stat-card,
    .card-body,
    .card-header {
        padding-left: 16px;
        padding-right: 16px;
    }
}

/* ═══════════════════════════════════════
   ANIMATIONS
═══════════════════════════════════════ */
@keyframes pulse        { 0%,100%{opacity:1} 50%{opacity:.4} }
@keyframes dropIn       { from{opacity:0;transform:translateY(-8px) scale(.98)} to{opacity:1;transform:none} }
@keyframes modalIn      { from{opacity:0;transform:translateY(14px) scale(.98)} to{opacity:1;transform:none} }
@keyframes slideInRight { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:none} }
::-webkit-scrollbar       { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-hi); border-radius: 3px; }

CSS;
}

/* ════════════════════════════════════════════
   nexusSidebar()
════════════════════════════════════════════ */
function nexusSidebar(): string
{
    global $NEXUS, $activePage;

    $av   = htmlspecialchars($NEXUS['avatarBg']);
    $init = htmlspecialchars($NEXUS['userInitials']);
    $name = htmlspecialchars($NEXUS['userName']);
    $sub  = htmlspecialchars($NEXUS['userEmail'] ?: '@' . $NEXUS['userUsername']);

    $role = $NEXUS['userRole'] ?? 'admin';
    if (!in_array($role, ['admin', 'staff', 'viewer'], true)) {
        $role = 'admin';
    }

    $nav = _nx_nav_link('dashboard', 'index.php',           'fas fa-home',            'Dashboard',     $activePage)
         . '<div class="nav-section-label">Master List</div>' . "\n";
    if ($role !== 'viewer') {
        $nav .= _nx_nav_link('events', 'manage_event.php', 'fa-solid fa-file-lines', 'Manage Events', $activePage);
    }
    $nav .= _nx_nav_link('audience', 'manage_audience.php',  'fas fa-users',           'Audience List', $activePage);
    if ($role === 'admin') {
        $nav .= _nx_nav_link('users', 'manage_users.php', 'fas fa-user-plus', 'User List', $activePage);
    }
    $nav .= _nx_nav_link('reports', 'reports.php', 'fas fa-chart-bar', 'Reports', $activePage);
    if ($role !== 'viewer') {
        $nav .= _nx_nav_link('certificates', 'certificates.php', 'fas fa-certificate', 'Certificates', $activePage);
    }
    if ($role === 'staff' || $role === 'viewer') {
        $nav .= '<div class="nav-section-label">Account</div>' . "\n"
             . _nx_nav_link('account', 'account.php', 'fas fa-id-card', 'My account', $activePage);
    }

    return
'<nav class="sidebar">' . "\n"
. '    <div class="sidebar-brand">' . "\n"
. '        <div class="brand-mark">' . "\n"
. '            <svg viewBox="0 0 18 18" fill="none">' . "\n"
. '                <rect x="1" y="1" width="7" height="7" rx="1.5" fill="white"/>' . "\n"
. '                <rect x="10" y="1" width="7" height="7" rx="1.5" fill="white" opacity=".6"/>' . "\n"
. '                <rect x="1" y="10" width="7" height="7" rx="1.5" fill="white" opacity=".6"/>' . "\n"
. '                <rect x="10" y="10" width="7" height="7" rx="1.5" fill="white" opacity=".3"/>' . "\n"
. '            </svg>' . "\n"
. '        </div>' . "\n"
. '        <span class="brand-name">Nexus</span>' . "\n"
. '        <span class="brand-env">PROD</span>' . "\n"
. '        <button type="button" class="sidebar-close" onclick="nexusCloseSidebar()" aria-label="Close menu">' . "\n"
. '            <i class="fas fa-xmark"></i>' . "\n"
. '        </button>' . "\n"
. '    </div>' . "\n"
. '    <div class="sidebar-scroll">' . "\n"
. '        <div class="nav-section-label">Main</div>' . "\n"
. '        ' . $nav
. '    </div>' . "\n"
. '    <div class="sidebar-footer">' . "\n"
. '        <div class="sidebar-user">' . "\n"
. '            <div class="s-avatar" style="background:' . $av . '">' . $init . '</div>' . "\n"
. '            <div class="s-info">' . "\n"
. '                <div class="s-name">' . $name . '</div>' . "\n"
. '                <div class="s-role">' . $sub . '</div>' . "\n"
. '            </div>' . "\n"
. '            <button onclick="nexusLogout()" class="logout-btn" title="Sign Out">' . "\n"
. '                <i class="fas fa-arrow-right-from-bracket"></i>' . "\n"
. '            </button>' . "\n"
. '        </div>' . "\n"
. '    </div>' . "\n"
. '</nav>' . "\n"
. '<div class="sidebar-overlay" onclick="nexusCloseSidebar()" aria-hidden="true"></div>';
}

/* ════════════════════════════════════════════
   nexusTopbar()
════════════════════════════════════════════ */
function nexusTopbar(string $searchHandler = ''): string
{
    global $NEXUS, $pageTitle, $pageSubtitle;

    $av     = htmlspecialchars($NEXUS['avatarBg']);
    $init   = htmlspecialchars($NEXUS['userInitials']);
    $name   = htmlspecialchars($NEXUS['userName']);
    $first  = htmlspecialchars(explode(' ', $NEXUS['userName'])[0]);
    $email  = htmlspecialchars($NEXUS['userEmail'] ?: '@' . $NEXUS['userUsername']);
    $uname  = htmlspecialchars($NEXUS['userUsername']);
    $role   = htmlspecialchars($NEXUS['userRole']);
    $nc     = (int)$NEXUS['notifCount'];
    $ndot   = $nc === 0 ? ' hidden' : '';
    $ncHtml = $nc > 0 ? '<span class="dp-count">' . $nc . '</span>' : '';
    $pTitle = htmlspecialchars($pageTitle);
    $pSub   = htmlspecialchars($pageSubtitle);
    $since  = isset($_SESSION['login_time']) ? date('M d, H:i', $_SESSION['login_time']) : 'this session';
    $srch   = $searchHandler ? ' oninput="' . htmlspecialchars($searchHandler) . '(this.value)"' : '';

    $rawRole = $NEXUS['userRole'] ?? 'admin';
    if (!in_array($rawRole, ['admin', 'staff', 'viewer'], true)) {
        $rawRole = 'admin';
    }
    $profileHref = in_array($rawRole, ['staff', 'viewer'], true) ? 'account.php' : 'manage_users.php';

    // Build notification rows with per-item dismiss button
    $notifHtml = '';
    if (empty($NEXUS['notifications'])) {
        $notifHtml = '<div class="notif-empty">'
                   . '<i class="fas fa-bell-slash"></i>'
                   . '<p>No new notifications</p>'
                   . '</div>';
    } else {
        foreach ($NEXUS['notifications'] as $n) {
            $icon    = htmlspecialchars($n['icon']);
            $color   = htmlspecialchars($n['color']);
            $text    = $n['text'];
            $ts      = nexus_time_ago($n['time']);
            $key     = htmlspecialchars($n['key']);
            $href    = htmlspecialchars($n['href'] ?? '#');
            $unread  = $n['unread'] ? ' unread' : '';
            $notifHtml .=
                '<a href="' . $href . '" class="notif-row' . $unread . '" data-key="' . $key . '" '
                . 'onclick="nexusMarkOne(\'' . $key . '\',event)">'
                .   '<div class="ni ' . $color . '"><i class="fas ' . $icon . '"></i></div>'
                .   '<div style="flex:1;min-width:0">'
                .     '<div class="notif-body">' . $text . '</div>'
                .     '<div class="notif-ts">' . $ts . '</div>'
                .   '</div>'
                .   '<button class="notif-row-dismiss" title="Dismiss" '
                .       'onclick="nexusDismissOne(\'' . $key . '\',event)">'
                .       '<i class="fas fa-xmark"></i>'
                .   '</button>'
                . '</a>' . "\n";
        }
    }

    return
'<header class="topbar">' . "\n"
. '    <button type="button" class="topbar-menu" id="btnSidebarToggle" onclick="nexusToggleSidebar()" aria-label="Open menu">' . "\n"
. '        <i class="fas fa-bars"></i>' . "\n"
. '    </button>' . "\n"
. '    <span class="topbar-title">' . $pTitle . '</span>' . "\n"
. '    <span class="bc-sep">/</span>' . "\n"
. '    <span class="bc-cur">' . $pSub . '</span>' . "\n"
. '    <div class="topbar-right">' . "\n"
. '        <div class="topbar-action">' . "\n"
. '            <button class="topbar-btn" id="btnNotif" onclick="nexusDp(\'notif\')">' . "\n"
. '                <i class="fas fa-bell"></i>' . "\n"
. '                <span class="notif-dot' . $ndot . '" id="notifDot"></span>' . "\n"
. '            </button>' . "\n"
. '            <div class="dp dp-notif" id="dpNotif">' . "\n"
. '                <div class="dp-head">' . "\n"
. '                    <span class="dp-head-title">Notifications ' . $ncHtml . '</span>' . "\n"
. '                    <button class="dp-link" onclick="nexusMarkAllRead()">Mark all read</button>' . "\n"
. '                </div>' . "\n"
. '                <div class="notif-scroll" id="notifScroll">' . $notifHtml . '</div>' . "\n"
. '                <div class="dp-footer"><a href="manage_audience.php">View registrations &rarr;</a></div>' . "\n"
. '            </div>' . "\n"
. '        </div>' . "\n"
. '        <div class="topbar-action">' . "\n"
. '            <button class="topbar-btn" id="btnSettings" onclick="nexusDp(\'settings\')">' . "\n"
. '                <i class="fas fa-gear"></i>' . "\n"
. '            </button>' . "\n"
. '            <div class="dp dp-settings" id="dpSettings">' . "\n"
. '                <div class="dp-head"><span class="dp-head-title">Preferences</span></div>' . "\n"
. '                <div class="s-section">' . "\n"
. '                    <div class="s-section-title">Appearance</div>' . "\n"
. '                    <div class="s-row"><span class="s-label"><i class="fas fa-moon"></i> Dark Mode</span><label class="toggle"><input type="checkbox" id="togDark" checked onchange="nexusSaveSetting(\'dark\',this.checked)"><span class="toggle-track"></span></label></div>' . "\n"
. '                    <div class="s-row"><span class="s-label"><i class="fas fa-compress"></i> Compact View</span><label class="toggle"><input type="checkbox" id="togCompact" onchange="nexusSaveSetting(\'compact\',this.checked)"><span class="toggle-track"></span></label></div>' . "\n"
. '                </div>' . "\n"
. '                <div class="s-section">' . "\n"
. '                    <div class="s-section-title">Notifications</div>' . "\n"
. '                    <div class="s-row"><span class="s-label"><i class="fas fa-bell"></i> Push Alerts</span><label class="toggle"><input type="checkbox" id="togPush" checked onchange="nexusSaveSetting(\'push\',this.checked)"><span class="toggle-track"></span></label></div>' . "\n"
. '                    <div class="s-row"><span class="s-label"><i class="fas fa-envelope"></i> Email Digest</span><label class="toggle"><input type="checkbox" id="togEmail" onchange="nexusSaveSetting(\'email\',this.checked)"><span class="toggle-track"></span></label></div>' . "\n"
. '                </div>' . "\n"
. '                <div class="s-section">' . "\n"
. '                    <div class="s-section-title">Session</div>' . "\n"
. '                    <div class="session-info">'
.                         'Signed in as <span style="color:var(--accent-hi)">@' . $uname . '</span><br>'
.                         'Since ' . $since . '<br>'
.                         'Role: <span class="role-pill ' . $role . '">' . $role . '</span>'
.                     '</div>' . "\n"
. '                </div>' . "\n"
. '                <div class="s-section" style="padding:10px 18px">' . "\n"
. '                    <button onclick="nexusLogout()" class="dp-item danger" style="border-radius:6px;padding:8px 10px;cursor:pointer;text-align:left">' . "\n"
. '                        <i class="fas fa-arrow-right-from-bracket"></i> Sign Out' . "\n"
. '                    </button>' . "\n"
. '                </div>' . "\n"
. '            </div>' . "\n"
. '        </div>' . "\n"
. '        <div class="topbar-action">' . "\n"
. '            <button class="topbar-btn" id="btnUser" onclick="nexusDp(\'user\')" style="padding:0 10px;gap:7px">' . "\n"
. '                <div style="width:24px;height:24px;border-radius:6px;background:' . $av . ';display:grid;place-items:center;font-size:10px;font-weight:800;color:#fff;flex-shrink:0">' . $init . '</div>' . "\n"
. '                <span style="font-size:12px;color:var(--text-pri);max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . $first . '</span>' . "\n"
. '                <i class="fas fa-chevron-down" style="font-size:9px;color:var(--text-dim)"></i>' . "\n"
. '            </button>' . "\n"
. '            <div class="dp dp-user" id="dpUser">' . "\n"
. '                <div class="dp-user-hero">' . "\n"
. '                    <div class="dp-user-av" style="background:' . $av . '">' . $init . '</div>' . "\n"
. '                    <div style="min-width:0">' . "\n"
. '                        <div class="dp-user-name">' . $name . '</div>' . "\n"
. '                        <div class="dp-user-sub">' . $email . '</div>' . "\n"
. '                        <span class="role-pill ' . $role . '">' . $role . '</span>' . "\n"
. '                    </div>' . "\n"
. '                </div>' . "\n"
. '                <div class="dp-divider"></div>' . "\n"
. '                <a href="' . $profileHref . '" class="dp-item"><i class="fas fa-user-pen"></i> Edit Profile</a>' . "\n"
. '                <a href="' . $profileHref . '" class="dp-item"><i class="fas fa-key"></i> Change Password</a>' . "\n"
. ($rawRole === 'admin'
    ? '                <div class="dp-divider"></div>' . "\n"
    . '                <a href="manage_users.php" class="dp-item"><i class="fas fa-shield-halved"></i> User Management</a>' . "\n"
    : '')
. '                <div class="dp-divider"></div>' . "\n"
. '                <button onclick="nexusLogout()" class="dp-item danger" style="cursor:pointer;text-align:left">' . "\n"
. '                    <i class="fas fa-arrow-right-from-bracket"></i> Sign Out' . "\n"
. '                </button>' . "\n"
. '            </div>' . "\n"
. '        </div>' . "\n"
. '        <div class="topbar-status"><span class="status-dot"></span>All Systems Normal</div>' . "\n"
. '    </div>' . "\n"

. '</header>';
}

/* ════════════════════════════════════════════
   nexusFooter()
════════════════════════════════════════════ */
function nexusFooter(array $links = []): string
{
    global $NEXUS;
    $name = htmlspecialchars($NEXUS['userName']);
    $year = date('Y');
    if (empty($links)) {
        $links = ['Help' => '#', 'Licenses' => '#', 'Privacy' => '#'];
    }
    $linkHtml = '';
    foreach ($links as $label => $href) {
        $linkHtml .= '<a href="' . htmlspecialchars($href) . '" class="footer-link">' . htmlspecialchars($label) . '</a>' . "\n";
    }
    return '<footer class="footer">' . "\n"
         . '    <span class="footer-text">Nexus Platform &copy; ' . $year . ' &nbsp;&middot;&nbsp; v4.12.1 &nbsp;&middot;&nbsp; ' . $name . '</span>' . "\n"
         . '    <div class="footer-links">' . $linkHtml . '    </div>' . "\n"
         . '</footer>';
}

/* ════════════════════════════════════════════
   nexusToast()
════════════════════════════════════════════ */
function nexusToast(): string
{
    return '
<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Logout confirmation modal -->
<div class="modal-overlay" id="logoutModal">
    <div class="modal-box sm">
        <div class="modal-header">
            <div class="modal-title danger">
                <i class="fas fa-arrow-right-from-bracket" style="font-size:16px;margin-right:10px"></i>Sign Out
            </div>
            <button class="modal-close" onclick="closeModal(\'logoutModal\')">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body">
            <p style="font-family:var(--mono);font-size:13px;color:var(--text-sec);line-height:1.7;margin-bottom:8px">
                Are you sure you want to sign out?
            </p>
            <p style="font-family:var(--mono);font-size:11px;color:var(--text-dim);line-height:1.6">
                Your session will be ended and you will be redirected to the login page.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal(\'logoutModal\')">
                <i class="fas fa-xmark"></i> Cancel
            </button>
            <a href="logout.php" class="btn btn-danger-ghost">
                <i class="fas fa-arrow-right-from-bracket"></i> Yes, Sign Out
            </a>
        </div>
    </div>
</div>';
}

/* ════════════════════════════════════════════
   nexusJS()
════════════════════════════════════════════ */
function nexusJS(): string
{
    return <<<'JSBLOCK'
<script>
/* ════════════════════════════════════════════
   NEXUS SHARED JS — v4.13.0
════════════════════════════════════════════ */

/* ── Dropdown system ── */
const _NX_DPS = { notif: 'dpNotif', settings: 'dpSettings', user: 'dpUser' };
const _NX_BNS = { notif: 'btnNotif', settings: 'btnSettings', user: 'btnUser' };
const _NX_MOBILE = window.matchMedia('(max-width: 960px)');

function nexusDp(key) {
    const wasOpen = document.getElementById(_NX_DPS[key]).classList.contains('open');
    _nexusCloseAll();
    if (!wasOpen) {
        document.getElementById(_NX_DPS[key]).classList.add('open');
        document.getElementById(_NX_BNS[key]).classList.add('active');
    }
}
function _nexusCloseAll() {
    Object.values(_NX_DPS).forEach(id => document.getElementById(id)?.classList.remove('open'));
    Object.values(_NX_BNS).forEach(id => document.getElementById(id)?.classList.remove('active'));
}
function nexusToggleSidebar(forceOpen) {
    const shouldOpen = typeof forceOpen === 'boolean'
        ? forceOpen
        : !document.body.classList.contains('nx-sidebar-open');
    document.body.classList.toggle('nx-sidebar-open', shouldOpen && _NX_MOBILE.matches);
    document.getElementById('btnSidebarToggle')?.classList.toggle('active', shouldOpen && _NX_MOBILE.matches);
}
function nexusCloseSidebar() {
    document.body.classList.remove('nx-sidebar-open');
    document.getElementById('btnSidebarToggle')?.classList.remove('active');
}
document.addEventListener('click', e => {
    const inside = Object.keys(_NX_DPS).some(k =>
        document.getElementById(_NX_DPS[k])?.contains(e.target) ||
        document.getElementById(_NX_BNS[k])?.contains(e.target)
    );
    if (!inside) _nexusCloseAll();
    if (_NX_MOBILE.matches && e.target.closest('.sidebar .nav-item')) {
        nexusCloseSidebar();
    }
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        _nexusCloseAll();
        nexusCloseSidebar();
        closeModal('logoutModal');
    }
});
function _nexusSyncMobileUi() {
    if (!_NX_MOBILE.matches) {
        nexusCloseSidebar();
    }
}
if (typeof _NX_MOBILE.addEventListener === 'function') {
    _NX_MOBILE.addEventListener('change', _nexusSyncMobileUi);
} else if (typeof _NX_MOBILE.addListener === 'function') {
    _NX_MOBILE.addListener(_nexusSyncMobileUi);
}
window.addEventListener('resize', _nexusSyncMobileUi);

/* ════════════════════════════════════════════
   NOTIFICATIONS — mark read / dismiss
════════════════════════════════════════════ */

/**
 * POST a key (or array of keys) to nexus_notif_action.php.
 * action: 'read' | 'dismiss'
 */
function _nexusNotifPost(action, keys) {
    if (!Array.isArray(keys)) keys = [keys];
    const fd = new FormData();
    fd.append('action', action);
    keys.forEach(k => fd.append('keys[]', k));
    fetch('nexus_notif_action.php', { method: 'POST', body: fd }).catch(() => {});
}

/** Mark a single row as read when clicking it (navigates away naturally). */
function nexusMarkOne(key, event) {
    const row = document.querySelector(`.notif-row[data-key="${CSS.escape(key)}"]`);
    if (!row) return;
    row.classList.remove('unread');
    _nexusNotifPost('read', key);
    _nexusUpdateDot();
    // allow <a> navigation to proceed normally
}

/** Dismiss (hide) a single row without navigating. */
function nexusDismissOne(key, event) {
    event.preventDefault();
    event.stopPropagation();
    const row = document.querySelector(`.notif-row[data-key="${CSS.escape(key)}"]`);
    if (!row) return;
    row.style.transition = 'opacity .2s, max-height .25s, padding .25s';
    row.style.overflow   = 'hidden';
    row.style.opacity    = '0';
    row.style.maxHeight  = row.offsetHeight + 'px';
    requestAnimationFrame(() => {
        row.style.maxHeight = '0';
        row.style.padding   = '0';
    });
    setTimeout(() => {
        row.remove();
        _nexusUpdateDot();
        _nexusMaybeEmpty();
    }, 280);
    _nexusNotifPost('dismiss', key);
}

/** Mark all unread rows as read. */
function nexusMarkAllRead() {
    const rows = document.querySelectorAll('.notif-row.unread');
    const keys = [];
    rows.forEach(r => {
        r.classList.remove('unread');
        keys.push(r.dataset.key);
    });
    if (keys.length) _nexusNotifPost('read', keys);
    _nexusUpdateDot();
}

/** Update the red dot / count badge. */
function _nexusUpdateDot() {
    const unreadCount = document.querySelectorAll('.notif-row.unread').length;
    const dot  = document.getElementById('notifDot');
    const badge = document.querySelector('#dpNotif .dp-count');
    if (dot)   dot.classList.toggle('hidden', unreadCount === 0);
    if (badge) {
        if (unreadCount > 0) { badge.textContent = unreadCount; badge.style.display = ''; }
        else                 { badge.style.display = 'none'; }
    }
}

/** Show empty state if all rows are gone. */
function _nexusMaybeEmpty() {
    const scroll = document.getElementById('notifScroll');
    if (!scroll) return;
    if (!scroll.querySelector('.notif-row')) {
        scroll.innerHTML =
            '<div class="notif-empty">'
            + '<i class="fas fa-bell-slash"></i>'
            + '<p>All caught up!</p>'
            + '</div>';
    }
}

/* ── Settings (localStorage) ── */
const _NX_DEFAULTS = { dark: true, compact: false, push: true, email: false };
const _NX_IDS      = { dark: 'togDark', compact: 'togCompact', push: 'togPush', email: 'togEmail' };

function nexusSaveSetting(key, val) {
    localStorage.setItem('nx_' + key, val);
    _nexusApply(key, val);
}
function _nexusApply(key, val) {
    if (key === 'dark') {
        document.body.classList.toggle('light', !val);
        const el = document.getElementById('togDark');
        if (el) el.checked = !!val;
    }
    if (key === 'compact') {
        document.querySelectorAll('.stat-card').forEach(c => c.style.padding = val ? '14px 16px 12px' : '');
        document.querySelectorAll('.card-body').forEach(c  => c.style.padding = val ? '14px' : '');
    }
}
(function _nexusLoadSettings() {
    Object.entries(_NX_IDS).forEach(([key, elId]) => {
        const el = document.getElementById(elId);
        if (!el) return;
        const stored = localStorage.getItem('nx_' + key);
        const val = stored !== null ? stored === 'true' : _NX_DEFAULTS[key];
        el.checked = val;
        _nexusApply(key, val);
    });
})();

/* ── Toast ── */
function showToast(msg, type = 'success') {
    const icons = { success: 'circle-check', error: 'circle-xmark', info: 'circle-info', warning: 'triangle-exclamation' };
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `<i class="fas fa-${icons[type] || 'circle-info'}"></i><span>${msg}</span>`;
    document.getElementById('toastContainer').appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

/* ── Password helpers ── */
function nexusPwToggle(inputId, btn) {
    const inp = document.getElementById(inputId);
    const isText = inp.type === 'text';
    inp.type = isText ? 'password' : 'text';
    btn.querySelector('i').className = isText ? 'fas fa-eye' : 'fas fa-eye-slash';
}
function nexusPwStrength(pw, barId, labelId) {
    let s = 0;
    if (pw.length >= 8)            s++;
    if (pw.length >= 12)           s++;
    if (/[A-Z]/.test(pw))         s++;
    if (/[0-9]/.test(pw))         s++;
    if (/[^A-Za-z0-9]/.test(pw)) s++;
    const levels = [
        { w: '0%',   bg: 'var(--border-hi)', t: 'Enter a password' },
        { w: '25%',  bg: 'var(--danger)',    t: 'Very weak' },
        { w: '40%',  bg: 'var(--danger)',    t: 'Weak' },
        { w: '60%',  bg: 'var(--warning)',   t: 'Fair' },
        { w: '80%',  bg: 'var(--success)',   t: 'Strong' },
        { w: '100%', bg: '#34d399',          t: 'Very strong' },
    ];
    const l = levels[pw ? s : 0] || levels[0];
    const bar   = document.getElementById(barId);
    const label = document.getElementById(labelId);
    if (bar)   { bar.style.width = l.w; bar.style.background = l.bg; }
    if (label) { label.textContent = l.t; label.style.color = l.bg; }
}

/* ── Modal helpers ── */
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); })
);

/* ── Logout ── */
function nexusLogout() {
    _nexusCloseAll();
    openModal('logoutModal');
}
</script>
JSBLOCK;
}
