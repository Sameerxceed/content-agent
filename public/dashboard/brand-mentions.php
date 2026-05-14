<?php
/** Merged into mentions.php — kept for backward-compatibility redirects. */
require_once __DIR__ . '/../../includes/helpers.php';
$site = (int)($_GET['site'] ?? 0);
$qs = $_GET; unset($qs['site']);
$extra = $qs ? '&' . http_build_query($qs) : '';
header('Location: ' . url('/dashboard/mentions.php?site=' . $site . '&tab=brand' . $extra), true, 301);
exit;
