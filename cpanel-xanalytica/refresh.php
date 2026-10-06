<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$configPath = getenv('XANALYTICA_CONFIG') ?: (__DIR__ . '/config.php');
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing config file: {$configPath}\n");
    exit(2);
}

$config = require $configPath;
if (!is_array($config)) {
    fwrite(STDERR, "Invalid config file.\n");
    exit(2);
}

foreach (['curl', 'json', 'pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "Missing PHP extension: {$ext}\n");
        exit(2);
    }
}

date_default_timezone_set((string)($config['timezone'] ?? 'Europe/Stockholm'));
set_time_limit((int)($config['max_runtime_seconds'] ?? 900));

$force = in_array('--force', $argv, true);
$targetHours = array_map('intval', $config['target_hours'] ?? [1, 5, 9, 13, 17, 21]);
$now = new DateTimeImmutable('now', new DateTimeZone((string)($config['timezone'] ?? 'Europe/Stockholm')));
$currentHour = (int)$now->format('G');

if (!$force && !in_array($currentHour, $targetHours, true)) {
    echo json_encode([
        'status' => 'skipped',
        'reason' => 'outside_target_hours',
        'stockholm_time' => $now->format('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$lockFile = (string)($config['lock_file'] ?? (sys_get_temp_dir() . '/xanalytica-refresh.lock'));
$lockHandle = fopen($lockFile, 'c+');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'skipped', 'reason' => 'already_running']) . PHP_EOL;
    exit(0);
}

$cookieFile = tempnam(sys_get_temp_dir(), 'xanalytica_cookie_');
if ($cookieFile === false) {
    throw new RuntimeException('Could not create temporary cookie file.');
}

$pdo = null;
$runId = null;
$result = [
    'status' => 'failed',
    'issued' => 0,
    'failed' => 0,
    'failures' => [],
    'platforms' => [
        'x' => ['issued' => 0, 'failed' => 0],
        'facebook' => ['issued' => 0, 'failed' => 0],
        'instagram' => ['issued' => 0, 'failed' => 0],
    ],
];

function dbConnect(array $config): PDO
{
    $db = $config['db'] ?? [];
    $dsn = (string)($db['dsn'] ?? '');
    if ($dsn === '') {
        throw new RuntimeException('Missing database DSN.');
    }

    return new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function deepToken(mixed $value): ?string
{
    if (!is_array($value)) return null;

    foreach (['token', 'access_token', 'accessToken', 'jwt', 'id_token', 'idToken'] as $key) {
        if (isset($value[$key]) && is_string($value[$key]) && strlen($value[$key]) >= 16) {
            return $value[$key];
        }
    }

    foreach ($value as $child) {
        if (is_array($child)) {
            $token = deepToken($child);
            if ($token !== null) return $token;
        }
    }
    return null;
}

function apiRequest(array $config, string $cookieFile, string $method, string $path, ?array $jsonBody = null, ?string $bearer = null): array
{
    $baseUrl = rtrim((string)($config['base_url'] ?? 'https://xanalytica.online'), '/');
    $url = $baseUrl . '/' . ltrim($path, '/');
    $headers = ['Accept: application/json', 'User-Agent: XAnalytica-cPanel-Cron/1.0'];

    $payload = null;
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) throw new RuntimeException('Failed to encode JSON request.');
        $headers[] = 'Content-Type: application/json';
    }

    if ($bearer !== null && $bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_CONNECTTIMEOUT => (int)($config['connect_timeout_seconds'] ?? 20),
        CURLOPT_TIMEOUT => (int)($config['request_timeout_seconds'] ?? 120),
        CURLOPT_ENCODING => '',
    ]);

    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $curlNo = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) $body = '';

    $json = null;
    if ($body !== '') {
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) $json = $decoded;
    }

    return [
        'status' => $status,
        'body' => $body,
        'json' => $json,
        'curl_errno' => $curlNo,
        'curl_error' => $curlError,
    ];
}

function explicitFailure(array $response): ?string
{
    if (($response['curl_errno'] ?? 0) !== 0) {
        return 'cURL: ' . ($response['curl_error'] ?? 'unknown error');
    }

    $status = (int)($response['status'] ?? 0);
    if ($status < 200 || $status >= 300) return 'HTTP ' . $status;

    $json = $response['json'] ?? null;
    if (is_array($json)) {
        if (array_key_exists('success', $json) && $json['success'] === false) return 'API success=false';
        if (array_key_exists('ok', $json) && $json['ok'] === false) return 'API ok=false';
        $apiStatus = strtolower((string)($json['status'] ?? ''));
        if (in_array($apiStatus, ['error', 'failed', 'failure'], true)) return 'API status=' . $apiStatus;
    }

    return null;
}

function extractUsers(mixed $json): array
{
    if (!is_array($json)) return [];
    if (array_is_list($json)) return array_values(array_filter($json, 'is_array'));

    foreach (['users', 'items', 'results'] as $key) {
        if (isset($json[$key]) && is_array($json[$key]) && array_is_list($json[$key])) {
            return array_values(array_filter($json[$key], 'is_array'));
        }
    }

    if (isset($json['data']) && is_array($json['data'])) {
        if (array_is_list($json['data'])) return array_values(array_filter($json['data'], 'is_array'));
        foreach (['users', 'items', 'results'] as $key) {
            if (isset($json['data'][$key]) && is_array($json['data'][$key]) && array_is_list($json['data'][$key])) {
                return array_values(array_filter($json['data'][$key], 'is_array'));
            }
        }
    }

    return [];
}

function getPath(array $data, string $path): mixed
{
    $cursor = $data;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) return null;
        $cursor = $cursor[$segment];
    }
    return $cursor;
}

function firstValue(array $data, array $paths): mixed
{
    foreach ($paths as $path) {
        $value = getPath($data, $path);
        if ($value !== null && $value !== '') return $value;
    }
    return null;
}

function userId(array $user): int|string|null
{
    $value = firstValue($user, ['id', 'userId', 'user_id', 'user.id', 'profile.id']);
    return (is_int($value) || is_string($value)) ? $value : null;
}

function xUserName(array $user): ?string
{
    $value = firstValue($user, [
        'userName', 'username', 'xUsername', 'x_username',
        'twitterUsername', 'twitter_username', 'x.username', 'twitter.username',
        'socials.x.username', 'socials.twitter.username',
    ]);

    if (is_string($value) || is_numeric($value)) {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }
    return null;
}

function insertItem(PDO $pdo, int $runId, string $userRef, string $platform, string $endpoint, int $httpStatus, bool $success, ?string $error): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO xanalytica_refresh_items
         (run_id, user_ref, platform, endpoint, http_status, success, error_text, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
    );
    $stmt->execute([$runId, $userRef, $platform, $endpoint, $httpStatus, $success ? 1 : 0, $error]);
}

function appendLog(array $config, array $payload): void
{
    $logFile = (string)($config['log_file'] ?? (__DIR__ . '/logs/refresh.log'));
    $dir = dirname($logFile);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line !== false) @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

try {
    $pdo = dbConnect($config);

    $stmt = $pdo->prepare(
        'INSERT INTO xanalytica_refresh_runs
         (started_at, trigger_type, status, users_found, issued, failed)
         VALUES (UTC_TIMESTAMP(), ?, "running", 0, 0, 0)'
    );
    $stmt->execute([$force ? 'manual' : 'cron']);
    $runId = (int)$pdo->lastInsertId();

    $email = trim((string)($config['email'] ?? ''));
    $password = (string)($config['password'] ?? '');
    if ($email === '' || $password === '') {
        throw new RuntimeException('Missing XAnalytica email/password in config.php.');
    }

    $login = apiRequest($config, $cookieFile, 'POST', '/api/login', [
        'email' => $email,
        'password' => $password,
    ]);
    if (($error = explicitFailure($login)) !== null) throw new RuntimeException('Login failed: ' . $error);

    $bearer = deepToken($login['json']);

    $me = apiRequest($config, $cookieFile, 'GET', '/api/me', null, $bearer);
    if (($error = explicitFailure($me)) !== null) throw new RuntimeException('Authentication verification failed: ' . $error);

    $usersResponse = apiRequest($config, $cookieFile, 'GET', '/api/users', null, $bearer);
    if (($error = explicitFailure($usersResponse)) !== null) throw new RuntimeException('Could not load users: ' . $error);

    $users = extractUsers($usersResponse['json']);
    $expectedUsers = (int)($config['expected_users'] ?? 11);
    $result['users_found'] = count($users);

    if (count($users) !== $expectedUsers) {
        throw new RuntimeException('Safety stop: expected ' . $expectedUsers . ' users, found ' . count($users) . '.');
    }

    foreach ($users as $index => $user) {
        $id = userId($user);
        $xName = xUserName($user);
        $userRef = $id !== null ? (string)$id : ('row-' . ($index + 1));

        $actions = [];

        if ($id === null || $xName === null) {
            $actions[] = ['platform' => 'x', 'endpoint' => '/api/x-data/user/save', 'skip_error' => 'Could not map user id or X username from /api/users response.'];
        } else {
            $actions[] = ['platform' => 'x', 'endpoint' => '/api/x-data/user/save', 'body' => ['userId' => $id, 'userName' => $xName]];
        }

        if ($id === null) {
            $actions[] = ['platform' => 'facebook', 'endpoint' => '/api/fb-data/posts/:id', 'skip_error' => 'Could not map user id from /api/users response.'];
            $actions[] = ['platform' => 'instagram', 'endpoint' => '/api/instagram-data/posts/:id', 'skip_error' => 'Could not map user id from /api/users response.'];
        } else {
            $actions[] = ['platform' => 'facebook', 'endpoint' => '/api/fb-data/posts/' . rawurlencode((string)$id), 'body' => null];
            $actions[] = ['platform' => 'instagram', 'endpoint' => '/api/instagram-data/posts/' . rawurlencode((string)$id), 'body' => null];
        }

        foreach ($actions as $action) {
            $platform = (string)$action['platform'];
            $endpoint = (string)$action['endpoint'];
            $result['issued']++;
            $result['platforms'][$platform]['issued']++;

            if (isset($action['skip_error'])) {
                $error = (string)$action['skip_error'];
                $result['failed']++;
                $result['platforms'][$platform]['failed']++;
                $result['failures'][] = ['user' => $userRef, 'platform' => $platform, 'error' => $error];
                insertItem($pdo, $runId, $userRef, $platform, $endpoint, 0, false, $error);
                continue;
            }

            $response = apiRequest($config, $cookieFile, 'POST', $endpoint, $action['body'] ?? null, $bearer);
            $error = explicitFailure($response);
            $ok = $error === null;

            if (!$ok) {
                $result['failed']++;
                $result['platforms'][$platform]['failed']++;
                $result['failures'][] = ['user' => $userRef, 'platform' => $platform, 'error' => $error];
            }

            insertItem($pdo, $runId, $userRef, $platform, $endpoint, (int)$response['status'], $ok, $error);

            $pauseUs = (int)($config['pause_between_requests_ms'] ?? 350) * 1000;
            if ($pauseUs > 0) usleep($pauseUs);
        }
    }

    $expectedIssued = $expectedUsers * 3;
    $result['ok'] = $result['issued'] === $expectedIssued && $result['failed'] === 0;
    $result['status'] = $result['ok'] ? 'completed' : 'failed';

    $stmt = $pdo->prepare(
        'UPDATE xanalytica_refresh_runs
         SET completed_at = UTC_TIMESTAMP(), status = ?, users_found = ?, issued = ?, failed = ?, error_text = ?, result_json = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $result['status'],
        $result['users_found'],
        $result['issued'],
        $result['failed'],
        $result['ok'] ? null : 'One or more refresh actions failed.',
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $runId,
    ]);

    appendLog($config, ['at' => $now->format(DateTimeInterface::ATOM), 'run_id' => $runId, 'result' => $result]);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
} catch (Throwable $e) {
    $result['status'] = 'failed';
    $result['error'] = $e->getMessage();

    if ($pdo instanceof PDO && $runId !== null) {
        try {
            $stmt = $pdo->prepare(
                'UPDATE xanalytica_refresh_runs
                 SET completed_at = UTC_TIMESTAMP(), status = "failed", users_found = ?, issued = ?, failed = ?, error_text = ?, result_json = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                (int)($result['users_found'] ?? 0),
                (int)$result['issued'],
                (int)$result['failed'],
                substr($e->getMessage(), 0, 2000),
                json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $runId,
            ]);
        } catch (Throwable) {
        }
    }

    appendLog($config, ['at' => $now->format(DateTimeInterface::ATOM), 'run_id' => $runId, 'result' => $result]);
    fwrite(STDERR, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
} finally {
    if (is_file($cookieFile)) @unlink($cookieFile);
    if (is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}
