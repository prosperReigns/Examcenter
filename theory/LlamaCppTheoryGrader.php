<?php
declare(strict_types=1);

require_once __DIR__ . '/GradingResult.php';
require_once __DIR__ . '/TheoryGraderInterface.php';
require_once __DIR__ . '/LlamaCppRuntime.php';

final class LlamaCppTheoryGrader implements TheoryGraderInterface
{
    public function __construct(
        private ?LlamaCppRuntime $runtime = null,
        private string $endpoint = 'http://127.0.0.1:11435',
        private string $model = 'qwen3-1.7b',
        private int $timeout = 300
    ) {
        $this->runtime ??= new LlamaCppRuntime($this->endpoint);
        $this->endpoint = rtrim((string)(getenv('EXAMCENTER_LLAMA_ENDPOINT') ?: $this->endpoint), '/');
        $this->model = (string)(getenv('EXAMCENTER_LLAMA_MODEL_ALIAS') ?: $this->model);
    }

    public function grade(string $question, float $max, string $answer): array
    {
        $this->runtime->ensureReady();
        $prompt = $this->prompt($question, $max, $answer);
        $ch = curl_init($this->endpoint . '/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a careful examination marker. Return valid JSON only, without markdown or hidden reasoning.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.1,
                'max_tokens' => 900,
                'stream' => false,
                'response_format' => ['type' => 'json_object']
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Local llama.cpp request failed: ' . $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 400) {
            throw new RuntimeException('llama.cpp returned HTTP ' . $status . ': ' . substr(trim($raw), 0, 700));
        }

        $response = json_decode($raw, true);
        $content = $response['choices'][0]['message']['content'] ?? null;
        if (is_array($content)) {
            $content = implode("\n", array_map(
                static fn($part) => is_array($part) ? (string)($part['text'] ?? '') : (string)$part,
                $content
            ));
        }
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('llama.cpp returned an empty grading response.');
        }
        $data = json_decode(trim($content), true);
        if (!is_array($data)) {
            $clean = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', trim($content));
            $data = json_decode((string)$clean, true);
        }
        if (!is_array($data)) {
            throw new RuntimeException('llama.cpp returned invalid grading JSON.');
        }
        return GradingResult::fromModel($data, $max);
    }

    private function prompt(string $question, float $max, string $answer): string
    {
        $schema = '{"awarded_marks":number,"confidence":number,"relevance_score":number,"correctness_score":number,"completeness_score":number,"understanding_score":number,"explanation":"string","strengths":["string"],"weaknesses":["string"],"missing_points":["string"]}';
        return "Grade this written examination answer using your own reliable knowledge. There is no teacher answer key. Evaluate relevance, factual correctness, completeness and understanding. Give partial credit where justified. Do not penalize different wording. Do not invent facts or claim the student said something they did not say. Never award more than the maximum. Return JSON only.\n\nQUESTION:\n{$question}\n\nMAXIMUM MARKS:\n{$max}\n\nSTUDENT ANSWER:\n{$answer}\n\nRequired JSON shape: {$schema}. Scores except awarded_marks are 0..1.";
    }
}
