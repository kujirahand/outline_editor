<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h((string)($csrfToken ?? '')) ?>">
  <title><?= h((string)($title ?? 'Outline Editor')) ?></title>
  <link rel="stylesheet" href="<?= h(asset_url('assets/style.css')) ?>">
</head>
<body>
  <?= $content ?>
</body>
</html>
