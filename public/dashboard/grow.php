<?php
/**
 * Dashboard — Grow: the "what to do once the basics are set up" page.
 *
 * Houses the advanced/intel features that don't fit the core
 * Scan→SEO→Keywords→Publish→Track funnel:
 *   - Competitors (track + content gaps)
 *   - Brand Presence (Reddit/Quora/LinkedIn engagement)
 *   - AEO Tracker (Perplexity/ChatGPT citation tracking)
 *   - AI Discoverability (llms.txt, schema, AI crawlers)
 *   - Alerts (ranking-drop / broken-link notifications)
 *
 * Each is a card that opens its dedicated page.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';

$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

// ── Counts for each card's status line ─────────────────────
$competitors_active = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM competitors WHERE site_id = ? AND status = 'active'");
    $stmt->execute([$site_id]);
    $competitors_active = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

$open_gaps = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM content_gaps WHERE site_id = ? AND status = 'open'");
    $stmt->execute([$site_id]);
    $open_gaps = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

$aeo_queries = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM aeo_queries WHERE site_id = ?");
    $stmt->execute([$site_id]);
    $aeo_queries = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

$unread_alerts = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM alerts WHERE site_id = ? AND read_at IS NULL");
    $stmt->execute([$site_id]);
    $unread_alerts = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

$page_title = 'Grow — ' . $site['name'];

ob_start();

// Persistent site workflow stepper at top
$stepper_active = 'grow';
include __DIR__ . '/_site_stepper.php';
?>

<style>
.grow-intro { padding:12px 16px; background:#f5f0ff; border:1px solid #d8b4fe; border-radius:8px; margin-bottom:14px; }
.grow-intro .title { font-weight:600; font-size:13px; color:#6b21a8; }
.grow-intro .desc  { font-size:12px; color:#6b21a8; margin-top:3px; line-height:1.5; }

.grow-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:12px; }
.grow-card { display:flex; flex-direction:column; gap:8px; padding:16px; background:#fff; border:1px solid var(--border); border-radius:8px; text-decoration:none; color:inherit; transition:background 0.1s, border-color 0.1s; }
.grow-card:hover { background:#f8fafb; border-color:#cbd5e1; }
.grow-card .head { display:flex; align-items:center; gap:10px; }
.grow-card .icon { width:36px; height:36px; border-radius:8px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.grow-card .title { font-size:14px; font-weight:600; color:var(--primary); }
.grow-card .desc { font-size:12px; color:#64748b; line-height:1.5; }
.grow-card .meta { font-size:11px; color:#475569; margin-top:auto; padding-top:8px; border-top:1px solid #f1f5f9; }
.grow-card .meta strong { color:var(--primary); }
</style>

<div class="grow-intro">
    <div class="title">🌱 Grow beyond the basics</div>
    <div class="desc">Your content pipeline is running. These are the next-level features — competitive intel, off-site engagement, AI-engine tracking — that compound your results over months.</div>
</div>

<div class="grow-grid">
    <a href="<?= url('/dashboard/competitors.php?site=' . $site_id) ?>" class="grow-card">
        <div class="head">
            <span class="icon">⚔️</span>
            <span class="title">Competitors</span>
        </div>
        <div class="desc">Track what competitors publish, find content gaps you can fill, watch their keyword movements.</div>
        <div class="meta">
            <?php if ($competitors_active > 0): ?>
                <strong><?= $competitors_active ?></strong> tracked<?php if ($open_gaps > 0): ?> · <strong><?= $open_gaps ?></strong> open content gap<?= $open_gaps === 1 ? '' : 's' ?><?php endif; ?>
            <?php else: ?>
                Not set up yet
            <?php endif; ?>
        </div>
    </a>

    <a href="<?= url('/dashboard/ai-presence.php?site=' . $site_id) ?>" class="grow-card">
        <div class="head">
            <span class="icon">🏢</span>
            <span class="title">Brand Presence</span>
        </div>
        <div class="desc">Find live conversations about your topics on Reddit, Quora, LinkedIn — join with AI-drafted replies grounded in your content.</div>
        <div class="meta">Reddit · Quora · LinkedIn</div>
    </a>

    <a href="<?= url('/dashboard/aeo.php?site=' . $site_id) ?>" class="grow-card">
        <div class="head">
            <span class="icon">🎯</span>
            <span class="title">AEO Tracker</span>
        </div>
        <div class="desc">Track when Perplexity, ChatGPT and other answer engines cite your site. Citation share is the new SEO.</div>
        <div class="meta">
            <?php if ($aeo_queries > 0): ?>
                <strong><?= $aeo_queries ?></strong> queries tracked
            <?php else: ?>
                Not set up yet
            <?php endif; ?>
        </div>
    </a>

    <a href="<?= url('/dashboard/ai-seo.php?site=' . $site_id) ?>" class="grow-card">
        <div class="head">
            <span class="icon">🤖</span>
            <span class="title">AI Discoverability</span>
        </div>
        <div class="desc">llms.txt, schema.org, AI crawler rules — make your site easy for ChatGPT, Claude and Perplexity to cite.</div>
        <div class="meta">llms.txt · schema · AI crawlers</div>
    </a>

    <a href="<?= url('/dashboard/alerts.php?site=' . $site_id) ?>" class="grow-card">
        <div class="head">
            <span class="icon">🔔</span>
            <span class="title">Alerts</span>
        </div>
        <div class="desc">Automated watchers fire when rankings drop, links break, or new mentions land. Don't watch dashboards — let them call you.</div>
        <div class="meta">
            <?php if ($unread_alerts > 0): ?>
                <strong><?= $unread_alerts ?></strong> unread notification<?= $unread_alerts === 1 ? '' : 's' ?>
            <?php else: ?>
                All clear
            <?php endif; ?>
        </div>
    </a>
</div>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
