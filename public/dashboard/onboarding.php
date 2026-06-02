<?php
/**
 * Onboarding Wizard — Guided setup for new websites.
 * Shows AI agents working in real-time, step by step.
 *
 * Flow: Enter URL → Scan → Audit → Fix → Content Plan → First Post → Done
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$page_title = '🚀 New Site Setup';

// Get sites for the dropdown (if coming back)
$stmt = $db->prepare('SELECT id, name, domain FROM sites WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
$stmt->execute([$user_id]);
$latest_site = $stmt->fetch();

ob_start();
?>

<style>
    .wizard { max-width: 800px; margin: 0 auto; }

    .step {
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        margin-bottom: 12px;
        overflow: hidden;
        transition: all 0.3s;
    }

    .step.active { border-color: var(--primary); box-shadow: 0 2px 12px rgba(27,58,107,0.1); }
    .step.completed { border-color: #10b981; }
    .step.locked { opacity: 0.5; }

    .step-header {
        padding: 14px 18px;
        display: flex;
        align-items: center;
        gap: 12px;
        cursor: pointer;
    }

    .step-number {
        width: 32px; height: 32px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 14px;
        background: #f1f5f9; color: #94a3b8;
        flex-shrink: 0;
    }

    .step.active .step-number { background: var(--primary); color: #fff; }
    .step.completed .step-number { background: #10b981; color: #fff; }

    .step-title { font-weight: 600; font-size: 14px; }
    .step-subtitle { font-size: 12px; color: #94a3b8; }

    .step-body {
        padding: 0 18px 18px;
        display: none;
    }

    .step.active .step-body { display: block; }

    .step-result {
        background: #f8fafb;
        border-radius: 6px;
        padding: 12px 14px;
        margin-top: 10px;
        font-size: 13px;
    }

    .ai-log {
        font-family: monospace;
        font-size: 11px;
        color: #666;
        background: #1a1a2e;
        color: #10b981;
        border-radius: 6px;
        padding: 12px 14px;
        max-height: 250px;
        overflow-y: auto;
        margin-top: 10px;
    }

    .ai-log .line { padding: 2px 0; }
    .ai-log .line.info { color: #3b82f6; }
    .ai-log .line.success { color: #10b981; }
    .ai-log .line.warn { color: #f59e0b; }
    .ai-log .line.error { color: #ef4444; }
    .ai-log .line.dim { color: #555; }

    .metric-row {
        display: flex; gap: 12px; margin-top: 10px; flex-wrap: wrap;
    }

    .metric {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        padding: 10px 14px;
        text-align: center;
        flex: 1;
        min-width: 100px;
    }

    .metric .val { font-size: 22px; font-weight: 700; color: var(--primary); }
    .metric .label { font-size: 10px; color: #94a3b8; text-transform: uppercase; margin-top: 2px; }

    .score-reveal {
        text-align: center;
        padding: 20px;
    }

    .score-reveal .big-score {
        font-size: 72px;
        font-weight: 800;
        color: var(--primary);
        line-height: 1;
    }

    .next-btn {
        margin-top: 14px;
        padding: 10px 24px;
        background: var(--accent);
        color: #fff;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
    }

    .next-btn:hover { background: #a82a00; }
    .next-btn:disabled { background: #ccc; cursor: not-allowed; }
</style>

<div class="wizard">
    <div style="text-align:center;margin-bottom:14px;">
        <div style="font-size:20px;font-weight:700;color:var(--primary);">Let's get your website ready</div>
        <div style="font-size:13px;color:#64748b;margin-top:4px;">We'll scan, audit, fix what's broken, and plan your first content — watch it happen in real time.</div>
    </div>

    <!-- Step 1: Enter URL -->
    <div class="step active" id="step-1">
        <div class="step-header">
            <div class="step-number">1</div>
            <div>
                <div class="step-title">Enter your website URL</div>
                <div class="step-subtitle">We'll analyze everything about your site</div>
            </div>
        </div>
        <div class="step-body">
            <div class="form-group">
                <input type="text" id="site-url" class="form-control" placeholder="example.com" style="font-size:16px;padding:12px;" autofocus>
            </div>
            <div class="form-group">
                <input type="text" id="site-name" class="form-control" placeholder="Site name (optional)">
            </div>
            <button class="next-btn" onclick="startOnboarding()">🚀 Start AI Analysis</button>
        </div>
    </div>

    <!-- Step 2: Scanning -->
    <div class="step locked" id="step-2">
        <div class="step-header">
            <div class="step-number">2</div>
            <div>
                <div class="step-title">Scanning your website</div>
                <div class="step-subtitle">Detecting platform, brand, content structure</div>
            </div>
        </div>
        <div class="step-body">
            <div class="ai-log" id="scan-log"></div>
            <div class="metric-row" id="scan-metrics" style="display:none;"></div>
            <button class="next-btn" id="scan-next" style="display:none;" onclick="startAudit()">Continue to SEO Audit →</button>
        </div>
    </div>

    <!-- Step 3: SEO Audit -->
    <div class="step locked" id="step-3">
        <div class="step-header">
            <div class="step-number">3</div>
            <div>
                <div class="step-title">📊 Running SEO Audit</div>
                <div class="step-subtitle">Checking every page for SEO issues</div>
            </div>
        </div>
        <div class="step-body">
            <div class="ai-log" id="audit-log"></div>
            <div class="score-reveal" id="audit-score" style="display:none;">
                <div style="font-size:13px;color:#94a3b8;text-transform:uppercase;letter-spacing:2px;">Your SEO Score</div>
                <div class="big-score" id="score-value">0</div>
                <div style="font-size:14px;color:#666;margin-top:4px;" id="score-detail"></div>
            </div>
            <button class="next-btn" id="audit-next" style="display:none;" onclick="startFix()">Fix all issues automatically →</button>
        </div>
    </div>

    <!-- Step 4: Auto-Fix -->
    <div class="step locked" id="step-4">
        <div class="step-header">
            <div class="step-number">4</div>
            <div>
                <div class="step-title">Fixing SEO issues</div>
                <div class="step-subtitle">Generating fixes and deploying to your site</div>
            </div>
        </div>
        <div class="step-body">
            <div style="margin-bottom:8px;">
                <div style="height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
                    <div id="fix-progress-bar" style="height:100%;width:0%;background:#10b981;border-radius:4px;transition:width 0.3s;"></div>
                </div>
                <div class="flex justify-between mt-2" style="font-size:11px;color:#94a3b8;">
                    <span id="fix-counter">0 / 0</span>
                    <span id="fix-status">Starting...</span>
                </div>
            </div>
            <div class="ai-log" id="fix-log"></div>
            <div class="score-reveal" id="fix-result" style="display:none;">
                <div style="font-size:13px;color:#3b82f6;text-transform:uppercase;letter-spacing:2px;">Fixes Ready to Deploy</div>
                <div class="big-score" style="color:#10b981;" id="fixed-count">0</div>
                <div class="sub" style="font-size:14px;color:#666;">fixes generated & ready to deploy</div>
            </div>
            <button class="next-btn" id="fix-next" style="display:none;" onclick="startKeywords()">🔑 Find Keywords →</button>
        </div>
    </div>

    <!-- Step 5: Keywords -->
    <div class="step locked" id="step-5">
        <div class="step-header">
            <div class="step-number">5</div>
            <div>
                <div class="step-title">🔑 AI is researching keywords</div>
                <div class="step-subtitle">Finding what your audience is searching for</div>
            </div>
        </div>
        <div class="step-body">
            <div class="ai-log" id="kw-log"></div>
            <div class="metric-row" id="kw-metrics" style="display:none;"></div>
            <button class="next-btn" id="kw-next" style="display:none;" onclick="startContentPlan()">🧠 Create Content Plan →</button>
        </div>
    </div>

    <!-- Step 6: Content Plan -->
    <div class="step locked" id="step-6">
        <div class="step-header">
            <div class="step-number">6</div>
            <div>
                <div class="step-title">🧠 AI Content Strategy</div>
                <div class="step-subtitle">Proposing blog topics based on keywords + trends</div>
            </div>
        </div>
        <div class="step-body">
            <div class="ai-log" id="plan-log"></div>
            <div id="plan-topics" style="display:none;"></div>
            <button class="next-btn" id="plan-next" style="display:none;" onclick="finishSetup()">✅ Go to Dashboard →</button>
        </div>
    </div>

    <!-- Step 7: Done -->
    <div class="step locked" id="step-7">
        <div class="step-header">
            <div class="step-number">🎉</div>
            <div>
                <div class="step-title">Setup Complete!</div>
                <div class="step-subtitle">Your AI content agent is ready</div>
            </div>
        </div>
        <div class="step-body">
            <div style="text-align:center;padding:20px;">
                <div style="font-size:48px;margin-bottom:10px;">🎉</div>
                <div style="font-size:18px;font-weight:600;color:var(--primary);">Your site is ready!</div>
                <div class="text-sm text-muted" style="margin-top:4px;">The AI agent will continue working — writing content, monitoring SEO, and improving your rankings.</div>
                <div class="metric-row" id="final-metrics" style="justify-content:center;margin-top:16px;"></div>
                <a href="<?= url('/dashboard/sites.php') ?>" class="next-btn" style="display:inline-block;margin-top:16px;text-decoration:none;">Go to Dashboard →</a>
            </div>
        </div>
    </div>
</div>

<script>
const API_BASE = '<?= url('/api') ?>';
let siteId = null;
let siteDomain = '';

function log(containerId, text, type = '') {
    const el = document.getElementById(containerId);
    const div = document.createElement('div');
    div.className = 'line ' + type;
    div.textContent = (type === 'success' ? '✓ ' : type === 'info' ? '→ ' : type === 'warn' ? '⚠ ' : type === 'error' ? '✗ ' : '  ') + text;
    el.appendChild(div);
    el.scrollTop = el.scrollHeight;
}

function activateStep(num) {
    document.querySelectorAll('.step').forEach(s => {
        s.classList.remove('active');
        s.classList.add('locked');
    });
    for (let i = 1; i < num; i++) {
        document.getElementById('step-' + i).classList.remove('locked');
        document.getElementById('step-' + i).classList.add('completed');
    }
    const step = document.getElementById('step-' + num);
    step.classList.remove('locked');
    step.classList.add('active');
    step.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

async function startOnboarding() {
    const url = document.getElementById('site-url').value.trim();
    const name = document.getElementById('site-name').value.trim();
    if (!url) { alert('Please enter a URL'); return; }

    siteDomain = url.replace(/^https?:\/\//, '').replace(/^www\./, '').replace(/\/+$/, '');

    // Create site
    activateStep(2);
    log('scan-log', 'Creating site record for ' + siteDomain + '...', 'info');

    try {
        // Add site via form post
        const formData = new FormData();
        formData.append('action', 'add_api');
        formData.append('url', url);
        formData.append('name', name || siteDomain);

        const res = await fetch(API_BASE + '/onboarding.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'create_site', url: url, name: name || siteDomain})
        });
        const data = await res.json();

        if (data.error) { log('scan-log', data.error, 'error'); return; }
        siteId = data.site_id;
        log('scan-log', 'Site created (ID: #' + siteId + ')', 'success');

        // Start scanning
        await runScan();
    } catch(e) {
        log('scan-log', 'Error: ' + e.message, 'error');
    }
}

async function runScan() {
    log('scan-log', 'Starting AI website scanner...', 'info');
    log('scan-log', 'Fetching homepage...', 'dim');

    const res = await fetch(API_BASE + '/onboarding.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'scan', site_id: siteId})
    });
    const data = await res.json();

    if (data.success) {
        log('scan-log', 'Homepage loaded (' + (data.status || 200) + ')', 'success');
        log('scan-log', 'Platform: ' + (data.platform || 'custom'), 'success');
        log('scan-log', 'Title: ' + (data.title || 'N/A'), 'success');
        log('scan-log', 'Internal links: ' + (data.internal_links || 0), 'info');
        log('scan-log', 'Images: ' + (data.images || 0), 'info');
        log('scan-log', 'Blog found: ' + (data.blog_path || 'No'), 'info');
        log('scan-log', 'SSL: ' + (data.ssl_valid ? 'Valid' : 'Invalid'), data.ssl_valid ? 'success' : 'warn');
        log('scan-log', 'Sitemap: ' + (data.sitemap ? 'Found' : 'Missing'), data.sitemap ? 'success' : 'warn');

        if (data.colors && data.colors.length > 0) {
            log('scan-log', 'Brand colors: ' + data.colors.join(', '), 'info');
        }
        if (data.social && Object.keys(data.social).length > 0) {
            log('scan-log', 'Social: ' + Object.keys(data.social).join(', '), 'info');
        }

        log('scan-log', '', '');
        log('scan-log', 'Scan complete!', 'success');

        // Show metrics
        const metrics = document.getElementById('scan-metrics');
        metrics.style.display = 'flex';
        metrics.innerHTML = `
            <div class="metric"><div class="val">${data.internal_links || 0}</div><div class="label">Pages Found</div></div>
            <div class="metric"><div class="val">${data.images || 0}</div><div class="label">Images</div></div>
            <div class="metric"><div class="val">${data.platform || 'custom'}</div><div class="label">Platform</div></div>
            <div class="metric"><div class="val">${data.ssl_valid ? '✓' : '✗'}</div><div class="label">SSL</div></div>
        `;

        document.getElementById('scan-next').style.display = 'inline-block';
    } else {
        log('scan-log', 'Scan failed: ' + (data.error || 'Unknown'), 'error');
    }
}

async function startAudit() {
    activateStep(3);
    log('audit-log', 'Starting SEO audit...', 'info');
    log('audit-log', 'Crawling pages and checking for issues...', 'dim');

    const res = await fetch(API_BASE + '/onboarding.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'audit', site_id: siteId})
    });
    const data = await res.json();

    if (data.success) {
        log('audit-log', 'Crawled ' + data.pages + ' pages in ' + data.duration + 's', 'success');
        log('audit-log', 'Found ' + data.issues + ' issues (' + data.critical + ' critical)', data.critical > 0 ? 'warn' : 'success');

        // Animate score reveal
        const scoreEl = document.getElementById('audit-score');
        scoreEl.style.display = 'block';
        const scoreVal = document.getElementById('score-value');
        document.getElementById('score-detail').textContent = data.issues + ' issues found across ' + data.pages + ' pages';

        let current = 0;
        const target = data.score;
        const interval = setInterval(() => {
            current += Math.ceil(target / 30);
            if (current >= target) { current = target; clearInterval(interval); }
            scoreVal.textContent = current;
            scoreVal.style.color = current >= 80 ? '#10b981' : current >= 50 ? '#f59e0b' : '#ef4444';
        }, 40);

        if (data.issues > 0) {
            document.getElementById('audit-next').style.display = 'inline-block';
            document.getElementById('audit-next').textContent = 'Fix ' + data.issues + ' issues automatically →';
        } else {
            document.getElementById('audit-next').style.display = 'inline-block';
            document.getElementById('audit-next').textContent = '🔑 Find Keywords →';
            document.getElementById('audit-next').onclick = function() { startKeywords(); };
        }
    } else {
        log('audit-log', 'Audit failed: ' + (data.error || 'Unknown'), 'error');
    }
}

async function startFix() {
    activateStep(4);
    log('fix-log', 'Starting auto-fixer...', 'info');

    let offset = 0, totalFixed = 0, totalSkipped = 0, totalIssues = 0;
    let hasMore = true;

    while (hasMore) {
        const res = await fetch(API_BASE + '/auto-fix-all.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({site_id: siteId, batch_size: 10, offset: offset})
        });
        const data = await res.json();

        if (!data.success) {
            log('fix-log', 'Batch error: ' + (data.error || 'Unknown'), 'error');
            break;
        }

        totalFixed += data.fixed;
        totalSkipped += data.skipped;
        totalIssues = data.total_issues;
        hasMore = data.has_more;
        offset = data.next_offset || offset + 10;

        const pct = totalIssues > 0 ? Math.round((Math.min(offset, totalIssues) / totalIssues) * 100) : 100;
        document.getElementById('fix-progress-bar').style.width = pct + '%';
        document.getElementById('fix-counter').textContent = Math.min(offset, totalIssues) + ' / ' + totalIssues;
        document.getElementById('fix-status').textContent = totalFixed + ' fixed, ' + totalSkipped + ' skipped';

        if (data.applied) {
            data.applied.forEach(a => log('fix-log', a, 'success'));
        }
        if (data.deployed) {
            data.deployed.forEach(d => log('fix-log', 'Deployed: ' + d, 'info'));
        }
    }

    log('fix-log', '', '');
    log('fix-log', 'Fixes generated! ' + totalFixed + ' ready to deploy, ' + totalSkipped + ' need manual review.', 'success');

    document.getElementById('fix-result').style.display = 'block';
    document.getElementById('fixed-count').textContent = totalFixed;

    // Show honest status + snippet
    const resultCard = document.getElementById('fix-result');
    resultCard.querySelector('.sub').innerHTML = 'fixes generated & ready to deploy';

    // Show deployment options
    const deployInfo = document.createElement('div');
    deployInfo.style.cssText = 'text-align:left;margin-top:16px;padding:14px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;';
    deployInfo.innerHTML = `
        <div style="font-weight:600;margin-bottom:8px;">📦 How to apply these fixes to your live site:</div>
        <div style="margin-bottom:12px;padding:10px;background:#f0fdf4;border-radius:4px;">
            <strong>Option 1: Add this snippet</strong> (fastest — 1 minute)<br>
            <span style="font-size:12px;color:#666;">Paste this one line in your website's &lt;head&gt; tag:</span>
            <div style="background:#1a1a2e;color:#10b981;padding:8px 12px;border-radius:4px;margin-top:6px;font-family:monospace;font-size:11px;cursor:pointer;word-break:break-all;" onclick="navigator.clipboard.writeText(this.innerText.trim());alert('Copied!')">
                &lt;script src="${window.location.origin}<?= url('/snippet/contentagent.js') ?>" data-site="${siteDomain}"&gt;&lt;/script&gt;
            </div>
            <span style="font-size:11px;color:#94a3b8;">Click to copy. The snippet auto-injects all missing canonical, meta, OG, and schema tags.</span>
        </div>
        <div style="padding:10px;background:#f8f9fa;border-radius:4px;">
            <strong>Option 2: Give server access</strong> (permanent fix)<br>
            <span style="font-size:12px;color:#666;">Add FTP or database credentials in Sites → Edit to let ContentAgent push changes directly.</span>
        </div>
    `;
    resultCard.appendChild(deployInfo);

    document.getElementById('fix-next').style.display = 'inline-block';
}

async function startKeywords() {
    activateStep(5);
    log('kw-log', 'Starting keyword research...', 'info');
    log('kw-log', 'Scraping Google Autocomplete...', 'dim');

    const res = await fetch(API_BASE + '/onboarding.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'keywords', site_id: siteId})
    });
    const data = await res.json();

    if (data.success) {
        log('kw-log', 'Found ' + data.total + ' keywords', 'success');
        if (data.samples) {
            data.samples.forEach(k => log('kw-log', '  → ' + k, 'dim'));
        }

        const metrics = document.getElementById('kw-metrics');
        metrics.style.display = 'flex';
        metrics.innerHTML = `
            <div class="metric"><div class="val">${data.total}</div><div class="label">Keywords</div></div>
            <div class="metric"><div class="val">${data.clusters || 0}</div><div class="label">Topic Clusters</div></div>
        `;

        document.getElementById('kw-next').style.display = 'inline-block';
    } else {
        log('kw-log', 'Error: ' + (data.error || 'Unknown'), 'error');
        document.getElementById('kw-next').style.display = 'inline-block';
    }
}

async function startContentPlan() {
    activateStep(6);
    log('plan-log', 'Analyzing keywords, news, and trends...', 'info');
    log('plan-log', 'Generating content strategy with AI...', 'dim');

    const res = await fetch(API_BASE + '/onboarding.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'content_plan', site_id: siteId})
    });
    const data = await res.json();

    if (data.success && data.topics) {
        log('plan-log', 'Created ' + data.topics.length + ' blog topic proposals!', 'success');

        const topicsDiv = document.getElementById('plan-topics');
        topicsDiv.style.display = 'block';
        topicsDiv.innerHTML = '<div style="font-weight:600;margin-bottom:8px;">Proposed Blog Topics:</div>';

        data.topics.forEach((t, i) => {
            topicsDiv.innerHTML += `
                <div style="padding:10px 0;border-bottom:1px solid #f1f5f9;">
                    <div style="font-weight:600;font-size:13px;">${i+1}. ${t.title || t}</div>
                    ${t.description ? '<div style="font-size:12px;color:#666;margin-top:2px;">' + t.description + '</div>' : ''}
                    ${t.keywords ? '<div style="font-size:11px;color:#94a3b8;margin-top:2px;">Keywords: ' + (Array.isArray(t.keywords) ? t.keywords.join(', ') : t.keywords) + '</div>' : ''}
                </div>
            `;
        });

        topicsDiv.innerHTML += '<div class="text-sm text-muted" style="margin-top:8px;">Go to AI Writer to write any of these topics.</div>';
    } else {
        log('plan-log', 'Content plan skipped (add API key for AI features)', 'warn');
    }

    document.getElementById('plan-next').style.display = 'inline-block';
}

function finishSetup() {
    activateStep(7);

    // Show final metrics
    const metrics = document.getElementById('final-metrics');
    metrics.innerHTML = `
        <div class="metric"><div class="val">✓</div><div class="label">Site Scanned</div></div>
        <div class="metric"><div class="val">✓</div><div class="label">SEO Audited</div></div>
        <div class="metric"><div class="val">✓</div><div class="label">Fixes Ready</div></div>
        <div class="metric"><div class="val">✓</div><div class="label">Keywords Found</div></div>
    `;
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
