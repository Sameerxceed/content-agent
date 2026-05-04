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
function haiku_write_blog(string $topic, string $brand_tone, array $keywords = [], int $word_count = 1000): array
{
    $keyword_list = implode(', ', $keywords);

    $current_year = date('Y');
    $current_date = date('d M Y');

    $system = "You are the content writer for Xceed Imagination — a boutique software studio in Pune, India with 12+ years experience and 200+ shipped projects. Team of ~40 engineers.

WRITING STYLE (match this exactly):
- First person plural: \"we\", \"our team\", \"at Xceed\"
- Opinionated, direct, no fluff. Lead with the answer, not the question.
- Use real-world context: mention actual project types you've built (ERPs, marketplaces, AI pipelines, mobile apps)
- Include specific numbers and ranges where relevant (timelines, costs, team sizes)
- Tone: confident practitioner sharing hard-won knowledge, NOT a generic content mill
- Short paragraphs. No corporate jargon. Write like you're explaining to a smart founder.
- Start posts with a bold statement or counter-intuitive insight, not \"In today's fast-paced world...\"

Brand voice: $brand_tone
IMPORTANT: Today is {$current_date}. Current year is {$current_year}. NEVER use 2024 or 2025.

Output ONLY valid JSON with keys: title, seo_title, seo_description, excerpt, body (HTML), tags (array of strings).
Body = well-structured HTML with H2/H3 subheadings, <p>, <ul>/<li>, <strong>.
Do not wrap in markdown code blocks.";

    $prompt = "Write a {$word_count}-word SEO blog post about: {$topic}
Target keywords: {$keyword_list}
Year: {$current_year}

Write from Xceed Imagination's perspective — a Pune-based studio that builds custom software and AI solutions.
Reference real experience where appropriate (without inventing specific client names).
Make it genuinely useful — the kind of post a CTO or founder would bookmark.
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
