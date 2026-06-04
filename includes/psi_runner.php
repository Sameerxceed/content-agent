<?php
/**
 * PageSpeed Insights baseline runner — captures performance + Core Web Vitals
 * snapshots across a curated set of URLs per site, weekly. Powers the Health
 * Report trend chart and surfaces regressions early.
 *
 * Provider: Google PSI v5 API. Free tier (no key) is rate-limited to ~25 req/day.
 * Production needs an API key from console.cloud.google.com (no billing required
 * for PSI usage at our volumes). Key lives in config under 'google_psi_api_key'.
 *
 * No vendor leaks — customer UI says "page speed" / "Core Web Vitals", not "PSI".
 */

require_once __DIR__ . '/helpers.php';

const PSI_ENDPOINT  = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
const PSI_TIMEOUT   = 90;     // per-call — PSI can be slow
const PSI_DELAY_US  = 1500000; // 1.5s between calls

/**
 * Auto-curate 10 baseline URLs per site from current_site_urls.
 * Priorities: home > top contact > top about > top collection > top product > top blog
 * Falls back to whatever we have if the site doesn't follow shop conventions.
 */
function psi_pick_baseline_urls(PDO $db, int $site_id, string $domain): array
{
    $stmt = $db->prepare("SELECT path, url_type, title FROM current_site_urls WHERE site_id = ? ORDER BY id");
    $stmt->execute([$site_id]);
    $by_type = ['home' => [], 'collection' => [], 'product' => [], 'page' => [], 'blog' => [], 'other' => []];
    foreach ($stmt->fetchAll() as $r) {
        $t = $r['url_type'] ?: 'other';
        if (!isset($by_type[$t])) $by_type[$t] = [];
        $by_type[$t][] = $r['path'];
    }

    $picks = [];
    $add = function (string $path, string $label) use (&$picks, $domain) {
        if (count($picks) >= 10) return;
        $url = 'https://' . $domain . ($path === '/' ? '' : $path);
        $picks[$url] = $label;
    };

    $add('/', 'Home');
    // contact + about if present
    foreach (['/contact', '/pages/contact', '/about', '/pages/about'] as $p) {
        foreach (array_merge($by_type['page'], $by_type['other']) as $existing) {
            if ($existing === $p) { $add($p, basename($p)); break; }
        }
    }
    foreach (array_slice($by_type['collection'], 0, 2) as $p) $add($p, 'Collection');
    foreach (array_slice($by_type['product'], 0, 2) as $p)    $add($p, 'Product');
    foreach (array_slice($by_type['blog'], 0, 2) as $p)       $add($p, 'Blog post');
    foreach (array_slice($by_type['page'], 0, 2) as $p)       $add($p, 'Page');
    foreach (array_slice($by_type['other'], 0, 5) as $p)      $add($p, 'Other');

    return $picks;
}

/**
 * Persist a baseline URL set into cwv_baseline_urls so the user can edit it.
 * Idempotent — same site_id+url rows are kept.
 */
function psi_seed_baseline_urls(PDO $db, int $site_id, array $url_map): int
{
    $upsert = $db->prepare("INSERT INTO cwv_baseline_urls (site_id, url, label, created_at)
                            VALUES (?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE label = VALUES(label)");
    $added = 0;
    foreach ($url_map as $url => $label) {
        try { $upsert->execute([$site_id, mb_substr($url, 0, 2048), mb_substr($label, 0, 120)]); $added++; }
        catch (Throwable $e) { error_log('[psi] seed: ' . $e->getMessage()); }
    }
    return $added;
}

/**
 * Call PSI for one URL + device. Returns parsed metrics.
 */
function psi_fetch(string $url, string $device = 'mobile'): array
{
    $key = config('google_psi_api_key');
    $params = [
        'url'        => $url,
        'strategy'   => $device,
        'category'   => 'performance', // SEO/accessibility/best-practices return at no extra cost but slow request — add later if user wants
    ];
    if ($key) $params['key'] = $key;
    $endpoint = PSI_ENDPOINT . '?' . http_build_query($params);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => PSI_TIMEOUT,
        CURLOPT_USERAGENT      => 'ContentAgent-PSI/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        return ['success' => false, 'error' => "PSI HTTP {$code}: " . substr((string)$body, 0, 200)];
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data)) return ['success' => false, 'error' => 'Unparseable PSI response'];

    $lh = $data['lighthouseResult'] ?? [];
    $audits = $lh['audits'] ?? [];
    $cats = $lh['categories'] ?? [];

    // Lighthouse (lab) metrics
    $lcp_ms = (int)($audits['largest-contentful-paint']['numericValue'] ?? 0);
    $cls_v  = (float)($audits['cumulative-layout-shift']['numericValue'] ?? 0);
    $fcp_ms = (int)($audits['first-contentful-paint']['numericValue'] ?? 0);
    $ttfb   = (int)($audits['server-response-time']['numericValue'] ?? 0);
    $inp_lab = (int)($audits['interactive']['numericValue'] ?? 0); // proxy when no field INP

    $perf_score = isset($cats['performance']['score'])
        ? (int)round($cats['performance']['score'] * 100)
        : null;

    // CrUX (field) data — real users of this URL/origin
    $crux       = $data['loadingExperience'] ?? [];
    $crux_metr  = $crux['metrics'] ?? [];
    $field_loading = $crux['overall_category'] ?? null;
    $field_lcp_ms = isset($crux_metr['LARGEST_CONTENTFUL_PAINT_MS']['percentile'])
        ? (int)$crux_metr['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] : null;
    $field_inp_ms = isset($crux_metr['INTERACTION_TO_NEXT_PAINT']['percentile'])
        ? (int)$crux_metr['INTERACTION_TO_NEXT_PAINT']['percentile'] : null;
    $field_cls = isset($crux_metr['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'])
        ? round($crux_metr['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] / 100, 3) : null;
    $lab_only = empty($field_loading);

    // top 3 opportunities for debugging
    $opps = [];
    foreach ($audits as $aid => $a) {
        if (($a['details']['type'] ?? '') === 'opportunity' && !empty($a['details']['overallSavingsMs'])) {
            $opps[] = ['id' => $aid, 'title' => $a['title'] ?? '', 'savings_ms' => (int)$a['details']['overallSavingsMs']];
        }
    }
    usort($opps, fn($a, $b) => $b['savings_ms'] <=> $a['savings_ms']);
    $top_opps = array_slice($opps, 0, 3);

    return [
        'success'      => true,
        'perf_score'   => $perf_score,
        'lcp_ms'       => $lcp_ms,
        'cls'          => round($cls_v, 3),
        'fcp_ms'       => $fcp_ms,
        'ttfb_ms'      => $ttfb,
        'inp_ms'       => $inp_lab ?: null,
        'field_loading' => $field_loading,
        'field_lcp_ms' => $field_lcp_ms,
        'field_inp_ms' => $field_inp_ms,
        'field_cls'    => $field_cls,
        'lab_only'     => $lab_only ? 1 : 0,
        'raw_excerpt'  => json_encode($top_opps),
    ];
}

/**
 * Run baseline for a site: fetches mobile + desktop for each URL in
 * cwv_baseline_urls and upserts a row in cwv_baseline keyed by date.
 *
 * Returns counters.
 */
function psi_run_baseline(PDO $db, int $site_id, ?array $url_override = null): array
{
    if ($url_override === null) {
        $stmt = $db->prepare("SELECT url, label FROM cwv_baseline_urls WHERE site_id = ? ORDER BY priority DESC, id");
        $stmt->execute([$site_id]);
        $urls = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } else {
        $urls = $url_override;
    }
    if (empty($urls)) return ['checked' => 0, 'success' => 0, 'errors' => 0, 'error' => 'no baseline URLs configured'];

    $today = date('Y-m-d');
    $upsert = $db->prepare("INSERT INTO cwv_baseline
        (site_id, url, device, snapshot_date, perf_score, lcp_ms, inp_ms, cls, fcp_ms, ttfb_ms,
         field_loading, field_lcp_ms, field_inp_ms, field_cls, lab_only, raw_excerpt, error, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            perf_score = VALUES(perf_score), lcp_ms = VALUES(lcp_ms), inp_ms = VALUES(inp_ms),
            cls = VALUES(cls), fcp_ms = VALUES(fcp_ms), ttfb_ms = VALUES(ttfb_ms),
            field_loading = VALUES(field_loading), field_lcp_ms = VALUES(field_lcp_ms),
            field_inp_ms = VALUES(field_inp_ms), field_cls = VALUES(field_cls),
            lab_only = VALUES(lab_only), raw_excerpt = VALUES(raw_excerpt), error = NULL");
    $err_upsert = $db->prepare("INSERT INTO cwv_baseline (site_id, url, device, snapshot_date, error, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE error = VALUES(error)");

    $checked = 0; $ok = 0; $errs = 0;
    foreach ($urls as $url => $label) {
        foreach (['mobile', 'desktop'] as $device) {
            $checked++;
            $r = psi_fetch($url, $device);
            if (!empty($r['success'])) {
                $upsert->execute([
                    $site_id, mb_substr($url, 0, 2048), $device, $today,
                    $r['perf_score'], $r['lcp_ms'], $r['inp_ms'], $r['cls'], $r['fcp_ms'], $r['ttfb_ms'],
                    $r['field_loading'], $r['field_lcp_ms'], $r['field_inp_ms'], $r['field_cls'],
                    $r['lab_only'], $r['raw_excerpt'],
                    null,
                ]);
                $ok++;
            } else {
                $err_upsert->execute([$site_id, mb_substr($url, 0, 2048), $device, $today, $r['error']]);
                $errs++;
            }
            usleep(PSI_DELAY_US);
        }
    }
    return ['checked' => $checked, 'success' => $ok, 'errors' => $errs];
}

/** Latest snapshot per URL+device — feeds the dashboard table. */
function psi_latest_per_url(PDO $db, int $site_id): array
{
    $stmt = $db->prepare("SELECT b.* FROM cwv_baseline b
        INNER JOIN (
            SELECT url, device, MAX(snapshot_date) AS d FROM cwv_baseline
            WHERE site_id = ? GROUP BY url, device
        ) latest ON latest.url = b.url AND latest.device = b.device AND latest.d = b.snapshot_date
        WHERE b.site_id = ? ORDER BY b.url, b.device");
    $stmt->execute([$site_id, $site_id]);
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['url']][$r['device']] = $r;
    return $out;
}

/** Site-wide rollup: counts of pages in each Core Web Vitals band, latest snapshot. */
function psi_site_summary(PDO $db, int $site_id): array
{
    $latest = psi_latest_per_url($db, $site_id);
    $mobile = ['good' => 0, 'needs_improvement' => 0, 'poor' => 0, 'no_data' => 0];
    $perf_scores = [];
    foreach ($latest as $url => $by_device) {
        $m = $by_device['mobile'] ?? null;
        if (!$m || !empty($m['error'])) { $mobile['no_data']++; continue; }
        if (!empty($m['perf_score'])) $perf_scores[] = (int)$m['perf_score'];
        $lcp = (int)($m['field_lcp_ms'] ?? $m['lcp_ms'] ?? 0);
        if ($lcp === 0)         $mobile['no_data']++;
        elseif ($lcp <= 2500)   $mobile['good']++;
        elseif ($lcp <= 4000)   $mobile['needs_improvement']++;
        else                    $mobile['poor']++;
    }
    return [
        'mobile' => $mobile,
        'avg_perf_mobile' => $perf_scores ? (int)round(array_sum($perf_scores) / count($perf_scores)) : null,
        'urls_tracked'    => count($latest),
    ];
}
