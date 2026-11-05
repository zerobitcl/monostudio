<?php
/**
 * Dossier único por sitio. Search Console, Analytics, PageSpeed, HTML,
 * repo y tareas cerradas salen de aquí: el prompt de Cursor y el informe
 * cliente leen el mismo paquete, no cada uno su caché.
 */

declare(strict_types=1);

if (!defined('ERS_LIBRARY')) {
    define('ERS_LIBRARY', true);
}

require_once __DIR__ . '/gsc.php';
require_once __DIR__ . '/lib.php';

const DOSSIER_VERSION = '2026.11.05-integral';
const DOSSIER_GA_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
const DOSSIER_GA_TTL = 21600;
const DOSSIER_PSI_TTL = 604800;
const DOSSIER_DIR_TTL = 43200;
const DOSSIER_PSI_LIMIT = 5;

function dossierCacheFile(string $dataDir, string $host): string
{
    return $dataDir . '/dossier-' . md5($host) . '.json';
}

function dossierDomFile(string $dataDir, string $host): string
{
    return $dataDir . '/seo-dom-' . md5($host) . '.json';
}

function dossierRepoFile(string $dataDir, string $host): string
{
    return $dataDir . '/seo-repo-' . md5($host) . '.json';
}

function dossierGaFile(string $dataDir, string $host): string
{
    return $dataDir . '/ga4-cache-' . md5($host) . '.json';
}

function dossierPsiFile(string $dataDir, string $host): string
{
    return $dataDir . '/psi-cache-' . md5($host) . '.json';
}

function dossierNormPath(string $value): string
{
    $value = strtolower(trim($value));
    $value = explode('?', $value, 2)[0];
    $value = explode('#', $value, 2)[0];
    if ($value === '' || $value === '/' || $value === '(not set)') {
        return '/';
    }
    if (!str_starts_with($value, '/')) {
        $path = parse_url($value, PHP_URL_PATH);
        $value = is_string($path) && $path !== '' ? $path : '/';
    }
    $value = rawurldecode($value);
    return rtrim($value, '/') ?: '/';
}

function dossierPublicError(string $msg): string
{
    $msg = preg_replace('/key=[^&\s]+/i', 'key=***', $msg) ?? $msg;
    return ersClip($msg, 320);
}

function dossierApiMessage(array $res, string $fallback): string
{
    $data = is_array($res['data'] ?? null) ? $res['data'] : [];
    $msg = $data['error']['message'] ?? $data['Error'] ?? $data['error_description'] ?? $res['error'] ?? $fallback;
    return dossierPublicError((string) $msg);
}

function dossierSecret(string $dataDir, string $file): string
{
    $data = gscReadJson($dataDir . '/' . $file, []);
    foreach (['apiKey', 'api_key', 'key', 'token'] as $key) {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value !== '' && !str_starts_with($value, 'PEGAR') && !str_starts_with($value, 'YOUR')) {
            return $value;
        }
    }
    return '';
}

function dossierWindows(array $gsc): array
{
    $end = (string) ($gsc['range']['end'] ?? '');
    $start = (string) ($gsc['range']['start'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        $endDt = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-3 days');
        $startDt = $endDt->modify('-27 days');
        $end = $endDt->format('Y-m-d');
        $start = $startDt->format('Y-m-d');
    }
    $prevEnd = (new DateTimeImmutable($start))->modify('-1 day');
    $prevStart = $prevEnd->modify('-27 days');
    return [
        'start' => $start,
        'end' => $end,
        'prevStart' => $prevStart->format('Y-m-d'),
        'prevEnd' => $prevEnd->format('Y-m-d'),
    ];
}

function dossierPeriod28(array $gsc): array
{
    foreach ($gsc['periods'] ?? [] as $period) {
        if (is_array($period) && ($period['id'] ?? '') === '28d') {
            return $period;
        }
    }
    return [
        'totals' => is_array($gsc['totals'] ?? null) ? $gsc['totals'] : [],
        'delta' => is_array($gsc['totalsDelta'] ?? null) ? $gsc['totalsDelta'] : [],
        'previous' => [],
        'range' => is_array($gsc['range'] ?? null) ? $gsc['range'] : [],
    ];
}

function dossierHostPrefs(string $dataDir, string $host): array
{
    $config = gscNormalizeConfig(gscReadJson($dataDir . '/gsc.json', []));
    $entry = $config['hosts'][$host] ?? [];
    return is_array($entry) ? gscHostEntry($entry) : gscHostEntry([]);
}

function dossierClient(string $host): array
{
    $found = ['id' => '', 'name' => $host];
    foreach (ersReadStore()['clients'] ?? [] as $client) {
        if (!is_array($client)) {
            continue;
        }
        if (gscHost((string) ($client['siteUrl'] ?? '')) !== $host) {
            continue;
        }
        $found['id'] = (string) ($client['id'] ?? '');
        $name = trim((string) ($client['name'] ?? ''));
        if ($name !== '') {
            $found['name'] = $name;
        }
        break;
    }
    return $found;
}

function dossierTasks(string $host, array $windows): array
{
    $client = dossierClient($host);
    if ($client['id'] === '') {
        return [];
    }
    $tz = new DateTimeZone('America/Santiago');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $windows['start'], $tz);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $windows['end'], $tz);
    if (!$start || !$end) {
        return [];
    }
    $from = $start->getTimestamp();
    $to = $end->setTime(23, 59, 59)->getTimestamp();
    $out = [];
    foreach (ersReadStore()['tasks'] ?? [] as $task) {
        if (!is_array($task)) {
            continue;
        }
        $done = (int) ($task['doneAt'] ?? 0);
        if ($done <= 0 || (string) ($task['clientId'] ?? '') !== $client['id']) {
            continue;
        }
        if ($done < $from || $done > $to) {
            continue;
        }
        $title = trim((string) ($task['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $out[] = [
            'title' => $title,
            'doneAt' => $done,
            'area' => trim((string) ($task['area'] ?? '')),
        ];
    }
    usort($out, static fn ($a, $b) => $b['doneAt'] <=> $a['doneAt']);
    return array_slice($out, 0, 8);
}

function dossierTasksSig(string $host, array $windows): string
{
    $bits = [];
    foreach (dossierTasks($host, $windows) as $task) {
        $bits[] = $task['doneAt'] . '|' . $task['title'];
    }
    return md5(implode("\n", $bits));
}

/* ------------------------------------------------------------
   HTML en vivo. Una sola pasada: el informe ya no scrapea aparte.
   ------------------------------------------------------------ */

function dossierCollectSchemaTypes(mixed $node, array &$out, int $depth = 0): void
{
    if ($depth > 8 || !is_array($node)) {
        return;
    }
    if (isset($node['@type'])) {
        $types = is_array($node['@type']) ? $node['@type'] : [$node['@type']];
        foreach ($types as $type) {
            if (is_string($type) && $type !== '') {
                $out[$type] = true;
            }
        }
    }
    foreach ($node as $key => $child) {
        if ($key === '@context' || !is_array($child)) {
            continue;
        }
        dossierCollectSchemaTypes($child, $out, $depth + 1);
    }
}

/**
 * Scrape SEO. Los campos viejos (title, h1, meta, canonical) se mantienen
 * para no invalidar a ciegas el caché; h1Count marca la versión nueva.
 */
function dossierFetchDom(string $url): array
{
    $empty = [
        'ok' => false,
        'status' => 0,
        'title' => '',
        'h1' => '',
        'h2' => '',
        'meta' => '',
        'canonical' => '',
        'robots' => '',
        'ogTitle' => '',
        'ogDescription' => '',
        'lang' => '',
        'h1Count' => 0,
        'h2Count' => 0,
        'titleLen' => 0,
        'schemas' => [],
        'images' => 0,
        'imagesMissingAlt' => 0,
        'internalLinks' => 0,
        'wordCount' => 0,
        'hreflang' => false,
        'hasTel' => false,
        'hasWhatsapp' => false,
        'hasForm' => false,
        'hasMailto' => false,
        'error' => '',
        'fetchedAt' => time(),
    ];

    if (!preg_match('#^https?://#i', $url) || gscIsAgencyHost($url)) {
        $empty['error'] = 'URL no válida';
        return $empty;
    }

    $html = '';
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'MonoStudio-SeoAudit/1.0',
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            $empty['status'] = $code;
            $empty['error'] = $err !== '' ? $err : 'sin respuesta';
            return $empty;
        }
        $html = $raw;
    } else {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 6,
                'header' => "User-Agent: MonoStudio-SeoAudit/1.0\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            $empty['error'] = 'sin respuesta';
            return $empty;
        }
        $html = $raw;
        $code = 200;
    }

    if ($code < 200 || $code >= 400) {
        $empty['status'] = $code;
        $empty['error'] = 'HTTP ' . $code;
        return $empty;
    }

    if (!class_exists('DOMDocument')) {
        $empty['status'] = $code;
        $empty['error'] = 'DOMDocument no disponible';
        return $empty;
    }

    if (strlen($html) > 350000) {
        $html = substr($html, 0, 350000);
    }
    $flat = strtolower($html);

    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xpath = new DOMXPath($dom);

    $textOf = static function (string $expr, int $limit = 0) use ($xpath): string {
        $nodes = $xpath->query($expr);
        $parts = [];
        if ($nodes) {
            foreach ($nodes as $i => $node) {
                if ($limit > 0 && $i >= $limit) {
                    break;
                }
                $parts[] = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');
            }
        }
        return gscAscii(implode(' · ', array_filter($parts)));
    };
    $attrOf = static function (string $expr) use ($xpath): string {
        foreach ($xpath->query($expr) ?: [] as $attr) {
            return gscAscii(trim((string) $attr->nodeValue));
        }
        return '';
    };

    $title = $textOf('//title', 1);
    $h1Nodes = $xpath->query('//h1');
    $h2Nodes = $xpath->query('//h2');
    $h1Count = $h1Nodes ? $h1Nodes->length : 0;
    $h2Count = $h2Nodes ? $h2Nodes->length : 0;

    $schemas = [];
    $ld = $xpath->query('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]');
    if ($ld) {
        foreach ($ld as $script) {
            $json = json_decode((string) $script->textContent, true);
            dossierCollectSchemaTypes($json, $schemas);
        }
    }

    $images = 0;
    $missingAlt = 0;
    foreach ($xpath->query('//img') ?: [] as $img) {
        if (!$img instanceof DOMElement) {
            continue;
        }
        $images++;
        if (trim($img->getAttribute('alt')) === '') {
            $missingAlt++;
        }
    }

    $host = gscHost($url);
    $internal = 0;
    $hasTel = str_contains($flat, 'tel:');
    $hasWa = str_contains($flat, 'wa.me') || str_contains($flat, 'api.whatsapp.com') || str_contains($flat, 'whatsapp.com/send');
    $hasMailto = str_contains($flat, 'mailto:');
    foreach ($xpath->query('//a[@href]') ?: [] as $a) {
        if (!$a instanceof DOMElement) {
            continue;
        }
        $href = trim($a->getAttribute('href'));
        $low = strtolower($href);
        if (str_starts_with($low, 'tel:')) {
            $hasTel = true;
            continue;
        }
        if (str_starts_with($low, 'mailto:')) {
            $hasMailto = true;
            continue;
        }
        if ($href === '' || str_starts_with($low, '#') || str_starts_with($low, 'javascript:')) {
            continue;
        }
        $linkHost = gscHost($href);
        if (str_starts_with($href, '/') || $linkHost === '' || $linkHost === $host) {
            $internal++;
        }
    }
    $forms = $xpath->query('//form');
    $hasForm = $forms && $forms->length > 0;

    $hreflang = false;
    foreach ($xpath->query('//link[@hreflang]') ?: [] as $link) {
        if ($link instanceof DOMElement && trim($link->getAttribute('hreflang')) !== '') {
            $hreflang = true;
            break;
        }
    }

    $wordCount = 0;
    foreach ($xpath->query('//script|//style|//noscript') ?: [] as $node) {
        $node->parentNode?->removeChild($node);
    }
    $body = trim(preg_replace('/\s+/u', ' ', $dom->textContent ?? '') ?? '');
    if ($body !== '') {
        $parts = preg_split('/\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($parts) ? count($parts) : 0;
    }

    $lang = '';
    $langAttr = $xpath->query('/html/@lang');
    if ($langAttr && $langAttr->length > 0) {
        $lang = strtolower(trim((string) $langAttr->item(0)?->nodeValue));
    }

    return [
        'ok' => true,
        'status' => $code,
        'title' => ersClip($title, 200),
        'h1' => ersClip($textOf('//h1', 3), 240),
        'h2' => ersClip($textOf('//h2', 8), 500),
        'meta' => ersClip($attrOf('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content'), 320),
        'canonical' => ersClip($attrOf('//link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]/@href'), 500),
        'robots' => ersClip($attrOf('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"]/@content'), 120),
        'ogTitle' => ersClip($attrOf('//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content'), 200),
        'ogDescription' => ersClip($attrOf('//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:description"]/@content'), 320),
        'lang' => ersClip($lang, 16),
        'h1Count' => $h1Count,
        'h2Count' => $h2Count,
        'titleLen' => function_exists('mb_strlen') ? mb_strlen($title) : strlen($title),
        'schemas' => array_keys($schemas),
        'images' => $images,
        'imagesMissingAlt' => $missingAlt,
        'internalLinks' => $internal,
        'wordCount' => $wordCount,
        'hreflang' => $hreflang,
        'hasTel' => $hasTel,
        'hasWhatsapp' => $hasWa,
        'hasForm' => $hasForm,
        'hasMailto' => $hasMailto,
        'error' => '',
        'fetchedAt' => time(),
    ];
}

function dossierDomIndex(string $dataDir, string $host): array
{
    $cache = gscReadJson(dossierDomFile($dataDir, $host), []);
    $pages = [];
    $done = 0;
    foreach ($cache['pages'] ?? [] as $url => $row) {
        if (!is_array($row) || empty($row['ok'])) {
            continue;
        }
        $done++;
        $path = dossierNormPath((string) $url);
        if (!isset($pages[$path])) {
            $pages[$path] = $row;
        }
    }
    $total = 0;
    $site = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    foreach ($site['pages'] ?? [] as $row) {
        $url = trim((string) ($row['url'] ?? ''));
        if ($url !== '' && gscUrlBelongsToHost($url, $host)) {
            $total++;
        }
    }
    return [
        'pages' => $pages,
        'done' => $done,
        'total' => $total,
        'updatedAt' => (int) ($cache['updatedAt'] ?? 0),
    ];
}

/* ------------------------------------------------------------
   Analytics. El lote principal admite 5 requests; eventos y
   dispositivo van en un segundo lote para no perder lo básico.
   ------------------------------------------------------------ */

function dossierGaRows(array $report): array
{
    $dims = array_map(static fn ($h) => (string) ($h['name'] ?? ''), $report['dimensionHeaders'] ?? []);
    $mets = array_map(static fn ($h) => (string) ($h['name'] ?? ''), $report['metricHeaders'] ?? []);
    $out = [];
    foreach ($report['rows'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $item = [];
        foreach ($dims as $i => $name) {
            $item[$name] = (string) ($row['dimensionValues'][$i]['value'] ?? '');
        }
        foreach ($mets as $i => $name) {
            $item[$name] = (float) ($row['metricValues'][$i]['value'] ?? 0);
        }
        $out[] = $item;
    }
    return $out;
}

function dossierRangeRow(array $rows, string $name, int $fallback): array
{
    foreach ($rows as $row) {
        if (($row['dateRange'] ?? '') === $name) {
            return $row;
        }
    }
    return $rows[$fallback] ?? [];
}

function dossierEventKind(string $name): string
{
    $n = preg_replace('/[^a-z0-9]+/', '_', gscAscii($name)) ?? '';
    if (preg_match('/whats|wa_me|wame/', $n)) {
        return 'whatsapp';
    }
    if (preg_match('/phone|call|llamad|telefono|click_tel/', $n)) {
        return 'llamada';
    }
    if (preg_match('/mail|email/', $n)) {
        return 'email';
    }
    if (preg_match('/form|lead|contact|cotiz|submit|conversion/', $n)) {
        return 'formulario';
    }
    return 'otro';
}

function dossierGaDirectory(string $dataDir, array $sa, bool $fresh = false): array
{
    $file = $dataDir . '/ga4-directory.json';
    $cached = gscReadJson($file, []);
    $age = time() - (int) ($cached['fetchedAt'] ?? 0);
    $ttl = ($cached['error'] ?? '') !== '' ? 600 : DOSSIER_DIR_TTL;
    if (!$fresh && isset($cached['fetchedAt']) && is_array($cached['properties'] ?? null) && $age < $ttl) {
        return $cached;
    }
    if ($sa === []) {
        return ['fetchedAt' => 0, 'email' => '', 'error' => 'Falta la cuenta de servicio.', 'properties' => []];
    }

    try {
        $token = gscAccessToken($sa, $dataDir . '/ga4-token.json', DOSSIER_GA_SCOPE);
    } catch (Throwable $e) {
        return ['fetchedAt' => time(), 'email' => (string) ($sa['client_email'] ?? ''), 'error' => dossierPublicError($e->getMessage()), 'properties' => []];
    }

    $auth = ['Authorization: Bearer ' . $token];
    $list = gscHttp('GET', 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200', null, $auth);
    if (!$list['ok']) {
        return [
            'fetchedAt' => time(),
            'email' => (string) ($sa['client_email'] ?? ''),
            'error' => 'Analytics no dejó listar propiedades. Activá la API Admin y Data, y sumá ' . ($sa['client_email'] ?? 'la cuenta de servicio') . ' como Lector. ' . dossierApiMessage($list, ''),
            'properties' => [],
        ];
    }

    $pending = [];
    $meta = [];
    foreach ($list['data']['accountSummaries'] ?? [] as $account) {
        if (!is_array($account)) {
            continue;
        }
        $accountName = (string) ($account['displayName'] ?? '');
        foreach ($account['propertySummaries'] ?? [] as $property) {
            if (!is_array($property)) {
                continue;
            }
            $id = gscGa4Property((string) ($property['property'] ?? ''));
            if ($id === '') {
                continue;
            }
            $key = 'p' . $id;
            $meta[$key] = [
                'id' => $id,
                'name' => (string) ($property['displayName'] ?? $id),
                'account' => $accountName,
                'host' => '',
            ];
            $pending[$key] = [
                'method' => 'GET',
                'url' => 'https://analyticsadmin.googleapis.com/v1beta/properties/' . $id . '/dataStreams',
                'headers' => $auth,
            ];
        }
    }

    foreach (gscMultiFetch($pending) as $key => $res) {
        if (!$res['ok']) {
            continue;
        }
        foreach ($res['data']['dataStreams'] ?? [] as $stream) {
            $uri = (string) ($stream['webStreamData']['defaultUri'] ?? '');
            $host = gscHost($uri);
            if ($host !== '' && ($meta[$key]['host'] ?? '') === '') {
                $meta[$key]['host'] = $host;
            }
        }
    }

    $payload = [
        'fetchedAt' => time(),
        'email' => (string) ($sa['client_email'] ?? ''),
        'error' => '',
        'properties' => array_values($meta),
    ];
    gscWriteJson($file, $payload);
    return $payload;
}

function dossierResolveProperty(string $dataDir, array $sa, string $host, string $configured): array
{
    if ($configured !== '') {
        return ['id' => $configured, 'detected' => false, 'name' => '', 'error' => ''];
    }
    $dir = dossierGaDirectory($dataDir, $sa, false);
    if (($dir['error'] ?? '') !== '' && ($dir['properties'] ?? []) === []) {
        return ['id' => '', 'detected' => false, 'name' => '', 'error' => (string) $dir['error']];
    }
    $matches = array_values(array_filter(
        $dir['properties'] ?? [],
        static fn ($p) => ($p['host'] ?? '') === $host
    ));
    if (count($matches) === 1) {
        return ['id' => (string) $matches[0]['id'], 'detected' => true, 'name' => (string) $matches[0]['name'], 'error' => ''];
    }
    if (count($matches) > 1) {
        return ['id' => '', 'detected' => false, 'name' => '', 'error' => 'Hay más de una propiedad de Analytics para ' . $host . '. Elegí el ID en Diagnóstico.'];
    }
    $email = (string) ($dir['email'] ?? $sa['client_email'] ?? '');
    return [
        'id' => '',
        'detected' => false,
        'name' => '',
        'error' => 'Ninguna propiedad de Analytics apunta a ' . $host . '. Pegá el ID y agregá ' . $email . ' como Lector.',
    ];
}

function dossierGaBatch(string $token, string $propertyId, array $requests): array
{
    $res = gscHttp(
        'POST',
        'https://analyticsdata.googleapis.com/v1beta/properties/' . $propertyId . ':batchRunReports',
        ['requests' => $requests],
        ['Authorization: Bearer ' . $token]
    );
    if (!$res['ok']) {
        throw new RuntimeException(dossierApiMessage($res, 'Analytics no respondió'));
    }
    return is_array($res['data']['reports'] ?? null) ? $res['data']['reports'] : [];
}

function dossierFetchGa(string $dataDir, array $sa, string $host, string $propertyId, array $windows, bool $fresh): array
{
    $file = dossierGaFile($dataDir, $host);
    $cached = gscReadJson($file, []);
    $same = ($cached['property'] ?? '') === $propertyId
        && ($cached['range']['start'] ?? '') === $windows['start']
        && ($cached['range']['end'] ?? '') === $windows['end']
        && ($cached['version'] ?? '') === DOSSIER_VERSION;
    if (!$fresh && $same && empty($cached['error']) && (time() - (int) ($cached['fetchedAt'] ?? 0)) < DOSSIER_GA_TTL) {
        $cached['cached'] = true;
        return $cached;
    }

    $token = gscAccessToken($sa, $dataDir . '/ga4-token.json', DOSSIER_GA_SCOPE);
    $current = ['startDate' => $windows['start'], 'endDate' => $windows['end'], 'name' => 'current'];
    $previous = ['startDate' => $windows['prevStart'], 'endDate' => $windows['prevEnd'], 'name' => 'previous'];

    $run = static function (bool $useKey) use ($token, $propertyId, $current, $previous): array {
        $metric = $useKey ? 'keyEvents' : 'conversions';
        $core = [
            [
                'dateRanges' => [$current, $previous],
                'dimensions' => [['name' => 'dateRange']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'newUsers'],
                    ['name' => 'engagementRate'],
                    ['name' => 'averageSessionDuration'],
                    ['name' => 'screenPageViews'],
                    ['name' => $metric],
                ],
            ],
            [
                'dateRanges' => [$current],
                'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
                'metrics' => [['name' => 'sessions'], ['name' => 'engagedSessions'], ['name' => $metric]],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => 8,
            ],
            [
                'dateRanges' => [$current],
                'dimensions' => [['name' => 'landingPagePlusQueryString']],
                'metrics' => [['name' => 'sessions'], ['name' => 'engagementRate'], ['name' => $metric]],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => 25,
            ],
            [
                'dateRanges' => [$current],
                'dimensions' => [['name' => 'deviceCategory']],
                'metrics' => [['name' => 'sessions']],
                'limit' => 5,
            ],
            [
                'dateRanges' => [$current, $previous],
                'dimensions' => [['name' => 'dateRange']],
                'dimensionFilter' => [
                    'filter' => [
                        'fieldName' => 'sessionDefaultChannelGroup',
                        'stringFilter' => ['matchType' => 'EXACT', 'value' => 'Organic Search'],
                    ],
                ],
                'metrics' => [['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => $metric]],
            ],
        ];
        return dossierGaBatch($token, $propertyId, $core);
    };

    try {
        $reports = $run(true);
        $metric = 'keyEvents';
    } catch (RuntimeException $e) {
        if (!str_contains(strtolower($e->getMessage()), 'keyevent')) {
            throw $e;
        }
        $reports = $run(false);
        $metric = 'conversions';
    }

    $totals = dossierGaRows(is_array($reports[0] ?? null) ? $reports[0] : []);
    $now = dossierRangeRow($totals, 'current', 0);
    $prev = dossierRangeRow($totals, 'previous', 1);
    $channels = dossierGaRows(is_array($reports[1] ?? null) ? $reports[1] : []);
    $landings = dossierGaRows(is_array($reports[2] ?? null) ? $reports[2] : []);
    $devices = dossierGaRows(is_array($reports[3] ?? null) ? $reports[3] : []);
    $organicRows = dossierGaRows(is_array($reports[4] ?? null) ? $reports[4] : []);
    $organic = dossierRangeRow($organicRows, 'current', 0);
    $organicPrev = dossierRangeRow($organicRows, 'previous', 1);

    $events = [];
    $landingDevices = [];
    try {
        $extra = dossierGaBatch($token, $propertyId, [
            [
                'dateRanges' => [$current],
                'dimensions' => [['name' => 'eventName']],
                'metrics' => [['name' => $metric], ['name' => 'eventCount']],
                'metricFilter' => [
                    'filter' => [
                        'fieldName' => $metric,
                        'numericFilter' => ['operation' => 'GREATER_THAN', 'value' => ['int64Value' => '0']],
                    ],
                ],
                'orderBys' => [['metric' => ['metricName' => $metric], 'desc' => true]],
                'limit' => 25,
            ],
            [
                'dateRanges' => [$current],
                'dimensions' => [
                    ['name' => 'landingPagePlusQueryString'],
                    ['name' => 'deviceCategory'],
                ],
                'metrics' => [['name' => 'sessions'], ['name' => $metric]],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => 50,
            ],
        ]);
        foreach (dossierGaRows(is_array($extra[0] ?? null) ? $extra[0] : []) as $row) {
            $name = (string) ($row['eventName'] ?? '');
            if ($name === '') {
                continue;
            }
            $events[] = [
                'name' => $name,
                'kind' => dossierEventKind($name),
                'keyEvents' => (int) round($row[$metric] ?? 0),
                'eventCount' => (int) round($row['eventCount'] ?? 0),
            ];
        }
        foreach (dossierGaRows(is_array($extra[1] ?? null) ? $extra[1] : []) as $row) {
            $path = dossierNormPath((string) ($row['landingPagePlusQueryString'] ?? ''));
            $device = strtolower((string) ($row['deviceCategory'] ?? ''));
            if ($device === '') {
                continue;
            }
            if (!isset($landingDevices[$path][$device])) {
                $landingDevices[$path][$device] = ['sessions' => 0, 'keyEvents' => 0];
            }
            $landingDevices[$path][$device]['sessions'] += (int) round($row['sessions'] ?? 0);
            $landingDevices[$path][$device]['keyEvents'] += (int) round($row[$metric] ?? 0);
        }
    } catch (Throwable) {
        // El lote extra es contexto. Si falla, el informe sigue con los totales.
    }

    $breakdown = ['whatsapp' => 0, 'llamada' => 0, 'formulario' => 0, 'email' => 0];
    foreach ($events as $event) {
        $kind = (string) ($event['kind'] ?? 'otro');
        if (isset($breakdown[$kind])) {
            $breakdown[$kind] += (int) $event['keyEvents'];
        }
    }
    $classified = array_sum($breakdown);
    $keyNow = (int) round($now[$metric] ?? 0);
    $keyPrev = (int) round($prev[$metric] ?? 0);
    $contacts = $classified > 0 ? $classified : $keyNow;

    $payload = [
        'ok' => true,
        'cached' => false,
        'version' => DOSSIER_VERSION,
        'fetchedAt' => time(),
        'property' => $propertyId,
        'range' => ['start' => $windows['start'], 'end' => $windows['end']],
        'metric' => $metric,
        'current' => [
            'sessions' => (int) round($now['sessions'] ?? 0),
            'users' => (int) round($now['totalUsers'] ?? 0),
            'newUsers' => (int) round($now['newUsers'] ?? 0),
            'engagementRate' => (float) ($now['engagementRate'] ?? 0),
            'avgDuration' => (float) ($now['averageSessionDuration'] ?? 0),
            'views' => (int) round($now['screenPageViews'] ?? 0),
            'conversions' => $keyNow,
            'keyEvents' => $keyNow,
        ],
        'previous' => [
            'sessions' => (int) round($prev['sessions'] ?? 0),
            'users' => (int) round($prev['totalUsers'] ?? 0),
            'keyEvents' => $keyPrev,
        ],
        'organic' => [
            'sessions' => (int) round($organic['sessions'] ?? 0),
            'users' => (int) round($organic['totalUsers'] ?? 0),
            'conversions' => (int) round($organic[$metric] ?? 0),
            'keyEvents' => (int) round($organic[$metric] ?? 0),
        ],
        'organicPrevious' => [
            'sessions' => (int) round($organicPrev['sessions'] ?? 0),
            'keyEvents' => (int) round($organicPrev[$metric] ?? 0),
        ],
        'channels' => array_map(static fn ($row) => [
            'name' => (string) ($row['sessionDefaultChannelGroup'] ?? ''),
            'sessions' => (int) round($row['sessions'] ?? 0),
            'conversions' => (int) round($row[$metric] ?? 0),
            'keyEvents' => (int) round($row[$metric] ?? 0),
        ], $channels),
        'landings' => array_map(static fn ($row) => [
            'path' => dossierNormPath((string) ($row['landingPagePlusQueryString'] ?? '')),
            'sessions' => (int) round($row['sessions'] ?? 0),
            'engagementRate' => (float) ($row['engagementRate'] ?? 0),
            'conversions' => (int) round($row[$metric] ?? 0),
            'keyEvents' => (int) round($row[$metric] ?? 0),
        ], $landings),
        'devices' => array_map(static fn ($row) => [
            'name' => strtolower((string) ($row['deviceCategory'] ?? '')),
            'sessions' => (int) round($row['sessions'] ?? 0),
        ], $devices),
        'events' => $events,
        'landingDevices' => $landingDevices,
        'contacts' => $contacts,
        'contactsClassified' => $classified > 0,
        'contactBreakdown' => $breakdown,
        'hasKeyEvents' => $keyNow > 0 || $keyPrev > 0,
        'keyEvents' => $keyNow,
        'keyEventsPrev' => $keyPrev,
        'error' => '',
    ];
    gscWriteJson($file, $payload);
    return $payload;
}

/* ------------------------------------------------------------
   PageSpeed: las 4 categorías viajan en la misma llamada.
   ------------------------------------------------------------ */

function dossierPsiScore(array $categories, string $id): ?int
{
    $score = $categories[$id]['score'] ?? null;
    return is_numeric($score) ? (int) round(((float) $score) * 100) : null;
}

function dossierPsiPage(string $apiKey, string $url): ?array
{
    $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url='
        . rawurlencode($url)
        . '&strategy=mobile&category=performance&category=seo&category=accessibility&category=best-practices&key='
        . rawurlencode($apiKey);
    $res = gscHttp('GET', $endpoint, null, [], 20);
    if (!$res['ok'] || !is_array($res['data'])) {
        return null;
    }
    $data = $res['data'];
    $audits = is_array($data['lighthouseResult']['audits'] ?? null) ? $data['lighthouseResult']['audits'] : [];
    $categories = is_array($data['lighthouseResult']['categories'] ?? null) ? $data['lighthouseResult']['categories'] : [];
    $field = strtoupper((string) ($data['loadingExperience']['overall_category'] ?? ''));
    if (!in_array($field, ['FAST', 'AVERAGE', 'SLOW'], true)) {
        $field = '';
    }
    return [
        'at' => time(),
        'version' => DOSSIER_VERSION,
        'score' => dossierPsiScore($categories, 'performance'),
        'seo' => dossierPsiScore($categories, 'seo'),
        'accessibility' => dossierPsiScore($categories, 'accessibility'),
        'bestPractices' => dossierPsiScore($categories, 'best-practices'),
        'lcp' => (string) ($audits['largest-contentful-paint']['displayValue'] ?? ''),
        'cls' => (string) ($audits['cumulative-layout-shift']['displayValue'] ?? ''),
        'inp' => (string) ($audits['interaction-to-next-paint']['displayValue'] ?? ''),
        'field' => $field,
    ];
}

function dossierPsiTargets(array $gsc, string $host): array
{
    $home = 'https://' . $host . '/';
    $urls = [$home];
    $seen = [dossierNormPath($home) => true];
    $rows = is_array($gsc['pages'] ?? null) ? $gsc['pages'] : [];
    usort($rows, static fn ($a, $b) => ((int) ($b['current']['impressions'] ?? 0)) <=> ((int) ($a['current']['impressions'] ?? 0)));
    foreach ($rows as $row) {
        if (count($urls) >= DOSSIER_PSI_LIMIT) {
            break;
        }
        if (!is_array($row) || !empty($row['error'])) {
            continue;
        }
        $url = trim((string) ($row['url'] ?? ''));
        if ($url === '' || (int) ($row['current']['impressions'] ?? 0) < 30) {
            continue;
        }
        $key = dossierNormPath($url);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $urls[] = $url;
    }
    return $urls;
}

function dossierPsi(string $dataDir, string $host, string $apiKey, bool $fresh, array $gsc = []): array
{
    $file = dossierPsiFile($dataDir, $host);
    $cached = gscReadJson($file, []);
    $pages = is_array($cached['pages'] ?? null) ? $cached['pages'] : [];
    $home = 'https://' . $host . '/';
    if ($apiKey === '') {
        return ['pages' => $pages, 'home' => is_array($pages[$home] ?? null) ? $pages[$home] : null];
    }

    $targets = dossierPsiTargets($gsc !== [] ? $gsc : ['pages' => []], $host);
    $changed = false;
    foreach ($targets as $url) {
        $hit = $pages[$url] ?? null;
        // El TTL manda: no re-medir todo el sitio solo porque ahora pedimos más categorías.
        $stale = !is_array($hit) || (time() - (int) ($hit['at'] ?? 0)) >= DOSSIER_PSI_TTL;
        if (!$fresh && !$stale) {
            continue;
        }
        $measured = dossierPsiPage($apiKey, $url);
        if ($measured) {
            $pages[$url] = $measured;
            $changed = true;
        }
    }
    if ($changed) {
        gscWriteJson($file, ['pages' => $pages]);
    }
    return ['pages' => $pages, 'home' => is_array($pages[$home] ?? null) ? $pages[$home] : null];
}

function dossierEnsureKeywords(string $dataDir, array $sa, string $host, array $gsc): array
{
    $kwFile = gscKeywordCacheFile($dataDir, $host);
    $kw = gscReadJson($kwFile, []);
    $valid = isset($kw['buckets'])
        && isset($kw['fetchedAt'])
        && ($kw['version'] ?? '') === GSC_VERSION
        && ($kw['kwVersion'] ?? '') === GSC_KW_VERSION;
    if ($valid && (time() - (int) $kw['fetchedAt']) < GSC_CACHE_TTL) {
        return $kw;
    }
    if ($sa === []) {
        return isset($kw['buckets']) ? $kw : [];
    }
    try {
        $token = gscAccessToken($sa, $dataDir . '/gsc-token.json');
        $sites = [];
        try {
            $sites = gscListSites($token);
        } catch (Throwable) {
        }
        $resolved = gscResolvePropertyCached(
            $token,
            'https://' . $host . '/',
            $sites,
            (string) ($gsc['diagnostics']['property'] ?? $kw['property'] ?? '')
        );
        $property = (string) ($resolved['property'] ?? '');
        if ($property === '') {
            return isset($kw['buckets']) ? $kw : [];
        }
        $kw = gscFetchKeywords($token, $property, $host);
        gscWriteJson($kwFile, $kw);
        return $kw;
    } catch (Throwable) {
        return isset($kw['buckets']) ? $kw : [];
    }
}

function dossierQueryForPath(array $kw, string $path): string
{
    $want = dossierNormPath($path);
    foreach (['striking', 'lowCtr', 'top'] as $bucket) {
        foreach ($kw['buckets'][$bucket] ?? [] as $row) {
            if (!is_array($row) || !empty($row['brand'])) {
                continue;
            }
            if (dossierNormPath((string) ($row['page'] ?? '')) === $want && trim((string) ($row['q'] ?? '')) !== '') {
                return (string) $row['q'];
            }
        }
    }
    return '';
}

function dossierPsiStamp(array $psi): string
{
    $bits = [];
    foreach ($psi['pages'] ?? [] as $url => $row) {
        if (is_array($row)) {
            $bits[] = (string) $url . ':' . (int) ($row['at'] ?? 0);
        }
    }
    sort($bits);
    return md5(implode('|', $bits));
}

/**
 * Arma el paquete desde los cachés. No llama a la red.
 * ok=false cuando todavía no hay Search Console: sin eso no hay dossier.
 */
function dossierPack(string $dataDir, string $host): array
{
    $gsc = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    $hasGsc = ($gsc['host'] ?? '') === $host && (is_array($gsc['totals'] ?? null) || is_array($gsc['periods'] ?? null));
    $windows = dossierWindows($gsc);
    $period = dossierPeriod28($gsc);
    $totals = is_array($period['totals'] ?? null) ? $period['totals'] : [];
    if ($totals === [] && is_array($gsc['totals'] ?? null)) {
        $totals = $gsc['totals'];
    }
    $previous = is_array($period['previous'] ?? null) ? $period['previous'] : [];
    $delta = is_array($period['delta'] ?? null) ? $period['delta'] : (is_array($gsc['totalsDelta'] ?? null) ? $gsc['totalsDelta'] : []);

    $kw = gscReadJson(gscKeywordCacheFile($dataDir, $host), []);
    if (!isset($kw['buckets']) || ($kw['host'] ?? $host) === '') {
        if (!isset($kw['buckets'])) {
            $kw = [];
        }
    }

    $pages = [];
    foreach ($gsc['pages'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $url = (string) ($row['url'] ?? '');
        $path = dossierNormPath($url);
        $pages[] = [
            'url' => $url,
            'path' => $path,
            'title' => gscPageTitle($row),
            'clicks' => (int) ($row['current']['clicks'] ?? 0),
            'impressions' => (int) ($row['current']['impressions'] ?? 0),
            'ctr' => (float) ($row['current']['ctr'] ?? 0),
            'position' => (float) ($row['current']['position'] ?? 0),
            'prevClicks' => (int) ($row['previous']['clicks'] ?? 0),
            'deltaClicks' => (int) ($row['delta']['clicks'] ?? 0),
            'deltaPosition' => (float) ($row['delta']['position'] ?? 0),
            'indexTone' => (string) ($row['indexStatus']['tone'] ?? ''),
            'indexReason' => (string) ($row['indexStatus']['reason'] ?? $row['indexStatus']['coverage'] ?? ''),
            'error' => (string) ($row['error'] ?? ''),
            'thermometer' => (string) ($row['thermometer']['tone'] ?? ''),
            'query' => isset($kw['buckets']) ? dossierQueryForPath($kw, $path) : '',
        ];
    }

    $ga = gscReadJson(dossierGaFile($dataDir, $host), []);
    $gaOk = !empty($ga['ok']) && empty($ga['error']) && ($ga['version'] ?? '') === DOSSIER_VERSION;
    if (!$gaOk) {
        $ga = null;
    }

    $psi = gscReadJson(dossierPsiFile($dataDir, $host), []);
    $psiPages = is_array($psi['pages'] ?? null) ? $psi['pages'] : [];
    $homeUrl = 'https://' . $host . '/';
    $psiPack = [
        'pages' => $psiPages,
        'home' => is_array($psiPages[$homeUrl] ?? null) ? $psiPages[$homeUrl] : null,
    ];

    $dom = dossierDomIndex($dataDir, $host);
    $repoRaw = gscReadJson(dossierRepoFile($dataDir, $host), []);
    $repoOk = ($repoRaw['host'] ?? '') === $host && ($repoRaw['slug'] ?? '') !== '';
    $repo = [
        'slug' => $repoOk ? (string) $repoRaw['slug'] : '',
        'branch' => $repoOk ? (string) ($repoRaw['branch'] ?? '') : '',
        'paths' => $repoOk && is_array($repoRaw['paths'] ?? null) ? $repoRaw['paths'] : [],
        'files' => $repoOk && is_array($repoRaw['files'] ?? null) ? $repoRaw['files'] : [],
        'fetchedAt' => $repoOk ? (int) ($repoRaw['fetchedAt'] ?? 0) : 0,
    ];

    $client = dossierClient($host);
    $tasks = $hasGsc ? dossierTasks($host, $windows) : [];
    $hasPsi = false;
    foreach ($psiPages as $row) {
        if (is_array($row) && ($row['score'] ?? null) !== null) {
            $hasPsi = true;
            break;
        }
    }

    return [
        'ok' => $hasGsc && $totals !== [],
        'host' => $host,
        'version' => DOSSIER_VERSION,
        'fingerprint' => '',
        'builtAt' => time(),
        'client' => $client,
        'windows' => $windows,
        'search' => [
            'clicks' => (int) ($totals['clicks'] ?? 0),
            'impressions' => (int) ($totals['impressions'] ?? 0),
            'ctr' => (float) ($totals['ctr'] ?? 0),
            'position' => (float) ($totals['position'] ?? 0),
            'clicksDelta' => (int) ($delta['clicks'] ?? 0),
            'impressionsDelta' => (int) ($delta['impressions'] ?? 0),
            'ctrDelta' => (float) ($delta['ctr'] ?? 0),
            'positionDelta' => (float) ($delta['position'] ?? 0),
            'prevClicks' => (int) ($previous['clicks'] ?? 0),
            'prevImpressions' => (int) ($previous['impressions'] ?? 0),
        ],
        'daily' => is_array($gsc['daily'] ?? null) ? $gsc['daily'] : [],
        'devices' => is_array($gsc['devices'] ?? null) ? $gsc['devices'] : [],
        'inventory' => is_array($gsc['inventory'] ?? null) ? $gsc['inventory'] : [],
        'diagnostics' => is_array($gsc['diagnostics'] ?? null) ? $gsc['diagnostics'] : [],
        'pages' => $pages,
        'keywords' => isset($kw['buckets']) ? $kw : [],
        'ga' => $ga,
        'gaMeta' => [
            'ok' => $ga !== null,
            'property' => (string) ($ga['property'] ?? ''),
            'message' => '',
        ],
        'psi' => $psiPack,
        'dom' => $dom['pages'],
        'domProgress' => [
            'done' => $dom['done'],
            'total' => $dom['total'],
            'complete' => $dom['total'] > 0 && $dom['done'] >= $dom['total'],
            'updatedAt' => $dom['updatedAt'],
        ],
        'repo' => $repo,
        'tasks' => $tasks,
        'sources' => [
            'gsc' => $hasGsc && $totals !== [],
            'ga4' => $ga !== null,
            'keyEvents' => $ga !== null && !empty($ga['hasKeyEvents']),
            'psi' => $hasPsi,
            'dom' => $dom['done'] > 0,
            'repo' => $repo['slug'] !== '',
            'keywords' => isset($kw['buckets']),
        ],
    ];
}

function dossierStamps(string $dataDir, string $host): array
{
    $gsc = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    $kw = gscReadJson(gscKeywordCacheFile($dataDir, $host), []);
    $ga = gscReadJson(dossierGaFile($dataDir, $host), []);
    $psi = gscReadJson(dossierPsiFile($dataDir, $host), []);
    $dom = gscReadJson(dossierDomFile($dataDir, $host), []);
    $repo = gscReadJson(dossierRepoFile($dataDir, $host), []);
    $windows = dossierWindows($gsc);
    return [
        'v' => DOSSIER_VERSION,
        'gsc' => (int) ($gsc['fetchedAt'] ?? 0),
        'kw' => (int) ($kw['fetchedAt'] ?? 0),
        'ga' => (int) ($ga['fetchedAt'] ?? 0),
        'psi' => dossierPsiStamp(['pages' => $psi['pages'] ?? []]),
        'dom' => (int) ($dom['updatedAt'] ?? 0),
        'repo' => (int) ($repo['fetchedAt'] ?? 0),
        'tasks' => dossierTasksSig($host, $windows),
    ];
}

/**
 * $allowFetch refresca keywords, Analytics y PageSpeed (respetando su TTL,
 * salvo $fresh). El HTML y el repo no se scrapean acá: los llena la auditoría.
 */
function dossierBuild(string $dataDir, array $sa, string $host, bool $fresh = false, bool $allowFetch = false): array
{
    $host = gscSanitizeHost($host);
    if ($host === '') {
        return dossierPack($dataDir, '');
    }

    $gaNote = '';
    if ($allowFetch) {
        $gsc = gscReadJson(gscHostCacheFile($dataDir, $host), []);
        if (($gsc['host'] ?? '') === $host) {
            try {
                dossierEnsureKeywords($dataDir, $sa, $host, $gsc);
            } catch (Throwable) {
            }
            $prefs = dossierHostPrefs($dataDir, $host);
            $resolved = dossierResolveProperty($dataDir, $sa, $host, (string) ($prefs['ga4Property'] ?? ''));
            if ($resolved['id'] !== '' && $sa !== []) {
                try {
                    dossierFetchGa($dataDir, $sa, $host, $resolved['id'], dossierWindows($gsc), $fresh);
                } catch (Throwable $e) {
                    $gaNote = dossierPublicError($e->getMessage());
                }
            } elseif (($resolved['error'] ?? '') !== '') {
                $gaNote = (string) $resolved['error'];
            }
            $psiKey = dossierSecret($dataDir, 'psi.json');
            if ($psiKey !== '') {
                try {
                    dossierPsi($dataDir, $host, $psiKey, $fresh, $gsc);
                } catch (Throwable) {
                }
            }
        }
    }

    $fp = md5(json_encode(dossierStamps($dataDir, $host), JSON_UNESCAPED_UNICODE) ?: '');
    $cacheFile = dossierCacheFile($dataDir, $host);
    $cached = gscReadJson($cacheFile, []);
    if (
        !$fresh
        && ($cached['fingerprint'] ?? '') === $fp
        && is_array($cached['dossier'] ?? null)
        && ($cached['dossier']['version'] ?? '') === DOSSIER_VERSION
    ) {
        return $cached['dossier'];
    }

    $dossier = dossierPack($dataDir, $host);
    $dossier['fingerprint'] = $fp;
    if ($gaNote !== '' && empty($dossier['sources']['ga4'])) {
        $dossier['gaMeta']['message'] = $gaNote;
    }
    if ($dossier['ok']) {
        gscWriteJson($cacheFile, ['fingerprint' => $fp, 'dossier' => $dossier]);
    }
    return $dossier;
}

function dossierRead(string $dataDir, string $host): array
{
    return dossierBuild($dataDir, [], $host, false, false);
}
