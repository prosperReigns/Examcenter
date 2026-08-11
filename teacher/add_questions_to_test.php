<?php
session_start();

require_once "../db.php";
require_once "../includes/system_guard.php";

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

$conn->begin_transaction();

try {

    /*
     * Get target test details, scoped to a subject this
     * teacher is actually assigned to.
     */

    if (empty($assigned_subjects)) {
        throw new Exception("You are not assigned to any subjects.");
    }

    $placeholders = implode(',', array_fill(0, count($assigned_subjects), '?'));

    $stmt = $conn->prepare("
        SELECT academic_level_id, subject
        FROM tests
        WHERE id = ?
        AND subject IN ($placeholders)
    ");

    $types = 'i' . str_repeat('s', count($assigned_subjects));
    $params = array_merge([$test_id], $assigned_subjects);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $test = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$test) {
        throw new Exception("Invalid test, or you don't have access to it.");
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

        $stmt = $conn->prepare("
            SELECT
                id,
                question_text,
                class,
                subject,
                question_type
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

        /*
         * Insert into new_questions
         */

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
            $test['academic_level_id'],
            $test['subject'],
            $question['question_type']
        );

        $stmt->execute();

        $new_question_id = $stmt->insert_id;

        $stmt->close();

        /*
         * Copy options depending on question type
         */

        switch ($question['question_type']) {

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