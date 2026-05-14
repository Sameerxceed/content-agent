<?php
/**
 * Daily brand-mention scan.
 *
 * For each site, query Google CSE for the site's brand name (and a couple of
 * variants) over the last 24 hours. New mentions land in brand_mentions and
 * raise an alert.
 *
 * Run via: cron-runner.php brand-monitor
 */

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

if (empty(config('google_cse_api_key')) || empty(config('google_cse_cx'))) {
    echo "Skipped: Google CSE not configured\n";
    return;
}

$sites = cron_get_sites($db, $site_id_filter);
echo "Brand monitor scanning " . count($sites) . " sites\n";

$api_key = config('google_cse_api_key');
$cx      = config('google_cse_cx');

foreach ($sites as $site) {
    $sid = (int)$site['id'];

    // Build search queries — brand name + domain, but exclude own site
    $brand   = trim($site['name']);
    $domain  = preg_replace('#^(https?://)?(www\.)?#i', '', strtolower($site['domain']));
    if (mb_strlen($brand) < 3) {
        echo "  skip site #{$sid}: brand name too short to be specific\n";
        continue;
    }

    $queries = [
        "\"{$brand}\" -site:{$domain}",
    ];

    echo "  scanning #{$sid} '{$brand}'...\n";

    cron_run_site_job($db, $sid, $job_type, function ($run_id) use ($db, $sid, $domain, $queries, $api_key, $cx) {
        $new_mentions = 0;
        $samples = [];

        foreach ($queries as $q) {
            $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
                'key' => $api_key, 'cx' => $cx, 'q' => $q, 'num' => 10,
                'dateRestrict' => 'd1',  // last 24h
            ]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'ContentAgent/1.0',
            ]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status !== 200) continue;

            $data = json_decode($body, true);
            $stmt = $db->prepare('INSERT IGNORE INTO brand_mentions (site_id, source_domain, url, title, snippet, status, found_at) VALUES (?, ?, ?, ?, ?, "new", NOW())');

            foreach ($data['items'] ?? [] as $item) {
                $mention_url = $item['link'] ?? '';
                if (!$mention_url) continue;
                $host = preg_replace('#^www\.#', '', strtolower(parse_url($mention_url, PHP_URL_HOST) ?? ''));
                if (!$host || $host === $domain || str_ends_with($host, '.' . $domain)) continue;

                $stmt->execute([
                    $sid,
                    $host,
                    mb_substr($mention_url, 0, 2048),
                    mb_substr($item['title'] ?? '', 0, 500),
                    mb_substr(strip_tags($item['snippet'] ?? ''), 0, 1000),
                ]);
                if ($stmt->rowCount() > 0) {
                    $new_mentions++;
                    if (count($samples) < 5) {
                        $samples[] = $host . ' — ' . mb_substr($item['title'] ?? '', 0, 80);
                    }
                }
            }
        }

        if ($new_mentions > 0) {
            alert_create($db, $sid, 'brand_mention',
                "{$new_mentions} new brand mention" . ($new_mentions > 1 ? 's' : '') . ' found',
                implode("\n", $samples),
                '/dashboard/brand-mentions.php?site=' . $sid,
                'info',
                ['count' => $new_mentions]
            );
        }

        return ['new_mentions' => $new_mentions];
    });
}

echo "Brand monitor complete.\n";
