<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/api.php';

$user = require_api_user();
$input = require_json_post();
$pdo = outline_db($user);

$id = input_int($input, 'id');
$node = get_node($pdo, $id);
if (!$node) {
    json_response(['ok' => false, 'error' => 'Node not found'], 404);
}

$oldParentId = $node['parent_id'] === null ? null : (int)$node['parent_id'];

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('DELETE FROM nodes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    normalize_positions($pdo, $oldParentId);

    $remaining = (int)$pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn();
    if ($remaining === 0) {
        seed_outline_if_empty($pdo);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response([
    'ok' => true,
    'nodes' => fetch_nodes($pdo),
]);
