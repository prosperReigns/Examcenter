<?php

declare(strict_types=1);
final class CodingRunner
{
    private const ALLOWED = ['python', 'javascript', 'php', 'java', 'c', 'cpp'];
    public function run(string $language, string $source, string $input = '', int $timeoutMs = 3000): array
    {
        $language = strtolower(trim($language));
        if (!in_array($language, self::ALLOWED, true)) throw new InvalidArgumentException('Unsupported coding language.');
        $runner = getenv('EXAMCENTER_RUNNER_' . strtoupper($language));
        if (!$runner) return ['status' => 'not_configured', 'message' => 'No local runner configured for this language.'];
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'examcenter-code-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);
        $ext = ['python' => 'py', 'javascript' => 'js', 'php' => 'php', 'java' => 'java', 'c' => 'c', 'cpp' => 'cpp'][$language];
        $file = $dir . DIRECTORY_SEPARATOR . 'Main.' . $ext;
        file_put_contents($file, $source);
        $p = proc_open($runner . ' ' . escapeshellarg($file), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        if (!is_resource($p)) return ['status' => 'error', 'message' => 'Unable to start runner.'];
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $t = microtime(true);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        $ms = (int)round((microtime(true) - $t) * 1000);
        @unlink($file);
        @rmdir($dir);
        return ['status' => $code === 0 ? 'passed' : 'failed', 'exit_code' => $code, 'runtime_ms' => $ms, 'output' => $out, 'error' => $err];
    }
}
