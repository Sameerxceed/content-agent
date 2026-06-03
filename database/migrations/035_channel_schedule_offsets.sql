-- Per-channel publish offsets so a single post can stagger across days.
-- Standard B2B playbook: blog Mon, LinkedIn Tue, Newsletter Wed → more touches
-- across same audience without burning them. Compressing all into one day
-- wastes reach.
--
-- The autopilot uses these defaults when setting post_channels.scheduled_for.
-- Users can override per post on the plan-item page.

ALTER TABLE `sites`
    ADD COLUMN `channel_offsets` JSON DEFAULT NULL
        COMMENT 'Per-channel publish offsets in days from target_publish_date, e.g. {"cms":0,"linkedin":1,"newsletter":2}'
        AFTER `default_channels`;
