<?php
/**
 * Content artifacts — the multi-artifact generator + channel fit matrix.
 *
 * For each plan item, ONE structured Claude call returns the complete
 * package: blog body (with embedded FAQ section), schema.org JSON-LD blob,
 * LinkedIn snippet, Twitter thread, Reddit post, newsletter teaser, hero
 * image prompt, and internal-link suggestions. One call is cheaper, faster,
 * and produces consistent voice across channels.
 *
 * The fit matrix decides which channels are appropriate for which content
 * types (e.g. glossary entries don't tweet, service pages don't Reddit).
 *
 * Public API:
 *   content_artifacts_fit_matrix(): array
 *   content_fits_channel(string $type, string $channel): bool
 *   content_artifacts_resolve_channels(array $configured, string $type): array
 *   content_artifacts_generate_full_package(PDO $db, int $item_id): array
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/business_profile.php';
require_once __DIR__ . '/haiku.php';
require_once __DIR__ . '/schema-generator.php';

/**
 * Build the JSON-LD bundle for a freshly-drafted post: Article + FAQPage +
 * BreadcrumbList. Generated PHP-side (deterministic) instead of asked of
 * Claude (hallucination-prone). Returns an array of decoded schema dicts
 * ready to JSON-encode into post_channels.variant_content for the 'schema'
 * channel — what AI engines and Google read for rich snippets.
 *
 * Caller passes the FAQs from the Claude package ([{q, a}, ...]) and we
 * normalise + dedupe. If $faqs is empty we skip FAQPage.
 */
function content_artifacts_compose_schema(PDO $db, int $post_id, array $faqs, ?array $site_override = null): array
{
    $stmt = $db->prepare("SELECT p.*, s.name AS site_name, s.domain, s.blog_path
        FROM posts p JOIN sites s ON s.id = p.site_id WHERE p.id = ?");
    $stmt->execute([$post_id]);
    $row = $stmt->fetch();
    if (!$row) return [];

    $site = $site_override ?: [
        'name'      => (string)$row['site_name'],
        'domain'    => (string)$row['domain'],
        'blog_path' => $row['blog_path'] ?: '/blog',
    ];
    $base_url  = 'https://' . ltrim((string)$site['domain'], 'https://');
    $blog_path = $site['blog_path'] ?: '/blog';

    $bundle = [];

    // 1. Article (BlogPosting or NewsArticle) — always emit
    $article_json = schema_blog_post([
        'title'           => (string)($row['title'] ?? ''),
        'slug'            => (string)($row['slug'] ?? ''),
        'body'            => (string)($row['body'] ?? ''),
        'seo_description' => (string)($row['seo_description'] ?? ''),
        'type'            => (string)($row['type'] ?? 'blog'),
        'tags'            => (string)($row['seo_keywords'] ?? '[]'),
        'created_at'      => (string)($row['created_at'] ?? date('c')),
        'updated_at'      => (string)($row['updated_at'] ?? date('c')),
        'published_at'    => $row['published_at'] ?? null,
    ], [
        'name'      => $site['name'],
        'domain'    => $site['domain'],
        'blog_path' => $blog_path,
    ]);
    $article = json_decode($article_json, true);
    if (is_array($article)) $bundle[] = $article;

    // 2. FAQPage — only if Claude returned FAQs
    if (!empty($faqs)) {
        $normalised = [];
        foreach ($faqs as $f) {
            $q = trim((string)($f['q'] ?? $f['question'] ?? ''));
            $a = trim((string)($f['a'] ?? $f['answer'] ?? ''));
            if ($q !== '' && $a !== '') {
                $normalised[] = ['question' => $q, 'answer' => $a];
            }
        }
        if (!empty($normalised)) {
            $faq_json = schema_faq($normalised);
            $faq = json_decode($faq_json, true);
            if (is_array($faq)) $bundle[] = $faq;
        }
    }

    // 3. BreadcrumbList — Home > Blog > Post Title
    $crumbs_json = schema_breadcrumbs([
        ['name' => 'Home',         'url' => $base_url . '/'],
        ['name' => 'Blog',         'url' => $base_url . rtrim($blog_path, '/') . '/'],
        ['name' => (string)$row['title'], 'url' => $base_url . rtrim($blog_path, '/') . '/' . (string)$row['slug']],
    ], $base_url);
    $crumbs = json_decode($crumbs_json, true);
    if (is_array($crumbs)) $bundle[] = $crumbs;

    return $bundle;
}

/**
 * Channel-content fit matrix. Channels that don't appear in the matrix
 * row for a given content_type are skipped at distribution time.
 *
 * 'schema' and 'llms' are always included downstream — they're metadata,
 * not channels, but live in the same channels[] array on plan items.
 */
function content_artifacts_fit_matrix(): array
{
    // Reddit removed 2026-06-02: Reddit's post-2023 API policy (Devvit-only
    // for new apps, weeks-long manual review for the legacy Data API, low
    // approval rate for marketing use cases) makes it unworkable as a
    // publishing channel. To re-enable: add 'reddit' back here AND uncomment
    // the wizard registration in includes/setup_wizards/registry.php.
    return [
        'pillar'       => ['cms', 'linkedin', 'twitter', 'newsletter'],
        'blog'         => ['cms', 'linkedin', 'twitter', 'newsletter'],
        'comparison'   => ['cms', 'linkedin', 'twitter', 'newsletter'],
        'guide'        => ['cms', 'linkedin', 'twitter', 'newsletter'],
        'service_page' => ['cms', 'linkedin', 'newsletter'],
        'glossary'     => ['cms'],
        'news'         => ['cms', 'linkedin', 'twitter', 'newsletter'],
    ];
}

function content_fits_channel(string $content_type, string $channel): bool
{
    $matrix = content_artifacts_fit_matrix();
    return in_array($channel, $matrix[$content_type] ?? [], true);
}

/**
 * Given the site's configured channel IDs and the item's content_type,
 * return the channels this item should publish to. Always includes
 * 'schema' and 'llms' (metadata always emitted).
 */
function content_artifacts_resolve_channels(array $configured_channel_ids, string $content_type): array
{
    $matrix = content_artifacts_fit_matrix();
    $allowed = $matrix[$content_type] ?? ['cms'];
    $out = [];
    foreach ($configured_channel_ids as $ch) {
        if (in_array($ch, $allowed, true)) $out[] = $ch;
    }
    if (!in_array('cms', $out, true) && in_array('cms', $configured_channel_ids, true)) {
        // CMS should always be included if connected
        array_unshift($out, 'cms');
    }
    $out[] = 'schema';
    $out[] = 'llms';
    return array_values(array_unique($out));
}

/**
 * Generate the full multi-artifact package for a plan item. Single Claude
 * call returns blog + faq + schema + linkedin + twitter + reddit + newsletter
 * + image prompt + internal-link suggestions. Caller validates and persists.
 *
 * Returns the parsed JSON structure or throws RuntimeException on failure.
 */
/**
 * Generate the full multi-artifact package for a plan item.
 *
 * $channels: explicit channel-id list (preferred — late-binding). When NULL,
 * we resolve the live set from the channel registry intersected with the fit
 * matrix for this item's content_type. The stored item.channels JSON is only
 * used as a last-resort fallback (legacy items pre-late-binding).
 */
function content_artifacts_generate_full_package(PDO $db, int $item_id, ?array $channels = null): array
{
    // ── Load the plan item + denormalised context ──────────────
    $stmt = $db->prepare("SELECT i.*, p.site_id AS plan_site, c.name AS cluster_name, c.angle AS cluster_angle,
            k.keyword AS primary_keyword, k.buyer_question, k.intent AS keyword_intent, k.search_volume,
            k.difficulty, k.serp_brief
        FROM content_plan_items i
        JOIN content_plans p          ON i.plan_id = p.id
        JOIN content_plan_clusters c  ON i.cluster_id = c.id
        JOIN keywords k               ON i.primary_keyword_id = k.id
        WHERE i.id = ?");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch();
    if (!$item) throw new RuntimeException("Plan item {$item_id} not found");

    $site_id = (int)$item['site_id'];
    $profile = profile_get($db, $site_id);
    if (!$profile) throw new RuntimeException('Business profile not found');

    // Resolve secondary keywords
    $secondary_ids = json_decode($item['secondary_keyword_ids'] ?? '[]', true) ?: [];
    $secondaries = [];
    if (!empty($secondary_ids)) {
        $in = implode(',', array_fill(0, count($secondary_ids), '?'));
        $s2 = $db->prepare("SELECT id, keyword, buyer_question, intent FROM keywords WHERE id IN ({$in})");
        $s2->execute($secondary_ids);
        $secondaries = $s2->fetchAll();
    }

    // Pillar URL context (for supporting items in the same cluster)
    $pillar_url = null;
    if ($item['role'] !== 'pillar') {
        $stmt = $db->prepare("SELECT p.slug FROM content_plan_items i
            JOIN posts p ON i.post_id = p.id
            WHERE i.cluster_id = ? AND i.role = 'pillar' AND p.status IN ('approved','published')
            LIMIT 1");
        $stmt->execute([(int)$item['cluster_id']]);
        $pillar_url = $stmt->fetchColumn() ?: null;
    }

    // Resolve channel variants needed at draft time (late-binding).
    // Caller passes the freshly-resolved set; if not, we resolve here from
    // the registry + fit matrix so newly-connected channels flow through.
    if ($channels === null) {
        require_once __DIR__ . '/channels/registry.php';
        $site_stmt = $db->prepare("SELECT * FROM sites WHERE id = ?");
        $site_stmt->execute([$site_id]);
        $site_row = $site_stmt->fetch();
        $configured = $site_row ? array_keys(channels_registry()->configured_for($site_row)) : [];
        $channels = content_artifacts_resolve_channels($configured, (string)$item['content_type']);
        // Last-resort fallback for legacy items: use the snapshot
        if (empty($channels)) {
            $channels = json_decode($item['channels'] ?? '[]', true) ?: ['cms'];
        }
    }
    $needs = [
        'linkedin'   => in_array('linkedin',   $channels, true),
        'twitter'    => in_array('twitter',    $channels, true),
        'reddit'     => in_array('reddit',     $channels, true),
        'newsletter' => in_array('newsletter', $channels, true),
    ];

    $serp_brief = $item['serp_brief'] ? json_decode($item['serp_brief'], true) : null;
    $word_count = (int)($item['recommended_word_count'] ?? 2000);

    // ── Build the prompt ────────────────────────────────────────
    $secondary_lines = '';
    foreach ($secondaries as $sk) {
        $secondary_lines .= "  - {$sk['keyword']} (intent={$sk['intent']})"
            . ($sk['buyer_question'] ? " — \"{$sk['buyer_question']}\"" : '') . "\n";
    }

    $variants_needed = [];
    if ($needs['linkedin'])   $variants_needed[] = '"linkedin": "150-220 word post with hook + insight + CTA-style discussion question"';
    if ($needs['twitter'])    $variants_needed[] = '"twitter_thread": ["tweet 1 (hook, no #)", "tweet 2", ... 5-8 tweets, each <=270 chars]';
    if ($needs['reddit'])     $variants_needed[] = '"reddit": {"title": "discussion title", "body": "250-400 word discussion-style post (NOT salesy)"}';
    if ($needs['newsletter']) $variants_needed[] = '"newsletter": {"subject": "compelling email subject line, 40-60 chars, primary keyword early", "preheader": "preview text below subject in inboxes, 80-120 chars", "body_html": "complete standalone HTML newsletter — about 350-500 words. Structure: <p>1-2 sentence hook tying to a real buyer pain.</p> <p>2-3 paragraphs of substantive value (concrete numbers, frameworks, or quick wins) — NOT a teaser, NOT a summary of the blog. Original content the reader can act on directly.</p> <p>Clear CTA paragraph linking to the full blog: <em>For the full breakdown including {{specific topic}}, read the complete guide.</em></p>. Tone: conversational B2B, no hype. Avoid emojis."}';

    $variants_block = empty($variants_needed) ? '' : ",\n" . implode(",\n", $variants_needed);

    $sys = "You are the content writer for " . ($profile['name'] ?? 'this business') . ".\n"
         . "Today's date is " . date('j F Y') . ". When you mention a year (e.g. for 'best X in 2025' style titles, recency cues, or examples), use the CURRENT year or the relevant fiscal year — never an old year. Stale year references are an instant credibility hit.\n"
         . "Produce a complete multi-artifact content package for ONE blog post in a topic cluster.\n\n"
         . "OUTPUT — strict JSON with these top-level keys:\n"
         . "{\n"
         . "  \"blog\": {\n"
         . "    \"title\": \"compelling on-page H1, 60 chars max\",\n"
         . "    \"seo_title\": \"60 chars max, includes primary keyword near front\",\n"
         . "    \"seo_description\": \"160 chars max, hook + benefit + CTA verb\",\n"
         . "    \"excerpt\": \"30-50 word preview\",\n"
         . "    \"body_html\": \"long-form HTML with <h2>/<h3>/<p>/<ul>/<ol>/<blockquote>. Target {$word_count} words. Include a <section class='faq'><h2>Frequently Asked Questions</h2>...</section> near the end with the 8-10 FAQ Q&As as <h3>+<p>.\"\n"
         . "  },\n"
         . "  \"faq\": [ {\"q\":\"...\",\"a\":\"...\"}, ... 8-10 entries ],\n"
         . "  \"hero_image\": {\n"
         . "    \"prompt\": \"detailed prompt for DALL-E 3 (or Unsplash query as fallback). Describe a professional, on-brand, no-text business illustration. Photo-realistic or modern flat-illustration, not generic stock.\",\n"
         . "    \"alt\": \"accessibility alt text, plain-language description of the image\"\n"
         . "  },\n"
         . "  \"internal_link_suggestions\": [ \"anchor text → /target-slug-hint\", ... 3-6 entries ]"
         . $variants_block . "\n"
         . "}\n\n"
         . "CONSTRAINTS:\n"
         . "  - Write in this business's voice. Reference their actual services/positioning, not generic agency platitudes.\n"
         . "  - FAQ Q&As must come from real buyer questions — pull from the buyer_questions provided + invent additional plausible ones.\n"
         . "  - body_html is well-structured HTML — no Markdown. Use semantic tags. Embed the FAQ section as <section class='faq'> near the end.\n"
         . "  - LinkedIn: punchy hook, one insight from the post, end with a question to drive comments. NO hashtags.\n"
         . "  - Twitter thread: first tweet stands alone as a hook; subsequent tweets each carry a discrete insight. No marketing puffery.\n"
         . "  - Reddit: discussion-style, share-the-thinking tone, casually mention the source business in body NOT title.\n"
         . "  - Newsletter: tease one specific insight + link to read more. Don't dump the whole post.\n"
         . "  - hero_image.prompt: 1-2 sentences. Describe the SCENE, not the abstract concept. No text in the image. No specific people.\n"
         . "  - NO lorem ipsum, NO placeholder text, NO 'as an AI' phrasing.\n\n"
         . "Output ONLY valid JSON. No prose before/after. No code fences.\n\n"
         . profile_prompt_block($profile);

    $context = "## ITEM CONTEXT\n"
        . "Cluster: \"{$item['cluster_name']}\" — {$item['cluster_angle']}\n"
        . "Role in cluster: {$item['role']}\n"
        . "Content type: {$item['content_type']}\n"
        . "Primary keyword: {$item['primary_keyword']} (intent={$item['keyword_intent']}, vol=" . ($item['search_volume'] ?? '—') . ", diff=" . ($item['difficulty'] ?? '—') . ")\n"
        . ($item['buyer_question'] ? "Buyer is asking: \"{$item['buyer_question']}\"\n" : '')
        . "Proposed title hint: {$item['proposed_title']}\n"
        . "Proposed angle: {$item['proposed_angle']}\n"
        . "Target word count: {$word_count}\n"
        . ($secondary_lines ? "\nSecondary keywords (weave these in naturally, do not stuff):\n{$secondary_lines}" : '')
        . ($pillar_url ? "\nThis is a SUPPORTING post in a cluster. Link to the cluster's pillar at: /{$pillar_url}\n" : '')
        . ($serp_brief ? "\nSERP brief — what's currently ranking:\n" . json_encode($serp_brief, JSON_PRETTY_PRINT) . "\n" : '');

    // ── One Claude call returns the full package ──────────────
    $resp = haiku_chat($sys, $context, 16000);
    if (empty($resp['success'])) {
        throw new RuntimeException('Claude error: ' . ($resp['error'] ?? 'unknown'));
    }
    $data = extract_json_from_text($resp['content'] ?? '');
    if (!is_array($data) || empty($data['blog']) || empty($data['blog']['body_html'])) {
        error_log('[content_artifacts] malformed JSON, first 500 chars: ' . substr($resp['content'] ?? '', 0, 500));
        throw new RuntimeException('Full-package generator returned malformed JSON');
    }
    return $data;
}
