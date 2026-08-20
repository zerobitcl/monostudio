<?php
/**
 * Mono Studio OS — API de persistencia
 * GET  → devuelve { clients, requests, tasks }
 * POST → guarda { clients, requests, tasks } en data/store.json
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$dataDir = dirname(__DIR__) . '/data';
$dataFile = $dataDir . '/store.json';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/** mbstring no está garantizado en todos los hosting; substr es el fallback seguro. */
function clip(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function sanitizeNotes($notes): array
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
            'id' => clip((string) ($note['id'] ?? ''), 64),
            'body' => clip($body, 4000),
            'createdAt' => (int) ($note['createdAt'] ?? 0),
        ];
    }
    return $out;
}

function sanitizeClients($clients): array
{
    if (!is_array($clients)) {
        return [];
    }

    $out = [];
    foreach ($clients as $client) {
        if (!is_array($client)) {
            continue;
        }
        $client['notes'] = sanitizeNotes($client['notes'] ?? []);
        $client['siteUrl'] = clip(trim((string) ($client['siteUrl'] ?? '')), 300);
        $out[] = $client;
    }
    return $out;
}

/**
 * Las tareas alimentan el panel "Hoy". `ref` guarda el origen (URL SEO, id de cobro…)
 * para poder deduplicar señales ya convertidas en tarea sin volver a crearlas.
 */
function sanitizeTasks($tasks): array
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
            'id' => clip((string) ($task['id'] ?? ''), 64),
            'title' => clip($title, 240),
            'clientId' => clip((string) ($task['clientId'] ?? ''), 64),
            'kind' => clip((string) ($task['kind'] ?? 'manual'), 24),
            'ref' => clip((string) ($task['ref'] ?? ''), 500),
            'dueDate' => preg_match('#^\d{4}-\d{2}-\d{2}$#', (string) ($task['dueDate'] ?? ''))
                ? (string) $task['dueDate']
                : '',
            'createdAt' => (int) ($task['createdAt'] ?? 0),
            'doneAt' => (int) ($task['doneAt'] ?? 0),
        ];
    }
    return $out;
}

function emptyStore(): array
{
    return ['clients' => [], 'requests' => [], 'tasks' => []];
}

function readStore(string $path): array
{
    if (!file_exists($path)) {
        return emptyStore();
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return emptyStore();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return emptyStore();
    }

    return [
        'clients' => sanitizeClients($data['clients'] ?? null),
        'requests' => is_array($data['requests'] ?? null) ? $data['requests'] : [],
        'tasks' => sanitizeTasks($data['tasks'] ?? null),
    ];
}

function writeStore(string $path, array $data): bool
{
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(readStore($dataFile), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'JSON inválido']);
        exit;
    }

    $store = [
        'clients' => sanitizeClients($input['clients'] ?? null),
        'requests' => is_array($input['requests'] ?? null) ? $input['requests'] : [],
        'tasks' => sanitizeTasks($input['tasks'] ?? null),
    ];

    if (!writeStore($dataFile, $store)) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo escribir en el servidor. Revisa permisos de la carpeta /data']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
