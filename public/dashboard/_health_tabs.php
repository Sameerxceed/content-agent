<?php
/**
 * Shared Health-cluster tab bar.
 * Include after $filter_site / $site_id is set. Pass $active = 'audit' | 'approvals' | 'ai' | 'report'.
 */
$health_site = isset($site_id) ? (int)$site_id : (int)($filter_site ?? 0);
if (!$health_site) return;

$health_active = $active ?? 'audit';
$health_tabs = [
    'audit'     => ['Issues',       url('/dashboard/seo-audit.php?site=' . $health_site)],
    'approvals' => ['Approvals',    url('/dashboard/seo-approvals.php?site=' . $health_site)],
    'ai'        => ['AI Readiness', url('/dashboard/ai-seo.php?site=' . $health_site)],
    'report'    => ['Full Report',  url('/dashboard/report.php?site=' . $health_site)],
];

// Pending-approvals count for the badge
$health_pending = 0;
try {
    if (isset($db) && $db instanceof PDO) {
        $_h_stmt = $db->prepare('SELECT COUNT(*) FROM page_seo WHERE site_id = ? AND status = "pending"');
        $_h_stmt->execute([$health_site]);
        $health_pending = (int)$_h_stmt->fetchColumn();
    }
} catch (PDOException $e) {}
?>
<div style="display:flex;gap:2px;border-bottom:1px solid var(--border);margin-bottom:14px;">
    <?php foreach ($health_tabs as $key => [$label, $href]):
        $is_active = $health_active === $key;
    ?>
    <a href="<?= e($href) ?>" style="text-decoration:none;padding:10px 16px;font-size:13px;border-bottom:2px solid <?= $is_active ? 'var(--accent)' : 'transparent' ?>;color:<?= $is_active ? 'var(--accent)' : '#64748b' ?>;font-weight:<?= $is_active ? '600' : '500' ?>;">
        <?= $label ?>
        <?php if ($key === 'approvals' && $health_pending > 0): ?>
            <span style="font-size:10px;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:8px;margin-left:4px;"><?= $health_pending ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
