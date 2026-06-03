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
 * Build a raw cURL request spec for one engine. Returns null if engine unsupported
 * or key missing. Used to fire all engines in parallel via curl_multi.
 */
function aeo_build_request(string $engine, string $query): ?array
{
    if ($engine === 'claude_web') {
        $key = config('haiku_api_key'); if (empty($key)) return null;
        $model = config('haiku_model') ?: 'claude-sonnet-4-6';
        if (str_contains($model, 'haiku')) $model = 'claude-sonnet-4-6';
        return [
            'url' => 'https://api.anthropic.com/v1/messages',
            'headers' => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
            'body' => json_encode([
                'model' => $model, 'max_tokens' => 2000,
                'system' => 'You are an AI assistant answering search-style questions. Be concise. Use the web_search tool to look up current information and cite specific sources.',
                'messages' => [['role' => 'user', 'content' => $query]],
                'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]],
            ]),
        ];
    }
    if ($engine === 'openai_web') {
        $key = config('openai_api_key'); if (empty($key)) return null;
        return [
            'url' => 'https://api.openai.com/v1/chat/completions',
            'headers' => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            'body' => json_encode([
                'model' => 'gpt-4o-search-preview',
                'messages' => [
                    ['role' => 'system', 'content' => 'Answer concisely. Cite specific sources for any factual claims.'],
                    ['role' => 'user', 'content' => $query],
                ],
                'web_search_options' => new stdClass(),
            ]),
        ];
    }
    if ($engine === 'gemini_web') {
        $key = config('gemini_api_key'); if (empty($key)) return null;
        return [
            'url' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($key),
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode([
                'contents' => [['role' => 'user', 'parts' => [['text' => $query]]]],
                'tools' => [['google_search' => new stdClass()]],
                'systemInstruction' => ['parts' => [['text' => 'Answer concisely. Cite specific sources for any factual claims.']]],
                'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 2000],
            ]),
        ];
    }
    if ($engine === 'perplexity') {
        $key = config('perplexity_api_key'); if (empty($key)) return null;
        return [
            'url' => 'https://api.perplexity.ai/chat/completions',
            'headers' => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            'body' => json_encode([
                'model' => 'sonar',
                'messages' => [
                    ['role' => 'system', 'content' => 'Answer concisely. Cite specific sources for any factual claims.'],
                    ['role' => 'user', 'content' => $query],
                ],
                'return_citations' => true, 'temperature' => 0.2,
            ]),
        ];
    }
    return null;
}

/**
 * Parse a raw HTTP response from one engine into the unified
 * { success, text, citations[], error? } shape.
 */
function aeo_parse_response(string $engine, int $code, string $body): array
{
    if ($code < 200 || $code >= 300) {
        $label = aeo_engine_label($engine);
        return ['success' => false, 'error' => "{$label} HTTP {$code}: " . substr($body, 0, 250)];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) return ['success' => false, 'error' => 'Unparseable response'];

    $urls = []; $seen = []; $text = '';

    if ($engine === 'claude_web') {
        foreach ($data['content'] ?? [] as $block) {
            $t = $block['type'] ?? '';
            if ($t === 'text') {
                $text .= $block['text'] ?? '';
                foreach ($block['citations'] ?? [] as $cite) {
                    $u = $cite['url'] ?? null;
                    if ($u && !isset($seen[$u])) { $urls[] = $u; $seen[$u] = true; }
                }
            } elseif ($t === 'web_search_tool_result') {
                foreach ($block['content'] ?? [] as $r) {
                    $u = $r['url'] ?? null;
                    if ($u && !isset($seen[$u])) { $urls[] = $u; $seen[$u] = true; }
                }
            }
        }
    } elseif ($engine === 'openai_web') {
        $msg  = $data['choices'][0]['message'] ?? [];
        $text = $msg['content'] ?? '';
        foreach ($msg['annotations'] ?? [] as $ann) {
            if (($ann['type'] ?? '') !== 'url_citation') continue;
            $u = $ann['url_citation']['url'] ?? null;
            if ($u && !isset($seen[$u])) { $urls[] = $u; $seen[$u] = true; }
        }
    } elseif ($engine === 'gemini_web') {
        $candidate = $data['candidates'][0] ?? [];
        foreach ($candidate['content']['parts'] ?? [] as $part) $text .= $part['text'] ?? '';
        foreach ($candidate['groundingMetadata']['groundingChunks'] ?? [] as $chunk) {
            $u = $chunk['web']['uri'] ?? null;
            if ($u && !isset($seen[$u])) { $urls[] = $u; $seen[$u] = true; }
        }
    } elseif ($engine === 'perplexity') {
        $text  = $data['choices'][0]['message']['content'] ?? '';
        $cites = $data['citations'] ?? ($data['choices'][0]['message']['citations'] ?? []);
        if (!is_array($cites)) $cites = [];
        foreach ($cites as $c) {
            if (is_string($c)) $urls[] = $c;
            elseif (is_array($c) && !empty($c['url'])) $urls[] = $c['url'];
        }
    }

    if ($text === '' && empty($urls)) {
        return ['success' => false, 'error' => aeo_engine_label($engine) . ' returned no text or citations'];
    }
    return ['success' => true, 'text' => $text, 'citations' => $urls];
}

/**
 * Fire all configured engines IN PARALLEL via curl_multi. Returns engine => response.
 * Total wall time ≈ slowest engine, not sum — keeps single-query checks under
 * nginx's 60s proxy timeout.
 */
function aeo_query_all_engines_parallel(string $query, int $timeout = 55): array
{
    $engines = aeo_available_engines();
    if (empty($engines)) return [];

    $mh = curl_multi_init();
    $handles = [];
    foreach ($engines as $eng) {
        $req = aeo_build_request($eng, $query);
        if (!$req) continue;
        $ch = curl_init($req['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $req['body'],
            CURLOPT_HTTPHEADER     => $req['headers'],
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$eng] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 1.0);
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $eng => $ch) {
        $body = curl_multi_getcontent($ch) ?: '';
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if ($err && $code === 0) {
            $results[$eng] = ['success' => false, 'error' => aeo_engine_label($eng) . ' transport: ' . $err];
        } else {
            $results[$eng] = aeo_parse_response($eng, $code, $body);
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

/**
 * Run a single query against EVERY configured engine IN PARALLEL.
 * Stores per-engine rows in aeo_results, updates the aeo_queries summary,
 * returns engine => { success, cited, position, citations, competitors, response_text }.
 */
function aeo_check_query_all_engines(PDO $db, int $query_id): array
{
    $stmt = $db->prepare('SELECT q.*, s.domain AS site_domain
                          FROM aeo_queries q JOIN sites s ON q.site_id = s.id
                          WHERE q.id = ?');
    $stmt->execute([$query_id]);
    $q = $stmt->fetch();
    if (!$q) return [];

    $site_domain = aeo_domain('https://' . $q['site_domain']);
    $today = date('Y-m-d');
    $raw = aeo_query_all_engines_parallel($q['query_text']);

    $out = [];
    $latest_cited = null; $latest_position = null;
    foreach ($raw as $engine => $resp) {
        if (empty($resp['success'])) {
            $db->prepare('INSERT INTO aeo_results (query_id, engine, snapshot_date, error)
                          VALUES (?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE error = VALUES(error)')
               ->execute([$query_id, $engine, $today, $resp['error']]);
            $out[$engine] = ['success' => false, 'error' => $resp['error']];
            continue;
        }

        $citations = []; $our_cited = 0; $our_position = null; $competitors = [];
        foreach ($resp['citations'] as $i => $url) {
            $domain = aeo_domain($url);
            $position = $i + 1;
            $is_ours = ($domain === $site_domain || str_ends_with($domain, '.' . $site_domain));
            $citations[] = ['url' => $url, 'domain' => $domain, 'position' => $position, 'is_ours' => $is_ours];
            if ($is_ours && $our_position === null) { $our_cited = 1; $our_position = $position; }
            elseif (!$is_ours && !empty($domain)) { $competitors[] = $domain; }
        }
        $competitors = array_values(array_unique($competitors));

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
           ->execute([$query_id, $engine, $today, $resp['text'], json_encode($citations), $our_cited, $our_position, json_encode($competitors)]);

        $out[$engine] = [
            'success' => true, 'cited' => (bool)$our_cited, 'position' => $our_position,
            'citations' => $citations, 'competitors' => $competitors, 'response_text' => $resp['text'],
        ];

        // Track the strongest signal across engines for the query summary
        if ($our_cited && ($latest_cited === null || $latest_cited === 0)) {
            $latest_cited = 1; $latest_position = $our_position;
        } elseif ($latest_cited === null) {
            $latest_cited = 0;
        }
    }

    // Update query summary: cited = TRUE if ANY engine cited us
    $db->prepare('UPDATE aeo_queries
                  SET last_checked_at = NOW(), last_cited = ?, last_position = ?
                  WHERE id = ?')
       ->execute([$latest_cited ?? 0, $latest_position, $query_id]);

    return $out;
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
 * Generate a content brief designed to WIN a specific AEO query.
 *
 * Loads the query + latest per-engine responses + cited competitor domains, then
 * asks Claude to design a post that would be cited by AI engines for this query.
 * Returns a structured brief the user can review and accept into the Content Plan.
 *
 * Brief shape:
 *   {
 *     title, slug, angle, target_keyword, secondary_keywords[],
 *     recommended_word_count, must_cover_sections[], faq_questions[],
 *     competitor_gap, winning_hook, schema_hints[]
 *   }
 */
function aeo_generate_winning_brief(PDO $db, int $query_id): array
{
    $stmt = $db->prepare('SELECT q.*, s.id AS sid, s.name AS site_name, s.domain AS site_domain
                          FROM aeo_queries q JOIN sites s ON q.site_id = s.id
                          WHERE q.id = ?');
    $stmt->execute([$query_id]);
    $q = $stmt->fetch();
    if (!$q) return ['success' => false, 'error' => 'Query not found'];

    $per_engine = aeo_latest_per_engine($db, $query_id);

    // Aggregate competitor evidence: domains cited + a sample of each engine's response
    $competitor_domains = [];
    $engine_responses = [];
    foreach ($per_engine as $eng => $r) {
        if (!empty($r['competitors'])) {
            foreach ($r['competitors'] as $d) $competitor_domains[$d] = ($competitor_domains[$d] ?? 0) + 1;
        }
        if (!empty($r['response_text'])) {
            $engine_responses[$eng] = mb_substr($r['response_text'], 0, 1200);
        }
    }
    arsort($competitor_domains);
    $top_competitors = array_slice(array_keys($competitor_domains), 0, 10);

    // Business profile context so the brief sounds like the customer, not generic
    require_once __DIR__ . '/business_profile.php';
    $profile = profile_get($db, (int)$q['sid']);
    $profile_block = $profile ? profile_prompt_block($profile) . "\n\n" : '';

    $engines_text = '';
    foreach ($engine_responses as $eng => $resp) {
        $engines_text .= "\n### " . aeo_engine_label($eng) . " currently answers:\n" . $resp . "\n";
    }
    $competitors_text = $top_competitors
        ? "\n### Currently cited domains (in citation count order):\n- " . implode("\n- ", $top_competitors) . "\n"
        : '';

    $system = "You are a content strategist whose ONE job is to design a blog post that would win a specific AI-search query for {$q['site_name']} ({$q['site_domain']}).\n\n"
        . "AI search engines (Claude, ChatGPT, Gemini, Perplexity) cite pages that are:\n"
        . "- Direct and specific to the question (lead with the answer, no preamble)\n"
        . "- Rich in concrete data (numbers, ranges, year-stamped, named providers)\n"
        . "- Clearly structured (H2/H3 hierarchy, bullet/table lists, FAQ section)\n"
        . "- Genuinely useful (not thin SEO content)\n\n"
        . "You will be told the exact query, what competitors currently rank, and an excerpt of each engine's current answer. Design a post that beats them.\n\n"
        . "OUTPUT — strict JSON only:\n"
        . "{\n"
        . "  \"title\": \"60 chars max, direct and specific to the query\",\n"
        . "  \"slug\": \"url-safe-lowercase-hyphenated\",\n"
        . "  \"angle\": \"1-2 sentence positioning — what makes this post quotable that the competitors miss\",\n"
        . "  \"target_keyword\": \"the primary phrase the post targets\",\n"
        . "  \"secondary_keywords\": [\"phrase1\", \"phrase2\", ...],\n"
        . "  \"recommended_word_count\": 1800,\n"
        . "  \"must_cover_sections\": [\"H2 heading 1\", \"H2 heading 2\", ...],\n"
        . "  \"faq_questions\": [\"Q1?\", \"Q2?\", ... 6-10 questions real buyers would ask\"],\n"
        . "  \"competitor_gap\": \"1-2 sentences on what the cited competitors miss that this post will cover\",\n"
        . "  \"winning_hook\": \"the opening sentence — must answer the query directly in the first ~20 words\",\n"
        . "  \"schema_hints\": [\"FAQPage\", \"HowTo\", \"Article\", ...] /* schema.org types to emit */\n"
        . "}\n\n"
        . "Rules:\n"
        . "- The title and winning_hook must MATCH the buyer's intent literally — if the query is a cost question, the title and hook are about cost.\n"
        . "- FAQ questions must be ones a real buyer would ask AI next after the main query. Not generic. Not for SEO; for actual usefulness.\n"
        . "- must_cover_sections: 5-9 H2s. Use specifics like prices, integrations, timelines — not generic platitudes.\n"
        . "- Calibrate scope and tone to the business profile below. A 15-person Pune consultancy ≠ a Fortune 500.\n"
        . "- Do NOT plagiarise competitor text — use it only to understand what's missing.";

    $user = $profile_block
        . "Query the post must win: \"{$q['query_text']}\"\n"
        . "Category: " . ($q['category'] ?? 'industry') . "\n"
        . $competitors_text
        . $engines_text;

    $resp = haiku_chat($system, $user, 2500);
    if (empty($resp['success'])) {
        return ['success' => false, 'error' => 'Claude error: ' . ($resp['error'] ?? 'unknown')];
    }

    $content = trim($resp['content']);
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
    $brief = json_decode($content, true);
    if (!is_array($brief) && preg_match('/\{[\s\S]*\}/', $content, $m)) {
        $brief = json_decode($m[0], true);
    }
    if (!is_array($brief) || empty($brief['title'])) {
        return ['success' => false, 'error' => 'Could not parse Claude output as JSON brief.'];
    }

    return [
        'success'         => true,
        'brief'           => $brief,
        'top_competitors' => $top_competitors,
        'engines_used'    => array_keys($per_engine),
    ];
}

/**
 * Take a generated brief + accept it into the Content Plan as an aeo_gap item.
 * Find-or-creates the keyword and the "AEO Gap Winners" cluster on the active plan.
 * Returns the new plan_item id.
 */
function aeo_add_brief_to_plan(PDO $db, int $query_id, array $brief): array
{
    $stmt = $db->prepare('SELECT site_id, query_text FROM aeo_queries WHERE id = ?');
    $stmt->execute([$query_id]);
    $q = $stmt->fetch();
    if (!$q) return ['success' => false, 'error' => 'Query not found'];

    $site_id = (int)$q['site_id'];

    // Active plan
    $stmt = $db->prepare('SELECT id FROM content_plans WHERE site_id = ? AND status = "active" ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$site_id]);
    $plan_id = (int)($stmt->fetchColumn() ?: 0);
    if (!$plan_id) {
        return ['success' => false, 'error' => 'No active content plan for this site. Generate one first from the Plan page.'];
    }

    // Find-or-create the "AEO Gap Winners" cluster on this plan
    $cluster_name = 'AEO Gap Winners';
    $stmt = $db->prepare('SELECT id FROM content_plan_clusters WHERE plan_id = ? AND name = ? LIMIT 1');
    $stmt->execute([$plan_id, $cluster_name]);
    $cluster_id = (int)($stmt->fetchColumn() ?: 0);
    if (!$cluster_id) {
        $stmt = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM content_plan_clusters WHERE plan_id = ?');
        $stmt->execute([$plan_id]);
        $position = (int)$stmt->fetchColumn();
        $db->prepare('INSERT INTO content_plan_clusters (plan_id, site_id, position, name, angle)
                      VALUES (?, ?, ?, ?, ?)')
           ->execute([$plan_id, $site_id, $position, $cluster_name,
                      'Posts written specifically to win uncited AI-search queries. Each item targets one tracked AEO query.']);
        $cluster_id = (int)$db->lastInsertId();
    }

    // Find-or-create the keyword
    $kw = trim($brief['target_keyword'] ?? $q['query_text']);
    $kw = mb_substr($kw, 0, 240);
    $db->prepare('INSERT INTO keywords (site_id, keyword, priority)
                  VALUES (?, ?, 70)
                  ON DUPLICATE KEY UPDATE priority = GREATEST(priority, 70)')
       ->execute([$site_id, $kw]);
    $stmt = $db->prepare('SELECT id FROM keywords WHERE site_id = ? AND keyword = ? LIMIT 1');
    $stmt->execute([$site_id, $kw]);
    $kw_id = (int)$stmt->fetchColumn();

    // Resolve channels — use whatever the site has configured globally
    $channels = ['cms', 'schema', 'llms'];

    // Pick a target_publish_date — 2 weeks out from today
    $publish_date = date('Y-m-d', strtotime('+14 days'));
    $week_num = (int)date('W');

    // Insert the plan item
    $stmt = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM content_plan_items WHERE plan_id = ?');
    $stmt->execute([$plan_id]);
    $position = (int)$stmt->fetchColumn();

    $db->prepare('INSERT INTO content_plan_items
                  (plan_id, cluster_id, site_id, position, target_week, target_publish_date,
                   role, content_type, bucket, primary_keyword_id, target_aeo_query_id,
                   proposed_title, proposed_angle, recommended_word_count, channels, lock_state)
                  VALUES (?, ?, ?, ?, ?, ?, "supporting", "blog", "aeo_gap", ?, ?, ?, ?, ?, ?, "committed")')
       ->execute([
           $plan_id, $cluster_id, $site_id, $position, $week_num, $publish_date,
           $kw_id, $query_id,
           mb_substr($brief['title'], 0, 500),
           $brief['angle'] ?? '',
           (int)($brief['recommended_word_count'] ?? 1500),
           json_encode($channels),
       ]);
    $item_id = (int)$db->lastInsertId();

    return [
        'success'    => true,
        'plan_id'    => $plan_id,
        'cluster_id' => $cluster_id,
        'item_id'    => $item_id,
        'item_url'   => '/dashboard/plan-item.php?id=' . $item_id,
    ];
}

/**
 * For a given AEO query, does it already have a winning post queued or published?
 * Returns null, or { item_id, item_status, post_id, post_status }.
 */
function aeo_winning_post_state(PDO $db, int $query_id): ?array
{
    $stmt = $db->prepare('SELECT id AS item_id, lock_state, post_id
                          FROM content_plan_items
                          WHERE target_aeo_query_id = ?
                          ORDER BY id DESC LIMIT 1');
    $stmt->execute([$query_id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return [
        'item_id'     => (int)$row['item_id'],
        'item_status' => $row['lock_state'],
        'post_id'     => $row['post_id'] ? (int)$row['post_id'] : null,
    ];
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
