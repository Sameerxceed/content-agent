<?php
/**
 * Dashboard — Cron Jobs management.
 *
 * Super-admin only. Lets the user manage cron jobs from the UI instead of
 * editing Linux crontabs. Requires one master crontab entry on the server
 * (banner at top shows the exact line + copy button); after that, every
 * job's schedule + enabled state + run history is managed here.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cron_scheduler.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';

// Super-admin only — cron jobs are global infrastructure
if (!auth_is_super_admin()) {
    http_response_code(403);
    exit('Super admin only.');
}

$jobs = cron_scheduler_all($db);

// Recent runs (last 30)
$recent = $db->query("SELECT r.*, s.label, s.job_name FROM cron_runs r
    JOIN cron_schedules s ON r.schedule_id = s.id
    ORDER BY r.started_at DESC LIMIT 30")->fetchAll();

// Determine if the master cron is alive — heuristic: any run started in the last 10 min?
$master_alive = false;
try {
    $stmt = $db->query("SELECT 1 FROM cron_runs WHERE started_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1");
    $master_alive = (bool)$stmt->fetchColumn();
} catch (Throwable $e) {}

$linode_path = '/opt/contentagent';   // deploy_path default — could be from config
$crontab_line = "* * * * *  /usr/bin/php8.3 {$linode_path}/agent/cron-master.php >> /var/log/cron-master.log 2>&1";

$page_title = 'Cron Jobs';
ob_start();
?>

<style>
.cron-banner { padding:14px 18px; background:<?= $master_alive ? '#dcfce7' : '#fef3c7' ?>; border:1px solid <?= $master_alive ? '#86efac' : '#fcd34d' ?>; border-radius:8px; margin-bottom:14px; }
.cron-banner .head { display:flex; gap:8px; align-items:center; font-weight:600; font-size:13px; color:<?= $master_alive ? '#166534' : '#92400e' ?>; }
.cron-banner code { display:block; background:#0f172a; color:#e2e8f0; padding:10px 14px; border-radius:6px; margin-top:8px; font-family:ui-monospace,monospace; font-size:12px; white-space:nowrap; overflow-x:auto; }
.cron-banner .desc { font-size:11px; color:<?= $master_alive ? '#166534' : '#92400e' ?>; margin-top:6px; line-height:1.5; }
.cron-banner button.copy { background:#0f172a; color:#fff; border:0; padding:4px 10px; font-size:11px; border-radius:4px; cursor:pointer; margin-left:6px; vertical-align:middle; }

.cron-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(380px, 1fr)); gap:10px; margin-bottom:18px; }
.cron-card { padding:12px 14px; background:#fff; border:1px solid var(--border); border-radius:8px; display:flex; flex-direction:column; gap:6px; }
.cron-card.disabled { opacity:0.6; }
.cron-card .head { display:flex; justify-content:space-between; gap:8px; align-items:flex-start; }
.cron-card .label { font-size:13px; font-weight:600; color:var(--primary); }
.cron-card .job { font-size:10px; color:#94a3b8; font-family:ui-monospace,monospace; margin-top:2px; }
.cron-card .desc { font-size:11px; color:#64748b; line-height:1.5; }
.cron-card .meta { display:grid; grid-template-columns:auto 1fr; gap:4px 10px; font-size:11px; color:#475569; padding-top:6px; border-top:1px solid #f1f5f9; }
.cron-card .meta strong { color:#0f172a; }
.cron-card .actions { display:flex; gap:6px; padding-top:6px; }
.cron-card .actions .btn { padding:4px 10px; font-size:11px; border-radius:4px; cursor:pointer; border:1px solid var(--border); background:#fff; }
.cron-card .actions .btn.primary { background:#10b981; color:#fff; border-color:#10b981; }
.cron-card .actions .btn.danger { color:#dc2626; border-color:#fecaca; }
.cron-status { font-size:10px; text-transform:uppercase; padding:2px 7px; border-radius:8px; font-weight:600; }
.cs-running { background:#dbeafe; color:#1e40af; }
.cs-done    { background:#dcfce7; color:#166534; }
.cs-failed  { background:#fee2e2; color:#991b1b; }
.cs-queued  { background:#f1f5f9; color:#64748b; }

.runs-table { width:100%; font-size:12px; border-collapse:collapse; }
.runs-table th, .runs-table td { padding:6px 10px; border-bottom:1px solid #f1f5f9; text-align:left; }
.runs-table th { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.4px; font-weight:600; }
</style>

<h2 style="font-size:18px;font-weight:600;color:var(--primary);margin:0 0 12px;">⏰ Cron Jobs</h2>

<div class="cron-banner">
    <div class="head">
        <?php if ($master_alive): ?>
            <span style="color:#10b981;">●</span> Master cron is alive — jobs are running on schedule
        <?php else: ?>
            <span style="color:#f59e0b;">●</span> Master cron not detected yet — add this ONE line to the server crontab
        <?php endif; ?>
        <button class="copy" onclick="navigator.clipboard.writeText('<?= e($crontab_line) ?>').then(()=>this.textContent='Copied!')">Copy line</button>
    </div>
    <code><?= e($crontab_line) ?></code>
    <div class="desc">
        On Linode, run <code>crontab -e</code> once and paste this line. After that, every cron job lives in this UI — add, edit, disable, or trigger them manually. No more SSH needed for cron changes.
        <?php if (!$master_alive): ?>
            Once installed, this banner will turn green within 1-2 minutes.
        <?php endif; ?>
    </div>
</div>

<div class="cron-grid">
    <?php foreach ($jobs as $job):
        $sched = cron_scheduler_summarize_schedule($job);
        $next  = $job['next_run_at'] ? format_date($job['next_run_at']) : '—';
        $last  = $job['last_run_at'] ? format_date($job['last_run_at']) : '—';
        $st    = $job['last_status'] ?: 'never run';
        $dur   = $job['last_duration_seconds'] !== null ? $job['last_duration_seconds'] . 's' : '—';
    ?>
    <div class="cron-card <?= !$job['enabled'] ? 'disabled' : '' ?>">
        <div class="head">
            <div>
                <div class="label"><?= e($job['label']) ?></div>
                <div class="job"><?= e($job['job_name']) ?></div>
            </div>
            <span class="cron-status cs-<?= e($st === 'never run' ? 'queued' : $st) ?>"><?= e($st) ?></span>
        </div>
        <?php if (!empty($job['description'])): ?>
            <div class="desc"><?= e($job['description']) ?></div>
        <?php endif; ?>
        <div class="meta">
            <span>Schedule</span><strong><?= e($sched) ?></strong>
            <span>Next run</span><strong><?= e($next) ?></strong>
            <span>Last run</span><strong><?= e($last) ?> <?= $dur !== '—' ? "({$dur})" : '' ?></strong>
            <?php if (!empty($job['last_error'])): ?>
                <span>Last error</span><strong style="color:#dc2626;"><?= e($job['last_error']) ?></strong>
            <?php endif; ?>
        </div>
        <div class="actions">
            <button class="btn primary" onclick="runJobNow(<?= (int)$job['id'] ?>, this)">Run now</button>
            <?php if ($job['enabled']): ?>
                <button class="btn danger" onclick="toggleJob(<?= (int)$job['id'] ?>, 0, this)">Disable</button>
            <?php else: ?>
                <button class="btn" onclick="toggleJob(<?= (int)$job['id'] ?>, 1, this)">Enable</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<h3 style="font-size:13px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin:18px 0 8px;">Recent runs</h3>
<div class="card" style="padding:0;">
    <table class="runs-table">
        <thead>
            <tr>
                <th>Job</th>
                <th>Started</th>
                <th>Duration</th>
                <th>Status</th>
                <th>Error</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent as $r):
                $dur = $r['finished_at'] ? (strtotime($r['finished_at']) - strtotime($r['started_at'])) . 's' : (in_array($r['status'], ['running'], true) ? 'in progress' : '—');
            ?>
                <tr>
                    <td><?= e($r['label']) ?> <span style="color:#94a3b8;font-family:ui-monospace,monospace;font-size:10px;">(<?= e($r['job_name']) ?>)</span></td>
                    <td><?= e(format_date($r['started_at'])) ?></td>
                    <td><?= e($dur) ?></td>
                    <td><span class="cron-status cs-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
                    <td style="color:#dc2626;font-size:11px;"><?= e($r['error'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recent)): ?>
                <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:18px;">No runs yet. Add the crontab line above to start the master scheduler.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
async function runJobNow(scheduleId, btn) {
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Queuing…';
    try {
        const res = await fetch('<?= url('/api/cron-job-action.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'run_now', schedule_id: scheduleId })
        });
        const data = await res.json();
        if (data.success) {
            btn.textContent = 'Started';
            setTimeout(() => location.reload(), 1200);
        } else {
            alert('Failed: ' + (data.error || 'unknown'));
            btn.disabled = false; btn.textContent = orig;
        }
    } catch (e) { alert(e.message); btn.disabled = false; btn.textContent = orig; }
}

async function toggleJob(scheduleId, enabled, btn) {
    btn.disabled = true;
    const res = await fetch('<?= url('/api/cron-job-action.php') ?>', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'toggle', schedule_id: scheduleId, enabled: enabled })
    });
    const data = await res.json();
    if (data.success) location.reload();
    else { alert('Failed: ' + (data.error || 'unknown')); btn.disabled = false; }
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
