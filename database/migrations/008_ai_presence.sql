-- AI Presence: stores discovered conversations and generated replies
CREATE TABLE IF NOT EXISTS `ai_presence_content` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int unsigned NOT NULL,
  `platform` varchar(50) NOT NULL,
  `source_url` varchar(1000) DEFAULT NULL,
  `source_title` varchar(500) DEFAULT NULL,
  `source_content` text DEFAULT NULL,
  `reply_content` text DEFAULT NULL,
  `status` enum('found','reply_drafted','posted','skipped') NOT NULL DEFAULT 'found',
  `posted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_site` (`site_id`),
  KEY `idx_platform` (`platform`),
  CONSTRAINT `fk_presence_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
