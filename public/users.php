<?php

require_once __DIR__ . '/../bootstrap.php';

use PriceGrabber\Middleware\AuthMiddleware;
use PriceGrabber\Core\View;
use PriceGrabber\Controllers\UserController;

// Require authentication
AuthMiddleware::handle();

$userController = new UserController();

$error = null;
$success = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $username = trim($_POST['username'] ?? '') ?: null;

        if (empty($email) || empty($password)) {
            $error = 'Email and password are required';
        } else {
            $result = $userController->createUser($email, $password, $username);

            if ($result['success']) {
                $success = 'User created successfully';
            } else {
                $error = $result['error'];
            }
        }
    } elseif ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId > 0) {
            $result = $userController->deleteUser($userId);

            if ($result['success']) {
                $success = 'User deleted successfully';
            } else {
                $error = $result['error'];
            }
        } else {
            $error = 'Invalid user ID';
        }
    }
}

// Get all users
$users = $userController->getAllUsers();

View::display('users.html.twig', [
    'current_page' => 'users',
    'users' => $users,
    'error' => $error,
    'success' => $success
]);
