<?php

require_once __DIR__ . '/../bootstrap.php';

use PriceGrabber\Middleware\AuthMiddleware;
use PriceGrabber\Core\View;
use PriceGrabber\Models\Settings;

// Require authentication
AuthMiddleware::handle();

$settingsModel = new Settings();

$message = null;
$messageType = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update') {
        try {
            // Update all submitted settings
            foreach ($_POST as $key => $value) {
                if ($key !== 'action' && strpos($key, 'setting_') === 0) {
                    $settingKey = substr($key, 8); // Remove 'setting_' prefix
                    $settingsModel->set($settingKey, $value);
                }
            }

            $message = 'Settings updated successfully';
            $messageType = 'success';
        } catch (\Exception $e) {
            $message = 'Error updating settings: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get all settings grouped by category
$allSettings = $settingsModel->getAll();
$settingsByCategory = [];

foreach ($allSettings as $setting) {
    $category = $setting['category'];
    if (!isset($settingsByCategory[$category])) {
        $settingsByCategory[$category] = [];
    }
    $settingsByCategory[$category][] = $setting;
}

View::display('settings.html.twig', [
    'current_page' => 'settings',
    'settings_by_category' => $settingsByCategory,
    'message' => $message,
    'message_type' => $messageType
]);
