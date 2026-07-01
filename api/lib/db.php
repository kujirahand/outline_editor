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
    ensure_users_schema($pdo);
    ensure_default_user($pdo);
    sync_registration_codes_from_env($pdo);
    return $pdo;
}

function ensure_users_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_dir TEXT NOT NULL UNIQUE,
            login_id TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL DEFAULT "",
            password_hash TEXT NOT NULL,
            display_name TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    ensure_column($pdo, 'users', 'email', 'TEXT NOT NULL DEFAULT ""');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_unique ON users(email) WHERE email <> ""');
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
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS menu_plugin_settings (
            user_id INTEGER NOT NULL,
            plugin_name TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (user_id, plugin_name),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS registration_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code_hash TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL DEFAULT "",
            max_uses INTEGER NOT NULL DEFAULT 1,
            used_count INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS email_verifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            code_hash TEXT NOT NULL,
            registration_code_id INTEGER NOT NULL,
            expires_at TEXT NOT NULL,
            consumed_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (registration_code_id) REFERENCES registration_codes(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_verifications_email ON email_verifications(email, expires_at)');
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) {
            return;
        }
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function registration_code_hash(string $code): string
{
    return hash('sha256', trim($code));
}

function sync_registration_codes_from_env(PDO $pdo): void
{
    $codesText = trim((string)getenv('OUTLINE_REGISTRATION_CODES'));
    if ($codesText === '') {
        return;
    }

    $maxUses = (int)(getenv('OUTLINE_REGISTRATION_CODE_MAX_USES') ?: 1);
    if ($maxUses < 1) {
        $maxUses = 1;
    }

    $insert = $pdo->prepare(
        'INSERT OR IGNORE INTO registration_codes
            (code_hash, label, max_uses, used_count, active, created_at, updated_at)
         VALUES
            (:code_hash, :label, :max_uses, 0, 1, :created_at, :updated_at)'
    );
    $now = now_text();
    foreach (explode(',', $codesText) as $code) {
        $code = trim($code);
        if ($code === '') {
            continue;
        }
        $insert->execute([
            ':code_hash' => registration_code_hash($code),
            ':label' => 'env',
            ':max_uses' => $maxUses,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
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

function find_user_by_email(string $email): ?array
{
    $stmt = users_db()->prepare('SELECT * FROM users WHERE email = :email AND email <> ""');
    $stmt->execute([':email' => normalize_email($email)]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function find_available_registration_code(string $code): ?array
{
    $stmt = users_db()->prepare(
        'SELECT *
         FROM registration_codes
         WHERE code_hash = :code_hash
            AND active = 1
            AND used_count < max_uses'
    );
    $stmt->execute([':code_hash' => registration_code_hash($code)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_email_verification(int $registrationCodeId, string $email, string $code): void
{
    $pdo = users_db();
    $email = normalize_email($email);
    $now = now_text();
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);

    $pdo->prepare(
        'UPDATE email_verifications
         SET consumed_at = :consumed_at, updated_at = :updated_at
         WHERE email = :email AND consumed_at IS NULL'
    )->execute([
        ':consumed_at' => $now,
        ':updated_at' => $now,
        ':email' => $email,
    ]);

    $stmt = $pdo->prepare(
        'INSERT INTO email_verifications
            (email, code_hash, registration_code_id, expires_at, consumed_at, created_at, updated_at)
         VALUES
            (:email, :code_hash, :registration_code_id, :expires_at, NULL, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':email' => $email,
        ':code_hash' => password_hash($code, PASSWORD_DEFAULT),
        ':registration_code_id' => $registrationCodeId,
        ':expires_at' => $expiresAt,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function find_valid_email_verification(string $email, string $code): ?array
{
    $stmt = users_db()->prepare(
        'SELECT *
         FROM email_verifications
         WHERE email = :email
            AND consumed_at IS NULL
            AND expires_at >= :now
         ORDER BY id DESC'
    );
    $stmt->execute([
        ':email' => normalize_email($email),
        ':now' => now_text(),
    ]);

    foreach ($stmt->fetchAll() as $row) {
        if (password_verify($code, (string)$row['code_hash'])) {
            return $row;
        }
    }
    return null;
}

function create_registered_user(array $verification, string $loginId, string $password, string $displayName): array
{
    $pdo = users_db();
    $pdo->beginTransaction();
    try {
        $registrationCodeId = (int)$verification['registration_code_id'];
        $stmt = $pdo->prepare(
            'SELECT *
             FROM registration_codes
             WHERE id = :id
                AND active = 1
                AND used_count < max_uses'
        );
        $stmt->execute([':id' => $registrationCodeId]);
        $registrationCode = $stmt->fetch();
        if (!$registrationCode) {
            throw new RuntimeException('登録コードは使用できません。');
        }

        $email = normalize_email((string)$verification['email']);
        if (find_user_by_login_id($loginId) || find_user_by_email($email)) {
            throw new RuntimeException('このログインIDまたはメールアドレスは既に使われています。');
        }

        $now = now_text();
        $userDir = new_user_dir($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO users (user_dir, login_id, email, password_hash, display_name, created_at, updated_at)
             VALUES (:user_dir, :login_id, :email, :password_hash, :display_name, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':user_dir' => $userDir,
            ':login_id' => $loginId,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':display_name' => $displayName,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $userId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'UPDATE email_verifications
             SET consumed_at = :consumed_at, updated_at = :updated_at
             WHERE id = :id'
        )->execute([
            ':consumed_at' => $now,
            ':updated_at' => $now,
            ':id' => (int)$verification['id'],
        ]);
        $pdo->prepare(
            'UPDATE registration_codes
             SET used_count = used_count + 1, updated_at = :updated_at
             WHERE id = :id'
        )->execute([
            ':updated_at' => $now,
            ':id' => $registrationCodeId,
        ]);

        $pdo->commit();
        $user = find_user_by_id($userId);
        if (!$user) {
            throw new RuntimeException('ユーザー作成に失敗しました。');
        }
        ensure_user_storage($user);
        ensure_default_outline_file($user);
        return $user;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function new_user_dir(PDO $pdo): string
{
    do {
        $userDir = 'u_' . bin2hex(random_bytes(8));
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE user_dir = :user_dir');
        $stmt->execute([':user_dir' => $userDir]);
    } while ($stmt->fetchColumn());
    return $userDir;
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

/**
 * @param array<int, string> $pluginNames
 * @return array<string, bool>
 */
function menu_plugin_enabled_map(array $user, array $pluginNames): array
{
    $enabled = [];
    foreach ($pluginNames as $pluginName) {
        $enabled[$pluginName] = true;
    }

    if ($pluginNames === []) {
        return $enabled;
    }

    $placeholders = implode(',', array_fill(0, count($pluginNames), '?'));
    $stmt = users_db()->prepare(
        "SELECT plugin_name, enabled
         FROM menu_plugin_settings
         WHERE user_id = ? AND plugin_name IN ($placeholders)"
    );
    $stmt->execute(array_merge([user_id($user)], $pluginNames));

    foreach ($stmt->fetchAll() as $row) {
        $enabled[(string)$row['plugin_name']] = ((int)$row['enabled']) === 1;
    }

    return $enabled;
}

function is_menu_plugin_enabled(array $user, string $pluginName): bool
{
    $map = menu_plugin_enabled_map($user, [$pluginName]);
    return $map[$pluginName] ?? true;
}

function set_menu_plugin_enabled(array $user, string $pluginName, bool $enabled): void
{
    $stmt = users_db()->prepare(
        'INSERT INTO menu_plugin_settings (user_id, plugin_name, enabled, updated_at)
         VALUES (:user_id, :plugin_name, :enabled, :updated_at)
         ON CONFLICT(user_id, plugin_name) DO UPDATE SET
            enabled = excluded.enabled,
            updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':user_id' => user_id($user),
        ':plugin_name' => $pluginName,
        ':enabled' => $enabled ? 1 : 0,
        ':updated_at' => now_text(),
    ]);
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
