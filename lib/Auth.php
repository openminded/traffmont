<?php
/**
 * Simple Authentication and Security Helper
 */
class Auth
{
    public static function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function check()
    {
        self::startSession();
        return !empty($_SESSION['traffmont_user_id']);
    }

    public static function requireLogin()
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function login($userId, $username)
    {
        self::startSession();
        $_SESSION['traffmont_user_id'] = $userId;
        $_SESSION['traffmont_username'] = $username;
    }

    public static function logout()
    {
        self::startSession();
        unset($_SESSION['traffmont_user_id']);
        unset($_SESSION['traffmont_username']);
        session_destroy();
    }

    public static function currentUser()
    {
        self::startSession();
        return $_SESSION['traffmont_username'] ?? null;
    }

    public static function generateCsrfToken()
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken($token)
    {
        self::startSession();
        return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

