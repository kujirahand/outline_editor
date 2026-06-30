<div class="app-shell">
  <header class="topbar">
    <div>
      <h1>Outline Editor</h1>
      <p id="save-status" class="save-status">読み込み中</p>
    </div>
    <div class="topbar-actions">
      <span class="user-name"><?= h((string)($user['display_name'] ?: $user['login_id'])) ?></span>
      <div class="topbar-menu">
        <button id="menu-toggle-button" class="menu-toggle-button" type="button" aria-label="メニュー" aria-expanded="false" aria-controls="topbar-menu-panel">☰</button>
        <div id="topbar-menu-panel" class="topbar-menu-panel" hidden>
          <button id="export-button" class="menu-item-button" type="button">Markdown</button>
          <form class="menu-item-form" method="post" action="./">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf_token" value="<?= h((string)$csrfToken) ?>">
            <button class="menu-item-button" type="submit">ログアウト</button>
          </form>
        </div>
      </div>
    </div>
  </header>

  <main class="editor-shell">
    <div class="toolbar" aria-label="編集ツール">
      <button id="add-root-button" type="button">ルート追加</button>
    </div>
    <section id="outline" class="outline" aria-label="アウトライン"></section>
  </main>

  <div id="export-panel" class="export-panel" hidden>
    <button id="export-close-button" class="export-close-button" type="button" aria-label="Markdown出力を閉じる">×</button>
    <textarea id="export-text" class="export-text" readonly aria-label="Markdown export"></textarea>
  </div>
</div>
<script src="assets/app.js" defer></script>
