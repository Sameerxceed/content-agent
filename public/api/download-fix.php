<?php
/**
 * Download Fix File API
 * Generates and serves fix files for download.
 *
 * GET ?site_id=3&type=header   — download header snippet
 * GET ?site_id=3&type=sitemap  — download sitemap.xml
 * GET ?site_id=3&type=robots   — download robots.txt
 * GET ?site_id=3&type=all      — download ZIP of all fixes
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/fix-generator.php';

auth_start();

if (!auth_check()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site_id'] ?? 0);
$type = $_GET['type'] ?? '';

if (!$site_id || !$type) {
    http_response_code(400);
    echo 'site_id and type required';
    exit;
}

// Verify site ownership
$site = auth_get_accessible_site($db, $site_id);

if (!$site) {
    http_response_code(404);
    echo 'Site not found';
    exit;
}

// Generate the requested fix
if ($type === 'header') {
    $fix = fix_generate_header_snippet($site, $db);
} elseif ($type === 'sitemap') {
    $fix = fix_generate_sitemap($site, $db);
} elseif ($type === 'robots') {
    $fix = fix_generate_robots($site);
} elseif ($type === 'all') {
    // Generate ZIP with all fixes
    $fixes = fix_generate_all($site, $db);

    $zip_path = sys_get_temp_dir() . '/contentagent-fixes-' . $site['domain'] . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach ($fixes as $fix) {
            $zip->addFromString($fix['path'], $fix['content']);
        }

        // Add README with instructions
        $readme = "ContentAgent SEO Fix Files for {$site['domain']}\n";
        $readme .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $readme .= str_repeat('=', 50) . "\n\n";
        foreach ($fixes as $fix) {
            $readme .= "File: {$fix['filename']}\n";
            $readme .= "Upload to: {$fix['path']}\n";
            $readme .= "Instructions: {$fix['instructions']}\n\n";
        }
        $zip->addFromString('README.txt', $readme);
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="seo-fixes-' . $site['domain'] . '.zip"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        unlink($zip_path);
        exit;
    }

    http_response_code(500);
    echo 'Could not create ZIP';
    exit;
} else {
    http_response_code(400);
    echo 'Invalid type. Use: header, sitemap, robots, or all';
    exit;
}

// Serve single file download
$content_types = [
    'sitemap' => 'application/xml',
    'robots' => 'text/plain',
    'header' => 'text/plain',
];

header('Content-Type: ' . ($content_types[$type] ?? 'text/plain'));
header('Content-Disposition: attachment; filename="' . $fix['filename'] . '"');
header('Content-Length: ' . strlen($fix['content']));
echo $fix['content'];
