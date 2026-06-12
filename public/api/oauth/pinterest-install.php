<?php
/**
 * Pinterest OAuth — Step 1: redirect the user to Pinterest's authorize screen.
 *
 * Expects:
 *   site_id (int) — which site is connecting
 *
 * Pinterest's OAuth doesn't need any per-shop input (unlike Shopify), so we
 * skip the in-app prompt and just bounce straight to pinterest.com.
 */

require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/integrations/pinterest.php';

auth_start();
if (!auth_check()) redirect('/auth/login.php');

$db = require __DIR__ . '/../../../includes/db.php';

$site_id = (int)($_REQUEST['site_id'] ?? $_REQUEST['site'] ?? 0);

$back = $site_id
    ? '/dashboard/setup.php?site=' . $site_id . '&tab=channels'
    : '/dashboard/sites.php';

if (!$site_id || !auth_can_access_site($db, $site_id)) {
    $_SESSION['flash_error'] = 'Site not found.';
    redirect('/dashboard/sites.php');
}

if (!config('pinterest_client_id') || !config('pinterest_client_secret')) {
    $_SESSION['flash_error'] = 'Pinterest OAuth is not configured on this server. Set pinterest_client_id and pinterest_client_secret in config.php first.';
    redirect($back);
}

redirect(pinterest_get_auth_url($site_id));
