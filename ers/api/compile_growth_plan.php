<?php
/**
 * Motor de mejora continua. Solo corre con el botón del proyecto:
 * GSC + GA4 + SERP local + On-Page, y Gemini devuelve tareas persistentes.
 *
 * GET  action=status&proyecto_id=&host=
 * GET  action=locations&proyecto_id=&q=
 * POST action=run     { proyecto_id, url_objetivo, keyword, location_code, force? }
 * POST action=task    { proyecto_id, host, task_id, estado }
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/DataForSeoService.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const ERS_GROWTH_TTL = 604800;

function ersGrowthFail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => ersClip($message, 400)], JSON_UNESCAPED_UNICODE);
    exit;
}

function ersGrowthFold(string $value): string
{
    $value = trim($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function ersGrowthNormUrl(string $url): string
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $host = strtolower((string) $parts['host']);
    $host = preg_replace('#^www\.#', '', $host) ?? $host;
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    $path = $path === '/' ? '/' : rtrim(rawurldecode($path), '/');
    return 'https://' . $host . $path;
}

function ersGrowthSameUrl(string $a, string $b): bool
{
    $na = ersGrowthNormUrl($a);
    $nb = ersGrowthNormUrl($b);
    return $na !== '' && $na === $nb;
}

function ersGrowthClient(string $projectId): ?array
{
    $projectId = strtolower(trim($projectId));
    if (!ersLooksLikeUuid($projectId)) {
        return null;
    }
    $store = ersReadStore();
    $idx = ersFindClientIndex($store, $projectId);
    if ($idx < 0 || !is_array($store['clients'][$idx] ?? null)) {
        return null;
    }
    return $store['clients'][$idx];
}

/** La URL tiene que ser de este cliente. Un alias no abre el sitio de otro. */
function ersGrowthAssertUrl(array $client, string $url): string
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) {
        throw new InvalidArgumentException('La URL objetivo no es válida.');
    }
    $host = gscSanitizeHost($url);
    $allowed = [];
    foreach (ersClientHosts($client) as $candidate) {
        $clean = gscSanitizeHost($candidate);
        if ($clean !== '') {
            $allowed[$clean] = true;
        }
    }
    if ($host === '' || !isset($allowed[$host])) {
        throw new InvalidArgumentException('La URL no pertenece a este proyecto.');
    }
    return $host;
}

function ersGrowthPlanPath(string $dataDir, string $projectId, string $host): string
{
    return $dataDir . '/dfs/' . strtolower($projectId) . '/latest-' . md5($host) . '.json';
}

function ersGrowthReadPlan(string $dataDir, string $projectId, string $host): ?array
{
    $plan = gscReadJson(ersGrowthPlanPath($dataDir, $projectId, $host), []);
    if (($plan['proyecto_id'] ?? '') !== strtolower($projectId) || ($plan['host'] ?? '') !== $host) {
        return null;
    }
    return $plan;
}

function ersGrowthWritePlan(string $dataDir, array $plan): void
{
    $path = ersGrowthPlanPath($dataDir, (string) $plan['proyecto_id'], (string) $plan['host']);
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo guardar el plan.');
    }
    if (!gscWriteJson($path, $plan)) {
        throw new RuntimeException('No se pudo guardar el plan.');
    }
}

/** @return array<string, mixed> */
function ersGrowthGsc(string $dataDir, string $host, string $url): array
{
    $empty = [
        'ok' => false,
        'source' => 'none',
        'error' => '',
        'range' => ['start' => '', 'end' => '', 'days' => 28],
        'url' => $url,
        'clicks' => 0,
        'impressions' => 0,
        'ctr' => 0,
        'position' => 0,
        'queries_4_15' => [],
    ];
    $cache = gscReadJson(gscHostCacheFile($dataDir, $host), []);
    if (($cache['host'] ?? '') !== $host) {
        $cache = [];
    }
    $exact = $url;
    foreach ($cache['pages'] ?? [] as $row) {
        if (is_array($row) && ersGrowthSameUrl((string) ($row['url'] ?? ''), $url)) {
            $exact = (string) $row['url'];
            break;
        }
    }
    try {
        $sa = gscLoadServiceAccount($dataDir, $dataDir . '/gsc-service-account.json');
        if ($sa === []) {
            throw new RuntimeException('Falta la cuenta de servicio de Search Console.');
        }
        $token = gscAccessToken($sa, $dataDir . '/gsc-token.json');
        $property = (string) ($cache['diagnostics']['property'] ?? '');
        $end = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-3 days');
        $start = $end->modify('-27 days');
        $range = ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'days' => 28];
        $pull = static function (string $property, string $pageUrl) use ($token, $range): array {
            $filter = [[
                'filters' => [[
                    'dimension' => 'page',
                    'operator' => 'equals',
                    'expression' => $pageUrl,
                ]],
            ]];
            $totals = gscQueryAnalytics($token, $property, $range['start'], $range['end'], [
                'dimensionFilterGroups' => $filter,
                'rowLimit' => 1,
            ]);
            $queries = gscQueryAnalytics($token, $property, $range['start'], $range['end'], [
                'dimensions' => ['query'],
                'dimensionFilterGroups' => $filter,
                'rowLimit' => 100,
            ]);
            return [$totals, $queries];
        };
        if ($property === '') {
            $sites = gscListSites($token);
            $resolved = gscResolveProperty($token, $exact, $sites);
            $property = (string) ($resolved['property'] ?? '');
            if ($property === '') {
                throw new RuntimeException((string) ($resolved['error'] ?? 'Sin acceso a Search Console.'));
            }
        }
        try {
            [$totals, $queries] = $pull($property, $exact);
        } catch (Throwable $first) {
            $sites = gscListSites($token);
            $resolved = gscResolveProperty($token, $exact, $sites);
            $property = (string) ($resolved['property'] ?? '');
            if ($property === '') {
                throw $first;
            }
            [$totals, $queries] = $pull($property, $exact);
        }
        $metric = gscMetricRow($totals['rows'][0] ?? null);
        if ($metric['impressions'] === 0 && $exact !== $url) {
            [$totals, $queries] = $pull($property, $url);
            $metric = gscMetricRow($totals['rows'][0] ?? null);
            $exact = $url;
        }
        return [
            'ok' => true,
            'source' => 'live',
            'error' => '',
            'range' => $range,
            'url' => $exact,
            'clicks' => $metric['clicks'],
            'impressions' => $metric['impressions'],
            'ctr' => round((float) $metric['ctr'], 4),
            'position' => round((float) $metric['position'], 1),
            'queries_4_15' => ersGrowthQueries($queries),
        ];
    } catch (Throwable $e) {
        $fallback = ersGrowthGscFromCache($cache, $dataDir, $host, $url);
        if ($fallback !== []) {
            $fallback['ok'] = true;
            $fallback['error'] = '';
            return $fallback;
        }
        $empty['error'] = ersClip($e->getMessage(), 300);
        return $empty;
    }
}

/** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
function ersGrowthQueries(array $payload): array
{
    $out = [];
    foreach ($payload['rows'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $query = trim((string) ($row['keys'][0] ?? ''));
        $pos = (float) ($row['position'] ?? 0);
        if ($query === '' || $pos < 4 || $pos > 15) {
            continue;
        }
        $out[] = [
            'query' => ersClip($query, 160),
            'clicks' => (int) round((float) ($row['clicks'] ?? 0)),
            'impressions' => (int) round((float) ($row['impressions'] ?? 0)),
            'ctr' => round((float) ($row['ctr'] ?? 0), 4),
            'position' => round($pos, 1),
        ];
    }
    usort($out, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
    return array_slice($out, 0, 25);
}

/** @param array<string, mixed> $cache @return array<string, mixed> */
function ersGrowthGscFromCache(array $cache, string $dataDir, string $host, string $url): array
{
    if (($cache['host'] ?? '') !== $host) {
        return [];
    }
    $metric = null;
    $exact = $url;
    foreach ($cache['pages'] ?? [] as $row) {
        if (!is_array($row) || !ersGrowthSameUrl((string) ($row['url'] ?? ''), $url)) {
            continue;
        }
        $metric = is_array($row['current'] ?? null) ? $row['current'] : null;
        $exact = (string) $row['url'];
        break;
    }
    $queries = [];
    $kw = gscReadJson(gscKeywordCacheFile($dataDir, $host), []);
    if (($kw['host'] ?? '') === $host) {
        $seen = [];
        foreach ($kw['buckets'] ?? [] as $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row) || !ersGrowthSameUrl((string) ($row['page'] ?? ''), $url)) {
                    continue;
                }
                $pos = (float) ($row['position'] ?? 0);
                $query = trim((string) ($row['q'] ?? ''));
                if ($query === '' || $pos < 4 || $pos > 15 || isset($seen[$query])) {
                    continue;
                }
                $seen[$query] = true;
                $queries[] = [
                    'query' => ersClip($query, 160),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => round((float) ($row['ctr'] ?? 0), 4),
                    'position' => round($pos, 1),
                ];
            }
        }
    }
    if ($metric === null && $queries === []) {
        return [];
    }
    $range = is_array($cache['range'] ?? null) ? $cache['range'] : [];
    return [
        'ok' => true,
        'source' => 'cache',
        'error' => '',
        'range' => [
            'start' => (string) ($range['start'] ?? ''),
            'end' => (string) ($range['end'] ?? ''),
            'days' => 28,
        ],
        'url' => $exact,
        'clicks' => (int) ($metric['clicks'] ?? 0),
        'impressions' => (int) ($metric['impressions'] ?? 0),
        'ctr' => round((float) ($metric['ctr'] ?? 0), 4),
        'position' => round((float) ($metric['position'] ?? 0), 1),
        'queries_4_15' => array_slice($queries, 0, 25),
    ];
}

/** @return array<string, mixed> */
function ersGrowthGa4(string $dataDir, string $host, string $url): array
{
    $path = dossierNormPath($url);
    $base = [
        'ok' => false,
        'error' => '',
        'range' => ['start' => '', 'end' => ''],
        'property' => '',
        'page' => [
            'path' => $path,
            'found' => false,
            'sessions_mobile' => 0,
            'sessions_desktop' => 0,
            'sessions_tablet' => 0,
            'engagement_rate' => null,
            'bounce_rate' => null,
            'key_events' => 0,
        ],
        'contacts' => [
            'scope' => 'sitio',
            'whatsapp' => 0,
            'llamada' => 0,
            'formulario' => 0,
            'email' => 0,
        ],
    ];
    try {
        $sa = gscLoadServiceAccount($dataDir, $dataDir . '/gsc-service-account.json');
        if ($sa === []) {
            throw new RuntimeException('Falta la cuenta de servicio para Analytics.');
        }
        $gsc = gscReadJson(gscHostCacheFile($dataDir, $host), []);
        if (($gsc['host'] ?? '') !== $host) {
            $gsc = [];
        }
        $windows = dossierWindows($gsc);
        $prefs = dossierHostPrefs($dataDir, $host);
        $resolved = dossierResolveProperty($dataDir, $sa, $host, (string) ($prefs['ga4Property'] ?? ''));
        $property = (string) ($resolved['id'] ?? '');
        if ($property === '') {
            throw new RuntimeException((string) ($resolved['error'] ?: 'Sin propiedad de Analytics para este sitio.'));
        }
        $ga = dossierFetchGa($dataDir, $sa, $host, $property, $windows, false);
        $base['ok'] = !empty($ga['ok']);
        $base['property'] = $property;
        $base['range'] = ['start' => $windows['start'], 'end' => $windows['end']];
        $base['error'] = (string) ($ga['error'] ?? '');
        $devices = is_array($ga['landingDevices'][$path] ?? null) ? $ga['landingDevices'][$path] : [];
        $pageEvents = 0;
        foreach (['mobile' => 'sessions_mobile', 'desktop' => 'sessions_desktop', 'tablet' => 'sessions_tablet'] as $name => $field) {
            $hit = is_array($devices[$name] ?? null) ? $devices[$name] : [];
            $base['page'][$field] = (int) ($hit['sessions'] ?? 0);
            $pageEvents += (int) ($hit['keyEvents'] ?? 0);
        }
        $found = $devices !== [];
        foreach ($ga['landings'] ?? [] as $landing) {
            if (!is_array($landing) || ($landing['path'] ?? '') !== $path) {
                continue;
            }
            $found = true;
            $rate = isset($landing['engagementRate']) ? (float) $landing['engagementRate'] : null;
            $base['page']['engagement_rate'] = $rate !== null ? round($rate, 4) : null;
            $base['page']['bounce_rate'] = $rate !== null ? round(max(0, 1 - $rate), 4) : null;
            if ($pageEvents === 0) {
                $pageEvents = (int) ($landing['keyEvents'] ?? $landing['conversions'] ?? 0);
            }
            break;
        }
        $base['page']['found'] = $found;
        $base['page']['key_events'] = $pageEvents;
        $contacts = is_array($ga['contactBreakdown'] ?? null) ? $ga['contactBreakdown'] : [];
        foreach (['whatsapp', 'llamada', 'formulario', 'email'] as $kind) {
            $base['contacts'][$kind] = (int) ($contacts[$kind] ?? 0);
        }
        return $base;
    } catch (Throwable $e) {
        $base['error'] = ersClip($e->getMessage(), 300);
        return $base;
    }
}

/** @param array<string, mixed> $serp @param array<int, array<string, mixed>> $pages @return array<string, mixed> */
function ersGrowthComparison(string $url, array $serp, array $pages): array
{
    $byUrl = [];
    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $byUrl[ersGrowthNormUrl((string) ($page['url'] ?? ''))] = $page;
    }
    $rows = [];
    $push = static function (string $role, ?int $rank, string $pageUrl, ?array $serpRow) use (&$rows, $byUrl): void {
        $page = $byUrl[ersGrowthNormUrl($pageUrl)] ?? null;
        $h1 = '';
        if (is_array($page) && is_array($page['h1'] ?? null) && isset($page['h1'][0])) {
            $h1 = (string) $page['h1'][0];
        }
        $rows[] = [
            'role' => $role,
            'rank' => $rank,
            'url' => $pageUrl,
            'onpage_score' => is_array($page) ? ($page['onpage_score'] ?? null) : null,
            'title' => is_array($page) && ($page['title'] ?? '') !== ''
                ? $page['title']
                : (string) ($serpRow['title'] ?? ''),
            'h1' => $h1,
            'words' => is_array($page) ? (int) ($page['plain_text_word_count'] ?? 0) : null,
            'schema' => is_array($page) && is_array($page['schema'] ?? null) ? $page['schema'] : [],
            'errors' => is_array($page) && is_array($page['errors'] ?? null)
                ? $page['errors']
                : ['Sin auditoría On-Page'],
            'page' => $page,
        ];
    };
    $push('ours', isset($serp['position']) ? (is_int($serp['position']) ? $serp['position'] : null) : null, $url, is_array($serp['our'] ?? null) ? $serp['our'] : null);
    foreach ($serp['competitors'] ?? [] as $rival) {
        if (!is_array($rival)) {
            continue;
        }
        $rivalUrl = trim((string) ($rival['url'] ?? ''));
        if ($rivalUrl === '' || ersGrowthSameUrl($rivalUrl, $url)) {
            continue;
        }
        $push('rival', (int) ($rival['rank'] ?? 0), $rivalUrl, $rival);
    }
    $scores = [];
    foreach ($rows as $row) {
        if ($row['role'] === 'rival' && is_numeric($row['onpage_score'])) {
            $scores[] = (float) $row['onpage_score'];
        }
    }
    $own = null;
    foreach ($rows as $row) {
        if ($row['role'] === 'ours') {
            $own = $row['onpage_score'];
            break;
        }
    }
    return [
        'rows' => $rows,
        'own_score' => $own,
        'top3_avg_score' => $scores !== [] ? round(array_sum($scores) / count($scores), 1) : null,
        'pages' => $pages,
    ];
}

/** @param array<string, mixed> $raw @param array<int, array<string, mixed>> $previous @return array<int, array<string, mixed>> */
function ersGrowthTasks(array $raw, array $previous): array
{
    $prev = [];
    foreach ($previous as $task) {
        if (is_array($task) && ($task['id'] ?? '') !== '') {
            $prev[(string) $task['id']] = $task;
        }
    }
    $cats = ['quick_wins_seo', 'alertas_cro', 'brecha_tecnica_vs_top1', 'oportunidades_locales'];
    $out = [];
    foreach ($cats as $cat) {
        $list = $raw[$cat] ?? [];
        if (!is_array($list)) {
            continue;
        }
        foreach (array_slice($list, 0, 4) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $action = trim((string) ($item['accion_exacta'] ?? ''));
            if ($action === '') {
                continue;
            }
            $priority = strtolower(trim((string) ($item['prioridad'] ?? 'media')));
            if (!in_array($priority, ['alta', 'media', 'baja'], true)) {
                $priority = 'media';
            }
            $impact = strtolower(trim((string) ($item['impacto'] ?? 'trafico')));
            if (!in_array($impact, ['trafico', 'ctr', 'leads'], true)) {
                $impact = 'trafico';
            }
            $id = substr(hash('sha256', $cat . '|' . ersGrowthFold(preg_replace('/\s+/', ' ', $action) ?? $action)), 0, 16);
            $old = $prev[$id] ?? null;
            $state = 'pendiente';
            $appliedAt = 0;
            $appliedOn = '';
            if (is_array($old) && ($old['estado'] ?? '') === 'aplicado') {
                $state = 'aplicado';
                $appliedAt = (int) ($old['appliedAt'] ?? 0);
                $appliedOn = (string) ($old['appliedOn'] ?? '');
            }
            $out[] = [
                'id' => $id,
                'categoria' => $cat,
                'prioridad' => $priority,
                'impacto' => $impact,
                'accion_exacta' => ersClip($action, 1200),
                'estado' => $state,
                'appliedAt' => $appliedAt,
                'appliedOn' => $appliedOn,
            ];
        }
    }
    return $out;
}

/** @param array<string, mixed> $bundle */
function ersGrowthGemini(array $bundle, array $previous): array
{
    $cfg = ersLoadGeminiConfig();
    if (($cfg['apiKey'] ?? '') === '') {
        throw new RuntimeException('Falta configurar Gemini en ers/data/gemini.json.');
    }
    $system = <<<TXT
Sos el estratega de adquisición local de Mono Studio en Chile. Tu único trabajo es conseguir clientes de la ciudad indicada y dejar la URL en el puesto 1 de Google (orgánico y Local Pack) y citada en respuestas de IAs.

REGLAS
- Usá exclusivamente el JSON del usuario. No inventes métricas, queries, Schema ni textos que no se apoyen en esos datos.
- ctr de Search Console es un ratio: 0.05 = 5%.
- queries_4_15 son las consultas de ESA url entre el puesto 4 y el 15. quick_wins_seo solo usa las que están entre 4 y 10.
- ga4_data.contacts es del sitio entero, 28 días. Los leads de la URL son page.key_events. Si page.found es false, no diagnostiques CRO como si la URL tuviera cero tráfico: falta el dato.
- Si no hay evidencia para una categoría, devolvé su array vacío.
- Máximo 4 tareas por categoría.
- Cada accion_exacta es el cambio listo para pegar: title, meta description, H1-H3, bloque, Schema o sección. Nada genérico.

CATEGORÍAS
- quick_wins_seo: Title, Meta Description y H1-H3 exactos para keywords en posición 4-10.
- alertas_cro: WhatsApp, propuesta de valor en móvil o velocidad, solo si hay sesiones y pocos leads en la URL.
- brecha_tecnica_vs_top1: diferencias reales contra el orgánico #1 (leader). Si leader.ours es true, compará contra competitors[0] y decilo. Estructura H1-H3, entidades de la ciudad/comuna y Schema LocalBusiness, Service o FAQPage.
- oportunidades_locales: secciones o landings satélite a partir de queries_4_15 y people_also_ask. Incluí la URL sugerida y el H1.

prioridad: alta, media o baja. impacto: trafico, ctr o leads.
TXT;
    $taskSchema = [
        'type' => 'OBJECT',
        'properties' => [
            'prioridad' => ['type' => 'STRING'],
            'impacto' => ['type' => 'STRING'],
            'accion_exacta' => ['type' => 'STRING'],
        ],
        'required' => ['prioridad', 'impacto', 'accion_exacta'],
    ];
    $list = ['type' => 'ARRAY', 'items' => $taskSchema];
    $payload = json_encode($bundle, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('No se pudo armar el contexto para Gemini.');
    }
    $body = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => ersClip($payload, 60000)]],
        ]],
        'generationConfig' => array_merge([
            'temperature' => 0.2,
            'maxOutputTokens' => 8192,
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'OBJECT',
                'properties' => [
                    'quick_wins_seo' => $list,
                    'alertas_cro' => $list,
                    'brecha_tecnica_vs_top1' => $list,
                    'oportunidades_locales' => $list,
                ],
                'required' => ['quick_wins_seo', 'alertas_cro', 'brecha_tecnica_vs_top1', 'oportunidades_locales'],
            ],
        ], ersGeminiThinkingConfig($cfg)),
    ];
    $response = ersGeminiHttp(ersGeminiUrl($cfg), $body, 90);
    $raw = '';
    foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
        if (isset($part['text']) && is_string($part['text']) && empty($part['thought'])) {
            $raw .= $part['text'];
        }
    }
    $raw = trim($raw);
    $raw = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $raw) ?? $raw;
    $decoded = json_decode(trim($raw), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Gemini no devolvió un plan JSON usable.');
    }
    return ersGrowthTasks($decoded, $previous);
}

/** @return array<string, mixed> */
function ersGrowthPublicSerp(array $serp): array
{
    foreach (['our', 'leader'] as $key) {
        if (!is_array($serp[$key] ?? null)) {
            continue;
        }
        unset($serp[$key]['description']);
    }
    return $serp;
}

function ersGrowthRun(DataForSeoService $service, string $dataDir, array $input): array
{
    $projectId = strtolower(trim((string) ($input['proyecto_id'] ?? '')));
    $client = ersGrowthClient($projectId);
    if ($client === null) {
        ersGrowthFail(404, 'Proyecto no encontrado.');
    }
    $url = trim((string) ($input['url_objetivo'] ?? ''));
    $host = ersGrowthAssertUrl($client, $url);
    $keyword = trim(preg_replace('/\s+/', ' ', (string) ($input['keyword'] ?? '')) ?? '');
    $len = function_exists('mb_strlen') ? mb_strlen($keyword) : strlen($keyword);
    if ($len < 2 || $len > 120) {
        throw new InvalidArgumentException('La keyword tiene que tener entre 2 y 120 caracteres.');
    }
    $locationCode = (int) ($input['location_code'] ?? 0);
    $force = !empty($input['force']);
    $key = hash('sha256', $projectId . "\n" . ersGrowthNormUrl($url) . "\n" . ersGrowthFold($keyword) . "\n" . $locationCode);
    $existing = ersGrowthReadPlan($dataDir, $projectId, $host);
    if (
        !$force
        && is_array($existing)
        && ($existing['key'] ?? '') === $key
        && (int) ($existing['expiresAt'] ?? 0) > time()
        && ($existing['geminiError'] ?? '') === ''
    ) {
        $existing['fromCache'] = true;
        return [
            'ok' => true,
            'fromCache' => true,
            'chargedUsd' => 0,
            'costs' => $service->costs($projectId),
            'plan' => $existing,
        ];
    }

    set_time_limit(180);
    $service->beginRun();
    $closed = false;
    $close = static function () use ($service, $projectId, &$closed): array {
        if ($closed) {
            return ['chargedUsd' => 0.0, 'live' => false];
        }
        $closed = true;
        return $service->finishRun($projectId);
    };
    try {
        $gsc = ersGrowthGsc($dataDir, $host, $url);
        $ga4 = ersGrowthGa4($dataDir, $host, $url);
        $place = $service->requireLocation($projectId, $locationCode);
        $serp = $service->serp($projectId, $host, $keyword, $locationCode, $force);
        $auditUrls = [$url];
        foreach ($serp['competitors'] ?? [] as $rival) {
            if (!is_array($rival)) {
                continue;
            }
            $rivalUrl = trim((string) ($rival['url'] ?? ''));
            if ($rivalUrl === '' || ersGrowthSameUrl($rivalUrl, $url)) {
                continue;
            }
            $auditUrls[] = $rivalUrl;
            if (count($auditUrls) >= 4) {
                break;
            }
        }
        $onpage = $service->onpage($projectId, $auditUrls, $force);
        $comparison = ersGrowthComparison($url, $serp, is_array($onpage['pages'] ?? null) ? $onpage['pages'] : []);
        $bundle = [
            'proyecto' => [
                'id' => $projectId,
                'nombre' => (string) ($client['name'] ?? ''),
                'host' => $host,
            ],
            'url_objetivo' => $url,
            'keyword' => $keyword,
            'ubicacion' => [
                'location_code' => $place['location_code'],
                'nombre' => $place['location_name'],
                'tipo' => $place['location_type'],
            ],
            'gsc_data' => $gsc,
            'ga4_data' => $ga4,
            'serp_local_data' => [
                'position' => $serp['position'],
                'local_pack' => $serp['local_pack'],
                'ai_overview' => $serp['ai_overview'],
                'people_also_ask' => $serp['people_also_ask'],
                'our' => $serp['our'],
                'leader' => $serp['leader'],
                'competitors' => $serp['competitors'],
            ],
            'onpage_comparison' => [
                'own_score' => $comparison['own_score'],
                'top3_avg_score' => $comparison['top3_avg_score'],
                'pages' => $comparison['pages'],
            ],
        ];
        $previous = is_array($existing['tasks'] ?? null) && ($existing['key'] ?? '') === $key
            ? $existing['tasks']
            : [];
        $geminiError = '';
        try {
            $tasks = ersGrowthGemini($bundle, $previous);
        } catch (Throwable $e) {
            $tasks = $previous;
            $geminiError = ersClip($e->getMessage(), 300);
        }
        $warnings = [];
        if (empty($gsc['ok'])) {
            $warnings[] = 'Search Console: ' . (string) $gsc['error'];
        }
        if (empty($ga4['ok'])) {
            $warnings[] = 'Analytics: ' . (string) $ga4['error'];
        }
        if ($geminiError !== '') {
            $warnings[] = $geminiError;
        }
        $run = $close();
        $now = time();
        $plan = [
            'proyecto_id' => $projectId,
            'host' => $host,
            'key' => $key,
            'url' => $url,
            'keyword' => $keyword,
            'location_code' => $place['location_code'],
            'location_name' => $place['location_name'],
            'location_label' => $place['label'],
            'fetchedAt' => $now,
            'expiresAt' => $now + ERS_GROWTH_TTL,
            'fromCache' => false,
            'chargedUsd' => $run['chargedUsd'],
            'geminiError' => $geminiError,
            'warnings' => $warnings,
            'gsc_data' => $gsc,
            'ga4_data' => $ga4,
            'serp_local_data' => ersGrowthPublicSerp([
                'position' => $serp['position'],
                'local_pack' => $serp['local_pack'],
                'ai_overview' => $serp['ai_overview'],
                'people_also_ask' => $serp['people_also_ask'],
                'our' => $serp['our'],
                'leader' => $serp['leader'],
                'competitors' => $serp['competitors'],
                'fromCache' => !empty($serp['fromCache']),
            ]),
            'onpage_comparison' => [
                'own_score' => $comparison['own_score'],
                'top3_avg_score' => $comparison['top3_avg_score'],
                'rows' => array_map(static function (array $row): array {
                    unset($row['page']);
                    return $row;
                }, $comparison['rows']),
                'fromCache' => !empty($onpage['fromCache']),
            ],
            'tasks' => $tasks,
        ];
        ersGrowthWritePlan($dataDir, $plan);
        return [
            'ok' => true,
            'fromCache' => false,
            'chargedUsd' => $run['chargedUsd'],
            'costs' => $service->costs($projectId),
            'plan' => $plan,
        ];
    } catch (Throwable $e) {
        $close();
        throw $e;
    }
}

function ersGrowthTask(string $dataDir, array $input): array
{
    $projectId = strtolower(trim((string) ($input['proyecto_id'] ?? '')));
    $client = ersGrowthClient($projectId);
    if ($client === null) {
        ersGrowthFail(404, 'Proyecto no encontrado.');
    }
    $host = gscSanitizeHost((string) ($input['host'] ?? ''));
    $allowed = false;
    foreach (ersClientHosts($client) as $candidate) {
        if (gscSanitizeHost($candidate) === $host) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        throw new InvalidArgumentException('El sitio no pertenece a este proyecto.');
    }
    $taskId = strtolower(trim((string) ($input['task_id'] ?? '')));
    $state = strtolower(trim((string) ($input['estado'] ?? '')));
    if (!preg_match('/^[a-f0-9]{16}$/', $taskId) || !in_array($state, ['pendiente', 'aplicado'], true)) {
        throw new InvalidArgumentException('No se pudo actualizar la tarea.');
    }
    $plan = ersGrowthReadPlan($dataDir, $projectId, $host);
    if ($plan === null || !is_array($plan['tasks'] ?? null)) {
        ersGrowthFail(404, 'No hay un diagnóstico guardado para este sitio.');
    }
    $found = false;
    $now = time();
    $day = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('America/Santiago'))->format('Y-m-d');
    foreach ($plan['tasks'] as $i => $task) {
        if (!is_array($task) || ($task['id'] ?? '') !== $taskId) {
            continue;
        }
        $found = true;
        $plan['tasks'][$i]['estado'] = $state;
        if ($state === 'aplicado') {
            $plan['tasks'][$i]['appliedAt'] = (int) ($task['appliedAt'] ?? 0) > 0 ? (int) $task['appliedAt'] : $now;
            $plan['tasks'][$i]['appliedOn'] = (string) ($task['appliedOn'] ?? '') !== '' ? (string) $task['appliedOn'] : $day;
        } else {
            $plan['tasks'][$i]['appliedAt'] = 0;
            $plan['tasks'][$i]['appliedOn'] = '';
        }
    }
    if (!$found) {
        ersGrowthFail(404, 'La tarea no está en el diagnóstico de este sitio.');
    }
    ersGrowthWritePlan($dataDir, $plan);
    return ['ok' => true, 'plan' => $plan];
}

$dataDir = ersDataDir();
$service = new DataForSeoService($dataDir);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method === 'POST') {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    $input = is_array($decoded) ? $decoded : [];
}
$action = (string) ($input['action'] ?? $_GET['action'] ?? '');

try {
    if ($method === 'GET' && $action === 'status') {
        $projectId = strtolower(trim((string) ($_GET['proyecto_id'] ?? '')));
        $client = ersGrowthClient($projectId);
        if ($client === null) {
            ersGrowthFail(404, 'Proyecto no encontrado.');
        }
        $host = gscSanitizeHost((string) ($_GET['host'] ?? ''));
        $allowed = false;
        foreach (ersClientHosts($client) as $candidate) {
            if (gscSanitizeHost($candidate) === $host) {
                $allowed = true;
                break;
            }
        }
        echo json_encode([
            'configured' => $service->isConfigured(),
            'costs' => $service->costs($projectId),
            'plan' => $allowed ? ersGrowthReadPlan($dataDir, $projectId, $host) : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET' && $action === 'locations') {
        $projectId = strtolower(trim((string) ($_GET['proyecto_id'] ?? '')));
        if (ersGrowthClient($projectId) === null) {
            ersGrowthFail(404, 'Proyecto no encontrado.');
        }
        $pack = $service->searchLocations($projectId, (string) ($_GET['q'] ?? ''));
        echo json_encode([
            'ready' => $pack['ready'],
            'matches' => $pack['matches'],
            'costs' => $service->costs($projectId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && $action === 'run') {
        echo json_encode(ersGrowthRun($service, $dataDir, $input), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && $action === 'task') {
        echo json_encode(ersGrowthTask($dataDir, $input), JSON_UNESCAPED_UNICODE);
        exit;
    }

    ersGrowthFail(405, 'Acción no disponible.');
} catch (InvalidArgumentException $e) {
    ersGrowthFail(400, $e->getMessage());
} catch (Throwable $e) {
    ersGrowthFail(502, $e->getMessage());
}
