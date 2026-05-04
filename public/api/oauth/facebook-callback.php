<?php
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/integrations/facebook.php';

auth_start();
if (!auth_check()) redirect('/auth/login.php');

$db = require __DIR__ . '/../../../includes/db.php';
$code = $_GET['code'] ?? '';
$state = (int)($_GET['state'] ?? 0);

if (empty($code) || !$state) {
    $_SESSION['flash_error'] = 'Facebook authorization failed.';
    redirect('/dashboard/sites.php');
}

$tokens = facebook_exchange_code($code);
if (!$tokens) {
    $_SESSION['flash_error'] = 'Failed to connect Facebook.';
    redirect('/dashboard/sites.php?action=view&id=' . $state);
}

$pages = facebook_get_pages($tokens['access_token']);
facebook_save_tokens($db, $state, $tokens, $pages);

$ig_count = 0;
foreach ($pages as $p) {
    if (facebook_get_instagram_account($p['access_token'], $p['id'])) $ig_count++;
}

$msg = 'Facebook connected! ' . count($pages) . ' page(s)';
if ($ig_count > 0) $msg .= ', ' . $ig_count . ' Instagram account(s)';
$_SESSION['flash_success'] = $msg;
redirect('/dashboard/sites.php?action=view&id=' . $state);
