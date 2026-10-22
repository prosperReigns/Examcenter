<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/**
 * Small durable queue for local AI theory grading.
 * Inference is never performed while a queue/database lock is held.
 */
final class TheoryGradingQueue
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function enqueue(int $submissionId, int $resultId, int $testId, int $studentId): int
    {
        $sql = "INSERT INTO theory_grading_jobs
                    (submission_id,result_id,test_id,student_id,status,attempt_count,max_attempts,available_at)
                VALUES (?,?,?,?, 'pending',0,3,NOW())
                ON DUPLICATE KEY UPDATE
                    available_at=IF(status='completed',available_at,NOW()),
                    locked_at=IF(status='completed',locked_at,NULL),
                    locked_by=IF(status='completed',locked_by,NULL),
                    last_error=IF(status='completed',last_error,NULL),
                    result_id=VALUES(result_id),
                    test_id=VALUES(test_id),
                    student_id=VALUES(student_id),
                    status=IF(status='completed','completed','pending')";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('iiii', $submissionId, $resultId, $testId, $studentId);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        if ($id > 0) {
            return $id;
        }
        $stmt = $this->db->prepare("SELECT id FROM theory_grading_jobs WHERE submission_id=? LIMIT 1");
        $stmt->bind_param('i', $submissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('The theory grading job could not be queued.');
        }
        return (int)$row['id'];
    }

    /**
     * Recover jobs abandoned by a crashed worker. Default lease is 15 minutes.
     */
    public function recoverStaleJobs(int $leaseMinutes = 15): int
    {
        $leaseMinutes = max(1, min(120, $leaseMinutes));
        $stmt = $this->db->prepare(
            "UPDATE theory_grading_jobs
             SET status=IF(attempt_count >= max_attempts,'failed','pending'),
                 available_at=NOW(), locked_at=NULL, locked_by=NULL,
                 last_error=CONCAT(COALESCE(last_error,''), CASE WHEN last_error IS NULL OR last_error='' THEN '' ELSE '\n' END,
                                   'Recovered stale processing job after worker lease expired.')
             WHERE status='processing' AND locked_at < DATE_SUB(NOW(), INTERVAL {$leaseMinutes} MINUTE)"
        );
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();

        // Keep submission/result status aligned when recovering abandoned jobs.
        $stmt = $this->db->prepare(
            "UPDATE theory_submissions s
             JOIN theory_grading_jobs j ON j.submission_id=s.id
             SET s.grading_status=IF(j.status='failed','failed','pending')
             WHERE j.status IN ('pending','failed') AND s.status='submitted'"
        );
        $stmt->execute();
        $stmt->close();
        $stmt = $this->db->prepare(
            "UPDATE results r
             JOIN theory_grading_jobs j ON j.result_id=r.id
             SET r.theory_grading_status=IF(j.status='failed','failed','pending')
             WHERE j.status IN ('pending','failed')"
        );
        $stmt->execute();
        $stmt->close();
        return $count;
    }

    /** Claim one job in a short transaction, then release the DB lock before inference. */
    public function claimNext(string $workerId): ?array
    {
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT id,submission_id,result_id,test_id,student_id,attempt_count,max_attempts
                 FROM theory_grading_jobs
                 WHERE status='pending' AND available_at <= NOW()
                 ORDER BY id ASC LIMIT 1 FOR UPDATE"
            );
            $stmt->execute();
            $job = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$job) {
                $this->db->commit();
                return null;
            }

            $stmt = $this->db->prepare(
                "UPDATE theory_grading_jobs
                 SET status='processing',attempt_count=attempt_count+1,locked_at=NOW(),locked_by=?,last_error=NULL
                 WHERE id=? AND status='pending'"
            );
            $jobId = (int)$job['id'];
            $stmt->bind_param('si', $workerId, $jobId);
            $stmt->execute();
            $claimed = $stmt->affected_rows === 1;
            $stmt->close();
            if ($claimed) {
                $submissionId = (int)$job['submission_id'];
                $stmt = $this->db->prepare("UPDATE theory_submissions SET grading_status='processing' WHERE id=? AND status='submitted'");
                $stmt->bind_param('i', $submissionId);
                $stmt->execute();
                $stmt->close();
                $resultId = (int)($job['result_id'] ?? 0);
                if ($resultId > 0) {
                    $stmt = $this->db->prepare("UPDATE results SET theory_grading_status='processing' WHERE id=?");
                    $stmt->bind_param('i', $resultId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            $this->db->commit();

            if (!$claimed) {
                return null;
            }
            $job['attempt_count'] = (int)$job['attempt_count'] + 1;
            $job['id'] = $jobId;
            return $job;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function completeWithinTransaction(int $jobId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE theory_grading_jobs
             SET status='completed',locked_at=NULL,locked_by=NULL,last_error=NULL,completed_at=NOW()
             WHERE id=?"
        );
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $stmt->close();
    }

    public function fail(int $jobId, int $attemptCount, int $maxAttempts, string $error): string
    {
        $terminal = $attemptCount >= $maxAttempts;
        $status = $terminal ? 'failed' : 'pending';
        $delay = min(300, 5 * (2 ** max(0, $attemptCount - 1)));
        $safeError = substr($error, 0, 4000);
        $stmt = $this->db->prepare(
            "UPDATE theory_grading_jobs
             SET status=?,available_at=DATE_ADD(NOW(), INTERVAL ? SECOND),
                 locked_at=NULL,locked_by=NULL,last_error=?
             WHERE id=?"
        );
        $stmt->bind_param('sisi', $status, $delay, $safeError, $jobId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare(
            "UPDATE theory_submissions SET grading_status=? WHERE id=(SELECT submission_id FROM theory_grading_jobs WHERE id=?)"
        );
        $submissionStatus = $terminal ? 'failed' : 'pending';
        $stmt->bind_param('si', $submissionStatus, $jobId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare(
            "UPDATE results SET theory_grading_status=? WHERE id=(SELECT result_id FROM theory_grading_jobs WHERE id=?)"
        );
        $stmt->bind_param('si', $submissionStatus, $jobId);
        $stmt->execute();
        $stmt->close();
        return $status;
    }

    /** Explicit teacher retry for a terminal failed job; answer rows remain untouched. */
    public function retryFailed(int $jobId): bool
    {
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT submission_id,result_id,status FROM theory_grading_jobs WHERE id=? FOR UPDATE"
            );
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $job = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$job || $job['status'] !== 'failed') {
                $this->db->commit();
                return false;
            }
            $stmt = $this->db->prepare(
                "UPDATE theory_grading_jobs SET status='pending',max_attempts=attempt_count+3,available_at=NOW(),locked_at=NULL,locked_by=NULL,last_error=NULL WHERE id=?"
            );
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $stmt->close();
            $submissionId = (int)$job['submission_id'];
            $stmt = $this->db->prepare("UPDATE theory_submissions SET grading_status='pending' WHERE id=? AND status='submitted'");
            $stmt->bind_param('i', $submissionId);
            $stmt->execute();
            $stmt->close();
            if ($job['result_id'] !== null) {
                $resultId = (int)$job['result_id'];
                $stmt = $this->db->prepare("UPDATE results SET theory_grading_status='pending' WHERE id=?");
                $stmt->bind_param('i', $resultId);
                $stmt->execute();
                $stmt->close();
            }
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
