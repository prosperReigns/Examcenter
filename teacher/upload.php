<?php
require '../db.php';

$conn = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['json_file'])) {
    $json = file_get_contents($_FILES['json_file']['tmp_name']);
    $data = json_decode($json, true);

    if (!$data || !isset($data['test']) || !isset($data['questions'])) {
        die("Invalid JSON format");
    }

    $test = $data['test'];
    $questions = $data['questions'];

    // Check if test already exists
    $stmt = $conn->prepare("SELECT id FROM tests WHERE title=? AND class=? AND subject=?");
    $stmt->bind_param("sss", $test['title'], $test['class'], $test['subject']);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $test_id = $existing['id'];
    } else {
        $stmt = $conn->prepare("INSERT INTO tests (title, class, subject, duration) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $test['title'], $test['class'], $test['subject'], $test['duration']);
        $stmt->execute();
        $test_id = $stmt->insert_id;
        $stmt->close();
    }

    foreach ($questions as $q) {
        $type = $q['type'];

        // Insert into new_questions table first to get question_id
        $stmt = $conn->prepare("
            INSERT INTO new_questions (question_text, test_id, class, subject, question_type)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "sisss",
            $q['question'],
            $test_id,
            $test['class'],
            $test['subject'],
            $type
        );
        $stmt->execute();
        $question_id = $stmt->insert_id;
        $stmt->close();

        switch ($type) {
            case 'multiple_choice_single':
                $opt1 = $q['option1'] ?? '';
                $opt2 = $q['option2'] ?? '';
                $opt3 = $q['option3'] ?? '';
                $opt4 = $q['option4'] ?? '';
                $correct = $q['correct_answer'] ?? '';
                $image = $q['image_path'] ?? null;

                $stmt = $conn->prepare("
                    INSERT INTO single_choice_questions
                    (question_id, option1, option2, option3, option4, correct_answer, image_path)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "issssss",
                    $question_id,
                    $opt1,
                    $opt2,
                    $opt3,
                    $opt4,
                    $correct,
                    $image
                );
                $stmt->execute();
                $stmt->close();
                break;

            case 'fill_blank':
                $answer = $q['correct_answer'] ?? '';

                $stmt = $conn->prepare("
                    INSERT INTO fill_blank_questions
                    (question_id, correct_answer)
                    VALUES (?, ?)
                ");
                $stmt->bind_param("is", $question_id, $answer);
                $stmt->execute();
                $stmt->close();
                break;

            case 'true_false':
                $answer = $q['correct_answer'] ?? '';
                $stmt = $conn->prepare("
                    INSERT INTO true_false_questions
                    (question_id, correct_answer)
                    VALUES (?, ?)
                ");
                $stmt->bind_param("is", $question_id, $answer);
                $stmt->execute();
                $stmt->close();
                break;

            default:
                // ignore unknown types
                continue 2;
        }
    }

    echo "✅ Test and questions uploaded successfully. <a href='add_questions.php'>Go back</a>";
} else {
    echo "❌ No file uploaded.";
}
?>
