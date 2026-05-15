<?php
/**
 * Save or fetch a site's business focus (topics + description).
 * This is the source of truth for everything AI does — keyword research, content writing, SEO proposals.
 *
 * POST JSON:
 *   { action: "save", site_id, business_description, topics: ["..."] }
 *   { action: "suggest", site_id }   — uses scraper + AI to suggest topics from the live homepage
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/scraper.php';
require_once __DIR__ . '/../../includes/haiku.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';
$site_id = (int)($input['site_id'] ?? 0);

if (!$site_id) { http_response_code(400); echo json_encode(['error' => 'site_id required']); exit; }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }

if ($action === 'save') {
    $desc = trim($input['business_description'] ?? '');
    $persona = trim($input['persona'] ?? '');
    $usp = trim($input['usp'] ?? '');
    $topics = $input['topics'] ?? [];
    if (!is_array($topics)) $topics = [];
    $topics = array_values(array_filter(array_map('trim', $topics)));

    if (empty($desc) && empty($topics)) {
        echo json_encode(['error' => 'Please provide either a business description or topics']);
        exit;
    }

    $confirmed = !empty($topics) ? 1 : 0;
    $stmt = $db->prepare('UPDATE sites SET business_description = ?, persona = ?, usp = ?, topics = ?, topics_confirmed = ? WHERE id = ?');
    $stmt->execute([$desc ?: null, $persona ?: null, $usp ?: null, json_encode($topics), $confirmed, $site_id]);

    $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
        $site_id, 'business_focus_saved', json_encode(['topics' => $topics, 'description_chars' => mb_strlen($desc), 'by_user' => $user_id]), 'success',
    ]);

    echo json_encode(['success' => true, 'topics' => $topics, 'topics_confirmed' => (bool)$confirmed]);
    exit;
}

if ($action === 'suggest') {
    // Scrape homepage and ask AI to identify likely products/business
    $domain = 'https://' . ltrim($site['domain'], 'https://');
    $homepage = scraper_fetch($domain, 15);

    if ($homepage['status'] !== 200) {
        echo json_encode(['success' => false, 'error' => 'Could not reach the homepage (HTTP ' . $homepage['status'] . ')']);
        exit;
    }

    $doc = scraper_parse_html($homepage['body']);
    $title = scraper_get_title($doc);
    $meta = scraper_get_meta($doc);
    $body_text = mb_substr(scraper_get_text($doc), 0, 4000);

    $ctx = "Page title: {$title}\nMeta description: " . ($meta['description'] ?? '') . "\nHomepage text:\n{$body_text}";

    $ai = haiku_chat(
        "You are reading a real business homepage. Identify exactly what they sell or offer.\n"
        . "Output ONLY a JSON object with two fields:\n"
        . "  \"description\": one-sentence summary in plain English (e.g. \"Handwoven silk scarves and shawls from London\")\n"
        . "  \"topics\": array of 4-6 short keyword phrases (2-3 words each) that match what they actually sell\n"
        . "Be specific. If the homepage says scarves, say scarves — don't generalize to 'luxury accessories'. "
        . "If you cannot clearly determine the business, return {\"description\": \"\", \"topics\": []}.",
        $ctx,
        512
    );

    $suggestion = ['description' => '', 'topics' => []];
    if ($ai['success']) {
        $content = preg_replace('/^```(?:json)?\s*/m', '', $ai['content']);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $parsed = json_decode(trim($content), true);
        if (is_array($parsed)) $suggestion = array_merge($suggestion, $parsed);
    }

    echo json_encode(['success' => true, 'suggestion' => $suggestion]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
