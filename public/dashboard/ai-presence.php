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

    if ($post_action === 'scan_platform') {
        $platform_key = $input['platform'] ?? '';
        $search_terms = presence_build_search_terms($site, $db);
        $conversations = presence_scan_platform($platform_key, $search_terms);
        $platform_info = PRESENCE_PLATFORMS[$platform_key] ?? [];
        json_response([
            'success' => true,
            'platform' => $platform_key,
            'platform_info' => $platform_info,
            'conversations' => $conversations,
            'count' => count($conversations),
        ]);
    }

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

// No more server-side scan — all done via AJAX now
$auto_scan = ($action === 'scan');

// Get stored content
$stored = presence_get_all($db, $site_id, $filter_platform ?: null);
$stats = presence_get_stats($db, $site_id);

$page_title = 'AI Presence — ' . $site['name'];
ob_start();
?>

<style>
.platform-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px; margin-bottom:14px; }
.platform-card { background:#fff; border:1px solid var(--border); border-radius:8px; padding:14px; cursor:pointer; transition:box-shadow 0.15s, transform 0.15s; position:relative; }
.platform-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.08); transform:translateY(-1px); }
.platform-card .icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:13px; margin-bottom:8px; }
.platform-card .name { font-weight:600; font-size:14px; color:var(--primary); }
.platform-card .desc { font-size:11px; color:#94a3b8; margin-top:2px; line-height:1.4; }
.platform-card .impact { font-size:10px; font-weight:600; margin-top:6px; }
.platform-card .auto-badge { font-size:9px; background:#d1fae5; color:#065f46; padding:1px 6px; border-radius:8px; }
.platform-card .scan-status { position:absolute; top:8px; right:8px; font-size:10px; }
.platform-card.scanning { opacity:0.7; pointer-events:none; }
.platform-card.scanned { border-color:#10b981; }

.conv-card { background:#fff; border:1px solid var(--border); border-radius:8px; margin-bottom:6px; overflow:hidden; }
.conv-card:hover { border-color:#cbd5e1; }
.conv-platform { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; padding:2px 8px; border-radius:10px; color:#fff; flex-shrink:0; }
.conv-title { font-size:13px; font-weight:600; color:var(--primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.conv-title a { color:inherit; text-decoration:none; }
.conv-title a:hover { text-decoration:underline; }
.conv-meta { font-size:10px; color:#94a3b8; margin-top:1px; }
.conv-actions { padding:6px 14px; background:#f8fafc; display:flex; gap:6px; align-items:center; }
.conv-reply { width:calc(100% - 28px); padding:8px; border:1px solid var(--border); border-radius:6px; font-size:12px; font-family:inherit; resize:vertical; min-height:60px; margin:6px 14px; display:none; }
.conv-reply.show { display:block; }

.status-badge { font-size:10px; padding:2px 8px; border-radius:10px; font-weight:600; }
.status-found { background:#dbeafe; color:#1e40af; }
.status-reply_drafted { background:#fef3c7; color:#92400e; }
.status-posted { background:#d1fae5; color:#065f46; }

.scan-progress { background:#0f172a; color:#a3e635; padding:12px 16px; border-radius:8px; font-family:monospace; font-size:12px; margin-bottom:14px; max-height:200px; overflow-y:auto; display:none; }
.scan-progress.show { display:block; }
.scan-progress .line { margin:2px 0; }
.scan-progress .success { color:#4ade80; }
.scan-progress .error { color:#f87171; }
.scan-progress .info { color:#60a5fa; }

@keyframes spin { to { transform:rotate(360deg); } }
.spinner { display:inline-block; width:12px; height:12px; border:2px solid #94a3b8; border-top-color:transparent; border-radius:50%; animation:spin 0.6s linear infinite; }
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

<!-- Scan Progress Log -->
<div id="scan-log" class="scan-progress"></div>

<!-- All Platforms -->
<div class="card" style="margin-bottom:14px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span style="font-weight:600;">Platforms Where You Should Be Present</span>
        <button onclick="scanAllPlatforms()" id="scan-all-btn" class="btn btn-accent btn-sm" style="display:inline-flex;align-items:center;gap:4px;">Scan All Platforms</button>
    </div>
    <div class="platform-grid" style="padding:14px;">
        <?php foreach (PRESENCE_PLATFORMS as $key => $p): ?>
        <div class="platform-card" id="platform-<?= $key ?>" onclick="scanPlatform('<?= $key ?>')">
            <div class="scan-status" id="status-<?= $key ?>"></div>
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
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Live Results (populated by JS) -->
<div id="results-container"></div>

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
const API = window.location.pathname + '?site=<?= $site_id ?>';
const PLATFORMS = <?= json_encode(PRESENCE_PLATFORMS) ?>;
// Platforms with direct APIs — scan these first (most reliable)
const SCAN_ORDER = ['reddit', 'hackernews', 'stackoverflow', 'github', 'youtube', 'quora', 'linkedin', 'twitter', 'medium', 'producthunt', 'facebook', 'wikipedia'];

function log(msg, type = '') {
    const el = document.getElementById('scan-log');
    el.classList.add('show');
    el.innerHTML += '<div class="line ' + type + '">' + (type === 'info' ? '→ ' : type === 'success' ? '✓ ' : type === 'error' ? '✗ ' : '') + msg + '</div>';
    el.scrollTop = el.scrollHeight;
}

async function scanPlatform(key) {
    const card = document.getElementById('platform-' + key);
    const statusEl = document.getElementById('status-' + key);
    if (!card || card.classList.contains('scanning')) return;

    card.classList.add('scanning');
    statusEl.innerHTML = '<span class="spinner"></span>';
    log('Scanning ' + (PLATFORMS[key]?.name || key) + '...', 'info');

    try {
        const res = await fetch(API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'scan_platform', platform: key})
        });
        const data = await res.json();

        card.classList.remove('scanning');
        card.classList.add('scanned');

        if (data.conversations && data.conversations.length > 0) {
            statusEl.innerHTML = '<span style="color:#10b981;font-weight:700;">' + data.conversations.length + ' found</span>';
            log(PLATFORMS[key].name + ': ' + data.conversations.length + ' conversations found', 'success');
            renderConversations(key, data.platform_info, data.conversations);
        } else {
            statusEl.innerHTML = '<span style="color:#94a3b8;">0 found</span>';
            log(PLATFORMS[key].name + ': No conversations found', 'error');
        }
    } catch(e) {
        card.classList.remove('scanning');
        statusEl.innerHTML = '<span style="color:#ef4444;">Error</span>';
        log(PLATFORMS[key].name + ': ' + e.message, 'error');
    }
}

async function scanAllPlatforms() {
    const btn = document.getElementById('scan-all-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Scanning...';
    document.getElementById('scan-log').innerHTML = '';
    document.getElementById('results-container').innerHTML = '';

    log('Starting scan across all platforms...', 'info');

    for (const key of SCAN_ORDER) {
        await scanPlatform(key);
    }

    log('Scan complete!', 'success');
    btn.disabled = false;
    btn.textContent = 'Scan All Platforms';
}

function renderConversations(platformKey, platformInfo, conversations) {
    const container = document.getElementById('results-container');
    // Check if section for this platform already exists
    let section = document.getElementById('results-' + platformKey);
    if (!section) {
        section = document.createElement('div');
        section.id = 'results-' + platformKey;
        section.className = 'card';
        section.style.marginBottom = '14px';
        section.innerHTML = '<div class="card-header" style="display:flex;align-items:center;gap:8px;">'
            + '<span class="conv-platform" style="background:' + (platformInfo.color || '#94a3b8') + ';">' + (platformInfo.icon || '') + '</span>'
            + '<span style="font-weight:600;">' + (platformInfo.name || platformKey) + '</span>'
            + '<span class="text-sm text-muted">(' + conversations.length + ' conversations)</span>'
            + '</div><div class="results-body" style="padding:10px;"></div>';
        container.appendChild(section);
    }

    const body = section.querySelector('.results-body');
    conversations.forEach(conv => {
        const id = 'c-' + Math.random().toString(36).substr(2, 9);
        const meta = [];
        if (conv.subreddit) meta.push('r/' + conv.subreddit);
        if (conv.score) meta.push(typeof conv.score === 'number' ? conv.score + ' pts' : conv.score);
        if (conv.num_comments) meta.push(conv.num_comments + ' comments');
        if (conv.author) meta.push(conv.author);
        if (conv.created) meta.push(conv.created);

        const snippet = cleanSnippet(conv.snippet);
        const titleClean = escHtml(conv.title || 'View conversation');
        const convTitle = conv.title || '';
        const convSnippet = (conv.snippet || '').substring(0, 500);

        const html = '<div class="conv-card" id="' + id + '">'
            + '<div style="padding:10px 14px;display:flex;justify-content:space-between;align-items:center;gap:10px;">'
            + '<div style="flex:1;min-width:0;">'
            + '<div class="conv-title"><a href="' + escHtml(conv.url || '#') + '" target="_blank">' + titleClean + '</a></div>'
            + (meta.length ? '<div class="conv-meta">' + escHtml(meta.join(' · ')) + '</div>' : '')
            + (snippet ? '<div style="font-size:12px;color:#94a3b8;margin-top:4px;line-height:1.4;">' + escHtml(snippet) + '</div>' : '')
            + '</div>'
            + '<div style="display:flex;gap:4px;flex-shrink:0;">'
            + '<button onclick="draftReply(this, \'' + platformKey + '\', ' + JSON.stringify(convTitle) + ', ' + JSON.stringify(convSnippet) + ')" class="btn btn-accent btn-sm" style="font-size:11px;padding:4px 10px;">Reply with AI</button>'
            + (conv.url ? '<a href="' + escHtml(conv.url) + '" target="_blank" class="btn btn-outline btn-sm" style="text-decoration:none;font-size:11px;padding:4px 8px;">Open</a>' : '')
            + '<button onclick="saveConversation(this, \'' + platformKey + '\', ' + JSON.stringify(conv.url || '') + ', ' + JSON.stringify(convTitle) + ', ' + JSON.stringify(convSnippet) + ')" class="btn btn-outline btn-sm" style="font-size:11px;padding:4px 8px;">Save</button>'
            + '</div>'
            + '</div>'
            + '<textarea class="conv-reply" placeholder="AI-generated reply will appear here..."></textarea>'
            + '<div class="conv-actions" style="display:none;">'
            + '<button onclick="copyReply(this)" class="btn btn-outline btn-sm">Copy Reply</button>'
            + '</div>'
            + '</div>';
        body.insertAdjacentHTML('beforeend', html);
    });
}

function escHtml(str) {
    const d = document.createElement('div');
    // First decode HTML entities (&#x27; etc) then re-escape for safe display
    const tmp = document.createElement('textarea');
    tmp.innerHTML = str;
    d.textContent = tmp.value.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    return d.innerHTML;
}

function cleanSnippet(str) {
    if (!str) return '';
    const tmp = document.createElement('textarea');
    tmp.innerHTML = str;
    return tmp.value.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 150);
}

async function draftReply(btn, platform, title, content) {
    const card = btn.closest('.conv-card');
    const textarea = card.querySelector('.conv-reply');
    const actionsBar = card.querySelector('.conv-actions');

    btn.disabled = true;
    btn.textContent = 'Generating...';
    textarea.classList.add('show');
    textarea.value = 'Thinking...';

    try {
        const res = await fetch(API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'draft_reply', platform, title, content})
        });
        const data = await res.json();
        if (data.success) {
            textarea.value = data.reply;
            btn.textContent = 'Regenerate';
            btn.disabled = false;
            if (actionsBar) actionsBar.style.display = 'flex';
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
        const res = await fetch(API, {
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
        const res = await fetch(API, {
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

// Auto-scan if URL has action=scan
<?php if ($auto_scan): ?>
document.addEventListener('DOMContentLoaded', () => scanAllPlatforms());
<?php endif; ?>
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
