<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

/**
 * Server-Sent Events (SSE) for real-time updates.
 * 
 * GET /API/Live%20Events/index.php?role=kitchen
 * GET /API/Live%20Events/index.php?role=bar
 */

$role = $_GET['role'] ?? '';
if (!in_array($role, ['kitchen', 'bar'], true)) {
    http_response_code(400);
    echo 'Invalid role';
    exit;
}

set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$pdo = get_db();
$lastId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int)$_SERVER['HTTP_LAST_EVENT_ID'] : 0;

function send_event(string $event, array $data, int $id): void
{
    echo "id: {$id}\n";
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
}

send_event('connected', ['role' => $role], max(0, $lastId));

while (!connection_aborted()) {
    // Check if direct_printing is enabled
    $skipEvents = false;
    try {
        $settingStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'direct_printing' LIMIT 1");
        $settingStmt->execute();
        $settingRow = $settingStmt->fetch();
        if ($settingRow && $settingRow['setting_value'] === '1') {
            $skipEvents = true;
        }
    } catch (Throwable $e) {
        $skipEvents = false;
    }

    if (!$skipEvents) {
        // Fetch new order items for this role
        $stmt = $pdo->prepare(
            "SELECT oi.id, oi.order_id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price,
                    oi.status, oi.routed_to, oi.created_at,
                    o.table_id, o.waiter_id, o.special_instructions AS instructions,
                    u.name AS waiter_name
             FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             JOIN orders o ON o.id = oi.order_id
             JOIN users u ON u.id = o.waiter_id
             WHERE oi.id > :last_id AND oi.routed_to = :role
             ORDER BY oi.id"
        );
        $stmt->execute([':last_id' => $lastId, ':role' => $role]);
        $items = $stmt->fetchAll();

        foreach ($items as $item) {
            $item['id'] = (int)$item['id'];
            $item['order_id'] = (int)$item['order_id'];
            $item['menu_item_id'] = (int)$item['menu_item_id'];
            $item['quantity'] = (int)$item['quantity'];
            $item['unit_price'] = (float)$item['unit_price'];
            $item['table_id'] = (int)$item['table_id'];
            $item['waiter_id'] = (int)$item['waiter_id'];

            $lastId = (int)$item['id'];
            send_event('new-order', $item, $lastId);
        }
    }

    send_event('heartbeat', ['time' => time()], $lastId);
    sleep(3);
}
