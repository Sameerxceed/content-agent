<?php
/**
 * Shopify OAuth — Step 1: build the install URL and redirect the user to
 * Shopify's authorize screen.
 *
 * Expects POST or GET:
 *   site_id (int) — which site is connecting
 *   shop    (str) — shop domain ("my-store" / "my-store.myshopify.com" /
 *                   "https://my-store.myshopify.com" / custom domain). We
 *                   normalise to bare hostname before building the install URL.
 *
 * On success: 302 to Shopify. On error: bounces back to Setup → Channels with
 * a flash_error.
 */

require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/integrations/shopify_oauth.php';

auth_start();
if (!auth_check()) redirect('/auth/login.php');

$db = require __DIR__ . '/../../../includes/db.php';

$site_id = (int)($_REQUEST['site_id'] ?? $_REQUEST['site'] ?? 0);
$shop_in = trim((string)($_REQUEST['shop'] ?? ''));

$back = $site_id
    ? '/dashboard/setup.php?site=' . $site_id . '&tab=channels'
    : '/dashboard/sites.php';

if (!$site_id || !auth_can_access_site($db, $site_id)) {
    $_SESSION['flash_error'] = 'Site not found.';
    redirect('/dashboard/sites.php');
}

if (!config('shopify_client_id') || !config('shopify_client_secret')) {
    $_SESSION['flash_error'] = 'Shopify OAuth is not configured on this server. Set shopify_client_id and shopify_client_secret in config.php, or paste a manual access token instead.';
    redirect($back);
}

$shop = shopify_normalise_shop($shop_in);
if (!$shop) {
    $_SESSION['flash_error'] = 'Enter your Shopify store domain (e.g. my-store.myshopify.com).';
    redirect($back);
}

$install_url = shopify_get_install_url($shop, $site_id);
redirect($install_url);
