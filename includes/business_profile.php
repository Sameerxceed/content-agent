<?php
/**
 * Business profile — the centralised "who is this customer" module.
 *
 * Used by:
 *   - Scanner (CLI + web) to INFER and store the profile via Claude.
 *   - Every downstream agent (competitor discovery, blog writer, keyword
 *     research, AEO suggester, Brand Presence, news scraper, schema generator)
 *     to READ the profile and bake it into LLM prompts / filter logic.
 *
 * Public API:
 *   profile_fetch_pages(string $domain): array        — crawl homepage + about + team
 *   profile_infer(array $site, array $pages): array   — Claude call → structured fields
 *   profile_save(PDO $db, int $site_id, array $inferred): void
 *   profile_get(PDO $db, int $site_id): ?array        — for downstream readers
 *   profile_prompt_block(array $profile): string      — drop into any LLM system prompt
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/scraper.php';
require_once __DIR__ . '/haiku.php';

// Allowed enum values — kept in sync with migration 027.
const PROFILE_SIZE_TIERS         = ['solo', 'small', 'mid', 'large', 'enterprise'];
const PROFILE_BUSINESS_MODELS    = ['b2b', 'b2c', 'b2b2c', 'nonprofit', 'marketplace'];
const PROFILE_OFFERING_TYPES     = ['service', 'product', 'hybrid'];
const PROFILE_CUSTOMER_SEGMENTS  = ['consumer', 'smb', 'midmarket', 'enterprise', 'mixed'];
const PROFILE_MARKET_SCOPES      = ['local', 'regional', 'national', 'global'];
const PROFILE_MATURITY_TIERS     = ['bootstrapped', 'established', 'category_leader', 'public_company'];

/**
 * Crawl the homepage + the first reachable About/Team/Contact page.
 * Returns extracted text excerpts keyed by page label, capped per page.
 */
function profile_fetch_pages(string $domain): array
{
    $base = rtrim('https://' . preg_replace('#^https?://#i', '', $domain), '/');
    $candidates = [
        'homepage' => $base . '/',
        'about'    => $base . '/about',
        'team'     => $base . '/team',
        'contact'  => $base . '/contact',
    ];

    $excerpts = [];
    foreach ($candidates as $label => $url) {
        $resp = scraper_fetch($url, 15);
        if ($resp['status'] !== 200 || empty($resp['body'])) continue;

        // Strip scripts/styles before text extraction so we don't feed Claude JS noise.
        $doc = scraper_parse_html($resp['body']);
        $text = trim(scraper_get_text($doc));
        if (mb_strlen($text) < 80) continue; // too thin to be useful

        $excerpts[$label] = mb_substr($text, 0, $label === 'homepage' ? 3000 : 2000);
    }

    return $excerpts;
}

/**
 * Call Claude to extract a structured business profile from the crawled text.
 * Returns ['success' => bool, 'fields' => [...], 'confidence' => [...], 'signals' => [...], 'error' => ?string].
 *
 * Each field is null when Claude has no signal. Confidence is 0..1 per field.
 * Signals are short quoted snippets that justified the inference.
 */
function profile_infer(array $site, array $pages): array
{
    if (empty($pages)) {
        return ['success' => false, 'error' => 'No pages could be fetched for inference.'];
    }

    $sizes      = implode('|', PROFILE_SIZE_TIERS);
    $models     = implode('|', PROFILE_BUSINESS_MODELS);
    $offerings  = implode('|', PROFILE_OFFERING_TYPES);
    $segments   = implode('|', PROFILE_CUSTOMER_SEGMENTS);
    $scopes     = implode('|', PROFILE_MARKET_SCOPES);
    $maturities = implode('|', PROFILE_MATURITY_TIERS);

    $system = <<<SYS
You are a business analyst. Read the website excerpts below and infer a structured
profile of the company. Output ONLY valid JSON (no markdown fences, no commentary).

Schema:
{
  "fields": {
    "founding_year":     <int 1900-2026 or null>,
    "hq_city":           <string or null>,
    "hq_country":        <string or null>,   // prefer ISO English name e.g. "India", "United States"
    "size_tier":         <"{$sizes}" or null>,  // solo=1, small=2-10, mid=11-50, large=51-500, enterprise=500+
    "employee_estimate": <int or null>,       // your best guess
    "business_model":    <"{$models}" or null>,
    "offering_type":     <"{$offerings}" or null>,
    "industry_category": <short string or null>,  // e.g. "Tech consulting", "SaaS", "E-commerce"
    "industry_sub":      <short string or null>,  // e.g. "AI/ML services", "Document automation"
    "customer_segment":  <"{$segments}" or null>, // who they sell to
    "market_scope":      <"{$scopes}" or null>,    // local/regional/national/global
    "maturity_tier":     <"{$maturities}" or null>
  },
  "confidence": { /* same keys, value 0.0-1.0 — how sure are you per field */ },
  "signals":    { /* same keys, value = short quoted text from the excerpts that supports the inference, or null */ }
}

Rules:
- Be honest about uncertainty. If About/Team pages don't exist, you'll have less to go on — return nulls with low confidence instead of guessing.
- Don't invent numbers. If you cannot find a founding year on the page, return null.
- size_tier and employee_estimate should agree: solo=1, small=2-10, mid=11-50, large=51-500, enterprise=500+.
- maturity_tier: bootstrapped = early/self-funded, established = solid mid-size, category_leader = #1 or #2 in their niche, public_company = stock-listed.
- Enums MUST match exactly one of the allowed values — no synonyms, no new categories.
SYS;

    $page_block = '';
    foreach ($pages as $label => $text) {
        $page_block .= "\n\n--- " . strtoupper($label) . " ---\n" . $text;
    }

    $user_prompt = "Company name: " . ($site['name'] ?? 'unknown')
        . "\nDomain: " . ($site['domain'] ?? 'unknown')
        . "\n\nWebsite excerpts:" . $page_block;

    $resp = haiku_chat($system, $user_prompt, 1500);
    if (empty($resp['success'])) {
        return ['success' => false, 'error' => $resp['error'] ?? 'Haiku call failed'];
    }

    $content = trim($resp['content']);
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
    $data = json_decode($content, true);
    if (!is_array($data) || empty($data['fields'])) {
        return ['success' => false, 'error' => 'Could not parse inference JSON', 'raw' => mb_substr($content, 0, 500)];
    }

    // Clamp enums + types so a hallucinated value can't sneak into the DB.
    $f = $data['fields'];
    $fields = [
        'founding_year'     => _profile_clamp_int($f['founding_year'] ?? null, 1900, 2030),
        'hq_city'           => _profile_clamp_string($f['hq_city'] ?? null, 80),
        'hq_country'        => _profile_clamp_string($f['hq_country'] ?? null, 80),
        'size_tier'         => _profile_clamp_enum($f['size_tier'] ?? null, PROFILE_SIZE_TIERS),
        'employee_estimate' => _profile_clamp_int($f['employee_estimate'] ?? null, 1, 1000000),
        'business_model'    => _profile_clamp_enum($f['business_model'] ?? null, PROFILE_BUSINESS_MODELS),
        'offering_type'     => _profile_clamp_enum($f['offering_type'] ?? null, PROFILE_OFFERING_TYPES),
        'industry_category' => _profile_clamp_string($f['industry_category'] ?? null, 80),
        'industry_sub'      => _profile_clamp_string($f['industry_sub'] ?? null, 120),
        'customer_segment'  => _profile_clamp_enum($f['customer_segment'] ?? null, PROFILE_CUSTOMER_SEGMENTS),
        'market_scope'      => _profile_clamp_enum($f['market_scope'] ?? null, PROFILE_MARKET_SCOPES),
        'maturity_tier'     => _profile_clamp_enum($f['maturity_tier'] ?? null, PROFILE_MATURITY_TIERS),
    ];

    return [
        'success'    => true,
        'fields'     => $fields,
        'confidence' => is_array($data['confidence'] ?? null) ? $data['confidence'] : [],
        'signals'    => is_array($data['signals']    ?? null) ? $data['signals']    : [],
    ];
}

/**
 * Save the inferred fields to the sites table.
 *
 * Skips fields the user has already confirmed (profile_confirmed=1) so re-scans
 * never overwrite human edits — except for confidence/signals/timestamp which
 * track the latest inference for audit.
 */
function profile_save(PDO $db, int $site_id, array $inferred): void
{
    if (empty($inferred['success']) || empty($inferred['fields'])) return;

    $stmt = $db->prepare('SELECT profile_confirmed FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $confirmed = (int)$stmt->fetchColumn() === 1;

    if ($confirmed) {
        // User has reviewed — only refresh the audit fields, don't touch the values.
        $upd = $db->prepare('UPDATE sites SET profile_confidence = ?, profile_signals = ?, profile_inferred_at = NOW() WHERE id = ?');
        $upd->execute([
            json_encode($inferred['confidence'], JSON_UNESCAPED_UNICODE),
            json_encode($inferred['signals'],    JSON_UNESCAPED_UNICODE),
            $site_id,
        ]);
        return;
    }

    $cols = array_keys($inferred['fields']);
    $set  = implode(', ', array_map(fn($c) => "`{$c}` = ?", $cols));
    $sql  = "UPDATE sites SET {$set}, profile_confidence = ?, profile_signals = ?, profile_inferred_at = NOW() WHERE id = ?";

    $params = array_values($inferred['fields']);
    $params[] = json_encode($inferred['confidence'], JSON_UNESCAPED_UNICODE);
    $params[] = json_encode($inferred['signals'],    JSON_UNESCAPED_UNICODE);
    $params[] = $site_id;

    $db->prepare($sql)->execute($params);
}

/**
 * Single fetch downstream agents use. Returns the profile + computed convenience flags.
 * Returns null if the site doesn't exist.
 */
function profile_get(PDO $db, int $site_id): ?array
{
    $stmt = $db->prepare('SELECT id, name, domain, business_description, persona, usp, topics, brand_tone,
                                 founding_year, hq_city, hq_country, size_tier, employee_estimate,
                                 business_model, offering_type, industry_category, industry_sub,
                                 customer_segment, market_scope, maturity_tier,
                                 profile_confirmed, profile_inferred_at, profile_confidence, profile_signals
                          FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $row['topics'] = json_decode($row['topics'] ?? '[]', true) ?: [];
    $row['profile_confidence'] = json_decode($row['profile_confidence'] ?? '{}', true) ?: [];
    $row['profile_signals']    = json_decode($row['profile_signals']    ?? '{}', true) ?: [];

    // Computed flags downstream code can branch on.
    $size = $row['size_tier'] ?? null;
    $row['_is_sme']       = in_array($size, ['solo', 'small', 'mid'], true);
    $row['_is_micro']     = in_array($size, ['solo', 'small'], true);
    $row['_is_enterprise']= in_array($size, ['large', 'enterprise'], true);
    $row['_is_local']     = in_array($row['market_scope'] ?? '', ['local', 'regional'], true);
    $row['_is_b2c']       = ($row['business_model'] ?? '') === 'b2c';
    $row['_is_authority'] = in_array($row['maturity_tier'] ?? '', ['category_leader', 'public_company'], true);

    return $row;
}

/**
 * Render the profile as a compact prompt block that every LLM-using agent
 * should inject into its system prompt. ~200 tokens.
 *
 * Skips empty fields so a half-filled profile still produces a clean block.
 */
function profile_prompt_block(array $profile): string
{
    $lines = [];
    $lines[] = '## About this business (use this to ground every decision)';
    $lines[] = '- Name: ' . ($profile['name'] ?? 'unknown') . ' (' . ($profile['domain'] ?? '') . ')';

    if (!empty($profile['founding_year'])) {
        $age = max(1, (int)date('Y') - (int)$profile['founding_year']);
        $lines[] = "- Founded: {$profile['founding_year']} ({$age} years in business)";
    }
    $where = trim(implode(', ', array_filter([$profile['hq_city'] ?? null, $profile['hq_country'] ?? null])));
    if ($where !== '') $lines[] = "- HQ: {$where}";

    if (!empty($profile['size_tier'])) {
        $sz = $profile['size_tier'];
        $emp = !empty($profile['employee_estimate']) ? " (~{$profile['employee_estimate']} people)" : '';
        $lines[] = "- Size: {$sz}{$emp}";
    }
    if (!empty($profile['business_model'])) $lines[] = "- Business model: " . strtoupper($profile['business_model']);
    if (!empty($profile['offering_type']))  $lines[] = "- Offering: {$profile['offering_type']}";
    if (!empty($profile['industry_category'])) {
        $ind = $profile['industry_category'];
        if (!empty($profile['industry_sub'])) $ind .= " → {$profile['industry_sub']}";
        $lines[] = "- Industry: {$ind}";
    }
    if (!empty($profile['customer_segment'])) $lines[] = "- Sells to: {$profile['customer_segment']}";
    if (!empty($profile['market_scope']))     $lines[] = "- Market: {$profile['market_scope']}";
    if (!empty($profile['maturity_tier']))    $lines[] = "- Maturity: " . str_replace('_', ' ', $profile['maturity_tier']);

    if (!empty($profile['business_description'])) {
        $lines[] = "- In their own words: " . trim($profile['business_description']);
    }
    if (!empty($profile['persona'])) {
        $lines[] = "- Ideal customer: " . trim($profile['persona']);
    }
    if (!empty($profile['usp'])) {
        $lines[] = "- USP: " . trim($profile['usp']);
    }
    if (!empty($profile['topics']) && is_array($profile['topics'])) {
        $lines[] = "- Topics: " . implode(', ', $profile['topics']);
    }
    if (!empty($profile['brand_tone'])) {
        $lines[] = "- Voice/tone: " . $profile['brand_tone'];
    }

    $lines[] = '';
    $lines[] = 'Calibrate everything you produce to THIS business. A small B2B consultancy should not be advised to compete head-on with category leaders; an enterprise should not be advised to chase hyperlocal long-tail. Match scale and audience.';

    return implode("\n", $lines);
}

// ── internal helpers ────────────────────────────────────────

function _profile_clamp_enum($value, array $allowed): ?string
{
    if ($value === null || $value === '') return null;
    $value = strtolower(trim((string)$value));
    return in_array($value, $allowed, true) ? $value : null;
}

function _profile_clamp_int($value, int $min, int $max): ?int
{
    if ($value === null || $value === '') return null;
    if (!is_numeric($value)) return null;
    $n = (int)$value;
    if ($n < $min || $n > $max) return null;
    return $n;
}

function _profile_clamp_string($value, int $max_len): ?string
{
    if ($value === null) return null;
    $s = trim((string)$value);
    if ($s === '') return null;
    return mb_substr($s, 0, $max_len);
}
