<?php
/**
 * AI Visibility Monitor — checks if a brand/site is mentioned in AI responses.
 *
 * Uses Claude Haiku to simulate what AI models know about the brand.
 * Asks industry-relevant questions and checks if the brand appears.
 */

require_once __DIR__ . '/haiku.php';

/**
 * Run AI visibility check for a site.
 * Returns array of queries, whether the brand was mentioned, and the AI response.
 */
function check_ai_visibility(array $site, PDO $db): array
{
    $domain = $site['domain'];
    $name = $site['name'];
    $topics = json_decode($site['topics'] ?? '[]', true) ?: [];
    $platform = $site['platform'] ?? 'custom';

    // Get keywords for context
    $stmt = $db->prepare('SELECT keyword FROM keywords WHERE site_id = ? ORDER BY priority DESC LIMIT 10');
    $stmt->execute([$site['id']]);
    $keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Build test queries based on site context
    $queries = [];

    // Brand-specific queries
    $queries[] = [
        'type' => 'brand',
        'query' => "What do you know about {$name}? What does {$domain} do?",
        'expect' => $name,
    ];

    // Industry queries from keywords
    if (!empty($keywords)) {
        $top_kw = $keywords[0];
        $queries[] = [
            'type' => 'industry',
            'query' => "What are the best companies for {$top_kw}?",
            'expect' => $name,
        ];
    }

    if (!empty($topics)) {
        $topic = $topics[0];
        $queries[] = [
            'type' => 'topic',
            'query' => "Who are the top providers of {$topic} services?",
            'expect' => $name,
        ];
    }

    // Location-based if applicable
    $queries[] = [
        'type' => 'recommendation',
        'query' => "Can you recommend a company like {$name}? What alternatives exist?",
        'expect' => $name,
    ];

    // Content authority
    if (!empty($keywords) && count($keywords) > 2) {
        $queries[] = [
            'type' => 'authority',
            'query' => "What are the best resources or blogs about {$keywords[1]}?",
            'expect' => $domain,
        ];
    }

    // Run each query through AI
    $results = [];
    $mentioned_count = 0;

    foreach ($queries as $q) {
        $system = "You are a helpful AI assistant. Answer the user's question based on your knowledge. "
            . "Be specific and name real companies, websites, and resources when relevant. "
            . "Do not make up information you don't know.";

        $response = haiku_chat($system, $q['query'], 1024);

        $mentioned = false;
        $ai_text = '';

        if ($response['success'] && !empty($response['content'])) {
            $ai_text = $response['content'];
            // Check if brand name or domain is mentioned
            $mentioned = stripos($ai_text, $q['expect']) !== false
                      || stripos($ai_text, $name) !== false
                      || stripos($ai_text, $domain) !== false;
        }

        if ($mentioned) $mentioned_count++;

        $results[] = [
            'type' => $q['type'],
            'query' => $q['query'],
            'mentioned' => $mentioned,
            'response' => $ai_text,
            'searched_for' => $q['expect'],
        ];
    }

    $total = count($results);
    $score = $total > 0 ? round(($mentioned_count / $total) * 100) : 0;

    return [
        'score' => $score,
        'mentioned' => $mentioned_count,
        'total' => $total,
        'results' => $results,
    ];
}
