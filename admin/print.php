<?php require 'db.php'; ?>

<!DOCTYPE html>
<html>
<head>
<title>Print Registrations</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
@media print {
    button { display: none; }
}
</style>
</head>

<body>

<div class="container mt-3">
<button onclick="window.print()" class="btn btn-primary mb-3">Print</button>

<h5 class="text-center fw-bold mb-3">
Event Registration List
</h5>

<table class="table table-bordered table-sm">
<thead>
<tr>
    <th>#</th>
    <th>Name</th>
    <th>Unit</th>
    <th>Rank</th>
    <th>Service</th>
    <th>Serial</th>
</tr>
</thead>
<tbody>

<?php
$i = 1;
$stmt = $pdo->query("SELECT * FROM registrations");
foreach ($stmt as $row):
?>
<tr>
    <td><?= $i++ ?></td>
    <td><?= $row['last_name'].', '.$row['first_name'] ?></td>
    <td><?= $row['unit_office'] ?></td>
    <td><?= $row['rank'] ?></td>
    <td><?= $row['major_service'] ?></td>
    <td><?= $row['serial_number'] ?></td>
</tr>
<?php endforeach; ?>

</tbody>
</table>

</div>
</body>
</html>
