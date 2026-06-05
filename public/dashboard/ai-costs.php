<?php
/**
 * Dashboard — AI cost analytics.
 *
 * Super-admin only. Pivots the ai_calls log into:
 *   - Total spend (today / 7d / 30d / 90d)
 *   - Per-site (drives customer-tier pricing decisions)
 *   - Per-feature (which capability burns the most)
 *   - Per-provider/model (which vendor we're paying)
 *
 * Data source is the ai_calls table populated by includes/ai_cost.php's
 * ai_log_call() — every Claude/OpenAI/Gemini/Perplexity call lands one row.
 *
 * If the table is empty (logging just turned on) the page shows a clear
 * "still collecting data" empty state instead of a wall of zeros.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();
if (!auth_is_super_admin()) {
    http_response_code(403);
    exit('Super admin only — customer billing data.');
}

$db = require __DIR__ . '/../../includes/db.php';

// Migration check — show a clear message if Phase 0 migration hasn't been run yet.
$table_exists = false;
try {
    $r = $db->query("SHOW TABLES LIKE 'ai_calls'");
    $table_exists = $r && $r->fetch() ? true : false;
} catch (Throwable $e) {}

$range = (string)($_GET['range'] ?? '30d');
$range_map = [
    '24h' => ['hours' => 24,  'label' => 'last 24 hours'],
    '7d'  => ['hours' => 168, 'label' => 'last 7 days'],
    '30d' => ['hours' => 720, 'label' => 'last 30 days'],
    '90d' => ['hours' => 2160,'label' => 'last 90 days'],
];
if (!isset($range_map[$range])) $range = '30d';
$hours = $range_map[$range]['hours'];

// All queries scoped by the same SINCE clause. Qualified column because
// the per-site pivot joins `sites` which also has a `created_at` column —
// unqualified makes MySQL throw "Column 'created_at' is ambiguous".
$since_sql_unqualified = "created_at > DATE_SUB(NOW(), INTERVAL {$hours} HOUR)";
$since_sql_qualified   = "c.created_at > DATE_SUB(NOW(), INTERVAL {$hours} HOUR)";

$summary = ['total_cost' => 0, 'total_calls' => 0, 'total_in' => 0, 'total_out' => 0, 'oldest' => null];
$by_site = $by_feature = $by_model = $by_day = [];

if ($table_exists) {
    $row = $db->query("SELECT
            COALESCE(SUM(cost_usd), 0) AS total_cost,
            COUNT(*) AS total_calls,
            COALESCE(SUM(input_tokens), 0)  AS total_in,
            COALESCE(SUM(output_tokens), 0) AS total_out,
            MIN(created_at) AS oldest
        FROM ai_calls WHERE {$since_sql_unqualified}")->fetch();
    if ($row) $summary = $row;

    $by_site = $db->query("SELECT
            COALESCE(s.name, '(no site)')  AS site_name,
            c.site_id,
            COUNT(*)                       AS calls,
            SUM(c.cost_usd)                AS cost,
            SUM(c.input_tokens)            AS in_tok,
            SUM(c.output_tokens)           AS out_tok
        FROM ai_calls c LEFT JOIN sites s ON s.id = c.site_id
        WHERE {$since_sql_qualified}
        GROUP BY c.site_id, s.name
        ORDER BY cost DESC
        LIMIT 50")->fetchAll();

    $by_feature = $db->query("SELECT
            feature,
            COUNT(*) AS calls,
            SUM(cost_usd) AS cost,
            AVG(cost_usd) AS avg_cost,
            AVG(ms) AS avg_ms
        FROM ai_calls WHERE {$since_sql_unqualified}
        GROUP BY feature
        ORDER BY cost DESC
        LIMIT 30")->fetchAll();

    $by_model = $db->query("SELECT
            provider, model,
            COUNT(*) AS calls,
            SUM(cost_usd) AS cost,
            SUM(input_tokens) AS in_tok,
            SUM(output_tokens) AS out_tok
        FROM ai_calls WHERE {$since_sql_unqualified}
        GROUP BY provider, model
        ORDER BY cost DESC")->fetchAll();

    // Daily spend for sparkline — clamp to the chosen window
    $by_day = $db->query("SELECT
            DATE(created_at) AS d,
            SUM(cost_usd) AS cost,
            COUNT(*) AS calls
        FROM ai_calls WHERE {$since_sql_unqualified}
        GROUP BY DATE(created_at)
        ORDER BY d ASC")->fetchAll();
}

$page_title = 'AI Costs';
ob_start();
?>
<style>
.ac-head { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:14px; flex-wrap:wrap; gap:10px; }
.ac-head h2 { margin:0; font-size:18px; color:var(--primary); }
.ac-head .sub { font-size:11px; color:#dc2626; font-weight:600; padding:2px 8px; background:#fee2e2; border-radius:10px; margin-left:8px; }
.ac-range { display:flex; gap:0; border:1px solid var(--border); border-radius:6px; overflow:hidden; }
.ac-range a { padding:5px 12px; font-size:12px; color:#475569; text-decoration:none; border-right:1px solid var(--border); }
.ac-range a:last-child { border-right:0; }
.ac-range a.active { background:var(--primary); color:#fff; }
.ac-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; margin-bottom:18px; }
.ac-card { padding:14px 16px; background:#fff; border:1px solid var(--border); border-radius:8px; }
.ac-card .lbl { font-size:10px; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; margin-bottom:4px; }
.ac-card .num { font-size:24px; font-weight:700; color:#0f172a; line-height:1; }
.ac-card .num.cost { color:#dc2626; }
.ac-card .sub { font-size:11px; color:#64748b; margin-top:5px; }
.ac-section { margin-bottom:22px; }
.ac-section h3 { font-size:11px; text-transform:uppercase; letter-spacing:0.6px; color:#64748b; margin:0 0 6px; }
.ac-section .desc { font-size:11px; color:#94a3b8; margin-bottom:8px; }
.ac-table { width:100%; background:#fff; border:1px solid var(--border); border-radius:6px; border-collapse:collapse; font-size:12px; }
.ac-table th, .ac-table td { padding:7px 12px; border-bottom:1px solid #f1f5f9; text-align:left; }
.ac-table th { font-size:10px; text-transform:uppercase; color:#94a3b8; letter-spacing:0.4px; background:#f8fafc; }
.ac-table td.num { font-family:ui-monospace, monospace; text-align:right; }
.ac-table td.cost { font-family:ui-monospace, monospace; text-align:right; color:#dc2626; font-weight:600; }
.ac-table tr:last-child td { border-bottom:0; }
.ac-bar { display:inline-block; background:#fee2e2; border-radius:2px; height:8px; vertical-align:middle; margin-right:6px; }
.ac-empty { padding:30px; text-align:center; color:#64748b; background:#fff; border:1px dashed var(--border); border-radius:8px; }
.ac-spark { display:flex; align-items:flex-end; gap:1px; height:60px; padding:6px 0; }
.ac-spark .b { flex:1; background:#dc2626; min-width:3px; opacity:0.85; border-radius:1px 1px 0 0; }
.ac-empty .pill { display:inline-block; padding:3px 9px; background:#fef3c7; color:#92400e; border-radius:10px; font-size:11px; margin-top:8px; }
</style>

<div class="ac-head">
  <div>
    <h2>AI Costs <span class="sub">SUPER-ADMIN</span></h2>
    <div style="font-size:11px; color:#94a3b8; margin-top:3px;">Every Claude / OpenAI / Gemini / Perplexity call we make. Drives customer-tier pricing.</div>
  </div>
  <div class="ac-range">
    <?php foreach ($range_map as $key => $cfg): ?>
      <a href="?range=<?= e($key) ?>" class="<?= $range === $key ? 'active' : '' ?>"><?= e($cfg['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$table_exists): ?>
  <div class="ac-empty">
    <strong>Logging not enabled yet.</strong><br>
    Run migration <code>046_ai_calls.sql</code> on prod, then come back. All AI calls after that land here in real time.
  </div>
<?php elseif ((int)$summary['total_calls'] === 0): ?>
  <div class="ac-empty">
    No AI calls logged in the <?= e($range_map[$range]['label']) ?>. Trigger a Build redirect map / Generate Plan / AEO recall to seed data.
    <div class="pill">Logging is live — just waiting for traffic.</div>
  </div>
<?php else:
  $total_cost = (float)$summary['total_cost'];
  $total_calls = (int)$summary['total_calls'];
  $avg_cost = $total_calls ? $total_cost / $total_calls : 0;
  $max_day_cost = 0;
  foreach ($by_day as $d) { if ($d['cost'] > $max_day_cost) $max_day_cost = (float)$d['cost']; }
?>
  <div class="ac-cards">
    <div class="ac-card">
      <div class="lbl">Total spend</div>
      <div class="num cost">$<?= number_format($total_cost, 2) ?></div>
      <div class="sub"><?= e($range_map[$range]['label']) ?></div>
    </div>
    <div class="ac-card">
      <div class="lbl">Calls</div>
      <div class="num"><?= number_format($total_calls) ?></div>
      <div class="sub">avg $<?= number_format($avg_cost, 4) ?> / call</div>
    </div>
    <div class="ac-card">
      <div class="lbl">Input tokens</div>
      <div class="num"><?= number_format((int)$summary['total_in'] / 1000, 0) ?>K</div>
      <div class="sub">prompts sent</div>
    </div>
    <div class="ac-card">
      <div class="lbl">Output tokens</div>
      <div class="num"><?= number_format((int)$summary['total_out'] / 1000, 0) ?>K</div>
      <div class="sub">model output</div>
    </div>
    <div class="ac-card">
      <div class="lbl">Customers active</div>
      <div class="num"><?= count(array_filter($by_site, fn($r) => $r['site_id'] !== null)) ?></div>
      <div class="sub">distinct sites this window</div>
    </div>
  </div>

  <?php if (!empty($by_day)): ?>
  <div class="ac-section">
    <h3>Daily spend</h3>
    <div class="desc">One bar per day. Hover-relative bars (tallest = peak day).</div>
    <div class="ac-spark">
      <?php foreach ($by_day as $d):
        $h = $max_day_cost > 0 ? (int)round(((float)$d['cost'] / $max_day_cost) * 100) : 0;
      ?>
        <div class="b" style="height:<?= max(2, $h) ?>%;" title="<?= e($d['d']) ?> · $<?= number_format((float)$d['cost'], 2) ?> · <?= (int)$d['calls'] ?> calls"></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="ac-section">
    <h3>Per customer (drives tier pricing)</h3>
    <div class="desc">If a customer burns more than their plan tier covers, this is where you'll see it.</div>
    <table class="ac-table">
      <thead><tr><th>Customer / Site</th><th class="num">Calls</th><th class="num">Tokens in</th><th class="num">Tokens out</th><th class="num">Spend</th><th></th></tr></thead>
      <tbody>
        <?php
        $max_site_cost = $by_site ? max(array_map(fn($r) => (float)$r['cost'], $by_site)) : 0;
        foreach ($by_site as $s):
          $w = $max_site_cost > 0 ? (int)round(((float)$s['cost'] / $max_site_cost) * 100) : 0;
        ?>
          <tr>
            <td>
              <?php if ($s['site_id']): ?>
                <a href="<?= url('/dashboard/site.php?id=' . (int)$s['site_id']) ?>" style="color:var(--primary); text-decoration:none;"><?= e($s['site_name']) ?></a>
              <?php else: ?>
                <span style="color:#94a3b8;">(global / no site)</span>
              <?php endif; ?>
            </td>
            <td class="num"><?= number_format((int)$s['calls']) ?></td>
            <td class="num"><?= number_format((int)$s['in_tok'] / 1000, 1) ?>K</td>
            <td class="num"><?= number_format((int)$s['out_tok'] / 1000, 1) ?>K</td>
            <td class="cost">$<?= number_format((float)$s['cost'], 4) ?></td>
            <td style="width:80px;"><span class="ac-bar" style="width:<?= $w ?>px;"></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="ac-section">
    <h3>Per feature</h3>
    <div class="desc">Which capability spends the most. Refine prompts on the top rows for the biggest savings.</div>
    <table class="ac-table">
      <thead><tr><th>Feature</th><th class="num">Calls</th><th class="num">Avg / call</th><th class="num">Avg ms</th><th class="num">Total spend</th></tr></thead>
      <tbody>
        <?php foreach ($by_feature as $f): ?>
          <tr>
            <td><code style="font-size:11px; color:#0f172a;"><?= e($f['feature']) ?></code></td>
            <td class="num"><?= number_format((int)$f['calls']) ?></td>
            <td class="num">$<?= number_format((float)$f['avg_cost'], 5) ?></td>
            <td class="num"><?= number_format((int)$f['avg_ms']) ?></td>
            <td class="cost">$<?= number_format((float)$f['cost'], 4) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="ac-section">
    <h3>Per provider / model</h3>
    <div class="desc">If one vendor dominates, that's leverage. If pricing changes, this is where it'll show first.</div>
    <table class="ac-table">
      <thead><tr><th>Provider</th><th>Model</th><th class="num">Calls</th><th class="num">In tokens</th><th class="num">Out tokens</th><th class="num">Total spend</th></tr></thead>
      <tbody>
        <?php foreach ($by_model as $m): ?>
          <tr>
            <td><?= e($m['provider']) ?></td>
            <td><code style="font-size:11px;"><?= e($m['model']) ?></code></td>
            <td class="num"><?= number_format((int)$m['calls']) ?></td>
            <td class="num"><?= number_format((int)$m['in_tok']) ?></td>
            <td class="num"><?= number_format((int)$m['out_tok']) ?></td>
            <td class="cost">$<?= number_format((float)$m['cost'], 4) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div style="font-size:11px; color:#94a3b8; margin-top:14px;">
    Earliest call in window: <?= e($summary['oldest'] ?? '—') ?>.
    Costs computed from per-call rate-card snapshot — update <code>AI_PRICES</code> in <code>includes/ai_cost.php</code> when providers reprice.
  </div>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
