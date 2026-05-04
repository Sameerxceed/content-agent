<?php
/**
 * Session-based authentication helpers.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Start session with secure settings.
 */
function auth_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $config = config();
    session_name($config['session_name']);

    session_set_cookie_params([
        'lifetime' => $config['session_lifetime'],
        'path'     => '/',
        'secure'   => $config['app_env'] === 'production',
        'httponly'  => true,
        'samesite'  => 'Lax',
    ]);

    session_start();
}

/**
 * Attempt login with email and password.
 */
function auth_login(PDO $db, string $email, string $password): bool
{
    $stmt = $db->prepare('SELECT id, email, password_hash, name, plan FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);

    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_plan']  = $user['plan'];
    $_SESSION['logged_in']  = true;

    return true;
}

/**
 * Log out the current user.
 */
function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Check if user is logged in.
 */
function auth_check(): bool
{
    return !empty($_SESSION['logged_in']);
}

/**
 * Get current user ID.
 */
function auth_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user data from session.
 */
function auth_user(): ?array
{
    if (!auth_check()) return null;
    return [
        'id'    => $_SESSION['user_id'],
        'email' => $_SESSION['user_email'],
        'name'  => $_SESSION['user_name'],
        'plan'  => $_SESSION['user_plan'],
    ];
}

/**
 * Require authentication — redirect to login if not logged in.
 */
function auth_require(): void
{
    if (!auth_check()) {
        redirect('/auth/login.php');
    }
}

/**
 * Require NO authentication — redirect to dashboard if logged in.
 */
function auth_guest(): void
{
    if (auth_check()) {
        redirect('/dashboard/index.php');
    }
}

/**
 * Hash a password for storage.
 */
function auth_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify API key from request header for API endpoints.
 */
function auth_api_verify(): bool
{
    $api_key = config('api_key');
    if (empty($api_key)) return false;

    $header = $_SERVER['HTTP_X_API_KEY'] ?? '';
    return hash_equals($api_key, $header);
}
