<?php
declare(strict_types=1);

namespace App;

/**
 * Input Validator
 * 
 * Provides static methods for validating and sanitizing
 * all user inputs across the application.
 */
class Validator
{
    /**
     * Validate an email address.
     */
    public static function email(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate that a string is not empty after trimming.
     */
    public static function notEmpty(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    /**
     * Validate a numeric value is positive.
     */
    public static function positiveNumeric($value): bool
    {
        return is_numeric($value) && (float)$value > 0;
    }

    /**
     * Validate a value is within a range.
     */
    public static function inRange($value, $min, $max): bool
    {
        return $value >= $min && $value <= $max;
    }

    /**
     * Validate that a value is in a list of allowed values.
     */
    public static function inArray($value, array $allowed): bool
    {
        return in_array($value, $allowed, true);
    }

    /**
     * Sanitize a string for safe HTML output (XSS prevention).
     */
    public static function sanitize(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitize for use in JSON responses.
     */
    public static function sanitizeJson($value): mixed
    {
        if (is_string($value)) {
            return htmlspecialchars_decode(self::sanitize($value), ENT_QUOTES);
        }
        if (is_array($value)) {
            return array_map([self::class, 'sanitizeJson'], $value);
        }
        return $value;
    }

    /**
     * Validate a menu category.
     */
    public static function menuCategory(string $category): bool
    {
        $allowed = [
            'beer', 'malt', 'soft-drinks', 'water', 'energy-drinks', 'juice',
            'spirits', 'ready-to-drink', 'rice', 'pepper-soup', 'grills',
            'soups', 'swallow', 'extras', 'cigarettes',
        ];
        return in_array($category, $allowed, true);
    }

    /**
     * Validate order status.
     */
    public static function orderStatus(string $status): bool
    {
        return in_array($status, ['pending', 'preparing', 'ready', 'served', 'completed'], true);
    }

    /**
     * Validate order item status.
     */
    public static function orderItemStatus(string $status): bool
    {
        return in_array($status, ['pending', 'preparing', 'ready', 'served', 'completed'], true);
    }

    /**
     * Validate payment method.
     */
    public static function paymentMethod(string $method): bool
    {
        return in_array($method, ['cash', 'pos', 'transfer', 'pending'], true);
    }

    /**
     * Validate user role.
     */
    public static function userRole(string $role): bool
    {
        return in_array($role, ['waiter', 'kitchen', 'bar', 'manager', 'supervisor', 'admin', 'owner'], true);
    }

    /**
     * Validate table status.
     */
    public static function tableStatus(string $status): bool
    {
        return in_array($status, ['available', 'occupied', 'reserved', 'closed'], true);
    }

    /**
     * Validate integer ID.
     */
    public static function positiveInt($value): bool
    {
        return is_numeric($value) && (int)$value > 0;
    }

    /**
     * Get string length with validation.
     */
    public static function maxLength(string $value, int $max): bool
    {
        return mb_strlen($value) <= $max;
    }

    /**
     * Validate a price value (0.00 to 999999.99).
     */
    public static function price($value): bool
    {
        return is_numeric($value) && (float)$value >= 0 && (float)$value <= 999999.99;
    }
}
