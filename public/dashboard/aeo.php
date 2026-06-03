<?php
/**
 * Dashboard — AEO Tracker (Citations + Industry Recall).
 *
 * Tabs:
 *   Citations         — per-query, per-engine view of which AI search engines
 *                       cite this site (Claude / ChatGPT / Gemini / Perplexity).
 *   Industry Recall   — does each engine REMEMBER the brand when asked generic
 *                       industry questions WITHOUT web search? Measures presence
 *                       in training data, not real-time citation.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/aeo.php';
require_once __DIR__ . '/../../includes/ai-visibility.php';

auth_start();
auth_require();

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { redirect('/dashboard/index.php'); }

$tab = $_GET['tab'] ?? 'citations';
if (!in_array($tab, ['citations', 'recall'], true)) $tab = 'citations';

$aeo_engines    = aeo_available_engines();
$recall_engines = recall_available_engines();

$summary = aeo_site_summary($db, $site_id);

// Query list — per-engine results loaded separately
$stmt = $db->prepare('SELECT * FROM aeo_queries WHERE site_id = ? AND status = "active"
                      ORDER BY last_checked_at IS NULL DESC, last_cited DESC, created_at DESC');
$stmt->execute([$site_id]);
$queries = $stmt->fetchAll();

// Recall: latest snapshot per engine for this site
$recall_per_engine = recall_latest_per_engine($db, $site_id);

$page_title = 'AEO Tracker — ' . $site['name'];
ob_start();
?>
<style>
.aeo-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; margin-bottom:12px; }
.aeo-engines-pills { display:flex; gap:6px; flex-wrap:wrap; }
.aeo-eng-pill { font-size:10px; padding:3px 9px; border-radius:10px; background:#e2e8f0; color:#475569; font-weight:600; text-transform:uppercase; letter-spacing:0.4px; }
.aeo-eng-pill.on { background:#d1fae5; color:#065f46; }
.aeo-tabs { display:flex; gap:0; border-bottom:1px solid var(--border); margin-bottom:14px; }
.aeo-tab { padding:8px 16px; font-size:13px; color:var(--text-light); border-bottom:2px solid transparent; text-decoration:none; }
.aeo-tab.active { color:var(--primary); border-bottom-color:var(--primary); font-weight:600; }
.aeo-q { background:#fff; border:1px solid var(--border); border-radius:6px; padding:12px 14px; margin-bottom:8px; }
.aeo-q-head { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
.aeo-q-text { font-size:13px; font-weight:600; color:var(--text); }
.aeo-q-meta { font-size:11px; color:var(--text-light); margin-top:2px; }
.aeo-q-actions { display:flex; gap:6px; }
.aeo-q-actions button { font-size:11px; padding:4px 10px; }
.aeo-q-engines { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; }
.aeo-q-engine { font-size:11px; padding:3px 10px; border-radius:12px; display:inline-flex; align-items:center; gap:4px; }
.aeo-q-engine.cited { background:#d1fae5; color:#065f46; }
.aeo-q-engine.uncited { background:#f1f5f9; color:#64748b; }
.aeo-q-engine.error { background:#fee2e2; color:#991b1b; }
.aeo-q-engine.notrun { background:#f8fafc; color:#94a3b8; border:1px dashed #cbd5e1; }
.aeo-q-details { margin-top:10px; padding-top:10px; border-top:1px solid var(--border); display:none; }
.aeo-q.expanded .aeo-q-details { display:block; }
.aeo-cite-list { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
.aeo-cite { font-size:11px; padding:3px 8px; border-radius:10px; background:#f1f5f9; color:#475569; text-decoration:none; }
.aeo-cite.ours { background:#d1fae5; color:#065f46; font-weight:600; }
.aeo-response { font-size:12px; color:#475569; line-height:1.5; margin-top:6px; padding:8px 10px; background:#f8fafc; border-radius:4px; max-height:120px; overflow-y:auto; }
.aeo-cat { font-size:10px; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-light); }
.aeo-empty { color:var(--text-light); font-size:13px; padding:14px; background:#f8fafc; border-radius:6px; border:1px dashed var(--border); }
.recall-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; margin-bottom:14px; }
.recall-card { background:#fff; border:1px solid var(--border); border-radius:6px; padding:14px; }
.recall-card .eng { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-light); margin-bottom:4px; }
.recall-card .score { font-size:32px; font-weight:700; color:var(--primary); line-height:1; }
.recall-card .score.low { color:#dc2626; }
.recall-card .score.mid { color:#d97706; }
.recall-card .meta { font-size:11px; color:var(--text-light); margin-top:6px; }
.recall-q { background:#fff; border:1px solid var(--border); border-radius:6px; padding:12px 14px; margin-bottom:8px; }
.recall-q-head { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
.recall-q-text { font-size:13px; font-weight:600; color:var(--text); }
.recall-q-engines { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; }
</style>

<div class="aeo-engines-pills" style="margin-bottom:8px;">
    <span style="font-size:11px; color:var(--text-light); margin-right:4px;">Engines configured:</span>
    <?php foreach (['claude_web', 'openai_web', 'gemini_web', 'perplexity'] as $e): ?>
        <span class="aeo-eng-pill <?= in_array($e, $aeo_engines, true) ? 'on' : '' ?>"><?= e(aeo_engine_label($e)) ?></span>
    <?php endforeach; ?>
</div>

<?php if (empty($aeo_engines)): ?>
<div class="alert alert-warning">
    No AI engine configured. Add at least one of Claude / OpenAI / Gemini / Perplexity in <a href="<?= url('/dashboard/integrations.php') ?>">Integrations</a>.
</div>
<?php endif; ?>

<div class="aeo-tabs">
    <a class="aeo-tab <?= $tab === 'citations' ? 'active' : '' ?>" href="<?= url('/dashboard/aeo.php?site=' . $site_id . '&tab=citations') ?>">Citations</a>
    <a class="aeo-tab <?= $tab === 'recall' ? 'active' : '' ?>" href="<?= url('/dashboard/aeo.php?site=' . $site_id . '&tab=recall') ?>">Industry Recall</a>
</div>

<?php if ($tab === 'citations'): ?>
    <!-- ============= CITATIONS TAB ============= -->

    <div class="flex items-center justify-between" style="margin-bottom:10px;">
        <div style="font-size:11px; color:var(--text-light);">
            Each query is run through all configured engines. ✓ = your domain was cited.
        </div>
        <div class="flex gap-2">
            <button class="btn btn-outline btn-sm" onclick="suggestQueries(this)" <?= empty($aeo_engines) ? 'disabled' : '' ?>>Suggest queries</button>
            <button class="btn btn-accent btn-sm" onclick="checkAll(this)" <?= empty($aeo_engines) ? 'disabled' : '' ?>>Check all now</button>
        </div>
    </div>

    <div class="aeo-stats">
        <div class="stat-card"><div class="stat-label">Tracked queries</div><div class="stat-value"><?= number_format($summary['total_queries']) ?></div></div>
        <div class="stat-card"><div class="stat-label">Currently cited (any engine)</div><div class="stat-value" style="color:var(--success);"><?= number_format($summary['cited_now']) ?></div></div>
        <div class="stat-card"><div class="stat-label">Citation rate</div><div class="stat-value"><?= $summary['citation_rate'] ?>%</div></div>
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
            <button class="btn btn-primary btn-sm" onclick="addQuery(this)" <?= empty($aeo_engines) ? 'disabled' : '' ?>>Add</button>
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
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">Tracked queries (<?= count($queries) ?>)</div>
        <?php if (!$queries): ?>
            <div class="aeo-empty">No queries yet. Click "Suggest queries" to let Claude propose 8 relevant ones, or add manually.</div>
        <?php else: ?>
            <?php foreach ($queries as $q):
                $per_eng = aeo_latest_per_engine($db, (int)$q['id']);
            ?>
            <div class="aeo-q" data-id="<?= (int)$q['id'] ?>">
                <div class="aeo-q-head">
                    <div style="flex:1;">
                        <div class="aeo-q-text">"<?= e($q['query_text']) ?>"</div>
                        <div class="aeo-q-meta">
                            <span class="aeo-cat"><?= e($q['category'] ?? 'industry') ?></span>
                            <?php if ($q['last_checked_at']): ?>
                                · last checked <?= e(date('d M H:i', strtotime($q['last_checked_at']))) ?>
                            <?php else: ?>
                                · <span style="color:var(--warning);">not checked yet</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="aeo-q-actions">
                        <?php if ($per_eng): ?><button class="btn btn-outline" onclick="toggleDetails(this)">Details</button><?php endif; ?>
                        <button class="btn btn-outline" onclick="checkQuery(<?= (int)$q['id'] ?>, this)" <?= empty($aeo_engines) ? 'disabled' : '' ?>>Check now</button>
                        <button class="btn btn-outline" style="color:var(--danger);" onclick="deleteQuery(<?= (int)$q['id'] ?>, this)">×</button>
                    </div>
                </div>

                <div class="aeo-q-engines">
                    <?php foreach ($aeo_engines as $eng):
                        $r = $per_eng[$eng] ?? null;
                        if ($r === null) {
                            $cls = 'notrun'; $label = 'not run';
                        } elseif ($r['error']) {
                            $cls = 'error'; $label = 'error';
                        } elseif ($r['cited']) {
                            $cls = 'cited'; $label = '✓ cited' . ($r['position'] ? ' #' . (int)$r['position'] : '');
                        } else {
                            $cls = 'uncited'; $label = '✗ not cited';
                        }
                    ?>
                        <span class="aeo-q-engine <?= $cls ?>"><strong><?= e(aeo_engine_label($eng)) ?></strong> · <?= e($label) ?></span>
                    <?php endforeach; ?>
                </div>

                <?php if ($per_eng): ?>
                <div class="aeo-q-details">
                    <?php foreach ($per_eng as $eng => $r):
                        if ($r['error']) continue;
                    ?>
                        <div style="margin-bottom:12px;">
                            <div style="font-size:11px; color:var(--text-light); margin-bottom:4px;"><strong><?= e(aeo_engine_label($eng)) ?></strong> — checked <?= e($r['snapshot_date']) ?></div>
                            <?php if ($r['response_text']): ?>
                            <div class="aeo-response"><?= e(mb_substr($r['response_text'], 0, 500)) ?><?= mb_strlen($r['response_text']) > 500 ? '…' : '' ?></div>
                            <?php endif; ?>
                            <?php if (!empty($r['citations'])): ?>
                            <div style="font-size:11px; color:var(--text-light); margin-top:6px;">Citations:</div>
                            <div class="aeo-cite-list">
                                <?php foreach ($r['citations'] as $c): ?>
                                <a class="aeo-cite <?= !empty($c['is_ours']) ? 'ours' : '' ?>" href="<?= e($c['url']) ?>" target="_blank" rel="noopener">
                                    #<?= (int)$c['position'] ?> <?= e($c['domain']) ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php else: /* ============= RECALL TAB ============= */ ?>

    <div class="flex items-center justify-between" style="margin-bottom:10px;">
        <div style="font-size:11px; color:var(--text-light);">
            Does each AI engine REMEMBER this brand without web search? Measures presence in training data.
        </div>
        <button class="btn btn-accent btn-sm" onclick="checkRecall(this)" <?= empty($recall_engines) ? 'disabled' : '' ?>>Run recall check now</button>
    </div>

    <?php if (empty($recall_per_engine)): ?>
        <div class="aeo-empty">No recall data yet. Click "Run recall check now" — we'll ask each AI engine generic industry questions and see whether they mention <strong><?= e($site['name']) ?></strong>.</div>
    <?php else: ?>
        <div class="recall-grid">
            <?php foreach ($recall_engines as $eng):
                $r = $recall_per_engine[$eng] ?? null;
                $score = $r['score'] ?? null;
                $score_cls = $score === null ? '' : ($score < 30 ? 'low' : ($score < 60 ? 'mid' : ''));
            ?>
            <div class="recall-card">
                <div class="eng"><?= e(recall_engine_label($eng)) ?></div>
                <?php if ($r): ?>
                    <div class="score <?= $score_cls ?>"><?= (int)$score ?>%</div>
                    <div class="meta"><?= (int)$r['mentioned'] ?> of <?= (int)$r['total'] ?> questions mentioned you · <?= e($r['snapshot_date']) ?></div>
                <?php else: ?>
                    <div class="score" style="color:#cbd5e1;">—</div>
                    <div class="meta">Not run yet</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php
        // Pick the question list from the engine with most data
        $sample = null;
        foreach ($recall_per_engine as $r) { if (!empty($r['results'])) { $sample = $r['results']; break; } }
        if ($sample):
        ?>
        <div class="card">
            <div class="card-header">Per-question breakdown</div>
            <?php foreach ($sample as $i => $q): ?>
            <div class="recall-q">
                <div class="recall-q-head">
                    <div style="flex:1;">
                        <div class="recall-q-text">"<?= e($q['query']) ?>"</div>
                        <div class="aeo-q-meta">
                            <span class="aeo-cat"><?= e($q['type']) ?></span>
                            · looking for "<?= e($q['searched_for']) ?>"
                        </div>
                    </div>
                </div>
                <div class="recall-q-engines">
                    <?php foreach ($recall_engines as $eng):
                        $rEng = $recall_per_engine[$eng] ?? null;
                        $hit = $rEng['results'][$i]['mentioned'] ?? null;
                        if ($hit === null) {
                            $cls = 'notrun'; $label = 'not run';
                        } elseif ($hit) {
                            $cls = 'cited'; $label = '✓ mentioned';
                        } else {
                            $cls = 'uncited'; $label = '✗ unknown';
                        }
                    ?>
                        <span class="aeo-q-engine <?= $cls ?>"><strong><?= e(recall_engine_label($eng)) ?></strong> · <?= e($label) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

<?php endif; /* end tab */ ?>

<!-- Suggestion modal (citations tab only) -->
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
    try { await api({action:'add_query', query: text, category: cat}); window.location.reload(); }
    catch(e) { btn.disabled = false; alert(e.message); }
}

async function checkQuery(id, btn) {
    btn.disabled = true; const orig = btn.textContent; btn.textContent = '…';
    try {
        const r = await api({action:'check_query', query_id: id});
        if (!r.success) { alert('Failed: see per-engine errors'); }
        window.location.reload();
    } catch(e) { alert(e.message); btn.disabled = false; btn.textContent = orig; }
}

async function checkAll(btn) {
    if (!confirm('Run all active queries through every configured engine? This may take a few minutes.')) return;
    btn.disabled = true; btn.textContent = 'Checking…';
    try {
        const r = await api({action:'check_all'});
        alert(`Checked ${r.checked} queries × ${r.engines} engines. Cited (any engine): ${r.cited}. Errors: ${r.errors}.`);
        window.location.reload();
    } catch(e) { alert(e.message); btn.disabled = false; btn.textContent = 'Check all now'; }
}

async function checkRecall(btn) {
    if (!confirm('Ask each engine industry questions to check if they remember this brand?')) return;
    btn.disabled = true; btn.textContent = 'Running…';
    try {
        const r = await api({action:'check_recall'});
        window.location.reload();
    } catch(e) { alert(e.message); btn.disabled = false; btn.textContent = 'Run recall check now'; }
}

async function deleteQuery(id, btn) {
    if (!confirm('Delete this tracked query? History will be removed.')) return;
    try { await api({action:'delete_query', query_id: id}); btn.closest('.aeo-q').remove(); }
    catch(e) { alert(e.message); }
}

function toggleDetails(btn) { btn.closest('.aeo-q').classList.toggle('expanded'); }

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
    try { await api({action:'bulk_add', queries}); window.location.reload(); }
    catch(e) { alert(e.message); btn.disabled = false; }
}

function closeSuggest() { document.getElementById('suggestModal').style.display = 'none'; }

function escapeHtml(s) {
    return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
