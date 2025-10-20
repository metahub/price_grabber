# Parallel Scraping Setup Guide

This guide explains how to set up and use the parallel scraping feature to speed up product data collection.

## Overview

The parallel scraping system allows multiple scraper instances to run simultaneously, each processing different products. This significantly speeds up scraping large product catalogs.

**Key Features:**
- Multiple scrapers run concurrently (configurable limit)
- Dynamic item locking prevents duplicate scraping
- Automatic stale lock cleanup (crashed scrapers don't block items)
- Full tracking and statistics for each scraper run
- Supports both queue mode (continuous) and batch mode (-n limit)

## Database Setup

### 1. Create the item_locks table

Run this SQL to create the item locks table:

```bash
mysql -u your_user -p your_database < database/item_locks_schema.sql
```

Or manually execute:
```sql
SOURCE database/item_locks_schema.sql;
```

### 2. Add parallel scraping settings

Run this SQL to add the configuration settings:

```bash
mysql -u your_user -p your_database < database/parallel_scraping_settings.sql
```

Or manually execute:
```sql
SOURCE database/parallel_scraping_settings.sql;
```

This adds two settings:
- `max_concurrent_scrapers` (default: 5) - Maximum number of scrapers allowed to run simultaneously
- `item_lock_timeout_seconds` (default: 180) - Item lock timeout in seconds (3 minutes)

### 3. Verify settings

You can view and adjust these settings in the Settings page of the web interface.

## How It Works

### Instance Limit
- Before starting, each scraper checks how many instances are currently running
- If the count is at or above `max_concurrent_scrapers`, the new scraper exits gracefully
- This prevents system overload while maximizing parallelization

### Item Locking
- Each scraper tries to lock individual products before processing them
- If a product is already locked by another scraper, it's skipped
- Locks are released after processing (success or failure)
- Stale locks (older than timeout) are automatically cleaned up

### Lock Timeout
- Default: 3 minutes (180 seconds)
- If a scraper crashes or is killed, locks expire after this time
- Other scrapers can then pick up the orphaned items
- Adjust via `item_lock_timeout_seconds` setting if needed

## Usage

### Running Parallel Scrapers

**Option 1: Queue Mode (Continuous)**
```bash
# Start multiple instances (up to max_concurrent_scrapers)
php scrape.php -a &
php scrape.php -a &
php scrape.php -a &
```

Each scraper processes products until none are left needing scraping.

**Option 2: Batch Mode (Fixed Limit)**
```bash
# Each scraper processes up to 50 products
php scrape.php -n 50 &
php scrape.php -n 50 &
php scrape.php -n 50 &
```

Each scraper processes up to N products, then exits.

**Option 3: Cron Automation**
```cron
# Run every minute - system auto-manages concurrent instances
* * * * * cd /path/to/price_grabber && php scrape.php -a >> /var/log/scraper.log 2>&1
```

The system automatically prevents exceeding `max_concurrent_scrapers`.

### Monitoring

**1. Real-time Console Output**
```
Scraping up to 50 products (parallel mode)...

Completed!
Total: 47 products
Success: 45
Failed: 2
Skipped (locked by other scrapers): 12
Bot Challenges: 3
Successful Bypasses: 3
```

**2. Scraper Runs Page**

Visit the "Scraper Runs" page in the web interface to view:
- Total runs and items processed
- Average duration and items/minute
- Bot challenges and bypass rate
- Detailed run history with status

**3. Logs**

Check `storage/logs/app.log` for detailed information:
```bash
tail -f storage/logs/app.log
```

## Configuration

### Adjusting Max Concurrent Scrapers

**Via Web Interface:**
1. Go to Settings page
2. Find `max_concurrent_scrapers`
3. Update value (recommended: 3-10)
4. Save changes

**Via Database:**
```sql
UPDATE settings SET value = '10' WHERE `key` = 'max_concurrent_scrapers';
```

### Adjusting Item Lock Timeout

**Via Web Interface:**
1. Go to Settings page
2. Find `item_lock_timeout_seconds`
3. Update value (recommended: 120-300)
4. Save changes

**Via Database:**
```sql
UPDATE settings SET value = '300' WHERE `key` = 'item_lock_timeout_seconds';
```

## Performance Tips

### Optimal Configuration

**For CPU-bound scraping (fast responses):**
- `max_concurrent_scrapers`: 5-10
- `item_lock_timeout_seconds`: 120 (2 minutes)
- Use batch mode: `php scrape.php -n 100`

**For IO-bound scraping (slow responses, WAF challenges):**
- `max_concurrent_scrapers`: 10-20
- `item_lock_timeout_seconds`: 300 (5 minutes)
- Use queue mode: `php scrape.php -a`

### Memory Considerations

Each scraper instance uses ~512MB of memory (including Chrome headless).

**Example calculations:**
- 5 concurrent scrapers = ~2.5GB RAM
- 10 concurrent scrapers = ~5GB RAM
- 20 concurrent scrapers = ~10GB RAM

Ensure your system has adequate RAM before increasing `max_concurrent_scrapers`.

### Scraper Delay Settings

The `scraper_delay` setting (in Settings page) adds delay between requests within each scraper:
- **1 second**: Polite crawling, recommended for most sites
- **0 seconds**: Aggressive (may trigger rate limits)
- **2-3 seconds**: Very polite, for sensitive sites

This delay is per scraper, so with 5 scrapers @ 1 second delay:
- Each scraper: 1 request/second
- Total system: ~5 requests/second

## Troubleshooting

### Issue: Scrapers immediately exit with "Max concurrent scrapers limit reached"

**Cause:** Too many scrapers already running.

**Solution:**
1. Check active scrapers: `ps aux | grep scrape.php`
2. Wait for current scrapers to finish
3. Or increase `max_concurrent_scrapers` setting

### Issue: Items showing "Skipped (locked by other scrapers)" but not being processed

**Cause:** Stale locks from crashed scrapers.

**Solution:**
Stale locks are automatically cleaned when a new scraper starts, but you can manually clean them:

```sql
DELETE FROM item_locks WHERE locked_at < DATE_SUB(NOW(), INTERVAL 180 SECOND);
```

### Issue: High memory usage causing crashes

**Cause:** Too many concurrent scrapers for available RAM.

**Solution:**
1. Reduce `max_concurrent_scrapers`
2. Add more RAM to server
3. Monitor with: `top` or `htop`

### Issue: Products not being scraped (stuck)

**Cause:** All scrapers may be processing other items.

**Solution:**
1. Check scraper runs page for progress
2. Verify products meet scraping criteria (minimum interval)
3. Check logs for errors: `tail -f storage/logs/app.log`

## Statistics and Insights

### Understanding "Items Skipped"

The "items skipped" counter shows how many products were locked by other scrapers:
- **High skip count**: Good! Means scrapers are efficiently distributing work
- **Zero skip count**: Either single scraper running, or products are sparse
- **All items skipped**: Increase buffer by running more scrapers

### Optimal Items/Minute

Target: 30-60 items/minute per scraper (with WAF challenges)

**If lower:**
- Sites may be slow or have WAF challenges
- Check bot challenge statistics
- Consider increasing concurrent scrapers

**If higher:**
- Great performance!
- Can potentially increase concurrent scrapers

## Advanced Usage

### Targeting Specific Products

You can modify `scrape.php` to pass filters:

```php
// Example: Only scrape products from a specific site
$scrapeResult = $scraper->scrapeProducts(['site' => 'example.com'], $limit);
```

### Manual Lock Management

```php
use PriceGrabber\Models\ItemLock;

$itemLock = new ItemLock();

// Release all locks for a specific run
$itemLock->releaseAllLocks($runId);

// Clean stale locks
$itemLock->cleanStaleLocks(180);

// Check lock status
$locked = $itemLock->isLocked('PRODUCT-123');
```

## Summary

The parallel scraping system allows you to:
- ✅ Run multiple scrapers simultaneously
- ✅ Automatically prevent duplicate work
- ✅ Handle scraper crashes gracefully
- ✅ Scale scraping speed with concurrent instances
- ✅ Monitor performance with detailed statistics

Start with 3-5 concurrent scrapers and adjust based on system resources and scraping performance.
