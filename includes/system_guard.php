<?php
require_once __DIR__ . '/../db.php';

$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("
    SELECT setup_completed 
    FROM system_settings 
    ORDER BY id DESC 
    LIMIT 1
");
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Only redirect if setup is truly incomplete
if (!$result || (int)$result['setup_completed'] !== 1) {
    // Reset session step when redirecting
    if (isset($_SESSION['setup_step'])) {
        unset($_SESSION['setup_step']);
    }
    header("Location: /EXAMCENTER/super_admin/system_setup.php");
    exit();
}
?>