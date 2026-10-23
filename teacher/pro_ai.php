<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../includes/pro.php';
// Pro guard intentionally disabled during development; restore before production.
// require_pro('ai_assessment');
require_once __DIR__ . '/../ai/LlamaCppAssessmentAssistant.php';
$out = null;
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ai = new LlamaCppAssessmentAssistant();
        $out = $_POST['action'] === 'review' ? $ai->reviewQuestion($_POST['question']) : $ai->generateQuestions($_POST['topic'], max(1, min(20, (int)$_POST['count'])), $_POST['level']);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
} ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offline AI Assessment | Examcenter</title>
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
<style>
:root{--ai-ink:#172b4d;--ai-muted:#64748b;--ai-line:#e2e8f0;--ai-blue:#2459a6}
body{background:#f3f6fb;color:var(--ai-ink)}
.main-content{padding:clamp(20px,3vw,36px);max-width:1440px}
.ai-page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:24px}
.ai-eyebrow{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;font-weight:800;color:var(--ai-blue);margin-bottom:8px}
.ai-page-head h1{font-size:clamp(1.55rem,3vw,2.1rem);font-weight:800;letter-spacing:-.04em;margin:0 0 8px}
.ai-page-head p{color:var(--ai-muted);margin:0;max-width:720px}
.ai-local-pill{display:inline-flex;align-items:center;gap:8px;border:1px solid #b9e5d0;background:#effaf4;color:#176644;border-radius:999px;padding:8px 12px;font-size:.78rem;font-weight:750;white-space:nowrap}
.ai-local-pill:before{content:"";width:8px;height:8px;border-radius:50%;background:#22a06b}
.ai-work-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;align-items:start}
.ai-work-card{background:#fff;border:1px solid var(--ai-line);border-radius:18px;box-shadow:0 10px 30px rgba(20,43,77,.055);overflow:hidden}
.ai-work-card-head{padding:20px 22px;border-bottom:1px solid var(--ai-line);display:flex;align-items:flex-start;gap:13px}
.ai-work-icon{width:42px;height:42px;display:grid;place-items:center;flex:0 0 42px;border-radius:13px;background:#eaf2fc;color:var(--ai-blue);font-size:1.2rem;font-weight:800}
.ai-work-card-head h2{font-size:1.05rem;font-weight:800;margin:2px 0 5px}
.ai-work-card-head p{font-size:.86rem;color:var(--ai-muted);margin:0}
.ai-work-card-body{padding:22px}
.ai-work-card .form-label{font-size:.83rem;font-weight:750;color:#34445b;margin-bottom:7px}
.ai-work-card .form-control,.ai-work-card .form-select{border:1px solid #d5deea;border-radius:10px;padding:11px 12px}
.ai-work-card .form-control:focus{border-color:#6b98d8;box-shadow:0 0 0 .2rem rgba(36,89,166,.12)}
.ai-work-card .btn{border-radius:10px;padding:11px 15px;font-weight:750}
.ai-help-note{border:1px solid #d7e5f8;border-radius:12px;background:#f1f6fd;color:#385477;padding:13px 15px;font-size:.83rem}
.ai-output{margin-top:22px;background:#111c2e;color:#e5edf8;border-radius:15px;overflow:hidden;border:1px solid #24334a}
.ai-output-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 17px;background:#1a2940;color:#dbeafe;font-weight:750;font-size:.84rem}
.ai-output pre{padding:18px;margin:0;max-height:520px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font-size:.82rem;line-height:1.65;color:#e5edf8}
.ai-alert{border-radius:12px}
@media(max-width:900px){.ai-work-grid{grid-template-columns:1fr}.ai-page-head{flex-direction:column}.ai-local-pill{white-space:normal}}
@media(max-width:575px){.main-content{padding:16px}.ai-work-card-head,.ai-work-card-body{padding:17px}.ai-page-head{margin-bottom:18px}}
</style>
</head>

<body><?php $teacher = $_SESSION['teacher'] ?? []; $admin = $_SESSION['admin'] ?? []; $user = $_SESSION['user'] ?? []; require __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
  <header class="ai-page-head"><div><div class="ai-eyebrow">Teacher workspace · Pro tools</div><h1>Offline AI Assessment</h1><p>Draft new questions or review existing questions with the local Qwen model. Generated content remains a draft until you check and approve it.</p></div><span class="ai-local-pill">Local processing · llama.cpp</span></header>
  <div class="ai-help-note mb-4"><strong>Teacher control:</strong> AI suggestions are not added to an exam automatically. Review accuracy, syllabus fit, clarity and marks before using generated content.</div>
  <?php if ($err): ?><div class="alert alert-danger ai-alert" role="alert"><strong>AI request could not be completed.</strong><div class="mt-1"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div></div><?php endif; ?>
  <div class="ai-work-grid">
    <section class="ai-work-card"><div class="ai-work-card-head"><div class="ai-work-icon" aria-hidden="true">＋</div><div><h2>Generate draft questions</h2><p>Create a starting set for a topic and level.</p></div></div>
      <div class="ai-work-card-body"><form method="post"><input type="hidden" name="action" value="generate">
        <div class="mb-3"><label class="form-label" for="ai-topic">Topic or learning objective</label><input id="ai-topic" name="topic" class="form-control" placeholder="e.g. Photosynthesis" required></div>
        <div class="row g-3 mb-3"><div class="col-sm-5"><label class="form-label" for="ai-count">Number of questions</label><input id="ai-count" name="count" type="number" value="5" min="1" max="20" class="form-control" required></div><div class="col-sm-7"><label class="form-label" for="ai-level">Difficulty / level</label><select id="ai-level" name="level" class="form-select"><option value="easy">Easy</option><option value="medium" selected>Medium</option><option value="hard">Hard</option></select></div></div>
        <button class="btn btn-primary w-100" type="submit">Generate draft questions</button>
      </form></div></section>
    <section class="ai-work-card"><div class="ai-work-card-head"><div class="ai-work-icon" aria-hidden="true">✓</div><div><h2>Review a question</h2><p>Ask the model to assess clarity and quality.</p></div></div>
      <div class="ai-work-card-body"><form method="post"><input type="hidden" name="action" value="review">
        <div class="mb-3"><label class="form-label" for="ai-question">Question text</label><textarea id="ai-question" name="question" class="form-control" rows="6" placeholder="Paste the question you want to review…" required></textarea></div>
        <button class="btn btn-outline-primary w-100" type="submit">Review question</button>
      </form></div></section>
  </div>
  <?php if ($out): ?><section class="ai-output" aria-labelledby="ai-output-heading"><div class="ai-output-head"><span id="ai-output-heading">AI response · Review before use</span><span class="badge text-bg-light">Draft only</span></div><pre><?= htmlspecialchars(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></pre></section><?php endif; ?>
</main>
</body>

</html>