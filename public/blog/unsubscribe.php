<?php
/**
 * Blog — Unsubscribe endpoint.
 * GET /blog/unsubscribe.php?token=xxx
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/newsletter.php';

$db = require __DIR__ . '/../../includes/db.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:60px"><h2>Invalid link</h2></body></html>';
    exit;
}

$success = newsletter_unsubscribe($db, $token);
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Unsubscribed</title></head>
<body style="font-family:-apple-system,sans-serif;text-align:center;padding:60px;">
<?php if ($success): ?>
    <h2>You've been unsubscribed</h2>
    <p style="color:#666;margin-top:8px;">You won't receive any more emails from us.</p>
<?php else: ?>
    <h2>Already unsubscribed</h2>
    <p style="color:#666;margin-top:8px;">This email was already removed from our list.</p>
<?php endif; ?>
</body></html>
