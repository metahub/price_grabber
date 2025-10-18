<?php

require_once __DIR__ . '/../bootstrap.php';

use PriceGrabber\Middleware\AuthMiddleware;
use PriceGrabber\Core\View;
use PriceGrabber\Models\Product;
use PriceGrabber\Models\PriceHistory;

// Require authentication
AuthMiddleware::handle();

$productModel = new Product();
$priceHistoryModel = new PriceHistory();

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$parentFilter = isset($_GET['parent_filter']) ? $_GET['parent_filter'] : '';

// Build filters
$filters = [];
if (!empty($search)) {
    $filters['search'] = $search;
}
if ($parentFilter === 'parents_only') {
    $filters['parent_id'] = 'null';
}

// Get products
$products = $productModel->getAll($filters);

// Get latest prices for all products
$productsWithPrices = [];
foreach ($products as $product) {
    $latestPrice = $priceHistoryModel->getLatestByProduct($product['product_id']);
    $product['latest_price'] = $latestPrice;
    $productsWithPrices[] = $product;
}


View::display('products.html.twig', [
    'current_page' => 'products',
    'products' => $productsWithPrices,
    'search' => $search,
    'parent_filter' => $parentFilter
]);
