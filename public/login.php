<?php

require_once __DIR__ . '/../bootstrap.php';

use PriceGrabber\Core\Auth;
use PriceGrabber\Core\View;

$auth = Auth::getInstance();

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = null;
$success = null;

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        // Attempt login
        $rememberDuration = $remember ? (60 * 60 * 24 * 30) : null; // 30 days
        $result = $auth->login($email, $password, $rememberDuration);

        if ($result['success']) {
            // Check if there's a redirect URL
            $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
            unset($_SESSION['redirect_after_login']);

            header('Location: ' . $redirect);
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

View::display('login.html.twig', [
    'error' => $error,
    'success' => $success
]);
