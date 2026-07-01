<?php

declare(strict_types=1);

/**
 * @return array<int, array{name:string,label:string,html:string}>
 */
function load_menu_plugins(array $args = [], ?string $pluginDir = null): array
{
    $dir = $pluginDir ?? dirname(__DIR__, 2) . '/plugins/menu';
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.inc.php');
    if ($files === false) {
        return [];
    }

    sort($files, SORT_STRING);
    $plugins = [];

    foreach ($files as $file) {
        $name = basename($file, '.inc.php');
        if (!preg_match('/\A[A-Za-z0-9_]+\z/', $name)) {
            error_log("Invalid menu plugin name: $name");
            continue;
        }

        require_once $file;

        $function = 'get_menu_' . $name;
        if (!function_exists($function)) {
            error_log("Menu plugin function not found: $function");
            continue;
        }

        $result = $function(array_merge($args, ['plugin_name' => $name]));
        if (!is_array($result)) {
            error_log("Menu plugin must return an array: $name");
            continue;
        }

        $label = trim((string)($result['label'] ?? ''));
        $html = (string)($result['html'] ?? '');
        if ($label === '') {
            error_log("Menu plugin label is empty: $name");
            continue;
        }

        $plugins[] = [
            'name' => $name,
            'label' => $label,
            'html' => $html,
        ];
    }

    return $plugins;
}

function menu_plugin_dir(?string $pluginDir = null): string
{
    return $pluginDir ?? dirname(__DIR__, 2) . '/plugins/menu';
}

function is_valid_plugin_name(string $name): bool
{
    return preg_match('/\A[A-Za-z0-9_]+\z/', $name) === 1;
}

function require_menu_plugin_file(string $name, ?string $pluginDir = null): bool
{
    if (!is_valid_plugin_name($name)) {
        return false;
    }

    $path = menu_plugin_dir($pluginDir) . DIRECTORY_SEPARATOR . $name . '.inc.php';
    if (!is_file($path)) {
        return false;
    }

    require_once $path;
    return true;
}

/**
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function exec_menu_plugin(string $name, array $params, ?string $pluginDir = null): array
{
    if (!require_menu_plugin_file($name, $pluginDir)) {
        return ['ok' => false, 'error' => 'Plugin not found'];
    }

    $function = 'exec_' . $name;
    if (!function_exists($function)) {
        return ['ok' => false, 'error' => 'Plugin action not found'];
    }

    $result = $function($params);
    if (is_array($result)) {
        return ['ok' => true, 'result' => $result];
    }

    return ['ok' => true, 'result' => ['value' => $result]];
}
