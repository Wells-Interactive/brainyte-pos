<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$auth = require_role(['admin', 'owner']);
$pdo = get_db();

$restaurantId = (int)($auth['restaurant_id'] ?? 0);
if ($restaurantId <= 0) {
    $restaurantStmt = $pdo->prepare('SELECT restaurant_id FROM users WHERE id = :id LIMIT 1');
    $restaurantStmt->execute([':id' => (int)$auth['id']]);
    $restaurantId = (int)$restaurantStmt->fetchColumn();
}
if ($restaurantId <= 0) {
    json_response(['error' => 'This account is not assigned to a restaurant'], 403);
}

$stmt = $pdo->prepare(
    "SELECT id, name, email, role, created_at, is_demo
     FROM users
     WHERE role IN ('waiter', 'kitchen', 'bar', 'manager', 'supervisor', 'admin', 'owner', 'rider')
       AND restaurant_id = :restaurant_id
       AND " . demo_data_scope_sql('users', $auth) . '
     ORDER BY role, name, id'
);
$stmt->execute([':restaurant_id' => $restaurantId]);

json_response([
    'success' => true,
    'data' => ['staff' => $stmt->fetchAll()],
]);
