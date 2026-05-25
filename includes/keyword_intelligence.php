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

/** Hard caps so a single run never explodes the budget. */
const KI_MAX_SEEDS              = 8;     // distinct seed phrases we expand
const KI_MAX_AUTOCOMPLETE_PER   = 30;    // suggestions per seed via Google
const KI_MAX_IDEAS_PER_SEED     = 150;   // DataForSEO keyword_ideas per seed
const KI_MAX_SUGGESTIONS_PER    = 100;   // DataForSEO keyword_suggestions per seed
const KI_KEEP_TOP_N             = 400;   // after dedup, only enrich + classify the top N by raw volume signal
const KI_INTENT_BATCH           = 60;    // keywords per Claude classification call
const KI_RELEVANCE_BATCH        = 120;   // keywords per Claude relevance-filter call

/**
 * Top-level orchestrator.
 *
 * @return array{
 *   seeds: string[],
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

    // ── Step 1: build seeds from profile + confirmed topics ────────
    $progress('Building seed phrases from your business profile...', 5);
    // Prefer AI-derived specific service-line seeds — they're narrower than
    // raw topics like "AI" or "software development", which over-expand when
    // the SERP provider does semantic neighbourhood lookup. Falls back to the
    // mechanical builder if the AI call fails.
    $seeds = ki_build_seeds_ai($profile) ?: ki_build_seeds($profile);
    if (empty($seeds)) {
        throw new RuntimeException('No seed phrases could be derived. Confirm Business Focus + topics, then retry.');
    }

    // ── Step 2: expand via Autocomplete (free) ────────────────────
    $candidates = [];      // keyword (lowercase) => ['source' => ..., 'type' => ..., 'seed' => ...]
    $total_seeds = count($seeds);
    foreach ($seeds as $i => $seed) {
        $progress("Google Autocomplete [" . ($i + 1) . "/{$total_seeds}]: \"{$seed}\"", 10 + (int)(($i / $total_seeds) * 15));
        $suggs = ki_google_autocomplete($seed);
        foreach (array_slice($suggs, 0, KI_MAX_AUTOCOMPLETE_PER) as $s) {
            $key = ki_normalize($s);
            if ($key === '' || isset($candidates[$key])) continue;
            $candidates[$key] = [
                'source' => 'autocomplete',
                'type'   => ki_shape($key),
                'seed'   => $seed,
            ];
        }
        // also try modifier-prefixed seeds for richer question/comparison coverage
        foreach (['best', 'how to', 'what is', 'top', 'vs'] as $mod) {
            $suggs = ki_google_autocomplete("{$mod} {$seed}");
            foreach (array_slice($suggs, 0, 8) as $s) {
                $key = ki_normalize($s);
                if ($key === '' || isset($candidates[$key])) continue;
                $candidates[$key] = ['source' => 'autocomplete', 'type' => ki_shape($key), 'seed' => "{$mod} {$seed}"];
            }
        }
        usleep(900000); // 0.9s pacing to stay under Google's unofficial rate limit
    }

    // ── Step 3: expand via DataForSEO keyword_ideas + suggestions ──
    $dfso_ready = !empty(config('dataforseo_login')) && !empty(config('dataforseo_password'));
    $dfso_metrics = [];   // keyword => ['search_volume', 'keyword_difficulty', 'cpc']
    if ($dfso_ready) {
        foreach ($seeds as $i => $seed) {
            $progress("DataForSEO ideas [" . ($i + 1) . "/{$total_seeds}]: \"{$seed}\"", 25 + (int)(($i / $total_seeds) * 25));
            $ideas = dataforseo_keyword_ideas($seed, KI_MAX_IDEAS_PER_SEED);
            foreach ($ideas as $row) {
                $key = ki_normalize($row['keyword']);
                if ($key === '') continue;
                if (!isset($candidates[$key])) {
                    $candidates[$key] = ['source' => 'dataforseo_ideas', 'type' => ki_shape($key), 'seed' => $seed];
                }
                $dfso_metrics[$key] = [
                    'search_volume'      => $row['search_volume']      ?? null,
                    'keyword_difficulty' => $row['keyword_difficulty'] ?? null,
                    'cpc'                => $row['cpc']                ?? null,
                ];
            }
            $sugg = dataforseo_keyword_suggestions($seed, KI_MAX_SUGGESTIONS_PER);
            foreach ($sugg as $row) {
                $key = ki_normalize($row['keyword']);
                if ($key === '') continue;
                if (!isset($candidates[$key])) {
                    $candidates[$key] = ['source' => 'dataforseo_suggestions', 'type' => ki_shape($key), 'seed' => $seed];
                }
                if (!isset($dfso_metrics[$key])) {
                    $dfso_metrics[$key] = [
                        'search_volume'      => $row['search_volume']      ?? null,
                        'keyword_difficulty' => $row['keyword_difficulty'] ?? null,
                        'cpc'                => $row['cpc']                ?? null,
                    ];
                }
            }
        }
    }

    $total_raw = count($candidates);

    // ── Step 4: keep top N by volume signal (anything we already have metrics for ranks higher) ──
    $progress("Picking top " . KI_KEEP_TOP_N . " of {$total_raw} candidates by volume...", 55);
    $ranked = [];
    foreach ($candidates as $kw => $meta) {
        $vol = $dfso_metrics[$kw]['search_volume'] ?? null;
        // unmetered candidates get a small baseline so we don't lose all autocomplete-only rows
        $ranked[$kw] = $vol !== null ? $vol : -1;
    }
    arsort($ranked);
    $kept = array_slice(array_keys($ranked), 0, KI_KEEP_TOP_N, true);
    $kept_set = array_flip($kept);
    foreach ($candidates as $kw => $_) {
        if (!isset($kept_set[$kw])) unset($candidates[$kw]);
    }

    // ── Step 5: bulk-enrich anything still missing metrics ─────────
    if ($dfso_ready) {
        $needs = array_values(array_filter(array_keys($candidates), fn($kw) => empty($dfso_metrics[$kw])));
        if ($needs) {
            $progress('Enriching ' . count($needs) . ' keywords with volume + difficulty...', 65);
            $bulk = dataforseo_keyword_overview($needs);
            foreach ($bulk as $kw => $info) {
                $dfso_metrics[$kw] = [
                    'search_volume'      => $info['search_volume']      ?? null,
                    'keyword_difficulty' => $info['keyword_difficulty'] ?? null,
                    'cpc'                => $info['cpc']                ?? null,
                ];
            }
        }
    }

    // ── Step 5b: Claude relevance pass ─────────────────────────────
    // DataForSEO's semantic expansion is wide — a "software development" seed
    // pulls in "computer repair services"; an "AI" seed pulls in "ai story
    // generator". Filter the list against the business profile so only
    // keywords a real prospective customer of THIS business would type
    // survive. Same lesson the competitor-discovery pass learned the hard way.
    $before_filter = count($candidates);
    $progress("Filtering {$before_filter} candidates for relevance to your business...", 70);
    $drop_set = ki_relevance_drop_set(array_keys($candidates), $profile);
    if (!empty($drop_set)) {
        foreach ($drop_set as $bad) unset($candidates[$bad]);
    }

    // ── Step 6: pull GSC position + ranking competitor on the candidates we already know about ──
    $gsc_data = [];   // keyword => ['position', 'impressions']
    if (!empty($candidates)) {
        $progress('Cross-referencing your Google Search Console data...', 75);
        $in  = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = $db->prepare("SELECT keyword, gsc_position, current_rank, impressions FROM keywords WHERE site_id = ? AND keyword IN ({$in})");
        $stmt->execute(array_merge([$site_id], array_keys($candidates)));
        while ($r = $stmt->fetch()) {
            $key = ki_normalize($r['keyword']);
            $gsc_data[$key] = [
                'position'    => $r['gsc_position'] ?? $r['current_rank'] ?? null,
                'impressions' => $r['impressions']  ?? null,
            ];
        }
    }

    // ── Step 7: Claude classifies intent + extracts the buyer question, in batches ──
    $progress('Classifying buyer intent (this is the slow part)...', 80);
    $intent_map = ki_classify_intent_bulk(array_keys($candidates), $profile);

    // ── Step 8: score + recommend an action per keyword ────────────
    $progress('Scoring opportunities + recommending actions...', 92);
    $rows = [];
    $count_action = ['quick_win' => 0, 'new_content' => 0, 'aeo_gap' => 0, 'watch' => 0, 'skip' => 0];
    $count_intent = ['informational' => 0, 'commercial' => 0, 'transactional' => 0, 'navigational' => 0, 'unknown' => 0];

    foreach ($candidates as $kw => $meta) {
        $metrics = $dfso_metrics[$kw] ?? [];
        $gsc     = $gsc_data[$kw]     ?? [];
        $intent_row = $intent_map[$kw] ?? ['intent' => 'unknown', 'question' => null];

        $score  = ki_opportunity_score($metrics, $intent_row['intent'], $gsc, $profile, $kw);
        $action = ki_recommend_action($metrics, $intent_row['intent'], $gsc, $score, $profile);

        $count_action[$action] = ($count_action[$action] ?? 0) + 1;
        $count_intent[$intent_row['intent']] = ($count_intent[$intent_row['intent']] ?? 0) + 1;

        $rows[] = [
            'keyword'           => mb_substr($kw, 0, 255),
            'source'            => $meta['source'],
            'keyword_type'      => $meta['type'],
            'cluster'           => null, // filled by a separate clustering pass downstream
            'intent'            => $intent_row['intent'],
            'buyer_question'    => $intent_row['question'],
            'search_volume'     => $metrics['search_volume']      ?? null,
            'difficulty'        => $metrics['keyword_difficulty'] ?? null,
            'cpc'               => $metrics['cpc']                ?? null,
            'opportunity_score' => $score,
            'recommended_action'=> $action,
            'priority'          => $score, // priority kept in sync with opportunity for legacy callers
        ];
    }

    return [
        'seeds'             => $seeds,
        'total_raw'         => $total_raw,
        'total_kept'        => count($rows),
        'counts_by_action'  => $count_action,
        'counts_by_intent'  => $count_intent,
        'rows'              => $rows,
    ];
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
        $sys  = "You filter expanded keyword research candidates for relevance to a specific business. "
              . "Output ONLY valid JSON: {\"drop\":[\"keyword 1\",\"keyword 2\",...]}. No commentary, no code fences.\n\n"
              . "Drop a keyword when ANY of these are true:\n"
              . "  - It's about a DIFFERENT industry's products/services (e.g. 'computer repair' for a software firm; 'ai story generator' for an AI consulting firm)\n"
              . "  - It targets the wrong buyer (consumer-grade terms for a B2B business, or enterprise-only terms for an SMB shop)\n"
              . "  - It's geographically irrelevant (a US-only keyword for an India-focused business, or vice versa)\n"
              . "  - It's a job/career search (\"X jobs\", \"X salary\", \"how to become X\") — these are job seekers, not buyers\n"
              . "  - It's a navigational query for a different specific brand\n"
              . "  - It is plainly off-topic — would a buyer of THIS business EVER type this when looking to hire/buy from them?\n\n"
              . "KEEP when in doubt — a noisy list with some false positives is better than wiping good keywords. "
              . "Never drop more than 70% of the batch.\n\n"
              . $profile_block;
        $prompt = "Candidate keywords:\n\n{$list}";

        $resp = haiku_chat($sys, $prompt, 2000);
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
        // Cap drops per batch — defensive against a misfired profile
        $cap = (int)floor(count($batch) * 0.7);
        if (count($batch_drops) > $cap) $batch_drops = array_slice($batch_drops, 0, $cap);

        foreach ($batch_drops as $d) {
            // Only honour drops that were actually in the batch (Claude can hallucinate)
            if (in_array($d, $batch, true)) $drops[$d] = true;
        }
        usleep(150000);
    }
    return $drops;
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
// Misc
// ─────────────────────────────────────────────────────────────────

function ki_normalize(string $kw): string
{
    $kw = strtolower(trim($kw));
    $kw = preg_replace('/\s+/', ' ', $kw);
    return $kw;
}
