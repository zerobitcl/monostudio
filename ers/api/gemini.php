<?php
/**
 * Mono Studio OS — copiloto Gemini (chat + function calling).
 * POST { message, history?, context? }
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
    $cfg = ersLoadGeminiConfig();
    echo json_encode([
        'configured' => ($cfg['apiKey'] ?? '') !== '',
        'model' => $cfg['model'] ?? 'gemini-2.0-flash',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

function ersLoadGeminiConfig(): array
{
    $path = ersDataDir() . '/gemini.json';
    if (!file_exists($path)) {
        return ['apiKey' => '', 'model' => 'gemini-2.0-flash'];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return ['apiKey' => '', 'model' => 'gemini-2.0-flash'];
    }
    return [
        'apiKey' => trim((string) ($data['apiKey'] ?? $data['api_key'] ?? '')),
        'model' => trim((string) ($data['model'] ?? 'gemini-2.0-flash')) ?: 'gemini-2.0-flash',
    ];
}

function ersGeminiSystemPrompt(array $context): string
{
    $module = (string) ($context['module'] ?? 'today');
    $clientId = (string) ($context['clientId'] ?? '');
    $clientName = (string) ($context['clientName'] ?? '');
    $host = (string) ($context['host'] ?? '');

    return <<<TXT
Sos el copiloto operativo de Mono Studio OS (agencia web chilena).
Hablás en español, claro y breve. No inventés métricas ni fechas: usá tools.
Podés dejar check-in (ok / follow_up / blocked) y notas en el cuaderno del cliente.
Cambios de cobro, valor de plan o cerrar solicitudes requieren confirmación del usuario: llamá la tool igual; el sistema pedirá OK.
Si te piden un informe SEO, usá generarInformeSeo y resumí el markdown en el chat.
Contexto UI: módulo={$module}; clienteId={$clientId}; clienteNombre={$clientName}; hostSEO={$host}.
Si el usuario habla de "este cliente" y hay clientId, usalo.
TXT;
}

function ersGeminiHttp(string $url, array $payload, int $timeout = 60): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        throw new RuntimeException('No se pudo serializar el request a Gemini');
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Gemini: ' . ($err ?: 'sin respuesta'));
        }
        $data = json_decode($raw, true);
        if ($code >= 400) {
            $msg = is_array($data) ? ($data['error']['message'] ?? $raw) : $raw;
            throw new RuntimeException('Gemini HTTP ' . $code . ': ' . ersClip((string) $msg, 400));
        }
        if (!is_array($data)) {
            throw new RuntimeException('Respuesta Gemini inválida');
        }
        return $data;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException('Gemini: sin respuesta');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Respuesta Gemini inválida');
    }
    if (isset($data['error'])) {
        throw new RuntimeException('Gemini: ' . ersClip((string) ($data['error']['message'] ?? 'error'), 400));
    }
    return $data;
}

function ersExtractModelParts(array $response): array
{
    $parts = $response['candidates'][0]['content']['parts'] ?? [];
    return is_array($parts) ? $parts : [];
}

function ersPartsToText(array $parts): string
{
    $chunks = [];
    foreach ($parts as $part) {
        if (isset($part['text']) && is_string($part['text'])) {
            $chunks[] = $part['text'];
        }
    }
    return trim(implode("\n", $chunks));
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

$message = trim((string) ($input['message'] ?? ''));
if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Falta message']);
    exit;
}

$cfg = ersLoadGeminiConfig();
if ($cfg['apiKey'] === '') {
    http_response_code(503);
    echo json_encode([
        'error' => 'Falta configurar Gemini. Copiá ers/data/gemini.example.json a gemini.json y poné tu apiKey.',
    ]);
    exit;
}

$context = is_array($input['context'] ?? null) ? $input['context'] : [];
$history = is_array($input['history'] ?? null) ? $input['history'] : [];
$history = array_slice($history, -12);

$contents = [];
foreach ($history as $turn) {
    if (!is_array($turn)) {
        continue;
    }
    $role = ($turn['role'] ?? '') === 'model' ? 'model' : 'user';
    $text = trim((string) ($turn['text'] ?? ''));
    if ($text === '') {
        continue;
    }
    $contents[] = [
        'role' => $role,
        'parts' => [['text' => ersClip($text, 8000)]],
    ];
}
$contents[] = [
    'role' => 'user',
    'parts' => [['text' => ersClip($message, 8000)]],
];

$payloadBase = [
    'systemInstruction' => [
        'parts' => [['text' => ersGeminiSystemPrompt($context)]],
    ],
    'tools' => [
        ['functionDeclarations' => ersGeminiToolDeclarations()],
    ],
    'toolConfig' => [
        'functionCallingConfig' => ['mode' => 'AUTO'],
    ],
    'generationConfig' => [
        'temperature' => 0.3,
        'maxOutputTokens' => 2048,
    ],
];

$model = rawurlencode($cfg['model']);
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key='
    . rawurlencode($cfg['apiKey']);

$pending = [];
$toolTrace = [];
$storeDirty = false;
$finalText = '';
$maxRounds = 5;

try {
    for ($round = 0; $round < $maxRounds; $round++) {
        $payload = $payloadBase;
        $payload['contents'] = $contents;
        $response = ersGeminiHttp($url, $payload);
        $parts = ersExtractModelParts($response);

        $functionCalls = [];
        foreach ($parts as $part) {
            if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                $functionCalls[] = $part['functionCall'];
            }
        }

        if ($functionCalls === []) {
            $finalText = ersPartsToText($parts);
            break;
        }

        $contents[] = [
            'role' => 'model',
            'parts' => $parts,
        ];

        $fnResponses = [];
        foreach ($functionCalls as $call) {
            $name = (string) ($call['name'] ?? '');
            $args = $call['args'] ?? [];
            if (!is_array($args)) {
                $args = [];
            }

            $run = ersRunAction($name, $args, 'gemini', false);
            $toolTrace[] = [
                'tool' => $name,
                'args' => $args,
                'ok' => $run['ok'] ?? false,
                'pending' => !empty($run['pending']),
            ];

            if (!empty($run['pending'])) {
                $pending[] = [
                    'tool' => $run['tool'] ?? $name,
                    'args' => $run['args'] ?? $args,
                    'label' => $run['label'] ?? $name,
                ];
                $fnResponses[] = [
                    'functionResponse' => [
                        'name' => $name,
                        'response' => [
                            'ok' => true,
                            'pendingConfirmation' => true,
                            'message' => 'Acción sensible: el usuario debe confirmar en la UI. No la des por hecha.',
                            'label' => $run['label'] ?? $name,
                        ],
                    ],
                ];
                continue;
            }

            $mutating = in_array($name, [
                'addNote', 'setCheckIn', 'addTask', 'completeTask',
                'updateBillingDate', 'updatePlanValue', 'completeRequest',
            ], true);

            if (!($run['ok'] ?? false)) {
                $fnResponses[] = [
                    'functionResponse' => [
                        'name' => $name,
                        'response' => [
                            'ok' => false,
                            'error' => $run['error'] ?? 'error',
                        ],
                    ],
                ];
                continue;
            }

            if ($mutating) {
                $storeDirty = true;
            }
            $resultPayload = $run['result'] ?? [];
            // Evitar mandar markdown enorme completo si no hace falta: truncar en respuesta a Gemini
            if (is_array($resultPayload) && isset($resultPayload['markdown'])) {
                $md = (string) $resultPayload['markdown'];
                $resultPayload['markdownPreview'] = ersClip($md, 6000);
                // keep full markdown for client via toolTrace side channel
                $toolTrace[count($toolTrace) - 1]['markdown'] = $md;
                unset($resultPayload['markdown']);
                unset($resultPayload['summary']);
            }

            $fnResponses[] = [
                'functionResponse' => [
                    'name' => $name,
                    'response' => [
                        'ok' => true,
                        'result' => $resultPayload,
                    ],
                ],
            ];
        }

        $contents[] = [
            'role' => 'user',
            'parts' => $fnResponses,
        ];
    }

    if ($finalText === '' && $pending) {
        $finalText = 'Hay acciones que requieren tu confirmación.';
    }
    if ($finalText === '') {
        $finalText = 'Listo.';
    }
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$markdownReports = [];
foreach ($toolTrace as $t) {
    if (!empty($t['markdown'])) {
        $markdownReports[] = [
            'tool' => $t['tool'],
            'markdown' => $t['markdown'],
        ];
    }
}

echo json_encode(
    [
        'ok' => true,
        'reply' => $finalText,
        'pending' => $pending,
        'tools' => array_map(static function ($t) {
            unset($t['markdown']);
            return $t;
        }, $toolTrace),
        'reports' => $markdownReports,
        'store' => $storeDirty ? ersReadStore() : null,
    ],
    JSON_UNESCAPED_UNICODE
);
