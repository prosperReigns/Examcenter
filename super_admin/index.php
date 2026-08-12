<?php

session_start();

require_once "../db.php";
require_once "../includes/system_guard.php";
require_once "../license/license_guard.php";
require_once "helpers.php";

$license = getLicense();

$daysRemaining = daysRemaining();


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


function licenseStatusClass(string $status): string
{
    return match (strtolower(trim($status))) {
        'active'   => 'success',
        'expired'  => 'danger',
        'inactive' => 'warning',
        'revoked'  => 'dark',
        default    => 'secondary',
    };
}


function licenseStatusIcon(string $status): string
{
    return match (strtolower(trim($status))) {
        'active'   => 'fa-check-circle',
        'expired'  => 'fa-times-circle',
        'inactive' => 'fa-pause-circle',
        'revoked'  => 'fa-ban',
        default    => 'fa-question-circle',
    };
}


function formatLicenseStatus(string $status): string
{
    return ucfirst(strtolower(trim($status)));
}


function formatDateValue($value): string
{
    if (empty($value)) {
        return 'Not available';
    }

    $timestamp = strtotime((string)$value);

    if ($timestamp === false) {
        return e($value);
    }

    return date('d M Y, h:i A', $timestamp);
}


$status = strtolower(trim($license['status'] ?? 'unknown'));

$statusClass = licenseStatusClass($status);
$statusIcon  = licenseStatusIcon($status);

$licenseStatus = formatLicenseStatus($status);

$daysText = $daysRemaining < 0
    ? 'Expired'
    : number_format($daysRemaining) . ' day' . ($daysRemaining == 1 ? '' : 's');

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>License Information | Examcenter</title>

<link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/add_question.css">


<style>

    :root {
        --primary: #4361ee;
        --primary-dark: #3651d4;

        --success: #16a34a;
        --warning: #f59e0b;
        --danger: #dc2626;
        --info: #0891b2;

        --dark: #172033;
        --text: #334155;
        --muted: #64748b;

        --border: #e2e8f0;
        --background: #f5f7fb;

        --card-radius: 14px;
    }


    * {
        box-sizing: border-box;
    }


    body {
        margin: 0;

        background: var(--background);

        color: var(--text);

        font-family:
            Inter,
            -apple-system,
            BlinkMacSystemFont,
            "Segoe UI",
            sans-serif;
    }


    /*
    |--------------------------------------------------------------------------
    | SIDEBAR
    |--------------------------------------------------------------------------
    */

    .sidebar {
        position: fixed;

        top: 0;
        left: 0;

        width: 250px;
        height: 100vh;

        background: #212529;

        z-index: 1050;

        overflow-y: auto;

        transition: transform .25s ease;
    }


    .sidebar-menu {
        padding: 15px 0 25px;
    }


    .sidebar-brand {
        padding: 24px 20px 20px;

        color: #fff;

        border-bottom: 1px solid rgba(255,255,255,.08);

        margin-bottom: 10px;
    }


    .sidebar-brand h4 {
        margin: 0;

        font-size: 20px;

        font-weight: 700;
    }


    .sidebar-brand p {
        margin: 5px 0 0;

        color: rgba(255,255,255,.60);

        font-size: 12px;
    }


    .sidebar-menu a {
        display: flex;

        align-items: center;

        gap: 12px;

        padding: 11px 20px;

        color: rgba(255,255,255,.72);

        text-decoration: none;

        font-size: 14px;

        transition:
            background .18s ease,
            color .18s ease;
    }


    .sidebar-menu a i {
        width: 20px;

        text-align: center;

        font-size: 14px;
    }


    .sidebar-menu a:hover {
        background: rgba(255,255,255,.08);

        color: #fff;
    }


    .sidebar-menu a.active {
        background: rgba(67,97,238,.22);

        color: #fff;

        border-right: 3px solid var(--primary);
    }


    .sidebar-section {
        padding: 15px 20px 7px;

        color: rgba(255,255,255,.35);

        font-size: 10px;

        font-weight: 700;

        text-transform: uppercase;

        letter-spacing: .08em;
    }


    /*
    |--------------------------------------------------------------------------
    | SIDEBAR TOGGLE
    |--------------------------------------------------------------------------
    */

    #sidebarToggle {
        position: fixed;

        top: 18px;
        right: 18px;

        width: 44px;
        height: 44px;

        border: 0;

        border-radius: 10px;

        background: var(--primary);

        color: #fff;

        display: flex;

        align-items: center;
        justify-content: center;

        flex-direction: column;

        gap: 4px;

        z-index: 1100;

        box-shadow:
            0 6px 18px rgba(67,97,238,.30);

        cursor: pointer;

        transition:
            background .2s ease,
            transform .2s ease;
    }


    #sidebarToggle:hover {
        background: var(--primary-dark);

        transform: translateY(-1px);
    }


    #sidebarToggle span {
        display: block;

        width: 18px;
        height: 2px;

        border-radius: 2px;

        background: #fff;
    }


    /*
    |--------------------------------------------------------------------------
    | OVERLAY
    |--------------------------------------------------------------------------
    */

    .sidebar-overlay {
        position: fixed;

        inset: 0;

        background: rgba(15,23,42,.45);

        z-index: 1040;

        opacity: 0;

        visibility: hidden;

        transition:
            opacity .25s ease,
            visibility .25s ease;
    }


    .sidebar-overlay.active {
        opacity: 1;

        visibility: visible;
    }


    /*
    |--------------------------------------------------------------------------
    | MAIN CONTENT
    |--------------------------------------------------------------------------
    */

    .main-content {
        min-height: 100vh;

        margin-left: 250px;

        padding: 28px;

        transition: margin-left .25s ease;
    }


    .page-container {
        max-width: 1450px;

        margin: 0 auto;
    }


    /*
    |--------------------------------------------------------------------------
    | PAGE HEADER
    |--------------------------------------------------------------------------
    */

    .page-header {
        display: flex;

        align-items: center;

        justify-content: space-between;

        gap: 20px;

        margin-bottom: 26px;

        padding-right: 65px;
    }


    .page-heading {
        display: flex;

        align-items: center;

        gap: 14px;
    }


    .page-heading-icon {
        width: 50px;
        height: 50px;

        display: flex;

        align-items: center;
        justify-content: center;

        border-radius: 13px;

        background: rgba(67,97,238,.11);

        color: var(--primary);

        font-size: 21px;
    }


    .page-heading h2 {
        margin: 0;

        color: var(--dark);

        font-size: 25px;

        font-weight: 700;
    }


    .page-heading p {
        margin: 4px 0 0;

        color: var(--muted);

        font-size: 14px;
    }


    /*
    |--------------------------------------------------------------------------
    | LICENSE STATUS HERO
    |--------------------------------------------------------------------------
    */

    .license-hero {
        position: relative;

        overflow: hidden;

        background: #fff;

        border: 1px solid var(--border);

        border-radius: var(--card-radius);

        box-shadow:
            0 4px 16px rgba(15,23,42,.05);

        margin-bottom: 20px;
    }


    .license-hero::before {
        content: "";

        position: absolute;

        left: 0;
        top: 0;
        bottom: 0;

        width: 5px;

        background: var(--primary);
    }


    .hero-body {
        padding: 25px 28px;

        display: flex;

        align-items: center;

        justify-content: space-between;

        gap: 25px;
    }


    .hero-left {
        display: flex;

        align-items: center;

        gap: 17px;
    }


    .license-icon {
        width: 58px;
        height: 58px;

        border-radius: 15px;

        display: flex;

        align-items: center;
        justify-content: center;

        background: rgba(67,97,238,.10);

        color: var(--primary);

        font-size: 23px;

        flex-shrink: 0;
    }


    .hero-title {
        margin: 0;

        color: var(--dark);

        font-size: 20px;

        font-weight: 700;
    }


    .hero-subtitle {
        margin: 4px 0 0;

        color: var(--muted);

        font-size: 13px;
    }


    .status-badge {
        display: inline-flex;

        align-items: center;

        gap: 7px;

        padding: 8px 13px;

        border-radius: 8px;

        font-size: 12px;

        font-weight: 700;
    }


    .status-success {
        background: #ecfdf5;
        color: #15803d;
    }


    .status-danger {
        background: #fef2f2;
        color: #b91c1c;
    }


    .status-warning {
        background: #fffbeb;
        color: #b45309;
    }


    .status-dark {
        background: #f1f5f9;
        color: #334155;
    }


    .status-secondary {
        background: #f1f5f9;
        color: #64748b;
    }


    /*
    |--------------------------------------------------------------------------
    | INFORMATION GRID
    |--------------------------------------------------------------------------
    */

    .info-grid {
        display: grid;

        grid-template-columns: repeat(2, 1fr);

        gap: 20px;

        margin-bottom: 20px;
    }


    .info-card {
        background: #fff;

        border: 1px solid var(--border);

        border-radius: var(--card-radius);

        box-shadow:
            0 4px 14px rgba(15,23,42,.04);

        overflow: hidden;
    }


    .info-card-header {
        display: flex;

        align-items: center;

        gap: 10px;

        padding: 17px 20px;

        border-bottom: 1px solid var(--border);

        color: var(--dark);

        font-size: 14px;

        font-weight: 700;
    }


    .info-card-header i {
        color: var(--primary);

        width: 20px;

        text-align: center;
    }


    .info-card-body {
        padding: 0;
    }


    .info-row {
        display: grid;

        grid-template-columns: 180px 1fr;

        align-items: start;

        gap: 20px;

        padding: 16px 20px;

        border-bottom: 1px solid #eef2f7;
    }


    .info-row:last-child {
        border-bottom: 0;
    }


    .info-label {
        color: var(--muted);

        font-size: 12px;

        font-weight: 700;
    }


    .info-value {
        color: #334155;

        font-size: 13px;

        font-weight: 600;

        word-break: break-word;
    }


    .license-key {
        display: inline-block;

        padding: 8px 10px;

        background: #f8fafc;

        border: 1px solid var(--border);

        border-radius: 7px;

        font-family: monospace;

        font-size: 12px;

        letter-spacing: .02em;

        word-break: break-all;
    }


    .fingerprint {
        display: block;

        padding: 10px;

        background: #f8fafc;

        border: 1px solid var(--border);

        border-radius: 8px;

        font-family: monospace;

        font-size: 11px;

        line-height: 1.6;

        word-break: break-all;

        color: #475569;
    }


    /*
    |--------------------------------------------------------------------------
    | EXPIRY CARD
    |--------------------------------------------------------------------------
    */

    .expiry-card {
        background: #fff;

        border: 1px solid var(--border);

        border-radius: var(--card-radius);

        box-shadow:
            0 4px 14px rgba(15,23,42,.04);

        padding: 22px;

        margin-bottom: 20px;
    }


    .expiry-top {
        display: flex;

        align-items: center;

        justify-content: space-between;

        gap: 15px;

        margin-bottom: 16px;
    }


    .expiry-label {
        color: var(--muted);

        font-size: 12px;

        font-weight: 700;
    }


    .expiry-value {
        color: var(--dark);

        font-size: 23px;

        font-weight: 750;
    }


    .expiry-value.expired {
        color: var(--danger);
    }


    .expiry-value.active {
        color: var(--success);
    }


    .expiry-progress {
        height: 7px;

        background: #eef2f7;

        border-radius: 20px;

        overflow: hidden;
    }


    .expiry-progress-bar {
        height: 100%;

        border-radius: inherit;

        background: var(--primary);
    }


    /*
    |--------------------------------------------------------------------------
    | ACTIONS
    |--------------------------------------------------------------------------
    */

    .actions-card {
        background: #fff;

        border: 1px solid var(--border);

        border-radius: var(--card-radius);

        box-shadow:
            0 4px 14px rgba(15,23,42,.04);

        overflow: hidden;
    }


    .actions-header {
        padding: 17px 20px;

        border-bottom: 1px solid var(--border);

        display: flex;

        align-items: center;

        gap: 10px;

        color: var(--dark);

        font-size: 14px;

        font-weight: 700;
    }


    .actions-header i {
        color: var(--primary);
    }


    .actions-body {
        padding: 20px;

        display: grid;

        grid-template-columns: repeat(3, 1fr);

        gap: 12px;
    }


    .license-action {
        display: flex;

        align-items: center;

        gap: 12px;

        padding: 14px;

        border: 1px solid var(--border);

        border-radius: 10px;

        text-decoration: none;

        transition:
            border-color .2s ease,
            background .2s ease,
            transform .2s ease;
    }


    .license-action:hover {
        transform: translateY(-1px);

        background: #f8faff;

        border-color: #cbd5e1;
    }


    .action-icon {
        width: 40px;
        height: 40px;

        border-radius: 10px;

        display: flex;

        align-items: center;
        justify-content: center;

        flex-shrink: 0;
    }


    .action-download .action-icon {
        background: #ecfdf5;
        color: var(--success);
    }


    .action-renew .action-icon {
        background: #fffbeb;
        color: var(--warning);
    }


    .action-replace .action-icon {
        background: #fef2f2;
        color: var(--danger);
    }


    .action-title {
        display: block;

        color: var(--dark);

        font-size: 13px;

        font-weight: 700;
    }


    .action-description {
        display: block;

        margin-top: 2px;

        color: var(--muted);

        font-size: 11px;
    }


    /*
    |--------------------------------------------------------------------------
    | RESPONSIVE
    |--------------------------------------------------------------------------
    */

    @media (max-width: 1100px) {

        .info-grid {
            grid-template-columns: 1fr;
        }

        .actions-body {
            grid-template-columns: 1fr;
        }

    }


    @media (max-width: 991px) {

        .sidebar {
            transform: translateX(-100%);
        }


        .sidebar.active {
            transform: translateX(0);
        }


        .main-content {
            margin-left: 0;

            padding: 20px;
        }

    }


    @media (max-width: 768px) {

        .main-content {
            padding: 16px;
        }


        .page-header {
            padding-right: 58px;

            align-items: flex-start;

            flex-direction: column;
        }


        .page-heading h2 {
            font-size: 21px;
        }


        .hero-body {
            align-items: flex-start;

            flex-direction: column;

            padding: 20px;
        }


        .hero-left {
            align-items: flex-start;
        }


        .info-row {
            grid-template-columns: 1fr;

            gap: 5px;

            padding: 14px 16px;
        }


        .expiry-card {
            padding: 18px;
        }

    }


    @media (max-width: 480px) {

        .page-heading-icon {
            width: 44px;
            height: 44px;
        }


        .page-heading h2 {
            font-size: 19px;
        }


        .license-icon {
            width: 50px;
            height: 50px;
        }


        .hero-title {
            font-size: 17px;
        }


        #sidebarToggle {
            top: 14px;
            right: 14px;
        }

    }

</style>

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================== -->

<aside id="sidebar" class="sidebar">

<div class="sidebar-brand">

    <h4>
        <i class="fas fa-graduation-cap me-2"></i>
        Examcenter
    </h4>

    <p>
        Administrator Panel
    </p>

</div>


<nav class="sidebar-menu">

    <a href="dashboard.php">
        <i class="fas fa-home"></i>
        <span>Dashboard</span>
    </a>


    <a href="manage_admins.php">
        <i class="fas fa-user-plus"></i>
        <span>Manage Admins</span>
    </a>


    <div class="sidebar-section">
        Management
    </div>


    <a href="../admin/manage_classes.php">
        <i class="fas fa-school"></i>
        <span>Manage Classes</span>
    </a>


    <a href="../admin/manage_session.php">
        <i class="fas fa-calendar-alt"></i>
        <span>Manage Session</span>
    </a>


    <div class="sidebar-section">
        System
    </div>


    <a href="index.php" class="active">
        <i class="fas fa-key"></i>
        <span>License</span>
    </a>

    <a href="audit_logs.php">
        <i class="fas fa-shield-alt"></i>
        Audit Logs
    </a>


    <a href="backup_list.php">
        <i class="fas fa-database"></i>
        Backup
    </a>


    <a href="settings.php">
        <i class="fas fa-cog"></i>
        <span>Settings</span>
    </a>


    <a href="../teacher/logout.php">
        <i class="fas fa-sign-out-alt"></i>
        <span>Logout</span>
    </a>

</nav>

</aside>

<!-- =========================================================
     SIDEBAR OVERLAY
========================================================== -->

<div
    id="sidebarOverlay"
    class="sidebar-overlay"
></div>

<!-- =========================================================
     SIDEBAR TOGGLE
========================================================== -->

<button
type="button"
id="sidebarToggle"
aria-label="Toggle navigation"
aria-expanded="false"

>

<span></span>
<span></span>
<span></span>


</button>

<!-- =========================================================
     MAIN CONTENT
========================================================== -->

<main class="main-content">

<div class="page-container">

<!-- =====================================================
     PAGE HEADER
====================================================== -->

<div class="page-header">

    <div class="page-heading">

        <div class="page-heading-icon">
            <i class="fas fa-key"></i>
        </div>

        <div>

            <h2>
                License Information
            </h2>

            <p>
                View your Examcenter license status and activation details.
            </p>

        </div>

    </div>

</div>


<!-- =====================================================
     LICENSE STATUS HERO
====================================================== -->

<section class="license-hero">

    <div class="hero-body">

        <div class="hero-left">

            <div class="license-icon">
                <i class="fas <?= e($statusIcon) ?>"></i>
            </div>

            <div>

                <h3 class="hero-title">
                    <?= e($license['school_name'] ?? 'Examcenter License') ?>
                </h3>

                <p class="hero-subtitle">
                    Current license status and activation information
                </p>

            </div>

        </div>


        <span class="status-badge status-<?= e($statusClass) ?>">

            <i class="fas <?= e($statusIcon) ?>"></i>

            <?= e($licenseStatus) ?>

        </span>

    </div>

</section>


<!-- =====================================================
     LICENSE INFORMATION
====================================================== -->

<div class="info-grid">


    <!-- License Details -->

    <section class="info-card">

        <div class="info-card-header">

            <i class="fas fa-id-card"></i>

            License Details

        </div>


        <div class="info-card-body">


            <div class="info-row">

                <div class="info-label">
                    School
                </div>

                <div class="info-value">
                    <?= e($license['school_name'] ?? 'Not available') ?>
                </div>

            </div>


            <div class="info-row">

                <div class="info-label">
                    License Key
                </div>

                <div class="info-value">

                    <span class="license-key">
                        <?= e($license['license_key'] ?? 'Not available') ?>
                    </span>

                </div>

            </div>


            <div class="info-row">

                <div class="info-label">
                    Activation Date
                </div>

                <div class="info-value">
                    <?= formatDateValue($license['activation_date'] ?? null) ?>
                </div>

            </div>


            <div class="info-row">

                <div class="info-label">
                    Expiry Date
                </div>

                <div class="info-value">
                    <?= formatDateValue($license['expiry_date'] ?? null) ?>
                </div>

            </div>


            <div class="info-row">

                <div class="info-label">
                    Last Verified
                </div>

                <div class="info-value">
                    <?= formatDateValue($license['last_verified'] ?? null) ?>
                </div>

            </div>


        </div>

    </section>


    <!-- Machine Information -->

    <section class="info-card">

        <div class="info-card-header">

            <i class="fas fa-desktop"></i>

            Machine Information

        </div>


        <div class="info-card-body">


            <div class="info-row">

                <div class="info-label">
                    Machine Fingerprint
                </div>

                <div class="info-value">

                    <span class="fingerprint">
                        <?= e($license['machine_fingerprint'] ?? 'Not available') ?>
                    </span>

                </div>

            </div>


            <div class="info-row">

                <div class="info-label">
                    License Status
                </div>

                <div class="info-value">

                    <span class="status-badge status-<?= e($statusClass) ?>">

                        <i class="fas <?= e($statusIcon) ?>"></i>

                        <?= e($licenseStatus) ?>

                    </span>

                </div>

            </div>


            <div class="info-row">

                <div class="info-label">
                    Days Remaining
                </div>

                <div class="info-value">

                    <span class="<?= $daysRemaining < 0 ? 'text-danger' : 'text-success' ?>">

                        <i class="fas <?= $daysRemaining < 0 ? 'fa-exclamation-circle' : 'fa-clock' ?> me-1"></i>

                        <?= e($daysText) ?>

                    </span>

                </div>

            </div>


        </div>

    </section>


</div>


<!-- =====================================================
     EXPIRY SUMMARY
====================================================== -->

<section class="expiry-card">

    <div class="expiry-top">

        <div>

            <div class="expiry-label">
                LICENSE VALIDITY
            </div>

            <div class="expiry-value <?= $daysRemaining < 0 ? 'expired' : 'active' ?>">

                <?= e($daysText) ?>

            </div>

        </div>


        <div class="text-end">

            <div class="expiry-label">
                EXPIRES
            </div>

            <div class="fw-semibold text-dark small">

                <?= formatDateValue($license['expiry_date'] ?? null) ?>

            </div>

        </div>

    </div>


    <div class="expiry-progress">

        <?php

        /*
         * This is intentionally a visual status indicator.
         * The actual license validity remains controlled by
         * the existing license guard.
         */

        if ($daysRemaining < 0) {
            $progress = 0;
        } elseif ($daysRemaining >= 365) {
            $progress = 100;
        } else {
            $progress = max(5, min(100, ($daysRemaining / 365) * 100));
        }

        ?>

        <div
            class="expiry-progress-bar"
            style="width: <?= (float)$progress ?>%;"
        ></div>

    </div>

</section>


<!-- =====================================================
     LICENSE ACTIONS
====================================================== -->

<section class="actions-card">

    <div class="actions-header">

        <i class="fas fa-tools"></i>

        License Actions

    </div>


    <div class="actions-body">


        <a
            href="download.php"
            class="license-action action-download"
        >

            <div class="action-icon">

                <i class="fas fa-download"></i>

            </div>

            <div>

                <span class="action-title">
                    Download Fingerprint
                </span>

                <span class="action-description">
                    Download this machine's fingerprint.
                </span>

            </div>

        </a>


        <a
            href="renew.php"
            class="license-action action-renew"
        >

            <div class="action-icon">

                <i class="fas fa-sync-alt"></i>

            </div>

            <div>

                <span class="action-title">
                    Renew License
                </span>

                <span class="action-description">
                    Renew or extend your license.
                </span>

            </div>

        </a>


        <a
            href="replace.php"
            class="license-action action-replace"
        >

            <div class="action-icon">

                <i class="fas fa-exchange-alt"></i>

            </div>

            <div>

                <span class="action-title">
                    Replace License
                </span>

                <span class="action-description">
                    Replace the license for this machine.
                </span>

            </div>

        </a>


    </div>

</section>
" "

</div>

</main>

<!-- =========================================================
     JAVASCRIPT
========================================================== -->

<script src="../js/bootstrap.bundle.min.js"></script>

<script>

document.addEventListener("DOMContentLoaded", function () {

    const sidebar = document.getElementById("sidebar");

    const toggle = document.getElementById("sidebarToggle");

    const overlay = document.getElementById("sidebarOverlay");


    if (!sidebar || !toggle || !overlay) {
        return;
    }


    function openSidebar() {

        sidebar.classList.add("active");

        overlay.classList.add("active");

        toggle.setAttribute(
            "aria-expanded",
            "true"
        );

    }


    function closeSidebar() {

        sidebar.classList.remove("active");

        overlay.classList.remove("active");

        toggle.setAttribute(
            "aria-expanded",
            "false"
        );

    }


    function toggleSidebar() {

        if (sidebar.classList.contains("active")) {

            closeSidebar();

        } else {

            openSidebar();

        }

    }


    toggle.addEventListener(
        "click",
        toggleSidebar
    );


    overlay.addEventListener(
        "click",
        closeSidebar
    );


    sidebar.querySelectorAll("a").forEach(function (link) {

        link.addEventListener("click", function () {

            if (window.innerWidth <= 991) {
                closeSidebar();
            }

        });

    });


    document.addEventListener(
        "keydown",
        function (event) {

            if (event.key === "Escape") {
                closeSidebar();
            }

        }
    );


    window.addEventListener(
        "resize",
        function () {

            if (window.innerWidth > 991) {
                closeSidebar();
            }

        }
    );

});

</script>

</body>

</html>
