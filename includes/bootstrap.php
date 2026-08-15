function app_url(string $path = ''): string
{
    $config = require __DIR__ . '/../config/app.php';

    $base = rtrim($config['base_url'] ?? '', '/');
    $path = '/' . ltrim($path, '/');

    return $base . $path;
}