<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Lagos');

function set_now(): string
{
    return date('Y-m-d H:i:s');
}

function json_response(array $payload, int $status = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_json_body(): array
{
    $body = file_get_contents('php://input');
    if ($body === false || $body === '') {
        return [];
    }

    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : [];
}

function safe_text(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function require_login(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['user']['role'])) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function require_admin(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $role = $_SESSION['user']['role'] ?? null;
    if (empty($role) || !in_array($role, ['admin', 'owner'], true)) {
        http_response_code(403);
        json_response(['error' => 'Forbidden'], 403);
    }
}

function require_manager(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $role = $_SESSION['user']['role'] ?? null;
    if (empty($role) || !in_array($role, ['admin', 'owner', 'manager', 'supervisor'], true)) {
        http_response_code(403);
        json_response(['error' => 'Forbidden'], 403);
    }
}

function is_admin_or_owner(): bool
{
    $role = $_SESSION['user']['role'] ?? null;
    return in_array($role, ['admin', 'owner'], true);
}

function is_manager_or_above(): bool
{
    $role = $_SESSION['user']['role'] ?? null;
    return in_array($role, ['admin', 'owner', 'manager', 'supervisor'], true);
}

function generate_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf_token(): void
{
    $body = get_json_body();
    $token = $body['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        http_response_code(419);
        json_response(['error' => 'CSRF token mismatch'], 419);
    }
}

function regenerate_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $oldData = $_SESSION;
    session_regenerate_id(true);
    $_SESSION = $oldData;
}

function get_session_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? 0);
}

function get_session_username(): string
{
    return trim((string)($_SESSION['username'] ?? $_SESSION['user']['name'] ?? ''));
}

function get_session_role(): string
{
    return trim((string)($_SESSION['role'] ?? $_SESSION['user']['role'] ?? ''));
}
