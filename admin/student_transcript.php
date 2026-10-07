<?php

// ================================================================
// student_transcript.php
// ================================================================

session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../includes/universal_architecture.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;


// ================================================================
// AUTHENTICATION
// ================================================================

if (!isset($_SESSION['user_id'])) {

    http_response_code(403);
    exit('Unauthorized.');

}


// ================================================================
// DATABASE
// ================================================================

$database = Database::getInstance();
$conn = $database->getConnection();

if (!$conn) {

    http_response_code(500);
    exit('Database connection failed.');

}

$universal_transcript_available = examcenterUniversalTableExists($conn, 'organizational_units')
    && examcenterUniversalTableExists($conn, 'courses')
    && examcenterUniversalTableExists($conn, 'academic_periods')
    && examcenterUniversalTableExists($conn, 'programmes')
    && examcenterUniversalTableExists($conn, 'programme_levels')
    && examcenterUniversalColumnExists($conn, 'tests', 'organizational_unit_id')
    && examcenterUniversalColumnExists($conn, 'tests', 'course_id')
    && examcenterUniversalColumnExists($conn, 'tests', 'programme_id')
    && examcenterUniversalColumnExists($conn, 'tests', 'programme_level_id')
    && examcenterUniversalColumnExists($conn, 'tests', 'academic_period_id')
    && examcenterUniversalColumnExists($conn, 'results', 'academic_period_id');


// ================================================================
// VERIFY ADMIN
// ================================================================

$adminId = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT id, username, role
    FROM admins
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $adminId);
$stmt->execute();

$adminResult = $stmt->get_result();
$admin = $adminResult->fetch_assoc();

$stmt->close();


if (
    !$admin ||
    strtolower(trim((string) $admin['role'])) !== 'admin'
) {

    http_response_code(403);
    exit('Unauthorized.');

}


// ================================================================
// GET STUDENT ID
// ================================================================

$studentId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$studentId || $studentId <= 0) {

    http_response_code(400);
    exit('Invalid student ID.');

}


// ================================================================
// FILTERS
// ================================================================

$testTitleFilter = trim(
    (string) ($_GET['test_title'] ?? '')
);

$academicYearFilter = trim(
    (string) ($_GET['academic_year'] ?? '')
);


// ================================================================
// FETCH STUDENT
// ================================================================

$stmt = $conn->prepare("
    SELECT
        s.id,
        s.full_name,
        s.class AS class_id,
        c.class_name,
        s.email,
        s.phone,
        s.photo,
        s.address,
        s.role,
        s.created_at,
        s.updated_at

    FROM students s

    LEFT JOIN classes c
        ON s.class = c.id

    WHERE s.id = ?

    LIMIT 1
");

$stmt->bind_param("i", $studentId);
$stmt->execute();

$result = $stmt->get_result();

$student = $result->fetch_assoc();

$stmt->close();


if (!$student) {

    http_response_code(404);
    exit('Student not found.');

}


// ================================================================
// STUDENT VALUES
// ================================================================

$studentName =
    !empty($student['full_name'])
        ? $student['full_name']
        : 'Student';

$className =
    !empty($student['class_name'])
        ? $student['class_name']
        : 'Not assigned';

$email =
    !empty($student['email'])
        ? $student['email']
        : 'Not provided';

$phone =
    !empty($student['phone'])
        ? $student['phone']
        : 'Not provided';

$address =
    !empty($student['address'])
        ? $student['address']
        : 'Not provided';

$universal_student_context = null;

if ($universal_transcript_available && examcenterUniversalTableExists($conn, 'institution_memberships')) {
    $stmt = $conn->prepare(
        "SELECT ou.name, ou.code
         FROM institution_memberships im
         INNER JOIN unit_memberships um
             ON um.institution_membership_id = im.id
         INNER JOIN organizational_units ou
             ON ou.id = um.organizational_unit_id
         WHERE im.legacy_student_id = ?
           AND im.status = 'active'
           AND um.status = 'active'
         ORDER BY ou.name
         LIMIT 1"
    );
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $universal_student_context = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}


// ================================================================
// FETCH RESULTS
// ================================================================

$sql = "
    SELECT

        r.id AS result_id,
        r.test_id,
        r.score,
        r.total_questions,
        r.status,
        r.created_at AS taken_at,

        t.title AS exam_title,
        t.subject,
        t.year AS academic_year,
        t.duration";

if ($universal_transcript_available) {
    $sql .= ",
        ou.name,
        co.code,
        co.name,
        p.programme_name,
        pl.level_name AS programme_level_name,
        ap.name,
        ap.period_type";
}

$sql .= "

    FROM results r

    INNER JOIN tests t
        ON r.test_id = t.id

";

if ($universal_transcript_available) {
    $sql .= "
    LEFT JOIN organizational_units ou
        ON ou.id = t.organizational_unit_id
    LEFT JOIN courses co
        ON co.id = t.course_id
    LEFT JOIN programmes p
        ON p.id = t.programme_id
    LEFT JOIN programme_levels pl
        ON pl.id = t.programme_level_id
    LEFT JOIN academic_periods ap
        ON ap.id = COALESCE(r.academic_period_id, t.academic_period_id)
    ";
}

$sql .= " WHERE r.user_id = ?
";

$types = "i";
$params = [$studentId];


if ($testTitleFilter !== '') {

    $sql .= "
        AND t.title LIKE ?
    ";

    $types .= "s";

    $params[] =
        '%' . $testTitleFilter . '%';

}


if ($academicYearFilter !== '') {

    $sql .= "
        AND t.year = ?
    ";

    $types .= "s";

    $params[] =
        $academicYearFilter;

}


$sql .= "
    ORDER BY
        t.year DESC,
        r.created_at DESC
";


$stmt = $conn->prepare($sql);

$stmt->bind_param(
    $types,
    ...$params
);

$stmt->execute();

$result = $stmt->get_result();

$studentResults = [];

while ($row = $result->fetch_assoc()) {

    $studentResults[] = $row;

}

$stmt->close();


// ================================================================
// HTML ESCAPE
// ================================================================

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


// ================================================================
// DOMPDF OPTIONS
// ================================================================

$options = new Options();

$options->set(
    'isHtml5ParserEnabled',
    true
);

$options->set(
    'isRemoteEnabled',
    true
);

$options->set(
    'defaultFont',
    'DejaVu Sans'
);


$dompdf = new Dompdf($options);


// ================================================================
// TRANSCRIPT HTML
// ================================================================

ob_start();

?>

<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<style>

@page {
    margin: 30px 35px 35px 35px;
}

body {

    font-family: DejaVu Sans, sans-serif;

    color: #222;

    font-size: 11px;

    margin: 0;

}

.header {

    text-align: center;

    border-bottom: 2px solid #1d4ed8;

    padding-bottom: 15px;

    margin-bottom: 20px;

}

.header h1 {

    margin: 0;

    font-size: 20px;

    color: #1d4ed8;

}

.header h2 {

    margin: 5px 0 0;

    font-size: 14px;

}

.header p {

    margin: 4px 0 0;

    color: #666;

    font-size: 10px;

}


/* ================================================================
   STUDENT INFORMATION
================================================================ */

.student-box {

    border: 1px solid #dfe4ea;

    padding: 15px;

    margin-bottom: 20px;

}

.student-title {

    font-size: 14px;

    font-weight: bold;

    margin-bottom: 12px;

    color: #1d4ed8;

}

.info-table {

    width: 100%;

    border-collapse: collapse;

}

.info-table td {

    padding: 7px 5px;

    vertical-align: top;

}

.info-label {

    color: #666;

    width: 22%;

    font-weight: bold;

}

.info-value {

    width: 28%;

}


/* ================================================================
   RESULTS
================================================================ */

.section-title {

    font-size: 14px;

    font-weight: bold;

    color: #1d4ed8;

    margin-bottom: 10px;

}

.results-table {

    width: 100%;

    border-collapse: collapse;

    margin-top: 5px;

}

.results-table th {

    background: #1d4ed8;

    color: white;

    padding: 8px 6px;

    text-align: left;

    font-size: 9px;

}

.results-table td {

    border: 1px solid #dfe4ea;

    padding: 8px 6px;

    font-size: 9px;

}

.results-table tr {

    page-break-inside: avoid;

}

.results-table tr:nth-child(even) td {

    background: #f8fafc;

}

.score {

    font-weight: bold;

}

.percentage {

    font-weight: bold;

}


/* ================================================================
   FILTER
================================================================ */

.filter-note {

    margin-bottom: 10px;

    padding: 7px 10px;

    background: #f1f5f9;

    border: 1px solid #e2e8f0;

    font-size: 9px;

    color: #475569;

}


/* ================================================================
   FOOTER
================================================================ */

.footer {

    margin-top: 35px;

    padding-top: 10px;

    border-top: 1px solid #dfe4ea;

    font-size: 8px;

    color: #777;

}

.signature {

    margin-top: 45px;

    width: 220px;

    border-top: 1px solid #333;

    padding-top: 5px;

    text-align: center;

    font-size: 9px;

}

</style>

</head>


<body>


<!-- ============================================================
     HEADER
============================================================= -->

<div class="header">

    <h1>
        STUDENT TRANSCRIPT
    </h1>

    <h2>
        <?= e($studentName) ?>
    </h2>

    <p>
        Academic Examination Record
    </p>

</div>


<!-- ============================================================
     STUDENT INFORMATION
============================================================= -->

<div class="student-box">

    <div class="student-title">
        Student Information
    </div>

    <table class="info-table">

        <tr>

            <td class="info-label">
                Full Name
            </td>

            <td class="info-value">
                <?= e($studentName) ?>
            </td>

            <td class="info-label">
                Registration No.
            </td>

        </tr>


        <tr>

            <td class="info-label">
                Class
            </td>

            <td class="info-value">
                <?= e($className) ?>
            </td>

            <?php if ($universal_transcript_available): ?>
                <td class="info-label">
                    Unit
                </td>

                <td class="info-value">
                    <?= e($universal_student_context['unit_name'] ?? 'Not assigned') ?>
                </td>
            <?php endif; ?>

            <td class="info-label">
                Email
            </td>

            <td class="info-value">
                <?= e($email) ?>
            </td>

        </tr>


        <tr>

            <td class="info-label">
                Phone
            </td>

            <td class="info-value">
                <?= e($phone) ?>
            </td>

            <td class="info-label">
                Address
            </td>

            <td class="info-value">
                <?= e($address) ?>
            </td>

        </tr>

    </table>

</div>


<!-- ============================================================
     FILTER INFORMATION
============================================================= -->

<?php if (
    $testTitleFilter !== '' ||
    $academicYearFilter !== ''
): ?>

<div class="filter-note">

    <strong>Transcript Filter:</strong>

    <?php if ($testTitleFilter !== ''): ?>

        Test:
        <?= e($testTitleFilter) ?>

    <?php endif; ?>


    <?php if (
        $testTitleFilter !== '' &&
        $academicYearFilter !== ''
    ): ?>

        &nbsp; | &nbsp;

    <?php endif; ?>


    <?php if ($academicYearFilter !== ''): ?>

        Academic Year:
        <?= e($academicYearFilter) ?>

    <?php endif; ?>

</div>

<?php endif; ?>


<!-- ============================================================
     EXAMINATION RESULTS
============================================================= -->

<div class="section-title">

    Examination Results

</div>


<?php if (!empty($studentResults)): ?>

<table class="results-table">

    <thead>

        <tr>

            <th>
                Examination
            </th>

            <th>
                Academic Year
            </th>

            <th>
                Subject
            </th>

            <?php if ($universal_transcript_available): ?>
                <th>Course / Programme</th>
                <th>Academic Period</th>
            <?php endif; ?>

            <th>
                Score
            </th>

            <th>
                Percentage
            </th>

            <th>
                Date Taken
            </th>

        </tr>

    </thead>


    <tbody>

    <?php foreach (
        $studentResults
        as $examResult
    ): ?>


        <?php

        $score =
            (float) (
                $examResult['score'] ?? 0
            );

        $totalQuestions =
            (int) (
                $examResult[
                    'total_questions'
                ] ?? 0
            );

        $percentage =
            $totalQuestions > 0
                ? (
                    $score /
                    $totalQuestions
                ) * 100
                : 0;

        ?>


        <tr>

            <td>

                <?= e(
                    $examResult['exam_title']
                    ?? 'Untitled Exam'
                ) ?>

            </td>


            <td>

                <?= e(
                    $examResult['academic_year']
                    ?? 'Not specified'
                ) ?>

            </td>


            <td>

                <?= e(
                    $examResult['subject']
                    ?? 'Not specified'
                ) ?>

            </td>

            <?php if ($universal_transcript_available): ?>
                <td>
                    <?= e($examResult['course_name'] ?? 'General assessment') ?>
                    <?php if (!empty($examResult['programme_name'])): ?>
                        <br><small><?= e($examResult['programme_name']) ?>
                            <?php if (!empty($examResult['programme_level_name'])): ?>
                                / <?= e($examResult['programme_level_name']) ?>
                            <?php endif; ?>
                        </small>
                    <?php endif; ?>
                </td>

                <td>
                    <?= e($examResult['period_name'] ?? 'Not specified') ?>
                </td>
            <?php endif; ?>


            <td class="score">

                <?= e($score) ?>
                /
                <?= $totalQuestions ?>

            </td>


            <td class="percentage">

                <?= number_format(
                    $percentage,
                    1
                ) ?>%

            </td>


            <td>

                <?= !empty(
                    $examResult['taken_at']
                )
                    ? e(
                        date(
                            'd M Y',
                            strtotime(
                                $examResult[
                                    'taken_at'
                                ]
                            )
                        )
                    )
                    : 'Not available'
                ?>

            </td>

        </tr>


    <?php endforeach; ?>

    </tbody>

</table>


<?php else: ?>


<div class="filter-note">

    No examination results were found
    for the selected criteria.

</div>


<?php endif; ?>


<!-- ============================================================
     SIGNATURE
============================================================= -->

<div class="signature">

    Authorized Signature

</div>


<!-- ============================================================
     FOOTER
============================================================= -->

<div class="footer">

    Generated on
    <?= date('d M Y, h:i A') ?>

    &nbsp; | &nbsp;

    Student ID:
    <?= (int) $student['id'] ?>

</div>


</body>

</html>

<?php

$html = ob_get_clean();


// ================================================================
// GENERATE PDF
// ================================================================

$dompdf->loadHtml($html);

$dompdf->setPaper(
    'A4',
    'portrait'
);

$dompdf->render();


// ================================================================
// DOWNLOAD
// ================================================================

$safeName = preg_replace(
    '/[^a-zA-Z0-9_-]/',
    '_',
    $studentName
);

$filename =
    'Student_Transcript_' .
    $safeName .
    '.pdf';


$dompdf->stream(
    $filename,
    [
        'Attachment' => true
    ]
);

exit;