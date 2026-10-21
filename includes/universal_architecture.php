<?php
declare(strict_types=1);

/**
 * Universal architecture compatibility helpers.
 *
 * These helpers use the canonical names from
 * database/migrations/20261005_0001_universal_architecture.sql.
 */

function examcenterUniversalTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function examcenterUniversalColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function examcenterActiveInstitutionId(mysqli $conn): ?int
{
    if (!examcenterUniversalTableExists($conn, 'institutions')) return null;

    $result = $conn->query(
        "SELECT id FROM institutions
         WHERE is_active = 1
         ORDER BY id
         LIMIT 1"
    );
    $row = $result ? $result->fetch_assoc() : null;
    return $row ? (int)$row['id'] : null;
}

/**
 * Return the active institution and a stable category derived from its saved type.
 * Legacy installations without universal tables resolve to an "other" profile.
 */
function examcenterInstitutionProfile(mysqli $conn): array
{
    $profile = [
        'id' => null,
        'type_id' => null,
        'type_code' => '',
        'type_name' => '',
        'category' => 'other',
    ];

    if (!examcenterUniversalTableExists($conn, 'institutions')) return $profile;

    $hasTypes = examcenterUniversalTableExists($conn, 'institution_types')
        && examcenterUniversalColumnExists($conn, 'institutions', 'institution_type_id');
    $sql = $hasTypes
        ? "SELECT i.id, i.institution_type_id AS type_id, it.code AS type_code, it.name AS type_name
           FROM institutions i LEFT JOIN institution_types it ON it.id = i.institution_type_id
           WHERE i.is_active = 1 ORDER BY i.id LIMIT 1"
        : "SELECT id FROM institutions WHERE is_active = 1 ORDER BY id LIMIT 1";
    $result = $conn->query($sql);
    $row = $result ? $result->fetch_assoc() : null;
    if (!$row) return $profile;

    $code = strtolower(trim((string)($row['type_code'] ?? '')));
    $name = strtolower(trim((string)($row['type_name'] ?? '')));
    $category = 'other';
    if (str_contains($code, 'primary') || str_contains($name, 'primary')) {
        $category = 'primary_school';
    } elseif (str_contains($code, 'secondary') || str_contains($name, 'secondary')) {
        $category = 'secondary_school';
    } elseif (str_contains($code, 'tertiary') || str_contains($code, 'higher')
        || str_contains($name, 'university') || str_contains($name, 'college')
        || str_contains($name, 'higher education') || str_contains($name, 'tertiary')) {
        $category = 'higher_education';
    } elseif (str_contains($code, 'vocational') || str_contains($name, 'vocational')) {
        $category = 'vocational';
    } elseif (str_contains($code, 'corporate') || str_contains($code, 'business')
        || str_contains($name, 'corporate') || str_contains($name, 'business')) {
        $category = 'business';
    }

    return [
        'id' => (int)$row['id'],
        'type_id' => isset($row['type_id']) ? (int)$row['type_id'] : null,
        'type_code' => (string)($row['type_code'] ?? ''),
        'type_name' => (string)($row['type_name'] ?? ''),
        'category' => $category,
    ];
}

/**
 * List universal course assignments for a legacy teacher account.
 * The universal membership links the legacy teachers row to a people row.
 */
function examcenterTeacherCourseAssignments(mysqli $conn, int $teacherId, int $institutionId): array
{
    $requiredTables = ['course_assignments', 'courses', 'people', 'institution_memberships'];
    foreach ($requiredTables as $table) {
        if (!examcenterUniversalTableExists($conn, $table)) return [];
    }

    $stmt = $conn->prepare(
        "SELECT DISTINCT c.id AS course_id, c.code AS course_code, c.name AS course_name,
                ca.unit_id, ou.name AS unit_name, ca.academic_period_id
         FROM institution_memberships im
         INNER JOIN people p ON p.id = im.person_id
         INNER JOIN course_assignments ca ON ca.person_id = p.id AND ca.role_code = 'teacher'
         INNER JOIN courses c ON c.id = ca.course_id AND c.institution_id = im.institution_id AND c.is_active = 1
         LEFT JOIN organizational_units ou ON ou.id = ca.unit_id
         WHERE im.legacy_teacher_id = ? AND im.institution_id = ? AND im.status = 'active'
         ORDER BY c.name, ou.name"
    );
    $stmt->bind_param('ii', $teacherId, $institutionId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function examcenterFindAcademicPeriod(mysqli $conn, int $institutionId, string $periodCode): ?int
{
    if (!examcenterUniversalTableExists($conn, 'academic_periods')) return null;

    $stmt = $conn->prepare(
        "SELECT id FROM academic_periods
         WHERE institution_id = ? AND code = ?
         LIMIT 1"
    );
    $stmt->bind_param('is', $institutionId, $periodCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['id'] : null;
}

function examcenterSyncAcademicPeriod(
    mysqli $conn,
    int $institutionId,
    string $periodType,
    string $periodName,
    string $periodCode,
    ?int $parentPeriodId = null,
    ?int $legacyAcademicYearId = null,
    string $status = 'planned'
): ?int {
    $existing = examcenterFindAcademicPeriod($conn, $institutionId, $periodCode);
    if ($existing !== null) return $existing;

    $stmt = $conn->prepare(
        "INSERT INTO academic_periods
            (institution_id, parent_id, code, name, period_type, status, metadata)
         VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?)"
    );

    $parent = $parentPeriodId ?? 0;
    $metadata = json_encode(
        ['legacy_academic_year_id' => $legacyAcademicYearId],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?: '{}';

    $stmt->bind_param(
        'iisssss',
        $institutionId,
        $parent,
        $periodCode,
        $periodName,
        $periodType,
        $status,
        $metadata
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id > 0 ? $id : null;
}
