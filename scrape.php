#!/usr/bin/env php
<?php

require_once __DIR__ . '/bootstrap.php';

use PriceGrabber\Core\Scraper;
use PriceGrabber\Core\Logger;
use PriceGrabber\Models\ScraperLock;
use PriceGrabber\Models\ScraperRun;

// Parse command line arguments
$options = getopt('u:an:', ['url:', 'all', 'limit:']);

$scraper = new Scraper();

try {
    if (isset($options['u']) || isset($options['url'])) {
        // Scrape a single URL
        $url = $options['u'] ?? $options['url'];
        Logger::info("Scraping single URL", ['url' => $url]);
        echo "Scraping single URL: {$url}\n";

        $result = $scraper->scrapeUrl($url);
        echo "Success!\n";
        print_r($result);
    } elseif (isset($options['a']) || isset($options['all']) || isset($options['n']) || isset($options['limit'])) {
        // Check for existing lock
        $lockManager = new ScraperLock();

        if ($lockManager->isLocked()) {
            $currentLock = $lockManager->getCurrentLock();
            Logger::info("Scraper already running, skipping execution", [
                'locked_pid' => $currentLock['process_id'],
                'locked_since' => $currentLock['started_at'],
                'current_pid' => getmypid()
            ]);
            echo "Scraper is already running (PID: {$currentLock['process_id']}, started: {$currentLock['started_at']})\n";
            echo "Skipping execution.\n";
            exit(0);
        }

        // Try to acquire lock
        if (!$lockManager->acquireLock()) {
            Logger::error("Failed to acquire scraper lock");
            echo "Failed to acquire scraper lock. Another instance may have started.\n";
            exit(1);
        }

        // Initialize run tracker
        $runTracker = new ScraperRun();
        $runId = null;

        try {
            // Get limit parameter
            $limit = null;
            if (isset($options['n'])) {
                $limit = (int)$options['n'];
            } elseif (isset($options['limit'])) {
                $limit = (int)$options['limit'];
            }

            // Start tracking the run
            $runId = $runTracker->startRun($limit);

            // Scrape products (all or limited)
            if ($limit) {
                echo "Scraping {$limit} products...\n";
                Logger::info("Starting scrape with limit", ['limit' => $limit, 'run_id' => $runId]);
            } else {
                echo "Scraping all products...\n";
                Logger::info("Starting scrape all products", ['run_id' => $runId]);
            }

            $scrapeResult = $scraper->scrapeProducts([], $limit);

            $results = $scrapeResult['results'];
            $botChallenges = $scrapeResult['bot_challenges'];
            $successfulBypasses = $scrapeResult['successful_bypasses'];

            $success = count(array_filter($results, fn($r) => !isset($r['error'])));
            $failed = count($results) - $success;
            $total = count($results);

            // Mark run as completed
            $runTracker->completeRun($runId, $success, $failed, $total, $botChallenges, $successfulBypasses);

            echo "\nCompleted!\n";
            echo "Total: {$total} products\n";
            echo "Success: {$success}\n";
            echo "Failed: {$failed}\n";
            if ($botChallenges > 0) {
                echo "Bot Challenges: {$botChallenges}\n";
                echo "Successful Bypasses: {$successfulBypasses}\n";
            }

            Logger::info("Scrape products completed", [
                'run_id' => $runId,
                'total' => $total,
                'success' => $success,
                'failed' => $failed,
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
            // Always release the lock, even if an error occurs
            $lockManager->releaseLock();
        }
    } else {
        echo "Usage:\n";
        echo "  php scrape.php -u <url>       Scrape a single URL\n";
        echo "  php scrape.php --url=<url>    Scrape a single URL\n";
        echo "  php scrape.php -a             Scrape all products\n";
        echo "  php scrape.php --all          Scrape all products\n";
        echo "  php scrape.php -n <number>    Scrape limited number of products\n";
        echo "  php scrape.php --limit=<num>  Scrape limited number of products\n";
    }
} catch (Exception $e) {
    Logger::error("Scraper error", ['error' => $e->getMessage()]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
