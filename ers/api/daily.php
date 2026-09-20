<?php
/**
 * Mono Studio OS — briefing diario (cron 8:00 America/Santiago).
 *
 * GET/POST ?key=DAILY_SECRET
 * El secret vive en ers/data/gemini.json → "dailySecret"
 * o ers/data/daily.json → "dailySecret".
 *
 * Ejemplo cron Hostinger:
 *   0 8 * * * curl -fsS "https://TU-DOMINIO/ers/api/daily.php?key=SECRETO"
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$provided = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
if ($provided === '') {
    $hdr = (string) ($_SERVER['HTTP_X_DAILY_KEY'] ?? '');
    $provided = trim($hdr);
}

$secret = ersLoadDailySecret();
if ($secret === '') {
    http_response_code(503);
    echo json_encode([
        'error' => 'Falta dailySecret en ers/data/gemini.json (o daily.json).',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hash_equals($secret, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $dry = isset($_GET['dry']) || isset($_GET['preview']);
    if ($dry) {
        // Preview: no escribe, solo lista candidatos
        $store = ersReadStore();
        $zone = new DateTimeZone('America/Santiago');
        $now = new DateTimeImmutable('now', $zone);
        $today = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
    $lookaheadEnd = $now->modify('+2 days')->format('Y-m-d');
        $candidates = [];
        foreach ($store['clients'] as $client) {
            foreach ($client['watches'] ?? [] as $w) {
                if (($w['status'] ?? '') !== 'open') {
                    continue;
                }
                $due = (string) ($w['dueDate'] ?? '');
                if ($due === '') {
                    continue;
                }
                if ($due <= $yesterday) {
                    $candidates[] = [
                        'action' => 'alarm',
                        'client' => $client['name'] ?? '',
                        'title' => $w['title'] ?? '',
                        'dueDate' => $due,
                    ];
                } elseif ($due <= $lookaheadEnd) {
                    $candidates[] = [
                        'action' => 'due-task',
                        'client' => $client['name'] ?? '',
                        'title' => $w['title'] ?? '',
                        'dueDate' => $due,
                    ];
                }
            }
        }
        echo json_encode([
            'ok' => true,
            'dry' => true,
            'date' => $today,
            'candidates' => $candidates,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = ersRunDailyBriefing('America/Santiago');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
