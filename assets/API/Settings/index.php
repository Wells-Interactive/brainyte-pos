<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/demo_mode.php';

/**
 * Centralized Settings API
 * 
 * GET  /API/Settings/index.php           - Get all settings
 * POST /API/Settings/index.php           - Update a setting (manager+)
 * GET  /API/Settings/index.php?key=foo   - Get single setting value
 */

$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET - Retrieve settings
// ============================================================
if ($method === 'GET') {
    $pdo = get_db();
    $singleKey = trim((string)($_GET['key'] ?? ''));

    if ($singleKey !== '') {
        $value = get_setting_value($pdo, $singleKey);
        if ($value !== null) {
            json_response([
                'success' => true,
                'data' => ['key' => $singleKey, 'value' => $value],
            ]);
        } else {
            json_response(['error' => 'Setting not found'], 404);
        }
        return;
    }

    // Return all settings
    $settings = get_setting_values($pdo);
    json_response(['success' => true, 'data' => ['settings' => $settings]]);
    return;
}

// ============================================================
// POST - Update a setting
// ============================================================
if ($method === 'POST') {
    require_manager();
    $authUser = get_auth_user();
    try {
        $body = get_json_body();
    } catch (JsonException $exception) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

    $settingKey = trim((string)($body['key'] ?? ''));
    $settingValue = trim((string)($body['value'] ?? ''));

    if ($settingKey === '') {
        json_response(['error' => 'Setting key is required'], 400);
    }

    $allowedKeys = [
        'direct_printing',
        'home_delivery_enabled',
        'restaurant_name',
        'logo_url',
        'vat_rate',
        'currency',
        'timezone',
        'printer_type',
        'footer_text',
        'delivery_base_price',
        'delivery_per_km_rate',
        'delivery_max_distance',
        'demo_mode_enabled',
    ];

    if (!in_array($settingKey, $allowedKeys, true)) {
        json_response(['error' => 'Invalid setting key'], 400);
    }

    if (in_array($settingKey, ['direct_printing', 'home_delivery_enabled', 'demo_mode_enabled'], true) && !in_array($settingValue, ['0', '1'], true)) {
        json_response(['error' => 'Setting value must be 0 or 1'], 400);
    }

    if ($settingKey === 'vat_rate') {
        $vatValue = (float)$settingValue;
        if ($vatValue < 0 || $vatValue > 100) {
            json_response(['error' => 'VAT rate must be between 0 and 100'], 400);
        }
        $settingValue = number_format($vatValue, 2, '.', '');
    }

    $now = date('Y-m-d H:i:s');
    $pdo = get_db();

    if ($settingKey === 'demo_mode_enabled') {
        require_admin();
        $authUser = get_auth_user();
        if (($authUser['role'] ?? '') !== 'owner' || !empty($authUser['is_demo'])) {
            json_response(['error' => 'Only the main owner can change Demo Mode'], 403);
        }

        $pdo->beginTransaction();
        try {
            $demoOwnerId = ensure_demo_dataset($pdo);

            $storageKey = restaurant_setting_storage_key($settingKey, $authUser);
            $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated_at) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
            $stmt->execute([':key' => $storageKey, ':value' => $settingValue, ':updated_at' => $now]);

            if ($settingValue === '0') {
                $pdo->exec('UPDATE auth_tokens SET revoked = 1 WHERE user_id IN (SELECT id FROM users WHERE is_demo = 1)');
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response(['error' => $exception->getMessage()], 422);
        }

        json_response([
            'success' => true,
            'data' => [
                'key' => $settingKey,
                'value' => $settingValue,
                'updated_at' => $now,
                'demo_owner_email' => DEMO_OWNER_EMAIL,
                'demo_owner_password' => DEMO_OWNER_PASSWORD,
                'demo_owner_id' => $demoOwnerId,
            ],
        ]);
    }

    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated_at) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
    $stmt->execute([':key' => $settingKey, ':value' => $settingValue, ':updated_at' => $now]);

    json_response([
        'success' => true,
        'data' => ['key' => $settingKey, 'value' => $settingValue, 'updated_at' => $now],
    ]);
    return;
}

http_response_code(405);
json_response(['error' => 'Method not allowed'], 405);
