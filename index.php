<?php

declare(strict_types=1);

require_once __DIR__ . '/api/lib/auth.php';
require_once __DIR__ . '/api/lib/menu_plugin.php';
require_once __DIR__ . '/api/lib/template.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'セッションが切れました。もう一度試してください。';
    } elseif ($action === 'login') {
        $loginId = trim((string)($_POST['login_id'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if (login_user($loginId, $password)) {
            header('Location: ./');
            exit;
        }

        $error = 'ログインIDまたはパスワードが違います。';
    } elseif ($action === 'logout') {
        logout_user();
        header('Location: ./');
        exit;
    }
}

$user = current_user();
$csrfToken = csrf_token();
echo render_page($user ? 'outline.php' : 'login.php', [
    'title' => $user ? 'Outline Editor' : 'Login',
    'user' => $user,
    'error' => $error,
    'csrfToken' => $csrfToken,
    'menuPlugins' => $user ? load_menu_plugins([
        'user' => $user,
        'csrfToken' => $csrfToken,
    ]) : [],
]);
