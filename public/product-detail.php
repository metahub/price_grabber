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

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$productId) {
    header('Location: products.php');
    exit;
}

$product = $productModel->findById($productId);
if (!$product) {
    header('Location: products.php');
    exit;
}

$priceHistory = $priceHistoryModel->getByProduct($product['product_id'], 30);
$children = $productModel->getChildren($product['product_id']);

// Get latest price for each child
foreach ($children as &$child) {
    $child['latest_price'] = $priceHistoryModel->getLatestByProduct($child['product_id']);
}

$parent = $product['parent_id'] ? $productModel->findById($product['parent_id']) : null;

View::display('product-detail.html.twig', [
    'current_page' => 'products',
    'product' => $product,
    'price_history' => $priceHistory,
    'children' => $children,
    'parent' => $parent
]);
