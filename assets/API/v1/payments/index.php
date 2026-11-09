<?php
declare(strict_types=1);
/** Paystack payment initialization and verification for delivery orders. */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$pdo = get_db();
$auth = require_auth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    try {
        $body = get_json_body();
    } catch (JsonException $e) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

    $action = (string)($body['action'] ?? '');

    if ($action === 'initialize') {
        if ($auth['role'] !== 'customer') {
            json_response(['error' => 'Only customers can make payments'], 403);
        }

        $deliveryOrderId = (int)($body['delivery_order_id'] ?? 0);
        if ($deliveryOrderId <= 0) {
            json_response(['error' => 'Delivery order ID is required'], 422);
        }

        $stmt = $pdo->prepare('SELECT id, total_amount, payment_method, status FROM delivery_orders WHERE id = :id AND customer_id = :customer LIMIT 1');
        $stmt->execute([':id' => $deliveryOrderId, ':customer' => $auth['id']]);
        $order = $stmt->fetch();

        if (!$order) {
            json_response(['error' => 'Delivery order not found'], 404);
        }

        if ($order['payment_method'] === 'paid') {
            json_response(['error' => 'Order is already paid'], 422);
        }

        $amount = (float)$order['total_amount'];
        if ($amount <= 0) {
            json_response(['error' => 'Order amount is invalid'], 422);
        }

        $paystackSecretKey = trim((string)($_ENV['PAYSTACK_SECRET_KEY'] ?? ''));
        if ($paystackSecretKey === '') {
            json_response(['error' => 'Payment service is not configured'], 500);
        }

        $reference = 'DELIVERY_' . $deliveryOrderId . '_' . time() . '_' . bin2hex(random_bytes(4));
        $callbackUrl = trim((string)($body['callback_url'] ?? ''));
        $email = $auth['email'] ?? '';

        $payload = [
            'email' => $email,
            'amount' => (int)round($amount * 100),
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => [
                'delivery_order_id' => $deliveryOrderId,
                'customer_id' => $auth['id'],
                'payment_method' => 'paystack',
            ],
        ];

        $ch = curl_init('https://api.paystack.co/transaction/initialize');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $paystackSecretKey,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            error_log('Paystack initialization failed: ' . $response);
            json_response(['error' => 'Unable to initialize payment'], 500);
        }

        $result = json_decode($response, true);
        if (!$result || !isset($result['status']) || !$result['status']) {
            json_response(['error' => $result['message'] ?? 'Payment initialization failed'], 400);
        }

        $authorizationUrl = $result['data']['authorization_url'] ?? '';
        $accessCode = $result['data']['access_code'] ?? '';
        $reference = $result['data']['reference'] ?? $reference;

        $pdo->prepare('INSERT INTO paystack_transactions (delivery_order_id, reference, amount, currency, status, customer_email, callback_url, created_at, updated_at) VALUES (:delivery_order_id, :reference, :amount, :currency, :status, :email, :callback, :now, :now)')->execute([
            ':delivery_order_id' => $deliveryOrderId,
            ':reference' => $reference,
            ':amount' => $amount,
            ':currency' => 'NGN',
            ':status' => 'initialized',
            ':email' => $email,
            ':callback' => $callbackUrl,
        ]);

        json_response(['success' => true, 'data' => [
            'authorization_url' => $authorizationUrl,
            'access_code' => $accessCode,
            'reference' => $reference,
            'amount' => $amount,
        ]]);
    }

    if ($action === 'verify') {
        $reference = trim((string)($body['reference'] ?? ''));
        if ($reference === '') {
            json_response(['error' => 'Reference is required'], 422);
        }

        $stmt = $pdo->prepare('SELECT id, delivery_order_id, amount, status FROM paystack_transactions WHERE reference = :reference LIMIT 1');
        $stmt->execute([':reference' => $reference]);
        $transaction = $stmt->fetch();

        if (!$transaction) {
            json_response(['error' => 'Transaction not found'], 404);
        }

        $paystackSecretKey = trim((string)($_ENV['PAYSTACK_SECRET_KEY'] ?? ''));
        if ($paystackSecretKey === '') {
            json_response(['error' => 'Payment service is not configured'], 500);
        }

        $ch = curl_init('https://api.paystack.co/transaction/verify/' . urlencode($reference));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $paystackSecretKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            error_log('Paystack verification failed: ' . $response);
            json_response(['error' => 'Unable to verify payment'], 500);
        }

        $result = json_decode($response, true);
        if (!$result || !isset($result['status']) || !$result['status']) {
            json_response(['error' => $result['message'] ?? 'Payment verification failed'], 400);
        }

        $paystackData = $result['data'] ?? [];
        $paystackStatus = strtolower((string)($paystackData['status'] ?? ''));
        $gatewayResponse = $paystackData['gateway_response'] ?? '';

        $newTransactionStatus = $paystackStatus === 'success' ? 'success' : ($paystackStatus === 'failed' ? 'failed' : 'pending');

        $pdo->prepare('UPDATE paystack_transactions SET status = :status, gateway_response = :response, updated_at = :now WHERE id = :id')->execute([
            ':status' => $newTransactionStatus,
            ':response' => $gatewayResponse,
            ':now' => date('Y-m-d H:i:s'),
            ':id' => $transaction['id'],
        ]);

        if ($newTransactionStatus === 'success') {
            $pdo->prepare('UPDATE delivery_orders SET payment_method = :payment, status = :status, updated_at = :now WHERE id = :id')->execute([
                ':payment' => 'paystack',
                ':status' => 'accepted',
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $transaction['delivery_order_id'],
            ]);
        }

        json_response(['success' => true, 'data' => [
            'reference' => $reference,
            'status' => $newTransactionStatus,
            'gateway_response' => $gatewayResponse,
            'delivery_order_id' => $transaction['delivery_order_id'],
        ]]);
    }

    json_response(['error' => 'Unknown action'], 400);
    exit;
}

if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM paystack_transactions WHERE id = :id AND delivery_order_id IN (SELECT id FROM delivery_orders WHERE customer_id = :customer) LIMIT 1');
        $stmt->execute([':id' => $id, ':customer' => $auth['id']]);
        $transaction = $stmt->fetch();
        if (!$transaction) {
            json_response(['error' => 'Transaction not found'], 404);
        }
        json_response(['success' => true, 'data' => $transaction]);
    }

    $stmt = $pdo->prepare('SELECT pt.*, d.total_amount, d.status AS delivery_status FROM paystack_transactions pt JOIN delivery_orders d ON d.id = pt.delivery_order_id WHERE d.customer_id = :customer ORDER BY pt.created_at DESC LIMIT 5');
    $stmt->execute([':customer' => $auth['id']]);
    json_response(['success' => true, 'data' => ['transactions' => $stmt->fetchAll()]]);
    exit;
}

json_response(['error' => 'Method not allowed'], 405);
