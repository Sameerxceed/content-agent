<?php
/**
 * Migration runner — applies any *.sql in database/migrations/ that hasn't been applied yet.
 *
 * Idempotent: tracks applied files in `schema_migrations`. Safe to run repeatedly.
 * Called automatically by deploy-webhook.php after git pull; also runnable manually:
 *   php database/migrate.php
 *
 * Returns array { applied: [filenames], skipped: [filenames], errors: [{file, error}] }.
 */

require_once __DIR__ . '/../includes/helpers.php';

function run_migrations(?PDO $db = null): array
{
    if ($db === null) {
        $db = require __DIR__ . '/../includes/db.php';
    }

    // Track table — created if missing
    $db->exec('CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `filename` VARCHAR(255) NOT NULL,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`filename`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $applied_already = [];
    foreach ($db->query('SELECT filename FROM schema_migrations') as $r) {
        $applied_already[$r['filename']] = true;
    }

    $files = glob(__DIR__ . '/migrations/*.sql');
    sort($files, SORT_NATURAL);

    $result = ['applied' => [], 'skipped' => [], 'errors' => []];

    foreach ($files as $path) {
        $name = basename($path);
        if (isset($applied_already[$name])) {
            $result['skipped'][] = $name;
            continue;
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            $result['errors'][] = ['file' => $name, 'error' => 'Cannot read file'];
            continue;
        }

        // Split into individual statements so duplicate-column / table-exists in one
        // statement doesn't roll back the whole migration (handles re-running on a
        // partially-deployed DB where some tables/columns from an old migration are
        // already present). Naive split is fine — our migrations don't use semicolons
        // inside string literals or stored procedures.
        $statements = array_filter(array_map('trim', explode(';', preg_replace('#--[^\n]*#', '', $sql))));
        $had_real_error = false;
        $had_any_change = false;
        $stmt_errors = [];

        foreach ($statements as $stmt) {
            if ($stmt === '') continue;
            try {
                $db->exec($stmt);
                $had_any_change = true;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                // Treat idempotency errors as benign: the schema already has what
                // this statement was trying to create.
                if (
                    stripos($msg, 'already exists') !== false ||
                    stripos($msg, 'Duplicate column') !== false ||
                    stripos($msg, 'Duplicate key name') !== false ||
                    stripos($msg, "Can't DROP") !== false
                ) {
                    continue;
                }
                $had_real_error = true;
                $stmt_errors[] = $msg;
                break;
            }
        }

        if ($had_real_error) {
            $result['errors'][] = ['file' => $name, 'error' => implode(' | ', $stmt_errors)];
            break;
        }

        $db->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
        $result['applied'][] = $name;
    }

    return $result;
}

// CLI mode
if (PHP_SAPI === 'cli') {
    $r = run_migrations();
    echo "Applied: " . count($r['applied']) . " | Skipped: " . count($r['skipped']) . " | Errors: " . count($r['errors']) . "\n";
    foreach ($r['applied'] as $f) echo "  + {$f}\n";
    foreach ($r['errors'] as $e) echo "  ! {$e['file']}: {$e['error']}\n";
    exit(empty($r['errors']) ? 0 : 1);
}
