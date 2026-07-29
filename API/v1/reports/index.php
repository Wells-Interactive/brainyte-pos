<?php
declare(strict_types=1);
/**
 * API v1 - Reports & Statistics
 *
 * GET /API/v1/reports/index.php - Get dashboard statistics
 *   Query: ?scope=day|week|month|year|custom&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 *   Query: ?top_items=10 (default: 10)
 *   Query: ?report=sales|products|staff|payments|operations (default: dashboard)
 *
 * This is the stable public API for the Flutter application.
 * Revenue is calculated from completed/paid orders only.
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
$report = trim((string)($_GET['report'] ?? 'dashboard'));
$topItemsLimit = isset($_GET['top_items']) ? min(50, max(1, (int)$_GET['top_items'])) : 10;
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));

$pdo = get_db();
$now = date('Y-m-d H:i:s');

// ============================================================
// Build date range from scope
// ============================================================
switch ($scope) {
    case 'year':
        $fromDate = date('Y-01-01') . ' 00:00:00';
        $toDate = $now;
        break;
    case 'custom':
        if ($startDate !== '' && $endDate !== '') {
            $fromDate = $startDate . ' 00:00:00';
            $toDate = $endDate . ' 23:59:59';
        } else {
            $fromDate = date('Y-m-d') . ' 00:00:00';
            $toDate = $now;
        }
        break;
    case 'week':
        $fromDate = date('Y-m-d', strtotime('-7 days')) . ' 00:00:00';
        $toDate = $now;
        break;
    case 'month':
        $fromDate = date('Y-m-d', strtotime('-30 days')) . ' 00:00:00';
        $toDate = $now;
        break;
    case 'day':
    default:
        $fromDate = date('Y-m-d') . ' 00:00:00';
        $toDate = $now;
        break;
}

$dayStart = date('Y-m-d') . ' 00:00:00';

try {
    // ============================================================
    // SALES REPORT
    // ============================================================
    if ($report === 'sales') {
        // Gross sales (total of all completed orders)
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS gross_sales
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.status = 'completed' AND o.updated_at >= :from_date AND o.updated_at <= :to_date"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $grossSales = (float)$stmt->fetchColumn();

        // VAT collected (from settings)
        $vatStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'vat_rate' LIMIT 1");
        $vatRow = $vatStmt->fetch();
        $vatRate = $vatRow ? (float)$vatRow['setting_value'] : 0.0;
        $vatCollected = $grossSales * ($vatRate / 100);

        // Net sales
        $netSales = $grossSales - $vatCollected;

        // Discounts (placeholder - can be expanded with discount table)
        $discounts = 0.0;

        // Number of orders
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT o.id) FROM orders o
             WHERE o.status = 'completed' AND o.updated_at >= :from_date AND o.updated_at <= :to_date"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $orderCount = (int)$stmt->fetchColumn();

        // Items sold
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(oi.quantity), 0)
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.status = 'completed' AND o.updated_at >= :from_date AND o.updated_at <= :to_date"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $itemsSold = (int)$stmt->fetchColumn();

        // Average order value
        $avgOrderValue = $orderCount > 0 ? $grossSales / $orderCount : 0.0;

        // Sales by day (for charts)
        $stmt = $pdo->prepare(
            "SELECT DATE(o.updated_at) AS sale_date, COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS daily_sales,
                    COUNT(DISTINCT o.id) AS daily_orders
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.status = 'completed' AND o.updated_at >= :from_date AND o.updated_at <= :to_date
             GROUP BY DATE(o.updated_at)
             ORDER BY sale_date ASC"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $salesByDay = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => [
                'report' => 'sales',
                'scope' => $scope,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'gross_sales' => $grossSales,
                'discounts' => $discounts,
                'vat_rate' => $vatRate,
                'vat_collected' => $vatCollected,
                'net_sales' => $netSales,
                'order_count' => $orderCount,
                'items_sold' => $itemsSold,
                'average_order_value' => round($avgOrderValue, 2),
                'sales_by_day' => $salesByDay,
            ],
            'meta' => [
                'generated_at' => $now,
                'scope' => $scope,
            ],
        ]);
        exit;
    }

    // ============================================================
    // PRODUCTS REPORT
    // ============================================================
    if ($report === 'products') {
        // Top food items
        $stmt = $pdo->prepare(
            "SELECT mi.name AS item_name, mi.category, SUM(oi.quantity) AS quantity_sold,
                    SUM(oi.quantity * oi.unit_price) AS total_revenue
             FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             JOIN orders o ON o.id = oi.order_id
             WHERE o.status = 'completed' AND o.updated_at >= :from_date AND o.updated_at <= :to_date
             GROUP BY oi.menu_item_id
             ORDER BY quantity_sold DESC
             LIMIT :top_items"
        );
        $stmt->bindValue(':from_date', $fromDate, PDO::PARAM_STR);
        $stmt->bindValue(':to_date', $toDate, PDO::PARAM_STR);
        $stmt->bindValue(':top_items', $topItemsLimit, PDO::PARAM_INT);
        $stmt->execute();
        $topItems = $stmt->fetchAll();

        // Top categories
        $stmt = $pdo->prepare(
            "SELECT mi.category, SUM(oi.quantity) AS quantity_sold,
                    SUM(oi.quantity * oi.unit_price) AS total_revenue,
                    COUNT(DISTINCT oi.menu_item_id) AS unique_items
             FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             JOIN orders o ON o.id = oi.order_id
             WHERE o.status = 'completed' AND o.updated_at >= :from_date AND o.updated_at <= :to_date
             GROUP BY mi.category
             ORDER BY total_revenue DESC"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $categories = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => [
                'report' => 'products',
                'scope' => $scope,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'top_items' => $topItems,
                'categories' => $categories,
            ],
            'meta' => [
                'generated_at' => $now,
                'scope' => $scope,
            ],
        ]);
        exit;
    }

    // ============================================================
    // STAFF REPORT
    // ============================================================
    if ($report === 'staff') {
        // Sales by waiter
        $stmt = $pdo->prepare(
            "SELECT u.id AS waiter_id, u.name AS waiter_name,
                    COUNT(DISTINCT o.id) AS orders_count,
                    COALESCE(SUM(oi.quantity), 0) AS items_sold,
                    COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS total_sales
             FROM users u
             JOIN orders o ON o.waiter_id = u.id
             JOIN order_items oi ON oi.order_id = o.id
             WHERE o.status = 'completed' AND o.updated_at >= :from_date AND o.updated_at <= :to_date
             GROUP BY u.id
             ORDER BY total_sales DESC"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $waiterSales = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => [
                'report' => 'staff',
                'scope' => $scope,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'waiter_sales' => $waiterSales,
            ],
            'meta' => [
                'generated_at' => $now,
                'scope' => $scope,
            ],
        ]);
        exit;
    }

    // ============================================================
    // PAYMENTS REPORT
    // ============================================================
    if ($report === 'payments') {
        // Payment method breakdown
        $stmt = $pdo->prepare(
            "SELECT o.payment_method,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS total_amount
             FROM orders o
             JOIN order_items oi ON oi.order_id = o.id
             WHERE o.status = 'completed' AND o.updated_at >= :from_date AND o.updated_at <= :to_date
             GROUP BY o.payment_method
             ORDER BY total_amount DESC"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $paymentBreakdown = $stmt->fetchAll();

        // Payment status breakdown
        $stmt = $pdo->prepare(
            "SELECT o.payment_status,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS total_amount
             FROM orders o
             JOIN order_items oi ON oi.order_id = o.id
             WHERE o.updated_at >= :from_date AND o.updated_at <= :to_date
             GROUP BY o.payment_status
             ORDER BY total_amount DESC"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $statusBreakdown = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => [
                'report' => 'payments',
                'scope' => $scope,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'payment_methods' => $paymentBreakdown,
                'payment_statuses' => $statusBreakdown,
            ],
            'meta' => [
                'generated_at' => $now,
                'scope' => $scope,
            ],
        ]);
        exit;
    }

    // ============================================================
    // OPERATIONS REPORT
    // ============================================================
    if ($report === 'operations') {
        // Order status breakdown
        $stmt = $pdo->prepare(
            "SELECT o.status, COUNT(*) AS order_count
             FROM orders o
             WHERE o.created_at >= :from_date AND o.created_at <= :to_date
             GROUP BY o.status
             ORDER BY order_count DESC"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $orderStatusBreakdown = $stmt->fetchAll();

        // Cancelled/voided items
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cancelled_count,
                    COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS cancelled_value
             FROM orders o
             JOIN order_items oi ON oi.order_id = o.id
             WHERE o.status = 'completed' AND o.payment_status = 'voided'
               AND o.updated_at >= :from_date AND o.updated_at <= :to_date"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $cancelled = $stmt->fetch();

        // Department breakdown
        $stmt = $pdo->prepare(
            "SELECT oi.routed_to,
                    COUNT(*) AS item_count,
                    COALESCE(SUM(oi.quantity), 0) AS quantity_sold,
                    COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS total_revenue
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.status = 'completed' AND o.updated_at >= :from_date AND o.updated_at <= :to_date
             GROUP BY oi.routed_to"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $departmentBreakdown = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => [
                'report' => 'operations',
                'scope' => $scope,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'order_statuses' => $orderStatusBreakdown,
                'cancelled_orders' => (int)($cancelled['cancelled_count'] ?? 0),
                'cancelled_value' => (float)($cancelled['cancelled_value'] ?? 0),
                'department_breakdown' => $departmentBreakdown,
            ],
            'meta' => [
                'generated_at' => $now,
                'scope' => $scope,
            ],
        ]);
        exit;
    }

    // ============================================================
    // DASHBOARD REPORT (default - backward compatible)
    // ============================================================

    // Total revenue (completed orders in scope)
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS total_revenue
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.status = 'completed' AND o.updated_at >= :from_date AND o.updated_at <= :to_date"
    );
    $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
    $totalRevenue = (float)$stmt->fetchColumn();

    // Completed orders count
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders WHERE status = 'completed' AND updated_at >= :from_date AND updated_at <= :to_date"
    );
    $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
    $completedOrders = (int)$stmt->fetchColumn();

    // Items sold
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.quantity), 0)
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.status = 'completed' AND o.updated_at >= :from_date AND o.updated_at <= :to_date"
    );
    $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
    $itemsSold = (int)$stmt->fetchColumn();

    // Pending orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status NOT IN ('completed')");
    $stmt->execute();
    $pendingOrders = (int)$stmt->fetchColumn();

    // Bar orders (total)
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE oi.routed_to = 'bar' AND o.created_at >= :from_date AND o.created_at <= :to_date"
    );
    $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
    $totalBarOrders = (int)$stmt->fetchColumn();

    // Kitchen orders (total)
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE oi.routed_to = 'kitchen' AND o.created_at >= :from_date AND o.created_at <= :to_date"
    );
    $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
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
         WHERE o.status = 'completed' AND o.updated_at >= :from_date AND o.updated_at <= :to_date
         GROUP BY oi.menu_item_id
         ORDER BY quantity_sold DESC
         LIMIT :top_items"
    );
    $stmt->bindValue(':from_date', $fromDate, PDO::PARAM_STR);
    $stmt->bindValue(':to_date', $toDate, PDO::PARAM_STR);
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

