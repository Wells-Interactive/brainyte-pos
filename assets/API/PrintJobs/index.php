<?php
declare(strict_types=1);
/**
 * Print Jobs API Endpoint
 *
 * GET  /API/PrintJobs/index.php?department=kitchen  - List pending print jobs
 * GET  /API/PrintJobs/index.php?failed=1            - List failed print jobs
 * GET  /API/PrintJobs/index.php?statistics=1        - Print job statistics
 * POST /API/PrintJobs/index.php                     - Update print job status
 *
 * Body (POST): { job_id, action: 'complete'|'fail'|'retry'|'cancel', error?: string }
 *
 * This endpoint uses the App\PrintJob class for all operations.
 * Printing is a separate layer on top of the normal order workflow.
 * The database remains the source of truth.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

use App\PrintJob;

$authUser = require_role(['waiter', 'kitchen', 'bar', 'admin', 'owner', 'manager', 'supervisor']);
$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_db();
$printJob = new PrintJob($pdo);

// ============================================================
// GET - List print jobs
// ============================================================
if ($method === 'GET') {
    // Statistics
    if (isset($_GET['statistics'])) {
        json_response([
            'success' => true,
            'data' => $printJob->getStatistics(),
        ]);
        return;
    }

    // Failed jobs (admin/manager+)
    if (isset($_GET['failed'])) {
        if (!in_array($authUser['role'], ['admin', 'owner', 'manager'], true)) {
            json_response(['error' => 'Forbidden'], 403);
        }
        json_response([
            'success' => true,
            'data' => $printJob->getFailedJobs(),
        ]);
        return;
    }

    // Pending jobs by department
    $department = trim((string)($_GET['department'] ?? ''));
    if (!in_array($department, ['kitchen', 'bar'], true)) {
        json_response(['error' => 'Department parameter required (kitchen or bar)'], 400);
    }

    json_response([
        'success' => true,
        'data' => $printJob->getPendingJobs($department),
    ]);
    return;
}

// ============================================================
// POST - Update print job status
// ============================================================
if ($method !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $body = get_json_body();
} catch (JsonException $e) {
    json_response(['error' => 'Invalid JSON body'], 400);
}

$jobId = isset($body['job_id']) ? (int)$body['job_id'] : 0;
$action = trim((string)($body['action'] ?? ''));

if ($jobId <= 0 || !in_array($action, ['complete', 'fail', 'retry', 'cancel', 'printing'], true)) {
    json_response(['error' => 'Valid job_id and action (complete|fail|retry|cancel|printing) are required'], 400);
}

$result = false;
$message = '';

switch ($action) {
    case 'complete':
        $result = $printJob->markCompleted($jobId);
        $message = 'Print job marked as completed';
        break;
    case 'fail':
        $error = trim((string)($body['error'] ?? 'Unknown error'));
        $result = $printJob->markFailed($jobId, $error);
        $message = 'Print job marked as failed';
        break;
    case 'retry':
        $result = $printJob->retry($jobId);
        $message = 'Print job queued for retry';
        break;
    case 'cancel':
        $result = $printJob->cancel($jobId);
        $message = 'Print job cancelled';
        break;
    case 'printing':
        $result = $printJob->markPrinting($jobId);
        $message = 'Print job marked as printing';
        break;
}

if (!$result) {
    json_response(['error' => 'Unable to update print job. It may not exist or be in an invalid state.'], 400);
}

json_response([
    'success' => true,
    'data' => [
        'job_id' => $jobId,
        'action' => $action,
        'message' => $message,
    ],
]);
