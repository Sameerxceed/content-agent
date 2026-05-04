/**
 * ContentAgent SEO Snippet
 *
 * Embed this on any website to auto-fix SEO issues:
 * <script src="https://YOUR_CONTENTAGENT_URL/snippet/contentagent.js" data-site="yourdomain.com"></script>
 *
 * What it does:
 * - Adds missing canonical tag
 * - Adds/overrides meta title & description
 * - Adds Open Graph tags if missing
 * - Injects JSON-LD schema markup
 * - Handles 301 redirects
 * - All controlled from ContentAgent dashboard
 */

(function() {
    'use strict';

    // Get config from script tag
    var script = document.currentScript || document.querySelector('script[data-site]');
    if (!script) return;

    var domain = script.getAttribute('data-site') || window.location.hostname;
    var apiBase = script.src.replace('/snippet/contentagent.js', '/api');
    var path = window.location.pathname;

    // Fetch SEO data
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

            // Canonical
            if (data.canonical) {
                var existing = head.querySelector('link[rel="canonical"]');
                if (!existing) {
                    var link = document.createElement('link');
                    link.rel = 'canonical';
                    link.href = data.canonical;
                    head.appendChild(link);
                }
            }

            // Meta title
            if (data.meta_title) {
                document.title = data.meta_title;
                var titleMeta = head.querySelector('meta[property="og:title"]');
                if (titleMeta) titleMeta.content = data.meta_title;
            }

            // Meta description
            if (data.meta_description) {
                var desc = head.querySelector('meta[name="description"]');
                if (desc) {
                    desc.content = data.meta_description;
                } else {
                    desc = document.createElement('meta');
                    desc.name = 'description';
                    desc.content = data.meta_description;
                    head.appendChild(desc);
                }
            }

            // Open Graph
            if (data.og_title) setMeta('og:title', data.og_title, 'property');
            if (data.og_description) setMeta('og:description', data.og_description, 'property');
            if (data.og_image) setMeta('og:image', data.og_image, 'property');

            // OG defaults
            if (!head.querySelector('meta[property="og:type"]')) {
                setMeta('og:type', 'website', 'property');
            }
            if (!head.querySelector('meta[property="og:url"]')) {
                setMeta('og:url', window.location.href, 'property');
            }

            // Twitter Card
            if (!head.querySelector('meta[name="twitter:card"]')) {
                setMeta('twitter:card', 'summary', 'name');
            }

            // Schema / JSON-LD
            if (data.schema) {
                var schemas = Array.isArray(data.schema) ? data.schema : [data.schema];
                schemas.forEach(function(s) {
                    var el = document.createElement('script');
                    el.type = 'application/ld+json';
                    el.textContent = JSON.stringify(s);
                    head.appendChild(el);
                });
            }

            // Extra head HTML
            if (data.extra_head) {
                var div = document.createElement('div');
                div.innerHTML = data.extra_head;
                while (div.firstChild) {
                    head.appendChild(div.firstChild);
                }
            }

        })
        .catch(function() { /* silent fail — don't break the site */ });

    function setMeta(name, content, attr) {
        attr = attr || 'name';
        var el = document.head.querySelector('meta[' + attr + '="' + name + '"]');
        if (el) {
            el.content = content;
        } else {
            el = document.createElement('meta');
            el.setAttribute(attr, name);
            el.content = content;
            document.head.appendChild(el);
        }
    }

})();
