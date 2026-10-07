# Examcenter Pro Assessment Suite

This module extends Examcenter Universal without changing the Core CBT contract.

## Implemented Pro feature codes

- `question_bank_pro` — governed reusable item bank, metadata, status and versioning.
- `assessment_blueprints` — reusable assessment blueprints and paper assembly records.
- `assessment_analytics` — item/sample/facility analytics foundation.
- `secure_exam_mode` — configurable browser integrity controls and offline security events.
- `exam_sessions` — exam sessions, rooms, candidates, invigilators and incidents.
- `certificates` — certificate issuance and verification tokens.
- `multi_campus` — central campus/branch records and memberships.
- `ai_assessment` — offline Ollama assessment drafting/review using `qwen3:1.7b`; output is advisory and requires approval.
- `examiner_moderation` — examiner assignments, moderation reviews and appeals.
- `corporate_assessment` — recruitment, competency and employee assessment records.
- `coding_assessment` — coding questions, test cases, submissions and controlled local runner adapter.

## Core-safe rule

All Pro pages call the existing feature gate. A missing/expired/unavailable Pro entitlement must not prevent Core CBT exams from running.

## Offline rule

AI uses local Ollama only. Coding execution uses explicitly configured local runners. No external AI/API is required for the exam workflow.

## Secure exam rule

Browser controls are evidence-producing controls, not proof of misconduct. The platform records events for human review. OS-level application lockdown requires a managed kiosk/device environment and is not falsely claimed by PHP/JavaScript alone.

## Verification

Run:

`php scripts/verify_pro_architecture.php`

Then execute the MariaDB migration on a development database:

`php database/migrate.php`

Live migration, browser verification, Ollama execution and coding-runner execution still require the user's local PC environment and are intentionally not claimed as completed here.


## Product boundary

Examcenter Core is free. Any capability required to conduct a normal examination end-to-end is never Pro-gated. Pro covers supporting/advanced capabilities only. New features must be classified using `ExamcenterProductPolicy` before implementation.
