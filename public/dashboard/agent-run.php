<?php
/**
 * Agent Run Page — Shows live progress for any agent.
 * URL: /dashboard/agent-run.php?agent=scanner&site=1
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$agent = $_GET['agent'] ?? '';
$site_id = (int)($_GET['site'] ?? 0);

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$site_id, $user_id]);
$site = $stmt->fetch();

if (!$site) {
    header('Location: ' . url('/dashboard/sites.php'));
    exit;
}

$agent_info = [
    'scanner'          => ['name' => '🔍 Website Scanner', 'desc' => 'Analyzing your website — detecting platform, brand, content structure'],
    'seo-auditor'      => ['name' => '📊 SEO Audit', 'desc' => 'Crawling pages and checking for SEO issues'],
    'auto-fixer'       => ['name' => '🤖 Auto-Fixer', 'desc' => 'Generating and deploying fixes for all SEO issues'],
    'keyword-research' => ['name' => '🔑 Keyword Research', 'desc' => 'Finding keywords your audience is searching for'],
    'content-planner'  => ['name' => '🧠 AI Content Planner', 'desc' => 'Analyzing keywords + trends to write and publish content'],
    'news-scraper'     => ['name' => '📰 News Scraper', 'desc' => 'Pulling relevant news from RSS feeds in your niche'],
    'evaluator'        => ['name' => '📈 Strategy Evaluator', 'desc' => 'Reviewing performance and recommending next steps'],
];

$info = $agent_info[$agent] ?? ['name' => ucfirst($agent), 'desc' => 'Running agent...'];

$page_title = $info['name'];

ob_start();
?>

<style>
    .agent-page { max-width: 800px; margin: 0 auto; }
    .agent-header { text-align: center; margin-bottom: 16px; }
    .agent-header h2 { font-size: 22px; font-weight: 700; color: var(--primary); }
    .agent-header p { font-size: 13px; color: #94a3b8; margin-top: 4px; }
    .agent-site { font-size: 13px; color: #666; margin-bottom: 16px; text-align: center; }
    .agent-site strong { color: var(--primary); }

    .progress-card {
        background: #fff; border: 1px solid var(--border); border-radius: 8px;
        padding: 18px; margin-bottom: 14px;
    }

    .progress-bar-wrap {
        height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden; margin-bottom: 8px;
    }
    .progress-bar-fill {
        height: 100%; width: 0%; background: var(--primary); border-radius: 4px; transition: width 0.3s;
    }

    .agent-log {
        font-family: monospace; font-size: 11px;
        background: #0f172a; color: #94a3b8;
        border-radius: 8px; padding: 14px;
        max-height: 350px; overflow-y: auto;
        margin-bottom: 14px;
    }
    .agent-log .line { padding: 2px 0; }
    .agent-log .success { color: #10b981; }
    .agent-log .info { color: #3b82f6; }
    .agent-log .warn { color: #f59e0b; }
    .agent-log .error { color: #ef4444; }
    .agent-log .dim { color: #475569; }
    .agent-log .highlight { color: #fff; font-weight: 600; }

    .result-card {
        background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px;
        padding: 18px; text-align: center; display: none;
    }
    .result-card .big { font-size: 48px; font-weight: 800; color: #10b981; }
    .result-card .sub { font-size: 14px; color: #666; margin-top: 4px; }

    .action-buttons { display: flex; gap: 8px; justify-content: center; margin-top: 16px; flex-wrap: wrap; }
</style>

<div class="agent-page">
    <div style="margin-bottom:10px;">
        <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site['name']) ?></a>
    </div>
    <div class="agent-header">
        <h2><?= e($info['name']) ?></h2>
        <p><?= e($info['desc']) ?></p>
    </div>
    <div class="agent-site">Running on <strong><?= e($site['name']) ?></strong> (<?= e($site['domain']) ?>)</div>

    <div class="progress-card">
        <div class="flex justify-between items-center mb-2">
            <span id="status-text" style="font-weight:600;font-size:13px;">Initializing...</span>
            <span id="progress-pct" class="text-sm text-muted">0%</span>
        </div>
        <div class="progress-bar-wrap">
            <div class="progress-bar-fill" id="progress-bar"></div>
        </div>
    </div>

    <div class="agent-log" id="agent-log"></div>

    <div class="result-card" id="result-card">
        <div class="big" id="result-value"></div>
        <div class="sub" id="result-text"></div>
    </div>

    <div class="action-buttons" id="action-buttons" style="display:none;">
        <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" class="btn btn-primary btn-sm">← Back to Site</a>
        <?php if ($agent === 'scanner'): ?>
            <a href="<?= url('/dashboard/agent-run.php?agent=seo-auditor&site=' . $site_id) ?>" class="btn btn-accent btn-sm">Next: Run SEO Audit →</a>
        <?php elseif ($agent === 'seo-auditor'): ?>
            <!-- View Issues button added dynamically via JS with audit_id -->
        <?php elseif ($agent === 'auto-fixer'): ?>
            <a href="<?= url('/dashboard/agent-run.php?agent=keyword-research&site=' . $site_id) ?>" class="btn btn-accent btn-sm">Next: Find Keywords →</a>
        <?php elseif ($agent === 'keyword-research'): ?>
            <a href="<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>" class="btn btn-accent btn-sm">Next: AI Content Planner →</a>
        <?php endif; ?>
    </div>
</div>

<script>
const API = '<?= url('/api') ?>';
const siteId = <?= $site_id ?>;
const agent = '<?= e($agent) ?>';

function log(text, cls = '') {
    const el = document.getElementById('agent-log');
    const prefix = cls === 'success' ? '✓ ' : cls === 'info' ? '→ ' : cls === 'warn' ? '⚠ ' : cls === 'error' ? '✗ ' : '  ';
    el.innerHTML += '<div class="line ' + cls + '">' + prefix + text + '</div>';
    el.scrollTop = el.scrollHeight;
}

function setProgress(pct, text) {
    document.getElementById('progress-bar').style.width = pct + '%';
    document.getElementById('progress-pct').textContent = pct + '%';
    if (text) document.getElementById('status-text').textContent = text;
}

function showResult(value, text) {
    const card = document.getElementById('result-card');
    card.style.display = 'block';
    document.getElementById('result-value').textContent = value;
    document.getElementById('result-text').textContent = text;
    document.getElementById('action-buttons').style.display = 'flex';
}

async function run() {
    setProgress(5, 'Starting ' + agent + '...');
    log('Connecting to ' + agent + ' agent...', 'info');

    <?php if ($agent === 'scanner'): ?>
        // Scanner
        setProgress(10, 'Fetching website...');
        log('Scanning ' + '<?= e($site['domain']) ?>' + '...', 'info');

        const res = await fetch(API + '/onboarding.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'scan', site_id: siteId})
        });
        const data = await res.json();

        if (data.success) {
            setProgress(100, 'Scan complete!');
            log('Platform: ' + (data.platform || 'custom'), 'success');
            log('Title: ' + (data.title || 'N/A'), data.title ? 'success' : 'warn');

            if (data.internal_links > 10) {
                log('Pages found: ' + data.internal_links + ' — Good site structure', 'success');
            } else if (data.internal_links > 0) {
                log('Pages found: ' + data.internal_links + ' — Small site. More pages = more SEO opportunities', 'warn');
            } else {
                log('This is a single-page website — only 1 page found, no internal links. SEO needs multiple pages with content to rank.', 'error');
            }

            if (data.images > 0) {
                log('Images: ' + data.images + ' found', 'info');
            } else {
                log('Images: 0 — Add images to improve engagement', 'warn');
            }

            log('SSL: ' + (data.ssl_valid ? 'Valid — Secure connection' : 'Invalid — Not secure! Get an SSL certificate'), data.ssl_valid ? 'success' : 'error');
            log('Sitemap: ' + (data.sitemap ? 'Found — Search engines can discover your pages' : 'Missing — Search engines can\'t find all your pages'), data.sitemap ? 'success' : 'error');
            log('Blog: ' + (data.blog_path ? data.blog_path + ' — Content hub found' : 'Not found — No blog means no fresh content for SEO'), data.blog_path ? 'success' : 'warn');

            // Summary feedback
            let issues = 0;
            if (!data.ssl_valid) issues++;
            if (!data.sitemap) issues++;
            if (data.internal_links === 0) issues++;
            if (!data.blog_path) issues++;

            if (issues === 0) {
                showResult('✓', 'Site looks great! Ready for SEO audit.');
            } else {
                showResult(issues + ' issue' + (issues > 1 ? 's' : ''), 'Found ' + issues + ' thing' + (issues > 1 ? 's' : '') + ' to fix. Run SEO Audit for full analysis.');
            }
        } else {
            setProgress(100, 'Scan failed');
            log(data.error || 'Unknown error', 'error');
            showResult('✗', 'Scan failed: ' + (data.error || 'Unknown'));
        }

    <?php elseif ($agent === 'seo-auditor'): ?>
        // SEO Audit
        setProgress(10, 'Crawling pages...');
        log('Starting SEO audit (up to 30 pages)...', 'info');

        const res = await fetch(API + '/onboarding.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'audit', site_id: siteId})
        });
        const data = await res.json();

        if (data.success) {
            setProgress(100, 'Audit complete!');
            log('Crawled ' + data.pages + ' pages in ' + data.duration + 's', 'success');
            log('Score: ' + data.score + '/100', 'highlight');
            log('Issues found: ' + data.issues + ' (' + data.critical + ' critical, ' + data.warnings + ' warnings)', data.issues > 0 ? 'warn' : 'success');
            showResult(data.score + '/100', data.issues + ' issues found across ' + data.pages + ' pages');

            if (data.audit_id) {
                document.getElementById('action-buttons').innerHTML += '<a href="<?= url('/dashboard/seo-audit.php?audit=') ?>' + data.audit_id + '" class="btn btn-accent btn-sm">View Issues & Fix →</a>';
            }
        } else {
            setProgress(100, 'Audit failed');
            log(data.error, 'error');
        }

    <?php elseif ($agent === 'auto-fixer'): ?>
        // Auto-fixer with batches
        setProgress(10, 'Starting auto-fixer...');
        log('Analyzing issues...', 'info');

        let offset = 0, totalFixed = 0, totalSkipped = 0, totalIssues = 0, hasMore = true;

        while (hasMore) {
            const res = await fetch(API + '/auto-fix-all.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({site_id: siteId, batch_size: 10, offset: offset})
            });
            const data = await res.json();
            if (!data.success) { log(data.error || 'Batch error', 'error'); break; }

            totalFixed += data.fixed;
            totalSkipped += data.skipped;
            totalIssues = data.total_issues;
            hasMore = data.has_more;
            offset = data.next_offset || offset + 10;

            const pct = totalIssues > 0 ? Math.round((Math.min(offset, totalIssues) / totalIssues) * 100) : 100;
            setProgress(pct, 'Fixing: ' + totalFixed + ' done, ' + totalSkipped + ' skipped');

            if (data.applied) data.applied.forEach(a => log(a, 'success'));
            if (data.deployed) data.deployed.forEach(d => log('Deployed: ' + d, 'info'));
        }

        setProgress(100, 'Auto-fix complete!');
        log('Done! ' + totalFixed + ' fixed, ' + totalSkipped + ' skipped', 'success');
        showResult(totalFixed, 'fixes generated & ready to deploy (out of ' + totalIssues + ' issues)');

        // Show snippet info
        const snippet = document.createElement('div');
        snippet.style.cssText = 'margin-top:14px;padding:14px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;text-align:left;font-size:13px;max-width:600px;margin-left:auto;margin-right:auto;';
        snippet.innerHTML = '<strong>To apply fixes to your live site:</strong> Add this snippet to your website\'s &lt;head&gt;:<br><code style="font-size:11px;background:#1a1a2e;color:#10b981;padding:4px 8px;border-radius:3px;display:block;margin-top:6px;word-break:break-all;">&lt;script src="' + window.location.origin + '<?= url("/snippet/contentagent.js") ?>" data-site="<?= e($site["domain"]) ?>"&gt;&lt;/script&gt;</code><br>Or add FTP/DB credentials in Sites → Edit for direct deployment.';
        document.getElementById('result-card').appendChild(snippet);

    <?php elseif ($agent === 'keyword-research'): ?>
        // Keywords
        setProgress(10, 'Researching keywords...');
        log('Scraping Google Autocomplete...', 'info');

        const res = await fetch(API + '/onboarding.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'keywords', site_id: siteId})
        });
        const data = await res.json();

        if (data.success) {
            setProgress(100, 'Keywords found!');
            log('Found ' + data.total + ' keywords in ' + data.clusters + ' clusters', 'success');
            if (data.samples) data.samples.forEach(k => log('  → ' + k, 'dim'));
            showResult(data.total, 'keywords discovered');
            document.getElementById('action-buttons').innerHTML += '<a href="<?= url('/dashboard/keywords.php?site=' . $site_id) ?>" class="btn btn-primary btn-sm">View Keywords →</a>';
        } else {
            setProgress(100, 'Failed');
            log(data.error, 'error');
        }

    <?php elseif ($agent === 'content-planner'): ?>
        // Content Planner — redirect to AI Writer
        window.location.href = '<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>';

    <?php elseif ($agent === 'news-scraper'): ?>
        setProgress(10, 'Scraping news feeds...');
        log('Fetching RSS feeds...', 'info');

        const res = await fetch(API + '/run-agent.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({agent: 'news-scraper', site_id: siteId})
        });
        const data = await res.json();

        if (data.success) {
            setProgress(50, 'Agent started...');
            log('News scraper started in background', 'success');
            log('Check logs: ' + data.log, 'dim');

            // Wait and check for new posts
            await new Promise(r => setTimeout(r, 8000));
            setProgress(100, 'Done!');
            log('News scraping complete. Check Posts page for new items.', 'success');
            showResult('✓', 'News scraper complete');
            document.getElementById('action-buttons').innerHTML += '<a href="<?= url('/dashboard/posts.php?site=' . $site_id . '&type=news') ?>" class="btn btn-primary btn-sm">View News Posts →</a>';
        }

    <?php elseif ($agent === 'evaluator'): ?>
        setProgress(10, 'Analyzing performance...');
        log('Gathering data: posts, keywords, SEO scores, activity...', 'info');

        const res = await fetch(API + '/onboarding.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'content_plan', site_id: siteId})
        });
        const data = await res.json();

        setProgress(100, 'Analysis complete!');
        if (data.success && data.topics) {
            log('AI Strategy Recommendations:', 'highlight');
            log('', '');
            data.topics.forEach((t, i) => {
                log((i+1) + '. ' + (t.title || t), 'success');
                if (t.description) log('   ' + t.description, 'dim');
            });
            showResult(data.topics.length, 'strategic recommendations');
            document.getElementById('action-buttons').innerHTML += '<a href="<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>" class="btn btn-accent btn-sm">Write Content →</a>';
        }

    <?php else: ?>
        log('Unknown agent: <?= e($agent) ?>', 'error');
        setProgress(100, 'Error');
        showResult('?', 'Unknown agent');
    <?php endif; ?>
}

// Start immediately
run().catch(e => {
    log('Fatal error: ' + e.message, 'error');
    setProgress(100, 'Error');
});
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
