<?php
declare(strict_types=1);

/**
 * Legacy -> Universal data backfill.
 *
 * Important: the legacy schema does not contain a school/institution foreign key
 * on students, teachers, classes or subjects. Therefore legacy people/classes
 * are mapped to the first legacy school/institution when multiple schools exist.
 * This is explicit and deterministic; it does not duplicate every legacy record
 * into every institution.
 */

require_once __DIR__ . '/../db.php';

$db = Database::connection();
$db->begin_transaction();

try {
    $type = $db->query("SELECT id FROM institution_types WHERE code='secondary_school' LIMIT 1")->fetch_assoc();
    $unitType = $db->query("SELECT id FROM organizational_unit_types WHERE code='class' LIMIT 1")->fetch_assoc();
    if (!$type || !$unitType) {
        throw new RuntimeException('Universal migration has not been applied.');
    }

    $typeId = (int)$type['id'];
    $unitTypeId = (int)$unitType['id'];

    // 1. Backfill institutions from legacy schools.
    $schools = $db->query("SELECT id, school_name FROM schools ORDER BY id");
    $institutionIds = [];
    while ($school = $schools->fetch_assoc()) {
        $schoolId = (int)$school['id'];
        $name = trim((string)$school['school_name']) ?: 'Legacy Institution ' . $schoolId;
        $code = 'LEGACY-SCHOOL-' . $schoolId;

        $stmt = $db->prepare("SELECT id FROM institutions WHERE code=? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $institution = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$institution) {
            $stmt = $db->prepare(
                "INSERT INTO institutions (institution_type_id,name,code)
                 VALUES (?,?,?)"
            );
            $stmt->bind_param('iss', $typeId, $name, $code);
            $stmt->execute();
            $institutionId = (int)$db->insert_id;
            $stmt->close();
        } else {
            $institutionId = (int)$institution['id'];
        }

        $institutionIds[$schoolId] = $institutionId;
    }

    if (!$institutionIds) {
        // A legacy installation may not have a schools row yet.
        $stmt = $db->prepare(
            "SELECT id FROM institutions
             WHERE institution_type_id = ?
             ORDER BY id LIMIT 1"
        );
        $stmt->bind_param('i', $typeId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $institutionIds[0] = (int)$existing['id'];
        } else {
            $name = 'Legacy Institution';
            $code = 'LEGACY-INSTITUTION';
            $stmt = $db->prepare(
                "INSERT INTO institutions (institution_type_id,name,code)
                 VALUES (?,?,?)"
            );
            $stmt->bind_param('iss', $typeId, $name, $code);
            $stmt->execute();
            $institutionIds[0] = (int)$db->insert_id;
            $stmt->close();
        }
    }

    // Legacy people have no school_id, so use one deterministic owner.
    $institutionId = (int)reset($institutionIds);

    // 2. Academic years -> universal academic periods.
    $years = $db->query(
        "SELECT id, year, session, status
         FROM academic_years
         ORDER BY id"
    );
    $periodMap = [];
    while ($row = $years->fetch_assoc()) {
        $legacyId = (int)$row['id'];
        $code = 'LEGACY-YEAR-' . $legacyId;
        $name = trim((string)$row['year']);
        if ((string)$row['session'] !== '') $name .= ' / ' . trim((string)$row['session']);
        if ($name === '') $name = $code;
        $status = ((string)$row['status'] === 'active') ? 'active' : 'inactive';

        $stmt = $db->prepare(
            "SELECT id FROM academic_periods
             WHERE institution_id=? AND code=? LIMIT 1"
        );
        $stmt->bind_param('is', $institutionId, $code);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $periodMap[$legacyId] = (int)$existing['id'];
            continue;
        }

        $metadata = json_encode(
            ['legacy_academic_year_id' => $legacyId],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';

        $stmt = $db->prepare(
            "INSERT INTO academic_periods
                (institution_id,code,name,period_type,status,metadata)
             VALUES (?,?,?,'academic_year',?,?)"
        );
        $stmt->bind_param('issss', $institutionId, $code, $name, $status, $metadata);
        $stmt->execute();
        $periodMap[$legacyId] = (int)$db->insert_id;
        $stmt->close();
    }

    // 3. Legacy subjects -> Universal courses.
    $subjects = $db->query("SELECT id, subject_name FROM subjects ORDER BY id");
    $courseMap = [];
    while ($subject = $subjects->fetch_assoc()) {
        $legacyId = (int)$subject['id'];
        $name = trim((string)$subject['subject_name']);
        $code = 'LEGACY-SUBJECT-' . $legacyId;

        $stmt = $db->prepare(
            "SELECT id FROM courses
             WHERE institution_id=? AND code=? LIMIT 1"
        );
        $stmt->bind_param('is', $institutionId, $code);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $courseMap[$legacyId] = (int)$existing['id'];
            continue;
        }

        $type = 'subject';
        $stmt = $db->prepare(
            "INSERT INTO courses
                (institution_id,code,name,course_type,legacy_subject_id)
             VALUES (?,?,?,?,?)"
        );
        $stmt->bind_param('isssi', $institutionId, $code, $name, $type, $legacyId);
        $stmt->execute();
        $courseMap[$legacyId] = (int)$db->insert_id;
        $stmt->close();
    }

    // 4. Legacy classes -> Universal organisational units.
    $classMap = [];
    $classes = $db->query("SELECT id, class_name FROM classes ORDER BY id");
    while ($class = $classes->fetch_assoc()) {
        $legacyId = (int)$class['id'];
        $name = trim((string)$class['class_name']);
        $code = 'LEGACY-CLASS-' . $legacyId;

        $stmt = $db->prepare(
            "SELECT id FROM organizational_units
             WHERE institution_id=? AND legacy_class_id=? LIMIT 1"
        );
        $stmt->bind_param('ii', $institutionId, $legacyId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $classMap[$legacyId] = (int)$existing['id'];
            continue;
        }

        $stmt = $db->prepare(
            "INSERT INTO organizational_units
                (institution_id,unit_type_id,legacy_class_id,name,code)
             VALUES (?,?,?,?,?)"
        );
        $stmt->bind_param('iiiss', $institutionId, $unitTypeId, $legacyId, $name, $code);
        $stmt->execute();
        $classMap[$legacyId] = (int)$db->insert_id;
        $stmt->close();
    }

    // 5. Students -> people + institution/unit memberships.
    $students = $db->query(
        "SELECT id, full_name, email, phone, photo, class
         FROM students ORDER BY id"
    );
    while ($student = $students->fetch_assoc()) {
        $legacyId = (int)$student['id'];
        $external = 'LEGACY-STUDENT-' . $legacyId;
        $display = trim((string)$student['full_name']) ?: 'Student ' . $legacyId;

        $stmt = $db->prepare(
            "SELECT id FROM people
             WHERE institution_id=? AND external_ref=? LIMIT 1"
        );
        $stmt->bind_param('is', $institutionId, $external);
        $stmt->execute();
        $person = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($person) {
            $personId = (int)$person['id'];
        } else {
            $stmt = $db->prepare(
                "INSERT INTO people
                    (institution_id,display_name,email,phone,external_ref,photo_path)
                 VALUES (?,?,?,?,?,?)"
            );
            $photo = $student['photo'] ?: null;
            $email = $student['email'] ?: null;
            $phone = $student['phone'] ?: null;
            $stmt->bind_param('isssss', $institutionId, $display, $email, $phone, $external, $photo);
            $stmt->execute();
            $personId = (int)$db->insert_id;
            $stmt->close();
        }

        $stmt = $db->prepare(
            "SELECT id FROM institution_memberships
             WHERE institution_id=? AND person_id=? AND role_code='student' LIMIT 1"
        );
        $stmt->bind_param('ii', $institutionId, $personId);
        $stmt->execute();
        $membership = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$membership) {
            $role = 'student';
            $stmt = $db->prepare(
                "INSERT INTO institution_memberships
                    (institution_id,person_id,role_code,legacy_student_id)
                 VALUES (?,?,?,?)"
            );
            $stmt->bind_param('iisi', $institutionId, $personId, $role, $legacyId);
            $stmt->execute();
            $stmt->close();
        }

        $legacyClassId = is_numeric((string)$student['class'])
            ? (int)$student['class']
            : 0;
        if ($legacyClassId > 0 && isset($classMap[$legacyClassId])) {
            $unitId = $classMap[$legacyClassId];
            $stmt = $db->prepare(
                "INSERT IGNORE INTO unit_memberships
                    (unit_id,person_id,membership_role)
                 VALUES (?,?,'student')"
            );
            $stmt->bind_param('ii', $unitId, $personId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // 6. Teachers -> people + institution memberships.
    $teachers = $db->query(
        "SELECT id, first_name, last_name, email, phone
         FROM teachers ORDER BY id"
    );
    $teacherPeople = [];
    while ($teacher = $teachers->fetch_assoc()) {
        $legacyId = (int)$teacher['id'];
        $external = 'LEGACY-TEACHER-' . $legacyId;
        $display = trim((string)$teacher['first_name'] . ' ' . (string)$teacher['last_name']);
        if ($display === '') $display = 'Teacher ' . $legacyId;

        $stmt = $db->prepare(
            "SELECT id FROM people
             WHERE institution_id=? AND external_ref=? LIMIT 1"
        );
        $stmt->bind_param('is', $institutionId, $external);
        $stmt->execute();
        $person = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($person) {
            $personId = (int)$person['id'];
        } else {
            $stmt = $db->prepare(
                "INSERT INTO people
                    (institution_id,display_name,email,phone,external_ref)
                 VALUES (?,?,?,?,?)"
            );
            $email = $teacher['email'] ?: null;
            $phone = $teacher['phone'] ?: null;
            $stmt->bind_param('issss', $institutionId, $display, $email, $phone, $external);
            $stmt->execute();
            $personId = (int)$db->insert_id;
            $stmt->close();
        }

        $teacherPeople[$legacyId] = $personId;

        $stmt = $db->prepare(
            "INSERT IGNORE INTO institution_memberships
                (institution_id,person_id,role_code,legacy_teacher_id)
             VALUES (?,?, 'teacher',?)"
        );
        $stmt->bind_param('iii', $institutionId, $personId, $legacyId);
        $stmt->execute();
        $stmt->close();
    }

    // 7. Legacy teacher_subjects -> Universal course assignments.
    if (isset($courseMap[1]) || $db->query("SHOW TABLES LIKE 'teacher_subjects'")->num_rows > 0) {
        $assignments = $db->query(
            "SELECT teacher_id, subject FROM teacher_subjects ORDER BY teacher_id"
        );
        while ($assignment = $assignments->fetch_assoc()) {
            $teacherId = (int)$assignment['teacher_id'];
            if (!isset($teacherPeople[$teacherId])) continue;

            $subjectName = trim((string)$assignment['subject']);
            $stmt = $db->prepare(
                "SELECT s.id
                 FROM subjects s
                 WHERE LOWER(TRIM(s.subject_name)) = LOWER(TRIM(?))
                 LIMIT 1"
            );
            $stmt->bind_param('s', $subjectName);
            $stmt->execute();
            $subject = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$subject) continue;

            $legacySubjectId = (int)$subject['id'];
            if (!isset($courseMap[$legacySubjectId])) continue;

            $courseId = $courseMap[$legacySubjectId];
            $personId = $teacherPeople[$teacherId];

            $stmt = $db->prepare(
                "INSERT IGNORE INTO course_assignments
                    (course_id,person_id,role_code)
                 VALUES (?,?,'teacher')"
            );
            $stmt->bind_param('ii', $courseId, $personId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $db->commit();
    echo "Universal legacy backfill completed successfully." . PHP_EOL;
    echo "Legacy records were mapped to institution ID {$institutionId} where no legacy school FK exists." . PHP_EOL;
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Backfill failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
