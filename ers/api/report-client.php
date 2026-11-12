<?php
/**
 * Informe para entregar al cliente. Mismo dossier que el informe Mono Studio,
 * pero cuenta el mes desde lo que avanzó. Ningún número se maquilla: si bajó,
 * se dice con su nombre ("leve baja"), sin alarma y sin jerga.
 */

declare(strict_types=1);

if (!defined('ERS_LIBRARY')) {
    http_response_code(404);
    exit;
}

const REPORT_CLIENT_VERSION = '2026.11.12-client-gbp';

function reportClientCopy(string $lang): array
{
    $all = [
        'es' => [
            'kicker' => 'Mono Studio',
            'title' => 'Informe mensual',
            'pageHow' => 'Así avanzó su sitio este mes',
            'generated' => 'Preparado el',
            'visits' => 'Personas desde Google',
            'seen' => 'Veces que los vieron',
            'contacts' => 'Contactos',
            'pages' => 'Páginas en Google',
            'speed' => 'Velocidad en celular',
            'maps' => 'Google Maps',
            'mapsReviews' => 'opiniones',
            'vsPrev' => 'vs. mes anterior',
            'first' => 'Primeros resultados',
            'none' => 'En preparación',
            'strong' => 'Crecimiento fuerte',
            'growing' => 'En crecimiento',
            'slow' => 'Crecimiento lento',
            'stable' => 'Estable',
            'dip' => 'Leve baja',
            'low' => 'Mes más tranquilo',
            'headGrow' => '%s sigue sumando personas que llegan desde Google.',
            'headSeen' => 'Google los muestra más que el mes pasado. El siguiente paso es convertir esa visibilidad en visitas.',
            'headStable' => 'El sitio mantiene su lugar en Google y queda listo para el siguiente paso.',
            'headLow' => 'Fue un mes más tranquilo en Google. Ya sabemos dónde recuperar terreno.',
            'headFirst' => 'Llegaron los primeros resultados desde Google.',
            'chart' => 'Visitas desde Google, día a día',
            'legendNow' => 'Este mes',
            'legendPrev' => 'Mes anterior',
            'channels' => 'De dónde llegan',
            'queries' => 'Cómo los encuentran',
            'best' => 'Lo mejor del mes',
            'bestPage' => '%s sumó %s visitas desde Google',
            'bestRising' => 'Más gente busca «%s»',
            'bestRisingLine' => '%s veces más que el mes anterior',
            'bestSeen' => 'Aparecieron %s veces más en Google',
            'bestFast' => 'El sitio carga rápido en el celular',
            'bestContacts' => '%s contactos llegaron desde el sitio',
            'bestBase' => 'El sitio ya está en Google con %s páginas',
            'bestBaseLine' => 'Es la base sobre la que vamos a crecer.',
            'bestMaps' => 'Calificación %s en Google Maps',
            'bestMapsLine' => '%s opiniones de clientes reales',
            'bestMapsNew' => '+%s opiniones nuevas en Google Maps',
            'done' => 'Lo que hicimos',
            'doneIndex' => 'Estamos sumando a Google: %s',
            'doneReview' => 'Revisamos el sitio completo: búsquedas, páginas, velocidad, Maps y contactos.',
            'next' => 'Oportunidades para el próximo mes',
            'nextEmpty' => 'Seguimos midiendo y afinando lo que ya funciona.',
            'oppGain' => 'Hasta %s visitas más al mes',
            'oppStriking' => 'Llevar «%s» a los primeros resultados',
            'oppStrikingLine' => 'Ya aparece en Google. Con un ajuste puede quedar arriba, donde la gente hace clic.',
            'oppCtr' => 'Hacer más atractiva %s en Google',
            'oppCtrLine' => 'Mucha gente la ve. Un mejor título invita a entrar.',
            'oppFalling' => 'Recuperar el ritmo de %s',
            'oppFallingLine' => 'Ya trajo visitas antes. Vamos a renovarla para que vuelva a subir.',
            'oppContact' => 'Facilitar el contacto desde %s',
            'oppContactLine' => 'Que escribir por WhatsApp o llamar quede a un toque.',
            'oppSpeed' => 'Hacer el sitio más rápido en el celular',
            'oppSpeedLine' => 'La mayoría entra desde el teléfono. Cada segundo cuenta.',
            'oppContent' => 'Afinar el contenido de %s',
            'oppContentLine' => 'Que Google entienda mejor qué ofrecen y a quién.',
            'oppRising' => 'Aprovechar el interés en «%s»',
            'oppRisingLine' => 'Es una búsqueda que está creciendo. Vamos a ampliar esa página.',
            'oppMeasure' => 'Medir cada contacto',
            'oppMeasureLine' => 'Contar los WhatsApp, llamadas y formularios para ver el resultado en clientes.',
            'oppNap' => 'Unificar datos entre Google Maps y el sitio',
            'oppNapLine' => 'Que el teléfono y la dirección sean los mismos en Maps y en la web.',
            'oppReviews' => 'Sumar opiniones de clientes en Google',
            'oppReviewsLine' => 'Más reseñas reales fortalecen la ficha frente a la competencia local.',
            'oppMapsSite' => 'Enlazar el sitio en Google Maps',
            'oppMapsSiteLine' => 'Quien los encuentra en el mapa debe poder entrar al sitio de un toque.',
            'sign' => 'Cualquier duda sobre este informe, lo conversamos.',
            'other' => 'Otros',
            'home' => 'Inicio',
        ],
        'en' => [
            'kicker' => 'Mono Studio',
            'title' => 'Monthly report',
            'pageHow' => 'How your site moved forward this month',
            'generated' => 'Prepared on',
            'visits' => 'People from Google',
            'seen' => 'Times you were seen',
            'contacts' => 'Contacts',
            'pages' => 'Pages on Google',
            'speed' => 'Phone speed',
            'maps' => 'Google Maps',
            'mapsReviews' => 'reviews',
            'vsPrev' => 'vs. last month',
            'first' => 'First results',
            'none' => 'Getting ready',
            'strong' => 'Strong growth',
            'growing' => 'Growing',
            'slow' => 'Slow growth',
            'stable' => 'Steady',
            'dip' => 'Slight dip',
            'low' => 'A quieter month',
            'headGrow' => '%s keeps gaining people who arrive from Google.',
            'headSeen' => 'Google is showing you more than last month. Next, we turn that visibility into visits.',
            'headStable' => 'The site is holding its place on Google and is ready for the next step.',
            'headLow' => 'It was a quieter month on Google. We already know where to win ground back.',
            'headFirst' => 'The first results from Google have arrived.',
            'chart' => 'Visits from Google, day by day',
            'legendNow' => 'This month',
            'legendPrev' => 'Last month',
            'channels' => 'Where they come from',
            'queries' => 'How people find you',
            'best' => 'Highlights',
            'bestPage' => '%s gained %s visits from Google',
            'bestRising' => 'More people search for “%s”',
            'bestRisingLine' => '%s more times than last month',
            'bestSeen' => 'You appeared %s more times on Google',
            'bestFast' => 'The site loads fast on phones',
            'bestContacts' => '%s contacts came through the site',
            'bestBase' => 'The site is on Google with %s pages',
            'bestBaseLine' => 'That is the base we will grow from.',
            'bestMaps' => '%s rating on Google Maps',
            'bestMapsLine' => '%s reviews from real customers',
            'bestMapsNew' => '+%s new reviews on Google Maps',
            'done' => 'What we did',
            'doneIndex' => 'Adding to Google: %s',
            'doneReview' => 'We reviewed the whole site: searches, pages, speed, Maps and contacts.',
            'next' => 'Opportunities for next month',
            'nextEmpty' => 'We keep measuring and refining what already works.',
            'oppGain' => 'Up to %s more visits a month',
            'oppStriking' => 'Bring “%s” to the top results',
            'oppStrikingLine' => 'It already shows on Google. One adjustment can move it up where people click.',
            'oppCtr' => 'Make %s more appealing on Google',
            'oppCtrLine' => 'Many people see it. A better title invites them in.',
            'oppFalling' => 'Bring %s back up to pace',
            'oppFallingLine' => 'It brought visits before. We will refresh it so it climbs again.',
            'oppContact' => 'Make contact easier from %s',
            'oppContactLine' => 'WhatsApp or a call should be one tap away.',
            'oppSpeed' => 'Make the site faster on phones',
            'oppSpeedLine' => 'Most visits come from a phone. Every second counts.',
            'oppContent' => 'Refine the content of %s',
            'oppContentLine' => 'So Google better understands what you offer and to whom.',
            'oppRising' => 'Ride the interest in “%s”',
            'oppRisingLine' => 'This search is growing. We will expand that page.',
            'oppMeasure' => 'Measure every contact',
            'oppMeasureLine' => 'Count WhatsApp messages, calls and forms to see results in customers.',
            'oppNap' => 'Align Google Maps and the website',
            'oppNapLine' => 'Phone and address should match on Maps and on the site.',
            'oppReviews' => 'Add customer reviews on Google',
            'oppReviewsLine' => 'More real reviews strengthen the listing against local competitors.',
            'oppMapsSite' => 'Link the website on Google Maps',
            'oppMapsSiteLine' => 'People who find you on the map should reach the site in one tap.',
            'sign' => 'Any questions about this report, let’s talk.',
            'other' => 'Other',
            'home' => 'Home',
        ],
        'pt' => [
            'kicker' => 'Mono Studio',
            'title' => 'Relatório mensal',
            'pageHow' => 'Como seu site avançou este mês',
            'generated' => 'Preparado em',
            'visits' => 'Pessoas pelo Google',
            'seen' => 'Vezes que viram vocês',
            'contacts' => 'Contatos',
            'pages' => 'Páginas no Google',
            'speed' => 'Velocidade no celular',
            'maps' => 'Google Maps',
            'mapsReviews' => 'avaliações',
            'vsPrev' => 'vs. mês anterior',
            'first' => 'Primeiros resultados',
            'none' => 'Em preparação',
            'strong' => 'Crescimento forte',
            'growing' => 'Em crescimento',
            'slow' => 'Crescimento lento',
            'stable' => 'Estável',
            'dip' => 'Leve queda',
            'low' => 'Mês mais tranquilo',
            'headGrow' => '%s segue somando pessoas que chegam pelo Google.',
            'headSeen' => 'O Google mostra vocês mais que no mês passado. O próximo passo é transformar isso em visitas.',
            'headStable' => 'O site mantém seu lugar no Google e está pronto para o próximo passo.',
            'headLow' => 'Foi um mês mais tranquilo no Google. Já sabemos onde recuperar terreno.',
            'headFirst' => 'Chegaram os primeiros resultados do Google.',
            'chart' => 'Visitas pelo Google, dia a dia',
            'legendNow' => 'Este mês',
            'legendPrev' => 'Mês anterior',
            'channels' => 'De onde chegam',
            'queries' => 'Como encontram vocês',
            'best' => 'O melhor do mês',
            'bestPage' => '%s somou %s visitas pelo Google',
            'bestRising' => 'Mais gente busca «%s»',
            'bestRisingLine' => '%s vezes a mais que no mês anterior',
            'bestSeen' => 'Apareceram %s vezes a mais no Google',
            'bestFast' => 'O site carrega rápido no celular',
            'bestContacts' => '%s contatos chegaram pelo site',
            'bestBase' => 'O site já está no Google com %s páginas',
            'bestBaseLine' => 'É a base sobre a qual vamos crescer.',
            'bestMaps' => 'Nota %s no Google Maps',
            'bestMapsLine' => '%s avaliações de clientes reais',
            'bestMapsNew' => '+%s avaliações novas no Google Maps',
            'done' => 'O que fizemos',
            'doneIndex' => 'Estamos incluindo no Google: %s',
            'doneReview' => 'Revisamos o site inteiro: buscas, páginas, velocidade, Maps e contatos.',
            'next' => 'Oportunidades para o próximo mês',
            'nextEmpty' => 'Seguimos medindo e afinando o que já funciona.',
            'oppGain' => 'Até %s visitas a mais por mês',
            'oppStriking' => 'Levar «%s» aos primeiros resultados',
            'oppStrikingLine' => 'Já aparece no Google. Um ajuste pode colocá-la no topo, onde as pessoas clicam.',
            'oppCtr' => 'Deixar %s mais atraente no Google',
            'oppCtrLine' => 'Muita gente vê. Um título melhor convida a entrar.',
            'oppFalling' => 'Recuperar o ritmo de %s',
            'oppFallingLine' => 'Já trouxe visitas antes. Vamos renová-la para voltar a subir.',
            'oppContact' => 'Facilitar o contato a partir de %s',
            'oppContactLine' => 'WhatsApp ou ligação a um toque.',
            'oppSpeed' => 'Deixar o site mais rápido no celular',
            'oppSpeedLine' => 'A maioria entra pelo telefone. Cada segundo conta.',
            'oppContent' => 'Afinar o conteúdo de %s',
            'oppContentLine' => 'Para o Google entender melhor o que vocês oferecem e para quem.',
            'oppRising' => 'Aproveitar o interesse em «%s»',
            'oppRisingLine' => 'É uma busca em crescimento. Vamos ampliar essa página.',
            'oppMeasure' => 'Medir cada contato',
            'oppMeasureLine' => 'Contar WhatsApp, ligações e formulários para ver o resultado em clientes.',
            'oppNap' => 'Unificar dados entre Google Maps e o site',
            'oppNapLine' => 'Telefone e endereço iguais no Maps e no site.',
            'oppReviews' => 'Somar avaliações de clientes no Google',
            'oppReviewsLine' => 'Mais avaliações reais fortalecem a ficha frente à concorrência local.',
            'oppMapsSite' => 'Ligar o site no Google Maps',
            'oppMapsSiteLine' => 'Quem encontra no mapa deve entrar no site com um toque.',
            'sign' => 'Qualquer dúvida sobre este relatório, conversamos.',
            'other' => 'Outros',
            'home' => 'Início',
        ],
    ];
    return $all[$lang] ?? $all['es'];
}

/**
 * Nombre honesto para la variación: "crecimiento lento" es verdad y no asusta;
 * una baja real se nombra como baja.
 */
function reportClientTrend(int $now, int $prev, array $t): array
{
    if ($prev <= 0) {
        return $now > 0
            ? ['key' => 'first', 'word' => $t['first'], 'tone' => 'up', 'pct' => null]
            : ['key' => 'none', 'word' => $t['none'], 'tone' => 'flat', 'pct' => null];
    }
    $pct = ($now - $prev) / $prev;
    $key = match (true) {
        $pct >= 0.25 => 'strong',
        $pct >= 0.08 => 'growing',
        $pct > 0.0 => 'slow',
        $pct >= -0.05 => 'stable',
        $pct >= -0.2 => 'dip',
        default => 'low',
    };
    $tone = in_array($key, ['strong', 'growing', 'slow'], true) ? 'up' : ($key === 'stable' ? 'flat' : 'soft');
    return ['key' => $key, 'word' => $t[$key], 'tone' => $tone, 'pct' => $pct];
}

function reportClientPct(?float $pct, string $lang): string
{
    if ($pct === null || abs($pct) < 0.005) {
        return '';
    }
    $n = (int) round(abs($pct) * 100);
    return ($pct > 0 ? '+' : '−') . reportNum((float) max(1, $n), $lang) . '%';
}

function reportClientPageName(array $dossier, string $path, array $t): string
{
    $path = dossierNormPath($path);
    if ($path === '/' || $path === '') {
        return $t['home'];
    }
    return findingsPublicName($path);
}

function reportClientKpis(array $dossier, string $lang, array $t, array $speed): array
{
    $search = $dossier['search'];
    $ga = is_array($dossier['ga'] ?? null) ? $dossier['ga'] : null;
    $items = [];

    $clicks = (int) ($search['clicks'] ?? 0);
    $trend = reportClientTrend($clicks, (int) ($search['prevClicks'] ?? 0), $t);
    $items[] = ['label' => $t['visits'], 'value' => reportNum((float) $clicks, $lang), 'trend' => $trend];

    $impr = (int) ($search['impressions'] ?? 0);
    $seen = reportClientTrend($impr, (int) ($search['prevImpressions'] ?? 0), $t);
    $items[] = ['label' => $t['seen'], 'value' => reportNum((float) $impr, $lang), 'trend' => $seen];

    if ($ga && !empty($ga['hasKeyEvents'])) {
        $now = (int) ($ga['keyEvents'] ?? 0);
        $items[] = [
            'label' => $t['contacts'],
            'value' => reportNum((float) (int) ($ga['contacts'] ?? $now), $lang),
            'trend' => reportClientTrend($now, (int) ($ga['keyEventsPrev'] ?? 0), $t),
        ];
    } else {
        $indexed = (int) ($dossier['inventory']['indexed'] ?? 0);
        $items[] = [
            'label' => $t['pages'],
            'value' => $indexed > 0 ? reportNum((float) $indexed, $lang) : '—',
            'trend' => ['key' => 'flat', 'word' => '', 'tone' => 'flat', 'pct' => null],
        ];
    }

    if (($speed['key'] ?? 'none') !== 'none') {
        $items[] = [
            'label' => $t['speed'],
            'value' => $speed['word'],
            'trend' => ['key' => 'flat', 'word' => '', 'tone' => $speed['tone'] === 'down' ? 'soft' : $speed['tone'], 'pct' => null],
        ];
    }

    $gbp = is_array($dossier['gbp'] ?? null) ? $dossier['gbp'] : [];
    if (!empty($gbp['ok']) && isset($gbp['rating'])) {
        $delta = (int) ($gbp['reviewsDelta'] ?? 0);
        $items[] = [
            'label' => $t['maps'],
            'value' => reportNum((float) $gbp['rating'], $lang, 1) . '★',
            'trend' => [
                'key' => $delta > 0 ? 'growing' : 'flat',
                'word' => reportNum((float) (int) ($gbp['userRatingCount'] ?? 0), $lang) . ' ' . $t['mapsReviews']
                    . ($delta > 0 ? ' · +' . reportNum((float) $delta, $lang) : ''),
                'tone' => $delta > 0 ? 'up' : 'flat',
                'pct' => null,
            ],
        ];
    }
    return [$items, $trend, $seen];
}

function reportClientHeadline(string $name, array $visits, array $seen, array $t): string
{
    if ($visits['key'] === 'first') {
        return $t['headFirst'];
    }
    if (in_array($visits['key'], ['strong', 'growing', 'slow'], true)) {
        return sprintf($t['headGrow'], $name);
    }
    if (in_array($seen['key'], ['strong', 'growing', 'slow', 'first'], true)) {
        return $t['headSeen'];
    }
    if ($visits['key'] === 'stable' || $visits['key'] === 'none') {
        return $t['headStable'];
    }
    return $t['headLow'];
}

function reportClientWins(array $dossier, string $lang, array $t, array $speed): array
{
    $wins = [];

    $best = null;
    foreach ($dossier['pages'] ?? [] as $page) {
        $d = (int) ($page['deltaClicks'] ?? 0);
        if ($d > 0 && ($best === null || $d > (int) $best['deltaClicks'])) {
            $best = $page;
        }
    }
    if ($best !== null) {
        $wins[] = [
            'title' => sprintf($t['bestPage'], reportClientPageName($dossier, (string) $best['path'], $t), '+' . reportNum((float) $best['deltaClicks'], $lang)),
            'line' => '',
        ];
    }

    $ga = is_array($dossier['ga'] ?? null) ? $dossier['ga'] : null;
    if ($ga && !empty($ga['hasKeyEvents']) && (int) ($ga['contacts'] ?? 0) > 0) {
        $wins[] = ['title' => sprintf($t['bestContacts'], reportNum((float) $ga['contacts'], $lang)), 'line' => ''];
    }

    foreach ($dossier['keywords']['buckets']['rising'] ?? [] as $row) {
        if (!is_array($row) || !empty($row['brand'])) {
            continue;
        }
        $dImpr = (int) ($row['dImpr'] ?? 0);
        $q = trim((string) ($row['q'] ?? ''));
        if ($dImpr < 10 || $q === '') {
            continue;
        }
        $wins[] = [
            'title' => sprintf($t['bestRising'], $q),
            'line' => sprintf($t['bestRisingLine'], reportNum((float) $dImpr, $lang)),
        ];
        break;
    }

    $dImpr = (int) ($dossier['search']['impressionsDelta'] ?? 0);
    if ($dImpr > 0) {
        $wins[] = ['title' => sprintf($t['bestSeen'], reportNum((float) $dImpr, $lang)), 'line' => ''];
    }

    if (($speed['key'] ?? '') === 'fast') {
        $wins[] = ['title' => $t['bestFast'], 'line' => ''];
    }

    $gbp = is_array($dossier['gbp'] ?? null) ? $dossier['gbp'] : [];
    if (!empty($gbp['ok'])) {
        $delta = (int) ($gbp['reviewsDelta'] ?? 0);
        $rating = isset($gbp['rating']) ? (float) $gbp['rating'] : null;
        if ($delta > 0) {
            $wins[] = [
                'title' => sprintf($t['bestMapsNew'], reportNum((float) $delta, $lang)),
                'line' => '',
            ];
        } elseif ($rating !== null && $rating >= 4.7 && (int) ($gbp['userRatingCount'] ?? 0) >= 10) {
            $wins[] = [
                'title' => sprintf($t['bestMaps'], reportNum($rating, $lang, 1)),
                'line' => sprintf($t['bestMapsLine'], reportNum((float) (int) $gbp['userRatingCount'], $lang)),
            ];
        }
    }

    if ($wins === []) {
        $indexed = (int) ($dossier['inventory']['indexed'] ?? 0);
        if ($indexed > 0) {
            $wins[] = ['title' => sprintf($t['bestBase'], reportNum((float) $indexed, $lang)), 'line' => $t['bestBaseLine']];
        }
    }
    return array_slice($wins, 0, 4);
}

/** Traduce el hallazgo técnico a la oportunidad que el cliente gana al resolverlo. */
function reportClientOpportunity(array $f, array $dossier, string $lang, array $t): ?array
{
    $kind = (string) ($f['kind'] ?? '');
    $page = reportClientPageName($dossier, (string) ($f['path'] ?? '/'), $t);
    $query = trim((string) ($f['query'] ?? ''));
    $figure = is_array($f['figure'] ?? null) ? $f['figure'] : [];
    $gain = (($figure['dir'] ?? '') === 'gain' && ($figure['unit'] ?? '') === 'clicks' && (int) ($figure['n'] ?? 0) > 0)
        ? sprintf($t['oppGain'], reportNum((float) $figure['n'], $lang))
        : '';

    [$title, $line, $cat] = match (true) {
        $kind === 'striking' && $query !== '' => [sprintf($t['oppStriking'], $query), $t['oppStrikingLine'], 'google'],
        $kind === 'rising' && $query !== '' => [sprintf($t['oppRising'], $query), $t['oppRisingLine'], 'content'],
        $kind === 'lowctr' => [sprintf($t['oppCtr'], $page), $t['oppCtrLine'], 'google'],
        $kind === 'falling' => [sprintf($t['oppFalling'], $page), $t['oppFallingLine'], 'content'],
        in_array($kind, ['leak', 'noContact', 'deviceGap'], true) => [sprintf($t['oppContact'], $page), $t['oppContactLine'], 'contacts'],
        in_array($kind, ['slow', 'cls'], true) => [$t['oppSpeed'], $t['oppSpeedLine'], 'speed'],
        $kind === 'measure' => [$t['oppMeasure'], $t['oppMeasureLine'], 'contacts'],
        in_array($kind, ['napPhoneMismatch', 'localSchemaIncomplete'], true) => [$t['oppNap'], $t['oppNapLine'], 'maps'],
        in_array($kind, ['gbpLowReviews', 'gbpLowRating', 'gbpNegativeReview'], true) => [$t['oppReviews'], $t['oppReviewsLine'], 'maps'],
        in_array($kind, ['gbpNoWebsite', 'gbpWrongDomain'], true) => [$t['oppMapsSite'], $t['oppMapsSiteLine'], 'maps'],
        ($f['category'] ?? '') === 'onpage' || in_array($kind, ['cannibal', 'zombie', 'brand'], true)
            => [sprintf($t['oppContent'], $page), $t['oppContentLine'], 'content'],
        default => [null, null, null],
    };
    if ($title === null) {
        return null;
    }
    return ['title' => $title, 'line' => $line, 'number' => $gain, 'category' => $cat];
}

function reportClientOpportunities(array $findings, array $dossier, string $lang, array $t): array
{
    $pool = array_values(array_filter(
        $findings,
        static fn ($f) => ($f['category'] ?? '') !== 'index'
    ));
    usort($pool, static fn ($a, $b) => ($b['impact'] <=> $a['impact']) ?: ($b['severity'] <=> $a['severity']));
    $out = [];
    $seen = [];
    foreach ($pool as $f) {
        $opp = reportClientOpportunity($f, $dossier, $lang, $t);
        if ($opp === null || isset($seen[$opp['title']])) {
            continue;
        }
        $seen[$opp['title']] = true;
        $out[] = $opp;
        if (count($out) >= 3) {
            break;
        }
    }
    return $out;
}

function reportClientDone(array $dossier, array $findings, string $lang, array $t): array
{
    $done = [];
    foreach (array_slice($dossier['tasks'] ?? [], 0, 4) as $task) {
        $title = trim((string) ($task['title'] ?? ''));
        if ($title !== '') {
            $done[] = $title;
        }
    }
    $names = [];
    foreach ($findings as $f) {
        if (($f['category'] ?? '') !== 'index' || !in_array($f['kind'] ?? '', ['noindex', 'blocked', 'robotsMeta', 'canonical'], true)) {
            continue;
        }
        if (findingsIsPrivatePath((string) ($f['path'] ?? '/'))) {
            continue;
        }
        $names[] = reportClientPageName($dossier, (string) $f['path'], $t);
    }
    $names = array_values(array_unique($names));
    if ($names !== []) {
        $done[] = sprintf($t['doneIndex'], reportJoinNames(array_slice($names, 0, 4), $lang));
    }
    $done[] = $t['doneReview'];
    return array_slice($done, 0, 5);
}

function reportClientRender(array $m): string
{
    $t = $m['t'];
    $e = 'reportE';

    $kpis = '';
    foreach ($m['kpis'] as $k) {
        $trend = $k['trend'];
        $pct = reportClientPct($trend['pct'] ?? null, $m['lang']);
        $badge = ($trend['word'] ?? '') !== ''
            ? '<em class="t-' . $e((string) $trend['tone']) . '">' . $e((string) $trend['word'])
                . ($pct !== '' ? ' <b>' . $e($pct) . '</b>' : '') . '</em>'
            : '';
        $kpis .= '<article class="kpi"><span>' . $e((string) $k['label']) . '</span><strong>'
            . $e((string) $k['value']) . '</strong>' . $badge . '</article>';
    }

    $chart = $m['bars'] !== ''
        ? '<section class="block"><h2>' . $e($t['chart']) . '</h2>' . $m['bars']
            . '<p class="legend"><i class="sw sw--now"></i>' . $e($t['legendNow'])
            . '<i class="sw sw--prev"></i>' . $e($t['legendPrev']) . '</p></section>'
        : '';

    $channels = '';
    if ($m['channels'] !== []) {
        $bar = '';
        $legend = '';
        $colors = ['#1f5f4a', '#c8a86b', '#8fb8a6', '#d9cfbd', '#ece6da'];
        foreach ($m['channels'] as $i => $slice) {
            $color = $colors[$i] ?? '#ece6da';
            $bar .= '<span style="width:' . max(2, round(((float) $slice['share']) * 100, 1)) . '%;background:' . $color . '"></span>';
            $legend .= '<li><i style="background:' . $color . '"></i>' . $e((string) $slice['name']) . ' · '
                . $e(reportPct((float) $slice['share'], $m['lang'])) . '</li>';
        }
        $channels = '<section class="block"><h2>' . $e($t['channels']) . '</h2><div class="mix">' . $bar
            . '</div><ul class="mixlegend">' . $legend . '</ul></section>';
    }

    $chips = '';
    foreach ($m['queries'] as $q) {
        $chips .= '<span class="chip">' . $e((string) $q) . '</span>';
    }
    $queries = $chips !== '' ? '<section class="block"><h2>' . $e($t['queries']) . '</h2><p class="chips">' . $chips . '</p></section>' : '';

    $wins = '';
    foreach ($m['wins'] as $w) {
        $wins .= '<li><span class="dot"></span><div><strong>' . $e((string) $w['title']) . '</strong>'
            . (($w['line'] ?? '') !== '' ? '<p>' . $e((string) $w['line']) . '</p>' : '') . '</div></li>';
    }
    $winsBlock = $wins !== '' ? '<section class="block"><h2>' . $e($t['best']) . '</h2><ul class="wins">' . $wins . '</ul></section>' : '';

    $done = '';
    foreach ($m['done'] as $d) {
        $done .= '<li>' . $e((string) $d) . '</li>';
    }

    $opps = '';
    foreach ($m['opps'] as $o) {
        $opps .= '<article class="opp">' . reportIcon((string) $o['category']) . '<div><strong>' . $e((string) $o['title'])
            . '</strong><p>' . $e((string) $o['line']) . '</p></div>'
            . (($o['number'] ?? '') !== '' ? '<em>' . $e((string) $o['number']) . '</em>' : '') . '</article>';
    }
    if ($opps === '') {
        $opps = '<p class="muted">' . $e($t['nextEmpty']) . '</p>';
    }

    $css = <<<CSS
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #faf7f1; color: #1f1c17; }
    body { font-family: "Segoe UI", system-ui, sans-serif; font-size: 13px; line-height: 1.5; }
    .mast { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; background: #1f5f4a; color: #faf7f1; padding: 28px 30px 24px; }
    .kicker { margin: 0 0 8px; letter-spacing: .28em; text-transform: uppercase; font-size: 10px; color: #e6d3a8; }
    h1 { margin: 0; font-size: 32px; font-weight: 560; letter-spacing: -.03em; line-height: 1; }
    .who { margin: 8px 0 0; color: #dbe8e1; }
    .when { margin: 0; text-align: right; color: #cfe0d7; font-size: 12px; }
    .page { padding: 24px 30px 8px; }
    .page2 { break-before: page; page-break-before: always; }
    .headline { margin: 0 0 18px; font-size: 19px; line-height: 1.35; font-weight: 520; letter-spacing: -.01em; max-width: 34em; }
    .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
    .kpi { background: #fff; border-radius: 10px; padding: 14px 14px 12px; min-height: 100px; box-shadow: 0 1px 0 #ece6da; }
    .kpi span { display: block; font-size: 10px; letter-spacing: .08em; text-transform: uppercase; color: #7a7266; }
    .kpi strong { display: block; margin-top: 8px; font-size: 28px; letter-spacing: -.03em; font-weight: 620; line-height: 1; }
    .kpi em { display: inline-block; margin-top: 10px; font-style: normal; font-size: 11px; padding: 3px 8px; border-radius: 999px; }
    .kpi em b { font-weight: 650; }
    .t-up { background: #e3efe9; color: #1f5f4a; }
    .t-flat { background: #f1ece2; color: #5f574b; }
    .t-soft { background: #f6ecd9; color: #7a5a1e; }
    h2 { margin: 22px 0 10px; font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: #1f5f4a; }
    .legend { display: flex; gap: 14px; align-items: center; margin: 4px 0 0; color: #6b6458; font-size: 11px; }
    .sw { width: 10px; height: 10px; display: inline-block; margin-right: 4px; border-radius: 2px; }
    .sw--now { background: #1f5f4a; }
    .sw--prev { background: #ddd3c2; }
    .mix { display: flex; height: 14px; background: #ece6da; overflow: hidden; border-radius: 999px; }
    .mix span { display: block; height: 100%; }
    .mixlegend { display: flex; flex-wrap: wrap; gap: 8px 16px; list-style: none; margin: 8px 0 0; padding: 0; font-size: 12px; }
    .mixlegend i { width: 8px; height: 8px; display: inline-block; margin-right: 6px; border-radius: 50%; }
    .chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 0; }
    .chip { background: #fff; padding: 6px 12px; font-size: 13px; border-radius: 999px; box-shadow: 0 1px 0 #ece6da; }
    .wins { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
    .wins li { display: grid; grid-template-columns: 14px 1fr; gap: 10px; background: #fff; padding: 12px 14px; border-radius: 10px; break-inside: avoid; }
    .wins .dot { width: 10px; height: 10px; margin-top: 5px; border-radius: 50%; background: #1f5f4a; }
    .wins strong { font-size: 14px; }
    .wins p { margin: 2px 0 0; color: #6b6458; }
    .done { margin: 0; padding: 0 0 0 18px; }
    .done li { margin: 0 0 7px; }
    .opp { display: grid; grid-template-columns: 26px 1fr auto; gap: 12px; align-items: start; background: #fff; padding: 14px; margin: 0 0 8px; border-radius: 10px; color: #1f5f4a; break-inside: avoid; page-break-inside: avoid; }
    .opp div { color: #1f1c17; }
    .opp strong { display: block; font-size: 14px; }
    .opp p { margin: 3px 0 0; color: #5f574b; }
    .opp em { font-style: normal; font-weight: 650; color: #1f5f4a; text-align: right; max-width: 15ch; font-size: 12px; }
    .muted { color: #7a7266; }
    .sign { display: flex; gap: 14px; align-items: center; margin-top: 22px; background: #1f5f4a; color: #faf7f1; padding: 16px 18px; border-radius: 12px; }
    .mark { width: 42px; height: 42px; border-radius: 50%; border: 1px solid rgba(250,247,241,.4); display: flex; align-items: center; justify-content: center; color: #e6d3a8; letter-spacing: .06em; }
    .sign p { margin: 0; }
    .sign__name { font-size: 16px; }
    .sign__note { margin-top: 3px !important; color: #dbe8e1; font-size: 12px; }
CSS;

    return '<!DOCTYPE html><html lang="' . $e($m['lang']) . '"><head><meta charset="utf-8"><title>'
        . $e($t['title'] . ' — ' . $m['name']) . '</title><style>' . $css . '</style></head><body>'
        . '<header class="mast"><div><p class="kicker">' . $e($t['kicker']) . '</p><h1>' . $e($m['name'])
        . '</h1><p class="who">' . $e($t['pageHow']) . '</p></div><p class="when">' . $e($m['rangeLabel'])
        . '<br>' . $e($m['generated']) . '</p></header>'
        . '<section class="page"><p class="headline">' . $e($m['headline']) . '</p><div class="kpis" style="grid-template-columns:repeat('
        . max(1, count($m['kpis'])) . ',1fr)">' . $kpis . '</div>'
        . $chart . $winsBlock . '</section>'
        . '<section class="page page2">' . $channels . $queries
        . '<h2>' . $e($t['done']) . '</h2><ul class="done">' . $done . '</ul>'
        . '<h2>' . $e($t['next']) . '</h2>' . $opps
        . '<aside class="sign"><div class="mark">ER</div><div><p class="sign__name">Eduardo Rojas · Mono Studio</p>'
        . '<p class="sign__note">' . $e($t['sign']) . '</p></div></aside></section></body></html>';
}

function reportClientCompose(string $dataDir, array $sa, string $host, string $lang, bool $fresh): array
{
    $dossier = dossierBuild($dataDir, $sa, $host, $fresh, true);
    if (empty($dossier['ok'])) {
        throw new RuntimeException('No hay datos de Search Console para ' . $host . '. Abrí el sitio en SEO y pulsá Actualizar.');
    }
    $findings = findingsDetect($dossier);
    $t = reportClientCopy($lang);
    $copy = reportPhrases($lang);
    $name = (string) ($dossier['client']['name'] ?? $host);
    $windows = $dossier['windows'];

    $speedRaw = reportSpeedWord(is_array($dossier['psi']['home'] ?? null) ? $dossier['psi']['home'] : null, $copy);
    $speedKey = match ($speedRaw['word']) {
        $copy['speedFast'] => 'fast',
        $copy['speedSlow'] => 'slow',
        $copy['speedMid'] => 'mid',
        default => 'none',
    };
    $speed = $speedRaw + ['key' => $speedKey];

    [$kpis, $visits, $seen] = reportClientKpis($dossier, $lang, $t, $speed);
    $generatedAt = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));

    $html = reportClientRender([
        'lang' => $lang,
        't' => $t,
        'name' => $name,
        'headline' => reportClientHeadline($name, $visits, $seen, $t),
        'rangeLabel' => reportDateLabel($windows['start'], $lang) . ' — ' . reportDateLabel($windows['end'], $lang),
        'generated' => $t['generated'] . ' ' . reportDateLabel($generatedAt->format('Y-m-d'), $lang),
        'kpis' => $kpis,
        'bars' => reportBarsSvg(
            reportClickSeries($dossier['daily'] ?? [], $windows['start'], $windows['end']),
            reportClickSeries($dossier['daily'] ?? [], $windows['prevStart'], $windows['prevEnd'])
        ),
        'wins' => reportClientWins($dossier, $lang, $t, $speed),
        'channels' => reportChannelSlices($dossier, $lang, $copy),
        'queries' => reportTopQueries($dossier, 4),
        'done' => reportClientDone($dossier, $findings, $lang, $t),
        'opps' => reportClientOpportunities($findings, $dossier, $lang, $t),
    ]);
    $html = str_replace(['fill="#1d4ed8"', 'fill="#d9d3c7"'], ['fill="#1f5f4a"', 'fill="#ddd3c2"'], $html);

    $fileName = reportFileName($name);
    gscWriteJson(reportHtmlCacheFile($dataDir, $host, 'client'), [
        'version' => REPORT_CLIENT_VERSION,
        'lang' => $lang,
        'fileName' => $fileName,
        'html' => $html,
        'fetchedAt' => time(),
    ]);

    $gaMeta = is_array($dossier['gaMeta'] ?? null) ? $dossier['gaMeta'] : ['ok' => false, 'message' => ''];
    return [
        'html' => $html,
        'fileName' => $fileName,
        'lang' => $lang,
        'variant' => 'client',
        'clientName' => $name,
        'ga' => $gaMeta,
        'narrative' => 'template',
        'narrativeError' => '',
        'contactsMeasured' => !empty($dossier['sources']['keyEvents']),
        'coverage' => $dossier['sources'] ?? [],
        'findingCount' => count($findings),
    ];
}
