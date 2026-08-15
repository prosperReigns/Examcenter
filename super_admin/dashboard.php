<?php
session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    strtolower($_SESSION['user_role']) !== 'super_admin'
) {
    error_log("Redirecting to login: No user_id or invalid role in session");
    header("Location: ../login.php?error=Not logged in");
    exit();
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/
try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

    $super_admin_id = (int) $_SESSION['user_id'];

    /*
    |--------------------------------------------------------------------------
    | SUPER ADMIN PROFILE
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
        SELECT username
        FROM super_admins
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        error_log("Prepare failed for admin profile: " . $conn->error);
        die("Database error");
    }

    $stmt->bind_param("i", $super_admin_id);
    $stmt->execute();

    $user_data = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$user_data) {
        error_log("Invalid super admin data for user_id={$super_admin_id}");

        session_destroy();

        header("Location: ../login.php?error=Unauthorized");
        exit();
    }


    /*
    |--------------------------------------------------------------------------
    | DASHBOARD STATISTICS
    |--------------------------------------------------------------------------
    */
    $stats = [
        'total_questions' => 0,
        'total_students' => 0,
        'completed_exams' => 0,
        'total_teachers' => 0,
        'question_distribution' => []
    ];


    // Total questions
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count
        FROM new_questions
    ");

    $stmt->execute();

    $stats['total_questions'] =
        (int) ($stmt->get_result()->fetch_assoc()['count'] ?? 0);

    $stmt->close();


    // Total students
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count
        FROM students
    ");

    $stmt->execute();

    $stats['total_students'] =
        (int) ($stmt->get_result()->fetch_assoc()['count'] ?? 0);

    $stmt->close();


    // Total teachers
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count
        FROM teachers
    ");

    $stmt->execute();

    $stats['total_teachers'] =
        (int) ($stmt->get_result()->fetch_assoc()['count'] ?? 0);

    $stmt->close();


    // Completed exams
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count
        FROM results
    ");

    $stmt->execute();

    $stats['completed_exams'] =
        (int) ($stmt->get_result()->fetch_assoc()['count'] ?? 0);

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | QUESTION DISTRIBUTION
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
        SELECT subject, COUNT(*) AS count
        FROM new_questions
        GROUP BY subject
        ORDER BY count DESC
        LIMIT 3
    ");

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $stats['question_distribution'][$row['subject']] =
            (int) $row['count'];
    }

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | PERFORMANCE FILTER
    |--------------------------------------------------------------------------
    */
    $allowed_classes = [
        'JSS1',
        'JSS2',
        'JSS3',
        'SS1',
        'SS2',
        'SS3'
    ];

    $selected_class = $_GET['class'] ?? 'all';

    if (
        $selected_class !== 'all' &&
        !in_array($selected_class, $allowed_classes, true)
    ) {
        $selected_class = 'all';
    }


    /*
    |--------------------------------------------------------------------------
    | PERFORMANCE DATA
    |--------------------------------------------------------------------------
    */
    $performance_query = "
        SELECT
            t.subject,
            AVG(
                CASE
                    WHEN r.total_questions > 0
                    THEN (r.score / r.total_questions) * 100
                    ELSE 0
                END
            ) AS average_score
        FROM results r
        JOIN tests t
            ON r.test_id = t.id
        JOIN students s
            ON r.user_id = s.id
        WHERE 1 = 1
    ";

    $params = [];
    $types = '';

    if ($selected_class !== 'all') {
        $performance_query .= "
            AND s.class = ?
        ";

        $params[] = $selected_class;
        $types .= 's';
    }

    $performance_query .= "
        GROUP BY t.subject
        ORDER BY average_score DESC
    ";

    $stmt = $conn->prepare($performance_query);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $result = $stmt->get_result();

    $chart_labels = [];
    $chart_data = [];

    while ($row = $result->fetch_assoc()) {
        $chart_labels[] = $row['subject'];
        $chart_data[] = round((float) $row['average_score'], 1);
    }

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | RECENT EXAM RESULTS
    |--------------------------------------------------------------------------
    */
    $recent_results = [];

    $stmt = $conn->prepare("
        SELECT
            r.user_id,
            s.full_name,
            r.created_at,
            CASE
                WHEN r.total_questions > 0
                THEN (r.score / r.total_questions) * 100
                ELSE 0
            END AS score,
            s.class,
            r.status
        FROM results r
        JOIN students s
            ON r.user_id = s.id
        ORDER BY r.created_at DESC
        LIMIT 10
    ");

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $recent_results[] = $row;
    }

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | PENDING EXAMS
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count
        FROM results
        WHERE LOWER(status) = 'pending'
    ");

    $stmt->execute();

    $pending_exams =
        (int) ($stmt->get_result()->fetch_assoc()['count'] ?? 0);

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | RECENT ACTIVITIES
    |--------------------------------------------------------------------------
    */
    $recent_activities = [];

    $stmt = $conn->prepare("
        SELECT
            activity,
            created_at,
            ip_address
        FROM activities_log
        ORDER BY created_at DESC
        LIMIT 5
    ");

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $recent_activities[] = $row;
    }

    $stmt->close();


} catch (Exception $e) {

    error_log("Dashboard error: " . $e->getMessage());

    die("System error");
}

$conn->close();


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/
function time_ago($datetime)
{
    try {
        $now = new DateTime();
        $ago = new DateTime($datetime);

        $diff = $now->diff($ago);

        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = [
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        ];

        foreach ($string as $key => &$value) {

            if ($diff->$key) {

                $value =
                    $diff->$key .
                    ' ' .
                    $value .
                    ($diff->$key > 1 ? 's' : '');

            } else {

                unset($string[$key]);
            }
        }

        $string = array_slice($string, 0, 1);

        return $string
            ? implode(', ', $string) . ' ago'
            : 'just now';

    } catch (Exception $e) {

        return 'recently';
    }
}


$username = $user_data['username'] ?? 'Super Admin';
?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Super Admin Dashboard | Examcenter
</title>

<link
    href="../css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="../css/all.css"
>

<link
    rel="stylesheet"
    href="../css/dataTables.bootstrap5.min.css"
>

<style>

    /* =========================================================
       ROOT
    ========================================================= */

    :root {
        --primary: #4361ee;
        --primary-dark: #3046c7;
        --secondary: #64748b;
        --success: #16a34a;
        --warning: #f59e0b;
        --danger: #dc3545;
        --info: #0891b2;

        --sidebar-width: 260px;
        --sidebar-collapsed: 78px;

        --background: #f5f7fb;
        --card: #ffffff;
        --border: #e8ebf1;
        --text: #1e293b;
        --muted: #64748b;

        --shadow:
            0 8px 30px rgba(15, 23, 42, 0.06);

        --transition: 0.25s ease;
    }


    * {
        box-sizing: border-box;
    }


    body {
        margin: 0;
        background: var(--background);
        color: var(--text);
        font-family:
            -apple-system,
            BlinkMacSystemFont,
            "Segoe UI",
            Roboto,
            Arial,
            sans-serif;
    }


    a {
        text-decoration: none;
    }


    /* =========================================================
       SIDEBAR
    ========================================================= */

    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;

        width: var(--sidebar-width);

        background:
            linear-gradient(
                180deg,
                #111827 0%,
                #172033 100%
            );

        color: #fff;

        z-index: 1100;

        transition:
            width var(--transition),
            transform var(--transition);

        box-shadow:
            4px 0 25px rgba(15, 23, 42, 0.08);

        overflow: visible;
    }


    .sidebar-header {
        height: 82px;

        display: flex;
        align-items: center;

        padding: 0 20px;

        border-bottom:
            1px solid rgba(255,255,255,0.08);
    }


    .brand {
        display: flex;
        align-items: center;
        gap: 12px;

        color: #fff;

        min-width: 0;
    }


    .brand-icon {
        width: 42px;
        height: 42px;

        flex-shrink: 0;

        display: flex;
        align-items: center;
        justify-content: center;

        border-radius: 12px;

        background:
            linear-gradient(
                135deg,
                var(--primary),
                #6366f1
            );

        font-size: 19px;

        box-shadow:
            0 6px 18px rgba(67, 97, 238, 0.35);
    }


    .brand-text {
        white-space: nowrap;
        overflow: hidden;
        transition: opacity var(--transition);
    }


    .brand-text strong {
        display: block;
        font-size: 17px;
        letter-spacing: 0.2px;
    }


    .brand-text small {
        display: block;
        color: #94a3b8;
        margin-top: 2px;
        font-size: 11px;
    }


    /* =========================================================
       ADMIN PROFILE
    ========================================================= */

    .admin-profile {
        margin: 18px 14px;

        padding: 14px;

        border-radius: 14px;

        background:
            rgba(255,255,255,0.055);

        border:
            1px solid rgba(255,255,255,0.06);
    }


    .profile-avatar {
        width: 40px;
        height: 40px;

        display: flex;
        align-items: center;
        justify-content: center;

        border-radius: 50%;

        background:
            rgba(67, 97, 238, 0.2);

        color: #93c5fd;

        flex-shrink: 0;
    }


    .profile-details {
        min-width: 0;
    }


    .profile-details small {
        color: #94a3b8;
        font-size: 11px;
    }


    .profile-details strong {
        display: block;

        color: #fff;

        font-size: 13px;

        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }


    /* =========================================================
       SIDEBAR NAVIGATION
    ========================================================= */

    .sidebar-section {
        padding: 0 18px;

        margin-top: 22px;
        margin-bottom: 8px;

        color: #64748b;

        font-size: 10px;

        text-transform: uppercase;

        letter-spacing: 1px;

        font-weight: 700;

        white-space: nowrap;
    }


    .sidebar-menu {
        padding: 0 12px;
    }


    .sidebar-menu a {
        position: relative;

        display: flex;
        align-items: center;

        gap: 13px;

        width: 100%;

        padding: 12px 14px;

        margin-bottom: 4px;

        border-radius: 10px;

        color: #cbd5e1;

        font-size: 13px;

        transition:
            background var(--transition),
            color var(--transition),
            transform var(--transition);

        white-space: nowrap;
    }


    .sidebar-menu a i {
        width: 20px;

        text-align: center;

        font-size: 15px;

        flex-shrink: 0;
    }


    .sidebar-menu a:hover {
        background:
            rgba(255,255,255,0.07);

        color: #fff;

        transform: translateX(2px);
    }


    .sidebar-menu a.active {
        background:
            linear-gradient(
                135deg,
                var(--primary),
                #5b6ff5
            );

        color: #fff;

        box-shadow:
            0 7px 20px rgba(67, 97, 238, 0.28);
    }


    .sidebar-menu a.logout-btn {
        margin-top: 20px;

        color: #fca5a5;
    }


    .sidebar-menu a.logout-btn:hover {
        background:
            rgba(220, 53, 69, 0.12);

        color: #fecaca;
    }


    /* =========================================================
       RIGHT SIDEBAR TOGGLE
    ========================================================= */

    .sidebar-toggle {
        position: absolute;

        right: -16px;

        top: 88px;

        width: 32px;
        height: 32px;

        border: 0;

        border-radius: 50%;

        background: #fff;

        color: var(--primary);

        display: flex;
        align-items: center;
        justify-content: center;

        box-shadow:
            0 4px 15px rgba(15, 23, 42, 0.15);

        cursor: pointer;

        z-index: 1200;

        transition:
            transform var(--transition),
            background var(--transition);
    }


    .sidebar-toggle:hover {
        background: #f8fafc;
    }


    .sidebar-toggle i {
        transition:
            transform var(--transition);
    }


    /* =========================================================
       COLLAPSED SIDEBAR
    ========================================================= */

    body.sidebar-collapsed .sidebar {
        width: var(--sidebar-collapsed);
    }


    body.sidebar-collapsed .main-content {
        margin-left: var(--sidebar-collapsed);
    }


    body.sidebar-collapsed .brand-text,
    body.sidebar-collapsed .profile-details,
    body.sidebar-collapsed .sidebar-section,
    body.sidebar-collapsed .sidebar-menu a span {
        opacity: 0;
        width: 0;
        overflow: hidden;
    }


    body.sidebar-collapsed .sidebar-header {
        justify-content: center;
        padding: 0;
    }


    body.sidebar-collapsed .admin-profile {
        justify-content: center;
        padding: 10px;
    }


    body.sidebar-collapsed .sidebar-menu {
        padding: 0 10px;
    }


    body.sidebar-collapsed .sidebar-menu a {
        justify-content: center;
        padding: 12px;
    }


    body.sidebar-collapsed .sidebar-toggle i {
        transform: rotate(180deg);
    }


    /* =========================================================
       MAIN CONTENT
    ========================================================= */

    .main-content {
        min-height: 100vh;

        margin-left: var(--sidebar-width);

        padding: 28px 32px;

        transition:
            margin-left var(--transition);
    }


    /* =========================================================
       TOP BAR
    ========================================================= */

    .topbar {
        display: flex;

        align-items: center;

        justify-content: space-between;

        margin-bottom: 28px;
    }


    .page-heading h1 {
        margin: 0;

        font-size: 25px;

        font-weight: 700;

        letter-spacing: -0.4px;
    }


    .page-heading p {
        margin: 5px 0 0;

        color: var(--muted);

        font-size: 13px;
    }


    .topbar-actions {
        display: flex;

        align-items: center;

        gap: 10px;
    }


    .topbar-button {
        position: relative;

        width: 42px;
        height: 42px;

        border: 1px solid var(--border);

        background: #fff;

        color: var(--secondary);

        border-radius: 11px;

        display: flex;

        align-items: center;

        justify-content: center;

        transition: var(--transition);
    }


    .topbar-button:hover {
        color: var(--primary);

        border-color:
            rgba(67, 97, 238, 0.25);

        transform: translateY(-1px);
    }


    .notification-count {
        position: absolute;

        top: -4px;
        right: -4px;

        min-width: 18px;
        height: 18px;

        padding: 0 4px;

        border-radius: 20px;

        background: var(--danger);

        color: #fff;

        font-size: 10px;

        display: flex;
        align-items: center;
        justify-content: center;

        border: 2px solid var(--background);
    }


    .view-results-button {
        display: inline-flex;

        align-items: center;

        gap: 8px;

        padding: 10px 15px;

        border-radius: 10px;

        background: var(--primary);

        color: #fff;

        font-size: 13px;

        font-weight: 600;

        transition: var(--transition);
    }


    .view-results-button:hover {
        background: var(--primary-dark);

        color: #fff;

        transform: translateY(-1px);
    }


    /* =========================================================
       STAT CARDS
    ========================================================= */

    .stat-card {
        position: relative;

        overflow: hidden;

        background: #fff;

        border:
            1px solid var(--border);

        border-radius: 16px;

        padding: 21px;

        height: 100%;

        box-shadow: var(--shadow);

        transition:
            transform var(--transition),
            box-shadow var(--transition);
    }


    .stat-card:hover {
        transform: translateY(-4px);

        box-shadow:
            0 15px 35px rgba(15, 23, 42, 0.09);
    }


    .stat-card::after {
        content: "";

        position: absolute;

        right: -30px;
        bottom: -30px;

        width: 100px;
        height: 100px;

        border-radius: 50%;

        background:
            rgba(67, 97, 238, 0.04);
    }


    .stat-icon {
        width: 46px;
        height: 46px;

        display: flex;
        align-items: center;
        justify-content: center;

        border-radius: 12px;

        font-size: 18px;

        margin-bottom: 16px;
    }


    .icon-primary {
        color: var(--primary);
        background: rgba(67, 97, 238, 0.1);
    }


    .icon-success {
        color: var(--success);
        background: rgba(22, 163, 74, 0.1);
    }


    .icon-info {
        color: var(--info);
        background: rgba(8, 145, 178, 0.1);
    }


    .icon-warning {
        color: var(--warning);
        background: rgba(245, 158, 11, 0.1);
    }


    .stat-label {
        color: var(--muted);

        font-size: 12px;

        font-weight: 500;
    }


    .count {
        font-size: 29px;

        line-height: 1;

        font-weight: 750;

        color: var(--text);

        margin-bottom: 7px;
    }


    /* =========================================================
       CARDS
    ========================================================= */

    .dashboard-card {
        background: #fff;

        border:
            1px solid var(--border);

        border-radius: 16px;

        box-shadow: var(--shadow);

        overflow: hidden;

        height: 100%;
    }


    .card-header-modern {
        display: flex;

        align-items: center;

        justify-content: space-between;

        padding: 18px 20px;

        background: #fff;

        border-bottom:
            1px solid var(--border);
    }


    .card-title {
        display: flex;

        align-items: center;

        gap: 9px;

        margin: 0;

        font-size: 14px;

        font-weight: 700;

        color: var(--text);
    }


    .card-title i {
        color: var(--primary);
    }


    .card-body-modern {
        padding: 20px;
    }


    /* =========================================================
       QUESTION DISTRIBUTION
    ========================================================= */

    .subject-item {
        display: flex;

        align-items: center;

        justify-content: space-between;

        padding: 14px;

        margin-bottom: 10px;

        background: #f8fafc;

        border:
            1px solid #edf0f5;

        border-radius: 12px;

        transition: var(--transition);
    }


    .subject-item:last-child {
        margin-bottom: 0;
    }


    .subject-item:hover {
        background: #f1f5f9;

        transform: translateX(2px);
    }


    .subject-name {
        display: flex;

        align-items: center;

        gap: 11px;

        font-size: 13px;

        font-weight: 600;
    }


    .subject-icon {
        width: 34px;
        height: 34px;

        border-radius: 9px;

        display: flex;

        align-items: center;
        justify-content: center;

        background:
            rgba(67, 97, 238, 0.1);

        color: var(--primary);
    }


    .subject-count {
        font-size: 12px;

        color: var(--muted);

        font-weight: 600;
    }


    /* =========================================================
       CHART
    ========================================================= */

    .chart-wrapper {
        position: relative;

        height: 315px;
    }


    .chart-empty {
        position: absolute;

        inset: 0;

        display: flex;

        flex-direction: column;

        align-items: center;

        justify-content: center;

        color: var(--muted);
    }


    .chart-empty i {
        font-size: 35px;

        margin-bottom: 12px;

        opacity: 0.45;
    }


    .class-select {
        width: auto;

        min-width: 120px;

        border:
            1px solid var(--border);

        border-radius: 9px;

        font-size: 12px;

        padding: 7px 30px 7px 10px;
    }


    /* =========================================================
       RESULTS TABLE
    ========================================================= */

    .table-card {
        margin-top: 24px;
    }


    .table-responsive {
        padding: 0 20px 20px;
    }


    #resultsTable {
        margin-bottom: 0 !important;
    }


    #resultsTable thead th {
        background: #f8fafc;

        border-bottom:
            1px solid var(--border);

        color: var(--muted);

        font-size: 11px;

        text-transform: uppercase;

        letter-spacing: 0.4px;

        font-weight: 700;

        padding: 13px;
    }


    #resultsTable tbody td {
        padding: 14px 13px;

        vertical-align: middle;

        color: #475569;

        font-size: 13px;
    }


    #resultsTable tbody tr:hover {
        background: #fafbff;
    }


    .student-name {
        color: var(--text);

        font-weight: 600;
    }


    .score {
        font-weight: 700;

        color: var(--text);
    }


    .status-badge {
        display: inline-flex;

        align-items: center;

        gap: 5px;

        padding: 5px 9px;

        border-radius: 20px;

        font-size: 10px;

        font-weight: 700;
    }


    .badge-passed {
        background: #dcfce7;

        color: #15803d;
    }


    .badge-failed {
        background: #fee2e2;

        color: #b91c1c;
    }


    .badge-pending {
        background: #fef3c7;

        color: #b45309;
    }


    /* =========================================================
       QUICK ACTIONS
    ========================================================= */

    .action-item {
        display: flex;

        align-items: center;

        justify-content: space-between;

        padding: 14px;

        border:
            1px solid var(--border);

        border-radius: 12px;

        color: var(--text);

        margin-bottom: 10px;

        transition: var(--transition);
    }


    .action-item:last-child {
        margin-bottom: 0;
    }


    .action-item:hover {
        color: var(--text);

        border-color:
            rgba(67, 97, 238, 0.25);

        background:
            rgba(67, 97, 238, 0.025);

        transform: translateX(3px);
    }


    .action-left {
        display: flex;

        align-items: center;

        gap: 12px;
    }


    .action-icon {
        width: 40px;
        height: 40px;

        display: flex;

        align-items: center;

        justify-content: center;

        border-radius: 10px;

        color: var(--primary);

        background:
            rgba(67, 97, 238, 0.09);
    }


    .action-title {
        display: block;

        font-size: 13px;

        font-weight: 700;
    }


    .action-description {
        display: block;

        margin-top: 2px;

        color: var(--muted);

        font-size: 11px;
    }


    .action-arrow {
        color: #94a3b8;

        font-size: 12px;
    }


    /* =========================================================
       ACTIVITY TIMELINE
    ========================================================= */

    .activity-item {
        position: relative;

        display: flex;

        gap: 12px;

        padding: 12px 0;

        border-bottom:
            1px solid #f0f2f5;
    }


    .activity-item:first-child {
        padding-top: 0;
    }


    .activity-item:last-child {
        border-bottom: 0;

        padding-bottom: 0;
    }


    .activity-icon {
        width: 34px;
        height: 34px;

        flex-shrink: 0;

        display: flex;

        align-items: center;

        justify-content: center;

        border-radius: 50%;

        color: var(--primary);

        background:
            rgba(67, 97, 238, 0.09);

        font-size: 12px;
    }


    .activity-text {
        min-width: 0;
    }


    .activity-text p {
        margin: 0 0 4px;

        color: #475569;

        font-size: 12px;

        line-height: 1.5;
    }


    .activity-text small {
        color: #94a3b8;

        font-size: 10px;
    }


    /* =========================================================
       EMPTY STATE
    ========================================================= */

    .empty-state {
        display: flex;

        flex-direction: column;

        align-items: center;

        justify-content: center;

        padding: 35px 15px;

        color: var(--muted);

        text-align: center;
    }


    .empty-state i {
        font-size: 30px;

        margin-bottom: 10px;

        opacity: 0.4;
    }


    .empty-state p {
        margin: 0;

        font-size: 12px;
    }


    /* =========================================================
       MOBILE OVERLAY
    ========================================================= */

    .sidebar-overlay {
        display: none;

        position: fixed;

        inset: 0;

        background:
            rgba(15, 23, 42, 0.45);

        z-index: 1050;
    }


    /* =========================================================
       MOBILE
    ========================================================= */

    @media (max-width: 991.98px) {

        .sidebar {
            transform:
                translateX(-100%);

            width: var(--sidebar-width);
        }


        .sidebar.mobile-open {
            transform:
                translateX(0);
        }


        .sidebar-overlay.active {
            display: block;
        }


        .main-content,
        body.sidebar-collapsed .main-content {
            margin-left: 0;

            padding:
                20px 16px;
        }


        body.sidebar-collapsed .sidebar {
            width: var(--sidebar-width);
        }


        body.sidebar-collapsed .brand-text,
        body.sidebar-collapsed .profile-details,
        body.sidebar-collapsed .sidebar-section,
        body.sidebar-collapsed .sidebar-menu a span {
            opacity: 1;

            width: auto;
        }


        body.sidebar-collapsed .sidebar-header {
            justify-content: flex-start;

            padding: 0 20px;
        }


        body.sidebar-collapsed .admin-profile {
            justify-content: flex-start;

            padding: 14px;
        }


        body.sidebar-collapsed .sidebar-menu {
            padding: 0 12px;
        }


        body.sidebar-collapsed .sidebar-menu a {
            justify-content: flex-start;

            padding: 12px 14px;
        }


        .sidebar-toggle {
            display: none;
        }


        .mobile-menu-button {
            display: flex !important;
        }


        .topbar {
            align-items: flex-start;
        }


        .page-heading h1 {
            font-size: 21px;
        }


        .view-results-button {
            display: none;
        }
    }


    .mobile-menu-button {
        display: none;

        width: 42px;
        height: 42px;

        align-items: center;
        justify-content: center;

        border: 1px solid var(--border);

        background: #fff;

        color: var(--text);

        border-radius: 11px;
    }


    @media (max-width: 575.98px) {

        .main-content {
            padding:
                16px 12px !important;
        }


        .topbar {
            margin-bottom: 20px;
        }


        .page-heading p {
            display: none;
        }


        .topbar-actions {
            gap: 6px;
        }


        .topbar-button,
        .mobile-menu-button {
            width: 38px;
            height: 38px;
        }


        .stat-card {
            padding: 17px;
        }


        .count {
            font-size: 25px;
        }


        .card-header-modern {
            padding: 15px;
        }


        .card-body-modern {
            padding: 15px;
        }


        .table-responsive {
            padding:
                0 12px 15px;
        }


        .class-select {
            min-width: 95px;
        }
    }

</style>

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar" id="sidebar">

<div class="sidebar-header">

    <a
        href="dashboard.php"
        class="brand"
    >

        <div class="brand-icon">
            <i class="fas fa-graduation-cap"></i>
        </div>

        <div class="brand-text">

            <strong>Examcenter</strong>

            <small>Administration Portal</small>

        </div>

    </a>

</div>


<!-- ADMIN PROFILE -->

<div class="admin-profile d-flex align-items-center gap-2">

    <div class="profile-avatar">

        <i class="fas fa-user-shield"></i>

    </div>

    <div class="profile-details">

        <small>Signed in as</small>

        <strong>
            <?= htmlspecialchars($username); ?>
        </strong>

    </div>

</div>


<!-- NAVIGATION -->

<div class="sidebar-section">
    Main Menu
</div>

<nav class="sidebar-menu">

    <a
        href="dashboard.php"
        class="active"
    >
        <i class="fas fa-th-large"></i>
        <span>Dashboard</span>
    </a>


    <a href="manage_admins.php">

        <i class="fas fa-user-shield"></i>

        <span>Manage Admins</span>

    </a>


    <a href="manage_classes.php">

        <i class="fas fa-school"></i>

        <span>Manage Classes</span>

    </a>


    <a href="manage_session.php">

        <i class="fas fa-calendar-alt"></i>

        <span>Manage Session</span>

    </a>


    <a href="manage_subject.php">

        <i class="fas fa-book"></i>

        <span>Manage Subjects</span>

    </a>

    <a href="backup_list.php">
        <i class="fas fa-database"></i>
        <span>Backups</span>
    </a>

    <a
        href="audit_logs.php"
    >
        <i class="fas fa-history"></i>
        <span>Audit Logs</span>
    </a>
    <a href="index.php">
        <i class="fas fa-key"></i>
        <span>License</span>
    </a>

    <div class="sidebar-section">
        System
    </div>


    <a href="settings.php">

        <i class="fas fa-cog"></i>

        <span>Settings</span>

    </a>


    <a
        href="../admin/logout.php"
        class="logout-btn"
    >

        <i class="fas fa-sign-out-alt"></i>

        <span>Logout</span>

    </a>

</nav>


<!-- DESKTOP TOGGLE -->

<button
    type="button"
    class="sidebar-toggle"
    id="sidebarToggle"
    aria-label="Toggle sidebar"
    title="Toggle sidebar"
>

    <i class="fas fa-chevron-left"></i>

</button>

</aside>

<!-- MOBILE OVERLAY -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<main class="main-content">

<!-- TOP BAR -->

<header class="topbar">

    <div class="d-flex align-items-center gap-3">

        <button
            type="button"
            class="mobile-menu-button"
            id="mobileMenuButton"
            aria-label="Open menu"
        >
            <i class="fas fa-bars"></i>
        </button>


        <div class="page-heading">

            <h1>
                Super Admin Dashboard
            </h1>

            <p>
                Monitor and manage your Examcenter system.
            </p>

        </div>

    </div>


    <div class="topbar-actions">

        <!-- NOTIFICATION -->

        <button
            type="button"
            class="topbar-button"
            title="Notifications"
        >

            <i class="fas fa-bell"></i>

            <?php if ($pending_exams > 0): ?>

                <span class="notification-count">
                    <?= $pending_exams; ?>
                </span>

            <?php endif; ?>

        </button>


        <!-- RESULTS -->

        <a
            href="../admin/view_results.php"
            class="view-results-button"
        >

            <i class="fas fa-chart-bar"></i>

            <span>View Results</span>

        </a>

    </div>

</header>


<!-- =====================================================
     STATISTICS
====================================================== -->

<section class="row g-4 mb-4">

    <!-- QUESTIONS -->

    <div class="col-6 col-xl-3">

        <div class="stat-card">

            <div class="stat-icon icon-primary">

                <i class="fas fa-question-circle"></i>

            </div>

            <div class="count">

                <?= $stats['total_questions']; ?>

            </div>

            <div class="stat-label">
                Total Questions
            </div>

        </div>

    </div>


    <!-- STUDENTS -->

    <div class="col-6 col-xl-3">

        <div class="stat-card">

            <div class="stat-icon icon-success">

                <i class="fas fa-user-graduate"></i>

            </div>

            <div class="count">

                <?= $stats['total_students']; ?>

            </div>

            <div class="stat-label">
                Total Students
            </div>

        </div>

    </div>


    <!-- TEACHERS -->

    <div class="col-6 col-xl-3">

        <div class="stat-card">

            <div class="stat-icon icon-info">

                <i class="fas fa-chalkboard-teacher"></i>

            </div>

            <div class="count">

                <?= $stats['total_teachers']; ?>

            </div>

            <div class="stat-label">
                Total Teachers
            </div>

        </div>

    </div>


    <!-- EXAMS -->

    <div class="col-6 col-xl-3">

        <div class="stat-card">

            <div class="stat-icon icon-warning">

                <i class="fas fa-check-circle"></i>

            </div>

            <div class="count">

                <?= $stats['completed_exams']; ?>

            </div>

            <div class="stat-label">
                Exams Completed
            </div>

        </div>

    </div>

</section>


<!-- =====================================================
     QUESTION DISTRIBUTION + PERFORMANCE
====================================================== -->

<section class="row g-4">

    <!-- QUESTION DISTRIBUTION -->

    <div class="col-lg-5">

        <div class="dashboard-card">

            <div class="card-header-modern">

                <h2 class="card-title">

                    <i class="fas fa-layer-group"></i>

                    Question Distribution

                </h2>

                <span class="badge text-bg-light">
                    Top 3
                </span>

            </div>


            <div class="card-body-modern">

                <?php if (empty($stats['question_distribution'])): ?>

                    <div class="empty-state">

                        <i class="fas fa-inbox"></i>

                        <p>
                            No questions found.
                        </p>

                    </div>

                <?php else: ?>

                    <?php foreach (
                        $stats['question_distribution']
                        as $subject => $count
                    ): ?>

                        <div class="subject-item">

                            <div class="subject-name">

                                <div class="subject-icon">

                                    <i class="fas fa-book"></i>

                                </div>

                                <span>
                                    <?= htmlspecialchars($subject); ?>
                                </span>

                            </div>


                            <span class="subject-count">

                                <?= $count; ?>
                                Questions

                            </span>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- PERFORMANCE -->

    <div class="col-lg-7">

        <div class="dashboard-card">

            <div class="card-header-modern">

                <h2 class="card-title">

                    <i class="fas fa-chart-column"></i>

                    Performance Overview

                </h2>


                <form
                    id="classFilterForm"
                    method="GET"
                >

                    <select
                        id="classSelector"
                        name="class"
                        class="form-select class-select"
                    >

                        <option
                            value="all"
                            <?= $selected_class === 'all'
                                ? 'selected'
                                : ''; ?>
                        >
                            All Classes
                        </option>

                        <?php foreach ($allowed_classes as $class): ?>

                            <option
                                value="<?= htmlspecialchars($class); ?>"
                                <?= $selected_class === $class
                                    ? 'selected'
                                    : ''; ?>
                            >
                                <?= htmlspecialchars($class); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </form>

            </div>


            <div class="card-body-modern">

                <div class="chart-wrapper">

                    <canvas
                        id="performanceChart"
                    ></canvas>


                    <div
                        id="chartEmptyState"
                        class="chart-empty"
                        style="display:none;"
                    >

                        <i class="fas fa-chart-bar"></i>

                        <p>
                            No performance data available.
                        </p>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =====================================================
     RECENT RESULTS
====================================================== -->

<section class="dashboard-card table-card">

    <div class="card-header-modern">

        <h2 class="card-title">

            <i class="fas fa-clock-rotate-left"></i>

            Recent Exam Results

        </h2>

        <span class="text-muted small">
            Latest 10 results
        </span>

    </div>


    <div class="table-responsive">

        <table
            id="resultsTable"
            class="table table-hover align-middle"
            style="width:100%"
        >

            <thead>

                <tr>

                    <th>Student ID</th>

                    <th>Student</th>

                    <th>Exam Date</th>

                    <th>Score</th>

                    <th>Class</th>

                    <th>Status</th>

                </tr>

            </thead>


            <tbody>

                <?php foreach ($recent_results as $result): ?>

                    <?php
                        $score = round(
                            (float) $result['score'],
                            1
                        );

                        $passed = $score >= 50;
                    ?>

                    <tr>

                        <td>
                            <?= htmlspecialchars(
                                $result['user_id']
                            ); ?>
                        </td>


                        <td>

                            <span class="student-name">

                                <?= htmlspecialchars(
                                    $result['full_name']
                                ); ?>

                            </span>

                        </td>


                        <td>

                            <?= date(
                                'M j, Y',
                                strtotime(
                                    $result['created_at']
                                )
                            ); ?>

                        </td>


                        <td>

                            <span class="score">

                                <?= $score; ?>%

                            </span>

                        </td>


                        <td>

                            <?= htmlspecialchars(
                                $result['class']
                            ); ?>

                        </td>


                        <td>

                            <span
                                class="status-badge
                                <?= $passed
                                    ? 'badge-passed'
                                    : 'badge-failed'; ?>"
                            >

                                <i
                                    class="fas
                                    <?= $passed
                                        ? 'fa-check'
                                        : 'fa-xmark'; ?>"
                                ></i>

                                <?= $passed
                                    ? 'Passed'
                                    : 'Failed'; ?>

                            </span>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</section>


<!-- =====================================================
     QUICK ACTIONS + RECENT ACTIVITY
====================================================== -->

<section class="row g-4 mt-0">

    <!-- QUICK ACTIONS -->

    <div class="col-lg-6">

        <div class="dashboard-card">

            <div class="card-header-modern">

                <h2 class="card-title">

                    <i class="fas fa-bolt"></i>

                    Quick Actions

                </h2>

            </div>


            <div class="card-body-modern">


                <!-- ADD QUESTIONS -->

                <a
                    href="add_question.php"
                    class="action-item"
                >

                    <div class="action-left">

                        <div class="action-icon">

                            <i class="fas fa-plus"></i>

                        </div>

                        <div>

                            <span class="action-title">
                                Add Questions
                            </span>

                            <span class="action-description">
                                Current count:
                                <?= $stats['total_questions']; ?>
                            </span>

                        </div>

                    </div>


                    <i class="fas fa-chevron-right action-arrow"></i>

                </a>


                <!-- TEACHERS -->

                <a
                    href="manage_teachers.php"
                    class="action-item"
                >

                    <div class="action-left">

                        <div class="action-icon">

                            <i class="fas fa-users"></i>

                        </div>

                        <div>

                            <span class="action-title">
                                Manage Teachers
                            </span>

                            <span class="action-description">
                                Total teachers:
                                <?= $stats['total_teachers']; ?>
                            </span>

                        </div>

                    </div>


                    <i class="fas fa-chevron-right action-arrow"></i>

                </a>


                <!-- RESULTS -->

                <a
                    href="view_results.php"
                    class="action-item"
                >

                    <div class="action-left">

                        <div class="action-icon">

                            <i class="fas fa-file-export"></i>

                        </div>

                        <div>

                            <span class="action-title">
                                View Exam Results
                            </span>

                            <span class="action-description">
                                Available results:
                                <?= $stats['completed_exams']; ?>
                            </span>

                        </div>

                    </div>


                    <i class="fas fa-chevron-right action-arrow"></i>

                </a>


            </div>

        </div>

    </div>


    <!-- RECENT ACTIVITIES -->

    <div class="col-lg-6">

        <div class="dashboard-card">

            <div class="card-header-modern">

                <h2 class="card-title">

                    <i class="fas fa-history"></i>

                    Recent Activities

                </h2>

            </div>


            <div class="card-body-modern">

                <?php if (!empty($recent_activities)): ?>

                    <?php foreach (
                        $recent_activities
                        as $activity
                    ): ?>

                        <div class="activity-item">

                            <div class="activity-icon">

                                <i class="fas fa-info"></i>

                            </div>


                            <div class="activity-text">

                                <p>

                                    <?= htmlspecialchars(
                                        $activity['activity']
                                    ); ?>

                                </p>

                                <small>

                                    <?= time_ago(
                                        $activity['created_at']
                                    ); ?>

                                    <?php if (
                                        !empty(
                                            $activity['ip_address']
                                        )
                                    ): ?>

                                        •
                                        <?= htmlspecialchars(
                                            $activity['ip_address']
                                        ); ?>

                                    <?php endif; ?>

                                </small>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="fas fa-bell-slash"></i>

                        <p>
                            No recent activities.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

</section>

</main>

<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script src="../js/chart.min.js"></script>

<script src="../js/jquery.dataTables.min.js"></script>

<script src="../js/dataTables.bootstrap5.min.js"></script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        /*
        |--------------------------------------------------------------------------
        | SIDEBAR
        |--------------------------------------------------------------------------
        */

        const body =
            document.body;

        const sidebarToggle =
            document.getElementById(
                'sidebarToggle'
            );

        const mobileMenuButton =
            document.getElementById(
                'mobileMenuButton'
            );

        const sidebar =
            document.getElementById(
                'sidebar'
            );

        const sidebarOverlay =
            document.getElementById(
                'sidebarOverlay'
            );


        /*
        |--------------------------------------------------------------------------
        | DESKTOP SIDEBAR COLLAPSE
        |--------------------------------------------------------------------------
        */

        const savedSidebarState =
            localStorage.getItem(
                'examcenterSidebarCollapsed'
            );


        if (
            savedSidebarState === 'true' &&
            window.innerWidth > 991
        ) {
            body.classList.add(
                'sidebar-collapsed'
            );
        }


        sidebarToggle.addEventListener(
            'click',
            function () {

                body.classList.toggle(
                    'sidebar-collapsed'
                );

                localStorage.setItem(
                    'examcenterSidebarCollapsed',
                    body.classList.contains(
                        'sidebar-collapsed'
                    )
                );

            }
        );


        /*
        |--------------------------------------------------------------------------
        | MOBILE SIDEBAR
        |--------------------------------------------------------------------------
        */

        function openMobileSidebar() {

            sidebar.classList.add(
                'mobile-open'
            );

            sidebarOverlay.classList.add(
                'active'
            );

            document.body.style.overflow =
                'hidden';
        }


        function closeMobileSidebar() {

            sidebar.classList.remove(
                'mobile-open'
            );

            sidebarOverlay.classList.remove(
                'active'
            );

            document.body.style.overflow =
                '';
        }


        mobileMenuButton.addEventListener(
            'click',
            openMobileSidebar
        );


        sidebarOverlay.addEventListener(
            'click',
            closeMobileSidebar
        );


        /*
        |--------------------------------------------------------------------------
        | CLOSE MOBILE SIDEBAR AFTER NAVIGATION
        |--------------------------------------------------------------------------
        */

        sidebar
            .querySelectorAll('a')
            .forEach(function (link) {

                link.addEventListener(
                    'click',
                    function () {

                        if (
                            window.innerWidth <= 991
                        ) {
                            closeMobileSidebar();
                        }

                    }
                );

            });


        /*
        |--------------------------------------------------------------------------
        | DATATABLE
        |--------------------------------------------------------------------------
        */

        $('#resultsTable').DataTable({

            responsive: true,

            pageLength: 10,

            lengthChange: false,

            searching: true,

            ordering: true,

            order: [
                [2, 'desc']
            ],

            language: {

                search: '',

                searchPlaceholder:
                    'Search results...',

                emptyTable:
                    'No exam results found',

                zeroRecords:
                    'No matching results found'
            }

        });


        /*
        |--------------------------------------------------------------------------
        | PERFORMANCE CHART
        |--------------------------------------------------------------------------
        */

        const canvas =
            document.getElementById(
                'performanceChart'
            );

        const emptyState =
            document.getElementById(
                'chartEmptyState'
            );


        const chartLabels =
            <?= !empty($chart_labels)
                ? json_encode(
                    $chart_labels,
                    JSON_UNESCAPED_UNICODE
                )
                : '[]'; ?>;


        const chartData =
            <?= !empty($chart_data)
                ? json_encode(
                    $chart_data
                )
                : '[]'; ?>;


        if (
            chartLabels.length === 0 ||
            chartData.length === 0
        ) {

            canvas.style.display =
                'none';

            emptyState.style.display =
                'flex';

        } else {

            new Chart(
                canvas.getContext('2d'),
                {

                    type: 'bar',

                    data: {

                        labels:
                            chartLabels,

                        datasets: [

                            {

                                label:
                                    'Average Score',

                                data:
                                    chartData,

                                backgroundColor:
                                    'rgba(67, 97, 238, 0.78)',

                                borderColor:
                                    '#4361ee',

                                borderWidth:
                                    1,

                                borderRadius:
                                    7,

                                maxBarThickness:
                                    45

                            }

                        ]

                    },


                    options: {

                        responsive: true,

                        maintainAspectRatio:
                            false,

                        animation: {

                            duration: 900

                        },


                        scales: {

                            x: {

                                grid: {

                                    display: false

                                },

                                ticks: {

                                    color:
                                        '#64748b',

                                    font: {

                                        size: 11

                                    }

                                }

                            },


                            y: {

                                beginAtZero: true,

                                max: 100,

                                grid: {

                                    color:
                                        '#eef1f5'

                                },

                                ticks: {

                                    color:
                                        '#64748b',

                                    stepSize: 20,

                                    callback:
                                        function (
                                            value
                                        ) {

                                            return value +
                                                '%';

                                        }

                                }

                            }

                        },


                        plugins: {

                            legend: {

                                display: false

                            },


                            tooltip: {

                                backgroundColor:
                                    '#111827',

                                padding: 11,

                                cornerRadius: 8,

                                callbacks: {

                                    label:
                                        function (
                                            context
                                        ) {

                                            return (
                                                ' Average: ' +
                                                Number(
                                                    context.parsed.y
                                                ).toFixed(1) +
                                                '%'
                                            );

                                        }

                                }

                            }

                        }

                    }

                }
            );

        }


        /*
        |--------------------------------------------------------------------------
        | CLASS FILTER
        |--------------------------------------------------------------------------
        */

        const classSelector =
            document.getElementById(
                'classSelector'
            );


        classSelector.addEventListener(
            'change',
            function () {

                document
                    .getElementById(
                        'classFilterForm'
                    )
                    .submit();

            }
        );


        /*
        |--------------------------------------------------------------------------
        | COUNTER ANIMATION
        |--------------------------------------------------------------------------
        */

        document
            .querySelectorAll('.count')
            .forEach(function (element) {

                const target =
                    parseInt(
                        element.textContent.trim(),
                        10
                    ) || 0;

                let current = 0;

                const duration = 850;

                const start =
                    performance.now();


                function animate(
                    timestamp
                ) {

                    const progress =
                        Math.min(
                            (
                                timestamp -
                                start
                            ) /
                            duration,
                            1
                        );


                    const eased =
                        1 -
                        Math.pow(
                            1 - progress,
                            3
                        );


                    current =
                        Math.floor(
                            eased *
                            target
                        );


                    element.textContent =
                        current.toLocaleString();


                    if (
                        progress < 1
                    ) {

                        requestAnimationFrame(
                            animate
                        );

                    } else {

                        element.textContent =
                            target.toLocaleString();

                    }

                }


                requestAnimationFrame(
                    animate
                );

            });


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE SIDEBAR RESET
        |--------------------------------------------------------------------------
        */

        window.addEventListener(
            'resize',
            function () {

                if (
                    window.innerWidth > 991
                ) {

                    closeMobileSidebar();

                }

            }
        );

    }
);

</script>

</body>

</html>
