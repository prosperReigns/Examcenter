<?php

session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../includes/universal_architecture.php';

$isBankMode = isset($_POST['bank_mode']) && $_POST['bank_mode'] === "1";

$database = Database::getInstance();
$conn = $database->getConnection();

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    $_SESSION['error'] = "Database connection failed.";
    header("Location: " . ($isBankMode ? "bank.php" : "add_question.php"));
    exit();
}

$teacher_id = (int)($_SESSION['user_id'] ?? 0);

if ($teacher_id <= 0) {
    $_SESSION['error'] = "Invalid user session.";
    header("Location: " . ($isBankMode ? "bank.php" : "add_question.php"));
    exit();
}

$institution_profile = examcenterInstitutionProfile($conn);
$institution_id = (int)($institution_profile['id'] ?? 0);
$is_school_institution = $institution_id === 0 || in_array(
    $institution_profile['category'],
    ['primary_school', 'secondary_school'],
    true
);
$question_universal_columns = $institution_id > 0
    && examcenterUniversalColumnExists($conn, 'new_questions', 'course_id')
    && examcenterUniversalColumnExists($conn, 'new_questions', 'organizational_unit_id')
    && examcenterUniversalColumnExists($conn, 'new_questions', 'academic_period_id');
$assigned_courses = $institution_id > 0
    ? examcenterTeacherCourseAssignments($conn, $teacher_id, $institution_id)
    : [];


/*
|--------------------------------------------------------------------------
| FETCH ASSIGNED SUBJECTS
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT subject
    FROM teacher_subjects
    WHERE teacher_id = ?
");

$stmt->bind_param("i", $teacher_id);
$stmt->execute();

$result = $stmt->get_result();

$assigned_subjects = [];

while ($row = $result->fetch_assoc()) {
    $assigned_subjects[] = $row['subject'];
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| IMAGE UPLOAD
|--------------------------------------------------------------------------
*/

function handleImageUpload($question_id)
{
    global $conn;

    if (
        !isset($_FILES['question_image']) ||
        $_FILES['question_image']['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ($_FILES['question_image']['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    $max_size = 2 * 1024 * 1024;

    if ($_FILES['question_image']['size'] > $max_size) {
        return false;
    }

    $allowed_types = [
        'image/jpeg',
        'image/png',
        'image/gif'
    ];

    $mime_type = mime_content_type($_FILES['question_image']['tmp_name']);

    if (!in_array($mime_type, $allowed_types, true)) {
        return false;
    }

    $upload_dir = '../Uploads/questions/';

    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            return false;
        }
    }

    $extension_map = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif'
    ];

    $ext = $extension_map[$mime_type];

    $filename = 'question_' . $question_id . '_' . time() . '.' . $ext;

    $full_path = $upload_dir . $filename;

    if (
        move_uploaded_file(
            $_FILES['question_image']['tmp_name'],
            $full_path
        )
    ) {
        return 'Uploads/questions/' . $filename;
    }

    return false;
}


/*
|--------------------------------------------------------------------------
| ANSWER TABLE MAP
|--------------------------------------------------------------------------
*/

$answer_table_map = [
    'multiple_choice_single'   => 'single_choice_questions',
    'multiple_choice_multiple' => 'multiple_choice_questions',
    'true_false'               => 'true_false_questions',
    'fill_blanks'              => 'fill_blank_questions'
];


/*
|--------------------------------------------------------------------------
| DELETE QUESTION
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['delete_question'])
) {

    $question_id = (int)($_POST['question_id'] ?? 0);

    if ($question_id <= 0) {
        $_SESSION['error'] = "Invalid question ID.";
        header("Location: " . ($isBankMode ? "bank.php" : "add_question.php"));
        exit();
    }

    $conn->begin_transaction();

    try {

        /*
         * Find question and verify ownership.
         *
         * For bank questions:
         *   test_id IS NULL
         *   teacher_id must match current teacher.
         *
         * For normal test questions:
         *   test_id IS NOT NULL.
         */

        $stmt = $conn->prepare("
            SELECT
                id,
                question_text,
                question_type,
                test_id,
                teacher_id
            FROM new_questions
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param("i", $question_id);
        $stmt->execute();

        $question = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$question) {
            throw new Exception("Question not found.");
        }


        /*
         * Bank mode ownership check.
         */

        if ($isBankMode) {

            if (
                $question['test_id'] !== null ||
                (int)$question['teacher_id'] !== $teacher_id
            ) {
                throw new Exception(
                    "You do not have permission to delete this question."
                );
            }
        }


        /*
         * Get image before deleting answer record.
         */

        if (isset($answer_table_map[$question['question_type']])) {

            $table = $answer_table_map[$question['question_type']];

            $stmt = $conn->prepare("
                SELECT image_path
                FROM $table
                WHERE question_id = ?
                LIMIT 1
            ");

            $stmt->bind_param("i", $question_id);
            $stmt->execute();

            $image = $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if (
                $image &&
                !empty($image['image_path']) &&
                file_exists("../{$image['image_path']}")
            ) {
                unlink("../{$image['image_path']}");
            }


            /*
             * Delete answer record.
             */

            $stmt = $conn->prepare("
                DELETE FROM $table
                WHERE question_id = ?
            ");

            $stmt->bind_param("i", $question_id);
            $stmt->execute();
            $stmt->close();
        }


        /*
         * Delete question itself.
         *
         * Any other answer tables referencing
         * new_questions.id should ideally have
         * ON DELETE CASCADE.
         */

        $stmt = $conn->prepare("
            DELETE FROM new_questions
            WHERE id = ?
        ");

        $stmt->bind_param("i", $question_id);

        if (!$stmt->execute()) {
            throw new Exception(
                "Unable to delete question: " . $stmt->error
            );
        }

        $stmt->close();

        $conn->commit();


        $_SESSION['success'] = "Question deleted successfully!";


        /*
         * Activity log
         */

        $ip_address =
            filter_var(
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                FILTER_VALIDATE_IP
            ) ?: '0.0.0.0';

        $user_agent =
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        $activity =
            "Teacher deleted question ID $question_id: "
            . substr($question['question_text'] ?? '', 0, 50);

        $stmt_log = $conn->prepare("
            INSERT INTO activities_log
            (
                activity,
                admin_id,
                ip_address,
                user_agent,
                created_at
            )
            VALUES (?, ?, ?, ?, NOW())
        ");

        if ($stmt_log) {

            $stmt_log->bind_param(
                "siss",
                $activity,
                $teacher_id,
                $ip_address,
                $user_agent
            );

            $stmt_log->execute();
            $stmt_log->close();
        }

    } catch (Exception $e) {

        $conn->rollback();

        error_log(
            "Question deletion error: "
            . $e->getMessage()
        );

        $_SESSION['error'] = $e->getMessage();
    }

    /* 
    |-------------------------------------------------------------------------- 
    | REDIRECT AFTER DELETE 
    |-------------------------------------------------------------------------- | 
    | If the delete came from the Test Preview modal, 
    | return to bank.php. | 
    | Otherwise preserve the existing redirect behavior. 
    | */
    if ( isset($_POST['redirect_to']) && $_POST['redirect_to'] === 'bank' ) { 
        header("Location: bank.php"); 
        exit; 
    }

    header("Location: " . ($isBankMode ? "bank.php" : "add_question.php"));
    exit();
}


/*
|--------------------------------------------------------------------------
| EDIT QUESTION
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['edit_question'])
) {

    $question_id = (int)($_POST['question_id'] ?? 0);

    if ($question_id <= 0) {
        $_SESSION['error'] = "Invalid question ID.";
        header("Location: " . ($isBankMode ? "bank.php" : "add_question.php"));
        exit();
    }


    $edit_context_columns = $question_universal_columns
        ? ', course_id, organizational_unit_id, academic_period_id'
        : '';
    $stmt = $conn->prepare("
        SELECT
            id,
            question_text,
            question_type,
            test_id,
            teacher_id,
            class,
            subject,
            maximum_marks
            {$edit_context_columns}
        FROM new_questions
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $question_id);
    $stmt->execute();

    $edit_question = $stmt->get_result()->fetch_assoc();

    $stmt->close();


    if (!$edit_question) {

        $_SESSION['error'] = "Question not found.";

    } else {

        /*
         * Bank ownership check.
         */

        if (
            $isBankMode &&
            (
                $edit_question['test_id'] !== null ||
                (int)$edit_question['teacher_id'] !== $teacher_id
            )
        ) {

            $_SESSION['error'] =
                "You do not have permission to edit this question.";

        } else {

            $question_type =
                $edit_question['question_type'];

            if ($question_type === 'theory') {
                $edit_question['options'] = ['maximum_marks' => $edit_question['maximum_marks'] ?? ''];
            }

            if (isset($answer_table_map[$question_type])) {

                $table =
                    $answer_table_map[$question_type];

                switch ($question_type) {

                    case 'multiple_choice_single':

                        $sql = "
                            SELECT
                                option1,
                                option2,
                                option3,
                                option4,
                                correct_answer,
                                image_path
                            FROM $table
                            WHERE question_id = ?
                            LIMIT 1
                        ";

                        break;


                    case 'multiple_choice_multiple':

                        $sql = "
                            SELECT
                                option1,
                                option2,
                                option3,
                                option4,
                                correct_answers,
                                image_path
                            FROM $table
                            WHERE question_id = ?
                            LIMIT 1
                        ";

                        break;


                    case 'true_false':

                    case 'fill_blanks':

                        $sql = "
                            SELECT
                                correct_answer
                            FROM $table
                            WHERE question_id = ?
                            LIMIT 1
                        ";

                        break;


                    default:

                        $sql = null;
                }


                if ($sql) {

                    $stmt = $conn->prepare($sql);

                    $stmt->bind_param(
                        "i",
                        $question_id
                    );

                    $stmt->execute();

                    $edit_question['options'] =
                        $stmt->get_result()->fetch_assoc();

                    $stmt->close();
                }
            }


            $_SESSION['edit_question'] =
                $edit_question;
        }
    }

    header("Location: " . ($isBankMode ? "bank.php" : "add_question.php"));
    exit();
}


/*
|--------------------------------------------------------------------------
| QUESTION SUBMISSION
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['question'])
) {

    $question_id =
        (int)($_POST['question_id'] ?? 0);

    $question_text =
        trim($_POST['question'] ?? '');

    $question_type =
        trim($_POST['question_type'] ?? '');


    $valid_types = [
        'multiple_choice_single',
        'multiple_choice_multiple',
        'true_false',
        'fill_blanks',
        'theory'
    ];


    /*
     * Basic validation.
     */

    $maximum_marks = (float)($_POST['maximum_marks'] ?? 0);

    if (
        $question_text === '' ||
        !in_array($question_type, $valid_types, true) ||
        ($question_type === 'theory' && $maximum_marks <= 0)
    ) {

        $_SESSION['error'] =
            "Question text and valid question type are required.";

        header(
            "Location: " .
            ($isBankMode
                ? "bank.php"
                : "add_question.php")
        );

        exit();
    }


    /*
     |--------------------------------------------------------------------------
     | DETERMINE CLASS, SUBJECT AND TEST
     |--------------------------------------------------------------------------
     */

    $class = '';
    $subject = '';
    $test_id = null;
    $course_id = null;
    $organizational_unit_id = null;
    $academic_period_id = null;


    /*
     * ==========================================================
     * QUESTION BANK MODE
     * ==========================================================
     */

    if ($isBankMode) {

        if (!$is_school_institution) {
            if (!$question_universal_columns) {
                throw new Exception('Question-bank course fields are not installed. Apply the ADDED migration in help.txt first.');
            }
            $course_id = (int)($_POST['course_id'] ?? 0);
            $organizational_unit_id = (int)($_POST['organizational_unit_id'] ?? 0) ?: null;
            $academic_period_id = (int)($_POST['academic_period_id'] ?? 0) ?: null;
            $assigned_course = null;
            foreach ($assigned_courses as $assignment) {
                if ((int)$assignment['course_id'] === $course_id) {
                    $assigned_course = $assignment;
                    break;
                }
            }
            if (!$assigned_course) {
                throw new Exception('You are not assigned to the selected course.');
            }
            $subject = trim((string)$assigned_course['course_name']);
            $class = '';
            if ($organizational_unit_id !== null && (int)($assigned_course['unit_id'] ?? 0) !== $organizational_unit_id) {
                throw new Exception('Selected unit is not part of your course assignment.');
            }
            if ($academic_period_id !== null) {
                $periodCheck = $conn->prepare("SELECT id FROM academic_periods WHERE id = ? AND institution_id = ? AND status IN ('planned', 'active') LIMIT 1");
                $periodCheck->bind_param('ii', $academic_period_id, $institution_id);
                $periodCheck->execute();
                $periodValid = $periodCheck->get_result()->fetch_assoc();
                $periodCheck->close();
                if (!$periodValid) throw new Exception('Invalid academic period for this institution.');
            }
            $test_id = null;
        } else {

        $academic_level_id =
            (int)($_POST['academic_level_id'] ?? 0);


        if ($academic_level_id <= 0) {

            $_SESSION['error'] =
                "Please select a class.";

            header("Location: bank.php");
            exit();
        }


        /*
         * Fetch academic level.
         */

        $stmt = $conn->prepare("
            SELECT level_code, class_group
            FROM academic_levels
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "i",
            $academic_level_id
        );

        $stmt->execute();

        $level =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();


        if (!$level) {

            $_SESSION['error'] =
                "Invalid class selected.";

            header("Location: bank.php");
            exit();
        }

        if (($institution_profile['category'] ?? '') === 'primary_school'
            && strtoupper((string)$level['class_group']) !== 'PRIMARY'
            && preg_match('/^(P|PRI|PRIMARY)/i', (string)$level['level_code']) !== 1) {
            throw new Exception('Select a valid primary school level.');
        }
        if (($institution_profile['category'] ?? '') === 'secondary_school'
            && !in_array(strtoupper((string)$level['class_group']), ['JSS', 'SS'], true)) {
            throw new Exception('Select a valid secondary school level.');
        }


        $class =
            trim($level['level_code']);

        

        /*
        * Determine subject.
        *
        * A teacher can only use subjects assigned to them.
        */

        if (count($assigned_subjects) === 1) {

            $subject = trim($assigned_subjects[0]);

        } else {

            $subject = trim($_POST['subject'] ?? '');

            if ($subject === '') {

                $_SESSION['error'] =
                    "Please select a subject.";

                header("Location: bank.php");
                exit();
            }

            if (
                !in_array(
                    $subject,
                    $assigned_subjects,
                    true
                )
            ) {

                $_SESSION['error'] =
                    "You are not assigned to the selected subject.";

                header("Location: bank.php");
                exit();
            }
        }


        /*
        * ----------------------------------------------------------
        * VALIDATE CLASS ↔ SUBJECT GROUP
        * ----------------------------------------------------------
        *
        * JSS1/JSS2/JSS3 can only use subjects marked (JSS)
        * SS1/SS2/SS3 can only use subjects marked (SS)
        *
        * Example:
        *
        * JSS1 + Mathematics (JSS) = VALID
        * JSS3 + Mathematics (JSS) = VALID
        * SS1  + Mathematics (SS)  = VALID
        * SS3  + Mathematics (SS)  = VALID
        *
        * JSS1 + Mathematics (SS)  = INVALID
        * SS1  + Mathematics (JSS) = INVALID
        */

        $class_group = '';

        if (preg_match('/^(JSS|SS)/i', $class, $matches)) {
            $class_group = strtoupper($matches[1]);
        }


        $subject_group = '';

        if (preg_match('/\((JSS|SS)\)\s*$/i', $subject, $matches)) {
            $subject_group = strtoupper($matches[1]);
        }


        if (
            $class_group !== '' &&
            $subject_group !== '' &&
            $class_group !== $subject_group
        ) {

            $_SESSION['error'] =
                "The selected subject does not belong to the selected class.";

            header("Location: bank.php");
            exit();
        }


        /*
         * Bank questions MUST NOT belong to a test.
         */

        $test_id = null;
        }
    }


    /*
     * ==========================================================
     * NORMAL TEST MODE
     * ==========================================================
     */

    else {

        if (!isset($_SESSION['current_test_id'])) {

            $_SESSION['error'] =
                "Please create or select a test first.";

            header("Location: add_question.php");
            exit();
        }


        $test_id =
            (int)$_SESSION['current_test_id'];


        if ($test_id <= 0) {

            unset($_SESSION['current_test_id']);

            $_SESSION['error'] =
                "Invalid test.";

            header("Location: add_question.php");
            exit();
        }


        /*
         * Get test information.
         */

        if ($is_school_institution) {
            if (empty($assigned_subjects)) throw new Exception('You do not have any assigned subjects.');
            $scopeValues = $assigned_subjects;
            $scopeTypes = str_repeat('s', count($scopeValues));
            $scopeColumn = 't.subject';
            $institutionCondition = '';
        } else {
            $scopeValues = array_values(array_unique(array_map(static fn($assignment) => (int)$assignment['course_id'], $assigned_courses)));
            if (!$scopeValues) throw new Exception('You do not have any assigned courses.');
            $scopeTypes = str_repeat('i', count($scopeValues));
            $scopeColumn = 't.course_id';
            $institutionCondition = ' AND t.institution_id = ?';
        }
        $scopePlaceholders = implode(',', array_fill(0, count($scopeValues), '?'));
        $stmt = $conn->prepare("
            SELECT
                t.id,
                al.class_group,
                t.subject
            FROM tests t
            LEFT JOIN academic_levels al ON al.id = t.academic_level_id
            WHERE t.id = ? AND {$scopeColumn} IN ({$scopePlaceholders}){$institutionCondition}
            LIMIT 1
        ");
        $testParams = array_merge([$test_id], $scopeValues);
        $testTypes = 'i' . $scopeTypes;
        if (!$is_school_institution) {
            $testParams[] = $institution_id;
            $testTypes .= 'i';
        }
        $stmt->bind_param($testTypes, ...$testParams);

        $stmt->execute();

        $test_data =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();


        if (!$test_data) {

            unset($_SESSION['current_test_id']);

            $_SESSION['error'] =
                "Invalid test.";

            header("Location: add_question.php");
            exit();
        }


        $class =
            trim($test_data['class_group']);

        $subject =
            trim($test_data['subject']);
    }


    /*
     |--------------------------------------------------------------------------
     | IMAGE HANDLING
     |--------------------------------------------------------------------------
     */

    $image_path = null;


    if (
        isset($_POST['remove_image']) &&
        $_POST['remove_image'] === 'on' &&
        $question_id > 0
    ) {

        if (
            isset(
                $answer_table_map[$question_type]
            )
        ) {

            $table =
                $answer_table_map[$question_type];

            $stmt = $conn->prepare("
                SELECT image_path
                FROM $table
                WHERE question_id = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                "i",
                $question_id
            );

            $stmt->execute();

            $image =
                $stmt->get_result()->fetch_assoc();

            $stmt->close();


            if (
                $image &&
                !empty($image['image_path']) &&
                file_exists("../{$image['image_path']}")
            ) {

                unlink(
                    "../{$image['image_path']}"
                );
            }


            /*
             * Clear image path in database.
             */

            $stmt = $conn->prepare("
                UPDATE $table
                SET image_path = NULL
                WHERE question_id = ?
            ");

            $stmt->bind_param(
                "i",
                $question_id
            );

            $stmt->execute();
            $stmt->close();
        }

    } else {

        $image_path =
            handleImageUpload(
                $question_id > 0
                    ? $question_id
                    : time()
            );


        if ($image_path === false) {

            $_SESSION['error'] =
                "Image upload failed. Please use a valid JPG, PNG or GIF image under 2MB.";

            header(
                "Location: " .
                ($isBankMode
                    ? "bank.php"
                    : "add_question.php")
            );

            exit();
        }
    }


    /*
     |--------------------------------------------------------------------------
     | SAVE QUESTION
     |--------------------------------------------------------------------------
     */

    $conn->begin_transaction();

    try {

        /*
         * ==========================================================
         * CREATE / UPDATE new_questions
         * ==========================================================
         */

        if ($question_id > 0) {

            /*
             * Load existing question.
             */

            $stmt = $conn->prepare("
                SELECT
                    id,
                    test_id,
                    teacher_id,
                    question_type
                FROM new_questions
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                "i",
                $question_id
            );

            $stmt->execute();

            $existing_question =
                $stmt->get_result()->fetch_assoc();

            $stmt->close();


            if (!$existing_question) {
                throw new Exception(
                    "Question not found."
                );
            }


            /*
             * Permission check for bank question.
             */

            if ($isBankMode) {

                if (
                    $existing_question['test_id'] !== null ||
                    (int)$existing_question['teacher_id'] !== $teacher_id
                ) {
                    throw new Exception(
                        "You do not have permission to edit this question."
                    );
                }
            }


            /*
             * Update bank question.
             */

            if ($isBankMode) {

                if ($question_universal_columns && !$is_school_institution) {
                    $stmt = $conn->prepare("UPDATE new_questions SET question_text = ?, teacher_id = ?, test_id = NULL, class = ?, subject = ?, course_id = ?, organizational_unit_id = ?, academic_period_id = ?, question_type = ? WHERE id = ? AND teacher_id = ? AND test_id IS NULL");
                    $stmt->bind_param('sissiiisii', $question_text, $teacher_id, $class, $subject, $course_id, $organizational_unit_id, $academic_period_id, $question_type, $question_id, $teacher_id);
                } else {

                $stmt = $conn->prepare("
                    UPDATE new_questions
                    SET
                        question_text = ?,
                        teacher_id = ?,
                        test_id = NULL,
                        class = ?,
                        subject = ?,
                        question_type = ?
                    WHERE
                        id = ?
                        AND teacher_id = ?
                        AND test_id IS NULL
                ");

                $stmt->bind_param(
                    "sisssii",
                    $question_text,
                    $teacher_id,
                    $class,
                    $subject,
                    $question_type,
                    $question_id,
                    $teacher_id
                );
                }

            }

            /*
             * Update normal test question.
             */

            else {

                $stmt = $conn->prepare("
                    UPDATE new_questions
                    SET
                        question_text = ?,
                        test_id = ?,
                        class = ?,
                        subject = ?,
                        question_type = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    "sisssi",
                    $question_text,
                    $test_id,
                    $class,
                    $subject,
                    $question_type,
                    $question_id
                );
            }

        }

        /*
         * ==========================================================
         * CREATE NEW QUESTION
         * ==========================================================
         */

        else {

            if ($isBankMode) {

                if ($question_universal_columns && !$is_school_institution) {
                    $stmt = $conn->prepare("INSERT INTO new_questions (question_text, test_id, teacher_id, class, subject, course_id, organizational_unit_id, academic_period_id, question_type, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->bind_param('sissiiis', $question_text, $teacher_id, $class, $subject, $course_id, $organizational_unit_id, $academic_period_id, $question_type);
                } else {

                /*
                 * Bank question:
                 *
                 * test_id = NULL
                 * teacher_id = current teacher
                 */

                $stmt = $conn->prepare("
                    INSERT INTO new_questions
                    (
                        question_text,
                        test_id,
                        teacher_id,
                        class,
                        subject,
                        question_type,
                        created_at
                    )
                    VALUES
                    (
                        ?,
                        NULL,
                        ?,
                        ?,
                        ?,
                        ?,
                        NOW()
                    )
                ");

                $stmt->bind_param(
                    "sisss",
                    $question_text,
                    $teacher_id,
                    $class,
                    $subject,
                    $question_type
                );
                }

            } else {

                /*
                 * Normal test question.
                 */

                $stmt = $conn->prepare("
                    INSERT INTO new_questions
                    (
                        question_text,
                        test_id,
                        teacher_id,
                        class,
                        subject,
                        question_type,
                        created_at
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        NULL,
                        ?,
                        ?,
                        ?,
                        NOW()
                    )
                ");

                $stmt->bind_param(
                    "sisss",
                    $question_text,
                    $test_id,
                    $class,
                    $subject,
                    $question_type
                );
            }
        }


        if (!$stmt) {
            throw new Exception(
                "Prepare question save failed: "
                . $conn->error
            );
        }


        if (!$stmt->execute()) {
            throw new Exception(
                "Unable to save question: "
                . $stmt->error
            );
        }


        if ($question_id <= 0) {
            $question_id =
                $stmt->insert_id;
        }


        $stmt->close();

        // Store maximum marks on the canonical question-bank record.
        if ($question_type === 'theory') {
            $marksStmt = $conn->prepare("UPDATE new_questions SET maximum_marks = ? WHERE id = ?");
            if (!$marksStmt) throw new Exception('Unable to prepare maximum-marks update: ' . $conn->error);
            $marksStmt->bind_param('di', $maximum_marks, $question_id);
        } else {
            $marksStmt = $conn->prepare("UPDATE new_questions SET maximum_marks = NULL WHERE id = ?");
            if (!$marksStmt) throw new Exception('Unable to prepare maximum-marks reset: ' . $conn->error);
            $marksStmt->bind_param('i', $question_id);
        }
        if (!$marksStmt->execute()) throw new Exception('Unable to save maximum marks: ' . $marksStmt->error);
        $marksStmt->close();

        /*
         |--------------------------------------------------------------------------
         | REMOVE OLD ANSWER DATA
         |--------------------------------------------------------------------------
         */

        /*
         * We use the SAME answer tables for:
         *
         * normal questions
         * AND
         * question-bank questions.
         */

        foreach ($answer_table_map as $table) {

            $stmt = $conn->prepare("
                DELETE FROM $table
                WHERE question_id = ?
            ");

            if (!$stmt) {
                throw new Exception(
                    "Unable to prepare answer cleanup."
                );
            }

            $stmt->bind_param(
                "i",
                $question_id
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    "Unable to remove old answer data: "
                    . $stmt->error
                );
            }

            $stmt->close();
        }


        /*
         |--------------------------------------------------------------------------
         | SAVE ANSWER DATA
         |--------------------------------------------------------------------------
         */

        switch ($question_type) {

            /*
             * ==========================================================
             * SINGLE CHOICE
             * ==========================================================
             */

            case 'multiple_choice_single':

                $option1 =
                    trim($_POST['option1'] ?? '');

                $option2 =
                    trim($_POST['option2'] ?? '');

                $option3 =
                    trim($_POST['option3'] ?? '');

                $option4 =
                    trim($_POST['option4'] ?? '');

                $correct_answer =
                    trim($_POST['correct_answer'] ?? '');


                if (
                    $option1 === '' ||
                    $option2 === '' ||
                    $option3 === '' ||
                    $option4 === '' ||
                    !in_array(
                        $correct_answer,
                        ['1', '2', '3', '4'],
                        true
                    )
                ) {

                    throw new Exception(
                        "All four options and a valid correct answer are required."
                    );
                }


                $options = [
                    1 => $option1,
                    2 => $option2,
                    3 => $option3,
                    4 => $option4
                ];


                $correct_text =
                    $options[(int)$correct_answer];


                /*
                 * Preserve existing image if no new image
                 * was uploaded during edit.
                 */

                if ($image_path === null && $question_id > 0) {

                    $stmt = $conn->prepare("
                        SELECT image_path
                        FROM single_choice_questions
                        WHERE question_id = ?
                        LIMIT 1
                    ");

                    $stmt->bind_param(
                        "i",
                        $question_id
                    );

                    $stmt->execute();

                    $old =
                        $stmt->get_result()->fetch_assoc();

                    $stmt->close();

                    $image_path =
                        $old['image_path'] ?? null;
                }


                $stmt = $conn->prepare("
                    INSERT INTO single_choice_questions
                    (
                        question_id,
                        option1,
                        option2,
                        option3,
                        option4,
                        correct_answer,
                        image_path
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    "issssss",
                    $question_id,
                    $option1,
                    $option2,
                    $option3,
                    $option4,
                    $correct_text,
                    $image_path
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        "Unable to save options: "
                        . $stmt->error
                    );
                }

                $stmt->close();

                break;


            /*
             * ==========================================================
             * MULTIPLE CHOICE
             * ==========================================================
             */

            case 'multiple_choice_multiple':

                $option1 =
                    trim($_POST['option1'] ?? '');

                $option2 =
                    trim($_POST['option2'] ?? '');

                $option3 =
                    trim($_POST['option3'] ?? '');

                $option4 =
                    trim($_POST['option4'] ?? '');


                $correct_answers =
                    isset($_POST['correct_answers'])
                        ? array_map(
                            'intval',
                            (array)$_POST['correct_answers']
                        )
                        : [];


                if (
                    $option1 === '' ||
                    $option2 === '' ||
                    $option3 === '' ||
                    $option4 === '' ||
                    empty($correct_answers)
                ) {

                    throw new Exception(
                        "All four options and at least one correct answer are required."
                    );
                }


                $options = [
                    1 => $option1,
                    2 => $option2,
                    3 => $option3,
                    4 => $option4
                ];


                $correct_texts = [];


                foreach ($correct_answers as $index) {

                    if (!isset($options[$index])) {
                        throw new Exception(
                            "Invalid correct answer selected."
                        );
                    }

                    $correct_texts[] =
                        $options[$index];
                }


                $correct_text =
                    implode(',', $correct_texts);


                if (
                    $image_path === null &&
                    $question_id > 0
                ) {

                    $stmt = $conn->prepare("
                        SELECT image_path
                        FROM multiple_choice_questions
                        WHERE question_id = ?
                        LIMIT 1
                    ");

                    $stmt->bind_param(
                        "i",
                        $question_id
                    );

                    $stmt->execute();

                    $old =
                        $stmt->get_result()->fetch_assoc();

                    $stmt->close();

                    $image_path =
                        $old['image_path'] ?? null;
                }


                $stmt = $conn->prepare("
                    INSERT INTO multiple_choice_questions
                    (
                        question_id,
                        option1,
                        option2,
                        option3,
                        option4,
                        correct_answers,
                        image_path
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    "issssss",
                    $question_id,
                    $option1,
                    $option2,
                    $option3,
                    $option4,
                    $correct_text,
                    $image_path
                );


                if (!$stmt->execute()) {
                    throw new Exception(
                        "Unable to save options: "
                        . $stmt->error
                    );
                }

                $stmt->close();

                break;


            /*
             * ==========================================================
             * TRUE / FALSE
             * ==========================================================
             */

            case 'true_false':

                $correct_answer =
                    trim($_POST['correct_answer'] ?? '');


                if (
                    !in_array(
                        $correct_answer,
                        ['True', 'False'],
                        true
                    )
                ) {

                    throw new Exception(
                        "A valid True/False answer is required."
                    );
                }


                $stmt = $conn->prepare("
                    INSERT INTO true_false_questions
                    (
                        question_id,
                        correct_answer
                    )
                    VALUES (?, ?)
                ");

                $stmt->bind_param(
                    "is",
                    $question_id,
                    $correct_answer
                );


                if (!$stmt->execute()) {
                    throw new Exception(
                        "Unable to save True/False answer: "
                        . $stmt->error
                    );
                }

                $stmt->close();

                break;


            /*
             * ==========================================================
             * FILL IN THE BLANK
             * ==========================================================
             */

            case 'theory':
                // Theory bank rows intentionally have no objective answer record.
                if (!$isBankMode) {
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
                    $theoryStmt->bind_param('iisd', $assessmentId, $number, $question_text, $maximum_marks);
                    if (!$theoryStmt->execute()) throw new Exception('Unable to save theory question: ' . $theoryStmt->error);
                    $theoryStmt->close();
                }
                break;

            case 'fill_blanks':

                $correct_answer =
                    trim($_POST['correct_answer'] ?? '');


                if ($correct_answer === '') {

                    throw new Exception(
                        "A correct answer is required."
                    );
                }


                $stmt = $conn->prepare("
                    INSERT INTO fill_blank_questions
                    (
                        question_id,
                        correct_answer
                    )
                    VALUES (?, ?)
                ");

                $stmt->bind_param(
                    "is",
                    $question_id,
                    $correct_answer
                );


                if (!$stmt->execute()) {
                    throw new Exception(
                        "Unable to save fill-blank answer: "
                        . $stmt->error
                    );
                }

                $stmt->close();

                break;
        }


        /*
         |--------------------------------------------------------------------------
         | COMMIT
         |--------------------------------------------------------------------------
         */

        $conn->commit();


        $_SESSION['success'] =
            $isBankMode
                ? "Question saved to the Question Bank successfully!"
                : "Question saved successfully!";


        /*
         |--------------------------------------------------------------------------
         | ACTIVITY LOG
         |--------------------------------------------------------------------------
         */

        $ip_address =
            filter_var(
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                FILTER_VALIDATE_IP
            ) ?: '0.0.0.0';

        $user_agent =
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';


        $activity =
            $isBankMode
                ? "Teacher saved question bank question ID $question_id: "
                    . substr($question_text, 0, 50)
                : "Teacher saved question ID $question_id: "
                    . substr($question_text, 0, 50);


        // Activity logging is optional; a missing log table must not turn a
        // successfully committed question into a reported save failure.
        try {
            if (examcenterUniversalTableExists($conn, 'activities_log')) {
                $stmt_log = $conn->prepare("
                    INSERT INTO activities_log
                    (
                        activity,
                        admin_id,
                        ip_address,
                        user_agent,
                        created_at
                    )
                    VALUES (?, ?, ?, ?, NOW())
                ");

                $stmt_log->bind_param(
                    "siss",
                    $activity,
                    $teacher_id,
                    $ip_address,
                    $user_agent
                );
                $stmt_log->execute();
                $stmt_log->close();
            }
        } catch (Throwable $loggingError) {
            // Logging errors are isolated from the teacher-facing save result.
        }

    } catch (Exception $e) {

        $conn->rollback();

        error_log(
            "Question save error: "
            . $e->getMessage()
        );

        $_SESSION['error'] =
            "Error saving question: "
            . $e->getMessage();
    }


    /*
     |--------------------------------------------------------------------------
     | REDIRECT
     |--------------------------------------------------------------------------
     */

    header(
        "Location: " .
        ($isBankMode
            ? "bank.php"
            : "add_question.php")
    );

    exit();
}


$conn->close();

?>
