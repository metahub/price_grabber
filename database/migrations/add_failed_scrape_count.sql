-- Migration: Add consecutive_failed_scrapes counter to products table
-- Date: 2025-10-22
-- Purpose: Track consecutive failed scrapes to avoid marking URLs as invalid too quickly

-- Add consecutive_failed_scrapes column
ALTER TABLE products
ADD COLUMN consecutive_failed_scrapes INT DEFAULT 0
COMMENT 'Counter for consecutive scrapes that returned no data - reset on successful scrape'
AFTER url_status;

-- Add index for filtering
ALTER TABLE products
ADD INDEX idx_consecutive_failed_scrapes (consecutive_failed_scrapes);
