<?php
/**
 * Redirects API — single endpoint, multiple actions.
 *
 * POST JSON: { action, site_id, ... }
 *
 * Actions:
 *   crawl_site        — launch site crawler in background (sitemap-first)
 *   build_map         — launch redirect builder in background (uses live URLs from crawl)
 *   list              — return paginated redirects { items, summary }
 *   set_target        — manually edit a redirect's to_path
 *   approve           — mark pending → approved
 *   reject            — mark pending → rejected
 *   export_next_config — return next.config.js redirects block as text
 *   export_csv        — return CSV body suitable for Shopify URL Redirects import
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/redirect_map_builder.php';
require_once __DIR__ . '/../../includes/ai_cost.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

function rd_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}
function rd_text(string $body, string $filename = ''): void {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    if ($filename) header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $body; exit;
}
function rd_csv(string $body, string $filename = 'redirects.csv'): void {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $body; exit;
}

$db = require __DIR__ . '/../../includes/db.php';

// GET-friendly export actions need to read from $_GET (browser direct-download)
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? $_GET['action'] ?? '');
$site_id = (int)($input['site_id'] ?? $_GET['site_id'] ?? 0);

$site = auth_get_accessible_site($db, $site_id);
if (!$site) rd_respond(['error' => 'Site not found'], 404);

try {
    if ($action === 'preflight_build') {
        // Cheap dry-run of the heuristic pass + cost estimate. Lets the user
        // see scope + cost (admin) BEFORE we burn Claude tokens on 16K URLs.
        $dry = rmb_dry_run($db, $site_id);
        if ($dry['live_inventory_size'] === 0) {
            rd_respond([
                'error'   => 'No live URL inventory yet — run "Crawl live site" first so we know what targets exist.',
                'dry_run' => $dry,
            ], 400);
        }
        $est = ai_estimate_job('redirect_build', [
            'dead_count'          => $dry['to_process'],
            'heuristic_hit_count' => $dry['heuristic_hits'],
        ]);
        // Admin sees the dollar number; customers see scope + runtime only.
        $is_admin = auth_is_super_admin();
        if (!$is_admin) {
            $est['est_cost_usd'] = null;
            foreach ($est['steps'] as &$s) { $s['est_cost'] = null; }
            unset($s);
        }
        rd_respond([
            'success'   => true,
            'dry_run'   => $dry,
            'estimate'  => $est,
            'is_admin'  => $is_admin,
        ]);
    }

    if ($action === 'crawl_site' || $action === 'build_map') {
        $script_name = $action === 'crawl_site' ? 'cron-site-crawl.php' : 'cron-redirect-build.php';
        $php    = config('php_path') ?: '/usr/bin/php8.3';
        $script = realpath(__DIR__ . '/../../agent/' . $script_name);
        if (!$script) rd_respond(['error' => 'CLI script not found'], 500);

        $log_dir = config('log_path') ?: '/var/log/contentagent';
        $log     = rtrim($log_dir, '/') . '/redirects.log';
        $extra   = $action === 'build_map' && !empty($input['limit']) ? ' --limit=' . (int)$input['limit'] : '';

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf('start /B "" "%s" "%s" --site=%d%s', $php, $script, $site_id, $extra);
            pclose(popen($cmd, 'r'));
        } else {
            // setsid + </dev/null fully detaches so PHP-FPM reaping or browser
            // disconnect doesn't kill the background job.
            $cmd = sprintf(
                'setsid %s %s --site=%d%s </dev/null >> %s 2>&1 &',
                escapeshellarg($php),
                escapeshellarg($script),
                $site_id,
                $extra,
                escapeshellarg($log)
            );
            exec($cmd);
        }
        rd_respond(['success' => true, 'launched' => true]);
    }

    if ($action === 'list') {
        $filter = (string)($input['filter'] ?? $_GET['filter'] ?? 'all');
        $where = 'site_id = ?'; $args = [$site_id];
        if ($filter === 'pending')   { $where .= " AND status = 'pending'"; }
        if ($filter === 'approved')  { $where .= " AND status IN ('approved','applied')"; }
        if ($filter === 'rejected')  { $where .= " AND status = 'rejected'"; }
        if ($filter === 'no_target') { $where .= " AND to_path IS NULL"; }
        if ($filter === 'high')      { $where .= " AND confidence >= 85"; }
        if ($filter === 'medium')    { $where .= " AND confidence BETWEEN 60 AND 84"; }
        if ($filter === 'low')       { $where .= " AND (confidence < 60 OR confidence IS NULL)"; }
        $limit = max(1, min(500, (int)($input['limit'] ?? $_GET['limit'] ?? 200)));
        $stmt = $db->prepare("SELECT id, from_path, to_path, confidence, match_method, reasoning, status, auto_approved, applied_at
                              FROM redirect_map WHERE {$where}
                              ORDER BY confidence DESC, id LIMIT {$limit}");
        $stmt->execute($args);
        rd_respond([
            'items'   => $stmt->fetchAll(),
            'summary' => rmb_site_summary($db, $site_id),
        ]);
    }

    if ($action === 'set_target') {
        $rid = (int)($input['redirect_id'] ?? 0);
        $to  = trim((string)($input['to_path'] ?? ''));
        if (!$rid) rd_respond(['error' => 'redirect_id required'], 400);
        // 410-style: empty → null
        $to = $to === '' ? null : $to;
        $db->prepare("UPDATE redirect_map SET to_path = ?, match_method = 'manual', confidence = 100,
                      reasoning = 'manually set', status = 'approved', auto_approved = 0, updated_at = NOW()
                      WHERE id = ? AND site_id = ?")
           ->execute([$to, $rid, $site_id]);
        rd_respond(['success' => true]);
    }

    if ($action === 'approve' || $action === 'reject') {
        $rid = (int)($input['redirect_id'] ?? 0);
        if (!$rid) rd_respond(['error' => 'redirect_id required'], 400);
        $new = $action === 'approve' ? 'approved' : 'rejected';
        $db->prepare("UPDATE redirect_map SET status = ?, updated_at = NOW() WHERE id = ? AND site_id = ?")
           ->execute([$new, $rid, $site_id]);
        rd_respond(['success' => true, 'status' => $new]);
    }

    if ($action === 'export_next_config') {
        $stmt = $db->prepare("SELECT from_path, to_path FROM redirect_map
                              WHERE site_id = ? AND status IN ('approved','applied') AND to_path IS NOT NULL
                              ORDER BY id");
        $stmt->execute([$site_id]);
        $entries = [];
        foreach ($stmt->fetchAll() as $r) {
            $from = addslashes($r['from_path']);
            $to   = addslashes($r['to_path']);
            $entries[] = "    { source: '{$from}', destination: '{$to}', permanent: true },";
        }
        $body = "// Generated by ContentAgent. Paste this `async redirects()` into next.config.js.\n"
              . "// Each entry is a 301 from a dead historical URL to its living target.\n\n"
              . "module.exports = {\n"
              . "  async redirects() {\n"
              . "    return [\n"
              . implode("\n", $entries) . "\n"
              . "    ];\n"
              . "  },\n"
              . "};\n";
        rd_text($body, 'next.config.js');
    }

    // Multi-platform redirect exports — same input shape (approved+to_path), platform-specific format
    $platform_export_map = [
        'export_apache'    => ['fn' => 'redirect_export_apache_htaccess', 'file' => '.htaccess',         'mime' => 'text/plain'],
        'export_nginx'     => ['fn' => 'redirect_export_nginx',           'file' => 'redirects.conf',    'mime' => 'text/plain'],
        'export_netlify'   => ['fn' => 'redirect_export_netlify',         'file' => '_redirects',        'mime' => 'text/plain'],
        'export_vercel'    => ['fn' => 'redirect_export_vercel_json',     'file' => 'vercel.json',       'mime' => 'application/json'],
        'export_wordpress' => ['fn' => 'redirect_export_wordpress',       'file' => 'wp-redirects.txt',  'mime' => 'text/plain'],
    ];
    if (isset($platform_export_map[$action])) {
        require_once __DIR__ . '/../../includes/redirect_exporters.php';
        $stmt = $db->prepare("SELECT from_path, to_path FROM redirect_map
                              WHERE site_id = ? AND status IN ('approved','applied') AND to_path IS NOT NULL
                              ORDER BY id");
        $stmt->execute([$site_id]);
        $rows = $stmt->fetchAll();
        $cfg = $platform_export_map[$action];
        $body = $cfg['fn']($rows);
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: ' . $cfg['mime']);
        header('Content-Disposition: attachment; filename="' . $cfg['file'] . '"');
        echo $body;
        exit;
    }

    if ($action === 'export_csv') {
        // Shopify URL Redirects bulk-import CSV: columns Redirect from,Redirect to
        $stmt = $db->prepare("SELECT from_path, to_path FROM redirect_map
                              WHERE site_id = ? AND status IN ('approved','applied') AND to_path IS NOT NULL
                              ORDER BY id");
        $stmt->execute([$site_id]);
        $rows = [['Redirect from', 'Redirect to']];
        foreach ($stmt->fetchAll() as $r) $rows[] = [$r['from_path'], $r['to_path']];
        $out = fopen('php://temp', 'w+');
        foreach ($rows as $row) fputcsv($out, $row);
        rewind($out);
        $body = stream_get_contents($out);
        fclose($out);
        rd_csv($body, 'shopify-url-redirects.csv');
    }

    if ($action === 'bulk_approve') {
        // Approve every pending row that has a non-null to_path. Skips
        // no-target rows (those need a manual decision).
        $stmt = $db->prepare("UPDATE redirect_map SET status = 'approved', updated_at = NOW()
                              WHERE site_id = ? AND status = 'pending' AND to_path IS NOT NULL");
        $stmt->execute([$site_id]);
        rd_respond(['success' => true, 'approved' => $stmt->rowCount()]);
    }

    if ($action === 'export_not_found') {
        require_once __DIR__ . '/../../includes/not_found_generator.php';
        $destinations = nfg_pick_destinations($db, $site_id);
        if (empty($destinations)) rd_respond(['error' => 'Crawl your site first so we know what to suggest on the 404 page.'], 400);
        $platform = strtolower((string)($site['platform'] ?? 'custom'));
        if ($platform === 'shopify') {
            rd_text(nfg_generate_shopify($site, $destinations), 'ca-404.liquid');
        }
        rd_text(nfg_generate_nextjs($site, $destinations), 'not-found.tsx');
    }

    if ($action === 'apply_to_shopify') {
        require_once __DIR__ . '/../../includes/integrations/shopify.php';
        $shop_url = trim((string)($site['cms_url'] ?? ''));
        $token    = trim((string)($site['cms_api_key'] ?? ''));
        if ($shop_url === '' || $token === '') {
            rd_respond(['error' => 'This site needs cms_url + cms_api_key set in Setup → Channels → CMS to push to Shopify.'], 400);
        }
        $verify = shopify_admin_verify($shop_url, $token);
        if (!$verify['ok']) rd_respond(['error' => 'Shopify auth failed: ' . $verify['error']], 400);

        // For >250 approved redirects, push in background — Shopify's 2 req/s
        // rate limit + browser/nginx timeouts make synchronous push fragile
        // past a couple of minutes.
        $count_stmt = $db->prepare("SELECT COUNT(*) FROM redirect_map
            WHERE site_id = ? AND status = 'approved' AND to_path IS NOT NULL");
        $count_stmt->execute([$site_id]);
        $approved_count = (int)$count_stmt->fetchColumn();

        if ($approved_count > 250) {
            // Concurrency guard — refuse to spawn if one is already running
            $check = $db->prepare("SELECT id FROM redirect_runs
                WHERE site_id = ? AND kind = 'apply' AND status = 'running' LIMIT 1");
            $check->execute([$site_id]);
            $running = (int)$check->fetchColumn();
            if ($running) {
                rd_respond(['success' => true, 'already_running' => true, 'run_id' => $running, 'total_queued' => $approved_count]);
            }

            $db->prepare("INSERT INTO redirect_runs (site_id, kind, status) VALUES (?, 'apply', 'running')")
               ->execute([$site_id]);
            $run_id = (int)$db->lastInsertId();

            $php    = config('php_path') ?: '/usr/bin/php8.3';
            $script = realpath(__DIR__ . '/../../agent/cron-shopify-push.php');
            $log    = (config('log_path') ?: '/var/log/contentagent') . '/redirects.log';
            if (PHP_OS_FAMILY === 'Windows') {
                $cmd = sprintf('start /B "" "%s" "%s" --site=%d --run=%d', $php, $script, $site_id, $run_id);
                pclose(popen($cmd, 'r'));
            } else {
                $cmd = sprintf('setsid %s %s --site=%d --run=%d </dev/null >> %s 2>&1 &',
                    escapeshellarg($php), escapeshellarg($script), $site_id, $run_id, escapeshellarg($log));
                exec($cmd);
            }
            rd_respond(['success' => true, 'launched' => true, 'run_id' => $run_id, 'total_queued' => $approved_count]);
        }

        // Small batch — synchronous is fine
        $r = shopify_admin_push_approved($db, $site_id, $shop_url, $token);
        rd_respond(['success' => true] + $r);
    }

    if ($action === 'apply_status') {
        // UI polls this when a background apply is in flight.
        $stmt = $db->prepare("SELECT id, status, items_processed, items_succeeded, items_failed, started_at, finished_at, error
            FROM redirect_runs WHERE site_id = ? AND kind = 'apply' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$site_id]);
        rd_respond(['success' => true, 'run' => $stmt->fetch() ?: null]);
    }

    rd_respond(['error' => 'Unknown action: ' . $action], 400);
} catch (Throwable $e) {
    error_log('[redirect-action] ' . $e->getMessage());
    rd_respond(['error' => $e->getMessage()], 500);
}
