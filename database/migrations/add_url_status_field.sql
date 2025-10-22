-- Migration: Add url_status field to products table
-- Date: 2025-10-22
-- Purpose: Track URL validity to prevent scraping invalid/dead URLs

-- Add url_status column to products table
ALTER TABLE products
ADD COLUMN url_status ENUM('unchecked', 'valid', 'invalid', 'error')
DEFAULT 'unchecked'
COMMENT 'Tracks URL validity: unchecked=not scraped yet, valid=working with data, invalid=dead/no data, error=network/load failure'
AFTER url;

-- Add index for efficient filtering
ALTER TABLE products
ADD INDEX idx_url_status (url_status);

-- Update existing products that have price history to 'valid'
UPDATE products p
SET url_status = 'valid'
WHERE EXISTS (
    SELECT 1 FROM price_history ph
    WHERE ph.product_id = p.product_id
    LIMIT 1
);

-- Products without price history remain as 'unchecked' (they may not have been scraped yet)
