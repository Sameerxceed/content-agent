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

if ($error) {
    $_SESSION['flash_error'] = 'Google authorization failed: ' . $error;
    redirect('/dashboard/settings.php');
}

if (empty($code) || !$state) {
    $_SESSION['flash_error'] = 'Invalid callback parameters.';
    redirect('/dashboard/settings.php');
}

// Verify site ownership
$stmt = $db->prepare('SELECT id FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$state, auth_user_id()]);
if (!$stmt->fetch()) {
    $_SESSION['flash_error'] = 'Site not found.';
    redirect('/dashboard/sites.php');
}

// Exchange code for tokens
$tokens = google_exchange_code($code);

if (!$tokens) {
    $_SESSION['flash_error'] = 'Failed to exchange authorization code. Try again.';
    redirect('/dashboard/sites.php?action=view&id=' . $state);
}

// Save tokens
google_save_tokens($db, $state, $tokens);

// Log
$db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
    $state, 'google_connect', 'Google Search Console connected', 'success'
]);

$_SESSION['flash_success'] = 'Google Search Console connected! Ranking data will be available shortly.';
redirect('/dashboard/sites.php?action=view&id=' . $state);
