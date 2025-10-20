-- Scraper runs table to log scraper executions
CREATE TABLE IF NOT EXISTS `scraper_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` timestamp NULL DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT NULL COMMENT 'Duration in seconds',
  `items_processed` int(11) DEFAULT 0 COMMENT 'Number of products successfully scraped',
  `items_failed` int(11) DEFAULT 0 COMMENT 'Number of products that failed to scrape',
  `items_total` int(11) DEFAULT 0 COMMENT 'Total number of products attempted',
  `bot_challenges` int(11) DEFAULT 0 COMMENT 'Number of bot/WAF challenges encountered',
  `successful_bypasses` int(11) DEFAULT 0 COMMENT 'Number of successful Chrome bypasses',
  `status` varchar(20) NOT NULL DEFAULT 'running' COMMENT 'running, completed, failed, interrupted',
  `error_message` text DEFAULT NULL COMMENT 'Error message if run failed',
  `process_id` int(11) DEFAULT NULL COMMENT 'Process ID (PID)',
  `hostname` varchar(255) DEFAULT NULL COMMENT 'Server hostname',
  `limit_parameter` int(11) DEFAULT NULL COMMENT 'Limit parameter if used (-n option)',
  PRIMARY KEY (`id`),
  KEY `started_at` (`started_at`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
