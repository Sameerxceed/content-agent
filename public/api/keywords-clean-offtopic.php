<?php
/**
 * Run the Claude relevance filter against ALL currently-active GSC-imported
 * keywords for a site and auto-ignore the ones that aren't relevant to this
 * business. Used to clean up existing pollution from before the auto-filter
 * on sync existed, or any drift that snuck through.
 *
 * POST JSON: { site_id }
 * Returns:   { success, scanned, ignored }
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/keyword_intelligence.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

function kco_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) {
        $stray = ob_get_clean();
        if (trim($stray) !== '') error_log('[keywords-clean-offtopic] stray output: ' . substr($stray, 0, 500));
    }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);
if (!$site_id) kco_respond(['error' => 'site_id required'], 400);
if (!auth_can_access_site($db, $site_id)) kco_respond(['error' => 'Site not found'], 404);

try {
    // Pull every currently-active GSC-sourced keyword for this site. Don't
    // touch manual entries — those are deliberate by the user.
    $stmt = $db->prepare("SELECT keyword FROM keywords WHERE site_id = ? AND status = 'active' AND source = 'gsc'");
    $stmt->execute([$site_id]);
    $keywords = array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), fn($k) => trim((string)$k) !== ''));

    $ignored = 0;
    if (!empty($keywords)) {
        $ignored = keywords_auto_ignore_offtopic($db, $site_id, $keywords);
    }

    kco_respond([
        'success' => true,
        'scanned' => count($keywords),
        'ignored' => $ignored,
    ]);
} catch (Throwable $e) {
    error_log('[keywords-clean-offtopic] ' . $e->getMessage());
    kco_respond(['error' => $e->getMessage()], 500);
}
