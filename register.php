<?php
session_start();
require 'config/db.php';

/* ══ FETCH ACTIVE EVENT ══
   Support event_id from landing page OR fall back to any ongoing event
*/
$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);

if ($eventId) {
    $stmt = $pdo->prepare("
        SELECT id, agenda, venue, start_date, end_date, event_days
        FROM event_settings
        WHERE id = ? AND active = 1
    ");
    $stmt->execute([$eventId]);
} else {
    $stmt = $pdo->prepare("
        SELECT id, agenda, venue, start_date, end_date, event_days
        FROM event_settings
        WHERE start_date <= CURDATE() AND end_date >= CURDATE() AND active = 1
        ORDER BY start_date ASC LIMIT 1
    ");
    $stmt->execute();
}

$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    header("Location: home.php");
    exit;
}

$agenda    = $settings['agenda'];
$venue     = $settings['venue'];
$totalDays = (int)$settings['event_days'];
$startDate = date('F d, Y', strtotime($settings['start_date']));
$endDate   = date('F d, Y', strtotime($settings['end_date']));
$event_date = ($startDate === $endDate) ? $startDate : $startDate . ' – ' . $endDate;

/* ══ FORM SUBMISSION ══ */
$success      = false;
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $sql = "
            INSERT INTO event_registrations (
                agenda, start_date, end_date, event_day, venue,
                last_name, first_name, middle_name, middle_initial, ext_name,
                unit_office, rank, major_service, serial_number, designation,
                email, contact_number, agreed_terms
            ) VALUES (
                :agenda, :start_date, :end_date, :event_day, :venue,
                :last_name, :first_name, :middle_name, :middle_initial, :ext_name,
                :unit_office, :rank, :major_service, :serial_number, :designation,
                :email, :contact_number, :agreed_terms
            )";

        $stmt2 = $pdo->prepare($sql);
        $stmt2->execute([
            ':agenda'         => $_POST['agenda']         ?? $agenda,
            ':start_date'     => $_POST['start_date']     ?? $settings['start_date'],
            ':end_date'       => $_POST['end_date']        ?? $settings['end_date'],
            ':event_day'      => $_POST['event_day']      ?? null,
            ':venue'          => $_POST['venue']           ?? $venue,
            ':last_name'      => trim($_POST['last_name']      ?? ''),
            ':first_name'     => trim($_POST['first_name']     ?? ''),
            ':middle_name'    => trim($_POST['middle_name']    ?? ''),
            ':middle_initial' => trim($_POST['middle_initial'] ?? ''),
            ':ext_name'       => $_POST['ext_name']        ?? '',
            ':unit_office'    => $_POST['unit_office']     ?? '',
            ':rank'           => $_POST['rank']            ?? '',
            ':major_service'  => $_POST['major_service']   ?? '',
            ':serial_number'  => trim($_POST['serial_number']  ?? ''),
            ':designation'    => trim($_POST['designation']    ?? ''),
            ':email'          => trim($_POST['email']          ?? ''),
            ':contact_number' => trim($_POST['contact_number'] ?? ''),
            ':agreed_terms'   => isset($_POST['agreed_terms']) ? 1 : 0,
        ]);
        $success = true;
    } catch (PDOException $e) {
        $errorMessage = "There was an error saving your registration. Please try again.";
    }
}

$stmtCount = $pdo->prepare("
    SELECT COUNT(*) 
    FROM event_registrations
    WHERE agenda = ?
    AND start_date = CURDATE()
");
$stmtCount->execute([$agenda]);


$total = $stmtCount->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Event Registration</title>
<script>(function(){try{var d=localStorage.getItem('nx_dark');if(d==='false')document.documentElement.classList.add('light');}catch(e){}})();</script>
<link rel="stylesheet" href="css/public-theme.css">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* ══ VARIABLES ══ */
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
    --radius:      10px;
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

/* ══ HERO HEADER ══ */
.hero {
    position: relative; z-index: 1;
    background: var(--panel);
    border-bottom: 1px solid var(--border-hi);
    padding: 32px 24px 36px;
    text-align: center;
    overflow: hidden;
}
.hero::before {
    content: ''; position: absolute; inset: 0;
    background:
        radial-gradient(ellipse at 20% 60%, rgba(79,110,247,.1) 0%, transparent 55%),
        radial-gradient(ellipse at 80% 20%, rgba(240,192,96,.06) 0%, transparent 45%);
    pointer-events: none;
}
.hero::after {
    content: ''; position: absolute;
    bottom: 0; left: 15%; right: 15%; height: 1px;
    background: linear-gradient(90deg, transparent, var(--gold), transparent);
    opacity: .3;
}
.hero-inner { position: relative; z-index: 1; max-width: 640px; margin: 0 auto; }
.hero-logos { display: flex; align-items: center; justify-content: center; gap: 18px; margin-bottom: 20px; }
.hero-logos img { height: 54px; filter: drop-shadow(0 2px 10px rgba(0,0,0,.5)); }
.logo-div { width: 1px; height: 40px; background: var(--border-hi); }
.hero-eyebrow {
    font-family: var(--mono); font-size: 10px; letter-spacing: .2em;
    text-transform: uppercase; color: var(--gold); margin-bottom: 10px; opacity: .85;
}
.hero-title { font-size: clamp(18px, 3vw, 26px); font-weight: 800; letter-spacing: -.02em; line-height: 1.2; margin-bottom: 6px; }
.hero-sub { font-family: var(--mono); font-size: 11px; color: var(--text-sec); line-height: 1.7; }

/* ══ PAGE WRAPPER ══ */
.page-wrap {
    position: relative; z-index: 1;
    max-width: 960px; margin: 0 auto;
    padding: 32px 20px 64px;
}

/* ══ EVENT BANNER ══ */
.event-banner {
    background: var(--panel);
    border: 1px solid var(--border-hi);
    border-radius: var(--radius);
    padding: 18px 22px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    position: relative;
    overflow: hidden;
}
.event-banner::before {
    content: ''; position: absolute;
    top: 0; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, var(--accent), var(--accent-hi));
}
.event-banner-icon {
    width: 42px; height: 42px; border-radius: 10px;
    background: rgba(79,110,247,.12); color: var(--accent-hi);
    display: grid; place-items: center; font-size: 16px; flex-shrink: 0;
}
.event-banner-body { flex: 1; min-width: 0; }
.event-banner-label {
    font-family: var(--mono); font-size: 10px; color: var(--text-dim);
    letter-spacing: .12em; text-transform: uppercase; margin-bottom: 3px;
}
.event-banner-title { font-size: 15px; font-weight: 700; color: var(--text-pri); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.event-banner-meta {
    display: flex; flex-wrap: wrap; gap: 4px 16px; margin-top: 6px;
}
.event-meta-item {
    display: flex; align-items: center; gap: 6px;
    font-family: var(--mono); font-size: 11px; color: var(--text-sec);
}
.event-meta-item i { color: var(--accent); font-size: 10px; width: 12px; text-align: center; }

.event-banner-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 11px; border-radius: 20px;
    font-family: var(--mono); font-size: 10px; font-weight: 500;
    letter-spacing: .08em; text-transform: uppercase;
    background: rgba(52,211,153,.08); color: var(--success);
    border: 1px solid rgba(52,211,153,.2); flex-shrink: 0;
}
.event-banner-badge .dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--success); animation: blink 2s ease infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ══ FORM SECTIONS ══ */
.form-section {
    background: var(--panel);
    border: 1px solid var(--border-hi);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 16px;
}
.section-header {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 22px;
    border-bottom: 1px solid var(--border);
    background: var(--panel-hi);
}
.section-header-icon {
    width: 28px; height: 28px; border-radius: 7px;
    background: rgba(79,110,247,.12); color: var(--accent-hi);
    display: grid; place-items: center; font-size: 12px; flex-shrink: 0;
}
.section-header-title { font-size: 13px; font-weight: 700; color: var(--text-pri); letter-spacing: .01em; }
.section-header-sub { font-family: var(--mono); font-size: 10px; color: var(--text-dim); margin-left: auto; }

.section-body { padding: 22px; }

/* ══ FORM FIELDS ══ */
.form-row { display: grid; gap: 16px; margin-bottom: 16px; }
.form-row:last-child { margin-bottom: 0; }
.col-1 { grid-template-columns: 1fr; }
.col-2 { grid-template-columns: 1fr 1fr; }
.col-3 { grid-template-columns: 1fr 1fr 1fr; }
.col-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }
.col-2-1 { grid-template-columns: 2fr 1fr; }
.col-1-2 { grid-template-columns: 1fr 2fr; }
.col-3-1-1 { grid-template-columns: 3fr 1fr 1fr; }
.col-2-2-1 { grid-template-columns: 1fr 1fr 1fr 1fr 0.5fr; }

.field { display: flex; flex-direction: column; gap: 6px; }

.field label {
    font-family: var(--mono); font-size: 10px;
    color: var(--text-dim); text-transform: uppercase; letter-spacing: .1em;
}
.field label .req { color: var(--danger); margin-left: 2px; }

.field input,
.field select,
.field textarea {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border-hi);
    border-radius: 8px;
    padding: 10px 13px;
    font-family: var(--mono); font-size: 12px;
    color: var(--text-pri);
    outline: none;
    transition: border-color .2s, box-shadow .2s;
    appearance: none;
}
.field input::placeholder { color: var(--text-dim); }
.field input:focus,
.field select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-glow);
}
.field input[readonly],
.field input[readonly]:focus {
    color: var(--text-sec);
    border-color: var(--border);
    box-shadow: none;
    cursor: default;
}
.field select option { background: var(--panel); color: var(--text-pri); }

/* Validation states */
.field input.valid,
.field select.valid   { border-color: var(--success); }
.field input.invalid,
.field select.invalid { border-color: var(--danger); }

.field .hint {
    font-family: var(--mono); font-size: 10px;
    color: var(--text-dim); margin-top: 2px;
}

/* Custom select arrow */
.field select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23454d66' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 34px;
}

/* ══ TERMS & SUBMIT ══ */
.terms-row {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 18px 22px;
    border-top: 1px solid var(--border);
    background: var(--panel-hi);
}
.custom-checkbox { position: relative; width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
.custom-checkbox input { position: absolute; opacity: 0; width: 0; height: 0; }
.custom-checkbox .check-box {
    position: absolute; inset: 0;
    background: var(--bg); border: 1px solid var(--border-hi);
    border-radius: 4px; cursor: pointer;
    transition: background .15s, border-color .15s;
    display: grid; place-items: center;
}
.custom-checkbox input:checked + .check-box {
    background: var(--accent); border-color: var(--accent);
}
.custom-checkbox .check-box::after {
    content: ''; width: 5px; height: 9px;
    border: 2px solid white; border-top: none; border-left: none;
    transform: rotate(45deg) translate(-1px, -1px);
    display: none;
}
.custom-checkbox input:checked + .check-box::after { display: block; }

.terms-text {
    font-family: var(--mono); font-size: 11px;
    color: var(--text-sec); line-height: 1.6; flex: 1;
}
.terms-text strong { color: var(--text-pri); }

/* Submit button */
.submit-wrap { padding: 0 22px 22px; }
.btn-submit {
    width: 100%; padding: 13px;
    background: var(--accent); color: #fff;
    font-family: var(--sans); font-size: 13px; font-weight: 700;
    letter-spacing: .06em; text-transform: uppercase;
    border: none; border-radius: 8px; cursor: pointer;
    transition: background .15s, box-shadow .15s, transform .15s;
    box-shadow: 0 4px 16px rgba(79,110,247,.3);
    display: flex; align-items: center; justify-content: center; gap: 9px;
}
.btn-submit:hover:not(:disabled) {
    background: var(--accent-hi);
    box-shadow: 0 6px 24px rgba(79,110,247,.45);
    transform: translateY(-1px);
}
.btn-submit:disabled {
    opacity: .45; cursor: not-allowed;
    box-shadow: none; transform: none;
}

/* ══ FOOTER ══ */
.page-footer {
    position: relative; z-index: 1;
    border-top: 1px solid var(--border-hi);
    background: var(--panel);
    padding: 18px 24px;
    text-align: center;
    font-family: var(--mono); font-size: 11px; color: var(--text-dim);
}

/* ══ SWEETALERT OVERRIDE ══ */
.swal2-popup {
    background: var(--panel) !important;
    color: var(--text-pri) !important;
    border: 1px solid var(--border-hi) !important;
    font-family: var(--sans) !important;
}
.swal2-title { color: var(--text-pri) !important; }
.swal2-html-container { color: var(--text-sec) !important; font-family: var(--mono) !important; font-size: 13px !important; }
.swal2-confirm { background: var(--accent) !important; font-family: var(--sans) !important; font-weight: 700 !important; }
.swal2-icon.swal2-success { border-color: var(--success) !important; }
.swal2-icon.swal2-success [class^=swal2-success-line] { background: var(--success) !important; }
.swal2-icon.swal2-warning { border-color: var(--warning) !important; color: var(--warning) !important; }
.swal2-icon.swal2-error { border-color: var(--danger) !important; color: var(--danger) !important; }

/* ══ RESPONSIVE ══ */
@media (max-width: 720px) {
    .col-2, .col-3, .col-4, .col-2-1, .col-1-2, .col-3-1-1, .col-2-2-1 {
        grid-template-columns: 1fr;
    }
    .col-2 { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 480px) {
    .col-2 { grid-template-columns: 1fr; }
    .hero { padding: 24px 16px 28px; }
    .hero-logos img { height: 44px; }
}
/* FIXED HEADER */
.top-header {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    z-index: 999;

    background: var(--panel);
    border-bottom: 1px solid var(--border-hi);
    backdrop-filter: blur(8px);

    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 10px 20px;
}

/* LEFT SIDE */
.top-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}

.top-header-left .title {
    font-size: 12px;
    font-weight: 600;
}

.top-header-left .subtitle {
    font-size: 10px;
    color: var(--text-dim);
    font-family: var(--mono);
}

/* RIGHT SIDE */
.top-header-right {
    display: flex;
    align-items: center;
}

/* REMOVE extra spacing from toggle */
.theme-toggle-wrap {
    margin: 0;
}

/* PUSH CONTENT DOWN */
body {
    padding-top: 70px;
}
</style>
</head>
<body>

<div class="top-header">

    <!-- LEFT: Branding -->
    <div class="top-header-left">
        <a href="home.php"><img src="assets/img/pn_seal.png" style="height:32px;"></a>
        <div>
            <div class="title">Philippine Navy Registration</div>
            <div class="subtitle">Office of Naval Systems Engineering (N11)</div>
        </div>
    </div>

    <!-- RIGHT: Theme Toggle -->
    <div class="top-header-right">
        <div class="theme-toggle-wrap">
            <div class="theme-toggle-hints" aria-hidden="true">
        <span class="theme-hint-day">Day</span>
        <span class="theme-hint-night">Night</span>
    </div>
            <button type="button" class="theme-toggle" id="theme-toggle-btn"
                role="switch"
                onclick="nexusPublicThemeToggle()"
                aria-checked="true"
                aria-label="Theme">
                <span class="theme-toggle-track">
                    <span class="theme-toggle-thumb">
                        <i class="fas fa-moon fa-fw" data-theme-icon></i>
                    </span>
                </span>
            </button>
        </div>
    </div>

</div>
<!-- ══ MAIN ══ -->
<div class="page-wrap">

    <!-- Event Banner -->
    <div class="event-banner">
        <div class="event-banner-icon"><i class="fas fa-calendar-days"></i></div>
        <div class="event-banner-body">
            <div class="event-banner-label">Currently Registering For</div>
            <div class="event-banner-title"><?= htmlspecialchars($agenda) ?></div>
            <div class="event-banner-meta">
                <div class="event-meta-item"><i class="fas fa-calendar"></i><?= htmlspecialchars($event_date) ?></div>
                <div class="event-meta-item"><i class="fas fa-location-dot"></i><?= htmlspecialchars($venue) ?></div>
                <div class="event-meta-item"><i class="fas fa-clock"></i><?= $totalDays ?> day<?= $totalDays != 1 ? 's' : '' ?></div>
                <div class="event-meta-item"><i class="fas fa-users"></i><?= $total ?> attendees today</div>
            </div>
        </div>
        <div class="event-banner-badge"><span class="dot"></span> Registration Open</div>
    </div>

    <form id="registrationForm" method="POST">
        <input type="hidden" name="agenda"     value="<?= htmlspecialchars($agenda) ?>">
        <input type="hidden" name="venue"      value="<?= htmlspecialchars($venue) ?>">
        <input type="hidden" name="start_date" id="start_date" value="<?= htmlspecialchars($settings['start_date']) ?>">
        <input type="hidden" name="end_date"   id="end_date"   value="<?= htmlspecialchars($settings['end_date']) ?>">

        <!-- ══ EVENT DETAILS ══ -->
        <div class="form-section">
            <div class="section-header">
                <div class="section-header-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="section-header-title">Event Details</div>
                <div class="section-header-sub">Select your attendance day</div>
            </div>
            <div class="section-body">
                <div class="form-row col-3">
                    <div class="field">
                        <label>Agenda</label>
                        <input type="text" value="<?= htmlspecialchars($agenda) ?>" readonly>
                    </div>
                    <div class="field">
                        <label>Day of Attendance <span class="req">*</span></label>
                        <select name="event_day" id="event_day" required>
                            <option value="">— Select Day —</option>
                            <?php
                            $start = new DateTime($settings['start_date']);
                            $today = new DateTime(); $today->setTime(0,0,0);
                            for ($i = 1; $i <= $totalDays; $i++):
                                $dayDate = clone $start;
                                $dayDate->modify('+' . ($i - 1) . ' days');
                                $dayDate->setTime(0,0,0);
                                $isToday  = ($dayDate == $today);
                                $disabled = !$isToday ? 'disabled' : '';
                                $selected = $isToday ? 'selected' : '';
                            ?>
                            <option value="<?= str_pad($i, 2, '0', STR_PAD_LEFT) ?>"
                            data-date="<?= $dayDate->format('Y-m-d') ?>"
                            <?= $disabled ?>
                            <?= $selected ?>>
                            Day <?= str_pad($i, 2, '0', STR_PAD_LEFT) ?> — <?= $dayDate->format('M d, Y') ?>
                            <?= $disabled ? ' (Closed)' : ' (Today)' ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                        <div class="hint">Select your attendance date</div>
                    </div>
                    <div class="field">
                        <label>Selected Date</label>
                        <input type="text" id="eventDateDisplay" placeholder="Auto-filled on selection" readonly>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ PERSONAL INFORMATION ══ -->
        <div class="form-section">
            <div class="section-header">
                <div class="section-header-icon"><i class="fas fa-user"></i></div>
                <div class="section-header-title">Personal Information</div>
                <div class="section-header-sub">Fields marked <span style="color:var(--danger)">*</span> are required</div>
            </div>
            <div class="section-body">

                <!-- Name row -->
                <div class="form-row col-2-2-1">
                    <div class="field">
                        <label>Last Name <span class="req">*</span></label>
                        <input type="text" name="last_name" placeholder="e.g. Dela Cruz" required>
                    </div>
                    <div class="field">
                        <label>First Name <span class="req">*</span></label>
                        <input type="text" name="first_name" placeholder="e.g. Juan Antonio" required>
                    </div>
                    <div class="field">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" placeholder="e.g. San Juan">
                        <div class="hint">Leave blank if none</div>
                    </div>
                    <div class="field">
                        <label>Middle Initial <span class="req">*</span></label>
                        <input type="text" name="middle_initial" placeholder="e.g. SJ" maxlength="2">
                    </div>
                    <div class="field">
                        <label>Ext. Name</label>
                        <select name="ext_name">
                            <option value="">—</option>
                            <option value="Jr">Jr</option>
                            <option value="Sr">Sr</option>
                            <option value="II">II</option>
                            <option value="III">III</option>
                        </select>
                        <div class="hint">If any</div>
                    </div>
                </div>

                <!-- Service row -->
                <div class="form-row col-3">
                    <div class="field">
                        <label>Rank <span class="req">*</span></label>
                        <select name="rank" required>
                            <option value="">— Select Rank —</option>
                            <optgroup label="Officer">
                                <option value="ADM">O-10 Admiral</option>
                                <option value="VADM">O-9 Vice Admiral</option>
                                <option value="RADM">O-8 Rear Admiral</option>
                                <option value="CAPT">O-6 Captain</option>
                                <option value="CDR">O-5 Commander</option>
                                <option value="LCDR">O-4 Lieutenant Commander</option>
                                <option value="LT">O-3 Lieutenant</option>
                                <option value="LTJG">O-2 Lieutenant Junior Grade</option>
                                <option value="ENS">O-1 Ensign</option>
                            </optgroup>
                            <optgroup label="Other">
                                <option value="CPO">CPO</option>
                                <option value="PO1">PO1</option>
                                <option value="PO2">PO2</option>
                                <option value="PO3">PO3</option>
                                <option value="SN1">SN1</option>
                                <option value="SN2">SN2</option>
                                <option value="ASN">ASN</option>
                                <option value="Mr">Mr</option>
                                <option value="Ms">Ms</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="field">
                        <label>Major Service <span class="req">*</span></label>
                        <select name="major_service" required>
                            <option value="">— Select Service —</option>
                            <option value="PN">Philippine Navy (PN)</option>
                            <option value="GSC">General Staff Corps (GSC)</option>
                            <option value="AFP">Armed Forces of the Philippines (AFP)</option>
                            <option value="CivHR">Civilian Human Resource (CivHR)</option>
                        </select>
                    </div>
                    <div class="field">
                    <label>Unit / Office <span class="req">*</span></label>
                    <input 
                        type="text" 
                        name="unit_office" 
                        class="form-control"
                        placeholder="e.g. O/N11" 
                        required
                    >
                </div>
                </div>

                <!-- Details row -->
                <div class="form-row col-4">
                    <div class="field">
                        <label>Serial Number <span class="req">*</span></label>
                        <input type="text" name="serial_number" placeholder="e.g. O-123456" required>
                    </div>
                    <div class="field">
                        <label>Designation <span class="req">*</span></label>
                        <input type="text" name="designation" placeholder="e.g. Engineering Officer" required>
                    </div>
                    <div class="field">
                        <label>Email Address <span class="req">*</span></label>
                        <input type="email" name="email" placeholder="e.g. juan@navy.mil.ph" required>
                    </div>
<div class="field">
    <label>Contact Number <span class="req">*</span></label>
    <input 
        type="text" 
        name="contact_number" 
        id="contact_number"
        placeholder="09123456789"
        maxlength="11"
        required
    >
</div>

<script>
const input = document.getElementById('contact_number');

input.addEventListener('input', function () {
    // Remove non-digits
    this.value = this.value.replace(/\D/g, '');

    // Force prefix 09
    if (!this.value.startsWith('09')) {
        this.value = '09' + this.value.replace(/^0+/, '').slice(0, 9);
    }

    // Limit to 11 digits
    this.value = this.value.slice(0, 11);
});
</script>
                </div>

            </div><!-- /section-body -->

            <!-- Terms -->
            <div class="terms-row">
                <label class="custom-checkbox">
                    <input type="checkbox" id="agreeCheck" name="agreed_terms" value="1" required>
                    <span class="check-box"></span>
                </label>
                <div class="terms-text">
                    By checking this box and clicking <strong>Submit Registration</strong>, I confirm that all information
                    provided is accurate and complete. I understand this constitutes an official registration record.
                </div>
            </div>

            <!-- Submit -->
            <div class="submit-wrap" style="padding-top:16px">
                <button type="submit" class="btn-submit" id="submitBtn" disabled>
                    <i class="fas fa-paper-plane"></i> Submit Registration
                </button>
            </div>

        </div><!-- /form-section -->
    </form>

</div><!-- /page-wrap -->

<!-- ══ FOOTER ══ -->
<div class="page-footer">
    © O/N11 &nbsp;·&nbsp; Philippine Navy &nbsp;·&nbsp; All rights reserved &nbsp;·&nbsp; <?= date('Y') ?>
</div>

<!-- ══ SUCCESS / ERROR ══ -->
<script>
<?php if ($success): ?>
Swal.fire({
    icon: 'success',
    title: 'Registration Successful',
    html: 'Your registration has been saved.<br><span style="font-family:\'DM Mono\',monospace;font-size:12px;color:#8890aa">You may now close this page.</span>',
    confirmButtonText: 'Register Another',
    confirmButtonColor: '#4f6ef7'
}).then(() => { window.location.href = 'register.php?event_id=<?= $settings['id'] ?>'; });
<?php elseif (!empty($errorMessage)): ?>
Swal.fire({
    icon: 'error',
    title: 'Submission Failed',
    text: '<?= addslashes($errorMessage) ?>',
    confirmButtonColor: '#e05c6a'
});
<?php endif; ?>
</script>

<!-- ══ FORM JS ══ -->
<script>
const submitBtn   = document.getElementById('submitBtn');
const agreeCheck  = document.getElementById('agreeCheck');
const reqFields   = document.querySelectorAll('[required]');

// Toggle submit button
agreeCheck.addEventListener('change', () => {
    submitBtn.disabled = !agreeCheck.checked;
});

// Live validation
reqFields.forEach(f => {
    ['input','change'].forEach(evt => {
        f.addEventListener(evt, () => {
            if (f.type === 'checkbox') return;
            const ok = f.value.trim() !== '';
            f.classList.toggle('valid',   ok);
            f.classList.toggle('invalid', !ok);
        });
    });
});

// Submit guard
document.getElementById('registrationForm').addEventListener('submit', function(e) {
    let ok = true;
    reqFields.forEach(f => {
        if (f.type === 'checkbox') return;
        if (!f.value.trim()) {
            f.classList.add('invalid');
            f.classList.remove('valid');
            ok = false;
        }
    });
    if (!ok) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Incomplete Form',
            text: 'Please complete all required fields before submitting.',
            confirmButtonText: 'OK',
            confirmButtonColor: '#4f6ef7'
        }).then(() => {
            document.querySelector('.invalid')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }
});

// Day select → date display + update hidden start_date
document.getElementById('event_day').addEventListener('change', function() {
    const opt  = this.options[this.selectedIndex];
    const date = opt.getAttribute('data-date');
    const disp = document.getElementById('eventDateDisplay');
    if (!date) { disp.value = ''; return; }
    disp.value = new Date(date + 'T00:00:00').toLocaleDateString('en-US', {
        month: 'long', day: '2-digit', year: 'numeric'
    });
    // Override start_date with the selected attendance day
    document.getElementById('start_date').value = date;
});

window.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('event_day');
    const selectedOption = select.options[select.selectedIndex];

    if (selectedOption && selectedOption.dataset.date) {
        const date = selectedOption.dataset.date;
        const disp = document.getElementById('eventDateDisplay');

        disp.value = new Date(date + 'T00:00:00').toLocaleDateString('en-US', {
            month: 'long', day: '2-digit', year: 'numeric'
        });

        document.getElementById('start_date').value = date;
    }
});
</script>

<script src="js/theme-toggle-public.js"></script>
</body>
</html>