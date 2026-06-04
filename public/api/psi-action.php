<?php
/**
 * PSI / Core Web Vitals actions.
 *
 * POST JSON: { action, site_id, ... }
 *
 *   run_baseline  — launch baseline run (backgrounded)
 *   add_url       — add a custom URL to the baseline set
 *   remove_url    — remove a URL
 *   list          — return latest snapshot per URL + site summary
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/psi_runner.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

function psi_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');
$site_id = (int)($input['site_id'] ?? 0);
$site = auth_get_accessible_site($db, $site_id);
if (!$site) psi_respond(['error' => 'Site not found'], 404);

try {
    if ($action === 'run_baseline') {
        $php    = config('php_path') ?: '/usr/bin/php8.3';
        $script = realpath(__DIR__ . '/../../agent/cron-psi-baseline.php');
        if (!$script) psi_respond(['error' => 'CLI script not found'], 500);
        $log = (config('log_path') ?: '/var/log/contentagent') . '/psi.log';
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf('start /B "" "%s" "%s" --site=%d', $php, $script, $site_id);
            pclose(popen($cmd, 'r'));
        } else {
            // setsid detaches from PHP-FPM process group so the job survives reaping.
            $cmd = sprintf('setsid %s %s --site=%d </dev/null >> %s 2>&1 &',
                escapeshellarg($php), escapeshellarg($script), $site_id, escapeshellarg($log));
            exec($cmd);
        }
        psi_respond(['success' => true, 'launched' => true]);
    }

    if ($action === 'add_url') {
        $url = trim((string)($input['url'] ?? ''));
        $label = trim((string)($input['label'] ?? 'Custom'));
        if ($url === '' || !preg_match('#^https?://#i', $url)) psi_respond(['error' => 'valid url required'], 400);
        $db->prepare("INSERT INTO cwv_baseline_urls (site_id, url, label) VALUES (?, ?, ?)
                      ON DUPLICATE KEY UPDATE label = VALUES(label)")
           ->execute([$site_id, mb_substr($url, 0, 2048), mb_substr($label, 0, 120)]);
        psi_respond(['success' => true]);
    }

    if ($action === 'remove_url') {
        $url = trim((string)($input['url'] ?? ''));
        $db->prepare("DELETE FROM cwv_baseline_urls WHERE site_id = ? AND url = ?")
           ->execute([$site_id, $url]);
        psi_respond(['success' => true]);
    }

    if ($action === 'list') {
        psi_respond([
            'summary' => psi_site_summary($db, $site_id),
            'latest'  => psi_latest_per_url($db, $site_id),
        ]);
    }

    psi_respond(['error' => 'Unknown action: ' . $action], 400);
} catch (Throwable $e) {
    error_log('[psi-action] ' . $e->getMessage());
    psi_respond(['error' => $e->getMessage()], 500);
}
