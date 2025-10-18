<?php

namespace PriceGrabber\Middleware;

use PriceGrabber\Core\Auth;

class AuthMiddleware
{
    public static function handle()
    {
        $auth = Auth::getInstance();

        if (!$auth->isLoggedIn()) {
            // Store the intended destination
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];

            // Redirect to login page
            header('Location: /login.php');
            exit;
        }
    }
}
