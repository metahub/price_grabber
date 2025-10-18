<?php

require_once __DIR__ . '/../bootstrap.php';

use PriceGrabber\Core\Auth;

$auth = Auth::getInstance();
$auth->logout();

// Redirect to login page
header('Location: login.php');
exit;
