<?php
/**
 * Hard-delete a site and every row that belongs to it.
 *
 * Most tables already have ON DELETE CASCADE on `site_id`, so deleting the
 * `sites` row would cascade automatically. But a few tables (page_seo,
 * post_channels, post_performance, performance_actions, password_resets,
 * agent_runs etc) reach the site indirectly via posts/users or were created
 * via PHP without an FK — we clean those up explicitly first.
 *
 * Runs in a transaction. Returns a per-table count of rows removed so the
 * caller can show a confirmation summary.
 */

require_once __DIR__ . '/helpers.php';

function site_delete_cascade(PDO $db, int $site_id): array
{
    if ($site_id <= 0) return ['success' => false, 'error' => 'Invalid site id'];

    // Verify the site exists before doing anything
    $check = $db->prepare('SELECT id, name FROM sites WHERE id = ?');
    $check->execute([$site_id]);
    $site = $check->fetch();
    if (!$site) return ['success' => false, 'error' => 'Site not found'];

    $counts = [];
    $db->beginTransaction();

    try {
        // 1. Tables reached via posts → site (these may or may not cascade via post_id;
        //    delete explicitly so we know it's clean).
        $post_ids = $db->prepare('SELECT id FROM posts WHERE site_id = ?');
        $post_ids->execute([$site_id]);
        $pids = $post_ids->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($pids)) {
            $place = implode(',', array_fill(0, count($pids), '?'));
            foreach (['post_channels', 'post_performance', 'performance_actions', 'social_posts'] as $tbl) {
                $counts[$tbl] = _site_delete_try($db, "DELETE FROM `{$tbl}` WHERE post_id IN ({$place})", $pids);
            }
        }

        // 2. Tables with site_id that may not have FK cascade
        foreach ([
            'page_seo',
            'seo_issues',
            'seo_audits',
            'aeo_results',           // cascade via aeo_queries but harmless to attempt
            'aeo_queries',
            'content_gaps',
            'gap_analysis_runs',
            'competitor_keyword_rankings',
            'competitor_pages',
            'competitors',
            'alerts',
            'ai_visibility',
            'brand_mentions',
            'ai_presence_content',
            'integrations',
            'keywords',
            'subscribers',
            'newsletters',
            'agent_runs',
            'agent_log',
            'posts',
            // Note: integration_setup_progress is per-user, not per-site
        ] as $tbl) {
            $counts[$tbl] = _site_delete_try($db, "DELETE FROM `{$tbl}` WHERE site_id = ?", [$site_id]);
        }

        // 3. Finally the site row itself
        $db->prepare('DELETE FROM sites WHERE id = ?')->execute([$site_id]);
        $counts['sites'] = 1;

        $db->commit();
        return [
            'success'    => true,
            'site_name'  => $site['name'],
            'counts'     => array_filter($counts, fn($v) => $v > 0),
            'total_rows' => array_sum($counts),
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Run a DELETE and return rowCount(), silently ignoring "table doesn't exist"
 * so this works on installs missing optional migrations.
 */
function _site_delete_try(PDO $db, string $sql, array $params): int
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, "doesn't exist") !== false || stripos($msg, 'Unknown column') !== false) {
            return 0;
        }
        throw $e;
    }
}
