<?php
/**
 * RSS Feed Parser
 * Fetches and parses RSS/Atom feeds.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Fetch and parse an RSS/Atom feed.
 *
 * @return array ['items' => [...], 'title' => string, 'error' => string|null]
 */
function rss_fetch(string $feed_url, int $limit = 20): array
{
    $response = http_get($feed_url, [], 15);

    if ($response['error'] || $response['status'] !== 200) {
        return [
            'items' => [],
            'title' => '',
            'error' => $response['error'] ?: "HTTP {$response['status']}",
        ];
    }

    return rss_parse($response['body'], $limit);
}

/**
 * Parse RSS/Atom XML string.
 */
function rss_parse(string $xml, int $limit = 20): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);

    if (!$doc->loadXML($xml)) {
        libxml_clear_errors();
        return ['items' => [], 'title' => '', 'error' => 'Invalid XML'];
    }

    libxml_clear_errors();

    // Detect feed type
    $root = $doc->documentElement->tagName;

    if (stripos($root, 'feed') !== false) {
        return rss_parse_atom($doc, $limit);
    }

    return rss_parse_rss($doc, $limit);
}

/**
 * Parse RSS 2.0 feed.
 */
function rss_parse_rss(DOMDocument $doc, int $limit): array
{
    $items = [];
    $channel = $doc->getElementsByTagName('channel')->item(0);
    $feed_title = '';

    if ($channel) {
        $title_el = $channel->getElementsByTagName('title')->item(0);
        $feed_title = $title_el ? trim($title_el->textContent) : '';
    }

    $entries = $doc->getElementsByTagName('item');
    $count = 0;

    foreach ($entries as $entry) {
        if ($count >= $limit) break;

        $title       = get_tag_value($entry, 'title');
        $link        = get_tag_value($entry, 'link');
        $description = get_tag_value($entry, 'description');
        $pubDate     = get_tag_value($entry, 'pubDate');
        $guid        = get_tag_value($entry, 'guid');
        $categories  = [];

        $cat_tags = $entry->getElementsByTagName('category');
        foreach ($cat_tags as $cat) {
            $categories[] = trim($cat->textContent);
        }

        $items[] = [
            'title'       => $title,
            'link'        => $link,
            'description' => strip_tags($description),
            'date'        => $pubDate ? date('Y-m-d H:i:s', strtotime($pubDate)) : null,
            'guid'        => $guid ?: $link,
            'categories'  => $categories,
            'source'      => $feed_title,
        ];

        $count++;
    }

    return ['items' => $items, 'title' => $feed_title, 'error' => null];
}

/**
 * Parse Atom feed.
 */
function rss_parse_atom(DOMDocument $doc, int $limit): array
{
    $items = [];
    $feed_title = '';

    $title_tags = $doc->getElementsByTagName('title');
    if ($title_tags->length > 0) {
        $feed_title = trim($title_tags->item(0)->textContent);
    }

    $entries = $doc->getElementsByTagName('entry');
    $count = 0;

    foreach ($entries as $entry) {
        if ($count >= $limit) break;

        $title   = get_tag_value($entry, 'title');
        $summary = get_tag_value($entry, 'summary') ?: get_tag_value($entry, 'content');
        $updated = get_tag_value($entry, 'updated') ?: get_tag_value($entry, 'published');
        $id      = get_tag_value($entry, 'id');

        // Get link href from Atom
        $link = '';
        $link_tags = $entry->getElementsByTagName('link');
        foreach ($link_tags as $link_tag) {
            $rel = $link_tag->getAttribute('rel');
            if ($rel === 'alternate' || $rel === '' || !$rel) {
                $link = $link_tag->getAttribute('href');
                break;
            }
        }

        $categories = [];
        $cat_tags = $entry->getElementsByTagName('category');
        foreach ($cat_tags as $cat) {
            $term = $cat->getAttribute('term') ?: trim($cat->textContent);
            if ($term) $categories[] = $term;
        }

        $items[] = [
            'title'       => $title,
            'link'        => $link,
            'description' => strip_tags($summary),
            'date'        => $updated ? date('Y-m-d H:i:s', strtotime($updated)) : null,
            'guid'        => $id ?: $link,
            'categories'  => $categories,
            'source'      => $feed_title,
        ];

        $count++;
    }

    return ['items' => $items, 'title' => $feed_title, 'error' => null];
}

/**
 * Get text content of a child tag.
 */
function get_tag_value(DOMElement $parent, string $tag): string
{
    $elements = $parent->getElementsByTagName($tag);
    if ($elements->length === 0) return '';
    return trim($elements->item(0)->textContent);
}

/**
 * Filter feed items by keyword relevance.
 *
 * @param array $items   Feed items
 * @param array $keywords Keywords to match against
 * @param float $min_score Minimum relevance score (0-1)
 * @return array Filtered and scored items
 */
function rss_filter_by_relevance(array $items, array $keywords, float $min_score = 0.1): array
{
    $filtered = [];

    foreach ($items as $item) {
        $text = strtolower($item['title'] . ' ' . $item['description']);
        $score = 0;
        $matched = [];

        foreach ($keywords as $kw) {
            $kw_lower = strtolower($kw);
            if (strpos($text, $kw_lower) !== false) {
                // Title match is worth more
                if (strpos(strtolower($item['title']), $kw_lower) !== false) {
                    $score += 0.3;
                } else {
                    $score += 0.1;
                }
                $matched[] = $kw;
            }
        }

        if ($score >= $min_score) {
            $item['relevance_score'] = round($score, 2);
            $item['matched_keywords'] = $matched;
            $filtered[] = $item;
        }
    }

    // Sort by relevance score descending
    usort($filtered, fn($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);

    return $filtered;
}

/**
 * Deduplicate feed items by title similarity.
 */
function rss_deduplicate(array $items): array
{
    $unique = [];
    $seen_titles = [];

    foreach ($items as $item) {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/', '', $item['title']));

        // Check against existing titles with simple similarity
        $is_dupe = false;
        foreach ($seen_titles as $seen) {
            similar_text($normalized, $seen, $percent);
            if ($percent > 80) {
                $is_dupe = true;
                break;
            }
        }

        if (!$is_dupe) {
            $seen_titles[] = $normalized;
            $unique[] = $item;
        }
    }

    return $unique;
}
