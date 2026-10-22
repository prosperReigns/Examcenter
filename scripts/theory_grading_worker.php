<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../theory/TheoryGradingQueue.php';
require_once __DIR__ . '/../theory/TheoryGradingService.php';
require_once __DIR__ . '/../theory/LlamaCppRuntime.php';
require_once __DIR__ . '/../theory/LlamaCppTheoryGrader.php';

set_time_limit(0);

// Prevent two launcher invocations on the same host from starting parallel workers.
$workerLockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'examcenter-theory-worker.lock';
$workerLock = @fopen($workerLockPath, 'c');
if ($workerLock === false || !flock($workerLock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[theory-worker] Another worker already holds the host lock; exiting.\n");
    exit(0);
}

$options = $argv ?? [];
$once = in_array('--once', $options, true);
$sleepSeconds = 2;
foreach ($options as $option) {
    if (str_starts_with($option, '--sleep=')) {
        $sleepSeconds = max(1, min(30, (int)substr($option, 8)));
    }
}

$db = Database::connection();
$queue = new TheoryGradingQueue($db);
$endpoint = (string)(getenv('EXAMCENTER_LLAMA_ENDPOINT') ?: 'http://127.0.0.1:11435');
$model = (string)(getenv('EXAMCENTER_LLAMA_MODEL_ALIAS') ?: 'qwen3-1.7b');
$runtime = new LlamaCppRuntime($endpoint, $model);
$grader = new LlamaCppTheoryGrader($runtime, $endpoint, $model, 600);
$service = new TheoryGradingService($grader);
$workerId = gethostname() . ':php-' . getmypid();

fwrite(STDOUT, '[theory-worker] Started ' . $workerId . '; inference requests are sequential.' . PHP_EOL);

do {
    try {
        $queue->recoverStaleJobs(15);
        $job = $queue->claimNext($workerId);
        if (!$job) {
            if ($once) {
                break;
            }
            sleep($sleepSeconds);
            continue;
        }

        $jobId = (int)$job['id'];
        $submissionId = (int)$job['submission_id'];
        fwrite(STDOUT, '[theory-worker] Processing job ' . $jobId . ' (submission ' . $submissionId . ').' . PHP_EOL);

        try {
            // This is the slow step. It runs before opening the short DB write transaction.
            $batch = $service->gradeSubmissionBatch($submissionId);
            $db->begin_transaction();
            try {
                $totals = $service->persistBatch($batch['items'], $batch['grades']);
                $stmt = $db->prepare(
                    "UPDATE theory_submissions
                     SET status='graded',grading_status='completed',total_marks=?,maximum_marks=?
                     WHERE id=? AND status='submitted'"
                );
                $stmt->bind_param('ddi', $totals['total_marks'], $totals['maximum_marks'], $submissionId);
                $stmt->execute();
                $stmt->close();

                $resultId = (int)($job['result_id'] ?? 0);
                if ($resultId > 0) {
                    $stmt = $db->prepare(
                        "UPDATE results
                         SET theory_score=?,score=COALESCE(objective_score,score)+?,theory_grading_status='completed'
                         WHERE id=?"
                    );
                    $stmt->bind_param('ddi', $totals['total_marks'], $totals['total_marks'], $resultId);
                    $stmt->execute();
                    $stmt->close();
                }
                $queue->completeWithinTransaction($jobId);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
            fwrite(STDOUT, '[theory-worker] Completed job ' . $jobId . '; score ' . $totals['total_marks'] . '/' . $totals['maximum_marks'] . '.' . PHP_EOL);
        } catch (Throwable $e) {
            $status = $queue->fail(
                $jobId,
                (int)$job['attempt_count'],
                (int)$job['max_attempts'],
                get_class($e) . ': ' . $e->getMessage()
            );
            error_log('[Examcenter theory worker] Job ' . $jobId . ' ' . $status . ': ' . $e->getMessage());
            fwrite(STDERR, '[theory-worker] Job ' . $jobId . ' ' . $status . ': ' . $e->getMessage() . PHP_EOL);
        }

        if ($once) {
            break;
        }
    } catch (Throwable $e) {
        error_log('[Examcenter theory worker] Queue loop error: ' . $e->getMessage());
        fwrite(STDERR, '[theory-worker] Queue loop error: ' . $e->getMessage() . PHP_EOL);
        if ($once) {
            exit(1);
        }
        sleep($sleepSeconds);
    }
} while (true);

fwrite(STDOUT, '[theory-worker] Stopped.' . PHP_EOL);
