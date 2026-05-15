<?php
/**
 * Dashboard — AEO (Answer Engine Optimization) tracker.
 *
 * Per-site page showing tracked queries, whether your site is cited by AI search
 * engines (Perplexity today, Claude/GPT later), and who's getting cited instead.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/aeo.php';

auth_start();
auth_require();

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { redirect('/dashboard/index.php'); }

// AEO is ready if EITHER Claude (default) or Perplexity is configured.
$claude_ok     = !empty(config('haiku_api_key'));
$perplexity_ok = !empty(config('perplexity_api_key'));
$engine_ok     = $claude_ok || $perplexity_ok;
$engine_label  = $claude_ok ? 'Claude (web search)' : ($perplexity_ok ? 'Perplexity Sonar' : 'no engine');

$summary = aeo_site_summary($db, $site_id);

// Query list with latest result
$stmt = $db->prepare('
    SELECT q.*,
           (SELECT response_text FROM aeo_results WHERE query_id = q.id ORDER BY snapshot_date DESC LIMIT 1) AS latest_response,
           (SELECT citations FROM aeo_results WHERE query_id = q.id ORDER BY snapshot_date DESC LIMIT 1) AS latest_citations,
           (SELECT competitor_domains FROM aeo_results WHERE query_id = q.id ORDER BY snapshot_date DESC LIMIT 1) AS latest_competitors
    FROM aeo_queries q
    WHERE q.site_id = ? AND q.status = "active"
    ORDER BY q.last_checked_at IS NULL DESC, q.last_cited DESC, q.created_at DESC
');
$stmt->execute([$site_id]);
$queries = $stmt->fetchAll();

$page_title = 'AEO Tracker — ' . $site['name'];
ob_start();
?>
<style>
.aeo-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:18px; }
.aeo-q {
    background:#fff; border:1px solid var(--border); border-radius:6px; padding:12px 14px;
    margin-bottom:8px;
}
.aeo-q.cited { border-left:3px solid var(--success); }
.aeo-q.uncited { border-left:3px solid #94a3b8; }
.aeo-q.unchecked { border-left:3px solid var(--warning); }
.aeo-q-head { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
.aeo-q-text { font-size:13px; font-weight:600; color:var(--text); }
.aeo-q-meta { font-size:11px; color:var(--text-light); margin-top:2px; }
.aeo-q-actions { display:flex; gap:6px; }
.aeo-q-actions button { font-size:11px; padding:4px 10px; }
.aeo-q-details { margin-top:10px; padding-top:10px; border-top:1px solid var(--border); display:none; }
.aeo-q.expanded .aeo-q-details { display:block; }
.aeo-cite-list { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
.aeo-cite {
    font-size:11px; padding:3px 8px; border-radius:10px; background:#f1f5f9; color:#475569; text-decoration:none;
}
.aeo-cite.ours { background:#d1fae5; color:#065f46; font-weight:600; }
.aeo-cite:hover { background:#e2e8f0; }
.aeo-response { font-size:12px; color:#475569; line-height:1.5; margin-top:6px; padding:8px 10px; background:#f8fafc; border-radius:4px; max-height:120px; overflow-y:auto; }
.aeo-cat { font-size:10px; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-light); }
.aeo-empty { color:var(--text-light); font-size:13px; padding:14px; background:#f8fafc; border-radius:6px; border:1px dashed var(--border); }
</style>

<div class="flex items-center justify-between mb-4">
    <div>
        <div style="font-size:11px; color:var(--text-light); text-transform:uppercase; letter-spacing:0.5px;">AEO Tracker</div>
        <h2 style="font-size:18px; font-weight:600; margin:2px 0 0; color:var(--primary);"><?= e($site['name']) ?></h2>
    </div>
    <div class="flex gap-2">
        <button class="btn btn-outline btn-sm" onclick="suggestQueries(this)" <?= $engine_ok ? '' : 'disabled' ?>>💡 Suggest queries</button>
        <button class="btn btn-accent btn-sm" onclick="checkAll(this)" <?= $engine_ok ? '' : 'disabled' ?>>🔭 Check all now</button>
    </div>
</div>

<div style="font-size:11px; color:var(--text-light); margin-bottom:10px;">Engine: <strong><?= e($engine_label) ?></strong></div>

<?php if (!$engine_ok): ?>
<div class="alert alert-warning">
    No AI search engine configured. Set up Claude (recommended) or Perplexity in <a href="<?= url('/dashboard/integrations.php') ?>">Integrations</a>.
</div>
<?php endif; ?>

<div class="aeo-grid">
    <div class="stat-card">
        <div class="stat-label">Tracked queries</div>
        <div class="stat-value"><?= number_format($summary['total_queries']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Currently cited</div>
        <div class="stat-value" style="color:var(--success);"><?= number_format($summary['cited_now']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Citation rate</div>
        <div class="stat-value"><?= $summary['citation_rate'] ?>%</div>
    </div>
</div>

<div class="card">
    <div class="card-header">Add a query</div>
    <div style="display:flex; gap:8px; align-items:flex-end;">
        <div style="flex:1;">
            <label style="font-size:11px; color:var(--text-light);">What would a real user ask an AI assistant?</label>
            <input type="text" id="newQuery" class="form-control" placeholder="best CRM for small construction businesses">
        </div>
        <select id="newCategory" class="form-control" style="width:160px;">
            <option value="industry">Industry</option>
            <option value="brand">Brand</option>
            <option value="how-to">How-to</option>
            <option value="comparison">Comparison</option>
            <option value="location">Location</option>
        </select>
        <button class="btn btn-primary btn-sm" onclick="addQuery(this)" <?= $engine_ok ? '' : 'disabled' ?>>Add</button>
    </div>
</div>

<?php if (!empty($summary['top_competitors'])): ?>
<div class="card">
    <div class="card-header">Who's getting cited instead</div>
    <div style="display:flex; flex-wrap:wrap; gap:8px;">
        <?php foreach ($summary['top_competitors'] as $c): ?>
        <span style="font-size:12px; padding:5px 10px; background:#fee2e2; color:#991b1b; border-radius:14px;">
            <strong><?= e($c['domain']) ?></strong> · <?= (int)$c['mentions'] ?> mentions
        </span>
        <?php endforeach; ?>
    </div>
    <div style="font-size:11px; color:var(--text-light); margin-top:8px;">
        These domains are showing up across your tracked AEO queries. Consider what content makes them so quotable to AI engines.
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">Tracked queries (<?= count($queries) ?>)</div>
    <?php if (!$queries): ?>
        <div class="aeo-empty">No queries yet. Click "Suggest queries" above to let Claude propose 8 relevant ones, or add manually.</div>
    <?php else: ?>
        <?php foreach ($queries as $q):
            $cls = $q['last_checked_at'] === null ? 'unchecked' : ($q['last_cited'] ? 'cited' : 'uncited');
            $citations = json_decode($q['latest_citations'] ?: '[]', true) ?: [];
        ?>
        <div class="aeo-q <?= $cls ?>" data-id="<?= (int)$q['id'] ?>">
            <div class="aeo-q-head">
                <div style="flex:1;">
                    <div class="aeo-q-text">"<?= e($q['query_text']) ?>"</div>
                    <div class="aeo-q-meta">
                        <span class="aeo-cat"><?= e($q['category'] ?? 'industry') ?></span>
                        <?php if ($q['last_checked_at']): ?>
                            · last checked <?= e(date('d M H:i', strtotime($q['last_checked_at']))) ?>
                            · <?= $q['last_cited'] ? '<span style="color:var(--success);">✓ cited' . ($q['last_position'] ? ' at #' . (int)$q['last_position'] : '') . '</span>' : '<span style="color:#94a3b8;">not cited</span>' ?>
                        <?php else: ?>
                            · <span style="color:var(--warning);">not checked yet</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="aeo-q-actions">
                    <?php if ($citations): ?>
                    <button class="btn btn-outline" onclick="toggleDetails(this)">Details</button>
                    <?php endif; ?>
                    <button class="btn btn-outline" onclick="checkQuery(<?= (int)$q['id'] ?>, this)" <?= $engine_ok ? '' : 'disabled' ?>>Check now</button>
                    <button class="btn btn-outline" style="color:var(--danger);" onclick="deleteQuery(<?= (int)$q['id'] ?>, this)">×</button>
                </div>
            </div>
            <?php if ($citations): ?>
            <div class="aeo-q-details">
                <?php if ($q['latest_response']): ?>
                <div class="aeo-response"><?= e(mb_substr($q['latest_response'], 0, 500)) ?><?= mb_strlen($q['latest_response']) > 500 ? '…' : '' ?></div>
                <?php endif; ?>
                <div style="font-size:11px; color:var(--text-light); margin-top:8px;">Citations:</div>
                <div class="aeo-cite-list">
                    <?php foreach ($citations as $c): ?>
                    <a class="aeo-cite <?= !empty($c['is_ours']) ? 'ours' : '' ?>" href="<?= e($c['url']) ?>" target="_blank" rel="noopener">
                        #<?= (int)$c['position'] ?> <?= e($c['domain']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Suggestion modal -->
<div id="suggestModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:200; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto;">
    <div style="background:#fff; border-radius:8px; max-width:620px; width:100%; padding:18px 22px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <div style="font-size:16px; font-weight:600; color:var(--primary);">Suggested AEO queries</div>
            <button class="btn btn-outline btn-sm" onclick="closeSuggest()">×</button>
        </div>
        <div id="suggestBody" style="font-size:13px; color:var(--text-light);">Generating…</div>
        <div style="display:flex; justify-content:flex-end; margin-top:14px; gap:8px;">
            <button class="btn btn-outline btn-sm" onclick="closeSuggest()">Cancel</button>
            <button class="btn btn-primary btn-sm" onclick="addSelected(this)">Add selected</button>
        </div>
    </div>
</div>

<script>
const SITE_ID = <?= $site_id ?>;
const AEO_API = '<?= url('/api/aeo-action.php') ?>';

async function api(body) {
    const res = await fetch(AEO_API, {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({...body, site_id: SITE_ID})
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
    return data;
}

async function addQuery(btn) {
    const text = document.getElementById('newQuery').value.trim();
    const cat  = document.getElementById('newCategory').value;
    if (!text) return;
    btn.disabled = true;
    try {
        await api({action:'add_query', query: text, category: cat});
        window.location.reload();
    } catch(e) { btn.disabled = false; alert(e.message); }
}

async function checkQuery(id, btn) {
    btn.disabled = true; const orig = btn.textContent; btn.textContent = '…';
    try {
        const r = await api({action:'check_query', query_id: id});
        if (!r.success) { alert(r.error || 'Failed'); btn.disabled = false; btn.textContent = orig; return; }
        window.location.reload();
    } catch(e) { alert(e.message); btn.disabled = false; btn.textContent = orig; }
}

async function checkAll(btn) {
    if (!confirm('Run all active queries through Perplexity now? This may take a minute.')) return;
    btn.disabled = true; btn.textContent = 'Checking all…';
    try {
        const r = await api({action:'check_all'});
        alert(`Checked ${r.checked}. Cited: ${r.cited}. Errors: ${r.errors}.`);
        window.location.reload();
    } catch(e) { alert(e.message); btn.disabled = false; btn.textContent = '🔭 Check all now'; }
}

async function deleteQuery(id, btn) {
    if (!confirm('Delete this tracked query? History will be removed.')) return;
    try {
        await api({action:'delete_query', query_id: id});
        btn.closest('.aeo-q').remove();
    } catch(e) { alert(e.message); }
}

function toggleDetails(btn) {
    btn.closest('.aeo-q').classList.toggle('expanded');
}

async function suggestQueries(btn) {
    btn.disabled = true;
    document.getElementById('suggestModal').style.display = 'flex';
    document.getElementById('suggestBody').innerHTML = 'Asking Claude for query ideas…';
    try {
        const r = await api({action:'suggest'});
        if (!r.queries || r.queries.length === 0) {
            document.getElementById('suggestBody').innerHTML = '<div style="color:#dc2626;">No suggestions returned. Add keywords + topics for this site, then try again.</div>';
            btn.disabled = false;
            return;
        }
        let html = '<div style="font-size:12px; color:var(--text-light); margin-bottom:10px;">Tick the queries you want to track:</div>';
        r.queries.forEach((q, i) => {
            html += `<label style="display:flex; gap:8px; padding:8px 10px; background:#f8fafc; border-radius:5px; margin-bottom:6px; cursor:pointer;">
                <input type="checkbox" data-i="${i}" data-query="${q.query.replace(/"/g, '&quot;')}" data-category="${q.category||'industry'}" checked>
                <div style="flex:1;">
                    <div style="font-size:13px; color:var(--text);">"${escapeHtml(q.query)}"</div>
                    <div style="font-size:10px; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-light); margin-top:2px;">${escapeHtml(q.category||'industry')}</div>
                </div>
            </label>`;
        });
        document.getElementById('suggestBody').innerHTML = html;
        btn.disabled = false;
    } catch(e) {
        document.getElementById('suggestBody').innerHTML = '<div style="color:#dc2626;">' + escapeHtml(e.message) + '</div>';
        btn.disabled = false;
    }
}

async function addSelected(btn) {
    const checks = document.querySelectorAll('#suggestBody input[type=checkbox]:checked');
    if (checks.length === 0) { closeSuggest(); return; }
    const queries = Array.from(checks).map(c => ({query: c.dataset.query, category: c.dataset.category}));
    btn.disabled = true;
    try {
        await api({action:'bulk_add', queries});
        window.location.reload();
    } catch(e) { alert(e.message); btn.disabled = false; }
}

function closeSuggest() { document.getElementById('suggestModal').style.display = 'none'; }

function escapeHtml(s) {
    return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
