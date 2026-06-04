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
 */
function shopify_admin_verify(string $shop_url, string $token): array
{
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
 */
function shopify_admin_delete_redirect(string $shop_url, string $token, int $redirect_id): array
{
    $r = shopify_admin_call('DELETE', shopify_admin_base($shop_url) . '/redirects/' . $redirect_id . '.json', $token);
    usleep(SHOPIFY_DELAY_US);
    if ($r['status'] === 200 || $r['status'] === 204) return ['success' => true];
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
function shopify_admin_push_approved(PDO $db, int $site_id, string $shop_url, string $token): array
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

    $pushed = 0; $duplicates = 0; $errors = 0;
    foreach ($rows as $r) {
        $res = shopify_admin_create_redirect($shop_url, $token, (string)$r['from_path'], (string)$r['to_path']);
        if (!empty($res['success'])) {
            $update->execute([$res['id'], (int)$r['id']]);
            $pushed++;
        } elseif (($res['error'] ?? '') === 'duplicate_or_conflict') {
            // Mark as applied anyway — Shopify already has a redirect on this path
            $update->execute([null, (int)$r['id']]);
            $mark_dupe->execute(['duplicate on Shopify (kept existing)', (int)$r['id']]);
            $duplicates++;
        } else {
            $mark_dupe->execute([$res['error'] ?? 'unknown', (int)$r['id']]);
            $errors++;
        }
    }
    return ['pushed' => $pushed, 'duplicates' => $duplicates, 'errors' => $errors, 'total' => count($rows)];
}
