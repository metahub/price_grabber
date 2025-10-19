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
$sellers = isset($_GET['sellers']) ? $_GET['sellers'] : [];

// Pagination parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 50;
$offset = ($page - 1) * $perPage;

// Build filters
$filters = [];
if (!empty($search)) {
    $filters['search'] = $search;
}
if ($parentFilter === 'parents_only') {
    $filters['parent_id'] = 'null';
}
if (!empty($sellers) && is_array($sellers)) {
    $filters['sellers'] = $sellers;
}

// Add pagination to filters
$filters['limit'] = $perPage;
$filters['offset'] = $offset;

// Get total count for pagination
$totalProducts = $productModel->countAll($filters);
$totalPages = ceil($totalProducts / $perPage);

// Get products
$products = $productModel->getAll($filters);

// Get latest prices for all products
$productsWithPrices = [];
foreach ($products as $product) {
    $latestPrice = $priceHistoryModel->getLatestByProduct($product['product_id']);
    $product['latest_price'] = $latestPrice;
    $productsWithPrices[] = $product;
}

// Get all available sellers for the filter
$allSellers = $priceHistoryModel->getAllSellers();

View::display('products.html.twig', [
    'current_page' => 'products',
    'products' => $productsWithPrices,
    'search' => $search,
    'parent_filter' => $parentFilter,
    'selected_sellers' => $sellers,
    'all_sellers' => $allSellers,
    'page' => $page,
    'per_page' => $perPage,
    'total_products' => $totalProducts,
    'total_pages' => $totalPages
]);
