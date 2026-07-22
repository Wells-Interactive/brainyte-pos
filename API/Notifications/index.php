<?php
declare(strict_types=1);
/**
 * Notifications API
 * 
 * GET  /API/Notifications/index.php           - Get pending notifications for current user
 * POST /API/Notifications/index.php?action=read   - Mark notification as read
 * POST /API/Notifications/index.php?action=create - Create a notification (admin only)
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

date_default_timezone_set('Africa/Lagos');

$method = $_SERVER['REQUEST_METHOD'];
$action = trim((string)($_GET['action'] ?? ''));

// ============================================================
// GET - Fetch pending notifications
// ============================================================
if ($method === 'GET') {
    $authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);
    $pdo = get_db();

    $notifications = get_pending_notifications($pdo, $authUser['role'], $authUser['id']);

    json_response([
        'success' => true,
        'data' => $notifications,
        'meta' => ['count' => count($notifications)],
    ]);
    return;
}

// ============================================================
// POST - Create or mark as read
// ============================================================
if ($method === 'POST') {
    try {
        $body = get_json_body();
    } catch (JsonException $e) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

    $pdo = get_db();

    if ($action === 'read') {
        // Mark notification as read
        $authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);
        $notificationId = isset($body['id']) ? (int)$body['id'] : 0;

        if ($notificationId <= 0) {
            json_response(['error' => 'Notification ID is required'], 400);
        }

        mark_notification_read($pdo, $notificationId);

        json_response(['success' => true, 'data' => ['id' => $notificationId, 'is_read' => 1]]);
        return;
    }

    if ($action === 'create') {
        // Create notification (admin/manager only)
        require_role(['admin', 'owner', 'manager', 'supervisor']);

        $targetRole = $body['target_role'] ?? 'all';
        $targetUserId = isset($body['target_user_id']) ? (int)$body['target_user_id'] : null;
        $title = trim((string)($body['title'] ?? ''));
        $bodyText = trim((string)($body['body'] ?? ''));
        $type = $body['type'] ?? 'order_update';
        $referenceType = $body['reference_type'] ?? null;
        $referenceId = isset($body['reference_id']) ? (int)$body['reference_id'] : null;

        if (empty($title) || empty($bodyText)) {
            json_response(['error' => 'Title and body are required'], 400);
        }

        $allowedRoles = ['waiter', 'kitchen', 'bar', 'manager', 'supervisor', 'admin', 'owner', 'all'];
        if (!in_array($targetRole, $allowedRoles, true)) {
            json_response(['error' => 'Invalid target role'], 400);
        }

        $notificationId = create_notification(
            $pdo,
            $targetRole,
            $targetUserId,
            $title,
            $bodyText,
            $type,
            $referenceType,
            $referenceId
        );

        json_response(['success' => true, 'data' => ['id' => $notificationId]]);
        return;
    }

    json_response(['error' => 'Invalid action'], 400);
    return;
}

http_response_code(405);
json_response(['error' => 'Method not allowed'], 405);
