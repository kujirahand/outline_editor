# プラグイン仕様

Outline Editor は、ハンバーガーメニューに項目を追加するメニュープラグインを読み込めます。

## メニュープラグイン

メニュープラグインは、次の場所に PHP ファイルとして配置します。

```text
plugins/menu/<name>.inc.php
```

`<name>` には英数字と `_` だけを使います。例: `example`, `my_tool`, `export_html`

ファイル内には、ファイル名に対応する関数を定義します。

```php
<?php

function get_menu_example(array $args): array
{
    return [
        'label' => 'サンプル',
        'html' => '<p>サブページHTML</p>',
    ];
}
```

上の例では、ファイル名は `plugins/menu/example.inc.php` です。

## 関数名

関数名は次の形式にします。

```text
get_menu_<name>
```

例えば、ファイル名が `memo.inc.php` なら、関数名は `get_menu_memo()` です。

## 引数

関数には `$args` が渡されます。

```php
function get_menu_example(array $args): array
```

現在渡される主な値:

```php
[
    'user' => $user,
    'csrfToken' => $csrfToken,
    'plugin_name' => '<name>',
]
```

`user` にはログイン中のユーザー情報が入ります。`csrfToken` は、プラグイン内のフォームや JavaScript から書き込み処理を行う場合に利用できます。`plugin_name` には、読み込まれているプラグイン名が入ります。

## 戻り値

関数は配列を返します。

必須項目:

| キー | 内容 |
|---|---|
| `label` | ハンバーガーメニューに表示するラベル |
| `html` | クリック時にポップアップへ表示する HTML |

例:

```php
return [
    'label' => 'ヘルプ',
    'html' => '<p>ここにヘルプを表示します。</p>',
];
```

`label` が空の場合、そのプラグインはメニューに登録されません。

## プラグイン API

メニュープラグインは、画面から呼び出す実行関数も定義できます。

```php
function exec_example(array $params): array
{
    return [
        'label' => '実行結果',
    ];
}
```

`plugins/menu/<name>.inc.php` に `exec_<name>($params)` を定義しておくと、次の URL から呼び出せます。

```text
GET /api/plugin.php?name=<name>&type=menu&params...
```

例:

```text
GET /api/plugin.php?name=dice&type=menu&count=2&sides=6
```

この API はログイン必須です。`type` は現在 `menu` のみ対応しています。`name` は英数字と `_` のみに制限されます。

レスポンス例:

```json
{
  "ok": true,
  "result": {
    "label": "3 + 5 = 8"
  }
}
```

プラグイン内に `exec_<name>()` が定義されていない場合は、エラーになります。

### HTML から API を呼ぶ

プラグイン HTML 内に `form[data-plugin-api-form]` を置くと、送信時に現在のプラグインの `exec_<name>($params)` が呼び出されます。

```php
return [
    'label' => 'サンプル',
    'html' => '
      <form data-plugin-api-form data-plugin-result="#sample-result">
        <input name="message" value="hello">
        <button type="submit">実行</button>
      </form>
      <div id="sample-result" class="plugin-api-result"></div>
    ',
];
```

フォーム内の入力値はクエリパラメータとして渡されます。レスポンスの `result.label` があれば、`data-plugin-result` で指定した要素に表示されます。

## 表示の流れ

1. ログイン済みユーザーがアウトライン画面を開く
2. サーバ側で `plugins/menu/*.inc.php` をファイル名順に読み込む
3. 各ファイルの `get_menu_<name>($args)` を呼び出す
4. 戻り値の `label` をハンバーガーメニューに追加する
5. メニュー項目をクリックすると、戻り値の `html` がポップアップ内に表示される

## HTML とセキュリティ

`html` はそのまま画面に埋め込まれます。プラグインはローカルに設置された信頼済みコードとして扱います。

ユーザー入力や外部データを `html` に含める場合は、プラグイン側で必ずエスケープしてください。

```php
function plugin_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

ユーザー入力をそのまま HTML に連結してはいけません。

## 読み込み対象外になる条件

次の場合、そのファイルはメニューに登録されません。

- ファイル名の `<name>` に英数字と `_` 以外が含まれる
- `get_menu_<name>()` が定義されていない
- 関数の戻り値が配列ではない
- 戻り値の `label` が空

これらは画面上には表示せず、PHP の `error_log()` に記録します。

## 最小例

`plugins/menu/about.inc.php`:

```php
<?php

function get_menu_about(array $args): array
{
    return [
        'label' => 'このアプリについて',
        'html' => '<p>Outline Editor は軽量なツリーエディタです。</p>',
    ];
}
```

このファイルを置くと、ハンバーガーメニューに「このアプリについて」が追加されます。クリックすると、`html` の内容がポップアップで表示されます。

## サイコロプラグイン例

`plugins/menu/dice.inc.php` には、サイコロを振る簡単なサンプルがあります。

```text
GET /api/plugin.php?name=dice&type=menu&count=2&sides=6
```

`exec_dice($params)` が `count` と `sides` を受け取り、出目、合計、表示用ラベルを返します。
