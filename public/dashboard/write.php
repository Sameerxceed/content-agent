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

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? $_POST['site_id'] ?? 0);
$step = $_GET['step'] ?? $_POST['step'] ?? 'pick-site';

$page_title = '🧠 AI Content Writer';

// Get user's sites
$stmt = $db->prepare('SELECT id, name, domain FROM sites WHERE user_id = ? ORDER BY name');
$stmt->execute([$user_id]);
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
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([$site_id, $user_id]);
    $site = $stmt->fetch();
    if (!$site) { echo '<div class="alert alert-error">Site not found.</div>'; } else {

    // Gather signals
    $stmt = $db->prepare('SELECT keyword FROM keywords WHERE site_id = ? ORDER BY priority DESC LIMIT 15');
    $stmt->execute([$site_id]);
    $keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $db->prepare('SELECT title FROM posts WHERE site_id = ? ORDER BY created_at DESC LIMIT 10');
    $stmt->execute([$site_id]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $topics = json_decode($site['topics'] ?? '[]', true) ?: [];
    $current_year = date('Y');

    // Ask AI for topic proposals
    $result = haiku_chat(
        "You are a content strategist for {$site['name']} ({$site['domain']}). Year: {$current_year}.
Propose 4 blog post topics. Each must be relevant to the business and target SEO keywords.

Output ONLY valid JSON array:
[
  {\"title\": \"Proposed title\", \"description\": \"1 sentence about what to cover\", \"keywords\": [\"kw1\", \"kw2\"], \"type\": \"evergreen|trend|how-to|opinion\"}
]",
        "Business: {$site['name']}\nTopics: " . implode(', ', $topics) . "\nKeywords: " . implode(', ', array_slice($keywords, 0, 10)) . "\nAlready written: " . implode(', ', array_slice($existing, 0, 5)) . "\nAvoid duplicates.",
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

    <div class="mb-4">
        <span class="text-muted">Writing for:</span> <strong><?= e($site['name']) ?></strong> (<?= e($site['domain']) ?>)
    </div>

    <?php if (!empty($proposals)): ?>
    <div class="card">
        <div class="card-header">🧠 AI Proposed Topics — Pick one or write your own</div>
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
        <div class="card-header">Or write about your own topic</div>
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

    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([$site_id, $user_id]);
    $site = $stmt->fetch();

    if (!$site || !$topic) {
        echo '<div class="alert alert-error">Missing site or topic.</div>';
    } else {
        $brand_tone = $site['brand_tone'] ?? 'professional';
        $kw_array = array_filter(array_map('trim', explode(',', $keywords_str)));
        $prompt_topic = $description ? "{$topic}. {$description}" : $topic;

        // Write the post
        $result = haiku_write_blog($prompt_topic, $brand_tone, $kw_array, rand(config('agent_min_word_count'), config('agent_max_word_count')));

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
                    <div class="card-header">Publish</div>
                    <div class="form-group">
                        <label>Action</label>
                        <select name="publish_action" class="form-control">
                            <option value="draft">Save as Draft (review later)</option>
                            <option value="publish_local">Publish locally only</option>
                            <option value="publish_cms" selected>Publish to CMS (live on website)</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn btn-accent" style="padding:10px 24px;">Publish →</button>
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
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([$site_id, $user_id]);
    $site = $stmt->fetch();

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
            trim($_POST['excerpt'] ?? ''),
            trim($_POST['seo_title'] ?? ''),
            trim($_POST['seo_description'] ?? ''),
            trim($_POST['seo_keywords'] ?? ''),
            'blog',
            json_encode(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')))),
            $status,
            $status === 'published' ? now() : null,
        ]);

        $post_id = $db->lastInsertId();

        // Push to CMS if requested
        $cms_result = null;
        if ($action === 'publish_cms' && !empty($site['cms_url']) && !empty($site['cms_api_key'])) {
            require_once __DIR__ . '/../../includes/cms-connector.php';
            $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
            $stmt->execute([$post_id]);
            $post_data = $stmt->fetch();
            $cms_result = cms_push_post($post_data, $site['cms_url'], $site['cms_api_key']);

            $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
                $site_id, 'publish_to_cms',
                json_encode(['post_id' => $post_id, 'slug' => $slug, 'result' => $cms_result['success']]),
                $cms_result['success'] ? 'success' : 'fail',
            ]);
        }
    ?>

    <?php if ($action === 'publish_cms' && $cms_result && $cms_result['success']): ?>
        <div class="alert alert-success">
            Published to <strong><?= e($site['domain']) ?></strong>!
            <a href="https://<?= e($site['domain']) ?>/blog/<?= e($cms_result['slug'] ?? $slug) ?>" target="_blank" style="color:#065f46;font-weight:600;">View on site →</a>
        </div>
    <?php elseif ($action === 'publish_cms' && $cms_result): ?>
        <div class="alert alert-error">
            Saved locally but CMS push failed: <?= e($cms_result['error'] ?? 'Unknown error') ?>
        </div>
    <?php elseif ($action === 'draft'): ?>
        <div class="alert alert-info">Saved as draft. Go to Posts to review and publish later.</div>
    <?php else: ?>
        <div class="alert alert-success">Published locally.</div>
    <?php endif; ?>

    <div class="flex gap-2 mt-4">
        <?php if ($status === 'published'): ?>
            <a href="<?= url('/blog/post.php?site=' . $site_id . '&slug=' . urlencode($slug)) ?>" class="btn btn-primary" style="text-decoration:none;" target="_blank">Preview on Blog →</a>
        <?php endif; ?>
        <a href="<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>" class="btn btn-accent" style="text-decoration:none;">Write another →</a>
        <a href="<?= url('/dashboard/posts.php?action=edit&id=' . $post_id . '&site=' . $site_id) ?>" class="btn btn-outline" style="text-decoration:none;">Edit Post</a>
        <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" class="btn btn-outline" style="text-decoration:none;">Back to Site</a>
    </div>

    <?php } ?>

<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
