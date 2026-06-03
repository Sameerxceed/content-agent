<?php
/**
 * Per-channel scheduling — turn one publish date into a staggered schedule
 * across multiple distribution channels (blog Mon, LinkedIn Tue, newsletter Wed).
 *
 * Stored as a JSON column `sites.channel_offsets` keyed by channel id:
 *   {"cms": 0, "linkedin": 1, "newsletter": 2, "schema": 0, "llms": 0}
 *
 * Values are days from the post's target_publish_date. 0 = same day, +1 = next day.
 * Negative offsets supported for pre-publish teasers later.
 */

/** Default offsets used when a site hasn't customised. Standard B2B playbook. */
function channel_schedule_defaults(): array
{
    return [
        'cms'        => 0,
        'schema'     => 0, // fires with the blog body — no human delay
        'llms'       => 0, // index regeneration; same day
        'linkedin'   => 1, // mid-week professional engagement peak
        'twitter'    => 1,
        'newsletter' => 2, // mid-week open-rate sweet spot
    ];
}

/** Load offsets for a site, merged over defaults. */
function channel_schedule_get(array $site): array
{
    $defaults = channel_schedule_defaults();
    $raw = $site['channel_offsets'] ?? null;
    if (!$raw) return $defaults;
    $custom = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    if (!is_array($custom)) return $defaults;
    foreach ($custom as $k => $v) $defaults[$k] = (int)$v;
    return $defaults;
}

/**
 * Resolve the actual scheduled_for DATETIME for one (base_date, channel) pair.
 * base_date is the post's target_publish_date (Y-m-d). Returns DATETIME string.
 *
 * Publish time within the day uses midnight (00:00:00) by design — keeps
 * scheduling predictable and gives cron-publish room to fire early in the day.
 */
function channel_schedule_for(string $base_date, string $channel, array $offsets): string
{
    $days = isset($offsets[$channel]) ? (int)$offsets[$channel] : 0;
    $ts   = strtotime($base_date);
    if ($ts === false) $ts = time();
    if ($days !== 0) $ts = strtotime(($days > 0 ? '+' : '') . $days . ' days', $ts);
    return date('Y-m-d 00:00:00', $ts);
}
