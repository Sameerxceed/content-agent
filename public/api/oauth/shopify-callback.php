<?php
/**
 * Shopify OAuth — Step 2: callback. Shopify redirects here with
 *   ?code=...&shop=...&state=<nonce>:<site_id>&hmac=...&timestamp=...
 *
 * We verify HMAC + state nonce, exchange the code for a permanent
 * access_token, save to sites.cms_url + cms_api_key, then verify the token
 * by hitting shop.json (or the GraphQL equivalent).
 */

require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/integrations/shopify_oauth.php';
require_once __DIR__ . '/../../../includes/integrations/shopify.php';

auth_start();
if (!auth_check()) redirect('/auth/login.php');

$db = require __DIR__ . '/../../../includes/db.php';

$code  = (string)($_GET['code'] ?? '');
$shop  = (string)($_GET['shop'] ?? '');
$state = (string)($_GET['state'] ?? '');
$err   = (string)($_GET['error'] ?? '');

// state format = <nonce>:<site_id>
$site_id = 0;
$nonce   = '';
if (strpos($state, ':') !== false) {
    [$nonce, $sid] = explode(':', $state, 2);
    $site_id = (int)$sid;
}

$back = $site_id
    ? '/dashboard/setup.php?site=' . $site_id . '&tab=channels'
    : '/dashboard/sites.php';

if ($err) {
    $_SESSION['flash_error'] = 'Shopify authorisation cancelled or failed: ' . $err;
    redirect($back);
}

if (!$code || !$shop || !$site_id) {
    $_SESSION['flash_error'] = 'Invalid Shopify callback (missing code, shop or state).';
    redirect($back);
}

if (!auth_can_access_site($db, $site_id)) {
    $_SESSION['flash_error'] = 'Site not found.';
    redirect('/dashboard/sites.php');
}

// CSRF nonce check — must match what shopify-install.php stashed in session.
if (empty($_SESSION['shopify_oauth_nonce']) || !hash_equals($_SESSION['shopify_oauth_nonce'], $nonce)) {
    $_SESSION['flash_error'] = 'Shopify auth state mismatch. Try connecting again.';
    redirect($back);
}

// HMAC check — protects against forged callbacks. We pass the RAW query
// string (not $_GET) because Shopify computes HMAC over the URL-encoded
// form they sent, and re-encoding from $_GET doesn't reliably reproduce
// the same bytes.
$raw_qs = (string)($_SERVER['QUERY_STRING'] ?? '');
if (!shopify_verify_hmac($raw_qs)) {
    // TEMP DEBUG — log computed-vs-received so we can diagnose the mismatch.
    // Remove once the HMAC flow is verified working.
    $debug_secret = (string)config('shopify_client_secret');
    $debug_pairs = [];
    $debug_hmac_received = '';
    foreach (explode('&', $raw_qs) as $pair) {
        if ($pair === '') continue;
        $eq = strpos($pair, '=');
        $key = $eq === false ? $pair : substr($pair, 0, $eq);
        if ($key === 'hmac') { $debug_hmac_received = $eq === false ? '' : urldecode(substr($pair, $eq + 1)); continue; }
        if ($key === 'signature') continue;
        $debug_pairs[$key] = $pair;
    }
    ksort($debug_pairs);
    $debug_message  = implode('&', $debug_pairs);
    $debug_computed_with_prefix = hash_hmac('sha256', $debug_message, $debug_secret);
    $debug_computed_no_prefix   = hash_hmac('sha256', $debug_message, preg_replace('/^shpss_/', '', $debug_secret));
    @file_put_contents('/tmp/shopify_hmac_debug.log',
        date('c') . "\n" .
        "raw_qs: $raw_qs\n" .
        "message: $debug_message\n" .
        "received_hmac: $debug_hmac_received\n" .
        "computed_with_prefix: $debug_computed_with_prefix\n" .
        "computed_no_prefix:   $debug_computed_no_prefix\n" .
        "secret_len: " . strlen($debug_secret) . "\n" .
        "----\n",
        FILE_APPEND);

    $_SESSION['flash_error'] = 'Shopify auth signature invalid. Try connecting again. (Debug log written to /tmp/shopify_hmac_debug.log)';
    redirect($back);
}

// Normalise the returned shop (Shopify always echoes the myshopify alias here).
$shop_clean = shopify_normalise_shop($shop);
if (!$shop_clean) {
    $_SESSION['flash_error'] = 'Shopify returned an unrecognised shop domain: ' . htmlspecialchars($shop);
    redirect($back);
}

// Exchange code → access_token.
$tokens = shopify_exchange_code($shop_clean, $code);
if (!$tokens || empty($tokens['access_token'])) {
    $_SESSION['flash_error'] = 'Failed to exchange Shopify authorisation code. Try again.';
    redirect($back);
}

shopify_save_token($db, $site_id, $shop_clean, $tokens);

// Verify the token works (routes via prefix — shpat_ uses REST, atkn_ uses GraphQL).
$verify = shopify_admin_verify('https://' . $shop_clean, (string)$tokens['access_token']);
if (!$verify['ok']) {
    $_SESSION['flash_error'] = 'Token saved, but verify call failed: ' . ($verify['error'] ?? 'unknown') . '. You may need to reconnect.';
    redirect($back);
}

// Clean up nonce so it can't be replayed.
unset($_SESSION['shopify_oauth_nonce'], $_SESSION['shopify_oauth_site'], $_SESSION['shopify_oauth_shop']);

$db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
    $site_id, 'shopify_connect',
    'Shopify connected via OAuth: ' . ($verify['shop']['name'] ?? $shop_clean) . ' (scope: ' . ($tokens['scope'] ?? 'unknown') . ')',
    'success',
]);

$_SESSION['flash_success'] = 'Shopify connected — ' . htmlspecialchars($verify['shop']['name'] ?? $shop_clean) . '. Blog posts, redirects, and product audits are now available.';
redirect($back);
