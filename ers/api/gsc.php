<?php
/**
 * Mono Studio OS — Google Search Console
 * Autenticación: cuenta de servicio (JWT). Sin OAuth de usuario.
 *
 * GET  action=sites            → propiedades que ve el bot
 * GET  action=site&host=x.cl   → métricas, señales e inventario de un cliente
 * POST action=config           → override de sitemap/URLs para un host
 *
 * Credenciales: data/gsc-service-account.json (el JSON que baja Google Cloud)
 */

declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

$dataDir = dirname(__DIR__) . '/data';
$configFile = $dataDir . '/gsc.json';
$keyFilePreferred = $dataDir . '/gsc-service-account.json';
$tokenFile = $dataDir . '/gsc-token.json';

const GSC_SCOPE = 'https://www.googleapis.com/auth/webmasters';
const GSC_CACHE_TTL = 900;
const GSC_MAX_MANUAL_PAGES = 40;
const GSC_MAX_SITEMAP_URLS = 120;
const GSC_MAX_QUERY_PROBES = 12;
const GSC_MAX_URL_INSPECTIONS = 18;
const GSC_TREND_DAYS = 90;

/** Peticiones simultáneas a Google. Más alto arriesga rate limiting. */
const GSC_CONCURRENCY = 6;

/**
 * Segundos de trabajo antes de devolver lo que haya. El proxy del hosting corta
 * con 504 alrededor de los 60s, así que cerramos antes y marcamos `partial`.
 */
const GSC_TIME_BUDGET = 40;

/** Sube al cambiar la lógica de resolución de propiedad; sirve para saber qué versión está viva en el server. */
const GSC_VERSION = '2026.08.20-ops';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function gscTimeLeft(float $deadline): float
{
    return $deadline - microtime(true);
}

function gscJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
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
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
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

/**
 * Ejecuta varias peticiones en paralelo con curl_multi. Sin curl cae a modo
 * secuencial, que es lento pero correcto.
 *
 * @param array<string,array{method:string,url:string,body?:?array,headers?:string[]}> $jobs
 * @return array<string,array{ok:bool,status:int,error:?string,data:mixed}>
 */
function gscMultiFetch(array $jobs, int $concurrency = GSC_CONCURRENCY): array
{
    if ($jobs === []) {
        return [];
    }

    if (!function_exists('curl_multi_init')) {
        $out = [];
        foreach ($jobs as $key => $job) {
            $out[$key] = gscHttp($job['method'], $job['url'], $job['body'] ?? null, $job['headers'] ?? []);
        }
        return $out;
    }

    $results = [];
    $multi = curl_multi_init();
    $handles = [];
    $pending = $jobs;

    $push = static function () use (&$pending, &$handles, $multi, $concurrency): void {
        while ($pending !== [] && count($handles) < $concurrency) {
            $key = array_key_first($pending);
            $job = $pending[$key];
            unset($pending[$key]);

            $headers = $job['headers'] ?? [];
            $payload = isset($job['body']) && $job['body'] !== null ? json_encode($job['body']) : null;
            if ($payload !== null) {
                $headers[] = 'Content-Type: application/json';
            }

            $ch = curl_init($job['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $job['method'],
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            curl_multi_add_handle($multi, $ch);
            $handles[(int) $ch] = ['handle' => $ch, 'key' => $key];
        }
    };

    $push();

    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 0.5);
        }

        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $entry = $handles[(int) $ch] ?? null;
            if ($entry !== null) {
                $raw = curl_multi_getcontent($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                $results[$entry['key']] = [
                    'ok' => $code >= 200 && $code < 300,
                    'status' => $code,
                    'error' => $err !== '' ? $err : null,
                    'data' => is_string($raw) ? json_decode($raw, true) : null,
                ];
                unset($handles[(int) $ch]);
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        $push();
        $running = $running > 0 || $handles !== [] || $pending !== [];
    } while ($running && $status === CURLM_OK);

    curl_multi_close($multi);

    foreach ($jobs as $key => $_) {
        $results[$key] ??= ['ok' => false, 'status' => 0, 'error' => 'Sin respuesta', 'data' => null];
    }
    return $results;
}

function gscJobError(array $res, string $fallback): string
{
    return (string) ($res['data']['error']['message'] ?? $res['error'] ?? $fallback);
}

function gscAnalyticsJob(string $token, string $property, string $start, string $end, array $extra = []): array
{
    return [
        'method' => 'POST',
        'url' => 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/searchAnalytics/query',
        'body' => array_merge(['startDate' => $start, 'endDate' => $end, 'rowLimit' => 1000], $extra),
        'headers' => ['Authorization: Bearer ' . $token],
    ];
}

function gscInspectJob(string $token, string $property, string $url): array
{
    return [
        'method' => 'POST',
        'url' => 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
        'body' => ['inspectionUrl' => $url, 'siteUrl' => $property, 'languageCode' => 'es-CL'],
        'headers' => ['Authorization: Bearer ' . $token],
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
    foreach (array_slice($pages, 0, GSC_MAX_MANUAL_PAGES) as $page) {
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
        if (gscIsAgencyHost($url)) {
            continue;
        }
        $url = preg_replace('#\#.*$#', '', $url) ?? $url;
        $clip = static fn (string $s, int $n): string => function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
        $out[] = ['url' => $clip($url, 500), 'label' => $clip($label, 80)];
    }
    return gscMergePages($out);
}

function gscSanitizeSitemapUrl(string $url): string
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url) || gscIsAgencyHost($url)) {
        return '';
    }
    return preg_replace('#\#.*$#', '', $url) ?? $url;
}

function gscIsAgencyHost(string $url): bool
{
    return gscHost($url) === 'monostudio.cl';
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
        // El .example.json tiene forma de cuenta de servicio pero no sirve para firmar.
        if ($file !== $preferred && !str_contains(basename($file), '.example.')) {
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
    $cachedScope = (string) ($cached['scope'] ?? '');
    if ($token !== '' && $exp > time() + 60 && $cachedScope === GSC_SCOPE) {
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
        'scope' => GSC_SCOPE,
    ]);

    return $access;
}

function gscQueryAnalytics(string $token, string $siteUrl, string $start, string $end, array $extra = []): array
{
    $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';
    $body = array_merge([
        'startDate' => $start,
        'endDate' => $end,
        'rowLimit' => 1000,
    ], $extra);

    $res = gscHttp('POST', $endpoint, $body, ['Authorization: Bearer ' . $token]);
    if (!$res['ok']) {
        $msg = $res['data']['error']['message'] ?? $res['error'] ?? 'Error al consultar Search Console';
        throw new RuntimeException((string) $msg);
    }

    return is_array($res['data']) ? $res['data'] : [];
}

function gscListSites(string $token): array
{
    $res = gscHttp('GET', 'https://www.googleapis.com/webmasters/v3/sites', null, [
        'Authorization: Bearer ' . $token,
    ]);
    if (!$res['ok']) {
        $msg = $res['data']['error']['message'] ?? $res['error'] ?? 'No se pudieron listar las propiedades GSC';
        throw new RuntimeException((string) $msg);
    }
    $entries = $res['data']['siteEntry'] ?? [];
    return is_array($entries) ? $entries : [];
}

function gscAddSite(string $token, string $property): bool
{
    $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property);
    $res = gscHttp('PUT', $endpoint, null, ['Authorization: Bearer ' . $token]);
    return $res['ok'] || (int) ($res['status'] ?? 0) === 204;
}

function gscHost(string $url): string
{
    if (stripos($url, 'sc-domain:') === 0) {
        return strtolower(substr($url, strlen('sc-domain:')));
    }
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
    return preg_replace('#^www\.#', '', $host) ?? $host;
}

function gscPropertyCandidates(string $pageUrl, array $sites): array
{
    $host = gscHost($pageUrl);
    $candidates = [];
    if ($host !== '') {
        $candidates[] = 'sc-domain:' . $host;
    }

    foreach ($sites as $entry) {
        $site = (string) ($entry['siteUrl'] ?? '');
        if ($site === '' || gscHost($site) !== $host) {
            continue;
        }
        $candidates[] = $site;
    }

    $unique = [];
    foreach ($candidates as $item) {
        if ($item !== '' && !in_array($item, $unique, true)) {
            $unique[] = $item;
        }
    }
    return $unique;
}

function gscProbeProperty(string $token, string $property): array
{
    try {
        $end = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-3 days');
        $start = $end->modify('-1 day');
        gscQueryAnalytics($token, $property, $start->format('Y-m-d'), $end->format('Y-m-d'));
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function gscHumanizeError(string $message, string $host): string
{
    if (stripos($message, 'sufficient permission') !== false) {
        return "Google rechazó la propiedad URL https://{$host}/. En Search Console el bot debe estar en la propiedad de dominio y la API consulta sc-domain:{$host}. Quita y vuelve a agregar gsc-reader-bot con permiso Completo en esa propiedad de dominio.";
    }
    return $message;
}

function gscResolveProperty(string $token, string $pageUrl, array &$sites): array
{
    $host = gscHost($pageUrl);
    $domainProp = $host !== '' ? 'sc-domain:' . $host : '';
    if ($domainProp !== '') {
        gscAddSite($token, $domainProp);
        try {
            $sites = gscListSites($token);
        } catch (Throwable $e) {
            // seguimos con la lista previa
        }
    }

    $lastError = '';
    $tried = [];
    foreach (gscPropertyCandidates($pageUrl, $sites) as $candidate) {
        $tried[] = $candidate;
        $probe = gscProbeProperty($token, $candidate);
        if ($probe['ok']) {
            return ['property' => $candidate, 'error' => ''];
        }
        $lastError = $probe['error'] ?: $lastError;
    }

    $listed = array_values(array_filter(array_map(
        static fn ($e) => (string) ($e['siteUrl'] ?? ''),
        $sites
    ), static fn ($s) => gscHost($s) === $host));

    $hint = $listed !== []
        ? ' El bot ve: ' . implode(', ', $listed) . '.'
        : ' El bot no ve sc-domain:' . $host . ' ni ninguna URL de ese host.';

    return [
        'property' => '',
        'error' => gscHumanizeError($lastError ?: 'Sin acceso a ' . $host, $host)
            . $hint
            . ' Probé: ' . ($tried !== [] ? implode(', ', $tried) : '—')
            . ' [v' . GSC_VERSION . ']',
    ];
}

function gscIndexRows(array $payload): array
{
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

function gscPageKey(string $url): string
{
    return rtrim(strtolower(trim($url)), '/');
}

function gscMergePages(array ...$groups): array
{
    $out = [];
    $seen = [];
    foreach ($groups as $pages) {
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $url = trim((string) ($page['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $key = gscPageKey($url);
            if (isset($seen[$key])) {
                if (($out[$seen[$key]]['label'] ?? '') === '' && !empty($page['label'])) {
                    $out[$seen[$key]]['label'] = trim((string) $page['label']);
                }
                continue;
            }
            $seen[$key] = count($out);
            $out[] = [
                'url' => $url,
                'label' => trim((string) ($page['label'] ?? '')),
            ];
        }
    }
    return $out;
}

function gscSiteHomeUrl(string $siteUrl, array $pages = [], string $sitemapUrl = ''): string
{
    if ($pages !== []) {
        return (string) ($pages[0]['url'] ?? '');
    }
    if ($sitemapUrl !== '') {
        $host = gscHost($sitemapUrl);
        return $host !== '' ? 'https://' . $host . '/' : '';
    }
    $host = gscHost($siteUrl);
    return $host !== '' ? 'https://' . $host . '/' : '';
}

function gscSitemapCandidates(string $siteUrl, array $pages, string $sitemapUrl): array
{
    $out = [];
    if ($sitemapUrl !== '') {
        $out[] = $sitemapUrl;
    }
    $home = rtrim(gscSiteHomeUrl($siteUrl, $pages, $sitemapUrl), '/');
    if ($home !== '') {
        $out[] = $home . '/sitemap_index.xml';
        $out[] = $home . '/sitemap.xml';
    }

    $unique = [];
    foreach ($out as $item) {
        if ($item !== '' && !in_array($item, $unique, true)) {
            $unique[] = $item;
        }
    }
    return $unique;
}

function gscFetchText(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'MonoStudio-GSC/1.0',
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException($error !== '' ? $error : 'No se pudo descargar el sitemap');
        }
        return (string) $raw;
    }

    $raw = @file_get_contents($url, false, stream_context_create([
        'http' => [
            'timeout' => 25,
            'ignore_errors' => true,
            'header' => "User-Agent: MonoStudio-GSC/1.0\r\n",
        ],
    ]));
    if ($raw === false || $raw === '') {
        throw new RuntimeException('No se pudo descargar el sitemap');
    }
    return (string) $raw;
}

function gscParseSitemapXml(string $xml): array
{
    if (!function_exists('simplexml_load_string')) {
        throw new RuntimeException('PHP no tiene SimpleXML para leer sitemaps.');
    }

    libxml_use_internal_errors(true);
    $node = simplexml_load_string($xml);
    if ($node === false) {
        throw new RuntimeException('El sitemap XML no es válido.');
    }

    $root = strtolower($node->getName());
    $namespaces = $node->getNamespaces(true);
    $defaultNs = $namespaces[''] ?? null;
    $children = $defaultNs ? $node->children($defaultNs) : $node;
    $urls = [];
    $sitemaps = [];

    if ($root === 'urlset') {
        foreach ($children->url as $entry) {
            $loc = trim((string) ($entry->loc ?? ''));
            if ($loc === '' || !preg_match('#^https?://#i', $loc) || gscIsAgencyHost($loc)) {
                continue;
            }
            $urls[] = $loc;
        }
    } elseif ($root === 'sitemapindex') {
        foreach ($children->sitemap as $entry) {
            $loc = trim((string) ($entry->loc ?? ''));
            if ($loc !== '' && preg_match('#^https?://#i', $loc)) {
                $sitemaps[] = $loc;
            }
        }
    } else {
        throw new RuntimeException('La fuente XML no parece un sitemap.');
    }

    return ['root' => $root, 'urls' => $urls, 'sitemaps' => $sitemaps];
}

function gscDiscoverPagesFromSitemap(array $candidates, float $deadline = INF): array
{
    $visited = [];
    $queue = $candidates;
    $pages = [];
    $source = '';
    $lastError = '';

    while ($queue !== [] && count($pages) < GSC_MAX_SITEMAP_URLS && count($visited) < 18) {
        // Leer sitemaps anidados no puede consumir el presupuesto de las métricas.
        if (gscTimeLeft($deadline) < 12) {
            break;
        }
        $current = array_shift($queue);
        if (!$current || isset($visited[$current])) {
            continue;
        }
        $visited[$current] = true;
        try {
            $parsed = gscParseSitemapXml(gscFetchText($current));
            if ($source === '') {
                $source = $current;
            }
            foreach ($parsed['urls'] as $url) {
                if (count($pages) >= GSC_MAX_SITEMAP_URLS) {
                    break;
                }
                $pages[] = ['url' => $url, 'label' => ''];
            }
            foreach ($parsed['sitemaps'] as $child) {
                if (!isset($visited[$child]) && count($queue) < 18) {
                    $queue[] = $child;
                }
            }
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
        }
    }

    return [
        'source' => $source,
        'pages' => gscMergePages($pages),
        'error' => $source === '' ? $lastError : '',
    ];
}

function gscListSitemaps(string $token, string $siteUrl): array
{
    $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/sitemaps';
    $res = gscHttp('GET', $endpoint, null, ['Authorization: Bearer ' . $token]);
    if (!$res['ok']) {
        $msg = $res['data']['error']['message'] ?? $res['error'] ?? 'No se pudieron leer los sitemaps';
        throw new RuntimeException((string) $msg);
    }
    $items = $res['data']['sitemap'] ?? [];
    return is_array($items) ? $items : [];
}

function gscAggregateWeekdays(array $daily): array
{
    $buckets = [];
    foreach ($daily as $row) {
        $stamp = strtotime((string) ($row['date'] ?? ''));
        if ($stamp === false) {
            continue;
        }
        $idx = (int) date('w', $stamp);
        if (!isset($buckets[$idx])) {
            $buckets[$idx] = ['clicks' => 0, 'impressions' => 0, 'days' => 0];
        }
        $buckets[$idx]['clicks'] += (int) ($row['clicks'] ?? 0);
        $buckets[$idx]['impressions'] += (int) ($row['impressions'] ?? 0);
        $buckets[$idx]['days']++;
    }

    $labels = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];
    $out = [];
    foreach ($buckets as $idx => $bucket) {
        $days = max(1, (int) $bucket['days']);
        $out[] = [
            'label' => $labels[$idx] ?? (string) $idx,
            'clicks' => (int) round($bucket['clicks'] / $days),
            'impressions' => (int) round($bucket['impressions'] / $days),
        ];
    }
    usort($out, static fn ($a, $b) => ($b['clicks'] <=> $a['clicks']) ?: ($b['impressions'] <=> $a['impressions']));
    return $out;
}

function gscAggregateMonths(array $daily): array
{
    $buckets = [];
    foreach ($daily as $row) {
        $date = (string) ($row['date'] ?? '');
        if (!preg_match('#^\d{4}-\d{2}-\d{2}$#', $date)) {
            continue;
        }
        $month = substr($date, 0, 7);
        if (!isset($buckets[$month])) {
            $buckets[$month] = ['clicks' => 0, 'impressions' => 0];
        }
        $buckets[$month]['clicks'] += (int) ($row['clicks'] ?? 0);
        $buckets[$month]['impressions'] += (int) ($row['impressions'] ?? 0);
    }
    ksort($buckets);
    $out = [];
    foreach ($buckets as $month => $bucket) {
        $out[] = [
            'label' => $month,
            'clicks' => $bucket['clicks'],
            'impressions' => $bucket['impressions'],
        ];
    }
    return array_slice($out, -4);
}

function gscMergeDailyBucket(array &$bucket, array $rows): void
{
    foreach ($rows as $row) {
        $date = (string) ($row['date'] ?? '');
        if ($date === '') {
            continue;
        }
        if (!isset($bucket[$date])) {
            $bucket[$date] = ['date' => $date, 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
        }
        $bucket[$date]['clicks'] += (int) ($row['clicks'] ?? 0);
        $bucket[$date]['impressions'] += (int) ($row['impressions'] ?? 0);
        if (($row['impressions'] ?? 0) > 0) {
            $bucket[$date]['position'] += ((float) ($row['position'] ?? 0)) * ((int) ($row['impressions'] ?? 0));
        }
    }
}

function gscIndexLabel(array $status): array
{
    $verdict = strtoupper((string) ($status['verdict'] ?? ''));
    $coverage = (string) ($status['coverageState'] ?? '');
    $label = $coverage !== '' ? $coverage : 'Sin inspección';
    $tone = 'neutral';
    if ($verdict === 'PASS' || stripos($coverage, 'Submitted and indexed') !== false || stripos($coverage, 'Indexed') !== false) {
        $label = 'Indexada';
        $tone = 'indexed';
    } elseif ($verdict === 'FAIL' || stripos($coverage, 'not indexed') !== false || stripos($coverage, 'Error') !== false) {
        $tone = 'issue';
    }
    return [
        'label' => $label,
        'tone' => $tone,
        'coverage' => $coverage,
        'lastCrawl' => (string) ($status['lastCrawlTime'] ?? ''),
    ];
}

function gscThermometer(array $now, array $delta, float $siteCtr): array
{
    if ($now['impressions'] >= 80 && $now['position'] > 0 && $now['position'] <= 15 && $now['ctr'] < max($siteCtr * 0.7, 0.012)) {
        return ['label' => 'Pide empujón', 'tone' => 'push'];
    }
    if ($delta['clicks'] <= -5 || ($delta['position'] ?? 0) < -1.5) {
        return ['label' => 'Cayendo', 'tone' => 'down'];
    }
    if ($now['clicks'] > 0 || $now['impressions'] >= 40) {
        return ['label' => 'Activa', 'tone' => 'up'];
    }
    return ['label' => 'Sin tracción', 'tone' => 'neutral'];
}

/** Nombre corto y legible de una URL: "/servicios/seo" → "servicios/seo". */
function gscPageTitle(array $row): string
{
    $label = trim((string) ($row['label'] ?? ''));
    if ($label !== '' && !preg_match('#^https?://#i', $label)) {
        return $label;
    }
    $path = trim((string) (parse_url((string) ($row['url'] ?? ''), PHP_URL_PATH) ?? ''), '/');
    return $path === '' ? 'Home' : $path;
}

/**
 * Convierte métricas en acciones. La severidad (3 alta → 1 baja) es lo único que
 * el front necesita para priorizar; así el orden vive en un solo lugar.
 */
function gscBuildSignals(array $rows): array
{
    $signals = [];

    foreach ($rows as $row) {
        $url = (string) ($row['url'] ?? '');
        $title = gscPageTitle($row);
        $now = is_array($row['current'] ?? null) ? $row['current'] : [];
        $delta = is_array($row['delta'] ?? null) ? $row['delta'] : [];
        $tone = (string) ($row['thermometer']['tone'] ?? '');
        $indexTone = (string) ($row['indexStatus']['tone'] ?? '');

        if (!empty($row['error'])) {
            $signals[] = [
                'kind' => 'blocked',
                'severity' => 3,
                'title' => 'Sin acceso a ' . $title,
                'detail' => 'Search Console rechazó esta URL. Revisa permisos del bot en la propiedad.',
                'metric' => 'Bloqueada',
                'url' => $url,
            ];
            continue;
        }

        if ($indexTone === 'issue') {
            $signals[] = [
                'kind' => 'noindex',
                'severity' => 3,
                'title' => $title . ' no está indexada',
                'detail' => (string) ($row['indexStatus']['coverage'] ?? 'Google no la tiene en el índice.'),
                'metric' => 'Pedir indexación',
                'url' => $url,
            ];
        }

        if ($tone === 'down') {
            $lost = (int) ($delta['clicks'] ?? 0);
            $posMove = round((float) ($delta['position'] ?? 0), 1);
            $signals[] = [
                'kind' => 'falling',
                'severity' => abs($lost) >= 15 ? 3 : 2,
                'title' => $title . ' está perdiendo clics',
                'detail' => $posMove < 0
                    ? 'Bajó ' . abs($posMove) . ' puestos frente a los 28 días previos.'
                    : 'Menos clics que en los 28 días previos con posición estable.',
                'metric' => ($lost > 0 ? '+' : '') . $lost . ' clics',
                'url' => $url,
            ];
        }

        if ($tone === 'push') {
            $signals[] = [
                'kind' => 'push',
                'severity' => 2,
                'title' => $title . ' pide un empujón',
                'detail' => 'Aparece mucho pero casi nadie entra: reescribe title y meta description.',
                'metric' => 'CTR ' . round(((float) ($now['ctr'] ?? 0)) * 100, 1)
                    . '% · Pos. ' . round((float) ($now['position'] ?? 0), 1),
                'url' => $url,
            ];
        }

        if ((int) ($now['impressions'] ?? 0) === 0 && $indexTone !== 'issue') {
            $signals[] = [
                'kind' => 'zombie',
                'severity' => 1,
                'title' => $title . ' no aparece en búsquedas',
                'detail' => 'Cero impresiones en 28 días: falta contenido, enlaces internos o intención de búsqueda.',
                'metric' => '0 impresiones',
                'url' => $url,
            ];
        }
    }

    usort($signals, static fn ($a, $b) => ($b['severity'] <=> $a['severity']));
    $signals = array_slice($signals, 0, 24);

    foreach ($signals as $i => $signal) {
        $signals[$i]['id'] = $signal['kind'] . '|' . $signal['url'];
    }
    return $signals;
}

/** Inventario: cuántas páginas existen, cuántas rinden y cuántas están indexadas. */
function gscBuildInventory(array $rows): array
{
    $inventory = [
        'total' => count($rows),
        'withData' => 0,
        'noData' => 0,
        'checked' => 0,
        'indexed' => 0,
        'notIndexed' => 0,
        'blocked' => 0,
    ];

    foreach ($rows as $row) {
        if (!empty($row['error'])) {
            $inventory['blocked']++;
            continue;
        }
        if ((int) ($row['current']['impressions'] ?? 0) > 0) {
            $inventory['withData']++;
        } else {
            $inventory['noData']++;
        }
        $tone = (string) ($row['indexStatus']['tone'] ?? '');
        if ($tone === '') {
            continue;
        }
        $inventory['checked']++;
        if ($tone === 'indexed') {
            $inventory['indexed']++;
        } elseif ($tone === 'issue') {
            $inventory['notIndexed']++;
        }
    }

    return $inventory;
}

/** Un archivo de caché por host: refrescar un cliente no invalida a los demás. */
function gscHostCacheFile(string $dataDir, string $host): string
{
    return $dataDir . '/gsc-cache-' . md5($host) . '.json';
}

function gscSanitizeHost(string $value): string
{
    $host = gscHost(preg_match('#^[a-z]+:#i', $value) ? $value : 'https://' . ltrim($value, '/'));
    if ($host === '' || $host === 'monostudio.cl' || !preg_match('#^[a-z0-9.-]+\.[a-z]{2,}$#i', $host)) {
        return '';
    }
    return strtolower($host);
}

/**
 * El config pasó de un único sitio a un mapa por host: un ERS con varios clientes
 * necesita overrides independientes. Migra el formato antiguo al vuelo.
 */
function gscNormalizeConfig(array $config): array
{
    $hosts = [];

    if (is_array($config['hosts'] ?? null)) {
        foreach ($config['hosts'] as $rawHost => $entry) {
            $host = gscSanitizeHost((string) $rawHost);
            if ($host === '' || !is_array($entry)) {
                continue;
            }
            $hosts[$host] = [
                'sitemapUrl' => gscSanitizeSitemapUrl((string) ($entry['sitemapUrl'] ?? '')),
                'pages' => gscSanitizePages($entry['pages'] ?? []),
            ];
        }
        return ['hosts' => $hosts];
    }

    $ensure = static function (array &$hosts, string $host): void {
        if ($host !== '' && !isset($hosts[$host])) {
            $hosts[$host] = ['sitemapUrl' => '', 'pages' => []];
        }
    };

    foreach (gscSanitizePages($config['pages'] ?? []) as $page) {
        $host = gscSanitizeHost((string) $page['url']);
        $ensure($hosts, $host);
        if ($host !== '') {
            $hosts[$host]['pages'][] = $page;
        }
    }

    $legacySitemap = gscSanitizeSitemapUrl((string) ($config['sitemapUrl'] ?? ''));
    if ($legacySitemap !== '') {
        $host = gscSanitizeHost($legacySitemap);
        $ensure($hosts, $host);
        if ($host !== '') {
            $hosts[$host]['sitemapUrl'] = $legacySitemap;
        }
    }

    $ensure($hosts, gscSanitizeHost((string) ($config['siteUrl'] ?? '')));

    return ['hosts' => $hosts];
}

$rawConfig = gscReadJson($configFile, []);
$config = gscNormalizeConfig($rawConfig);
if (!array_key_exists('hosts', $rawConfig) || $rawConfig['hosts'] !== $config['hosts']) {
    gscWriteJson($configFile, $config);
    foreach (glob($dataDir . '/gsc-cache*.json') ?: [] as $stale) {
        @unlink($stale);
    }
}

$serviceAccount = gscLoadServiceAccount($dataDir, $keyFilePreferred);
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($action === '' || $action === 'sites' || $action === 'status')) {
    $sites = [];
    $sitesError = '';
    if ($serviceAccount !== []) {
        try {
            $token = gscAccessToken($serviceAccount, $tokenFile);
            $sites = array_values(array_filter(array_map(
                static fn ($e) => [
                    'property' => (string) ($e['siteUrl'] ?? ''),
                    'host' => gscHost((string) ($e['siteUrl'] ?? '')),
                    'permission' => (string) ($e['permissionLevel'] ?? ''),
                ],
                gscListSites($token)
            ), static fn ($e) => $e['property'] !== '' && $e['host'] !== 'monostudio.cl'));
        } catch (Throwable $e) {
            $sitesError = $e->getMessage();
        }
    }

    gscJson([
        'connected' => $serviceAccount !== [],
        'version' => GSC_VERSION,
        'serviceEmail' => (string) ($serviceAccount['client_email'] ?? ''),
        'hosts' => array_keys($config['hosts']),
        'properties' => $sites,
        'error' => $sitesError,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'config') {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        gscJson(['error' => 'JSON inválido'], 400);
    }

    $host = gscSanitizeHost((string) ($input['host'] ?? ''));
    if ($host === '') {
        gscJson(['error' => 'Indica un dominio válido'], 400);
    }

    $config['hosts'][$host] = [
        'sitemapUrl' => gscSanitizeSitemapUrl((string) ($input['sitemapUrl'] ?? '')),
        'pages' => gscSanitizePages(is_array($input['pages'] ?? null) ? $input['pages'] : []),
    ];

    if (!gscWriteJson($configFile, $config)) {
        gscJson(['error' => 'No se pudo guardar la configuración'], 500);
    }

    @unlink(gscHostCacheFile($dataDir, $host));
    gscJson(['ok' => true, 'host' => $host] + $config['hosts'][$host]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($action === 'site' || $action === 'query')) {
    try {
        if ($serviceAccount === []) {
            gscJson([
                'error' => 'No hay cuenta de servicio. Sube el JSON a ers/data/gsc-service-account.json y agrega su email como usuario en Search Console.',
            ], 400);
        }

        @set_time_limit(GSC_TIME_BUDGET + 30);
        $deadline = microtime(true) + GSC_TIME_BUDGET;

        $host = gscSanitizeHost((string) ($_GET['host'] ?? ''));
        if ($host === '' && $config['hosts'] !== []) {
            $host = (string) array_key_first($config['hosts']);
        }
        if ($host === '') {
            gscJson(['error' => 'Indica el dominio del cliente que quieres analizar'], 400);
        }

        $override = is_array($config['hosts'][$host] ?? null) ? $config['hosts'][$host] : [];
        $sitemapUrl = gscSanitizeSitemapUrl((string) ($override['sitemapUrl'] ?? ''));
        $manualPages = gscSanitizePages($override['pages'] ?? []);
        $fallbackSite = 'sc-domain:' . $host;
        $seedUrl = 'https://' . $host . '/';

        $hostCacheFile = gscHostCacheFile($dataDir, $host);
        $pageSig = md5(json_encode([$host, $sitemapUrl, $manualPages, GSC_VERSION], JSON_UNESCAPED_SLASHES));
        $cached = gscReadJson($hostCacheFile, []);
        $fresh = (string) ($_GET['fresh'] ?? '') === '1';
        if (
            !$fresh
            && isset($cached['fetchedAt'])
            && empty($cached['error'])
            && (time() - (int) $cached['fetchedAt']) < GSC_CACHE_TTL
            && ($cached['pageSig'] ?? '') === $pageSig
        ) {
            $cached['cached'] = true;
            gscJson($cached);
        }

        $token = gscAccessToken($serviceAccount, $tokenFile);
        $sites = [];
        try {
            $sites = gscListSites($token);
        } catch (Throwable $e) {
            // Seguimos: gscResolveProperty prueba candidatos aunque list sites falle.
        }

        $primary = gscResolveProperty($token, $seedUrl, $sites);
        $primaryProperty = (string) ($primary['property'] ?? '');
        $sitemapItems = [];
        $sitemapApiError = '';
        if ($primaryProperty !== '') {
            try {
                $sitemapItems = gscListSitemaps($token, $primaryProperty);
            } catch (Throwable $e) {
                $sitemapApiError = $e->getMessage();
            }
        }

        // El sitemap declarado en GSC es más fiable que adivinar /sitemap.xml.
        if ($sitemapUrl === '') {
            foreach ($sitemapItems as $item) {
                $candidate = gscSanitizeSitemapUrl((string) ($item['path'] ?? ''));
                if ($candidate !== '' && gscHost($candidate) === $host) {
                    $sitemapUrl = $candidate;
                    break;
                }
            }
        }

        $sitemapDiscovery = gscDiscoverPagesFromSitemap(
            gscSitemapCandidates($fallbackSite, $manualPages, $sitemapUrl),
            $deadline
        );
        $pages = gscMergePages($manualPages, $sitemapDiscovery['pages']);
        if ($pages === []) {
            $pages = [['url' => $seedUrl, 'label' => 'Home']];
        }

        $resolvedByHost = [$host => $primary];

        $end = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-3 days');
        $start = $end->modify('-27 days');
        $prevEnd = $start->modify('-1 day');
        $prevStart = $prevEnd->modify('-27 days');
        $trendStart = $end->modify('-' . (GSC_TREND_DAYS - 1) . ' days');

        $startIso = $start->format('Y-m-d');
        $endIso = $end->format('Y-m-d');
        $prevStartIso = $prevStart->format('Y-m-d');
        $prevEndIso = $prevEnd->format('Y-m-d');
        $trendStartIso = $trendStart->format('Y-m-d');

        $groups = [];
        $unmatched = [];
        foreach ($pages as $page) {
            $host = gscHost($page['url']);
            if (!array_key_exists($host, $resolvedByHost)) {
                $resolvedByHost[$host] = gscResolveProperty($token, $page['url'], $sites);
            }
            $resolved = $resolvedByHost[$host];
            $property = (string) ($resolved['property'] ?? '');
            if ($property === '') {
                $unmatched[] = [
                    'page' => $page,
                    'error' => (string) ($resolved['error'] ?? 'Sin acceso'),
                ];
                continue;
            }
            $groups[$property][] = $page;
        }

        $nowMap = [];
        $prevMap = [];
        $totals = ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
        $totalsBefore = ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
        $propertyErrors = [];
        $weightNow = 0;
        $weightPrev = 0;
        $dailyBucket = [];

        // Las cinco consultas de una propiedad son independientes: van en paralelo.
        foreach ($groups as $property => $groupPages) {
            $res = gscMultiFetch([
                'current' => gscAnalyticsJob($token, $property, $startIso, $endIso, ['dimensions' => ['page']]),
                'previous' => gscAnalyticsJob($token, $property, $prevStartIso, $prevEndIso, ['dimensions' => ['page']]),
                'totalsNow' => gscAnalyticsJob($token, $property, $startIso, $endIso),
                'totalsPrev' => gscAnalyticsJob($token, $property, $prevStartIso, $prevEndIso),
                'daily' => gscAnalyticsJob($token, $property, $trendStartIso, $endIso, [
                    'dimensions' => ['date'],
                    'rowLimit' => GSC_TREND_DAYS + 10,
                ]),
            ]);

            if (!$res['current']['ok']) {
                $propertyErrors[$property] = gscJobError($res['current'], 'Error al consultar Search Console');
                continue;
            }

            $nowMap += gscIndexRows(is_array($res['current']['data']) ? $res['current']['data'] : []);
            if ($res['previous']['ok'] && is_array($res['previous']['data'])) {
                $prevMap += gscIndexRows($res['previous']['data']);
            }

            if ($res['daily']['ok']) {
                $dailyRowsRaw = [];
                foreach ($res['daily']['data']['rows'] ?? [] as $row) {
                    $date = (string) ($row['keys'][0] ?? '');
                    if ($date !== '') {
                        $dailyRowsRaw[] = ['date' => $date] + gscMetricRow($row);
                    }
                }
                gscMergeDailyBucket($dailyBucket, $dailyRowsRaw);
            }

            $tNow = gscMetricRow($res['totalsNow']['data']['rows'][0] ?? null);
            $tPrev = gscMetricRow($res['totalsPrev']['data']['rows'][0] ?? null);
            $totals['clicks'] += $tNow['clicks'];
            $totals['impressions'] += $tNow['impressions'];
            $totalsBefore['clicks'] += $tPrev['clicks'];
            $totalsBefore['impressions'] += $tPrev['impressions'];
            if ($tNow['impressions'] > 0) {
                $totals['position'] += $tNow['position'] * $tNow['impressions'];
                $weightNow += $tNow['impressions'];
            }
            if ($tPrev['impressions'] > 0) {
                $totalsBefore['position'] += $tPrev['position'] * $tPrev['impressions'];
                $weightPrev += $tPrev['impressions'];
            }
        }

        $totals['ctr'] = $totals['impressions'] > 0 ? $totals['clicks'] / $totals['impressions'] : 0;
        $totalsBefore['ctr'] = $totalsBefore['impressions'] > 0 ? $totalsBefore['clicks'] / $totalsBefore['impressions'] : 0;
        $totals['position'] = $weightNow > 0 ? $totals['position'] / $weightNow : 0;
        $totalsBefore['position'] = $weightPrev > 0 ? $totalsBefore['position'] / $weightPrev : 0;

        $pageRows = [];
        $seen = [];
        foreach ($pages as $page) {
            $key = rtrim($page['url'], '/');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $host = gscHost($page['url']);
            $property = (string) ($resolvedByHost[$host]['property'] ?? '');
            $error = null;
            foreach ($unmatched as $miss) {
                if ($miss['page']['url'] === $page['url']) {
                    $error = $miss['error'];
                    break;
                }
            }
            if ($error === null && $property !== '' && isset($propertyErrors[$property])) {
                $error = $propertyErrors[$property];
            }
            $now = gscMetricRow($nowMap[$page['url']] ?? $nowMap[$key] ?? null);
            $prev = gscMetricRow($prevMap[$page['url']] ?? $prevMap[$key] ?? null);
            $delta = gscDelta($now, $prev);
            $pageRows[] = [
                'url' => $page['url'],
                'label' => $page['label'] !== '' ? $page['label'] : $page['url'],
                'property' => $property,
                'error' => $error,
                'current' => $now,
                'previous' => $prev,
                'delta' => $delta,
                'queries' => [],
                'indexStatus' => null,
                'thermometer' => gscThermometer($now, $delta, (float) $totals['ctr']),
            ];
        }

        usort($pageRows, static fn ($a, $b) => (($b['current']['impressions'] ?? 0) <=> ($a['current']['impressions'] ?? 0)) ?: (($b['current']['clicks'] ?? 0) <=> ($a['current']['clicks'] ?? 0)));

        // Enriquecimiento opcional: si el presupuesto se agota, devolvemos lo básico.
        $partial = false;
        $enrichable = [];
        foreach ($pageRows as $idx => $row) {
            if (($row['error'] ?? null) === null && ($row['property'] ?? '') !== '') {
                $enrichable[] = $idx;
            }
        }

        if (gscTimeLeft($deadline) > 8) {
            $queryJobs = [];
            foreach (array_slice($enrichable, 0, GSC_MAX_QUERY_PROBES) as $idx) {
                $queryJobs[$idx] = gscAnalyticsJob(
                    $token,
                    (string) $pageRows[$idx]['property'],
                    $startIso,
                    $endIso,
                    [
                        'dimensions' => ['query'],
                        'rowLimit' => 5,
                        'dimensionFilterGroups' => [[
                            'filters' => [[
                                'dimension' => 'page',
                                'operator' => 'equals',
                                'expression' => (string) $pageRows[$idx]['url'],
                            ]],
                        ]],
                    ]
                );
            }

            foreach (gscMultiFetch($queryJobs) as $idx => $res) {
                if (!$res['ok']) {
                    continue;
                }
                $queries = [];
                foreach ($res['data']['rows'] ?? [] as $row) {
                    $query = trim((string) ($row['keys'][0] ?? ''));
                    if ($query === '') {
                        continue;
                    }
                    $queries[] = [
                        'query' => $query,
                        'clicks' => (int) round((float) ($row['clicks'] ?? 0)),
                        'impressions' => (int) round((float) ($row['impressions'] ?? 0)),
                    ];
                }
                $pageRows[$idx]['queries'] = $queries;
            }
        } else {
            $partial = true;
        }

        // La inspección de URL es la llamada más lenta de Google: siempre al final.
        if (gscTimeLeft($deadline) > 10) {
            $inspectJobs = [];
            foreach (array_slice($enrichable, 0, GSC_MAX_URL_INSPECTIONS) as $idx) {
                $inspectJobs[$idx] = gscInspectJob(
                    $token,
                    (string) $pageRows[$idx]['property'],
                    (string) $pageRows[$idx]['url']
                );
            }

            foreach (gscMultiFetch($inspectJobs) as $idx => $res) {
                if ($res['ok']) {
                    $index = $res['data']['inspectionResult']['indexStatusResult'] ?? [];
                    $pageRows[$idx]['indexStatus'] = gscIndexLabel(is_array($index) ? $index : []);
                    continue;
                }
                $pageRows[$idx]['indexStatus'] = [
                    'label' => 'Sin inspección',
                    'tone' => 'neutral',
                    'coverage' => gscJobError($res, 'No se pudo inspeccionar la URL'),
                    'lastCrawl' => '',
                ];
            }
        } else {
            $partial = true;
        }

        ksort($dailyBucket);
        $dailyRows = array_values(array_map(static function (array $row): array {
            if (($row['impressions'] ?? 0) > 0) {
                $row['ctr'] = $row['clicks'] / $row['impressions'];
                $row['position'] = $row['position'] / $row['impressions'];
            } else {
                $row['ctr'] = 0;
                $row['position'] = 0;
            }
            return $row;
        }, $dailyBucket));

        $diagnostics = [
            'property' => $primaryProperty,
            'sitemapSource' => $sitemapDiscovery['source'] ?? '',
            'sitemapUrl' => $sitemapUrl,
            'sitemapError' => $sitemapDiscovery['error'] ?? '',
            'sitemapApiError' => $sitemapApiError,
            'sitemapItems' => array_map(static fn ($item) => [
                'path' => (string) ($item['path'] ?? ''),
                'lastSubmitted' => (string) ($item['lastSubmitted'] ?? ''),
                'isPending' => (bool) ($item['isPending'] ?? false),
                'warnings' => (int) ($item['warnings'] ?? 0),
                'errors' => (int) ($item['errors'] ?? 0),
            ], $sitemapItems),
            'properties' => array_values(array_map(static fn ($e) => [
                'property' => (string) ($e['siteUrl'] ?? ''),
                'permission' => (string) ($e['permissionLevel'] ?? ''),
            ], $sites)),
            'fromSitemap' => count($sitemapDiscovery['pages'] ?? []),
            'fromManual' => count($manualPages),
        ];

        // Sin propiedad accesible no hay métricas: devolvemos el motivo, no una tabla vacía.
        if ($groups === [] && $unmatched !== []) {
            gscJson([
                'connected' => true,
                'host' => $host,
                'error' => $unmatched[0]['error'],
                'diagnostics' => $diagnostics,
                'pages' => [],
                'signals' => [],
                'inventory' => gscBuildInventory([]),
            ], 200);
        }

        $payload = [
            'connected' => true,
            'cached' => false,
            'fetchedAt' => time(),
            'pageSig' => $pageSig,
            'host' => $host,
            'version' => GSC_VERSION,
            'partial' => $partial,
            'elapsed' => round(GSC_TIME_BUDGET - gscTimeLeft($deadline), 1),
            'range' => ['start' => $startIso, 'end' => $endIso],
            'totals' => $totals,
            'totalsDelta' => gscDelta($totals, $totalsBefore),
            'daily' => array_map(static fn ($row) => [
                'date' => $row['date'],
                'clicks' => (int) $row['clicks'],
                'impressions' => (int) $row['impressions'],
            ], array_slice($dailyRows, -56)),
            'trend' => [
                'weekdays' => gscAggregateWeekdays($dailyRows),
                'months' => gscAggregateMonths($dailyRows),
            ],
            'inventory' => gscBuildInventory($pageRows),
            'signals' => gscBuildSignals($pageRows),
            'diagnostics' => $diagnostics,
            'pages' => $pageRows,
        ];
        gscWriteJson($hostCacheFile, $payload);
        gscJson($payload);
    } catch (Throwable $e) {
        gscJson(['error' => $e->getMessage()], 500);
    }
}

gscJson(['error' => 'Método no permitido'], 405);
