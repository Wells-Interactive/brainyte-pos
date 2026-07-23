<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

use App\MenuItem;
use App\CSRF;
use App\AuditLog;

$allowedCategories = ['beer', 'malt', 'soft-drinks', 'water', 'energy-drinks', 'juice', 'spirits', 'ready-to-drink', 'rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras', 'cigarettes'];
$category = in_array(trim((string)($_GET['category'] ?? '')), $allowedCategories, true) ? trim((string)$_GET['category']) : null;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $pdo = get_db();
        $menuItem = new MenuItem($pdo);
        $items = $menuItem->getAvailable($category);
        json_response(['items' => $items]);
    } catch (Throwable $exception) {
        json_response(['error' => 'Unable to load menu items'], 500);
    }
    return;
}

if ($method === 'POST' || $method === 'PUT') {
    require_admin();

    try {
        $body = get_json_body();
    } catch (JsonException $exception) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

    $pdo = get_db();
    $menuItem = new MenuItem($pdo);
    $auditLog = new AuditLog($pdo);
    $authUser = get_auth_user();

    if ($method === 'POST') {
        $name = trim((string)($body['name'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $price = isset($body['price']) ? (float)$body['price'] : 0.0;
        $cat = trim((string)($body['category'] ?? ''));
        $available = isset($body['available']) ? (int)$body['available'] : 1;

        if ($name === '' || $description === '' || $price <= 0 || $cat === '' || !in_array($cat, $allowedCategories, true)) {
            json_response(['error' => 'Name, description, price and a valid category are required'], 400);
        }

        try {
            $itemId = $menuItem->create($name, $description, $price, $cat, $available);
            $auditLog->dataChange($authUser['id'], 'menu_item', $itemId, 'create', "Created menu item {$name} ({$cat}) at ₦{$price}");
            json_response(['success' => true, 'item_id' => $itemId]);
        } catch (Throwable $exception) {
            json_response(['error' => 'Unable to create menu item'], 500);
        }
        return;
    }

    if ($method === 'PUT') {
        $itemId = isset($body['id']) ? (int)$body['id'] : 0;
        $price = isset($body['price']) ? (float)$body['price'] : -1;

        if ($itemId <= 0 || $price < 0) {
            json_response(['error' => 'Item ID and price are required'], 400);
        }

        try {
            $menuItem->updatePrice($itemId, $price);
            $auditLog->dataChange($authUser['id'], 'menu_item', $itemId, 'update_price', "Updated menu item #{$itemId} price to ₦{$price}");
            json_response(['success' => true]);
        } catch (Throwable $exception) {
            json_response(['error' => 'Unable to update menu item'], 500);
        }
        return;
    }
}

http_response_code(405);
json_response(['error' => 'Method not allowed'], 405);
