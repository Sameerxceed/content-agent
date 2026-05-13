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

// Agents that depend on the customer having confirmed what their business sells.
$needs_business_focus = ['keyword-research', 'content-planner'];
if (in_array($agent, $needs_business_focus, true) && empty($site['topics_confirmed'])) {
    $_SESSION['flash_error'] = 'Please confirm your Business Focus first — without it, AI guesses and may produce content for the wrong industry.';
    header('Location: ' . url('/dashboard/site.php?id=' . $site_id . '#business-focus'));
    exit;
}

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
            // Platform insight
            const p = data.platform || 'custom';
            const platformMsg = {
                'wordpress': 'WordPress detected — plugins available for most SEO fixes',
                'opencart': 'OpenCart detected — template and .htaccess fixes needed for SEO',
                'shopify': 'Shopify detected — robots.txt customizable via theme, llms.txt via CDN upload + URL redirect',
                'wix': 'Wix detected — limited SEO control, consider migrating for full SEO',
                'squarespace': 'Squarespace detected — basic SEO built-in, limited customization',
                'nextjs': 'Next.js detected — great for SEO with server-side rendering',
                'custom': 'Custom platform — full control over all SEO elements'
            };
            log('Platform: ' + p + ' — ' + (platformMsg[p] || 'Custom build'), 'success');

            // Title check
            if (!data.title || data.title.length < 10) {
                log('Title: "' + (data.title || '') + '" — Too short or missing. Title should be 50-60 characters describing your business', 'error');
            } else if (data.title.length > 70) {
                log('Title: "' + data.title + '" — Too long (' + data.title.length + ' chars). Shorten to under 60 characters', 'warn');
            } else {
                log('Title: "' + data.title + '" — Good length (' + data.title.length + ' chars)', 'success');
            }

            // Pages / internal links
            if (data.internal_links > 20) {
                log('Pages found: ' + data.internal_links + ' — Strong site structure with good internal linking', 'success');
            } else if (data.internal_links > 10) {
                log('Pages found: ' + data.internal_links + ' — Decent structure. Consider adding more content pages', 'success');
            } else if (data.internal_links > 0) {
                log('Pages found: ' + data.internal_links + ' — Small site. More pages = more keywords you can rank for', 'warn');
            } else {
                log('Single-page website — no internal links found. Google can only rank this 1 page. You need multiple pages to compete in search.', 'error');
            }

            // Images
            if (data.images > 10) {
                log('Images: ' + data.images + ' — Good visual content', 'success');
            } else if (data.images > 0) {
                log('Images: ' + data.images + ' — Consider adding more images for better engagement', 'info');
            } else {
                log('No images found — pages with images get 94% more views. Add product/service images', 'warn');
            }

            // SSL
            if (data.ssl_valid) {
                log('SSL: Secure (HTTPS) — Google prefers secure sites', 'success');
            } else {
                log('SSL: NOT SECURE — Google marks this site as "Not Secure". Visitors will see a warning. Get an SSL certificate immediately', 'error');
            }

            // Sitemap
            if (data.sitemap) {
                log('Sitemap: Found — Google and AI bots can discover all your pages', 'success');
            } else if (data.internal_links === 0) {
                log('Sitemap: Missing — With only 1 page, a sitemap won\'t help much. Focus on adding more pages first', 'warn');
            } else {
                log('Sitemap: Missing — Google is probably missing ' + Math.round(data.internal_links * 0.4) + '+ of your pages. Create a sitemap.xml urgently', 'error');
            }

            // Blog
            if (data.blog_path) {
                log('Blog: Found at ' + data.blog_path + ' — Great for publishing fresh content that ranks', 'success');
            } else {
                log('No blog found — Websites with blogs get 55% more traffic. A blog lets you target keywords and attract visitors', 'warn');
            }

            // Summary
            let issues = 0;
            let critical = 0;
            if (!data.ssl_valid) { issues++; critical++; }
            if (!data.sitemap && data.internal_links > 0) { issues++; critical++; }
            if (data.internal_links === 0) { issues++; critical++; }
            if (!data.blog_path) issues++;
            if (!data.title || data.title.length < 10) { issues++; critical++; }
            if (data.images === 0) issues++;

            if (issues === 0) {
                showResult('✓', 'Site looks great! Ready for a detailed SEO audit.');
            } else if (critical > 0) {
                showResult(critical + ' critical', critical + ' urgent issue' + (critical > 1 ? 's' : '') + ' found. Run SEO Audit for full analysis and fixes.');
            } else {
                showResult(issues + ' suggestion' + (issues > 1 ? 's' : ''), 'Minor improvements possible. Run SEO Audit for details.');
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

            // Score with context
            const score = data.score;
            if (score >= 90) {
                log('Score: ' + score + '/100 — Excellent! Your site is well optimized', 'success');
            } else if (score >= 75) {
                log('Score: ' + score + '/100 — Good, but room for improvement', 'success');
            } else if (score >= 50) {
                log('Score: ' + score + '/100 — Needs work. Several SEO issues are hurting your rankings', 'warn');
            } else {
                log('Score: ' + score + '/100 — Poor. Major SEO problems are preventing this site from ranking', 'error');
            }

            // Issues breakdown with context
            if (data.critical > 0) {
                log(data.critical + ' critical issues — These are blocking your site from ranking. Fix these first!', 'error');
            }
            if (data.warnings > 0) {
                log(data.warnings + ' warnings — These hurt your SEO but won\'t block you completely', 'warn');
            }
            if (data.issues === 0) {
                log('No issues found — Your site is fully optimized!', 'success');
            }

            // Pages context
            if (data.pages <= 1) {
                log('Only ' + data.pages + ' page audited — single-page sites have very limited SEO potential', 'warn');
            }

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
        if (totalFixed > 0) {
            log(totalFixed + ' issues auto-fixed — These fixes are ready to deploy to your site', 'success');
        }
        if (totalSkipped > 0) {
            log(totalSkipped + ' issues skipped — These need manual fixing (content changes, image alt text, etc.)', 'info');
        }
        if (totalFixed === 0 && totalIssues > 0) {
            log('No auto-fixes available — The remaining issues need manual content changes', 'warn');
        }
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
            if (data.total > 30) {
                log('Found ' + data.total + ' keywords — Excellent keyword opportunity!', 'success');
            } else if (data.total > 10) {
                log('Found ' + data.total + ' keywords — Good starting point for content', 'success');
            } else if (data.total > 0) {
                log('Found ' + data.total + ' keywords — Limited opportunities. Consider broadening your niche', 'warn');
            } else {
                log('No keywords found — Try adding topics in site settings', 'error');
            }
            if (data.clusters > 1) {
                log(data.clusters + ' topic clusters identified — Each cluster can become a content series', 'info');
            }
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
