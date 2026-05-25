<?php
/**
 * Keyword Research CLI — background-job version.
 *
 * The thin web endpoint public/api/keyword-research-start.php creates an
 * agent_runs row, fires this script via nohup/START, and returns job_id.
 * UI polls /api/keyword-research-status.php for current_step + final result.
 *
 * Steps written into agent_runs.current_step for live progress:
 *   1. Building seed phrases from your business profile
 *   2. Google Autocomplete [N/M]
 *   3. DataForSEO ideas [N/M]
 *   4. Picking top N candidates by volume
 *   5. Enriching X keywords with volume + difficulty
 *   6. Cross-referencing your Google Search Console data
 *   7. Classifying buyer intent
 *   8. Scoring opportunities + recommending actions
 *   9. Saving results
 *
 * Usage: php agent/keyword-research.php --site=N --run=M
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/keyword_intelligence.php';
require_once __DIR__ . '/../includes/integrations/google.php';

$db = require __DIR__ . '/../includes/db.php';

$opts    = getopt('', ['site:', 'run:']);
$site_id = (int)($opts['site'] ?? 0);
$run_id  = (int)($opts['run']  ?? 0);

if (!$site_id || !$run_id) {
    fwrite(STDERR, "Usage: php keyword-research.php --site=N --run=M\n");
    exit(1);
}

// agent_runs heartbeat helpers (same shape as competitors-discover.php)
$progress = function (string $step, int $pct, ?array $partial = null) use ($db, $run_id) {
    $sql = 'UPDATE agent_runs SET current_step = ?, progress = ?';
    $params = [$step, $pct];
    if ($partial !== null) {
        $sql .= ', result_summary = ?';
        $params[] = json_encode($partial);
    }
    $sql .= ' WHERE id = ?';
    $params[] = $run_id;
    $db->prepare($sql)->execute($params);
};
$mark_done = function (array $summary) use ($db, $run_id) {
    $db->prepare('UPDATE agent_runs SET status = "done", progress = 100, current_step = "Done", result_summary = ?, finished_at = NOW() WHERE id = ?')
       ->execute([json_encode($summary), $run_id]);
};
$mark_failed = function (string $error) use ($db, $run_id) {
    $db->prepare('UPDATE agent_runs SET status = "failed", current_step = "Failed", error = ?, finished_at = NOW() WHERE id = ?')
       ->execute([$error, $run_id]);
    exit(1);
};

try {
    $stmt = $db->prepare('SELECT id, domain FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    if (!$site) $mark_failed('Site not found.');

    // ── Step -1: Wipe stale AI-found rows BEFORE re-running ─────────
    // Without this, every Find Keywords run accumulated on top of the
    // previous one. User would see 100 fresh keywords + 400 old garbage
    // from earlier looser-filter runs. Manual entries + Google-synced
    // rows are preserved — those are deliberate user data.
    $wipe = $db->prepare("DELETE FROM keywords
        WHERE site_id = ?
          AND source IN ('autocomplete', 'paa', 'dataforseo_ideas', 'dataforseo_suggestions', 'competitor')
          AND (gsc_synced_at IS NULL)");
    $wipe->execute([$site_id]);
    $wiped = $wipe->rowCount();
    if ($wiped > 0) {
        $progress("Cleared {$wiped} previously-found keywords for a fresh run...", 1);
    }

    // ── Step 0: Sync Google Search Console first (if connected) ────
    // Folded into Find Keywords so the user gets one button that does
    // everything — sync → expand → score → bucket. Previously these were
    // three separate menu items that confused users into wondering which
    // to click. GSC sync also runs an auto-relevance pass on newly-imported
    // keywords inside google_update_rankings(), so blog/news drift gets
    // filtered automatically.
    $gsc_summary = ['skipped' => 'not connected'];
    $stmt = $db->prepare('SELECT id FROM integrations WHERE site_id = ? AND platform = "google_search_console" AND is_active = 1');
    $stmt->execute([$site_id]);
    if ($stmt->fetchColumn()) {
        $progress('Pulling fresh data from Google Search Console...', 2);
        try {
            $gsc_summary = google_update_rankings($db, $site_id);
        } catch (Throwable $e) {
            // Don't fail the whole job if GSC sync fails — log and continue with expansion.
            error_log('[keyword-research CLI] GSC sync failed: ' . $e->getMessage());
            $gsc_summary = ['skipped' => 'sync error: ' . $e->getMessage()];
        }
    }

    // ── Step 1+: AI expansion, enrichment, intent classification, scoring ──
    $result = ki_run($db, $site_id, $progress);

    // ── Persist rows ────────────────────────────────────────
    $progress('Saving ' . count($result['rows']) . ' keywords...', 95);

    // We never overwrite GSC/manual-source rows' priority; we just enrich them
    // with the new intelligence fields. Brand-new candidates take all the AI fields.
    $upsert = $db->prepare("INSERT INTO keywords
        (site_id, keyword, source, keyword_type, cluster, intent, buyer_question,
         search_volume, difficulty, cpc, priority, opportunity_score, recommended_action,
         metrics_refreshed_at, last_checked)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            keyword_type        = COALESCE(VALUES(keyword_type), keyword_type),
            intent              = IF(VALUES(intent) = 'unknown', intent, VALUES(intent)),
            buyer_question      = COALESCE(VALUES(buyer_question), buyer_question),
            search_volume       = COALESCE(VALUES(search_volume), search_volume),
            difficulty          = COALESCE(VALUES(difficulty), difficulty),
            cpc                 = COALESCE(VALUES(cpc), cpc),
            opportunity_score   = VALUES(opportunity_score),
            recommended_action  = VALUES(recommended_action),
            priority            = IF(source IN ('gsc', 'manual'), priority, VALUES(priority)),
            metrics_refreshed_at = NOW(),
            last_checked        = NOW()
    ");

    $saved = 0;
    foreach ($result['rows'] as $row) {
        $upsert->execute([
            $site_id,
            $row['keyword'],
            $row['source'],
            $row['keyword_type'],
            $row['cluster'],
            $row['intent'],
            $row['buyer_question'],
            $row['search_volume'],
            $row['difficulty'],
            $row['cpc'],
            $row['priority'],
            $row['opportunity_score'],
            $row['recommended_action'],
        ]);
        $saved++;
    }

    // ── Step Final: clean off-topic GSC pollution (one sweep on existing rows) ──
    $progress('Cleaning off-topic Google keywords...', 98);
    $cleaned = 0;
    try {
        $gsc_stmt = $db->prepare("SELECT keyword FROM keywords WHERE site_id = ? AND status = 'active' AND source = 'gsc'");
        $gsc_stmt->execute([$site_id]);
        $gsc_keywords = array_values(array_filter($gsc_stmt->fetchAll(PDO::FETCH_COLUMN)));
        if (!empty($gsc_keywords)) {
            $cleaned = keywords_auto_ignore_offtopic($db, $site_id, $gsc_keywords);
        }
    } catch (Throwable $e) {
        error_log('[keyword-research CLI] post-run GSC cleanup failed: ' . $e->getMessage());
    }

    $summary = [
        'total_raw'        => $result['total_raw'],
        'total_kept'       => $result['total_kept'],
        'saved'            => $saved,
        'wiped_before'     => $wiped,
        'counts_by_action' => $result['counts_by_action'],
        'counts_by_intent' => $result['counts_by_intent'],
        'gsc'              => $gsc_summary,
        'gsc_auto_ignored' => $cleaned,
    ];
    $mark_done($summary);

    echo "Done. Saved {$saved} keywords ({$result['total_raw']} candidates discovered).\n";
} catch (Throwable $e) {
    error_log('[keyword-research CLI] ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
    $mark_failed($e->getMessage());
}
