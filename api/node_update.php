<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/api.php';

$user = require_api_user();
$input = require_json_post();
$file = request_outline_file($user, $input);
$pdo = outline_db($user, $file);

$id = input_int($input, 'id');
$node = get_node($pdo, $id);
if (!$node) {
    json_response(['ok' => false, 'error' => 'Node not found'], 404);
}

$fields = [];
$params = [':id' => $id, ':updated_at' => now_text()];

if (array_key_exists('text', $input)) {
    $fields[] = 'text = :text';
    $params[':text'] = input_text($input, 'text');
}

if (array_key_exists('collapsed', $input)) {
    $collapsed = (int)(bool)$input['collapsed'];
    $fields[] = 'collapsed = :collapsed';
    $params[':collapsed'] = $collapsed;
}

if (!$fields) {
    json_response(['ok' => false, 'error' => 'No changes'], 400);
}

$fields[] = 'updated_at = :updated_at';
$stmt = $pdo->prepare('UPDATE nodes SET ' . implode(', ', $fields) . ' WHERE id = :id');
$stmt->execute($params);

json_response([
    'ok' => true,
    'node' => get_node($pdo, $id),
]);
