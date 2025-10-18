# Price Grabber

A PHP-based web scraping tool for tracking product prices across multiple retailers. Features automated price tracking, historical data storage, parent-child product relationships, and comprehensive logging.

## Features

- **Automated Price Scraping**: Configurable CSS/XPath selectors per hostname
- **Historical Price Tracking**: Store and analyze price changes over time
- **Product Hierarchy**: Support for parent-child relationships (sizes, variants)
- **Bulk Import**: Tab-separated data import for easy product management
- **Comprehensive Logging**: Monolog integration for debugging and monitoring
- **Modern Template Engine**: Twig templating for clean, maintainable views

## Tech Stack

- **PHP** 7.4+
- **MySQL** 5.7+
- **Composer** for dependency management
- **Monolog** for logging
- **Twig** for templating (planned)

## Database Schema

### Products Table
- `product_id` (VARCHAR 50) - Unique product identifier
- `parent_id` (VARCHAR 50) - Reference to parent product
- `sku` (VARCHAR 50) - Stock Keeping Unit
- `ean` (VARCHAR 20) - European Article Number
- `site` (VARCHAR 50) - Source website
- `site_product_id` (VARCHAR 50) - External product ID
- `price` (DECIMAL) - Current price
- `uvp` (DECIMAL) - Manufacturer's suggested retail price
- `site_status` (VARCHAR 20) - Product status on site
- `url` (VARCHAR 250) - Product URL
- `name`, `description`, `image_url` - Product details

### Price History Table
Tracks all price changes with timestamps, UVP, currency, and availability status.

### Scraper Config Table
Defines scraping patterns (CSS/XPath selectors) per hostname.

## Installation

### 1. Clone Repository

```bash
cd /path/to/your/projects
# Extract or clone the price_grabber directory
```

### 2. Install Dependencies

```bash
composer install
```

If you don't have Composer installed:
```bash
curl -sS https://getcomposer.org/installer | php
php composer.phar install
```

### 3. Configure Environment

```bash
cp .env.example .env
```

Edit `.env` with your settings:

```env
# Database
DB_HOST=localhost
DB_PORT=3306
DB_NAME=price_grabber
DB_USER=your_username
DB_PASSWORD=your_password

# Application
APP_ENV=development
APP_DEBUG=true
APP_TIMEZONE=UTC

# Scraper Settings
SCRAPER_USER_AGENT="Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
SCRAPER_TIMEOUT=30
SCRAPER_MAX_RETRIES=3
SCRAPER_DELAY=1
```

### 4. Set Up Database

Create the database:

```bash
mysql -u root -p
```

```sql
CREATE DATABASE price_grabber CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

Import the schema:

```bash
mysql -u your_username -p price_grabber < database/schema.sql
```

**Or use the setup script:**

```bash
php setup.php
```

### 5. Configure Web Server

#### Option A: PHP Built-in Server (Development)

```bash
cd public
php -S localhost:8000
```

Access at: http://localhost:8000

#### Option B: Apache

Create a virtual host pointing to the `public/` directory:

```apache
<VirtualHost *:80>
    ServerName price-grabber.local
    DocumentRoot /path/to/price_grabber/public

    <Directory /path/to/price_grabber/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Usage

### 1. Configure Scraper Patterns

Visit **Scraper Config** in the web interface and add configurations for each website:

**Example:**
- **Hostname**: `amazon.com`
- **Price Selector**: `.a-price-whole` (CSS)
- **UVP Selector**: `.a-text-price .a-offscreen`
- **Availability Selector**: `#availability span`
- **Selector Type**: CSS

### 2. Import Products

#### Via Bulk Import Interface

Navigate to **Bulk Import** and paste tab-separated data:

```
product_id	parent_id	sku	ean	site	site_product_id	price	uvp	site_status	url	name	description
PROD001		SKU123	1234567890123	amazon	B08X123	29.99	39.99	active	https://example.com/product	Example Product	Product description
PROD001-S		SKU123-S	1234567890124	amazon	B08X124	29.99	39.99	active	https://example.com/product-small	Example Product - Small	Small size variant
```

**Column Format:**
1. `product_id` (required)
2. `parent_id` (optional - leave empty for parent products)
3. `sku`
4. `ean`
5. `site`
6. `site_product_id`
7. `price`
8. `uvp`
9. `site_status`
10. `url` (required)
11. `name`
12. `description`

### 3. Run the Scraper

#### Scrape All Products

```bash
php scrape.php --all
```

#### Scrape Single URL

```bash
php scrape.php --url="https://example.com/product-page"
```

### 4. View Results

- **Products Overview**: Filter by site, status, or search by name/SKU/EAN
- **Product Details**: View price history, variants, and statistics
- **Price History**: Track price changes over time

## Automated Scraping

Set up a cron job for automatic price updates:

```bash
crontab -e
```

Add entry (runs daily at 2 AM):

```cron
0 2 * * * cd /path/to/price_grabber && php scrape.php --all >> logs/scraper.log 2>&1
```

## Logging

Logs are stored in `logs/app.log` with automatic rotation (30 days retention).

**Log Levels:**
- `debug`: Detailed scraping information
- `info`: General operations
- `warning`: Non-critical issues
- `error`: Failures and exceptions

**View logs:**

```bash
tail -f logs/app.log
```

## Project Structure

```
price_grabber/
├── bootstrap.php           # Autoloader & initialization
├── composer.json          # Dependencies
├── database/
│   └── schema.sql        # Database schema
├── logs/                 # Application logs
├── public/               # Web root
│   ├── index.php        # Homepage
│   ├── products.php     # Product listing
│   ├── product-detail.php
│   ├── bulk-import.php
│   └── scraper-config.php
├── src/
│   ├── Core/
│   │   ├── Config.php
│   │   ├── Database.php
│   │   ├── Logger.php
│   │   └── Scraper.php
│   ├── Models/
│   │   ├── Product.php
│   │   ├── PriceHistory.php
│   │   └── ScraperConfig.php
│   └── Controllers/
│       └── BulkImportController.php
├── scrape.php           # CLI scraper
└── setup.php            # Setup wizard
```

## Troubleshooting

### Composer Dependency Errors

```bash
composer install --no-dev  # Skip development dependencies
```

### Database Connection Failed

- Verify credentials in `.env`
- Ensure MySQL is running
- Check database exists

### Scraper Not Finding Data

- Test selectors in browser DevTools
- Check if website uses JavaScript rendering (not supported)
- Try XPath instead of CSS selectors
- Review logs for detailed error messages

### Permission Issues with Logs

```bash
mkdir -p logs
chmod 755 logs
```

## Development

### Adding New Scrapers

1. Navigate to **Scraper Config**
2. Add hostname and selectors
3. Test with a single URL first
4. Monitor logs for issues

### Extending the Schema

Edit `database/schema.sql` and update corresponding Model classes in `src/Models/`.

## Security Notes

- Never commit `.env` file
- Use strong database passwords
- Implement rate limiting for scraping
- Respect robots.txt and terms of service
- Consider using rotating proxies for high-volume scraping

## Future Enhancements

- [ ] Twig template integration for web interface
- [ ] Price change notifications via email
- [ ] API endpoints for external integrations
- [ ] Charts/graphs for price trends
- [ ] Support for JavaScript-rendered pages (Puppeteer/Playwright)
- [ ] Proxy rotation support
- [ ] Multi-currency conversion
- [ ] CSV/Excel export

## License

This project is for educational purposes. Always respect website terms of service and scraping policies.

## Support

For issues or questions, check the logs first:
```bash
tail -100 logs/app.log
```

Enable debug mode in `.env`:
```env
APP_DEBUG=true
```
