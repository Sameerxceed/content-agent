<?php
/**
 * GitHub Webhook — Auto-deploy on push.
 * Receives GitHub push events and runs git pull.
 *
 * Setup:
 * 1. Add DEPLOY_SECRET to config.php
 * 2. In GitHub repo → Settings → Webhooks → Add webhook:
 *    - URL: https://contentagent.xceedtech.in/api/deploy-webhook.php
 *    - Content type: application/json
 *    - Secret: (same as DEPLOY_SECRET)
 *    - Events: Just the push event
 */

// Load config for deploy secret
$config_path = __DIR__ . '/../../config/config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    echo 'Config not found';
    exit;
}
$config = require $config_path;

$secret = $config['deploy_secret'] ?? '';
if (empty($secret)) {
    http_response_code(500);
    echo 'Deploy secret not configured';
    exit;
}

// Verify GitHub signature
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (empty($signature)) {
    http_response_code(403);
    echo 'No signature';
    exit;
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo 'Invalid signature';
    exit;
}

// Parse payload
$data = json_decode($payload, true);
$ref = $data['ref'] ?? '';
$branch = str_replace('refs/heads/', '', $ref);

// Only deploy on master branch pushes
if ($branch !== 'master') {
    echo 'Skipped: not master branch';
    exit;
}

// Run git pull
$repo_path = $config['deploy_path'] ?? '/opt/contentagent';
$output = [];
$return_code = 0;

exec("cd {$repo_path} && git pull 2>&1", $output, $return_code);

$log = implode("\n", $output);

// Run pending migrations if git pull succeeded
$migration_summary = ['skipped' => 'git pull failed'];
if ($return_code === 0) {
    try {
        require_once $repo_path . '/database/migrate.php';
        $migration_summary = run_migrations();
        $log .= "\nMigrations: applied=" . count($migration_summary['applied'])
              . " skipped=" . count($migration_summary['skipped'])
              . " errors=" . count($migration_summary['errors']);
        foreach ($migration_summary['applied'] as $m) $log .= "\n  + {$m}";
        foreach ($migration_summary['errors'] as $e) $log .= "\n  ! {$e['file']}: {$e['error']}";
    } catch (Throwable $e) {
        $log .= "\nMigration runner exception: " . $e->getMessage();
        $migration_summary = ['error' => $e->getMessage()];
    }
}

// Log deployment
$log_file = $repo_path . '/storage/deploy.log';
$log_entry = date('Y-m-d H:i:s') . " | Branch: {$branch} | Status: " . ($return_code === 0 ? 'OK' : 'FAIL') . "\n{$log}\n---\n";
file_put_contents($log_file, $log_entry, FILE_APPEND);

// Return result
header('Content-Type: application/json');
echo json_encode([
    'success' => $return_code === 0,
    'branch' => $branch,
    'output' => $log,
    'migrations' => $migration_summary,
]);
