-- Add theme_name column to sites table for platform-aware fix generation
ALTER TABLE sites ADD COLUMN theme_name VARCHAR(100) DEFAULT NULL AFTER platform;
