/**
 * ContentAgent SEO Snippet
 *
 * Embed this on any website to auto-fix SEO issues:
 * <script src="https://YOUR_CONTENTAGENT_URL/snippet/contentagent.js" data-site="yourdomain.com"></script>
 *
 * What it does (SAFE by default — fill_only mode):
 * - Adds canonical tag ONLY if missing
 * - Adds meta description ONLY if missing
 * - Adds Open Graph tags ONLY if missing
 * - Injects JSON-LD schema
 * - Handles 301 redirects
 * - Does NOT replace existing page titles or descriptions
 *
 * Override mode (must be enabled per-site in ContentAgent):
 * - Replaces existing title/description with the version from ContentAgent
 * - Only use when you explicitly want ContentAgent to control these tags
 */

(function() {
    'use strict';

    var script = document.currentScript || document.querySelector('script[data-site]');
    if (!script) return;

    var domain = script.getAttribute('data-site') || window.location.hostname;
    var apiBase = script.src.replace('/snippet/contentagent.js', '/api');
    var path = window.location.pathname;

    fetch(apiBase + '/seo-data.php?domain=' + encodeURIComponent(domain) + '&path=' + encodeURIComponent(path))
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data || data.error) return;

            // Handle redirect
            if (data.redirect) {
                window.location.replace(data.to);
                return;
            }

            var head = document.head;
            var override = data.snippet_mode === 'override';

            // Canonical — always safe to add if missing; only replace in override mode
            if (data.canonical) {
                var existing = head.querySelector('link[rel="canonical"]');
                if (!existing) {
                    var link = document.createElement('link');
                    link.rel = 'canonical';
                    link.href = data.canonical;
                    head.appendChild(link);
                } else if (override) {
                    existing.href = data.canonical;
                }
            }

            // Meta title — ONLY override if explicitly enabled
            if (data.meta_title && override) {
                document.title = data.meta_title;
                var titleMeta = head.querySelector('meta[property="og:title"]');
                if (titleMeta) titleMeta.content = data.meta_title;
            }
            // If not override mode, do nothing to <title> — keep whatever the site already has

            // Meta description — add if missing, replace only in override mode
            if (data.meta_description) {
                var desc = head.querySelector('meta[name="description"]');
                if (!desc) {
                    desc = document.createElement('meta');
                    desc.name = 'description';
                    desc.content = data.meta_description;
                    head.appendChild(desc);
                } else if (override) {
                    desc.content = data.meta_description;
                }
            }

            // Open Graph — add if missing, replace only in override mode
            if (data.og_title) setMeta('og:title', data.og_title, 'property', override);
            if (data.og_description) setMeta('og:description', data.og_description, 'property', override);
            if (data.og_image) setMeta('og:image', data.og_image, 'property', override);

            // OG defaults (only add if missing — these are always safe)
            if (!head.querySelector('meta[property="og:type"]')) {
                setMeta('og:type', 'website', 'property', false);
            }
            if (!head.querySelector('meta[property="og:url"]')) {
                setMeta('og:url', window.location.href, 'property', false);
            }
            if (!head.querySelector('meta[name="twitter:card"]')) {
                setMeta('twitter:card', 'summary', 'name', false);
            }

            // Schema / JSON-LD — always safe to add (additive, doesn't replace anything)
            if (data.schema) {
                var schemas = Array.isArray(data.schema) ? data.schema : [data.schema];
                schemas.forEach(function(s) {
                    var el = document.createElement('script');
                    el.type = 'application/ld+json';
                    el.textContent = JSON.stringify(s);
                    head.appendChild(el);
                });
            }

            // Extra head HTML — only injected, never replaces existing
            if (data.extra_head) {
                var div = document.createElement('div');
                div.innerHTML = data.extra_head;
                while (div.firstChild) {
                    head.appendChild(div.firstChild);
                }
            }

        })
        .catch(function() { /* silent fail — don't break the site */ });

    function setMeta(name, content, attr, allowOverride) {
        attr = attr || 'name';
        var el = document.head.querySelector('meta[' + attr + '="' + name + '"]');
        if (!el) {
            el = document.createElement('meta');
            el.setAttribute(attr, name);
            el.content = content;
            document.head.appendChild(el);
        } else if (allowOverride) {
            el.content = content;
        }
        // If el exists and not override mode — leave it alone
    }

})();
