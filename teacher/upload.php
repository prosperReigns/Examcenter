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

    // --- Save test ---
    $stmt = $conn->prepare("INSERT INTO tests (title, class, subject, duration) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sssi", $test_title, $test_class, $test_subject, $test_duration);
    $stmt->execute();
    $test_id = $stmt->insert_id;
    $stmt->close();

    // --- Parse questions ---
    $questions = [];
    $current_question = [];

    foreach ($lines as $index => $line) {
        if ($index === 0) continue; // skip title
        if ($line === '' || preg_match('/^(Class|Subject|Duration):/i', $line)) continue;

        // Question detection: 1. question text A) opt1 B) opt2 ...
        if (preg_match('/^(\d+)\.\s*(.+)$/', $line, $m)) {
            if (!empty($current_question)) $questions[] = $current_question;

            $current_question = [
                'question' => '',
                'options' => [],
                'correct_answer' => ''
            ];

            $question_part = trim($m[2]);

            // Extract options
            if (preg_match_all('/([A-Da-d])\)\s*([^A-D]+)/', $question_part, $matches, PREG_SET_ORDER)) {
                $option_texts = [];
                foreach ($matches as $opt) {
                    $letter = strtoupper($opt[1]);
                    $current_question['options'][$letter] = trim($opt[2]);
                    $option_texts[] = $opt[0];
                }
                $question_text = trim(str_replace($option_texts, '', $question_part));
                $current_question['question'] = $question_text;
            } else {
                $current_question['question'] = $question_part;
            }
            continue;
        }

        // Correct answer
        if (preg_match('/^correct answer:\s*(.+)$/i', $line, $m)) {
            $current_question['correct_answer'] = trim($m[1]);
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

        $stmt = $conn->prepare("INSERT INTO new_questions (question_text, test_id, class, subject, question_type) VALUES (?, ?, ?, ?, ?)");
        $type = 'multiple_choice_single';
        $stmt->bind_param("sisss", $q['question'], $test_id, $test_class, $test_subject, $type);
        $stmt->execute();
        $question_id = $stmt->insert_id;
        $stmt->close();

        $opt1 = $q['options']['A'] ?? '';
        $opt2 = $q['options']['B'] ?? '';
        $opt3 = $q['options']['C'] ?? '';
        $opt4 = $q['options']['D'] ?? '';
        $correct = $q['correct_answer'];

        $stmt = $conn->prepare("INSERT INTO single_choice_questions (question_id, option1, option2, option3, option4, correct_answer) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssss", $question_id, $opt1, $opt2, $opt3, $opt4, $correct);
        $stmt->execute();
        $stmt->close();
    }

    echo "✅ Test and questions uploaded successfully.";

} else {
    echo "❌ No file uploaded.";
}
