<?php
/**
 * Informe de la encuesta del tribunal. Vive bajo /ers/ (HTTP Basic).
 *
 * GET  action=status
 * POST action=delete   { id }
 * POST action=analyze  { force? }
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once dirname(__DIR__, 2) . '/encuesta/store.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function encuestaJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function encuestaInput(): array
{
    $raw = json_decode((string) file_get_contents('php://input'), true);
    return is_array($raw) ? $raw : [];
}

function encuestaStatusPayload(array $data): array
{
    $entries = encuestaPublicEntries($data);
    $stamp = encuestaFingerprint($entries);
    $report = is_array($data['report'] ?? null) ? $data['report'] : null;
    $sent = 0;
    $drafts = 0;
    foreach ($entries as $row) {
        if (($row['status'] ?? '') === 'borrador') {
            $drafts++;
        } else {
            $sent++;
        }
    }
    return [
        'ok' => true,
        'count' => count($entries),
        'sent' => $sent,
        'drafts' => $drafts,
        'entries' => $entries,
        'link' => 'https://monostudio.cl/encuesta/',
        'report' => $report,
        'reportAt' => (int) ($data['reportAt'] ?? 0),
        'reportStale' => $report !== null && (string) ($data['reportBasedOn'] ?? '') !== $stamp,
    ];
}

function encuestaSchema(): array
{
    $str = ['type' => 'string'];
    $obj = static fn (array $props): array => [
        'type' => 'object',
        'properties' => $props,
        'required' => array_keys($props),
    ];
    return $obj([
        'resumen_ejecutivo' => $str,
        'problematicas' => [
            'type' => 'array',
            'items' => $obj([
                'nivel' => ['type' => 'string', 'enum' => ['urgente', 'media', 'liviana']],
                'titulo' => $str,
                'descripcion' => $str,
                'menciones' => ['type' => 'integer'],
                'nombres' => ['type' => 'array', 'items' => $str],
                'sugerencias' => ['type' => 'array', 'items' => $str],
            ]),
        ],
        'mejoras_rapidas' => ['type' => 'array', 'items' => $str],
    ]);
}

function encuestaSanitizeReport(array $raw, array $entries): array
{
    $known = [];
    foreach ($entries as $row) {
        $name = trim((string) ($row['nombre'] ?? ''));
        if ($name !== '') {
            $known[function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name)] = $name;
        }
    }
    $clip = static function (string $value, int $max): string {
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    };
    $total = max(1, count($entries));
    $problems = [];
    foreach (array_slice(is_array($raw['problematicas'] ?? null) ? $raw['problematicas'] : [], 0, 12) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $titulo = $clip((string) ($row['titulo'] ?? ''), 140);
        $descripcion = $clip((string) ($row['descripcion'] ?? ''), 900);
        if ($titulo === '' || $descripcion === '') {
            continue;
        }
        $nivel = (string) ($row['nivel'] ?? '');
        if (!in_array($nivel, ['urgente', 'media', 'liviana'], true)) {
            $nivel = 'media';
        }
        $nombres = [];
        foreach (is_array($row['nombres'] ?? null) ? $row['nombres'] : [] as $name) {
            $key = trim((string) $name);
            $fold = function_exists('mb_strtolower') ? mb_strtolower($key, 'UTF-8') : strtolower($key);
            if (isset($known[$fold]) && !in_array($known[$fold], $nombres, true)) {
                $nombres[] = $known[$fold];
            }
        }
        $sugerencias = [];
        foreach (is_array($row['sugerencias'] ?? null) ? $row['sugerencias'] : [] as $item) {
            $text = $clip((string) $item, 300);
            if ($text !== '') {
                $sugerencias[] = $text;
            }
            if (count($sugerencias) >= 4) {
                break;
            }
        }
        $menciones = (int) ($row['menciones'] ?? count($nombres));
        if ($nombres !== []) {
            $menciones = max($menciones, count($nombres));
        }
        $menciones = max(1, min($total, $menciones));
        $problems[] = [
            'nivel' => $nivel,
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'menciones' => $menciones,
            'nombres' => $nombres,
            'sugerencias' => $sugerencias,
        ];
    }
    $rank = ['urgente' => 0, 'media' => 1, 'liviana' => 2];
    usort($problems, static function (array $a, array $b) use ($rank): int {
        $level = ($rank[$a['nivel']] ?? 9) <=> ($rank[$b['nivel']] ?? 9);
        return $level !== 0 ? $level : ($b['menciones'] <=> $a['menciones']);
    });

    $quick = [];
    foreach (array_slice(is_array($raw['mejoras_rapidas'] ?? null) ? $raw['mejoras_rapidas'] : [], 0, 8) as $item) {
        $text = $clip((string) $item, 300);
        if ($text !== '') {
            $quick[] = $text;
        }
    }

    return [
        'resumen_ejecutivo' => $clip((string) ($raw['resumen_ejecutivo'] ?? ''), 1200),
        'problematicas' => $problems,
        'mejoras_rapidas' => $quick,
    ];
}

function encuestaAnalyze(bool $force): array
{
    $current = encuestaReadRaw();
    $entries = array_values(array_filter(
        encuestaPublicEntries($current),
        static function (array $row): bool {
            $text = trim((string) ($row['texto'] ?? ''));
            $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
            return $len >= 10;
        }
    ));
    if ($entries === []) {
        throw new RuntimeException('Todavía no hay registros con texto suficiente para cruzar.');
    }
    $stamp = encuestaFingerprint($entries);
    if (
        !$force
        && is_array($current['report'] ?? null)
        && ($current['reportBasedOn'] ?? '') === $stamp
        && ($current['report']['problematicas'] ?? []) !== []
    ) {
        $payload = encuestaStatusPayload($current);
        $payload['cached'] = true;
        return $payload;
    }

    $cfg = ersLoadGeminiConfig();
    if ($cfg['apiKey'] === '') {
        throw new RuntimeException('Falta configurar Gemini (ers/data/gemini.json).');
    }

    $blocks = [];
    foreach ($entries as $index => $row) {
        $blocks[] = '--- Registro ' . ($index + 1) . ' · ' . $row['nombre'] . " ---\n" . $row['texto'];
    }
    $system = <<<TXT
Sos un relator de un equipo de tribunal. Cruzás solo lo que escribieron las personas. No inventes problemas, nombres ni cargos que no estén en los registros.
Las inquietudes cubren el cargo, el funcionamiento del tribunal y el servicio a usuarios. Si alguien habla de atención, espera, trámites o trato a quien llega al tribunal, eso es servicio a usuarios: agrúpalo así y no lo rebajes a un detalle interno.
Agrupá lo que se repite. Ordená las problemáticas de más urgente a más liviana: urgente = traba el trabajo o se repite con fuerza; media = duele pero se puede sostener; liviana = mejora deseable.
Cada problemática cita los nombres reales de quienes la mencionan y las sugerencias que ellos mismos propusieron, en sus términos.
resumen_ejecutivo: máximo 4 frases, lo más grave primero.
mejoras_rapidas: acuerdos fáciles de aplicar que aparecen en los textos. Si no hay, lista vacía.
TXT;
    $payload = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => "Registros del equipo (" . count($entries) . "):\n\n" . implode("\n\n", $blocks)]],
        ]],
        'generationConfig' => array_merge([
            'temperature' => 0.2,
            'maxOutputTokens' => 8192,
            'responseMimeType' => 'application/json',
            'responseSchema' => encuestaSchema(),
        ], ersGeminiThinkingConfig($cfg)),
    ];

    @set_time_limit(120);
    $res = ersGeminiHttp(ersGeminiUrl($cfg), $payload, 90);
    $text = '';
    foreach ($res['candidates'][0]['content']['parts'] ?? [] as $part) {
        if (empty($part['thought']) && isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }
    $text = trim((string) preg_replace('#^```(?:json)?|```$#m', '', $text));
    $raw = json_decode($text, true);
    if (!is_array($raw)) {
        throw new RuntimeException('Gemini no devolvió un informe válido. Reintenta.');
    }
    $report = encuestaSanitizeReport($raw, $entries);
    if ($report['resumen_ejecutivo'] === '' || $report['problematicas'] === []) {
        throw new RuntimeException('El informe vino vacío. Reintenta.');
    }
    $report['model'] = $cfg['model'];
    $report['basedOn'] = count($entries);

    $saved = encuestaMutate(static function (array &$data) use ($report, $stamp): array {
        $data['report'] = $report;
        $data['reportBasedOn'] = $stamp;
        $data['reportAt'] = time();
        return encuestaStatusPayload($data);
    });
    $saved['cached'] = false;
    return $saved;
}

try {
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        encuestaJson(encuestaStatusPayload(encuestaReadRaw()));
    }
    if ($method !== 'POST') {
        encuestaJson(['error' => 'Método no permitido'], 405);
    }
    $input = encuestaInput();
    $action = (string) ($input['action'] ?? '');
    if ($action === 'delete') {
        $id = trim((string) ($input['id'] ?? ''));
        if ($id === '') {
            encuestaJson(['error' => 'Falta el registro.'], 400);
        }
        $payload = encuestaMutate(static function (array &$data) use ($id): array {
            $data['entries'] = array_values(array_filter(
                $data['entries'],
                static fn ($row): bool => is_array($row) && (string) ($row['id'] ?? '') !== $id
            ));
            return encuestaStatusPayload($data);
        });
        encuestaJson($payload);
    }
    if ($action === 'analyze') {
        $force = in_array((string) ($input['force'] ?? ''), ['1', 'true'], true) || ($input['force'] ?? null) === true;
        encuestaJson(encuestaAnalyze($force));
    }
    encuestaJson(['error' => 'Acción no reconocida.'], 400);
} catch (Throwable $e) {
    encuestaJson(['error' => $e->getMessage()], 500);
}
