<?php

declare(strict_types=1);

final class TestFailure extends RuntimeException
{
}

final class HttpClient
{
    private string $baseUrl;
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, headers:array<int, string>, body:string, json:?array}
     */
    public function request(string $method, string $path, string $body = '', array $headers = []): array
    {
        $requestHeaders = $headers;
        if ($this->cookies) {
            $cookieParts = [];
            foreach ($this->cookies as $name => $value) {
                $cookieParts[] = $name . '=' . $value;
            }
            $requestHeaders[] = 'Cookie: ' . implode('; ', $cookieParts);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $requestHeaders),
                'content' => $body,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);

        $responseBody = @file_get_contents($this->baseUrl . $path, false, $context);
        if ($responseBody === false) {
            throw new TestFailure("HTTP request failed: $method $path");
        }

        $responseHeaders = $http_response_header ?? [];
        $status = $this->statusFromHeaders($responseHeaders);
        $this->storeCookies($responseHeaders);

        $json = null;
        if (str_contains(strtolower(implode("\n", $responseHeaders)), 'content-type: application/json')) {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $responseBody,
            'json' => $json,
        ];
    }

    /**
     * @param array<int, string> $headers
     */
    private function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/\AHTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
                return (int)$matches[1];
            }
        }
        return 0;
    }

    /**
     * @param array<int, string> $headers
     */
    private function storeCookies(array $headers): void
    {
        foreach ($headers as $header) {
            if (!preg_match('/\ASet-Cookie:\s*([^=;\s]+)=([^;]*)/i', $header, $matches)) {
                continue;
            }
            $this->cookies[$matches[1]] = $matches[2];
        }
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        $expectedText = var_export($expected, true);
        $actualText = var_export($actual, true);
        throw new TestFailure("$message\nExpected: $expectedText\nActual:   $actualText");
    }
}

/**
 * @return array<string, mixed>
 */
function expect_json(array $response, int $status): array
{
    assert_same($status, $response['status'], 'Unexpected HTTP status.');
    assert_true(is_array($response['json']), 'Response body is not JSON.');
    return $response['json'];
}

function extract_csrf(string $html): string
{
    if (preg_match('/name="csrf-token"\s+content="([^"]+)"/', $html, $matches)) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $matches)) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }
    throw new TestFailure('CSRF token was not found.');
}

/**
 * @param array<string, mixed> $payload
 * @return array{status:int, headers:array<int, string>, body:string, json:?array}
 */
function api_post(HttpClient $client, string $path, array $payload, string $csrfToken): array
{
    return $client->request(
        'POST',
        $path,
        json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        [
            'Content-Type: application/json',
            'X-CSRF-Token: ' . $csrfToken,
        ],
    );
}

/**
 * @param array<int, array<string, mixed>> $nodes
 */
function find_node(array $nodes, int $id): array
{
    foreach ($nodes as $node) {
        if (($node['id'] ?? null) === $id) {
            return $node;
        }
    }
    throw new TestFailure("Node was not found: $id");
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) {
            remove_tree($child);
            continue;
        }
        unlink($child);
    }
    rmdir($path);
}

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new TestFailure("Cannot reserve test port: $errstr");
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    if (!is_string($name) || !preg_match('/:(\d+)\z/', $name, $matches)) {
        throw new TestFailure('Cannot determine test port.');
    }
    return (int)$matches[1];
}

/**
 * @return resource
 */
function start_server(string $dataDir, int $port)
{
    $root = dirname(__DIR__, 2);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['file', 'php://temp', 'w'],
        2 => ['file', 'php://temp', 'w'],
    ];

    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, 'router.php'],
        $descriptorSpec,
        $pipes,
        $root,
        ['OUTLINE_DATA_DIR' => $dataDir],
    );
    if (!is_resource($process)) {
        throw new TestFailure('Cannot start PHP test server.');
    }

    $client = new HttpClient('http://127.0.0.1:' . $port);
    $deadline = microtime(true) + 5.0;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            proc_close($process);
            throw new TestFailure('PHP test server stopped unexpectedly.');
        }
        try {
            $response = $client->request('GET', '/');
            if ($response['status'] > 0) {
                return $process;
            }
        } catch (Throwable) {
            usleep(50000);
        }
    } while (microtime(true) < $deadline);

    proc_terminate($process);
    proc_close($process);
    throw new TestFailure('PHP test server did not start.');
}

function login(HttpClient $client): string
{
    $loginPage = $client->request('GET', '/');
    assert_same(200, $loginPage['status'], 'Login page should load.');
    $loginCsrf = extract_csrf($loginPage['body']);

    $form = http_build_query([
        'csrf_token' => $loginCsrf,
        'action' => 'login',
        'login_id' => 'admin',
        'password' => 'outline',
    ]);
    $login = $client->request('POST', '/', $form, ['Content-Type: application/x-www-form-urlencoded']);
    assert_same(302, $login['status'], 'Login should redirect after success.');

    $outline = $client->request('GET', '/');
    assert_same(200, $outline['status'], 'Outline page should load after login.');
    assert_true(str_contains($outline['body'], 'Outline Editor'), 'Logged-in outline page was not rendered.');
    return extract_csrf($outline['body']);
}

function run_api_tests(): void
{
    $dataDir = sys_get_temp_dir() . '/outline-api-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $port = free_port();
    $process = start_server($dataDir, $port);
    $client = new HttpClient('http://127.0.0.1:' . $port);

    try {
        $unauthorized = expect_json($client->request('GET', '/api/tree.php'), 401);
        assert_same(false, $unauthorized['ok'], 'Tree API should reject anonymous users.');
        assert_same('Login required', $unauthorized['error'], 'Anonymous tree API error mismatch.');

        $csrfToken = login($client);

        $tree = expect_json($client->request('GET', '/api/tree.php'), 200);
        assert_same(true, $tree['ok'], 'Tree API should succeed after login.');
        assert_true(count($tree['nodes']) >= 3, 'Default outline seed should exist.');

        $missingCsrf = expect_json($client->request(
            'POST',
            '/api/node_create.php',
            '{"parent_id":null,"position":0,"text":"No CSRF"}',
            ['Content-Type: application/json'],
        ), 403);
        assert_same('Invalid CSRF token', $missingCsrf['error'], 'Missing CSRF error mismatch.');

        $createdParent = expect_json(api_post($client, '/api/node_create.php', [
            'parent_id' => null,
            'position' => 0,
            'text' => 'Parent',
        ], $csrfToken), 200);
        assert_same(true, $createdParent['ok'], 'Parent create should succeed.');
        $parentId = (int)$createdParent['node']['id'];
        assert_same('Parent', $createdParent['node']['text'], 'Created parent text mismatch.');
        assert_same(0, $createdParent['node']['position'], 'Created parent should be inserted at position 0.');

        $createdChild = expect_json(api_post($client, '/api/node_create.php', [
            'parent_id' => null,
            'position' => 1,
            'text' => 'Child',
        ], $csrfToken), 200);
        $childId = (int)$createdChild['node']['id'];

        $updated = expect_json(api_post($client, '/api/node_update.php', [
            'id' => $childId,
            'text' => 'Updated child',
            'collapsed' => true,
        ], $csrfToken), 200);
        assert_same('Updated child', $updated['node']['text'], 'Updated text mismatch.');
        assert_same(1, $updated['node']['collapsed'], 'Collapsed flag mismatch.');

        $moved = expect_json(api_post($client, '/api/node_move.php', [
            'id' => $childId,
            'parent_id' => $parentId,
            'position' => 0,
        ], $csrfToken), 200);
        $movedChild = find_node($moved['nodes'], $childId);
        assert_same($parentId, $movedChild['parent_id'], 'Moved child parent mismatch.');
        assert_same(0, $movedChild['position'], 'Moved child position mismatch.');

        $cycle = expect_json(api_post($client, '/api/node_move.php', [
            'id' => $parentId,
            'parent_id' => $childId,
            'position' => 0,
        ], $csrfToken), 400);
        assert_same('Cannot move node under descendant', $cycle['error'], 'Cycle prevention error mismatch.');

        $deleted = expect_json(api_post($client, '/api/node_delete.php', [
            'id' => $childId,
        ], $csrfToken), 200);
        assert_same(true, $deleted['ok'], 'Delete should succeed.');
        foreach ($deleted['nodes'] as $node) {
            assert_true(($node['id'] ?? null) !== $childId, 'Deleted node should not remain in tree.');
        }
    } finally {
        proc_terminate($process);
        proc_close($process);
        remove_tree($dataDir);
    }
}

try {
    run_api_tests();
    echo "api tests passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, "api tests failed: " . $e->getMessage() . "\n");
    exit(1);
}
