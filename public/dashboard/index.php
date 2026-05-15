<?php
/**
 * Dashboard — Site listing with key metrics.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();
$is_super = auth_is_super_admin();

// Super-admin sees every site (with owner column); regular users see their own.
$base_select = '
    SELECT s.*,
        u.name AS owner_name,
        u.email AS owner_email,
        (SELECT COUNT(*) FROM posts p WHERE p.site_id = s.id AND p.status = "published") as published,
        (SELECT COUNT(*) FROM posts p WHERE p.site_id = s.id AND p.status = "draft") as drafts,
        (SELECT COUNT(*) FROM posts p WHERE p.site_id = s.id) as total_posts,
        (SELECT COUNT(*) FROM keywords k WHERE k.site_id = s.id) as keywords,
        (SELECT a.score FROM seo_audits a WHERE a.site_id = s.id ORDER BY a.run_at DESC LIMIT 1) as seo_score,
        (SELECT COUNT(*) FROM seo_issues i WHERE i.audit_id = (SELECT a2.id FROM seo_audits a2 WHERE a2.site_id = s.id ORDER BY a2.run_at DESC LIMIT 1) AND i.status = "open") as open_issues,
        (SELECT al.created_at FROM agent_log al WHERE al.site_id = s.id ORDER BY al.created_at DESC LIMIT 1) as last_activity
    FROM sites s
    LEFT JOIN users u ON s.user_id = u.id
';

if ($is_super) {
    $stmt = $db->prepare($base_select . ' ORDER BY s.created_at DESC');
    $stmt->execute();
} else {
    $stmt = $db->prepare($base_select . ' WHERE s.user_id = ? ORDER BY s.created_at DESC');
    $stmt->execute([$user_id]);
}
$sites = $stmt->fetchAll();

$page_title = 'Dashboard';
$topbar_actions = '<a href="' . url('/dashboard/onboarding.php') . '" class="btn btn-accent">+ Add Site</a>';

ob_start();
?>

<?php if (empty($sites)): ?>
    <div class="card" style="text-align: center; padding: 48px 20px;">
        <div style="font-size: 40px; margin-bottom: 10px;">&#127760;</div>
        <p style="font-size: 16px; font-weight: 600; margin-bottom: 4px;">No sites yet</p>
        <p class="text-muted text-sm mb-4">Add your first website to start generating content and improving SEO.</p>
        <a href="<?= url('/dashboard/onboarding.php') ?>" class="btn btn-accent">+ Add Your First Site</a>
    </div>
<?php else: ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 14px;">
        <?php foreach ($sites as $s):
            $sc_class = 'score-bad';
            if ($s['seo_score'] >= 80) $sc_class = 'score-good';
            elseif ($s['seo_score'] >= 50) $sc_class = 'score-ok';
        ?>
        <a href="<?= url('/dashboard/site.php?id=' . $s['id']) ?>" style="text-decoration: none; color: inherit;">
            <div class="card" style="cursor: pointer; transition: box-shadow 0.15s, transform 0.15s; margin-bottom: 0;" onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,0.1)';this.style.transform='translateY(-2px)'" onmouseout="this.style.boxShadow='';this.style.transform=''">

                <!-- Site header -->
                <div class="flex items-center gap-4" style="margin-bottom: 12px;">
                    <?php if ($s['seo_score'] !== null): ?>
                        <span class="score-circle <?= $sc_class ?>" style="width:44px;height:44px;font-size:16px;flex-shrink:0;"><?= $s['seo_score'] ?></span>
                    <?php else: ?>
                        <span class="score-circle" style="width:44px;height:44px;font-size:11px;background:#f1f5f9;color:#94a3b8;flex-shrink:0;">N/A</span>
                    <?php endif; ?>
                    <div style="min-width: 0;">
                        <div style="font-size: 15px; font-weight: 600; color: var(--primary);"><?= e($s['name']) ?></div>
                        <div class="text-sm text-muted" style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                            <?= e($s['domain']) ?>
                            <span class="badge badge-<?= $s['is_active'] ? 'approved' : 'rejected' ?>" style="font-size:10px;"><?= $s['is_active'] ? 'Active' : 'Off' ?></span>
                            <?php if ($s['platform']): ?>
                                <span class="badge badge-info" style="font-size:10px;"><?= e($s['platform']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($is_super && !empty($s['owner_email'])): ?>
                            <div style="font-size: 10px; color: #94a3b8; margin-top:2px;">
                                Owner: <?= e($s['owner_name'] ?: $s['owner_email']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Metrics grid -->
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; padding: 10px 0; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
                    <div style="text-align: center;">
                        <div style="font-size: 18px; font-weight: 700; color: var(--primary);"><?= $s['published'] ?></div>
                        <div style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">Published</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 18px; font-weight: 700; color: <?= $s['drafts'] > 0 ? 'var(--warning)' : 'var(--primary)' ?>;"><?= $s['drafts'] ?></div>
                        <div style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">Drafts</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 18px; font-weight: 700; color: var(--primary);"><?= $s['keywords'] ?></div>
                        <div style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">Keywords</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 18px; font-weight: 700; color: <?= $s['open_issues'] > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= $s['open_issues'] ?></div>
                        <div style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">Issues</div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="flex justify-between items-center" style="padding-top: 8px;">
                    <span class="text-sm text-muted">
                        <?php if ($s['last_activity']): ?>
                            Last activity: <?= format_date($s['last_activity'], 'H:i, d M') ?>
                        <?php else: ?>
                            No activity yet
                        <?php endif; ?>
                    </span>
                    <span style="color: var(--accent); font-size: 13px; font-weight: 500;">View &rarr;</span>
                </div>

            </div>
        </a>
        <?php endforeach; ?>

        <!-- Add site card -->
        <a href="<?= url('/dashboard/onboarding.php') ?>" style="text-decoration: none;">
            <div class="card" style="cursor: pointer; margin-bottom: 0; display: flex; align-items: center; justify-content: center; min-height: 180px; border: 2px dashed var(--border); background: #fafbfc;" onmouseover="this.style.borderColor='var(--accent)';this.style.background='#fff'" onmouseout="this.style.borderColor='var(--border)';this.style.background='#fafbfc'">
                <div style="text-align: center;">
                    <div style="font-size: 28px; color: var(--accent); margin-bottom: 4px;">+</div>
                    <div style="font-size: 13px; color: var(--accent); font-weight: 500;">Add New Site</div>
                </div>
            </div>
        </a>
    </div>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
