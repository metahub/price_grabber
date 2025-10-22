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
