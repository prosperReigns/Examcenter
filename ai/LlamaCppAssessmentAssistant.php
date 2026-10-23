<?php
declare(strict_types=1);

require_once __DIR__ . '/../theory/LlamaCppRuntime.php';

final class LlamaCppAssessmentAssistant
{
    private LlamaCppRuntime $runtime;
    private string $endpoint;
    private string $model;

    public function __construct(?string $model = null, ?string $endpoint = null)
    {
        $this->endpoint = rtrim($endpoint ?: (getenv('EXAMCENTER_LLAMA_ENDPOINT') ?: 'http://127.0.0.1:11435'), '/');
        $this->model = $model ?: (getenv('EXAMCENTER_LLAMA_MODEL_ALIAS') ?: 'qwen3-1.7b');
        $this->runtime = new LlamaCppRuntime($this->endpoint);
    }

    public function generateQuestions(string $topic, int $count = 5, string $level = 'medium'): array
    {
        $prompt = "Create {$count} assessment questions about {$topic}. Difficulty: {$level}. Return ONLY a JSON array. Each item must have keys question_text, question_type, difficulty, bloom_level, learning_objective, suggested_marks.";
        $result = $this->request($prompt);
        if (!array_is_list($result)) {
            throw new RuntimeException('AI question generation did not return a JSON array.');
        }
        return $result;
    }

    public function reviewQuestion(string $question): array
    {
        return $this->request("Review this assessment question for clarity, ambiguity, difficulty and bias. Return ONLY a JSON object with keys clarity, ambiguity, difficulty, bias, suggestions. Question: " . $question);
    }

    private function request(string $prompt): array
    {
        $this->runtime->ensureReady();
        $ch = curl_init($this->endpoint . '/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an assessment authoring assistant. Return only the JSON requested by the user, with no markdown.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.2,
                'max_tokens' => 1800,
                'stream' => false
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 180
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
        $content = $response['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('AI returned an empty response.');
        }
        $content = trim($content);
        $content = (string)preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', $content);
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI returned invalid JSON.');
        }
        return $decoded;
    }
}
