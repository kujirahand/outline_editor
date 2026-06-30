# Tests

テストは対象ごとに分けています。

- `api/`: PHP の JSON API と認証、保存、ファイル切り替えを HTTP 経由で確認する
- `app/`: ブラウザ上の編集操作を Playwright で確認する

実行コマンド:

```sh
just test-api
just test-app
just test
```

`test-app` は Playwright と Chrome を使います。Chrome 以外の Chromium チャンネルを使う場合は `APP_TEST_BROWSER_CHANNEL` を指定します。
