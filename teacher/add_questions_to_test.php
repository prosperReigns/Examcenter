<?php
session_start();

require_once "../db.php";
require_once "../includes/system_guard.php";

if (!isset($_SESSION['current_test_id'])) {
    $_SESSION['error'] = "Please create or select a test first.";
    Header("Location: bank.php");
    Exit();
}

if (empty($_POST['questions'])) {
    $_SESSION['error'] = "Please select at least one question.";
    Header("Location: bank.php");
    Exit();
}

$database = Database::getInstance();
$conn = $database->getConnection();

$test_id = (int)$_SESSION['current_test_id'];

$conn->begin_transaction();

try {

    /*
     * Get current test details
     */

    $stmt = $conn->prepare("
        SELECT class_group, subject
        FROM tests
        WHERE id = ?
    ");

    $stmt->bind_param("i", $test_id);
    $stmt->execute();

    $test = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$test) {
        throw new Exception("Invalid test.");
    }

    foreach ($_POST['questions'] as $bank_id) {

        $bank_id = (int)$bank_id;

        /*
         * Read question from bank
         */

        $stmt = $conn->prepare("
            SELECT *
            FROM question_bank
            WHERE id=?
        ");

        $stmt->bind_param("i", $bank_id);
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
            $test['class_group'],
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

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = $e->getMessage();
}

header("location: bank.php");
exit();
?>
