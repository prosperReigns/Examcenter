<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$db = Database::connection();
$db->begin_transaction();

try {
    $type = $db->query("SELECT id FROM institution_types WHERE code='secondary_school' LIMIT 1")->fetch_assoc();
    if (!$type) throw new RuntimeException('Universal migration has not been applied.');
    $typeId = (int)$type['id'];

    $schools = $db->query("SELECT id, school_name FROM schools ORDER BY id");
    while ($school = $schools->fetch_assoc()) {
        $schoolId = (int)$school['id'];
        $name = trim((string)$school['school_name']);
        $code = 'LEGACY-SCHOOL-' . $schoolId;

        $stmt = $db->prepare("SELECT id FROM institutions WHERE code=? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $institution = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$institution) {
            $stmt = $db->prepare("INSERT INTO institutions (institution_type_id,name,code) VALUES (?,?,?)");
            $stmt->bind_param('iss', $typeId, $name, $code);
            $stmt->execute();
            $institutionId = $db->insert_id;
            $stmt->close();
        } else {
            $institutionId = (int)$institution['id'];
        }

        $unitType = $db->query("SELECT id FROM organizational_unit_types WHERE code='class' LIMIT 1")->fetch_assoc();
        $unitTypeId = (int)$unitType['id'];

        $classes = $db->query("SELECT id, class_name FROM classes ORDER BY id");
        while ($class = $classes->fetch_assoc()) {
            $legacyClassId = (int)$class['id'];
            $className = (string)$class['class_name'];

            $stmt = $db->prepare("SELECT id FROM organizational_units WHERE institution_id=? AND legacy_class_id=? LIMIT 1");
            $stmt->bind_param('ii', $institutionId, $legacyClassId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$exists) {
                $stmt = $db->prepare("INSERT INTO organizational_units (institution_id,unit_type_id,legacy_class_id,name,code) VALUES (?,?,?,?,?)");
                $classCode = 'LEGACY-CLASS-' . $legacyClassId;
                $stmt->bind_param('iiiss', $institutionId, $unitTypeId, $legacyClassId, $className, $classCode);
                $stmt->execute();
                $stmt->close();
            }
        }

        $people = $db->query("SELECT id, full_name, email, phone, photo FROM students ORDER BY id");
        while ($student = $people->fetch_assoc()) {
            $legacyId = (int)$student['id'];
            $external = 'LEGACY-STUDENT-' . $legacyId;
            $display = (string)$student['full_name'];

            $stmt = $db->prepare("SELECT id FROM people WHERE institution_id=? AND external_ref=? LIMIT 1");
            $stmt->bind_param('is', $institutionId, $external);
            $stmt->execute();
            $person = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$person) {
                $stmt = $db->prepare("INSERT INTO people (institution_id,display_name,email,phone,external_ref,photo_path) VALUES (?,?,?,?,?,?)");
                $photo = $student['photo'] ?: null;
                $stmt->bind_param('isssss', $institutionId, $display, $student['email'], $student['phone'], $external, $photo);
                $stmt->execute();
                $personId = $db->insert_id;
                $stmt->close();
            } else {
                $personId = (int)$person['id'];
            }

            $stmt = $db->prepare("SELECT id FROM institution_memberships WHERE institution_id=? AND person_id=? AND role_code='student' LIMIT 1");
            $stmt->bind_param('ii', $institutionId, $personId);
            $stmt->execute();
            $membership = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$membership) {
                $stmt = $db->prepare("INSERT INTO institution_memberships (institution_id,person_id,role_code,legacy_student_id) VALUES (?,?,?,?)");
                $role = 'student';
                $stmt->bind_param('iisi', $institutionId, $personId, $role, $legacyId);
                $stmt->execute();
                $stmt->close();
            }
        }

        $teachers = $db->query("SELECT id, first_name, last_name, email, phone FROM teachers ORDER BY id");
        while ($teacher = $teachers->fetch_assoc()) {
            $legacyId = (int)$teacher['id'];
            $external = 'LEGACY-TEACHER-' . $legacyId;
            $display = trim($teacher['first_name'] . ' ' . $teacher['last_name']);

            $stmt = $db->prepare("SELECT id FROM people WHERE institution_id=? AND external_ref=? LIMIT 1");
            $stmt->bind_param('is', $institutionId, $external);
            $stmt->execute();
            $person = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$person) {
                $stmt = $db->prepare("INSERT INTO people (institution_id,display_name,email,phone,external_ref) VALUES (?,?,?,?,?)");
                $stmt->bind_param('issss', $institutionId, $display, $teacher['email'], $teacher['phone'], $external);
                $stmt->execute();
                $personId = $db->insert_id;
                $stmt->close();
            } else {
                $personId = (int)$person['id'];
            }

            $stmt = $db->prepare("SELECT id FROM institution_memberships WHERE institution_id=? AND person_id=? AND role_code='teacher' LIMIT 1");
            $stmt->bind_param('ii', $institutionId, $personId);
            $stmt->execute();
            $membership = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$membership) {
                $stmt = $db->prepare("INSERT INTO institution_memberships (institution_id,person_id,role_code,legacy_teacher_id) VALUES (?,?,?,?)");
                $role = 'teacher';
                $stmt->bind_param('iisi', $institutionId, $personId, $role, $legacyId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    $db->commit();
    echo "Universal legacy backfill completed." . PHP_EOL;
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Backfill failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
