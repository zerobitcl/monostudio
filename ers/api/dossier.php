<?php
/**
 * Dossier único por sitio. Search Console, Analytics, PageSpeed, HTML,
 * repo y tareas cerradas salen de aquí: el plan de crecimiento (Cursor) y el informe
 * cliente leen el mismo paquete, no cada uno su caché.
 */

declare(strict_types=1);

if (!defined('ERS_LIBRARY')) {
    define('ERS_LIBRARY', true);
}

require_once __DIR__ . '/gsc.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/gbp.php';

const DOSSIER_VERSION = '2026.11.21-tuning';
const DOSSIER_GA_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
const DOSSIER_GA_TTL = 21600;
const DOSSIER_PSI_TTL = 604800;
const DOSSIER_DIR_TTL = 43200;
const DOSSIER_PSI_LIMIT = 5;
const DOSSIER_DOM_MAX_BYTES = 800000;
const DOSSIER_FRESH_TTL = 21600;
const DOSSIER_AGING_TTL = 86400;

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
 * Extrae NAP / LocalBusiness del JSON-LD (teléfono, dirección, geo, sameAs, hasMap).
 * Acumula en $nap por referencia; no inventa datos.
 */
function dossierCollectNapFromLd(mixed $node, array &$nap, int $depth = 0): void
{
    if ($depth > 10 || !is_array($node)) {
        return;
    }
    // Array de nodos (@graph o lista).
    $isList = function_exists('array_is_list')
        ? array_is_list($node)
        : ($node === [] || array_keys($node) === range(0, count($node) - 1));
    if ($isList) {
        foreach ($node as $child) {
            dossierCollectNapFromLd($child, $nap, $depth + 1);
        }
        return;
    }
    if (isset($node['@graph']) && is_array($node['@graph'])) {
        dossierCollectNapFromLd($node['@graph'], $nap, $depth + 1);
    }

    $types = [];
    if (isset($node['@type'])) {
        $raw = is_array($node['@type']) ? $node['@type'] : [$node['@type']];
        foreach ($raw as $t) {
            if (is_string($t) && $t !== '') {
                $types[] = $t;
            }
        }
    }
    $localTypes = [
        'LocalBusiness', 'Organization', 'ProfessionalService', 'Store', 'Restaurant',
        'Dentist', 'Attorney', 'MedicalBusiness', 'HomeAndConstructionBusiness',
        'LegalService', 'HealthAndBeautyBusiness', 'LodgingBusiness', 'FoodEstablishment',
        'RealEstateAgent', 'TravelAgency', 'AutomotiveBusiness', 'SportsActivityLocation',
    ];
    $isLocal = $types === [] || count(array_intersect($types, $localTypes)) > 0;

    if ($isLocal) {
        foreach (['telephone', 'phone'] as $k) {
            $tel = trim((string) ($node[$k] ?? ''));
            if ($tel !== '') {
                $nap['phones'][$tel] = true;
            }
        }
        if (isset($node['address'])) {
            $addr = $node['address'];
            if (is_string($addr) && trim($addr) !== '') {
                $nap['address'] = ersClip(trim($addr), 300);
            } elseif (is_array($addr)) {
                $parts = [];
                foreach (['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry'] as $ak) {
                    $v = trim((string) ($addr[$ak] ?? ''));
                    if ($v !== '') {
                        $parts[] = $v;
                    }
                }
                if ($parts !== []) {
                    $nap['address'] = ersClip(implode(', ', $parts), 300);
                }
                $locality = trim((string) ($addr['addressLocality'] ?? ''));
                if ($locality !== '') {
                    $nap['locality'] = ersClip($locality, 80);
                }
            }
        }
        if (isset($node['geo']) && is_array($node['geo'])) {
            $lat = $node['geo']['latitude'] ?? null;
            $lng = $node['geo']['longitude'] ?? null;
            if (is_numeric($lat) && is_numeric($lng)) {
                $nap['geo'] = ['lat' => (float) $lat, 'lng' => (float) $lng];
            }
        }
        foreach (['hasMap', 'maps'] as $k) {
            $map = trim((string) ($node[$k] ?? ''));
            if ($map !== '' && (str_contains($map, 'google.com/maps') || str_contains($map, 'maps.app.goo.gl') || str_contains($map, 'goo.gl/maps'))) {
                $nap['mapsUrls'][$map] = true;
            }
        }
        if (isset($node['sameAs'])) {
            $same = is_array($node['sameAs']) ? $node['sameAs'] : [$node['sameAs']];
            foreach ($same as $url) {
                $url = trim((string) $url);
                if ($url === '') {
                    continue;
                }
                $nap['sameAs'][$url] = true;
                if (str_contains($url, 'google.com/maps') || str_contains($url, 'maps.app.goo.gl') || str_contains($url, 'goo.gl/maps')) {
                    $nap['mapsUrls'][$url] = true;
                }
            }
        }
        $name = trim((string) ($node['name'] ?? ''));
        if ($name !== '' && ($nap['name'] ?? '') === '') {
            $nap['name'] = ersClip($name, 160);
        }
        foreach ($types as $t) {
            if (in_array($t, $localTypes, true)) {
                $nap['schemaTypes'][$t] = true;
            }
        }
    }

    foreach ($node as $key => $child) {
        if ($key === '@context' || $key === '@type' || !is_array($child)) {
            continue;
        }
        dossierCollectNapFromLd($child, $nap, $depth + 1);
    }
}

function dossierEmptyNap(): array
{
    return [
        'phones' => [],
        'address' => '',
        'locality' => '',
        'geo' => null,
        'mapsUrls' => [],
        'sameAs' => [],
        'name' => '',
        'schemaTypes' => [],
        'hasTelLink' => false,
        'hasMapsLink' => false,
    ];
}

function dossierFinalizeNap(array $nap): array
{
    $phones = array_keys(is_array($nap['phones'] ?? null) ? $nap['phones'] : []);
    $maps = array_keys(is_array($nap['mapsUrls'] ?? null) ? $nap['mapsUrls'] : []);
    $same = array_keys(is_array($nap['sameAs'] ?? null) ? $nap['sameAs'] : []);
    $types = array_keys(is_array($nap['schemaTypes'] ?? null) ? $nap['schemaTypes'] : []);
    return [
        'phones' => array_values(array_slice($phones, 0, 8)),
        'address' => (string) ($nap['address'] ?? ''),
        'locality' => (string) ($nap['locality'] ?? ''),
        'geo' => is_array($nap['geo'] ?? null) ? $nap['geo'] : null,
        'mapsUrls' => array_values(array_slice($maps, 0, 5)),
        'sameAs' => array_values(array_slice($same, 0, 12)),
        'name' => (string) ($nap['name'] ?? ''),
        'schemaTypes' => $types,
        'hasTelLink' => !empty($nap['hasTelLink']),
        'hasMapsLink' => !empty($nap['hasMapsLink']) || $maps !== [],
        'hasLocalSchema' => $types !== [],
    ];
}

/**
 * Scrape SEO. Los campos viejos (title, h1, meta, canonical) se mantienen
 * para no invalidar a ciegas el caché; h1Count marca la versión nueva.
 */
/** Estado de frescura a partir de un timestamp Unix. */
function dossierFreshnessState(int $updatedAt, int $freshTtl = DOSSIER_FRESH_TTL, int $agingTtl = DOSSIER_AGING_TTL): string
{
    if ($updatedAt <= 0) {
        return 'missing';
    }
    $age = time() - $updatedAt;
    if ($age < $freshTtl) {
        return 'fresh';
    }
    if ($age < $agingTtl) {
        return 'aging';
    }
    return 'stale';
}

/**
 * Ledger unificado por fuente. Lo leen el status de auditoría y la UI.
 *
 * @return array<string, array<string, mixed>>
 */
function dossierFreshnessLedger(string $dataDir, string $host, array $dossier = [], array $extra = []): array
{
    $gsc = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    $kw = gscReadJson(gscKeywordCacheFile($dataDir, $host), []);
    $kwAi = gscReadJson(gscKeywordAiFile($dataDir, $host), []);
    $ga = gscReadJson(dossierGaFile($dataDir, $host), []);
    $psi = gscReadJson(dossierPsiFile($dataDir, $host), []);
    $dom = gscReadJson(dossierDomFile($dataDir, $host), []);
    $repo = gscReadJson(dossierRepoFile($dataDir, $host), []);
    $gbp = function_exists('gbpReadCache') ? gbpReadCache($dataDir, $host) : [];
    $cursor = is_array($extra['cursor'] ?? null) ? $extra['cursor'] : [];

    $fullAt = (int) ($gsc['fullFetchedAt'] ?? $gsc['fetchedAt'] ?? 0);
    $radarAt = (int) ($gsc['radarRefreshedAt'] ?? 0);
    $isPartial = !empty($gsc['radarRefresh']) && $fullAt > 0 && $radarAt >= $fullAt;

    $domPages = is_array($dom['pages'] ?? null) ? $dom['pages'] : [];
    $oldest = 0;
    $newest = 0;
    $staleCount = 0;
    $now = time();
    foreach ($domPages as $row) {
        if (!is_array($row)) {
            continue;
        }
        $at = (int) ($row['fetchedAt'] ?? 0);
        if ($at <= 0) {
            continue;
        }
        if ($oldest === 0 || $at < $oldest) {
            $oldest = $at;
        }
        if ($at > $newest) {
            $newest = $at;
        }
        if (($now - $at) >= 604800) {
            $staleCount++;
        }
    }

    $psiFetched = 0;
    $psiOldest = 0;
    foreach (($psi['pages'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $at = (int) ($row['fetchedAt'] ?? 0);
        if ($at <= 0) {
            continue;
        }
        if ($at > $psiFetched) {
            $psiFetched = $at;
        }
        if ($psiOldest === 0 || $at < $psiOldest) {
            $psiOldest = $at;
        }
    }

    $domDone = (int) ($dossier['domProgress']['done'] ?? 0);
    $domTotal = (int) ($dossier['domProgress']['total'] ?? count($domPages));
    if ($domDone === 0 && $domPages !== []) {
        $domDone = count(array_filter($domPages, static fn ($r) => is_array($r) && !empty($r['ok'])));
    }

    $source = static function (
        string $state,
        int $updatedAt,
        array $more = []
    ): array {
        return array_merge([
            'state' => $state,
            'updatedAt' => $updatedAt,
        ], $more);
    };

    return [
        'gsc' => $source(
            $isPartial ? 'partial' : dossierFreshnessState($fullAt),
            $fullAt,
            [
                'fullFetchedAt' => $fullAt,
                'radarRefreshedAt' => $radarAt,
                'isPartialRadar' => $isPartial,
                'fetchedAt' => (int) ($gsc['fetchedAt'] ?? 0),
            ]
        ),
        'keywords' => $source(
            dossierFreshnessState((int) ($kw['fetchedAt'] ?? 0)),
            (int) ($kw['fetchedAt'] ?? 0),
            [
                'aiGeneratedAt' => (int) ($kwAi['generatedAt'] ?? 0),
                'aiState' => dossierFreshnessState((int) ($kwAi['generatedAt'] ?? 0)),
            ]
        ),
        'ga4' => $source(
            !empty($ga['ok']) ? dossierFreshnessState((int) ($ga['fetchedAt'] ?? 0)) : 'missing',
            (int) ($ga['fetchedAt'] ?? 0)
        ),
        'psi' => $source(
            $psiFetched > 0 ? dossierFreshnessState($psiFetched, DOSSIER_PSI_TTL / 2, DOSSIER_PSI_TTL) : 'missing',
            $psiFetched,
            ['oldestAt' => $psiOldest]
        ),
        'gbp' => $source(
            !empty($gbp['ok']) || !empty($gbp['placeId'])
                ? dossierFreshnessState((int) ($gbp['fetchedAt'] ?? 0), 43200, 86400)
                : 'missing',
            (int) ($gbp['fetchedAt'] ?? 0)
        ),
        'dom' => $source(
            $newest > 0 ? dossierFreshnessState($newest, 86400, 604800) : 'missing',
            (int) ($dom['updatedAt'] ?? $newest),
            [
                'oldestUrlAt' => $oldest,
                'newestUrlAt' => $newest,
                'done' => $domDone,
                'total' => $domTotal,
                'staleCount' => $staleCount,
            ]
        ),
        'repo' => $source(
            (($repo['slug'] ?? '') !== '' && (int) ($repo['fetchedAt'] ?? 0) > 0)
                ? dossierFreshnessState((int) ($repo['fetchedAt'] ?? 0))
                : 'missing',
            (int) ($repo['fetchedAt'] ?? 0),
            ['commitSha' => (string) ($repo['commitSha'] ?? '')]
        ),
        'plan' => $source(
            is_array($cursor['insights'] ?? null)
                ? (!empty($cursor['stale']) ? 'stale' : dossierFreshnessState((int) ($cursor['generatedAt'] ?? 0)))
                : 'missing',
            (int) ($cursor['generatedAt'] ?? 0),
            [
                'basedOnCommit' => (string) ($cursor['commitSha'] ?? ''),
                'stale' => !empty($cursor['stale']),
            ]
        ),
    ];
}

/** ¿El nodo vive dentro de chrome (nav/header/footer/aside)? */
function dossierNodeInChrome(DOMNode $node): bool
{
    $cur = $node->parentNode;
    while ($cur instanceof DOMElement) {
        $tag = strtolower($cur->tagName);
        if (in_array($tag, ['nav', 'header', 'footer', 'aside', 'script', 'style', 'noscript'], true)) {
            return true;
        }
        $role = strtolower($cur->getAttribute('role'));
        if (in_array($role, ['navigation', 'banner', 'contentinfo', 'complementary'], true)) {
            return true;
        }
        $cls = strtolower($cur->getAttribute('class') . ' ' . $cur->getAttribute('id'));
        if (preg_match('/\b(cookie|consent|newsletter|modal|popup|nav|menu|footer|header|sidebar)\b/', $cls)) {
            return true;
        }
        $cur = $cur->parentNode;
    }
    return false;
}

function dossierWordCountFromText(string $text): int
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') {
        return 0;
    }
    $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? count($parts) : 0;
}

function dossierFetchDom(string $url): array
{
    $emptyNap = dossierFinalizeNap(dossierEmptyNap());
    $empty = [
        'ok' => false,
        'status' => 0,
        'httpCode' => 0,
        'redirectCount' => 0,
        'effectiveUrl' => '',
        'xRobots' => '',
        'lastModified' => '',
        'title' => '',
        'h1' => '',
        'h2' => '',
        'h3' => '',
        'h1List' => [],
        'h2List' => [],
        'h3List' => [],
        'meta' => '',
        'canonical' => '',
        'robots' => '',
        'ogTitle' => '',
        'ogDescription' => '',
        'lang' => '',
        'h1Count' => 0,
        'h2Count' => 0,
        'h3Count' => 0,
        'titleLen' => 0,
        'schemas' => [],
        'images' => 0,
        'imagesMissingAlt' => 0,
        'imgsWithoutDimensions' => 0,
        'heroImgWithoutPriority' => false,
        'imagesLegacy' => 0,
        'imagesModern' => 0,
        'internalLinks' => 0,
        'contextualLinks' => 0,
        'chromeLinks' => 0,
        'internalTargets' => [],
        'wordCount' => 0,
        'mainWordCount' => 0,
        'leadExcerpt' => '',
        'hasFaq' => false,
        'dateModified' => '',
        'datePublished' => '',
        'contentHash' => '',
        'hreflang' => false,
        'hasTel' => false,
        'hasWhatsapp' => false,
        'hasForm' => false,
        'hasMailto' => false,
        'nap' => $emptyNap,
        'error' => '',
        'fetchedAt' => time(),
    ];

    if (!preg_match('#^https?://#i', $url) || gscIsAgencyHost($url)) {
        $empty['error'] = 'URL no válida';
        return $empty;
    }

    $html = '';
    $code = 0;
    $redirectCount = 0;
    $effectiveUrl = $url;
    $xRobots = '';
    $lastModified = '';

    if (function_exists('curl_init')) {
        $headerBag = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'MonoStudio-SeoAudit/1.1',
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$headerBag): int {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $headerBag[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectCount = (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
        $eff = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if ($eff !== '') {
            $effectiveUrl = $eff;
        }
        $err = curl_error($ch);
        curl_close($ch);
        $xRobots = (string) ($headerBag['x-robots-tag'] ?? '');
        $lastModified = (string) ($headerBag['last-modified'] ?? '');
        if (!is_string($raw)) {
            $empty['status'] = $code;
            $empty['httpCode'] = $code;
            $empty['redirectCount'] = $redirectCount;
            $empty['effectiveUrl'] = $effectiveUrl;
            $empty['xRobots'] = $xRobots;
            $empty['lastModified'] = $lastModified;
            $empty['error'] = $err !== '' ? $err : 'sin respuesta';
            return $empty;
        }
        $html = $raw;
    } else {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 8,
                'header' => "User-Agent: MonoStudio-SeoAudit/1.1\r\n",
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
        $respHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?: [])
            : [];
        foreach ($respHeaders as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (stripos($line, 'X-Robots-Tag:') === 0) {
                $xRobots = trim(substr($line, strlen('X-Robots-Tag:')));
            }
            if (stripos($line, 'Last-Modified:') === 0) {
                $lastModified = trim(substr($line, strlen('Last-Modified:')));
            }
        }
    }

    $empty['httpCode'] = $code;
    $empty['redirectCount'] = $redirectCount;
    $empty['effectiveUrl'] = $effectiveUrl;
    $empty['xRobots'] = $xRobots;
    $empty['lastModified'] = $lastModified;

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

    if (strlen($html) > DOSSIER_DOM_MAX_BYTES) {
        $html = substr($html, 0, DOSSIER_DOM_MAX_BYTES);
    }
    $flat = strtolower($html);

    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xpath = new DOMXPath($dom);

    $listOf = static function (string $expr, int $limit = 12) use ($xpath): array {
        $nodes = $xpath->query($expr);
        $parts = [];
        if ($nodes) {
            foreach ($nodes as $i => $node) {
                if ($i >= $limit) {
                    break;
                }
                $t = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');
                if ($t !== '') {
                    $parts[] = gscAscii($t);
                }
            }
        }
        return $parts;
    };
    $attrOf = static function (string $expr) use ($xpath): string {
        foreach ($xpath->query($expr) ?: [] as $attr) {
            return gscAscii(trim((string) $attr->nodeValue));
        }
        return '';
    };

    $title = $listOf('//title', 1)[0] ?? '';
    $h1List = $listOf('//h1', 6);
    $h2List = $listOf('//h2', 12);
    $h3List = $listOf('//h3', 12);
    $h1Count = count($h1List);
    $h2Nodes = $xpath->query('//h2');
    $h3Nodes = $xpath->query('//h3');
    $h2Count = $h2Nodes ? $h2Nodes->length : 0;
    $h3Count = $h3Nodes ? $h3Nodes->length : 0;

    $schemas = [];
    $napAcc = dossierEmptyNap();
    $dateModified = '';
    $datePublished = '';
    $hasFaqSchema = false;
    $ld = $xpath->query('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]');
    if ($ld) {
        foreach ($ld as $script) {
            $json = json_decode((string) $script->textContent, true);
            dossierCollectSchemaTypes($json, $schemas);
            dossierCollectNapFromLd($json, $napAcc);
            $walkDates = static function ($node) use (&$walkDates, &$dateModified, &$datePublished, &$hasFaqSchema): void {
                if (!is_array($node)) {
                    return;
                }
                if (isset($node[0]) && is_array($node[0])) {
                    foreach ($node as $child) {
                        $walkDates($child);
                    }
                    return;
                }
                $type = $node['@type'] ?? '';
                $types = is_array($type) ? $type : [$type];
                foreach ($types as $t) {
                    if (strcasecmp((string) $t, 'FAQPage') === 0) {
                        $hasFaqSchema = true;
                    }
                }
                if ($dateModified === '' && !empty($node['dateModified'])) {
                    $dateModified = trim((string) $node['dateModified']);
                }
                if ($datePublished === '' && !empty($node['datePublished'])) {
                    $datePublished = trim((string) $node['datePublished']);
                }
                foreach ($node as $v) {
                    if (is_array($v)) {
                        $walkDates($v);
                    }
                }
            };
            $walkDates($json);
        }
    }

    $images = 0;
    $missingAlt = 0;
    $imgsWithoutDimensions = 0;
    $imagesLegacy = 0;
    $imagesModern = 0;
    $heroImgWithoutPriority = false;
    $imgIndex = 0;
    foreach ($xpath->query('//img') ?: [] as $img) {
        if (!$img instanceof DOMElement) {
            continue;
        }
        $images++;
        $imgIndex++;
        if (trim($img->getAttribute('alt')) === '') {
            $missingAlt++;
        }
        $w = trim($img->getAttribute('width'));
        $h = trim($img->getAttribute('height'));
        if ($w === '' || $h === '') {
            $imgsWithoutDimensions++;
        }
        $src = strtolower($img->getAttribute('src') . ' ' . $img->getAttribute('srcset'));
        if (preg_match('/\.(webp|avif)(\?|$|\s)/i', $src)) {
            $imagesModern++;
        } elseif (preg_match('/\.(jpe?g|png|gif|bmp)(\?|$|\s)/i', $src)) {
            $imagesLegacy++;
        }
        if ($imgIndex === 1) {
            $loading = strtolower($img->getAttribute('loading'));
            $prio = strtolower($img->getAttribute('fetchpriority'));
            if ($loading === 'lazy' || $prio !== 'high') {
                $heroImgWithoutPriority = true;
            }
        }
    }

    $host = gscHost($url);
    $internal = 0;
    $contextualLinks = 0;
    $chromeLinks = 0;
    $internalTargets = [];
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
            $napAcc['hasTelLink'] = true;
            $tel = preg_replace('/^tel:/i', '', $href) ?? '';
            $tel = trim(rawurldecode($tel));
            if ($tel !== '') {
                $napAcc['phones'][$tel] = true;
            }
            continue;
        }
        if (str_starts_with($low, 'mailto:')) {
            $hasMailto = true;
            continue;
        }
        if (
            str_contains($low, 'google.com/maps')
            || str_contains($low, 'maps.app.goo.gl')
            || str_contains($low, 'goo.gl/maps')
            || str_contains($low, 'maps.google.')
        ) {
            $napAcc['hasMapsLink'] = true;
            $napAcc['mapsUrls'][$href] = true;
        }
        if ($href === '' || str_starts_with($low, '#') || str_starts_with($low, 'javascript:')) {
            continue;
        }
        $linkHost = gscHost($href);
        $isInternal = str_starts_with($href, '/') || $linkHost === '' || $linkHost === $host;
        if (!$isInternal) {
            continue;
        }
        $internal++;
        $inChrome = dossierNodeInChrome($a);
        if ($inChrome) {
            $chromeLinks++;
        } else {
            $contextualLinks++;
        }
        $abs = $href;
        if (str_starts_with($href, '/')) {
            $abs = 'https://' . $host . $href;
        } elseif ($linkHost === '') {
            $abs = rtrim($url, '/') . '/' . ltrim($href, '/');
        }
        $path = dossierNormPath($abs);
        if ($path === '' || count($internalTargets) >= 60 && !isset($internalTargets[$path])) {
            continue;
        }
        $anchor = trim(preg_replace('/\s+/u', ' ', $a->textContent ?? '') ?? '');
        if (!isset($internalTargets[$path])) {
            $internalTargets[$path] = [
                'count' => 0,
                'contextual' => 0,
                'sampleAnchor' => '',
            ];
        }
        $internalTargets[$path]['count']++;
        if (!$inChrome) {
            $internalTargets[$path]['contextual']++;
        }
        if ($internalTargets[$path]['sampleAnchor'] === '' && $anchor !== '') {
            $internalTargets[$path]['sampleAnchor'] = gscAscii(ersClip($anchor, 80));
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

    $hasFaqDom = false;
    foreach ($xpath->query('//details') ?: [] as $_) {
        $hasFaqDom = true;
        break;
    }
    if (!$hasFaqDom) {
        foreach (array_merge($h2List, $h3List) as $heading) {
            if (str_contains($heading, '?') || preg_match('/\b(faq|preguntas?\s+frecuentes)\b/i', $heading)) {
                $hasFaqDom = true;
                break;
            }
        }
    }

    // Conteos de palabras: total vs contenido principal (sin chrome).
    foreach ($xpath->query('//script|//style|//noscript') ?: [] as $node) {
        $node->parentNode?->removeChild($node);
    }
    $wordCount = dossierWordCountFromText((string) ($dom->textContent ?? ''));

    $mainText = '';
    $mainNode = null;
    foreach (['//main', '//article', '//*[@role="main"]'] as $expr) {
        $found = $xpath->query($expr);
        if ($found && $found->length > 0) {
            $mainNode = $found->item(0);
            break;
        }
    }
    if ($mainNode instanceof DOMNode) {
        $mainText = (string) ($mainNode->textContent ?? '');
    } else {
        $bodyNode = $xpath->query('//body')->item(0);
        if ($bodyNode instanceof DOMNode) {
            $clone = $bodyNode->cloneNode(true);
            if ($clone instanceof DOMElement) {
                $toRemove = [];
                $stack = [$clone];
                while ($stack !== []) {
                    $cur = array_pop($stack);
                    if (!$cur instanceof DOMElement) {
                        continue;
                    }
                    foreach (iterator_to_array($cur->childNodes) as $child) {
                        if (!$child instanceof DOMElement) {
                            continue;
                        }
                        $tag = strtolower($child->tagName);
                        $role = strtolower($child->getAttribute('role'));
                        $cls = strtolower($child->getAttribute('class') . ' ' . $child->getAttribute('id'));
                        if (
                            in_array($tag, ['nav', 'header', 'footer', 'aside'], true)
                            || in_array($role, ['navigation', 'banner', 'contentinfo', 'complementary'], true)
                            || preg_match('/\b(cookie|consent|newsletter|modal|popup)\b/', $cls)
                        ) {
                            $toRemove[] = $child;
                        } else {
                            $stack[] = $child;
                        }
                    }
                }
                foreach ($toRemove as $n) {
                    $n->parentNode?->removeChild($n);
                }
                $mainText = (string) ($clone->textContent ?? '');
            }
        }
    }
    $mainWordCount = dossierWordCountFromText($mainText);
    $leadExcerpt = gscAscii(ersClip(trim(preg_replace('/\s+/u', ' ', $mainText) ?? ''), 280));

    $lang = '';
    $langAttr = $xpath->query('/html/@lang');
    if ($langAttr && $langAttr->length > 0) {
        $lang = strtolower(trim((string) $langAttr->item(0)?->nodeValue));
    }

    $meta = $attrOf('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content');
    $canonical = $attrOf('//link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]/@href');
    $robots = $attrOf('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"]/@content');
    $h1Joined = implode(' · ', array_slice($h1List, 0, 3));
    $h2Joined = implode(' · ', array_slice($h2List, 0, 8));
    $h3Joined = implode(' · ', array_slice($h3List, 0, 8));
    $schemaKeys = array_keys($schemas);
    $contentHash = md5(implode('|', [
        $title,
        $meta,
        $canonical,
        $h1Joined,
        $h2Joined,
        (string) $mainWordCount,
        implode(',', $schemaKeys),
    ]));

    return [
        'ok' => true,
        'status' => $code,
        'httpCode' => $code,
        'redirectCount' => $redirectCount,
        'effectiveUrl' => ersClip($effectiveUrl, 500),
        'xRobots' => ersClip($xRobots, 160),
        'lastModified' => ersClip($lastModified, 80),
        'title' => ersClip($title, 200),
        'h1' => ersClip($h1Joined, 240),
        'h2' => ersClip($h2Joined, 500),
        'h3' => ersClip($h3Joined, 400),
        'h1List' => array_map(static fn ($t) => ersClip($t, 160), $h1List),
        'h2List' => array_map(static fn ($t) => ersClip($t, 160), $h2List),
        'h3List' => array_map(static fn ($t) => ersClip($t, 120), $h3List),
        'meta' => ersClip($meta, 320),
        'canonical' => ersClip($canonical, 500),
        'robots' => ersClip($robots, 120),
        'ogTitle' => ersClip($attrOf('//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content'), 200),
        'ogDescription' => ersClip($attrOf('//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:description"]/@content'), 320),
        'lang' => ersClip($lang, 16),
        'h1Count' => $h1Count,
        'h2Count' => $h2Count,
        'h3Count' => $h3Count,
        'titleLen' => function_exists('mb_strlen') ? mb_strlen($title) : strlen($title),
        'schemas' => $schemaKeys,
        'images' => $images,
        'imagesMissingAlt' => $missingAlt,
        'imgsWithoutDimensions' => $imgsWithoutDimensions,
        'heroImgWithoutPriority' => $heroImgWithoutPriority,
        'imagesLegacy' => $imagesLegacy,
        'imagesModern' => $imagesModern,
        'internalLinks' => $internal,
        'contextualLinks' => $contextualLinks,
        'chromeLinks' => $chromeLinks,
        'internalTargets' => $internalTargets,
        'wordCount' => $wordCount,
        'mainWordCount' => $mainWordCount,
        'leadExcerpt' => $leadExcerpt,
        'hasFaq' => $hasFaqSchema || $hasFaqDom,
        'dateModified' => ersClip($dateModified, 40),
        'datePublished' => ersClip($datePublished, 40),
        'contentHash' => $contentHash,
        'hreflang' => $hreflang,
        'hasTel' => $hasTel,
        'hasWhatsapp' => $hasWa,
        'hasForm' => $hasForm,
        'hasMailto' => $hasMailto,
        'nap' => dossierFinalizeNap($napAcc),
        'error' => '',
        'fetchedAt' => time(),
    ];
}

/** Agrega NAP del sitio (home preferente) a partir del índice DOM. */
function dossierSiteNap(array $domPages): array
{
    $merged = dossierEmptyNap();
    $order = [];
    if (isset($domPages['/']) && is_array($domPages['/'])) {
        $order[] = '/';
    }
    foreach ($domPages as $path => $row) {
        if ($path !== '/') {
            $order[] = $path;
        }
    }
    foreach ($order as $path) {
        $row = $domPages[$path] ?? null;
        if (!is_array($row) || empty($row['ok'])) {
            continue;
        }
        $nap = is_array($row['nap'] ?? null) ? $row['nap'] : [];
        foreach ($nap['phones'] ?? [] as $tel) {
            $tel = trim((string) $tel);
            if ($tel !== '') {
                $merged['phones'][$tel] = true;
            }
        }
        foreach ($nap['mapsUrls'] ?? [] as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                $merged['mapsUrls'][$url] = true;
            }
        }
        foreach ($nap['sameAs'] ?? [] as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                $merged['sameAs'][$url] = true;
            }
        }
        foreach ($nap['schemaTypes'] ?? [] as $type) {
            $type = trim((string) $type);
            if ($type !== '') {
                $merged['schemaTypes'][$type] = true;
            }
        }
        if (($merged['address'] ?? '') === '' && trim((string) ($nap['address'] ?? '')) !== '') {
            $merged['address'] = (string) $nap['address'];
        }
        if (($merged['locality'] ?? '') === '' && trim((string) ($nap['locality'] ?? '')) !== '') {
            $merged['locality'] = (string) $nap['locality'];
        }
        if (($merged['name'] ?? '') === '' && trim((string) ($nap['name'] ?? '')) !== '') {
            $merged['name'] = (string) $nap['name'];
        }
        if (($merged['geo'] ?? null) === null && is_array($nap['geo'] ?? null)) {
            $merged['geo'] = $nap['geo'];
        }
        if (!empty($nap['hasTelLink'])) {
            $merged['hasTelLink'] = true;
        }
        if (!empty($nap['hasMapsLink'])) {
            $merged['hasMapsLink'] = true;
        }
        // Solo scrapear NAP de home + contacto/servicios; basta con unas pocas páginas.
        if (count($merged['phones']) >= 3 && ($merged['address'] ?? '') !== '') {
            break;
        }
    }
    return dossierFinalizeNap($merged);
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
        $values = is_array($row['dimensionValues'] ?? null) ? $row['dimensionValues'] : [];
        foreach ($dims as $i => $name) {
            $item[$name] = (string) ($values[$i]['value'] ?? '');
        }
        // Con dos rangos la API anexa el nombre del período sin pedirlo en dimensions.
        if (!array_key_exists('dateRange', $item) && isset($values[count($dims)]['value'])) {
            $item['dateRange'] = (string) $values[count($dims)]['value'];
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
                // dateRange no va en dimensions: la Data API lo rechaza y lo anexa sola.
                'dateRanges' => [$current, $previous],
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

/**
 * Top queries de una URL: protege las que ya traen clics y marca oportunidades.
 *
 * @return array{topQueries: array, protectQueries: array, opportunityQueries: array}
 */
function dossierPageQueries(array $gscRow, array $kw, string $path): array
{
    $top = [];
    foreach (array_slice(is_array($gscRow['queries'] ?? null) ? $gscRow['queries'] : [], 0, 8) as $q) {
        if (!is_array($q)) {
            continue;
        }
        $query = trim((string) ($q['query'] ?? $q['q'] ?? ''));
        if ($query === '') {
            continue;
        }
        $top[] = [
            'q' => $query,
            'clicks' => (int) ($q['clicks'] ?? 0),
            'impressions' => (int) ($q['impressions'] ?? 0),
            'ctr' => (float) ($q['ctr'] ?? 0),
            'position' => (float) ($q['position'] ?? 0),
        ];
    }

    $want = dossierNormPath($path);
    $oppIndex = [];
    foreach (['striking', 'lowCtr', 'falling'] as $bucket) {
        foreach ($kw['buckets'][$bucket] ?? [] as $row) {
            if (!is_array($row) || !empty($row['brand'])) {
                continue;
            }
            if (dossierNormPath((string) ($row['page'] ?? '')) !== $want) {
                continue;
            }
            $q = trim((string) ($row['q'] ?? ''));
            if ($q === '') {
                continue;
            }
            $oppIndex[$q] = [
                'q' => $q,
                'bucket' => $bucket,
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
                'gain' => (int) ($row['gain'] ?? $row['ctrGain'] ?? 0),
            ];
        }
    }

    $protect = [];
    foreach ($top as $row) {
        if ((int) $row['clicks'] >= 1) {
            $protect[] = $row;
        }
        if (count($protect) >= 4) {
            break;
        }
    }

    $opportunity = array_values($oppIndex);
    usort($opportunity, static fn ($a, $b) => ((int) $b['gain'] <=> (int) $a['gain']) ?: ((int) $b['impressions'] <=> (int) $a['impressions']));

    return [
        'topQueries' => array_slice($top, 0, 6),
        'protectQueries' => array_slice($protect, 0, 4),
        'opportunityQueries' => array_slice($opportunity, 0, 5),
    ];
}

/**
 * Grafo de inlinks a partir de internalTargets del scrape DOM.
 *
 * @return array<string, array{inlinksTotal:int, inlinksContextual:int, inlinkAnchors:array, inlinkFrom:array}>
 */
function dossierBuildInlinkGraph(array $domPages): array
{
    $graph = [];
    foreach ($domPages as $fromPath => $dom) {
        if (!is_array($dom) || empty($dom['ok'])) {
            continue;
        }
        $fromPath = dossierNormPath((string) $fromPath);
        $targets = is_array($dom['internalTargets'] ?? null) ? $dom['internalTargets'] : [];
        foreach ($targets as $toPath => $meta) {
            $to = dossierNormPath((string) $toPath);
            if ($to === '' || $to === $fromPath) {
                continue;
            }
            if (!isset($graph[$to])) {
                $graph[$to] = [
                    'inlinksTotal' => 0,
                    'inlinksContextual' => 0,
                    'inlinkAnchors' => [],
                    'inlinkFrom' => [],
                ];
            }
            $count = (int) ($meta['count'] ?? 1);
            $contextual = (int) ($meta['contextual'] ?? 0);
            $graph[$to]['inlinksTotal'] += max(1, $count);
            $graph[$to]['inlinksContextual'] += max(0, $contextual);
            $graph[$to]['inlinkFrom'][] = $fromPath;
            $anchor = trim((string) ($meta['sampleAnchor'] ?? ''));
            if ($anchor !== '' && count($graph[$to]['inlinkAnchors']) < 3) {
                $graph[$to]['inlinkAnchors'][] = $anchor;
            }
        }
    }
    foreach ($graph as $path => $row) {
        $graph[$path]['inlinkFrom'] = array_values(array_unique($row['inlinkFrom']));
    }
    return $graph;
}

/**
 * Page SEO Cards para Gemini / informes: señal densa por URL prioritaria.
 *
 * @return list<array<string, mixed>>
 */
function dossierBuildPageCards(array $dossier, array $findings = [], array $kw = [], int $limit = 12): array
{
    $paths = [];
    foreach ($findings as $f) {
        if (!is_array($f) || (int) ($f['severity'] ?? 0) < 2) {
            continue;
        }
        $p = dossierNormPath((string) ($f['path'] ?? ''));
        if ($p !== '') {
            $paths[$p] = ($paths[$p] ?? 0) + ((int) ($f['severity'] ?? 0) * 10) + (int) ($f['impact'] ?? 0);
        }
    }
    $kwSrc = $kw !== [] ? $kw : (is_array($dossier['keywords'] ?? null) ? $dossier['keywords'] : []);
    foreach (['striking', 'lowCtr', 'falling'] as $bucket) {
        foreach ($kwSrc['buckets'][$bucket] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $p = dossierNormPath((string) ($row['page'] ?? ''));
            if ($p === '') {
                continue;
            }
            $paths[$p] = ($paths[$p] ?? 0) + (int) ($row['gain'] ?? $row['ctrGain'] ?? 5) + (int) ($row['impressions'] ?? 0) / 50;
        }
    }
    foreach ($dossier['pages'] ?? [] as $page) {
        if (!is_array($page)) {
            continue;
        }
        $p = dossierNormPath((string) ($page['path'] ?? $page['url'] ?? ''));
        if ($p === '') {
            continue;
        }
        $delta = (int) ($page['deltaClicks'] ?? 0);
        if ($delta <= -3) {
            $paths[$p] = ($paths[$p] ?? 0) + abs($delta) * 3;
        }
        if ((int) ($page['impressions'] ?? 0) >= 80) {
            $paths[$p] = ($paths[$p] ?? 0) + 2;
        }
    }
    if ($paths === []) {
        $paths['/'] = 1;
    }
    arsort($paths);
    $paths = array_slice(array_keys($paths), 0, $limit);

    $byPath = [];
    foreach ($dossier['pages'] ?? [] as $page) {
        if (is_array($page)) {
            $byPath[dossierNormPath((string) ($page['path'] ?? $page['url'] ?? ''))] = $page;
        }
    }
    $dom = is_array($dossier['dom'] ?? null) ? $dossier['dom'] : [];
    $psiPages = is_array($dossier['psi']['pages'] ?? null) ? $dossier['psi']['pages'] : [];
    $findingFiles = [];
    foreach ($findings as $f) {
        if (!is_array($f)) {
            continue;
        }
        $p = dossierNormPath((string) ($f['path'] ?? ''));
        if ($p !== '' && ($f['file'] ?? '') !== '') {
            $findingFiles[$p] = (string) $f['file'];
        }
    }

    $cards = [];
    foreach ($paths as $path) {
        $page = $byPath[$path] ?? ['path' => $path, 'url' => 'https://' . ($dossier['host'] ?? '') . ($path === '/' ? '/' : $path)];
        $domRow = is_array($dom[$path] ?? null) ? $dom[$path] : [];
        $psi = null;
        $url = (string) ($page['url'] ?? '');
        if ($url !== '' && is_array($psiPages[$url] ?? null)) {
            $psi = $psiPages[$url];
        }
        $cards[] = [
            'path' => $path,
            'url' => $url,
            'clicks' => (int) ($page['clicks'] ?? 0),
            'impressions' => (int) ($page['impressions'] ?? 0),
            'ctr' => (float) ($page['ctr'] ?? 0),
            'position' => (float) ($page['position'] ?? 0),
            'deltaClicks' => (int) ($page['deltaClicks'] ?? 0),
            'indexLabel' => (string) ($page['indexLabel'] ?? ''),
            'lastCrawl' => (string) ($page['lastCrawl'] ?? ''),
            'lastmod' => (string) ($page['lastmod'] ?? ''),
            'inlinksContextual' => (int) ($page['inlinksContextual'] ?? 0),
            'inlinksTotal' => (int) ($page['inlinksTotal'] ?? 0),
            'inlinkAnchors' => is_array($page['inlinkAnchors'] ?? null) ? $page['inlinkAnchors'] : [],
            'inlinkFrom' => array_slice(is_array($page['inlinkFrom'] ?? null) ? $page['inlinkFrom'] : [], 0, 5),
            'protectQueries' => is_array($page['protectQueries'] ?? null) ? $page['protectQueries'] : [],
            'opportunityQueries' => is_array($page['opportunityQueries'] ?? null) ? $page['opportunityQueries'] : [],
            'topQueries' => is_array($page['topQueries'] ?? null) ? $page['topQueries'] : [],
            'title' => (string) ($domRow['title'] ?? $page['title'] ?? ''),
            'meta' => (string) ($domRow['meta'] ?? ''),
            'h1' => (string) ($domRow['h1'] ?? ''),
            'h2' => (string) ($domRow['h2'] ?? ''),
            'h3' => (string) ($domRow['h3'] ?? ''),
            'mainWordCount' => (int) ($domRow['mainWordCount'] ?? $domRow['wordCount'] ?? 0),
            'wordCount' => (int) ($domRow['wordCount'] ?? 0),
            'leadExcerpt' => (string) ($domRow['leadExcerpt'] ?? ''),
            'schemas' => is_array($domRow['schemas'] ?? null) ? $domRow['schemas'] : [],
            'hasTel' => !empty($domRow['hasTel']),
            'hasWhatsapp' => !empty($domRow['hasWhatsapp']),
            'hasForm' => !empty($domRow['hasForm']),
            'hasFaq' => !empty($domRow['hasFaq']),
            'redirectCount' => (int) ($domRow['redirectCount'] ?? 0),
            'effectiveUrl' => (string) ($domRow['effectiveUrl'] ?? ''),
            'canonical' => (string) ($domRow['canonical'] ?? ''),
            'xRobots' => (string) ($domRow['xRobots'] ?? ''),
            'psiScore' => $psi['score'] ?? null,
            'targetFile' => (string) ($findingFiles[$path] ?? ''),
        ];
    }
    return $cards;
}

/** Texto compacto de Page Cards para el prompt de Gemini. */
function dossierPageCardsBlock(array $cards): string
{
    if ($cards === []) {
        return 'Sin Page SEO Cards. No inventes el title/H1 actuales.';
    }
    $lines = [];
    foreach ($cards as $i => $c) {
        $n = $i + 1;
        $protect = [];
        foreach ($c['protectQueries'] ?? [] as $q) {
            $protect[] = is_array($q) ? (string) ($q['q'] ?? '') : (string) $q;
        }
        $opp = [];
        foreach ($c['opportunityQueries'] ?? [] as $q) {
            if (!is_array($q)) {
                continue;
            }
            $opp[] = ($q['q'] ?? '') . ' (pos ' . round((float) ($q['position'] ?? 0), 1)
                . ', +' . (int) ($q['gain'] ?? 0) . ' clics pot.)';
        }
        $lines[] = "### Card {$n}: {$c['path']}"
            . "\nURL: " . ($c['url'] ?? '')
            . "\nMétricas 28d: " . (int) ($c['clicks'] ?? 0) . ' clics · '
            . (int) ($c['impressions'] ?? 0) . ' impr · CTR '
            . round(((float) ($c['ctr'] ?? 0)) * 100, 1) . '% · pos '
            . round((float) ($c['position'] ?? 0), 1)
            . ' · delta clics ' . (int) ($c['deltaClicks'] ?? 0)
            . "\nÍndice: " . (($c['indexLabel'] ?? '') !== '' ? $c['indexLabel'] : '—')
            . ' · lastCrawl: ' . (($c['lastCrawl'] ?? '') !== '' ? $c['lastCrawl'] : '—')
            . ' · lastmod: ' . (($c['lastmod'] ?? '') !== '' ? $c['lastmod'] : '—')
            . "\nInlinks contextuales: " . (int) ($c['inlinksContextual'] ?? 0)
            . ' / total ' . (int) ($c['inlinksTotal'] ?? 0)
            . (($c['inlinkFrom'] ?? []) !== [] ? ' · desde ' . implode(', ', array_slice($c['inlinkFrom'], 0, 4)) : '')
            . "\nPROTEGER (no borrar del title/H1): " . ($protect !== [] ? implode(' · ', $protect) : '—')
            . "\nCAPTURAR: " . ($opp !== [] ? implode(' · ', $opp) : '—')
            . "\ntitle: " . ($c['title'] ?? '')
            . "\nmeta: " . ($c['meta'] ?? '')
            . "\nh1: " . ($c['h1'] ?? '')
            . "\nh2: " . ($c['h2'] ?? '')
            . ((($c['h3'] ?? '') !== '') ? "\nh3: " . $c['h3'] : '')
            . "\npalabras main: " . (int) ($c['mainWordCount'] ?? 0)
            . ' (total ' . (int) ($c['wordCount'] ?? 0) . ')'
            . ((($c['leadExcerpt'] ?? '') !== '') ? "\nexcerpt: " . $c['leadExcerpt'] : '')
            . ((($c['schemas'] ?? []) !== []) ? "\nschema: " . implode(', ', array_slice($c['schemas'], 0, 6)) : '')
            . ' · FAQ=' . (!empty($c['hasFaq']) ? 'sí' : 'no')
            . ' · CTA tel/wa/form=' . (!empty($c['hasTel']) ? 'tel ' : '')
            . (!empty($c['hasWhatsapp']) ? 'wa ' : '')
            . (!empty($c['hasForm']) ? 'form' : '—')
            . ((($c['targetFile'] ?? '') !== '') ? "\narchivo: " . $c['targetFile'] : '');
    }
    $lines[] = "\nREGLA ANTI-REGRESIÓN: al reescribir title/H1/meta de una Card, conservá las queries de PROTEGER; sumá CAPTURAR sin sustituir las que ya traen clics.";
    return implode("\n\n", $lines);
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

    $dom = dossierDomIndex($dataDir, $host);
    $inlinkGraph = dossierBuildInlinkGraph($dom['pages'] ?? []);

    $pages = [];
    foreach ($gsc['pages'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $url = (string) ($row['url'] ?? '');
        $path = dossierNormPath($url);
        $qPack = isset($kw['buckets'])
            ? dossierPageQueries($row, $kw, $path)
            : ['topQueries' => [], 'protectQueries' => [], 'opportunityQueries' => []];
        $links = $inlinkGraph[$path] ?? [
            'inlinksTotal' => 0,
            'inlinksContextual' => 0,
            'inlinkAnchors' => [],
            'inlinkFrom' => [],
        ];
        $domRow = is_array($dom['pages'][$path] ?? null) ? $dom['pages'][$path] : [];
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
            'indexLabel' => (string) ($row['indexStatus']['label'] ?? ''),
            'indexReason' => (string) ($row['indexStatus']['reason'] ?? $row['indexStatus']['coverage'] ?? ''),
            'lastCrawl' => (string) ($row['indexStatus']['lastCrawl'] ?? ''),
            'lastmod' => (string) ($row['lastmod'] ?? ''),
            'error' => (string) ($row['error'] ?? ''),
            'thermometer' => (string) ($row['thermometer']['tone'] ?? ''),
            'query' => isset($kw['buckets']) ? dossierQueryForPath($kw, $path) : '',
            'topQueries' => $qPack['topQueries'],
            'protectQueries' => $qPack['protectQueries'],
            'opportunityQueries' => $qPack['opportunityQueries'],
            'inlinksTotal' => (int) ($links['inlinksTotal'] ?? 0),
            'inlinksContextual' => (int) ($links['inlinksContextual'] ?? 0),
            'inlinkAnchors' => $links['inlinkAnchors'] ?? [],
            'inlinkFrom' => $links['inlinkFrom'] ?? [],
            'redirectCount' => (int) ($domRow['redirectCount'] ?? 0),
            'effectiveUrl' => (string) ($domRow['effectiveUrl'] ?? ''),
            'xRobots' => (string) ($domRow['xRobots'] ?? ''),
            'mainWordCount' => (int) ($domRow['mainWordCount'] ?? 0),
            'contentHash' => (string) ($domRow['contentHash'] ?? ''),
            'domFetchedAt' => (int) ($domRow['fetchedAt'] ?? 0),
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
    $repoRaw = gscReadJson(dossierRepoFile($dataDir, $host), []);
    $repoOk = ($repoRaw['host'] ?? '') === $host && ($repoRaw['slug'] ?? '') !== '';
    $repo = [
        'slug' => $repoOk ? (string) $repoRaw['slug'] : '',
        'branch' => $repoOk ? (string) ($repoRaw['branch'] ?? '') : '',
        'commitSha' => $repoOk ? (string) ($repoRaw['commitSha'] ?? '') : '',
        'previousCommitSha' => $repoOk ? (string) ($repoRaw['previousCommitSha'] ?? '') : '',
        'changedFiles' => $repoOk && is_array($repoRaw['changedFiles'] ?? null) ? $repoRaw['changedFiles'] : [],
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

    $siteNap = dossierSiteNap($dom['pages']);
    // GBP solo desde caché en pack (sin red). dossierBuild refresca si allowFetch.
    $gbp = function_exists('gbpPackForDossier')
        ? gbpPackForDossier($dataDir, $host, false, false)
        : ['ok' => false, 'linked' => false];

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
        'periods' => is_array($gsc['periods'] ?? null) ? $gsc['periods'] : [],
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
        'siteNap' => $siteNap,
        'gbp' => $gbp,
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
            'gbp' => !empty($gbp['ok']),
        ],
        'freshness' => dossierFreshnessLedger($dataDir, $host, [
            'domProgress' => [
                'done' => $dom['done'],
                'total' => $dom['total'],
            ],
        ]),
        'gscMeta' => [
            'fullFetchedAt' => (int) ($gsc['fullFetchedAt'] ?? $gsc['fetchedAt'] ?? 0),
            'radarRefreshedAt' => (int) ($gsc['radarRefreshedAt'] ?? 0),
            'isPartialRadar' => !empty($gsc['radarRefresh']),
            'fetchedAt' => (int) ($gsc['fetchedAt'] ?? 0),
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
    $gbp = function_exists('gbpReadCache') ? gbpReadCache($dataDir, $host) : [];
    $windows = dossierWindows($gsc);
    return [
        'v' => DOSSIER_VERSION,
        'gsc' => (int) ($gsc['fullFetchedAt'] ?? $gsc['fetchedAt'] ?? 0),
        'gscRadar' => (int) ($gsc['radarRefreshedAt'] ?? 0),
        'kw' => (int) ($kw['fetchedAt'] ?? 0),
        'ga' => (int) ($ga['fetchedAt'] ?? 0),
        'psi' => dossierPsiStamp(['pages' => $psi['pages'] ?? []]),
        'dom' => (int) ($dom['updatedAt'] ?? 0),
        'repo' => (int) ($repo['fetchedAt'] ?? 0),
        'repoSha' => (string) ($repo['commitSha'] ?? ''),
        'gbp' => (int) ($gbp['fetchedAt'] ?? 0),
        'gbpId' => (string) ($gbp['placeId'] ?? $gbp['place']['placeId'] ?? ''),
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
            // GBP: respeta TTL interno; fresh fuerza Place Details.
            if (function_exists('gbpEnsure')) {
                try {
                    $prefs = dossierHostPrefs($dataDir, $host);
                    if (gscPlaceId((string) ($prefs['placeId'] ?? '')) !== '') {
                        gbpEnsure($dataDir, $host, $fresh);
                    }
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
