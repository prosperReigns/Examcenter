<?php
session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;


/* =========================================================
   AUTHENTICATION
========================================================= */

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    strtolower($_SESSION['user_role']) !== 'teacher'
) {
    error_log("Redirecting to login: No user_id or invalid role in session");
    header("Location: ../login.php?error=Not logged in");
    exit();
}


/* =========================================================
   DATABASE
========================================================= */

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $teacher_id = (int) $_SESSION['user_id'];


    /* =====================================================
       TEACHER PROFILE
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT username, last_name
        FROM teachers
        WHERE id = ?
    ");

    if (!$stmt) {
        throw new Exception("Unable to prepare teacher profile query.");
    }

    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();

    $teacher = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$teacher) {
        error_log("No teacher found for user_id={$teacher_id}");

        session_destroy();

        header("Location: ../login.php?error=Unauthorized");
        exit();
    }


    /* =====================================================
       CLASS / SUBJECT MAPPING
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT DISTINCT
            c.class_name,
            ts.subject
        FROM classes c
        JOIN academic_levels al
            ON al.id = c.academic_level_id
        JOIN subject_levels sl
            ON sl.class_level = al.class_group
        JOIN subjects s
            ON s.id = sl.subject_id
        JOIN teacher_subjects ts
            ON ts.subject = s.subject_name
        WHERE ts.teacher_id = ?
        ORDER BY c.class_name, ts.subject
    ");

    if (!$stmt) {
        throw new Exception("Unable to prepare class mapping query.");
    }

    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();

    $class_subject_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    $class_subjects = [];

    foreach ($class_subject_rows as $row) {
        $class_subjects[$row['class_name']][] = $row['subject'];
    }


    /* =====================================================
       ASSIGNED SUBJECTS
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT subject
        FROM teacher_subjects
        WHERE teacher_id = ?
        ORDER BY subject
    ");

    if (!$stmt) {
        throw new Exception("Unable to prepare assigned subjects query.");
    }

    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();

    $subject_result = $stmt->get_result();

    $assigned_subjects = [];

    while ($row = $subject_result->fetch_assoc()) {
        $assigned_subjects[] = $row['subject'];
    }

    $stmt->close();

    $no_subject_assigned = empty($assigned_subjects);

    if ($no_subject_assigned) {
        $assigned_subjects = ['__no_subject__'];
    }


    /* =====================================================
       FILTERS
    ===================================================== */

    $class_filter = trim($_GET['selected_class'] ?? '');
    $subject_filter = trim($_GET['selected_subject'] ?? '');
    $test_title_filter = trim($_GET['selected_title'] ?? '');
    $year_filter = trim($_GET['selected_year'] ?? '');
    $student_name_filter = trim($_GET['student_name'] ?? '');

    $error = '';
    $success = '';


    /* =====================================================
       PAGINATION
    ===================================================== */

    $results_per_page = 10;

    $current_page = isset($_GET['page']) && is_numeric($_GET['page'])
        ? (int) $_GET['page']
        : 1;

    if ($current_page < 1) {
        $current_page = 1;
    }

    $offset = ($current_page - 1) * $results_per_page;


    /* =====================================================
       COMMON SUBJECT CONDITION
    ===================================================== */

    $subject_conditions = [];

    foreach ($assigned_subjects as $subject) {
        $subject_conditions[] = 't.subject LIKE CONCAT(?, "%")';
    }

    $subject_where = '(' . implode(' OR ', $subject_conditions) . ')';


    /* =====================================================
       EXPORT RESULTS
    ===================================================== */

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['export_results'])
    ) {
        try {

            $export_title = trim($_POST['selected_title'] ?? '');
            $export_class = trim($_POST['selected_class'] ?? '');
            $export_subject = trim($_POST['selected_subject'] ?? '');
            $export_year = trim($_POST['selected_year'] ?? '');
            $export_student = trim($_POST['student_name'] ?? '');

            $export_query = "
                SELECT
                    r.*,
                    s.full_name AS student_name,
                    s.class AS student_class,
                    t.subject,
                    t.title AS test_title,
                    c.class_name AS test_class,
                    t.year
                FROM results r
                JOIN students s
                    ON r.user_id = s.id
                JOIN tests t
                    ON r.test_id = t.id
                JOIN academic_levels al
                    ON al.id = t.academic_level_id
                JOIN classes c
                    ON c.academic_level_id = al.id
                WHERE {$subject_where}
            ";

            $export_params = $assigned_subjects;
            $export_types = str_repeat('s', count($assigned_subjects));


            if ($export_title !== '') {
                $export_query .= " AND t.title = ?";
                $export_params[] = $export_title;
                $export_types .= 's';
            }

            if ($export_class !== '') {
                $export_query .= " AND c.class_name = ?";
                $export_params[] = $export_class;
                $export_types .= 's';
            }

            if ($export_subject !== '') {
                $export_query .= " AND t.subject = ?";
                $export_params[] = $export_subject;
                $export_types .= 's';
            }

            if ($export_year !== '') {
                $export_query .= " AND t.year = ?";
                $export_params[] = $export_year;
                $export_types .= 's';
            }

            if ($export_student !== '') {
                $export_query .= " AND s.full_name LIKE ?";
                $export_params[] = '%' . $export_student . '%';
                $export_types .= 's';
            }

            $export_query .= " ORDER BY r.created_at DESC";


            $stmt = $conn->prepare($export_query);

            if (!$stmt) {
                throw new Exception("Unable to prepare export query.");
            }

            $stmt->bind_param($export_types, ...$export_params);
            $stmt->execute();

            $export_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $stmt->close();


            /* =================================================
               CREATE WORD DOCUMENT
            ================================================= */

            $phpWord = new PhpWord();

            $section = $phpWord->addSection([
                'marginTop' => 720,
                'marginBottom' => 720,
                'marginLeft' => 720,
                'marginRight' => 720
            ]);

            $section->addTitle('Exam Results Report', 1);

            $section->addText(
                'Generated on: ' . date('F j, Y g:i A')
            );

            $section->addText(
                'Teacher: ' . $teacher['last_name']
            );

            if ($export_title !== '') {
                $section->addText('Test: ' . $export_title);
            }

            if ($export_class !== '') {
                $section->addText('Class: ' . $export_class);
            }

            if ($export_subject !== '') {
                $section->addText('Subject: ' . $export_subject);
            }

            if ($export_year !== '') {
                $section->addText('Year: ' . $export_year);
            }

            if ($export_student !== '') {
                $section->addText('Student: ' . $export_student);
            }


            $section->addText('');


            $table = $section->addTable([
                'borderSize' => 1,
                'borderColor' => '999999',
                'cellMargin' => 80
            ]);

            $table->addRow();

            $headers = [
                ['Student', 2000],
                ['Class', 1500],
                ['Test Title', 2000],
                ['Subject', 1500],
                ['Score', 1000],
                ['Percentage', 1000],
                ['Date', 1500],
                ['Year', 1000]
            ];

            foreach ($headers as [$header, $width]) {
                $table->addCell($width)->addText(
                    $header,
                    ['bold' => true]
                );
            }


            foreach ($export_results as $result) {

                $total_questions = (float) $result['total_questions'];
                $score = (float) $result['score'];

                $percentage = $total_questions > 0
                    ? round(($score / $total_questions) * 100, 2)
                    : 0;

                $table->addRow();

                $table->addCell(2000)->addText(
                    htmlspecialchars($result['student_name'])
                );

                $table->addCell(1500)->addText(
                    htmlspecialchars($result['test_class'])
                );

                $table->addCell(2000)->addText(
                    htmlspecialchars($result['test_title'])
                );

                $table->addCell(1500)->addText(
                    htmlspecialchars($result['subject'])
                );

                $table->addCell(1000)->addText(
                    $result['score'] . '/' . $result['total_questions']
                );

                $table->addCell(1000)->addText(
                    $percentage . '%'
                );

                $table->addCell(1500)->addText(
                    date(
                        'M j, Y g:i A',
                        strtotime($result['created_at'])
                    )
                );

                $table->addCell(1000)->addText(
                    htmlspecialchars($result['year'])
                );
            }


            /* =================================================
               SAVE FILE
            ================================================= */

            $filename = 'Exam_Results_' . date('Ymd_His') . '.docx';

            $temp_file = tempnam(
                sys_get_temp_dir(),
                'phpword'
            );

            $writer = IOFactory::createWriter(
                $phpWord,
                'Word2007'
            );

            $writer->save($temp_file);


            /* =================================================
               ACTIVITY LOG
            ================================================= */

            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $activity =
                "Teacher {$teacher['username']} exported results for " .
                ($export_title ?: 'all tests') .
                ($export_class ? " in {$export_class}" : '') .
                ($export_subject ? " ({$export_subject})" : '') .
                ($export_year ? " ({$export_year})" : '') .
                ($export_student ? " for {$export_student}" : '');

            $stmt_log = $conn->prepare("
                INSERT INTO activities_log
                (
                    activity,
                    teacher_id,
                    ip_address,
                    user_agent,
                    created_at
                )
                VALUES (?, ?, ?, ?, NOW())
            ");

            if ($stmt_log) {
                $stmt_log->bind_param(
                    "siss",
                    $activity,
                    $teacher_id,
                    $ip_address,
                    $user_agent
                );

                $stmt_log->execute();
                $stmt_log->close();
            }


            /* =================================================
               DOWNLOAD
            ================================================= */

            header(
                'Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            );

            header(
                'Content-Disposition: attachment; filename="' . $filename . '"'
            );

            header(
                'Content-Length: ' . filesize($temp_file)
            );

            readfile($temp_file);

            unlink($temp_file);

            exit;

        } catch (Exception $e) {

            error_log(
                "Export error: " . $e->getMessage()
            );

            $error = "Unable to export results. Please try again.";
        }
    }


    /* =========================================================
       RESULT QUERIES
    ========================================================= */

    $base_from = "
        FROM results r
        JOIN students s
            ON r.user_id = s.id
        JOIN tests t
            ON r.test_id = t.id
        JOIN academic_levels al
            ON al.id = t.academic_level_id
        JOIN classes c
            ON c.academic_level_id = al.id
    ";


    $count_query = "
        SELECT COUNT(*) AS total
        {$base_from}
        WHERE {$subject_where}
    ";

    $select_query = "
        SELECT
            r.*,
            s.full_name AS student_name,
            s.class AS student_class,
            t.subject,
            t.title AS test_title,
            c.class_name AS test_class,
            t.year
        {$base_from}
        WHERE {$subject_where}
    ";


    $params = $assigned_subjects;
    $types = str_repeat('s', count($assigned_subjects));


    /* =====================================================
       FILTER CONDITIONS
    ===================================================== */

    if ($test_title_filter !== '') {

        $count_query .= " AND t.title = ?";
        $select_query .= " AND t.title = ?";

        $params[] = $test_title_filter;
        $types .= 's';
    }


    if ($class_filter !== '') {

        $count_query .= " AND c.class_name = ?";
        $select_query .= " AND c.class_name = ?";

        $params[] = $class_filter;
        $types .= 's';
    }


    if ($subject_filter !== '') {

        $count_query .= " AND t.subject = ?";
        $select_query .= " AND t.subject = ?";

        $params[] = $subject_filter;
        $types .= 's';
    }


    if ($year_filter !== '') {

        $count_query .= " AND t.year = ?";
        $select_query .= " AND t.year = ?";

        $params[] = $year_filter;
        $types .= 's';
    }


    if ($student_name_filter !== '') {

        $count_query .= " AND s.full_name LIKE ?";
        $select_query .= " AND s.full_name LIKE ?";

        $params[] = '%' . $student_name_filter . '%';
        $types .= 's';
    }


    /* =====================================================
       TOTAL RESULTS
    ===================================================== */

    $stmt = $conn->prepare($count_query);

    if (!$stmt) {
        throw new Exception("Unable to prepare result count query.");
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $count_data = $stmt->get_result()->fetch_assoc();

    $total_results = (int) ($count_data['total'] ?? 0);

    $stmt->close();


    $total_pages = $total_results > 0
        ? (int) ceil($total_results / $results_per_page)
        : 0;


    if ($total_pages > 0 && $current_page > $total_pages) {

        $current_page = $total_pages;

        $offset =
            ($current_page - 1) *
            $results_per_page;
    }


    /* =====================================================
       FETCH CURRENT PAGE
    ===================================================== */

    $select_query .= "
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $params[] = $results_per_page;
    $params[] = $offset;

    $types .= 'ii';


    $stmt = $conn->prepare($select_query);

    if (!$stmt) {
        throw new Exception("Unable to prepare results query.");
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $results = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();


    /* =========================================================
       FILTER OPTIONS
    ========================================================= */

    /* Classes */

    $stmt = $conn->prepare("
        SELECT DISTINCT c.class_name
        FROM classes c
        JOIN tests t
            ON c.academic_level_id = t.academic_level_id
        JOIN results r
            ON t.id = r.test_id
        WHERE {$subject_where}
        ORDER BY c.class_name
    ");

    if ($stmt) {

        $subject_types =
            str_repeat('s', count($assigned_subjects));

        $stmt->bind_param(
            $subject_types,
            ...$assigned_subjects
        );

        $stmt->execute();

        $classes =
            $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmt->close();

    } else {

        $classes = [];
    }


    /* Years */

    $stmt = $conn->prepare("
        SELECT DISTINCT t.year
        FROM tests t
        JOIN results r
            ON t.id = r.test_id
        JOIN academic_levels al
            ON al.id = t.academic_level_id
        JOIN classes c
            ON c.academic_level_id = al.id
        WHERE {$subject_where}
        ORDER BY t.year DESC
    ");

    if ($stmt) {

        $subject_types =
            str_repeat('s', count($assigned_subjects));

        $stmt->bind_param(
            $subject_types,
            ...$assigned_subjects
        );

        $stmt->execute();

        $years =
            $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmt->close();

    } else {

        $years = [];
    }


    /* Test Titles */

    $stmt = $conn->prepare("
        SELECT DISTINCT t.title
        FROM tests t
        JOIN results r
            ON t.id = r.test_id
        WHERE {$subject_where}
        ORDER BY t.title
    ");

    if ($stmt) {

        $subject_types =
            str_repeat('s', count($assigned_subjects));

        $stmt->bind_param(
            $subject_types,
            ...$assigned_subjects
        );

        $stmt->execute();

        $test_titles =
            $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmt->close();

    } else {

        $test_titles = [];
    }


} catch (Exception $e) {

    error_log(
        "View results error: " . $e->getMessage()
    );

    die("System error");
}


$conn->close();


/* =========================================================
   HELPER VALUES
========================================================= */

$has_filters =
    $test_title_filter !== '' ||
    $class_filter !== '' ||
    $subject_filter !== '' ||
    $year_filter !== '' ||
    $student_name_filter !== '';

$start_result =
    $total_results > 0
        ? $offset + 1
        : 0;

$end_result =
    $total_results > 0
        ? min(
            $offset + count($results),
            $total_results
        )
        : 0;

$pagination_query = [
    'selected_class' => $class_filter,
    'selected_subject' => $subject_filter,
    'selected_title' => $test_title_filter,
    'selected_year' => $year_filter,
    'student_name' => $student_name_filter
];

$pagination_url = function ($page) use ($pagination_query) {

    $query = array_merge(
        ['page' => $page],
        $pagination_query
    );

    return '?' . http_build_query($query);
};
?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Exam Results | Examcenter</title>

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
    href="../css/admin-dashboard.css"
>

<link
    rel="stylesheet"
    href="../css/view_results.css"
>


<style>

    /* =====================================================
       PAGE FOUNDATION
    ===================================================== */

    :root {
        --primary: #4361ee;
        --primary-dark: #3451d1;
        --success: #16a34a;
        --warning: #f59e0b;
        --danger: #dc2626;
        --text: #1f2937;
        --muted: #6b7280;
        --border: #e5e7eb;
        --surface: #ffffff;
        --surface-soft: #f8fafc;
        --shadow:
            0 8px 30px rgba(15, 23, 42, 0.06);
    }


    body {
        background: #f4f7fb;
        color: var(--text);
    }


    .main-content {
        min-height: 100vh;
    }


    /* =====================================================
       HEADER
    ===================================================== */

    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 24px;
    }


    .page-title-wrapper {
        display: flex;
        align-items: center;
        gap: 14px;
    }


    .page-title-icon {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        background: rgba(67, 97, 238, 0.1);
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }


    .page-title {
        margin: 0;
        font-size: 25px;
        font-weight: 700;
        letter-spacing: -0.4px;
    }


    .page-subtitle {
        margin: 3px 0 0;
        color: var(--muted);
        font-size: 14px;
    }


    .sidebar-toggle {
        width: 44px;
        height: 44px;
        border: 0;
        border-radius: 12px;
        background: var(--primary);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 5px 15px rgba(67, 97, 238, .2);
    }


    /* =====================================================
       ALERTS
    ===================================================== */

    .custom-alert {
        border: 0;
        border-radius: 14px;
        box-shadow: var(--shadow);
    }


    /* =====================================================
       STAT CARDS
    ===================================================== */

    .result-stat {
        background: var(--surface);
        border: 1px solid rgba(229, 231, 235, .8);
        border-radius: 16px;
        padding: 18px;
        height: 100%;
        box-shadow: var(--shadow);
    }


    .result-stat-inner {
        display: flex;
        align-items: center;
        gap: 14px;
    }


    .stat-icon {
        width: 44px;
        height: 44px;
        flex: 0 0 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }


    .stat-icon.blue {
        background: rgba(67, 97, 238, .1);
        color: var(--primary);
    }


    .stat-icon.green {
        background: rgba(22, 163, 74, .1);
        color: var(--success);
    }


    .stat-icon.orange {
        background: rgba(245, 158, 11, .12);
        color: var(--warning);
    }


    .stat-label {
        color: var(--muted);
        font-size: 12px;
        margin-bottom: 2px;
    }


    .stat-value {
        font-size: 21px;
        font-weight: 700;
        margin: 0;
    }


    /* =====================================================
       FILTER CARD
    ===================================================== */

    .filter-card {
        background: var(--surface);
        border: 1px solid rgba(229, 231, 235, .8);
        border-radius: 18px;
        padding: 22px;
        box-shadow: var(--shadow);
    }


    .filter-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        margin-bottom: 20px;
    }


    .filter-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
        font-size: 16px;
        font-weight: 700;
    }


    .filter-title i {
        color: var(--primary);
    }


    .filter-description {
        color: var(--muted);
        font-size: 12px;
        margin: 4px 0 0 27px;
    }


    .form-label {
        font-size: 12px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 7px;
    }


    .form-control,
    .form-select {
        min-height: 44px;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 13px;
        box-shadow: none !important;
    }


    .form-control:focus,
    .form-select:focus {
        border-color: var(--primary);
    }


    .filter-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }


    .btn-modern {
        min-height: 44px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 600;
        padding: 0 17px;
    }


    .btn-primary-modern {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
    }


    .btn-primary-modern:hover {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
        color: #fff;
    }


    .export-btn {
        color: var(--success);
        border: 1px solid rgba(22, 163, 74, .25);
        background: rgba(22, 163, 74, .06);
    }


    .export-btn:hover {
        background: var(--success);
        color: #fff;
    }


    /* =====================================================
       RESULTS HEADER
    ===================================================== */

    .results-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        margin: 24px 0 14px;
    }


    .results-heading {
        margin: 0;
        font-size: 17px;
        font-weight: 700;
    }


    .results-count {
        color: var(--muted);
        font-size: 13px;
        font-weight: 400;
    }


    .active-filter {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: rgba(67, 97, 238, .08);
        color: var(--primary);
        border-radius: 20px;
        padding: 5px 9px;
        font-size: 11px;
        font-weight: 600;
        margin: 3px;
    }


    /* =====================================================
       TABLE
    ===================================================== */

    .results-table {
        background: var(--surface);
        border: 1px solid rgba(229, 231, 235, .8);
        border-radius: 18px;
        overflow: hidden;
        box-shadow: var(--shadow);
    }


    .results-table table {
        margin: 0;
        min-width: 1050px;
    }


    .results-table thead th {
        background: #f8fafc !important;
        color: #64748b !important;
        border-bottom: 1px solid var(--border);
        border-top: 0;
        padding: 14px 16px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .45px;
        white-space: nowrap;
    }


    .results-table tbody td {
        padding: 15px 16px;
        vertical-align: middle;
        border-color: #f0f2f5;
        font-size: 13px;
        color: #374151;
    }


    .results-table tbody tr {
        transition: background .15s ease;
    }


    .results-table tbody tr:hover {
        background: #fafbff;
    }


    .student-name {
        font-weight: 700;
        color: #1f2937;
    }


    .student-avatar {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        background: rgba(67, 97, 238, .1);
        color: var(--primary);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-right: 9px;
        font-weight: 700;
        font-size: 12px;
    }


    .modern-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 7px;
        padding: 5px 8px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }


    .class-badge {
        background: rgba(67, 97, 238, .08);
        color: var(--primary);
    }


    .subject-badge {
        background: #f1f5f9;
        color: #475569;
    }


    .score {
        font-weight: 700;
        color: #334155;
    }


    .percentage-cell {
        font-weight: 800;
    }


    .percentage-cell.high {
        color: #198754;
    }


    .percentage-cell.medium {
        color: #b7791f;
    }


    .percentage-cell.low {
         color: #dc3545;
    }


    .percentage-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 68px;
    padding: 6px 10px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 800;
    position: relative;
    background: #fff;
    border: 1px solid currentColor;
}

    /* Light tinted background */
    .percentage-cell.high .percentage-pill {
        background: #e8f7ef;
        color: #198754;
        border-color: #b7e4c7;
    }

    .percentage-cell.medium .percentage-pill {
        background: #fff8e1;
        color: #946200;
        border-color: #f1d58a;
    }

    .percentage-cell.low .percentage-pill {
        background: #fdecec;
        color: #dc3545;
        border-color: #f3b5b5;
    }


    .percentage-pill span {
        position: relative;
    }


    .result-date {
        color: #64748b;
        white-space: nowrap;
        font-size: 12px;
    }


    /* =====================================================
       EMPTY STATE
    ===================================================== */

    .empty-state {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 65px 25px;
        text-align: center;
        box-shadow: var(--shadow);
    }


    .empty-icon {
        width: 74px;
        height: 74px;
        margin: 0 auto 18px;
        border-radius: 22px;
        background: #f1f5f9;
        color: #94a3b8;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
    }


    .empty-state h4 {
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 7px;
    }


    .empty-state p {
        color: var(--muted);
        font-size: 13px;
        margin: 0;
    }


    /* =====================================================
       PAGINATION
    ===================================================== */

    .pagination-wrapper {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        margin-top: 18px;
        flex-wrap: wrap;
    }


    .pagination-info {
        color: var(--muted);
        font-size: 12px;
    }


    .pagination {
        margin: 0;
        gap: 5px;
    }


    .pagination .page-item {
        margin: 0;
    }


    .pagination .page-link {
        min-width: 36px;
        height: 36px;
        border: 1px solid var(--border);
        border-radius: 8px !important;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #475569;
        font-size: 12px;
        font-weight: 600;
        background: #fff;
    }


    .pagination .page-link:hover {
        color: var(--primary);
        border-color: rgba(67, 97, 238, .3);
        background: rgba(67, 97, 238, .04);
    }


    .pagination .page-item.active .page-link {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
    }


    /* =====================================================
       RESPONSIVE
    ===================================================== */

    @media (max-width: 991px) {

        .page-header {
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 21px;
        }

        .results-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .filter-heading {
            align-items: flex-start;
            flex-direction: column;
        }

    }


    @media (max-width: 575px) {

        .page-title-icon {
            width: 42px;
            height: 42px;
        }

        .page-title {
            font-size: 19px;
        }

        .page-subtitle {
            font-size: 12px;
        }

        .filter-card {
            padding: 16px;
            border-radius: 14px;
        }

        .results-table {
            border-radius: 14px;
        }

        .pagination-wrapper {
            justify-content: center;
        }

    }

</style>

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================= -->

<div class="sidebar">

<div class="sidebar-brand">

    <h3>
        <i class="fas fa-graduation-cap me-2"></i>
        Examcenter
    </h3>

    <div class="admin-info">

        <small>Welcome back,</small>

        <h6>
            <?php echo htmlspecialchars($teacher['last_name']); ?>
        </h6>

    </div>

</div>


<div class="sidebar-menu mt-4">

    <a href="dashboard.php">
        <i class="fas fa-tachometer-alt"></i>
        Dashboard
    </a>

    <a href="add_question.php">
        <i class="fas fa-plus-circle"></i>
        Add Questions
    </a>

    <a href="bank.php">
        <i class="fas fa-database"></i>
        Question Bank
    </a>

    <a href="view_questions.php">
        <i class="fas fa-list"></i>
        View Questions
    </a>

    <a href="manage_test.php">
        <i class="fas fa-list"></i>
        Manage Test
    </a>

    <a
        href="view_results.php"
        class="active"
    >
        <i class="fas fa-chart-bar"></i>
        Exam Results
    </a>

    <a href="manage_students.php">
        <i class="fas fa-users"></i>
        Manage Students
    </a>

    <a href="settings.php">
        <i class="fas fa-cog"></i>
        Settings
    </a>

    <a href="my-profile.php">
        <i class="fas fa-user"></i>
        My Profile
    </a>

    <a
        href="logout.php"
        class="logout-btn"
    >
        <i class="fas fa-sign-out-alt"></i>
        Logout
    </a>

</div>


</div>

<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<div class="main-content">

<!-- =====================================================
     HEADER
====================================================== -->

<div class="page-header">

    <div class="page-title-wrapper">

        <div class="page-title-icon">
            <i class="fas fa-chart-line"></i>
        </div>

        <div>

            <h1 class="page-title">
                Exam Results
            </h1>

            <p class="page-subtitle">
                Review, filter and export your students' examination results.
            </p>

        </div>

    </div>


    <button
        class="sidebar-toggle d-lg-none"
        id="sidebarToggle"
        type="button"
        aria-label="Toggle sidebar"
    >
        <i class="fas fa-bars"></i>
    </button>

</div>


<!-- =====================================================
     ALERTS
====================================================== -->

<?php if ($error): ?>

    <div
        class="alert alert-danger alert-dismissible fade show custom-alert"
        role="alert"
    >
        <i class="fas fa-exclamation-circle me-2"></i>

        <?php echo htmlspecialchars($error); ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
        ></button>

    </div>

<?php endif; ?>


<?php if ($success): ?>

    <div
        class="alert alert-success alert-dismissible fade show custom-alert"
        role="alert"
    >
        <i class="fas fa-check-circle me-2"></i>

        <?php echo htmlspecialchars($success); ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
        ></button>

    </div>

<?php endif; ?>


<div class="container-fluid px-0">


    <!-- =================================================
         QUICK STATS
    ================================================== -->

    <div class="row g-3 mb-4">

        <div class="col-md-4">

            <div class="result-stat">

                <div class="result-stat-inner">

                    <div class="stat-icon blue">
                        <i class="fas fa-file-alt"></i>
                    </div>

                    <div>

                        <div class="stat-label">
                            Total Results
                        </div>

                        <p class="stat-value">
                            <?php echo number_format($total_results); ?>
                        </p>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="result-stat">

                <div class="result-stat-inner">

                    <div class="stat-icon green">
                        <i class="fas fa-book"></i>
                    </div>

                    <div>

                        <div class="stat-label">
                            Assigned Subjects
                        </div>

                        <p class="stat-value">
                            <?php
                            echo $no_subject_assigned
                                ? '0'
                                : count($assigned_subjects);
                            ?>
                        </p>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="result-stat">

                <div class="result-stat-inner">

                    <div class="stat-icon orange">
                        <i class="fas fa-calendar-alt"></i>
                    </div>

                    <div>

                        <div class="stat-label">
                            Current Page
                        </div>

                        <p class="stat-value">
                            <?php
                            echo $total_pages > 0
                                ? $current_page . ' / ' . $total_pages
                                : '0';
                            ?>
                        </p>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =================================================
         NO SUBJECT WARNING
    ================================================== -->

    <?php if ($no_subject_assigned): ?>

        <div class="alert alert-warning custom-alert mb-4">

            <i class="fas fa-info-circle me-2"></i>

            You currently have no subjects assigned to you.
            Please contact your administrator.

        </div>

    <?php endif; ?>


    <!-- =================================================
         FILTER CARD
    ================================================== -->

    <div class="filter-card">

        <div class="filter-heading">

            <div>

                <h5 class="filter-title">

                    <i class="fas fa-sliders-h"></i>

                    Filter Results

                </h5>

                <div class="filter-description">
                    Narrow down examination results by test, class,
                    subject, year or student.
                </div>

            </div>


            <?php if ($has_filters): ?>

                <a
                    href="view_results.php"
                    class="btn btn-sm btn-outline-secondary"
                >
                    <i class="fas fa-times me-1"></i>
                    Clear Filters
                </a>

            <?php endif; ?>

        </div>


        <form
            method="GET"
            action=""
        >

            <div class="row g-3">


                <!-- TEST -->

                <div class="col-xl-3 col-md-6">

                    <label class="form-label">
                        Test Title
                    </label>

                    <select
                        class="form-select"
                        name="selected_title"
                    >

                        <option value="">
                            All Tests
                        </option>

                        <?php foreach ($test_titles as $title): ?>

                            <option
                                value="<?php echo htmlspecialchars($title['title']); ?>"
                                <?php echo $test_title_filter === $title['title'] ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($title['title']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- CLASS -->

                <div class="col-xl-3 col-md-6">

                    <label class="form-label">
                        Class
                    </label>

                    <select
                        class="form-select"
                        name="selected_class"
                        id="selectedClass"
                    >

                        <option value="">
                            All Classes
                        </option>

                        <?php foreach ($classes as $class): ?>

                            <option
                                value="<?php echo htmlspecialchars($class['class_name']); ?>"
                                <?php echo $class_filter === $class['class_name'] ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- SUBJECT -->

                <div class="col-xl-3 col-md-6">

                    <label class="form-label">
                        Subject
                    </label>

                    <select
                        class="form-select"
                        name="selected_subject"
                        id="selectedSubject"
                    >

                        <option value="">
                            All Subjects
                        </option>

                        <?php foreach ($assigned_subjects as $subject): ?>

                            <?php if ($subject === '__no_subject__') continue; ?>

                            <option
                                value="<?php echo htmlspecialchars($subject); ?>"
                                <?php echo $subject_filter === $subject ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($subject); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- YEAR -->

                <div class="col-xl-3 col-md-6">

                    <label class="form-label">
                        Year
                    </label>

                    <select
                        class="form-select"
                        name="selected_year"
                    >

                        <option value="">
                            All Years
                        </option>

                        <?php foreach ($years as $year): ?>

                            <option
                                value="<?php echo htmlspecialchars($year['year']); ?>"
                                <?php echo $year_filter == $year['year'] ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($year['year']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- STUDENT -->

                <div class="col-xl-6">

                    <label class="form-label">
                        Student Name
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        name="student_name"
                        value="<?php echo htmlspecialchars($student_name_filter); ?>"
                        placeholder="Search by student name..."
                    >

                </div>


                <!-- ACTIONS -->

                <div class="col-xl-6 d-flex align-items-end">

                    <div class="filter-actions w-100">

                        <button
                            type="submit"
                            class="btn btn-modern btn-primary-modern flex-grow-1"
                        >
                            <i class="fas fa-search me-2"></i>
                            Apply Filters
                        </button>

                    </div>

                </div>

            </div>

        </form>


        <!-- EXPORT -->

        <?php if ($total_results > 0): ?>

            <div
                class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-4 pt-4 border-top"
            >

                <div class="small text-muted">

                    <i class="fas fa-file-export me-1"></i>

                    Export the results matching your current filters.

                </div>


                <form
                    method="POST"
                    action=""
                >

                    <input
                        type="hidden"
                        name="selected_title"
                        value="<?php echo htmlspecialchars($test_title_filter); ?>"
                    >

                    <input
                        type="hidden"
                        name="selected_class"
                        value="<?php echo htmlspecialchars($class_filter); ?>"
                    >

                    <input
                        type="hidden"
                        name="selected_subject"
                        value="<?php echo htmlspecialchars($subject_filter); ?>"
                    >

                    <input
                        type="hidden"
                        name="selected_year"
                        value="<?php echo htmlspecialchars($year_filter); ?>"
                    >

                    <input
                        type="hidden"
                        name="student_name"
                        value="<?php echo htmlspecialchars($student_name_filter); ?>"
                    >

                    <input
                        type="hidden"
                        name="export_results"
                        value="1"
                    >


                    <button
                        type="submit"
                        class="btn btn-modern export-btn"
                    >
                        <i class="fas fa-file-word me-2"></i>
                        Export to Word
                    </button>

                </form>

            </div>

        <?php endif; ?>

    </div>


    <!-- =================================================
         RESULTS HEADER
    ================================================== -->

    <div class="results-header">

        <div>

            <h5 class="results-heading">

                Examination Results

                <span class="results-count">

                    <?php if ($total_results > 0): ?>

                        · Showing
                        <?php echo $start_result; ?>–<?php echo $end_result; ?>
                        of
                        <?php echo number_format($total_results); ?>

                    <?php else: ?>

                        · No results

                    <?php endif; ?>

                </span>

            </h5>


            <?php if ($has_filters): ?>

                <div class="mt-2">

                    <?php if ($test_title_filter): ?>

                        <span class="active-filter">
                            <i class="fas fa-file-alt"></i>
                            <?php echo htmlspecialchars($test_title_filter); ?>
                        </span>

                    <?php endif; ?>


                    <?php if ($class_filter): ?>

                        <span class="active-filter">
                            <i class="fas fa-users"></i>
                            <?php echo htmlspecialchars($class_filter); ?>
                        </span>

                    <?php endif; ?>


                    <?php if ($subject_filter): ?>

                        <span class="active-filter">
                            <i class="fas fa-book"></i>
                            <?php echo htmlspecialchars($subject_filter); ?>
                        </span>

                    <?php endif; ?>


                    <?php if ($year_filter): ?>

                        <span class="active-filter">
                            <i class="fas fa-calendar"></i>
                            <?php echo htmlspecialchars($year_filter); ?>
                        </span>

                    <?php endif; ?>


                    <?php if ($student_name_filter): ?>

                        <span class="active-filter">
                            <i class="fas fa-user"></i>
                            <?php echo htmlspecialchars($student_name_filter); ?>
                        </span>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =================================================
         RESULTS TABLE
    ================================================== -->

    <?php if (!empty($results)): ?>

        <div class="results-table table-responsive">

            <table class="table table-hover align-middle">

                <thead>

                    <tr>

                        <th>
                            Student
                        </th>

                        <th>
                            Class
                        </th>

                        <th>
                            Test
                        </th>

                        <th>
                            Subject
                        </th>

                        <th>
                            Score
                        </th>

                        <th>
                            Percentage
                        </th>

                        <th>
                            Date
                        </th>

                        <th>
                            Year
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php foreach ($results as $result): ?>

                    <?php

                    $score = (float) $result['score'];

                    $total_questions =
                        (float) $result['total_questions'];

                    $percentage =
                        $total_questions > 0
                            ? round(
                                ($score / $total_questions) * 100,
                                2
                            )
                            : 0;

                    $percentage_class =
                        $percentage >= 70
                            ? 'high'
                            : (
                                $percentage >= 50
                                    ? 'medium'
                                    : 'low'
                            );


                    $student_name =
                        trim($result['student_name']);

                    $student_initial =
                        strtoupper(
                            substr(
                                $student_name,
                                0,
                                1
                            )
                        );

                    ?>

                    <tr>


                        <!-- STUDENT -->

                        <td>

                            <div class="d-flex align-items-center">

                                <span class="student-avatar">
                                    <?php echo htmlspecialchars($student_initial); ?>
                                </span>

                                <span class="student-name">
                                    <?php echo htmlspecialchars($student_name); ?>
                                </span>

                            </div>

                        </td>


                        <!-- CLASS -->

                        <td>

                            <span class="modern-badge class-badge">

                                <i class="fas fa-users me-1"></i>

                                <?php
                                echo htmlspecialchars(
                                    $result['test_class']
                                );
                                ?>

                            </span>

                        </td>


                        <!-- TEST -->

                        <td>

                            <span class="fw-semibold">

                                <?php
                                echo htmlspecialchars(
                                    $result['test_title']
                                );
                                ?>

                            </span>

                        </td>


                        <!-- SUBJECT -->

                        <td>

                            <span class="modern-badge subject-badge">

                                <?php
                                echo htmlspecialchars(
                                    $result['subject']
                                );
                                ?>

                            </span>

                        </td>


                        <!-- SCORE -->

                        <td>

                            <span class="score">

                                <?php
                                echo htmlspecialchars(
                                    $result['score']
                                );
                                ?>

                                /

                                <?php
                                echo htmlspecialchars(
                                    $result['total_questions']
                                );
                                ?>

                            </span>

                        </td>


                        <!-- PERCENTAGE -->

                        <td
                            class="percentage-cell <?php echo $percentage_class; ?>"
                        >

                            <span class="percentage-pill">

                                <span>
                                    <?php echo $percentage; ?>%
                                </span>

                            </span>

                        </td>


                        <!-- DATE -->

                        <td>

                            <span class="result-date">

                                <i class="far fa-clock me-1"></i>

                                <?php

                                echo date(
                                    'M j, Y g:i A',
                                    strtotime(
                                        $result['created_at']
                                    )
                                );

                                ?>

                            </span>

                        </td>


                        <!-- YEAR -->

                        <td>

                            <span class="fw-semibold">

                                <?php
                                echo htmlspecialchars(
                                    $result['year']
                                );
                                ?>

                            </span>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>


        <!-- =================================================
             PAGINATION
        ================================================== -->

        <?php if ($total_pages > 1): ?>

            <div class="pagination-wrapper">

                <div class="pagination-info">

                    Showing
                    <strong><?php echo $start_result; ?></strong>
                    –
                    <strong><?php echo $end_result; ?></strong>
                    of
                    <strong><?php echo number_format($total_results); ?></strong>
                    results

                </div>


                <nav aria-label="Results pagination">

                    <ul class="pagination">


                        <!-- PREVIOUS -->

                        <li
                            class="page-item <?php echo $current_page <= 1 ? 'disabled' : ''; ?>"
                        >

                            <?php if ($current_page > 1): ?>

                                <a
                                    class="page-link"
                                    href="<?php echo $pagination_url($current_page - 1); ?>"
                                    aria-label="Previous"
                                >
                                    <i class="fas fa-chevron-left"></i>
                                </a>

                            <?php else: ?>

                                <span class="page-link">
                                    <i class="fas fa-chevron-left"></i>
                                </span>

                            <?php endif; ?>

                        </li>


                        <?php

                        $start_page =
                            max(
                                1,
                                $current_page - 2
                            );

                        $end_page =
                            min(
                                $total_pages,
                                $current_page + 2
                            );

                        ?>


                        <!-- FIRST PAGE -->

                        <?php if ($start_page > 1): ?>

                            <li class="page-item">

                                <a
                                    class="page-link"
                                    href="<?php echo $pagination_url(1); ?>"
                                >
                                    1
                                </a>

                            </li>


                            <?php if ($start_page > 2): ?>

                                <li class="page-item disabled">

                                    <span class="page-link">
                                        ...
                                    </span>

                                </li>

                            <?php endif; ?>

                        <?php endif; ?>


                        <!-- PAGE NUMBERS -->

                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>

                            <li
                                class="page-item <?php echo $i === $current_page ? 'active' : ''; ?>"
                            >

                                <a
                                    class="page-link"
                                    href="<?php echo $pagination_url($i); ?>"
                                >
                                    <?php echo $i; ?>
                                </a>

                            </li>

                        <?php endfor; ?>


                        <!-- LAST PAGE -->

                        <?php if ($end_page < $total_pages): ?>

                            <?php if ($end_page < $total_pages - 1): ?>

                                <li class="page-item disabled">

                                    <span class="page-link">
                                        ...
                                    </span>

                                </li>

                            <?php endif; ?>


                            <li class="page-item">

                                <a
                                    class="page-link"
                                    href="<?php echo $pagination_url($total_pages); ?>"
                                >
                                    <?php echo $total_pages; ?>
                                </a>

                            </li>

                        <?php endif; ?>


                        <!-- NEXT -->

                        <li
                            class="page-item <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>"
                        >

                            <?php if ($current_page < $total_pages): ?>

                                <a
                                    class="page-link"
                                    href="<?php echo $pagination_url($current_page + 1); ?>"
                                    aria-label="Next"
                                >
                                    <i class="fas fa-chevron-right"></i>
                                </a>

                            <?php else: ?>

                                <span class="page-link">
                                    <i class="fas fa-chevron-right"></i>
                                </span>

                            <?php endif; ?>

                        </li>


                    </ul>

                </nav>

            </div>

        <?php endif; ?>


    <?php else: ?>


        <!-- =================================================
             EMPTY STATE
        ================================================== -->

        <div class="empty-state">

            <div class="empty-icon">

                <i class="fas fa-chart-bar"></i>

            </div>


            <h4>
                No Results Found
            </h4>


            <p>

                <?php if ($has_filters): ?>

                    No examination results match your current filters.
                    Try adjusting your search criteria.

                <?php else: ?>

                    Examination results will appear here once
                    students complete tests.

                <?php endif; ?>

            </p>


            <?php if ($has_filters): ?>

                <a
                    href="view_results.php"
                    class="btn btn-sm btn-outline-primary mt-4"
                >

                    <i class="fas fa-times me-1"></i>

                    Clear Filters

                </a>

            <?php endif; ?>

        </div>

    <?php endif; ?>


</div>

</div>

<!-- =========================================================
     SCRIPTS
========================================================= -->

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script>

$(document).ready(function () {


    /* =====================================================
       SIDEBAR TOGGLE
    ===================================================== */

    $('#sidebarToggle').on('click', function () {

        $('.sidebar').toggleClass('active');

    });


    /* =====================================================
       CLASS / SUBJECT MAPPING
    ===================================================== */

    const classSubjectMapping =
        <?php echo json_encode($class_subjects); ?>;

    const assignedSubjects =
        <?php echo json_encode($assigned_subjects); ?>;


    const classSelect =
        document.getElementById('selectedClass');

    const subjectSelect =
        document.getElementById('selectedSubject');


    if (classSelect && subjectSelect) {


        function updateSubjects(selectedSubject = '') {

            const selectedClass =
                classSelect.value;


            subjectSelect.innerHTML =
                '<option value="">All Subjects</option>';


            let subjects = [];


            if (
                selectedClass &&
                classSubjectMapping[selectedClass]
            ) {

                subjects =
                    classSubjectMapping[selectedClass];

            } else {

                subjects =
                    assignedSubjects.filter(
                        subject =>
                            subject !== '__no_subject__'
                    );

            }


            subjects = [
                ...new Set(
                    subjects.filter(
                        subject =>
                            assignedSubjects.includes(subject)
                    )
                )
            ];


            subjects.forEach(function (subject) {

                const option =
                    document.createElement('option');

                option.value = subject;

                option.textContent = subject;

                if (subject === selectedSubject) {
                    option.selected = true;
                }

                subjectSelect.appendChild(option);

            });

        }


        classSelect.addEventListener(
            'change',
            function () {

                updateSubjects();

            }
        );


        updateSubjects(
            <?php echo json_encode($subject_filter); ?>
        );

    }


    /* =====================================================
       CLOSE SIDEBAR WHEN CLICKING OUTSIDE ON MOBILE
    ===================================================== */

    $(document).on('click', function (event) {

        if (
            window.innerWidth <= 991 &&
            $('.sidebar').hasClass('active') &&
            !$(event.target).closest('.sidebar, #sidebarToggle').length
        ) {

            $('.sidebar').removeClass('active');

        }

    });


});

</script>

</body>

</html>
