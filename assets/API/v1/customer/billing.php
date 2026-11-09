<?php
declare(strict_types=1);
/** Customer billing: last 5 delivery order transactions for the logged-in customer. */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$pdo = get_db();
$auth = require_auth();

if ($auth['role'] !== 'customer') {
    json_response(['error' => 'Only customers can view billing'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT d.id AS delivery_order_id, d.total_amount, d.payment_method, d.status AS delivery_status, d.created_at, d.delivery_address, pt.reference, pt.status AS payment_status, pt.gateway_response FROM delivery_orders d LEFT JOIN paystack_transactions pt ON pt.delivery_order_id = d.id WHERE d.customer_id = :customer ORDER BY d.created_at DESC LIMIT 5');
    $stmt->execute([':customer' => $auth['id']]);
    $transactions = $stmt->fetchAll();

    foreach ($transactions as &$tx) {
        $tx['total_amount'] = (float)$tx['total_amount'];
        $tx['created_at'] = (string)$tx['created_at'];
    }

    json_response(['success' => true, 'data' => ['transactions' => $transactions]]);
    exit;
}

json_response(['error' => 'Method not allowed'], 405);
