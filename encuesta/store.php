<?php
/**
 * Almacén de la encuesta. No es un endpoint: submit.php y ers/api/encuesta.php lo incluyen.
 */

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function encuestaStorePath(): string
{
    return dirname(__DIR__) . '/ers/data/encuesta-tribunal.json';
}

function encuestaBlank(): array
{
    return [
        'entries' => [],
        'report' => null,
        'reportBasedOn' => '',
        'reportAt' => 0,
        'limits' => [],
    ];
}

function encuestaReadRaw(): array
{
    $path = encuestaStorePath();
    if (!is_file($path)) {
        return encuestaBlank();
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return encuestaBlank();
    }
    $data['entries'] = is_array($data['entries'] ?? null) ? $data['entries'] : [];
    $data['limits'] = is_array($data['limits'] ?? null) ? $data['limits'] : [];
    $data['reportBasedOn'] = (string) ($data['reportBasedOn'] ?? '');
    $data['reportAt'] = (int) ($data['reportAt'] ?? 0);
    return $data;
}

/**
 * @template T
 * @param callable(array): T $change
 * @return T
 */
function encuestaMutate(callable $change)
{
    $path = encuestaStorePath();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo preparar el archivo de la encuesta.');
    }
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('No se pudo bloquear el archivo de la encuesta.');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('No se pudo bloquear el archivo de la encuesta.');
        }
        $data = encuestaReadRaw();
        $result = $change($data);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('No se pudo guardar la encuesta.');
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) {
            throw new RuntimeException('No se pudo guardar la encuesta.');
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** RUT chileno normalizado como 12345678-9, o vacío si el dígito no calza. */
function encuestaNormalizeRut(string $value): string
{
    $value = strtoupper((string) (preg_replace('/[^0-9kK]/', '', $value) ?? ''));
    if (strlen($value) < 8 || strlen($value) > 9) {
        return '';
    }
    $dv = substr($value, -1);
    $num = substr($value, 0, -1);
    if (!ctype_digit($num)) {
        return '';
    }
    $sum = 0;
    $mul = 2;
    for ($i = strlen($num) - 1; $i >= 0; $i--) {
        $sum += (int) $num[$i] * $mul;
        $mul = $mul === 7 ? 2 : $mul + 1;
    }
    $rest = 11 - ($sum % 11);
    $expected = $rest === 11 ? '0' : ($rest === 10 ? 'K' : (string) $rest);
    return $dv === $expected ? $num . '-' . $dv : '';
}

function encuestaPublicEntries(array $data): array
{
    $out = [];
    foreach ($data['entries'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = (string) ($row['status'] ?? '');
        $out[] = [
            'id' => (string) ($row['id'] ?? ''),
            'rut' => (string) ($row['rut'] ?? ''),
            'nombre' => (string) ($row['nombre'] ?? ''),
            'texto' => (string) ($row['texto'] ?? ''),
            'status' => $status === 'borrador' ? 'borrador' : 'enviado',
            'createdAt' => (int) ($row['createdAt'] ?? 0),
            'updatedAt' => (int) ($row['updatedAt'] ?? $row['createdAt'] ?? 0),
        ];
    }
    usort($out, static fn (array $a, array $b): int => $b['updatedAt'] <=> $a['updatedAt']);
    return $out;
}

function encuestaFingerprint(array $entries): string
{
    $parts = [];
    foreach ($entries as $row) {
        $parts[] = ($row['id'] ?? '') . '|' . ($row['status'] ?? '') . '|' . ($row['texto'] ?? '') . '|' . ($row['nombre'] ?? '');
    }
    sort($parts);
    return md5(implode("\n", $parts));
}
