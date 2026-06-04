-- Free-form per-site JSON notes column. Used for now by IndexNow key storage;
-- a sane catch-all for small per-site key-value state that doesn't justify
-- its own column (e.g. webhook secrets, third-party verification tokens, etc).

ALTER TABLE `sites`
    ADD COLUMN `notes` LONGTEXT DEFAULT NULL
        COMMENT 'JSON key-value store for site-scoped misc state (IndexNow key, future webhook secrets, etc.)'
        AFTER `channel_offsets`;
