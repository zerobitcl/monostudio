<?php
/**
 * Mono Studio OS — API de persistencia
 * GET  → devuelve { clients, requests }
 * POST → guarda { clients, requests } en data/store.json
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

function readStore(string $path): array
{
    if (!file_exists($path)) {
        return ['clients' => [], 'requests' => []];
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['clients' => [], 'requests' => []];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['clients' => [], 'requests' => []];
    }

    return [
        'clients' => is_array($data['clients'] ?? null) ? $data['clients'] : [],
        'requests' => is_array($data['requests'] ?? null) ? $data['requests'] : [],
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
        'clients' => is_array($input['clients'] ?? null) ? $input['clients'] : [],
        'requests' => is_array($input['requests'] ?? null) ? $input['requests'] : [],
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
