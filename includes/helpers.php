<?php
/**
 * Common utility functions.
 */

/**
 * Load app config (cached after first call).
 */
function config(string $key = null, $default = null)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/config.php';
    }
    if ($key === null) return $config;
    return $config[$key] ?? $default;
}

/**
 * Get the base path for URLs.
 */
function base_path(): string
{
    return config('base_path', '');
}

/**
 * Generate a URL with base path prefix.
 */
function url(string $path): string
{
    return base_path() . $path;
}

/**
 * Redirect to a URL and exit.
 */
function redirect(string $url): void
{
    header("Location: " . base_path() . $url);
    exit;
}

/**
 * Escape HTML output.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Generate a URL-safe slug from a string.
 */
function slugify(string $text): string
{
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
    }
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim($text, '-');
    return strtolower($text);
}

/**
 * Generate a CSRF token and store in session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Output a hidden CSRF input field.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

/**
 * Verify CSRF token from POST request.
 */
function csrf_verify(): bool
{
    $token = $_POST['_csrf_token'] ?? '';
    return hash_equals($_SESSION['_csrf_token'] ?? '', $token);
}

/**
 * Return JSON response and exit.
 */
function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Log agent activity to file.
 */
function agent_log(string $message, string $level = 'INFO'): void
{
    $log_path = config('log_path') . '/agent.log';
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents($log_path, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Truncate text to a given length, preserving word boundaries.
 */
function truncate(string $text, int $length = 160, string $suffix = '...'): string
{
    if (mb_strlen($text) <= $length) return $text;
    $truncated = mb_substr($text, 0, $length);
    $last_space = mb_strrpos($truncated, ' ');
    if ($last_space !== false) {
        $truncated = mb_substr($truncated, 0, $last_space);
    }
    return $truncated . $suffix;
}

/**
 * Get current IST datetime string.
 */
function now(): string
{
    return (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
}

/**
 * Format a datetime string for display.
 */
function format_date(string $datetime, string $format = 'd M Y, h:i A'): string
{
    $dt = new DateTime($datetime, new DateTimeZone('Asia/Kolkata'));
    return $dt->format($format);
}

/**
 * Validate that required POST fields are present and non-empty.
 */
function validate_required(array $fields): array
{
    $errors = [];
    foreach ($fields as $field) {
        if (empty(trim($_POST[$field] ?? ''))) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }
    return $errors;
}

/**
 * Simple HTTP GET request using cURL.
 */
function http_get(string $url, array $headers = [], int $timeout = 30): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'ContentAgent/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body'   => $body,
        'error'  => $error,
    ];
}

/**
 * Simple HTTP POST request using cURL.
 */
function http_post(string $url, array $data, array $headers = [], int $timeout = 60): array
{
    $ch = curl_init($url);
    $json = json_encode($data);
    $headers[] = 'Content-Type: application/json';

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'ContentAgent/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body'   => $body,
        'error'  => $error,
    ];
}
