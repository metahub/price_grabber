-- Optimize max_concurrent_scrapers based on performance testing
-- Testing showed: 1 process = 7.4/min, 2 processes = 10/min, 4 processes = 10/min
-- Conclusion: 1 process gives best per-process throughput with no benefit from parallelization
-- Likely bottleneck: Chrome overhead or Otto.de throttling concurrent requests from same IP

UPDATE settings
SET value = '1'
WHERE `key` = 'max_concurrent_scrapers';

SELECT `key`, `value`, `description`
FROM settings
WHERE `key` = 'max_concurrent_scrapers';
