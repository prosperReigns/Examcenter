<?php
session_start();

require_once '../db.php';
require_once '../includes/system_guard.php';
require_once '../includes/universal_architecture.php';
require_once '../license/license_guard.php';

if (
    !isset($_SESSION['user_id'], $_SESSION['user_role'])
    || strtolower((string)$_SESSION['user_role']) !== 'teacher'
) {
    header('Location: ../login.php?error=Not logged in');
    exit();
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$database = Database::getInstance();
$conn = $database->getConnection();
if ($conn->connect_error) {
    error_log('Teacher question list database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    exit('Unable to load questions right now. Please try again later.');
}
$conn->set_charset('utf8mb4');

$teacherId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT username, last_name FROM teachers WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $teacherId);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$teacher) {
    session_destroy();
    header('Location: ../login.php?error=Unauthorized');
    exit();
}

$institutionId = examcenterActiveInstitutionId($conn);
$institutionProfile = examcenterInstitutionProfile($conn);
$isSchool = $institutionId === null || in_array(
    $institutionProfile['category'] ?? '',
    ['primary_school', 'secondary_school'],
    true
);
$assignedSubjects = [];
$assignedCourses = [];
if ($isSchool) {
    $stmt = $conn->prepare('SELECT DISTINCT subject FROM teacher_subjects WHERE teacher_id = ? ORDER BY subject');
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $subject = trim((string)($row['subject'] ?? ''));
        if ($subject !== '') {
            $assignedSubjects[] = $subject;
        }
    }
    $stmt->close();
} else {
    $assignedCourses = examcenterTeacherCourseAssignments($conn, $teacherId, $institutionId);
    foreach ($assignedCourses as $assignment) {
        $subject = trim((string)($assignment['course_name'] ?? ''));
        if ($subject !== '') {
            $assignedSubjects[] = $subject;
        }
    }
}
$assignedSubjects = array_values(array_unique($assignedSubjects));

$subjectFilter = trim((string)($_GET['subject'] ?? ''));
$classFilter = $isSchool ? trim((string)($_GET['class'] ?? '')) : '';
$search = trim((string)($_GET['search'] ?? ''));
if ($subjectFilter !== '' && !in_array($subjectFilter, $assignedSubjects, true)) {
    $subjectFilter = '';
}

$classes = [];
$questions = [];
$totalQuestions = 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page - 1) * $pageSize;

if ($assignedSubjects) {
    $subjectMarks = implode(',', array_fill(0, count($assignedSubjects), '?'));
    $scopeSql = 'q.teacher_id = ? AND q.subject IN (' . $subjectMarks . ')';
    $scopeTypes = 'i' . str_repeat('s', count($assignedSubjects));
    $scopeParams = array_merge([$teacherId], $assignedSubjects);

    if (!$isSchool && $assignedCourses && examcenterUniversalColumnExists($conn, 'new_questions', 'course_id')) {
        $courseIds = array_values(array_unique(array_map(
            static fn($assignment): int => (int)$assignment['course_id'],
            $assignedCourses
        )));
        if ($courseIds) {
            $scopeSql .= ' AND q.course_id IN (' . implode(',', array_fill(0, count($courseIds), '?')) . ')';
            $scopeTypes .= str_repeat('i', count($courseIds));
            $scopeParams = array_merge($scopeParams, $courseIds);
        }
    }

    if ($isSchool) {
        $classesSql = 'SELECT DISTINCT q.class FROM new_questions q WHERE ' . $scopeSql . ' AND q.class IS NOT NULL AND q.class <> "" ORDER BY q.class';
        $stmt = $conn->prepare($classesSql);
        $stmt->bind_param($scopeTypes, ...$scopeParams);
        $stmt->execute();
        $classes = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'class');
        $stmt->close();
        if ($classFilter !== '' && !in_array($classFilter, $classes, true)) {
            $classFilter = '';
        }
    }

    $where = $scopeSql;
    $types = $scopeTypes;
    $params = $scopeParams;
    if ($subjectFilter !== '') {
        $where .= ' AND q.subject = ?';
        $types .= 's';
        $params[] = $subjectFilter;
    }
    if ($classFilter !== '') {
        $where .= ' AND q.class = ?';
        $types .= 's';
        $params[] = $classFilter;
    }
    if ($search !== '') {
        $where .= ' AND q.question_text LIKE ?';
        $types .= 's';
        $params[] = '%' . $search . '%';
    }

    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM new_questions q WHERE ' . $where);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalQuestions = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $query = 'SELECT q.id, q.question_text, q.class, q.subject, q.question_type, q.test_id, q.created_at, t.title AS test_title
              FROM new_questions q
              LEFT JOIN tests t ON t.id = q.test_id
              WHERE ' . $where . '
              ORDER BY q.created_at DESC, q.id DESC
              LIMIT ? OFFSET ?';
    $types .= 'ii';
    $params[] = $pageSize;
    $params[] = $offset;
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$totalPages = max(1, (int)ceil($totalQuestions / $pageSize));
$queryParams = [
    'subject' => $subjectFilter,
    'class' => $classFilter,
    'search' => $search,
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>View Questions | Examcenter</title>
    <style>
        :root { color-scheme: light; --ink:#182230; --muted:#667085; --line:#e5eaf0; --brand:#2459a6; --surface:#fff; --page:#f4f7fb; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--page); color:var(--ink); font:15px/1.5 Arial,sans-serif; }
        .topbar { display:flex; justify-content:space-between; align-items:center; gap:20px; padding:18px clamp(18px,4vw,48px); background:#fff; border-bottom:1px solid var(--line); }
        .brand { font-weight:700; color:var(--brand); font-size:19px; text-decoration:none; }
        .nav { display:flex; gap:16px; flex-wrap:wrap; }
        .nav a { color:#475467; text-decoration:none; font-weight:600; }
        .nav a.active { color:var(--brand); }
        main { width:min(1180px, calc(100% - 32px)); margin:32px auto; }
        .heading { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; margin-bottom:22px; }
        h1 { margin:0 0 6px; font-size:28px; }
        p { margin:0; color:var(--muted); }
        .button { display:inline-flex; align-items:center; justify-content:center; border:0; border-radius:8px; padding:10px 15px; color:#fff; background:var(--brand); text-decoration:none; font-weight:700; cursor:pointer; }
        .button.secondary { color:#344054; background:#eef2f7; }
        .panel { background:var(--surface); border:1px solid var(--line); border-radius:12px; box-shadow:0 4px 14px #1018280a; margin-bottom:20px; }
        .filters { padding:18px; display:grid; grid-template-columns: minmax(180px,1fr) minmax(160px,.8fr) minmax(220px,1.2fr) auto; gap:14px; align-items:end; }
        label { display:block; font-size:13px; color:#475467; font-weight:700; margin-bottom:6px; }
        input,select { width:100%; min-height:42px; padding:9px 11px; border:1px solid #d0d5dd; border-radius:7px; color:var(--ink); background:#fff; }
        .list-head { display:flex; justify-content:space-between; align-items:center; padding:18px 20px; border-bottom:1px solid var(--line); }
        .list-head h2 { margin:0; font-size:18px; }
        .count { color:var(--muted); font-size:14px; }
        .question { padding:18px 20px; border-bottom:1px solid var(--line); }
        .question:last-child { border-bottom:0; }
        .question-top { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; }
        .question-text { white-space:pre-wrap; overflow-wrap:anywhere; margin:0; }
        .meta { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
        .tag { display:inline-block; padding:4px 9px; border-radius:999px; background:#f0f4fa; color:#475467; font-size:12px; }
        .empty { text-align:center; padding:44px 20px; color:var(--muted); }
        .pager { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-top:1px solid var(--line); }
        @media(max-width:760px) { .topbar,.heading { align-items:flex-start; flex-direction:column; } .filters { grid-template-columns:1fr; } .question-top { flex-direction:column; } }
    </style>
</head>
<body>
<header class="topbar">
    <a class="brand" href="dashboard.php">Examcenter</a>
    <nav class="nav" aria-label="Teacher navigation">
        <a href="dashboard.php">Dashboard</a>
        <a href="add_question.php">Add Questions</a>
            <a href="add_question.php#theory-assessment">
                <i class="fas fa-pen-to-square"></i>
                Theory Questions
            </a>
        <a href="bank.php">Question Bank</a>
        <a class="active" href="view_questions.php">View Questions</a>
        <a href="manage_test.php">Manage Tests</a>
        <a href="view_results.php">Exam Results</a>
    </nav>
</header>
<main>
    <div class="heading">
        <div>
            <h1>Questions</h1>
            <p>Browse questions you created, filtered by your assigned <?= $isSchool ? 'subject' : 'course' ?>.</p>
        </div>
        <a class="button" href="add_question.php">Add a question</a>
    </div>

    <form class="panel filters" method="get" action="view_questions.php">
        <div>
            <label for="subject"> <?= $isSchool ? 'Subject' : 'Course' ?></label>
            <select name="subject" id="subject">
                <option value="">All assigned <?= $isSchool ? 'subjects' : 'courses' ?></option>
                <?php foreach ($assignedSubjects as $subject): ?>
                    <option value="<?= e($subject) ?>" <?= $subjectFilter === $subject ? 'selected' : '' ?>><?= e($subject) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($isSchool): ?>
        <div>
            <label for="class">Class</label>
            <select name="class" id="class">
                <option value="">All classes</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= e($class) ?>" <?= $classFilter === $class ? 'selected' : '' ?>><?= e($class) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label for="search">Question text</label>
            <input type="search" id="search" name="search" value="<?= e($search) ?>" placeholder="Search question text">
        </div>
        <div style="display:flex;gap:8px">
            <button class="button" type="submit">Filter</button>
            <a class="button secondary" href="view_questions.php">Clear</a>
        </div>
    </form>

    <section class="panel" aria-labelledby="questions-heading">
        <div class="list-head">
            <h2 id="questions-heading">Available questions</h2>
            <span class="count"><?= number_format($totalQuestions) ?> question<?= $totalQuestions === 1 ? '' : 's' ?></span>
        </div>
        <?php if (!$assignedSubjects): ?>
            <div class="empty">No subjects or courses are assigned to your account yet.</div>
        <?php elseif (!$questions): ?>
            <div class="empty">No questions match this selection. Try another subject or add questions for this subject.</div>
        <?php else: ?>
            <?php foreach ($questions as $index => $question): ?>
                <article class="question">
                    <div class="question-top">
                        <p class="question-text"><strong><?= number_format($offset + $index + 1) ?>.</strong> <?= nl2br(e($question['question_text'])) ?></p>
                    </div>
                    <div class="meta">
                        <?php if ($isSchool && !empty($question['class'])): ?><span class="tag"><?= e($question['class']) ?></span><?php endif; ?>
                        <span class="tag"><?= e($question['subject']) ?></span>
                        <span class="tag"><?= e(ucwords(str_replace('_', ' ', (string)$question['question_type']))) ?></span>
                        <span class="tag"><?= $question['test_id'] === null ? 'Question bank' : e($question['test_title'] ?: 'Assigned test') ?></span>
                        <?php if (!empty($question['created_at'])): ?><span class="tag">Added <?= e(date('M j, Y', strtotime((string)$question['created_at']))) ?></span><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if ($totalPages > 1): ?>
                <div class="pager">
                    <span>Page <?= $page ?> of <?= $totalPages ?></span>
                    <div style="display:flex;gap:8px">
                        <?php if ($page > 1): ?><a class="button secondary" href="?<?= e(http_build_query(array_merge($queryParams, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?>
                        <?php if ($page < $totalPages): ?><a class="button" href="?<?= e(http_build_query(array_merge($queryParams, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
<?php $conn->close(); ?>
