-- Parallel scraping settings
INSERT INTO `settings` (`key`, `value`, `description`, `type`, `category`) VALUES
('max_concurrent_scrapers', '5', 'Maximum number of scraper instances allowed to run simultaneously', 'integer', 'scraper'),
('item_lock_timeout_seconds', '180', 'Item lock timeout in seconds (3 minutes default). If a scraper locks an item but doesn''t finish, lock expires after this time.', 'integer', 'scraper')
ON DUPLICATE KEY UPDATE
  `description` = VALUES(`description`),
  `type` = VALUES(`type`),
  `category` = VALUES(`category`);
