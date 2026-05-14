<?php
/**
 * Unified per-channel API.
 *
 * Each post can be published to multiple channels independently:
 *   - generate: ask Claude to create the channel-specific variant
 *   - save:     persist user-edited variant content
 *   - publish:  publish-now via the adapter (sync)
 *   - schedule: set scheduled_for; cron-publish picks it up
 *   - cancel:   revert from queued/scheduled back to draft
 *   - delete:   remove the channel row entirely (so it can be re-generated)
 *
 * POST JSON: { action, post_id, channel, ...action-specific fields }
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/channels/registry.php';
require_once __DIR__ . '/../../includes/haiku.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action  = $input['action']  ?? '';
$post_id = (int)($input['post_id'] ?? 0);
$channel = trim($input['channel'] ?? '');

if (!$action || !$post_id || !$channel) {
    http_response_code(400);
    echo json_encode(['error' => 'action, post_id, channel required']);
    exit;
}

// Verify ownership: user owns the site that owns this post
$stmt = $db->prepare('SELECT p.*, s.* FROM posts p JOIN sites s ON p.site_id = s.id WHERE p.id = ? AND s.user_id = ?');
$stmt->execute([$post_id, $user_id]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Post not found']);
    exit;
}

// Re-fetch post + site cleanly (the JOIN above had column collisions)
$post = $db->prepare('SELECT * FROM posts WHERE id = ?');
$post->execute([$post_id]);
$post = $post->fetch(PDO::FETCH_ASSOC);

$site = $db->prepare('SELECT * FROM sites WHERE id = ?');
$site->execute([$post['site_id']]);
$site = $site->fetch(PDO::FETCH_ASSOC);

$registry = channels_registry();
$adapter = $registry->get($channel);
if (!$adapter) {
    http_response_code(400);
    echo json_encode(['error' => "Unknown channel '{$channel}'"]);
    exit;
}

// Helper: get the existing post_channels row, or create a blank draft row
function get_or_create_row(PDO $db, int $post_id, string $channel): array {
    $stmt = $db->prepare('SELECT * FROM post_channels WHERE post_id = ? AND channel = ?');
    $stmt->execute([$post_id, $channel]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    $db->prepare('INSERT INTO post_channels (post_id, channel, status, created_at) VALUES (?, ?, "draft", NOW())')
        ->execute([$post_id, $channel]);
    $stmt->execute([$post_id, $channel]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ─── GENERATE ────────────────────────────────────────────────
if ($action === 'generate') {
    if ($channel === 'cms') {
        // CMS doesn't have a "variant" — it publishes the post body directly
        echo json_encode(['success' => false, 'error' => 'CMS channel uses the post body directly — no variant to generate']);
        exit;
    }

    $result = haiku_repurpose_for_channel($post, $channel, $site);
    if (empty($result['success'])) {
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Claude failed to generate variant']);
        exit;
    }

    $row = get_or_create_row($db, $post_id, $channel);
    $db->prepare('UPDATE post_channels SET variant_content = ?, status = IF(status IN ("published"), status, "draft"), error = NULL, updated_at = NOW() WHERE id = ?')
        ->execute([$result['content'], $row['id']]);

    echo json_encode(['success' => true, 'content' => $result['content']]);
    exit;
}

// ─── SAVE edited variant ─────────────────────────────────────
if ($action === 'save') {
    $content = $input['content'] ?? '';
    $row = get_or_create_row($db, $post_id, $channel);
    $db->prepare('UPDATE post_channels SET variant_content = ?, status = IF(status IN ("published"), status, "draft"), updated_at = NOW() WHERE id = ?')
        ->execute([$content, $row['id']]);
    echo json_encode(['success' => true]);
    exit;
}

// ─── PUBLISH now (synchronous) ───────────────────────────────
if ($action === 'publish') {
    $row = get_or_create_row($db, $post_id, $channel);

    // For non-CMS channels: require a variant_content (so user can't accidentally publish a blank variant)
    if ($channel !== 'cms' && empty($row['variant_content'])) {
        echo json_encode(['success' => false, 'error' => 'Generate or write the variant content first']);
        exit;
    }

    // For CMS: ensure variant_content holds the post body (transform_post default)
    if ($channel === 'cms') {
        $variant = $adapter->transform_post($post, $site);
        $db->prepare('UPDATE post_channels SET variant_content = ?, status = "queued", scheduled_for = NULL, attempts = IF(status IN ("published"), attempts, 0), updated_at = NOW() WHERE id = ?')
            ->execute([$variant['content'] ?? null, $row['id']]);
    } else {
        $db->prepare('UPDATE post_channels SET status = "queued", scheduled_for = NULL, attempts = IF(status IN ("published"), 0, attempts), error = NULL, updated_at = NOW() WHERE id = ?')
            ->execute([$row['id']]);
    }

    $result = $registry->publish_row($db, (int)$row['id']);
    echo json_encode($result);
    exit;
}

// ─── SCHEDULE for later ──────────────────────────────────────
if ($action === 'schedule') {
    $when = trim($input['scheduled_for'] ?? '');
    if (!$when) {
        echo json_encode(['success' => false, 'error' => 'scheduled_for required (YYYY-MM-DD HH:MM)']);
        exit;
    }
    $ts = strtotime($when);
    if (!$ts || $ts < time() - 60) {
        echo json_encode(['success' => false, 'error' => 'Schedule must be a valid future date/time']);
        exit;
    }
    $scheduled = date('Y-m-d H:i:s', $ts);

    $row = get_or_create_row($db, $post_id, $channel);

    if ($channel !== 'cms' && empty($row['variant_content'])) {
        echo json_encode(['success' => false, 'error' => 'Generate or write the variant content first']);
        exit;
    }

    if ($channel === 'cms') {
        $variant = $adapter->transform_post($post, $site);
        $db->prepare('UPDATE post_channels SET variant_content = ?, status = "queued", scheduled_for = ?, attempts = 0, error = NULL, updated_at = NOW() WHERE id = ?')
            ->execute([$variant['content'] ?? null, $scheduled, $row['id']]);
    } else {
        $db->prepare('UPDATE post_channels SET status = "queued", scheduled_for = ?, attempts = 0, error = NULL, updated_at = NOW() WHERE id = ?')
            ->execute([$scheduled, $row['id']]);
    }

    echo json_encode(['success' => true, 'scheduled_for' => $scheduled]);
    exit;
}

// ─── CANCEL (revert to draft) ────────────────────────────────
if ($action === 'cancel') {
    $row = get_or_create_row($db, $post_id, $channel);
    if ($row['status'] === 'published') {
        echo json_encode(['success' => false, 'error' => "Can't cancel an already-published post"]);
        exit;
    }
    $db->prepare('UPDATE post_channels SET status = "draft", scheduled_for = NULL, error = NULL, updated_at = NOW() WHERE id = ?')
        ->execute([$row['id']]);
    echo json_encode(['success' => true]);
    exit;
}

// ─── DELETE the row entirely ─────────────────────────────────
if ($action === 'delete') {
    $db->prepare('DELETE FROM post_channels WHERE post_id = ? AND channel = ?')
        ->execute([$post_id, $channel]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
