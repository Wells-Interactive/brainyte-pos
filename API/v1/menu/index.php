<?php
declare(strict_types=1);
/**
 * API v1 - Menu Items
 *
 * GET  /API/v1/menu/index.php          - List all available menu items
 * GET  /API/v1/menu/index.php?id=123   - Get single menu item
 * POST /API/v1/menu/index.php          - Create menu item (admin/owner only)
 *
 * This is the stable public API for the Flutter application.
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

use App\MenuItem;
use App\Permission;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();
$menuItemService = new MenuItem($pdo);

// ============================================================
// GET - List menu items
// ============================================================
if ($method === 'GET') {
    $itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($itemId > 0) {
        $item = $menuItemService->getById($itemId);
        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Menu item not found']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'data' => $item,
        ]);
        exit;
    }

    $category = trim((string)($_GET['category'] ?? ''));
    $includeUnavailable = isset($_GET['all']) && $_GET['all'] === '1';

    $items = $menuItemService->getAll($category, $includeUnavailable);

    echo json_encode([
        'success' => true,
        'data' => $items,
        'meta' => ['count' => count($items)],
    ]);
    exit;
}

// ============================================================
// POST - Create menu item (admin/owner only)
// ============================================================
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$authUser = require_role(['admin', 'owner']);

try {
    $body = get_json_body();
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$name = trim((string)($body['name'] ?? ''));
$description = trim((string)($body['description'] ?? ''));
$price = isset($body['price']) ? (float)$body['price'] : 0;
$category = trim((string)($body['category'] ?? ''));
$available = isset($body['available']) ? (int)$body['available'] : 1;

if ($name === '' || $description === '' || $price <= 0 || $category === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Name, description, price, and category are required']);
    exit;
}

// Use the existing MenuItem class to create (individual params, not array)
try {
    $itemId = $menuItemService->create($name, $description, $price, $category, $available);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

if (!$itemId) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to create menu item']);
    exit;
}

http_response_code(201);
echo json_encode([
    'success' => true,
    'data' => ['id' => (int)$itemId, 'name' => $name],
]);
