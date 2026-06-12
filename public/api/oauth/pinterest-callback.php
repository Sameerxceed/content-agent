<?php
/**
 * Pinterest OAuth — Step 2: Pinterest redirects here with ?code + ?state.
 * State is just the site_id (Pinterest doesn't sign callbacks so we keep
 * the nonce-encoding pattern that Shopify needs unnecessary here).
 *
 * Flow:
 *   1. Exchange code → access_token + refresh_token
 *   2. Fetch the user's account info (for the friendly name)
 *   3. Save to integrations table
 *   4. List boards
 *      - 0 boards → flash error, ask user to create one on pinterest.com
 *      - 1 board  → auto-pick + send back to setup
 *      - 2+ boards → send to board picker page
 */

require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/integrations/pinterest.php';

auth_start();
if (!auth_check()) redirect('/auth/login.php');

$db = require __DIR__ . '/../../../includes/db.php';

$code    = (string)($_GET['code'] ?? '');
$state   = (int)($_GET['state'] ?? 0);
$err     = (string)($_GET['error'] ?? '');

$back = $state
    ? '/dashboard/setup.php?site=' . $state . '&tab=channels'
    : '/dashboard/sites.php';

if ($err) {
    $_SESSION['flash_error'] = 'Pinterest authorisation cancelled or failed: ' . $err;
    redirect($back);
}

if (!$code || !$state) {
    $_SESSION['flash_error'] = 'Pinterest returned an incomplete callback. Try connecting again.';
    redirect($back);
}

if (!auth_can_access_site($db, $state)) {
    $_SESSION['flash_error'] = 'Site not found.';
    redirect('/dashboard/sites.php');
}

$tokens = pinterest_exchange_code($code);
if (!$tokens) {
    $_SESSION['flash_error'] = 'Failed to exchange Pinterest authorisation code. Try connecting again.';
    redirect($back);
}

$account = pinterest_get_account($tokens['access_token']) ?: [];
pinterest_save_tokens($db, $state, $tokens, $account);

// Decide the next step based on how many boards the user has.
$boards = pinterest_list_boards($tokens['access_token']);

$db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
    $state, 'pinterest_connect',
    'Pinterest connected: @' . ($account['username'] ?? 'unknown') . ' (' . count($boards) . ' board(s) found)',
    'success',
]);

if (count($boards) === 0) {
    $_SESSION['flash_error'] = 'Pinterest connected as @' . ($account['username'] ?? '') . ' but no boards were found. Create at least one board on pinterest.com, then reconnect.';
    redirect($back);
}

if (count($boards) === 1) {
    // Auto-pick the only board.
    $b = $boards[0];
    pinterest_set_board($db, $state, $b['id'], $b['name']);
    $_SESSION['flash_success'] = 'Pinterest connected as @' . ($account['username'] ?? '') . '. Pins will go to the "' . $b['name'] . '" board.';
    redirect($back);
}

// Multiple boards — send to picker.
$_SESSION['flash_success'] = 'Pinterest connected as @' . ($account['username'] ?? '') . '. Now pick which board pins should go to.';
redirect('/dashboard/pinterest-board.php?site=' . $state);
