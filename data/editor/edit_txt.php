<?php
/* =====================================================================
   edit_text.php  –  rKTs etext editor
   Displays the manuscript image (OpenSeadragon / IIIF) alongside the
   etext for the current page and lets the user:
     1. Edit any line's text in-place
     2. Add a new line after the last line of a folio
     3. Shift a page break  (e.g. 1b → 2a, cascading all subsequent)
     4. Split a line into two (useful for single-line pages)
     5. Delete a line (renumbers subsequent lines on the same folio)
   All changes are written back to the .txt file on disk via the
   save_edit.php endpoint (see below / or inline here with AJAX).
===================================================================== */

// ── Inline save endpoint ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action    = $_POST['action']    ?? '';
    $sec       = $_POST['sec']       ?? '';
    // The JS passes the already-resolved etext URL (e.g. ../../data/etexts/1Dk/003.txt).
    // We convert it to a server-side path relative to this file's directory.
    $etextUrl  = $_POST['etext_url'] ?? '';

    if (!$etextUrl) {
        echo json_encode(['ok'=>false,'error'=>'etext_url not provided']);
        exit;
    }
    // Strip leading ../../ components to build a path relative to this script
    // This file is at  data/editor/edit_text.php
    // etextUrl from JS: ../../data/etexts/1Dk/003.txt  →  same as  data/etexts/1Dk/003.txt
    // realpath of this script's dir: /…/data/editor/
    $scriptDir = __DIR__;                          // …/data/editor
    $etextPath = realpath($scriptDir . '/../../' . ltrim(preg_replace('#^\.\./\.\.\/#', '', $etextUrl), '/'));

    if (!$etextPath || !file_exists($etextPath)) {
        // Fallback: try resolving relative to document root
        $etextPath2 = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim(preg_replace('#^\.\./\.\.\/#', '', $etextUrl), '/');
        $etextPath2 = realpath($etextPath2);
        if ($etextPath2 && file_exists($etextPath2)) {
            $etextPath = $etextPath2;
        } else {
            echo json_encode(['ok'=>false,'error'=>"etext file not found. url=$etextUrl resolved=$etextPath"]);
            exit;
        }
    }

    // Load the raw lines
    error_log('[edit_text] POST: action='.$action.' pag='.($_POST['pag']??'').' linenum='.($_POST['linenum']??'').' etextPath='.$etextPath);
    $raw   = file_get_contents($etextPath);
    $lines = explode("\n", str_replace("\r\n", "\n", $raw));

    // ── Helpers ──────────────────────────────────────────────────────
    // Parse a line like "3a1(sec): text"  →  [pag, linenum, sec, text]
    function parseLine($l) {
        if (preg_match('/^(\d+\'?[ab])(\d+)(?:\(([^)]*)\))?:\s*(.*)/', $l, $m)) {
            return [$m[1], (int)$m[2], $m[3] ?? '', $m[4]];
        }
        return null;
    }
    function buildLine($pag, $linenum, $sec, $text) {
        $s = $sec !== '' ? "($sec)" : '';
        return "{$pag}{$linenum}{$s}: {$text}";
    }

    // Increment a pagination label by one side: 1a→1b, 1b→2a, 2a→2b …
    function incPag($pag) {
        if (preg_match('/^(\d+)(a)$/', $pag, $m)) return $m[1].'b';
        if (preg_match('/^(\d+)(b)$/', $pag, $m)) return ((int)$m[1]+1).'a';
        return $pag; // fallback
    }

    // ── Actions ──────────────────────────────────────────────────────
    switch ($action) {

        // 1. Edit a single line's text
        case 'edit_line': {
            $pag     = $_POST['pag']     ?? '';
            $linenum = (int)($_POST['linenum'] ?? 0);
            $newText = $_POST['text']    ?? '';
            $found   = false;
            foreach ($lines as &$l) {
                $p = parseLine($l);
                if ($p && $p[0]===$pag && $p[1]===$linenum) {
                    $l = buildLine($p[0], $p[1], $p[2], $newText);
                    $found = true; break;
                }
            }
            unset($l);
            if (!$found) {
                $pags = array_unique(array_filter(array_map('parseLine', $lines), fn($p)=>$p!==null));
                $pagList = implode(', ', array_slice(array_column(array_values(array_filter(array_map('parseLine',$lines))), 0), 0, 10));
                echo json_encode(['ok'=>false,'error'=>"Line not found: pag=$pag linenum=$linenum. Sample pags: $pagList"]);
                exit;
            }
            break;
        }

        // 2. Add a new line after the last line of a folio
        case 'add_line': {
            $pag     = $_POST['pag']  ?? '';
            $secCode = $sec;
            // Find the last line index of this folio
            $lastIdx  = -1;
            $lastNum  = 0;
            foreach ($lines as $i => $l) {
                $p = parseLine($l);
                if ($p && $p[0]===$pag) { $lastIdx = $i; $lastNum = $p[1]; }
            }
            if ($lastIdx === -1) { echo json_encode(['ok'=>false,'error'=>'Folio not found']); exit; }
            $newLine = buildLine($pag, $lastNum+1, $secCode, '');
            array_splice($lines, $lastIdx+1, 0, [$newLine]);
            break;
        }

        // 3. Shift page break: change $fromPag to $toPag and cascade all following pages
        case 'shift_page': {
            $fromPag = $_POST['from_pag'] ?? '';
            // We reassign: the first line currently labelled $fromPag+1 gets $fromPag,
            // and everything from the original $fromPag onward gets bumped one step.
            // Strategy: walk all lines, collect distinct folio labels that are >= fromPag
            // in canonical sort order, then rename them in reverse to avoid collisions.
            $pagOrder = [];
            foreach ($lines as $l) {
                $p = parseLine($l);
                if ($p) $pagOrder[$p[0]] = true;
            }
            // Canonical compare: numeric folio then side
            function pagVal($pag) {
                if (preg_match('/^(\d+)(a|b)$/', $pag, $m))
                    return (int)$m[1] * 2 + ($m[2]==='b' ? 1 : 0);
                return -1;
            }
            $fromVal = pagVal($fromPag);
            // Collect folios >= fromPag, sorted descending
            $toShift = [];
            foreach (array_keys($pagOrder) as $pg) {
                if (pagVal($pg) >= $fromVal) $toShift[] = $pg;
            }
            usort($toShift, fn($a,$b) => pagVal($b) - pagVal($a)); // desc

            foreach ($toShift as $pg) {
                $newPg = incPag($pg);
                foreach ($lines as &$l) {
                    $p = parseLine($l);
                    if ($p && $p[0]===$pg) $l = buildLine($newPg, $p[1], $p[2], $p[3]);
                }
                unset($l);
            }
            break;
        }

        // 4. Split a line: cut at $splitPos, rename second part as next line number
        case 'split_line': {
            $pag       = $_POST['pag']              ?? '';
            $linenum   = (int)($_POST['linenum']    ?? 0);
            // Accept multiple positions as JSON array, e.g. [12, 45, 78]
            $posJson   = $_POST['split_positions']  ?? '[]';
            $positions = json_decode($posJson, true);
            if (!is_array($positions) || !count($positions)) {
                echo json_encode(['ok'=>false,'error'=>'No split positions provided']);
                exit;
            }
            // Sort ascending, deduplicate
            $positions = array_values(array_unique($positions));
            sort($positions);
            // Find the line
            $foundIdx = -1; $foundParsed = null;
            foreach ($lines as $i => $l) {
                $p = parseLine($l);
                if ($p && $p[0]===$pag && $p[1]===$linenum) { $foundIdx=$i; $foundParsed=$p; break; }
            }
            if ($foundIdx === -1) {
                echo json_encode(['ok'=>false,'error'=>"Line not found (split): pag=$pag linenum=$linenum"]);
                exit;
            }
            $text    = $foundParsed[3];
            $secCode = $foundParsed[2];
            $n       = count($positions); // number of new lines inserted
            // Renumber all subsequent lines on this folio first
            foreach ($lines as &$l) {
                $p = parseLine($l);
                if ($p && $p[0]===$pag && $p[1] > $linenum) {
                    $l = buildLine($p[0], $p[1]+$n, $p[2], $p[3]);
                }
            }
            unset($l);
            // Build the new segments
            $segments = [];
            $prev = 0;
            foreach ($positions as $pos) {
                $segments[] = mb_substr($text, $prev, $pos - $prev);
                $prev = $pos;
            }
            $segments[] = mb_substr($text, $prev); // last part
            // Replace the original line and splice in the new ones
            $newLines = [];
            foreach ($segments as $k => $seg) {
                $newLines[] = buildLine($pag, $linenum + $k, $secCode, $seg);
            }
            array_splice($lines, $foundIdx, 1, $newLines);
            break;
        }

        // 5. Delete a line and renumber subsequent lines on the same folio
        case 'delete_line': {
            $pag     = $_POST['pag']     ?? '';
            $linenum = (int)($_POST['linenum'] ?? 0);
            // Find and remove the line — try exact match first, then loose int match
            $foundIdx = -1;
            $allPags  = [];
            foreach ($lines as $i => $l) {
                $p = parseLine($l);
                if (!$p) continue;
                $allPags[] = $p[0];
                if ($p[0] === $pag && $p[1] === $linenum) { $foundIdx = $i; break; }
            }
            if ($foundIdx === -1) {
                $unique = array_unique($allPags);
                echo json_encode(['ok'=>false,'error'=>"Line not found: pag=$pag linenum=$linenum. Pags in file: ".implode(', ', array_slice($unique,0,10))]);
                exit;
            }
            array_splice($lines, $foundIdx, 1);
            // Renumber subsequent lines on this folio: linenum+1 → linenum, etc.
            foreach ($lines as &$l) {
                $p = parseLine($l);
                if ($p && $p[0]===$pag && $p[1] > $linenum) {
                    $l = buildLine($p[0], $p[1]-1, $p[2], $p[3]);
                }
            }
            unset($l);
            break;
        }

        default:
            echo json_encode(['ok'=>false,'error'=>'Unknown action']);
            exit;
    }

    // ── Write log ────────────────────────────────────────────────────
    $logPath = __DIR__ . '/edit_text.log';
    $ts      = date('Y-m-d H:i:s');
    $coll_p  = $_POST['coll']  ?? '?';
    $title_p = $_POST['title'] ?? '?';
    $vol_p   = $_POST['vol']   ?? '?';
    $sec_p   = $_POST['sec']   ?? '';
    $ref     = trim("$coll_p $title_p vol.$vol_p" . ($sec_p ? " [$sec_p]" : ''));

    switch ($action) {
        case 'edit_line':
            $pag_l  = $_POST['pag']     ?? '?';
            $lnum_l = $_POST['linenum'] ?? '?';
            $txt_l  = $_POST['text']    ?? '';
            $entry  = "EDIT     $ref  folio $pag_l  line $lnum_l  →  $txt_l";
            break;
        case 'add_line':
            $pag_l  = $_POST['pag'] ?? '?';
            $entry  = "ADD      $ref  folio $pag_l  (new line appended)";
            break;
        case 'shift_page':
            $from_l = $_POST['from_pag'] ?? '?';
            $entry  = "SHIFT    $ref  from folio $from_l onward (cascade +1 side)";
            break;
        case 'split_line':
            $pag_l  = $_POST['pag']             ?? '?';
            $lnum_l = $_POST['linenum']          ?? '?';
            $pos_l  = $_POST['split_positions']  ?? '[]';
            $n_l    = count(json_decode($pos_l, true) ?: []);
            $entry  = "SPLIT    $ref  folio $pag_l  line $lnum_l  ($n_l split" . ($n_l>1?'s':'') . " at positions $pos_l)";
            break;
        case 'delete_line':
            $pag_l  = $_POST['pag']     ?? '?';
            $lnum_l = $_POST['linenum'] ?? '?';
            $entry  = "DELETE   $ref  folio $pag_l  line $lnum_l";
            break;
        default:
            $entry  = "UNKNOWN  $ref  action=$action";
    }

    file_put_contents($logPath, "[$ts]  $entry\n", FILE_APPEND | LOCK_EX);

    // Write back
    $result = file_put_contents($etextPath, implode("\n", $lines));
    if ($result === false) { echo json_encode(['ok'=>false,'error'=>'Write failed']); exit; }
    echo json_encode(['ok'=>true]);
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>rKTs – Edit etext</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="icon" type="image/png" sizes="32x32" href="../../configuration/favicon-32x32.png">
    <link rel="stylesheet" href="../../configuration/style.css">

    <script src="https://cdn.jsdelivr.net/npm/openseadragon@4.1/build/openseadragon/openseadragon.min.js"></script>

    <style>
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background: #1a1a1a; color: #e0e0e0; }

        /* ── Top bar ── */
        #topbar {
            height: 52px; background: #fff; border-bottom: 1px solid #dee2e6;
            display: flex; align-items: center; gap: 0.8rem;
            padding: 0 0.9rem; flex-shrink: 0; overflow: hidden;
        }
        #topbar .logo img { height: 32px; width: auto; display: block; }
        #topbar .logo-text { color: #2c4a6e; font-weight: 700; font-size: 1.05rem; }
        #topbar .divider   { width: 1px; height: 22px; background: #dee2e6; flex-shrink: 0; }
        #topbar .back-link { color: #2c6fad; font-size: 0.82rem; text-decoration: none; white-space: nowrap; }
        #topbar .back-link:hover { color: #1a4f8a; text-decoration: underline; }
        #coll-title-strip { font-size: 0.78rem; color: #343a40; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1 1 0; min-width: 0; }
        .meta-item { font-size: 0.75rem; color: #6c757d; white-space: nowrap; }
        .meta-item strong { color: #343a40; font-weight: 600; }

        /* editor badge */
        #editor-badge {
            background: #fff3cd; border: 1px solid #ffc107; color: #856404;
            font-size: 0.7rem; font-weight: 700; border-radius: 3px; padding: 2px 7px;
            letter-spacing: 0.06em; text-transform: uppercase; flex-shrink: 0;
        }

        /* ── Nav bar ── */
        #nav-bar {
            height: 40px; background: #f8f9fa; border-bottom: 1px solid #dee2e6;
            display: flex; align-items: center; gap: 0.4rem;
            padding: 0 0.9rem; flex-shrink: 0;
        }
        #page-label { font-size: 0.9rem; min-width: 76px; text-align: center; color: #343a40; font-weight: 600; }
        #page-count { font-size: 0.72rem; color: #adb5bd; }
        #page-input { width: 68px; font-size: 0.82rem; background: #fff; border: 1px solid #ced4da; color: #343a40; border-radius: 3px; padding: 2px 6px; text-align: center; }
        #page-input:focus { outline: none; border-color: #2c6fad; box-shadow: 0 0 0 2px rgba(44,111,173,0.15); }
        #nav-right { margin-left: auto; display: flex; align-items: center; gap: 0.4rem; }

        /* ── Main layout ── */
        #main-area { display: flex; flex-direction: column; height: calc(100vh - 52px - 40px); overflow: hidden; }
        #viewer-row { display: flex; height: 55vh; flex-shrink: 0; min-height: 100px; overflow: hidden; }

        /* ── Image viewer ── */
        #viewer-wrap { position: relative; flex: 1 1 0; min-width: 0; background: #111; }
        #viewer      { width: 100%; height: 100%; }
        #loading-overlay { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 0.8rem; background: #111; z-index: 10; }
        #loading-overlay .spinner-border { width: 2.2rem; height: 2.2rem; color: #6ea8d8; }
        #loading-overlay .load-msg { font-size: 0.8rem; color: #666; }
        #error-overlay { position: absolute; inset: 0; display: none; align-items: center; justify-content: center; background: #111; z-index: 10; }
        #error-overlay .alert { background: #2a1a1a; border-color: #6b2a2a; color: #e8a0a0; max-width: 480px; }
        #manifest-bar { position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.55); backdrop-filter: blur(2px); padding: 2px 8px; display: flex; align-items: center; gap: 0.3rem; font-size: 0.68rem; font-family: monospace; white-space: nowrap; overflow: hidden; z-index: 5; }
        #manifest-label { color: #adb5bd; flex-shrink: 0; }
        #manifest-url   { color: #6ea8d8; text-overflow: ellipsis; overflow: hidden; text-decoration: none; }
        #manifest-url:hover { color: #90c4f0; text-decoration: underline; }

        /* ── Resize handle ── */
        #resize-handle { height: 5px; cursor: row-resize; flex-shrink: 0; background: #dee2e6; }
        #resize-handle:hover, #resize-handle.dragging { background: #adb5bd; }

        /* ── eText / editor pane ── */
        #etext-pane { flex: 1 1 0; min-height: 80px; background: #fff; display: flex; flex-direction: column; overflow: hidden; }
        #etext-header { padding: 0.3rem 0.85rem; background: #f8f9fa; border-bottom: 1px solid #dee2e6; display: flex; align-items: center; gap: 0.6rem; flex-shrink: 0; }
        .etext-title  { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em; color: #6c757d; font-weight: 700; }
        #etext-page-badge { font-size: 0.72rem; background: #fff3cd; border: 1px solid #ffc107; color: #856404; border-radius: 3px; padding: 1px 6px; font-family: monospace; }
        #etext-status { font-size: 0.72rem; color: #adb5bd; }

        /* header right-side action buttons */
        #etext-actions { margin-left: auto; display: flex; align-items: center; gap: 0.4rem; }

        #etext-body { flex: 1 1 0; overflow-y: auto; padding: 0.6rem 0.9rem; }

        /* ── Line rows ── */
        .eline-row {
            display: flex; align-items: flex-start; gap: 0.5rem;
            margin-bottom: 0.25rem; border-radius: 4px; padding: 2px 4px;
            transition: background 0.1s;
        }
        .eline-row:hover { background: #f0f5fb; }
        .eline-num  { color: #adb5bd; font-size: 0.68rem; min-width: 20px; padding-top: 5px; flex-shrink: 0; font-variant-numeric: tabular-nums; }
        .eline-text { color: #212529; font-size: 0.84rem; line-height: 1.85; flex: 1 1 0; min-width: 0; word-break: break-all; }

        /* inline editor */
        .eline-edit-input {
            flex: 1 1 0; font-size: 0.84rem; line-height: 1.6; font-family: inherit;
            border: 1px solid #2c6fad; border-radius: 3px; padding: 2px 6px;
            outline: none; background: #f0f5ff; color: #212529; width: 100%;
        }
        .eline-edit-input:focus { box-shadow: 0 0 0 2px rgba(44,111,173,0.2); }

        /* action buttons per line */
        .eline-btns { display: flex; align-items: center; gap: 3px; flex-shrink: 0; opacity: 0; transition: opacity 0.15s; min-width: 66px; justify-content: flex-end; }
        .eline-row:hover .eline-btns { opacity: 1; }
        .eline-btn {
            background: none; border: 1px solid transparent; border-radius: 3px;
            font-size: 0.72rem; padding: 1px 5px; cursor: pointer; color: #6c757d;
            line-height: 1.5; transition: background 0.1s, color 0.1s;
        }
        .eline-btn:hover { background: #e9ecef; color: #212529; border-color: #ced4da; }
        .eline-btn.save-btn  { color: #2d6a4f; }
        .eline-btn.save-btn:hover  { background: #d4edda; border-color: #5a9e6f; }
        .eline-btn.split-btn { color: #9c5a00; }
        .eline-btn.split-btn:hover { background: #fff3cd; border-color: #ffc107; }
        .eline-btn.cancel-btn { color: #8b2020; }
        .eline-btn.cancel-btn:hover { background: #f8d7da; border-color: #f5c6cb; }
        .eline-btn.delete-btn { color: #8b2020; }
        .eline-btn.delete-btn:hover { background: #f8d7da; border-color: #f5c6cb; }

        /* add-line row */
        #add-line-row {
            display: flex; align-items: center; gap: 0.5rem;
            margin-top: 0.5rem; padding: 4px 4px;
        }
        #add-line-btn {
            font-size: 0.78rem; color: #2c6fad; background: #f0f5fb;
            border: 1px dashed #c8d6e5; border-radius: 4px;
            padding: 3px 10px; cursor: pointer; transition: background 0.1s;
        }
        #add-line-btn:hover { background: #d0e4f7; }

        /* no-etext */
        .no-etext { font-size: 0.78rem; color: #adb5bd; font-style: italic; }

        /* ── Shift page modal ── */
        .modal-sm2 { max-width: 380px; }
        .modal-header-warning { background: #fff3cd; border-bottom-color: #ffc107; }

        /* ── Split modal ── */
        /* split modal */
        #split-line-display {
            font-family: inherit; font-size: 0.9rem; line-height: 2;
            white-space: pre-wrap; word-break: break-all; cursor: text;
            padding: 8px 10px; border: 1px solid #dee2e6; border-radius: 4px;
            background: #fafafa; user-select: none;
            color: #212529;
        }
        .split-marker {
            display: inline-block; width: 0; height: 0;
            border-left: 8px solid transparent; border-right: 8px solid transparent;
            border-top: 10px solid #2c6fad;
            vertical-align: top; margin: 0 -8px; cursor: pointer;
            position: relative; top: 0;
        }
        .split-marker:hover { border-top-color: #a04040; }
        #split-segments-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; margin-top: 0.5rem; }
        #split-segments-table th { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.07em; color: #adb5bd; font-weight: 700; padding: 3px 6px; border-bottom: 1px solid #dee2e6; }
        #split-segments-table td { padding: 3px 6px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        #split-segments-table td:first-child { color: #adb5bd; font-size: 0.72rem; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .split-hint { font-size: 0.78rem; color: #6c757d; margin-bottom: 0.5rem; }

        /* ── Status toast ── */
        #toast-wrap { position: fixed; bottom: 1.2rem; right: 1.2rem; z-index: 9999; display: flex; flex-direction: column; gap: 0.4rem; }
        .toast-msg {
            font-size: 0.82rem; padding: 6px 14px; border-radius: 4px;
            background: #2d6a4f; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.25);
            animation: fadeInUp 0.2s ease;
        }
        .toast-msg.err { background: #8b2020; }
        @keyframes fadeInUp { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }

        /* ── Nav buttons ── */
        .btn-nav {
            background: #fff; border: 1px solid #ced4da; color: #495057;
            padding: 2px 9px; border-radius: 3px; font-size: 0.82rem;
            cursor: pointer; transition: background 0.1s; white-space: nowrap; line-height: 1.6;
        }
        .btn-nav:hover:not(:disabled) { background: #e9ecef; color: #212529; border-color: #adb5bd; }
        .btn-nav:disabled { opacity: 0.35; cursor: default; }
    </style>
</head>
<body>

<!-- ── Top bar ──────────────────────────────────────────────────────── -->
<div id="topbar">
    <div class="logo">
        <a href="../../index.html">
            <img src="../../configuration/rkts-logo.png" alt="rKTs"
                 onerror="this.style.display='none';document.getElementById('logo-fallback').style.display='block'">
        </a>
        <span id="logo-fallback" class="logo-text" style="display:none">rKTs</span>
    </div>
    <div class="divider"></div>
    <a href="#" class="back-link" id="back-link"><i class="bi bi-arrow-left me-1"></i><span id="rkts-display">Back</span></a>
    <div class="divider"></div>
    <div id="coll-title-strip">
        <span class="meta-item"><strong>Coll&nbsp;</strong><span id="meta-coll">—</span></span>
        <span class="meta-item ms-2"><strong>Vol&nbsp;</strong><span id="meta-vol">—</span></span>
    </div>
    <span id="editor-badge"><i class="bi bi-pencil-square me-1"></i>Edit mode</span>
</div>

<!-- ── Nav bar ──────────────────────────────────────────────────────── -->
<div id="nav-bar">
    <button class="btn-nav" id="btn-first" title="First page"><i class="bi bi-skip-start-fill"></i></button>
    <button class="btn-nav" id="btn-prev"  title="Previous"><i class="bi bi-chevron-left"></i></button>
    <span id="page-label">—</span>
    <button class="btn-nav" id="btn-next"  title="Next"><i class="bi bi-chevron-right"></i></button>
    <button class="btn-nav" id="btn-last"  title="Last page"><i class="bi bi-skip-end-fill"></i></button>
    <button class="btn-nav" id="btn-home"  title="Reset zoom"><i class="bi bi-arrows-fullscreen"></i></button>
    <button class="btn-nav" id="btn-fullscreen" title="Full screen"><i class="bi bi-fullscreen"></i></button>
    <!-- Shift page break -->
    <button class="btn-nav ms-2" id="btn-shift-page" title="Shift page break (cascade)" style="color:#856404;border-color:#ffc107;">
        <i class="bi bi-arrow-right-square"></i> Shift page
    </button>
    <div id="nav-right">
        <span id="page-count"></span>
        <input type="text" id="page-input" placeholder="go to…" title="Pagination label + Enter">
    </div>
</div>

<!-- ── Main area ─────────────────────────────────────────────────────── -->
<div id="main-area">
  <div id="viewer-row">
    <div id="viewer-wrap">
      <div id="loading-overlay">
        <div class="spinner-border" role="status"></div>
        <div class="load-msg" id="load-msg">Loading…</div>
      </div>
      <div id="error-overlay">
        <div class="alert"><i class="bi bi-exclamation-triangle me-2"></i><span id="error-msg">Could not load image.</span></div>
      </div>
      <div id="viewer"></div>
      <div id="manifest-bar">
        <span id="manifest-label">IIIF&nbsp;</span>
        <a id="manifest-url" href="#" target="_blank"></a>
      </div>
    </div>
  </div>

  <!-- ── Resize handle ── -->
  <div id="resize-handle"></div>

  <!-- ── eText / editor pane ── -->
  <div id="etext-pane">
    <div id="etext-header">
      <span class="etext-title">eText</span>
      <span id="etext-page-badge">—</span>
      <span id="etext-status"></span>
      <div id="etext-actions">
        <span style="font-size:0.72rem;color:#adb5bd;">Hover a line to edit&nbsp;·&nbsp;Click <i class="bi bi-scissors"></i> to split</span>
      </div>
    </div>
    <div id="etext-body">
      <span class="no-etext" id="etext-placeholder">Loading…</span>
    </div>
  </div>
</div>

<!-- ── Toast ─────────────────────────────────────────────────────────── -->
<div id="toast-wrap"></div>

<!-- ── Shift page modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="shiftModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm2 modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header modal-header-warning">
        <h6 class="modal-title mb-0"><i class="bi bi-arrow-right-square me-2"></i>Shift page break</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2" style="font-size:0.85rem;">
          Currently viewing folio <strong id="shift-current-pag">—</strong>.<br>
          Shifting will rename this folio and cascade all subsequent folios forward by one side.
        </p>
        <p class="mb-0" style="font-size:0.8rem;color:#856404;">
          <i class="bi bi-exclamation-triangle me-1"></i>
          This rewrites the etext file. Make sure you have a backup.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm btn-warning" id="shift-confirm-btn">Shift &amp; save</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Split line modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="splitModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:1200px;">
    <div class="modal-content">
      <div class="modal-header" style="background:#fff8e1;border-bottom-color:#ffc107;">
        <h6 class="modal-title mb-0"><i class="bi bi-scissors me-2"></i>Split line</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="split-hint">
          <i class="bi bi-info-circle me-1"></i>
          Click in the text to add a split point <span style="color:#2c6fad;">▼</span>.
          Click an existing marker to remove it.
          Add as many splits as needed — each segment becomes a new line.
        </p>
        <div id="split-line-display"></div>
        <table id="split-segments-table" style="display:none;">
          <thead><tr><th>Line</th><th>Text</th></tr></thead>
          <tbody id="split-segments-body"></tbody>
        </table>
      </div>
      <div class="modal-footer">
        <span id="split-count-label" style="font-size:0.78rem;color:#6c757d;margin-right:auto;"></span>
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm btn-warning" id="split-confirm-btn" disabled>Split &amp; save</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
'use strict';

/* ── URL params ─────────────────────────────────────────────────────── */
const sp      = new URLSearchParams(location.search);
const coll    = sp.get('coll')  || '';
const title   = sp.get('title') || '';
const vol     = sp.get('vol')   || '';
const sec     = sp.get('sec')   || '';
let   startPage = sp.get('page') || '';

const COLL_BASE   = '../../data/collections';
const COLLS_URL   = '../../data/general/collections.json';
const ETEXTS_BASE = '../../data/etexts';
const GENRE_CODE = { Kanjur:'1', Tanjur:'2', Anthology:'3', Other:'4' };

/* ── Populate meta labels ───────────────────────────────────────────── */
document.getElementById('meta-coll').textContent = coll || '—';
document.getElementById('meta-vol').textContent  = vol  || '—';
document.getElementById('rkts-display').textContent = 'images.html';
document.getElementById('back-link').href =
    '../../pages/images.html?' + new URLSearchParams({coll,title,vol,sec,page:startPage}).toString();

/* ── Utilities ──────────────────────────────────────────────────────── */
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function toast(msg, isErr=false) {
    const wrap = document.getElementById('toast-wrap');
    const el = document.createElement('div');
    el.className = 'toast-msg' + (isErr ? ' err' : '');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 3000);
}

/* ── State ──────────────────────────────────────────────────────────── */
let pages        = [];
let currentIdx   = 0;
let currentPage  = null;
let currentPag   = '';
let etextMap     = {};
let etextReady   = false;
let iiifTemplate = null;
let cropData     = null;
let cropSeqMap   = null;
let pendingCrop  = null;
let osd          = null;
let currentImageUrl = null;

const loadingEl  = document.getElementById('loading-overlay');
const pageLabel  = document.getElementById('page-label');
const pageCount  = document.getElementById('page-count');
const pageInput  = document.getElementById('page-input');
const btnFirst   = document.getElementById('btn-first');
const btnPrev    = document.getElementById('btn-prev');
const btnNext    = document.getElementById('btn-next');
const btnLast    = document.getElementById('btn-last');
const btnHome    = document.getElementById('btn-home');

/* ── Crop helpers ───────────────────────────────────────────────────── */
function buildCropSeqMap() {
    if (!cropData || !cropData.files) return;
    cropSeqMap = {};
    cropData.files.forEach((f, i) => {
        const m = (f.img_path || '').match(/-(\d+)b\./i);
        if (m) cropSeqMap[parseInt(m[1], 10)] = i;
    });
}

function computeCrop(page, iiifW, iiifH) {
    if (!cropData || !cropData.files) return null;
    if (!page || !page.file || !page.location) return null;
    let fileIdx;
    const pathMatch = String(page.file).match(/[-](\d+)b(?:\.[^\/]*)?$/i);
    if (pathMatch && cropSeqMap) {
        const seq = parseInt(pathMatch[1], 10);
        fileIdx = cropSeqMap[seq];
        if (fileIdx === undefined) return null;
    } else {
        const fileNum = parseInt(page.file, 10);
        if (isNaN(fileNum) || fileNum < 1) return null;
        fileIdx = fileNum - 1;
    }
    const pageIdx = (page.location === 'top') ? 0 : 1;
    const entry   = cropData.files[fileIdx];
    if (!entry || !entry.pages || !entry.pages[pageIdx]) return null;
    const rect = entry.pages[pageIdx].minAreaRect;
    const refW = entry.width, refH = entry.height;
    if (!refW || !refH) return null;
    const rapp = iiifW / refW;
    return {
        x: Math.round((rect[0] - rect[2] / 2) * rapp),
        y: Math.round((rect[1] - rect[3] / 2) * rapp),
        w: Math.round(rect[2] * rapp),
        h: Math.round(rect[3] * rapp)
    };
}

/* ── IIIF URL ───────────────────────────────────────────────────────── */
function gpbImageUrl(fileId) {
    const base = String(fileId).replace(/\.[^.]+$/, '');
    const fileNum = base.slice(-4).padStart(4, '0');
    return 'https://www.rswb.org/rkts/Gpb/I' + vol + '/I' + fileNum + '.png';
}
function iiifUrl(fileId) {
    if (coll === 'Gpb') return gpbImageUrl(fileId);
    if (iiifTemplate) {
        const volSafe = vol.replace(/-/g,'_');
        return iiifTemplate.replace('yyy', encodeURIComponent(volSafe))
                           .replace('xxx', encodeURIComponent(fileId));
    }
    if (/^https?:\/\//i.test(fileId)) {
        const base = fileId.replace(/\/+$/, '');
        return base.endsWith('/info.json') ? base : base + '/info.json';
    }
    return 'https://iiif.bdrc.io/' + fileId + '/info.json';
}

function normalizeIiifInfo(info) {
    if (Array.isArray(info.profile) && info.profile.length &&
            typeof info.profile[0] === 'object' && info.profile[0] !== null) {
        const p0 = info.profile[0], p1 = info.profile[1] || {};
        const lvl = p0['@id'] || 'http://iiif.io/api/image/2/level1.json';
        const merged = {};
        [p0, p1].forEach(src => { Object.keys(src).forEach(k => { if (k !== '@id' && src[k] != null) merged[k] = src[k]; }); });
        info.profile = [lvl, merged];
    }
    return info;
}

function openIiif(fileId) {
    const url = iiifUrl(fileId);
    // Clear previous image and any stale error immediately
    if (osd) osd.close();
    document.getElementById('error-overlay').style.display = 'none';
    loadingEl.style.display = 'flex';
    document.getElementById('load-msg').textContent = 'Loading image…';
    const mUrl = document.getElementById('manifest-url');
    if (mUrl) { mUrl.textContent = url; mUrl.href = url; }

    if (coll === 'Gpb') {
        pendingCrop = null;
        currentImageUrl = url;
        osd.open({ type: 'image', url });
        return;
    }
    fetch(url)
        .then(r => { if (!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
        .then(info => {
            info = normalizeIiifInfo(info);
            if (!info['@id']) info['@id'] = url.replace(/\/info\.json$/, '');
            currentImageUrl = info['@id'];
            pendingCrop = computeCrop(currentPage, info.width, info.height);
            osd.open(info);
        })
        .catch(err => {
            if (osd) osd.close();
            showError('Failed to load image: ' + err.message);
        });
}

function showError(msg) {
    loadingEl.style.display = 'none';
    const eo = document.getElementById('error-overlay');
    document.getElementById('error-msg').textContent = msg;
    eo.style.display = 'flex';
}
function hideLoading() { loadingEl.style.display = 'none'; }

/* ── Pagination helpers ─────────────────────────────────────────────── */
function pagToNum(p) {
    if (!p) return -1;
    const m = p.match(/^(\d+)('?)([ab]?)$/);
    if (!m) return -1;
    const base = parseInt(m[1],10);
    const prime = m[2]==="'";
    const side  = m[3];
    if (prime) return base + (side==='b'?0.7:0.6);
    return base + (side==='b'?0.5:0);
}

/* ── eText parsing ──────────────────────────────────────────────────── */
function parseEtext(txt) {
    etextMap = {};
    const re = /^(\d+'?[ab])(\d+)(?:\(([^)]*)\))?:\s*(.*)/;
    txt.split(/\r?\n/).forEach(raw => {
        const m = raw.match(re);
        if (!m) return;
        const [, pag, lnum, secCode='', text] = m;
        if (!etextMap[pag]) etextMap[pag] = [];
        etextMap[pag].push({ line: parseInt(lnum,10), text, sec: secCode });
    });
}

function loadEtext(url) {
    document.getElementById('etext-status').textContent = 'loading…';
    fetch(url)
        .then(r => { if (!r.ok) throw new Error('HTTP '+r.status); return r.text(); })
        .then(txt => {
            document.getElementById('etext-status').textContent = '';
            parseEtext(txt);
            etextReady = true;
            if (currentPag) renderEtext(currentPag);
        })
        .catch(() => {
            document.getElementById('etext-status').textContent = '';
            etextReady = true;
            document.getElementById('etext-body').innerHTML = '<span class="no-etext">No etext available for this volume.</span>';
        });
}

/* ── Render etext with edit controls ───────────────────────────────── */
function renderEtext(pag) {
    currentPag = pag;
    document.getElementById('etext-page-badge').textContent = pag || '—';
    const body = document.getElementById('etext-body');

    if (!etextReady) { body.innerHTML = '<span class="no-etext">Loading etext…</span>'; return; }

    let lines = etextMap[pag];
    if (sec && lines) {
        const filtered = lines.filter(l => l.sec === sec);
        if (filtered.length) lines = filtered;
    }

    if (!lines || !lines.length) {
        body.innerHTML = '<span class="no-etext">No etext for page ' + esc(pag) + '.</span>'
            + '<div id="add-line-row"><button id="add-line-btn"><i class="bi bi-plus-circle me-1"></i>Add first line for this page</button></div>';
        document.getElementById('add-line-btn').addEventListener('click', () => addLine(pag));
        return;
    }

    let html = '';
    lines.forEach(l => {
        html += `<div class="eline-row" data-pag="${esc(pag)}" data-line="${l.line}">
            <div class="eline-btns">
                <button class="eline-btn edit-btn" title="Edit this line"><i class="bi bi-pencil"></i></button>
                <button class="eline-btn split-btn" title="Split this line"><i class="bi bi-scissors"></i></button>
                <button class="eline-btn delete-btn" title="Delete this line"><i class="bi bi-trash3"></i></button>
            </div>
            <span class="eline-num">${l.line}</span>
            <span class="eline-text">${esc(l.text)}</span>
        </div>`;
    });
    html += `<div id="add-line-row">
        <button id="add-line-btn"><i class="bi bi-plus-circle me-1"></i>Add line ${lines[lines.length-1].line + 1}</button>
    </div>`;

    body.innerHTML = html;

    // Wire up edit buttons
    body.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            const row = e.target.closest('.eline-row');
            startInlineEdit(row);
        });
    });

    // Wire up split buttons
    body.querySelectorAll('.split-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            const row = e.target.closest('.eline-row');
            const pag = row.dataset.pag;
            const linenum = parseInt(row.dataset.line, 10);
            const text = row.querySelector('.eline-text')?.textContent ?? '';
            openSplitModal(pag, linenum, text);
        });
    });

    // Wire up delete buttons
    body.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            const row     = e.target.closest('.eline-row');
            const pag     = row.dataset.pag;
            const linenum = parseInt(row.dataset.line, 10);
            const preview = row.querySelector('.eline-text')?.textContent ?? '';
            openDeleteConfirm(row, pag, linenum, preview);
        });
    });

    // Wire up add-line button
    document.getElementById('add-line-btn').addEventListener('click', () => addLine(pag));
}

/* ── Inline editing ─────────────────────────────────────────────────── */
function startInlineEdit(row) {
    if (row.dataset.editing) return;
    row.dataset.editing = '1';
    const textSpan = row.querySelector('.eline-text');
    const origText = textSpan.textContent;
    const btns     = row.querySelector('.eline-btns');
    const pag      = row.dataset.pag;
    const linenum  = parseInt(row.dataset.line, 10);

    // Replace span with input
    const input = document.createElement('input');
    input.type  = 'text';
    input.className = 'eline-edit-input';
    input.value = origText;
    textSpan.replaceWith(input);
    input.focus();
    input.select();

    // Replace buttons with save/cancel
    btns.innerHTML = `<button class="eline-btn save-btn" title="Save"><i class="bi bi-check-lg"></i></button>
                      <button class="eline-btn cancel-btn" title="Cancel"><i class="bi bi-x-lg"></i></button>`;

    function doSave() {
        const newText = input.value;
        saveAction('edit_line', { pag, linenum, text: newText }, () => {
            // Update local map
            const entry = etextMap[pag];
            if (entry) { const l = entry.find(x => x.line===linenum); if (l) l.text = newText; }
            renderEtext(pag);
        });
    }

    function doCancel() { renderEtext(pag); }

    btns.querySelector('.save-btn').addEventListener('click', doSave);
    btns.querySelector('.cancel-btn').addEventListener('click', doCancel);
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter')  { e.preventDefault(); doSave(); }
        if (e.key === 'Escape') { e.preventDefault(); doCancel(); }
    });
    // Make row always show buttons while editing
    btns.style.opacity = '1';
    row.style.background = '#f0f5ff';
}

/* ── Add line ───────────────────────────────────────────────────────── */
function addLine(pag) {
    saveAction('add_line', { pag }, () => {
        // Reload etext from server to get updated content
        reloadEtext(pag);
    });
}

/* ── Delete line (inline confirm) ──────────────────────────────────── */
function openDeleteConfirm(row, pag, linenum, preview) {
    // If another confirm is already open on a different row, close it first
    document.querySelectorAll('.eline-row.delete-confirming').forEach(r => {
        if (r !== row) cancelDeleteConfirm(r);
    });
    if (row.dataset.deleteConfirm) return; // already open
    row.dataset.deleteConfirm = '1';
    row.classList.add('delete-confirming');
    row.style.background = '#fff0f0';

    const btns = row.querySelector('.eline-btns');
    btns.style.opacity = '1';
    btns.innerHTML =
        `<span style="font-size:0.72rem;color:#8b2020;white-space:nowrap;">Delete line ${linenum}?</span>
         <button class="eline-btn confirm-delete-btn" title="Yes, delete" style="color:#8b2020;background:#f8d7da;border-color:#f5c6cb;">
             <i class="bi bi-check-lg"></i> Yes
         </button>
         <button class="eline-btn cancel-delete-btn" title="Cancel">
             <i class="bi bi-x-lg"></i>
         </button>`;

    btns.querySelector('.confirm-delete-btn').addEventListener('click', () => {
        saveAction('delete_line', { pag, linenum }, () => {
            reloadEtext(pag);
        });
    });
    btns.querySelector('.cancel-delete-btn').addEventListener('click', () => {
        cancelDeleteConfirm(row);
        renderEtext(pag); // re-render to restore buttons cleanly
    });
}

function cancelDeleteConfirm(row) {
    delete row.dataset.deleteConfirm;
    row.classList.remove('delete-confirming');
    row.style.background = '';
}

/* ── Shift page ─────────────────────────────────────────────────────── */
document.getElementById('btn-shift-page').addEventListener('click', () => {
    document.getElementById('shift-current-pag').textContent = currentPag;
    new bootstrap.Modal(document.getElementById('shiftModal')).show();
});
document.getElementById('shift-confirm-btn').addEventListener('click', function () {
    bootstrap.Modal.getInstance(document.getElementById('shiftModal'))?.hide();
    saveAction('shift_page', { from_pag: currentPag }, () => {
        toast('Page break shifted. Reloading etext…');
        reloadEtext(currentPag);
    });
});

/* ── Split modal ────────────────────────────────────────────────────── */
/* ── Split modal (multi-split) ──────────────────────────────────────── */
let splitState = { pag:'', linenum:0, text:'', positions:[] };

function openSplitModal(pag, linenum, text) {
    splitState = { pag, linenum, text, positions: [] };
    renderSplitDisplay();
    document.getElementById('split-confirm-btn').disabled = true;
    document.getElementById('split-count-label').textContent = '';
    document.getElementById('split-segments-table').style.display = 'none';
    new bootstrap.Modal(document.getElementById('splitModal')).show();
}

function charPosFromClick(display, e) {
    // Use caretRangeFromPoint / caretPositionFromPoint to get char offset
    let pos = 0;
    const range = document.caretRangeFromPoint?.(e.clientX, e.clientY)
               ?? (() => { const cp = document.caretPositionFromPoint?.(e.clientX, e.clientY); if (!cp) return null; const r = document.createRange(); r.setStart(cp.offsetNode, cp.offset); return r; })();
    if (!range) return -1;
    const walker = document.createTreeWalker(display, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) {
        if (node === range.startContainer) { pos += range.startOffset; break; }
        pos += node.length;
    }
    return pos;
}

function renderSplitDisplay() {
    const { text, positions, linenum } = splitState;
    const div     = document.getElementById('split-line-display');

    // Build HTML with markers sorted
    const sorted = [...new Set(positions)].filter(p => p > 0 && p < text.length).sort((a,b) => a-b);
    let html = '';
    let prev = 0;
    sorted.forEach(p => {
        html += esc(text.slice(prev, p))
             + `<span class="split-marker" data-pos="${p}" title="Click to remove this split"></span>`;
        prev = p;
    });
    html += esc(text.slice(prev));
    div.innerHTML = html || '<em style="color:#adb5bd">(empty)</em>';

    // Wire marker clicks to remove
    div.querySelectorAll('.split-marker').forEach(m => {
        m.addEventListener('click', e => {
            e.stopPropagation();
            const pos = parseInt(m.dataset.pos, 10);
            splitState.positions = splitState.positions.filter(p => p !== pos);
            renderSplitDisplay();
        });
    });

    // Wire display click to add split
    div.onclick = function(e) {
        if (e.target.classList.contains('split-marker')) return;
        let pos = charPosFromClick(div, e);
        if (pos < 1 || pos >= text.length) return;
        // Snap away from existing markers (±2 chars)
        if (splitState.positions.some(p => Math.abs(p - pos) < 2)) return;
        splitState.positions.push(pos);
        renderSplitDisplay();
    };

    // Update segments table
    const tbody     = document.getElementById('split-segments-body');
    const table     = document.getElementById('split-segments-table');
    const countLabel = document.getElementById('split-count-label');
    const confirmBtn = document.getElementById('split-confirm-btn');
    if (!sorted.length) {
        table.style.display = 'none';
        countLabel.textContent = '';
        confirmBtn.disabled = true;
        return;
    }
    // Build segments
    const segs = [];
    let p2 = 0;
    sorted.forEach(sp => { segs.push(text.slice(p2, sp)); p2 = sp; });
    segs.push(text.slice(p2));

    tbody.innerHTML = segs.map((seg, i) =>
        `<tr>
            <td>${linenum + i}</td>
            <td>${esc(seg) || '<em style="color:#adb5bd">empty</em>'}</td>
        </tr>`
    ).join('');
    table.style.display = '';
    countLabel.textContent = `${sorted.length} split${sorted.length>1?'s':''} → ${segs.length} lines`;
    confirmBtn.disabled = false;
}

document.getElementById('split-confirm-btn').addEventListener('click', function () {
    const { pag, linenum, text, positions } = splitState;
    const sorted = [...new Set(positions)].filter(p => p > 0 && p < text.length).sort((a,b) => a-b);
    if (!sorted.length) return;
    bootstrap.Modal.getInstance(document.getElementById('splitModal'))?.hide();
    saveAction('split_line', { pag, linenum, split_positions: JSON.stringify(sorted) }, () => {
        reloadEtext(pag);
    });
});

/* ── POST to PHP save endpoint (this same file) ─────────────────────── */
function saveAction(action, extra, onSuccess) {
    const body = new URLSearchParams({ action, coll, title, vol, sec, etext_url: etextUrl || '', ...extra });
    document.getElementById('etext-status').textContent = 'saving…';
    fetch(location.pathname, { method:'POST', body })
        .then(r => r.json())
        .then(data => {
            document.getElementById('etext-status').textContent = '';
            if (data.ok) {
                toast('Saved.');
                if (onSuccess) onSuccess();
            } else {
                toast('Error: ' + (data.error || 'unknown'), true);
            }
        })
        .catch(err => {
            document.getElementById('etext-status').textContent = '';
            toast('Network error: ' + err.message, true);
        });
}

/* ── Reload etext from server ───────────────────────────────────────── */
let etextUrl = null;

function reloadEtext(pag) {
    if (!etextUrl) return;
    fetch(etextUrl + '?_=' + Date.now())
        .then(r => r.ok ? r.text() : '')
        .then(txt => {
            if (txt) parseEtext(txt);
            renderEtext(pag);
        });
}

/* ── Navigation ─────────────────────────────────────────────────────── */
function goTo(idx) {
    if (idx < 0 || idx >= pages.length) return;
    currentIdx  = idx;
    currentPage = pages[idx];
    const pag = currentPage.pagination || ('p' + (idx+1));
    currentPag = pag;
    pageLabel.textContent = pag;
    pageCount.textContent = (idx+1) + '\u202f/\u202f' + pages.length;
    btnFirst.disabled = idx === 0;
    btnPrev.disabled  = idx === 0;
    btnNext.disabled  = idx === pages.length - 1;
    btnLast.disabled  = idx === pages.length - 1;
    const sp2 = new URLSearchParams(location.search);
    sp2.set('page', pag);
    history.replaceState(null, '', '?' + sp2.toString());
    openIiif(currentPage.file);
    renderEtext(pag);
}

btnFirst.addEventListener('click', () => goTo(0));
btnPrev.addEventListener('click',  () => goTo(currentIdx - 1));
btnNext.addEventListener('click',  () => goTo(currentIdx + 1));
btnLast.addEventListener('click',  () => goTo(pages.length - 1));
btnHome.addEventListener('click',  () => { if (osd) osd.viewport.goHome(true); });

const btnFs    = document.getElementById('btn-fullscreen');
const fsTarget = document.getElementById('viewer-wrap');
btnFs.addEventListener('click', () => {
    if (!document.fullscreenElement) fsTarget.requestFullscreen().catch(()=>{});
    else document.exitFullscreen().catch(()=>{});
});
document.addEventListener('fullscreenchange', () => {
    const icon = btnFs.querySelector('i');
    if (document.fullscreenElement) { icon.className='bi bi-fullscreen-exit'; btnFs.title='Exit full screen'; }
    else { icon.className='bi bi-fullscreen'; btnFs.title='Full screen'; }
});

document.addEventListener('keydown', e => {
    if (e.target === pageInput || e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown')  { e.preventDefault(); goTo(currentIdx+1); }
    if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')    { e.preventDefault(); goTo(currentIdx-1); }
    if (e.key === 'Home') { e.preventDefault(); goTo(0); }
    if (e.key === 'End')  { e.preventDefault(); goTo(pages.length-1); }
});

pageInput.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    const target = pageInput.value.trim(); pageInput.value = '';
    const found = pages.findIndex(p => p.pagination === target);
    if (found >= 0) { goTo(found); }
    else { pageInput.style.borderColor='#a04040'; setTimeout(() => { pageInput.style.borderColor='#ced4da'; }, 1000); }
});

/* ── Resize handle ──────────────────────────────────────────────────── */
(function () {
    const handle    = document.getElementById('resize-handle');
    const viewerRow = document.getElementById('viewer-row');
    let dragging=false, startY=0, startH=0;
    handle.addEventListener('mousedown', e => {
        dragging=true; startY=e.clientY; startH=viewerRow.offsetHeight;
        handle.classList.add('dragging');
        document.body.style.cursor='row-resize'; document.body.style.userSelect='none';
    });
    document.addEventListener('mousemove', e => {
        if (!dragging) return;
        const newH = Math.max(100, Math.min(window.innerHeight*0.85, startH+(e.clientY-startY)));
        viewerRow.style.height = newH + 'px';
    });
    document.addEventListener('mouseup', () => {
        if (!dragging) return;
        dragging=false; handle.classList.remove('dragging');
        document.body.style.cursor=''; document.body.style.userSelect='';
        if (osd) osd.viewport.goHome(true);
    });
})();

/* ── OpenSeadragon init ─────────────────────────────────────────────── */
function initOsd() {
    osd = OpenSeadragon({
        id: 'viewer',
        prefixUrl: 'https://cdn.jsdelivr.net/npm/openseadragon@4.1/build/openseadragon/images/',
        showNavigationControl: false,
        animationTime: 0.3, blendTime: 0.1,
        constrainDuringPan: false, maxZoomPixelRatio: 4,
        minZoomImageRatio: 0.5, visibilityRatio: 0.5,
        defaultZoomLevel: 0, tileSources: null
    });
    osd.addHandler('open', function () {
        if (pendingCrop) {
            const c = pendingCrop; pendingCrop = null;
            const tiledImage = osd.world.getItemAt(0);
            if (tiledImage) {
                const imageRect    = new OpenSeadragon.Rect(c.x, c.y, c.w, c.h);
                tiledImage.setClip(imageRect);
                const viewportRect = tiledImage.imageToViewportRectangle(imageRect);
                osd.viewport.fitBounds(viewportRect, true);
            }
        }
        let done = false;
        function finish() { if (!done) { done = true; hideLoading(); } }
        if (coll === 'Gpb') { finish(); return; }
        osd.addOnceHandler('tile-drawn', finish);
        setTimeout(finish, 8000);
    });
    osd.addHandler('open-failed', () => showError('Failed to load image.'));
}

/* ── Bootstrap: load volume JSON + etext ───────────────────────────── */
if (!title || !coll || !vol) { showError('Missing required URL parameters (title, coll, vol).'); }
else {
    initOsd();

    const jsonUrl     = COLL_BASE + '/' + encodeURIComponent(coll) + '/' + encodeURIComponent(title) + '/' + encodeURIComponent(vol) + '.json';
    const manifestUrl = COLL_BASE + '/' + encodeURIComponent(coll) + '/' + encodeURIComponent(title) + '/manifest.txt';

    document.getElementById('load-msg').textContent = 'Loading volume index…';

    const cropUrl = COLL_BASE + '/' + encodeURIComponent(coll) + '/' + encodeURIComponent(title) + '_Crop/' + encodeURIComponent(vol) + '.json';

    Promise.all([
        fetch(jsonUrl).then(r => { if (!r.ok) throw new Error('HTTP '+r.status+' – '+jsonUrl); return r.json(); }),
        fetch(manifestUrl).then(r => r.ok ? r.text() : null).catch(()=>null),
        fetch(cropUrl).then(r => r.ok ? r.json() : null).catch(()=>null)
    ]).then(([data, tmplText, cropJson]) => {
        if (tmplText) iiifTemplate = tmplText.trim();
        if (cropJson) { cropData = cropJson; buildCropSeqMap(); }

        pages = Object.keys(data)
            .filter(k => /^\d+$/.test(k))
            .sort((a,b) => parseInt(a,10)-parseInt(b,10))
            .map(k => data[k])
            .filter(Boolean);

        if (!pages.length) { showError('No pages found in volume JSON.'); return; }

        // Resolve etext URL via collections.json
        fetch(COLLS_URL).then(r => r.ok ? r.json() : []).catch(()=>[]).then(cols => {
            let genre='', volno='';
            const matched = cols.filter(c => c.sigla === coll);
            console.log('[edit_text] coll:', coll, '| title:', title, '| vol:', vol);
            console.log('[edit_text] collections matched by sigla:', matched.length);
            matched.forEach(c => {
                if (!genre && c.genre) genre = c.genre;
                if (!volno && c.constituents) {
                    c.constituents.forEach(con => {
                        console.log('[edit_text]   constituent title:', con.title);
                        if ((title && con.title !== title) || volno) return;
                        (con.structure||[]).forEach(s => {
                            console.log('[edit_text]     volref:', s.volref, 'volno:', s.volno);
                            if (s.volref===vol && s.volno && !volno) volno=s.volno;
                        });
                    });
                }
            });
            const code = GENRE_CODE[genre] || '';
            const pad  = String(parseInt(volno,10)||0).padStart(3,'0');
            console.log('[edit_text] genre:', genre, '| code:', code, '| volno:', volno, '| pad:', pad);
            if (code && volno) {
                etextUrl = ETEXTS_BASE + '/' + code + coll + '/' + pad + '.txt';
                console.log('[edit_text] etextUrl:', etextUrl);
                loadEtext(etextUrl);
            } else {
                console.warn('[edit_text] could not resolve etext URL – genre or volno missing');
                etextReady = true;
                document.getElementById('etext-body').innerHTML = '<span class="no-etext">No etext available for this collection.</span>';
            }
        });

        // Navigate to start page
        let startIdx = 0;
        if (startPage) {
            const f = pages.findIndex(p => p.pagination === startPage);
            if (f >= 0) startIdx = f;
        }
        goTo(startIdx);
    }).catch(err => showError('Could not load volume index: '+err.message));
}

})();
</script>
</body>
</html>