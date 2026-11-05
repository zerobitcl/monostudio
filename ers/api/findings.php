<?php
/**
 * Catálogo de hallazgos. Cada detector solo lee el dossier y devuelve
 * evidencia: ni el prompt ni el informe calculan umbrales por su cuenta.
 */

declare(strict_types=1);

require_once __DIR__ . '/dossier.php';

function findingsSay(
    string $esTitle,
    string $esLine,
    string $enTitle,
    string $enLine,
    string $ptTitle,
    string $ptLine
): array {
    return [
        'es' => ['title' => $esTitle, 'line' => $esLine],
        'en' => ['title' => $enTitle, 'line' => $enLine],
        'pt' => ['title' => $ptTitle, 'line' => $ptLine],
    ];
}

function findingsMake(
    string $kind,
    string $category,
    int $severity,
    int $impact,
    array $figure,
    string $path,
    string $url,
    string $query,
    array $evidence,
    array $lines,
    string $tech,
    string $audience = 'both'
): array {
    $id = $kind . '|' . ($path !== '' ? $path : 'site') . '|' . substr(gscAscii($query), 0, 48);
    return [
        'id' => $id,
        'kind' => $kind,
        'category' => $category,
        'severity' => $severity,
        'impact' => max(0, $impact),
        'figure' => $figure,
        'evidence' => $evidence,
        'path' => $path !== '' ? $path : '/',
        'url' => $url,
        'query' => $query,
        'file' => '',
        'lines' => $lines,
        'techText' => $tech,
        'audience' => $audience,
    ];
}

function findingsFigure(int $n, string $unit, string $dir): array
{
    return ['n' => max(0, $n), 'unit' => $unit, 'dir' => $dir];
}

function findingsPageDecay(array $page): bool
{
    $prev = (int) ($page['prevClicks'] ?? 0);
    $delta = (int) ($page['deltaClicks'] ?? 0);
    $dPos = (float) ($page['deltaPosition'] ?? 0);
    if ($prev >= 10 && $delta <= -1 && ($delta / $prev) <= -0.3) {
        return true;
    }
    // deltaPosition negativo = empeoró.
    if ($prev >= 5 && $dPos <= -1.5 && $delta < 0) {
        return true;
    }
    return $delta <= -5;
}

function findingsIsTransactional(string $query): bool
{
    $q = ' ' . gscAscii($query) . ' ';
    foreach ([
        'precio', 'precios', 'cotiz', 'presupuesto', 'contratar', 'comprar', 'compra',
        'urgencia', 'urgente', 'whatsapp', 'telefono', 'fono', 'llamar', 'contacto',
        'servicio', 'servicios', 'empresa', 'mejor', 'barato', 'economico',
        'cerca', 'cerca de', 'a domicilio',
    ] as $needle) {
        if (str_contains($q, $needle)) {
            return true;
        }
    }
    return (bool) preg_match('/\ben\s+[a-z]{4,}\b/u', $q);
}

function findingsDisplay(string $display): ?float
{
    if (!preg_match('/\d+(?:[.,]\d+)?/', $display, $m)) {
        return null;
    }
    return (float) str_replace(',', '.', $m[0]);
}

function findingsSkip(string $path): bool
{
    return (bool) preg_match('#/(legal|privacidad|privacy|cookies|terminos|terms|login|cart|carrito|gracias|thanks|thank-you)(/|$)#', $path);
}

function findingsByPath(array $dossier, string $path): ?array
{
    foreach ($dossier['pages'] ?? [] as $page) {
        if (($page['path'] ?? '') === $path) {
            return $page;
        }
    }
    return null;
}

function findingsDom(array $dossier, string $path): ?array
{
    $row = $dossier['dom'][$path] ?? null;
    return is_array($row) && !empty($row['ok']) ? $row : null;
}

function findingsPsiByPath(array $dossier): array
{
    $out = [];
    foreach ($dossier['psi']['pages'] ?? [] as $url => $row) {
        if (is_array($row)) {
            $out[dossierNormPath((string) $url)] = $row;
        }
    }
    return $out;
}

function findingsLandings(array $dossier): array
{
    $out = [];
    foreach ($dossier['ga']['landings'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $path = dossierNormPath((string) ($row['path'] ?? '/'));
        if (!isset($out[$path])) {
            $out[$path] = $row;
        }
    }
    return $out;
}

/**
 * Login, gracias, admin, carrito y similares pueden ir en noindex a propósito.
 * Una página pública (sobre nosotros, equipo, servicios) no.
 */
function findingsIsPrivatePath(string $path): bool
{
    $slug = strtolower(trim(dossierNormPath($path), '/'));
    if ($slug === '') {
        return false;
    }
    $first = explode('/', $slug)[0];
    $exact = [
        'wp-admin', 'wp-login.php', 'admin', 'login', 'signin', 'sign-in', 'registro', 'register',
        'cuenta', 'account', 'mi-cuenta', 'my-account', 'carrito', 'cart', 'checkout', 'pago',
        'gracias', 'thank-you', 'thanks', 'confirmacion', 'preview', 'borrador', 'draft',
        'private', 'privado', 'search', 'buscar', 'busqueda', 'feed', 'xmlrpc.php', '404', 'cdn-cgi',
    ];
    if (in_array($first, $exact, true) || in_array($slug, $exact, true)) {
        return true;
    }
    foreach (['gracias-', 'thank-', 'preview-', 'draft-', 'private-', 'wp-', 'account-', 'checkout-'] as $prefix) {
        if (str_starts_with($first, $prefix)) {
            return true;
        }
    }
    return false;
}

function findingsPublicName(string $path): string
{
    $slug = trim(dossierNormPath($path), '/');
    if ($slug === '') {
        return 'Inicio';
    }
    $last = basename(str_replace('\\', '/', $slug));
    return ucwords(str_replace(['-', '_'], ' ', $last));
}

function findingsContactRate(array $dossier): float
{
    $ga = $dossier['ga'] ?? null;
    if (!is_array($ga)) {
        return 0.04;
    }
    $sessions = (int) ($ga['current']['sessions'] ?? 0);
    $events = (int) ($ga['keyEvents'] ?? 0);
    if ($sessions < 20 || $events < 1) {
        return 0.04;
    }
    return max(0.01, min(0.25, $events / $sessions));
}

/** @return list<array> */
function findingsIndex(array $dossier): array
{
    $out = [];
    $blocked = 0;
    $noindex = 0;
    foreach ($dossier['pages'] ?? [] as $page) {
        $path = (string) ($page['path'] ?? '/');
        if (findingsIsPrivatePath($path)) {
            continue;
        }
        $title = (string) ($page['title'] ?? $path);
        $url = (string) ($page['url'] ?? '');
        if (($page['error'] ?? '') !== '') {
            if ($blocked >= 5) {
                continue;
            }
            $blocked++;
            $out[] = findingsMake(
                'blocked',
                'index',
                3,
                max(10, (int) $page['impressions']),
                findingsFigure(1, 'pages', 'risk'),
                $path,
                $url,
                '',
                ['error' => $page['error']],
                findingsSay(
                    $title . ' no se puede revisar',
                    'Google no deja leer esta página. Mientras siga así, no puede traer visitas.',
                    $title . ' cannot be checked',
                    'Google cannot read this page, so it cannot bring visits.',
                    $title . ' não pode ser lida',
                    'O Google não consegue ler esta página, então ela não traz visitas.'
                ),
                'Search Console rechazó ' . $path . '. Revisar permisos, robots y que la URL responda 200.'
            );
            continue;
        }
        if (($page['indexTone'] ?? '') === 'issue') {
            if ($noindex >= 8) {
                continue;
            }
            $noindex++;
            $impr = (int) $page['impressions'];
            $out[] = findingsMake(
                'noindex',
                'index',
                3,
                max(10, $impr),
                findingsFigure(1, 'pages', 'risk'),
                $path,
                $url,
                (string) ($page['query'] ?? ''),
                ['impressions' => $impr, 'reason' => $page['indexReason'] ?? ''],
                findingsSay(
                    $title . ' no aparece en Google',
                    'La página existe, pero Google no la muestra. Sin eso no hay visitas ni contactos.',
                    $title . ' is missing from Google',
                    'The page exists, but Google is not showing it. Nothing else can bring visits.',
                    $title . ' não aparece no Google',
                    'A página existe, mas o Google não a mostra. Sem isso não há visitas.'
                ),
                'No indexada: ' . $path . '. ' . (string) ($page['indexReason'] ?? 'Pedir indexación y revisar canonical/noindex.')
            );
        }
    }

    $robots = 0;
    $canonical = 0;
    foreach ($dossier['pages'] ?? [] as $page) {
        $path = (string) ($page['path'] ?? '/');
        if (findingsIsPrivatePath($path)) {
            continue;
        }
        $dom = findingsDom($dossier, $path);
        if ($dom === null || (int) $page['impressions'] < 10) {
            continue;
        }
        $robotsMeta = strtolower((string) ($dom['robots'] ?? ''));
        if ($robots < 4 && str_contains($robotsMeta, 'noindex') && ($page['indexTone'] ?? '') !== 'issue') {
            $robots++;
            $out[] = findingsMake(
                'robotsMeta',
                'index',
                3,
                max(10, (int) $page['impressions']),
                findingsFigure((int) $page['impressions'], 'impressions', 'risk'),
                $path,
                (string) $page['url'],
                '',
                ['robots' => $dom['robots'], 'impressions' => (int) $page['impressions']],
                findingsSay(
                    'Una página visible se esconde de Google',
                    'La página recibe búsquedas, pero le dice a Google que no la indexe.',
                    'A visible page tells Google to skip it',
                    'The page already gets searches, but its code asks Google not to index it.',
                    'Uma página visível pede para não ser indexada',
                    'A página recebe buscas, mas pede ao Google para não indexá-la.'
                ),
                'Quitar noindex del meta robots de ' . $path . ' (hoy: ' . $dom['robots'] . '). Tiene ' . (int) $page['impressions'] . ' impresiones.'
            );
        }
        $canon = trim((string) ($dom['canonical'] ?? ''));
        if ($canonical < 4 && $canon !== '') {
            $canonPath = dossierNormPath($canon);
            if ($canonPath !== '/' && $canonPath !== $path && $canonPath !== '') {
                $canonical++;
                $out[] = findingsMake(
                    'canonical',
                    'index',
                    2,
                    max(5, (int) $page['impressions']),
                    findingsFigure((int) $page['impressions'], 'impressions', 'risk'),
                    $path,
                    (string) $page['url'],
                    '',
                    ['canonical' => $canon],
                    findingsSay(
                        'Google está mirando otra página',
                        'Esta dirección le dice a Google que la importante es otra. Se reparte la fuerza.',
                        'Google is being sent to another page',
                        'This address tells Google the important one is a different URL.',
                        'O Google está sendo enviado a outra página',
                        'Este endereço diz ao Google que a página importante é outra.'
                    ),
                    'Canonical de ' . $path . ' apunta a ' . $canonPath . '. Unificar o corregir el href.'
                );
            }
        }
    }

    $diag = is_array($dossier['diagnostics'] ?? null) ? $dossier['diagnostics'] : [];
    $items = $diag['sitemapItems'] ?? null;
    $hasSitemap = trim((string) ($diag['sitemapUrl'] ?? '')) !== ''
        || (is_array($items) && $items !== []);
    $sitemapError = trim((string) ($diag['sitemapError'] ?? ''));
    if (!$hasSitemap && $sitemapError !== '' && (int) ($dossier['inventory']['total'] ?? 0) >= 3) {
        $out[] = findingsMake(
            'sitemap',
            'index',
            2,
            15,
            findingsFigure((int) ($dossier['inventory']['total'] ?? 0), 'pages', 'risk'),
            '/',
            'https://' . ($dossier['host'] ?? '') . '/',
            '',
            ['error' => $sitemapError],
            findingsSay(
                'Google no tiene el mapa del sitio',
                'Sin el mapa, las páginas nuevas tardan más en aparecer en el buscador.',
                'Google does not have the site map',
                'Without it, new pages take longer to show up in search.',
                'O Google não tem o mapa do site',
                'Sem o mapa, páginas novas demoram mais para aparecer na busca.'
            ),
            'Sitemap no enviado o con error: ' . $sitemapError . '. Publicar /sitemap.xml y enviarlo en Search Console.'
        );
    }
    return $out;
}

/** @return list<array> */
function findingsDemand(array $dossier): array
{
    $out = [];
    $falling = 0;
    foreach ($dossier['pages'] ?? [] as $page) {
        if ($falling >= 6 || ($page['error'] ?? '') !== '' || !findingsPageDecay($page)) {
            continue;
        }
        $falling++;
        $lost = abs((int) $page['deltaClicks']);
        $path = (string) $page['path'];
        $out[] = findingsMake(
            'falling',
            'demand',
            $lost >= 15 || ((int) $page['prevClicks'] >= 10 && $lost / max(1, (int) $page['prevClicks']) >= 0.4) ? 3 : 2,
            $lost,
            findingsFigure($lost, 'clicks', 'loss'),
            $path,
            (string) $page['url'],
            (string) ($page['query'] ?? ''),
            ['deltaClicks' => (int) $page['deltaClicks'], 'prevClicks' => (int) $page['prevClicks']],
            findingsSay(
                (string) $page['title'] . ' perdió visitas',
                'Esta página trajo menos gente que el mes anterior. Conviene revisarla antes de que siga bajando.',
                (string) $page['title'] . ' lost visits',
                'This page brought fewer people than last month. Worth fixing before it keeps dropping.',
                (string) $page['title'] . ' perdeu visitas',
                'Esta página trouxe menos gente que no mês anterior. Vale revisar antes que caia mais.'
            ),
            'Caída en ' . $path . ': ' . (int) $page['deltaClicks'] . ' clics vs el período anterior (antes ' . (int) $page['prevClicks'] . ').'
        );
    }

    $kw = $dossier['keywords'] ?? [];
    $buckets = is_array($kw['buckets'] ?? null) ? $kw['buckets'] : [];
    $striking = 0;
    foreach ($buckets['striking'] ?? [] as $row) {
        if ($striking >= 5 || !is_array($row) || !empty($row['brand'])) {
            continue;
        }
        $gain = (int) ($row['gain'] ?? 0);
        $pos = (float) ($row['position'] ?? 0);
        if ($gain < 1 || $pos < 4 || $pos > 20) {
            continue;
        }
        $striking++;
        $query = (string) ($row['q'] ?? '');
        $path = dossierNormPath((string) ($row['page'] ?? '/'));
        $page = findingsByPath($dossier, $path);
        $out[] = findingsMake(
            'striking',
            'demand',
            2,
            $gain,
            findingsFigure($gain, 'clicks', 'gain'),
            $path,
            (string) ($page['url'] ?? ''),
            $query,
            ['position' => $pos, 'impressions' => (int) ($row['impressions'] ?? 0), 'gain' => $gain],
            findingsSay(
                '«' . $query . '» está a un paso',
                'La gente ya busca esto y el sitio aparece cerca. Reforzar esa página puede subirla.',
                '“' . $query . '” is one step away',
                'People already search this and the site shows up close. Strengthening the page can lift it.',
                '«' . $query . '» está perto',
                'As pessoas já buscam isso e o site aparece perto. Reforçar a página pode subir.'
            ),
            'Striking distance: «' . $query . '» en ' . $path . ', posición ' . round($pos, 1) . ', +' . $gain . ' clics estimados al top.'
        );
    }

    $low = 0;
    foreach ($buckets['lowCtr'] ?? [] as $row) {
        if ($low >= 4 || !is_array($row) || !empty($row['brand'])) {
            continue;
        }
        $gain = (int) ($row['ctrGain'] ?? 0);
        if ($gain < 1) {
            continue;
        }
        $low++;
        $query = (string) ($row['q'] ?? '');
        $path = dossierNormPath((string) ($row['page'] ?? '/'));
        $page = findingsByPath($dossier, $path);
        $out[] = findingsMake(
            'lowctr',
            'demand',
            2,
            $gain,
            findingsFigure($gain, 'clicks', 'gain'),
            $path,
            (string) ($page['url'] ?? ''),
            $query,
            ['ctr' => (float) ($row['ctr'] ?? 0), 'position' => (float) ($row['position'] ?? 0), 'gain' => $gain],
            findingsSay(
                'El título no convence en «' . $query . '»',
                'Aparecen en Google, pero poca gente entra. El texto del resultado se puede reescribir.',
                'The title is not earning the click for “' . $query . '”',
                'The site shows up, but few people click. The result text can be rewritten.',
                'O título não convence em «' . $query . '»',
                'Aparecem no Google, mas pouca gente entra. O texto do resultado pode ser reescrito.'
            ),
            'CTR bajo en «' . $query . '» (' . $path . '), posición ' . round((float) ($row['position'] ?? 0), 1) . '. Reescribir title y meta. +' . $gain . ' clics si alcanza el CTR esperado.'
        );
    }

    // Sin keywords, el CTR bajo se lee de la página (termómetro / curva esperada).
    if ($low === 0) {
        foreach ($dossier['pages'] ?? [] as $page) {
            if ($low >= 4 || ($page['error'] ?? '') !== '') {
                continue;
            }
            $pos = (float) $page['position'];
            $impr = (int) $page['impressions'];
            $ctr = (float) $page['ctr'];
            if ($pos <= 0 || $pos > 8 || $impr < 80 || $ctr >= gscExpectedCtr($pos) * 0.6) {
                continue;
            }
            $gain = (int) max(1, round($impr * (gscExpectedCtr($pos) - $ctr)));
            $low++;
            $out[] = findingsMake(
                'lowctr',
                'demand',
                2,
                $gain,
                findingsFigure($gain, 'clicks', 'gain'),
                (string) $page['path'],
                (string) $page['url'],
                (string) ($page['query'] ?? ''),
                ['ctr' => $ctr, 'position' => $pos, 'impressions' => $impr],
                findingsSay(
                    'Se muestran y casi no entran',
                    'Esta página aparece seguido en Google y poca gente hace clic. Hay que cambiar cómo se ve el resultado.',
                    'Shown often, clicked rarely',
                    'This page appears often and few people click. The result text needs a rewrite.',
                    'Aparece muito e quase não clicam',
                    'Esta página aparece bastante e pouca gente clica. Vale mudar o texto do resultado.'
                ),
                'CTR de ' . $page['path'] . ' por debajo de lo esperado en posición ' . round($pos, 1) . ' (' . $impr . ' imp).'
            );
        }
    }

    $cannibal = 0;
    foreach ($buckets['cannibal'] ?? [] as $row) {
        if ($cannibal >= 3 || !is_array($row)) {
            continue;
        }
        $pages = is_array($row['pages'] ?? null) ? $row['pages'] : [];
        if (count($pages) < 2) {
            continue;
        }
        $cannibal++;
        $query = (string) ($row['q'] ?? '');
        $paths = array_map(static fn ($p) => dossierNormPath((string) ($p['url'] ?? '')), array_slice($pages, 0, 2));
        $impr = (int) ($row['impressions'] ?? 0);
        $out[] = findingsMake(
            'cannibal',
            'demand',
            2,
            max(3, (int) round($impr * 0.02)),
            findingsFigure(count($pages), 'pages', 'risk'),
            $paths[0] ?? '/',
            (string) ($pages[0]['url'] ?? ''),
            $query,
            ['impressions' => $impr, 'pages' => $paths],
            findingsSay(
                'Dos páginas pelean por «' . $query . '»',
                'El sitio compite consigo mismo en esa búsqueda. Conviene dejar una sola página fuerte.',
                'Two pages fight for “' . $query . '”',
                'The site competes with itself on that search. One strong page should own it.',
                'Duas páginas disputam «' . $query . '»',
                'O site compete consigo mesmo nessa busca. Vale deixar uma página só.'
            ),
            'Canibalización «' . $query . '»: ' . implode(' y ', $paths) . ' (' . $impr . ' impresiones).'
        );
    }

    $rising = 0;
    foreach ($buckets['rising'] ?? [] as $row) {
        if ($rising >= 3 || !is_array($row)) {
            continue;
        }
        $dImpr = (int) ($row['dImpr'] ?? 0);
        if ($dImpr < 20) {
            continue;
        }
        $rising++;
        $query = (string) ($row['q'] ?? '');
        $path = dossierNormPath((string) ($row['page'] ?? '/'));
        $page = findingsByPath($dossier, $path);
        $out[] = findingsMake(
            'rising',
            'demand',
            1,
            max(1, (int) round($dImpr / 20)),
            findingsFigure($dImpr, 'impressions', 'gain'),
            $path,
            (string) ($page['url'] ?? ''),
            $query,
            ['dImpr' => $dImpr],
            findingsSay(
                '«' . $query . '» está creciendo',
                'Cada vez más gente busca esto y llega al sitio. Es buen momento para ampliar la página.',
                '“' . $query . '” is growing',
                'More people are searching this and reaching the site. A good moment to expand the page.',
                '«' . $query . '» está crescendo',
                'Cada vez mais gente busca isso. É um bom momento para ampliar a página.'
            ),
            'Query en alza «' . $query . '» en ' . $path . ', +' . $dImpr . ' impresiones.'
        );
    }

    $summary = is_array($kw['summary'] ?? null) ? $kw['summary'] : [];
    $brandShare = (float) ($summary['brandShare'] ?? 0);
    $brandClicks = (int) ($summary['clicks'] ?? 0);
    if ($brandShare >= 0.7 && $brandClicks >= 30) {
        $extra = (int) round($brandClicks * ($brandShare - 0.5));
        $out[] = findingsMake(
            'brand',
            'demand',
            2,
            max(5, $extra),
            findingsFigure((int) round($brandShare * 100), 'percent', 'risk'),
            '/',
            'https://' . ($dossier['host'] ?? '') . '/',
            '',
            ['brandShare' => $brandShare, 'clicks' => $brandClicks],
            findingsSay(
                'Casi solo los encuentran por el nombre',
                'La mayoría de los clics viene de quien ya los conoce. Falta aparecer en búsquedas nuevas.',
                'Almost only found by name',
                'Most clicks come from people who already know them. New searches are still missing.',
                'Quase só encontram pelo nome',
                'A maioria dos cliques vem de quem já conhece. Falta aparecer em buscas novas.'
            ),
            'Dependencia de marca: ' . round($brandShare * 100) . '% de los clics de queries visibles son de marca (' . $brandClicks . ' clics).'
        );
    }

    $zombies = 0;
    foreach ($dossier['pages'] ?? [] as $page) {
        if ($zombies >= 4 || ($page['error'] ?? '') !== '') {
            continue;
        }
        if ((int) $page['impressions'] !== 0 || ($page['indexTone'] ?? '') === 'issue') {
            continue;
        }
        $zombies++;
        $out[] = findingsMake(
            'zombie',
            'demand',
            1,
            1,
            findingsFigure(0, 'clicks', 'risk'),
            (string) $page['path'],
            (string) $page['url'],
            '',
            ['impressions' => 0],
            findingsSay(
                (string) $page['title'] . ' no aparece en búsquedas',
                'En 28 días nadie la vio en Google. Falta contenido o enlaces internos.',
                (string) $page['title'] . ' does not show in search',
                'Nobody saw it on Google in 28 days. It needs content or internal links.',
                (string) $page['title'] . ' não aparece nas buscas',
                'Em 28 dias ninguém viu no Google. Falta conteúdo ou links internos.'
            ),
            '0 impresiones en 28 días: ' . $page['path'] . '.'
        );
    }
    return $out;
}

/** @return list<array> */
function findingsOnPage(array $dossier): array
{
    $out = [];
    if (($dossier['dom'] ?? []) === []) {
        return $out;
    }

    $titles = [];
    $metas = [];
    $titleMissing = 0;
    $titleLen = 0;
    $metaMissing = 0;
    $h1 = 0;
    $thin = 0;
    $alts = 0;
    $hasLocal = false;

    foreach ($dossier['dom'] as $path => $dom) {
        if (!is_array($dom) || empty($dom['ok'])) {
            continue;
        }
        foreach ($dom['schemas'] ?? [] as $type) {
            if (in_array($type, ['LocalBusiness', 'Organization', 'ProfessionalService', 'Store'], true)) {
                $hasLocal = true;
            }
        }
        $page = findingsByPath($dossier, (string) $path);
        $impr = (int) ($page['impressions'] ?? 0);
        $url = (string) ($page['url'] ?? '');
        $title = trim((string) ($dom['title'] ?? ''));
        $meta = trim((string) ($dom['meta'] ?? ''));
        if ($title !== '') {
            $titles[$title][] = (string) $path;
        }
        if ($meta !== '') {
            $metas[$meta][] = (string) $path;
        }
        $worth = $impr >= 15 || $path === '/';
        if (!$worth) {
            continue;
        }

        if ($title === '' && $titleMissing < 5) {
            $titleMissing++;
            $out[] = findingsMake(
                'titleMissing',
                'onpage',
                2,
                max(5, $impr),
                findingsFigure($impr, 'impressions', 'risk'),
                (string) $path,
                $url,
                '',
                ['impressions' => $impr],
                findingsSay(
                    'Falta el título que se ve en Google',
                    'Esta página no tiene el texto con el que aparece en el buscador.',
                    'The Google title is missing',
                    'This page has no text for how it shows up in search.',
                    'Falta o título que aparece no Google',
                    'Esta página não tem o texto com que aparece na busca.'
                ),
                'Title vacío en ' . $path . '.'
            );
        } elseif ($title !== '' && $titleLen < 4 && $impr >= 40) {
            $len = (int) ($dom['titleLen'] ?? 0);
            if ($len > 0 && ($len < 20 || $len > 65)) {
                $titleLen++;
                $out[] = findingsMake(
                    'titleLen',
                    'onpage',
                    1,
                    max(3, (int) round($impr * 0.02)),
                    findingsFigure($len, 'chars', 'risk'),
                    (string) $path,
                    $url,
                    '',
                    ['titleLen' => $len],
                    findingsSay(
                        'El título de Google está mal de largo',
                        'Muy corto se corta la idea; muy largo Google lo recorta y no se lee entero.',
                        'The Google title is the wrong length',
                        'Too short drops the idea; too long and Google cuts it off.',
                        'O título do Google está no tamanho errado',
                        'Curto demais perde a ideia; longo demais o Google corta.'
                    ),
                    'Title de ' . $path . ' tiene ' . $len . ' caracteres (ideal 20-65).'
                );
            }
        }

        if ($meta === '' && $metaMissing < 5 && $impr >= 30) {
            $metaMissing++;
            $out[] = findingsMake(
                'metaMissing',
                'onpage',
                2,
                max(4, (int) round($impr * 0.03)),
                findingsFigure($impr, 'impressions', 'risk'),
                (string) $path,
                $url,
                '',
                ['impressions' => $impr],
                findingsSay(
                    'Falta la frase debajo del título',
                    'En Google, debajo del título, no hay una descripción que invite a entrar.',
                    'The line under the title is missing',
                    'On Google, under the title, there is no description inviting the click.',
                    'Falta a frase abaixo do título',
                    'No Google, abaixo do título, não há uma descrição convidando a entrar.'
                ),
                'Meta description vacía en ' . $path . ' (' . $impr . ' impresiones).'
            );
        }

        if ($h1 < 5 && array_key_exists('h1Count', $dom)) {
            $count = (int) $dom['h1Count'];
            if ($count !== 1) {
                $h1++;
                $out[] = findingsMake(
                    'h1',
                    'onpage',
                    $count === 0 ? 2 : 1,
                    max(3, (int) round($impr * 0.02)),
                    findingsFigure($count, 'count', 'risk'),
                    (string) $path,
                    $url,
                    '',
                    ['h1Count' => $count],
                    findingsSay(
                        $count === 0 ? 'La página no tiene un título principal' : 'La página tiene varios títulos principales',
                        $count === 0
                            ? 'Quien entra no ve claro de qué se trata. Hace falta un solo título grande.'
                            : 'Hay más de un título grande. Conviene dejar uno solo, el de la oferta.',
                        $count === 0 ? 'The page has no main heading' : 'The page has several main headings',
                        $count === 0
                            ? 'Visitors do not see what the page is about. It needs one clear heading.'
                            : 'More than one big heading. Keep a single one, about the offer.',
                        $count === 0 ? 'A página não tem um título principal' : 'A página tem vários títulos principais',
                        $count === 0
                            ? 'Quem entra não vê do que se trata. Falta um título grande só.'
                            : 'Há mais de um título grande. Vale deixar um só, o da oferta.'
                    ),
                    'H1 count=' . $count . ' en ' . $path . '. Debe quedar exactamente 1.'
                );
            }
        }

        if ($thin < 4 && array_key_exists('wordCount', $dom) && (int) $dom['wordCount'] > 0 && (int) $dom['wordCount'] < 150 && $impr >= 20 && !findingsSkip((string) $path)) {
            $thin++;
            $out[] = findingsMake(
                'thin',
                'onpage',
                1,
                max(3, (int) round($impr * 0.02)),
                findingsFigure((int) $dom['wordCount'], 'words', 'risk'),
                (string) $path,
                $url,
                (string) ($page['query'] ?? ''),
                ['words' => (int) $dom['wordCount']],
                findingsSay(
                    'La página dice muy poco',
                    'Hay poca explicación para lo que la gente busca. Ampliarla ayuda a que Google la entienda.',
                    'The page says very little',
                    'There is little explanation for what people search. Expanding it helps Google understand it.',
                    'A página diz muito pouco',
                    'Há pouca explicação para o que as pessoas buscam. Ampliar ajuda o Google a entendê-la.'
                ),
                'Contenido delgado en ' . $path . ': ' . (int) $dom['wordCount'] . ' palabras visibles.'
            );
        }

        $img = (int) ($dom['images'] ?? 0);
        $missing = (int) ($dom['imagesMissingAlt'] ?? 0);
        if ($alts < 3 && $img >= 4 && $missing >= 3 && ($missing / $img) >= 0.4 && $impr >= 20) {
            $alts++;
            $out[] = findingsMake(
                'alts',
                'onpage',
                1,
                max(2, $missing),
                findingsFigure($missing, 'images', 'risk'),
                (string) $path,
                $url,
                '',
                ['images' => $img, 'missingAlt' => $missing],
                findingsSay(
                    'Hay fotos sin descripción',
                    'Varias imágenes no dicen qué muestran. Google y quien no las ve se pierden el contexto.',
                    'Photos without a description',
                    'Several images do not say what they show. Google and people who cannot see them miss the context.',
                    'Há fotos sem descrição',
                    'Várias imagens não dizem o que mostram. O Google e quem não as vê perdem o contexto.'
                ),
                $missing . ' de ' . $img . ' imágenes sin alt en ' . $path . '.'
            );
        }
    }

    foreach ($titles as $title => $paths) {
        $paths = array_values(array_unique($paths));
        if (count($paths) < 2 || strlen($title) < 12) {
            continue;
        }
        $out[] = findingsMake(
            'titleDup',
            'onpage',
            2,
            8 * count($paths),
            findingsFigure(count($paths), 'pages', 'risk'),
            $paths[0],
            '',
            '',
            ['title' => $title, 'paths' => array_slice($paths, 0, 4)],
            findingsSay(
                'Varias páginas se ven igual en Google',
                'Comparten el mismo título. Cada una debería decir su oferta.',
                'Several pages look the same on Google',
                'They share the same title. Each one should state its own offer.',
                'Várias páginas aparecem iguais no Google',
                'Compartilham o mesmo título. Cada uma deveria dizer a sua oferta.'
            ),
            'Title duplicado «' . $title . '» en ' . implode(', ', array_slice($paths, 0, 4)) . '.'
        );
        break;
    }
    foreach ($metas as $meta => $paths) {
        $paths = array_values(array_unique($paths));
        if (count($paths) < 2 || strlen($meta) < 20) {
            continue;
        }
        $out[] = findingsMake(
            'metaDup',
            'onpage',
            1,
            4 * count($paths),
            findingsFigure(count($paths), 'pages', 'risk'),
            $paths[0],
            '',
            '',
            ['paths' => array_slice($paths, 0, 4)],
            findingsSay(
                'La descripción de Google está repetida',
                'Dos páginas usan la misma frase. Cada resultado debería decir algo distinto.',
                'The Google description is repeated',
                'Two pages use the same sentence. Each result should say something different.',
                'A descrição do Google está repetida',
                'Duas páginas usam a mesma frase. Cada resultado deveria dizer algo distinto.'
            ),
            'Meta description duplicada en ' . implode(', ', array_slice($paths, 0, 4)) . '.'
        );
        break;
    }

    $gaps = 0;
    foreach ($buckets = ($dossier['keywords']['buckets']['striking'] ?? []) as $row) {
        if ($gaps >= 4 || !is_array($row) || !empty($row['brand'])) {
            continue;
        }
        $pos = (float) ($row['position'] ?? 0);
        $impr = (int) ($row['impressions'] ?? 0);
        $query = trim((string) ($row['q'] ?? ''));
        if ($query === '' || $pos < 4 || $pos > 12 || $impr < 40) {
            continue;
        }
        $path = dossierNormPath((string) ($row['page'] ?? ''));
        $dom = findingsDom($dossier, $path);
        if ($dom === null) {
            continue;
        }
        $needle = gscAscii($query);
        $hay = ($dom['title'] ?? '') . ' ' . ($dom['h1'] ?? '') . ' ' . ($dom['h2'] ?? '');
        if ($needle === '' || str_contains($hay, $needle)) {
            continue;
        }
        $gaps++;
        $gain = (int) ($row['gain'] ?? 0);
        $page = findingsByPath($dossier, $path);
        $out[] = findingsMake(
            'gap',
            'onpage',
            2,
            max(4, $gain),
            findingsFigure(max(4, $gain), 'clicks', 'gain'),
            $path,
            (string) ($page['url'] ?? ''),
            $query,
            ['position' => $pos, 'impressions' => $impr, 'gain' => $gain],
            findingsSay(
                'Falta «' . $query . '» en la página',
                'La gente busca esa frase y casi no está en el título ni en los encabezados.',
                '“' . $query . '” is missing on the page',
                'People search that phrase and it is barely in the title or the headings.',
                'Falta «' . $query . '» na página',
                'As pessoas buscam essa frase e ela quase não está no título nem nos títulos internos.'
            ),
            'Gap on-page: «' . $query . '» no está en title/H1/H2 de ' . $path . ' (pos ' . round($pos, 1) . ', ' . $impr . ' imp).'
        );
    }

    if (!$hasLocal) {
        $out[] = findingsMake(
            'schema',
            'onpage',
            2,
            12,
            findingsFigure(1, 'count', 'risk'),
            '/',
            'https://' . ($dossier['host'] ?? '') . '/',
            '',
            ['schemas' => []],
            findingsSay(
                'Google no sabe que es un negocio',
                'Falta marcar el nombre, la ciudad y el teléfono de forma que el buscador lo entienda.',
                'Google does not know this is a business',
                'Name, city and phone are not marked in a way search engines understand.',
                'O Google não sabe que é um negócio',
                'Falta marcar nome, cidade e telefone de um jeito que a busca entenda.'
            ),
            'Ninguna página scrapeada tiene JSON-LD LocalBusiness/Organization. Agregarlo en el layout.'
        );
    }

    $home = findingsDom($dossier, '/');
    if (is_array($home) && trim((string) ($home['ogTitle'] ?? '')) === '' && trim((string) ($home['title'] ?? '')) !== '') {
        $out[] = findingsMake(
            'og',
            'onpage',
            1,
            3,
            findingsFigure(1, 'count', 'risk'),
            '/',
            'https://' . ($dossier['host'] ?? '') . '/',
            '',
            [],
            findingsSay(
                'Al compartir el sitio no se ve bien',
                'En WhatsApp o redes, la portada no lleva título ni imagen propia.',
                'Sharing the site looks unfinished',
                'On WhatsApp or social, the homepage has no title or image of its own.',
                'Ao compartilhar o site não aparece bem',
                'No WhatsApp ou nas redes, a capa não leva título nem imagem própria.'
            ),
            'Home sin og:title. Completar Open Graph en el layout.'
        );
    }
    return $out;
}

/** @return list<array> */
function findingsConversion(array $dossier): array
{
    $out = [];
    $landings = findingsLandings($dossier);
    $rate = findingsContactRate($dossier);
    $leaks = 0;
    $bare = 0;

    foreach ($dossier['pages'] ?? [] as $page) {
        $path = (string) ($page['path'] ?? '');
        $query = (string) ($page['query'] ?? '');
        $clicks = (int) ($page['clicks'] ?? 0);
        $impr = (int) ($page['impressions'] ?? 0);
        if ($path === '' || ($clicks < 5 && $impr < 80)) {
            continue;
        }
        $landing = $landings[dossierNormPath($path)] ?? null;
        $dom = findingsDom($dossier, $path);
        $commercial = ($query !== '' && findingsIsTransactional($query))
            || (bool) preg_match('#/(servicio|servicios|contacto|contact|cotiz|producto|productos)(/|$)#', $path);

        if ($leaks < 4 && is_array($landing) && $query !== '' && findingsIsTransactional($query)) {
            $sessions = (int) ($landing['sessions'] ?? 0);
            $events = (int) ($landing['keyEvents'] ?? $landing['conversions'] ?? 0);
            $eng = (float) ($landing['engagementRate'] ?? 1);
            if ($sessions >= 3 && $events === 0 && $eng < 0.45) {
                $leaks++;
                $impact = max(1, (int) round($sessions * $rate));
                $out[] = findingsMake(
                    'leak',
                    'conversion',
                    3,
                    $impact,
                    findingsFigure(0, 'contacts', 'risk'),
                    $path,
                    (string) $page['url'],
                    $query,
                    ['sessions' => $sessions, 'engagement' => $eng, 'keyEvents' => 0],
                    findingsSay(
                        'Llegan por «' . $query . '» y no escriben',
                        'Google ya trae gente con ganas de cotizar, pero en la página no dejan contacto.',
                        'They arrive for “' . $query . '” and do not write',
                        'Search already sends people ready to ask, but the page does not turn them into a contact.',
                        'Chegam por «' . $query . '» e não escrevem',
                        'O Google já traz gente pronta para cotar, mas na página não deixam contato.'
                    ),
                    'Fuga en ' . $path . ' para «' . $query . '»: ' . $sessions . ' sesiones, engagement ' . round($eng * 100) . '%, 0 key events.'
                );
            }
        }

        if ($bare < 4 && $dom !== null && $commercial && ($clicks >= 8 || (is_array($landing) && (int) ($landing['sessions'] ?? 0) >= 8))) {
            $hasRoute = !empty($dom['hasTel']) || !empty($dom['hasWhatsapp']) || !empty($dom['hasForm']);
            if (!$hasRoute && !findingsSkip($path)) {
                $bare++;
                $out[] = findingsMake(
                    'noContact',
                    'conversion',
                    3,
                    max(4, $clicks),
                    findingsFigure(0, 'contacts', 'risk'),
                    $path,
                    (string) $page['url'],
                    $query,
                    ['clicks' => $clicks],
                    findingsSay(
                        'No hay cómo contactarlos en la página',
                        'Quien llega no encuentra WhatsApp, teléfono ni formulario a la vista.',
                        'No way to get in touch on the page',
                        'Whoever lands does not see WhatsApp, a phone or a form.',
                        'Não há como entrar em contato na página',
                        'Quem chega não encontra WhatsApp, telefone nem formulário.'
                    ),
                    'Sin tel:, WhatsApp ni <form> en ' . $path . ' (' . $clicks . ' clics, intención comercial).'
                );
            }
        }
    }

    $devices = [];
    foreach ($dossier['devices'] ?? [] as $device) {
        if (!is_array($device)) {
            continue;
        }
        $name = strtolower((string) ($device['name'] ?? ''));
        if ($name !== '') {
            $devices[$name] = $device;
        }
    }
    $mobile = $devices['mobile'] ?? null;
    $desktop = $devices['desktop'] ?? null;
    if (is_array($mobile) && is_array($desktop)) {
        $mImpr = (int) ($mobile['impressions'] ?? 0);
        $dImpr = (int) ($desktop['impressions'] ?? 0);
        $mCtr = $mImpr > 0 ? ((int) $mobile['clicks']) / $mImpr : 0;
        $dCtr = $dImpr > 0 ? ((int) $desktop['clicks']) / $dImpr : 0;
        if ($mImpr >= 80 && $dImpr >= 40 && $dCtr > 0.01 && $mCtr < $dCtr * 0.55) {
            $gain = (int) max(1, round($mImpr * ($dCtr - $mCtr)));
            $out[] = findingsMake(
                'deviceGap',
                'conversion',
                2,
                $gain,
                findingsFigure($gain, 'clicks', 'gain'),
                '/',
                'https://' . ($dossier['host'] ?? '') . '/',
                '',
                ['mobileCtr' => $mCtr, 'desktopCtr' => $dCtr, 'mobileImpressions' => $mImpr],
                findingsSay(
                    'En el celular hacen menos clic',
                    'El resultado se ve peor en el teléfono, que es donde está la mayoría de la gente.',
                    'Fewer clicks on the phone',
                    'The result looks worse on mobile, which is where most people are.',
                    'No celular clicam menos',
                    'O resultado aparece pior no telefone, que é onde está a maioria.'
                ),
                'CTR móvil ' . round($mCtr * 100, 1) . '% vs escritorio ' . round($dCtr * 100, 1) . '% (' . $mImpr . ' imp móvil). Revisar title en SERP mobile y velocidad.'
            );
        }
    }

    if (!empty($dossier['sources']['ga4']) && empty($dossier['sources']['keyEvents'])) {
        $out[] = findingsMake(
            'measure',
            'code',
            2,
            0,
            findingsFigure(0, 'contacts', 'risk'),
            '/',
            'https://' . ($dossier['host'] ?? '') . '/',
            '',
            [],
            findingsSay(
                'Todavía no se miden los contactos',
                'No queda registrado el WhatsApp, la llamada ni el formulario. Sin eso no se ve si el sitio vende.',
                'Contacts are not measured yet',
                'WhatsApp, calls and the form are not recorded. Without that, sales from the site stay invisible.',
                'Os contatos ainda não são medidos',
                'WhatsApp, ligação e formulário não ficam registrados. Sem isso não se vê se o site vende.'
            ),
            'GA4 conectado sin key events. Instrumentar click_whatsapp, phone_click y generate_lead (o form_submit) y marcarlos como eventos clave.',
            'cursor'
        );
    }
    return $out;
}

/** @return list<array> */
function findingsSpeed(array $dossier): array
{
    $out = [];
    $slow = 0;
    $cls = 0;
    $seo = 0;
    $psi = findingsPsiByPath($dossier);
    foreach ($dossier['pages'] ?? [] as $page) {
        $path = (string) ($page['path'] ?? '');
        $row = $psi[$path] ?? null;
        if (!is_array($row)) {
            continue;
        }
        $impr = (int) ($page['impressions'] ?? 0);
        if ($impr < 20 && $path !== '/') {
            continue;
        }
        $score = $row['score'] ?? null;
        $field = (string) ($row['field'] ?? '');
        $bad = $field === 'SLOW' || (is_int($score) && $score > 0 && $score < 50);
        if ($slow < 3 && $bad) {
            $slow++;
            $severity = ($field === 'SLOW' && $impr >= 100) || (is_int($score) && $score < 30) ? 3 : 2;
            $out[] = findingsMake(
                'slow',
                'speed',
                $severity,
                max(4, (int) $page['clicks'], (int) round($impr / 25)),
                findingsFigure(is_int($score) ? $score : 0, 'score', 'risk'),
                $path,
                (string) $page['url'],
                '',
                ['score' => $score, 'field' => $field, 'lcp' => $row['lcp'] ?? ''],
                findingsSay(
                    'En el celular la página va lenta',
                    'Quien entra desde el teléfono espera de más. Eso frena la visita y también la posición.',
                    'The page is slow on a phone',
                    'People arriving on a phone wait too long. That hurts the visit and the ranking.',
                    'No celular a página está lenta',
                    'Quem entra pelo telefone espera demais. Isso segura a visita e também a posição.'
                ),
                'PSI móvil ' . $path . ': score ' . ($score ?? '—') . ', campo ' . ($field !== '' ? $field : '—') . ', LCP ' . ($row['lcp'] ?? '—') . '.'
            );
        }
        $clsVal = findingsDisplay((string) ($row['cls'] ?? ''));
        if ($cls < 2 && $clsVal !== null && $clsVal > 0.25) {
            $cls++;
            $out[] = findingsMake(
                'cls',
                'speed',
                2,
                max(3, (int) round($impr / 40)),
                findingsFigure((int) round($clsVal * 100), 'count', 'risk'),
                $path,
                (string) $page['url'],
                '',
                ['cls' => $clsVal],
                findingsSay(
                    'La página se mueve mientras carga',
                    'En el celular los botones saltan. La gente toca donde no es y se va.',
                    'The page jumps while it loads',
                    'On a phone the buttons move. People tap the wrong place and leave.',
                    'A página se mexe enquanto carrega',
                    'No celular os botões pulam. A pessoa toca no lugar errado e sai.'
                ),
                'CLS ' . $row['cls'] . ' en ' . $path . ' (umbral 0.25). Reservar alto de imágenes y fuentes.'
            );
        }
        $seoScore = $row['seo'] ?? null;
        if ($seo < 2 && is_int($seoScore) && $seoScore > 0 && $seoScore < 80) {
            $seo++;
            $out[] = findingsMake(
                'psiSeo',
                'speed',
                1,
                max(2, (int) round((100 - $seoScore) / 5)),
                findingsFigure($seoScore, 'score', 'risk'),
                $path,
                (string) $page['url'],
                '',
                ['seo' => $seoScore],
                findingsSay(
                    'Google marca fallas básicas en la página',
                    'Hay detalles técnicos (títulos, enlaces, datos) que el propio test de Google señala.',
                    'Google flags basic issues on the page',
                    'The Google test itself points at titles, links or data that need a fix.',
                    'O Google marca falhas básicas na página',
                    'Há detalhes técnicos que o próprio teste do Google aponta.'
                ),
                'Lighthouse SEO ' . $seoScore . ' en ' . $path . '. Correr las auditorías failed del reporte.'
            );
        }
    }

    $a11yCount = 0;
    foreach ($dossier['pages'] ?? [] as $page) {
        $path = (string) ($page['path'] ?? '');
        $row = $psi[$path] ?? null;
        if (!is_array($row) || $a11yCount >= 2) {
            continue;
        }
        $score = $row['accessibility'] ?? null;
        $impr = (int) ($page['impressions'] ?? 0);
        if (!is_int($score) || $score <= 0 || $score >= 70 || ($impr < 20 && $path !== '/')) {
            continue;
        }
        $a11yCount++;
        $out[] = findingsMake(
            'psiA11y',
            'speed',
            1,
            max(2, (int) round((100 - $score) / 8)),
            findingsFigure($score, 'score', 'risk'),
            $path,
            (string) $page['url'],
            '',
            ['accessibility' => $score],
            findingsSay(
                'La página es difícil de usar',
                'Botones, contraste o textos no se leen bien en el celular. Parte de la gente no llega a contactar.',
                'The page is hard to use',
                'Buttons, contrast or text fail on a phone. Some people never reach the contact.',
                'A página é difícil de usar',
                'Botões, contraste ou texto falham no celular. Parte das pessoas não chega a contactar.'
            ),
            'Lighthouse accesibilidad ' . $score . ' en ' . $path . '.'
        );
    }
    return $out;
}

function findingsLayoutFile(array $paths): string
{
    foreach ($paths as $path) {
        $path = (string) $path;
        if (preg_match('#(^|/)(layout|root-layout|document)\.(tsx?|jsx?|vue|astro|php)$#i', $path)) {
            return $path;
        }
    }
    foreach ($paths as $path) {
        if (preg_match('#(^|/)index\.html$#i', (string) $path)) {
            return (string) $path;
        }
    }
    return '';
}

function findingsGuessFile(string $pagePath, array $paths): string
{
    $slug = trim($pagePath, '/');
    $last = $slug === '' ? 'index' : basename($slug);
    $best = '';
    $bestScore = 0;
    foreach ($paths as $file) {
        $file = (string) $file;
        $lower = strtolower(str_replace('\\', '/', $file));
        if (!preg_match('#\.(tsx?|jsx?|vue|astro|php|html)$#', $lower)) {
            continue;
        }
        $score = 0;
        if ($slug === '' || $last === 'index') {
            if (preg_match('#(^|/)(page|index)\.(tsx?|jsx?|vue|astro|html|php)$#', $lower)) {
                $score = 40 - substr_count($lower, '/');
            }
        } elseif (str_contains($lower, strtolower($last))) {
            $score = 25;
            if (str_contains($lower, strtolower($slug))) {
                $score += 25;
            }
            if (preg_match('#(page|index)\.(tsx?|jsx?|vue|astro|html|php)$#', $lower)) {
                $score += 15;
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $file;
        }
    }
    return $bestScore >= 20 ? $best : '';
}

function findingsAttachFiles(array $findings, array $dossier): array
{
    $paths = $dossier['repo']['paths'] ?? [];
    if ($paths === []) {
        return $findings;
    }
    $layout = findingsLayoutFile($paths);
    $sitewide = ['schema', 'og', 'brand', 'deviceGap', 'sitemap', 'measure', 'titleDup', 'metaDup'];
    foreach ($findings as &$finding) {
        if (in_array($finding['kind'], $sitewide, true)) {
            $finding['file'] = $layout;
            continue;
        }
        $finding['file'] = findingsGuessFile((string) ($finding['path'] ?? '/'), $paths);
        if ($finding['file'] === '' && $finding['category'] === 'speed') {
            $finding['file'] = $layout;
        }
    }
    unset($finding);
    return $findings;
}

function findingsDetect(array $dossier): array
{
    if (empty($dossier['ok'])) {
        return [];
    }
    $all = array_merge(
        findingsIndex($dossier),
        findingsDemand($dossier),
        findingsOnPage($dossier),
        findingsConversion($dossier),
        findingsSpeed($dossier)
    );
    $seen = [];
    $out = [];
    foreach ($all as $finding) {
        $key = $finding['kind'] . '|' . $finding['path'] . '|' . gscAscii((string) $finding['query']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $finding;
    }
    usort($out, static fn ($a, $b) => ($b['severity'] <=> $a['severity']) ?: ($b['impact'] <=> $a['impact']));
    $out = array_slice($out, 0, 40);
    return findingsAttachFiles($out, $dossier);
}

/**
 * Los 3 que ve el cliente. La indexación de páginas públicas no entra:
 * eso se corrige en el ciclo, no se informa como hallazgo del mes.
 */
function findingsTop(array $findings, int $limit = 3): array
{
    $visible = array_values(array_filter(
        $findings,
        static fn ($f) => ($f['audience'] ?? 'both') !== 'cursor' && ($f['category'] ?? '') !== 'index'
    ));
    $pool = $visible;
    usort($pool, static fn ($a, $b) => ($b['severity'] <=> $a['severity']) ?: ($b['impact'] <=> $a['impact']));
    $strong = array_values(array_filter($pool, static fn ($f) => (int) ($f['severity'] ?? 0) >= 2));
    return array_slice($strong !== [] ? $strong : $pool, 0, $limit);
}

function findingsCounts(array $findings): array
{
    $out = ['total' => count($findings), 'index' => 0, 'demand' => 0, 'onpage' => 0, 'conversion' => 0, 'speed' => 0, 'code' => 0];
    foreach ($findings as $finding) {
        $category = (string) ($finding['category'] ?? '');
        if (isset($out[$category])) {
            $out[$category]++;
        }
    }
    return $out;
}

function findingsCursorLines(array $findings): string
{
    $lines = [];
    foreach ($findings as $i => $f) {
        $n = $i + 1;
        $lines[] = $n . '. [' . $f['kind'] . '/' . $f['category'] . '] sev ' . $f['severity']
            . ' · impacto ' . $f['impact']
            . ' · ' . $f['path']
            . ($f['query'] !== '' ? ' · query «' . $f['query'] . '»' : '')
            . ($f['file'] !== '' ? ' · archivo ' . $f['file'] : '');
        $lines[] = '   ' . $f['techText'];
        $ev = json_encode($f['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($ev) && $ev !== '[]' && $ev !== '{}') {
            $lines[] = '   evidencia: ' . $ev;
        }
    }
    return implode("\n", $lines);
}

function findingsCursorBlock(array $findings): string
{
    if ($findings === []) {
        return 'Sin hallazgos sobre los datos disponibles.';
    }
    $now = [];
    $later = [];
    foreach ($findings as $f) {
        if (($f['category'] ?? '') === 'index') {
            $now[] = $f;
        } else {
            $later[] = $f;
        }
    }
    $chunks = [];
    if ($now !== []) {
        $chunks[] = "CORREGIR AHORA — indexación de páginas públicas. Un cambio por URL, antes que cualquier otro trabajo. No quites noindex de páginas privadas (login, gracias, admin, carrito, preview).\n"
            . findingsCursorLines($now);
    }
    if ($later !== []) {
        $chunks[] = "DESPUÉS — solo cuando la indexación pública ya esté corregida\n" . findingsCursorLines($later);
    }
    return implode("\n\n", $chunks);
}

/**
 * Proyección para la lista de señales del operador. Mantiene los kinds
 * que la agenda ya conoce (noindex, falling, push, zombie, blocked).
 */
function findingsSignalsFromRows(array $rows): array
{
    $pages = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $url = (string) ($row['url'] ?? '');
        $pages[] = [
            'url' => $url,
            'path' => dossierNormPath($url),
            'title' => gscPageTitle($row),
            'clicks' => (int) ($row['current']['clicks'] ?? 0),
            'impressions' => (int) ($row['current']['impressions'] ?? 0),
            'ctr' => (float) ($row['current']['ctr'] ?? 0),
            'position' => (float) ($row['current']['position'] ?? 0),
            'prevClicks' => (int) ($row['previous']['clicks'] ?? 0),
            'deltaClicks' => (int) ($row['delta']['clicks'] ?? 0),
            'deltaPosition' => (float) ($row['delta']['position'] ?? 0),
            'indexTone' => (string) ($row['indexStatus']['tone'] ?? ''),
            'indexReason' => (string) ($row['indexStatus']['reason'] ?? $row['indexStatus']['coverage'] ?? 'Google no la tiene en el índice.'),
            'error' => (string) ($row['error'] ?? ''),
            'thermometer' => (string) ($row['thermometer']['tone'] ?? ''),
            'query' => '',
        ];
    }
    $findings = findingsDetect([
        'ok' => true,
        'host' => '',
        'pages' => $pages,
        'keywords' => [],
        'dom' => [],
        'psi' => ['pages' => []],
        'devices' => [],
        'diagnostics' => [],
        'inventory' => [],
        'sources' => [],
        'ga' => null,
        'repo' => ['paths' => [], 'files' => []],
    ]);

    $want = ['blocked' => 'blocked', 'noindex' => 'noindex', 'falling' => 'falling', 'lowctr' => 'push', 'zombie' => 'zombie'];
    $signals = [];
    $notIndexed = [];
    foreach ($findings as $f) {
        $kind = $want[$f['kind']] ?? '';
        if ($kind === '') {
            continue;
        }
        $title = (string) ($f['lines']['es']['title'] ?? $f['path']);
        if ($kind === 'noindex') {
            $notIndexed[] = $title;
        }
        $metric = match ($kind) {
            'noindex' => 'CRÍTICO · Pedir indexación',
            'blocked' => 'Bloqueada',
            'falling' => ((int) ($f['evidence']['deltaClicks'] ?? 0)) . ' clics',
            'push' => 'CTR ' . round(((float) ($f['evidence']['ctr'] ?? 0)) * 100, 1) . '% · Pos. ' . round((float) ($f['evidence']['position'] ?? 0), 1),
            'zombie' => '0 impresiones',
            default => '',
        };
        $signals[] = [
            'kind' => $kind,
            'severity' => (int) $f['severity'],
            'title' => $title,
            'detail' => (string) ($f['techText'] ?? ''),
            'metric' => $metric,
            'url' => (string) ($f['url'] ?? ''),
        ];
    }

    $notCount = count($notIndexed);
    if ($notCount > 0) {
        $sample = array_slice($notIndexed, 0, 4);
        $extra = $notCount > 4 ? ' y ' . ($notCount - 4) . ' más' : '';
        array_unshift($signals, [
            'kind' => 'site-noindex',
            'severity' => 3,
            'title' => $notCount === 1 ? '1 página fuera del índice' : $notCount . ' páginas fuera del índice',
            'detail' => 'Incidente grave: sin indexación no hay tráfico orgánico. Revisar: ' . implode(', ', $sample) . $extra . '.',
            'metric' => 'CRÍTICO',
            'url' => '',
        ]);
    }

    usort($signals, static function ($a, $b) {
        $aSite = ($a['kind'] ?? '') === 'site-noindex' ? 1 : 0;
        $bSite = ($b['kind'] ?? '') === 'site-noindex' ? 1 : 0;
        if ($aSite !== $bSite) {
            return $bSite <=> $aSite;
        }
        return ($b['severity'] <=> $a['severity']);
    });
    $signals = array_slice($signals, 0, 24);
    foreach ($signals as $i => $signal) {
        $signals[$i]['id'] = $signal['kind'] . '|' . ($signal['url'] !== '' ? $signal['url'] : 'site');
    }
    return $signals;
}
