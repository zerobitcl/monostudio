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
require_once __DIR__ . '/dossier.php';
require_once __DIR__ . '/findings.php';
require_once __DIR__ . '/report-view.php';

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
            'conversions' => 'Conversiones',
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
            'times' => 'veces',
            'clicksShort' => 'clics',
            'placeShort' => 'lugar',
            'signNote' => 'Preparé este informe con los datos de Google de este período. Si un número no cierra, lo vemos juntos.',
            'pageOf' => 'Página',
            'of' => 'de',
            'headline' => 'En los últimos 28 días, %s recibió %s clics desde Google%s.',
            'deltaUp' => ', %s más que el mes anterior',
            'deltaDown' => ', %s menos que el mes anterior',
            'leadSearch' => 'Apareció %s veces en resultados y quedó, en promedio, en el lugar %s.',
            'leadGa' => 'En el sitio hubo %s personas y %s visitas. %s llegaron desde el buscador.',
            'leadConv' => 'Se registraron %s conversiones en el período.',
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
            'conversions' => 'Conversions',
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
            'times' => 'times',
            'clicksShort' => 'clicks',
            'placeShort' => 'place',
            'signNote' => 'I prepared this report with Google data for this period. If a number looks off, we can review it together.',
            'pageOf' => 'Page',
            'of' => 'of',
            'headline' => 'In the last 28 days, %s got %s clicks from Google%s.',
            'deltaUp' => ', %s more than the previous month',
            'deltaDown' => ', %s fewer than the previous month',
            'leadSearch' => 'The site showed up %s times and sat at an average place of %s.',
            'leadGa' => 'The site had %s people and %s visits. %s came from search.',
            'leadConv' => 'There were %s conversions in the period.',
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
            'conversions' => 'Conversões',
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
            'times' => 'vezes',
            'clicksShort' => 'cliques',
            'placeShort' => 'posição',
            'signNote' => 'Preparei este relatório com os dados do Google deste período. Se algum número não fechar, vemos juntos.',
            'pageOf' => 'Página',
            'of' => 'de',
            'headline' => 'Nos últimos 28 dias, %s recebeu %s cliques no Google%s.',
            'deltaUp' => ', %s a mais que o mês anterior',
            'deltaDown' => ', %s a menos que o mês anterior',
            'leadSearch' => 'Apareceu %s vezes nos resultados e ficou, em média, na posição %s.',
            'leadGa' => 'O site teve %s pessoas e %s visitas. %s chegaram pela pesquisa.',
            'leadConv' => 'Foram registradas %s conversões no período.',
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
            'leak' => ['Arreglar la página que recibe visitas pero no convierte', 'Google ya trae gente con intención de comprar o cotizar; en el sitio se van sin contactar. Revisar oferta, WhatsApp y velocidad en celular.'],
            'gap' => ['Meter la búsqueda clave en el título y los encabezados', 'La gente ya busca esto y la página casi aparece arriba, pero esa frase no está en el título ni en los encabezados.'],
            'cannibal' => ['Dejar de competir consigo mismos', 'Dos páginas del mismo sitio pelean por la misma búsqueda y se restan fuerza. Unificamos o marcamos cuál es la principal.'],
            'push' => ['Subir esta página a los primeros lugares', 'Ya aparece cerca de la primera página. Reforzamos el contenido para que suba y traiga más clics.'],
            'title' => ['Reescribir cómo se ve en Google', 'Se muestra seguido y poca gente hace clic. El título que ve el usuario no está convenciendo.'],
            'growing' => ['Ampliar lo que ya está creciendo', 'Esta página está ganando clics. La ampliamos ahora, antes de que se estanque.'],
            'hold' => ['Mantener y medir', 'No es la prioridad de este mes. La dejamos estable y seguimos el número.'],
        ],
        'en' => [
            'index' => ['Get this page into Google’s index', 'It is not in the index today. Nothing else can rank until it is.'],
            'speed' => ['Make this page faster on a phone', 'It loads slowly on mobile. That holds the ranking back, and the people who already clicked.'],
            'falling' => ['Win back the clicks that dropped', 'It fell against the previous month. We will check that the page still answers what people search.'],
            'leak' => ['Fix the page that gets visits but no contacts', 'Search already sends people with buying intent; they leave without converting. Check the offer, WhatsApp and mobile speed.'],
            'gap' => ['Put the key search into the title and headings', 'People already search this and the page is near the top, but that phrase is missing from the title and headings.'],
            'cannibal' => ['Stop competing with yourself', 'Two pages fight for the same search and split the signal. Merge them or pick a primary URL.'],
            'push' => ['Move this page into the top places', 'It already shows near the first page. We will strengthen the content so it climbs and earns more clicks.'],
            'title' => ['Rewrite how it looks in Google', 'It is shown often and few people click. The title they see is not convincing.'],
            'growing' => ['Expand what is already growing', 'This page is gaining clicks. We extend it now, before it stalls.'],
            'hold' => ['Keep it and measure', 'Not the priority this month. We leave it stable and watch the number.'],
        ],
        'pt' => [
            'index' => ['Fazer o Google indexar esta página', 'Hoje ela não está no índice. Sem isso, o resto do trabalho não tem onde ranquear.'],
            'speed' => ['Acelerar a página no celular', 'No telefone ela carrega devagar. Isso segura a posição e também quem já clicou.'],
            'falling' => ['Recuperar os cliques que caíram', 'Caiu em relação ao mês anterior. Vamos ver se o conteúdo ainda responde ao que as pessoas buscam.'],
            'leak' => ['Corrigir a página que recebe visitas e não converte', 'O Google já traz gente com intenção de comprar; no site elas saem sem contato. Revisar oferta, WhatsApp e velocidade no celular.'],
            'gap' => ['Colocar a busca-chave no título e nos cabeçalhos', 'As pessoas já buscam isso e a página quase aparece no topo, mas a frase não está no título nem nos cabeçalhos.'],
            'cannibal' => ['Parar de competir consigo mesmo', 'Duas páginas do mesmo site brigam pela mesma busca. Unificamos ou marcamos a principal.'],
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
    return dossierGaDirectory($dataDir, $sa, $fresh);
}

function reportResolveProperty(string $dataDir, array $sa, string $host, string $configured): array
{
    return dossierResolveProperty($dataDir, $sa, $host, $configured);
}

function reportFetchGa(string $dataDir, array $sa, string $host, string $propertyId, array $windows, bool $fresh): array
{
    return dossierFetchGa($dataDir, $sa, $host, $propertyId, $windows, $fresh);
}

function reportPsi(string $dataDir, string $host, string $apiKey, bool $fresh, array $gsc = []): array
{
    return dossierPsi($dataDir, $host, $apiKey, $fresh, $gsc);
}

function reportEnsureKeywords(string $dataDir, array $sa, string $host, array $gsc): array
{
    return dossierEnsureKeywords($dataDir, $sa, $host, $gsc);
}
function reportPlain(string $value, int $max): string
{
    $value = trim(preg_replace('/\s+/u', ' ', str_replace(['**', '`', '#'], '', $value)) ?? '');
    return ersClip($value, $max);
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

function reportFileName(string $name): string
{
    $slug = strtolower(gscAscii($name));
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '', '-');
    return 'informe-' . ($slug !== '' ? $slug : 'cliente') . '-' . gmdate('Y-m-d') . '.pdf';
}

function reportHtmlCacheFile(string $dataDir, string $host): string
{
    return $dataDir . '/report-html-' . md5($host) . '.json';
}

function reportCachedHtml(string $dataDir, string $host, string $lang): ?array
{
    $cached = gscReadJson(reportHtmlCacheFile($dataDir, $host), []);
    if (
        ($cached['version'] ?? '') === REPORT_HTML_VERSION
        && ($cached['lang'] ?? '') === $lang
        && is_string($cached['html'] ?? null)
        && $cached['html'] !== ''
        && is_string($cached['fileName'] ?? null)
        && $cached['fileName'] !== ''
    ) {
        return [
            'html' => (string) $cached['html'],
            'fileName' => (string) $cached['fileName'],
            'signature' => (string) ($cached['signature'] ?? ''),
        ];
    }
    return null;
}

function reportPdf(string $apiKey, string $html, string $fileName, string $lang = 'es'): string
{
    $copy = reportCopy($lang);
    $footer = '<div style="font-size:9px;width:100%;padding:0 18px;color:#7a746a;font-family:Arial,sans-serif;">'
        . '<span style="float:left">Eduardo Rojas · Mono Studio</span>'
        . '<span style="float:right">' . htmlspecialchars($copy['pageOf'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
        . ' <span class="pageNumber"></span> ' . htmlspecialchars($copy['of'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
        . ' <span class="totalPages"></span></span></div>';
    $res = gscHttp('POST', 'https://v2.api2pdf.com/chrome/pdf/html', [
        'html' => $html,
        'inline' => false,
        'fileName' => $fileName,
        'options' => [
            'printBackground' => true,
            'landscape' => false,
            'displayHeaderFooter' => true,
            'headerTemplate' => '<span></span>',
            'footerTemplate' => $footer,
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
            $prefs = reportHostConfig($dataDir, $host);
            $lang = gscReportLang((string) ($input['lang'] ?? $prefs['reportLang'] ?? 'es'));
            $fresh = in_array((string) ($input['fresh'] ?? ''), ['1', 'true'], true);

            if ($action === 'pdf' && !$fresh) {
                $cachedHtml = reportCachedHtml($dataDir, $host, $lang);
                if ($cachedHtml !== null) {
                    $apiKey = reportSecret($dataDir, 'api2pdf.json');
                    if ($apiKey === '') {
                        gscJson(['error' => 'Falta la clave de api2pdf. Copiá ers/data/api2pdf.example.json a api2pdf.json.'], 400);
                    }
                    $url = reportPdf($apiKey, $cachedHtml['html'], $cachedHtml['fileName'], $lang);
                    gscJson(['ok' => true, 'url' => $url, 'fileName' => $cachedHtml['fileName'], 'fromCache' => true]);
                }
            }

            $built = reportCompose($dataDir, $sa, $host, $lang, $fresh);
            if ($action === 'build') {
                gscJson(['ok' => true] + $built);
            }
            $apiKey = reportSecret($dataDir, 'api2pdf.json');
            if ($apiKey === '') {
                gscJson(['error' => 'Falta la clave de api2pdf. Copiá ers/data/api2pdf.example.json a api2pdf.json.'], 400);
            }
            $url = reportPdf($apiKey, $built['html'], $built['fileName'], $lang);
            gscJson(['ok' => true, 'url' => $url, 'fileName' => $built['fileName']]);
        }

        gscJson(['error' => 'Acción desconocida'], 404);
    } catch (Throwable $e) {
        gscJson(['error' => reportPublicError($e->getMessage())], 500);
    }
}
