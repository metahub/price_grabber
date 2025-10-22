#!/usr/bin/env php
<?php

// Increase memory limit for scraper operations (especially Chrome headless)
ini_set('memory_limit', '512M');

require_once __DIR__ . '/bootstrap.php';

use PriceGrabber\Core\Scraper;
use PriceGrabber\Core\Logger;
use PriceGrabber\Models\ScraperLock;
use PriceGrabber\Models\ScraperRun;
use PriceGrabber\Models\ItemLock;
use PriceGrabber\Models\Settings;

// Parse command line arguments
$options = getopt('u:an:p:', ['url:', 'all', 'limit:', 'product-id:']);

// Log memory limit for debugging
$memoryLimit = ini_get('memory_limit');
Logger::info("Scraper starting", [
    'memory_limit' => $memoryLimit,
    'pid' => getmypid()
]);

$scraper = new Scraper();

try {
    if (isset($options['p']) || isset($options['product-id'])) {
        // Scrape a specific product by product_id
        $productId = $options['p'] ?? $options['product-id'];
        Logger::info("Scraping product by ID", ['product_id' => $productId]);
        echo "Scraping product: {$productId}\n";

        $result = $scraper->scrapeByProductId($productId);
        echo "Success!\n";
        echo "Product ID: " . ($result['product_id'] ?? 'N/A') . "\n";
        echo "URL Status: " . ($result['url_status'] ?? 'N/A') . "\n";
        if (!empty($result['data'])) {
            echo "\nScraped Data:\n";
            echo "  Price: " . ($result['data']['price'] ?? 'N/A') . "\n";
            echo "  Name: " . ($result['data']['name'] ?? 'N/A') . "\n";
            echo "  Seller: " . ($result['data']['seller'] ?? 'N/A') . "\n";
            echo "  Availability: " . ($result['data']['availability'] ?? 'N/A') . "\n";
        }
    } elseif (isset($options['u']) || isset($options['url'])) {
        // Scrape a single URL
        $url = $options['u'] ?? $options['url'];
        Logger::info("Scraping single URL", ['url' => $url]);
        echo "Scraping single URL: {$url}\n";

        $result = $scraper->scrapeUrl($url);
        echo "Success!\n";
        print_r($result);
    } elseif (isset($options['a']) || isset($options['all']) || isset($options['n']) || isset($options['limit'])) {
        // Load settings for parallel scraping
        $settingsModel = new Settings();
        $maxConcurrentScrapers = $settingsModel->get('max_concurrent_scrapers', 5);
        $itemLockTimeout = $settingsModel->get('item_lock_timeout_seconds', 180);

        // Check if max concurrent scrapers limit reached
        $lockManager = new ScraperLock();
        $activeLocks = $lockManager->countActiveLocks();

        if ($activeLocks >= $maxConcurrentScrapers) {
            Logger::info("Max concurrent scrapers limit reached, skipping execution", [
                'active_scrapers' => $activeLocks,
                'max_allowed' => $maxConcurrentScrapers,
                'current_pid' => getmypid()
            ]);
            echo "Max concurrent scrapers limit reached ({$activeLocks}/{$maxConcurrentScrapers})\n";
            echo "Skipping execution.\n";
            exit(0);
        }

        // Note: We don't check isLocked() anymore because we allow multiple instances
        // The max concurrent limit is enforced above

        // Try to acquire lock
        if (!$lockManager->acquireLock()) {
            Logger::error("Failed to acquire scraper lock");
            echo "Failed to acquire scraper lock. Another instance may have started.\n";
            exit(1);
        }

        // Initialize run tracker and item lock manager
        $runTracker = new ScraperRun();
        $itemLockManager = new ItemLock();
        $runId = null;

        try {
            // Get limit parameter
            $limit = null;
            if (isset($options['n'])) {
                $limit = (int)$options['n'];
            } elseif (isset($options['limit'])) {
                $limit = (int)$options['limit'];
            }

            // Check if there are products to scrape before creating a run entry
            $productModel = new \PriceGrabber\Models\Product();
            $minInterval = $settingsModel->get('scraper_min_interval', 3600);

            // Fetch a small number just to check if anything is available
            // Use the same buffer logic as the actual scraper (3x)
            $checkLimit = $limit ? min($limit * 3, 10) : 10;
            $productsToScrape = $productModel->getProductsNeedingScrape($minInterval, $checkLimit);

            if (empty($productsToScrape)) {
                Logger::info("No products need scraping, skipping execution", [
                    'min_interval' => $minInterval,
                    'current_pid' => getmypid()
                ]);
                echo "No products need scraping at this time.\n";
                echo "Skipping execution.\n";

                // Release the lock before exiting
                $lockManager->releaseLock();
                exit(0);
            }

            Logger::info("Found products needing scrape", [
                'count' => count($productsToScrape),
                'limit' => $limit,
                'min_interval' => $minInterval
            ]);

            // Start tracking the run
            $runId = $runTracker->startRun($limit);

            // Clean stale item locks before starting (for parallel scraping)
            $staleLocksCleaned = $itemLockManager->cleanStaleLocks($itemLockTimeout);
            if ($staleLocksCleaned > 0) {
                Logger::info("Cleaned stale item locks", [
                    'count' => $staleLocksCleaned,
                    'run_id' => $runId
                ]);
            }

            // Set the run ID on the scraper for item locking
            $scraper->setRunId($runId);

            // Scrape products (all or limited)
            if ($limit) {
                echo "Scraping up to {$limit} products (parallel mode)...\n";
                Logger::info("Starting scrape with limit", [
                    'limit' => $limit,
                    'run_id' => $runId,
                    'active_scrapers' => $activeLocks + 1
                ]);
            } else {
                echo "Scraping all products (parallel mode)...\n";
                Logger::info("Starting scrape all products", [
                    'run_id' => $runId,
                    'active_scrapers' => $activeLocks + 1
                ]);
            }

            $scrapeResult = $scraper->scrapeProducts([], $limit);

            $results = $scrapeResult['results'];
            $botChallenges = $scrapeResult['bot_challenges'];
            $successfulBypasses = $scrapeResult['successful_bypasses'];
            $itemsSkipped = $scrapeResult['items_skipped'];

            $success = count(array_filter($results, fn($r) => !isset($r['error'])));
            $failed = count($results) - $success;
            $total = count($results);

            // Mark run as completed
            $runTracker->completeRun($runId, $success, $failed, $total, $botChallenges, $successfulBypasses);

            echo "\nCompleted!\n";
            echo "Total: {$total} products\n";
            echo "Success: {$success}\n";
            echo "Failed: {$failed}\n";
            if ($itemsSkipped > 0) {
                echo "Skipped (locked by other scrapers): {$itemsSkipped}\n";
            }
            if ($botChallenges > 0) {
                echo "Bot Challenges: {$botChallenges}\n";
                echo "Successful Bypasses: {$successfulBypasses}\n";
            }

            Logger::info("Scrape products completed", [
                'run_id' => $runId,
                'total' => $total,
                'success' => $success,
                'failed' => $failed,
                'items_skipped' => $itemsSkipped,
                'limit' => $limit,
                'bot_challenges' => $botChallenges,
                'successful_bypasses' => $successfulBypasses
            ]);
        } catch (\Exception $e) {
            // Mark run as failed if an error occurs
            if ($runId) {
                $runTracker->failRun($runId, $e->getMessage());
            }
            throw $e; // Re-throw to be caught by outer try-catch
        } finally {
            // Always release all item locks for this run
            if ($runId) {
                $itemLockManager->releaseAllLocks($runId);
            }

            // Always release the scraper lock, even if an error occurs
            $lockManager->releaseLock();
        }
    } else {
        echo "Usage:\n";
        echo "  php scrape.php -p <product_id>     Scrape a specific product by ID\n";
        echo "  php scrape.php --product-id=<id>   Scrape a specific product by ID\n";
        echo "  php scrape.php -u <url>            Scrape a single URL\n";
        echo "  php scrape.php --url=<url>         Scrape a single URL\n";
        echo "  php scrape.php -a                  Scrape all products\n";
        echo "  php scrape.php --all               Scrape all products\n";
        echo "  php scrape.php -n <number>         Scrape limited number of products\n";
        echo "  php scrape.php --limit=<num>       Scrape limited number of products\n";
        echo "\n";
        echo "Examples:\n";
        echo "  php scrape.php -p 199529-60        Scrape product with ID 199529-60\n";
        echo "  php scrape.php -n 10               Scrape 10 products\n";
    }
} catch (Exception $e) {
    Logger::error("Scraper error", ['error' => $e->getMessage()]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
