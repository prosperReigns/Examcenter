<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

// Initialize database connection
$database = Database::getInstance();
$conn = $database->getConnection();
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    $_SESSION['error'] = "Database connection failed.";
    header("Location: add_question.php");
    exit();
}

// Fetch assigned subjects
$teacher_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE teacher_id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
$assigned_subjects = [];
while ($row = $result->fetch_assoc()) {
    $assigned_subjects[] = $row['subject'];
}
$stmt->close();

// Handle image upload
function handleImageUpload($question_id) {
    global $conn;
    if (!isset($_FILES['question_image']) || $_FILES['question_image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $max_size = 2 * 1024 * 1024; // 2MB
    if ($_FILES['question_image']['size'] > $max_size) {
        return false;
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($_FILES['question_image']['type'], $allowed_types)) {
        return false;
    }

    $upload_dir = '../Uploads/questions/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $ext = pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION);
    $filename = 'question_' . $question_id . '_' . time() . '.' . $ext;
    $full_path = $upload_dir . $filename;

    if (move_uploaded_file($_FILES['question_image']['tmp_name'], $full_path)) {
        return 'Uploads/questions/' . $filename;
    }

    return false;
}

// Handle question deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_question'])) {
    $question_id = (int)($_POST['question_id'] ?? 0);
    $question_type = $_POST['question_type'] ?? '';
    $valid_types = ['multiple_choice_single', 'multiple_choice_multiple', 'true_false', 'fill_blanks'];

    if ($question_id <= 0 || !in_array($question_type, $valid_types)) {
        $_SESSION['error'] = "Invalid question ID or type.";
    } else {
        $table_map = [
            'multiple_choice_single' => 'single_choice_questions',
            'multiple_choice_multiple' => 'multiple_choice_questions',
            'true_false' => 'true_false_questions',
            'fill_blanks' => 'fill_blank_questions',
        ];
        $table = $table_map[$question_type];

        // Delete associated image
        $stmt = $conn->prepare("SELECT image_path FROM $table WHERE question_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $image = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($image['image_path'] && file_exists("../{$image['image_path']}")) {
                if (!unlink("../{$image['image_path']}")) {
                    error_log("Failed to delete image: ../{$image['image_path']}");
                }
            }
        }

        // Delete from specific table
        $stmt = $conn->prepare("DELETE FROM $table WHERE question_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $stmt->close();
        }

        // Delete from new_questions
        $stmt = $conn->prepare("SELECT test_id, question_text FROM new_questions WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $question = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM new_questions WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $question_id);
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Question deleted successfully!";
                    // Log activity
                    $ip_address = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                    $activity = "Teacher deleted question ID $question_id: " . substr($question['question_text'] ?? '', 0, 50);
                    $stmt_log = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
                    if ($stmt_log) {
                        $stmt_log->bind_param("siss", $activity, $teacher_id, $ip_address, $user_agent);
                        $stmt_log->execute();
                        $stmt_log->close();
                    }
                } else {
                    error_log("Execute failed for question deletion: " . $stmt->error);
                    $_SESSION['error'] = "Error deleting question.";
                }
                $stmt->close();
            }
        }
    }
    header("Location: add_question.php");
    exit();
}

// Handle question editing (load question into form)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_question'])) {
    $question_id = (int)($_POST['question_id'] ?? 0);
    if ($question_id <= 0) {
        $_SESSION['error'] = "Invalid question ID.";
    } else {
        $stmt = $conn->prepare("SELECT id, question_text, question_type FROM new_questions WHERE id = ?");
        if (!$stmt) {
            error_log("Prepare failed for edit question: " . $conn->error);
            $_SESSION['error'] = "Database error.";
        } else {
            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $edit_question = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($edit_question) {
                $sql = '';
                switch ($edit_question['question_type']) {
                    case 'multiple_choice_single':
                        $sql = "SELECT option1, option2, option3, option4, correct_answer, image_path FROM single_choice_questions WHERE question_id = ?";
                        break;
                    case 'multiple_choice_multiple':
                        $sql = "SELECT option1, option2, option3, option4, correct_answers, image_path FROM multiple_choice_questions WHERE question_id = ?";
                        break;
                    case 'true_false':
                        $sql = "SELECT correct_answer FROM true_false_questions WHERE question_id = ?";
                        break;
                    case 'fill_blanks':
                        $sql = "SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?";
                        break;
                }

                if ($sql) {
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param("i", $question_id);
                        $stmt->execute();
                        $edit_question['options'] = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                    }
                }
                $_SESSION['edit_question'] = $edit_question;
            } else {
                $_SESSION['error'] = "Question not found.";
            }
        }
    }
    header("Location: add_question.php");
    exit();
}

// Handle question submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['question'])) {
    if (!isset($_SESSION['current_test_id'])) {
        $_SESSION['error'] = "Please create or select a test first.";
    } else {
        $test_id = (int)$_SESSION['current_test_id'];
        $question_id = (int)($_POST['question_id'] ?? 0);
        $question_text = trim($_POST['question'] ?? '');
        $question_type = trim($_POST['question_type'] ?? '');

        if (empty($question_text) || empty($question_type) || !in_array($question_type, ['multiple_choice_single', 'multiple_choice_multiple', 'true_false', 'fill_blanks'])) {
            $_SESSION['error'] = "Question text and valid type are required.";
        } else {
            $stmt = $conn->prepare("SELECT class, subject FROM tests WHERE id = ?");
            if (!$stmt) {
                error_log("Prepare failed for test data: " . $conn->error);
                $_SESSION['error'] = "Database error.";
            } else {
                $stmt->bind_param("i", $test_id);
                $stmt->execute();
                $test_data = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$test_data) {
                    unset($_SESSION['current_test_id']);
                    $_SESSION['error'] = "Invalid test.";
                } else {
                    $class = $test_data['class'];
                    $subject = $test_data['subject'];

                    // Handle image
                    $image_path = null;
                    if (isset($_POST['remove_image']) && $_POST['remove_image'] === 'on' && $question_id) {
                        $table = $question_type === 'multiple_choice_single' ? 'single_choice_questions' : 'multiple_choice_questions';
                        $stmt = $conn->prepare("SELECT image_path FROM $table WHERE question_id = ?");
                        if ($stmt) {
                            $stmt->bind_param("i", $question_id);
                            $stmt->execute();
                            $image = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            if ($image['image_path'] && file_exists("../{$image['image_path']}")) {
                                unlink("../{$image['image_path']}");
                            }
                        }
                    } else {
                        $image_path = handleImageUpload($question_id ?: time());
                        if ($image_path === false) {
                            $_SESSION['error'] = "Image upload failed: Invalid file or size.";
                        }
                    }

                    if (!isset($_SESSION['error'])) {
                        $conn->begin_transaction();
                        try {
                            if ($question_id) {
                                $stmt = $conn->prepare("UPDATE new_questions SET question_text = ?, question_type = ? WHERE id = ?");
                                $stmt->bind_param("ssi", $question_text, $question_type, $question_id);
                            } else {
                                $stmt = $conn->prepare("INSERT INTO new_questions (question_text, test_id, class, subject, question_type, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                                $stmt->bind_param("sisss", $question_text, $test_id, $class, $subject, $question_type);
                            }
                            if (!$stmt->execute()) {
                                throw new Exception("Error saving question: " . $stmt->error);
                            }
                            $question_id = $question_id ?: $stmt->insert_id;
                            $stmt->close();

                            // Delete existing options
                            $table_map = [
                                'multiple_choice_single' => 'single_choice_questions',
                                'multiple_choice_multiple' => 'multiple_choice_questions',
                                'true_false' => 'true_false_questions',
                                'fill_blanks' => 'fill_blank_questions',
                            ];
                            if (isset($table_map[$question_type])) {
                                $table = $table_map[$question_type];
                                $stmt = $conn->prepare("DELETE FROM $table WHERE question_id = ?");
                                if ($stmt) {
                                    $stmt->bind_param("i", $question_id);
                                    $stmt->execute();
                                    $stmt->close();
                                }
                            }

                            switch ($question_type) {
                                case 'multiple_choice_single':
                                    $option1 = trim($_POST['option1'] ?? '');
                                    $option2 = trim($_POST['option2'] ?? '');
                                    $option3 = trim($_POST['option3'] ?? '');
                                    $option4 = trim($_POST['option4'] ?? '');
                                    $correct_answer = trim($_POST['correct_answer'] ?? '');
                                    $options = [$option1, $option2, $option3, $option4];
                                    if ($option1 && $option2 && $option3 && $option4 && $correct_answer && in_array($correct_answer, ['1', '2', '3', '4'])) {
                                        $correct_text = $options[(int)$correct_answer - 1];
                                        $stmt = $conn->prepare("INSERT INTO single_choice_questions (question_id, option1, option2, option3, option4, correct_answer, image_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                        $stmt->bind_param("issssss", $question_id, $option1, $option2, $option3, $option4, $correct_text, $image_path);
                                        $stmt->execute();
                                        $stmt->close();
                                    } else {
                                        throw new Exception("All options and a valid correct answer are required.");
                                    }
                                    break;

                                case 'multiple_choice_multiple':
                                    $option1 = trim($_POST['option1'] ?? '');
                                    $option2 = trim($_POST['option2'] ?? '');
                                    $option3 = trim($_POST['option3'] ?? '');
                                    $option4 = trim($_POST['option4'] ?? '');
                                    $correct_answers = isset($_POST['correct_answers']) ? array_map('intval', $_POST['correct_answers']) : [];
                                    $correct_text = implode(',', array_map(fn($i) => [$option1, $option2, $option3, $option4][$i - 1], $correct_answers));
                                    if ($option1 && $option2 && $option3 && $option4 && $correct_answers) {
                                        $stmt = $conn->prepare("INSERT INTO multiple_choice_questions (question_id, option1, option2, option3, option4, correct_answers, image_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                        $stmt->bind_param("issssss", $question_id, $option1, $option2, $option3, $option4, $correct_text, $image_path);
                                        $stmt->execute();
                                        $stmt->close();
                                    } else {
                                        throw new Exception("All options and at least one correct answer are required.");
                                    }
                                    break;

                                case 'true_false':
                                    $correct_answer = trim($_POST['correct_answer'] ?? '');
                                    if (in_array($correct_answer, ['True', 'False'])) {
                                        $stmt = $conn->prepare("INSERT INTO true_false_questions (question_id, correct_answer) VALUES (?, ?)");
                                        $stmt->bind_param("is", $question_id, $correct_answer);
                                        $stmt->execute();
                                        $stmt->close();
                                    } else {
                                        throw new Exception("A valid correct answer is required.");
                                    }
                                    break;

                                case 'fill_blanks':
                                    $correct_answer = trim($_POST['correct_answer'] ?? '');
                                    if ($correct_answer) {
                                        $stmt = $conn->prepare("INSERT INTO fill_blank_questions (question_id, correct_answer) VALUES (?, ?)");
                                        $stmt->bind_param("is", $question_id, $correct_answer);
                                        $stmt->execute();
                                        $stmt->close();
                                    } else {
                                        throw new Exception("A correct answer is required.");
                                    }
                                    break;
                            }

                            $conn->commit();
                            $_SESSION['success'] = "Question " . ($question_id ? 'updated' : 'added') . " successfully!";
                            // Log activity
                            $ip_address = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';
                            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                            $activity = "Teacher " . ($question_id ? 'updated' : 'added') . " question ID $question_id: " . substr($question_text, 0, 50);
                            $stmt_log = $conn->prepare("INSERT INTO activities_log (activity, admin_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())");
                            if ($stmt_log) {
                                $stmt_log->bind_param("siss", $activity, $teacher_id, $ip_address, $user_agent);
                                $stmt_log->execute();
                                $stmt_log->close();
                            }
                        } catch (Exception $e) {
                            $conn->rollback();
                            error_log("Question save error: " . $e->getMessage());
                            $_SESSION['error'] = "Error saving question: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
    header("Location: add_question.php");
    exit();
}

$conn->close();
?>