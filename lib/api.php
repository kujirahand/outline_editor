<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $input = json_decode($raw, true);
    if (!is_array($input)) {
        json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    return $input;
}

function require_api_user(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['ok' => false, 'error' => 'Login required'], 401);
    }
    return $user;
}

function require_json_post(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'POST required'], 405);
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verify_csrf_token($token)) {
        json_response(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
    }

    return read_json_body();
}

function input_int(array $input, string $key, bool $nullable = false): ?int
{
    if (!array_key_exists($key, $input)) {
        if ($nullable) {
            return null;
        }
        json_response(['ok' => false, 'error' => "Missing $key"], 400);
    }

    if ($input[$key] === null && $nullable) {
        return null;
    }

    if (!is_int($input[$key]) && !(is_string($input[$key]) && preg_match('/\A-?\d+\z/', $input[$key]))) {
        json_response(['ok' => false, 'error' => "$key must be integer"], 400);
    }

    return (int)$input[$key];
}

function input_text(array $input, string $key, int $maxLength = 20000): string
{
    if (!array_key_exists($key, $input) || !is_string($input[$key])) {
        json_response(['ok' => false, 'error' => "$key must be string"], 400);
    }

    if (strlen($input[$key]) > $maxLength) {
        json_response(['ok' => false, 'error' => "$key is too long"], 400);
    }

    return $input[$key];
}
