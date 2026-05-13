<?php
/**
 * Claude Haiku API wrapper.
 * Single point of control for all AI calls.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Send a message to Claude Haiku and get a response.
 *
 * @param string $system_prompt  System-level instructions
 * @param string $user_message   The user/agent prompt
 * @param int    $max_tokens     Max response tokens (default from config)
 * @return array ['success' => bool, 'content' => string, 'usage' => array, 'error' => string|null]
 */
function haiku_chat(string $system_prompt, string $user_message, int $max_tokens = 0): array
{
    $api_key    = config('haiku_api_key');
    $model      = config('haiku_model');
    $max_tokens = $max_tokens ?: config('haiku_max_tokens');

    $payload = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'system'     => $system_prompt,
        'messages'   => [
            ['role' => 'user', 'content' => $user_message],
        ],
    ];

    $response = http_post(
        'https://api.anthropic.com/v1/messages',
        $payload,
        [
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        120
    );

    if ($response['error']) {
        agent_log('Haiku API error: ' . $response['error'], 'ERROR');
        return [
            'success' => false,
            'content' => '',
            'usage'   => [],
            'error'   => $response['error'],
        ];
    }

    $data = json_decode($response['body'], true);

    if ($response['status'] !== 200) {
        $error_msg = $data['error']['message'] ?? 'HTTP ' . $response['status'];
        agent_log('Haiku API HTTP error: ' . $error_msg, 'ERROR');
        return [
            'success' => false,
            'content' => '',
            'usage'   => [],
            'error'   => $error_msg,
        ];
    }

    $content = '';
    foreach ($data['content'] ?? [] as $block) {
        if ($block['type'] === 'text') {
            $content .= $block['text'];
        }
    }

    return [
        'success' => true,
        'content' => $content,
        'usage'   => $data['usage'] ?? [],
        'error'   => null,
    ];
}

/**
 * Generate a blog post from a topic/keyword.
 */
function haiku_write_blog(string $topic, string $brand_tone, array $keywords = [], int $word_count = 1000, ?array $site = null, ?array $serp_brief = null): array
{
    $keyword_list = implode(', ', $keywords);

    $current_year = date('Y');
    $current_date = date('d M Y');

    // Site-aware system prompt — ground in customer's own words
    $site_name = $site['name'] ?? 'our company';
    $site_domain = $site['domain'] ?? '';
    $topics = json_decode($site['topics'] ?? '[]', true) ?: [];
    $niche = !empty($topics) ? implode(', ', array_slice($topics, 0, 3)) : 'our industry';
    $business_desc = trim($site['business_description'] ?? '');
    $business_line = $business_desc ? "WHAT WE ACTUALLY DO (from owner): {$business_desc}\n\n" : '';

    // SERP brief — model the post on what's actually ranking
    $serp_lines = '';
    if (is_array($serp_brief) && !empty($serp_brief)) {
        $bits = [];
        if (!empty($serp_brief['format']))           $bits[] = "Format: {$serp_brief['format']}";
        if (!empty($serp_brief['intent']))           $bits[] = "Intent: {$serp_brief['intent']}";
        if (!empty($serp_brief['avg_word_count']))   $bits[] = "Target length: ~{$serp_brief['avg_word_count']} words (this is what top results have)";
        if (!empty($serp_brief['winning_pattern']))  $bits[] = "Winning pattern: {$serp_brief['winning_pattern']}";

        $outline = $serp_brief['recommended_outline'] ?? [];
        if (!empty($outline) && is_array($outline)) {
            $bits[] = "Recommended H2 sections (model these): " . implode(' | ', array_slice($outline, 0, 7));
        }
        $themes = $serp_brief['common_themes'] ?? [];
        if (!empty($themes) && is_array($themes)) {
            $bits[] = "Common themes to cover: " . implode(', ', array_slice($themes, 0, 8));
        }
        if (!empty($bits)) {
            $serp_lines = "WHAT'S RANKING ON GOOGLE FOR THIS TOPIC (use this to compete):\n- " . implode("\n- ", $bits) . "\n\n";
        }
    }

    $system = "You are the content writer for {$site_name}" . ($site_domain ? " ({$site_domain})" : "") . ".

{$business_line}{$serp_lines}WRITING STYLE:
- Write as {$site_name}. Use \"we\", \"our team\" naturally.
- Opinionated, direct, no fluff. Lead with the answer, not the question.
- Tone: {$brand_tone}
- Short paragraphs. No corporate jargon. Write like you're explaining to a smart reader.
- Start posts with a bold statement or insight, not \"In today's fast-paced world...\"
- Our niche: {$niche}
- STAY STRICTLY within what we actually do. Do not invent products or services we don't offer.

IMPORTANT: Today is {$current_date}. Current year is {$current_year}. NEVER use 2024 or 2025.

Output ONLY valid JSON with keys: title, seo_title, seo_description, excerpt, body (HTML), tags (array of strings).
Body = well-structured HTML with H2/H3 subheadings, <p>, <ul>/<li>, <strong>.
Do not wrap in markdown code blocks.";

    // If brief had a target word count, use it; otherwise use the requested count
    $target_words = (is_array($serp_brief) && !empty($serp_brief['avg_word_count']) && $serp_brief['avg_word_count'] > 300)
        ? (int)$serp_brief['avg_word_count']
        : $word_count;

    $prompt = "Write a {$target_words}-word SEO blog post about: {$topic}
Target keywords: {$keyword_list}
Year: {$current_year}

Write from {$site_name}'s perspective. Make it genuinely useful and authoritative.
Include: a punchy title, 3-4 H2 sections, specific actionable advice, and a clear conclusion.";

    $result = haiku_chat($system, $prompt);

    if (!$result['success']) return $result;

    // Strip markdown code blocks if present
    $content = $result['content'];
    $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
    $content = preg_replace('/\s*```\s*$/m', '', $content);
    $content = trim($content);

    $parsed = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Try to extract JSON from the response
        if (preg_match('/\{[\s\S]*\}/m', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
        if (!$parsed) {
            return [
                'success' => false,
                'content' => $result['content'],
                'usage'   => $result['usage'],
                'error'   => 'Failed to parse JSON response: ' . json_last_error_msg(),
            ];
        }
    }

    $result['parsed'] = $parsed;
    return $result;
}

/**
 * Generate meta title and description for a page.
 */
function haiku_generate_meta(string $page_url, string $page_content): array
{
    $system = "You are an SEO specialist. Output ONLY valid JSON with keys: seo_title (max 60 chars), seo_description (max 160 chars).";

    $content_preview = mb_substr(strip_tags($page_content), 0, 2000);
    $prompt = "Generate an optimal SEO meta title and description for this page.
URL: {$page_url}
Content preview: {$content_preview}";

    return haiku_chat($system, $prompt, 256);
}

/**
 * Generate alt text for an image based on context.
 */
function haiku_generate_alt_text(string $image_url, string $page_context): array
{
    $system = "You are an accessibility specialist. Output ONLY a single descriptive alt text string (max 125 characters). No JSON, no quotes, just the text.";

    $prompt = "Write descriptive alt text for an image.
Image URL: {$image_url}
Page context: {$page_context}";

    return haiku_chat($system, $prompt, 64);
}

/**
 * Analyze a website and generate brand tone description.
 */
function haiku_analyze_brand(string $site_content): array
{
    $system = "You are a brand strategist. Analyze the website content and output ONLY valid JSON with keys:
tone (string, e.g. 'professional and approachable'),
topics (array of main topic areas),
audience (string, target audience description),
style_notes (string, writing style recommendations).";

    $preview = mb_substr($site_content, 0, 4000);
    $prompt = "Analyze this website content and determine the brand voice, key topics, and target audience:\n\n{$preview}";

    return haiku_chat($system, $prompt, 512);
}

/**
 * Generate JSON-LD schema markup for a page.
 */
function haiku_generate_schema(string $page_type, string $page_url, string $page_content): array
{
    $system = "You are a structured data specialist. Output ONLY valid JSON-LD schema markup (no markdown, no explanation).
Use schema.org vocabulary. Include all required and recommended properties.";

    $preview = mb_substr(strip_tags($page_content), 0, 2000);
    $prompt = "Generate JSON-LD structured data for this page.
Page type: {$page_type}
URL: {$page_url}
Content: {$preview}";

    return haiku_chat($system, $prompt, 1024);
}
