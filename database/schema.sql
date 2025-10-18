-- Price Grabber Database Schema

-- Products table: stores base product data
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id VARCHAR(50) NOT NULL UNIQUE,
    parent_id VARCHAR(50) NULL,
    sku VARCHAR(50),
    ean VARCHAR(20),
    site VARCHAR(50),
    site_product_id VARCHAR(50),
    price DECIMAL(10, 2),
    uvp DECIMAL(10, 2),
    site_status VARCHAR(20),
    url VARCHAR(250) NOT NULL,
    name VARCHAR(255),
    description TEXT,
    image_url VARCHAR(512),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_product_id (product_id),
    INDEX idx_parent_id (parent_id),
    INDEX idx_sku (sku),
    INDEX idx_ean (ean),
    INDEX idx_site (site),
    INDEX idx_url (url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Price history table: stores historical price data
CREATE TABLE IF NOT EXISTS price_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id VARCHAR(50) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    uvp DECIMAL(10, 2),
    currency VARCHAR(3) DEFAULT 'EUR',
    site_status VARCHAR(20),
    availability ENUM('in_stock', 'out_of_stock', 'limited', 'unknown') DEFAULT 'unknown',
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_id (product_id),
    INDEX idx_fetched_at (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scraper configuration table: defines scraping patterns by hostname
CREATE TABLE IF NOT EXISTS scraper_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hostname VARCHAR(255) NOT NULL UNIQUE,
    price_selector VARCHAR(512) NOT NULL COMMENT 'CSS selector or XPath for price',
    seller_selector VARCHAR(512) COMMENT 'CSS selector or XPath for seller',
    availability_selector VARCHAR(512) COMMENT 'CSS selector or XPath for availability',
    name_selector VARCHAR(512) COMMENT 'CSS selector or XPath for product name',
    image_selector VARCHAR(512) COMMENT 'CSS selector or XPath for product image',
    currency VARCHAR(3) DEFAULT 'USD',
    selector_type ENUM('css', 'xpath') DEFAULT 'css',
    active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hostname (hostname),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample scraper configurations for common sites
INSERT INTO scraper_config (hostname, price_selector, seller_selector, availability_selector, name_selector, image_selector, currency, selector_type) VALUES
('example.com', '.product-price', '.seller-name', '.availability-status', 'h1.product-title', '.product-image img', 'USD', 'css'),
('shop.example.com', '//span[@class="price"]', '//div[@class="seller"]/text()', '//span[@class="stock"]', '//h1', '//img[@class="main-image"]/@src', 'USD', 'xpath');
