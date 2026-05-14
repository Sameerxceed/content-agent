<?php
/**
 * Weekly competitor re-discovery for every site that has active keywords.
 * Calls the same Google CSE → top 10 → aggregate logic as the manual button.
 * When a new competitor surfaces (not seen before), raises an alert.
 *
 * Run via: cron-runner.php competitor-redetect
 */

/** @var PDO $db */
/** @var ?int $site_id_filter */
/** @var string $job_type */

// Need CSE configured
if (empty(config('google_cse_api_key')) || empty(config('google_cse_cx'))) {
    echo "Skipped: Google CSE not configured\n";
    return;
}

$sites = cron_get_sites($db, $site_id_filter);
echo "Scanning " . count($sites) . " sites for competitor re-discovery\n";

foreach ($sites as $site) {
    $sid = (int)$site['id'];

    // Skip sites with fewer than 5 active keywords (not enough signal)
    $stmt = $db->prepare("SELECT COUNT(*) FROM keywords WHERE site_id = ? AND status = 'active'");
    $stmt->execute([$sid]);
    if ((int)$stmt->fetchColumn() < 5) {
        echo "  skip site #{$sid} ({$site['domain']}): fewer than 5 active keywords\n";
        continue;
    }

    echo "  re-detecting competitors for #{$sid} {$site['domain']}...\n";

    cron_run_site_job($db, $sid, $job_type, function ($run_id) use ($db, $sid, $site) {
        // Capture pre-existing competitor domains
        $stmt = $db->prepare("SELECT domain FROM competitors WHERE site_id = ?");
        $stmt->execute([$sid]);
        $known = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

        // Re-use the discovery endpoint by invoking it via internal HTTP call
        // Simpler: replicate the CSE call inline since we control all inputs.
        $api_key = config('google_cse_api_key');
        $cx      = config('google_cse_cx');

        $stmt = $db->prepare("SELECT id, keyword FROM keywords WHERE site_id = ? AND status = 'active' ORDER BY (source = 'gsc') DESC, priority DESC LIMIT 30");
        $stmt->execute([$sid]);
        $keywords = $stmt->fetchAll();

        $own = preg_replace('#^(https?://)?(www\.)?#i', '', strtolower($site['domain']));
        $own = rtrim($own, '/');
        $excl = ['wikipedia.org','reddit.com','quora.com','youtube.com','medium.com','linkedin.com','facebook.com','twitter.com','x.com','instagram.com','pinterest.com','amazon.com','ebay.com','google.com','bing.com','github.com','stackoverflow.com'];

        $domain_data = [];
        $cse_calls = 0;
        foreach ($keywords as $kw) {
            $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
                'key' => $api_key, 'cx' => $cx, 'q' => $kw['keyword'], 'num' => 10,
            ]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'ContentAgent/1.0',
            ]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $cse_calls++;
            if ($status !== 200) continue;
            $data = json_decode($body, true);
            foreach ($data['items'] ?? [] as $idx => $item) {
                $host = preg_replace('#^www\.#', '', strtolower(parse_url($item['link'] ?? '', PHP_URL_HOST) ?? ''));
                if (!$host || $host === $own || str_ends_with($host, '.' . $own)) continue;
                $skip = false;
                foreach ($excl as $b) { if ($host === $b || str_ends_with($host, '.' . $b)) { $skip = true; break; } }
                if ($skip) continue;
                $domain_data[$host] ??= ['shared' => 0, 'rankings' => []];
                $domain_data[$host]['shared']++;
                $domain_data[$host]['rankings'][] = ['keyword_id' => (int)$kw['id'], 'position' => $idx + 1, 'url' => $item['link'] ?? '', 'title' => $item['title'] ?? ''];
            }
        }

        // Persist top 15 with 2+ keyword overlap
        $candidates = array_filter($domain_data, fn($d) => $d['shared'] >= 2);
        uasort($candidates, fn($a, $b) => $b['shared'] <=> $a['shared']);
        $candidates = array_slice($candidates, 0, 15, true);

        $new_competitors = [];
        $total_kw = count($keywords);
        $insert_c = $db->prepare('INSERT INTO competitors (site_id, domain, source, status, overlap_score, shared_keywords, last_analysed_at) VALUES (?, ?, "auto", "active", ?, ?, NOW()) ON DUPLICATE KEY UPDATE overlap_score = VALUES(overlap_score), shared_keywords = VALUES(shared_keywords), last_analysed_at = NOW(), status = IF(status = "ignored", "ignored", "active")');
        $insert_r = $db->prepare('INSERT INTO competitor_keyword_rankings (competitor_id, keyword_id, position, url, title, last_seen_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE position = VALUES(position), url = VALUES(url), title = VALUES(title), last_seen_at = NOW()');

        foreach ($candidates as $domain => $info) {
            $is_new = !isset($known[$domain]);
            $overlap_score = (int)round(($info['shared'] / max($total_kw, 1)) * 100);
            $insert_c->execute([$sid, $domain, $overlap_score, $info['shared']]);
            $cid = (int)$db->prepare('SELECT id FROM competitors WHERE site_id = ? AND domain = ?')->execute([$sid, $domain]);
            $cid_stmt = $db->prepare('SELECT id FROM competitors WHERE site_id = ? AND domain = ?');
            $cid_stmt->execute([$sid, $domain]);
            $cid = (int)$cid_stmt->fetchColumn();
            foreach ($info['rankings'] as $r) {
                $insert_r->execute([$cid, $r['keyword_id'], $r['position'], mb_substr($r['url'], 0, 2048), mb_substr($r['title'], 0, 500)]);
            }
            if ($is_new) $new_competitors[] = ['domain' => $domain, 'shared' => $info['shared']];
        }

        if (!empty($new_competitors)) {
            $top = array_slice($new_competitors, 0, 5);
            $lines = array_map(fn($c) => $c['domain'] . ' (shares ' . $c['shared'] . ' keywords)', $top);
            alert_create($db, $sid, 'new_competitor',
                count($new_competitors) . ' new competitor' . (count($new_competitors) > 1 ? 's' : '') . ' detected',
                implode("\n", $lines),
                '/dashboard/competitors.php?site=' . $sid,
                'info',
                ['new' => $new_competitors]
            );
        }

        return ['keywords_analysed' => $total_kw, 'cse_calls' => $cse_calls, 'candidates' => count($candidates), 'new_competitors' => count($new_competitors)];
    });
}

echo "Competitor re-discovery complete.\n";
