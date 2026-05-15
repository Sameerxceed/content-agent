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
    $stmt = $db->prepare('SELECT id, email, password_hash, name, plan, is_super_admin FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    auth_set_session($user);
    return true;
}

/**
 * Set the session vars for a logged-in user. Extracted so signup and
 * password-reset can reuse it without going through auth_login().
 *
 * @param array $user row from users with at minimum: id, email, name, plan, is_super_admin
 */
function auth_set_session(array $user): void
{
    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);

    $_SESSION['user_id']         = $user['id'];
    $_SESSION['user_email']      = $user['email'];
    $_SESSION['user_name']       = $user['name'];
    $_SESSION['user_plan']       = $user['plan'] ?? 'free';
    $_SESSION['is_super_admin']  = (int)($user['is_super_admin'] ?? 0);
    $_SESSION['logged_in']       = true;
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
        'id'             => $_SESSION['user_id'],
        'email'          => $_SESSION['user_email'],
        'name'           => $_SESSION['user_name'],
        'plan'           => $_SESSION['user_plan'] ?? 'free',
        'is_super_admin' => (int)($_SESSION['is_super_admin'] ?? 0),
    ];
}

/**
 * Is the current user a global super-admin? (sees all sites)
 */
function auth_is_super_admin(): bool
{
    return !empty($_SESSION['is_super_admin']);
}

/**
 * Can the current user act on this site?
 * Super-admins can act on any site; regular users only on sites they own.
 *
 * Returns false if not logged in.
 */
function auth_can_access_site(PDO $db, int $site_id): bool
{
    if (!auth_check() || $site_id <= 0) return false;
    if (auth_is_super_admin()) {
        // Still confirm the site exists
        $stmt = $db->prepare('SELECT 1 FROM sites WHERE id = ?');
        $stmt->execute([$site_id]);
        return (bool)$stmt->fetchColumn();
    }
    $stmt = $db->prepare('SELECT 1 FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([$site_id, auth_user_id()]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Convenience: load a site by id if accessible, else null.
 */
function auth_get_accessible_site(PDO $db, int $site_id): ?array
{
    if (!auth_check() || $site_id <= 0) return null;
    if (auth_is_super_admin()) {
        $stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
        $stmt->execute([$site_id, auth_user_id()]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
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
