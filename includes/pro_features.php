<?php
declare(strict_types=1);
final class ProFeatures {
 public const QUESTION_BANK='question_bank_pro', BLUEPRINTS='assessment_blueprints', ANALYTICS='assessment_analytics', SECURE_MODE='secure_exam_mode', SESSIONS='exam_sessions', CERTIFICATES='certificates', MULTI_CAMPUS='multi_campus', AI_ASSESSMENT='ai_assessment', MODERATION='examiner_moderation', CORPORATE='corporate_assessment', CODING='coding_assessment';
 public static function all():array{return [self::QUESTION_BANK,self::BLUEPRINTS,self::ANALYTICS,self::SECURE_MODE,self::SESSIONS,self::CERTIFICATES,self::MULTI_CAMPUS,self::AI_ASSESSMENT,self::MODERATION,self::CORPORATE,self::CODING];}
}