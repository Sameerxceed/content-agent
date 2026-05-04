<?php
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/integrations/linkedin.php';

auth_start();
if (!auth_check()) redirect('/auth/login.php');

$db = require __DIR__ . '/../../../includes/db.php';
$code = $_GET['code'] ?? '';
$state = (int)($_GET['state'] ?? 0);

if (empty($code) || !$state) {
    $_SESSION['flash_error'] = 'LinkedIn authorization failed.';
    redirect('/dashboard/sites.php');
}

$tokens = linkedin_exchange_code($code);
if (!$tokens) {
    $_SESSION['flash_error'] = 'Failed to connect LinkedIn.';
    redirect('/dashboard/sites.php?action=view&id=' . $state);
}

$profile = linkedin_get_profile($tokens['access_token']);
linkedin_save_tokens($db, $state, $tokens, $profile ?: []);

$_SESSION['flash_success'] = 'LinkedIn connected! (' . ($profile['name'] ?? 'Account') . ')';
redirect('/dashboard/sites.php?action=view&id=' . $state);
