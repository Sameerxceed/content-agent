-- Add snippet_mode to sites table to control whether the SEO snippet
-- can override existing tags or only fill in missing ones.
-- Default: 'fill_only' (safe) — never replaces what the site already has.

ALTER TABLE `sites`
ADD COLUMN `snippet_mode` ENUM('fill_only', 'override') NOT NULL DEFAULT 'fill_only'
COMMENT 'fill_only = snippet only adds missing tags; override = snippet can replace existing title/desc'
AFTER `cms_api_key`;
