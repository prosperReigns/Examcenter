<?php

session_start();

require_once '../db.php';


/* =========================================================
   ERROR REPORTING
========================================================= */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');


/* =========================================================
   AUTHENTICATION
========================================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}


$database = Database::getInstance();
$conn = $database->getConnection();

$user_id = (int) $_SESSION['user_id'];


/* =========================================================
   VERIFY SUPER ADMIN
========================================================= */

$stmt = $conn->prepare("
    SELECT role
    FROM super_admins
    WHERE id = ?
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$admin = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


if (
    !$admin ||
    strtolower($admin['role']) !== 'super_admin'
) {
    session_destroy();

    header(
        "Location: /EXAMCENTER/login.php?error=Unauthorized"
    );

    exit();
}


/* =========================================================
   SETUP CONFIGURATION
========================================================= */

$class_groups = [
    'PRIMARY',
    'JSS',
    'SS'
];

$totalSteps = 5;

$success = false;
$error = '';


/* =========================================================
   FORM HANDLING
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $conn->begin_transaction();


        /* =====================================================
           STEP 1 — ADD SCHOOL
        ===================================================== */

        if (isset($_POST['add_school'])) {

            $school_name = trim(
                $_POST['school_name'] ?? ''
            );

            if ($school_name === '') {
                throw new Exception(
                    "School name cannot be empty."
                );
            }


            $stmt = $conn->prepare("
                SELECT id
                FROM schools
                WHERE school_name = ?
            ");

            $stmt->bind_param(
                "s",
                $school_name
            );

            $stmt->execute();
            $stmt->store_result();


            if ($stmt->num_rows === 0) {

                $stmt->close();

                $stmt = $conn->prepare("
                    INSERT INTO schools
                        (school_name)
                    VALUES
                        (?)
                ");

                $stmt->bind_param(
                    "s",
                    $school_name
                );

                $stmt->execute();
            }

            $stmt->close();


            $_SESSION['setup_step'] = 2;

            $conn->commit();

            $success = true;
        }


        /* =====================================================
           STEP 2 — ADD ACADEMIC YEAR
        ===================================================== */

        if (isset($_POST['add_year'])) {

            $year = trim(
                $_POST['new_year'] ?? ''
            );

            if ($year === '') {
                throw new Exception(
                    "Academic year cannot be empty."
                );
            }


            $stmt = $conn->prepare("
                SELECT id
                FROM academic_years
                WHERE year = ?
            ");

            $stmt->bind_param(
                "s",
                $year
            );

            $stmt->execute();
            $stmt->store_result();


            if ($stmt->num_rows === 0) {

                $stmt->close();

                $status = 'inactive';

                $stmt = $conn->prepare("
                    INSERT INTO academic_years
                        (year, status)
                    VALUES
                        (?, ?)
                ");

                $stmt->bind_param(
                    "ss",
                    $year,
                    $status
                );

                $stmt->execute();
            }

            $stmt->close();


            $_SESSION['setup_step'] = 3;

            $conn->commit();

            $success = true;
        }


        /* =====================================================
           STEP 3 — ADD CLASS
        ===================================================== */

        if (isset($_POST['add_class'])) {

            $class_group = strtoupper(
                trim(
                    $_POST['class_group'] ?? ''
                )
            );

            $level_code = strtoupper(
                trim(
                    $_POST['level_code'] ?? ''
                )
            );

            $stream_name = ucfirst(
                strtolower(
                    trim(
                        $_POST['stream_name'] ?? ''
                    )
                )
            );


            if (
                !$class_group ||
                !$level_code ||
                !$stream_name
            ) {
                throw new Exception(
                    "All fields are required."
                );
            }


            /* Validate level code */

            $valid = false;

            if (
                $class_group === 'JSS' &&
                str_starts_with(
                    $level_code,
                    'JSS'
                )
            ) {
                $valid = true;
            }

            elseif (
                $class_group === 'SS' &&
                str_starts_with(
                    $level_code,
                    'SS'
                )
            ) {
                $valid = true;
            }

            elseif (
                $class_group === 'PRIMARY' &&
                str_starts_with(
                    $level_code,
                    'PRY'
                )
            ) {
                $valid = true;
            }


            if (!$valid) {

                throw new Exception(
                    "Level Code '$level_code' does not match Class Group '$class_group'."
                );
            }


            /* Academic level */

            $stmt = $conn->prepare("
                SELECT id
                FROM academic_levels
                WHERE level_code = ?
                  AND class_group = ?
            ");

            $stmt->bind_param(
                "ss",
                $level_code,
                $class_group
            );

            $stmt->execute();

            $result = $stmt->get_result();


            if ($result->num_rows > 0) {

                $academic_level_id =
                    $result->fetch_assoc()['id'];

            } else {

                $stmt->close();

                $stmt = $conn->prepare("
                    INSERT INTO academic_levels
                        (level_code, class_group)
                    VALUES
                        (?, ?)
                ");

                $stmt->bind_param(
                    "ss",
                    $level_code,
                    $class_group
                );

                $stmt->execute();

                $academic_level_id =
                    $stmt->insert_id;
            }


            $stmt->close();


            /* Stream */

            $stmt = $conn->prepare("
                SELECT id
                FROM streams
                WHERE stream_name = ?
            ");

            $stmt->bind_param(
                "s",
                $stream_name
            );

            $stmt->execute();

            $result = $stmt->get_result();


            if ($result->num_rows > 0) {

                $stream_id =
                    $result->fetch_assoc()['id'];

            } else {

                $stmt->close();

                $stmt = $conn->prepare("
                    INSERT INTO streams
                        (stream_name)
                    VALUES
                        (?)
                ");

                $stmt->bind_param(
                    "s",
                    $stream_name
                );

                $stmt->execute();

                $stream_id =
                    $stmt->insert_id;
            }


            $stmt->close();


            /* Class */

            $class_name =
                $level_code . ' ' . $stream_name;


            $stmt = $conn->prepare("
                SELECT id
                FROM classes
                WHERE academic_level_id = ?
                  AND stream_id = ?
            ");

            $stmt->bind_param(
                "ii",
                $academic_level_id,
                $stream_id
            );

            $stmt->execute();

            $result = $stmt->get_result();


            if ($result->num_rows > 0) {

                throw new Exception(
                    "Class already exists."
                );

            } else {

                $stmt->close();

                $stmt = $conn->prepare("
                    INSERT INTO classes
                        (
                            academic_level_id,
                            stream_id,
                            class_name
                        )
                    VALUES
                        (?, ?, ?)
                ");

                $stmt->bind_param(
                    "iis",
                    $academic_level_id,
                    $stream_id,
                    $class_name
                );

                $stmt->execute();
            }


            $stmt->close();


            $_SESSION['setup_step'] = 4;

            $conn->commit();

            $success = true;
        }


        /* =====================================================
           STEP 4 — ADD SUBJECT
        ===================================================== */

        if (isset($_POST['add_subject'])) {

            $subject_name = trim(
                $_POST['subject_name'] ?? ''
            );

            $class_level =
                $_POST['class_level'] ?? '';


            if (
                $subject_name === '' ||
                $class_level === ''
            ) {
                throw new Exception(
                    "Subject and class level required."
                );
            }


            $stmt = $conn->prepare("
                SELECT id
                FROM subjects
                WHERE subject_name = ?
            ");

            $stmt->bind_param(
                "s",
                $subject_name
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $subject = $result->fetch_assoc();

            $stmt->close();


            if ($subject) {

                $subject_id =
                    $subject['id'];

            } else {

                $stmt = $conn->prepare("
                    INSERT INTO subjects
                        (subject_name)
                    VALUES
                        (?)
                ");

                $stmt->bind_param(
                    "s",
                    $subject_name
                );

                $stmt->execute();

                $subject_id =
                    $stmt->insert_id;

                $stmt->close();
            }


            /* Link subject to class level */

            $stmt = $conn->prepare("
                INSERT IGNORE INTO subject_levels
                    (subject_id, class_level)
                VALUES
                    (?, ?)
            ");

            $stmt->bind_param(
                "is",
                $subject_id,
                $class_level
            );

            $stmt->execute();

            $stmt->close();


            $_SESSION['setup_step'] = 5;

            $conn->commit();

            $success = true;
        }


        /* =====================================================
           STEP 5 — CREATE ADMIN
        ===================================================== */

        if (isset($_POST['add_admin'])) {

            $admin_username = trim(
                $_POST['admin_username'] ?? ''
            );

            $admin_password =
                $_POST['admin_password'] ?? '';


            if (
                $admin_username === '' ||
                $admin_password === ''
            ) {
                throw new Exception(
                    "Admin username and password required."
                );
            }


            /* Check existing admin */

            $stmt = $conn->prepare("
                SELECT id
                FROM admins
                WHERE username = ?
            ");

            $stmt->bind_param(
                "s",
                $admin_username
            );

            $stmt->execute();
            $stmt->store_result();


            if ($stmt->num_rows === 0) {

                $stmt->close();

                $hashedPassword =
                    password_hash(
                        $admin_password,
                        PASSWORD_DEFAULT
                    );

                $stmt = $conn->prepare("
                    INSERT INTO admins
                        (
                            username,
                            password,
                            role
                        )
                    VALUES
                        (?, ?, 'admin')
                ");

                $stmt->bind_param(
                    "ss",
                    $admin_username,
                    $hashedPassword
                );

                $stmt->execute();

            } else {

                $stmt->close();
            }


            $stmt->close();


            /* Complete setup */

            $stmt = $conn->prepare("
                INSERT INTO system_settings
                    (
                        setup_completed,
                        setup_completed_at,
                        setup_by
                    )
                VALUES
                    (
                        1,
                        NOW(),
                        ?
                    )
            ");

            $stmt->bind_param(
                "i",
                $user_id
            );

            $stmt->execute();

            $stmt->close();


            $conn->commit();

            unset(
                $_SESSION['setup_step']
            );


            header(
                "Location: /EXAMCENTER/super_admin/dashboard.php"
            );

            exit();
        }


    } catch (Exception $e) {

        $conn->rollback();

        $error =
            $e->getMessage();

        error_log($error);
    }
}


$currentStep = max(
    1,
    min(
        $totalSteps,
        (int) (
            $_SESSION['setup_step'] ?? 1
        )
    )
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

<title>
    System Setup | Examcenter
</title>

<!-- =====================================================
     PROJECT STYLES
====================================================== -->

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
    href="../css/sidebar.css"
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
    --primary-dark: #3651d4;

    --dark: #172033;
    --text: #334155;
    --muted: #64748b;

    --border: #e2e8f0;

    --background: #f5f7fb;

    --success: #16a34a;
    --danger: #dc2626;

    --radius: 18px;
}


/* =========================================================
   BASE
========================================================= */

* {
    box-sizing: border-box;
}


html,
body {
    min-height: 100%;
}


body {

    margin: 0;

    background:
        linear-gradient(
            135deg,
            #f8faff 0%,
            #f4f6fb 100%
        );

    color: var(--text);

    font-family:
        Inter,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}


/* =========================================================
   SETUP LAYOUT
========================================================= */

.setup-page {

    min-height: 100vh;

    display: flex;

    align-items: center;
    justify-content: center;

    padding: 35px 20px;
}


.setup-shell {

    width: 100%;

    max-width: 1080px;

    background: #fff;

    border: 1px solid var(--border);

    border-radius: 22px;

    overflow: hidden;

    display: grid;

    grid-template-columns:
        330px 1fr;

    box-shadow:
        0 25px 70px
        rgba(15, 23, 42, .10);
}


/* =========================================================
   LEFT PANEL
========================================================= */

.setup-sidebar {

    background:
        linear-gradient(
            155deg,
            #172033 0%,
            #202c47 100%
        );

    color: #fff;

    padding: 35px 28px;

    position: relative;

    overflow: hidden;
}


.setup-sidebar::before {

    content: "";

    position: absolute;

    width: 230px;
    height: 230px;

    border-radius: 50%;

    background:
        rgba(67, 97, 238, .14);

    top: -100px;
    right: -90px;
}


.setup-sidebar::after {

    content: "";

    position: absolute;

    width: 180px;
    height: 180px;

    border-radius: 50%;

    background:
        rgba(255, 255, 255, .035);

    bottom: -90px;
    left: -80px;
}


.brand {

    position: relative;

    z-index: 2;

    display: flex;

    align-items: center;

    gap: 11px;

    color: #fff;

    text-decoration: none;

    font-size: 20px;

    font-weight: 750;

    margin-bottom: 42px;
}


.brand-icon {

    width: 40px;
    height: 40px;

    border-radius: 11px;

    background: var(--primary);

    display: flex;

    align-items: center;
    justify-content: center;

    box-shadow:
        0 8px 20px
        rgba(67, 97, 238, .30);
}


.setup-intro {

    position: relative;

    z-index: 2;

    margin-bottom: 36px;
}


.setup-intro h1 {

    margin: 0 0 10px;

    font-size: 25px;

    line-height: 1.25;

    font-weight: 750;
}


.setup-intro p {

    margin: 0;

    color: #aeb9cc;

    font-size: 13px;

    line-height: 1.7;
}


/* =========================================================
   STEPS
========================================================= */

.setup-steps {

    position: relative;

    z-index: 2;

    display: flex;

    flex-direction: column;

    gap: 18px;
}


.setup-step-item {

    display: flex;

    align-items: center;

    gap: 13px;

    color: #8997ad;

    position: relative;
}


.setup-step-item:not(:last-child)::after {

    content: "";

    position: absolute;

    left: 16px;
    top: 34px;

    width: 1px;
    height: 18px;

    background:
        rgba(255, 255, 255, .12);
}


.step-number {

    width: 33px;
    height: 33px;

    border-radius: 50%;

    border: 1px solid
        rgba(255, 255, 255, .16);

    display: flex;

    align-items: center;
    justify-content: center;

    flex-shrink: 0;

    font-size: 12px;

    font-weight: 700;
}


.step-text strong {

    display: block;

    color: #c7d0df;

    font-size: 13px;

    font-weight: 650;
}


.step-text span {

    display: block;

    font-size: 11px;

    margin-top: 2px;

    color: #718098;
}


.setup-step-item.active {

    color: #fff;
}


.setup-step-item.active .step-number {

    background: var(--primary);

    border-color: var(--primary);

    color: #fff;

    box-shadow:
        0 5px 15px
        rgba(67, 97, 238, .35);
}


.setup-step-item.active .step-text strong {

    color: #fff;
}


.setup-step-item.completed .step-number {

    background: rgba(22, 163, 74, .18);

    border-color:
        rgba(22, 163, 74, .35);

    color: #86efac;
}


/* =========================================================
   MAIN CONTENT
========================================================= */

.setup-content {

    padding: 40px;

    min-width: 0;
}


/* =========================================================
   CONTENT HEADER
========================================================= */

.content-header {

    display: flex;

    align-items: flex-start;

    justify-content: space-between;

    gap: 20px;

    margin-bottom: 30px;
}


.content-label {

    color: var(--primary);

    font-size: 11px;

    font-weight: 750;

    text-transform: uppercase;

    letter-spacing: .08em;

    margin-bottom: 7px;
}


.content-header h2 {

    margin: 0;

    color: var(--dark);

    font-size: 25px;

    font-weight: 750;
}


.content-header p {

    margin: 6px 0 0;

    color: var(--muted);

    font-size: 13px;

    line-height: 1.6;
}


.step-counter {

    flex-shrink: 0;

    padding: 7px 11px;

    border-radius: 9px;

    background: #f1f5ff;

    color: var(--primary);

    font-size: 12px;

    font-weight: 700;
}


/* =========================================================
   PROGRESS
========================================================= */

.progress-container {

    margin-bottom: 32px;
}


.progress {

    height: 7px;

    background: #eef2f7;

    border-radius: 20px;

    overflow: hidden;
}


.progress-bar {

    background:
        linear-gradient(
            90deg,
            var(--primary),
            #667eea
        );

    border-radius: 20px;

    transition:
        width .35s ease;
}


/* =========================================================
   FORM CARD
========================================================= */

.step-card {

    border: 1px solid var(--border);

    border-radius: 15px;

    background: #fff;

    padding: 25px;

    box-shadow:
        0 5px 20px
        rgba(15, 23, 42, .035);
}


.step-icon {

    width: 48px;
    height: 48px;

    border-radius: 13px;

    background:
        rgba(67, 97, 238, .10);

    color: var(--primary);

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 18px;

    margin-bottom: 17px;
}


.step-card h3 {

    margin: 0 0 6px;

    color: var(--dark);

    font-size: 18px;

    font-weight: 700;
}


.step-card .step-description {

    color: var(--muted);

    font-size: 13px;

    line-height: 1.6;

    margin-bottom: 24px;
}


/* =========================================================
   FORM
========================================================= */

.form-label {

    color: #475569;

    font-size: 12px;

    font-weight: 700;

    margin-bottom: 7px;
}


.form-control,
.form-select {

    min-height: 45px;

    border-color: var(--border);

    border-radius: 9px;

    color: #334155;

    font-size: 13px;

    box-shadow: none !important;

    transition:
        border-color .15s ease,
        box-shadow .15s ease;
}


.form-control::placeholder {

    color: #a0aec0;
}


.form-control:focus,
.form-select:focus {

    border-color: var(--primary);

    box-shadow:
        0 0 0 3px
        rgba(67, 97, 238, .10) !important;
}


.form-hint {

    color: #94a3b8;

    font-size: 11px;

    margin-top: 6px;

    line-height: 1.5;
}


/* =========================================================
   INPUT GROUP
========================================================= */

.input-group .form-control {

    border-radius:
        9px 0 0 9px;
}


.input-group .btn {

    border-radius:
        0 9px 9px 0;
}


/* =========================================================
   CLASS GROUP OPTIONS
========================================================= */

.group-options {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 9px;

    margin-bottom: 4px;
}


.group-option {

    position: relative;
}


.group-option input {

    position: absolute;

    opacity: 0;

    pointer-events: none;
}


.group-option label {

    min-height: 45px;

    border: 1px solid var(--border);

    border-radius: 9px;

    display: flex;

    align-items: center;
    justify-content: center;

    cursor: pointer;

    color: #64748b;

    font-size: 12px;

    font-weight: 700;

    transition: all .15s ease;
}


.group-option label:hover {

    border-color: #aebcf5;

    background: #f8faff;
}


.group-option input:checked + label {

    background:
        rgba(67, 97, 238, .08);

    border-color: var(--primary);

    color: var(--primary);
}


/* =========================================================
   PASSWORD
========================================================= */

.password-wrapper {

    position: relative;
}


.password-wrapper .form-control {

    padding-right: 45px;
}


.password-toggle {

    position: absolute;

    top: 50%;
    right: 10px;

    transform: translateY(-50%);

    width: 32px;
    height: 32px;

    border: 0;

    background: transparent;

    color: #94a3b8;

    display: flex;

    align-items: center;
    justify-content: center;

    cursor: pointer;

    border-radius: 7px;
}


.password-toggle:hover {

    color: var(--primary);

    background: #f1f5ff;
}


/* =========================================================
   ALERTS
========================================================= */

.setup-alert {

    border: 0;

    border-radius: 10px;

    padding: 12px 14px;

    font-size: 12px;

    margin-bottom: 20px;

    display: flex;

    align-items: flex-start;

    gap: 9px;
}


.setup-alert i {

    margin-top: 1px;
}


/* =========================================================
   ACTION
========================================================= */

.form-actions {

    display: flex;

    align-items: center;

    justify-content: flex-end;

    gap: 10px;

    margin-top: 25px;

    padding-top: 20px;

    border-top:
        1px solid #eef2f7;
}


.btn-continue {

    min-height: 43px;

    padding: 0 20px;

    border-radius: 9px;

    background: var(--primary);

    border-color: var(--primary);

    color: #fff;

    font-size: 13px;

    font-weight: 650;

    display: inline-flex;

    align-items: center;

    gap: 8px;

    transition:
        transform .15s ease,
        background .15s ease;
}


.btn-continue:hover {

    background: var(--primary-dark);

    border-color: var(--primary-dark);

    color: #fff;

    transform: translateY(-1px);
}


.btn-finish {

    background: var(--success);

    border-color: var(--success);
}


.btn-finish:hover {

    background: #15803d;

    border-color: #15803d;
}


/* =========================================================
   INFO BOX
========================================================= */

.info-box {

    margin-top: 18px;

    padding: 13px 14px;

    border-radius: 10px;

    background: #f8fafc;

    border: 1px solid #edf2f7;

    display: flex;

    gap: 10px;

    color: #64748b;

    font-size: 11px;

    line-height: 1.6;
}


.info-box i {

    color: var(--primary);

    margin-top: 2px;
}


/* =========================================================
   FOOTER
========================================================= */

.setup-footer {

    margin-top: 20px;

    text-align: center;

    color: #94a3b8;

    font-size: 11px;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 900px) {

    .setup-shell {

        grid-template-columns: 1fr;

        max-width: 650px;
    }


    .setup-sidebar {

        padding: 25px;

    }


    .brand {

        margin-bottom: 25px;
    }


    .setup-intro {

        margin-bottom: 25px;
    }


    .setup-steps {

        display: grid;

        grid-template-columns:
            repeat(5, 1fr);

        gap: 5px;
    }


    .setup-step-item {

        justify-content: center;
    }


    .setup-step-item::after {

        display: none !important;
    }


    .step-text {

        display: none;
    }


    .setup-content {

        padding: 30px;
    }
}


@media (max-width: 600px) {

    .setup-page {

        padding: 15px;
    }


    .setup-shell {

        border-radius: 16px;
    }


    .setup-sidebar {

        padding: 22px 18px;
    }


    .brand {

        margin-bottom: 20px;
    }


    .setup-intro h1 {

        font-size: 21px;
    }


    .setup-steps {

        grid-template-columns:
            repeat(5, 1fr);
    }


    .step-number {

        width: 30px;
        height: 30px;

        font-size: 11px;
    }


    .setup-content {

        padding: 22px 18px;
    }


    .content-header {

        margin-bottom: 22px;
    }


    .content-header h2 {

        font-size: 21px;
    }


    .step-card {

        padding: 20px;
    }


    .group-options {

        grid-template-columns: 1fr;
    }


    .form-actions {

        flex-direction: column;
    }


    .btn-continue {

        width: 100%;

        justify-content: center;
    }
}

</style>

</head>

<body>

<div class="setup-page">

<div class="setup-shell">

<!-- =====================================================
     LEFT SETUP SIDEBAR
====================================================== -->

<aside class="setup-sidebar">

<a
href="#"
class="brand"

>

```
<span class="brand-icon">

    <i class="fas fa-graduation-cap"></i>

</span>

<span>
    Examcenter
</span>
```

</a>

<div class="setup-intro">

```
<h1>
    System Setup
</h1>

<p>
    Let's configure your examination
    system. Complete each step to
    prepare Examcenter for use.
</p>
```

</div>

<div class="setup-steps">

<div
    class="setup-step-item"
    data-step-item="1"
>

```
<div class="step-number">
    1
</div>

<div class="step-text">

    <strong>
        School
    </strong>

    <span>
        School information
    </span>

</div>
```

</div>

<div
    class="setup-step-item"
    data-step-item="2"
>

```
<div class="step-number">
    2
</div>

<div class="step-text">

    <strong>
        Academic Year
    </strong>

    <span>
        Current academic year
    </span>

</div>
```

</div>

<div
    class="setup-step-item"
    data-step-item="3"
>

```
<div class="step-number">
    3
</div>

<div class="step-text">

    <strong>
        Class
    </strong>

    <span>
        Class and stream
    </span>

</div>
```

</div>

<div
    class="setup-step-item"
    data-step-item="4"
>

```
<div class="step-number">
    4
</div>

<div class="step-text">

    <strong>
        Subject
    </strong>

    <span>
        Subject configuration
    </span>

</div>
```

</div>

<div
    class="setup-step-item"
    data-step-item="5"
>

```
<div class="step-number">
    5
</div>

<div class="step-text">

    <strong>
        Administrator
    </strong>

    <span>
        Create admin account
    </span>

</div>
```

</div>

</div>

</aside>

<!-- =====================================================
     MAIN SETUP CONTENT
====================================================== -->

<main class="setup-content">

<div class="content-header">

```
<div>

    <div class="content-label">
        Initial Configuration
    </div>

    <h2 id="contentTitle">
        Create School
    </h2>

    <p id="contentDescription">
        Start by registering the school
        that will use Examcenter.
    </p>

</div>


<div class="step-counter">

    Step
    <span id="currentStepNumber">
        <?= $currentStep ?>
    </span>
    of
    <?= $totalSteps ?>

</div>
```

</div>

<!-- Progress -->

<div class="progress-container">

```
<div class="progress">

    <div
        id="setupProgress"
        class="progress-bar"
        role="progressbar"
        style="
            width:
            <?= ($currentStep / $totalSteps) * 100 ?>%;
        "
    ></div>

</div>
```

</div>

<?php if (!empty($error)): ?>

<div class="setup-alert alert alert-danger">

```
<i class="fas fa-exclamation-circle"></i>

<div>
    <?= htmlspecialchars($error) ?>
</div>
```

</div>

<?php endif; ?>

<!-- =====================================================
     STEP 1
====================================================== -->

<div
    class="setup-step"
    data-step="1"
>

<div class="step-card">

```
<div class="step-icon">
    <i class="fas fa-school"></i>
</div>

<h3>
    Create School
</h3>

<div class="step-description">
    Enter the official name of the school
    that will operate this Examcenter installation.
</div>


<form
    method="POST"
    action="system_setup.php"
>

    <div class="mb-3">

        <label
            for="school_name"
            class="form-label"
        >
            School Name
        </label>

        <input
            type="text"
            id="school_name"
            name="school_name"
            class="form-control"
            placeholder="e.g. Kings College"
            autocomplete="organization"
            required
        >

        <div class="form-hint">
            Use the school's official registered name.
        </div>

    </div>


    <div class="form-actions">

        <button
            type="submit"
            name="add_school"
            class="btn btn-continue"
        >

            Save & Continue

            <i class="fas fa-arrow-right"></i>

        </button>

    </div>

</form>
```

</div>

</div>

<!-- =====================================================
     STEP 2
====================================================== -->

<div
    class="setup-step d-none"
    data-step="2"
>

<div class="step-card">

```
<div class="step-icon">
    <i class="fas fa-calendar-alt"></i>
</div>

<h3>
    Add Academic Year
</h3>

<div class="step-description">
    Define the academic year that will be
    used by the examination system.
</div>


<form
    method="POST"
    action="system_setup.php"
>

    <div class="mb-3">

        <label
            for="new_year"
            class="form-label"
        >
            Academic Year
        </label>

        <input
            type="text"
            id="new_year"
            name="new_year"
            class="form-control"
            placeholder="e.g. 2025/2026"
            required
        >

        <div class="form-hint">
            Enter the academic year using the
            school's preferred format.
        </div>

    </div>


    <div class="form-actions">

        <button
            type="submit"
            name="add_year"
            class="btn btn-continue"
        >

            Save & Continue

            <i class="fas fa-arrow-right"></i>

        </button>

    </div>

</form>
```

</div>

</div>

<!-- =====================================================
     STEP 3
====================================================== -->

<div
    class="setup-step d-none"
    data-step="3"
>

<div class="step-card">

```
<div class="step-icon">
    <i class="fas fa-users"></i>
</div>

<h3>
    Add Class
</h3>

<div class="step-description">
    Create the first academic level and
    stream that students will belong to.
</div>


<form
    method="POST"
    action="system_setup.php"
>


    <div class="mb-3">

        <label class="form-label">
            Class Group
        </label>

        <div class="group-options">

            <?php foreach ($class_groups as $group): ?>

            <div class="group-option">

                <input
                    type="radio"
                    id="group_<?= htmlspecialchars(strtolower($group)) ?>"
                    name="class_group"
                    value="<?= htmlspecialchars($group) ?>"
                    required
                >

                <label
                    for="group_<?= htmlspecialchars(strtolower($group)) ?>"
                >
                    <?= htmlspecialchars($group) ?>
                </label>

            </div>

            <?php endforeach; ?>

        </div>

    </div>


    <div class="row g-3">


        <div class="col-md-6">

            <label
                for="level_code"
                class="form-label"
            >
                Level Code
            </label>

            <input
                type="text"
                id="level_code"
                name="level_code"
                class="form-control"
                placeholder="e.g. JSS1"
                required
            >

            <div class="form-hint">
                PRIMARY uses PRY codes;
                JSS uses JSS codes;
                SS uses SS codes.
            </div>

        </div>


        <div class="col-md-6">

            <label
                for="stream_name"
                class="form-label"
            >
                Stream Name
            </label>

            <input
                type="text"
                id="stream_name"
                name="stream_name"
                class="form-control"
                placeholder="e.g. Gold"
                required
            >

            <div class="form-hint">
                Example:
                JSS1 Gold.
            </div>

        </div>


    </div>


    <div class="info-box">

        <i class="fas fa-info-circle"></i>

        <div>
            The level code must correspond
            to the selected class group.
        </div>

    </div>


    <div class="form-actions">

        <button
            type="submit"
            name="add_class"
            class="btn btn-continue"
        >

            Save & Continue

            <i class="fas fa-arrow-right"></i>

        </button>

    </div>


</form>
```

</div>

</div>

<!-- =====================================================
     STEP 4
====================================================== -->

<div
    class="setup-step d-none"
    data-step="4"
>

<div class="step-card">

```
<div class="step-icon">
    <i class="fas fa-book"></i>
</div>

<h3>
    Add Subject
</h3>

<div class="step-description">
    Create a subject and associate it with
    the appropriate class group.
</div>


<form
    method="POST"
    action="system_setup.php"
>


    <div class="mb-3">

        <label
            for="subject_name"
            class="form-label"
        >
            Subject Name
        </label>

        <input
            type="text"
            id="subject_name"
            name="subject_name"
            class="form-control"
            placeholder="e.g. Mathematics"
            required
        >

    </div>


    <div class="mb-3">

        <label
            for="class_level"
            class="form-label"
        >
            Class Group
        </label>

        <select
            id="class_level"
            name="class_level"
            class="form-select"
            required
        >

            <option value="">
                Select Class Group
            </option>

            <option value="PRIMARY">
                PRIMARY
            </option>

            <option value="JSS">
                JSS
            </option>

            <option value="SS">
                SS
            </option>

        </select>

    </div>


    <div class="form-actions">

        <button
            type="submit"
            name="add_subject"
            class="btn btn-continue"
        >

            Save & Continue

            <i class="fas fa-arrow-right"></i>

        </button>

    </div>


</form>
```

</div>

</div>

<!-- =====================================================
     STEP 5
====================================================== -->

<div
    class="setup-step d-none"
    data-step="5"
>

<div class="step-card">

```
<div class="step-icon">
    <i class="fas fa-user-shield"></i>
</div>

<h3>
    Create Admin Account
</h3>

<div class="step-description">
    Create the administrator account that
    will manage the Examcenter system.
</div>


<form
    method="POST"
    action="system_setup.php"
>


    <div class="mb-3">

        <label
            for="admin_username"
            class="form-label"
        >
            Admin Username
        </label>

        <input
            type="text"
            id="admin_username"
            name="admin_username"
            class="form-control"
            placeholder="Enter administrator username"
            autocomplete="username"
            required
        >

    </div>


    <div class="mb-3">

        <label
            for="admin_password"
            class="form-label"
        >
            Admin Password
        </label>

        <div class="password-wrapper">

            <input
                type="password"
                id="admin_password"
                name="admin_password"
                class="form-control"
                placeholder="Create a secure password"
                autocomplete="new-password"
                required
            >

            <button
                type="button"
                class="password-toggle"
                id="passwordToggle"
                aria-label="Show password"
            >
                <i class="fas fa-eye"></i>
            </button>

        </div>

        <div class="form-hint">
            Use a strong password that only the
            administrator should know.
        </div>

    </div>


    <div class="info-box">

        <i class="fas fa-shield-alt"></i>

        <div>
            This account will be used to access
            and manage the configured Examcenter
            installation.
        </div>

    </div>


    <div class="form-actions">

        <button
            type="submit"
            name="add_admin"
            class="btn btn-continue btn-finish"
        >

            <i class="fas fa-check"></i>

            Finish Setup

        </button>

    </div>


</form>
```

</div>

</div>

<div class="setup-footer">

```
Examcenter System Setup

&bull;

Secure Initial Configuration
```

</div>

</main>

</div>

</div>

<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script src="../js/bootstrap.bundle.min.js"></script>

<script>

(function () {

    "use strict";


    /* =====================================================
       CONFIGURATION
    ===================================================== */

    const currentStep =
        <?= $currentStep ?>;

    const totalSteps =
        <?= $totalSteps ?>;


    /* =====================================================
       STEP CONTENT
    ===================================================== */

    const stepData = {

        1: {
            title: "Create School",
            description:
                "Start by registering the school that will use Examcenter."
        },

        2: {
            title: "Add Academic Year",
            description:
                "Define the academic year that will be used by the examination system."
        },

        3: {
            title: "Add Class",
            description:
                "Create the first academic level and stream that students will belong to."
        },

        4: {
            title: "Add Subject",
            description:
                "Create a subject and associate it with the appropriate class group."
        },

        5: {
            title: "Create Admin Account",
            description:
                "Create the administrator account that will manage the Examcenter system."
        }

    };


    /* =====================================================
       SHOW CURRENT STEP
    ===================================================== */

    function showStep(step) {

        document
            .querySelectorAll(".setup-step")
            .forEach(function (element) {

                element.classList.add("d-none");

            });


        const activeStep =
            document.querySelector(
                '.setup-step[data-step="' +
                step +
                '"]'
            );


        if (activeStep) {

            activeStep.classList.remove(
                "d-none"
            );

        }


        /* Progress */

        const progress =
            document.getElementById(
                "setupProgress"
            );


        if (progress) {

            progress.style.width =
                (
                    step /
                    totalSteps *
                    100
                ) + "%";

        }


        /* Counter */

        const counter =
            document.getElementById(
                "currentStepNumber"
            );


        if (counter) {

            counter.textContent =
                step;

        }


        /* Header */

        if (stepData[step]) {

            const title =
                document.getElementById(
                    "contentTitle"
                );

            const description =
                document.getElementById(
                    "contentDescription"
                );


            if (title) {

                title.textContent =
                    stepData[step].title;

            }


            if (description) {

                description.textContent =
                    stepData[step].description;

            }

        }


        /* Sidebar steps */

        document
            .querySelectorAll(
                "[data-step-item]"
            )
            .forEach(function (item) {

                const itemStep =
                    Number(
                        item.dataset.stepItem
                    );


                item.classList.remove(
                    "active",
                    "completed"
                );


                if (itemStep === step) {

                    item.classList.add(
                        "active"
                    );

                }

                else if (itemStep < step) {

                    item.classList.add(
                        "completed"
                    );

                    const number =
                        item.querySelector(
                            ".step-number"
                        );


                    if (number) {

                        number.innerHTML =
                            '<i class="fas fa-check"></i>';

                    }

                }

            });

    }


    showStep(currentStep);


    /* =====================================================
       PASSWORD VISIBILITY
    ===================================================== */

    const passwordToggle =
        document.getElementById(
            "passwordToggle"
        );

    const passwordInput =
        document.getElementById(
            "admin_password"
        );


    if (
        passwordToggle &&
        passwordInput
    ) {

        passwordToggle.addEventListener(
            "click",
            function () {

                const isPassword =
                    passwordInput.type ===
                    "password";


                passwordInput.type =
                    isPassword
                        ? "text"
                        : "password";


                const icon =
                    passwordToggle.querySelector(
                        "i"
                    );


                if (icon) {

                    icon.classList.toggle(
                        "fa-eye",
                        !isPassword
                    );

                    icon.classList.toggle(
                        "fa-eye-slash",
                        isPassword
                    );

                }


                passwordToggle.setAttribute(
                    "aria-label",
                    isPassword
                        ? "Hide password"
                        : "Show password"
                );

            }
        );

    }


    /* =====================================================
       CLASS GROUP → LEVEL CODE HELPER
    ===================================================== */

    const groupInputs =
        document.querySelectorAll(
            'input[name="class_group"]'
        );

    const levelCode =
        document.getElementById(
            "level_code"
        );


    if (
        groupInputs.length &&
        levelCode
    ) {

        groupInputs.forEach(
            function (input) {

                input.addEventListener(
                    "change",
                    function () {

                        const group =
                            this.value;


                        if (
                            levelCode.value
                                .trim() === ""
                        ) {

                            if (
                                group ===
                                "PRIMARY"
                            ) {

                                levelCode.placeholder =
                                    "e.g. PRY1";

                            }

                            else if (
                                group ===
                                "JSS"
                            ) {

                                levelCode.placeholder =
                                    "e.g. JSS1";

                            }

                            else if (
                                group ===
                                "SS"
                            ) {

                                levelCode.placeholder =
                                    "e.g. SS1";

                            }

                        }

                    }
                );

            }
        );

    }

})();

</script>

</body>

</html>
