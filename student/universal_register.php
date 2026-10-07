<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/universal_architecture.php';

$conn = Database::connection();
$error = '';
$tests = [];

$institutionId = examcenterActiveInstitutionId($conn);
if ($institutionId === null) {
    exit('No active institution has been configured. Complete Universal Institution Setup first.');
}

$stmt = $conn->prepare(
    "SELECT
        t.id,
        t.title,
        t.subject,
        t.year,
        t.duration,
        t.organizational_unit_id,
        ou.name AS unit_name,
        c.name AS course_name,
        ap.name AS period_name
     FROM tests t
     LEFT JOIN organizational_units ou ON ou.id=t.organizational_unit_id
     LEFT JOIN courses c ON c.id=t.course_id
     LEFT JOIN academic_periods ap ON ap.id=t.academic_period_id
     WHERE t.institution_id=?
       AND t.title IS NOT NULL
       AND t.title <> ''
     ORDER BY t.year DESC, t.title ASC"
);
$stmt->bind_param('i', $institutionId);
$stmt->execute();
$tests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid request token.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $testId = (int)($_POST['test_id'] ?? 0);

        if ($name === '' || $testId <= 0) {
            $error = 'Candidate name and assessment are required.';
        } else {
            $stmt = $conn->prepare(
                "SELECT
                    t.id, t.title, t.subject, t.year,
                    t.organizational_unit_id, t.course_id, t.academic_period_id
                 FROM tests t
                 WHERE t.id=? AND t.institution_id=?
                 LIMIT 1"
            );
            $stmt->bind_param('ii', $testId, $institutionId);
            $stmt->execute();
            $test = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$test) {
                $error = 'The selected assessment is not available.';
            } else {
                $conn->begin_transaction();
                try {
                    // Keep the legacy student row as a compatibility identity.
                    $legacyClass = !empty($test['organizational_unit_id'])
                        ? (string)$test['organizational_unit_id']
                        : 'UNIVERSAL';

                    $stmt = $conn->prepare(
                        "INSERT INTO students (full_name,class) VALUES (?,?)"
                    );
                    $stmt->bind_param('ss', $name, $legacyClass);
                    $stmt->execute();
                    $studentId = (int)$conn->insert_id;
                    $stmt->close();

                    $externalRef = 'LEGACY-STUDENT-' . $studentId;
                    $stmt = $conn->prepare(
                        "INSERT INTO people
                            (institution_id,display_name,external_ref,is_active)
                         VALUES (?,?,?,1)"
                    );
                    $stmt->bind_param('iss', $institutionId, $name, $externalRef);
                    $stmt->execute();
                    $personId = (int)$conn->insert_id;
                    $stmt->close();

                    $stmt = $conn->prepare(
                        "INSERT INTO institution_memberships
                            (institution_id,person_id,role_code,legacy_student_id,status)
                         VALUES (?,?, 'student',?,'active')"
                    );
                    $stmt->bind_param('iii', $institutionId, $personId, $studentId);
                    $stmt->execute();
                    $membershipId = (int)$conn->insert_id;
                    $stmt->close();

                    if (!empty($test['organizational_unit_id'])) {
                        $unitId = (int)$test['organizational_unit_id'];
                        $stmt = $conn->prepare(
                            "INSERT IGNORE INTO unit_memberships
                                (unit_id,person_id,membership_role)
                             VALUES (?,?,'student')"
                        );
                        $stmt->bind_param('ii', $unitId, $personId);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $_SESSION['student_id'] = $studentId;
                    $_SESSION['student_name'] = $name;
                    $_SESSION['student_class'] = $legacyClass;
                    $_SESSION['student_subject'] = (string)$test['subject'];
                    $_SESSION['test_title'] = (string)$test['title'];
                    $_SESSION['exam_year'] = (string)$test['year'];
                    $_SESSION['current_test_id'] = $testId;
                    $_SESSION['institution_membership_id'] = $membershipId;

                    $conn->commit();
                    header('Location: take_exam.php');
                    exit;
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('Universal registration failed: ' . $e->getMessage());
                    $error = 'Registration failed. Please try again.';
                }
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
<title>Candidate Registration | Examcenter</title>
<link href="../css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h3 class="mb-1">Candidate Registration</h3>
                    <small class="text-muted">Universal Assessment Mode</small>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <?php if (!$tests): ?>
                        <div class="alert alert-warning">
                            No assessments are currently available for this institution.
                        </div>
                    <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="mb-3">
                            <label class="form-label">Candidate Name</label>
                            <input type="text" name="name" class="form-control" required maxlength="150">
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Assessment</label>
                            <select name="test_id" class="form-select" required>
                                <option value="">Select assessment</option>
                                <?php foreach ($tests as $test): ?>
                                    <option value="<?= (int)$test['id'] ?>">
                                        <?= htmlspecialchars($test['title']) ?>
                                        — <?= htmlspecialchars((string)$test['subject']) ?>
                                        <?php if ($test['course_name']): ?> / <?= htmlspecialchars($test['course_name']) ?><?php endif; ?>
                                        <?php if ($test['unit_name']): ?> / <?= htmlspecialchars($test['unit_name']) ?><?php endif; ?>
                                        <?php if ($test['period_name']): ?> / <?= htmlspecialchars($test['period_name']) ?><?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button class="btn btn-primary w-100">Start Assessment</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
