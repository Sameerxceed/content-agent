<?php
/**
 * Instagram Carousel Generator
 * Turns a blog post into 5-slide carousel images using HTML/CSS → image conversion.
 * Generates HTML slides that can be screenshot/converted to images.
 *
 * CLI Usage: php agent/carousel-generator.php --post=1
 *            php agent/carousel-generator.php --post=1 --output=carousel
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/haiku.php';

$db = require __DIR__ . '/../includes/db.php';

$opts = getopt('', ['post:', 'output:']);
$post_id = $opts['post'] ?? null;
$output_dir = $opts['output'] ?? __DIR__ . '/../cache/carousels';

if (!$post_id) {
    echo "Usage: php carousel-generator.php --post=1\n";
    exit(1);
}

$stmt = $db->prepare('SELECT p.*, s.domain, s.brand_colors, s.brand_fonts, s.brand_tone, s.name as site_name FROM posts p JOIN sites s ON p.site_id = s.id WHERE p.id = ?');
$stmt->execute([$post_id]);
$post = $stmt->fetch();

if (!$post) {
    echo "Post #{$post_id} not found.\n";
    exit(1);
}

echo "Generating carousel for: {$post['title']}\n";

$colors = json_decode($post['brand_colors'] ?? '[]', true) ?: [];
$fonts = json_decode($post['brand_fonts'] ?? '[]', true) ?: [];

// Filter out greys/blacks/whites — not useful as brand colors
$brand_colors = array_filter($colors, function($c) {
    $c = strtolower($c);
    return !in_array($c, ['#000', '#000000', '#333', '#333333', '#555', '#666', '#777', '#888', '#999', '#aaa', '#bbb', '#ccc', '#ddd', '#eee', '#f0f0f0', '#f5f5f5', '#fafafa', '#fff', '#ffffff', '#e8e8e8', '#0a0a0a']);
});

$primary = !empty($brand_colors) ? reset($brand_colors) : '#1B3A6B';
$accent = count($brand_colors) > 1 ? array_values($brand_colors)[1] : '#CC3300';

// Filter out invalid fonts
$valid_fonts = array_filter($fonts, fn($f) => strlen($f) > 2 && strpos($f, '&#') === false && strpos($f, '&') === false);
$font = !empty($valid_fonts) ? reset($valid_fonts) : 'Inter';

// Use Haiku to extract 5 key points from the post
$content = strip_tags($post['body']);

$result = haiku_chat(
    "You are a social media content specialist. Extract key points from a blog post to create a 5-slide Instagram carousel.
Output ONLY valid JSON with this structure:
{
  \"hook\": \"Attention-grabbing first slide text (max 15 words)\",
  \"slides\": [
    {\"heading\": \"Slide 2 heading (max 6 words)\", \"points\": [\"point 1\", \"point 2\", \"point 3\"]},
    {\"heading\": \"Slide 3 heading (max 6 words)\", \"points\": [\"point 1\", \"point 2\", \"point 3\"]},
    {\"heading\": \"Slide 4 heading (max 6 words)\", \"points\": [\"point 1\", \"point 2\", \"point 3\"]}
  ],
  \"cta\": \"Call to action text for last slide (max 10 words)\"
}
Keep each point under 12 words. Make them punchy and Instagram-friendly.",
    "Extract 5-slide carousel content from this blog post:\n\nTitle: {$post['title']}\n\nContent:\n" . mb_substr($content, 0, 3000),
    1024
);

if (!$result['success']) {
    echo "AI failed: {$result['error']}\n";
    // Fallback: manual extraction
    $slides_data = carousel_fallback($post['title'], $content);
} else {
    $parsed = $result['content'];
    $parsed = preg_replace('/^```(?:json)?\s*/m', '', $parsed);
    $parsed = preg_replace('/\s*```\s*$/m', '', $parsed);
    $slides_data = json_decode(trim($parsed), true);
    if (!$slides_data) {
        echo "Failed to parse AI response. Using fallback.\n";
        $slides_data = carousel_fallback($post['title'], $content);
    }
}

// Create output directory
if (!is_dir($output_dir)) {
    mkdir($output_dir, 0755, true);
}

$post_dir = $output_dir . '/' . $post['slug'];
if (!is_dir($post_dir)) {
    mkdir($post_dir, 0755, true);
}

// Generate HTML slides
$slides_html = [];

// Slide 1: Hook
$slides_html[] = carousel_slide_html(1, 'hook', [
    'text' => $slides_data['hook'] ?? $post['title'],
    'site_name' => $post['site_name'],
], $primary, $accent, $font);

// Slides 2-4: Content
foreach (($slides_data['slides'] ?? []) as $i => $slide) {
    $slides_html[] = carousel_slide_html($i + 2, 'content', [
        'heading' => $slide['heading'] ?? '',
        'points'  => $slide['points'] ?? [],
    ], $primary, $accent, $font);
}

// Slide 5: CTA
$slides_html[] = carousel_slide_html(5, 'cta', [
    'text' => $slides_data['cta'] ?? 'Read the full article',
    'site_name' => $post['site_name'],
    'domain' => $post['domain'],
], $primary, $accent, $font);

// Save slides
foreach ($slides_html as $i => $html) {
    $file = $post_dir . '/slide-' . ($i + 1) . '.html';
    file_put_contents($file, $html);
    echo "  Slide " . ($i + 1) . ": {$file}\n";
}

// Save combined preview
$slide_count = count($slides_html);
$preview = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Carousel Preview — {$post['site_name']}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#1a1a1a;font-family:-apple-system,sans-serif;color:#fff;padding:20px;}
h1{font-size:18px;text-align:center;margin-bottom:4px;}
.sub{text-align:center;color:#888;font-size:13px;margin-bottom:20px;}
.slides{display:flex;gap:16px;overflow-x:auto;padding:10px;justify-content:center;flex-wrap:wrap;}
.slide-wrap{width:320px;height:320px;border-radius:10px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.4);flex-shrink:0;position:relative;}
.slide-wrap iframe{width:1080px;height:1080px;border:none;transform:scale(0.296);transform-origin:top left;}
.slide-num{position:absolute;top:8px;left:8px;background:rgba(0,0,0,0.6);color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;z-index:2;}
.tip{text-align:center;color:#666;font-size:12px;margin-top:16px;}
</style></head><body>
<h1>Instagram Carousel — {$post['site_name']}</h1>
<div class='sub'>{$slide_count} slides · Right-click each slide → Save as Image</div>
<div class='slides'>\n";
foreach ($slides_html as $i => $html) {
    $num = $i + 1;
    $preview .= "<div class='slide-wrap'><span class='slide-num'>{$num}/{$slide_count}</span><iframe src='slide-{$num}.html'></iframe></div>\n";
}
$preview .= "</div>\n<div class='tip'>Tip: Open each slide-N.html individually and screenshot at 1080×1080 for Instagram.</div>\n</body></html>";
file_put_contents($post_dir . '/preview.html', $preview);

echo "\nDone! " . count($slides_html) . " slides generated.\n";
echo "Preview: {$post_dir}/preview.html\n";

// Log
$db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
    $post['site_id'], 'carousel',
    json_encode(['post_id' => $post_id, 'slides' => count($slides_html), 'path' => $post_dir]),
    'success',
]);

// ── Helpers ─────────────────────────────────────────────

function carousel_slide_html(int $num, string $type, array $data, string $primary, string $accent, string $font): string
{
    $bg = $type === 'cta' ? $accent : $primary;
    $text_color = '#ffffff';

    $inner = '';

    if ($type === 'hook') {
        $inner = "
            <div style='font-size:72px;font-weight:800;line-height:1.1;text-align:center;padding:0 80px;'>
                {$data['text']}
            </div>
            <div style='margin-top:50px;font-size:24px;opacity:0.7;text-transform:uppercase;letter-spacing:4px;'>
                {$data['site_name']}
            </div>
            <div style='position:absolute;bottom:50px;font-size:20px;opacity:0.5;'>Swipe →</div>";
    } elseif ($type === 'content') {
        $points_html = '';
        foreach ($data['points'] ?? [] as $p) {
            $points_html .= "<div style='display:flex;align-items:flex-start;gap:18px;margin-bottom:28px;'>
                <div style='width:12px;height:12px;background:{$accent};border-radius:50%;margin-top:12px;flex-shrink:0;'></div>
                <div style='font-size:36px;line-height:1.35;'>{$p}</div>
            </div>";
        }
        $inner = "
            <div style='width:100%;text-align:left;padding:0 80px;'>
                <div style='font-size:28px;text-transform:uppercase;letter-spacing:3px;opacity:0.5;margin-bottom:24px;'>{$num}/5</div>
                <div style='font-size:48px;font-weight:700;margin-bottom:40px;line-height:1.15;'>{$data['heading']}</div>
                {$points_html}
            </div>";
    } elseif ($type === 'cta') {
        $inner = "
            <div style='font-size:60px;font-weight:800;line-height:1.15;text-align:center;padding:0 80px;margin-bottom:40px;'>
                {$data['text']}
            </div>
            <div style='padding:18px 50px;border:3px solid white;border-radius:10px;font-size:30px;font-weight:600;'>
                {$data['domain']}
            </div>
            <div style='margin-top:36px;font-size:22px;opacity:0.7;'>
                {$data['site_name']}
            </div>";
    }

    return "<!DOCTYPE html>
<html><head><meta charset='UTF-8'>
<link href='https://fonts.googleapis.com/css2?family=" . urlencode($font) . ":wght@400;600;700;800&display=swap' rel='stylesheet'>
<style>*{margin:0;padding:0;box-sizing:border-box}</style>
</head>
<body style='width:1080px;height:1080px;background:{$bg};color:{$text_color};font-family:\"{$font}\",sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;position:relative;'>
{$inner}
</body></html>";
}

function carousel_fallback(string $title, string $content): array
{
    // Extract sentences for slides
    $sentences = preg_split('/[.!?]+/', $content, -1, PREG_SPLIT_NO_EMPTY);
    $sentences = array_filter(array_map('trim', $sentences));
    $sentences = array_values(array_filter($sentences, fn($s) => mb_strlen($s) > 20 && mb_strlen($s) < 100));

    return [
        'hook' => truncate($title, 60, ''),
        'slides' => [
            ['heading' => 'Key Insight #1', 'points' => array_slice($sentences, 0, 3)],
            ['heading' => 'Key Insight #2', 'points' => array_slice($sentences, 3, 3)],
            ['heading' => 'Key Insight #3', 'points' => array_slice($sentences, 6, 3)],
        ],
        'cta' => 'Read the full article',
    ];
}
