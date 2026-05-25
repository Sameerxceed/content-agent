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
// Default lands on Quick Wins (the most actionable bucket). Falls back to
// 'all' below if the site has nothing in Quick Wins yet, so a first-time
// visitor doesn't see an empty page.
$filter_status  = $_GET['status']  ?? 'quick_wins';
$filter_intent  = $_GET['intent']  ?? '';       // informational | commercial | transactional | navigational | ''
$top_view       = $_GET['view']    ?? 'keywords'; // keywords | gsc

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

$stmt = $db->prepare("SELECT k.*, s.domain FROM keywords k JOIN sites s ON k.site_id = s.id WHERE {$where_sql} ORDER BY COALESCE(k.opportunity_score, k.priority) DESC, k.impressions DESC, k.keyword LIMIT 300");
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

$current_filters = ['site' => $filter_site, 'cluster' => $filter_cluster];
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

<?php if ($filter_site): ?>
<!-- Top-level view tabs: Keywords vs GSC Data -->
<div style="display:flex;gap:2px;border-bottom:1px solid var(--border);margin-bottom:14px;">
    <a href="<?= url('/dashboard/keywords.php?site=' . (int)$filter_site . '&view=keywords') ?>" style="text-decoration:none;padding:10px 16px;font-size:13px;border-bottom:2px solid <?= $top_view === 'keywords' ? 'var(--accent)' : 'transparent' ?>;color:<?= $top_view === 'keywords' ? 'var(--accent)' : '#64748b' ?>;font-weight:<?= $top_view === 'keywords' ? '600' : '500' ?>;">Keywords</a>
    <a href="<?= url('/dashboard/keywords.php?site=' . (int)$filter_site . '&view=gsc') ?>" style="text-decoration:none;padding:10px 16px;font-size:13px;border-bottom:2px solid <?= $top_view === 'gsc' ? 'var(--accent)' : 'transparent' ?>;color:<?= $top_view === 'gsc' ? 'var(--accent)' : '#64748b' ?>;font-weight:<?= $top_view === 'gsc' ? '600' : '500' ?>;">📈 GSC Data</a>
</div>
<?php endif; ?>

<?php if ($top_view === 'gsc' && $filter_site):
    // ── GSC Data view (was search-console.php) ──────────────
    $stmt = $db->prepare('SELECT * FROM integrations WHERE site_id = ? AND platform = "google_search_console" AND is_active = 1');
    $stmt->execute([(int)$filter_site]);
    $integration = $stmt->fetch();

    $gsc_site = auth_get_accessible_site($db, (int)$filter_site);

    if (!$integration): ?>
        <div class="card" style="text-align:center;padding:40px;">
            <div style="font-size:48px;margin-bottom:10px;">📊</div>
            <h3 style="margin-bottom:4px;">Connect Google Search Console</h3>
            <p class="text-muted text-sm" style="max-width:500px;margin:0 auto 16px;">See real keyword rankings, clicks, impressions, and CTR from Google.</p>
            <?php if (empty(config('google_client_id'))): ?>
                <div class="alert alert-warning" style="max-width:500px;margin:0 auto;">
                    Google OAuth credentials not configured. <a href="<?= url('/dashboard/integrations.php') ?>">Set up in Integrations Hub</a>.
                </div>
            <?php else: ?>
                <a href="<?= e(google_get_auth_url((int)$filter_site)) ?>" class="btn btn-primary" style="padding:10px 24px;">Connect Google Search Console →</a>
            <?php endif; ?>
        </div>
    <?php else:
        $access_token = google_get_token($db, (int)$filter_site);
        if (($_GET['action'] ?? '') === 'sync' && $access_token) {
            $sync_result = google_update_rankings($db, (int)$filter_site);
            if ($sync_result['success']) {
                $total = ($sync_result['updated'] ?? 0) + ($sync_result['inserted'] ?? 0);
                $auto_ig = (int)($sync_result['auto_ignored'] ?? 0);
                $extra = $auto_ig > 0 ? ' · auto-filtered ' . $auto_ig . ' off-topic queries to the Ignored tab' : '';
                echo '<div class="alert alert-success">Synced ' . $total . ' keywords' . $extra . '</div>';
            } else {
                echo '<div class="alert alert-error">Sync failed: ' . e($sync_result['error'] ?? 'Unknown') . '</div>';
            }
        }
        $summary = $access_token ? google_performance_summary($db, (int)$filter_site, 30) : null;
        $page_data = $access_token ? google_page_performance($db, (int)$filter_site, 30) : null;
    ?>
        <div class="flex justify-between items-center mb-4">
            <div class="text-sm text-muted">
                Connected as: <strong><?= e($integration['account_name'] ?? 'Google Account') ?></strong>
                · Last synced: <?= $integration['updated_at'] ? format_date($integration['updated_at']) : 'Never' ?>
            </div>
            <a href="<?= url('/dashboard/keywords.php?site=' . (int)$filter_site . '&view=gsc&action=sync') ?>" class="btn btn-primary btn-sm">🔄 Sync Rankings</a>
        </div>

        <?php if ($summary): ?>
        <div class="stats-grid" style="margin-bottom:14px;">
            <div class="stat-card"><div class="stat-label">Clicks (30 days)</div><div class="stat-value"><?= number_format($summary['clicks']) ?></div></div>
            <div class="stat-card"><div class="stat-label">Impressions</div><div class="stat-value"><?= number_format($summary['impressions']) ?></div></div>
            <div class="stat-card"><div class="stat-label">Avg CTR</div><div class="stat-value"><?= $summary['ctr'] ?>%</div></div>
            <div class="stat-card"><div class="stat-label">Avg Position</div><div class="stat-value"><?= $summary['position'] ?></div></div>
        </div>
        <?php endif; ?>

        <?php if ($page_data && !empty($page_data['rows'])): ?>
        <div class="card">
            <div class="card-header">Top Pages (last 30 days)</div>
            <table>
                <thead><tr><th>Page</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Avg Position</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($page_data['rows'], 0, 20) as $row): ?>
                    <tr>
                        <td class="text-sm" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <a href="<?= e($row['keys'][0]) ?>" target="_blank" style="color:var(--primary);text-decoration:none;"><?= e(str_replace('https://www.' . $gsc_site['domain'], '', $row['keys'][0])) ?></a>
                        </td>
                        <td><?= $row['clicks'] ?></td>
                        <td><?= number_format($row['impressions']) ?></td>
                        <td><?= round($row['ctr'] * 100, 1) ?>%</td>
                        <td><?= round($row['position'], 1) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="card" style="padding:30px;text-align:center;color:#94a3b8;">No page data yet. Click Sync to pull from Google.</div>
        <?php endif; ?>

    <?php endif;
    // Skip the rest of the page (keywords list)
    $page_content = ob_get_clean();
    require __DIR__ . '/../../templates/dashboard/layout.php';
    return;
endif; ?>

<?php if ($filter_site):
    $_dfso_ok = !empty(config('dataforseo_login')) && !empty(config('dataforseo_password'));
?>
<!-- Consolidated keyword toolbar: add input + Find New Keywords CTA + GSC status footer + more-options menu -->
<div class="card" style="margin-bottom:8px; padding:10px 12px;">
    <!-- Row 1: primary actions — add manual keyword + Find New Keywords -->
    <div style="display:flex; gap:8px; align-items:stretch; flex-wrap:wrap;">
        <div style="flex:1; min-width:280px; position:relative; display:flex; gap:6px;">
            <input type="text" id="add-keyword-input"
                placeholder="+  Add a keyword you want to target (Enter to save)"
                style="flex:1;padding:8px 12px;font-size:13px;border:1px solid var(--border);border-radius:6px;outline:none;transition:border-color .15s;"
                onfocus="this.style.borderColor='var(--accent)'" onblur="this.style.borderColor='var(--border)'">
            <button type="button" id="add-keyword-btn"
                style="background:#fff;border:1px solid var(--border);color:#334155;padding:0 14px;font-size:13px;font-weight:500;border-radius:6px;cursor:pointer;white-space:nowrap;">Add</button>
        </div>
        <?php if ($_dfso_ok): ?>
            <button onclick="runDeepResearch(<?= (int)$filter_site ?>)" id="kr-run-btn"
                title="Expands your topics into 300-500 candidates, fetches real search data, infers buyer intent, scores everything against your business profile. Runs in background, ~3-8 min."
                style="background:#7c3aed;border:1px solid #7c3aed;color:#fff;padding:8px 14px;font-size:13px;font-weight:600;border-radius:6px;cursor:pointer;white-space:nowrap;">
                🧠 Find New Keywords
            </button>
        <?php else: ?>
            <a href="<?= url('/dashboard/integrations.php') ?>" style="display:inline-flex;align-items:center;padding:8px 14px;font-size:13px;background:#f1f5f9;color:#475569;border:1px solid var(--border);border-radius:6px;text-decoration:none;white-space:nowrap;">
                Connect search data →
            </a>
        <?php endif; ?>
        <div style="position:relative;">
            <button onclick="toggleKwMenu(event)" id="kw-more-btn"
                style="background:#fff;border:1px solid var(--border);color:#475569;padding:8px 12px;font-size:14px;border-radius:6px;cursor:pointer;"
                title="More options">⋯</button>
            <div id="kw-more-menu" style="display:none;position:absolute;right:0;top:calc(100% + 4px);background:#fff;border:1px solid var(--border);border-radius:6px;box-shadow:0 8px 20px rgba(15,23,42,.08);min-width:260px;z-index:50;overflow:hidden;">
                <?php if ($_dfso_ok): ?>
                <a href="#" onclick="event.preventDefault();hideKwMenu();enrichKeywords(<?= (int)$filter_site ?>, true, this);return false;" class="kw-menu-item" style="display:block;padding:10px 14px;font-size:13px;color:#334155;text-decoration:none;cursor:pointer;">
                    <span style="display:block;font-weight:500;">🔄 Just refresh metrics</span>
                    <span style="display:block;font-size:11px;color:#94a3b8;margin-top:2px;">Re-pull volume + difficulty for existing keywords only</span>
                </a>
                <?php endif; ?>
                <?php if ($gsc_connected): ?>
                <a href="#" onclick="event.preventDefault();hideKwMenu();syncGsc(<?= (int)$filter_site ?>);return false;" class="kw-menu-item" style="display:block;padding:10px 14px;font-size:13px;color:#334155;text-decoration:none;cursor:pointer;border-top:1px solid #f1f5f9;">
                    <span style="display:block;font-weight:500;">🔁 Re-sync Google Search Console</span>
                    <span style="display:block;font-size:11px;color:#94a3b8;margin-top:2px;">Pull fresh impressions, clicks, and position</span>
                </a>
                <?php else: ?>
                <a href="<?= url('/dashboard/keywords.php?site=' . (int)$filter_site . '&view=gsc') ?>" class="kw-menu-item" style="display:block;padding:10px 14px;font-size:13px;color:#334155;text-decoration:none;cursor:pointer;border-top:1px solid #f1f5f9;">
                    <span style="display:block;font-weight:500;">🔗 Connect Google Search Console</span>
                    <span style="display:block;font-size:11px;color:#94a3b8;margin-top:2px;">Get real ranking data instead of estimates</span>
                </a>
                <?php endif; ?>
                <?php if ($gsc_active_count > 0): ?>
                <a href="#" onclick="event.preventDefault();hideKwMenu();cleanOfftopicGsc(<?= (int)$filter_site ?>, <?= $gsc_active_count ?>);return false;" class="kw-menu-item" style="display:block;padding:10px 14px;font-size:13px;color:#334155;text-decoration:none;cursor:pointer;border-top:1px solid #f1f5f9;">
                    <span style="display:block;font-weight:500;">🧹 Clean off-topic Google keywords (<?= number_format($gsc_active_count) ?>)</span>
                    <span style="display:block;font-size:11px;color:#94a3b8;margin-top:2px;">Auto-ignore searches that aren't relevant to your business</span>
                </a>
                <?php endif; ?>
                <?php if ($ai_keyword_count > 0): ?>
                <a href="#" onclick="event.preventDefault();hideKwMenu();deleteAll(<?= (int)$filter_site ?>, <?= $ai_keyword_count ?>);return false;" class="kw-menu-item kw-menu-danger" style="display:block;padding:10px 14px;font-size:13px;color:#dc2626;text-decoration:none;cursor:pointer;border-top:1px solid #f1f5f9;">
                    <span style="display:block;font-weight:500;">🗑 Clear AI-found keywords (<?= number_format($ai_keyword_count) ?>)</span>
                    <span style="display:block;font-size:11px;color:#94a3b8;margin-top:2px;">Manual + Google-synced rows are preserved</span>
                </a>
                <?php endif; ?>
            </div>
            <style>
                .kw-menu-item:hover { background:#f8fafc; }
                .kw-menu-item.kw-menu-danger:hover { background:#fef2f2; }
            </style>
        </div>
    </div>

    <!-- Row 2: GSC sync status — compact footer line (id let's syncGsc() update it in place) -->
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
function toggleKwMenu(e) {
    e.stopPropagation();
    const m = document.getElementById('kw-more-menu');
    m.style.display = m.style.display === 'none' ? 'block' : 'none';
}
function hideKwMenu() { document.getElementById('kw-more-menu').style.display = 'none'; }
document.addEventListener('click', (e) => {
    const m = document.getElementById('kw-more-menu');
    const b = document.getElementById('kw-more-btn');
    if (m && b && !m.contains(e.target) && e.target !== b) m.style.display = 'none';
});
</script>
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
                btn.disabled = false;
                btn.textContent = '🧠 Find New Keywords';
                const msg = '✓ Done. Saved ' + (s.saved || 0) + ' keywords from ' + (s.total_raw || 0) + ' candidates · '
                    + (a.quick_win   || 0) + ' Quick Wins · '
                    + (a.new_content || 0) + ' New Content · '
                    + (a.aeo_gap     || 0) + ' AEO Gaps';
                status.innerHTML = '<span style="color:#065f46;">' + msg + '</span>';
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
    // Action buckets — what the user should DO. Listed in actionability order.
    $tabs = [
        'quick_wins'  => ['💎 Quick Wins', '#10b981', $status_counts['quick_wins'], 'Already ranking page 2-3 — push to page 1.'],
        'new_content' => ['🆕 New Content','#7c3aed', $status_counts['new_content'], 'Not ranking, realistic difficulty, real buyer intent — write something.'],
        'aeo_gap'     => ['🎯 AEO Gaps',   '#0284c7', $status_counts['aeo_gap'],    'Informational queries you\'re invisible on. Candidates for AI-friendly content.'],
        'watch'       => ['👀 Watch',      '#64748b', $status_counts['watch'],      'Interesting but not actionable yet.'],
        'skip'        => ['⏭ Skip',        '#94a3b8', $status_counts['skip'],       'Wrong intent or too hard for your scale.'],
        'ignored'     => ['Ignored',       '#f59e0b', $status_counts['ignored'],    'Keywords you\'ve marked off-brand or irrelevant.'],
        'all'         => ['All',           '#0f172a', $status_counts['all'],        'Every active keyword for this site. Ignored rows are in the Ignored tab.'],
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
        <!-- Bulk action bar -->
        <div id="kw-actions-bar" style="padding:8px 12px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">
            <div style="font-size:12px;color:#64748b;">
                <span id="kw-selected-count">0</span> selected
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
        <table>
            <thead>
                <tr>
                    <th style="width:32px;"><input type="checkbox" id="kw-select-all" onchange="toggleAll(this)"></th>
                    <th>Keyword</th>
                    <th title="Buyer intent — AI-classified">Intent</th>
                    <th title="0-100 opportunity score">Score</th>
                    <th title="Estimated monthly searches">Volume</th>
                    <th title="0-100, lower is easier">Diff</th>
                    <th title="Times shown in Google">Impr</th>
                    <th title="Your average position">Pos</th>
                    <th>Source</th>
                    <th title="SERP content brief — what's ranking and how to compete">📊 Brief</th>
                    <th style="width:90px;text-align:right;">Actions</th>
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
    const tabs    = document.querySelectorAll('.kw-tab');
    const wrapper = document.getElementById('kw-table-wrapper');
    if (!tabs.length || !wrapper) return;

    tabs.forEach(tab => {
        tab.addEventListener('click', async (e) => {
            e.preventDefault();
            const target = tab.getAttribute('href');
            if (!target) return;

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

            // Show a quiet loading state
            wrapper.style.opacity = '0.5';
            try {
                const sep = target.includes('?') ? '&' : '?';
                const res = await fetch(target + sep + 'partial=table', { credentials: 'same-origin' });
                const html = await res.text();
                // Replace the wrapper element entirely so the new id stays unique
                const tmp = document.createElement('div');
                tmp.innerHTML = html.trim();
                const fresh = tmp.querySelector('#kw-table-wrapper');
                if (fresh) {
                    wrapper.replaceWith(fresh);
                    // Update browser URL without reloading
                    if (window.history && history.pushState) {
                        history.pushState({}, '', target);
                    }
                } else {
                    // Fallback to a hard reload if the partial came back malformed
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
