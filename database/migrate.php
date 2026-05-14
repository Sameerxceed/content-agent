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

/**
 * Split a SQL file into individual statements, respecting:
 *   - Line comments: -- to end of line, # to end of line
 *   - Block comments: /* ... *‍/
 *   - String literals: single-quoted and double-quoted with backslash escapes and '' doubling
 */
function sql_split(string $sql): array
{
    $stmts = [];
    $buf = '';
    $i = 0;
    $len = strlen($sql);

    while ($i < $len) {
        $c = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        // Line comment -- or #
        if (($c === '-' && $next === '-') || $c === '#') {
            $nl = strpos($sql, "\n", $i);
            if ($nl === false) break;
            $i = $nl + 1;
            continue;
        }
        // Block comment /* ... */
        if ($c === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) break;
            $i = $end + 2;
            continue;
        }
        // String literal
        if ($c === "'" || $c === '"') {
            $quote = $c;
            $buf .= $c;
            $i++;
            while ($i < $len) {
                $ch = $sql[$i];
                $buf .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $buf .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($ch === $quote) {
                    // Doubled quote means literal quote, stay in string
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $buf .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $i++;
            }
            continue;
        }
        // Statement terminator
        if ($c === ';') {
            $t = trim($buf);
            if ($t !== '') $stmts[] = $t;
            $buf = '';
            $i++;
            continue;
        }
        $buf .= $c;
        $i++;
    }

    $t = trim($buf);
    if ($t !== '') $stmts[] = $t;
    return $stmts;
}

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
        // statement doesn't roll back the whole migration. String-aware so a
        // semicolon inside a COMMENT string doesn't break the statement.
        $statements = sql_split($sql);
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
