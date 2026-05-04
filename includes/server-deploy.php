<?php
/**
 * Server Deploy — Push files directly to customer's server.
 * Supports FTP, SFTP, and API-based deployment.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Deploy a file to the server based on site config.
 */
function server_deploy_file(array $site, string $filename, string $content): array
{
    $type = $site['server_type'] ?? 'api_only';

    switch ($type) {
        case 'ftp':
            return deploy_via_ftp($site, $filename, $content);
        case 'sftp':
            return deploy_via_sftp($site, $filename, $content);
        case 'api_only':
            return deploy_via_api($site, $filename, $content);
        default:
            return ['success' => false, 'error' => "Unsupported server type: {$type}"];
    }
}

/**
 * Deploy via FTP.
 */
function deploy_via_ftp(array $site, string $filename, string $content): array
{
    $host = $site['server_host'] ?? '';
    $user = $site['server_user'] ?? '';
    $pass = $site['server_pass'] ?? '';
    $path = rtrim($site['server_path'] ?? '/public_html', '/');

    if (empty($host) || empty($user)) {
        return ['success' => false, 'error' => 'FTP host and username required'];
    }

    $conn = @ftp_connect($host, 21, 15);
    if (!$conn) {
        return ['success' => false, 'error' => "Cannot connect to FTP: {$host}"];
    }

    if (!@ftp_login($conn, $user, $pass)) {
        ftp_close($conn);
        return ['success' => false, 'error' => 'FTP login failed'];
    }

    ftp_pasv($conn, true);

    // Write content to temp file
    $tmp = tmpfile();
    fwrite($tmp, $content);
    rewind($tmp);

    $remote_path = $path . '/' . $filename;
    $success = @ftp_fput($conn, $remote_path, $tmp, FTP_ASCII);

    fclose($tmp);
    ftp_close($conn);

    if ($success) {
        return ['success' => true, 'path' => $remote_path];
    }

    return ['success' => false, 'error' => "Failed to upload {$filename} to {$remote_path}"];
}

/**
 * Deploy via SFTP (requires ssh2 extension).
 */
function deploy_via_sftp(array $site, string $filename, string $content): array
{
    if (!function_exists('ssh2_connect')) {
        return ['success' => false, 'error' => 'SSH2 extension not installed'];
    }

    $host = $site['server_host'] ?? '';
    $user = $site['server_user'] ?? '';
    $pass = $site['server_pass'] ?? '';
    $path = rtrim($site['server_path'] ?? '/var/www/html', '/');

    $conn = @ssh2_connect($host, 22);
    if (!$conn) {
        return ['success' => false, 'error' => "Cannot connect to SSH: {$host}"];
    }

    if (!@ssh2_auth_password($conn, $user, $pass)) {
        return ['success' => false, 'error' => 'SSH authentication failed'];
    }

    $sftp = @ssh2_sftp($conn);
    if (!$sftp) {
        return ['success' => false, 'error' => 'Cannot initialize SFTP'];
    }

    $remote_path = $path . '/' . $filename;
    $stream = @fopen("ssh2.sftp://{$sftp}{$remote_path}", 'w');

    if (!$stream) {
        return ['success' => false, 'error' => "Cannot write to {$remote_path}"];
    }

    fwrite($stream, $content);
    fclose($stream);

    return ['success' => true, 'path' => $remote_path];
}

/**
 * Deploy via CMS API (deploy-file endpoint).
 */
function deploy_via_api(array $site, string $filename, string $content): array
{
    $cms_url = $site['cms_url'] ?? '';
    $api_key = $site['cms_api_key'] ?? '';

    if (empty($cms_url) || empty($api_key)) {
        return ['success' => false, 'error' => 'CMS URL and API key required'];
    }

    $deploy_url = rtrim($cms_url, '/') . '/api/deploy-file.php';

    $ch = curl_init($deploy_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['filename' => $filename, 'content' => $content]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $api_key,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true);

    if ($status === 200 && !empty($data['success'])) {
        return ['success' => true, 'path' => $filename];
    }

    return ['success' => false, 'error' => $data['error'] ?? "HTTP {$status}"];
}

/**
 * Deploy multiple files to the server.
 */
function server_deploy_batch(array $site, array $files): array
{
    $results = [];
    foreach ($files as $filename => $content) {
        $result = server_deploy_file($site, $filename, $content);
        $results[$filename] = $result;
    }
    return $results;
}

/**
 * Test server connection.
 */
function server_test_connection(array $site): array
{
    $type = $site['server_type'] ?? 'api_only';

    if ($type === 'ftp') {
        $conn = @ftp_connect($site['server_host'] ?? '', 21, 10);
        if (!$conn) return ['success' => false, 'error' => 'Cannot connect'];
        $login = @ftp_login($conn, $site['server_user'] ?? '', $site['server_pass'] ?? '');
        ftp_close($conn);
        return $login ? ['success' => true, 'message' => 'FTP connection OK'] : ['success' => false, 'error' => 'Login failed'];
    }

    if ($type === 'api_only') {
        $cms_url = $site['cms_url'] ?? '';
        if (empty($cms_url)) return ['success' => false, 'error' => 'No CMS URL'];
        $result = http_get($cms_url, [], 10);
        return $result['status'] >= 200 && $result['status'] < 400
            ? ['success' => true, 'message' => 'CMS reachable']
            : ['success' => false, 'error' => "HTTP {$result['status']}"];
    }

    return ['success' => false, 'error' => "Test not implemented for {$type}"];
}
