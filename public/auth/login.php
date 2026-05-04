<?php
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();

// Already logged in?
if (auth_check()) {
    redirect('/dashboard/index.php');
}

$bp = base_path();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter your email and password.';
        } else {
            $db = require __DIR__ . '/../../includes/db.php';
            if (auth_login($db, $email, $password)) {
                redirect('/dashboard/index.php');
                return;
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — ContentAgent</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1B3A6B;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .login-box {
            background: #fff;
            border-radius: 8px;
            padding: 32px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .login-logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .login-logo img {
            max-width: 160px;
            height: auto;
        }
        .login-title {
            text-align: center;
            font-size: 14px;
            color: #64748b;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 14px;
        }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 4px;
        }
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
            color: #2c3e50;
            transition: border-color 0.15s;
        }
        .form-control:focus {
            outline: none;
            border-color: #1B3A6B;
            box-shadow: 0 0 0 3px rgba(27, 58, 107, 0.1);
        }
        .btn-login {
            width: 100%;
            padding: 10px;
            background: #CC3300;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
            margin-top: 4px;
        }
        .btn-login:hover { background: #a82a00; }
        .error {
            background: #fecaca;
            color: #991b1b;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 14px;
            border: 1px solid #fca5a5;
        }
        .footer-text {
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            margin-top: 16px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="login-logo">
            <img src="<?= $bp ?>/dashboard/assets/img/logo.png" alt="ContentAgent">
        </div>
        <div class="login-title">Sign in to ContentAgent</div>

        <?php if ($error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control"
                       value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" required>
            </div>

            <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div class="footer-text">Powered by Xceed Imagination</div>
    </div>
</body>
</html>
