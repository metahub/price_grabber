-- Scraper locks table to prevent concurrent scraper runs
CREATE TABLE IF NOT EXISTS `scraper_locks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `process_id` int(11) NOT NULL COMMENT 'Process ID (PID) of the running scraper',
  `hostname` varchar(255) DEFAULT NULL COMMENT 'Server hostname (for distributed systems)',
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the lock was acquired',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
