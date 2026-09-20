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
        'model' => $cfg['model'] ?? 'gemini-3.6-flash',
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
        return ['apiKey' => '', 'model' => 'gemini-3.6-flash'];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return ['apiKey' => '', 'model' => 'gemini-3.6-flash'];
    }
    return [
        'apiKey' => trim((string) ($data['apiKey'] ?? $data['api_key'] ?? '')),
        'model' => trim((string) ($data['model'] ?? 'gemini-3.6-flash')) ?: 'gemini-3.6-flash',
    ];
}

function ersGeminiSystemPrompt(array $context): string
{
    $module = (string) ($context['module'] ?? 'today');
    $clientId = (string) ($context['clientId'] ?? '');
    $clientName = (string) ($context['clientName'] ?? '');
    $host = (string) ($context['host'] ?? '');
    $seoHosts = is_array($context['seoHosts'] ?? null) ? $context['seoHosts'] : [];
    $hostsLine = $seoHosts !== []
        ? implode(', ', array_slice(array_map('strval', $seoHosts), 0, 20))
        : '(ninguno con siteUrl)';

    return <<<TXT
Sos el copiloto operativo de Mono Studio OS (agencia web en Chile). Respondés en español chileno, directo, sin relleno.

REGLAS DE RESPUESTA
- Máximo ~120 palabras salvo que pidan un informe.
- Cero clases teóricas (no expliques qué es SEO/MRR/etc. a menos que lo pidan explícitamente).
- SEO "en general" / "cómo vamos" / "alertas" / sin nombrar cliente → usá getPortfolioSeo (toda la cartera). NO uses solo el hostSEO del contexto.
- SEO de un cliente/sitio concreto (o "este sitio" con host en contexto) → getSeoSummary.
- Si no hay datos útiles: 1 frase + qué falta (ej. "Actualizá SEO en esos hosts").
- No inventes métricas: usá tools. No inventes clientes.

FORMATO (markdown compacto)
- 1 título corto con ## (opcional)
- Viñetas con * o -
- Negritas solo en números clave o nombres
- Sin tablas, sin líneas horizontales ---, sin bloques de código
- Cerrá con 1–3 acciones concretas si aplica

ESTRUCTURA PORTFOLIO SEO
## Cartera SEO
* Totales 28d (clics + Δ) y cuántos sitios con/sin caché
* 3–5 sitios que necesitan atención (nombre + dato clave)
* 1–2 próximos pasos

ESTRUCTURA SITIO SEO
## {host o cliente}
* Clics / impresiones / posición (28d) + Δ
* 1–2 páginas que importan
* 1 diagnóstico + 1–2 próximos pasos

TOOLS
- Check-in: setCheckIn (ok | follow_up | blocked) + nota.
- Notas: addNote (pasá clientId o name + body). No hace falta getClient antes si ya tenés el nombre.
- Si el usuario TE CUENTA un hecho operativo (rank&rent a cobro, deadline, “acordamos X el día Y”): addWatch con title, dueDate y type (rank_rent_billing, billing_start, follow_up, deadline, seo, content, custom). Pasá name o clientId. Así el cron de las 8am lo aprieta.
- No llames getClient + addNote/addWatch en paralelo: o pasá el nombre directo, o esperá el id.
- Cobro / valor plan / cerrar solicitud: llamá la tool; el sistema pide confirmación.
- Informe largo de un sitio: generarInformeSeo y en el chat solo un resumen corto.

Contexto UI: módulo={$module}; clienteId={$clientId}; clienteNombre={$clientName}; hostSEO={$host}.
Hosts con sitio en cartera: {$hostsLine}.
Si dice "este cliente" / "este sitio" y hay id/host, usalos. Si pregunta en general, ignorá hostSEO y usá getPortfolioSeo.
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

/**
 * PHP convierte {} en []. Gemini exige Struct (objeto) en functionCall.args
 * y en functionResponse.response — nunca una lista.
 */
function ersJsonStruct(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if ($value === []) {
        return new stdClass();
    }
    if (array_is_list($value)) {
        return array_map('ersJsonStruct', $value);
    }
    $out = new stdClass();
    foreach ($value as $key => $item) {
        $out->{$key} = ersJsonStruct($item);
    }
    return $out;
}

function ersNormalizeGeminiParts(array $parts): array
{
    foreach ($parts as $i => $part) {
        if (!is_array($part)) {
            continue;
        }
        if (isset($part['functionCall']) && is_array($part['functionCall'])) {
            $args = $part['functionCall']['args'] ?? [];
            if (!is_array($args) || $args === [] || array_is_list($args)) {
                $parts[$i]['functionCall']['args'] = new stdClass();
            } else {
                $parts[$i]['functionCall']['args'] = ersJsonStruct($args);
            }
        }
        if (isset($part['functionResponse']) && is_array($part['functionResponse'])) {
            $response = $part['functionResponse']['response'] ?? [];
            if (!is_array($response) || array_is_list($response)) {
                $parts[$i]['functionResponse']['response'] = ersJsonStruct(
                    is_array($response) && !array_is_list($response) ? $response : ['ok' => true]
                );
            } else {
                $parts[$i]['functionResponse']['response'] = ersJsonStruct($response);
            }
        }
    }
    return $parts;
}

function ersGeminiArgs(mixed $args): array
{
    if (is_string($args) && $args !== '') {
        $decoded = json_decode($args, true);
        $args = is_array($decoded) ? $decoded : [];
    } elseif ($args instanceof stdClass) {
        $args = json_decode(json_encode($args), true) ?? [];
    }
    if (!is_array($args) || ($args !== [] && array_is_list($args))) {
        return [];
    }
    return $args;
}

function ersHydrateToolArgs(string $name, array $args, array $context): array
{
    $hasClient = trim((string) ($args['clientId'] ?? '')) !== ''
        || trim((string) ($args['name'] ?? '')) !== '';
    $ctxId = trim((string) ($context['clientId'] ?? ''));
    $ctxName = trim((string) ($context['clientName'] ?? ''));
    $ctxHost = trim((string) ($context['host'] ?? ''));

    $needsClient = in_array($name, [
        'getClient', 'listNotes', 'getSeoSummary', 'generarInformeSeo',
        'addNote', 'setCheckIn', 'addWatch', 'listWatches',
        'updateBillingDate', 'updatePlanValue', 'addTask',
    ], true);

    if ($needsClient && !$hasClient && $ctxId !== '') {
        $args['clientId'] = $ctxId;
        if ($ctxName !== '') {
            $args['name'] = $ctxName;
        }
    }

    if (
        in_array($name, ['getSeoSummary', 'generarInformeSeo'], true)
        && trim((string) ($args['host'] ?? '')) === ''
        && $ctxHost !== ''
    ) {
        $args['host'] = $ctxHost;
    }

    return $args;
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
        $rawParts = ersExtractModelParts($response);
        // args se extraen de raw: normalize convierte objects a stdClass y
        // ersGeminiArgs los tiraba como [].
        $parts = ersNormalizeGeminiParts($rawParts);

        $functionCalls = [];
        foreach ($rawParts as $part) {
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
            $args = ersHydrateToolArgs($name, ersGeminiArgs($call['args'] ?? []), $context);

            $run = ersRunAction($name, $args, 'gemini', false);
            $toolTrace[] = [
                'tool' => $name,
                'args' => $args,
                'ok' => $run['ok'] ?? false,
                'pending' => !empty($run['pending']),
                'error' => ($run['ok'] ?? false) ? null : ($run['error'] ?? null),
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
                'addNote', 'setCheckIn', 'addWatch', 'completeWatch', 'addTask', 'completeTask',
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
            $resultPayload = $run['result'] ?? new stdClass();
            // Evitar mandar markdown enorme completo si no hace falta: truncar en respuesta a Gemini
            if (is_array($resultPayload) && isset($resultPayload['markdown'])) {
                $md = (string) $resultPayload['markdown'];
                $resultPayload['markdownPreview'] = ersClip($md, 6000);
                // keep full markdown for client via toolTrace side channel
                $toolTrace[count($toolTrace) - 1]['markdown'] = $md;
                unset($resultPayload['markdown']);
                unset($resultPayload['summary']);
            }
            if (is_array($resultPayload) && $resultPayload === []) {
                $resultPayload = new stdClass();
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
