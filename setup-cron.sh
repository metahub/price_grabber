#!/bin/bash
# Setup cron job for price_grabber scraper
# Run every minute with max 4 concurrent jobs, 100 items each

# Add cron job (if not already exists)
CRON_JOB="* * * * * cd /var/www/tools/price_grabber/current && /usr/bin/php scrape.php -n 100 >> /var/www/tools/price_grabber/shared/logs/cron.log 2>&1"

# Check if cron job already exists
(crontab -l 2>/dev/null | grep -F "price_grabber/current") && echo "Cron job already exists" || (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -

echo "Cron job installed:"
crontab -l | grep "price_grabber"
