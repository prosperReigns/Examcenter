<?php
function getPostActivationRedirect(): string
{
    // Teacher is explicitly identified by user_role.
    if (
        isset($_SESSION['user_id']) &&
        isset($_SESSION['user_role']) &&
        strtolower((string) $_SESSION['user_role']) === 'teacher'
    ) {
        return '../teacher/dashboard.php';
    }

    // An authenticated user without the teacher role
    // is treated as an admin.
    if (isset($_SESSION['user_id'])) {
        return '../admin/dashboard.php';
    }

    // No authenticated user: system setup / first activation.
    return 'index.php';
}
?>