<?php
/**
 * Radiografía semanal forense (7d vs 7d previos).
 * Pre-computa métricas + device + diff SEO de git + detectores; Gemini solo narra.
 *
 * Se incluye desde seo-audit.php (action=radiografia).
 */

declare(strict_types=1);

if (!defined('ERS_LIBRARY')) {
    define('ERS_LIBRARY', true);
}

const RADIOGRAFIA_VERSION = '2026.11.23';
const RADIOGRAFIA_TTL = 21600; // 6 h
const RADIOGRAFIA_AI_TIMEOUT = 70;
const RADIOGRAFIA_TITLE_COOLDOWN_DAYS = 14;

function radiografiaFile(string $dataDir, string $host): string
{
    return $dataDir . '/seo-radiografia-' . md5($host) . '.json';
}

/** Misma ventana GSC que el resto del stack: cierra hoy−3 (desfase Search Console). */
function radiografiaWindows(): array
{
    $end = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-3 days');
    $start = $end->modify('-6 days');
    $prevEnd = $start->modify('-1 day');
    $prevStart = $prevEnd->modify('-6 days');
    return [
        'end' => $end->format('Y-m-d'),
        'start' => $start->format('Y-m-d'),
        'prevEnd' => $prevEnd->format('Y-m-d'),
        'prevStart' => $prevStart->format('Y-m-d'),
        'lagNote' => 'Search Console suele ir 24–48 h atrás; cambios de las últimas 48 h aún no miden en estos 7 días.',
    ];
}

function radiografiaEmptyMetrics(): array
{
    return ['clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0];
}

function radiografiaPct(float $n): string
{
    return round($n * 100, 1) . '%';
}

function radiografiaDeviceFromRows(array $data): array
{
    $out = [];
    foreach ($data['rows'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = strtolower((string) ($row['keys'][0] ?? ''));
        if ($name === '') {
            continue;
        }
        $m = gscMetricRow($row);
        $out[$name] = $m;
    }
    return $out;
}

function radiografiaPagesFromRows(array $data): array
{
    $out = [];
    foreach ($data['rows'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $url = (string) ($row['keys'][0] ?? '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        $out[gscPageKey($url)] = [
            'url' => $url,
            'path' => (string) (parse_url($url, PHP_URL_PATH) ?: '/'),
            'metrics' => gscMetricRow($row),
        ];
    }
    return $out;
}

/**
 * Consulta GSC en vivo para la ventana 7d: totales, device y páginas (sitio).
 * Opcional: device filtrado a la home si hay URL canónica.
 */
function radiografiaFetchGscSlice(string $token, string $property, string $start, string $end, string $homeUrl = ''): array
{
    $jobs = [
        'totals' => gscAnalyticsJob($token, $property, $start, $end),
        'device' => gscAnalyticsJob($token, $property, $start, $end, [
            'dimensions' => ['device'],
            'rowLimit' => 10,
        ]),
        'page' => gscAnalyticsJob($token, $property, $start, $end, [
            'dimensions' => ['page'],
            'rowLimit' => 250,
        ]),
    ];
    if ($homeUrl !== '') {
        $jobs['homeDevice'] = gscAnalyticsJob($token, $property, $start, $end, [
            'dimensions' => ['device'],
            'rowLimit' => 10,
            'dimensionFilterGroups' => [[
                'filters' => [[
                    'dimension' => 'page',
                    'operator' => 'equals',
                    'expression' => $homeUrl,
                ]],
            ]],
        ]);
    }

    $res = gscMultiFetch($jobs);
    $totals = radiografiaEmptyMetrics();
    if (!empty($res['totals']['ok']) && is_array($res['totals']['data'] ?? null)) {
        $totals = gscMetricRow($res['totals']['data']['rows'][0] ?? null);
    }
    $devices = [];
    if (!empty($res['device']['ok']) && is_array($res['device']['data'] ?? null)) {
        $devices = radiografiaDeviceFromRows($res['device']['data']);
    }
    $pages = [];
    if (!empty($res['page']['ok']) && is_array($res['page']['data'] ?? null)) {
        $pages = radiografiaPagesFromRows($res['page']['data']);
    }
    $homeDevices = [];
    if ($homeUrl !== '' && !empty($res['homeDevice']['ok']) && is_array($res['homeDevice']['data'] ?? null)) {
        $homeDevices = radiografiaDeviceFromRows($res['homeDevice']['data']);
    }

    return [
        'totals' => $totals,
        'devices' => $devices,
        'homeDevices' => $homeDevices,
        'pages' => $pages,
        'ok' => !empty($res['totals']['ok']) || !empty($res['device']['ok']),
    ];
}

function radiografiaDeviceRow(array $devices, string $name): array
{
    return is_array($devices[$name] ?? null) ? $devices[$name] : radiografiaEmptyMetrics();
}

function radiografiaPathKey(string $urlOrPath): string
{
    if (str_starts_with($urlOrPath, 'http')) {
        $path = (string) (parse_url($urlOrPath, PHP_URL_PATH) ?: '/');
    } else {
        $path = $urlOrPath;
    }
    $path = '/' . ltrim(strtolower($path), '/');
    return rtrim($path, '/') ?: '/';
}

function radiografiaHomeUrl(array $site, string $host): string
{
    $candidates = [];
    foreach ($site['pages'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $url = trim((string) ($row['url'] ?? ''));
        if ($url === '' || !gscUrlBelongsToHost($url, $host)) {
            continue;
        }
        $path = radiografiaPathKey($url);
        if ($path === '/') {
            $candidates[] = $url;
        }
    }
    if ($candidates !== []) {
        // Preferir www si hay ambas.
        usort($candidates, static function (string $a, string $b): int {
            $aw = str_contains(strtolower(parse_url($a, PHP_URL_HOST) ?: ''), 'www.') ? 0 : 1;
            $bw = str_contains(strtolower(parse_url($b, PHP_URL_HOST) ?: ''), 'www.') ? 0 : 1;
            return $aw <=> $bw;
        });
        return $candidates[0];
    }
    return 'https://www.' . $host . '/';
}

function radiografiaExtractSeoTags(string $html): array
{
    $title = '';
    $meta = '';
    $h1 = '';
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if (preg_match('#<meta\s[^>]*name=["\']description["\'][^>]*>#is', $html, $m)
        || preg_match('#<meta\s[^>]*name=["\']description["\'][^>]*/?>#is', $html, $m)
    ) {
        $tag = $m[0];
        if (preg_match('#content=["\'](.*?)["\']#is', $tag, $c)) {
            $meta = trim(html_entity_decode($c[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    } elseif (preg_match('#<meta\s[^>]*content=["\'](.*?)["\'][^>]*name=["\']description["\'][^>]*>#is', $html, $m)) {
        $meta = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if (preg_match('#<h1\b[^>]*>(.*?)</h1>#is', $html, $m)) {
        $h1 = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return [
        'title' => ersClip($title, 220),
        'meta' => ersClip($meta, 400),
        'h1' => ersClip($h1, 220),
    ];
}

function radiografiaGithubFileAt(
    string $owner,
    string $repo,
    string $path,
    string $ref,
    string $token
): string {
    if ($owner === '' || $repo === '' || $path === '' || $ref === '' || $token === '') {
        return '';
    }
    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $path)))
        . '?ref=' . rawurlencode($ref);
    $res = seoAuditGithubHttp('GET', $url, $token, 18);
    if (($res['code'] ?? 500) >= 400 || !is_array($res['data'])) {
        return '';
    }
    $encoding = (string) ($res['data']['encoding'] ?? '');
    $rawContent = (string) ($res['data']['content'] ?? '');
    if ($encoding === 'base64') {
        $decoded = base64_decode(preg_replace('#\s+#', '', $rawContent) ?? '', true);
        return is_string($decoded) ? $decoded : '';
    }
    return $rawContent;
}

/**
 * Commits que tocaron paths SEO (index.html / layout / metadata), con before→after de title/meta/H1.
 */
function radiografiaGitSeoDiff(string $dataDir, string $host, string $repoRaw): array
{
    $repoRaw = seoAuditResolveRepo($host, $repoRaw);
    $parsed = seoAuditParseRepo($repoRaw);
    if ($parsed['slug'] === '') {
        return ['ok' => false, 'reason' => 'Sin repo en la ficha', 'changes' => [], 'paths' => []];
    }
    $cfg = seoAuditLoadGithubConfig();
    if ($cfg['token'] === '') {
        return ['ok' => false, 'reason' => 'Falta github.json', 'changes' => [], 'paths' => [], 'slug' => $parsed['slug']];
    }

    $repoCache = gscReadJson(seoAuditRepoFile($dataDir, $host), []);
    $branch = (string) ($repoCache['branch'] ?? 'main');
    $paths = [];
    foreach ($repoCache['paths'] ?? [] as $p) {
        $p = str_replace('\\', '/', (string) $p);
        if ($p !== '' && seoAuditIsSeoPath($p)) {
            $paths[] = $p;
        }
    }
    // Priorizar home / index / metadata.
    usort($paths, static function (string $a, string $b): int {
        $score = static function (string $p): int {
            $base = strtolower(basename($p));
            $s = 0;
            if ($base === 'index.html') {
                $s += 100;
            }
            if (preg_match('#(^|/)(app|pages|src)/#', $p)) {
                $s += 20;
            }
            if (preg_match('#(layout|metadata|seo|head)#i', $p)) {
                $s += 40;
            }
            return -$s;
        };
        return $score($a) <=> $score($b);
    });
    $paths = array_slice(array_values(array_unique($paths)), 0, 4);
    if ($paths === []) {
        $paths = ['index.html'];
    }

    $owner = $parsed['owner'];
    $repo = $parsed['repo'];
    $token = $cfg['token'];
    $changes = [];

    foreach ($paths as $path) {
        $listUrl = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
            . '/commits?path=' . rawurlencode($path) . '&sha=' . rawurlencode($branch) . '&per_page=10';
        $listRes = seoAuditGithubHttp('GET', $listUrl, $token, 20);
        if (($listRes['code'] ?? 500) >= 400 || !is_array($listRes['data'])) {
            continue;
        }
        $commits = [];
        foreach ($listRes['data'] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $sha = (string) ($c['sha'] ?? '');
            if ($sha === '') {
                continue;
            }
            $commits[] = [
                'sha' => $sha,
                'short' => substr($sha, 0, 7),
                'date' => (string) ($c['commit']['author']['date'] ?? $c['commit']['committer']['date'] ?? ''),
                'message' => ersClip((string) ($c['commit']['message'] ?? ''), 120),
            ];
        }
        // API viene newest-first; para before→after recorremos de viejo a nuevo.
        $commits = array_reverse($commits);
        $prevTags = null;
        $prevSha = '';
        $prevDate = '';
        foreach ($commits as $commit) {
            $html = radiografiaGithubFileAt($owner, $repo, $path, $commit['sha'], $token);
            if ($html === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $html)) {
                continue;
            }
            $tags = radiografiaExtractSeoTags($html);
            if ($prevTags !== null) {
                $fields = [];
                foreach (['title', 'meta', 'h1'] as $field) {
                    if (($prevTags[$field] ?? '') !== ($tags[$field] ?? '')) {
                        $fields[] = $field;
                    }
                }
                if ($fields !== []) {
                    $changes[] = [
                        'path' => $path,
                        'sha' => $commit['short'],
                        'shaFull' => $commit['sha'],
                        'date' => substr($commit['date'], 0, 10),
                        'datetime' => $commit['date'],
                        'message' => $commit['message'],
                        'fields' => $fields,
                        'before' => $prevTags,
                        'after' => $tags,
                        'prevSha' => substr($prevSha, 0, 7),
                        'prevDate' => substr($prevDate, 0, 10),
                    ];
                }
            }
            $prevTags = $tags;
            $prevSha = $commit['sha'];
            $prevDate = $commit['date'];
        }
    }

    // Más recientes primero.
    usort($changes, static fn ($a, $b) => strcmp((string) ($b['datetime'] ?? ''), (string) ($a['datetime'] ?? '')));
    $changes = array_slice($changes, 0, 12);

    return [
        'ok' => true,
        'slug' => $parsed['slug'],
        'branch' => $branch,
        'paths' => $paths,
        'changes' => $changes,
        'latestTitleMeta' => $changes[0]['after'] ?? null,
        'newestChangeAt' => $changes[0]['datetime'] ?? '',
    ];
}

function radiografiaDomSnapshot(string $dataDir, string $host): array
{
    $dom = gscReadJson(seoAuditDomFile($dataDir, $host), []);
    $home = null;
    foreach ($dom['pages'] ?? [] as $key => $page) {
        if (!is_array($page)) {
            continue;
        }
        $url = (string) ($page['url'] ?? (is_string($key) ? $key : ''));
        $path = radiografiaPathKey($url !== '' ? $url : (string) ($page['path'] ?? '/'));
        if ($path !== '/') {
            continue;
        }
        $postal = '';
        $nap = is_array($page['nap'] ?? null) ? $page['nap'] : [];
        $postal = (string) ($nap['postalCode'] ?? '');
        $home = [
            'url' => $url,
            'title' => (string) ($page['title'] ?? ''),
            'meta' => (string) ($page['meta'] ?? ''),
            'h1' => (string) ($page['h1'] ?? ''),
            'hasFaq' => !empty($page['hasFaq']),
            'schemas' => is_array($page['schemas'] ?? null) ? $page['schemas'] : [],
            'postalCode' => $postal,
            'fetchedAt' => (int) ($page['fetchedAt'] ?? $dom['updatedAt'] ?? $dom['fetchedAt'] ?? 0),
        ];
        break;
    }
    return [
        'home' => $home,
        'domFetchedAt' => (int) ($dom['updatedAt'] ?? $dom['fetchedAt'] ?? 0),
    ];
}

/**
 * Detectores deterministas. Gemini no inventa umbrales: solo narra estos ids.
 */
function radiografiaDetect(array $facts): array
{
    $out = [];
    $win = $facts['windows'] ?? [];
    $siteNow = $facts['site']['now'] ?? radiografiaEmptyMetrics();
    $sitePrev = $facts['site']['prev'] ?? radiografiaEmptyMetrics();
    $devices = $facts['devices'] ?? [];
    $pages = $facts['pages'] ?? [];
    $git = $facts['gitSeo'] ?? [];
    $dom = $facts['dom']['home'] ?? null;

    $checkDevice = static function (string $device, array $now, array $prev) use (&$out): void {
        $impNow = (int) ($now['impressions'] ?? 0);
        $impPrev = (int) ($prev['impressions'] ?? 0);
        $clkNow = (int) ($now['clicks'] ?? 0);
        $clkPrev = (int) ($prev['clicks'] ?? 0);
        $ctrNow = (float) ($now['ctr'] ?? 0);
        $ctrPrev = (float) ($prev['ctr'] ?? 0);
        $posNow = (float) ($now['position'] ?? 0);
        $posPrev = (float) ($prev['position'] ?? 0);

        // Impresiones casi iguales (±8% o ±2 abs) y CTR cae ≥35% relativo con ≥4 clics previos.
        $impFlat = $impPrev >= 20
            && abs($impNow - $impPrev) <= max(2, (int) round($impPrev * 0.08));
        if ($impFlat && $clkPrev >= 4 && $ctrPrev > 0 && $ctrNow < $ctrPrev * 0.65) {
            $out[] = [
                'id' => 'sameImpDropCtr',
                'device' => $device,
                'severity' => 'alto',
                'title' => 'Misma demanda, menos clics (' . $device . ')',
                'evidence' => [
                    'impressionsPrev' => $impPrev,
                    'impressionsNow' => $impNow,
                    'clicksPrev' => $clkPrev,
                    'clicksNow' => $clkNow,
                    'ctrPrev' => $ctrPrev,
                    'ctrNow' => $ctrNow,
                ],
                'line' => sprintf(
                    '%s: %d→%d impr (planas), %d→%d clics, CTR %s→%s. Pistola humeante de snippet/title.',
                    $device,
                    $impPrev,
                    $impNow,
                    $clkPrev,
                    $clkNow,
                    radiografiaPct($ctrPrev),
                    radiografiaPct($ctrNow)
                ),
            ];
        }

        // Impresiones caen y posición media mejora → pérdida de cola (queries 8–15).
        $impDrop = $impPrev >= 40 && ($impPrev - $impNow) >= max(20, (int) round($impPrev * 0.25));
        $posBetter = $posPrev > 0 && $posNow > 0 && $posNow < ($posPrev - 0.15);
        if ($impDrop && $posBetter) {
            $out[] = [
                'id' => 'impDownPosUp',
                'device' => $device,
                'severity' => 'alto',
                'title' => 'Menos impresiones con mejor posición (' . $device . ')',
                'evidence' => [
                    'impressionsPrev' => $impPrev,
                    'impressionsNow' => $impNow,
                    'positionPrev' => $posPrev,
                    'positionNow' => $posNow,
                ],
                'line' => sprintf(
                    '%s: impr %d→%d (%+d), posición %.1f→%.1f. Dejaste de salir en búsquedas secundarias (cola), no caíste en el core.',
                    $device,
                    $impPrev,
                    $impNow,
                    $impNow - $impPrev,
                    $posPrev,
                    $posNow
                ),
            ];
        }
    };

    foreach (['desktop', 'mobile', 'tablet'] as $dev) {
        $now = $devices['now'][$dev] ?? radiografiaEmptyMetrics();
        $prev = $devices['prev'][$dev] ?? radiografiaEmptyMetrics();
        if (((int) ($now['impressions'] ?? 0) + (int) ($prev['impressions'] ?? 0)) < 10) {
            continue;
        }
        $checkDevice($dev === 'desktop' ? 'ordenador' : $dev, $now, $prev);
    }

    // Anti-canibalización: tráfico concentrado en home y landings internas ~0 impr.
    $withImp = 0;
    $zeroImp = 0;
    $homeImp = 0;
    $sitemapCount = (int) ($pages['sitemapCount'] ?? 0);
    $withTraffic = [];
    foreach ($pages['now'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $imp = (int) ($row['impressions'] ?? 0);
        $path = (string) ($row['path'] ?? '/');
        if ($imp > 0) {
            $withImp++;
            $withTraffic[] = ['path' => $path, 'impressions' => $imp, 'clicks' => (int) ($row['clicks'] ?? 0)];
            if ($path === '/') {
                $homeImp = $imp;
            }
        }
    }
    foreach ($pages['sitemapPaths'] ?? [] as $path) {
        $found = false;
        foreach ($pages['now'] ?? [] as $row) {
            if ((string) ($row['path'] ?? '') === $path && (int) ($row['impressions'] ?? 0) > 0) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $zeroImp++;
        }
    }
    $totalImp = (int) ($siteNow['impressions'] ?? 0);
    if ($sitemapCount >= 5 && $withImp <= max(4, (int) ceil($sitemapCount * 0.35)) && $homeImp >= (int) round($totalImp * 0.7)) {
        $out[] = [
            'id' => 'zeroImpLandings',
            'severity' => 'medio',
            'title' => 'No es canibalización: landings sin impresiones',
            'evidence' => [
                'sitemapCount' => $sitemapCount,
                'urlsWithImpressions' => $withImp,
                'urlsZeroImpressions' => $zeroImp,
                'homeImpressions' => $homeImp,
                'siteImpressions' => $totalImp,
                'withTraffic' => array_slice($withTraffic, 0, 8),
            ],
            'line' => sprintf(
                'Sitemap %d URLs; solo %d con ≥1 impr en 7d (home %d impr). Google aún no muestra las landings nuevas: el peso sigue en /.',
                $sitemapCount,
                $withImp,
                $homeImp
            ),
        ];
    }

    // Cuello de botella de indexación.
    $notIndexed = [];
    foreach ($pages['index'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tone = (string) ($row['tone'] ?? '');
        $label = strtolower((string) ($row['label'] ?? ''));
        $path = (string) ($row['path'] ?? '');
        if ($path === '/' || $path === '') {
            continue;
        }
        $bad = in_array($tone, ['bad', 'warn'], true)
            || str_contains($label, 'no index')
            || str_contains($label, 'descubierta')
            || str_contains($label, 'excluded')
            || str_contains($label, 'sin index');
        // Solo cobertura real (no mezclar con “0 impr” — eso va en zeroImpLandings).
        if ($bad) {
            $notIndexed[] = [
                'path' => $path,
                'label' => (string) ($row['label'] ?? ''),
                'impressions7d' => (int) ($row['impressions7d'] ?? 0),
                'lastmod' => (string) ($row['lastmod'] ?? ''),
            ];
        }
    }
    if (count($notIndexed) >= 2) {
        $out[] = [
            'id' => 'indexBottleneck',
            'severity' => 'alto',
            'title' => 'Cuello de botella de indexación',
            'evidence' => ['pages' => array_slice($notIndexed, 0, 12)],
            'line' => count($notIndexed) . ' URLs internas sin tráfico o con cobertura floja. Pedí inspección/sitemap para las prioritarias.',
        ];
    }

    // Cooldown title/H1 tras cambio reciente.
    $newestGit = (string) ($git['newestChangeAt'] ?? '');
    $titleTouched = false;
    $metaTouched = false;
    $recentChanges = [];
    foreach ($git['changes'] ?? [] as $ch) {
        if (!is_array($ch)) {
            continue;
        }
        $fields = $ch['fields'] ?? [];
        if (in_array('title', $fields, true) || in_array('h1', $fields, true)) {
            $titleTouched = true;
        }
        if (in_array('meta', $fields, true)) {
            $metaTouched = true;
        }
        $recentChanges[] = $ch;
        if (count($recentChanges) >= 5) {
            break;
        }
    }
    $daysSince = null;
    if ($newestGit !== '') {
        try {
            $dt = new DateTimeImmutable($newestGit);
            $daysSince = (int) floor((time() - $dt->getTimestamp()) / 86400);
        } catch (Throwable) {
            $daysSince = null;
        }
    }
    if ($titleTouched && $daysSince !== null && $daysSince < RADIOGRAFIA_TITLE_COOLDOWN_DAYS) {
        $out[] = [
            'id' => 'titleCooldown',
            'severity' => 'alto',
            'title' => 'No tocar title/H1 todavía',
            'evidence' => [
                'daysSinceChange' => $daysSince,
                'cooldownDays' => RADIOGRAFIA_TITLE_COOLDOWN_DAYS,
                'newestChangeAt' => $newestGit,
                'changes' => $recentChanges,
            ],
            'line' => sprintf(
                'Title/H1 se movió hace %d día(s). Esperá al menos %d–21 días antes de volver a tocarlos; el snippet limpio aún puede no medir en GSC.',
                $daysSince,
                RADIOGRAFIA_TITLE_COOLDOWN_DAYS
            ),
        ];
    } elseif ($metaTouched && $recentChanges !== []) {
        $out[] = [
            'id' => 'metaSnippetShift',
            'severity' => 'alto',
            'title' => 'Cambio de meta description en el periodo',
            'evidence' => ['changes' => $recentChanges],
            'line' => 'Hubo cambio de meta description en commits recientes: candidato a explicar caída de CTR con impresiones planas.',
        ];
    }

    // lastmod desfasado vs cambio de title.
    $homeLastmod = (string) ($pages['homeLastmod'] ?? '');
    if ($newestGit !== '' && $homeLastmod !== '') {
        $gitDay = substr($newestGit, 0, 10);
        if ($gitDay > $homeLastmod) {
            $out[] = [
                'id' => 'lastmodStale',
                'severity' => 'bajo',
                'title' => 'sitemap lastmod desfasado',
                'evidence' => ['lastmod' => $homeLastmod, 'gitChange' => $gitDay],
                'line' => "lastmod de / en sitemap es {$homeLastmod}; el último cambio SEO en git es {$gitDay}. Actualizá lastmod.",
            ];
        }
    }

    // FAQ ausente solo si hubo edición reciente de la home (si no, puede ser así desde siempre).
    if (is_array($dom) && array_key_exists('hasFaq', $dom) && empty($dom['hasFaq']) && $recentChanges !== []) {
        $homeEdited = false;
        foreach ($recentChanges as $ch) {
            $p = strtolower((string) ($ch['path'] ?? ''));
            if ($p !== '' && (str_ends_with($p, 'index.html') || str_contains($p, 'layout') || str_contains($p, 'page.'))) {
                $homeEdited = true;
                break;
            }
        }
        if ($homeEdited) {
            $out[] = [
                'id' => 'faqMissing',
                'severity' => 'medio',
                'title' => 'FAQ ausente en home tras edits recientes',
                'evidence' => ['hasFaq' => false],
                'line' => 'La home scrapeada no tiene FAQ detectable y hubo commits SEO recientes. Si se borró una pregunta de alta conversión, restaurarla (y postalCode en Schema si aplica).',
            ];
        }
    }

    $out[] = [
        'id' => 'gscLag',
        'severity' => 'bajo',
        'title' => 'Desfase Search Console',
        'evidence' => ['windows' => $win],
        'line' => (string) ($win['lagNote'] ?? 'GSC lleva 24–48 h de desfase.'),
    ];

    // Posición sitio.
    $posNow = (float) ($siteNow['position'] ?? 0);
    $posPrev = (float) ($sitePrev['position'] ?? 0);
    if ($posPrev > 0 && $posNow > 0) {
        $out[] = [
            'id' => 'positionTrend',
            'severity' => 'bajo',
            'title' => 'Posición media sitio',
            'evidence' => ['prev' => $posPrev, 'now' => $posNow],
            'line' => sprintf(
                'Posición media sitio %.1f→%.1f (%s).',
                $posPrev,
                $posNow,
                $posNow < $posPrev ? 'mejoró' : ($posNow > $posPrev ? 'empeoró' : 'igual')
            ),
        ];
    }

    return $out;
}

function radiografiaBuildTable(array $facts): array
{
    $siteNow = $facts['site']['now'] ?? radiografiaEmptyMetrics();
    $sitePrev = $facts['site']['prev'] ?? radiografiaEmptyMetrics();
    $devNow = $facts['devices']['now'] ?? [];
    $devPrev = $facts['devices']['prev'] ?? [];
    $deskNow = radiografiaDeviceRow($devNow, 'desktop');
    $deskPrev = radiografiaDeviceRow($devPrev, 'desktop');
    $mobNow = radiografiaDeviceRow($devNow, 'mobile');
    $mobPrev = radiografiaDeviceRow($devPrev, 'mobile');

    $fmtDev = static function (array $m): string {
        $imp = (int) ($m['impressions'] ?? 0);
        $clk = (int) ($m['clicks'] ?? 0);
        $ctr = radiografiaPct((float) ($m['ctr'] ?? 0));
        return "{$imp} impr. / {$clk} clics ({$ctr} CTR)";
    };

    $pagesWith = [];
    foreach ($facts['pages']['now'] ?? [] as $row) {
        if ((int) ($row['impressions'] ?? 0) > 0) {
            $pagesWith[] = ($row['path'] ?? '/') . ' (' . (int) $row['impressions'] . ')';
        }
    }

    return [
        [
            'metric' => 'Posición media',
            'prev' => round((float) ($sitePrev['position'] ?? 0), 1),
            'now' => round((float) ($siteNow['position'] ?? 0), 1),
            'diff' => round((float) ($sitePrev['position'] ?? 0) - (float) ($siteNow['position'] ?? 0), 1),
            'note' => 'Negativo en diff = peor posición (sube el número).',
        ],
        [
            'metric' => 'Ordenador (PC)',
            'prev' => $fmtDev($deskPrev),
            'now' => $fmtDev($deskNow),
            'diff' => sprintf(
                '%+d impr. / %+d clics',
                (int) $deskNow['impressions'] - (int) $deskPrev['impressions'],
                (int) $deskNow['clicks'] - (int) $deskPrev['clicks']
            ),
            'note' => '',
        ],
        [
            'metric' => 'Móviles',
            'prev' => $fmtDev($mobPrev),
            'now' => $fmtDev($mobNow),
            'diff' => sprintf(
                '%+d impr. / %+d clics',
                (int) $mobNow['impressions'] - (int) $mobPrev['impressions'],
                (int) $mobNow['clicks'] - (int) $mobPrev['clicks']
            ),
            'note' => '',
        ],
        [
            'metric' => 'Páginas con tráfico',
            'prev' => (string) ($facts['pages']['prevWithTraffic'] ?? '—'),
            'now' => $pagesWith !== [] ? implode(', ', array_slice($pagesWith, 0, 8)) : 'ninguna',
            'diff' => ((int) ($facts['pages']['sitemapCount'] ?? 0) - count($pagesWith)) . ' URLs del sitemap sin impr.',
            'note' => '',
        ],
    ];
}

function radiografiaFallbackMarkdown(array $facts, array $detectors): string
{
    $host = (string) ($facts['host'] ?? '');
    $win = $facts['windows'] ?? [];
    $lines = [];
    $lines[] = "# Radiografía 7 días — {$host}";
    $lines[] = '';
    $lines[] = sprintf(
        'Ventana: %s→%s vs %s→%s (lag GSC −3 días). %s',
        $win['start'] ?? '',
        $win['end'] ?? '',
        $win['prevStart'] ?? '',
        $win['prevEnd'] ?? '',
        $win['lagNote'] ?? ''
    );
    $lines[] = '';
    $lines[] = '## 1. Números exactos';
    $lines[] = '';
    $lines[] = '| Métrica | 7 días anteriores | Últimos 7 días | Diferencia |';
    $lines[] = '|---|---|---|---|';
    foreach (radiografiaBuildTable($facts) as $row) {
        $lines[] = '| ' . $row['metric'] . ' | ' . $row['prev'] . ' | ' . $row['now'] . ' | ' . $row['diff'] . ' |';
    }
    $lines[] = '';
    $lines[] = '## 2. Conclusiones (detectores)';
    $lines[] = '';
    $n = 0;
    foreach ($detectors as $d) {
        if (($d['id'] ?? '') === 'gscLag' || ($d['id'] ?? '') === 'positionTrend') {
            continue;
        }
        $n++;
        $lines[] = "### " . chr(64 + min($n, 26)) . '. ' . ($d['title'] ?? $d['id']);
        $lines[] = (string) ($d['line'] ?? '');
        $lines[] = '';
        if ($n >= 3) {
            break;
        }
    }
    if ($n === 0) {
        $lines[] = 'Sin anomalías fuertes en 7d; revisá CONSULTAS en Search Console por si hay variación de demanda.';
        $lines[] = '';
    }

    $git = $facts['gitSeo']['changes'] ?? [];
    if ($git !== []) {
        $lines[] = '## Commits SEO (title/meta/H1)';
        $lines[] = '';
        foreach (array_slice($git, 0, 5) as $ch) {
            $fields = implode(', ', $ch['fields'] ?? []);
            $lines[] = sprintf(
                '- `%s` (%s) en `%s` — cambió %s',
                $ch['sha'] ?? '',
                $ch['date'] ?? '',
                $ch['path'] ?? '',
                $fields
            );
            if (in_array('meta', $ch['fields'] ?? [], true)) {
                $lines[] = '  - meta antes: ' . ersClip((string) ($ch['before']['meta'] ?? ''), 160);
                $lines[] = '  - meta después: ' . ersClip((string) ($ch['after']['meta'] ?? ''), 160);
            }
            if (in_array('title', $ch['fields'] ?? [], true)) {
                $lines[] = '  - title antes: ' . ersClip((string) ($ch['before']['title'] ?? ''), 120);
                $lines[] = '  - title después: ' . ersClip((string) ($ch['after']['title'] ?? ''), 120);
            }
        }
        $lines[] = '';
    }

    $lines[] = '## 3. Qué hacer ahora (por impacto)';
    $lines[] = '';
    $ids = array_column($detectors, 'id');
    if (in_array('titleCooldown', $ids, true)) {
        $lines[] = '1. **NO vuelvas a cambiar el `<title>` ni el `<h1>` de la Home** por al menos 14–21 días.';
    }
    $lines[] = '- Revisá en Search Console la pestaña CONSULTAS ordenada por Impresiones diferencia.';
    $lines[] = '- En Indexación → Páginas, pedí inspección manual de / y de las landings prioritarias sin impr.';
    if (in_array('lastmodStale', $ids, true)) {
        $lines[] = '- Actualizá `<lastmod>` de la home en sitemap.xml a la fecha del último cambio SEO.';
    }
    if (in_array('faqMissing', $ids, true)) {
        $lines[] = '- Revisá/restaurá FAQ de alta conversión y postalCode en Schema si se borraron.';
    }
    $lines[] = '';
    foreach ($detectors as $d) {
        if (($d['id'] ?? '') === 'gscLag') {
            $lines[] = '> ' . ($d['line'] ?? '');
        }
    }

    return implode("\n", $lines);
}

function radiografiaNarrativeSchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'markdown' => ['type' => 'string'],
        ],
        'required' => ['markdown'],
    ];
}

function radiografiaNarrate(array $facts, array $detectors): array
{
    $cfg = ersLoadGeminiConfig();
    if ($cfg['apiKey'] === '') {
        return [
            'markdown' => radiografiaFallbackMarkdown($facts, $detectors),
            'narrative' => 'fallback',
            'model' => '',
        ];
    }

    $payloadFacts = [
        'host' => $facts['host'],
        'windows' => $facts['windows'],
        'table' => radiografiaBuildTable($facts),
        'site' => $facts['site'],
        'devices' => $facts['devices'],
        'pages' => [
            'sitemapCount' => $facts['pages']['sitemapCount'] ?? 0,
            'now' => array_slice($facts['pages']['now'] ?? [], 0, 20),
            'homeLastmod' => $facts['pages']['homeLastmod'] ?? '',
            'indexSample' => array_slice($facts['pages']['index'] ?? [], 0, 15),
        ],
        'gitSeo' => [
            'slug' => $facts['gitSeo']['slug'] ?? '',
            'changes' => array_slice($facts['gitSeo']['changes'] ?? [], 0, 8),
            'newestChangeAt' => $facts['gitSeo']['newestChangeAt'] ?? '',
        ],
        'domHome' => $facts['dom']['home'] ?? null,
        'detectors' => $detectors,
    ];

    $system = <<<TXT
Sos un SEO forense senior (español neutro-chileno). Escribís una RADIOGRAFÍA SEMANAL interna.
Solo podés usar los datos del JSON. No inventes métricas, keywords, commits, URLs ni fechas.
Si un detector no está, no lo afirmes. Citá SHAs cortos y números exactos del JSON.
Estructura obligatoria en markdown:
1) Intro de 2–4 frases (qué revelan los números; si aplica, descartá canibalización con evidencia).
2) Sección "Lo que revelan los números" con tabla markdown (Métrica | 7 días anteriores | Últimos 7 días | Diferencia | Diagnóstico).
3) "Las 3 conclusiones clave" (A/B/C) ligadas a detectors (sameImpDropCtr, impDownPosUp, zeroImpLandings, indexBottleneck, metaSnippetShift, titleCooldown…).
4) "Qué hacer ahora" ordenado por impacto: incluir NO tocar title/H1 si hay titleCooldown; acciones GSC (Consultas, Indexación); ajustes quirúrgicos de código sin tocar title si aplica.
Mencioná el desfase 24–48 h de GSC si hay cambios recientes.
Salida JSON: { "markdown": "..." } nada más.
TXT;

    $body = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => json_encode($payloadFacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
        ]],
        'generationConfig' => array_merge([
            'temperature' => 0.25,
            'maxOutputTokens' => 8192,
            'responseMimeType' => 'application/json',
            'responseSchema' => radiografiaNarrativeSchema(),
        ], ersGeminiThinkingConfig($cfg)),
    ];

    try {
        @set_time_limit(RADIOGRAFIA_AI_TIMEOUT + 15);
        $res = ersGeminiHttp(ersGeminiUrl($cfg), $body, RADIOGRAFIA_AI_TIMEOUT);
        $text = '';
        foreach ($res['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (empty($part['thought']) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }
        $text = trim((string) preg_replace('#^```(?:json)?|```$#m', '', $text));
        $raw = json_decode($text, true);
        $md = is_array($raw) ? trim((string) ($raw['markdown'] ?? '')) : '';
        if ($md === '') {
            return [
                'markdown' => radiografiaFallbackMarkdown($facts, $detectors),
                'narrative' => 'fallback',
                'model' => (string) ($cfg['model'] ?? ''),
            ];
        }
        return [
            'markdown' => $md,
            'narrative' => 'gemini',
            'model' => (string) ($cfg['model'] ?? ''),
            'thinkingLevel' => (string) ($cfg['thinkingLevel'] ?? ''),
        ];
    } catch (Throwable $e) {
        return [
            'markdown' => radiografiaFallbackMarkdown($facts, $detectors)
                . "\n\n> Nota: Gemini no respondió (" . ersClip($e->getMessage(), 120) . ').',
            'narrative' => 'fallback',
            'model' => (string) ($cfg['model'] ?? ''),
        ];
    }
}

function radiografiaBuildFacts(string $dataDir, string $host, string $repoRaw): array
{
    $site = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    if (($site['host'] ?? '') !== $host) {
        throw new RuntimeException('Primero actualizá el SEO del sitio (Search Console).');
    }

    $windows = radiografiaWindows();
    $daily = is_array($site['daily'] ?? null) ? $site['daily'] : [];
    $fromDailyNow = gscTotalsFromDaily(gscDailyInRange($daily, $windows['start'], $windows['end']));
    $fromDailyPrev = gscTotalsFromDaily(gscDailyInRange($daily, $windows['prevStart'], $windows['prevEnd']));

    $property = (string) ($site['diagnostics']['property'] ?? '');
    if ($property === '') {
        foreach ($site['pages'] ?? [] as $row) {
            if (!empty($row['property'])) {
                $property = (string) $row['property'];
                break;
            }
        }
    }

    $homeUrl = radiografiaHomeUrl($site, $host);
    $sliceNow = [
        'totals' => $fromDailyNow,
        'devices' => [],
        'homeDevices' => [],
        'pages' => [],
        'ok' => false,
    ];
    $slicePrev = [
        'totals' => $fromDailyPrev,
        'devices' => [],
        'homeDevices' => [],
        'pages' => [],
        'ok' => false,
    ];

    if ($property !== '') {
        try {
            $sa = gscLoadServiceAccount($dataDir, $dataDir . '/gsc-service-account.json');
            $token = gscAccessToken($sa, $dataDir . '/gsc-token.json');
            $sliceNow = radiografiaFetchGscSlice($token, $property, $windows['start'], $windows['end'], $homeUrl);
            $slicePrev = radiografiaFetchGscSlice($token, $property, $windows['prevStart'], $windows['prevEnd'], $homeUrl);
            // Preferir totales API si vinieron; si no, daily.
            if ((int) ($sliceNow['totals']['impressions'] ?? 0) === 0 && (int) ($fromDailyNow['impressions'] ?? 0) > 0) {
                $sliceNow['totals'] = $fromDailyNow;
            }
            if ((int) ($slicePrev['totals']['impressions'] ?? 0) === 0 && (int) ($fromDailyPrev['impressions'] ?? 0) > 0) {
                $slicePrev['totals'] = $fromDailyPrev;
            }
        } catch (Throwable $e) {
            // Seguimos con daily + devices vacíos.
            $sliceNow['fetchError'] = ersClip($e->getMessage(), 160);
        }
    }

    // Preferir device de home si tiene volumen comparable; si no, sitio.
    $pickDevices = static function (array $slice): array {
        $siteDev = $slice['devices'] ?? [];
        $homeDev = $slice['homeDevices'] ?? [];
        $homeImp = 0;
        foreach ($homeDev as $m) {
            $homeImp += (int) ($m['impressions'] ?? 0);
        }
        $siteImp = 0;
        foreach ($siteDev as $m) {
            $siteImp += (int) ($m['impressions'] ?? 0);
        }
        if ($homeImp >= 20 && $siteImp > 0 && $homeImp >= (int) round($siteImp * 0.55)) {
            return ['map' => $homeDev, 'scope' => 'home'];
        }
        return ['map' => $siteDev, 'scope' => 'site'];
    };
    $devNowPack = $pickDevices($sliceNow);
    $devPrevPack = $pickDevices($slicePrev);

    $sitemapPaths = [];
    $homeLastmod = '';
    $indexRows = [];
    $impNowByPath = [];
    foreach ($sliceNow['pages'] as $key => $row) {
        $path = radiografiaPathKey((string) ($row['path'] ?? $row['url'] ?? '/'));
        $impNowByPath[$path] = (int) ($row['metrics']['impressions'] ?? 0);
    }

    foreach ($site['pages'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $url = (string) ($row['url'] ?? '');
        if ($url === '' || !gscUrlBelongsToHost($url, $host)) {
            continue;
        }
        $path = radiografiaPathKey($url);
        $sitemapPaths[$path] = true;
        if ($path === '/' && $homeLastmod === '') {
            $homeLastmod = (string) ($row['lastmod'] ?? '');
        }
        $status = is_array($row['indexStatus'] ?? null) ? $row['indexStatus'] : [];
        $indexRows[] = [
            'path' => $path,
            'url' => $url,
            'label' => (string) ($status['label'] ?? ''),
            'tone' => (string) ($status['tone'] ?? ''),
            'lastCrawl' => (string) ($status['lastCrawl'] ?? ''),
            'lastmod' => (string) ($row['lastmod'] ?? ''),
            'impressions7d' => (int) ($impNowByPath[$path] ?? 0),
            'inSitemap' => true,
        ];
    }

    $pagesNow = [];
    foreach ($sliceNow['pages'] as $row) {
        $path = radiografiaPathKey((string) ($row['path'] ?? '/'));
        $m = $row['metrics'];
        $pagesNow[] = [
            'path' => $path,
            'url' => (string) ($row['url'] ?? ''),
            'clicks' => (int) ($m['clicks'] ?? 0),
            'impressions' => (int) ($m['impressions'] ?? 0),
            'ctr' => (float) ($m['ctr'] ?? 0),
            'position' => (float) ($m['position'] ?? 0),
        ];
    }
    usort($pagesNow, static fn ($a, $b) => ($b['impressions'] <=> $a['impressions']));

    $pagesPrev = [];
    foreach ($slicePrev['pages'] as $row) {
        $path = radiografiaPathKey((string) ($row['path'] ?? '/'));
        $m = $row['metrics'];
        $pagesPrev[] = [
            'path' => $path,
            'impressions' => (int) ($m['impressions'] ?? 0),
            'clicks' => (int) ($m['clicks'] ?? 0),
        ];
    }
    $prevWith = [];
    foreach ($pagesPrev as $row) {
        if ($row['impressions'] > 0) {
            $prevWith[] = $row['path'] . ' (' . $row['impressions'] . ')';
        }
    }

    $git = radiografiaGitSeoDiff($dataDir, $host, $repoRaw);
    $dom = radiografiaDomSnapshot($dataDir, $host);

    return [
        'host' => $host,
        'property' => $property,
        'homeUrl' => $homeUrl,
        'windows' => $windows,
        'deviceScope' => $devNowPack['scope'],
        'site' => [
            'now' => $sliceNow['totals'],
            'prev' => $slicePrev['totals'],
            'delta' => gscDelta($sliceNow['totals'], $slicePrev['totals']),
        ],
        'devices' => [
            'now' => $devNowPack['map'],
            'prev' => $devPrevPack['map'],
        ],
        'pages' => [
            'sitemapCount' => count($sitemapPaths),
            'sitemapPaths' => array_keys($sitemapPaths),
            'now' => $pagesNow,
            'prevWithTraffic' => $prevWith !== [] ? implode(', ', array_slice($prevWith, 0, 8)) : '—',
            'homeLastmod' => $homeLastmod,
            'index' => $indexRows,
        ],
        'gitSeo' => $git,
        'dom' => $dom,
        'gscFetchedAt' => (int) ($site['fullFetchedAt'] ?? $site['fetchedAt'] ?? 0),
        'fetchError' => (string) ($sliceNow['fetchError'] ?? ''),
    ];
}

function radiografiaRun(string $dataDir, string $host, string $repoRaw = '', bool $fresh = false): array
{
    $cacheFile = radiografiaFile($dataDir, $host);
    $cached = gscReadJson($cacheFile, []);
    $site = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    $gscAt = (int) ($site['fullFetchedAt'] ?? $site['fetchedAt'] ?? 0);
    $repoCache = gscReadJson(seoAuditRepoFile($dataDir, $host), []);
    $commitSha = (string) ($repoCache['commitSha'] ?? '');

    if (
        !$fresh
        && ($cached['host'] ?? '') === $host
        && ($cached['version'] ?? '') === RADIOGRAFIA_VERSION
        && (int) ($cached['gscFetchedAt'] ?? 0) === $gscAt
        && (string) ($cached['commitSha'] ?? '') === $commitSha
        && (time() - (int) ($cached['generatedAt'] ?? 0)) < RADIOGRAFIA_TTL
        && is_string($cached['markdown'] ?? null)
        && $cached['markdown'] !== ''
    ) {
        $cached['cached'] = true;
        return $cached;
    }

    @set_time_limit(90);
    $facts = radiografiaBuildFacts($dataDir, $host, $repoRaw);
    $detectors = radiografiaDetect($facts);
    $narration = radiografiaNarrate($facts, $detectors);

    $payload = [
        'ok' => true,
        'cached' => false,
        'host' => $host,
        'version' => RADIOGRAFIA_VERSION,
        'generatedAt' => time(),
        'gscFetchedAt' => (int) ($facts['gscFetchedAt'] ?? 0),
        'commitSha' => $commitSha,
        'windows' => $facts['windows'],
        'table' => radiografiaBuildTable($facts),
        'detectors' => $detectors,
        'gitSeo' => [
            'ok' => !empty($facts['gitSeo']['ok']),
            'slug' => $facts['gitSeo']['slug'] ?? '',
            'changes' => array_slice($facts['gitSeo']['changes'] ?? [], 0, 8),
            'newestChangeAt' => $facts['gitSeo']['newestChangeAt'] ?? '',
            'reason' => $facts['gitSeo']['reason'] ?? '',
        ],
        'site' => $facts['site'],
        'devices' => $facts['devices'],
        'deviceScope' => $facts['deviceScope'] ?? 'site',
        'markdown' => $narration['markdown'],
        'narrative' => $narration['narrative'],
        'model' => $narration['model'] ?? '',
        'thinkingLevel' => $narration['thinkingLevel'] ?? '',
        'fetchError' => $facts['fetchError'] ?? '',
    ];
    gscWriteJson($cacheFile, $payload);
    return $payload;
}
