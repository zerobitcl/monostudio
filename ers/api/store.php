<?php
/**
 * Mono Studio OS — API de persistencia
 * GET  → devuelve { clients, requests, tasks }
 * POST → guarda { clients, requests, tasks } en data/store.json
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$dataFile = ersStorePath();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(ersReadStore($dataFile), JSON_UNESCAPED_UNICODE);
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
        'clients' => ersSanitizeClients($input['clients'] ?? null),
        'requests' => is_array($input['requests'] ?? null) ? $input['requests'] : [],
        'tasks' => ersSanitizeTasks($input['tasks'] ?? null),
    ];

    if (!ersWriteStore($store, $dataFile)) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo escribir en el servidor. Revisa permisos de la carpeta /data']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
