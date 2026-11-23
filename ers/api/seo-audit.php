<?php
/**
 * Auditoría SEO integral → prompt maestro para Cursor.
 *
 * GET  action=status&host=     → progreso DOM + repo + análisis cacheado
 * POST action=dom-batch        → scrape HTML de un lote de URLs del inventario
 * POST action=repo-scan        → lee tree + archivos SEO de un repo GitHub privado
 * POST action=cursor-audit     → plan integral de crecimiento (hallazgos + keywords IA + prompt Cursor)
 * POST action=radiografia      → radiografía forense 7d (métricas×device + git SEO + narrativa)
 * POST action=mark-applied     → check “Hecho” en un cambio del plan
 * POST action=unmark-applied   → quitar el check
 * POST action=verify-applied   → releer HTML y pasar a verified lo que ya no detecta el motor
 * POST action=refresh-context  → refresca GA4/PSI/Repo HEAD + reconstruye dossier
 *
 * Credenciales GitHub: data/github.json → { "token": "ghp_..." }
 */

declare(strict_types=1);

if (!defined('ERS_LIBRARY')) {
    define('ERS_LIBRARY', true);
}

$seoAuditStandalone = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__);
require_once __DIR__ . '/gsc.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/dossier.php';
require_once __DIR__ . '/findings.php';
require_once __DIR__ . '/radiografia.php';

const SEO_AUDIT_DOM_BATCH = 18;
const SEO_AUDIT_DOM_BUDGET = 42;
const SEO_AUDIT_DOM_TTL = 604800; // 7 días: re-scrape si el HTML cambió de firma
const SEO_AUDIT_REPO_TTL = 21600; // 6 h
const SEO_AUDIT_REPO_BYTES = 110000;
const SEO_AUDIT_AI_TIMEOUT = 75;
const SEO_AUDIT_VERSION = '2026.11.21-tuning';

if ($seoAuditStandalone) {
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

$dataDir = ersDataDir();

function seoAuditJson(array $payload, int $code = 200): void
{
    gscJson($payload, $code);
}

function seoAuditDomFile(string $dataDir, string $host): string
{
    return $dataDir . '/seo-dom-' . md5($host) . '.json';
}

function seoAuditRepoFile(string $dataDir, string $host): string
{
    return $dataDir . '/seo-repo-' . md5($host) . '.json';
}

function seoAuditCursorFile(string $dataDir, string $host): string
{
    return $dataDir . '/seo-cursor-' . md5($host) . '.json';
}

function seoAuditAppliedFile(string $dataDir, string $host): string
{
    return $dataDir . '/seo-applied-' . md5($host) . '.json';
}

/** Clave estable de un cambio: findingId si hay, si no hash de titulo|archivo|url. */
function seoAuditCambioKey(array $c): string
{
    $fid = trim((string) ($c['findingId'] ?? ''));
    if ($fid !== '') {
        return ersClip($fid, 200);
    }
    $blob = strtolower(
        trim((string) ($c['titulo'] ?? '')) . '|'
        . trim((string) ($c['archivo'] ?? '')) . '|'
        . trim((string) ($c['url'] ?? ''))
    );
    return md5($blob);
}

function seoAuditReadApplied(string $dataDir, string $host): array
{
    $data = gscReadJson(seoAuditAppliedFile($dataDir, $host), []);
    $items = [];
    $seen = [];
    foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = ersClip(trim((string) ($row['id'] ?? '')), 200);
        if ($id === '' || isset($seen[$id])) {
            continue;
        }
        $status = (string) ($row['status'] ?? 'applied');
        if (!in_array($status, ['applied', 'verified', 'dismissed'], true)) {
            $status = 'applied';
        }
        $seen[$id] = true;
        $items[] = [
            'id' => $id,
            'findingId' => ersClip(trim((string) ($row['findingId'] ?? '')), 200),
            'titulo' => ersClip(trim((string) ($row['titulo'] ?? '')), 160),
            'archivo' => ersClip(trim((string) ($row['archivo'] ?? '')), 260),
            'url' => ersClip(trim((string) ($row['url'] ?? '')), 500),
            'status' => $status,
            'appliedAt' => (int) ($row['appliedAt'] ?? 0),
            'verifiedAt' => (int) ($row['verifiedAt'] ?? 0),
            'commitSha' => ersClip(trim((string) ($row['commitSha'] ?? '')), 64),
        ];
    }
    return ['host' => $host, 'items' => $items];
}

function seoAuditWriteApplied(string $dataDir, string $host, array $items): array
{
    $payload = [
        'host' => $host,
        'items' => array_values(array_slice($items, -200)),
        'updatedAt' => time(),
    ];
    gscWriteJson(seoAuditAppliedFile($dataDir, $host), $payload);
    return $payload;
}

/** Mapas rápidos: por id de cambio y por findingId → status. */
function seoAuditAppliedMaps(string $dataDir, string $host): array
{
    $byId = [];
    $findingStatus = [];
    foreach (seoAuditReadApplied($dataDir, $host)['items'] as $item) {
        $byId[$item['id']] = $item;
        if (!in_array($item['status'], ['applied', 'verified'], true)) {
            continue;
        }
        $findingStatus[$item['id']] = $item['status'];
        if ($item['findingId'] !== '') {
            $findingStatus[$item['findingId']] = $item['status'];
        }
    }
    return ['byId' => $byId, 'findingStatus' => $findingStatus];
}

/** Quita del prompt los hallazgos ya marcados hechos o verificados. */
function seoAuditFilterFindings(array $findings, string $dataDir, string $host): array
{
    $maps = seoAuditAppliedMaps($dataDir, $host);
    if ($maps['findingStatus'] === []) {
        return $findings;
    }
    $out = [];
    foreach ($findings as $f) {
        if (!is_array($f)) {
            continue;
        }
        $id = (string) ($f['id'] ?? '');
        if ($id !== '' && isset($maps['findingStatus'][$id])) {
            continue;
        }
        $out[] = $f;
    }
    return $out;
}

function seoAuditAnnotateCambios(array $cambios, string $dataDir, string $host): array
{
    $maps = seoAuditAppliedMaps($dataDir, $host);
    $out = [];
    foreach ($cambios as $c) {
        if (!is_array($c)) {
            continue;
        }
        $key = seoAuditCambioKey($c);
        $hit = $maps['byId'][$key] ?? null;
        if ($hit === null) {
            $fid = trim((string) ($c['findingId'] ?? ''));
            if ($fid !== '') {
                $hit = $maps['byId'][$fid] ?? null;
            }
        }
        $c['key'] = $key;
        $c['status'] = is_array($hit) ? (string) ($hit['status'] ?? '') : '';
        $c['appliedAt'] = is_array($hit) ? (int) ($hit['appliedAt'] ?? 0) : 0;
        $c['verifiedAt'] = is_array($hit) ? (int) ($hit['verifiedAt'] ?? 0) : 0;
        $out[] = $c;
    }
    return $out;
}

function seoAuditAppliedBlock(string $dataDir, string $host): string
{
    $items = seoAuditReadApplied($dataDir, $host)['items'];
    $applied = [];
    $verified = [];
    foreach ($items as $item) {
        if ($item['status'] === 'verified') {
            $verified[] = $item;
        } elseif ($item['status'] === 'applied') {
            $applied[] = $item;
        }
    }
    if ($applied === [] && $verified === []) {
        return 'Ninguna mejora marcada como hecha todavía.';
    }
    $lines = [];
    if ($verified !== []) {
        $lines[] = 'Ya verificadas (NO las vuelvas a pedir):';
        foreach (array_slice($verified, -12) as $item) {
            $lines[] = '- ' . $item['titulo']
                . ($item['findingId'] !== '' ? ' [' . $item['findingId'] . ']' : '');
        }
    }
    if ($applied !== []) {
        $lines[] = 'Marcadas hechas, pendientes de verificar en HTML/GSC (NO reimplementar; como máximo una nota de deploy/medición pendiente):';
        foreach (array_slice($applied, -12) as $item) {
            $lines[] = '- ' . $item['titulo'];
        }
    }
    return implode("\n", $lines);
}

/**
 * Mejoras que el motor no puede comprobar solo (Maps, fotos, reseñas, sin URL/archivo).
 * Quedan en “Hecho” hasta confirmación humana; nunca pasan solas a verified.
 */
function seoAuditIsManualOnly(array $item): bool
{
    $blob = strtolower(
        trim((string) ($item['titulo'] ?? '')) . ' '
        . trim((string) ($item['archivo'] ?? '')) . ' '
        . trim((string) ($item['url'] ?? '')) . ' '
        . trim((string) ($item['findingId'] ?? ''))
    );
    foreach ([
        'google business', 'business profile', 'google maps', 'gbp',
        'ficha de google', 'mi negocio', 'fotograf', 'photo', 'reseñ', 'review',
        'maps.google', 'abrir en maps',
    ] as $needle) {
        if ($needle !== '' && str_contains($blob, $needle)) {
            return true;
        }
    }
    return trim((string) ($item['url'] ?? '')) === ''
        && trim((string) ($item['archivo'] ?? '')) === '';
}

/**
 * Deshace “Comprobado” falso: verified sin findingId no debió existir
 * (el detector no tenía evidencia; p. ej. fotos en GBP).
 */
function seoAuditRepairFalseVerified(string $dataDir, string $host): int
{
    $pack = seoAuditReadApplied($dataDir, $host);
    $fixed = 0;
    foreach ($pack['items'] as &$item) {
        if (($item['status'] ?? '') !== 'verified') {
            continue;
        }
        $fid = trim((string) ($item['findingId'] ?? ''));
        if ($fid !== '' && !seoAuditIsManualOnly($item)) {
            continue;
        }
        $item['status'] = 'applied';
        $item['verifiedAt'] = 0;
        $fixed += 1;
    }
    unset($item);
    if ($fixed > 0) {
        seoAuditWriteApplied($dataDir, $host, $pack['items']);
    }
    return $fixed;
}

/**
 * Solo verifica con evidencia: findingId conocido que ya no dispara el detector.
 * Sin findingId → intenta reatachar; si no hay match, sigue en applied (no inventa “comprobado”).
 */
function seoAuditAutoVerify(string $dataDir, string $host, array $currentFindings): int
{
    $present = [];
    foreach ($currentFindings as $f) {
        $id = is_array($f) ? (string) ($f['id'] ?? '') : '';
        if ($id !== '') {
            $present[$id] = true;
        }
    }
    $pack = seoAuditReadApplied($dataDir, $host);
    $verified = 0;
    $dirty = false;
    foreach ($pack['items'] as &$item) {
        if (($item['status'] ?? '') !== 'applied') {
            continue;
        }
        if (seoAuditIsManualOnly($item)) {
            continue;
        }
        $fid = trim((string) ($item['findingId'] ?? ''));
        if ($fid === '') {
            $matched = seoAuditMatchFindingId($item, $currentFindings);
            if ($matched !== '') {
                $item['findingId'] = $matched;
                $item['id'] = seoAuditCambioKey($item);
                $dirty = true;
            }
            // Sin id estable no hay prueba de que el problema desapareció.
            continue;
        }
        if (isset($present[$fid])) {
            continue;
        }
        $item['status'] = 'verified';
        $item['verifiedAt'] = time();
        $verified += 1;
        $dirty = true;
    }
    unset($item);
    if ($dirty) {
        seoAuditWriteApplied($dataDir, $host, $pack['items']);
    }
    return $verified;
}

function seoAuditMatchFindingId(array $c, array $findings): string
{
    $url = trim((string) ($c['url'] ?? ''));
    $path = $url !== '' ? (string) (parse_url($url, PHP_URL_PATH) ?: '/') : '';
    $pathNorm = strtolower(rtrim($path, '/') ?: '/');
    $titulo = strtolower(trim((string) ($c['titulo'] ?? '')));
    $archivo = strtolower(trim((string) ($c['archivo'] ?? '')));
    $best = '';
    $bestScore = 0;
    foreach ($findings as $f) {
        if (!is_array($f)) {
            continue;
        }
        $score = 0;
        $fUrl = (string) ($f['url'] ?? '');
        $fPath = strtolower(rtrim((string) ($f['path'] ?? ''), '/') ?: '/');
        $fTitle = strtolower((string) ($f['lines']['es']['title'] ?? ''));
        $fFile = strtolower((string) ($f['file'] ?? ''));
        $kind = strtolower((string) ($f['kind'] ?? ''));
        if ($url !== '' && $fUrl === $url) {
            $score += 5;
        }
        if ($pathNorm !== '/' && $fPath === $pathNorm) {
            $score += 3;
        }
        if ($titulo !== '' && $fTitle !== '' && (str_contains($titulo, $fTitle) || str_contains($fTitle, $titulo))) {
            $score += 4;
        }
        if ($kind !== '' && $titulo !== '' && str_contains($titulo, $kind)) {
            $score += 2;
        }
        if ($archivo !== '' && $fFile !== '' && ($archivo === $fFile || str_ends_with($archivo, $fFile) || str_ends_with($fFile, $archivo))) {
            $score += 3;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = (string) ($f['id'] ?? '');
        }
    }
    return $bestScore >= 3 ? $best : '';
}

function seoAuditAttachFindingIds(array $cambios, array $findings): array
{
    $out = [];
    foreach ($cambios as $c) {
        if (!is_array($c)) {
            continue;
        }
        if (trim((string) ($c['findingId'] ?? '')) === '') {
            $c['findingId'] = seoAuditMatchFindingId($c, $findings);
        }
        $c['key'] = seoAuditCambioKey($c);
        $out[] = $c;
    }
    return $out;
}

function seoAuditMarkApplied(string $dataDir, string $host, array $raw, string $commitSha = ''): array
{
    $titulo = ersClip(trim((string) ($raw['titulo'] ?? '')), 160);
    if ($titulo === '') {
        throw new RuntimeException('Falta el título del cambio.');
    }
    $item = [
        'id' => '',
        'findingId' => ersClip(trim((string) ($raw['findingId'] ?? '')), 200),
        'titulo' => $titulo,
        'archivo' => ersClip(trim((string) ($raw['archivo'] ?? '')), 260),
        'url' => ersClip(trim((string) ($raw['url'] ?? '')), 500),
        'status' => 'applied',
        'appliedAt' => time(),
        'verifiedAt' => 0,
        'commitSha' => ersClip($commitSha !== '' ? $commitSha : trim((string) ($raw['commitSha'] ?? '')), 64),
    ];
    if ($item['findingId'] === '') {
        $dossier = dossierRead($dataDir, $host);
        if (!empty($dossier['ok'])) {
            $item['findingId'] = seoAuditMatchFindingId($item, findingsDetect($dossier));
        }
    }
    if (trim((string) ($raw['key'] ?? '')) !== '') {
        $item['id'] = ersClip(trim((string) $raw['key']), 200);
    } else {
        $item['id'] = seoAuditCambioKey($item);
    }
    $pack = seoAuditReadApplied($dataDir, $host);
    $items = [];
    $replaced = false;
    foreach ($pack['items'] as $row) {
        if ($row['id'] === $item['id'] || ($item['findingId'] !== '' && $row['findingId'] === $item['findingId'])) {
            $item['appliedAt'] = (int) ($row['appliedAt'] ?? time()) ?: time();
            $items[] = $item;
            $replaced = true;
            continue;
        }
        $items[] = $row;
    }
    if (!$replaced) {
        $items[] = $item;
    }
    seoAuditWriteApplied($dataDir, $host, $items);
    return ['ok' => true, 'host' => $host, 'item' => $item, 'items' => seoAuditReadApplied($dataDir, $host)['items']];
}

function seoAuditUnmarkApplied(string $dataDir, string $host, string $key): array
{
    $key = ersClip(trim($key), 200);
    if ($key === '') {
        throw new RuntimeException('Falta la clave del cambio.');
    }
    $pack = seoAuditReadApplied($dataDir, $host);
    $items = array_values(array_filter(
        $pack['items'],
        static fn (array $row): bool => $row['id'] !== $key && $row['findingId'] !== $key
    ));
    seoAuditWriteApplied($dataDir, $host, $items);
    return ['ok' => true, 'host' => $host, 'items' => $items];
}

function seoAuditVerifyApplied(string $dataDir, string $host): array
{
    @set_time_limit(55);
    $repaired = seoAuditRepairFalseVerified($dataDir, $host);
    $pack = seoAuditReadApplied($dataDir, $host);
    $pendingBefore = 0;
    $manualBefore = 0;
    $urls = [];
    foreach ($pack['items'] as $item) {
        if (($item['status'] ?? '') !== 'applied') {
            continue;
        }
        $pendingBefore += 1;
        if (seoAuditIsManualOnly($item)) {
            $manualBefore += 1;
            continue;
        }
        $url = trim((string) ($item['url'] ?? ''));
        if ($url !== '' && gscUrlBelongsToHost($url, $host)) {
            $urls[] = $url;
        }
    }
    if ($pendingBefore === 0 && $repaired === 0) {
        return [
            'ok' => true,
            'host' => $host,
            'touched' => 0,
            'autoVerified' => 0,
            'repaired' => 0,
            'verified' => 0,
            'pending' => 0,
            'manual' => 0,
            'items' => $pack['items'],
            'findingCounts' => [],
            'note' => 'No hay mejoras en estado “Hecho” para verificar. Marcá el check primero.',
        ];
    }
    $touched = 0;
    if ($urls !== []) {
        $touch = seoAuditTouchDom($dataDir, $host, array_values(array_unique($urls)));
        $touched = (int) ($touch['touched'] ?? count($urls));
    }
    // HTML nuevo debe invalidar el dossier empaquetado para no leer sellos viejos.
    seoAuditInvalidateDossier($dataDir, $host);
    // Solo relee cachés (HTML tocado arriba). Evita el dossierBuild completo que corta por timeout.
    $dossier = dossierRead($dataDir, $host);
    $findings = !empty($dossier['ok']) ? findingsDetect($dossier) : [];
    $auto = seoAuditAutoVerify($dataDir, $host, $findings);
    $items = seoAuditReadApplied($dataDir, $host)['items'];
    $verified = 0;
    $pending = 0;
    $manual = 0;
    foreach ($items as $item) {
        if ($item['status'] === 'verified') {
            $verified += 1;
        } elseif ($item['status'] === 'applied') {
            $pending += 1;
            if (seoAuditIsManualOnly($item)) {
                $manual += 1;
            }
        }
    }
    $note = '';
    if ($repaired > 0) {
        $note = $repaired === 1
            ? 'Se revirtió 1 “Comprobado” sin evidencia (p. ej. Maps/manual). Quedó en Hecho.'
            : "Se revirtieron {$repaired} “Comprobado” sin evidencia. Quedaron en Hecho.";
    }
    if ($manual > 0 && $auto === 0) {
        $manualNote = $manual === 1
            ? '1 mejora es manual/Maps: el sistema no puede comprobarla solo. Dejala en Hecho o desmarcá el check.'
            : "{$manual} mejoras son manuales/Maps: el sistema no puede comprobarlas solo.";
        $note = $note !== '' ? $note . ' ' . $manualNote : $manualNote;
    }

    return [
        'ok' => true,
        'host' => $host,
        'touched' => $touched,
        'autoVerified' => $auto,
        'repaired' => $repaired,
        'verified' => $verified,
        'pending' => $pending,
        'manual' => $manual,
        'items' => $items,
        'findingCounts' => findingsCounts(seoAuditFilterFindings($findings, $dataDir, $host)),
        'note' => $note,
    ];
}

function seoAuditLoadGithubConfig(): array
{
    $data = gscReadJson(ersDataDir() . '/github.json', []);
    $token = trim((string) ($data['token'] ?? $data['apiKey'] ?? $data['pat'] ?? ''));
    if ($token !== '' && (str_starts_with($token, 'PEGAR') || str_starts_with($token, 'YOUR'))) {
        $token = '';
    }
    return ['token' => $token];
}

/** Limpia basura invisible de pastes (ZWSP, BOM, NBSP, barras unicode, etc.). */
function seoAuditCleanRepoRaw(string $raw): string
{
    $raw = trim($raw);
    // BOM / zero-width / bidi marks
    $raw = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{2060}\x{00AD}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $raw) ?? $raw;
    // NBSP y espacios raros → espacio normal, luego trim
    $raw = preg_replace('/[\x{00A0}\x{202F}\x{2000}-\x{200A}\x{205F}\x{3000}]+/u', ' ', $raw) ?? $raw;
    // Barras que se ven igual a "/" pero rompen el regex ASCII
    $raw = str_replace(
        ["\u{2215}", "\u{2044}", "\u{FF0F}", "\u{29F8}", "\\"],
        ['/', '/', '/', '/', ''],
        $raw
    );
    $raw = trim($raw);
    // Comillas tipográficas / wraps al pegar
    $raw = trim($raw, " \t\n\r\0\x0B\"'`“”‘’");
    return $raw;
}

/** Normaliza owner/repo desde URL o slug. */
function seoAuditParseRepo(string $raw): array
{
    $raw = seoAuditCleanRepoRaw($raw);
    if ($raw === '') {
        return ['owner' => '', 'repo' => '', 'slug' => ''];
    }
    // Acepta URL completa, ssh y slug. Extrae el primer owner/repo razonable.
    // Delimitador ~ (no #): el # dentro de [^?#] rompía el patrón y toda URL fallaba.
    if (preg_match('~(?:github\.com[/:]|git@github\.com:)([^/\s?#]+)/([^/\s?#]+)~i', $raw, $m)
        || preg_match('~^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/?~', $raw, $m)
    ) {
        $owner = $m[1];
        $repo = preg_replace('~\.git$~i', '', $m[2]) ?? $m[2];
        $repo = rtrim($repo, '/');
    } else {
        return ['owner' => '', 'repo' => '', 'slug' => ''];
    }
    $owner = trim($owner);
    $repo = trim($repo);
    if ($owner === '' || $repo === '' || !preg_match('~^[A-Za-z0-9_.-]+$~', $owner) || !preg_match('~^[A-Za-z0-9_.-]+$~', $repo)) {
        return ['owner' => '', 'repo' => '', 'slug' => ''];
    }
    return ['owner' => $owner, 'repo' => $repo, 'slug' => $owner . '/' . $repo];
}

/** Repo de la ficha del cliente (store) para este host. */
function seoAuditRepoFromStore(string $host): string
{
    if ($host === '') {
        return '';
    }
    $store = gscReadJson(ersStorePath(), []);
    foreach ($store['clients'] ?? [] as $client) {
        if (!is_array($client)) {
            continue;
        }
        $clientHost = gscSanitizeHost((string) ($client['siteUrl'] ?? ''));
        if ($clientHost === $host) {
            return seoAuditCleanRepoRaw((string) ($client['githubRepo'] ?? ''));
        }
    }
    return '';
}

/**
 * Elige un repo parseable: request primero, si falla cae a la ficha.
 * Devuelve el slug owner/repo (no la URL cruda) para el resto del flujo.
 */
function seoAuditResolveRepo(string $host, string $repoRaw): string
{
    $candidates = [
        seoAuditCleanRepoRaw($repoRaw),
        seoAuditRepoFromStore($host),
    ];
    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        $parsed = seoAuditParseRepo($candidate);
        if ($parsed['slug'] !== '') {
            return $parsed['slug'];
        }
    }
    // Devolver crudo limpio para que el error muestre qué llegó.
    return seoAuditCleanRepoRaw($repoRaw) !== ''
        ? seoAuditCleanRepoRaw($repoRaw)
        : seoAuditRepoFromStore($host);
}

function seoAuditInventoryUrls(array $siteCache, string $host): array
{
    $urls = [];
    foreach ($siteCache['pages'] ?? [] as $row) {
        $url = trim((string) ($row['url'] ?? ''));
        if ($url === '' || !gscUrlBelongsToHost($url, $host)) {
            continue;
        }
        $urls[$url] = true;
    }
    return array_keys($urls);
}

/** El scrape vive en el dossier: una sola lectura para informe y auditoría. */
function seoAuditFetchDom(string $url): array
{
    return dossierFetchDom($url);
}
function seoAuditDomCache(string $dataDir, string $host): array
{
    $cache = gscReadJson(seoAuditDomFile($dataDir, $host), []);
    if (($cache['host'] ?? '') !== $host) {
        return [
            'host' => $host,
            'version' => SEO_AUDIT_VERSION,
            'pages' => [],
            'updatedAt' => 0,
        ];
    }
    if (!is_array($cache['pages'] ?? null)) {
        $cache['pages'] = [];
    }
    return $cache;
}

function seoAuditDomProgress(array $domCache, array $urls): array
{
    $pages = $domCache['pages'] ?? [];
    $done = 0;
    $failed = 0;
    $pending = [];
    $now = time();
    foreach ($urls as $url) {
        $row = $pages[$url] ?? null;
        // mainWordCount marca el scrape profundo 2026.11.21; sin eso hay que releer.
        $fresh = is_array($row)
            && array_key_exists('h1Count', $row)
            && array_key_exists('mainWordCount', $row)
            && (($row['fetchedAt'] ?? 0) > 0)
            && ($now - (int) $row['fetchedAt']) < SEO_AUDIT_DOM_TTL;
        if ($fresh) {
            $done += 1;
            if (empty($row['ok'])) {
                $failed += 1;
            }
        } else {
            $pending[] = $url;
        }
    }
    $total = count($urls);
    return [
        'done' => $done,
        'failed' => $failed,
        'total' => $total,
        'remaining' => count($pending),
        'complete' => $total > 0 && $pending === [],
        'pending' => $pending,
        'pct' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
    ];
}

function seoAuditRunDomBatch(string $dataDir, string $host, int $limit = SEO_AUDIT_DOM_BATCH, bool $fresh = false): array
{
    $site = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    if (($site['host'] ?? '') !== $host || !is_array($site['pages'] ?? null)) {
        throw new RuntimeException('Primero actualizá el SEO del sitio (botón Actualizar) para armar el inventario de URLs.');
    }

    $urls = seoAuditInventoryUrls($site, $host);
    if ($urls === []) {
        throw new RuntimeException('El inventario del sitio no tiene URLs para scrapear.');
    }

    $cache = seoAuditDomCache($dataDir, $host);
    if ($fresh) {
        // Reinicia el lote: el operador pidió releer el HTML del inventario actual.
        $cache['pages'] = [];
        $cache['updatedAt'] = 0;
    }
    $progress = seoAuditDomProgress($cache, $urls);
    $pending = $progress['pending'];
    $batch = array_slice($pending, 0, max(1, min($limit, SEO_AUDIT_DOM_BATCH)));

    @set_time_limit(SEO_AUDIT_DOM_BUDGET + 20);
    $deadline = microtime(true) + SEO_AUDIT_DOM_BUDGET;
    $scraped = 0;

    foreach ($batch as $url) {
        if (gscTimeLeft($deadline) < 4) {
            break;
        }
        $cache['pages'][$url] = seoAuditFetchDom($url);
        $scraped += 1;
    }

    $cache['host'] = $host;
    $cache['version'] = SEO_AUDIT_VERSION;
    $cache['updatedAt'] = time();
    $cache['inventorySig'] = md5(implode('|', $urls));
    gscWriteJson(seoAuditDomFile($dataDir, $host), $cache);

    $after = seoAuditDomProgress($cache, $urls);
    return [
        'ok' => true,
        'host' => $host,
        'scraped' => $scraped,
        'done' => $after['done'],
        'failed' => $after['failed'],
        'total' => $after['total'],
        'remaining' => $after['remaining'],
        'complete' => $after['complete'],
        'pct' => $after['pct'],
        'partial' => !$after['complete'],
        'updatedAt' => $cache['updatedAt'],
    ];
}

/** Relee solo las URLs del plan anterior. El resto del HTML sigue en caché. */
function seoAuditTouchDom(string $dataDir, string $host, array $urls): array
{
    $cache = seoAuditDomCache($dataDir, $host);
    $pick = [];
    foreach ($urls as $url) {
        $url = trim((string) $url);
        if ($url === '' || !gscUrlBelongsToHost($url, $host)) {
            continue;
        }
        $pick[$url] = true;
        if (count($pick) >= 8) {
            break;
        }
    }
    if ($pick === []) {
        return ['ok' => true, 'host' => $host, 'touched' => 0, 'updatedAt' => (int) ($cache['updatedAt'] ?? 0)];
    }

    @set_time_limit(40);
    $deadline = microtime(true) + 24;
    $n = 0;
    foreach (array_keys($pick) as $url) {
        if (gscTimeLeft($deadline) < 3) {
            break;
        }
        $cache['pages'][$url] = seoAuditFetchDom($url);
        $n += 1;
    }
    $cache['host'] = $host;
    $cache['version'] = SEO_AUDIT_VERSION;
    $cache['updatedAt'] = time();
    gscWriteJson(seoAuditDomFile($dataDir, $host), $cache);

    return [
        'ok' => true,
        'host' => $host,
        'touched' => $n,
        'updatedAt' => $cache['updatedAt'],
    ];
}

function seoAuditGithubHttp(string $method, string $url, string $token, int $timeout = 25): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: MonoStudio-SeoAudit',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('GitHub: ' . ($err ?: 'sin respuesta'));
        }
        $data = json_decode($raw, true);
        return ['code' => $code, 'data' => is_array($data) ? $data : null, 'raw' => $raw];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException('GitHub: sin respuesta');
    }
    $data = json_decode($raw, true);
    // Sin curl no hay status confiable; si parsea JSON útil asumimos 200.
    $code = is_array($data) ? 200 : 502;
    return ['code' => $code, 'data' => is_array($data) ? $data : null, 'raw' => $raw];
}

/** SHA del HEAD. Vacío si GitHub no responde: el llamador conserva la caché. */
function seoAuditGithubHead(string $owner, string $repo, string $branch, string $token): string
{
    $branch = trim($branch);
    if ($owner === '' || $repo === '' || $branch === '' || $token === '') {
        return '';
    }
    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/git/ref/heads/' . rawurlencode($branch);
    $res = seoAuditGithubHttp('GET', $url, $token, 15);
    if ($res['code'] >= 400 || !is_array($res['data'])) {
        return '';
    }
    return (string) ($res['data']['object']['sha'] ?? '');
}

/** Rutas tocadas entre dos commits. Sin parches: el contenido se baja aparte. */
function seoAuditGithubChangedPaths(string $owner, string $repo, string $base, string $head, string $token): array
{
    if ($owner === '' || $repo === '' || $base === '' || $head === '' || $base === $head || $token === '') {
        return [];
    }
    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/compare/' . rawurlencode($base) . '...' . rawurlencode($head);
    $res = seoAuditGithubHttp('GET', $url, $token, 20);
    if ($res['code'] >= 400 || !is_array($res['data'])) {
        return [];
    }
    $paths = [];
    foreach ($res['data']['files'] ?? [] as $file) {
        if (!is_array($file)) {
            continue;
        }
        $name = str_replace('\\', '/', (string) ($file['filename'] ?? ''));
        if ($name === '' || isset($paths[$name])) {
            continue;
        }
        $paths[$name] = true;
        if (count($paths) >= 40) {
            break;
        }
    }
    return array_keys($paths);
}

function seoAuditIsTextPath(string $path): bool
{
    $lower = strtolower(str_replace('\\', '/', $path));
    if (preg_match('#(^|/)(node_modules|dist|build|\.next|coverage|vendor|\.git)/#', $lower)) {
        return false;
    }
    if (preg_match('#\.(png|jpe?g|gif|webp|avif|ico|svg|woff2?|ttf|eot|mp4|pdf|zip|gz|lock)$#', $lower)) {
        return false;
    }
    return (bool) preg_match('#\.(tsx?|jsx?|vue|svelte|astro|php|html?|css|scss|json|md|txt|xml|toml|ya?ml)$#', $lower);
}

/** Archivos que el plan anterior le pidió a Cursor: hay que releer su contenido actual. */
function seoAuditWantedPaths(string $dataDir, string $host): array
{
    $cursor = gscReadJson(seoAuditCursorFile($dataDir, $host), []);
    $set = [];
    foreach ($cursor['insights']['cambios'] ?? [] as $cambio) {
        if (!is_array($cambio)) {
            continue;
        }
        $file = strtolower(str_replace('\\', '/', trim((string) ($cambio['archivo'] ?? ''))));
        if ($file !== '') {
            $set[$file] = true;
        }
    }
    return $set;
}

function seoAuditPathWanted(string $path, array $wanted): bool
{
    if ($wanted === []) {
        return false;
    }
    $key = strtolower(str_replace('\\', '/', $path));
    if (isset($wanted[$key])) {
        return true;
    }
    foreach ($wanted as $w => $_) {
        if ($w !== '' && (str_ends_with($key, '/' . $w) || str_ends_with($w, '/' . $key))) {
            return true;
        }
    }
    return false;
}

function seoAuditIsSeoPath(string $path): bool
{
    $path = str_replace('\\', '/', $path);
    $base = strtolower(basename($path));
    $lower = strtolower($path);

    if (preg_match('#(^|/)\.(git|github|vscode|idea|husky)/#', $lower)) {
        return false;
    }
    if (preg_match('#(^|/)(node_modules|dist|build|\.next|coverage|vendor)/#', $lower)) {
        return false;
    }

    $exact = [
        'package.json', 'robots.txt', 'sitemap.xml', 'sitemap_index.xml',
        'next.config.js', 'next.config.mjs', 'next.config.ts',
        'nuxt.config.js', 'nuxt.config.ts', 'astro.config.mjs', 'astro.config.ts',
        'vite.config.js', 'vite.config.ts', 'index.html', 'app.html',
        'gatsby-config.js', 'gatsby-config.ts', 'hugo.toml', 'hugo.yaml',
        'vercel.json', 'netlify.toml', '_headers',
    ];
    if (in_array($base, $exact, true)) {
        return true;
    }

    if (preg_match('#(seo|metadata|meta|robots|sitemap|canonical|schema|json-ld|structured)#i', $lower)) {
        return true;
    }
    if (preg_match('#(^|/)(app|src|pages|layouts?|components?)/(layout|root-layout|head|seo|meta)#i', $lower)) {
        return true;
    }
    if (preg_match('#(^|/)(app|src)/.*\.(tsx?|jsx?|vue|svelte|astro)$#i', $lower)
        && preg_match('#(layout|page|head|template|document)#i', $base)) {
        return true;
    }
    return false;
}

function seoAuditScorePath(string $path): int
{
    $lower = strtolower($path);
    $base = strtolower(basename($path));
    $score = 10;
    if (in_array($base, ['package.json', 'robots.txt', 'sitemap.xml'], true)) {
        $score += 40;
    }
    if (preg_match('#next\.config|nuxt\.config|astro\.config#', $lower)) {
        $score += 30;
    }
    if (preg_match('#seo|metadata|meta\.|robots#', $lower)) {
        $score += 25;
    }
    if (preg_match('#layout\.(tsx?|jsx?|vue|astro)$#', $lower)) {
        $score += 20;
    }
    if (preg_match('#(^|/)(app|src)/page\.(tsx?|jsx?)$#', $lower) || $base === 'index.html') {
        $score += 15;
    }
    // Preferir archivos cortos (menos ruido).
    $score -= (int) min(20, strlen($path) / 20);
    return $score;
}

function seoAuditRunRepoScan(string $dataDir, string $host, string $repoRaw, bool $fresh = false): array
{
    $repoRaw = seoAuditResolveRepo($host, $repoRaw);
    $parsed = seoAuditParseRepo($repoRaw);
    if ($parsed['slug'] === '') {
        $hint = $repoRaw === ''
            ? 'No hay repo en la ficha del cliente.'
            : ('Valor recibido: "' . ersClip($repoRaw, 80) . '".');
        throw new RuntimeException('Repo inválido. Usá owner/repo o https://github.com/owner/repo. ' . $hint);
    }

    $cacheFile = seoAuditRepoFile($dataDir, $host);
    $cached = gscReadJson($cacheFile, []);
    $sameRepo = ($cached['host'] ?? '') === $host && ($cached['slug'] ?? '') === $parsed['slug'];
    $previousSha = $sameRepo ? (string) ($cached['commitSha'] ?? '') : '';
    $cacheUsable = $sameRepo
        && ($cached['fetchedAt'] ?? 0) > 0
        && ($cached['version'] ?? '') === SEO_AUDIT_VERSION
        && $previousSha !== '';

    // La caché de 6 h no alcanza: un push nuevo tiene que invalidarla aunque el TTL siga vivo.
    if (!$fresh && $cacheUsable) {
        $cfgEarly = seoAuditLoadGithubConfig();
        $head = '';
        $branch = (string) ($cached['branch'] ?? '');
        if ($cfgEarly['token'] !== '' && $branch !== '') {
            try {
                $head = seoAuditGithubHead($parsed['owner'], $parsed['repo'], $branch, $cfgEarly['token']);
            } catch (Throwable) {
                $head = '';
            }
        }
        $ttlOk = (time() - (int) $cached['fetchedAt']) < SEO_AUDIT_REPO_TTL;
        if (($head !== '' && $head === $previousSha) || ($head === '' && $ttlOk)) {
            $cached['cached'] = true;
            $cached['commitChanged'] = false;
            return $cached;
        }
    }

    $cfg = seoAuditLoadGithubConfig();
    if ($cfg['token'] === '') {
        throw new RuntimeException('Falta configurar GitHub. Copiá ers/data/github.example.json a github.json y poné un PAT con permiso repo.');
    }

    @set_time_limit(55);
    $owner = rawurlencode($parsed['owner']);
    $repo = rawurlencode($parsed['repo']);
    $base = 'https://api.github.com/repos/' . $owner . '/' . $repo;

    $metaRes = seoAuditGithubHttp('GET', $base, $cfg['token']);
    if ($metaRes['code'] === 401 || $metaRes['code'] === 403) {
        throw new RuntimeException('GitHub rechazó el token (HTTP ' . $metaRes['code'] . '). Revisá el PAT y los permisos repo.');
    }
    if ($metaRes['code'] === 404) {
        throw new RuntimeException('No se encontró el repo ' . $parsed['slug'] . ' (o el token no tiene acceso).');
    }
    if ($metaRes['code'] >= 400 || !is_array($metaRes['data'])) {
        $msg = is_array($metaRes['data']) ? (string) ($metaRes['data']['message'] ?? '') : '';
        throw new RuntimeException('GitHub HTTP ' . $metaRes['code'] . ($msg !== '' ? ': ' . $msg : ''));
    }

    $defaultBranch = (string) ($metaRes['data']['default_branch'] ?? 'main');
    $head = '';
    try {
        $head = seoAuditGithubHead($parsed['owner'], $parsed['repo'], $defaultBranch, $cfg['token']);
    } catch (Throwable) {
        $head = '';
    }
    $changed = [];
    if ($previousSha !== '' && $head !== '' && $previousSha !== $head) {
        try {
            $changed = seoAuditGithubChangedPaths($parsed['owner'], $parsed['repo'], $previousSha, $head, $cfg['token']);
        } catch (Throwable) {
            $changed = [];
        }
    }
    $changedSet = array_fill_keys($changed, true);
    $wanted = seoAuditWantedPaths($dataDir, $host);

    $treeRes = seoAuditGithubHttp(
        'GET',
        $base . '/git/trees/' . rawurlencode($defaultBranch) . '?recursive=1',
        $cfg['token'],
        40
    );
    if ($treeRes['code'] >= 400 || !is_array($treeRes['data'])) {
        throw new RuntimeException('No se pudo leer el árbol del repo (HTTP ' . $treeRes['code'] . ').');
    }

    $tree = [];
    $extra = [];
    foreach ($treeRes['data']['tree'] ?? [] as $item) {
        if (!is_array($item) || ($item['type'] ?? '') !== 'blob') {
            continue;
        }
        $path = (string) ($item['path'] ?? '');
        if ($path === '') {
            continue;
        }
        $size = (int) ($item['size'] ?? 0);
        if ($size > 120000) {
            continue;
        }
        $isChanged = isset($changedSet[$path]);
        $isWanted = seoAuditPathWanted($path, $wanted);
        $entry = [
            'path' => $path,
            'size' => $size,
            'sha' => (string) ($item['sha'] ?? ''),
            'score' => seoAuditScorePath($path) + (($isChanged || $isWanted) ? 220 : 0),
        ];
        if (seoAuditIsSeoPath($path)) {
            $tree[] = $entry;
        } elseif (($isChanged || $isWanted) && seoAuditIsTextPath($path)) {
            $extra[$path] = $entry;
        }
    }

    usort($tree, static fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['path'], $b['path']));
    $candidates = array_slice($tree, 0, 28);
    $have = [];
    foreach ($candidates as $candidate) {
        $have[$candidate['path']] = true;
    }
    if ($extra !== []) {
        $extraList = array_values($extra);
        usort($extraList, static fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['path'], $b['path']));
        foreach ($extraList as $entry) {
            if (isset($have[$entry['path']])) {
                continue;
            }
            $candidates[] = $entry;
            $have[$entry['path']] = true;
            if (count($candidates) >= 40) {
                break;
            }
        }
    }
    // Los archivos del commit nuevo van primero: si no, el tope de bytes se los come el layout viejo.
    usort($candidates, static fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['path'], $b['path']));

    $files = [];
    $bytes = 0;
    $paths = array_map(static fn ($t) => $t['path'], $tree);
    foreach ($extra as $path => $_) {
        $paths[] = $path;
    }
    $paths = array_values(array_unique($paths));

    foreach ($candidates as $item) {
        if ($bytes >= SEO_AUDIT_REPO_BYTES) {
            break;
        }
        $path = $item['path'];
        $contentRes = seoAuditGithubHttp(
            'GET',
            $base . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $path)))
                . '?ref=' . rawurlencode($defaultBranch),
            $cfg['token'],
            20
        );
        if ($contentRes['code'] >= 400 || !is_array($contentRes['data'])) {
            continue;
        }
        $encoding = (string) ($contentRes['data']['encoding'] ?? '');
        $rawContent = (string) ($contentRes['data']['content'] ?? '');
        if ($encoding === 'base64') {
            $decoded = base64_decode(preg_replace('#\s+#', '', $rawContent) ?? '', true);
            $text = is_string($decoded) ? $decoded : '';
        } else {
            $text = $rawContent;
        }
        if ($text === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text)) {
            continue;
        }
        $room = SEO_AUDIT_REPO_BYTES - $bytes;
        if ($room < 400) {
            break;
        }
        $clip = ersClip($text, min(14000, $room));
        $files[] = [
            'path' => $path,
            'bytes' => strlen($clip),
            'content' => $clip,
        ];
        $bytes += strlen($clip);
    }

    // Sin SHA previo (planes viejos) igual se ve qué archivo cambió de texto.
    if ($changed === [] && $sameRepo && is_array($cached['files'] ?? null)) {
        $oldByPath = [];
        foreach ($cached['files'] as $old) {
            if (!is_array($old)) {
                continue;
            }
            $oldPath = (string) ($old['path'] ?? '');
            if ($oldPath !== '') {
                $oldByPath[$oldPath] = (string) ($old['content'] ?? '');
            }
        }
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path !== '' && isset($oldByPath[$path]) && $oldByPath[$path] !== (string) ($file['content'] ?? '')) {
                $changed[] = $path;
            }
        }
    }

    $payload = [
        'ok' => true,
        'cached' => false,
        'host' => $host,
        'slug' => $parsed['slug'],
        'owner' => $parsed['owner'],
        'repo' => $parsed['repo'],
        'branch' => $defaultBranch,
        'commitSha' => $head,
        'previousCommitSha' => $previousSha,
        'commitChanged' => ($head !== '' && $head !== $previousSha) || $changed !== [],
        'changedFiles' => $changed,
        'fetchedAt' => time(),
        'version' => SEO_AUDIT_VERSION,
        'paths' => array_slice($paths, 0, 400),
        'files' => $files,
        'fileCount' => count($files),
        'pathCount' => count($paths),
        'bytes' => $bytes,
        'private' => (bool) ($metaRes['data']['private'] ?? false),
        'htmlUrl' => (string) ($metaRes['data']['html_url'] ?? ('https://github.com/' . $parsed['slug'])),
    ];
    gscWriteJson($cacheFile, $payload);
    return $payload;
}

function seoAuditCursorSchema(): array
{
    $str = ['type' => 'string'];
    $enum = static fn (array $values) => ['type' => 'string', 'enum' => $values];
    $obj = static fn (array $props) => [
        'type' => 'object',
        'properties' => $props,
        'required' => array_keys($props),
    ];

    return $obj([
        'resumenSimple' => $str,
        'cambios' => [
            'type' => 'array',
            'items' => $obj([
                'titulo' => $str,
                'porque' => $str,
                'impacto' => $enum(['alto', 'medio', 'bajo']),
                'esfuerzo' => $enum(['bajo', 'medio', 'alto']),
                'url' => $str,
                'archivo' => $str,
                'findingId' => $str,
            ]),
        ],
        'cursorPrompt' => $str,
    ]);
}

function seoAuditSanitizeCursor(array $raw, string $host, array $knownUrls, array $knownPaths): array
{
    $clip = static fn ($s, int $n): string => ersClip(trim((string) $s), $n);
    $oneOf = static fn ($v, array $allowed, string $fallback): string => in_array($v, $allowed, true) ? $v : $fallback;

    $urlByPath = [];
    foreach ($knownUrls as $url) {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $urlByPath[strtolower($path)] = $url;
        $urlByPath[strtolower(rtrim($path, '/') ?: '/')] = $url;
    }
    $pathSet = [];
    foreach ($knownPaths as $p) {
        $pathSet[strtolower(str_replace('\\', '/', (string) $p))] = (string) $p;
    }

    $resolveUrl = static function (string $url) use ($host, $urlByPath): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (gscUrlBelongsToHost($url, $host)) {
            return $url;
        }
        if ($url[0] === '/') {
            return $urlByPath[strtolower(rtrim($url, '/') ?: '/')] ?? '';
        }
        return '';
    };

    $resolveFile = static function (string $file) use ($pathSet): string {
        $file = trim(str_replace('\\', '/', $file));
        if ($file === '') {
            return '';
        }
        $key = strtolower(ltrim($file, '/'));
        if (isset($pathSet[$key])) {
            return $pathSet[$key];
        }
        // match por sufijo
        foreach ($pathSet as $k => $orig) {
            if (str_ends_with($k, '/' . $key) || $k === $key) {
                return $orig;
            }
        }
        return '';
    };

    $cambios = [];
    foreach (array_slice(is_array($raw['cambios'] ?? null) ? $raw['cambios'] : [], 0, 16) as $c) {
        $titulo = $clip($c['titulo'] ?? '', 160);
        if ($titulo === '') {
            continue;
        }
        $row = [
            'titulo' => $titulo,
            'porque' => $clip($c['porque'] ?? '', 420),
            'impacto' => $oneOf($c['impacto'] ?? '', ['alto', 'medio', 'bajo'], 'medio'),
            'esfuerzo' => $oneOf($c['esfuerzo'] ?? '', ['bajo', 'medio', 'alto'], 'medio'),
            'url' => $resolveUrl((string) ($c['url'] ?? '')),
            'archivo' => $resolveFile((string) ($c['archivo'] ?? '')),
            'findingId' => $clip($c['findingId'] ?? '', 200),
        ];
        $row['key'] = seoAuditCambioKey($row);
        $cambios[] = $row;
    }

    $prompt = $clip($raw['cursorPrompt'] ?? '', 14000);
    if ($prompt === '' && $cambios !== []) {
        $prompt = "Sos un agente de Cursor trabajando en el sitio {$host}.\n\n"
            . "Implementá estos cambios SEO en orden, sin inventar archivos que no existan:\n\n";
        foreach ($cambios as $i => $c) {
            $n = $i + 1;
            $prompt .= "{$n}. {$c['titulo']}\n   Por qué: {$c['porque']}\n";
            if ($c['archivo'] !== '') {
                $prompt .= "   Archivo: {$c['archivo']}\n";
            }
            if ($c['url'] !== '') {
                $prompt .= '   URL: ' . $c['url'] . "\n";
            }
            $prompt .= "\n";
        }
        $prompt .= "Reglas: no inventes rutas; si falta contexto, preguntá; priorizá impacto alto y esfuerzo bajo.";
    }

    return [
        'resumenSimple' => $clip($raw['resumenSimple'] ?? '', 1200),
        'cambios' => $cambios,
        'cursorPrompt' => $prompt,
    ];
}

function seoAuditFallbackRaw(array $findings): array
{
    $cambios = [];
    foreach (array_slice($findings, 0, 12) as $f) {
        $sev = (int) ($f['severity'] ?? 1);
        $row = [
            'titulo' => (string) ($f['lines']['es']['title'] ?? $f['kind']),
            'porque' => (string) ($f['techText'] ?? ''),
            'impacto' => $sev >= 3 ? 'alto' : ($sev === 2 ? 'medio' : 'bajo'),
            'esfuerzo' => in_array($f['kind'], ['gap', 'titleMissing', 'metaMissing', 'schema', 'og', 'measure', 'alts'], true) ? 'bajo' : 'medio',
            'url' => (string) ($f['url'] ?? ''),
            'archivo' => (string) ($f['file'] ?? ''),
            'findingId' => (string) ($f['id'] ?? ''),
        ];
        $row['key'] = seoAuditCambioKey($row);
        $cambios[] = $row;
    }
    return [
        'resumenSimple' => 'La redacción automática no llegó. Los cambios salen directo de los datos del sitio, en orden de prioridad.',
        'cambios' => $cambios,
        'cursorPrompt' => '',
    ];
}

function seoAuditFingerprint(array $dossier, string $kwAiSig = ''): string
{
    return md5((string) ($dossier['fingerprint'] ?? '') . '|' . SEO_AUDIT_VERSION . '|kwai:' . $kwAiSig);
}

function seoAuditKwAiSig(?array $ai): string
{
    if ($ai === null || !is_array($ai['insights'] ?? null)) {
        return 'none';
    }
    return (int) ($ai['basedOn'] ?? 0) . ':' . (int) ($ai['generatedAt'] ?? 0);
}

function seoAuditMeasurementNote(): string
{
    return <<<TXT

## Medición
Analytics no tiene eventos clave de contacto. Antes de cerrar el trabajo, instrumentá y marcá como key events:
- click_whatsapp (clic en wa.me o api.whatsapp.com)
- phone_click (clic en tel:)
- generate_lead o form_submit (envío del formulario)
No inventes el ID de medición. Si no está en el repo, dejá el snippet comentado y preguntá.
TXT;
}

/** Bloque cerrado con NAP oficial de Google Business Profile. */
function seoAuditGbpBlock(array $dossier): string
{
    $gbp = is_array($dossier['gbp'] ?? null) ? $dossier['gbp'] : [];
    if (empty($gbp['ok'])) {
        if (!empty($gbp['linked'])) {
            return "Ficha vinculada pero sin datos frescos de Places. No inventes NAP.";
        }
        return 'Sin ficha de Google Business Profile vinculada. No inventes nombre, dirección ni teléfono local.';
    }
    $hours = is_array($gbp['regularOpeningHours'] ?? null) ? $gbp['regularOpeningHours'] : [];
    $lines = [
        'placeId: ' . (string) ($gbp['placeId'] ?? ''),
        'Nombre oficial: ' . (string) ($gbp['displayName'] ?? ''),
        'Categoría: ' . (string) ($gbp['primaryTypeDisplayName'] ?? ''),
        'Dirección: ' . (string) ($gbp['formattedAddress'] ?? ''),
        'Teléfono: ' . trim((string) (($gbp['internationalPhoneNumber'] ?? '') ?: ($gbp['nationalPhoneNumber'] ?? ''))),
        'Sitio en la ficha: ' . (string) ($gbp['websiteUri'] ?? ''),
        'Maps URL: ' . (string) ($gbp['googleMapsUri'] ?? ''),
        'Estado: ' . (string) ($gbp['businessStatus'] ?? ''),
        'Rating: ' . (isset($gbp['rating']) ? (string) $gbp['rating'] : '—')
            . ' · Reseñas: ' . (int) ($gbp['userRatingCount'] ?? 0)
            . ' · Delta reseñas: ' . (int) ($gbp['reviewsDelta'] ?? 0),
        'Fotos: ' . (int) ($gbp['photosCount'] ?? 0),
    ];
    if ($hours !== []) {
        $lines[] = 'Horarios:';
        foreach (array_slice($hours, 0, 7) as $h) {
            $lines[] = '  - ' . $h;
        }
    } else {
        $lines[] = 'Horarios: (vacío en la ficha)';
    }
    $siteNap = is_array($dossier['siteNap'] ?? null) ? $dossier['siteNap'] : [];
    if ($siteNap !== []) {
        $lines[] = '';
        $lines[] = 'NAP detectado en el HTML del sitio (para cruzar, no inventar):';
        $lines[] = '  teléfonos web: ' . implode(', ', array_slice($siteNap['phones'] ?? [], 0, 4));
        $lines[] = '  address web: ' . (string) ($siteNap['address'] ?? '');
        $lines[] = '  schema local: ' . (!empty($siteNap['hasLocalSchema']) ? implode(', ', $siteNap['schemaTypes'] ?? []) : 'ausente');
        $lines[] = '  maps link en web: ' . (!empty($siteNap['hasMapsLink']) ? 'sí' : 'no');
    }
    $lines[] = '';
    $lines[] = 'REGLA: cualquier JSON-LD LocalBusiness, footer o bloque de contacto debe usar EXACTAMENTE este NAP de Maps. Cero datos inventados.';
    return implode("\n", $lines);
}

function seoAuditCycleFile(string $dataDir, string $host): string
{
    return $dataDir . '/seo-cycle-' . md5($host) . '.json';
}

function seoAuditReadCycle(string $dataDir, string $host): array
{
    $data = gscReadJson(seoAuditCycleFile($dataDir, $host), []);
    $snaps = is_array($data['snapshots'] ?? null) ? $data['snapshots'] : [];
    return ['host' => $host, 'snapshots' => $snaps];
}

/** Últimas pasadas + tareas SEO cerradas, para no repetir el mismo plan. */
function seoAuditCycleBlock(string $dataDir, string $host, array $dossier): string
{
    $snaps = seoAuditReadCycle($dataDir, $host)['snapshots'];
    $prev = null;
    for ($i = count($snaps) - 1; $i >= 0; $i--) {
        if (is_array($snaps[$i])) {
            $prev = $snaps[$i];
            break;
        }
    }

    $done = [];
    $clientId = (string) ($dossier['client']['id'] ?? '');
    if ($clientId !== '') {
        $cutoffMs = (int) ((time() - 45 * 86400) * 1000);
        foreach (ersReadStore()['tasks'] ?? [] as $task) {
            if (!is_array($task) || (string) ($task['clientId'] ?? '') !== $clientId) {
                continue;
            }
            $doneAt = (int) ($task['doneAt'] ?? 0);
            if ($doneAt < $cutoffMs) {
                continue;
            }
            $kind = (string) ($task['kind'] ?? '');
            if (!str_starts_with($kind, 'seo')) {
                continue;
            }
            $title = trim((string) ($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $done[] = $title;
            if (count($done) >= 8) {
                break;
            }
        }
    }

    if ($prev === null && $done === []) {
        return 'Sin ciclo anterior. Esta es la primera pasada: priorizá quick wins medibles.';
    }

    $lines = [];
    if (is_array($prev)) {
        $search = is_array($dossier['search'] ?? null) ? $dossier['search'] : [];
        $lines[] = 'Pasada previa (' . gmdate('Y-m-d', (int) ($prev['at'] ?? 0)) . '): '
            . (int) ($prev['clicks'] ?? 0) . ' clics 28d, posición ' . ($prev['position'] ?? '—')
            . '. Ahora: ' . (int) ($search['clicks'] ?? 0) . ' clics, posición '
            . round((float) ($search['position'] ?? 0), 1) . '.';
        $cambios = [];
        foreach ($prev['cambios'] ?? [] as $titulo) {
            $titulo = trim((string) $titulo);
            if ($titulo !== '') {
                $cambios[] = $titulo;
            }
        }
        if ($cambios !== []) {
            $lines[] = 'Ya se pidió (no lo repitas igual; proponé el siguiente escalón):';
            foreach ($cambios as $titulo) {
                $lines[] = '- ' . $titulo;
            }
        }
    }
    if ($done !== []) {
        $lines[] = 'Tareas SEO cerradas en los últimos 45 días:';
        foreach ($done as $title) {
            $lines[] = '- ' . $title;
        }
    }
    $lines[] = 'Si una mejora anterior ya está hecha, no la vuelvas a pedir.';
    return implode("\n", $lines);
}

function seoAuditRememberCycle(string $dataDir, string $host, array $dossier, array $findings, array $insights): void
{
    $cycle = seoAuditReadCycle($dataDir, $host);
    $search = is_array($dossier['search'] ?? null) ? $dossier['search'] : [];
    $cambios = [];
    foreach (array_slice(is_array($insights['cambios'] ?? null) ? $insights['cambios'] : [], 0, 8) as $cambio) {
        $titulo = trim((string) (is_array($cambio) ? ($cambio['titulo'] ?? '') : ''));
        if ($titulo !== '') {
            $cambios[] = $titulo;
        }
    }
    $ids = [];
    foreach (array_slice($findings, 0, 24) as $finding) {
        $id = (string) ($finding['id'] ?? '');
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    $snaps = array_values(array_filter($cycle['snapshots'], 'is_array'));
    $snaps[] = [
        'at' => time(),
        'clicks' => (int) ($search['clicks'] ?? 0),
        'position' => round((float) ($search['position'] ?? 0), 1),
        'findingIds' => $ids,
        'cambios' => $cambios,
    ];
    if (count($snaps) > 3) {
        $snaps = array_slice($snaps, -3);
    }
    gscWriteJson(seoAuditCycleFile($dataDir, $host), ['host' => $host, 'snapshots' => $snaps]);
}

/** Title, meta y H1 reales — legado; preferí Page SEO Cards. */
function seoAuditDomBrief(array $dossier, array $findings): string
{
    $cards = dossierBuildPageCards($dossier, $findings, is_array($dossier['keywords'] ?? null) ? $dossier['keywords'] : [], 12);
    return dossierPageCardsBlock($cards);
}

/**
 * Prompt Cursor único: estrategia Gemini + appendix cerrado una sola vez (sin duplicar bloques).
 */
function seoAuditAssembleCursorPrompt(
    string $host,
    array $insights,
    array $findings,
    array $dossier,
    array $cards,
    string $delta,
    bool $codeChanged
): string {
    $parts = [];
    $geminiPrompt = trim((string) ($insights['cursorPrompt'] ?? ''));
    $resumen = trim((string) ($insights['resumenSimple'] ?? ''));
    if ($geminiPrompt !== '') {
        $parts[] = $geminiPrompt;
    } else {
        $parts[] = "Sos un agente de Cursor trabajando en el sitio {$host}.";
        if ($resumen !== '') {
            $parts[] = "Objetivo de esta pasada:\n" . $resumen;
        }
        $parts[] = "Implementá los cambios listados abajo en orden, sin inventar archivos.";
    }

    $cambios = is_array($insights['cambios'] ?? null) ? $insights['cambios'] : [];
    if ($cambios !== []) {
        $parts[] = "## Cambios a ejecutar (orden estricto)";
        foreach ($cambios as $i => $c) {
            if (!is_array($c)) {
                continue;
            }
            $n = $i + 1;
            $line = "{$n}. " . (string) ($c['titulo'] ?? '');
            if (($c['porque'] ?? '') !== '') {
                $line .= "\n   Por qué: " . $c['porque'];
            }
            if (($c['archivo'] ?? '') !== '') {
                $line .= "\n   Archivo: " . $c['archivo'];
            }
            if (($c['url'] ?? '') !== '') {
                $line .= "\n   URL: " . $c['url'];
            }
            if (($c['findingId'] ?? '') !== '') {
                $line .= "\n   findingId: " . $c['findingId'];
            }
            $parts[] = $line;
        }
    }

    $parts[] = "## Page SEO Cards (contexto real — anti-regresión)";
    $parts[] = dossierPageCardsBlock($cards);

    $parts[] = "## Hallazgos pendientes (lista cerrada, no inventar otros)";
    $parts[] = findingsCursorBlock($findings);

    $parts[] = "## NAP oficial Google Business Profile (no inventar)";
    $parts[] = seoAuditGbpBlock($dossier);

    if ($codeChanged && $delta !== '') {
        $parts[] = "## Código ya avanzado (no reimplementar)";
        $parts[] = $delta;
    }

    if (empty($dossier['sources']['keyEvents'])) {
        $parts[] = trim(seoAuditMeasurementNote());
    }

    $parts[] = "## Reglas finales";
    $parts[] = "- Conservá las queries de PROTEGER en cada Card al reescribir title/H1/meta.\n"
        . "- No inventes archivos, URLs, keywords ni NAP.\n"
        . "- Un cambio por URL cuando haya bloque CORREGIR AHORA.\n"
        . "- Criterios de aceptación: qué debe verse en la URL / Search Console / JSON-LD después.";

    return ersClip(trim(implode("\n\n", array_filter($parts, static fn ($p) => trim((string) $p) !== ''))), 28000);
}

/** Invalida dossier-<md5>.json para forzar rebuild en la próxima lectura. */
function seoAuditInvalidateDossier(string $dataDir, string $host): void
{
    $file = dossierCacheFile($dataDir, $host);
    if (is_file($file)) {
        @unlink($file);
    }
}

/**
 * Refresco inteligente de contexto (GA4/PSI/GBP + HEAD repo ligero) sin re-scrapear todo el HTML.
 */
function seoAuditRefreshContext(string $dataDir, string $host, string $repoRaw = '', bool $fresh = true): array
{
    @set_time_limit(55);
    $sa = gscLoadServiceAccount($dataDir, $dataDir . '/gsc-service-account.json');
    $dossier = dossierBuild($dataDir, $sa, $host, $fresh, true);

    $repoNote = '';
    $repoRaw = seoAuditResolveRepo($host, $repoRaw);
    $parsed = seoAuditParseRepo($repoRaw);
    if ($parsed['slug'] !== '') {
        try {
            seoAuditRunRepoScan($dataDir, $host, $repoRaw, false);
            $repoNote = 'Repo verificado';
        } catch (Throwable $e) {
            $repoNote = 'Repo: ' . ersClip($e->getMessage(), 120);
        }
    }

    seoAuditInvalidateDossier($dataDir, $host);
    $dossier = dossierBuild($dataDir, $sa, $host, false, false);

    return [
        'ok' => true,
        'host' => $host,
        'dossierOk' => !empty($dossier['ok']),
        'freshness' => is_array($dossier['freshness'] ?? null)
            ? $dossier['freshness']
            : dossierFreshnessLedger($dataDir, $host, $dossier),
        'sources' => $dossier['sources'] ?? [],
        'repoNote' => $repoNote,
        'gaMessage' => (string) ($dossier['gaMeta']['message'] ?? ''),
    ];
}

/** Extractos del repo ya descargados. El scan los guardaba y el prompt no los usaba. */
function seoAuditRepoExcerpt(array $repo, array $findings, array $cards = []): string
{
    $files = is_array($repo['files'] ?? null) ? $repo['files'] : [];
    if ($files === []) {
        return '';
    }
    $needles = [];
    $changed = [];
    foreach ($repo['changedFiles'] ?? [] as $changedPath) {
        $changed[strtolower(str_replace('\\', '/', (string) $changedPath))] = true;
    }
    foreach ($findings as $finding) {
        $file = strtolower(str_replace('\\', '/', (string) ($finding['file'] ?? '')));
        if ($file !== '') {
            $needles[$file] = true;
        }
        $slug = strtolower(basename(trim((string) ($finding['path'] ?? ''), '/')));
        if ($slug !== '' && $slug !== '/') {
            $needles[$slug] = true;
        }
    }
    foreach ($cards as $card) {
        if (!is_array($card)) {
            continue;
        }
        $file = strtolower(str_replace('\\', '/', (string) ($card['targetFile'] ?? '')));
        if ($file !== '') {
            $needles[$file] = true;
        }
        $slug = strtolower(basename(trim((string) ($card['path'] ?? ''), '/')));
        if ($slug !== '' && $slug !== '/') {
            $needles[$slug] = true;
        }
    }

    $ranked = [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $path = (string) ($file['path'] ?? '');
        $content = (string) ($file['content'] ?? '');
        if ($path === '' || $content === '') {
            continue;
        }
        $lower = strtolower(str_replace('\\', '/', $path));
        $score = 0;
        if (isset($changed[$lower])) {
            $score += 120;
        }
        if (preg_match('#(^|/)(layout|robots\.txt|sitemap)#', $lower)) {
            $score += 50;
        }
        if (isset($needles[$lower])) {
            $score += 80;
        }
        foreach ($needles as $needle => $_) {
            if ($needle !== '' && str_contains($lower, $needle)) {
                $score += 25;
            }
        }
        if (preg_match('#seo|metadata|head#', $lower)) {
            $score += 15;
        }
        $ranked[] = ['path' => $path, 'content' => $content, 'score' => $score];
    }
    usort($ranked, static fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['path'], $b['path']));

    $chunks = [];
    $budget = 45000;
    foreach ($ranked as $file) {
        if ($file['score'] < 15 && $chunks !== []) {
            continue;
        }
        if ($budget < 600 || count($chunks) >= 8) {
            break;
        }
        $clip = ersClip($file['content'], min(9000, $budget));
        $chunks[] = '### ' . $file['path'] . "\n" . $clip;
        $budget -= strlen($clip);
    }
    return implode("\n\n", $chunks);
}

/** Commit, HTML y Search Console iguales al plan guardado: no gastar otra redacción. */
function seoAuditSameInputsPlan(string $dataDir, string $host): ?array
{
    $cacheFile = seoAuditCursorFile($dataDir, $host);
    $cached = gscReadJson($cacheFile, []);
    if (
        ($cached['host'] ?? '') !== $host
        || !is_array($cached['insights'] ?? null)
        || ($cached['version'] ?? '') !== SEO_AUDIT_VERSION
    ) {
        return null;
    }

    $repo = gscReadJson(seoAuditRepoFile($dataDir, $host), []);
    $dom = gscReadJson(seoAuditDomFile($dataDir, $host), []);
    $gsc = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    $kw = gscReadJson(gscKeywordCacheFile($dataDir, $host), []);
    $commitSha = ($repo['host'] ?? '') === $host ? (string) ($repo['commitSha'] ?? '') : '';
    $domStamp = (int) ($dom['updatedAt'] ?? 0);
    $gscAt = (int) ($gsc['fetchedAt'] ?? 0);
    $kwAt = (int) ($kw['fetchedAt'] ?? 0);
    $same = $commitSha !== ''
        && $commitSha === (string) ($cached['commitSha'] ?? '')
        && $domStamp > 0
        && $domStamp === (int) ($cached['domStamp'] ?? 0)
        && $gscAt > 0
        && $gscAt === (int) ($cached['gscFetchedAt'] ?? 0)
        && $kwAt === (int) ($cached['kwFetchedAt'] ?? -1);
    if (!$same) {
        return null;
    }

    $short = substr($commitSha, 0, 7);
    $cached['cached'] = true;
    $cached['stale'] = false;
    $cached['codeUnchanged'] = true;
    $cached['codeChanged'] = false;
    $cached['codeNote'] = 'El código en GitHub sigue en el commit ' . $short
        . ' y el HTML leído no cambió. Si ya aplicaste el plan en Cursor, hacé push a la rama por defecto y volvé a generar: el siguiente plan sale de ese código.';
    if (is_array($cached['insights']['cambios'] ?? null)) {
        $cached['insights']['cambios'] = seoAuditAnnotateCambios($cached['insights']['cambios'], $dataDir, $host);
    }
    $cached['applied'] = seoAuditReadApplied($dataDir, $host)['items'];
    gscWriteJson($cacheFile, $cached);
    return $cached;
}

function seoAuditCodeDeltaBlock(array $repo, string $prevSha): string
{
    $sha = (string) ($repo['commitSha'] ?? '');
    $changed = [];
    foreach ($repo['changedFiles'] ?? [] as $path) {
        $path = trim((string) $path);
        if ($path !== '') {
            $changed[] = $path;
        }
    }
    if ($sha === '') {
        return 'Sin commit de GitHub. El plan sale del HTML publicado y, si hay, de la caché del repo.';
    }
    $lines = ['Commit actual: ' . $sha];
    if ($prevSha === '' && $changed === []) {
        $lines[] = 'Primera pasada con el commit fijado. La siguiente compara contra este SHA.';
        return implode("\n", $lines);
    }
    if ($prevSha !== '' && $prevSha === $sha && $changed === []) {
        $lines[] = 'Mismo commit que el plan anterior. No hay código nuevo en GitHub.';
        return implode("\n", $lines);
    }
    if ($prevSha !== '') {
        $lines[] = 'Commit del plan anterior: ' . $prevSha;
    }
    $lines[] = 'El código avanzó respecto del plan anterior. Estos archivos ya se editaron: no pidas el mismo cambio otra vez.';
    if ($changed === []) {
        $lines[] = '(El diff no listó archivos. Igual no repitas tareas del ciclo anterior sobre un archivo cuyo contenido ya ves más abajo.)';
    } else {
        foreach (array_slice($changed, 0, 30) as $path) {
            $lines[] = '- ' . $path;
        }
    }
    $lines[] = 'Si el HTML publicado todavía muestra el problema viejo, es un deploy pendiente: no pidas reimplementarlo. Proponé el siguiente escalón.';
    return implode("\n", $lines);
}

function seoAuditRunCursor(string $dataDir, string $host, bool $fresh = false): array
{
    // $fresh pide otra redacción, pero con el mismo commit y el mismo HTML sale el mismo plan.
    $unchanged = seoAuditSameInputsPlan($dataDir, $host);
    if ($unchanged !== null) {
        return $unchanged;
    }

    $started = microtime(true);
    $cfg = ersLoadGeminiConfig();
    if ($cfg['apiKey'] === '') {
        throw new RuntimeException('Falta configurar Gemini (ers/data/gemini.json).');
    }

    $site = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    if (($site['host'] ?? '') !== $host) {
        throw new RuntimeException('Primero actualizá el SEO del sitio.');
    }
    $urls = seoAuditInventoryUrls($site, $host);
    $sa = gscLoadServiceAccount($dataDir, $dataDir . '/gsc-service-account.json');
    // $fresh regenera solo la redacción Gemini. PSI/GA respetan su TTL (Actualizar SEO los fuerza).
    $dossier = dossierBuild($dataDir, $sa, $host, false, true);
    if (empty($dossier['ok'])) {
        throw new RuntimeException('Primero actualizá el SEO del sitio.');
    }
    $domProgress = $dossier['domProgress'] ?? ['done' => 0, 'total' => 0, 'complete' => false];
    if ((int) ($domProgress['done'] ?? 0) === 0) {
        throw new RuntimeException('Todavía no hay HTML scrapeado. Pulsá “Continuar lote” al menos una vez.');
    }

    // Keywords IA: reusa caché; si el dossier ya consumió mucho tiempo, no regeneres.
    $kwNote = '';
    $kwPack = ['kw' => [], 'ai' => null, 'generated' => false];
    $elapsedBeforeKw = microtime(true) - $started;
    try {
        if ($elapsedBeforeKw > 40) {
            $kwCached = gscKeywordsCached($dataDir, $host);
            $kwPack = [
                'kw' => $kwCached,
                'ai' => $kwCached !== []
                    ? gscKeywordAiFor($dataDir, $host, (int) ($kwCached['fetchedAt'] ?? 0))
                    : null,
                'generated' => false,
            ];
            if ($kwPack['ai'] === null) {
                $kwNote = 'Keywords IA omitidas: el dossier ya tardó; reintentá el plan cuando haya caché.';
            }
        } else {
            $kwPack = gscEnsureKeywordsAi($dataDir, $host, false);
        }
    } catch (Throwable $e) {
        $kwCached = gscKeywordsCached($dataDir, $host);
        $kwPack = [
            'kw' => $kwCached,
            'ai' => $kwCached !== []
                ? gscKeywordAiFor($dataDir, $host, (int) ($kwCached['fetchedAt'] ?? 0))
                : null,
            'generated' => false,
        ];
        $kwNote = 'Keywords IA no disponibles: ' . ersClip($e->getMessage(), 160);
    }
    $kw = $kwPack['kw'] !== [] ? $kwPack['kw'] : (is_array($dossier['keywords'] ?? null) ? $dossier['keywords'] : []);
    $kwAi = $kwPack['ai'];
    $kwAiSig = seoAuditKwAiSig($kwAi);

    $repo = $dossier['repo'] ?? [];
    $allFindings = findingsDetect($dossier);
    seoAuditAutoVerify($dataDir, $host, $allFindings);
    $findings = seoAuditFilterFindings($allFindings, $dataDir, $host);
    $fingerprint = seoAuditFingerprint($dossier, $kwAiSig);

    $cacheFile = seoAuditCursorFile($dataDir, $host);
    $cached = gscReadJson($cacheFile, []);
    if (
        !$fresh
        && ($cached['host'] ?? '') === $host
        && ($cached['fingerprint'] ?? '') === $fingerprint
        && is_array($cached['insights'] ?? null)
        && ($cached['version'] ?? '') === SEO_AUDIT_VERSION
    ) {
        $cached['cached'] = true;
        $cached['stale'] = false;
        if (is_array($cached['insights']['cambios'] ?? null)) {
            $cached['insights']['cambios'] = seoAuditAnnotateCambios($cached['insights']['cambios'], $dataDir, $host);
        }
        $cached['applied'] = seoAuditReadApplied($dataDir, $host)['items'];
        return $cached;
    }

    $prevSha = (string) ($cached['commitSha'] ?? '');
    $commitSha = (string) ($repo['commitSha'] ?? '');
    $changedNow = is_array($repo['changedFiles'] ?? null) && $repo['changedFiles'] !== [];
    $codeChanged = $changedNow || ($commitSha !== '' && $prevSha !== '' && $commitSha !== $prevSha);
    $delta = seoAuditCodeDeltaBlock($repo, $prevSha);
    $repoLabel = ($repo['slug'] ?? '') !== '' ? (string) $repo['slug'] : 'no conectado';
    if ($commitSha !== '') {
        $repoLabel .= ' @ ' . substr($commitSha, 0, 7);
    }

    $search = $dossier['search'] ?? [];
    $paths = array_slice($repo['paths'] ?? [], 0, 80);
    $hasKwAi = is_array($kwAi['insights'] ?? null);
    $parts = [
        'Sitio: ' . $host,
        'Objetivo: subir de nivel el sitio — más clics relevantes, páginas más fuertes, más contactos.',
        'Clics 28d: ' . (int) ($search['clicks'] ?? 0) . ' (delta ' . (int) ($search['clicksDelta'] ?? 0) . ')',
        'Impresiones: ' . (int) ($search['impressions'] ?? 0),
        'Contactos (key events): ' . (int) ($dossier['ga']['contacts'] ?? 0)
            . (!empty($dossier['sources']['keyEvents']) ? '' : ' — sin eventos clave'),
        'HTML: ' . (int) ($domProgress['done'] ?? 0) . '/' . (int) ($domProgress['total'] ?? 0),
        'Repo: ' . $repoLabel,
        'PSI home: ' . (string) ($dossier['psi']['home']['score'] ?? '—'),
        'Google Maps: ' . (!empty($dossier['sources']['gbp'])
            ? ((string) ($dossier['gbp']['displayName'] ?? 'vinculado') . ' · ★' . (string) ($dossier['gbp']['rating'] ?? '—'))
            : 'no vinculado'),
        '',
        '## HALLAZGOS (lista cerrada: no agregues problemas que no estén aquí)',
        findingsCursorBlock($findings),
        '',
        '## DATOS REALES GOOGLE BUSINESS PROFILE (NAP OFICIAL)',
        seoAuditGbpBlock($dossier),
        '',
        '## OPORTUNIDADES DE KEYWORDS (buckets GSC, datos reales)',
        gscKeywordsBucketsBrief($kw),
        '',
        '## CRECIMIENTO POR QUERIES (estrategia Keywords IA — lista cerrada)',
        gscKeywordsGrowthBlock($kwAi),
        '',
        '## PATHS DEL REPO',
        $paths === [] ? 'Sin repo. El prompt debe decir que se aplique en el proyecto local abierto en Cursor.' : implode("\n", array_map(static fn ($p) => '- ' . $p, $paths)),
        '',
        '## PAGE SEO CARDS (radiografía por URL: métricas, queries a PROTEGER/CAPTURAR, DOM real)',
        dossierPageCardsBlock($pageCards = dossierBuildPageCards($dossier, $findings, $kw, 12)),
        '',
        '## CICLO ANTERIOR',
        seoAuditCycleBlock($dataDir, $host, $dossier),
        '',
        '## MEJORAS YA MARCADAS (checks del operador)',
        seoAuditAppliedBlock($dataDir, $host),
        '',
        '## CÓDIGO DESDE EL ÚLTIMO PLAN',
        $delta,
    ];
    $excerpt = seoAuditRepoExcerpt($repo, $findings, $pageCards);
    if ($excerpt !== '') {
        $parts[] = '';
        $parts[] = '## CÓDIGO REAL DEL REPO (editá esto; no inventes archivos que no estén acá)';
        $parts[] = $excerpt;
    }
    if ($kwNote !== '') {
        $parts[] = '';
        $parts[] = '## NOTA OPERATIVA';
        $parts[] = $kwNote;
    }

    $system = <<<TXT
Sos un tech lead SEO. Tu trabajo es SUBIR DE NIVEL el sitio en cada pasada: más demanda capturada, mejor on-page, más contactos y coherencia local (Maps ↔ web).
Los problemas YA están en HALLAZGOS. La estrategia de queries YA está en CRECIMIENTO POR QUERIES y en los buckets. El NAP oficial YA está en DATOS REALES GOOGLE BUSINESS PROFILE.
Las PAGE SEO CARDS traen el estado real por URL (title/meta/H1/H2, queries a PROTEGER y a CAPTURAR, inlinks). No inventes otro title actual.
REGLA ANTI-REGRESIÓN: al proponer title/H1/meta, conservá las queries de PROTEGER de esa Card y sumá CAPTURAR sin borrar lo que ya trae clics.
No descubras otros problemas. No inventes métricas, queries, URLs, teléfonos, direcciones ni archivos.
Si hay CÓDIGO REAL DEL REPO, citá el archivo y el cambio concreto. No pidas reescribir un archivo cuyo contenido no viste.
Si hay CICLO ANTERIOR, no repitas un cambio ya pedido ni una tarea ya cerrada.
Si MEJORAS YA MARCADAS lista un findingId o título, no lo pongas en cambios.
Si CÓDIGO DESDE EL ÚLTIMO PLAN dice que el commit cambió, ese código ya está hecho.
Respondés en español neutro-chileno, directo.

Salida JSON:
- resumenSimple: 3 a 6 frases, sin jerga. Qué nivel se gana y por qué importa al negocio.
- cambios: máx 12, en orden. Incluí before→after concreto para title/meta/H1/H2/enlaces cuando aplique. url y archivo solo si aparecen en los datos. findingId = id exacto de HALLAZGOS cuando corresponde.
- cursorPrompt: instrucciones cortas para Cursor Agent (contexto + orden de trabajo + criterios de aceptación). NO copies literales los bloques HALLAZGOS/GBP/CRECIMIENTO: el sistema los anexará una sola vez. Enfocate en el plan de ejecución quirúrgico por archivo/URL.

Cubrí severidad 3 y 2, y acciones de keywords de impacto alto/medio, salvo lo que el commit nuevo ya cubre.
TXT;

    $payload = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => implode("\n", $parts)]]]],
        'generationConfig' => array_merge([
            'temperature' => 0.3,
            'maxOutputTokens' => 12288,
            'responseMimeType' => 'application/json',
            'responseSchema' => seoAuditCursorSchema(),
        ], ersGeminiThinkingConfig($cfg)),
    ];

    // Si acabamos de regenerar Keywords IA, sumamos su timeout al presupuesto.
    $budget = SEO_AUDIT_AI_TIMEOUT + 25 + (!empty($kwPack['generated']) ? GSC_KW_AI_TIMEOUT + 10 : 0);
    @set_time_limit($budget);
    $res = ersGeminiHttp(ersGeminiUrl($cfg), $payload, SEO_AUDIT_AI_TIMEOUT);
    $text = '';
    foreach ($res['candidates'][0]['content']['parts'] ?? [] as $part) {
        if (empty($part['thought']) && isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }
    $text = trim((string) preg_replace('#^```(?:json)?|```$#m', '', $text));
    $raw = json_decode($text, true);
    $narrative = 'gemini';
    if (!is_array($raw)) {
        $raw = seoAuditFallbackRaw($findings);
        $narrative = 'fallback';
    }

    $insights = seoAuditSanitizeCursor($raw, $host, $urls, $repo['paths'] ?? []);
    $insights['cambios'] = seoAuditAttachFindingIds(
        is_array($insights['cambios'] ?? null) ? $insights['cambios'] : [],
        $allFindings
    );
    // No devolver en el plan ítems que el operador ya marcó hechos/verificados.
    $maps = seoAuditAppliedMaps($dataDir, $host);
    if ($maps['findingStatus'] !== []) {
        $insights['cambios'] = array_values(array_filter(
            $insights['cambios'],
            static function (array $c) use ($maps): bool {
                $key = seoAuditCambioKey($c);
                $fid = trim((string) ($c['findingId'] ?? ''));
                if (isset($maps['findingStatus'][$key]) || ($fid !== '' && isset($maps['findingStatus'][$fid]))) {
                    return false;
                }
                return true;
            }
        ));
    }
    $insights['cambios'] = seoAuditAnnotateCambios($insights['cambios'], $dataDir, $host);
    seoAuditRememberCycle($dataDir, $host, $dossier, $findings, $insights);
    // Un solo prompt ensamblado: sin duplicar HALLAZGOS/GBP/queries al final.
    $insights['cursorPrompt'] = seoAuditAssembleCursorPrompt(
        $host,
        $insights,
        $findings,
        $dossier,
        $pageCards,
        $delta,
        $codeChanged
    );
    if ($hasKwAi) {
        $insights['keywords'] = $kwAi['insights'];
    }
    $out = [
        'ok' => true,
        'cached' => false,
        'stale' => false,
        'host' => $host,
        'model' => $cfg['model'],
        'thinkingLevel' => $cfg['thinkingLevel'] ?? ERS_GEMINI_DEFAULT_THINKING,
        'narrative' => $narrative,
        'generatedAt' => time(),
        'fingerprint' => $fingerprint,
        'coverage' => [
            'domDone' => (int) ($domProgress['done'] ?? 0),
            'domTotal' => (int) ($domProgress['total'] ?? 0),
            'domComplete' => (bool) ($domProgress['complete'] ?? false),
            'repo' => ($repo['slug'] ?? '') !== '',
            'repoSlug' => (string) ($repo['slug'] ?? ''),
            'keywords' => $kw !== [],
            'keywordsAi' => $hasKwAi,
            'keywordsAiNote' => $kwNote,
            'sources' => array_merge(
                is_array($dossier['sources'] ?? null) ? $dossier['sources'] : [],
                ['keywords' => $kw !== [], 'keywordsAi' => $hasKwAi]
            ),
            'findingCounts' => findingsCounts($findings),
        ],
        'keywordsAi' => $hasKwAi ? [
            'model' => (string) ($kwAi['model'] ?? ''),
            'generatedAt' => (int) ($kwAi['generatedAt'] ?? 0),
            'basedOn' => (int) ($kwAi['basedOn'] ?? 0),
            'stale' => false,
            'insights' => $kwAi['insights'],
        ] : null,
        'insights' => $insights,
        'commitSha' => $commitSha,
        'previousCommitSha' => $prevSha,
        'codeChanged' => $codeChanged,
        'codeUnchanged' => false,
        'codeNote' => $codeChanged
            ? ('Plan sobre el código nuevo ' . substr($commitSha, 0, 7) . '.')
            : '',
        'domStamp' => (int) ($domProgress['updatedAt'] ?? 0),
        'gscFetchedAt' => (int) ($site['fetchedAt'] ?? 0),
        'kwFetchedAt' => (int) (gscReadJson(gscKeywordCacheFile($dataDir, $host), [])['fetchedAt'] ?? 0),
        'applied' => seoAuditReadApplied($dataDir, $host)['items'],
        'version' => SEO_AUDIT_VERSION,
    ];
    gscWriteJson($cacheFile, $out);
    return $out;
}

function seoAuditStatus(string $dataDir, string $host, string $repoRaw = ''): array
{
    $site = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    $hasSite = ($site['host'] ?? '') === $host;
    $urls = $hasSite ? seoAuditInventoryUrls($site, $host) : [];
    $dom = seoAuditDomCache($dataDir, $host);
    $domProgress = seoAuditDomProgress($dom, $urls);

    $repoRaw = seoAuditResolveRepo($host, $repoRaw);
    $parsed = seoAuditParseRepo($repoRaw);
    $repo = gscReadJson(seoAuditRepoFile($dataDir, $host), []);
    $repoSha = ($repo['host'] ?? '') === $host ? (string) ($repo['commitSha'] ?? '') : '';
    $repoOk = ($repo['host'] ?? '') === $host
        && ($parsed['slug'] === '' || ($repo['slug'] ?? '') === $parsed['slug'])
        && ($repo['fetchedAt'] ?? 0) > 0
        && ($repo['version'] ?? '') === SEO_AUDIT_VERSION
        && (
            (time() - (int) $repo['fetchedAt']) < SEO_AUDIT_REPO_TTL
            || $repoSha !== ''
        );

    $cursor = gscReadJson(seoAuditCursorFile($dataDir, $host), []);
    $hasCursor = ($cursor['host'] ?? '') === $host && is_array($cursor['insights'] ?? null);

    $kw = gscKeywordsCached($dataDir, $host);
    $kwAi = $kw !== []
        ? gscKeywordAiFor($dataDir, $host, (int) ($kw['fetchedAt'] ?? 0))
        : null;
    $dossier = dossierRead($dataDir, $host);
    $allFindings = !empty($dossier['ok']) ? findingsDetect($dossier) : [];
    // Repara “Comprobado” sin evidencia (Maps/manual) antes de anotar la UI.
    seoAuditRepairFalseVerified($dataDir, $host);
    if ($allFindings !== []) {
        seoAuditAutoVerify($dataDir, $host, $allFindings);
    }
    $findings = seoAuditFilterFindings($allFindings, $dataDir, $host);
    $fingerprint = seoAuditFingerprint($dossier, seoAuditKwAiSig($kwAi));
    $appliedItems = seoAuditReadApplied($dataDir, $host)['items'];

    $planSha = (string) ($cursor['commitSha'] ?? '');
    if ($hasCursor) {
        $cursor['stale'] = ($cursor['fingerprint'] ?? '') !== $fingerprint
            || ($cursor['version'] ?? '') !== SEO_AUDIT_VERSION
            || ($repoSha !== '' && $planSha !== '' && $repoSha !== $planSha);
        if (is_array($cursor['insights']['cambios'] ?? null)) {
            $cursor['insights']['cambios'] = seoAuditAnnotateCambios($cursor['insights']['cambios'], $dataDir, $host);
        }
    }

    $gh = seoAuditLoadGithubConfig();
    $coverage = is_array($dossier['sources'] ?? null) ? $dossier['sources'] : [
        'gsc' => false, 'ga4' => false, 'keyEvents' => false, 'psi' => false, 'dom' => false, 'repo' => false, 'keywords' => false, 'gbp' => false,
    ];
    $coverage['keywords'] = $kw !== [];
    $coverage['keywordsAi'] = is_array($kwAi['insights'] ?? null)
        || is_array($cursor['keywordsAi']['insights'] ?? null)
        || is_array($cursor['insights']['keywords'] ?? null);

    return [
        'ok' => true,
        'host' => $host,
        'siteReady' => $hasSite && $urls !== [],
        'dom' => [
            'done' => $domProgress['done'],
            'failed' => $domProgress['failed'],
            'total' => $domProgress['total'],
            'remaining' => $domProgress['remaining'],
            'complete' => $domProgress['complete'],
            'pct' => $domProgress['pct'],
            'updatedAt' => (int) ($dom['updatedAt'] ?? 0),
        ],
        'repo' => [
            'configured' => $parsed['slug'] !== '',
            'slug' => $parsed['slug'] !== '' ? $parsed['slug'] : (string) ($repo['slug'] ?? ''),
            'ready' => $repoOk,
            'tokenConfigured' => $gh['token'] !== '',
            'fetchedAt' => (int) ($repo['fetchedAt'] ?? 0),
            'commitSha' => $repoSha,
            'fileCount' => (int) ($repo['fileCount'] ?? 0),
            'pathCount' => (int) ($repo['pathCount'] ?? 0),
            'error' => '',
        ],
        'keywordsReady' => $kw !== [],
        'coverage' => $coverage,
        'gaMessage' => (string) ($dossier['gaMeta']['message'] ?? ''),
        'findingCounts' => findingsCounts($findings),
        'applied' => $appliedItems,
        'audit' => $hasCursor ? [
            'ready' => true,
            'stale' => (bool) ($cursor['stale'] ?? false),
            'generatedAt' => (int) ($cursor['generatedAt'] ?? 0),
            'model' => (string) ($cursor['model'] ?? ''),
            'thinkingLevel' => (string) ($cursor['thinkingLevel'] ?? ''),
            'narrative' => (string) ($cursor['narrative'] ?? 'gemini'),
            'insights' => $cursor['insights'],
            'keywordsAi' => $cursor['keywordsAi'] ?? null,
            'coverage' => $cursor['coverage'] ?? null,
            'commitSha' => $planSha,
            'codeChanged' => (bool) ($cursor['codeChanged'] ?? false),
            'codeUnchanged' => (bool) ($cursor['codeUnchanged'] ?? false),
            'codeNote' => (string) ($cursor['codeNote'] ?? ''),
            'applied' => $appliedItems,
        ] : [
            'ready' => false,
            'stale' => false,
            'generatedAt' => 0,
            'model' => '',
            'thinkingLevel' => '',
            'narrative' => '',
            'insights' => null,
            'keywordsAi' => null,
            'coverage' => null,
            'commitSha' => '',
            'codeChanged' => false,
            'codeUnchanged' => false,
            'codeNote' => '',
            'applied' => $appliedItems,
        ],
        'canGenerate' => $domProgress['done'] > 0 && $hasSite,
        'freshness' => dossierFreshnessLedger($dataDir, $host, is_array($dossier) ? $dossier : [], [
            'cursor' => $hasCursor ? $cursor : [],
        ]),
        'gscMeta' => [
            'fullFetchedAt' => (int) ($site['fullFetchedAt'] ?? $site['fetchedAt'] ?? 0),
            'radarRefreshedAt' => (int) ($site['radarRefreshedAt'] ?? 0),
            'isPartialRadar' => !empty($site['radarRefresh']),
            'fetchedAt' => (int) ($site['fetchedAt'] ?? 0),
        ],
        'radiografia' => seoAuditRadiografiaStatus($dataDir, $host, $repoSha),
    ];
}

/** Resumen de caché de radiografía 7d para el status del panel. */
function seoAuditRadiografiaStatus(string $dataDir, string $host, string $repoSha = ''): array
{
    $siteLocal = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    $gscStamp = (int) ($siteLocal['fullFetchedAt'] ?? $siteLocal['fetchedAt'] ?? 0);
    $rad = gscReadJson(radiografiaFile($dataDir, $host), []);
    $ready = ($rad['host'] ?? '') === $host
        && is_string($rad['markdown'] ?? null)
        && $rad['markdown'] !== '';
    $stale = $ready && (
        (string) ($rad['version'] ?? '') !== RADIOGRAFIA_VERSION
        || (int) ($rad['gscFetchedAt'] ?? 0) !== $gscStamp
        || ($repoSha !== '' && (string) ($rad['commitSha'] ?? '') !== '' && (string) ($rad['commitSha'] ?? '') !== $repoSha)
    );
    return [
        'ready' => $ready,
        'stale' => $stale,
        'generatedAt' => (int) ($rad['generatedAt'] ?? 0),
        'narrative' => (string) ($rad['narrative'] ?? ''),
        'model' => (string) ($rad['model'] ?? ''),
        'windows' => is_array($rad['windows'] ?? null) ? $rad['windows'] : null,
        'detectorCount' => is_array($rad['detectors'] ?? null) ? count($rad['detectors']) : 0,
    ];
}

// --- Router (solo si este archivo es el script pedido; el cron lo incluye como librería) ---

if ($seoAuditStandalone) {
$action = (string) ($_GET['action'] ?? '');
if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $peek = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($peek) && isset($peek['action'])) {
        $action = (string) $peek['action'];
        // re-buffer? already consumed — store for handlers
        $GLOBALS['seo_audit_input'] = $peek;
    }
}

function seoAuditInput(): array
{
    if (isset($GLOBALS['seo_audit_input']) && is_array($GLOBALS['seo_audit_input'])) {
        return $GLOBALS['seo_audit_input'];
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = json_decode((string) file_get_contents('php://input'), true);
        return is_array($raw) ? $raw : [];
    }
    return $_GET;
}

try {
    if ($action === 'status') {
        $host = gscSanitizeHost((string) ($_GET['host'] ?? ''));
        if ($host === '') {
            seoAuditJson(['error' => 'Indica el dominio del cliente'], 400);
        }
        $repo = (string) ($_GET['repo'] ?? '');
        seoAuditJson(seoAuditStatus($dataDir, $host, $repo));
    }

    if ($action === 'dom-batch') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            seoAuditJson(['error' => 'Método no permitido'], 405);
        }
        $input = seoAuditInput();
        $host = gscSanitizeHost((string) ($input['host'] ?? ''));
        if ($host === '') {
            seoAuditJson(['error' => 'Indica el dominio del cliente'], 400);
        }
        $limit = (int) ($input['limit'] ?? SEO_AUDIT_DOM_BATCH);
        $fresh = in_array((string) ($input['fresh'] ?? ''), ['1', 'true'], true) || ($input['fresh'] ?? null) === true;
        seoAuditJson(seoAuditRunDomBatch($dataDir, $host, $limit, $fresh));
    }

    if ($action === 'dom-touch') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            seoAuditJson(['error' => 'Método no permitido'], 405);
        }
        $input = seoAuditInput();
        $host = gscSanitizeHost((string) ($input['host'] ?? ''));
        if ($host === '') {
            seoAuditJson(['error' => 'Indica el dominio del cliente'], 400);
        }
        $urls = is_array($input['urls'] ?? null) ? $input['urls'] : [];
        seoAuditJson(seoAuditTouchDom($dataDir, $host, $urls));
    }

    if ($action === 'repo-scan') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            seoAuditJson(['error' => 'Método no permitido'], 405);
        }
        $input = seoAuditInput();
        $host = gscSanitizeHost((string) ($input['host'] ?? ''));
        $repo = (string) ($input['repo'] ?? '');
        if ($host === '') {
            seoAuditJson(['error' => 'Indica el dominio del cliente'], 400);
        }
        $fresh = in_array((string) ($input['fresh'] ?? ''), ['1', 'true'], true) || ($input['fresh'] ?? null) === true;
        seoAuditJson(seoAuditRunRepoScan($dataDir, $host, $repo, $fresh));
    }

    if ($action === 'cursor-audit') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            seoAuditJson(['error' => 'Método no permitido'], 405);
        }
        $input = seoAuditInput();
        $host = gscSanitizeHost((string) ($input['host'] ?? ''));
        if ($host === '') {
            seoAuditJson(['error' => 'Indica el dominio del cliente'], 400);
        }
        $fresh = in_array((string) ($input['fresh'] ?? ''), ['1', 'true'], true) || ($input['fresh'] ?? null) === true;
        seoAuditJson(seoAuditRunCursor($dataDir, $host, $fresh));
    }

    if ($action === 'radiografia') {
        $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? seoAuditInput() : $_GET;
        $host = gscSanitizeHost((string) ($input['host'] ?? ''));
        if ($host === '') {
            seoAuditJson(['error' => 'Indica el dominio del cliente'], 400);
        }
        // GET: solo lectura de caché (sin Gemini ni GSC en vivo).
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $rad = gscReadJson(radiografiaFile($dataDir, $host), []);
            if (($rad['host'] ?? '') !== $host || !is_string($rad['markdown'] ?? null) || $rad['markdown'] === '') {
                seoAuditJson(['ok' => false, 'ready' => false, 'host' => $host, 'markdown' => '']);
            }
            $rad['ok'] = true;
            $rad['ready'] = true;
            $rad['cached'] = true;
            seoAuditJson($rad);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            seoAuditJson(['error' => 'Método no permitido'], 405);
        }
        $repo = (string) ($input['repo'] ?? '');
        $fresh = in_array((string) ($input['fresh'] ?? ''), ['1', 'true'], true) || ($input['fresh'] ?? null) === true;
        seoAuditJson(radiografiaRun($dataDir, $host, $repo, $fresh));
    }

    if ($action === 'mark-applied') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            seoAuditJson(['error' => 'Método no permitido'], 405);
        }
        $input = seoAuditInput();
        $host = gscSanitizeHost((string) ($input['host'] ?? ''));
        if ($host === '') {
            seoAuditJson(['error' => 'Indica el dominio del cliente'], 400);
        }
        $cambio = is_array($input['cambio'] ?? null) ? $input['cambio'] : $input;
        $commitSha = (string) ($input['commitSha'] ?? '');
        seoAuditJson(seoAuditMarkApplied($dataDir, $host, $cambio, $commitSha));
    }

    if ($action === 'unmark-applied') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            seoAuditJson(['error' => 'Método no permitido'], 405);
        }
        $input = seoAuditInput();
        $host = gscSanitizeHost((string) ($input['host'] ?? ''));
        $key = (string) ($input['key'] ?? $input['id'] ?? '');
        if ($host === '') {
            seoAuditJson(['error' => 'Indica el dominio del cliente'], 400);
        }
        seoAuditJson(seoAuditUnmarkApplied($dataDir, $host, $key));
    }

    if ($action === 'verify-applied') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            seoAuditJson(['error' => 'Método no permitido'], 405);
        }
        $input = seoAuditInput();
        $host = gscSanitizeHost((string) ($input['host'] ?? ''));
        if ($host === '') {
            seoAuditJson(['error' => 'Indica el dominio del cliente'], 400);
        }
        seoAuditJson(seoAuditVerifyApplied($dataDir, $host));
    }

    if ($action === 'refresh-context') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            seoAuditJson(['error' => 'Método no permitido'], 405);
        }
        $input = seoAuditInput();
        $host = gscSanitizeHost((string) ($input['host'] ?? ''));
        if ($host === '') {
            seoAuditJson(['error' => 'Indica el dominio del cliente'], 400);
        }
        $repo = (string) ($input['repo'] ?? '');
        $fresh = !isset($input['fresh']) || in_array((string) ($input['fresh'] ?? ''), ['1', 'true'], true) || ($input['fresh'] ?? null) === true;
        seoAuditJson(seoAuditRefreshContext($dataDir, $host, $repo, $fresh));
    }

    seoAuditJson(['error' => 'Acción no reconocida. Usá status, dom-batch, dom-touch, repo-scan, cursor-audit, radiografia, mark-applied, unmark-applied, verify-applied o refresh-context.'], 400);
} catch (Throwable $e) {
    seoAuditJson(['error' => $e->getMessage()], 500);
}
}
