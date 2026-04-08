<?php
require '../config/db.php';

$username = 'admin';
$password = password_hash('admin123', PASSWORD_DEFAULT);
$full_name = 'System Administrator';

$stmt = $pdo->prepare("
    INSERT INTO admins (username, password, full_name)
    VALUES (?, ?, ?)
");

$stmt->execute([$username, $password, $full_name]);

echo "Admin created successfully";
