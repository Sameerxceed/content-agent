<?php
/**
 * AI Industry Recall — does the brand appear when AI models answer generic
 * industry questions WITHOUT web search? Measures presence in training data,
 * not real-time citation.
 *
 * Multi-engine: runs the same questions through every configured chat engine
 * (Claude / OpenAI / Gemini) so the user sees which models remember them.
 *
 * Storage: ai_recall_snapshots table — one row per (site, engine, date).
 */

require_once __DIR__ . '/haiku.php';
require_once __DIR__ . '/helpers.php';

/** Engines available for recall (plain chat, no web search). */
function recall_available_engines(): array
{
    $engines = [];
    if (!empty(config('haiku_api_key')))  $engines[] = 'claude';
    if (!empty(config('openai_api_key'))) $engines[] = 'openai';
    if (!empty(config('gemini_api_key'))) $engines[] = 'gemini';
    return $engines;
}

/** Display label for a recall engine. */
function recall_engine_label(string $engine): string
{
    return ['claude' => 'Claude', 'openai' => 'ChatGPT', 'gemini' => 'Gemini'][$engine] ?? $engine;
}

/**
 * Plain-chat call (no web search) to a given engine. Returns { success, content, error }.
 */
function recall_chat(string $engine, string $system, string $user, int $max_tokens = 1024): array
{
    if ($engine === 'claude') {
        return haiku_chat($system, $user, $max_tokens);
    }
    if ($engine === 'openai') {
        return recall_chat_openai($system, $user, $max_tokens);
    }
    if ($engine === 'gemini') {
        return recall_chat_gemini($system, $user, $max_tokens);
    }
    return ['success' => false, 'error' => 'Unsupported recall engine: ' . $engine];
}

function recall_chat_openai(string $system, string $user, int $max_tokens): array
{
    $key = config('openai_api_key');
    if (empty($key)) return ['success' => false, 'error' => 'OpenAI API key not set'];

    $payload = [
        'model'    => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'max_tokens'  => $max_tokens,
        'temperature' => 0.3,
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
        CURLOPT_TIMEOUT        => 45,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        return ['success' => false, 'error' => "OpenAI HTTP {$code}: " . substr((string)$body, 0, 200)];
    }
    $data = json_decode($body, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    if ($text === '') return ['success' => false, 'error' => 'OpenAI returned empty response'];
    return ['success' => true, 'content' => $text];
}

function recall_chat_gemini(string $system, string $user, int $max_tokens): array
{
    $key = config('gemini_api_key');
    if (empty($key)) return ['success' => false, 'error' => 'Gemini API key not set'];

    $model = 'gemini-2.0-flash';
    $url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($key);
    $payload = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'generationConfig'  => ['temperature' => 0.3, 'maxOutputTokens' => $max_tokens],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 45,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        return ['success' => false, 'error' => "Gemini HTTP {$code}: " . substr((string)$body, 0, 200)];
    }
    $data = json_decode($body, true);
    $text = '';
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
        $text .= $part['text'] ?? '';
    }
    if ($text === '') return ['success' => false, 'error' => 'Gemini returned empty response'];
    return ['success' => true, 'content' => $text];
}

/**
 * Build the industry-recall question set for a site. Same question set
 * across all engines so scores are comparable.
 */
function recall_build_questions(array $site, PDO $db): array
{
    $stmt = $db->prepare("SELECT keyword FROM keywords WHERE site_id = ? AND status = 'active' ORDER BY priority DESC LIMIT 10");
    $stmt->execute([$site['id']]);
    $keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $topics   = json_decode($site['topics'] ?? '[]', true) ?: [];
    $name     = $site['name'];
    $domain   = $site['domain'];

    $q = [];
    $q[] = ['type' => 'brand', 'query' => "What do you know about {$name}? What does {$domain} do?", 'expect' => $name];
    if (!empty($keywords)) {
        $q[] = ['type' => 'industry', 'query' => "What are the best companies for {$keywords[0]}?", 'expect' => $name];
    }
    if (!empty($topics)) {
        $q[] = ['type' => 'topic', 'query' => "Who are the top providers of {$topics[0]} services?", 'expect' => $name];
    }
    $q[] = ['type' => 'recommendation', 'query' => "Can you recommend a company like {$name}? What alternatives exist?", 'expect' => $name];
    if (!empty($keywords) && count($keywords) > 2) {
        $q[] = ['type' => 'authority', 'query' => "What are the best resources or blogs about {$keywords[1]}?", 'expect' => $domain];
    }
    return $q;
}

/**
 * Run the industry-recall check for ONE engine. Stores a snapshot row.
 * Returns { engine, score, mentioned, total, results }.
 */
function check_ai_visibility(array $site, PDO $db, string $engine = 'claude'): array
{
    $questions = recall_build_questions($site, $db);
    $name = $site['name']; $domain = $site['domain'];

    $sys = "You are a helpful AI assistant. Answer the user's question based on your knowledge. "
         . "Be specific and name real companies, websites, and resources when relevant. "
         . "Do not make up information you don't know.";

    $results = []; $mentioned = 0;
    foreach ($questions as $q) {
        $resp = recall_chat($engine, $sys, $q['query'], 1024);
        $text = $resp['success'] ? ($resp['content'] ?? '') : '';
        $hit = false;
        if ($text !== '') {
            $hit = stripos($text, $q['expect']) !== false
                || stripos($text, $name) !== false
                || stripos($text, $domain) !== false;
        }
        if ($hit) $mentioned++;
        $results[] = [
            'type'         => $q['type'],
            'query'        => $q['query'],
            'mentioned'    => $hit,
            'response'     => $text,
            'searched_for' => $q['expect'],
            'error'        => $resp['success'] ? null : ($resp['error'] ?? 'unknown error'),
        ];
    }

    $total = count($results);
    $score = $total > 0 ? (int)round(($mentioned / $total) * 100) : 0;

    // Persist snapshot for this engine + today
    $today = date('Y-m-d');
    $db->prepare('INSERT INTO ai_recall_snapshots (site_id, engine, snapshot_date, score, mentioned, total_questions, results_json)
                  VALUES (?, ?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                    score = VALUES(score),
                    mentioned = VALUES(mentioned),
                    total_questions = VALUES(total_questions),
                    results_json = VALUES(results_json)')
       ->execute([
           (int)$site['id'], $engine, $today,
           $score, $mentioned, $total,
           json_encode($results),
       ]);

    return [
        'engine'    => $engine,
        'score'     => $score,
        'mentioned' => $mentioned,
        'total'     => $total,
        'results'   => $results,
    ];
}

/**
 * Run the recall check across EVERY configured engine. Returns per-engine
 * results + a combined "any-engine" score.
 */
function check_ai_visibility_all_engines(array $site, PDO $db): array
{
    $engines = recall_available_engines();
    $per_engine = [];
    foreach ($engines as $e) {
        $per_engine[$e] = check_ai_visibility($site, $db, $e);
    }

    // "Any-engine" mentioned: a question counts as mentioned if ANY engine knew
    $any_count = 0; $total = 0;
    if (!empty($per_engine)) {
        $first = reset($per_engine);
        $total = $first['total'];
        for ($i = 0; $i < $total; $i++) {
            foreach ($per_engine as $r) {
                if (!empty($r['results'][$i]['mentioned'])) { $any_count++; break; }
            }
        }
    }
    $any_score = $total > 0 ? (int)round(($any_count / $total) * 100) : 0;

    return [
        'per_engine' => $per_engine,
        'any_score'  => $any_score,
        'any_count'  => $any_count,
        'total'      => $total,
        'engines'    => $engines,
    ];
}

/**
 * Load the latest snapshot per engine for a site.
 * Returns keyed-by-engine array of { score, mentioned, total, results, snapshot_date }
 */
function recall_latest_per_engine(PDO $db, int $site_id): array
{
    $stmt = $db->prepare('SELECT engine, snapshot_date, score, mentioned, total_questions, results_json
                          FROM ai_recall_snapshots
                          WHERE site_id = ?
                          ORDER BY snapshot_date DESC');
    $stmt->execute([$site_id]);
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $e = $r['engine'];
        if (isset($out[$e])) continue;
        $out[$e] = [
            'score'         => (int)$r['score'],
            'mentioned'     => (int)$r['mentioned'],
            'total'         => (int)$r['total_questions'],
            'results'       => json_decode($r['results_json'] ?: '[]', true) ?: [],
            'snapshot_date' => $r['snapshot_date'],
        ];
    }
    return $out;
}
