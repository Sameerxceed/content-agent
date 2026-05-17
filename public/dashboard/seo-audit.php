<?php
/**
 * Dashboard — SEO Audit results.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$filter_site = $_GET['site'] ?? '';
$audit_id = $_GET['audit'] ?? '';

$page_title = 'SEO/AEO — Issues';

ob_start();

if ($filter_site && !$audit_id) {
    $active = 'audit';
    include __DIR__ . '/_health_tabs.php';

    // Show pasted/external issues at the top of the Issues tab
    $ext_stmt = $db->prepare('SELECT * FROM seo_issues WHERE site_id = ? AND source = "pasted_alert" AND status = "open" ORDER BY created_at DESC LIMIT 50');
    try {
        $ext_stmt->execute([(int)$filter_site]);
        $external_issues = $ext_stmt->fetchAll();
    } catch (PDOException $e) {
        $external_issues = []; // migration 023 not applied yet
    }
?>
<style>
.alert-paste-card { background:#fff; border:1px solid var(--border); border-left:3px solid #3b82f6; border-radius:6px; padding:12px 14px; margin-bottom:14px; }
.alert-paste-card .title { font-weight:600; font-size:13px; color:var(--primary); margin-bottom:4px; }
.alert-paste-card .desc  { font-size:11px; color:var(--text-light); margin-bottom:8px; line-height:1.5; }
.alert-paste-card textarea { width:100%; min-height:80px; padding:8px 10px; border:1px solid var(--border); border-radius:5px; font-size:12px; font-family:inherit; resize:vertical; }
.alert-preview { margin-top:10px; padding-top:10px; border-top:1px solid #f1f5f9; }
.alert-issue-row { display:flex; gap:8px; align-items:flex-start; padding:8px 0; border-bottom:1px solid #f1f5f9; font-size:12px; }
.alert-issue-row input[type=checkbox] { margin-top:3px; }
.alert-issue-row .url { color:var(--primary); word-break:break-all; }
.alert-issue-row .code { font-size:10px; padding:1px 6px; border-radius:8px; background:#e2e8f0; color:#475569; text-transform:uppercase; letter-spacing:0.3px; }
.alert-issue-row .code.critical { background:#fecaca; color:#991b1b; }
.alert-issue-row .code.warning  { background:#fef3c7; color:#92400e; }
.alert-issue-row .fix { color:var(--text-light); margin-top:2px; }
.ext-issue { background:#fff; border:1px solid var(--border); border-left:3px solid #3b82f6; border-radius:6px; padding:10px 14px; margin-bottom:6px; }
.ext-issue .url { font-weight:600; font-size:12px; color:var(--primary); word-break:break-all; }
.ext-issue .meta { font-size:11px; color:var(--text-light); margin-top:2px; }
</style>

<div class="alert-paste-card">
    <div class="title">📥 Paste a Search Console alert or email</div>
    <div class="desc">Drop in the raw text of any GSC email (e.g. "Not found (404)", "Excluded by 'noindex' tag", "Duplicate without canonical"). Claude will extract the affected URLs and the fix for each.</div>
    <textarea id="paste-alert-text" placeholder="Paste the email body here, including the URL list..."></textarea>
    <div style="display:flex; gap:8px; align-items:center; margin-top:8px;">
        <button class="btn btn-primary btn-sm" onclick="parseAlert(this)">Parse with AI</button>
        <span id="parse-status" style="font-size:11px; color:var(--text-light);"></span>
    </div>
    <div id="parse-preview" class="alert-preview" style="display:none;">
        <div style="font-size:12px; font-weight:600; margin-bottom:6px;">Parsed issues — tick the ones to save:</div>
        <div id="parsed-list"></div>
        <div style="margin-top:10px; display:flex; gap:8px;">
            <button class="btn btn-accent btn-sm" onclick="saveAlert(this)">Save selected to Issues</button>
            <button class="btn btn-outline btn-sm" onclick="document.getElementById('parse-preview').style.display='none'">Cancel</button>
        </div>
    </div>
</div>

<?php if (!empty($external_issues)): ?>
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span>External alerts (from pasted emails) — <?= count($external_issues) ?> open</span>
        <span style="font-size:11px; color:var(--text-light); font-weight:normal;">Source: Search Console / email</span>
    </div>
    <?php foreach ($external_issues as $ei):
        $sev_color = $ei['severity'] === 'critical' ? '#991b1b' : ($ei['severity'] === 'warning' ? '#92400e' : '#475569');
        $sev_bg    = $ei['severity'] === 'critical' ? '#fecaca' : ($ei['severity'] === 'warning' ? '#fef3c7' : '#e2e8f0');
    ?>
    <div class="ext-issue" data-id="<?= (int)$ei['id'] ?>">
        <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start;">
            <div style="flex:1; min-width:0;">
                <div class="url"><?= e($ei['url']) ?></div>
                <div class="meta">
                    <span style="background:<?= $sev_bg ?>; color:<?= $sev_color ?>; padding:1px 6px; border-radius:8px; font-weight:600; font-size:10px; text-transform:uppercase;"><?= e($ei['type']) ?></span>
                    · added <?= e(date('d M H:i', strtotime($ei['created_at']))) ?>
                </div>
                <?php if (!empty($ei['suggested_fix'])): ?>
                    <div style="font-size:12px; color:var(--text); margin-top:6px; line-height:1.5;"><?= e($ei['suggested_fix']) ?></div>
                <?php endif; ?>
            </div>
            <div style="display:flex; gap:4px; flex-shrink:0;">
                <a href="<?= e($ei['url']) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:11px; padding:3px 8px; text-decoration:none;">Open ↗</a>
                <button class="btn btn-accent btn-sm" style="font-size:11px; padding:3px 8px;" onclick="generateFix(<?= (int)$ei['id'] ?>, this)">⚡ Fix</button>
                <button class="btn btn-outline btn-sm" style="font-size:11px; padding:3px 8px;" onclick="markExtResolved(<?= (int)$ei['id'] ?>, this)">Resolved</button>
                <button class="btn btn-outline btn-sm" style="font-size:11px; padding:3px 8px; color:var(--text-light);" onclick="markExtIgnored(<?= (int)$ei['id'] ?>, this)">Ignore</button>
            </div>
        </div>
        <div class="fix-preview" id="fix-<?= (int)$ei['id'] ?>" style="display:none; margin-top:10px; padding-top:10px; border-top:1px solid #f1f5f9;"></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
const PARSE_API = '<?= url('/api/seo-issue-parse.php') ?>';
const PARSE_SITE = <?= (int)$filter_site ?>;
let _parsedIssues = [];

async function parseAlert(btn) {
    const text = document.getElementById('paste-alert-text').value.trim();
    if (!text) { alert('Paste some text first.'); return; }
    btn.disabled = true; btn.textContent = 'Parsing…';
    document.getElementById('parse-status').textContent = 'Asking Claude to extract issues…';
    try {
        const res = await fetch(PARSE_API, {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'parse', site_id: PARSE_SITE, text})
        });
        const data = await res.json();
        if (!data.success) {
            document.getElementById('parse-status').innerHTML = '<span style="color:#dc2626;">' + (data.error || 'Failed') + '</span>';
            btn.disabled = false; btn.textContent = 'Parse with AI';
            return;
        }
        _parsedIssues = data.issues || [];
        if (_parsedIssues.length === 0) {
            document.getElementById('parse-status').innerHTML = '<span style="color:#dc2626;">No issues extracted. Try pasting more context.</span>';
            btn.disabled = false; btn.textContent = 'Parse with AI';
            return;
        }
        renderParsed();
        document.getElementById('parse-status').textContent = `Found ${_parsedIssues.length} issue(s)`;
        document.getElementById('parse-preview').style.display = 'block';
        btn.disabled = false; btn.textContent = 'Re-parse';
    } catch(e) {
        document.getElementById('parse-status').innerHTML = '<span style="color:#dc2626;">' + e.message + '</span>';
        btn.disabled = false; btn.textContent = 'Parse with AI';
    }
}

function renderParsed() {
    const html = _parsedIssues.map((i, idx) => `
        <div class="alert-issue-row">
            <input type="checkbox" data-i="${idx}" checked>
            <div style="flex:1; min-width:0;">
                <div><span class="code ${escAttr(i.severity)}">${escHtml(i.issue_label)}</span> <span class="url">${escHtml(i.url)}</span></div>
                <div class="fix">${escHtml(i.recommended_fix)}</div>
            </div>
        </div>
    `).join('');
    document.getElementById('parsed-list').innerHTML = html;
}

async function saveAlert(btn) {
    const checks = document.querySelectorAll('#parsed-list input[type=checkbox]:checked');
    if (checks.length === 0) { alert('Select at least one issue.'); return; }
    const selected = Array.from(checks).map(c => _parsedIssues[parseInt(c.dataset.i, 10)]);
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
        const res = await fetch(PARSE_API, {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'save', site_id: PARSE_SITE, issues: selected})
        });
        const data = await res.json();
        if (data.success) {
            alert(`Saved ${data.saved} issue(s). ${data.skipped > 0 ? data.skipped + ' already on the open list.' : ''}`);
            window.location.reload();
        } else {
            alert(data.error || 'Failed'); btn.disabled = false; btn.textContent = 'Save selected to Issues';
        }
    } catch(e) { alert(e.message); btn.disabled = false; btn.textContent = 'Save selected to Issues'; }
}

async function generateFix(issueId, btn) {
    const box = document.getElementById('fix-' + issueId);
    if (box.style.display === 'block') { box.style.display = 'none'; return; }
    btn.disabled = true; const orig = btn.textContent; btn.textContent = '…';
    try {
        const res = await fetch(PARSE_API, {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'generate_fix', site_id: PARSE_SITE, issue_id: issueId})
        });
        const fix = await res.json();
        if (!fix.success) { alert(fix.error || 'Failed'); btn.disabled = false; btn.textContent = orig; return; }
        const followupBtn = fix.followup ? `<a href="${escAttr(fix.followup)}" class="btn btn-accent btn-sm" style="font-size:11px;padding:3px 10px;text-decoration:none;margin-left:6px;">Open in Posts →</a>` : '';
        box.innerHTML = `
            <div style="font-size:13px; font-weight:600; color:var(--primary); margin-bottom:4px;">${escHtml(fix.title)} <span style="font-size:10px;text-transform:uppercase;background:#e2e8f0;color:#475569;padding:1px 6px;border-radius:8px;margin-left:4px;letter-spacing:0.3px;">${escHtml(fix.fix_type)}</span></div>
            <div style="font-size:12px; color:var(--text-light); margin-bottom:8px;">${escHtml(fix.summary)}</div>
            <pre id="fix-pre-${issueId}" style="background:#0f172a;color:#cbd5e1;padding:10px 12px;border-radius:6px;font-size:11px;line-height:1.5;max-height:280px;overflow:auto;white-space:pre-wrap;word-break:break-word;margin:0;">${escHtml(fix.preview)}</pre>
            <div style="margin-top:6px; display:flex; gap:6px;">
                <button class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 10px;" onclick="copyFix(${issueId}, this)">Copy</button>
                ${followupBtn}
            </div>
        `;
        box.style.display = 'block';
        btn.disabled = false; btn.textContent = '✓ Fix ready';
    } catch(e) {
        alert(e.message); btn.disabled = false; btn.textContent = orig;
    }
}
function copyFix(issueId, btn) {
    const pre = document.getElementById('fix-pre-' + issueId);
    if (!pre) return;
    navigator.clipboard.writeText(pre.textContent);
    btn.textContent = 'Copied!'; setTimeout(() => btn.textContent = 'Copy', 1500);
}

async function markExtResolved(id, btn) { await _extUpdate(id, 'resolved', btn); }
async function markExtIgnored(id, btn)  { await _extUpdate(id, 'ignored',  btn); }
async function _extUpdate(id, status, btn) {
    btn.disabled = true; btn.textContent = '…';
    try {
        const res = await fetch('<?= url('/api/seo-issue-status.php') ?>', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({issue_id: id, status})
        });
        const data = await res.json();
        if (data.success) {
            const row = btn.closest('.ext-issue'); if (row) row.remove();
        } else { alert(data.error || 'Failed'); btn.disabled = false; }
    } catch(e) { alert(e.message); btn.disabled = false; }
}

function escHtml(s) { return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }
function escAttr(s) { return escHtml(s); }
</script>

<?php
}

if ($audit_id):
    // Show single audit detail
    $stmt = $db->prepare('SELECT a.*, s.domain, s.name as site_name FROM seo_audits a JOIN sites s ON a.site_id = s.id WHERE a.id = ? AND s.user_id = ?');
    $stmt->execute([(int)$audit_id, $user_id]);
    $audit = $stmt->fetch();

    if (!$audit):
        echo '<div class="alert alert-error">Audit not found.</div>';
    else:
        // Get issues (exclude resolved/ignored/fixed_by_snippet from display)
        $stmt = $db->prepare('SELECT * FROM seo_issues WHERE audit_id = ? AND status NOT IN ("resolved", "ignored", "fixed_by_snippet") ORDER BY FIELD(severity, "critical", "warning", "info"), type');
        $stmt->execute([$audit['id']]);
        $issues = $stmt->fetchAll();

        // Recalculate stats from live data
        $open_count = count(array_filter($issues, fn($i) => $i['status'] === 'open'));
        $fixed_count = count(array_filter($issues, fn($i) => $i['status'] === 'fix_applied' || $i['status'] === 'fix_proposed'));
        $live_critical = count(array_filter($issues, fn($i) => $i['severity'] === 'critical' && $i['status'] === 'open'));
        $live_warnings = count(array_filter($issues, fn($i) => $i['severity'] === 'warning' && $i['status'] === 'open'));

        $score_class = 'score-bad';
        if ($audit['score'] >= 80) $score_class = 'score-good';
        elseif ($audit['score'] >= 50) $score_class = 'score-ok';
?>
    <div style="margin-bottom:10px;">
        <a href="<?= url('/dashboard/site.php?id=' . $audit['site_id']) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($audit['site_name']) ?></a>
    </div>
    <div class="flex justify-between items-center mb-4">
        <div class="flex items-center gap-4">
            <span class="score-circle <?= $score_class ?>" style="width:56px;height:56px;font-size:20px;"><?= $audit['score'] ?></span>
            <div>
                <div style="font-weight: 600;"><?= e($audit['site_name']) ?></div>
                <div class="text-sm text-muted"><?= format_date($audit['run_at']) ?> &bull; <?= $audit['pages_crawled'] ?> pages crawled &bull; <?= round($audit['duration_ms'] / 1000, 1) ?>s</div>
            </div>
        </div>
        <div class="flex gap-2">
            <button onclick="fixAll(<?= $audit['id'] ?>)" class="btn btn-accent btn-sm" id="fix-all-btn">🤖 Fix All Issues</button>
            <a href="<?= url('/api/download-fix.php?site_id=' . $audit['site_id'] . '&type=all') ?>" class="btn btn-sm" style="background:#3b82f6;color:#fff;text-decoration:none;">📦 Download Fix Files</a>
            <a href="<?= url('/api/export-audit.php?audit_id=' . $audit['id']) ?>" class="btn btn-sm" style="background:#059669;color:#fff;text-decoration:none;">📊 Export Report</a>
            <a href="<?= url('/dashboard/seo-audit.php?site=' . $audit['site_id']) ?>" class="btn btn-outline btn-sm">All Audits</a>
        </div>
    </div>

    <!-- Fix progress bar -->
    <div id="fix-progress" style="display:none;margin-bottom:14px;">
        <div class="card" style="padding:14px 16px;border-color:#10b981;">
            <div class="flex justify-between items-center mb-2">
                <strong id="fix-status-text">🤖 Fixing issues...</strong>
                <span id="fix-counter" class="text-sm text-muted">0 / 0</span>
            </div>
            <div style="height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
                <div id="fix-bar" style="height:100%;width:0%;background:#10b981;border-radius:4px;transition:width 0.3s;"></div>
            </div>
            <div id="fix-log" class="mt-2 text-sm" style="max-height:200px;overflow-y:auto;font-family:monospace;font-size:11px;color:#666;"></div>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Open Issues</div>
            <div class="stat-value"><?= $open_count ?></div>
            <?php if ($fixed_count > 0): ?><div class="stat-sub" style="color:var(--success);"><?= $fixed_count ?> fixed</div><?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="stat-label">Critical</div>
            <div class="stat-value" style="color: <?= $live_critical > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= $live_critical ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Warnings</div>
            <div class="stat-value" style="color: <?= $live_warnings > 0 ? 'var(--warning)' : 'var(--success)' ?>;"><?= $live_warnings ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Passed</div>
            <div class="stat-value" style="color: var(--success);"><?= $audit['passed'] ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Issues (<?= count($issues) ?>)</div>
        <?php if (empty($issues)): ?>
            <p class="text-muted text-sm" style="padding: 16px;">No issues found. Great job!</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Severity</th>
                        <th>Type</th>
                        <th>URL</th>
                        <th>Description</th>
                        <th>Fix</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($issues as $issue): ?>
                    <tr>
                        <td><span class="badge badge-<?= $issue['severity'] ?>"><?= $issue['severity'] ?></span></td>
                        <td class="text-sm"><?= e(str_replace('_', ' ', $issue['type'])) ?></td>
                        <td class="text-sm" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <a href="<?= e($issue['url']) ?>" target="_blank" title="<?= e($issue['url']) ?>" style="color: var(--primary);">
                                <?= e(truncate($issue['url'], 40)) ?>
                            </a>
                        </td>
                        <td class="text-sm"><?= e($issue['description']) ?></td>
                        <td class="text-sm text-muted">
                            <?php if ($issue['status'] === 'fix_proposed' && $issue['suggested_fix']): ?>
                                <details><summary style="cursor:pointer;color:var(--primary);">View fix</summary><pre style="white-space:pre-wrap;font-size:11px;background:#f8f9fa;padding:8px;border-radius:4px;margin-top:4px;max-width:300px;"><?= e($issue['suggested_fix']) ?></pre></details>
                            <?php else: ?>
                                <?= e(truncate($issue['suggested_fix'] ?? '', 60)) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($issue['status'] === 'open'): ?>
                                <button onclick="fixIssue(<?= $issue['id'] ?>, this)" class="btn btn-accent btn-sm">Fix</button>
                            <?php else: ?>
                                <span class="badge badge-<?= $issue['status'] === 'resolved' ? 'approved' : ($issue['status'] === 'fix_proposed' ? 'published' : ($issue['status'] === 'ignored' ? 'draft' : 'warning')) ?>"><?= $issue['status'] ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Clear next step -->
    <?php if (!empty($issues)): ?>
    <div style="display:flex;justify-content:center;gap:10px;margin:14px 0;flex-wrap:wrap;">
        <a href="<?= url('/dashboard/agent-run.php?agent=auto-fixer&site=' . $audit['site_id']) ?>" class="btn btn-accent" style="padding:12px 28px;font-size:14px;text-decoration:none;">Auto-Fix All <?= count($issues) ?> Issues →</a>
        <a href="<?= url('/dashboard/site.php?id=' . $audit['site_id']) ?>" class="btn btn-outline" style="padding:12px 28px;font-size:14px;text-decoration:none;">← Back to Site</a>
    </div>
    <?php else: ?>
    <div style="display:flex;justify-content:center;gap:10px;margin:14px 0;flex-wrap:wrap;">
        <a href="<?= url('/dashboard/agent-run.php?agent=keyword-research&site=' . $audit['site_id']) ?>" class="btn btn-accent" style="padding:12px 28px;font-size:14px;text-decoration:none;">Next: Find Keywords →</a>
        <a href="<?= url('/dashboard/site.php?id=' . $audit['site_id']) ?>" class="btn btn-outline" style="padding:12px 28px;font-size:14px;text-decoration:none;">← Back to Site</a>
    </div>
    <?php endif; ?>

    <script>
    const API = '<?= url('/api/fix-issue.php') ?>';
    const AUTO_FIX_API = '<?= url('/api/auto-fix-all.php') ?>';

    async function fixIssue(id, btn) {
        btn.disabled = true;
        btn.textContent = 'Fixing...';
        try {
            const res = await fetch(API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({issue_id: id})
            });
            const data = await res.json();
            if (data.success) {
                btn.outerHTML = '<span class="badge badge-approved" style="font-size:11px;">✓ Fixed</span>';
            } else {
                btn.textContent = 'Error';
            }
        } catch(e) {
            btn.textContent = 'Error';
        }
    }

    async function fixAll(auditId) {
        const btn = document.getElementById('fix-all-btn');
        btn.disabled = true;
        btn.textContent = '🤖 Fixing...';

        const progress = document.getElementById('fix-progress');
        const statusText = document.getElementById('fix-status-text');
        const counter = document.getElementById('fix-counter');
        const bar = document.getElementById('fix-bar');
        const log = document.getElementById('fix-log');
        progress.style.display = 'block';

        let totalFixed = 0, totalSkipped = 0, totalIssues = 0, offset = 0;
        const batchSize = 10;
        let allApplied = [], allDeployed = [];
        let hasMore = true;

        statusText.textContent = '🤖 Starting auto-fixer...';
        log.innerHTML = '<div>Connecting to AI engine...</div>';

        while (hasMore) {
            try {
                const res = await fetch(AUTO_FIX_API, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({site_id: <?= $audit['site_id'] ?>, batch_size: batchSize, offset: offset})
                });
                const data = await res.json();

                if (!data.success) {
                    log.innerHTML += '<div style="color:#ef4444;">⚠ Batch error: ' + (data.error || 'Unknown') + '</div>';
                    break;
                }

                totalFixed += data.fixed;
                totalSkipped += data.skipped;
                totalIssues = data.total_issues;
                hasMore = data.has_more;
                offset = data.next_offset || offset + batchSize;

                if (data.applied) allApplied = allApplied.concat(data.applied);
                if (data.deployed) allDeployed = allDeployed.concat(data.deployed);

                // Update progress
                const processed = Math.min(offset, totalIssues);
                const pct = totalIssues > 0 ? Math.round((processed / totalIssues) * 100) : 100;
                bar.style.width = pct + '%';
                counter.textContent = processed + ' / ' + totalIssues;
                statusText.textContent = '🤖 Fixing... ' + totalFixed + ' fixed, ' + totalSkipped + ' skipped';

                // Log this batch
                data.applied && data.applied.forEach(function(a) {
                    log.innerHTML += '<div style="color:#10b981;">✓ ' + a + '</div>';
                });
                data.deployed && data.deployed.forEach(function(d) {
                    log.innerHTML += '<div style="color:#3b82f6;">📦 ' + d + '</div>';
                });
                log.scrollTop = log.scrollHeight;

            } catch(e) {
                log.innerHTML += '<div style="color:#ef4444;">⚠ Network error. Retrying...</div>';
                await new Promise(r => setTimeout(r, 2000));
                // Don't increment offset — retry same batch
                continue;
            }
        }

        // Done!
        bar.style.width = '100%';
        statusText.textContent = '✅ Done! Fixed ' + totalFixed + ' of ' + totalIssues + ' issues. ' + totalSkipped + ' skipped.';
        btn.textContent = '✅ All Done';
        btn.style.background = '#10b981';

        // Update all Fix buttons
        document.querySelectorAll('button[onclick^="fixIssue"]').forEach(function(b) {
            b.outerHTML = '<span class="badge badge-approved" style="font-size:11px;">✓ Fixed</span>';
        });

        log.innerHTML += '<div style="margin-top:10px;font-weight:bold;color:#10b981;">Complete! ' + totalFixed + ' fixed, ' + totalSkipped + ' skipped. Refresh to see updated statuses.</div>';
    }
    </script>
    <?php endif; ?>

<?php else:
    // List audits
    $where = ['s.user_id = ?'];
    $params = [$user_id];

    if ($filter_site) {
        $where[] = 'a.site_id = ?';
        $params[] = (int)$filter_site;
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT a.*, s.domain, s.name as site_name FROM seo_audits a JOIN sites s ON a.site_id = s.id WHERE {$where_sql} ORDER BY a.run_at DESC LIMIT 50");
    $stmt->execute($params);
    $audits = $stmt->fetchAll();

    if (auth_is_super_admin()) {
    $stmt = $db->query('SELECT id, name FROM sites ORDER BY name');
} else {
    $stmt = $db->prepare('SELECT id, name FROM sites WHERE user_id = ? ORDER BY name');
    $stmt->execute([$user_id]);
}
    $sites = $stmt->fetchAll();
?>
    <div style="margin-bottom:10px;">
        <a href="<?= url('/dashboard/index.php') ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to Dashboard</a>
    </div>
    <div class="card" style="padding: 10px 16px;">
        <form method="GET" class="flex gap-4 items-center">
            <select name="site" class="form-control" style="width: auto; min-width: 150px;">
                <option value="">All Sites</option>
                <?php foreach ($sites as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $filter_site == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        </form>
    </div>

    <div class="card">
        <?php if (empty($audits)): ?>
            <p class="text-muted text-sm" style="padding: 20px; text-align: center;">No audits yet. Run: <code>php agent/seo-auditor.php --site=ID</code></p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Site</th>
                        <th>Score</th>
                        <th>Issues</th>
                        <th>Critical</th>
                        <th>Pages</th>
                        <th>Duration</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audits as $a): ?>
                    <?php
                        $sc = 'score-bad';
                        if ($a['score'] >= 80) $sc = 'score-good';
                        elseif ($a['score'] >= 50) $sc = 'score-ok';
                    ?>
                    <tr>
                        <td><?= e($a['site_name']) ?></td>
                        <td><span class="score-circle <?= $sc ?>" style="width:36px;height:36px;font-size:13px;"><?= $a['score'] ?></span></td>
                        <td><?= $a['total_issues'] ?></td>
                        <td><?= $a['critical'] > 0 ? '<span class="badge badge-critical">' . $a['critical'] . '</span>' : '0' ?></td>
                        <td><?= $a['pages_crawled'] ?></td>
                        <td class="text-sm"><?= round($a['duration_ms'] / 1000, 1) ?>s</td>
                        <td class="text-sm text-muted"><?= format_date($a['run_at'], 'd M, h:i A') ?></td>
                        <td><a href="<?= url('/dashboard/seo-audit.php?audit=' . $a['id']) ?>" class="btn btn-outline btn-sm">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
