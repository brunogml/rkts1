<?php
/**
 * addnotes.php
 *
 * Reads notes.txt (format: {ref}###{note}) and writes each note into the
 * matching catalog XML entry.
 *
 * Logic:
 *  1. Parse each line: ref = part before ###, note = part after.
 *  2. Extract the collection prefix (leading letters) from the ref.
 *     e.g. "D805" -> "D", "Dk090-002" -> "Dk", "Dd049-006" -> "Dd"
 *  3. Look up the rkts number in ../collections/{coll}/{coll}.xml
 *     by finding the <item> whose <ref> matches.
 *  4. Write/update <note> in ../catalogs/{cat}/{cat}{rkts}.xml
 *     inside the <item> whose <ref> matches.
 *
 * Notes file location: same folder as this script (notes.txt)
 * Collection XML:       ../collections/{coll}/{coll}.xml
 * Catalog XML:          ../catalogs/{cat}/{cat}{rkts}.xml
 *
 * Usage: php addnotes.php [path/to/notes.txt]
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

$scriptDir    = __DIR__;
$defaultNotes = $scriptDir . '/notes.txt';
$notesFile = isset($argv[1]) ? $argv[1] : $defaultNotes;

// ---------------------------------------------------------------------------
// Helper: extract collection prefix (leading letters) from a ref string
// e.g. "D805" -> "D", "Dk090-002" -> "Dk", "Dd049-006" -> "Dd"
// ---------------------------------------------------------------------------
function collPrefix(string $ref): string {
    preg_match('/^([A-Za-z]+)/', $ref, $m);
    return $m[1] ?? '';
}

// ---------------------------------------------------------------------------
// Helper: load a SimpleXML document with error handling
// ---------------------------------------------------------------------------
function loadXml(string $path): ?SimpleXMLElement {
    if (!file_exists($path)) {
        echo "  [WARN] File not found: $path\n";
        return null;
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    if ($xml === false) {
        $errors = libxml_get_errors();
        echo "  [ERROR] Failed to parse $path\n";
        foreach ($errors as $e) {
            echo "    " . trim($e->message) . "\n";
        }
        libxml_clear_errors();
        return null;
    }
    return $xml;
}

// ---------------------------------------------------------------------------
// Helper: save a SimpleXML document back to disk, preserving XML declaration
// ---------------------------------------------------------------------------
function saveXml(SimpleXMLElement $xml, string $path): bool {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput       = true;
    $dom->loadXML($xml->asXML());
    $result = $dom->save($path);
    return $result !== false;
}

// ---------------------------------------------------------------------------
// Step 1: parse notes.txt
// ---------------------------------------------------------------------------
if (!file_exists($notesFile)) {
    die("[ERROR] Notes file not found: $notesFile\n");
}

$lines = file($notesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$notes = [];   // [ ref => note, ... ]  (last occurrence wins if duplicates)

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '###') === false) {
        continue;
    }
    [$ref, $note] = explode('###', $line, 2);
    $ref  = trim($ref);
    $note = trim($note);
    if ($ref !== '' && $note !== '') {
        $notes[$ref] = $note;
    }
}

if (empty($notes)) {
    die("[INFO] No notes found in $notesFile\n");
}

echo "Loaded " . count($notes) . " note(s) from $notesFile\n\n";

// ---------------------------------------------------------------------------
// Step 2 & 3: for each note, find the rkts number and write to catalog XML
// ---------------------------------------------------------------------------
foreach ($notes as $ref => $note) {
    echo "Processing: $ref\n";

    $coll = collPrefix($ref);
    if ($coll === '') {
        echo "  [SKIP] Cannot determine collection prefix for ref: $ref\n";
        continue;
    }

    // --- locate collection XML ---
    $collXmlPath = "$scriptDir/../collections/$coll/$coll.xml";
    $collXml = loadXml($collXmlPath);
    if ($collXml === null) {
        continue;
    }

    // --- find the matching item in the collection XML ---
    $rkts = null;
    $cat  = null;
    foreach ($collXml->item as $item) {
        if (trim((string)$item->ref) === $ref) {
            $rkts = trim((string)$item->rkts);
            $cat  = trim((string)$item->cat);
            break;
        }
    }

    if ($rkts === null) {
        echo "  [WARN] Ref '$ref' not found in $collXmlPath\n";
        continue;
    }

    if ($rkts === '0' || $rkts === '') {
        echo "  [SKIP] rkts=0 or empty for ref '$ref' — no catalog entry\n";
        continue;
    }

    echo "  rkts=$rkts, cat=$cat\n";

    // --- locate catalog XML ---
    // Path: ../catalogs/{cat}/{cat}{rkts}.xml
    $catalogFile = "$scriptDir/../catalogs/$cat/{$cat}{$rkts}.xml";
    $catalogXml  = loadXml($catalogFile);
    if ($catalogXml === null) {
        continue;
    }

    // --- find the matching item in the catalog XML by ref ---
    $found = false;
    foreach ($catalogXml->item as $item) {
        if (trim((string)$item->ref) === $ref) {
            // Set or replace <note>
            if (isset($item->note)) {
                $item->note = $note;
            } else {
                $item->addChild('note', htmlspecialchars($note, ENT_XML1, 'UTF-8'));
            }
            $found = true;
            break;
        }
    }

    if (!$found) {
        echo "  [WARN] Ref '$ref' not found in $catalogFile\n";
        continue;
    }

    // --- save updated catalog XML ---
    if (saveXml($catalogXml, $catalogFile)) {
        echo "  [OK] Note written to $catalogFile<br>";
    } else {
        echo "  [ERROR] Could not save $catalogFile<br>";
    }
}

echo "\nDone.\n";