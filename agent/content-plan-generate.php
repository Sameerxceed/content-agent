<?php
/**
 * Content Plan generator — background-job CLI.
 *
 * The thin web endpoint public/api/content-plan-start.php creates an
 * agent_runs row + fires this script via nohup, returns job_id. UI polls
 * /api/content-plan-status.php for current_step + final result.
 *
 * Steps surfaced via agent_runs.current_step so the UI shows live progress:
 *   1. Loading business profile and keywords
 *   2. Clustering keywords into topic groups (AI)
 *   3. Sequencing N items across N weeks (AI)
 *   4. Computing forecast
 *   5. Saving plan, clusters, and pipeline
 *   6. Plan generated.
 *
 * Usage: php agent/content-plan-generate.php --site=N --run=M
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/content_plan.php';

$db = require __DIR__ . '/../includes/db.php';

$opts    = getopt('', ['site:', 'run:', 'cadence:', 'horizon:', 'forecast_horizon:', 'goal:']);
$site_id = (int)($opts['site'] ?? 0);
$run_id  = (int)($opts['run']  ?? 0);
if (!$site_id || !$run_id) {
    fwrite(STDERR, "Usage: php content-plan-generate.php --site=N --run=M [--cadence=2] [--horizon=12]\n");
    exit(1);
}

// agent_runs heartbeat helpers (mirrors agent/keyword-research.php exactly)
$progress = function (string $step, int $pct, ?array $partial = null) use ($db, $run_id) {
    $sql = 'UPDATE agent_runs SET current_step = ?, progress = ?';
    $params = [$step, $pct];
    if ($partial !== null) { $sql .= ', result_summary = ?'; $params[] = json_encode($partial); }
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
    $stmt = $db->prepare('SELECT id, name, autonomy_mode, posts_per_week FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    if (!$site) $mark_failed('Site not found.');

    // Pull cadence/horizon from --opts first, fall back to site defaults
    $cadence  = (int)($opts['cadence']  ?? $site['posts_per_week'] ?? 2);
    $horizon  = (int)($opts['horizon']  ?? 12);
    $forecast_horizon = (int)($opts['forecast_horizon'] ?? 26);
    $goal     = (string)($opts['goal']  ?? '');

    $summary = plan_generate($db, $site_id, [
        'cadence_posts_per_week' => $cadence,
        'rolling_horizon_weeks'  => $horizon,
        'forecast_horizon_weeks' => $forecast_horizon,
        'goal'                   => $goal,
    ], $progress);

    $mark_done($summary);
    echo "Plan generated. plan_id={$summary['plan_id']} clusters={$summary['clusters']} items={$summary['items_saved']}\n";
} catch (Throwable $e) {
    error_log('[content-plan-generate CLI] ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
    $mark_failed($e->getMessage());
}
