<?php
/**
 * GMC API endpoint — set merchant_id + run sync.
 *
 * Forms POST as urlencoded (set_merchant_id), AJAX POSTs as JSON (audit).
 * Both handled.
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gmc_api.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

function gmc_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';

// Form POST (set_merchant_id) vs JSON (audit)
$raw = file_get_contents('php://input');
$input = (str_starts_with(trim($raw), '{') ? (json_decode($raw, true) ?: []) : $_POST);

$action  = (string)($input['action']  ?? '');
$site_id = (int)   ($input['site_id'] ?? 0);

$site = auth_get_accessible_site($db, $site_id);
if (!$site) gmc_respond(['error' => 'Site not found'], 404);

try {
    if ($action === 'set_merchant_id') {
        $mid = preg_replace('/[^0-9]/', '', (string)($input['merchant_id'] ?? ''));
        if ($mid === '') gmc_respond(['error' => 'merchant_id must be numeric'], 400);

        $notes = json_decode($site['notes'] ?? '{}', true) ?: [];
        $notes['gmc_merchant_id'] = $mid;
        $db->prepare("UPDATE sites SET notes = ? WHERE id = ?")
           ->execute([json_encode($notes), $site_id]);

        // Kick off the first sync in background so the form submit doesn't hang.
        @set_time_limit(180);
        $r = gmc_audit_site($db, $site_id, $mid);
        // Form submitted from the setup card — bounce back to the page.
        if (!isset($_SERVER['CONTENT_TYPE']) || !str_contains((string)$_SERVER['CONTENT_TYPE'], 'application/json')) {
            header('Location: ' . url('/dashboard/gmc.php?site=' . $site_id));
            exit;
        }
        gmc_respond(['success' => true, 'merchant_id' => $mid] + $r);
    }

    if ($action === 'audit') {
        $notes = json_decode($site['notes'] ?? '{}', true) ?: [];
        $mid = (string)($notes['gmc_merchant_id'] ?? '');
        if ($mid === '') gmc_respond(['error' => 'No merchant_id set on this site yet'], 400);
        @set_time_limit(300);
        $r = gmc_audit_site($db, $site_id, $mid);
        gmc_respond($r);
    }

    if ($action === 'suggest_fix') {
        // Claude proposes corrections for ONE product's unresolved issues.
        $notes = json_decode($site['notes'] ?? '{}', true) ?: [];
        $mid = (string)($notes['gmc_merchant_id'] ?? '');
        $product_id = (string)($input['product_id'] ?? '');
        if ($mid === '' || $product_id === '') gmc_respond(['error' => 'merchant_id and product_id required'], 400);
        $r = gmc_generate_fix($db, $site_id, $mid, $product_id);
        gmc_respond($r);
    }

    gmc_respond(['error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('[gmc-action] ' . $e->getMessage());
    gmc_respond(['error' => $e->getMessage()], 500);
}
