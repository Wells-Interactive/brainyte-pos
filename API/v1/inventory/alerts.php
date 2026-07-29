<?php
declare(strict_types=1);
/**
 * API v1 - Inventory Alerts
 *
 * GET /API/v1/inventory/alerts.php - Get all stock alerts
 *
 * Access: admin, owner, manager, supervisor (inventory.view)
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

use App\Inventory;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$authUser = require_role(['admin', 'owner', 'manager', 'supervisor']);

$pdo = get_db();
$inventory = new Inventory($pdo);

try {
    $alerts = $inventory->getStockAlerts();
    $stats = $inventory->getStatistics();

    echo json_encode([
        'success' => true,
        'data' => [
            'alerts' => $alerts,
            'statistics' => $stats,
            'generated_at' => date('Y-m-d H:i:s'),
        ],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to fetch inventory alerts']);
    error_log('Inventory alerts error: ' . $e->getMessage());
}

