<main class="login-shell">
  <form class="login-panel" method="post" action="./">
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="csrf_token" value="<?= h((string)$csrfToken) ?>">
    <h1>Outline Editor</h1>
    <p class="login-note">ログインしてアウトラインを編集します。</p>

    <?php if (!empty($error)): ?>
      <p class="error-message"><?= h((string)$error) ?></p>
    <?php endif; ?>

    <label>
      <span>ログインID</span>
      <input name="login_id" type="text" value="admin" autocomplete="username" required>
    </label>

    <label>
      <span>パスワード</span>
      <input name="password" type="password" value="outline" autocomplete="current-password" required>
    </label>

    <button type="submit">ログイン</button>
    <p class="login-hint">初期ユーザー: admin / outline</p>
  </form>
</main>

