<?php
/**
 * Cookie consent banner — JS embed.
 *
 * Customer adds ONE line to their site's <head>:
 *   <script src="https://contentagent.com/embed/cookie-banner.php?site=N" async></script>
 *
 * On first load the banner appears at the bottom; Accept/Reject persists to
 * localStorage so it never re-appears for that visitor. Accept fires a
 * window.dispatchEvent('cookies-accepted') so the customer site can lazy-load
 * analytics/marketing trackers only after consent.
 *
 * Output is pure JavaScript with the customer's branding + cookie-policy URL
 * baked in. No third-party deps. ~3 KB on the wire.
 */
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=300');  // 5min — banner config can change
header('Access-Control-Allow-Origin: *');       // any customer domain may load it

$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { echo "/* missing site param */"; exit; }

$db = require __DIR__ . '/../../includes/db.php';

// Load just enough: site name + cookie policy URL (live if published, else /cookies fallback)
$stmt = $db->prepare("SELECT id, name, domain FROM sites WHERE id = ?");
$stmt->execute([$site_id]);
$site = $stmt->fetch();
if (!$site) { echo "/* unknown site */"; exit; }

// Find the published cookie policy URL — fall back to /cookies on the customer's domain
$doc_stmt = $db->prepare("SELECT status, published_url, found_url FROM legal_docs WHERE site_id = ? AND doc_type = 'cookies'");
$doc_stmt->execute([$site_id]);
$doc = $doc_stmt->fetch();

$cookie_url = '';
if ($doc) {
    $cookie_url = (string)($doc['published_url'] ?? $doc['found_url'] ?? '');
}
if ($cookie_url === '') {
    $cookie_url = 'https://' . ltrim((string)$site['domain'], 'https://') . '/cookies';
}

$brand = (string)$site['name'];

// Allow customer to override copy via query params (rare; defer)
$accept_label = 'Accept all';
$reject_label = 'Reject non-essential';
$message = 'We use cookies to make this site work, understand how it\'s used, and improve it. You can accept all cookies or reject non-essential ones. See our <a href="' . htmlspecialchars($cookie_url, ENT_QUOTES) . '" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline;">Cookie Policy</a> for details.';

// JSON-safe values to inline
$cfg = [
    'siteId'      => $site_id,
    'brand'       => $brand,
    'cookieUrl'   => $cookie_url,
    'message'     => $message,
    'acceptLabel' => $accept_label,
    'rejectLabel' => $reject_label,
    'storageKey'  => 'ca_cookie_consent_v1',
];
?>
(function(){
    'use strict';
    var CFG = <?= json_encode($cfg, JSON_UNESCAPED_SLASHES) ?>;

    // Already decided? Don't re-show.
    try {
        var existing = localStorage.getItem(CFG.storageKey);
        if (existing) {
            // Re-fire the event on each load so trackers conditionally initialise.
            var ev = (existing === 'accepted') ? 'cookies-accepted' : 'cookies-rejected';
            window.dispatchEvent(new CustomEvent(ev, { detail: { value: existing } }));
            return;
        }
    } catch (e) { /* localStorage blocked → keep showing banner */ }

    function setConsent(value) {
        try { localStorage.setItem(CFG.storageKey, value); } catch (e) {}
        var ev = (value === 'accepted') ? 'cookies-accepted' : 'cookies-rejected';
        window.dispatchEvent(new CustomEvent(ev, { detail: { value: value } }));
        var el = document.getElementById('ca-cookie-banner');
        if (el) { el.style.transform = 'translateY(120%)'; setTimeout(function(){ el.remove(); }, 280); }
    }

    function mount() {
        if (document.getElementById('ca-cookie-banner')) return;
        var wrap = document.createElement('div');
        wrap.id = 'ca-cookie-banner';
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-live', 'polite');
        wrap.setAttribute('aria-label', 'Cookie consent');
        wrap.innerHTML =
            '<div class="ca-cb-inner">' +
                '<div class="ca-cb-msg">' + CFG.message + '</div>' +
                '<div class="ca-cb-actions">' +
                    '<button type="button" class="ca-cb-btn ca-cb-reject" id="ca-cb-reject">' + CFG.rejectLabel + '</button>' +
                    '<button type="button" class="ca-cb-btn ca-cb-accept" id="ca-cb-accept">' + CFG.acceptLabel + '</button>' +
                '</div>' +
            '</div>';

        var style = document.createElement('style');
        style.textContent =
            '#ca-cookie-banner{position:fixed;left:16px;right:16px;bottom:16px;z-index:2147483646;' +
                'background:#0f172a;color:#f8fafc;border-radius:12px;box-shadow:0 12px 36px rgba(0,0,0,0.32);' +
                'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;' +
                'transition:transform .28s ease;transform:translateY(0);max-width:980px;margin:0 auto;}' +
            '#ca-cookie-banner .ca-cb-inner{padding:14px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;}' +
            '#ca-cookie-banner .ca-cb-msg{flex:1;min-width:240px;font-size:13px;line-height:1.55;}' +
            '#ca-cookie-banner .ca-cb-actions{display:flex;gap:8px;flex-wrap:wrap;}' +
            '#ca-cookie-banner .ca-cb-btn{font-size:13px;font-weight:600;border:0;border-radius:6px;' +
                'padding:9px 16px;cursor:pointer;transition:opacity .15s;}' +
            '#ca-cookie-banner .ca-cb-btn:hover{opacity:.88;}' +
            '#ca-cookie-banner .ca-cb-reject{background:transparent;color:#cbd5e1;border:1px solid #475569;}' +
            '#ca-cookie-banner .ca-cb-accept{background:#22c55e;color:#0f172a;}' +
            '@media (max-width:520px){#ca-cookie-banner{left:8px;right:8px;bottom:8px;}' +
                '#ca-cookie-banner .ca-cb-inner{padding:12px 14px;}' +
                '#ca-cookie-banner .ca-cb-actions{width:100%;}' +
                '#ca-cookie-banner .ca-cb-btn{flex:1;}}';
        document.head.appendChild(style);
        document.body.appendChild(wrap);

        document.getElementById('ca-cb-accept').addEventListener('click', function(){ setConsent('accepted'); });
        document.getElementById('ca-cb-reject').addEventListener('click', function(){ setConsent('rejected'); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }

    // Expose a tiny API in case the host page wants to re-open the banner ("Manage cookies" link)
    window.ContentAgentCookieBanner = {
        reset: function(){
            try { localStorage.removeItem(CFG.storageKey); } catch (e) {}
            var el = document.getElementById('ca-cookie-banner');
            if (el) el.remove();
            mount();
        },
        getConsent: function(){
            try { return localStorage.getItem(CFG.storageKey); } catch (e) { return null; }
        },
    };
})();
