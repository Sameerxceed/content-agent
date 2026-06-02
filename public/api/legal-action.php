<?php
/**
 * Legal docs actions API.
 *
 * POST JSON: { action, site_id, doc_type }
 *   - action='generate'           : run Claude, draft saved on the row
 *   - action='publish'            : push the drafted doc to the customer's CMS
 *   - action='generate_and_publish': both, in sequence
 *   - action='discard'            : wipe the draft, return row to 'missing'
 */
// Buffer ALL output that happens during requires/auth so a stray PHP notice
// or whitespace can't corrupt the session-cookie header and bounce the
// caller to 401. Same defensive pattern as content-plan-start.php.
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/legal_docs.php';

auth_start();
if (!auth_check()) {
    http_response_code(401);
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$action   = (string)($input['action']   ?? '');
$site_id  = (int)   ($input['site_id']  ?? 0);
$doc_type = (string)($input['doc_type'] ?? '');

if (!$site_id || !$doc_type) {
    http_response_code(400);
    echo json_encode(['error' => 'site_id and doc_type required']);
    exit;
}
if (!auth_can_access_site($db, $site_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'No access to this site']);
    exit;
}
if (!in_array($doc_type, ['privacy','terms','cookies','refund','disclaimer'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'unknown doc_type']);
    exit;
}

try {
    switch ($action) {
        case 'generate':
            $r = legal_docs_generate($db, $site_id, $doc_type);
            echo json_encode($r);
            break;

        case 'publish':
            $r = legal_docs_publish($db, $site_id, $doc_type, $user_id);
            echo json_encode($r);
            break;

        case 'generate_and_publish':
            $g = legal_docs_generate($db, $site_id, $doc_type);
            if (empty($g['success'])) { echo json_encode($g); break; }
            $p = legal_docs_publish($db, $site_id, $doc_type, $user_id);
            echo json_encode(array_merge($g, $p));
            break;

        case 'discard':
            $db->prepare("UPDATE legal_docs SET status='missing', title=NULL, body_html=NULL, slug=NULL, generated_at=NULL WHERE site_id=? AND doc_type=?")
               ->execute([$site_id, $doc_type]);
            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
