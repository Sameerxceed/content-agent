<?php
/**
 * Export SEO Audit Report as CSV
 * Downloads a CSV file with all issues, action items, and status.
 *
 * GET ?audit_id=15
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();

if (!auth_check()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$audit_id = (int)($_GET['audit_id'] ?? 0);

if (!$audit_id) {
    http_response_code(400);
    echo 'audit_id required';
    exit;
}

// Verify ownership
$stmt = $db->prepare('SELECT a.*, s.domain, s.name as site_name FROM seo_audits a JOIN sites s ON a.site_id = s.id WHERE a.id = ? AND s.user_id = ?');
$stmt->execute([$audit_id, $user_id]);
$audit = $stmt->fetch();

if (!$audit) {
    http_response_code(404);
    echo 'Audit not found';
    exit;
}

// Get all issues for this audit
$stmt = $db->prepare('SELECT * FROM seo_issues WHERE audit_id = ? ORDER BY FIELD(severity, "critical", "warning", "info"), type, url');
$stmt->execute([$audit_id]);
$issues = $stmt->fetchAll();

// Generate CSV
$filename = 'seo-audit-' . $audit['domain'] . '-' . date('Y-m-d', strtotime($audit['run_at'])) . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Add BOM for Excel UTF-8 compatibility
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Header row
fputcsv($output, [
    'SEO Audit Report: ' . $audit['site_name'] . ' (' . $audit['domain'] . ')',
]);
fputcsv($output, [
    'Date: ' . date('d M Y, h:i A', strtotime($audit['run_at'])),
    'Score: ' . $audit['score'] . '/100',
    'Pages Crawled: ' . $audit['pages_crawled'],
]);
fputcsv($output, []); // blank row

// Column headers
fputcsv($output, [
    '#',
    'Priority',
    'Type',
    'Page URL',
    'Issue',
    'Action Required',
    'Status',
]);

$i = 1;
foreach ($issues as $issue) {
    $priority = $issue['severity'] === 'critical' ? 'HIGH' : ($issue['severity'] === 'warning' ? 'MEDIUM' : 'LOW');

    $status_label = match ($issue['status']) {
        'open' => 'Action Needed',
        'fix_proposed' => 'Fix Available',
        'fix_applied' => 'Fixed',
        'resolved' => 'Resolved',
        'ignored' => 'Ignored',
        'fixed_by_snippet' => 'Auto-Fixed (Snippet)',
        default => $issue['status'],
    };

    fputcsv($output, [
        $i++,
        $priority,
        str_replace('_', ' ', ucfirst($issue['type'])),
        $issue['url'],
        $issue['description'],
        $issue['suggested_fix'] ?? '',
        $status_label,
    ]);
}

// Summary at bottom
fputcsv($output, []);
fputcsv($output, ['SUMMARY']);
fputcsv($output, ['Total Issues', count($issues)]);
fputcsv($output, ['Critical (HIGH)', count(array_filter($issues, fn($i) => $i['severity'] === 'critical'))]);
fputcsv($output, ['Warnings (MEDIUM)', count(array_filter($issues, fn($i) => $i['severity'] === 'warning'))]);
fputcsv($output, ['Info (LOW)', count(array_filter($issues, fn($i) => $i['severity'] === 'info'))]);
fputcsv($output, ['Auto-Fixed', count(array_filter($issues, fn($i) => $i['status'] === 'fixed_by_snippet' || $i['status'] === 'fix_applied'))]);
fputcsv($output, ['Action Needed', count(array_filter($issues, fn($i) => $i['status'] === 'open'))]);

fclose($output);
