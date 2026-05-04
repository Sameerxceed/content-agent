<?php
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/integrations/twitter.php';

auth_start();
if (!auth_check()) redirect('/auth/login.php');

$db = require __DIR__ . '/../../../includes/db.php';
$code = $_GET['code'] ?? '';
$state = (int)($_GET['state'] ?? 0);
$code_verifier = $_SESSION['twitter_code_verifier'] ?? '';

if (empty($code) || !$state) {
    $_SESSION['flash_error'] = 'Twitter authorization failed.';
    redirect('/dashboard/sites.php');
}

$tokens = twitter_exchange_code($code, $code_verifier);
if (!$tokens) {
    $_SESSION['flash_error'] = 'Failed to connect Twitter.';
    redirect('/dashboard/sites.php?action=view&id=' . $state);
}

$user = twitter_get_user($tokens['access_token']);
twitter_save_tokens($db, $state, $tokens, $user);

$_SESSION['flash_success'] = 'Twitter/X connected! (' . ($user['username'] ?? 'Account') . ')';
redirect('/dashboard/sites.php?action=view&id=' . $state);
