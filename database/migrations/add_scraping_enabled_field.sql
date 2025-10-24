-- Migration: Add scraping_enabled field to products table
-- Date: 2025-10-24
-- Purpose: Allow manual enable/disable of scraping for individual products

-- Add scraping_enabled column to products table
ALTER TABLE products
ADD COLUMN scraping_enabled TINYINT(1) NOT NULL DEFAULT 1
COMMENT 'Enable/disable scraping for this product: 1=enabled, 0=disabled'
AFTER url_status;

-- Add index for efficient filtering
ALTER TABLE products
ADD INDEX idx_scraping_enabled (scraping_enabled);

-- All existing products are enabled by default (value already set by DEFAULT 1)
