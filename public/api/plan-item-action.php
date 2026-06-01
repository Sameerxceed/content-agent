<?php
/**
 * Plan item actions — approve / regenerate / draft_now / skip / regenerate_image.
 *
 * POST JSON: { action, item_id?, post_id?, provider? }
 *
 * Actions:
 *   - approve         : item must be lock_state='drafted'. Sets posts.status='approved',
 *                       all post_channels.status='queued', item.lock_state stays 'drafted'
 *                       (cron-publish will flip to 'published' on scheduled_for).
 *   - regenerate      : kills the current draft post (status='rejected'), unlocks the
 *                       item to 'pipeline', fires the autopilot CLI for this single item.
 *   - draft_now       : immediately runs the autopilot for one pipeline item (skip the
 *                       5-day wait). Returns immediately; CLI runs in background.
 *   - skip            : removes the item from the pipeline (cleanest: hard-delete).
 *                       Keyword stays in cluster pool, future review can re-schedule.
 *   - regenerate_image: generate a new hero image via the chosen provider for the
 *                       linked post. Returns the new URL.
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

function pia_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) {
        $stray = ob_get_clean();
        if (trim($stray) !== '') error_log('[plan-item-action] stray: ' . substr($stray, 0, 500));
    }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');

if ($action === '') pia_respond(['error' => 'action required'], 400);

// Resolve item + ensure access
$item_id = (int)($input['item_id'] ?? 0);
$item = null;
if ($item_id) {
    $stmt = $db->prepare("SELECT i.*, p.site_id AS plan_site FROM content_plan_items i JOIN content_plans p ON i.plan_id = p.id WHERE i.id = ?");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch();
    if (!$item) pia_respond(['error' => 'Item not found'], 404);
    if (!auth_can_access_site($db, (int)$item['site_id'])) pia_respond(['error' => 'Access denied'], 403);
}

switch ($action) {

    // ───────── APPROVE ─────────
    case 'approve':
        if (!$item) pia_respond(['error' => 'item_id required'], 400);
        if ($item['lock_state'] !== 'drafted') pia_respond(['error' => 'Item not in drafted state'], 400);
        if (!$item['post_id']) pia_respond(['error' => 'No linked post'], 400);
        $db->prepare("UPDATE posts SET status='approved' WHERE id=?")->execute([(int)$item['post_id']]);
        $db->prepare("UPDATE post_channels SET status='queued' WHERE post_id=? AND status IN ('draft','rejected')")
           ->execute([(int)$item['post_id']]);
        pia_respond(['success' => true, 'scheduled_for' => $item['target_publish_date']]);

    // ───────── REGENERATE (throw current draft away + redraft) ─────────
    case 'regenerate':
        if (!$item) pia_respond(['error' => 'item_id required'], 400);
        if ($item['post_id']) {
            $db->prepare("UPDATE posts SET status='rejected' WHERE id=?")->execute([(int)$item['post_id']]);
        }
        $db->prepare("UPDATE content_plan_items SET lock_state='pipeline', post_id=NULL, drafted_at=NULL WHERE id=?")
           ->execute([$item_id]);
        _pia_fire_autopilot_for_item($item_id);
        pia_respond(['success' => true]);

    // ───────── DRAFT NOW (early autopilot trigger for one item) ─────────
    case 'draft_now':
        if (!$item) pia_respond(['error' => 'item_id required'], 400);
        if ($item['lock_state'] !== 'pipeline') pia_respond(['error' => 'Item already drafted or in flight'], 400);
        _pia_fire_autopilot_for_item($item_id);
        pia_respond(['success' => true]);

    // ───────── SKIP ─────────
    case 'skip':
        if (!$item) pia_respond(['error' => 'item_id required'], 400);
        if ($item['post_id']) {
            $db->prepare("UPDATE posts SET status='rejected' WHERE id=?")->execute([(int)$item['post_id']]);
        }
        // Unschedule the keyword in the cluster pool so future reviews can re-pick it
        $db->prepare("UPDATE content_plan_cluster_keywords SET is_scheduled=0, scheduled_item_id=NULL WHERE scheduled_item_id=?")
           ->execute([$item_id]);
        $db->prepare("DELETE FROM content_plan_items WHERE id=?")->execute([$item_id]);
        pia_respond(['success' => true]);

    // ───────── REGENERATE HERO IMAGE ─────────
    case 'regenerate_image':
        require_once __DIR__ . '/../../includes/image_gen.php';
        $post_id  = (int)($input['post_id'] ?? 0);
        $provider = (string)($input['provider'] ?? 'dalle3');
        if (!$post_id) pia_respond(['error' => 'post_id required'], 400);
        // Permission check by joining posts → sites
        $stmt = $db->prepare("SELECT p.site_id, p.title, p.hero_image_prompt FROM posts p WHERE p.id = ?");
        $stmt->execute([$post_id]);
        $row = $stmt->fetch();
        if (!$row) pia_respond(['error' => 'Post not found'], 404);
        if (!auth_can_access_site($db, (int)$row['site_id'])) pia_respond(['error' => 'Access denied'], 403);
        $prompt = trim((string)($row['hero_image_prompt'] ?? ''));
        if ($prompt === '') $prompt = (string)$row['title'];
        // Temporarily blank the providers we don't want so the function only tries the chosen one
        $oai_key      = config('openai_api_key');
        $unsplash_key = config('unsplash_access_key');
        if ($provider !== 'dalle3'   && !empty($oai_key))      $GLOBALS['_pia_saved_oai']      = $oai_key;
        if ($provider !== 'unsplash' && !empty($unsplash_key)) $GLOBALS['_pia_saved_unsplash'] = $unsplash_key;
        // We need a simpler way to force provider — just call the internal helpers directly
        $img = _pia_force_image_provider($db, $post_id, $prompt, (string)$row['title'], $provider);
        if (!$img) pia_respond(['error' => 'Image generation failed — check ' . $provider . ' credentials in Settings.'], 500);
        pia_respond(['success' => true, 'url' => $img['url'], 'provider' => $img['provider']]);

    default:
        pia_respond(['error' => 'Unknown action: ' . $action], 400);
}

// ─────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────

function _pia_fire_autopilot_for_item(int $item_id): void
{
    $php = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
    $script = realpath(__DIR__ . '/../../agent/cron-plan-autopilot.php');
    if (!$script) { error_log('[plan-item-action] autopilot CLI not found'); return; }
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = sprintf('start /B "" "%s" "%s" --item=%d', $php, $script, $item_id);
        pclose(popen($cmd, 'r'));
    } else {
        $log = config('log_path') . '/cron-plan-autopilot.log';
        $cmd = sprintf('nohup %s %s --item=%d >> %s 2>&1 &', escapeshellarg($php), escapeshellarg($script), $item_id, escapeshellarg($log));
        exec($cmd);
    }
}

/** Generate via a specific provider only, by setting only that provider's key. */
function _pia_force_image_provider(PDO $db, int $post_id, string $prompt, string $alt, string $provider): ?array
{
    require_once __DIR__ . '/../../includes/image_gen.php';
    // image_gen_for_post tries dalle3 then unsplash. For an explicit provider, we
    // directly invoke the internal callers — slightly hacky but avoids refactoring.
    if ($provider === 'dalle3') {
        $key = config('openai_api_key');
        if (empty($key)) return null;
        $url = _image_gen_dalle3($prompt, $key);
        if (!$url) return null;
        // resolve site_id
        $stmt = $db->prepare("SELECT site_id FROM posts WHERE id = ?");
        $stmt->execute([$post_id]);
        $site_id = (int)$stmt->fetchColumn();
        $local = _image_gen_download_to_local($url, $site_id, $post_id, 'dalle3');
        if (!$local) return null;
        _image_gen_save_to_post($db, $post_id, $local, $prompt, 'dalle3', $alt);
        return ['provider' => 'dalle3', 'url' => $local];
    }
    if ($provider === 'unsplash') {
        $key = config('unsplash_access_key');
        if (empty($key)) return null;
        $url = _image_gen_unsplash_search($prompt, $key);
        if (!$url) return null;
        $stmt = $db->prepare("SELECT site_id FROM posts WHERE id = ?");
        $stmt->execute([$post_id]);
        $site_id = (int)$stmt->fetchColumn();
        $local = _image_gen_download_to_local($url, $site_id, $post_id, 'unsplash');
        if (!$local) return null;
        _image_gen_save_to_post($db, $post_id, $local, $prompt, 'unsplash', $alt);
        return ['provider' => 'unsplash', 'url' => $local];
    }
    return null;
}
