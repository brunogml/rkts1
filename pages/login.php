<?php
/**
 * login.php  –  rKTs authentication endpoint
 *
 * POST  { "username": "…", "password": "…" }
 * → 200 { "ok": true,  "user": "…", "level": 1, "email": "…" }
 * → 401 { "ok": false, "error": "Invalid username or password." }
 *
 * Reads credentials from ../data/general/login.txt
 * Format per line:  "user";"password";"email";level
 */

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['username']) || empty($input['password'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
    exit;
}

$username = trim($input['username']);
$password = $input['password'];

// ── Read login.txt ────────────────────────────────────────────────────────────
$loginFile = __DIR__ . '/../data/general/login.txt';

if (!file_exists($loginFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Login data not found.']);
    exit;
}

$lines = file($loginFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
    $parts = explode(';', $line);
    if (count($parts) < 4) continue;

    $fileUser  = trim($parts[0], '"');
    $filePass  = trim($parts[1], '"');
    $fileEmail = trim($parts[2], '"');
    $fileLevel = (int) trim($parts[3]);

    if (strcasecmp($fileUser, $username) === 0 && $filePass === $password) {
        echo json_encode([
            'ok'    => true,
            'user'  => $fileUser,
            'level' => $fileLevel,
            'email' => $fileEmail,
        ]);
        exit;
    }
}

http_response_code(401);
echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);