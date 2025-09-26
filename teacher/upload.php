<?php
require '../db.php';
require '../vendor/autoload.php'; // PhpWord autoload
use PhpOffice\PhpWord\IOFactory;

$conn = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_file'])) {

    $fileTmp = $_FILES['test_file']['tmp_name'];
    $fileName = $_FILES['test_file']['name'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if ($fileExt !== 'docx') {
        die("Unsupported file type. Only .docx allowed.");
    }

    // --- Read DOCX ---
    $phpWord = IOFactory::load($fileTmp);
    $lines = [];
    foreach ($phpWord->getSections() as $section) {
        foreach ($section->getElements() as $element) {
            if (method_exists($element, 'getText')) {
                $lines[] = trim($element->getText());
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                $text = '';
                foreach ($element->getElements() as $child) {
                    if (method_exists($child, 'getText')) {
                        $text .= $child->getText() . ' ';
                    }
                }
                $lines[] = trim($text);
            }
        }
    }

    // Normalize lines
    foreach ($lines as &$line) {
        $line = trim($line);
        $line = preg_replace('/_+/', '', $line); // remove underscores
        $line = str_replace("\xC2\xA0", ' ', $line); // remove non-breaking spaces
    }
    unset($line);

    // --- Parse header first ---
    if (empty($lines)) die("File is empty");

    $test_title = trim($lines[0]); // first line is always title
    $test_class = '';
    $test_subject = '';
    $test_duration = 0;

    foreach ($lines as $line) {
        if (preg_match('/^Class:\s*(.+)$/i', $line, $m)) {
            $test_class = trim($m[1]);
        }
        if (preg_match('/^Subject:\s*(.+)$/i', $line, $m)) {
            $test_subject = trim($m[1]);
        }
        if (preg_match('/^Duration:\s*(\d+)/i', $line, $m)) {
            $test_duration = intval($m[1]);
        }
    }

    // Validate headers
    if (empty($test_title) || empty($test_class) || empty($test_subject) || empty($test_duration)) {
        die("Missing required test header information.");
    }

    // --- Check if test already exists before inserting ---
    $stmt = $conn->prepare("SELECT id FROM tests WHERE title = ? AND class = ? AND subject = ? LIMIT 1");
    $stmt->bind_param("sss", $test_title, $test_class, $test_subject);
    $stmt->execute();
    $stmt->bind_result($existing_test_id);
    if ($stmt->fetch()) {
        $test_id = $existing_test_id;
        $stmt->close();
    } else {
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO tests (title, class, subject, duration) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $test_title, $test_class, $test_subject, $test_duration);
        $stmt->execute();
        $test_id = $stmt->insert_id;
        $stmt->close();
    }

    // --- Parse questions ---
    $questions = [];
    $current_question = [];

    foreach ($lines as $index => $line) {
        if ($index === 0) continue; // skip title
        if ($line === '' || preg_match('/^(Class|Subject|Duration):/i', $line)) continue;

        // Question detection: 1. question text a) opt1 (b) opt2 ...
        if (preg_match('/^(\d+)\.\s*(.+)$/', $line, $m)) {
            if (!empty($current_question)) $questions[] = $current_question;

            $current_question = [
                'question' => '',
                'options' => [],
                'correct_answer' => ''
            ];

            $question_part = trim($m[2]);

            // --- Extract options in strict a) or (a) format ---
            if (preg_match_all('/\(?([A-Da-d])\)\s*(.*?)(?=\s*\(?[A-Da-d]\)|$)/i', $question_part, $matches, PREG_SET_ORDER)) {
                $question_text = $question_part;

                foreach ($matches as $opt) {
                    $letter = strtoupper($opt[1]);
                    $text = trim($opt[2]);
                    $current_question['options'][$letter] = $text;

                    // Remove this option from the question text
                    $question_text = str_replace($opt[0], '', $question_text);
                }

                $current_question['question'] = trim($question_text);
            } else {
                $current_question['question'] = $question_part;
            }
            continue;
        }

        // --- Correct answer detection ---
        if (preg_match('/^\s*correct answer\s*:\s*(.+)$/i', $line, $m)) {
            $ans = trim($m[1]);

            if ($ans === '') {
                // Case 3: empty → default to A
                $current_question['correct_answer'] = 'A';
            } elseif (preg_match('/^[A-Da-d]$/', $ans)) {
                // Case 1: single letter
                $current_question['correct_answer'] = strtoupper($ans);
            } else {
                // Case 2: full text
                $current_question['correct_answer'] = $ans;
            }
            continue;
        }

        // Multi-line question continuation
        if (!empty($current_question) && !preg_match('/^correct answer:/i', $line)) {
            $current_question['question'] .= ' ' . $line;
        }
    }

    if (!empty($current_question)) $questions[] = $current_question;

    if (empty($questions)) die("No questions found in the file.");

    // --- Save questions ---
    foreach ($questions as $q) {
        if (empty($q['question']) || empty($q['correct_answer'])) continue;

        // Save the main question
        $stmt = $conn->prepare("INSERT INTO new_questions (question_text, test_id, class, subject, question_type) VALUES (?, ?, ?, ?, ?)");
        $type = 'multiple_choice_single';
        $stmt->bind_param("sisss", $q['question'], $test_id, $test_class, $test_subject, $type);
        $stmt->execute();
        $question_id = $stmt->insert_id;
        $stmt->close();

        // Prepare options safely
        $opt1 = $q['options']['A'] ?? '';
        $opt2 = $q['options']['B'] ?? '';
        $opt3 = $q['options']['C'] ?? '';
        $opt4 = $q['options']['D'] ?? '';

        $correct = $q['correct_answer'];

        // If answer is a letter, make sure it exists in options
        if (preg_match('/^[A-D]$/', $correct)) {
            if (!array_key_exists($correct, $q['options'])) {
                // fallback: first available option
                foreach (['A','B','C','D'] as $letter) {
                    if (!empty($q['options'][$letter])) {
                        $correct = $letter;
                        break;
                    }
                }
            }
        }

        // Save the options and correct answer (letter or full text)
        $stmt = $conn->prepare("INSERT INTO single_choice_questions (question_id, option1, option2, option3, option4, correct_answer) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssss", $question_id, $opt1, $opt2, $opt3, $opt4, $correct);
        $stmt->execute();
        $stmt->close();
    }

    echo "✅ Test and questions uploaded successfully.";

} else {
    echo "❌ No file uploaded.";
}
