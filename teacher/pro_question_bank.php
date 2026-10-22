<?php
/**
 * Compatibility route for the former duplicate Pro question-bank page.
 * bank.php is the single source of truth for objective and theory questions.
 */
session_start();
if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_role'] ?? '')) !== 'teacher') {
    header('Location: ../login.php?error=Not logged in');
    exit;
}
header('Location: bank.php');
exit;
