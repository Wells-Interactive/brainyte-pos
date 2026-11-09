<?php
declare(strict_types=1);
/** Authenticated customer profile management. */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

$auth = require_role(['customer']);
$pdo = get_db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = $pdo->prepare('SELECT u.name,u.email,p.phone_number,p.address FROM users u JOIN customer_profiles p ON p.user_id=u.id WHERE u.id=:id');
    $q->execute([':id' => $auth['id']]);
    json_response(['success' => true, 'data' => $q->fetch() ?: []]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') json_response(['error' => 'Method not allowed'], 405);
try { $body = get_json_body(); } catch (JsonException $e) { json_response(['error' => 'Invalid JSON body'], 400); }
$name = trim((string)($body['name'] ?? ''));
$email = strtolower(trim((string)($body['email'] ?? '')));
$phone = trim((string)($body['phone_number'] ?? ''));
$address = trim((string)($body['address'] ?? ''));
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($phone) < 7) json_response(['error' => 'Name, valid email and phone number are required'], 422);
$existing = $pdo->prepare('SELECT id FROM users WHERE email=:email AND id<>:id');
$existing->execute([':email'=>$email, ':id'=>$auth['id']]);
if ($existing->fetch()) json_response(['error' => 'An account with this email already exists'], 409);
$now = date('Y-m-d H:i:s');
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET name=:name,email=:email WHERE id=:id')->execute([':name'=>$name, ':email'=>$email, ':id'=>$auth['id']]);
    $pdo->prepare('UPDATE customer_profiles SET phone_number=:phone,address=:address,updated_at=:now WHERE user_id=:id')->execute([':phone'=>$phone, ':address'=>$address ?: null, ':now'=>$now, ':id'=>$auth['id']]);
    $pdo->commit();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
json_response(['success'=>true,'data'=>['message'=>'Profile updated successfully']]);
