<?php
/**
 * Claude Haiku API wrapper.
 * Single point of control for all AI calls.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_cost.php';
require_once __DIR__ . '/quotas.php';

/**
 * Send a message to Claude Haiku and get a response.
 *
 * @param string   $system_prompt System-level instructions
 * @param string   $user_message  The user/agent prompt
 * @param int      $max_tokens    Max response tokens (default from config)
 * @param string   $feature       Stable identifier for cost tracking, e.g.
 *                                'blog_write', 'redirect_fuzzy_match',
 *                                'plan_cluster_pick'. Default 'unspecified'
 *                                so existing call sites don't break — they
 *                                get backfilled to a real feature name
 *                                over time.
 * @param int|null $site_id       Optional — for cost-per-site reporting.
 * @return array ['success' => bool, 'content' => string, 'usage' => array, 'error' => string|null]
 */
function haiku_chat(string $system_prompt, string $user_message, int $max_tokens = 0, string $feature = 'unspecified', ?int $site_id = null): array
{
    $api_key    = config('haiku_api_key');
    $model      = config('haiku_model');
    $max_tokens = $max_tokens ?: config('haiku_max_tokens');

    // ── Guardrails ─────────────────────────────────────────────────
    // Master monthly-budget check. Bypasses on super-admin sites + on
    // calls without site context (rare global ops). Returns a structured
    // error instead of throwing so existing callers don't crash.
    if ($site_id) {
        try {
            $db_for_guard = _ai_db();
            if ($db_for_guard) {
                $q = quota_check_budget($db_for_guard, $site_id);
                if (!$q['allowed']) {
                    return [
                        'success' => false,
                        'content' => '',
                        'usage'   => [],
                        'error'   => 'QUOTA_EXCEEDED: ' . $q['message'],
                        'quota'   => $q,
                    ];
                }
                $m = quota_check_model_allowed($db_for_guard, $site_id, $model);
                if (!$m['allowed']) {
                    return [
                        'success' => false,
                        'content' => '',
                        'usage'   => [],
                        'error'   => 'QUOTA_EXCEEDED: ' . $m['message'],
                        'quota'   => $m,
                    ];
                }
            }
        } catch (Throwable $e) {
            // Guard failure must never block the call — log and proceed.
            error_log('[haiku quota_check] ' . $e->getMessage());
        }
    }

    $payload = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'system'     => $system_prompt,
        'messages'   => [
            ['role' => 'user', 'content' => $user_message],
        ],
    ];

    $t0 = microtime(true);
    $response = http_post(
        'https://api.anthropic.com/v1/messages',
        $payload,
        [
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        120
    );
    $ms = (int)round((microtime(true) - $t0) * 1000);

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

    // Log the call cost. ai_log_call resolves its own PDO and no-ops if the
    // ai_calls table doesn't exist yet (pre-Phase 0). Cannot break the call.
    ai_log_call('anthropic', $model, $feature, $site_id, $data['usage'] ?? [], $ms);

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
    // profile_get() returns topics as an array; raw DB row returns it as JSON string.
    $topics = is_array($site['topics'] ?? null) ? $site['topics'] : (json_decode($site['topics'] ?? '[]', true) ?: []);
    $niche = !empty($topics) ? implode(', ', array_slice($topics, 0, 3)) : 'our industry';
    $business_desc = trim($site['business_description'] ?? '');
    $persona       = trim($site['persona'] ?? '');
    $usp           = trim($site['usp'] ?? '');
    $business_line = $business_desc ? "WHAT WE ACTUALLY DO (from owner): {$business_desc}\n\n" : '';
    if ($persona) $business_line .= "OUR IDEAL READER: {$persona}\n\n";
    if ($usp)     $business_line .= "WHAT MAKES US DIFFERENT (use this naturally, never as a sales pitch): {$usp}\n\n";

    // Structured business profile — calibrates authority/scale of the writing.
    // (A bootstrapped 15-person consultancy shouldn't write like Gartner; an
    // enterprise leader shouldn't write like a hobbyist blog.) Only injected
    // when at least one structured field is present.
    if (!empty($site['size_tier']) || !empty($site['business_model']) || !empty($site['industry_category'])) {
        if (function_exists('profile_prompt_block')) {
            $business_line .= profile_prompt_block($site) . "\n\n";
        }
    }

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

/**
 * Repurpose a blog post for a specific channel.
 * Returns the channel-formatted text ready to publish.
 *
 * @param array  $post    Post row (title, body, excerpt, slug, etc.)
 * @param string $channel One of: linkedin, twitter, reddit, newsletter
 * @param array  $site    Site row (name, domain, brand_tone, business_description, persona, usp)
 * @return array ['success' => bool, 'content' => string, 'error' => ?string]
 */
function haiku_repurpose_for_channel(array $post, string $channel, array $site): array
{
    $title    = $post['title'] ?? '';
    $excerpt  = $post['excerpt'] ?? '';
    $body     = trim(strip_tags($post['body'] ?? ''));
    $blog_url = !empty($site['domain']) && !empty($post['slug'])
        ? 'https://' . ltrim($site['domain'], 'https://') . '/blog/' . $post['slug']
        : '';

    $site_name     = $site['name'] ?? 'we';
    $brand_tone    = $site['brand_tone'] ?? 'professional';
    $business_desc = trim($site['business_description'] ?? '');
    $persona       = trim($site['persona'] ?? '');
    $usp           = trim($site['usp'] ?? '');

    $brand_block = '';
    if ($business_desc) $brand_block .= "WE DO: {$business_desc}\n";
    if ($persona)       $brand_block .= "OUR AUDIENCE: {$persona}\n";
    if ($usp)           $brand_block .= "WHAT MAKES US DIFFERENT: {$usp}\n";

    $body_excerpt = mb_substr($body, 0, 2000);

    $templates = [
        'linkedin' => [
            'system' => "You write LinkedIn posts that get engagement.\n"
                . "Constraints: 150-220 words. First line is a hook (1 short sentence) — punchy, contrarian, or curious. "
                . "No corporate jargon. No emojis at start. 2-3 short paragraphs with line breaks. "
                . "End with a soft question that invites discussion. Don't sell.\n"
                . "Tone: {$brand_tone}. Voice: {$site_name} (first person plural 'we').\n"
                . "{$brand_block}"
                . "Output ONLY the post text — no labels, no quotes, no markdown.",
            'prompt' => "Source blog post:\n\nTitle: {$title}\n\nKey points: {$body_excerpt}\n\n"
                . ($blog_url ? "Link to the full post: {$blog_url}\n\n" : '')
                . "Write a LinkedIn post that distills the most insightful point. End with: 'Full breakdown: " . ($blog_url ?: '[blog link]') . "' on its own line.",
        ],
        'twitter' => [
            'system' => "You write Twitter/X threads.\n"
                . "Constraints: 5-8 tweets. Each tweet <= 270 chars (leave room). Number them like '1/' '2/' ... '8/8'. "
                . "Tweet 1 = the hook (no '1/' prefix — just a strong opening line). Tweet 2+ = numbered. "
                . "Last tweet = soft CTA with the blog link.\n"
                . "Tone: {$brand_tone}. Voice: {$site_name}.\n"
                . "{$brand_block}"
                . "Output ONLY the thread, one tweet per line, blank line between tweets.",
            'prompt' => "Source blog post:\n\nTitle: {$title}\n\nContent: {$body_excerpt}\n\n"
                . ($blog_url ? "Link: {$blog_url}\n\n" : '')
                . "Turn this into a Twitter thread of 5-8 tweets.",
        ],
        'reddit' => [
            'system' => "You write Reddit posts.\n"
                . "Reddit hates promotional content. Frame as a discussion or genuinely useful observation, not as an ad.\n"
                . "Constraints: 250-400 words. No marketing speak. No 'check out our blog'. "
                . "If linking to source, do so as a footnote 'Source: ...' at the end, casually. "
                . "Title separate from body — first line is the title, then a blank line, then the body.\n"
                . "{$brand_block}"
                . "Output ONLY the title + body. No labels.",
            'prompt' => "Source blog post:\n\nTitle: {$title}\n\nContent: {$body_excerpt}\n\n"
                . ($blog_url ? "Optional source link: {$blog_url}\n\n" : '')
                . "Convert this into a Reddit-appropriate post that adds value to a community discussion. Pick a tighter angle than the original blog title.",
        ],
        'newsletter' => [
            'system' => "You write the body of a weekly newsletter section featuring one new blog post.\n"
                . "Constraints: 80-130 words. Friendly, conversational tone. "
                . "Tease the post but don't repeat its full content. End with a 'Read more →' inviting the click.\n"
                . "Tone: {$brand_tone}. Voice: {$site_name}.\n"
                . "{$brand_block}"
                . "Output ONLY the section text.",
            'prompt' => "Source blog post:\n\nTitle: {$title}\n\n" . ($excerpt ? "Excerpt: {$excerpt}\n\n" : '')
                . "Content: {$body_excerpt}\n\n"
                . ($blog_url ? "Link: {$blog_url}\n\n" : '')
                . "Write the newsletter section featuring this post.",
        ],
        'pinterest' => [
            // Pinterest pins have a title (~100 char) AND a description (~500
            // char). We return both as "Title\n\nDescription" — the channel
            // adapter splits on the blank line. Pinterest's algorithm rewards
            // keyword-rich descriptions + 3-6 relevant hashtags at the end.
            'system' => "You write Pinterest pins for a business that drives traffic from Pinterest searchers to its blog.\n"
                . "Format the output as exactly two parts separated by a blank line:\n"
                . "  PART 1 (title): a click-worthy headline, max 90 characters, no clickbait. Title case. No emoji.\n"
                . "  PART 2 (description): 150-300 characters that promise what the reader will get from the linked post. "
                . "Use specific words a Pinterest user would actually search for. End with 3-6 relevant hashtags (lowercase, no spaces).\n"
                . "Tone: {$brand_tone}. Voice: {$site_name}.\n"
                . "{$brand_block}"
                . "Output ONLY the title, blank line, then the description with hashtags. No labels, no quotes.",
            'prompt' => "Source blog post:\n\nTitle: {$title}\n\nContent: {$body_excerpt}\n\n"
                . ($blog_url ? "Link Pinterest users will click to: {$blog_url}\n\n" : '')
                . "Write the pin title and description that will make Pinterest searchers tap through.",
        ],
    ];

    if (!isset($templates[$channel])) {
        return ['success' => false, 'content' => '', 'error' => "No repurpose template for channel '{$channel}'"];
    }

    $tpl = $templates[$channel];
    $result = haiku_chat($tpl['system'], $tpl['prompt'], 1024);

    if (!$result['success']) {
        return ['success' => false, 'content' => '', 'error' => $result['error']];
    }

    return ['success' => true, 'content' => trim($result['content']), 'error' => null];
}
