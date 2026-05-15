<?php
/**
 * API — Run agent scripts from the dashboard.
 * POST /api/run-agent.php
 * Body: { "agent": "scanner|seo-auditor|keyword-research|blog-writer|news-scraper|evaluator", "site_id": 1, "params": {} }
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

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
$agent   = $input['agent'] ?? '';
$site_id = (int)($input['site_id'] ?? 0);
$params  = $input['params'] ?? [];

// Validate agent name
$valid_agents = [
    'scanner'          => 'scanner.php',
    'seo-auditor'      => 'seo-auditor.php',
    'keyword-research'  => 'keyword-research.php',
    'blog-writer'       => 'blog-writer.php',
    'content-planner'   => 'content-planner.php',
    'news-scraper'      => 'news-scraper.php',
    'auto-fixer'        => 'auto-fixer.php',
    'evaluator'         => 'evaluator.php',
    'newsletter'        => 'newsletter.php',
    'carousel-generator' => 'carousel-generator.php',
];

if (!isset($valid_agents[$agent])) {
    json_response(['error' => 'Invalid agent. Use: ' . implode(', ', array_keys($valid_agents))], 400);
}

// Verify site ownership
if ($site_id) {
    if (!auth_can_access_site($db, $site_id)) {
        json_response(['error' => 'Site not found'], 404);
    }
}

// Build CLI command
$php = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
if (!file_exists($php)) {
    $php = 'php';
}

$script = realpath(__DIR__ . '/../../agent/' . $valid_agents[$agent]);
if (!$script) {
    json_response(['error' => 'Agent script not found'], 500);
}

$cmd = '"' . $php . '" "' . $script . '"';

// Carousel uses --post instead of --site
if ($agent === 'carousel-generator' && !empty($params['post_id'])) {
    $cmd .= ' --post=' . (int)$params['post_id'];
} elseif ($agent === 'content-planner') {
    $cmd .= ' --site=' . $site_id . ' --auto-write --auto-publish';
} elseif ($site_id) {
    $cmd .= ' --site=' . $site_id;
}

// Extra params
if (!empty($params['seed'])) {
    $cmd .= ' --seed=' . escapeshellarg($params['seed']);
}
if (!empty($params['count'])) {
    $cmd .= ' --count=' . (int)$params['count'];
}
if (!empty($params['max-pages'])) {
    $cmd .= ' --max-pages=' . (int)$params['max-pages'];
}
if (!empty($params['topic'])) {
    $cmd .= ' --topic=' . escapeshellarg($params['topic']);
}

// Run in background on Windows
$log_file = realpath(__DIR__ . '/../../logs') . '/agent-run-' . date('Ymd-His') . '.log';
$cmd .= ' > "' . $log_file . '" 2>&1';

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    pclose(popen("start /B cmd /C \"$cmd\"", 'r'));
} else {
    exec("$cmd &");
}

json_response([
    'success' => true,
    'message' => "Agent '{$agent}' started for site #{$site_id}",
    'agent'   => $agent,
    'log'     => basename($log_file),
]);
