-- Multi-user / Agency support

CREATE TABLE IF NOT EXISTS `teams` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(255) NOT NULL,
    `owner_id`   INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_teams_owner` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `team_members` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `team_id`    INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NOT NULL,
    `role`       ENUM('admin','editor','viewer') NOT NULL DEFAULT 'viewer',
    `invited_by` INT UNSIGNED DEFAULT NULL,
    `joined_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_team_user` (`team_id`, `user_id`),
    CONSTRAINT `fk_tm_team` FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tm_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invitations` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `team_id`     INT UNSIGNED NOT NULL,
    `email`       VARCHAR(255) NOT NULL,
    `role`        ENUM('admin','editor','viewer') NOT NULL DEFAULT 'editor',
    `token`       VARCHAR(64) NOT NULL,
    `invited_by`  INT UNSIGNED NOT NULL,
    `accepted_at` DATETIME DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`  DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_invite_token` (`token`),
    CONSTRAINT `fk_inv_team` FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add team reference to sites
ALTER TABLE `sites` ADD COLUMN `team_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`;

-- Add role to users
ALTER TABLE `users` ADD COLUMN `role` ENUM('owner','admin','editor','viewer') NOT NULL DEFAULT 'owner' AFTER `plan`;
