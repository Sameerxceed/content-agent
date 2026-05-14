<?php
/** Replaced by the modular channel system on each post page + Integrations Hub for connections. */
require_once __DIR__ . '/../../includes/helpers.php';
$site = (int)($_GET['site'] ?? 0);
$post = (int)($_GET['post'] ?? 0);
if ($post) {
    header('Location: ' . url('/dashboard/posts.php?site=' . $site . '&action=edit&id=' . $post), true, 301);
} else {
    header('Location: ' . url('/dashboard/integrations.php'), true, 301);
}
exit;
