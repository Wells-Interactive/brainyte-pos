<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

use App\Permission;

/**
 * Server-Sent Events (SSE) for real-time updates.
 *
 * GET /API/Live_Events/index.php
 * GET /API/Live_Events/index.php?department=kitchen   (admin/manager only)
 *
 * Security:
 *   - Authentication is REQUIRED before opening the stream
 *   - The role is DERIVED from the authenticated user, NOT from the URL
 *   - Kitchen users automatically see kitchen events
 *   - Bar users automatically see bar events
 *   - Admin/manager/supervisor can optionally specify ?department= to view any stream
 *   - Unauthorized requests receive a 401/403 response
 *
 * Flutter Fallback:
 *   If SSE connections are unreliable (mobile backgrounding, shared hosting timeouts),
 *   use the short-polling endpoint instead:
 *   GET /API/Live_Events/poll.php?since=ID&department=kitchen
 *
 *   Poll every 2-5 seconds as fallback.
 */

// Authenticate the user BEFORE opening the SSE stream
$authUser = require_auth();

// Determine which department to stream:
// For kitchen/bar users -> use their own role (auto-derived, not from URL)
// For admin/manager/supervisor -> allow optional ?department= parameter
$role = $authUser['role'];

if (in_array($role, ['admin', 'owner', 'manager', 'supervisor'], true)) {
    // Admin/manager can specify a department to view
    $department = trim((string)($_GET['department'] ?? ''));
    if (!in_array($department, ['kitchen', 'bar'], true)) {
        // Default to kitchen for admin if not specified
        $department = 'kitchen';
    }
} elseif (in_array($role, ['kitchen', 'bar'], true)) {
    // Kitchen/bar users are locked to their own department
    $department = $role;
} else {
    // Other roles (waiter, etc.) cannot use SSE
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: SSE is only available for kitchen, bar, and admin roles']);
    exit;
}

// Set up SSE headers
set_time_limit(0);
ignore_user_abort(false);  // Let the client disconnect properly
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$pdo = get_db();

// Get the last event ID from the client, if any
$lastId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int)$_SERVER['HTTP_LAST_EVENT_ID'] : 0;

// Reconnection time
echo "retry: 3000\n\n";

/**
 * Send an SSE event to the client.
 */
function send_event(string $event, array $data, int $id): void
{
    echo "id: {$id}\n";
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

// Send initial connection event
send_event('connected', [
    'department' => $department,
    'authenticated_as' => $authUser['role'],
    'authenticated_user' => $authUser['name'],
], max(0, $lastId));

// Main event loop
$heartbeatInterval = 0;
$maxHeartbeatsBeforeReconnect = 30; // ~90 seconds without data suggests reconnect

while (!connection_aborted()) {
    // Check if the client is still there
    if (connection_aborted()) {
        break;
    }

    // Note: direct_printing does NOT suppress SSE events.
    // Printing is a separate concern from the display workflow.
    // Kitchen/Bar dashboards ALWAYS show their events.
    // The database is the source of truth.

    // Fetch new order items for this department since the last known ID
    $stmt = $pdo->prepare(
        "SELECT oi.id, oi.order_id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price,
                oi.status, oi.routed_to, oi.created_at, oi.instructions,
                o.table_id, o.waiter_id, o.special_instructions AS order_instructions,
                u.name AS waiter_name
         FROM order_items oi
         JOIN menu_items mi ON mi.id = oi.menu_item_id
         JOIN orders o ON o.id = oi.order_id
         JOIN users u ON u.id = o.waiter_id
         WHERE oi.id > :last_id AND oi.routed_to = :department
         ORDER BY oi.id"
    );
    $stmt->execute([':last_id' => $lastId, ':department' => $department]);
    $items = $stmt->fetchAll();

    $itemCount = 0;
    foreach ($items as $item) {
        $item['id'] = (int)$item['id'];
        $item['order_id'] = (int)$item['order_id'];
        $item['menu_item_id'] = (int)$item['menu_item_id'];
        $item['quantity'] = (int)$item['quantity'];
        $item['unit_price'] = (float)$item['unit_price'];
        $item['table_id'] = (int)$item['table_id'];
        $item['waiter_id'] = (int)$item['waiter_id'];

        $lastId = (int)$item['id'];
        $itemCount++;
        send_event('new-order', $item, $lastId);
    }

    // Also check for status updates on existing items
    if ($itemCount === 0 && $lastId > 0) {
        $statusStmt = $pdo->prepare(
            "SELECT oi.id, oi.order_id, oi.menu_item_id, mi.name AS item_name, oi.quantity, oi.unit_price,
                    oi.status, oi.routed_to, oi.created_at, oi.instructions,
                    o.table_id, o.waiter_id
             FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.routed_to = :department
               AND oi.updated_at > :since
               AND oi.id <= :last_id
             ORDER BY oi.updated_at DESC
             LIMIT 5"
        );
        $stmt = $pdo->prepare("ALTER TABLE order_items ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at");
        // If column doesn't exist yet, just skip status updates
        $statusItems = [];
        try {
            $statusStmt->execute([
                ':department' => $department,
                ':since' => date('Y-m-d H:i:s', time() - 30),
                ':last_id' => $lastId,
            ]);
            $statusItems = $statusStmt->fetchAll();
        } catch (Throwable $e) {
            $statusItems = [];
        }

        foreach ($statusItems as $item) {
            $item['id'] = (int)$item['id'];
            $item['order_id'] = (int)$item['order_id'];
            $item['menu_item_id'] = (int)$item['menu_item_id'];
            $item['quantity'] = (int)$item['quantity'];
            $item['unit_price'] = (float)$item['unit_price'];
            $item['table_id'] = (int)$item['table_id'];
            $item['waiter_id'] = (int)$item['waiter_id'];
            send_event('status-update', $item, $lastId);
        }
    }

    // Heartbeat every 3 seconds
    send_event('heartbeat', [
        'time' => time(),
        'department' => $department,
    ], $lastId);

    $heartbeatInterval++;

    // Suggest reconnect if too many heartbeats without data
    if ($heartbeatInterval >= $maxHeartbeatsBeforeReconnect) {
        send_event('reconnect', ['reason' => 'keepalive', 'retry' => 3000], $lastId);
        $heartbeatInterval = 0;
    }

    sleep(3);
}

