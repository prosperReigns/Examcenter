<?php
// ================================================================
// manage_session.php
// ================================================================

session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once __DIR__ . '/../license/license_guard.php';

// ---------------------------------------------------------------
// Development error reporting
// ---------------------------------------------------------------
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// ---------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

try {

    $database = Database::getInstance();
    $conn = $database->getConnection();

    $user_id = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare("
        SELECT username, role
        FROM admins
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $user = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$user || strtolower($user['role']) !== 'admin') {

        session_destroy();

        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // -----------------------------------------------------------
    // Admin profile
    // -----------------------------------------------------------

    $stmt = $conn->prepare("
        SELECT username
        FROM admins
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $admin = $stmt->get_result()->fetch_assoc();

    $stmt->close();

} catch (Throwable $e) {

    error_log("Manage session page error: " . $e->getMessage());

    die("System error");
}


// ================================================================
// AJAX ACTIONS
// ================================================================

if (isset($_GET['action'])) {

    $action = trim($_GET['action']);

    header('Content-Type: application/json; charset=utf-8');


    // ============================================================
    // GET SESSIONS
    // ============================================================

    if ($action === 'get_sessions') {

        $year = trim($_GET['year'] ?? '');

        if ($year === '') {

            echo json_encode([
                'success' => false,
                'message' => 'Academic year is required.'
            ]);

            exit();
        }

        try {

            $stmt = $conn->prepare("
                SELECT DISTINCT session
                FROM academic_years
                WHERE year = ?
                  AND session IS NOT NULL
                  AND TRIM(session) <> ''
                ORDER BY session ASC
            ");

            $stmt->bind_param("s", $year);
            $stmt->execute();

            $result = $stmt->get_result();

            $sessions = [];

            while ($row = $result->fetch_assoc()) {
                $sessions[] = $row['session'];
            }

            $stmt->close();


            // ----------------------------------------------------
            // Determine whether this academic year is active.
            // ----------------------------------------------------

            $stmt = $conn->prepare("
                SELECT COUNT(*) AS active_count
                FROM academic_years
                WHERE year = ?
                  AND status = 'active'
            ");

            $stmt->bind_param("s", $year);
            $stmt->execute();

            $row = $stmt->get_result()->fetch_assoc();

            $stmt->close();

            $yearStatus =
                ($row && (int)$row['active_count'] > 0)
                    ? 'active'
                    : 'inactive';


            echo json_encode([
                'success' => true,
                'sessions' => $sessions,
                'year_status' => $yearStatus
            ]);

        } catch (Throwable $e) {

            error_log("get_sessions error: " . $e->getMessage());

            echo json_encode([
                'success' => false,
                'message' => 'Unable to load sessions.'
            ]);
        }

        exit();
    }


    // ============================================================
    // GET EXAM TITLES
    // ============================================================

    if ($action === 'get_exams') {

        $year = trim($_GET['year'] ?? '');
        $session = trim($_GET['session'] ?? '');

        if ($year === '' || $session === '') {

            echo json_encode([
                'success' => false,
                'message' => 'Academic year and session are required.'
            ]);

            exit();
        }

        try {

            $stmt = $conn->prepare("
                SELECT DISTINCT exam_title
                FROM academic_years
                WHERE year = ?
                  AND session = ?
                  AND exam_title IS NOT NULL
                  AND TRIM(exam_title) <> ''
                ORDER BY exam_title ASC
            ");

            $stmt->bind_param(
                "ss",
                $year,
                $session
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $exams = [];

            while ($row = $result->fetch_assoc()) {
                $exams[] = $row['exam_title'];
            }

            $stmt->close();


            echo json_encode([
                'success' => true,
                'exams' => $exams
            ]);

        } catch (Throwable $e) {

            error_log("get_exams error: " . $e->getMessage());

            echo json_encode([
                'success' => false,
                'message' => 'Unable to load exam titles.'
            ]);
        }

        exit();
    }


    // ============================================================
    // ADD SESSION
    // ============================================================

    if ($action === 'add_session') {

        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        $year = trim($input['year'] ?? '');
        $sessionName = trim($input['session'] ?? '');

        if ($year === '' || $sessionName === '') {

            echo json_encode([
                'success' => false,
                'message' => 'Year and session are required.'
            ]);

            exit();
        }

        try {

            // ----------------------------------------------------
            // Prevent duplicate session for the same year.
            // ----------------------------------------------------

            $stmt = $conn->prepare("
                SELECT id
                FROM academic_years
                WHERE year = ?
                  AND session = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                "ss",
                $year,
                $sessionName
            );

            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {

                $stmt->close();

                echo json_encode([
                    'success' => false,
                    'message' => 'Session already exists for this year.'
                ]);

                exit();
            }

            $stmt->close();


            // ----------------------------------------------------
            // Session is an available option, not the active
            // academic-year selection.
            // ----------------------------------------------------

            $status = 'inactive';

            $stmt = $conn->prepare("
                INSERT INTO academic_years
                    (year, session, exam_title, status)
                VALUES
                    (?, ?, NULL, ?)
            ");

            $stmt->bind_param(
                "sss",
                $year,
                $sessionName,
                $status
            );

            $ok = $stmt->execute();

            $stmt->close();


            if ($ok) {

                echo json_encode([
                    'success' => true,
                    'message' => 'Session added successfully.'
                ]);

            } else {

                echo json_encode([
                    'success' => false,
                    'message' => 'Could not add session.'
                ]);
            }

        } catch (Throwable $e) {

            error_log("add_session error: " . $e->getMessage());

            echo json_encode([
                'success' => false,
                'message' => 'Unable to add session.'
            ]);
        }

        exit();
    }


    // ============================================================
    // ADD EXAM TITLE
    // ============================================================

    if ($action === 'add_exam') {

        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        $year = trim($input['year'] ?? '');
        $sessionName = trim($input['session'] ?? '');
        $examTitle = trim($input['exam'] ?? '');

        if (
            $year === '' ||
            $sessionName === '' ||
            $examTitle === ''
        ) {

            echo json_encode([
                'success' => false,
                'message' => 'Year, session and exam title are required.'
            ]);

            exit();
        }

        try {

            // ----------------------------------------------------
            // Prevent duplicate exam title.
            // ----------------------------------------------------

            $stmt = $conn->prepare("
                SELECT id
                FROM academic_years
                WHERE year = ?
                  AND session = ?
                  AND exam_title = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                "sss",
                $year,
                $sessionName,
                $examTitle
            );

            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {

                $stmt->close();

                echo json_encode([
                    'success' => false,
                    'message' => 'This exam title already exists for the year/session.'
                ]);

                exit();
            }

            $stmt->close();


            $status = 'inactive';

            $stmt = $conn->prepare("
                INSERT INTO academic_years
                    (year, session, exam_title, status)
                VALUES
                    (?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "ssss",
                $year,
                $sessionName,
                $examTitle,
                $status
            );

            $ok = $stmt->execute();

            $stmt->close();


            if ($ok) {

                echo json_encode([
                    'success' => true,
                    'message' => 'Exam title added successfully.'
                ]);

            } else {

                echo json_encode([
                    'success' => false,
                    'message' => 'Could not add exam title.'
                ]);
            }

        } catch (Throwable $e) {

            error_log("add_exam error: " . $e->getMessage());

            echo json_encode([
                'success' => false,
                'message' => 'Unable to add exam title.'
            ]);
        }

        exit();
    }


    // ============================================================
    // TOGGLE ACADEMIC YEAR
    // ============================================================

    if ($action === 'toggle_year') {

        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        $year = trim($input['year'] ?? '');

        if ($year === '') {

            echo json_encode([
                'success' => false,
                'message' => 'Academic year is required.'
            ]);

            exit();
        }

        try {

            // ----------------------------------------------------
            // ACADEMIC YEAR RULE
            // ----------------------------------------------------
            // Only ONE academic year may be active at a time.
            //
            // Activating an inactive year therefore means:      
            //   1. Deactivate every other academic year.
            //   2. Activate every row belonging to the selected year.
            //
            // Deactivating the currently active year is allowed;
            // this leaves the system with no active academic year
            // until another year is activated.
            // ----------------------------------------------------

            $conn->begin_transaction();

            $stmt = $conn->prepare("
                SELECT COUNT(*) AS active_count
                FROM academic_years
                WHERE year = ?
                  AND status = 'active'
            ");

            if (!$stmt) {
                throw new Exception('Unable to check academic year status.');
            }

            $stmt->bind_param("s", $year);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $currentlyActive =
                ($row && (int)$row['active_count'] > 0);

            $newStatus = $currentlyActive ? 'inactive' : 'active';

            if ($newStatus === 'active') {
                // First deactivate ALL other years.
                $stmt = $conn->prepare("
                    UPDATE academic_years
                    SET status = 'inactive'
                    WHERE year <> ?
                      AND status = 'active'
                ");

                if (!$stmt) {
                    throw new Exception('Unable to deactivate the previous academic year.');
                }

                $stmt->bind_param("s", $year);
                $stmt->execute();
                $stmt->close();
            }

            // Then set every row belonging to the selected year
            // to the requested status.
            $stmt = $conn->prepare("
                UPDATE academic_years
                SET status = ?
                WHERE year = ?
            ");

            if (!$stmt) {
                throw new Exception('Unable to update academic year status.');
            }

            $stmt->bind_param("ss", $newStatus, $year);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if (!$ok) {
                throw new Exception('Could not update academic year status.');
            }

            $conn->commit();


            if ($ok) {

                echo json_encode([
                    'success' => true,
                    'status' => $newStatus,
                    'affected_rows' => $affected,
                    'message' =>
                        $newStatus === 'active'
                            ? 'Academic year activated successfully.'
                            : 'Academic year deactivated successfully.'
                ]);

            } else {

                echo json_encode([
                    'success' => false,
                    'message' => 'Could not update academic year status.'
                ]);
            }

        } catch (Throwable $e) {

            error_log("toggle_year error: " . $e->getMessage());

            echo json_encode([
                'success' => false,
                'message' => 'Unable to update academic year status.'
            ]);
        }

        exit();
    }


    // ============================================================
    // DELETE ACADEMIC YEAR
    // ============================================================

    if ($action === 'delete_year') {

        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        $year = trim($input['year'] ?? '');

        if ($year === '') {

            echo json_encode([
                'success' => false,
                'message' => 'Academic year is required.'
            ]);

            exit();
        }

        try {

            $stmt = $conn->prepare("
                DELETE FROM academic_years
                WHERE year = ?
            ");

            $stmt->bind_param("s", $year);

            $ok = $stmt->execute();

            $stmt->close();


            if ($ok) {

                echo json_encode([
                    'success' => true,
                    'message' => 'Academic year deleted successfully.'
                ]);

            } else {

                echo json_encode([
                    'success' => false,
                    'message' => 'Could not delete academic year.'
                ]);
            }

        } catch (Throwable $e) {

            error_log("delete_year error: " . $e->getMessage());

            echo json_encode([
                'success' => false,
                'message' => 'Unable to delete academic year.'
            ]);
        }

        exit();
    }


    // ============================================================
    // SAVE SELECTION
    //
    // IMPORTANT:
    //
    // This DOES NOT:
    // - insert a new academic year
    // - check "academic year already exists"
    // - create a new year/session/exam combination
    //
    // It updates the existing ACTIVE row belonging to the
    // selected academic year.
    //
    // Only session and exam_title are changed.
    // ============================================================

    if ($action === 'save_selection') {

        $data = json_decode(
            file_get_contents('php://input'),
            true
        );

        $year = trim($data['year'] ?? '');
        $session = trim($data['session'] ?? '');
        $exam = trim($data['exam'] ?? '');


        if (
            $year === '' ||
            $session === '' ||
            $exam === ''
        ) {

            echo json_encode([
                'success' => false,
                'message' =>
                    'Year, session, and exam title are required.'
            ]);

            exit();
        }


        try {

            $conn->begin_transaction();


            // ----------------------------------------------------
            // Find the existing active row for this year.
            //
            // Prefer a row that currently has no session/exam
            // because that is the original academic-year
            // placeholder created by the Add Academic Year form.
            //
            // If there is no placeholder, use the first active
            // row for the year.
            // ----------------------------------------------------

            $stmt = $conn->prepare("
                SELECT id
                FROM academic_years
                WHERE year = ?
                  AND status = 'active'
                ORDER BY
                    CASE
                        WHEN session IS NULL
                         AND exam_title IS NULL
                        THEN 0
                        ELSE 1
                    END,
                    id ASC
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->bind_param("s", $year);

            $stmt->execute();

            $result = $stmt->get_result();

            $activeRow = $result->fetch_assoc();

            $stmt->close();


            // ----------------------------------------------------
            // No active academic-year row exists.
            //
            // IMPORTANT:
            // Do NOT create one here.
            // The user must activate the academic year first.
            // ----------------------------------------------------

            if (!$activeRow) {

                $conn->rollback();

                echo json_encode([
                    'success' => false,
                    'message' =>
                        'The selected academic year is not active. Activate the academic year before saving the selection.'
                ]);

                exit();
            }


            $activeId = (int)$activeRow['id'];


            // ----------------------------------------------------
            // Update ONLY session and exam_title.
            //
            // year remains unchanged.
            // status remains active.
            // id remains unchanged.
            // ----------------------------------------------------

            $stmt = $conn->prepare("
                UPDATE academic_years
                SET
                    session = ?,
                    exam_title = ?
                WHERE id = ?
                  AND year = ?
                  AND status = 'active'
                LIMIT 1
            ");

            $stmt->bind_param(
                "ssis",
                $session,
                $exam,
                $activeId,
                $year
            );


            if (!$stmt->execute()) {

                $error = $stmt->error;

                $stmt->close();

                throw new Exception(
                    'Unable to update academic year: ' . $error
                );
            }


            $affectedRows = $stmt->affected_rows;

            $stmt->close();


            // ----------------------------------------------------
            // Commit the update.
            // ----------------------------------------------------

            $conn->commit();


            echo json_encode([
                'success' => true,
                'message' =>
                    'Academic session saved successfully.',
                'id' => $activeId,
                'affected_rows' => $affectedRows
            ]);

        } catch (Throwable $e) {

            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                // Ignore rollback failure.
            }

            error_log(
                "save_selection error: " .
                $e->getMessage()
            );

            echo json_encode([
                'success' => false,
                'message' =>
                    'Unable to save the academic session.'
            ]);
        }

        exit();
    }


    // ============================================================
    // UNKNOWN ACTION
    // ============================================================

    echo json_encode([
        'success' => false,
        'message' => 'Unknown action.'
    ]);

    exit();
}


// ================================================================
// NORMAL POST — ADD ACADEMIC YEAR
// ================================================================

$success = '';
$errorMsg = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['new_year'])
) {

    $newYear = trim($_POST['new_year']);


    if ($newYear === '') {

        $errorMsg = "Academic year cannot be empty.";

    } else {

        try {

            // ----------------------------------------------------
            // Only prevent duplicate YEAR PLACEHOLDERS.
            //
            // This validation is intentionally NOT used by
            // Save Selection.
            //
            // Save Selection is an UPDATE operation.
            // ----------------------------------------------------

            $stmt = $conn->prepare("
                SELECT id
                FROM academic_years
                WHERE year = ?
                  AND session IS NULL
                  AND exam_title IS NULL
                LIMIT 1
            ");

            $stmt->bind_param(
                "s",
                $newYear
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $placeholderExists =
                $result->num_rows > 0;

            $stmt->close();


            if ($placeholderExists) {

                $errorMsg =
                    "This academic year has already been added.";

            } else {

                // ------------------------------------------------
                // Create the academic-year placeholder.
                //
                // New years ALWAYS begin inactive.
                // ------------------------------------------------

                $status = 'inactive';

                $stmt = $conn->prepare("
                    INSERT INTO academic_years
                        (year, session, exam_title, status)
                    VALUES
                        (?, NULL, NULL, ?)
                ");

                $stmt->bind_param(
                    "ss",
                    $newYear,
                    $status
                );


                if ($stmt->execute()) {

                    $success =
                        "Academic year added successfully.";

                } else {

                    $errorMsg =
                        "Database error while adding academic year.";
                }

                $stmt->close();
            }

        } catch (Throwable $e) {

            error_log(
                "Add academic year error: " .
                $e->getMessage()
            );

            $errorMsg =
                "Unable to add academic year.";
        }
    }
}


// ================================================================
// LOAD ACADEMIC YEARS
// ================================================================

$years = [];

$stmt = $conn->prepare("
    SELECT
        year,
        SUM(
            CASE
                WHEN status = 'active'
                THEN 1
                ELSE 0
            END
        ) AS active_count
    FROM academic_years
    GROUP BY year
    ORDER BY year ASC
");

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $years[] = [
        'year' => $row['year'],
        'status' =>
            ((int)$row['active_count'] > 0)
                ? 'active'
                : 'inactive'
    ];
}

$stmt->close();


// ================================================================
// LOAD CURRENT ACTIVE SESSION
// ================================================================

$activeSession = null;

$stmt = $conn->prepare("
    SELECT
        year,
        session,
        exam_title
    FROM academic_years
    WHERE status = 'active'
      AND session IS NOT NULL
      AND exam_title IS NOT NULL
    ORDER BY id ASC
    LIMIT 1
");

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $activeSession = $result->fetch_assoc();
}

$stmt->close();

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="utf-8">

<title>Manage Session | Admin</title>

<meta
name="viewport"
content="width=device-width, initial-scale=1"

>

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
    href="../css/sidebar.css"
>

<link
    rel="stylesheet"
    href="../css/dataTables.bootstrap5.min.css"
>

<style>

:root {

    --ms-primary: #0d6efd;
    --ms-primary-soft: #eaf2ff;

    --ms-success: #198754;
    --ms-success-soft: #e8f6ee;

    --ms-warning: #ffc107;
    --ms-danger: #dc3545;

    --ms-ink: #1f2430;
    --ms-muted: #6b7280;

    --ms-border: #e7e9ee;
    --ms-bg: #f5f7fb;

    --ms-radius: 14px;
}


body {
    background: var(--ms-bg);
}


/* ==============================================================
   PAGE HEADER
   ============================================================== */

.page-header {

    display: flex;

    justify-content: space-between;

    align-items: flex-start;

    gap: 1rem;

    margin-bottom: 1.5rem;
}


.page-header h2 {

    font-weight: 700;

    color: var(--ms-ink);

    margin-bottom: .25rem;
}


.page-header p {

    color: var(--ms-muted);

    margin-bottom: 0;

    font-size: .95rem;
}


#sidebarToggle {

    border-radius: 10px;

    width: 42px;

    height: 42px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    flex-shrink: 0;
}


/* ==============================================================
   CARDS
   ============================================================== */

.ms-card {

    background: #fff;

    border: 1px solid var(--ms-border);

    border-radius: var(--ms-radius);

    box-shadow:
        0 1px 2px rgba(16, 24, 40, .04);
}


.ms-card-body {

    padding: 1.25rem;
}


.ms-card-header {

    padding: 1rem 1.25rem;

    border-bottom: 1px solid var(--ms-border);

    display: flex;

    align-items: center;

    gap: .6rem;
}


.ms-card-header .ms-icon {

    width: 34px;

    height: 34px;

    border-radius: 9px;

    background: var(--ms-primary-soft);

    color: var(--ms-primary);

    display: inline-flex;

    align-items: center;

    justify-content: center;

    font-size: .95rem;

    flex-shrink: 0;
}


.ms-card-header h5,
.ms-card-header h6 {

    margin: 0;

    font-weight: 700;

    font-size: 1rem;

    color: var(--ms-ink);
}


/* ==============================================================
   LAYOUT
   ============================================================== */

.page-wrap {

    display: flex;

    align-items: flex-start;

    gap: 1.25rem;

    flex-wrap: wrap;
}


.left-col {

    width: 300px;

    flex-shrink: 0;
}


.left-col > .ms-card + .ms-card {

    margin-top: 1.25rem;
}


.right-col {

    flex: 1 1 480px;

    min-width: 0;
}


@media (max-width: 991.98px) {

    .left-col {

        width: 100%;
    }
}


.moveable-content.sidebar-active {

    margin-left: 250px;

    transition: margin-left .3s ease;
}


/* ==============================================================
   ADD YEAR
   ============================================================== */

#addYearForm .input-group .form-control {

    border-radius: 10px 0 0 10px;
}


#addYearForm .input-group .btn {

    border-radius: 0 10px 10px 0;
}


/* ==============================================================
   YEAR LIST
   ============================================================== */

.year-list {

    max-height: 60vh;

    overflow: auto;

    padding-right: 2px;
}


.year-list::-webkit-scrollbar {

    width: 6px;
}


.year-list::-webkit-scrollbar-thumb {

    background: #d7dbe3;

    border-radius: 10px;
}


.year-item {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: .5rem;

    padding: .65rem .75rem;

    border: 1px solid var(--ms-border);

    border-radius: 10px;

    margin-bottom: .6rem;

    transition:
        border-color .15s ease,
        background .15s ease;
}


.year-item:hover {

    border-color: #cfd8e8;
}


.year-item.is-selected {

    border-color: var(--ms-primary);

    background: var(--ms-primary-soft);
}


.year-item .year-main {

    display: flex;

    align-items: center;

    gap: .55rem;

    min-width: 0;

    flex: 1;
}


.year-item .year-dot {

    width: 8px;

    height: 8px;

    border-radius: 50%;

    background: var(--ms-success);

    flex-shrink: 0;
}


.year-item.is-inactive .year-dot {

    background: #c7cbd4;
}


.select-year-btn {

    border: none;

    background: transparent;

    font-weight: 600;

    color: var(--ms-ink);

    padding: 0;

    text-align: left;

    white-space: nowrap;

    overflow: hidden;

    text-overflow: ellipsis;

    cursor: pointer;
}


.year-item .actions {

    display: flex;

    align-items: center;

    gap: .3rem;

    flex-shrink: 0;
}


.year-item .actions .btn {

    width: 30px;

    height: 30px;

    padding: 0;

    border-radius: 8px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    font-size: .8rem;
}


.small-muted {

    font-size: .88rem;

    color: var(--ms-muted);
}


/* ==============================================================
   STEPPER
   ============================================================== */

.ms-stepper {

    display: flex;

    align-items: center;

    gap: .5rem;

    margin-bottom: 1.25rem;

    flex-wrap: wrap;
}


.ms-step {

    display: flex;

    align-items: center;

    gap: .5rem;

    padding: .4rem .8rem .4rem .4rem;

    border-radius: 999px;

    background: #f0f2f6;

    color: var(--ms-muted);

    font-size: .85rem;

    font-weight: 600;

    transition:
        background .2s ease,
        color .2s ease;
}


.ms-step .num {

    width: 22px;

    height: 22px;

    border-radius: 50%;

    background: #dfe3ea;

    color: #fff;

    font-size: .75rem;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    flex-shrink: 0;
}


.ms-step.is-done {

    background: var(--ms-success-soft);

    color: #166a3f;
}


.ms-step.is-done .num {

    background: var(--ms-success);
}


.ms-step-sep {

    width: 22px;

    height: 1px;

    background: var(--ms-border);

    flex-shrink: 0;
}


/* ==============================================================
   RESULT BOX
   ============================================================== */

.result-box {

    display: flex;

    flex-wrap: wrap;

    gap: .75rem;
}


.result-pill {

    flex: 1 1 160px;

    border: 1px solid var(--ms-border);

    border-radius: 12px;

    padding: .75rem .9rem;

    background: #fafbfd;
}


.result-pill .label {

    display: block;

    font-size: .72rem;

    text-transform: uppercase;

    letter-spacing: .04em;

    color: var(--ms-muted);

    font-weight: 700;

    margin-bottom: .2rem;
}


.result-pill .value {

    font-weight: 700;

    color: var(--ms-ink);

    font-size: .98rem;
}


.result-pill.is-filled {

    border-color: #bfe3cc;

    background: var(--ms-success-soft);
}


.result-pill.is-filled .value {

    color: #166a3f;
}


/* ==============================================================
   RADIO LIST
   ============================================================== */

.radio-list {

    display: flex;

    flex-wrap: wrap;

    gap: .55rem;
}


.radio-list label {

    position: relative;

    border: 1px solid var(--ms-border);

    border-radius: 10px;

    padding: .5rem .9rem .5rem .5rem;

    margin: 0;

    cursor: pointer;

    font-size: .9rem;

    font-weight: 600;

    color: var(--ms-ink);

    background: #fff;

    display: inline-flex;

    align-items: center;

    gap: .4rem;

    transition:
        border-color .15s ease,
        background .15s ease,
        color .15s ease;
}


.radio-list label:hover {

    border-color: #bcd4fb;
}


.radio-list label:has(input:checked) {

    border-color: var(--ms-primary);

    background: var(--ms-primary-soft);

    color: var(--ms-primary);
}


.radio-list label:has(input:disabled) {

    opacity: .55;

    cursor: not-allowed;
}


.radio-list input[type="radio"] {

    accent-color: var(--ms-primary);
}


/* ==============================================================
   SECTIONS
   ============================================================== */

.section {

    margin-bottom: 1.5rem;
}


.section:last-child {

    margin-bottom: 0;
}


.section-head {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: .5rem;

    margin-bottom: .65rem;

    flex-wrap: wrap;
}


.section-head h6 {

    margin: 0;

    font-weight: 700;

    color: var(--ms-ink);

    display: flex;

    align-items: center;

    gap: .45rem;
}


.section-head h6 i {

    color: var(--ms-primary);

    font-size: .85rem;
}


/* ==============================================================
   ADD BUTTONS
   ============================================================== */

.btn-add-dashed {

    border: 1.5px dashed #b9c2d4;

    background: #fff;

    color: var(--ms-primary);

    font-weight: 600;

    border-radius: 10px;
}


.btn-add-dashed:hover {

    border-color: var(--ms-primary);

    background: var(--ms-primary-soft);

    color: var(--ms-primary);
}


.btn-add-dashed:disabled {

    color: var(--ms-muted);

    border-color: var(--ms-border);
}


/* ==============================================================
   SAVE BAR
   ============================================================== */

.save-bar {

    position: sticky;

    bottom: 1rem;

    display: flex;

    justify-content: flex-end;

    margin-top: 1.75rem;
}


#saveBtn {

    border-radius: 12px;

    padding: .7rem 1.75rem;

    font-weight: 700;

    box-shadow:
        0 8px 20px rgba(25, 135, 84, .25);
}


#saveBtn:disabled {

    box-shadow: none;
}


/* ==============================================================
   MODALS
   ============================================================== */

.modal-content {

    border-radius: 14px;

    border: none;

    overflow: hidden;
}


.modal-header {

    background: var(--ms-primary-soft);

    border-bottom: none;
}


.modal-header .modal-title {

    font-weight: 700;

    color: var(--ms-ink);

    font-size: 1.02rem;
}


.alert {

    border-radius: 12px;
}


@media (max-width: 575.98px) {

    .year-item {

        align-items: flex-start;
    }

    .year-item .year-main {

        flex-wrap: wrap;
    }

    .year-item .actions {

        flex-shrink: 0;
    }

    .save-bar {

        position: static;

        justify-content: stretch;
    }

    #saveBtn {

        width: 100%;
    }
}

</style>

</head>

<body>

<!-- ============================================================
     SIDEBAR
     ============================================================ -->

<div class="sidebar">

<div class="sidebar-brand">

    <h3>
        <i class="fas fa-graduation-cap me-2"></i>
        Examcenter
    </h3>

    <div class="admin-info">

        <small>Welcome back,</small>

        <h6>
            <b>
                <?php
                echo htmlspecialchars(
                    $admin['username']
                );
                ?>
            </b>
        </h6>

    </div>

</div>


<div class="sidebar-menu mt-4">

    <a href="dashboard.php">
        <i class="fas fa-tachometer-alt"></i>
        Dashboard
    </a>

    <a href="add_teacher.php">
        <i class="fas fa-plus-circle"></i>
        Add Teacher
    </a>

    <a href="view_questions.php">
        <i class="fas fa-list"></i>
        View Questions
    </a>

    <a href="view_results.php">
        <i class="fas fa-chart-bar"></i>
        Exam Results
    </a>

    <a href="manage_classes.php">
        <i class="fas fa-users"></i>
        Manage Classes
    </a>

    <a
        href="manage_session.php"
        class="active"
    >
        <i class="fas fa-users"></i>
        Manage Session
    </a>

    <a href="manage_subject.php">
        <i class="fas fa-users"></i>
        Manage Subject
    </a>

    <a href="manage_students.php">
        <i class="fas fa-users"></i>
        Manage Student
    </a>

    <a href="manage_teachers.php">
        <i class="fas fa-users"></i>
        Manage Teachers
    </a>

    <a href="manage_test.php">
        <i class="fas fa-users"></i>
        Manage Tests
    </a>

    <a href="exam_schedule.php">
        <i class="fas fa-calendar-check"></i>
        Timestable
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

    <a
        href="logout.php"
        class="logout-btn"
    >
        <i class="fas fa-sign-out-alt"></i>
        Logout
    </a>

</div>

</div>

<!-- ============================================================
     MAIN CONTENT
     ============================================================ -->

<div class="main-content">

<!-- PAGE HEADER -->

<div class="page-header">

    <div>

        <h2>
            Manage Academic Sessions
        </h2>

        <p>
            Set up academic years, terms and exam titles,
            then choose the one that's currently active.
        </p>

    </div>


    <button
        class="btn btn-primary d-lg-none"
        id="sidebarToggle"
        type="button"
    >
        <i class="fas fa-bars"></i>
    </button>

</div>


<!-- ========================================================
     SERVER MESSAGES
     ======================================================== -->

<?php if ($success): ?>

    <div class="alert alert-success">

        <i class="fas fa-check-circle me-2"></i>

        <?php
        echo htmlspecialchars($success);
        ?>

    </div>

<?php endif; ?>


<?php if ($errorMsg): ?>

    <div class="alert alert-danger">

        <i class="fas fa-triangle-exclamation me-2"></i>

        <?php
        echo htmlspecialchars($errorMsg);
        ?>

    </div>

<?php endif; ?>


<!-- ========================================================
     PAGE WORKSPACE
     ======================================================== -->

<div class="page-wrap moveable-content">


    <!-- ====================================================
         LEFT COLUMN
         ==================================================== -->

    <div class="left-col">


        <!-- ADD ACADEMIC YEAR -->

        <div class="ms-card">

            <div class="ms-card-header">

                <span class="ms-icon">

                    <i class="fas fa-calendar-plus"></i>

                </span>

                <h5>
                    Add Academic Year
                </h5>

            </div>


            <div class="ms-card-body">

                <form
                    method="POST"
                    id="addYearForm"
                >

                    <div class="input-group">

                        <input
                            type="text"
                            name="new_year"
                            class="form-control"
                            placeholder="e.g. 2025/2026"
                            required
                        >

                        <button
                            class="btn btn-primary"
                            type="submit"
                        >

                            <i class="fas fa-plus"></i>

                        </button>

                    </div>

                </form>

            </div>

        </div>


        <!-- ACADEMIC YEARS -->

        <div class="ms-card">

            <div class="ms-card-header">

                <span class="ms-icon">

                    <i class="fas fa-calendar-days"></i>

                </span>

                <h6>
                    Academic Years
                </h6>

            </div>


            <div class="ms-card-body">

                <div
                    class="year-list"
                    id="yearList"
                >

                    <?php foreach ($years as $y): ?>

                        <div
                            class="year-item
                            <?php
                            echo $y['status'] === 'active'
                                ? ''
                                : 'is-inactive';
                            ?>"
                            data-year="<?php
                                echo htmlspecialchars(
                                    $y['year']
                                );
                            ?>"
                        >


                            <div class="year-main">

                                <span class="year-dot"></span>


                                <button
                                    type="button"
                                    class="select-year-btn"
                                    data-year="<?php
                                        echo htmlspecialchars(
                                            $y['year']
                                        );
                                    ?>"
                                    title="View <?php
                                        echo htmlspecialchars(
                                            $y['year']
                                        );
                                    ?>"
                                >

                                    <?php
                                    echo htmlspecialchars(
                                        $y['year']
                                    );
                                    ?>

                                </button>


                                <?php if (
                                    $y['status'] === 'active'
                                ): ?>

                                    <span class="badge bg-success">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-secondary">
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </div>


                            <div class="actions">


                                <!-- TOGGLE -->

                                <button
                                    type="button"
                                    class="btn btn-warning toggle-year-btn"
                                    data-year="<?php
                                        echo htmlspecialchars(
                                            $y['year']
                                        );
                                    ?>"
                                    title="<?php
                                        echo $y['status'] === 'active'
                                            ? 'Deactivate'
                                            : 'Activate';
                                    ?>"
                                >

                                    <i
                                        class="fas <?php
                                            echo $y['status'] === 'active'
                                                ? 'fa-pause'
                                                : 'fa-play';
                                        ?>"
                                    ></i>

                                </button>


                                <!-- DELETE -->

                                <button
                                    type="button"
                                    class="btn btn-danger delete-year-btn"
                                    data-year="<?php
                                        echo htmlspecialchars(
                                            $y['year']
                                        );
                                    ?>"
                                    title="Delete"
                                >

                                    <i class="fas fa-trash"></i>

                                </button>

                            </div>

                        </div>

                    <?php endforeach; ?>


                    <?php if (empty($years)): ?>

                        <div class="small-muted text-center py-3">

                            <i
                                class="fas fa-inbox mb-2 d-block fs-4 text-muted"
                            ></i>

                            No academic years yet.

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>


    <!-- ====================================================
         RIGHT COLUMN
         ==================================================== -->

    <div class="right-col">

        <div class="ms-card">

            <div class="ms-card-body">


                <!-- STEPPER -->

                <div
                    class="ms-stepper"
                    id="msStepper"
                >

                    <div
                        class="ms-step"
                        id="step-year"
                    >

                        <span class="num">1</span>

                        Year

                    </div>


                    <div class="ms-step-sep"></div>


                    <div
                        class="ms-step"
                        id="step-session"
                    >

                        <span class="num">2</span>

                        Term

                    </div>


                    <div class="ms-step-sep"></div>


                    <div
                        class="ms-step"
                        id="step-exam"
                    >

                        <span class="num">3</span>

                        Exam Title

                    </div>


                    <div class="ms-step-sep"></div>


                    <div
                        class="ms-step"
                        id="step-save"
                    >

                        <span class="num">4</span>

                        Save

                    </div>

                </div>


                <!-- CURRENT SELECTION -->

                <div class="section selection-field">

                    <div
                        class="result-box"
                        id="resultBox"
                    >


                        <div
                            class="result-pill"
                            id="pillYear"
                        >

                            <span class="label">
                                Year
                            </span>

                            <span
                                class="value"
                                id="selectedYear"
                            >
                                —
                            </span>

                        </div>


                        <div
                            class="result-pill"
                            id="pillSession"
                        >

                            <span class="label">
                                Session
                            </span>

                            <span
                                class="value"
                                id="selectedSession"
                            >
                                —
                            </span>

                        </div>


                        <div
                            class="result-pill"
                            id="pillExam"
                        >

                            <span class="label">
                                Exam Title
                            </span>

                            <span
                                class="value"
                                id="selectedExam"
                            >
                                —
                            </span>

                        </div>

                    </div>

                </div>


                <hr>


                <!-- ==================================================
                     SESSIONS
                     ================================================== -->

                <div class="section selection-field">

                    <div class="section-head">

                        <h6>

                            <i class="fas fa-layer-group"></i>

                            Sessions (Terms)

                        </h6>


                        <button
                            type="button"
                            id="openAddSessionBtn"
                            class="btn btn-sm btn-add-dashed"
                            disabled
                        >

                            <i class="fas fa-plus me-1"></i>

                            Add Session

                        </button>

                    </div>


                    <div id="sessionsArea">

                        <div class="small-muted">

                            <i class="fas fa-arrow-up me-1"></i>

                            Select a year to load sessions.

                        </div>

                    </div>

                </div>


                <!-- ==================================================
                     EXAM TITLES
                     ================================================== -->

                <div class="section selection-field">

                    <div class="section-head">

                        <h6>

                            <i class="fas fa-file-lines"></i>

                            Exam Titles

                        </h6>


                        <button
                            type="button"
                            id="openAddExamBtn"
                            class="btn btn-sm btn-add-dashed"
                            disabled
                        >

                            <i class="fas fa-plus me-1"></i>

                            Add Exam Title

                        </button>

                    </div>


                    <div id="examsArea">

                        <div class="small-muted">

                            <i class="fas fa-arrow-up me-1"></i>

                            Select a session to load exam titles.

                        </div>

                    </div>

                </div>


                <!-- ==================================================
                     SAVE
                     ================================================== -->

                <div class="save-bar">

                    <button
                        type="button"
                        id="saveBtn"
                        class="btn btn-success"
                        disabled
                    >

                        <i class="fas fa-floppy-disk me-2"></i>

                        Save Selection

                    </button>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- ============================================================
     ADD SESSION MODAL
     ============================================================ -->

<div
    class="modal fade"
    id="addSessionModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <form
            id="addSessionForm"
            class="modal-content"
        >

            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="fas fa-layer-group me-2"></i>

                    Add Session for

                    <span id="modalYearText"></span>

                </h5>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>

            </div>


            <div class="modal-body">

                <div class="mb-3">

                    <label class="form-label">
                        Session Name
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        name="session"
                        placeholder="e.g. First Term"
                        required
                    >

                </div>


                <input
                    type="hidden"
                    name="year"
                    id="modalYearInput"
                >

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Add Session
                </button>

            </div>

        </form>

    </div>

</div>


<!-- ============================================================
     ADD EXAM MODAL
     ============================================================ -->

<div
    class="modal fade"
    id="addExamModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <form
            id="addExamForm"
            class="modal-content"
        >

            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="fas fa-file-lines me-2"></i>

                    Add Exam Title for

                    <span id="modalExamYearText"></span>

                    —

                    <span id="modalExamSessionText"></span>

                </h5>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>

            </div>


            <div class="modal-body">

                <div class="mb-3">

                    <label class="form-label">
                        Exam Title
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        name="exam"
                        placeholder="e.g. Midterm Exam"
                        required
                    >

                </div>


                <input
                    type="hidden"
                    name="year"
                    id="modalExamYearInput"
                >


                <input
                    type="hidden"
                    name="session"
                    id="modalExamSessionInput"
                >

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Add Exam Title
                </button>

            </div>

        </form>

    </div>

</div>

</div>

<!-- ================================================================
     JAVASCRIPT DEPENDENCIES
     ================================================================ -->

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script src="../js/dataTables.min.js"></script>

<script src="../js/dataTables.bootstrap5.min.js"></script>

<script src="../js/jquery.validate.min.js"></script>

<script>

// ================================================================
// SIDEBAR TOGGLE
// ================================================================

$(document).ready(function () {

    $('#sidebarToggle').on('click', function () {

        $('.sidebar').toggleClass('active');

        $('.moveable-content')
            .toggleClass('sidebar-active');

    });

});

</script>

<script>

const ACTIVE_SESSION =
    <?php
    echo json_encode(
        $activeSession,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
    ?>;


// ================================================================
// DOM ELEMENTS
// ================================================================

const selectedYearEl =
    document.getElementById('selectedYear');

const selectedSessionEl =
    document.getElementById('selectedSession');

const selectedExamEl =
    document.getElementById('selectedExam');

const openAddSessionBtn =
    document.getElementById('openAddSessionBtn');

const openAddExamBtn =
    document.getElementById('openAddExamBtn');

const saveBtn =
    document.getElementById('saveBtn');


// ================================================================
// CURRENT SELECTION
// ================================================================

let currentYear = null;

let currentSession = null;

let currentExam = null;


// ================================================================
// ESCAPE HTML
// ================================================================

function escapeHtml(value) {

    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


// ================================================================
// SET STEPPER STATE
// ================================================================

function setStepState(id, done) {

    const element =
        document.getElementById(id);

    if (!element) {
        return;
    }

    element.classList.toggle(
        'is-done',
        Boolean(done)
    );
}


// ================================================================
// HIGHLIGHT SELECTED YEAR
// ================================================================

function markSelectedYear(year) {

    document
        .querySelectorAll('.year-item')
        .forEach(item => {

            item.classList.toggle(
                'is-selected',
                item.dataset.year === year
            );

        });
}


// ================================================================
// UPDATE RESULT BOX
// ================================================================

function updateResultBox() {

    selectedYearEl.textContent =
        currentYear || '—';

    selectedSessionEl.textContent =
        currentSession || '—';

    selectedExamEl.textContent =
        currentExam || '—';


    const ready =
        Boolean(
            currentYear &&
            currentSession &&
            currentExam
        );


    saveBtn.disabled = !ready;


    document
        .getElementById('pillYear')
        .classList.toggle(
            'is-filled',
            Boolean(currentYear)
        );


    document
        .getElementById('pillSession')
        .classList.toggle(
            'is-filled',
            Boolean(currentSession)
        );


    document
        .getElementById('pillExam')
        .classList.toggle(
            'is-filled',
            Boolean(currentExam)
        );


    setStepState(
        'step-year',
        Boolean(currentYear)
    );

    setStepState(
        'step-session',
        Boolean(currentSession)
    );

    setStepState(
        'step-exam',
        Boolean(currentExam)
    );

    setStepState(
        'step-save',
        ready
    );


    markSelectedYear(currentYear);
}


// ================================================================
// LOAD SESSIONS
// ================================================================

async function loadSessions(year) {

    try {

        const response = await fetch(
            `?action=get_sessions&year=${encodeURIComponent(year)}`,
            {
                cache: 'no-store'
            }
        );


        if (!response.ok) {
            throw new Error('Unable to load sessions.');
        }


        const data =
            await response.json();


        if (!data.success) {
            throw new Error(
                data.message ||
                'Unable to load sessions.'
            );
        }


        const sessionsArea =
            document.getElementById('sessionsArea');


        sessionsArea.innerHTML = '';


        const wrapper =
            document.createElement('div');

        wrapper.className =
            'radio-list';


        // --------------------------------------------------------
        // Default session options.
        // --------------------------------------------------------

        const defaults = [
            'First Term',
            'Second Term',
            'Third Term'
        ];


        const sessions =
            Array.from(
                new Set([
                    ...defaults,
                    ...(data.sessions || [])
                ])
            );


        sessions.forEach(session => {

            const label =
                document.createElement('label');


            const input =
                document.createElement('input');


            input.type = 'radio';

            input.name = 'sessionRadio';

            input.value = session;


            if (data.year_status !== 'active') {

                input.disabled = true;

            }


            label.appendChild(input);

            label.appendChild(
                document.createTextNode(
                    ' ' + session
                )
            );


            wrapper.appendChild(label);

        });


        if (
            !data.sessions ||
            data.sessions.length === 0
        ) {

            wrapper.insertAdjacentHTML(
                'beforeend',
                `
                <div class="small-muted w-100">
                    No saved sessions found.
                    You can add one.
                </div>
                `
            );

        }


        if (
            data.year_status !== 'active'
        ) {

            wrapper.insertAdjacentHTML(
                'beforeend',
                `
                <div class="small-muted w-100 mt-1">
                    <i class="fas fa-circle-info me-1"></i>
                    Year is inactive —
                    activate it before selecting a session.
                </div>
                `
            );

        }


        sessionsArea.appendChild(wrapper);


        // --------------------------------------------------------
        // Session listeners.
        // --------------------------------------------------------

        wrapper
            .querySelectorAll(
                'input[name="sessionRadio"]'
            )
            .forEach(radio => {

                radio.addEventListener(
                    'change',
                    () => {

                        currentSession =
                            radio.value;

                        currentExam = null;


                        openAddExamBtn.disabled =
                            !currentSession;


                        document
                            .getElementById(
                                'examsArea'
                            )
                            .innerHTML = `
                                <div class="small-muted">
                                    <i class="fas fa-spinner fa-spin me-1"></i>
                                    Loading exam titles...
                                </div>
                            `;


                        updateResultBox();


                        loadExamsFor(
                            currentYear,
                            currentSession
                        );

                    }
                );

            });


    } catch (error) {

        console.error(
            'loadSessions:',
            error
        );


        document
            .getElementById('sessionsArea')
            .innerHTML = `
                <div class="text-danger">
                    <i class="fas fa-triangle-exclamation me-1"></i>
                    Failed to load sessions.
                </div>
            `;

    }

}


// ================================================================
// LOAD EXAM TITLES
// ================================================================

async function loadExamsFor(
    year,
    session
) {

    try {

        const response = await fetch(
            `?action=get_exams&year=${encodeURIComponent(year)}&session=${encodeURIComponent(session)}`,
            {
                cache: 'no-store'
            }
        );


        if (!response.ok) {
            throw new Error(
                'Unable to load exam titles.'
            );
        }


        const data =
            await response.json();


        if (!data.success) {
            throw new Error(
                data.message ||
                'Unable to load exam titles.'
            );
        }


        const examsArea =
            document.getElementById(
                'examsArea'
            );


        examsArea.innerHTML = '';


        const wrapper =
            document.createElement('div');

        wrapper.className =
            'radio-list';


        const defaults = [
            'Exam',
            'Test',
            'Mock'
        ];


        const exams =
            Array.from(
                new Set([
                    ...defaults,
                    ...(data.exams || [])
                ])
            );


        exams.forEach(exam => {

            const label =
                document.createElement('label');


            const input =
                document.createElement('input');


            input.type = 'radio';

            input.name = 'examRadio';

            input.value = exam;


            label.appendChild(input);

            label.appendChild(
                document.createTextNode(
                    ' ' + exam
                )
            );


            wrapper.appendChild(label);

        });


        if (
            !data.exams ||
            data.exams.length === 0
        ) {

            wrapper.insertAdjacentHTML(
                'beforeend',
                `
                <div class="small-muted w-100">
                    No saved exam titles found.
                    You can add one.
                </div>
                `
            );

        }


        examsArea.appendChild(wrapper);


        wrapper
            .querySelectorAll(
                'input[name="examRadio"]'
            )
            .forEach(radio => {

                radio.addEventListener(
                    'change',
                    () => {

                        currentExam =
                            radio.value;

                        updateResultBox();

                    }
                );

            });


    } catch (error) {

        console.error(
            'loadExamsFor:',
            error
        );


        document
            .getElementById('examsArea')
            .innerHTML = `
                <div class="text-danger">
                    <i class="fas fa-triangle-exclamation me-1"></i>
                    Failed to load exam titles.
                </div>
            `;

    }

}


// ================================================================
// RESTORE CURRENT SELECTION AFTER YEAR LIST REFRESH
// ================================================================

async function refreshYearList() {

    try {

        const response =
            await fetch(
                'manage_session.php',
                {
                    cache: 'no-store'
                }
            );


        if (!response.ok) {
            throw new Error(
                'Unable to refresh academic years.'
            );
        }


        const html =
            await response.text();


        const doc =
            new DOMParser()
                .parseFromString(
                    html,
                    'text/html'
                );


        const newList =
            doc.getElementById(
                'yearList'
            );


        if (!newList) {
            return;
        }


        document
            .getElementById('yearList')
            .innerHTML =
            newList.innerHTML;


        attachEventListeners();


        if (!currentYear) {

            updateResultBox();

            return;
        }


        markSelectedYear(
            currentYear
        );


        openAddSessionBtn.disabled =
            false;


        await loadSessions(
            currentYear
        );


        // --------------------------------------------------------
        // Restore selected session.
        // --------------------------------------------------------

        if (currentSession) {

            const sessionRadio =
                Array.from(
                    document.querySelectorAll(
                        'input[name="sessionRadio"]'
                    )
                ).find(
                    radio =>
                        radio.value ===
                        currentSession
                );


            if (sessionRadio) {

                sessionRadio.checked =
                    true;

                openAddExamBtn.disabled =
                    false;


                await loadExamsFor(
                    currentYear,
                    currentSession
                );


                // ------------------------------------------------
                // Restore selected exam.
                // ------------------------------------------------

                if (currentExam) {

                    const examRadio =
                        Array.from(
                            document.querySelectorAll(
                                'input[name="examRadio"]'
                            )
                        ).find(
                            radio =>
                                radio.value ===
                                currentExam
                        );


                    if (examRadio) {

                        examRadio.checked =
                            true;

                    }

                }

            }

        }


        updateResultBox();


    } catch (error) {

        console.error(
            'refreshYearList:',
            error
        );

    }

}


// ================================================================
// ATTACH YEAR BUTTON EVENTS
// ================================================================

function attachEventListeners() {


    // ============================================================
    // SELECT YEAR
    // ============================================================

    document
        .querySelectorAll('.select-year-btn')
        .forEach(button => {

            button.onclick = async function () {

                const year =
                    this.dataset.year;


                currentYear =
                    year;

                currentSession =
                    null;

                currentExam =
                    null;


                openAddSessionBtn.disabled =
                    false;

                openAddExamBtn.disabled =
                    true;


                document
                    .getElementById(
                        'examsArea'
                    )
                    .innerHTML = `
                        <div class="small-muted">
                            <i class="fas fa-arrow-up me-1"></i>
                            Select a session to load
                            exam titles.
                        </div>
                    `;


                updateResultBox();


                await loadSessions(
                    currentYear
                );

            };

        });


    // ============================================================
    // TOGGLE YEAR
    // ============================================================

    document
        .querySelectorAll('.toggle-year-btn')
        .forEach(button => {

            button.onclick = async function () {

                const year =
                    this.dataset.year;


                if (
                    !confirm(
                        `Change status for ${year}?`
                    )
                ) {

                    return;
                }


                try {

                    const response =
                        await fetch(
                            '?action=toggle_year',
                            {
                                method: 'POST',

                                headers: {
                                    'Content-Type':
                                        'application/json'
                                },

                                body:
                                    JSON.stringify({
                                        year
                                    })
                            }
                        );


                    const data =
                        await response.json();


                    alert(
                        data.message ||
                        'Status updated.'
                    );


                    if (!data.success) {
                        return;
                    }


                    // ------------------------------------------------
                    // Keep the selected year.
                    // ------------------------------------------------

                    currentYear =
                        year;


                    await refreshYearList();


                } catch (error) {

                    console.error(
                        'toggle_year:',
                        error
                    );


                    alert(
                        'Unable to update academic year status.'
                    );

                }

            };

        });


    // ============================================================
    // DELETE YEAR
    // ============================================================

    document
        .querySelectorAll('.delete-year-btn')
        .forEach(button => {

            button.onclick = async function () {

                const year =
                    this.dataset.year;


                if (
                    !confirm(
                        `Delete ${year} permanently?`
                    )
                ) {

                    return;
                }


                try {

                    const response =
                        await fetch(
                            '?action=delete_year',
                            {
                                method: 'POST',

                                headers: {
                                    'Content-Type':
                                        'application/json'
                                },

                                body:
                                    JSON.stringify({
                                        year
                                    })
                            }
                        );


                    const data =
                        await response.json();


                    alert(
                        data.message ||
                        'Academic year deleted.'
                    );


                    if (!data.success) {
                        return;
                    }


                    // ------------------------------------------------
                    // If deleted year was selected,
                    // clear workspace.
                    // ------------------------------------------------

                    if (
                        currentYear === year
                    ) {

                        currentYear =
                            null;

                        currentSession =
                            null;

                        currentExam =
                            null;


                        document
                            .getElementById(
                                'sessionsArea'
                            )
                            .innerHTML = `
                                <div class="small-muted">
                                    <i class="fas fa-arrow-up me-1"></i>
                                    Select a year to load sessions.
                                </div>
                            `;


                        document
                            .getElementById(
                                'examsArea'
                            )
                            .innerHTML = `
                                <div class="small-muted">
                                    <i class="fas fa-arrow-up me-1"></i>
                                    Select a session to load
                                    exam titles.
                                </div>
                            `;


                        openAddSessionBtn.disabled =
                            true;

                        openAddExamBtn.disabled =
                            true;

                        updateResultBox();

                    }


                    // ------------------------------------------------
                    // Refresh only the list.
                    // ------------------------------------------------

                    const response2 =
                        await fetch(
                            'manage_session.php',
                            {
                                cache: 'no-store'
                            }
                        );


                    const html =
                        await response2.text();


                    const doc =
                        new DOMParser()
                            .parseFromString(
                                html,
                                'text/html'
                            );


                    const newList =
                        doc.getElementById(
                            'yearList'
                        );


                    if (newList) {

                        document
                            .getElementById(
                                'yearList'
                            )
                            .innerHTML =
                            newList.innerHTML;

                        attachEventListeners();

                    }


                } catch (error) {

                    console.error(
                        'delete_year:',
                        error
                    );


                    alert(
                        'Unable to delete academic year.'
                    );

                }

            };

        });

}


// ================================================================
// ADD SESSION MODAL
// ================================================================

const addSessionModal =
    new bootstrap.Modal(
        document.getElementById(
            'addSessionModal'
        )
    );


openAddSessionBtn.onclick =
    function () {

        if (!currentYear) {

            alert(
                'Select a year first.'
            );

            return;
        }


        document
            .getElementById(
                'modalYearText'
            )
            .textContent =
            currentYear;


        document
            .getElementById(
                'modalYearInput'
            )
            .value =
            currentYear;


        addSessionModal.show();

    };


// ================================================================
// ADD SESSION FORM
// ================================================================

document
    .getElementById('addSessionForm')
    .addEventListener(
        'submit',
        async function (event) {

            event.preventDefault();


            const session =
                this.session.value.trim();


            if (!session) {

                alert(
                    'Enter session name.'
                );

                return;
            }


            try {

                const response =
                    await fetch(
                        '?action=add_session',
                        {
                            method: 'POST',

                            headers: {
                                'Content-Type':
                                    'application/json'
                            },

                            body:
                                JSON.stringify({
                                    year:
                                        currentYear,

                                    session
                                })
                        }
                    );


                const data =
                    await response.json();


                alert(
                    data.message ||
                    'Session added.'
                );


                if (!data.success) {
                    return;
                }


                this.reset();

                addSessionModal.hide();


                await loadSessions(
                    currentYear
                );


            } catch (error) {

                console.error(
                    'add_session:',
                    error
                );


                alert(
                    'Unable to add session.'
                );

            }

        }
    );


// ================================================================
// ADD EXAM MODAL
// ================================================================

const addExamModal =
    new bootstrap.Modal(
        document.getElementById(
            'addExamModal'
        )
    );


openAddExamBtn.onclick =
    function () {

        if (!currentYear) {

            alert(
                'Select a year first.'
            );

            return;
        }


        if (!currentSession) {

            alert(
                'Select a session first.'
            );

            return;
        }


        document
            .getElementById(
                'modalExamYearText'
            )
            .textContent =
            currentYear;


        document
            .getElementById(
                'modalExamSessionText'
            )
            .textContent =
            currentSession;


        document
            .getElementById(
                'modalExamYearInput'
            )
            .value =
            currentYear;


        document
            .getElementById(
                'modalExamSessionInput'
            )
            .value =
            currentSession;


        addExamModal.show();

    };


// ================================================================
// ADD EXAM FORM
// ================================================================

document
    .getElementById('addExamForm')
    .addEventListener(
        'submit',
        async function (event) {

            event.preventDefault();


            const exam =
                this.exam.value.trim();


            if (!exam) {

                alert(
                    'Enter exam title.'
                );

                return;
            }


            try {

                const response =
                    await fetch(
                        '?action=add_exam',
                        {
                            method: 'POST',

                            headers: {
                                'Content-Type':
                                    'application/json'
                            },

                            body:
                                JSON.stringify({
                                    year:
                                        currentYear,

                                    session:
                                        currentSession,

                                    exam
                                })
                        }
                    );


                const data =
                    await response.json();


                alert(
                    data.message ||
                    'Exam title added.'
                );


                if (!data.success) {
                    return;
                }


                this.reset();

                addExamModal.hide();


                await loadExamsFor(
                    currentYear,
                    currentSession
                );


            } catch (error) {

                console.error(
                    'add_exam:',
                    error
                );


                alert(
                    'Unable to add exam title.'
                );

            }

        }
    );


// ================================================================
// SAVE SELECTION
// ================================================================

saveBtn.onclick =
    async function () {

        if (
            !currentYear ||
            !currentSession ||
            !currentExam
        ) {

            return;
        }


        if (
            !confirm(
                'Save this as the active academic session?'
            )
        ) {

            return;
        }


        // --------------------------------------------------------
        // Prevent accidental double-clicks.
        // --------------------------------------------------------

        const originalHtml =
            saveBtn.innerHTML;


        saveBtn.disabled = true;


        saveBtn.innerHTML = `
            <span
                class="spinner-border spinner-border-sm me-2"
                role="status"
                aria-hidden="true"
            ></span>
            Saving...
        `;


        try {

            const response =
                await fetch(
                    '?action=save_selection',
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type':
                                'application/json'
                        },

                        body:
                            JSON.stringify({
                                year:
                                    currentYear,

                                session:
                                    currentSession,

                                exam:
                                    currentExam
                            })
                    }
                );


            const data =
                await response.json();


            // ----------------------------------------------------
            // IMPORTANT:
            //
            // The server now returns success after updating the
            // EXISTING active row.
            //
            // No new academic year is inserted.
            // ----------------------------------------------------

            if (data.success) {

                alert(
                    data.message ||
                    'Academic session saved successfully.'
                );


                // ------------------------------------------------
                // Refresh the page only after successful save.
                // ------------------------------------------------

                window.location.reload();

                return;

            }


            alert(
                'Save failed: ' +
                (
                    data.message ||
                    'Unable to save selection.'
                )
            );


        } catch (error) {

            console.error(
                'save_selection:',
                error
            );


            alert(
                'Unable to save the academic session.'
            );


        } finally {

            // ----------------------------------------------------
            // If reload did not happen, restore button.
            // ----------------------------------------------------

            saveBtn.disabled =
                !(
                    currentYear &&
                    currentSession &&
                    currentExam
                );

            saveBtn.innerHTML =
                originalHtml;

        }

    };


// ================================================================
// INITIAL STATE
// ================================================================

openAddSessionBtn.disabled =
    true;

openAddExamBtn.disabled =
    true;

saveBtn.disabled =
    true;

updateResultBox();

attachEventListeners();


// ================================================================
// RESTORE ACTIVE SESSION
// ================================================================

if (
    ACTIVE_SESSION &&
    ACTIVE_SESSION.year
) {

    currentYear =
        ACTIVE_SESSION.year;

    currentSession =
        ACTIVE_SESSION.session;

    currentExam =
        ACTIVE_SESSION.exam_title;


    updateResultBox();


    openAddSessionBtn.disabled =
        false;


    openAddExamBtn.disabled =
        !currentSession;


    loadSessions(
        currentYear
    ).then(
        async function () {

            // ----------------------------------------------------
            // Restore session radio.
            // ----------------------------------------------------

            if (currentSession) {

                const sessionRadio =
                    Array.from(
                        document.querySelectorAll(
                            'input[name="sessionRadio"]'
                        )
                    ).find(
                        radio =>
                            radio.value ===
                            currentSession
                    );


                if (sessionRadio) {

                    sessionRadio.checked =
                        true;


                    await loadExamsFor(
                        currentYear,
                        currentSession
                    );


                    // --------------------------------------------
                    // Restore exam radio.
                    // --------------------------------------------

                    if (currentExam) {

                        const examRadio =
                            Array.from(
                                document.querySelectorAll(
                                    'input[name="examRadio"]'
                                )
                            ).find(
                                radio =>
                                    radio.value ===
                                    currentExam
                            );


                        if (examRadio) {

                            examRadio.checked =
                                true;

                        }

                    }

                }

            }


            updateResultBox();

        }
    );

}

</script>

</body>

</html>
