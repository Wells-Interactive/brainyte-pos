<?php
declare(strict_types=1);
/** Customer ban/unban for admin/owner. */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$pdo = get_db();
$auth = require_auth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!in_array($auth['role'], ['admin', 'owner', 'manager', 'supervisor'], true)) {
        json_response(['error' => 'Forbidden'], 403);
    }

    $search = trim((string)($_GET['search'] ?? ''));
    $params = [];
    $sql = 'SELECT u.id, u.name, u.email, u.role, cp.phone_number, cp.email_verified_at, cp.banned, cp.created_at FROM users u JOIN customer_profiles cp ON cp.user_id = u.id WHERE u.role = :role AND ' . demo_data_scope_sql('u', $auth);
    $params[':role'] = 'customer';

    if ($search !== '') {
        $sql .= ' AND (u.name LIKE :search OR u.email LIKE :search OR cp.phone_number LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY u.created_at DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll();

    foreach ($customers as &$customer) {
        $customer['banned'] = (bool)$customer['banned'];
    }

    json_response(['success' => true, 'data' => ['customers' => $customers]]);
    exit;
}

if ($method === 'POST') {
    if (!in_array($auth['role'], ['admin', 'owner'], true)) {
        json_response(['error' => 'Only admin and owner can ban customers'], 403);
    }

    try {
        $body = get_json_body();
    } catch (JsonException $e) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

    $customerId = (int)($body['customer_id'] ?? 0);
    $ban = (bool)($body['ban'] ?? false);

    if ($customerId <= 0) {
        json_response(['error' => 'Customer ID is required'], 422);
    }

    $userStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id AND ' . demo_data_scope_sql('users', $auth) . ' LIMIT 1');
    $userStmt->execute([':id' => $customerId]);
    $user = $userStmt->fetch();

    if (!$user || $user['role'] !== 'customer') {
        json_response(['error' => 'Customer not found'], 404);
    }

    $stmt = $pdo->prepare('UPDATE customer_profiles SET banned = :banned, updated_at = :now WHERE user_id = :user_id');
    $stmt->execute([
        ':banned' => $ban ? 1 : 0,
        ':now' => date('Y-m-d H:i:s'),
        ':user_id' => $customerId,
    ]);

    $action = $ban ? 'banned' : 'unbanned';
    json_response(['success' => true, 'data' => ['message' => "Customer {$action} successfully"]]);
    exit;
}

json_response(['error' => 'Method not allowed'], 405);
