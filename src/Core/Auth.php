<?php

namespace PriceGrabber\Core;

use Delight\Auth\Auth as DelightAuth;

class Auth
{
    private static $instance = null;
    private $auth;

    private function __construct()
    {
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        $this->auth = new DelightAuth($pdo);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getAuth()
    {
        return $this->auth;
    }

    public function isLoggedIn()
    {
        return $this->auth->isLoggedIn();
    }

    public function getUserId()
    {
        return $this->auth->getUserId();
    }

    public function getEmail()
    {
        return $this->auth->getEmail();
    }

    public function getUsername()
    {
        return $this->auth->getUsername();
    }

    public function login($email, $password, $rememberDuration = null)
    {
        try {
            if ($rememberDuration) {
                $this->auth->login($email, $password, $rememberDuration);
            } else {
                $this->auth->login($email, $password);
            }
            return ['success' => true];
        } catch (\Delight\Auth\InvalidEmailException $e) {
            return ['success' => false, 'error' => 'Invalid email address'];
        } catch (\Delight\Auth\InvalidPasswordException $e) {
            return ['success' => false, 'error' => 'Invalid password'];
        } catch (\Delight\Auth\EmailNotVerifiedException $e) {
            return ['success' => false, 'error' => 'Email not verified'];
        } catch (\Delight\Auth\TooManyRequestsException $e) {
            return ['success' => false, 'error' => 'Too many requests. Please try again later'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'An error occurred during login: ' . $e->getMessage()];
        }
    }

    public function logout()
    {
        try {
            $this->auth->logOut();
            $this->auth->destroySession();
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Logout failed'];
        }
    }

    public function createUser($email, $password, $username = null)
    {
        try {
            $userId = $this->auth->admin()->createUser($email, $password, $username);

            // Mark user as verified immediately (no email confirmation needed)
            $db = Database::getInstance();
            $db->execute('UPDATE users SET verified = 1 WHERE id = ?', [$userId]);

            return ['success' => true, 'userId' => $userId];
        } catch (\Delight\Auth\InvalidEmailException $e) {
            return ['success' => false, 'error' => 'Invalid email address'];
        } catch (\Delight\Auth\InvalidPasswordException $e) {
            return ['success' => false, 'error' => 'Invalid password (min. 8 characters)'];
        } catch (\Delight\Auth\UserAlreadyExistsException $e) {
            return ['success' => false, 'error' => 'User with this email already exists'];
        } catch (\Delight\Auth\TooManyRequestsException $e) {
            return ['success' => false, 'error' => 'Too many requests. Please try again later'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Failed to create user: ' . $e->getMessage()];
        }
    }

    public function deleteUser($userId)
    {
        try {
            $this->auth->admin()->deleteUserById($userId);
            return ['success' => true];
        } catch (\Delight\Auth\UnknownIdException $e) {
            return ['success' => false, 'error' => 'User not found'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Failed to delete user'];
        }
    }

    public function changePassword($userId, $newPassword)
    {
        try {
            $this->auth->admin()->changePasswordForUserById($userId, $newPassword);
            return ['success' => true];
        } catch (\Delight\Auth\UnknownIdException $e) {
            return ['success' => false, 'error' => 'User not found'];
        } catch (\Delight\Auth\InvalidPasswordException $e) {
            return ['success' => false, 'error' => 'Invalid password (min. 8 characters)'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Failed to change password: ' . $e->getMessage()];
        }
    }

    public function getAllUsers()
    {
        $db = Database::getInstance();
        return $db->fetchAll('SELECT id, email, username, registered, last_login, verified FROM users ORDER BY registered DESC');
    }

    public function requireAuth()
    {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }
}
