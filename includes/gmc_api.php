<?php
/**
 * Google Merchant Center Content API client.
 *
 * Pulls product diagnostics so the merchant can see what's wrong with
 * their feed (missing GTIN, invalid prices, mismatched availability,
 * image issues, policy violations) and fix per-product instead of
 * blindly re-syncing the whole feed.
 *
 * OAuth scope: https://www.googleapis.com/auth/content
 * (added to google.php — same Google integration row, just a wider scope).
 *
 * Endpoints used:
 *   GET  /content/v2.1/{merchantId}/products
 *   GET  /content/v2.1/{merchantId}/productstatuses
 *
 * The fix generator (per-product fix proposals via Claude) lands on top
 * of this — it reads gmc_issues, sends them to Claude with the product
 * row, and produces a corrected fields JSON the merchant can apply via
 * Shopify metafields (Module 1 push already exists).
 *
 * v1 focus: list + diagnostics. Fix generator is a follow-up.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/integrations/google.php';

const GMC_BASE = 'https://shoppingcontent.googleapis.com/content/v2.1';

function gmc_list_merchants(string $access_token): array
{
    $ch = curl_init(GMC_BASE . '/accounts/authinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return [];
    $data = json_decode($body, true) ?: [];
    $out = [];
    foreach ($data['accountIdentifiers'] ?? [] as $a) {
        if (!empty($a['merchantId']))    $out[] = ['id' => $a['merchantId'],    'role' => 'merchant'];
        if (!empty($a['aggregatorId']))  $out[] = ['id' => $a['aggregatorId'],  'role' => 'aggregator'];
    }
    return $out;
}

function gmc_list_products(string $access_token, string $merchant_id, ?string $page_token = null): array
{
    $url = GMC_BASE . '/' . rawurlencode($merchant_id) . '/products?maxResults=250'
         . ($page_token ? '&pageToken=' . rawurlencode($page_token) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        error_log('[gmc_api list_products] HTTP ' . $code . ': ' . substr((string)$body, 0, 250));
        return ['resources' => [], 'nextPageToken' => null];
    }
    return json_decode($body, true) ?: ['resources' => [], 'nextPageToken' => null];
}

function gmc_list_product_statuses(string $access_token, string $merchant_id, ?string $page_token = null): array
{
    $url = GMC_BASE . '/' . rawurlencode($merchant_id) . '/productstatuses?maxResults=250'
         . ($page_token ? '&pageToken=' . rawurlencode($page_token) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        error_log('[gmc_api product_statuses] HTTP ' . $code . ': ' . substr((string)$body, 0, 250));
        return ['resources' => [], 'nextPageToken' => null];
    }
    return json_decode($body, true) ?: ['resources' => [], 'nextPageToken' => null];
}

/**
 * Sync products + per-product diagnostics for one site's merchant account.
 * Idempotent — upserts on (site, merchant, product_id).
 */
function gmc_audit_site(PDO $db, int $site_id, string $merchant_id): array
{
    $token = google_get_token($db, $site_id);
    if (!$token) return ['success' => false, 'error' => 'No active Google integration on this site'];

    // Pass 1 — pull product catalogue
    $product_upsert = $db->prepare("INSERT INTO gmc_products
        (site_id, merchant_id, product_id, offer_id, title, link, image_link,
         price, availability, condition_state, issue_count, last_fetched_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
        ON DUPLICATE KEY UPDATE
            offer_id = VALUES(offer_id),
            title = VALUES(title),
            link = VALUES(link),
            image_link = VALUES(image_link),
            price = VALUES(price),
            availability = VALUES(availability),
            condition_state = VALUES(condition_state),
            last_fetched_at = NOW()");

    $count = 0;
    $page_token = null;
    do {
        $page = gmc_list_products($token, $merchant_id, $page_token);
        foreach ($page['resources'] ?? [] as $p) {
            $price = isset($p['price']['value']) ? ($p['price']['value'] . ' ' . ($p['price']['currency'] ?? '')) : null;
            $product_upsert->execute([
                $site_id, $merchant_id,
                (string)$p['id'],
                $p['offerId'] ?? null,
                $p['title'] ?? null,
                $p['link'] ?? null,
                $p['imageLink'] ?? null,
                $price, $p['availability'] ?? null, $p['condition'] ?? null,
            ]);
            $count++;
        }
        $page_token = $page['nextPageToken'] ?? null;
        if ($page_token) usleep(200_000);
    } while ($page_token);

    // Pass 2 — pull diagnostics + write per-product issues
    $issue_upsert = $db->prepare("INSERT INTO gmc_issues
        (site_id, merchant_id, product_id, issue_code, severity, destination,
         description, detail, documentation, detected_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            severity = VALUES(severity),
            destination = VALUES(destination),
            description = VALUES(description),
            detail = VALUES(detail),
            documentation = VALUES(documentation),
            detected_at = NOW(),
            resolved_at = NULL");

    $count_per_product = $db->prepare("UPDATE gmc_products SET issue_count = (
        SELECT COUNT(*) FROM gmc_issues
        WHERE site_id = ? AND merchant_id = ? AND product_id = gmc_products.product_id AND resolved_at IS NULL
    ) WHERE site_id = ? AND merchant_id = ? AND product_id = ?");

    $total_issues = 0; $audited = 0;
    $page_token = null;
    do {
        $page = gmc_list_product_statuses($token, $merchant_id, $page_token);
        foreach ($page['resources'] ?? [] as $st) {
            $pid = (string)($st['productId'] ?? '');
            if ($pid === '') continue;
            foreach ($st['itemLevelIssues'] ?? [] as $iss) {
                $issue_upsert->execute([
                    $site_id, $merchant_id, $pid,
                    (string)($iss['code'] ?? 'unknown'),
                    strtolower((string)($iss['servability'] ?? 'warning')) === 'disapproved' ? 'error'
                        : (strtolower((string)($iss['resolution'] ?? '')) === 'merchant_action' ? 'warning' : 'suggestion'),
                    $iss['destination'] ?? null,
                    $iss['description'] ?? null,
                    $iss['detail'] ?? null,
                    $iss['documentation'] ?? null,
                ]);
                $total_issues++;
            }
            $count_per_product->execute([$site_id, $merchant_id, $site_id, $merchant_id, $pid]);
            $audited++;
        }
        $page_token = $page['nextPageToken'] ?? null;
        if ($page_token) usleep(200_000);
    } while ($page_token);

    return [
        'success'         => true,
        'products_synced' => $count,
        'products_audited'=> $audited,
        'issues_found'    => $total_issues,
    ];
}

function gmc_site_summary(PDO $db, int $site_id): array
{
    $stmt = $db->prepare("SELECT COUNT(*) AS products,
            SUM(CASE WHEN issue_count > 0 THEN 1 ELSE 0 END) AS with_issues,
            MAX(last_fetched_at) AS last_fetched
        FROM gmc_products WHERE site_id = ?");
    $stmt->execute([$site_id]);
    $p = $stmt->fetch() ?: [];

    $stmt = $db->prepare("SELECT severity, COUNT(*) AS cnt FROM gmc_issues
        WHERE site_id = ? AND resolved_at IS NULL GROUP BY severity");
    $stmt->execute([$site_id]);
    $by_sev = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'products'    => (int)($p['products'] ?? 0),
        'with_issues' => (int)($p['with_issues'] ?? 0),
        'errors'      => (int)($by_sev['error'] ?? 0),
        'warnings'    => (int)($by_sev['warning'] ?? 0),
        'suggestions' => (int)($by_sev['suggestion'] ?? 0),
        'last_fetched'=> $p['last_fetched'] ?? null,
    ];
}
