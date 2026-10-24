<?php
/**
 * save_file.php – generic write helper for rKTs data files.
 *
 * Called by register.html via POST with JSON body:
 *   { "path": "../data/general/login.txt", "content": "..." }
 *
 * Security constraints:
 *   – Only paths inside ../data/ are accepted.
 *   – Only .txt and .xml extensions are allowed.
 */

header('Content-Type: application/json');

// ── Read input ────────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['path']) || !isset($input['content'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing path or content']);
    exit;
}

$requestedPath = $input['path'];   // e.g. "../data/general/login.txt"
$content       = $input['content'];

// ── Path validation ───────────────────────────────────────────────────────────
// Allowed base: the data/ folder one level above this script.
// We use realpath() on the *directory*, which always exists.
$dataDir = realpath(__DIR__ . '/../data');

if ($dataDir === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Data directory not found']);
    exit;
}

// Resolve the requested path relative to THIS script's directory,
// without requiring the target file to exist yet.
// 1. Build an absolute path string
$absRequested = __DIR__ . '/' . $requestedPath;

// 2. Normalise . and .. segments manually (realpath() fails on non-existent files)
$parts  = explode('/', str_replace('\\', '/', $absRequested));
$stack  = [];
foreach ($parts as $p) {
    if ($p === '' || $p === '.') continue;
    if ($p === '..') { array_pop($stack); }
    else             { $stack[] = $p; }
}
$target = '/' . implode('/', $stack);

// 3. Confirm the resolved target is inside the data directory
if (strpos($target, $dataDir . '/') !== 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Path not allowed: ' . $target]);
    exit;
}

// 4. Extension whitelist
$ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
if (!in_array($ext, ['txt', 'xml'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'File type not allowed']);
    exit;
}

// ── Write ─────────────────────────────────────────────────────────────────────
$dir = dirname($target);
if (!is_dir($dir)) {
    if (!mkdir($dir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot create directory']);
        exit;
    }
}

if (file_put_contents($target, $content, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Write failed – check file permissions']);
    exit;
}

echo json_encode(['ok' => true]);