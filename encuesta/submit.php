<?php
/**
 * Alta pública de una respuesta. No lista ni devuelve lo ya guardado.
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

function encuestaFail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function encuestaLen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function encuestaClip(string $value, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    encuestaFail(405, 'Método no permitido');
}

$raw = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($raw)) {
    encuestaFail(400, 'El envío no es válido.');
}

if (trim((string) ($raw['empresa'] ?? '')) !== '') {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$nombre = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($raw['nombre'] ?? ''))) ?? '');
$texto = trim(strip_tags((string) ($raw['texto'] ?? '')));
if (encuestaLen($nombre) < 2 || encuestaLen($nombre) > 120) {
    encuestaFail(400, 'Escribe tu nombre (entre 2 y 120 caracteres).');
}
if (encuestaLen($texto) < 10 || encuestaLen($texto) > 8000) {
    encuestaFail(400, 'El registro debe tener entre 10 y 8000 caracteres.');
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$key = hash('sha256', 'encuesta-tribunal|' . $ip);
$now = time();

try {
    encuestaMutate(static function (array &$data) use ($key, $now, $nombre, $texto): array {
        $hits = [];
        foreach ($data['limits'][$key] ?? [] as $stamp) {
            $stamp = (int) $stamp;
            if ($now - $stamp < 3600) {
                $hits[] = $stamp;
            }
        }
        if (count($hits) >= 6) {
            throw new RuntimeException('Ya enviaste varios registros en la última hora. Espera un momento.');
        }
        $hits[] = $now;
        $data['limits'][$key] = $hits;
        if (count($data['entries']) >= 80) {
            throw new RuntimeException('La encuesta ya cerró el cupo de registros.');
        }
        $data['entries'][] = [
            'id' => bin2hex(random_bytes(8)),
            'nombre' => encuestaClip($nombre, 120),
            'texto' => encuestaClip($texto, 8000),
            'createdAt' => $now,
        ];
        return ['ok' => true];
    });
} catch (RuntimeException $e) {
    encuestaFail(429, $e->getMessage());
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
