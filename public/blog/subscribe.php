<?php
/**
 * Blog — Newsletter subscribe endpoint.
 * POST /blog/subscribe.php { email, name }
 * Also serves as embeddable form via GET.
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/newsletter.php';

$db = require __DIR__ . '/../../includes/db.php';

// Determine site
$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/^www\./', '', $host);

$stmt = $db->prepare('SELECT * FROM sites WHERE domain = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$host]);
$site = $stmt->fetch();

// Also allow site_id param for local testing
if (!$site && isset($_REQUEST['site_id'])) {
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([(int)$_REQUEST['site_id']]);
    $site = $stmt->fetch();
}

if (!$site) {
    json_response(['error' => 'Site not found'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $email = $input['email'] ?? '';
    $name = $input['name'] ?? '';

    $result = newsletter_subscribe($db, $site['id'], $email, $name);

    if (!empty($input['redirect'])) {
        $_SESSION['flash'] = $result['success'] ? 'Subscribed!' : ($result['error'] ?? 'Error');
        header('Location: ' . $input['redirect']);
        exit;
    }

    json_response($result, $result['success'] ? 200 : 400);
}

// GET — show simple form (embeddable)
$site_name = e($site['name']);
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Subscribe — <?= $site_name ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f5f5}
.box{background:#fff;border-radius:8px;padding:28px;max-width:380px;width:100%;box-shadow:0 2px 8px rgba(0,0,0,0.1)}
h2{font-size:18px;color:#1B3A6B;margin-bottom:4px}
p{font-size:13px;color:#666;margin-bottom:16px}
input{width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:5px;font-size:14px;margin-bottom:10px}
input:focus{outline:none;border-color:#1B3A6B}
button{width:100%;padding:10px;background:#CC3300;color:#fff;border:none;border-radius:5px;font-size:14px;font-weight:600;cursor:pointer}
button:hover{background:#a82a00}
.msg{padding:8px;border-radius:5px;font-size:13px;margin-bottom:10px;background:#d1fae5;color:#065f46}
</style></head>
<body>
<div class="box">
    <h2>Subscribe to <?= $site_name ?></h2>
    <p>Get our latest articles delivered to your inbox weekly.</p>
    <?php if (!empty($_SESSION['flash'])): ?>
        <div class="msg"><?= e($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
    <?php endif; ?>
    <form method="POST">
        <input type="text" name="name" placeholder="Your name (optional)">
        <input type="email" name="email" placeholder="Your email" required>
        <button type="submit">Subscribe</button>
    </form>
</div>
</body></html>
