<?php
/**
 * Shopify OAuth integration.
 *
 * Build a "Connect with Shopify" install flow so customers don't have to hand-
 * paste tokens. They click → are redirected to Shopify's authorize screen →
 * approve scopes → Shopify redirects back to us with a code → we exchange the
 * code for a permanent `shpat_` Admin API token and save it to sites.cms_api_key.
 *
 * Setup (one-time, Sameer's job):
 * 1. Go to https://partners.shopify.com → Apps → Create app → public app
 *    OR https://shopify.dev/dashboard → Apps → New app
 * 2. Set "App URL" = config('app_url') . '/dashboard/setup.php'
 * 3. Set "Allowed redirection URL" =
 *    config('app_url') . '/api/oauth/shopify-callback.php'
 * 4. Set scopes: write_content, write_online_store_navigation, read_products, read_themes
 * 5. Copy Client ID + Client Secret into config.php as shopify_client_id /
 *    shopify_client_secret.
 *
 * The Shopify OAuth flow is shop-specific — there's no central "pick your
 * shop" page. The customer must provide their {shop}.myshopify.com domain
 * first, then we build the install URL against that shop.
 *
 * Reference: https://shopify.dev/docs/apps/auth/oauth/getting-started
 */

require_once __DIR__ . '/../helpers.php';

/**
 * Scopes we request:
 *   - write_content                  → blog articles + pages
 *   - write_online_store_navigation  → URL redirects (Shopify moved redirects
 *                                      under the Navigation API in 2026; the
 *                                      old `write_url_redirects` scope was
 *                                      retired)
 *   - read_products                  → GMC feed audit + Image SEO
 *   - read_themes                    → deploy branded 404.liquid to theme
 */
const SHOPIFY_OAUTH_SCOPES = 'write_content,write_online_store_navigation,read_products,read_themes';

/**
 * Normalise whatever the user typed into a canonical shop hostname.
 * Accepts:
 *   "https://shop.myshopify.com/"     → "shop.myshopify.com"
 *   "shop.myshopify.com"               → "shop.myshopify.com"
 *   "shop"                              → "shop.myshopify.com"
 *   "annalouoflondon.com"               → "annalouoflondon.com" (custom domain
 *     — Shopify accepts these too, but the install URL still uses the
 *     myshopify alias when known. We accept both.)
 * Returns null if the input doesn't look like a shop domain.
 */
function shopify_normalise_shop(string $input): ?string
{
    $s = strtolower(trim($input));
    if ($s === '') return null;
    // strip protocol + trailing slash + path
    $s = preg_replace('#^https?://#i', '', $s);
    $s = strtok($s, '/'); // drop any path
    $s = rtrim($s, '/');
    if (!$s) return null;
    // bare shop name → append myshopify suffix
    if (!str_contains($s, '.')) $s = $s . '.myshopify.com';
    // basic sanity — must look like a hostname
    if (!preg_match('/^[a-z0-9][a-z0-9\-]*(\.[a-z0-9\-]+)+$/', $s)) return null;
    return $s;
}

/**
 * Build the Shopify authorize URL the user will be redirected to.
 * State encodes the site_id so the callback knows which row to update; we
 * also store a nonce in the session for CSRF protection.
 */
function shopify_get_install_url(string $shop, int $site_id): string
{
    $nonce = bin2hex(random_bytes(16));
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['shopify_oauth_nonce'] = $nonce;
        $_SESSION['shopify_oauth_site']  = $site_id;
        $_SESSION['shopify_oauth_shop']  = $shop;
    }

    $params = [
        'client_id'    => (string)config('shopify_client_id'),
        'scope'        => SHOPIFY_OAUTH_SCOPES,
        'redirect_uri' => config('app_url') . '/api/oauth/shopify-callback.php',
        // Shopify recommends `state` be a per-request nonce. We concatenate
        // nonce:site_id so the callback can route + verify in one step.
        'state'        => $nonce . ':' . $site_id,
    ];
    return 'https://' . $shop . '/admin/oauth/authorize?' . http_build_query($params);
}

/**
 * Verify the HMAC signature Shopify includes on the callback.
 *
 * Algorithm (per Shopify's reference Python impl):
 *   1. Parse the raw query string with parse_str (URL-decodes values).
 *   2. Pull out + remove `hmac` and `signature`.
 *   3. Sort the remaining params alphabetically by key.
 *   4. Join as `key=value` pairs with `&`. NB: values are DECODED here —
 *      Shopify signs the decoded form, not the URL-encoded form. So
 *      `state=abc:9` (not `state=abc%3A9`) is what gets hashed.
 *   5. HMAC-SHA256 with client_secret, hex-encode, compare.
 *
 * Confirmed empirically: signing the encoded form (preserving %3A etc.)
 * produces a mismatching hash even when the request is genuine.
 */
function shopify_verify_hmac(string $raw_query): bool
{
    $secret = (string)config('shopify_client_secret');
    if (!$secret) return false;

    parse_str($raw_query, $params);
    $received_hmac = (string)($params['hmac'] ?? '');
    if ($received_hmac === '') return false;
    unset($params['hmac'], $params['signature']);

    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        // Skip array-typed values — Shopify never sends arrays on the
        // OAuth callback, so this is defensive only.
        if (is_array($v)) continue;
        $pairs[] = $k . '=' . $v;
    }
    $message  = implode('&', $pairs);
    $computed = hash_hmac('sha256', $message, $secret);
    return hash_equals($computed, $received_hmac);
}

/**
 * Exchange the OAuth code for a permanent Admin API access token. The token
 * is shop-scoped (works only for the shop that approved the install) and
 * doesn't expire (no refresh-token dance needed, unlike Google).
 *
 * Returns ['access_token' => 'shpat_...', 'scope' => '...'] on success, null
 * on failure.
 */
function shopify_exchange_code(string $shop, string $code): ?array
{
    $url = 'https://' . $shop . '/admin/oauth/access_token';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'client_id'     => (string)config('shopify_client_id'),
            'client_secret' => (string)config('shopify_client_secret'),
            'code'          => $code,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) return null;
    $data = json_decode((string)$body, true);
    return (is_array($data) && !empty($data['access_token'])) ? $data : null;
}

/**
 * Save the access token to sites — drops into the existing cms_url/cms_api_key
 * columns so all downstream code (connectors/shopify.php, integrations/
 * shopify.php, redirect push, etc.) just works without changes.
 *
 * Also persists the granted scope into sites.notes JSON (under shopify.scope)
 * for diagnostics — handy when GraphQL mutations fail with "access denied" and
 * we need to know whether the customer approved write_content.
 */
function shopify_save_token(PDO $db, int $site_id, string $shop, array $tokens): void
{
    $shop_url = 'https://' . $shop;
    $token    = (string)$tokens['access_token'];
    $scope    = (string)($tokens['scope'] ?? '');

    // Load + merge sites.notes JSON without losing other modules' state.
    $row = $db->prepare("SELECT notes FROM sites WHERE id = ?");
    $row->execute([$site_id]);
    $current = $row->fetchColumn();
    $notes   = $current ? (json_decode((string)$current, true) ?: []) : [];
    if (!is_array($notes)) $notes = [];
    $notes['shopify'] = array_merge($notes['shopify'] ?? [], [
        'shop'      => $shop,
        'scope'     => $scope,
        'connected_at' => date('c'),
        'auth_method'  => 'oauth',
    ]);

    $db->prepare("UPDATE sites
                    SET cms_url = ?, cms_api_key = ?,
                        platform = COALESCE(NULLIF(platform,''),'shopify'),
                        notes = ?, updated_at = NOW()
                  WHERE id = ?")
        ->execute([$shop_url, $token, json_encode($notes), $site_id]);
}
