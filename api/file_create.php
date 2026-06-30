<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/api.php';

$user = require_api_user();
$input = require_json_post();
$name = input_text($input, 'name', 120);
$file = create_outline_file($user, $name);
select_outline_file($user, (int)$file['id']);
$pdo = outline_db($user, $file);

json_response([
    'ok' => true,
    'active_file_id' => (int)$file['id'],
    'file' => [
        'id' => (int)$file['id'],
        'name' => (string)$file['name'],
        'created_at' => (string)$file['created_at'],
        'updated_at' => (string)$file['updated_at'],
    ],
    'files' => fetch_outline_files($user),
    'nodes' => fetch_nodes($pdo),
]);
