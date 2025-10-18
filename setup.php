#!/usr/bin/env php
<?php

/**
 * Setup script for Price Grabber
 * This script helps you initialize the application
 */

echo "==============================================\n";
echo "    Price Grabber - Setup Script\n";
echo "==============================================\n\n";

// Check if .env file exists
if (!file_exists(__DIR__ . '/.env')) {
    echo "[!] .env file not found\n";
    echo "[*] Creating .env from .env.example...\n";

    if (file_exists(__DIR__ . '/.env.example')) {
        copy(__DIR__ . '/.env.example', __DIR__ . '/.env');
        echo "[✓] .env file created\n";
        echo "[!] Please edit .env file with your database credentials\n\n";
    } else {
        echo "[✗] .env.example not found\n";
        exit(1);
    }
} else {
    echo "[✓] .env file exists\n\n";
}

// Load .env
require_once __DIR__ . '/src/Core/Config.php';
use PriceGrabber\Core\Config;

try {
    Config::load();
    echo "[✓] Configuration loaded\n\n";
} catch (Exception $e) {
    echo "[✗] Error loading configuration: " . $e->getMessage() . "\n";
    exit(1);
}

// Test database connection
echo "Testing database connection...\n";

try {
    $host = Config::get('DB_HOST');
    $port = Config::get('DB_PORT', '3306');
    $socket = Config::get('DB_SOCKET');
    $dbname = Config::get('DB_NAME');
    $username = Config::get('DB_USER');
    $password = Config::get('DB_PASSWORD', '');

    // Use socket if provided, otherwise use host:port
    if ($socket) {
        $dsn = "mysql:unix_socket={$socket};charset=utf8mb4";
        echo "Connecting via socket: {$socket}\n";
    } else {
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        echo "Connecting to: {$host}:{$port}\n";
    }

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Verify which port we connected to
    $portCheck = $pdo->query("SHOW VARIABLES LIKE 'port'")->fetch(PDO::FETCH_ASSOC);
    echo "[✓] Successfully connected to MySQL server on port: {$portCheck['Value']}\n";

    // Check if database exists
    $stmt = $pdo->query("SHOW DATABASES LIKE '{$dbname}'");
    $dbExists = $stmt->fetch();

    if (!$dbExists) {
        echo "[!] Database '{$dbname}' does not exist\n";
        echo "[*] Creating database...\n";

        $pdo->exec("CREATE DATABASE `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "[✓] Database created\n";
    } else {
        echo "[✓] Database '{$dbname}' exists\n";
    }

    // Connect to the specific database
    if ($socket) {
        $dsn = "mysql:unix_socket={$socket};dbname={$dbname};charset=utf8mb4";
    } else {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    }

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Check if tables exist
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        echo "[!] No tables found in database\n";
        echo "[*] Would you like to import the schema? (y/n): ";

        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));

        if (strtolower($line) === 'y') {
            echo "[*] Importing schema...\n";

            $schemaFile = __DIR__ . '/database/schema.sql';
            if (!file_exists($schemaFile)) {
                echo "[✗] Schema file not found at {$schemaFile}\n";
                exit(1);
            }

            $sql = file_get_contents($schemaFile);
            $statements = explode(';', $sql);

            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    $pdo->exec($statement);
                }
            }

            echo "[✓] Schema imported successfully\n";
        }
    } else {
        echo "[✓] Found " . count($tables) . " tables: " . implode(', ', $tables) . "\n";
    }

} catch (PDOException $e) {
    echo "[✗] Database error: " . $e->getMessage() . "\n";
    echo "\nPlease check your database credentials in .env file\n";
    exit(1);
}

echo "\n==============================================\n";
echo "    Setup Complete!\n";
echo "==============================================\n\n";

echo "Next steps:\n";
echo "1. Start your web server (Apache/Nginx) or use PHP built-in server:\n";
echo "   cd public && php -S localhost:8000\n\n";
echo "2. Visit http://localhost:8000 in your browser\n\n";
echo "3. Configure scraper patterns for your target websites\n\n";
echo "4. Import products using the bulk import feature\n\n";
echo "5. Run the scraper:\n";
echo "   php scrape.php --all\n\n";

echo "For more information, see README.md\n\n";
