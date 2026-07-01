<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/menu_plugin.php';

$user = require_api_user();

function settings_plugins_payload(array $user): array
{
    $plugins = load_menu_plugins(['user' => $user]);
    $names = array_map(static fn(array $plugin): string => (string)$plugin['name'], $plugins);
    $enabledMap = menu_plugin_enabled_map($user, $names);

    return array_map(static function (array $plugin) use ($enabledMap): array {
        $name = (string)$plugin['name'];
        return [
            'name' => $name,
            'label' => (string)$plugin['label'],
            'enabled' => $enabledMap[$name] ?? true,
        ];
    }, $plugins);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response([
        'ok' => true,
        'menu_plugins' => settings_plugins_payload($user),
    ]);
}

$input = require_json_post();
$menuPlugins = $input['menu_plugins'] ?? null;
if (!is_array($menuPlugins)) {
    json_response(['ok' => false, 'error' => 'menu_plugins must be object'], 400);
}

$availablePlugins = load_menu_plugins(['user' => $user]);
$availableNames = [];
foreach ($availablePlugins as $plugin) {
    $availableNames[(string)$plugin['name']] = true;
}

foreach ($menuPlugins as $name => $enabled) {
    $name = (string)$name;
    if (!is_valid_plugin_name($name) || !isset($availableNames[$name])) {
        json_response(['ok' => false, 'error' => 'Invalid plugin name'], 400);
    }
    if (!is_bool($enabled)) {
        json_response(['ok' => false, 'error' => 'enabled must be boolean'], 400);
    }
}

foreach ($menuPlugins as $name => $enabled) {
    set_menu_plugin_enabled($user, (string)$name, (bool)$enabled);
}

json_response([
    'ok' => true,
    'menu_plugins' => settings_plugins_payload($user),
]);
