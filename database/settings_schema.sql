-- Settings table to store application configuration
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` text,
  `description` text,
  `type` varchar(20) NOT NULL DEFAULT 'string' COMMENT 'string, integer, boolean',
  `category` varchar(50) NOT NULL DEFAULT 'general',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default scraper configuration values
INSERT INTO `settings` (`key`, `value`, `description`, `type`, `category`) VALUES
('scraper_user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'User agent string for HTTP requests', 'string', 'scraper'),
('scraper_timeout', '30', 'Request timeout in seconds', 'integer', 'scraper'),
('scraper_max_retries', '3', 'Maximum number of retry attempts', 'integer', 'scraper'),
('scraper_delay', '3', 'Delay between requests in seconds', 'integer', 'scraper'),
('scraper_202_delay', '30', 'Delay when receiving 202 response in seconds', 'integer', 'scraper'),
('scraper_429_delay', '60', 'Delay when rate limited (429 response) in seconds', 'integer', 'scraper'),
('scraper_min_interval', '3600', 'Minimum interval between scrapes for same product in seconds (1 hour = 3600)', 'integer', 'scraper'),
('chrome_enabled', 'true', 'Enable Chrome headless browser for WAF/bot detection bypass', 'boolean', 'chrome'),
('chrome_binary_path', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', 'Path to Chrome/Chromium binary', 'string', 'chrome'),
('chrome_timeout', '60', 'Chrome browser timeout in seconds', 'integer', 'chrome'),
('chrome_disable_images', 'true', 'Disable image loading in Chrome for faster scraping', 'boolean', 'chrome')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
