<?php
session_start();

require_once "../db.php";
require_once "../includes/system_guard.php";
require_once "../includes/universal_architecture.php";
//require_once '../license/license_guard.php';

$teacher_id = (int)($_SESSION['user_id'] ?? 0);

if ($teacher_id <= 0) {
    $_SESSION['error'] = "Invalid user session.";
    Header("Location: bank.php");
    Exit();
}

if (empty($_POST['questions'])) {
    $_SESSION['error'] = "Please select at least one question.";
    Header("Location: bank.php");
    Exit();
}

/*
 * Determine which test we're adding to.
 *
 * A teacher can either:
 *   - explicitly pick a test from the dropdown on bank.php
 *     (target_test_id), or
 *   - rely on the "current test" stored in their session.
 *
 * target_test_id, when present, always wins.
 */

$target_test_id = isset($_POST['target_test_id']) ? (int)$_POST['target_test_id'] : 0;

if ($target_test_id > 0) {
    $test_id = $target_test_id;
} elseif (isset($_SESSION['current_test_id'])) {
    $test_id = (int)$_SESSION['current_test_id'];
} else {
    $_SESSION['error'] = "Please create or select a test first.";
    Header("Location: bank.php");
    Exit();
}

$database = Database::getInstance();
$conn = $database->getConnection();

$universal_test_scope = examcenterUniversalTableExists($conn, 'institutions')
    && examcenterUniversalColumnExists($conn, 'tests', 'institution_id')
    && examcenterUniversalColumnExists($conn, 'tests', 'organizational_unit_id')
    && examcenterUniversalColumnExists($conn, 'tests', 'course_id')
    && examcenterUniversalColumnExists($conn, 'tests', 'academic_period_id');

$active_institution_id = $universal_test_scope
    ? examcenterActiveInstitutionId($conn)
    : null;
$institution_profile = examcenterInstitutionProfile($conn);
$is_school_institution = $active_institution_id === null || in_array(
    $institution_profile['category'],
    ['primary_school', 'secondary_school'],
    true
);

/*
 * Fetch the subjects this teacher is assigned to, so we can
 * confirm they're actually allowed to touch the chosen test
 * and the bank questions being copied.
 */

$stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE teacher_id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$assigned_subjects = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'subject');
$stmt->close();
$assigned_courses = (!$is_school_institution && $active_institution_id)
    ? examcenterTeacherCourseAssignments($conn, $teacher_id, $active_institution_id)
    : [];
if (!$is_school_institution) {
    $assigned_subjects = array_values(array_unique(array_map(
        static fn($assignment) => trim((string)$assignment['course_name']),
        $assigned_courses
    )));
}
$teacher_test_values = $is_school_institution
    ? $assigned_subjects
    : array_values(array_unique(array_map(static fn($assignment) => (int)$assignment['course_id'], $assigned_courses)));
$teacher_test_column = $is_school_institution ? 'subject' : 'course_id';
$teacher_test_types = str_repeat($is_school_institution ? 's' : 'i', count($teacher_test_values));

$conn->begin_transaction();

try {

    /*
     * Get target test details, scoped to a subject this
     * teacher is actually assigned to.
     */

    if (empty($assigned_subjects)) {
        throw new Exception("You are not assigned to any subjects.");
    }

    if (empty($teacher_test_values)) throw new Exception('You are not assigned to any eligible subjects or courses.');
    $placeholders = implode(',', array_fill(0, count($teacher_test_values), '?'));

    $test_context_select = $universal_test_scope
        ? ", institution_id, organizational_unit_id, course_id, academic_period_id"
        : '';
    $test_scope_condition = $universal_test_scope
        ? ' AND institution_id = ?'
        : '';

    $stmt = $conn->prepare("
        SELECT academic_level_id, subject{$test_context_select}
        FROM tests
        WHERE id = ?
        AND {$teacher_test_column} IN ($placeholders)
        {$test_scope_condition}
    ");

    $types = 'i' . $teacher_test_types;
    $params = array_merge([$test_id], $teacher_test_values);
    if ($universal_test_scope) {
        if ($active_institution_id === null) {
            throw new Exception("No active institution is configured.");
        }
        $types .= 'i';
        $params[] = $active_institution_id;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $test = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$test) {
        throw new Exception("Invalid test, or you don't have access to it.");
    }

    $question_context_available = $active_institution_id !== null
        && examcenterUniversalTableExists($conn, 'courses')
        && examcenterUniversalTableExists($conn, 'organizational_units')
        && examcenterUniversalTableExists($conn, 'academic_periods')
        && examcenterUniversalColumnExists($conn, 'new_questions', 'course_id')
        && examcenterUniversalColumnExists($conn, 'new_questions', 'organizational_unit_id')
        && examcenterUniversalColumnExists($conn, 'new_questions', 'academic_period_id');
    if (!$is_school_institution && !$question_context_available) {
        throw new Exception('Apply the ADDED question-bank migration in help.txt before copying universal bank questions.');
    }

    // Remember this as the active test for subsequent visits.
    $_SESSION['current_test_id'] = $test_id;

    foreach ($_POST['questions'] as $bank_id) {

        $bank_id = (int)$bank_id;

        /*
         * Read question from bank, scoped to the requesting
         * teacher so nobody can copy another teacher's
         * private bank questions into their own test.
         */

        $question_context_select = $question_context_available
            ? ', course_id, organizational_unit_id, academic_period_id'
            : '';
        $stmt = $conn->prepare("
            SELECT
                id,
                question_text,
                class,
                subject,
                question_type,
                maximum_marks
                {$question_context_select}
            FROM new_questions
            WHERE id = ?
            AND test_id IS NULL
            AND teacher_id = ?
        ");

        $stmt->bind_param("ii", $bank_id, $teacher_id);
        $stmt->execute();

        $question = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$question) {
            Continue;
        }

        if (!$is_school_institution && (int)($question['course_id'] ?? 0) !== (int)($test['course_id'] ?? 0)) {
            continue;
        }

        /*
         * Insert into new_questions
         */

        if ($question_context_available && $universal_test_scope) {
            $course_id = (int)($test['course_id'] ?? 0) ?: null;
            $unit_id = (int)($test['organizational_unit_id'] ?? 0) ?: null;
            $period_id = (int)($test['academic_period_id'] ?? 0) ?: null;
            $stmt = $conn->prepare("INSERT INTO new_questions (question_text, test_id, teacher_id, class, subject, course_id, organizational_unit_id, academic_period_id, question_type, created_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param('sissiiis', $question['question_text'], $test_id, $question['class'], $test['subject'], $course_id, $unit_id, $period_id, $question['question_type']);
        } else {
        $stmt = $conn->prepare("
            INSERT INTO new_questions
            (
                Question_text,
                Test_id,
                Class,
                Subject,
                Question_type,
                Created_at
            )
            VALUES
            (
                ?,?,?,?,?,NOW()
            )
        ");

        $stmt->bind_param(
            "sisss",
            $question['question_text'],
            $test_id,
            $question['class'],
            $test['subject'],
            $question['question_type']
        );
        }

        $stmt->execute();

        $new_question_id = $stmt->insert_id;

        $stmt->close();

        /*
         * Copy options depending on question type
         */

        switch ($question['question_type']) {

            case "theory":
                $marks = (float)($question['maximum_marks'] ?? 0);
                if ($marks <= 0) throw new Exception('The selected theory bank question has no maximum marks. Edit it in bank.php and set maximum marks first.');
                $assessmentStmt = $conn->prepare("SELECT id FROM theory_assessments WHERE test_id = ? LIMIT 1");
                $assessmentStmt->bind_param('i', $test_id);
                $assessmentStmt->execute();
                $assessment = $assessmentStmt->get_result()->fetch_assoc();
                $assessmentStmt->close();
                if (!$assessment) {
                    $instructions = '';
                    $model = (string)(getenv('EXAMCENTER_THEORY_MODEL') ?: 'qwen3:1.7b');
                    $createAssessment = $conn->prepare("INSERT INTO theory_assessments(test_id, instructions, ai_model, status) VALUES (?, ?, ?, 'draft')");
                    $createAssessment->bind_param('iss', $test_id, $instructions, $model);
                    if (!$createAssessment->execute()) throw new Exception('Unable to create theory assessment: ' . $createAssessment->error);
                    $assessment = ['id' => $createAssessment->insert_id];
                    $createAssessment->close();
                }
                $assessmentId = (int)$assessment['id'];
                $numberStmt = $conn->prepare("SELECT COALESCE(MAX(question_number), 0) + 1 AS next_number FROM theory_questions WHERE theory_assessment_id = ?");
                $numberStmt->bind_param('i', $assessmentId);
                $numberStmt->execute();
                $number = (int)$numberStmt->get_result()->fetch_assoc()['next_number'];
                $numberStmt->close();
                $theoryStmt = $conn->prepare("INSERT INTO theory_questions(theory_assessment_id, question_number, question_text, maximum_marks) VALUES (?, ?, ?, ?)");
                $theoryStmt->bind_param('iisd', $assessmentId, $number, $question['question_text'], $marks);
                if (!$theoryStmt->execute()) throw new Exception('Unable to attach theory question: ' . $theoryStmt->error);
                $theoryStmt->close();
                break;

            case "multiple_choice_single":

                $stmt = $conn->prepare("
                    SELECT *
                    FROM single_choice_questions
                    WHERE question_id=?
                ");

                $stmt->bind_param("i", $bank_id);
                $stmt->execute();

                $data = $stmt->get_result()->fetch_assoc();

                $stmt->close();

                if ($data) {

                    $stmt = $conn->prepare("
                        INSERT INTO single_choice_questions
                        (
                            Question_id,
                            Option1,
                            Option2,
                            Option3,
                            Option4,
                            Correct_answer,
                            Image_path
                        )
                        VALUES
                        (?,?,?,?,?,?,?)
                    ");

                    $stmt->bind_param(
                        "issssss",
                        $new_question_id,
                        $data['option1'],
                        $data['option2'],
                        $data['option3'],
                        $data['option4'],
                        $data['correct_answer'],
                        $data['image_path']
                    );

                    $stmt->execute();
                    $stmt->close();
                }

                break;

            case "multiple_choice_multiple":
                $stmt = $conn->prepare("
                    SELECT *
                    FROM multiple_choice_questions
                    WHERE question_id=?
                ");

                $stmt->bind_param("i", $bank_id);
                $stmt->execute();

                $data = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($data) {
                    $stmt = $conn->prepare("
                        INSERT INTO multiple_choice_questions
                        (
                            Question_id,
                            Option1,
                            Option2,
                            Option3,
                            Option4,
                            Correct_answers,
                            Image_path
                        )
                        VALUES
                        (?,?,?,?,?,?,?)
                    ");

                    $stmt->bind_param(
                        "issssss",
                        $new_question_id,
                        $data['option1'],
                        $data['option2'],
                        $data['option3'],
                        $data['option4'],
                        $data['correct_answers'],
                        $data['image_path']
                    );
                    $stmt->execute();
                    $stmt->close();
                }

                break;

            case "true_false":
                $stmt = $conn->prepare("
                    SELECT *
                    FROM true_false_questions
                    WHERE question_id=?
                ");

                $stmt->bind_param("i", $bank_id);
                $stmt->execute();

                $data = $stmt->get_result()->fetch_assoc();

                $stmt->close();

                if ($data) {
                    $stmt = $conn->prepare("
                        INSERT INTO true_false_questions
                        (
                            Question_id,
                            Correct_answer
                        )
                        VALUES
                        (?,?)
                    ");

                    $stmt->bind_param(
                        "is",
                        $new_question_id,
                        $data['correct_answer']
                    );

                    $stmt->execute();
                    $stmt->close();
                }

                break;

            case "fill_blanks":
                $stmt = $conn->prepare("
                    SELECT *
                    FROM fill_blank_questions
                    WHERE question_id=?
                ");

                $stmt->bind_param("i", $bank_id);
                $stmt->execute();

                $data = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($data) {
                    $stmt = $conn->prepare("
                        INSERT INTO fill_blank_questions
                        (
                            Question_id,
                            Correct_answer
                        )
                        VALUES
                        (?,?)
                    ");

                    $stmt->bind_param(
                        "is",
                        $new_question_id,
                        $data['correct_answer']
                    );

                    $stmt->execute();
                    $stmt->close();
                }
                break;
        }
    }

    $conn->commit();

    $_SESSION['success'] = "Selected questions added successfully.";

    header("Location: bank.php?success=questions_added");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = $e->getMessage();
}

header("location: bank.php");
exit();
?>
