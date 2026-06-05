<?php
/**
 * Google Search Console API client.
 *
 * Native pull replaces the paste-based seo_issue_parser flow for sites
 * that connect via OAuth. Uses the existing google.php OAuth handler —
 * the OAuth scope now includes webmasters.readonly.
 *
 * Two endpoints we hit:
 *   POST  /searchconsole/v1/sites/{siteUrl}/searchAnalytics/query
 *     — daily clicks/impressions/ctr/position by (page, query)
 *   POST  /v1/urlInspection/index:inspect
 *     — per-URL indexing status (coverage_state, indexing_state)
 *
 * Fetch cadence:
 *   - performance: daily cron, pulls yesterday's data (1-day delay is
 *     typical for GSC). Backfills last 30d on first connect.
 *   - index status: weekly cron, walks current_site_urls inventory.
 *
 * Rate limits (as of 2026-01): 1200 req/min/project, 6 QPS/property.
 * We sleep 300ms between calls to stay well under.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/integrations/google.php';

const GSC_BASE = 'https://searchconsole.googleapis.com';
const GSC_BACKFILL_DAYS = 30;

/**
 * Fetch performance rows for a single day for the GSC property.
 * Returns array of [page, query, clicks, impressions, ctr, position].
 */
function gsc_fetch_day(string $access_token, string $site_url, string $date): array
{
    $rows = [];
    $start = 0; $rowLimit = 25000;
    while (true) {
        $payload = json_encode([
            'startDate'  => $date,
            'endDate'    => $date,
            'dimensions' => ['page', 'query'],
            'rowLimit'   => $rowLimit,
            'startRow'   => $start,
        ]);
        $url = GSC_BASE . '/v1/sites/' . rawurlencode($site_url) . '/searchAnalytics/query';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            error_log('[gsc_api] HTTP ' . $code . ' for ' . $site_url . ' day ' . $date . ': ' . substr((string)$body, 0, 250));
            break;
        }
        $data = json_decode($body, true) ?: [];
        $batch = $data['rows'] ?? [];
        foreach ($batch as $r) {
            $rows[] = [
                'page'        => $r['keys'][0] ?? '',
                'query'       => $r['keys'][1] ?? '',
                'clicks'      => (int)($r['clicks'] ?? 0),
                'impressions' => (int)($r['impressions'] ?? 0),
                'ctr'         => (float)($r['ctr'] ?? 0),
                'position'    => (float)($r['position'] ?? 0),
            ];
        }
        if (count($batch) < $rowLimit) break;
        $start += $rowLimit;
    }
    return $rows;
}

/**
 * Pull yesterday's performance data + upsert. Optionally backfill `$days_back`
 * days on first connect.
 */
function gsc_fetch_performance(PDO $db, int $site_id, int $days_back = 1): array
{
    $token = google_get_token($db, $site_id);
    if (!$token) return ['success' => false, 'error' => 'No active Google integration on this site'];

    $stmt = $db->prepare("SELECT i.account_id AS gsc_site, s.domain
        FROM integrations i JOIN sites s ON s.id = i.site_id
        WHERE i.site_id = ? AND i.platform = 'google_search_console' AND i.is_active = 1");
    $stmt->execute([$site_id]);
    $r = $stmt->fetch();
    if (!$r) return ['success' => false, 'error' => 'GSC site not selected'];
    $gsc_site = $r['gsc_site'] ?: ('sc-domain:' . $r['domain']);

    $upsert = $db->prepare("INSERT INTO gsc_metrics_daily
        (site_id, metric_date, page, page_hash, query, query_hash,
         clicks, impressions, ctr, position, fetched_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            clicks = VALUES(clicks),
            impressions = VALUES(impressions),
            ctr = VALUES(ctr),
            position = VALUES(position),
            fetched_at = NOW()");

    $days = max(1, min(90, $days_back));
    $total_rows = 0;
    for ($i = 1; $i <= $days; $i++) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $rows = gsc_fetch_day($token, $gsc_site, $date);
        foreach ($rows as $row) {
            if ($row['page'] === '' || $row['query'] === '') continue;
            $upsert->execute([
                $site_id, $date,
                $row['page'], sha1($row['page']),
                $row['query'], sha1($row['query']),
                $row['clicks'], $row['impressions'], $row['ctr'], $row['position'],
                date('Y-m-d H:i:s'),
            ]);
            $total_rows++;
        }
        usleep(300_000);
    }
    return ['success' => true, 'days_fetched' => $days, 'rows_upserted' => $total_rows];
}

/**
 * Pull index inspection status for one URL.
 */
function gsc_inspect_url(string $access_token, string $site_url, string $page_url): ?array
{
    $payload = json_encode([
        'inspectionUrl' => $page_url,
        'siteUrl'       => $site_url,
    ]);
    $ch = curl_init(GSC_BASE . '/v1/urlInspection/index:inspect');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $data = json_decode($body, true) ?: [];
    return $data['inspectionResult'] ?? null;
}

function gsc_site_summary(PDO $db, int $site_id, int $days = 28): array
{
    $stmt = $db->prepare("SELECT
            COUNT(DISTINCT page_hash) AS pages,
            COUNT(DISTINCT query_hash) AS queries,
            SUM(clicks) AS total_clicks,
            SUM(impressions) AS total_imps,
            MAX(metric_date) AS last_date
        FROM gsc_metrics_daily
        WHERE site_id = ? AND metric_date > DATE_SUB(CURDATE(), INTERVAL ? DAY)");
    $stmt->execute([$site_id, $days]);
    $row = $stmt->fetch() ?: [];
    return [
        'pages'    => (int)($row['pages']    ?? 0),
        'queries'  => (int)($row['queries']  ?? 0),
        'clicks'   => (int)($row['total_clicks'] ?? 0),
        'imps'     => (int)($row['total_imps']   ?? 0),
        'last_date'=> $row['last_date'] ?? null,
    ];
}
