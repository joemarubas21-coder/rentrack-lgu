<?php
/**
 * Simple environment loader.
 * Reads a .env file (kept OUT of git) located in the project root,
 * then falls back to real environment variables (Railway, Docker, etc).
 */
if (!function_exists('env_value')) {
    function env_value(string $key, $default = null)
    {
        static $loaded = false;
        static $file = [];

        if (!$loaded) {
            $loaded = true;
            $path = dirname(__DIR__) . '/.env';
            if (is_readable($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    $file[trim($k)] = trim(trim($v), "\"'");
                }
            }
        }

        $real = getenv($key);
        if ($real !== false && $real !== '') {
            return $real;
        }
        return $file[$key] ?? $default;
    }
}

if (!function_exists('site_base_url')) {
    /** Fixed base URL from config (prevents Host header injection in emailed links). */
    function site_base_url(): string
    {
        $base = env_value('SITE_BASE_URL');
        if ($base) {
            return rtrim($base, '/');
        }
        // Dev fallback only (localhost)
        return 'http://localhost/rentalsystem';
    }
}

if (!function_exists('safe_error')) {
    /** Logs the real exception server-side and returns a generic message for users. */
    function safe_error($e): string
    {
        error_log('[app error] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        return 'Please try again later.';
    }
}
require_once __DIR__ . '/upload.php';
