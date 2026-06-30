<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'GET required'], 405);
}

$user = require_api_user();
$file = current_outline_file($user);

json_response([
    'ok' => true,
    'active_file_id' => (int)$file['id'],
    'files' => fetch_outline_files($user),
]);
