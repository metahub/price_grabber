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
    private $timeout;
    private $maxRetries;
    private $delay;
    private $delay202;
    private $delay429;
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
        $this->timeout = $this->settingsModel->get('scraper_timeout', 30);
        $this->maxRetries = $this->settingsModel->get('scraper_max_retries', 3);
        $this->delay = $this->settingsModel->get('scraper_delay', 1);
        $this->delay202 = $this->settingsModel->get('scraper_202_delay', 30);
        $this->delay429 = $this->settingsModel->get('scraper_429_delay', 5);
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

        if ($productId) {
            // Update existing product with scraped data
            $updateData = [
                'url' => $url,
                'price' => $data['price'] ?? null,
                'site_status' => $data['site_status'] ?? null,
            ];

            if (!empty($data['name'])) {
                $updateData['name'] = $data['name'];
            }
            if (!empty($data['image_url'])) {
                $updateData['image_url'] = $data['image_url'];
            }

            $this->productModel->update($productId, $updateData);
            Logger::info("Product updated from scrape", ['product_id' => $productId]);
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
            'data' => $data
        ];
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

                // Delay between requests
                if ($this->delay > 0) {
                    sleep($this->delay);
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
        $ch = curl_init();

        // Array to capture response headers
        $responseHeaders = [];

        // Header callback to capture response headers
        $headerCallback = function($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }
            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);
            return $len;
        };

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADERFUNCTION => $headerCallback,
        ]);

        $attempts = 0;
        $html = false;

        while ($attempts < $this->maxRetries && !$html) {
            $responseHeaders = []; // Reset headers for each attempt
            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($html && $httpCode == 200) {
                break;
            }

            // Handle 202 Accepted - Check if it's AWS WAF bot detection challenge
            if ($httpCode == 202) {
                // Check if this is AWS WAF challenge (x-amzn-waf-action: challenge)
                $isWafChallenge = isset($responseHeaders['x-amzn-waf-action']) &&
                                  $responseHeaders['x-amzn-waf-action'] === 'challenge';

                if ($isWafChallenge) {
                    // Increment bot challenge counter
                    $this->botChallenges++;

                    Logger::warning("AWS WAF bot challenge detected (202)", [
                        'url' => $url,
                        'waf_action' => $responseHeaders['x-amzn-waf-action']
                    ]);

                    // Close curl before attempting Chrome
                    curl_close($ch);

                    // Try fetching with Chrome to bypass WAF
                    Logger::info("Attempting to bypass WAF using headless Chrome");
                    $chromeHtml = $this->fetchUrlWithChrome($url);

                    if ($chromeHtml) {
                        // Increment successful bypass counter
                        $this->successfulBypasses++;

                        Logger::info("Successfully bypassed WAF with Chrome", ['url' => $url]);
                        return $chromeHtml;
                    }

                    Logger::error("Chrome fallback failed for WAF challenge", ['url' => $url]);
                    return false;
                } else {
                    // Standard 202 Accepted (async processing) - retry with delay
                    Logger::warning("202 Accepted received (async processing)", [
                        'url' => $url,
                        'attempt' => $attempts + 1,
                        'delay' => $this->delay202
                    ]);

                    if ($attempts < $this->maxRetries) {
                        sleep($this->delay202);
                        $attempts++;
                        continue;
                    }
                }
            }

            // Handle 429 Too Many Requests with extra delay
            if ($httpCode == 429) {
                Logger::warning("429 Too Many Requests received", [
                    'url' => $url,
                    'attempt' => $attempts + 1,
                    'delay' => $this->delay429
                ]);

                if ($attempts < $this->maxRetries) {
                    sleep($this->delay429);
                    $attempts++;
                    continue;
                }
            }

            $attempts++;
            if ($attempts < $this->maxRetries) {
                Logger::debug("Retrying fetch", [
                    'url' => $url,
                    'attempt' => $attempts,
                    'http_code' => $httpCode
                ]);
                sleep(1);
            }
        }

        curl_close($ch);

        return $html;
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
        $userDataDir = null;

        try {
            $browserFactory = new BrowserFactory($this->chromeBinaryPath);

            // Create user data directory for Chrome (prevents ProcessSingleton errors)
            $userDataDir = sys_get_temp_dir() . '/chrome_data_' . getmypid();
            if (!is_dir($userDataDir)) {
                if (!mkdir($userDataDir, 0777, true)) {
                    Logger::error("Failed to create Chrome user data directory", [
                        'dir' => $userDataDir
                    ]);
                }
                // Ensure directory is fully writable
                chmod($userDataDir, 0777);
            }

            // Launch browser with options optimized for headless server operation
            $browser = $browserFactory->createBrowser([
                'headless' => true,
                'noSandbox' => true, // Required for server environments
                'keepAlive' => false,
                'windowSize' => [1920, 1080],
                'userAgent' => $this->userAgent,
                'customFlags' => [
                    '--disable-dev-shm-usage',           // Overcome limited resource problems
                    '--disable-setuid-sandbox',          // Additional sandbox disabling
                    '--disable-gpu',                     // GPU not available on servers
                    '--no-first-run',                    // Skip first run tasks
                    '--no-default-browser-check',        // Don't check if default browser
                    '--disable-software-rasterizer',     // Disable software rasterizer
                    '--disable-extensions',              // Disable extensions
                    '--disable-background-networking',   // Reduce background activity
                    '--disable-sync',                    // Disable syncing to a Google account
                    '--metrics-recording-only',          // Don't send metrics
                    '--disable-breakpad',                // Disable crash reporting
                    '--mute-audio',                      // No audio needed
                    '--disable-notifications',           // No notifications
                    '--single-process',                  // Run as single process (fixes ProcessSingleton)
                    '--user-data-dir=' . $userDataDir,   // Dedicated user data directory
                ],
            ]);

            // Create a new page
            $page = $browser->createPage();

            // Optionally disable images for faster loading
            if ($this->chromeDisableImages) {
                // Note: chrome-php doesn't have direct image blocking,
                // but we can add it via Chrome DevTools Protocol if needed
            }

            // Navigate to the URL
            $navigation = $page->navigate($url);

            // Wait for page to load (with timeout)
            $navigation->waitForNavigation('networkIdle', $this->chromeTimeout * 1000);

            // Get the HTML content
            $html = $page->getHtml();

            $memoryAfter = memory_get_usage(true);
            $memoryUsed = $memoryAfter - $memoryBefore;

            Logger::info("Successfully fetched URL with Chrome", [
                'url' => $url,
                'html_length' => strlen($html),
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

            // Clean up Chrome user data directory
            if ($userDataDir !== null && is_dir($userDataDir)) {
                try {
                    // Remove the temporary user data directory
                    $this->removeDirectory($userDataDir);
                } catch (\Exception $e) {
                    Logger::warning("Failed to clean up Chrome user data directory", [
                        'dir' => $userDataDir,
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
     * Recursively remove a directory and its contents
     *
     * @param string $dir Directory path to remove
     * @return bool True on success
     */
    private function removeDirectory($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        return rmdir($dir);
    }
}
