<?php

declare(strict_types=1);
require_once __DIR__ . '/OllamaTheoryGrader.php';
require_once __DIR__ . '/../db.php';
final class TheoryGradingService
{
    public function __construct(private OllamaTheoryGrader $grader) {}
    public function gradeAnswer(int $answerId): array
    {
        $db = Database::connection();
        $s = $db->prepare("SELECT a.answer_text,q.question_text,q.maximum_marks FROM theory_answers a JOIN theory_questions q ON q.id=a.theory_question_id WHERE a.id=? LIMIT 1");
        $s->bind_param('i', $answerId);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row) throw new RuntimeException('Theory answer not found.');
        $r = $this->grader->grade((string)$row['question_text'], (float)$row['maximum_marks'], (string)$row['answer_text']);
        $s = $db->prepare("INSERT INTO theory_marks (answer_id,awarded_marks,maximum_marks,percentage,confidence,relevance_score,correctness_score,completeness_score,understanding_score,explanation,strengths,weaknesses,missing_points,grading_model,grading_model_version,status,graded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ai_provisional',NOW()) ON DUPLICATE KEY UPDATE awarded_marks=VALUES(awarded_marks),maximum_marks=VALUES(maximum_marks),percentage=VALUES(percentage),confidence=VALUES(confidence),relevance_score=VALUES(relevance_score),correctness_score=VALUES(correctness_score),completeness_score=VALUES(completeness_score),understanding_score=VALUES(understanding_score),explanation=VALUES(explanation),strengths=VALUES(strengths),weaknesses=VALUES(weaknesses),missing_points=VALUES(missing_points),grading_model=VALUES(grading_model),status='ai_provisional',graded_at=NOW()");
        $model = (string)(getenv('EXAMCENTER_THEORY_MODEL') ?: 'qwen3:1.7b');
        $ver = 'local';
        $s->bind_param('iddddddddssssss', $answerId, $r['awarded_marks'], $r['maximum_marks'], $r['percentage'], $r['confidence'], $r['relevance_score'], $r['correctness_score'], $r['completeness_score'], $r['understanding_score'], $r['explanation'], $r['strengths'], $r['weaknesses'], $r['missing_points'], $model, $ver);
        $s->execute();
        $s->close();
        return $r;
    }
}
