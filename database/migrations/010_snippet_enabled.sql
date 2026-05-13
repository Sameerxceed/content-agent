-- Master kill switch for the SEO snippet.
-- When 0, the seo-data.php API returns empty for the site, so the snippet
-- on the live website cannot inject ANYTHING — no titles, no schema, no OG, nothing.
-- Default: 0 (OFF) — customer must explicitly opt in per site.

ALTER TABLE `sites`
ADD COLUMN `snippet_enabled` TINYINT(1) NOT NULL DEFAULT 0
COMMENT 'Master switch: 0 = snippet API returns nothing, 1 = active (subject to snippet_mode)'
AFTER `snippet_mode`;

-- Existing sites: keep OFF by default (safer to make user opt-in than to assume consent)
UPDATE `sites` SET `snippet_enabled` = 0;
