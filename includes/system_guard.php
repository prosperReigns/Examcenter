<?php
require_once __DIR__ . '/../db.php';

$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT setup_completed FROM system_settings WHERE id = 1");
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$result || (int)$result['setup_completed'] !== 1) {
    header("Location: /EXAMCENTER/super_admin/system_setup.php");
    exit();
}
