<?php
/**
 * Mono Studio OS — Google Search Console
 * Autenticación: cuenta de servicio (JWT). Sin OAuth de usuario.
 *
 * GET  action=status|query
 * POST action=config → guarda siteUrl + pages
 *
 * Credenciales: data/gsc-service-account.json (el JSON que baja Google Cloud)
 */

declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

$dataDir = dirname(__DIR__) . '/data';
$configFile = $dataDir . '/gsc.json';
$keyFilePreferred = $dataDir . '/gsc-service-account.json';
$cacheFile = $dataDir . '/gsc-cache.json';
$tokenFile = $dataDir . '/gsc-token.json';

const GSC_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
const GSC_CACHE_TTL = 900;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function gscJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function gscReadJson(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $fallback;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $fallback;
}

function gscWriteJson(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        return false;
    }
    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return false;
    }
    $tmp = $path . '.tmp';
    return file_put_contents($tmp, $payload, LOCK_EX) !== false && rename($tmp, $path);
}

function gscHttp(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $payload = $body !== null ? json_encode($body) : null;
    $headerList = $headers;
    if ($payload !== null) {
        $headerList[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerList,
            CURLOPT_TIMEOUT => 25,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'error' => $err ?: 'cURL falló', 'data' => null];
        }
        $data = json_decode($raw, true);
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'error' => null, 'data' => $data];
    }

    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerList),
            'timeout' => 25,
            'ignore_errors' => true,
        ],
    ];
    if ($payload !== null) {
        $opts['http']['content'] = $payload;
    }
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'No se pudo contactar a Google', 'data' => null];
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'error' => null,
        'data' => json_decode((string) $raw, true),
    ];
}

function gscFormPost(string $url, array $fields): array
{
    $body = http_build_query($fields);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 25,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'error' => $err ?: 'cURL falló', 'data' => null];
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => json_decode($raw, true)];
    }

    $raw = @file_get_contents($url, false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 25,
            'ignore_errors' => true,
        ],
    ]));
    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'No se pudo contactar a Google', 'data' => null];
    }
    return ['ok' => true, 'status' => 200, 'data' => json_decode($raw, true)];
}

function gscSanitizePages(array $pages): array
{
    $out = [];
    foreach (array_slice($pages, 0, 12) as $page) {
        if (is_string($page)) {
            $url = trim($page);
            $label = '';
        } elseif (is_array($page)) {
            $url = trim((string) ($page['url'] ?? ''));
            $label = trim((string) ($page['label'] ?? ''));
        } else {
            continue;
        }
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        $clip = static fn (string $s, int $n): string => function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
        $out[] = ['url' => $clip($url, 500), 'label' => $clip($label, 80)];
    }
    return $out;
}

function gscSanitizeSiteUrl(string $siteUrl): string
{
    $siteUrl = trim($siteUrl);
    if (preg_match('#^sc-domain:[a-z0-9.-]+$#i', $siteUrl)) {
        return $siteUrl;
    }
    if (preg_match('#^https?://#i', $siteUrl)) {
        return function_exists('mb_substr') ? mb_substr($siteUrl, 0, 300) : substr($siteUrl, 0, 300);
    }
    return '';
}

function gscIsServiceAccount(array $data): bool
{
    return ($data['type'] ?? '') === 'service_account'
        && !empty($data['client_email'])
        && !empty($data['private_key']);
}

function gscLoadServiceAccount(string $dataDir, string $preferred): array
{
    $candidates = [$preferred];
    foreach (glob($dataDir . '/*.json') ?: [] as $file) {
        if ($file !== $preferred) {
            $candidates[] = $file;
        }
    }

    foreach ($candidates as $file) {
        $data = gscReadJson($file);
        if (gscIsServiceAccount($data)) {
            $data['_path'] = basename($file);
            return $data;
        }
    }

    return [];
}

function gscB64Url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function gscAccessToken(array $sa, string $tokenFile): string
{
    $cached = gscReadJson($tokenFile, []);
    $token = (string) ($cached['access_token'] ?? '');
    $exp = (int) ($cached['expires_at'] ?? 0);
    if ($token !== '' && $exp > time() + 60) {
        return $token;
    }

    $email = (string) ($sa['client_email'] ?? '');
    $privateKey = (string) ($sa['private_key'] ?? '');
    if ($email === '' || $privateKey === '') {
        throw new RuntimeException('Falta el JSON de la cuenta de servicio en /ers/data/gsc-service-account.json');
    }
    if (!function_exists('openssl_sign')) {
        throw new RuntimeException('PHP no tiene OpenSSL; no se puede firmar el JWT de Google.');
    }

    $now = time();
    $header = gscB64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $claims = gscB64Url(json_encode([
        'iss' => $email,
        'scope' => GSC_SCOPE,
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ], JSON_UNESCAPED_SLASHES));
    $unsigned = $header . '.' . $claims;

    $key = openssl_pkey_get_private($privateKey);
    if ($key === false) {
        throw new RuntimeException('La private_key del JSON de servicio no es válida.');
    }
    $ok = openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);
    if (PHP_VERSION_ID < 80000) {
        openssl_pkey_free($key);
    }
    if (!$ok || $signature === '') {
        throw new RuntimeException('No se pudo firmar el JWT de la cuenta de servicio.');
    }

    $res = gscFormPost('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $unsigned . '.' . gscB64Url($signature),
    ]);

    $access = $res['data']['access_token'] ?? null;
    if (!$res['ok'] || !is_string($access) || $access === '') {
        $msg = $res['data']['error_description'] ?? $res['data']['error'] ?? $res['error'] ?? 'Google rechazó la cuenta de servicio';
        throw new RuntimeException((string) $msg);
    }

    gscWriteJson($tokenFile, [
        'access_token' => $access,
        'expires_at' => time() + (int) ($res['data']['expires_in'] ?? 3500),
        'email' => $email,
    ]);

    return $access;
}

function gscQueryAnalytics(string $token, string $siteUrl, string $start, string $end, array $extra = []): array
{
    $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';
    $body = array_merge([
        'startDate' => $start,
        'endDate' => $end,
        'rowLimit' => 250,
    ], $extra);

    $res = gscHttp('POST', $endpoint, $body, ['Authorization: Bearer ' . $token]);
    if (!$res['ok']) {
        $msg = $res['data']['error']['message'] ?? $res['error'] ?? 'Error al consultar Search Console';
        throw new RuntimeException((string) $msg);
    }

    return is_array($res['data']) ? $res['data'] : [];
}

function gscMetricRow(?array $row): array
{
    $clicks = (float) ($row['clicks'] ?? 0);
    $impr = (float) ($row['impressions'] ?? 0);
    $ctr = (float) ($row['ctr'] ?? 0);
    $pos = (float) ($row['position'] ?? 0);
    return [
        'clicks' => (int) round($clicks),
        'impressions' => (int) round($impr),
        'ctr' => $ctr,
        'position' => $pos,
    ];
}

function gscDelta(array $now, array $prev): array
{
    return [
        'clicks' => $now['clicks'] - $prev['clicks'],
        'impressions' => $now['impressions'] - $prev['impressions'],
        'ctr' => $now['ctr'] - $prev['ctr'],
        'position' => $prev['position'] > 0 && $now['position'] > 0
            ? $prev['position'] - $now['position']
            : 0,
    ];
}

$config = gscReadJson($configFile, ['siteUrl' => '', 'pages' => []]);
$serviceAccount = gscLoadServiceAccount($dataDir, $keyFilePreferred);
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($action === '' || $action === 'status')) {
    gscJson([
        'connected' => $serviceAccount !== [],
        'auth' => 'service_account',
        'serviceEmail' => (string) ($serviceAccount['client_email'] ?? ''),
        'keyFile' => (string) ($serviceAccount['_path'] ?? 'gsc-service-account.json'),
        'siteUrl' => (string) ($config['siteUrl'] ?? ''),
        'pages' => gscSanitizePages($config['pages'] ?? []),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'config') {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        gscJson(['error' => 'JSON inválido'], 400);
    }

    $siteUrl = gscSanitizeSiteUrl((string) ($input['siteUrl'] ?? ''));
    if ($siteUrl === '') {
        gscJson(['error' => 'Propiedad GSC inválida. Usa sc-domain:tudominio.cl o https://tudominio.cl/'], 400);
    }

    $pages = gscSanitizePages(is_array($input['pages'] ?? null) ? $input['pages'] : []);
    $config = ['siteUrl' => $siteUrl, 'pages' => $pages];
    if (!gscWriteJson($configFile, $config)) {
        gscJson(['error' => 'No se pudo guardar la configuración'], 500);
    }

    @unlink($cacheFile);
    gscJson(['ok' => true, 'pages' => $pages, 'siteUrl' => $siteUrl]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'query') {
    try {
        if ($serviceAccount === []) {
            gscJson([
                'error' => 'No hay cuenta de servicio. Sube el JSON a ers/data/gsc-service-account.json y agrega su email como usuario en Search Console.',
            ], 400);
        }

        $siteUrl = gscSanitizeSiteUrl((string) ($config['siteUrl'] ?? ''));
        $pages = gscSanitizePages($config['pages'] ?? []);
        if ($siteUrl === '') {
            gscJson(['error' => 'Falta la propiedad de Search Console'], 400);
        }
        if ($pages === []) {
            gscJson(['error' => 'Agrega al menos una URL para monitorear'], 400);
        }

        $cached = gscReadJson($cacheFile, []);
        $fresh = (string) ($_GET['fresh'] ?? '') === '1';
        if (
            !$fresh
            && isset($cached['fetchedAt'])
            && (time() - (int) $cached['fetchedAt']) < GSC_CACHE_TTL
            && ($cached['siteUrl'] ?? '') === $siteUrl
        ) {
            $cached['cached'] = true;
            gscJson($cached);
        }

        $token = gscAccessToken($serviceAccount, $tokenFile);
        $end = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-3 days');
        $start = $end->modify('-27 days');
        $prevEnd = $start->modify('-1 day');
        $prevStart = $prevEnd->modify('-27 days');

        $startIso = $start->format('Y-m-d');
        $endIso = $end->format('Y-m-d');
        $prevStartIso = $prevStart->format('Y-m-d');
        $prevEndIso = $prevEnd->format('Y-m-d');

        $current = gscQueryAnalytics($token, $siteUrl, $startIso, $endIso, ['dimensions' => ['page']]);
        $previous = gscQueryAnalytics($token, $siteUrl, $prevStartIso, $prevEndIso, ['dimensions' => ['page']]);
        $totalsNow = gscQueryAnalytics($token, $siteUrl, $startIso, $endIso);
        $totalsPrev = gscQueryAnalytics($token, $siteUrl, $prevStartIso, $prevEndIso);

        $indexRows = static function (array $payload): array {
            $map = [];
            foreach ($payload['rows'] ?? [] as $row) {
                $keys = $row['keys'] ?? [];
                $url = is_array($keys) ? (string) ($keys[0] ?? '') : '';
                if ($url !== '') {
                    $map[rtrim($url, '/')] = $row;
                    $map[$url] = $row;
                }
            }
            return $map;
        };

        $nowMap = $indexRows($current);
        $prevMap = $indexRows($previous);
        $totals = gscMetricRow($totalsNow['rows'][0] ?? null);
        $totalsBefore = gscMetricRow($totalsPrev['rows'][0] ?? null);

        $pageRows = [];
        $seen = [];
        foreach ($pages as $page) {
            $key = rtrim($page['url'], '/');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $now = gscMetricRow($nowMap[$page['url']] ?? $nowMap[$key] ?? null);
            $prev = gscMetricRow($prevMap[$page['url']] ?? $prevMap[$key] ?? null);
            $pageRows[] = [
                'url' => $page['url'],
                'label' => $page['label'] !== '' ? $page['label'] : $page['url'],
                'current' => $now,
                'previous' => $prev,
                'delta' => gscDelta($now, $prev),
            ];
        }

        $payload = [
            'connected' => true,
            'cached' => false,
            'fetchedAt' => time(),
            'siteUrl' => $siteUrl,
            'range' => [
                'start' => $startIso,
                'end' => $endIso,
                'prevStart' => $prevStartIso,
                'prevEnd' => $prevEndIso,
            ],
            'totals' => $totals,
            'totalsDelta' => gscDelta($totals, $totalsBefore),
            'pages' => $pageRows,
        ];
        gscWriteJson($cacheFile, $payload);
        gscJson($payload);
    } catch (Throwable $e) {
        gscJson(['error' => $e->getMessage()], 500);
    }
}

gscJson(['error' => 'Método no permitido'], 405);
