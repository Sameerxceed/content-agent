<?php
/**
 * Integrations Hub.
 *
 * Lists every external integration (Google CSE, Resend, Reddit, LinkedIn, Twitter, GSC)
 * with status + click-through to a guided step-by-step setup wizard.
 *
 * Drives /api/integration-action.php behind the scenes.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/setup_wizards/registry.php';

auth_start();
auth_require();

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$wizards   = setup_wizards_all();
$is_super  = auth_is_super_admin();

// Fetch each user's wizard progress (super-admin only — customers don't run wizards)
$progress_map = [];
if ($is_super) {
    try {
        $stmt = $db->prepare('SELECT integration, current_step, status, state_json, last_test_result, last_attempted_at
                              FROM integration_setup_progress WHERE user_id = ?');
        $stmt->execute([$user_id]);
        foreach ($stmt->fetchAll() as $row) {
            $progress_map[$row['integration']] = $row;
        }
    } catch (PDOException $e) {
        // table not migrated yet — fail soft
    }
}

$page_title = $is_super ? 'Integrations' : "What's included";

ob_start();
?>

<?php if (!$is_super): /* ───── CUSTOMER read-only view ───── */ ?>
<style>
.inc-hero { background:linear-gradient(135deg, #1B3A6B 0%, #2c5282 100%); color:#fff; border-radius:8px; padding:18px 22px; margin-bottom:18px; }
.inc-hero h2 { font-size:17px; font-weight:600; margin-bottom:4px; }
.inc-hero p  { font-size:13px; opacity:0.9; }
.inc-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:10px; margin-bottom:18px; }
.inc-card {
    background:#fff; border:1px solid var(--border); border-left:3px solid var(--success);
    border-radius:6px; padding:12px 14px; display:flex; align-items:flex-start; gap:10px;
}
.inc-card .ico { font-size:22px; line-height:1; }
.inc-card .nm  { font-weight:600; font-size:13px; color:var(--primary); }
.inc-card .dc  { font-size:11px; color:var(--text-light); line-height:1.5; margin-top:2px; }
.inc-connect-card {
    background:#fff; border:1px solid var(--border); border-radius:6px; padding:14px 16px;
}
.inc-connect-card h3 { font-size:14px; font-weight:600; color:var(--primary); margin-bottom:4px; }
.inc-connect-card p  { font-size:12px; color:var(--text-light); margin-bottom:10px; }
</style>

<div class="inc-hero">
    <h2>Everything ContentAgent runs on, included in your plan</h2>
    <p>These are the AI and data services powering your dashboard. No setup needed — we handle the keys and the bill.</p>
</div>

<div class="inc-grid">
    <?php
    // Customer-friendly list of shared services. Don't expose keys or internal IDs.
    $included = [
        ['🤖', 'Claude AI (Anthropic)',     'Writes blog posts, repurposes for channels, parses pasted alerts.'],
        ['📊', 'DataForSEO',                'Real keyword volume, difficulty, and SERP positions.'],
        ['🔭', 'Perplexity (optional)',     'Tracks who AI search engines cite for your queries.'],
        ['🔍', 'Google Custom Search',      'Powers competitor discovery and AI Presence scans.'],
        ['✉',  'Resend Email',              'Delivers your weekly digests and newsletter sends.'],
    ];
    foreach ($included as [$icon, $name, $desc]):
    ?>
    <div class="inc-card">
        <div class="ico"><?= $icon ?></div>
        <div style="flex:1;">
            <div class="nm"><?= e($name) ?></div>
            <div class="dc"><?= e($desc) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="inc-connect-card">
    <h3>Connect your accounts</h3>
    <p>Plug ContentAgent into your <strong>own</strong> external accounts (Google Search Console, LinkedIn, Twitter, Reddit) per site — we don't share these across customers.</p>
    <p style="margin:0;">Open any of your sites and use the <strong>Distribution channels</strong> card on the Overview page to connect.</p>
</div>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
return;
endif; /* customer view */
?>
<style>
.intg-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:14px; margin-bottom:20px; }
.intg-card {
    background:#fff; border:1px solid var(--border); border-radius:8px; padding:16px;
    display:flex; flex-direction:column; gap:8px; cursor:pointer; transition:all 0.15s;
}
.intg-card:hover { border-color:var(--primary); box-shadow:0 2px 6px rgba(0,0,0,0.06); }
.intg-card.connected { border-left:3px solid var(--success); }
.intg-card.failed    { border-left:3px solid var(--danger); }
.intg-card.in-progress { border-left:3px solid var(--warning); }
.intg-head { display:flex; align-items:center; gap:10px; }
.intg-icon { font-size:24px; }
.intg-name { font-size:15px; font-weight:600; color:var(--primary); }
.intg-purpose { font-size:12px; color:var(--text-light); line-height:1.5; }
.intg-status { font-size:11px; padding:3px 8px; border-radius:10px; display:inline-block; font-weight:600; }
.intg-status.s-ok   { background:#d1fae5; color:#065f46; }
.intg-status.s-fail { background:#fecaca; color:#991b1b; }
.intg-status.s-prog { background:#fef3c7; color:#92400e; }
.intg-status.s-new  { background:#e2e8f0; color:#475569; }

/* ── Wizard modal ──────────────────────────── */
.wiz-overlay {
    position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:200;
    display:flex; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto;
}
.wiz-modal {
    background:#fff; border-radius:10px; width:100%; max-width:680px;
    box-shadow:0 20px 60px rgba(0,0,0,0.25);
}
.wiz-header {
    padding:16px 20px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
}
.wiz-title { font-size:16px; font-weight:600; color:var(--primary); display:flex; align-items:center; gap:10px; }
.wiz-close { background:none; border:none; font-size:22px; cursor:pointer; color:var(--text-light); }
.wiz-body { padding:20px; }
.wiz-stepper { display:flex; gap:6px; margin-bottom:18px; }
.wiz-step-pill {
    flex:1; height:6px; border-radius:3px; background:var(--border);
}
.wiz-step-pill.done { background:var(--success); }
.wiz-step-pill.current { background:var(--primary); }

.wiz-step-title { font-size:15px; font-weight:600; margin-bottom:6px; color:var(--text); }
.wiz-step-why   { font-size:12px; color:var(--text-light); margin-bottom:14px; line-height:1.5; }

.wiz-instructions { background:#f8fafc; border:1px solid var(--border); border-radius:6px; padding:12px 14px; margin-bottom:14px; }
.wiz-instructions ol { margin:0; padding-left:18px; font-size:13px; line-height:1.7; color:var(--text); }
.wiz-instructions code { background:#fff; border:1px solid var(--border); padding:1px 5px; border-radius:3px; font-size:12px; }

.wiz-link {
    display:inline-block; background:var(--primary); color:#fff; text-decoration:none;
    padding:8px 14px; border-radius:6px; font-size:13px; font-weight:500; margin-bottom:14px;
}
.wiz-link:hover { background:var(--primary-dark); }

.wiz-field-row { margin-bottom:12px; }
.wiz-field-row label { display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:var(--text); }
.wiz-field-row input[type=text], .wiz-field-row input[type=password], .wiz-field-row textarea {
    width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:5px; font-size:13px;
}
.wiz-field-row input[type=checkbox] { margin-right:6px; }

.wiz-actions { display:flex; justify-content:space-between; align-items:center; margin-top:18px; padding-top:14px; border-top:1px solid var(--border); }
.wiz-error { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:10px 12px; border-radius:6px; font-size:13px; margin-bottom:14px; }
.wiz-success { background:#d1fae5; border:1px solid #6ee7b7; color:#065f46; padding:12px 14px; border-radius:6px; font-size:13px; margin-bottom:14px; }

.wiz-parsed-error { background:#fff7ed; border:1px solid #fdba74; border-radius:6px; padding:14px; margin-bottom:14px; }
.wiz-parsed-error .pe-title { font-weight:600; color:#9a3412; margin-bottom:6px; font-size:14px; }
.wiz-parsed-error .pe-msg   { color:#7c2d12; font-size:13px; margin-bottom:10px; line-height:1.5; }
.wiz-parsed-error .pe-fixes { display:flex; flex-wrap:wrap; gap:8px; }
.wiz-parsed-error .pe-fix   {
    background:#fff; border:1px solid #fdba74; color:#9a3412;
    padding:6px 12px; border-radius:5px; font-size:12px; text-decoration:none; font-weight:500;
}
.wiz-parsed-error .pe-fix:hover { background:#fed7aa; }
</style>

<div class="card" style="background:linear-gradient(135deg, #1B3A6B 0%, #2c5282 100%); color:#fff; border:none;">
    <div style="font-size:18px; font-weight:600; margin-bottom:4px;">Integrations Hub</div>
    <div style="font-size:13px; opacity:0.85;">Everything ContentAgent talks to — set up once, runs forever. Click any card to start or resume setup.</div>
</div>

<div class="intg-grid">
    <?php foreach ($wizards as $id => $wiz):
        $configured = $wiz->is_configured();
        $prog = $progress_map[$id] ?? null;
        $status = $prog['status'] ?? null;

        $card_class = 'intg-card';
        $pill_class = 's-new'; $pill_text = 'Not set up';
        if ($configured) {
            $card_class .= ' connected';
            $pill_class = 's-ok'; $pill_text = '✓ Configured';
        } elseif ($status === 'failed') {
            $card_class .= ' failed';
            $pill_class = 's-fail'; $pill_text = 'Last test failed';
        } elseif ($status === 'in_progress' && (int)($prog['current_step'] ?? 1) > 1) {
            $card_class .= ' in-progress';
            $pill_class = 's-prog'; $pill_text = 'Resume at step ' . (int)$prog['current_step'];
        }
    ?>
    <div class="<?= $card_class ?>" onclick="openWizard('<?= e($id) ?>')">
        <div class="intg-head">
            <span class="intg-icon"><?= $wiz->icon() ?></span>
            <div style="flex:1;">
                <div class="intg-name"><?= e($wiz->name()) ?></div>
                <div style="font-size:11px; color:var(--text-light); margin-top:2px;">
                    <?= $wiz->scope() === 'site' ? 'Per-site connection' : 'Global setup' ?>
                </div>
            </div>
        </div>
        <div class="intg-purpose"><?= e($wiz->purpose()) ?></div>
        <div style="margin-top:auto;">
            <span class="intg-status <?= $pill_class ?>"><?= $pill_text ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Wizard modal (single, repurposed) -->
<div id="wizOverlay" class="wiz-overlay" style="display:none;">
    <div class="wiz-modal">
        <div class="wiz-header">
            <div class="wiz-title" id="wizTitle">…</div>
            <button class="wiz-close" onclick="closeWizard()">&times;</button>
        </div>
        <div class="wiz-body" id="wizBody">Loading…</div>
    </div>
</div>

<script>
const csrfToken = '<?= e($_SESSION["_csrf_token"] ?? "") ?>';
let wizState = null;       // full state from get_state
let currentStepIdx = 1;

function openWizard(integration) {
    document.getElementById('wizOverlay').style.display = 'flex';
    document.getElementById('wizBody').innerHTML = 'Loading…';
    document.getElementById('wizTitle').textContent = '…';
    api({ action: 'get_state', integration }).then(data => {
        wizState = data;
        currentStepIdx = data.current_step || 1;
        document.getElementById('wizTitle').innerHTML = data.icon + ' &nbsp;' + escapeHtml(data.name);
        renderStep();
    }).catch(err => {
        document.getElementById('wizBody').innerHTML = '<div class="wiz-error">' + escapeHtml(err.message) + '</div>';
    });
}

function closeWizard() {
    document.getElementById('wizOverlay').style.display = 'none';
    if (wizState && wizState.is_configured) {
        // refresh page to update card statuses
        window.location.reload();
    }
}

function renderStep() {
    const steps = wizState.steps;
    const isFinal = currentStepIdx > steps.length;

    let html = '<div class="wiz-stepper">';
    for (let i = 1; i <= steps.length; i++) {
        let cls = 'wiz-step-pill';
        if (i < currentStepIdx) cls += ' done';
        else if (i === currentStepIdx) cls += ' current';
        html += '<div class="' + cls + '" title="Step ' + i + '"></div>';
    }
    html += '</div>';

    if (isFinal) {
        html += renderFinal();
        document.getElementById('wizBody').innerHTML = html;
        return;
    }

    const step = steps[currentStepIdx - 1];
    html += '<div class="wiz-step-title">Step ' + currentStepIdx + ' of ' + steps.length + ' — ' + escapeHtml(step.title) + '</div>';
    if (step.why) html += '<div class="wiz-step-why">' + escapeHtml(step.why) + '</div>';

    if (step.external_url) {
        html += '<a class="wiz-link" href="' + escapeHtml(step.external_url) + '" target="_blank" rel="noopener">'
              + escapeHtml(step.link_label || 'Open ↗') + '</a>';
    }

    if (step.instructions && step.instructions.length) {
        html += '<div class="wiz-instructions"><ol>';
        for (const ins of step.instructions) html += '<li>' + ins + '</li>';
        html += '</ol></div>';
    }

    html += '<div id="wizFieldsErr"></div>';
    html += '<div id="wizFields">';
    for (const f of (step.fields || [])) {
        const prefill = (wizState.state && wizState.state[f.key]) || '';
        html += '<div class="wiz-field-row">';
        if (f.type === 'checkbox') {
            html += '<label><input type="checkbox" data-key="' + escapeAttr(f.key) + '" ' + (prefill ? 'checked' : '') + '> ' + escapeHtml(f.label) + '</label>';
        } else if (f.type === 'textarea') {
            html += '<label>' + escapeHtml(f.label) + '</label>';
            html += '<textarea data-key="' + escapeAttr(f.key) + '" rows="3">' + escapeHtml(prefill) + '</textarea>';
        } else {
            html += '<label>' + escapeHtml(f.label) + '</label>';
            html += '<input type="' + (f.type === 'password' ? 'password' : 'text') + '" data-key="' + escapeAttr(f.key)
                  + '" placeholder="' + escapeAttr(f.placeholder || '') + '" value="' + escapeAttr(prefill) + '">';
        }
        html += '</div>';
    }
    html += '</div>';

    html += '<div class="wiz-actions">';
    if (currentStepIdx > 1) {
        html += '<button class="btn btn-outline btn-sm" onclick="prevStep()">&larr; Back</button>';
    } else { html += '<div></div>'; }
    const nextLabel = (currentStepIdx === steps.length) ? 'Save & Test &rarr;' : 'Save & Continue &rarr;';
    html += '<button class="btn btn-primary btn-sm" id="wizNextBtn" onclick="saveStep()">' + nextLabel + '</button>';
    html += '</div>';

    document.getElementById('wizBody').innerHTML = html;
}

function renderFinal() {
    let html = '';
    if (wizState.is_configured) {
        html += '<div class="wiz-success">✓ <strong>' + escapeHtml(wizState.name) + ' is configured.</strong> ' + escapeHtml(wizState.status_line) + '</div>';
    }

    if (wizState.last_test) {
        const lt = wizState.last_test;
        if (lt.parsed) {
            html += renderParsedError(lt.parsed);
        } else if (lt.success) {
            html += '<div class="wiz-success">✓ Test passed. ' + escapeHtml((lt.details && lt.details.note) || '') + '</div>';
        }
    }

    html += '<div style="text-align:center; padding:14px 0;">'
          + '<button class="btn btn-primary" onclick="runTest()" id="wizTestBtn">Run test now</button> '
          + '<button class="btn btn-outline btn-sm" onclick="resetWizard()" style="margin-left:8px;">Reset & start over</button>'
          + '</div>';

    html += '<div class="wiz-actions">'
          + '<button class="btn btn-outline btn-sm" onclick="prevStep()">&larr; Back</button>'
          + '<button class="btn btn-primary btn-sm" onclick="closeWizard()">Done</button>'
          + '</div>';
    return html;
}

function renderParsedError(p) {
    let html = '<div class="wiz-parsed-error">';
    html += '<div class="pe-title">' + escapeHtml(p.title || 'Test failed') + '</div>';
    html += '<div class="pe-msg">' + escapeHtml(p.message || '') + '</div>';
    if (p.fixes && p.fixes.length) {
        html += '<div class="pe-fixes">';
        for (const f of p.fixes) {
            if (f.url) {
                html += '<a class="pe-fix" href="' + escapeAttr(f.url) + '" target="_blank" rel="noopener">' + escapeHtml(f.label) + ' ↗</a>';
            } else {
                html += '<span class="pe-fix">' + escapeHtml(f.label) + '</span>';
            }
        }
        html += '</div>';
    }
    html += '</div>';
    return html;
}

function prevStep() {
    if (currentStepIdx > 1) currentStepIdx--;
    renderStep();
}

function collectFields() {
    const inputs = {};
    document.querySelectorAll('#wizFields [data-key]').forEach(el => {
        if (el.type === 'checkbox') inputs[el.dataset.key] = el.checked ? '1' : '';
        else inputs[el.dataset.key] = el.value;
    });
    return inputs;
}

async function saveStep() {
    const btn = document.getElementById('wizNextBtn');
    btn.disabled = true;
    const inputs = collectFields();
    try {
        const res = await api({
            action: 'save_step',
            integration: wizState.integration,
            step: currentStepIdx,
            input: inputs,
        });
        if (!res.success) {
            document.getElementById('wizFieldsErr').innerHTML = '<div class="wiz-error">' + escapeHtml(res.error || 'Validation failed') + '</div>';
            btn.disabled = false;
            return;
        }
        // Refresh state so we get updated state map, then advance
        const fresh = await api({ action: 'get_state', integration: wizState.integration });
        wizState = fresh;
        if (res.is_final) {
            currentStepIdx = wizState.steps.length + 1;
            renderStep();
            // Auto-run test on completion
            runTest();
        } else {
            currentStepIdx = res.next_step;
            renderStep();
        }
    } catch (err) {
        document.getElementById('wizFieldsErr').innerHTML = '<div class="wiz-error">' + escapeHtml(err.message) + '</div>';
        btn.disabled = false;
    }
}

async function runTest() {
    const btn = document.getElementById('wizTestBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Testing…'; }
    try {
        const res = await api({ action: 'test', integration: wizState.integration });
        // Re-fetch state for fresh status_line + last_test
        wizState = await api({ action: 'get_state', integration: wizState.integration });
        renderStep();
    } catch (err) {
        if (btn) { btn.disabled = false; btn.textContent = 'Run test now'; }
        alert(err.message);
    }
}

async function resetWizard() {
    if (!confirm('Wipe progress and start over? Saved keys in config are not removed.')) return;
    await api({ action: 'reset', integration: wizState.integration });
    wizState = await api({ action: 'get_state', integration: wizState.integration });
    currentStepIdx = 1;
    renderStep();
}

async function api(body) {
    const res = await fetch('<?= url('/api/integration-action.php') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
    return data;
}

function escapeHtml(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}
function escapeAttr(s) { return escapeHtml(s); }

// Close on overlay click
document.getElementById('wizOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeWizard();
});
// Esc to close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('wizOverlay').style.display === 'flex') closeWizard();
});
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
