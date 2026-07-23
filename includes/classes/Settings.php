<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Settings Management
 * 
 * Manages all application settings stored in the database.
 * Provides caching to avoid repeated database queries.
 */
class Settings
{
    private PDO $pdo;
    private array $cache = [];
    private bool $loaded = false;

    /** Allowed setting keys for validation */
    private const ALLOWED_KEYS = [
        'direct_printing',
        'restaurant_name',
        'logo_url',
        'vat_rate',
        'currency',
        'timezone',
        'printer_type',
        'footer_text',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Load all settings into cache.
     */
    public function loadAll(): array
    {
        if ($this->loaded) {
            return $this->cache;
        }

        try {
            $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM settings');
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $this->cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (\Throwable $e) {
            // Use defaults
        }

        // Ensure defaults exist
        $defaults = [
            'restaurant_name' => 'Restaurant POS',
            'logo_url' => '/assets/images/brainyte-icon.png',
            'vat_rate' => '0.00',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'printer_type' => 'thermal',
            'footer_text' => 'Powered by Brainyte',
            'direct_printing' => '0',
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($this->cache[$key])) {
                $this->cache[$key] = $value;
            }
        }

        $this->loaded = true;
        return $this->cache;
    }

    /**
     * Get a specific setting value.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        if (!$this->loaded) {
            $this->loadAll();
        }
        return $this->cache[$key] ?? $default;
    }

    /**
     * Get a setting as boolean.
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        return $value === '1' || $value === 'true' || $value === 'yes';
    }

    /**
     * Get a setting as float.
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        return (float)$value;
    }

    /**
     * Update a setting value.
     */
    public function set(string $key, string $value): void
    {
        if (!in_array($key, self::ALLOWED_KEYS, true)) {
            throw new \InvalidArgumentException("Invalid setting key: {$key}");
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) 
             VALUES (:key, :value, :updated_at) 
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([':key' => $key, ':value' => $value, ':updated_at' => $now]);

        // Update cache
        $this->cache[$key] = $value;
    }

    /**
     * Validate a setting value.
     */
    public static function validate(string $key, string $value): ?string
    {
        switch ($key) {
            case 'direct_printing':
                return in_array($value, ['0', '1'], true) ? null : 'Must be 0 or 1';
            case 'vat_rate':
                $vat = (float)$value;
                return ($vat >= 0 && $vat <= 100) ? null : 'VAT rate must be between 0 and 100';
            case 'restaurant_name':
                return mb_strlen($value) <= 100 ? null : 'Name too long (max 100)';
            case 'footer_text':
                return mb_strlen($value) <= 200 ? null : 'Footer text too long (max 200)';
            case 'timezone':
                $allowed = [
                    'Africa/Lagos', 'Africa/Accra', 'Africa/Nairobi', 'Africa/Cairo',
                    'Europe/London', 'America/New_York',
                ];
                return in_array($value, $allowed, true) ? null : 'Invalid timezone';
            case 'currency':
                return in_array($value, ['NGN', 'USD', 'GBP', 'EUR'], true) ? null : 'Invalid currency';
            case 'printer_type':
                return in_array($value, ['thermal', 'a4', 'receipt'], true) ? null : 'Invalid printer type';
            case 'logo_url':
                return mb_strlen($value) <= 500 ? null : 'Logo URL too long';
            default:
                return 'Unknown setting key';
        }
    }

    /**
     * Validate and set a setting.
     */
    public function validateAndSet(string $key, string $value): ?string
    {
        $error = self::validate($key, $value);
        if ($error !== null) {
            return $error;
        }
        $this->set($key, $value);
        return null;
    }
}
