-- Let users mark keywords as "ignored" so AI skips them in content planning.
-- e.g. brand-collision searches (someone else's "Xceed Technologies"), off-topic typos, etc.
--
-- Also track where each keyword came from:
--   autocomplete  - Google autocomplete (AI research agent)
--   paa           - People Also Ask
--   gsc           - Google Search Console real data
--   manual        - User typed it in (e.g. "xceed imagination")

ALTER TABLE `keywords`
ADD COLUMN `status` ENUM('active', 'ignored') NOT NULL DEFAULT 'active' COMMENT 'ignored = excluded from AI suggestions' AFTER `priority`,
ADD COLUMN `ignored_reason` VARCHAR(255) DEFAULT NULL COMMENT 'Why this was ignored (optional)' AFTER `status`,
ADD COLUMN `source` ENUM('autocomplete', 'paa', 'gsc', 'manual') NOT NULL DEFAULT 'autocomplete' AFTER `ignored_reason`,
ADD INDEX `idx_keywords_status` (`site_id`, `status`);
