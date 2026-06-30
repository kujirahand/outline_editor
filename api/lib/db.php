<?php

declare(strict_types=1);

define('OUTLINE_DATA_DIR', getenv('OUTLINE_DATA_DIR') ?: __DIR__ . '/../../data');

function now_text(): string
{
    return gmdate('Y-m-d H:i:s');
}

function open_sqlite(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function users_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(OUTLINE_DATA_DIR)) {
        mkdir(OUTLINE_DATA_DIR, 0775, true);
    }

    $pdo = open_sqlite(OUTLINE_DATA_DIR . '/users.sqlite');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_dir TEXT NOT NULL UNIQUE,
            login_id TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            display_name TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS outline_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            file_key TEXT NOT NULL,
            name TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (user_id, file_key),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    ensure_default_user($pdo);
    return $pdo;
}

function ensure_default_user(PDO $pdo): void
{
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $now = now_text();
    $stmt = $pdo->prepare(
        'INSERT INTO users (user_dir, login_id, password_hash, display_name, created_at, updated_at)
         VALUES (:user_dir, :login_id, :password_hash, :display_name, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':user_dir' => 'demo',
        ':login_id' => 'admin',
        ':password_hash' => password_hash('outline', PASSWORD_DEFAULT),
        ':display_name' => 'Admin',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $user = [
        'id' => (int)$pdo->lastInsertId(),
        'user_dir' => 'demo',
    ];
    ensure_user_storage($user);
    ensure_default_outline_file($user);
}

function find_user_by_id(int $id): ?array
{
    $stmt = users_db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function find_user_by_login_id(string $loginId): ?array
{
    $stmt = users_db()->prepare('SELECT * FROM users WHERE login_id = :login_id');
    $stmt->execute([':login_id' => $loginId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function validate_user_dir(string $userDir): void
{
    if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $userDir)) {
        throw new RuntimeException('Invalid user directory');
    }
}

function user_base_dir(array $user): string
{
    $userDir = (string)($user['user_dir'] ?? '');
    validate_user_dir($userDir);
    return OUTLINE_DATA_DIR . '/user/' . $userDir;
}

function ensure_user_storage(array $user): void
{
    $baseDir = user_base_dir($user);
    $uploadDir = $baseDir . '/upload';
    $outlinesDir = $baseDir . '/outlines';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    if (!is_dir($outlinesDir)) {
        mkdir($outlinesDir, 0775, true);
    }
}

function user_id(array $user): int
{
    $id = (int)($user['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Invalid user');
    }
    return $id;
}

function validate_file_key(string $fileKey): void
{
    if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $fileKey)) {
        throw new RuntimeException('Invalid file key');
    }
}

function ensure_default_outline_file(array $user): void
{
    $userId = user_id($user);
    $pdo = users_db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM outline_files WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $userId]);

    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $now = now_text();
    $insert = $pdo->prepare(
        'INSERT INTO outline_files (user_id, file_key, name, created_at, updated_at)
         VALUES (:user_id, :file_key, :name, :created_at, :updated_at)'
    );
    $insert->execute([
        ':user_id' => $userId,
        ':file_key' => 'index',
        ':name' => 'メイン',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function fetch_outline_files(array $user): array
{
    ensure_default_outline_file($user);

    $stmt = users_db()->prepare(
        'SELECT id, name, created_at, updated_at
         FROM outline_files
         WHERE user_id = :user_id
         ORDER BY id'
    );
    $stmt->execute([':user_id' => user_id($user)]);

    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }, $stmt->fetchAll());
}

function get_outline_file(array $user, int $id): ?array
{
    ensure_default_outline_file($user);

    $stmt = users_db()->prepare(
        'SELECT id, user_id, file_key, name, created_at, updated_at
         FROM outline_files
         WHERE user_id = :user_id AND id = :id'
    );
    $stmt->execute([
        ':user_id' => user_id($user),
        ':id' => $id,
    ]);
    $file = $stmt->fetch();
    return $file ?: null;
}

function first_outline_file(array $user): array
{
    ensure_default_outline_file($user);

    $stmt = users_db()->prepare(
        'SELECT id, user_id, file_key, name, created_at, updated_at
         FROM outline_files
         WHERE user_id = :user_id
         ORDER BY id
         LIMIT 1'
    );
    $stmt->execute([':user_id' => user_id($user)]);
    $file = $stmt->fetch();
    if (!$file) {
        throw new RuntimeException('Outline file was not initialized');
    }
    return $file;
}

function create_outline_file(array $user, string $name): array
{
    ensure_user_storage($user);
    ensure_default_outline_file($user);

    $name = trim($name);
    if ($name === '') {
        $name = '無題';
    }
    $pdo = users_db();
    $now = now_text();
    do {
        $fileKey = bin2hex(random_bytes(12));
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO outline_files (user_id, file_key, name, created_at, updated_at)
             VALUES (:user_id, :file_key, :name, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':user_id' => user_id($user),
            ':file_key' => $fileKey,
            ':name' => $name,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    } while ($stmt->rowCount() === 0);

    $file = get_outline_file($user, (int)$pdo->lastInsertId());
    if (!$file) {
        throw new RuntimeException('Outline file was not created');
    }

    outline_db($user, $file);
    return $file;
}

function outline_file_path(array $user, array $file): string
{
    $fileKey = (string)($file['file_key'] ?? '');
    validate_file_key($fileKey);

    $baseDir = user_base_dir($user);
    if ($fileKey === 'index') {
        return $baseDir . '/index.sqlite';
    }
    return $baseDir . '/outlines/' . $fileKey . '.sqlite';
}

function outline_db(array $user, ?array $file = null): PDO
{
    static $connections = [];

    ensure_user_storage($user);
    $file = $file ?: first_outline_file($user);
    $path = outline_file_path($user, $file);

    if (isset($connections[$path])) {
        return $connections[$path];
    }

    $pdo = open_sqlite($path);
    ensure_outline_schema($pdo);
    seed_outline_if_empty($pdo);
    $connections[$path] = $pdo;
    return $pdo;
}

function ensure_outline_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nodes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER NULL,
            position INTEGER NOT NULL DEFAULT 0,
            text TEXT NOT NULL DEFAULT "",
            collapsed INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (parent_id) REFERENCES nodes(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nodes_parent_position ON nodes(parent_id, position, id)');
}

function seed_outline_if_empty(PDO $pdo): void
{
    $count = (int)$pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $now = now_text();
    $stmt = $pdo->prepare(
        'INSERT INTO nodes (parent_id, position, text, collapsed, created_at, updated_at)
         VALUES (NULL, :position, :text, 0, :created_at, :updated_at)'
    );

    foreach (['はじめてのアウトライン', 'Enterで兄弟を追加', 'Tabで子ノードにする'] as $index => $text) {
        $stmt->execute([
            ':position' => $index,
            ':text' => $text,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function normalize_positions(PDO $pdo, ?int $parentId): void
{
    $where = $parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent_id';
    $stmt = $pdo->prepare("SELECT id FROM nodes WHERE $where ORDER BY position, id");
    if ($parentId === null) {
        $stmt->execute();
    } else {
        $stmt->execute([':parent_id' => $parentId]);
    }

    $update = $pdo->prepare('UPDATE nodes SET position = :position WHERE id = :id');
    $position = 0;
    foreach ($stmt->fetchAll() as $row) {
        $update->execute([':position' => $position, ':id' => (int)$row['id']]);
        $position++;
    }
}

function node_exists(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM nodes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return (bool)$stmt->fetchColumn();
}

function get_node(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM nodes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $node = $stmt->fetch();
    return $node ?: null;
}

function is_descendant(PDO $pdo, int $nodeId, int $possibleDescendantId): bool
{
    $current = get_node($pdo, $possibleDescendantId);

    while ($current && $current['parent_id'] !== null) {
        $parentId = (int)$current['parent_id'];
        if ($parentId === $nodeId) {
            return true;
        }
        $current = get_node($pdo, $parentId);
    }

    return false;
}

function fetch_nodes(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT id, parent_id, position, text, collapsed, created_at, updated_at
         FROM nodes
         ORDER BY parent_id IS NOT NULL, parent_id, position, id'
    )->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'parent_id' => $row['parent_id'] === null ? null : (int)$row['parent_id'],
            'position' => (int)$row['position'],
            'text' => (string)$row['text'],
            'collapsed' => (int)$row['collapsed'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }, $rows);
}
