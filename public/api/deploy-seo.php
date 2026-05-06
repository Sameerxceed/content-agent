<?php
/**
 * API — Auto-generate and deploy SEO files to the live website.
 * POST /api/deploy-seo.php
 * Body: { "site_id": 1, "type": "llms|robots|schema|all" }
 *
 * Generates the file content, then pushes it to the site's CMS via deploy-file API.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai-seo.php';
require_once __DIR__ . '/../../includes/schema-generator.php';

auth_start();

if (!auth_check()) {
    json_response(['error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$site_id = (int)($input['site_id'] ?? 0);
$type = $input['type'] ?? '';

if (!$site_id || !$type) {
    json_response(['error' => 'site_id and type required'], 400);
}

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$site_id, $user_id]);
$site = $stmt->fetch();

if (!$site) json_response(['error' => 'Site not found'], 404);

$cms_url = $site['cms_url'];
$cms_api_key = $site['cms_api_key'];

if (empty($cms_url) || empty($cms_api_key)) {
    json_response(['error' => 'CMS not configured. Go to Site Edit → add CMS URL and API Key.'], 400);
}

$deploy_url = rtrim($cms_url, '/') . '/api/deploy-file.php';
$deployed = [];
$errors = [];

// ── Generate and deploy based on type ────────────────────

if ($type === 'llms' || $type === 'all') {
    $llms = generate_llms_txt($site, $db);
    $result = deploy_file($deploy_url, $cms_api_key, 'llms.txt', $llms);
    if ($result['success']) {
        $deployed[] = 'llms.txt';
    } else {
        $errors[] = 'llms.txt: ' . ($result['error'] ?? 'unknown');
    }

    $llms_full = generate_llms_full_txt($site, $db);
    $result = deploy_file($deploy_url, $cms_api_key, 'llms-full.txt', $llms_full);
    if ($result['success']) {
        $deployed[] = 'llms-full.txt';
    } else {
        $errors[] = 'llms-full.txt: ' . ($result['error'] ?? 'unknown');
    }
}

if ($type === 'robots' || $type === 'all') {
    $robots = generate_ai_robots_txt($site['domain'], true);
    $result = deploy_file($deploy_url, $cms_api_key, 'robots.txt', $robots);
    if ($result['success']) {
        $deployed[] = 'robots.txt';
    } else {
        $errors[] = 'robots.txt: ' . ($result['error'] ?? 'unknown');
    }
}

if ($type === 'schema' || $type === 'all') {
    $schemas = [
        'schema-organization.json' => schema_organization($site),
        'schema-website.json'      => schema_website($site),
    ];

    foreach ($schemas as $filename => $content) {
        $result = deploy_file($deploy_url, $cms_api_key, $filename, $content);
        if ($result['success']) {
            $deployed[] = $filename;
        } else {
            $errors[] = $filename . ': ' . ($result['error'] ?? 'unknown');
        }
    }
}

// Log
$db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
    $site_id,
    'deploy_seo',
    json_encode(['type' => $type, 'deployed' => $deployed, 'errors' => $errors]),
    empty($errors) ? 'success' : 'fail',
]);

if (!empty($deployed)) {
    json_response([
        'success'  => true,
        'deployed' => $deployed,
        'errors'   => $errors,
        'message'  => 'Deployed: ' . implode(', ', $deployed),
    ]);
} else {
    $error_detail = !empty($errors) ? implode('; ', $errors) : 'Unknown error';
    $message = 'Deploy failed: ' . $error_detail;

    // Add helpful context
    if (strpos($error_detail, '404') !== false) {
        $message .= '. The deploy endpoint was not found at ' . $deploy_url . ' — check if the CMS API is set up correctly.';
    } elseif (strpos($error_detail, 'Could not resolve') !== false || strpos($error_detail, 'connect') !== false) {
        $message .= '. Cannot reach the CMS server — check if the CMS URL is correct and the server is online.';
    } elseif (strpos($error_detail, '401') !== false || strpos($error_detail, '403') !== false) {
        $message .= '. Authentication failed — check if the API key is correct in Site Settings.';
    }

    json_response([
        'success' => false,
        'errors'  => $errors,
        'message' => $message,
    ], 500);
}

// ── Helper ──────────────────────────────────────────────

function deploy_file(string $deploy_url, string $api_key, string $filename, string $content): array
{
    $payload = [
        'filename' => $filename,
        'content'  => $content,
    ];

    $ch = curl_init($deploy_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $api_key,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['success' => false, 'error' => $error];

    $data = json_decode($body, true);

    if ($status === 200 && !empty($data['success'])) {
        return ['success' => true];
    }

    return ['success' => false, 'error' => $data['error'] ?? "HTTP {$status}"];
}
