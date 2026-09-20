<?php
/**
 * Mono Studio OS — helpers compartidos (store, audit, acciones atómicas).
 */

declare(strict_types=1);

function ersDataDir(): string
{
    return dirname(__DIR__) . '/data';
}

function ersStorePath(): string
{
    return ersDataDir() . '/store.json';
}

function ersAuditPath(): string
{
    return ersDataDir() . '/audit.jsonl';
}

function ersClip(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function ersUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function ersSanitizeNotes($notes): array
{
    if (!is_array($notes)) {
        return [];
    }

    $out = [];
    foreach (array_slice($notes, 0, 200) as $note) {
        if (!is_array($note)) {
            continue;
        }
        $body = trim((string) ($note['body'] ?? ''));
        if ($body === '') {
            continue;
        }
        $out[] = [
            'id' => ersClip((string) ($note['id'] ?? ''), 64),
            'body' => ersClip($body, 4000),
            'createdAt' => (int) ($note['createdAt'] ?? 0),
            'kind' => ersClip((string) ($note['kind'] ?? ''), 24),
            'by' => ersClip((string) ($note['by'] ?? ''), 24),
        ];
    }
    return $out;
}

function ersSanitizeCheckIn($raw): ?array
{
    if (!is_array($raw)) {
        return null;
    }
    $status = strtolower(trim((string) ($raw['status'] ?? '')));
    if (!in_array($status, ['ok', 'follow_up', 'blocked'], true)) {
        return null;
    }
    return [
        'status' => $status,
        'note' => ersClip(trim((string) ($raw['note'] ?? '')), 4000),
        'at' => (int) ($raw['at'] ?? 0),
        'by' => ersClip((string) ($raw['by'] ?? 'user'), 24),
    ];
}

function ersSanitizeClients($clients): array
{
    if (!is_array($clients)) {
        return [];
    }

    $out = [];
    foreach ($clients as $client) {
        if (!is_array($client)) {
            continue;
        }
        $client['notes'] = ersSanitizeNotes($client['notes'] ?? []);
        $client['siteUrl'] = ersClip(trim((string) ($client['siteUrl'] ?? '')), 300);
        $checkIn = ersSanitizeCheckIn($client['lastCheckIn'] ?? null);
        if ($checkIn) {
            $client['lastCheckIn'] = $checkIn;
        } else {
            unset($client['lastCheckIn']);
        }
        $out[] = $client;
    }
    return $out;
}

function ersSanitizeTasks($tasks): array
{
    if (!is_array($tasks)) {
        return [];
    }

    $out = [];
    foreach (array_slice($tasks, 0, 500) as $task) {
        if (!is_array($task)) {
            continue;
        }
        $title = trim((string) ($task['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $out[] = [
            'id' => ersClip((string) ($task['id'] ?? ''), 64),
            'title' => ersClip($title, 240),
            'clientId' => ersClip((string) ($task['clientId'] ?? ''), 64),
            'kind' => ersClip((string) ($task['kind'] ?? 'manual'), 24),
            'ref' => ersClip((string) ($task['ref'] ?? ''), 500),
            'dueDate' => preg_match('#^\d{4}-\d{2}-\d{2}$#', (string) ($task['dueDate'] ?? ''))
                ? (string) $task['dueDate']
                : '',
            'createdAt' => (int) ($task['createdAt'] ?? 0),
            'doneAt' => (int) ($task['doneAt'] ?? 0),
        ];
    }
    return $out;
}

function ersEmptyStore(): array
{
    return ['clients' => [], 'requests' => [], 'tasks' => []];
}

function ersReadStore(?string $path = null): array
{
    $path = $path ?? ersStorePath();
    if (!file_exists($path)) {
        return ersEmptyStore();
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ersEmptyStore();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ersEmptyStore();
    }

    return [
        'clients' => ersSanitizeClients($data['clients'] ?? null),
        'requests' => is_array($data['requests'] ?? null) ? $data['requests'] : [],
        'tasks' => ersSanitizeTasks($data['tasks'] ?? null),
    ];
}

function ersWriteStore(array $data, ?string $path = null): bool
{
    $path = $path ?? ersStorePath();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        return false;
    }

    $payload = json_encode(
        [
            'clients' => $data['clients'] ?? [],
            'requests' => $data['requests'] ?? [],
            'tasks' => $data['tasks'] ?? [],
            'updatedAt' => gmdate('c'),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );

    if ($payload === false) {
        return false;
    }

    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
        return false;
    }

    return rename($tmp, $path);
}

function ersAuditAppend(array $entry): void
{
    $dir = ersDataDir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        return;
    }

    $row = array_merge(
        [
            'id' => ersUuid(),
            'ts' => gmdate('c'),
        ],
        $entry
    );
    $line = json_encode($row, JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return;
    }
    file_put_contents(ersAuditPath(), $line . "\n", FILE_APPEND | LOCK_EX);
}

function ersAuditRecent(int $limit = 40, string $clientId = ''): array
{
    $path = ersAuditPath();
    if (!file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $out = [];
    for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
        $row = json_decode($lines[$i], true);
        if (!is_array($row)) {
            continue;
        }
        if ($clientId !== '') {
            $argClient = (string) ($row['args']['clientId'] ?? '');
            $metaClient = (string) ($row['clientId'] ?? '');
            if ($argClient !== $clientId && $metaClient !== $clientId) {
                continue;
            }
        }
        $out[] = $row;
    }
    return $out;
}

function ersFindClientIndex(array $store, string $clientId): int
{
    foreach ($store['clients'] as $i => $client) {
        if (($client['id'] ?? '') === $clientId) {
            return (int) $i;
        }
    }
    return -1;
}

function ersHostOf(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return '';
    }
    return strtolower(preg_replace('/^www\./i', '', $host) ?? $host);
}

function ersClientPublic(array $client): array
{
    $notes = array_slice(array_reverse($client['notes'] ?? []), 0, 8);
    return [
        'id' => $client['id'] ?? '',
        'name' => $client['name'] ?? '',
        'valueCLP' => $client['valueCLP'] ?? 0,
        'planType' => $client['planType'] ?? '',
        'nextBillingDate' => $client['nextBillingDate'] ?? '',
        'phone' => $client['phone'] ?? '',
        'siteUrl' => $client['siteUrl'] ?? '',
        'inDevelopment' => !empty($client['inDevelopment']),
        'lastCheckIn' => $client['lastCheckIn'] ?? null,
        'notes' => array_map(static function ($n) {
            return [
                'id' => $n['id'] ?? '',
                'body' => $n['body'] ?? '',
                'createdAt' => $n['createdAt'] ?? 0,
                'kind' => $n['kind'] ?? '',
                'by' => $n['by'] ?? '',
            ];
        }, $notes),
        'noteCount' => count($client['notes'] ?? []),
    ];
}

function ersBuildAgenda(array $store): array
{
    $items = [];
    $now = new DateTimeImmutable('today');

    foreach ($store['clients'] as $client) {
        if (!empty($client['inDevelopment'])) {
            continue;
        }
        $date = (string) ($client['nextBillingDate'] ?? '');
        if (!preg_match('#^\d{4}-\d{2}-\d{2}$#', $date)) {
            continue;
        }
        $due = DateTimeImmutable::createFromFormat('Y-m-d', $date) ?: null;
        if (!$due) {
            continue;
        }
        $days = (int) $now->diff($due)->format('%r%a');
        if ($days > 7) {
            continue;
        }
        $items[] = [
            'type' => 'billing',
            'clientId' => $client['id'] ?? '',
            'clientName' => $client['name'] ?? '',
            'title' => 'Cobro · ' . ($client['name'] ?? ''),
            'dueDate' => $date,
            'daysUntil' => $days,
            'priority' => $days < 0 ? 5 : ($days <= 3 ? 4 : 2),
        ];
    }

    foreach ($store['requests'] as $req) {
        if (($req['status'] ?? '') !== 'activa') {
            continue;
        }
        $client = null;
        foreach ($store['clients'] as $c) {
            if (($c['id'] ?? '') === ($req['clientId'] ?? '')) {
                $client = $c;
                break;
            }
        }
        $items[] = [
            'type' => 'request',
            'id' => $req['id'] ?? '',
            'clientId' => $req['clientId'] ?? '',
            'clientName' => $client['name'] ?? '',
            'title' => $req['description'] ?? '',
            'createdAt' => $req['createdAt'] ?? 0,
            'priority' => 3,
        ];
    }

    foreach ($store['tasks'] as $task) {
        if (!empty($task['doneAt'])) {
            continue;
        }
        $clientName = '';
        foreach ($store['clients'] as $c) {
            if (($c['id'] ?? '') === ($task['clientId'] ?? '')) {
                $clientName = (string) ($c['name'] ?? '');
                break;
            }
        }
        $items[] = [
            'type' => 'task',
            'id' => $task['id'] ?? '',
            'clientId' => $task['clientId'] ?? '',
            'clientName' => $clientName,
            'title' => $task['title'] ?? '',
            'dueDate' => $task['dueDate'] ?? '',
            'kind' => $task['kind'] ?? 'manual',
            'priority' => 2,
        ];
    }

    usort($items, static fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));
    return array_slice($items, 0, 40);
}

function ersSeoSummary(string $host): array
{
    $host = strtolower(trim($host));
    $host = preg_replace('/^www\./i', '', $host) ?? $host;
    if ($host === '') {
        return ['error' => 'Host vacío'];
    }

    $cacheFile = ersDataDir() . '/gsc-cache-' . md5($host) . '.json';
    if (!file_exists($cacheFile)) {
        return [
            'host' => $host,
            'available' => false,
            'message' => 'Sin caché SEO. Abrí el módulo SEO y pulsá Actualizar para este sitio.',
        ];
    }

    $raw = file_get_contents($cacheFile);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return ['host' => $host, 'available' => false, 'message' => 'Caché SEO corrupta'];
    }

    $periods = is_array($data['periods'] ?? null) ? $data['periods'] : [];
    $period28 = null;
    foreach ($periods as $p) {
        if (($p['id'] ?? '') === '28d') {
            $period28 = $p;
            break;
        }
    }
    if (!$period28 && $periods) {
        $period28 = $periods[0];
    }

    $pages = [];
    foreach (array_slice($data['pages'] ?? [], 0, 12) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $pages[] = [
            'url' => $row['url'] ?? '',
            'clicks' => $row['current']['clicks'] ?? 0,
            'impressions' => $row['current']['impressions'] ?? 0,
            'ctr' => $row['current']['ctr'] ?? 0,
            'position' => $row['current']['position'] ?? 0,
            'deltaClicks' => $row['delta']['clicks'] ?? 0,
            'index' => $row['indexStatus']['label'] ?? '',
            'thermometer' => $row['thermometer']['label'] ?? '',
        ];
    }

    $signals = [];
    foreach (array_slice($data['signals'] ?? [], 0, 10) as $s) {
        if (!is_array($s)) {
            continue;
        }
        $signals[] = [
            'kind' => $s['kind'] ?? '',
            'title' => $s['title'] ?? '',
            'detail' => $s['detail'] ?? '',
            'severity' => $s['severity'] ?? 0,
            'url' => $s['url'] ?? '',
            'metric' => $s['metric'] ?? '',
        ];
    }

    $analysis = [];
    foreach (array_slice($data['analysis'] ?? [], 0, 6) as $a) {
        if (!is_array($a)) {
            continue;
        }
        $analysis[] = [
            'tone' => $a['tone'] ?? '',
            'title' => $a['title'] ?? '',
            'body' => $a['body'] ?? '',
        ];
    }

    return [
        'host' => $host,
        'available' => true,
        'fetchedAt' => $data['fetchedAt'] ?? null,
        'period28' => $period28,
        'inventory' => $data['inventory'] ?? null,
        'signals' => $signals,
        'analysis' => $analysis,
        'topPages' => $pages,
    ];
}

function ersBuildSeoReportMarkdown(string $host, array $summary, ?array $client): string
{
    $name = $client['name'] ?? $host;
    $lines = [
        '# Informe SEO — ' . $name,
        '',
        'Host: `' . $host . '`',
        'Generado: ' . gmdate('Y-m-d H:i') . ' UTC',
        '',
    ];

    if (empty($summary['available'])) {
        $lines[] = $summary['message'] ?? 'Sin datos SEO.';
        return implode("\n", $lines);
    }

    $t = $summary['period28']['totals'] ?? [];
    $d = $summary['period28']['delta'] ?? [];
    $lines[] = '## Últimos 28 días';
    $lines[] = sprintf(
        '- Clics: %s (Δ %s)',
        $t['clicks'] ?? 0,
        $d['clicks'] ?? 0
    );
    $lines[] = sprintf(
        '- Impresiones: %s (Δ %s)',
        $t['impressions'] ?? 0,
        $d['impressions'] ?? 0
    );
    $lines[] = sprintf('- CTR: %.1f%%', (($t['ctr'] ?? 0) * 100));
    $lines[] = sprintf('- Posición: %.1f', $t['position'] ?? 0);
    $lines[] = '';

    $inv = $summary['inventory'] ?? [];
    if ($inv) {
        $lines[] = '## Inventario';
        $lines[] = sprintf(
            '- Indexadas: %s/%s · Fuera: %s · Bloqueadas: %s · Con tráfico: %s',
            $inv['indexed'] ?? 0,
            $inv['checked'] ?? 0,
            $inv['notIndexed'] ?? 0,
            $inv['blocked'] ?? 0,
            $inv['withData'] ?? 0
        );
        $lines[] = '';
    }

    if (!empty($summary['analysis'])) {
        $lines[] = '## Lectura';
        foreach ($summary['analysis'] as $a) {
            $lines[] = '- **' . ($a['title'] ?? '') . '**: ' . ($a['body'] ?? '');
        }
        $lines[] = '';
    }

    if (!empty($summary['signals'])) {
        $lines[] = '## Señales';
        foreach ($summary['signals'] as $s) {
            $lines[] = sprintf(
                '- [S%s] %s — %s',
                $s['severity'] ?? 0,
                $s['title'] ?? '',
                $s['detail'] ?? ''
            );
        }
        $lines[] = '';
    }

    if (!empty($summary['topPages'])) {
        $lines[] = '## Páginas top';
        foreach ($summary['topPages'] as $p) {
            $lines[] = sprintf(
                '- %s · %s clics (Δ %s) · pos %s · %s',
                $p['url'] ?? '',
                $p['clicks'] ?? 0,
                $p['deltaClicks'] ?? 0,
                isset($p['position']) ? number_format((float) $p['position'], 1) : '—',
                $p['index'] ?: ($p['thermometer'] ?: '—')
            );
        }
    }

    $lines[] = '';
    $lines[] = '_Mono Studio OS · borrador desde caché GSC_';
    return implode("\n", $lines);
}

/** Acciones que requieren confirmación explícita del usuario. */
function ersSensitiveTools(): array
{
    return ['updateBillingDate', 'updatePlanValue', 'completeRequest'];
}

/**
 * Ejecuta una tool del copiloto.
 * @return array{ok:bool, pending?:bool, result?:mixed, error?:string, label?:string, undo?:mixed}
 */
function ersRunAction(string $tool, array $args, string $actor = 'user', bool $confirmed = false): array
{
    $tool = trim($tool);
    $sensitive = in_array($tool, ersSensitiveTools(), true);
    if ($sensitive && !$confirmed && $actor === 'gemini') {
        return [
            'ok' => true,
            'pending' => true,
            'tool' => $tool,
            'args' => $args,
            'label' => ersActionLabel($tool, $args),
        ];
    }

    try {
        $result = match ($tool) {
            'listClients' => ersActionListClients(),
            'getClient' => ersActionGetClient($args),
            'listNotes' => ersActionListNotes($args),
            'listAgenda' => ersActionListAgenda(),
            'getSeoSummary' => ersActionGetSeoSummary($args),
            'generarInformeSeo' => ersActionGenerarInformeSeo($args, $actor),
            'addNote' => ersActionAddNote($args, $actor),
            'setCheckIn' => ersActionSetCheckIn($args, $actor),
            'addTask' => ersActionAddTask($args, $actor),
            'completeTask' => ersActionCompleteTask($args, $actor),
            'updateBillingDate' => ersActionUpdateBillingDate($args, $actor),
            'updatePlanValue' => ersActionUpdatePlanValue($args, $actor),
            'completeRequest' => ersActionCompleteRequest($args, $actor),
            'auditRecent' => ['entries' => ersAuditRecent((int) ($args['limit'] ?? 20), (string) ($args['clientId'] ?? ''))],
            default => throw new InvalidArgumentException('Tool desconocida: ' . $tool),
        };

        return ['ok' => true, 'pending' => false, 'result' => $result];
    } catch (Throwable $e) {
        ersAuditAppend([
            'actor' => $actor,
            'tool' => $tool,
            'args' => $args,
            'ok' => false,
            'error' => $e->getMessage(),
        ]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function ersActionLabel(string $tool, array $args): string
{
    return match ($tool) {
        'updateBillingDate' => 'Cambiar cobro de cliente a ' . ($args['nextBillingDate'] ?? '—'),
        'updatePlanValue' => 'Cambiar valor de plan a ' . ($args['valueCLP'] ?? '—'),
        'completeRequest' => 'Marcar solicitud como hecha',
        default => $tool,
    };
}

function ersActionListClients(): array
{
    $store = ersReadStore();
    return array_map('ersClientPublic', $store['clients']);
}

function ersActionGetClient(array $args): array
{
    $id = trim((string) ($args['clientId'] ?? ''));
    $name = trim((string) ($args['name'] ?? ''));
    $store = ersReadStore();

    foreach ($store['clients'] as $client) {
        if ($id !== '' && ($client['id'] ?? '') === $id) {
            return ersClientPublic($client);
        }
        if ($name !== '' && strcasecmp((string) ($client['name'] ?? ''), $name) === 0) {
            return ersClientPublic($client);
        }
        if ($name !== '' && stripos((string) ($client['name'] ?? ''), $name) !== false) {
            return ersClientPublic($client);
        }
    }
    throw new RuntimeException('Cliente no encontrado');
}

function ersActionListNotes(array $args): array
{
    $client = ersActionGetClient($args);
    return [
        'clientId' => $client['id'],
        'name' => $client['name'],
        'notes' => $client['notes'],
        'lastCheckIn' => $client['lastCheckIn'],
    ];
}

function ersActionListAgenda(): array
{
    return ['items' => ersBuildAgenda(ersReadStore())];
}

function ersActionGetSeoSummary(array $args): array
{
    $host = trim((string) ($args['host'] ?? ''));
    if ($host === '') {
        $client = ersActionGetClient($args);
        $host = ersHostOf((string) ($client['siteUrl'] ?? ''));
    }
    return ersSeoSummary($host);
}

function ersActionGenerarInformeSeo(array $args, string $actor): array
{
    $client = null;
    try {
        $client = ersActionGetClient($args);
    } catch (Throwable) {
        /* host-only report */
    }

    $host = trim((string) ($args['host'] ?? ''));
    if ($host === '' && $client) {
        $host = ersHostOf((string) ($client['siteUrl'] ?? ''));
    }
    $summary = ersSeoSummary($host);
    $markdown = ersBuildSeoReportMarkdown($host, $summary, $client);

    ersAuditAppend([
        'actor' => $actor,
        'tool' => 'generarInformeSeo',
        'args' => ['host' => $host, 'clientId' => $client['id'] ?? ''],
        'clientId' => $client['id'] ?? '',
        'ok' => true,
        'result' => ['chars' => strlen($markdown)],
    ]);

    return [
        'host' => $host,
        'clientId' => $client['id'] ?? '',
        'clientName' => $client['name'] ?? '',
        'markdown' => $markdown,
        'summary' => $summary,
    ];
}

function ersActionAddNote(array $args, string $actor): array
{
    $clientId = trim((string) ($args['clientId'] ?? ''));
    $body = trim((string) ($args['body'] ?? ''));
    if ($clientId === '' || $body === '') {
        throw new InvalidArgumentException('clientId y body son obligatorios');
    }

    $store = ersReadStore();
    $idx = ersFindClientIndex($store, $clientId);
    if ($idx < 0) {
        throw new RuntimeException('Cliente no encontrado');
    }

    $note = [
        'id' => ersUuid(),
        'body' => ersClip($body, 4000),
        'createdAt' => (int) round(microtime(true) * 1000),
        'kind' => 'note',
        'by' => $actor,
    ];
    $store['clients'][$idx]['notes'] = $store['clients'][$idx]['notes'] ?? [];
    $store['clients'][$idx]['notes'][] = $note;

    if (!ersWriteStore($store)) {
        throw new RuntimeException('No se pudo guardar');
    }

    ersAuditAppend([
        'actor' => $actor,
        'tool' => 'addNote',
        'args' => ['clientId' => $clientId, 'body' => ersClip($body, 200)],
        'clientId' => $clientId,
        'ok' => true,
        'undo' => ['type' => 'deleteNote', 'clientId' => $clientId, 'noteId' => $note['id']],
        'result' => ['noteId' => $note['id']],
    ]);

    return ['note' => $note, 'client' => ersClientPublic($store['clients'][$idx])];
}

function ersActionSetCheckIn(array $args, string $actor): array
{
    $clientId = trim((string) ($args['clientId'] ?? ''));
    $status = strtolower(trim((string) ($args['status'] ?? 'ok')));
    $note = trim((string) ($args['note'] ?? ''));
    if ($clientId === '') {
        throw new InvalidArgumentException('clientId obligatorio');
    }
    if (!in_array($status, ['ok', 'follow_up', 'blocked'], true)) {
        throw new InvalidArgumentException('status inválido (ok|follow_up|blocked)');
    }

    $store = ersReadStore();
    $idx = ersFindClientIndex($store, $clientId);
    if ($idx < 0) {
        throw new RuntimeException('Cliente no encontrado');
    }

    $prev = $store['clients'][$idx]['lastCheckIn'] ?? null;
    $checkIn = [
        'status' => $status,
        'note' => ersClip($note, 4000),
        'at' => (int) round(microtime(true) * 1000),
        'by' => $actor,
    ];
    $store['clients'][$idx]['lastCheckIn'] = $checkIn;

    $labels = ['ok' => 'OK', 'follow_up' => 'Seguimiento', 'blocked' => 'Bloqueado'];
    $body = '[check-in:' . $status . '] ' . ($labels[$status] ?? $status);
    if ($note !== '') {
        $body .= ' — ' . $note;
    }
    $noteRow = [
        'id' => ersUuid(),
        'body' => ersClip($body, 4000),
        'createdAt' => $checkIn['at'],
        'kind' => 'checkin',
        'by' => $actor,
    ];
    $store['clients'][$idx]['notes'] = $store['clients'][$idx]['notes'] ?? [];
    $store['clients'][$idx]['notes'][] = $noteRow;

    if (!ersWriteStore($store)) {
        throw new RuntimeException('No se pudo guardar');
    }

    ersAuditAppend([
        'actor' => $actor,
        'tool' => 'setCheckIn',
        'args' => ['clientId' => $clientId, 'status' => $status, 'note' => ersClip($note, 200)],
        'clientId' => $clientId,
        'ok' => true,
        'undo' => ['type' => 'restoreCheckIn', 'clientId' => $clientId, 'prev' => $prev, 'noteId' => $noteRow['id']],
        'result' => $checkIn,
    ]);

    return [
        'checkIn' => $checkIn,
        'note' => $noteRow,
        'client' => ersClientPublic($store['clients'][$idx]),
    ];
}

function ersActionAddTask(array $args, string $actor): array
{
    $title = trim((string) ($args['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('title obligatorio');
    }
    $clientId = trim((string) ($args['clientId'] ?? ''));
    $dueDate = (string) ($args['dueDate'] ?? '');
    if ($dueDate !== '' && !preg_match('#^\d{4}-\d{2}-\d{2}$#', $dueDate)) {
        throw new InvalidArgumentException('dueDate inválida');
    }

    $store = ersReadStore();
    if ($clientId !== '' && ersFindClientIndex($store, $clientId) < 0) {
        throw new RuntimeException('Cliente no encontrado');
    }

    $task = [
        'id' => ersUuid(),
        'title' => ersClip($title, 240),
        'clientId' => $clientId,
        'kind' => 'manual',
        'ref' => '',
        'dueDate' => $dueDate,
        'createdAt' => (int) round(microtime(true) * 1000),
        'doneAt' => 0,
    ];
    $store['tasks'][] = $task;
    if (!ersWriteStore($store)) {
        throw new RuntimeException('No se pudo guardar');
    }

    ersAuditAppend([
        'actor' => $actor,
        'tool' => 'addTask',
        'args' => ['title' => $task['title'], 'clientId' => $clientId, 'dueDate' => $dueDate],
        'clientId' => $clientId,
        'ok' => true,
        'undo' => ['type' => 'deleteTask', 'taskId' => $task['id']],
        'result' => ['taskId' => $task['id']],
    ]);

    return ['task' => $task];
}

function ersActionCompleteTask(array $args, string $actor): array
{
    $taskId = trim((string) ($args['taskId'] ?? ''));
    if ($taskId === '') {
        throw new InvalidArgumentException('taskId obligatorio');
    }

    $store = ersReadStore();
    foreach ($store['tasks'] as $i => $task) {
        if (($task['id'] ?? '') !== $taskId) {
            continue;
        }
        $prev = $task['doneAt'] ?? 0;
        $store['tasks'][$i]['doneAt'] = (int) round(microtime(true) * 1000);
        if (!ersWriteStore($store)) {
            throw new RuntimeException('No se pudo guardar');
        }
        ersAuditAppend([
            'actor' => $actor,
            'tool' => 'completeTask',
            'args' => ['taskId' => $taskId],
            'clientId' => $task['clientId'] ?? '',
            'ok' => true,
            'undo' => ['type' => 'restoreTaskDone', 'taskId' => $taskId, 'prevDoneAt' => $prev],
        ]);
        return ['task' => $store['tasks'][$i]];
    }
    throw new RuntimeException('Tarea no encontrada');
}

function ersActionUpdateBillingDate(array $args, string $actor): array
{
    $clientId = trim((string) ($args['clientId'] ?? ''));
    $date = trim((string) ($args['nextBillingDate'] ?? ''));
    if ($clientId === '' || !preg_match('#^\d{4}-\d{2}-\d{2}$#', $date)) {
        throw new InvalidArgumentException('clientId y nextBillingDate (YYYY-MM-DD) obligatorios');
    }

    $store = ersReadStore();
    $idx = ersFindClientIndex($store, $clientId);
    if ($idx < 0) {
        throw new RuntimeException('Cliente no encontrado');
    }

    $prev = $store['clients'][$idx]['nextBillingDate'] ?? '';
    $store['clients'][$idx]['nextBillingDate'] = $date;
    if (!ersWriteStore($store)) {
        throw new RuntimeException('No se pudo guardar');
    }

    ersAuditAppend([
        'actor' => $actor,
        'tool' => 'updateBillingDate',
        'args' => ['clientId' => $clientId, 'nextBillingDate' => $date],
        'clientId' => $clientId,
        'ok' => true,
        'undo' => ['type' => 'restoreBillingDate', 'clientId' => $clientId, 'prev' => $prev],
        'result' => ['prev' => $prev, 'next' => $date],
    ]);

    return ['client' => ersClientPublic($store['clients'][$idx]), 'prev' => $prev];
}

function ersActionUpdatePlanValue(array $args, string $actor): array
{
    $clientId = trim((string) ($args['clientId'] ?? ''));
    $value = (int) ($args['valueCLP'] ?? 0);
    if ($clientId === '' || $value < 1) {
        throw new InvalidArgumentException('clientId y valueCLP (>0) obligatorios');
    }

    $store = ersReadStore();
    $idx = ersFindClientIndex($store, $clientId);
    if ($idx < 0) {
        throw new RuntimeException('Cliente no encontrado');
    }

    $prev = $store['clients'][$idx]['valueCLP'] ?? 0;
    $store['clients'][$idx]['valueCLP'] = $value;
    if (!ersWriteStore($store)) {
        throw new RuntimeException('No se pudo guardar');
    }

    ersAuditAppend([
        'actor' => $actor,
        'tool' => 'updatePlanValue',
        'args' => ['clientId' => $clientId, 'valueCLP' => $value],
        'clientId' => $clientId,
        'ok' => true,
        'undo' => ['type' => 'restorePlanValue', 'clientId' => $clientId, 'prev' => $prev],
        'result' => ['prev' => $prev, 'next' => $value],
    ]);

    return ['client' => ersClientPublic($store['clients'][$idx]), 'prev' => $prev];
}

function ersActionCompleteRequest(array $args, string $actor): array
{
    $requestId = trim((string) ($args['requestId'] ?? ''));
    if ($requestId === '') {
        throw new InvalidArgumentException('requestId obligatorio');
    }

    $store = ersReadStore();
    foreach ($store['requests'] as $i => $req) {
        if (($req['id'] ?? '') !== $requestId) {
            continue;
        }
        $prev = $req['status'] ?? '';
        $store['requests'][$i]['status'] = 'hecha';
        if (!ersWriteStore($store)) {
            throw new RuntimeException('No se pudo guardar');
        }
        ersAuditAppend([
            'actor' => $actor,
            'tool' => 'completeRequest',
            'args' => ['requestId' => $requestId],
            'clientId' => $req['clientId'] ?? '',
            'ok' => true,
            'undo' => ['type' => 'restoreRequestStatus', 'requestId' => $requestId, 'prev' => $prev],
        ]);
        return ['request' => $store['requests'][$i]];
    }
    throw new RuntimeException('Solicitud no encontrada');
}

function ersGeminiToolDeclarations(): array
{
    $str = static fn(string $desc) => ['type' => 'string', 'description' => $desc];
    $num = static fn(string $desc) => ['type' => 'number', 'description' => $desc];

    return [
        [
            'name' => 'listClients',
            'description' => 'Lista clientes de la cartera con check-in y datos básicos',
            'parameters' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        [
            'name' => 'getClient',
            'description' => 'Obtiene un cliente por id o nombre (parcial)',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'clientId' => $str('UUID del cliente'),
                    'name' => $str('Nombre o fragmento del nombre'),
                ],
            ],
        ],
        [
            'name' => 'listNotes',
            'description' => 'Notas recientes y último check-in del cliente',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'clientId' => $str('UUID'),
                    'name' => $str('Nombre si no hay id'),
                ],
            ],
        ],
        [
            'name' => 'listAgenda',
            'description' => 'Pendientes de Hoy: cobros cercanos, solicitudes y tareas',
            'parameters' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        [
            'name' => 'getSeoSummary',
            'description' => 'Resumen SEO desde caché GSC (host o cliente)',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'host' => $str('Hostname sin www'),
                    'clientId' => $str('UUID del cliente'),
                    'name' => $str('Nombre del cliente'),
                ],
            ],
        ],
        [
            'name' => 'generarInformeSeo',
            'description' => 'Genera un informe SEO en markdown a partir de la caché GSC',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'host' => $str('Hostname'),
                    'clientId' => $str('UUID'),
                    'name' => $str('Nombre'),
                ],
            ],
        ],
        [
            'name' => 'addNote',
            'description' => 'Agrega una nota al cuaderno del cliente',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'clientId' => $str('UUID del cliente'),
                    'body' => $str('Texto de la nota'),
                ],
                'required' => ['clientId', 'body'],
            ],
        ],
        [
            'name' => 'setCheckIn',
            'description' => 'Deja check-in del cliente: ok, follow_up o blocked, con nota opcional',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'clientId' => $str('UUID del cliente'),
                    'status' => $str('ok | follow_up | blocked'),
                    'note' => $str('Contexto breve'),
                ],
                'required' => ['clientId', 'status'],
            ],
        ],
        [
            'name' => 'addTask',
            'description' => 'Crea una tarea en la agenda Hoy',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => $str('Qué hay que hacer'),
                    'clientId' => $str('UUID opcional'),
                    'dueDate' => $str('YYYY-MM-DD opcional'),
                ],
                'required' => ['title'],
            ],
        ],
        [
            'name' => 'completeTask',
            'description' => 'Marca una tarea como hecha',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'taskId' => $str('UUID de la tarea'),
                ],
                'required' => ['taskId'],
            ],
        ],
        [
            'name' => 'updateBillingDate',
            'description' => 'Cambia la fecha de próximo cobro (requiere confirmación del usuario)',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'clientId' => $str('UUID'),
                    'nextBillingDate' => $str('YYYY-MM-DD'),
                ],
                'required' => ['clientId', 'nextBillingDate'],
            ],
        ],
        [
            'name' => 'updatePlanValue',
            'description' => 'Cambia el valor del plan en CLP (requiere confirmación)',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'clientId' => $str('UUID'),
                    'valueCLP' => $num('Monto entero CLP'),
                ],
                'required' => ['clientId', 'valueCLP'],
            ],
        ],
        [
            'name' => 'completeRequest',
            'description' => 'Marca una solicitud de cliente como hecha (requiere confirmación)',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'requestId' => $str('UUID de la solicitud'),
                ],
                'required' => ['requestId'],
            ],
        ],
        [
            'name' => 'auditRecent',
            'description' => 'Últimas acciones del audit log (por si algo salió mal)',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'limit' => $num('Cantidad (máx 40)'),
                    'clientId' => $str('Filtrar por cliente'),
                ],
            ],
        ],
    ];
}
