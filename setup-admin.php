#!/usr/bin/env php
<?php

/**
 * Setup Admin User Script
 * Creates the first admin user for Price Grabber
 */

require_once __DIR__ . '/bootstrap.php';

use PriceGrabber\Core\Auth;
use PriceGrabber\Core\Database;

// Make sure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

echo "\n";
echo "========================================\n";
echo "  Price Grabber - Admin User Setup\n";
echo "========================================\n";
echo "\n";

try {
    // Check database connection
    $db = Database::getInstance();
    echo "✓ Database connection successful\n\n";

    // Check if users table exists
    $tables = $db->fetchAll("SHOW TABLES LIKE 'users'");
    if (empty($tables)) {
        echo "✗ Users table does not exist. Please run the database migrations first:\n";
        echo "  mysql -u [user] -p [database] < database/auth_schema.sql\n\n";
        exit(1);
    }

    // Check if any users exist
    $userCount = $db->fetchOne("SELECT COUNT(*) as count FROM users");
    if ($userCount['count'] > 0) {
        echo "⚠ Warning: There are already {$userCount['count']} user(s) in the database.\n";
        echo "Do you want to create another user? (y/n): ";
        $confirm = trim(fgets(STDIN));
        if (strtolower($confirm) !== 'y') {
            echo "\nAborted.\n\n";
            exit(0);
        }
        echo "\n";
    }

    // Get email
    echo "Enter admin email: ";
    $email = trim(fgets(STDIN));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "✗ Invalid email address\n\n";
        exit(1);
    }

    // Get password
    echo "Enter password (min. 8 characters): ";
    $password = trim(fgets(STDIN));

    if (strlen($password) < 8) {
        echo "✗ Password must be at least 8 characters\n\n";
        exit(1);
    }

    // Get username (optional)
    echo "Enter username (optional, press Enter to skip): ";
    $username = trim(fgets(STDIN));
    $username = empty($username) ? null : $username;

    echo "\n";
    echo "Creating admin user...\n";

    // Create user
    $auth = Auth::getInstance();
    $result = $auth->createUser($email, $password, $username);

    if ($result['success']) {
        echo "✓ Admin user created successfully!\n";
        echo "\n";
        echo "Login credentials:\n";
        echo "  Email: {$email}\n";
        if ($username) {
            echo "  Username: {$username}\n";
        }
        echo "\n";
        echo "You can now login at: http://localhost:8890/login.php\n";
        echo "\n";
    } else {
        echo "✗ Failed to create user: {$result['error']}\n\n";
        exit(1);
    }

} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
    exit(1);
}
