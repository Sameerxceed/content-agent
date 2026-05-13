-- Phase 2: SERP Brief
--
-- For each keyword, store an AI-generated content brief based on what's currently
-- ranking in Google's top 10. The AI writer reads this to model new posts on
-- the format/length/intent that's actually winning.

ALTER TABLE `keywords`
ADD COLUMN `serp_brief` JSON DEFAULT NULL COMMENT 'Claude-extracted summary of what ranks for this keyword'  AFTER `cluster`,
ADD COLUMN `serp_briefed_at` DATETIME DEFAULT NULL COMMENT 'When serp_brief was last refreshed' AFTER `serp_brief`;
