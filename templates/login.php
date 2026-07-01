<main class="login-shell">
  <div class="auth-grid">
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

    <section class="login-panel register-panel">
      <h2>ユーザー登録</h2>
      <p class="login-note">登録コードを持っている人だけが、メール認証後に登録できます。</p>

      <?php if (!empty($notice)): ?>
        <p class="success-message"><?= h((string)$notice) ?></p>
      <?php endif; ?>
      <?php if (!empty($registerError)): ?>
        <p class="error-message"><?= h((string)$registerError) ?></p>
      <?php endif; ?>

      <form class="auth-subform" method="post" action="./">
        <input type="hidden" name="action" value="request_register">
        <input type="hidden" name="csrf_token" value="<?= h((string)$csrfToken) ?>">

        <label>
          <span>登録コード</span>
          <input name="registration_code" type="text" autocomplete="off" required>
        </label>

        <label>
          <span>メールアドレス</span>
          <input name="email" type="email" value="<?= h((string)($registerEmail ?? '')) ?>" autocomplete="email" required>
        </label>

        <button type="submit">4桁番号を送信</button>
      </form>

      <form class="auth-subform" method="post" action="./">
        <input type="hidden" name="action" value="complete_register">
        <input type="hidden" name="csrf_token" value="<?= h((string)$csrfToken) ?>">

        <label>
          <span>メールアドレス</span>
          <input name="email" type="email" value="<?= h((string)($registerEmail ?? '')) ?>" autocomplete="email" required>
        </label>

        <label>
          <span>メールで届いた4桁番号</span>
          <input name="email_code" type="text" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="one-time-code" required>
        </label>

        <label>
          <span>ログインID</span>
          <input name="login_id" type="text" autocomplete="username" required>
        </label>

        <label>
          <span>表示名</span>
          <input name="display_name" type="text" autocomplete="name">
        </label>

        <label>
          <span>パスワード</span>
          <input name="password" type="password" autocomplete="new-password" minlength="8" required>
        </label>

        <button type="submit">登録を完了</button>
      </form>
    </section>
  </div>
</main>
