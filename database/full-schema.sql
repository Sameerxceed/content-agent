-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: contentagent
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `agent_log`
--

DROP TABLE IF EXISTS `agent_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL COMMENT 'scan, write_blog, scrape_news, seo_audit, etc.',
  `details` text DEFAULT NULL,
  `status` enum('success','fail','skipped') NOT NULL DEFAULT 'success',
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_agentlog_site` (`site_id`),
  KEY `idx_agentlog_action` (`action`),
  KEY `idx_agentlog_created` (`created_at`),
  CONSTRAINT `fk_agentlog_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `billing`
--

DROP TABLE IF EXISTS `billing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `stripe_customer_id` varchar(255) DEFAULT NULL,
  `stripe_subscription_id` varchar(255) DEFAULT NULL,
  `plan` enum('free','starter','growth','agency') NOT NULL DEFAULT 'free',
  `status` enum('active','canceled','past_due','trialing') NOT NULL DEFAULT 'trialing',
  `trial_ends_at` datetime DEFAULT NULL,
  `current_period_end` datetime DEFAULT NULL,
  `sites_limit` int(10) unsigned NOT NULL DEFAULT 1,
  `posts_limit` int(10) unsigned NOT NULL DEFAULT 8,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_billing_user` (`user_id`),
  KEY `idx_billing_stripe` (`stripe_customer_id`),
  CONSTRAINT `fk_billing_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `billing_events`
--

DROP TABLE IF EXISTS `billing_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `stripe_event_id` varchar(255) DEFAULT NULL,
  `amount_cents` int(11) DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'usd',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_be_user` (`user_id`),
  CONSTRAINT `fk_be_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `integrations`
--

DROP TABLE IF EXISTS `integrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `integrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned NOT NULL,
  `platform` varchar(50) NOT NULL COMMENT 'google_search_console, instagram, linkedin, twitter, facebook',
  `access_token` text DEFAULT NULL,
  `refresh_token` text DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `account_id` varchar(255) DEFAULT NULL,
  `account_name` varchar(255) DEFAULT NULL,
  `extra_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extra_data`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `connected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration` (`site_id`,`platform`),
  CONSTRAINT `fk_integration_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invitations`
--

DROP TABLE IF EXISTS `invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invitations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int(10) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` enum('admin','editor','viewer') NOT NULL DEFAULT 'editor',
  `token` varchar(64) NOT NULL,
  `invited_by` int(10) unsigned NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invite_token` (`token`),
  KEY `fk_inv_team` (`team_id`),
  CONSTRAINT `fk_inv_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `keywords`
--

DROP TABLE IF EXISTS `keywords`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `keywords` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned NOT NULL,
  `keyword` varchar(255) NOT NULL,
  `search_volume` int(10) unsigned DEFAULT NULL,
  `difficulty` tinyint(3) unsigned DEFAULT NULL COMMENT '0-100 score',
  `current_rank` smallint(5) unsigned DEFAULT NULL,
  `target_rank` smallint(5) unsigned DEFAULT NULL,
  `cluster` varchar(100) DEFAULT NULL COMMENT 'topic cluster name',
  `priority` tinyint(3) unsigned NOT NULL DEFAULT 50 COMMENT '0-100',
  `last_checked` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keywords_site_kw` (`site_id`,`keyword`(191)),
  KEY `idx_keywords_site` (`site_id`),
  KEY `idx_keywords_priority` (`priority`),
  CONSTRAINT `fk_keywords_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsletters`
--

DROP TABLE IF EXISTS `newsletters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsletters` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned NOT NULL,
  `subject` varchar(500) NOT NULL,
  `body` longtext NOT NULL,
  `status` enum('draft','sent') NOT NULL DEFAULT 'draft',
  `sent_count` int(10) unsigned DEFAULT 0,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nl_site` (`site_id`),
  CONSTRAINT `fk_nl_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `page_seo`
--

DROP TABLE IF EXISTS `page_seo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `page_seo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned NOT NULL,
  `url_path` varchar(500) NOT NULL COMMENT 'e.g. /services, /blog/my-post',
  `canonical` varchar(2048) DEFAULT NULL,
  `meta_title` varchar(100) DEFAULT NULL,
  `meta_description` varchar(200) DEFAULT NULL,
  `og_title` varchar(100) DEFAULT NULL,
  `og_description` varchar(200) DEFAULT NULL,
  `og_image` varchar(2048) DEFAULT NULL,
  `schema_json` longtext DEFAULT NULL COMMENT 'JSON-LD markup',
  `extra_head` text DEFAULT NULL COMMENT 'Any extra HTML for <head>',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pageseo_path` (`site_id`,`url_path`(191)),
  CONSTRAINT `fk_pageseo_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=304 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `posts`
--

DROP TABLE IF EXISTS `posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `posts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned NOT NULL,
  `title` varchar(500) NOT NULL,
  `slug` varchar(500) NOT NULL,
  `body` longtext NOT NULL,
  `excerpt` text DEFAULT NULL,
  `seo_title` varchar(70) DEFAULT NULL,
  `seo_description` varchar(170) DEFAULT NULL,
  `seo_keywords` varchar(500) DEFAULT NULL,
  `type` enum('blog','news') NOT NULL DEFAULT 'blog',
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `status` enum('draft','approved','published','rejected') NOT NULL DEFAULT 'draft',
  `source_url` varchar(2048) DEFAULT NULL COMMENT 'original URL for news articles',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `published_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_posts_site_slug` (`site_id`,`slug`(191)),
  KEY `idx_posts_site` (`site_id`),
  KEY `idx_posts_status` (`status`),
  KEY `idx_posts_type` (`type`),
  KEY `idx_posts_published` (`published_at`),
  CONSTRAINT `fk_posts_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `redirects`
--

DROP TABLE IF EXISTS `redirects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `redirects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned NOT NULL,
  `from_path` varchar(500) NOT NULL,
  `to_url` varchar(2048) NOT NULL,
  `type` smallint(6) NOT NULL DEFAULT 301,
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_redirect_path` (`site_id`,`from_path`(191)),
  CONSTRAINT `fk_redirect_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `seo_audits`
--

DROP TABLE IF EXISTS `seo_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seo_audits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned NOT NULL,
  `score` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT '0-100 health score',
  `total_issues` smallint(5) unsigned NOT NULL DEFAULT 0,
  `critical` smallint(5) unsigned NOT NULL DEFAULT 0,
  `warnings` smallint(5) unsigned NOT NULL DEFAULT 0,
  `passed` smallint(5) unsigned NOT NULL DEFAULT 0,
  `pages_crawled` smallint(5) unsigned NOT NULL DEFAULT 0,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `run_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audits_site` (`site_id`),
  KEY `idx_audits_run` (`run_at`),
  CONSTRAINT `fk_audits_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `seo_issues`
--

DROP TABLE IF EXISTS `seo_issues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seo_issues` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `audit_id` int(10) unsigned NOT NULL,
  `site_id` int(10) unsigned NOT NULL,
  `type` enum('broken_link','missing_meta','missing_schema','missing_sitemap','missing_robots','redirect_chain','missing_alt','ssl_error','missing_canonical','missing_og','auth_error','speed_issue','duplicate_meta','mobile_issue') NOT NULL,
  `severity` enum('critical','warning','info') NOT NULL DEFAULT 'warning',
  `url` varchar(2048) NOT NULL,
  `description` text NOT NULL,
  `suggested_fix` text DEFAULT NULL,
  `status` enum('open','fix_proposed','fix_applied','resolved','ignored') NOT NULL DEFAULT 'open',
  `fixed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_issues_audit` (`audit_id`),
  KEY `idx_issues_site` (`site_id`),
  KEY `idx_issues_type` (`type`),
  KEY `idx_issues_severity` (`severity`),
  KEY `idx_issues_status` (`status`),
  CONSTRAINT `fk_issues_audit` FOREIGN KEY (`audit_id`) REFERENCES `seo_audits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_issues_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=741 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sites`
--

DROP TABLE IF EXISTS `sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `domain` varchar(255) NOT NULL,
  `platform` varchar(50) DEFAULT NULL COMMENT 'wordpress, shopify, opencart, custom',
  `brand_colors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`brand_colors`)),
  `brand_fonts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`brand_fonts`)),
  `brand_tone` text DEFAULT NULL,
  `topics` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`topics`)),
  `keywords` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`keywords`)),
  `agent_mode` enum('auto','manual') NOT NULL DEFAULT 'manual',
  `blog_path` varchar(100) NOT NULL DEFAULT '/blog',
  `rss_feeds` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rss_feeds`)),
  `cms_url` varchar(500) DEFAULT NULL,
  `cms_api_key` varchar(255) DEFAULT NULL,
  `server_type` varchar(20) DEFAULT 'api_only',
  `server_host` varchar(255) DEFAULT NULL,
  `server_user` varchar(255) DEFAULT NULL,
  `server_pass` varchar(255) DEFAULT NULL,
  `server_path` varchar(500) DEFAULT NULL,
  `git_repo` varchar(500) DEFAULT NULL,
  `hosting_panel` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `scanned_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sites_user` (`user_id`),
  KEY `idx_sites_domain` (`domain`),
  CONSTRAINT `fk_sites_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `social_posts`
--

DROP TABLE IF EXISTS `social_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `social_posts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL,
  `site_id` int(10) unsigned NOT NULL,
  `platform` enum('linkedin','twitter','facebook','instagram') NOT NULL,
  `content` text NOT NULL,
  `status` enum('draft','scheduled','posted','failed') NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_social_post` (`post_id`),
  KEY `idx_social_site` (`site_id`),
  KEY `idx_social_status` (`status`),
  CONSTRAINT `fk_social_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_social_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `subscribers`
--

DROP TABLE IF EXISTS `subscribers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscribers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `status` enum('active','unsubscribed','bounced') NOT NULL DEFAULT 'active',
  `token` varchar(64) NOT NULL,
  `subscribed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `unsubscribed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sub_site_email` (`site_id`,`email`),
  KEY `idx_sub_status` (`status`),
  CONSTRAINT `fk_sub_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team_members`
--

DROP TABLE IF EXISTS `team_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `role` enum('admin','editor','viewer') NOT NULL DEFAULT 'viewer',
  `invited_by` int(10) unsigned DEFAULT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_team_user` (`team_id`,`user_id`),
  KEY `fk_tm_user` (`user_id`),
  CONSTRAINT `fk_tm_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `teams`
--

DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teams` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `owner_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_teams_owner` (`owner_id`),
  CONSTRAINT `fk_teams_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `plan` enum('starter','growth','agency') NOT NULL DEFAULT 'starter',
  `role` enum('owner','admin','editor','viewer') NOT NULL DEFAULT 'owner',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-04 14:26:24
