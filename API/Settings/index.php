<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

/**
 * Centralized Settings API
 * 
 * GET  /API/Settings/index.php           - Get all settings
 * POST /API/Settings/index.php           - Update a setting (manager+)
 * GET  /API/Settings/index.php?key=foo   - Get single setting value
 */

date_default_timezone_set('Africa/Lagos');

$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET - Retrieve settings
// ============================================================
if ($method === 'GET') {
    $pdo = get_db();
    $singleKey = trim((string)($_GET['key'] ?? ''));

    if ($singleKey !== '') {
        // Return single setting
        $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => $singleKey]);
        $row = $stmt->fetch();
        if ($row) {
            json_response([
                'success' => true,
                'data' => ['key' => $row['setting_key'], 'value' => $row['setting_value']],
            ]);
        } else {
            json_response(['error' => 'Setting not found'], 404);
        }
        return;
    }

    // Return all settings
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $rows = $stmt->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    json_response(['success' => true, 'data' => ['settings' => $settings]]);
    return;
}

// ============================================================
// POST - Update a setting
// ============================================================
if ($method === 'POST') {
    require_manager();
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

    // Allowed settings keys
    $allowedKeys = [
        'direct_printing',
        'restaurant_name',
        'logo_url',
        'vat_rate',
        'currency',
        'timezone',
        'printer_type',
        'footer_text',
    ];

    if (!in_array($settingKey, $allowedKeys, true)) {
        json_response(['error' => 'Invalid setting key'], 400);
    }

    // Validate values
    if ($settingKey === 'direct_printing' && !in_array($settingValue, ['0', '1'], true)) {
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
