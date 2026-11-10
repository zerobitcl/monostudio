<?php
/**
 * Informe de cliente en dos páginas. Los números salen del dossier;
 * la prosa solo los nombra. El semáforo no lo elige el modelo.
 */

declare(strict_types=1);

if (!defined('ERS_LIBRARY')) {
    http_response_code(404);
    exit;
}

const REPORT_HTML_VERSION = '2026.11.05-client2';

function reportExtra(string $lang): array
{
    $all = [
        'es' => [
            'pageHow' => 'Cómo le fue este mes',
            'chart' => 'Día a día',
            'legendNow' => 'Este mes',
            'legendPrev' => 'Mes anterior',
            'channels' => 'De dónde llegan',
            'queries' => 'Cómo los buscan',
            'done' => 'Lo que hicimos',
            'now' => 'En marcha',
            'nowLine' => 'Son páginas públicas. Quedan corregidas en este ciclo, no para el mes que viene.',
            'nowLineOne' => 'Es una página pública. Queda corregida en este ciclo, no para el mes que viene.',
            'pageOne' => 'página',
            'found' => 'Lo que encontramos',
            'foundEmpty' => 'No hay un problema que cambie el mes. Seguimos midiendo.',
            'next' => 'Próximos 30 días',
            'contacts' => 'Contactos',
            'contactsShort' => 'contactos',
            'contactsNote' => 'Todavía no medimos WhatsApp, llamadas ni formularios.',
            'fromGooglePeople' => 'Llegaron desde Google',
            'clicks' => 'Clics en Google',
            'impressions' => 'Veces en Google',
            'pagesInGoogle' => 'Páginas en Google',
            'pagesShort' => 'páginas',
            'speed' => 'En el celular',
            'speedFast' => 'Rápida',
            'speedMid' => 'Media',
            'speedSlow' => 'Lenta',
            'speedNone' => 'Sin dato',
            'speedMobile' => 'celular',
            'lightGood' => 'Bien',
            'lightWatch' => 'Atención',
            'lightBad' => 'Urgente',
            'verdictGood' => 'El mes cierra bien: la demanda se sostiene y no hay un freno grave.',
            'verdictWatch' => 'El mes pide atención: hay un punto concreto para corregir.',
            'verdictBad' => 'El mes marca urgencia: algo está frenando que los encuentren o los contacten.',
            'impactGain' => 'Hasta %s clics más al mes',
            'impactLoss' => '%s clics menos que el mes anterior',
            'footnote' => 'Un clic en Google y una visita al sitio no son el mismo hecho.',
            'wordsShort' => 'palabras',
            'imagesShort' => 'fotos',
            'other' => 'Otros',
            'wa' => 'WhatsApp',
            'call' => 'llamadas',
            'form' => 'formularios',
            'catSpeed' => 'Velocidad',
            'catContent' => 'Contenido',
            'catContacts' => 'Contactos',
            'catGoogle' => 'Google',
        ],
        'en' => [
            'pageHow' => 'How the month went',
            'chart' => 'Day by day',
            'legendNow' => 'This month',
            'legendPrev' => 'Previous month',
            'channels' => 'Where they come from',
            'queries' => 'How they search',
            'done' => 'What we did',
            'now' => 'In progress',
            'nowLine' => 'These are public pages. They are fixed in this cycle, not left for next month.',
            'nowLineOne' => 'This is a public page. It is fixed in this cycle, not left for next month.',
            'pageOne' => 'page',
            'found' => 'What we found',
            'foundEmpty' => 'Nothing here would change the month. We keep measuring.',
            'next' => 'Next 30 days',
            'contacts' => 'Contacts',
            'contactsShort' => 'contacts',
            'contactsNote' => 'WhatsApp, calls and forms are not measured yet.',
            'fromGooglePeople' => 'Arrived from Google',
            'clicks' => 'Clicks on Google',
            'impressions' => 'Times on Google',
            'pagesInGoogle' => 'Pages on Google',
            'pagesShort' => 'pages',
            'speed' => 'On a phone',
            'speedFast' => 'Fast',
            'speedMid' => 'Average',
            'speedSlow' => 'Slow',
            'speedNone' => 'No data',
            'speedMobile' => 'phone',
            'lightGood' => 'On track',
            'lightWatch' => 'Attention',
            'lightBad' => 'Urgent',
            'verdictGood' => 'The month closes well: demand is holding and nothing serious is in the way.',
            'verdictWatch' => 'The month needs attention: there is one concrete thing to fix.',
            'verdictBad' => 'The month is urgent: something is stopping people from finding them or getting in touch.',
            'impactGain' => 'Up to %s more clicks a month',
            'impactLoss' => '%s fewer clicks than last month',
            'footnote' => 'A click on Google and a visit on the site are not the same event.',
            'wordsShort' => 'words',
            'imagesShort' => 'photos',
            'other' => 'Other',
            'wa' => 'WhatsApp',
            'call' => 'calls',
            'form' => 'forms',
            'catSpeed' => 'Speed',
            'catContent' => 'Content',
            'catContacts' => 'Contacts',
            'catGoogle' => 'Google',
        ],
        'pt' => [
            'pageHow' => 'Como foi o mês',
            'chart' => 'Dia a dia',
            'legendNow' => 'Este mês',
            'legendPrev' => 'Mês anterior',
            'channels' => 'De onde chegam',
            'queries' => 'Como buscam',
            'done' => 'O que fizemos',
            'now' => 'Em andamento',
            'nowLine' => 'São páginas públicas. Ficam corrigidas neste ciclo, não para o mês que vem.',
            'nowLineOne' => 'É uma página pública. Fica corrigida neste ciclo, não para o mês que vem.',
            'pageOne' => 'página',
            'found' => 'O que encontramos',
            'foundEmpty' => 'Não há um problema que mude o mês. Seguimos medindo.',
            'next' => 'Próximos 30 dias',
            'contacts' => 'Contatos',
            'contactsShort' => 'contatos',
            'contactsNote' => 'Ainda não medimos WhatsApp, ligações nem formulários.',
            'fromGooglePeople' => 'Chegaram pelo Google',
            'clicks' => 'Cliques no Google',
            'impressions' => 'Vezes no Google',
            'pagesInGoogle' => 'Páginas no Google',
            'pagesShort' => 'páginas',
            'speed' => 'No celular',
            'speedFast' => 'Rápida',
            'speedMid' => 'Média',
            'speedSlow' => 'Lenta',
            'speedNone' => 'Sem dado',
            'speedMobile' => 'celular',
            'lightGood' => 'Bem',
            'lightWatch' => 'Atenção',
            'lightBad' => 'Urgente',
            'verdictGood' => 'O mês fecha bem: a demanda se sustenta e não há um freio grave.',
            'verdictWatch' => 'O mês pede atenção: há um ponto concreto para corrigir.',
            'verdictBad' => 'O mês marca urgência: algo está segurando que os encontrem ou entrem em contato.',
            'impactGain' => 'Até %s cliques a mais por mês',
            'impactLoss' => '%s cliques a menos que o mês anterior',
            'footnote' => 'Um clique no Google e uma visita ao site não são o mesmo fato.',
            'wordsShort' => 'palavras',
            'imagesShort' => 'fotos',
            'other' => 'Outros',
            'wa' => 'WhatsApp',
            'call' => 'ligações',
            'form' => 'formulários',
            'catSpeed' => 'Velocidade',
            'catContent' => 'Conteúdo',
            'catContacts' => 'Contatos',
            'catGoogle' => 'Google',
        ],
    ];
    return $all[$lang] ?? $all['es'];
}

function reportPhrases(string $lang): array
{
    return array_merge(reportCopy($lang), reportExtra($lang));
}

function reportMoveCategory(string $category): string
{
    return match ($category) {
        'speed' => 'speed',
        'conversion' => 'contacts',
        'index', 'demand' => 'google',
        default => 'content',
    };
}

function reportCatLabel(string $category, array $copy): string
{
    return match ($category) {
        'speed' => $copy['catSpeed'],
        'contacts' => $copy['catContacts'],
        'google' => $copy['catGoogle'],
        default => $copy['catContent'],
    };
}

function reportFigure(array $figure, string $lang, array $copy): string
{
    $n = (int) ($figure['n'] ?? 0);
    $unit = (string) ($figure['unit'] ?? '');
    $dir = (string) ($figure['dir'] ?? 'risk');
    if ($n < 1 && $unit !== 'contacts') {
        return '';
    }
    if ($unit === 'contacts') {
        return reportNum((float) $n, $lang) . ' ' . $copy['contactsShort'];
    }
    if ($unit === 'clicks' && $dir === 'gain') {
        return reportFill($copy['impactGain'], [reportNum((float) $n, $lang)]);
    }
    if ($unit === 'clicks' && $dir === 'loss') {
        return reportFill($copy['impactLoss'], [reportNum((float) $n, $lang)]);
    }
    if ($unit === 'pages') {
        $word = (int) $n === 1 ? ($copy['pageOne'] ?? 'página') : $copy['pagesShort'];
        return reportNum((float) $n, $lang) . ' ' . $word;
    }
    if ($unit === 'impressions') {
        return reportNum((float) $n, $lang) . ' ' . $copy['times'];
    }
    if ($unit === 'percent') {
        return reportNum((float) $n, $lang) . '%';
    }
    if ($unit === 'words') {
        return reportNum((float) $n, $lang) . ' ' . $copy['wordsShort'];
    }
    if ($unit === 'images') {
        return reportNum((float) $n, $lang) . ' ' . $copy['imagesShort'];
    }
    if ($unit === 'chars' || $unit === 'count' || $unit === 'score') {
        return reportNum((float) $n, $lang);
    }
    return reportNum((float) $n, $lang);
}

function reportSpeedWord(?array $psi, array $copy): array
{
    if (!is_array($psi) || (($psi['score'] ?? null) === null && ($psi['field'] ?? '') === '')) {
        return ['word' => $copy['speedNone'], 'note' => '', 'tone' => 'flat'];
    }
    $score = is_numeric($psi['score'] ?? null) ? (int) $psi['score'] : null;
    $field = (string) ($psi['field'] ?? '');
    if ($field === 'FAST' || ($score !== null && $score >= 90)) {
        return ['word' => $copy['speedFast'], 'note' => $copy['speedMobile'], 'tone' => 'up'];
    }
    if ($field === 'SLOW' || ($score !== null && $score > 0 && $score < 50)) {
        return ['word' => $copy['speedSlow'], 'note' => $copy['speedMobile'], 'tone' => 'down'];
    }
    return ['word' => $copy['speedMid'], 'note' => $copy['speedMobile'], 'tone' => 'flat'];
}

function reportVerdictTone(array $dossier, array $findings): string
{
    $search = is_array($dossier['search'] ?? null) ? $dossier['search'] : [];
    $prev = (int) ($search['prevClicks'] ?? 0);
    $clickPct = $prev > 0 ? ((int) ($search['clicksDelta'] ?? 0)) / $prev : null;
    $ga = is_array($dossier['ga'] ?? null) ? $dossier['ga'] : null;
    $lead = $clickPct;
    if ($ga && !empty($ga['hasKeyEvents'])) {
        $prevK = (int) ($ga['keyEventsPrev'] ?? 0);
        $lead = $prevK > 0 ? (((int) $ga['keyEvents'] - $prevK) / $prevK) : $clickPct;
    }
    $sev3 = false;
    $sev2 = false;
    foreach ($findings as $f) {
        if (($f['audience'] ?? '') === 'cursor') {
            continue;
        }
        if (($f['category'] ?? '') === 'index') {
            continue;
        }
        if ((int) ($f['severity'] ?? 0) >= 3) {
            $sev3 = true;
        } elseif ((int) ($f['severity'] ?? 0) >= 2) {
            $sev2 = true;
        }
    }
    if ($sev3 || ($lead !== null && $lead <= -0.2)) {
        return 'bad';
    }
    if ($sev2 || ($lead !== null && $lead < -0.05)) {
        return 'watch';
    }
    return 'good';
}

function reportKpis(array $dossier, string $lang, array $copy): array
{
    $search = $dossier['search'];
    $ga = is_array($dossier['ga'] ?? null) ? $dossier['ga'] : null;
    $items = [];
    $usedClicks = false;
    if ($ga && !empty($ga['hasKeyEvents'])) {
        $now = (int) ($ga['contacts'] ?? $ga['keyEvents'] ?? 0);
        $prev = (int) ($ga['keyEventsPrev'] ?? 0);
        $deltaNow = (int) ($ga['keyEvents'] ?? $now);
        // La flecha solo si el número grande y el mes anterior miden lo mismo.
        $comparable = $now === $deltaNow;
        $bits = [];
        foreach (['whatsapp' => $copy['wa'], 'llamada' => $copy['call'], 'formulario' => $copy['form']] as $key => $label) {
            $n = (int) ($ga['contactBreakdown'][$key] ?? 0);
            if ($n > 0) {
                $bits[] = reportNum((float) $n, $lang) . ' ' . $label;
            }
        }
        $items[] = [
            'label' => $copy['contacts'],
            'value' => reportNum((float) $now, $lang),
            'delta' => $comparable
                ? reportDelta((float) ($deltaNow - $prev), 'count', $lang, $copy)
                : ['text' => '', 'tone' => 'flat'],
            'note' => implode(' · ', array_slice($bits, 0, 3)),
        ];
    } elseif ($ga && (int) ($ga['organic']['sessions'] ?? 0) > 0) {
        $now = (int) $ga['organic']['sessions'];
        $prev = (int) ($ga['organicPrevious']['sessions'] ?? 0);
        $items[] = [
            'label' => $copy['fromGooglePeople'],
            'value' => reportNum((float) $now, $lang),
            'delta' => reportDelta((float) ($now - $prev), 'count', $lang, $copy),
            'note' => $copy['contactsNote'],
        ];
    } else {
        $usedClicks = true;
        $items[] = [
            'label' => $copy['fromGooglePeople'],
            'value' => reportNum((float) ($search['clicks'] ?? 0), $lang),
            'delta' => reportDelta((float) ($search['clicksDelta'] ?? 0), 'count', $lang, $copy),
            'note' => $copy['contactsNote'],
        ];
    }
    if (!$usedClicks) {
        $items[] = [
            'label' => $copy['clicks'],
            'value' => reportNum((float) ($search['clicks'] ?? 0), $lang),
            'delta' => reportDelta((float) ($search['clicksDelta'] ?? 0), 'count', $lang, $copy),
            'note' => '',
        ];
    } else {
        $indexed = (int) ($dossier['inventory']['indexed'] ?? 0);
        $total = (int) ($dossier['inventory']['total'] ?? 0);
        $items[] = [
            'label' => $copy['pagesInGoogle'],
            'value' => $total > 0 ? (reportNum((float) $indexed, $lang) . '/' . reportNum((float) $total, $lang)) : '—',
            'delta' => ['text' => '', 'tone' => 'flat'],
            'note' => '',
        ];
    }
    $items[] = [
        'label' => $copy['impressions'],
        'value' => reportNum((float) ($search['impressions'] ?? 0), $lang),
        'delta' => reportDelta((float) ($search['impressionsDelta'] ?? 0), 'count', $lang, $copy),
        'note' => '',
    ];
    $speed = reportSpeedWord(is_array($dossier['psi']['home'] ?? null) ? $dossier['psi']['home'] : null, $copy);
    $items[] = [
        'label' => $copy['speed'],
        'value' => $speed['word'],
        'delta' => ['text' => $speed['note'], 'tone' => $speed['tone']],
        'note' => '',
    ];
    return $items;
}

function reportClickSeries(array $daily, string $start, string $end): array
{
    $map = [];
    foreach ($daily as $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = (string) ($row['date'] ?? '');
        if ($date !== '') {
            $map[$date] = (int) ($row['clicks'] ?? 0);
        }
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $last = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    if (!$dt || !$last) {
        return [];
    }
    $out = [];
    while ($dt <= $last && count($out) < 31) {
        $out[] = $map[$dt->format('Y-m-d')] ?? 0;
        $dt = $dt->modify('+1 day');
    }
    return $out;
}

function reportBarsSvg(array $current, array $previous): string
{
    $n = max(count($current), count($previous));
    if ($n < 2) {
        return '';
    }
    $max = 1;
    foreach ([$current, $previous] as $series) {
        foreach ($series as $v) {
            $max = max($max, (int) $v);
        }
    }
    $w = 640;
    $h = 86;
    $bw = ($w - 8) / $n;
    $bars = '';
    for ($i = 0; $i < $n; $i++) {
        $x = 4 + ($i * $bw);
        $pw = max(1.2, $bw - 1.6);
        $ph = (($previous[$i] ?? 0) / $max) * 68;
        $ch = (($current[$i] ?? 0) / $max) * 68;
        $bars .= '<rect x="' . round($x, 1) . '" y="' . round(76 - $ph, 1) . '" width="' . round($pw, 1) . '" height="' . round(max($ph, 0), 1) . '" fill="#d9d3c7"/>';
        $bars .= '<rect x="' . round($x, 1) . '" y="' . round(76 - $ch, 1) . '" width="' . round($pw * 0.62, 1) . '" height="' . round(max($ch, 0), 1) . '" fill="#1d4ed8"/>';
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="86" role="img" aria-hidden="true">' . $bars . '</svg>';
}

function reportChannelSlices(array $dossier, string $lang, array $copy): array
{
    $ga = $dossier['ga'] ?? null;
    if (!is_array($ga)) {
        return [];
    }
    $rows = [];
    $total = 0;
    foreach ($ga['channels'] ?? [] as $ch) {
        if (!is_array($ch)) {
            continue;
        }
        $n = (int) ($ch['sessions'] ?? 0);
        if ($n < 1) {
            continue;
        }
        $rows[] = ['name' => reportChannelName((string) ($ch['name'] ?? ''), $lang), 'n' => $n];
        $total += $n;
    }
    if ($total < 1) {
        return [];
    }
    usort($rows, static fn ($a, $b) => $b['n'] <=> $a['n']);
    $top = array_slice($rows, 0, 4);
    $used = array_sum(array_column($top, 'n'));
    if ($total - $used > 0) {
        $top[] = ['name' => $copy['other'], 'n' => $total - $used];
    }
    foreach ($top as &$slice) {
        $slice['share'] = $slice['n'] / $total;
    }
    unset($slice);
    return $top;
}

function reportTopQueries(array $dossier, int $limit = 3): array
{
    $buckets = $dossier['keywords']['buckets'] ?? [];
    $out = [];
    foreach (array_merge($buckets['top'] ?? [], $buckets['striking'] ?? []) as $row) {
        if (!is_array($row) || !empty($row['brand'])) {
            continue;
        }
        $q = trim((string) ($row['q'] ?? ''));
        if ($q === '' || in_array($q, $out, true)) {
            continue;
        }
        $out[] = $q;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function reportIcon(string $category): string
{
    $paths = [
        'speed' => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/>',
        'contacts' => '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7a2.5 2.5 0 0 1-2.5 2.5H9l-4 3v-3.2A2.5 2.5 0 0 1 4 13.5v-7z"/>',
        'google' => '<circle cx="11" cy="11" r="6"/><path d="m20 20-3.8-3.8"/>',
        'content' => '<path d="M6 3.5h8l4 4V20a1.5 1.5 0 0 1-1.5 1.5h-10.5A1.5 1.5 0 0 1 4.5 20V5A1.5 1.5 0 0 1 6 3.5z"/><path d="M14 3.5V8h4"/>',
    ];
    $d = $paths[$category] ?? $paths['content'];
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">' . $d . '</svg>';
}

function reportJoinNames(array $names, string $lang): string
{
    $conj = ['es' => 'y', 'en' => 'and', 'pt' => 'e'][$lang] ?? 'y';
    $names = array_values(array_filter($names, static fn ($n) => $n !== ''));
    if (count($names) <= 1) {
        return $names[0] ?? '';
    }
    $last = array_pop($names);
    return implode(', ', $names) . ' ' . $conj . ' ' . $last;
}

/** Una sola tarjeta: las páginas públicas fuera de Google se corrigen ahora. */
function reportIndexNow(array $findings, string $lang, array $copy): ?array
{
    $rows = array_values(array_filter($findings, static function ($f) {
        return ($f['category'] ?? '') === 'index'
            && in_array($f['kind'] ?? '', ['noindex', 'blocked', 'robotsMeta', 'canonical'], true)
            && !findingsIsPrivatePath((string) ($f['path'] ?? '/'));
    }));
    $sitemap = array_values(array_filter($findings, static fn ($f) => ($f['kind'] ?? '') === 'sitemap'));
    if ($rows === [] && $sitemap === []) {
        return null;
    }
    if ($rows === []) {
        $lines = $sitemap[0]['lines'][$lang] ?? $sitemap[0]['lines']['es'];
        return ['title' => $lines['title'], 'line' => $lines['line'], 'number' => ''];
    }
    $names = [];
    foreach ($rows as $f) {
        $names[] = findingsPublicName((string) ($f['path'] ?? '/'));
    }
    $names = array_values(array_unique($names));
    $n = count($names);
    $shown = array_slice($names, 0, 4);
    $title = reportJoinNames($shown, $lang);
    if ($n > 4) {
        $title .= ' +' . ($n - 4);
    }
    return [
        'title' => $title,
        'line' => $n === 1 ? $copy['nowLineOne'] : $copy['nowLine'],
        'number' => reportNum((float) $n, $lang) . ' ' . ($n === 1 ? $copy['pageOne'] : $copy['pagesShort']),
    ];
}

function reportNarrative(string $dataDir, string $host, string $lang, array $brief, string $signature, bool $fresh): array
{
    $file = $dataDir . '/report-narrative-' . md5($host) . '.json';
    $cached = gscReadJson($file, []);
    if (
        !$fresh
        && ($cached['signature'] ?? '') === $signature
        && ($cached['lang'] ?? '') === $lang
        && ($cached['version'] ?? '') === REPORT_HTML_VERSION
        && is_array($cached['narrative'] ?? null)
    ) {
        return $cached['narrative'] + ['source' => 'cache', 'error' => ''];
    }

    $cfg = ersLoadGeminiConfig();
    if ($cfg['apiKey'] === '') {
        return ['source' => 'template', 'error' => '', 'verdict' => '', 'findings' => [], 'moves' => []];
    }

    $system = 'Redactás el informe que Mono Studio le entrega al dueño del negocio. '
        . 'Idioma obligatorio: ' . reportLangName($lang) . '. No mezcles idiomas. '
        . 'No inventes cifras, búsquedas ni páginas. No pongas números en las frases: el número va aparte. '
        . 'Sin jerga (nada de CTR, indexación, Search Console, API, LCP, severidad). '
        . 'verdict: una frase, acorde al tono que viene en el JSON (good, watch o bad). No cambies el tono. '
        . 'No hables de páginas fuera de Google ni de indexación: si hay páginas públicas sin mostrar, ya se están corrigiendo y no son un plan de 30 días. '
        . 'findings: una entrada por cada id del JSON, título corto y una frase de qué vamos a hacer. '
        . 'moves: máximo 3 trabajos de los próximos 30 días, cada uno con category speed, content, contacts o google. '
        . 'El título de un move no puede ser igual al título de un finding. Es el trabajo, en verbo. '
        . 'Texto plano, sin markdown.';

    $empty = ['source' => 'template', 'error' => '', 'verdict' => '', 'findings' => [], 'moves' => []];
    try {
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}']],
            ]],
            // Informe cliente: thinking low para caber en el timeout corto del proxy.
            'generationConfig' => array_merge([
                'temperature' => 0.3,
                'maxOutputTokens' => 1800,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'verdict' => ['type' => 'string'],
                        'findings' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'line' => ['type' => 'string'],
                            ],
                            'required' => ['id', 'title', 'line'],
                        ]],
                        'moves' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'detail' => ['type' => 'string'],
                                'category' => ['type' => 'string', 'enum' => ['speed', 'content', 'contacts', 'google']],
                            ],
                            'required' => ['title', 'detail', 'category'],
                        ]],
                    ],
                    'required' => ['verdict', 'findings', 'moves'],
                ],
            ], ersGeminiThinkingConfig($cfg, 'low')),
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
        foreach ($brief['findings'] ?? [] as $row) {
            $known[(string) ($row['id'] ?? '')] = true;
        }
        $findings = [];
        foreach ($raw['findings'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || !isset($known[$id]) || isset($findings[$id])) {
                continue;
            }
            $findings[$id] = [
                'title' => reportPlain((string) ($row['title'] ?? ''), 90),
                'line' => reportPlain((string) ($row['line'] ?? ''), 220),
            ];
        }
        $moves = [];
        foreach ($raw['moves'] ?? [] as $row) {
            if (!is_array($row) || count($moves) >= 3) {
                continue;
            }
            $title = reportPlain((string) ($row['title'] ?? ''), 90);
            $detail = reportPlain((string) ($row['detail'] ?? ''), 220);
            $category = (string) ($row['category'] ?? 'content');
            if ($title === '' || $detail === '' || !in_array($category, ['speed', 'content', 'contacts', 'google'], true)) {
                continue;
            }
            $moves[] = ['title' => $title, 'detail' => $detail, 'category' => $category];
        }
        $narrative = [
            'verdict' => reportPlain((string) ($raw['verdict'] ?? ''), 220),
            'findings' => $findings,
            'moves' => $moves,
            'source' => 'gemini',
            'error' => '',
        ];
        gscWriteJson($file, [
            'signature' => $signature,
            'lang' => $lang,
            'version' => REPORT_HTML_VERSION,
            'narrative' => $narrative,
        ]);
        return $narrative;
    } catch (Throwable $e) {
        $empty['error'] = reportPublicError($e->getMessage());
        return $empty;
    }
}

function reportRender(array $model): string
{
    $lang = (string) $model['lang'];
    $copy = $model['copy'];
    $e = 'reportE';
    $tone = (string) ($model['tone'] ?? 'watch');
    $lightWord = ['good' => $copy['lightGood'], 'watch' => $copy['lightWatch'], 'bad' => $copy['lightBad']][$tone] ?? $copy['lightWatch'];

    $kpis = '';
    foreach ($model['kpis'] as $kpi) {
        $note = (string) ($kpi['note'] ?? '');
        $delta = (string) ($kpi['delta']['text'] ?? '');
        $kpis .= '<article class="kpi"><span>' . $e((string) $kpi['label']) . '</span><strong>'
            . $e((string) $kpi['value']) . '</strong><em class="' . $e((string) ($kpi['delta']['tone'] ?? 'flat')) . '">'
            . $e($delta) . '</em>'
            . ($note !== '' ? '<small>' . $e($note) . '</small>' : '')
            . '</article>';
    }

    $chart = '';
    if (($model['bars'] ?? '') !== '') {
        $chart = '<section class="block"><h2>' . $e($copy['chart']) . '</h2>'
            . $model['bars']
            . '<p class="legend"><i class="swatch swatch--now"></i>' . $e($copy['legendNow'])
            . '<i class="swatch swatch--prev"></i>' . $e($copy['legendPrev']) . '</p></section>';
    }

    $channels = '';
    if (($model['channels'] ?? []) !== []) {
        $bar = '';
        $legend = '';
        $colors = ['#1d4ed8', '#12141c', '#93c5fd', '#a8a29e', '#e7e5e4'];
        foreach ($model['channels'] as $i => $slice) {
            $width = max(2, round(((float) $slice['share']) * 100, 1));
            $color = $colors[$i] ?? '#e7e5e4';
            $bar .= '<span style="width:' . $width . '%;background:' . $color . '"></span>';
            $legend .= '<li><i style="background:' . $color . '"></i>' . $e((string) $slice['name'])
                . ' · ' . $e(reportPct((float) $slice['share'], $lang)) . '</li>';
        }
        $channels = '<section class="block"><h2>' . $e($copy['channels']) . '</h2><div class="mixbar">'
            . $bar . '</div><ul class="mixlegend">' . $legend . '</ul></section>';
    }

    $queries = '';
    foreach ($model['queries'] as $q) {
        $queries .= '<span class="chip">' . $e((string) $q) . '</span>';
    }
    $queryBlock = $queries !== ''
        ? '<section class="block"><h2>' . $e($copy['queries']) . '</h2><p class="chips">' . $queries . '</p></section>'
        : '';

    $done = '';
    foreach ($model['tasks'] as $task) {
        $done .= '<li>' . $e((string) $task['title']) . '</li>';
    }
    $doneBlock = $done !== ''
        ? '<h2>' . $e($copy['done']) . '</h2><ul class="done">' . $done . '</ul>'
        : '';

    $now = $model['now'] ?? null;
    $nowBlock = '';
    if (is_array($now) && ($now['title'] ?? '') !== '') {
        $num = (string) ($now['number'] ?? '');
        $nowBlock = '<h2>' . $e($copy['now']) . '</h2><article class="found now"><div><strong>'
            . $e((string) $now['title']) . '</strong><p>' . $e((string) ($now['line'] ?? ''))
            . '</p></div>' . ($num !== '' ? '<em>' . $e($num) . '</em>' : '') . '</article>';
    }

    $found = '';
    foreach ($model['found'] as $item) {
        $num = (string) ($item['number'] ?? '');
        $found .= '<article class="found"><div><strong>' . $e((string) $item['title']) . '</strong><p>'
            . $e((string) $item['line']) . '</p></div>'
            . ($num !== '' ? '<em>' . $e($num) . '</em>' : '')
            . '</article>';
    }
    if ($found === '') {
        $found = '<p class="muted">' . $e($copy['foundEmpty']) . '</p>';
    }

    $moves = '';
    foreach ($model['moves'] as $move) {
        $cat = (string) ($move['category'] ?? 'content');
        $moves .= '<article class="move">' . reportIcon($cat) . '<div><span>' . $e(reportCatLabel($cat, $copy))
            . '</span><strong>' . $e((string) $move['title']) . '</strong><p>' . $e((string) $move['detail']) . '</p></div></article>';
    }

    $css = <<<CSS
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #f4f1ea; color: #1c1915; }
    body { font-family: "Segoe UI", system-ui, sans-serif; font-size: 13px; line-height: 1.45; }
    .sheet { padding: 0; }
    .mast { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; background: #12141c; color: #f6f3ec; padding: 26px 28px 22px; }
    .kicker { margin: 0 0 8px; letter-spacing: .28em; text-transform: uppercase; font-size: 10px; color: #93c5fd; }
    h1 { margin: 0; font-size: 32px; font-weight: 560; letter-spacing: -.03em; line-height: 1; }
    .who { margin: 8px 0 0; color: #d9d3c7; }
    .when { margin: 0; text-align: right; color: #b7b1a6; font-size: 12px; }
    .page { padding: 22px 28px 8px; }
    .page2 { break-before: page; page-break-before: always; }
    .light { display: flex; gap: 12px; align-items: flex-start; margin: 0 0 16px; }
    .light i { width: 14px; height: 14px; border-radius: 50%; margin-top: 3px; flex: none; border: 2px solid #1c1915; }
    .light.is-good i { background: #0d6b45; }
    .light.is-watch i { background: #a16207; }
    .light.is-bad i { background: #9d2c2c; }
    .light strong { display: block; font-size: 13px; letter-spacing: .08em; text-transform: uppercase; }
    .light p { margin: 2px 0 0; font-size: 16px; line-height: 1.35; max-width: 62ch; }
    .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
    .kpi { background: #fff; border-top: 3px solid #1d4ed8; padding: 12px 12px 10px; min-height: 92px; }
    .kpi span { display: block; font-size: 9px; letter-spacing: .08em; text-transform: uppercase; color: #78716c; }
    .kpi strong { display: block; margin-top: 6px; font-size: 26px; letter-spacing: -.03em; font-weight: 620; line-height: 1; }
    .kpi em, .kpi small { display: block; margin-top: 6px; font-style: normal; font-size: 11px; color: #78716c; }
    .kpi em.up { color: #0d6b45; }
    .kpi em.down { color: #9d2c2c; }
    h2 { margin: 18px 0 8px; font-size: 11px; letter-spacing: .16em; text-transform: uppercase; }
    .legend { display: flex; gap: 14px; align-items: center; margin: 4px 0 0; color: #57534e; font-size: 11px; }
    .swatch { width: 10px; height: 10px; display: inline-block; margin-right: 4px; }
    .swatch--now { background: #1d4ed8; }
    .swatch--prev { background: #d9d3c7; }
    .mixbar { display: flex; height: 14px; background: #e7e5e4; overflow: hidden; }
    .mixbar span { display: block; height: 100%; }
    .mixlegend { display: flex; flex-wrap: wrap; gap: 8px 16px; list-style: none; margin: 8px 0 0; padding: 0; font-size: 12px; }
    .mixlegend i { width: 8px; height: 8px; display: inline-block; margin-right: 6px; }
    .chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 0; }
    .chip { background: #fff; padding: 6px 10px; font-size: 13px; }
    .done { margin: 0; padding: 0 0 0 18px; }
    .done li { margin: 0 0 6px; }
    .found { display: flex; justify-content: space-between; gap: 16px; background: #fff; padding: 12px 14px; margin: 0 0 8px; break-inside: avoid; page-break-inside: avoid; }
    .found.now { border-left: 3px solid #12141c; }
    .found strong { display: block; font-size: 15px; }
    .found p { margin: 4px 0 0; max-width: 62ch; }
    .found em { font-style: normal; font-weight: 650; color: #1d4ed8; text-align: right; max-width: 16ch; }
    .move { display: grid; grid-template-columns: 28px 1fr; gap: 10px; background: #fff; padding: 12px 14px; margin: 0 0 8px; break-inside: avoid; page-break-inside: avoid; }
    .move span { display: block; font-size: 10px; letter-spacing: .12em; text-transform: uppercase; color: #1d4ed8; }
    .move strong { display: block; margin-top: 2px; }
    .move p { margin: 3px 0 0; }
    .muted { color: #78716c; }
    .foot { margin-top: 18px; }
    .note { margin: 0 0 14px; color: #78716c; font-size: 11px; }
    .sign { display: flex; gap: 14px; align-items: center; background: #12141c; color: #f6f3ec; padding: 16px; }
    .mark { width: 42px; height: 42px; border: 1px solid rgba(246,243,236,.35); display: flex; align-items: center; justify-content: center; color: #93c5fd; letter-spacing: .06em; }
    .sign__name { margin: 0; font-size: 16px; }
    .sign__role { margin: 2px 0 0; letter-spacing: .16em; text-transform: uppercase; font-size: 10px; color: #93c5fd; }
    .sign__note { margin: 4px 0 0; color: #d9d3c7; font-size: 12px; max-width: 52ch; }
CSS;

    return '<!DOCTYPE html><html lang="' . $e($lang) . '"><head><meta charset="utf-8"><title>'
        . $e($copy['title'] . ' — ' . $model['name']) . '</title><style>' . $css . '</style></head><body>'
        . '<article class="sheet"><header class="mast"><div><p class="kicker">' . $e($copy['kicker']) . '</p><h1>'
        . $e($model['name']) . '</h1><p class="who">' . $e($copy['pageHow']) . '</p></div><p class="when">'
        . $e($model['rangeLabel']) . '<br>' . $e($model['generated']) . '</p></header>'
        . '<section class="page"><div class="light is-' . $e($tone) . '"><i></i><div><strong>' . $e($lightWord)
        . '</strong><p>' . $e((string) $model['verdict']) . '</p></div></div>'
        . '<div class="kpis">' . $kpis . '</div>' . $chart . $channels . $queryBlock . '</section>'
        . '<section class="page page2">' . $nowBlock . $doneBlock
        . '<h2>' . $e($copy['found']) . '</h2>' . $found
        . ($moves !== '' ? '<h2>' . $e($copy['next']) . '</h2>' . $moves : '')
        . '<div class="foot"><p class="note">' . $e($copy['footnote']) . ' · ' . $e($model['host']) . '</p>'
        . '<aside class="sign"><div class="mark">ER</div><div><p class="sign__name">Eduardo Rojas</p><p class="sign__role">Mono Studio</p>'
        . '<p class="sign__note">' . $e($copy['signNote']) . '</p></div></aside></div></section></article></body></html>';
}

function reportCompose(string $dataDir, array $sa, string $host, string $lang, bool $fresh): array
{
    $dossier = dossierBuild($dataDir, $sa, $host, $fresh, true);
    if (empty($dossier['ok'])) {
        throw new RuntimeException('No hay datos de Search Console para ' . $host . '. Abrí el sitio en SEO y pulsá Actualizar.');
    }

    $findings = findingsDetect($dossier);
    $top = findingsTop($findings, 3);
    $tone = reportVerdictTone($dossier, $findings);
    $copy = reportPhrases($lang);
    $now = reportIndexNow($findings, $lang, $copy);
    $name = (string) ($dossier['client']['name'] ?? $host);
    $windows = $dossier['windows'];
    $kpis = reportKpis($dossier, $lang, $copy);

    $verdictKey = ['good' => 'verdictGood', 'watch' => 'verdictWatch', 'bad' => 'verdictBad'][$tone];
    $fallbackVerdict = (string) $copy[$verdictKey];
    $fallbackMoves = [];
    $briefFindings = [];
    foreach ($top as $f) {
        $cat = reportMoveCategory((string) ($f['category'] ?? 'onpage'));
        $lines = $f['lines'][$lang] ?? $f['lines']['es'];
        $briefFindings[] = [
            'id' => $f['id'],
            'title' => $lines['title'],
            'line' => $lines['line'],
            'category' => $cat,
        ];
        if (count($fallbackMoves) < 3) {
            $fallbackMoves[] = [
                'title' => $copy['cat' . ucfirst($cat)] ?? $lines['title'],
                'detail' => $lines['line'],
                'category' => $cat,
            ];
        }
    }

    $signature = md5(json_encode([
        REPORT_HTML_VERSION,
        $lang,
        $tone,
        $dossier['search'],
        $dossier['ga']['keyEvents'] ?? 0,
        array_column($top, 'id'),
        $now['title'] ?? '',
        $dossier['fingerprint'] ?? '',
    ], JSON_UNESCAPED_UNICODE) ?: '');

    $narrative = reportNarrative($dataDir, $host, $lang, [
        'client' => $name,
        'tone' => $tone,
        'kpis' => array_map(static fn ($k) => [
            'label' => $k['label'],
            'value' => $k['value'],
            'delta' => $k['delta']['text'] ?? '',
        ], $kpis),
        'findings' => $briefFindings,
    ], $signature, $fresh);

    $found = [];
    foreach ($top as $f) {
        $lines = $f['lines'][$lang] ?? $f['lines']['es'];
        $over = $narrative['findings'][$f['id']] ?? null;
        $found[] = [
            'title' => (is_array($over) && ($over['title'] ?? '') !== '') ? $over['title'] : $lines['title'],
            'line' => (is_array($over) && ($over['line'] ?? '') !== '') ? $over['line'] : $lines['line'],
            'number' => reportFigure(is_array($f['figure'] ?? null) ? $f['figure'] : [], $lang, $copy),
            'category' => reportMoveCategory((string) ($f['category'] ?? '')),
        ];
    }
    $moves = $narrative['moves'] ?? [];
    if (!is_array($moves) || $moves === []) {
        $moves = $fallbackMoves;
    }
    $verdict = trim((string) ($narrative['verdict'] ?? ''));
    if ($verdict === '') {
        $verdict = $fallbackVerdict;
    }

    $generatedAt = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
    $model = [
        'lang' => $lang,
        'copy' => $copy,
        'name' => $name,
        'host' => $host,
        'tone' => $tone,
        'verdict' => $verdict,
        'rangeLabel' => reportDateLabel($windows['start'], $lang) . ' — ' . reportDateLabel($windows['end'], $lang),
        'generated' => $copy['generated'] . ' ' . reportDateLabel($generatedAt->format('Y-m-d'), $lang),
        'kpis' => $kpis,
        'bars' => reportBarsSvg(
            reportClickSeries($dossier['daily'] ?? [], $windows['start'], $windows['end']),
            reportClickSeries($dossier['daily'] ?? [], $windows['prevStart'], $windows['prevEnd'])
        ),
        'channels' => reportChannelSlices($dossier, $lang, $copy),
        'queries' => reportTopQueries($dossier, 3),
        'tasks' => array_slice($dossier['tasks'] ?? [], 0, 4),
        'now' => $now,
        'found' => $found,
        'moves' => array_slice($moves, 0, 3),
    ];

    $html = reportRender($model);
    $fileName = 'mono-studio-' . reportFileName($name);
    gscWriteJson(reportHtmlCacheFile($dataDir, $host), [
        'version' => REPORT_HTML_VERSION,
        'signature' => $signature,
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
        'clientName' => $name,
        'signature' => $signature,
        'ga' => $gaMeta,
        'psi' => [
            'ok' => ($dossier['psi']['home'] ?? null) !== null,
            'configured' => dossierSecret($dataDir, 'psi.json') !== '',
        ],
        'narrative' => (string) ($narrative['source'] ?? 'template'),
        'narrativeError' => (string) ($narrative['error'] ?? ''),
        'contactsMeasured' => !empty($dossier['sources']['keyEvents']),
        'coverage' => $dossier['sources'] ?? [],
        'findingCount' => count($findings),
    ];
}
