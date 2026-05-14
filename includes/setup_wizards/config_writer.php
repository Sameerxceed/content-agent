<?php
/**
 * Safely persist key/value pairs into config/config.php.
 * Used by global-scope setup wizards to save API keys.
 */

function config_write(array $updates): array
{
    $path = __DIR__ . '/../../config/config.php';
    if (!file_exists($path)) {
        return ['success' => false, 'error' => 'config.php not found'];
    }
    $cfg = require $path;
    if (!is_array($cfg)) {
        return ['success' => false, 'error' => 'config.php did not return an array'];
    }
    foreach ($updates as $k => $v) {
        $cfg[$k] = $v;
    }
    $body = "<?php\n/**\n * ContentAgent Configuration\n */\n\nreturn " . var_export($cfg, true) . ";\n";
    $bytes = @file_put_contents($path, $body);
    if ($bytes === false) {
        return ['success' => false, 'error' => 'Could not write config.php (permissions?)'];
    }
    return ['success' => true, 'bytes' => $bytes];
}
