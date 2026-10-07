<?php

declare(strict_types=1);
require_once __DIR__ . '/../db.php';
final class ProAssessmentService
{
    private mysqli $db;
    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?: Database::connection();
    }
    public function recordSecurityEvent(int $testId, ?int $studentId, ?int $attemptId, string $type, string $severity = 'info', array $evidence = []): int
    {
        $j = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $s = $this->db->prepare("INSERT INTO pro_exam_security_events(test_id,student_id,attempt_id,event_type,severity,evidence) VALUES(?,?,?,?,?,?)");
        $s->bind_param('iiisss', $testId, $studentId, $attemptId, $type, $severity, $j);
        $s->execute();
        $id = $this->db->insert_id;
        $s->close();
        return (int)$id;
    }
    public function issueCertificate(int $institutionId, string $recipient, string $certificateNo, ?int $studentId = null, ?int $testId = null): int
    {
        $token = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $s = $this->db->prepare("INSERT INTO pro_certificates(institution_id,student_id,test_id,certificate_no,recipient_name,issued_at,verification_token) VALUES(?,?,?,?,?,?,?)");
        $s->bind_param('iiissss', $institutionId, $studentId, $testId, $certificateNo, $recipient, $now, $token);
        $s->execute();
        $id = $this->db->insert_id;
        $s->close();
        return (int)$id;
    }
    public function verifyCertificate(string $token): ?array
    {
        $s = $this->db->prepare("SELECT c.*,i.name institution_name FROM pro_certificates c JOIN institutions i ON i.id=c.institution_id WHERE c.verification_token=? LIMIT 1");
        $s->bind_param('s', $token);
        $s->execute();
        $r = $s->get_result()->fetch_assoc();
        $s->close();
        return $r ?: null;
    }
    public function itemAnalysis(int $testId): array
    {
        $s = $this->db->prepare("SELECT nq.id,nq.question_text,COUNT(ea.id) sample_size,AVG(CASE WHEN ea.answer IS NOT NULL AND ea.answer<>'' THEN 1 ELSE 0 END) facility FROM new_questions nq LEFT JOIN exam_attempts ea ON ea.question_id=nq.id AND ea.test_id=nq.test_id WHERE nq.test_id=? GROUP BY nq.id ORDER BY nq.id");
        $s->bind_param('i', $testId);
        $s->execute();
        $r = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
        return $r;
    }
}
