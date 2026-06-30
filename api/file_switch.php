<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/api.php';

$user = require_api_user();
$input = require_json_post();
$fileId = input_int($input, 'id');
$file = select_outline_file($user, $fileId);
if (!$file) {
    json_response(['ok' => false, 'error' => 'File not found'], 404);
}
$pdo = outline_db($user, $file);

json_response([
    'ok' => true,
    'active_file_id' => (int)$file['id'],
    'files' => fetch_outline_files($user),
    'nodes' => fetch_nodes($pdo),
]);
