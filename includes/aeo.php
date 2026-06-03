<?php
/**
 * AEO (Answer Engine Optimization) tracker.
 *
 * Calls AI search engines for tracked queries, parses citations, and records
 * whether the site's domain appears + at what position. Snapshots over time.
 *
 * Engines:
 *   - claude_web : Claude messages API with the web_search server tool
 *   - openai_web : OpenAI gpt-4o-search-preview (web search baked into the model)
 *   - gemini_web : Gemini 2.0 Flash with Google Search grounding
 *   - perplexity : Perplexity Sonar API (always web-grounded)
 *
 * Engine-agnostic by design — each function returns { success, text, citations[] }.
 * UI runs whichever engines are configured (have keys) and shows per-engine results.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/haiku.php';

/** Engines configured (have API keys) on this install, in display order. */
function aeo_available_engines(): array
{
    $engines = [];
    if (!empty(config('haiku_api_key')))      $engines[] = 'claude_web';
    if (!empty(config('openai_api_key')))     $engines[] = 'openai_web';
    if (!empty(config('gemini_api_key')))     $engines[] = 'gemini_web';
    if (!empty(config('perplexity_api_key'))) $engines[] = 'perplexity';
    return $engines;
}

/** Display label for an engine id. */
function aeo_engine_label(string $engine): string
{
    return [
        'claude_web' => 'Claude',
        'openai_web' => 'ChatGPT',
        'gemini_web' => 'Gemini',
        'perplexity' => 'Perplexity',
    ][$engine] ?? $engine;
}

/** Default engine for AEO checks. First configured engine wins. */
function aeo_default_engine(): string
{
    $available = aeo_available_engines();
    return $available[0] ?? 'claude_web';
}

/**
 * Call Claude with the web_search server tool and return { text, citations[], error? }.
 *
 * Claude returns a response with mixed content blocks:
 *   - server_tool_use blocks (the search calls Claude made)
 *   - web_search_tool_result blocks (the URL list Claude got back)
 *   - text blocks (the answer, optionally with inline citation references)
 *
 * We extract every URL Claude actually looked at, in the order they appeared.
 */
function aeo_query_claude_web(string $query): array
{
    $api_key = config('haiku_api_key');
    if (empty($api_key)) return ['success' => false, 'error' => 'Claude API key not set'];

    // Use Sonnet for grounded web answers — Haiku doesn't support web_search yet.
    $model = config('haiku_model') ?: 'claude-sonnet-4-6';
    if (str_contains($model, 'haiku')) {
        $model = 'claude-sonnet-4-6';
    }

    $payload = [
        'model'      => $model,
        'max_tokens' => 2000,
        'system'     => 'You are an AI assistant answering search-style questions. Be concise. Use the web_search tool to look up current information and cite specific sources.',
        'messages'   => [
            ['role' => 'user', 'content' => $query],
        ],
        'tools' => [
            ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 120,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        return ['success' => false, 'error' => "Claude HTTP {$code}: " . substr((string)$body, 0, 300)];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) return ['success' => false, 'error' => 'Unparseable response'];

    $text  = '';
    $urls  = [];
    $seen  = [];

    foreach ($data['content'] ?? [] as $block) {
        $type = $block['type'] ?? '';
        if ($type === 'text') {
            $text .= $block['text'] ?? '';
            // Text blocks may include citations referring back to a specific URL
            foreach ($block['citations'] ?? [] as $cite) {
                $u = $cite['url'] ?? null;
                if ($u && !isset($seen[$u])) { $urls[] = $u; $seen[$u] = true; }
            }
        } elseif ($type === 'web_search_tool_result') {
            // Results array of pages Claude looked at
            foreach ($block['content'] ?? [] as $r) {
                $u = $r['url'] ?? null;
                if ($u && !isset($seen[$u])) { $urls[] = $u; $seen[$u] = true; }
            }
        }
    }

    if ($text === '' && empty($urls)) {
        return ['success' => false, 'error' => 'Claude returned no text or citations — web_search may have failed.'];
    }

    return ['success' => true, 'text' => $text, 'citations' => $urls];
}

/**
 * Call Perplexity Sonar and return { text, citations[], error? }.
 *
 * @return array {
 *   success: bool,
 *   text: string,
 *   citations: array<int, string>  // URLs in citation order
 *   error?: string,
 * }
 */
function aeo_query_perplexity(string $query): array
{
    $key = config('perplexity_api_key');
    if (empty($key)) return ['success' => false, 'error' => 'Perplexity API key not set'];

    $payload = [
        'model'    => 'sonar',
        'messages' => [
            ['role' => 'system', 'content' => 'Answer concisely. Cite specific sources for any factual claims.'],
            ['role' => 'user',   'content' => $query],
        ],
        'return_citations' => true,
        'temperature'      => 0.2,
    ];

    $ch = curl_init('https://api.perplexity.ai/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 45,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        return ['success' => false, 'error' => "Perplexity HTTP {$code}: " . substr((string)$body, 0, 250)];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) return ['success' => false, 'error' => 'Unparseable response'];

    $text = $data['choices'][0]['message']['content'] ?? '';
    // Perplexity returns either top-level `citations` (URL array) or per-message; cover both
    $cites = $data['citations'] ?? ($data['choices'][0]['message']['citations'] ?? []);
    if (!is_array($cites)) $cites = [];
    // Normalise to flat URL strings
    $urls = [];
    foreach ($cites as $c) {
        if (is_string($c)) $urls[] = $c;
        elseif (is_array($c) && !empty($c['url'])) $urls[] = $c['url'];
    }

    return ['success' => true, 'text' => $text, 'citations' => $urls];
}

/**
 * Call OpenAI's gpt-4o-search-preview (web search built into the model) and
 * return { text, citations[], error? }.
 *
 * The model returns content with `annotations` of type `url_citation`, each
 * pointing at a source URL the answer was grounded in.
 */
function aeo_query_openai_web(string $query): array
{
    $key = config('openai_api_key');
    if (empty($key)) return ['success' => false, 'error' => 'OpenAI API key not set'];

    $payload = [
        'model'    => 'gpt-4o-search-preview',
        'messages' => [
            ['role' => 'system', 'content' => 'Answer concisely. Cite specific sources for any factual claims.'],
            ['role' => 'user',   'content' => $query],
        ],
        'web_search_options' => new stdClass(), // enable web search with defaults
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        return ['success' => false, 'error' => "OpenAI HTTP {$code}: " . substr((string)$body, 0, 250)];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) return ['success' => false, 'error' => 'Unparseable response'];

    $msg  = $data['choices'][0]['message'] ?? [];
    $text = $msg['content'] ?? '';
    $urls = [];
    $seen = [];
    foreach ($msg['annotations'] ?? [] as $ann) {
        if (($ann['type'] ?? '') !== 'url_citation') continue;
        $u = $ann['url_citation']['url'] ?? null;
        if ($u && !isset($seen[$u])) { $urls[] = $u; $seen[$u] = true; }
    }

    if ($text === '' && empty($urls)) {
        return ['success' => false, 'error' => 'OpenAI returned no text or citations'];
    }

    return ['success' => true, 'text' => $text, 'citations' => $urls];
}

/**
 * Call Gemini 2.0 Flash with Google Search grounding and return
 * { text, citations[], error? }.
 *
 * Grounded answers include `groundingMetadata.groundingChunks[].web.uri` —
 * the source URLs Gemini consulted.
 */
function aeo_query_gemini_web(string $query): array
{
    $key = config('gemini_api_key');
    if (empty($key)) return ['success' => false, 'error' => 'Gemini API key not set'];

    $model = 'gemini-2.0-flash';
    $url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($key);

    $payload = [
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $query]]],
        ],
        'tools' => [
            ['google_search' => new stdClass()],
        ],
        'systemInstruction' => [
            'parts' => [['text' => 'Answer concisely. Cite specific sources for any factual claims.']],
        ],
        'generationConfig' => [
            'temperature' => 0.2,
            'maxOutputTokens' => 2000,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        return ['success' => false, 'error' => "Gemini HTTP {$code}: " . substr((string)$body, 0, 250)];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) return ['success' => false, 'error' => 'Unparseable response'];

    $candidate = $data['candidates'][0] ?? [];
    $text = '';
    foreach ($candidate['content']['parts'] ?? [] as $part) {
        $text .= $part['text'] ?? '';
    }

    $urls = [];
    $seen = [];
    foreach ($candidate['groundingMetadata']['groundingChunks'] ?? [] as $chunk) {
        $u = $chunk['web']['uri'] ?? null;
        if ($u && !isset($seen[$u])) { $urls[] = $u; $seen[$u] = true; }
    }

    if ($text === '' && empty($urls)) {
        return ['success' => false, 'error' => 'Gemini returned no text or citations — grounding may have failed.'];
    }

    return ['success' => true, 'text' => $text, 'citations' => $urls];
}

/**
 * Extract the bare domain from a URL (no www, no trailing slash).
 */
function aeo_domain(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    $host = preg_replace('#^www\.#i', '', $host);
    return strtolower($host);
}

/**
 * Run one tracked query, store the result snapshot, update the query row.
 * Returns the row data + parsed signals.
 */
function aeo_check_query(PDO $db, int $query_id, ?string $engine = null): array
{
    $engine = $engine ?: aeo_default_engine();

    $stmt = $db->prepare('SELECT q.*, s.domain AS site_domain
                          FROM aeo_queries q JOIN sites s ON q.site_id = s.id
                          WHERE q.id = ?');
    $stmt->execute([$query_id]);
    $q = $stmt->fetch();
    if (!$q) return ['success' => false, 'error' => 'Query not found'];

    $site_domain = aeo_domain('https://' . $q['site_domain']);

    if ($engine === 'claude_web') {
        $resp = aeo_query_claude_web($q['query_text']);
    } elseif ($engine === 'openai_web') {
        $resp = aeo_query_openai_web($q['query_text']);
    } elseif ($engine === 'gemini_web') {
        $resp = aeo_query_gemini_web($q['query_text']);
    } elseif ($engine === 'perplexity') {
        $resp = aeo_query_perplexity($q['query_text']);
    } else {
        return ['success' => false, 'error' => 'Unsupported engine: ' . $engine];
    }

    $today = date('Y-m-d');

    if (empty($resp['success'])) {
        $db->prepare('INSERT INTO aeo_results (query_id, engine, snapshot_date, error)
                      VALUES (?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE error = VALUES(error)')
           ->execute([$query_id, $engine, $today, $resp['error']]);
        $db->prepare('UPDATE aeo_queries SET last_checked_at = NOW() WHERE id = ?')
           ->execute([$query_id]);
        return ['success' => false, 'error' => $resp['error']];
    }

    // Parse citations into rich rows
    $citations = [];
    $our_cited = 0;
    $our_position = null;
    $competitor_domains = [];
    foreach ($resp['citations'] as $i => $url) {
        $domain = aeo_domain($url);
        $position = $i + 1;
        $is_ours = ($domain === $site_domain || str_ends_with($domain, '.' . $site_domain));
        $citations[] = [
            'url'      => $url,
            'domain'   => $domain,
            'position' => $position,
            'is_ours'  => $is_ours,
        ];
        if ($is_ours && $our_position === null) {
            $our_cited = 1;
            $our_position = $position;
        } elseif (!$is_ours && !empty($domain)) {
            $competitor_domains[] = $domain;
        }
    }
    $competitor_domains = array_values(array_unique($competitor_domains));

    $db->prepare('INSERT INTO aeo_results
                  (query_id, engine, snapshot_date, response_text, citations, our_cited, our_position, competitor_domains)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                    response_text = VALUES(response_text),
                    citations = VALUES(citations),
                    our_cited = VALUES(our_cited),
                    our_position = VALUES(our_position),
                    competitor_domains = VALUES(competitor_domains),
                    error = NULL')
       ->execute([
           $query_id, $engine, $today,
           $resp['text'],
           json_encode($citations),
           $our_cited,
           $our_position,
           json_encode($competitor_domains),
       ]);

    $db->prepare('UPDATE aeo_queries
                  SET last_checked_at = NOW(), last_cited = ?, last_position = ?
                  WHERE id = ?')
       ->execute([$our_cited, $our_position, $query_id]);

    return [
        'success'       => true,
        'cited'         => (bool)$our_cited,
        'position'      => $our_position,
        'citations'     => $citations,
        'competitors'   => $competitor_domains,
        'response_text' => $resp['text'],
    ];
}

/**
 * Run a single query against EVERY configured engine. Returns per-engine results.
 * Each engine result is stored as a separate row in aeo_results keyed by (query, engine, date).
 */
function aeo_check_query_all_engines(PDO $db, int $query_id): array
{
    $engines = aeo_available_engines();
    $results = [];
    foreach ($engines as $engine) {
        $results[$engine] = aeo_check_query($db, $query_id, $engine);
        usleep(300000); // 0.3s between calls so we don't hammer any one provider
    }
    return $results;
}

/**
 * Bulk check all active queries for a site. Runs every configured engine per query
 * so the user sees full per-engine coverage (Claude/ChatGPT/Gemini/Perplexity).
 */
function aeo_check_all_for_site(PDO $db, int $site_id): array
{
    $stmt = $db->prepare('SELECT id FROM aeo_queries WHERE site_id = ? AND status = "active"');
    $stmt->execute([$site_id]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $engines = aeo_available_engines();
    if (empty($engines)) {
        return ['checked' => 0, 'cited' => 0, 'errors' => 0, 'engines' => 0];
    }

    $checked = 0; $cited = 0; $errors = 0;
    foreach ($ids as $qid) {
        $per_engine = aeo_check_query_all_engines($db, (int)$qid);
        $checked++;
        $any_cited = false; $any_error = false;
        foreach ($per_engine as $r) {
            if (!empty($r['success']) && !empty($r['cited'])) $any_cited = true;
            if (empty($r['success'])) $any_error = true;
        }
        if ($any_cited) $cited++;
        if ($any_error) $errors++;
    }
    return ['checked' => $checked, 'cited' => $cited, 'errors' => $errors, 'engines' => count($engines)];
}

/**
 * Read latest per-engine citation row for a given query. Returns keyed by engine id.
 * Each value is { cited, position, citations[], competitor_domains[], response_text, snapshot_date }
 * or null if that engine has never been run for this query.
 */
function aeo_latest_per_engine(PDO $db, int $query_id): array
{
    $stmt = $db->prepare('SELECT engine, snapshot_date, response_text, citations, our_cited, our_position, competitor_domains, error
                          FROM aeo_results
                          WHERE query_id = ?
                          ORDER BY snapshot_date DESC');
    $stmt->execute([$query_id]);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $e = $r['engine'];
        if (isset($out[$e])) continue; // first row per engine = latest (ORDER BY DESC)
        $out[$e] = [
            'cited'         => (bool)$r['our_cited'],
            'position'      => $r['our_position'],
            'citations'     => json_decode($r['citations'] ?: '[]', true) ?: [],
            'competitors'   => json_decode($r['competitor_domains'] ?: '[]', true) ?: [],
            'response_text' => $r['response_text'] ?? '',
            'snapshot_date' => $r['snapshot_date'],
            'error'         => $r['error'] ?? null,
        ];
    }
    return $out;
}

/**
 * Ask Claude to suggest AEO queries for a site based on its topics + keywords.
 * Returns an array of { query, category } objects.
 */
function aeo_suggest_queries(PDO $db, array $site): array
{
    $stmt = $db->prepare("SELECT keyword FROM keywords WHERE site_id = ? AND status = 'active' ORDER BY priority DESC LIMIT 15");
    $stmt->execute([$site['id']]);
    $keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $topics = json_decode($site['topics'] ?? '[]', true) ?: [];

    // Profile context so suggested queries match the business's actual scale,
    // geography and customer — not generic enterprise SEO. e.g. for a 15-person
    // Pune AI consultancy, queries should sound like "best AI consultancy for
    // mid-market in India", not "how to choose an AI vendor for Fortune 500".
    require_once __DIR__ . '/business_profile.php';
    $profile = profile_get($db, (int)$site['id']);
    $profile_block = $profile ? profile_prompt_block($profile) . "\n\n" : '';

    $system = "You are an AEO (Answer Engine Optimization) strategist. Generate 8 search queries that real users would ask AI assistants (ChatGPT, Perplexity, Claude) and where this business would ideally be cited as a source.\n\nReturn ONLY valid JSON array:\n[{\"query\": \"...\", \"category\": \"brand|industry|how-to|comparison|location\"}]\n\nMix categories. Make them sound natural — how people actually ask AI, not Google. Lean toward queries with commercial intent (someone deciding what to buy / use). Calibrate each query to the SPECIFIC business profile below: a small local boutique should not get queries phrased for an enterprise buyer, and vice versa.";
    $user = $profile_block
        . "Business: {$site['name']} ({$site['domain']})\nTopics: " . implode(', ', $topics)
        . "\nKeywords: " . implode(', ', array_slice($keywords, 0, 10));

    $resp = haiku_chat($system, $user, 1024);
    if (empty($resp['success'])) return [];

    $content = trim($resp['content']);
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
    $arr = json_decode($content, true);
    if (!is_array($arr) && preg_match('/\[[\s\S]*\]/', $content, $m)) {
        $arr = json_decode($m[0], true);
    }
    return is_array($arr) ? $arr : [];
}

/**
 * Site-wide AEO summary: citation rate, top competitor citers, trend.
 */
function aeo_site_summary(PDO $db, int $site_id, int $days = 30): array
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM aeo_queries WHERE site_id = ? AND status = "active"');
    $stmt->execute([$site_id]);
    $total = (int)$stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM aeo_queries WHERE site_id = ? AND status = "active" AND last_cited = 1');
    $stmt->execute([$site_id]);
    $cited_now = (int)$stmt->fetchColumn();

    // Top competitor domains across recent results
    $stmt = $db->prepare('SELECT r.competitor_domains
                          FROM aeo_results r JOIN aeo_queries q ON r.query_id = q.id
                          WHERE q.site_id = ? AND r.snapshot_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)');
    $stmt->execute([$site_id, $days]);
    $tally = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $arr = json_decode($json ?: '[]', true) ?: [];
        foreach ($arr as $d) {
            if (!$d) continue;
            $tally[$d] = ($tally[$d] ?? 0) + 1;
        }
    }
    arsort($tally);
    $top_competitors = array_slice(array_keys($tally), 0, 10);
    $top_competitors_with_counts = [];
    foreach ($top_competitors as $d) $top_competitors_with_counts[] = ['domain' => $d, 'mentions' => $tally[$d]];

    return [
        'total_queries'    => $total,
        'cited_now'        => $cited_now,
        'citation_rate'    => $total > 0 ? round(($cited_now / $total) * 100, 1) : 0,
        'top_competitors'  => $top_competitors_with_counts,
    ];
}
