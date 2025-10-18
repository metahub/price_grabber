#!/usr/bin/env php
<?php

require_once __DIR__ . '/bootstrap.php';

use PriceGrabber\Core\Scraper;
use PriceGrabber\Core\Logger;

// Parse command line arguments
$options = getopt('u:a', ['url:', 'all']);

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
    } elseif (isset($options['a']) || isset($options['all'])) {
        // Scrape all products
        echo "Scraping all products...\n";
        Logger::info("Starting scrape all products");

        $results = $scraper->scrapeProducts();

        $success = count(array_filter($results, fn($r) => !isset($r['error'])));
        $failed = count($results) - $success;

        echo "\nCompleted!\n";
        echo "Total: " . count($results) . " products\n";
        echo "Success: {$success}\n";
        echo "Failed: {$failed}\n";

        Logger::info("Scrape all products completed", [
            'total' => count($results),
            'success' => $success,
            'failed' => $failed
        ]);
    } else {
        echo "Usage:\n";
        echo "  php scrape.php -u <url>     Scrape a single URL\n";
        echo "  php scrape.php --url=<url>  Scrape a single URL\n";
        echo "  php scrape.php -a           Scrape all products\n";
        echo "  php scrape.php --all        Scrape all products\n";
    }
} catch (Exception $e) {
    Logger::error("Scraper error", ['error' => $e->getMessage()]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
