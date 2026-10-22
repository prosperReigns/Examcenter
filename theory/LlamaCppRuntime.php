<?php
declare(strict_types=1);

/**
 * Starts and verifies the local llama.cpp HTTP server only when theory marking
 * is requested. Core objective CBT never depends on this class.
 */
final class LlamaCppRuntime
{
    private string $endpoint;
    private string $alias;
    private int $startupTimeout;
    private bool $ready = false;

    public function __construct(
        ?string $endpoint = null,
        ?string $alias = null,
        int $startupTimeout = 120
    ) {
        $this->endpoint = rtrim($endpoint ?: (getenv('EXAMCENTER_LLAMA_ENDPOINT') ?: 'http://127.0.0.1:11435'), '/');
        $this->alias = $alias ?: (getenv('EXAMCENTER_LLAMA_MODEL_ALIAS') ?: 'qwen3-1.7b');
        $this->startupTimeout = max(5, $startupTimeout);
    }

    /**
     * Ensure a healthy local server is serving Qwen 3 1.7B.
     * No model downloads are initiated: only a local GGUF file is accepted.
     */
    public function ensureReady(): void
    {
        if ($this->ready) {
            return;
        }
        if ($this->isReady()) {
            $this->ready = true;
            return;
        }

        $server = $this->findServerBinary();
        if ($server === null) {
            throw new RuntimeException(
                'Local AI is not running and llama-server could not be found. Install/package the llama.cpp server binary, ' .
                'or set EXAMCENTER_LLAMA_SERVER to its full path. Objective exams can still be submitted; AI theory grading can be retried later.'
            );
        }

        $model = $this->findModelFile();
        if ($model === null) {
            throw new RuntimeException(
                'Qwen 3 1.7B GGUF was not found in the local llama.cpp cache. Set EXAMCENTER_LLAMA_MODEL_PATH to the existing local .gguf file. ' .
                'Examcenter will not download models during an exam.'
            );
        }

        $port = (int)(parse_url($this->endpoint, PHP_URL_PORT) ?: 11435);
        $logFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'examcenter-llama-server.log';
        $args = [
            $server, '-m', $model, '--alias', $this->alias,
            '--host', '127.0.0.1', '--port', (string)$port,
            '-c', (string)(getenv('EXAMCENTER_LLAMA_CONTEXT_SIZE') ?: 4096)
        ];
        $this->launchDetached($args, $logFile);

        $deadline = microtime(true) + $this->startupTimeout;
        do {
            usleep(500000);
            if ($this->isReady()) {
                $this->ready = true;
                return;
            }
        } while (microtime(true) < $deadline);

        $logHint = is_file($logFile) ? ' Server log: ' . $logFile : '';
        throw new RuntimeException(
            'llama.cpp did not become ready within ' . $this->startupTimeout .
            ' seconds. Check the llama-server binary, model file and port ' . $port . '.' . $logHint
        );
    }

    private function isReady(): bool
    {
        $health = $this->request('/health', 2);
        if ($health['status'] !== 200) {
            return false;
        }

        $models = $this->request('/v1/models', 3);
        if ($models['status'] !== 200) {
            return false;
        }

        $payload = json_decode($models['body'], true);
        $items = $payload['data'] ?? [];
        if (!is_array($items) || $items === []) {
            return false;
        }

        foreach ($items as $item) {
            $id = strtolower((string)($item['id'] ?? ''));
            if (str_contains($id, 'qwen') && (str_contains($id, '1.7b') || str_contains($id, '1_7b'))) {
                return true;
            }
        }
        return false;
    }

    /** @return array{status:int,body:string} */
    private function request(string $path, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for local AI health checks.');
        }
        $ch = curl_init($this->endpoint . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPGET => true
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $body === false ? 0 : $status, 'body' => is_string($body) ? $body : ''];
    }

    private function findServerBinary(): ?string
    {
        $configured = trim((string)(getenv('EXAMCENTER_LLAMA_SERVER') ?: ''));
        $candidates = array_filter([
            $configured,
            __DIR__ . '/../runtime/llama.cpp/llama-server.exe',
            __DIR__ . '/../runtime/llama.cpp/llama-server',
            __DIR__ . '/../bin/llama-server.exe',
            __DIR__ . '/../bin/llama-server'
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        $commands = PHP_OS_FAMILY === 'Windows'
            ? ['where.exe llama-server.exe 2>NUL', 'where.exe llama-server 2>NUL', 'where.exe llama-cli.exe 2>NUL']
            : ['command -v llama-server 2>/dev/null', 'command -v llama-cli 2>/dev/null'];
        foreach ($commands as $command) {
            $found = [];
            @exec($command, $found);
            foreach ($found as $line) {
                $line = trim($line);
                if ($line === '' || !is_file($line)) {
                    continue;
                }
                if (stripos(basename($line), 'llama-cli') === 0) {
                    $sibling = dirname($line) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'llama-server.exe' : 'llama-server');
                    if (is_file($sibling)) {
                        return realpath($sibling) ?: $sibling;
                    }
                    continue;
                }
                return realpath($line) ?: $line;
            }
        }
        return null;
    }

    private function findModelFile(): ?string
    {
        $configured = trim((string)(getenv('EXAMCENTER_LLAMA_MODEL_PATH') ?: ''));
        if ($configured !== '' && is_file($configured) && strtolower(pathinfo($configured, PATHINFO_EXTENSION)) === 'gguf') {
            return realpath($configured) ?: $configured;
        }

        $home = (string)(getenv('USERPROFILE') ?: getenv('HOME') ?: '');
        $localAppData = (string)(getenv('LOCALAPPDATA') ?: '');
        $roots = array_filter([
            getenv('LLAMA_CACHE') ?: null,
            $home !== '' ? $home . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'llama.cpp' : null,
            $home !== '' ? $home . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'huggingface' . DIRECTORY_SEPARATOR . 'hub' : null,
            $localAppData !== '' ? $localAppData . DIRECTORY_SEPARATOR . 'llama.cpp' : null
        ]);

        foreach (array_unique($roots) as $root) {
            if (!is_dir($root)) {
                continue;
            }
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if (!$file->isFile() || strtolower($file->getExtension()) !== 'gguf') {
                        continue;
                    }
                    $path = $file->getPathname();
                    $lower = strtolower(str_replace('\\', '/', $path));
                    if (str_contains($lower, 'qwen3') &&
                        (str_contains($lower, '1.7b') || str_contains($lower, '1_7b')) &&
                        str_contains($lower, 'q4_k_m')) {
                        return $file->getRealPath() ?: $path;
                    }
                }
            } catch (Throwable $e) {
                // Ignore inaccessible cache folders; a configured model path remains available.
            }
        }
        return null;
    }

    /** @param list<string> $args */
    private function launchDetached(array $args, string $logFile): void
    {
        $quoted = array_map(static function (string $arg): string {
            return escapeshellarg($arg);
        }, $args);

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start "" /B ' . implode(' ', $quoted) . ' > ' . escapeshellarg($logFile) . ' 2>&1';
        } else {
            $command = 'nohup ' . implode(' ', $quoted) . ' > ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null &';
        }
        @exec($command);
    }
}
