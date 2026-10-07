# Examcenter System Audit — 2026-10-07

## Scope

This audit covers the current `main` codebase architecture, Core/Pro boundary, Universal schema integration, examination lifecycle, theory assessment, licensing, backups, duplicate files, offline operation and Pro modules.

The audit found **389 tracked files** before cleanup.

## Critical findings fixed in this audit

### 1. Universal schema/runtime mismatch
The Universal migration and PHP layer disagreed about several column names.

Examples found:

- `institutions.is_active` vs runtime queries using `institutions.status`
- `academic_periods.code` vs runtime queries using `period_code`
- `academic_periods.parent_id` vs `parent_period_id`
- `academic_periods.name` vs `period_name`
- `courses.code/name` vs legacy-facing `course_code/course_name`
- Universal `tests.*` context columns were referenced by application code but were not created by migration 0001.

**Fix:** corrected the shared Universal helpers and added migration `20261007_0004_system_audit_hardening.sql` to repair missing runtime schema contracts.

### 2. Core exam answer endpoint trust issue
`student/save_answer.php` trusted posted `user_id`, did not verify that the question belonged to the active test, and had no server-side expiry check.

**Fix:** the endpoint now derives identity/test context from the authenticated session, validates CSRF, validates question ownership and checks the new server-side attempt-session expiry record.

### 3. Core submission grading bug
Objective multiple-choice/true-false/fill-blank grading contained PHP operator-precedence logic that could turn a fetched row into a boolean before accessing its fields.

**Fix:** corrected the fetch/condition logic and made result finalisation transactional.

### 4. Offline-first violation in the exam client
The exam page loaded MathJS and Bootstrap Icons from public CDNs.

**Fix:** MathJS now loads from the bundled local asset. The external Bootstrap Icons dependency was removed.

### 5. Unstable question ordering
The exam page rebuilt its question list using `ORDER BY RAND()`, which can change question order when the page is refreshed.

**Fix:** Core delivery now uses deterministic question ordering. Randomised delivery belongs in the advanced/Pro assessment builder.

### 6. Timer restoration bug
Existing `time_left` was previously passed through `max(existing, full_duration)`, which could restore a nearly completed attempt to the full duration.

**Fix:** remaining time is clamped to the valid range instead of being increased.

### 7. Duplicate student-side AI theory grader
`student/grade_theory.php` duplicated the teacher-side AI grading operation and allowed the candidate to trigger grading.

**Fix:** removed the duplicate endpoint. AI grading is now initiated through the teacher grading workflow.

### 8. Broken Pro feature code
Teacher theory grading requested the unregistered entitlement code `ai_theory`.

**Fix:** it now uses the registered `ai_assessment` entitlement.

### 9. Duplicate logout implementations
Admin and teacher logout implementations were byte-for-byte duplicates.

**Fix:** introduced a single root logout handler; role-specific paths now delegate to it.

### 10. Repository clutter
Removed redundant copied CSS assets and committed macOS `.DS_Store` metadata.

## New audit infrastructure

### `scripts/audit_system.php`

Runs offline and checks:

- duplicate file content
- required architecture files
- migration registration
- Core policy coverage
- external CDN dependencies in Core exam pages
- CSRF protection on Core mutation endpoints
- known Universal schema mismatches
- known hard-coded JSS/SS assumptions
- PHP syntax across the repository

Run:

```bash
php scripts/audit_system.php
```

Then run:

```bash
php scripts/verify_pro_architecture.php
```

and:

```bash
php database/migrate.php
```

## New database capabilities

Migration `20261007_0004_system_audit_hardening.sql` adds/repairs:

- Universal context columns on legacy `tests`
- legacy subject linkage for Universal `courses`
- legacy academic-year linkage for Universal periods
- Universal membership/assignment references on attempts and results
- `assessment_assignments`
- server-side `exam_attempt_sessions`
- `exam_attempt_events`
- Core feature catalog

## Remaining architectural gaps

These were identified but are intentionally not disguised as "implemented" by this audit:

1. Remaining legacy administrative screens still expose compatibility-only JSS/SS/PRIMARY workflows; these are now isolated from the authoritative Universal setup and candidate registration flows.
2. Universal runtime pages now use canonical database columns (`name`, `code`, `is_active`, `parent_id`, etc.). UI result aliases such as `course_name` or `unit_name` are presentation aliases only.
3. Migration `20261007_0005_legacy_universal_reconciliation.php` upgrades databases created from the earlier Universal draft naming convention.
3. Advanced Pro capabilities still requiring full implementation include:
   - cloud backup/synchronisation
   - student/parent portals
   - communication/SMS/email workflows
   - public API and webhooks
   - advanced result/reporting workflows
   - enterprise customisation
   - multi-campus central administration beyond the current base module
4. Existing Pro CRUD pages need a second security pass for CSRF, scoped institution access and role-specific authorization.
5. The server-side `exam_attempt_sessions` model is now present, but the complete browser recovery flow should be migrated from the legacy per-question `exam_attempts` timing model to it.
6. The current repository contains legacy and Universal schemas simultaneously. They must remain backward compatible until a controlled migration removes the legacy dependency.

## Product boundary confirmed

Core remains free for the complete normal examination lifecycle:

**setup → author questions → create exam → schedule → candidate takes exam → answers saved → submission → marking → result**

Pro remains additive:

**analytics → advanced question governance → blueprinting → security controls → invigilation → certificates → multi-campus → AI authoring → moderation → corporate assessment → coding assessment → advanced integrations**

A Pro entitlement failure must never prevent Core CBT operation.
