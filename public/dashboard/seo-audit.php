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

$page_title = 'SEO Audit';

ob_start();

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

    $stmt = $db->prepare('SELECT id, name FROM sites WHERE user_id = ? ORDER BY name');
    $stmt->execute([$user_id]);
    $sites = $stmt->fetchAll();
?>
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
