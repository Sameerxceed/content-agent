<?php
/**
 * Dashboard — Interactive Blog Writer.
 * Step 1: AI proposes topics based on keywords, news, trends
 * Step 2: User picks/edits a topic
 * Step 3: AI writes the post → shown in editable form
 * Step 4: User reviews, edits, publishes to CMS
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/haiku.php';
require_once __DIR__ . '/../../includes/performance.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? $_POST['site_id'] ?? 0);
$step = $_POST['step'] ?? $_GET['step'] ?? 'pick-site';

// If a specific site is in play and topics aren't confirmed, bounce to site page.
if ($site_id) {
    $_site_chk = auth_get_accessible_site($db, $site_id);
    if ($_site_chk && empty($_site_chk['topics_confirmed'])) {
        $_SESSION['flash_error'] = 'Please confirm your Business Focus first — without it, AI might write off-topic content.';
        header('Location: ' . url('/dashboard/site.php?id=' . $site_id . '#business-focus'));
        exit;
    }
}

$page_title = '🧠 AI Content Writer';

// Get user's sites (super-admin sees all)
if (auth_is_super_admin()) {
    $stmt = $db->query('SELECT id, name, domain FROM sites ORDER BY name');
} else {
    $stmt = $db->prepare('SELECT id, name, domain FROM sites WHERE user_id = ? ORDER BY name');
    $stmt->execute([$user_id]);
}
$sites = $stmt->fetchAll();

ob_start();
?>

<?php if ($step === 'pick-site' || !$site_id): ?>
    <!-- Step 0: Pick a site -->
    <div class="card" style="max-width:500px;">
        <div class="card-header">Select a site to write for</div>
        <form method="GET">
            <input type="hidden" name="step" value="propose">
            <div class="form-group">
                <select name="site" class="form-control" required>
                    <option value="">Choose a site...</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= e($s['name']) ?> — <?= e($s['domain']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-accent">Next →</button>
        </form>
    </div>

<?php elseif ($step === 'propose'): ?>
    <!-- Step 1: AI proposes topics -->
    <?php
    $site = auth_get_accessible_site($db, $site_id);
    if (!$site) { echo '<div class="alert alert-error">Site not found.</div>'; } else {

    // Gather signals
    $stmt = $db->prepare("SELECT keyword FROM keywords WHERE site_id = ? AND status = 'active' ORDER BY priority DESC LIMIT 15");
    $stmt->execute([$site_id]);
    $keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $db->prepare('SELECT title FROM posts WHERE site_id = ? ORDER BY created_at DESC LIMIT 10');
    $stmt->execute([$site_id]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $topics = json_decode($site['topics'] ?? '[]', true) ?: [];
    $current_year = date('Y');

    // Performance Loop signals — what's already working, what's slipping
    $perf_buckets = ['winners' => [], 'decay' => [], 'dead_air' => [], 'all' => []];
    try { $perf_buckets = performance_buckets($db, $site_id); } catch (PDOException $e) {}

    // Hide posts the user has already dismissed or queued for refresh from the planner
    $hidden_post_ids = [];
    try {
        $h = $db->prepare("SELECT DISTINCT post_id FROM performance_actions WHERE action IN ('dismiss','refresh_queued','refresh_done') AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $h->execute();
        $hidden_post_ids = array_flip($h->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {}
    $winners_filtered = array_values(array_filter($perf_buckets['winners'], fn($p) => !isset($hidden_post_ids[$p['post_id']])));
    $decay_filtered   = array_values(array_filter($perf_buckets['decay'],   fn($p) => !isset($hidden_post_ids[$p['post_id']])));

    // Compact strings for the AI prompt
    $winner_titles = array_map(fn($p) => $p['title'], array_slice($winners_filtered, 0, 5));
    $decay_titles  = array_map(fn($p) => $p['title'], array_slice($decay_filtered,   0, 5));
    $dead_titles   = array_map(fn($p) => $p['title'], array_slice($perf_buckets['dead_air'], 0, 5));

    $perf_block = '';
    if ($winner_titles) $perf_block .= "\n\nWINNING posts (extend these topics): " . implode(' | ', $winner_titles);
    if ($dead_titles)   $perf_block .= "\n\nDEAD topics (avoid these — no traction): " . implode(' | ', $dead_titles);

    // Ask AI for topic proposals
    $result = haiku_chat(
        "You are a content strategist for {$site['name']} ({$site['domain']}). Year: {$current_year}.
Propose 4 blog post topics. Each must be relevant to the business and target SEO keywords.
Lean toward topic clusters that are already working organically — go deeper on winning themes rather than scattering.
Avoid topics similar to ones with no traction.

Output ONLY valid JSON array:
[
  {\"title\": \"Proposed title\", \"description\": \"1 sentence about what to cover\", \"keywords\": [\"kw1\", \"kw2\"], \"type\": \"evergreen|trend|how-to|opinion\"}
]",
        "Business: {$site['name']}\nTopics: " . implode(', ', $topics) . "\nKeywords: " . implode(', ', array_slice($keywords, 0, 10)) . "\nAlready written: " . implode(', ', array_slice($existing, 0, 5)) . $perf_block . "\nAvoid duplicates.",
        1024
    );

    $proposals = [];
    if ($result['success']) {
        $content = preg_replace('/^```(?:json)?\s*/m', '', $result['content']);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $proposals = json_decode(trim($content), true);
        if (!$proposals && preg_match('/\[[\s\S]*\]/m', $content, $m)) {
            $proposals = json_decode($m[0], true);
        }
    }
    ?>

    <?= page_intro('✍', 'AI Content Planner',
        'Propose blog topics tailored to ' . $site['name'] . '\'s keywords and business focus. Pick one, AI writes the full draft, you edit + publish.',
        'content') ?>

    <div class="mb-4" style="font-size:12px; color:var(--text-light);">
        Writing for: <strong style="color:var(--text);"><?= e($site['name']) ?></strong> (<?= e($site['domain']) ?>)
    </div>

    <?php if (!empty($winners_filtered)): ?>
    <div class="card mb-4" style="border-left:3px solid #10b981;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span>🔥 What's working — write more like these (<?= count($winners_filtered) ?>)</span>
            <a href="<?= url('/dashboard/performance.php?site=' . $site_id) ?>" style="font-size:12px;color:var(--primary);text-decoration:none;">Performance →</a>
        </div>
        <?php foreach (array_slice($winners_filtered, 0, 5) as $w): $cms = $w['channels']['cms'] ?? []; ?>
        <div style="padding:10px 0;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:10px;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:13px;color:var(--primary);">"<?= e($w['title']) ?>"</div>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">
                    <?= number_format($cms['clicks'] ?? 0) ?> clicks · <?= number_format($cms['impressions'] ?? 0) ?> impressions · <?= e($w['reason'] ?? '') ?>
                </div>
            </div>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="step" value="write">
                <input type="hidden" name="site_id" value="<?= $site_id ?>">
                <input type="hidden" name="topic" value="More on: <?= e($w['title']) ?>">
                <input type="hidden" name="description" value="Build on the success of '<?= e($w['title']) ?>' — go one level deeper, cover an adjacent angle, or address the follow-up questions a reader would naturally have.">
                <button type="submit" class="btn btn-success btn-sm" style="font-size:11px;padding:4px 12px;">Extend →</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($decay_filtered)): ?>
    <div class="card mb-4" style="border-left:3px solid #f59e0b;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span>📉 Refresh queue — slipping posts (<?= count($decay_filtered) ?>)</span>
            <a href="<?= url('/dashboard/performance.php?site=' . $site_id) ?>" style="font-size:12px;color:var(--primary);text-decoration:none;">View all →</a>
        </div>
        <?php foreach (array_slice($decay_filtered, 0, 4) as $d): $cms = $d['channels']['cms'] ?? []; ?>
        <div style="padding:10px 0;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:10px;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:13px;color:var(--text);">"<?= e($d['title']) ?>"</div>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">
                    <?= number_format($cms['impressions'] ?? 0) ?> impr · CTR <?= isset($cms['ctr']) ? $cms['ctr'] . '%' : '—' ?> · <?= e($d['reason'] ?? '') ?>
                </div>
            </div>
            <button class="btn btn-accent btn-sm" style="font-size:11px;padding:4px 12px;" onclick="refreshPost(<?= (int)$d['post_id'] ?>, this)" title="Cannibalization check + GSC query intent + internal-link suggestions">⚡ Smart Refresh</button>
        </div>
        <?php endforeach; ?>
    </div>
    <script>
    async function refreshPost(postId, btn) {
        btn.disabled = true; btn.textContent = 'Refreshing…';
        try {
            const res = await fetch('<?= url('/api/performance-action.php') ?>', {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({ action:'refresh', post_id: postId })
            });
            const data = await res.json();
            if (data.success && data.new_post_id) {
                btn.textContent = '✓ Draft created';
                btn.style.background = 'var(--success)';
                setTimeout(() => { window.location.href = '<?= url('/dashboard/posts.php?site=' . $site_id) ?>'; }, 600);
            } else {
                btn.disabled = false; btn.textContent = 'Smart Refresh';
                alert(data.error || 'Failed');
            }
        } catch (e) { btn.disabled = false; btn.textContent = 'Smart Refresh'; alert(e.message); }
    }
    </script>
    <?php endif; ?>

    <?php
    // Top open content gaps (from competitor analysis)
    $top_gaps = [];
    try {
        $g_stmt = $db->prepare("SELECT id, topic, competitor_count, estimated_demand, sample_titles
            FROM content_gaps WHERE site_id = ? AND status = 'open'
            ORDER BY competitor_count DESC, estimated_demand DESC LIMIT 5");
        $g_stmt->execute([$site_id]);
        $top_gaps = $g_stmt->fetchAll();
    } catch (PDOException $e) {}
    ?>
    <?php if (!empty($top_gaps)): ?>
    <div class="card mb-4" style="border-left:3px solid #f59e0b;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span>💡 Suggested from competitor gaps (<?= count($top_gaps) ?> shown)</span>
            <a href="<?= url('/dashboard/content-gaps.php?site=' . $site_id) ?>" style="font-size:12px;color:var(--primary);text-decoration:none;">View all →</a>
        </div>
        <?php foreach ($top_gaps as $g): ?>
        <div style="padding:10px 0;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:10px;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:13px;color:var(--primary);"><?= e($g['topic']) ?></div>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">
                    Covered by <strong><?= (int)$g['competitor_count'] ?> competitors</strong>
                    <?php if (!empty($g['estimated_demand']) && $g['estimated_demand'] > 0): ?>
                        · ~<?= number_format($g['estimated_demand']) ?> imp/mo demand
                    <?php endif; ?>
                </div>
            </div>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="step" value="write">
                <input type="hidden" name="site_id" value="<?= $site_id ?>">
                <input type="hidden" name="topic" value="<?= e($g['topic']) ?>">
                <input type="hidden" name="gap_id" value="<?= (int)$g['id'] ?>">
                <button type="submit" class="btn btn-accent btn-sm" style="font-size:11px;padding:4px 12px;">Write this →</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    // Previous posts for this site
    $prev_stmt = $db->prepare('SELECT id, title, slug, status, type, published_at, created_at FROM posts WHERE site_id = ? ORDER BY created_at DESC LIMIT 10');
    $prev_stmt->execute([$site_id]);
    $prev_posts = $prev_stmt->fetchAll();
    ?>
    <?php if (!empty($prev_posts)): ?>
    <div class="card mb-4">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span>📝 Previous Posts (<?= count($prev_posts) ?>) <?= tt('Your most recent drafts and published posts on this site. Quick way to find a post you started and want to finish.') ?></span>
            <a href="<?= url('/dashboard/posts.php?site=' . $site_id) ?>" style="font-size:12px;color:var(--primary);">View All →</a>
        </div>
        <div style="max-height:240px;overflow-y:auto;">
        <?php foreach ($prev_posts as $pp): ?>
            <div style="padding:8px 0;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:8px;">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($pp['title']) ?></div>
                    <div style="font-size:11px;color:#888;">
                        <?= $pp['published_at'] ? format_date($pp['published_at'], 'd M Y') : format_date($pp['created_at'], 'd M Y') ?>
                        · <span class="badge badge-<?= $pp['status'] === 'published' ? 'success' : 'draft' ?>" style="font-size:10px;"><?= $pp['status'] ?></span>
                        · <?= e($pp['type']) ?>
                    </div>
                </div>
                <a href="<?= url('/dashboard/posts.php?action=edit&id=' . $pp['id'] . '&site=' . $site_id) ?>" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 8px;">Edit</a>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($proposals)): ?>
    <div class="card" style="border-left:3px solid #7c3aed;">
        <div class="card-header">
            🧠 AI Proposed Topics <?= tt('Claude generated these from your active keywords, competitor gaps, and posts that are already getting traction. Click "Write this" to have AI draft the full post for your review.') ?>
            <span style="font-size:11px;color:var(--text-light);font-weight:normal;display:block;margin-top:2px;">
                Pick one — AI writes the full draft for you to edit and publish.
                <?php if ($winner_titles || $dead_titles): ?>
                    Informed by <?= count($winner_titles) ?> winning theme<?= count($winner_titles) === 1 ? '' : 's' ?><?php if ($dead_titles): ?>, avoiding <?= count($dead_titles) ?> dead topic<?= count($dead_titles) === 1 ? '' : 's' ?><?php endif; ?>.
                <?php endif; ?>
            </span>
        </div>
        <?php foreach ($proposals as $i => $prop): ?>
        <div style="padding:12px 0;border-bottom:1px solid #f1f5f9;<?= $i === 0 ? '' : '' ?>">
            <form method="POST" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                <input type="hidden" name="step" value="write">
                <input type="hidden" name="site_id" value="<?= $site_id ?>">
                <input type="hidden" name="topic" value="<?= e($prop['title']) ?>">
                <input type="hidden" name="description" value="<?= e($prop['description'] ?? '') ?>">
                <input type="hidden" name="keywords" value="<?= e(implode(', ', $prop['keywords'] ?? [])) ?>">
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:14px;"><?= e($prop['title']) ?></div>
                    <div class="text-sm text-muted"><?= e($prop['description'] ?? '') ?></div>
                    <div class="text-sm" style="margin-top:2px;">
                        <span class="badge badge-info" style="font-size:10px;"><?= e($prop['type'] ?? 'blog') ?></span>
                        <?php foreach (($prop['keywords'] ?? []) as $kw): ?>
                            <span class="badge badge-draft" style="font-size:10px;"><?= e($kw) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-accent btn-sm" style="flex-shrink:0;">Write this →</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Custom topic -->
    <div class="card">
        <div class="card-header">Or write about your own topic <?= tt('Skip the AI suggestions and type any topic you want. AI will still write the full draft, just using your title and keywords as the brief.') ?></div>
        <form method="POST">
            <input type="hidden" name="step" value="write">
            <input type="hidden" name="site_id" value="<?= $site_id ?>">
            <div class="form-group">
                <label>Topic / Title</label>
                <input type="text" name="topic" class="form-control" placeholder="e.g. How to migrate from monolith to microservices" required>
            </div>
            <div class="form-group">
                <label>Target Keywords (comma-separated)</label>
                <input type="text" name="keywords" class="form-control" placeholder="e.g. microservices, migration, architecture" value="<?= e(implode(', ', array_slice($keywords, 0, 5))) ?>">
            </div>
            <button type="submit" class="btn btn-accent">Write this →</button>
        </form>
    </div>

    <script>
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function() {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px;"><svg width="16" height="16" viewBox="0 0 24 24" style="animation:spin 1s linear infinite;"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31" stroke-linecap="round"/></svg> Writing with AI... (30-60 seconds)</span>';
            }
        });
    });
    </script>
    <style>@keyframes spin { to { transform: rotate(360deg); } }</style>

    <?php } ?>

<?php elseif ($step === 'write'): ?>
    <!-- Step 2: AI writes the post → editable preview -->
    <?php
    $topic = $_POST['topic'] ?? '';
    $keywords_str = $_POST['keywords'] ?? '';
    $description = $_POST['description'] ?? '';

    $site = auth_get_accessible_site($db, $site_id);

    if (!$site || !$topic) {
        echo '<div class="alert alert-error">Missing site or topic.</div>';
    } else {
        $brand_tone = $site['brand_tone'] ?? 'professional';
        $kw_array = array_filter(array_map('trim', explode(',', $keywords_str)));
        $prompt_topic = $description ? "{$topic}. {$description}" : $topic;

        // If user came from a content gap, mark it as planned now
        $gap_id = (int)($_POST['gap_id'] ?? $_GET['gap_id'] ?? 0);
        if ($gap_id) {
            try {
                $db->prepare("UPDATE content_gaps SET status = 'planned' WHERE id = ? AND site_id = ?")->execute([$gap_id, $site_id]);
            } catch (PDOException $e) {}
        }

        // Look up SERP brief if any of the target keywords has one
        $serp_brief = null;
        $brief_keyword = null;
        if (!empty($kw_array)) {
            $placeholders = implode(',', array_fill(0, count($kw_array), '?'));
            $params = array_merge([$site_id], array_values($kw_array));
            $bstmt = $db->prepare("SELECT keyword, serp_brief FROM keywords WHERE site_id = ? AND keyword IN ({$placeholders}) AND serp_brief IS NOT NULL ORDER BY priority DESC LIMIT 1");
            $bstmt->execute($params);
            $brow = $bstmt->fetch();
            if ($brow && !empty($brow['serp_brief'])) {
                $serp_brief = json_decode($brow['serp_brief'], true);
                $brief_keyword = $brow['keyword'];
            }
        }

        // Write the post (pass site + optional SERP brief)
        $result = haiku_write_blog($prompt_topic, $brand_tone, $kw_array, rand(config('agent_min_word_count'), config('agent_max_word_count')), $site, $serp_brief);

        $post = $result['parsed'] ?? null;
        $error = null;

        if (!$result['success']) {
            $error = $result['error'];
        } elseif (!$post || empty($post['title'])) {
            $error = 'Could not parse AI response.';
        }
    ?>

    <div class="mb-4">
        <span class="text-muted">Writing for:</span> <strong><?= e($site['name']) ?></strong>
    </div>

    <?php if ($serp_brief && !$error): ?>
    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#065f46;">
        <strong>📊 SERP brief used for "<?= e($brief_keyword) ?>".</strong>
        The AI modelled this post on what's currently ranking on Google:
        <?= !empty($serp_brief['format']) ? e($serp_brief['format']) . ', ' : '' ?>
        <?= !empty($serp_brief['avg_word_count']) ? '~' . (int)$serp_brief['avg_word_count'] . ' words, ' : '' ?>
        <?= !empty($serp_brief['intent']) ? e($serp_brief['intent']) . ' intent.' : '' ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error">Error: <?= e($error) ?></div>
        <a href="<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>" class="btn btn-outline">← Try Again</a>
    <?php else: ?>

    <div class="alert alert-success">
        Post generated! Review and edit below, then publish.
    </div>

    <form method="POST" action="<?= url('/dashboard/write.php') ?>">
        <input type="hidden" name="step" value="publish">
        <input type="hidden" name="site_id" value="<?= $site_id ?>">

        <div class="grid-2">
            <div>
                <div class="card">
                    <div class="card-header">Content</div>
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" name="title" class="form-control" value="<?= e($post['title']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Excerpt</label>
                        <textarea name="excerpt" class="form-control" rows="3"><?= e($post['excerpt'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Body (HTML) — <?= str_word_count(strip_tags($post['body'])) ?> words</label>
                        <textarea name="body" class="form-control" rows="20" style="font-family:monospace;font-size:12px;" required><?= e($post['body']) ?></textarea>
                    </div>
                </div>
            </div>
            <div>
                <div class="card">
                    <div class="card-header">SEO</div>
                    <div class="form-group">
                        <label>SEO Title (<?= mb_strlen($post['seo_title'] ?? '') ?>/60)</label>
                        <input type="text" name="seo_title" class="form-control" value="<?= e($post['seo_title'] ?? '') ?>" maxlength="70">
                    </div>
                    <div class="form-group">
                        <label>Meta Description (<?= mb_strlen($post['seo_description'] ?? '') ?>/160)</label>
                        <textarea name="seo_description" class="form-control" rows="3" maxlength="170"><?= e($post['seo_description'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Keywords</label>
                        <input type="text" name="seo_keywords" class="form-control" value="<?= e($keywords_str) ?>">
                    </div>
                    <div class="form-group">
                        <label>Tags (comma-separated)</label>
                        <input type="text" name="tags" class="form-control" value="<?= e(implode(', ', $post['tags'] ?? [])) ?>">
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Save</div>
                    <div class="form-group">
                        <label style="display:block;font-size:13px;cursor:pointer;padding:6px 0;">
                            <input type="radio" name="publish_action" value="draft" checked> Save as Draft — Recommended
                        </label>
                        <label style="display:block;font-size:13px;cursor:pointer;padding:6px 0;">
                            <input type="radio" name="publish_action" value="publish_cms"> Publish to Blog now
                        </label>
                        <div style="font-size:11px;color:#64748b;margin-top:8px;line-height:1.5;">
                            After saving, you'll land on the post page where you can <strong>generate and publish each social channel separately</strong> (LinkedIn, Twitter, Reddit, Newsletter) — each with its own preview, edits, and schedule.
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn btn-accent" style="padding:10px 24px;">Save →</button>
                        <a href="<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>" class="btn btn-outline">← Back to topics</a>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <?php endif; } ?>

<?php elseif ($step === 'publish'): ?>
    <!-- Step 3: Save and optionally push to CMS -->
    <?php
    $site = auth_get_accessible_site($db, $site_id);

    if (!$site) { echo '<div class="alert alert-error">Site not found.</div>'; } else {
        $title = trim($_POST['title'] ?? '');
        $body = $_POST['body'] ?? '';
        $action = $_POST['publish_action'] ?? 'draft';

        $slug_words = array_slice(explode('-', slugify($title)), 0, 6);
        $slug = implode('-', $slug_words);

        // Ensure unique
        $check = $db->prepare('SELECT COUNT(*) FROM posts WHERE site_id = ? AND slug = ?');
        $check->execute([$site_id, $slug]);
        if ($check->fetchColumn() > 0) $slug .= '-' . date('md');

        $status = ($action === 'draft') ? 'draft' : 'published';

        $stmt = $db->prepare('INSERT INTO posts (site_id, title, slug, body, excerpt, seo_title, seo_description, seo_keywords, type, tags, status, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $site_id,
            $title,
            $slug,
            $body,
            mb_substr(trim($_POST['excerpt'] ?? ''), 0, 500),
            mb_substr(trim($_POST['seo_title'] ?? ''), 0, 70),
            mb_substr(trim($_POST['seo_description'] ?? ''), 0, 170),
            mb_substr(trim($_POST['seo_keywords'] ?? ''), 0, 255),
            'blog',
            json_encode(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')))),
            $status,
            $status === 'published' ? now() : null,
        ]);

        $post_id = $db->lastInsertId();

        // Publish via the new channels pipeline (multi-channel).
        $publish_results = [];
        $selected_channels = [];

        if ($action === 'publish_channels') {
            $selected_channels = is_array($_POST['channels'] ?? null) ? array_values($_POST['channels']) : [];
            // Back-compat: old "publish_cms" callers
        } elseif ($action === 'publish_cms') {
            $selected_channels = ['cms'];
        }

        if (!empty($selected_channels)) {
            require_once __DIR__ . '/../../includes/channels/registry.php';
            $registry = channels_registry();
            $created = $registry->queue_publish($db, (int)$post_id, $selected_channels);

            foreach ($created as $cid => $row_id) {
                $publish_results[$cid] = $registry->publish_row($db, $row_id);
            }

            $any_ok = false;
            foreach ($publish_results as $r) { if (!empty($r['success'])) { $any_ok = true; break; } }

            $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
                $site_id, 'publish_via_channels',
                json_encode(['post_id' => $post_id, 'slug' => $slug, 'channels' => array_keys($publish_results), 'any_ok' => $any_ok]),
                $any_ok ? 'success' : 'fail',
            ]);
        }
    ?>

    <?php if (!empty($publish_results)): ?>
        <?php
        $ok_channels   = array_keys(array_filter($publish_results, fn($r) => !empty($r['success'])));
        $fail_channels = array_keys(array_filter($publish_results, fn($r) => empty($r['success'])));
        ?>
        <?php if (!empty($ok_channels)): ?>
        <div class="alert alert-success">
            ✓ Published to: <strong><?= e(implode(', ', $ok_channels)) ?></strong>
            <ul style="margin:6px 0 0;padding-left:18px;font-size:13px;">
            <?php foreach ($ok_channels as $cid): if (!empty($publish_results[$cid]['external_url'])): ?>
                <li><?= e($cid) ?>: <a href="<?= e($publish_results[$cid]['external_url']) ?>" target="_blank" style="color:#065f46;font-weight:600;">View ↗</a></li>
            <?php endif; endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php if (!empty($fail_channels)): ?>
        <div class="alert alert-error">
            ✗ Failed on: <strong><?= e(implode(', ', $fail_channels)) ?></strong>. You can retry from the post page.
            <ul style="margin:6px 0 0;padding-left:18px;font-size:13px;">
            <?php foreach ($fail_channels as $cid): ?>
                <li><?= e($cid) ?>: <?= e($publish_results[$cid]['error'] ?? 'unknown error') ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    <?php elseif ($action === 'publish_channels' || $action === 'publish_cms'): ?>
        <div class="alert alert-warning">
            Saved locally. No configured channels were selected. Connect a channel and try again from the post page.
        </div>
    <?php elseif ($action === 'draft'): ?>
        <div class="alert alert-info">Saved as draft. Go to Posts to review and publish later.</div>
    <?php else: ?>
        <div class="alert alert-success">Published locally.</div>
    <?php endif; ?>

    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px 14px;margin-top:14px;">
        <div style="font-weight:600;font-size:13px;color:#065f46;margin-bottom:4px;">📡 Distribute this post to social channels</div>
        <div style="font-size:12px;color:#065f46;">From the post page, you can generate LinkedIn / Twitter / Reddit / Newsletter versions, edit them, and publish or schedule each one independently.</div>
        <div style="margin-top:10px;">
            <a href="<?= url('/dashboard/posts.php?action=edit&id=' . $post_id . '&site=' . $site_id) ?>" class="btn btn-accent" style="text-decoration:none;">Open post page →</a>
        </div>
    </div>

    <div class="flex gap-2 mt-4">
        <?php if ($status === 'published'): ?>
            <a href="<?= url('/blog/post.php?site=' . $site_id . '&slug=' . urlencode($slug)) ?>" class="btn btn-outline" style="text-decoration:none;" target="_blank">Preview on Blog →</a>
        <?php endif; ?>
        <a href="<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>" class="btn btn-outline" style="text-decoration:none;">Write another</a>
        <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" class="btn btn-outline" style="text-decoration:none;">Back to Site</a>
    </div>

    <?php } ?>

<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
