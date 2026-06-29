<div class="app-shell">
  <header class="topbar">
    <div>
      <h1>Outline Editor</h1>
      <p id="save-status" class="save-status">読み込み中</p>
    </div>
    <div class="topbar-actions">
      <span class="user-name"><?= h((string)($user['display_name'] ?: $user['login_id'])) ?></span>
      <form method="post" action="./">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= h((string)$csrfToken) ?>">
        <button class="secondary-button" type="submit">ログアウト</button>
      </form>
    </div>
  </header>

  <main class="editor-shell">
    <div class="toolbar" aria-label="編集ツール">
      <button id="add-root-button" type="button">ルート追加</button>
      <button id="export-button" type="button">Markdown</button>
    </div>
    <section id="outline" class="outline" aria-label="アウトライン"></section>
  </main>

  <textarea id="export-text" class="export-text" readonly aria-label="Markdown export"></textarea>
</div>
<script src="assets/app.js" defer></script>

