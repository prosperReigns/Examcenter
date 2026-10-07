<?php
declare(strict_types=1);
final class GradingResult {
    public static function fromModel(array $data, float $max): array {
        $score=max(0.0,min($max,(float)($data['awarded_marks']??0)));
        $clamp=static fn($v)=>($v===null||$v==='')?null:max(0.0,min(1.0,(float)$v));
        return [
            'awarded_marks'=>$score,'maximum_marks'=>$max,
            'percentage'=>$max>0?($score/$max)*100:0,
            'confidence'=>$clamp($data['confidence']??null),
            'relevance_score'=>$clamp($data['relevance_score']??null),
            'correctness_score'=>$clamp($data['correctness_score']??null),
            'completeness_score'=>$clamp($data['completeness_score']??null),
            'understanding_score'=>$clamp($data['understanding_score']??null),
            'explanation'=>(string)($data['explanation']??''),
            'strengths'=>isset($data['strengths'])?json_encode($data['strengths'],JSON_UNESCAPED_UNICODE):null,
            'weaknesses'=>isset($data['weaknesses'])?json_encode($data['weaknesses'],JSON_UNESCAPED_UNICODE):null,
            'missing_points'=>isset($data['missing_points'])?json_encode($data['missing_points'],JSON_UNESCAPED_UNICODE):null
        ];
    }
}