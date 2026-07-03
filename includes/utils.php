<?php
declare(strict_types=1);

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
    if (empty($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'admin') {
        http_response_code(403);
        json_response(['error' => 'Forbidden'], 403);
    }
}
