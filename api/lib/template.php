<?php

declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function asset_updated_at(string $path): string
{
    $fullPath = __DIR__ . '/../../' . ltrim($path, '/');
    return is_file($fullPath) ? date('YmdHis', (int)filemtime($fullPath)) : '00000000000000';
}

function asset_url(string $path): string
{
    $version = asset_updated_at($path);
    return $path . '?v=' . rawurlencode($version);
}

function render_template(string $template, array $params = []): string
{
    $path = __DIR__ . '/../../templates/' . $template;
    if (!is_file($path)) {
        throw new RuntimeException('Template not found: ' . $template);
    }

    extract($params, EXTR_SKIP);

    ob_start();
    require $path;
    return (string)ob_get_clean();
}

function render_page(string $template, array $params = []): string
{
    $content = render_template($template, $params);
    return render_template('layout.php', array_merge($params, ['content' => $content]));
}
