<?php

require_once __DIR__ . '/../bootstrap.php';

use PriceGrabber\Middleware\AuthMiddleware;
use PriceGrabber\Core\View;
use PriceGrabber\Models\ScraperRun;

// Require authentication
AuthMiddleware::handle();

$scraperRunModel = new ScraperRun();

// Pagination parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Get scraper runs
$runs = $scraperRunModel->getAll($perPage, $offset);

// Get total count for pagination
$totalRuns = $scraperRunModel->count();
$totalPages = ceil($totalRuns / $perPage);

// Get statistics
$statistics = $scraperRunModel->getStatistics();

View::display('scraper-runs.html.twig', [
    'current_page' => 'scraper-runs',
    'runs' => $runs,
    'statistics' => $statistics,
    'page' => $page,
    'per_page' => $perPage,
    'total_runs' => $totalRuns,
    'total_pages' => $totalPages
]);
