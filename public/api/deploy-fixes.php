<?php
/**
 * Deploy Fix Files API
 * Deploys generated fix files to the user's server via FTP/SFTP.
 *
 * POST {site_id, type: 'header'|'sitemap'|'robots'|'all'}
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/fix-generator.php';
require_once __DIR__ . '/../../includes/server-deploy.php';

auth_start();

if (!auth_check()) {
    json_response(['error' => 'Unauthorized'], 401);
}

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true);
$site_id = (int)($input['site_id'] ?? 0);
$type = $input['type'] ?? '';

if (!$site_id || !$type) {
    json_response(['error' => 'site_id and type required'], 400);
}

// Verify site ownership
$stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$site_id, $user_id]);
$site = $stmt->fetch();

if (!$site) {
    json_response(['error' => 'Site not found'], 404);
}

// Check if FTP/SFTP credentials exist
if (empty($site['server_host'])) {
    json_response(['error' => 'No FTP/SFTP credentials configured. Go to Edit Settings to add them.'], 400);
}

// Generate fix files
if ($type === 'all') {
    $fixes = fix_generate_all($site, $db);
} elseif ($type === 'header') {
    $fixes = [fix_generate_header_snippet($site, $db)];
} elseif ($type === 'sitemap') {
    $fixes = [fix_generate_sitemap($site, $db)];
} elseif ($type === 'robots') {
    $fixes = [fix_generate_robots($site)];
} else {
    json_response(['error' => 'Invalid type'], 400);
}

// Deploy each file
$results = [];
$success_count = 0;
$fail_count = 0;

foreach ($fixes as $fix) {
    $deploy_path = $fix['path'];

    // Prepend server_path if configured
    if (!empty($site['server_path'])) {
        $deploy_path = rtrim($site['server_path'], '/') . '/' . ltrim($deploy_path, '/');
    }

    $result = server_deploy_file($site, $deploy_path, $fix['content']);

    if ($result['success']) {
        $success_count++;
        $results[] = ['file' => $fix['filename'], 'path' => $deploy_path, 'status' => 'deployed'];
    } else {
        $fail_count++;
        $results[] = ['file' => $fix['filename'], 'path' => $deploy_path, 'status' => 'failed', 'error' => $result['error'] ?? 'Unknown error'];
    }
}

// Log the deployment
$db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
    $site_id,
    'deploy_fixes',
    json_encode(['type' => $type, 'deployed' => $success_count, 'failed' => $fail_count, 'files' => $results]),
    $fail_count === 0 ? 'success' : 'partial',
]);

json_response([
    'success' => $fail_count === 0,
    'deployed' => $success_count,
    'failed' => $fail_count,
    'files' => $results,
    'path' => $fixes[0]['path'] ?? '',
]);
