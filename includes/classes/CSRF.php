<?php
declare(strict_types=1);

namespace App;

/**
 * CSRF Protection
 * 
 * Handles generation and verification of CSRF tokens
 * for all data-changing forms and API requests.
 */
class CSRF
{
    /**
     * Generate a new CSRF token or retrieve existing one.
     */
    public static function generate(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify a CSRF token against the session token.
     */
    public static function verify(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Require a valid CSRF token from the current request.
     * Sends a 419 HTTP response if token is invalid.
     */
    public static function requireValid(): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = $body['csrf_token'] ?? $_POST['csrf_token'] ?? '';

        if (!self::verify($token)) {
            http_response_code(419);
            echo json_encode([
                'success' => false,
                'data' => null,
                'error' => 'CSRF token mismatch',
                'meta' => null,
            ]);
            exit;
        }
    }

    /**
     * Get a hidden input field HTML for CSRF token.
     */
    public static function inputField(): string
    {
        $token = self::generate();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
    }

    /**
     * Get CSRF token for JSON requests.
     */
    public static function getToken(): string
    {
        return self::generate();
    }
}
