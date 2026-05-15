<?php
/**
 * Performance Loop actions.
 *
 * POST JSON: { action, post_id, ... }
 *   - refresh        : ask Claude to rewrite the post body for the existing topic, save as draft (new post or update)
 *   - queue_similar  : create a draft keyword + content idea around the same topic
 *   - dismiss        : mark post as acknowledged (no more nagging)
 *   - fetch_now      : trigger an on-demand performance snapshot for the site
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/performance.php';
require_once __DIR__ . '/../../includes/haiku.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

try {
    if ($action === 'fetch_now') {
        $site_id = (int)($input['site_id'] ?? 0);
        $stmt = $db->prepare('SELECT id FROM sites WHERE id = ? AND user_id = ?');
        $stmt->execute([$site_id, $user_id]);
        if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }
        $org = performance_snapshot_organic($db, $site_id);
        $soc = performance_snapshot_social($db, $site_id);
        echo json_encode(['success' => true, 'organic' => $org, 'social' => $soc]);
        exit;
    }

    $post_id = (int)($input['post_id'] ?? 0);
    if (!$post_id) { http_response_code(400); echo json_encode(['error' => 'post_id required']); exit; }

    $stmt = $db->prepare('SELECT p.*, s.user_id, s.name AS site_name, s.domain
                          FROM posts p JOIN sites s ON p.site_id = s.id
                          WHERE p.id = ? AND s.user_id = ?');
    $stmt->execute([$post_id, $user_id]);
    $post = $stmt->fetch();
    if (!$post) { http_response_code(404); echo json_encode(['error' => 'Post not found']); exit; }

    if ($action === 'dismiss') {
        performance_log_action($db, $post_id, 'dismiss', $input['note'] ?? null);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'refresh') {
        // ── 1. Performance signals for this post ─────────────
        $cms = $db->prepare('SELECT SUM(impressions) imp, SUM(clicks) cl, AVG(ctr) ctr, AVG(avg_position) pos
                             FROM post_performance WHERE post_id = ? AND channel = "cms" AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)');
        $cms->execute([$post_id]);
        $perf = $cms->fetch() ?: [];

        // ── 2. GSC top-query intel for this URL ──────────────
        // raw_metrics is the GSC page-level row. It contains aggregate metrics for
        // the URL but not per-query. We approximate query intent by pulling keywords
        // that have high impressions but lower CTR site-wide — the rewrite uses
        // these as 'queries the title should better target'.
        $intent = $db->prepare("
            SELECT k.keyword, k.gsc_position, k.impressions
            FROM keywords k
            WHERE k.site_id = ? AND k.status = 'active'
              AND k.impressions IS NOT NULL AND k.impressions >= 20
              AND k.gsc_position BETWEEN 5 AND 30
            ORDER BY k.impressions DESC
            LIMIT 8
        ");
        $intent->execute([$post['site_id']]);
        $intent_keywords = $intent->fetchAll(PDO::FETCH_ASSOC);

        // ── 3. Cannibalization check ──────────────────────────
        // Find other published posts on this site whose title or seo_keywords
        // strongly overlap with the current one. Naive token-overlap heuristic.
        $current_tokens = array_filter(array_unique(preg_split('/[^a-z0-9]+/i', strtolower($post['title'] ?? '')) ?: []));
        $current_tokens = array_values(array_filter($current_tokens, fn($t) => strlen($t) >= 4));
        $cannibals = [];
        if (count($current_tokens) >= 3) {
            $siblings = $db->prepare("SELECT id, title, slug FROM posts WHERE site_id = ? AND id != ? AND status = 'published'");
            $siblings->execute([$post['site_id'], $post_id]);
            foreach ($siblings->fetchAll(PDO::FETCH_ASSOC) as $sib) {
                $sib_tokens = array_filter(array_unique(preg_split('/[^a-z0-9]+/i', strtolower($sib['title'])) ?: []));
                $sib_tokens = array_values(array_filter($sib_tokens, fn($t) => strlen($t) >= 4));
                if (count($sib_tokens) < 3) continue;
                $shared = array_intersect($current_tokens, $sib_tokens);
                $overlap = count($shared) / max(count($current_tokens), count($sib_tokens));
                if ($overlap >= 0.5) {
                    $cannibals[] = ['id' => (int)$sib['id'], 'title' => $sib['title'], 'slug' => $sib['slug'], 'overlap' => round($overlap * 100)];
                }
            }
            usort($cannibals, fn($a, $b) => $b['overlap'] - $a['overlap']);
            $cannibals = array_slice($cannibals, 0, 3);
        }

        // ── 4. Internal-link candidates ───────────────────────
        // Pull a few sibling posts that share a cluster or tag, but DON'T overlap
        // heavily (those are cannibalization, handled above). These become anchors
        // Claude can naturally link to in the rewritten body.
        $link_candidates = [];
        $cannibal_ids = array_column($cannibals, 'id');
        $place = $cannibal_ids ? str_repeat(',?', count($cannibal_ids) - 1) : '';
        $excl  = $cannibal_ids ? " AND p.id NOT IN (?{$place})" : '';
        $link_stmt = $db->prepare("
            SELECT p.id, p.title, p.slug
            FROM posts p
            WHERE p.site_id = ? AND p.id != ? AND p.status = 'published'
              {$excl}
            ORDER BY p.published_at DESC
            LIMIT 6
        ");
        $params = [$post['site_id'], $post_id];
        if ($cannibal_ids) array_push($params, ...$cannibal_ids);
        $link_stmt->execute($params);
        $link_candidates = $link_stmt->fetchAll(PDO::FETCH_ASSOC);

        $domain_base = 'https://' . preg_replace('#^https?://#i', '', $post['domain']);

        // ── 5. Build the enriched prompt ──────────────────────
        $intent_lines = array_map(
            fn($k) => "  - \"{$k['keyword']}\" — pos #" . (int)$k['gsc_position'] . ", " . (int)$k['impressions'] . " impressions",
            $intent_keywords
        );
        $intent_block = $intent_lines
            ? "\n\nUnderperforming queries this site shows for (use these to inform the new title + meta_description):\n" . implode("\n", $intent_lines)
            : '';

        $link_lines = array_map(
            fn($l) => "  - [{$l['title']}]({$domain_base}/blog/{$l['slug']})",
            $link_candidates
        );
        $link_block = $link_lines
            ? "\n\nInternal-link candidates — naturally weave 2-3 of these into the rewritten body where they support a point:\n" . implode("\n", $link_lines)
            : '';

        $cannibal_block = '';
        if (!empty($cannibals)) {
            $cnames = array_map(fn($c) => "\"{$c['title']}\" ({$c['overlap']}% title overlap)", $cannibals);
            $cannibal_block = "\n\nCANNIBALIZATION WARNING — these existing posts overlap heavily with this one:\n  - " . implode("\n  - ", $cnames)
                . "\nIn your rewrite, differentiate clearly so this post targets a DIFFERENT angle / sub-topic than those.";
        }

        $system = "You are a senior SEO editor. Rewrite the blog post to increase CTR and dwell time on Google. Keep the core topic but sharpen the angle so it stands out from sibling posts. Punchy intro, scannable sections (use ## headings), a strong takeaway, and natural internal links.\n\nReturn ONLY valid JSON: {\"title\": \"...\", \"seo_title\": \"... 55-65 chars\", \"seo_description\": \"... 140-160 chars\", \"body\": \"... markdown with [anchor](https://...) internal links ...\"}";

        $why = sprintf(
            "Site: %s (%s)\nCurrent title: %s\nLast 28 days: %d impressions, %d clicks, %s%% CTR, avg position %s%s%s%s\n\nCurrent body:\n%s",
            $post['site_name'], $post['domain'], $post['title'],
            (int)($perf['imp'] ?? 0), (int)($perf['cl'] ?? 0),
            $perf['ctr'] !== null ? round((float)$perf['ctr'] * 100, 2) : 'n/a',
            $perf['pos'] !== null ? round((float)$perf['pos'], 1) : 'n/a',
            $intent_block, $link_block, $cannibal_block,
            $post['body']
        );

        $resp = haiku_chat($system, $why, 4000);
        if (empty($resp['success'])) {
            echo json_encode(['success' => false, 'error' => $resp['error'] ?? 'AI call failed']);
            exit;
        }

        $content = trim($resp['content']);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
        $data = json_decode($content, true);
        if (!is_array($data) || empty($data['body'])) {
            echo json_encode(['success' => false, 'error' => 'AI returned unparseable response', 'raw' => $content]);
            exit;
        }

        // Save as a new draft tied to the same site — original stays live until user re-publishes
        $stmt = $db->prepare('INSERT INTO posts (site_id, title, slug, body, excerpt, seo_title, seo_description, type, status, source_url)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)');
        $new_slug = $post['slug'] . '-refresh-' . date('Ymd');
        $stmt->execute([
            $post['site_id'],
            $data['title']           ?? ($post['title'] . ' (refresh)'),
            $new_slug,
            $data['body'],
            substr(strip_tags($data['body']), 0, 200),
            $data['seo_title']       ?? null,
            $data['seo_description'] ?? null,
            $post['type'],
            'refresh-of:' . $post_id,
        ]);
        $new_id = (int)$db->lastInsertId();

        performance_log_action($db, $post_id, 'refresh_queued', 'New draft #' . $new_id);
        echo json_encode([
            'success'     => true,
            'new_post_id' => $new_id,
            'used_signals' => [
                'intent_keywords' => count($intent_keywords),
                'cannibals'       => count($cannibals),
                'link_candidates' => count($link_candidates),
            ],
            'cannibals' => $cannibals,
        ]);
        exit;
    }

    if ($action === 'queue_similar') {
        // Add a "topic to write about" — simplest path: create a draft keyword from the post title
        $kw = trim($input['keyword'] ?? $post['title']);
        $stmt = $db->prepare('INSERT INTO keywords (site_id, keyword, status, source, priority)
                              VALUES (?, ?, "active", "manual", 80)
                              ON DUPLICATE KEY UPDATE priority = GREATEST(priority, 80), status = "active"');
        $stmt->execute([$post['site_id'], $kw]);
        performance_log_action($db, $post_id, 'queue_similar', 'Keyword queued: ' . $kw);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action: ' . $action]);
} catch (Throwable $e) {
    error_log('[performance-action] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
