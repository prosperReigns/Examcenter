<?php
session_start();
require_once '../db.php';
require_once '../includes/system_guard.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/errors.log');

// Check super admin authentication
if (!isset($_SESSION['user_id'])) {
    header("Location: /EXAMCENTER/login.php?error=Not logged in");
    exit();
}

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();

    $user_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, role FROM super_admins WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $super_admin = $result->fetch_assoc();
    $stmt->close();

    if (!$super_admin || strtolower($super_admin['role']) !== 'super_admin') {
        session_destroy();
        header("Location: /EXAMCENTER/login.php?error=Unauthorized");
        exit();
    }
} catch (Exception $e) {
    error_log("Page error: " . $e->getMessage());
    die("System error");
}

// Initialize messages
$success = '';
$errorMsg = '';

// We'll respond JSON for AJAX 'action' requests.
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    header('Content-Type: application/json; charset=utf-8');

    // 1) Fetch sessions for a year
    if ($action === 'get_sessions' && !empty($_GET['year'])) {
        $year = $_GET['year'];
        $stmt = $conn->prepare("SELECT DISTINCT session, status FROM academic_years WHERE year = ? AND session IS NOT NULL ORDER BY session ASC");
        $stmt->bind_param("s", $year);
        $stmt->execute();
        $res = $stmt->get_result();
        $sessions = [];
        while ($r = $res->fetch_assoc()) {
            $sessions[] = $r['session'];
        }
        $stmt->close();

        // Determine year status (active if any row for that year is active)
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM academic_years WHERE year = ? AND status = 'active'");
        $stmt->bind_param("s", $year);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $year_status = ($row && (int)$row['cnt'] > 0) ? 'active' : 'inactive';

        echo json_encode(['success' => true, 'sessions' => $sessions, 'year_status' => $year_status]);
        exit();
    }

    // 2) Fetch exam titles for year+session
    if ($action === 'get_exams' && !empty($_GET['year']) && !empty($_GET['session'])) {
        $year = $_GET['year'];
        $session = $_GET['session'];
        $stmt = $conn->prepare("SELECT DISTINCT exam_title FROM academic_years WHERE year = ? AND session = ? AND exam_title IS NOT NULL ORDER BY exam_title ASC");
        $stmt->bind_param("ss", $year, $session);
        $stmt->execute();
        $res = $stmt->get_result();
        $exams = [];
        while ($r = $res->fetch_assoc()) {
            $exams[] = $r['exam_title'];
        }
        $stmt->close();
        echo json_encode(['success' => true, 'exams' => $exams]);
        exit();
    }

    // 3) Add session (AJAX POST expected)
    if ($action === 'add_session') {
        $input = json_decode(file_get_contents('php://input'), true);
        $year = trim($input['year'] ?? '');
        $sessionName = trim($input['session'] ?? '');

        if ($year === '' || $sessionName === '') {
            echo json_encode(['success' => false, 'message' => 'Year and session are required.']);
            exit();
        }

        // check if session exists for year
        $stmt = $conn->prepare("SELECT id FROM academic_years WHERE year = ? AND session = ?");
        $stmt->bind_param("ss", $year, $sessionName);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r->num_rows > 0) {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'Session already exists for this year.']);
            exit();
        }
        $stmt->close();

        // Insert a row where exam_title is NULL (we will add exam titles separately)
        $status = 'inactive';
        $stmt = $conn->prepare("INSERT INTO academic_years (year, session, exam_title, status) VALUES (?, ?, NULL, ?)");
        $stmt->bind_param("sss", $year, $sessionName, $status);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            echo json_encode(['success' => true, 'message' => 'Session added.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Could not add session (DB error).']);
        }
        exit();
    }

    // 4) Add exam title (AJAX POST expected)
    if ($action === 'add_exam') {
        $input = json_decode(file_get_contents('php://input'), true);
        $year = trim($input['year'] ?? '');
        $sessionName = trim($input['session'] ?? '');
        $examTitle = trim($input['exam'] ?? '');

        if ($year === '' || $sessionName === '' || $examTitle === '') {
            echo json_encode(['success' => false, 'message' => 'Year, session and exam title are required.']);
            exit();
        }

        // check if exact year+session+exam exists
        $stmt = $conn->prepare("SELECT id FROM academic_years WHERE year = ? AND session = ? AND exam_title = ?");
        $stmt->bind_param("sss", $year, $sessionName, $examTitle);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r->num_rows > 0) {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'This exam title already exists for the year/session.']);
            exit();
        }
        $stmt->close();

        // insert a new row for this year/session/exam_title
        $status = 'inactive';
        $stmt = $conn->prepare("INSERT INTO academic_years (year, session, exam_title, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $year, $sessionName, $examTitle, $status);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            echo json_encode(['success' => true, 'message' => 'Exam title added.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Could not add exam title (DB error).']);
        }
        exit();
    }

    // 5) Toggle year status (AJAX POST expected)
    if ($action === 'toggle_year') {
        $input = json_decode(file_get_contents('php://input'), true);
        $year = trim($input['year'] ?? '');
        if ($year === '') {
            echo json_encode(['success' => false, 'message' => 'Year required']);
            exit();
        }

        // Determine current status of year (if any active rows -> active)
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM academic_years WHERE year = ? AND status = 'active'");
        $stmt->bind_param("s", $year);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $currentlyActive = ($row && (int)$row['cnt'] > 0);

        $newStatus = $currentlyActive ? 'inactive' : 'active';

        // Update all rows for that year to new status
        $stmt = $conn->prepare("UPDATE academic_years SET status = ? WHERE year = ?");
        $stmt->bind_param("ss", $newStatus, $year);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            echo json_encode(['success' => true, 'status' => $newStatus, 'message' => 'Status updated']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Could not update status']);
        }
        exit();
    }

    // 6) Delete year (AJAX POST expected)
    if ($action === 'delete_year') {
        $input = json_decode(file_get_contents('php://input'), true);
        $year = trim($input['year'] ?? '');
        if ($year === '') {
            echo json_encode(['success' => false, 'message' => 'Year required']);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM academic_years WHERE year = ?");
        $stmt->bind_param("s", $year);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) echo json_encode(['success' => true, 'message' => 'Year deleted']);
        else echo json_encode(['success' => false, 'message' => 'Could not delete year']);

        exit();

    }

    // 7) SAVE selected year/session/exam_title as the ONLY active one
if ($action === "save_selection") {
    $data = json_decode(file_get_contents("php://input"), true);

    $year    = trim($data['year'] ?? '');
    $session = trim($data['session'] ?? '');
    $exam    = trim($data['exam'] ?? '');

    if (!$year || !$session || !$exam) {
        echo json_encode(['success' => false, 'message' => 'Year, session, and exam title are required']);
        exit;
    }

    try {
        $conn->begin_transaction(); // Start transaction for atomicity

        // Step 1: Deactivate ALL rows (set status = 'inactive')
        $conn->query("UPDATE academic_years SET status = 'inactive'");

        // Step 2: Check if exact combo (year + session + exam_title) already exists
        $stmt = $conn->prepare("SELECT id FROM academic_years WHERE year = ? AND session = ? AND exam_title = ?");
        $stmt->bind_param("sss", $year, $session, $exam);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            // Exists → Activate it (UPDATE status = 'active')
            $stmt = $conn->prepare("UPDATE academic_years SET status = 'active' WHERE year = ? AND session = ? AND exam_title = ?");
            $stmt->bind_param("sss", $year, $session, $exam);
            $stmt->execute();
        } else {
            // Doesn't exist → Create it with status = 'active'
            $stmt = $conn->prepare("INSERT INTO academic_years (year, session, exam_title, status) VALUES (?, ?, ?, 'active')");
            $stmt->bind_param("sss", $year, $session, $exam);
            $stmt->execute();
        }

        $conn->commit(); // Commit the transaction
        echo json_encode(['success' => true, 'message' => 'Active session saved successfully!']);
    } catch (Exception $e) {
        $conn->rollback(); // Rollback on error
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}
}
// --- Normal page POST handling (Add a new year) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_year'])) {
    $newYear = trim($_POST['new_year']);
    if ($newYear === '') {
        $errorMsg = "Academic year cannot be empty.";
    } else {
        // check distinct year already exists
        $stmt = $conn->prepare("SELECT 1 FROM academic_years WHERE year = ? LIMIT 1");
        $stmt->bind_param("s", $newYear);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r->num_rows > 0) {
            $errorMsg = "Academic year already exists.";
            $stmt->close();
        } else {
            $stmt->close();
            // insert a placeholder row with session NULL so year appears
            $status = 'inactive';
            $stmt = $conn->prepare("INSERT INTO academic_years (year, session, exam_title, status) VALUES (?, NULL, NULL, ?)");
            $stmt->bind_param("ss", $newYear, $status);
            if ($stmt->execute()) {
                $success = "Academic year added successfully.";
            } else {
                $errorMsg = "Database error while adding year.";
            }
            $stmt->close();
        }
    }
}

// 1. Get all years with status
$years = [];
$stmt = $conn->prepare("
    SELECT year, 
           SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count
    FROM academic_years
    GROUP BY year
    ORDER BY year ASC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $years[] = [
        'year'   => $row['year'],
        'status' => ($row['active_count'] > 0) ? 'active' : 'inactive'
    ];
}
$stmt->close();

// 2. Get the single active session (year + session + exam_title)
$activeSession = null;
$stmt = $conn->prepare("
    SELECT year, session, exam_title 
    FROM academic_years 
    WHERE status = 'active' 
    LIMIT 1
");
$stmt->execute();
$result = $stmt->get_result();
$activeSession = $result->num_rows > 0 ? $result->fetch_assoc() : null;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Session | Super Admin</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/dataTables.bootstrap5.min.css">
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-brand">
        <h3><i class="fas fa-graduation-cap me-2"></i>D-Portal</h3>
        <div class="admin-info">
            <small>Welcome back,</small>
            <h6><?php echo htmlspecialchars($super_admin['username']); ?></h6>
        </div>
    </div>
    <div class="sidebar-menu mt-4">
        <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
        <a href="manage_admins.php"><i class="fas fa-users-cog"></i>Manage Admins</a>
        <a href="manage_classes.php"><i class="fas fa-users-cog"></i>Manage Classes</a>
        <a href="manage_session.php" class="active"><i class="fas fa-users-cog"></i>Manage Session</a>
        <a href="manage_students.php"><i class="fas fa-users-cog"></i>Manage Students</a>
        <a href="manage_subject.php"><i class="fas fa-users-cog"></i>Manage Subject</a>
        <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
        <a href="../admin/logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i>Logout</a>
    </div>
</div>

<!-- Main Content -->
<div class="content container mt-5">

    <div class="header d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Manage Academic Sessions</h2>
        <button class="btn btn-primary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?php echo $errorMsg; ?></div>
    <?php endif; ?>

    <div class="page-wrap d-flex moveable-content">
        <!-- LEFT: Years column (vertical) -->
        <div class="left-col">
            <div class="card p-3 mb-3">
                <h5 class="mb-2">Add Academic Year</h5>
                <form method="POST" id="addYearForm">
                    <div class="input-group">
                        <input type="text" name="new_year" class="form-control form-control-sm" placeholder="e.g. 2025/2026" required>
                        <button class="btn btn-sm btn-primary" type="submit">Add</button>
                    </div>
                </form>
            </div>

            <div class="card p-3">
                <h6 class="mb-2">Academic Years</h6>
                <div class="year-list" id="yearList">
                    <?php foreach ($years as $y): ?>
                        <div class="year-item" data-year="<?php echo htmlspecialchars($y['year']); ?>">
                            <div>
                                <button class="btn btn-outline-primary btn-sm select-year-btn" data-year="<?php echo htmlspecialchars($y['year']); ?>">
                                    <?php echo htmlspecialchars($y['year']); ?>
                                </button>
                                <?php if ($y['status'] === 'active'): ?>
                                    <span class="badge bg-success ms-2">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary ms-2">Inactive</span>
                                <?php endif; ?>
                            </div>
                            <div class="actions">
                                <button class="btn btn-sm btn-warning toggle-year-btn" data-year="<?php echo htmlspecialchars($y['year']); ?>"><?php echo ($y['status'] === 'active') ? 'Deactivate' : 'Activate'; ?></button>
                                <button class="btn btn-sm btn-danger delete-year-btn" data-year="<?php echo htmlspecialchars($y['year']); ?>">Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($years)): ?>
                        <div class="small-muted">No academic years yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT: Workspace -->
        <div class="right-col flex-grow-1">
            <div class="section selection-field mb-3">
                <h5>Selection</h5>
                <div class="result-box" id="resultBox">
                    <div><strong>Year:</strong> <span id="selectedYear">—</span></div>
                    <div><strong>Session:</strong> <span id="selectedSession">—</span></div>
                    <div><strong>Exam Title:</strong> <span id="selectedExam">—</span></div>
                </div>
            </div>

            <div class="section selection-field mb-3">
                <h6>Sessions (Terms)</h6>
                <div id="sessionsArea">
                    <div class="small-muted">Select a year to load sessions.</div>
                </div>
                <div class="mt-2">
                    <button id="openAddSessionBtn" class="btn btn-sm btn-primary" disabled>Add Session</button>
                </div>
            </div>

            <div class="section selection-field mb-3">
                <h6>Exam Titles</h6>
                <div id="examsArea">
                    <div class="small-muted">Select a session to load exam titles.</div>
                </div>
                <div class="mt-2">
                    <button id="openAddExamBtn" class="btn btn-sm btn-primary" disabled>Add Exam Title</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Session Modal -->
    <div class="modal fade" id="addSessionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form id="addSessionForm" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Session (Term) for <span id="modalYearText"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Session Name</label>
                        <input type="text" class="form-control" name="session" placeholder="e.g. First Term" required>
                    </div>
                    <input type="hidden" name="year" id="modalYearInput">
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Add Session</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Exam Modal -->
    <div class="modal fade" id="addExamModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form id="addExamForm" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Exam Title for <span id="modalExamYearText"></span> — <span id="modalExamSessionText"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Exam Title</label>
                        <input type="text" class="form-control" name="exam" placeholder="e.g. Midterm Exam" required>
                    </div>
                    <input type="hidden" name="year" id="modalExamYearInput">
                    <input type="hidden" name="session" id="modalExamSessionInput">
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Add Exam Title</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>

    <div class="text-end mt-4">
        <button id="saveBtn" class="btn btn-success btn-lg" disabled>💾 Save Selection</button>
    </div>
</div>

<script src="../js/jquery-3.7.0.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/dataTables.min.js"></script>
    <script src="../js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/jquery.validate.min.js"></script>

    <script>
        $(document).ready(function() {
    $('#sidebarToggle').click(function() {
        $('.sidebar').toggleClass('active');
        $('.moveable-content').toggleClass('sidebar-active');
    });
});
<script>
const selectedYearEl = document.getElementById('selectedYear');
const selectedSessionEl = document.getElementById('selectedSession');
const selectedExamEl = document.getElementById('selectedExam');
const openAddSessionBtn = document.getElementById('openAddSessionBtn');
const openAddExamBtn = document.getElementById('openAddExamBtn');
const saveBtn = document.getElementById('saveBtn');

let currentYear = null;
let currentSession = null;
let currentExam = null;

// Update UI + Save button
function updateResultBox() {
    selectedYearEl.textContent = currentYear || '—';
    selectedSessionEl.textContent = currentSession || '—';
    selectedExamEl.textContent = currentExam || '—';
    saveBtn.disabled = !(currentYear && currentSession && currentExam);
}

// Refresh year list + restore full selection
async function refreshYearList() {
    const resp = await fetch('manage_session.php');
    const text = await resp.text();
    const doc = new DOMParser().parseFromString(text, 'text/html');
    const newList = doc.getElementById('yearList');
    if (newList) {
        document.getElementById('yearList').innerHTML = newList.innerHTML;
        attachEventListeners();

        // CRITICAL: If a year was selected before toggle, KEEP IT SELECT SmashED
        if (currentYear) {
            selectedYearEl.textContent = currentYear;
            openAddSessionBtn.disabled = false;
            await loadSessions(currentYear); // Re-load sessions

            // Restore session if it was selected
            if (currentSession) {
                const sessionRadio = document.querySelector(`input[name="sessionRadio"][value="${currentSession.replace(/"/g, '\\"')}"]`);
                if (sessionRadio) {
                    sessionRadio.checked = true;
                    openAddExamBtn.disabled = false;
                    await loadExamsFor(currentYear, currentSession);

                    if (currentExam) {
                        const examRadio = document.querySelector(`input[name="examRadio"][value="${currentExam.replace(/"/g, '\\"')}"]`);
                        if (examRadio) examRadio.checked = true;
                    }
                }
            }
            updateResultBox();
        }
    }
}

// Load sessions for a year
async function loadSessions(year) {
    try {
        const resp = await fetch(`?action=get_sessions&year=${encodeURIComponent(year)}`);
        const data = await resp.json();

        const sessionsArea = document.getElementById('sessionsArea');
        sessionsArea.innerHTML = '';
        const wrapper = document.createElement('div');
        wrapper.className = 'radio-list';

        const defaults = ['First Term', 'Second Term', 'Third Term'];
        new Set([...defaults, ...(data.sessions || [])]).forEach(s => {
            const label = document.createElement('label');
            label.innerHTML = `<input type="radio" name="sessionRadio" value="${s}" ${data.year_status !== 'active' ? 'disabled' : ''}> ${s}`;
            wrapper.appendChild(label);
        });

        if (!data.sessions || data.sessions.length === 0) {
            wrapper.appendChild(document.createTextNode('No sessions found. You can add one.'));
        }
        if (data.year_status !== 'active') {
            wrapper.insertAdjacentHTML('beforeend', '<div class="small-muted mt-2">Year is inactive — cannot select sessions.</div>');
        }

        sessionsArea.appendChild(wrapper);

        // Session change
        document.querySelectorAll('input[name="sessionRadio"]').forEach(r => {
            r.addEventListener('change', () => {
                currentSession = r.value;
                currentExam = null;
                updateResultBox();
                openAddExamBtn.disabled = false;
                loadExamsFor(year, currentSession);
            });
        });

    } catch (e) {
        document.getElementById('sessionsArea').innerHTML = '<div class="text-danger">Failed to load sessions</div>';
    }
}

// Load exam titles
async function loadExamsFor(year, session) {
    try {
        const resp = await fetch(`?action=get_exams&year=${encodeURIComponent(year)}&session=${encodeURIComponent(session)}`);
        const data = await resp.json();

        const examsArea = document.getElementById('examsArea');
        examsArea.innerHTML = '';
        const wrapper = document.createElement('div');
        wrapper.className = 'radio-list';

        const defaults = ['Exam', 'Test', 'Mock'];
        new Set([...defaults, ...(data.exams || [])]).forEach(e => {
            const label = document.createElement('label');
            label.innerHTML = `<input type="radio" name="examRadio" value="${e}"> ${e}`;
            wrapper.appendChild(label);
        });

        if (!data.exams || data.exams.length === 0) {
            wrapper.appendChild(document.createTextNode('No exam titles found. Add one.'));
        }

        examsArea.appendChild(wrapper);

        document.querySelectorAll('input[name="examRadio"]').forEach(r => {
            r.addEventListener('change', () => {
                currentExam = r.value;
                updateResultBox();
            });
        });

    } catch (e) {
        document.getElementById('examsArea').innerHTML = '<div class="text-danger">Failed to load exams</div>';
    }
}

// Re-attach all button listeners
function attachEventListeners() {
    // Select year
    document.querySelectorAll('.select-year-btn').forEach(btn => {
        btn.onclick = () => {
            currentYear = btn.dataset.year;
            updateResultBox();
            openAddSessionBtn.disabled = false;
            loadSessions(currentYear);
        };
    });

    // Toggle year (Activate/Deactivate)
    document.querySelectorAll('.toggle-year-btn').forEach(btn => {
        btn.onclick = async () => {
            const year = btn.dataset.year;
            if (!confirm(`Change status for ${year}?`)) return;

            const resp = await fetch('?action=toggle_year', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ year })
            }).then(r => r.json());

            alert(resp.message || 'Status updated!');

            // Keep the year selected after toggle
            currentYear = year;
            await refreshYearList(); // This now preserves selection!
        };
    });

    // Delete year
    document.querySelectorAll('.delete-year-btn').forEach(btn => {
        btn.onclick = async () => {
            if (!confirm('Delete this year permanently?')) return;
            await fetch('?action=delete_year', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ year: btn.dataset.year })
            });
            location.reload();
        };
    });
}

// Add Session Modal
const addSessionModal = new bootstrap.Modal('#addSessionModal');
openAddSessionBtn.onclick = () => {
    if (!currentYear) return alert('Select a year first');
    document.getElementById('modalYearText').textContent = currentYear;
    addSessionModal.show();
};
document.getElementById('addSessionForm').onsubmit = async (e) => {
    e.preventDefault();
    const session = e.target.session.value.trim();
    if (!session) return alert('Enter session name');

    const resp = await fetch('?action=add_session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ year: currentYear, session })
    }).then(r => r.json());

    alert(resp.message);
    addSessionModal.hide();
    await loadSessions(currentYear);
};

// Add Exam Modal
const addExamModal = new bootstrap.Modal('#addExamModal');
openAddExamBtn.onclick = () => {
    if (!currentSession) return alert('Select a session first');
    document.getElementById('modalExamYearText').textContent = currentYear;
    document.getElementById('modalExamSessionText').textContent = currentSession;
    addExamModal.show();
};
document.getElementById('addExamForm').onsubmit = async (e) => {
    e.preventDefault();
    const exam = e.target.exam.value.trim();
    if (!exam) return alert('Enter exam title');

    const resp = await fetch('?action=add_exam', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ year: currentYear, session: currentSession, exam })
    }).then(r => r.json());

    alert(resp.message);
    addExamModal.hide();
    await loadExamsFor(currentYear, currentSession);
};

// SAVE SELECTION
saveBtn.onclick = async () => {
    if (!currentYear || !currentSession || !currentExam) return;

    if (!confirm('Save this as the active academic session?')) return;

    const resp = await fetch('?action=save_selection', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            year: currentYear,
            session: currentSession,
            exam: currentExam
        })
    }).then(r => r.json());

    if (resp.success) {
        alert('Active session saved successfully!');
        location.reload();
    } else {
        alert('Save failed: ' + (resp.message || 'Unknown error'));
    }
};

// INIT — Auto-load active session on page load
openAddSessionBtn.disabled = true;
openAddExamBtn.disabled = true;
saveBtn.disabled = true;
updateResultBox();
attachEventListeners();

// AUTO-SELECT CURRENT ACTIVE SESSION (if exists)
if (ACTIVE_SESSION && ACTIVE_SESSION.year) {
    currentYear = ACTIVE_SESSION.year;
    currentSession = ACTIVE_SESSION.session;
    currentExam = ACTIVE_SESSION.exam_title;

    // Update UI
    selectedYearEl.textContent = currentYear;
    selectedSessionEl.textContent = currentSession || '—';
    selectedExamEl.textContent = currentExam || '—';

    // Enable buttons
    openAddSessionBtn.disabled = false;
    openAddExamBtn.disabled = false;
    saveBtn.disabled = false; // All 3 are selected

    // Load sessions → will auto-check the correct radio
    loadSessions(currentYear).then(() => {
        // After sessions load, check the correct session radio
        const sessionRadio = document.querySelector(`input[name="sessionRadio"][value="${escapeQuotes(currentSession)}"]`);
        if (sessionRadio) {
            sessionRadio.checked = true;
            // Now load exams and auto-check
            loadExamsFor(currentYear, currentSession).then(() => {
                const examRadio = document.querySelector(`input[name="examRadio"][value="${escapeQuotes(currentExam)}"]`);
                if (examRadio) examRadio.checked = true;
            });
        }
    });
}

// Helper: escape quotes for JS
function escapeQuotes(str) {
    if (!str) return '';
    return str.replace(/"/g, '\\"').replace(/'/g, "\\'");
}
</script>
<script>
    const CURRENT_ACTIVE = <?php echo json_encode($active); ?>;
    const ACTIVE_SESSION = <?php echo json_encode($activeSession); ?>;
</script>
</body>
</html>