<?php
/**
 * Common utility functions.
 */

/**
 * Extract the first balanced JSON object/array from a text blob that may
 * contain markdown fences, prose preamble, or trailing rationale. Tolerates
 * the common Claude failure modes:
 *   - ```json ... ``` fences (with or without language tag)
 *   - leading "Here's the JSON:" prose
 *   - trailing "**Rationale:**\n1. ..." prose after the JSON
 * Returns the decoded array, or null if no parseable JSON was found.
 */
function extract_json_from_text(string $text): ?array
{
    $text = trim($text);
    if ($text === '') return null;

    // Strip a leading ```json or ``` fence if present
    $text = preg_replace('/^```(?:json|JSON)?\s*\n?/', '', $text);

    // Find the first { or [ — JSON object/array start
    $start_obj = strpos($text, '{');
    $start_arr = strpos($text, '[');
    $start = false;
    if ($start_obj !== false && $start_arr !== false) {
        $start = min($start_obj, $start_arr);
    } else {
        $start = $start_obj !== false ? $start_obj : $start_arr;
    }
    if ($start === false) return null;

    // Walk forward, tracking brace/bracket depth, respecting strings + escapes
    $open  = $text[$start];
    $close = $open === '{' ? '}' : ']';
    $depth = 0;
    $in_string = false;
    $escape = false;
    $end = -1;
    $len = strlen($text);
    for ($i = $start; $i < $len; $i++) {
        $ch = $text[$i];
        if ($escape) { $escape = false; continue; }
        if ($ch === '\\') { $escape = true; continue; }
        if ($ch === '"') { $in_string = !$in_string; continue; }
        if ($in_string) continue;
        if ($ch === $open)  $depth++;
        elseif ($ch === $close) {
            $depth--;
            if ($depth === 0) { $end = $i; break; }
        }
    }
    if ($end < 0) return null;

    $json = substr($text, $start, $end - $start + 1);
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

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
 * ── UI helpers — inline explainers, page intros, section headers ────
 *
 * These give every page a consistent "what is this, what do I do" pattern
 * without each page rolling its own markup. CSS for these classes lives in
 * templates/dashboard/layout.php.
 */

/**
 * Inline tooltip: <span class="tt" data-tip="...">ⓘ</span>
 * Renders a small info icon that shows the explanation on hover/tap.
 */
function tt(string $text): string
{
    return '<span class="tt" tabindex="0" data-tip="' . e($text) . '">&#9432;</span>';
}

/**
 * Page intro hero band. Use at the top of dashboard pages right after the
 * back-link, BEFORE the page content. Tints to one of: accent (Content),
 * primary (SEO/Setup), success (Performance), purple (AI), gray (System).
 *
 * @param string $area One of: content / seo / performance / ai / system
 */
function page_intro(string $icon, string $title, string $description, string $area = 'system', string $cta_html = ''): string
{
    $areas = [
        'content'     => ['#CC3300', '#fff4ef'],
        'seo'         => ['#1B3A6B', '#eef2f9'],
        'performance' => ['#10b981', '#ecfdf5'],
        'ai'          => ['#7c3aed', '#f5f0ff'],
        'system'      => ['#64748b', '#f1f5f9'],
    ];
    [$accent, $bg] = $areas[$area] ?? $areas['system'];

    $cta = $cta_html ? '<div style="margin-top:8px;">' . $cta_html . '</div>' : '';
    return '<div class="page-intro" style="background:' . $bg . '; border-left:3px solid ' . $accent . '; padding:12px 16px; border-radius:6px; margin-bottom:14px;">'
        . '<div style="display:flex; align-items:center; gap:8px; font-weight:600; font-size:15px; color:' . $accent . ';">'
        . '<span>' . $icon . '</span><span>' . e($title) . '</span>'
        . '</div>'
        . '<div style="font-size:12px; color:var(--text-light); margin-top:3px; line-height:1.5;">' . e($description) . '</div>'
        . $cta
        . '</div>';
}

/**
 * Compact section header with optional explainer subtitle.
 * Use INSIDE a card to label a subsection cleanly.
 */
function section_intro(string $title, string $description = '', string $icon = ''): string
{
    $i = $icon ? '<span style="margin-right:6px;">' . $icon . '</span>' : '';
    $d = $description
        ? '<div style="font-size:11px; color:var(--text-light); margin-top:2px;">' . e($description) . '</div>'
        : '';
    return '<div style="margin-bottom:8px;"><div style="font-weight:600; font-size:13px; color:var(--primary);">' . $i . e($title) . '</div>' . $d . '</div>';
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
