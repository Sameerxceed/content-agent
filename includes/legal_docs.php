<?php
/**
 * Legal docs — auto-detect, auto-generate, auto-publish the standard
 * compliance pages (privacy / terms / cookies / refund / disclaimer)
 * that every website needs but most SMB owners ignore.
 *
 * Day 1 (this file): detection.
 * Day 2: Claude generation per doc type, jurisdiction-aware.
 * Day 3: CMS publish path + review dashboard.
 *
 * Public API:
 *   legal_docs_required_types(array $profile): array      — which doc types apply
 *   legal_docs_detect_missing(PDO $db, int $site_id): array — runs detection, writes rows
 *   legal_docs_list(PDO $db, int $site_id): array         — load all rows for a site
 *   legal_docs_count_missing(PDO $db, int $site_id): int  — quick alert count
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/business_profile.php';

/**
 * Standard URL paths each doc type usually lives at. Scanner HEADs each
 * candidate; first 200 wins (the site has it, no need to generate).
 */
function legal_docs_expected_paths(string $doc_type): array
{
    return match ($doc_type) {
        'privacy'    => ['/privacy', '/privacy-policy', '/privacy.html', '/privacy.php', '/policy/privacy'],
        'terms'      => ['/terms', '/terms-of-service', '/terms-and-conditions', '/tos', '/t-and-c', '/legal/terms'],
        'cookies'    => ['/cookies', '/cookie-policy', '/cookies.html', '/policy/cookies'],
        'refund'     => ['/refund', '/refund-policy', '/return-policy', '/returns', '/cancellation-policy'],
        'disclaimer' => ['/disclaimer', '/legal-disclaimer', '/disclaimers'],
        default      => [],
    };
}

/**
 * Decide which doc types are required vs recommended vs optional for THIS
 * business, based on the inferred profile. Privacy + Terms are universal;
 * the rest depend on what the business actually does.
 *
 * Returns: ['privacy' => 'required', 'cookies' => 'recommended', ...]
 */
function legal_docs_required_types(array $profile): array
{
    $types = [
        'privacy' => 'required',  // Anyone collecting an email / cookie / IP → required globally
        'terms'   => 'required',  // Anyone with a website where users do anything → required
    ];

    // Cookies banner / policy — required in EU + UK + EEA, recommended elsewhere.
    // Default to required since most sites do use cookies even when owners don't realise.
    $types['cookies'] = 'required';

    // Refund / cancellation — only for sites that take money
    $offering = (string)($profile['offering_type'] ?? '');
    $model    = (string)($profile['business_model'] ?? '');
    if ($offering === 'product' || $model === 'b2c' || $model === 'marketplace' || $model === 'subscription') {
        $types['refund'] = 'required';
    }

    // Disclaimer — for advice-giving / health / finance / legal sectors where liability matters
    $industry = strtolower((string)($profile['industry'] ?? '') . ' ' . (string)($profile['business_description'] ?? ''));
    if (preg_match('/(financ|invest|legal|medic|health|pharma|tax|account|consult|advisor|insur)/i', $industry)) {
        $types['disclaimer'] = 'recommended';
    }

    return $types;
}

/**
 * Run detection for one site: HEAD each candidate URL, write a legal_docs
 * row per doc_type with status=missing (or 'published' with found_url if
 * the site already has one).
 *
 * @return array Summary: ['checked' => N, 'missing' => N, 'found' => N, 'types' => [...]]
 */
function legal_docs_detect_missing(PDO $db, int $site_id): array
{
    $stmt = $db->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    if (!$site) return ['error' => 'site not found'];

    $profile = profile_get($db, $site_id) ?: [];
    $required = legal_docs_required_types($profile);
    $base_url = 'https://' . ltrim((string)$site['domain'], 'https://');

    $checked = 0; $missing = 0; $found = 0; $types_out = [];

    foreach ($required as $doc_type => $relevance) {
        $paths = legal_docs_expected_paths($doc_type);
        $hit_url = null;
        foreach ($paths as $p) {
            $url = $base_url . $p;
            $checked++;
            if (_legal_docs_head_check($url)) { $hit_url = $url; break; }
        }

        // Upsert. Don't overwrite an existing 'drafted/approved/published' row's
        // body; just refresh detection state.
        $existing = $db->prepare("SELECT id, status FROM legal_docs WHERE site_id = ? AND doc_type = ?");
        $existing->execute([$site_id, $doc_type]);
        $row = $existing->fetch();

        if ($hit_url) {
            $found++;
            // Live URL found. But if a fresh draft exists locally, don't
            // overwrite — the draft is more current than whatever is live.
            // Only auto-mark 'published' when there's nothing better in flight.
            $draft_in_flight = $row && in_array($row['status'], ['drafted', 'approved', 'generating'], true);
            if ($row && $draft_in_flight) {
                $db->prepare("UPDATE legal_docs SET found_url = ?, expected_paths = ?, relevance = ?, detected_at = NOW() WHERE id = ?")
                   ->execute([$hit_url, json_encode($paths), $relevance, (int)$row['id']]);
                $types_out[$doc_type] = ['status' => $row['status'], 'url' => $hit_url, 'relevance' => $relevance, 'note' => 'draft pending, live URL found too'];
            } elseif ($row) {
                $db->prepare("UPDATE legal_docs SET status = 'published', found_url = ?, expected_paths = ?, relevance = ?, detected_at = NOW() WHERE id = ?")
                   ->execute([$hit_url, json_encode($paths), $relevance, (int)$row['id']]);
                $types_out[$doc_type] = ['status' => 'present', 'url' => $hit_url, 'relevance' => $relevance];
            } else {
                $db->prepare("INSERT INTO legal_docs (site_id, doc_type, status, found_url, expected_paths, relevance, detected_at, published_url)
                              VALUES (?, ?, 'published', ?, ?, ?, NOW(), ?)")
                   ->execute([$site_id, $doc_type, $hit_url, json_encode($paths), $relevance, $hit_url]);
                $types_out[$doc_type] = ['status' => 'present', 'url' => $hit_url, 'relevance' => $relevance];
            }
        } else {
            $missing++;
            // Detection didn't find a live URL. NEVER auto-downgrade from any
            // post-detection state — drafts, approvals, and published rows all
            // stay put. Cache propagation, CDN lag, or a slug-format mismatch
            // can cause false 404s on the HEAD check, and silently overwriting
            // the user's progress is the worst possible UX. Only refresh
            // paths/relevance/detected_at metadata.
            $immutable_statuses = ['drafted', 'approved', 'generating', 'published', 'failed'];
            if ($row && in_array($row['status'], $immutable_statuses, true)) {
                $db->prepare("UPDATE legal_docs SET expected_paths = ?, relevance = ?, detected_at = NOW() WHERE id = ?")
                   ->execute([json_encode($paths), $relevance, (int)$row['id']]);
                $types_out[$doc_type] = ['status' => $row['status'], 'url' => $row['published_url'] ?? null, 'relevance' => $relevance];
            } elseif ($row) {
                // Existing row in status='missing' or 'unknown' — just refresh the timestamp
                $db->prepare("UPDATE legal_docs SET status = 'missing', found_url = NULL, expected_paths = ?, relevance = ?, detected_at = NOW() WHERE id = ?")
                   ->execute([json_encode($paths), $relevance, (int)$row['id']]);
                $types_out[$doc_type] = ['status' => 'missing', 'url' => null, 'relevance' => $relevance];
            } else {
                $db->prepare("INSERT INTO legal_docs (site_id, doc_type, status, expected_paths, relevance, detected_at)
                              VALUES (?, ?, 'missing', ?, ?, NOW())")
                   ->execute([$site_id, $doc_type, json_encode($paths), $relevance]);
                $types_out[$doc_type] = ['status' => 'missing', 'url' => null, 'relevance' => $relevance];
            }
        }
    }

    return [
        'checked' => $checked,
        'missing' => $missing,
        'found'   => $found,
        'types'   => $types_out,
    ];
}

/** Internal: HEAD request with short timeout, returns true on 200/301/302. */
function _legal_docs_head_check(string $url): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,  // some SMB sites have iffy certs; we just need existence
        CURLOPT_USERAGENT      => 'Mozilla/5.0 ContentAgent Scanner',
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return in_array($code, [200, 301, 302], true);
}

/** Load every legal_docs row for a site, indexed by doc_type. */
function legal_docs_list(PDO $db, int $site_id): array
{
    $stmt = $db->prepare("SELECT * FROM legal_docs WHERE site_id = ? ORDER BY FIELD(doc_type,'privacy','terms','cookies','refund','disclaimer')");
    $stmt->execute([$site_id]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['doc_type']] = $row;
    }
    return $out;
}

/** Cheap count for the Site Overview alert badge. */
function legal_docs_count_missing(PDO $db, int $site_id): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM legal_docs WHERE site_id = ? AND status = 'missing'");
    $stmt->execute([$site_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Generate a doc draft via Claude. Jurisdiction-aware. Reads business profile +
 * connected platforms to produce accurate content (cookie list reflects what
 * the site actually tracks, refund terms mention real payment processors).
 *
 * Marks the row 'generating' before calling Claude; 'drafted' on success;
 * 'failed' with last_error on failure.
 *
 * @return array { success: bool, error?: string, title?: string, body_html?: string }
 */
function legal_docs_generate(PDO $db, int $site_id, string $doc_type): array
{
    require_once __DIR__ . '/haiku.php';

    $stmt = $db->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    if (!$site) return ['success' => false, 'error' => 'site not found'];

    $profile = profile_get($db, $site_id) ?: [];

    // Make sure a row exists
    $row_stmt = $db->prepare("SELECT id FROM legal_docs WHERE site_id = ? AND doc_type = ?");
    $row_stmt->execute([$site_id, $doc_type]);
    $row_id = (int)$row_stmt->fetchColumn();
    if (!$row_id) {
        $db->prepare("INSERT INTO legal_docs (site_id, doc_type, status, detected_at) VALUES (?, ?, 'generating', NOW())")
           ->execute([$site_id, $doc_type]);
        $row_id = (int)$db->lastInsertId();
    } else {
        $db->prepare("UPDATE legal_docs SET status='generating', last_error=NULL WHERE id=?")->execute([$row_id]);
    }

    // Build a profile summary the prompt can reason about
    $hq_country = (string)($profile['hq_country'] ?? $site['country'] ?? '');
    $market     = (string)($profile['market_scope'] ?? 'global');
    $offering   = (string)($profile['offering_type'] ?? '');
    $model      = (string)($profile['business_model'] ?? '');
    $industry   = (string)($profile['industry'] ?? '');
    $desc       = (string)($profile['business_description'] ?? '');

    // Decide which jurisdictions the policy must explicitly cover
    $jurisdictions = ['IN', 'EU', 'US-CA'];  // baseline — India DPDP + EU GDPR + California CCPA
    if (in_array($market, ['global', 'international'], true) || $hq_country !== 'IN') {
        $jurisdictions[] = 'UK';
    }

    $doc_specs = [
        'privacy' => [
            'title' => 'Privacy Policy',
            'sections' => 'Information We Collect, How We Use Your Information, Cookies and Tracking, Third-Party Services, Data Sharing, Data Retention, Your Rights (access/correction/deletion/portability), International Data Transfers, Children\'s Privacy, Security, Changes to This Policy, Contact Us',
            'wordcount' => '1200-1800',
            'notes' => 'Must enumerate cookies, list any third-party processors (analytics, payment, email), explain consent mechanisms, and describe user data-deletion process. Include separate paragraphs for DPDP (India) rights, GDPR (EU/UK) rights, and CCPA (California) rights.',
        ],
        'terms' => [
            'title' => 'Terms of Service',
            'sections' => 'Acceptance of Terms, Eligibility, Account Registration, Acceptable Use, Intellectual Property, User Content, Disclaimers, Limitation of Liability, Indemnification, Termination, Governing Law and Jurisdiction, Dispute Resolution, Changes to Terms, Contact',
            'wordcount' => '1000-1500',
            'notes' => 'Set jurisdiction = ' . ($hq_country ?: 'India') . '. Include arbitration / dispute resolution mechanism. State liability cap.',
        ],
        'cookies' => [
            'title' => 'Cookie Policy',
            'sections' => 'What Are Cookies, Cookies We Use (Essential / Analytics / Marketing / Preference), Third-Party Cookies, Managing Your Cookie Preferences, How to Disable Cookies, Updates to This Policy, Contact Us',
            'wordcount' => '600-900',
            'notes' => 'Include a table of cookies (name, purpose, duration, type). Describe consent banner mechanism. Explain how to withdraw consent. Reference the Privacy Policy.',
        ],
        'refund' => [
            'title' => 'Refund and Cancellation Policy',
            'sections' => 'Refund Eligibility, How to Request a Refund, Processing Time, Non-Refundable Items, Cancellation of Subscriptions, Chargebacks, Contact Us',
            'wordcount' => '500-800',
            'notes' => 'Set refund window (default 14 days), describe process. Mention payment processor (Stripe / Razorpay / etc.). Reference jurisdiction consumer-protection laws.',
        ],
        'disclaimer' => [
            'title' => 'Disclaimer',
            'sections' => 'General Information, No Professional Advice, Limitation of Liability, External Links, Errors and Omissions, Contact Us',
            'wordcount' => '400-700',
            'notes' => 'For ' . ($industry ?: 'advisory') . ' businesses: explicitly state that content does not constitute ' . ($industry ?: 'professional') . ' advice and the reader should consult a qualified professional.',
        ],
    ];

    $spec = $doc_specs[$doc_type] ?? null;
    if (!$spec) {
        $db->prepare("UPDATE legal_docs SET status='failed', last_error=? WHERE id=?")
           ->execute(['unknown doc_type: ' . $doc_type, $row_id]);
        return ['success' => false, 'error' => 'unknown doc_type: ' . $doc_type];
    }

    $sys = "You are a compliance writer drafting legal documents for SMB websites.\n"
         . "Your output is a SUBSTANTIVE BASELINE document a small business can publish today.\n"
         . "It is NOT a substitute for a lawyer — but it should be far better than nothing, which is what 90% of SMBs have.\n\n"
         . "CONSTRAINTS:\n"
         . "- Use clear, plain English. No archaic legalese ('hereinafter', 'witnesseth'). Direct language professionals respect.\n"
         . "- Cite specific laws where relevant: DPDP Act 2023 (India), GDPR (EU/UK), CCPA + CPRA (California). Don't fake citations.\n"
         . "- Write for the SPECIFIC business below — not a generic template. Reference what they actually do.\n"
         . "- Use <h2> for major sections, <h3> for subsections, <p> for paragraphs, <ul><li> for lists. Output ONLY the body — no <html>/<head>/<body> wrappers.\n"
         . "- Include a 'Last updated: " . date('j F Y') . "' line at the top.\n"
         . "- End with a Contact section using the company name + a placeholder email address pattern based on the domain.\n\n"
         . "JURISDICTIONS to cover explicitly: " . implode(', ', $jurisdictions) . "\n\n"
         . "OUTPUT — return strict JSON only: {\"title\": \"...\", \"body_html\": \"...\"}\n";

    $business_block = "Business: " . (string)($site['name'] ?? $site['domain']) . "\n"
                    . "Domain: " . (string)$site['domain'] . "\n"
                    . "HQ Country: " . ($hq_country ?: 'India') . "\n"
                    . "Market Scope: " . $market . "\n"
                    . "Business Model: " . ($model ?: 'unspecified') . "\n"
                    . "Offering: " . ($offering ?: 'unspecified') . "\n"
                    . "Industry: " . ($industry ?: 'unspecified') . "\n"
                    . "Description: " . ($desc ?: '(no profile description on file)') . "\n";

    $prompt = $business_block . "\n"
            . "Document: {$spec['title']}\n"
            . "Target sections: {$spec['sections']}\n"
            . "Target word count: {$spec['wordcount']}\n"
            . "Critical guidance: {$spec['notes']}\n\n"
            . "Produce the document now.";

    $resp = haiku_chat($sys, $prompt, 8000, 'legal_doc_generate', $site_id);
    if (empty($resp['success'])) {
        $err = (string)($resp['error'] ?? 'unknown Claude error');
        $db->prepare("UPDATE legal_docs SET status='failed', last_error=? WHERE id=?")->execute([$err, $row_id]);
        return ['success' => false, 'error' => $err];
    }

    $data = extract_json_from_text((string)$resp['content']);
    if (!is_array($data) || empty($data['body_html'])) {
        $db->prepare("UPDATE legal_docs SET status='failed', last_error=? WHERE id=?")
           ->execute(['malformed Claude response', $row_id]);
        return ['success' => false, 'error' => 'malformed Claude response'];
    }

    $title = (string)($data['title'] ?? $spec['title']);
    $body  = (string)$data['body_html'];
    $slug  = match ($doc_type) {
        'privacy'    => 'privacy',
        'terms'      => 'terms',
        'cookies'    => 'cookies',
        'refund'     => 'refund-policy',
        'disclaimer' => 'disclaimer',
    };

    $db->prepare("UPDATE legal_docs SET status='drafted', title=?, body_html=?, slug=?, jurisdictions=?, generated_at=NOW(), version=version+1, last_error=NULL WHERE id=?")
       ->execute([$title, $body, $slug, json_encode($jurisdictions), $row_id]);

    return [
        'success'   => true,
        'doc_id'    => $row_id,
        'title'     => $title,
        'body_html' => $body,
    ];
}

/**
 * Compute the canonical hosted URL for a legal doc.
 * This is the Tier-1 universal URL that works for every customer regardless
 * of whether their site has a CMS we can push to.
 */
function legal_docs_hosted_url(int $site_id, string $slug): string
{
    $base = rtrim((string)config('app_url', ''), '/');
    return $base . '/legal/' . $site_id . '/' . $slug;
}

/**
 * Publish a doc.
 *
 * Strategy: ContentAgent always hosts the doc at /legal/{site_id}/{slug} —
 * that URL is the canonical published_url and works on day one for every
 * customer, no integrations required. If the site has a CMS configured we
 * ALSO try a best-effort push so the doc lives on the customer's own
 * domain too — but failure there does NOT roll the doc back to drafted.
 * The customer always has a working link to share.
 */
function legal_docs_publish(PDO $db, int $site_id, string $doc_type, int $user_id = 0): array
{
    require_once __DIR__ . '/cms-connector.php';

    $stmt = $db->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    if (!$site) return ['success' => false, 'error' => 'site not found'];

    $row_stmt = $db->prepare("SELECT * FROM legal_docs WHERE site_id = ? AND doc_type = ?");
    $row_stmt->execute([$site_id, $doc_type]);
    $row = $row_stmt->fetch();
    if (!$row) return ['success' => false, 'error' => 'no draft found — generate first'];
    if (empty($row['body_html'])) return ['success' => false, 'error' => 'draft body is empty'];

    // Mark approved if not already
    if ($row['status'] !== 'approved') {
        $db->prepare("UPDATE legal_docs SET status='approved', approved_at=NOW(), approved_by=? WHERE id=?")
           ->execute([$user_id ?: null, (int)$row['id']]);
    }

    $slug = (string)$row['slug'];
    $hosted_url = legal_docs_hosted_url($site_id, $slug);

    // Tier-1: hosted URL is always the canonical published location.
    // Mark published immediately — works for every customer regardless of
    // their tech stack.
    $db->prepare("UPDATE legal_docs SET status='published', published_at=NOW(), published_url=?, last_error=NULL WHERE id=?")
       ->execute([$hosted_url, (int)$row['id']]);

    $cms_result = null;
    $cms_note   = null;

    // Tier-2: opportunistic push to the customer's CMS if connected.
    // Failure here does NOT downgrade the publish — the hosted URL still works.
    if (!empty($site['cms_url']) && !empty($site['cms_api_key'])) {
        $post = [
            'title'            => (string)$row['title'],
            'slug'             => $slug,
            'excerpt'          => '',
            'body'             => (string)$row['body_html'],
            'tags'             => '[]',
            'type'             => 'page',
            'seo_title'        => (string)$row['title'],
            'seo_description'  => 'Read our ' . (string)$row['title'] . '.',
            'seo_keywords'     => '',
            'published_at'     => date('Y-m-d'),
        ];
        $cms_result = cms_push_post($post, (string)$site['cms_url'], (string)$site['cms_api_key']);
        if (!empty($cms_result['success'])) {
            $cms_note = 'Also pushed to your CMS at ' . rtrim((string)$site['cms_url'], '/') . '/' . $slug;
        } else {
            $cms_note = 'CMS push skipped: ' . (string)($cms_result['error'] ?? 'unknown error') . ' — your hosted URL still works.';
        }
    } else {
        $cms_note = 'No CMS connected — hosting on ContentAgent only.';
    }

    return [
        'success'       => true,
        'published_url' => $hosted_url,
        'cms_pushed'    => !empty($cms_result['success']),
        'note'          => $cms_note,
    ];
}
