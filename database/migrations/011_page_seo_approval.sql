-- Approval workflow for page_seo.
--
-- Rule: brand-facing SEO fields (title, descriptions, OG title/desc, OG image)
-- need explicit approval before they're served by the snippet.
-- Auto-tier fields (canonical, schema, extra_head) are served as soon as they exist.
--
-- status:
--   pending   = proposed by ContentAgent, waiting for customer approval
--   approved  = customer approved, snippet may serve brand-facing fields
--   rejected  = customer rejected, snippet won't serve brand-facing fields
--
-- content_hash: hash of the page's visible content at time of last scan.
--   When this changes on a re-scan, a new proposal is generated (and previous
--   approved version keeps serving until the new one is approved too).

ALTER TABLE `page_seo`
ADD COLUMN `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER `extra_head`,
ADD COLUMN `content_hash` VARCHAR(64) DEFAULT NULL COMMENT 'SHA-256 of page content at scan time' AFTER `status`,
ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL AFTER `content_hash`,
ADD COLUMN `reviewed_by` INT UNSIGNED DEFAULT NULL AFTER `reviewed_at`,
ADD INDEX `idx_pageseo_status` (`site_id`, `status`);

-- Grandfather: existing rows are treated as pending (require review before going live).
-- Snippet is OFF for all sites anyway, so nothing is currently served.
UPDATE `page_seo` SET `status` = 'pending' WHERE `status` IS NULL OR `status` = '';
