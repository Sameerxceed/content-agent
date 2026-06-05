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
require_once __DIR__ . '/haiku.php';

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

/**
 * Generate a Claude-proposed fix for ONE product's outstanding GMC issues.
 *
 * Returns:
 *   {
 *     success: bool,
 *     fixes: [ { field, old_value, new_value, addresses_issue_codes: [...], reason } ],
 *     unfixable: [ { issue_code, reason } ],   // issues that need human intervention
 *     error?: string
 *   }
 *
 * Claude is grounded in:
 *   - The product row (title, link, image, price, availability, condition)
 *   - The list of unresolved gmc_issues for this product (code, severity,
 *     description, Google's documentation link)
 *
 * Claude returns a list of (field, new_value) pairs the merchant can apply.
 * Applying them is a SEPARATE step (gmc_apply_fix) because for Shopify-
 * connected sites we push via metafields; for others the merchant edits
 * their feed directly.
 */
function gmc_generate_fix(PDO $db, int $site_id, string $merchant_id, string $product_id): array
{
    $stmt = $db->prepare("SELECT * FROM gmc_products WHERE site_id = ? AND merchant_id = ? AND product_id = ?");
    $stmt->execute([$site_id, $merchant_id, $product_id]);
    $product = $stmt->fetch();
    if (!$product) return ['success' => false, 'error' => 'Product not found'];

    $stmt = $db->prepare("SELECT * FROM gmc_issues
        WHERE site_id = ? AND merchant_id = ? AND product_id = ? AND resolved_at IS NULL
        ORDER BY FIELD(severity, 'error','warning','suggestion')");
    $stmt->execute([$site_id, $merchant_id, $product_id]);
    $issues = $stmt->fetchAll();
    if (empty($issues)) return ['success' => false, 'error' => 'No unresolved issues on this product'];

    $issue_lines = [];
    foreach ($issues as $i => $iss) {
        $issue_lines[] = ($i + 1) . ". [{$iss['severity']}] {$iss['issue_code']}\n"
            . "   Google says: " . ($iss['description'] ?? '(no description)') . "\n"
            . ($iss['detail'] ? "   Detail: {$iss['detail']}\n" : '')
            . ($iss['documentation'] ? "   Docs: {$iss['documentation']}\n" : '');
    }

    $product_snapshot = [
        'product_id'   => $product['product_id'],
        'title'        => $product['title'],
        'link'         => $product['link'],
        'image_link'   => $product['image_link'],
        'price'        => $product['price'],
        'availability' => $product['availability'],
        'condition'    => $product['condition_state'],
    ];

    $system = "You are a Google Merchant Center feed specialist. The merchant has a product with one or more issues flagged by Google. Propose specific, applicable field changes that would resolve the issues — ONLY for fields you can actually correct from the information given.\n\n"
        . "OUTPUT — strict JSON:\n"
        . "{\n"
        . "  \"fixes\": [\n"
        . "    {\n"
        . "      \"field\": \"title|description|gtin|mpn|brand|google_product_category|image_link|availability|condition|...\",\n"
        . "      \"old_value\": \"current value (or null if not set)\",\n"
        . "      \"new_value\": \"the corrected value\",\n"
        . "      \"addresses_issue_codes\": [\"issue_code_1\", ...],\n"
        . "      \"reason\": \"one short sentence explaining why this fixes the issue\"\n"
        . "    }\n"
        . "  ],\n"
        . "  \"unfixable\": [ { \"issue_code\": \"...\", \"reason\": \"why a fix needs human action (e.g. need to source GTIN from manufacturer)\" } ]\n"
        . "}\n\n"
        . "Rules:\n"
        . "- NEVER invent identifiers (GTIN, MPN, brand) you don't have. Put those in unfixable.\n"
        . "- If the issue is content-quality (title too short, missing keywords), propose a corrected title.\n"
        . "- If the issue is policy (restricted product), put in unfixable with a clear reason.\n"
        . "- If you can't tell from the data what's wrong, put in unfixable.\n"
        . "- Skip fixes you're <70% confident about. Conservative > wrong.";

    $user = "PRODUCT SNAPSHOT:\n" . json_encode($product_snapshot, JSON_PRETTY_PRINT) . "\n\n"
        . "UNRESOLVED ISSUES:\n" . implode("\n", $issue_lines);

    $resp = haiku_chat($system, $user, 2000, 'gmc_fix_generator', $site_id);
    if (empty($resp['success'])) return ['success' => false, 'error' => 'Claude error: ' . ($resp['error'] ?? 'unknown')];

    $txt = trim($resp['content']);
    $txt = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $txt);
    $j = json_decode($txt, true);
    if (!is_array($j) && preg_match('/\{[\s\S]*\}/', $txt, $m)) $j = json_decode($m[0], true);
    if (!is_array($j)) return ['success' => false, 'error' => 'Unparseable Claude output'];

    return [
        'success'   => true,
        'fixes'     => array_values((array)($j['fixes']     ?? [])),
        'unfixable' => array_values((array)($j['unfixable'] ?? [])),
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
