<?php

require_once __DIR__ . '/../bootstrap.php';

use PriceGrabber\Middleware\AuthMiddleware;
use PriceGrabber\Core\View;
use PriceGrabber\Models\ScraperConfig;

// Require authentication
AuthMiddleware::handle();

$configModel = new ScraperConfig();
$message = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] === 'add') {
                $configModel->create($_POST);
                $message = ['type' => 'success', 'text' => 'Configuration added successfully!'];
            } elseif ($_POST['action'] === 'update') {
                $configModel->update($_POST['id'], $_POST);
                $message = ['type' => 'success', 'text' => 'Configuration updated successfully!'];
            } elseif ($_POST['action'] === 'delete') {
                $configModel->delete($_POST['id']);
                $message = ['type' => 'success', 'text' => 'Configuration deleted successfully!'];
            }
        } catch (Exception $e) {
            $message = ['type' => 'error', 'text' => 'Error: ' . $e->getMessage()];
        }
    }
}

$configs = $configModel->getAll(false);

View::display('scraper-config.html.twig', [
    'current_page' => 'scraper-config',
    'message' => $message,
    'configs' => $configs
]);
