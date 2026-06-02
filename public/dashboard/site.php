<?php
/**
 * Site Command Center — minimal landing.
 *
 * The persistent stepper (rendered by _site_stepper.php) is the primary
 * navigation: workflow steps with health metrics inline. Click a step →
 * its focused page opens. This landing page just shows the stepper plus
 * a single next-action CTA plus a thin row of secondary links to the
 * non-workflow features (Competitors, Brand Presence, AEO, Alerts) that
 * don't fit the linear funnel.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['id'] ?? 0);

if (!$site_id) { redirect('/dashboard/sites.php'); }

$site = auth_get_accessible_site($db, $site_id);

if (!$site) { redirect('/dashboard/sites.php'); }

// ── Secondary-feature counts for the small links below ─────
$competitors_active = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM competitors WHERE site_id = ? AND status = 'active'");
    $stmt->execute([$site_id]);
    $competitors_active = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// Legal docs detection — cheap count for the alert card below.
$legal_missing = 0;
$legal_missing_types = [];
try {
    require_once __DIR__ . '/../../includes/legal_docs.php';
    $rows = $db->prepare("SELECT doc_type FROM legal_docs WHERE site_id = ? AND status = 'missing'");
    $rows->execute([$site_id]);
    $legal_missing_types = $rows->fetchAll(PDO::FETCH_COLUMN);
    $legal_missing = count($legal_missing_types);
} catch (PDOException $e) {
    // legal_docs table may not exist yet (migration not applied) — silently skip
}

$unread_alerts = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM alerts WHERE site_id = ? AND read_at IS NULL");
    $stmt->execute([$site_id]);
    $unread_alerts = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// Business focus state — shown as a warning band only when not confirmed
$topics_confirmed = !empty($site['topics_confirmed']);

$page_title = $site['name'];

ob_start();

// Render the persistent stepper (with topbar enabled — this is the landing).
$stepper_active = null;   // no step is "the current page" on the landing
$stepper_topbar = true;   // landing renders the name + Edit + Reports header
include __DIR__ . '/_site_stepper.php';

// The stepper partial leaves $_stp_current_key behind. Use it for the CTA.
$next_action_labels = [
    'scan'     => 'Start: Scan your website',
    'seo'      => 'Next: Run SEO audit',
    'keywords' => 'Next: Find keywords',
    'publish'  => 'Next: Write & publish your first post',
    'track'    => 'Next: Connect Google Search Console',
    'grow'    => 'Next: Set up Competitors, AEO and more',
];
$next_action_label = $_stp_current_key ? $next_action_labels[$_stp_current_key] : null;
$next_action_href  = $_stp_current_key ? $_stp_steps[$_stp_current_key]['href'] : null;
?>

<style>
.cta-next { display:block; background:var(--accent); color:#fff; padding:14px 20px; border-radius:8px; text-align:center; font-size:14px; font-weight:600; text-decoration:none; margin-bottom:14px; }
.cta-next:hover { background:#a82a00; color:#fff; }

.focus-warn { padding:12px 16px; background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; margin-bottom:14px; }
.focus-warn .title { font-size:13px; font-weight:600; color:#92400e; }
.focus-warn .desc  { font-size:12px; color:#92400e; margin-top:3px; }

.legal-warn { display:flex; justify-content:space-between; align-items:center; gap:14px; padding:14px 18px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; margin-bottom:14px; }
.legal-warn .body { flex:1; }
.legal-warn .title { font-size:13px; font-weight:600; color:#991b1b; }
.legal-warn .desc { font-size:12px; color:#991b1b; margin-top:3px; line-height:1.5; }
.legal-warn .cta { padding:8px 16px; background:#dc2626; color:#fff; border-radius:6px; font-size:12px; font-weight:600; text-decoration:none; white-space:nowrap; }
.legal-warn .cta:hover { background:#b91c1c; color:#fff; }

.aside-strip { display:flex; gap:10px; flex-wrap:wrap; }
.aside-link { flex:1; min-width:160px; display:flex; align-items:center; gap:10px; padding:10px 14px; background:#fff; border:1px solid var(--border); border-radius:8px; text-decoration:none; color:inherit; }
.aside-link:hover { background:#f8fafb; border-color:#cbd5e1; }
.aside-link .icon { font-size:18px; }
.aside-link .label { font-size:13px; font-weight:600; color:var(--primary); }
.aside-link .meta { font-size:11px; color:#64748b; }
.aside-section-label { font-size:11px; color:var(--text-light); text-transform:uppercase; letter-spacing:0.5px; font-weight:600; margin:8px 0 6px; }
</style>

<?php if (!$topics_confirmed): ?>
    <div class="focus-warn">
        <div class="title">⚠ Tell ContentAgent what your business sells</div>
        <div class="desc">AI keyword research and content writing depend on this. <a href="<?= url('/dashboard/sites.php?action=edit&id=' . $site_id . '#focus') ?>" style="color:#92400e;font-weight:600;">Set business focus →</a></div>
    </div>
<?php endif; ?>

<?php if ($legal_missing > 0): ?>
    <div class="legal-warn">
        <div class="body">
            <div class="title">⚠ <?= $legal_missing ?> missing legal document<?= $legal_missing === 1 ? '' : 's' ?> on your website</div>
            <div class="desc">
                Your site is missing <strong><?= e(implode(' · ', array_map('ucfirst', $legal_missing_types))) ?></strong>.
                Required under DPDP (India), GDPR (EU), CCPA (US). ContentAgent can generate these for your business and publish them in one click — jurisdiction-aware, tailored to what your business actually does.
            </div>
        </div>
        <a href="<?= url('/dashboard/legal.php?site=' . $site_id) ?>" class="cta">Generate &amp; publish →</a>
    </div>
<?php endif; ?>

<?php if ($next_action_label): ?>
    <a href="<?= e($next_action_href) ?>" class="cta-next"><?= e($next_action_label) ?> →</a>
<?php else: ?>
    <div style="padding:12px 16px;background:#ecfdf5;border:1px solid #86efac;border-radius:8px;margin-bottom:14px;color:#065f46;font-size:13px;">
        ✓ Your site is fully set up. Use the stepper above to jump to any stage.
    </div>
<?php endif; ?>

<?php // The "More for this site" strip moved into the new Grow step in the stepper. ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
