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
          <button id="file-picker-open-button" class="menu-item-button file-picker-open-button" type="button">ファイル切替</button>
          <button id="file-create-button" class="menu-item-button" type="button">新規ファイル</button>
          <button id="add-root-button" class="menu-item-button" type="button">ルート追加</button>
          <button id="export-button" class="menu-item-button" type="button">Markdown</button>
          <?php foreach (($allMenuPlugins ?? $menuPlugins ?? []) as $menuPlugin): ?>
            <?php $pluginEnabled = ($menuPluginEnabled[(string)$menuPlugin['name']] ?? true); ?>
            <button class="menu-item-button plugin-menu-button" type="button" data-plugin-menu-name="<?= h((string)$menuPlugin['name']) ?>"<?= $pluginEnabled ? '' : ' hidden' ?>><?= h((string)$menuPlugin['label']) ?></button>
          <?php endforeach; ?>
          <button id="settings-open-button" class="menu-item-button" type="button">設定</button>
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
    <section id="outline" class="outline" aria-label="アウトライン"></section>
  </main>

  <div id="file-picker-panel" class="file-picker-panel" role="dialog" aria-modal="true" aria-labelledby="file-picker-title" hidden>
    <div class="file-picker-dialog">
      <header class="file-picker-header">
        <div>
          <h2 id="file-picker-title">ファイルを選択</h2>
          <p id="file-picker-current" class="file-picker-current"></p>
        </div>
        <button id="file-picker-close-button" class="file-picker-close-button" type="button" aria-label="ファイル選択を閉じる">×</button>
      </header>
      <div id="file-picker-list" class="file-picker-list"></div>
    </div>
  </div>

  <div id="export-panel" class="export-panel" hidden>
    <button id="export-close-button" class="export-close-button" type="button" aria-label="Markdown出力を閉じる">×</button>
    <textarea id="export-text" class="export-text" readonly aria-label="Markdown export"></textarea>
  </div>

  <div id="settings-panel" class="settings-panel" role="dialog" aria-modal="true" aria-labelledby="settings-title" hidden>
    <div class="settings-dialog">
      <header class="settings-header">
        <h2 id="settings-title">設定</h2>
        <button id="settings-close-button" class="settings-close-button" type="button" aria-label="設定を閉じる">×</button>
      </header>
      <form id="settings-form" class="settings-content">
        <section class="settings-section" aria-labelledby="settings-menu-plugin-title">
          <h3 id="settings-menu-plugin-title">メニュープラグイン</h3>
          <div class="settings-plugin-list">
            <?php foreach (($allMenuPlugins ?? $menuPlugins ?? []) as $menuPlugin): ?>
              <?php $pluginEnabled = ($menuPluginEnabled[(string)$menuPlugin['name']] ?? true); ?>
              <label class="settings-plugin-row">
                <input class="settings-plugin-toggle" type="checkbox" data-plugin-name="<?= h((string)$menuPlugin['name']) ?>"<?= $pluginEnabled ? ' checked' : '' ?>>
                <span><?= h((string)$menuPlugin['label']) ?></span>
              </label>
            <?php endforeach; ?>
            <?php if (($allMenuPlugins ?? $menuPlugins ?? []) === []): ?>
              <p class="settings-empty">プラグインがありません。</p>
            <?php endif; ?>
          </div>
        </section>
        <footer class="settings-footer">
          <span id="settings-status" class="settings-status" aria-live="polite"></span>
          <button id="settings-save-button" type="submit">保存</button>
        </footer>
      </form>
    </div>
  </div>

  <div id="plugin-popup-panel" class="plugin-popup-panel" role="dialog" aria-modal="true" aria-labelledby="plugin-popup-title" hidden>
    <div class="plugin-popup-dialog">
      <header class="plugin-popup-header">
        <h2 id="plugin-popup-title"></h2>
        <button id="plugin-popup-close-button" class="plugin-popup-close-button" type="button" aria-label="プラグイン画面を閉じる">×</button>
      </header>
      <div id="plugin-popup-content" class="plugin-popup-content"></div>
    </div>
  </div>

  <?php foreach (($allMenuPlugins ?? $menuPlugins ?? []) as $menuPlugin): ?>
    <template id="plugin-menu-template-<?= h((string)$menuPlugin['name']) ?>"><?= (string)$menuPlugin['html'] ?></template>
  <?php endforeach; ?>
</div>
<script src="<?= h(asset_url('assets/app.js')) ?>" defer></script>
