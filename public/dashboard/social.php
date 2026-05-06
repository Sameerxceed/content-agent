<?php
/**
 * Dashboard — Social Media Integrations.
 * Connect accounts, view status, post to social platforms.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? 0);

$page_title = '📱 Social Media';

// Get sites
$stmt = $db->prepare('SELECT id, name, domain FROM sites WHERE user_id = ? ORDER BY name');
$stmt->execute([$user_id]);
$sites = $stmt->fetchAll();

ob_start();

if (empty($site_id)): ?>
<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/index.php') ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to Dashboard</a>
</div>
<?php endif; ?>

<!-- Site selector -->
<div class="card" style="padding:10px 16px;margin-bottom:14px;">
    <form method="GET" class="flex gap-4 items-center">
        <select name="site" class="form-control" style="width:auto;min-width:200px;">
            <option value="">Select a site</option>
            <?php foreach ($sites as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $site_id == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Manage</button>
    </form>
</div>

<?php if ($site_id):
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([$site_id, $user_id]);
    $site = $stmt->fetch();

    if (!$site): ?>
        <div class="alert alert-error">Site not found.</div>
    <?php else:
        // Get all integrations for this site
        $stmt = $db->prepare('SELECT * FROM integrations WHERE site_id = ? ORDER BY platform');
        $stmt->execute([$site_id]);
        $integrations = [];
        foreach ($stmt->fetchAll() as $i) $integrations[$i['platform']] = $i;

        $platforms = [
            'linkedin' => [
                'name'  => 'LinkedIn',
                'icon'  => '💼',
                'color' => '#0A66C2',
                'config_key' => 'linkedin_client_id',
                'auth_fn' => 'linkedin_get_auth_url',
            ],
            'twitter' => [
                'name'  => 'Twitter / X',
                'icon'  => '🐦',
                'color' => '#1DA1F2',
                'config_key' => 'twitter_client_id',
                'auth_fn' => 'twitter_get_auth_url',
            ],
            'facebook' => [
                'name'  => 'Facebook + Instagram',
                'icon'  => '📘',
                'color' => '#1877F2',
                'config_key' => 'facebook_app_id',
                'auth_fn' => 'facebook_get_auth_url',
            ],
            'google_search_console' => [
                'name'  => 'Google Search Console',
                'icon'  => '📊',
                'color' => '#4285F4',
                'config_key' => 'google_client_id',
                'auth_fn' => 'google_get_auth_url',
            ],
        ];
    ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-bottom:16px;">
        <?php foreach ($platforms as $key => $p):
            $connected = isset($integrations[$key]) && $integrations[$key]['is_active'];
            $integration = $integrations[$key] ?? null;
            $has_config = !empty(config($p['config_key']));
        ?>
        <div class="card" style="margin-bottom:0;border-top:3px solid <?= $connected ? '#10b981' : $p['color'] ?>;">
            <div class="flex items-center gap-4" style="margin-bottom:10px;">
                <div style="font-size:28px;"><?= $p['icon'] ?></div>
                <div>
                    <div style="font-weight:600;"><?= $p['name'] ?></div>
                    <?php if ($connected): ?>
                        <div class="text-sm" style="color:#10b981;">
                            ✓ Connected
                            <?php if ($integration['account_name']): ?>
                                — <?= e($integration['account_name']) ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-sm text-muted">Not connected</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($connected): ?>
                <div class="text-sm text-muted mb-2">
                    Connected: <?= format_date($integration['connected_at'], 'd M Y') ?>
                    <?php if ($integration['token_expires_at']): ?>
                        · Expires: <?= format_date($integration['token_expires_at'], 'd M Y') ?>
                    <?php endif; ?>
                </div>
                <div class="flex gap-2">
                    <?php if ($key !== 'google_search_console'): ?>
                        <button onclick="testPost('<?= $key ?>', <?= $site_id ?>)" class="btn btn-outline btn-sm">Test Post</button>
                    <?php else: ?>
                        <a href="<?= url('/dashboard/search-console.php?site=' . $site_id) ?>" class="btn btn-outline btn-sm">View Data →</a>
                    <?php endif; ?>
                    <form method="POST" action="<?= url('/api/oauth/disconnect.php') ?>" style="display:inline;">
                        <input type="hidden" name="site_id" value="<?= $site_id ?>">
                        <input type="hidden" name="platform" value="<?= $key ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger);" onclick="return confirm('Disconnect <?= $p['name'] ?>?')">Disconnect</button>
                    </form>
                </div>
            <?php elseif ($has_config): ?>
                <?php
                    // Load the auth function
                    if ($key === 'google_search_console') {
                        require_once __DIR__ . '/../../includes/integrations/google.php';
                        $auth_url = google_get_auth_url($site_id);
                    } elseif ($key === 'linkedin') {
                        require_once __DIR__ . '/../../includes/integrations/linkedin.php';
                        $auth_url = linkedin_get_auth_url($site_id);
                    } elseif ($key === 'twitter') {
                        require_once __DIR__ . '/../../includes/integrations/twitter.php';
                        $auth_url = twitter_get_auth_url($site_id);
                    } elseif ($key === 'facebook') {
                        require_once __DIR__ . '/../../includes/integrations/facebook.php';
                        $auth_url = facebook_get_auth_url($site_id);
                    }
                ?>
                <a href="<?= e($auth_url) ?>" class="btn btn-sm" style="background:<?= $p['color'] ?>;color:#fff;text-decoration:none;">Connect <?= $p['name'] ?> →</a>
            <?php else: ?>
                <div class="text-sm text-muted">
                    Add <code><?= $p['config_key'] ?></code> to config.php to enable.
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Instagram Carousel Generator -->
    <?php
    $post_id = (int)($_GET['post'] ?? 0);
    $carousel_action = $_GET['action'] ?? '';

    if ($post_id && ($carousel_action === 'carousel' || $carousel_action === 'share')):
        $stmt = $db->prepare('SELECT * FROM posts WHERE id = ? AND site_id = ?');
        $stmt->execute([$post_id, $site_id]);
        $share_post = $stmt->fetch();

        if ($share_post):
            $colors = json_decode($site['brand_colors'] ?? '[]', true) ?: [];
            $bg_color = $colors[0] ?? '#1B3A6B';
            $accent = $colors[1] ?? '#CC3300';

            // Generate carousel slides from post content
            $post_body = strip_tags($share_post['body']);
            $sentences = preg_split('/(?<=[.!?])\s+/', $post_body, -1, PREG_SPLIT_NO_EMPTY);

            // Build slides: title slide + 4-5 content slides + CTA slide
            $slides = [];
            // Slide 1: Title
            $slides[] = ['type' => 'title', 'text' => $share_post['title']];

            // Content slides: group sentences into chunks
            $chunk = '';
            $slide_count = 0;
            foreach ($sentences as $s) {
                $s = trim($s);
                if (empty($s)) continue;
                if (strlen($chunk . ' ' . $s) > 280 || $slide_count === 0) {
                    if (!empty($chunk)) {
                        $slides[] = ['type' => 'content', 'text' => trim($chunk)];
                        $slide_count++;
                    }
                    $chunk = $s;
                } else {
                    $chunk .= ' ' . $s;
                }
                if ($slide_count >= 5) break;
            }
            if (!empty($chunk) && $slide_count < 5) {
                $slides[] = ['type' => 'content', 'text' => trim($chunk)];
            }

            // Final slide: CTA
            $slides[] = ['type' => 'cta', 'text' => "Follow " . $site['name'] . " for more\n\n" . $site['domain']];
    ?>
    <div class="card" style="margin-bottom:14px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:600;">Instagram Carousel — <?= e(truncate($share_post['title'], 50)) ?></span>
            <span class="text-sm text-muted"><?= count($slides) ?> slides</span>
        </div>

        <div style="padding:14px;overflow-x:auto;">
            <div style="display:flex;gap:12px;min-width:max-content;">
                <?php foreach ($slides as $i => $slide): ?>
                <div style="width:300px;height:300px;flex-shrink:0;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center;position:relative;
                    <?php if ($slide['type'] === 'title'): ?>
                        background:<?= e($bg_color) ?>;color:#fff;
                    <?php elseif ($slide['type'] === 'cta'): ?>
                        background:<?= e($accent) ?>;color:#fff;
                    <?php else: ?>
                        background:#fff;border:2px solid <?= e($bg_color) ?>;color:<?= e($bg_color) ?>;
                    <?php endif; ?>
                ">
                    <div style="position:absolute;top:8px;left:12px;font-size:10px;opacity:0.5;"><?= $i + 1 ?>/<?= count($slides) ?></div>
                    <?php if ($slide['type'] === 'title'): ?>
                        <div style="font-size:20px;font-weight:700;line-height:1.3;"><?= e($slide['text']) ?></div>
                    <?php elseif ($slide['type'] === 'cta'): ?>
                        <div style="font-size:18px;font-weight:600;line-height:1.4;white-space:pre-line;"><?= e($slide['text']) ?></div>
                    <?php else: ?>
                        <div style="font-size:14px;line-height:1.6;"><?= e($slide['text']) ?></div>
                    <?php endif; ?>
                    <?php if ($slide['type'] !== 'cta'): ?>
                        <div style="position:absolute;bottom:10px;right:12px;font-size:9px;opacity:0.4;"><?= e($site['name']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="padding:10px 14px;background:#f8fafc;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <button onclick="downloadSlides()" class="btn btn-accent btn-sm">Download All Slides</button>
            <button onclick="copyCaption()" class="btn btn-outline btn-sm">Copy Caption</button>
            <span class="text-sm text-muted">Tip: Screenshot each slide or use the download button</span>
        </div>

        <!-- Caption for Instagram -->
        <div style="padding:10px 14px;">
            <div style="font-size:12px;font-weight:600;margin-bottom:4px;">Instagram Caption:</div>
            <textarea id="insta-caption" class="form-control" rows="4" style="font-size:12px;"><?= e($share_post['excerpt'] ?: truncate(strip_tags($share_post['body']), 200)) ?>

#<?= e(str_replace(' ', '', $site['name'])) ?> <?php
                $tags = json_decode($share_post['tags'] ?? '[]', true) ?: [];
                foreach (array_slice($tags, 0, 5) as $tag) {
                    echo '#' . e(str_replace([' ', ','], '', $tag)) . ' ';
                }
            ?></textarea>
        </div>
    </div>

    <script>
    function copyCaption() {
        const el = document.getElementById('insta-caption');
        navigator.clipboard.writeText(el.value);
        event.target.textContent = 'Copied!';
        setTimeout(() => event.target.textContent = 'Copy Caption', 2000);
    }

    function downloadSlides() {
        // Use html2canvas if available, otherwise prompt screenshot
        const slides = document.querySelectorAll('[style*="width:300px;height:300px"]');
        if (typeof html2canvas !== 'undefined') {
            slides.forEach((slide, i) => {
                html2canvas(slide).then(canvas => {
                    const link = document.createElement('a');
                    link.download = 'slide-' + (i+1) + '.png';
                    link.href = canvas.toDataURL();
                    link.click();
                });
            });
        } else {
            alert('Take a screenshot of each slide. Tip: Right-click → Save as Image, or use a browser extension like GoFullPage.');
        }
    }
    </script>
    <?php endif; endif; ?>

    <!-- Recent social posts -->
    <?php
    $stmt = $db->prepare('SELECT sp.*, p.title as post_title FROM social_posts sp JOIN posts p ON sp.post_id = p.id WHERE sp.site_id = ? ORDER BY sp.created_at DESC LIMIT 10');
    $stmt->execute([$site_id]);
    $social_posts = $stmt->fetchAll();
    ?>

    <?php if (!empty($social_posts)): ?>
    <div class="card">
        <div class="card-header">Recent Social Posts</div>
        <table>
            <thead>
                <tr><th>Post</th><th>Platform</th><th>Status</th><th>Date</th></tr>
            </thead>
            <tbody>
                <?php foreach ($social_posts as $sp): ?>
                <tr>
                    <td class="text-sm"><?= e(truncate($sp['post_title'], 40)) ?></td>
                    <td><span class="badge badge-info"><?= e($sp['platform']) ?></span></td>
                    <td><span class="badge badge-<?= $sp['status'] === 'posted' ? 'approved' : ($sp['status'] === 'failed' ? 'rejected' : 'draft') ?>"><?= $sp['status'] ?></span></td>
                    <td class="text-sm text-muted"><?= format_date($sp['created_at'], 'H:i, d M') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <script>
    async function testPost(platform, siteId) {
        const text = prompt('Enter test message for ' + platform + ':', 'Testing ContentAgent social posting! 🤖');
        if (!text) return;

        try {
            const res = await fetch('<?= url('/api/social-post.php') ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({post_id: 0, platforms: [platform], test_text: text, site_id: siteId})
            });
            const data = await res.json();
            alert(data.results && data.results[platform] && data.results[platform].success ? 'Posted successfully!' : 'Failed: ' + (data.results?.[platform]?.error || 'Unknown'));
        } catch(e) {
            alert('Error: ' + e.message);
        }
    }
    </script>

    <?php endif; ?>
<?php else: ?>
    <div class="card" style="text-align:center;padding:30px;">
        <div style="font-size:32px;margin-bottom:8px;">📱</div>
        <p class="text-muted">Select a site to manage social media connections.</p>
    </div>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
