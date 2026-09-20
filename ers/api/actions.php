<?php
/**
 * Mono Studio OS — acciones atómicas + audit.
 * POST { action, args, confirmed? }
 * GET  ?action=audit&limit=&clientId=
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string) ($_GET['action'] ?? 'audit');
    if ($action === 'audit') {
        $limit = max(1, min(80, (int) ($_GET['limit'] ?? 40)));
        $clientId = trim((string) ($_GET['clientId'] ?? ''));
        echo json_encode(['entries' => ersAuditRecent($limit, $clientId)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'status') {
        echo json_encode([
            'ok' => true,
            'sensitive' => ersSensitiveTools(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Acción GET desconocida']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

$tool = trim((string) ($input['action'] ?? $input['tool'] ?? ''));
$args = is_array($input['args'] ?? null) ? $input['args'] : [];
$confirmed = !empty($input['confirmed']);
$actor = trim((string) ($input['actor'] ?? 'user'));
if (!in_array($actor, ['user', 'gemini'], true)) {
    $actor = 'user';
}

if ($tool === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Falta action']);
    exit;
}

// Confirmaciones desde la UI siempre actor=user + confirmed
if ($confirmed) {
    $actor = 'user';
}

$result = ersRunAction($tool, $args, $actor, $confirmed || $actor === 'user');

if (!$result['ok']) {
    http_response_code(400);
}

$store = ersReadStore();
echo json_encode(
    array_merge($result, [
        'store' => $store,
    ]),
    JSON_UNESCAPED_UNICODE
);
