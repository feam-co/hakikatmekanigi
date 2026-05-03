<?php
// feam.co/iot/komut.php — ESP32 command relay
// POST: IDE komut yazar | GET: ESP32 komutu çeker (ve siler)

define('API_KEY', getenv('FEAM_IOT_KEY') ?: 'feam-iot-2026');
define('CMD_FILE', __DIR__ . '/commands.json');

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

// ── POST: IDE komut yazar ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth();

    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || empty($body['cmd'])) {
        http_response_code(400);
        echo json_encode(['error' => 'cmd field required']);
        exit;
    }

    $cmd = [
        'id'     => uniqid('cmd_'),
        'ts'     => date('c'),
        'device' => $body['device'] ?? 'esp32',
        'cmd'    => $body['cmd'],       // örn: "servo:90", "role:on", "reset"
        'params' => $body['params'] ?? null,
    ];

    $fp = fopen(CMD_FILE, 'c+');
    if (flock($fp, LOCK_EX)) {
        $existing = [];
        $size = filesize(CMD_FILE);
        if ($size > 0) {
            $raw = fread($fp, $size);
            $existing = json_decode($raw, true) ?: [];
        }
        $existing[] = $cmd;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($existing, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    echo json_encode(['ok' => true, 'id' => $cmd['id']]);
    exit;
}

// ── GET: ESP32 komutu çeker (kuyruktan siler) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    auth();

    $device = $_GET['device'] ?? 'esp32';

    if (!file_exists(CMD_FILE)) {
        echo json_encode(['cmd' => null]);
        exit;
    }

    $fp = fopen(CMD_FILE, 'c+');
    flock($fp, LOCK_EX);

    $size = filesize(CMD_FILE);
    $cmds = [];
    if ($size > 0) {
        $raw = fread($fp, $size);
        $cmds = json_decode($raw, true) ?: [];
    }

    // Bu device için ilk komutu al ve kuyruktan çıkar
    $found = null;
    $remaining = [];
    foreach ($cmds as $c) {
        if ($found === null && ($c['device'] === $device || $c['device'] === 'all')) {
            $found = $c;
        } else {
            $remaining[] = $c;
        }
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($remaining, JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);

    echo json_encode(['cmd' => $found]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
