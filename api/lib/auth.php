<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], $token);
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $user = find_user_by_id((int)$_SESSION['user_id']);
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    ensure_user_storage($user);
    ensure_default_outline_file($user);
    return $user;
}

function login_user(string $loginId, string $password): bool
{
    $user = find_user_by_login_id($loginId);
    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    unset($_SESSION['outline_file_id']);
    csrf_token();
    ensure_user_storage($user);
    ensure_default_outline_file($user);
    return true;
}

function current_outline_file(array $user): array
{
    $fileId = isset($_SESSION['outline_file_id']) ? (int)$_SESSION['outline_file_id'] : 0;
    if ($fileId > 0) {
        $file = get_outline_file($user, $fileId);
        if ($file) {
            return $file;
        }
    }

    $file = first_outline_file($user);
    $_SESSION['outline_file_id'] = (int)$file['id'];
    return $file;
}

function select_outline_file(array $user, int $fileId): ?array
{
    $file = get_outline_file($user, $fileId);
    if (!$file) {
        return null;
    }

    $_SESSION['outline_file_id'] = (int)$file['id'];
    return $file;
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }

    session_destroy();
}
