-- Extend seo_issues so external sources (GSC emails, pasted alerts, GSC API)
-- can register issues alongside the auto-audit pipeline.
--
-- 1. Make audit_id nullable so issues can exist without belonging to an audit run.
-- 2. Widen the type ENUM to cover Google Search Console issue codes.
-- 3. Add a source column so we know where each issue came from.

ALTER TABLE `seo_issues`
    MODIFY `audit_id` INT UNSIGNED NULL,
    MODIFY `type` ENUM(
        'broken_link','missing_meta','missing_schema',
        'missing_sitemap','missing_robots','redirect_chain',
        'missing_alt','ssl_error','missing_canonical',
        'missing_og','auth_error','speed_issue',
        'duplicate_meta','mobile_issue',
        -- new external/GSC types
        'not_found_404','noindex_blocked','duplicate_no_canonical',
        'soft_404','blocked_by_robots','crawled_not_indexed',
        'discovered_not_indexed','server_error_5xx','redirect_error',
        'mobile_usability','core_web_vitals','other_external'
    ) NOT NULL,
    ADD COLUMN `source` VARCHAR(40) NOT NULL DEFAULT 'auto_audit'
        COMMENT 'auto_audit / pasted_alert / gsc_api / manual' AFTER `audit_id`;

CREATE INDEX `idx_seo_issues_source` ON `seo_issues` (`source`);
