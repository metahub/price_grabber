<?php

namespace PriceGrabber\Controllers;

use PriceGrabber\Core\Auth;
use PriceGrabber\Core\Logger;

class UserController
{
    private $auth;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
    }

    public function getAllUsers()
    {
        return $this->auth->getAllUsers();
    }

    public function createUser($email, $password, $username = null)
    {
        Logger::info('Creating new user', ['email' => $email]);

        $result = $this->auth->createUser($email, $password, $username);

        if ($result['success']) {
            Logger::info('User created successfully', ['userId' => $result['userId'], 'email' => $email]);
        } else {
            Logger::warning('User creation failed', ['email' => $email, 'error' => $result['error']]);
        }

        return $result;
    }

    public function deleteUser($userId)
    {
        Logger::info('Deleting user', ['userId' => $userId]);

        $result = $this->auth->deleteUser($userId);

        if ($result['success']) {
            Logger::info('User deleted successfully', ['userId' => $userId]);
        } else {
            Logger::warning('User deletion failed', ['userId' => $userId, 'error' => $result['error']]);
        }

        return $result;
    }

    public function changePassword($userId, $newPassword)
    {
        Logger::info('Changing password for user', ['userId' => $userId]);

        $result = $this->auth->changePassword($userId, $newPassword);

        if ($result['success']) {
            Logger::info('Password changed successfully', ['userId' => $userId]);
        } else {
            Logger::warning('Password change failed', ['userId' => $userId, 'error' => $result['error']]);
        }

        return $result;
    }
}
