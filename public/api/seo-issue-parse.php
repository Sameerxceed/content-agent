<?php
/**
 * Parse pasted SEO alert / email text into issue rows.
 *
 * POST JSON:
 *   { action: "parse",  site_id, text }       — preview only, no DB writes
 *   { action: "save",   site_id, issues: [] } — persist edited list to seo_issues
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/seo_issue_parser.php';
require_once __DIR__ . '/../../includes/seo_issue_fixer.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$action  = $input['action'] ?? '';
$site_id = (int)($input['site_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$site_id, $user_id]);
$site = $stmt->fetch();
if (!$site) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }

try {
    if ($action === 'parse') {
        $text = (string)($input['text'] ?? '');
        $result = seo_issue_parse_alert($text, $site);
        echo json_encode($result);
        exit;
    }

    if ($action === 'save') {
        $issues = $input['issues'] ?? [];
        if (!is_array($issues)) { echo json_encode(['error' => 'issues must be array']); exit; }
        $saved = seo_issue_save_parsed($db, $site_id, $issues);
        echo json_encode(['success' => true, 'saved' => $saved, 'skipped' => count($issues) - $saved]);
        exit;
    }

    if ($action === 'generate_fix') {
        $issue_id = (int)($input['issue_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM seo_issues WHERE id = ? AND site_id = ?');
        $stmt->execute([$issue_id, $site_id]);
        $issue = $stmt->fetch();
        if (!$issue) { http_response_code(404); echo json_encode(['error' => 'Issue not found']); exit; }
        $fix = seo_issue_generate_fix($db, $issue, $site);
        echo json_encode($fix);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('[seo-issue-parse] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
