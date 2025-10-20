-- Item locks table to track which scraper is processing which product
CREATE TABLE IF NOT EXISTS `item_locks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` varchar(50) NOT NULL COMMENT 'Product ID being processed',
  `scraper_run_id` int(11) NOT NULL COMMENT 'Scraper run ID that locked this item',
  `process_id` int(11) NOT NULL COMMENT 'Process ID (PID) of the scraper',
  `locked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the lock was acquired',
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_id` (`product_id`),
  KEY `scraper_run_id` (`scraper_run_id`),
  KEY `locked_at` (`locked_at`),
  CONSTRAINT `item_locks_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `item_locks_run_fk` FOREIGN KEY (`scraper_run_id`) REFERENCES `scraper_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
