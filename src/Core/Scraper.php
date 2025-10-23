<?php

namespace PriceGrabber\Core;

use PriceGrabber\Models\Product;
use PriceGrabber\Models\PriceHistory;
use PriceGrabber\Models\ScraperConfig;
use PriceGrabber\Models\Settings;
use PriceGrabber\Models\ItemLock;
use DOMDocument;
use DOMXPath;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Exception\CommunicationException;
use HeadlessChromium\Exception\NoResponseAvailable;

class Scraper
{
    private $productModel;
    private $priceHistoryModel;
    private $scraperConfigModel;
    private $settingsModel;
    private $itemLockModel;
    private $userAgent;
    private $delay;
    private $minInterval;
    private $itemLockTimeout;

    // Chrome headless browser settings
    private $chromeEnabled;
    private $chromeBinaryPath;
    private $chromeTimeout;
    private $chromeDisableImages;

    // Bot detection tracking
    private $botChallenges = 0;
    private $successfulBypasses = 0;

    // Parallel scraping support
    private $currentRunId = null;
    private $itemsSkipped = 0; // Items skipped because locked by another scraper

    public function __construct()
    {
        $this->productModel = new Product();
        $this->priceHistoryModel = new PriceHistory();
        $this->scraperConfigModel = new ScraperConfig();
        $this->settingsModel = new Settings();
        $this->itemLockModel = new ItemLock();

        // Load scraper settings from database
        $this->userAgent = $this->settingsModel->get('scraper_user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        $this->delay = $this->settingsModel->get('scraper_delay', 1);
        $this->minInterval = $this->settingsModel->get('scraper_min_interval', 3600);
        $this->itemLockTimeout = $this->settingsModel->get('item_lock_timeout_seconds', 180);

        // Chrome headless browser configuration
        $this->chromeEnabled = $this->settingsModel->get('chrome_enabled', false);
        $this->chromeBinaryPath = $this->settingsModel->get('chrome_binary_path', '/usr/bin/chromium-browser');
        $this->chromeTimeout = $this->settingsModel->get('chrome_timeout', 60);
        $this->chromeDisableImages = $this->settingsModel->get('chrome_disable_images', true);
    }

    /**
     * Set the current scraper run ID (for item locking)
     *
     * @param int $runId Scraper run ID
     */
    public function setRunId($runId)
    {
        $this->currentRunId = $runId;
    }

    public function scrapeUrl($url, $productIdToUpdate = null)
    {
        Logger::info("Fetching URL", ['url' => $url]);

        $hostname = parse_url($url, PHP_URL_HOST);
        $config = $this->scraperConfigModel->findByHostname($hostname);

        if (!$config) {
            $error = "No scraper configuration found for hostname: {$hostname}";
            Logger::error($error, ['url' => $url]);
            throw new \Exception($error);
        }

        $html = $this->fetchUrl($url);
        if (!$html) {
            $error = "Failed to fetch URL: {$url}";
            Logger::error($error);

            // Mark URL as error if fetch failed
            if ($productIdToUpdate) {
                $this->productModel->update($productIdToUpdate, ['url_status' => 'error']);
                Logger::info("Marked URL as error (fetch failed)", ['product_id' => $productIdToUpdate]);
            }

            throw new \Exception($error);
        }

        $data = $this->parseHtml($html, $config);

        $productId = $productIdToUpdate;

        if (!$productId) {
            // Check if product exists by URL
            $existingProduct = $this->productModel->findByUrl($url);
            if ($existingProduct) {
                $productId = $existingProduct['product_id'];
            }
        }

        // Determine URL status with intelligent failure detection
        $hasPrice = !empty($data['price']);
        $hasSeller = !empty($data['seller']);
        $hasName = !empty($data['name']);
        $hasData = $hasPrice || $hasName;
        $htmlSize = strlen($html);
        $consecutiveFailures = 0;

        // Detect specific failure types
        $is404 = $this->is404Page($html);
        $isKasadaBlock = $htmlSize < 10000; // Small response = bot detection
        $isTemporarilyUnavailable = !empty($data['availability']) &&
            in_array($data['availability'], ['out_of_stock', 'limited']);

        if ($hasData) {
            // Successfully extracted data - mark as valid and reset failure counter
            $urlStatus = 'valid';
            $consecutiveFailures = 0;
            Logger::debug("Product scraped successfully, resetting failure counter", [
                'product_id' => $productId,
                'url' => $url
            ]);
        } else {
            // No data extracted - determine why and handle accordingly

            if ($is404) {
                // IMMEDIATE INVALID: Page doesn't exist (404)
                $urlStatus = 'invalid';
                $consecutiveFailures = 0; // Don't increment, it's permanently invalid
                Logger::warning("404 page detected - marking as invalid immediately", [
                    'product_id' => $productId,
                    'url' => $url
                ]);
            } elseif ($htmlSize >= 10000 && !$hasPrice && !$hasSeller) {
                // IMMEDIATE INVALID: Got past bot detection but page has no price/seller
                // This means wrong URL or product removed
                $urlStatus = 'invalid';
                $consecutiveFailures = 0; // Don't increment, it's permanently invalid
                Logger::warning("Large HTML but no price/seller data - marking as invalid immediately", [
                    'product_id' => $productId,
                    'url' => $url,
                    'html_size' => $htmlSize
                ]);
            } elseif ($isKasadaBlock) {
                // NEVER INVALID: Kasada block - keep retrying forever
                $urlStatus = 'unchecked';
                if ($productId) {
                    $existingProduct = $this->productModel->findByProductId($productId);
                    $consecutiveFailures = ($existingProduct['consecutive_failed_scrapes'] ?? 0) + 1;
                } else {
                    $consecutiveFailures = 1;
                }
                Logger::info("Kasada block detected - will keep retrying", [
                    'product_id' => $productId,
                    'url' => $url,
                    'html_size' => $htmlSize,
                    'consecutive_failures' => $consecutiveFailures
                ]);
            } elseif ($isTemporarilyUnavailable) {
                // NEVER INVALID: Product exists but temporarily out of stock
                $urlStatus = 'unchecked';
                $consecutiveFailures = 0; // Reset counter, it's not a failure
                Logger::info("Product temporarily unavailable - will keep retrying", [
                    'product_id' => $productId,
                    'url' => $url,
                    'availability' => $data['availability']
                ]);
            } else {
                // Unknown failure - keep as unchecked but don't increment counter much
                $urlStatus = 'unchecked';
                if ($productId) {
                    $existingProduct = $this->productModel->findByProductId($productId);
                    $consecutiveFailures = ($existingProduct['consecutive_failed_scrapes'] ?? 0) + 1;
                } else {
                    $consecutiveFailures = 1;
                }
                Logger::info("Unknown failure type - keeping as unchecked", [
                    'product_id' => $productId,
                    'url' => $url,
                    'html_size' => $htmlSize,
                    'consecutive_failures' => $consecutiveFailures
                ]);
            }
        }

        if ($productId) {
            // Update existing product with scraped data
            $updateData = [
                'url' => $url,
                'price' => $data['price'] ?? null,
                'site_status' => $data['site_status'] ?? null,
                'url_status' => $urlStatus,
                'consecutive_failed_scrapes' => $consecutiveFailures,
            ];

            if (!empty($data['name'])) {
                $updateData['name'] = $data['name'];
            }
            if (!empty($data['image_url'])) {
                $updateData['image_url'] = $data['image_url'];
            }

            $this->productModel->update($productId, $updateData);
            Logger::info("Product updated from scrape", [
                'product_id' => $productId,
                'url_status' => $urlStatus
            ]);
        }

        // Save price history if we have a price
        if ($productId && isset($data['price'])) {
            $this->priceHistoryModel->create([
                'product_id' => $productId,
                'price' => $data['price'],
                'uvp' => $data['uvp'] ?? null,
                'currency' => $config['currency'] ?? 'EUR',
                'seller' => $data['seller'] ?? null,
                'site_status' => $data['site_status'] ?? null,
                'availability' => $data['availability'] ?? 'unknown',
            ]);

            Logger::info("Price history entry created", [
                'product_id' => $productId,
                'price' => $data['price']
            ]);
        }

        return [
            'product_id' => $productId,
            'data' => $data,
            'url_status' => $urlStatus
        ];
    }

    /**
     * Scrape a specific product by its product_id
     *
     * @param string $productId Product ID to scrape
     * @return array Result of the scrape operation
     * @throws \Exception If product not found or scraping fails
     */
    public function scrapeByProductId($productId)
    {
        Logger::info("Scraping by product ID", ['product_id' => $productId]);

        // Find product by product_id
        $product = $this->productModel->findByProductId($productId);

        if (!$product) {
            $error = "Product not found: {$productId}";
            Logger::error($error);
            throw new \Exception($error);
        }

        if (empty($product['url'])) {
            $error = "Product {$productId} has no URL configured";
            Logger::error($error);
            throw new \Exception($error);
        }

        Logger::info("Found product, scraping URL", [
            'product_id' => $productId,
            'url' => $product['url'],
            'name' => $product['name'] ?? 'N/A'
        ]);

        // Scrape the product's URL
        return $this->scrapeUrl($product['url'], $productId);
    }

    public function scrapeProducts($filters = [], $limit = null)
    {
        // Reset counters for this run
        $this->botChallenges = 0;
        $this->successfulBypasses = 0;
        $this->itemsSkipped = 0;

        // Get only products that need scraping based on minimum interval
        // In parallel mode, we fetch more items than the limit to ensure enough work
        // (some items may be locked by other scrapers)
        $fetchLimit = $limit ? $limit * 3 : null; // Fetch 3x items for buffer
        $products = $this->productModel->getProductsNeedingScrape($this->minInterval, $fetchLimit);

        // Apply additional filters if provided
        if (!empty($filters)) {
            // Filter the products array based on provided filters
            $products = array_filter($products, function($product) use ($filters) {
                if (!empty($filters['site']) && $product['site'] !== $filters['site']) {
                    return false;
                }
                if (!empty($filters['site_status']) && $product['site_status'] !== $filters['site_status']) {
                    return false;
                }
                return true;
            });
        }

        $results = [];
        $itemsProcessed = 0;

        Logger::info("Starting batch scrape", [
            'total_products_available' => count($products),
            'min_interval' => $this->minInterval,
            'limit' => $limit,
            'run_id' => $this->currentRunId
        ]);

        foreach ($products as $product) {
            // Check if we've reached the limit (if specified)
            if ($limit && $itemsProcessed >= $limit) {
                Logger::info("Reached scrape limit, stopping", [
                    'limit' => $limit,
                    'processed' => $itemsProcessed
                ]);
                break;
            }

            // Try to acquire lock on this item (parallel scraping support)
            if ($this->currentRunId) {
                $lockAcquired = $this->itemLockModel->tryLockItem(
                    $product['product_id'],
                    $this->currentRunId,
                    getmypid(),
                    $this->itemLockTimeout
                );

                if (!$lockAcquired) {
                    // Another scraper is processing this item, skip it
                    $this->itemsSkipped++;
                    Logger::debug("Item locked by another scraper, skipping", [
                        'product_id' => $product['product_id']
                    ]);
                    continue;
                }
            }

            try {
                $result = $this->scrapeUrl($product['url'], $product['product_id']);
                $results[] = $result;
                $itemsProcessed++;

                // Release the lock after successful scrape
                if ($this->currentRunId) {
                    $this->itemLockModel->releaseLock($product['product_id']);
                }

                // Delay between requests (randomized to avoid predictable patterns)
                if ($this->delay > 0) {
                    $randomDelay = rand($this->delay, $this->delay * 2);
                    Logger::debug("Applying delay between requests", [
                        'base_delay' => $this->delay,
                        'actual_delay' => $randomDelay
                    ]);
                    sleep($randomDelay);
                }
            } catch (\Exception $e) {
                Logger::error("Error scraping product", [
                    'product_id' => $product['product_id'],
                    'url' => $product['url'],
                    'error' => $e->getMessage()
                ]);

                $results[] = [
                    'product_id' => $product['product_id'],
                    'error' => $e->getMessage()
                ];
                $itemsProcessed++;

                // Release the lock even on error
                if ($this->currentRunId) {
                    $this->itemLockModel->releaseLock($product['product_id']);
                }
            }
        }

        Logger::info("Batch scrape completed", [
            'total' => count($results),
            'failed' => count(array_filter($results, fn($r) => isset($r['error']))),
            'items_skipped' => $this->itemsSkipped,
            'bot_challenges' => $this->botChallenges,
            'successful_bypasses' => $this->successfulBypasses
        ]);

        return [
            'results' => $results,
            'bot_challenges' => $this->botChallenges,
            'successful_bypasses' => $this->successfulBypasses,
            'items_skipped' => $this->itemsSkipped
        ];
    }

    private function fetchUrl($url)
    {
        // Skip curl entirely - it flags IP and rarely works (1-2/1000 success rate)
        // Go straight to Chrome headless for all requests
        Logger::info("Fetching URL with headless Chrome (skipping curl)", ['url' => $url]);
        return $this->fetchUrlWithChrome($url);
    }

    /**
     * Fetch URL using headless Chrome browser
     * Used to bypass WAF/bot detection (e.g., AWS WAF challenges)
     *
     * @param string $url The URL to fetch
     * @return string|false The HTML content or false on failure
     */
    private function fetchUrlWithChrome($url)
    {
        if (!$this->chromeEnabled) {
            Logger::warning("Chrome is disabled in config", ['url' => $url]);
            return false;
        }

        if (!file_exists($this->chromeBinaryPath)) {
            Logger::error("Chrome binary not found", [
                'path' => $this->chromeBinaryPath,
                'url' => $url
            ]);
            return false;
        }

        $memoryBefore = memory_get_usage(true);

        Logger::info("Fetching URL with headless Chrome", [
            'url' => $url,
            'chrome_path' => $this->chromeBinaryPath,
            'memory_usage_mb' => round($memoryBefore / 1024 / 1024, 2)
        ]);

        $browser = null;

        try {
            $browserFactory = new BrowserFactory($this->chromeBinaryPath);

            // Launch browser with options
            // Testing: Removed all customFlags to see if that brings back JS challenges
            // Before Oct 21, there were no custom flags and WAF challenges appeared
            $browser = $browserFactory->createBrowser([
                'headless' => true,
                'noSandbox' => true, // Required for some server environments
                'keepAlive' => false,
                'windowSize' => [1920, 1080],
                'userAgent' => $this->userAgent,
            ]);

            // Create a new page
            $page = $browser->createPage();

            // Set realistic viewport size
            try {
                $page->setViewport(1920, 1080)->await();
            } catch (\Exception $e) {
                Logger::debug("Failed to set viewport", ['error' => $e->getMessage()]);
            }

            // Optionally disable images for faster loading
            if ($this->chromeDisableImages) {
                // Note: chrome-php doesn't have direct image blocking,
                // but we can add it via Chrome DevTools Protocol if needed
            }

            // Navigate to the URL
            $navigation = $page->navigate($url);

            // Wait for page to load (with timeout)
            // Use 'load' instead of 'networkIdle' to ensure external scripts load
            $navigation->waitForNavigation('load', $this->chromeTimeout * 1000);

            // Poll for actual content to load (challenge completion or page error)
            // Kasada challenge is ~717 bytes, real pages are 400KB+
            $maxWaitTime = 20; // seconds
            $minContentSize = 10000; // 10KB threshold
            $startTime = time();
            $pollInterval = 500000; // 0.5 seconds in microseconds
            $pollCount = 0;

            Logger::info("Waiting for page content to load", [
                'url' => $url,
                'max_wait_seconds' => $maxWaitTime
            ]);

            $html = $page->getHtml();
            $initialSize = strlen($html);

            while (strlen($html) < $minContentSize && (time() - $startTime) < $maxWaitTime) {
                usleep($pollInterval);
                $html = $page->getHtml();
                $pollCount++;

                // Log progress every 2 seconds
                if ($pollCount % 4 === 0) {
                    Logger::debug("Still waiting for content", [
                        'url' => $url,
                        'current_size' => strlen($html),
                        'elapsed_seconds' => time() - $startTime
                    ]);
                }
            }

            $finalSize = strlen($html);
            $elapsedTime = time() - $startTime;

            Logger::info("Page content load complete", [
                'url' => $url,
                'initial_size' => $initialSize,
                'final_size' => $finalSize,
                'elapsed_seconds' => $elapsedTime,
                'poll_count' => $pollCount,
                'timed_out' => $finalSize < $minContentSize
            ]);

            $memoryAfter = memory_get_usage(true);
            $memoryUsed = $memoryAfter - $memoryBefore;

            $htmlLength = strlen($html);

            // Check for suspiciously small responses (likely bot detection/error pages)
            if ($htmlLength < 10000) {
                Logger::warning("Received suspiciously small HTML response - possible bot detection", [
                    'url' => $url,
                    'html_length' => $htmlLength,
                    'html_preview' => substr($html, 0, 500) // First 500 chars for debugging
                ]);
            }

            Logger::info("Successfully fetched URL with Chrome", [
                'url' => $url,
                'html_length' => $htmlLength,
                'memory_used_mb' => round($memoryUsed / 1024 / 1024, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
            ]);

            return $html;

        } catch (CommunicationException $e) {
            Logger::error("Chrome communication error", [
                'url' => $url,
                'error' => $e->getMessage(),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
            ]);
            return false;
        } catch (NoResponseAvailable $e) {
            Logger::error("Chrome no response error", [
                'url' => $url,
                'error' => $e->getMessage(),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
            ]);
            return false;
        } catch (\Exception $e) {
            Logger::error("Chrome error", [
                'url' => $url,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
            ]);
            return false;
        } finally {
            // Always try to close the browser, even if an error occurred
            if ($browser !== null) {
                try {
                    $browser->close();
                } catch (\Exception $e) {
                    Logger::warning("Failed to close Chrome browser", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    private function parseHtml($html, $config)
    {
        $data = [];

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();

        if ($config['selector_type'] === 'xpath') {
            $xpath = new DOMXPath($dom);

            $data['price'] = $this->extractXPath($xpath, $config['price_selector']);
            $data['uvp'] = $this->extractXPath($xpath, $config['uvp_selector'] ?? null);
            $data['seller'] = $this->extractXPath($xpath, $config['seller_selector'] ?? null);
            $data['site_status'] = $this->extractXPath($xpath, $config['availability_selector']);
            $data['name'] = $this->extractXPath($xpath, $config['name_selector']);
            $data['image_url'] = $this->extractXPath($xpath, $config['image_selector']);
        } else {
            // CSS selector - convert to XPath
            $xpath = new DOMXPath($dom);

            $data['price'] = $this->extractCss($xpath, $config['price_selector']);
            $data['uvp'] = $this->extractCss($xpath, $config['uvp_selector'] ?? null);
            $data['seller'] = $this->extractCss($xpath, $config['seller_selector'] ?? null);
            $data['site_status'] = $this->extractCss($xpath, $config['availability_selector']);
            $data['name'] = $this->extractCss($xpath, $config['name_selector']);
            $data['image_url'] = $this->extractCss($xpath, $config['image_selector']);
        }

        // Clean up price and uvp (remove currency symbols, commas, etc.)
        if (isset($data['price'])) {
            $data['price'] = $this->cleanPrice($data['price']);
        }

        if (isset($data['uvp'])) {
            $data['uvp'] = $this->cleanPrice($data['uvp']);
        }

        // Map site_status to availability
        if (isset($data['site_status'])) {
            $data['availability'] = $this->mapAvailability($data['site_status']);
        }

        return $data;
    }

    private function extractXPath($xpath, $expression)
    {
        if (empty($expression)) {
            return null;
        }

        $nodes = $xpath->query($expression);
        if ($nodes && $nodes->length > 0) {
            return trim($nodes->item(0)->nodeValue);
        }

        return null;
    }

    private function extractCss($xpath, $selector)
    {
        if (empty($selector)) {
            return null;
        }

        // Simple CSS to XPath conversion
        $xpathExpr = $this->cssToXPath($selector);
        return $this->extractXPath($xpath, $xpathExpr);
    }

    private function cssToXPath($cssSelector)
    {
        // Simple CSS to XPath converter (handles basic selectors)
        $xpathExpr = '//*';

        // Handle class selector
        if (preg_match('/^\.([a-zA-Z0-9_-]+)$/', $cssSelector, $matches)) {
            $xpathExpr = "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$matches[1]} ')]";
        }
        // Handle ID selector
        elseif (preg_match('/^#([a-zA-Z0-9_-]+)$/', $cssSelector, $matches)) {
            $xpathExpr = "//*[@id='{$matches[1]}']";
        }
        // Handle element.class
        elseif (preg_match('/^([a-zA-Z0-9]+)\.([a-zA-Z0-9_-]+)$/', $cssSelector, $matches)) {
            $xpathExpr = "//{$matches[1]}[contains(concat(' ', normalize-space(@class), ' '), ' {$matches[2]} ')]";
        }
        // Handle basic element selector
        elseif (preg_match('/^[a-zA-Z0-9]+$/', $cssSelector)) {
            $xpathExpr = "//{$cssSelector}";
        }

        return $xpathExpr;
    }

    private function cleanPrice($priceString)
    {
        // Remove currency symbols and clean the price
        $priceString = preg_replace('/[^0-9.,]/', '', $priceString);

        // Handle different decimal separators
        if (substr_count($priceString, '.') > 1) {
            $priceString = str_replace('.', '', $priceString);
        }

        $priceString = str_replace(',', '.', $priceString);

        return (float)$priceString;
    }

    private function mapAvailability($siteStatus)
    {
        $siteStatus = strtolower($siteStatus);

        if (strpos($siteStatus, 'in stock') !== false || strpos($siteStatus, 'available') !== false) {
            return 'in_stock';
        }

        if (strpos($siteStatus, 'out of stock') !== false || strpos($siteStatus, 'unavailable') !== false) {
            return 'out_of_stock';
        }

        if (strpos($siteStatus, 'limited') !== false) {
            return 'limited';
        }

        return 'unknown';
    }

    /**
     * Detect if HTML is a 404 page
     *
     * @param string $html The HTML content
     * @return bool True if 404 page detected
     */
    private function is404Page($html)
    {
        $htmlLower = strtolower($html);

        // Check for common 404 indicators
        $indicators = [
            '404',
            'not found',
            'seite nicht gefunden', // German
            'page not found',
            'diese seite existiert nicht', // German
            'fehler 404', // German
            'error 404',
            'page doesn\'t exist',
            'nicht verfügbar', // German "not available"
        ];

        // Check title and common error containers
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = strtolower($matches[1]);
            foreach ($indicators as $indicator) {
                if (strpos($title, $indicator) !== false) {
                    return true;
                }
            }
        }

        // Check for 404 in the first 2000 characters (headers, error messages usually appear early)
        $htmlStart = substr($htmlLower, 0, 2000);
        foreach ($indicators as $indicator) {
            if (strpos($htmlStart, $indicator) !== false) {
                // Make sure it's not just in a meta tag or script
                if (preg_match('/[>\s]' . preg_quote($indicator, '/') . '[<\s]/i', $htmlStart)) {
                    return true;
                }
            }
        }

        return false;
    }
}
