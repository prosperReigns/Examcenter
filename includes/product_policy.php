<?php
declare(strict_types=1);

/**
 * Examcenter product policy:
 * Core = everything required to conduct a normal examination end-to-end.
 * Pro = supporting, advanced, automation, intelligence, security, analytics,
 *       professional document, multi-campus and corporate capabilities.
 *
 * IMPORTANT: Core pages may include license_guard.php for compatibility,
 * but that guard is fail-open and does not enforce Pro entitlement.
 */
final class ExamcenterProductPolicy
{
    public const CORE = 'core';
    public const PRO = 'pro';

    public static function coreFeatures(): array
    {
        return [
            'institution_setup','academic_years','academic_periods',
            'organizational_units','classes','subjects','courses',
            'students','teachers','administrators',
            'teacher_subject_assignment','teacher_class_assignment',
            'question_creation','question_management','test_creation',
            'exam_scheduling','exam_taking','exam_timer','exam_submission',
            'objective_marking','basic_results','result_viewing',
            'basic_result_management','offline_exam_operation',
            'basic_exam_records','basic_required_backups',
        ];
    }

    public static function proFeatures(): array
    {
        return ProFeatures::all();
    }

    public static function isCore(string $feature): bool
    {
        return in_array($feature, self::coreFeatures(), true);
    }

    public static function isPro(string $feature): bool
    {
        return in_array($feature, self::proFeatures(), true);
    }

    public static function rule(): string
    {
        return 'If a feature is necessary to conduct and complete a normal examination, it is Core Free. Supporting or advanced capabilities are Pro.';
    }
}
