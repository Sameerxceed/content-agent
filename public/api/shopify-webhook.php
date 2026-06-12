<?php
/**
 * Shopify webhook endpoint — handles BOTH the mandatory GDPR webhooks AND
 * the app/uninstalled webhook. Single endpoint dispatched by topic header.
 *
 * Endpoint URL (configure once in Partner Dashboard → App setup):
 *   https://contentagent.xceedtech.in/api/shopify-webhook.php
 *
 * Mandatory GDPR webhooks (Shopify enforces these for App Store approval):
 *   - customers/data_request → merchant requests a customer's data
 *   - customers/redact       → merchant requests a customer be forgotten
 *   - shop/redact            → 48h after app uninstall, scrub everything
 *
 * Plus:
 *   - app/uninstalled        → fire on uninstall, delete OAuth token immediately
 *
 * Auth: Shopify signs every webhook with an HMAC-SHA256 of the raw body using
 * the app's client_secret. We MUST verify before doing anything with the
 * payload. The signature is in the X-Shopify-Hmac-Sha256 header (base64).
 *
 * Why this is mostly no-op for ContentAgent:
 * ContentAgent reads merchant-side data only (products, themes, redirects,
 * content). We do NOT store any customer PII (no order details, no shopper
 * names, no addresses, no emails of shoppers). So customers/data_request
 * and customers/redact have nothing to return or delete on our side. We
 * still respond 200 OK and log the event for audit purposes — required
 * for App Store approval.
 *
 * shop/redact is meaningful: 48h after uninstall, Shopify expects all
 * shop-related data to be deleted. We trigger our own delete cascade.
 */

require_once __DIR__ . '/../../includes/helpers.php';

// Read raw body BEFORE any header() calls that might consume it.
$raw_body = file_get_contents('php://input');

$topic     = (string)($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '');
$shop      = (string)($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? '');
$hmac_sent = (string)($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '');

// --- HMAC verification ------------------------------------------------------
$secret = (string)config('shopify_client_secret', '');
if (!$secret || !$hmac_sent || !$raw_body) {
    http_response_code(401);
    error_log('[shopify-webhook] rejected: missing secret/hmac/body');
    exit('Unauthorized');
}
$hmac_calc = base64_encode(hash_hmac('sha256', $raw_body, $secret, true));
if (!hash_equals($hmac_calc, $hmac_sent)) {
    http_response_code(401);
    error_log('[shopify-webhook] rejected: HMAC mismatch (shop=' . $shop . ', topic=' . $topic . ')');
    exit('Unauthorized');
}

$payload = json_decode($raw_body, true) ?: [];

$db = require __DIR__ . '/../../includes/db.php';

// Log every authenticated webhook for audit (Shopify reviewers will check this).
$log_stmt = $db->prepare("INSERT INTO agent_log (site_id, action, details, status)
                          VALUES (?, ?, ?, ?)");

// Try to resolve site_id from the shop domain so we can scope deletions.
$site_id = 0;
if ($shop) {
    $row = $db->prepare("SELECT id FROM sites WHERE cms_url LIKE ? OR domain LIKE ? LIMIT 1");
    $row->execute(['%' . $shop . '%', '%' . $shop . '%']);
    $site_id = (int)($row->fetchColumn() ?: 0);
}

// --- Topic dispatch ---------------------------------------------------------
switch ($topic) {

    case 'customers/data_request':
        // Merchant has asked us to provide data we hold about a customer.
        // ContentAgent holds NO shopper PII — we operate on store-side data
        // only. Respond 200 and log; nothing to deliver.
        $log_stmt->execute([
            $site_id ?: null,
            'shopify_gdpr_data_request',
            'GDPR data request received for shop ' . $shop . '. ContentAgent stores no customer PII — no data to deliver. Payload: ' . substr($raw_body, 0, 500),
            'success',
        ]);
        http_response_code(200);
        echo json_encode(['status' => 'no_customer_data_held']);
        exit;

    case 'customers/redact':
        // Merchant has asked us to delete data about a specific customer.
        // Again — we hold no shopper PII. Acknowledge and log.
        $log_stmt->execute([
            $site_id ?: null,
            'shopify_gdpr_customer_redact',
            'GDPR customer redact received for shop ' . $shop . '. ContentAgent stores no customer PII — nothing to delete. Payload: ' . substr($raw_body, 0, 500),
            'success',
        ]);
        http_response_code(200);
        echo json_encode(['status' => 'no_customer_data_held']);
        exit;

    case 'shop/redact':
        // 48 hours after the merchant uninstalled the app, Shopify asks us
        // to delete all data related to the shop. This is the real cleanup.
        if ($site_id) {
            // Cascade deletes — keep this list in sync with any new
            // per-site tables we add to the schema.
            // Verified against e:/Xceed/Code/ContentAgent/database/migrations/.
            // try/catch below handles any future renames gracefully.
            $tables = [
                'redirect_map', 'redirect_runs', 'historical_urls', 'current_site_urls',
                'wayback_runs', 'posts', 'post_channels', 'post_performance', 'newsletters',
                'social_posts', 'subscribers', 'keywords', 'content_plans',
                'content_plan_items', 'content_plan_clusters', 'content_plan_cluster_keywords',
                'plan_reviews', 'plan_drift_log', 'aeo_queries', 'aeo_results',
                'ai_recall_snapshots', 'ai_visibility_snapshots', 'ai_presence_content',
                'brand_mentions', 'competitors', 'competitor_pages', 'competitor_keyword_rankings',
                'content_gaps', 'gap_runs', 'legal_docs', 'seo_audits', 'seo_issues',
                'schema_audits', 'image_audits', 'outbound_links', 'content_freshness',
                'gmc_issues', 'gmc_products', 'gsc_metrics_daily', 'gsc_index_status',
                'cwv_baseline', 'cwv_baseline_urls', 'performance_actions',
                'integrations', 'integration_setup_progress', 'agent_runs', 'agent_log',
                'cron_schedules', 'cron_runs', 'ai_calls', 'alerts',
            ];
            foreach ($tables as $t) {
                try {
                    $db->prepare("DELETE FROM `{$t}` WHERE site_id = ?")->execute([$site_id]);
                } catch (Throwable $e) {
                    // Table may not exist in older deployments — log + continue.
                    error_log('[shopify-webhook] shop/redact skipped table ' . $t . ': ' . $e->getMessage());
                }
            }
            // Finally the site row itself (keep an audit trail row first).
            $log_stmt->execute([
                $site_id,
                'shopify_gdpr_shop_redact',
                'GDPR shop redact: deleted all data for shop ' . $shop . ' (site_id=' . $site_id . ')',
                'success',
            ]);
            $db->prepare("DELETE FROM sites WHERE id = ?")->execute([$site_id]);
        } else {
            error_log('[shopify-webhook] shop/redact for unknown shop: ' . $shop);
        }
        http_response_code(200);
        echo json_encode(['status' => 'shop_data_deleted']);
        exit;

    case 'app/uninstalled':
        // Merchant uninstalled the app. Revoke the OAuth token immediately
        // (don't wait for shop/redact 48h later — the token is useless now
        // anyway, and keeping it around is bad hygiene).
        if ($site_id) {
            $db->prepare("UPDATE sites SET cms_api_key = NULL, updated_at = NOW() WHERE id = ?")
               ->execute([$site_id]);
            $log_stmt->execute([
                $site_id,
                'shopify_app_uninstalled',
                'App uninstalled by merchant. OAuth token revoked. shop=' . $shop,
                'success',
            ]);
        }
        http_response_code(200);
        echo json_encode(['status' => 'uninstall_acknowledged']);
        exit;

    default:
        // Unknown topic — log + 200 (don't 4xx, Shopify will retry forever).
        error_log('[shopify-webhook] unknown topic: ' . $topic . ' from shop=' . $shop);
        http_response_code(200);
        echo json_encode(['status' => 'topic_not_handled', 'topic' => $topic]);
        exit;
}
