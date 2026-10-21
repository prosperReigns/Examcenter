<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/universal_architecture.php';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$conn = Database::connection();
$error = '';
$institutionProfile = examcenterInstitutionProfile($conn);
$institutionId = examcenterActiveInstitutionId($conn);
if ($institutionId === null) {
    http_response_code(503);
    exit('No active institution has been configured. Complete Universal Institution Setup first.');
}

$category = (string)($institutionProfile['category'] ?? 'other');
$isSchool = in_array($category, ['primary_school', 'secondary_school'], true);
$hasInstitution = examcenterUniversalColumnExists($conn, 'tests', 'institution_id');
if (!$hasInstitution) {
    http_response_code(503);
    exit('Universal assessment registration is not available until institution test records are configured.');
}
$hasAcademicLevel = examcenterUniversalColumnExists($conn, 'tests', 'academic_level_id');
$hasCourse = examcenterUniversalColumnExists($conn, 'tests', 'course_id')
    && examcenterUniversalTableExists($conn, 'courses');
$hasUnit = examcenterUniversalColumnExists($conn, 'tests', 'organizational_unit_id')
    && examcenterUniversalTableExists($conn, 'organizational_units');
$hasPeriod = examcenterUniversalColumnExists($conn, 'tests', 'academic_period_id')
    && examcenterUniversalTableExists($conn, 'academic_periods');
$institutionJoinScope = $hasInstitution ? ' AND %s.institution_id = t.institution_id' : '';

$selectLevel = $hasAcademicLevel
    ? 'al.level_code AS level_name'
    : 'NULL AS level_name';
$levelJoin = $hasAcademicLevel && examcenterUniversalTableExists($conn, 'academic_levels')
    ? ' LEFT JOIN academic_levels al ON al.id = t.academic_level_id'
    : '';
$selectCourse = $hasCourse
    ? 't.course_id, c.name AS course_name'
    : 'NULL AS course_id, NULL AS course_name';
$courseJoin = $hasCourse && examcenterUniversalTableExists($conn, 'courses')
    ? ' LEFT JOIN courses c ON c.id = t.course_id' . sprintf($institutionJoinScope, 'c')
    : '';
$selectUnit = $hasUnit
    ? 't.organizational_unit_id, ou.name AS unit_name'
    : 'NULL AS organizational_unit_id, NULL AS unit_name';
$unitJoin = $hasUnit && examcenterUniversalTableExists($conn, 'organizational_units')
    ? ' LEFT JOIN organizational_units ou ON ou.id = t.organizational_unit_id' . sprintf($institutionJoinScope, 'ou')
    : '';
$selectPeriod = $hasPeriod
    ? 't.academic_period_id, ap.name AS period_name, ap.period_type'
    : 'NULL AS academic_period_id, NULL AS period_name, NULL AS period_type';
$periodJoin = $hasPeriod && examcenterUniversalTableExists($conn, 'academic_periods')
    ? ' LEFT JOIN academic_periods ap ON ap.id = t.academic_period_id' . sprintf($institutionJoinScope, 'ap')
    : '';
$institutionScope = $hasInstitution ? ' AND t.institution_id = ?' : '';
$query = "SELECT t.id, t.title, t.subject, t.year, t.duration,
                 {$selectLevel}, {$selectCourse}, {$selectUnit}, {$selectPeriod}
          FROM tests t
          {$levelJoin} {$courseJoin} {$unitJoin} {$periodJoin}
          WHERE t.title IS NOT NULL AND t.title <> '' {$institutionScope}
          ORDER BY t.year DESC, t.title ASC";
$stmt = $conn->prepare($query);
if ($hasInstitution) {
    $stmt->bind_param('i', $institutionId);
}
$stmt->execute();
$tests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$subjectLabel = $isSchool ? 'Subject' : 'Course';
$unitLabel = match ($category) {
    'higher_education' => 'Faculty / Department',
    'vocational' => 'Programme / Training Unit',
    'business' => 'Department / Unit',
    default => 'Organizational Unit',
};
$subjectKey = static function (array $test) use ($isSchool): string {
    return trim((string)($isSchool ? $test['subject'] : ($test['course_name'] ?: $test['subject'])));
};
$subjects = [];
$levels = [];
$years = [];
$periods = [];
$units = [];
foreach ($tests as $test) {
    $key = $subjectKey($test);
    if ($key !== '') $subjects[$key] = $key;
    if ($isSchool && trim((string)($test['level_name'] ?? '')) !== '') {
        $levels[(string)$test['level_name']] = (string)$test['level_name'];
    }
    if (trim((string)($test['year'] ?? '')) !== '') {
        $years[(string)$test['year']] = (string)$test['year'];
    }
    if (!empty($test['academic_period_id'])) {
        $periodId = (string)(int)$test['academic_period_id'];
        $periods[$periodId] = trim((string)($test['period_name'] ?? ''))
            ?: 'Academic period ' . $periodId;
    }
    if (!empty($test['organizational_unit_id'])) {
        $unitId = (string)(int)$test['organizational_unit_id'];
        $units[$unitId] = trim((string)($test['unit_name'] ?? '')) ?: $unitLabel . ' ' . $unitId;
    }
}
natcasesort($subjects);
natcasesort($levels);
krsort($years);
natcasesort($periods);
natcasesort($units);

$old = [
    'name' => trim((string)($_POST['name'] ?? '')),
    'subject' => trim((string)($_POST['subject'] ?? '')),
    'level' => trim((string)($_POST['level'] ?? '')),
    'year' => trim((string)($_POST['year'] ?? '')),
    'period' => trim((string)($_POST['period'] ?? '')),
    'unit' => trim((string)($_POST['unit'] ?? '')),
    'test_id' => (int)($_POST['test_id'] ?? 0),
];
$hasPeriods = !empty($periods);
$showYear = $isSchool || !$hasPeriods;

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your registration session expired. Refresh the page and try again.';
    } elseif ($old['name'] === '' || mb_strlen($old['name']) > 150 || $old['test_id'] <= 0) {
        $error = 'Enter the student name and select an assessment.';
    } else {
        $selectedTest = null;
        foreach ($tests as $test) {
            if ((int)$test['id'] === $old['test_id']) {
                $selectedTest = $test;
                break;
            }
        }
        if (!$selectedTest) {
            $error = 'The selected assessment is not available for this institution.';
        } elseif ($old['subject'] !== $subjectKey($selectedTest)) {
            $error = 'Select a valid ' . strtolower($subjectLabel) . ' for this assessment.';
        } elseif ($isSchool && $old['level'] !== (string)($selectedTest['level_name'] ?? '')) {
            $error = 'Select the class or level assigned to this assessment.';
        } elseif ($showYear && $years && $old['year'] !== (string)($selectedTest['year'] ?? '')) {
            $error = $isSchool ? 'Select the academic year assigned to this assessment.' : 'Select the year assigned to this assessment.';
        } elseif ($periods && $old['period'] !== (string)((int)($selectedTest['academic_period_id'] ?? 0))) {
            $error = 'Select the academic period assigned to this assessment.';
        } elseif ($units && $old['unit'] !== '' && $old['unit'] !== (string)((int)($selectedTest['organizational_unit_id'] ?? 0))) {
            $error = 'Select the organizational unit assigned to this assessment.';
        } else {
            $conn->begin_transaction();
            try {
                $legacyClass = $isSchool
                    ? (string)$selectedTest['level_name']
                    : (!empty($selectedTest['organizational_unit_id'])
                        ? (string)(int)$selectedTest['organizational_unit_id']
                        : 'UNIVERSAL');

                $stmt = $conn->prepare('INSERT INTO students (full_name, class) VALUES (?, ?)');
                $stmt->bind_param('ss', $old['name'], $legacyClass);
                $stmt->execute();
                $studentId = (int)$conn->insert_id;
                $stmt->close();

                $externalRef = 'LEGACY-STUDENT-' . $studentId;
                $stmt = $conn->prepare(
                    'INSERT INTO people (institution_id, display_name, external_ref, is_active) VALUES (?, ?, ?, 1)'
                );
                $stmt->bind_param('iss', $institutionId, $old['name'], $externalRef);
                $stmt->execute();
                $personId = (int)$conn->insert_id;
                $stmt->close();

                $stmt = $conn->prepare(
                    "INSERT INTO institution_memberships (institution_id, person_id, role_code, legacy_student_id, status)
                     VALUES (?, ?, 'student', ?, 'active')"
                );
                $stmt->bind_param('iii', $institutionId, $personId, $studentId);
                $stmt->execute();
                $membershipId = (int)$conn->insert_id;
                $stmt->close();

                if (!empty($selectedTest['organizational_unit_id']) && examcenterUniversalTableExists($conn, 'unit_memberships')) {
                    $unitId = (int)$selectedTest['organizational_unit_id'];
                    $stmt = $conn->prepare(
                        "INSERT IGNORE INTO unit_memberships (unit_id, person_id, membership_role)
                         VALUES (?, ?, 'student')"
                    );
                    $stmt->bind_param('ii', $unitId, $personId);
                    $stmt->execute();
                    $stmt->close();
                }

                $_SESSION['student_id'] = $studentId;
                $_SESSION['student_name'] = $old['name'];
                $_SESSION['student_class'] = $legacyClass;
                $_SESSION['student_subject'] = (string)$selectedTest['subject'];
                $_SESSION['student_course'] = $subjectKey($selectedTest);
                $_SESSION['student_period'] = (string)($selectedTest['period_name'] ?? '');
                $_SESSION['test_title'] = (string)$selectedTest['title'];
                $_SESSION['exam_year'] = (string)$selectedTest['year'];
                $_SESSION['current_test_id'] = (int)$selectedTest['id'];
                $_SESSION['institution_membership_id'] = $membershipId;
                unset($_SESSION['exam_preview_authorized'], $_SESSION['theory_test_id'], $_SESSION['exam_questions']);

                $conn->commit();
                header('Location: exam_preview.php');
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('Universal registration failed: ' . $e->getMessage());
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Registration | Examcenter</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --registration-ink: #172b4d;
            --registration-muted: #66758b;
            --registration-blue: #2459a6;
            --registration-line: #e4eaf2;
        }
        body {
            min-height: 100vh;
            color: var(--registration-ink);
            background:
                radial-gradient(ellipse at 80% 0%, rgba(77, 132, 211, .13), transparent 34rem),
                linear-gradient(180deg, #f5f8fc 0%, #eef3f9 100%);
        }
        .registration-topbar {
            height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 clamp(20px, 5vw, 72px);
            background: rgba(255, 255, 255, .9);
            border-bottom: 1px solid rgba(223, 231, 241, .9);
        }
        .registration-brand { display: inline-flex; align-items: center; gap: 11px; color: var(--registration-ink); font-size: 18px; font-weight: 750; text-decoration: none; letter-spacing: -.3px; }
        .registration-brand-mark { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 10px; color: #fff; background: linear-gradient(145deg, #3475ce, #1e4f95); font-size: 15px; font-weight: 800; box-shadow: 0 5px 12px rgba(36, 89, 166, .22); }
        .topbar-note { color: var(--registration-muted); font-size: 13px; }
        .registration-shell { max-width: 1080px; padding-top: clamp(30px, 6vh, 64px); padding-bottom: 56px; }
        .registration-heading { margin: 0 0 25px; }
        .eyebrow { color: var(--registration-blue); font-size: 12px; font-weight: 800; letter-spacing: 1.2px; text-transform: uppercase; }
        .registration-heading h1 { margin: 8px 0 7px; color: var(--registration-ink); font-size: clamp(27px, 4vw, 36px); font-weight: 750; letter-spacing: -1px; }
        .registration-heading p { margin: 0; color: var(--registration-muted); }
        .registration-card { overflow: hidden; border: 1px solid var(--registration-line); border-radius: 16px; background: #fff; box-shadow: 0 20px 55px rgba(31, 55, 88, .09); }
        .registration-card-header { padding: 22px 26px; border-bottom: 1px solid var(--registration-line); background: #fff; }
        .registration-card-header strong { display: block; font-size: 16px; }
        .registration-card-header span { display: inline-block; margin-top: 4px; color: var(--registration-muted); font-size: 13px; }
        .registration-card-body { padding: 26px; }
        .field-label { margin-bottom: 7px; color: #31445f; font-size: 13px; font-weight: 700; }
        .form-control, .form-select { min-height: 46px; padding: 10px 12px; border-color: #d7e0eb; border-radius: 8px; color: var(--registration-ink); box-shadow: none; }
        .form-control:focus, .form-select:focus { border-color: #6d9bd6; box-shadow: 0 0 0 3px rgba(36, 89, 166, .12); }
        .name-field .form-control { min-height: 50px; }
        .field-help { margin-top: 6px; color: #7a8798; font-size: 12px; }
        .continue-button { min-height: 49px; border: 0; border-radius: 8px; background: linear-gradient(110deg, #2b68b8, #24559b); box-shadow: 0 7px 16px rgba(36, 89, 166, .18); font-weight: 700; }
        .continue-button:hover { background: #1f4f92; }
        .registration-aside { height: 100%; padding: 23px; border: 1px solid var(--registration-line); border-radius: 16px; background: rgba(255, 255, 255, .78); }
        .registration-aside h2 { margin: 0 0 18px; font-size: 15px; font-weight: 750; }
        .step { display: flex; gap: 12px; margin-bottom: 18px; }
        .step:last-child { margin-bottom: 0; }
        .step-number { flex: 0 0 28px; height: 28px; display: grid; place-items: center; border-radius: 50%; color: var(--registration-blue); background: #eaf2fc; font-size: 12px; font-weight: 800; }
        .step strong { display: block; margin: 1px 0 3px; font-size: 13px; }
        .step span { color: var(--registration-muted); font-size: 12px; }
        .privacy-note { margin-top: 23px; padding-top: 17px; border-top: 1px solid var(--registration-line); color: var(--registration-muted); font-size: 12px; line-height: 1.55; }
        .registration-footer { margin-top: 18px; color: #7a8798; font-size: 12px; text-align: center; }
        @media (max-width: 767.98px) {
            .registration-topbar { height: 62px; }
            .topbar-note { display: none; }
            .registration-shell { padding-top: 26px; }
            .registration-card-header, .registration-card-body { padding: 20px; }
        }
    </style>
</head>
<body>
<header class="registration-topbar">
    <a class="registration-brand" href="register.php" aria-label="Examcenter registration">
        <span class="registration-brand-mark" aria-hidden="true">E</span>
        <span>Examcenter</span>
    </a>
    <span class="topbar-note">Secure candidate registration</span>
</header>
<main class="container registration-shell">
    <div class="registration-heading">
        <span class="eyebrow">Candidate portal</span>
        <h1>Student Registration</h1>
        <p>Enter your details and choose the assessment you are attending.</p>
    </div>
    <div class="row g-4 align-items-stretch">
        <div class="col-lg-8">
            <section class="registration-card" aria-labelledby="registration-form-title">
                <div class="registration-card-header">
                    <strong id="registration-form-title"><?= e($institutionProfile['type_name'] ?: 'Assessment') ?> registration details</strong>
                    <span>Fields shown here match the selected institution and its available assessments.</span>
                </div>
                <div class="registration-card-body">
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
                    <?php endif; ?>

                    <?php if (!$tests): ?>
                        <div class="alert alert-warning mb-0">No assessments are currently available for this institution.</div>
                    <?php else: ?>
                        <form method="post" id="registrationForm">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <div class="mb-3">
                                <label for="name" class="form-label field-label">Student Name</label>
                                <input type="text" id="name" name="name" class="form-control" required maxlength="150" autocomplete="name" value="<?= e($old['name']) ?>">
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="subject" class="form-label field-label"><?= e($subjectLabel) ?></label>
                                    <select id="subject" name="subject" class="form-select" required>
                                        <option value="">Select <?= strtolower(e($subjectLabel)) ?></option>
                                        <?php foreach ($subjects as $subject): ?>
                                            <option value="<?= e($subject) ?>" <?= $old['subject'] === $subject ? 'selected' : '' ?>><?= e($subject) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <?php if ($isSchool): ?>
                                    <div class="col-md-6">
                                        <label for="level" class="form-label field-label">Class / Grade / Level</label>
                                        <select id="level" name="level" class="form-select" required>
                                            <option value="">Select class or level</option>
                                            <?php foreach ($levels as $level): ?>
                                                <option value="<?= e($level) ?>" <?= $old['level'] === $level ? 'selected' : '' ?>><?= e($level) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php elseif ($units): ?>
                                    <div class="col-md-6">
                                        <label for="unit" class="form-label field-label"><?= e($unitLabel) ?> <span class="text-muted">(optional)</span></label>
                                        <select id="unit" name="unit" class="form-select">
                                            <option value="">Any unit</option>
                                            <?php foreach ($units as $id => $unit): ?>
                                                <option value="<?= e($id) ?>" <?= $old['unit'] === $id ? 'selected' : '' ?>><?= e($unit) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <?php if ($showYear && $years): ?>
                                    <div class="col-md-6">
                                        <label for="year" class="form-label field-label"><?= $isSchool ? 'Academic Year' : 'Assessment Year' ?></label>
                                        <select id="year" name="year" class="form-select" required>
                                            <option value="">Select year</option>
                                            <?php foreach ($years as $year): ?>
                                                <option value="<?= e($year) ?>" <?= $old['year'] === $year ? 'selected' : '' ?>><?= e($year) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <?php if ($hasPeriods): ?>
                                    <div class="col-md-6">
                                        <label for="period" class="form-label field-label"><?= $isSchool ? 'Term / Academic Period' : 'Academic / Operational Period' ?></label>
                                        <select id="period" name="period" class="form-select" required>
                                            <option value="">Select period</option>
                                            <option value="0" <?= $old['period'] === '0' ? 'selected' : '' ?>>No period assigned</option>
                                            <?php foreach ($periods as $id => $periodName): ?>
                                                <option value="<?= e($id) ?>" <?= $old['period'] === $id ? 'selected' : '' ?>><?= e($periodName) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <div class="col-12">
                                    <label for="test_id" class="form-label field-label">Assessment</label>
                                    <select id="test_id" name="test_id" class="form-select" required>
                                        <option value="">Select assessment</option>
                                        <?php foreach ($tests as $test): ?>
                                            <?php
                                            $periodKey = (string)((int)($test['academic_period_id'] ?? 0));
                                            $courseOrSubject = $subjectKey($test);
                                            $unitId = (string)((int)($test['organizational_unit_id'] ?? 0));
                                            ?>
                                            <option
                                                value="<?= (int)$test['id'] ?>"
                                                data-subject="<?= e($courseOrSubject) ?>"
                                                data-level="<?= e($test['level_name'] ?? '') ?>"
                                                data-year="<?= e($test['year'] ?? '') ?>"
                                                data-period="<?= e($periodKey) ?>"
                                                data-unit="<?= e($unitId) ?>"
                                                <?= $old['test_id'] === (int)$test['id'] ? 'selected' : '' ?>
                                            ><?= e($test['title']) ?> — <?= e($courseOrSubject) ?><?php if ($isSchool && !empty($test['level_name'])): ?> / <?= e($test['level_name']) ?><?php endif; ?><?php if (!empty($test['year'])): ?> / <?= e($test['year']) ?><?php endif; ?><?php if (!empty($test['period_name'])): ?> / <?= e($test['period_name']) ?><?php endif; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="field-help">Only assessments matching the selected details can be registered.</div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary continue-button w-100 mt-4">Continue to Exam Preview <span aria-hidden="true">&nbsp;→</span></button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <aside class="col-lg-4">
            <div class="registration-aside">
                <h2>What happens next?</h2>
                <div class="step"><span class="step-number">1</span><div><strong>Enter your details</strong><span>Use your name and the correct subject or course.</span></div></div>
                <div class="step"><span class="step-number">2</span><div><strong>Choose your assessment</strong><span>Confirm the class or period shown for your institution.</span></div></div>
                <div class="step"><span class="step-number">3</span><div><strong>Review before starting</strong><span>The next screen shows your details and exam instructions.</span></div></div>
                <div class="privacy-note">Please check your name and assessment details before continuing. You will be able to review the exam information before entering the exam hall.</div>
            </div>
        </aside>
    </div>
    <div class="registration-footer">Examcenter assessment portal</div>
</main>
<script>
(() => {
    const form = document.getElementById('registrationForm');
    if (!form) return;
    const filterIds = ['subject', 'level', 'year', 'period', 'unit'];
    const assessment = document.getElementById('test_id');
    const filterElements = filterIds.map(id => document.getElementById(id)).filter(Boolean);
    const updateAssessments = () => {
        const filters = Object.fromEntries(filterElements.map(el => [el.id, el.value]));
        let selectedStillAvailable = false;
        for (const option of assessment.options) {
            if (!option.value) continue;
            const matches = (!filters.subject || option.dataset.subject === filters.subject)
                && (!filters.level || option.dataset.level === filters.level)
                && (!filters.year || option.dataset.year === filters.year)
                && (!filters.period || option.dataset.period === filters.period)
                && (!filters.unit || option.dataset.unit === filters.unit);
            option.hidden = !matches;
            if (matches && option.selected) selectedStillAvailable = true;
        }
        if (!selectedStillAvailable) assessment.value = '';
    };
    filterElements.forEach(el => el.addEventListener('change', updateAssessments));
    updateAssessments();
})();
</script>
</body>
</html>
<?php $conn->close(); ?>
