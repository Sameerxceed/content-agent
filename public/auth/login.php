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
                <div style="position:relative;">
                    <input type="password" id="password" name="password" class="form-control" style="padding-right:40px;" required>
                    <button type="button" onclick="togglePassword()" id="eye-btn" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:16px;color:#94a3b8;padding:4px;" title="Show password">
                        <svg id="eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg id="eye-off-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>

            <script>
            function togglePassword() {
                const pwd = document.getElementById('password');
                const eyeOn = document.getElementById('eye-icon');
                const eyeOff = document.getElementById('eye-off-icon');
                if (pwd.type === 'password') {
                    pwd.type = 'text';
                    eyeOn.style.display = 'none';
                    eyeOff.style.display = 'block';
                } else {
                    pwd.type = 'password';
                    eyeOn.style.display = 'block';
                    eyeOff.style.display = 'none';
                }
            }
            </script>

            <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div class="footer-text">Powered by Xceed Imagination</div>
    </div>
</body>
</html>
