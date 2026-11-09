<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$auth = require_role(['super_admin']);
$pdo = get_db();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $search = trim((string)($_GET['search'] ?? ''));
    if (strlen($search) > 120) {
        json_response(['error' => 'Search text must be 120 characters or fewer'], 422);
    }
    $query = 
        'SELECT r.id, r.name, r.address, r.phone_number, r.email, r.is_active, r.archived_at,
                r.chain_id, c.name AS chain_name, r.created_at
         FROM restaurants r
         LEFT JOIN restaurant_chains c ON c.id = r.chain_id
         ';
    $params = [];
    if ($search !== '') {
        $query .= 'WHERE LOWER(CONCAT_WS(" ", r.name, r.address, r.phone_number, r.email, c.name, r.id)) LIKE :search ';
        $params[':search'] = '%' . mb_strtolower($search) . '%';
    }
    $query .= 'ORDER BY r.archived_at IS NOT NULL, c.name, r.name, r.id';
    $restaurantStmt = $pdo->prepare($query);
    $restaurantStmt->execute($params);
    $restaurants = $restaurantStmt->fetchAll();
    $chains = $pdo->query('SELECT id, name FROM restaurant_chains ORDER BY name, id')->fetchAll();
    json_response(['success' => true, 'data' => ['restaurants' => $restaurants, 'chains' => $chains]]);
}

if (!in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $body = get_json_body();
} catch (JsonException $exception) {
    json_response(['error' => 'Invalid JSON body'], 400);
}

if (($auth['auth_type'] ?? '') === 'session' && !verify_csrf_token($body['csrf_token'] ?? null)) {
    json_response(['error' => 'CSRF token mismatch'], 419);
}

if ($method === 'POST') {
    $name = trim((string)($body['name'] ?? ''));
    $chainName = trim((string)($body['chain_name'] ?? ''));
    $ownerName = trim((string)($body['owner_name'] ?? ''));
    $ownerEmail = strtolower(trim((string)($body['owner_email'] ?? '')));
    $ownerPassword = (string)($body['owner_password'] ?? '');
    if ($name === '' || $ownerName === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || strlen($ownerPassword) < 8) {
        json_response(['error' => 'Restaurant name, owner name, valid owner email, and an owner password of at least 8 characters are required'], 422);
    }

    $existingOwner = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = :email LIMIT 1');
    $existingOwner->execute([':email' => $ownerEmail]);
    if ($existingOwner->fetch()) {
        json_response(['error' => 'A user with that owner email already exists'], 409);
    }

    try {
        $pdo->beginTransaction();
        $now = date('Y-m-d H:i:s');
        $chainId = null;
        if ($chainName !== '') {
            $findChain = $pdo->prepare('SELECT id FROM restaurant_chains WHERE name = :name LIMIT 1');
            $findChain->execute([':name' => $chainName]);
            $chainId = $findChain->fetchColumn();
            if ($chainId === false) {
                $createChain = $pdo->prepare(
                    'INSERT INTO restaurant_chains (name, created_at, updated_at) VALUES (:name, :created_at, :updated_at)'
                );
                $createChain->execute([':name' => $chainName, ':created_at' => $now, ':updated_at' => $now]);
                $chainId = (int)$pdo->lastInsertId();
            } else {
                $chainId = (int)$chainId;
            }
        }

        $createRestaurant = $pdo->prepare(
            'INSERT INTO restaurants (chain_id, name, address, phone_number, email, is_active, created_at, updated_at)
             VALUES (:chain_id, :name, :address, :phone, :email, 1, :created_at, :updated_at)'
        );
        $createRestaurant->execute([
            ':chain_id' => $chainId,
            ':name' => $name,
            ':address' => trim((string)($body['address'] ?? '')) ?: null,
            ':phone' => trim((string)($body['phone_number'] ?? '')) ?: null,
            ':email' => trim((string)($body['email'] ?? '')) ?: null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $restaurantId = (int)$pdo->lastInsertId();

        $createOwner = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, restaurant_id, created_at)
             VALUES (:name, :email, :password_hash, :role, :restaurant_id, :created_at)'
        );
        $createOwner->execute([
            ':name' => $ownerName,
            ':email' => $ownerEmail,
            ':password_hash' => password_hash($ownerPassword, PASSWORD_BCRYPT),
            ':role' => 'owner',
            ':restaurant_id' => $restaurantId,
            ':created_at' => $now,
        ]);
        $createTable = $pdo->prepare(
            'INSERT INTO restaurant_tables (name, status, restaurant_id, created_at)
             VALUES (:name, :status, :restaurant_id, :created_at)'
        );
        for ($number = 1; $number <= 20; $number++) {
            $createTable->execute([
                ':name' => 'Table ' . $number,
                ':status' => 'available',
                ':restaurant_id' => $restaurantId,
                ':created_at' => $now,
            ]);
        }
        if ($chainId !== null) {
            $shareChainMenu = $pdo->prepare(
                'UPDATE menu_items SET chain_id = :chain_id
                 WHERE restaurant_id IN (SELECT id FROM restaurants WHERE chain_id = :restaurant_chain_id)'
            );
            $shareChainMenu->execute([
                ':chain_id' => $chainId,
                ':restaurant_chain_id' => $chainId,
            ]);
            $createChainInventory = $pdo->prepare(
                "INSERT IGNORE INTO inventory_items
                    (menu_item_id, current_stock, min_stock_level, unit, restaurant_id, created_at, updated_at)
                 SELECT mi.id, 0, 10, 'pieces', r.id, :created_at, :updated_at
                 FROM menu_items mi
                 JOIN restaurants r ON r.chain_id = mi.chain_id
                 WHERE mi.chain_id = :chain_id"
            );
            $createChainInventory->execute([
                ':created_at' => $now,
                ':updated_at' => $now,
                ':chain_id' => $chainId,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException && (string)$exception->getCode() === '23000') {
            json_response(['error' => 'A user with that owner email already exists'], 409);
        }
        error_log('Restaurant creation failed: ' . $exception->getMessage());
        json_response(['error' => 'Unable to create restaurant and owner account'], 500);
    }

    json_response(['success' => true, 'data' => ['restaurant_id' => $restaurantId]], 201);
}

$restaurantId = filter_var($body['restaurant_id'] ?? null, FILTER_VALIDATE_INT);
if (!$restaurantId || $restaurantId < 1) {
    json_response(['error' => 'A valid restaurant_id is required'], 422);
}

$restaurantStmt = $pdo->prepare('SELECT id FROM restaurants WHERE id = :id AND archived_at IS NULL LIMIT 1');
$restaurantStmt->execute([':id' => $restaurantId]);
if (!$restaurantStmt->fetchColumn()) {
    json_response(['error' => 'Restaurant not found'], 404);
}

if ($method === 'DELETE') {
    $stmt = $pdo->prepare(
        'UPDATE restaurants SET is_active = 0, archived_at = :archived_at, updated_at = :updated_at
         WHERE id = :id AND archived_at IS NULL'
    );
    $now = date('Y-m-d H:i:s');
    $stmt->execute([':archived_at' => $now, ':updated_at' => $now, ':id' => $restaurantId]);
} else {
    $action = (string)($body['action'] ?? '');
    $now = date('Y-m-d H:i:s');
    if ($action === 'toggle') {
        $stmt = $pdo->prepare('UPDATE restaurants SET is_active = IF(is_active = 1, 0, 1), updated_at = :now WHERE id = :id');
        $stmt->execute([':now' => $now, ':id' => $restaurantId]);
    } elseif ($action === 'link_chain') {
        $chainId = $body['chain_id'] ?? null;
        if ($chainId !== null && $chainId !== '') {
            $chainId = filter_var($chainId, FILTER_VALIDATE_INT);
            if (!$chainId || $chainId < 1) {
                json_response(['error' => 'A valid chain_id or an empty value is required'], 422);
            }
            $chainStmt = $pdo->prepare('SELECT id FROM restaurant_chains WHERE id = :id');
            $chainStmt->execute([':id' => $chainId]);
            if (!$chainStmt->fetchColumn()) {
                json_response(['error' => 'Chain not found'], 404);
            }
        } else {
            $chainId = null;
        }
        try {
            $pdo->beginTransaction();
            $clearOldChain = $pdo->prepare('UPDATE menu_items SET chain_id = NULL WHERE restaurant_id = :restaurant_id');
            $clearOldChain->execute([':restaurant_id' => $restaurantId]);
            $stmt = $pdo->prepare('UPDATE restaurants SET chain_id = :chain_id, updated_at = :now WHERE id = :id');
            $stmt->execute([':chain_id' => $chainId, ':now' => $now, ':id' => $restaurantId]);
            if ($chainId !== null) {
                $shareMenu = $pdo->prepare(
                    'UPDATE menu_items SET chain_id = :chain_id
                     WHERE restaurant_id IN (SELECT id FROM restaurants WHERE chain_id = :restaurant_chain_id)'
                );
                $shareMenu->execute([':chain_id' => $chainId, ':restaurant_chain_id' => $chainId]);
                $createChainInventory = $pdo->prepare(
                    "INSERT IGNORE INTO inventory_items
                        (menu_item_id, current_stock, min_stock_level, unit, restaurant_id, created_at, updated_at)
                     SELECT mi.id, 0, 10, 'pieces', r.id, :created_at, :updated_at
                     FROM menu_items mi
                     JOIN restaurants r ON r.chain_id = mi.chain_id
                     WHERE mi.chain_id = :chain_id"
                );
                $createChainInventory->execute([
                    ':created_at' => $now,
                    ':updated_at' => $now,
                    ':chain_id' => $chainId,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Restaurant chain update failed: ' . $exception->getMessage());
            json_response(['error' => 'Unable to update restaurant chain'], 500);
        }
    } else {
        json_response(['error' => 'Unsupported restaurant action'], 422);
    }
}

json_response(['success' => true, 'data' => ['message' => $method === 'DELETE' ? 'Restaurant archived' : 'Restaurant updated']]);
