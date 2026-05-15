-- Per-site digest recipient — when set, weekly digest goes here instead of the owner user's email.
-- Lets the owner keep admin@xceedtech.in as the login but route reports to a different inbox.

ALTER TABLE `sites`
    ADD COLUMN `digest_email` VARCHAR(255) NULL
    COMMENT 'Optional override: weekly digest sends here instead of the owner user.email'
    AFTER `email_digest`;
