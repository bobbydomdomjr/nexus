<?php
require 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Invalid request');
}

/* =========================
   SAFELY COLLECT POST DATA
========================= */

$agenda         = $_POST['agenda']         ?? null;
$end_date       = $_POST['end_date']       ?? null;
$event_day      = $_POST['event_day']      ?? null;
$venue          = $_POST['venue']          ?? null;

$last_name      = trim($_POST['last_name']      ?? '');
$first_name     = trim($_POST['first_name']     ?? '');
$middle_name    = trim($_POST['middle_name']    ?? '');
$middle_initial = trim($_POST['middle_initial'] ?? '');
$ext_name       = $_POST['ext_name']       ?? null;

$unit_office    = $_POST['unit_office']    ?? null;
$rank           = $_POST['rank']           ?? null;
$major_service  = $_POST['major_service']  ?? null;
$serial_number  = trim($_POST['serial_number']  ?? '');
$designation    = trim($_POST['designation']    ?? '');

$email          = trim($_POST['email']          ?? '');
$contact_number = trim($_POST['contact_number'] ?? '');
$agreed_terms   = isset($_POST['agreed_terms']) ? 1 : 0;

/* =========================
   DERIVE start_date FROM
   THE SELECTED event_day
========================= */

// event_day is stored as "01", "02", etc.
// The hidden start_date field is already overridden by JS to the
// selected day's date — but we derive it server-side too for safety.

$event_start    = $_POST['start_date'] ?? null; // overridden by JS on the front-end
$start_date     = $event_start;                 // will be the selected day's date

/* =========================
   BASIC SERVER-SIDE GUARD
========================= */

$required = [$agenda, $start_date, $end_date, $event_day, $venue,
             $last_name, $first_name, $middle_initial,
             $unit_office, $rank, $major_service, $serial_number,
             $designation, $email, $contact_number];

foreach ($required as $val) {
    if (empty(trim((string)$val))) {
        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'warning',
            title: 'Incomplete Submission',
            text: 'All required fields must be filled out.',
            confirmButtonColor: '#4f6ef7'
        }).then(() => { window.history.back(); });
        </script>";
        exit;
    }
}

/* =========================
   INSERT QUERY
========================= */

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

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':agenda'         => $agenda,
        ':start_date'     => $start_date,   // ← selected day's date
        ':end_date'       => $end_date,
        ':event_day'      => $event_day,
        ':venue'          => $venue,

        ':last_name'      => $last_name,
        ':first_name'     => $first_name,
        ':middle_name'    => $middle_name ?: null,
        ':middle_initial' => $middle_initial,
        ':ext_name'       => $ext_name ?: null,

        ':unit_office'    => $unit_office,
        ':rank'           => $rank,
        ':major_service'  => $major_service,
        ':serial_number'  => $serial_number,
        ':designation'    => $designation,

        ':email'          => $email,
        ':contact_number' => $contact_number,
        ':agreed_terms'   => $agreed_terms,
    ]);

    echo "
    <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
    Swal.fire({
        icon: 'success',
        title: 'Registration Successful',
        html: 'Your registration has been saved.<br><span style=\"font-family:\'DM Mono\',monospace;font-size:12px;color:#8890aa\">You may now close this page or register another.</span>',
        confirmButtonText: 'Register Another',
        confirmButtonColor: '#4f6ef7'
    }).then(() => {
        window.history.back();
    });
    </script>";

} catch (PDOException $e) {
    echo "
    <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
    Swal.fire({
        icon: 'error',
        title: 'Submission Failed',
        text: 'There was an error saving your registration. Please try again.',
        confirmButtonColor: '#e05c6a'
    }).then(() => {
        window.history.back();
    });
    </script>";
}
?>