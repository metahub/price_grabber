# Troubleshooting Bot Detection Issues

## Problem
Otto.de is detecting headless Chrome and returning minimal HTML responses (700-800 bytes) instead of full product pages with actual content (500KB+).

## Symptoms
- URL works fine in a real browser
- Headless Chrome gets 776-byte or 717-byte responses
- No product data extracted (price, name, seller all N/A)
- Log shows: "Successfully fetched URL" but html_length is under 1000 bytes

## Latest Updates (After Deployment)

### 1. Check Bot Detection Logs
After deploying the latest code, run a test scrape and check logs:

```bash
php scrape.php -p 100670 2>&1 | grep -A 5 "suspiciously small"
```

You should now see a WARNING with the actual HTML content:
```
[WARNING] Received suspiciously small HTML response - possible bot detection
  html_length: 776
  html_preview: <html><head>...first 500 chars...
```

### 2. Verify User Agent
Check what user agent is configured:

```bash
mysql -u pg_user -p price_grabber -e "SELECT \`key\`, \`value\` FROM settings WHERE \`key\` = 'scraper_user_agent'"
```

Should be a realistic modern browser, e.g.:
```
Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36
```

**Update if needed:**
```sql
UPDATE settings
SET `value` = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
WHERE `key` = 'scraper_user_agent';
```

### 3. Check Scraper Delay
Otto.de may be rate-limiting. Check current delay:

```bash
mysql -u pg_user -p price_grabber -e "SELECT \`key\`, \`value\` FROM settings WHERE \`key\` = 'scraper_delay'"
```

**Increase delay if getting many bot detections:**
```sql
UPDATE settings SET `value` = '5' WHERE `key` = 'scraper_delay';  -- 5 seconds between requests
```

### 4. New Chrome Stealth Flags (Already Added)
The latest code now includes:
- `--disable-blink-features=AutomationControlled` - Hides the fact that browser is automated
- `--disable-web-security` - Bypasses some WAF checks
- `--disable-features=IsolateOrigins,site-per-process` - Performance improvement

### 5. Check Chrome Binary
Verify Chrome is installed and accessible:

```bash
which google-chrome
/usr/bin/google-chrome --version
```

### 6. Consecutive Failures Logic (Already Deployed)
With the latest code, URLs won't be marked invalid until 3 consecutive failures:
- Failure 1: `url_status='unchecked'`, `consecutive_failed_scrapes=1` → Will retry
- Failure 2: `url_status='unchecked'`, `consecutive_failed_scrapes=2` → Will retry
- Failure 3: `url_status='invalid'`, `consecutive_failed_scrapes=3` → Marked invalid

Check products with failures:
```sql
SELECT product_id, name, consecutive_failed_scrapes, url_status
FROM products
WHERE consecutive_failed_scrapes > 0
ORDER BY consecutive_failed_scrapes DESC
LIMIT 20;
```

### 7. Reset Failed Products
If you want to retry products that were marked invalid:

```sql
-- Reset all invalid products
UPDATE products
SET url_status = 'unchecked', consecutive_failed_scrapes = 0
WHERE url_status = 'invalid';

-- Or reset products with 1-2 failures
UPDATE products
SET consecutive_failed_scrapes = 0
WHERE consecutive_failed_scrapes < 3;
```

## Kasada Bot Detection (Latest Finding)

Otto.de is using **Kasada Protection SDK (KPSDK)** - a sophisticated anti-bot system. When detected, you'll see:
- HTML responses of 717-776 bytes
- HTML preview shows: `<script>window.KPSDK={};...`
- JavaScript challenge page instead of product content

### Latest Code Updates (2025-10-22)

**CRITICAL FIX**: Removed Chrome flags that were creating bot fingerprint
- Custom flags (--disable-crash-reporter, --disable-gpu, etc.) were making us MORE detectable
- Result: Went from 0% success (instant 717-byte blocks) to 50%+ success rate

**INTELLIGENT POLLING**: Replaced random sleep with smart content detection
- Polls HTML size every 0.5 seconds for up to 20 seconds
- Stops immediately when real content loads (no wasted time)
- Correctly detects both successful loads and bot blocks
- Logs: "timed_out: true" for blocks, "elapsed_seconds: 0-5" for success

**RANDOMIZED DELAYS**: Added unpredictable timing patterns
- JavaScript wait time varies (prevents timing fingerprints)
- Delay between requests randomized (base_delay × 1-2)

**Result**: ~45-50% first-attempt success rate with proper detection of blocks vs. legitimate pages

**A/B TEST RESULTS (2025-10-22)**: Tested 100 products WITH vs WITHOUT Chrome flags:
- WITH flags: 48% first-attempt success
- WITHOUT flags: 45% first-attempt success
- **Conclusion**: Chrome flags provide NO significant benefit (3% difference is within normal variance)
- **Decision**: Keep code WITHOUT flags for cleaner implementation

### If Kasada Detection Persists

**The hard truth**: Kasada is one of the most sophisticated bot detection systems. It:
- Detects headless Chrome even with stealth flags
- Analyzes browser fingerprints
- Checks JavaScript execution patterns
- May require specialized tools to bypass

**Recommended Solutions (in order)**:

1. **Slow down scraping significantly**
   - Set `scraper_delay` to 10-15 seconds between requests
   - Run scraper less frequently (every 12-24 hours instead of hourly)

2. **Try different times of day**
   - Bot detection may be less strict during off-peak hours

3. **Use residential proxies with IP rotation**
   - Required for consistent bypassing
   - Services: Bright Data, Smartproxy, Oxylabs

4. **Consider switching to Puppeteer with puppeteer-extra-stealth**
   - More advanced evasion techniques
   - Requires Node.js implementation

5. **Use a scraping service API**
   - Services like ScrapingBee, ScraperAPI handle bot detection
   - Costs money but works reliably

## Advanced Solutions (If Bot Detection Persists)

### Option 1: Rotate User Agents
Create a pool of realistic user agents and rotate them:

1. Add multiple user agents to settings:
```sql
INSERT INTO settings (`key`, `value`, category) VALUES
('scraper_user_agent_pool', '["Mozilla/5.0 (Windows NT 10.0; Win64; x64)...", "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)..."]', 'scraper');
```

2. Modify scraper to randomly select from pool (requires code change)

### Option 2: Add Residential Proxies
If Otto.de is blocking your server's IP:
- Use residential proxy service (Bright Data, Smartproxy, etc.)
- Rotate proxies with each request
- Requires code modification to add proxy support

### Option 3: Add Cookies/Session
Some sites check for cookies:
- Store cookies after first successful request
- Reuse cookies for subsequent requests
- Requires code modification to implement cookie jar

### Option 4: Increase Randomization
- Random delays (3-7 seconds instead of fixed 3)
- Random request order
- Simulate human-like browsing patterns

## Monitoring

### Check Scraper Success Rate
```sql
SELECT
    DATE(started_at) as date,
    COUNT(*) as total_runs,
    SUM(items_processed) as processed,
    SUM(items_failed) as failed,
    ROUND(SUM(items_processed) / (SUM(items_processed) + SUM(items_failed)) * 100, 2) as success_rate
FROM scraper_runs
WHERE started_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(started_at)
ORDER BY date DESC;
```

### Check Products by URL Status
```sql
SELECT
    url_status,
    COUNT(*) as count,
    ROUND(COUNT(*) / (SELECT COUNT(*) FROM products) * 100, 2) as percentage
FROM products
GROUP BY url_status;
```

### Find Products Failing Repeatedly
```sql
SELECT
    p.product_id,
    p.name,
    p.url,
    p.consecutive_failed_scrapes,
    p.url_status,
    (SELECT COUNT(*) FROM price_history WHERE product_id = p.product_id) as history_count,
    (SELECT MAX(fetched_at) FROM price_history WHERE product_id = p.product_id) as last_success
FROM products p
WHERE p.consecutive_failed_scrapes >= 2
ORDER BY p.consecutive_failed_scrapes DESC
LIMIT 20;
```

## When Bot Detection is Unavoidable

If Otto.de aggressively blocks all automated access:

1. **Reduce scraping frequency** - Scrape less often (every 24-48 hours instead of hourly)
2. **Prioritize important products** - Use `product_priority` to focus on high-value items
3. **Accept some failures** - The consecutive failures logic (3 attempts) gives temporary issues time to resolve
4. **Contact Otto.de** - Some sites offer API access or partnerships for legitimate price monitoring

## Latest Code Features Summary

✅ **Consecutive failures** - 3 attempts before marking invalid
✅ **Stealth Chrome flags** - Hide automation markers
✅ **Bot detection logging** - See actual HTML content returned
✅ **Skip empty runs** - Don't create database entries when no products need scraping

Deploy latest code and monitor logs for the HTML preview to identify what's being blocked!
