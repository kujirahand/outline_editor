<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/menu_plugin.php';

$user = require_api_user();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $input = $_GET;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = require_json_post();
    $input = array_merge($_GET, $input);
} else {
    json_response(['ok' => false, 'error' => 'GET or POST required'], 405);
}

$name = trim((string)($input['name'] ?? ''));
$type = trim((string)($input['type'] ?? ''));

if ($type !== 'menu') {
    json_response(['ok' => false, 'error' => 'Unsupported plugin type'], 400);
}

if (!is_valid_plugin_name($name)) {
    json_response(['ok' => false, 'error' => 'Invalid plugin name'], 400);
}

if (!is_menu_plugin_enabled($user, $name)) {
    json_response(['ok' => false, 'error' => 'Plugin is disabled'], 403);
}

$params = $input;
unset($params['name'], $params['type']);
$params['user'] = $user;
$params['plugin_name'] = $name;

$result = exec_menu_plugin($name, $params);
$status = $result['ok'] ? 200 : 404;
json_response($result, $status);
