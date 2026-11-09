<?php
declare(strict_types=1);
/** Miscellaneous Orders: link multiple tables/orders under one group for waiters. */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$pdo = get_db();
$auth = require_auth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare('SELECT * FROM miscellaneous_orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $misc = $stmt->fetch();
        if (!$misc) {
            json_response(['error' => 'Miscellaneous order not found'], 404);
        }

        $assignmentsStmt = $pdo->prepare('SELECT a.*, t.name AS table_name FROM miscellaneous_order_assignments a JOIN restaurant_tables t ON t.id = a.table_id WHERE a.miscellaneous_order_id = :id');
        $assignmentsStmt->execute([':id' => $id]);
        $misc['assignments'] = $assignmentsStmt->fetchAll();

        json_response(['success' => true, 'data' => $misc]);
        exit;
    }

    $sql = 'SELECT m.*, t.name AS primary_table_name FROM miscellaneous_orders m JOIN restaurant_tables t ON t.id = m.primary_table_id WHERE m.waiter_id = :waiter AND m.status = :status ORDER BY m.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':waiter' => $auth['id'], ':status' => 'open']);
    json_response(['success' => true, 'data' => ['miscellaneous_orders' => $stmt->fetchAll()]]);
    exit;
}

if ($method === 'POST') {
    try {
        $body = get_json_body();
    } catch (JsonException $e) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

    $action = (string)($body['action'] ?? '');

    if ($action === 'create') {
        $primaryTableId = (int)($body['primary_table_id'] ?? 0);
        $notes = trim((string)($body['notes'] ?? ''));

        if ($primaryTableId <= 0) {
            json_response(['error' => 'Primary table is required'], 422);
        }

        $tableStmt = $pdo->prepare('SELECT id FROM restaurant_tables WHERE id = :id LIMIT 1');
        $tableStmt->execute([':id' => $primaryTableId]);
        if (!$tableStmt->fetch()) {
            json_response(['error' => 'Table not found'], 404);
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO miscellaneous_orders (primary_table_id, waiter_id, status, notes, created_at, updated_at) VALUES (:primary_table_id, :waiter_id, :status, :notes, :created_at, :updated_at)');
        $stmt->execute([
            ':primary_table_id' => $primaryTableId,
            ':waiter_id' => $auth['id'],
            ':status' => 'open',
            ':notes' => $notes ?: null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $miscId = (int)$pdo->lastInsertId();

        $assignStmt = $pdo->prepare('INSERT INTO miscellaneous_order_assignments (miscellaneous_order_id, table_id, assigned_at, notes) VALUES (:misc_id, :table_id, :assigned_at, :notes)');
        $assignStmt->execute([
            ':misc_id' => $miscId,
            ':table_id' => $primaryTableId,
            ':assigned_at' => $now,
            ':notes' => 'Primary table',
        ]);

        json_response(['success' => true, 'data' => ['id' => $miscId, 'primary_table_id' => $primaryTableId]], 201);
        exit;
    }

    if ($action === 'assign_table') {
        $miscId = (int)($body['miscellaneous_order_id'] ?? 0);
        $tableId = (int)($body['table_id'] ?? 0);
        $notes = trim((string)($body['notes'] ?? ''));

        if ($miscId <= 0 || $tableId <= 0) {
            json_response(['error' => 'Miscellaneous order ID and table ID are required'], 422);
        }

        $miscStmt = $pdo->prepare('SELECT id, status FROM miscellaneous_orders WHERE id = :id AND waiter_id = :waiter LIMIT 1');
        $miscStmt->execute([':id' => $miscId, ':waiter' => $auth['id']]);
        $misc = $miscStmt->fetch();
        if (!$misc) {
            json_response(['error' => 'Miscellaneous order not found'], 404);
        }
        if ($misc['status'] !== 'open') {
            json_response(['error' => 'Cannot assign tables to a closed miscellaneous order'], 422);
        }

        $tableStmt = $pdo->prepare('SELECT id FROM restaurant_tables WHERE id = :id LIMIT 1');
        $tableStmt->execute([':id' => $tableId]);
        if (!$tableStmt->fetch()) {
            json_response(['error' => 'Table not found'], 404);
        }

        $existsStmt = $pdo->prepare('SELECT id FROM miscellaneous_order_assignments WHERE miscellaneous_order_id = :misc_id AND table_id = :table_id LIMIT 1');
        $existsStmt->execute([':misc_id' => $miscId, ':table_id' => $tableId]);
        if ($existsStmt->fetch()) {
            json_response(['error' => 'Table is already assigned to this miscellaneous order'], 422);
        }

        $now = date('Y-m-d H:i:s');
        $assignStmt = $pdo->prepare('INSERT INTO miscellaneous_order_assignments (miscellaneous_order_id, table_id, assigned_at, notes) VALUES (:misc_id, :table_id, :assigned_at, :notes)');
        $assignStmt->execute([
            ':misc_id' => $miscId,
            ':table_id' => $tableId,
            ':assigned_at' => $now,
            ':notes' => $notes ?: null,
        ]);

        json_response(['success' => true, 'data' => ['message' => 'Table assigned successfully']], 201);
        exit;
    }

    if ($action === 'complete') {
        $miscId = (int)($body['miscellaneous_order_id'] ?? 0);
        if ($miscId <= 0) {
            json_response(['error' => 'Miscellaneous order ID is required'], 422);
        }

        $stmt = $pdo->prepare('UPDATE miscellaneous_orders SET status = :status, updated_at = :now WHERE id = :id AND waiter_id = :waiter AND status = :open');
        $stmt->execute([':status' => 'completed', ':now' => date('Y-m-d H:i:s'), ':id' => $miscId, ':waiter' => $auth['id']]);

        if ($stmt->rowCount() === 0) {
            json_response(['error' => 'Miscellaneous order not found or already closed'], 404);
        }

        json_response(['success' => true, 'data' => ['message' => 'Miscellaneous order completed']]);
        exit;
    }

    if ($action === 'cancel') {
        $miscId = (int)($body['miscellaneous_order_id'] ?? 0);
        if ($miscId <= 0) {
            json_response(['error' => 'Miscellaneous order ID is required'], 422);
        }

        $stmt = $pdo->prepare('UPDATE miscellaneous_orders SET status = :status, updated_at = :now WHERE id = :id AND waiter_id = :waiter AND status = :open');
        $stmt->execute([':status' => 'cancelled', ':now' => date('Y-m-d H:i:s'), ':id' => $miscId, ':waiter' => $auth['id']]);

        if ($stmt->rowCount() === 0) {
            json_response(['error' => 'Miscellaneous order not found or already closed'], 404);
        }

        json_response(['success' => true, 'data' => ['message' => 'Miscellaneous order cancelled']]);
        exit;
    }

    json_response(['error' => 'Unknown action'], 400);
    exit;
}

json_response(['error' => 'Method not allowed'], 405);
