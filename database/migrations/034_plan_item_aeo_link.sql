-- Link plan items back to the AEO query they were created to win.
-- Lets us measure "did writing this post actually move the citation needle?"
-- and surfaces an "in progress" state on the AEO Tracker for queries that
-- already have winning content queued.

ALTER TABLE `content_plan_items`
    ADD COLUMN `target_aeo_query_id` INT UNSIGNED DEFAULT NULL
        COMMENT 'AEO query this item was written to win — set by "Write content to win this query" action'
        AFTER `secondary_keyword_ids`,
    ADD KEY `idx_target_aeo` (`target_aeo_query_id`),
    ADD CONSTRAINT `fk_item_aeo_query`
        FOREIGN KEY (`target_aeo_query_id`) REFERENCES `aeo_queries`(`id`) ON DELETE SET NULL;
