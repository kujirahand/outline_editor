<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/api.php';

function sibling_ids(PDO $pdo, ?int $parentId, int $excludeId): array
{
    $where = $parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent_id';
    $stmt = $pdo->prepare("SELECT id FROM nodes WHERE $where AND id <> :exclude_id ORDER BY position, id");
    $params = [':exclude_id' => $excludeId];
    if ($parentId !== null) {
        $params[':parent_id'] = $parentId;
    }
    $stmt->execute($params);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

function write_sibling_order(PDO $pdo, ?int $parentId, array $ids): void
{
    $stmt = $pdo->prepare('UPDATE nodes SET parent_id = :parent_id, position = :position, updated_at = :updated_at WHERE id = :id');
    $now = now_text();
    foreach (array_values($ids) as $position => $id) {
        $stmt->execute([
            ':parent_id' => $parentId,
            ':position' => $position,
            ':updated_at' => $now,
            ':id' => $id,
        ]);
    }
}

$user = require_api_user();
$input = require_json_post();
$pdo = outline_db($user);

$id = input_int($input, 'id');
$newParentId = input_int($input, 'parent_id', true);
$newPosition = max(0, input_int($input, 'position'));

$node = get_node($pdo, $id);
if (!$node) {
    json_response(['ok' => false, 'error' => 'Node not found'], 404);
}

if ($newParentId !== null) {
    if ($newParentId === $id) {
        json_response(['ok' => false, 'error' => 'Cannot move node under itself'], 400);
    }
    if (!node_exists($pdo, $newParentId)) {
        json_response(['ok' => false, 'error' => 'Parent node not found'], 404);
    }
    if (is_descendant($pdo, $id, $newParentId)) {
        json_response(['ok' => false, 'error' => 'Cannot move node under descendant'], 400);
    }
}

$oldParentId = $node['parent_id'] === null ? null : (int)$node['parent_id'];

$pdo->beginTransaction();
try {
    $oldSiblings = sibling_ids($pdo, $oldParentId, $id);
    write_sibling_order($pdo, $oldParentId, $oldSiblings);

    $newSiblings = sibling_ids($pdo, $newParentId, $id);
    $newPosition = min($newPosition, count($newSiblings));
    array_splice($newSiblings, $newPosition, 0, [$id]);
    write_sibling_order($pdo, $newParentId, $newSiblings);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response([
    'ok' => true,
    'node' => get_node($pdo, $id),
    'nodes' => fetch_nodes($pdo),
]);
