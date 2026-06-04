<?php
/**
 * Wayback CDX harvester — pulls every URL the Internet Archive has on record
 * for a domain, dedupes, and stores in `historical_urls`. Foundation for the
 * 301 redirect map builder (Module 3) — every dead URL here is a candidate
 * for redirect to a living target.
 *
 * Provider: CDX Server API at https://web.archive.org/cdx/search/cdx
 * Free, no auth, no key. We self-rate-limit (≥1s between calls) to be polite.
 * CDX paginates at ~150k rows per response; we follow `resumeKey` until done.
 *
 * Customer-facing copy never says "Wayback" — it's "archive history" or
 * "historical URLs we found in search engines" (no vendor leaks per
 * feedback_no_vendor_leaks.md).
 *
 * Heavy run: backgrounded via agent/cron-wayback-harvest.php --site=N
 * (NOT to be called from PHP-FPM — can take minutes for big stores).
 */

require_once __DIR__ . '/helpers.php';

const WAYBACK_CDX_URL  = 'https://web.archive.org/cdx/search/cdx';
const WAYBACK_DELAY_US = 1200000;  // 1.2s between page fetches — polite
const WAYBACK_TIMEOUT  = 60;       // per-request seconds
const WAYBACK_PAGE_SIZE = 5000;    // CDX `limit` per page

/**
 * Normalise a URL for dedup: lowercase host, strip default ports, strip
 * trailing slash on path (but not on bare host), strip url fragment.
 * Returns the normalised URL string. Used to compute url_hash.
 */
function wayback_normalise_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    // Reject obvious junk
    if (!preg_match('#^https?://#i', $url)) $url = 'http://' . ltrim($url, '/');
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return '';
    $scheme = strtolower($parts['scheme'] ?? 'http');
    $host   = strtolower($parts['host']);
    $path   = $parts['path'] ?? '';
    $query  = isset($parts['query']) ? '?' . $parts['query'] : '';
    // Strip default-port if present
    if (isset($parts['port']) && !in_array((int)$parts['port'], [80, 443], true)) {
        $host .= ':' . (int)$parts['port'];
    }
    if ($path !== '' && $path !== '/') $path = rtrim($path, '/');
    if ($path === '') $path = '/';
    return $scheme . '://' . $host . $path . $query;
}

/** Pull just the path portion (no query) for grouping in the UI. */
function wayback_path_only(string $normalised_url): string
{
    $p = parse_url($normalised_url, PHP_URL_PATH) ?: '/';
    return mb_substr($p, 0, 1024);
}

/**
 * Run one CDX page fetch. Returns ['rows' => [...], 'resumeKey' => string|null,
 * 'error' => string|null].
 *
 * CDX response is a JSON array of arrays. First row is the header row when we
 * pass `output=json`. Each data row is [urlkey, timestamp, original, mimetype,
 * statuscode, digest, length]. We filter to 200-only (skip 404 snapshots since
 * those don't represent URLs that ever worked) and dedupe by `original`.
 */
function wayback_fetch_page(string $domain, ?string $resume_key = null): array
{
    $params = [
        'url'        => $domain . '/*',
        'output'     => 'json',
        'fl'         => 'original,timestamp,statuscode,mimetype',
        'collapse'   => 'urlkey',           // server-side dedup by URL key
        'filter'     => 'statuscode:200',   // only snapshots that actually rendered
        'limit'      => WAYBACK_PAGE_SIZE,
        'showResumeKey' => 'true',
    ];
    if ($resume_key !== null && $resume_key !== '') {
        $params['resumeKey'] = $resume_key;
    }
    $url = WAYBACK_CDX_URL . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => WAYBACK_TIMEOUT,
        CURLOPT_USERAGENT      => 'ContentAgent-WaybackHarvester/1.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)                   return ['rows' => [], 'resumeKey' => null, 'error' => 'curl: ' . $err];
    if ($code < 200 || $code >= 300) return ['rows' => [], 'resumeKey' => null, 'error' => 'CDX HTTP ' . $code];

    $data = json_decode((string)$body, true);
    if (!is_array($data) || empty($data)) return ['rows' => [], 'resumeKey' => null, 'error' => null];

    // CDX puts a blank line + resumeKey at the end when more pages exist.
    // Pattern is: [..., [], ["AAAAresumeKey"]]. Pull it out before processing.
    $resume = null;
    $last = end($data);
    if (is_array($last) && count($last) === 1 && is_string($last[0])) {
        $resume = $last[0];
        array_pop($data);
        // remove the trailing blank separator row too if present
        $sep = end($data);
        if (is_array($sep) && empty($sep)) array_pop($data);
    }

    // First row is the header when output=json — drop it.
    if (!empty($data) && is_array($data[0]) && in_array('original', $data[0], true)) {
        array_shift($data);
    }

    return ['rows' => $data, 'resumeKey' => $resume, 'error' => null];
}

/**
 * Harvest the full archive history for a domain into `historical_urls`.
 * Returns ['urls_fetched' => N, 'urls_new' => N, 'pages' => N, 'error' => str|null].
 *
 * This is the main public entrypoint. Idempotent — re-running updates
 * first_seen / last_seen / snapshot_count without creating duplicates.
 */
function wayback_harvest_site(PDO $db, int $site_id, string $domain, ?callable $progress = null): array
{
    // Open a run record so the dashboard can show progress / history.
    $db->prepare("INSERT INTO wayback_runs (site_id, started_at, status) VALUES (?, NOW(), 'running')")
       ->execute([$site_id]);
    $run_id = (int)$db->lastInsertId();

    $domain = preg_replace('#^https?://#i', '', trim($domain));
    $domain = rtrim($domain, '/');
    if ($domain === '') {
        $db->prepare("UPDATE wayback_runs SET status='failed', finished_at=NOW(), error=? WHERE id=?")
           ->execute(['empty domain', $run_id]);
        return ['urls_fetched' => 0, 'urls_new' => 0, 'pages' => 0, 'error' => 'empty domain', 'run_id' => $run_id];
    }

    $upsert = $db->prepare("INSERT INTO historical_urls
        (site_id, url, url_hash, path, first_seen, last_seen, snapshot_count, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            first_seen = LEAST(first_seen, VALUES(first_seen)),
            last_seen  = GREATEST(last_seen,  VALUES(last_seen)),
            snapshot_count = snapshot_count + 1,
            updated_at = NOW()");

    $total_fetched = 0;
    $total_new     = 0;
    $pages         = 0;
    $resume_key    = null;
    $error         = null;

    do {
        $page = wayback_fetch_page($domain, $resume_key);
        $pages++;
        if ($page['error']) { $error = $page['error']; break; }

        foreach ($page['rows'] as $row) {
            // row = [original, timestamp, statuscode, mimetype]
            if (!is_array($row) || count($row) < 2) continue;
            $original_url = (string)$row[0];
            $ts_raw       = (string)$row[1];
            $mimetype     = $row[3] ?? '';
            // Skip non-html snapshots — assets/images/scripts inflate the table
            // without contributing redirect candidates.
            if ($mimetype !== '' && !str_starts_with($mimetype, 'text/html')) continue;

            $normalised = wayback_normalise_url($original_url);
            if ($normalised === '') continue;
            $hash = sha1($normalised);
            $path = wayback_path_only($normalised);
            // Parse Wayback timestamp YYYYMMDDhhmmss
            $ts_dt = wayback_parse_timestamp($ts_raw);

            $total_fetched++;
            try {
                $upsert->execute([$site_id, mb_substr($normalised, 0, 2048), $hash, $path, $ts_dt, $ts_dt]);
                if ((int)$db->lastInsertId() > 0) $total_new++;
            } catch (Throwable $e) {
                error_log('[wayback] upsert: ' . $e->getMessage());
            }
        }

        if ($progress) $progress([
            'page' => $pages, 'fetched' => $total_fetched, 'new' => $total_new, 'resume' => $resume_key !== null,
        ]);

        $resume_key = $page['resumeKey'];
        if ($resume_key !== null) usleep(WAYBACK_DELAY_US);
    } while ($resume_key !== null);

    $db->prepare("UPDATE wayback_runs SET
        status = ?, finished_at = NOW(),
        urls_fetched = ?, urls_new = ?, pages_paginated = ?, error = ?
        WHERE id = ?")
       ->execute([$error ? 'failed' : 'done', $total_fetched, $total_new, $pages, $error, $run_id]);

    return [
        'urls_fetched' => $total_fetched,
        'urls_new'     => $total_new,
        'pages'        => $pages,
        'error'        => $error,
        'run_id'       => $run_id,
    ];
}

/** Convert YYYYMMDDhhmmss → MySQL DATETIME. Returns null on bad input. */
function wayback_parse_timestamp(string $ts): ?string
{
    if (!preg_match('/^\d{14}$/', $ts)) return null;
    $y = substr($ts, 0, 4); $mo = substr($ts, 4, 2); $d = substr($ts, 6, 2);
    $h = substr($ts, 8, 2); $mi = substr($ts, 10, 2); $s = substr($ts, 12, 2);
    return "{$y}-{$mo}-{$d} {$h}:{$mi}:{$s}";
}

/**
 * Aggregate counts for a site — feeds the dashboard widget.
 */
function wayback_site_summary(PDO $db, int $site_id): array
{
    $stmt = $db->prepare("SELECT
        COUNT(*) AS total_urls,
        SUM(CASE WHEN is_dead = 1 THEN 1 ELSE 0 END) AS dead_urls,
        SUM(CASE WHEN current_checked_at IS NULL THEN 1 ELSE 0 END) AS unchecked
        FROM historical_urls WHERE site_id = ?");
    $stmt->execute([$site_id]);
    $row = $stmt->fetch() ?: [];

    $stmt = $db->prepare("SELECT started_at, finished_at, status, urls_fetched, urls_new, error
        FROM wayback_runs WHERE site_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$site_id]);
    $last_run = $stmt->fetch() ?: null;

    return [
        'total_urls' => (int)($row['total_urls'] ?? 0),
        'dead_urls'  => (int)($row['dead_urls'] ?? 0),
        'unchecked'  => (int)($row['unchecked'] ?? 0),
        'last_run'   => $last_run,
    ];
}
