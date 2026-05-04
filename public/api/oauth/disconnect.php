<?php
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/auth.php';

auth_start();
if (!auth_check()) redirect('/auth/login.php');

$db = require __DIR__ . '/../../../includes/db.php';

$site_id = (int)($_POST['site_id'] ?? 0);
$platform = $_POST['platform'] ?? '';

if ($site_id && $platform) {
    $stmt = $db->prepare('UPDATE integrations SET is_active = 0 WHERE site_id = ? AND platform = ?');
    $stmt->execute([$site_id, $platform]);
    $_SESSION['flash_success'] = ucfirst($platform) . ' disconnected.';
}

redirect('/dashboard/social.php?site=' . $site_id);
