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

    start_user_session($user);
    return true;
}

function start_user_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    unset($_SESSION['outline_file_id']);
    csrf_token();
    ensure_user_storage($user);
    ensure_default_outline_file($user);
}

function request_registration_verification(string $registrationCode, string $email): ?string
{
    $registrationCode = trim($registrationCode);
    $email = normalize_email($email);

    if ($registrationCode === '') {
        return '登録コードを入力してください。';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'メールアドレスを正しく入力してください。';
    }
    if (find_user_by_email($email)) {
        return 'このメールアドレスは既に登録されています。';
    }

    $codeRow = find_available_registration_code($registrationCode);
    if (!$codeRow) {
        return '登録コードが正しくないか、既に使われています。';
    }

    $emailCode = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    create_email_verification((int)$codeRow['id'], $email, $emailCode);

    if (!send_registration_email($email, $emailCode)) {
        return '認証番号メールを送信できませんでした。';
    }

    return null;
}

function complete_registration(
    string $email,
    string $emailCode,
    string $loginId,
    string $password,
    string $displayName
): ?string {
    $email = normalize_email($email);
    $emailCode = trim($emailCode);
    $loginId = trim($loginId);
    $displayName = trim($displayName);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'メールアドレスを正しく入力してください。';
    }
    if (!preg_match('/\A\d{4}\z/', $emailCode)) {
        return 'メールで届いた4桁の番号を入力してください。';
    }
    if (!preg_match('/\A[a-zA-Z0-9_.@-]{3,100}\z/', $loginId)) {
        return 'ログインIDは3文字以上で、英数字と . _ @ - のみ使えます。';
    }
    if (strlen($password) < 8) {
        return 'パスワードは8文字以上にしてください。';
    }
    if ($displayName === '') {
        $displayName = $loginId;
    }

    $verification = find_valid_email_verification($email, $emailCode);
    if (!$verification) {
        return '認証番号が違うか、有効期限が切れています。';
    }

    try {
        $user = create_registered_user($verification, $loginId, $password, $displayName);
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    start_user_session($user);
    return null;
}

function send_registration_email(string $email, string $code): bool
{
    $subject = 'Outline Editor 認証番号';
    $body = "Outline Editor の登録認証番号: {$code}\n\nこの番号は1時間有効です。\n";
    $mailLog = trim((string)getenv('OUTLINE_MAIL_LOG'));
    if ($mailLog !== '') {
        $line = '[' . now_text() . '] to=' . $email . ' code=' . $code . "\n" . $body . "\n";
        return file_put_contents($mailLog, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    $headers = [];
    $from = trim((string)getenv('OUTLINE_MAIL_FROM'));
    if ($from !== '') {
        $headers[] = 'From: ' . $from;
    }
    if (function_exists('mb_send_mail')) {
        return mb_send_mail($email, $subject, $body, implode("\r\n", $headers));
    }
    return mail($email, $subject, $body, implode("\r\n", $headers));
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
