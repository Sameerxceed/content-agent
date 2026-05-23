-- Rich business profile, inferred by Claude during scan and confirmed by the user.
-- Becomes the single source of truth every downstream agent reads from
-- (competitor discovery, blog writer, keyword research, AEO suggester,
-- Brand Presence search, news scraper, schema generator).
--
-- Before this, the scan only captured site metadata (platform, brand colors,
-- blog path) — leaving every agent to treat every customer the same. The visible
-- symptom: Infosys showing up as a "competitor" for a 15-person boutique.

ALTER TABLE `sites`
    ADD COLUMN `founding_year`        SMALLINT UNSIGNED NULL                  AFTER `usp`,
    ADD COLUMN `hq_city`              VARCHAR(80)       NULL                  AFTER `founding_year`,
    ADD COLUMN `hq_country`           VARCHAR(80)       NULL                  AFTER `hq_city`,
    ADD COLUMN `size_tier`            ENUM('solo','small','mid','large','enterprise') NULL
                                      COMMENT 'solo=1, small=2-10, mid=11-50, large=51-500, enterprise=500+'
                                                                              AFTER `hq_country`,
    ADD COLUMN `employee_estimate`    SMALLINT UNSIGNED NULL                  AFTER `size_tier`,
    ADD COLUMN `business_model`       ENUM('b2b','b2c','b2b2c','nonprofit','marketplace') NULL
                                                                              AFTER `employee_estimate`,
    ADD COLUMN `offering_type`        ENUM('service','product','hybrid') NULL AFTER `business_model`,
    ADD COLUMN `industry_category`    VARCHAR(80)       NULL
                                      COMMENT 'e.g. Tech consulting, SaaS, E-commerce, Agency'
                                                                              AFTER `offering_type`,
    ADD COLUMN `industry_sub`         VARCHAR(120)      NULL
                                      COMMENT 'e.g. AI/ML, Document AI, DevOps'
                                                                              AFTER `industry_category`,
    ADD COLUMN `customer_segment`     ENUM('consumer','smb','midmarket','enterprise','mixed') NULL
                                                                              AFTER `industry_sub`,
    ADD COLUMN `market_scope`         ENUM('local','regional','national','global') NULL
                                                                              AFTER `customer_segment`,
    ADD COLUMN `maturity_tier`        ENUM('bootstrapped','established','category_leader','public_company') NULL
                                                                              AFTER `market_scope`,
    ADD COLUMN `profile_confidence`   JSON              NULL
                                      COMMENT 'per-field 0-1 from the inference call'
                                                                              AFTER `maturity_tier`,
    ADD COLUMN `profile_signals`      JSON              NULL
                                      COMMENT 'quotes/clues Claude used per field; surfaced as "why we think this"'
                                                                              AFTER `profile_confidence`,
    ADD COLUMN `profile_inferred_at`  DATETIME          NULL                  AFTER `profile_signals`,
    ADD COLUMN `profile_confirmed`    TINYINT(1) NOT NULL DEFAULT 0
                                      COMMENT 'true once the user has reviewed the inferred fields'
                                                                              AFTER `profile_inferred_at`;
