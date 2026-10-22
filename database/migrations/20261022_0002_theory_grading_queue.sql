-- Examcenter: persistent, resumable background theory grading.
-- Apply once after 20261005_0002_theory_assessment.sql and
-- 20261022_0001_combined_theory_exam_scores.sql.
-- Existing student answers and objective results are preserved.

ALTER TABLE results
    ADD COLUMN objective_score DECIMAL(10,2) NULL AFTER score,
    ADD COLUMN objective_total DECIMAL(10,2) NULL AFTER objective_score,
    ADD COLUMN theory_score DECIMAL(10,2) NULL AFTER objective_score,
    ADD COLUMN theory_grading_status VARCHAR(20) NOT NULL DEFAULT 'not_required' AFTER status;

CREATE TABLE IF NOT EXISTS theory_grading_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    result_id INT NULL,
    test_id INT NOT NULL,
    student_id INT NOT NULL,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by VARCHAR(120) NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_theory_grading_submission (submission_id),
    KEY idx_theory_grading_claim (status, available_at, id),
    KEY idx_theory_grading_stale (status, locked_at),
    KEY idx_theory_grading_test_student (test_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
