<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';

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

$hasInstructionsColumn = false;
try {
    $columnStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'special_instructions'");
    $columnStmt->execute();
    $hasInstructionsColumn = (int)$columnStmt->fetchColumn() > 0;
} catch (Throwable $exception) {
    $hasInstructionsColumn = false;
}

send_event('connected', ['role' => $role], max(0, $lastId));

while (!connection_aborted()) {
    $selectColumns = 'oi.id, oi.order_id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.status, oi.routed_to, o.table_id, o.waiter_id, o.created_at';
    if ($hasInstructionsColumn) {
        $selectColumns .= ', o.special_instructions AS instructions';
    }

    $stmt = $pdo->prepare("SELECT {$selectColumns} FROM order_items oi JOIN menu_items mi ON mi.id = oi.menu_item_id JOIN orders o ON o.id = oi.order_id WHERE oi.id > :last_id AND oi.routed_to = :role AND oi.status = :status ORDER BY oi.id");
    $stmt->execute([':last_id' => $lastId, ':role' => $role, ':status' => 'pending']);
    $items = $stmt->fetchAll();

    foreach ($items as $item) {
        if (!$hasInstructionsColumn) {
            $item['instructions'] = null;
        }
        $lastId = (int)$item['id'];
        send_event('new-order', $item, $lastId);
    }

    send_event('heartbeat', ['time' => time()], $lastId);
    sleep(3);
}
