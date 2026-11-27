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

$action = (string) ($raw['action'] ?? 'send');
if (!in_array($action, ['load', 'save', 'send'], true)) {
    encuestaFail(400, 'Acción no reconocida.');
}

$rut = encuestaNormalizeRut((string) ($raw['rut'] ?? ''));
if ($rut === '') {
    encuestaFail(400, 'El RUT no es válido. Escríbelo con guion, por ejemplo 12.345.678-9.');
}

$nombre = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($raw['nombre'] ?? ''))) ?? '');
$texto = trim(strip_tags((string) ($raw['texto'] ?? '')));
if ($action !== 'load') {
    if (encuestaLen($nombre) < 2 || encuestaLen($nombre) > 120) {
        encuestaFail(400, 'Escribe tu nombre (entre 2 y 120 caracteres).');
    }
    $min = $action === 'send' ? 10 : 1;
    if (encuestaLen($texto) < $min || encuestaLen($texto) > 8000) {
        encuestaFail(400, $action === 'send'
            ? 'Para enviar, escribe al menos un párrafo breve.'
            : 'Escribe algo antes de guardar.');
    }
}

$ip = hash('sha256', 'encuesta-tribunal|' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$now = time();

try {
    $result = encuestaMutate(static function (array &$data) use ($action, $rut, $nombre, $texto, $ip, $now): array {
        $bucket = $action === 'load' ? 'load' : 'write';
        $cap = $action === 'load' ? 80 : 600;
        $hits = [];
        foreach ($data['limits'][$ip][$bucket] ?? [] as $stamp) {
            $stamp = (int) $stamp;
            if ($now - $stamp < 3600) {
                $hits[] = $stamp;
            }
        }
        if (count($hits) >= $cap) {
            throw new RuntimeException('Demasiados intentos en la última hora. Espera un momento.');
        }
        $hits[] = $now;
        $data['limits'][$ip][$bucket] = $hits;

        $index = null;
        foreach ($data['entries'] as $i => $row) {
            if (is_array($row) && (string) ($row['rut'] ?? '') === $rut) {
                $index = $i;
                break;
            }
        }

        if ($action === 'load') {
            if ($index === null) {
                return ['ok' => true, 'found' => false, 'rut' => $rut];
            }
            $row = $data['entries'][$index];
            return [
                'ok' => true,
                'found' => true,
                'rut' => $rut,
                'nombre' => (string) ($row['nombre'] ?? ''),
                'texto' => (string) ($row['texto'] ?? ''),
                'status' => (string) ($row['status'] ?? 'enviado') === 'borrador' ? 'borrador' : 'enviado',
                'updatedAt' => (int) ($row['updatedAt'] ?? $row['createdAt'] ?? 0),
            ];
        }

        $status = $action === 'send' ? 'enviado' : 'borrador';
        if ($index === null && $action === 'save') {
            $status = 'borrador';
        }
        if ($index !== null && $action === 'save' && (string) ($data['entries'][$index]['status'] ?? '') === 'enviado') {
            $status = 'enviado';
        }

        if ($index === null) {
            if (count($data['entries']) >= 80) {
                throw new RuntimeException('La encuesta ya cerró el cupo de registros.');
            }
            $data['entries'][] = [
                'id' => bin2hex(random_bytes(8)),
                'rut' => $rut,
                'nombre' => encuestaClip($nombre, 120),
                'texto' => encuestaClip($texto, 8000),
                'status' => $status,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];
        } else {
            $data['entries'][$index]['nombre'] = encuestaClip($nombre, 120);
            $data['entries'][$index]['texto'] = encuestaClip($texto, 8000);
            $data['entries'][$index]['status'] = $status;
            $data['entries'][$index]['updatedAt'] = $now;
            if (empty($data['entries'][$index]['rut'])) {
                $data['entries'][$index]['rut'] = $rut;
            }
        }

        return [
            'ok' => true,
            'found' => true,
            'rut' => $rut,
            'status' => $status,
            'updatedAt' => $now,
        ];
    });
} catch (RuntimeException $e) {
    encuestaFail(429, $e->getMessage());
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
