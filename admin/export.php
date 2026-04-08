<?php
require 'db.php';

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=event_registrations.xls");

echo "Name\tUnit\tRank\tService\tSerial\tDesignation\tEmail\tContact\tDay\tDate\n";

$stmt = $pdo->query("SELECT * FROM registrations");

while ($row = $stmt->fetch()) {
    echo "{$row['last_name']}, {$row['first_name']}\t";
    echo "{$row['unit_office']}\t";
    echo "{$row['rank']}\t";
    echo "{$row['major_service']}\t";
    echo "{$row['serial_number']}\t";
    echo "{$row['designation']}\t";
    echo "{$row['email']}\t";
    echo "{$row['contact_number']}\t";
    echo "{$row['event_day']}\t";
    echo "{$row['event_date']}\n";
}
