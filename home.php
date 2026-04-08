<?php
require 'config/db.php';

function getEventStatus(string $start, string $end): array {
    $now = new DateTime();
    $s   = new DateTime($start);
    $e   = new DateTime($end);
    if ($now < $s) {
        return ['label' => 'Upcoming', 'key' => 'upcoming'];
    }
    if ($now >= $s && $now <= $e) {
        return ['label' => 'Ongoing', 'key' => 'ongoing'];
    }
    return ['label' => 'Closed', 'key' => 'closed'];
}

$stmt = $pdo->query("
    SELECT id, agenda, venue, start_date, end_date, event_days
    FROM event_settings
    WHERE active = 1
    ORDER BY start_date ASC
");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Ongoing first, then upcoming, then closed/past; within each bucket by start date */
$statusOrder = ['ongoing' => 0, 'upcoming' => 1, 'closed' => 2];
usort($events, static function (array $a, array $b) use ($statusOrder): int {
    $ka = getEventStatus($a['start_date'], $a['end_date'])['key'];
    $kb = getEventStatus($b['start_date'], $b['end_date'])['key'];
    $ra = $statusOrder[$ka] ?? 99;
    $rb = $statusOrder[$kb] ?? 99;
    if ($ra !== $rb) {
        return $ra <=> $rb;
    }
    return strtotime($a['start_date']) <=> strtotime($b['start_date']);
});

$eventStats = ['ongoing' => 0, 'upcoming' => 0, 'closed' => 0];
foreach ($events as $ev) {
    $k = getEventStatus($ev['start_date'], $ev['end_date'])['key'];
    if (isset($eventStats[$k])) {
        $eventStats[$k]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PN Event Registration</title>
<script>(function(){try{var d=localStorage.getItem('nx_dark');if(d==='false')document.documentElement.classList.add('light');}catch(e){}})();</script>
<link rel="stylesheet" href="css/public-theme.css">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
:root {
    --bg:          #0a0c10;
    --panel:       #0f1117;
    --panel-hi:    #141720;
    --border:      #1e2230;
    --border-hi:   #2e3450;
    --accent:      #4f6ef7;
    --accent-hi:   #6e87ff;
    --accent-glow: rgba(79,110,247,0.18);
    --gold:        #f0c060;
    --success:     #34d399;
    --warning:     #f5a623;
    --danger:      #e05c6a;
    --text-pri:    #eef0f8;
    --text-sec:    #8890aa;
    --text-dim:    #454d66;
    --mono:        'DM Mono', monospace;
    --sans:        'Syne', sans-serif;
    --radius:      12px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }

body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text-pri);
    min-height: 100vh;
}

body::before {
    content: '';
    position: fixed; inset: 0;
    background-image:
        linear-gradient(var(--border) 1px, transparent 1px),
        linear-gradient(90deg, var(--border) 1px, transparent 1px);
    background-size: 48px 48px;
    opacity: .22;
    pointer-events: none;
    z-index: 0;
}

/* ── HERO ── */
.hero {
    position: relative; z-index: 1;
    padding: 48px 24px 56px;
    text-align: center;
    border-bottom: 1px solid var(--border-hi);
    background: linear-gradient(180deg, var(--panel) 0%, var(--panel-hi) 100%);
    overflow: hidden;
}
.hero::before {
    content: '';
    position: absolute; inset: 0;
    background:
        radial-gradient(ellipse 80% 60% at 50% -20%, rgba(79,110,247,.18) 0%, transparent 55%),
        radial-gradient(ellipse at 20% 70%, rgba(79,110,247,.1) 0%, transparent 50%),
        radial-gradient(ellipse at 85% 15%, rgba(240,192,96,.09) 0%, transparent 42%);
    pointer-events: none;
}
.hero::after {
    content: '';
    position: absolute;
    bottom: 0; left: 10%; right: 10%; height: 1px;
    background: linear-gradient(90deg, transparent, rgba(240,192,96,.45), transparent);
    opacity: .55;
}
.hero-badge-row {
    display: inline-flex; align-items: center; justify-content: center;
    flex-wrap: wrap; gap: 8px 14px;
    margin-bottom: 22px;
}
.hero-pill {
    font-family: var(--mono); font-size: 9px; font-weight: 500;
    letter-spacing: .14em; text-transform: uppercase;
    color: var(--text-sec);
    padding: 5px 12px;
    border-radius: 100px;
    border: 1px solid var(--border-hi);
    background: rgba(79,110,247,.06);
}
.hero-pill i { color: var(--accent-hi); margin-right: 5px; font-size: 8px; }
.hero-pill.gold { background: rgba(240,192,96,.07); border-color: rgba(240,192,96,.2); }
.hero-pill.gold i { color: var(--gold); }
.hero-lines { position: absolute; inset: 0; pointer-events: none; overflow: hidden; }
.hero-lines span {
    position: absolute; display: block;
    width: 1px; height: 100%; top: 0;
    background: linear-gradient(to bottom, transparent, rgba(79,110,247,.07), transparent);
}
.hero-lines span:nth-child(1) { left: 12%; }
.hero-lines span:nth-child(2) { left: 33%; }
.hero-lines span:nth-child(3) { right: 33%; }
.hero-lines span:nth-child(4) { right: 12%; }

.hero-inner { position: relative; z-index: 1; max-width: 640px; margin: 0 auto; }

.hero-logos {
    display: flex; align-items: center; justify-content: center;
    gap: 20px; margin-bottom: 26px;
}
.hero-logos img {
    height: 62px;
    filter: drop-shadow(0 4px 20px rgba(0,0,0,.45));
    transition: transform .35s ease;
}
.hero-inner:hover .hero-logos img { transform: translateY(-2px); }
.logo-divider {
    width: 1px; height: 48px;
    background: linear-gradient(180deg, transparent, var(--border-hi), transparent);
    opacity: .9;
}

.hero-eyebrow {
    font-family: var(--mono); font-size: 10px; font-weight: 500;
    letter-spacing: .22em; text-transform: uppercase;
    color: var(--gold); margin-bottom: 12px; opacity: .92;
}
.hero-title {
    font-size: clamp(28px, 4.2vw, 42px); font-weight: 800;
    letter-spacing: -.035em; color: var(--text-pri);
    line-height: 1.12; margin-bottom: 14px;
}
.hero-title .hero-gradient {
    background: linear-gradient(135deg, var(--text-pri) 0%, var(--accent-hi) 48%, var(--gold) 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    color: transparent;
}
html.light .hero-title .hero-gradient {
    background: linear-gradient(135deg, #0f1117 0%, #4f6ef7 50%, #b45309 100%);
    -webkit-background-clip: text;
    background-clip: text;
}
.hero-subtitle {
    font-family: var(--mono); font-size: 12px;
    color: var(--text-sec); line-height: 1.75;
    max-width: 520px; margin: 0 auto;
    opacity: .95;
}
.hero-scroll-hint {
    display: inline-flex; align-items: center; gap: 8px;
    margin-top: 28px;
    font-family: var(--mono); font-size: 10px;
    letter-spacing: .12em; text-transform: uppercase;
    color: var(--text-dim);
    animation: heroHintFloat 2.5s ease-in-out infinite;
}
.hero-scroll-hint i { font-size: 11px; color: var(--accent); opacity: .8; }
@keyframes heroHintFloat {
    0%, 100% { transform: translateY(0); opacity: .75; }
    50% { transform: translateY(5px); opacity: 1; }
}

/* Quick stats (below hero) */
.stats-strip {
    position: relative; z-index: 1;
    border-bottom: 1px solid var(--border-hi);
    background: var(--bg);
    padding: 16px 20px;
}
.stats-strip-inner {
    max-width: 860px; margin: 0 auto;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}
.stat-chip {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 4px;
    padding: 14px 12px;
    border-radius: var(--radius);
    border: 1px solid var(--border-hi);
    background: var(--panel);
    transition: border-color .2s, box-shadow .2s;
}
.stat-chip:hover {
    border-color: rgba(79,110,247,.35);
    box-shadow: 0 8px 28px rgba(0,0,0,.12);
}
.stat-chip .stat-num {
    font-family: var(--mono); font-size: 26px; font-weight: 600;
    letter-spacing: -.03em; line-height: 1;
    color: var(--text-pri);
}
.stat-chip .stat-num.accent { color: var(--accent-hi); }
.stat-chip .stat-num.gold { color: var(--gold); }
.stat-chip .stat-num.muted { color: var(--text-dim); }
.stat-chip .stat-label {
    font-family: var(--mono); font-size: 9px;
    letter-spacing: .14em; text-transform: uppercase;
    color: var(--text-dim);
}

/* ── MAIN ── */
.main {
    position: relative; z-index: 1;
    max-width: 860px; margin: 0 auto;
    padding: 36px 20px 88px;
}

.section-head { margin-bottom: 22px; }
.section-label {
    display: flex; align-items: center; gap: 10px;
    font-family: var(--mono); font-size: 10px; font-weight: 600;
    letter-spacing: .16em; text-transform: uppercase;
    color: var(--text-dim); margin-bottom: 8px;
}
.section-label::after { content: ''; flex: 1; height: 1px; background: linear-gradient(90deg, var(--border-hi), transparent); }
.section-label i { color: var(--accent); font-size: 11px; }
.section-lead {
    font-size: 14px; font-weight: 500;
    color: var(--text-sec); line-height: 1.55;
    max-width: 52ch;
}

/* ── EVENT CARDS ── */
.events-list { display: flex; flex-direction: column; gap: 14px; }

.event-card {
    background: var(--panel);
    border: 1px solid var(--border-hi);
    border-radius: 14px;
    overflow: hidden; position: relative;
    transition: border-color .25s, box-shadow .25s, transform .25s;
    animation: fadeUp .45s cubic-bezier(.22,1,.36,1) both;
    box-shadow: 0 4px 24px rgba(0,0,0,.12);
}
html.light .event-card { box-shadow: 0 4px 24px rgba(15,17,23,.06); }
.event-card:nth-child(1) { animation-delay: .04s; }
.event-card:nth-child(2) { animation-delay: .10s; }
.event-card:nth-child(3) { animation-delay: .16s; }
.event-card:nth-child(4) { animation-delay: .22s; }
.event-card:nth-child(5) { animation-delay: .28s; }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: none; }
}

.event-card:hover {
    border-color: rgba(79,110,247,.55);
    box-shadow: 0 0 0 1px rgba(79,110,247,.35), 0 16px 48px rgba(79,110,247,.14);
    transform: translateY(-3px);
}

/* Top accent bar */
.event-card::before {
    content: ''; position: absolute;
    top: 0; left: 0; right: 0; height: 2px;
}
.event-card.status-ongoing::before  { background: linear-gradient(90deg, var(--accent), var(--accent-hi)); }
.event-card.status-upcoming::before { background: linear-gradient(90deg, var(--gold), #fbbf24); }
.event-card.status-closed::before   { background: var(--border-hi); }

/* Ambient glow */
.event-card.status-ongoing::after {
    content: ''; position: absolute; top: -50px; right: -50px;
    width: 200px; height: 200px; border-radius: 50%; pointer-events: none;
    background: radial-gradient(circle, rgba(79,110,247,.06) 0%, transparent 70%);
}
.event-card.status-upcoming::after {
    content: ''; position: absolute; top: -50px; right: -50px;
    width: 200px; height: 200px; border-radius: 50%; pointer-events: none;
    background: radial-gradient(circle, rgba(240,192,96,.05) 0%, transparent 70%);
}

.card-inner { padding: 24px 26px 22px; position: relative; z-index: 1; }

.card-top {
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: 14px;
    margin-bottom: 14px; flex-wrap: wrap;
}

.agenda-wrap { flex: 1; min-width: 0; }
.agenda-number {
    font-family: var(--mono); font-size: 10px;
    color: var(--text-dim); letter-spacing: .12em;
    text-transform: uppercase; margin-bottom: 4px;
}
.agenda-title {
    font-size: 18px; font-weight: 700;
    color: var(--text-pri); line-height: 1.35; letter-spacing: -.02em;
}

/* Status badge */
.status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 11px; border-radius: 20px;
    font-family: var(--mono); font-size: 10px; font-weight: 500;
    letter-spacing: .08em; text-transform: uppercase;
    white-space: nowrap; flex-shrink: 0; border: 1px solid;
}
.status-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }

.badge-ongoing  { background: rgba(79,110,247,.08);  color: var(--accent-hi); border-color: rgba(79,110,247,.25); }
.badge-ongoing  .status-dot { background: var(--accent-hi); box-shadow: 0 0 6px var(--accent); animation: blink 2s ease infinite; }
.badge-upcoming { background: rgba(240,192,96,.08);  color: var(--gold);       border-color: rgba(240,192,96,.25); }
.badge-upcoming .status-dot { background: var(--gold); }
.badge-closed   { background: rgba(69,77,102,.12);   color: var(--text-dim);   border-color: var(--border-hi); }
.badge-closed   .status-dot { background: var(--text-dim); }

@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

/* Meta */
.card-meta {
    display: flex; flex-wrap: wrap;
    gap: 8px; margin-bottom: 18px;
}
.meta-item {
    display: inline-flex; align-items: center; gap: 7px;
    font-family: var(--mono); font-size: 11px; color: var(--text-sec);
    padding: 6px 11px;
    border-radius: 8px;
    background: rgba(79,110,247,.06);
    border: 1px solid rgba(79,110,247,.12);
}
.meta-item i { width: 14px; text-align: center; color: var(--accent-hi); font-size: 11px; opacity: .95; }

/* Countdown */
.countdown-wrap {
    background: linear-gradient(145deg, var(--panel-hi) 0%, rgba(79,110,247,.04) 100%);
    border: 1px solid var(--border-hi);
    border-radius: 12px; padding: 16px 18px; margin-bottom: 18px;
}
.countdown-header {
    display: flex; align-items: center; gap: 7px;
    font-family: var(--mono); font-size: 10px;
    color: var(--text-dim); letter-spacing: .12em;
    text-transform: uppercase; margin-bottom: 12px;
}
.countdown-header i { color: var(--accent); font-size: 10px; }
.countdown-timer { display: flex; align-items: center; gap: 8px; }

.c-unit {
    text-align: center;
    background: var(--bg); border: 1px solid var(--border-hi);
    border-radius: 10px; padding: 12px 8px 8px;
    min-width: 56px; flex: 1; max-width: 76px; position: relative; overflow: hidden;
}
.c-unit::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
    background: linear-gradient(90deg, transparent, rgba(79,110,247,.3), transparent);
}
.c-num {
    font-family: var(--mono); font-size: 26px; font-weight: 600;
    color: var(--text-pri); line-height: 1; display: block; letter-spacing: -.03em;
    font-variant-numeric: tabular-nums;
}
.c-lbl {
    font-family: var(--mono); font-size: 9px; color: var(--text-dim);
    letter-spacing: .1em; text-transform: uppercase; margin-top: 4px; display: block;
}
.c-sep { font-family: var(--mono); font-size: 18px; color: var(--text-dim); margin-bottom: 8px; flex-shrink: 0; }

.countdown-done {
    display: flex; align-items: center; gap: 8px;
    font-family: var(--mono); font-size: 12px; color: var(--success); padding: 4px 0;
}
.countdown-done.ended { color: var(--text-dim); }

/* Card footer */
.card-footer {
    display: flex; align-items: center;
    justify-content: space-between; gap: 12px;
    flex-wrap: wrap; padding-top: 16px;
    border-top: 1px solid var(--border);
}
.reg-status {
    display: flex; align-items: center; gap: 7px;
    font-family: var(--mono); font-size: 11px; color: var(--text-sec);
}
.reg-status.open   { color: var(--success); }
.reg-status.soon   { color: var(--gold); }
.reg-status.closed { color: var(--text-dim); }

.btn-register {
    display: inline-flex; align-items: center; gap: 9px;
    padding: 12px 22px;
    background: linear-gradient(135deg, var(--accent) 0%, #3d5ae8 100%); color: #fff;
    font-family: var(--sans); font-size: 12px; font-weight: 700;
    letter-spacing: .06em; text-transform: uppercase;
    border: none; border-radius: 10px; cursor: pointer;
    text-decoration: none;
    transition: background .2s, box-shadow .2s, transform .2s;
    box-shadow: 0 4px 20px rgba(79,110,247,.38), inset 0 1px 0 rgba(255,255,255,.12);
}
.btn-register:hover {
    background: linear-gradient(135deg, var(--accent-hi) 0%, var(--accent) 100%);
    box-shadow: 0 8px 28px rgba(79,110,247,.5), inset 0 1px 0 rgba(255,255,255,.15);
    transform: translateY(-2px); color: #fff;
}
.btn-register:active { transform: translateY(0); }
.btn-register:focus-visible {
    outline: 2px solid var(--accent-hi);
    outline-offset: 3px;
}

.btn-disabled {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 22px;
    background: rgba(69,77,102,.08); color: var(--text-dim);
    font-family: var(--sans); font-size: 12px; font-weight: 700;
    letter-spacing: .05em; text-transform: uppercase;
    border: 1px solid var(--border-hi); border-radius: 10px; cursor: not-allowed;
}

/* Empty state */
.empty-state {
    text-align: center; padding: 64px 28px 72px;
    background: linear-gradient(180deg, var(--panel) 0%, var(--panel-hi) 100%);
    border: 1px solid var(--border-hi); border-radius: 14px;
    position: relative; overflow: hidden;
}
.empty-state::before {
    content: ''; position: absolute; inset: 0;
    background: radial-gradient(ellipse at 50% 0%, rgba(79,110,247,.08) 0%, transparent 55%);
    pointer-events: none;
}
.empty-state .empty-icon-wrap {
    width: 88px; height: 88px; margin: 0 auto 20px;
    border-radius: 20px;
    display: grid; place-items: center;
    background: rgba(79,110,247,.08);
    border: 1px solid rgba(79,110,247,.2);
    position: relative; z-index: 1;
}
.empty-state .empty-icon-wrap i { font-size: 36px; color: var(--accent-hi); opacity: .85; }
.empty-state h3 { font-size: 21px; font-weight: 800; color: var(--text-pri); margin-bottom: 10px; position: relative; z-index: 1; letter-spacing: -.02em; }
.empty-state p  { font-family: var(--mono); font-size: 12px; color: var(--text-dim); line-height: 1.75; position: relative; z-index: 1; max-width: 400px; margin: 0 auto; }

/* Footer */
.page-footer {
    position: relative; z-index: 1;
    border-top: 1px solid var(--border-hi);
    background: linear-gradient(180deg, var(--panel-hi) 0%, var(--panel) 100%);
    padding: 28px 24px 32px; text-align: center;
    font-family: var(--mono); font-size: 11px; color: var(--text-dim);
    display: flex; flex-direction: column; align-items: center; gap: 10px;
}
.page-footer-icons {
    display: flex; align-items: center; justify-content: center; gap: 16px;
    margin-bottom: 4px; color: var(--text-dim); font-size: 13px;
}
.page-footer-icons i { opacity: .65; transition: opacity .2s, color .2s; }
.page-footer-icons i:hover { opacity: 1; color: var(--accent-hi); }
.page-footer-line { max-width: 480px; line-height: 1.65; }

/* Admin link */
.admin-link {
    position: fixed; bottom: 20px; right: 20px;
    background: var(--panel); color: var(--text-dim);
    font-family: var(--mono); font-size: 10px; font-weight: 500;
    padding: 7px 14px; border-radius: 20px; text-decoration: none;
    border: 1px solid var(--border-hi); z-index: 100;
    display: flex; align-items: center; gap: 6px;
    letter-spacing: .06em; text-transform: uppercase;
    transition: all .2s;
}
.admin-link:hover { color: var(--accent-hi); border-color: var(--accent); background: rgba(79,110,247,.06); }

@media (max-width: 640px) {
    .stats-strip-inner { grid-template-columns: 1fr; max-width: 320px; }
    .stat-chip { flex-direction: row; justify-content: space-between; }
    .stat-chip .stat-num { font-size: 22px; }
}

@media (max-width: 560px) {
    .hero { padding: 36px 16px 48px; }
    .hero-badge-row { margin-bottom: 18px; }
    .hero-logos img { height: 48px; }
    .hero-scroll-hint { margin-top: 22px; }
    .card-inner { padding: 18px 16px 16px; }
    .c-unit { min-width: 44px; }
    .c-num { font-size: 20px; }
    .card-footer { flex-direction: column; align-items: stretch; }
    .btn-register, .btn-disabled { justify-content: center; }
    .meta-item { font-size: 10px; padding: 5px 9px; }
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

<!-- HERO -->
<div class="hero">
    <div class="hero-lines"><span></span><span></span><span></span><span></span></div>
    <div class="hero-inner">
        <div class="hero-badge-row">
            <span class="hero-pill"><i class="fas fa-shield-halved"></i> Secure</span>
            <span class="hero-pill gold"><i class="fas fa-anchor"></i> Official</span>
            <span class="hero-pill"><i class="fas fa-calendar-check"></i> <?= date('Y') ?></span>
        </div>
        <div class="hero-logos">
            <img src="assets/img/bagong_pilipinas.png" alt="Bagong Pilipinas">
            <div class="logo-divider"></div>
            <img src="assets/img/pn_seal.png" alt="Philippine Navy">
        </div>
        <div class="hero-eyebrow">Official Event Portal</div>
        <h1 class="hero-title">Philippine Navy<br><span class="hero-gradient">Event Registration</span></h1>
        <p class="hero-subtitle">
            Office of the AC of NS for Naval Systems Engineering, N11<br>
            Naval Station Jose Andrada, 2335 Roxas Boulevard, Manila
        </p>
        <?php if (!empty($events)): ?>
        <div class="hero-scroll-hint"><span>View events</span><i class="fas fa-chevron-down"></i></div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($events)): ?>
<div class="stats-strip">
    <div class="stats-strip-inner">
        <div class="stat-chip">
            <span class="stat-num accent"><?= (int) $eventStats['ongoing'] ?></span>
            <span class="stat-label">Ongoing</span>
        </div>
        <div class="stat-chip">
            <span class="stat-num gold"><?= (int) $eventStats['upcoming'] ?></span>
            <span class="stat-label">Upcoming</span>
        </div>
        <div class="stat-chip">
            <span class="stat-num muted"><?= (int) $eventStats['closed'] ?></span>
            <span class="stat-label">Closed</span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- MAIN -->
<div class="main">

    <div class="section-head">
        <div class="section-label">
            <i class="fas fa-calendar-days"></i>
            <?= count($events) ?> Active Event<?= count($events) != 1 ? 's' : '' ?>
        </div>
        <?php if (!empty($events)): ?>
        <p class="section-lead">Select an event below to review details and register when registration is open.</p>
        <?php else: ?>
        <p class="section-lead">When new events are published, they will appear here for self-service registration.</p>
        <?php endif; ?>
    </div>

    <?php if (empty($events)): ?>
    <div class="empty-state">
        <div class="empty-icon-wrap"><i class="fas fa-calendar-xmark"></i></div>
        <h3>No Active Events</h3>
        <p>There are currently no open events for registration.<br>Please check back later or contact your administrator.</p>
    </div>

    <?php else: ?>
    <div class="events-list">
    <?php foreach ($events as $idx => $ev):
        $status    = getEventStatus($ev['start_date'], $ev['end_date']);
        $statusKey = $status['key'];
        $statusLbl = $status['label'];

        $startFmt = date('F d, Y', strtotime($ev['start_date']));
        $endFmt   = date('F d, Y', strtotime($ev['end_date']));
        $dateLine = ($startFmt === $endFmt) ? $startFmt : $startFmt . ' – ' . $endFmt;

        $countdownTarget = match($statusKey) {
            'upcoming' => $ev['start_date'] . 'T00:00:00',
            'ongoing'  => $ev['end_date']   . 'T23:59:59',
            default    => ''
        };
        $safeId = 'ev_' . $ev['id'];
    ?>
    <div class="event-card status-<?= $statusKey ?>">
        <div class="card-inner">

            <div class="card-top">
                <div class="agenda-wrap">
                    <div class="agenda-number">Event <?= str_pad($idx + 1, 2, '0', STR_PAD_LEFT) ?></div>
                    <div class="agenda-title"><?= htmlspecialchars($ev['agenda']) ?></div>
                </div>
                <span class="status-badge badge-<?= $statusKey ?>">
                    <span class="status-dot"></span><?= $statusLbl ?>
                </span>
            </div>

            <div class="card-meta">
                <div class="meta-item"><i class="fas fa-calendar-days"></i><?= htmlspecialchars($dateLine) ?></div>
                <div class="meta-item"><i class="fas fa-location-dot"></i><?= htmlspecialchars($ev['venue'] ?? 'TBA') ?></div>
                <?php if (!empty($ev['event_days'])): ?>
                <div class="meta-item"><i class="fas fa-clock"></i><?= (int)$ev['event_days'] ?> day<?= $ev['event_days'] != 1 ? 's' : '' ?></div>
                <?php endif; ?>
            </div>

            <?php if ($statusKey !== 'closed'): ?>
            <div class="countdown-wrap">
                <div class="countdown-header">
                    <i class="fas fa-hourglass-half"></i>
                    <?= $statusKey === 'upcoming' ? 'Registration opens in' : 'Event ends in' ?>
                </div>
                <div class="countdown-timer" id="timer_<?= $safeId ?>">
                    <div class="c-unit"><span class="c-num" id="<?= $safeId ?>_d">--</span><span class="c-lbl">Days</span></div>
                    <span class="c-sep">:</span>
                    <div class="c-unit"><span class="c-num" id="<?= $safeId ?>_h">--</span><span class="c-lbl">Hrs</span></div>
                    <span class="c-sep">:</span>
                    <div class="c-unit"><span class="c-num" id="<?= $safeId ?>_m">--</span><span class="c-lbl">Min</span></div>
                    <span class="c-sep">:</span>
                    <div class="c-unit"><span class="c-num" id="<?= $safeId ?>_s">--</span><span class="c-lbl">Sec</span></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card-footer">
                <?php if ($statusKey === 'ongoing'): ?>
                    <div class="reg-status open"><i class="fas fa-circle-check"></i> Registration is open</div>
                    <a href="register.php?event_id=<?= $ev['id'] ?>" class="btn-register">
                        <i class="fas fa-pen-to-square"></i> Register Now
                    </a>
                <?php elseif ($statusKey === 'upcoming'): ?>
                    <div class="reg-status soon"><i class="fas fa-calendar-clock"></i> Opens <?= $startFmt ?></div>
                    <span class="btn-disabled"><i class="fas fa-lock"></i> Not Yet Open</span>
                <?php else: ?>
                    <div class="reg-status closed"><i class="fas fa-ban"></i> Registration closed</div>
                    <span class="btn-disabled"><i class="fas fa-lock"></i> Closed</span>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <?php if ($countdownTarget): ?>
    <script>
    (function(){
        var target     = new Date("<?= $countdownTarget ?>").getTime();
        var prefix     = "<?= $safeId ?>";
        var isUpcoming = <?= $statusKey === 'upcoming' ? 'true' : 'false' ?>;
        var pad = function(n){ return n < 10 ? '0'+n : ''+n; };
        var t = setInterval(function(){
            var diff = target - Date.now();
            if(diff <= 0){
                clearInterval(t);
                var wrap = document.getElementById('timer_'+prefix);
                if(wrap){
                    var cls = isUpcoming ? 'countdown-done' : 'countdown-done ended';
                    var msg = isUpcoming
                        ? '<i class="fas fa-circle-check"></i> Registration is now open — refresh to register'
                        : '<i class="fas fa-clock"></i> This event has ended';
                    wrap.innerHTML = '<div class="'+cls+'">'+msg+'</div>';
                }
                return;
            }
            var d = Math.floor(diff/86400000);
            var h = Math.floor((diff%86400000)/3600000);
            var m = Math.floor((diff%3600000)/60000);
            var s = Math.floor((diff%60000)/1000);
            document.getElementById(prefix+'_d').textContent = d;
            document.getElementById(prefix+'_h').textContent = pad(h);
            document.getElementById(prefix+'_m').textContent = pad(m);
            document.getElementById(prefix+'_s').textContent = pad(s);
        }, 1000);
    })();
    </script>
    <?php endif; ?>

    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<!-- FOOTER -->
<div class="page-footer">
    <div class="page-footer-icons" aria-hidden="true">
        <i class="fas fa-anchor" title=""></i>
        <i class="fas fa-shield-halved" title=""></i>
        <i class="fas fa-calendar-check" title=""></i>
    </div>
    <div class="page-footer-line"><strong style="color:var(--text-sec)">Philippine Navy</strong> &nbsp;·&nbsp; Naval Station Jose Andrada, 2335 Roxas Boulevard, Manila</div>
    <div class="page-footer-line">Official event portal &nbsp;·&nbsp; Secure registration &nbsp;·&nbsp; <?= date('Y') ?></div>
</div>

<a href="admin/login.php" class="admin-link"><i class="fas fa-lock"></i> Admin</a>

<script src="js/theme-toggle-public.js"></script>
</body>
</html>