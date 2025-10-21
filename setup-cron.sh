#!/bin/bash
# Setup cron job for price_grabber scraper
# Run every minute with max 4 concurrent jobs, 100 items each
# Runs as root (Chrome requires it) but fixes log permissions for www-data

echo "Setting up price_grabber cron job..."
echo ""
echo "This will run as ROOT user because Chrome requires elevated permissions."
echo "Log files will be automatically chowned to www-data after each run."
echo ""

# The cron job command
CRON_JOB="* * * * * cd /var/www/tools/price_grabber/current && /usr/bin/php scrape.php -n 100 >> /var/www/tools/price_grabber/shared/logs/cron.log 2>&1 && chown -R www-data:www-data /var/www/tools/price_grabber/shared/logs/*.log 2>/dev/null"

# Check if cron job already exists
if crontab -l 2>/dev/null | grep -F "price_grabber/current" > /dev/null; then
    echo "⚠️  Cron job already exists!"
    echo ""
    echo "Current cron job:"
    crontab -l | grep "price_grabber"
    echo ""
    read -p "Do you want to replace it? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        # Remove old cron job and add new one
        (crontab -l 2>/dev/null | grep -v "price_grabber/current"; echo "$CRON_JOB") | crontab -
        echo "✓ Cron job updated!"
    else
        echo "Keeping existing cron job."
        exit 0
    fi
else
    # Add new cron job
    (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -
    echo "✓ Cron job installed!"
fi

echo ""
echo "Current cron configuration:"
crontab -l | grep "price_grabber"
echo ""
echo "The scraper will run every minute, processing up to 100 items."
echo "Max 4 concurrent scrapers (controlled by max_concurrent_scrapers setting)."
echo ""
echo "Monitor with:"
echo "  tail -f /var/www/tools/price_grabber/shared/logs/cron.log"
echo "  tail -f /var/www/tools/price_grabber/shared/logs/app-*.log"
