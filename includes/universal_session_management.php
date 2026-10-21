<?php
declare(strict_types=1);

/** Universal period and assessment-group UI for non-school institutions. */
function examcenterRenderUniversalSessionManagement(mysqli $conn, array $profile, array $admin): void
{
    $institutionId = (int)($profile['id'] ?? 0);
    $category = (string)($profile['category'] ?? 'other');
    $configs = [
        'higher_education' => [
            'institution' => 'Higher education', 'root_label' => 'Academic Year',
            'root_type' => 'academic_year', 'root_types' => ['academic_year'],
            'parent_types' => ['academic_year'],
            'child_types' => ['semester', 'trimester', 'quarter', 'teaching_period', 'custom'],
            'child_label' => 'Academic period', 'root_hint' => 'Create an academic year, then add semesters, trimesters, quarters, or other configured teaching periods as needed.',
            'standalone' => false,
        ],
        'vocational' => [
            'institution' => 'Vocational / professional training', 'root_label' => 'Training cohort or programme period',
            'root_type' => 'training_cohort', 'root_types' => ['training_cohort', 'programme_period'],
            'parent_types' => ['training_cohort', 'programme_period'],
            'child_types' => ['module_period', 'assessment_period', 'custom'],
            'child_label' => 'Training period', 'root_hint' => 'Create cohorts or programme periods. Add module or assessment periods beneath them when useful.',
            'standalone' => false,
        ],
        'business' => [
            'institution' => 'Corporate / business', 'root_label' => 'Operational period or campaign',
            'root_type' => 'recruitment_campaign', 'root_types' => ['recruitment_campaign', 'operational_period'],
            'parent_types' => ['recruitment_campaign', 'operational_period'],
            'child_types' => ['assessment_period', 'operational_period', 'custom'],
            'child_label' => 'Campaign or assessment period', 'root_hint' => 'Create recruitment campaigns or operational periods when needed. Assessments can also stand alone without a period.',
            'standalone' => true,
        ],
        'other' => [
            'institution' => 'Assessment centre / other', 'root_label' => 'Custom period',
            'root_type' => 'custom', 'root_types' => ['custom'],
            'parent_types' => ['custom'],
            'child_types' => ['custom'], 'child_label' => 'Custom period',
            'root_hint' => 'Use custom periods only when they help organize assessments. A standalone assessment group does not require a period.',
            'standalone' => true,
        ],
    ];
    $config = $configs[$category] ?? $configs['other'];
    $tablesReady = true;
    foreach (['academic_periods', 'assessment_groups', 'assessment_group_assignments'] as $table) {
        if (!examcenterUniversalTableExists($conn, $table)) $tablesReady = false;
    }
    if (!$tablesReady || $institutionId <= 0) {
        http_response_code(503);
        echo '<!doctype html><meta charset="utf-8"><title>Session management unavailable</title><p>Universal period tables are not available. Ask the system administrator to complete the documented database setup.</p>';
        return;
    }

    if (empty($_SESSION['universal_session_csrf'])) {
        $_SESSION['universal_session_csrf'] = bin2hex(random_bytes(32));
    }
    $csrf = (string)$_SESSION['universal_session_csrf'];
    $flash = '';
    $error = '';
    $action = (string)($_POST['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
                throw new RuntimeException('Your session expired. Refresh the page and try again.');
            }
            if ($action === 'create_period') {
                $name = trim((string)($_POST['name'] ?? ''));
                $type = trim((string)($_POST['period_type'] ?? ''));
                $parentId = filter_var($_POST['parent_id'] ?? '', FILTER_VALIDATE_INT);
                $parentId = $parentId === false || (int)$parentId <= 0 ? null : (int)$parentId;
                $start = trim((string)($_POST['starts_at'] ?? ''));
                $end = trim((string)($_POST['ends_at'] ?? ''));
                if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('Enter a period name up to 150 characters.');
                $allowedTypes = $parentId === null ? $config['root_types'] : $config['child_types'];
                if (!in_array($type, $allowedTypes, true)) throw new RuntimeException('That period type is not configured for this institution.');
                $toPeriodDate = static function (string $value, bool $endOfDay = false): ?string {
                    if ($value === '') return null;
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    $parseErrors = DateTimeImmutable::getLastErrors();
                    if ($date === false || $date->format('Y-m-d') !== $value
                        || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))) {
                        throw new RuntimeException('Enter valid start and end dates.');
                    }
                    return $date->format($endOfDay ? 'Y-m-d 23:59:59' : 'Y-m-d 00:00:00');
                };
                $startValue = $toPeriodDate($start);
                $endValue = $toPeriodDate($end, true);
                if ($startValue !== null && $endValue !== null && substr($endValue, 0, 10) < substr($startValue, 0, 10)) {
                    throw new RuntimeException('The end date must be on or after the start date.');
                }
                if ($parentId !== null) {
                    $check = $conn->prepare('SELECT id, period_type FROM academic_periods WHERE id = ? AND institution_id = ? LIMIT 1');
                    $check->bind_param('ii', $parentId, $institutionId);
                    $check->execute();
                    $validParent = $check->get_result()->fetch_assoc();
                    $check->close();
                    if (!$validParent) throw new RuntimeException('Choose a parent period from this institution.');
                    if (!in_array((string)$validParent['period_type'], $config['parent_types'], true)) {
                        throw new RuntimeException('Choose a compatible top-level parent period.');
                    }
                }
                $code = 'period-' . bin2hex(random_bytes(8));
                $status = 'planned';
                $stmt = $conn->prepare('INSERT INTO academic_periods (institution_id, parent_id, code, name, period_type, starts_at, ends_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iissssss', $institutionId, $parentId, $code, $name, $type, $startValue, $endValue, $status);
                $stmt->execute();
                $stmt->close();
                $flash = 'Period created.';
            } elseif ($action === 'create_group') {
                $name = trim((string)($_POST['name'] ?? ''));
                $groupType = trim((string)($_POST['group_type'] ?? 'assessment'));
                $periodId = filter_var($_POST['period_id'] ?? '', FILTER_VALIDATE_INT);
                $periodId = $periodId === false || (int)$periodId <= 0 ? null : (int)$periodId;
                $validGroupTypes = ['examination', 'mock', 'midterm', 'final', 'certification', 'recruitment', 'assessment', 'custom'];
                if ($name === '' || mb_strlen($name) > 255) throw new RuntimeException('Enter an assessment group name up to 255 characters.');
                if (!in_array($groupType, $validGroupTypes, true)) throw new RuntimeException('Choose a valid assessment group type.');
                if ($periodId !== null) {
                    $check = $conn->prepare('SELECT id FROM academic_periods WHERE id = ? AND institution_id = ? LIMIT 1');
                    $check->bind_param('ii', $periodId, $institutionId);
                    $check->execute();
                    $validPeriod = $check->get_result()->fetch_assoc();
                    $check->close();
                    if (!$validPeriod) throw new RuntimeException('Choose a period from this institution.');
                } elseif (!$config['standalone']) {
                    throw new RuntimeException('Select a period before creating an assessment group.');
                }
                $conn->begin_transaction();
                $code = 'group-' . bin2hex(random_bytes(8));
                $stmt = $conn->prepare('INSERT INTO assessment_groups (institution_id, code, name, group_type) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('isss', $institutionId, $code, $name, $groupType);
                $stmt->execute();
                $groupId = (int)$stmt->insert_id;
                $stmt->close();
                if ($periodId !== null) {
                    $nullUnit = null;
                    $nullProgramme = null;
                    $nullCourse = null;
                    $stmt = $conn->prepare('INSERT INTO assessment_group_assignments (assessment_group_id, unit_id, programme_id, academic_period_id, course_id) VALUES (?, ?, ?, ?, ?)');
                    $stmt->bind_param('iiiii', $groupId, $nullUnit, $nullProgramme, $periodId, $nullCourse);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
                $flash = 'Assessment group created.';
            } elseif ($action === 'update_period_status') {
                $periodId = filter_var($_POST['period_id'] ?? null, FILTER_VALIDATE_INT);
                $status = (string)($_POST['status'] ?? '');
                if (!$periodId || !in_array($status, ['planned', 'active', 'closed', 'cancelled'], true)) throw new RuntimeException('Choose a valid period and status.');
                $stmt = $conn->prepare('UPDATE academic_periods SET status = ? WHERE id = ? AND institution_id = ?');
                $stmt->bind_param('sii', $status, $periodId, $institutionId);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    $stmt->close();
                    $check = $conn->prepare('SELECT id FROM academic_periods WHERE id = ? AND institution_id = ?');
                    $check->bind_param('ii', $periodId, $institutionId);
                    $check->execute();
                    $exists = (bool)$check->get_result()->fetch_assoc();
                    $check->close();
                    if (!$exists) throw new RuntimeException('Period not found for this institution.');
                }
                $stmt->close();
                $flash = 'Period status updated.';
            } else {
                throw new RuntimeException('Choose a valid session-management action.');
            }
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('Universal session management: ' . $e->getMessage());
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save this change. Check the details and try again.';
        }
    }

    $stmt = $conn->prepare('SELECT id, parent_id, code, name, period_type, starts_at, ends_at, status FROM academic_periods WHERE institution_id = ? ORDER BY COALESCE(parent_id, id), parent_id IS NOT NULL, starts_at, name');
    $stmt->bind_param('i', $institutionId);
    $stmt->execute();
    $periods = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $stmt = $conn->prepare('SELECT ag.id, ag.name, ag.group_type, ap.name AS period_name FROM assessment_groups ag LEFT JOIN assessment_group_assignments aga ON aga.assessment_group_id = ag.id LEFT JOIN academic_periods ap ON ap.id = aga.academic_period_id AND ap.institution_id = ag.institution_id WHERE ag.institution_id = ? GROUP BY ag.id, ag.name, ag.group_type, ap.name ORDER BY ag.created_at DESC');
    $stmt->bind_param('i', $institutionId);
    $stmt->execute();
    $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $periodById = [];
    foreach ($periods as $period) $periodById[(int)$period['id']] = $period;
    $h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $rootTypeOptions = ['academic_year' => 'Academic Year', 'training_cohort' => 'Training Cohort', 'programme_period' => 'Programme Period', 'recruitment_campaign' => 'Recruitment Campaign', 'operational_period' => 'Operational Period', 'custom' => 'Custom Period'];
    $childTypeOptions = ['semester' => 'Semester', 'trimester' => 'Trimester', 'quarter' => 'Quarter', 'teaching_period' => 'Teaching Period', 'module_period' => 'Module / Assessment Period', 'assessment_period' => 'Assessment Period', 'operational_period' => 'Operational Period', 'custom' => 'Custom Period'];
    $groupTypeOptions = ['examination' => 'Examination', 'mock' => 'Mock examination', 'midterm' => 'Midterm assessment', 'final' => 'Final examination', 'certification' => 'Certification', 'recruitment' => 'Recruitment round', 'assessment' => 'Assessment', 'custom' => 'Custom'];
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Periods and Assessments | Examcenter</title><link href="../css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../css/all.css"><link rel="stylesheet" href="../css/sidebar.css"><style>
    body{background:#f5f7fb;color:#202938}.main-content{padding:28px}.session-wrap{max-width:1240px;margin:auto}.session-card{border:0;border-radius:16px;box-shadow:0 8px 28px #1d35570d}.session-card .card-header{background:#fff;border-bottom:1px solid #e8edf4;padding:20px 22px}.muted{color:#667085}.period-indent{padding-left:32px}.status-pill{font-size:.75rem}.section-title{font-weight:700}.form-text{color:#667085}.empty-state{padding:28px;text-align:center;color:#667085;background:#f8fafc;border-radius:12px}.session-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}@media(max-width:900px){.session-grid{grid-template-columns:1fr}.main-content{padding:16px}}
    </style></head><body>
    <?php require __DIR__ . '/../admin/sidebar.php'; ?>
    <main class="main-content"><div class="session-wrap">
      <header class="d-flex justify-content-between align-items-start gap-3 mb-4"><div><div class="text-primary fw-semibold small"><?= $h($config['institution']) ?></div><h1 class="h3 mb-1">Periods and assessment groups</h1><p class="muted mb-0">Periods organize time. Assessment groups organize related tests. Tests, sittings, attempts, and results remain managed in their existing modules.</p></div><button class="btn btn-outline-primary d-lg-none" id="sidebarToggle" aria-label="Toggle navigation"><i class="fas fa-bars"></i></button></header>
      <?php if ($flash !== ''): ?><div class="alert alert-success" role="status"><?= $h($flash) ?></div><?php endif; ?><?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= $h($error) ?></div><?php endif; ?>
      <div class="session-grid mb-4">
        <section class="card session-card"><div class="card-header"><h2 class="h5 mb-1">Create a period</h2><div class="form-text"><?= $h($config['root_hint']) ?> Dates may overlap when your calendar requires it.</div></div><div class="card-body"><form method="post"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="create_period"><div class="mb-3"><label class="form-label" for="periodName">Period name</label><input class="form-control" id="periodName" name="name" maxlength="150" required placeholder="e.g. 2026/2027 Academic Year"></div><div class="row g-3"><div class="col-md-6"><label class="form-label" for="periodType">Period type</label><select class="form-select" id="periodType" name="period_type" required><optgroup label="Top-level periods"><?php foreach ($config['root_types'] as $type): ?><option value="<?= $h($type) ?>"><?= $h($rootTypeOptions[$type] ?? ucfirst(str_replace('_', ' ', $type))) ?></option><?php endforeach; ?></optgroup><optgroup label="Child periods"><?php foreach ($config['child_types'] as $type): ?><option value="<?= $h($type) ?>"><?= $h($childTypeOptions[$type] ?? ucfirst(str_replace('_', ' ', $type))) ?></option><?php endforeach; ?></optgroup></select><div class="form-text">Choose a top-level type when there is no parent. Child types require a parent period.</div></div><div class="col-md-6"><label class="form-label" for="periodParent">Parent period <span class="text-muted">(optional)</span></label><select class="form-select" id="periodParent" name="parent_id"><option value="">No parent / top-level period</option><?php foreach ($periods as $period): ?><?php if (in_array((string)$period['period_type'], $config['parent_types'], true)): ?><option value="<?= (int)$period['id'] ?>"><?= $h($period['name']) ?> · <?= $h(str_replace('_', ' ', $period['period_type'])) ?></option><?php endif; ?><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label" for="periodStart">Start date</label><input class="form-control" type="date" id="periodStart" name="starts_at"></div><div class="col-md-6"><label class="form-label" for="periodEnd">End date</label><input class="form-control" type="date" id="periodEnd" name="ends_at"></div></div><button class="btn btn-primary mt-3" type="submit"><i class="fas fa-plus me-1"></i>Create period</button></form></div></section>
        <section class="card session-card"><div class="card-header"><h2 class="h5 mb-1">Create an assessment group</h2><div class="form-text">A group may contain multiple tests. Choose a period when applicable.</div></div><div class="card-body"><form method="post"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="create_group"><div class="mb-3"><label class="form-label" for="groupName">Group name</label><input class="form-control" id="groupName" name="name" maxlength="255" required placeholder="e.g. Graduate Recruitment Round 1"></div><div class="row g-3"><div class="col-md-6"><label class="form-label" for="groupType">Group type</label><select class="form-select" id="groupType" name="group_type"><?php foreach ($groupTypeOptions as $value => $label): ?><option value="<?= $h($value) ?>"><?= $h($label) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label" for="groupPeriod">Period<?= $config['standalone'] ? ' (optional)' : '' ?></label><select class="form-select" id="groupPeriod" name="period_id"><option value="">No period</option><?php foreach ($periods as $period): ?><option value="<?= (int)$period['id'] ?>"><?= $h($period['name']) ?> · <?= $h(str_replace('_', ' ', $period['period_type'])) ?></option><?php endforeach; ?></select><?php if (!$config['standalone']): ?><div class="form-text">Select a period to organize this assessment group.</div><?php endif; ?></div></div><button class="btn btn-primary mt-3" type="submit"><i class="fas fa-layer-group me-1"></i>Create group</button></form></div></section>
      </div>
      <section class="card session-card mb-4"><div class="card-header"><h2 class="h5 mb-0">Configured periods</h2></div><div class="card-body p-0"><?php if (!$periods): ?><div class="empty-state m-3">No periods have been created. Create one only if your institution uses a calendar or operational period.</div><?php else: ?><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Period</th><th>Type</th><th>Dates</th><th>Status</th><th>Update status</th></tr></thead><tbody><?php foreach ($periods as $period): ?><tr><td class="<?= $period['parent_id'] ? 'period-indent' : '' ?>"><?= $h($period['name']) ?></td><td><?= $h(ucwords(str_replace('_', ' ', $period['period_type']))) ?></td><td><?= $h($period['starts_at'] ? date('M j, Y', strtotime($period['starts_at'])) : '—') ?> – <?= $h($period['ends_at'] ? date('M j, Y', strtotime($period['ends_at'])) : '—') ?></td><td><span class="badge text-bg-light status-pill"><?= $h(ucfirst($period['status'])) ?></span></td><td><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="update_period_status"><input type="hidden" name="period_id" value="<?= (int)$period['id'] ?>"><select class="form-select form-select-sm" name="status" aria-label="Status for <?= $h($period['name']) ?>"><?php foreach (['planned','active','closed','cancelled'] as $status): ?><option value="<?= $status ?>" <?= $period['status'] === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-primary" type="submit">Save</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></section>
      <section class="card session-card"><div class="card-header"><h2 class="h5 mb-0">Assessment groups</h2></div><div class="card-body p-0"><?php if (!$groups): ?><div class="empty-state m-3">No assessment groups yet. Create one to organize related tests.</div><?php else: ?><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Group</th><th>Type</th><th>Period</th></tr></thead><tbody><?php foreach ($groups as $group): ?><tr><td><?= $h($group['name']) ?></td><td><?= $h(ucfirst(str_replace('_', ' ', $group['group_type'] ?? 'assessment'))) ?></td><td><?= $h($group['period_name'] ?? 'Standalone') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></section>
      <p class="small muted mt-3">Existing tests, exam schedules, candidate attempts, marking, and results keep their current lifecycle. Periods and groups can be selected in the assessment workflows that already support those links.</p>
    </div></main><script src="../js/bootstrap.bundle.min.js"></script><script>document.getElementById('sidebarToggle')?.addEventListener('click',()=>document.body.classList.toggle('sidebar-open'));const parent=document.getElementById('periodParent'),type=document.getElementById('periodType');const updatePeriodTypes=()=>{const groups=[...type.options];const child=parent.value!=='';for(const opt of groups){if(!opt.value)continue;const inRoot=opt.parentElement.label==='Top-level periods';opt.disabled=child?inRoot:!inRoot;if(opt.disabled&&opt.selected)opt.selected=false;}if(!type.value){const first=groups.find(o=>!o.disabled&&o.value);if(first)first.selected=true;}};parent?.addEventListener('change',updatePeriodTypes);updatePeriodTypes();</script></body></html>
    <?php
}
