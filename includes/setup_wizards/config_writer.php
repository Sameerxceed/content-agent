<?php
/**
 * Safely persist key/value pairs into config/config.php.
 * Used by global-scope setup wizards to save API keys.
 *
 * Throws RuntimeException on failure so save callbacks can't ignore problems.
 * (Previous version returned a status array that all callers discarded —
 * which let users land on "test failed: key not saved" instead of seeing
 * the real save error at the step where they typed.)
 */

function config_write(array $updates): array
{
    $path = __DIR__ . '/../../config/config.php';
    if (!file_exists($path)) {
        throw new RuntimeException('config.php not found at ' . $path);
    }
    $cfg = require $path;
    if (!is_array($cfg)) {
        throw new RuntimeException('config.php did not return an array');
    }
    foreach ($updates as $k => $v) {
        $cfg[$k] = $v;
    }
    $body = "<?php\n/**\n * ContentAgent Configuration\n */\n\nreturn " . var_export($cfg, true) . ";\n";
    $bytes = @file_put_contents($path, $body);
    if ($bytes === false) {
        $err = error_get_last();
        throw new RuntimeException('Could not write config.php — ' . ($err['message'] ?? 'check permissions on ' . $path));
    }
    // Invalidate opcache so the next require sees the new value, otherwise
    // subsequent config() reads in the same process can return stale values.
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
    return ['success' => true, 'bytes' => $bytes];
}

/** Remove key(s) from config/config.php. Mirror of config_write for cleanup on wizard reset. */
function config_unset(array $keys): void
{
    $path = __DIR__ . '/../../config/config.php';
    if (!file_exists($path)) return;
    $cfg = require $path;
    if (!is_array($cfg)) return;
    $changed = false;
    foreach ($keys as $k) {
        if (array_key_exists($k, $cfg)) { unset($cfg[$k]); $changed = true; }
    }
    if (!$changed) return;
    $body = "<?php\n/**\n * ContentAgent Configuration\n */\n\nreturn " . var_export($cfg, true) . ";\n";
    @file_put_contents($path, $body);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
}
