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

         // Force UTF-8 encoding
        if (!mb_check_encoding($line, 'UTF-8')) {
            $line = mb_convert_encoding($line, 'UTF-8', 'auto');
        }

        // Fix common Word symbol corruption
        $line = html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    unset($line);

    // =======================
    // STRICT FORMAT VALIDATOR
    // =======================

    function fail($lineNumber, $lineContent, $tip) {
        die(
            "❌ Format Error at line {$lineNumber}:<br>
            <strong>{$lineContent}</strong><br><br>
            💡 Tip: {$tip}"
        );
    }

    $totalLines = count($lines);

    // ---- HEADER VALIDATION (LINES 1–4) ----
    if ($totalLines < 4) {
        die("❌ File too short. Expected at least 4 header lines.");
    }

    // Line 1: Title
    if (empty(trim($lines[0]))) {
        fail(1, '[EMPTY LINE]', 'Provide a test title on the first line.');
    }

    // Line 2: Class
    if (!preg_match('/^Class:\s*\w+/i', $lines[1])) {
        fail(2, $lines[1], 'Use format: Class: SS1');
    }

    // Line 3: Subject
    if (!preg_match('/^Subject:\s*.+/i', $lines[2])) {
        fail(3, $lines[2], 'Use format: Subject: Data Processing');
    }

    // Line 4: Duration
    if (!preg_match('/^Duration:\s*\d+/i', $lines[3])) {
        fail(4, $lines[3], 'Use format: Duration: 30');
    }

    // ---- QUESTION VALIDATION ----
    $expectedQuestion = 1;

    for ($i = 4; $i < $totalLines; $i++) {

        $line = trim($lines[$i]);
        if ($line === '') continue;

        // ---- Question number ----
        if (preg_match('/^(\d+)\.\s*(.+)$/', $line, $qMatch)) {

            $qNumber = (int)$qMatch[1];

            if ($qNumber !== $expectedQuestion) {
                fail(
                    $i + 1,
                    $line,
                    "Question numbering error. Expected question {$expectedQuestion}."
                );
            }

            $questionText = $qMatch[2];

            // ---- Options extraction ----
            preg_match_all(
                '/\(?([A-Da-d])\)\s*(.*?)(?=\s*\(?[A-Da-d]\)|$)/',
                $questionText,
                $options,
                PREG_SET_ORDER
            );

            if (count($options) !== 4) {
                fail(
                    $i + 1,
                    $line,
                    "Each question must have exactly 4 options (A–D)."
                );
            }

            // Ensure all A–D exist
            $found = [];
            foreach ($options as $opt) {
                $found[] = strtoupper($opt[1]);
            }

            foreach (['A','B','C','D'] as $req) {
                if (!in_array($req, $found)) {
                    fail(
                        $i + 1,
                        $line,
                        "Missing option {$req}. Use a) or (a) format."
                    );
                }
            }

            // ---- Correct answer must be NEXT LINE ----
            if (!isset($lines[$i + 1])) {
                fail(
                    $i + 1,
                    $line,
                    "Correct answer missing after this question."
                );
            }

            $nextLine = trim($lines[$i + 1]);

            if (!preg_match('/^correct answer\s*:\s*(.+)$/i', $nextLine, $ansMatch)) {
                fail(
                    $i + 2,
                    $nextLine,
                    "Correct answer must be on the next line. Format: Correct answer: Option"
                );
            }

            $answer = trim($ansMatch[1]);

            if ($answer === '') {
                fail(
                    $i + 2,
                    $nextLine,
                    "Correct answer cannot be empty."
                );
            }

            $expectedQuestion++;
            $i++; // skip correct answer line
        }
    }

    // =======================
    // END VALIDATION
    // =======================

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

    // --- Get academic year from form ---
if (empty($_POST['year'])) {
    die("Academic year is required.");
}
$test_year = $_POST['year'];

    // Get academic_level_id for the class name
    $stmt = $conn->prepare("SELECT id FROM classes WHERE class_name = ? LIMIT 1");
    $stmt->bind_param("s", $test_class);
    $stmt->execute();
    $stmt->bind_result($academic_level_id);
    if (!$stmt->fetch()) {
        die("❌ Invalid class: {$test_class}");
    }
    $stmt->close();

    // Derive class level (JSS / SS)
    $test_class_level = str_starts_with($test_class, 'SS') ? 'SS' : 'JSS';

    // Validate subject against subject_levels
    $stmt = $conn->prepare("
        SELECT 1
        FROM subjects s
        JOIN subject_levels sl ON sl.subject_id = s.id
        WHERE s.subject_name = ?
        AND sl.class_level = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $test_subject, $test_class_level);
    $stmt->execute();

    if (!$stmt->fetch()) {
        die("❌ Subject '{$test_subject}' is not allowed for {$test_class_level}");
    }
    $stmt->close();

    // Check if test exists
    $stmt = $conn->prepare("SELECT id FROM tests WHERE title = ? AND academic_level_id = ? AND subject = ? AND year = ? LIMIT 1");
    $stmt->bind_param("siss", $test_title, $academic_level_id, $test_subject, $test_year);

    $stmt->execute();
    $stmt->bind_result($existing_test_id);
    if ($stmt->fetch()) {
        $test_id = $existing_test_id;
        $stmt->close();
    } else {
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO tests (title, academic_level_id, subject, duration, year, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("sisii", $test_title, $academic_level_id, $test_subject, $test_duration, $test_year);
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
    echo '<br><br><a href="add_question.php" style="text-decoration:none;font-size:18px;">⬅ Back</a>';

} else {
    echo "❌ No file uploaded.";
    echo '<br><br><a href="add_question.php" style="text-decoration:none;font-size:18px;">⬅ Back</a>';
}
