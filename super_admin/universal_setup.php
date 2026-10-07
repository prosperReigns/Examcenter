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
$userId = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("SELECT role FROM super_admins WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin || strtolower((string)$admin['role']) !== 'super_admin') {
    session_destroy();
    header('Location: ../login.php?error=Unauthorized');
    exit;
}

if (!examcenterUniversalTableExists($conn, 'institutions')) {
    exit('Universal Architecture migration is required before system setup.');
}

$errors = [];
$success = '';

$institutionTypes = [];
$result = $conn->query(
    "SELECT id, code, name
     FROM institution_types
     WHERE is_active = 1
     ORDER BY name"
);
if ($result) $institutionTypes = $result->fetch_all(MYSQLI_ASSOC);

$unitTypes = [];
$result = $conn->query(
    "SELECT id, code, name
     FROM organizational_unit_types
     WHERE is_active = 1
     ORDER BY name"
);
if ($result) $unitTypes = $result->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        $conn->begin_transaction();

        if ($action === 'create_structure') {
            $institutionTypeId = (int)($_POST['institution_type_id'] ?? 0);
            $institutionName = trim((string)($_POST['institution_name'] ?? ''));
            $institutionCode = strtoupper(trim((string)($_POST['institution_code'] ?? '')));
            $timezone = trim((string)($_POST['timezone'] ?? 'Africa/Lagos')) ?: 'UTC';
            $country = strtoupper(trim((string)($_POST['country_code'] ?? '')));
            $unitTypeId = (int)($_POST['unit_type_id'] ?? 0);
            $unitName = trim((string)($_POST['unit_name'] ?? ''));
            $unitCode = strtoupper(trim((string)($_POST['unit_code'] ?? '')));
            $programmeName = trim((string)($_POST['programme_name'] ?? ''));
            $programmeCode = strtoupper(trim((string)($_POST['programme_code'] ?? '')));
            $levelName = trim((string)($_POST['level_name'] ?? ''));
            $levelCode = strtoupper(trim((string)($_POST['level_code'] ?? '')));
            $periodName = trim((string)($_POST['period_name'] ?? ''));
            $periodCode = strtoupper(trim((string)($_POST['period_code'] ?? '')));
            $courseName = trim((string)($_POST['course_name'] ?? ''));
            $courseCode = strtoupper(trim((string)($_POST['course_code'] ?? '')));

            if ($institutionTypeId <= 0 || $institutionName === '') {
                throw new RuntimeException('Institution type and institution name are required.');
            }

            $stmt = $conn->prepare(
                "SELECT id FROM institutions
                 WHERE (code = ? AND ? <> '') OR name = ?
                 ORDER BY id LIMIT 1"
            );
            $stmt->bind_param('sss', $institutionCode, $institutionCode, $institutionName);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                $institutionId = (int)$existing['id'];
                $stmt = $conn->prepare(
                    "UPDATE institutions
                     SET institution_type_id=?, timezone=?, country_code=?, is_active=1
                     WHERE id=?"
                );
                $stmt->bind_param('issi', $institutionTypeId, $timezone, $country, $institutionId);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO institutions
                        (institution_type_id,name,code,timezone,country_code)
                     VALUES (?,?,?,?,?)"
                );
                $codeValue = $institutionCode !== '' ? $institutionCode : null;
                $countryValue = $country !== '' ? $country : null;
                $stmt->bind_param('issss', $institutionTypeId, $institutionName, $codeValue, $timezone, $countryValue);
                $stmt->execute();
                $institutionId = (int)$conn->insert_id;
                $stmt->close();
            }

            if ($unitTypeId > 0 && $unitName !== '') {
                $stmt = $conn->prepare(
                    "SELECT id FROM organizational_units
                     WHERE institution_id=? AND ((code=? AND ? <> '') OR name=?)
                     LIMIT 1"
                );
                $stmt->bind_param('isss', $institutionId, $unitCode, $unitCode, $unitName);
                $stmt->execute();
                $unit = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($unit) {
                    $unitId = (int)$unit['id'];
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO organizational_units
                            (institution_id,unit_type_id,name,code)
                         VALUES (?,?,?,NULLIF(?,''))"
                    );
                    $stmt->bind_param('iiss', $institutionId, $unitTypeId, $unitName, $unitCode);
                    $stmt->execute();
                    $unitId = (int)$conn->insert_id;
                    $stmt->close();
                }
            } else {
                $unitId = null;
            }

            $programmeId = null;
            if ($programmeName !== '') {
                $programmeCode = $programmeCode !== '' ? $programmeCode : 'PROGRAMME-' . time();
                $stmt = $conn->prepare(
                    "SELECT id FROM programmes WHERE institution_id=? AND code=? LIMIT 1"
                );
                $stmt->bind_param('is', $institutionId, $programmeCode);
                $stmt->execute();
                $programme = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($programme) {
                    $programmeId = (int)$programme['id'];
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO programmes (institution_id,code,name) VALUES (?,?,?)"
                    );
                    $stmt->bind_param('iss', $institutionId, $programmeCode, $programmeName);
                    $stmt->execute();
                    $programmeId = (int)$conn->insert_id;
                    $stmt->close();
                }
            }

            if ($programmeId && $levelName !== '') {
                $levelCode = $levelCode !== '' ? $levelCode : 'LEVEL-' . time();
                $stmt = $conn->prepare(
                    "SELECT id FROM programme_levels
                     WHERE programme_id=? AND code=? LIMIT 1"
                );
                $stmt->bind_param('is', $programmeId, $levelCode);
                $stmt->execute();
                $level = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$level) {
                    $stmt = $conn->prepare(
                        "INSERT INTO programme_levels
                            (programme_id,organizational_unit_id,code,name)
                         VALUES (?,?,?,?,?)"
                    );
                    $unitForLevel = $unitId ?: null;
                    $stmt->bind_param('iisss', $programmeId, $unitForLevel, $levelCode, $levelName);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if ($periodName !== '') {
                $periodCode = $periodCode !== '' ? $periodCode : 'PERIOD-' . time();
                $stmt = $conn->prepare(
                    "INSERT IGNORE INTO academic_periods
                        (institution_id,code,name,period_type,status)
                     VALUES (?,?,?,'academic_year','active')"
                );
                $stmt->bind_param('iss', $institutionId, $periodCode, $periodName);
                $stmt->execute();
                $stmt->close();
            }

            if ($courseName !== '') {
                $courseCode = $courseCode !== '' ? $courseCode : 'COURSE-' . time();
                $stmt = $conn->prepare(
                    "INSERT IGNORE INTO courses
                        (institution_id,programme_id,code,name,course_type)
                     VALUES (?,?,?,?,'subject')"
                );
                $stmt->bind_param('iiss', $institutionId, $programmeId, $courseCode, $courseName);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("SELECT id FROM system_settings ORDER BY id LIMIT 1");
            $stmt->execute();
            $settings = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($settings) {
                $settingsId = (int)$settings['id'];
                $stmt = $conn->prepare(
                    "UPDATE system_settings
                     SET setup_completed=1, setup_completed_at=NOW(), setup_by=?
                     WHERE id=?"
                );
                $stmt->bind_param('ii', $userId, $settingsId);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO system_settings
                        (setup_completed,setup_completed_at,setup_by)
                     VALUES (1,NOW(),?)"
                );
                $stmt->bind_param('i', $userId);
            }
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $success = 'Universal institution structure saved successfully.';
        } else {
            throw new RuntimeException('Unknown setup action.');
        }
    } catch (Throwable $e) {
        $conn->rollback();
        $errors[] = $e->getMessage();
    }
}

$existingInstitution = $conn->query(
    "SELECT i.id, i.name, i.code, it.name AS institution_type
     FROM institutions i
     INNER JOIN institution_types it ON it.id=i.institution_type_id
     WHERE i.is_active=1
     ORDER BY i.id LIMIT 1"
)->fetch_assoc();

$existingUnits = [];
if ($existingInstitution) {
    $stmt = $conn->prepare(
        "SELECT ou.name, ou.code, ut.name AS type_name
         FROM organizational_units ou
         INNER JOIN organizational_unit_types ut ON ut.id=ou.unit_type_id
         WHERE ou.institution_id=? AND ou.is_active=1
         ORDER BY ou.sort_order, ou.name"
    );
    $institutionId = (int)$existingInstitution['id'];
    $stmt->bind_param('i', $institutionId);
    $stmt->execute();
    $existingUnits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Universal Institution Setup | Examcenter</title>
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/all.css">
<link rel="stylesheet" href="../css/admin-dashboard.css">
</head>
<body>
<div class="container py-5">
    <div class="mb-4">
        <h1>Universal Institution Setup</h1>
        <p class="text-muted mb-0">
            Configure the organisation using institution, units, programmes, levels,
            academic periods and courses. No school-specific class model is required.
        </p>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($existingInstitution): ?>
        <div class="alert alert-info">
            Active institution: <strong><?= htmlspecialchars($existingInstitution['name']) ?></strong>
            (<?= htmlspecialchars($existingInstitution['institution_type']) ?>)
        </div>
    <?php endif; ?>

    <form method="post" class="card shadow-sm">
        <div class="card-body">
            <input type="hidden" name="action" value="create_structure">

            <h4 class="mb-3">1. Institution</h4>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Institution Type</label>
                    <select name="institution_type_id" class="form-select" required>
                        <option value="">Select type</option>
                        <?php foreach ($institutionTypes as $type): ?>
                            <option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Institution Name</label>
                    <input name="institution_name" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Institution Code</label>
                    <input name="institution_code" class="form-control" placeholder="Optional">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Timezone</label>
                    <input name="timezone" class="form-control" value="Africa/Lagos">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Country Code</label>
                    <input name="country_code" class="form-control" maxlength="2" placeholder="NG">
                </div>
            </div>

            <h4 class="mb-3">2. First organisational unit</h4>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label">Unit Type</label>
                    <select name="unit_type_id" class="form-select">
                        <option value="">Optional</option>
                        <?php foreach ($unitTypes as $type): ?>
                            <option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Unit Name</label>
                    <input name="unit_name" class="form-control" placeholder="e.g. Faculty of Science, Grade 6, Engineering">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Unit Code</label>
                    <input name="unit_code" class="form-control">
                </div>
            </div>

            <h4 class="mb-3">3. Programme and level</h4>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Programme Name</label>
                    <input name="programme_name" class="form-control" placeholder="Optional">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Programme Code</label>
                    <input name="programme_code" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Level Code</label>
                    <input name="level_code" class="form-control">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Level Name</label>
                    <input name="level_name" class="form-control" placeholder="e.g. Year 1, Grade 6, 100 Level, Apprentice">
                </div>
            </div>

            <h4 class="mb-3">4. Academic period</h4>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Period Name</label>
                    <input name="period_name" class="form-control" placeholder="e.g. 2026/2027">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Period Code</label>
                    <input name="period_code" class="form-control" placeholder="e.g. 2026-2027">
                </div>
            </div>

            <h4 class="mb-3">5. First course / subject</h4>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Course Name</label>
                    <input name="course_name" class="form-control" placeholder="e.g. Mathematics, Computer Science, Workplace Safety">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Course Code</label>
                    <input name="course_code" class="form-control">
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard</a>
            <button class="btn btn-primary">Save Universal Structure</button>
        </div>
    </form>
</div>
<script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>
