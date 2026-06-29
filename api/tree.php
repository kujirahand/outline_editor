<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'GET required'], 405);
}

$user = require_api_user();
$pdo = outline_db($user);

json_response([
    'ok' => true,
    'nodes' => fetch_nodes($pdo),
]);

