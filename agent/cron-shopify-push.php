<?php
/**
 * Background CLI — push every approved redirect on a site to Shopify.
 *
 * Used by Module 3 "Apply N to Shopify" when N is too large to push
 * synchronously inside the browser request (Shopify rate-limit is 2 req/s
 * per shop, so ~1000 redirects = ~8 min minimum which exceeds nginx
 * proxy timeouts).
 *
 * Usage:
 *   php agent/cron-shopify-push.php --site=N [--run=N]
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/integrations/shopify.php';

$opts = getopt('', ['site:', 'run::']);
$site_id = (int)($opts['site'] ?? 0);
$run_id  = isset($opts['run']) ? (int)$opts['run'] : null;
if ($site_id <= 0) { fwrite(STDERR, "Usage: cron-shopify-push.php --site=N [--run=N]\n"); exit(1); }

$db = require __DIR__ . '/../includes/db.php';

$stmt = $db->prepare("SELECT cms_url, cms_api_key, name FROM sites WHERE id = ?");
$stmt->execute([$site_id]);
$site = $stmt->fetch();
if (!$site) { fwrite(STDERR, "site {$site_id} not found\n"); exit(2); }

$shop_url = trim((string)$site['cms_url']);
$token    = trim((string)$site['cms_api_key']);
if ($shop_url === '' || $token === '') {
    fwrite(STDERR, "site {$site_id} missing cms_url or cms_api_key\n");
    exit(3);
}

if (!$run_id) {
    $db->prepare("INSERT INTO redirect_runs (site_id, kind, status) VALUES (?, 'apply', 'running')")
       ->execute([$site_id]);
    $run_id = (int)$db->lastInsertId();
} else {
    $db->prepare("UPDATE redirect_runs SET status = 'running', started_at = NOW() WHERE id = ?")->execute([$run_id]);
}

$t0 = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] shopify push start — site={$site_id} ({$site['name']}) run={$run_id}\n";

try {
    $verify = shopify_admin_verify($shop_url, $token);
    if (!$verify['ok']) throw new RuntimeException('shopify auth failed: ' . $verify['error']);

    $r = shopify_admin_push_approved($db, $site_id, $shop_url, $token, $run_id, function (array $p) {
        if ($p['processed'] % 50 === 0) {
            echo "  pushed={$p['pushed']} dupes={$p['duplicates']} errors={$p['errors']} of {$p['total']}\n";
        }
    });

    $dur = round(microtime(true) - $t0, 1);
    $db->prepare("UPDATE redirect_runs SET status = 'done', finished_at = NOW(),
        items_processed = ?, items_succeeded = ?, items_failed = ? WHERE id = ?")
       ->execute([$r['total'], $r['pushed'] + $r['duplicates'], $r['errors'], $run_id]);
    echo "[" . date('Y-m-d H:i:s') . "] done in {$dur}s — pushed={$r['pushed']} dupes={$r['duplicates']} errors={$r['errors']} of {$r['total']}\n";
} catch (Throwable $e) {
    $db->prepare("UPDATE redirect_runs SET status = 'failed', finished_at = NOW(), error = ? WHERE id = ?")
       ->execute([$e->getMessage(), $run_id]);
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(4);
}
exit(0);
