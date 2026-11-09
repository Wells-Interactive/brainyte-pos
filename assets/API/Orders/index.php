<?php
declare(strict_types=1);
/**
 * Orders API Router - Compatibility Wrapper
 * 
 * This file acts as a routing layer that dispatches to dedicated endpoints.
 * 
 * POST /API/Orders/index.php         -> POST /API/Orders/create.php (new order)
 * POST /API/Orders/index.php?action=status  -> POST /API/Orders/status.php (update status)
 * GET  /API/Orders/index.php         -> GET /API/Orders/list.php (list orders)
 * GET  /API/Orders/index.php?action=history -> GET /API/Orders/history.php (order history)
 * 
 * The single official order creation endpoint is: POST /API/Orders/create.php
 * New clients should use the dedicated endpoints directly.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$action = trim((string)($_GET['action'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' && $action === '') {
    // Order creation - route to official endpoint
    require __DIR__ . '/create.php';
    return;
}

if ($method === 'POST' && $action === 'status') {
    // Status update
    require __DIR__ . '/status.php';
    return;
}

if ($method === 'GET' && $action === 'history') {
    // Order history
    require __DIR__ . '/history.php';
    return;
}

if ($method === 'GET' && $action === '') {
    // List orders (default)
    require __DIR__ . '/list.php';
    return;
}

http_response_code(405);
json_response(['error' => 'Method not allowed'], 405);
