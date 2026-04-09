<?php
require 'config/db.php';

$query = "SELECT `rank`, `first_name`, `last_name`, `middle_initial`, `ext_name` FROM event_registrations LIMIT 1";

try {
    $stmt = $pdo->query($query);
    $results = $stmt->fetchAll();
    echo "Query successful. Rows: " . count($results) . "\n";
    print_r($results[0]);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>