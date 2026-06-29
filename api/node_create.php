<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

$user = require_api_user();
$input = require_json_post();
$pdo = outline_db($user);

$parentId = input_int($input, 'parent_id', true);
$position = max(0, input_int($input, 'position'));
$text = array_key_exists('text', $input) && is_string($input['text']) ? $input['text'] : '';

if ($parentId !== null && !node_exists($pdo, $parentId)) {
    json_response(['ok' => false, 'error' => 'Parent node not found'], 404);
}

$pdo->beginTransaction();
try {
    normalize_positions($pdo, $parentId);

    $where = $parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent_id';
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM nodes WHERE $where");
    $parentId === null ? $countStmt->execute() : $countStmt->execute([':parent_id' => $parentId]);
    $count = (int)$countStmt->fetchColumn();
    $position = min($position, $count);

    $shiftWhere = $parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent_id';
    $shift = $pdo->prepare("UPDATE nodes SET position = position + 1 WHERE $shiftWhere AND position >= :position");
    $params = [':position' => $position];
    if ($parentId !== null) {
        $params[':parent_id'] = $parentId;
    }
    $shift->execute($params);

    $now = now_text();
    $stmt = $pdo->prepare(
        'INSERT INTO nodes (parent_id, position, text, collapsed, created_at, updated_at)
         VALUES (:parent_id, :position, :text, 0, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':parent_id' => $parentId,
        ':position' => $position,
        ':text' => substr($text, 0, 20000),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    normalize_positions($pdo, $parentId);
    $id = (int)$pdo->lastInsertId();
    $node = get_node($pdo, $id);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response([
    'ok' => true,
    'node' => $node,
    'nodes' => fetch_nodes($pdo),
]);
