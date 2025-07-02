<?php
require '../db.php';
$conn = Database::getInstance()->getConnection();

$result = $conn->query("SELECT * FROM tests ORDER BY id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Download Tests</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container py-5">
    <h2 class="mb-4">Available Tests</h2>
    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>Title</th>
                <th>Class</th>
                <th>Subject</th>
                <th>Duration (mins)</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= htmlspecialchars($row['class']) ?></td>
                <td><?= htmlspecialchars($row['subject']) ?></td>
                <td><?= htmlspecialchars($row['duration']) ?></td>
                <td>
                    <a class="btn btn-sm btn-primary" 
                       href="download.php?class=<?= urlencode($row['class']) ?>&subject=<?= urlencode($row['subject']) ?>&title=<?= urlencode($row['title']) ?>">
                       Download
                    </a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</body>
</html>
