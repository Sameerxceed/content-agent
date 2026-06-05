<?php
/**
 * Dashboard — Plans + per-site assignment.
 *
 * Super-admin only. Two things in one page:
 *
 *  1. Plans table — edit per-tier limits (monthly budget, post cap, AEO
 *     cap, etc.). Tweak Pro's monthly_ai_budget if Anna starts hitting it.
 *  2. Per-site plan assignment — every site lists its current plan, this
 *     month's AI spend, and a dropdown to change tier or set a budget
 *     override (e.g. bump one site to $50 for a migration month).
 *
 * Saves are processed on the same page via POST. No JS required.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/quotas.php';

auth_start();
auth_require();
if (!auth_is_super_admin()) {
    http_response_code(403);
    exit('Super admin only — plan management is operator-level.');
}

$db = require __DIR__ . '/../../includes/db.php';

// Migration check
$migration_ok = false;
try {
    $r = $db->query("SHOW TABLES LIKE 'plans'");
    $migration_ok = $r && $r->fetch();
} catch (Throwable $e) {}

$flash = '';
if ($migration_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_plan') {
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        try {
            $stmt = $db->prepare("UPDATE plans SET
                price_monthly_usd          = ?,
                monthly_ai_budget_usd      = ?,
                max_posts_per_month        = ?,
                max_aeo_queries            = ?,
                max_images_per_month       = ?,
                max_redirect_urls_per_run  = ?,
                max_plan_regens_per_week   = ?,
                max_sites_per_user         = ?
                WHERE id = ?");
            $stmt->execute([
                (float)($_POST['price_monthly_usd'] ?? 0),
                (float)($_POST['monthly_ai_budget_usd'] ?? 0),
                (int)($_POST['max_posts_per_month'] ?? 0),
                (int)($_POST['max_aeo_queries'] ?? 0),
                (int)($_POST['max_images_per_month'] ?? 0),
                (int)($_POST['max_redirect_urls_per_run'] ?? 0),
                (int)($_POST['max_plan_regens_per_week'] ?? 0),
                (int)($_POST['max_sites_per_user'] ?? 0),
                $plan_id,
            ]);
            $flash = '✓ Plan updated.';
        } catch (Throwable $e) {
            $flash = '✗ ' . $e->getMessage();
        }
    }

    if ($action === 'assign_site') {
        $site_id = (int)($_POST['site_id'] ?? 0);
        $new_plan_id = (int)($_POST['new_plan_id'] ?? 0);
        $override_raw = trim((string)($_POST['budget_override'] ?? ''));
        $override = $override_raw === '' ? null : (float)$override_raw;
        try {
            $stmt = $db->prepare("UPDATE sites SET plan_id = ?, plan_budget_override_usd = ? WHERE id = ?");
            $stmt->execute([$new_plan_id, $override, $site_id]);
            $flash = '✓ Site plan updated.';
        } catch (Throwable $e) {
            $flash = '✗ ' . $e->getMessage();
        }
    }

    redirect('/dashboard/plans.php?msg=' . urlencode($flash));
}
if (!empty($_GET['msg'])) $flash = (string)$_GET['msg'];

$plans = $migration_ok ? $db->query("SELECT * FROM plans WHERE is_active = 1 ORDER BY price_monthly_usd ASC")->fetchAll() : [];

// All sites + their plan + this month's spend
$sites = [];
if ($migration_ok) {
    $rows = $db->query("SELECT s.id, s.name, s.domain, s.plan_id, s.plan_budget_override_usd,
            p.code AS plan_code, p.name AS plan_name, p.monthly_ai_budget_usd,
            (SELECT COALESCE(SUM(cost_usd), 0) FROM ai_calls
                WHERE site_id = s.id
                AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS spend_this_month
        FROM sites s LEFT JOIN plans p ON p.id = s.plan_id
        ORDER BY s.name")->fetchAll();
    $sites = $rows;
}

$page_title = 'Plans + Limits';
ob_start();
?>
<style>
.pl-head { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:12px; flex-wrap:wrap; gap:8px; }
.pl-head h2 { margin:0; font-size:18px; color:var(--primary); }
.pl-head .sub { font-size:11px; color:#dc2626; font-weight:600; padding:2px 8px; background:#fee2e2; border-radius:10px; margin-left:8px; }
.pl-flash { background:#d1fae5; color:#065f46; padding:8px 14px; border-radius:6px; margin-bottom:14px; font-size:13px; }
.pl-flash.err { background:#fee2e2; color:#991b1b; }
.pl-section { margin-bottom:24px; }
.pl-section h3 { font-size:11px; text-transform:uppercase; letter-spacing:0.6px; color:#64748b; margin:0 0 8px; }
.pl-section .desc { font-size:11px; color:#94a3b8; margin-bottom:10px; }
.pl-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:10px; }
.pl-card { background:#fff; border:1px solid var(--border); border-radius:8px; padding:14px 16px; }
.pl-card.tier-starter { border-top:3px solid #94a3b8; }
.pl-card.tier-pro     { border-top:3px solid #7c3aed; }
.pl-card.tier-agency  { border-top:3px solid #0c4a6e; }
.pl-card.tier-super   { border-top:3px solid #dc2626; }
.pl-card h4 { margin:0 0 4px; font-size:15px; color:#0f172a; display:flex; justify-content:space-between; align-items:baseline; }
.pl-card h4 .price { font-size:12px; font-weight:500; color:#dc2626; font-family:ui-monospace, monospace; }
.pl-card .code { font-size:10px; color:#94a3b8; text-transform:uppercase; letter-spacing:0.4px; }
.pl-row { display:grid; grid-template-columns: 1fr 80px; gap:6px; padding:5px 0; align-items:center; font-size:12px; border-top:1px solid #f1f5f9; }
.pl-row:first-of-type { border-top:0; }
.pl-row label { color:#475569; }
.pl-row input { width:100%; padding:3px 6px; font-size:12px; border:1px solid var(--border); border-radius:3px; font-family:ui-monospace, monospace; text-align:right; }
.pl-card button { margin-top:8px; font-size:11px; padding:5px 12px; background:var(--primary); color:#fff; border:0; border-radius:4px; cursor:pointer; }
.pl-table { width:100%; background:#fff; border:1px solid var(--border); border-radius:6px; border-collapse:collapse; font-size:12px; }
.pl-table th, .pl-table td { padding:8px 12px; border-bottom:1px solid #f1f5f9; text-align:left; vertical-align:middle; }
.pl-table th { font-size:10px; text-transform:uppercase; color:#94a3b8; letter-spacing:0.4px; background:#f8fafc; }
.pl-table td.num { font-family:ui-monospace, monospace; text-align:right; }
.pl-table tr:last-child td { border-bottom:0; }
.pl-table select, .pl-table input { padding:4px 7px; font-size:12px; border:1px solid var(--border); border-radius:3px; }
.pl-table input { width:60px; font-family:ui-monospace, monospace; text-align:right; }
.pl-spend-bar { display:inline-block; width:60px; height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden; vertical-align:middle; }
.pl-spend-bar > div { height:100%; background:#dc2626; }
.pl-spend-bar > div.warn { background:#d97706; }
.pl-spend-bar > div.ok   { background:#059669; }
.pl-tier-pill { font-size:10px; padding:2px 7px; border-radius:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.4px; }
.pl-tier-pill.starter { background:#f1f5f9; color:#475569; }
.pl-tier-pill.pro     { background:#ede9fe; color:#5b21b6; }
.pl-tier-pill.agency  { background:#dbeafe; color:#1e40af; }
.pl-tier-pill.super   { background:#fee2e2; color:#991b1b; }
.pl-empty { padding:20px; text-align:center; color:#64748b; background:#fef9c3; border:1px solid #fde68a; border-radius:8px; }
.pl-explain { font-size:11px; color:#94a3b8; }
</style>

<div class="pl-head">
  <h2>Plans &amp; Limits <span class="sub">SUPER-ADMIN</span></h2>
  <div style="font-size:11px; color:#64748b;">Master guardrails. Edit tiers + assign sites + override budgets.</div>
</div>

<?php if ($flash): ?>
  <div class="pl-flash <?= str_starts_with($flash, '✗') ? 'err' : '' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<?php if (!$migration_ok): ?>
  <div class="pl-empty">
    Migration <code>052_plans.sql</code> hasn't been run on prod yet. Run it on Linode MySQL, then come back.
  </div>
<?php else: ?>

<div class="pl-section">
  <h3>Subscription tiers</h3>
  <div class="desc">Hard caps per tier. 0 = unlimited. Changes take effect on the next AI call.</div>
  <div class="pl-grid">
    <?php foreach ($plans as $p): ?>
      <form method="POST" class="pl-card tier-<?= e($p['code']) ?>">
        <input type="hidden" name="action" value="update_plan">
        <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
        <h4>
          <?= e($p['name']) ?>
          <span class="price">$<input type="number" step="0.01" name="price_monthly_usd" value="<?= number_format((float)$p['price_monthly_usd'], 2, '.', '') ?>" style="width:60px;">/mo</span>
        </h4>
        <div class="code"><?= e($p['code']) ?></div>
        <div style="margin-top:8px;">
          <div class="pl-row"><label>Monthly AI budget ($)</label><input type="number" step="0.01" name="monthly_ai_budget_usd" value="<?= number_format((float)$p['monthly_ai_budget_usd'], 2, '.', '') ?>"></div>
          <div class="pl-row"><label>Posts / month</label><input type="number" name="max_posts_per_month" value="<?= (int)$p['max_posts_per_month'] ?>"></div>
          <div class="pl-row"><label>AEO tracked queries</label><input type="number" name="max_aeo_queries" value="<?= (int)$p['max_aeo_queries'] ?>"></div>
          <div class="pl-row"><label>Hero images / month</label><input type="number" name="max_images_per_month" value="<?= (int)$p['max_images_per_month'] ?>"></div>
          <div class="pl-row"><label>Redirect URLs / build run</label><input type="number" name="max_redirect_urls_per_run" value="<?= (int)$p['max_redirect_urls_per_run'] ?>"></div>
          <div class="pl-row"><label>Plan regens / week</label><input type="number" name="max_plan_regens_per_week" value="<?= (int)$p['max_plan_regens_per_week'] ?>"></div>
          <div class="pl-row"><label>Max sites / user</label><input type="number" name="max_sites_per_user" value="<?= (int)$p['max_sites_per_user'] ?>"></div>
        </div>
        <button type="submit">Save</button>
        <div class="pl-explain" style="margin-top:6px;">0 = unlimited on this row.</div>
      </form>
    <?php endforeach; ?>
  </div>
</div>

<div class="pl-section">
  <h3>Per-site assignment + spend this month</h3>
  <div class="desc">Each site's current tier, this month's actual AI spend vs budget, and a row-level override field for special cases (e.g. bump Anna to $50 for her migration month).</div>
  <table class="pl-table">
    <thead><tr>
      <th>Site</th>
      <th>Domain</th>
      <th>Current plan</th>
      <th class="num">Spend this month</th>
      <th>Budget bar</th>
      <th>Re-assign</th>
      <th>Override $</th>
      <th></th>
    </tr></thead>
    <tbody>
      <?php foreach ($sites as $s):
        $eff_budget = $s['plan_budget_override_usd'] !== null
            ? (float)$s['plan_budget_override_usd']
            : (float)($s['monthly_ai_budget_usd'] ?? 0);
        $spent      = (float)$s['spend_this_month'];
        $pct        = $eff_budget > 0 ? min(100, (int)round(($spent / $eff_budget) * 100)) : 0;
        $bar_cls    = $pct >= 90 ? '' : ($pct >= 60 ? 'warn' : 'ok');
      ?>
      <tr>
        <form method="POST">
          <input type="hidden" name="action" value="assign_site">
          <input type="hidden" name="site_id" value="<?= (int)$s['id'] ?>">
          <td><a href="<?= url('/dashboard/site.php?id=' . (int)$s['id']) ?>" style="color:var(--primary);text-decoration:none;"><?= e($s['name']) ?></a></td>
          <td style="font-size:11px;color:#64748b;"><?= e($s['domain']) ?></td>
          <td><span class="pl-tier-pill <?= e($s['plan_code'] ?? 'starter') ?>"><?= e($s['plan_code'] ?? '—') ?></span></td>
          <td class="num">$<?= number_format($spent, 4) ?> / $<?= number_format($eff_budget, 2) ?></td>
          <td><span class="pl-spend-bar"><div class="<?= $bar_cls ?>" style="width:<?= $pct ?>%;"></div></span></td>
          <td>
            <select name="new_plan_id">
              <?php foreach ($plans as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$s['plan_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['code']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="number" step="0.01" name="budget_override" value="<?= $s['plan_budget_override_usd'] !== null ? number_format((float)$s['plan_budget_override_usd'], 2, '.', '') : '' ?>" placeholder="(plan default)" title="Per-site monthly budget override. Leave blank to use plan default."></td>
          <td><button type="submit" style="font-size:11px; padding:4px 10px; background:var(--primary); color:#fff; border:0; border-radius:3px; cursor:pointer;">Save</button></td>
        </form>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="pl-section" style="font-size:11px; color:#94a3b8;">
  <strong>How the guardrails fire:</strong> every Claude / OpenAI / Gemini / Perplexity / image-gen call checks the site's monthly budget before hitting the API. Over-budget calls return a structured QUOTA_EXCEEDED error instead of running. The "Super" plan code bypasses all caps — use it for internal sites only.
</div>

<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
