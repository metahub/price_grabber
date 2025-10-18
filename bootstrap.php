<?php

/**
 * Bootstrap file for Price Grabber
 * Handles autoloading for both Composer and custom classes
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load Composer autoloader if available
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Fallback to custom autoloader if Composer hasn't been run yet
    spl_autoload_register(function ($class) {
        $prefix = 'PriceGrabber\\';
        $base_dir = __DIR__ . '/src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });

    // Warn if Monolog is needed but not available
    if (!class_exists('Monolog\Logger')) {
        if (php_sapi_name() === 'cli') {
            echo "[!] Warning: Composer dependencies not installed. Run 'composer install' for full functionality.\n";
        }
    }
}

// Load configuration
require_once __DIR__ . '/src/Core/Config.php';
