<?php
/**
 * DataForSEO v3. Credenciales solo en servidor.
 * Las banderas de SERP y On-Page quedan apagadas: cada una sube el precio
 * (rectángulos, AI Overview async, clics de PAA, JavaScript, recursos).
 */

declare(strict_types=1);

if (!defined('ERS_LIBRARY')) {
    define('ERS_LIBRARY', true);
}

require_once __DIR__ . '/../api/dossier.php';

final class DataForSeoService
{
    public const BASE = 'https://api.dataforseo.com/v3';
    public const CACHE_TTL = 604800;

    private string $dataDir;
    private ?array $config = null;
    private bool $counting = false;
    private float $runUsd = 0.0;
    private bool $runLive = false;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/\\');
    }

    public function isConfigured(): bool
    {
        $cfg = $this->config();
        return $cfg['login'] !== '' && $cfg['password'] !== '';
    }

    public function beginRun(): void
    {
        $this->counting = true;
        $this->runUsd = 0.0;
        $this->runLive = false;
    }

    /** Cierra la corrida y, si hubo llamada viva, deja el costo como última consulta. */
    public function finishRun(string $projectId): array
    {
        $charged = round($this->runUsd, 6);
        $live = $this->runLive;
        $this->counting = false;
        $this->runUsd = 0.0;
        $this->runLive = false;
        if ($live) {
            $this->noteRun($projectId, $charged);
        }
        return ['chargedUsd' => $charged, 'live' => $live];
    }

    public function costs(string $projectId): array
    {
        $projectId = $this->assertProject($projectId);
        $data = gscReadJson($this->ledgerPath($projectId), []);
        return [
            'totalUsd' => round((float) ($data['totalUsd'] ?? 0), 6),
            'lastRunUsd' => round((float) ($data['lastRunUsd'] ?? 0), 6),
            'lastRunAt' => (int) ($data['lastRunAt'] ?? 0),
        ];
    }

    /**
     * Buscador de ciudades/comunas. Solo pega a la API si no existe el caché permanente.
     *
     * @return array{ready: bool, matches: array<int, array<string, mixed>>, chargedUsd: float}
     */
    public function searchLocations(string $projectId, string $query): array
    {
        $projectId = $this->assertProject($projectId);
        $needle = trim($query);
        if (function_exists('mb_strlen') ? mb_strlen($needle) < 2 : strlen($needle) < 2) {
            return ['ready' => $this->readLocations() !== null, 'matches' => [], 'chargedUsd' => 0.0];
        }
        $pack = $this->ensureLocations($projectId, true);
        return [
            'ready' => true,
            'matches' => $this->filterLocations($pack['list'], $needle),
            'chargedUsd' => $pack['chargedUsd'],
        ];
    }

    /** @return array{location_code: int, location_name: string, location_type: string, label: string, chargedUsd: float} */
    public function requireLocation(string $projectId, int $code): array
    {
        $projectId = $this->assertProject($projectId);
        if ($code <= 0) {
            throw new InvalidArgumentException('Elegí una ciudad o comuna de Chile.');
        }
        $pack = $this->ensureLocations($projectId, true);
        foreach ($pack['list'] as $row) {
            if ((int) $row['location_code'] !== $code) {
                continue;
            }
            if (strcasecmp((string) $row['location_type'], 'Country') === 0) {
                throw new InvalidArgumentException('Elegí una ciudad o comuna, no el país entero.');
            }
            $label = $this->locationLabel($row, $this->parentMap($pack['list']));
            return [
                'location_code' => $code,
                'location_name' => (string) $row['location_name'],
                'location_type' => (string) $row['location_type'],
                'label' => $label,
                'chargedUsd' => $pack['chargedUsd'],
            ];
        }
        throw new InvalidArgumentException('Esa ciudad no está en el listado de Chile.');
    }

    /**
     * @return array<string, mixed>
     */
    public function serp(string $projectId, string $domain, string $keyword, int $locationCode, bool $force): array
    {
        $projectId = $this->assertProject($projectId);
        $domain = $this->hostOf($domain);
        $keyword = trim($keyword);
        if ($domain === '' || $keyword === '' || $locationCode <= 0) {
            throw new InvalidArgumentException('Faltan dominio, keyword o ciudad para el SERP.');
        }
        $file = $this->projectDir($projectId) . '/serp-' . $this->hash($projectId, $domain, $this->fold($keyword), (string) $locationCode) . '.json';
        return $this->withLock($this->projectDir($projectId) . '/run.lock', function () use ($file, $force, $projectId, $domain, $keyword, $locationCode): array {
            if (!$force) {
                $cached = $this->freshCache($file);
                if ($cached !== null) {
                    return ['fromCache' => true, 'chargedUsd' => 0.0, 'fetchedAt' => (int) $cached['fetchedAt']] + $cached['result'];
                }
            }
            $live = $this->call('POST', '/serp/google/organic/live/advanced', [[
                'keyword' => $keyword,
                'location_code' => $locationCode,
                'language_code' => 'es',
                'device' => 'mobile',
                'depth' => 10,
                'calculate_rectangles' => false,
                'load_async_ai_overview' => false,
                'people_also_ask_click_depth' => 0,
            ]], $projectId, 70);
            $this->assertTop($live['data'], $live['http']);
            $task = $this->firstTask($live['data']);
            if (!$this->taskOk($task)) {
                throw new RuntimeException('DataForSEO SERP: ' . $this->taskMessage($task));
            }
            $result = is_array($task['result'][0] ?? null) ? $task['result'][0] : [];
            $presented = self::presentSerp($result, $domain);
            $this->writeCache($file, $live['cost'], $presented);
            return ['fromCache' => false, 'chargedUsd' => $live['cost'], 'fetchedAt' => time()] + $presented;
        });
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, mixed>
     */
    public function onpage(string $projectId, array $urls, bool $force): array
    {
        $projectId = $this->assertProject($projectId);
        $clean = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if (!preg_match('#^https?://#i', $url)) {
                continue;
            }
            $key = $this->fold($this->normUrl($url));
            if ($key === '' || isset($clean[$key])) {
                continue;
            }
            $clean[$key] = $url;
            if (count($clean) >= 4) {
                break;
            }
        }
        if ($clean === []) {
            throw new InvalidArgumentException('No hay URLs para auditar.');
        }
        $file = $this->projectDir($projectId) . '/onpage-' . $this->hash($projectId, ...array_keys($clean)) . '.json';
        return $this->withLock($this->projectDir($projectId) . '/run.lock', function () use ($file, $force, $projectId, $clean): array {
            if (!$force) {
                $cached = $this->freshCache($file);
                if ($cached !== null) {
                    return ['fromCache' => true, 'chargedUsd' => 0.0, 'fetchedAt' => (int) $cached['fetchedAt']] + $cached['result'];
                }
            }
            $tasks = [];
            foreach ($clean as $url) {
                $tasks[] = [
                    'url' => $url,
                    'enable_javascript' => false,
                    'load_resources' => false,
                    'enable_browser_rendering' => false,
                ];
            }
            $live = $this->call('POST', '/on_page/instant_pages', $tasks, $projectId, 70);
            $this->assertTop($live['data'], $live['http']);
            $pages = [];
            $apiTasks = is_array($live['data']['tasks'] ?? null) ? $live['data']['tasks'] : [];
            $i = 0;
            foreach ($clean as $url) {
                $task = is_array($apiTasks[$i] ?? null) ? $apiTasks[$i] : [];
                $pages[] = self::presentOnpage($task, $url);
                $i++;
            }
            $presented = ['pages' => $pages];
            $this->writeCache($file, $live['cost'], $presented);
            return ['fromCache' => false, 'chargedUsd' => $live['cost'], 'fetchedAt' => time()] + $presented;
        });
    }

    /** @param array<string, mixed> $result */
    public static function presentSerp(array $result, string $domain): array
    {
        $domain = preg_replace('#^www\.#', '', strtolower($domain)) ?? strtolower($domain);
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $organic = [];
        $local = false;
        $ai = ['present' => false, 'excerpt' => ''];
        $paa = [];

        $itemTypes = is_array($result['item_types'] ?? null) ? $result['item_types'] : [];
        foreach ($itemTypes as $type) {
            $type = (string) $type;
            if (in_array($type, ['local_pack', 'maps'], true)) {
                $local = true;
            }
            if ($type === 'ai_overview') {
                $ai['present'] = true;
            }
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            if (in_array($type, ['local_pack', 'maps', 'local_services'], true)) {
                $local = true;
            }
            if ($type === 'ai_overview') {
                $ai['present'] = true;
                $ai['excerpt'] = self::aiExcerpt($item);
            }
            if ($type === 'people_also_ask') {
                foreach (self::paaQuestions($item) as $question) {
                    $paa[] = $question;
                }
            }
            if ($type !== 'organic') {
                continue;
            }
            $host = self::hostOfValue((string) ($item['domain'] ?? ''));
            if ($host === '') {
                $host = self::hostOfValue((string) ($item['url'] ?? ''));
            }
            if ($host === '' || self::isGoogleHost($host)) {
                continue;
            }
            $rank = (int) ($item['rank_group'] ?? 0);
            if ($rank <= 0) {
                $rank = (int) ($item['rank_absolute'] ?? 0);
            }
            if ($rank <= 0) {
                continue;
            }
            $organic[] = [
                'rank' => $rank,
                'rank_absolute' => (int) ($item['rank_absolute'] ?? $rank),
                'url' => ersClip(trim((string) ($item['url'] ?? '')), 500),
                'title' => ersClip(trim((string) ($item['title'] ?? '')), 180),
                'description' => ersClip(trim(preg_replace('/\s+/', ' ', (string) ($item['description'] ?? '')) ?? ''), 320),
                'domain' => $host,
                'ours' => $host === $domain,
            ];
        }

        usort($organic, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
        $our = null;
        $rivals = [];
        foreach ($organic as $row) {
            if ($row['ours'] && $our === null) {
                $our = $row;
                continue;
            }
            if (!$row['ours'] && count($rivals) < 3) {
                $rivals[] = [
                    'rank' => $row['rank'],
                    'url' => $row['url'],
                    'title' => $row['title'],
                    'description' => $row['description'],
                ];
            }
        }
        $position = null;
        if ($our !== null && $our['rank'] >= 1 && $our['rank'] <= 10) {
            $position = $our['rank'];
        }
        $paa = array_slice(array_values(array_unique($paa)), 0, 8);

        return [
            'position' => $position,
            'local_pack' => $local,
            'ai_overview' => $ai,
            'people_also_ask' => $paa,
            'our' => $our,
            'leader' => $organic[0] ?? null,
            'competitors' => $rivals,
            'organic_top' => array_slice($organic, 0, 4),
        ];
    }

    /** @param array<string, mixed> $task */
    public static function presentOnpage(array $task, string $requestedUrl): array
    {
        $base = [
            'url' => $requestedUrl,
            'error' => '',
            'onpage_score' => null,
            'title' => '',
            'description' => '',
            'h1' => [],
            'h2' => [],
            'h3' => [],
            'plain_text_word_count' => 0,
            'schema' => [],
            'internal_links' => 0,
            'external_links' => 0,
            'images_count' => 0,
            'images_without_alt' => ['count' => null, 'present' => false],
            'load_time_ms' => null,
            'indexable' => false,
            'status_code' => 0,
            'errors' => [],
        ];
        if ($task === [] || (int) ($task['status_code'] ?? 0) !== 20000) {
            $base['error'] = $task === [] ? 'Sin respuesta de On-Page.' : self::taskMessageStatic($task);
            $base['errors'] = [$base['error']];
            return $base;
        }
        $result = is_array($task['result'][0] ?? null) ? $task['result'][0] : [];
        $item = is_array($result['items'][0] ?? null) ? $result['items'][0] : $result;
        if (!is_array($item)) {
            $base['error'] = 'On-Page no devolvió la página.';
            $base['errors'] = [$base['error']];
            return $base;
        }
        $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
        $checks = is_array($item['checks'] ?? null) ? $item['checks'] : [];
        $timing = is_array($item['page_timing'] ?? null) ? $item['page_timing'] : [];
        $content = is_array($meta['content'] ?? null) ? $meta['content'] : [];
        $heads = self::headings(is_array($meta['htags'] ?? null) ? $meta['htags'] : []);
        $images = self::imagesWithoutAlt($meta, $checks);
        $status = (int) ($item['status_code'] ?? 0);
        $robots = strtolower((string) ($meta['robots'] ?? ''));
        $noindex = !empty($checks['no_index']) || !empty($checks['is_noindex']) || str_contains($robots, 'noindex');
        $indexable = $status >= 200 && $status < 300
            && empty($checks['is_4xx_code'])
            && empty($checks['is_5xx_code'])
            && empty($checks['is_broken'])
            && !$noindex;
        $load = $timing['duration_time'] ?? null;
        $schema = array_slice(self::schemaTypes($item), 0, 12);
        $page = [
            'url' => trim((string) ($item['url'] ?? $requestedUrl)) ?: $requestedUrl,
            'error' => '',
            'onpage_score' => isset($item['onpage_score']) && is_numeric($item['onpage_score'])
                ? round((float) $item['onpage_score'], 1)
                : null,
            'title' => ersClip(trim((string) ($meta['title'] ?? '')), 180),
            'description' => ersClip(trim((string) ($meta['description'] ?? '')), 320),
            'h1' => $heads['h1'],
            'h2' => $heads['h2'],
            'h3' => $heads['h3'],
            'plain_text_word_count' => (int) ($content['plain_text_word_count'] ?? 0),
            'schema' => $schema,
            'internal_links' => (int) ($meta['internal_links_count'] ?? 0),
            'external_links' => (int) ($meta['external_links_count'] ?? 0),
            'images_count' => (int) ($meta['images_count'] ?? 0),
            'images_without_alt' => $images,
            'load_time_ms' => is_numeric($load) ? (int) round((float) $load) : null,
            'indexable' => $indexable,
            'status_code' => $status,
            'errors' => self::onpageErrors($checks, $images, $indexable, $status),
        ];
        return $page;
    }

    /** @param array<string, mixed> $item */
    private static function aiExcerpt(array $item): string
    {
        $text = trim((string) ($item['markdown'] ?? $item['text'] ?? ''));
        if ($text === '' && is_array($item['items'] ?? null)) {
            $bits = [];
            foreach ($item['items'] as $sub) {
                if (!is_array($sub)) {
                    continue;
                }
                $bit = trim((string) ($sub['markdown'] ?? $sub['text'] ?? $sub['title'] ?? ''));
                if ($bit !== '') {
                    $bits[] = $bit;
                }
            }
            $text = implode(' ', $bits);
        }
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return ersClip(trim($text), 400);
    }

    /** @param array<string, mixed> $item @return array<int, string> */
    private static function paaQuestions(array $item): array
    {
        $out = [];
        $subs = is_array($item['items'] ?? null) ? $item['items'] : [];
        foreach ($subs as $sub) {
            if (!is_array($sub)) {
                continue;
            }
            $q = trim((string) ($sub['title'] ?? $sub['question'] ?? ''));
            if ($q !== '') {
                $out[] = ersClip($q, 180);
            }
        }
        if ($out === []) {
            $q = trim((string) ($item['title'] ?? ''));
            if ($q !== '') {
                $out[] = ersClip($q, 180);
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $htags @return array{h1: array<int, string>, h2: array<int, string>, h3: array<int, string>} */
    private static function headings(array $htags): array
    {
        $out = ['h1' => [], 'h2' => [], 'h3' => []];
        $push = static function (string $level, string $text) use (&$out): void {
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
            if ($text === '' || !isset($out[$level]) || count($out[$level]) >= 12) {
                return;
            }
            $out[$level][] = ersClip($text, 160);
        };
        if (array_is_list($htags)) {
            foreach ($htags as $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                $level = strtolower((string) ($tag['level'] ?? $tag['name'] ?? ''));
                $push($level, (string) ($tag['text'] ?? $tag['title'] ?? ''));
            }
            return $out;
        }
        foreach (['h1', 'h2', 'h3'] as $level) {
            $list = $htags[$level] ?? $htags[strtoupper($level)] ?? [];
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $tag) {
                if (is_string($tag)) {
                    $push($level, $tag);
                } elseif (is_array($tag)) {
                    $push($level, (string) ($tag['text'] ?? $tag['title'] ?? ''));
                }
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $node @return array<int, string> */
    private static function schemaTypes(array $node, int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }
        $found = [];
        foreach ($node as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $name = strtolower($key);
            if (in_array($name, ['html', 'page_as_html', 'raw_html', 'plain_text', 'description', 'script'], true)) {
                continue;
            }
            if ($name === '@type' || $name === 'schema_type') {
                foreach (is_array($value) ? $value : [$value] as $type) {
                    if (is_string($type) && trim($type) !== '') {
                        $found[] = ersClip(trim($type), 80);
                    }
                }
            }
            if (is_array($value)) {
                $found = array_merge($found, self::schemaTypes($value, $depth + 1));
            }
        }
        $uniq = [];
        foreach ($found as $type) {
            $uniq[strtolower($type)] = $type;
        }
        return array_values($uniq);
    }

    /** @param array<string, mixed> $meta @param array<string, mixed> $checks @return array{count: ?int, present: bool} */
    private static function imagesWithoutAlt(array $meta, array $checks): array
    {
        $count = null;
        foreach (['images', 'imgs'] as $key) {
            if (!is_array($meta[$key] ?? null)) {
                continue;
            }
            $missing = 0;
            $seen = false;
            foreach ($meta[$key] as $img) {
                if (is_string($img)) {
                    $seen = true;
                    if (trim($img) === '') {
                        $missing++;
                    }
                    continue;
                }
                if (!is_array($img)) {
                    continue;
                }
                $seen = true;
                $alt = trim((string) ($img['alt'] ?? $img['alt_text'] ?? ''));
                if ($alt === '') {
                    $missing++;
                }
            }
            if ($seen) {
                $count = $missing;
            }
        }
        $flag = $count !== null
            ? $count > 0
            : (!empty($checks['no_image_alt']) || !empty($checks['no_image_alt_attribute']));
        return ['count' => $count, 'present' => $flag];
    }

    /**
     * @param array<string, mixed> $checks
     * @param array{count: ?int, present: bool} $images
     * @return array<int, string>
     */
    private static function onpageErrors(array $checks, array $images, bool $indexable, int $status): array
    {
        $map = [
            'no_h1_tag' => 'Sin H1',
            'no_description' => 'Sin meta description',
            'no_title' => 'Sin title',
            'title_too_long' => 'Title largo',
            'title_too_short' => 'Title corto',
            'is_4xx_code' => 'HTTP 4xx',
            'is_5xx_code' => 'HTTP 5xx',
            'is_broken' => 'Página rota',
            'low_content_rate' => 'Poco texto',
            'high_loading_time' => 'Carga lenta',
            'no_image_alt' => 'Imágenes sin alt',
            'is_redirect' => 'Redirección',
            'has_render_blocking_resources' => 'Recursos bloqueantes',
        ];
        $out = [];
        foreach ($map as $key => $label) {
            if (!empty($checks[$key])) {
                $out[] = $label;
            }
        }
        if (!$indexable) {
            $out[] = $status >= 400 ? 'No indexable (HTTP ' . $status . ')' : 'No indexable';
        }
        if ($images['present'] && !in_array('Imágenes sin alt', $out, true)) {
            $out[] = $images['count'] !== null ? $images['count'] . ' imágenes sin alt' : 'Imágenes sin alt';
        }
        return array_slice(array_values(array_unique($out)), 0, 8);
    }

    /** @param array<string, mixed> $data */
    private function assertTop(array $data, int $http): void
    {
        if ($http === 401 || $http === 403) {
            throw new RuntimeException('DataForSEO rechazó las credenciales.');
        }
        if ($http < 200 || $http >= 300) {
            throw new RuntimeException('DataForSEO HTTP ' . $http . ': ' . $this->statusMessage($data));
        }
        $code = (int) ($data['status_code'] ?? 0);
        if ($code !== 20000) {
            throw new RuntimeException('DataForSEO ' . $code . ': ' . $this->statusMessage($data));
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function firstTask(array $data): array
    {
        $task = $data['tasks'][0] ?? null;
        return is_array($task) ? $task : [];
    }

    /** @param array<string, mixed> $task */
    private function taskOk(array $task): bool
    {
        return self::taskOkStatic($task);
    }

    /** @param array<string, mixed> $task */
    private static function taskOkStatic(array $task): bool
    {
        $code = (int) ($task['status_code'] ?? 0);
        if ($code === 20000) {
            return true;
        }
        $msg = strtolower((string) ($task['status_message'] ?? ''));
        return in_array($code, [40102, 40202], true)
            || str_contains($msg, 'no search results')
            || str_contains($msg, 'no results');
    }

    /** @param array<string, mixed> $task */
    private function taskMessage(array $task): string
    {
        return self::taskMessageStatic($task);
    }

    /** @param array<string, mixed> $task */
    private static function taskMessageStatic(array $task): string
    {
        $code = (int) ($task['status_code'] ?? 0);
        $msg = trim((string) ($task['status_message'] ?? 'error'));
        return ersClip(($code > 0 ? $code . ' ' : '') . $msg, 220);
    }

    /** @param array<string, mixed> $data */
    private function statusMessage(array $data): string
    {
        return ersClip(trim((string) ($data['status_message'] ?? 'error')), 220);
    }

    /** @param array<string, mixed>|null $body @return array{http: int, data: array<string, mixed>, cost: float} */
    private function call(string $method, string $path, ?array $body, string $projectId, int $timeout): array
    {
        $cfg = $this->config();
        if ($cfg['login'] === '' || $cfg['password'] === '') {
            throw new RuntimeException('Falta DATAFORSEO_LOGIN o DATAFORSEO_PASSWORD en ers/data/dataforseo.json.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('El servidor no tiene cURL para hablar con DataForSEO.');
        }
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                throw new RuntimeException('No se pudo armar el request a DataForSEO.');
            }
        }
        $headers = [
            'Authorization: Basic ' . base64_encode($cfg['login'] . ':' . $cfg['password']),
            'Accept: application/json',
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('DataForSEO no respondió: ' . ersClip($err !== '' ? $err : 'sin respuesta', 180));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('DataForSEO devolvió una respuesta que no es JSON (HTTP ' . $http . ').');
        }
        $cost = $this->readCost($data);
        $this->logCost($projectId, $path, $cost, $http);
        return ['http' => $http, 'data' => $data, 'cost' => $cost];
    }

    /** @param array<string, mixed> $data */
    private function readCost(array $data): float
    {
        if (isset($data['cost']) && is_numeric($data['cost'])) {
            return round((float) $data['cost'], 6);
        }
        $sum = 0.0;
        foreach ($data['tasks'] ?? [] as $task) {
            if (is_array($task) && isset($task['cost']) && is_numeric($task['cost'])) {
                $sum += (float) $task['cost'];
            }
        }
        return round($sum, 6);
    }

    private function logCost(string $projectId, string $endpoint, float $cost, int $http): void
    {
        $projectId = $this->assertProject($projectId);
        $dir = $this->projectDir($projectId);
        $line = json_encode([
            'at' => time(),
            'endpoint' => $endpoint,
            'costUsd' => $cost,
            'http' => $http,
        ], JSON_UNESCAPED_UNICODE);
        if ($line !== false) {
            file_put_contents($dir . '/cost.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
        }
        $this->updateLedger($projectId, static function (array $data) use ($cost): array {
            $data['totalUsd'] = round((float) ($data['totalUsd'] ?? 0) + $cost, 6);
            return $data;
        });
        if ($this->counting) {
            $this->runUsd = round($this->runUsd + $cost, 6);
            // El listado de Chile es referencia, no la consulta del diagnóstico.
            if (str_contains($endpoint, '/serp/google/organic') || str_contains($endpoint, '/on_page/')) {
                $this->runLive = true;
            }
        }
    }

    private function noteRun(string $projectId, float $usd): void
    {
        $projectId = $this->assertProject($projectId);
        $this->updateLedger($projectId, static function (array $data) use ($usd): array {
            $data['lastRunUsd'] = round($usd, 6);
            $data['lastRunAt'] = time();
            return $data;
        });
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutator */
    private function updateLedger(string $projectId, callable $mutator): void
    {
        $path = $this->ledgerPath($projectId);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }
        $fp = fopen($path, 'c+');
        if ($fp === false) {
            return;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }
            $raw = stream_get_contents($fp);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                $data = ['totalUsd' => 0, 'lastRunUsd' => 0, 'lastRunAt' => 0];
            }
            $data = $mutator($data);
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json === false) {
                return;
            }
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $json);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** @return array{list: array<int, array<string, mixed>>, chargedUsd: float} */
    private function ensureLocations(string $projectId, bool $fetchIfMissing): array
    {
        $cached = $this->readLocations();
        if ($cached !== null) {
            return ['list' => $cached, 'chargedUsd' => 0.0];
        }
        if (!$fetchIfMissing) {
            return ['list' => [], 'chargedUsd' => 0.0];
        }
        $lock = $this->dataDir . '/dfs/locations.lock';
        return $this->withLock($lock, function () use ($projectId): array {
            $again = $this->readLocations();
            if ($again !== null) {
                return ['list' => $again, 'chargedUsd' => 0.0];
            }
            $live = $this->call('GET', '/serp/google/locations/cl', null, $projectId, 40);
            $this->assertTop($live['data'], $live['http']);
            $task = $this->firstTask($live['data']);
            if (!$this->taskOk($task)) {
                throw new RuntimeException('DataForSEO ubicaciones: ' . $this->taskMessage($task));
            }
            $list = $this->storeLocations(is_array($task['result'] ?? null) ? $task['result'] : [], $live['cost']);
            return ['list' => $list, 'chargedUsd' => $live['cost']];
        });
    }

    /** @return array<int, array<string, mixed>>|null */
    private function readLocations(): ?array
    {
        $data = gscReadJson($this->dataDir . '/dfs/locations-cl.json', []);
        $list = $data['locations'] ?? null;
        if (!is_array($list) || $list === []) {
            return null;
        }
        return $list;
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function storeLocations(array $rows, float $cost): array
    {
        $list = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = (int) ($row['location_code'] ?? 0);
            $name = trim((string) ($row['location_name'] ?? ''));
            if ($code <= 0 || $name === '') {
                continue;
            }
            $list[] = [
                'location_code' => $code,
                'location_name' => ersClip($name, 140),
                'location_type' => ersClip(trim((string) ($row['location_type'] ?? '')), 40),
                'location_code_parent' => (int) ($row['location_code_parent'] ?? 0),
            ];
        }
        if ($list === []) {
            throw new RuntimeException('DataForSEO no devolvió ubicaciones de Chile.');
        }
        $dir = $this->dataDir . '/dfs';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo guardar el listado de ciudades.');
        }
        if (!gscWriteJson($dir . '/locations-cl.json', [
            'fetchedAt' => time(),
            'costUsd' => $cost,
            'locations' => $list,
        ])) {
            throw new RuntimeException('No se pudo guardar el listado de ciudades.');
        }
        return $list;
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    private function filterLocations(array $list, string $query): array
    {
        $needle = gscAscii($query);
        if (strlen($needle) < 2) {
            return [];
        }
        $parents = $this->parentMap($list);
        $hits = [];
        foreach ($list as $row) {
            $type = strtolower((string) ($row['location_type'] ?? ''));
            if ($type === 'country') {
                continue;
            }
            $folded = gscAscii((string) $row['location_name']);
            $pos = strpos($folded, $needle);
            if ($pos === false) {
                continue;
            }
            $typeScore = in_array($type, ['city', 'municipality'], true) ? 0 : (in_array($type, ['region', 'district', 'county'], true) ? 1 : 2);
            $hits[] = [
                'score' => $pos === 0 ? 0 : 1,
                'typeScore' => $typeScore,
                'len' => strlen($folded),
                'row' => [
                    'location_code' => (int) $row['location_code'],
                    'location_name' => (string) $row['location_name'],
                    'location_type' => (string) $row['location_type'],
                    'label' => $this->locationLabel($row, $parents),
                ],
            ];
        }
        usort($hits, static function (array $a, array $b): int {
            return ($a['typeScore'] <=> $b['typeScore'])
                ?: ($a['score'] <=> $b['score'])
                ?: ($a['len'] <=> $b['len']);
        });
        return array_map(static fn (array $hit): array => $hit['row'], array_slice($hits, 0, 12));
    }

    /** @param array<int, array<string, mixed>> $list @return array<int, string> */
    private function parentMap(array $list): array
    {
        $map = [];
        foreach ($list as $row) {
            $map[(int) $row['location_code']] = (string) $row['location_name'];
        }
        return $map;
    }

    /** @param array<string, mixed> $row @param array<int, string> $parents */
    private function locationLabel(array $row, array $parents): string
    {
        $name = (string) $row['location_name'];
        $parent = $parents[(int) ($row['location_code_parent'] ?? 0)] ?? '';
        if ($parent !== '' && $parent !== $name && !str_contains($name, ',')) {
            return $name . ', ' . $parent;
        }
        return $name;
    }

    /** @return array<string, mixed>|null */
    private function freshCache(string $file): ?array
    {
        $data = gscReadJson($file, []);
        $at = (int) ($data['fetchedAt'] ?? 0);
        if ($at <= 0 || (time() - $at) >= self::CACHE_TTL || !is_array($data['result'] ?? null)) {
            return null;
        }
        return $data;
    }

    /** @param array<string, mixed> $result */
    private function writeCache(string $file, float $cost, array $result): void
    {
        if (!gscWriteJson($file, ['fetchedAt' => time(), 'costUsd' => $cost, 'result' => $result])) {
            throw new RuntimeException('No se pudo guardar la caché de DataForSEO.');
        }
    }

    private function hash(string ...$parts): string
    {
        return substr(hash('sha256', implode("\n", $parts)), 0, 24);
    }

    private function fold(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function normUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $host = preg_replace('#^www\.#', '', strtolower((string) $parts['host'])) ?? strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $path = $path === '/' ? '/' : rtrim($path, '/');
        return 'https://' . $host . $path;
    }

    private function hostOf(string $value): string
    {
        return self::hostOfValue($value);
    }

    private static function hostOfValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!str_contains($value, '://')) {
            $value = 'https://' . ltrim($value, '/');
        }
        $host = strtolower((string) (parse_url($value, PHP_URL_HOST) ?? ''));
        return preg_replace('#^www\.#', '', $host) ?? $host;
    }

    private static function isGoogleHost(string $host): bool
    {
        return $host === 'google.com' || $host === 'google.cl'
            || str_ends_with($host, '.google.com') || str_ends_with($host, '.google.cl');
    }

    /** @return array{login: string, password: string} */
    private function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }
        $file = gscReadJson($this->dataDir . '/dataforseo.json', []);
        $login = trim((string) ($file['DATAFORSEO_LOGIN'] ?? $file['login'] ?? ''));
        $password = trim((string) ($file['DATAFORSEO_PASSWORD'] ?? $file['password'] ?? ''));
        $envLogin = getenv('DATAFORSEO_LOGIN');
        $envPassword = getenv('DATAFORSEO_PASSWORD');
        if (is_string($envLogin) && trim($envLogin) !== '') {
            $login = trim($envLogin);
        }
        if (is_string($envPassword) && trim($envPassword) !== '') {
            $password = trim($envPassword);
        }
        $this->config = ['login' => $login, 'password' => $password];
        return $this->config;
    }

    private function assertProject(string $projectId): string
    {
        $projectId = strtolower(trim($projectId));
        if (!ersLooksLikeUuid($projectId)) {
            throw new InvalidArgumentException('Proyecto inválido.');
        }
        return $projectId;
    }

    private function projectDir(string $projectId): string
    {
        $projectId = $this->assertProject($projectId);
        $dir = $this->dataDir . '/dfs/' . $projectId;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el almacenamiento del proyecto.');
        }
        return $dir;
    }

    private function ledgerPath(string $projectId): string
    {
        return $this->dataDir . '/dfs/' . $this->assertProject($projectId) . '/ledger.json';
    }

    /** @param callable(): mixed $fn */
    private function withLock(string $lockPath, callable $fn): mixed
    {
        $dir = dirname($lockPath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo preparar la caché de DataForSEO.');
        }
        $fp = fopen($lockPath, 'c');
        if ($fp === false) {
            return $fn();
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('No se pudo reservar la consulta de DataForSEO.');
            }
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
