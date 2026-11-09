<?php
declare(strict_types=1);
/** Customer home-delivery registration and email verification. */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

function delivery_is_enabled(PDO $pdo): bool {
    $row = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'home_delivery_enabled' LIMIT 1")->fetch();
    return $row && $row['setting_value'] === '1';
}
function send_delivery_code(PDO $pdo, int $userId, string $email): bool {
    $code = (string)random_int(100000, 999999);
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('UPDATE email_verification_codes SET consumed_at = :now WHERE user_id = :user_id AND consumed_at IS NULL')
        ->execute([':now' => $now, ':user_id' => $userId]);
    $pdo->prepare('INSERT INTO email_verification_codes (user_id, code_hash, expires_at, created_at) VALUES (:user_id, :hash, :expires, :now)')
        ->execute([':user_id' => $userId, ':hash' => password_hash($code, PASSWORD_DEFAULT), ':expires' => date('Y-m-d H:i:s', strtotime('+10 minutes')), ':now' => $now]);
    return mail(
        $email,
        'Brainyte delivery verification code',
        "Your verification code is {$code}. It expires in 10 minutes.",
        "Content-Type: text/plain; charset=UTF-8"
    );
}

$pdo = get_db();
if (!delivery_is_enabled($pdo)) json_response(['error' => 'Home delivery is currently unavailable'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);
try { $body = get_json_body(); } catch (JsonException $e) { json_response(['error' => 'Invalid JSON body'], 400); }
$action = (string)($body['action'] ?? 'register');

if ($action === 'register') {
    $name = trim((string)($body['name'] ?? '')); $email = strtolower(trim((string)($body['email'] ?? '')));
    $phone = trim((string)($body['phone_number'] ?? '')); $password = (string)($body['password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($phone) < 7 || strlen($password) < 8) {
        json_response(['error' => 'Name, valid email, phone number and an 8-character password are required'], 422);
    }
    $existing = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = :email LIMIT 1'); $existing->execute([':email' => $email]);
    if ($existing->fetch()) json_response(['error' => 'An account with this email already exists'], 409);
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO users (name, email, password_hash, role, created_at) VALUES (:name,:email,:password,'customer',:now)")
            ->execute([':name' => $name, ':email' => $email, ':password' => password_hash($password, PASSWORD_DEFAULT), ':now' => $now]);
        $userId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO customer_profiles (user_id, phone_number, created_at, updated_at) VALUES (:id,:phone,:now,:now)')
            ->execute([':id' => $userId, ':phone' => $phone, ':now' => $now]);
        if (!send_delivery_code($pdo, $userId, $email)) {
            throw new RuntimeException('verification_email_unavailable');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getMessage() === 'verification_email_unavailable') {
            json_response(['error' => 'Unable to send the verification email. Please try again later.'], 503);
        }
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            json_response(['error' => 'An account with this email already exists'], 409);
        }
        error_log('Customer registration failed: ' . $e->getMessage());
        json_response(['error' => 'Unable to complete registration. Please try again later.'], 500);
    }
    json_response(['success' => true, 'data' => ['message' => 'Verification code sent to your email']], 201);
}

if ($action === 'verify') {
    $email = strtolower(trim((string)($body['email'] ?? ''))); $code = trim((string)($body['code'] ?? ''));
    $stmt = $pdo->prepare("SELECT c.id,c.code_hash,c.expires_at,u.id AS user_id FROM email_verification_codes c JOIN users u ON u.id=c.user_id WHERE u.email=:email AND c.consumed_at IS NULL ORDER BY c.id DESC LIMIT 1");
    $stmt->execute([':email' => $email]); $row = $stmt->fetch();
    if (!$row || strtotime($row['expires_at']) < time() || !password_verify($code, $row['code_hash'])) json_response(['error' => 'Invalid or expired verification code'], 422);
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('UPDATE email_verification_codes SET consumed_at=:now WHERE id=:id')->execute([':now' => $now, ':id' => $row['id']]);
    $pdo->prepare('UPDATE customer_profiles SET email_verified_at=:now, updated_at=:now WHERE user_id=:id')->execute([':now' => $now, ':id' => $row['user_id']]);
    json_response(['success' => true, 'data' => ['message' => 'Email verified. You can now sign in.']]);
}
if ($action === 'resend') {
    $email = strtolower(trim((string)($body['email'] ?? ''))); $stmt = $pdo->prepare("SELECT u.id FROM users u JOIN customer_profiles p ON p.user_id=u.id WHERE u.email=:email AND u.role='customer' LIMIT 1"); $stmt->execute([':email' => $email]); $user = $stmt->fetch();
    if (!$user) json_response(['error' => 'Customer account not found'], 404);
    if (!send_delivery_code($pdo, (int)$user['id'], $email)) {
        json_response(['error' => 'Unable to send the verification email. Please try again later.'], 503);
    }
    json_response(['success' => true, 'data' => ['message' => 'Verification code sent to your email']]);
}
json_response(['error' => 'Unsupported action'], 400);
