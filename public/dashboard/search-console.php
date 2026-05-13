<?php
/**
 * Dashboard — Google Search Console data.
 * Shows keyword rankings, page performance, clicks, impressions, CTR.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/integrations/google.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? 0);
$action = $_GET['action'] ?? '';

$page_title = '📈 Search Console';

// Get sites
$stmt = $db->prepare('SELECT id, name, domain FROM sites WHERE user_id = ? ORDER BY name');
$stmt->execute([$user_id]);
$sites = $stmt->fetchAll();

ob_start();

if (empty($site_id)): ?>
<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/index.php') ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to Dashboard</a>
</div>
<?php endif; ?>

<!-- Site selector -->
<div class="card" style="padding:10px 16px;margin-bottom:14px;">
    <form method="GET" class="flex gap-4 items-center" style="flex-wrap:wrap;">
        <select name="site" class="form-control" style="width:auto;min-width:200px;">
            <option value="">Select a site</option>
            <?php foreach ($sites as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $site_id == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> — <?= e($s['domain']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">View Data</button>
    </form>
</div>

<?php if ($site_id):
    // Check if connected
    $stmt = $db->prepare('SELECT * FROM integrations WHERE site_id = ? AND platform = "google_search_console" AND is_active = 1');
    $stmt->execute([$site_id]);
    $integration = $stmt->fetch();

    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([$site_id, $user_id]);
    $site = $stmt->fetch();

    if (!$site): ?>
        <div class="alert alert-error">Site not found.</div>
    <?php elseif (!$integration): ?>
        <!-- Not connected — show connect button -->
        <div class="card" style="text-align:center;padding:40px;">
            <div style="font-size:48px;margin-bottom:10px;">📊</div>
            <h3 style="margin-bottom:4px;">Connect Google Search Console</h3>
            <p class="text-muted text-sm" style="max-width:500px;margin:0 auto 16px;">
                Connect your Google Search Console to see real keyword rankings, clicks, impressions, and CTR data.
                ContentAgent will use this data to make smarter content decisions.
            </p>

            <?php if (empty(config('google_client_id'))): ?>
                <div class="alert alert-warning" style="max-width:500px;margin:0 auto 16px;text-align:left;">
                    <strong>Setup required:</strong> Add Google OAuth credentials to config.php:
                    <pre style="font-size:11px;margin-top:6px;background:#f8f9fa;padding:8px;border-radius:4px;">'google_client_id'     => 'YOUR_CLIENT_ID',
'google_client_secret' => 'YOUR_CLIENT_SECRET',</pre>
                    <ol style="font-size:12px;margin-top:8px;padding-left:16px;">
                        <li>Go to <a href="https://console.cloud.google.com" target="_blank">console.cloud.google.com</a></li>
                        <li>Create project → Enable "Search Console API"</li>
                        <li>Credentials → Create OAuth2 Client (Web application)</li>
                        <li>Set redirect URI: <code><?= e(config('app_url')) ?>/api/oauth/google-callback.php</code></li>
                        <li>Copy Client ID + Secret to config.php</li>
                    </ol>
                </div>
            <?php else: ?>
                <a href="<?= e(google_get_auth_url($site_id)) ?>" class="btn btn-primary" style="padding:10px 24px;">
                    Connect Google Search Console →
                </a>
            <?php endif; ?>
        </div>

    <?php else:
        // Connected — show data
        $access_token = google_get_token($db, $site_id);

        // Sync rankings if action requested
        if ($action === 'sync' && $access_token) {
            $sync_result = google_update_rankings($db, $site_id);
            if ($sync_result['success']) {
                $total = ($sync_result['updated'] ?? 0) + ($sync_result['inserted'] ?? 0);
                echo '<div class="alert alert-success">Synced ' . $total . ' keywords from Search Console (matched property: <code>' . e($sync_result['matched_url'] ?? '') . '</code>)</div>';
            } else {
                echo '<div class="alert alert-error" style="font-size:13px;">'
                    . '<strong>Sync failed:</strong> ' . e($sync_result['error'] ?? 'Unknown')
                    . '<div style="margin-top:8px;font-size:12px;color:#64748b;">'
                    . 'Most common cause: the Google account you connected does not have access to this site in Search Console. '
                    . 'Either connect a different Google account that owns the property, or add your current Google account as a user in '
                    . '<a href="https://search.google.com/search-console" target="_blank">Search Console</a> &rarr; Settings &rarr; Users and permissions.'
                    . '</div>'
                    . '</div>';
            }
        }

        // Get summary
        $summary = $access_token ? google_performance_summary($db, $site_id, 30) : null;

        // Get top keywords with rankings
        $stmt = $db->prepare('SELECT * FROM keywords WHERE site_id = ? AND current_rank IS NOT NULL AND current_rank > 0 ORDER BY search_volume DESC LIMIT 30');
        $stmt->execute([$site_id]);
        $ranked_keywords = $stmt->fetchAll();

        // Get page performance
        $page_data = $access_token ? google_page_performance($db, $site_id, 30) : null;
    ?>

        <div class="flex justify-between items-center mb-4">
            <div class="text-sm text-muted">
                Connected as: <strong><?= e($integration['account_name'] ?? 'Google Account') ?></strong>
                · Last synced: <?= $integration['updated_at'] ? format_date($integration['updated_at']) : 'Never' ?>
            </div>
            <div class="flex gap-2">
                <a href="<?= url('/dashboard/search-console.php?site=' . $site_id . '&action=sync') ?>" class="btn btn-primary btn-sm">🔄 Sync Rankings</a>
            </div>
        </div>

        <!-- Summary cards -->
        <?php if ($summary): ?>
        <div class="stats-grid" style="margin-bottom:14px;">
            <div class="stat-card">
                <div class="stat-label">Clicks (30 days)</div>
                <div class="stat-value"><?= number_format($summary['clicks']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Impressions</div>
                <div class="stat-value"><?= number_format($summary['impressions']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Avg CTR</div>
                <div class="stat-value"><?= $summary['ctr'] ?>%</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Avg Position</div>
                <div class="stat-value"><?= $summary['position'] ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Keyword Rankings -->
        <div class="card">
            <div class="card-header flex justify-between items-center">
                <span>Keyword Rankings</span>
                <a href="<?= url('/dashboard/keywords.php?site=' . $site_id) ?>" class="text-sm" style="color:var(--accent);text-decoration:none;">All Keywords →</a>
            </div>
            <?php if (empty($ranked_keywords)): ?>
                <p class="text-muted text-sm" style="padding:16px;">No ranking data yet. Click "Sync Rankings" to pull data from Search Console.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Keyword</th>
                            <th>Position</th>
                            <th>Impressions</th>
                            <th>Cluster</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ranked_keywords as $kw): ?>
                        <tr>
                            <td style="font-weight:500;"><?= e($kw['keyword']) ?></td>
                            <td>
                                <span style="font-weight:700;color:<?= $kw['current_rank'] <= 10 ? 'var(--success)' : ($kw['current_rank'] <= 30 ? 'var(--warning)' : 'var(--danger)') ?>;">
                                    #<?= $kw['current_rank'] ?>
                                </span>
                            </td>
                            <td class="text-sm"><?= $kw['search_volume'] ? number_format($kw['search_volume']) : '—' ?></td>
                            <td class="text-sm text-muted"><?= e($kw['cluster'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Page Performance -->
        <?php if ($page_data && !empty($page_data['rows'])): ?>
        <div class="card">
            <div class="card-header">Top Pages</div>
            <table>
                <thead>
                    <tr>
                        <th>Page</th>
                        <th>Clicks</th>
                        <th>Impressions</th>
                        <th>CTR</th>
                        <th>Avg Position</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($page_data['rows'], 0, 20) as $row): ?>
                    <tr>
                        <td class="text-sm" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <a href="<?= e($row['keys'][0]) ?>" target="_blank" style="color:var(--primary);text-decoration:none;"><?= e(str_replace('https://www.' . $site['domain'], '', $row['keys'][0])) ?></a>
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
        <?php endif; ?>

    <?php endif; ?>
<?php else: ?>
    <div class="card" style="text-align:center;padding:30px;">
        <p class="text-muted">Select a site above to view Search Console data.</p>
    </div>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
