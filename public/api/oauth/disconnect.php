<?php
/**
 * Disconnect a per-site OAuth integration.
 *
 * POST: site_id, platform, [return_to]
 *
 * Marks the integration row inactive (we soft-delete so the user's prior
 * configuration survives a reconnect — the row gets refreshed by the OAuth
 * callback rather than re-created). For Google specifically we also clear
 * the tokens so the next consent flow gets a fresh grant with current scope.
 */
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/auth.php';

auth_start();
if (!auth_check()) redirect('/auth/login.php');

$db = require __DIR__ . '/../../../includes/db.php';

$site_id  = (int)($_POST['site_id'] ?? 0);
$platform = (string)($_POST['platform'] ?? '');
$return   = (string)($_POST['return_to'] ?? '');

if ($site_id && $platform) {
    // Clear tokens AND mark inactive so reconnect gets a clean OAuth grant
    // with whatever scope is currently configured (important when scopes
    // change — e.g. adding GMC's content scope to the GSC integration).
    $stmt = $db->prepare('UPDATE integrations
        SET is_active = 0, access_token = NULL, refresh_token = NULL, token_expires_at = NULL, updated_at = NOW()
        WHERE site_id = ? AND platform = ?');
    $stmt->execute([$site_id, $platform]);
    $_SESSION['flash_success'] = ucfirst(str_replace('_', ' ', $platform)) . ' disconnected. Click Connect again to re-grant permissions.';
}

// Bounce back to whichever page initiated the disconnect, defaulting to the
// per-site Setup → Channels tab (the most common origin).
if ($return && str_starts_with($return, '/')) {
    redirect($return);
}
redirect('/dashboard/setup.php?site=' . $site_id . '&tab=channels');
