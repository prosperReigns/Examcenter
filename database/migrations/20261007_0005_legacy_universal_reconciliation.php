<?php
declare(strict_types=1);

/**
 * Reconcile the earlier Universal draft schema with the canonical v2 schema.
 *
 * The original draft used names such as institution_name, unit_name,
 * course_name and period_name. The canonical runtime schema uses name/code
 * plus is_active. This migration is deliberately introspective so it works
 * against both fresh v2 installations and databases created from the draft.
 */

function tableExists(mysqli $db, string $table): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) n FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=?"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n > 0;
}

function columnExists(mysqli $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) n FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name=? AND column_name=?"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n > 0;
}

function addColumn(mysqli $db, string $table, string $column, string $definition): void {
    if (!columnExists($db, $table, $column)) {
        if (!$db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
            throw new RuntimeException("Unable to add {$table}.{$column}: {$db->error}");
        }
    }
}

function copyColumn(mysqli $db, string $table, string $target, string $source): void {
    if (columnExists($db, $table, $target) && columnExists($db, $table, $source)) {
        if (!$db->query(
            "UPDATE `{$table}`
             SET `{$target}`=`{$source}`
             WHERE (`{$target}` IS NULL OR `{$target}`='')"
        )) {
            throw new RuntimeException("Unable to reconcile {$table}.{$target}: {$db->error}");
        }
    }
}

$db = $GLOBALS['db'] ?? null;
if (!$db instanceof mysqli) {
    throw new RuntimeException('Reconciliation migration requires the active database connection.');
}

/*
 * Institutions
 */
if (tableExists($db, 'institutions')) {
    addColumn($db, 'institutions', 'name', 'VARCHAR(255) NULL');
    addColumn($db, 'institutions', 'code', 'VARCHAR(100) NULL');
    addColumn($db, 'institutions', 'is_active', 'TINYINT(1) NULL DEFAULT 1');
    copyColumn($db, 'institutions', 'name', 'institution_name');
    copyColumn($db, 'institutions', 'code', 'institution_code');
    if (columnExists($db, 'institutions', 'status')) {
        $db->query("UPDATE institutions SET is_active=IF(status='active',1,0) WHERE is_active IS NULL");
    }
    $db->query("UPDATE institutions SET is_active=1 WHERE is_active IS NULL");
}

/*
 * Organisational unit types
 */
if (tableExists($db, 'organizational_unit_types')) {
    addColumn($db, 'organizational_unit_types', 'code', 'VARCHAR(80) NULL');
    addColumn($db, 'organizational_unit_types', 'name', 'VARCHAR(150) NULL');
    addColumn($db, 'organizational_unit_types', 'is_active', 'TINYINT(1) NULL DEFAULT 1');
    copyColumn($db, 'organizational_unit_types', 'code', 'type_code');
    copyColumn($db, 'organizational_unit_types', 'name', 'type_name');
    $db->query("UPDATE organizational_unit_types SET is_active=1 WHERE is_active IS NULL");
    $db->query("UPDATE organizational_unit_types SET code=CONCAT('UNIT-TYPE-',id) WHERE code IS NULL OR code=''");
    $db->query("UPDATE organizational_unit_types SET name=CONCAT('Unit Type ',id) WHERE name IS NULL OR name=''");
}

/*
 * Organisational units
 */
if (tableExists($db, 'organizational_units')) {
    addColumn($db, 'organizational_units', 'parent_id', 'BIGINT NULL');
    addColumn($db, 'organizational_units', 'code', 'VARCHAR(100) NULL');
    addColumn($db, 'organizational_units', 'name', 'VARCHAR(255) NULL');
    addColumn($db, 'organizational_units', 'is_active', 'TINYINT(1) NULL DEFAULT 1');
    copyColumn($db, 'organizational_units', 'parent_id', 'parent_unit_id');
    copyColumn($db, 'organizational_units', 'code', 'unit_code');
    copyColumn($db, 'organizational_units', 'name', 'unit_name');
    if (columnExists($db, 'organizational_units', 'status')) {
        $db->query("UPDATE organizational_units SET is_active=IF(status='active',1,0) WHERE is_active IS NULL");
    }
    $db->query("UPDATE organizational_units SET is_active=1 WHERE is_active IS NULL");
}

/*
 * Academic periods
 */
if (tableExists($db, 'academic_periods')) {
    addColumn($db, 'academic_periods', 'parent_id', 'BIGINT NULL');
    addColumn($db, 'academic_periods', 'code', 'VARCHAR(100) NULL');
    addColumn($db, 'academic_periods', 'name', 'VARCHAR(150) NULL');
    addColumn($db, 'academic_periods', 'starts_at', 'DATETIME NULL');
    addColumn($db, 'academic_periods', 'ends_at', 'DATETIME NULL');
    addColumn($db, 'academic_periods', 'metadata', 'JSON NULL');
    copyColumn($db, 'academic_periods', 'parent_id', 'parent_period_id');
    copyColumn($db, 'academic_periods', 'code', 'period_code');
    copyColumn($db, 'academic_periods', 'name', 'period_name');
    if (columnExists($db, 'academic_periods', 'start_date')) {
        $db->query("UPDATE academic_periods SET starts_at=CAST(start_date AS DATETIME) WHERE starts_at IS NULL AND start_date IS NOT NULL");
    }
    if (columnExists($db, 'academic_periods', 'end_date')) {
        $db->query("UPDATE academic_periods SET ends_at=CAST(end_date AS DATETIME) WHERE ends_at IS NULL AND end_date IS NOT NULL");
    }
    $db->query("UPDATE academic_periods SET code=CONCAT('PERIOD-',id) WHERE code IS NULL OR code=''");
    $db->query("UPDATE academic_periods SET name=CONCAT('Period ',id) WHERE name IS NULL OR name=''");
}

/*
 * Programmes and programme levels
 */
if (tableExists($db, 'programmes')) {
    addColumn($db, 'programmes', 'code', 'VARCHAR(100) NULL');
    addColumn($db, 'programmes', 'name', 'VARCHAR(255) NULL');
    addColumn($db, 'programmes', 'is_active', 'TINYINT(1) NULL DEFAULT 1');
    copyColumn($db, 'programmes', 'code', 'programme_code');
    copyColumn($db, 'programmes', 'name', 'programme_name');
    if (columnExists($db, 'programmes', 'status')) {
        $db->query("UPDATE programmes SET is_active=IF(status='active',1,0) WHERE is_active IS NULL");
    }
    $db->query("UPDATE programmes SET is_active=1 WHERE is_active IS NULL");
    $db->query("UPDATE programmes SET code=CONCAT('PROGRAMME-',id) WHERE code IS NULL OR code=''");
}
if (tableExists($db, 'programme_levels')) {
    addColumn($db, 'programme_levels', 'organizational_unit_id', 'BIGINT NULL');
    addColumn($db, 'programme_levels', 'code', 'VARCHAR(100) NULL');
    addColumn($db, 'programme_levels', 'name', 'VARCHAR(150) NULL');
    addColumn($db, 'programme_levels', 'sort_order', 'INT NULL DEFAULT 0');
    copyColumn($db, 'programme_levels', 'code', 'level_code');
    copyColumn($db, 'programme_levels', 'name', 'level_name');
    copyColumn($db, 'programme_levels', 'sort_order', 'level_order');
    $db->query("UPDATE programme_levels SET code=CONCAT('LEVEL-',id) WHERE code IS NULL OR code=''");
    $db->query("UPDATE programme_levels SET name=CONCAT('Level ',id) WHERE name IS NULL OR name=''");
}

/*
 * People and institution memberships
 */
if (tableExists($db, 'people')) {
    addColumn($db, 'people', 'institution_id', 'BIGINT NULL');
    addColumn($db, 'people', 'display_name', 'VARCHAR(255) NULL');
    addColumn($db, 'people', 'external_ref', 'VARCHAR(150) NULL');
    addColumn($db, 'people', 'is_active', 'TINYINT(1) NULL DEFAULT 1');
    copyColumn($db, 'people', 'display_name', 'full_name');
    copyColumn($db, 'people', 'external_ref', 'person_code');
    if (columnExists($db, 'people', 'status')) {
        $db->query("UPDATE people SET is_active=IF(status='active',1,0) WHERE is_active IS NULL");
    }
    $db->query("UPDATE people SET is_active=1 WHERE is_active IS NULL");
}
if (tableExists($db, 'institution_memberships')) {
    addColumn($db, 'institution_memberships', 'starts_at', 'DATETIME NULL');
    addColumn($db, 'institution_memberships', 'ends_at', 'DATETIME NULL');
    addColumn($db, 'institution_memberships', 'metadata', 'JSON NULL');
    if (columnExists($db, 'institution_memberships', 'start_date')) {
        $db->query("UPDATE institution_memberships SET starts_at=CAST(start_date AS DATETIME) WHERE starts_at IS NULL AND start_date IS NOT NULL");
    }
    if (columnExists($db, 'institution_memberships', 'end_date')) {
        $db->query("UPDATE institution_memberships SET ends_at=CAST(end_date AS DATETIME) WHERE ends_at IS NULL AND end_date IS NOT NULL");
    }
    if (tableExists($db, 'people')) {
        $db->query(
            "UPDATE people p
             INNER JOIN institution_memberships im ON im.person_id=p.id
             SET p.institution_id=im.institution_id
             WHERE p.institution_id IS NULL"
        );
    }
}
if (tableExists($db, 'unit_memberships')) {
    addColumn($db, 'unit_memberships', 'unit_id', 'BIGINT NULL');
    addColumn($db, 'unit_memberships', 'person_id', 'BIGINT NULL');
    addColumn($db, 'unit_memberships', 'starts_at', 'DATETIME NULL');
    addColumn($db, 'unit_memberships', 'ends_at', 'DATETIME NULL');
    addColumn($db, 'unit_memberships', 'membership_role', 'VARCHAR(50) NULL');
    copyColumn($db, 'unit_memberships', 'unit_id', 'organizational_unit_id');
    if (columnExists($db, 'unit_memberships', 'institution_membership_id') && tableExists($db, 'institution_memberships')) {
        $db->query(
            "UPDATE unit_memberships um
             INNER JOIN institution_memberships im ON im.id=um.institution_membership_id
             SET um.person_id=im.person_id
             WHERE um.person_id IS NULL"
        );
    }
    if (columnExists($db, 'unit_memberships', 'start_date')) {
        $db->query("UPDATE unit_memberships SET starts_at=CAST(start_date AS DATETIME) WHERE starts_at IS NULL AND start_date IS NOT NULL");
    }
    if (columnExists($db, 'unit_memberships', 'end_date')) {
        $db->query("UPDATE unit_memberships SET ends_at=CAST(end_date AS DATETIME) WHERE ends_at IS NULL AND end_date IS NOT NULL");
    }
}

/*
 * Courses
 */
if (tableExists($db, 'courses')) {
    addColumn($db, 'courses', 'code', 'VARCHAR(100) NULL');
    addColumn($db, 'courses', 'name', 'VARCHAR(255) NULL');
    addColumn($db, 'courses', 'is_active', 'TINYINT(1) NULL DEFAULT 1');
    copyColumn($db, 'courses', 'code', 'course_code');
    copyColumn($db, 'courses', 'name', 'course_name');
    if (columnExists($db, 'courses', 'status')) {
        $db->query("UPDATE courses SET is_active=IF(status='active',1,0) WHERE is_active IS NULL");
    }
    $db->query("UPDATE courses SET is_active=1 WHERE is_active IS NULL");
    $db->query("UPDATE courses SET code=CONCAT('COURSE-',id) WHERE code IS NULL OR code=''");
    $db->query("UPDATE courses SET name=CONCAT('Course ',id) WHERE name IS NULL OR name=''");
}

/*
 * Course assignments
 */
if (tableExists($db, 'course_assignments')) {
    addColumn($db, 'course_assignments', 'unit_id', 'BIGINT NULL');
    addColumn($db, 'course_assignments', 'person_id', 'BIGINT NULL');
    addColumn($db, 'course_assignments', 'role_code', 'VARCHAR(50) NULL');
    copyColumn($db, 'course_assignments', 'unit_id', 'organizational_unit_id');
    if (columnExists($db, 'course_assignments', 'instructor_membership_id') && tableExists($db, 'institution_memberships')) {
        $db->query(
            "UPDATE course_assignments ca
             INNER JOIN institution_memberships im ON im.id=ca.instructor_membership_id
             SET ca.person_id=im.person_id
             WHERE ca.person_id IS NULL"
        );
    }
    copyColumn($db, 'course_assignments', 'role_code', 'assignment_type');
    $db->query("UPDATE course_assignments SET role_code='teacher' WHERE role_code IS NULL OR role_code=''");
}

/*
 * Assessment groups
 */
if (tableExists($db, 'assessment_groups')) {
    addColumn($db, 'assessment_groups', 'code', 'VARCHAR(100) NULL');
    addColumn($db, 'assessment_groups', 'name', 'VARCHAR(255) NULL');
    copyColumn($db, 'assessment_groups', 'code', 'group_code');
    copyColumn($db, 'assessment_groups', 'name', 'group_name');
    $db->query("UPDATE assessment_groups SET code=CONCAT('GROUP-',id) WHERE code IS NULL OR code=''");
    $db->query("UPDATE assessment_groups SET name=CONCAT('Assessment Group ',id) WHERE name IS NULL OR name=''");
}

if (tableExists($db, 'assessment_group_assignments')) {
    addColumn($db, 'assessment_group_assignments', 'unit_id', 'BIGINT NULL');
    copyColumn($db, 'assessment_group_assignments', 'unit_id', 'organizational_unit_id');
}

echo "Legacy Universal schema reconciliation completed." . PHP_EOL;
