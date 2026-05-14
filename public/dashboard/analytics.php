<?php
/** Replaced by Performance (per-site) + the home dashboard (multi-site). */
require_once __DIR__ . '/../../includes/helpers.php';
$site = (int)($_GET['site'] ?? 0);
if ($site) {
    header('Location: ' . url('/dashboard/performance.php?site=' . $site), true, 301);
} else {
    header('Location: ' . url('/dashboard/index.php'), true, 301);
}
exit;
