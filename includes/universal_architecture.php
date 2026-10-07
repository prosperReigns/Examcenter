<?php

function examcenterUniversalTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int)$stmt->get_result()->fetch_assoc()['total'] > 0;
    $stmt->close();
    return $exists;
}

function examcenterUniversalColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int)$stmt->get_result()->fetch_assoc()['total'] > 0;
    $stmt->close();
    return $exists;
}

function examcenterActiveInstitutionId(mysqli $conn): ?int
{
    if (!examcenterUniversalTableExists($conn, 'institutions')) {
        return null;
    }

    $result = $conn->query(
        "SELECT id FROM institutions
         WHERE status = 'active'
         ORDER BY id
         LIMIT 1"
    );
    $row = $result ? $result->fetch_assoc() : null;
    return $row ? (int)$row['id'] : null;
}

function examcenterFindAcademicPeriod(
    mysqli $conn,
    int $institutionId,
    string $periodCode
): ?int {
    $stmt = $conn->prepare(
        "SELECT id FROM academic_periods
         WHERE institution_id = ? AND period_code = ?
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
    $existing = examcenterFindAcademicPeriod(
        $conn,
        $institutionId,
        $periodCode
    );
    if ($existing !== null) {
        return $existing;
    }

    $stmt = $conn->prepare(
        "INSERT INTO academic_periods
            (institution_id, parent_period_id, period_type, period_name,
             period_code, status, legacy_academic_year_id)
         VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, NULLIF(?, 0))"
    );
    $parent = $parentPeriodId ?? 0;
    $legacy = $legacyAcademicYearId ?? 0;
    $stmt->bind_param(
        'iissssi',
        $institutionId,
        $parent,
        $periodType,
        $periodName,
        $periodCode,
        $status,
        $legacy
    );
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id > 0 ? $id : null;
}
