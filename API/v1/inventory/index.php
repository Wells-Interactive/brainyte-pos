<?php
declare(strict_types=1);
/**
 * API v1 - Inventory Management
 *
 * GET  /API/v1/inventory/index.php                   - List all inventory items
 * GET  /API/v1/inventory/index.php?menu_item_id=1    - Get inventory for a menu item
 * POST /API/v1/inventory/index.php                   - Adjust stock (requires reason)
 *
 * Access: admin, owner, manager (inventory.manage)
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

use App\Inventory;
use App\AuditLog;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();
$inventory = new Inventory($pdo);
$auditLog = new AuditLog($pdo);

// ============================================================
// GET - List inventory / Get single item
// ============================================================
if ($method === 'GET') {
    $authUser = require_role(['admin', 'owner', 'manager', 'supervisor']);

    $menuItemId = isset($_GET['menu_item_id']) ? (int)$_GET['menu_item_id'] : 0;

    if ($menuItemId > 0) {
        $item = $inventory->getByMenuItemId($menuItemId);
        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Inventory item not found']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'data' => $item,
        ]);
        exit;
    }

    $items = $inventory->getAll();
    $alerts = $inventory->getStockAlerts();
    $stats = $inventory->getStatistics();

    echo json_encode([
        'success' => true,
        'data' => [
            'items' => $items,
            'alerts' => $alerts,
            'statistics' => $stats,
        ],
    ]);
    exit;
}

// ============================================================
// POST - Adjust stock
// ============================================================
if ($method === 'POST') {
    $authUser = require_role(['admin', 'owner', 'manager']);

    try {
        $body = get_json_body();
    } catch (JsonException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $menuItemId = isset($body['menu_item_id']) ? (int)$body['menu_item_id'] : 0;
    $quantity = isset($body['quantity']) ? (float)$body['quantity'] : 0;
    $reason = trim((string)($body['reason'] ?? ''));

    if ($menuItemId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'menu_item_id is required']);
        exit;
    }

    if ($quantity === 0.0) {
        http_response_code(400);
        echo json_encode(['error' => 'Quantity must be non-zero']);
        exit;
    }

    if ($reason === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Reason is required for stock adjustment']);
        exit;
    }

    try {
        $result = $inventory->adjustStock(
            $menuItemId,
            $quantity,
            $reason,
            $authUser['id'],
            'adjustment',
            null
        );

        // Log to audit trail
        $auditLog->log(
            $authUser['id'],
            'inventory_adjustment',
            'inventory_items',
            $result['inventory_item_id'],
            "Adjusted stock for menu_item_id={$menuItemId}: {$result['previous_qty']} -> {$result['new_qty']} ({$reason})"
        );

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $result,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Stock adjustment failed: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================================
// PUT - Update min stock level or unit
// ============================================================
if ($method === 'PUT') {
    $authUser = require_role(['admin', 'owner', 'manager']);

    try {
        $body = get_json_body();
    } catch (JsonException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $menuItemId = isset($body['menu_item_id']) ? (int)$body['menu_item_id'] : 0;

    if ($menuItemId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'menu_item_id is required']);
        exit;
    }

    $updates = [];

    if (isset($body['min_stock_level'])) {
        $minStock = (float)$body['min_stock_level'];
        $inventory->setMinStockLevel($menuItemId, $minStock);
        $updates['min_stock_level'] = $minStock;
    }

    if (isset($body['unit'])) {
        $unit = trim((string)$body['unit']);
        if ($unit !== '') {
            $inventory->setUnit($menuItemId, $unit);
            $updates['unit'] = $unit;
        }
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid fields to update (min_stock_level, unit)']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'menu_item_id' => $menuItemId,
            'updated' => $updates,
        ],
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

