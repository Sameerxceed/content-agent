<?php
/**
 * Google OAuth2 callback handler.
 * Receives authorization code, exchanges for tokens, saves to DB.
 */

require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/integrations/google.php';

auth_start();

if (!auth_check()) {
    redirect('/auth/login.php');
}

$db = require __DIR__ . '/../../../includes/db.php';

$code = $_GET['code'] ?? '';
$state = (int)($_GET['state'] ?? 0); // site_id
$error = $_GET['error'] ?? '';

// Helper — always bounce back to the per-site Channels page so the user can
// retry / disconnect / see the connection status from one consistent place.
$back = $state
    ? '/dashboard/setup.php?site=' . $state . '&tab=channels'
    : '/dashboard/index.php';

if ($error) {
    $_SESSION['flash_error'] = 'Google authorization failed: ' . $error;
    redirect($back);
}

if (empty($code) || !$state) {
    $_SESSION['flash_error'] = 'Invalid callback parameters.';
    redirect($back);
}

// Verify site access (owner OR super-admin)
if (!auth_can_access_site($db, (int)$state)) {
    $_SESSION['flash_error'] = 'Site not found.';
    redirect('/dashboard/sites.php');
}

// Exchange code for tokens
$tokens = google_exchange_code($code);

if (!$tokens) {
    $_SESSION['flash_error'] = 'Failed to exchange authorization code. Try again.';
    redirect($back);
}

// Save tokens
google_save_tokens($db, $state, $tokens);

// Log
$db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
    $state, 'google_connect', 'Google Search Console connected', 'success'
]);

$_SESSION['flash_success'] = 'Google Search Console connected! Ranking data + Merchant Center diagnostics now available.';
// Bounce back to Setup → Channels — that's where the Connect / Reconnect
// button lives, so it's the most useful landing page right after the OAuth
// round-trip. (The old destination was the site Overview, which made the
// user re-navigate to verify the connection.)
redirect('/dashboard/setup.php?site=' . $state . '&tab=channels');
