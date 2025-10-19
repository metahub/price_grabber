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
$productPriority = isset($_GET['product_priority']) ? $_GET['product_priority'] : '';

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
if (!empty($productPriority)) {
    $filters['product_priority'] = $productPriority;
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

// Get all product priorities for the filter
$allPriorities = $productModel->getProductPriorities();

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Get ALL filtered products (remove pagination)
    $exportFilters = $filters;
    unset($exportFilters['limit']);
    unset($exportFilters['offset']);

    $exportProducts = $productModel->getAll($exportFilters);

    // Get prices for all products
    $exportData = [];
    foreach ($exportProducts as $product) {
        $latestPrice = $priceHistoryModel->getLatestByProduct($product['product_id']);
        $product['latest_price'] = $latestPrice;
        $exportData[] = $product;
    }

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=products_export_' . date('Y-m-d_H-i-s') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Write UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Write CSV header
    fputcsv($output, [
        'ID',
        'Product ID',
        'Parent ID',
        'SKU',
        'EAN',
        'Site',
        'Site Product ID',
        'Name',
        'Description',
        'URL',
        'Price',
        'UVP',
        'Site Status',
        'Product Priority',
        'Latest Price',
        'Latest Price Currency',
        'Latest Price Seller',
        'Latest Price Date',
        'Created At',
        'Updated At'
    ]);

    // Write data rows
    foreach ($exportData as $product) {
        fputcsv($output, [
            $product['id'],
            $product['product_id'],
            $product['parent_id'],
            $product['sku'],
            $product['ean'],
            $product['site'],
            $product['site_product_id'],
            $product['name'],
            $product['description'],
            $product['url'],
            $product['price'],
            $product['uvp'],
            $product['site_status'],
            $product['product_priority'],
            $product['latest_price']['price'] ?? '',
            $product['latest_price']['currency'] ?? '',
            $product['latest_price']['seller'] ?? '',
            $product['latest_price']['fetched_at'] ?? '',
            $product['created_at'],
            $product['updated_at']
        ]);
    }

    fclose($output);
    exit;
}

View::display('products.html.twig', [
    'current_page' => 'products',
    'products' => $productsWithPrices,
    'search' => $search,
    'parent_filter' => $parentFilter,
    'selected_sellers' => $sellers,
    'all_sellers' => $allSellers,
    'product_priority' => $productPriority,
    'all_priorities' => $allPriorities,
    'page' => $page,
    'per_page' => $perPage,
    'total_products' => $totalProducts,
    'total_pages' => $totalPages
]);
