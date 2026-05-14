<?php
/**
 * Daily check for new competitor blog/marketing posts.
 *
 * For every active competitor of every site, fetch the sitemap and compare
 * against URLs we've already recorded in competitor_pages. New URLs become alerts.
 *
 * We DO NOT re-scrape/extract topics here (that's the monthly gap-analysis job).
 * This is the lightweight "they published something" watcher.
 *
 * Run via: cron-runner.php competitor-pages-check
 */

require_once __DIR__ . '/../includes/scraper.php';

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

$sites = cron_get_sites($db, $site_id_filter);
echo "Checking competitor new posts across " . count($sites) . " sites\n";

foreach ($sites as $site) {
    $sid = (int)$site['id'];

    $stmt = $db->prepare("SELECT * FROM competitors WHERE site_id = ? AND status = 'active' ORDER BY shared_keywords DESC LIMIT 10");
    $stmt->execute([$sid]);
    $competitors = $stmt->fetchAll();
    if (empty($competitors)) continue;

    echo "  site #{$sid} ({$site['domain']}) - checking " . count($competitors) . " competitors\n";

    cron_run_site_job($db, $sid, $job_type, function ($run_id) use ($db, $sid, $competitors) {
        $total_new = 0;
        $new_by_competitor = [];

        foreach ($competitors as $comp) {
            $cid = (int)$comp['id'];
            $domain = $comp['domain'];

            // Known URLs we've already seen
            $stmt = $db->prepare('SELECT url FROM competitor_pages WHERE competitor_id = ?');
            $stmt->execute([$cid]);
            $known = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

            // Fetch sitemap candidates
            $base = 'https://' . preg_replace('#^https?://#', '', $domain);
            $base = rtrim($base, '/');
            $found_urls = [];

            foreach (['/sitemap.xml', '/sitemap_index.xml', '/sitemap-index.xml'] as $path) {
                $res = scraper_fetch($base . $path, 8);
                if ($res['status'] !== 200 || empty($res['body'])) continue;
                $urls = _competitor_pages_check_parse_sitemap($res['body'], $base, 60);
                if (!empty($urls)) { $found_urls = $urls; break; }
            }

            // Compare
            $new = [];
            foreach ($found_urls as $u) {
                if (!isset($known[$u])) $new[] = $u;
            }
            if (empty($new)) continue;

            // Cap at 10 new per competitor per day
            $new = array_slice($new, 0, 10);

            // Insert as page stubs (no topic yet — gap analysis fills that monthly)
            $stmt = $db->prepare('INSERT IGNORE INTO competitor_pages (competitor_id, url, scraped_at) VALUES (?, ?, NOW())');
            foreach ($new as $u) {
                $stmt->execute([$cid, mb_substr($u, 0, 2048)]);
            }

            $total_new += count($new);
            $new_by_competitor[$domain] = count($new);
        }

        // Alert if we found new content
        if ($total_new > 0) {
            $lines = [];
            foreach ($new_by_competitor as $d => $c) $lines[] = "{$d}: {$c} new page" . ($c > 1 ? 's' : '');
            alert_create($db, $sid, 'competitor_post',
                $total_new . ' new competitor page' . ($total_new > 1 ? 's' : '') . ' published',
                implode("\n", $lines),
                '/dashboard/competitors.php?site=' . $sid,
                'info',
                ['by_competitor' => $new_by_competitor]
            );
        }

        return ['total_new' => $total_new, 'by_competitor' => $new_by_competitor];
    });
}

echo "Competitor pages check complete.\n";

function _competitor_pages_check_parse_sitemap(string $xml, string $base, int $max): array {
    libxml_use_internal_errors(true);
    $doc = @simplexml_load_string($xml);
    if (!$doc) return [];
    $out = [];

    if (isset($doc->sitemap)) {
        foreach ($doc->sitemap as $sm) {
            $child = trim((string)$sm->loc);
            if (!$child) continue;
            $res = scraper_fetch($child, 8);
            if ($res['status'] !== 200) continue;
            $out = array_merge($out, _competitor_pages_check_parse_sitemap($res['body'], $base, $max - count($out)));
            if (count($out) >= $max) break;
        }
        return array_slice($out, 0, $max);
    }

    if (isset($doc->url)) {
        foreach ($doc->url as $u) {
            $loc = trim((string)$u->loc);
            if (!$loc) continue;
            if (!str_starts_with($loc, $base)) continue;
            if (preg_match('#/(tag|category|author|page/\d+|wp-content|wp-json|feed|amp)/#i', $loc)) continue;
            $out[] = $loc;
            if (count($out) >= $max) break;
        }
    }
    return $out;
}
