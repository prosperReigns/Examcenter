<?php
declare(strict_types=1);

require_once __DIR__ . '/TheoryGraderInterface.php';
require_once __DIR__ . '/GradingResult.php';
require_once __DIR__ . '/../db.php';

final class TheoryGradingService
{
    public function __construct(private TheoryGraderInterface $grader) {}

    public function gradeAnswer(int $answerId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT a.id answer_id,a.answer_text,q.question_text,q.maximum_marks FROM theory_answers a JOIN theory_questions q ON q.id=a.theory_question_id WHERE a.id=? LIMIT 1");
        $stmt->bind_param('i', $answerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('Theory answer not found.');
        }
        $result = trim((string)$row['answer_text']) === ''
            ? GradingResult::fromModel(['awarded_marks'=>0,'explanation'=>'No answer was provided; zero marks awarded.','confidence'=>1,'relevance_score'=>0,'correctness_score'=>0,'completeness_score'=>0,'understanding_score'=>0,'strengths'=>[],'weaknesses'=>['No answer submitted'],'missing_points'=>['A response to the question']], (float)$row['maximum_marks'])
            : $this->grader->grade((string)$row['question_text'], (float)$row['maximum_marks'], (string)$row['answer_text']);
        $this->saveMark($db, $answerId, $result);
        return $result;
    }

    /**
     * Fetch all answers for one student-subject paper, then perform one model call.
     * No database transaction or row lock is held while the model is running.
     */
    public function gradeSubmissionBatch(int $submissionId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT a.id answer_id,a.answer_text,q.question_number,q.question_text,q.maximum_marks
             FROM theory_answers a
             JOIN theory_questions q ON q.id=a.theory_question_id
             WHERE a.submission_id=?
             ORDER BY q.question_number ASC"
        );
        $stmt->bind_param('i', $submissionId);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if ($items === []) {
            throw new RuntimeException('The submitted theory paper has no answer rows.');
        }
        if (!method_exists($this->grader, 'gradeBatch')) {
            throw new RuntimeException('The configured theory grader does not support five-question batch grading.');
        }

        // Each model request contains no more than five questions, even if a future assessment is longer.
        $grades = [];
        foreach (array_chunk($items, 5) as $chunk) {
            $chunkGrades = $this->grader->gradeBatch($chunk);
            foreach ($chunk as $item) {
                $answerId = (int)$item['answer_id'];
                if (!isset($chunkGrades[$answerId])) {
                    throw new RuntimeException('The batch grader did not return a valid grade for every answer.');
                }
                $grades[$answerId] = $chunkGrades[$answerId];
            }
        }
        return ['items' => $items, 'grades' => $grades];
    }

    /**
     * Persist model output inside the worker's short transaction.
     * Teacher-final marks are never overwritten by an automatic retry.
     */
    public function persistBatch(array $items, array $grades): array
    {
        $db = Database::connection();
        $total = 0.0;
        $maximum = 0.0;
        foreach ($items as $item) {
            $answerId = (int)$item['answer_id'];
            $maximumMarks = (float)$item['maximum_marks'];
            $stmt = $db->prepare("SELECT awarded_marks,maximum_marks,status FROM theory_marks WHERE answer_id=? LIMIT 1");
            $stmt->bind_param('i', $answerId);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing && $existing['status'] === 'teacher_final') {
                $total += (float)$existing['awarded_marks'];
                $maximum += (float)$existing['maximum_marks'];
                continue;
            }

            $result = $grades[$answerId];
            $this->saveMark($db, $answerId, $result);
            $total += (float)$result['awarded_marks'];
            $maximum += (float)$result['maximum_marks'];
        }
        return ['total_marks' => $total, 'maximum_marks' => $maximum];
    }

    private function saveMark(mysqli $db, int $answerId, array $result): void
    {
        $stmt = $db->prepare(
            "INSERT INTO theory_marks
             (answer_id,awarded_marks,maximum_marks,percentage,confidence,relevance_score,correctness_score,completeness_score,understanding_score,explanation,strengths,weaknesses,missing_points,grading_model,grading_model_version,status,graded_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ai_provisional',NOW())
             ON DUPLICATE KEY UPDATE
                awarded_marks=VALUES(awarded_marks),maximum_marks=VALUES(maximum_marks),percentage=VALUES(percentage),
                confidence=VALUES(confidence),relevance_score=VALUES(relevance_score),correctness_score=VALUES(correctness_score),
                completeness_score=VALUES(completeness_score),understanding_score=VALUES(understanding_score),
                explanation=VALUES(explanation),strengths=VALUES(strengths),weaknesses=VALUES(weaknesses),
                missing_points=VALUES(missing_points),grading_model=VALUES(grading_model),
                grading_model_version=VALUES(grading_model_version),status='ai_provisional',graded_at=NOW()"
        );
        $model = (string)(getenv('EXAMCENTER_LLAMA_MODEL_ALIAS') ?: 'qwen3-1.7b');
        $version = 'llama.cpp-local';
        $stmt->bind_param(
            'iddddddddssssss',
            $answerId, $result['awarded_marks'], $result['maximum_marks'], $result['percentage'],
            $result['confidence'], $result['relevance_score'], $result['correctness_score'],
            $result['completeness_score'], $result['understanding_score'], $result['explanation'],
            $result['strengths'], $result['weaknesses'], $result['missing_points'], $model, $version
        );
        $stmt->execute();
        $stmt->close();
    }
}
