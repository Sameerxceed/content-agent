<?php
/**
 * Keyword Intelligence — the "Ubersuggest-grade" keyword research module.
 *
 * Stacks four signals into one scored, bucketed action plan per keyword:
 *
 *   1. Breadth     — Google Autocomplete + DataForSEO keyword_ideas / suggestions
 *   2. Metrics     — DataForSEO search_volume / difficulty / CPC (bulk enrichment)
 *   3. Buyer intent — Claude classifies each keyword + extracts the buyer question
 *   4. Personalisation — cross-references the business profile, GSC position, and
 *                        competitor table to score opportunity and recommend an action
 *
 * Output is a list of keyword rows already shaped for the keywords table, each
 * with `opportunity_score`, `recommended_action`, `intent`, `keyword_type`, and
 * `buyer_question` populated. The downstream caller (agent/keyword-research.php
 * CLI) writes them to MySQL.
 *
 * Public API:
 *   ki_run(PDO $db, int $site_id, callable $progress, array $opts = []): array
 *
 *   $progress is the same closure shape competitors-discover uses:
 *       $progress(string $step, int $pct, ?array $partial = null)
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/business_profile.php';
require_once __DIR__ . '/dataforseo.php';
require_once __DIR__ . '/haiku.php';

/** Hard caps. Deliberately strict — 100 useful keywords beats 500 noisy ones. */
const KI_MAX_SEEDS              = 8;     // distinct seed phrases we expand
const KI_MAX_AUTOCOMPLETE_PER   = 20;    // suggestions per seed via Google (autocomplete stays narrower than DFSO)
const KI_MAX_IDEAS_PER_SEED     = 30;    // DataForSEO keyword_ideas per seed — was 150, too wide
const KI_MAX_SUGGESTIONS_PER    = 20;    // DataForSEO keyword_suggestions per seed — was 100, too wide
const KI_KEEP_TOP_N             = 200;   // after dedup, cap candidates before the expensive Claude passes
const KI_INTENT_BATCH           = 60;    // keywords per Claude classification call
const KI_RELEVANCE_BATCH        = 100;   // keywords per Claude relevance-filter call
const KI_RELEVANCE_DROP_CAP_PCT = 95;    // max % per batch the relevance filter can drop — leave a small safety net

/**
 * Top-level orchestrator — AI-first architecture.
 *
 * Claude generates the full keyword list directly from the business profile
 * (no DataForSEO/Autocomplete expansion). DataForSEO is used only to enrich
 * metrics (volume / difficulty / CPC). This produces ~80-120 keywords that
 * are all on-target by construction, instead of expanding wide and trying
 * to filter back down to relevance.
 *
 * @return array{
 *   total_raw: int,
 *   total_kept: int,
 *   counts_by_action: array<string,int>,
 *   counts_by_intent: array<string,int>,
 *   rows: array<int, array<string,mixed>>   // ready to upsert
 * }
 */
function ki_run(PDO $db, int $site_id, callable $progress, array $opts = []): array
{
    $profile = profile_get($db, $site_id);
    if (!$profile) throw new RuntimeException('Site profile not found.');

    // ── Step 1: AI generates the keyword list directly ────────────
    // Replaces the previous seed → autocomplete → DFSO ideas expansion
    // pipeline (which kept dragging in adjacent-industry junk). Claude
    // works from the business profile and writes 80-120 keywords each
    // shaped to a real prospective customer of THIS business.
    $progress('Generating keywords from your business profile...', 10);
    $generated = ki_generate_keywords_ai($profile);
    if (empty($generated)) {
        throw new RuntimeException('Could not generate keywords. Confirm Business Focus is filled in (industry, topics, USP) and retry.');
    }

    // ── Step 2: Enrich each with DataForSEO metrics (volume/difficulty/CPC) ──
    // Query against the business's actual market — defaulting to US (2840)
    // misses real search volume for India-only / UK-only / etc. keywords.
    // An India-based consultancy targeting "hire developers india" gets zero
    // data from a US query but plenty from an India query.
    $location_code = ki_location_code_for_profile($profile);
    $dfso_ready = !empty(config('dataforseo_login')) && !empty(config('dataforseo_password'));
    $dfso_metrics = [];
    if ($dfso_ready) {
        $progress('Looking up real search volume + difficulty for ' . count($generated) . ' keywords...', 50);
        $kw_strings = array_values(array_unique(array_column($generated, 'keyword')));
        $bulk = dataforseo_keyword_overview($kw_strings, $location_code);
        foreach ($bulk as $kw => $info) {
            $dfso_metrics[$kw] = [
                'search_volume'      => $info['search_volume']      ?? null,
                'keyword_difficulty' => $info['keyword_difficulty'] ?? null,
                'cpc'                => $info['cpc']                ?? null,
            ];
        }
        // Fallback: for any keyword DataForSEO had nothing for in the local
        // market, retry against worldwide (2840 / US is the closest proxy)
        // so very-long-tail still picks up at least minimal signal.
        if ($location_code !== DFSO_DEFAULT_LOCATION) {
            $missing = array_values(array_filter($kw_strings, fn($kw) => empty($dfso_metrics[$kw])));
            if ($missing) {
                $bulk2 = dataforseo_keyword_overview($missing, DFSO_DEFAULT_LOCATION);
                foreach ($bulk2 as $kw => $info) {
                    if (empty($dfso_metrics[$kw])) {
                        $dfso_metrics[$kw] = [
                            'search_volume'      => $info['search_volume']      ?? null,
                            'keyword_difficulty' => $info['keyword_difficulty'] ?? null,
                            'cpc'                => $info['cpc']                ?? null,
                        ];
                    }
                }
            }
        }
    }

    // ── Step 3: Cross-reference your GSC data for current rank/impressions ──
    $progress('Cross-referencing your Google Search Console data...', 75);
    $gsc_data = [];
    $kw_strings = array_column($generated, 'keyword');
    if (!empty($kw_strings)) {
        $in  = implode(',', array_fill(0, count($kw_strings), '?'));
        $stmt = $db->prepare("SELECT keyword, gsc_position, current_rank, impressions FROM keywords WHERE site_id = ? AND keyword IN ({$in})");
        $stmt->execute(array_merge([$site_id], $kw_strings));
        while ($r = $stmt->fetch()) {
            $key = ki_normalize($r['keyword']);
            $gsc_data[$key] = [
                'position'    => $r['gsc_position'] ?? $r['current_rank'] ?? null,
                'impressions' => $r['impressions']  ?? null,
            ];
        }
    }

    // ── Step 4: Score + recommend an action per keyword ────────────
    $progress('Scoring opportunities + recommending actions...', 90);
    $rows = [];
    $count_action = ['quick_win' => 0, 'new_content' => 0, 'aeo_gap' => 0, 'watch' => 0, 'skip' => 0];
    $count_intent = ['informational' => 0, 'commercial' => 0, 'transactional' => 0, 'navigational' => 0, 'unknown' => 0];

    foreach ($generated as $g) {
        $kw      = $g['keyword'];
        $metrics = $dfso_metrics[$kw] ?? [];
        $gsc     = $gsc_data[$kw]     ?? [];

        $score  = ki_opportunity_score($metrics, $g['intent'], $gsc, $profile, $kw);
        $action = ki_recommend_action($metrics, $g['intent'], $gsc, $score, $profile);

        $count_action[$action] = ($count_action[$action] ?? 0) + 1;
        $count_intent[$g['intent']] = ($count_intent[$g['intent']] ?? 0) + 1;

        $rows[] = [
            'keyword'           => mb_substr($kw, 0, 255),
            // 'autocomplete' is the closest existing enum value to "AI-generated"
            // without a schema change. The UI already shows it as "AI" — fine.
            'source'            => 'autocomplete',
            'keyword_type'      => $g['keyword_type'] ?? ki_shape($kw),
            'cluster'           => null,
            'intent'            => $g['intent'],
            'buyer_question'    => $g['buyer_question'],
            'search_volume'     => $metrics['search_volume']      ?? null,
            'difficulty'        => $metrics['keyword_difficulty'] ?? null,
            'cpc'               => $metrics['cpc']                ?? null,
            'opportunity_score' => $score,
            'recommended_action'=> $action,
            'priority'          => $score,
        ];
    }

    return [
        'total_raw'         => count($generated),
        'total_kept'        => count($rows),
        'counts_by_action'  => $count_action,
        'counts_by_intent'  => $count_intent,
        'rows'              => $rows,
    ];
}

/**
 * Ask Claude to generate the entire keyword list directly from the business
 * profile. Single call returns 80-120 keyword objects, each already shaped
 * with intent + buyer_question + keyword_type. No expansion, no filtering —
 * because nothing irrelevant ever enters the pipeline.
 *
 * @return array<int, array{keyword:string, intent:string, buyer_question:?string, keyword_type:string}>
 */
function ki_generate_keywords_ai(array $profile): array
{
    $name = trim((string)($profile['name'] ?? 'this business'));

    $sys = "You are a search marketer for {$name}. Generate 80-120 keywords that real prospective customers would type into Google when actively looking to hire/buy from this business.\n\n"
         . "For EVERY keyword, output 4 fields:\n"
         . "  - keyword: the exact phrase a buyer types (lowercase, 2-7 words)\n"
         . "  - intent: 'informational' (learning) | 'commercial' (comparing options) | 'transactional' (ready to buy/hire) | 'navigational' (specific brand)\n"
         . "  - buyer_question: ONE short sentence in the buyer's voice — what they're actually asking (max 18 words)\n"
         . "  - keyword_type: 'head' (1-2 words) | 'long_tail' (5+ words) | 'question' (starts with what/why/how/etc.) | 'comparison' (vs/alternative) | 'geo' (mentions a location) | 'related' (other)\n\n"
         . "Spread the list across these patterns:\n"
         . "  - Service-line keywords (e.g. 'AI consulting for healthcare', 'computer vision development services')\n"
         . "  - Problem-aware searches (e.g. 'how to automate document processing', 'reduce manual data entry costs')\n"
         . "  - Comparison + alternatives (e.g. 'best AI consulting firms for SMBs', 'alternatives to building an in-house ML team')\n"
         . "  - Geo-modified (e.g. 'AI consulting Pune', 'software development partner India') — only if business serves that geo\n"
         . "  - Long-tail buyer questions (e.g. 'which AI consultancy is best for document automation')\n"
         . "  - Hiring/buying intent (e.g. 'hire AI consultants', 'AI development team for startups')\n\n"
         . "STRICT RULES — break any of these and the output is useless:\n"
         . "  - Every keyword MUST be one a real BUYER OF THIS BUSINESS would type when looking to hire/buy from them. Not a researcher, not a job seeker, not a curious bystander.\n"
         . "  - Calibrate to the business's actual scale: a 15-person consultancy shouldn't have 'fortune 500' or 'enterprise platform' keywords. A global SaaS shouldn't have hyperlocal long-tail.\n"
         . "  - NO tool/product brand names that aren't this business (e.g. don't list 'synthesia ai', 'kaggle datasets', 'weights ai', 'spicy ai', 'undressing ai')\n"
         . "  - NO job-seeker queries (no 'X jobs', 'X salary', 'X internship', 'how to become X', 'X resume')\n"
         . "  - NO NSFW or adjacent-industry junk\n"
         . "  - NO vague single-word terms like 'software', 'consulting', 'ai'\n"
         . "  - NO competitor brand names\n\n"
         . "Output ONLY valid JSON: {\"keywords\":[{...}, {...}, ...]}. No commentary, no code fences, no leading text.\n\n"
         . profile_prompt_block($profile);

    $prompt = "Generate the keyword list now. Aim for 100 keywords, all genuinely buyer-shaped for this business.";

    $resp = haiku_chat($sys, $prompt, 8000);
    if (empty($resp['success'])) {
        error_log('[ki_generate_keywords_ai] Claude error: ' . ($resp['error'] ?? 'unknown'));
        return [];
    }

    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($resp['content']));
    $data  = json_decode($clean, true);
    if (!is_array($data) || empty($data['keywords']) || !is_array($data['keywords'])) {
        error_log('[ki_generate_keywords_ai] malformed JSON: ' . substr($clean, 0, 500));
        return [];
    }

    $seen = [];
    $out  = [];
    foreach ($data['keywords'] as $k) {
        if (!is_array($k)) continue;
        $kw = ki_normalize((string)($k['keyword'] ?? ''));
        if ($kw === '' || mb_strlen($kw) > 255) continue;
        if (isset($seen[$kw])) continue;
        $seen[$kw] = true;

        $intent = strtolower(trim((string)($k['intent'] ?? 'unknown')));
        if (!in_array($intent, ['informational','commercial','transactional','navigational'], true)) {
            $intent = 'unknown';
        }

        $type = strtolower(trim((string)($k['keyword_type'] ?? '')));
        if (!in_array($type, ['head','long_tail','question','comparison','geo','related'], true)) {
            $type = ki_shape($kw);
        }

        $q = trim((string)($k['buyer_question'] ?? ''));

        $out[] = [
            'keyword'        => $kw,
            'intent'         => $intent,
            'buyer_question' => $q !== '' ? mb_substr($q, 0, 500) : null,
            'keyword_type'   => $type,
        ];
    }

    return $out;
}

// ─────────────────────────────────────────────────────────────────
// Seeds
// ─────────────────────────────────────────────────────────────────

/**
 * Build 4-8 seed phrases for keyword expansion. Stacks:
 *   - Confirmed topics from Business Focus
 *   - industry_sub + industry_category (e.g. "Document AI", "AI consulting")
 *   - Geo-suffixed variants when market_scope is local/regional/national
 *   - Customer-segment suffixes ("for small business", "for startups") when SME
 */
function ki_build_seeds(array $profile): array
{
    $seeds = [];

    $topics = (array)($profile['topics'] ?? []);
    foreach (array_slice($topics, 0, 4) as $t) {
        $t = trim((string)$t);
        if ($t !== '') $seeds[] = strtolower($t);
    }

    if (!empty($profile['industry_sub']))      $seeds[] = strtolower((string)$profile['industry_sub']);
    if (!empty($profile['industry_category'])) $seeds[] = strtolower((string)$profile['industry_category']);

    // Local boutiques benefit from geo-suffix seeds — picks up "X services Pune" etc.
    $geo   = trim((string)($profile['hq_city'] ?? '')) ?: trim((string)($profile['hq_country'] ?? ''));
    $scope = $profile['market_scope'] ?? '';
    if ($geo !== '' && in_array($scope, ['local', 'regional', 'national'], true)) {
        foreach (array_slice($topics, 0, 2) as $t) {
            $seeds[] = strtolower(trim($t) . ' ' . $geo);
        }
    }

    // SME-shaped seeds — surface "for small business" / "for startups" intent
    if (in_array($profile['size_tier'] ?? '', ['solo', 'small'], true)) {
        foreach (array_slice($topics, 0, 2) as $t) {
            $seeds[] = strtolower(trim($t) . ' for small business');
        }
    }

    $seeds = array_values(array_unique(array_filter(array_map('trim', $seeds))));
    return array_slice($seeds, 0, KI_MAX_SEEDS);
}

/**
 * Ask Claude for 6-8 narrow, service-line-shaped seed phrases derived from
 * the business profile. This beats mechanical seeds because the SERP provider
 * does semantic expansion — a broad seed like "AI" or "software development"
 * pulls in completely unrelated stuff (consumer AI tools, hardware repair).
 * A narrow seed like "ai document automation for healthcare" stays in lane.
 *
 * Returns null on AI failure so the caller can fall back to ki_build_seeds().
 */
function ki_build_seeds_ai(array $profile): ?array
{
    // Need at least industry + a topic or USP to bother with the AI call.
    if (empty($profile['industry_category']) && empty($profile['industry_sub']) && empty($profile['topics'])) {
        return null;
    }

    $sys = "You write narrow, buyer-shaped keyword seed phrases for a specific business. "
         . "Output ONLY a JSON array of 6-8 short strings (2-5 words each), no commentary.\n\n"
         . "Each seed must:\n"
         . "  - Name a SPECIFIC service or offering this business actually sells (not the whole industry)\n"
         . "  - Be a phrase a real prospective customer would type into Google\n"
         . "  - Be narrow enough that a search engine's semantic expansion won't drag in unrelated industries\n"
         . "  - Match this business's scale + geography (don't suggest enterprise terms for an SMB shop)\n\n"
         . "BAD (too broad — semantic expansion will pull in junk):\n"
         . "  \"AI\" → pulls in ai story generator, ai chat, etc.\n"
         . "  \"software development\" → pulls in computer repair, IT support, etc.\n"
         . "  \"consulting\" → pulls in financial planning, life coaching, etc.\n\n"
         . "GOOD (narrow, on-target):\n"
         . "  \"document AI services\"\n"
         . "  \"computer vision consulting\"\n"
         . "  \"custom AI agent development\"\n"
         . "  \"AI consulting for manufacturing\"\n\n"
         . profile_prompt_block($profile);

    $resp = haiku_chat($sys, "Write 6-8 narrow seed phrases for this business.", 400);
    if (empty($resp['success'])) {
        error_log('[ki_build_seeds_ai] Claude error: ' . ($resp['error'] ?? 'unknown'));
        return null;
    }
    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($resp['content']));
    $data  = json_decode($clean, true);
    if (!is_array($data) || empty($data)) return null;

    $seeds = array_values(array_unique(array_filter(array_map(
        fn($s) => ki_normalize((string)$s),
        $data
    ))));
    return array_slice($seeds, 0, KI_MAX_SEEDS);
}

// ─────────────────────────────────────────────────────────────────
// Free-tier autocomplete
// ─────────────────────────────────────────────────────────────────

/** Google's public autocomplete JSON endpoint. No auth, but rate-sensitive. */
function ki_google_autocomplete(string $query): array
{
    $url = 'https://suggestqueries.google.com/complete/search?client=firefox&q=' . urlencode($query);
    $res = http_get($url, [], 8);
    if ($res['status'] !== 200) return [];
    $data = json_decode($res['body'], true);
    if (!is_array($data) || !isset($data[1]) || !is_array($data[1])) return [];
    return array_values(array_filter($data[1], fn($s) => strtolower($s) !== strtolower($query)));
}

// ─────────────────────────────────────────────────────────────────
// Relevance filter (Claude, batched)
// ─────────────────────────────────────────────────────────────────

/**
 * Given the candidate keyword list + business profile, ask Claude which
 * keywords a real prospective customer of THIS business would NOT type
 * when looking to hire/buy from them. Returns the set of keywords to drop.
 *
 * Batched in chunks of 120 to keep Claude calls fast and bounded.
 *
 * Defensive: caps the drop ratio at 70% per batch — if Claude wants to
 * drop nearly everything, that's usually a sign the profile or its prompt
 * misfired, and we'd rather keep questionable rows than wipe the list.
 *
 * @return array<string,true>  map of keyword => true for dropped items
 */
function ki_relevance_drop_set(array $keywords, array $profile): array
{
    $drops = [];
    if (empty($keywords)) return $drops;

    $profile_block = profile_prompt_block($profile);

    foreach (array_chunk($keywords, KI_RELEVANCE_BATCH) as $batch) {
        $list = implode("\n", array_map(fn($k) => "- {$k}", $batch));
        $sys  = "You are a strict relevance filter for keyword research. Drop EVERY keyword that wouldn't be typed by a real prospective customer of THIS business when looking to hire/buy from them.\n\n"
              . "Output ONLY valid JSON: {\"drop\":[\"keyword 1\",\"keyword 2\",...]}. No commentary, no code fences.\n\n"
              . "Be ruthless. Bias toward dropping. The user explicitly said they would rather have 100 high-quality keywords than 500 noisy ones. Most expanded keywords ARE noise — wrong industry, wrong buyer, wrong intent. Cut hard.\n\n"
              . "DEFINITELY drop:\n"
              . "  - DIFFERENT industry (e.g. 'computer repair', 'flights to india', 'india post tracking' for a software/AI consultancy)\n"
              . "  - CONSUMER products/services for a B2B business (e.g. 'ai story generator' for B2B AI consulting)\n"
              . "  - ENTERPRISE-only terms for an SMB (e.g. 'fortune 500 erp' for a 15-person agency)\n"
              . "  - WRONG GEOGRAPHY (US-only term for India-focused business or vice versa, unless your business sells globally)\n"
              . "  - JOB/CAREER search ('X jobs', 'X salary', 'how to become X', 'X internship', 'X resume')\n"
              . "  - GENERIC how-to/tutorial searches that bring readers but not buyers\n"
              . "  - NAVIGATIONAL for a different brand (unless they're a known partner)\n"
              . "  - VAGUE single-word terms that won't convert ('software', 'consulting', 'ai')\n"
              . "  - Anything you cannot CLEARLY justify as a real buyer intent for THIS specific business\n\n"
              . "Only KEEP a keyword if you can finish this sentence: 'A prospective customer of " . ($profile['name'] ?? 'this business') . " would type this when actively looking for someone who does ____'.\n\n"
              . $profile_block;
        $prompt = "Candidate keywords:\n\n{$list}";

        $resp = haiku_chat($sys, $prompt, 3000);
        if (empty($resp['success'])) {
            error_log('[ki_relevance_drop_set] Claude error: ' . ($resp['error'] ?? 'unknown'));
            continue;
        }
        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($resp['content']));
        $data = json_decode($clean, true);
        if (!is_array($data) || !isset($data['drop']) || !is_array($data['drop'])) continue;

        $batch_drops = array_values(array_filter(array_map(
            fn($k) => ki_normalize((string)$k),
            $data['drop']
        )));
        // Keep a tiny safety net (95%) so a totally misfired profile prompt
        // can't nuke literally every candidate, but otherwise let it cut hard.
        $cap = (int)floor(count($batch) * (KI_RELEVANCE_DROP_CAP_PCT / 100));
        if (count($batch_drops) > $cap) $batch_drops = array_slice($batch_drops, 0, $cap);

        foreach ($batch_drops as $d) {
            if (in_array($d, $batch, true)) $drops[$d] = true;
        }
        usleep(150000);
    }
    return $drops;
}

/**
 * Run the relevance filter against a list of keyword STRINGS that may have
 * just been inserted (e.g. by a GSC sync). For any keyword Claude deems
 * off-topic against the business profile, mark the corresponding row as
 * status='ignored' with a clear reason so the user can later inspect them
 * in the Ignored tab and restore mistakes.
 *
 * Returns the count of rows auto-ignored. Safe to call with an empty list
 * or when the profile isn't filled in (it skips quietly in those cases —
 * we don't want to auto-ignore stuff when we have no profile context to
 * judge against).
 */
function keywords_auto_ignore_offtopic(PDO $db, int $site_id, array $keyword_strings): int
{
    $keyword_strings = array_values(array_filter(array_map(fn($s) => ki_normalize((string)$s), $keyword_strings)));
    if (empty($keyword_strings)) return 0;

    $profile = profile_get($db, $site_id);
    if (!$profile) return 0;
    // Need at least industry to make a credible relevance call. Without it,
    // skip — better noisy GSC list than aggressive false-positives.
    if (empty($profile['industry_category']) && empty($profile['industry_sub']) && empty($profile['topics'])) {
        return 0;
    }

    $drops = ki_relevance_drop_set($keyword_strings, $profile);
    if (empty($drops)) return 0;

    $reason = 'Off-topic for your business (auto-filtered from Google Search Console).';
    $upd = $db->prepare("UPDATE keywords SET status = 'ignored', ignored_reason = ? WHERE site_id = ? AND keyword = ? AND status = 'active'");
    $count = 0;
    foreach (array_keys($drops) as $kw) {
        $upd->execute([$reason, $site_id, $kw]);
        if ($upd->rowCount() > 0) $count++;
    }
    return $count;
}

// ─────────────────────────────────────────────────────────────────
// Intent classification (Claude, batched)
// ─────────────────────────────────────────────────────────────────

/**
 * For each keyword, Claude returns {intent, question}. Batched 60 at a time
 * to keep latency + cost reasonable. Returns map keyword => ['intent', 'question'].
 */
function ki_classify_intent_bulk(array $keywords, array $profile): array
{
    $out = [];
    if (empty($keywords)) return $out;

    $profile_block = profile_prompt_block($profile);

    foreach (array_chunk($keywords, KI_INTENT_BATCH) as $batch) {
        $list = implode("\n", array_map(fn($k) => "- {$k}", $batch));
        $sys  = "You are a keyword intent classifier. For each keyword in the list, output a single JSON object whose keys are the keywords (exact strings) and whose values have two fields:\n"
              . "  - intent: one of 'informational' (learning), 'commercial' (researching options), 'transactional' (ready to buy/hire), 'navigational' (looking for a specific brand/site)\n"
              . "  - question: ONE short sentence describing what the buyer typing this is actually asking. Phrase in the buyer's voice (e.g. \"Which AI consultancy should I hire for our document-processing problem?\"). Max 18 words.\n\n"
              . "Output ONLY valid JSON, no commentary, no code fences. Cover every input keyword. Use the business profile below to make intent calls that are realistic for THIS business's prospective customers.\n\n"
              . $profile_block;
        $prompt = "Classify these keywords:\n\n{$list}";

        $resp = haiku_chat($sys, $prompt, 4000);
        if (empty($resp['success'])) {
            error_log('[ki_classify_intent_bulk] Claude error: ' . ($resp['error'] ?? 'unknown'));
            continue;
        }
        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($resp['content']));
        $data = json_decode($clean, true);
        if (!is_array($data)) continue;

        foreach ($data as $kw => $row) {
            $key = ki_normalize($kw);
            if ($key === '') continue;
            $intent = strtolower(trim((string)($row['intent'] ?? 'unknown')));
            if (!in_array($intent, ['informational','commercial','transactional','navigational'], true)) {
                $intent = 'unknown';
            }
            $q = trim((string)($row['question'] ?? ''));
            $out[$key] = [
                'intent'   => $intent,
                'question' => $q !== '' ? mb_substr($q, 0, 500) : null,
            ];
        }
        usleep(150000);
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────
// Scoring + action recommendation
// ─────────────────────────────────────────────────────────────────

/**
 * 0-100 opportunity score blending volume × intent × difficulty × current rank.
 * Designed so quick-wins (page 2-3 with real impressions) rank highest.
 */
function ki_opportunity_score(array $metrics, string $intent, array $gsc, array $profile, string $keyword): int
{
    $vol  = (int)($metrics['search_volume']      ?? 0);
    $diff = (int)($metrics['keyword_difficulty'] ?? 50);
    $pos  = (float)($gsc['position'] ?? 0);

    // Volume contribution: log scale so 100 vs 100k aren't 1000× apart.
    $vol_score = $vol > 0 ? min(60, log10(max(1, $vol)) * 15) : 5;

    // Intent contribution
    $intent_bonus = [
        'transactional' => 20,
        'commercial'    => 15,
        'informational' => 5,
        'navigational'  => -10,
        'unknown'       => 0,
    ][$intent] ?? 0;

    // Difficulty penalty
    $diff_penalty = 0;
    if ($diff >= 71)       $diff_penalty = -35;
    elseif ($diff >= 51)   $diff_penalty = -20;
    elseif ($diff >= 31)   $diff_penalty = -10;

    // Current-rank bonus — page 2/3 is the sweet spot
    $rank_bonus = 0;
    if ($pos > 0) {
        if ($pos <= 3)      $rank_bonus = -10;   // already winning; lower opportunity
        elseif ($pos <= 15) $rank_bonus = 25;    // quick win!
        elseif ($pos <= 30) $rank_bonus = 15;
        elseif ($pos <= 50) $rank_bonus = 5;
    }

    // Size-aware modifier — small firms shouldn't chase head terms (1-2 words)
    $size = $profile['size_tier'] ?? null;
    $word_count = str_word_count($keyword);
    if (in_array($size, ['solo', 'small'], true) && $word_count <= 2) {
        $diff_penalty -= 10;
    }

    // Geo bonus for local businesses if keyword mentions their geo
    $geo = strtolower(trim((string)($profile['hq_city'] ?? '')));
    if ($geo !== '' && in_array($profile['market_scope'] ?? '', ['local','regional'], true) && stripos($keyword, $geo) !== false) {
        $rank_bonus += 10;
    }

    $score = (int)round($vol_score + $intent_bonus + $diff_penalty + $rank_bonus);
    return max(0, min(100, $score));
}

/**
 * Pick the recommended action bucket. Order matters — first match wins.
 */
function ki_recommend_action(array $metrics, string $intent, array $gsc, int $score, array $profile): string
{
    $vol  = (int)($metrics['search_volume']      ?? 0);
    $diff = (int)($metrics['keyword_difficulty'] ?? 50);
    $pos  = (float)($gsc['position'] ?? 0);
    $impr = (int)($gsc['impressions'] ?? 0);

    // Quick win: already ranking page 2-3 with impressions
    if ($pos > 3 && $pos <= 30 && ($impr > 0 || $vol > 10)) {
        return 'quick_win';
    }

    // Skip: very hard or wrong intent for this business
    if ($intent === 'navigational') return 'skip';
    $size = $profile['size_tier'] ?? null;
    if ($diff >= 75 && in_array($size, ['solo','small'], true)) return 'skip';

    // New content: not ranking, realistic difficulty, has buyer intent
    if (($pos <= 0 || $pos > 30) && $diff < 60 && in_array($intent, ['commercial','transactional','informational'], true) && $vol >= 10) {
        return 'new_content';
    }

    // AEO gap: informational + question-shaped + we don't rank. (Real AEO-cited-competitor
    // overlay is a downstream pass — for now, surface info-question keywords as candidate gaps.)
    if ($intent === 'informational' && $pos <= 0 && $score >= 40) {
        return 'aeo_gap';
    }

    return 'watch';
}

// ─────────────────────────────────────────────────────────────────
// Shape detection (purely linguistic — no API needed)
// ─────────────────────────────────────────────────────────────────

/**
 * Classify a keyword by its surface shape. Question/comparison/long-tail/etc.
 * Used to bucket the keywords UI without paying Claude per keyword for this.
 */
function ki_shape(string $keyword): string
{
    $k = strtolower(trim($keyword));
    if ($k === '') return 'related';

    // Question patterns
    if (preg_match('/^(what|why|how|when|where|who|which|is|are|can|do|does|should)\b/', $k)
        || str_contains($k, '?')) {
        return 'question';
    }
    // Comparison patterns
    if (preg_match('/\b(vs|versus|or|alternative(s)? to|compared to)\b/', $k)) {
        return 'comparison';
    }
    // Geo — basic heuristic, fires when a real geo word appears (the seed-builder
    // adds these intentionally, so this is enough; not trying to detect arbitrary
    // place names).
    if (preg_match('/\b(india|pune|mumbai|delhi|bangalore|usa|uk|near me)\b/', $k)) {
        return 'geo';
    }

    $wc = str_word_count($k);
    if ($wc >= 5) return 'long_tail';
    if ($wc <= 2) return 'head';
    return 'related';
}

// ─────────────────────────────────────────────────────────────────
// Geo
// ─────────────────────────────────────────────────────────────────

/**
 * Map the business's HQ country to a DataForSEO location_code. Used when
 * querying volume / difficulty / CPC so we get the actual market data
 * instead of US defaults that miss India/UK/etc. local search volumes.
 *
 * Global businesses fall back to US (2840) since that's typically the
 * largest search market and a reasonable worldwide proxy.
 */
function ki_location_code_for_profile(array $profile): int
{
    $country = strtolower(trim((string)($profile['hq_country'] ?? '')));
    if ($country === '') return DFSO_DEFAULT_LOCATION;

    // Strip common variants — "United States of America" → "united states"
    $country = trim(preg_replace('/\s+of\s+america$/i', '', $country));

    // Top-20-ish search markets. DataForSEO codes from their location list.
    static $map = [
        'india'           => 2356,
        'united states'   => 2840,
        'usa'             => 2840,
        'us'              => 2840,
        'united kingdom'  => 2826,
        'uk'              => 2826,
        'britain'         => 2826,
        'canada'          => 2124,
        'australia'       => 2036,
        'germany'         => 2276,
        'france'          => 2250,
        'spain'           => 2724,
        'italy'           => 2380,
        'netherlands'     => 2528,
        'brazil'          => 2076,
        'mexico'          => 2484,
        'japan'           => 2392,
        'south korea'     => 2410,
        'korea'           => 2410,
        'singapore'       => 2702,
        'philippines'     => 2608,
        'indonesia'       => 2360,
        'malaysia'        => 2458,
        'thailand'        => 2764,
        'vietnam'         => 2704,
        'united arab emirates' => 2784,
        'uae'             => 2784,
        'saudi arabia'    => 2682,
        'south africa'    => 2710,
        'new zealand'     => 2554,
        'ireland'         => 2372,
        'sweden'          => 2752,
        'norway'          => 2578,
        'denmark'         => 2208,
        'poland'          => 2616,
        'turkey'          => 2792,
        'argentina'       => 2032,
        'chile'           => 2152,
        'colombia'        => 2170,
    ];

    // For global businesses, US is the largest market and a reasonable default
    if (($profile['market_scope'] ?? '') === 'global') {
        return DFSO_DEFAULT_LOCATION;
    }
    return $map[$country] ?? DFSO_DEFAULT_LOCATION;
}

// ─────────────────────────────────────────────────────────────────
// Misc
// ─────────────────────────────────────────────────────────────────

function ki_normalize(string $kw): string
{
    $kw = strtolower(trim($kw));
    $kw = preg_replace('/\s+/', ' ', $kw);
    return $kw;
}
