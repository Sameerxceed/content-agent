<?php
/**
 * Image SEO auditor.
 *
 * Walks every published post on a site, extracts each <img> tag, grades
 * it on alt text + dimensions + file size, and writes one row to
 * image_audits per image.
 *
 * Status taxonomy (in severity order — first match wins):
 *   broken     — HEAD returns 4xx/5xx. Image is gone.
 *   needs_alt  — alt attribute missing or empty.
 *   weak_alt   — alt is just the filename, "image", "img", "photo",
 *                a single word, or under 4 chars. Doesn't help SEO or
 *                accessibility.
 *   oversized  — file > 500KB. Tanks LCP, especially on mobile.
 *   no_dims    — width or height attribute missing. Causes CLS.
 *   good       — passes everything.
 */

require_once __DIR__ . '/helpers.php';

const IMG_AUDIT_MAX_BYTES = 500_000; // 500KB cap before flagging as oversized
const IMG_AUDIT_HEAD_TIMEOUT = 8;

/**
 * Extract all <img> tags from a body of HTML. Returns array of
 *   { src, alt, width, height } — width/height as ints or null.
 */
function img_audit_extract(string $html): array
{
    if ($html === '') return [];
    $out = [];
    if (!preg_match_all('/<img\b([^>]*)>/i', $html, $matches)) return [];
    foreach ($matches[1] as $attrs) {
        $src    = null; $alt = null; $w = null; $h = null;
        if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $m))    $src = trim($m[1]);
        if (preg_match('/\balt\s*=\s*["\']([^"\']*)["\']/i', $attrs, $m))     $alt = $m[1];
        if (preg_match('/\bwidth\s*=\s*["\']?(\d+)/i', $attrs, $m))           $w   = (int)$m[1];
        if (preg_match('/\bheight\s*=\s*["\']?(\d+)/i', $attrs, $m))          $h   = (int)$m[1];
        if (!$src) continue;
        $out[] = ['src' => $src, 'alt' => $alt, 'width' => $w, 'height' => $h];
    }
    return $out;
}

/**
 * Resolve a possibly-relative image URL against the post's base URL.
 */
function img_audit_resolve_url(string $src, string $base): string
{
    if (preg_match('#^https?://#i', $src)) return $src;
    if (str_starts_with($src, '//'))      return 'https:' . $src;
    if (str_starts_with($src, '/'))       return rtrim($base, '/') . $src;
    return rtrim($base, '/') . '/' . $src;
}

/**
 * HEAD the image — get Content-Length + status. Lightweight, no body fetch.
 */
function img_audit_head(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => IMG_AUDIT_HEAD_TIMEOUT,
        CURLOPT_USERAGENT      => 'ContentAgent-ImageAuditor/1.0',
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $bytes = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
    return ['code' => $code, 'bytes' => $bytes];
}

/**
 * Heuristic: is this alt text actually useful?
 */
function img_audit_alt_weak(?string $alt, string $src): bool
{
    if ($alt === null) return true;
    $a = trim($alt);
    if ($a === '' || mb_strlen($a) < 4)              return true;
    $low = strtolower($a);
    if (in_array($low, ['image', 'img', 'photo', 'picture', 'graphic'], true)) return true;
    // alt is just the basename of the src
    $base = strtolower(pathinfo(parse_url($src, PHP_URL_PATH) ?: '', PATHINFO_FILENAME));
    if ($base !== '' && $low === $base) return true;
    return false;
}

/**
 * Classify one image's status. Returns ['status', 'notes'].
 */
function img_audit_classify(?string $alt, ?int $w, ?int $h, string $src, array $head): array
{
    if ($head['code'] >= 400) return ['broken', "HTTP {$head['code']}"];
    if ($alt === null || trim((string)$alt) === '') return ['needs_alt', 'no alt attribute'];
    if (img_audit_alt_weak($alt, $src)) return ['weak_alt', 'alt is generic, single-word, or filename'];
    if ($head['bytes'] > IMG_AUDIT_MAX_BYTES) {
        return ['oversized', 'file ' . round($head['bytes'] / 1024) . 'KB (>500KB)'];
    }
    if ($w === null || $h === null) return ['no_dims', 'width/height attributes missing — causes CLS'];
    return ['good', null];
}

/**
 * Audit every published post on a site. Idempotent — re-running updates
 * the per-image row, preserving dismissed_at.
 *
 * @return array { posts_scanned, images_found, by_status: [...] }
 */
function img_audit_site(PDO $db, int $site_id, ?callable $progress = null): array
{
    $stmt = $db->prepare("SELECT id, title, body, slug FROM posts
        WHERE site_id = ? AND status = 'published' ORDER BY id DESC");
    $stmt->execute([$site_id]);
    $posts = $stmt->fetchAll();

    // Build base URL from site
    $sstmt = $db->prepare("SELECT domain, blog_path FROM sites WHERE id = ?");
    $sstmt->execute([$site_id]);
    $site = $sstmt->fetch() ?: ['domain' => '', 'blog_path' => '/blog'];
    $base = 'https://' . ltrim((string)$site['domain'], 'https://');

    $upsert = $db->prepare("INSERT INTO image_audits
        (site_id, post_id, image_url, image_url_hash, alt_text, width, height,
         file_bytes, status, issue_notes, last_audited_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            post_id = VALUES(post_id),
            alt_text = VALUES(alt_text),
            width = VALUES(width),
            height = VALUES(height),
            file_bytes = VALUES(file_bytes),
            status = VALUES(status),
            issue_notes = VALUES(issue_notes),
            last_audited_at = NOW()");

    $by_status = ['good' => 0, 'needs_alt' => 0, 'weak_alt' => 0, 'no_dims' => 0, 'oversized' => 0, 'broken' => 0];
    $images_found = 0; $i = 0;

    foreach ($posts as $post) {
        $i++;
        $imgs = img_audit_extract((string)$post['body']);
        foreach ($imgs as $img) {
            $url = img_audit_resolve_url($img['src'], $base);
            $head = img_audit_head($url);
            [$status, $notes] = img_audit_classify($img['alt'], $img['width'], $img['height'], $url, $head);
            $upsert->execute([
                $site_id, (int)$post['id'], $url, sha1($url),
                $img['alt'], $img['width'], $img['height'],
                $head['bytes'] ?: null,
                $status, $notes,
            ]);
            $by_status[$status]++;
            $images_found++;
        }
        if ($progress) $progress(['posts' => $i, 'total_posts' => count($posts), 'images' => $images_found]);
    }

    return [
        'posts_scanned' => count($posts),
        'images_found'  => $images_found,
        'by_status'     => $by_status,
    ];
}

function img_audit_site_summary(PDO $db, int $site_id): array
{
    $stmt = $db->prepare("SELECT status, COUNT(*) AS cnt
        FROM image_audits WHERE site_id = ? AND dismissed_at IS NULL
        GROUP BY status");
    $stmt->execute([$site_id]);
    $by_status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'by_status' => $by_status,
        'total'     => (int)array_sum($by_status),
    ];
}
