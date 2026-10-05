<?php
declare(strict_types=1);
require_once __DIR__.'/GradingResult.php';
final class OllamaTheoryGrader {
    public function __construct(private string $endpoint='http://127.0.0.1:11434',private string $model='qwen1.7b',private int $timeout=300){}
    public function grade(string $question,float $max,string $answer):array{
        $prompt=$this->prompt($question,$max,$answer);
        $ch=curl_init(rtrim($this->endpoint,'/').'/api/generate');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$this->timeout,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['model'=>$this->model,'prompt'=>$prompt,'stream'=>false,'format'=>'json','options'=>['temperature'=>0.1]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $raw=curl_exec($ch); if($raw===false){$e=curl_error($ch);curl_close($ch);throw new RuntimeException('Local Ollama request failed: '.$e);}
        $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch); if($status>=400)throw new RuntimeException('Ollama returned HTTP '.$status);
        $response=json_decode($raw,true); $data=json_decode((string)($response['response']??''),true);
        if(!is_array($data))throw new RuntimeException('Ollama returned invalid grading JSON.');
        return GradingResult::fromModel($data,$max);
    }
    private function prompt(string $q,float $max,string $a):string{
        return "Grade this written examination answer using your own reliable knowledge. There is no teacher answer key. Evaluate relevance, factual correctness, completeness and understanding. Give partial credit where justified. Do not penalize different wording. Do not invent facts or claim the student said something they did not say. Never award more than the maximum.\n\nQUESTION:\n{$q}\n\nMAXIMUM MARKS:\n{$max}\n\nSTUDENT ANSWER:\n{$a}\n\nReturn JSON only: {"awarded_marks":number,"confidence":number,"relevance_score":number,"correctness_score":number,"completeness_score":number,"understanding_score":number,"explanation":"string","strengths":["string"],"weaknesses":["string"],"missing_points":["string"]}. Scores except awarded_marks are 0..1.";
    }
}