<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

date_default_timezone_set('Africa/Lagos');

session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_response(['error' => 'Method not allowed'], 405);
}

$action = trim((string)($_GET['action'] ?? ''));

// Handle add_user action for admin/owner
if ($action === 'add_user') {
    require_admin();
    try {
        $body = get_json_body();
    } catch (JsonException $exception) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

    $name = trim((string)($body['name'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $role = trim((string)($body['role'] ?? ''));

    if ($name === '' || $email === '' || $password === '' || !in_array($role, ['waiter', 'kitchen', 'bar', 'manager', 'supervisor', 'admin'], true)) {
        json_response(['error' => 'Name, email, password and valid role are required'], 400);
    }

    $pdo = get_db();
    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $checkStmt->execute([':email' => $email]);
    if ($checkStmt->fetch()) {
        json_response(['error' => 'A user with this email already exists'], 409);
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $insertStmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)');
    $insertStmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':password_hash' => $passwordHash,
        ':role' => $role,
    ]);

    json_response(['success' => true, 'user_id' => (int)$pdo->lastInsertId()]);
    exit;
}

try {
    $body = get_json_body();
} catch (JsonException $exception) {
    json_response(['error' => 'Invalid JSON body'], 400);
}

$email = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($email === '' || $password === '') {
    json_response(['error' => 'Email and password are required'], 400);
}

$pdo = get_db();
$stmt = $pdo->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

if (empty($user) || !password_verify($password, $user['password_hash'])) {
    json_response(['error' => 'Invalid credentials'], 401);
}

// Regenerate session ID after successful login
session_regenerate_id(true);

// Store session with simplified keys
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['username'] = $user['name'];
$_SESSION['role'] = $user['role'];

// Also keep legacy user array for backward compatibility
$_SESSION['user'] = [
    'id' => (int)$user['id'],
    'name' => $user['name'],
    'email' => $user['email'],
    'role' => $user['role'],
];

// Generate CSRF token for the session
generate_csrf_token();

json_response([
    'success' => true,
    'user' => [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'role' => $user['role'],
    ],
    'csrf_token' => $_SESSION['csrf_token'],
]);
