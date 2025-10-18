<?php

require_once __DIR__ . '/../bootstrap.php';

use PriceGrabber\Middleware\AuthMiddleware;
use PriceGrabber\Core\Config;
use PriceGrabber\Core\View;

// Require authentication
AuthMiddleware::handle();

Config::load();

// Optional: Get stats for the dashboard
$stats = [
    'total_products' => 0,
    'active_scrapers' => 0,
    'last_scrape' => 'Never'
];

View::display('home.html.twig', [
    'current_page' => 'home',
    'stats' => $stats
]);
