<?php
/**
 * AI Presence Builder — Discover conversations & engage across platforms.
 *
 * Shows all platforms, discovers conversations via web search/APIs,
 * generates AI-powered replies for users to copy and post.
 *
 * GET ?site=3
 * GET ?site=3&action=scan — scan for conversations
 * GET ?site=3&action=scan&platform=reddit — scan specific platform
 * POST — handle reply generation, status updates, edits
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai-presence.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$site_id, $user_id]);
$site = $stmt->fetch();
if (!$site) { redirect('/dashboard/index.php'); }

$action = $_GET['action'] ?? '';
$filter_platform = $_GET['platform'] ?? '';

// ── Handle POST actions (AJAX) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $post_action = $input['action'] ?? '';

    if ($post_action === 'draft_reply') {
        $conv = [
            'platform' => $input['platform'] ?? '',
            'source_title' => $input['title'] ?? '',
            'source_content' => $input['content'] ?? '',
            'title' => $input['title'] ?? '',
            'snippet' => $input['content'] ?? '',
        ];
        $result = presence_draft_reply($site, $conv, $db);
        json_response($result);
    }

    if ($post_action === 'save') {
        $id = presence_save($db, $site_id, $input);
        json_response(['success' => true, 'id' => $id]);
    }

    if ($post_action === 'update_status') {
        $ok = presence_update_status($db, (int)$input['id'], $site_id, $input['status']);
        json_response(['success' => $ok]);
    }

    if ($post_action === 'update_reply') {
        $ok = presence_update_reply($db, (int)$input['id'], $site_id, $input['reply']);
        json_response(['success' => $ok]);
    }

    json_response(['error' => 'Unknown action'], 400);
}

// ── Scan for conversations ─────────────────────────────────
$scan_results = null;
if ($action === 'scan') {
    $platforms_to_scan = $filter_platform ? [$filter_platform] : ['reddit', 'quora', 'linkedin', 'twitter', 'hackernews'];
    $scan_results = presence_scan_conversations($site, $db, $platforms_to_scan);
}

// Get stored content
$stored = presence_get_all($db, $site_id, $filter_platform ?: null);
$stats = presence_get_stats($db, $site_id);

$page_title = 'AI Presence — ' . $site['name'];
ob_start();
?>

<style>
.platform-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px; margin-bottom:14px; }
.platform-card { background:#fff; border:1px solid var(--border); border-radius:8px; padding:14px; cursor:pointer; transition:box-shadow 0.15s, transform 0.15s; }
.platform-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.08); transform:translateY(-1px); }
.platform-card .icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:13px; margin-bottom:8px; }
.platform-card .name { font-weight:600; font-size:14px; color:var(--primary); }
.platform-card .desc { font-size:11px; color:#94a3b8; margin-top:2px; line-height:1.4; }
.platform-card .impact { font-size:10px; font-weight:600; margin-top:6px; }
.platform-card .auto-badge { font-size:9px; background:#d1fae5; color:#065f46; padding:1px 6px; border-radius:8px; }

.conv-card { background:#fff; border:1px solid var(--border); border-radius:8px; margin-bottom:8px; overflow:hidden; }
.conv-header { padding:10px 14px; display:flex; justify-content:space-between; align-items:flex-start; gap:10px; border-bottom:1px solid #f1f5f9; }
.conv-platform { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; padding:2px 8px; border-radius:10px; color:#fff; flex-shrink:0; }
.conv-title { font-size:13px; font-weight:600; color:var(--primary); }
.conv-title a { color:inherit; text-decoration:none; }
.conv-title a:hover { text-decoration:underline; }
.conv-meta { font-size:11px; color:#94a3b8; margin-top:2px; }
.conv-body { padding:10px 14px; }
.conv-snippet { font-size:12px; color:#64748b; line-height:1.5; }
.conv-actions { padding:8px 14px; background:#f8fafc; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.conv-reply { width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; font-size:13px; font-family:inherit; resize:vertical; min-height:80px; margin:8px 14px; display:none; }
.conv-reply.show { display:block; }

.status-badge { font-size:10px; padding:2px 8px; border-radius:10px; font-weight:600; }
.status-found { background:#dbeafe; color:#1e40af; }
.status-reply_drafted { background:#fef3c7; color:#92400e; }
.status-posted { background:#d1fae5; color:#065f46; }
.status-skipped { background:#f1f5f9; color:#64748b; }

.scan-btn { display:inline-flex; align-items:center; gap:4px; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site['name']) ?></a>
</div>

<div style="text-align:center;margin-bottom:14px;">
    <h2 style="font-size:20px;color:var(--primary);margin-bottom:4px;">AI Presence Builder</h2>
    <p style="font-size:13px;color:#64748b;">Discover where people are talking about your industry — and join the conversation.</p>
</div>

<!-- Stats -->
<?php if ($stats['total'] > 0): ?>
<div class="stats-grid" style="margin-bottom:14px;">
    <div class="stat-card"><div class="stat-label">Conversations Found</div><div class="stat-value"><?= $stats['total'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Replies Drafted</div><div class="stat-value"><?= $stats['drafted'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Posted</div><div class="stat-value" style="color:var(--success);"><?= $stats['posted'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Platforms Active</div><div class="stat-value"><?= count($stats['platforms']) ?></div></div>
</div>
<?php endif; ?>

<!-- All Platforms -->
<div class="card" style="margin-bottom:14px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span style="font-weight:600;">Platforms Where You Should Be Present</span>
        <a href="<?= url('/dashboard/ai-presence.php?site=' . $site_id . '&action=scan') ?>" class="btn btn-accent btn-sm scan-btn" style="text-decoration:none;">Scan All Platforms</a>
    </div>
    <div class="platform-grid" style="padding:14px;">
        <?php foreach (PRESENCE_PLATFORMS as $key => $p): ?>
        <a href="<?= url('/dashboard/ai-presence.php?site=' . $site_id . '&action=scan&platform=' . $key) ?>" class="platform-card" style="text-decoration:none;color:inherit;">
            <div class="icon" style="background:<?= $p['color'] ?>;"><?= $p['icon'] ?></div>
            <div class="name"><?= $p['name'] ?></div>
            <div class="desc"><?= $p['description'] ?></div>
            <div class="impact" style="color:<?= strpos($p['impact'], 'Very High') !== false ? '#10b981' : (strpos($p['impact'], 'High') !== false ? '#3b82f6' : '#f59e0b') ?>;">
                Impact: <?= $p['impact'] ?>
                <?php if ($p['can_auto_post']): ?>
                    <span class="auto-badge">Can Auto-Post</span>
                <?php endif; ?>
            </div>
            <?php if (isset($stats['platforms'][$key])): ?>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;"><?= $stats['platforms'][$key] ?> conversations tracked</div>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Scan Results -->
<?php if ($scan_results): ?>
<div class="card" style="margin-bottom:14px;">
    <div class="card-header">
        <span style="font-weight:600;">Conversations Found</span>
        <span class="text-sm text-muted" style="margin-left:8px;">Click "Reply with AI" to generate a response you can copy and post</span>
    </div>
    <div style="padding:10px;">
    <?php
    $has_results = false;
    foreach ($scan_results as $pk => $platform_data):
        if (empty($platform_data['conversations'])) continue;
        $has_results = true;
        $p = $platform_data['platform'];
    ?>
        <?php foreach ($platform_data['conversations'] as $conv): ?>
        <div class="conv-card" id="conv-<?= md5($conv['url'] ?? rand()) ?>">
            <div class="conv-header">
                <div style="flex:1;min-width:0;">
                    <span class="conv-platform" style="background:<?= $p['color'] ?>;"><?= $p['icon'] ?> <?= $p['name'] ?></span>
                    <div class="conv-title" style="margin-top:4px;">
                        <?php if (!empty($conv['url'])): ?>
                            <a href="<?= e($conv['url']) ?>" target="_blank"><?= e($conv['title'] ?: 'View conversation') ?></a>
                        <?php else: ?>
                            <?= e($conv['title'] ?: 'Conversation') ?>
                        <?php endif; ?>
                    </div>
                    <div class="conv-meta">
                        <?php if (!empty($conv['subreddit'])): ?>r/<?= e($conv['subreddit']) ?> &bull; <?php endif; ?>
                        <?php if (!empty($conv['score'])): ?><?= $conv['score'] ?> upvotes &bull; <?php endif; ?>
                        <?php if (!empty($conv['num_comments'])): ?><?= $conv['num_comments'] ?> comments &bull; <?php endif; ?>
                        <?php if (!empty($conv['created'])): ?><?= $conv['created'] ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if (!empty($conv['snippet'])): ?>
            <div class="conv-body">
                <div class="conv-snippet"><?= e(truncate($conv['snippet'], 300)) ?></div>
            </div>
            <?php endif; ?>
            <textarea class="conv-reply" id="reply-<?= md5($conv['url'] ?? rand()) ?>" placeholder="AI-generated reply will appear here..."></textarea>
            <div class="conv-actions">
                <button onclick="draftReply(this, '<?= e($pk) ?>', <?= e(json_encode($conv['title'] ?? '')) ?>, <?= e(json_encode(substr($conv['snippet'] ?? '', 0, 500))) ?>)" class="btn btn-accent btn-sm">Reply with AI</button>
                <button onclick="copyReply(this)" class="btn btn-outline btn-sm" style="display:none;">Copy Reply</button>
                <?php if (!empty($conv['url'])): ?>
                    <a href="<?= e($conv['url']) ?>" target="_blank" class="btn btn-outline btn-sm" style="text-decoration:none;">Open &rarr;</a>
                <?php endif; ?>
                <button onclick="saveConversation(this, '<?= e($pk) ?>', <?= e(json_encode($conv['url'] ?? '')) ?>, <?= e(json_encode($conv['title'] ?? '')) ?>, <?= e(json_encode(substr($conv['snippet'] ?? '', 0, 500))) ?>)" class="btn btn-outline btn-sm" style="font-size:11px;">Save</button>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if (!$has_results): ?>
        <div style="text-align:center;padding:20px;color:#94a3b8;">
            <p>No conversations found for your keywords. Try adding more topics/keywords in Site Settings.</p>
        </div>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Saved / Previously Found -->
<?php if (!empty($stored)): ?>
<div class="card">
    <div class="card-header">
        <span style="font-weight:600;">Saved Conversations (<?= count($stored) ?>)</span>
    </div>
    <div style="padding:10px;">
    <?php foreach ($stored as $item):
        $p = PRESENCE_PLATFORMS[$item['platform']] ?? ['name' => $item['platform'], 'color' => '#94a3b8', 'icon' => '?'];
    ?>
        <div class="conv-card">
            <div class="conv-header">
                <div style="flex:1;min-width:0;">
                    <span class="conv-platform" style="background:<?= $p['color'] ?>;"><?= $p['icon'] ?> <?= $p['name'] ?></span>
                    <span class="status-badge status-<?= $item['status'] ?>" style="margin-left:6px;"><?= str_replace('_', ' ', $item['status']) ?></span>
                    <div class="conv-title" style="margin-top:4px;">
                        <?php if (!empty($item['source_url'])): ?>
                            <a href="<?= e($item['source_url']) ?>" target="_blank"><?= e($item['source_title'] ?: 'View') ?></a>
                        <?php else: ?>
                            <?= e($item['source_title'] ?: 'Conversation') ?>
                        <?php endif; ?>
                    </div>
                    <div class="conv-meta"><?= format_date($item['created_at']) ?></div>
                </div>
            </div>
            <?php if (!empty($item['reply_content'])): ?>
            <div class="conv-body">
                <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Your reply:</div>
                <div class="conv-snippet" style="background:#f0fdf4;padding:8px;border-radius:4px;"><?= nl2br(e($item['reply_content'])) ?></div>
            </div>
            <div class="conv-actions">
                <button onclick="navigator.clipboard.writeText(this.closest('.conv-card').querySelector('.conv-snippet').innerText);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy Reply',2000)" class="btn btn-outline btn-sm">Copy Reply</button>
                <?php if (!empty($item['source_url'])): ?>
                    <a href="<?= e($item['source_url']) ?>" target="_blank" class="btn btn-outline btn-sm" style="text-decoration:none;">Open &rarr;</a>
                <?php endif; ?>
                <?php if ($item['status'] !== 'posted'): ?>
                    <button onclick="markPosted(<?= $item['id'] ?>, this)" class="btn btn-sm" style="background:#10b981;color:#fff;border:none;font-size:11px;">Mark as Posted</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
const API = window.location.pathname;
const siteId = <?= $site_id ?>;

async function draftReply(btn, platform, title, content) {
    const card = btn.closest('.conv-card');
    const textarea = card.querySelector('.conv-reply');
    const copyBtn = card.querySelector('[onclick^="copyReply"]');

    btn.disabled = true;
    btn.textContent = 'Generating...';
    textarea.classList.add('show');
    textarea.value = 'Thinking...';

    try {
        const res = await fetch(API + '?site=<?= $site_id ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'draft_reply', platform, title, content})
        });
        const data = await res.json();
        if (data.success) {
            textarea.value = data.reply;
            btn.textContent = 'Regenerate';
            btn.disabled = false;
            if (copyBtn) copyBtn.style.display = 'inline-flex';
        } else {
            textarea.value = 'Error: ' + (data.error || 'Failed to generate');
            btn.textContent = 'Retry';
            btn.disabled = false;
        }
    } catch(e) {
        textarea.value = 'Network error: ' + e.message;
        btn.textContent = 'Retry';
        btn.disabled = false;
    }
}

function copyReply(btn) {
    const card = btn.closest('.conv-card');
    const textarea = card.querySelector('.conv-reply');
    navigator.clipboard.writeText(textarea.value);
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy Reply', 2000);
}

async function saveConversation(btn, platform, url, title, snippet) {
    btn.disabled = true;
    btn.textContent = 'Saving...';

    const card = btn.closest('.conv-card');
    const textarea = card.querySelector('.conv-reply');
    const reply = textarea && textarea.value && textarea.value !== 'Thinking...' ? textarea.value : null;

    try {
        const res = await fetch(API + '?site=<?= $site_id ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'save', platform, url, title, snippet, reply})
        });
        const data = await res.json();
        if (data.success) {
            btn.textContent = 'Saved!';
            btn.style.background = '#d1fae5';
            btn.style.color = '#065f46';
        }
    } catch(e) {
        btn.textContent = 'Error';
    }
}

async function markPosted(id, btn) {
    btn.disabled = true;
    btn.textContent = 'Updating...';
    try {
        const res = await fetch(API + '?site=<?= $site_id ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'update_status', id, status: 'posted'})
        });
        const data = await res.json();
        if (data.success) {
            btn.textContent = 'Posted!';
            btn.style.background = '#d1fae5';
        }
    } catch(e) {
        btn.textContent = 'Error';
    }
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
