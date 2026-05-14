<?php
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/integrations/reddit.php';

auth_start();
if (!auth_check()) redirect('/auth/login.php');

$db = require __DIR__ . '/../../../includes/db.php';
$code = $_GET['code'] ?? '';
$state_param = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

// State format: "{site_id}:{nonce}"
$site_id = 0;
if ($state_param && strpos($state_param, ':') !== false) {
    [$sid, ] = explode(':', $state_param, 2);
    $site_id = (int)$sid;
}

if ($error) {
    $_SESSION['flash_error'] = 'Reddit authorization denied: ' . $error;
    redirect('/dashboard/site.php?id=' . $site_id);
}

if (empty($code) || !$site_id) {
    $_SESSION['flash_error'] = 'Reddit authorization failed (missing code or state).';
    redirect('/dashboard/site.php?id=' . $site_id);
}

// Verify state matches what we put in the session
$expected = $_SESSION['reddit_oauth_state'] ?? '';
if ($expected && $expected !== $state_param) {
    $_SESSION['flash_error'] = 'Reddit state mismatch — possible CSRF. Try again.';
    redirect('/dashboard/site.php?id=' . $site_id);
}
unset($_SESSION['reddit_oauth_state']);

$tokens = reddit_exchange_code($code);
if (!$tokens) {
    $_SESSION['flash_error'] = 'Failed to exchange Reddit auth code for tokens.';
    redirect('/dashboard/site.php?id=' . $site_id);
}

$username = reddit_get_username($tokens['access_token']);
reddit_save_tokens($db, $site_id, $tokens, $username);

$_SESSION['flash_success'] = 'Reddit connected as ' . ($username ? 'u/' . $username : 'your account') . '!';
redirect('/dashboard/site.php?id=' . $site_id);
