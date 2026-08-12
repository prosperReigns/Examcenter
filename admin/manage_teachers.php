<?php
// manage_teachers.php

session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';

// ============================================================
// DEVELOPMENT ERROR REPORTING
// ============================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ============================================================
// AUTHENTICATION
// ============================================================
if (!isset($_SESSION['user_id'])) {
    error_log("Redirecting to login: No user_id in session");
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

    $user_id = (int) $_SESSION['user_id'];

    // --------------------------------------------------------
    // Verify admin
    // --------------------------------------------------------
    $stmt = $conn->prepare("
        SELECT username, role
        FROM admins
        WHERE id = ?
        LIMIT 1
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


// ============================================================
// VARIABLES
// ============================================================
$error = '';
$success = '';


// ============================================================
// GET ALL ACTIVE CLASSES
// ============================================================
$classes = [];

$classStmt = $conn->prepare("
    SELECT
        c.id,
        c.class_name,
        c.academic_level_id,
        c.stream_id,
        al.level_code,
        s.stream_name
    FROM classes c
    JOIN academic_levels al
        ON c.academic_level_id = al.id
    JOIN streams s
        ON c.stream_id = s.id
    WHERE c.is_active = 1
    ORDER BY al.level_code, s.stream_name
");

$classStmt->execute();

$classResult = $classStmt->get_result();

$classes = $classResult->fetch_all(MYSQLI_ASSOC);

$classStmt->close();


// ============================================================
// PRE-FETCH TEACHER → CLASS MAPPING
// Avoid N+1 queries
// ============================================================
$teacherClasses = [];

$stmt = $conn->prepare("
    SELECT
        tc.teacher_id,
        c.id AS class_id,
        al.level_code,
        s.stream_name
    FROM teacher_classes tc
    JOIN classes c
        ON tc.class_id = c.id
    JOIN academic_levels al
        ON c.academic_level_id = al.id
    JOIN streams s
        ON c.stream_id = s.id
");

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $teacherClasses[$row['teacher_id']] =
        $row['level_code'] . ' ' . $row['stream_name'];
}

$stmt->close();


// ============================================================
// DELETE TEACHER
// ============================================================
if (isset($_GET['delete_id'])) {

    $teacher_id = (int) $_GET['delete_id'];

    try {

        $conn->begin_transaction();

        // ----------------------------------------------------
        // Verify teacher exists
        // ----------------------------------------------------
        $stmt = $conn->prepare("
            SELECT email
            FROM teachers
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $teacher = $result->fetch_assoc();

        $stmt->close();

        if ($teacher) {

            // ------------------------------------------------
            // Delete teacher subjects
            // ------------------------------------------------
            $stmt = $conn->prepare("
                DELETE FROM teacher_subjects
                WHERE teacher_id = ?
            ");

            $stmt->bind_param("i", $teacher_id);
            $stmt->execute();

            $stmt->close();


            // ------------------------------------------------
            // Delete teacher
            // ------------------------------------------------
            $stmt = $conn->prepare("
                DELETE FROM teachers
                WHERE id = ?
            ");

            $stmt->bind_param("i", $teacher_id);
            $stmt->execute();

            $stmt->close();


            $conn->commit();

            $success = "Teacher deleted successfully!";


            // ------------------------------------------------
            // Activity Log
            // ------------------------------------------------
            $ip_address =
                filter_var(
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    FILTER_VALIDATE_IP
                ) ?: '0.0.0.0';

            $user_agent =
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

            $activity =
                "Admin {$user['username']} deleted teacher ID: {$teacher_id}";

            $stmt = $conn->prepare("
                INSERT INTO activities_log
                (
                    activity,
                    admin_id,
                    ip_address,
                    user_agent,
                    created_at
                )
                VALUES (?, ?, ?, ?, NOW())
            ");

            $stmt->bind_param(
                "siss",
                $activity,
                $user_id,
                $ip_address,
                $user_agent
            );

            $stmt->execute();
            $stmt->close();

        } else {

            $conn->rollback();

            $error = "Teacher not found!";
        }

    } catch (Exception $e) {

        $conn->rollback();

        error_log("Teacher deletion error: " . $e->getMessage());

        $error = "Error deleting teacher: " . $e->getMessage();
    }
}


// ============================================================
// FETCH ALL TEACHERS
// ============================================================
$teachers = [];

$stmt = $conn->prepare("
    SELECT
        t.id,
        t.first_name,
        t.last_name,
        t.username,
        t.email,
        t.phone,
        GROUP_CONCAT(
            ts.subject
            SEPARATOR ', '
        ) AS subjects
    FROM teachers t
    LEFT JOIN teacher_subjects ts
        ON t.id = ts.teacher_id
    GROUP BY t.id
    ORDER BY t.last_name, t.first_name
");

$stmt->execute();

$result = $stmt->get_result();

$teachers = $result->fetch_all(MYSQLI_ASSOC);

$stmt->close();


// ============================================================
// STATISTICS
// ============================================================
$totalTeachers = count($teachers);

$teachersWithSubjects = 0;
$teachersWithClasses = 0;

foreach ($teachers as $teacher) {

    if (!empty($teacher['subjects'])) {
        $teachersWithSubjects++;
    }

    if (isset($teacherClasses[$teacher['id']])) {
        $teachersWithClasses++;
    }
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

    <title>Manage Teachers | Examcenter</title>

    <!-- Bootstrap -->
    <link
        href="../css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Font Awesome -->
    <link
        rel="stylesheet"
        href="../css/all.css"
    >

    <!-- DataTables -->
    <link
        rel="stylesheet"
        href="../css/dataTables.bootstrap5.min.css"
    >

    <!-- Existing Dashboard Styles -->
    <link
        rel="stylesheet"
        href="../css/admin-dashboard.css"
    >

    <link
        rel="stylesheet"
        href="../css/dashboard.css"
    >

    <!-- Sidebar -->
    <link
        rel="stylesheet"
        href="../css/sidebar.css"
    >


    <style>

        /* =====================================================
           PAGE
        ===================================================== */

        body {
            background: #f5f7fb;
        }

        .main-content {
            padding-bottom: 40px;
        }


        /* =====================================================
           PAGE HEADER
        ===================================================== */

        .page-header {
            margin-bottom: 25px;
        }

        .page-title {
            font-size: 1.65rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
        }

        .page-subtitle {
            color: #6b7280;
            font-size: 0.92rem;
            margin: 0;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }


        /* =====================================================
           STAT CARDS
        ===================================================== */

        .stat-card {
            background: #fff;
            border: 1px solid #e9edf5;
            border-radius: 14px;
            padding: 18px 20px;
            height: 100%;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.035);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(15, 23, 42, 0.07);
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef2ff;
            color: #4361ee;
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        .stat-label {
            font-size: .78rem;
            font-weight: 600;
            color: #7b8494;
            text-transform: uppercase;
            letter-spacing: .035em;
            margin-bottom: 3px;
        }

        .stat-value {
            font-size: 1.35rem;
            font-weight: 700;
            color: #1f2937;
            line-height: 1.1;
        }


        /* =====================================================
           MAIN TABLE CARD
        ===================================================== */

        .teachers-card {
            margin-top: 24px;
            background: #fff;
            border: 1px solid #e9edf5;
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.045);
            overflow: hidden;
        }

        .teachers-card-header {
            padding: 20px 22px;
            border-bottom: 1px solid #edf0f5;
        }

        .card-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }

        .card-description {
            color: #8a93a3;
            font-size: .84rem;
            margin-top: 4px;
            margin-bottom: 0;
        }


        /* =====================================================
           SEARCH
        ===================================================== */

        .table-toolbar {
            padding: 16px 22px;
            background: #fafbfc;
            border-bottom: 1px solid #edf0f5;
        }

        .search-box {
            position: relative;
            max-width: 360px;
        }

        .search-box i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #9aa3b2;
            z-index: 2;
        }

        .search-box input {
            height: 42px;
            border: 1px solid #dfe4ec;
            border-radius: 9px;
            padding-left: 38px;
            font-size: .88rem;
            background: #fff;
        }

        .search-box input:focus {
            border-color: #4361ee;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, .1);
        }


        /* =====================================================
           TABLE
        ===================================================== */

        .table-container {
            padding: 0 22px 18px;
        }

        #teachersTable {
            margin-top: 0 !important;
        }

        #teachersTable thead th {
            background: #fafbfc;
            color: #687386;
            font-size: .74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .035em;
            border-bottom: 1px solid #e6eaf0;
            padding: 14px 12px;
            white-space: nowrap;
        }

        #teachersTable tbody td {
            vertical-align: middle;
            padding: 15px 12px;
            color: #374151;
            font-size: .86rem;
            border-color: #edf0f4;
        }

        #teachersTable tbody tr {
            transition: background .15s ease;
        }

        #teachersTable tbody tr:hover {
            background: #fafbff;
        }


        /* =====================================================
           TEACHER NAME
        ===================================================== */

        .teacher-profile {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 180px;
        }

        .teacher-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(
                135deg,
                #4361ee,
                #5d74ef
            );
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: .78rem;
            flex-shrink: 0;
        }

        .teacher-name {
            font-weight: 650;
            color: #1f2937;
            margin: 0;
            line-height: 1.2;
        }

        .teacher-role {
            color: #8a93a3;
            font-size: .72rem;
            margin-top: 2px;
        }


        /* =====================================================
           SUBJECT BADGES
        ===================================================== */

        .subject-badge {
            background: #eef2ff;
            color: #4354a7;
            border: 1px solid #e1e6ff;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: .72rem;
            font-weight: 600;
            margin-right: 4px;
            margin-bottom: 4px;
            display: inline-block;
        }


        /* =====================================================
           CLASS BADGE
        ===================================================== */

        .class-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            border-radius: 7px;
            padding: 5px 9px;
            font-size: .74rem;
            font-weight: 600;
            white-space: nowrap;
        }


        /* =====================================================
           ACTION BUTTONS
        ===================================================== */

        .action-buttons {
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .action-btn {
            border-radius: 7px;
            font-size: .75rem;
            font-weight: 600;
            padding: 6px 9px;
        }

        .action-btn i {
            margin-right: 4px;
        }


        /* =====================================================
           EMPTY STATE
        ===================================================== */

        .empty-state {
            padding: 65px 20px;
        }

        .empty-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #eef2ff;
            color: #4361ee;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            font-size: 1.7rem;
        }

        .empty-state h4 {
            font-size: 1.05rem;
            font-weight: 700;
            color: #374151;
        }

        .empty-state p {
            color: #8a93a3;
            font-size: .86rem;
        }


        /* =====================================================
           DELETE MODAL
        ===================================================== */

        .delete-modal-icon {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: #fff1f2;
            color: #dc3545;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 1.3rem;
        }

        .delete-modal-content {
            text-align: center;
            padding: 10px 15px 5px;
        }

        .delete-modal-content h5 {
            font-weight: 700;
            color: #1f2937;
        }

        .delete-modal-content p {
            color: #6b7280;
            font-size: .88rem;
            margin-bottom: 0;
        }


        /* =====================================================
           DATATABLES
        ===================================================== */

        .dataTables_wrapper .dataTables_info {
            color: #7b8494;
            font-size: .78rem;
            padding-top: 15px;
        }

        .dataTables_wrapper .dataTables_paginate {
            padding-top: 10px;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border-radius: 7px !important;
            margin-left: 3px;
            border: 1px solid transparent !important;
            font-size: .78rem;
        }

        .dataTables_wrapper
        .dataTables_paginate
        .paginate_button.current {
            background: #4361ee !important;
            color: #fff !important;
            border-color: #4361ee !important;
        }

        .dataTables_wrapper
        .dataTables_paginate
        .paginate_button:hover {
            background: #eef2ff !important;
            color: #4361ee !important;
            border-color: #dfe5ff !important;
        }


        /* =====================================================
           ALERTS
        ===================================================== */

        .custom-alert {
            border: 0;
            border-radius: 10px;
            font-size: .86rem;
            box-shadow: 0 3px 12px rgba(15, 23, 42, .04);
        }


        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 991.98px) {

            .main-content {
                padding: 20px 15px;
            }

            .page-title {
                font-size: 1.35rem;
            }

            .header-actions .btn-primary {
                display: none;
            }

            .stat-card {
                padding: 15px;
            }

            .teachers-card-header {
                padding: 17px;
            }

            .table-toolbar {
                padding: 14px 17px;
            }

            .table-container {
                padding: 0 10px 15px;
            }

            .search-box {
                max-width: 100%;
            }

        }


        @media (max-width: 575.98px) {

            .page-header {
                align-items: flex-start !important;
            }

            .page-subtitle {
                max-width: 240px;
            }

            .stat-card {
                padding: 14px;
            }

            .stat-icon {
                width: 40px;
                height: 40px;
            }

        }

    </style>

</head>


<body>


<!-- =========================================================
     SIDEBAR
========================================================== -->

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
                    <?php echo htmlspecialchars($user['username']); ?>
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
            <i class="fas fa-user-plus"></i>
            Add Teachers
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
            <i class="fas fa-user-plus"></i>
            Manage Session
        </a>

        <a href="manage_subject.php">
            <i class="fas fa-book"></i>
            Manage Subject
        </a>

        <a href="manage_teachers.php" class="active">
            <i class="fas fa-chalkboard-teacher"></i>
            Manage Teachers
        </a>

        <a href="manage_test.php">
            <i class="fas fa-file-alt"></i>
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

        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            Logout
        </a>

    </div>

</div>


<!-- =========================================================
     MAIN CONTENT
========================================================== -->

<div class="main-content">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="header page-header d-flex justify-content-between align-items-center">

        <div>

            <h2 class="page-title">
                Manage Teachers
            </h2>

            <p class="page-subtitle">
                View, manage and organize teachers registered in your school.
            </p>

        </div>


        <div class="header-actions">

            <a
                href="add_teacher.php"
                class="btn btn-primary"
            >
                <i class="fas fa-plus me-2"></i>
                Add Teacher
            </a>

            <button
                class="btn btn-primary d-lg-none"
                id="sidebarToggle"
                type="button"
                aria-label="Toggle sidebar"
            >
                <i class="fas fa-bars"></i>
            </button>

        </div>

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


    <!-- =====================================================
         STATISTICS
    ====================================================== -->

    <div class="row g-3">

        <div class="col-12 col-md-4">

            <div class="stat-card">

                <div class="d-flex align-items-center gap-3">

                    <div class="stat-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>

                    <div>

                        <div class="stat-label">
                            Total Teachers
                        </div>

                        <div class="stat-value">
                            <?php echo $totalTeachers; ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-4">

            <div class="stat-card">

                <div class="d-flex align-items-center gap-3">

                    <div class="stat-icon">
                        <i class="fas fa-book"></i>
                    </div>

                    <div>

                        <div class="stat-label">
                            With Subjects
                        </div>

                        <div class="stat-value">
                            <?php echo $teachersWithSubjects; ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-4">

            <div class="stat-card">

                <div class="d-flex align-items-center gap-3">

                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>

                    <div>

                        <div class="stat-label">
                            With Classes
                        </div>

                        <div class="stat-value">
                            <?php echo $teachersWithClasses; ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =====================================================
         TEACHERS CARD
    ====================================================== -->

    <div class="teachers-card">


        <!-- Card Header -->

        <div class="teachers-card-header">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                <div>

                    <h5 class="card-title">
                        <i class="fas fa-users me-2 text-primary"></i>
                        Teachers List
                    </h5>

                    <p class="card-description">
                        Manage teacher accounts, subjects and class assignments.
                    </p>

                </div>

                <div class="text-muted small">

                    <?php echo $totalTeachers; ?>

                    <?php echo $totalTeachers === 1 ? 'teacher' : 'teachers'; ?>

                </div>

            </div>

        </div>


        <!-- Toolbar -->

        <div class="table-toolbar">

            <div class="search-box">

                <i class="fas fa-search"></i>

                <input
                    type="text"
                    id="searchInput"
                    class="form-control"
                    placeholder="Search by name, username, email..."
                    autocomplete="off"
                >

            </div>

        </div>


        <!-- Table -->

        <div class="table-container">

            <?php if (!empty($teachers)): ?>

                <div class="table-responsive">

                    <table
                        id="teachersTable"
                        class="table table-hover align-middle"
                        style="width:100%"
                    >

                        <thead>

                            <tr>

                                <th>Name</th>

                                <th>Username</th>

                                <th>Email</th>

                                <th>Phone</th>

                                <th>Subjects</th>

                                <th>Class</th>

                                <th>Actions</th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($teachers as $teacher): ?>

                            <?php

                            $fullName =
                                trim(
                                    $teacher['first_name'] .
                                    ' ' .
                                    $teacher['last_name']
                                );

                            $initials =
                                strtoupper(
                                    substr(
                                        $teacher['first_name'] ?? '',
                                        0,
                                        1
                                    ) .
                                    substr(
                                        $teacher['last_name'] ?? '',
                                        0,
                                        1
                                    )
                                );

                            ?>

                            <tr class="teacher-row">


                                <!-- Name -->

                                <td>

                                    <div class="teacher-profile">

                                        <div class="teacher-avatar">

                                            <?php
                                            echo htmlspecialchars(
                                                $initials ?: 'T'
                                            );
                                            ?>

                                        </div>

                                        <div>

                                            <div class="teacher-name">

                                                <?php
                                                echo htmlspecialchars(
                                                    $fullName
                                                );
                                                ?>

                                            </div>

                                            <div class="teacher-role">
                                                Teacher
                                            </div>

                                        </div>

                                    </div>

                                </td>


                                <!-- Username -->

                                <td>

                                    <span class="text-dark">

                                        <?php
                                        echo htmlspecialchars(
                                            $teacher['username']
                                        );
                                        ?>

                                    </span>

                                </td>


                                <!-- Email -->

                                <td>

                                    <?php if (!empty($teacher['email'])): ?>

                                        <span>
                                            <?php
                                            echo htmlspecialchars(
                                                $teacher['email']
                                            );
                                            ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            Not provided
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- Phone -->

                                <td>

                                    <?php if (!empty($teacher['phone'])): ?>

                                        <?php
                                        echo htmlspecialchars(
                                            $teacher['phone']
                                        );
                                        ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            Not provided
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- Subjects -->

                                <td>

                                    <?php if (!empty($teacher['subjects'])): ?>

                                        <?php
                                        $teacherSubjects =
                                            explode(
                                                ', ',
                                                $teacher['subjects']
                                            );
                                        ?>

                                        <?php foreach ($teacherSubjects as $subject): ?>

                                            <span class="subject-badge">

                                                <?php
                                                echo htmlspecialchars(
                                                    $subject
                                                );
                                                ?>

                                            </span>

                                        <?php endforeach; ?>

                                    <?php else: ?>

                                        <span class="text-muted small">

                                            <i class="fas fa-minus-circle me-1"></i>
                                            No subjects assigned

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- Class -->

                                <td>

                                    <?php
                                    $assignedClass =
                                        $teacherClasses[$teacher['id']]
                                        ?? null;
                                    ?>

                                    <?php if ($assignedClass): ?>

                                        <span class="class-badge">

                                            <i class="fas fa-users"></i>

                                            <?php
                                            echo htmlspecialchars(
                                                $assignedClass
                                            );
                                            ?>

                                        </span>

                                    <?php else: ?>

                                        <span class="text-muted small">

                                            <i class="fas fa-minus-circle me-1"></i>
                                            No class assigned

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- Actions -->

                                <td>

                                    <div class="action-buttons">

                                        <a
                                            href="add_teacher.php?edit_id=<?php echo (int) $teacher['id']; ?>"
                                            class="btn btn-sm btn-outline-primary action-btn"
                                            title="Edit teacher"
                                        >

                                            <i class="fas fa-edit"></i>
                                            Edit

                                        </a>


                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger action-btn delete-btn"
                                            data-id="<?php echo (int) $teacher['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>"
                                            title="Delete teacher"
                                        >

                                            <i class="fas fa-trash-alt"></i>
                                            Delete

                                        </button>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>


                <!-- Empty State -->

                <div class="empty-state text-center">

                    <div class="empty-icon">

                        <i class="fas fa-chalkboard-teacher"></i>

                    </div>

                    <h4>
                        No Teachers Found
                    </h4>

                    <p>
                        There are currently no teachers registered in the system.
                    </p>

                    <a
                        href="add_teacher.php"
                        class="btn btn-primary"
                    >

                        <i class="fas fa-plus me-2"></i>

                        Add Teacher

                    </a>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>


<!-- =========================================================
     DELETE CONFIRMATION MODAL
========================================================== -->

<div
    class="modal fade"
    id="deleteModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content border-0 shadow">

            <div class="modal-header border-0 pb-0">

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>

            </div>

            <div class="modal-body">

                <div class="delete-modal-content">

                    <div class="delete-modal-icon">

                        <i class="fas fa-trash-alt"></i>

                    </div>

                    <h5>
                        Delete Teacher?
                    </h5>

                    <p>

                        Are you sure you want to delete
                        <strong id="deleteTeacherName"></strong>?

                        <br>

                        This action cannot be undone.

                    </p>

                </div>

            </div>


            <div class="modal-footer border-0 justify-content-center pb-4">

                <button
                    type="button"
                    class="btn btn-light px-4"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <a
                    href="#"
                    id="confirmDelete"
                    class="btn btn-danger px-4"
                >
                    <i class="fas fa-trash-alt me-2"></i>
                    Delete Teacher
                </a>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     SCRIPTS
========================================================== -->

<script src="../js/jquery-3.7.0.min.js"></script>

<script src="../js/bootstrap.bundle.min.js"></script>

<script src="../js/jquery.dataTables.min.js"></script>

<script src="../js/dataTables.bootstrap5.min.js"></script>

<script src="../js/jquery.validate.min.js"></script>


<script>

$(document).ready(function () {

    // ========================================================
    // SIDEBAR TOGGLE
    // ========================================================

    $('#sidebarToggle').on('click', function () {

        $('.sidebar').toggleClass('active');

    });


    // ========================================================
    // INITIALIZE DATATABLE
    // ========================================================

    let teachersTable = null;

    if ($('#teachersTable').length) {

        teachersTable = $('#teachersTable').DataTable({

            pageLength: 10,

            searching: true,

            lengthChange: false,

            ordering: true,

            responsive: false,

            autoWidth: false,

            columnDefs: [

                {
                    orderable: false,
                    targets: [4, 5, 6]
                }

            ],

            language: {

                search: '',

                searchPlaceholder:
                    'Search teachers...',

                lengthMenu:
                    'Show _MENU_ teachers',

                info:
                    'Showing _START_ to _END_ of _TOTAL_ teachers',

                infoEmpty:
                    'No teachers available',

                zeroRecords:
                    'No teachers match your search',

                paginate: {

                    previous: '‹',

                    next: '›'

                }

            },

            dom:
                'rt' +
                '<"d-flex justify-content-between align-items-center flex-wrap gap-2"ip>'

        });

    }


    // ========================================================
    // CUSTOM SEARCH
    // ========================================================

    $('#searchInput').on('input', function () {

        if (teachersTable) {

            teachersTable
                .search(this.value)
                .draw();

        }

    });


    // ========================================================
    // DELETE CONFIRMATION
    // ========================================================

    $('.delete-btn').on('click', function () {

        const id =
            $(this).data('id');

        const name =
            $(this).data('name') || 'this teacher';


        $('#deleteTeacherName')
            .text(name);


        $('#confirmDelete')
            .attr(
                'href',
                'manage_teachers.php?delete_id=' + encodeURIComponent(id)
            );


        const modalElement =
            document.getElementById('deleteModal');

        const modal =
            bootstrap.Modal.getOrCreateInstance(
                modalElement
            );

        modal.show();

    });


    // ========================================================
    // AUTO-HIDE ALERTS
    // ========================================================

    setTimeout(function () {

        $('.alert').fadeOut(500);

    }, 5000);


    // ========================================================
    // CLOSE MOBILE SIDEBAR WHEN LINK IS CLICKED
    // ========================================================

    $('.sidebar a').on('click', function () {

        if (window.innerWidth < 992) {

            $('.sidebar').removeClass('active');

        }

    });


    // ========================================================
    // ESCAPE KEY
    // ========================================================

    $(document).on('keydown', function (event) {

        if (event.key === 'Escape') {

            $('.sidebar').removeClass('active');

        }

    });

});

</script>

</body>

</html>
