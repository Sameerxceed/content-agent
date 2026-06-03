<?php
/**
 * Merged into AEO Tracker (aeo.php). Kept as a permanent redirect so existing
 * bookmarks, alert notifications, and external links continue to work.
 */
require_once __DIR__ . '/../../includes/helpers.php';

$site_id = (int)($_GET['site'] ?? 0);
$target  = $site_id ? '/dashboard/aeo.php?site=' . $site_id : '/dashboard/index.php';
redirect($target);
