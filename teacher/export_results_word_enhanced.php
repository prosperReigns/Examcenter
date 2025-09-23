<?php
session_start();
require_once '../db.php';
require_once '../vendor/autoload.php'; // For PHPWord

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\SimpleType\JcTable;
use PhpOffice\PhpWord\Style\Table;

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'teacher') {
    error_log("Unauthorized access attempt to export results");
    header("Location: /EXAMCENTER/login.php?error=Unauthorized");
    exit();
}

// Initialize database connection
try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

    // Fetch teacher profile
    $teacher_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, last_name, first_name FROM teachers WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for teacher profile: " . $conn->error);
        die("Database error");
    }
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$teacher) {
        error_log("No teacher found for user_id=$teacher_id");
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }

    // Fetch assigned subjects
    $stmt = $conn->prepare("SELECT subject FROM teacher_subjects WHERE id = ?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assigned_subjects = [];
    while ($row = $result->fetch_assoc()) {
        $assigned_subjects[] = $row['subject'];
    }
    $stmt->close();

    // Get filter parameters
    $class_filter = $_GET['selected_class'] ?? '';
    $subject_filter = $_GET['selected_subject'] ?? '';
    $test_title_filter = $_GET['selected_title'] ?? '';
    $student_id_filter = $_GET['student_id'] ?? '';
    $detailed_report = isset($_GET['detailed_report']) && $_GET['detailed_report'] == '1';

    // Build the query
    $query = "SELECT r.*, s.name AS student_name, s.class AS student_class, s.admission_number, 
                   t.subject, t.title AS test_title, t.class AS test_class, t.description,
                   t.duration, t.created_at AS test_created_at
            FROM results r
            JOIN students s ON r.user_id = s.id
            JOIN tests t ON r.test_id = t.id
            WHERE t.subject IN (" . implode(',', array_fill(0, count($assigned_subjects), '?')) . ")";

    $params = $assigned_subjects;
    $types = str_repeat('s', count($assigned_subjects));

    // Apply filters
    if (!empty($test_title_filter)) {
        $query .= " AND t.title = ?";
        $params[] = $test_title_filter;
        $types .= 's';
    }

    if (!empty($class_filter)) {
        $query .= " AND s.class = ?";
        $params[] = $class_filter;
        $types .= 's';
    }

    if (!empty($subject_filter)) {
        $query .= " AND t.subject = ?";
        $params[] = $subject_filter;
        $types .= 's';
    }

    if (!empty($student_id_filter)) {
        $query .= " AND s.id = ?";
        $params[] = $student_id_filter;
        $types .= 'i';
    }

    $query .= " ORDER BY r.created_at DESC";

    // Prepare and execute the query
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed for results query: " . $conn->error);
        die("Database error");
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $results = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($results)) {
        die("No results found matching your criteria.");
    }

    // Create a new PHPWord instance
    $phpWord = new PhpWord();
    
    // Add document properties
    $properties = $phpWord->getDocumentProperties();
    $properties->setCreator('D-Portal CBT System');
    $properties->setCompany('School Name');
    $properties->setTitle('Exam Results Report');
    $properties->setDescription('Exam results export from D-Portal CBT');
    $properties->setCategory('Exam Results');
    $properties->setLastModifiedBy($teacher['username']);
    
    // Define styles
    $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16, 'color' => '4361ee']);
    $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14, 'color' => '000000']);
    
    $headerStyle = ['bold' => true, 'size' => 12];
    $normalStyle = ['size' => 11];
    $smallStyle = ['size' => 10];
    
    $tableStyle = ['borderSize' => 6, 'borderColor' => '4361ee', 'cellMargin' => 80];
    $firstRowStyle = ['bgColor' => '4361ee', 'textColor' => 'FFFFFF', 'bold' => true];
    $cellStyle = ['valign' => 'center'];
    
    // Create the main section
    $section = $phpWord->addSection();
    
    // Add header with logo and school name
    $header = $section->addHeader();
    $header->addText('D-Portal CBT System', ['bold' => true, 'size' => 12, 'color' => '4361ee'], ['alignment' => 'center']);
    
    // Add footer with page number
    $footer = $section->addFooter();
    $footer->addPreserveText('Page {PAGE} of {NUMPAGES}', null, ['alignment' => 'center']);
    
    // Add title
    $section->addTitle('Exam Results Report', 1);
    
    // Add report metadata
    $section->addText('Generated on: ' . date('F j, Y g:i A'), $normalStyle);
    $section->addText('Teacher: ' . $teacher['last_name'] . ' ' . $teacher['first_name'], $normalStyle);
    
    // Add filter information
    $section->addTextBreak(1);
    $section->addTitle('Report Filters', 2);
    $filterTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 80]);
    
    if (!empty($test_title_filter)) {
        $filterRow = $filterTable->addRow();
        $filterRow->addCell(1500)->addText('Test Title:', ['bold' => true]);
        $filterRow->addCell(4000)->addText($test_title_filter);
    }
    
    if (!empty($class_filter)) {
        $filterRow = $filterTable->addRow();
        $filterRow->addCell(1500)->addText('Class:', ['bold' => true]);
        $filterRow->addCell(4000)->addText($class_filter);
    }
    
    if (!empty($subject_filter)) {
        $filterRow = $filterTable->addRow();
        $filterRow->addCell(1500)->addText('Subject:', ['bold' => true]);
        $filterRow->addCell(4000)->addText($subject_filter);
    }
    
    if (!empty($student_id_filter)) {
        $student_name = $results[0]['student_name'] ?? 'Unknown';
        $filterRow = $filterTable->addRow();
        $filterRow->addCell(1500)->addText('Student:', ['bold' => true]);
        $filterRow->addCell(4000)->addText($student_name);
    }
    
    $section->addTextBreak(1);
    
    // Add results summary
    $section->addTitle('Results Summary', 2);
    $section->addText('Total Results: ' . count($results), $normalStyle);
    
    // Calculate overall statistics
    $totalScore = 0;
    $totalQuestions = 0;
    $highestScore = 0;
    $lowestScore = 100;
    $scoreDistribution = [
        '90-100' => 0,
        '80-89' => 0,
        '70-79' => 0,
        '60-69' => 0,
        '50-59' => 0,
        'Below 50' => 0
    ];
    
    foreach ($results as $result) {
        $totalScore += $result['score'];
        $totalQuestions += $result['total_questions'];
        $percentage = round(($result['score'] / $result['total_questions']) * 100, 2);
        
        if ($percentage > $highestScore) $highestScore = $percentage;
        if ($percentage < $lowestScore) $lowestScore = $percentage;
        
        if ($percentage >= 90) $scoreDistribution['90-100']++;
        elseif ($percentage >= 80) $scoreDistribution['80-89']++;
        elseif ($percentage >= 70) $scoreDistribution['70-79']++;
        elseif ($percentage >= 60) $scoreDistribution['60-69']++;
        elseif ($percentage >= 50) $scoreDistribution['50-59']++;
        else $scoreDistribution['Below 50']++;
    }
    
    $averageScore = count($results) > 0 ? round(($totalScore / $totalQuestions) * 100, 2) : 0;
    
    // Add statistics table
    $statsTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 80]);
    
    $statsRow = $statsTable->addRow();
    $statsRow->addCell(2000)->addText('Average Score:', ['bold' => true]);
    $statsRow->addCell(1500)->addText($averageScore . '%');
    $statsRow->addCell(2000)->addText('Highest Score:', ['bold' => true]);
    $statsRow->addCell(1500)->addText($highestScore . '%');
    
    $statsRow = $statsTable->addRow();
    $statsRow->addCell(2000)->addText('Lowest Score:', ['bold' => true]);
    $statsRow->addCell(1500)->addText($lowestScore . '%');
    $statsRow->addCell(2000)->addText('Total Students:', ['bold' => true]);
    $statsRow->addCell(1500)->addText(count($results));
    
    $section->addTextBreak(1);
    
    // Add score distribution
    $section->addTitle('Score Distribution', 2);
    $distTable = $section->addTable(['borderSize' => 1, 'borderColor' => '999999', 'cellMargin' => 80]);
    
    $distRow = $distTable->addRow();
    $distRow->addCell(2000, $firstRowStyle)->addText('Score Range', ['color' => 'FFFFFF', 'bold' => true]);
    $distRow->addCell(2000, $firstRowStyle)->addText('Number of Students', ['color' => 'FFFFFF', 'bold' => true]);
    $distRow->addCell(2000, $firstRowStyle)->addText('Percentage', ['color' => 'FFFFFF', 'bold' => true]);
    
    foreach ($scoreDistribution as $range => $count) {
        $percentage = count($results) > 0 ? round(($count / count($results)) * 100, 2) : 0;
        $distRow = $distTable->addRow();
        $distRow->addCell(2000)->addText($range);
        $distRow->addCell(2000)->addText($count);
        $distRow->addCell(2000)->addText($percentage . '%');
    }
    
    $section->addTextBreak(1);
    
    // Add detailed results table
    $section->addTitle('Detailed Results', 2);
    $table = $section->addTable($tableStyle);
    
    // Add header row
    $table->addRow(400, ['bgColor' => '4361ee']);
    $table->addCell(2000, $cellStyle)->addText('Student', ['color' => 'FFFFFF', 'bold' => true]);
    $table->addCell(1000, $cellStyle)->addText('Class', ['color' => 'FFFFFF', 'bold' => true]);
    $table->addCell(2000, $cellStyle)->addText('Test Title', ['color' => 'FFFFFF', 'bold' => true]);
    $table->addCell(1500, $cellStyle)->addText('Subject', ['color' => 'FFFFFF', 'bold' => true]);
    $table->addCell(1000, $cellStyle)->addText('Score', ['color' => 'FFFFFF', 'bold' => true]);
    $table->addCell(1000, $cellStyle)->addText('Percentage', ['color' => 'FFFFFF', 'bold' => true]);
    $table->addCell(1500, $cellStyle)->addText('Date', ['color' => 'FFFFFF', 'bold' => true]);
    
    // Add data rows
    foreach ($results as $result) {
        $percentage = round(($result['score'] / $result['total_questions']) * 100, 2);
        $textColor = $percentage >= 70 ? '28a745' : ($percentage >= 50 ? 'ffc107' : 'dc3545');
        
        $table->addRow();
        $table->addCell(2000, $cellStyle)->addText($result['student_name']);
        $table->addCell(1000, $cellStyle)->addText($result['student_class']);
        $table->addCell(2000, $cellStyle)->addText($result['test_title']);
        $table->addCell(1500, $cellStyle)->addText($result['subject']);
        $table->addCell(1000, $cellStyle)->addText($result['score'] . '/' . $result['total_questions']);
        $table->addCell(1000, $cellStyle)->addText($percentage . '%', ['color' => $textColor]);
        $table->addCell(1500, $cellStyle)->addText(date('M j, Y g:i A', strtotime($result['created_at'])));
    }
    
    // If detailed report is requested and we have a single student
    if ($detailed_report && !empty($student_id_filter) && count($results) > 0) {
        $section->addPageBreak();
        $section->addTitle('Student Detailed Report', 1);
        
        $result = $results[0]; // Get the first result for this student
        
        // Add student information
        $section->addTitle('Student Information', 2);
        $studentTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 80]);
        
        $studentRow = $studentTable->addRow();
        $studentRow->addCell(2000)->addText('Name:', ['bold' => true]);
        $studentRow->addCell(4000)->addText($result['student_name']);
        
        $studentRow = $studentTable->addRow();
        $studentRow->addCell(2000)->addText('Class:', ['bold' => true]);
        $studentRow->addCell(4000)->addText($result['student_class']);
        
        $studentRow = $studentTable->addRow();
        $studentRow->addCell(2000)->addText('Admission Number:', ['bold' => true]);
        $studentRow->addCell(4000)->addText($result['admission_number'] ?? 'N/A');
        
        $section->addTextBreak(1);
        
        // Add test information
        $section->addTitle('Test Information', 2);
        $testTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 80]);
        
        $testRow = $testTable->addRow();
        $testRow->addCell(2000)->addText('Test Title:', ['bold' => true]);
        $testRow->addCell(4000)->addText($result['test_title']);
        
        $testRow = $testTable->addRow();
        $testRow->addCell(2000)->addText('Subject:', ['bold' => true]);
        $testRow->addCell(4000)->addText($result['subject']);
        
        $testRow = $testTable->addRow();
        $testRow->addCell(2000)->addText('Description:', ['bold' => true]);
        $testRow->addCell(4000)->addText($result['description'] ?? 'N/A');
        
        $testRow = $testTable->addRow();
        $testRow->addCell(2000)->addText('Duration:', ['bold' => true]);
        $testRow->addCell(4000)->addText($result['duration'] ?? 'N/A');
        
        $testRow = $testTable->addRow();
        $testRow->addCell(2000)->addText('Date Taken:', ['bold' => true]);
        $testRow->addCell(4000)->addText(date('M j, Y g:i A', strtotime($result['created_at'])));
        
        $section->addTextBreak(1);
        
        // Add result information
        $section->addTitle('Result Summary', 2);
        $resultTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 80]);
        
        $resultRow = $resultTable->addRow();
        $resultRow->addCell(2000)->addText('Score:', ['bold' => true]);
        $resultRow->addCell(4000)->addText($result['score'] . ' out of ' . $result['total_questions']);
        
        $percentage = round(($result['score'] / $result['total_questions']) * 100, 2);
        $resultRow = $resultTable->addRow();
        $resultRow->addCell(2000)->addText('Percentage:', ['bold' => true]);
        $resultRow->addCell(4000)->addText($percentage . '%');
        
        $grade = '';
        if ($percentage >= 90) $grade = 'A+ (Excellent)';
        elseif ($percentage >= 80) $grade = 'A (Very Good)';
        elseif ($percentage >= 70) $grade = 'B (Good)';
        elseif ($percentage >= 60) $grade = 'C (Average)';
        elseif ($percentage >= 50) $grade = 'D (Pass)';
        else $grade = 'F (Fail)';
        
        $resultRow = $resultTable->addRow();
        $resultRow->addCell(2000)->addText('Grade:', ['bold' => true]);
        $resultRow->addCell(4000)->addText($grade);
        
        // Try to get question details if available
        if (!empty($result['test_id']) && !empty($result['user_id'])) {
            $section->addTextBreak(1);
            $section->addTitle('Question Analysis', 2);
            
            // Query to get question responses if available
            $query = "SELECT q.question_text, q.question_type, r.selected_option, r.correct_option, r.is_correct 
                      FROM question_responses r 
                      JOIN questions q ON r.question_id = q.id 
                      WHERE r.test_id = ? AND r.user_id = ? 
                      ORDER BY r.question_order";
            
            $stmt = $conn->prepare($query);
            if ($stmt) {
                $stmt->bind_param("ii", $result['test_id'], $result['user_id']);
                $stmt->execute();
                $questions_result = $stmt->get_result();
                $questions = $questions_result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                if (!empty($questions)) {
                    $questionTable = $section->addTable(['borderSize' => 1, 'borderColor' => '999999', 'cellMargin' => 80]);
                    
                    // Header row
                    $questionTable->addRow();
                    $questionTable->addCell(2000)->addText('Question', ['bold' => true]);
                    $questionTable->addCell(2000)->addText('Type', ['bold' => true]);
                    $questionTable->addCell(2000)->addText('Your Answer', ['bold' => true]);
                    $questionTable->addCell(2000)->addText('Correct Answer', ['bold' => true]);
                    $questionTable->addCell(1500)->addText('Result', ['bold' => true]);

                    foreach ($questions as $q) {
                        $questionTable->addRow();
                        $questionTable->addCell(2000)->addText(strip_tags(substr($q['question_text'], 0, 80)) . '...');
                        $questionTable->addCell(2000)->addText($q['question_type']);
                        $questionTable->addCell(2000)->addText($q['selected_option'] ?? 'N/A');
                        $questionTable->addCell(2000)->addText($q['correct_option'] ?? 'N/A');
                        
                        $resultText = $q['is_correct'] ? 'Correct' : 'Incorrect';
                        $resultColor = $q['is_correct'] ? '28a745' : 'dc3545';
                        $questionTable->addCell(1500)->addText($resultText, ['color' => $resultColor, 'bold' => true]);
                    }
                } else {
                    $section->addText('No detailed question analysis available.', $smallStyle);
                }
            }
        }
    }

    // Finally, output the Word file
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment;filename="exam_results_' . date('Y-m-d_H-i-s') . '.docx"');
    header('Cache-Control: max-age=0');

    $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
    $objWriter->save('php://output');
    exit();

} catch (Exception $e) {
    error_log("Export error: " . $e->getMessage());
    die("System error during export");
}