<?php
declare(strict_types=1);

$currentPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$sidebarUsername = $admin['username'] ?? $user['username'] ?? $_SESSION['username'] ?? 'Admin';

$adminNav = [
    ['dashboard.php', 'fa-tachometer-alt', 'Dashboard'],
    ['add_teacher.php', 'fa-user-plus', 'Add Teachers'],
    ['view_questions.php', 'fa-list', 'View Questions'],
    ['view_results.php', 'fa-chart-bar', 'Exam Results'],
    ['manage_classes.php', 'fa-users', 'Manage Classes'],
    ['manage_session.php', 'fa-calendar-alt', 'Manage Session'],
    ['manage_subject.php', 'fa-book', 'Manage Subjects'],
    ['manage_students.php', 'fa-user-graduate', 'Manage Students'],
    ['manage_teachers.php', 'fa-chalkboard-teacher', 'Manage Teachers'],
    ['manage_test.php', 'fa-file-alt', 'Manage Tests'],
    ['exam_schedule.php', 'fa-calendar-check', 'Timetable'],
    ['../backup/backup_list.php', 'fa-database', 'Backups'],
    ['audit_logs.php', 'fa-history', 'Audit Logs'],
    ['../license/index.php', 'fa-key', 'License'],
    ['pro_suite.php', 'fa-layer-group', 'Pro Suite'],
    ['settings.php', 'fa-cog', 'Settings'],
];
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <h3><i class="fas fa-graduation-cap me-2"></i>Examcenter</h3>
        <div class="admin-info">
            <small>Welcome back,</small>
            <h6><?= htmlspecialchars((string)$sidebarUsername, ENT_QUOTES, 'UTF-8') ?></h6>
        </div>
    </div>

    <nav class="sidebar-menu" aria-label="Admin navigation">
        <?php foreach ($adminNav as [$href, $icon, $label]): ?>
            <?php $target = basename(parse_url($href, PHP_URL_PATH) ?: ''); ?>
            <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="<?= $currentPage === $target ? 'active' : '' ?>">
                <i class="fas <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i>
                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>

        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </nav>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
