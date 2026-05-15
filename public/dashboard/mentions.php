<?php
/**
 * Mentions — merged Brand Mentions + AI Presence into one page with tabs.
 *
 * Tabs:
 *   - brand    : daily Google-scanned mentions of your brand (was brand-mentions.php)
 *   - presence : conversations across Reddit/HN/SO/etc with AI-drafted replies (was ai-presence.php)
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

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { redirect('/dashboard/index.php'); }

$tab = $_GET['tab'] ?? 'brand';
if (!in_array($tab, ['brand', 'presence'], true)) $tab = 'brand';

// ── POST handler (AI Presence AJAX endpoints) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $post_action = $input['action'] ?? '';

    if ($post_action === 'scan_platform') {
        $search_terms = presence_build_search_terms($site, $db);
        $conversations = presence_scan_platform($input['platform'] ?? '', $search_terms);
        json_response([
            'success' => true,
            'platform' => $input['platform'] ?? '',
            'platform_info' => PRESENCE_PLATFORMS[$input['platform'] ?? ''] ?? [],
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
        json_response(presence_draft_reply($site, $conv, $db));
    }
    if ($post_action === 'save') {
        json_response(['success' => true, 'id' => presence_save($db, $site_id, $input)]);
    }
    if ($post_action === 'update_status') {
        json_response(['success' => presence_update_status($db, (int)$input['id'], $site_id, $input['status'])]);
    }
    if ($post_action === 'update_reply') {
        json_response(['success' => presence_update_reply($db, (int)$input['id'], $site_id, $input['reply'])]);
    }
    json_response(['error' => 'Unknown action'], 400);
}

// ── GET handler: brand-mentions status update ─────────────
if ($tab === 'brand' && !empty($_GET['mark']) && !empty($_GET['status']) && in_array($_GET['status'], ['seen','ignored','new'], true)) {
    $db->prepare('UPDATE brand_mentions bm JOIN sites s ON bm.site_id = s.id SET bm.status = ? WHERE bm.id = ? AND s.user_id = ?')
       ->execute([$_GET['status'], (int)$_GET['mark'], $user_id]);
    redirect('/dashboard/mentions.php?site=' . $site_id . '&tab=brand&filter=' . ($_GET['filter'] ?? 'new'));
}

// ── Tab data prep ─────────────────────────────────────────
$brand_counts = ['new' => 0, 'seen' => 0, 'ignored' => 0, 'all' => 0];
$brand_mentions_rows = [];
$brand_filter = $_GET['filter'] ?? 'new';

if ($tab === 'brand') {
    $where = ['site_id = ?']; $params = [$site_id];
    if (in_array($brand_filter, ['new','seen','ignored'], true)) { $where[] = 'status = ?'; $params[] = $brand_filter; }
    $where_sql = implode(' AND ', $where);
    $stmt = $db->prepare("SELECT * FROM brand_mentions WHERE {$where_sql} ORDER BY found_at DESC LIMIT 200");
    $stmt->execute($params);
    $brand_mentions_rows = $stmt->fetchAll();
    $stmt = $db->prepare('SELECT status, COUNT(*) c FROM brand_mentions WHERE site_id = ? GROUP BY status');
    $stmt->execute([$site_id]);
    foreach ($stmt->fetchAll() as $r) { $brand_counts[$r['status']] = (int)$r['c']; $brand_counts['all'] += (int)$r['c']; }
}

$presence_stored = $presence_stats = null;
$presence_filter_platform = '';
$auto_scan = false;
if ($tab === 'presence') {
    $presence_filter_platform = $_GET['platform'] ?? '';
    $presence_stored = presence_get_all($db, $site_id, $presence_filter_platform ?: null);
    $presence_stats  = presence_get_stats($db, $site_id);
    $auto_scan = ($_GET['action'] ?? '') === 'scan';
}

// Get total counts for tab badges
$total_brand_new = (int)$db->query("SELECT COUNT(*) FROM brand_mentions WHERE site_id = {$site_id} AND status = 'new'")->fetchColumn();
$total_presence  = (int)$db->query("SELECT COUNT(*) FROM ai_presence_content WHERE site_id = {$site_id}")->fetchColumn();

$page_title = 'Mentions — ' . $site['name'];
ob_start();
?>

<style>
.m-tabs { display:flex; gap:2px; border-bottom:1px solid var(--border); margin-bottom:16px; }
.m-tabs a {
    padding:10px 16px; text-decoration:none; font-size:13px; color:#64748b;
    border-bottom:2px solid transparent; font-weight:500;
}
.m-tabs a.active { color:var(--accent); border-bottom-color:var(--accent); font-weight:600; }
.m-tabs .badge { font-size:10px; background:#e2e8f0; color:#475569; padding:1px 6px; border-radius:8px; margin-left:4px; }
.m-tabs a.active .badge { background:#fee4dc; color:var(--accent); }
.filter-tabs { display:flex; gap:4px; margin-bottom:14px; }
.filter-tabs a { padding:6px 12px; text-decoration:none; font-size:12px; color:#64748b; border:1px solid var(--border); border-radius:5px; }
.filter-tabs a.active { background:var(--primary); color:#fff; border-color:var(--primary); }

/* Brand mention card */
.mention { display:flex; gap:12px; padding:12px 14px; border:1px solid var(--border); border-radius:8px; margin-bottom:6px; background:#fff; }
.mention.new { border-left:3px solid #3b82f6; }
.mention.ignored { opacity:0.5; }
.mention .body { flex:1; min-width:0; }
.mention .title { font-weight:600; font-size:13px; color:var(--primary); }
.mention .title a { color:inherit; text-decoration:none; }
.mention .domain { font-size:11px; color:#64748b; }
.mention .snippet { font-size:12px; color:#475569; margin-top:6px; line-height:1.5; }
.mention .meta { font-size:11px; color:#94a3b8; margin-top:6px; }
.mention .actions { display:flex; gap:6px; flex-shrink:0; }
.mention .actions a { font-size:11px; padding:3px 10px; border:1px solid var(--border); border-radius:4px; text-decoration:none; color:#64748b; }

/* AI Presence */
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
.conv-platform { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; padding:2px 8px; border-radius:10px; color:#fff; flex-shrink:0; }
.conv-title { font-size:13px; font-weight:600; color:var(--primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.conv-title a { color:inherit; text-decoration:none; }
.conv-title a:hover { text-decoration:underline; }
.conv-meta { font-size:10px; color:#94a3b8; margin-top:1px; }
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

<div class="flex items-center justify-between mb-4">
    <div>
        <div style="font-size:11px; color:var(--text-light); text-transform:uppercase; letter-spacing:0.5px;">Mentions</div>
        <h2 style="font-size:18px; font-weight:600; margin:2px 0 0; color:var(--primary);"><?= e($site['name']) ?></h2>
    </div>
</div>

<div class="m-tabs">
    <a href="<?= url('/dashboard/mentions.php?site=' . $site_id . '&tab=brand') ?>" class="<?= $tab === 'brand' ? 'active' : '' ?>">
        Brand mentions <?php if ($total_brand_new > 0): ?><span class="badge"><?= $total_brand_new ?></span><?php endif; ?>
    </a>
    <a href="<?= url('/dashboard/mentions.php?site=' . $site_id . '&tab=presence') ?>" class="<?= $tab === 'presence' ? 'active' : '' ?>">
        AI Presence <?php if ($total_presence > 0): ?><span class="badge"><?= $total_presence ?></span><?php endif; ?>
    </a>
</div>

<?php if ($tab === 'brand'): ?>

<p style="font-size:12px;color:#64748b;margin-bottom:14px;">Where on the web your brand name appeared in the last 24 hours. Scanned daily.</p>

<div class="filter-tabs">
    <?php foreach (['new','seen','ignored','all'] as $f): ?>
    <a href="<?= url('/dashboard/mentions.php?site=' . $site_id . '&tab=brand&filter=' . $f) ?>" class="<?= $brand_filter === $f ? 'active' : '' ?>">
        <?= ucfirst($f === 'seen' ? 'Reviewed' : $f) ?> (<?= $brand_counts[$f] ?? $brand_counts['all'] ?>)
    </a>
    <?php endforeach; ?>
</div>

<?php if (empty($brand_mentions_rows)): ?>
<div class="card" style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;">
    <?= $brand_counts['all'] === 0 ? 'No mentions detected yet. The daily monitor scans Google for your brand name.' : 'Nothing to show in this view.' ?>
</div>
<?php endif; ?>

<?php foreach ($brand_mentions_rows as $m): ?>
<div class="mention <?= e($m['status']) ?>">
    <div class="body">
        <div class="title"><a href="<?= e($m['url']) ?>" target="_blank"><?= e($m['title'] ?: '(no title)') ?> ↗</a></div>
        <div class="domain"><?= e($m['source_domain']) ?></div>
        <?php if (!empty($m['snippet'])): ?><div class="snippet"><?= e($m['snippet']) ?></div><?php endif; ?>
        <div class="meta">Found <?= format_date($m['found_at']) ?></div>
    </div>
    <div class="actions">
        <?php if ($m['status'] === 'new'): ?>
            <a href="<?= url('/dashboard/mentions.php?site=' . $site_id . '&tab=brand&filter=' . e($brand_filter) . '&mark=' . (int)$m['id'] . '&status=seen') ?>">✓ Mark reviewed</a>
            <a href="<?= url('/dashboard/mentions.php?site=' . $site_id . '&tab=brand&filter=' . e($brand_filter) . '&mark=' . (int)$m['id'] . '&status=ignored') ?>">👁 Ignore</a>
        <?php elseif ($m['status'] === 'seen'): ?>
            <a href="<?= url('/dashboard/mentions.php?site=' . $site_id . '&tab=brand&filter=' . e($brand_filter) . '&mark=' . (int)$m['id'] . '&status=ignored') ?>">👁 Ignore</a>
        <?php else: ?>
            <a href="<?= url('/dashboard/mentions.php?site=' . $site_id . '&tab=brand&filter=' . e($brand_filter) . '&mark=' . (int)$m['id'] . '&status=new') ?>">↺ Restore</a>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php else: /* presence tab */ ?>

<p style="font-size:12px;color:#64748b;margin-bottom:14px;">Discover where people are talking about your industry — and join the conversation with AI-drafted replies.</p>

<?php if ($presence_stats['total'] > 0): ?>
<div class="stats-grid" style="margin-bottom:14px;">
    <div class="stat-card"><div class="stat-label">Conversations Found</div><div class="stat-value"><?= $presence_stats['total'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Replies Drafted</div><div class="stat-value"><?= $presence_stats['drafted'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Posted</div><div class="stat-value" style="color:var(--success);"><?= $presence_stats['posted'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Platforms Active</div><div class="stat-value"><?= count($presence_stats['platforms']) ?></div></div>
</div>
<?php endif; ?>

<?php if (empty(config('google_cse_api_key')) && empty(config('reddit_client_id'))): ?>
<div class="alert alert-warning">
    For best results, connect Google Search or Reddit API. <a href="<?= url('/dashboard/integrations.php') ?>">Set up in Integrations Hub</a>.
</div>
<?php endif; ?>

<div id="scan-log" class="scan-progress"></div>

<div class="card" style="margin-bottom:14px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span>Platforms Where You Should Be Present</span>
        <button onclick="scanAllPlatforms()" id="scan-all-btn" class="btn btn-accent btn-sm">Scan All Platforms</button>
    </div>
    <div class="platform-grid" style="padding:14px;">
        <?php foreach (PRESENCE_PLATFORMS as $key => $p): ?>
        <div class="platform-card" id="platform-<?= $key ?>" onclick="scanPlatform('<?= $key ?>')">
            <div class="scan-status" id="status-<?= $key ?>"></div>
            <div class="icon" style="background:<?= $p['color'] ?>;"><?= $p['icon'] ?></div>
            <div class="name"><?= $p['name'] ?></div>
            <div class="desc"><?= $p['description'] ?></div>
            <div class="impact" style="color:<?= strpos($p['impact'],'Very High')!==false?'#10b981':(strpos($p['impact'],'High')!==false?'#3b82f6':'#f59e0b') ?>;">
                Impact: <?= $p['impact'] ?>
                <?php if ($p['can_auto_post']): ?><span class="auto-badge">Can Auto-Post</span><?php endif; ?>
            </div>
            <?php if (isset($presence_stats['platforms'][$key])): ?>
                <div style="font-size:10px;color:#94a3b8;margin-top:4px;"><?= $presence_stats['platforms'][$key] ?> conversations tracked</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="results-container"></div>

<?php if (!empty($presence_stored)): ?>
<div class="card">
    <div class="card-header">Saved Conversations (<?= count($presence_stored) ?>)</div>
    <div style="padding:10px;">
    <?php foreach ($presence_stored as $item):
        $p = PRESENCE_PLATFORMS[$item['platform']] ?? ['name' => $item['platform'], 'color' => '#94a3b8', 'icon' => '?'];
    ?>
        <div class="conv-card">
            <div style="padding:10px 14px;">
                <span class="conv-platform" style="background:<?= $p['color'] ?>;"><?= $p['icon'] ?> <?= $p['name'] ?></span>
                <span class="status-badge status-<?= $item['status'] ?>" style="margin-left:6px;"><?= str_replace('_',' ',$item['status']) ?></span>
                <div class="conv-title" style="margin-top:4px;">
                    <?php if (!empty($item['source_url'])): ?>
                        <a href="<?= e($item['source_url']) ?>" target="_blank"><?= e($item['source_title'] ?: 'View') ?></a>
                    <?php else: ?>
                        <?= e($item['source_title'] ?: 'Conversation') ?>
                    <?php endif; ?>
                </div>
                <div class="conv-meta"><?= format_date($item['created_at']) ?></div>
            </div>
            <?php if (!empty($item['reply_content'])): ?>
            <div style="padding:0 14px 10px;">
                <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Your reply:</div>
                <div class="conv-snippet" style="background:#f0fdf4;padding:8px;border-radius:4px;font-size:12px;"><?= nl2br(e($item['reply_content'])) ?></div>
                <div style="margin-top:6px;display:flex;gap:6px;">
                    <button onclick="navigator.clipboard.writeText(this.closest('.conv-card').querySelector('.conv-snippet').innerText);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy Reply',2000)" class="btn btn-outline btn-sm">Copy Reply</button>
                    <?php if (!empty($item['source_url'])): ?><a href="<?= e($item['source_url']) ?>" target="_blank" class="btn btn-outline btn-sm" style="text-decoration:none;">Open ↗</a><?php endif; ?>
                    <?php if ($item['status'] !== 'posted'): ?>
                        <button onclick="markPosted(<?= $item['id'] ?>, this)" class="btn btn-sm" style="background:#10b981;color:#fff;border:none;font-size:11px;">Mark as Posted</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
const API = window.location.pathname + '?site=<?= $site_id ?>&tab=presence';
const PLATFORMS = <?= json_encode(PRESENCE_PLATFORMS) ?>;
const SCAN_ORDER = ['reddit', 'hackernews', 'stackoverflow', 'github', 'youtube', 'quora', 'linkedin', 'twitter', 'medium', 'producthunt', 'facebook', 'wikipedia'];

function log(msg, type='') {
    const el = document.getElementById('scan-log');
    el.classList.add('show');
    el.innerHTML += '<div class="line ' + type + '">' + (type==='info'?'→ ':type==='success'?'✓ ':type==='error'?'✗ ':'') + msg + '</div>';
    el.scrollTop = el.scrollHeight;
}
async function scanPlatform(key) {
    const card = document.getElementById('platform-' + key);
    const statusEl = document.getElementById('status-' + key);
    if (!card || card.classList.contains('scanning')) return;
    card.classList.add('scanning'); statusEl.innerHTML = '<span class="spinner"></span>';
    log('Scanning ' + (PLATFORMS[key]?.name || key) + '...', 'info');
    try {
        const res = await fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'scan_platform', platform:key}) });
        const data = await res.json();
        card.classList.remove('scanning'); card.classList.add('scanned');
        if (data.conversations && data.conversations.length > 0) {
            statusEl.innerHTML = '<span style="color:#10b981;font-weight:700;">' + data.conversations.length + ' found</span>';
            log(PLATFORMS[key].name + ': ' + data.conversations.length + ' conversations found', 'success');
            renderConversations(key, data.platform_info, data.conversations);
        } else {
            statusEl.innerHTML = '<span style="color:#94a3b8;">0 found</span>';
            log(PLATFORMS[key].name + ': No conversations found', 'error');
        }
    } catch(e) {
        card.classList.remove('scanning'); statusEl.innerHTML = '<span style="color:#ef4444;">Error</span>';
        log(PLATFORMS[key].name + ': ' + e.message, 'error');
    }
}
async function scanAllPlatforms() {
    const btn = document.getElementById('scan-all-btn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Scanning...';
    document.getElementById('scan-log').innerHTML = '';
    document.getElementById('results-container').innerHTML = '';
    log('Starting scan across all platforms...', 'info');
    for (const key of SCAN_ORDER) await scanPlatform(key);
    log('Scan complete!', 'success');
    btn.disabled = false; btn.textContent = 'Scan All Platforms';
}
function renderConversations(platformKey, platformInfo, conversations) {
    const container = document.getElementById('results-container');
    let section = document.getElementById('results-' + platformKey);
    if (!section) {
        section = document.createElement('div');
        section.id = 'results-' + platformKey;
        section.className = 'card'; section.style.marginBottom = '14px';
        section.innerHTML = '<div class="card-header" style="display:flex;align-items:center;gap:8px;">'
            + '<span class="conv-platform" style="background:' + (platformInfo.color||'#94a3b8') + ';">' + (platformInfo.icon||'') + '</span>'
            + '<span style="font-weight:600;">' + (platformInfo.name||platformKey) + '</span>'
            + '<span class="text-sm text-muted">(' + conversations.length + ' conversations)</span></div>'
            + '<div class="results-body" style="padding:10px;"></div>';
        container.appendChild(section);
    }
    const body = section.querySelector('.results-body');
    conversations.forEach(conv => {
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
        const html = '<div class="conv-card">'
            + '<div style="padding:10px 14px;display:flex;justify-content:space-between;align-items:center;gap:10px;">'
            + '<div style="flex:1;min-width:0;">'
            + '<div class="conv-title"><a href="' + escHtml(conv.url||'#') + '" target="_blank">' + titleClean + '</a></div>'
            + (meta.length ? '<div class="conv-meta">' + escHtml(meta.join(' · ')) + '</div>' : '')
            + (snippet ? '<div style="font-size:12px;color:#94a3b8;margin-top:4px;line-height:1.4;">' + escHtml(snippet) + '</div>' : '')
            + '</div>'
            + '<div style="display:flex;gap:4px;flex-shrink:0;">'
            + '<button onclick="draftReply(this,\'' + platformKey + '\',' + JSON.stringify(convTitle) + ',' + JSON.stringify(convSnippet) + ')" class="btn btn-accent btn-sm" style="font-size:11px;padding:4px 10px;">Reply with AI</button>'
            + (conv.url ? '<a href="' + escHtml(conv.url) + '" target="_blank" class="btn btn-outline btn-sm" style="text-decoration:none;font-size:11px;padding:4px 8px;">Open</a>' : '')
            + '<button onclick="saveConversation(this,\'' + platformKey + '\',' + JSON.stringify(conv.url||'') + ',' + JSON.stringify(convTitle) + ',' + JSON.stringify(convSnippet) + ')" class="btn btn-outline btn-sm" style="font-size:11px;padding:4px 8px;">Save</button>'
            + '</div></div>'
            + '<textarea class="conv-reply" placeholder="AI-generated reply will appear here..."></textarea>'
            + '<div class="conv-actions" style="display:none;padding:6px 14px;background:#f8fafc;"><button onclick="copyReply(this)" class="btn btn-outline btn-sm">Copy Reply</button></div>'
            + '</div>';
        body.insertAdjacentHTML('beforeend', html);
    });
}
function escHtml(str) {
    const d = document.createElement('div');
    const tmp = document.createElement('textarea'); tmp.innerHTML = str;
    d.textContent = tmp.value.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();
    return d.innerHTML;
}
function cleanSnippet(str) {
    if (!str) return '';
    const tmp = document.createElement('textarea'); tmp.innerHTML = str;
    return tmp.value.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().substring(0,150);
}
async function draftReply(btn, platform, title, content) {
    const card = btn.closest('.conv-card');
    const textarea = card.querySelector('.conv-reply');
    const actionsBar = card.querySelector('.conv-actions');
    btn.disabled = true; btn.textContent = 'Generating...';
    textarea.classList.add('show'); textarea.value = 'Thinking...';
    try {
        const res = await fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'draft_reply', platform, title, content}) });
        const data = await res.json();
        if (data.success) { textarea.value = data.reply; btn.textContent = 'Regenerate'; btn.disabled = false; if (actionsBar) actionsBar.style.display = 'flex'; }
        else { textarea.value = 'Error: ' + (data.error||'Failed'); btn.textContent = 'Retry'; btn.disabled = false; }
    } catch(e) { textarea.value = 'Network error: ' + e.message; btn.textContent = 'Retry'; btn.disabled = false; }
}
function copyReply(btn) {
    const card = btn.closest('.conv-card');
    navigator.clipboard.writeText(card.querySelector('.conv-reply').value);
    btn.textContent = 'Copied!'; setTimeout(() => btn.textContent = 'Copy Reply', 2000);
}
async function saveConversation(btn, platform, url, title, snippet) {
    btn.disabled = true; btn.textContent = 'Saving...';
    const card = btn.closest('.conv-card');
    const textarea = card.querySelector('.conv-reply');
    const reply = textarea && textarea.value && textarea.value !== 'Thinking...' ? textarea.value : null;
    try {
        const res = await fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'save', platform, url, title, snippet, reply}) });
        const data = await res.json();
        if (data.success) { btn.textContent = 'Saved!'; btn.style.background = '#d1fae5'; btn.style.color = '#065f46'; }
    } catch(e) { btn.textContent = 'Error'; }
}
async function markPosted(id, btn) {
    btn.disabled = true; btn.textContent = 'Updating...';
    try {
        const res = await fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'update_status', id, status:'posted'}) });
        const data = await res.json();
        if (data.success) { btn.textContent = 'Posted!'; btn.style.background = '#d1fae5'; }
    } catch(e) { btn.textContent = 'Error'; }
}
<?php if ($auto_scan): ?>
document.addEventListener('DOMContentLoaded', () => scanAllPlatforms());
<?php endif; ?>
</script>

<?php endif; /* tab */ ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
