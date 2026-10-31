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

$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET - Retrieve settings
// ============================================================
if ($method === 'GET') {
    $pdo = get_db();
    $singleKey = trim((string)($_GET['key'] ?? ''));

    if ($singleKey !== '') {
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
        // Demo access is an owner-level decision. The demo user deliberately
        // retains the normal owner role for existing web/Flutter navigation.
        require_admin();
        $password = (string)($body['demo_owner_password'] ?? '');

        $pdo->beginTransaction();
        try {
            $demoUserStmt = $pdo->query('SELECT id, email FROM users WHERE is_demo = 1 LIMIT 1');
            $demoUser = $demoUserStmt->fetch();

            if ($settingValue === '1' && !$demoUser) {
                if (strlen($password) < 12) {
                    throw new InvalidArgumentException('Choose a demo owner password of at least 12 characters.');
                }

                $email = 'demo.owner@brainyte.local';
                $emailCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $emailCheck->execute([':email' => $email]);
                if ($emailCheck->fetch()) {
                    throw new InvalidArgumentException('The reserved demo owner email is already in use.');
                }

                $create = $pdo->prepare(
                    'INSERT INTO users (name, email, password_hash, role, is_demo, created_at)
                     VALUES (:name, :email, :password_hash, :role, 1, :created_at)'
                );
                $create->execute([
                    ':name' => 'Brainyte Demo Owner',
                    ':email' => $email,
                    ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    ':role' => 'owner',
                    ':created_at' => $now,
                ]);
                $demoUser = ['id' => (int)$pdo->lastInsertId(), 'email' => $email];
            } elseif ($settingValue === '1' && $password !== '') {
                if (strlen($password) < 12) {
                    throw new InvalidArgumentException('Choose a demo owner password of at least 12 characters.');
                }
                $reset = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id AND is_demo = 1');
                $reset->execute([
                    ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    ':id' => $demoUser['id'],
                ]);
            }

            $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated_at) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
            $stmt->execute([':key' => $settingKey, ':value' => $settingValue, ':updated_at' => $now]);

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
                'demo_owner_email' => $demoUser['email'] ?? 'demo.owner@brainyte.local',
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
