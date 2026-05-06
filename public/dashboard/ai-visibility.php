<?php
/**
 * AI Visibility Monitor — Are you being found in AI conversations?
 *
 * Asks AI questions about the brand's industry and checks if the brand
 * appears in the responses. Simulates what ChatGPT/Perplexity/Claude
 * would say when users ask about the brand's space.
 *
 * GET ?site=3
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai-visibility.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$site_id, $user_id]);
$site = $stmt->fetch();
if (!$site) { redirect('/dashboard/index.php'); }

$run = isset($_GET['run']);
$results = null;

if ($run) {
    $results = check_ai_visibility($site, $db);

    // Save results to agent_log
    $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
        $site_id,
        'ai_visibility_check',
        json_encode(['score' => $results['score'], 'mentioned' => $results['mentioned'], 'total' => $results['total']]),
        $results['score'] > 0 ? 'success' : 'warning',
    ]);
}

$page_title = 'AI Visibility — ' . $site['name'];
ob_start();
?>

<style>
.vis-card { background:#fff; border:1px solid var(--border); border-radius:8px; margin-bottom:10px; overflow:hidden; }
.vis-header { padding:12px 16px; border-bottom:1px solid var(--border); }
.vis-body { padding:14px 16px; }
.vis-query { font-weight:600; font-size:14px; color:var(--primary); margin-bottom:6px; }
.vis-type { font-size:10px; text-transform:uppercase; color:#94a3b8; margin-bottom:4px; }
.vis-response { font-size:13px; color:#374151; line-height:1.6; background:#f8fafc; padding:10px 14px; border-radius:6px; max-height:200px; overflow-y:auto; }
.vis-badge { display:inline-block; padding:3px 12px; border-radius:12px; font-size:12px; font-weight:600; }
.vis-found { background:#d1fae5; color:#065f46; }
.vis-not-found { background:#fecaca; color:#991b1b; }
.vis-score-box { text-align:center; padding:20px; background:#fff; border:1px solid var(--border); border-radius:8px; margin-bottom:14px; }
.vis-score-box .big { font-size:48px; font-weight:800; }
.vis-score-box .sub { font-size:14px; color:#64748b; margin-top:4px; }
.highlight-brand { background:#fef3c7; padding:1px 4px; border-radius:3px; font-weight:600; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site['name']) ?></a>
</div>

<div style="text-align:center;margin-bottom:14px;">
    <h2 style="font-size:20px;color:var(--primary);margin-bottom:4px;">AI Visibility Monitor</h2>
    <p style="font-size:13px;color:#64748b;">Is <?= e($site['name']) ?> being mentioned when people ask AI about your industry?</p>
</div>

<?php if (!$run): ?>
    <div style="text-align:center;padding:30px 20px;">
        <p style="font-size:15px;color:#374151;margin-bottom:6px;">We'll ask AI the same questions your customers ask — and check if your brand appears in the answers.</p>
        <p style="font-size:13px;color:#94a3b8;margin-bottom:16px;">This simulates what happens when someone asks ChatGPT, Perplexity, or Claude about your industry.</p>
        <a href="<?= url('/dashboard/ai-visibility.php?site=' . $site_id . '&run=1') ?>" class="btn btn-accent" style="padding:12px 32px;font-size:15px;text-decoration:none;">Check AI Visibility Now</a>
    </div>
<?php else: ?>
    <!-- Score -->
    <div class="vis-score-box">
        <div class="big <?= $results['score'] >= 60 ? 'score-good' : ($results['score'] >= 30 ? 'score-ok' : 'score-bad') ?>" style="color:<?= $results['score'] >= 60 ? '#10b981' : ($results['score'] >= 30 ? '#f59e0b' : '#ef4444') ?>;">
            <?= $results['score'] ?>%
        </div>
        <div class="sub">
            AI Visibility Score — Your brand was mentioned in <?= $results['mentioned'] ?> out of <?= $results['total'] ?> AI queries
        </div>
        <div style="margin-top:10px;font-size:13px;color:#64748b;">
            <?php if ($results['score'] >= 60): ?>
                Great! AI models know about your brand. Keep publishing quality content to maintain visibility.
            <?php elseif ($results['score'] >= 30): ?>
                AI has some awareness of your brand, but you're not the first recommendation. More content and external mentions needed.
            <?php else: ?>
                AI models don't know much about your brand yet. You need more online presence — blog posts, mentions on authority sites, and PR coverage.
            <?php endif; ?>
        </div>
    </div>

    <!-- Results -->
    <?php foreach ($results['results'] as $r): ?>
    <div class="vis-card">
        <div class="vis-header" style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div class="vis-type"><?= e(ucfirst($r['type'])) ?> query</div>
                <div class="vis-query">"<?= e($r['query']) ?>"</div>
            </div>
            <span class="vis-badge <?= $r['mentioned'] ? 'vis-found' : 'vis-not-found' ?>">
                <?= $r['mentioned'] ? 'Mentioned' : 'Not Found' ?>
            </span>
        </div>
        <div class="vis-body">
            <div class="vis-response">
                <?php
                $text = e($r['response']);
                // Highlight brand name and domain in response
                $text = preg_replace('/(' . preg_quote(e($site['name']), '/') . ')/i', '<span class="highlight-brand">$1</span>', $text);
                $text = preg_replace('/(' . preg_quote(e($site['domain']), '/') . ')/i', '<span class="highlight-brand">$1</span>', $text);
                echo nl2br($text);
                ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Tips -->
    <div class="vis-card">
        <div class="vis-header">
            <div class="vis-query">How to Improve Your AI Visibility</div>
        </div>
        <div class="vis-body" style="font-size:13px;color:#374151;line-height:1.8;">
            <ol style="padding-left:18px;">
                <li><strong>Publish expert content regularly</strong> — AI models learn from the web. More quality articles = more AI awareness of your brand.</li>
                <li><strong>Get mentioned on authority sites</strong> — Reddit, Quora, industry forums, guest posts. AI models heavily cite Reddit and Wikipedia.</li>
                <li><strong>Use structured data (schema)</strong> — Help AI understand your business type, services, and expertise.</li>
                <li><strong>Keep llms.txt updated</strong> — This file directly tells AI models what your site is about.</li>
                <li><strong>Answer questions your customers ask</strong> — Create FAQ pages and how-to guides. AI loves citing direct answers.</li>
                <li><strong>Be the primary source</strong> — Original research, case studies, and real data get cited more than generic advice.</li>
            </ol>
        </div>
    </div>

    <div style="text-align:center;margin-top:10px;">
        <a href="<?= url('/dashboard/ai-visibility.php?site=' . $site_id . '&run=1') ?>" class="btn btn-outline btn-sm">Re-run Check</a>
        <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;margin-left:6px;">Back to Site</a>
    </div>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
