<?php
/**
 * Dashboard — Keywords management.
 * Add custom keywords, ignore off-brand ones, filter, bulk-edit.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/integrations/google.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$filter_site    = $_GET['site']    ?? '';
$filter_cluster = $_GET['cluster'] ?? '';
// Default lands on All — the full keyword list, so the user always sees
// what's actually there. Bucket tabs (Quick Wins, New Content, etc.) are
// one click away when they want to drill in.
$filter_status  = $_GET['status']  ?? 'all';
$filter_intent  = $_GET['intent']  ?? '';       // informational | commercial | transactional | navigational | ''
$filter_sort    = $_GET['sort']    ?? 'score';  // score | az | za | volume | position | difficulty | impressions

$page_title = 'Keywords';

ob_start();

// Get sites
if (auth_is_super_admin()) {
    $stmt = $db->query('SELECT id, name FROM sites ORDER BY name');
} else {
    $stmt = $db->prepare('SELECT id, name FROM sites WHERE user_id = ? ORDER BY name');
    $stmt->execute([$user_id]);
}
$sites = $stmt->fetchAll();

// Build query — super-admin sees all keywords across all sites
if (auth_is_super_admin()) {
    $where = ['1=1'];
    $params = [];
} else {
    $where = ['s.user_id = ?'];
    $params = [$user_id];
}

if ($filter_site)    { $where[] = 'k.site_id = ?'; $params[] = (int)$filter_site; }
if ($filter_cluster) { $where[] = 'k.cluster = ?'; $params[] = $filter_cluster; }

if ($filter_status === 'active' || $filter_status === 'all') {
    // 'All' is now the user's actual keyword list — active rows only. Ignored
    // rows live behind the Ignored tab so the off-topic auto-filtered junk
    // doesn't pollute the main view with strikethrough noise.
    $where[] = "k.status = 'active'";
} elseif ($filter_status === 'ignored') {
    $where[] = "k.status = 'ignored'";
} elseif ($filter_status === 'quick_wins') {
    // Prefer the AI-recommended action; fall back to the old GSC-position heuristic for legacy rows
    $where[] = "k.status = 'active' AND (k.recommended_action = 'quick_win' OR (k.recommended_action IS NULL AND k.gsc_position BETWEEN 11 AND 30 AND k.impressions > 0))";
} elseif (in_array($filter_status, ['new_content', 'aeo_gap', 'watch', 'skip'], true)) {
    $where[] = "k.status = 'active' AND k.recommended_action = ?";
    $params[] = $filter_status;
}

if ($filter_intent !== '' && in_array($filter_intent, ['informational','commercial','transactional','navigational'], true)) {
    $where[] = 'k.intent = ?';
    $params[] = $filter_intent;
}

$where_sql = implode(' AND ', $where);

// Map the sort key to a safe ORDER BY clause. Whitelist to avoid SQL injection
// via $_GET. NULLS LAST in MySQL via `IS NULL` trick so unenriched rows
// (no volume/difficulty yet) don't dominate the top.
$order_by = match ($filter_sort) {
    'az'         => 'k.keyword ASC',
    'za'         => 'k.keyword DESC',
    'volume'     => 'k.search_volume IS NULL, k.search_volume DESC, k.keyword ASC',
    'position'   => 'k.gsc_position IS NULL, k.gsc_position ASC, k.keyword ASC',
    'difficulty' => 'k.difficulty IS NULL, k.difficulty ASC, k.keyword ASC',
    'impressions'=> 'k.impressions IS NULL, k.impressions DESC, k.keyword ASC',
    default      => 'COALESCE(k.opportunity_score, k.priority) DESC, k.impressions DESC, k.keyword ASC',
};

$stmt = $db->prepare("SELECT k.*, s.domain FROM keywords k JOIN sites s ON k.site_id = s.id WHERE {$where_sql} ORDER BY {$order_by} LIMIT 300");
$stmt->execute($params);
$keywords = $stmt->fetchAll();

// Per-site status + action counts
$status_counts = ['active' => 0, 'ignored' => 0, 'quick_wins' => 0, 'new_content' => 0, 'aeo_gap' => 0, 'watch' => 0, 'skip' => 0, 'all' => 0];
if ($filter_site) {
    $stmt = $db->prepare("SELECT status, COUNT(*) c FROM keywords WHERE site_id = ? GROUP BY status");
    $stmt->execute([(int)$filter_site]);
    foreach ($stmt->fetchAll() as $r) { $status_counts[$r['status']] = (int)$r['c']; }
    // 'All' now reflects what the user actually sees on the All tab (active
    // rows only). Ignored rows are accessible via the Ignored tab.
    $status_counts['all'] = $status_counts['active'];

    $stmt = $db->prepare("SELECT COUNT(*) FROM keywords WHERE site_id = ? AND status = 'active' AND (recommended_action = 'quick_win' OR (recommended_action IS NULL AND gsc_position BETWEEN 11 AND 30 AND impressions > 0))");
    $stmt->execute([(int)$filter_site]);
    $status_counts['quick_wins'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT recommended_action, COUNT(*) c FROM keywords WHERE site_id = ? AND status = 'active' AND recommended_action IS NOT NULL GROUP BY recommended_action");
    $stmt->execute([(int)$filter_site]);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($status_counts[$r['recommended_action']])) {
            // quick_wins is computed above with fallback; don't overwrite
            if ($r['recommended_action'] !== 'quick_win') $status_counts[$r['recommended_action']] = (int)$r['c'];
        }
    }

    // Count of AI-found keywords that the "Clear AI estimates" button would
    // actually delete (everything except manual + GSC-synced rows). Used for
    // the confirmation dialog so the user knows exactly what they're nuking.
    $stmt = $db->prepare("SELECT COUNT(*) FROM keywords WHERE site_id = ? AND source <> 'manual' AND (source <> 'gsc' OR gsc_synced_at IS NULL)");
    $stmt->execute([(int)$filter_site]);
    $ai_keyword_count = (int)$stmt->fetchColumn();

    // Count of active GSC-sourced keywords — fed into the "Clean off-topic
    // GSC keywords" menu item so the user knows how many will be scanned.
    $stmt = $db->prepare("SELECT COUNT(*) FROM keywords WHERE site_id = ? AND status = 'active' AND source = 'gsc'");
    $stmt->execute([(int)$filter_site]);
    $gsc_active_count = (int)$stmt->fetchColumn();
} else {
    $ai_keyword_count = 0;
    $gsc_active_count = 0;
}

// GSC integration when filtered to one site
$gsc_connected = false;
$gsc_last_sync = null;
if ($filter_site) {
    $stmt = $db->prepare('SELECT id FROM integrations WHERE site_id = ? AND platform = "google_search_console" AND is_active = 1');
    $stmt->execute([(int)$filter_site]);
    $gsc_connected = (bool)$stmt->fetch();

    $stmt = $db->prepare('SELECT MAX(gsc_synced_at) FROM keywords WHERE site_id = ?');
    $stmt->execute([(int)$filter_site]);
    $gsc_last_sync = $stmt->fetchColumn();
}

// Clusters for filter
$cluster_stmt = $db->prepare("SELECT DISTINCT k.cluster FROM keywords k JOIN sites s ON k.site_id = s.id WHERE s.user_id = ? AND k.cluster IS NOT NULL ORDER BY k.cluster");
$cluster_stmt->execute([$user_id]);
$clusters = $cluster_stmt->fetchAll(PDO::FETCH_COLUMN);

// Top-line stats (always active scope)
$stmt = $db->prepare("SELECT COUNT(*) FROM keywords k JOIN sites s ON k.site_id = s.id WHERE s.user_id = ? AND k.status = 'active'");
$stmt->execute([$user_id]);
$total_active = (int)$stmt->fetchColumn();

// Site name if filtered
$site_name_kw = '';
if ($filter_site) {
    foreach ($sites as $s) {
        if ($s['id'] == $filter_site) { $site_name_kw = $s['name']; break; }
    }
}

function tab_url($current_filters, $status) {
    $q = array_merge($current_filters, ['status' => $status]);
    return url('/dashboard/keywords.php?' . http_build_query(array_filter($q)));
}

$current_filters = ['site' => $filter_site, 'cluster' => $filter_cluster, 'sort' => $filter_sort !== 'score' ? $filter_sort : null];
?>

<?php if ($filter_site && $site_name_kw): ?>
<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/site.php?id=' . (int)$filter_site) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site_name_kw) ?></a>
</div>
<?php
    // Persistent site workflow stepper
    $site_id = (int)$filter_site;
    $site = auth_get_accessible_site($db, $site_id);
    if ($site) {
        $stepper_active = 'keywords';
        include __DIR__ . '/_site_stepper.php';
    }
?>
<?php else: ?>
<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/index.php') ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to Dashboard</a>
</div>
<?php endif; ?>


<?php if ($filter_site):
    $_dfso_ok = !empty(config('dataforseo_login')) && !empty(config('dataforseo_password'));
?>
<!-- Two-action toolbar: Add keywords + Find Keywords. Everything else (GSC
     sync, off-topic cleanup, metric refresh) is folded into Find Keywords so
     the user has exactly two decisions, not five. -->
<div class="card" style="margin-bottom:8px; padding:10px 12px;">
    <div style="display:flex; gap:8px; align-items:stretch; flex-wrap:wrap;">
        <div style="flex:1; min-width:280px; display:flex; gap:6px;">
            <input type="text" id="add-keyword-input"
                placeholder="+  Add a keyword you want to target (Enter to save)"
                style="flex:1;padding:8px 12px;font-size:13px;border:1px solid var(--border);border-radius:6px;outline:none;transition:border-color .15s;"
                onfocus="this.style.borderColor='var(--accent)'" onblur="this.style.borderColor='var(--border)'">
            <button type="button" id="add-keyword-btn"
                style="background:#fff;border:1px solid var(--border);color:#334155;padding:0 14px;font-size:13px;font-weight:500;border-radius:6px;cursor:pointer;white-space:nowrap;">Add</button>
        </div>
        <?php if ($_dfso_ok): ?>
            <button onclick="runDeepResearch(<?= (int)$filter_site ?>)" id="kr-run-btn"
                title="One click does everything: pulls fresh Google Search Console data, expands your topics into 300-500 candidates, fetches real search volume + difficulty, classifies buyer intent, auto-ignores off-topic searches, and buckets everything into Quick Wins / New Content / AEO Gaps. Runs in background, ~5-8 min."
                style="background:#7c3aed;border:1px solid #7c3aed;color:#fff;padding:8px 14px;font-size:13px;font-weight:600;border-radius:6px;cursor:pointer;white-space:nowrap;">
                🧠 Find Keywords
            </button>
        <?php else: ?>
            <a href="<?= url('/dashboard/integrations.php') ?>" style="display:inline-flex;align-items:center;padding:8px 14px;font-size:13px;background:#f1f5f9;color:#475569;border:1px solid var(--border);border-radius:6px;text-decoration:none;white-space:nowrap;">
                Connect search data →
            </a>
        <?php endif; ?>
    </div>

    <!-- Compact GSC status footer (gets repurposed as a loader by syncGsc/runDeepResearch JS) -->
    <div id="gsc-status" style="margin-top:6px;font-size:11px;color:<?= $gsc_connected ? '#065f46' : '#92400e' ?>;display:flex;align-items:center;gap:6px;">
        <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:<?= $gsc_connected ? '#10b981' : '#f59e0b' ?>;"></span>
        <?php if ($gsc_connected): ?>
            Real data from Google · Last synced <?= $gsc_last_sync ? format_date($gsc_last_sync) : 'never' ?>
        <?php else: ?>
            Showing estimates only · <a href="<?= url('/dashboard/keywords.php?site=' . (int)$filter_site . '&view=gsc') ?>" style="color:#92400e;font-weight:600;">Connect Google for real impressions, clicks, CTR</a>
        <?php endif; ?>
    </div>

    <!-- Inline status messages — only take vertical space when populated -->
    <div id="add-msg"    style="font-size:11px;"></div>
    <div id="kr-status"  style="font-size:12px;"></div>
    <div id="enrich-msg" style="font-size:11px;"></div>
</div>
<script>
// ── Deep Research (background job) ─────────────────────────────────────
async function runDeepResearch(siteId) {
    const btn    = document.getElementById('kr-run-btn');
    const status = document.getElementById('kr-status');
    btn.disabled = true;
    btn.textContent = 'Queuing…';
    status.innerHTML = '<span style="color:#64748b;">Starting background job…</span>';
    try {
        const res  = await fetch('<?= url('/api/keyword-research-start.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ site_id: siteId })
        });
        const data = await res.json();
        if (!data.success || !data.job_id) {
            status.innerHTML = '<span style="color:#dc2626;">✗ ' + (data.error || 'Failed to start') + '</span>';
            btn.disabled = false;
            btn.textContent = '🧠 Find New Keywords';
            return;
        }
        pollResearchStatus(data.job_id);
    } catch (e) {
        status.innerHTML = '<span style="color:#dc2626;">✗ ' + e.message + '</span>';
        btn.disabled = false;
        btn.textContent = '🧠 Find New Keywords';
    }
}

function pollResearchStatus(jobId) {
    const btn    = document.getElementById('kr-run-btn');
    const status = document.getElementById('kr-status');

    const tick = async () => {
        try {
            const res  = await fetch('<?= url('/api/keyword-research-status.php') ?>?id=' + jobId);
            const data = await res.json();
            const step = data.current_step || 'Working…';
            const pct  = data.progress || 0;

            if (data.status === 'running') {
                btn.textContent = step + ' (' + pct + '%)';
                status.innerHTML = '<span style="color:#7c3aed;">⟳ ' + step + ' — ' + pct + '%</span>';
                setTimeout(tick, 3000);
                return;
            }
            if (data.status === 'done') {
                const s = data.summary || {};
                const a = s.counts_by_action || {};
                const gsc = s.gsc || {};
                btn.disabled = false;
                btn.textContent = '🧠 Find Keywords';
                let parts = ['Saved ' + (s.saved || 0) + ' keywords'];
                if (gsc.inserted || gsc.updated) {
                    parts.push('GSC synced ' + ((gsc.inserted||0) + (gsc.updated||0)));
                }
                if (s.gsc_auto_ignored) {
                    parts.push('auto-ignored ' + s.gsc_auto_ignored + ' off-topic');
                }
                parts.push((a.quick_win || 0) + ' Quick Wins');
                parts.push((a.new_content || 0) + ' New Content');
                if (a.aeo_gap) parts.push(a.aeo_gap + ' AEO Gaps');
                status.innerHTML = '<span style="color:#065f46;">✓ Done. ' + parts.join(' · ') + '.</span>';
                setTimeout(() => location.reload(), 1500);
                return;
            }
            if (data.status === 'failed') {
                btn.disabled = false;
                btn.textContent = '🧠 Find New Keywords';
                status.innerHTML = '<span style="color:#dc2626;">✗ ' + (data.error || 'Job failed') + '</span>';
                return;
            }
            // unknown status — back off
            setTimeout(tick, 5000);
        } catch (e) {
            status.innerHTML = '<span style="color:#dc2626;">Polling error: ' + e.message + ' — retrying…</span>';
            setTimeout(tick, 5000);
        }
    };
    tick();
}

async function enrichKeywords(siteId, onlyMissing, btn) {
    const orig = btn.textContent;
    btn.disabled = true;
    document.querySelectorAll('button[onclick^="enrichKeywords"]').forEach(b => b.disabled = true);
    btn.textContent = 'Enriching…';
    document.getElementById('enrich-msg').innerHTML = '<span style="color:#64748b;">Refreshing metrics… can take 5-20 seconds depending on keyword count.</span>';
    try {
        const res = await fetch('<?= url('/api/keywords-enrich.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ site_id: siteId, only_missing: onlyMissing })
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('enrich-msg').innerHTML = '<span style="color:#065f46;">✓ ' + (data.message || ('Enriched ' + (data.enriched||0) + ' of ' + (data.requested||0) + ' keywords. ' + (data.with_volume||0) + ' had real volume data.')) + '</span>';
            setTimeout(() => location.reload(), 1200);
        } else {
            document.getElementById('enrich-msg').innerHTML = '<span style="color:#dc2626;">✗ ' + (data.error || 'Failed') + '</span>';
            document.querySelectorAll('button[onclick^="enrichKeywords"]').forEach(b => b.disabled = false);
            btn.textContent = orig;
        }
    } catch (e) {
        document.getElementById('enrich-msg').innerHTML = '<span style="color:#dc2626;">✗ ' + e.message + '</span>';
        document.querySelectorAll('button[onclick^="enrichKeywords"]').forEach(b => b.disabled = false);
        btn.textContent = orig;
    }
}
</script>
<?php endif; ?>

<?php
// Site/cluster filter card only renders on the multi-site "All Sites" view OR
// when this site has legacy clusters. With the new action-bucket system on a
// single site, the card was dead UI with a confusing 'Apply' button.
$show_filter_form = !$filter_site || !empty($clusters);
?>
<?php if ($show_filter_form): ?>
<div class="card" style="padding:8px 12px;margin-bottom:8px;">
    <form method="GET" class="flex gap-4 items-center" style="flex-wrap: wrap;">
        <input type="hidden" name="status" value="<?= e($filter_status) ?>">
        <?php if ($filter_site): ?>
            <input type="hidden" name="site" value="<?= (int)$filter_site ?>">
        <?php else: ?>
        <select name="site" class="form-control" style="width:auto;min-width:180px;font-size:13px;padding:5px 8px;">
            <option value="">All Sites</option>
            <?php foreach ($sites as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filter_site == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if (!empty($clusters)): ?>
        <select name="cluster" class="form-control" style="width:auto;min-width:150px;font-size:13px;padding:5px 8px;">
            <option value="">All Clusters</option>
            <?php foreach ($clusters as $c): ?>
                <option value="<?= e($c) ?>" <?= $filter_cluster === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-outline btn-sm">Apply</button>
        <?php if ($filter_site || $filter_cluster): ?>
            <a href="<?= url('/dashboard/keywords.php') ?>" class="text-sm text-muted">Clear all</a>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<!-- Single guided tab row — action buckets first, admin tabs after -->
<?php if ($filter_site): ?>
<div id="kw-tabs" style="display:flex;gap:2px;margin-bottom:6px;border-bottom:1px solid var(--border);flex-wrap:wrap;align-items:center;">
    <?php
    // All is the default landing — full list first. Action buckets follow
    // in actionability order for users who want to drill into a specific
    // category. Ignored stays at the end (admin-style, not part of the
    // main workflow).
    $tabs = [
        'all'         => ['All',           '#0f172a', $status_counts['all'],        'Every active keyword for this site. Ignored rows are in the Ignored tab.'],
        'quick_wins'  => ['💎 Quick Wins', '#10b981', $status_counts['quick_wins'], 'Already ranking page 2-3 — push to page 1.'],
        'new_content' => ['🆕 New Content','#7c3aed', $status_counts['new_content'], 'Not ranking, realistic difficulty, real buyer intent — write something.'],
        'aeo_gap'     => ['🎯 AEO Gaps',   '#0284c7', $status_counts['aeo_gap'],    'Informational queries you\'re invisible on. Candidates for AI-friendly content.'],
        'watch'       => ['👀 Watch',      '#64748b', $status_counts['watch'],      'Interesting but not actionable yet.'],
        'skip'        => ['⏭ Skip',        '#94a3b8', $status_counts['skip'],       'Wrong intent or too hard for your scale.'],
        'ignored'     => ['Ignored',       '#f59e0b', $status_counts['ignored'],    'Keywords you\'ve marked off-brand or irrelevant.'],
    ];
    foreach ($tabs as $key => [$label, $color, $count, $hint]):
        $is_active = $filter_status === $key;
    ?>
    <a href="<?= tab_url($current_filters, $key) ?>" data-status="<?= e($key) ?>" data-color="<?= e($color) ?>" title="<?= e($hint) ?>" class="kw-tab" style="text-decoration:none;padding:6px 10px;font-size:12px;border-bottom:2px solid <?= $is_active ? $color : 'transparent' ?>;color:<?= $is_active ? $color : '#64748b' ?>;font-weight:<?= $is_active ? '600' : '500' ?>;">
        <?= $label ?> <span style="font-size:11px;color:#94a3b8;">(<?= $count ?>)</span>
    </a>
    <?php endforeach; ?>
    <span style="flex:1;"></span>
    <!-- Inline legend popover trigger -->
    <a href="#" onclick="event.preventDefault();document.getElementById('kw-legend').classList.toggle('hidden');" style="font-size:11px;color:#94a3b8;text-decoration:none;padding:6px 8px;" title="What do these columns mean?">ⓘ legend</a>
</div>

<!-- Collapsible column legend (hidden by default; toggled by the ⓘ link above) -->
<div id="kw-legend" class="hidden" style="margin-bottom:6px;padding:8px 12px;background:#f8fafc;border:1px solid var(--border);border-radius:6px;font-size:12px;color:#475569;line-height:1.6;">
    <div><strong>Intent:</strong> What the searcher wants. <em>Trans</em>actional (ready to buy) · <em>Comm</em>ercial (comparing) · <em>Info</em>rmational (learning) · <em>Nav</em>igational (a specific brand).</div>
    <div><strong>Score:</strong> 0-100 opportunity score blending volume × intent × difficulty × your current rank. Higher = better.</div>
    <div><strong>Volume / Diff:</strong> Estimated monthly searches and keyword difficulty (lower = easier).</div>
    <div><strong>Impr / Pos:</strong> Your Google Search Console impressions and average rank.</div>
    <div><strong>Source:</strong> Google = real GSC · Manual = you added it · AI = ContentAgent research · Comp = from a competitor.</div>
</div>
<style>#kw-legend.hidden{display:none}</style>
<?php endif; ?>

<!--KW_TABLE_START-->
<div id="kw-table-wrapper" class="card">
    <?php if (empty($keywords)): ?>
        <p class="text-muted text-sm" style="padding: 20px; text-align: center;">
        <?php if ($filter_status === 'ignored'): ?>
            No ignored keywords. Use the 👁 button to ignore off-brand ones.
        <?php elseif ($filter_status === 'quick_wins'): ?>
            No quick wins yet. Sync Google Search Console and run <strong>🧠 Deep Research</strong> to find keywords ranked 11-30 that could be pushed to page 1.
        <?php elseif (in_array($filter_status, ['new_content','aeo_gap','watch','skip'], true)): ?>
            No keywords in this bucket yet. Run <strong>🧠 Deep Research</strong> above to populate it.
        <?php else: ?>
            No keywords yet. Run <strong>🧠 Deep Research</strong> to expand from your business profile, or add custom keywords above.
        <?php endif; ?>
        </p>
    <?php else: ?>
        <!-- Bulk action bar with sort control -->
        <div id="kw-actions-bar" style="padding:8px 12px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:#f8fafc;gap:10px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="font-size:12px;color:#64748b;">
                    <span id="kw-selected-count">0</span> selected
                </div>
                <label style="font-size:11px;color:#64748b;display:flex;align-items:center;gap:4px;">
                    Sort by
                    <select id="kw-sort" onchange="onSortChange(this.value)" style="font-size:11px;padding:3px 6px;border:1px solid var(--border);border-radius:4px;background:#fff;">
                        <option value="score"      <?= $filter_sort === 'score'      ? 'selected' : '' ?>>Score (highest first)</option>
                        <option value="volume"     <?= $filter_sort === 'volume'     ? 'selected' : '' ?>>Volume (highest first)</option>
                        <option value="position"   <?= $filter_sort === 'position'   ? 'selected' : '' ?>>Position (best rank first)</option>
                        <option value="difficulty" <?= $filter_sort === 'difficulty' ? 'selected' : '' ?>>Difficulty (easiest first)</option>
                        <option value="impressions"<?= $filter_sort === 'impressions'? 'selected' : '' ?>>Impressions (highest first)</option>
                        <option value="az"         <?= $filter_sort === 'az'         ? 'selected' : '' ?>>Keyword A → Z</option>
                        <option value="za"         <?= $filter_sort === 'za'         ? 'selected' : '' ?>>Keyword Z → A</option>
                    </select>
                </label>
            </div>
            <div style="display:flex;gap:6px;">
                <?php if ($filter_status !== 'ignored'): ?>
                <button onclick="bulkIgnore()" id="kw-ignore-btn" class="btn btn-sm" style="background:#f59e0b;color:#fff;border:none;font-size:11px;" disabled>Ignore Selected</button>
                <?php else: ?>
                <button onclick="bulkRestore()" id="kw-restore-btn" class="btn btn-sm" style="background:#10b981;color:#fff;border:none;font-size:11px;" disabled>Restore Selected</button>
                <?php endif; ?>
                <button onclick="bulkDelete()" id="kw-delete-btn" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;font-size:11px;" disabled>Delete Selected</button>
            </div>
        </div>
        <?php
        // ⓘ icon used on every heading. The browser shows the title attribute
        // on hover — accessible, no JS, no popover library.
        $info = function (string $text): string {
            return '<span style="display:inline-block;margin-left:3px;color:#cbd5e1;font-size:11px;cursor:help;" title="' . htmlspecialchars($text, ENT_QUOTES) . '">ⓘ</span>';
        };
        ?>
        <table>
            <thead>
                <tr>
                    <th style="width:32px;"><input type="checkbox" id="kw-select-all" onchange="toggleAll(this)"></th>
                    <th>Keyword<?= $info('The search phrase you target. The line below shows the buyer\'s question — what they\'re really asking when they type this.') ?></th>
                    <th>Intent<?= $info("Why the searcher is searching. Trans = ready to buy/hire · Comm = comparing options · Info = learning · Nav = looking for a specific brand.") ?></th>
                    <th>Score<?= $info('Opportunity score 0-100. Higher is a better target. Blends search volume × buyer intent × difficulty × your current rank against your business profile.') ?></th>
                    <th>Volume<?= $info('Estimated monthly Google searches for this keyword (worldwide unless GSC data narrows it).') ?></th>
                    <th>Diff<?= $info('Keyword difficulty 0-100 — how hard it is to rank for. Lower = easier. Under 30 is usually within reach for a small site; over 70 is enterprise-tough.') ?></th>
                    <th>Impr<?= $info('Times your site appeared in Google search results for this keyword in the last 30 days (from Search Console). Higher = Google already shows you for this term.') ?></th>
                    <th>Pos<?= $info('Your average rank on Google for this keyword. 1-10 = page 1, 11-30 = page 2-3, 31+ = barely visible.') ?></th>
                    <th>Source<?= $info('Where this keyword came from. Google = real Search Console data · Manual = you typed it · AI = ContentAgent\'s research · Comp = imported from a competitor.') ?></th>
                    <th>📊 Brief<?= $info('SERP content brief — AI analysis of what\'s ranking on Google for this keyword + a recommended outline to compete. Click Generate to create.') ?></th>
                    <th style="width:90px;text-align:right;">Actions<?= $info('👁 = ignore (hide from main view, restorable). ✕ = delete permanently.') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keywords as $kw):
                    $has_gsc = !empty($kw['gsc_synced_at']);
                    $source = $kw['source'] ?? 'autocomplete';
                    $is_ignored = ($kw['status'] ?? 'active') === 'ignored';
                ?>
                <tr id="kw-row-<?= (int)$kw['id'] ?>" style="<?= $is_ignored ? 'opacity:0.55;' : '' ?>">
                    <td><input type="checkbox" class="kw-check" data-id="<?= (int)$kw['id'] ?>" onchange="updateSelectedCount()"></td>
                    <td style="font-weight: 500;<?= $is_ignored ? 'text-decoration:line-through;' : '' ?>">
                        <?= e($kw['keyword']) ?>
                        <?php if (!empty($kw['buyer_question'])): ?>
                            <div style="font-size:10px;color:#64748b;margin-top:2px;font-style:italic;" title="<?= e($kw['buyer_question']) ?>">→ <?= e(mb_substr($kw['buyer_question'], 0, 90)) ?><?= mb_strlen($kw['buyer_question']) > 90 ? '…' : '' ?></div>
                        <?php endif; ?>
                        <?php if ($is_ignored && !empty($kw['ignored_reason'])): ?>
                            <div style="font-size:10px;color:#94a3b8;font-style:italic;">— <?= e($kw['ignored_reason']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $intent_styles = [
                            'transactional' => ['Trans', '#059669', '#d1fae5'],
                            'commercial'    => ['Comm',  '#0284c7', '#e0f2fe'],
                            'informational' => ['Info',  '#7c3aed', '#ede9fe'],
                            'navigational'  => ['Nav',   '#94a3b8', '#f1f5f9'],
                            'unknown'       => ['—',     '#cbd5e1', 'transparent'],
                        ];
                        $intent_val = $kw['intent'] ?? 'unknown';
                        [$ilab, $ifg, $ibg] = $intent_styles[$intent_val] ?? $intent_styles['unknown'];
                        ?>
                        <span style="font-size:10px;font-weight:600;padding:2px 6px;border-radius:8px;background:<?= $ibg ?>;color:<?= $ifg ?>;"><?= $ilab ?></span>
                    </td>
                    <td>
                        <?php
                        $score = $kw['opportunity_score'] ?? $kw['priority'] ?? null;
                        if ($score !== null):
                            $sc = $score >= 70 ? 'var(--success)' : ($score >= 40 ? 'var(--warning)' : '#94a3b8');
                            // Show recommended_action as a subtle chip under the score when present
                            $action = $kw['recommended_action'] ?? null;
                            $action_styles = [
                                'quick_win'  => ['💎',  '#10b981'],
                                'new_content'=> ['🆕',  '#7c3aed'],
                                'aeo_gap'    => ['🎯',  '#0284c7'],
                                'watch'      => ['👀',  '#64748b'],
                                'skip'       => ['⏭',  '#cbd5e1'],
                            ];
                        ?>
                            <span style="font-weight:700;color:<?= $sc ?>;font-size:14px;"><?= (int)$score ?></span>
                            <?php if ($action && isset($action_styles[$action])): ?>
                                <div style="font-size:10px;color:<?= $action_styles[$action][1] ?>;" title="<?= e($action) ?>"><?= $action_styles[$action][0] ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#cbd5e1;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm"><?= $kw['search_volume'] !== null ? number_format((int)$kw['search_volume']) : '<span style="color:#cbd5e1;">—</span>' ?></td>
                    <td class="text-sm">
                        <?php if ($kw['difficulty'] !== null):
                            $dc = $kw['difficulty'] <= 30 ? 'var(--success)' : ($kw['difficulty'] <= 60 ? 'var(--warning)' : 'var(--danger)');
                        ?>
                            <span style="color:<?= $dc ?>;font-weight:600;"><?= (int)$kw['difficulty'] ?></span>
                        <?php else: ?>
                            <span style="color:#cbd5e1;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm"><?= $kw['impressions'] !== null ? number_format($kw['impressions']) : '<span style="color:#cbd5e1;">—</span>' ?></td>
                    <td>
                        <?php
                        $pos = $kw['gsc_position'] ?? $kw['current_rank'] ?? null;
                        if ($pos !== null && $pos > 0):
                            $pc = $pos <= 10 ? 'var(--success)' : ($pos <= 30 ? 'var(--warning)' : 'var(--danger)');
                        ?>
                            <span style="font-weight:600;color:<?= $pc ?>;">#<?= is_numeric($pos) ? (floor($pos) == $pos ? (int)$pos : number_format($pos, 1)) : $pos ?></span>
                        <?php else: ?>
                            <span style="color:#cbd5e1;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $src_styles = [
                            'gsc'                    => ['Google', '#10b981', '#d1fae5'],
                            'manual'                 => ['Manual', '#7c3aed', '#ede9fe'],
                            'autocomplete'           => ['AI',     '#64748b', '#f1f5f9'],
                            'paa'                    => ['PAA',    '#0284c7', '#e0f2fe'],
                            'dataforseo_ideas'       => ['AI',     '#64748b', '#f1f5f9'],
                            'dataforseo_suggestions' => ['AI',     '#64748b', '#f1f5f9'],
                            'competitor'             => ['Comp',   '#ea580c', '#ffedd5'],
                        ];
                        [$slabel, $sfg, $sbg] = $src_styles[$source] ?? $src_styles['autocomplete'];
                        ?>
                        <span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;background:<?= $sbg ?>;color:<?= $sfg ?>;"><?= $slabel ?></span>
                    </td>
                    <td>
                        <?php $has_brief = !empty($kw['serp_brief']); ?>
                        <?php if ($has_brief): ?>
                            <button onclick="viewBrief(<?= (int)$kw['id'] ?>)" title="View SERP brief" style="background:#d1fae5;border:none;color:#065f46;cursor:pointer;font-size:11px;padding:3px 8px;border-radius:4px;font-weight:600;">✓ View</button>
                        <?php else: ?>
                            <button onclick="generateBrief(<?= (int)$kw['id'] ?>, this)" title="Generate SERP brief" style="background:transparent;border:1px solid var(--border);color:#64748b;cursor:pointer;font-size:11px;padding:3px 8px;border-radius:4px;">Generate</button>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;white-space:nowrap;">
                        <?php if ($is_ignored): ?>
                            <button onclick="restoreOne(<?= (int)$kw['id'] ?>)" title="Restore (un-ignore)" style="background:transparent;border:none;color:#10b981;cursor:pointer;font-size:14px;padding:2px 4px;">↺</button>
                        <?php else: ?>
                            <button onclick="ignoreOne(<?= (int)$kw['id'] ?>)" title="Ignore this keyword" style="background:transparent;border:none;color:#f59e0b;cursor:pointer;font-size:14px;padding:2px 4px;">👁</button>
                        <?php endif; ?>
                        <button onclick="deleteOne(<?= (int)$kw['id'] ?>)" title="Delete this keyword permanently" style="background:transparent;border:none;color:#dc2626;cursor:pointer;font-size:14px;padding:2px 4px;">✕</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<!--KW_TABLE_END-->

<script>
const KW_API = '<?= url('/api/keywords-manage.php') ?>';
const SITE_ID = <?= $filter_site ? (int)$filter_site : 'null' ?>;

// Sort dropdown handler — updates the URL and triggers the existing AJAX
// table swap so the user keeps their scroll position and the rest of the
// page stays put.
function onSortChange(sort) {
    const url = new URL(window.location);
    if (sort && sort !== 'score') url.searchParams.set('sort', sort);
    else                          url.searchParams.delete('sort');
    const wrapper = document.getElementById('kw-table-wrapper');
    if (!wrapper) { window.location = url.toString(); return; }
    wrapper.style.opacity = '0.5';
    const sep = url.search ? '&' : '?';
    fetch(url.toString() + sep + 'partial=table', { credentials: 'same-origin' })
        .then(r => r.text())
        .then(html => {
            const tmp = document.createElement('div');
            tmp.innerHTML = html.trim();
            const fresh = tmp.querySelector('#kw-table-wrapper');
            if (fresh) {
                wrapper.replaceWith(fresh);
                if (window.history && history.pushState) history.pushState({}, '', url.toString());
            } else {
                window.location = url.toString();
            }
        })
        .catch(() => { window.location = url.toString(); });
}

// Wire up the keyword-add input + button. Inline onkeydown wasn't always
// firing (probably swallowed by browser autofill or focus shifts) — explicit
// listeners are more reliable.
(function () {
    const input = document.getElementById('add-keyword-input');
    const btn   = document.getElementById('add-keyword-btn');
    if (input) {
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); addKeywords(); }
        });
    }
    if (btn) btn.addEventListener('click', (e) => { e.preventDefault(); addKeywords(); });
})();

// Clean off-topic GSC keywords — runs the Claude relevance filter against
// every currently-active GSC-imported keyword and auto-ignores anything that
// doesn't match the business profile. Surfaces the count so the user can
// audit + restore from the Ignored tab.
async function cleanOfftopicGsc(siteId, count) {
    const msg = 'Scan ' + count + ' Google-imported keyword' + (count === 1 ? '' : 's') + ' and auto-ignore the ones that aren\'t relevant to your business?'
              + '\n\nUses your business profile to decide. Anything ignored can be restored from the Ignored tab.';
    if (!confirm(msg)) return;
    const dot = document.getElementById('gsc-status');
    if (dot) dot.innerHTML = '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#7c3aed;"></span> Scanning ' + count + ' Google keywords for relevance…';
    try {
        const res = await fetch('<?= url('/api/keywords-clean-offtopic.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ site_id: siteId })
        });
        const data = await res.json();
        if (data.success) {
            if (dot) dot.innerHTML = '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#10b981;"></span> Done — auto-ignored ' + data.ignored + ' off-topic keyword' + (data.ignored === 1 ? '' : 's') + '. Refreshing…';
            setTimeout(() => location.reload(), 1000);
        } else {
            if (dot) dot.innerHTML = '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#dc2626;"></span> Failed: ' + (data.error || 'unknown');
        }
    } catch (e) {
        if (dot) dot.innerHTML = '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#dc2626;"></span> Error: ' + e.message;
    }
}

// GSC sync — replaces the full-page navigation with an inline AJAX call so
// the user gets a loader and stays on the keywords page. The legacy URL
// keywords.php?view=gsc&action=sync still works as a fallback.
async function syncGsc(siteId) {
    const dot = document.getElementById('gsc-status');
    if (dot) dot.innerHTML = '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#7c3aed;"></span> Syncing Google Search Console…';
    try {
        const res  = await fetch('<?= url('/dashboard/keywords.php?view=gsc&action=sync&ajax=1&site=' . (int)$filter_site) ?>', {credentials: 'same-origin'});
        const text = await res.text();
        // The current sync endpoint returns HTML — we just need to know it succeeded.
        const ok = res.ok && (text.includes('Synced') || text.includes('synced'));
        if (ok) {
            if (dot) dot.innerHTML = '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#10b981;"></span> Google sync complete — refreshing…';
            setTimeout(() => location.reload(), 700);
        } else {
            if (dot) dot.innerHTML = '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#dc2626;"></span> Google sync failed. <a href="<?= url('/dashboard/keywords.php?site=' . (int)$filter_site . '&view=gsc') ?>" style="color:#dc2626;font-weight:600;">Open GSC view →</a>';
        }
    } catch (e) {
        if (dot) dot.innerHTML = '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#dc2626;"></span> Sync error: ' + e.message;
    }
}

function toggleAll(cb) {
    document.querySelectorAll('.kw-check').forEach(c => c.checked = cb.checked);
    updateSelectedCount();
}
function updateSelectedCount() {
    const n = document.querySelectorAll('.kw-check:checked').length;
    document.getElementById('kw-selected-count').textContent = n;
    ['kw-delete-btn','kw-ignore-btn','kw-restore-btn'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = n === 0;
    });
}
function selectedIds() {
    return Array.from(document.querySelectorAll('.kw-check:checked')).map(c => parseInt(c.dataset.id));
}
async function call(body) {
    try {
        const res = await fetch(KW_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
        const data = await res.json();
        if (data.success) location.reload();
        else alert('Failed: ' + (data.error || 'unknown'));
    } catch(e) { alert('Error: ' + e.message); }
}

async function addKeywords() {
    if (!SITE_ID) return;
    const input = document.getElementById('add-keyword-input');
    const raw = input.value.trim();
    if (!raw) return;
    const keywords = raw.split(/[,\n]/).map(s => s.trim()).filter(Boolean);
    if (!keywords.length) return;
    const msg = document.getElementById('add-msg');
    msg.innerHTML = '<span style="color:#64748b;">Adding...</span>';
    try {
        const res = await fetch(KW_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'add', site_id: SITE_ID, keywords})});
        const data = await res.json();
        if (data.success) {
            const added = data.added || 0;
            const skipped = data.skipped || 0;
            const noun = added === 1 ? 'keyword' : 'keywords';
            const suffix = skipped > 0 ? ' (' + skipped + ' already existed)' : '';
            msg.innerHTML = '<span style="color:#065f46;">✓ Added ' + added + ' ' + noun + suffix + ' — switching to All tab so you can see them.</span>';
            input.value = '';
            // Land on All tab where the new manual keywords are guaranteed to
            // be visible — they have no recommended_action yet, so they're
            // invisible on bucket views like Quick Wins / New Content.
            setTimeout(() => {
                const url = new URL(window.location);
                url.searchParams.set('status', 'all');
                window.location = url.toString();
            }, 900);
        } else {
            msg.innerHTML = '<span style="color:#dc2626;">' + (data.error || 'Failed') + '</span>';
        }
    } catch(e) { msg.innerHTML = '<span style="color:#dc2626;">Error: ' + e.message + '</span>'; }
}

function ignoreOne(id) {
    const reason = prompt('Why are you ignoring this keyword? (optional — e.g. "wrong brand", "irrelevant")', '');
    if (reason === null) return; // cancelled
    call({action:'ignore', ids:[id], reason});
}
function restoreOne(id) { call({action:'restore', ids:[id]}); }
function deleteOne(id)  { if (confirm('Delete this keyword permanently?')) call({action:'delete', ids:[id]}); }

function bulkIgnore() {
    const ids = selectedIds(); if (!ids.length) return;
    const reason = prompt('Reason for ignoring ' + ids.length + ' keywords? (optional)', '');
    if (reason === null) return;
    call({action:'ignore', ids, reason});
}
function bulkRestore() {
    const ids = selectedIds(); if (!ids.length) return;
    if (!confirm('Restore ' + ids.length + ' keyword' + (ids.length>1?'s':'') + '?')) return;
    call({action:'restore', ids});
}
function bulkDelete() {
    const ids = selectedIds(); if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' keyword' + (ids.length>1?'s':'') + ' permanently?')) return;
    call({action:'delete', ids});
}
function deleteAll(siteId, count) {
    const n = count ?? 0;
    const msg = 'Delete ' + n + ' AI-found keyword' + (n === 1 ? '' : 's') + '?'
              + '\n\nThis will permanently remove every keyword our research agent discovered for this site.'
              + '\n\nKept safe:'
              + '\n  • Keywords you typed in manually'
              + '\n  • Keywords with real Google Search Console data'
              + '\n\nYou can re-run "Find New Keywords" to repopulate.';
    if (!confirm(msg)) return;
    call({action:'delete_all', site_id: siteId});
}

// ── SERP Brief ─────────────────────────────────────────────────
const SERP_API = '<?= url('/api/serp-brief.php') ?>';

async function generateBrief(keywordId, btn) {
    btn.disabled = true;
    btn.textContent = 'Analysing...';
    try {
        const res = await fetch(SERP_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({keyword_id: keywordId})});
        const data = await res.json();
        if (data.success && data.brief) {
            showBriefModal(data.brief, data.briefed_at);
            // Update the button to "View" instead of reloading the whole page
            btn.outerHTML = '<button onclick="viewBrief(' + keywordId + ')" style="background:#d1fae5;border:none;color:#065f46;cursor:pointer;font-size:11px;padding:3px 8px;border-radius:4px;font-weight:600;">✓ View</button>';
        } else {
            alert('Failed: ' + (data.error || 'unknown'));
            btn.disabled = false;
            btn.textContent = 'Generate';
        }
    } catch(e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.textContent = 'Generate';
    }
}

async function viewBrief(keywordId) {
    try {
        const res = await fetch(SERP_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({keyword_id: keywordId})});
        const data = await res.json();
        if (data.success && data.brief) {
            showBriefModal(data.brief, data.briefed_at);
        } else {
            alert('Failed: ' + (data.error || 'unknown'));
        }
    } catch(e) { alert('Error: ' + e.message); }
}

function showBriefModal(brief, briefedAt) {
    const m = document.getElementById('brief-modal');
    const body = document.getElementById('brief-modal-body');

    const formatBadge = (val) => '<span style="display:inline-block;padding:2px 8px;background:#dbeafe;color:#1e40af;border-radius:10px;font-size:11px;font-weight:600;">' + val + '</span>';
    const intentBadge = (val) => '<span style="display:inline-block;padding:2px 8px;background:#fef3c7;color:#92400e;border-radius:10px;font-size:11px;font-weight:600;">' + val + '</span>';
    const diffBadge = (val) => {
        const colors = {easy:'#d1fae5,#065f46', medium:'#fef3c7,#92400e', hard:'#fecaca,#991b1b'};
        const c = (colors[val] || colors.medium).split(',');
        return '<span style="display:inline-block;padding:2px 8px;background:' + c[0] + ';color:' + c[1] + ';border-radius:10px;font-size:11px;font-weight:600;">' + val + '</span>';
    };

    let html = '<div style="padding:14px;">';
    html += '<div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap;">';
    if (brief.format) html += formatBadge(brief.format);
    if (brief.intent) html += intentBadge(brief.intent);
    if (brief.competitive_difficulty) html += diffBadge(brief.competitive_difficulty);
    if (brief.avg_word_count) html += '<span style="font-size:11px;color:#64748b;padding:2px 8px;">≈ ' + brief.avg_word_count + ' words</span>';
    html += '</div>';

    if (brief.winning_pattern) {
        html += '<div style="background:#f0fdf4;border-left:3px solid #10b981;padding:8px 12px;font-size:13px;margin-bottom:10px;"><strong>Winning pattern:</strong> ' + escapeHtml(brief.winning_pattern) + '</div>';
    }

    if (brief.notes) {
        html += '<div style="font-size:12px;color:#64748b;margin-bottom:12px;font-style:italic;">' + escapeHtml(brief.notes) + '</div>';
    }

    if (brief.recommended_outline && brief.recommended_outline.length) {
        html += '<div style="font-weight:600;font-size:12px;margin-bottom:6px;color:#475569;">RECOMMENDED OUTLINE</div>';
        html += '<ol style="font-size:13px;line-height:1.7;margin-bottom:14px;padding-left:18px;">';
        brief.recommended_outline.forEach(h => html += '<li>' + escapeHtml(h) + '</li>');
        html += '</ol>';
    }

    if (brief.common_themes && brief.common_themes.length) {
        html += '<div style="font-weight:600;font-size:12px;margin-bottom:6px;color:#475569;">COMMON THEMES ACROSS TOP RESULTS</div>';
        html += '<div style="margin-bottom:14px;">';
        brief.common_themes.forEach(t => {
            html += '<span style="display:inline-block;margin:2px 4px 2px 0;padding:2px 8px;background:#f1f5f9;border-radius:10px;font-size:11px;color:#475569;">' + escapeHtml(t) + '</span>';
        });
        html += '</div>';
    }

    if (brief.top_results && brief.top_results.length) {
        html += '<div style="font-weight:600;font-size:12px;margin-bottom:6px;color:#475569;">TOP 10 RESULTS' + (brief.own_ranked ? ' · <span style="color:#10b981;">You rank #' + brief.own_position + '</span>' : ' · <span style="color:#dc2626;">You don\'t rank top 10</span>') + '</div>';
        html += '<table style="width:100%;font-size:12px;border-collapse:collapse;">';
        brief.top_results.forEach(r => {
            html += '<tr style="border-bottom:1px solid #f1f5f9;">';
            html += '<td style="padding:4px 8px;color:#94a3b8;font-weight:600;">#' + r.position + '</td>';
            html += '<td style="padding:4px 8px;"><a href="' + escapeHtml(r.url) + '" target="_blank" style="color:var(--primary);text-decoration:none;">' + escapeHtml(r.title.substring(0, 80)) + '</a><div style="font-size:10px;color:#94a3b8;">' + escapeHtml(r.host) + (r.word_count ? ' · ' + r.word_count + ' words' : '') + '</div></td>';
            html += '</tr>';
        });
        html += '</table>';
    }

    html += '<div style="margin-top:14px;padding-top:10px;border-top:1px solid var(--border);font-size:11px;color:#94a3b8;text-align:center;">Generated: ' + briefedAt + '</div>';
    html += '</div>';

    body.innerHTML = html;
    m.style.display = 'flex';
}

function closeBrief() {
    document.getElementById('brief-modal').style.display = 'none';
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
}
</script>

<!-- SERP Brief modal -->
<div id="brief-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:1000;align-items:center;justify-content:center;" onclick="if(event.target.id==='brief-modal')closeBrief()">
    <div style="background:#fff;border-radius:8px;width:90%;max-width:680px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid var(--border);">
            <div style="font-weight:600;font-size:14px;color:var(--primary);">📊 SERP Brief</div>
            <button onclick="closeBrief()" style="background:transparent;border:none;font-size:20px;cursor:pointer;color:#94a3b8;line-height:1;">×</button>
        </div>
        <div id="brief-modal-body"></div>
    </div>
</div>

<script>
// ── Tab AJAX swap ───────────────────────────────────────────────────
// Click a bucket tab → fetch only the table partial → swap in place,
// update tab highlighting, and update the URL via pushState so the
// browser back button works. No scroll-jump, no full reload.
(function () {
    const tabs = document.querySelectorAll('.kw-tab');
    if (!tabs.length) return;

    tabs.forEach(tab => {
        tab.addEventListener('click', async (e) => {
            e.preventDefault();
            const target = tab.getAttribute('href');
            if (!target) return;

            // Re-query the wrapper INSIDE the handler — after the first swap
            // the captured node is detached, so a closure-captured const
            // would silently no-op on subsequent clicks. This was the bug
            // where tabs stopped working after one click.
            const wrapper = document.getElementById('kw-table-wrapper');
            if (!wrapper) { window.location = target; return; }

            // Style update — set this tab active, clear others
            tabs.forEach(t => {
                const c = t.getAttribute('data-color') || '#0f172a';
                if (t === tab) {
                    t.style.borderBottom = '2px solid ' + c;
                    t.style.color = c;
                    t.style.fontWeight = '600';
                } else {
                    t.style.borderBottom = '2px solid transparent';
                    t.style.color = '#64748b';
                    t.style.fontWeight = '500';
                }
            });

            wrapper.style.opacity = '0.5';
            try {
                const sep = target.includes('?') ? '&' : '?';
                const res = await fetch(target + sep + 'partial=table', { credentials: 'same-origin' });
                const html = await res.text();
                const tmp = document.createElement('div');
                tmp.innerHTML = html.trim();
                const fresh = tmp.querySelector('#kw-table-wrapper');
                if (fresh) {
                    wrapper.replaceWith(fresh);
                    if (window.history && history.pushState) history.pushState({}, '', target);
                } else {
                    window.location = target;
                }
            } catch (err) {
                window.location = target;
            }
        });
    });
})();
</script>

<?php
$full_page = ob_get_clean();

// Partial mode — when called with ?partial=table, return only the table
// card so the tab AJAX swap can drop it into the existing page without a
// full reload. Everything else (stepper, deep-research card, tabs) stays
// on screen and scroll position is preserved.
if (($_GET['partial'] ?? '') === 'table') {
    if (preg_match('/<!--KW_TABLE_START-->(.*)<!--KW_TABLE_END-->/s', $full_page, $m)) {
        header('Content-Type: text/html; charset=utf-8');
        echo $m[1];
        exit;
    }
}

$page_content = $full_page;
require __DIR__ . '/../../templates/dashboard/layout.php';
