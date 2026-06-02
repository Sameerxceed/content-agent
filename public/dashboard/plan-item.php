<?php
/**
 * Dashboard — Plan Item Studio.
 *
 * Where the user reviews a drafted plan item before approving it for
 * publication across all channels. Layout maps to the user's actual journey:
 *   1. Header — what this is, who it's for, status, primary CTA
 *   2. Hero image — the visual, with Upload/AI/Stock chooser
 *   3. Blog tab (default open) — title, SEO meta, full body
 *   4. Variant tabs — FAQ · LinkedIn · Twitter · Reddit · Newsletter · Schema
 *   5. Footer — Approve & Schedule, Regenerate, Skip
 * Sidebar carries the metadata (forecast, keyword info, cluster siblings).
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$item_id = (int)($_GET['id'] ?? 0);
if (!$item_id) { http_response_code(400); exit('Missing id'); }

// Load item + plan + cluster + primary keyword + post (if drafted)
$stmt = $db->prepare("SELECT i.*, p.site_id AS plan_site, p.cadence_posts_per_week, p.rolling_horizon_weeks,
        c.name AS cluster_name, c.angle AS cluster_angle,
        k.keyword AS primary_keyword, k.buyer_question, k.intent AS keyword_intent,
        k.search_volume, k.difficulty, k.cpc,
        post.id AS post_id_loaded, post.title AS post_title, post.body AS post_body,
        post.excerpt AS post_excerpt, post.seo_title AS post_seo_title, post.seo_description AS post_seo_desc,
        post.status AS post_status, post.hero_image_url, post.hero_image_prompt, post.hero_image_provider, post.hero_image_alt
    FROM content_plan_items i
    JOIN content_plans p          ON i.plan_id = p.id
    JOIN content_plan_clusters c  ON i.cluster_id = c.id
    JOIN keywords k               ON i.primary_keyword_id = k.id
    LEFT JOIN posts post          ON post.id = i.post_id
    WHERE i.id = ?");
$stmt->execute([$item_id]);
$item = $stmt->fetch();
if (!$item) { http_response_code(404); exit('Plan item not found'); }

$site_id = (int)$item['site_id'];
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(403); exit('Access denied'); }

// Secondary keywords
$secondary_ids = json_decode($item['secondary_keyword_ids'] ?? '[]', true) ?: [];
$secondaries = [];
if ($secondary_ids) {
    $in = implode(',', array_fill(0, count($secondary_ids), '?'));
    $s2 = $db->prepare("SELECT id, keyword, search_volume, difficulty FROM keywords WHERE id IN ({$in})");
    $s2->execute($secondary_ids);
    $secondaries = $s2->fetchAll();
}

// Cluster siblings
$siblings = $db->prepare("SELECT id, proposed_title, role, target_publish_date, lock_state
    FROM content_plan_items WHERE cluster_id = ? AND id != ? ORDER BY target_publish_date ASC LIMIT 8");
$siblings->execute([(int)$item['cluster_id'], $item_id]);
$siblings = $siblings->fetchAll();

// Channels + variant content (if drafted)
// Prefer live registry resolution (late-binding) so newly-connected channels
// surface even on items planned before the connect. Fall back to the snapshot.
require_once __DIR__ . '/../../includes/channels/registry.php';
require_once __DIR__ . '/../../includes/content_artifacts.php';
$site_row_for_ch = $db->prepare("SELECT * FROM sites WHERE id = ?");
$site_row_for_ch->execute([$site_id]);
$site_row_for_ch = $site_row_for_ch->fetch();
$configured_channel_ids = $site_row_for_ch ? array_keys(channels_registry()->configured_for($site_row_for_ch)) : [];
$channels_for_item = content_artifacts_resolve_channels($configured_channel_ids, (string)$item['content_type']);
if (empty($channels_for_item)) {
    $channels_for_item = json_decode($item['channels'] ?? '[]', true) ?: [];
}
$variants = [];
if ($item['post_id_loaded']) {
    $ch = $db->prepare("SELECT channel, status, variant_content, variant_meta FROM post_channels WHERE post_id = ?");
    $ch->execute([(int)$item['post_id_loaded']]);
    foreach ($ch->fetchAll() as $row) $variants[$row['channel']] = $row;
}

// FAQs aren't stored as their own channel — they live inside the schema JSON-LD
// bundle (as FAQPage.mainEntity) AND inside the blog body_html (Claude embeds
// them as a <section class="faq"> per the prompt). Pull them from the schema
// bundle here so the FAQ tab below has structured Q&A pairs to render.
$faqs = [];
if (!empty($variants['schema']['variant_content'])) {
    $bundle = json_decode((string)$variants['schema']['variant_content'], true);
    if (is_array($bundle)) {
        foreach ($bundle as $block) {
            if (($block['@type'] ?? '') === 'FAQPage' && !empty($block['mainEntity']) && is_array($block['mainEntity'])) {
                foreach ($block['mainEntity'] as $qa) {
                    $q = trim((string)($qa['name'] ?? ''));
                    $a = trim((string)($qa['acceptedAnswer']['text'] ?? ''));
                    if ($q !== '' && $a !== '') $faqs[] = ['q' => $q, 'a' => $a];
                }
                break;
            }
        }
    }
}

$page_title = ($item['proposed_title'] ?: 'Plan item') . ' — ' . $site['name'];
ob_start();

$stepper_active = 'publish';
include __DIR__ . '/_site_stepper.php';

$lock = $item['lock_state'];
$post_status = $item['post_status'] ?? null;
// Once the post is approved, surface that on the header even though lock_state
// remains 'drafted' until cron-publish actually ships it.
$is_approved = ($lock === 'drafted' && $post_status === 'approved');
$is_published = ($lock === 'published' || $post_status === 'published');
$lock_badges = [
    'pipeline'  => ['Pipeline',  '#94a3b8', '#f1f5f9'],
    'committed' => ['Drafting…', '#1e40af', '#dbeafe'],
    'drafted'   => ['Ready for review', '#d97706', '#fef3c7'],
    'published' => ['Published', '#166534', '#dcfce7'],
];
[$lock_label, $lock_fg, $lock_bg] = $lock_badges[$lock] ?? $lock_badges['pipeline'];
if ($is_approved) { $lock_label = 'Approved · scheduled'; $lock_fg = '#065f46'; $lock_bg = '#d1fae5'; }
if ($is_published) { $lock_label = 'Published'; $lock_fg = '#166534'; $lock_bg = '#dcfce7'; }
?>

<style>
.pi-grid { display:grid; grid-template-columns: 1fr 280px; gap:16px; }
@media (max-width: 900px) { .pi-grid { grid-template-columns: 1fr; } }

.pi-header { background:#fff; border:1px solid var(--border); border-radius:8px; padding:14px 18px; margin-bottom:12px; }
.pi-header .top { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
.pi-header h1 { margin:0; font-size:20px; font-weight:600; color:var(--primary); line-height:1.3; }
.pi-header .meta { font-size:12px; color:#64748b; margin-top:6px; display:flex; gap:14px; flex-wrap:wrap; }
.pi-header .meta strong { color:#0f172a; }
.pi-badge { display:inline-block; font-size:11px; font-weight:600; padding:3px 9px; border-radius:10px; }
.pi-actions { display:flex; gap:6px; flex-shrink:0; }
.pi-btn-primary { background:#10b981; color:#fff; border:1px solid #10b981; padding:8px 14px; font-size:13px; font-weight:600; border-radius:6px; cursor:pointer; }
.pi-btn-primary:hover { background:#059669; }
.pi-btn-primary:disabled { background:#94a3b8; border-color:#94a3b8; cursor:not-allowed; }
.pi-btn-outline { background:#fff; color:#475569; border:1px solid var(--border); padding:8px 12px; font-size:12px; border-radius:6px; cursor:pointer; }
.pi-btn-outline:hover { background:#f8fafb; }
.pi-btn-danger  { background:#fff; color:#dc2626; border:1px solid #fecaca; padding:8px 12px; font-size:12px; border-radius:6px; cursor:pointer; }

.pi-hero { background:#fff; border:1px solid var(--border); border-radius:8px; padding:14px; margin-bottom:12px; }
.pi-hero .label { font-size:11px; text-transform:uppercase; letter-spacing:0.6px; color:#64748b; font-weight:600; margin-bottom:8px; }
.pi-hero .preview { width:100%; aspect-ratio: 16/9; background:#f8fafb; border:2px dashed #cbd5e1; border-radius:6px; overflow:hidden; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; color:#94a3b8; }
.pi-hero .preview .empty-icon { font-size:42px; opacity:0.6; }
.pi-hero .preview .empty-text { font-size:13px; font-weight:500; }
.pi-hero .preview .empty-sub { font-size:11px; color:#cbd5e1; }
.pi-blog-preview { background:#fff; border:1px solid var(--border); border-radius:8px; padding:24px 28px; font-size:15px; line-height:1.7; color:#1e293b; max-height:620px; overflow-y:auto; }
.pi-blog-preview h1, .pi-blog-preview h2, .pi-blog-preview h3 { font-weight:600; color:#0f172a; margin:1.5em 0 0.5em; line-height:1.3; }
.pi-blog-preview h1 { font-size:24px; } .pi-blog-preview h2 { font-size:20px; } .pi-blog-preview h3 { font-size:17px; }
.pi-blog-preview h1:first-child, .pi-blog-preview h2:first-child, .pi-blog-preview h3:first-child { margin-top:0; }
.pi-blog-preview p { margin:0.8em 0; }
.pi-blog-preview ul, .pi-blog-preview ol { margin:0.8em 0; padding-left:1.6em; }
.pi-blog-preview li { margin:0.3em 0; }
.pi-blog-preview strong { color:#0f172a; font-weight:600; }
.pi-blog-preview blockquote { border-left:3px solid #6366f1; padding:0.4em 1em; margin:1em 0; color:#475569; background:#f8fafb; }
.pi-faq-list { display:flex; flex-direction:column; gap:8px; }
.pi-faq { background:#fff; border:1px solid var(--border); border-radius:6px; padding:0; transition:border-color 0.15s; }
.pi-faq[open] { border-color:#cbd5e1; }
.pi-faq summary { cursor:pointer; padding:12px 16px; font-size:14px; font-weight:600; color:#0f172a; list-style:none; position:relative; padding-right:36px; user-select:none; }
.pi-faq summary::-webkit-details-marker { display:none; }
.pi-faq summary::after { content:'+'; position:absolute; right:16px; top:50%; transform:translateY(-50%); font-size:18px; font-weight:400; color:#94a3b8; }
.pi-faq[open] summary::after { content:'−'; }
.pi-faq summary:hover { background:#f8fafb; }
.pi-faq-answer { padding:0 16px 14px; font-size:13px; line-height:1.6; color:#475569; }
.pi-hero .preview img { width:100%; height:100%; object-fit:cover; }
.pi-hero .prompt { background:#f8fafb; border:1px dashed var(--border); padding:8px 10px; font-size:11px; color:#475569; border-radius:4px; margin-top:8px; font-family:ui-monospace, monospace; line-height:1.5; max-height:70px; overflow:auto; }
.pi-hero .chooser { display:flex; gap:6px; margin-top:10px; flex-wrap:wrap; }
.pi-hero .provider { font-size:10px; padding:2px 8px; border-radius:10px; background:#f1f5f9; color:#475569; }
.pi-hero .provider.gemini { background:#ede9fe; color:#5b21b6; }
.pi-hero .provider.dalle3 { background:#fce7f3; color:#9d174d; }
.pi-hero .provider.unsplash { background:#dbeafe; color:#1e40af; }
.pi-hero .provider.manual { background:#dcfce7; color:#166534; }

.pi-tabs { background:#fff; border:1px solid var(--border); border-radius:8px; overflow:hidden; }
.pi-tabs-strip { display:flex; gap:0; border-bottom:1px solid var(--border); overflow-x:auto; }
.pi-tabs-strip a { padding:10px 14px; font-size:12px; color:#64748b; text-decoration:none; border-bottom:2px solid transparent; white-space:nowrap; cursor:pointer; }
.pi-tabs-strip a:hover { background:#f8fafb; color:var(--primary); }
.pi-tabs-strip a.active { color:var(--accent); border-bottom-color:var(--accent); font-weight:600; }
.pi-tab-content { padding:18px; min-height:300px; }
.pi-tab-content h3 { font-size:13px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin:0 0 8px; }
.pi-tab-content input.field, .pi-tab-content textarea.field { width:100%; padding:8px 10px; font-size:13px; border:1px solid var(--border); border-radius:4px; margin-bottom:10px; font-family:inherit; }
.pi-tab-content textarea.field { resize:vertical; min-height:80px; }
.pi-tab-content textarea.field.large { min-height:400px; font-family:ui-monospace, monospace; font-size:12px; line-height:1.5; }

.pi-side { display:flex; flex-direction:column; gap:12px; }
.pi-side .card { padding:12px 14px; background:#fff; border:1px solid var(--border); border-radius:8px; }
.pi-side .card h4 { margin:0 0 8px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.6px; color:#64748b; }
.pi-side .kw-list { font-size:12px; }
.pi-side .kw-list li { padding:5px 0; border-bottom:1px solid #f1f5f9; list-style:none; }
.pi-side .kw-list li:last-child { border-bottom:0; }
.pi-side .kw-list strong { color:#0f172a; }
.pi-side .forecast .num { font-size:22px; font-weight:700; color:#5b21b6; }
.pi-side .forecast .sub { font-size:11px; color:#6b21a8; margin-top:2px; }

.pi-footer { background:#f8fafb; border-top:1px solid var(--border); padding:12px 18px; display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:12px; border-radius:0 0 8px 8px; }
.pi-footer .danger { margin-left:auto; }

.empty-pipeline { background:#fff; border:2px dashed #ddd6fe; border-radius:8px; padding:32px; text-align:center; }
.empty-pipeline .icon { font-size:42px; margin-bottom:10px; }
.empty-pipeline .title { font-size:16px; font-weight:600; color:var(--primary); margin-bottom:6px; }
.empty-pipeline .desc { font-size:13px; color:#475569; max-width:480px; margin:0 auto 16px; line-height:1.6; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/plan.php?site=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">← Back to Plan</a>
</div>

<div class="pi-grid">

    <div>
        <!-- Header -->
        <div class="pi-header">
            <div class="top">
                <div style="flex:1;min-width:280px;">
                    <h1><?= e($item['proposed_title'] ?? '(untitled)') ?></h1>
                    <div class="meta">
                        <span class="pi-badge" style="background:<?= $lock_bg ?>;color:<?= $lock_fg ?>;"><?= $lock_label ?></span>
                        <span>📅 <strong><?= e(date('D, d M Y', strtotime($item['target_publish_date']))) ?></strong></span>
                        <span>📚 <?= e($item['cluster_name']) ?></span>
                        <span><?= e(ucfirst($item['role'])) ?> · <?= e(str_replace('_', ' ', $item['content_type'])) ?> · <?= e(str_replace('_', ' ', $item['bucket'])) ?></span>
                    </div>
                </div>
                <?php if ($is_approved || $is_published): ?>
                <div class="pi-actions">
                    <span class="pi-badge" style="background:#d1fae5;color:#065f46;">
                        ✓ <?= $is_published ? 'Published' : 'Scheduled for ' . e(date('D, d M', strtotime($item['target_publish_date']))) ?>
                    </span>
                </div>
                <?php elseif ($lock === 'drafted'): ?>
                <div class="pi-actions">
                    <button class="pi-btn-primary" onclick="approveItem(<?= $item_id ?>)">✓ Approve &amp; Schedule</button>
                    <button class="pi-btn-outline" onclick="regenerateItem(<?= $item_id ?>)" title="Throw away the current draft and regenerate from scratch">🔄 Regenerate</button>
                </div>
                <?php elseif ($lock === 'pipeline'): ?>
                <div class="pi-actions">
                    <button class="pi-btn-primary" onclick="draftNow(<?= $item_id ?>)" title="Don't wait for the scheduled draft date — draft this item now">⚡ Draft now</button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($lock === 'committed'): ?>

            <div class="empty-pipeline">
                <div class="icon" style="animation:pi-spin 1.2s linear infinite;display:inline-block;">⟳</div>
                <div class="title">Drafting in progress…</div>
                <div class="desc">
                    The autopilot is generating blog body, FAQ, schema, and channel variants in one pass.
                    Typically 2-4 minutes. This page will refresh automatically.
                </div>
                <div id="pi-poll-status" style="font-size:11px;color:#94a3b8;margin-top:12px;">Checking again in <span id="pi-poll-counter">10</span>s…</div>
            </div>
            <style>@keyframes pi-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>
            <script>
            (function() {
                let counter = 10;
                const cEl = document.getElementById('pi-poll-counter');
                const tick = setInterval(() => {
                    counter--;
                    if (cEl) cEl.textContent = counter;
                    if (counter <= 0) { clearInterval(tick); location.reload(); }
                }, 1000);
            })();
            </script>

        <?php elseif ($lock === 'pipeline' || !$item['post_id_loaded']): ?>

            <div class="empty-pipeline">
                <div class="icon">📝</div>
                <div class="title">Not drafted yet</div>
                <div class="desc">
                    This item is scheduled for <strong><?= e(date('D, d M Y', strtotime($item['target_publish_date']))) ?></strong>.
                    The autopilot will draft it automatically 5-7 days before that date.
                    Click <strong>Draft now</strong> above if you want to start earlier.
                </div>
                <div style="font-size:11px;color:#94a3b8;">
                    Primary keyword: <strong><?= e($item['primary_keyword']) ?></strong> · Proposed angle: <em><?= e($item['proposed_angle'] ?? '—') ?></em>
                </div>
            </div>

        <?php else: ?>

            <!-- Hero image -->
            <div class="pi-hero">
                <div class="label">Hero image</div>
                <div class="preview">
                    <?php if (!empty($item['hero_image_url'])): ?>
                        <img src="<?= e($item['hero_image_url']) ?>" alt="<?= e($item['hero_image_alt'] ?? '') ?>">
                    <?php else: ?>
                        <div class="empty-icon">🖼️</div>
                        <div class="empty-text">No hero image yet</div>
                        <div class="empty-sub">Upload your own, generate with AI, or pick a stock photo below</div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($item['hero_image_prompt'])): ?>
                    <div style="display:flex;align-items:center;gap:6px;margin-top:8px;">
                        <span class="pi-badge provider <?= e($item['hero_image_provider'] ?? 'none') ?>"><?= e($item['hero_image_provider'] ?? 'pending') ?></span>
                        <span style="font-size:11px;color:#94a3b8;">Prompt:</span>
                    </div>
                    <div class="prompt"><?= e($item['hero_image_prompt']) ?></div>
                <?php endif; ?>
                <div class="chooser">
                    <button class="pi-btn-outline" onclick="document.getElementById('hero-upload').click()">Upload your own</button>
                    <input type="file" id="hero-upload" accept="image/*" style="display:none;" onchange="uploadHeroImage(this, <?= (int)$item['post_id_loaded'] ?>)">
                    <button class="pi-btn-outline" onclick="regenerateImage(<?= (int)$item['post_id_loaded'] ?>, 'gemini')">Generate with AI</button>
                    <button class="pi-btn-outline" onclick="regenerateImage(<?= (int)$item['post_id_loaded'] ?>, 'unsplash')">Use a stock photo</button>
                </div>
                <div id="hero-status" style="font-size:11px;margin-top:6px;"></div>
            </div>

            <!-- Content tabs -->
            <div class="pi-tabs">
                <div class="pi-tabs-strip">
                    <a class="pi-tab active" data-tab="blog">Blog</a>
                    <?php if (!empty($variants['linkedin'])): ?>
                        <a class="pi-tab" data-tab="linkedin">LinkedIn</a>
                    <?php endif; ?>
                    <?php if (!empty($variants['twitter'])): ?>
                        <a class="pi-tab" data-tab="twitter">Twitter</a>
                    <?php endif; ?>
                    <?php if (!empty($variants['reddit'])): ?>
                        <a class="pi-tab" data-tab="reddit">Reddit</a>
                    <?php endif; ?>
                    <?php if (!empty($variants['newsletter'])): ?>
                        <a class="pi-tab" data-tab="newsletter">Newsletter</a>
                    <?php endif; ?>
                    <?php if (!empty($faqs)): ?>
                        <a class="pi-tab" data-tab="faqs">FAQs <span style="font-size:9px;background:#e2e8f0;color:#475569;padding:1px 6px;border-radius:8px;margin-left:2px;"><?= count($faqs) ?></span></a>
                    <?php endif; ?>
                    <?php if (!empty($variants['schema'])): ?>
                        <a class="pi-tab" data-tab="schema" title="Structured data for search engines">Structured data</a>
                    <?php endif; ?>
                </div>

                <div class="pi-tab-content tab-blog" data-tab-content="blog">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 12px;align-items:start;">
                        <div>
                            <h3>Title</h3>
                            <input class="field" value="<?= e($item['post_title'] ?? '') ?>" readonly>
                        </div>
                        <div>
                            <h3>SEO title</h3>
                            <input class="field" value="<?= e($item['post_seo_title'] ?? '') ?>" readonly>
                        </div>
                    </div>
                    <h3>SEO description</h3>
                    <input class="field" value="<?= e($item['post_seo_desc'] ?? '') ?>" readonly>
                    <h3>Body preview</h3>
                    <div class="pi-blog-preview">
                        <?= $item['post_body'] ?? '' ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#94a3b8;margin-top:8px;">
                        <a href="#" onclick="event.preventDefault();document.getElementById('pi-blog-html').style.display=document.getElementById('pi-blog-html').style.display==='none'?'block':'none';" style="color:#6366f1;text-decoration:none;">View raw HTML ⇅</a>
                        <span>Approximate word count: <?= number_format(str_word_count(strip_tags($item['post_body'] ?? ''))) ?></span>
                    </div>
                    <textarea id="pi-blog-html" class="field large" readonly style="display:none;margin-top:8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;"><?= e($item['post_body'] ?? '') ?></textarea>
                </div>

                <?php if (!empty($faqs)): ?>
                <div class="pi-tab-content tab-faqs" data-tab-content="faqs" style="display:none;">
                    <div style="font-size:12px;color:#64748b;margin-bottom:14px;">
                        <strong><?= count($faqs) ?> Q&amp;A pairs</strong> generated by the autopilot. Embedded inline at the end of the blog body AND emitted as <code>FAQPage</code> schema so Google can render expandable Q&amp;A in search results + AI engines can cite individual answers.
                    </div>
                    <div class="pi-faq-list">
                        <?php foreach ($faqs as $i => $qa): ?>
                            <details class="pi-faq" <?= $i < 3 ? 'open' : '' ?>>
                                <summary><?= e($qa['q']) ?></summary>
                                <div class="pi-faq-answer"><?= nl2br(e($qa['a'])) ?></div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php foreach (['linkedin','twitter','reddit','newsletter','schema'] as $ch):
                    if (empty($variants[$ch])) continue;
                    $v = $variants[$ch];
                    $content = (string)$v['variant_content'];
                    $meta = json_decode($v['variant_meta'] ?? 'null', true);
                ?>
                <div class="pi-tab-content tab-<?= $ch ?>" data-tab-content="<?= $ch ?>" style="display:none;">
                    <h3><?= e(ucfirst($ch)) ?> variant</h3>
                    <?php if ($ch === 'twitter'):
                        $thread = json_decode($content ?: '[]', true) ?: [];
                    ?>
                        <?php foreach ($thread as $i => $tweet): ?>
                            <div style="background:#f8fafb;border:1px solid #e2e8f0;padding:10px 12px;border-radius:6px;margin-bottom:6px;">
                                <div style="font-size:10px;color:#94a3b8;margin-bottom:4px;">Tweet <?= $i + 1 ?> / <?= count($thread) ?> · <?= mb_strlen($tweet) ?> chars</div>
                                <div style="font-size:13px;line-height:1.5;"><?= nl2br(e($tweet)) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif ($ch === 'reddit'): ?>
                        <h3>Title</h3>
                        <input class="field" value="<?= e($meta['title'] ?? '') ?>" readonly>
                        <h3>Body</h3>
                        <textarea class="field large" readonly><?= e($content) ?></textarea>
                    <?php elseif ($ch === 'schema'): ?>
                        <?php
                            // Pretty-print the JSON-LD bundle. variant_content stores
                            // a JSON-encoded array of schema dicts; re-encode for display.
                            $decoded = json_decode($content ?: '[]', true);
                            $pretty  = is_array($decoded)
                                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                : $content;
                            $blocks  = is_array($decoded) ? count($decoded) : 0;
                        ?>
                        <div style="font-size:11px;color:#94a3b8;margin-bottom:8px;">
                            <?= $blocks ?> JSON-LD block<?= $blocks === 1 ? '' : 's' ?> · embedded as <code>&lt;script type="application/ld+json"&gt;</code> in the published post
                        </div>
                        <textarea class="field large" readonly style="font-family:ui-monospace,monospace;font-size:11px;"><?= e($pretty) ?></textarea>
                    <?php elseif ($ch === 'newsletter'): ?>
                        <?php if (!empty($meta['subject'])): ?>
                            <h3>Subject line</h3>
                            <input class="field" value="<?= e($meta['subject']) ?>" readonly>
                        <?php endif; ?>
                        <?php if (!empty($meta['preheader'])): ?>
                            <h3>Preheader (inbox preview)</h3>
                            <input class="field" value="<?= e($meta['preheader']) ?>" readonly>
                        <?php endif; ?>
                        <h3>Email body preview</h3>
                        <div class="pi-blog-preview"><?= $content ?></div>
                        <div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#94a3b8;margin-top:8px;">
                            <a href="#" onclick="event.preventDefault();var el=document.getElementById('pi-nl-html');el.style.display=el.style.display==='none'?'block':'none';" style="color:#6366f1;text-decoration:none;">View raw HTML ⇅</a>
                            <span>Approximate word count: <?= number_format(str_word_count(strip_tags($content))) ?></span>
                        </div>
                        <textarea id="pi-nl-html" class="field large" readonly style="display:none;margin-top:8px;font-family:ui-monospace,monospace;font-size:12px;"><?= e($content) ?></textarea>
                    <?php else: ?>
                        <textarea class="field large" readonly><?= e($content) ?></textarea>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Footer with primary action repeated for scrolling visibility -->
            <div class="pi-footer">
                <?php if ($is_approved || $is_published): ?>
                    <div style="font-size:13px;color:#065f46;font-weight:500;">
                        ✓ <?= $is_published
                            ? 'Published'
                            : 'Approved &amp; queued for publication on <strong>' . e(date('D, d M Y', strtotime($item['target_publish_date']))) . '</strong>'
                        ?>
                    </div>
                    <a class="pi-btn-outline" href="<?= url('/dashboard/plan.php?site=' . $site_id) ?>">← Back to Plan</a>
                <?php else: ?>
                    <div style="font-size:11px;color:#94a3b8;">
                        Drafted <?= e(format_date($item['drafted_at'] ?? '')) ?> · Approving will schedule this for publish on <strong><?= e(date('D, d M', strtotime($item['target_publish_date']))) ?></strong>
                    </div>
                    <button class="pi-btn-primary" onclick="approveItem(<?= $item_id ?>)">✓ Approve &amp; Schedule</button>
                    <button class="pi-btn-danger danger" onclick="skipItem(<?= $item_id ?>)" title="Skip this item — removes it from the pipeline">⏭ Skip</button>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>

    <!-- Sidebar: forecast + keywords + cluster siblings -->
    <div class="pi-side">
        <div class="card forecast">
            <h4>Forecast</h4>
            <div class="num"><?= number_format((int)$item['estimated_clicks_at_6mo']) ?> <span style="font-size:13px;font-weight:500;">clicks/mo</span></div>
            <div class="sub">Expected rank #<?= (int)$item['estimated_rank'] ?> · confidence <?= (int)$item['confidence'] ?>%</div>
        </div>

        <div class="card">
            <h4>Primary keyword</h4>
            <ul class="kw-list" style="padding:0;margin:0;">
                <li>
                    <strong><?= e($item['primary_keyword']) ?></strong><br>
                    <span style="font-size:11px;color:#64748b;">
                        Vol <?= number_format((int)($item['search_volume'] ?? 0)) ?> ·
                        Diff <?= (int)($item['difficulty'] ?? 0) ?> ·
                        <?= e($item['keyword_intent']) ?>
                    </span>
                </li>
            </ul>
            <?php if ($secondaries): ?>
                <h4 style="margin-top:14px;">Secondary keywords</h4>
                <ul class="kw-list" style="padding:0;margin:0;">
                    <?php foreach ($secondaries as $sk): ?>
                        <li>
                            <?= e($sk['keyword']) ?>
                            <span style="font-size:10px;color:#94a3b8;">vol <?= number_format((int)($sk['search_volume'] ?? 0)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card">
            <h4>Channels</h4>
            <?php if (empty($channels_for_item)): ?>
                <div style="font-size:12px;color:#64748b;line-height:1.5;">
                    No publishing channels connected yet.
                    <br>
                    <a href="<?= url('/dashboard/integrations.php?site=' . $site_id) ?>" style="color:#7c3aed;font-weight:500;text-decoration:none;">
                        → Connect channels
                    </a>
                    <div style="font-size:11px;color:#94a3b8;margin-top:6px;">
                        Once connected (CMS, LinkedIn, Twitter, Reddit, Newsletter), future drafts will produce variants for each.
                    </div>
                </div>
            <?php else: ?>
                <ul class="kw-list" style="padding:0;margin:0;">
                    <?php foreach ($channels_for_item as $ch):
                        $v = $variants[$ch] ?? null;
                        $st = $v ? $v['status'] : 'pending';
                        $color = match ($st) { 'queued' => '#1e40af', 'published' => '#166534', 'failed' => '#dc2626', default => '#94a3b8' };
                    ?>
                        <li>
                            <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?= $color ?>;margin-right:6px;"></span>
                            <strong><?= e(ucfirst($ch)) ?></strong>
                            <span style="font-size:10px;color:#94a3b8;float:right;"><?= e($st) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($siblings): ?>
        <div class="card">
            <h4>Cluster siblings</h4>
            <ul class="kw-list" style="padding:0;margin:0;">
                <?php foreach ($siblings as $sib): ?>
                    <li>
                        <a href="<?= url('/dashboard/plan-item.php?id=' . (int)$sib['id']) ?>" style="color:var(--primary);text-decoration:none;font-size:12px;">
                            <?php if ($sib['role'] === 'pillar'): ?>📚 <?php endif; ?>
                            <?= e($sib['proposed_title']) ?>
                        </a>
                        <div style="font-size:10px;color:#94a3b8;"><?= e(date('d M', strtotime($sib['target_publish_date']))) ?> · <?= e($sib['lock_state']) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ── Tab switching ──
document.querySelectorAll('.pi-tab').forEach(t => {
    t.addEventListener('click', (e) => {
        e.preventDefault();
        const target = t.dataset.tab;
        document.querySelectorAll('.pi-tab').forEach(x => x.classList.toggle('active', x === t));
        document.querySelectorAll('[data-tab-content]').forEach(c => {
            c.style.display = (c.dataset.tabContent === target) ? '' : 'none';
        });
    });
});

const ITEM_API = '<?= url('/api/plan-item-action.php') ?>';

async function callAction(action, body) {
    const res = await fetch(ITEM_API, {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({...body, action})
    });
    return await res.json();
}

async function approveItem(itemId) {
    if (!confirm('Approve this item and schedule it for publication?')) return;
    const data = await callAction('approve', {item_id: itemId});
    if (data.success) {
        alert('Approved and queued for publish on ' + (data.scheduled_for || 'the target date') + '.');
        location.reload();
    } else {
        alert('Failed: ' + (data.error || 'unknown'));
    }
}

async function regenerateItem(itemId) {
    if (!confirm('Throw away the current draft and regenerate? (~2 min)')) return;
    const data = await callAction('regenerate', {item_id: itemId});
    if (data.success) {
        alert('Regeneration queued. Refresh in a couple of minutes.');
        location.reload();
    } else { alert('Failed: ' + (data.error || 'unknown')); }
}

async function draftNow(itemId) {
    if (!confirm('Draft this item now? (~2-3 min)')) return;
    const btn = document.querySelector('.pi-btn-primary');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Starting…'; }
    const data = await callAction('draft_now', {item_id: itemId});
    if (data.success) {
        // Server flipped lock_state to 'committed' — reload to enter the
        // "Drafting in progress…" view which auto-polls until done.
        location.reload();
    } else {
        alert('Failed: ' + (data.error || 'unknown'));
        if (btn) { btn.disabled = false; btn.textContent = '⚡ Draft now'; }
    }
}

async function skipItem(itemId) {
    if (!confirm('Skip this item? It will be removed from the pipeline. (You can regenerate the plan later.)')) return;
    const data = await callAction('skip', {item_id: itemId});
    if (data.success) {
        window.location = '<?= url('/dashboard/plan.php?site=' . $site_id) ?>';
    } else { alert('Failed: ' + (data.error || 'unknown')); }
}

async function regenerateImage(postId, provider) {
    const status = document.getElementById('hero-status');
    status.innerHTML = '<span style="color:#7c3aed;">⟳ Generating image via ' + provider + '...</span>';
    const data = await callAction('regenerate_image', {post_id: postId, provider: provider});
    if (data.success && data.url) {
        status.innerHTML = '<span style="color:#065f46;">✓ Image generated. Refreshing…</span>';
        setTimeout(() => location.reload(), 800);
    } else {
        status.innerHTML = '<span style="color:#dc2626;">✗ ' + (data.error || 'Failed') + '</span>';
    }
}

async function uploadHeroImage(input, postId) {
    if (!input.files || input.files.length === 0) return;
    const file = input.files[0];
    const fd = new FormData();
    fd.append('hero_image', file);
    fd.append('post_id', postId);
    const status = document.getElementById('hero-status');
    status.innerHTML = '<span style="color:#7c3aed;">⟳ Uploading…</span>';
    try {
        const res = await fetch('<?= url('/api/post-image-upload.php') ?>', { method:'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            status.innerHTML = '<span style="color:#065f46;">✓ Uploaded. Refreshing…</span>';
            setTimeout(() => location.reload(), 600);
        } else {
            status.innerHTML = '<span style="color:#dc2626;">✗ ' + (data.error || 'Failed') + '</span>';
        }
    } catch (e) {
        status.innerHTML = '<span style="color:#dc2626;">✗ ' + e.message + '</span>';
    }
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
