-- Global super-admin flag — distinct from the team-relative users.role enum.
-- When 1, the user sees and operates on every site in the system regardless of
-- ownership. Used for the agency/managed-service model.

ALTER TABLE `users`
    ADD COLUMN `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'God mode: sees and operates on every site regardless of ownership';

-- Bootstrap the first super-admin (Sameer / user_id = 1)
UPDATE `users` SET `is_super_admin` = 1 WHERE id = 1;
