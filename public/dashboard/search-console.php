<?php
/** Merged into keywords.php?view=gsc — kept for backward-compatibility. */
require_once __DIR__ . '/../../includes/helpers.php';
$site = (int)($_GET['site'] ?? 0);
$qs = $_GET; unset($qs['site']);
$qs['view'] = 'gsc';
$extra = '&' . http_build_query($qs);
header('Location: ' . url('/dashboard/keywords.php?site=' . $site . $extra), true, 301);
exit;
