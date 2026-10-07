<?php
declare(strict_types=1);

require_once __DIR__ . '/universal_architecture.php';

function examcenterResolveTestContext(
    mysqli $conn,
    string $subject,
    ?int $academicLevelId = null,
    ?int $streamId = null
): array {
    $context = [
        'institution_id' => null,
        'organizational_unit_id' => null,
        'course_id' => null,
    ];

    if (!examcenterUniversalTableExists($conn, 'institutions')) {
        return $context;
    }

    $institutionId = examcenterActiveInstitutionId($conn);
    if ($institutionId === null) return $context;
    $context['institution_id'] = $institutionId;

    if (
        examcenterUniversalTableExists($conn, 'courses') &&
        examcenterUniversalTableExists($conn, 'subjects') &&
        examcenterUniversalColumnExists($conn, 'courses', 'legacy_subject_id')
    ) {
        $stmt = $conn->prepare(
            "SELECT c.id
             FROM courses c
             INNER JOIN subjects s ON s.id = c.legacy_subject_id
             WHERE c.institution_id = ? AND s.subject_name = ?
             ORDER BY c.id
             LIMIT 1"
        );
        $stmt->bind_param('is', $institutionId, $subject);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($course) $context['course_id'] = (int)$course['id'];
    }

    if (
        $academicLevelId &&
        $streamId &&
        examcenterUniversalTableExists($conn, 'classes') &&
        examcenterUniversalTableExists($conn, 'organizational_units')
    ) {
        $stmt = $conn->prepare(
            "SELECT ou.id
             FROM organizational_units ou
             INNER JOIN classes c ON c.id = ou.legacy_class_id
             WHERE c.academic_level_id = ?
               AND c.stream_id = ?
               AND ou.institution_id = ?
             LIMIT 1"
        );
        $stmt->bind_param('iii', $academicLevelId, $streamId, $institutionId);
        $stmt->execute();
        $unit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($unit) $context['organizational_unit_id'] = (int)$unit['id'];
    }

    return $context;
}
