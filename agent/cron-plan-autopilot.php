<?php
/**
 * Content Plan autopilot — drafts items 5-7 days before their publish date.
 *
 * Runs daily (02:00 IST). For each active plan, finds pipeline items whose
 * target_publish_date is 5-7 days out, then for each:
 *   1. Lock to 'committed' immediately (so concurrent runs don't double-draft)
 *   2. Single Claude call returns blog + FAQ + schema + LinkedIn + Twitter + Reddit + newsletter + image_prompt
 *   3. Insert posts row (status=draft) with rich body
 *   4. Generate hero image via DALL-E (Unsplash fallback) using the image_prompt
 *   5. Create post_channels rows for applicable channels with variant_content pre-populated
 *   6. Lock to 'drafted', link via post_id
 *   7. Create alert for the site owner: "N posts ready for review"
 *
 * Usage:
 *   php agent/cron-plan-autopilot.php              — run for all active plans
 *   php agent/cron-plan-autopilot.php --site=N     — run for one site only
 *   php agent/cron-plan-autopilot.php --item=N     — run for one specific item (testing)
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/content_plan.php';
require_once __DIR__ . '/../includes/content_artifacts.php';
require_once __DIR__ . '/../includes/image_gen.php';

$db = require __DIR__ . '/../includes/db.php';

$opts    = getopt('', ['site:', 'item:']);
$site_id = (int)($opts['site'] ?? 0);
$item_id = (int)($opts['item'] ?? 0);

$today  = new DateTime('today');
$min_dt = (clone $today)->modify('+5 days')->format('Y-m-d');
$max_dt = (clone $today)->modify('+7 days')->format('Y-m-d');

if ($item_id) {
    // Specific item override (testing)
    $sql = "SELECT i.* FROM content_plan_items i WHERE i.id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$item_id]);
} else {
    $sql = "SELECT i.* FROM content_plan_items i
        JOIN content_plans p ON i.plan_id = p.id
        WHERE p.status = 'active'
          AND i.lock_state = 'pipeline'
          AND i.target_publish_date BETWEEN ? AND ?";
    if ($site_id) $sql .= " AND i.site_id = " . (int)$site_id;
    $sql .= " ORDER BY i.target_publish_date ASC LIMIT 50";
    $stmt = $db->prepare($sql);
    $stmt->execute([$min_dt, $max_dt]);
}
$items = $stmt->fetchAll();
echo "Autopilot: found " . count($items) . " items to draft.\n";

$success_by_site = [];
$failures = 0;

foreach ($items as $it) {
    $iid = (int)$it['id'];
    $isite = (int)$it['site_id'];

    // Lock immediately so concurrent runs skip this one. The UPDATE only
    // affects rows currently in 'pipeline'; if the row is already past that
    // state (drafted/published) someone else has it.
    $stmt = $db->prepare("UPDATE content_plan_items SET lock_state='committed' WHERE id=? AND lock_state IN ('pipeline','committed')");
    $stmt->execute([$iid]);
    $check = $db->prepare("SELECT lock_state FROM content_plan_items WHERE id=?");
    $check->execute([$iid]);
    $current_lock = (string)$check->fetchColumn();
    if ($current_lock !== 'committed') {
        echo "  ↷ item={$iid} in state '{$current_lock}', skipping.\n";
        continue;
    }

    try {
        // ── Generate the full multi-artifact package ─────────────
        echo "  → item={$iid}: generating package...\n";
        $pkg = content_artifacts_generate_full_package($db, $iid);

        // ── Insert posts row ─────────────────────────────────────
        $blog = $pkg['blog'];
        $slug = _autopilot_slugify($blog['title'] ?? 'untitled-' . $iid);
        // Ensure slug uniqueness per site
        $slug = _autopilot_unique_slug($db, $isite, $slug);

        $db->prepare("INSERT INTO posts
            (site_id, title, slug, body, excerpt, seo_title, seo_description, seo_keywords,
             type, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW(), NOW())")
            ->execute([
                $isite,
                mb_substr((string)($blog['title'] ?? ''), 0, 500),
                $slug,
                (string)($blog['body_html'] ?? ''),
                mb_substr((string)($blog['excerpt'] ?? ''), 0, 1000),
                mb_substr((string)($blog['seo_title'] ?? ''), 0, 70),
                mb_substr((string)($blog['seo_description'] ?? ''), 0, 170),
                _autopilot_seo_keywords_for($db, (int)$it['primary_keyword_id'], json_decode($it['secondary_keyword_ids'] ?? '[]', true) ?: []),
                _autopilot_post_type_from_item_type($it['content_type']),
            ]);
        $post_id = (int)$db->lastInsertId();

        // ── Generate hero image ──────────────────────────────────
        $hero = $pkg['hero_image'] ?? [];
        if (!empty($hero['prompt'])) {
            echo "    image: generating...\n";
            $img = image_gen_for_post($db, $post_id, (string)$hero['prompt'], (string)($hero['alt'] ?? ''));
            if ($img) {
                echo "    image: {$img['provider']} → {$img['url']}\n";
            } else {
                echo "    image: skipped (no provider configured)\n";
                // Persist prompt + alt anyway so the user can manually upload later
                $db->prepare("UPDATE posts SET hero_image_prompt=?, hero_image_alt=?, hero_image_provider='none' WHERE id=?")
                   ->execute([(string)$hero['prompt'], mb_substr((string)($hero['alt'] ?? ''), 0, 500), $post_id]);
            }
        }

        // ── Create post_channels rows for applicable channels ────
        $channels = json_decode($it['channels'] ?? '[]', true) ?: [];
        _autopilot_queue_channels($db, $post_id, $channels, $it['target_publish_date'], $pkg);

        // ── Link item → post and lock to 'drafted' ──────────────
        $db->prepare("UPDATE content_plan_items SET post_id=?, lock_state='drafted', drafted_at=NOW() WHERE id=?")
           ->execute([$post_id, $iid]);

        echo "  ✓ item={$iid} → post={$post_id}\n";
        $success_by_site[$isite] = ($success_by_site[$isite] ?? 0) + 1;
    } catch (Throwable $e) {
        $failures++;
        error_log("[autopilot] item={$iid}: " . $e->getMessage());
        // Unlock so a future run can retry
        $db->prepare("UPDATE content_plan_items SET lock_state='pipeline' WHERE id=?")->execute([$iid]);
        echo "  ✗ item={$iid}: " . $e->getMessage() . "\n";
    }
}

// ── Alerts (one per site that had drafts ready) ──
foreach ($success_by_site as $sid => $count) {
    $msg = $count === 1 ? '1 post is ready for review' : "{$count} posts are ready for review";
    $db->prepare("INSERT INTO alerts (site_id, type, severity, title, detail, link_url, detected_at)
        VALUES (?, 'plan_drafts_ready', 'info', ?, ?, ?, NOW())")
       ->execute([
           $sid,
           '✏️ ' . $msg,
           'The autopilot drafted these from your content plan. Review and approve to schedule for publishing.',
           '/dashboard/plan.php?site=' . $sid,
       ]);
}

echo "Autopilot done. drafts=" . array_sum($success_by_site) . " failures={$failures}\n";

// ─────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────

function _autopilot_slugify(string $title): string
{
    $s = mb_strtolower($title);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
    $s = trim($s, '-');
    return mb_substr($s, 0, 100);
}

function _autopilot_unique_slug(PDO $db, int $site_id, string $base): string
{
    $slug = $base;
    $i = 1;
    while (true) {
        $stmt = $db->prepare("SELECT 1 FROM posts WHERE site_id=? AND slug=?");
        $stmt->execute([$site_id, $slug]);
        if (!$stmt->fetchColumn()) return $slug;
        $i++;
        $slug = $base . '-' . $i;
    }
}

function _autopilot_seo_keywords_for(PDO $db, int $primary_id, array $secondary_ids): string
{
    $ids = array_unique(array_merge([$primary_id], array_map('intval', $secondary_ids)));
    if (empty($ids)) return '';
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT keyword FROM keywords WHERE id IN ({$in})");
    $stmt->execute($ids);
    $kws = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return mb_substr(implode(', ', $kws), 0, 500);
}

function _autopilot_post_type_from_item_type(string $content_type): string
{
    // posts.type enum was extended in migration 030 to support these
    $allowed = ['blog', 'news', 'pillar', 'comparison', 'guide', 'service_page', 'glossary'];
    return in_array($content_type, $allowed, true) ? $content_type : 'blog';
}

function _autopilot_queue_channels(PDO $db, int $post_id, array $channels, string $scheduled_for, array $pkg): void
{
    // For each channel in the item's applicable list, create a post_channels
    // row with variant_content pre-populated. Existing cron-publish.php will
    // pick these up on scheduled_for and distribute.
    $insert = $db->prepare("INSERT INTO post_channels
        (post_id, channel_id, status, scheduled_for, variant_content, variant_meta, created_at)
        VALUES (?, ?, 'draft', ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            scheduled_for = VALUES(scheduled_for),
            variant_content = VALUES(variant_content),
            variant_meta = VALUES(variant_meta)");

    foreach ($channels as $ch) {
        [$content, $meta] = _autopilot_variant_for_channel($ch, $pkg);
        if ($content === null) continue;
        try {
            $insert->execute([$post_id, $ch, $scheduled_for, $content, $meta]);
        } catch (Throwable $e) {
            error_log("[autopilot] queue {$ch} for post {$post_id}: " . $e->getMessage());
        }
    }
}

/** Returns [content_string, meta_json|null] for a given channel id. */
function _autopilot_variant_for_channel(string $channel, array $pkg): array
{
    switch ($channel) {
        case 'cms':
            // CMS adapter uses posts.body directly; variant_content for cms can hold the title for legacy parity
            return [(string)($pkg['blog']['title'] ?? ''), null];
        case 'linkedin':
            return [isset($pkg['linkedin']) ? (string)$pkg['linkedin'] : null, null];
        case 'twitter':
            if (empty($pkg['twitter_thread'])) return [null, null];
            return [json_encode($pkg['twitter_thread']), json_encode(['format' => 'thread'])];
        case 'reddit':
            if (empty($pkg['reddit'])) return [null, null];
            return [(string)($pkg['reddit']['body'] ?? ''), json_encode(['title' => $pkg['reddit']['title'] ?? ''])];
        case 'newsletter':
            return [isset($pkg['newsletter']) ? (string)$pkg['newsletter'] : null, null];
        case 'schema':
            // schema JSON-LD lives in variant_content as the JSON blob
            return [isset($pkg['schema_ldjson']) ? json_encode($pkg['schema_ldjson']) : '[]', null];
        case 'llms':
            // llms.txt regeneration is global; this row is a sentinel so the publish queue knows to trigger the regen
            return ['regenerate', null];
    }
    return [null, null];
}
