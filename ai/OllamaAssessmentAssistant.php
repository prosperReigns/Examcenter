<?php

declare(strict_types=1);
final class OllamaAssessmentAssistant
{
    public function __construct(private string $model = 'qwen3:1.7b', private string $baseUrl = 'http://127.0.0.1:11434') {}
    public function generateQuestions(string $topic, int $count = 5, string $level = 'medium'): array
    {
        return $this->request("Create {$count} assessment questions about {$topic}. Difficulty: {$level}. Return ONLY JSON array. Keys: question_text,question_type,difficulty,bloom_level,learning_objective,suggested_marks.");
    }
    public function reviewQuestion(string $question): array
    {
        return $this->request("Review this assessment question for clarity, ambiguity, difficulty and bias. Return ONLY JSON object with keys clarity,ambiguity,difficulty,bias,suggestions. Question: " . $question);
    }
    private function request(string $prompt): array
    {
        $payload = json_encode(['model' => $this->model, 'prompt' => $prompt, 'stream' => false, 'format' => 'json']);
        $ch = curl_init($this->baseUrl . '/api/generate');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 120]);
        $raw = curl_exec($ch);
        if ($raw === false) throw new RuntimeException('Offline Ollama request failed: ' . curl_error($ch));
        curl_close($ch);
        $d = json_decode((string)$raw, true);
        $j = json_decode((string)($d['response'] ?? ''), true);
        if (!is_array($j)) throw new RuntimeException('AI returned invalid JSON.');
        return $j;
    }
}
