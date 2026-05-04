<?php
/**
 * ContentAgent Setup Script
 * Run once to set up the database and admin user.
 *
 * Usage: php setup.php
 */

echo "ContentAgent Setup\n";
echo str_repeat('=', 40) . "\n\n";

// Check config
if (!file_exists(__DIR__ . '/config/config.php')) {
    if (file_exists(__DIR__ . '/config/config.example.php')) {
        copy(__DIR__ . '/config/config.example.php', __DIR__ . '/config/config.php');
        echo "[OK] Created config/config.php from example\n";
        echo "     Edit config/config.php with your database and API credentials.\n\n";
    } else {
        echo "[ERROR] No config file found. Create config/config.php\n";
        exit(1);
    }
}

require_once __DIR__ . '/includes/helpers.php';

// Test database connection
echo "Connecting to database...\n";
try {
    $db = require __DIR__ . '/includes/db.php';
    echo "[OK] Database connected: " . config('db_name') . "\n";
} catch (Exception $e) {
    echo "[ERROR] Database connection failed: " . $e->getMessage() . "\n";
    echo "Check your config/config.php database settings.\n";
    exit(1);
}

// Run migrations
echo "\nRunning migrations...\n";
$migration_dir = __DIR__ . '/database/migrations/';
$files = glob($migration_dir . '*.sql');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    echo "  Running: {$name}...\n";
    $sql = file_get_contents($file);

    try {
        $db->exec($sql);
        echo "  [OK] {$name}\n";
    } catch (PDOException $e) {
        // Ignore "already exists" errors
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "  [SKIP] Tables already exist\n";
        } else {
            echo "  [ERROR] {$e->getMessage()}\n";
        }
    }
}

// Create admin user
echo "\nSetting up admin user...\n";
$stmt = $db->query('SELECT COUNT(*) FROM users');
$user_count = $stmt->fetchColumn();

if ($user_count === 0) {
    $email = 'admin@contentagent.app';
    $password = 'changeme123';
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $db->prepare('INSERT INTO users (email, password_hash, name, plan) VALUES (?, ?, ?, ?)');
    $stmt->execute([$email, $hash, 'Admin', 'agency']);

    echo "[OK] Admin user created\n";
    echo "     Email: {$email}\n";
    echo "     Password: {$password}\n";
    echo "     CHANGE THIS PASSWORD after first login!\n";
} else {
    echo "[SKIP] Users already exist ({$user_count})\n";
}

// Create directories
echo "\nChecking directories...\n";
$dirs = ['logs', 'cache'];
foreach ($dirs as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
        echo "  [OK] Created {$dir}/\n";
    } else {
        echo "  [OK] {$dir}/ exists\n";
    }
}

// Summary
echo "\n" . str_repeat('=', 40) . "\n";
echo "Setup complete!\n\n";
echo "Next steps:\n";
echo "  1. Edit config/config.php (database + API key)\n";
echo "  2. Point your web server to public/ directory\n";
echo "  3. Open the app in your browser\n";
echo "  4. Log in and add your first site\n";
echo "  5. Run: php agent/scanner.php --site=1\n\n";

// Check for Haiku API key
if (empty(config('haiku_api_key'))) {
    echo "NOTE: No Haiku API key configured.\n";
    echo "      AI features (blog writing, meta generation, brand analysis)\n";
    echo "      will not work until you add your key to config/config.php\n";
    echo "      Get one at: https://console.anthropic.com\n\n";
}

echo "Crontab (for production):\n";
echo "  # Daily: news scraper\n";
echo "  0 6 * * * php " . __DIR__ . "/agent/news-scraper.php --all\n";
echo "  # Mon + Thu: blog writer\n";
echo "  0 7 * * 1,4 php " . __DIR__ . "/agent/blog-writer.php --site=1 --count=2\n";
echo "  # Weekly: keyword re-evaluation + SEO audit\n";
echo "  0 8 * * 0 php " . __DIR__ . "/agent/keyword-research.php --site=1\n";
echo "  0 9 * * 0 php " . __DIR__ . "/agent/seo-auditor.php --site=1\n";
echo "  0 10 * * 0 php " . __DIR__ . "/agent/evaluator.php --all\n";
