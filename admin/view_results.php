<?php
session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

/* ============================================================
   AUTHENTICATION
   ============================================================ */

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    strtolower($_SESSION['user_role']) !== 'admin'
) {
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

/* ============================================================
   INITIALIZE
   ============================================================ */

$error = '';
$admin = null;
$results = [];
$classes = [];
$years = [];
$test_titles = [];
$available_subjects = [];

$results_per_page = 10;

$current_page = isset($_GET['page']) && is_numeric($_GET['page'])
    ? max(1, (int)$_GET['page'])
    : 1;

/* ============================================================
   FILTERS
   ============================================================ */

$class_filter = trim($_GET['selected_class'] ?? '');
$subject_filter = trim($_GET['selected_subject'] ?? '');
$test_title_filter = trim($_GET['selected_title'] ?? '');
$year_filter = trim($_GET['selected_year'] ?? '');
$student_name_filter = trim($_GET['student_name'] ?? '');

/* ============================================================
   DATABASE
   ============================================================ */

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    /* --------------------------------------------------------
       ADMIN PROFILE
       -------------------------------------------------------- */

    $admin_id = (int)$_SESSION['user_id'];

    $stmt = $conn->prepare("
        SELECT username
        FROM admins
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Unable to prepare admin profile query.");
    }

    $stmt->bind_param("i", $admin_id);
    $stmt->execute();

    $admin = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$admin) {
        session_destroy();

        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    /* ========================================================
       EXPORT RESULTS TO WORD
       ======================================================== */

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_results'])) {

        $export_title = trim($_POST['selected_title'] ?? '');
        $export_class = trim($_POST['selected_class'] ?? '');
        $export_subject = trim($_POST['selected_subject'] ?? '');
        $export_year = trim($_POST['selected_year'] ?? '');
        $export_student = trim($_POST['student_name'] ?? '');

        $export_query = "
            SELECT
                r.*,
                s.full_name AS student_name,
                c.class_name AS student_class,
                t.subject,
                t.title AS test_title,
                t.year
            FROM results r
            INNER JOIN students s
                ON r.user_id = s.id
            INNER JOIN tests t
                ON r.test_id = t.id
            INNER JOIN classes c
                ON s.class = c.id
            WHERE 1 = 1
        ";

        $export_params = [];
        $export_types = '';

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
            $export_params[] = "%{$export_student}%";
            $export_types .= 's';
        }

        $export_query .= " ORDER BY r.created_at DESC";

        $stmt = $conn->prepare($export_query);

        if (!$stmt) {
            throw new Exception("Unable to prepare export query.");
        }

        if (!empty($export_params)) {
            $stmt->bind_param($export_types, ...$export_params);
        }

        $stmt->execute();

        $export_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmt->close();

        /* ----------------------------------------------------
           CREATE WORD DOCUMENT
           ---------------------------------------------------- */

        $phpWord = new PhpWord();

        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);

        $section = $phpWord->addSection([
            'marginTop' => 900,
            'marginBottom' => 900,
            'marginLeft' => 900,
            'marginRight' => 900,
        ]);

        $section->addTitle('Exam Results Report', 1);

        $section->addText(
            'Generated on: ' . date('F j, Y g:i A'),
            [
                'size' => 9,
                'color' => '666666'
            ]
        );

        $section->addTextBreak();

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

        $section->addTextBreak();

        $table = $section->addTable([
            'borderSize' => 1,
            'borderColor' => 'CCCCCC',
            'cellMargin' => 80,
        ]);

        $header_style = [
            'bold' => true,
            'color' => 'FFFFFF',
            'size' => 9,
        ];

        $header_cell_style = [
            'bgColor' => '4361EE',
        ];

        $headers = [
            ['Student', 2000],
            ['Class', 1200],
            ['Test Title', 2000],
            ['Subject', 1300],
            ['Score', 1000],
            ['Percentage', 1000],
            ['Date', 1700],
            ['Year', 1000],
        ];

        $table->addRow();

        foreach ($headers as [$label, $width]) {
            $table->addCell($width, $header_cell_style)
                ->addText($label, $header_style);
        }

        foreach ($export_results as $result) {

            $total_questions = (float)$result['total_questions'];
            $score = (float)$result['score'];

            $percentage = $total_questions > 0
                ? round(($score / $total_questions) * 100, 2)
                : 0;

            $table->addRow();

            $table->addCell(2000)->addText(
                htmlspecialchars($result['student_name'])
            );

            $table->addCell(1200)->addText(
                htmlspecialchars($result['student_class'])
            );

            $table->addCell(2000)->addText(
                htmlspecialchars($result['test_title'])
            );

            $table->addCell(1300)->addText(
                htmlspecialchars($result['subject'])
            );

            $table->addCell(1000)->addText(
                $score . '/' . $total_questions
            );

            $table->addCell(1000)->addText(
                $percentage . '%'
            );

            $table->addCell(1700)->addText(
                date('M j, Y g:i A', strtotime($result['created_at']))
            );

            $table->addCell(1000)->addText(
                htmlspecialchars($result['year'])
            );
        }

        $filename = 'Exam_Results_' . date('Ymd_His') . '.docx';

        $temp_file = tempnam(sys_get_temp_dir(), 'examcenter_results_');

        $writer = IOFactory::createWriter($phpWord, 'Word2007');

        $writer->save($temp_file);

        header(
            'Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        header(
            'Content-Disposition: attachment; filename="' . $filename . '"'
        );

        header('Content-Length: ' . filesize($temp_file));

        readfile($temp_file);

        unlink($temp_file);

        exit();
    }

    /* ========================================================
       BASE QUERY
       ======================================================== */

    $count_query = "
        SELECT COUNT(*) AS total
        FROM results r
        INNER JOIN students s
            ON r.user_id = s.id
        INNER JOIN tests t
            ON r.test_id = t.id
        INNER JOIN classes c
            ON s.class = c.id
        WHERE 1 = 1
    ";

    $select_query = "
        SELECT
            r.*,
            s.full_name AS student_name,
            c.class_name AS student_class,
            t.subject,
            t.title AS test_title,
            t.year
        FROM results r
        INNER JOIN students s
            ON r.user_id = s.id
        INNER JOIN tests t
            ON r.test_id = t.id
        INNER JOIN classes c
            ON s.class = c.id
        WHERE 1 = 1
    ";

    $params = [];
    $types = '';

    /* --------------------------------------------------------
       APPLY FILTERS
       -------------------------------------------------------- */

    if ($test_title_filter !== '') {

        $condition = " AND t.title = ?";

        $count_query .= $condition;
        $select_query .= $condition;

        $params[] = $test_title_filter;
        $types .= 's';
    }

    if ($class_filter !== '') {

        $condition = " AND c.class_name = ?";

        $count_query .= $condition;
        $select_query .= $condition;

        $params[] = $class_filter;
        $types .= 's';
    }

    if ($subject_filter !== '') {

        $condition = " AND t.subject = ?";

        $count_query .= $condition;
        $select_query .= $condition;

        $params[] = $subject_filter;
        $types .= 's';
    }

    if ($year_filter !== '') {

        $condition = " AND t.year = ?";

        $count_query .= $condition;
        $select_query .= $condition;

        $params[] = $year_filter;
        $types .= 's';
    }

    if ($student_name_filter !== '') {

        $condition = " AND s.full_name LIKE ?";

        $count_query .= $condition;
        $select_query .= $condition;

        $params[] = "%{$student_name_filter}%";
        $types .= 's';
    }

    /* ========================================================
       COUNT RESULTS
       ======================================================== */

    $stmt = $conn->prepare($count_query);

    if (!$stmt) {
        throw new Exception("Unable to prepare count query.");
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $count_result = $stmt->get_result()->fetch_assoc();

    $total_results = (int)($count_result['total'] ?? 0);

    $stmt->close();

    $total_pages = $total_results > 0
        ? (int)ceil($total_results / $results_per_page)
        : 1;

    if ($current_page > $total_pages) {
        $current_page = $total_pages;
    }

    $offset = ($current_page - 1) * $results_per_page;

    /* ========================================================
       FETCH RESULTS
       ======================================================== */

    $select_query .= "
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $select_params = $params;
    $select_types = $types . 'ii';

    $select_params[] = $results_per_page;
    $select_params[] = $offset;

    $stmt = $conn->prepare($select_query);

    if (!$stmt) {
        throw new Exception("Unable to prepare results query.");
    }

    $stmt->bind_param($select_types, ...$select_params);

    $stmt->execute();

    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    /* ========================================================
       FILTER OPTIONS
       ======================================================== */

    /* Classes */

    $stmt = $conn->prepare("
        SELECT DISTINCT
            c.class_name AS class
        FROM classes c
        INNER JOIN students s
            ON s.class = c.id
        INNER JOIN results r
            ON r.user_id = s.id
        ORDER BY c.class_name
    ");

    $stmt->execute();

    $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    /* Years */

    $stmt = $conn->prepare("
        SELECT DISTINCT
            t.year
        FROM tests t
        INNER JOIN results r
            ON r.test_id = t.id
        ORDER BY t.year DESC
    ");

    $stmt->execute();

    $years = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    /* Test titles */

    $stmt = $conn->prepare("
        SELECT DISTINCT
            t.title
        FROM tests t
        INNER JOIN results r
            ON r.test_id = t.id
        ORDER BY t.title
    ");

    $stmt->execute();

    $test_titles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    /* Subjects */

    if ($class_filter !== '') {

        $stmt = $conn->prepare("
            SELECT DISTINCT
                s.subject_name
            FROM subjects s
            INNER JOIN subject_levels sl
                ON s.id = sl.subject_id
            INNER JOIN classes c
                ON (
                    sl.class_level = SUBSTRING(
                        c.class_name,
                        1,
                        LENGTH(sl.class_level)
                    )
                )
            WHERE c.class_name = ?
            ORDER BY s.subject_name
        ");

        if ($stmt) {

            $stmt->bind_param("s", $class_filter);

            $stmt->execute();

            $subject_result = $stmt->get_result();

            while ($row = $subject_result->fetch_assoc()) {
                $available_subjects[] = $row['subject_name'];
            }

            $stmt->close();
        }
    }

    /*
     * Always make sure the currently selected subject remains
     * available in the dropdown even if the class/subject
     * relationship differs in an older database installation.
     */
    if (
        $subject_filter !== '' &&
        !in_array($subject_filter, $available_subjects, true)
    ) {
        $available_subjects[] = $subject_filter;
    }

    /* If no class is selected, show all subjects. */

    if ($class_filter === '') {

        $stmt = $conn->prepare("
            SELECT DISTINCT
                subject_name
            FROM subjects
            ORDER BY subject_name
        ");

        if ($stmt) {

            $stmt->execute();

            $subject_result = $stmt->get_result();

            $available_subjects = [];

            while ($row = $subject_result->fetch_assoc()) {
                $available_subjects[] = $row['subject_name'];
            }

            $stmt->close();
        }
    }

} catch (Exception $e) {

    error_log("View results error: " . $e->getMessage());

    $error = "Unable to load examination results.";

} finally {

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

/* ============================================================
   HELPER FUNCTIONS
   ============================================================ */

function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function buildPageUrl(int $page): string
{
    global
        $class_filter,
        $subject_filter,
        $test_title_filter,
        $year_filter,
        $student_name_filter;

    $params = [
        'page' => $page,
        'selected_class' => $class_filter,
        'selected_subject' => $subject_filter,
        'selected_title' => $test_title_filter,
        'selected_year' => $year_filter,
        'student_name' => $student_name_filter,
    ];

    return '?' . http_build_query($params);
}

$has_filters =
    $class_filter !== '' ||
    $subject_filter !== '' ||
    $test_title_filter !== '' ||
    $year_filter !== '' ||
    $student_name_filter !== '';

$first_result_number =
    $total_results > 0
        ? $offset + 1
        : 0;

$last_result_number =
    min(
        $offset + count($results),
        $total_results
    );

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

<link
    rel="stylesheet"
    href="../css/sidebar.css"
>

<style>

    /* =====================================================
       PAGE FOUNDATION
    ===================================================== */

    :root {
        --primary: #4361ee;
        --primary-dark: #3451d1;
        --primary-soft: #eef2ff;
        --success: #16a34a;
        --success-soft: #ecfdf3;
        --warning: #d97706;
        --warning-soft: #fff7ed;
        --danger: #dc2626;
        --danger-soft: #fef2f2;
        --text-dark: #172033;
        --text-muted: #718096;
        --border: #e8ecf3;
        --surface: #ffffff;
        --background: #f6f8fc;
        --radius: 16px;
    }

    body {
        background: var(--background);
        color: var(--text-dark);
    }

    .main-content {
        min-height: 100vh;
    }

    /* =====================================================
       PAGE HEADER
    ===================================================== */

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        margin-bottom: 28px;
    }

    .page-title {
        margin: 0;
        font-size: 1.7rem;
        font-weight: 750;
        letter-spacing: -0.02em;
        color: var(--text-dark);
    }

    .page-subtitle {
        margin: 5px 0 0;
        color: var(--text-muted);
        font-size: .9rem;
    }

    .header-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    /* =====================================================
       BUTTONS
    ===================================================== */

    .btn-primary {
        background: var(--primary);
        border-color: var(--primary);
    }

    .btn-primary:hover,
    .btn-primary:focus {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
    }

    .btn-soft-primary {
        background: var(--primary-soft);
        color: var(--primary);
        border: 1px solid transparent;
    }

    .btn-soft-primary:hover {
        background: #e1e7ff;
        color: var(--primary-dark);
    }

    .btn-soft-success {
        background: var(--success-soft);
        color: var(--success);
        border: 1px solid transparent;
    }

    .btn-soft-success:hover {
        background: #d9fbe7;
        color: #12813b;
    }

    /* =====================================================
       SUMMARY CARDS
    ===================================================== */

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin-bottom: 24px;
    }

    .summary-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        box-shadow: 0 4px 18px rgba(20, 30, 60, .035);
    }

    .summary-icon {
        width: 48px;
        height: 48px;
        flex: 0 0 48px;
        border-radius: 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--primary-soft);
        color: var(--primary);
        font-size: 1.15rem;
    }

    .summary-label {
        color: var(--text-muted);
        font-size: .78rem;
        font-weight: 600;
        margin-bottom: 3px;
    }

    .summary-value {
        font-size: 1.35rem;
        font-weight: 750;
        line-height: 1.1;
    }

    /* =====================================================
       FILTER CARD
    ===================================================== */

    .filter-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: 0 4px 18px rgba(20, 30, 60, .035);
        overflow: hidden;
    }

    .filter-header {
        padding: 20px 22px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .filter-title-wrap {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .filter-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: var(--primary-soft);
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .filter-title {
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
    }

    .filter-description {
        margin: 2px 0 0;
        color: var(--text-muted);
        font-size: .78rem;
    }

    .filter-body {
        padding: 22px;
    }

    .filter-label {
        display: block;
        font-size: .78rem;
        font-weight: 700;
        color: #4a5568;
        margin-bottom: 7px;
    }

    .filter-control {
        min-height: 44px;
        border-radius: 10px;
        border-color: #dfe4ec;
        font-size: .88rem;
        box-shadow: none;
    }

    .filter-control:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(67, 97, 238, .1);
    }

    .filter-actions {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-top: 20px;
        padding-top: 18px;
        border-top: 1px solid var(--border);
    }

    .active-filter {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        background: var(--primary-soft);
        color: var(--primary);
        border-radius: 20px;
        padding: 6px 10px;
        font-size: .75rem;
        font-weight: 650;
    }

    /* =====================================================
       RESULTS SECTION
    ===================================================== */

    .results-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: 0 4px 18px rgba(20, 30, 60, .035);
        overflow: hidden;
    }

    .results-header {
        padding: 20px 22px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
    }

    .results-title {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
    }

    .results-meta {
        margin-top: 4px;
        color: var(--text-muted);
        font-size: .78rem;
    }

    .results-count {
        display: inline-flex;
        align-items: center;
        padding: 6px 10px;
        background: #f4f6fa;
        color: #4a5568;
        border-radius: 20px;
        font-size: .75rem;
        font-weight: 700;
    }

    .table-container {
        overflow-x: auto;
    }

    .results-table {
        margin: 0;
        min-width: 1000px;
    }

    .results-table thead th {
        background: #f8faff;
        color: #657083;
        border-bottom: 1px solid var(--border);
        border-top: none;
        padding: 14px 16px;
        font-size: .72rem;
        font-weight: 750;
        text-transform: uppercase;
        letter-spacing: .045em;
        white-space: nowrap;
    }

    .results-table tbody td {
        padding: 16px;
        border-color: #edf0f5;
        vertical-align: middle;
        font-size: .84rem;
        color: #344054;
    }

    .results-table tbody tr {
        transition: background .15s ease;
    }

    .results-table tbody tr:hover {
        background: #fafbff;
    }

    .student-name {
        font-weight: 700;
        color: var(--text-dark);
    }

    .student-avatar {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: var(--primary-soft);
        color: var(--primary);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-right: 10px;
        font-size: .8rem;
        font-weight: 750;
    }

    .student-cell {
        display: flex;
        align-items: center;
        min-width: 190px;
    }

    .badge-class,
    .badge-subject,
    .badge-year {
        display: inline-flex;
        align-items: center;
        border-radius: 7px;
        padding: 6px 9px;
        font-size: .7rem;
        font-weight: 700;
    }

    .badge-class {
        background: var(--primary-soft);
        color: var(--primary);
    }

    .badge-subject {
        background: #f3f4f6;
        color: #4b5563;
    }

    .badge-year {
        background: #f8fafc;
        color: #64748b;
        border: 1px solid #e2e8f0;
    }

    .score-value {
        font-weight: 750;
        color: var(--text-dark);
        white-space: nowrap;
    }

    .percentage-cell {
        font-weight: 800;
        white-space: nowrap;
    }

    .percentage-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 65px;
        padding: 6px 9px;
        border-radius: 7px;
        font-size: .72rem;
    }

    .percentage-pill.high {
        background: var(--success-soft);
        color: var(--success);
    }

    .percentage-pill.medium {
        background: var(--warning-soft);
        color: var(--warning);
    }

    .percentage-pill.low {
        background: var(--danger-soft);
        color: var(--danger);
    }

    .date-cell {
        color: #667085;
        white-space: nowrap;
        font-size: .78rem;
    }

    /* =====================================================
       PAGINATION
    ===================================================== */

    .pagination-wrapper {
        padding: 18px 22px;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
    }

    .pagination-info {
        color: var(--text-muted);
        font-size: .78rem;
    }

    .pagination {
        margin: 0;
    }

    .pagination .page-link {
        color: var(--primary);
        border-color: #e3e7ef;
        min-width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px !important;
        margin: 0 2px;
        font-size: .78rem;
        font-weight: 650;
    }

    .pagination .page-item.active .page-link {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
    }

    .pagination .page-item.disabled .page-link {
        color: #b1b8c5;
        background: #f8f9fb;
    }

    /* =====================================================
       EMPTY STATE
    ===================================================== */

    .empty-state {
        padding: 70px 25px;
        text-align: center;
    }

    .empty-icon {
        width: 70px;
        height: 70px;
        border-radius: 18px;
        background: var(--primary-soft);
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 18px;
        font-size: 1.7rem;
    }

    .empty-state h4 {
        font-weight: 750;
        margin-bottom: 7px;
    }

    .empty-state p {
        color: var(--text-muted);
        font-size: .85rem;
        margin-bottom: 20px;
    }

    /* =====================================================
       MOBILE SIDEBAR
    ===================================================== */

    @media (max-width: 991px) {

        .sidebar {
            position: fixed;
            left: -260px;
            top: 0;
            height: 100%;
            width: 260px;
            z-index: 1050;
            transition: left .3s ease-in-out;
        }

        .sidebar.active {
            left: 0;
        }

        .main-content {
            margin-left: 0 !important;
            width: 100%;
        }

        .page-header {
            align-items: flex-start;
        }

        .summary-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 767px) {

        .page-header {
            flex-direction: row;
            align-items: center;
        }

        .page-title {
            font-size: 1.35rem;
        }

        .page-subtitle {
            font-size: .78rem;
        }

        .filter-header,
        .filter-body,
        .results-header {
            padding: 17px;
        }

        .filter-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-actions .btn {
            width: 100%;
        }

        .results-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .pagination-wrapper {
            flex-direction: column;
            align-items: center;
        }

        .pagination {
            flex-wrap: wrap;
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
            <b><?= e($admin['username'] ?? 'Administrator'); ?></b>
        </h6>

    </div>

</div>

<div class="sidebar-menu mt-4">

    <a href="dashboard.php">
        <i class="fas fa-tachometer-alt"></i>
        Dashboard
    </a>

    <a href="add_teacher.php">
        <i class="fas fa-user-plus"></i>
        Add Teachers
    </a>

    <a href="view_questions.php">
        <i class="fas fa-list"></i>
        View Questions
    </a>

    <a href="view_results.php" class="active">
        <i class="fas fa-chart-bar"></i>
        Exam Results
    </a>

    <a href="manage_classes.php">
        <i class="fas fa-users"></i>
        Manage Classes
    </a>

    <a href="manage_session.php">
        <i class="fas fa-calendar-alt"></i>
        Manage Session
    </a>

    <a href="manage_subject.php">
        <i class="fas fa-book"></i>
        Manage Subject
    </a>

    <a href="manage_teachers.php">
        <i class="fas fa-chalkboard-teacher"></i>
        Manage Teachers
    </a>

    <a href="manage_test.php">
        <i class="fas fa-file-alt"></i>
        Manage Tests
    </a>

    <a href="exam_schedule.php">
        <i class="fas fa-calendar-check"></i>
        Timetable
    </a>

    <a href="settings.php">
        <i class="fas fa-cog"></i>
        Settings
    </a>

    <a href="../license/index.php">
        <i class="fas fa-key"></i>
        License
    </a>

    <a href="audit_logs.php">
        <i class="fas fa-shield-alt"></i>
        Audit Logs
    </a>

    <a href="../backup/backup_list.php">
        <i class="fas fa-database"></i>
        Backup
    </a>

    <a href="logout.php" class="logout-btn">
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
     PAGE HEADER
====================================================== -->

<div class="page-header">

    <div>

        <h1 class="page-title">
            Exam Results
        </h1>

        <p class="page-subtitle">
            Review, filter and export student examination results.
        </p>

    </div>

    <div class="header-actions">

        <button
            type="button"
            class="btn btn-primary d-lg-none"
            id="sidebarToggle"
            aria-label="Toggle navigation"
        >
            <i class="fas fa-bars"></i>
        </button>

    </div>

</div>


<!-- =====================================================
     SUMMARY
====================================================== -->

<div class="summary-grid">

    <div class="summary-card">

        <div class="summary-icon">
            <i class="fas fa-chart-bar"></i>
        </div>

        <div>

            <div class="summary-label">
                TOTAL RESULTS
            </div>

            <div class="summary-value">
                <?= number_format($total_results); ?>
            </div>

        </div>

    </div>


    <div class="summary-card">

        <div class="summary-icon">
            <i class="fas fa-list-ol"></i>
        </div>

        <div>

            <div class="summary-label">
                CURRENT PAGE
            </div>

            <div class="summary-value">
                <?= number_format(count($results)); ?>
            </div>

        </div>

    </div>


    <div class="summary-card">

        <div class="summary-icon">
            <i class="fas fa-file-export"></i>
        </div>

        <div>

            <div class="summary-label">
                REPORT STATUS
            </div>

            <div class="summary-value">
                <?= $total_results > 0 ? 'Available' : 'Empty'; ?>
            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     FILTER CARD
====================================================== -->

<div class="filter-card mb-4">

    <div class="filter-header">

        <div class="filter-title-wrap">

            <div class="filter-icon">
                <i class="fas fa-filter"></i>
            </div>

            <div>

                <h5 class="filter-title">
                    Filter Results
                </h5>

                <p class="filter-description">
                    Narrow down results by test, class, subject, year or student.
                </p>

            </div>

        </div>

        <?php if ($has_filters): ?>

            <a
                href="view_results.php"
                class="btn btn-sm btn-light"
            >
                <i class="fas fa-times me-1"></i>
                Clear
            </a>

        <?php endif; ?>

    </div>


    <div class="filter-body">

        <form
            method="GET"
            action="view_results.php"
        >

            <div class="row g-3">

                <!-- TEST -->

                <div class="col-xl-3 col-md-6">

                    <label class="filter-label">
                        Test
                    </label>

                    <select
                        class="form-select filter-control"
                        name="selected_title"
                    >

                        <option value="">
                            All Tests
                        </option>

                        <?php foreach ($test_titles as $title): ?>

                            <option
                                value="<?= e($title['title']); ?>"
                                <?= $test_title_filter === $title['title'] ? 'selected' : ''; ?>
                            >
                                <?= e($title['title']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- CLASS -->

                <div class="col-xl-2 col-md-6">

                    <label class="filter-label">
                        Class
                    </label>

                    <select
                        class="form-select filter-control"
                        name="selected_class"
                        id="selectedClass"
                    >

                        <option value="">
                            All Classes
                        </option>

                        <?php foreach ($classes as $class): ?>

                            <option
                                value="<?= e($class['class']); ?>"
                                <?= $class_filter === $class['class'] ? 'selected' : ''; ?>
                            >
                                <?= e($class['class']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- SUBJECT -->

                <div class="col-xl-2 col-md-6">

                    <label class="filter-label">
                        Subject
                    </label>

                    <select
                        class="form-select filter-control"
                        name="selected_subject"
                        id="selectedSubject"
                    >

                        <option value="">
                            All Subjects
                        </option>

                        <?php foreach ($available_subjects as $subject): ?>

                            <option
                                value="<?= e($subject); ?>"
                                <?= $subject_filter === $subject ? 'selected' : ''; ?>
                            >
                                <?= e($subject); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- YEAR -->

                <div class="col-xl-2 col-md-6">

                    <label class="filter-label">
                        Academic Year
                    </label>

                    <select
                        class="form-select filter-control"
                        name="selected_year"
                    >

                        <option value="">
                            All Years
                        </option>

                        <?php foreach ($years as $year): ?>

                            <option
                                value="<?= e($year['year']); ?>"
                                <?= $year_filter === (string)$year['year'] ? 'selected' : ''; ?>
                            >
                                <?= e($year['year']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- STUDENT -->

                <div class="col-xl-3 col-md-6">

                    <label class="filter-label">
                        Student Name
                    </label>

                    <input
                        type="text"
                        class="form-control filter-control"
                        name="student_name"
                        value="<?= e($student_name_filter); ?>"
                        placeholder="Search student name..."
                        maxlength="100"
                    >

                </div>

            </div>


            <!-- FILTER ACTIONS -->

            <div class="filter-actions">

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <i class="fas fa-filter me-2"></i>
                    Apply Filters
                </button>

                <?php if ($has_filters): ?>

                    <a
                        href="view_results.php"
                        class="btn btn-light"
                    >
                        <i class="fas fa-undo me-2"></i>
                        Reset
                    </a>

                <?php endif; ?>

                <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">

                    <?php if ($class_filter !== ''): ?>

                        <span class="active-filter">
                            <i class="fas fa-users"></i>
                            <?= e($class_filter); ?>
                        </span>

                    <?php endif; ?>

                    <?php if ($subject_filter !== ''): ?>

                        <span class="active-filter">
                            <i class="fas fa-book"></i>
                            <?= e($subject_filter); ?>
                        </span>

                    <?php endif; ?>

                    <?php if ($year_filter !== ''): ?>

                        <span class="active-filter">
                            <i class="fas fa-calendar"></i>
                            <?= e($year_filter); ?>
                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </form>

    </div>

</div>


<!-- =====================================================
     RESULTS CARD
====================================================== -->

<div class="results-card">

    <div class="results-header">

        <div>

            <h5 class="results-title">
                Examination Results
            </h5>

            <div class="results-meta">

                <?php if ($total_results > 0): ?>

                    Showing
                    <strong><?= $first_result_number; ?></strong>
                    –
                    <strong><?= $last_result_number; ?></strong>
                    of
                    <strong><?= number_format($total_results); ?></strong>
                    results

                <?php else: ?>

                    No examination results found.

                <?php endif; ?>

            </div>

        </div>


        <div class="d-flex align-items-center gap-2">

            <span class="results-count">
                <i class="fas fa-database me-1"></i>
                <?= number_format($total_results); ?> Total
            </span>

            <?php if ($total_results > 0): ?>

                <form
                    method="POST"
                    action="view_results.php"
                    class="m-0"
                >

                    <input
                        type="hidden"
                        name="selected_title"
                        value="<?= e($test_title_filter); ?>"
                    >

                    <input
                        type="hidden"
                        name="selected_class"
                        value="<?= e($class_filter); ?>"
                    >

                    <input
                        type="hidden"
                        name="selected_subject"
                        value="<?= e($subject_filter); ?>"
                    >

                    <input
                        type="hidden"
                        name="selected_year"
                        value="<?= e($year_filter); ?>"
                    >

                    <input
                        type="hidden"
                        name="student_name"
                        value="<?= e($student_name_filter); ?>"
                    >

                    <input
                        type="hidden"
                        name="export_results"
                        value="1"
                    >

                    <button
                        type="submit"
                        class="btn btn-sm btn-soft-success"
                    >
                        <i class="fas fa-file-word me-1"></i>
                        Export
                    </button>

                </form>

            <?php endif; ?>

        </div>

    </div>


    <?php if (!empty($results)): ?>

        <!-- =================================================
             TABLE
        ================================================== -->

        <div class="table-container">

            <table class="table results-table">

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

                        $score = (float)$result['score'];
                        $total_questions = (float)$result['total_questions'];

                        $percentage = $total_questions > 0
                            ? round(($score / $total_questions) * 100, 2)
                            : 0;

                        if ($percentage >= 70) {
                            $percentage_class = 'high';
                        } elseif ($percentage >= 50) {
                            $percentage_class = 'medium';
                        } else {
                            $percentage_class = 'low';
                        }

                        $student_name = trim($result['student_name']);

                        $initials = '';

                        $name_parts = preg_split(
                            '/\s+/',
                            $student_name
                        );

                        if (!empty($name_parts[0])) {
                            $initials .= strtoupper(
                                substr($name_parts[0], 0, 1)
                            );
                        }

                        if (
                            count($name_parts) > 1 &&
                            !empty($name_parts[count($name_parts) - 1])
                        ) {
                            $initials .= strtoupper(
                                substr(
                                    $name_parts[count($name_parts) - 1],
                                    0,
                                    1
                                )
                            );
                        }

                        ?>

                        <tr>

                            <!-- STUDENT -->

                            <td>

                                <div class="student-cell">

                                    <span class="student-avatar">
                                        <?= e($initials); ?>
                                    </span>

                                    <span class="student-name">
                                        <?= e($student_name); ?>
                                    </span>

                                </div>

                            </td>


                            <!-- CLASS -->

                            <td>

                                <span class="badge-class">
                                    <i class="fas fa-users me-1"></i>
                                    <?= e($result['student_class']); ?>
                                </span>

                            </td>


                            <!-- TEST -->

                            <td>

                                <div class="fw-semibold">
                                    <?= e($result['test_title']); ?>
                                </div>

                            </td>


                            <!-- SUBJECT -->

                            <td>

                                <span class="badge-subject">
                                    <?= e($result['subject']); ?>
                                </span>

                            </td>


                            <!-- SCORE -->

                            <td>

                                <span class="score-value">
                                    <?= e($score); ?>
                                    /
                                    <?= e($total_questions); ?>
                                </span>

                            </td>


                            <!-- PERCENTAGE -->

                            <td>

                                <span class="percentage-pill <?= $percentage_class; ?>">

                                    <?php if ($percentage >= 70): ?>

                                        <i class="fas fa-arrow-up me-1"></i>

                                    <?php elseif ($percentage >= 50): ?>

                                        <i class="fas fa-minus me-1"></i>

                                    <?php else: ?>

                                        <i class="fas fa-arrow-down me-1"></i>

                                    <?php endif; ?>

                                    <?= e($percentage); ?>%

                                </span>

                            </td>


                            <!-- DATE -->

                            <td>

                                <span class="date-cell">

                                    <i class="far fa-clock me-1"></i>

                                    <?= e(
                                        date(
                                            'M j, Y g:i A',
                                            strtotime($result['created_at'])
                                        )
                                    ); ?>

                                </span>

                            </td>


                            <!-- YEAR -->

                            <td>

                                <span class="badge-year">
                                    <?= e($result['year']); ?>
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

                    Page
                    <strong><?= $current_page; ?></strong>
                    of
                    <strong><?= $total_pages; ?></strong>

                </div>


                <nav aria-label="Results pagination">

                    <ul class="pagination">

                        <!-- PREVIOUS -->

                        <li
                            class="page-item <?= $current_page <= 1 ? 'disabled' : ''; ?>"
                        >

                            <?php if ($current_page > 1): ?>

                                <a
                                    class="page-link"
                                    href="<?= e(buildPageUrl($current_page - 1)); ?>"
                                    aria-label="Previous page"
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

                        $start_page = max(
                            1,
                            $current_page - 2
                        );

                        $end_page = min(
                            $total_pages,
                            $current_page + 2
                        );

                        ?>


                        <!-- FIRST PAGE -->

                        <?php if ($start_page > 1): ?>

                            <li class="page-item">

                                <a
                                    class="page-link"
                                    href="<?= e(buildPageUrl(1)); ?>"
                                >
                                    1
                                </a>

                            </li>

                            <?php if ($start_page > 2): ?>

                                <li class="page-item disabled">

                                    <span class="page-link">
                                        …
                                    </span>

                                </li>

                            <?php endif; ?>

                        <?php endif; ?>


                        <!-- PAGE NUMBERS -->

                        <?php for ($page = $start_page; $page <= $end_page; $page++): ?>

                            <li
                                class="page-item <?= $page === $current_page ? 'active' : ''; ?>"
                            >

                                <a
                                    class="page-link"
                                    href="<?= e(buildPageUrl($page)); ?>"
                                >
                                    <?= $page; ?>
                                </a>

                            </li>

                        <?php endfor; ?>


                        <!-- LAST PAGE -->

                        <?php if ($end_page < $total_pages): ?>

                            <?php if ($end_page < $total_pages - 1): ?>

                                <li class="page-item disabled">

                                    <span class="page-link">
                                        …
                                    </span>

                                </li>

                            <?php endif; ?>

                            <li class="page-item">

                                <a
                                    class="page-link"
                                    href="<?= e(buildPageUrl($total_pages)); ?>"
                                >
                                    <?= $total_pages; ?>
                                </a>

                            </li>

                        <?php endif; ?>


                        <!-- NEXT -->

                        <li
                            class="page-item <?= $current_page >= $total_pages ? 'disabled' : ''; ?>"
                        >

                            <?php if ($current_page < $total_pages): ?>

                                <a
                                    class="page-link"
                                    href="<?= e(buildPageUrl($current_page + 1)); ?>"
                                    aria-label="Next page"
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

                    No examination results match the filters
                    you selected. Try changing or clearing your filters.

                <?php else: ?>

                    Examination results will appear here
                    once students complete tests.

                <?php endif; ?>
            </p>

            <?php if ($has_filters): ?>

                <a
                    href="view_results.php"
                    class="btn btn-primary"
                >
                    <i class="fas fa-times me-2"></i>
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

<script src="../js/jquery.dataTables.min.js"></script>

<script src="../js/dataTables.bootstrap5.min.js"></script>

<script src="../js/jquery.validate.min.js"></script>

<script>

document.addEventListener('DOMContentLoaded', function () {

    /* ========================================================
       SIDEBAR TOGGLE
    ======================================================== */

    const sidebar = document.querySelector('.sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');

    if (sidebar && sidebarToggle) {

        sidebarToggle.addEventListener('click', function () {

            sidebar.classList.toggle('active');

        });

    }


    /* ========================================================
       CLOSE SIDEBAR WHEN CLICKING OUTSIDE ON MOBILE
    ======================================================== */

    document.addEventListener('click', function (event) {

        if (
            window.innerWidth <= 991 &&
            sidebar &&
            sidebar.classList.contains('active') &&
            !sidebar.contains(event.target) &&
            !sidebarToggle.contains(event.target)
        ) {

            sidebar.classList.remove('active');

        }

    });


    /* ========================================================
       SUBJECT FILTER
    ======================================================== */

    const classSelect =
        document.getElementById('selectedClass');

    const subjectSelect =
        document.getElementById('selectedSubject');

    if (classSelect && subjectSelect) {

        classSelect.addEventListener('change', function () {

            const selectedClass = this.value;

            /*
             * The server already provides subjects for the
             * selected class. When the user changes class,
             * submit the form so PHP can load the correct
             * subject list from the database.
             */

            if (selectedClass !== '') {

                const currentUrl =
                    new URL(
                        window.location.href
                    );

                currentUrl.searchParams.set(
                    'selected_class',
                    selectedClass
                );

                currentUrl.searchParams.delete(
                    'selected_subject'
                );

                currentUrl.searchParams.set(
                    'page',
                    '1'
                );

                window.location.href =
                    currentUrl.toString();

            } else {

                /*
                 * If "All Classes" is selected, reset the
                 * subject filter as well.
                 */

                subjectSelect.value = '';

            }

        });

    }


    /* ========================================================
       PREVENT DOUBLE EXPORT SUBMISSION
    ======================================================== */

    document
        .querySelectorAll('form[action="view_results.php"]')
        .forEach(function (form) {

            form.addEventListener('submit', function (event) {

                const exportInput =
                    form.querySelector(
                        'input[name="export_results"]'
                    );

                if (!exportInput) {
                    return;
                }

                const button =
                    form.querySelector(
                        'button[type="submit"]'
                    );

                if (button) {

                    button.disabled = true;

                    button.innerHTML =
                        '<i class="fas fa-spinner fa-spin me-1"></i> Exporting...';

                }

            });

        });

});

</script>

</body>
</html>
