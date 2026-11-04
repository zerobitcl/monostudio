<?php
/**
 * Auditoría SEO integral → prompt maestro para Cursor.
 *
 * GET  action=status&host=     → progreso DOM + repo + análisis cacheado
 * POST action=dom-batch        → scrape HTML de un lote de URLs del inventario
 * POST action=repo-scan        → lee tree + archivos SEO de un repo GitHub privado
 * POST action=cursor-audit     → Gemini cruza todo y arma resumen + prompt Cursor
 *
 * Credenciales GitHub: data/github.json → { "token": "ghp_..." }
 */

declare(strict_types=1);

define('ERS_LIBRARY', true);
require_once __DIR__ . '/gsc.php';
require_once __DIR__ . '/lib.php';

const SEO_AUDIT_DOM_BATCH = 18;
const SEO_AUDIT_DOM_BUDGET = 42;
const SEO_AUDIT_DOM_TTL = 604800; // 7 días: re-scrape si el HTML cambió de firma
const SEO_AUDIT_REPO_TTL = 21600; // 6 h
const SEO_AUDIT_REPO_BYTES = 110000;
const SEO_AUDIT_AI_TIMEOUT = 75;
const SEO_AUDIT_VERSION = '2026.11.03-cursor';

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
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

/**
 * Scrape SEO del HTML: title, headings, meta, canonical, robots, OG.
 */
function seoAuditFetchDom(string $url): array
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
        $empty['fetchedAt'] = time();
        return $empty;
    }

    if (!class_exists('DOMDocument')) {
        $empty['status'] = $code;
        $empty['error'] = 'DOMDocument no disponible';
        return $empty;
    }

    // Truncar HTML gigante: basta el <head> + primeros headings.
    if (strlen($html) > 350000) {
        $html = substr($html, 0, 350000);
    }

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
    $h1 = $textOf('//h1', 3);
    $h2 = $textOf('//h2', 8);
    $meta = $attrOf('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content');
    $canonical = $attrOf('//link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]/@href');
    $robots = $attrOf('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"]/@content');
    $ogTitle = $attrOf('//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content');
    $ogDescription = $attrOf('//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:description"]/@content');

    return [
        'ok' => true,
        'status' => $code,
        'title' => ersClip($title, 200),
        'h1' => ersClip($h1, 240),
        'h2' => ersClip($h2, 500),
        'meta' => ersClip($meta, 320),
        'canonical' => ersClip($canonical, 500),
        'robots' => ersClip($robots, 120),
        'ogTitle' => ersClip($ogTitle, 200),
        'ogDescription' => ersClip($ogDescription, 320),
        'error' => '',
        'fetchedAt' => time(),
    ];
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
        $fresh = is_array($row)
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
    if (
        !$fresh
        && ($cached['host'] ?? '') === $host
        && ($cached['slug'] ?? '') === $parsed['slug']
        && ($cached['fetchedAt'] ?? 0) > 0
        && (time() - (int) $cached['fetchedAt']) < SEO_AUDIT_REPO_TTL
    ) {
        $cached['cached'] = true;
        return $cached;
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
    foreach ($treeRes['data']['tree'] ?? [] as $item) {
        if (!is_array($item) || ($item['type'] ?? '') !== 'blob') {
            continue;
        }
        $path = (string) ($item['path'] ?? '');
        if ($path === '' || !seoAuditIsSeoPath($path)) {
            continue;
        }
        $size = (int) ($item['size'] ?? 0);
        if ($size > 120000) {
            continue;
        }
        $tree[] = [
            'path' => $path,
            'size' => $size,
            'sha' => (string) ($item['sha'] ?? ''),
            'score' => seoAuditScorePath($path),
        ];
    }

    usort($tree, static fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['path'], $b['path']));
    $candidates = array_slice($tree, 0, 28);

    $files = [];
    $bytes = 0;
    $paths = array_map(static fn ($t) => $t['path'], $tree);

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

    $payload = [
        'ok' => true,
        'cached' => false,
        'host' => $host,
        'slug' => $parsed['slug'],
        'owner' => $parsed['owner'],
        'repo' => $parsed['repo'],
        'branch' => $defaultBranch,
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

function seoAuditDomLines(array $domCache, array $urls): string
{
    $lines = ['## HTML EN VIVO (title | h1 | meta | canonical | robots | status)'];
    $pages = $domCache['pages'] ?? [];
    foreach ($urls as $url) {
        $row = $pages[$url] ?? null;
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        if (!is_array($row)) {
            $lines[] = $path . ' | (sin scrapear)';
            continue;
        }
        $status = (int) ($row['status'] ?? 0);
        $flag = !empty($row['ok']) ? 'ok' : ('fail:' . ($row['error'] ?? $status));
        $lines[] = implode(' | ', [
            $path,
            ersClip((string) ($row['title'] ?? ''), 90),
            ersClip((string) ($row['h1'] ?? ''), 80),
            ersClip((string) ($row['meta'] ?? ''), 100),
            ersClip((string) ($row['canonical'] ?? ''), 80),
            ersClip((string) ($row['robots'] ?? ''), 40),
            $flag,
        ]);
    }
    return implode("\n", $lines);
}

function seoAuditRepoLines(array $repo): string
{
    if (($repo['slug'] ?? '') === '') {
        return "## REPO\nSin acceso al código. El prompt debe indicar cambios a aplicar en el proyecto local del operador.";
    }
    $lines = [
        '## REPO GITHUB: ' . ($repo['slug'] ?? ''),
        'Branch: ' . ($repo['branch'] ?? 'main') . ' · archivos SEO leídos: ' . (int) ($repo['fileCount'] ?? 0),
        '',
        '### Paths relevantes',
    ];
    foreach (array_slice($repo['paths'] ?? [], 0, 120) as $path) {
        $lines[] = '- ' . $path;
    }
    $lines[] = '';
    $lines[] = '### Contenido (recortes)';
    foreach ($repo['files'] ?? [] as $file) {
        $lines[] = '';
        $lines[] = '#### ' . ($file['path'] ?? '');
        $lines[] = '```';
        $lines[] = (string) ($file['content'] ?? '');
        $lines[] = '```';
    }
    return implode("\n", $lines);
}

function seoAuditSiteDigest(array $site): string
{
    $inv = $site['inventory'] ?? [];
    $analysis = $site['analysis'] ?? [];
    $lines = [
        '## INVENTARIO / INDEXACIÓN',
        'Páginas: ' . (int) ($inv['total'] ?? count($site['pages'] ?? [])),
        'Indexadas: ' . (int) ($inv['indexed'] ?? 0),
        'No indexadas: ' . (int) ($inv['notIndexed'] ?? 0),
        'Bloqueadas: ' . (int) ($inv['blocked'] ?? 0),
    ];
    if (is_array($analysis) && $analysis !== []) {
        $lines[] = 'Análisis: ' . ersClip(json_encode($analysis, JSON_UNESCAPED_UNICODE) ?: '', 1200);
    }
    $signals = array_slice($site['signals'] ?? [], 0, 12);
    if ($signals !== []) {
        $lines[] = '';
        $lines[] = '## SEÑALES';
        foreach ($signals as $s) {
            $lines[] = '- [' . ($s['severity'] ?? '') . '] ' . ($s['title'] ?? $s['kind'] ?? '')
                . ' · ' . ersClip((string) ($s['detail'] ?? ''), 160)
                . (($s['url'] ?? '') !== '' ? ' · ' . (parse_url((string) $s['url'], PHP_URL_PATH) ?: $s['url']) : '');
        }
    }
    $lines[] = '';
    $lines[] = '## TOP PÁGINAS (clics / imp / ctr / pos)';
    $pages = $site['pages'] ?? [];
    usort($pages, static fn ($a, $b) => ((int) ($b['clicks'] ?? 0) <=> (int) ($a['clicks'] ?? 0)));
    foreach (array_slice($pages, 0, 25) as $p) {
        $path = parse_url((string) ($p['url'] ?? ''), PHP_URL_PATH) ?: '/';
        $lines[] = $path . ' | ' . (int) ($p['clicks'] ?? 0) . ' clics | '
            . (int) ($p['impressions'] ?? 0) . ' imp | ctr '
            . round(((float) ($p['ctr'] ?? 0)) * 100, 1) . '% | pos '
            . round((float) ($p['position'] ?? 0), 1)
            . (!empty($p['indexStatus']['verdict']) ? ' | índice:' . $p['indexStatus']['verdict'] : '');
    }
    return implode("\n", $lines);
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
        $cambios[] = [
            'titulo' => $titulo,
            'porque' => $clip($c['porque'] ?? '', 420),
            'impacto' => $oneOf($c['impacto'] ?? '', ['alto', 'medio', 'bajo'], 'medio'),
            'esfuerzo' => $oneOf($c['esfuerzo'] ?? '', ['bajo', 'medio', 'alto'], 'medio'),
            'url' => $resolveUrl((string) ($c['url'] ?? '')),
            'archivo' => $resolveFile((string) ($c['archivo'] ?? '')),
        ];
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

function seoAuditRunCursor(string $dataDir, string $host, bool $fresh = false): array
{
    $cfg = ersLoadGeminiConfig();
    if ($cfg['apiKey'] === '') {
        throw new RuntimeException('Falta configurar Gemini (ers/data/gemini.json).');
    }

    $site = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    if (($site['host'] ?? '') !== $host) {
        throw new RuntimeException('Primero actualizá el SEO del sitio.');
    }
    $urls = seoAuditInventoryUrls($site, $host);
    $dom = seoAuditDomCache($dataDir, $host);
    $domProgress = seoAuditDomProgress($dom, $urls);
    if ($domProgress['done'] === 0) {
        throw new RuntimeException('Todavía no hay HTML scrapeado. Pulsá “Continuar lote” al menos una vez.');
    }

    $kw = gscReadJson(gscKeywordCacheFile($dataDir, $host), []);
    $repo = gscReadJson(seoAuditRepoFile($dataDir, $host), []);
    if (($repo['host'] ?? '') !== $host) {
        $repo = [];
    }

    $fingerprint = md5(json_encode([
        'site' => (int) ($site['fetchedAt'] ?? 0),
        'kw' => (int) ($kw['fetchedAt'] ?? 0),
        'dom' => (int) ($dom['updatedAt'] ?? 0),
        'domDone' => $domProgress['done'],
        'repo' => (int) ($repo['fetchedAt'] ?? 0),
        'v' => SEO_AUDIT_VERSION,
    ], JSON_UNESCAPED_UNICODE) ?: '');

    $cacheFile = seoAuditCursorFile($dataDir, $host);
    $cached = gscReadJson($cacheFile, []);
    if (
        !$fresh
        && ($cached['host'] ?? '') === $host
        && ($cached['fingerprint'] ?? '') === $fingerprint
        && is_array($cached['insights'] ?? null)
    ) {
        $cached['cached'] = true;
        $cached['stale'] = false;
        return $cached;
    }

    $parts = [
        'Sitio: ' . $host,
        'Cobertura HTML: ' . $domProgress['done'] . '/' . $domProgress['total']
            . ($domProgress['complete'] ? ' (completo)' : ' (parcial; seguí con más lotes si querés más precisión)'),
        '',
        seoAuditSiteDigest($site),
        '',
    ];

    if (is_array($kw['buckets'] ?? null) && function_exists('gscKeywordsAiPrompt')) {
        $parts[] = '## KEYWORDS GSC';
        $parts[] = gscKeywordsAiPrompt($kw);
        $parts[] = '';
    }

    $parts[] = seoAuditDomLines($dom, $urls);
    $parts[] = '';
    $parts[] = seoAuditRepoLines($repo);

    $system = <<<TXT
Sos un consultor SEO senior + tech lead. Vas a cruzar Search Console, HTML en vivo y (si hay) el repo GitHub del sitio.
Respondés en español neutro-chileno, directo, sin relleno.

Salida JSON:
- resumenSimple: 3 a 8 frases en lenguaje claro (sin jerga: nada de CTR, LCP, indexación técnica). Explicá qué se va a mejorar y por qué importa al negocio.
- cambios: lista priorizada (máx 12) de trabajos concretos. url = URL del sitio si aplica. archivo = path real del repo si aparece en los paths; si no hay repo o no sabés el archivo, dejá "".
- cursorPrompt: prompt MAESTRO largo para pegar en Cursor Agent. Debe incluir:
  1) Contexto del sitio y objetivo
  2) Hallazgos cruzados (datos ↔ HTML ↔ código)
  3) Lista ordenada de cambios con archivos concretos cuando existan
  4) Criterios de aceptación
  5) Reglas: no inventar archivos ni rutas; no tocar secretos; preguntar si falta un path; priorizar quick wins

REGLAS
- Usá SOLO URLs y paths que aparecen en los datos.
- No inventes métricas.
- Si el repo no está, el cursorPrompt debe decir que se aplique en el proyecto local abierto en Cursor.
- Priorizá impacto alto / esfuerzo bajo.
TXT;

    $payload = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => implode("\n", $parts)]]]],
        'generationConfig' => [
            'temperature' => 0.3,
            'maxOutputTokens' => 12288,
            'responseMimeType' => 'application/json',
            'responseSchema' => seoAuditCursorSchema(),
        ],
    ];

    @set_time_limit(SEO_AUDIT_AI_TIMEOUT + 25);
    $res = ersGeminiHttp(ersGeminiUrl($cfg), $payload, SEO_AUDIT_AI_TIMEOUT);
    $text = '';
    foreach ($res['candidates'][0]['content']['parts'] ?? [] as $part) {
        if (empty($part['thought']) && isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }
    $text = trim((string) preg_replace('#^```(?:json)?|```$#m', '', $text));
    $raw = json_decode($text, true);
    if (!is_array($raw)) {
        throw new RuntimeException('Gemini no devolvió un análisis válido. Reintenta.');
    }

    $insights = seoAuditSanitizeCursor($raw, $host, $urls, $repo['paths'] ?? []);
    $out = [
        'ok' => true,
        'cached' => false,
        'stale' => false,
        'host' => $host,
        'model' => $cfg['model'],
        'generatedAt' => time(),
        'fingerprint' => $fingerprint,
        'coverage' => [
            'domDone' => $domProgress['done'],
            'domTotal' => $domProgress['total'],
            'domComplete' => $domProgress['complete'],
            'repo' => ($repo['slug'] ?? '') !== '',
            'repoSlug' => (string) ($repo['slug'] ?? ''),
            'keywords' => is_array($kw['buckets'] ?? null),
        ],
        'insights' => $insights,
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
    $repoOk = ($repo['host'] ?? '') === $host
        && ($parsed['slug'] === '' || ($repo['slug'] ?? '') === $parsed['slug'])
        && ($repo['fetchedAt'] ?? 0) > 0
        && (time() - (int) $repo['fetchedAt']) < SEO_AUDIT_REPO_TTL;

    $cursor = gscReadJson(seoAuditCursorFile($dataDir, $host), []);
    $hasCursor = ($cursor['host'] ?? '') === $host && is_array($cursor['insights'] ?? null);

    $kw = gscReadJson(gscKeywordCacheFile($dataDir, $host), []);
    $fingerprint = md5(json_encode([
        'site' => (int) ($site['fetchedAt'] ?? 0),
        'kw' => (int) ($kw['fetchedAt'] ?? 0),
        'dom' => (int) ($dom['updatedAt'] ?? 0),
        'domDone' => $domProgress['done'],
        'repo' => (int) ($repo['fetchedAt'] ?? 0),
        'v' => SEO_AUDIT_VERSION,
    ], JSON_UNESCAPED_UNICODE) ?: '');

    if ($hasCursor) {
        $cursor['stale'] = ($cursor['fingerprint'] ?? '') !== $fingerprint;
    }

    $gh = seoAuditLoadGithubConfig();

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
            'fileCount' => (int) ($repo['fileCount'] ?? 0),
            'pathCount' => (int) ($repo['pathCount'] ?? 0),
            'error' => '',
        ],
        'keywordsReady' => isset($kw['fetchedAt']),
        'audit' => $hasCursor ? [
            'ready' => true,
            'stale' => (bool) ($cursor['stale'] ?? false),
            'generatedAt' => (int) ($cursor['generatedAt'] ?? 0),
            'model' => (string) ($cursor['model'] ?? ''),
            'insights' => $cursor['insights'],
            'coverage' => $cursor['coverage'] ?? null,
        ] : [
            'ready' => false,
            'stale' => false,
            'generatedAt' => 0,
            'model' => '',
            'insights' => null,
            'coverage' => null,
        ],
        'canGenerate' => $domProgress['done'] > 0 && $hasSite,
    ];
}

// --- Router ---

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

    seoAuditJson(['error' => 'Acción no reconocida. Usá status, dom-batch, repo-scan o cursor-audit.'], 400);
} catch (Throwable $e) {
    seoAuditJson(['error' => $e->getMessage()], 500);
}
