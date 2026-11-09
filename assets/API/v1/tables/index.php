<?php
declare(strict_types=1);
/** Restaurant tables management for admin/owner. */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$pdo = get_db();
$auth = require_auth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!in_array($auth['role'], ['admin', 'owner', 'manager', 'supervisor', 'waiter'], true)) {
        json_response(['error' => 'Forbidden'], 403);
    }

    $stmt = $pdo->query(
        'SELECT id, name, status, created_at
         FROM restaurant_tables
         WHERE ' . demo_data_scope_sql('restaurant_tables', $auth) . '
         ORDER BY id ASC'
    );
    $tables = $stmt->fetchAll();

    json_response(['success' => true, 'data' => ['tables' => $tables]]);
    exit;
}

if ($method === 'POST') {
    if (!in_array($auth['role'], ['admin', 'owner'], true)) {
        json_response(['error' => 'Only admin and owner can manage tables'], 403);
    }

    try {
        $body = get_json_body();
    } catch (JsonException $e) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

    $action = (string)($body['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string)($body['name'] ?? ''));
        $tableNumber = isset($body['id']) ? (int)$body['id'] : 0;

        if ($tableNumber <= 0 && $name === '') {
            json_response(['error' => 'Table number or name is required'], 422);
        }

        if ($tableNumber > 0) {
            $stmt = $pdo->prepare('SELECT id FROM restaurant_tables WHERE id = :id AND ' . demo_data_scope_sql('restaurant_tables', $auth) . ' LIMIT 1');
            $stmt->execute([':id' => $tableNumber]);
            if ($stmt->fetch()) {
                json_response(['error' => 'Table number already exists'], 422);
            }
        }

        $now = date('Y-m-d H:i:s');

        if ($tableNumber > 0) {
            $stmt = $pdo->prepare('INSERT INTO restaurant_tables (id, name, status, restaurant_id, created_at) VALUES (:id, :name, :status, :restaurant_id, :created_at)');
            $stmt->execute([
                ':id' => $tableNumber,
                ':name' => $name !== '' ? $name : 'Table ' . $tableNumber,
                ':status' => 'available',
                ':restaurant_id' => $auth['restaurant_id'] ?? null,
                ':created_at' => $now,
            ]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO restaurant_tables (name, status, restaurant_id, created_at) VALUES (:name, :status, :restaurant_id, :created_at)');
            $stmt->execute([
                ':name' => $name,
                ':status' => 'available',
                ':restaurant_id' => $auth['restaurant_id'] ?? null,
                ':created_at' => $now,
            ]);
            $tableNumber = (int)$pdo->lastInsertId();
        }

        json_response(['success' => true, 'data' => ['id' => $tableNumber, 'name' => $name !== '' ? $name : 'Table ' . $tableNumber, 'status' => 'available']], 201);
        exit;
    }

    if ($action === 'update') {
        $tableId = (int)($body['id'] ?? 0);
        $name = trim((string)($body['name'] ?? ''));

        if ($tableId <= 0) {
            json_response(['error' => 'Table ID is required'], 422);
        }

        $stmt = $pdo->prepare('UPDATE restaurant_tables SET name = :name, updated_at = :now WHERE id = :id AND ' . demo_data_scope_sql('restaurant_tables', $auth));
        $stmt->execute([
            ':name' => $name !== '' ? $name : 'Table ' . $tableId,
            ':now' => date('Y-m-d H:i:s'),
            ':id' => $tableId,
        ]);

        json_response(['success' => true, 'data' => ['message' => 'Table updated']]);
        exit;
    }

    if ($action === 'delete') {
        $tableId = (int)($body['id'] ?? 0);

        if ($tableId <= 0) {
            json_response(['error' => 'Table ID is required'], 422);
        }

        $stmt = $pdo->prepare('DELETE FROM restaurant_tables WHERE id = :id AND ' . demo_data_scope_sql('restaurant_tables', $auth));
        $stmt->execute([':id' => $tableId]);

        if ($stmt->rowCount() === 0) {
            json_response(['error' => 'Table not found'], 404);
        }

        json_response(['success' => true, 'data' => ['message' => 'Table deleted']]);
        exit;
    }

    json_response(['error' => 'Unknown action'], 400);
    exit;
}

json_response(['error' => 'Method not allowed'], 405);
