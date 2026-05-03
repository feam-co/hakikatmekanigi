<?php
// feam.co/iot/veri.php — ESP32 telemetry relay
// POST: ESP32 sensor data kaydet | GET: Son veriyi oku

define('API_KEY', getenv('FEAM_IOT_KEY') ?: 'feam-iot-2026');
define('DATA_FILE', __DIR__ . '/data.json');
define('MAX_RECORDS', 100);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Key, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function auth() {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($key !== API_KEY) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// ── POST: Veri kaydet ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth();

    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $record = [
        'ts'       => date('c'),
        'device'   => $body['device']  ?? 'esp32',
        'temp'     => $body['temp']    ?? null,
        'humidity' => $body['humidity'] ?? null,
        'status'   => $body['status']  ?? null,
        'extra'    => $body['extra']   ?? null,
    ];

    $fp = fopen(DATA_FILE, 'c+');
    if (flock($fp, LOCK_EX)) {
        $existing = [];
        $size = filesize(DATA_FILE);
        if ($size > 0) {
            $raw = fread($fp, $size);
            $existing = json_decode($raw, true) ?: [];
        }
        array_unshift($existing, $record);
        if (count($existing) > MAX_RECORDS) {
            $existing = array_slice($existing, 0, MAX_RECORDS);
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($existing, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    echo json_encode(['ok' => true, 'ts' => $record['ts']]);
    exit;
}

// ── GET: Son veriyi oku ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = min((int)($_GET['limit'] ?? 1), MAX_RECORDS);

    if (!file_exists(DATA_FILE)) {
        echo json_encode([]);
        exit;
    }

    $fp = fopen(DATA_FILE, 'r');
    flock($fp, LOCK_SH);
    $raw = fread($fp, filesize(DATA_FILE) ?: 1);
    flock($fp, LOCK_UN);
    fclose($fp);

    $data = json_decode($raw, true) ?: [];
    echo json_encode(array_slice($data, 0, $limit));
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
