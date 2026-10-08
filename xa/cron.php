<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$configPath = getenv('XA_CONFIG') ?: (__DIR__ . '/config.php');
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

$tz = new DateTimeZone((string)($config['timezone'] ?? 'Europe/Stockholm'));
date_default_timezone_set($tz->getName());
set_time_limit((int)($config['max_runtime_seconds'] ?? 900));
$force = in_array('--force', $argv, true);
$now = new DateTimeImmutable('now', $tz);

function dbConnect(array $config): PDO {
    $db = $config['db'] ?? [];
    $dsn = (string)($db['dsn'] ?? '');
    if ($dsn === '') throw new RuntimeException('Missing database DSN.');
    return new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function slotKey(DateTimeImmutable $slot): string {
    return $slot->format('Y-m-d\\TH:00P');
}

function latestDueSlot(DateTimeImmutable $now, array $hours): DateTimeImmutable {
    $candidates = [];
    for ($d = 0; $d >= -1; $d--) {
        $day = $now->modify("{$d} day")->format('Y-m-d');
        foreach ($hours as $hour) {
            $slot = new DateTimeImmutable(sprintf('%s %02d:00:00', $day, $hour), $now->getTimezone());
            if ($slot <= $now) $candidates[] = $slot;
        }
    }
    if (!$candidates) throw new RuntimeException('Could not determine a due slot.');
    usort($candidates, fn($a, $b) => $a <=> $b);
    return end($candidates);
}

function nextSlotAfter(DateTimeImmutable $last, DateTimeImmutable $now, array $hours): ?DateTimeImmutable {
    $cursor = $last->modify('-1 day')->setTime(0, 0);
    $end = $now->setTime(23, 59, 59);
    $candidates = [];
    while ($cursor <= $end) {
        $day = $cursor->format('Y-m-d');
        foreach ($hours as $hour) {
            $slot = new DateTimeImmutable(sprintf('%s %02d:00:00', $day, $hour), $now->getTimezone());
            if ($slot > $last && $slot <= $now) $candidates[] = $slot;
        }
        $cursor = $cursor->modify('+1 day');
    }
    if (!$candidates) return null;
    usort($candidates, fn($a, $b) => $a <=> $b);
    return $candidates[0];
}

function chooseSlot(PDO $pdo, DateTimeImmutable $now, array $config, bool $force): ?DateTimeImmutable {
    $hours = array_values(array_unique(array_map('intval', $config['target_hours'] ?? [1,5,9,13,17,21])));
    sort($hours);
    if ($force) return latestDueSlot($now, $hours);

    $row = $pdo->query("SELECT scheduled_for_utc FROM xa_refresh_slots WHERE status='success' ORDER BY scheduled_for_utc DESC LIMIT 1")->fetch();
    if (!$row) return latestDueSlot($now, $hours);

    $utc = new DateTimeZone('UTC');
    $last = (new DateTimeImmutable((string)$row['scheduled_for_utc'], $utc))->setTimezone($now->getTimezone());
    return nextSlotAfter($last, $now, $hours);
}

function ensureSlot(PDO $pdo, DateTimeImmutable $slot): int {
    $utc = $slot->setTimezone(new DateTimeZone('UTC'));
    $stmt = $pdo->prepare(
        "INSERT INTO xa_refresh_slots (slot_key, scheduled_for_utc, scheduled_for_local, status)
         VALUES (?, ?, ?, 'pending')
         ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
    );
    $stmt->execute([slotKey($slot), $utc->format('Y-m-d H:i:s'), $slot->format(DateTimeInterface::ATOM)]);
    return (int)$pdo->lastInsertId();
}

function deepToken(mixed $value): ?string {
    if (!is_array($value)) return null;
    foreach (['token','access_token','accessToken','jwt','id_token','idToken'] as $key) {
        if (isset($value[$key]) && is_string($value[$key]) && strlen($value[$key]) >= 16) return $value[$key];
    }
    foreach ($value as $child) {
        if (is_array($child) && ($token = deepToken($child))) return $token;
    }
    return null;
}

function rawRequest(array $config, string $cookieFile, string $method, string $path, ?array $jsonBody, ?string $bearer): array {
    $url = rtrim((string)($config['base_url'] ?? 'https://xanalytica.online'), '/') . '/' . ltrim($path, '/');
    $headers = ['Accept: application/json', 'User-Agent: XA-cPanel-Cron/2.0'];
    $payload = null;
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) throw new RuntimeException('Failed to encode JSON request.');
        $headers[] = 'Content-Type: application/json';
    }
    if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
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
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) $body = '';
    $json = null;
    if ($body !== '') {
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) $json = $decoded;
    }
    return compact('status','body','json','errno','error');
}

function apiFailure(array $r): ?string {
    if (($r['errno'] ?? 0) !== 0) return 'cURL: ' . ($r['error'] ?? 'unknown');
    $status = (int)($r['status'] ?? 0);
    if ($status < 200 || $status >= 300) return 'HTTP ' . $status;
    $json = $r['json'] ?? null;
    if (is_array($json)) {
        if (($json['success'] ?? null) === false) return 'API success=false';
        if (($json['ok'] ?? null) === false) return 'API ok=false';
        $s = strtolower((string)($json['status'] ?? ''));
        if (in_array($s, ['error','failed','failure'], true)) return 'API status=' . $s;
    }
    return null;
}

function requestWithRetry(array $config, string $cookieFile, string $method, string $path, ?array $body, ?string $bearer): array {
    $max = max(1, (int)($config['request_retries'] ?? 3));
    $delays = $config['retry_delays_seconds'] ?? [2,5,12];
    $last = [];
    for ($attempt = 1; $attempt <= $max; $attempt++) {
        $last = rawRequest($config, $cookieFile, $method, $path, $body, $bearer);
        $last['attempts'] = $attempt;
        $status = (int)($last['status'] ?? 0);
        $transient = (($last['errno'] ?? 0) !== 0) || in_array($status, [408,425,429], true) || $status >= 500;
        if (!$transient || $attempt === $max) return $last;
        $delay = (int)($delays[min($attempt - 1, count($delays) - 1)] ?? 5);
        if ($delay > 0) sleep($delay);
    }
    return $last;
}

function extractUsers(mixed $json): array {
    if (!is_array($json)) return [];
    if (array_is_list($json)) return array_values(array_filter($json, 'is_array'));
    foreach (['users','items','results'] as $key) {
        if (isset($json[$key]) && is_array($json[$key]) && array_is_list($json[$key])) return array_values(array_filter($json[$key], 'is_array'));
    }
    if (isset($json['data']) && is_array($json['data'])) {
        if (array_is_list($json['data'])) return array_values(array_filter($json['data'], 'is_array'));
        foreach (['users','items','results'] as $key) {
            if (isset($json['data'][$key]) && is_array($json['data'][$key]) && array_is_list($json['data'][$key])) return array_values(array_filter($json['data'][$key], 'is_array'));
        }
    }
    return [];
}

function getPath(array $data, string $path): mixed {
    $v = $data;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($v) || !array_key_exists($segment, $v)) return null;
        $v = $v[$segment];
    }
    return $v;
}

function firstValue(array $data, array $paths): mixed {
    foreach ($paths as $path) {
        $v = getPath($data, $path);
        if ($v !== null && $v !== '') return $v;
    }
    return null;
}

function userId(array $user): int|string|null {
    $v = firstValue($user, ['id','userId','user_id','user.id','profile.id']);
    return (is_int($v) || is_string($v)) ? $v : null;
}

function recursiveXName(array $node, string $parent = ''): ?string {
    foreach ($node as $key => $value) {
        $k = strtolower((string)$key);
        $flat = preg_replace('/[^a-z0-9]/', '', $k) ?? $k;
        $context = strtolower($parent . '.' . $k);
        if ((is_string($value) || is_numeric($value)) && trim((string)$value) !== '') {
            if (in_array($flat, ['xusername','xhandle','twitterusername','twitterhandle'], true)) return ltrim(trim((string)$value), '@');
            if ((str_contains($context, 'twitter') || preg_match('/(^|\\.)x(\\.|$)/', $context)) && in_array($flat, ['username','handle','name','userName'], true)) {
                return ltrim(trim((string)$value), '@');
            }
        }
        if (is_array($value) && ($found = recursiveXName($value, $context))) return $found;
    }
    return null;
}

function xUserName(array $user, int|string|null $id, array $config): ?string {
    $v = firstValue($user, [
        'xUsername','x_username','xUserName','twitterUsername','twitter_username','twitterUserName',
        'x.username','x.handle','twitter.username','twitter.handle','socials.x.username','socials.twitter.username'
    ]);
    if (is_string($v) || is_numeric($v)) {
        $s = ltrim(trim((string)$v), '@');
        if ($s !== '') return $s;
    }
    if ($found = recursiveXName($user)) return $found;
    $map = $config['x_username_map'] ?? [];
    if ($id !== null && isset($map[(string)$id])) {
        $s = ltrim(trim((string)$map[(string)$id]), '@');
        if ($s !== '') return $s;
    }
    return null;
}

function addItem(PDO $pdo, int $runId, string $userRef, string $platform, string $endpoint, array $r, bool $ok, ?string $error): void {
    $stmt = $pdo->prepare("INSERT INTO xa_refresh_items (run_id,user_ref,platform,endpoint,http_status,success,attempts,error_text,created_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
    $stmt->execute([$runId,$userRef,$platform,$endpoint,(int)($r['status'] ?? 0),$ok?1:0,(int)($r['attempts'] ?? 1),$error]);
}

function writeLog(array $config, array $payload): void {
    $path = (string)($config['log_file'] ?? (__DIR__ . '/logs/refresh.log'));
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0750, true);
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line !== false) @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$lockPath = (string)($config['lock_file'] ?? (__DIR__ . '/xa-refresh.lock'));
$lock = fopen($lockPath, 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status'=>'skipped','reason'=>'already_running']) . PHP_EOL;
    exit(0);
}

$pdo = null;
$cookieFile = null;
$runId = null;
$slotId = null;
$slot = null;
$result = ['status'=>'failed','issued'=>0,'failed'=>0,'failures'=>[],'platforms'=>[
    'x'=>['issued'=>0,'failed'=>0],
    'facebook'=>['issued'=>0,'failed'=>0],
    'instagram'=>['issued'=>0,'failed'=>0],
]];

try {
    $pdo = dbConnect($config);
    $gotDbLock = (int)$pdo->query("SELECT GET_LOCK('xa_refresh_v2', 0)")->fetchColumn();
    if ($gotDbLock !== 1) {
        echo json_encode(['status'=>'skipped','reason'=>'db_lock_busy']) . PHP_EOL;
        exit(0);
    }

    $slot = chooseSlot($pdo, $now, $config, $force);
    if ($slot === null) {
        echo json_encode(['status'=>'skipped','reason'=>'all_slots_completed','stockholm_time'=>$now->format(DateTimeInterface::ATOM)]) . PHP_EOL;
        exit(0);
    }
    $slotId = ensureSlot($pdo, $slot);

    $stmt = $pdo->prepare("UPDATE xa_refresh_slots SET status='running', attempts=attempts+1, last_attempt_at=UTC_TIMESTAMP(), error_text=NULL WHERE id=?");
    $stmt->execute([$slotId]);

    $stmt = $pdo->prepare("INSERT INTO xa_refresh_runs (slot_id,trigger_type,started_at,status) VALUES (?,?,UTC_TIMESTAMP(),'running')");
    $stmt->execute([$slotId, $force ? 'manual' : 'cron']);
    $runId = (int)$pdo->lastInsertId();

    $cookieFile = tempnam(sys_get_temp_dir(), 'xa_cookie_');
    if ($cookieFile === false) throw new RuntimeException('Could not create cookie file.');

    $email = trim((string)($config['email'] ?? ''));
    $password = (string)($config['password'] ?? '');
    if ($email === '' || $password === '') throw new RuntimeException('Missing XAnalytica credentials.');

    $login = requestWithRetry($config,$cookieFile,'POST','/api/login',['email'=>$email,'password'=>$password],null);
    if (($err = apiFailure($login)) !== null) throw new RuntimeException('Login failed: ' . $err);
    $bearer = deepToken($login['json']);

    $me = requestWithRetry($config,$cookieFile,'GET','/api/me',null,$bearer);
    if (($err = apiFailure($me)) !== null) throw new RuntimeException('Auth verification failed: ' . $err);

    $ur = requestWithRetry($config,$cookieFile,'GET','/api/users',null,$bearer);
    if (($err = apiFailure($ur)) !== null) throw new RuntimeException('Loading users failed: ' . $err);
    $users = extractUsers($ur['json']);
    $expectedUsers = (int)($config['expected_users'] ?? 11);
    $result['users_found'] = count($users);
    if (count($users) !== $expectedUsers) throw new RuntimeException("Safety stop: expected {$expectedUsers} users, found " . count($users));

    foreach ($users as $index => $user) {
        $id = userId($user);
        $xName = xUserName($user, $id, $config);
        $ref = $id !== null ? (string)$id : 'row-' . ($index + 1);
        $actions = [
            ['platform'=>'x','endpoint'=>'/api/x-data/user/save','body'=>($id !== null && $xName !== null) ? ['userId'=>$id,'userName'=>$xName] : null,'mapping_ok'=>($id !== null && $xName !== null)],
            ['platform'=>'facebook','endpoint'=>$id !== null ? '/api/fb-data/posts/' . rawurlencode((string)$id) : '/api/fb-data/posts/:id','body'=>null,'mapping_ok'=>$id !== null],
            ['platform'=>'instagram','endpoint'=>$id !== null ? '/api/instagram-data/posts/' . rawurlencode((string)$id) : '/api/instagram-data/posts/:id','body'=>null,'mapping_ok'=>$id !== null],
        ];

        foreach ($actions as $a) {
            $platform = $a['platform'];
            $endpoint = $a['endpoint'];
            $result['issued']++;
            $result['platforms'][$platform]['issued']++;

            if (!$a['mapping_ok']) {
                $error = $platform === 'x' ? 'Could not map user id/X username.' : 'Could not map user id.';
                $result['failed']++;
                $result['platforms'][$platform]['failed']++;
                $result['failures'][] = ['user'=>$ref,'platform'=>$platform,'error'=>$error];
                addItem($pdo,$runId,$ref,$platform,$endpoint,['status'=>0,'attempts'=>1],false,$error);
                continue;
            }

            $r = requestWithRetry($config,$cookieFile,'POST',$endpoint,$a['body'],$bearer);
            $error = apiFailure($r);
            $ok = $error === null;
            if (!$ok) {
                $result['failed']++;
                $result['platforms'][$platform]['failed']++;
                $result['failures'][] = ['user'=>$ref,'platform'=>$platform,'error'=>$error];
            }
            addItem($pdo,$runId,$ref,$platform,$endpoint,$r,$ok,$error);
            $pause = (int)($config['pause_between_requests_ms'] ?? 300);
            if ($pause > 0) usleep($pause * 1000);
        }
    }

    $result['ok'] = ($result['issued'] === $expectedUsers * 3 && $result['failed'] === 0);
    $result['status'] = $result['ok'] ? 'completed' : 'failed';
    $result['slot'] = slotKey($slot);
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $pdo->prepare("UPDATE xa_refresh_runs SET completed_at=UTC_TIMESTAMP(),status=?,users_found=?,issued=?,failed=?,error_text=?,result_json=? WHERE id=?");
    $stmt->execute([$result['status'],$result['users_found'],$result['issued'],$result['failed'],$result['ok']?null:'One or more actions failed.',$json,$runId]);

    $stmt = $pdo->prepare("UPDATE xa_refresh_slots SET status=?,completed_at=CASE WHEN ?='success' THEN UTC_TIMESTAMP() ELSE completed_at END,issued=?,failed=?,error_text=?,result_json=? WHERE id=?");
    $slotStatus = $result['ok'] ? 'success' : 'failed';
    $stmt->execute([$slotStatus,$slotStatus,$result['issued'],$result['failed'],$result['ok']?null:'One or more actions failed.',$json,$slotId]);

    writeLog($config,['at'=>$now->format(DateTimeInterface::ATOM),'run_id'=>$runId,'result'=>$result]);
    echo $json . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
} catch (Throwable $e) {
    $result['status'] = 'failed';
    $result['error'] = $e->getMessage();
    $result['slot'] = $slot instanceof DateTimeImmutable ? slotKey($slot) : null;
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($pdo instanceof PDO) {
        try {
            if ($runId !== null) {
                $stmt = $pdo->prepare("UPDATE xa_refresh_runs SET completed_at=UTC_TIMESTAMP(),status='failed',issued=?,failed=?,error_text=?,result_json=? WHERE id=?");
                $stmt->execute([$result['issued'],$result['failed'],substr($e->getMessage(),0,2000),$json,$runId]);
            }
            if ($slotId !== null) {
                $stmt = $pdo->prepare("UPDATE xa_refresh_slots SET status='failed',issued=?,failed=?,error_text=?,result_json=? WHERE id=?");
                $stmt->execute([$result['issued'],$result['failed'],substr($e->getMessage(),0,2000),$json,$slotId]);
            }
        } catch (Throwable) {}
    }
    writeLog($config,['at'=>$now->format(DateTimeInterface::ATOM),'run_id'=>$runId,'result'=>$result]);
    fwrite(STDERR, $json . PHP_EOL);
    exit(1);
} finally {
    if ($cookieFile && is_file($cookieFile)) @unlink($cookieFile);
    if ($pdo instanceof PDO) {
        try { $pdo->query("SELECT RELEASE_LOCK('xa_refresh_v2')"); } catch (Throwable) {}
    }
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}
