<?php

declare(strict_types=1);

require_once __DIR__ . '/api/lib/auth.php';
require_once __DIR__ . '/api/lib/menu_plugin.php';
require_once __DIR__ . '/api/lib/template.php';

$error = '';
$registerError = '';
$notice = '';
$registerEmail = '';

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
    } elseif ($action === 'request_register') {
        $registerEmail = normalize_email((string)($_POST['email'] ?? ''));
        $registerError = request_registration_verification(
            (string)($_POST['registration_code'] ?? ''),
            $registerEmail,
        ) ?? '';
        if ($registerError === '') {
            $notice = '認証番号をメールで送信しました。1時間以内に登録を完了してください。';
        }
    } elseif ($action === 'complete_register') {
        $registerEmail = normalize_email((string)($_POST['email'] ?? ''));
        $registerError = complete_registration(
            $registerEmail,
            (string)($_POST['email_code'] ?? ''),
            (string)($_POST['login_id'] ?? ''),
            (string)($_POST['password'] ?? ''),
            (string)($_POST['display_name'] ?? ''),
        ) ?? '';
        if ($registerError === '') {
            header('Location: ./');
            exit;
        }
    } elseif ($action === 'logout') {
        logout_user();
        header('Location: ./');
        exit;
    }
}

$user = current_user();
$csrfToken = csrf_token();
$allMenuPlugins = $user ? load_menu_plugins([
    'user' => $user,
    'csrfToken' => $csrfToken,
]) : [];
$menuPluginNames = array_map(static fn(array $plugin): string => (string)$plugin['name'], $allMenuPlugins);
$menuPluginEnabled = $user ? menu_plugin_enabled_map($user, $menuPluginNames) : [];
$enabledMenuPlugins = array_values(array_filter(
    $allMenuPlugins,
    static fn(array $plugin): bool => $menuPluginEnabled[(string)$plugin['name']] ?? true,
));
echo render_page($user ? 'outline.php' : 'login.php', [
    'title' => $user ? 'Outline Editor' : 'Login',
    'user' => $user,
    'error' => $error,
    'registerError' => $registerError,
    'notice' => $notice,
    'registerEmail' => $registerEmail,
    'csrfToken' => $csrfToken,
    'menuPlugins' => $enabledMenuPlugins,
    'allMenuPlugins' => $allMenuPlugins,
    'menuPluginEnabled' => $menuPluginEnabled,
]);
