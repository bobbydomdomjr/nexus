// debug_agenda.php  — DELETE after confirming
<?php
require '../config/db.php';
header('Content-Type: application/json');

echo json_encode([
    'in_registrations' => $pdo->query("SELECT DISTINCT agenda, LENGTH(agenda) as len FROM event_registrations")->fetchAll(PDO::FETCH_ASSOC),
    'in_settings'      => $pdo->query("SELECT id, agenda, LENGTH(agenda) as len FROM event_settings")->fetchAll(PDO::FETCH_ASSOC),
]);