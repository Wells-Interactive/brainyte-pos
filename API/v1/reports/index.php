<?php
declare(strict_types=1);
/**
 * API v1 - Reports & Statistics
 *
 * GET /API/v1/reports/index.php - Get dashboard statistics
 *   Query: ?scope=day|week|month (default: day)
 *   Query: ?top_items=10 (default: 10)
 *
 * This is the stable public API for the Flutter application.
 * Access: admin, owner, manager (reports.view permission)
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

use App\Permission;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$authUser = require_role(['admin', 'owner', 'manager', 'supervisor']);

$scope = trim((string)($_GET['scope'] ?? 'day'));
$topItemsLimit = isset($_GET['top_items']) ? min(50, max(1, (int)$_GET['top_items'])) : 10;

$pdo = get_db();
$now = date('Y-m-d H:i:s');

// Build date filters based on scope
switch ($scope) {
    case 'week':
        $startDate = date('Y-m-d', strtotime('-7 days'));
        break;
    case 'month':
        $startDate = date('Y-m-d', strtotime('-30 days'));
        break;
    case 'day':
    default:
        $startDate = date('Y-m-d');
        break;
}

$dayStart = date('Y-m-d') . ' 00:00:00';

try {
    // Total revenue (completed orders in scope)
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS total_revenue
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.status = 'completed' AND o.updated_at >= :start_date"
    );
    $stmt->execute([':start_date' => $startDate . ' 00:00:00']);
    $totalRevenue = (float)$stmt->fetchColumn();

    // Completed orders count
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders WHERE status = 'completed' AND updated_at >= :start_date"
    );
    $stmt->execute([':start_date' => $startDate . ' 00:00:00']);
    $completedOrders = (int)$stmt->fetchColumn();

    // Items sold
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.quantity), 0)
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.status = 'completed' AND o.updated_at >= :start_date"
    );
    $stmt->execute([':start_date' => $startDate . ' 00:00:00']);
    $itemsSold = (int)$stmt->fetchColumn();

    // Pending orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status NOT IN ('completed')");
    $stmt->execute();
    $pendingOrders = (int)$stmt->fetchColumn();

    // Bar orders (total)
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE oi.routed_to = 'bar' AND o.created_at >= :start_date"
    );
    $stmt->execute([':start_date' => $startDate . ' 00:00:00']);
    $totalBarOrders = (int)$stmt->fetchColumn();

    // Kitchen orders (total)
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE oi.routed_to = 'kitchen' AND o.created_at >= :start_date"
    );
    $stmt->execute([':start_date' => $startDate . ' 00:00:00']);
    $totalKitchenOrders = (int)$stmt->fetchColumn();

    // Day summary
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.quantity * oi.unit_price), 0)
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.status = 'completed' AND o.updated_at >= :day_start"
    );
    $stmt->execute([':day_start' => $dayStart]);
    $summaryDay = (float)$stmt->fetchColumn();

    // Week summary
    $weekStart = date('Y-m-d', strtotime('-7 days')) . ' 00:00:00';
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.quantity * oi.unit_price), 0)
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.status = 'completed' AND o.updated_at >= :week_start"
    );
    $stmt->execute([':week_start' => $weekStart]);
    $summaryWeek = (float)$stmt->fetchColumn();

    // Month summary
    $monthStart = date('Y-m-d', strtotime('-30 days')) . ' 00:00:00';
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.quantity * oi.unit_price), 0)
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.status = 'completed' AND o.updated_at >= :month_start"
    );
    $stmt->execute([':month_start' => $monthStart]);
    $summaryMonth = (float)$stmt->fetchColumn();

    // Top selling items
    $stmt = $pdo->prepare(
        "SELECT mi.name AS item_name, mi.category, SUM(oi.quantity) AS quantity_sold,
                SUM(oi.quantity * oi.unit_price) AS total_revenue
         FROM order_items oi
         JOIN menu_items mi ON mi.id = oi.menu_item_id
         JOIN orders o ON o.id = oi.order_id
         WHERE o.status = 'completed' AND o.updated_at >= :start_date
         GROUP BY oi.menu_item_id
         ORDER BY quantity_sold DESC
         LIMIT :top_items"
    );
    $stmt->bindValue(':start_date', $startDate . ' 00:00:00', PDO::PARAM_STR);
    $stmt->bindValue(':top_items', $topItemsLimit, PDO::PARAM_INT);
    $stmt->execute();
    $topItems = $stmt->fetchAll();

    // Table status
    $stmt = $pdo->query('SELECT id AS table_id, name, status FROM restaurant_tables ORDER BY name');
    $tables = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => [
            'scope' => $scope,
            'total_revenue' => $totalRevenue,
            'completed_orders' => $completedOrders,
            'items_sold' => $itemsSold,
            'pending_orders' => $pendingOrders,
            'total_bar_orders' => $totalBarOrders,
            'total_kitchen_orders' => $totalKitchenOrders,
            'summary_day' => $summaryDay,
            'summary_week' => $summaryWeek,
            'summary_month' => $summaryMonth,
            'top_items' => $topItems,
            'tables' => $tables,
        ],
        'meta' => [
            'generated_at' => $now,
            'scope' => $scope,
        ],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to generate report']);
    error_log('v1 reports error: ' . $e->getMessage());
}
