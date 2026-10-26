<?php
/**
 * Informe de cliente. Los números salen de Search Console, Analytics y la
 * portada en celular; la prosa solo los explica. Un clic de Google y una
 * visita de Analytics no son el mismo hecho, y el texto no los iguala.
 *
 * GET  action=status
 * GET  action=properties
 * GET  action=build&host=&lang=
 * POST action=prefs     { host, reportLang?, ga4Property? }
 * POST action=pdf       { host, lang }
 */

declare(strict_types=1);

define('ERS_LIBRARY', true);
require_once __DIR__ . '/gsc.php';
require_once __DIR__ . '/lib.php';

const REPORT_GA_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
const REPORT_GA_TTL = 21600;
const REPORT_PSI_TTL = 604800;
const REPORT_DIR_TTL = 43200;

function reportSecret(string $dataDir, string $file): string
{
    $data = gscReadJson($dataDir . '/' . $file, []);
    foreach (['apiKey', 'api_key', 'key'] as $key) {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value !== '' && !str_starts_with($value, 'PEGAR') && !str_starts_with($value, 'YOUR')) {
            return $value;
        }
    }
    return '';
}

function reportPublicError(string $msg): string
{
    $msg = preg_replace('/key=[^&\s]+/i', 'key=***', $msg) ?? $msg;
    return ersClip($msg, 320);
}

function reportApiMessage(array $res, string $fallback): string
{
    $data = is_array($res['data'] ?? null) ? $res['data'] : [];
    $msg = $data['error']['message'] ?? $data['Error'] ?? $data['error_description'] ?? $res['error'] ?? $fallback;
    return reportPublicError((string) $msg);
}

function reportCopy(string $lang): array
{
    $all = [
        'es' => [
            'kicker' => 'Mono Studio',
            'title' => 'Informe de crecimiento',
            'clicks' => 'Clics en Google',
            'impressions' => 'Veces visible',
            'ctr' => 'Entraron',
            'position' => 'Lugar promedio',
            'people' => 'Personas',
            'visits' => 'Visitas',
            'stayed' => 'Se quedaron',
            'fromGoogle' => 'Desde el buscador',
            'pages' => 'Cada página',
            'colPage' => 'Página',
            'colSeen' => 'Veces visible',
            'colClicks' => 'Clics',
            'colPlace' => 'Lugar',
            'colNext' => 'Qué vamos a hacer',
            'moves' => 'Próximos 30 días',
            'searched' => 'Los buscan por',
            'flat' => 'Sin cambio',
            'places' => 'puestos',
            'caveat' => 'Los clics de Google y las visitas del sitio no miden lo mismo: alguien puede ver el resultado y no entrar, o entrar y que Analytics lo cuente de otra forma. Los dos números conviven.',
            'sourcesSearch' => 'Google Search',
            'sourcesAnalytics' => 'Google Analytics',
            'sourcesSpeed' => 'velocidad en celular',
            'generated' => 'Generado',
            'speedNote' => 'Celular',
            'headline' => 'En los últimos 28 días, %s recibió %s clics desde Google%s.',
            'deltaUp' => ', %s más que el mes anterior',
            'deltaDown' => ', %s menos que el mes anterior',
            'leadSearch' => 'Apareció %s veces en resultados y quedó, en promedio, en el lugar %s.',
            'leadGa' => 'En el sitio hubo %s personas y %s visitas. %s llegaron desde el buscador.',
            'leadOpp' => 'La búsqueda «%s» ya los deja cerca de la primera página: es el crecimiento más cercano.',
            'leadClose' => 'Abajo está el trabajo de cada página y lo que vamos a hacer en los próximos 30 días.',
            'devices' => ['mobile' => 'celular', 'desktop' => 'computador', 'tablet' => 'tablet'],
        ],
        'en' => [
            'kicker' => 'Mono Studio',
            'title' => 'Growth report',
            'clicks' => 'Clicks from Google',
            'impressions' => 'Times shown',
            'ctr' => 'Click rate',
            'position' => 'Average place',
            'people' => 'People',
            'visits' => 'Visits',
            'stayed' => 'Stayed to look',
            'fromGoogle' => 'From search',
            'pages' => 'Each page',
            'colPage' => 'Page',
            'colSeen' => 'Times shown',
            'colClicks' => 'Clicks',
            'colPlace' => 'Place',
            'colNext' => 'What we will do',
            'moves' => 'Next 30 days',
            'searched' => 'People search',
            'flat' => 'No change',
            'places' => 'places',
            'caveat' => 'A click in Google and a visit in Analytics are not the same event. Someone can see the result and not open it, or land on the site and be counted differently. Both numbers stay, side by side.',
            'sourcesSearch' => 'Google Search',
            'sourcesAnalytics' => 'Google Analytics',
            'sourcesSpeed' => 'mobile speed',
            'generated' => 'Prepared',
            'speedNote' => 'Mobile',
            'headline' => 'In the last 28 days, %s got %s clicks from Google%s.',
            'deltaUp' => ', %s more than the previous month',
            'deltaDown' => ', %s fewer than the previous month',
            'leadSearch' => 'The site showed up %s times and sat at an average place of %s.',
            'leadGa' => 'The site had %s people and %s visits. %s came from search.',
            'leadOpp' => 'The search “%s” already puts the site near the first page. That is the closest growth.',
            'leadClose' => 'Below is the work on each page, and what we will do in the next 30 days.',
            'devices' => ['mobile' => 'mobile', 'desktop' => 'desktop', 'tablet' => 'tablet'],
        ],
        'pt' => [
            'kicker' => 'Mono Studio',
            'title' => 'Relatório de crescimento',
            'clicks' => 'Cliques no Google',
            'impressions' => 'Vezes visível',
            'ctr' => 'Entraram',
            'position' => 'Posição média',
            'people' => 'Pessoas',
            'visits' => 'Visitas',
            'stayed' => 'Ficaram para ver',
            'fromGoogle' => 'Pela pesquisa',
            'pages' => 'Cada página',
            'colPage' => 'Página',
            'colSeen' => 'Vezes visível',
            'colClicks' => 'Cliques',
            'colPlace' => 'Posição',
            'colNext' => 'O que vamos fazer',
            'moves' => 'Próximos 30 dias',
            'searched' => 'Buscam por',
            'flat' => 'Sem mudança',
            'places' => 'posições',
            'caveat' => 'Os cliques do Google e as visitas do site não medem a mesma coisa: alguém pode ver o resultado e não entrar, ou entrar e o Analytics contar de outro jeito. Os dois números convivem.',
            'sourcesSearch' => 'Google Search',
            'sourcesAnalytics' => 'Google Analytics',
            'sourcesSpeed' => 'velocidade no celular',
            'generated' => 'Gerado',
            'speedNote' => 'Celular',
            'headline' => 'Nos últimos 28 dias, %s recebeu %s cliques no Google%s.',
            'deltaUp' => ', %s a mais que o mês anterior',
            'deltaDown' => ', %s a menos que o mês anterior',
            'leadSearch' => 'Apareceu %s vezes nos resultados e ficou, em média, na posição %s.',
            'leadGa' => 'O site teve %s pessoas e %s visitas. %s chegaram pela pesquisa.',
            'leadOpp' => 'A busca «%s» já deixa o site perto da primeira página: é o crescimento mais próximo.',
            'leadClose' => 'Abaixo está o trabalho de cada página e o que vamos fazer nos próximos 30 dias.',
            'devices' => ['mobile' => 'celular', 'desktop' => 'computador', 'tablet' => 'tablet'],
        ],
    ];
    $copy = $all[$lang] ?? $all['es'];
    $copy['act'] = [
        'es' => [
            'index' => ['Lograr que Google indexe esta página', 'Hoy no aparece en el índice. Sin eso, el resto del trabajo no tiene dónde rankear.'],
            'speed' => ['Acelerar la página en el celular', 'En el teléfono carga lento. Eso frena la posición y también a quien ya hizo clic.'],
            'falling' => ['Recuperar los clics que se perdieron', 'Bajó frente al mes anterior. Vamos a revisar si el contenido sigue respondiendo lo que la gente busca.'],
            'push' => ['Subir esta página a los primeros lugares', 'Ya aparece cerca de la primera página. Reforzamos el contenido para que suba y traiga más clics.'],
            'title' => ['Reescribir cómo se ve en Google', 'Se muestra seguido y poca gente hace clic. El título que ve el usuario no está convenciendo.'],
            'growing' => ['Ampliar lo que ya está creciendo', 'Esta página está ganando clics. La ampliamos ahora, antes de que se estanque.'],
            'hold' => ['Mantener y medir', 'No es la prioridad de este mes. La dejamos estable y seguimos el número.'],
        ],
        'en' => [
            'index' => ['Get this page into Google’s index', 'It is not in the index today. Nothing else can rank until it is.'],
            'speed' => ['Make this page faster on a phone', 'It loads slowly on mobile. That holds the ranking back, and the people who already clicked.'],
            'falling' => ['Win back the clicks that dropped', 'It fell against the previous month. We will check that the page still answers what people search.'],
            'push' => ['Move this page into the top places', 'It already shows near the first page. We will strengthen the content so it climbs and earns more clicks.'],
            'title' => ['Rewrite how it looks in Google', 'It is shown often and few people click. The title they see is not convincing.'],
            'growing' => ['Expand what is already growing', 'This page is gaining clicks. We extend it now, before it stalls.'],
            'hold' => ['Keep it and measure', 'Not the priority this month. We leave it stable and watch the number.'],
        ],
        'pt' => [
            'index' => ['Fazer o Google indexar esta página', 'Hoje ela não está no índice. Sem isso, o resto do trabalho não tem onde ranquear.'],
            'speed' => ['Acelerar a página no celular', 'No telefone ela carrega devagar. Isso segura a posição e também quem já clicou.'],
            'falling' => ['Recuperar os cliques que caíram', 'Caiu em relação ao mês anterior. Vamos ver se o conteúdo ainda responde ao que as pessoas buscam.'],
            'push' => ['Levar esta página aos primeiros lugares', 'Já aparece perto da primeira página. Reforçamos o conteúdo para ela subir e trazer mais cliques.'],
            'title' => ['Reescrever como ela aparece no Google', 'É exibida bastante e pouca gente clica. O título que a pessoa vê não está convencendo.'],
            'growing' => ['Ampliar o que já está crescendo', 'Esta página está ganhando cliques. Ampliamos agora, antes que ela estanque.'],
            'hold' => ['Manter e medir', 'Não é a prioridade deste mês. Deixamos estável e acompanhamos o número.'],
        ],
    ][$lang] ?? [
        'hold' => ['Mantener y medir', 'No es la prioridad de este mes.'],
    ];
    return $copy;
}

function reportLangName(string $lang): string
{
    return [
        'es' => 'español latinoamericano neutro, trato de usted, sin jerga de agencia',
        'en' => 'clear professional English, addressed as you',
        'pt' => 'português brasileiro claro, tratamento por você, sem jargão de agência',
    ][$lang] ?? 'español latinoamericano neutro';
}

function reportNum(float $n, string $lang, int $decimals = 0): string
{
    $dec = $lang === 'en' ? '.' : ',';
    $thou = $lang === 'en' ? ',' : '.';
    return number_format($n, $decimals, $dec, $thou);
}

function reportPct(float $ratio, string $lang): string
{
    return reportNum($ratio * 100, $lang, 1) . '%';
}

function reportDateLabel(string $iso, string $lang): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
    if (!$dt) {
        return $iso;
    }
    $months = [
        'es' => ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'],
        'en' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'pt' => ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'],
    ];
    $names = $months[$lang] ?? $months['es'];
    $day = (int) $dt->format('j');
    $mon = $names[(int) $dt->format('n') - 1];
    $year = $dt->format('Y');
    return $lang === 'en' ? $mon . ' ' . $day . ', ' . $year : $day . ' ' . $mon . ' ' . $year;
}

function reportPath(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '' || $path === '/') {
        return '/';
    }
    $path = rawurldecode($path);
    return rtrim($path, '/') ?: '/';
}

function reportNormPath(string $value): string
{
    $value = strtolower(trim($value));
    $value = explode('?', $value, 2)[0];
    $value = explode('#', $value, 2)[0];
    if ($value === '' || $value === '/' || $value === '(not set)') {
        return '/';
    }
    if (!str_starts_with($value, '/')) {
        $value = reportPath($value);
    }
    return rtrim($value, '/') ?: '/';
}

function reportClientName(string $host): string
{
    foreach (ersReadStore()['clients'] as $client) {
        if (ersHostOf((string) ($client['siteUrl'] ?? '')) === $host) {
            $name = trim((string) ($client['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }
    return $host;
}

function reportHostConfig(string $dataDir, string $host): array
{
    $config = gscNormalizeConfig(gscReadJson($dataDir . '/gsc.json', []));
    $entry = $config['hosts'][$host] ?? [];
    return is_array($entry) ? gscHostEntry($entry) : gscHostEntry([]);
}

function reportSavePrefs(string $dataDir, string $host, array $input): array
{
    $file = $dataDir . '/gsc.json';
    $config = gscNormalizeConfig(gscReadJson($file, []));
    $prev = gscHostEntry(is_array($config['hosts'][$host] ?? null) ? $config['hosts'][$host] : []);
    if (array_key_exists('reportLang', $input)) {
        $prev['reportLang'] = gscReportLang((string) $input['reportLang']);
    }
    if (array_key_exists('ga4Property', $input)) {
        $prev['ga4Property'] = gscGa4Property((string) $input['ga4Property']);
    }
    $config['hosts'][$host] = gscHostEntry($prev);
    if (!gscWriteJson($file, $config)) {
        throw new RuntimeException('No se pudo guardar el idioma del informe');
    }
    return $config['hosts'][$host];
}

function reportWindows(array $gsc): array
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

function reportPeriod28(array $gsc): array
{
    foreach ($gsc['periods'] ?? [] as $period) {
        if (is_array($period) && ($period['id'] ?? '') === '28d') {
            return $period;
        }
    }
    return [
        'totals' => is_array($gsc['totals'] ?? null) ? $gsc['totals'] : [],
        'delta' => is_array($gsc['totalsDelta'] ?? null) ? $gsc['totalsDelta'] : [],
        'range' => is_array($gsc['range'] ?? null) ? $gsc['range'] : [],
    ];
}

function reportGaRows(array $report): array
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

function reportRangeRow(array $rows, string $name, int $fallback): array
{
    foreach ($rows as $row) {
        if (($row['dateRange'] ?? '') === $name) {
            return $row;
        }
    }
    return $rows[$fallback] ?? [];
}

function reportChannelName(string $name, string $lang): string
{
    $map = [
        'Organic Search' => ['es' => 'Búsqueda en Google', 'en' => 'Google search', 'pt' => 'Pesquisa no Google'],
        'Direct' => ['es' => 'Directo', 'en' => 'Direct', 'pt' => 'Direto'],
        'Referral' => ['es' => 'Otros sitios', 'en' => 'Other sites', 'pt' => 'Outros sites'],
        'Organic Social' => ['es' => 'Redes', 'en' => 'Social', 'pt' => 'Redes'],
        'Paid Social' => ['es' => 'Anuncios en redes', 'en' => 'Paid social', 'pt' => 'Anúncios em redes'],
        'Paid Search' => ['es' => 'Anuncios', 'en' => 'Paid search', 'pt' => 'Anúncios'],
        'Email' => ['es' => 'Correo', 'en' => 'Email', 'pt' => 'E-mail'],
        'Unassigned' => ['es' => 'Sin clasificar', 'en' => 'Unassigned', 'pt' => 'Sem classificação'],
        'Cross-network' => ['es' => 'Campañas', 'en' => 'Campaigns', 'pt' => 'Campanhas'],
        'Organic Video' => ['es' => 'Video', 'en' => 'Video', 'pt' => 'Vídeo'],
        'Display' => ['es' => 'Banners', 'en' => 'Display', 'pt' => 'Banners'],
    ];
    return $map[$name][$lang] ?? $name;
}

function reportGaDirectory(string $dataDir, array $sa, bool $fresh = false): array
{
    $file = $dataDir . '/ga4-directory.json';
    $cached = gscReadJson($file, []);
    $age = time() - (int) ($cached['fetchedAt'] ?? 0);
    $ttl = ($cached['error'] ?? '') !== '' ? 600 : REPORT_DIR_TTL;
    if (!$fresh && isset($cached['fetchedAt']) && is_array($cached['properties'] ?? null) && $age < $ttl) {
        return $cached;
    }
    if ($sa === []) {
        return ['fetchedAt' => 0, 'email' => '', 'error' => 'Falta la cuenta de servicio.', 'properties' => []];
    }

    try {
        $token = gscAccessToken($sa, $dataDir . '/ga4-token.json', REPORT_GA_SCOPE);
    } catch (Throwable $e) {
        return ['fetchedAt' => time(), 'email' => (string) ($sa['client_email'] ?? ''), 'error' => reportPublicError($e->getMessage()), 'properties' => []];
    }

    $auth = ['Authorization: Bearer ' . $token];
    $list = gscHttp('GET', 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200', null, $auth);
    if (!$list['ok']) {
        return [
            'fetchedAt' => time(),
            'email' => (string) ($sa['client_email'] ?? ''),
            'error' => 'Analytics no dejó listar propiedades. Activá la API Admin y Data, y sumá ' . ($sa['client_email'] ?? 'la cuenta de servicio') . ' como Lector. ' . reportApiMessage($list, ''),
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

function reportResolveProperty(string $dataDir, array $sa, string $host, string $configured): array
{
    if ($configured !== '') {
        return ['id' => $configured, 'detected' => false, 'name' => '', 'error' => ''];
    }
    $dir = reportGaDirectory($dataDir, $sa, false);
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

function reportFetchGa(string $dataDir, array $sa, string $host, string $propertyId, array $windows, bool $fresh): array
{
    $file = $dataDir . '/ga4-cache-' . md5($host) . '.json';
    $cached = gscReadJson($file, []);
    $same = ($cached['property'] ?? '') === $propertyId
        && ($cached['range']['start'] ?? '') === $windows['start']
        && ($cached['range']['end'] ?? '') === $windows['end'];
    if (!$fresh && $same && empty($cached['error']) && (time() - (int) ($cached['fetchedAt'] ?? 0)) < REPORT_GA_TTL) {
        $cached['cached'] = true;
        return $cached;
    }

    $token = gscAccessToken($sa, $dataDir . '/ga4-token.json', REPORT_GA_SCOPE);
    $metrics = [
        ['name' => 'sessions'],
        ['name' => 'totalUsers'],
        ['name' => 'newUsers'],
        ['name' => 'engagementRate'],
        ['name' => 'averageSessionDuration'],
        ['name' => 'screenPageViews'],
        ['name' => 'conversions'],
    ];
    $current = ['startDate' => $windows['start'], 'endDate' => $windows['end'], 'name' => 'current'];
    $previous = ['startDate' => $windows['prevStart'], 'endDate' => $windows['prevEnd'], 'name' => 'previous'];
    $body = [
        'requests' => [
            [
                'dateRanges' => [$current, $previous],
                'dimensions' => [['name' => 'dateRange']],
                'metrics' => $metrics,
            ],
            [
                'dateRanges' => [$current],
                'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
                'metrics' => [['name' => 'sessions'], ['name' => 'engagedSessions'], ['name' => 'conversions']],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => 8,
            ],
            [
                'dateRanges' => [$current],
                'dimensions' => [['name' => 'landingPagePlusQueryString']],
                'metrics' => [['name' => 'sessions'], ['name' => 'engagementRate'], ['name' => 'conversions']],
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
                'dateRanges' => [$current],
                'dimensionFilter' => [
                    'filter' => [
                        'fieldName' => 'sessionDefaultChannelGroup',
                        'stringFilter' => ['matchType' => 'EXACT', 'value' => 'Organic Search'],
                    ],
                ],
                'metrics' => [['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => 'conversions']],
            ],
        ],
    ];

    $res = gscHttp(
        'POST',
        'https://analyticsdata.googleapis.com/v1beta/properties/' . $propertyId . ':batchRunReports',
        $body,
        ['Authorization: Bearer ' . $token]
    );
    if (!$res['ok']) {
        throw new RuntimeException(reportApiMessage($res, 'Analytics no respondió'));
    }

    $reports = $res['data']['reports'] ?? [];
    $totals = reportGaRows(is_array($reports[0] ?? null) ? $reports[0] : []);
    $now = reportRangeRow($totals, 'current', 0);
    $prev = reportRangeRow($totals, 'previous', 1);
    $channels = reportGaRows(is_array($reports[1] ?? null) ? $reports[1] : []);
    $landings = reportGaRows(is_array($reports[2] ?? null) ? $reports[2] : []);
    $devices = reportGaRows(is_array($reports[3] ?? null) ? $reports[3] : []);
    $organicRows = reportGaRows(is_array($reports[4] ?? null) ? $reports[4] : []);
    $organic = $organicRows[0] ?? [];

    $payload = [
        'ok' => true,
        'cached' => false,
        'fetchedAt' => time(),
        'property' => $propertyId,
        'range' => ['start' => $windows['start'], 'end' => $windows['end']],
        'current' => [
            'sessions' => (int) round($now['sessions'] ?? 0),
            'users' => (int) round($now['totalUsers'] ?? 0),
            'newUsers' => (int) round($now['newUsers'] ?? 0),
            'engagementRate' => (float) ($now['engagementRate'] ?? 0),
            'avgDuration' => (float) ($now['averageSessionDuration'] ?? 0),
            'views' => (int) round($now['screenPageViews'] ?? 0),
            'conversions' => (int) round($now['conversions'] ?? 0),
        ],
        'previous' => [
            'sessions' => (int) round($prev['sessions'] ?? 0),
            'users' => (int) round($prev['totalUsers'] ?? 0),
        ],
        'organic' => [
            'sessions' => (int) round($organic['sessions'] ?? 0),
            'users' => (int) round($organic['totalUsers'] ?? 0),
            'conversions' => (int) round($organic['conversions'] ?? 0),
        ],
        'channels' => array_map(static fn ($row) => [
            'name' => (string) ($row['sessionDefaultChannelGroup'] ?? ''),
            'sessions' => (int) round($row['sessions'] ?? 0),
            'conversions' => (int) round($row['conversions'] ?? 0),
        ], $channels),
        'landings' => array_map(static fn ($row) => [
            'path' => reportNormPath((string) ($row['landingPagePlusQueryString'] ?? '')),
            'sessions' => (int) round($row['sessions'] ?? 0),
            'engagementRate' => (float) ($row['engagementRate'] ?? 0),
            'conversions' => (int) round($row['conversions'] ?? 0),
        ], $landings),
        'devices' => array_map(static fn ($row) => [
            'name' => strtolower((string) ($row['deviceCategory'] ?? '')),
            'sessions' => (int) round($row['sessions'] ?? 0),
        ], $devices),
        'error' => '',
    ];
    gscWriteJson($file, $payload);
    return $payload;
}

function reportPsiPage(string $apiKey, string $url): ?array
{
    $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url='
        . rawurlencode($url)
        . '&strategy=mobile&category=performance&key=' . rawurlencode($apiKey);
    $res = gscHttp('GET', $endpoint, null, [], 12);
    if (!$res['ok'] || !is_array($res['data'])) {
        return null;
    }
    $data = $res['data'];
    $audits = is_array($data['lighthouseResult']['audits'] ?? null) ? $data['lighthouseResult']['audits'] : [];
    $score = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
    $field = strtoupper((string) ($data['loadingExperience']['overall_category'] ?? ''));
    if (!in_array($field, ['FAST', 'AVERAGE', 'SLOW'], true)) {
        $field = '';
    }
    return [
        'at' => time(),
        'score' => is_numeric($score) ? (int) round(((float) $score) * 100) : null,
        'lcp' => (string) ($audits['largest-contentful-paint']['displayValue'] ?? ''),
        'cls' => (string) ($audits['cumulative-layout-shift']['displayValue'] ?? ''),
        'inp' => (string) ($audits['interaction-to-next-paint']['displayValue'] ?? ''),
        'field' => $field,
    ];
}

function reportPsi(string $dataDir, string $host, string $apiKey, bool $fresh): array
{
    $file = $dataDir . '/psi-cache-' . md5($host) . '.json';
    $cached = gscReadJson($file, []);
    $pages = is_array($cached['pages'] ?? null) ? $cached['pages'] : [];
    $home = 'https://' . $host . '/';
    $hit = $pages[$home] ?? null;
    $stale = !is_array($hit) || (time() - (int) ($hit['at'] ?? 0)) >= REPORT_PSI_TTL;
    if ($apiKey !== '' && ($fresh || $stale)) {
        $measured = reportPsiPage($apiKey, $home);
        if ($measured) {
            $pages[$home] = $measured;
            gscWriteJson($file, ['pages' => $pages]);
        }
    }
    return ['pages' => $pages, 'home' => is_array($pages[$home] ?? null) ? $pages[$home] : null];
}

function reportQueryFor(array $kw, string $path): string
{
    $want = reportNormPath($path);
    foreach (['striking', 'lowCtr', 'top'] as $bucket) {
        foreach ($kw['buckets'][$bucket] ?? [] as $row) {
            if (!is_array($row) || !empty($row['brand'])) {
                continue;
            }
            if (reportNormPath((string) ($row['page'] ?? '')) === $want && trim((string) ($row['q'] ?? '')) !== '') {
                return (string) $row['q'];
            }
        }
    }
    return '';
}

function reportPageAction(array $page, ?array $psi): string
{
    $tone = (string) ($page['indexStatus']['tone'] ?? '');
    $impr = (int) ($page['current']['impressions'] ?? 0);
    $clicks = (int) ($page['current']['clicks'] ?? 0);
    $pos = (float) ($page['current']['position'] ?? 0);
    $ctr = (float) ($page['current']['ctr'] ?? 0);
    $dClicks = (int) ($page['delta']['clicks'] ?? 0);
    $slow = is_array($psi) && (($psi['field'] ?? '') === 'SLOW' || ((int) ($psi['score'] ?? 100) > 0 && (int) $psi['score'] < 50));

    if ($tone === 'issue') {
        return 'index';
    }
    if ($slow && $impr >= 20) {
        return 'speed';
    }
    if ($dClicks <= -5) {
        return 'falling';
    }
    if ($pos >= 4 && $pos <= 20 && $impr >= 30) {
        return 'push';
    }
    if ($pos > 0 && $pos <= 8 && $impr >= 80 && $ctr < gscExpectedCtr($pos) * 0.6) {
        return 'title';
    }
    if ($dClicks >= 5 && $clicks > 0) {
        return 'growing';
    }
    return 'hold';
}

function reportPages(array $gsc, array $kw, ?array $ga, array $psi): array
{
    $landings = [];
    foreach ((is_array($ga) ? ($ga['landings'] ?? []) : []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $path = (string) ($row['path'] ?? '/');
        if (!isset($landings[$path])) {
            $landings[$path] = $row;
        }
    }
    $psiByPath = [];
    foreach ($psi['pages'] ?? [] as $url => $row) {
        if (is_array($row)) {
            $psiByPath[reportNormPath((string) $url)] = $row;
        }
    }

    $weight = ['index' => 0, 'falling' => 1, 'speed' => 2, 'push' => 3, 'title' => 4, 'growing' => 5, 'hold' => 6];
    $items = [];
    foreach ($gsc['pages'] ?? [] as $page) {
        if (!is_array($page) || !empty($page['error'])) {
            continue;
        }
        $path = reportPath((string) ($page['url'] ?? ''));
        $key = reportNormPath($path);
        $speed = $psiByPath[$key] ?? null;
        $action = reportPageAction($page, $speed);
        $landing = $landings[$key] ?? null;
        $items[] = [
            'path' => $path,
            'clicks' => (int) ($page['current']['clicks'] ?? 0),
            'impressions' => (int) ($page['current']['impressions'] ?? 0),
            'ctr' => (float) ($page['current']['ctr'] ?? 0),
            'position' => (float) ($page['current']['position'] ?? 0),
            'deltaClicks' => (int) ($page['delta']['clicks'] ?? 0),
            'query' => reportQueryFor($kw, $path),
            'sessions' => is_array($landing) ? (int) $landing['sessions'] : null,
            'engagement' => is_array($landing) ? (float) $landing['engagementRate'] : null,
            'psi' => $speed,
            'action' => $action,
            'weight' => $weight[$action] ?? 6,
        ];
    }
    usort($items, static fn ($a, $b) => ($a['weight'] <=> $b['weight']) ?: ($b['impressions'] <=> $a['impressions']));
    $active = array_values(array_filter($items, static fn ($row) => $row['action'] !== 'hold'));
    $picked = count($active) >= 3 ? array_slice($active, 0, 8) : array_slice($items, 0, 6);
    foreach ($picked as &$row) {
        unset($row['weight']);
    }
    unset($row);
    return $picked;
}

function reportPlain(string $value, int $max): string
{
    $value = trim(preg_replace('/\s+/u', ' ', str_replace(['**', '`', '#'], '', $value)) ?? '');
    return ersClip($value, $max);
}

function reportNarrative(string $dataDir, string $host, string $lang, array $dossier, string $signature, bool $fresh): array
{
    $file = $dataDir . '/report-narrative-' . md5($host) . '.json';
    $cached = gscReadJson($file, []);
    if (
        !$fresh
        && ($cached['signature'] ?? '') === $signature
        && ($cached['lang'] ?? '') === $lang
        && is_array($cached['narrative'] ?? null)
    ) {
        return $cached['narrative'] + ['source' => 'cache', 'error' => ''];
    }

    $cfg = ersLoadGeminiConfig();
    if ($cfg['apiKey'] === '') {
        return ['source' => 'template', 'error' => ''];
    }

    $system = 'Escribís el informe que Mono Studio le entrega al dueño del negocio. '
        . 'Idioma obligatorio: ' . reportLangName($lang) . '. No mezcles idiomas. '
        . 'Usá solo cifras que estén en el JSON. No inventes búsquedas, páginas ni porcentajes. '
        . 'Un clic de Google y una visita de Analytics no son lo mismo: no los presentes como si coincidieran. '
        . 'Sin jerga (nada de CTR, indexación, severidad, Search Console, API). '
        . 'headline: una frase. lead: dos o tres frases, el hallazgo primero. '
        . 'pages: una frase por path que esté en el JSON, diciendo qué vamos a hacer. '
        . 'moves: como máximo 4 trabajos de los próximos 30 días, cada uno atado a un path real. '
        . 'Texto plano, sin markdown.';

    try {
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => json_encode($dossier, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}']],
            ]],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 4096,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'headline' => ['type' => 'string'],
                        'lead' => ['type' => 'string'],
                        'pages' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string'],
                                'line' => ['type' => 'string'],
                            ],
                            'required' => ['path', 'line'],
                        ]],
                        'moves' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'detail' => ['type' => 'string'],
                                'path' => ['type' => 'string'],
                            ],
                            'required' => ['title', 'detail', 'path'],
                        ]],
                    ],
                    'required' => ['headline', 'lead', 'pages', 'moves'],
                ],
            ],
        ];
        $res = ersGeminiHttp(ersGeminiUrl($cfg), $payload, 28);
        $text = '';
        foreach ($res['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (empty($part['thought']) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }
        $raw = json_decode(trim($text), true);
        if (!is_array($raw)) {
            throw new RuntimeException('La redacción no vino en un formato usable');
        }
        $known = [];
        foreach ($dossier['pages'] ?? [] as $page) {
            $known[(string) ($page['path'] ?? '')] = true;
        }
        $pages = [];
        foreach ($raw['pages'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = reportNormPath((string) ($row['path'] ?? ''));
            $line = reportPlain((string) ($row['line'] ?? ''), 240);
            if ($line === '' || !isset($known[$path]) || isset($pages[$path])) {
                continue;
            }
            $pages[$path] = $line;
        }
        $moves = [];
        foreach ($raw['moves'] ?? [] as $row) {
            if (!is_array($row) || count($moves) >= 4) {
                continue;
            }
            $title = reportPlain((string) ($row['title'] ?? ''), 120);
            $detail = reportPlain((string) ($row['detail'] ?? ''), 280);
            $path = reportNormPath((string) ($row['path'] ?? ''));
            if ($title === '' || $detail === '' || !isset($known[$path])) {
                continue;
            }
            $moves[] = ['title' => $title, 'detail' => $detail, 'path' => $path];
        }
        $narrative = [
            'headline' => reportPlain((string) ($raw['headline'] ?? ''), 200),
            'lead' => reportPlain((string) ($raw['lead'] ?? ''), 700),
            'pages' => $pages,
            'moves' => $moves,
            'source' => 'gemini',
            'error' => '',
        ];
        if ($narrative['headline'] === '' || $narrative['lead'] === '') {
            throw new RuntimeException('La redacción llegó vacía');
        }
        gscWriteJson($file, ['signature' => $signature, 'lang' => $lang, 'narrative' => $narrative]);
        return $narrative;
    } catch (Throwable $e) {
        return ['source' => 'template', 'error' => reportPublicError($e->getMessage())];
    }
}

function reportFill(string $tpl, array $args): string
{
    $parts = explode('%s', $tpl);
    $out = '';
    foreach ($parts as $i => $part) {
        $out .= $part;
        if ($i < count($parts) - 1) {
            $out .= (string) ($args[$i] ?? '');
        }
    }
    return $out;
}

function reportFallback(array $copy, string $name, array $search, ?array $ga, array $pages, string $lang): array
{
    $delta = (int) ($search['clicksDelta'] ?? 0);
    $clause = '';
    if ($delta > 0) {
        $clause = reportFill($copy['deltaUp'], [reportNum($delta, $lang)]);
    } elseif ($delta < 0) {
        $clause = reportFill($copy['deltaDown'], [reportNum(abs($delta), $lang)]);
    }
    $headline = reportFill($copy['headline'], [$name, reportNum((float) ($search['clicks'] ?? 0), $lang), $clause]);
    $lead = reportFill($copy['leadSearch'], [
        reportNum((float) ($search['impressions'] ?? 0), $lang),
        reportNum((float) ($search['position'] ?? 0), $lang, 1),
    ]);
    if ($ga && (int) ($ga['current']['sessions'] ?? 0) > 0) {
        $lead .= ' ' . reportFill($copy['leadGa'], [
            reportNum((float) $ga['current']['users'], $lang),
            reportNum((float) $ga['current']['sessions'], $lang),
            reportNum((float) ($ga['organic']['sessions'] ?? 0), $lang),
        ]);
    }
    $opp = '';
    foreach ($pages as $page) {
        if (($page['action'] ?? '') === 'push' && ($page['query'] ?? '') !== '') {
            $opp = (string) $page['query'];
            break;
        }
    }
    if ($opp !== '') {
        $lead .= ' ' . reportFill($copy['leadOpp'], [$opp]);
    }
    $lead .= ' ' . $copy['leadClose'];

    $lines = [];
    $moves = [];
    foreach ($pages as $page) {
        $action = (string) ($page['action'] ?? 'hold');
        $pair = $copy['act'][$action] ?? $copy['act']['hold'];
        $lines[$page['path']] = $pair[1];
        if ($action !== 'hold' && count($moves) < 4) {
            $moves[] = ['title' => $pair[0], 'detail' => $pair[1], 'path' => $page['path']];
        }
    }
    return ['headline' => $headline, 'lead' => $lead, 'pages' => $lines, 'moves' => $moves];
}

function reportE(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function reportDelta(float $value, string $kind, string $lang, array $copy): array
{
    $flat = ($kind === 'places' && abs($value) < 0.05)
        || ($kind === 'pp' && abs($value) < 0.0005)
        || ($kind === 'count' && abs($value) < 1);
    if ($flat) {
        return ['text' => $copy['flat'], 'tone' => 'flat'];
    }
    if ($kind === 'places') {
        $sign = $value > 0 ? '+' : '−';
        return ['text' => $sign . reportNum(abs($value), $lang, 1) . ' ' . $copy['places'], 'tone' => $value > 0 ? 'up' : 'down'];
    }
    if ($kind === 'pp') {
        $sign = $value > 0 ? '+' : '−';
        return ['text' => $sign . reportNum(abs($value) * 100, $lang, 1) . ' pp', 'tone' => $value > 0 ? 'up' : 'down'];
    }
    $sign = $value > 0 ? '+' : '−';
    return ['text' => $sign . reportNum(abs($value), $lang), 'tone' => $value > 0 ? 'up' : 'down'];
}

function reportRender(array $model): string
{
    $lang = (string) $model['lang'];
    $copy = $model['copy'];
    $e = 'reportE';
    $kpi = static function (string $label, string $value, array $delta) use ($e): string {
        return '<article class="kpi"><span>' . $e($label) . '</span><strong>' . $e($value) . '</strong><em class="' . $e($delta['tone']) . '">' . $e($delta['text']) . '</em></article>';
    };

    $search = $model['search'];
    $kpis = $kpi($copy['clicks'], reportNum((float) $search['clicks'], $lang), $model['deltas']['clicks'])
        . $kpi($copy['impressions'], reportNum((float) $search['impressions'], $lang), $model['deltas']['impressions'])
        . $kpi($copy['ctr'], reportPct((float) $search['ctr'], $lang), $model['deltas']['ctr'])
        . $kpi($copy['position'], reportNum((float) $search['position'], $lang, 1), $model['deltas']['position']);

    $gaHtml = '';
    $ga = $model['ga'];
    if (is_array($ga) && !empty($ga['ok'])) {
        $cur = $ga['current'];
        $prevSessions = (int) ($ga['previous']['sessions'] ?? 0);
        $sessionDelta = reportDelta((float) $cur['sessions'] - $prevSessions, 'count', $lang, $copy);
        $share = $cur['sessions'] > 0 ? reportPct($ga['organic']['sessions'] / $cur['sessions'], $lang) : '—';
        $gaHtml = '<div class="kpis kpis--ga">'
            . $kpi($copy['people'], reportNum((float) $cur['users'], $lang), reportDelta((float) $cur['users'] - (float) ($ga['previous']['users'] ?? 0), 'count', $lang, $copy))
            . $kpi($copy['visits'], reportNum((float) $cur['sessions'], $lang), $sessionDelta)
            . $kpi($copy['stayed'], reportPct((float) $cur['engagementRate'], $lang), ['text' => '', 'tone' => 'flat'])
            . $kpi($copy['fromGoogle'], $share, ['text' => reportNum((float) $ga['organic']['sessions'], $lang), 'tone' => 'flat'])
            . '</div>';

        $chips = '';
        foreach (array_slice($ga['channels'] ?? [], 0, 4) as $channel) {
            if ((int) ($channel['sessions'] ?? 0) < 1) {
                continue;
            }
            $chips .= '<span class="chip">' . $e(reportChannelName((string) $channel['name'], $lang)) . ' · ' . $e(reportNum((float) $channel['sessions'], $lang)) . '</span>';
        }
        $deviceBits = [];
        $deviceTotal = 0;
        foreach ($ga['devices'] ?? [] as $device) {
            $deviceTotal += (int) ($device['sessions'] ?? 0);
        }
        if ($deviceTotal > 0) {
            foreach ($ga['devices'] ?? [] as $device) {
                $key = (string) ($device['name'] ?? '');
                $label = $copy['devices'][$key] ?? $key;
                if ($label === '' || (int) $device['sessions'] < 1) {
                    continue;
                }
                $deviceBits[] = reportPct($device['sessions'] / $deviceTotal, $lang) . ' ' . $label;
            }
        }
        if ($chips !== '' || $deviceBits) {
            $gaHtml .= '<p class="mix">' . $chips . ($deviceBits ? '<span class="chip">' . $e(implode(' · ', $deviceBits)) . '</span>' : '') . '</p>';
        }
    }

    $moves = '';
    foreach ($model['moves'] as $i => $move) {
        $moves .= '<article class="move"><span class="num">' . $e(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)) . '</span><div><strong>'
            . $e((string) $move['title']) . '</strong><p>' . $e((string) $move['detail']) . '</p><em>'
            . $e((string) $move['path']) . '</em></div></article>';
    }

    $sources = [$copy['sourcesSearch']];
    if (is_array($ga) && !empty($ga['ok'])) {
        $sources[] = $copy['sourcesAnalytics'];
    }
    if (!empty($model['hasSpeed'])) {
        $sources[] = $copy['sourcesSpeed'];
    }

    $cards = '';
    foreach ($model['pages'] as $page) {
        $line = (string) ($model['lines'][$page['path']] ?? '');
        $query = (string) ($page['query'] ?? '');
        $speed = '';
        if (is_array($page['psi'] ?? null) && ($page['psi']['score'] ?? null) !== null) {
            $speed = '<span class="speed">' . $e($copy['speedNote']) . ' ' . $e((string) $page['psi']['score']);
            if (($page['psi']['lcp'] ?? '') !== '') {
                $speed .= ' · LCP ' . $e((string) $page['psi']['lcp']);
            }
            $speed .= '</span>';
        }
        $place = $page['position'] > 0 ? reportNum((float) $page['position'], $lang, 1) : '—';
        $cards .= '<article class="card"><header><strong>' . $e((string) $page['path']) . '</strong><span>'
            . $e(reportNum((float) $page['impressions'], $lang)) . ' veces · '
            . $e(reportNum((float) $page['clicks'], $lang)) . ' clics · lugar '
            . $e($place) . '</span></header>'
            . ($query !== '' ? '<p class="query">' . $e($copy['searched']) . ' «' . $e($query) . '»</p>' : '')
            . ($speed !== '' ? '<p class="query">' . $speed . '</p>' : '')
            . '<p class="todo">' . $e($line) . '</p></article>';
    }

    $css = <<<CSS
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #f3f0e8; color: #16140f; }
    body { font-family: "Segoe UI", system-ui, sans-serif; font-size: 12.5px; line-height: 1.5; }
    .sheet { padding: 0 0 8px; }
    .mast { display: flex; justify-content: space-between; align-items: flex-end; gap: 28px; background: #12141c; color: #f6f3ec; padding: 28px 26px 22px; break-inside: avoid; page-break-inside: avoid; }
    .kicker { margin: 0 0 10px; letter-spacing: .28em; text-transform: uppercase; font-size: 10px; color: #8eb0ff; }
    h1 { margin: 0; font-size: 34px; font-weight: 560; letter-spacing: -.035em; line-height: 0.98; }
    .who { margin: 10px 0 0; font-size: 15px; color: #d9d3c7; }
    .when { margin: 0; text-align: right; color: #b7b1a6; font-size: 12px; line-height: 1.45; }
    .intro { margin: 22px 22px 0; break-inside: avoid; page-break-inside: avoid; }
    .headline { margin: 0 0 8px; padding-left: 14px; border-left: 3px solid #2f6bff; font-size: 18px; line-height: 1.35; letter-spacing: -.02em; }
    .lead { margin: 0; font-size: 14px; line-height: 1.55; max-width: 68ch; color: #3a342c; }
    .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 16px 22px 0; break-inside: avoid; page-break-inside: avoid; }
    .kpi { background: #fff; border-top: 3px solid #2f6bff; padding: 12px 12px 10px; }
    .kpis--ga .kpi { border-top-color: #12141c; }
    .kpi span { display: block; font-size: 9px; letter-spacing: .09em; text-transform: uppercase; color: #7a746a; }
    .kpi strong { display: block; margin-top: 5px; font-size: 22px; letter-spacing: -.03em; font-weight: 620; }
    .kpi em { display: block; margin-top: 3px; font-style: normal; font-size: 11px; color: #7a746a; min-height: 1em; }
    .kpi em.up { color: #0d6b45; }
    .kpi em.down { color: #9d2c2c; }
    .mix { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 22px 0; }
    .chip { background: #fff; padding: 5px 9px; font-size: 11px; color: #3a342c; }
    h2 { margin: 28px 22px 10px; font-size: 11px; letter-spacing: .16em; text-transform: uppercase; font-weight: 680; break-after: avoid; page-break-after: avoid; }
    .stack { margin: 0 22px; }
    .card, .move { background: #fff; padding: 12px 14px 13px; margin: 0 0 8px; break-inside: avoid; page-break-inside: avoid; }
    .card header { display: flex; justify-content: space-between; gap: 8px 16px; align-items: baseline; flex-wrap: wrap; }
    .card header strong { font-size: 14px; letter-spacing: -.02em; }
    .card header span, .query, .speed { color: #6f695f; font-size: 11.5px; }
    .query { margin: 4px 0 0; }
    .todo { margin: 6px 0 0; font-size: 13px; }
    .move { display: grid; grid-template-columns: 36px 1fr; gap: 8px; }
    .num { font-size: 13px; letter-spacing: .04em; color: #2f6bff; font-weight: 650; }
    .move strong { display: block; font-size: 14px; }
    .move p { margin: 3px 0 0; }
    .move em { display: block; margin-top: 3px; font-style: normal; color: #6f695f; font-size: 11.5px; }
    .close { margin: 22px 22px 0; break-inside: avoid; page-break-inside: avoid; }
    .note { margin: 0 0 18px; color: #6f695f; font-size: 11px; max-width: 72ch; }
    .sign { display: flex; gap: 16px; align-items: center; background: #12141c; color: #f6f3ec; padding: 18px 18px; }
    .mark { width: 46px; height: 46px; border: 1px solid rgba(246,243,236,.35); display: flex; align-items: center; justify-content: center; letter-spacing: .06em; font-size: 13px; color: #8eb0ff; }
    .sign__name { margin: 0; font-size: 18px; letter-spacing: -.02em; }
    .sign__role { margin: 2px 0 0; letter-spacing: .16em; text-transform: uppercase; font-size: 10px; color: #8eb0ff; }
    .sign__note { margin: 6px 0 0; color: #d9d3c7; font-size: 12px; max-width: 52ch; }
    p { orphans: 3; widows: 3; }
CSS;

    return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>'
        . $e($copy['title'] . ' — ' . $model['name']) . '</title><style>' . $css . '</style></head><body><article class="sheet">'
        . '<header class="mast"><div><p class="kicker">' . $e($copy['kicker']) . '</p><h1>' . $e($model['name']) . '</h1><p class="who">'
        . $e($copy['title']) . '</p></div><p class="when">' . $e($model['rangeLabel']) . '<br>' . $e($model['generated']) . '</p></header>'
        . '<section class="intro"><p class="headline">' . $e($model['headline']) . '</p><p class="lead">' . $e($model['lead']) . '</p></section>'
        . '<section class="kpis">' . $kpis . '</section>' . $gaHtml
        . ($cards !== '' ? '<h2>' . $e($copy['pages']) . '</h2><div class="stack">' . $cards . '</div>' : '')
        . ($moves !== '' ? '<h2>' . $e($copy['moves']) . '</h2><div class="stack">' . $moves . '</div>' : '')
        . '<section class="close"><p class="note">' . $e($copy['caveat']) . ' ' . $e(implode(' · ', $sources)) . ' · ' . $e($model['host']) . '</p>'
        . '<aside class="sign"><div class="mark">ER</div><div><p class="sign__name">Eduardo Rojas</p><p class="sign__role">Mono Studio</p>'
        . '<p class="sign__note">Preparé este informe con los datos de Google de este período. Si un número no cierra, lo vemos juntos.</p></div></aside>'
        . '</section></article></body></html>';
}

function reportFileName(string $name): string
{
    $slug = strtolower(gscAscii($name));
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '', '-');
    return 'informe-' . ($slug !== '' ? $slug : 'cliente') . '-' . gmdate('Y-m-d') . '.pdf';
}

function reportCompose(string $dataDir, array $sa, string $host, string $lang, bool $fresh): array
{
    $cacheFile = gscHostCacheFile($dataDir, $host);
    $gsc = gscReadJson($cacheFile, []);
    $period = reportPeriod28($gsc);
    $totals = is_array($period['totals'] ?? null) ? $period['totals'] : [];
    if ($totals === [] && !is_array($gsc['totals'] ?? null)) {
        throw new RuntimeException('No hay datos de Search Console para ' . $host . '. Abrí el sitio en SEO y pulsá Actualizar.');
    }
    if ($totals === []) {
        $totals = $gsc['totals'];
    }

    $prefs = reportHostConfig($dataDir, $host);
    $windows = reportWindows($gsc);
    $kw = gscReadJson(gscKeywordCacheFile($dataDir, $host), []);
    if (!isset($kw['buckets'])) {
        $kw = [];
    }

    $resolved = reportResolveProperty($dataDir, $sa, $host, (string) ($prefs['ga4Property'] ?? ''));
    $ga = null;
    $gaMeta = ['ok' => false, 'property' => $resolved['id'], 'detected' => $resolved['detected'], 'name' => $resolved['name'], 'message' => $resolved['error']];
    if ($resolved['id'] !== '' && $sa !== []) {
        try {
            $ga = reportFetchGa($dataDir, $sa, $host, $resolved['id'], $windows, $fresh);
            $gaMeta = ['ok' => true, 'property' => $resolved['id'], 'detected' => $resolved['detected'], 'name' => $resolved['name'], 'message' => ''];
        } catch (Throwable $e) {
            $ga = null;
            $gaMeta['message'] = reportPublicError($e->getMessage());
        }
    }

    $psiKey = reportSecret($dataDir, 'psi.json');
    $psi = ['pages' => [], 'home' => null];
    if ($psiKey !== '') {
        try {
            $psi = reportPsi($dataDir, $host, $psiKey, $fresh);
        } catch (Throwable) {
            $psi = ['pages' => [], 'home' => null];
        }
    }

    $pages = reportPages($gsc, $kw, $ga, $psi);
    $name = reportClientName($host);
    $copy = reportCopy($lang);
    $search = [
        'clicks' => (int) ($totals['clicks'] ?? 0),
        'impressions' => (int) ($totals['impressions'] ?? 0),
        'ctr' => (float) ($totals['ctr'] ?? 0),
        'position' => (float) ($totals['position'] ?? 0),
        'clicksDelta' => (int) ($period['delta']['clicks'] ?? $gsc['totalsDelta']['clicks'] ?? 0),
        'impressionsDelta' => (int) ($period['delta']['impressions'] ?? $gsc['totalsDelta']['impressions'] ?? 0),
        'ctrDelta' => (float) ($period['delta']['ctr'] ?? $gsc['totalsDelta']['ctr'] ?? 0),
        'positionDelta' => (float) ($period['delta']['position'] ?? $gsc['totalsDelta']['position'] ?? 0),
    ];

    $dossierPages = array_map(static fn ($page) => [
        'path' => $page['path'],
        'clicks' => $page['clicks'],
        'impressions' => $page['impressions'],
        'position' => round($page['position'], 1),
        'query' => $page['query'],
        'action' => $page['action'],
        'sessions' => $page['sessions'],
        'mobileScore' => is_array($page['psi'] ?? null) ? ($page['psi']['score'] ?? null) : null,
    ], $pages);
    $signature = md5(json_encode([$lang, $search, $dossierPages, $ga['fetchedAt'] ?? 0], JSON_UNESCAPED_UNICODE) ?: '');
    $narrative = reportNarrative($dataDir, $host, $lang, [
        'client' => $name,
        'host' => $host,
        'range' => $windows,
        'search' => $search,
        'analytics' => $ga ? [
            'people' => $ga['current']['users'],
            'visits' => $ga['current']['sessions'],
            'stayed' => reportPct((float) $ga['current']['engagementRate'], 'en'),
            'fromSearch' => $ga['organic']['sessions'],
        ] : null,
        'pages' => $dossierPages,
    ], $signature, $fresh);

    $fallback = reportFallback($copy, $name, $search, $ga, $pages, $lang);
    $lines = $fallback['pages'];
    foreach ($narrative['pages'] ?? [] as $path => $line) {
        if (is_string($line) && $line !== '') {
            $lines[$path] = $line;
        }
    }
    $moves = $narrative['moves'] ?? [];
    if (!is_array($moves) || $moves === []) {
        $moves = $fallback['moves'];
    }
    $headline = (string) ($narrative['headline'] ?? '');
    $lead = (string) ($narrative['lead'] ?? '');
    if ($headline === '' || $lead === '') {
        $headline = $fallback['headline'];
        $lead = $fallback['lead'];
    }

    $generatedAt = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
    $model = [
        'lang' => $lang,
        'copy' => $copy,
        'name' => $name,
        'host' => $host,
        'rangeLabel' => reportDateLabel($windows['start'], $lang) . ' — ' . reportDateLabel($windows['end'], $lang),
        'generated' => $copy['generated'] . ' ' . reportDateLabel($generatedAt->format('Y-m-d'), $lang),
        'headline' => $headline,
        'lead' => $lead,
        'search' => $search,
        'deltas' => [
            'clicks' => reportDelta((float) $search['clicksDelta'], 'count', $lang, $copy),
            'impressions' => reportDelta((float) $search['impressionsDelta'], 'count', $lang, $copy),
            'ctr' => reportDelta((float) $search['ctrDelta'], 'pp', $lang, $copy),
            'position' => reportDelta((float) $search['positionDelta'], 'places', $lang, $copy),
        ],
        'ga' => $ga ? ($ga + ['ok' => true]) : null,
        'pages' => $pages,
        'lines' => $lines,
        'moves' => $moves,
        'hasSpeed' => $psi['home'] !== null,
    ];

    return [
        'html' => reportRender($model),
        'fileName' => reportFileName($name),
        'lang' => $lang,
        'clientName' => $name,
        'ga' => $gaMeta,
        'psi' => ['ok' => $psi['home'] !== null, 'configured' => $psiKey !== ''],
        'narrative' => (string) ($narrative['source'] ?? 'template'),
        'narrativeError' => (string) ($narrative['error'] ?? ''),
    ];
}

function reportPdf(string $apiKey, string $html, string $fileName): string
{
    $res = gscHttp('POST', 'https://v2.api2pdf.com/chrome/pdf/html', [
        'html' => $html,
        'inline' => false,
        'fileName' => $fileName,
        'options' => [
            'printBackground' => true,
            'landscape' => false,
            'displayHeaderFooter' => true,
            'headerTemplate' => '<span></span>',
            'footerTemplate' => '<div style="font-size:9px;width:100%;padding:0 18px;color:#7a746a;font-family:Arial,sans-serif;"><span style="float:left">Eduardo Rojas · Mono Studio</span><span style="float:right">Página <span class="pageNumber"></span> de <span class="totalPages"></span></span></div>',
            'marginTop' => '0.48in',
            'marginBottom' => '0.66in',
            'marginLeft' => '0.48in',
            'marginRight' => '0.48in',
        ],
    ], ['Authorization: ' . $apiKey], 55);
    $url = '';
    if (is_array($res['data'] ?? null)) {
        $url = (string) ($res['data']['FileUrl'] ?? $res['data']['fileUrl'] ?? '');
    }
    if (!$res['ok'] || $url === '') {
        throw new RuntimeException(reportApiMessage($res, 'api2pdf no devolvió el PDF'));
    }
    return $url;
}

$isEntry = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__;
if ($isEntry) {
    $dataDir = dirname(__DIR__) . '/data';
    $sa = gscLoadServiceAccount($dataDir, $dataDir . '/gsc-service-account.json');
    $action = (string) ($_GET['action'] ?? '');

    try {
        if ($action === 'status') {
            gscJson([
                'pdf' => reportSecret($dataDir, 'api2pdf.json') !== '',
                'psi' => reportSecret($dataDir, 'psi.json') !== '',
                'email' => (string) ($sa['client_email'] ?? ''),
            ]);
        }

        if ($action === 'properties') {
            $fresh = (string) ($_GET['fresh'] ?? '') === '1';
            $dir = reportGaDirectory($dataDir, $sa, $fresh);
            gscJson([
                'ok' => ($dir['error'] ?? '') === '',
                'email' => (string) ($dir['email'] ?? ''),
                'error' => (string) ($dir['error'] ?? ''),
                'properties' => $dir['properties'] ?? [],
            ]);
        }

        if ($action === 'prefs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($input)) {
                gscJson(['error' => 'JSON inválido'], 400);
            }
            $host = gscSanitizeHost((string) ($input['host'] ?? ''));
            if ($host === '') {
                gscJson(['error' => 'Indica el dominio'], 400);
            }
            gscJson(['ok' => true, 'host' => $host] + reportSavePrefs($dataDir, $host, $input));
        }

        if ($action === 'build' || $action === 'pdf') {
            @set_time_limit(90);
            $input = $action === 'pdf' ? json_decode((string) file_get_contents('php://input'), true) : $_GET;
            $input = is_array($input) ? $input : [];
            $host = gscSanitizeHost((string) ($input['host'] ?? ''));
            if ($host === '') {
                gscJson(['error' => 'Indica el dominio del cliente'], 400);
            }
            $lang = 'es';
            $fresh = in_array((string) ($input['fresh'] ?? ''), ['1', 'true'], true);
            $built = reportCompose($dataDir, $sa, $host, $lang, $fresh);
            if ($action === 'build') {
                gscJson(['ok' => true] + $built);
            }
            $apiKey = reportSecret($dataDir, 'api2pdf.json');
            if ($apiKey === '') {
                gscJson(['error' => 'Falta la clave de api2pdf. Copiá ers/data/api2pdf.example.json a api2pdf.json.'], 400);
            }
            $url = reportPdf($apiKey, $built['html'], $built['fileName']);
            gscJson(['ok' => true, 'url' => $url, 'fileName' => $built['fileName']]);
        }

        gscJson(['error' => 'Acción desconocida'], 404);
    } catch (Throwable $e) {
        gscJson(['error' => reportPublicError($e->getMessage())], 500);
    }
}
