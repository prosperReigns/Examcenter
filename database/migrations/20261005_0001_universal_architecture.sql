-- Examcenter Universal Architecture / Pro Architecture v2
-- MySQL / MariaDB, utf8mb4
-- Additive migration: legacy CBT tables remain operational.

CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration_key VARCHAR(191) NOT NULL UNIQUE,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institutions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_type_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(100) NULL UNIQUE,
    timezone VARCHAR(100) NOT NULL DEFAULT 'Africa/Lagos',
    country_code CHAR(2) NULL,
    settings JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_institutions_type FOREIGN KEY (institution_type_id) REFERENCES institution_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organizational_unit_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organizational_units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id BIGINT UNSIGNED NOT NULL,
    unit_type_id INT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    legacy_class_id INT NULL,
    code VARCHAR(100) NULL,
    name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_unit_code (institution_id, code),
    CONSTRAINT fk_org_units_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_org_units_type FOREIGN KEY (unit_type_id) REFERENCES organizational_unit_types(id),
    CONSTRAINT fk_org_units_parent FOREIGN KEY (parent_id) REFERENCES organizational_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_org_units_legacy_class FOREIGN KEY (legacy_class_id) REFERENCES classes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    period_type VARCHAR(50) NOT NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'inactive',
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period_code (institution_id, code),
    CONSTRAINT fk_period_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_period_parent FOREIGN KEY (parent_id) REFERENCES academic_periods(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS programmes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    programme_type VARCHAR(80) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_programme_code (institution_id, code),
    CONSTRAINT fk_programmes_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS programme_levels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    programme_id BIGINT UNSIGNED NOT NULL,
    organizational_unit_id BIGINT UNSIGNED NULL,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    metadata JSON NULL,
    UNIQUE KEY uq_programme_level (programme_id, code),
    CONSTRAINT fk_programme_levels_programme FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE,
    CONSTRAINT fk_programme_levels_unit FOREIGN KEY (organizational_unit_id) REFERENCES organizational_units(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id BIGINT UNSIGNED NOT NULL,
    first_name VARCHAR(120) NULL,
    middle_name VARCHAR(120) NULL,
    last_name VARCHAR(120) NULL,
    display_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    external_ref VARCHAR(150) NULL,
    photo_path VARCHAR(500) NULL,
    metadata JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_person_external_ref (institution_id, external_ref),
    CONSTRAINT fk_people_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    role_code VARCHAR(50) NOT NULL,
    legacy_student_id INT NULL,
    legacy_teacher_id INT NULL,
    legacy_admin_id INT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_person_role (institution_id, person_id, role_code),
    CONSTRAINT fk_membership_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_membership_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
    CONSTRAINT fk_membership_student FOREIGN KEY (legacy_student_id) REFERENCES students(id) ON DELETE SET NULL,
    CONSTRAINT fk_membership_teacher FOREIGN KEY (legacy_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL,
    CONSTRAINT fk_membership_admin FOREIGN KEY (legacy_admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    membership_role VARCHAR(50) NOT NULL DEFAULT 'member',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_unit_person_role (unit_id, person_id, membership_role),
    CONSTRAINT fk_unit_membership_unit FOREIGN KEY (unit_id) REFERENCES organizational_units(id) ON DELETE CASCADE,
    CONSTRAINT fk_unit_membership_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id BIGINT UNSIGNED NOT NULL,
    programme_id BIGINT UNSIGNED NULL,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    course_type VARCHAR(80) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_course_code (institution_id, code),
    CONSTRAINT fk_courses_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_courses_programme FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id BIGINT UNSIGNED NOT NULL,
    unit_id BIGINT UNSIGNED NULL,
    person_id BIGINT UNSIGNED NULL,
    academic_period_id BIGINT UNSIGNED NULL,
    role_code VARCHAR(50) NOT NULL DEFAULT 'teacher',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_course_assignment (course_id, unit_id, person_id, academic_period_id, role_code),
    CONSTRAINT fk_course_assignment_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_course_assignment_unit FOREIGN KEY (unit_id) REFERENCES organizational_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_course_assignment_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL,
    CONSTRAINT fk_course_assignment_period FOREIGN KEY (academic_period_id) REFERENCES academic_periods(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    group_type VARCHAR(80) NULL,
    weighting DECIMAL(8,3) NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assessment_group (institution_id, code),
    CONSTRAINT fk_assessment_groups_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_group_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_group_id BIGINT UNSIGNED NOT NULL,
    unit_id BIGINT UNSIGNED NULL,
    programme_id BIGINT UNSIGNED NULL,
    academic_period_id BIGINT UNSIGNED NULL,
    course_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assessment_group_assignment (assessment_group_id, unit_id, programme_id, academic_period_id, course_id),
    CONSTRAINT fk_aga_group FOREIGN KEY (assessment_group_id) REFERENCES assessment_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_aga_unit FOREIGN KEY (unit_id) REFERENCES organizational_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_aga_programme FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE SET NULL,
    CONSTRAINT fk_aga_period FOREIGN KEY (academic_period_id) REFERENCES academic_periods(id) ON DELETE SET NULL,
    CONSTRAINT fk_aga_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS universal_assessment_context (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    test_id INT NOT NULL,
    institution_id BIGINT UNSIGNED NOT NULL,
    unit_id BIGINT UNSIGNED NULL,
    programme_id BIGINT UNSIGNED NULL,
    academic_period_id BIGINT UNSIGNED NULL,
    course_id BIGINT UNSIGNED NULL,
    assessment_group_id BIGINT UNSIGNED NULL,
    assessment_type VARCHAR(80) NOT NULL DEFAULT 'exam',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assessment_context_test (test_id),
    CONSTRAINT fk_uac_test FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE,
    CONSTRAINT fk_uac_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_uac_unit FOREIGN KEY (unit_id) REFERENCES organizational_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_uac_programme FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE SET NULL,
    CONSTRAINT fk_uac_period FOREIGN KEY (academic_period_id) REFERENCES academic_periods(id) ON DELETE SET NULL,
    CONSTRAINT fk_uac_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    CONSTRAINT fk_uac_group FOREIGN KEY (assessment_group_id) REFERENCES assessment_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pro_entitlement_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entitlement_id CHAR(36) NULL,
    product_code VARCHAR(80) NOT NULL DEFAULT 'examcenter',
    edition VARCHAR(50) NOT NULL DEFAULT 'pro',
    status VARCHAR(30) NOT NULL DEFAULT 'unknown',
    installation_id VARCHAR(255) NOT NULL,
    machine_id VARCHAR(255) NOT NULL,
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    package_version INT NOT NULL DEFAULT 2,
    public_key_version VARCHAR(30) NULL,
    signed_package LONGTEXT NOT NULL,
    last_verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pro_entitlement_installation (installation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pro_entitlement_features (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entitlement_cache_id BIGINT UNSIGNED NOT NULL,
    feature_code VARCHAR(100) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pro_entitlement_feature (entitlement_cache_id, feature_code),
    CONSTRAINT fk_pro_feature_cache FOREIGN KEY (entitlement_cache_id) REFERENCES pro_entitlement_cache(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO institution_types (code, name) VALUES
('primary_school', 'Primary School'),
('secondary_school', 'Secondary School'),
('tertiary_institution', 'Tertiary Institution'),
('vocational_institution', 'Vocational / Training Institution'),
('corporate', 'Corporate / Organisation'),
('assessment_centre', 'Assessment Centre'),
('other', 'Other');

INSERT IGNORE INTO organizational_unit_types (code, name) VALUES
('campus', 'Campus'),
('faculty', 'Faculty'),
('department', 'Department'),
('school', 'School / College'),
('grade', 'Grade / Year'),
('class', 'Class / Stream'),
('cohort', 'Cohort'),
('programme', 'Programme'),
('division', 'Division'),
('team', 'Team'),
('custom', 'Custom');
