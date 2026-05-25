-- Keyword Intelligence — richer fields for opportunity-driven research
--
-- The classic keyword research pass found names. This adds the signals that
-- decide what to DO with each one: buyer intent, opportunity score, and a
-- recommended action so the dashboard can bucket them like Ubersuggest, only
-- with real "go work on this" calls instead of a flat list.
--
-- intent             — Claude-classified buyer intent
-- buyer_question     — what a real user typing this is actually asking
-- cpc                — paid-search CPC from DataForSEO (a proxy for commercial value)
-- opportunity_score  — 0-100, computed from volume × intent × difficulty × current rank
-- recommended_action — which bucket this should land in on the dashboard
-- keyword_type       — surface-level shape (question / comparison / long-tail / etc.)
-- metrics_refreshed_at — when search_volume / difficulty / cpc were last pulled

ALTER TABLE `keywords`
ADD COLUMN `intent` ENUM('informational','commercial','transactional','navigational','unknown')
    NOT NULL DEFAULT 'unknown'
    COMMENT 'Claude-classified buyer intent' AFTER `cluster`,
ADD COLUMN `buyer_question` TEXT DEFAULT NULL
    COMMENT 'What is the user really asking when they type this?' AFTER `intent`,
ADD COLUMN `cpc` DECIMAL(8,2) DEFAULT NULL
    COMMENT 'Top-of-page bid (USD) from DataForSEO' AFTER `difficulty`,
ADD COLUMN `opportunity_score` TINYINT UNSIGNED DEFAULT NULL
    COMMENT '0-100, computed from volume × intent × difficulty × current rank' AFTER `priority`,
ADD COLUMN `recommended_action` ENUM('quick_win','new_content','aeo_gap','watch','skip') DEFAULT NULL
    COMMENT 'Which bucket to surface this keyword in' AFTER `opportunity_score`,
ADD COLUMN `keyword_type` ENUM('seed','head','long_tail','question','comparison','geo','related','autocomplete') DEFAULT NULL
    COMMENT 'Surface-level shape; drives bucket grouping' AFTER `recommended_action`,
ADD COLUMN `metrics_refreshed_at` DATETIME DEFAULT NULL
    COMMENT 'Last time volume / difficulty / cpc were pulled from DataForSEO' AFTER `last_checked`,
ADD INDEX `idx_keywords_action` (`site_id`, `recommended_action`, `opportunity_score`),
ADD INDEX `idx_keywords_intent` (`site_id`, `intent`);

-- Expand the source enum so DataForSEO-ideas-sourced rows are distinguishable
-- from raw autocomplete. Existing rows keep their value.
ALTER TABLE `keywords`
MODIFY COLUMN `source` ENUM('autocomplete','paa','gsc','manual','dataforseo_ideas','dataforseo_suggestions','competitor')
    NOT NULL DEFAULT 'autocomplete';
