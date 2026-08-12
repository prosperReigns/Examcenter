<?php

session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;


/* =========================================================
   ERROR REPORTING
========================================================= */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');


/* =========================================================
   HELPER
========================================================= */

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/**
 * Format the class exactly from academic_levels.
 *
 * Example:
 * JSS1
 * JSS2
 * SS1
 *
 * Streams are NOT part of tests because tests has no stream_id.
 */
function formatClassName(
    ?string $levelCode,
    ?string $streamName = null
): string {

    $levelCode = trim((string) $levelCode);
    $streamName = trim((string) $streamName);

    if ($levelCode === '') {
        return 'Unknown Class';
    }

    /*
     * If a stream exists, display:
     *
     * JSS1 - Gold
     *
     * Otherwise:
     *
     * JSS1
     */
    if ($streamName !== '') {
        return $levelCode . ' - ' . $streamName;
    }

    return $levelCode;
}


/**
 * Calculate percentage safely.
 */
function calculatePercentage(
    $score,
    $totalQuestions
): float {

    $score = (float) $score;
    $totalQuestions = (float) $totalQuestions;

    if ($totalQuestions <= 0) {
        return 0.0;
    }

    $percentage = ($score / $totalQuestions) * 100;

    /*
     * Keep percentage within the valid range.
     */
    $percentage = max(0, min(100, $percentage));

    return round($percentage, 2);
}


/**
 * CSS class for percentage.
 */
function percentageClass(float $percentage): string
{
    if ($percentage >= 70) {
        return 'high';
    }

    if ($percentage >= 50) {
        return 'medium';
    }

    return 'low';
}


/* =========================================================
   AUTHENTICATION
========================================================= */

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['user_role']) ||
    strtolower($_SESSION['user_role']) !== 'teacher'
) {
    error_log(
        "Unauthorized access attempt to view_results.php"
    );

    header(
        "Location: /EXAMCENTER/login.php?error=Not logged in"
    );

    exit();
}


/* =========================================================
   DATABASE
========================================================= */

try {

    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        throw new Exception(
            "Database connection failed: " .
            $conn->connect_error
        );
    }

    $conn->set_charset('utf8mb4');

    $teacher_id = (int) $_SESSION['user_id'];


    /* =====================================================
       TEACHER PROFILE
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT
            username,
            last_name
        FROM teachers
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception(
            "Unable to prepare teacher profile query."
        );
    }

    $stmt->bind_param(
        "i",
        $teacher_id
    );

    $stmt->execute();

    $teacher = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();


    if (!$teacher) {

        session_destroy();

        header(
            "Location: /EXAMCENTER/login.php?error=Unauthorized"
        );

        exit();
    }


    /* =====================================================
       ASSIGNED SUBJECTS
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT subject
        FROM teacher_subjects
        WHERE teacher_id = ?
        ORDER BY subject ASC
    ");

    if (!$stmt) {
        throw new Exception(
            "Unable to prepare assigned subjects query."
        );
    }

    $stmt->bind_param(
        "i",
        $teacher_id
    );

    $stmt->execute();

    $assigned_subjects = [];

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $assigned_subjects[] = trim($row['subject']);
    }

    $stmt->close();


    $error = '';
    $success = '';


    /* =====================================================
       FILTERS
    ===================================================== */

    $class_filter =
        trim($_GET['selected_class'] ?? '');

    $subject_filter =
        trim($_GET['selected_subject'] ?? '');

    $test_title_filter =
        trim($_GET['selected_title'] ?? '');

    $year_filter =
        trim($_GET['selected_year'] ?? '');

    $student_name_filter =
        trim($_GET['student_name'] ?? '');


    /* =====================================================
       PAGINATION
    ===================================================== */

    $results_per_page = 10;

    $current_page =
        isset($_GET['page']) &&
        is_numeric($_GET['page'])
            ? max(1, (int) $_GET['page'])
            : 1;

    $offset =
        ($current_page - 1) *
        $results_per_page;


    /* =====================================================
       SUBJECT ACCESS CONDITION
    ===================================================== */

    if (!empty($assigned_subjects)) {

        $subject_conditions = [];

        foreach ($assigned_subjects as $subject) {
            $subject_conditions[] =
                "t.subject LIKE CONCAT(?, '%')";
        }

        $subject_where =
            '(' .
            implode(
                ' OR ',
                $subject_conditions
            ) .
            ')';

    } else {

        /*
         * Teacher has no subjects.
         * Force query to return nothing.
         */
        $subject_where = "1 = 0";
    }


    /* =====================================================
       EXPORT RESULTS
    ===================================================== */

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['export_results'])
    ) {

        try {

            $export_title =
                trim($_POST['selected_title'] ?? '');

            $export_class =
                trim($_POST['selected_class'] ?? '');

            $export_subject =
                trim($_POST['selected_subject'] ?? '');

            $export_year =
                trim($_POST['selected_year'] ?? '');

            $export_student =
                trim($_POST['student_name'] ?? '');


            /*
             * IMPORTANT:
             *
             * Class comes from:
             *
             * tests.academic_level_id
             *        ↓
             * academic_levels.id
             *        ↓
             * academic_levels.level_code
             *
             * No tests.stream_id is used.
             */
            $export_query = "
                SELECT
                    r.*,

                    s.full_name AS student_name,

                    t.subject,
                    t.title AS test_title,
                    t.year,

                    al.level_code AS academic_level,

                    (
                        SELECT st.stream_name
                        FROM streams st
                        WHERE st.id = (
                            SELECT
                                c.stream_id
                            FROM classes c
                            WHERE c.academic_level_id =
                                t.academic_level_id
                            LIMIT 1
                        )
                        LIMIT 1
                    ) AS stream_name

                FROM results r

                INNER JOIN students s
                    ON r.user_id = s.id

                INNER JOIN tests t
                    ON r.test_id = t.id

                INNER JOIN academic_levels al
                    ON al.id = t.academic_level_id

                WHERE {$subject_where}
            ";


            $export_params =
                $assigned_subjects;

            $export_types =
                str_repeat(
                    's',
                    count($assigned_subjects)
                );


            if ($export_title !== '') {

                $export_query .=
                    " AND t.title = ?";

                $export_params[] =
                    $export_title;

                $export_types .= 's';
            }


            if ($export_class !== '') {

                $export_query .=
                    " AND al.level_code = ?";

                $export_params[] =
                    $export_class;

                $export_types .= 's';
            }


            if ($export_subject !== '') {

                $export_query .=
                    " AND t.subject = ?";

                $export_params[] =
                    $export_subject;

                $export_types .= 's';
            }


            if ($export_year !== '') {

                $export_query .=
                    " AND t.year = ?";

                $export_params[] =
                    $export_year;

                $export_types .= 's';
            }


            if ($export_student !== '') {

                $export_query .=
                    " AND s.full_name LIKE ?";

                $export_params[] =
                    '%' .
                    $export_student .
                    '%';

                $export_types .= 's';
            }


            $export_query .= "
                ORDER BY r.created_at DESC
            ";


            $stmt =
                $conn->prepare(
                    $export_query
                );

            if (!$stmt) {
                throw new Exception(
                    "Unable to prepare export query."
                );
            }


            if (!empty($export_params)) {

                $stmt->bind_param(
                    $export_types,
                    ...$export_params
                );
            }


            $stmt->execute();

            $export_results =
                $stmt
                    ->get_result()
                    ->fetch_all(
                        MYSQLI_ASSOC
                    );

            $stmt->close();


            /* =================================================
               CREATE WORD DOCUMENT
            ================================================= */

            $phpWord = new PhpWord();

            $section =
                $phpWord->addSection([
                    'marginTop' => 720,
                    'marginBottom' => 720,
                    'marginLeft' => 720,
                    'marginRight' => 720
                ]);


            $section->addTitle(
                'Exam Results Report',
                1
            );

            $section->addText(
                'Generated on: ' .
                date('F j, Y g:i A')
            );

            $section->addText(
                'Teacher: ' .
                $teacher['last_name']
            );


            if ($export_title !== '') {
                $section->addText(
                    'Test: ' .
                    $export_title
                );
            }


            if ($export_class !== '') {
                $section->addText(
                    'Class: ' .
                    $export_class
                );
            }


            if ($export_subject !== '') {
                $section->addText(
                    'Subject: ' .
                    $export_subject
                );
            }


            if ($export_year !== '') {
                $section->addText(
                    'Year: ' .
                    $export_year
                );
            }


            if ($export_student !== '') {
                $section->addText(
                    'Student: ' .
                    $export_student
                );
            }


            $section->addText('');


            $table =
                $section->addTable([
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

                $table
                    ->addCell($width)
                    ->addText(
                        $header,
                        ['bold' => true]
                    );
            }


            foreach ($export_results as $result) {

                $score =
                    (float) (
                        $result['score'] ?? 0
                    );

                $total_questions =
                    (float) (
                        $result['total_questions'] ?? 0
                    );

                $percentage =
                    calculatePercentage(
                        $score,
                        $total_questions
                    );


                $class_name =
                    formatClassName(
                        $result['academic_level'] ?? '',
                        $result['stream_name'] ?? ''
                    );


                $table->addRow();


                $table
                    ->addCell(2000)
                    ->addText(
                        $result['student_name']
                    );


                $table
                    ->addCell(1500)
                    ->addText(
                        $class_name
                    );


                $table
                    ->addCell(2000)
                    ->addText(
                        $result['test_title']
                    );


                $table
                    ->addCell(1500)
                    ->addText(
                        $result['subject']
                    );


                $table
                    ->addCell(1000)
                    ->addText(
                        $score .
                        '/' .
                        $total_questions
                    );


                $table
                    ->addCell(1000)
                    ->addText(
                        number_format(
                            $percentage,
                            2
                        ) . '%'
                    );


                $table
                    ->addCell(1500)
                    ->addText(
                        date(
                            'M j, Y g:i A',
                            strtotime(
                                $result['created_at']
                            )
                        )
                    );


                $table
                    ->addCell(1000)
                    ->addText(
                        $result['year']
                    );
            }


            /* =================================================
               SAVE DOCUMENT
            ================================================= */

            $filename =
                'Exam_Results_' .
                date('Ymd_His') .
                '.docx';


            $temp_file =
                tempnam(
                    sys_get_temp_dir(),
                    'phpword'
                );


            $writer =
                IOFactory::createWriter(
                    $phpWord,
                    'Word2007'
                );


            $writer->save(
                $temp_file
            );


            /* =================================================
               ACTIVITY LOG
            ================================================= */

            $ip_address =
                $_SERVER['REMOTE_ADDR'] ?? '';

            $user_agent =
                $_SERVER['HTTP_USER_AGENT'] ?? '';


            $activity =
                "Teacher {$teacher['username']} exported results for " .
                ($export_title ?: 'all tests') .
                ($export_class
                    ? " in {$export_class}"
                    : '') .
                ($export_subject
                    ? " ({$export_subject})"
                    : '') .
                ($export_year
                    ? " ({$export_year})"
                    : '') .
                ($export_student
                    ? " for {$export_student}"
                    : '');


            $stmt_log =
                $conn->prepare("
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
                'Content-Disposition: attachment; filename="' .
                $filename .
                '"'
            );

            header(
                'Content-Length: ' .
                filesize($temp_file)
            );


            readfile($temp_file);

            unlink($temp_file);

            exit();


        } catch (Exception $e) {

            error_log(
                "Export error: " .
                $e->getMessage()
            );

            $error =
                "Unable to export results. Please try again.";
        }
    }


    /* =========================================================
       RESULT QUERY
    ========================================================= */

    /*
     * IMPORTANT:
     *
     * We DO NOT use classes.class_name to determine
     * the academic class.
     *
     * The source of truth is:
     *
     * tests.academic_level_id
     *        ↓
     * academic_levels.id
     *        ↓
     * academic_levels.level_code
     *
     * Therefore:
     *
     * academic_level_id = 1 → JSS1
     * academic_level_id = 2 → SS1
     * academic_level_id = 3 → JSS2
     */

    $base_from = "

        FROM results r

        INNER JOIN students s
            ON r.user_id = s.id

        INNER JOIN tests t
            ON r.test_id = t.id

        INNER JOIN academic_levels al
            ON al.id = t.academic_level_id

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

            t.year,

            al.level_code AS academic_level,

            al.class_group

        {$base_from}

        WHERE {$subject_where}

    ";


    $params =
        $assigned_subjects;

    $types =
        str_repeat(
            's',
            count($assigned_subjects)
        );


    /* =====================================================
       TEST FILTER
    ===================================================== */

    if ($test_title_filter !== '') {

        $condition =
            " AND t.title = ?";

        $count_query .= $condition;
        $select_query .= $condition;

        $params[] =
            $test_title_filter;

        $types .= 's';
    }


    /* =====================================================
       CLASS FILTER
    ===================================================== */

    if ($class_filter !== '') {

        /*
         * Class is academic_levels.level_code.
         */
        $condition =
            " AND al.level_code = ?";

        $count_query .= $condition;
        $select_query .= $condition;

        $params[] =
            $class_filter;

        $types .= 's';
    }


    /* =====================================================
       SUBJECT FILTER
    ===================================================== */

    if ($subject_filter !== '') {

        $condition =
            " AND t.subject = ?";

        $count_query .= $condition;
        $select_query .= $condition;

        $params[] =
            $subject_filter;

        $types .= 's';
    }


    /* =====================================================
       YEAR FILTER
    ===================================================== */

    if ($year_filter !== '') {

        $condition =
            " AND t.year = ?";

        $count_query .= $condition;
        $select_query .= $condition;

        $params[] =
            $year_filter;

        $types .= 's';
    }


    /* =====================================================
       STUDENT FILTER
    ===================================================== */

    if ($student_name_filter !== '') {

        $condition =
            " AND s.full_name LIKE ?";

        $count_query .= $condition;
        $select_query .= $condition;

        $params[] =
            '%' .
            $student_name_filter .
            '%';

        $types .= 's';
    }


    /* =====================================================
       COUNT
    ===================================================== */

    $stmt =
        $conn->prepare(
            $count_query
        );

    if (!$stmt) {
        throw new Exception(
            "Unable to prepare result count query: " .
            $conn->error
        );
    }


    if (!empty($params)) {

        $stmt->bind_param(
            $types,
            ...$params
        );
    }


    $stmt->execute();


    $count_data =
        $stmt
            ->get_result()
            ->fetch_assoc();


    $total_results =
        (int) (
            $count_data['total'] ?? 0
        );


    $stmt->close();


    $total_pages =
        $total_results > 0
            ? (int) ceil(
                $total_results /
                $results_per_page
            )
            : 0;


    if (
        $total_pages > 0 &&
        $current_page > $total_pages
    ) {

        $current_page =
            $total_pages;

        $offset =
            ($current_page - 1) *
            $results_per_page;
    }


    /* =====================================================
       FETCH RESULTS
    ===================================================== */

    $select_query .= "

        ORDER BY
            r.created_at DESC

        LIMIT ? OFFSET ?

    ";


    $select_params =
        $params;

    $select_types =
        $types . 'ii';


    $select_params[] =
        $results_per_page;

    $select_params[] =
        $offset;


    $stmt =
        $conn->prepare(
            $select_query
        );


    if (!$stmt) {
        throw new Exception(
            "Unable to prepare results query: " .
            $conn->error
        );
    }


    $stmt->bind_param(
        $select_types,
        ...$select_params
    );


    $stmt->execute();


    $results =
        $stmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );


    $stmt->close();


    /* =========================================================
       FILTER OPTIONS
    ========================================================= */

    $classes = [];
    $years = [];
    $test_titles = [];
    $subjects = [];


    if (!empty($assigned_subjects)) {


        /* -----------------------------------------------------
           CLASSES

           ONLY academic_levels.level_code.
        ----------------------------------------------------- */

        $query = "

            SELECT DISTINCT

                al.level_code

            FROM tests t

            INNER JOIN academic_levels al
                ON al.id =
                    t.academic_level_id

            INNER JOIN results r
                ON r.test_id = t.id

            WHERE {$subject_where}

            ORDER BY
                al.level_code ASC

        ";


        $stmt =
            $conn->prepare($query);


        if ($stmt) {

            $filter_types =
                str_repeat(
                    's',
                    count($assigned_subjects)
                );


            $stmt->bind_param(
                $filter_types,
                ...$assigned_subjects
            );


            $stmt->execute();


            $classes =
                $stmt
                    ->get_result()
                    ->fetch_all(
                        MYSQLI_ASSOC
                    );


            $stmt->close();
        }


        /* -----------------------------------------------------
           SUBJECTS
        ----------------------------------------------------- */

        $query = "

            SELECT DISTINCT
                t.subject

            FROM tests t

            INNER JOIN results r
                ON r.test_id = t.id

            WHERE {$subject_where}

            ORDER BY
                t.subject ASC

        ";


        $stmt =
            $conn->prepare($query);


        if ($stmt) {

            $filter_types =
                str_repeat(
                    's',
                    count($assigned_subjects)
                );


            $stmt->bind_param(
                $filter_types,
                ...$assigned_subjects
            );


            $stmt->execute();


            $subjects =
                $stmt
                    ->get_result()
                    ->fetch_all(
                        MYSQLI_ASSOC
                    );


            $stmt->close();
        }


        /* -----------------------------------------------------
           YEARS
        ----------------------------------------------------- */

        $query = "

            SELECT DISTINCT
                t.year

            FROM tests t

            INNER JOIN results r
                ON r.test_id = t.id

            WHERE {$subject_where}

            AND t.year IS NOT NULL

            AND t.year <> ''

            ORDER BY
                t.year DESC

        ";


        $stmt =
            $conn->prepare($query);


        if ($stmt) {

            $filter_types =
                str_repeat(
                    's',
                    count($assigned_subjects)
                );


            $stmt->bind_param(
                $filter_types,
                ...$assigned_subjects
            );


            $stmt->execute();


            $years =
                $stmt
                    ->get_result()
                    ->fetch_all(
                        MYSQLI_ASSOC
                    );


            $stmt->close();
        }


        /* -----------------------------------------------------
           TEST TITLES
        ----------------------------------------------------- */

        $query = "

            SELECT DISTINCT
                t.title

            FROM tests t

            INNER JOIN results r
                ON r.test_id = t.id

            WHERE {$subject_where}

            AND t.title IS NOT NULL

            AND t.title <> ''

            ORDER BY
                t.title ASC

        ";


        $stmt =
            $conn->prepare($query);


        if ($stmt) {

            $filter_types =
                str_repeat(
                    's',
                    count($assigned_subjects)
                );


            $stmt->bind_param(
                $filter_types,
                ...$assigned_subjects
            );


            $stmt->execute();


            $test_titles =
                $stmt
                    ->get_result()
                    ->fetch_all(
                        MYSQLI_ASSOC
                    );


            $stmt->close();
        }
    }


    /* =========================================================
       SUMMARY STATISTICS
    ========================================================= */

    $total_score = 0;
    $total_possible = 0;

    foreach ($results as $row) {

        $total_score +=
            (float) ($row['score'] ?? 0);

        $total_possible +=
            (float) (
                $row['total_questions'] ?? 0
            );
    }


    $average_percentage =
        calculatePercentage(
            $total_score,
            $total_possible
        );


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
        'selected_class' =>
            $class_filter,

        'selected_subject' =>
            $subject_filter,

        'selected_title' =>
            $test_title_filter,

        'selected_year' =>
            $year_filter,

        'student_name' =>
            $student_name_filter
    ];


    $pagination_url =
        function ($page)
        use ($pagination_query) {

            $query =
                array_merge(
                    ['page' => $page],
                    $pagination_query
                );

            return '?' .
                http_build_query($query);
        };


} catch (Exception $e) {

    error_log(
        "View results error: " .
        $e->getMessage()
    );

    die(
        "System error. Please contact the administrator."
    );
}

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

:root {

    --primary: #4361ee;
    --primary-dark: #3451d1;

    --success: #198754;
    --warning: #f59e0b;
    --danger: #dc3545;

    --text: #1f2937;
    --muted: #6b7280;

    --border: #e5e7eb;
    --surface: #ffffff;
    --background: #f5f7fb;

}


body {
    background: var(--background);
    color: var(--text);
}


.main-content {
    min-height: 100vh;
    padding-bottom: 50px;
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

    background: rgba(67, 97, 238, .1);

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
}


.page-subtitle {

    margin: 4px 0 0;

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
}


/* =====================================================
   FILTER CARD
===================================================== */

.filter-card {

    background: var(--surface);

    border: 1px solid var(--border);

    border-radius: 16px;

    padding: 22px;

    margin-bottom: 22px;

    box-shadow:
        0 6px 25px rgba(15, 23, 42, .05);
}


.filter-heading {

    display: flex;

    align-items: center;
    justify-content: space-between;

    margin-bottom: 18px;
}


.filter-heading h5 {

    margin: 0;

    font-size: 16px;

    font-weight: 700;
}


.filter-count {

    padding: 5px 10px;

    border-radius: 20px;

    background: rgba(67, 97, 238, .1);

    color: var(--primary);

    font-size: 12px;

    font-weight: 700;
}


.form-label {

    font-size: 12px;

    font-weight: 700;

    color: #475569;

    margin-bottom: 7px;
}


.form-control,
.form-select {

    min-height: 42px;

    border-radius: 9px;

    border: 1px solid #dce1e8;

    box-shadow: none;

    font-size: 13px;
}


.form-control:focus,
.form-select:focus {

    border-color: var(--primary);

    box-shadow:
        0 0 0 3px rgba(67, 97, 238, .1);
}


/* =====================================================
   STAT CARDS
===================================================== */

.result-stat {

    background: #fff;

    border: 1px solid var(--border);

    border-radius: 15px;

    padding: 18px;

    height: 100%;

    box-shadow:
        0 5px 20px rgba(15, 23, 42, .04);
}


.result-stat-inner {

    display: flex;

    align-items: center;

    gap: 14px;
}


.stat-icon {

    width: 45px;
    height: 45px;

    border-radius: 12px;

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 17px;
}


.stat-icon.blue {

    background: rgba(67, 97, 238, .1);

    color: var(--primary);
}


.stat-icon.green {

    background: rgba(25, 135, 84, .1);

    color: var(--success);
}


.stat-icon.orange {

    background: rgba(245, 158, 11, .1);

    color: var(--warning);
}


.stat-label {

    color: var(--muted);

    font-size: 12px;

    margin-bottom: 3px;
}


.stat-value {

    font-size: 21px;

    font-weight: 800;

    line-height: 1.1;
}


/* =====================================================
   RESULTS TABLE
===================================================== */

.results-card {

    background: #fff;

    border: 1px solid var(--border);

    border-radius: 16px;

    overflow: hidden;

    box-shadow:
        0 6px 25px rgba(15, 23, 42, .05);
}


.results-card-header {

    padding: 18px 20px;

    border-bottom: 1px solid var(--border);

    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 15px;
}


.results-card-header h5 {

    margin: 0;

    font-size: 16px;

    font-weight: 700;
}


.results-table-wrapper {

    overflow-x: auto;
}


.results-table {

    width: 100%;

    min-width: 950px;

    border-collapse: collapse;
}


.results-table thead th {

    padding: 13px 15px;

    background: #f8fafc;

    border-bottom: 1px solid var(--border);

    color: #64748b;

    font-size: 11px;

    text-transform: uppercase;

    letter-spacing: .03em;

    font-weight: 800;

    white-space: nowrap;
}


.results-table tbody td {

    padding: 15px;

    border-bottom: 1px solid #eef1f5;

    font-size: 13px;

    vertical-align: middle;
}


.results-table tbody tr:hover {

    background: #fafbff;
}


/* =====================================================
   STUDENT
===================================================== */

.student-wrapper {

    display: flex;

    align-items: center;

    gap: 10px;
}


.student-avatar {

    width: 36px;
    height: 36px;

    min-width: 36px;

    border-radius: 10px;

    background: rgba(67, 97, 238, .1);

    color: var(--primary);

    display: flex;

    align-items: center;
    justify-content: center;

    font-weight: 800;

    font-size: 12px;
}


.student-name {

    font-weight: 700;

    color: #263247;
}


/* =====================================================
   CLASS BADGE
===================================================== */

.class-badge {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 6px 10px;

    border-radius: 8px;

    background: rgba(67, 97, 238, .1);

    color: var(--primary);

    font-weight: 800;

    font-size: 11px;

    white-space: nowrap;
}


/* =====================================================
   SUBJECT BADGE
===================================================== */

.subject-badge {

    display: inline-block;

    padding: 6px 10px;

    border-radius: 8px;

    background: rgba(13, 110, 253, .1);

    color: #0d6efd;

    font-size: 11px;

    font-weight: 700;
}


/* =====================================================
   SCORE
===================================================== */

.score {

    font-weight: 800;

    color: #263247;

    white-space: nowrap;
}


/* =====================================================
   PERCENTAGE
===================================================== */

.percentage-cell {

    text-align: center;

    min-width: 105px;
}


.percentage-pill {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    min-width: 72px;

    padding: 7px 12px;

    border-radius: 20px;

    font-size: 12px;

    font-weight: 900;

    line-height: 1;

    border: 1px solid transparent;
}


.percentage-cell.high
.percentage-pill {

    background: rgba(25, 135, 84, .1);

    color: #198754;

    border-color: rgba(10, 22, 17, 0.2);
}


.percentage-cell.medium
.percentage-pill {

    background: rgba(245, 158, 11, .1);

    color: #b45309;

    border-color: rgba(245, 158, 11, .2);
}


.percentage-cell.low
.percentage-pill {

    background: rgba(220, 53, 69, .1);

    color: #dc3545;

    border-color: rgba(220, 53, 69, .2);
}


/*
 * IMPORTANT:
 *
 * The percentage is rendered as plain HTML/PHP.
 * JavaScript is NOT responsible for displaying it.
 */


/* =====================================================
   DATE
===================================================== */

.result-date {

    color: #64748b;

    font-size: 12px;

    white-space: nowrap;
}


/* =====================================================
   EMPTY STATE
===================================================== */

.empty-state {

    padding: 65px 20px;

    text-align: center;

    color: var(--muted);
}


.empty-state-icon {

    width: 65px;
    height: 65px;

    margin: 0 auto 15px;

    border-radius: 18px;

    background: rgba(67, 97, 238, .08);

    color: var(--primary);

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 25px;
}


.empty-state h4 {

    margin-bottom: 7px;

    color: #263247;

    font-weight: 700;
}


/* =====================================================
   PAGINATION
===================================================== */

.pagination-wrapper {

    padding: 18px 20px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

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

    font-weight: 700;
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

        align-items: flex-start;
    }

}


@media (max-width: 575px) {

    .page-title {

        font-size: 20px;
    }

    .filter-card {

        padding: 16px;
    }

    .results-card-header {

        align-items: flex-start;

        flex-direction: column;
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

```
<div class="sidebar-brand">

    <h3>
        <i class="fas fa-graduation-cap me-2"></i>
        Examcenter
    </h3>

    <div class="admin-info">

        <small>Welcome back,</small>

        <h6>
            <?= e($teacher['last_name']) ?>
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
        <i class="fas fa-file-alt"></i>
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
```

</div>

<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<div class="main-content">

```
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
                View, filter and export examination results.
            </p>

        </div>

    </div>


    <div class="d-flex gap-2">

        <form
            method="POST"
            class="m-0"
        >

            <input
                type="hidden"
                name="selected_class"
                value="<?= e($class_filter) ?>"
            >

            <input
                type="hidden"
                name="selected_subject"
                value="<?= e($subject_filter) ?>"
            >

            <input
                type="hidden"
                name="selected_title"
                value="<?= e($test_title_filter) ?>"
            >

            <input
                type="hidden"
                name="selected_year"
                value="<?= e($year_filter) ?>"
            >

            <input
                type="hidden"
                name="student_name"
                value="<?= e($student_name_filter) ?>"
            >

            <button
                type="submit"
                name="export_results"
                value="1"
                class="btn btn-outline-primary"
            >
                <i class="fas fa-file-word me-1"></i>
                Export
            </button>

        </form>


        <button
            type="button"
            id="sidebarToggle"
            class="sidebar-toggle"
            aria-label="Toggle sidebar"
        >
            <i class="fas fa-bars"></i>
        </button>

    </div>

</div>


<!-- =====================================================
     ALERTS
====================================================== -->

<?php if ($error !== ''): ?>

    <div
        class="alert alert-danger"
        role="alert"
    >
        <i class="fas fa-circle-exclamation me-2"></i>
        <?= e($error) ?>
    </div>

<?php endif; ?>


<?php if ($success !== ''): ?>

    <div
        class="alert alert-success"
        role="alert"
    >
        <i class="fas fa-circle-check me-2"></i>
        <?= e($success) ?>
    </div>

<?php endif; ?>


<div class="container-fluid px-0">


    <!-- =================================================
         STATISTICS
    ================================================== -->

    <div class="row g-3 mb-4">

        <div class="col-xl-4 col-md-4">

            <div class="result-stat">

                <div class="result-stat-inner">

                    <div class="stat-icon blue">
                        <i class="fas fa-file-circle-check"></i>
                    </div>

                    <div>

                        <div class="stat-label">
                            Total Results
                        </div>

                        <div class="stat-value">
                            <?= number_format($total_results) ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-xl-4 col-md-4">

            <div class="result-stat">

                <div class="result-stat-inner">

                    <div class="stat-icon green">
                        <i class="fas fa-percent"></i>
                    </div>

                    <div>

                        <div class="stat-label">
                            Current Page Average
                        </div>

                        <div class="stat-value">

                            <?= number_format(
                                $average_percentage,
                                2
                            ) ?>%

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-xl-4 col-md-4">

            <div class="result-stat">

                <div class="result-stat-inner">

                    <div class="stat-icon orange">
                        <i class="fas fa-filter"></i>
                    </div>

                    <div>

                        <div class="stat-label">
                            Active Filters
                        </div>

                        <div class="stat-value">
                            <?= $has_filters ? 'Yes' : 'None' ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =================================================
         FILTERS
    ================================================== -->

    <div class="filter-card">

        <div class="filter-heading">

            <h5>
                <i class="fas fa-sliders me-2"></i>
                Filter Results
            </h5>


            <?php

            $active_filter_count = 0;

            foreach ([
                $class_filter,
                $subject_filter,
                $test_title_filter,
                $year_filter,
                $student_name_filter
            ] as $filter) {

                if ($filter !== '') {
                    $active_filter_count++;
                }
            }

            ?>


            <?php if ($active_filter_count > 0): ?>

                <span class="filter-count">

                    <?= $active_filter_count ?>

                    active filter
                    <?= $active_filter_count !== 1
                        ? 's'
                        : '' ?>

                </span>

            <?php endif; ?>

        </div>


        <form
            method="GET"
            action="view_results.php"
        >

            <div class="row g-3">


                <!-- CLASS -->

                <div class="col-xl-2 col-md-4">

                    <label
                        class="form-label"
                        for="classSelect"
                    >
                        Class
                    </label>

                    <select
                        name="selected_class"
                        id="classSelect"
                        class="form-select"
                    >

                        <option value="">
                            All Classes
                        </option>


                        <?php foreach ($classes as $class): ?>

                            <?php
                            $level =
                                trim(
                                    $class['level_code']
                                    ?? ''
                                );
                            ?>

                            <?php if ($level !== ''): ?>

                                <option
                                    value="<?= e($level) ?>"
                                    <?= $class_filter === $level
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($level) ?>
                                </option>

                            <?php endif; ?>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- SUBJECT -->

                <div class="col-xl-2 col-md-4">

                    <label
                        class="form-label"
                        for="subjectSelect"
                    >
                        Subject
                    </label>

                    <select
                        name="selected_subject"
                        id="subjectSelect"
                        class="form-select"
                    >

                        <option value="">
                            All Subjects
                        </option>


                        <?php foreach ($subjects as $subject): ?>

                            <option
                                value="<?= e($subject['subject']) ?>"
                                <?= $subject_filter ===
                                    $subject['subject']
                                        ? 'selected'
                                        : '' ?>
                            >
                                <?= e($subject['subject']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- TEST -->

                <div class="col-xl-2 col-md-4">

                    <label
                        class="form-label"
                        for="testSelect"
                    >
                        Test
                    </label>

                    <select
                        name="selected_title"
                        id="testSelect"
                        class="form-select"
                    >

                        <option value="">
                            All Tests
                        </option>


                        <?php foreach ($test_titles as $test): ?>

                            <option
                                value="<?= e($test['title']) ?>"
                                <?= $test_title_filter ===
                                    $test['title']
                                        ? 'selected'
                                        : '' ?>
                            >
                                <?= e($test['title']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- YEAR -->

                <div class="col-xl-2 col-md-4">

                    <label
                        class="form-label"
                        for="yearSelect"
                    >
                        Year
                    </label>

                    <select
                        name="selected_year"
                        id="yearSelect"
                        class="form-select"
                    >

                        <option value="">
                            All Years
                        </option>


                        <?php foreach ($years as $year): ?>

                            <option
                                value="<?= e($year['year']) ?>"
                                <?= $year_filter ===
                                    $year['year']
                                        ? 'selected'
                                        : '' ?>
                            >
                                <?= e($year['year']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- STUDENT -->

                <div class="col-xl-3 col-md-6">

                    <label
                        class="form-label"
                        for="studentInput"
                    >
                        Student
                    </label>

                    <input
                        type="text"
                        name="student_name"
                        id="studentInput"
                        class="form-control"
                        value="<?= e($student_name_filter) ?>"
                        placeholder="Search student name..."
                    >

                </div>


                <!-- ACTIONS -->

                <div class="col-xl-1 col-md-6">

                    <label class="form-label">
                        &nbsp;
                    </label>

                    <button
                        type="submit"
                        class="btn btn-primary w-100"
                        style="min-height:42px;"
                    >
                        <i class="fas fa-filter"></i>
                    </button>

                </div>

            </div>

        </form>

    </div>


    <!-- =================================================
         RESULTS
    ================================================== -->

    <div class="results-card">


        <div class="results-card-header">

            <div>

                <h5>
                    Examination Results
                </h5>

                <small class="text-muted">

                    <?php if ($total_results > 0): ?>

                        Showing
                        <?= $start_result ?>
                        –
                        <?= $end_result ?>
                        of
                        <?= number_format($total_results) ?>

                    <?php else: ?>

                        No results found

                    <?php endif; ?>

                </small>

            </div>


            <?php if ($has_filters): ?>

                <a
                    href="view_results.php"
                    class="btn btn-outline-secondary btn-sm"
                >
                    <i class="fas fa-xmark me-1"></i>
                    Clear Filters
                </a>

            <?php endif; ?>

        </div>


        <?php if (!empty($results)): ?>


            <div class="results-table-wrapper">

                <table class="results-table">

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

                        /*
                         * SCORE
                         */
                        $score =
                            (float) (
                                $result['score'] ?? 0
                            );


                        /*
                         * TOTAL QUESTIONS
                         *
                         * This is the denominator.
                         */
                        $total_questions =
                            (float) (
                                $result['total_questions']
                                ?? 0
                            );


                        /*
                         * PERCENTAGE
                         *
                         * Explicitly calculated in PHP.
                         */
                        $percentage =
                            calculatePercentage(
                                $score,
                                $total_questions
                            );


                        /*
                         * CLASS
                         *
                         * Source:
                         * academic_levels.level_code
                         */
                        $class_name =
                            formatClassName(
                                $result['academic_level']
                                ?? '',
                                ''
                            );


                        /*
                         * STUDENT
                         */
                        $student_name =
                            trim(
                                $result['student_name']
                                ?? 'Unknown Student'
                            );


                        $student_initial =
                            strtoupper(
                                substr(
                                    $student_name,
                                    0,
                                    1
                                )
                            );


                        $percentage_class =
                            percentageClass(
                                $percentage
                            );

                        ?>


                        <tr>


                            <!-- STUDENT -->

                            <td>

                                <div class="student-wrapper">

                                    <span
                                        class="student-avatar"
                                    >
                                        <?= e(
                                            $student_initial
                                        ) ?>
                                    </span>


                                    <span
                                        class="student-name"
                                    >
                                        <?= e(
                                            $student_name
                                        ) ?>
                                    </span>

                                </div>

                            </td>


                            <!-- CLASS -->

                            <td>

                                <span class="class-badge">

                                    <i class="fas fa-users"></i>

                                    <?= e(
                                        $class_name
                                    ) ?>

                                </span>

                            </td>


                            <!-- TEST -->

                            <td>

                                <strong>
                                    <?= e(
                                        $result['test_title']
                                        ?? 'Unknown Test'
                                    ) ?>
                                </strong>

                            </td>


                            <!-- SUBJECT -->

                            <td>

                                <span class="subject-badge">

                                    <?= e(
                                        $result['subject']
                                        ?? ''
                                    ) ?>

                                </span>

                            </td>


                            <!-- SCORE -->

                            <td>

                                <span class="score">

                                    <?= e(
                                        $score
                                    ) ?>

                                    /

                                    <?= e(
                                        $total_questions
                                    ) ?>

                                </span>

                            </td>


                            <!-- PERCENTAGE -->

                            <td class="percentage-cell <?= e(
                                $percentage_class
                            ) ?>">

                                <span class="percentage-pill">

                                    <?= number_format(
                                        $percentage,
                                        2
                                    ) ?>%

                                </span>

                            </td>


                            <!-- DATE -->

                            <td>

                                <span
                                    class="result-date"
                                >

                                    <i
                                        class="far fa-clock me-1"
                                    ></i>

                                    <?= e(
                                        date(
                                            'M j, Y g:i A',
                                            strtotime(
                                                $result['created_at']
                                            )
                                        )
                                    ) ?>

                                </span>

                            </td>


                            <!-- YEAR -->

                            <td>

                                <strong>

                                    <?= e(
                                        $result['year']
                                        ?? ''
                                    ) ?>

                                </strong>

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
                        <strong>
                            <?= $start_result ?>
                        </strong>

                        –

                        <strong>
                            <?= $end_result ?>
                        </strong>

                        of

                        <strong>
                            <?= number_format(
                                $total_results
                            ) ?>
                        </strong>

                        results

                    </div>


                    <nav
                        aria-label="Results pagination"
                    >

                        <ul class="pagination">


                            <!-- PREVIOUS -->

                            <li
                                class="page-item <?= $current_page <= 1
                                    ? 'disabled'
                                    : '' ?>"
                            >

                                <?php if ($current_page > 1): ?>

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            $pagination_url(
                                                $current_page - 1
                                            )
                                        ) ?>"
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


                            <!-- FIRST -->

                            <?php if ($start_page > 1): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            $pagination_url(1)
                                        ) ?>"
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

                            <?php for (
                                $page = $start_page;
                                $page <= $end_page;
                                $page++
                            ): ?>

                                <li
                                    class="page-item <?= $page ===
                                        $current_page
                                            ? 'active'
                                            : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            $pagination_url(
                                                $page
                                            )
                                        ) ?>"
                                    >
                                        <?= $page ?>
                                    </a>

                                </li>

                            <?php endfor; ?>


                            <!-- LAST -->

                            <?php if (
                                $end_page <
                                $total_pages
                            ): ?>

                                <?php if (
                                    $end_page <
                                    $total_pages - 1
                                ): ?>

                                    <li class="page-item disabled">

                                        <span class="page-link">
                                            ...
                                        </span>

                                    </li>

                                <?php endif; ?>


                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            $pagination_url(
                                                $total_pages
                                            )
                                        ) ?>"
                                    >
                                        <?= $total_pages ?>
                                    </a>

                                </li>

                            <?php endif; ?>


                            <!-- NEXT -->

                            <li
                                class="page-item <?= $current_page >=
                                    $total_pages
                                        ? 'disabled'
                                        : '' ?>"
                            >

                                <?php if (
                                    $current_page <
                                    $total_pages
                                ): ?>

                                    <a
                                        class="page-link"
                                        href="<?= e(
                                            $pagination_url(
                                                $current_page + 1
                                            )
                                        ) ?>"
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

                <div class="empty-state-icon">

                    <i class="fas fa-file-circle-question"></i>

                </div>


                <h4>
                    No examination results found
                </h4>


                <p>

                    <?php if ($has_filters): ?>

                        No results match the selected filters.
                        Try changing or clearing the filters.

                    <?php else: ?>

                        No examination results are available yet.

                    <?php endif; ?>

                </p>


                <?php if ($has_filters): ?>

                    <a
                        href="view_results.php"
                        class="btn btn-outline-secondary"
                    >
                        <i class="fas fa-xmark me-1"></i>
                        Clear Filters
                    </a>

                <?php endif; ?>

            </div>


        <?php endif; ?>


    </div>


</div>
```

</div>

<!-- =========================================================
     SCRIPTS
========================================================= -->

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const sidebarToggle =
            document.getElementById(
                'sidebarToggle'
            );

        const sidebar =
            document.querySelector(
                '.sidebar'
            );


        if (
            sidebarToggle &&
            sidebar
        ) {

            sidebarToggle.addEventListener(
                'click',
                function () {

                    sidebar.classList.toggle(
                        'active'
                    );

                }
            );

        }


        /*
         * Close mobile sidebar
         * when a menu item is clicked.
         */

        if (sidebar) {

            sidebar
                .querySelectorAll('a')
                .forEach(function (link) {

                    link.addEventListener(
                        'click',
                        function () {

                            if (
                                window.innerWidth <= 991
                            ) {

                                sidebar.classList.remove(
                                    'active'
                                );

                            }

                        }
                    );

                });

        }

    }

);

</script>

</body>

</html>
