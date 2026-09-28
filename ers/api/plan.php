<?php
/**
 * Mono Studio OS — "Vaciar cabeza": texto libre → plan de tareas jerárquico.
 * POST { text } → { ok, summary, question, tasks[] }
 *
 * No escribe en el store: la UI muestra el plan, el usuario lo edita y
 * recién ahí se persiste vía store.php. Así un mal parseo nunca ensucia la agenda.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

const ERS_PLAN_MAX_TASKS = 40;
const ERS_PLAN_MAX_SUBTASKS = 10;

$input = json_decode(file_get_contents('php://input'), true);
$text = is_array($input) ? trim((string) ($input['text'] ?? '')) : '';
if ($text === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Contame qué tenés pendiente']);
    exit;
}

$cfg = ersLoadGeminiConfig();
if ($cfg['apiKey'] === '') {
    http_response_code(503);
    echo json_encode(['error' => 'Falta configurar Gemini en ers/data/gemini.json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$store = ersReadStore();
$zone = new DateTimeZone('America/Santiago');
$today = new DateTimeImmutable('today', $zone);

/**
 * Los LLM resuelven mal "el jueves" o "fin de mes" sin un calendario explícito;
 * pasarle los próximos días ya mapeados elimina casi todos los errores de fecha.
 */
function ersPlanCalendar(DateTimeImmutable $from, int $days): string
{
    $names = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $lines = [];
    for ($i = 0; $i < $days; $i++) {
        $d = $from->modify("+{$i} days");
        $label = $i === 0 ? ' (hoy)' : ($i === 1 ? ' (mañana)' : '');
        $lines[] = $names[(int) $d->format('w')] . ' ' . $d->format('Y-m-d') . $label;
    }
    return implode("\n", $lines);
}

$clientNames = array_values(array_filter(array_map(
    static fn($c) => (string) ($c['name'] ?? ''),
    $store['clients']
)));

$areas = [];
$openTitles = [];
foreach ($store['tasks'] as $task) {
    if (!empty($task['doneAt'])) {
        continue;
    }
    if (($task['area'] ?? '') !== '') {
        $areas[$task['area']] = true;
    }
    if (($task['parentId'] ?? '') === '' && count($openTitles) < 80) {
        $openTitles[] = '- ' . $task['title'];
    }
}
$areas = array_keys($areas + ['Personal' => true, 'Mono Studio' => true]);

$calendar = ersPlanCalendar($today, 21);
$monthEnd = $today->modify('last day of this month')->format('Y-m-d');
$clientsLine = $clientNames !== [] ? implode(', ', array_slice($clientNames, 0, 60)) : '(sin clientes)';
$areasLine = implode(', ', $areas);
$openLine = $openTitles !== [] ? implode("\n", $openTitles) : '(ninguna)';

$system = <<<TXT
Sos el organizador personal de un dueño de agencia web en Chile. Recibís un vómito de ideas y pendientes y lo convertís en un plan accionable.

REGLAS
- Cada tarea: título corto (máx 80 caracteres) que empiece con verbo en infinitivo ("Llamar a…", "Enviar…"). Nada vago tipo "Ver lo del sitio".
- Si una idea implica varios pasos, creá UNA tarea objetivo con subtareas (1 nivel, máx 8, en orden de ejecución). Si es un paso simple, sin subtareas.
- Separá ideas mezcladas en tareas distintas. No pierdas nada de lo que dijo.
- priority: 3 = urgente o con consecuencia real (plata, cliente esperando, vence en ≤3 días, bloquea otras). 2 = importante sin urgencia. 1 = sería bueno.
- dueDate (YYYY-MM-DD): solo si el usuario dice o implica una fecha. NUNCA inventes. Usá el calendario. "Fin de mes" = {$monthEnd}. "La próxima semana" sin día = lunes siguiente.
- someday = true si dice "algún día", "cuando pueda", "idea", "me gustaría", "a futuro". Esas no llevan fecha.
- client: si la tarea es de un cliente de la lista, su nombre EXACTO y area vacío. Si no, client vacío.
- area: reutilizá una existente si calza; si no, una nueva de 1–2 palabras.
- notes: datos concretos que mencionó (montos, teléfonos, nombres, links). Vacío si no hay. No repitas el título.
- Si algo ya está en "tareas abiertas", no lo dupliques; mencionalo en summary.
- Ordená tasks por priority desc y luego fecha más cercana.
- summary: máx 2 frases en español chileno: qué ordenaste y por dónde partir.
- question: solo si falta algo crítico para ejecutar (ej. fecha de una entrega con cliente). Si no, vacío.

CALENDARIO
{$calendar}

CLIENTES: {$clientsLine}
ÁREAS EXISTENTES: {$areasLine}
TAREAS ABIERTAS:
{$openLine}
TXT;

$subtaskSchema = [
    'type' => 'OBJECT',
    'properties' => [
        'title' => ['type' => 'STRING'],
        'dueDate' => ['type' => 'STRING'],
    ],
    'required' => ['title'],
];

$taskSchema = [
    'type' => 'OBJECT',
    'properties' => [
        'title' => ['type' => 'STRING'],
        'area' => ['type' => 'STRING'],
        'client' => ['type' => 'STRING'],
        'priority' => ['type' => 'INTEGER'],
        'dueDate' => ['type' => 'STRING'],
        'someday' => ['type' => 'BOOLEAN'],
        'notes' => ['type' => 'STRING'],
        'subtasks' => ['type' => 'ARRAY', 'items' => $subtaskSchema],
    ],
    'required' => ['title', 'priority'],
    'propertyOrdering' => ['title', 'client', 'area', 'priority', 'dueDate', 'someday', 'notes', 'subtasks'],
];

$payload = [
    'systemInstruction' => ['parts' => [['text' => $system]]],
    'contents' => [[
        'role' => 'user',
        'parts' => [['text' => ersClip($text, 12000)]],
    ]],
    'generationConfig' => [
        'temperature' => 0.2,
        // Holgado: los modelos con razonamiento consumen tokens antes del JSON.
        'maxOutputTokens' => 8192,
        'responseMimeType' => 'application/json',
        'responseSchema' => [
            'type' => 'OBJECT',
            'properties' => [
                'summary' => ['type' => 'STRING'],
                'question' => ['type' => 'STRING'],
                'tasks' => ['type' => 'ARRAY', 'items' => $taskSchema],
            ],
            'required' => ['summary', 'tasks'],
            'propertyOrdering' => ['summary', 'question', 'tasks'],
        ],
    ],
];

try {
    $response = ersGeminiHttp(ersGeminiUrl($cfg), $payload, 90);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = '';
foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
    if (isset($part['text']) && is_string($part['text']) && empty($part['thought'])) {
        $raw .= $part['text'];
    }
}
$plan = json_decode(trim($raw), true);
if (!is_array($plan) || !is_array($plan['tasks'] ?? null)) {
    http_response_code(502);
    echo json_encode(['error' => 'La IA no devolvió un plan válido. Probá de nuevo.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientIndex = [];
foreach ($store['clients'] as $c) {
    $key = mb_strtolower(trim((string) ($c['name'] ?? '')));
    if ($key !== '') {
        $clientIndex[$key] = ['id' => (string) $c['id'], 'name' => (string) $c['name']];
    }
}

function ersPlanMatchClient(string $name, array $index): ?array
{
    $key = mb_strtolower(trim($name));
    if ($key === '') {
        return null;
    }
    if (isset($index[$key])) {
        return $index[$key];
    }
    foreach ($index as $candidate => $client) {
        if (str_contains($candidate, $key) || str_contains($key, $candidate)) {
            return $client;
        }
    }
    return null;
}

function ersPlanDate(mixed $value): string
{
    $value = trim((string) $value);
    return preg_match('#^\d{4}-\d{2}-\d{2}$#', $value) ? $value : '';
}

$tasks = [];
foreach (array_slice($plan['tasks'], 0, ERS_PLAN_MAX_TASKS) as $t) {
    if (!is_array($t)) {
        continue;
    }
    $title = trim((string) ($t['title'] ?? ''));
    if ($title === '') {
        continue;
    }
    $client = ersPlanMatchClient((string) ($t['client'] ?? ''), $clientIndex);
    $someday = !empty($t['someday']);

    $subtasks = [];
    foreach (array_slice(is_array($t['subtasks'] ?? null) ? $t['subtasks'] : [], 0, ERS_PLAN_MAX_SUBTASKS) as $s) {
        $subTitle = is_array($s) ? trim((string) ($s['title'] ?? '')) : '';
        if ($subTitle !== '') {
            $subtasks[] = [
                'title' => ersClip($subTitle, 240),
                'dueDate' => $someday ? '' : ersPlanDate($s['dueDate'] ?? ''),
            ];
        }
    }

    $tasks[] = [
        'title' => ersClip($title, 240),
        'clientId' => $client['id'] ?? '',
        'clientName' => $client['name'] ?? '',
        'area' => $client ? '' : ersClip(trim((string) ($t['area'] ?? '')), 40),
        'priority' => ersTaskPriority($t['priority'] ?? 2),
        'dueDate' => $someday ? '' : ersPlanDate($t['dueDate'] ?? ''),
        'someday' => $someday,
        'notes' => ersClip(trim((string) ($t['notes'] ?? '')), 1000),
        'subtasks' => $subtasks,
    ];
}

ersAuditAppend([
    'actor' => 'gemini',
    'tool' => 'planBrainDump',
    'args' => ['chars' => mb_strlen($text)],
    'ok' => true,
    'result' => ['tasks' => count($tasks)],
]);

echo json_encode([
    'ok' => true,
    'summary' => ersClip(trim((string) ($plan['summary'] ?? '')), 600),
    'question' => ersClip(trim((string) ($plan['question'] ?? '')), 400),
    'tasks' => $tasks,
], JSON_UNESCAPED_UNICODE);
