<?php
session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';


// ============================================================
// AUTHENTICATION
// ============================================================

if (!isset($_SESSION['user_id'])) {
    error_log("Redirecting to login: No user_id in session");
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}


// ============================================================
// DATABASE
// ============================================================

try {

    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

    $user_id = (int) $_SESSION['user_id'];

    // --------------------------------------------------------
    // Verify teacher
    // --------------------------------------------------------

    $stmt = $conn->prepare(
        "SELECT role, last_name FROM teachers WHERE id = ?"
    );

    if (!$stmt) {
        error_log("Prepare failed for teacher role check: " . $conn->error);
        die("Database error");
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $stmt->close();

    if (!$user || strtolower($user['role']) !== 'teacher') {

        error_log(
            "Unauthorized access attempt by user_id=" .
            $user_id .
            ", role=" .
            ($user['role'] ?? 'none')
        );

        session_destroy();

        header(
            "Location: /EXAMCENTER/login.php?error=Unauthorized"
        );

        exit();
    }

    $teacher_last_name = $user['last_name'] ?? 'Teacher';


    // --------------------------------------------------------
    // Teacher subjects
    // --------------------------------------------------------

    $stmt = $conn->prepare(
        "SELECT subject FROM teacher_subjects WHERE teacher_id = ?"
    );

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();

    $assigned_subjects = [];

    while ($row = $result->fetch_assoc()) {
        $assigned_subjects[] = $row['subject'];
    }

    $stmt->close();

    if (empty($assigned_subjects)) {
        error_log(
            "No subjects assigned to teacher_id=" . $user_id
        );

        die("No subjects assigned to this teacher");
    }


    // --------------------------------------------------------
    // Prepare subject IN clause
    // --------------------------------------------------------

    $escaped_subjects = array_map(
        [$conn, 'real_escape_string'],
        $assigned_subjects
    );

    $subjects_in = "'" . implode("','", $escaped_subjects) . "'";


} catch (Exception $e) {

    error_log(
        "Dashboard error: " . $e->getMessage()
    );

    die("System error");
}


// ============================================================
// TEACHER INFORMATION
// ============================================================

$teacher_username = $_SESSION['user_username'] ?? 'Teacher';


// ============================================================
// ACTIVITY LOGGER
// ============================================================

function log_activity(
    $conn,
    $activity,
    $teacher_id,
    $ip_address,
    $user_agent
) {

    $stmt = $conn->prepare(
        "INSERT INTO activities_log
        (activity, admin_id, ip_address, user_agent, created_at)
        VALUES (?, NULL, ?, ?, NOW())"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        "sss",
        $activity,
        $ip_address,
        $user_agent
    );

    $stmt->execute();
    $stmt->close();
}


// ============================================================
// LOGIN ACTIVITY
// ============================================================

$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

log_activity(
    $conn,
    "Teacher {$teacher_username} logged in",
    $user_id,
    $ip_address,
    $user_agent
);


// ============================================================
// TIME AGO
// ============================================================

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
            's' => 'second'
        ];

        foreach ($string as $k => &$v) {

            if ($diff->$k) {

                $v =
                    $diff->$k .
                    ' ' .
                    $v .
                    ($diff->$k > 1 ? 's' : '');

            } else {

                unset($string[$k]);
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


// ============================================================
// DEFAULT STATS
// ============================================================

$stats = [
    'total_questions' => 0,
    'active_students' => 0,
    'completed_exams' => 0,
    'question_distribution' => [],
    'performance_data' => []
];


// ============================================================
// TOTAL QUESTIONS
// ============================================================

$query = "
    SELECT COUNT(*) AS count
    FROM new_questions
    WHERE subject IN ($subjects_in)
";

$result = $conn->query($query);

if ($result) {

    $row = $result->fetch_assoc();

    $stats['total_questions'] =
        (int) $row['count'];

    $result->free();
}


// ============================================================
// ACTIVE STUDENTS
// ============================================================

$query = "
    SELECT COUNT(DISTINCT r.user_id) AS count
    FROM results r
    JOIN tests t ON r.test_id = t.id
    WHERE t.subject IN ($subjects_in)
";

$result = $conn->query($query);

if ($result) {

    $row = $result->fetch_assoc();

    $stats['active_students'] =
        (int) $row['count'];

    $result->free();
}


// ============================================================
// COMPLETED EXAMS
// ============================================================

$query = "
    SELECT COUNT(*) AS count
    FROM results r
    JOIN tests t ON r.test_id = t.id
    WHERE t.subject IN ($subjects_in)
";

$result = $conn->query($query);

if ($result) {

    $row = $result->fetch_assoc();

    $stats['completed_exams'] =
        (int) $row['count'];

    $result->free();
}


// ============================================================
// QUESTION DISTRIBUTION
// ============================================================

$query = "
    SELECT subject, COUNT(*) AS count
    FROM new_questions
    WHERE subject IN ($subjects_in)
    GROUP BY subject
    ORDER BY count DESC
    LIMIT 3
";

$result = $conn->query($query);

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $stats['question_distribution'][
            $row['subject']
        ] = (int) $row['count'];
    }

    $result->free();
}


// ============================================================
// PERFORMANCE DATA
// ============================================================

$query = "
    SELECT
        t.subject,
        AVG(r.score) AS average_score
    FROM results r
    JOIN tests t ON r.test_id = t.id
    WHERE t.subject IN ($subjects_in)
    GROUP BY t.subject
";

$result = $conn->query($query);

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $stats['performance_data'][
            $row['subject']
        ] = round(
            (float) $row['average_score'],
            1
        );
    }

    $result->free();
}


// ============================================================
// RECENT RESULTS
// ============================================================

$recent_results = [];

$query = "
    SELECT
        r.user_id,
        s.full_name,
        r.created_at,
        r.score,
        s.class,
        r.status
    FROM results r
    JOIN students s ON r.user_id = s.id
    JOIN tests t ON r.test_id = t.id
    WHERE t.subject IN ($subjects_in)
    ORDER BY r.created_at DESC
    LIMIT 10
";

$result = $conn->query($query);

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $recent_results[] = $row;
    }

    $result->free();
}


// ============================================================
// PENDING EXAMS
// ============================================================

$pending_exams = 0;

$query = "
    SELECT COUNT(*) AS count
    FROM results r
    JOIN tests t ON r.test_id = t.id
    WHERE r.status = 'pending'
    AND t.subject IN ($subjects_in)
";

$result = $conn->query($query);

if ($result) {

    $row = $result->fetch_assoc();

    $pending_exams =
        (int) $row['count'];

    $result->free();
}


// ============================================================
// RECENT ACTIVITIES
// ============================================================

$recent_activities = [];

$query = "
    SELECT
        activity,
        created_at,
        ip_address
    FROM activities_log
    WHERE admin_id IS NULL
    ORDER BY created_at DESC
    LIMIT 5
";

$result = $conn->query($query);

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $recent_activities[] = $row;
    }

    $result->free();
}


$conn->close();


// ============================================================
// CURRENT PAGE
// ============================================================

$current_page =
    basename($_SERVER['PHP_SELF']);


// ============================================================
// NAVIGATION
// ============================================================

$navigation = [

    [
        'section' => 'Overview',
        'items' => [
            [
                'label' => 'Dashboard',
                'icon' => 'fa-tachometer-alt',
                'url' => 'dashboard.php'
            ]
        ]
    ],

    [
        'section' => 'Questions',
        'items' => [
            [
                'label' => 'Add Questions',
                'icon' => 'fa-plus-circle',
                'url' => 'add_question.php'
            ],
            [
                'label' => 'Question Bank',
                'icon' => 'fa-database',
                'url' => 'bank.php'
            ],
            [
                'label' => 'View Questions',
                'icon' => 'fa-list',
                'url' => 'view_questions.php'
            ]
        ]
    ],

    [
        'section' => 'Examinations',
        'items' => [
            [
                'label' => 'Manage Test',
                'icon' => 'fa-file-alt',
                'url' => 'manage_test.php'
            ],
            [
                'label' => 'Exam Results',
                'icon' => 'fa-chart-bar',
                'url' => 'view_results.php'
            ]
        ]
    ],

    [
        'section' => 'Students',
        'items' => [
            [
                'label' => 'Manage Classroom',
                'icon' => 'fa-users',
                'url' => 'manage_classroom.php'
            ],
            [
                'label' => 'Manage Students',
                'icon' => 'fa-user-graduate',
                'url' => 'manage_students.php'
            ]
        ]
    ],

    [
        'section' => 'Account',
        'items' => [
            [
                'label' => 'Settings',
                'icon' => 'fa-cog',
                'url' => 'settings.php'
            ],
            [
                'label' => 'My Profile',
                'icon' => 'fa-user',
                'url' => 'my-profile.php'
            ]
        ]
    ]
];

?>

<!DOCTYPE html>

<html lang="en">

<head>

```
<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Teacher Dashboard | D-Portal CBT
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

    /* =====================================================
       ROOT
    ===================================================== */

    :root {
        --sidebar-width: 265px;
        --sidebar-collapsed: 82px;
        --primary: #4361ee;
        --primary-dark: #3046c9;
        --bg: #f5f7fb;
        --sidebar-bg: #111827;
        --sidebar-hover: #1f2937;
        --text: #1f2937;
        --muted: #6b7280;
        --border: #e5e7eb;
        --success: #16a34a;
        --danger: #dc2626;
        --warning: #d97706;
    }


    /* =====================================================
       BASE
    ===================================================== */

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font-family:
            Inter,
            -apple-system,
            BlinkMacSystemFont,
            "Segoe UI",
            sans-serif;
    }


    /* =====================================================
       SIDEBAR
    ===================================================== */

    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: var(--sidebar-width);
        height: 100vh;
        background: var(--sidebar-bg);
        color: #fff;
        z-index: 1050;
        overflow-y: auto;
        overflow-x: hidden;
        transition:
            width .25s ease,
            transform .25s ease;
        box-shadow:
            8px 0 30px rgba(0, 0, 0, .08);
    }

    .sidebar::-webkit-scrollbar {
        width: 5px;
    }

    .sidebar::-webkit-scrollbar-thumb {
        background: #374151;
        border-radius: 10px;
    }


    /* =====================================================
       BRAND
    ===================================================== */

    .sidebar-brand {
        padding: 24px 20px 20px;
        border-bottom: 1px solid rgba(255,255,255,.08);
    }

    .brand-main {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .brand-icon {
        width: 42px;
        height: 42px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--primary);
        border-radius: 12px;
        font-size: 18px;
        flex-shrink: 0;
    }

    .brand-text h4 {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
    }

    .brand-text span {
        display: block;
        color: #9ca3af;
        font-size: 11px;
        margin-top: 2px;
    }


    /* =====================================================
       TEACHER PROFILE
    ===================================================== */

    .teacher-profile {
        margin-top: 20px;
        padding: 13px;
        border-radius: 12px;
        background: rgba(255,255,255,.055);
        display: flex;
        align-items: center;
        gap: 11px;
    }

    .teacher-avatar {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: #e0e7ff;
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        flex-shrink: 0;
    }

    .teacher-profile small {
        color: #9ca3af;
        font-size: 10px;
        display: block;
    }

    .teacher-profile strong {
        font-size: 13px;
        display: block;
        margin-top: 2px;
    }


    /* =====================================================
       SIDEBAR NAVIGATION
    ===================================================== */

    .sidebar-menu {
        padding: 18px 12px 25px;
    }

    .nav-section {
        margin-bottom: 18px;
    }

    .nav-section-title {
        color: #6b7280;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .9px;
        font-weight: 700;
        padding: 0 11px 8px;
    }

    .sidebar-link {
        display: flex;
        align-items: center;
        gap: 13px;
        padding: 11px 12px;
        margin: 2px 0;
        border-radius: 9px;
        color: #cbd5e1;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        transition: all .18s ease;
    }

    .sidebar-link i {
        width: 20px;
        text-align: center;
        font-size: 14px;
        flex-shrink: 0;
    }

    .sidebar-link:hover {
        color: #fff;
        background: var(--sidebar-hover);
    }

    .sidebar-link.active {
        color: #fff;
        background: var(--primary);
        box-shadow:
            0 6px 18px rgba(67, 97, 238, .25);
    }

    .sidebar-link.logout {
        color: #fca5a5;
    }

    .sidebar-link.logout:hover {
        background: rgba(220,38,38,.12);
        color: #fecaca;
    }


    /* =====================================================
       MAIN CONTENT
    ===================================================== */

    .main-content {
        margin-left: var(--sidebar-width);
        min-height: 100vh;
        padding: 24px 28px 40px;
        transition: margin-left .25s ease;
    }


    /* =====================================================
       TOP HEADER
    ===================================================== */

    .top-header {
        min-height: 62px;
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 15px;
        padding: 11px 14px 11px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
        box-shadow: 0 3px 15px rgba(15,23,42,.035);
    }

    .page-heading h2 {
        font-size: 21px;
        font-weight: 700;
        margin: 0;
    }

    .page-heading p {
        margin: 3px 0 0;
        color: var(--muted);
        font-size: 12px;
    }

    .header-right {
        display: flex;
        align-items: center;
        gap: 10px;
    }


    /* =====================================================
       RIGHT SIDE TOGGLE
    ===================================================== */

    .sidebar-toggle {
        width: 42px;
        height: 42px;
        border: 1px solid var(--border);
        background: #fff;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #374151;
        cursor: pointer;
        transition: all .2s ease;
    }

    .sidebar-toggle:hover {
        color: var(--primary);
        border-color: #c7d2fe;
        background: #eef2ff;
    }


    /* =====================================================
       NOTIFICATION
    ===================================================== */

    .notification-wrapper {
        position: relative;
    }

    .notification-button {
        width: 42px;
        height: 42px;
        border: 1px solid var(--border);
        background: #fff;
        border-radius: 10px;
        color: #4b5563;
        cursor: pointer;
        position: relative;
    }

    .notification-button:hover {
        color: var(--primary);
        background: #f8faff;
    }

    .notification-count {
        position: absolute;
        top: -4px;
        right: -4px;
        min-width: 18px;
        height: 18px;
        padding: 0 5px;
        border-radius: 20px;
        background: var(--danger);
        color: #fff;
        font-size: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid #fff;
    }


    /* =====================================================
       NOTIFICATION MENU
    ===================================================== */

    .notification-menu {
        display: none;
        position: absolute;
        top: 52px;
        right: 0;
        width: 350px;
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 14px;
        box-shadow: 0 15px 40px rgba(15,23,42,.14);
        overflow: hidden;
        z-index: 1100;
    }

    .notification-wrapper.open .notification-menu {
        display: block;
    }

    .notification-header {
        padding: 15px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .notification-header h6 {
        margin: 0;
        font-weight: 700;
    }

    .notification-item {
        padding: 13px 15px;
        display: flex;
        gap: 11px;
        border-bottom: 1px solid #f1f5f9;
    }

    .activity-icon-small {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        background: #eef2ff;
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .notification-item p {
        margin: 0 0 4px;
        font-size: 12px;
        color: #374151;
    }

    .notification-item small {
        color: #9ca3af;
        font-size: 10px;
    }


    /* =====================================================
       STAT CARDS
    ===================================================== */

    .stat-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 15px;
        padding: 20px;
        height: 100%;
        position: relative;
        overflow: hidden;
        transition: transform .2s ease, box-shadow .2s ease;
    }

    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 28px rgba(15,23,42,.07);
    }

    .stat-icon {
        width: 43px;
        height: 43px;
        border-radius: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 17px;
        font-size: 17px;
    }

    .icon-blue {
        background: #eef2ff;
        color: var(--primary);
    }

    .icon-green {
        background: #ecfdf5;
        color: var(--success);
    }

    .icon-cyan {
        background: #ecfeff;
        color: #0891b2;
    }

    .stat-card .count {
        font-size: 29px;
        line-height: 1;
        font-weight: 750;
        margin-bottom: 6px;
    }

    .stat-card .label {
        color: var(--muted);
        font-size: 12px;
    }


    /* =====================================================
       CONTENT CARDS
    ===================================================== */

    .content-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 15px;
        overflow: hidden;
        height: 100%;
        box-shadow: 0 3px 15px rgba(15,23,42,.025);
    }

    .card-heading {
        padding: 17px 20px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .card-heading h5 {
        margin: 0;
        font-size: 14px;
        font-weight: 700;
    }

    .heading-icon {
        color: var(--primary);
        margin-right: 7px;
    }

    .card-body-custom {
        padding: 20px;
    }


    /* =====================================================
       SUBJECT CARDS
    ===================================================== */

    .subject-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }

    .subject-card {
        padding: 17px 14px;
        background: #f8fafc;
        border: 1px solid #edf0f5;
        border-radius: 11px;
        text-align: center;
    }

    .subject-card h6 {
        font-size: 12px;
        font-weight: 700;
        margin-bottom: 7px;
    }

    .subject-card strong {
        font-size: 21px;
        color: var(--primary);
    }

    .subject-card span {
        display: block;
        font-size: 10px;
        color: var(--muted);
        margin-top: 2px;
    }


    /* =====================================================
       CHART
    ===================================================== */

    .chart-wrapper {
        height: 290px;
        position: relative;
    }

    .chart-loading {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2;
        pointer-events: none;
    }

    .chart-loading .spinner-border {
        width: 25px;
        height: 25px;
    }

    .class-selector {
        width: auto;
        min-width: 115px;
        font-size: 11px;
        border-radius: 8px;
    }


    /* =====================================================
       RESULTS TABLE
    ===================================================== */

    .results-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 15px;
        overflow: hidden;
        box-shadow: 0 3px 15px rgba(15,23,42,.025);
    }

    .results-card .table {
        margin: 0;
        font-size: 12px;
    }

    .results-card thead th {
        background: #f8fafc;
        color: #64748b;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .4px;
        font-weight: 700;
        padding: 13px 15px;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    .results-card tbody td {
        padding: 13px 15px;
        vertical-align: middle;
        color: #475569;
    }

    .student-name {
        font-weight: 600;
        color: #1f2937;
    }


    /* =====================================================
       STATUS BADGES
    ===================================================== */

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 9px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 700;
    }

    .status-passed {
        background: #ecfdf5;
        color: #15803d;
    }

    .status-failed {
        background: #fef2f2;
        color: #b91c1c;
    }

    .status-pending {
        background: #fff7ed;
        color: #c2410c;
    }


    /* =====================================================
       QUICK ACTIONS
    ===================================================== */

    .quick-action {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        border: 1px solid #edf0f5;
        background: #fff;
        border-radius: 11px;
        padding: 13px;
        margin-bottom: 9px;
        color: inherit;
        text-decoration: none;
        transition: all .2s ease;
    }

    .quick-action:last-child {
        margin-bottom: 0;
    }

    .quick-action:hover {
        border-color: #c7d2fe;
        background: #f8faff;
        transform: translateX(2px);
    }

    .quick-action-content {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .quick-action-icon {
        width: 38px;
        height: 38px;
        border-radius: 9px;
        background: #eef2ff;
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .quick-action h6 {
        margin: 0 0 2px;
        font-size: 12px;
        font-weight: 700;
    }

    .quick-action small {
        color: var(--muted);
        font-size: 10px;
    }

    .quick-action > i {
        color: #94a3b8;
        font-size: 11px;
    }


    /* =====================================================
       ACTIVITIES
    ===================================================== */

    .activity-row {
        display: flex;
        align-items: flex-start;
        gap: 11px;
        padding: 13px 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .activity-row:first-child {
        padding-top: 0;
    }

    .activity-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .activity-dot {
        width: 32px;
        height: 32px;
        border-radius: 9px;
        background: #eef2ff;
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .activity-row p {
        margin: 0 0 3px;
        font-size: 11px;
        color: #374151;
        line-height: 1.4;
    }

    .activity-row small {
        color: #94a3b8;
        font-size: 9px;
    }


    /* =====================================================
       EMPTY STATE
    ===================================================== */

    .empty-state {
        padding: 35px 15px;
        text-align: center;
        color: #94a3b8;
    }

    .empty-state i {
        font-size: 28px;
        margin-bottom: 10px;
    }

    .empty-state p {
        font-size: 12px;
        margin: 0;
    }


    /* =====================================================
       SIDEBAR COLLAPSED
    ===================================================== */

    body.sidebar-collapsed .sidebar {
        width: var(--sidebar-collapsed);
    }

    body.sidebar-collapsed .main-content {
        margin-left: var(--sidebar-collapsed);
    }

    body.sidebar-collapsed .brand-text,
    body.sidebar-collapsed .teacher-profile > div:not(.teacher-avatar),
    body.sidebar-collapsed .nav-section-title,
    body.sidebar-collapsed .sidebar-link span {
        display: none;
    }

    body.sidebar-collapsed .sidebar-brand {
        padding-left: 20px;
        padding-right: 20px;
    }

    body.sidebar-collapsed .teacher-profile {
        justify-content: center;
        padding: 9px;
    }

    body.sidebar-collapsed .sidebar-link {
        justify-content: center;
        padding: 12px;
    }

    body.sidebar-collapsed .sidebar-link i {
        width: auto;
    }


    /* =====================================================
       OVERLAY
    ===================================================== */

    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15,23,42,.45);
        z-index: 1040;
    }


    /* =====================================================
       RESPONSIVE
    ===================================================== */

    @media (max-width: 991.98px) {

        .sidebar {
            transform: translateX(-100%);
            width: var(--sidebar-width);
        }

        .sidebar.mobile-open {
            transform: translateX(0);
        }

        .sidebar-overlay.active {
            display: block;
        }

        .main-content {
            margin-left: 0 !important;
            padding: 15px;
        }

        body.sidebar-collapsed .sidebar {
            width: var(--sidebar-width);
        }

        body.sidebar-collapsed .brand-text,
        body.sidebar-collapsed .teacher-profile > div:not(.teacher-avatar),
        body.sidebar-collapsed .nav-section-title,
        body.sidebar-collapsed .sidebar-link span {
            display: block;
        }

        body.sidebar-collapsed .teacher-profile {
            justify-content: flex-start;
            padding: 13px;
        }

        body.sidebar-collapsed .sidebar-link {
            justify-content: flex-start;
            padding: 11px 12px;
        }

        .top-header {
            padding: 11px 12px 11px 15px;
        }

        .page-heading h2 {
            font-size: 18px;
        }

        .page-heading p {
            display: none;
        }

        .subject-grid {
            grid-template-columns: 1fr;
        }

        .notification-menu {
            position: fixed;
            top: 75px;
            right: 12px;
            left: 12px;
            width: auto;
        }
    }


    @media (max-width: 575.98px) {

        .main-content {
            padding: 10px;
        }

        .top-header {
            margin-bottom: 15px;
        }

        .header-right {
            gap: 6px;
        }

        .sidebar-toggle,
        .notification-button {
            width: 38px;
            height: 38px;
        }

        .page-heading h2 {
            font-size: 16px;
        }

        .stat-card {
            padding: 17px;
        }

        .card-heading {
            padding: 14px 15px;
        }

        .card-body-custom {
            padding: 15px;
        }

        .chart-wrapper {
            height: 240px;
        }

        .class-selector {
            min-width: 90px;
        }
    }

</style>
```

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar" id="sidebar">

```
<div class="sidebar-brand">

    <div class="brand-main">

        <div class="brand-icon">
            <i class="fas fa-graduation-cap"></i>
        </div>

        <div class="brand-text">

            <h4>D-Portal</h4>

            <span>CBT MANAGEMENT SYSTEM</span>

        </div>

    </div>


    <div class="teacher-profile">

        <div class="teacher-avatar">

            <?= strtoupper(
                substr(
                    htmlspecialchars($teacher_last_name),
                    0,
                    1
                )
            ) ?>

        </div>

        <div>

            <small>Signed in as</small>

            <strong>
                <?= htmlspecialchars($teacher_last_name) ?>
            </strong>

        </div>

    </div>

</div>


<div class="sidebar-menu">

    <?php foreach ($navigation as $section): ?>

        <div class="nav-section">

            <div class="nav-section-title">
                <?= htmlspecialchars($section['section']) ?>
            </div>

            <?php foreach ($section['items'] as $item): ?>

                <?php
                $is_active =
                    $current_page ===
                    basename($item['url']);
                ?>

                <a
                    href="<?= htmlspecialchars($item['url']) ?>"
                    class="sidebar-link <?= $is_active ? 'active' : '' ?>"
                >

                    <i class="fas <?= htmlspecialchars($item['icon']) ?>"></i>

                    <span>
                        <?= htmlspecialchars($item['label']) ?>
                    </span>

                </a>

            <?php endforeach; ?>

        </div>

    <?php endforeach; ?>


    <div class="nav-section">

        <a
            href="logout.php"
            class="sidebar-link logout"
        >

            <i class="fas fa-sign-out-alt"></i>

            <span>Logout</span>

        </a>

    </div>

</div>
```

</aside>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<main class="main-content">

```
<!-- =====================================================
     HEADER
====================================================== -->

<header class="top-header">

    <div class="page-heading">

        <h2>Teacher Dashboard</h2>

        <p>
            Manage your examinations, questions and students.
        </p>

    </div>


    <div class="header-right">


        <!-- Notifications -->

        <div
            class="notification-wrapper"
            id="notificationWrapper"
        >

            <button
                type="button"
                class="notification-button"
                id="notificationButton"
                aria-label="Notifications"
            >

                <i class="fas fa-bell"></i>

                <?php if ($pending_exams > 0): ?>

                    <span class="notification-count">
                        <?= $pending_exams ?>
                    </span>

                <?php endif; ?>

            </button>


            <div class="notification-menu">

                <div class="notification-header">

                    <h6>Recent Activities</h6>

                    <?php if ($pending_exams > 0): ?>

                        <span class="badge bg-danger">
                            <?= $pending_exams ?> Pending
                        </span>

                    <?php endif; ?>

                </div>


                <?php if (!empty($recent_activities)): ?>

                    <?php foreach ($recent_activities as $activity): ?>

                        <div class="notification-item">

                            <div class="activity-icon-small">

                                <i class="fas fa-bell"></i>

                            </div>

                            <div>

                                <p>
                                    <?= htmlspecialchars(
                                        $activity['activity']
                                    ) ?>
                                </p>

                                <small>
                                    <?= time_ago(
                                        $activity['created_at']
                                    ) ?>

                                    &bull;

                                    <?= htmlspecialchars(
                                        $activity['ip_address']
                                    ) ?>
                                </small>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="fas fa-bell-slash"></i>

                        <p>No recent activities</p>

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- RIGHT-SIDE SIDEBAR TOGGLE -->

        <button
            type="button"
            class="sidebar-toggle"
            id="sidebarToggle"
            aria-label="Toggle sidebar"
            title="Toggle sidebar"
        >

            <i class="fas fa-bars"></i>

        </button>

    </div>

</header>


<!-- =====================================================
     STATISTICS
====================================================== -->

<div class="row g-3 mb-4">


    <div class="col-md-4">

        <div class="stat-card">

            <div class="stat-icon icon-blue">

                <i class="fas fa-question-circle"></i>

            </div>

            <div
                class="count"
                data-value="<?= $stats['total_questions'] ?>"
            >
                0
            </div>

            <div class="label">
                Total Questions
            </div>

        </div>

    </div>


    <div class="col-md-4">

        <div class="stat-card">

            <div class="stat-icon icon-green">

                <i class="fas fa-user-graduate"></i>

            </div>

            <div
                class="count"
                data-value="<?= $stats['active_students'] ?>"
            >
                0
            </div>

            <div class="label">
                Active Students
            </div>

        </div>

    </div>


    <div class="col-md-4">

        <div class="stat-card">

            <div class="stat-icon icon-cyan">

                <i class="fas fa-check-circle"></i>

            </div>

            <div
                class="count"
                data-value="<?= $stats['completed_exams'] ?>"
            >
                0
            </div>

            <div class="label">
                Exams Completed
            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     QUESTION DISTRIBUTION / PERFORMANCE
====================================================== -->

<div class="row g-3 mb-4">


    <!-- Question Distribution -->

    <div class="col-lg-6">

        <div class="content-card">

            <div class="card-heading">

                <h5>

                    <i class="fas fa-book heading-icon"></i>

                    Question Distribution

                </h5>

            </div>


            <div class="card-body-custom">

                <?php if (
                    empty(
                        $stats['question_distribution']
                    )
                ): ?>

                    <div class="empty-state">

                        <i class="fas fa-inbox"></i>

                        <p>
                            No questions found for your subjects
                        </p>

                    </div>

                <?php else: ?>

                    <div class="subject-grid">

                        <?php foreach (
                            $stats['question_distribution']
                            as $subject => $count
                        ): ?>

                            <div class="subject-card">

                                <h6>
                                    <?= htmlspecialchars(
                                        $subject
                                    ) ?>
                                </h6>

                                <strong>
                                    <?= $count ?>
                                </strong>

                                <span>
                                    Questions
                                </span>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- Performance -->

    <div class="col-lg-6">

        <div class="content-card">

            <div class="card-heading">

                <h5>

                    <i class="fas fa-chart-bar heading-icon"></i>

                    Performance Overview

                </h5>


                <select
                    id="classSelector"
                    class="form-select form-select-sm class-selector"
                >

                    <option value="all">
                        All Classes
                    </option>

                    <option value="JS1">JS1</option>
                    <option value="JS2">JS2</option>
                    <option value="JS3">JS3</option>
                    <option value="SS1">SS1</option>
                    <option value="SS2">SS2</option>
                    <option value="SS3">SS3</option>

                </select>

            </div>


            <div class="card-body-custom">

                <div class="chart-wrapper">

                    <div
                        class="chart-loading"
                        id="chartLoading"
                    >

                        <div
                            class="spinner-border text-primary"
                            role="status"
                        ></div>

                    </div>

                    <canvas
                        id="performanceChart"
                    ></canvas>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     RECENT RESULTS
====================================================== -->

<div class="results-card mb-4">

    <div class="card-heading">

        <h5>

            <i class="fas fa-history heading-icon"></i>

            Recent Exam Results

        </h5>

    </div>


    <div class="table-responsive">

        <table
            id="resultsTable"
            class="table table-hover"
            style="width:100%"
        >

            <thead>

                <tr>

                    <th>Student ID</th>

                    <th>Name</th>

                    <th>Exam Date</th>

                    <th>Score</th>

                    <th>Class</th>

                    <th>Status</th>

                </tr>

            </thead>


            <tbody>

                <?php foreach (
                    $recent_results
                    as $result
                ): ?>

                    <?php

                    $status =
                        strtolower(
                            $result['status'] ?? ''
                        );

                    if ($status === 'passed') {

                        $status_class =
                            'status-passed';

                    } elseif ($status === 'failed') {

                        $status_class =
                            'status-failed';

                    } else {

                        $status_class =
                            'status-pending';
                    }

                    ?>

                    <tr>

                        <td>
                            <?= htmlspecialchars(
                                $result['user_id']
                            ) ?>
                        </td>

                        <td>

                            <span class="student-name">

                                <?= htmlspecialchars(
                                    $result['full_name']
                                ) ?>

                            </span>

                        </td>

                        <td>
                            <?= date(
                                'M j, Y',
                                strtotime(
                                    $result['created_at']
                                )
                            ) ?>
                        </td>

                        <td>

                            <strong>
                                <?= htmlspecialchars(
                                    $result['score']
                                ) ?>%
                            </strong>

                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $result['class']
                            ) ?>
                        </td>

                        <td>

                            <span
                                class="status-badge <?= $status_class ?>"
                            >

                                <i class="fas
                                    <?= $status === 'passed'
                                        ? 'fa-check'
                                        : (
                                            $status === 'failed'
                                            ? 'fa-times'
                                            : 'fa-clock'
                                        )
                                    ?>
                                "></i>

                                <?= htmlspecialchars(
                                    ucfirst(
                                        $result['status']
                                    )
                                ) ?>

                            </span>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- =====================================================
     BOTTOM SECTION
====================================================== -->

<div class="row g-3">


    <!-- Quick Actions -->

    <div class="col-lg-6">

        <div class="content-card">

            <div class="card-heading">

                <h5>

                    <i class="fas fa-bolt heading-icon"></i>

                    Quick Actions

                </h5>

            </div>


            <div class="card-body-custom">


                <a
                    href="add_question.php"
                    class="quick-action"
                >

                    <div class="quick-action-content">

                        <div class="quick-action-icon">

                            <i class="fas fa-plus-circle"></i>

                        </div>

                        <div>

                            <h6>Add Questions</h6>

                            <small>
                                Current count:
                                <?= $stats['total_questions'] ?>
                            </small>

                        </div>

                    </div>

                    <i class="fas fa-chevron-right"></i>

                </a>


                <a
                    href="manage_students.php"
                    class="quick-action"
                >

                    <div class="quick-action-content">

                        <div class="quick-action-icon">

                            <i class="fas fa-user-plus"></i>

                        </div>

                        <div>

                            <h6>Manage Students</h6>

                            <small>
                                Active:
                                <?= $stats['active_students'] ?>
                            </small>

                        </div>

                    </div>

                    <i class="fas fa-chevron-right"></i>

                </a>


                <a
                    href="view_results.php"
                    class="quick-action"
                >

                    <div class="quick-action-content">

                        <div class="quick-action-icon">

                            <i class="fas fa-file-export"></i>

                        </div>

                        <div>

                            <h6>View Exam Results</h6>

                            <small>
                                Available:
                                <?= $stats['completed_exams'] ?>
                            </small>

                        </div>

                    </div>

                    <i class="fas fa-chevron-right"></i>

                </a>

            </div>

        </div>

    </div>


    <!-- Recent Activities -->

    <div class="col-lg-6">

        <div class="content-card">

            <div class="card-heading">

                <h5>

                    <i class="fas fa-info-circle heading-icon"></i>

                    Recent Activities

                </h5>

            </div>


            <div class="card-body-custom">

                <?php if (
                    !empty($recent_activities)
                ): ?>

                    <?php foreach (
                        $recent_activities
                        as $activity
                    ): ?>

                        <div class="activity-row">

                            <div class="activity-dot">

                                <i class="fas fa-info-circle"></i>

                            </div>

                            <div>

                                <p>
                                    <?= htmlspecialchars(
                                        $activity['activity']
                                    ) ?>
                                </p>

                                <small>

                                    <?= time_ago(
                                        $activity['created_at']
                                    ) ?>

                                    &bull;

                                    <?= htmlspecialchars(
                                        $activity['ip_address']
                                    ) ?>

                                </small>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="fas fa-bell-slash"></i>

                        <p>
                            No recent activities
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>
```

</main>

<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script src="../js/bootstrap.bundle.min.js"></script>

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/jquery.dataTables.min.js"></script>

<script src="../js/dataTables.bootstrap5.min.js"></script>

<script src="../js/chart.min.js"></script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        // =====================================================
        // SIDEBAR
        // =====================================================

        const body =
            document.body;

        const sidebar =
            document.getElementById('sidebar');

        const sidebarToggle =
            document.getElementById('sidebarToggle');

        const sidebarOverlay =
            document.getElementById('sidebarOverlay');


        function isMobile()
        {
            return window.innerWidth < 992;
        }


        sidebarToggle.addEventListener(
            'click',
            function () {

                if (isMobile()) {

                    sidebar.classList.toggle(
                        'mobile-open'
                    );

                    sidebarOverlay.classList.toggle(
                        'active'
                    );

                } else {

                    body.classList.toggle(
                        'sidebar-collapsed'
                    );

                }

            }
        );


        sidebarOverlay.addEventListener(
            'click',
            function () {

                sidebar.classList.remove(
                    'mobile-open'
                );

                sidebarOverlay.classList.remove(
                    'active'
                );

            }
        );


        window.addEventListener(
            'resize',
            function () {

                if (!isMobile()) {

                    sidebar.classList.remove(
                        'mobile-open'
                    );

                    sidebarOverlay.classList.remove(
                        'active'
                    );

                }

            }
        );


        // =====================================================
        // NOTIFICATIONS
        // =====================================================

        const notificationButton =
            document.getElementById(
                'notificationButton'
            );

        const notificationWrapper =
            document.getElementById(
                'notificationWrapper'
            );


        notificationButton.addEventListener(
            'click',
            function (event) {

                event.stopPropagation();

                notificationWrapper.classList.toggle(
                    'open'
                );

            }
        );


        document.addEventListener(
            'click',
            function (event) {

                if (
                    !notificationWrapper.contains(
                        event.target
                    )
                ) {

                    notificationWrapper.classList.remove(
                        'open'
                    );

                }

            }
        );


        // =====================================================
        // DATATABLE
        // =====================================================

        $('#resultsTable').DataTable({

            responsive: true,

            order: [
                [2, 'desc']
            ],

            pageLength: 10,

            language: {

                emptyTable:
                    'No exam results found',

                search:
                    'Search:'

            }

        });


        // =====================================================
        // PERFORMANCE CHART
        // =====================================================

        const chartCanvas =
            document.getElementById(
                'performanceChart'
            );

        const chartLoading =
            document.getElementById(
                'chartLoading'
            );


        const ctx =
            chartCanvas.getContext('2d');


        const performanceChart =
            new Chart(
                ctx,
                {

                    type: 'bar',

                    data: {

                        labels: [
                            'Loading...'
                        ],

                        datasets: [

                            {

                                label:
                                    'Average Score',

                                data: [
                                    0
                                ],

                                backgroundColor:
                                    'rgba(67, 97, 238, 0.72)',

                                borderColor:
                                    'rgba(67, 97, 238, 1)',

                                borderWidth: 1,

                                borderRadius: 6,

                                maxBarThickness: 45

                            }

                        ]

                    },

                    options: {

                        responsive: true,

                        maintainAspectRatio: false,

                        scales: {

                            y: {

                                beginAtZero: true,

                                max: 100,

                                grid: {
                                    color:
                                        '#eef2f7'
                                },

                                ticks: {

                                    font: {
                                        size: 10
                                    },

                                    callback:
                                        function(value) {
                                            return value + '%';
                                        }

                                }

                            },

                            x: {

                                grid: {
                                    display: false
                                },

                                ticks: {

                                    font: {
                                        size: 10
                                    }

                                }

                            }

                        },

                        plugins: {

                            legend: {
                                display: false
                            },

                            tooltip: {

                                callbacks: {

                                    label:
                                        function(context) {

                                            return (
                                                context.parsed.y
                                                .toFixed(1)
                                                + '%'
                                            );

                                        }

                                }

                            }

                        }

                    }

                }
            );


        // =====================================================
        // FETCH CHART DATA
        // =====================================================

        function fetchChartData(
            selectedClass = 'all'
        ) {

            chartLoading.style.display =
                'flex';


            fetch(
                `chart-data.php?class=${encodeURIComponent(
                    selectedClass
                )}`
            )

            .then(
                response => {

                    if (!response.ok) {
                        throw new Error(
                            'Unable to load chart data'
                        );
                    }

                    return response.json();
                }
            )

            .then(
                data => {

                    chartLoading.style.display =
                        'none';


                    if (
                        !data ||
                        !Array.isArray(data.labels) ||
                        data.labels.length === 0
                    ) {

                        performanceChart.data.labels = [
                            'No data available'
                        ];

                        performanceChart.data.datasets[0].data = [
                            0
                        ];

                    } else {

                        performanceChart.data.labels =
                            data.labels;

                        performanceChart.data.datasets[0].data =
                            data.data;

                    }


                    performanceChart.update();

                }
            )

            .catch(
                error => {

                    chartLoading.style.display =
                        'none';

                    console.error(
                        'Error fetching chart data:',
                        error
                    );

                    performanceChart.data.labels = [
                        'No data available'
                    ];

                    performanceChart.data.datasets[0].data = [
                        0
                    ];

                    performanceChart.update();

                }
            );

        }


        // =====================================================
        // CLASS FILTER
        // =====================================================

        document
            .getElementById('classSelector')
            .addEventListener(
                'change',
                function () {

                    fetchChartData(
                        this.value
                    );

                }
            );


        // =====================================================
        // INITIAL CHART LOAD
        // =====================================================

        fetchChartData();


        // =====================================================
        // ANIMATED COUNTERS
        // =====================================================

        document
            .querySelectorAll('.count')
            .forEach(
                function (element) {

                    const target =
                        parseInt(
                            element.dataset.value,
                            10
                        ) || 0;

                    if (target === 0) {

                        element.textContent = '0';

                        return;
                    }


                    let current = 0;

                    const duration = 800;

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
                                ) / duration,
                                1
                            );


                        current =
                            Math.floor(
                                progress *
                                target
                            );


                        element.textContent =
                            current.toLocaleString();


                        if (progress < 1) {

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

                }
            );

    }

);

</script>

</body>

</html>
