-- Capture the customer's own words about their business so AI grounds in truth, not guesses.
-- - business_description: free-text, customer-written ("we sell handwoven silk scarves...")
-- - topics_confirmed: 1 when customer has explicitly reviewed/saved topics (gates keyword research and content writing)

ALTER TABLE `sites`
ADD COLUMN `business_description` TEXT DEFAULT NULL COMMENT 'Customer-written description of what they sell/do' AFTER `topics`,
ADD COLUMN `topics_confirmed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = customer confirmed topics, ok to run AI; 0 = AI cannot run' AFTER `business_description`;
