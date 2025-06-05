<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Question</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.min.css">
    <link rel="stylesheet" href="../css/animate.min.css">
    <link rel="stylesheet" href="../css/add_question.css">
    <style>
        #imageUploadContainer {
            display: none;
        }
    </style>
</head>
<body>
    <!-- Gradient Header -->
    <div class="gradient-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">Add questions</h1>
                <div class="d-flex gap-3">
                    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#previewModal">
                        <i class="fas fa-eye me-2"></i>Preview
                    </button>
                    <a href="dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row g-4">
            <!-- Question Form -->
            <div class="col-lg-8">
                <div class="question-card">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <?php if (!$current_test): ?>
                        <h5 class="mb-3">Test Setup</h5>
                        <div class="card mb-4">
                            <div class="card-body">
                                <h6> <b> Create new Test</b></h6>
                                <form method="POST">
                                    <div class="row g-3">
                                        <div class="col-md-3 form-group-spacing">
                                            <label class="form-label fw-bold">Test Title</label>
                                           <select class="form-select" name="test_title" required>
                                                <option value="">Select Test title</option>
                                                <option value="First term exam">First term exam</option>
                                                <option value="First term test">First term test</option>
                                                <option value="Second term exam">Second term exam</option>
                                                <option value="Second term test">Second term test</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2 form-group-spacing">
                                            <label class="form-label fw-bold">Class</label>
                                            <select class="form-select" name="class" required>
                                                <option value="">Select Class</option>
                                                <option value="JSS1">JSS1</option>
                                                <option value="JSS2">JSS2</option>
                                                <option value="JSS3">JSS3</option>
                                                <option value="SS1">SS1</option>
                                                <option value="SS2">SS2</option>
                                                <option value="SS3">SS3</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3 form-group-spacing">
                                            <label class="form-label fw-bold">Subject</label>
                                            <select class="form-select" name="subject" required id="subjectSelect">
                                                <option value="">Select Subject</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3 form-group-spacing">
                                            <label class="form-label fw-bold">Duration (min)</label>
                                            <input type="number" class="form-control" name="duration" required placeholder="e.g. 30" min="1">
                                        </div>
                                    </div>
                                    <button type="submit" name="create_test" class="btn btn-primary mt-3">
                                        <i class="fas fa-plus me-2"></i>Create Test
                                    </button>
                                </form>

                                <?php if (!empty($tests)): ?>
                                    <hr>
                                    <h6>Select Existing Test</h6>
                                    <form method="POST">
                                        <div class="form-group-spacing">
                                            <label class="form-label fw-bold">Available Tests</label>
                                            <select class="form-select" name="test_id" required>
                                                <option value="">Select a Test</option>
                                                <?php foreach ($tests as $test): ?>
                                                    <option value="<?php echo $test['id']; ?>">
                                                        <?php echo htmlspecialchars($test['title'] . ' (' . $test['class'] . ' - ' . $test['subject'] . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" name="select_test" class="btn btn-primary">
                                            <i class="fas fa-check me-2"></i>Select Test
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Question Form -->
                    <?php if ($current_test): ?>
                        <h5 class="mb-3"><?php echo $edit_question ? 'Edit Question' : 'Add Question'; ?></h5>
                        <form method="POST" id="questionForm" enctype="multipart/form-data">
                            <input type="hidden" name="question_id" value="<?php echo $edit_question['id'] ?? ''; ?>">
                            <div class="form-group-spacing">
                                <label class="form-label fw-bold">Question Type</label>
                                <select class="form-select form-select-lg" name="question_type" id="questionType" required>
                                    <option value="multiple_choice_single" <?php echo ($edit_question && $edit_question['question_type'] == 'multiple_choice_single') ? 'selected' : ''; ?>>Multiple Choice (Single)</option>
                                    <option value="multiple_choice_multiple" <?php echo ($edit_question && $edit_question['question_type'] == 'multiple_choice_multiple') ? 'selected' : ''; ?>>Multiple Choice (Multiple)</option>
                                    <option value="true_false" <?php echo ($edit_question && $edit_question['question_type'] == 'true_false') ? 'selected' : ''; ?>>True/False</option>
                                    <option value="fill_blanks" <?php echo ($edit_question && $edit_question['question_type'] == 'fill_blanks') ? 'selected' : ''; ?>>Fill in Blanks</option>
                                </select>
                            </div>
                            
                            <div class="form-group-spacing">
                                <label class="form-label fw-bold">Question Text</label>
                                <textarea class="form-control" name="question" rows="4" 
                                    placeholder="Enter your question here..." required><?php echo htmlspecialchars($edit_question['question_text'] ?? ''); ?></textarea>
                            </div>

                            <!-- Dynamic Options Container -->
                            <div id="optionsContainer" class="form-group-spacing"></div>

                            <div class="d-flex justify-content-end gap-3 mt-4">
                                <button type="reset" class="btn btn-secondary">Clear</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-<?php echo $edit_question ? 'save' : 'plus'; ?> me-2"></i><?php echo $edit_question ? 'Update Question' : 'Add Question'; ?>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Preview Sidebar -->
            <div class="col-lg-4">
                <div class="preview-card">
                    <div class="question-card p-3">
                        <h5 class="mb-3">Test Overview</h5>
                        <?php if ($current_test): ?>
                            <div class="alert alert-primary">
                                <strong><?php echo htmlspecialchars($current_test['title']); ?></strong><br>
                                <span class="text-muted"><?php echo htmlspecialchars($current_test['class'] . ' - ' . $current_test['subject']); ?></span><br>
                                <small>Duration: <?php echo $current_test['duration']; ?> minutes</small>
                            </div>
                            
                            <div class="d-flex justify-content-between mb-3">
                                <span>Total Questions:</span>
                                <strong><?php echo $total_questions; ?></strong>
                            </div>
                            
                            <?php if ($total_questions > 0): ?>
                                <div class="question-navigation">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="prev">
                                        <button type="submit" class="btn btn-sm btn-outline-primary w-100 mb-2" 
                                            <?php echo ($_SESSION['current_question_index'] ?? 0) <= 0 ? 'disabled' : ''; ?>>
                                            Previous Question
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="next">
                                        <button type="submit" class="btn btn-sm btn-outline-primary w-100" 
                                            <?php echo ($_SESSION['current_question_index'] ?? 0) >= $total_questions - 1 ? 'disabled' : ''; ?>>
                                            Next Question
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                            <div class="mt-3">
                                <form method="POST" action="">
                                    <button type="submit" name="clear_test" class="btn btn-outline-danger w-100">
                                        <i class="fas fa-times me-2"></i>Clear Test Selection
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="text-center">
                                <p class="text-muted">No test selected. Create or select a test to start.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="previewModalLabel">Test Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body modal-preview">
                    <?php if ($current_test && !empty($questions)): ?>
                        <h6><?php echo htmlspecialchars($current_test['title']); ?> (<?php echo htmlspecialchars($current_test['class'] . ' - ' . $current_test['subject']); ?>)</h6>
                        <p><small>Duration: <?php echo $current_test['duration']; ?> minutes</small></p>
                        <hr>
                        <?php foreach ($questions as $index => $question): ?>
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong>Question <?php echo $index + 1; ?>: <?php echo htmlspecialchars($question['question_text']); ?></strong>
                                    <div class="action-buttons">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                            <input type="hidden" name="edit_question" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this question?');">
                                            <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                            <input type="hidden" name="question_type" value="<?php echo $question['question_type']; ?>">
                                            <input type="hidden" name="delete_question" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <span class="badge bg-primary ms-2"><?php echo ucfirst(str_replace('_', ' ', $question['question_type'])); ?></span>
                                <div class="mt-2">
                                    <?php
                                    switch ($question['question_type']) {
                                        case 'multiple_choice_single':
                                            $option_query = "SELECT option1, option2, option3, option4, correct_answer, image_path FROM single_choice_questions WHERE question_id = ?";
                                            $stmt = $conn->prepare($option_query);
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $options = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            if (!empty($options['image_path'])) {
                                                echo '<div class="mb-3">';
                                                echo '<img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid mb-2" style="max-height: 200px;">';
                                                echo '</div>';
                                            }   
                                            foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                                $option_number = $i + 1;
                                                echo "<div>" . ($options['correct_answer'] == $option_number ? '<i class="fas fa-check text-success me-2"></i>' : '') . 
                                                     htmlspecialchars($options[$opt]) . "</div>";
                                            }
                                            break;
                                        case 'multiple_choice_multiple':
                                            $option_query = "SELECT option1, option2, option3, option4, correct_answers, image_path FROM multiple_choice_questions WHERE question_id = ?";
                                            $stmt = $conn->prepare($option_query);
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $options = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            if (!empty($options['image_path'])) {
                                                echo '<div class="mb-3">';
                                                echo '<img src="../' . htmlspecialchars($options['image_path']) . '" class="img-fluid mb-2" style="max-height: 200px;">';
                                                echo '</div>';
                                            }
                                            $correct = explode(',', $options['correct_answers']);
                                            foreach (['option1', 'option2', 'option3', 'option4'] as $i => $opt) {
                                                $option_number = $i + 1;
                                                echo "<div>" . (in_array($option_number, $correct) ? '<i class="fas fa-check text-success me-2"></i>' : '') . 
                                                     htmlspecialchars($options[$opt]) . "</div>";
                                            }
                                            break;
                                        case 'true_false':
                                            $option_query = "SELECT correct_answer FROM true_false_questions WHERE question_id = ?";
                                            $stmt = $conn->prepare($option_query);
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $answer = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            echo "<div>Correct Answer: " . htmlspecialchars($answer['correct_answer']) . "</div>";
                                            break;
                                        case 'fill_blanks':
                                            $option_query = "SELECT correct_answer FROM fill_blank_questions WHERE question_id = ?";
                                            $stmt = $conn->prepare($option_query);
                                            $stmt->bind_param("i", $question['id']);
                                            $stmt->execute();
                                            $answer = $stmt->get_result()->fetch_assoc();
                                            $stmt->close();
                                            echo "<div>Correct Answer: " . htmlspecialchars($answer['correct_answer']) . "</div>";
                                            break;
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center">No questions available to preview.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>