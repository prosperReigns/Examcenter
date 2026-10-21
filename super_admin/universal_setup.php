<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/universal_architecture.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?error=Not%20logged%20in');
    exit;
}

$conn = Database::connection();
$userId = (int) $_SESSION['user_id'];

$stmt = $conn->prepare('SELECT role FROM super_admins WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$currentAdmin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$currentAdmin || strtolower((string) $currentAdmin['role']) !== 'super_admin') {
    session_destroy();
    header('Location: ../login.php?error=Unauthorized');
    exit;
}

if (!examcenterUniversalTableExists($conn, 'institutions')) {
    http_response_code(500);
    exit('Universal Architecture tables are missing. Apply the Universal Architecture schema before setup.');
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function tableColumns(mysqli $conn, string $table): array
{
    $columns = [];
    $safeTable = str_replace('`', '', $table);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");
    if ($result) {
        while ($row = $result->fetch_assoc()) $columns[$row['Field']] = $row;
        $result->free();
    }
    return $columns;
}
function postText(string $key, int $max = 190): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    return mb_substr($value, 0, $max);
}
function insertDynamic(mysqli $conn, string $table, array $values): int
{
    $columns = tableColumns($conn, $table);
    $values = array_intersect_key($values, $columns);
    if (!$values) throw new RuntimeException("Cannot create a record: no compatible columns were found in {$table}.");
    $names = array_keys($values);
    $quoted = array_map(static fn($n) => '`' . str_replace('`', '', $n) . '`', $names);
    $marks = implode(',', array_fill(0, count($names), '?'));
    $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES (' . $marks . ')';
    $stmt = $conn->prepare($sql);
    $types = '';
    $params = [];
    foreach ($values as $value) {
        $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
        $params[] = $value;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();
    return $id;
}
function updateSetupComplete(mysqli $conn, int $userId): void
{
    if (!examcenterUniversalTableExists($conn, 'system_settings')) return;
    $cols = tableColumns($conn, 'system_settings');
    $set = [];
    if (isset($cols['setup_completed'])) $set[] = 'setup_completed=1';
    if (isset($cols['setup_completed_at'])) $set[] = 'setup_completed_at=NOW()';
    if (isset($cols['setup_by'])) $set[] = 'setup_by=' . $userId;
    if (!$set) return;
    $result = $conn->query('SELECT id FROM system_settings ORDER BY id LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;
    if ($row) {
        $conn->query('UPDATE system_settings SET ' . implode(',', $set) . ' WHERE id=' . (int) $row['id']);
    } else {
        $fields = [];
        $values = [];
        if (isset($cols['setup_completed'])) {
            $fields[] = 'setup_completed';
            $values[] = '1';
        }
        if (isset($cols['setup_completed_at'])) {
            $fields[] = 'setup_completed_at';
            $values[] = 'NOW()';
        }
        if (isset($cols['setup_by'])) {
            $fields[] = 'setup_by';
            $values[] = (string) $userId;
        }
        if ($fields) $conn->query('INSERT INTO system_settings (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
    }
}
function findOrCreateAdmin(mysqli $conn, array $data): int
{
    $columns = tableColumns($conn, 'super_admins');
    $emailColumn = isset($columns['email']) ? 'email' : (isset($columns['email_address']) ? 'email_address' : null);
    $usernameColumn = isset($columns['username']) ? 'username' : (isset($columns['user_name']) ? 'user_name' : null);
    if (!$emailColumn && !$usernameColumn) {
        throw new RuntimeException('The super_admins table needs an email or username column to create the setup administrator.');
    }
    $identityColumn = $emailColumn ?: $usernameColumn;
    $identity = $emailColumn ? $data['email'] : $data['username'];
    $stmt = $conn->prepare("SELECT id FROM super_admins WHERE `{$identityColumn}` = ? LIMIT 1");
    $stmt->bind_param('s', $identity);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($found) throw new RuntimeException('That administrator email/username already exists. Use a different one.');

    $values = [];
    foreach (
        [
            'name' => $data['name'],
            'full_name' => $data['name'],
            'display_name' => $data['name'],
            'username' => $data['username'],
            'user_name' => $data['username'],
            'email' => $data['email'],
            'email_address' => $data['email'],
            'password' => $data['password_hash'],
            'password_hash' => $data['password_hash'],
            'role' => 'super_admin',
            'status' => 'active',
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ] as $column => $value
    ) {
        if (isset($columns[$column])) $values[$column] = $value;
    }
    if (!isset($values['role'])) throw new RuntimeException('The super_admins table has no role column; cannot safely assign the administrator role.');
    return insertDynamic($conn, 'super_admins', $values);
}
function setupInstitutionCategory(array $type): string
{
    $descriptor = strtolower(trim(($type['code'] ?? '') . ' ' . ($type['name'] ?? '')));
    if (preg_match('/primary|secondary|school|basic|k-12|high school/', $descriptor)) return 'school';
    if (preg_match('/tertiary|universit|college|polytechnic|higher|institute of technology/', $descriptor)) return 'higher_education';
    if (preg_match('/vocational|training|professional|trade|certification|apprentice/', $descriptor)) return 'vocational';
    if (preg_match('/corporate|company|business|enterprise|recruit|workplace|organisation|organization/', $descriptor)) return 'business';
    return 'other';
}
function setupInstitutionTypeHelp(array $type): string
{
    $code = strtolower((string) ($type['code'] ?? ''));
    $labels = [
        'primary_school' => 'Use this for nursery, primary, elementary, or basic education schools.',
        'secondary_school' => 'Use this for junior secondary, senior secondary, high school, or similar schools.',
        'tertiary_institution' => 'Use this for universities, polytechnics, colleges, and higher education institutions.',
        'vocational_institution' => 'Use this for training centres, trade schools, certification providers, and skills programmes.',
        'corporate' => 'Use this for companies, organisations, recruitment assessments, or staff training.',
        'assessment_centre' => 'Use this for independent testing or examination centres.',
        'other' => 'Use this only when none of the listed institution types fits your setup.'
    ];
    return $labels[$code] ?? 'Choose the closest match; you can refine the structure after setup.';
}
function setupUnitTypeCategory(array $type): string
{
    $code = strtolower((string) ($type['code'] ?? ''));
    if (in_array($code, ['custom'], true)) return 'all';
    if (in_array($code, ['grade', 'class'], true)) return 'school';
    if (in_array($code, ['faculty'], true)) return 'higher_education';
    if (in_array($code, ['division', 'team'], true)) return 'business';
    if (in_array($code, ['cohort'], true)) return 'vocational,business';
    if (in_array($code, ['campus'], true)) return 'higher_education,vocational,business';
    if (in_array($code, ['department'], true)) return 'school,higher_education,vocational,business';
    if (in_array($code, ['school'], true)) return 'school,higher_education,vocational';
    if (in_array($code, ['programme'], true)) return 'higher_education,vocational';
    return 'all';
}
function setupUnitTypeHelp(array $type): string
{
    $code = strtolower((string) ($type['code'] ?? ''));
    $labels = [
        'campus' => 'A physical campus, branch, or site.',
        'faculty' => 'A faculty used mainly by tertiary institutions, such as Faculty of Science.',
        'department' => 'A department, subject department, business department, or training department.',
        'school' => 'A school, college, or major section within the institution.',
        'grade' => 'A school grade or year group, such as Primary 6, JSS 2, or Year 8.',
        'class' => 'A class or stream, such as SS 1A, Grade 5 Blue, or Form 3 Science.',
        'cohort' => 'A cohort or intake group, often used for training or admissions.',
        'programme' => 'A programme, pathway, qualification, or course of study.',
        'division' => 'A business division or major operational unit.',
        'team' => 'A team used for workplace, recruitment, or staff assessments.',
        'custom' => 'Use this when none of the listed unit types describes your first unit.'
    ];
    return $labels[$code] ?? 'Choose the closest unit type for the first structure you want to create.';
}

$errors = [];
$institutionTypes = [];
$unitTypes = [];
$typeResult = $conn->query("SELECT id, code, name FROM institution_types WHERE is_active=1 ORDER BY name");
if ($typeResult) $institutionTypes = $typeResult->fetch_all(MYSQLI_ASSOC);
$unitResult = $conn->query("SELECT id, code, name FROM organizational_unit_types WHERE is_active=1 ORDER BY name");
if ($unitResult) $unitTypes = $unitResult->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_setup') {
    try {
        if (!hash_equals((string) ($_SESSION['setup_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your setup session expired. Refresh the page and try again.');
        }
        $typeId = filter_var($_POST['institution_type_id'] ?? null, FILTER_VALIDATE_INT);
        $name = postText('institution_name');
        $code = strtoupper(postText('institution_code', 60));
        $timezone = postText('timezone', 80) ?: 'Africa/Lagos';
        $country = strtoupper(postText('country_code', 2));
        $category = strtolower(postText('institution_category', 40));
        $unitTypeId = (int) ($_POST['unit_type_id'] ?? 0);
        $unitName = postText('unit_name');
        $unitCode = strtoupper(postText('unit_code', 60));
        $programmeName = postText('programme_name');
        $programmeCode = strtoupper(postText('programme_code', 60));
        $levelName = postText('level_name');
        $levelCode = strtoupper(postText('level_code', 60));
        $periodName = postText('period_name');
        $periodCode = strtoupper(postText('period_code', 60));
        $courseName = postText('course_name');
        $courseCode = strtoupper(postText('course_code', 60));
        $adminName = postText('admin_name');
        $adminUsername = postText('admin_username', 80);
        $adminEmail = strtolower(postText('admin_email', 190));
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        $confirmPassword = (string) ($_POST['admin_password_confirm'] ?? '');

        if (!$typeId || $name === '') throw new RuntimeException('Select an institution type and enter the institution name.');
        if (!in_array($category, ['school', 'higher_education', 'vocational', 'business', 'other'], true)) throw new RuntimeException('Choose a valid institution category.');
        if ($adminName === '' || $adminUsername === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter the administrator name, username, and a valid email address.');
        if (strlen($adminPassword) < 10) throw new RuntimeException('The administrator password must contain at least 10 characters.');
        if ($adminPassword !== $confirmPassword) throw new RuntimeException('The administrator passwords do not match.');
        if (!in_array($timezone, timezone_identifiers_list(), true)) throw new RuntimeException('Enter a valid timezone, for example Africa/Lagos.');
        if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) throw new RuntimeException('Country code must be two letters, for example NG.');

        $typeStmt = $conn->prepare('SELECT id FROM institution_types WHERE id=? AND is_active=1 LIMIT 1');
        $typeStmt->bind_param('i', $typeId);
        $typeStmt->execute();
        $validType = $typeStmt->get_result()->fetch_assoc();
        $typeStmt->close();
        if (!$validType) throw new RuntimeException('The selected institution type is not available.');

        $conn->begin_transaction();

        $existingStmt = $conn->prepare("SELECT id FROM institutions WHERE name=? OR (code=? AND ? <> '') ORDER BY id LIMIT 1");
        $existingStmt->bind_param('sss', $name, $code, $code);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
        if ($existing) throw new RuntimeException('An institution with that name or code already exists. Choose a unique name/code or review the existing setup.');

        $institutionValues = [
            'institution_type_id' => (int)$typeId,
            'name' => $name,
            'code' => $code !== '' ? $code : null,
            'timezone' => $timezone,
            'country_code' => $country !== '' ? $country : null,
            'is_active' => 1
        ];
        $institutionId = insertDynamic($conn, 'institutions', $institutionValues);

        if ($unitName !== '') {
            if ($unitTypeId <= 0) throw new RuntimeException('Select a unit type when entering a first organisational unit.');
            $unitId = insertDynamic($conn, 'organizational_units', [
                'institution_id' => $institutionId,
                'unit_type_id' => $unitTypeId,
                'name' => $unitName,
                'code' => $unitCode !== '' ? $unitCode : null,
                'is_active' => 1,
                'sort_order' => 0
            ]);
        } else $unitId = null;

        $programmeId = null;
        if ($programmeName !== '') {
            $programmeId = insertDynamic($conn, 'programmes', [
                'institution_id' => $institutionId,
                'code' => $programmeCode !== '' ? $programmeCode : 'PROGRAMME-' . strtoupper(bin2hex(random_bytes(3))),
                'name' => $programmeName,
                'is_active' => 1
            ]);
        }
        if ($levelName !== '') {
            if (!$programmeId) throw new RuntimeException('Enter a programme before adding a level.');
            insertDynamic($conn, 'programme_levels', [
                'programme_id' => $programmeId,
                'organizational_unit_id' => $unitId,
                'code' => $levelCode !== '' ? $levelCode : 'LEVEL-' . strtoupper(bin2hex(random_bytes(3))),
                'name' => $levelName,
                'is_active' => 1
            ]);
        }
        if ($periodName !== '') {
            insertDynamic($conn, 'academic_periods', [
                'institution_id' => $institutionId,
                'code' => $periodCode !== '' ? $periodCode : 'PERIOD-' . date('YmdHis'),
                'name' => $periodName,
                'period_type' => 'academic_year',
                'status' => 'active',
                'is_active' => 1
            ]);
        }
        if ($courseName !== '') {
            insertDynamic($conn, 'courses', [
                'institution_id' => $institutionId,
                'programme_id' => $programmeId,
                'code' => $courseCode !== '' ? $courseCode : 'COURSE-' . date('YmdHis'),
                'name' => $courseName,
                'course_type' => ($category === 'school' ? 'subject' : 'course'),
                'is_active' => 1
            ]);
        }

        $newAdminId = findOrCreateAdmin($conn, [
            'name' => $adminName,
            'username' => $adminUsername,
            'email' => $adminEmail,
            'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT)
        ]);
        updateSetupComplete($conn, $newAdminId);
        $conn->commit();

        session_regenerate_id(true);
        $_SESSION['user_id'] = $newAdminId;
        header('Location: dashboard.php?setup=complete');
        exit;
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
        $errors[] = $e instanceof mysqli_sql_exception
            ? 'Setup could not be saved. Check that the Universal Architecture tables and required columns are present. Details: ' . $e->getMessage()
            : $e->getMessage();
    }
}

if (!isset($_SESSION['setup_csrf'])) $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
$csrf = (string) $_SESSION['setup_csrf'];
$hasExistingInstitution = false;
$existingResult = $conn->query("SELECT id, name FROM institutions WHERE is_active=1 ORDER BY id LIMIT 1");
if ($existingResult && $existingResult->num_rows > 0) $hasExistingInstitution = true;
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System setup | Examcenter</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <style>
        :root {
            --ink: #17243b;
            --muted: #6d7890;
            --line: #e3e8f1;
            --primary: #3157d5;
            --wash: #f4f7fc;
            --green: #14866d
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            background: var(--wash);
            color: var(--ink);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif
        }

        .setup-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr)
        }

        .sidebar {
            background: #14213b;
            color: #fff;
            padding: 36px 26px;
            display: flex;
            flex-direction: column;
            gap: 30px
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 750;
            font-size: 21px;
            letter-spacing: -.4px
        }

        .brand-mark {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(145deg, #6887ff, #3157d5);
            display: grid;
            place-items: center;
            font-size: 21px
        }

        .sidebar-kicker {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #aab7d1;
            font-weight: 700;
            margin: 10px 0 0
        }

        .step-nav {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 8px
        }

        .step-nav li {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 10px;
            border-radius: 12px;
            color: #b9c4da;
            font-size: 13px
        }

        .step-nav li.active {
            background: #263858;
            color: #fff
        }

        .step-nav li.done {
            color: #d2f8eb
        }

        .step-number {
            width: 29px;
            height: 29px;
            border: 1px solid #5b6a86;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 12px;
            flex: none
        }

        .active .step-number {
            background: #5275f0;
            border-color: #5275f0;
            color: #fff
        }

        .done .step-number {
            background: #14866d;
            border-color: #14866d;
            color: #fff
        }

        .sidebar-note {
            margin-top: auto;
            color: #aab7d1;
            font-size: 12px;
            line-height: 1.7;
            border-top: 1px solid #34435e;
            padding-top: 20px
        }

        .main {
            padding: 34px clamp(20px, 5vw, 72px) 50px;
            min-width: 0
        }

        .topline {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 34px;
            color: var(--muted);
            font-size: 13px
        }

        .secure {
            display: flex;
            align-items: center;
            gap: 7px
        }

        .content {
            max-width: 850px;
            margin: 0 auto
        }

        .eyebrow {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--primary);
            font-weight: 800;
            margin-bottom: 10px
        }

        .page-title {
            font-size: clamp(27px, 4vw, 36px);
            font-weight: 760;
            letter-spacing: -1.2px;
            margin: 0 0 10px
        }

        .lead {
            color: var(--muted);
            line-height: 1.7;
            max-width: 650px;
            margin-bottom: 28px
        }

        .progress-wrap {
            margin: 0 0 26px
        }

        .progress-meta {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 9px
        }

        .progress {
            height: 6px;
            background: #e3e8f1;
            border-radius: 20px;
            overflow: hidden
        }

        .progress-bar {
            background: var(--primary);
            transition: width .2s
        }

        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 12px 40px rgba(25, 43, 80, .055);
            overflow: hidden
        }

        .panel-head {
            padding: 25px 28px 20px;
            border-bottom: 1px solid var(--line)
        }

        .panel-head h2 {
            font-size: 19px;
            font-weight: 750;
            margin: 0 0 7px
        }

        .panel-head p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6
        }

        .panel-body {
            padding: 26px 28px
        }

        .panel-foot {
            padding: 18px 28px;
            border-top: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            background: #fcfdff
        }

        .form-label {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px
        }

        .form-control,
        .form-select {
            border-color: #d9e0ec;
            border-radius: 10px;
            min-height: 47px;
            font-size: 14px;
            padding: 11px 13px
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #7891f4;
            box-shadow: 0 0 0 3px #3157d51c
        }

        .form-text {
            font-size: 12px;
            color: var(--muted);
            margin-top: 7px
        }

        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px
        }

        .field.full {
            grid-column: 1/-1
        }

        .step-pane {
            display: none
        }

        .step-pane.active {
            display: block;
            animation: fadein .2s ease
        }

        @keyframes fadein {
            from {
                opacity: .4;
                transform: translateY(4px)
            }

            to {
                opacity: 1;
                transform: none
            }
        }

        .type-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px
        }

        .type-card {
            border: 1px solid var(--line);
            border-radius: 13px;
            padding: 17px;
            cursor: pointer;
            display: flex;
            gap: 13px;
            align-items: flex-start;
            transition: .15s;
            background: #fff
        }

        .type-card:hover {
            border-color: #a8b8fa
        }

        .type-card.selected {
            border-color: var(--primary);
            background: #f5f7ff;
            box-shadow: 0 0 0 2px #3157d51c
        }

        .type-card input {
            margin-top: 4px;
            accent-color: var(--primary)
        }

        .type-card strong {
            display: block;
            font-size: 14px;
            margin-bottom: 5px
        }

        .type-card small {
            display: block;
            color: var(--muted);
            line-height: 1.5;
            font-size: 12px
        }

        .callout {
            padding: 13px 15px;
            background: #f1f5ff;
            border: 1px solid #dce5ff;
            border-radius: 12px;
            color: #42557e;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 22px
        }

        .field-guide {
            display: flex;
            gap: 13px;
            align-items: flex-start;
            padding: 14px 15px;
            background: #f8fbff;
            border: 1px solid #dce5ff;
            border-left: 4px solid var(--primary);
            border-radius: 12px;
            color: #42557e;
            margin: 0 0 22px
        }

        .field-guide i {
            color: var(--primary);
            font-size: 16px;
            margin-top: 3px
        }

        .field-guide strong {
            display: block;
            color: var(--ink);
            font-size: 13px;
            margin-bottom: 4px
        }

        .field-guide span {
            display: block;
            font-size: 12px;
            line-height: 1.6
        }

        .review-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px
        }

        .review-item {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px
        }

        .review-item span {
            display: block;
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .7px;
            margin-bottom: 6px
        }

        .review-item strong {
            font-size: 14px;
            overflow-wrap: anywhere
        }

        .btn {
            border-radius: 10px;
            padding: 11px 18px;
            font-size: 13px;
            font-weight: 700
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary)
        }

        .btn-primary:hover {
            background: #2446bb
        }

        .btn-outline-secondary {
            border-color: #d9e0ec;
            color: #3f4c64
        }

        .muted-small {
            color: var(--muted);
            font-size: 12px
        }

        .alert {
            border-radius: 12px;
            font-size: 13px
        }

        .password-wrap {
            position: relative
        }

        .password-wrap .form-control {
            padding-right: 78px
        }

        .toggle-password {
            position: absolute;
            right: 9px;
            top: 8px;
            border: 0;
            background: transparent;
            color: var(--primary);
            font-size: 12px;
            font-weight: 700;
            padding: 6px
        }

        @media(max-width:850px) {
            .setup-shell {
                grid-template-columns: 1fr
            }

            .sidebar {
                padding: 20px 22px;
                gap: 16px
            }

            .sidebar-kicker,
            .sidebar-note {
                display: none
            }

            .step-nav {
                display: flex;
                overflow: auto;
                gap: 6px
            }

            .step-nav li {
                min-width: max-content;
                padding: 8px
            }

            .step-nav li span:last-child {
                display: none
            }

            .main {
                padding: 24px 16px 35px
            }

            .topline {
                margin-bottom: 24px
            }

            .panel-head,
            .panel-body {
                padding: 21px
            }

            .panel-foot {
                padding: 16px 21px
            }
        }

        @media(max-width:520px) {

            .field-grid,
            .type-grid,
            .review-grid {
                grid-template-columns: 1fr
            }

            .field.full {
                grid-column: auto
            }

            .panel-foot {
                align-items: stretch
            }

            .panel-foot .btn {
                flex: 1
            }

            .page-title {
                font-size: 28px
            }
        }
    </style>
</head>

<body>
    <div class="setup-shell">
        <aside class="sidebar">
            <div class="brand"><span class="brand-mark">E</span><span>Examcenter</span></div>
            <div>
                <div class="sidebar-kicker">Setup wizard</div>
                <ul class="step-nav" id="stepNav">
                    <li class="active" data-nav="0"><span class="step-number">1</span><span>Institution</span></li>
                    <li data-nav="1"><span class="step-number">2</span><span>Structure</span></li>
                    <li data-nav="2"><span class="step-number">3</span><span>Academic setup</span></li>
                    <li data-nav="3"><span class="step-number">4</span><span>Courses</span></li>
                    <li data-nav="4"><span class="step-number">5</span><span>Administrator</span></li>
                    <li data-nav="5"><span class="step-number">6</span><span>Review</span></li>
                </ul>
            </div>
            <div class="sidebar-note">Configure your organisation once. Examcenter adapts the setup to the type of institution you select.<br><br> <span>●</span> Your information is saved to your local system.</div>
        </aside>
        <main class="main">
            <div class="topline"><span>Initial system configuration</span><span class="secure"><i class="fa fa-shield-alt"></i> Secure setup</span></div>
            <div class="content">
                <div class="eyebrow">Welcome to Examcenter</div>
                <h1 class="page-title">Let's set up your organisation</h1>
                <p class="lead">A few guided steps will prepare your workspace. The questions adapt to your institution, so you only see settings that are relevant to your organisation.</p>
                <?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endforeach; ?>
                <?php if ($hasExistingInstitution): ?><div class="alert alert-warning" role="alert">An active institution already exists. This wizard creates a new institution and administrator; make sure that is what you intend before continuing.</div><?php endif; ?>
                <div class="progress-wrap">
                    <div class="progress-meta"><span id="stepCaption">Step 1 of 6</span><span id="stepPercent">17% complete</span></div>
                    <div class="progress">
                        <div class="progress-bar" id="progressBar" style="width:16.67%"></div>
                    </div>
                </div>
                <form method="post" id="setupForm" class="panel" novalidate>
                    <input type="hidden" name="action" value="complete_setup">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="institution_category" id="institutionCategory" value="school">
                    <section class="step-pane active" data-step="0">
                        <div class="panel-head">
                            <h2>Tell us about your institution</h2>
                            <p>Choose the type that best describes your organisation. This determines the next steps.</p>
                        </div>
                        <div class="panel-body">
                            <div class="mb-4">
                                <label class="form-label">Organisation category <span class="text-danger">*</span></label>
                                <div class="type-grid">
                                    <label class="type-card selected"><input type="radio" name="category_choice" value="school" checked><span><strong>School</strong><small>Primary, secondary, basic or K–12 education</small></span></label>
                                    <label class="type-card"><input type="radio" name="category_choice" value="higher_education"><span><strong>Higher education</strong><small>University, college, polytechnic or tertiary institution</small></span></label>
                                    <label class="type-card"><input type="radio" name="category_choice" value="vocational"><span><strong>Vocational / professional</strong><small>Training centres, trade schools and certification providers</small></span></label>
                                    <label class="type-card"><input type="radio" name="category_choice" value="business"><span><strong>Business / corporate</strong><small>Recruitment, staff assessments and workplace training</small></span></label>
                                    <label class="type-card"><input type="radio" name="category_choice" value="other"><span><strong>Other / assessment centre</strong><small>Independent examination centres or custom structures</small></span></label>
                                </div>
                                <div class="form-text">Start here so the rest of setup only shows choices that fit your organisation.</div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label">Institution type <span class="text-danger">*</span></label>
                                <select name="institution_type_id" id="institutionType" class="form-select" required data-guide-key="institution_type_id">
                                    <option value="">Select institution type</option>
                                    <?php foreach ($institutionTypes as $type): ?><option value="<?= (int)$type['id'] ?>" data-code="<?= h(strtolower($type['code'])) ?>" data-name="<?= h(strtolower($type['name'])) ?>" data-category="<?= h(setupInstitutionCategory($type)) ?>" data-description="<?= h(setupInstitutionTypeHelp($type)) ?>"><?= h($type['name']) ?></option><?php endforeach; ?>
                                </select>
                                <div class="form-text" id="institutionTypeHelp">Choose the school type that best matches your setup.</div>
                            </div>
                            <div class="field-guide" id="institutionFieldGuide" aria-live="polite">
                                <i class="fa fa-info-circle"></i>
                                <div>
                                    <strong id="institutionGuideTitle">Institution details</strong>
                                    <span id="institutionGuideText">Fill in the official name, short code, country, and timezone for the organisation you are setting up.</span>
                                </div>
                            </div>
                            <div class="field-grid">
                                <div class="field full"><label class="form-label">Institution name <span class="text-danger">*</span></label><input name="institution_name" class="form-control" required maxlength="190" placeholder="e.g. Kingsway Academy" data-guide-key="institution_name"></div>
                                <div class="field"><label class="form-label">Institution code</label><input name="institution_code" class="form-control" maxlength="60" placeholder="e.g. KWA-001" data-guide-key="institution_code">
                                    <div class="form-text">Optional unique short code.</div>
                                </div>
                                <div class="field"><label class="form-label">Country code <span class="text-danger">*</span></label><input name="country_code" class="form-control" required maxlength="2" pattern="[A-Za-z]{2}" placeholder="NG" data-guide-key="country_code">
                                    <div class="form-text">Two-letter ISO country code.</div>
                                </div>
                                <div class="field full"><label class="form-label">Timezone</label><input name="timezone" class="form-control" value="Africa/Lagos" required placeholder="Africa/Lagos" data-guide-key="timezone">
                                    <div class="form-text">Use a valid IANA timezone, such as Africa/Lagos or Europe/London.</div>
                                </div>
                            </div>
                        </div>
                        <div class="panel-foot"><span class="muted-small">Required fields are marked with *</span><button type="button" class="btn btn-primary next-btn">Continue <i class="fa fa-arrow-right ms-1"></i></button></div>
                    </section>
                    <section class="step-pane" data-step="1">
                        <div class="panel-head">
                            <h2 id="structureTitle">Define your organisation structure</h2>
                            <p id="structureDescription">Add the first unit that will organise learners, teams or departments.</p>
                        </div>
                        <div class="panel-body">
                            <div class="callout" id="structureHint">You can add more units later. Start with the most useful top-level unit for your organisation.</div>
                            <div class="field-grid">
                                <div class="field"><label class="form-label" id="unitTypeLabel">Unit type</label><select name="unit_type_id" id="unitType" class="form-select">
                                        <option value="">Choose unit type</option><?php foreach ($unitTypes as $type): ?><option value="<?= (int)$type['id'] ?>" data-code="<?= h(strtolower($type['code'])) ?>" data-name="<?= h(strtolower($type['name'])) ?>" data-category="<?= h(setupUnitTypeCategory($type)) ?>" data-description="<?= h(setupUnitTypeHelp($type)) ?>"><?= h($type['name']) ?></option><?php endforeach; ?>
                                    </select>
                                    <div class="form-text" id="unitTypeHelp">Choose Grade/Year, Class/Stream, Department, or School/College for a school setup.</div>
                                </div>
                                <div class="field"><label class="form-label" id="unitNameLabel">First unit name</label><input name="unit_name" class="form-control" maxlength="190" placeholder="e.g. Junior Secondary"></div>
                                <div class="field full"><label class="form-label">Unit code</label><input name="unit_code" class="form-control" maxlength="60" placeholder="Optional short code"></div>
                            </div>
                        </div>
                        <div class="panel-foot"><button type="button" class="btn btn-outline-secondary back-btn"><i class="fa fa-arrow-left me-1"></i> Back</button><button type="button" class="btn btn-primary next-btn">Continue <i class="fa fa-arrow-right ms-1"></i></button></div>
                    </section>
                    <section class="step-pane" data-step="2">
                        <div class="panel-head">
                            <h2 id="academicTitle">Set up programmes and levels</h2>
                            <p id="academicDescription">Create the first programme and level, if your organisation uses them.</p>
                        </div>
                        <div class="panel-body">
                            <div class="callout" id="academicHint">These fields are optional. You can add additional programmes and levels after setup.</div>
                            <div class="field-grid">
                                <div class="field"><label class="form-label" id="programmeLabel">Programme / pathway name</label><input name="programme_name" class="form-control" maxlength="190" placeholder="e.g. General Education"></div>
                                <div class="field"><label class="form-label">Programme code</label><input name="programme_code" class="form-control" maxlength="60" placeholder="Optional"></div>
                                <div class="field"><label class="form-label" id="levelLabel">First level / stage</label><input name="level_name" class="form-control" maxlength="190" placeholder="e.g. Year 1, Grade 6, 100 Level"></div>
                                <div class="field"><label class="form-label">Level code</label><input name="level_code" class="form-control" maxlength="60" placeholder="Optional"></div>
                            </div>
                        </div>
                        <div class="panel-foot"><button type="button" class="btn btn-outline-secondary back-btn"><i class="fa fa-arrow-left me-1"></i> Back</button><button type="button" class="btn btn-primary next-btn">Continue <i class="fa fa-arrow-right ms-1"></i></button></div>
                    </section>
                    <section class="step-pane" data-step="3">
                        <div class="panel-head">
                            <h2 id="coursesTitle">Academic calendar and learning areas</h2>
                            <p id="coursesDescription">Add the first period and course, subject or assessment area where relevant.</p>
                        </div>
                        <div class="panel-body">
                            <div class="callout" id="coursesHint">You may leave these fields blank and configure them later from the dashboard.</div>
                            <div class="field-grid">
                                <div class="field"><label class="form-label" id="periodLabel">Academic period / cycle</label><input name="period_name" class="form-control" maxlength="190" placeholder="e.g. 2026/2027"></div>
                                <div class="field"><label class="form-label">Period code</label><input name="period_code" class="form-control" maxlength="60" placeholder="e.g. 2026-2027"></div>
                                <div class="field"><label class="form-label" id="courseLabel">First course / subject</label><input name="course_name" class="form-control" maxlength="190" placeholder="e.g. Mathematics"></div>
                                <div class="field"><label class="form-label">Course code</label><input name="course_code" class="form-control" maxlength="60" placeholder="Optional"></div>
                            </div>
                        </div>
                        <div class="panel-foot"><button type="button" class="btn btn-outline-secondary back-btn"><i class="fa fa-arrow-left me-1"></i> Back</button><button type="button" class="btn btn-primary next-btn">Continue <i class="fa fa-arrow-right ms-1"></i></button></div>
                    </section>
                    <section class="step-pane" data-step="4">
                        <div class="panel-head">
                            <h2>Create the administrator account</h2>
                            <p>This account will be created for your organisation and used to access the super-admin dashboard.</p>
                        </div>
                        <div class="panel-body">
                            <div class="callout">Use a unique username and email address. Choose a strong password and keep it somewhere safe.</div>
                            <div class="field-grid">
                                <div class="field full"><label class="form-label">Administrator full name <span class="text-danger">*</span></label><input name="admin_name" class="form-control" required maxlength="190" autocomplete="name" placeholder="e.g. Alex Johnson"></div>
                                <div class="field"><label class="form-label">Username <span class="text-danger">*</span></label><input name="admin_username" class="form-control" required maxlength="80" autocomplete="username" placeholder="e.g. alex.admin"></div>
                                <div class="field"><label class="form-label">Email address <span class="text-danger">*</span></label><input name="admin_email" type="email" class="form-control" required maxlength="190" autocomplete="email" placeholder="admin@example.com"></div>
                                <div class="field"><label class="form-label">Password <span class="text-danger">*</span></label>
                                    <div class="password-wrap"><input name="admin_password" id="adminPassword" type="password" class="form-control" required minlength="10" autocomplete="new-password" placeholder="At least 10 characters"><button type="button" class="toggle-password" data-target="adminPassword">Show</button></div>
                                    <div class="form-text">Use at least 10 characters.</div>
                                </div>
                                <div class="field"><label class="form-label">Confirm password <span class="text-danger">*</span></label>
                                    <div class="password-wrap"><input name="admin_password_confirm" id="adminPasswordConfirm" type="password" class="form-control" required minlength="10" autocomplete="new-password" placeholder="Re-enter password"><button type="button" class="toggle-password" data-target="adminPasswordConfirm">Show</button></div>
                                </div>
                            </div>
                        </div>
                        <div class="panel-foot"><button type="button" class="btn btn-outline-secondary back-btn"><i class="fa fa-arrow-left me-1"></i> Back</button><button type="button" class="btn btn-primary next-btn">Review setup <i class="fa fa-arrow-right ms-1"></i></button></div>
                    </section>
                    <section class="step-pane" data-step="5">
                        <div class="panel-head">
                            <h2>Review your setup</h2>
                            <p>Check the information below before creating your organisation and administrator account.</p>
                        </div>
                        <div class="panel-body">
                            <div class="review-grid" id="reviewGrid"></div>
                            <div class="callout mt-4 mb-0"><strong>Ready to finish?</strong> Selecting Complete setup will save your configuration and redirect you to the super-admin dashboard.</div>
                        </div>
                        <div class="panel-foot"><button type="button" class="btn btn-outline-secondary back-btn"><i class="fa fa-arrow-left me-1"></i> Back</button><button type="submit" class="btn btn-primary" id="submitButton"><i class="fa fa-check me-1"></i> Complete setup</button></div>
                    </section>
                </form>
                <p class="muted-small mt-3 text-center">Examcenter Universal · You can refine your organisation structure after setup.</p>
            </div>
        </main>
    </div>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
        (() => {
            const form = document.getElementById('setupForm');
            const panes = [...document.querySelectorAll('.step-pane')];
            const navItems = [...document.querySelectorAll('#stepNav li')];
            const categoryInput = document.getElementById('institutionCategory');
            const categoryRadios = [...document.querySelectorAll('input[name="category_choice"]')];
            const institutionSelect = document.getElementById('institutionType');
            const unitTypeSelect = document.getElementById('unitType');
            let current = 0;

            const copy = {
                school: {
                    structureTitle: 'Set up your school structure',
                    structureDescription: 'Add the first school section, grade group or other organisational unit.',
                    structureHint: 'Examples include Primary, Junior Secondary, Senior Secondary, Grade 6 or a department. Choose the unit types available in your system.',
                    unitTypeLabel: 'School unit type',
                    unitNameLabel: 'First section / class group',
                    academicTitle: 'Set up programmes and class levels',
                    academicDescription: 'Add the first programme and level used by your school.',
                    academicHint: 'For example: Basic Education → Primary 6, or Secondary Education → SS 1. These are examples, not fixed requirements.',
                    programmeLabel: 'Programme / school stage',
                    levelLabel: 'First class / grade / level',
                    coursesTitle: 'Set up the school calendar and subject',
                    coursesDescription: 'Optionally define the current academic year and the first subject.',
                    coursesHint: 'Examples: 2026/2027 academic year, Mathematics or English Language.',
                    periodLabel: 'Academic year / term',
                    courseLabel: 'First subject'
                },
                higher_education: {
                    structureTitle: 'Set up faculties, schools or departments',
                    structureDescription: 'Add the first organisational unit used by your tertiary institution.',
                    structureHint: 'Examples include Faculty of Science, School of Engineering, Department of Computer Science or campus.',
                    unitTypeLabel: 'Faculty / department / unit type',
                    unitNameLabel: 'First faculty / department',
                    academicTitle: 'Set up programmes and study levels',
                    academicDescription: 'Add a degree, diploma or other programme and its first level.',
                    academicHint: 'Examples: B.Sc. Computer Science → 100 Level, or HND → Year 1.',
                    programmeLabel: 'Degree / programme name',
                    levelLabel: 'First study level',
                    coursesTitle: 'Set up the academic session and course',
                    coursesDescription: 'Optionally add the academic session and first course.',
                    coursesHint: 'Examples: 2026/2027 session, CSC101 or Introduction to Computing.',
                    periodLabel: 'Academic session / semester',
                    courseLabel: 'First course / module'
                },
                vocational: {
                    structureTitle: 'Set up training units',
                    structureDescription: 'Add the first training department, trade area or delivery unit.',
                    structureHint: 'Examples include Electrical Installation, Culinary Arts, Safety Training or a training centre.',
                    unitTypeLabel: 'Training unit type',
                    unitNameLabel: 'First training unit',
                    academicTitle: 'Set up training programmes and stages',
                    academicDescription: 'Define the first qualification or training pathway and stage if applicable.',
                    academicHint: 'Examples: Welding Certification → Level 1, or Workplace Safety → Beginner.',
                    programmeLabel: 'Qualification / training programme',
                    levelLabel: 'First level / stage',
                    coursesTitle: 'Set up the training cycle and module',
                    coursesDescription: 'Optionally enter a training period and first module.',
                    coursesHint: 'Examples: October 2026 cohort, First Aid or Workplace Safety.',
                    periodLabel: 'Training cycle / cohort',
                    courseLabel: 'First module / subject'
                },
                business: {
                    structureTitle: 'Set up teams or departments',
                    structureDescription: 'Add the first department, business unit or location for assessments.',
                    structureHint: 'Examples include Human Resources, Sales, Operations, a branch or recruitment team.',
                    unitTypeLabel: 'Department / team type',
                    unitNameLabel: 'First team / department',
                    academicTitle: 'Set up assessment programmes',
                    academicDescription: 'Optionally define a recruitment, onboarding or staff development pathway.',
                    academicHint: 'Examples: Graduate Recruitment → Screening, or Staff Induction → New Hire.',
                    programmeLabel: 'Assessment / training programme',
                    levelLabel: 'First stage / cohort',
                    coursesTitle: 'Set up the assessment cycle and topic',
                    coursesDescription: 'Optionally add a cycle and first assessment topic or course.',
                    coursesHint: 'Examples: October Recruitment 2026, Workplace Safety or Technical Screening.',
                    periodLabel: 'Assessment cycle / cohort',
                    courseLabel: 'First topic / course'
                },
                other: {
                    structureTitle: 'Set up your organisational units',
                    structureDescription: 'Add the first unit that best represents how your organisation is arranged.',
                    structureHint: 'Use a unit type and name that match your own organisational structure.',
                    unitTypeLabel: 'Organisational unit type',
                    unitNameLabel: 'First unit name',
                    academicTitle: 'Set up programmes and levels',
                    academicDescription: 'Add a programme and stage if your organisation uses them.',
                    academicHint: 'These fields are optional and can be configured later.',
                    programmeLabel: 'Programme name',
                    levelLabel: 'First level / stage',
                    coursesTitle: 'Set up periods and learning areas',
                    coursesDescription: 'Optionally add a period and the first course or assessment area.',
                    coursesHint: 'These fields are optional and can be configured later.',
                    periodLabel: 'Period / cycle name',
                    courseLabel: 'First course / assessment area'
                }
            };

            const categoryControls = {
                school: {
                    institutionPlaceholder: 'Choose primary or secondary school',
                    institutionHelp: 'Primary School is for nursery, primary, elementary, or basic education. Secondary School is for junior or senior secondary/high school.',
                    unitPlaceholder: 'Choose grade, class, department, or school section',
                    unitHelp: 'For a school, choose Grade/Year for levels, Class/Stream for arms, Department for subjects, or School/College for a major section.',
                    placeholders: {
                        institution_name: 'e.g. Kingsway Academy',
                        unit_name: 'e.g. Junior Secondary, Primary 6, or SS 1A',
                        unit_code: 'e.g. JS, PRY6, or SS1A',
                        programme_name: 'e.g. Basic Education or Secondary Education',
                        programme_code: 'e.g. BASIC or SEC',
                        level_name: 'e.g. Primary 6, JSS 2, or SS 1',
                        level_code: 'e.g. PRY6, JSS2, or SS1',
                        period_name: 'e.g. 2026/2027 Academic Year',
                        period_code: 'e.g. 2026-2027',
                        course_name: 'e.g. Mathematics',
                        course_code: 'e.g. MATH'
                    }
                },
                higher_education: {
                    institutionPlaceholder: 'Choose tertiary institution',
                    institutionHelp: 'Use Tertiary Institution for universities, polytechnics, colleges, and similar higher education providers.',
                    unitPlaceholder: 'Choose campus, faculty, school, or department',
                    unitHelp: 'For tertiary institutions, Faculty, School/College, Department, Programme, or Campus is usually the right first unit.',
                    placeholders: {
                        institution_name: 'e.g. Northern Valley University',
                        unit_name: 'e.g. Faculty of Science',
                        unit_code: 'e.g. SCI',
                        programme_name: 'e.g. B.Sc. Computer Science',
                        programme_code: 'e.g. BSC-CS',
                        level_name: 'e.g. 100 Level or Year 1',
                        level_code: 'e.g. 100L',
                        period_name: 'e.g. 2026/2027 Session',
                        period_code: 'e.g. 2026-2027',
                        course_name: 'e.g. Introduction to Computing',
                        course_code: 'e.g. CSC101'
                    }
                },
                vocational: {
                    institutionPlaceholder: 'Choose vocational or training institution',
                    institutionHelp: 'Use Vocational / Training Institution for training centres, trade schools, skills programmes, and certification providers.',
                    unitPlaceholder: 'Choose training department, programme, cohort, or campus',
                    unitHelp: 'For training providers, Department, Programme, Cohort, Campus, or School/College usually fits best.',
                    placeholders: {
                        institution_name: 'e.g. Apex Skills Centre',
                        unit_name: 'e.g. Electrical Installation',
                        unit_code: 'e.g. ELEC',
                        programme_name: 'e.g. Welding Certification',
                        programme_code: 'e.g. WELD-CERT',
                        level_name: 'e.g. Level 1 or Beginner',
                        level_code: 'e.g. L1',
                        period_name: 'e.g. October 2026 Cohort',
                        period_code: 'e.g. OCT-2026',
                        course_name: 'e.g. Workshop Safety',
                        course_code: 'e.g. SAFE101'
                    }
                },
                business: {
                    institutionPlaceholder: 'Choose corporate or organisation',
                    institutionHelp: 'Use Corporate / Organisation for recruitment, staff assessments, onboarding, and workplace training.',
                    unitPlaceholder: 'Choose department, division, team, or branch',
                    unitHelp: 'For corporate setup, Department, Division, Team, Campus, or Cohort usually fits best.',
                    placeholders: {
                        institution_name: 'e.g. BluePeak Technologies',
                        unit_name: 'e.g. Human Resources or Sales Team',
                        unit_code: 'e.g. HR',
                        programme_name: 'e.g. Graduate Recruitment',
                        programme_code: 'e.g. GRAD-REC',
                        level_name: 'e.g. Screening or Interview Stage',
                        level_code: 'e.g. SCREEN',
                        period_name: 'e.g. October Recruitment 2026',
                        period_code: 'e.g. OCT-2026',
                        course_name: 'e.g. Technical Screening',
                        course_code: 'e.g. TECH'
                    }
                },
                other: {
                    institutionPlaceholder: 'Choose assessment centre or other',
                    institutionHelp: 'Use Assessment Centre for independent exam centres, or Other when none of the listed types fits.',
                    unitPlaceholder: 'Choose the closest unit type',
                    unitHelp: 'Choose the unit type closest to how your organisation is arranged.',
                    placeholders: {
                        institution_name: 'e.g. City Assessment Centre',
                        unit_name: 'e.g. Main Centre',
                        unit_code: 'Optional short code',
                        programme_name: 'e.g. Certification Assessments',
                        programme_code: 'Optional',
                        level_name: 'e.g. Beginner or Stage 1',
                        level_code: 'Optional',
                        period_name: 'e.g. 2026 Assessment Cycle',
                        period_code: 'Optional',
                        course_name: 'e.g. General Aptitude',
                        course_code: 'Optional'
                    }
                }
            };
            const institutionOptions = readOptions(institutionSelect);
            const unitTypeOptions = readOptions(unitTypeSelect);
            const institutionGuideTitle = document.getElementById('institutionGuideTitle');
            const institutionGuideText = document.getElementById('institutionGuideText');
            let activeInstitutionGuide = 'default';

            const institutionGuides = {
                default: {
                    title: 'Institution details',
                    text: () => 'Fill in the official name, short code, country, and timezone for the organisation you are setting up.'
                },
                institution_type_id: {
                    title: 'Institution type',
                    text: () => {
                        const controls = categoryControls[selectedCategory()] || categoryControls.other;
                        return controls.institutionHelp;
                    }
                },
                institution_name: {
                    title: 'Institution name',
                    text: () => 'Enter the full public name of the organisation. Example: ' + exampleFor('institution_name') + '.'
                },
                institution_code: {
                    title: 'Institution code',
                    text: () => 'Optional. Use a short unique code for reports, exports, or internal references. Example: ' + exampleFor('institution_code') + '.'
                },
                country_code: {
                    title: 'Country code',
                    text: () => 'Enter the two-letter ISO country code for the institution location. Example: NG for Nigeria, GH for Ghana, or GB for the United Kingdom.'
                },
                timezone: {
                    title: 'Timezone',
                    text: () => 'Enter a valid IANA timezone so schedules and timestamps are correct. Example: Africa/Lagos.'
                }
            };

            function selectedCategory() {
                return (categoryRadios.find(r => r.checked) || {}).value || 'school';
            }

            function readOptions(select) {
                return [...select.querySelectorAll('option')].filter(option => option.value).map(option => ({
                    value: option.value,
                    label: option.textContent,
                    code: option.dataset.code || '',
                    name: option.dataset.name || '',
                    category: option.dataset.category || 'other',
                    description: option.dataset.description || ''
                }));
            }

            function optionFits(option, category) {
                if (option.category === 'all') return true;
                if (option.category === 'shared') return ['school', 'higher_education', 'vocational', 'business'].includes(category);
                return option.category.split(',').includes(category);
            }

            function rebuildSelect(select, options, category, placeholder) {
                const currentValue = select.value;
                let filtered = options.filter(option => optionFits(option, category));
                if (!filtered.length) filtered = options;
                select.innerHTML = '';
                select.append(new Option(placeholder, ''));
                filtered.forEach(item => {
                    const option = new Option(item.label, item.value);
                    option.dataset.code = item.code;
                    option.dataset.name = item.name;
                    option.dataset.category = item.category;
                    option.dataset.description = item.description;
                    select.append(option);
                });
                select.value = filtered.some(item => item.value === currentValue) ? currentValue : '';
            }

            function setFieldPlaceholder(name, value) {
                const field = form.elements[name];
                if (field) field.placeholder = value;
            }

            function exampleFor(name) {
                const field = form.elements[name];
                return field?.placeholder || 'the value shown in the placeholder';
            }

            function updateInstitutionGuide(key = activeInstitutionGuide) {
                activeInstitutionGuide = institutionGuides[key] ? key : 'default';
                const guide = institutionGuides[activeInstitutionGuide];
                institutionGuideTitle.textContent = guide.title;
                institutionGuideText.textContent = typeof guide.text === 'function' ? guide.text() : guide.text;
            }

            function updateSelectHelp(select, helpId, fallback) {
                const selected = select.selectedOptions[0];
                document.getElementById(helpId).textContent = selected?.value ? (selected.dataset.description || fallback) : fallback;
            }

            function applyCategory(category) {
                categoryInput.value = category;
                const c = copy[category] || copy.other;
                const controls = categoryControls[category] || categoryControls.other;
                Object.entries(c).forEach(([key, value]) => {
                    const el = document.getElementById(key);
                    if (el) el.textContent = value;
                });
                Object.entries(controls.placeholders).forEach(([name, value]) => setFieldPlaceholder(name, value));
                rebuildSelect(institutionSelect, institutionOptions, category, controls.institutionPlaceholder);
                rebuildSelect(unitTypeSelect, unitTypeOptions, category, controls.unitPlaceholder);
                updateSelectHelp(institutionSelect, 'institutionTypeHelp', controls.institutionHelp);
                updateSelectHelp(unitTypeSelect, 'unitTypeHelp', controls.unitHelp);
                updateInstitutionGuide();
                document.querySelectorAll('.type-card').forEach(card => {
                    card.classList.toggle('selected', card.querySelector('input').checked);
                });
            }

            function updateReview() {
                const value = name => (form.elements[name]?.value || '').trim();
                const type = document.getElementById('institutionType');
                const typeName = type.selectedOptions[0]?.textContent || 'Not selected';
                const rows = [
                    ['Institution', value('institution_name') || '—'],
                    ['Institution type', typeName],
                    ['Category', selectedCategory().replace('_', ' ')],
                    ['Institution code', value('institution_code') || 'Not provided'],
                    ['Country / timezone', (value('country_code') || 'Not provided') + ' · ' + (value('timezone') || 'Africa/Lagos')],
                    ['Organisational unit', value('unit_name') || 'Not added'],
                    ['Programme', value('programme_name') || 'Not added'],
                    ['Level / stage', value('level_name') || 'Not added'],
                    ['Period / cycle', value('period_name') || 'Not added'],
                    ['Course / subject', value('course_name') || 'Not added'],
                    ['Administrator', value('admin_name') || '—'],
                    ['Admin email', value('admin_email') || '—']
                ];
                document.getElementById('reviewGrid').innerHTML = rows.map(([label, val]) =>
                    '<div class="review-item"><span>' + escapeHtml(label) + '</span><strong>' + escapeHtml(val) + '</strong></div>'
                ).join('');
            }

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, ch => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [ch]));
            }

            function render() {
                panes.forEach((pane, i) => pane.classList.toggle('active', i === current));
                navItems.forEach((item, i) => {
                    item.classList.toggle('active', i === current);
                    item.classList.toggle('done', i < current);
                    item.querySelector('.step-number').textContent = i < current ? '✓' : String(i + 1);
                });
                const pct = Math.round(((current + 1) / panes.length) * 100);
                document.getElementById('stepCaption').textContent = 'Step ' + (current + 1) + ' of ' + panes.length;
                document.getElementById('stepPercent').textContent = pct + '% complete';
                document.getElementById('progressBar').style.width = pct + '%';
                if (current === 5) updateReview();
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            }

            function validateCurrent() {
                const pane = panes[current];
                const fields = [...pane.querySelectorAll('input,select,textarea')].filter(el => el.type !== 'hidden' && el.type !== 'radio' && !el.disabled);
                for (const field of fields) {
                    if (!field.checkValidity()) {
                        field.reportValidity();
                        field.focus();
                        return false;
                    }
                }
                if (current === 0 && !document.getElementById('institutionType').value) {
                    document.getElementById('institutionType').reportValidity();
                    return false;
                }
                if (current === 4) {
                    const password = document.getElementById('adminPassword');
                    const confirm = document.getElementById('adminPasswordConfirm');
                    if (password.value !== confirm.value) {
                        confirm.setCustomValidity('Passwords do not match.');
                        confirm.reportValidity();
                        confirm.setCustomValidity('');
                        return false;
                    }
                }
                return true;
            }
            document.querySelectorAll('.next-btn').forEach(btn => btn.addEventListener('click', () => {
                if (!validateCurrent()) return;
                if (current < panes.length - 1) {
                    current++;
                    render();
                }
            }));
            document.querySelectorAll('.back-btn').forEach(btn => btn.addEventListener('click', () => {
                if (current > 0) {
                    current--;
                    render();
                }
            }));
            document.querySelectorAll('[data-guide-key]').forEach(field => {
                const showGuide = () => updateInstitutionGuide(field.dataset.guideKey);
                field.addEventListener('focus', showGuide);
                field.addEventListener('click', showGuide);
            });
            categoryRadios.forEach(radio => radio.addEventListener('change', () => applyCategory(selectedCategory())));
            institutionSelect.addEventListener('change', e => {
                const selected = e.target.selectedOptions[0];
                const controls = categoryControls[selectedCategory()] || categoryControls.other;
                updateSelectHelp(institutionSelect, 'institutionTypeHelp', controls.institutionHelp);
                if (!selected || !selected.value) return;
                const descriptor = (selected.dataset.code + ' ' + selected.dataset.name).toLowerCase();
                let category = 'other';
                if (/secondary|primary|school|basic|k-12|high school/.test(descriptor)) category = 'school';
                else if (/universit|college|polytechnic|tertiary|higher|institute of technology/.test(descriptor)) category = 'higher_education';
                else if (/vocational|training|professional|trade|certification|apprentice/.test(descriptor)) category = 'vocational';
                else if (/company|business|corporate|enterprise|recruit|workplace|organisation|organization/.test(descriptor)) category = 'business';
                const radio = categoryRadios.find(r => r.value === category);
                if (radio) radio.checked = true;
                applyCategory(category);
            });
            unitTypeSelect.addEventListener('change', () => {
                const controls = categoryControls[selectedCategory()] || categoryControls.other;
                updateSelectHelp(unitTypeSelect, 'unitTypeHelp', controls.unitHelp);
            });
            document.querySelectorAll('.toggle-password').forEach(btn => btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.target);
                input.type = input.type === 'password' ? 'text' : 'password';
                btn.textContent = input.type === 'password' ? 'Show' : 'Hide';
            }));
            form.addEventListener('submit', e => {
                if (current !== 5) {
                    e.preventDefault();
                    return;
                }
                const password = document.getElementById('adminPassword');
                const confirm = document.getElementById('adminPasswordConfirm');
                if (password.value !== confirm.value) {
                    e.preventDefault();
                    current = 4;
                    render();
                    confirm.setCustomValidity('Passwords do not match.');
                    confirm.reportValidity();
                    confirm.setCustomValidity('');
                    return;
                }
                document.getElementById('submitButton').disabled = true;
                document.getElementById('submitButton').innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating setup…';
            });
            applyCategory(selectedCategory());
            render();
        })();
    </script>
</body>

</html>
