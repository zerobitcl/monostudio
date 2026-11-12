<?php
/**
 * Google Business Profile vía Places API (New).
 * Sin OAuth de cliente: solo API Key + placeId vinculado por host.
 *
 * GET  action=status&host=     → ficha cacheada + vínculo
 * GET  action=search&q=&host=  → searchText (sugerencias)
 * POST action=link             → { host, placeId, placeQuery? }
 * POST action=unlink           → { host }
 * POST action=refresh          → { host, fresh?:1 }
 *
 * Credenciales: data/places.json → { "apiKey": "..." }
 * Fallback: la misma clave de data/psi.json si Places API (New) está habilitada.
 */

declare(strict_types=1);

if (!defined('ERS_LIBRARY')) {
    define('ERS_LIBRARY', true);
}

require_once __DIR__ . '/gsc.php';
require_once __DIR__ . '/lib.php';

const GBP_VERSION = '2026.11.12-places-new';
const GBP_TTL = 43200; // 12 h
const GBP_SNAPSHOT_DAYS = 28;
const GBP_SEARCH_LIMIT = 8;

/** FieldMask económico (Pro/Essentials) para searchText. */
const GBP_SEARCH_FIELD_MASK = 'places.id,places.displayName,places.formattedAddress,places.websiteUri,places.googleMapsUri,places.rating,places.userRatingCount,places.primaryTypeDisplayName,places.businessStatus';

/** FieldMask para Place Details (incluye reseñas y horarios). */
const GBP_DETAILS_FIELD_MASK = 'id,displayName,primaryTypeDisplayName,formattedAddress,nationalPhoneNumber,internationalPhoneNumber,websiteUri,googleMapsUri,businessStatus,rating,userRatingCount,regularOpeningHours,photos,reviews';

function gbpCacheFile(string $dataDir, string $host): string
{
    return $dataDir . '/gbp-cache-' . md5($host) . '.json';
}

function gbpSnapFile(string $dataDir, string $host): string
{
    return $dataDir . '/gbp-snap-' . md5($host) . '.json';
}

function gbpApiKey(string $dataDir = ''): string
{
    $dataDir = $dataDir !== '' ? $dataDir : ersDataDir();
    foreach (['places.json', 'psi.json'] as $file) {
        $data = gscReadJson($dataDir . '/' . $file, []);
        foreach (['apiKey', 'api_key', 'key'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '' && !str_starts_with($value, 'PEGAR') && !str_starts_with($value, 'YOUR')) {
                return $value;
            }
        }
    }
    return '';
}

function gbpSanitizePlaceId(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    // Acepta "places/ChIJ..." o el id pelado.
    if (preg_match('#^places/([A-Za-z0-9_-]+)$#', $value, $m)) {
        return $m[1];
    }
    if (preg_match('#^[A-Za-z0-9_-]{10,}$#', $value)) {
        return $value;
    }
    return '';
}

function gbpPhoneDigits(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    // Chile: 9 + móvil sin 56 → anteponer país si parece local.
    if (strlen($digits) === 9 && str_starts_with($digits, '9')) {
        $digits = '56' . $digits;
    }
    if (strlen($digits) === 8) {
        $digits = '56' . $digits;
    }
    return $digits;
}

function gbpPhonesMatch(string $a, string $b): bool
{
    $da = gbpPhoneDigits($a);
    $db = gbpPhoneDigits($b);
    if ($da === '' || $db === '') {
        return false;
    }
    if ($da === $db) {
        return true;
    }
    // Comparar últimos 8–9 dígitos (ignora código de país / prefijo).
    $ta = strlen($da) > 9 ? substr($da, -9) : $da;
    $tb = strlen($db) > 9 ? substr($db, -9) : $db;
    return $ta === $tb || str_ends_with($da, $tb) || str_ends_with($db, $ta);
}

function gbpHostFromUri(string $uri): string
{
    return gscSanitizeHost(gscHost($uri));
}

function gbpWebsiteMatchesHost(string $websiteUri, string $host): bool
{
    if ($websiteUri === '' || $host === '') {
        return false;
    }
    $siteHost = gbpHostFromUri($websiteUri);
    if ($siteHost === '') {
        return false;
    }
    return $siteHost === $host
        || str_ends_with($siteHost, '.' . $host)
        || str_ends_with($host, '.' . $siteHost);
}

/**
 * HTTP contra Places API (New). Usa X-Goog-Api-Key + FieldMask.
 */
function gbpHttp(string $method, string $url, ?array $body, string $apiKey, string $fieldMask, int $timeout = 20): array
{
    $headers = [
        'X-Goog-Api-Key: ' . $apiKey,
        'X-Goog-FieldMask: ' . $fieldMask,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    return gscHttp($method, $url, $body, $headers, $timeout);
}

function gbpNormalizePlace(array $raw, string $placeId = ''): array
{
    $id = $placeId !== '' ? $placeId : gbpSanitizePlaceId((string) ($raw['id'] ?? ''));
    if ($id === '' && isset($raw['name']) && is_string($raw['name'])) {
        $id = gbpSanitizePlaceId((string) $raw['name']);
    }

    $displayName = '';
    if (is_array($raw['displayName'] ?? null)) {
        $displayName = trim((string) ($raw['displayName']['text'] ?? ''));
    } elseif (is_string($raw['displayName'] ?? null)) {
        $displayName = trim((string) $raw['displayName']);
    }

    $hours = [];
    $weekday = $raw['regularOpeningHours']['weekdayDescriptions'] ?? null;
    if (is_array($weekday)) {
        foreach (array_slice($weekday, 0, 7) as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $hours[] = ersClip($line, 120);
            }
        }
    }

    $photosCount = 0;
    if (is_array($raw['photos'] ?? null)) {
        $photosCount = count($raw['photos']);
    }

    $reviews = [];
    foreach (array_slice(is_array($raw['reviews'] ?? null) ? $raw['reviews'] : [], 0, 5) as $rev) {
        if (!is_array($rev)) {
            continue;
        }
        $author = '';
        if (is_array($rev['authorAttribution'] ?? null)) {
            $author = trim((string) ($rev['authorAttribution']['displayName'] ?? ''));
        }
        $text = '';
        if (is_array($rev['text'] ?? null)) {
            $text = trim((string) ($rev['text']['text'] ?? ''));
        } elseif (is_string($rev['text'] ?? null)) {
            $text = trim((string) $rev['text']);
        }
        $rating = (int) ($rev['rating'] ?? 0);
        $relative = trim((string) ($rev['relativePublishTimeDescription'] ?? ''));
        $publishTime = trim((string) ($rev['publishTime'] ?? ''));
        $lang = '';
        if (is_array($rev['text'] ?? null)) {
            $lang = trim((string) ($rev['text']['languageCode'] ?? ''));
        }
        $reviews[] = [
            'author' => ersClip($author, 80),
            'rating' => max(0, min(5, $rating)),
            'text' => ersClip($text, 400),
            'relativeTime' => ersClip($relative, 60),
            'publishTime' => ersClip($publishTime, 40),
            'language' => ersClip($lang, 12),
        ];
    }

    $primaryType = trim((string) ($raw['primaryTypeDisplayName']['text'] ?? $raw['primaryTypeDisplayName'] ?? ''));
    if ($primaryType === '' && is_array($raw['primaryTypeDisplayName'] ?? null)) {
        $primaryType = trim((string) ($raw['primaryTypeDisplayName']['text'] ?? ''));
    }

    return [
        'placeId' => $id,
        'displayName' => ersClip($displayName, 160),
        'primaryTypeDisplayName' => ersClip($primaryType, 120),
        'formattedAddress' => ersClip(trim((string) ($raw['formattedAddress'] ?? '')), 300),
        'nationalPhoneNumber' => ersClip(trim((string) ($raw['nationalPhoneNumber'] ?? '')), 40),
        'internationalPhoneNumber' => ersClip(trim((string) ($raw['internationalPhoneNumber'] ?? '')), 40),
        'websiteUri' => ersClip(trim((string) ($raw['websiteUri'] ?? '')), 500),
        'googleMapsUri' => ersClip(trim((string) ($raw['googleMapsUri'] ?? '')), 500),
        'businessStatus' => ersClip(trim((string) ($raw['businessStatus'] ?? 'OPERATIONAL')), 40),
        'rating' => isset($raw['rating']) ? round((float) $raw['rating'], 1) : null,
        'userRatingCount' => (int) ($raw['userRatingCount'] ?? 0),
        'regularOpeningHours' => $hours,
        'photosCount' => $photosCount,
        'reviews' => $reviews,
    ];
}

function gbpSearchText(string $apiKey, string $query, string $host = '', int $limit = GBP_SEARCH_LIMIT): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $body = [
        'textQuery' => $query,
        'languageCode' => 'es',
        'pageSize' => max(1, min(20, $limit)),
    ];
    // Sesgo regional Chile si el host parece .cl
    if ($host !== '' && str_ends_with($host, '.cl')) {
        $body['regionCode'] = 'CL';
    }

    $res = gbpHttp(
        'POST',
        'https://places.googleapis.com/v1/places:searchText',
        $body,
        $apiKey,
        GBP_SEARCH_FIELD_MASK
    );
    if (!$res['ok']) {
        $msg = is_array($res['data'] ?? null)
            ? (string) (($res['data']['error']['message'] ?? '') ?: ($res['error'] ?? 'Places search falló'))
            : (string) ($res['error'] ?? 'Places search falló');
        throw new RuntimeException(ersClip($msg, 280));
    }

    $out = [];
    foreach (array_slice(is_array($res['data']['places'] ?? null) ? $res['data']['places'] : [], 0, $limit) as $place) {
        if (!is_array($place)) {
            continue;
        }
        $norm = gbpNormalizePlace($place);
        if ($norm['placeId'] === '') {
            continue;
        }
        $norm['websiteMatches'] = gbpWebsiteMatchesHost((string) $norm['websiteUri'], $host);
        $out[] = $norm;
    }
    // Preferir coincidencias de website del host.
    usort($out, static function ($a, $b) {
        $aw = !empty($a['websiteMatches']) ? 1 : 0;
        $bw = !empty($b['websiteMatches']) ? 1 : 0;
        if ($aw !== $bw) {
            return $bw <=> $aw;
        }
        return ((int) ($b['userRatingCount'] ?? 0)) <=> ((int) ($a['userRatingCount'] ?? 0));
    });
    return $out;
}

function gbpFetchDetails(string $apiKey, string $placeId): array
{
    $placeId = gbpSanitizePlaceId($placeId);
    if ($placeId === '') {
        throw new RuntimeException('placeId inválido');
    }
    $url = 'https://places.googleapis.com/v1/places/' . rawurlencode($placeId);
    $res = gbpHttp('GET', $url, null, $apiKey, GBP_DETAILS_FIELD_MASK);
    if (!$res['ok'] || !is_array($res['data'] ?? null)) {
        $msg = is_array($res['data'] ?? null)
            ? (string) (($res['data']['error']['message'] ?? '') ?: ($res['error'] ?? 'Place Details falló'))
            : (string) ($res['error'] ?? 'Place Details falló');
        throw new RuntimeException(ersClip($msg, 280));
    }
    return gbpNormalizePlace($res['data'], $placeId);
}

/**
 * Snapshot histórico: rating + userRatingCount para calcular reviewsDelta del período.
 * Guarda puntos cada refresh; el delta se mide contra el más cercano a hace ~28 días.
 */
function gbpUpdateSnapshot(string $dataDir, string $host, array $place): array
{
    $file = gbpSnapFile($dataDir, $host);
    $snap = gscReadJson($file, ['host' => $host, 'points' => []]);
    if (($snap['host'] ?? '') !== $host) {
        $snap = ['host' => $host, 'points' => []];
    }
    $points = is_array($snap['points'] ?? null) ? $snap['points'] : [];
    $now = time();
    $rating = $place['rating'] ?? null;
    $count = (int) ($place['userRatingCount'] ?? 0);

    // Evitar duplicar puntos del mismo día.
    $today = gmdate('Y-m-d', $now);
    $last = end($points);
    if (is_array($last) && gmdate('Y-m-d', (int) ($last['at'] ?? 0)) === $today) {
        $points[array_key_last($points)] = [
            'at' => $now,
            'rating' => $rating,
            'userRatingCount' => $count,
        ];
    } else {
        $points[] = [
            'at' => $now,
            'rating' => $rating,
            'userRatingCount' => $count,
        ];
    }
    // Conservar ~1 año de puntos diarios.
    if (count($points) > 400) {
        $points = array_slice($points, -400);
    }
    $snap['points'] = array_values($points);
    gscWriteJson($file, $snap);

    $target = $now - (GBP_SNAPSHOT_DAYS * 86400);
    $prev = null;
    foreach ($points as $p) {
        if (!is_array($p)) {
            continue;
        }
        $at = (int) ($p['at'] ?? 0);
        if ($at <= $target) {
            $prev = $p;
        }
    }
    // Si no hay punto viejo, usar el más antiguo disponible (excepto el actual).
    if ($prev === null && count($points) >= 2) {
        $prev = $points[0];
    }

    $prevCount = is_array($prev) ? (int) ($prev['userRatingCount'] ?? 0) : $count;
    $prevRating = is_array($prev) && isset($prev['rating']) ? $prev['rating'] : $rating;
    return [
        'reviewsDelta' => $count - $prevCount,
        'ratingDelta' => ($rating !== null && $prevRating !== null)
            ? round((float) $rating - (float) $prevRating, 2)
            : null,
        'prevUserRatingCount' => $prevCount,
        'prevRating' => $prevRating,
        'prevAt' => is_array($prev) ? (int) ($prev['at'] ?? 0) : 0,
    ];
}

function gbpReadCache(string $dataDir, string $host): array
{
    $cache = gscReadJson(gbpCacheFile($dataDir, $host), []);
    if (($cache['host'] ?? '') !== $host || ($cache['version'] ?? '') !== GBP_VERSION) {
        return [];
    }
    return $cache;
}

/**
 * Refresca Place Details si el TTL venció o $fresh. Requiere placeId en gsc.json.
 */
function gbpEnsure(string $dataDir, string $host, bool $fresh = false): array
{
    $host = gscSanitizeHost($host);
    if ($host === '') {
        return ['ok' => false, 'error' => 'host vacío'];
    }

    $prefs = dossierHostPrefsExists()
        ? dossierHostPrefs($dataDir, $host)
        : gbpHostPrefs($dataDir, $host);
    $placeId = gbpSanitizePlaceId((string) ($prefs['placeId'] ?? ''));
    if ($placeId === '') {
        return [
            'ok' => false,
            'linked' => false,
            'host' => $host,
            'placeId' => '',
            'placeQuery' => (string) ($prefs['placeQuery'] ?? ''),
            'error' => '',
        ];
    }

    $cached = gbpReadCache($dataDir, $host);
    $age = time() - (int) ($cached['fetchedAt'] ?? 0);
    $samePlace = ($cached['place']['placeId'] ?? '') === $placeId;
    if (!$fresh && $samePlace && $cached !== [] && $age < GBP_TTL && !empty($cached['ok'])) {
        $cached['cached'] = true;
        $cached['linked'] = true;
        return $cached;
    }

    $apiKey = gbpApiKey($dataDir);
    if ($apiKey === '') {
        // Devolver caché stale si existe; si no, error de config.
        if ($cached !== [] && !empty($cached['place'])) {
            $cached['cached'] = true;
            $cached['stale'] = true;
            $cached['linked'] = true;
            $cached['error'] = 'Falta API Key de Places (ers/data/places.json o psi.json).';
            return $cached;
        }
        return [
            'ok' => false,
            'linked' => true,
            'host' => $host,
            'placeId' => $placeId,
            'error' => 'Falta API Key de Places (ers/data/places.json o psi.json).',
        ];
    }

    try {
        $place = gbpFetchDetails($apiKey, $placeId);
        $delta = gbpUpdateSnapshot($dataDir, $host, $place);
        $pack = [
            'ok' => true,
            'linked' => true,
            'cached' => false,
            'stale' => false,
            'host' => $host,
            'placeId' => $placeId,
            'placeQuery' => (string) ($prefs['placeQuery'] ?? ''),
            'version' => GBP_VERSION,
            'fetchedAt' => time(),
            'place' => $place,
            'reviewsDelta' => (int) ($delta['reviewsDelta'] ?? 0),
            'ratingDelta' => $delta['ratingDelta'],
            'prevUserRatingCount' => (int) ($delta['prevUserRatingCount'] ?? 0),
            'prevRating' => $delta['prevRating'],
            'prevAt' => (int) ($delta['prevAt'] ?? 0),
            'error' => '',
        ];
        gscWriteJson(gbpCacheFile($dataDir, $host), $pack);
        return $pack;
    } catch (Throwable $e) {
        if ($cached !== [] && !empty($cached['place'])) {
            $cached['cached'] = true;
            $cached['stale'] = true;
            $cached['linked'] = true;
            $cached['error'] = ersClip($e->getMessage(), 280);
            return $cached;
        }
        return [
            'ok' => false,
            'linked' => true,
            'host' => $host,
            'placeId' => $placeId,
            'error' => ersClip($e->getMessage(), 280),
        ];
    }
}

/** Preferencias GBP sin depender de dossier (evita require circular en bootstrap). */
function gbpHostPrefs(string $dataDir, string $host): array
{
    $config = gscNormalizeConfig(gscReadJson($dataDir . '/gsc.json', []));
    $entry = $config['hosts'][$host] ?? [];
    return is_array($entry) ? gscHostEntry($entry) : gscHostEntry([]);
}

function dossierHostPrefsExists(): bool
{
    return function_exists('dossierHostPrefs');
}

/**
 * Empaqueta GBP para el dossier: datos de ficha + deltas.
 * No llama a la red si $allowFetch=false (solo lee caché).
 */
function gbpPackForDossier(string $dataDir, string $host, bool $allowFetch = false, bool $fresh = false): array
{
    $host = gscSanitizeHost($host);
    if ($host === '') {
        return ['ok' => false, 'linked' => false];
    }
    if ($allowFetch) {
        $pack = gbpEnsure($dataDir, $host, $fresh);
    } else {
        $prefs = function_exists('dossierHostPrefs')
            ? dossierHostPrefs($dataDir, $host)
            : gbpHostPrefs($dataDir, $host);
        $placeId = gbpSanitizePlaceId((string) ($prefs['placeId'] ?? ''));
        $cached = gbpReadCache($dataDir, $host);
        if ($placeId === '') {
            return [
                'ok' => false,
                'linked' => false,
                'placeId' => '',
                'placeQuery' => (string) ($prefs['placeQuery'] ?? ''),
            ];
        }
        if ($cached === [] || ($cached['place']['placeId'] ?? '') !== $placeId) {
            return [
                'ok' => false,
                'linked' => true,
                'placeId' => $placeId,
                'placeQuery' => (string) ($prefs['placeQuery'] ?? ''),
                'error' => 'Sin caché de Google Maps. Pulsa Actualizar o vinculá de nuevo.',
            ];
        }
        $pack = $cached;
        $pack['linked'] = true;
    }

    if (empty($pack['ok']) || empty($pack['place'])) {
        return [
            'ok' => false,
            'linked' => !empty($pack['linked']),
            'placeId' => (string) ($pack['placeId'] ?? ''),
            'placeQuery' => (string) ($pack['placeQuery'] ?? ''),
            'error' => (string) ($pack['error'] ?? ''),
            'fetchedAt' => (int) ($pack['fetchedAt'] ?? 0),
        ];
    }

    $place = $pack['place'];
    return [
        'ok' => true,
        'linked' => true,
        'placeId' => (string) ($place['placeId'] ?? $pack['placeId'] ?? ''),
        'placeQuery' => (string) ($pack['placeQuery'] ?? ''),
        'displayName' => (string) ($place['displayName'] ?? ''),
        'primaryTypeDisplayName' => (string) ($place['primaryTypeDisplayName'] ?? ''),
        'formattedAddress' => (string) ($place['formattedAddress'] ?? ''),
        'nationalPhoneNumber' => (string) ($place['nationalPhoneNumber'] ?? ''),
        'internationalPhoneNumber' => (string) ($place['internationalPhoneNumber'] ?? ''),
        'websiteUri' => (string) ($place['websiteUri'] ?? ''),
        'googleMapsUri' => (string) ($place['googleMapsUri'] ?? ''),
        'businessStatus' => (string) ($place['businessStatus'] ?? 'OPERATIONAL'),
        'rating' => $place['rating'] ?? null,
        'userRatingCount' => (int) ($place['userRatingCount'] ?? 0),
        'reviewsDelta' => (int) ($pack['reviewsDelta'] ?? 0),
        'ratingDelta' => $pack['ratingDelta'] ?? null,
        'regularOpeningHours' => is_array($place['regularOpeningHours'] ?? null) ? $place['regularOpeningHours'] : [],
        'photosCount' => (int) ($place['photosCount'] ?? 0),
        'reviews' => is_array($place['reviews'] ?? null) ? $place['reviews'] : [],
        'fetchedAt' => (int) ($pack['fetchedAt'] ?? 0),
        'error' => (string) ($pack['error'] ?? ''),
    ];
}

function gbpSaveLink(string $dataDir, string $host, string $placeId, string $placeQuery = ''): array
{
    $host = gscSanitizeHost($host);
    $placeId = gbpSanitizePlaceId($placeId);
    if ($host === '' || $placeId === '') {
        throw new RuntimeException('Host o placeId inválido');
    }
    $file = $dataDir . '/gsc.json';
    $config = gscNormalizeConfig(gscReadJson($file, []));
    $prev = gscHostEntry(is_array($config['hosts'][$host] ?? null) ? $config['hosts'][$host] : []);
    $prev['placeId'] = $placeId;
    if ($placeQuery !== '') {
        $prev['placeQuery'] = ersClip(trim($placeQuery), 200);
    }
    $config['hosts'][$host] = gscHostEntry($prev);
    if (!gscWriteJson($file, $config)) {
        throw new RuntimeException('No se pudo guardar el vínculo de Google Maps');
    }
    return $config['hosts'][$host];
}

function gbpUnlink(string $dataDir, string $host): array
{
    $host = gscSanitizeHost($host);
    if ($host === '') {
        throw new RuntimeException('Host inválido');
    }
    $file = $dataDir . '/gsc.json';
    $config = gscNormalizeConfig(gscReadJson($file, []));
    $prev = gscHostEntry(is_array($config['hosts'][$host] ?? null) ? $config['hosts'][$host] : []);
    $prev['placeId'] = '';
    $prev['placeQuery'] = '';
    $config['hosts'][$host] = gscHostEntry($prev);
    if (!gscWriteJson($file, $config)) {
        throw new RuntimeException('No se pudo desvincular Google Maps');
    }
    @unlink(gbpCacheFile($dataDir, $host));
    return $config['hosts'][$host];
}

function gbpSuggestQuery(string $host, string $clientName = ''): string
{
    $name = trim($clientName);
    if ($name === '') {
        $name = preg_replace('/\.(cl|com|net|org|io|app)$/i', '', $host) ?? $host;
        $name = str_replace(['-', '_', '.'], ' ', $name);
    }
    $bits = [trim($name)];
    if (str_ends_with($host, '.cl')) {
        $bits[] = 'Chile';
    }
    return trim(implode(' ', array_filter($bits)));
}

function gbpJson(array $payload, int $code = 200): void
{
    gscJson($payload, $code);
}

// --- Router (solo si se invoca como endpoint, no como librería) ---
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    header('X-Content-Type-Options: nosniff');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $dataDir = ersDataDir();
    $action = (string) ($_GET['action'] ?? '');
    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = json_decode((string) file_get_contents('php://input'), true);
        $input = is_array($raw) ? $raw : [];
        if ($action === '' && isset($input['action'])) {
            $action = (string) $input['action'];
        }
    }

    try {
        if ($action === 'status') {
            $host = gscSanitizeHost((string) ($_GET['host'] ?? $input['host'] ?? ''));
            if ($host === '') {
                gbpJson(['error' => 'Indica el dominio del cliente'], 400);
            }
            $fresh = (string) ($_GET['fresh'] ?? '') === '1';
            $pack = gbpEnsure($dataDir, $host, $fresh);
            $keyOk = gbpApiKey($dataDir) !== '';
            gbpJson([
                'ok' => true,
                'apiKeyConfigured' => $keyOk,
                'host' => $host,
                'gbp' => gbpPackForDossier($dataDir, $host, false, false) ?: $pack,
                'raw' => $pack,
            ]);
        }

        if ($action === 'search') {
            $host = gscSanitizeHost((string) ($_GET['host'] ?? $input['host'] ?? ''));
            $q = trim((string) ($_GET['q'] ?? $input['q'] ?? ''));
            if ($q === '' && $host !== '') {
                $clientName = '';
                foreach (ersReadStore()['clients'] ?? [] as $client) {
                    if (!is_array($client)) {
                        continue;
                    }
                    if (gscSanitizeHost(gscHost((string) ($client['siteUrl'] ?? ''))) === $host) {
                        $clientName = trim((string) ($client['name'] ?? ''));
                        break;
                    }
                }
                $q = gbpSuggestQuery($host, $clientName);
            }
            if ($q === '') {
                gbpJson(['error' => 'Escribe el nombre del negocio o una ciudad'], 400);
            }
            $apiKey = gbpApiKey($dataDir);
            if ($apiKey === '') {
                gbpJson(['error' => 'Falta API Key de Places en ers/data/places.json (o psi.json).'], 400);
            }
            $results = gbpSearchText($apiKey, $q, $host);
            gbpJson([
                'ok' => true,
                'query' => $q,
                'host' => $host,
                'results' => $results,
            ]);
        }

        if ($action === 'link') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                gbpJson(['error' => 'Método no permitido'], 405);
            }
            $host = gscSanitizeHost((string) ($input['host'] ?? ''));
            $placeId = gbpSanitizePlaceId((string) ($input['placeId'] ?? ''));
            $placeQuery = trim((string) ($input['placeQuery'] ?? ''));
            if ($host === '' || $placeId === '') {
                gbpJson(['error' => 'Falta host o placeId'], 400);
            }
            $prefs = gbpSaveLink($dataDir, $host, $placeId, $placeQuery);
            $pack = gbpEnsure($dataDir, $host, true);
            gbpJson([
                'ok' => true,
                'host' => $host,
                'placeId' => $prefs['placeId'] ?? $placeId,
                'placeQuery' => $prefs['placeQuery'] ?? $placeQuery,
                'gbp' => gbpPackForDossier($dataDir, $host, false, false),
                'raw' => $pack,
            ]);
        }

        if ($action === 'unlink') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                gbpJson(['error' => 'Método no permitido'], 405);
            }
            $host = gscSanitizeHost((string) ($input['host'] ?? ''));
            if ($host === '') {
                gbpJson(['error' => 'Indica el dominio'], 400);
            }
            gbpUnlink($dataDir, $host);
            gbpJson(['ok' => true, 'host' => $host, 'linked' => false]);
        }

        if ($action === 'refresh') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                gbpJson(['error' => 'Método no permitido'], 405);
            }
            $host = gscSanitizeHost((string) ($input['host'] ?? ''));
            if ($host === '') {
                gbpJson(['error' => 'Indica el dominio'], 400);
            }
            $pack = gbpEnsure($dataDir, $host, true);
            gbpJson([
                'ok' => !empty($pack['ok']),
                'host' => $host,
                'gbp' => gbpPackForDossier($dataDir, $host, false, false),
                'raw' => $pack,
                'error' => (string) ($pack['error'] ?? ''),
            ]);
        }

        gbpJson(['error' => 'Acción no reconocida. Usa status, search, link, unlink o refresh.'], 400);
    } catch (Throwable $e) {
        gbpJson(['error' => $e->getMessage()], 500);
    }
}
