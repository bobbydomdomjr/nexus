<?php
require 'config/db.php';

$stmt = $pdo->query("
    SELECT id, agenda, venue, start_date, end_date, event_days
    FROM event_settings
    WHERE active = 1
    ORDER BY start_date ASC
");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getEventStatus(string $start, string $end): array {
    $now = new DateTime();
    $s   = new DateTime($start);
    $e   = new DateTime($end);
    if ($now < $s)                return ['label' => 'Upcoming', 'key' => 'upcoming'];
    if ($now >= $s && $now <= $e) return ['label' => 'Ongoing',  'key' => 'ongoing'];
    return                               ['label' => 'Closed',   'key' => 'closed'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PN Event Registration</title>

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
    padding: 56px 24px 60px;
    text-align: center;
    border-bottom: 1px solid var(--border-hi);
    background: var(--panel);
    overflow: hidden;
}
.hero::before {
    content: '';
    position: absolute; inset: 0;
    background:
        radial-gradient(ellipse at 20% 60%, rgba(79,110,247,.13) 0%, transparent 55%),
        radial-gradient(ellipse at 80% 20%, rgba(240,192,96,.07) 0%, transparent 45%);
    pointer-events: none;
}
.hero::after {
    content: '';
    position: absolute;
    bottom: 0; left: 15%; right: 15%; height: 1px;
    background: linear-gradient(90deg, transparent, var(--gold), transparent);
    opacity: .3;
}
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
    gap: 18px; margin-bottom: 28px;
}
.hero-logos img { height: 60px; filter: drop-shadow(0 2px 12px rgba(0,0,0,.5)); }
.logo-divider { width: 1px; height: 44px; background: var(--border-hi); }

.hero-eyebrow {
    font-family: var(--mono); font-size: 10px; font-weight: 500;
    letter-spacing: .2em; text-transform: uppercase;
    color: var(--gold); margin-bottom: 14px; opacity: .85;
}
.hero-title {
    font-size: clamp(26px, 4vw, 38px); font-weight: 800;
    letter-spacing: -.03em; color: var(--text-pri);
    line-height: 1.15; margin-bottom: 12px;
}
.hero-subtitle {
    font-family: var(--mono); font-size: 12px;
    color: var(--text-sec); line-height: 1.7;
}

/* ── MAIN ── */
.main {
    position: relative; z-index: 1;
    max-width: 860px; margin: 0 auto;
    padding: 40px 20px 80px;
}

.section-label {
    display: flex; align-items: center; gap: 10px;
    font-family: var(--mono); font-size: 10px; font-weight: 500;
    letter-spacing: .16em; text-transform: uppercase;
    color: var(--text-dim); margin-bottom: 20px;
}
.section-label::after { content: ''; flex: 1; height: 1px; background: var(--border-hi); }
.section-label i { color: var(--accent); font-size: 11px; }

/* ── EVENT CARDS ── */
.events-list { display: flex; flex-direction: column; gap: 14px; }

.event-card {
    background: var(--panel);
    border: 1px solid var(--border-hi);
    border-radius: var(--radius);
    overflow: hidden; position: relative;
    transition: border-color .2s, box-shadow .2s, transform .2s;
    animation: fadeUp .45s cubic-bezier(.22,1,.36,1) both;
}
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
    border-color: var(--accent);
    box-shadow: 0 0 0 1px var(--accent), 0 12px 40px rgba(79,110,247,.1);
    transform: translateY(-2px);
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

.card-inner { padding: 22px 24px 20px; position: relative; z-index: 1; }

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
    font-size: 17px; font-weight: 700;
    color: var(--text-pri); line-height: 1.3; letter-spacing: -.01em;
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
    gap: 6px 22px; margin-bottom: 18px;
}
.meta-item {
    display: flex; align-items: center; gap: 7px;
    font-family: var(--mono); font-size: 11px; color: var(--text-sec);
}
.meta-item i { width: 13px; text-align: center; color: var(--accent); font-size: 11px; }

/* Countdown */
.countdown-wrap {
    background: var(--panel-hi);
    border: 1px solid var(--border-hi);
    border-radius: 10px; padding: 14px 16px; margin-bottom: 18px;
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
    border-radius: 8px; padding: 10px 8px 7px;
    min-width: 56px; flex: 1; max-width: 72px; position: relative; overflow: hidden;
}
.c-unit::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
    background: linear-gradient(90deg, transparent, rgba(79,110,247,.3), transparent);
}
.c-num {
    font-family: var(--mono); font-size: 24px; font-weight: 500;
    color: var(--text-pri); line-height: 1; display: block; letter-spacing: -.02em;
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
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 20px;
    background: var(--accent); color: #fff;
    font-family: var(--sans); font-size: 12px; font-weight: 700;
    letter-spacing: .05em; text-transform: uppercase;
    border: none; border-radius: 8px; cursor: pointer;
    text-decoration: none;
    transition: background .15s, box-shadow .15s, transform .15s;
    box-shadow: 0 4px 16px rgba(79,110,247,.3);
}
.btn-register:hover {
    background: var(--accent-hi);
    box-shadow: 0 6px 24px rgba(79,110,247,.45);
    transform: translateY(-1px); color: #fff;
}
.btn-register:active { transform: none; }

.btn-disabled {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 20px;
    background: transparent; color: var(--text-dim);
    font-family: var(--sans); font-size: 12px; font-weight: 700;
    letter-spacing: .05em; text-transform: uppercase;
    border: 1px solid var(--border-hi); border-radius: 8px; cursor: not-allowed;
}

/* Empty state */
.empty-state {
    text-align: center; padding: 72px 24px;
    background: var(--panel); border: 1px solid var(--border-hi); border-radius: var(--radius);
}
.empty-state i { font-size: 44px; color: var(--text-dim); margin-bottom: 16px; display: block; }
.empty-state h3 { font-size: 20px; font-weight: 700; color: var(--text-sec); margin-bottom: 8px; }
.empty-state p  { font-family: var(--mono); font-size: 12px; color: var(--text-dim); line-height: 1.7; }

/* Footer */
.page-footer {
    position: relative; z-index: 1;
    border-top: 1px solid var(--border-hi);
    background: var(--panel);
    padding: 20px 24px; text-align: center;
    font-family: var(--mono); font-size: 11px; color: var(--text-dim);
    display: flex; flex-direction: column; align-items: center; gap: 5px;
}

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

@media (max-width: 560px) {
    .hero { padding: 40px 16px 44px; }
    .hero-logos img { height: 48px; }
    .card-inner { padding: 18px 16px 16px; }
    .c-unit { min-width: 44px; }
    .c-num { font-size: 19px; }
    .card-footer { flex-direction: column; align-items: stretch; }
    .btn-register, .btn-disabled { justify-content: center; }
}
</style>
</head>
<body>

<!-- HERO -->
<div class="hero">
    <div class="hero-lines"><span></span><span></span><span></span><span></span></div>
    <div class="hero-inner">
        <div class="hero-logos">
            <img src="assets/img/bagong_pilipinas.png" alt="Bagong Pilipinas">
            <div class="logo-divider"></div>
            <img src="assets/img/pn_seal.png" alt="Philippine Navy">
        </div>
        <div class="hero-eyebrow">Official Event Portal</div>
        <h1 class="hero-title">Philippine Navy<br>Event Registration</h1>
        <p class="hero-subtitle">
            Office of the AC of NS for Naval Systems Engineering, N11<br>
            Naval Station Jose Andrada, 2335 Roxas Boulevard, Manila
        </p>
    </div>
</div>

<!-- MAIN -->
<div class="main">

    <div class="section-label">
        <i class="fas fa-calendar-days"></i>
        <?= count($events) ?> Active Event<?= count($events) != 1 ? 's' : '' ?>
    </div>

    <?php if (empty($events)): ?>
    <div class="empty-state">
        <i class="fas fa-calendar-xmark"></i>
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
    <div><strong style="color:var(--text-sec)">Philippine Navy</strong> &nbsp;·&nbsp; Naval Station Jose Andrada, 2335 Roxas Boulevard, Manila</div>
    <div>Official event portal &nbsp;·&nbsp; Secure registration &nbsp;·&nbsp; <?= date('Y') ?></div>
</div>

<a href="admin/login.php" class="admin-link"><i class="fas fa-lock"></i> Admin</a>

</body>
</html>