<?php
$stmt = $pdo->query("SELECT * FROM event_settings LIMIT 1");
$event = $stmt->fetch(PDO::FETCH_ASSOC);

$agenda = $event['agenda'] ?? '';
$venue = $event['venue'] ?? '';
$event_date = $event['event_date'] ?? '';
$formatted_date = date('F d, Y', strtotime($event_date));
