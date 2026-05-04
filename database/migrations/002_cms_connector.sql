-- Add CMS connector fields to sites table
ALTER TABLE sites
    ADD COLUMN cms_url VARCHAR(500) DEFAULT NULL AFTER rss_feeds,
    ADD COLUMN cms_api_key VARCHAR(255) DEFAULT NULL AFTER cms_url;
