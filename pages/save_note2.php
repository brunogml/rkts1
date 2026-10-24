<?php
/**
 * save_note2.php — notes + comments for etext_complete2.
 *
 * Notes stored in ../data/notes/{textid}.xml
 *
 * POST actions:
 *   save            – create/update a top-level note (own only for update)
 *   delete          – delete a top-level note (own or admin)
 *   save_comment    – create/update a comment on a note (own only for update)
 *   delete_comment  – delete a comment (own or admin)
 *
 * POST JSON fields:
 *   action    : string (required)
 *   textid    : string (required)
 *   id        : uuid   (note id; empty = new for save; required for delete/save_comment)
 *   comment_id: uuid   (empty = new comment; required for update/delete_comment)
 *   folio     : string
 *   line      : int
 *   selected  : string
 *   note      : string (HTML)
 *   user      : string (verified against login.txt server-side)
 *
 * GET ?textid=X  → { ok, notes: [{id, user, folio, line, selected, note, created, updated,
 *                                  comments: [{id, user, note, created, updated}]}] }
 */

header('Content-Type: application/json; charset=utf-8');

function err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function makeUuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
}

function setCdata(SimpleXMLElement $parent, string $childName, string $text): void {
    // Remove existing child if present, then add with CDATA
    unset($parent->$childName);
    $el  = $parent->addChild($childName);
    $dom = dom_import_simplexml($el);
    $dom->appendChild($dom->ownerDocument->createCDATASection($text));
}

function saveXml(SimpleXMLElement $xml, string $file): void {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());
    if ($dom->save($file) === false) err('Write failed – check permissions', 500);
}

function folioSortKey(string $folio): int {
    if (preg_match('/^(\d+)([ab])$/', $folio, $m))
        return (int)$m[1] * 1000 + ($m[2] === 'b' ? 500 : 0);
    if (preg_match('/^(\d+)$/', $folio, $m))
        return (int)$m[1] * 1000;
    return 0;
}

// ── Paths ─────────────────────────────────────────────────────────────────────
$loginFile = __DIR__ . '/../data/general/login.txt';
$notesDir  = realpath(__DIR__ . '/../data/notes');
if (!$notesDir) {
    $target = __DIR__ . '/../data/notes';
    if (!mkdir($target, 0755, true)) err('Cannot create notes directory', 500);
    $notesDir = realpath($target);
}

// ── GET ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $textid = preg_replace('/[^A-Za-z0-9_\-]/', '', $_GET['textid'] ?? '');
    if (!$textid) err('Missing textid');
    $file = $notesDir . '/' . $textid . '.xml';
    if (!file_exists($file)) { echo json_encode(['ok' => true, 'notes' => []]); exit; }
    $xml = simplexml_load_file($file);
    if (!$xml) err('Cannot parse notes file', 500);

    $out = [];
    foreach ($xml->note as $n) {
        $comments = [];
        foreach ($n->comment as $c) {
            $comments[] = [
                'id'      => (string)$c['id'],
                'created' => (string)$c['created'],
                'updated' => (string)$c['updated'],
                'user'    => (string)$c->user,
                'note'    => (string)$c->note_body,
            ];
        }
        $out[] = [
            'id'       => (string)$n['id'],
            'created'  => (string)$n['created'],
            'updated'  => (string)$n['updated'],
            'user'     => (string)$n->user,
            'folio'    => (string)$n->folio,
            'line'     => (int)$n->line,
            'selected' => (string)$n->selected,
            'note'     => (string)$n->note_body,
            'category' => (string)($n->category ?? 'general'),
            'sigla'    => (string)($n->sigla    ?? ''),
            'comments' => $comments,
        ];
    }
    usort($out, function($a, $b) {
        $ak = folioSortKey($a['folio']) * 10000 + (int)$a['line'];
        $bk = folioSortKey($b['folio']) * 10000 + (int)$b['line'];
        return $ak <=> $bk;
    });
    echo json_encode(['ok' => true, 'notes' => $out]);
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('Method not allowed', 405);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) err('Invalid JSON');

$action    = $input['action']     ?? '';
$textid    = preg_replace('/[^A-Za-z0-9_\-]/', '', $input['textid']    ?? '');
$noteId    = trim($input['id']          ?? '');
$commentId = trim($input['comment_id'] ?? '');
$folio     = trim($input['folio']      ?? '');
$line      = (int)($input['line']      ?? 0);
$selected  = trim($input['selected']   ?? '');
$note      = trim($input['note']       ?? '');
$username  = trim($input['user']       ?? '');
$category  = in_array($input['category'] ?? '', ['general','sigla']) ? $input['category'] : 'general';
$sigla     = trim($input['sigla']      ?? '');

if (!$textid)   err('Missing textid');
if (!$action)   err('Missing action');
if (!$username) err('Not logged in – please sign in', 403);

// Verify user exists in login.txt
$userLevel = null;
if (file_exists($loginFile)) {
    foreach (file($loginFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $p = explode(';', $ln);
        if (count($p) < 4) continue;
        if (strcasecmp(trim($p[0],'"'), $username) === 0) {
            $userLevel = (int)trim($p[3]);
            break;
        }
    }
}
if ($userLevel === null || $userLevel > 2) err('User not authorised', 403);
$isAdmin = ($userLevel <= 1);

// Load or create XML
$file = $notesDir . '/' . $textid . '.xml';
if (file_exists($file)) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file);
    if (!$xml) err('Cannot parse notes file', 500);
} else {
    $xml = new SimpleXMLElement('<notes/>');
}

// ── save (note) ───────────────────────────────────────────────────────────────
if ($action === 'save') {
    if ($noteId) {
        // Update existing note — must be own
        $found = false;
        foreach ($xml->note as $n) {
            if ((string)$n['id'] !== $noteId) continue;
            if (!$isAdmin && strcasecmp((string)$n->user, $username) !== 0)
                err('You can only edit your own notes', 403);
            $n->folio    = $folio;
            $n->line     = $line;
            $n->selected = $selected;
            $n->category = $category;
            $n->sigla    = $sigla;
            setCdata($n, 'note_body', $note);
            $n['updated'] = date('c');
            $found = true;
            break;
        }
        if (!$found) err('Note not found for update');
    } else {
        // New note
        $noteId = makeUuid();
        $n = $xml->addChild('note');
        $n->addAttribute('id',      $noteId);
        $n->addAttribute('created', date('c'));
        $n->addAttribute('updated', date('c'));
        $n->addChild('user',     htmlspecialchars($username));
        $n->addChild('folio',    htmlspecialchars($folio));
        $n->addChild('line',     (string)$line);
        $n->addChild('selected', htmlspecialchars($selected));
        $n->addChild('category', htmlspecialchars($category));
        $n->addChild('sigla',    htmlspecialchars($sigla));
        setCdata($n, 'note_body', $note);
    }
    saveXml($xml, $file);
    echo json_encode(['ok' => true, 'id' => $noteId]);
    exit;
}

// ── delete (note) ─────────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (!$noteId) err('Missing note id');
    $found = false;
    foreach ($xml->note as $n) {
        if ((string)$n['id'] !== $noteId) continue;
        if (!$isAdmin && strcasecmp((string)$n->user, $username) !== 0)
            err('You can only delete your own notes', 403);
        $node = dom_import_simplexml($n);
        $node->parentNode->removeChild($node);
        $found = true;
        break;
    }
    if (!$found) err('Note not found');
    saveXml($xml, $file);
    echo json_encode(['ok' => true]);
    exit;
}

// ── save_comment ──────────────────────────────────────────────────────────────
if ($action === 'save_comment') {
    if (!$noteId) err('Missing note id for comment');
    $parentFound = false;
    foreach ($xml->note as $n) {
        if ((string)$n['id'] !== $noteId) continue;
        $parentFound = true;
        if ($commentId) {
            // Update existing comment — must be own
            $found = false;
            foreach ($n->comment as $c) {
                if ((string)$c['id'] !== $commentId) continue;
                if (!$isAdmin && strcasecmp((string)$c->user, $username) !== 0)
                    err('You can only edit your own comments', 403);
                setCdata($c, 'note_body', $note);
                $c['updated'] = date('c');
                $found = true;
                break;
            }
            if (!$found) err('Comment not found for update');
        } else {
            // New comment
            $commentId = makeUuid();
            $c = $n->addChild('comment');
            $c->addAttribute('id',      $commentId);
            $c->addAttribute('created', date('c'));
            $c->addAttribute('updated', date('c'));
            $c->addChild('user', htmlspecialchars($username));
            setCdata($c, 'note_body', $note);
        }
        break;
    }
    if (!$parentFound) err('Parent note not found');
    saveXml($xml, $file);
    echo json_encode(['ok' => true, 'comment_id' => $commentId]);
    exit;
}

// ── delete_comment ────────────────────────────────────────────────────────────
if ($action === 'delete_comment') {
    if (!$noteId || !$commentId) err('Missing note or comment id');
    $found = false;
    foreach ($xml->note as $n) {
        if ((string)$n['id'] !== $noteId) continue;
        foreach ($n->comment as $c) {
            if ((string)$c['id'] !== $commentId) continue;
            if (!$isAdmin && strcasecmp((string)$c->user, $username) !== 0)
                err('You can only delete your own comments', 403);
            $node = dom_import_simplexml($c);
            $node->parentNode->removeChild($node);
            $found = true;
            break 2;
        }
    }
    if (!$found) err('Comment not found');
    saveXml($xml, $file);
    echo json_encode(['ok' => true]);
    exit;
}

err('Unknown action');