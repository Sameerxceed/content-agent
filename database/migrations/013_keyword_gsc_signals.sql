-- Real Google Search Console signals on keywords.
-- These replace the AI-guessed numbers with actual data from Google.
--
-- - impressions:  how many times this keyword was seen in Google search results (over the sync window)
-- - clicks:       how many times someone clicked through to the site
-- - ctr:          click-through rate (0.00 - 100.00, percent)
-- - gsc_position: average rank (1.0 = top, 50.5 = page 5-ish) — more precise than current_rank
-- - gsc_synced_at: when this row was last refreshed from GSC

ALTER TABLE `keywords`
ADD COLUMN `impressions` INT UNSIGNED DEFAULT NULL COMMENT 'Real impressions from GSC' AFTER `current_rank`,
ADD COLUMN `clicks` INT UNSIGNED DEFAULT NULL COMMENT 'Real clicks from GSC' AFTER `impressions`,
ADD COLUMN `ctr` DECIMAL(5,2) DEFAULT NULL COMMENT 'Click-through rate %, from GSC' AFTER `clicks`,
ADD COLUMN `gsc_position` DECIMAL(5,1) DEFAULT NULL COMMENT 'Precise avg position from GSC' AFTER `ctr`,
ADD COLUMN `gsc_synced_at` DATETIME DEFAULT NULL AFTER `gsc_position`;
