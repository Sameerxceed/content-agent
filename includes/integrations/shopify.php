<?php
/**
 * Shopify Admin API client — beyond just blog push (which lives in
 * connectors/shopify.php). This file is the API surface for everything
 * else ContentAgent needs from a Shopify store:
 *
 *   • URL Redirects: list / create / delete         (Module 3 push target)
 *   • Products + Variants: list / get               (Module 1, Module 4 GMC)
 *   • Collections: list                              (Module 1, Module 3 candidates)
 *   • Pages + Blogs + Articles: list                 (Module 3 candidates)
 *   • Shop diagnostics: verify credentials           (setup wizard)
 *
 * Credentials follow the same pattern as the existing CMS connector:
 *   $shop_url   = sites.cms_url          (https://shop.myshopify.com)
 *   $access_token = sites.cms_api_key    (shpat_... custom-app token)
 *
 * Rate limits: Shopify allows 2 req/sec on the Admin API (40 burst). We
 * self-throttle to be safe and back off on 429s.
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/shopify_graphql.php';

const SHOPIFY_API_VERSION = '2024-10';
const SHOPIFY_DELAY_US    = 600000;   // 0.6s between requests = ~1.6 req/sec
const SHOPIFY_TIMEOUT     = 25;

function shopify_admin_base(string $shop_url): string
{
    return rtrim($shop_url, '/') . '/admin/api/' . SHOPIFY_API_VERSION;
}

/**
 * Low-level Admin API call. Returns ['status' => int, 'body' => array|null, 'error' => string|null].
 * Auto-retries once on 429 with exponential delay.
 */
function shopify_admin_call(string $method, string $url, string $token, ?array $payload = null, int $retries = 1): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => SHOPIFY_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'X-Shopify-Access-Token: ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ];
    if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err && $code === 0) return ['status' => 0, 'body' => null, 'error' => $err];
    if ($code === 429 && $retries > 0) {
        usleep(2000000); // 2s — Shopify default Retry-After
        return shopify_admin_call($method, $url, $token, $payload, $retries - 1);
    }
    $decoded = json_decode((string)$body, true);
    return [
        'status' => $code,
        'body'   => is_array($decoded) ? $decoded : null,
        'error'  => $code >= 400
            ? (is_array($decoded) && !empty($decoded['errors'])
                ? (is_string($decoded['errors']) ? $decoded['errors'] : json_encode($decoded['errors']))
                : 'HTTP ' . $code)
            : null,
    ];
}

/**
 * Verify the access token works against the Shopify store.
 * Returns ['ok' => bool, 'shop' => array|null, 'error' => string|null].
 *
 * Routes to GraphQL for atkn_ tokens (Dev Dashboard automation tokens —
 * they don't accept REST).
 */
function shopify_admin_verify(string $shop_url, string $token): array
{
    if (shopify_uses_graphql($token)) return shopify_graphql_verify($shop_url, $token);
    $r = shopify_admin_call('GET', shopify_admin_base($shop_url) . '/shop.json', $token);
    if ($r['error']) return ['ok' => false, 'shop' => null, 'error' => $r['error']];
    return ['ok' => true, 'shop' => $r['body']['shop'] ?? null, 'error' => null];
}

/**
 * List existing URL Redirects on the store. Paginated up to 250 per page.
 * Returns flat array of [{id, path, target}].
 */
function shopify_admin_list_redirects(string $shop_url, string $token, int $cap = 1000): array
{
    if (shopify_uses_graphql($token)) return shopify_graphql_list_redirects($shop_url, $token, $cap);
    $out = [];
    $url = shopify_admin_base($shop_url) . '/redirects.json?limit=250';
    while ($url && count($out) < $cap) {
        $r = shopify_admin_call('GET', $url, $token);
        if ($r['error']) break;
        foreach ($r['body']['redirects'] ?? [] as $row) {
            $out[] = ['id' => $row['id'] ?? null, 'path' => $row['path'] ?? '', 'target' => $row['target'] ?? ''];
        }
        // Shopify exposes pagination via Link header — Admin REST returns it.
        // For simplicity (and since v1 uses this for dedup not full sync), we
        // accept the first page worth. If user has >250 redirects already, the
        // dedup will be partial — push API still safely 422s on duplicates.
        $url = null;
        usleep(SHOPIFY_DELAY_US);
    }
    return $out;
}

/**
 * Create one URL Redirect. Returns ['success'=>bool, 'id'=>int|null, 'error'=>string|null].
 * If a redirect for $path already exists, Shopify returns 422; we surface that as 'duplicate'.
 */
function shopify_admin_create_redirect(string $shop_url, string $token, string $path, string $target): array
{
    if (shopify_uses_graphql($token)) return shopify_graphql_create_redirect($shop_url, $token, $path, $target);
    if ($path === '' || $target === '') return ['success' => false, 'id' => null, 'error' => 'path and target required'];
    $r = shopify_admin_call('POST', shopify_admin_base($shop_url) . '/redirects.json', $token, [
        'redirect' => ['path' => $path, 'target' => $target],
    ]);
    usleep(SHOPIFY_DELAY_US);
    if ($r['status'] >= 200 && $r['status'] < 300) {
        return ['success' => true, 'id' => $r['body']['redirect']['id'] ?? null, 'error' => null];
    }
    if ($r['status'] === 422) {
        // most common cause: path is already taken — could be an existing
        // redirect (idempotent fine) or a live page (would be a conflict).
        return ['success' => false, 'id' => null, 'error' => 'duplicate_or_conflict'];
    }
    return ['success' => false, 'id' => null, 'error' => $r['error'] ?: 'HTTP ' . $r['status']];
}

/**
 * Delete a redirect by id. Used when the user "reverts" an applied row.
 * For atkn_ token sites, $redirect_id may be a GID string passed as int-cast —
 * caller should pass the original string via shopify_admin_delete_redirect_any().
 */
function shopify_admin_delete_redirect(string $shop_url, string $token, int $redirect_id): array
{
    $r = shopify_admin_call('DELETE', shopify_admin_base($shop_url) . '/redirects/' . $redirect_id . '.json', $token);
    usleep(SHOPIFY_DELAY_US);
    if ($r['status'] === 200 || $r['status'] === 204) return ['success' => true];
    return ['success' => false, 'error' => $r['error'] ?: 'HTTP ' . $r['status']];
}

/**
 * Polymorphic delete — accepts either a numeric REST id or a GraphQL GID
 * string. Picks the right transport based on token prefix + id shape.
 */
function shopify_admin_delete_redirect_any(string $shop_url, string $token, $id): array
{
    if (shopify_uses_graphql($token)) {
        return shopify_graphql_delete_redirect($shop_url, $token, (string)$id);
    }
    return shopify_admin_delete_redirect($shop_url, $token, (int)$id);
}

/**
 * List published products (paginated up to $cap). Returns flat array of
 * { id, handle, title, vendor, type, status, variants_count, image }.
 *
 * Used by GMC Module 4 to audit product-level feed health and by future
 * "freshness" features (which product pages haven't been updated in months).
 */
function shopify_admin_list_products(string $shop_url, string $token, int $cap = 250): array
{
    $out = [];
    $url = shopify_admin_base($shop_url) . '/products.json?limit=250&status=active';
    while ($url && count($out) < $cap) {
        $r = shopify_admin_call('GET', $url, $token);
        if ($r['error']) break;
        foreach ($r['body']['products'] ?? [] as $p) {
            $out[] = [
                'id'       => $p['id'] ?? null,
                'handle'   => $p['handle'] ?? '',
                'title'    => $p['title'] ?? '',
                'vendor'   => $p['vendor'] ?? '',
                'type'     => $p['product_type'] ?? '',
                'status'   => $p['status'] ?? '',
                'variants_count' => isset($p['variants']) ? count($p['variants']) : 0,
                'image'    => $p['image']['src'] ?? null,
                'tags'     => $p['tags'] ?? '',
                'updated_at' => $p['updated_at'] ?? null,
                'first_variant' => $p['variants'][0] ?? null,
            ];
            if (count($out) >= $cap) break 2;
        }
        // Shopify pagination via Link header (omitted for brevity — one page is
        // enough for v1 audit; can extend with header parsing later).
        $url = null;
        usleep(SHOPIFY_DELAY_US);
    }
    return $out;
}

/** Single product detail — used by feed audit per-product fix generation. */
function shopify_admin_get_product(string $shop_url, string $token, int $product_id): ?array
{
    $r = shopify_admin_call('GET', shopify_admin_base($shop_url) . '/products/' . $product_id . '.json', $token);
    if ($r['error']) return null;
    return $r['body']['product'] ?? null;
}

/** List collections — covers both smart + custom. */
function shopify_admin_list_collections(string $shop_url, string $token, int $cap = 250): array
{
    $out = [];
    foreach (['custom_collections', 'smart_collections'] as $kind) {
        $r = shopify_admin_call('GET', shopify_admin_base($shop_url) . "/{$kind}.json?limit=250", $token);
        if ($r['error']) continue;
        foreach ($r['body'][$kind] ?? [] as $c) {
            $out[] = [
                'id'     => $c['id'] ?? null,
                'kind'   => $kind,
                'handle' => $c['handle'] ?? '',
                'title'  => $c['title'] ?? '',
            ];
            if (count($out) >= $cap) break 2;
        }
        usleep(SHOPIFY_DELAY_US);
    }
    return $out;
}

/** Set a metafield on a product — used by GMC fix push (custom_label, etc). */
function shopify_admin_set_product_metafield(string $shop_url, string $token, int $product_id, string $namespace, string $key, string $type, string $value): array
{
    $r = shopify_admin_call('POST', shopify_admin_base($shop_url) . '/products/' . $product_id . '/metafields.json', $token, [
        'metafield' => [
            'namespace' => $namespace, 'key' => $key,
            'type' => $type, 'value' => $value,
        ],
    ]);
    if ($r['status'] >= 200 && $r['status'] < 300) return ['success' => true, 'id' => $r['body']['metafield']['id'] ?? null];
    return ['success' => false, 'error' => $r['error'] ?: 'HTTP ' . $r['status']];
}

/**
 * Bulk-push approved redirects to a Shopify store. Returns counters.
 *
 * Used by the Module 3 "Apply to Shopify" action: pulls every redirect with
 * status='approved' from redirect_map, pushes via Admin API, updates the row
 * with applied_at + external_id on success, leaves applied=null on failure
 * so re-runs are safe (idempotent on duplicates).
 */
function shopify_admin_push_approved(PDO $db, int $site_id, string $shop_url, string $token, ?int $run_id = null, ?callable $progress = null): array
{
    $stmt = $db->prepare("SELECT id, from_path, to_path FROM redirect_map
                          WHERE site_id = ? AND status = 'approved' AND to_path IS NOT NULL
                          ORDER BY id");
    $stmt->execute([$site_id]);
    $rows = $stmt->fetchAll();

    $update = $db->prepare("UPDATE redirect_map SET
        status = 'applied', applied_at = NOW(), applied_via = 'shopify_api',
        external_id = ?, updated_at = NOW() WHERE id = ?");
    $mark_dupe = $db->prepare("UPDATE redirect_map SET
        notes = CONCAT(COALESCE(notes,''), '\n[shopify push] ', ?), updated_at = NOW() WHERE id = ?");

    // Progress is reported to redirect_runs row when $run_id is supplied,
    // so the UI can poll an existing row instead of needing a new table.
    $progress_update = $run_id
        ? $db->prepare("UPDATE redirect_runs SET items_processed = ?, items_succeeded = ?, items_failed = ? WHERE id = ?")
        : null;

    $pushed = 0; $duplicates = 0; $errors = 0; $i = 0;
    foreach ($rows as $r) {
        $i++;
        $res = shopify_admin_create_redirect($shop_url, $token, (string)$r['from_path'], (string)$r['to_path']);
        if (!empty($res['success'])) {
            $update->execute([$res['id'], (int)$r['id']]);
            $pushed++;
        } elseif (($res['error'] ?? '') === 'duplicate_or_conflict') {
            $update->execute([null, (int)$r['id']]);
            $mark_dupe->execute(['duplicate on Shopify (kept existing)', (int)$r['id']]);
            $duplicates++;
        } else {
            $mark_dupe->execute([$res['error'] ?? 'unknown', (int)$r['id']]);
            $errors++;
        }
        if ($progress_update && ($i % 25 === 0 || $i === count($rows))) {
            $progress_update->execute([$i, $pushed + $duplicates, $errors, $run_id]);
        }
        if ($progress) $progress(['processed' => $i, 'pushed' => $pushed, 'duplicates' => $duplicates, 'errors' => $errors, 'total' => count($rows)]);
    }
    return ['pushed' => $pushed, 'duplicates' => $duplicates, 'errors' => $errors, 'total' => count($rows)];
}
