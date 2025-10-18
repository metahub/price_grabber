<?php

require_once __DIR__ . '/../bootstrap.php';

use PriceGrabber\Middleware\AuthMiddleware;
use PriceGrabber\Core\View;
use PriceGrabber\Controllers\BulkImportController;

// Require authentication
AuthMiddleware::handle();

$controller = new BulkImportController();
$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_data'])) {
    $results = $controller->import($_POST['bulk_data']);
}


View::display('bulk-import.html.twig', [
    'current_page' => 'bulk-import',
    'results' => $results,
    'template' => $controller->getTemplate()
]);
