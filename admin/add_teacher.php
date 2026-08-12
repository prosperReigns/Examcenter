<?php

session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../license/license_guard.php';

/*
|--------------------------------------------------------------------------
| Error Reporting
|--------------------------------------------------------------------------
*/
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['user_id'])) {
    error_log("Redirecting to login: No user_id in session");

    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}


/*
|--------------------------------------------------------------------------
| Database + Admin Authentication
|--------------------------------------------------------------------------
*/
try {

    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

    $user_id = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare("
        SELECT username, role
        FROM admins
        WHERE id = ?
    ");

    if (!$stmt) {
        error_log("Prepare failed for admin role check: " . $conn->error);
        die("Database error");
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $stmt->close();

    if (!$user || strtolower($user['role']) !== 'admin') {

        error_log(
            "Unauthorized access attempt by user_id={$user_id}, role=" .
            ($user['role'] ?? 'none')
        );

        session_destroy();

        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

} catch (Exception $e) {

    error_log("Page error: " . $e->getMessage());

    die("System error");
}


/*
|--------------------------------------------------------------------------
| Fetch Available Subjects
|--------------------------------------------------------------------------
*/
$available_subjects = [];

try {

    $subjects = $conn->query("
        SELECT
            s.id AS subject_id,
            s.subject_name,
            sl.class_level
        FROM subjects s
        JOIN subject_levels sl
            ON s.id = sl.subject_id
        ORDER BY s.subject_name, sl.class_level
    ");

    if ($subjects) {

        while ($row = $subjects->fetch_assoc()) {

            $available_subjects[] = [
                'id'    => $row['subject_id'],
                'name'  => $row['subject_name'],
                'level' => $row['class_level']
            ];
        }

        $subjects->free();
    }

} catch (Exception $e) {

    error_log("Error fetching subjects: " . $e->getMessage());
}


/*
|--------------------------------------------------------------------------
| Fetch Active Classes
|--------------------------------------------------------------------------
*/
$classes = [];

try {

    $result = $conn->query("
        SELECT
            id,
            class_name
        FROM classes
        WHERE is_active = 1
        ORDER BY class_name
    ");

    if ($result) {

        while ($row = $result->fetch_assoc()) {
            $classes[] = $row;
        }

        $result->free();
    }

} catch (Exception $e) {

    error_log("Error fetching classes: " . $e->getMessage());
}


/*
|--------------------------------------------------------------------------
| Initialize Variables
|--------------------------------------------------------------------------
*/
$error = '';
$success = '';

$first_name = '';
$last_name = '';
$email = '';
$phone = '';
$username = '';

$selected_subjects = [];
$selected_classes = [];

$is_edit_mode = false;
$teacher_id = null;


/*
|--------------------------------------------------------------------------
| Edit Mode
|--------------------------------------------------------------------------
*/
if (isset($_GET['edit_id'])) {

    $is_edit_mode = true;

    $teacher_id = (int) $_GET['edit_id'];

    try {

        $stmt = $conn->prepare("
            SELECT
                id,
                first_name,
                last_name,
                username,
                email,
                phone
            FROM teachers
            WHERE id = ?
        ");

        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $teacher = $result->fetch_assoc();

        $stmt->close();

        if ($teacher) {

            $first_name = $teacher['first_name'];
            $last_name  = $teacher['last_name'];
            $username   = $teacher['username'];
            $email      = $teacher['email'];
            $phone      = $teacher['phone'];


            /*
            |--------------------------------------------------------------------------
            | Fetch Teacher Subjects
            |--------------------------------------------------------------------------
            */
            $stmt = $conn->prepare("
                SELECT subject
                FROM teacher_subjects
                WHERE teacher_id = ?
            ");

            $stmt->bind_param("i", $teacher_id);
            $stmt->execute();

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {

                $selected_subjects[] = $row['subject'];
            }

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | Fetch Teacher Classes
            |--------------------------------------------------------------------------
            */
            $stmt = $conn->prepare("
                SELECT class_id
                FROM teacher_classes
                WHERE teacher_id = ?
            ");

            $stmt->bind_param("i", $teacher_id);
            $stmt->execute();

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {

                $selected_classes[] = (int) $row['class_id'];
            }

            $stmt->close();

        } else {

            $error = "Teacher not found.";
            $is_edit_mode = false;
            $teacher_id = null;
        }

    } catch (Exception $e) {

        error_log("Error loading teacher: " . $e->getMessage());

        $error = "Unable to load teacher information.";
        $is_edit_mode = false;
    }
}


/*
|--------------------------------------------------------------------------
| Handle Form Submission
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');

    $selected_subjects = $_POST['subjects'] ?? [];
    $selected_classes  = $_POST['classes'] ?? [];


    /*
    |--------------------------------------------------------------------------
    | Generate Username
    |--------------------------------------------------------------------------
    */
    $username = strtolower($first_name . '.' . $last_name);

    $username = preg_replace('/[^a-z.]/', '', $username);


    /*
    |--------------------------------------------------------------------------
    | Basic Validation
    |--------------------------------------------------------------------------
    */
    if (
        empty($first_name) ||
        empty($last_name) ||
        empty($email)
    ) {

        $error = "Please fill in all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } elseif (empty($selected_subjects)) {

        $error = "Please select at least one subject.";

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Check Duplicate Email / Username
            |--------------------------------------------------------------------------
            */
            $id_check = $is_edit_mode ? $teacher_id : 0;

            $stmt = $conn->prepare("
                SELECT id
                FROM teachers
                WHERE (email = ? OR username = ?)
                AND id != ?
            ");

            $stmt->bind_param(
                "ssi",
                $email,
                $username,
                $id_check
            );

            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {

                $error = "Email or username already exists.";

                $stmt->close();

            } else {

                $stmt->close();

                $conn->begin_transaction();


                /*
                |--------------------------------------------------------------------------
                | EDIT TEACHER
                |--------------------------------------------------------------------------
                */
                if ($is_edit_mode) {

                    $stmt = $conn->prepare("
                        UPDATE teachers
                        SET
                            first_name = ?,
                            last_name = ?,
                            username = ?,
                            email = ?,
                            phone = ?
                        WHERE id = ?
                    ");

                    $stmt->bind_param(
                        "sssssi",
                        $first_name,
                        $last_name,
                        $username,
                        $email,
                        $phone,
                        $teacher_id
                    );

                    if (!$stmt->execute()) {
                        throw new Exception(
                            "Update failed: " . $stmt->error
                        );
                    }

                    $stmt->close();


                    /*
                    |--------------------------------------------------------------------------
                    | Remove Old Subjects
                    |--------------------------------------------------------------------------
                    */
                    $stmt = $conn->prepare("
                        DELETE FROM teacher_subjects
                        WHERE teacher_id = ?
                    ");

                    $stmt->bind_param("i", $teacher_id);

                    if (!$stmt->execute()) {
                        throw new Exception(
                            "Delete subjects failed: " . $stmt->error
                        );
                    }

                    $stmt->close();

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | ADD NEW TEACHER
                    |--------------------------------------------------------------------------
                    */
                    $password = $_POST['password'] ?? '';
                    $confirm_password = $_POST['confirm_password'] ?? '';

                    if (empty($password)) {
                        throw new Exception("Password is required.");
                    }

                    if ($password !== $confirm_password) {
                        throw new Exception("Passwords do not match.");
                    }

                    if (strlen($password) < 8) {
                        throw new Exception(
                            "Password must be at least 8 characters long."
                        );
                    }

                    $hashed_password = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );


                    /*
                    |--------------------------------------------------------------------------
                    | Ensure Unique Username
                    |--------------------------------------------------------------------------
                    */
                    $base_username = $username;
                    $counter = 1;

                    while (true) {

                        $stmt = $conn->prepare("
                            SELECT id
                            FROM teachers
                            WHERE username = ?
                        ");

                        $stmt->bind_param("s", $username);
                        $stmt->execute();

                        $username_result = $stmt->get_result();

                        $exists = $username_result->num_rows > 0;

                        $stmt->close();

                        if (!$exists) {
                            break;
                        }

                        $username = $base_username . $counter;

                        $counter++;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Insert Teacher
                    |--------------------------------------------------------------------------
                    */
                    $stmt = $conn->prepare("
                        INSERT INTO teachers
                        (
                            first_name,
                            last_name,
                            username,
                            email,
                            password,
                            phone,
                            role
                        )
                        VALUES
                        (?, ?, ?, ?, ?, ?, 'teacher')
                    ");

                    $stmt->bind_param(
                        "ssssss",
                        $first_name,
                        $last_name,
                        $username,
                        $email,
                        $hashed_password,
                        $phone
                    );

                    if (!$stmt->execute()) {

                        throw new Exception(
                            "Insert failed: " . $stmt->error
                        );
                    }

                    $teacher_id = $conn->insert_id;

                    $stmt->close();
                }


                /*
                |--------------------------------------------------------------------------
                | Clear Existing Class Assignments
                |--------------------------------------------------------------------------
                */
                $stmt = $conn->prepare("
                    DELETE FROM teacher_classes
                    WHERE teacher_id = ?
                ");

                $stmt->bind_param("i", $teacher_id);
                $stmt->execute();

                $stmt->close();


                /*
                |--------------------------------------------------------------------------
                | Assign Classes
                |--------------------------------------------------------------------------
                */
                foreach ($selected_classes as $class_id) {

                    $class_id = (int) $class_id;

                    $stmt = $conn->prepare("
                        INSERT INTO teacher_classes
                        (
                            teacher_id,
                            class_id
                        )
                        VALUES
                        (?, ?)
                    ");

                    $stmt->bind_param(
                        "ii",
                        $teacher_id,
                        $class_id
                    );

                    if (!$stmt->execute()) {

                        throw new Exception(
                            "Assign class failed: " . $stmt->error
                        );
                    }

                    $stmt->close();
                }


                /*
                |--------------------------------------------------------------------------
                | Insert Subjects
                |--------------------------------------------------------------------------
                */
                foreach ($selected_subjects as $subject_level) {

                    $parts = explode('_', $subject_level, 2);

                    if (count($parts) !== 2) {
                        throw new Exception("Invalid subject selected.");
                    }

                    [$subject_id, $class_level] = $parts;

                    $subject_name = null;


                    foreach ($available_subjects as $sub) {

                        if (
                            (string) $sub['id'] === (string) $subject_id &&
                            (string) $sub['level'] === (string) $class_level
                        ) {

                            $subject_name =
                                $sub['name'] .
                                " (" .
                                $class_level .
                                ")";

                            break;
                        }
                    }


                    if (!$subject_name) {
                        throw new Exception("Invalid subject selected.");
                    }


                    $stmt = $conn->prepare("
                        INSERT INTO teacher_subjects
                        (
                            teacher_id,
                            subject
                        )
                        VALUES
                        (?, ?)
                    ");

                    $stmt->bind_param(
                        "is",
                        $teacher_id,
                        $subject_name
                    );

                    if (!$stmt->execute()) {

                        throw new Exception(
                            "Insert subject failed: " .
                            $stmt->error
                        );
                    }

                    $stmt->close();
                }


                /*
                |--------------------------------------------------------------------------
                | Commit Transaction
                |--------------------------------------------------------------------------
                */
                $conn->commit();


                if ($is_edit_mode) {

                    $success = "Teacher information updated successfully.";

                } else {

                    $success =
                        "Teacher added successfully. Username: " .
                        $username;

                    $first_name = '';
                    $last_name = '';
                    $email = '';
                    $phone = '';
                    $username = '';

                    $selected_subjects = [];
                    $selected_classes = [];
                }


                error_log(
                    "Teacher added/updated: ID={$teacher_id}, " .
                    "Username={$username}, Email={$email}"
                );
            }

        } catch (Exception $e) {

            if ($conn->errno === 0) {
                // Transaction state may already have been resolved.
            }

            try {
                $conn->rollback();
            } catch (Exception $rollbackError) {
                error_log(
                    "Rollback failed: " .
                    $rollbackError->getMessage()
                );
            }

            error_log(
                "Add teacher failed: " .
                $e->getMessage() .
                " | SQL Error: " .
                $conn->error
            );

            $error = $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| Helper: Determine Whether Subject Is Selected
|--------------------------------------------------------------------------
|
| Existing teacher_subjects records are stored like:
| Mathematics (JSS1)
|
| Form values are:
| subject_id_JSS1
|
*/
function isSubjectSelected(
    array $selectedSubjects,
    array $subject
): bool {

    $storedValue =
        $subject['name'] .
        " (" .
        $subject['level'] .
        ")";

    if (in_array($storedValue, $selectedSubjects, true)) {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Also support legacy values such as:
    | Mathematics_JSS1
    |--------------------------------------------------------------------------
    */
    $legacyValue =
        $subject['name'] .
        "_" .
        $subject['level'];

    return in_array($legacyValue, $selectedSubjects, true);
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

<title>
    <?php echo $is_edit_mode ? 'Edit Teacher' : 'Add Teacher'; ?>
    | Examcenter
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

<link
    rel="stylesheet"
    href="../css/admin-dashboard.css"
>

<link
    rel="stylesheet"
    href="../css/dashboard.css"
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

    :root {
        --primary: #4361ee;
        --primary-dark: #3046c9;
        --primary-soft: #eef2ff;
        --success: #198754;
        --danger: #dc3545;
        --text-dark: #1f2937;
        --text-muted: #6b7280;
        --border: #e5e7eb;
        --surface: #ffffff;
        --background: #f5f7fb;
    }


    * {
        box-sizing: border-box;
    }


    body {
        background: var(--background);
        color: var(--text-dark);
    }


    .main-content {
        min-height: 100vh;
        padding-bottom: 50px;
    }


    /*
    |--------------------------------------------------------------------------
    | Header
    |--------------------------------------------------------------------------
    */
    .page-header {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 20px 24px;
        margin-bottom: 24px;
        box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
    }


    .page-title {
        margin: 0;
        font-weight: 700;
        font-size: 1.55rem;
        color: var(--text-dark);
    }


    .page-subtitle {
        margin: 4px 0 0;
        color: var(--text-muted);
        font-size: 0.9rem;
    }


    /*
    |--------------------------------------------------------------------------
    | Cards
    |--------------------------------------------------------------------------
    */
    .form-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 5px 20px rgba(15, 23, 42, 0.045);
        margin-bottom: 24px;
    }


    .card-header-custom {
        padding: 22px 24px;
        border-bottom: 1px solid var(--border);
        background: #fff;
    }


    .card-header-custom h5 {
        margin: 0;
        font-weight: 700;
        font-size: 1.05rem;
    }


    .card-header-custom p {
        margin: 5px 0 0;
        color: var(--text-muted);
        font-size: 0.86rem;
    }


    .card-body-custom {
        padding: 24px;
    }


    /*
    |--------------------------------------------------------------------------
    | Form
    |--------------------------------------------------------------------------
    */
    .form-label {
        font-size: 0.88rem;
        font-weight: 600 !important;
        color: #374151;
        margin-bottom: 7px;
    }


    .form-control,
    .form-select {
        border: 1px solid #d9dee7;
        border-radius: 9px;
        min-height: 45px;
        padding: 10px 13px;
        transition: all 0.2s ease;
    }


    .form-control:focus,
    .form-select:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.10);
    }


    /*
    |--------------------------------------------------------------------------
    | Username
    |--------------------------------------------------------------------------
    */
    .username-box {
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 45px;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 9px;
        padding: 9px 13px;
    }


    .username-box i {
        color: var(--primary);
    }


    .username-preview {
        font-family: "Courier New", monospace;
        font-size: 0.9rem;
        font-weight: 600;
        color: #334155;
    }


    .field-help {
        display: block;
        margin-top: 6px;
        font-size: 0.78rem;
        color: var(--text-muted);
    }


    /*
    |--------------------------------------------------------------------------
    | Selection Section
    |--------------------------------------------------------------------------
    */
    .selection-section {
        margin-top: 28px;
    }


    .selection-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        margin-bottom: 12px;
    }


    .selection-title {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 700;
    }


    .selection-count {
        background: var(--primary-soft);
        color: var(--primary);
        border-radius: 20px;
        padding: 5px 10px;
        font-size: 0.75rem;
        font-weight: 600;
    }


    /*
    |--------------------------------------------------------------------------
    | Checkbox Grid
    |--------------------------------------------------------------------------
    */
    .selection-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }


    .selection-item {
        position: relative;
    }


    .selection-item input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }


    .selection-label {
        display: flex;
        align-items: center;
        min-height: 48px;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 9px;
        background: #fff;
        cursor: pointer;
        font-size: 0.84rem;
        transition: all 0.2s ease;
    }


    .selection-label:hover {
        border-color: #b8c4ff;
        background: #fafbff;
        transform: translateY(-1px);
    }


    .selection-label::before {
        content: "\f0c8";
        font-family: "Font Awesome 6 Free";
        font-weight: 400;
        color: #9ca3af;
        margin-right: 9px;
    }


    .selection-item input:checked + .selection-label {
        border-color: var(--primary);
        background: var(--primary-soft);
        color: #263aa7;
        font-weight: 600;
    }


    .selection-item input:checked + .selection-label::before {
        content: "\f14a";
        font-weight: 900;
        color: var(--primary);
    }


    /*
    |--------------------------------------------------------------------------
    | Password Strength
    |--------------------------------------------------------------------------
    */
    .password-strength {
        margin-top: 7px;
        font-size: 0.78rem;
        font-weight: 600;
    }


    /*
    |--------------------------------------------------------------------------
    | Buttons
    |--------------------------------------------------------------------------
    */
    .btn {
        border-radius: 9px;
        padding: 10px 16px;
        font-weight: 600;
    }


    .btn-primary {
        background: var(--primary);
        border-color: var(--primary);
    }


    .btn-primary:hover {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
    }


    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 30px;
        padding-top: 22px;
        border-top: 1px solid var(--border);
    }


    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    */
    .custom-alert {
        border: 0;
        border-radius: 12px;
        padding: 14px 17px;
        margin-bottom: 20px;
    }


    /*
    |--------------------------------------------------------------------------
    | Sidebar Toggle
    |--------------------------------------------------------------------------
    */
    .mobile-toggle {
        position: fixed;
        right: 18px;
        top: 18px;
        z-index: 1100;
        width: 44px;
        height: 44px;
        border: 0;
        border-radius: 10px;
        background: var(--primary);
        color: white;
        box-shadow: 0 6px 18px rgba(67, 97, 238, 0.28);
        display: none;
        align-items: center;
        justify-content: center;
    }


    /*
    |--------------------------------------------------------------------------
    | Sidebar Overlay
    |--------------------------------------------------------------------------
    */
    .sidebar-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        z-index: 999;
        display: none;
    }


    .sidebar-overlay.active {
        display: block;
    }


    /*
    |--------------------------------------------------------------------------
    | Sidebar User Info
    |--------------------------------------------------------------------------
    */
    .admin-info small {
        color: rgba(255, 255, 255, 0.72) !important;
    }


    .admin-info h6 {
        color: white !important;
        margin: 4px 0 0;
    }


    /*
    |--------------------------------------------------------------------------
    | Empty Selection
    |--------------------------------------------------------------------------
    */
    .empty-selection {
        padding: 20px;
        text-align: center;
        color: var(--text-muted);
        border: 1px dashed #d1d5db;
        border-radius: 10px;
        background: #fafafa;
    }


    /*
    |--------------------------------------------------------------------------
    | Responsive
    |--------------------------------------------------------------------------
    */
    @media (max-width: 1199px) {

        .selection-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }


    @media (max-width: 991px) {

        .mobile-toggle {
            display: flex;
        }


        .page-header {
            padding-right: 72px;
        }


        .selection-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }


        .sidebar {
            transform: translateX(-100%);
            transition: transform 0.25s ease;
            z-index: 1050;
        }


        .sidebar.active {
            transform: translateX(0);
        }
    }


    @media (max-width: 576px) {

        .main-content {
            padding: 15px;
        }


        .page-header {
            padding: 18px;
            border-radius: 12px;
        }


        .page-title {
            font-size: 1.25rem;
        }


        .card-body-custom {
            padding: 18px;
        }


        .card-header-custom {
            padding: 18px;
        }


        .selection-grid {
            grid-template-columns: 1fr;
        }


        .form-actions {
            flex-direction: column-reverse;
        }


        .form-actions .btn {
            width: 100%;
        }


        .header-actions .btn-back {
            display: none;
        }
    }

</style>


</head>

<body>

<!--
|--------------------------------------------------------------------------
| Mobile Sidebar Toggle
|--------------------------------------------------------------------------
-->

<button
type="button"
class="mobile-toggle"
id="sidebarToggle"
aria-label="Toggle navigation"
aria-expanded="false"

>

<i class="fas fa-bars"></i>

</button>

<!-- Sidebar Overlay -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!--
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
-->

<div class="sidebar" id="mainSidebar">

<div class="sidebar-brand">

    <h3>
        <i class="fas fa-graduation-cap me-2"></i>
        Examcenter
    </h3>

    <div class="admin-info">

        <small>Welcome back,</small>

        <h6>
            <?php echo htmlspecialchars($user['username']); ?>
        </h6>

    </div>

</div>


<div class="sidebar-menu mt-4">

    <a href="dashboard.php">
        <i class="fas fa-tachometer-alt"></i>
        Dashboard
    </a>

    <a
        href="add_teacher.php"
        class="active"
    >
        <i class="fas fa-user-plus"></i>
        Add Teachers
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

    <a href="manage_session.php">
        <i class="fas fa-calendar-alt"></i>
        Manage Session
    </a>

    <a href="manage_subject.php">
        <i class="fas fa-book"></i>
        Manage Subject
    </a>

    <a href="manage_students.php">
        <i class="fas fa-user-graduate"></i>
        Manage Student
    </a>

    <a href="manage_teachers.php">
        <i class="fas fa-chalkboard-teacher"></i>
        Manage Teachers
    </a>

    <a href="exam_schedule.php">
        <i class="fas fa-calendar-check"></i>
        Timetable
    </a>

    <a href="../backup/backup_list.php">
        <i class="fas fa-database"></i>
        Backups
    </a>

    <a href="audit_logs.php">
        <i class="fas fa-history"></i>
        Audit Logs
    </a>

    <a href="../license/index.php">
        <i class="fas fa-key"></i>
        License
    </a>

    <a href="settings.php">
        <i class="fas fa-cog"></i>
        Settings
    </a>

    <a
        href="../teacher/logout.php"
        class="logout-btn"
    >
        <i class="fas fa-sign-out-alt"></i>
        Logout
    </a>

</div>

</div>

<!--
|--------------------------------------------------------------------------
| Main Content
|--------------------------------------------------------------------------
-->

<div class="main-content">

<!-- Page Header -->
<div class="page-header">

    <div class="d-flex justify-content-between align-items-center">

        <div>

            <h1 class="page-title">

                <i class="fas <?php echo $is_edit_mode ? 'fa-user-edit' : 'fa-user-plus'; ?> me-2 text-primary"></i>

                <?php echo $is_edit_mode ? 'Edit Teacher' : 'Add Teacher'; ?>

            </h1>

            <p class="page-subtitle">

                <?php if ($is_edit_mode): ?>

                    Update teacher information, subjects and class assignments.

                <?php else: ?>

                    Create a teacher account and assign subjects and classes.

                <?php endif; ?>

            </p>

        </div>


        <div class="header-actions">

            <a
                href="manage_teachers.php"
                class="btn btn-outline-secondary btn-back"
            >
                <i class="fas fa-arrow-left me-2"></i>
                Back to Teachers
            </a>

        </div>

    </div>

</div>


<!-- Alerts -->

<?php if ($error): ?>

    <div
        class="alert alert-danger custom-alert alert-dismissible fade show"
        role="alert"
    >

        <i class="fas fa-circle-exclamation me-2"></i>

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
        class="alert alert-success custom-alert alert-dismissible fade show"
        role="alert"
    >

        <i class="fas fa-circle-check me-2"></i>

        <?php echo htmlspecialchars($success); ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
        ></button>

    </div>

<?php endif; ?>


<!--
|--------------------------------------------------------------------------
| Teacher Form
|--------------------------------------------------------------------------
-->
<div class="form-card">


    <div class="card-header-custom">

        <h5>

            <i class="fas fa-id-card me-2 text-primary"></i>

            Teacher Information

        </h5>

        <p>
            Enter the teacher's basic account and contact information.
        </p>

    </div>


    <div class="card-body-custom">

        <form
            method="POST"
            action="<?php
                echo htmlspecialchars($_SERVER['PHP_SELF']);
                echo $is_edit_mode
                    ? '?edit_id=' . $teacher_id
                    : '';
            ?>"
            id="teacherForm"
        >

            <?php if ($is_edit_mode): ?>

                <input
                    type="hidden"
                    name="teacher_id"
                    value="<?php echo $teacher_id; ?>"
                >

            <?php endif; ?>


            <!-- Basic Information -->

            <div class="row g-4">


                <!-- First Name -->

                <div class="col-md-6">

                    <label
                        class="form-label"
                        for="firstName"
                    >
                        First Name
                        <span class="text-danger">*</span>
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        id="firstName"
                        name="first_name"
                        value="<?php echo htmlspecialchars($first_name); ?>"
                        placeholder="Enter first name"
                        maxlength="50"
                        required
                    >

                </div>


                <!-- Last Name -->

                <div class="col-md-6">

                    <label
                        class="form-label"
                        for="lastName"
                    >
                        Last Name
                        <span class="text-danger">*</span>
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        id="lastName"
                        name="last_name"
                        value="<?php echo htmlspecialchars($last_name); ?>"
                        placeholder="Enter last name"
                        maxlength="50"
                        required
                    >

                </div>


                <!-- Username -->

                <div class="col-12">

                    <label class="form-label">
                        Generated Username
                    </label>

                    <div class="username-box">

                        <i class="fas fa-at"></i>

                        <span
                            id="usernamePreview"
                            class="username-preview"
                        >
                            <?php
                            echo !empty($username)
                                ? htmlspecialchars($username)
                                : 'username.will.generate.here';
                            ?>
                        </span>

                    </div>

                    <small class="field-help">

                        Username is automatically generated from the
                        teacher's first and last name.

                    </small>

                </div>


                <!-- Email -->

                <div class="col-md-6">

                    <label
                        class="form-label"
                        for="email"
                    >
                        Email Address
                        <span class="text-danger">*</span>
                    </label>

                    <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        value="<?php echo htmlspecialchars($email); ?>"
                        placeholder="teacher@example.com"
                        maxlength="100"
                        required
                    >

                </div>


                <!-- Phone -->

                <div class="col-md-6">

                    <label
                        class="form-label"
                        for="phone"
                    >
                        Phone Number
                    </label>

                    <input
                        type="tel"
                        class="form-control"
                        id="phone"
                        name="phone"
                        value="<?php echo htmlspecialchars($phone); ?>"
                        placeholder="Enter phone number"
                        maxlength="15"
                    >

                </div>


                <?php if (!$is_edit_mode): ?>


                    <!-- Password -->

                    <div class="col-md-6">

                        <label
                            class="form-label"
                            for="password"
                        >
                            Password
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            placeholder="Create password"
                            minlength="8"
                            required
                        >

                        <div
                            id="password-strength"
                            class="password-strength"
                        ></div>

                        <small class="field-help">
                            Minimum 8 characters.
                        </small>

                    </div>


                    <!-- Confirm Password -->

                    <div class="col-md-6">

                        <label
                            class="form-label"
                            for="confirmPassword"
                        >
                            Confirm Password
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="password"
                            class="form-control"
                            id="confirmPassword"
                            name="confirm_password"
                            placeholder="Repeat password"
                            minlength="8"
                            required
                        >

                    </div>


                <?php endif; ?>


            </div>


            <!--
            |--------------------------------------------------------------------------
            | Subjects
            |--------------------------------------------------------------------------
            -->

            <div class="selection-section">

                <div class="selection-header">

                    <div>

                        <h6 class="selection-title">

                            <i class="fas fa-book-open text-primary me-2"></i>

                            Subjects Taught

                            <span class="text-danger">*</span>

                        </h6>

                    </div>

                    <span
                        class="selection-count"
                        id="subjectCount"
                    >
                        0 selected
                    </span>

                </div>


                <?php if (!empty($available_subjects)): ?>

                    <div class="selection-grid">

                        <?php foreach ($available_subjects as $index => $subject): ?>

                            <?php

                            $subjectValue =
                                $subject['id'] .
                                '_' .
                                $subject['level'];

                            $isSelected = isSubjectSelected(
                                $selected_subjects,
                                $subject
                            );

                            ?>

                            <div class="selection-item">

                                <input
                                    type="checkbox"
                                    class="subject-checkbox"
                                    name="subjects[]"
                                    value="<?php echo htmlspecialchars($subjectValue); ?>"
                                    id="subject_<?php echo $index; ?>"
                                    <?php echo $isSelected ? 'checked' : ''; ?>
                                >

                                <label
                                    class="selection-label"
                                    for="subject_<?php echo $index; ?>"
                                >

                                    <?php
                                    echo htmlspecialchars(
                                        $subject['name'] .
                                        ' (' .
                                        $subject['level'] .
                                        ')'
                                    );
                                    ?>

                                </label>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-selection">

                        <i class="fas fa-book-open mb-2"></i>

                        <div>
                            No subjects are currently available.
                        </div>

                        <small>
                            Add subjects before assigning them to teachers.
                        </small>

                    </div>

                <?php endif; ?>

            </div>


            <!--
            |--------------------------------------------------------------------------
            | Classes
            |--------------------------------------------------------------------------
            -->

            <div class="selection-section">

                <div class="selection-header">

                    <div>

                        <h6 class="selection-title">

                            <i class="fas fa-users text-primary me-2"></i>

                            Assigned Classes

                        </h6>

                    </div>

                    <span
                        class="selection-count"
                        id="classCount"
                    >
                        0 selected
                    </span>

                </div>


                <?php if (!empty($classes)): ?>

                    <div class="selection-grid">

                        <?php foreach ($classes as $index => $class): ?>

                            <div class="selection-item">

                                <input
                                    type="checkbox"
                                    class="class-checkbox"
                                    name="classes[]"
                                    value="<?php echo (int) $class['id']; ?>"
                                    id="class_<?php echo $index; ?>"
                                    <?php
                                    echo in_array(
                                        (int) $class['id'],
                                        $selected_classes,
                                        true
                                    )
                                        ? 'checked'
                                        : '';
                                    ?>
                                >

                                <label
                                    class="selection-label"
                                    for="class_<?php echo $index; ?>"
                                >

                                    <?php
                                    echo htmlspecialchars(
                                        $class['class_name']
                                    );
                                    ?>

                                </label>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-selection">

                        <i class="fas fa-users mb-2"></i>

                        <div>
                            No active classes are available.
                        </div>

                        <small>
                            Create or activate a class before assigning it.
                        </small>

                    </div>

                <?php endif; ?>

            </div>


            <!--
            |--------------------------------------------------------------------------
            | Form Actions
            |--------------------------------------------------------------------------
            -->

            <div class="form-actions">

                <button
                    type="reset"
                    class="btn btn-outline-secondary"
                    id="clearButton"
                >
                    <i class="fas fa-rotate-left me-2"></i>
                    Clear
                </button>


                <button
                    type="submit"
                    class="btn btn-primary"
                >

                    <i class="fas fa-<?php echo $is_edit_mode ? 'save' : 'user-plus'; ?> me-2"></i>

                    <?php
                    echo $is_edit_mode
                        ? 'Update Teacher'
                        : 'Add Teacher';
                    ?>

                </button>

            </div>


        </form>

    </div>

</div>

</div>

<!--
|--------------------------------------------------------------------------
| Scripts
|--------------------------------------------------------------------------
-->

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script src="../js/jquery.validate.min.js"></script>

<script>

$(document).ready(function () {


    /*
    |--------------------------------------------------------------------------
    | Sidebar Toggle
    |--------------------------------------------------------------------------
    */

    const sidebar = $('#mainSidebar');
    const overlay = $('#sidebarOverlay');
    const toggle = $('#sidebarToggle');


    function openSidebar() {

        sidebar.addClass('active');
        overlay.addClass('active');

        toggle.attr('aria-expanded', 'true');

        toggle.find('i')
            .removeClass('fa-bars')
            .addClass('fa-xmark');
    }


    function closeSidebar() {

        sidebar.removeClass('active');
        overlay.removeClass('active');

        toggle.attr('aria-expanded', 'false');

        toggle.find('i')
            .removeClass('fa-xmark')
            .addClass('fa-bars');
    }


    toggle.on('click', function () {

        if (sidebar.hasClass('active')) {

            closeSidebar();

        } else {

            openSidebar();
        }

    });


    overlay.on('click', function () {
        closeSidebar();
    });


    sidebar.find('a').on('click', function () {

        if (window.innerWidth <= 991) {
            closeSidebar();
        }

    });


    /*
    |--------------------------------------------------------------------------
    | Username Preview
    |--------------------------------------------------------------------------
    */

    function updateUsernamePreview() {

        const firstName =
            $('input[name="first_name"]')
                .val()
                .trim()
                .toLowerCase();

        const lastName =
            $('input[name="last_name"]')
                .val()
                .trim()
                .toLowerCase();


        let username = '';


        if (firstName && lastName) {

            username =
                (firstName + '.' + lastName)
                .replace(/[^a-z.]/g, '');
        }


        $('#usernamePreview').text(
            username || 'username.will.generate.here'
        );
    }


    $('#firstName, #lastName').on(
        'input',
        updateUsernamePreview
    );


    /*
    |--------------------------------------------------------------------------
    | Password Strength
    |--------------------------------------------------------------------------
    */

    function checkPasswordStrength(password) {

        const indicator = $('#password-strength');


        if (!indicator.length) {
            return;
        }


        if (!password.length) {

            indicator
                .text('')
                .removeClass(
                    'text-danger text-warning text-success'
                );

            return;
        }


        if (password.length < 6) {

            indicator
                .text('Weak password')
                .removeClass(
                    'text-warning text-success'
                )
                .addClass('text-danger');

        } else if (password.length < 8) {

            indicator
                .text('Almost there — use at least 8 characters')
                .removeClass(
                    'text-danger text-success'
                )
                .addClass('text-warning');

        } else {

            indicator
                .text('Strong password')
                .removeClass(
                    'text-danger text-warning'
                )
                .addClass('text-success');
        }
    }


    $('#password').on('input', function () {

        checkPasswordStrength(
            $(this).val()
        );

    });


    /*
    |--------------------------------------------------------------------------
    | Selection Counters
    |--------------------------------------------------------------------------
    */

    function updateSelectionCounts() {

        const subjectCount =
            $('.subject-checkbox:checked').length;

        const classCount =
            $('.class-checkbox:checked').length;


        $('#subjectCount').text(
            subjectCount +
            (subjectCount === 1
                ? ' selected'
                : ' selected')
        );


        $('#classCount').text(
            classCount +
            (classCount === 1
                ? ' selected'
                : ' selected')
        );
    }


    $('.subject-checkbox, .class-checkbox').on(
        'change',
        updateSelectionCounts
    );


    updateSelectionCounts();


    /*
    |--------------------------------------------------------------------------
    | Form Validation
    |--------------------------------------------------------------------------
    */

    $('#teacherForm').validate({

        rules: {

            first_name: {
                required: true,
                maxlength: 50
            },

            last_name: {
                required: true,
                maxlength: 50
            },

            email: {
                required: true,
                email: true,
                maxlength: 100
            },

            phone: {
                maxlength: 15
            },

            password: {
                required: <?php echo $is_edit_mode ? 'false' : 'true'; ?>,
                minlength: 8
            },

            confirm_password: {
                required: <?php echo $is_edit_mode ? 'false' : 'true'; ?>,
                equalTo: '#password'
            },

            'subjects[]': {
                required: true
            }

        },


        messages: {

            first_name:
                "Please enter the teacher's first name.",

            last_name:
                "Please enter the teacher's last name.",

            email:
                "Please enter a valid email address.",

            phone:
                "Phone number cannot exceed 15 characters.",

            password:
                "Password must be at least 8 characters long.",

            confirm_password:
                "Passwords do not match.",

            'subjects[]':
                "Please select at least one subject."

        },


        errorElement: 'div',

        errorClass: 'invalid-feedback',


        highlight: function (element) {

            $(element)
                .addClass('is-invalid');

        },


        unhighlight: function (element) {

            $(element)
                .removeClass('is-invalid');

        },


        errorPlacement: function (
            error,
            element
        ) {

            if (
                element.attr('name') ===
                'subjects[]'
            ) {

                error.insertAfter(
                    element.closest('.selection-grid')
                );

            } else {

                error.insertAfter(element);
            }

        }

    });


    /*
    |--------------------------------------------------------------------------
    | Clear Button
    |--------------------------------------------------------------------------
    */

    $('#clearButton').on('click', function () {

        setTimeout(function () {

            $('#usernamePreview').text(
                'username.will.generate.here'
            );

            $('#password-strength').text('');

            updateSelectionCounts();

        }, 10);

    });


    /*
    |--------------------------------------------------------------------------
    | Auto-hide Alerts
    |--------------------------------------------------------------------------
    */

    setTimeout(function () {

        $('.alert').each(function () {

            const alertElement =
                bootstrap.Alert.getOrCreateInstance(this);

            alertElement.close();

        });

    }, 6000);


    /*
    |--------------------------------------------------------------------------
    | Close Sidebar When Resizing To Desktop
    |--------------------------------------------------------------------------
    */

    $(window).on('resize', function () {

        if (window.innerWidth > 991) {
            closeSidebar();
        }

    });

});

</script>

</body>
</html>
