-- Content Plan v1 — extends posts with new content types + hero image, and
-- adds per-site autopilot settings (autonomy mode + publishing cadence).

-- ── Extend posts.type enum ──
-- Adds the content shapes the planner produces: pillar pages (3000-5000w
-- hub content), comparison pages, guides, service pages, glossary entries.
-- blog + news (existing) remain the defaults.
ALTER TABLE `posts`
MODIFY COLUMN `type` ENUM('blog','news','pillar','comparison','guide','service_page','glossary')
    NOT NULL DEFAULT 'blog';

-- ── Hero image fields on posts ──
-- The autopilot full-package generator writes an image prompt; the image
-- backend (DALL-E 3, Unsplash fallback) renders it and stores URL + the
-- prompt for traceability + the provider that fulfilled it.
ALTER TABLE `posts`
ADD COLUMN `hero_image_url`      VARCHAR(1024) DEFAULT NULL
    COMMENT 'Public URL of the post hero image (AI-generated or stock)' AFTER `excerpt`,
ADD COLUMN `hero_image_prompt`   TEXT          DEFAULT NULL
    COMMENT 'The prompt Claude wrote that generated the image (audit trail)' AFTER `hero_image_url`,
ADD COLUMN `hero_image_provider` ENUM('dalle3','unsplash','manual','none') DEFAULT NULL
    COMMENT 'Which backend produced the hero image' AFTER `hero_image_prompt`,
ADD COLUMN `hero_image_alt`      VARCHAR(500)  DEFAULT NULL
    COMMENT 'Accessibility alt text (also fills og:image:alt)' AFTER `hero_image_provider`;

-- ── Per-site autopilot settings ──
-- autonomy_mode is per-site so an agency can run some clients on review-each
-- and others on hands-off later. v1 ships only 'review' as functional; the
-- other values exist for v2 wiring.
ALTER TABLE `sites`
ADD COLUMN `autonomy_mode` ENUM('review','hands_off','manual') NOT NULL DEFAULT 'review'
    COMMENT 'Plan execution mode: review (default) / hands_off (v2) / manual',
ADD COLUMN `posts_per_week` TINYINT UNSIGNED NOT NULL DEFAULT 2
    COMMENT 'Publishing cadence target for content plan generation';
