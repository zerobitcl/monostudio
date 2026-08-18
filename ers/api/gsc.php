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

const GSC_SCOPE = 'https://www.googleapis.com/auth/webmasters';
const GSC_CACHE_TTL = 900;
const GSC_MAX_MANUAL_PAGES = 40;
const GSC_MAX_SITEMAP_URLS = 120;
const GSC_MAX_QUERY_PROBES = 12;
const GSC_MAX_URL_INSPECTIONS = 18;
const GSC_TREND_DAYS = 90;

/** Sube al cambiar la lógica de resolución de propiedad; sirve para saber qué versión está viva en el server. */
const GSC_VERSION = '2026.08.18-premium-layer';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
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

function gscSanitizeSiteUrl(string $siteUrl): string
{
    $siteUrl = trim($siteUrl);
    if (preg_match('#^sc-domain:[a-z0-9.-]+$#i', $siteUrl)) {
        return gscIsAgencyHost($siteUrl) ? '' : strtolower($siteUrl);
    }
    if (preg_match('#^https?://#i', $siteUrl)) {
        $host = gscHost($siteUrl);
        if ($host === '' || $host === 'monostudio.cl') {
            return '';
        }
        // Prefijo URL y propiedad de dominio son cosas distintas en GSC.
        return 'sc-domain:' . $host;
    }
    return '';
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

function gscMatchProperty(string $pageUrl, array $sites): string
{
    $host = gscHost($pageUrl);
    if ($host === '') {
        return '';
    }

    foreach ($sites as $entry) {
        $site = (string) ($entry['siteUrl'] ?? '');
        if (stripos($site, 'sc-domain:') === 0) {
            $domain = strtolower(substr($site, strlen('sc-domain:')));
            if ($domain === $host) {
                return $site;
            }
        }
    }

    $best = '';
    $bestLen = 0;
    foreach ($sites as $entry) {
        $site = (string) ($entry['siteUrl'] ?? '');
        if ($site === '' || stripos($site, 'sc-domain:') === 0) {
            continue;
        }
        $prefix = rtrim($site, '/');
        if (stripos($pageUrl, $prefix) === 0 && strlen($prefix) > $bestLen) {
            $best = $site;
            $bestLen = strlen($prefix);
        }
    }

    return $best;
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

function gscDiscoverPagesFromSitemap(array $candidates): array
{
    $visited = [];
    $queue = $candidates;
    $pages = [];
    $source = '';
    $lastError = '';

    while ($queue !== [] && count($pages) < GSC_MAX_SITEMAP_URLS && count($visited) < 18) {
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

function gscTopQueriesForPage(string $token, string $siteUrl, string $pageUrl, string $start, string $end): array
{
    $payload = gscQueryAnalytics($token, $siteUrl, $start, $end, [
        'dimensions' => ['query'],
        'rowLimit' => 5,
        'dimensionFilterGroups' => [[
            'filters' => [[
                'dimension' => 'page',
                'operator' => 'equals',
                'expression' => $pageUrl,
            ]],
        ]],
    ]);

    $out = [];
    foreach ($payload['rows'] ?? [] as $row) {
        $query = trim((string) (($row['keys'][0] ?? '')));
        if ($query === '') {
            continue;
        }
        $out[] = [
            'query' => $query,
            'clicks' => (int) round((float) ($row['clicks'] ?? 0)),
            'impressions' => (int) round((float) ($row['impressions'] ?? 0)),
        ];
    }
    return $out;
}

function gscDailyRows(string $token, string $siteUrl, string $start, string $end): array
{
    $payload = gscQueryAnalytics($token, $siteUrl, $start, $end, [
        'dimensions' => ['date'],
        'rowLimit' => GSC_TREND_DAYS + 10,
    ]);

    $rows = [];
    foreach ($payload['rows'] ?? [] as $row) {
        $date = (string) (($row['keys'][0] ?? ''));
        if ($date === '') {
            continue;
        }
        $rows[] = ['date' => $date] + gscMetricRow($row);
    }
    return $rows;
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

function gscInspectUrl(string $token, string $property, string $url): array
{
    $res = gscHttp('POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
        'inspectionUrl' => $url,
        'siteUrl' => $property,
        'languageCode' => 'es-CL',
    ], ['Authorization: Bearer ' . $token]);

    if (!$res['ok']) {
        $msg = $res['data']['error']['message'] ?? $res['error'] ?? 'No se pudo inspeccionar la URL';
        throw new RuntimeException((string) $msg);
    }

    $index = $res['data']['inspectionResult']['indexStatusResult'] ?? [];
    return is_array($index) ? $index : [];
}

function gscIndexLabel(array $status): array
{
    $verdict = strtoupper((string) ($status['verdict'] ?? ''));
    $coverage = (string) ($status['coverageState'] ?? '');
    $label = $coverage !== '' ? $coverage : 'Sin inspeccion';
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
    $label = 'Fria';
    $tone = 'neutral';

    if ($now['impressions'] >= 80 && $now['position'] > 0 && $now['position'] <= 15 && $now['ctr'] < max($siteCtr * 0.7, 0.012)) {
        $label = 'Pide empujon';
        $tone = 'push';
    } elseif ($delta['clicks'] <= -5 || ($delta['position'] ?? 0) < -1.5) {
        $label = 'Cayendo';
        $tone = 'down';
    } elseif ($now['clicks'] > 0 || $now['impressions'] >= 40) {
        $label = 'Activa';
        $tone = 'up';
    }

    return ['label' => $label, 'tone' => $tone];
}

function gscBuildInsights(array $rows): array
{
    $opportunities = array_values(array_filter($rows, static fn ($row) => ($row['thermometer']['tone'] ?? '') === 'push'));
    usort($opportunities, static fn ($a, $b) => ($b['current']['impressions'] <=> $a['current']['impressions']));
    $decliners = array_values(array_filter($rows, static fn ($row) => ($row['thermometer']['tone'] ?? '') === 'down'));
    usort($decliners, static fn ($a, $b) => ($a['delta']['clicks'] <=> $b['delta']['clicks']));

    $items = [];
    foreach (array_slice($opportunities, 0, 3) as $row) {
        $items[] = [
            'title' => (string) $row['label'],
            'detail' => 'Muchas impresiones, CTR bajo y posicion rescatable.',
            'meta' => 'CTR ' . round(((float) ($row['current']['ctr'] ?? 0)) * 100, 1) . '% · Pos. ' . round((float) ($row['current']['position'] ?? 0), 1),
        ];
    }
    foreach (array_slice($decliners, 0, 2) as $row) {
        $items[] = [
            'title' => (string) $row['label'],
            'detail' => 'Viene cayendo frente al periodo anterior.',
            'meta' => (string) ($row['delta']['clicks'] ?? 0) . ' clics · Pos. ' . round((float) ($row['current']['position'] ?? 0), 1),
        ];
    }
    return array_slice($items, 0, 5);
}

$config = gscReadJson($configFile, ['siteUrl' => '', 'sitemapUrl' => '', 'pages' => []]);
$cleaned = [
    'siteUrl' => gscSanitizeSiteUrl((string) ($config['siteUrl'] ?? '')),
    'sitemapUrl' => gscSanitizeSitemapUrl((string) ($config['sitemapUrl'] ?? '')),
    'pages' => gscSanitizePages($config['pages'] ?? []),
];
if (
    $cleaned['siteUrl'] !== (string) ($config['siteUrl'] ?? '')
    || $cleaned['sitemapUrl'] !== (string) ($config['sitemapUrl'] ?? '')
    || $cleaned['pages'] !== ($config['pages'] ?? [])
) {
    gscWriteJson($configFile, $cleaned);
    @unlink($cacheFile);
}
$config = $cleaned;
$serviceAccount = gscLoadServiceAccount($dataDir, $keyFilePreferred);
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($action === '' || $action === 'status')) {
    $sites = [];
    $sitesError = '';
    if ($serviceAccount !== []) {
        try {
            $token = gscAccessToken($serviceAccount, $tokenFile);
            $sites = array_values(array_filter(array_map(
                static fn ($e) => [
                    'siteUrl' => (string) ($e['siteUrl'] ?? ''),
                    'permission' => (string) ($e['permissionLevel'] ?? ''),
                ],
                gscListSites($token)
            ), static fn ($e) => $e['siteUrl'] !== ''));
        } catch (Throwable $e) {
            $sitesError = $e->getMessage();
        }
    }
    gscJson([
        'connected' => $serviceAccount !== [],
        'version' => GSC_VERSION,
        'auth' => 'service_account',
        'serviceEmail' => (string) ($serviceAccount['client_email'] ?? ''),
        'keyFile' => (string) ($serviceAccount['_path'] ?? 'gsc-service-account.json'),
        'siteUrl' => (string) ($config['siteUrl'] ?? ''),
        'sitemapUrl' => (string) ($config['sitemapUrl'] ?? ''),
        'pages' => gscSanitizePages($config['pages'] ?? []),
        'sites' => array_column($sites, 'siteUrl'),
        'siteDetails' => $sites,
        'sitesError' => $sitesError,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'config') {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        gscJson(['error' => 'JSON inválido'], 400);
    }

    $siteUrl = gscSanitizeSiteUrl((string) ($input['siteUrl'] ?? ''));
    $sitemapUrl = gscSanitizeSitemapUrl((string) ($input['sitemapUrl'] ?? ''));
    $pages = gscSanitizePages(is_array($input['pages'] ?? null) ? $input['pages'] : []);
    if ($siteUrl === '' && $sitemapUrl === '' && $pages === []) {
        gscJson(['error' => 'Indica una propiedad, un sitemap o al menos una URL manual'], 400);
    }
    $config = ['siteUrl' => $siteUrl, 'sitemapUrl' => $sitemapUrl, 'pages' => $pages];
    if (!gscWriteJson($configFile, $config)) {
        gscJson(['error' => 'No se pudo guardar la configuración'], 500);
    }

    @unlink($cacheFile);
    gscJson(['ok' => true, 'pages' => $pages, 'siteUrl' => $siteUrl, 'sitemapUrl' => $sitemapUrl]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'query') {
    try {
        if ($serviceAccount === []) {
            gscJson([
                'error' => 'No hay cuenta de servicio. Sube el JSON a ers/data/gsc-service-account.json y agrega su email como usuario en Search Console.',
            ], 400);
        }

        $fallbackSite = gscSanitizeSiteUrl((string) ($config['siteUrl'] ?? ''));
        $sitemapUrl = gscSanitizeSitemapUrl((string) ($config['sitemapUrl'] ?? ''));
        $manualPages = gscSanitizePages($config['pages'] ?? []);
        if ($fallbackSite === '' && $sitemapUrl === '' && $manualPages === []) {
            gscJson(['error' => 'Configura una propiedad, un sitemap o una URL manual'], 400);
        }

        $pageSig = md5(json_encode([$fallbackSite, $sitemapUrl, $manualPages, GSC_VERSION], JSON_UNESCAPED_SLASHES));
        $cached = gscReadJson($cacheFile, []);
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

        $seedUrl = gscSiteHomeUrl($fallbackSite, $manualPages, $sitemapUrl);
        if ($seedUrl === '') {
            gscJson(['error' => 'No pude inferir el sitio base para consultar Search Console'], 400);
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

        $sitemapDiscovery = gscDiscoverPagesFromSitemap(gscSitemapCandidates($fallbackSite, $manualPages, $sitemapUrl));
        $pages = gscMergePages($manualPages, $sitemapDiscovery['pages']);
        if ($pages === []) {
            $pages = [['url' => $seedUrl, 'label' => 'Home']];
        }

        $resolvedByHost = [];

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

        foreach ($groups as $property => $groupPages) {
            try {
                $current = gscQueryAnalytics($token, $property, $startIso, $endIso, ['dimensions' => ['page']]);
                $previous = gscQueryAnalytics($token, $property, $prevStartIso, $prevEndIso, ['dimensions' => ['page']]);
                $totalsNow = gscQueryAnalytics($token, $property, $startIso, $endIso);
                $totalsPrev = gscQueryAnalytics($token, $property, $prevStartIso, $prevEndIso);
                $daily = gscDailyRows($token, $property, $trendStartIso, $endIso);
                $nowMap += gscIndexRows($current);
                $prevMap += gscIndexRows($previous);
                gscMergeDailyBucket($dailyBucket, $daily);
                $tNow = gscMetricRow($totalsNow['rows'][0] ?? null);
                $tPrev = gscMetricRow($totalsPrev['rows'][0] ?? null);
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
            } catch (Throwable $e) {
                $propertyErrors[$property] = $e->getMessage();
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

        $queryTargets = [];
        foreach ($pageRows as $idx => $row) {
            if (($row['error'] ?? null) === null && count($queryTargets) < GSC_MAX_QUERY_PROBES) {
                $queryTargets[] = $idx;
            }
        }
        foreach ($queryTargets as $idx) {
            try {
                $property = (string) ($pageRows[$idx]['property'] ?? '');
                if ($property === '') {
                    continue;
                }
                $pageRows[$idx]['queries'] = gscTopQueriesForPage($token, $property, (string) $pageRows[$idx]['url'], $startIso, $endIso);
            } catch (Throwable $e) {
                // Las consultas enriquecen; no bloquean el termometro.
            }
        }

        $inspectionTargets = [];
        foreach ($pageRows as $idx => $row) {
            if (($row['error'] ?? null) === null && count($inspectionTargets) < GSC_MAX_URL_INSPECTIONS) {
                $inspectionTargets[] = $idx;
            }
        }
        foreach ($inspectionTargets as $idx) {
            try {
                $property = (string) ($pageRows[$idx]['property'] ?? '');
                if ($property === '') {
                    continue;
                }
                $pageRows[$idx]['indexStatus'] = gscIndexLabel(gscInspectUrl($token, $property, (string) $pageRows[$idx]['url']));
            } catch (Throwable $e) {
                $pageRows[$idx]['indexStatus'] = [
                    'label' => 'Sin inspeccion',
                    'tone' => 'neutral',
                    'coverage' => $e->getMessage(),
                    'lastCrawl' => '',
                ];
            }
        }

        $indexSummary = ['checked' => 0, 'indexed' => 0, 'issues' => 0];
        foreach ($pageRows as $row) {
            if (!is_array($row['indexStatus'])) {
                continue;
            }
            $indexSummary['checked']++;
            if (($row['indexStatus']['tone'] ?? '') === 'indexed') {
                $indexSummary['indexed']++;
            } elseif (($row['indexStatus']['tone'] ?? '') === 'issue') {
                $indexSummary['issues']++;
            }
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

        if ($groups === [] && $unmatched !== []) {
            gscJson([
                'connected' => true,
                'error' => $unmatched[0]['error'],
                'siteUrl' => $fallbackSite,
                'sitemapUrl' => $sitemapUrl,
                'sites' => array_values(array_map(static fn ($e) => $e['siteUrl'] ?? '', $sites)),
                'siteDetails' => array_values(array_map(static fn ($e) => [
                    'siteUrl' => (string) ($e['siteUrl'] ?? ''),
                    'permission' => (string) ($e['permissionLevel'] ?? ''),
                ], $sites)),
                'discovery' => [
                    'source' => $sitemapDiscovery['source'] ?? '',
                    'detectedCount' => count($sitemapDiscovery['pages'] ?? []),
                    'manualCount' => count($manualPages),
                    'combinedCount' => count($pages),
                    'indexSummary' => $indexSummary,
                    'error' => $sitemapDiscovery['error'] ?? '',
                ],
                'sitemaps' => [
                    'items' => [],
                    'error' => $sitemapApiError,
                ],
                'pages' => array_map(static fn ($miss) => [
                    'url' => $miss['page']['url'],
                    'label' => $miss['page']['label'] ?: $miss['page']['url'],
                    'error' => $miss['error'],
                    'current' => gscMetricRow(null),
                    'previous' => gscMetricRow(null),
                    'delta' => gscDelta(gscMetricRow(null), gscMetricRow(null)),
                    'queries' => [],
                    'indexStatus' => null,
                    'thermometer' => ['label' => 'Sin acceso', 'tone' => 'down'],
                ], $unmatched),
            ], 200);
        }

        $payload = [
            'connected' => true,
            'cached' => false,
            'fetchedAt' => time(),
            'pageSig' => $pageSig,
            'siteUrl' => $fallbackSite,
            'sitemapUrl' => $sitemapUrl,
            'sites' => array_values(array_map(static fn ($e) => $e['siteUrl'] ?? '', $sites)),
            'siteDetails' => array_values(array_map(static fn ($e) => [
                'siteUrl' => (string) ($e['siteUrl'] ?? ''),
                'permission' => (string) ($e['permissionLevel'] ?? ''),
            ], $sites)),
            'range' => [
                'start' => $startIso,
                'end' => $endIso,
                'prevStart' => $prevStartIso,
                'prevEnd' => $prevEndIso,
            ],
            'totals' => $totals,
            'totalsDelta' => gscDelta($totals, $totalsBefore),
            'discovery' => [
                'source' => $sitemapDiscovery['source'] ?? '',
                'detectedCount' => count($sitemapDiscovery['pages'] ?? []),
                'manualCount' => count($manualPages),
                'combinedCount' => count($pages),
                'indexSummary' => $indexSummary,
                'error' => $sitemapDiscovery['error'] ?? '',
            ],
            'sitemaps' => [
                'items' => array_map(static fn ($item) => [
                    'path' => (string) ($item['path'] ?? ''),
                    'lastSubmitted' => (string) ($item['lastSubmitted'] ?? ''),
                    'isPending' => (bool) ($item['isPending'] ?? false),
                    'warnings' => (int) ($item['warnings'] ?? 0),
                    'errors' => (int) ($item['errors'] ?? 0),
                    'isSitemapsIndex' => (bool) ($item['isSitemapsIndex'] ?? false),
                ], $sitemapItems),
                'error' => $sitemapApiError,
            ],
            'trend' => [
                'weekdays' => gscAggregateWeekdays($dailyRows),
                'months' => gscAggregateMonths($dailyRows),
            ],
            'insights' => gscBuildInsights($pageRows),
            'pages' => $pageRows,
        ];
        gscWriteJson($cacheFile, $payload);
        gscJson($payload);
    } catch (Throwable $e) {
        gscJson(['error' => $e->getMessage()], 500);
    }
}

gscJson(['error' => 'Método no permitido'], 405);
