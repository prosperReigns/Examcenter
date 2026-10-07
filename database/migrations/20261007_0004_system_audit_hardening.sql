-- Examcenter system-audit hardening migration.
-- Repairs schema contracts that the Universal/Pro PHP layer already expects.
-- Safe for installations that have already applied 0001-0003.

ALTER TABLE tests
    ADD COLUMN IF NOT EXISTS institution_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS organizational_unit_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS programme_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS programme_level_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS academic_period_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS course_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS assessment_group_id BIGINT UNSIGNED NULL;

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS legacy_subject_id INT NULL;

ALTER TABLE academic_periods
    ADD COLUMN IF NOT EXISTS legacy_academic_year_id INT NULL;

ALTER TABLE exam_attempts
    ADD COLUMN IF NOT EXISTS institution_membership_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS assessment_assignment_id BIGINT UNSIGNED NULL;

ALTER TABLE results
    ADD COLUMN IF NOT EXISTS institution_membership_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS academic_period_id BIGINT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS assessment_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_group_id BIGINT UNSIGNED NOT NULL,
    institution_membership_id BIGINT UNSIGNED NULL,
    organizational_unit_id BIGINT UNSIGNED NULL,
    programme_level_id BIGINT UNSIGNED NULL,
    course_id BIGINT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    due_at DATETIME NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'assigned',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_assessment_assignment_group (assessment_group_id),
    INDEX idx_assessment_assignment_member (institution_membership_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_attempt_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    test_id INT NOT NULL,
    attempt_no INT UNSIGNED NOT NULL DEFAULT 1,
    institution_membership_id BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL,
    last_activity_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    submitted_at DATETIME NULL,
    current_index INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'in_progress',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_exam_attempt_session (user_id, test_id, attempt_no),
    INDEX idx_exam_attempt_session_status (test_id, status),
    INDEX idx_exam_attempt_session_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_attempt_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_session_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    question_id INT NULL,
    metadata JSON NULL,
    occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_exam_attempt_events_session (attempt_session_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS core_feature_catalog (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feature_code VARCHAR(100) NOT NULL UNIQUE,
    feature_name VARCHAR(180) NOT NULL,
    description TEXT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO core_feature_catalog(feature_code, feature_name, description) VALUES
('institution_setup','Institution Setup','Configure the assessment organisation.'),
('academic_structure','Academic/Organisational Structure','Classes, units, programmes, levels and periods.'),
('identity_access','Identity and Access','Administrators, teachers and candidates.'),
('question_authoring','Question Authoring','Create and manage assessment questions.'),
('assessment_authoring','Assessment Authoring','Create, configure and publish tests.'),
('assessment_scheduling','Assessment Scheduling','Schedule and reschedule examinations.'),
('exam_delivery','Exam Delivery','Offline candidate examination workflow.'),
('exam_recovery','Exam Recovery','Persist answers and recover interrupted attempts.'),
('objective_marking','Objective Marking','Automatic objective-question marking.'),
('theory_assessment','Theory Assessment','Written answers and manual/AI-assisted marking.'),
('results','Results','Calculate, store and view assessment results.'),
('basic_reporting','Basic Reporting','Essential candidate and assessment reports.'),
('offline_operation','Offline Operation','Core assessment lifecycle without Internet.'),
('basic_security','Basic Security','Authentication, access control and exam integrity.');
