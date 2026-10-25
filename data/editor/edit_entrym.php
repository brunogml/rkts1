<?php
/**
 * edit_entrym.php — rKTs Mongolian Catalog Entry Editor
 *
 * Derived from edit_entry.php (v5).
 * Key differences:
 *  • Catalog XML path: ../mongol/{PREFIX}/{ID}.xml  (e.g. mongol/K/K360.xml)
 *  • Primary text field: <mng> (Mongolian) instead of <tib>
 *  • <coloph> and <colophTitle> are also Mongolian text
 *  • Canon selector replaced by a single Mongolian-canon prefix input (K, G, …)
 *  • No collection XML update (Mongolian collections handled separately)
 */

// ─── SESSION / AUTH ───────────────────────────────────────────────────────────
session_start();

function loadUsers(): array {
    $path = dirname(__DIR__) . '/general/login.txt';
    if (!file_exists($path)) return [];
    $users = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $parts = explode(';', $line, 3);
        if (count($parts) < 2) continue;
        $strip = fn(string $s): string => trim(trim($s), '"');
        $users[$strip($parts[0])] = [
            'password' => $strip($parts[1]),
            'email'    => $strip($parts[2] ?? ''),
        ];
    }
    return $users;
}

if (($_POST['action'] ?? '') === 'logout') {
    unset($_SESSION['edit_entrym_user']);
    $qs = http_build_query(array_filter(['canon' => $_POST['canon'] ?? '', 'rkts' => $_POST['rkts'] ?? '']));
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? "?$qs" : ''));
    exit;
}

$loginError = '';
if (($_POST['action'] ?? '') === 'login') {
    $users    = loadUsers();
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($username !== '' && isset($users[$username])
        && $users[$username]['password'] === $password) {
        $_SESSION['edit_entrym_user'] = $username;
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } else {
        $loginError = 'Invalid username or password.';
    }
}

$loggedInUser = $_SESSION['edit_entrym_user'] ?? null;

// ─── Load persons.xml for translator rid lookup ───────────────────────────────
function load_persons_xml(string $path): array {
    if (!file_exists($path)) return [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    if (!$xml) return [];
    $out = [];
    foreach ($xml->item as $item) {
        $rid = trim((string)($item->ref ?? ''));
        if ($rid === '') continue;
        $stdname = trim((string)($item->Standardname ?? ''));
        foreach ($item->n as $n) {
            $name = trim((string)$n);
            if ($name !== '') $out[] = [$name, $rid, $stdname];
        }
        if ($stdname !== '') $out[] = [$stdname, $rid, $stdname];
    }
    return $out;
}
$persons_data = load_persons_xml(dirname(__DIR__) . '/general/persons.xml');

$BASE             = dirname(__DIR__);   // .../data
$MONGOL_DIR       = $BASE . '/mongol';
$COLLECTIONS_DIR  = $BASE . '/collections';
$COLLECTIONS_JSON = $BASE . '/general/collections.json';

// Mongolian canons: Kanjur (K) and Tanjur (T).
// The file path uses the letter prefix from the ID; the canon selector determines
// which reference setids to use from collections.json for collection-row derivation.
$MNG_CANONS = ['Kanjur' => 'K', 'Tanjur' => 'T'];

// ─── Helpers ──────────────────────────────────────────────────────────────────
/** Build the Mongolian catalog XML path from an rKTs ID string (e.g. "K360"). */
function mongol_xml_path(string $base, string $id): string {
    preg_match('/^([A-Za-z]+)/', $id, $m);
    $prefix = strtoupper($m[1] ?? '');
    return $base . '/' . $prefix . '/' . $id . '.xml';
}
/** Extract the letter prefix from an ID (e.g. "K360" → "K"). */
function id_prefix(string $id): string {
    preg_match('/^([A-Za-z]+)/', $id, $m);
    return strtoupper($m[1] ?? '');
}
/** Extract the numeric suffix from an ID (e.g. "K2" → 2, "K360" → 360). */
function id_number(string $id): int {
    preg_match('/(\d+)$/', $id, $m);
    return (int)($m[1] ?? 0);
}
function ref_to_rkts(string $ref, string $sigla): string {
    if (str_starts_with($ref, $sigla)) {
        $rest = substr($ref, strlen($sigla));
        if ($rest !== '' && ctype_digit($rest[0])) return $rest;
    }
    return ltrim($ref, 'A..Za..z') ?: $ref;
}
function xp(string $s): string { return str_replace('"', '&quot;', $s); }

// ─── Load collections.json ────────────────────────────────────────────────────
function load_collections_json(string $path): array {
    if (!file_exists($path)) return [];
    $arr = json_decode(file_get_contents($path), true) ?? [];
    // Map genre label → cat code (must match $CAT_CODES)
    $genre_to_cat = [
        'Kanjur'     => 'K', 'Tanjur' => 'T',
        'Old Tantra' => 'G', 'Bon Canon' => 'B',
        'Canonical Collection' => '',   // generic — stored under sigla only
    ];
    $out = [];
    foreach ($arr as $c) {
        $sig   = $c['sigla']  ?? ''; if ($sig === '') continue;
        $genre = $c['genre']  ?? '';
        $cc    = $genre_to_cat[$genre] ?? '';   // '' = not canon-specific

        $ref_setids = []; $all_setids = [];
        foreach ($c['constituents'] ?? [] as $con) {
            $t = $con['title'] ?? ''; if ($t === '') continue;
            $all_setids[] = $t;
            if (($con['isreferenceset'] ?? '0') === '1') $ref_setids[] = $t;
        }
        $entry = [
            'title'         => $c['title'] ?? '',
            'genre'         => $genre,
            'cat_code'      => $cc,
            'ref_setids'    => $ref_setids,
            'active_setids' => $ref_setids ?: $all_setids,
        ];
        // Store under canon-specific key (e.g. "D|K") AND bare sigla as fallback
        if ($cc !== '') {
            $out[$sig . '|' . $cc] = $entry;
        }
        // Bare sigla: only set if not yet set, so first entry wins as fallback
        if (!isset($out[$sig])) {
            $out[$sig] = $entry;
        }
    }
    return $out;
}

/** Look up a collection entry by sigla + current cat code (K/T/G/B). */
function coll_info(array $collections, string $sigla, string $cat_c): ?array {
    // Prefer canon-specific entry; fall back to bare sigla
    return $collections[$sigla . '|' . $cat_c]
        ?? $collections[$sigla]
        ?? null;
}

// ─── Load catalog XML ─────────────────────────────────────────────────────────
// Translator children: <name>, <role>, <rid>
function load_catalog_xml(string $path): array {
    if (!file_exists($path)) return [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path); if (!$xml) return [];
    $items = [];
    foreach ($xml->item as $item) {
        $sigla = (string)($item->coll ?? ''); if ($sigla === '') continue;
        $locs = [];
        foreach ($item->locations->location ?? [] as $loc) {
            $locs[] = [
                'setid' => (string)($loc->setid ?? ''), 'vol'  => (string)($loc->vol  ?? ''),
                'sec'   => (string)($loc->sec   ?? ''), 'debp' => (string)($loc->debp ?? ''),
                'debf'  => (string)($loc->debf  ?? ''), 'debl' => (string)($loc->debl ?? ''),
                'finp'  => (string)($loc->finp  ?? ''), 'finf' => (string)($loc->finf ?? ''),
                'finl'  => (string)($loc->finl  ?? ''),
            ];
        }
        $trs = [];
        foreach ($item->translators->translator ?? [] as $tr) {
            $trs[] = [
                'name' => trim((string)($tr->name ?? '')),
                'role' => trim((string)($tr->role ?? '')),
                'rid'  => trim((string)($tr->rid  ?? '')),
            ];
        }
        $bampos = [];
        foreach ($item->bampos->bampo ?? [] as $bp) {
            $bampos[] = [
                'rn'    => (string)($bp->rn    ?? ''),
                'name'  => (string)($bp->name  ?? ''),
                'setid' => (string)($bp->setid ?? ''),
                'vol'   => (string)($bp->vol   ?? ''),
                'sec'   => (string)($bp->sec   ?? ''),
                'debp'  => (string)($bp->debp  ?? ''),
                'debf'  => (string)($bp->debf  ?? ''),
                'debl'  => (string)($bp->debl  ?? ''),
            ];
        }
        $chapters = [];
        foreach ($item->chapters->chapter ?? [] as $ch) {
            $chapters[] = [
                'rn'    => (string)($ch->rn    ?? ''),
                'name'  => (string)($ch->name  ?? ''),
                'setid' => (string)($ch->setid ?? ''),
                'vol'   => (string)($ch->vol   ?? ''),
                'sec'   => (string)($ch->sec   ?? ''),
                'debp'  => (string)($ch->debp  ?? ''),
                'debf'  => (string)($ch->debf  ?? ''),
                'debl'  => (string)($ch->debl  ?? ''),
            ];
        }
        $items[] = [
            'coll'        => $sigla,
            'ref'         => (string)($item->ref         ?? ''),
            'rnb'         => (string)($item->rnb         ?? ''),
            'mng'         => (string)($item->mng         ?? ''),
            'tib'         => (string)($item->tib         ?? ''),
            'skt'         => (string)($item->skt         ?? ''),
            'coloph'      => (string)($item->coloph      ?? ''),
            'colophTitle' => (string)($item->colophTitle ?? ''),
            'margin'      => (string)($item->margin      ?? ''),
            'note'        => (string)($item->note        ?? ''),
            'locations'   => $locs,
            'translators' => $trs,
            'bampos'      => $bampos,
            'chapters'    => $chapters,
        ];
    }
    return $items;
}

// ─── Compute the effective reference setid(s) for display highlighting ──────────
// Used only for the ★ display in the UI; does NOT restrict which rows are written.
// Logic: intersect isreferenceset=1 setids with setids present in this catalog item.
// If no intersection (different canon), the item's own setids are all highlighted.
function effective_ref_setids(array $locations, array $active_setids): array {
    $non_summary = array_values(array_filter($locations, fn($l) => $l['vol'] !== ''));
    if (empty($active_setids)) return [];
    $item_setids  = array_unique(array_column($non_summary, 'setid'));
    $intersection = array_intersect($active_setids, $item_setids);
    return !empty($intersection) ? array_values($intersection) : $item_setids;
}

// ─── Derive collection rows — only from the canon-correct reference set ─────────
// active_setids is already the right isreferenceset=1 set for the current canon
// (K→MW22084, T→MW23703, etc.) because load_collections_json keys by sigla|cat_code.
// Empty-vol locations are aggregate summaries → skipped.
function derive_coll_rows(array $ci, string $sigla, string $cat_c, array $active_setids, int $rkts_no = 0): array {
    $non_summary = array_values(array_filter($ci['locations'], fn($l) => $l['vol'] !== ''));
    // Filter to ref-set setids; fallback to all non-summary if no match
    if (!empty($active_setids)) {
        $flip = array_flip($active_setids);
        $locs = array_values(array_filter($non_summary, fn($l) => isset($flip[$l['setid']])));
        if (empty($locs)) $locs = $non_summary;   // safety fallback
    } else {
        $locs = $non_summary;
    }
    $rnb      = $ci['rnb'] ?? '';
    $rkts_val = $rkts_no ? (string)$rkts_no : ref_to_rkts($ci['ref'], $sigla);
    if (!$locs) {
        return [[
            'ref'  => $ci['ref'], 'rnb' => $rnb,
            'rkts' => $rkts_val, 'cat' => $cat_c,
            'vol'  => '', 'sec'  => '',
            'debp' => '', 'debf' => '', 'debl' => '',
            'finp' => '', 'finf' => '', 'finl' => '',
            'mng'  => $ci['mng'] ?? '',
            'tib'  => $ci['tib'] ?? '',
        ]];
    }
    return array_map(fn($loc) => [
        'ref'  => $ci['ref'], 'rnb' => $rnb,
        'rkts' => $rkts_val, 'cat' => $cat_c,
        'vol'  => $loc['vol'],  'sec'  => $loc['sec'],
        'debp' => $loc['debp'], 'debf' => $loc['debf'], 'debl' => $loc['debl'],
        'finp' => $loc['finp'], 'finf' => $loc['finf'], 'finl' => $loc['finl'],
        'mng'  => $ci['mng'] ?? '',
        'tib'  => $ci['tib'] ?? '',
    ], $locs);
}

// ─── Build <translator> DOM node ──────────────────────────────────────────────
function build_translator_node(DOMDocument $dom, array $tr): DOMElement {
    $tel = $dom->createElement('translator');
    foreach (['name','role','rid'] as $tf) {
        $child = $dom->createElement($tf);
        $child->appendChild($dom->createTextNode($tr[$tf] ?? ''));
        $tel->appendChild($child);
    }
    return $tel;
}

// ─── Build <location> DOM node ────────────────────────────────────────────────
function build_location_node(DOMDocument $dom, array $loc): DOMElement {
    $lel = $dom->createElement('location');
    foreach (['setid','vol','sec','debp','debf','debl','finp','finf','finl'] as $lf) {
        $child = $dom->createElement($lf);
        $child->appendChild($dom->createTextNode($loc[$lf] ?? ''));
        $lel->appendChild($child);
    }
    return $lel;
}

// ─── Build <bampo> DOM node ───────────────────────────────────────────────────
function build_bampo_node(DOMDocument $dom, array $bp): DOMElement {
    $el = $dom->createElement('bampo');
    foreach (['rn','name','setid','vol','sec','debp','debf','debl'] as $f) {
        $child = $dom->createElement($f);
        $child->appendChild($dom->createTextNode($bp[$f] ?? ''));
        $el->appendChild($child);
    }
    return $el;
}

// ─── Build <chapter> DOM node ─────────────────────────────────────────────────
function build_chapter_node(DOMDocument $dom, array $ch): DOMElement {
    $el = $dom->createElement('chapter');
    foreach (['rn','name','setid','vol','sec','debp','debf','debl'] as $f) {
        $child = $dom->createElement($f);
        $child->appendChild($dom->createTextNode($ch[$f] ?? ''));
        $el->appendChild($child);
    }
    return $el;
}

// ─── Build full <item> DOM node ───────────────────────────────────────────────
function build_item_node(DOMDocument $dom, array $data): DOMElement {
    $item = $dom->createElement('item');
    foreach (['coll','ref','rnb','mng','tib','skt','coloph','colophTitle','margin','note'] as $f) {
        $el = $dom->createElement($f);
        $el->appendChild($dom->createTextNode($data[$f] ?? ''));
        $item->appendChild($el);
    }
    $locs_el = $dom->createElement('locations');
    foreach ($data['locations'] ?? [] as $loc) {
        $locs_el->appendChild(build_location_node($dom, $loc));
    }
    $item->appendChild($locs_el);
    $trs_el = $dom->createElement('translators');
    foreach ($data['translators'] ?? [] as $tr) {
        $trs_el->appendChild(build_translator_node($dom, $tr));
    }
    $item->appendChild($trs_el);
    $bps_el = $dom->createElement('bampos');
    foreach ($data['bampos'] ?? [] as $bp) {
        $bps_el->appendChild(build_bampo_node($dom, $bp));
    }
    $item->appendChild($bps_el);
    $chs_el = $dom->createElement('chapters');
    foreach ($data['chapters'] ?? [] as $ch) {
        $chs_el->appendChild(build_chapter_node($dom, $ch));
    }
    $item->appendChild($chs_el);
    return $item;
}

// ─── Save catalog XML: update existing items' metadata ───────────────────────
function save_catalog_xml(string $path, array $updates): bool {
    if (!file_exists($path)) return false;
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true; $dom->preserveWhiteSpace = false;
    if (!$dom->load($path)) return false;
    $xpath = new DOMXPath($dom);
    foreach ($updates as $upd) {
        $nodes = $xpath->query('//item[coll="'.xp($upd['coll']).'" and ref="'.xp($upd['ref']).'"]');
        foreach ($nodes as $node) {
            foreach (['rnb','mng','tib','skt','coloph','colophTitle','margin','note'] as $f) {
                $el = $xpath->query($f, $node)->item(0);
                if ($el) {
                    while ($el->firstChild) $el->removeChild($el->firstChild);
                    $el->appendChild($dom->createTextNode($upd[$f] ?? ''));
                } else {
                    $new = $dom->createElement($f);
                    $new->appendChild($dom->createTextNode($upd[$f] ?? ''));
                    $locs_n = $xpath->query('locations', $node)->item(0);
                    $locs_n ? $node->insertBefore($new, $locs_n) : $node->appendChild($new);
                }
            }
            // Rebuild <locations>
            $locs_node = $xpath->query('locations', $node)->item(0);
            if ($locs_node) {
                while ($locs_node->firstChild) $locs_node->removeChild($locs_node->firstChild);
                foreach ($upd['locations'] ?? [] as $loc) {
                    $locs_node->appendChild(build_location_node($dom, $loc));
                }
            }
            // Rebuild <translators>
            $trs_node = $xpath->query('translators', $node)->item(0);
            if ($trs_node) {
                while ($trs_node->firstChild) $trs_node->removeChild($trs_node->firstChild);
                foreach ($upd['translators'] ?? [] as $tr) {
                    $trs_node->appendChild(build_translator_node($dom, $tr));
                }
            }
            // Rebuild <bampos> — insert after <translators> if missing
            $trs_n    = $xpath->query('translators', $node)->item(0);
            $bps_node = $xpath->query('bampos', $node)->item(0);
            if (!$bps_node) {
                $bps_node = $dom->createElement('bampos');
                $trs_n ? $node->insertBefore($bps_node, $trs_n->nextSibling)
                       : $node->appendChild($bps_node);
            }
            while ($bps_node->firstChild) $bps_node->removeChild($bps_node->firstChild);
            foreach ($upd['bampos'] ?? [] as $bp) {
                $bps_node->appendChild(build_bampo_node($dom, $bp));
            }
            // Rebuild <chapters> — insert after <bampos> if missing
            $chs_node = $xpath->query('chapters', $node)->item(0);
            if (!$chs_node) {
                $chs_node = $dom->createElement('chapters');
                $bps_node ? $node->insertBefore($chs_node, $bps_node->nextSibling)
                           : $node->appendChild($chs_node);
            }
            while ($chs_node->firstChild) $chs_node->removeChild($chs_node->firstChild);
            foreach ($upd['chapters'] ?? [] as $ch) {
                $chs_node->appendChild(build_chapter_node($dom, $ch));
            }
        }
    }
    // ── Normalisation pass: ensure every <item> has <bampos> and <chapters>
    //    positioned immediately after <translators>, even if not in the update set.
    foreach ($xpath->query('//item') as $node) {
        $trs_node = $xpath->query('translators', $node)->item(0);
        // <bampos>
        if (!$xpath->query('bampos', $node)->item(0)) {
            $bps_new = $dom->createElement('bampos');
            $trs_node ? $node->insertBefore($bps_new, $trs_node->nextSibling)
                      : $node->appendChild($bps_new);
        }
        // Re-query because insertBefore may have shifted siblings
        $bps_existing = $xpath->query('bampos', $node)->item(0);
        // <chapters>
        if (!$xpath->query('chapters', $node)->item(0)) {
            $chs_new = $dom->createElement('chapters');
            $bps_existing ? $node->insertBefore($chs_new, $bps_existing->nextSibling)
                          : $node->appendChild($chs_new);
        }
    }
    return $dom->save($path) !== false;
}

// ─── Append a new item to catalog XML ────────────────────────────────────────
function append_catalog_item(string $path, array $data): bool {
    if (!file_exists($path)) return false;
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true; $dom->preserveWhiteSpace = false;
    if (!$dom->load($path)) return false;
    $root = $dom->documentElement;
    $root->appendChild($dom->createTextNode("\n  "));
    $root->appendChild(build_item_node($dom, $data));
    $root->appendChild($dom->createTextNode("\n"));
    return $dom->save($path) !== false;
}

// ─── Save collection XML ──────────────────────────────────────────────────────
function save_collection_xml(string $path, string $cat_c, array $refs, array $rows): bool {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true; $dom->preserveWhiteSpace = false;

    // Collect ALL existing items from disk (including other refs/cats)
    $existing_rows = [];
    if (file_exists($path)) {
        if (!$dom->load($path)) return false;
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        if ($xml) {
            $refs_flip = array_flip($refs);
            foreach ($xml->item as $item) {
                $r = (string)($item->ref ?? '');
                $c = (string)($item->cat ?? '');
                // Keep rows NOT being replaced
                if (isset($refs_flip[$r]) && $c === $cat_c) continue;
                $existing_rows[] = [
                    'ref'  => $r,
                    'rnb'  => (string)($item->rnb  ?? ''),
                    'rkts' => (string)($item->rkts ?? ''),
                    'cat'  => $c,
                    'vol'  => (string)($item->vol  ?? ''),
                    'sec'  => (string)($item->sec  ?? ''),
                    'debp' => (string)($item->debp ?? ''),
                    'debf' => (string)($item->debf ?? ''),
                    'debl' => (string)($item->debl ?? ''),
                    'finp' => (string)($item->finp ?? ''),
                    'finf' => (string)($item->finf ?? ''),
                    'finl' => (string)($item->finl ?? ''),
                    'mng'  => (string)($item->mng  ?? ''),
                    'tib'  => (string)($item->tib  ?? ''),
                ];
            }
        }
    } else {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;
    }

    // Merge existing (kept) rows with the new rows
    $all_rows = array_merge($existing_rows, $rows);

    // Sort by rnb (numeric), then by vol within same rnb
    usort($all_rows, function(array $a, array $b): int {
        $ra = (float)($a['rnb'] !== '' ? $a['rnb'] : PHP_INT_MAX);
        $rb = (float)($b['rnb'] !== '' ? $b['rnb'] : PHP_INT_MAX);
        if ($ra !== $rb) return $ra <=> $rb;
        return strnatcmp((string)($a['vol'] ?? ''), (string)($b['vol'] ?? ''));
    });

    // Rebuild DOM from scratch
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $root = $dom->createElement('list');
    $dom->appendChild($root);

    foreach ($all_rows as $row) {
        $item = $dom->createElement('item');
        foreach (['ref','rnb','rkts','cat','vol','sec','debp','debf','debl','finp','finf','finl','mng','tib'] as $f) {
            $el = $dom->createElement($f);
            $el->appendChild($dom->createTextNode((string)($row[$f] ?? '')));
            $item->appendChild($el);
        }
        $root->appendChild($item);
    }
    return $dom->save($path) !== false;
}

// ─── Parse translator POST array ─────────────────────────────────────────────
function parse_translators_post(array $ci_post): array {
    $tr_names = $ci_post['tr_name'] ?? [];
    $tr_roles = $ci_post['tr_role'] ?? [];
    $tr_rids  = $ci_post['tr_rid']  ?? [];
    $trs = [];
    foreach ($tr_names as $i => $name) {
        $name = trim($name);
        if ($name === '' && trim($tr_rids[$i] ?? '') === '') continue;
        $trs[] = [
            'name' => $name,
            'role' => trim($tr_roles[$i] ?? ''),
            'rid'  => trim($tr_rids[$i]  ?? ''),
        ];
    }
    return $trs;
}

// ─── Parse location POST array ────────────────────────────────────────────────
function parse_locations_post(array $locs_post): array {
    $setids = $locs_post['setid'] ?? [];
    $out = [];
    foreach ($setids as $i => $setid) {
        if (trim($setid) === '' && trim($locs_post['vol'][$i] ?? '') === '') continue;
        $out[] = [
            'setid' => trim($setid),
            'vol'   => trim($locs_post['vol'][$i]  ?? ''),
            'sec'   => trim($locs_post['sec'][$i]  ?? ''),
            'debp'  => trim($locs_post['debp'][$i] ?? ''),
            'debf'  => trim($locs_post['debf'][$i] ?? ''),
            'debl'  => trim($locs_post['debl'][$i] ?? ''),
            'finp'  => trim($locs_post['finp'][$i] ?? ''),
            'finf'  => trim($locs_post['finf'][$i] ?? ''),
            'finl'  => trim($locs_post['finl'][$i] ?? ''),
        ];
    }
    return $out;
}

// ─── Parse bampo POST array ───────────────────────────────────────────────────
// Skip only if BOTH rn and name are empty (genuinely blank row).
function parse_bampos_post(array $bps_post): array {
    $first = array_key_first($bps_post);
    if ($first === null) return [];
    $out = [];
    foreach (array_keys($bps_post[$first]) as $i) {
        $rn   = trim($bps_post['rn'][$i]   ?? '');
        $name = trim($bps_post['name'][$i]  ?? '');
        if ($rn === '' && $name === '') continue;   // blank row — skip
        $out[] = [
            'rn'    => $rn,
            'name'  => $name,
            'setid' => trim($bps_post['setid'][$i] ?? ''),
            'vol'   => trim($bps_post['vol'][$i]   ?? ''),
            'sec'   => trim($bps_post['sec'][$i]   ?? ''),
            'debp'  => trim($bps_post['debp'][$i]  ?? ''),
            'debf'  => trim($bps_post['debf'][$i]  ?? ''),
            'debl'  => trim($bps_post['debl'][$i]  ?? ''),
        ];
    }
    return $out;
}

// ─── Parse chapter POST array ─────────────────────────────────────────────────
// Skip only if BOTH rn and name are empty (genuinely blank row).
function parse_chapters_post(array $chs_post): array {
    $first = array_key_first($chs_post);
    if ($first === null) return [];
    $out = [];
    foreach (array_keys($chs_post[$first]) as $i) {
        $rn   = trim($chs_post['rn'][$i]   ?? '');
        $name = trim($chs_post['name'][$i]  ?? '');
        if ($rn === '' && $name === '') continue;   // blank row — skip
        $out[] = [
            'rn'    => $rn,
            'name'  => $name,
            'setid' => trim($chs_post['setid'][$i] ?? ''),
            'vol'   => trim($chs_post['vol'][$i]   ?? ''),
            'sec'   => trim($chs_post['sec'][$i]   ?? ''),
            'debp'  => trim($chs_post['debp'][$i]  ?? ''),
            'debf'  => trim($chs_post['debf'][$i]  ?? ''),
            'debl'  => trim($chs_post['debl'][$i]  ?? ''),
        ];
    }
    return $out;
}

// ══════════════════════════════════════════════════════════════════════════════
// POST handler
// ══════════════════════════════════════════════════════════════════════════════
$flash = [];
$action = $_POST['action'] ?? '';

// ── A: Save existing entry ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loggedInUser !== null && $action === 'save_entry' && !empty($_POST['save_sigla'])) {
    $save_sigla  = $_POST['save_sigla'];
    $rkts_post   = (int)($_POST['rkts']  ?? 0);
    $canon_post  = trim($_POST['canon'] ?? '');
    $refs_posted = $_POST['refs_posted'] ?? [];

    $cat_c_save   = $MNG_CANONS[$canon_post] ?? '';
    $rkts_id_post = $cat_c_save . $rkts_post;
    $catalog_path = mongol_xml_path($MONGOL_DIR, $rkts_id_post);
    $coll_path    = $COLLECTIONS_DIR . "/{$save_sigla}/{$save_sigla}.xml";

    $cols_json   = load_collections_json($COLLECTIONS_JSON);
    $active_sids = coll_info($cols_json, $save_sigla, $cat_c_save)['active_setids'] ?? [];
    $cat_updates   = [];
    $all_coll_rows = [];
    $cat_items_post = $_POST['cat_items'][$save_sigla] ?? [];

    foreach ($refs_posted as $ref) {
        $ci_post  = $cat_items_post[$ref] ?? [];
        $upd_locs = parse_locations_post($ci_post['locations'] ?? []);
        $upd_mng  = trim($ci_post['mng'] ?? '');
        $upd_tib  = trim($ci_post['tib'] ?? '');

        $cat_updates[] = [
            'coll'        => $save_sigla, 'ref' => $ref,
            'rnb'         => trim($ci_post['rnb']         ?? ''),
            'mng'         => $upd_mng,
            'tib'         => $upd_tib,
            'skt'         => trim($ci_post['skt']         ?? ''),
            'coloph'      => trim($ci_post['coloph']      ?? ''),
            'colophTitle' => trim($ci_post['colophTitle'] ?? ''),
            'margin'      => trim($ci_post['margin']      ?? ''),
            'note'        => trim($ci_post['note']        ?? ''),
            'locations'   => $upd_locs,
            'translators' => parse_translators_post($ci_post),
            'bampos'      => parse_bampos_post($ci_post['bampos']   ?? []),
            'chapters'    => parse_chapters_post($ci_post['chapters'] ?? []),
        ];

        $submitted_ci = [
            'ref'       => $ref,
            'rnb'       => trim($ci_post['rnb'] ?? ''),
            'mng'       => $upd_mng,
            'tib'       => $upd_tib,
            'locations' => $upd_locs,
        ];
        $all_coll_rows = array_merge(
            $all_coll_rows,
            derive_coll_rows($submitted_ci, $save_sigla, $cat_c_save, $active_sids, $rkts_post)
        );
    }

    $ok_cat  = save_catalog_xml($catalog_path, $cat_updates);
    $ok_coll = save_collection_xml($coll_path, $cat_c_save, $refs_posted, $all_coll_rows);

    $id_display = htmlspecialchars($rkts_id_post);
    $pfx        = htmlspecialchars($cat_c_save);
    if ($ok_cat && $ok_coll) {
        $flash[] = ['type' => 'success', 'html' => sprintf(
            '<i class="bi bi-check-circle-fill me-1"></i>'
            . 'Saved <strong>mongol/%s/%s.xml</strong> and '
            . '<strong>collections/%s/%s.xml</strong> — '
            . '%d collection row(s) auto-written.',
            $pfx, $id_display,
            htmlspecialchars($save_sigla), htmlspecialchars($save_sigla),
            count($all_coll_rows)
        )];
    } else {
        $parts = [];
        if (!$ok_cat)  $parts[] = "mongol/{$pfx}/{$id_display}.xml";
        if (!$ok_coll) $parts[] = 'collections/' . htmlspecialchars($save_sigla . '/' . $save_sigla) . '.xml';
        $flash[] = ['type' => 'danger', 'html' =>
            '<i class="bi bi-x-circle-fill me-1"></i>Could not write: <strong>'
            . implode('</strong>, <strong>', $parts) . '</strong>.'];
    }
}

// ── B: Add new catalog entry ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loggedInUser !== null && $action === 'new_entry') {
    $rkts_post    = (int)($_POST['rkts']  ?? 0);
    $canon_post   = trim($_POST['canon'] ?? '');
    $cat_c_new    = $MNG_CANONS[$canon_post] ?? '';
    $rkts_id_post = $cat_c_new . $rkts_post;
    $catalog_path = mongol_xml_path($MONGOL_DIR, $rkts_id_post);

    $new = $_POST['new_item'] ?? [];
    $coll_new = trim($new['coll'] ?? '');
    $ref_new  = trim($new['ref']  ?? '');

    if (!$coll_new || !$ref_new) {
        $flash[] = ['type' => 'danger', 'html' =>
            '<i class="bi bi-x-circle-fill me-1"></i>New entry requires at least <strong>coll</strong> and <strong>ref</strong>.'];
    } elseif (!file_exists($catalog_path)) {
        $flash[] = ['type' => 'danger', 'html' =>
            '<i class="bi bi-x-circle-fill me-1"></i>Catalog file <strong>'
            . htmlspecialchars($rkts_id_post) . '.xml</strong> not found at expected path.'];
    } else {
        $data = [
            'coll'        => $coll_new,
            'ref'         => $ref_new,
            'rnb'         => trim($new['rnb']         ?? ''),
            'mng'         => trim($new['mng']         ?? ''),
            'tib'         => trim($new['tib']         ?? ''),
            'skt'         => trim($new['skt']         ?? ''),
            'coloph'      => trim($new['coloph']      ?? ''),
            'colophTitle' => trim($new['colophTitle'] ?? ''),
            'margin'      => trim($new['margin']      ?? ''),
            'note'        => trim($new['note']        ?? ''),
            'locations'   => parse_locations_post($new['locations'] ?? []),
            'translators' => parse_translators_post($new),
            'bampos'      => parse_bampos_post($new['bampos']   ?? []),
            'chapters'    => parse_chapters_post($new['chapters'] ?? []),
        ];
        $ok_cat = append_catalog_item($catalog_path, $data);

        $cols_json   = load_collections_json($COLLECTIONS_JSON);
        $active_sids = coll_info($cols_json, $coll_new, $cat_c_new)['active_setids'] ?? [];
        $coll_path   = $COLLECTIONS_DIR . "/{$coll_new}/{$coll_new}.xml";
        $coll_rows   = derive_coll_rows($data, $coll_new, $cat_c_new, $active_sids, $rkts_post);
        $ok_coll     = save_collection_xml($coll_path, $cat_c_new, [$ref_new], $coll_rows);

        $id_display = htmlspecialchars($rkts_id_post);
        $pfx        = htmlspecialchars($cat_c_new);
        if ($ok_cat && $ok_coll) {
            $flash[] = ['type' => 'success', 'html' => sprintf(
                '<i class="bi bi-check-circle-fill me-1"></i>'
                . 'New entry <strong>%s</strong> appended to '
                . '<strong>mongol/%s/%s.xml</strong> and '
                . '<strong>collections/%s/%s.xml</strong> — '
                . '%d collection row(s) written.',
                htmlspecialchars($ref_new), $pfx, $id_display,
                htmlspecialchars($coll_new), htmlspecialchars($coll_new),
                count($coll_rows)
            )];
        } elseif ($ok_cat) {
            $flash[] = ['type' => 'warning', 'html' => sprintf(
                '<i class="bi bi-exclamation-triangle-fill me-1"></i>'
                . 'Entry <strong>%s</strong> appended to <strong>mongol/%s/%s.xml</strong> '
                . 'but could not write <strong>collections/%s/%s.xml</strong>.',
                htmlspecialchars($ref_new), $pfx, $id_display,
                htmlspecialchars($coll_new), htmlspecialchars($coll_new)
            )];
        } else {
            $flash[] = ['type' => 'danger', 'html' =>
                '<i class="bi bi-x-circle-fill me-1"></i>Could not write catalog file.'];
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// GET — load display data
// ═════════════════════════════════════════════════════════════════════════════
$rkts   = (int)trim($_REQUEST['rkts']  ?? '');  // numeric only, e.g. 360
$canon  = trim($_REQUEST['canon'] ?? '');        // "Kanjur" or "Tanjur"
$cat_c  = $MNG_CANONS[$canon] ?? '';
$rkts_id = ($cat_c && $rkts) ? $cat_c . $rkts : '';  // full ID, e.g. "K360"

$catalog_items_flat = [];
$catalog_by_sigla   = [];
$collections        = [];
$catalog_error      = '';

if ($rkts_id !== '') {
    $catalog_path       = mongol_xml_path($MONGOL_DIR, $rkts_id);
    $collections        = load_collections_json($COLLECTIONS_JSON);
    $catalog_items_flat = load_catalog_xml($catalog_path);

    if (empty($catalog_items_flat)) {
        $catalog_error = "No data found in <code>mongol/{$cat_c}/{$rkts_id}.xml</code>.";
    } else {
        foreach ($catalog_items_flat as $ci) $catalog_by_sigla[$ci['coll']][] = $ci;
        foreach ($catalog_by_sigla as $sigla => &$items) {
            $coll_info   = coll_info($collections, $sigla, $cat_c);
            $active_sids = $coll_info['active_setids'] ?? [];
            $ref_sids    = $coll_info['ref_setids']    ?? [];
            foreach ($items as &$ci) {
                $ci['active_setids']    = $active_sids;
                $ci['ref_has_marker']   = !empty($ref_sids);
                $ci['coll_file_exists'] = file_exists($COLLECTIONS_DIR . "/{$sigla}/{$sigla}.xml");
                $ci['coll_rows']        = derive_coll_rows($ci, $sigla, $cat_c, $active_sids, $rkts);
                $ci['effective_setids'] = effective_ref_setids($ci['locations'], $active_sids);
                $ci['ref_fallback']     = !empty($active_sids) && empty(array_intersect($active_sids,
                                              array_unique(array_column(
                                                array_filter($ci['locations'], fn($l) => $l['vol'] !== ''),
                                                'setid'))));
            }
            unset($ci);
        }
        unset($items);
        ksort($catalog_by_sigla);
    }
}

$page_title = 'Edit Mongolian Entry' . ($canon && $rkts ? " — {$canon} {$rkts_id}" : '') . ' | rKTs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($page_title) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="../../vendor/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root {
      --r-dk    : #1a3c6e;
      --r-md    : #2a6099;
      --r-lt    : #4a8fc4;
      --r-pale  : #f0f5fb;
      --r-panel : #e4eef8;
      --r-border: #b5cfe8;
    }
    body { background: var(--r-pale); font-size: .91rem; }
    .tib { font-family: "Noto Sans Tibetan", serif; font-size: 1.04em; }

    .page-header { background: linear-gradient(135deg, var(--r-dk) 0%, var(--r-md) 100%);
      color: #fff; padding: 1.3rem 1.5rem .95rem; border-bottom: 3px solid var(--r-lt); }
    .page-header h1 { font-size: 1.45rem; font-weight: 700; margin: 0; }
    .page-header p  { margin: .18rem 0 0; opacity: .8; font-size: .83rem; }

    /* Cards */
    .search-card { border: 1px solid var(--r-border); border-radius: .55rem; background: #fff;
                   box-shadow: 0 1px 4px rgba(26,60,110,.07); }
    .search-card .card-header { background: var(--r-dk); color: #fff;
      border-radius: .5rem .5rem 0 0; font-weight: 600; font-size: .87rem; padding: .5rem 1rem; }

    .coll-card { border: 1px solid var(--r-border); border-radius: .55rem; background: #fff;
                 box-shadow: 0 1px 5px rgba(26,60,110,.07); margin-bottom: 1rem; }
    .coll-card-header { background: linear-gradient(90deg, var(--r-dk) 0%, var(--r-md) 100%);
      color: #fff; border-radius: .5rem .5rem 0 0; padding: .58rem 1rem;
      display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }

    /* New-entry card gets a distinct teal-blue header */
    .new-entry-card { border: 2px solid var(--r-lt); border-radius: .55rem; background: #fff;
                      box-shadow: 0 2px 8px rgba(26,60,110,.12); margin-bottom: 1.2rem; }
    .new-entry-header { background: linear-gradient(90deg, #155e8e 0%, var(--r-lt) 100%);
      color: #fff; border-radius: .5rem .5rem 0 0; padding: .6rem 1rem;
      display: flex; align-items: center; gap: .6rem; }
    .new-entry-header .btn-collapse { background: rgba(255,255,255,.2); border: none; color: #fff;
      font-size: .82rem; padding: .22rem .6rem; border-radius: .3rem; cursor: pointer; }
    .new-entry-header .btn-collapse:hover { background: rgba(255,255,255,.35); }

    .coll-badge { background: var(--r-lt); color: #fff; font-weight: 700; font-size: .93rem;
                  padding: .14em .5em; border-radius: .28em; white-space: nowrap; }
    .coll-title  { font-weight: 600; font-size: .9rem; }
    .coll-ref-chip { font-size: .71rem; background: rgba(255,255,255,.22);
                     padding: .07em .35em; border-radius: .2em; white-space: nowrap; }

    .item-sub-header { background: var(--r-panel); border-bottom: 1px solid var(--r-border);
      padding: .42rem 1rem; display: flex; align-items: center; gap: .55rem;
      font-size: .81rem; font-weight: 700; color: var(--r-dk); }
    .multi-item-divider { border-top: 2px dashed var(--r-border); margin: .8rem 0 0; }

    .section-label { font-size: .69rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .08em; color: var(--r-md); border-bottom: 1px solid var(--r-border);
      padding-bottom: .2rem; margin-bottom: .55rem; }

    /* Edit panels */
    .catalog-edit-panel { background: var(--r-panel); border: 1px solid var(--r-border);
      border-radius: .4rem; padding: .75rem 1rem; margin-bottom: .9rem; }
    .catalog-edit-panel label { font-size: .69rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .06em; color: var(--r-dk); margin-bottom: .13rem; display: block; }
    .catalog-edit-panel .form-control { font-size: .83rem; padding: .2rem .4rem;
      border-color: var(--r-border); background: #fff; }
    .catalog-edit-panel .form-control1 { font-size: 1.2rem; padding: .2rem .4rem;
      border-color: var(--r-border); background: #fff; }
    .catalog-edit-panel .form-control:focus { border-color: var(--r-lt);
      box-shadow: 0 0 0 .18rem rgba(74,143,196,.25); }
    .new-entry-panel { background: #edf4fc; border: 1px solid var(--r-border);
      border-radius: .4rem; padding: .75rem 1rem; }
    .new-entry-panel label { font-size: .69rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .06em; color: var(--r-dk); margin-bottom: .13rem; display: block; }
    .new-entry-panel .form-control { font-size: .83rem; padding: .2rem .4rem;
      border-color: var(--r-border); background: #fff; }
    .new-entry-panel .form-control:focus { border-color: var(--r-lt);
      box-shadow: 0 0 0 .18rem rgba(74,143,196,.25); }

    /* Translator / location tables */
    .mini-table { width: 100%; font-size: .8rem; border-collapse: separate; border-spacing: 0; }
    .mini-table th { font-size: .65rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .06em; background: #d4e8f7; color: var(--r-dk); padding: .25rem .38rem;
      border: 1px solid var(--r-border); white-space: nowrap; }
    .mini-table td { padding: .13rem .2rem; border: 1px solid var(--r-border); vertical-align: middle; }
    .mini-table tbody tr:hover td { background: #dceef9; }
    .mini-table input.form-control { font-size: .78rem; padding: .12rem .22rem;
      border: none; background: transparent; box-shadow: none; }
    .mini-table input.form-control:focus { background: #fff; border: 1px solid var(--r-lt);
      box-shadow: 0 0 0 .13rem rgba(74,143,196,.22); border-radius: .2rem; }
    .col-tr-name { min-width: 13rem; } .col-tr-role { width: 7rem; }
    .col-tr-rid  { width: 8rem; }     .col-del     { width: 2rem; }
    .col-loc-setid{ width: 8rem; }    .col-loc-vol { width: 6rem; }
    .col-loc-sec  { width: 6rem; }    .col-loc-pfl { width: 3.2rem; }
    .col-loc-ff   { width: 2.5rem; }
    .col-bp-setid { width: 8rem; }    .col-bp-rn   { width: 3rem; }
    .col-bp-name  { min-width: 10rem; }
    .col-ch-setid { width: 8rem; }    .col-ch-rn   { width: 3rem; }
    .col-ch-name  { min-width: 10rem; }
    /* Bampo/chapter section colours */
    .bampo-label  { color: #7c3aed; }
    .chapter-label{ color: #0e7490; }
    .note-label   { color: #065f46; }

    /* Cat (K/T/G/B) badge on card header */
    .cat-badge-K { background: #0d6efd; color: #fff; }
    .cat-badge-T { background: #198754; color: #fff; }
    .cat-badge-G { background: #6f42c1; color: #fff; }
    .cat-badge-B { background: #d63384; color: #fff; }
    .cat-badge   { font-size: .8rem; font-weight: 800; padding: .15em .5em;
                   border-radius: .28em; letter-spacing: .05em; white-space: nowrap; }
    .fallback-setid-note { font-size: .67rem; color: #b45309; font-style: italic; }

    /* Catalog locations ref table */
    .loc-ref-table th { font-size: .65rem; background: var(--r-panel); color: var(--r-dk);
      font-weight: 700; white-space: nowrap; padding: .26rem .36rem; }
    .loc-ref-table td { font-size: .72rem; padding: .18rem .34rem; vertical-align: middle;
      font-family: monospace; }
    .loc-ref-table tr.is-ref td { background: #d4e8f7; }
    .loc-ref-table tr.is-ref td:first-child::before { content: '★ '; color: var(--r-lt); font-size: .6rem; }

    /* Collection display table */
    .coll-display-table th { font-size: .65rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .06em; background: #e8f3fb; color: var(--r-dk); white-space: nowrap;
      padding: .26rem .32rem; }
    .coll-display-table td { font-size: .78rem; padding: .2rem .3rem; font-family: monospace;
      vertical-align: middle; }
    .coll-display-table .tib-cell { font-family: "Noto Sans Tibetan", serif; font-size: .88em; }
    .coll-display-table tr.coll-ref-row td { background: #deeef9; }
    .mini-table tr.loc-ref-row td { background: #deeef9; }
    .mini-table tr.loc-ref-row td:first-child::before { content: "★ "; color: var(--r-lt); font-size: .6rem; }
    .readonly-note { font-size: .67rem; color: var(--r-md); font-style: italic; }

    /* Buttons */
    .btn-rkts       { background: var(--r-dk); border-color: var(--r-dk); color: #fff;
                      font-size: .82rem; font-weight: 600; }
    .btn-rkts:hover { background: var(--r-md); border-color: var(--r-md); color: #fff; }
    .btn-rkts-new   { background: #155e8e; border-color: #155e8e; color: #fff;
                      font-size: .82rem; font-weight: 600; }
    .btn-rkts-new:hover { background: var(--r-lt); border-color: var(--r-lt); color: #fff; }
    .btn-add-row    { font-size: .73rem; color: var(--r-md); border-color: var(--r-border);
                      background: #f0f5fb; padding: .18rem .55rem; }
    .btn-add-row:hover { background: var(--r-panel); border-color: var(--r-md); }

    /* Badges */
    .file-status  { font-size: .68rem; padding: .11em .4em; border-radius: .22em; }
    .file-exists  { background: #cce5ff; color: #003f7f; }
    .file-absent  { background: #f8d7da; color: #842029; }
    .ref-set-badge   { font-size: .63rem; background: #cce5ff; color: #003f7f;
                       padding: .08em .36em; border-radius: .2em; font-weight: 700; }
    .fallback-badge  { font-size: .63rem; background: #fff3cd; color: #664d03;
                       padding: .08em .36em; border-radius: .2em; font-weight: 700; }
    .setid-chip  { display: inline-block; font-size: .67rem; font-family: monospace;
                   padding: .07em .35em; border-radius: .2em; margin: .07em; }
    .setid-ref   { background: #cce5ff; color: #003f7f; font-weight: 700; }
    .setid-other { background: #e0e0e0; color: #555; }

    .info-bar { background: #ddeeff; border: 1px solid var(--r-border); border-radius: .35rem;
                padding: .4rem .75rem; font-size: .78rem; color: var(--r-dk); }
    .accordion-button { font-size: .85rem; font-weight: 600; padding: .48rem .85rem; }
    .accordion-button:not(.collapsed) { background: var(--r-panel); color: var(--r-dk); box-shadow: none; }
    .required-star { color: #dc3545; }

    /* ── Login modal ── */
    .modal-backdrop {
      position: fixed; inset: 0; background: rgba(0,0,0,.45);
      display: flex; align-items: center; justify-content: center;
      z-index: 9999;
    }
    .login-modal {
      background: #fff; border-radius: .7rem; padding: 2rem 2.2rem;
      width: 100%; max-width: 340px; box-shadow: 0 16px 48px rgba(0,0,0,.18);
    }
    .login-modal h2 { font-size: 1.2rem; font-weight: 700; margin-bottom: .25rem; color: var(--r-dk); }
    .login-modal .sub { font-size: .78rem; color: #6c757d; margin-bottom: 1.2rem; }
    .login-error { background: #f8d7da; border: 1px solid #f5c2c7; color: #842029;
      border-radius: .35rem; font-size: .8rem; padding: .45rem .7rem; margin-bottom: .8rem; }
    .login-modal .form-label { font-size: .72rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .04em; color: var(--r-dk); margin-bottom: .2rem; }

    /* ── Auth bar ── */
    .auth-bar { display: flex; align-items: center; gap: .75rem; }
    .user-badge { display: inline-flex; align-items: center; gap: .35rem;
      background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.3);
      border-radius: 20px; padding: .22rem .7rem; font-size: .78rem; font-weight: 600; color: #fff; }
    .btn-login-hdr { font-size: .78rem; font-weight: 600; padding: .28rem .8rem;
      border-radius: .35rem; background: rgba(255,255,255,.15);
      border: 1px solid rgba(255,255,255,.4); color: #fff; cursor: pointer; }
    .btn-login-hdr:hover { background: rgba(255,255,255,.28); }
    .btn-logout-hdr { font-size: .78rem; font-weight: 500; padding: .25rem .7rem;
      border-radius: .35rem; background: transparent;
      border: 1px solid rgba(255,255,255,.3); color: rgba(255,255,255,.8); cursor: pointer; }
    .btn-logout-hdr:hover { background: rgba(255,255,255,.1); color: #fff; }

    /* ── Readonly notice ── */
    .readonly-notice {
      background: #fff3cd; border-bottom: 1px solid #fde68a;
      padding: .5rem 1.5rem; font-size: .8rem; color: #664d03;
      display: flex; align-items: center; gap: .5rem;
    }
    .persons-hint.visible { display: block; }
  
    .tr-persons-hint {
      font-size: .67rem; color: #0d47a1; background: #ddeeff;
      border-radius: .2rem; padding: .08rem .35rem; margin-top: .18rem;
      display: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .tr-persons-hint.show { display: block; }

  </style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/includes/navbar.php')) include __DIR__ . '/includes/navbar.php'; ?>

<?php /* ── Persons datalist for translator name autocompletion ── */ ?>
<datalist id="rkts-persons">
<?php foreach ($persons_data as [$pname, $prid, $pstd]): ?>
  <option value="<?= htmlspecialchars($pname) ?>"></option>
<?php endforeach; ?>
</datalist>

<?php if (!$loggedInUser && isset($_GET['login'])): ?>
<div class="modal-backdrop" onclick="if(event.target===this)location.href='<?= htmlspecialchars(strtok($_SERVER['REQUEST_URI'],'?')) . '?' . http_build_query(array_filter(['canon'=>$canon,'rkts'=>$rkts])) ?>'">
  <div class="login-modal">
    <h2>Sign in</h2>
    <p class="sub">Enter your credentials to edit Mongolian catalog entries.</p>
    <?php if ($loginError): ?><div class="login-error">&#9888; <?= htmlspecialchars($loginError) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="canon"  value="<?= htmlspecialchars($canon) ?>">
      <input type="hidden" name="rkts"   value="<?= htmlspecialchars($rkts) ?>">
      <div class="mb-2">
        <label class="form-label">Username</label>
        <input class="form-control" type="text" name="username"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               autofocus autocomplete="username">
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input class="form-control" type="password" name="password" autocomplete="current-password">
      </div>
      <button class="btn btn-rkts w-100" type="submit">Sign in</button>
    </form>
    <a class="d-block text-center mt-2 small text-muted"
       href="<?= htmlspecialchars(strtok($_SERVER['REQUEST_URI'],'?')) . '?' . http_build_query(array_filter(['canon'=>$canon,'rkts'=>$rkts])) ?>">Cancel</a>
  </div>
</div>
<?php endif; ?>

<div class="page-header">
  <div class="container-xxl d-flex align-items-start justify-content-between flex-wrap gap-2">
    <div>
      <h1><i class="bi bi-pencil-square me-2"></i>Edit Mongolian Entry</h1>
      <p>Edit Mongolian catalog metadata. Collection rows are auto-derived from reference-set locations and written on save.</p>
    </div>
    <div class="auth-bar mt-1">
      <?php if ($loggedInUser): ?>
        <span class="user-badge">
          <i class="bi bi-person-fill"></i><?= htmlspecialchars($loggedInUser) ?>
        </span>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="logout">
          <input type="hidden" name="canon" value="<?= htmlspecialchars($canon) ?>">
          <input type="hidden" name="rkts" value="<?= htmlspecialchars($rkts) ?>">
          <button class="btn-logout-hdr" type="submit">Sign out</button>
        </form>
      <?php else: ?>
        <a href="?<?= http_build_query(array_filter(['canon'=>$canon,'rkts'=>$rkts])) ?>&login=1">
          <button class="btn-login-hdr" type="button">Sign in</button>
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if (!$loggedInUser): ?>
<div class="readonly-notice">
  <i class="bi bi-lock-fill"></i>
  Read-only view — <a href="?<?= http_build_query(array_filter(['canon'=>$canon,'rkts'=>$rkts])) ?>&login=1" class="fw-semibold ms-1">sign in</a>&nbsp;to save changes.
</div>
<?php endif; ?>

<div class="container-xxl py-4">

  <!-- Flash -->
  <?php foreach ($flash as $f): ?>
  <div class="alert alert-<?= htmlspecialchars($f['type']) ?> alert-dismissible fade show mb-3" role="alert">
    <?= $f['html'] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endforeach; ?>

  <!-- ── Search form ─────────────────────────────────────────────────────────── -->
  <div class="search-card mb-4">
    <div class="card-header"><i class="bi bi-search me-1"></i> Select Mongolian Catalog Entry</div>
    <div class="card-body py-3">
      <form method="get" action="" class="row g-3 align-items-end">
        <div class="col-auto">
          <label class="form-label fw-semibold mb-1" for="canon">Canon</label>
          <select class="form-select" id="canon" name="canon" required style="min-width:8rem">
            <option value="">— choose —</option>
            <?php foreach ($MNG_CANONS as $label => $code): ?>
            <option value="<?= htmlspecialchars($label) ?>"
              <?= ($canon === $label) ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?> (<?= $code ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <label class="form-label fw-semibold mb-1" for="rkts">No.</label>
          <input type="number" class="form-control" id="rkts" name="rkts"
                 min="1" placeholder="e.g. 360"
                 value="<?= $rkts ?: '' ?>"
                 style="width:8rem" required>
        </div>
        <div class="col-auto d-flex gap-1">
          <?php if ($canon && $rkts > 1): ?>
          <a href="?canon=<?= urlencode($canon) ?>&amp;rkts=<?= $rkts - 1 ?>"
             class="btn btn-outline-secondary" title="Previous (<?= htmlspecialchars($cat_c . ($rkts - 1)) ?>)">
            <i class="bi bi-chevron-left"></i>
          </a>
          <?php elseif ($canon): ?>
          <button type="button" class="btn btn-outline-secondary" disabled>
            <i class="bi bi-chevron-left"></i>
          </button>
          <?php endif; ?>
          <button type="submit" class="btn btn-rkts">
            <i class="bi bi-arrow-right-circle me-1"></i>Load
          </button>
          <?php if ($canon && $rkts): ?>
          <a href="?canon=<?= urlencode($canon) ?>&amp;rkts=<?= $rkts + 1 ?>"
             class="btn btn-outline-secondary" title="Next (<?= htmlspecialchars($cat_c . ($rkts + 1)) ?>)">
            <i class="bi bi-chevron-right"></i>
          </a>
          <?php endif; ?>
        </div>
        <?php if ($rkts_id): ?>
        <div class="col-auto ms-auto text-muted small">
          <code>mongol/<?= htmlspecialchars($cat_c) ?>/<?= htmlspecialchars($rkts_id) ?>.xml</code>
        </div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <?php if ($catalog_error): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
    <div><?= $catalog_error ?></div>
  </div>
  <?php endif; ?>

  <?php if ($rkts_id !== ''): ?>

  <!-- ══ Add New Catalog Entry ════════════════════════════════════════════════ -->
  <div class="new-entry-card">
    <div class="new-entry-header">
      <i class="bi bi-plus-circle-fill fs-5"></i>
      <span class="fw-bold">Add New Catalog Entry</span>
      <span class="text-white-50 small ms-1">→ appended to
        <code>mongol/<?= htmlspecialchars($cat_c) ?>/<?= htmlspecialchars($rkts_id) ?>.xml</code>
      </span>
      <button type="button" class="btn-collapse ms-auto"
              data-bs-toggle="collapse" data-bs-target="#new-entry-body">
        <i class="bi bi-chevron-down" id="new-entry-chevron"></i>
      </button>
    </div>

    <div id="new-entry-body" class="collapse">
      <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>"
            id="form-new-entry" class="p-3">
        <input type="hidden" name="action" value="new_entry">
        <input type="hidden" name="canon"  value="<?= htmlspecialchars($canon) ?>">
        <input type="hidden" name="rkts"   value="<?= htmlspecialchars($rkts) ?>">

        <div class="new-entry-panel">
          <!-- Row 1: coll + ref + rnb + mng + tib -->
          <div class="row g-2 mb-2">
            <div class="col-auto" style="width:7rem">
              <label for="ne-coll">coll <span class="required-star">*</span></label>
              <input type="text" class="form-control" id="ne-coll" name="new_item[coll]"
                     placeholder="e.g. Mmk" required>
            </div>
            <div class="col-auto" style="width:9rem">
              <label for="ne-ref">ref <span class="required-star">*</span></label>
              <input type="text" class="form-control" id="ne-ref" name="new_item[ref]"
                     placeholder="e.g. Mmk59" required>
            </div>
            <div class="col-auto" style="width:6rem">
              <label for="ne-rnb">rnb</label>
              <input type="text" class="form-control" id="ne-rnb" name="new_item[rnb]"
                     placeholder="60">
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-12">
              <label for="ne-mng">Mongolian title</label>
              <input type="text" class="form-control" id="ne-mng" name="new_item[mng]" style="font-size:1.05em" data-autoconv="1">
            </div>
            <div class="col-12">
              <label for="ne-tib">Tibetan title</label>
              <input type="text" class="form-control tib" id="ne-tib" name="new_item[tib]" style="font-size:1.05em" data-autoconv="1">
            </div>
            <div class="col-12">
              <label for="ne-skt">Sanskrit title</label>
              <input type="text" class="form-control" id="ne-skt" name="new_item[skt]" style="font-size:1.05em" data-autoconv="1">
            </div>
          </div>
          <!-- Row 2: coloph + colophTitle + margin -->
          <div class="row g-2 mb-2">
            <div class="col-12">
              <label for="ne-coloph">Colophon</label>
              <textarea class="form-control" id="ne-coloph" name="new_item[coloph]" rows="2" data-autoconv="1"></textarea>
            </div>
            <div class="col-12 col-md-6">
              <label for="ne-ct">Colophon title</label>
              <input type="text" class="form-control" id="ne-ct" name="new_item[colophTitle]">
            </div>
            <div class="col-12 col-md-3">
              <label for="ne-mg">Margin</label>
              <input type="text" class="form-control" id="ne-mg" name="new_item[margin]">
            </div>
          </div>

          <!-- Locations -->
          <div class="mb-2">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <label class="mb-0">Locations</label>
              <button type="button" class="btn btn-sm btn-add-row"
                      onclick="addLocation('new', 'new_item[locations]')">
                <i class="bi bi-plus me-1"></i>Add location
              </button>
            </div>
            <div class="table-responsive">
              <table class="mini-table" id="loc-table-new">
                <thead><tr>
                  <th class="col-loc-setid">setid</th>
                  <th class="col-loc-vol">vol</th>
                  <th class="col-loc-sec">sec</th>
                  <th class="col-loc-pfl">deb-p</th>
                  <th class="col-loc-ff">deb-f</th>
                  <th class="col-loc-pfl">deb-l</th>
                  <th class="col-loc-pfl">fin-p</th>
                  <th class="col-loc-ff">fin-f</th>
                  <th class="col-loc-pfl">fin-l</th>
                  <th class="col-del"></th>
                </tr></thead>
                <tbody id="loc-tbody-new">
                  <!-- blank first row -->
                  <tr>
                    <td><input type="text" class="form-control" name="new_item[locations][setid][0]" placeholder="MW…"></td>
                    <td><input type="text" class="form-control" name="new_item[locations][vol][0]"></td>
                    <td><input type="text" class="form-control" name="new_item[locations][sec][0]"></td>
                    <td><input type="text" class="form-control" name="new_item[locations][debp][0]"></td>
                    <td><input type="text" class="form-control" name="new_item[locations][debf][0]" placeholder="a/b"></td>
                    <td><input type="text" class="form-control" name="new_item[locations][debl][0]"></td>
                    <td><input type="text" class="form-control" name="new_item[locations][finp][0]"></td>
                    <td><input type="text" class="form-control" name="new_item[locations][finf][0]" placeholder="a/b"></td>
                    <td><input type="text" class="form-control" name="new_item[locations][finl][0]"></td>
                    <td class="col-del text-center">
                      <button type="button" class="btn btn-sm btn-outline-danger p-0 px-1"
                              onclick="deleteRow(this)"><i class="bi bi-x"></i></button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Translators -->
          <div>
            <div class="d-flex align-items-center justify-content-between mb-1">
              <label class="mb-0">Translators</label>
              <button type="button" class="btn btn-sm btn-add-row"
                      onclick="addTranslator('new', 'new_item')">
                <i class="bi bi-person-plus me-1"></i>Add translator
              </button>
            </div>
            <div class="table-responsive">
              <table class="mini-table" id="tr-table-new" data-prefix="new_item">
                <thead><tr>
                  <th class="col-tr-name">Name <code style="font-size:.58rem">&lt;name&gt;</code></th>
                  <th class="col-tr-role">Role <code style="font-size:.58rem">&lt;role&gt;</code></th>
                  <th class="col-tr-rid">RID <code style="font-size:.58rem">&lt;rid&gt;</code></th>
                  <th class="col-del"></th>
                </tr></thead>
                <tbody id="tr-tbody-new">
                  <tr id="tr-empty-new">
                    <td colspan="4" class="text-muted text-center py-2"
                        style="font-size:.77rem;font-style:italic;">
                      No translators — click "Add translator"
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

        </div><!-- /.new-entry-panel -->

        <!-- Bampos (new entry) -->
        <div class="new-entry-panel mt-2">
          <div class="d-flex align-items-center justify-content-between mb-1">
            <label class="mb-0 bampo-label">
              <i class="bi bi-bookmark me-1"></i>Bampos
              <span style="font-size:.67rem;font-weight:400;color:#7c3aed;margin-left:.3rem">&lt;bampo&gt;</span>
            </label>
            <button type="button" class="btn btn-sm btn-add-row"
                    onclick="addBampo('new', 'new_item[bampos]')">
              <i class="bi bi-plus me-1"></i>Add bampo
            </button>
          </div>
          <div class="table-responsive">
            <table class="mini-table" id="bp-table-new" data-ref-setid="">
              <thead><tr>
                <th class="col-bp-rn">rn</th>
                <th class="col-bp-name">name</th>
                <th class="col-bp-setid" title="setid — always ref set">setid <span style="color:var(--r-lt);font-size:.6rem">★</span></th>
                <th class="col-loc-vol">vol</th>
                <th class="col-loc-sec">sec</th>
                <th class="col-loc-pfl">deb-p</th>
                <th class="col-loc-ff">deb-f</th>
                <th class="col-loc-pfl">deb-l</th>
                <th class="col-del"></th>
              </tr></thead>
              <tbody id="bp-tbody-new">
                <tr id="bp-empty-new">
                  <td colspan="9" class="text-muted text-center py-2"
                      style="font-size:.77rem;font-style:italic;">
                    No bampos — click "Add bampo"
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Chapters (new entry) -->
        <div class="new-entry-panel mt-2">
          <div class="d-flex align-items-center justify-content-between mb-1">
            <label class="mb-0 chapter-label">
              <i class="bi bi-list-ol me-1"></i>Chapters
              <span style="font-size:.67rem;font-weight:400;color:#0e7490;margin-left:.3rem">&lt;chapter&gt;</span>
            </label>
            <button type="button" class="btn btn-sm btn-add-row"
                    onclick="addChapter('new', 'new_item[chapters]')">
              <i class="bi bi-plus me-1"></i>Add chapter
            </button>
          </div>
          <div class="table-responsive">
            <table class="mini-table" id="ch-table-new" data-ref-setid="">
              <thead><tr>
                <th class="col-ch-rn">rn</th>
                <th class="col-ch-name">name</th>
                <th class="col-ch-setid" title="setid — always ref set">setid <span style="color:var(--r-lt);font-size:.6rem">★</span></th>
                <th class="col-loc-vol">vol</th>
                <th class="col-loc-sec">sec</th>
                <th class="col-loc-pfl">deb-p</th>
                <th class="col-loc-ff">deb-f</th>
                <th class="col-loc-pfl">deb-l</th>
                <th class="col-del"></th>
              </tr></thead>
              <tbody id="ch-tbody-new">
                <tr id="ch-empty-new">
                  <td colspan="9" class="text-muted text-center py-2"
                      style="font-size:.77rem;font-style:italic;">
                    No chapters — click "Add chapter"
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Note (new entry) -->
        <div class="new-entry-panel mt-2">
          <label class="mb-1 note-label" for="ne-note">
            <i class="bi bi-sticky me-1"></i>Note
            <span style="font-size:.67rem;font-weight:400;color:#065f46;margin-left:.3rem">&lt;note&gt;</span>
          </label>
          <textarea class="form-control" id="ne-note" name="new_item[note]"
                    rows="3"
                    placeholder="Free-text editorial note…"></textarea>
        </div>

        <div class="d-flex justify-content-end mt-3">
          <button type="submit" class="btn btn-rkts-new">
            <i class="bi bi-file-plus me-1"></i>
            Add entry to <?= htmlspecialchars($rkts_id) ?>.xml
          </button>
        </div>
      </form>
    </div><!-- /#new-entry-body -->
  </div><!-- /.new-entry-card -->

  <?php endif; /* cat && rkts */ ?>

  <?php if ($catalog_by_sigla): ?>

  <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
    <span class="text-muted small fw-semibold">
      <?= count($catalog_by_sigla) ?> collection(s) ·
      <?= count($catalog_items_flat) ?> catalog item(s) for
      Mongolian&nbsp;<strong><?= htmlspecialchars($rkts_id) ?></strong>
    </span>
    <button class="btn btn-sm btn-outline-secondary ms-auto" id="btn-expand-all">
      <i class="bi bi-arrows-expand me-1"></i>Expand all
    </button>
    <button class="btn btn-sm btn-outline-secondary" id="btn-collapse-all">
      <i class="bi bi-arrows-collapse me-1"></i>Collapse all
    </button>
  </div>

  <?php
  $js_tr_counters      = [];
  $js_loc_counters     = [];
  $js_effective_setids = [];
  $js_bp_counters      = [];
  $js_ch_counters      = [];
  ?>
  <?php foreach ($catalog_by_sigla as $sigla => $items):
    $coll_info_d = coll_info($collections, $sigla, $cat_c);
    $coll_title  = $coll_info_d['title'] ?? '(unknown)';
    $has_file    = $items[0]['coll_file_exists'] ?? false;
    $ref_marked  = $items[0]['ref_has_marker']   ?? false;
    $active_sids = $items[0]['active_setids']    ?? [];
    $all_refs    = array_column($items, 'ref');
    $safe_sigla  = htmlspecialchars($sigla);
    $acc_id      = 'acc-' . preg_replace('/[^a-z0-9]/i', '-', $sigla);
  ?>

  <div class="coll-card">
    <div class="coll-card-header">
      <button class="accordion-button collapsed bg-transparent border-0 p-0 text-white shadow-none d-flex align-items-center gap-2 flex-grow-1"
              type="button" data-bs-toggle="collapse" data-bs-target="#body-<?= $acc_id ?>" aria-expanded="false">
        <span class="coll-badge"><?= $safe_sigla ?></span>
        <span class="cat-badge cat-badge-<?= htmlspecialchars($cat_c) ?>"><?= htmlspecialchars($cat_c) ?></span>
        <span class="coll-title"><?= htmlspecialchars($coll_title) ?></span>
        <?php foreach ($all_refs as $r): ?>
          <span class="coll-ref-chip"><?= htmlspecialchars($r) ?></span>
        <?php endforeach; ?>
       </button>
    </div>

    <div id="body-<?= $acc_id ?>" class="accordion-collapse collapse">
      <div class="accordion-body p-0">

        <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>"
              id="form-<?= $safe_sigla ?>">
          <input type="hidden" name="action"     value="save_entry">
          <input type="hidden" name="canon"      value="<?= htmlspecialchars($canon) ?>">
          <input type="hidden" name="rkts"       value="<?= htmlspecialchars($rkts) ?>">
          <input type="hidden" name="save_sigla" value="<?= $safe_sigla ?>">
          <?php foreach ($all_refs as $rh): ?>
          <input type="hidden" name="refs_posted[]" value="<?= htmlspecialchars($rh) ?>">
          <?php endforeach; ?>

          <?php foreach ($items as $item_idx => $ci):
            $is_multi    = count($items) > 1;
            $ref         = $ci['ref'];
            $safe_ref    = htmlspecialchars($ref);
            $n           = "cat_items[{$safe_sigla}][{$safe_ref}]";
            $tid         = preg_replace('/[^a-z0-9]/i', '_', $sigla . '__' . $ref);
            $active_flip = array_flip($active_sids);
            $all_loc_setids = array_unique(array_column($ci['locations'], 'setid'));
          ?>

          <?php if ($is_multi && $item_idx > 0): ?><div class="multi-item-divider"></div><?php endif; ?>
          <?php if ($is_multi): ?>
          <div class="item-sub-header">
            <i class="bi bi-bookmark-fill"></i>
            <strong><?= $safe_ref ?></strong>
            <span class="fw-normal text-muted ms-1">rnb&nbsp;<?= htmlspecialchars($ci['rnb']) ?></span>
          </div>
          <?php endif; ?>

          <div class="p-3">
            <p class="section-label">
              <i class="bi bi-book me-1"></i>Catalog Data
              <span class="fw-normal text-muted ms-2">→ <code>mongol/<?= htmlspecialchars($cat_c) ?>/<?= htmlspecialchars($rkts_id) ?>.xml</code></span>
            </p>

            <div class="catalog-edit-panel">
              <div class="row g-2 mb-2">
                <div class="col-auto" style="width:6rem">
                  <label for="rnb-<?= $tid ?>">rnb</label>
                  <input type="text" class="form-control" id="rnb-<?= $tid ?>"
                         name="<?= $n ?>[rnb]" value="<?= htmlspecialchars($ci['rnb']) ?>">
                </div>
                <div class="col-auto" style="width:8rem">
                  <label>ref <span class="fw-normal text-muted">(key)</span></label>
                  <input type="text" class="form-control" value="<?= $safe_ref ?>" readonly
                         style="background:#ddeeff;color:var(--r-dk);">
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-12">
                  <label for="mng-<?= $tid ?>">Mongolian title</label>
                  <input type="text" class="form-control" id="mng-<?= $tid ?>"
                         name="<?= $n ?>[mng]" value="<?= htmlspecialchars($ci['mng']) ?>"
                         style="font-size:1.05em" data-autoconv="1">
                </div>
                <div class="col-12">
                  <label for="tib-<?= $tid ?>">Tibetan title</label>
                  <input type="text" class="form-control tib" id="tib-<?= $tid ?>"
                         name="<?= $n ?>[tib]" value="<?= htmlspecialchars($ci['tib']) ?>" style="font-size:1.5em" data-autoconv="1">
                </div>
                <div class="col-12">
                  <label for="skt-<?= $tid ?>">Sanskrit title</label>
                  <input type="text" class="form-control" id="skt-<?= $tid ?>"
                         name="<?= $n ?>[skt]" value="<?= htmlspecialchars($ci['skt']) ?>" style="font-size:1.5em" data-autoconv="1">
                </div>
                <div class="col-12">
                  <label for="coloph-<?= $tid ?>">Colophon</label>
                  <textarea class="form-control" id="coloph-<?= $tid ?>"
                            name="<?= $n ?>[coloph]" rows="2" style="font-size:1.05em" data-autoconv="1"><?= htmlspecialchars($ci['coloph']) ?></textarea>
                </div>
                <div class="col-12 col-md-6">
                  <label for="ct-<?= $tid ?>">Colophon title</label>
                  <input type="text" class="form-control" id="ct-<?= $tid ?>"
                         name="<?= $n ?>[colophTitle]" value="<?= htmlspecialchars($ci['colophTitle']) ?>" style="font-size:1.05em">
                </div>
                <div class="col-12 col-md-3">
                  <label for="mg-<?= $tid ?>">Margin</label>
                  <input type="text" class="form-control" id="mg-<?= $tid ?>"
                         name="<?= $n ?>[margin]" value="<?= htmlspecialchars($ci['margin']) ?>">
                </div>
              </div>

              <!-- Translator table -->
              <div class="mt-2 mb-2">
                <div class="d-flex align-items-center justify-content-between mb-1">
                  <label class="mb-0">Translators</label>
                  <button type="button" class="btn btn-sm btn-add-row"
                          onclick="addTranslator('<?= $tid ?>', '<?= htmlspecialchars("cat_items[{$sigla}][{$ref}]") ?>')">
                    <i class="bi bi-person-plus me-1"></i>Add translator
                  </button>
                </div>
                <div class="table-responsive">
                  <table class="mini-table" id="tr-table-<?= $tid ?>"
                         data-prefix="<?= htmlspecialchars("cat_items[{$sigla}][{$ref}]") ?>">
                    <thead><tr>
                      <th class="col-tr-name">Name <code style="font-size:.58rem">&lt;name&gt;</code></th>
                      <th class="col-tr-role">Role <code style="font-size:.58rem">&lt;role&gt;</code></th>
                      <th class="col-tr-rid">RID <code style="font-size:.58rem">&lt;rid&gt;</code></th>
                      <th class="col-del"></th>
                    </tr></thead>
                    <tbody id="tr-tbody-<?= $tid ?>">
                    <?php foreach ($ci['translators'] as $ti => $tr): ?>
                    <tr>
                      <td class="col-tr-name">
                        <input type="text" class="form-control tib"
                               name="<?= $n ?>[tr_name][<?= $ti ?>]"
                               value="<?= htmlspecialchars($tr['name']) ?>" placeholder="name"
                               list="rkts-persons" autocomplete="off"
                               oninput="personsHint(this)">
                      </td>
                      <td class="col-tr-role">
                        <input type="text" class="form-control"
                               name="<?= $n ?>[tr_role][<?= $ti ?>]"
                               value="<?= htmlspecialchars($tr['role']) ?>" placeholder="role">
                      </td>
                      <td class="col-tr-rid">
                        <input type="text" class="form-control"
                               name="<?= $n ?>[tr_rid][<?= $ti ?>]"
                               value="<?= htmlspecialchars($tr['rid']) ?>" placeholder="bdr:P…">
                      </td>
                      <td class="col-del text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger p-0 px-1"
                                onclick="deleteRow(this)"><i class="bi bi-x"></i></button>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ci['translators'])): ?>
                    <tr id="tr-empty-<?= $tid ?>">
                      <td colspan="4" class="text-muted text-center py-2"
                          style="font-size:.77rem;font-style:italic;">
                        No translators — click "Add translator"
                      </td>
                    </tr>
                    <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <!-- Locations: editable mini-table ───────────────────── -->
              <?php
                $eff_flip = array_flip($ci['effective_setids']);
                $loc_prefix = "cat_items[{$sigla}][{$ref}][locations]";
              ?>
              <div class="mt-2">
                <div class="d-flex align-items-center justify-content-between mb-1">
                  <label class="mb-0">
                    Locations
                    <span style="font-size:.67rem;font-weight:400;color:var(--r-md);margin-left:.4rem">
                      ★ = used for
                      <span class="cat-badge cat-badge-<?= htmlspecialchars($cat_c) ?>"
                            style="font-size:.62rem;padding:.05em .35em"><?= htmlspecialchars($cat_c) ?></span>
                      collection rows
                    </span>
                    <?php if ($ci['ref_fallback']): ?>
                    <span class="fallback-setid-note ms-1">
                      <i class="bi bi-exclamation-triangle"></i>
                      ref set not in item — all used
                    </span>
                    <?php endif; ?>
                  </label>
                  <button type="button" class="btn btn-sm btn-add-row"
                          onclick="addLocation('<?= $tid ?>', '<?= htmlspecialchars($loc_prefix) ?>')">
                    <i class="bi bi-plus me-1"></i>Add location
                  </button>
                </div>
                <div class="table-responsive">
                  <table class="mini-table" id="loc-table-<?= $tid ?>">
                    <thead><tr>
                      <th class="col-loc-setid">setid</th>
                      <th class="col-loc-vol">vol</th>
                      <th class="col-loc-sec">sec</th>
                      <th class="col-loc-pfl">deb-p</th>
                      <th class="col-loc-ff">deb-f</th>
                      <th class="col-loc-pfl">deb-l</th>
                      <th class="col-loc-pfl">fin-p</th>
                      <th class="col-loc-ff">fin-f</th>
                      <th class="col-loc-pfl">fin-l</th>
                      <th class="col-del"></th>
                    </tr></thead>
                    <tbody id="loc-tbody-<?= $tid ?>">
                    <?php foreach ($ci['locations'] as $li => $loc):
                      $is_ref = isset($eff_flip[$loc['setid']]) && $loc['vol'] !== '';
                    ?>
                    <tr class="<?= $is_ref ? 'loc-ref-row' : '' ?>">
                      <td class="col-loc-setid">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($loc_prefix) ?>[setid][<?= $li ?>]"
                               value="<?= htmlspecialchars($loc['setid']) ?>"
                               placeholder="MW…"
                               oninput="highlightLocRow(this)">
                      </td>
                      <td class="col-loc-vol">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($loc_prefix) ?>[vol][<?= $li ?>]"
                               value="<?= htmlspecialchars($loc['vol']) ?>">
                      </td>
                      <td class="col-loc-sec">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($loc_prefix) ?>[sec][<?= $li ?>]"
                               value="<?= htmlspecialchars($loc['sec']) ?>">
                      </td>
                      <td class="col-loc-pfl">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($loc_prefix) ?>[debp][<?= $li ?>]"
                               value="<?= htmlspecialchars($loc['debp']) ?>">
                      </td>
                      <td class="col-loc-ff">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($loc_prefix) ?>[debf][<?= $li ?>]"
                               value="<?= htmlspecialchars($loc['debf']) ?>"
                               placeholder="a/b">
                      </td>
                      <td class="col-loc-pfl">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($loc_prefix) ?>[debl][<?= $li ?>]"
                               value="<?= htmlspecialchars($loc['debl']) ?>">
                      </td>
                      <td class="col-loc-pfl">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($loc_prefix) ?>[finp][<?= $li ?>]"
                               value="<?= htmlspecialchars($loc['finp']) ?>">
                      </td>
                      <td class="col-loc-ff">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($loc_prefix) ?>[finf][<?= $li ?>]"
                               value="<?= htmlspecialchars($loc['finf']) ?>"
                               placeholder="a/b">
                      </td>
                      <td class="col-loc-pfl">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($loc_prefix) ?>[finl][<?= $li ?>]"
                               value="<?= htmlspecialchars($loc['finl']) ?>">
                      </td>
                      <td class="col-del text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger p-0 px-1"
                                onclick="deleteRow(this)" title="Remove location">
                          <i class="bi bi-x"></i>
                        </button>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ci['locations'])): ?>
                    <tr id="loc-empty-<?= $tid ?>">
                      <td colspan="10" class="text-muted text-center py-2"
                          style="font-size:.77rem;font-style:italic;">
                        No locations — click "Add location"
                      </td>
                    </tr>
                    <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <!-- Bampos: editable mini-table ───────────────────── -->
              <?php
                $bp_prefix  = "cat_items[{$sigla}][{$ref}][bampos]";
                $bp_ref_sid = $ci['effective_setids'][0] ?? '';
              ?>
              <div class="mt-3">
                <div class="d-flex align-items-center justify-content-between mb-1">
                  <label class="mb-0 bampo-label">
                    <i class="bi bi-bookmark me-1"></i>Bampos
                    <span style="font-size:.67rem;font-weight:400;color:#7c3aed;margin-left:.3rem">&lt;bampo&gt;</span>
                  </label>
                  <button type="button" class="btn btn-sm btn-add-row"
                          onclick="addBampo('<?= $tid ?>', '<?= htmlspecialchars($bp_prefix) ?>')">
                    <i class="bi bi-plus me-1"></i>Add bampo
                  </button>
                </div>
                <div class="table-responsive">
                  <table class="mini-table" id="bp-table-<?= $tid ?>"
                         data-ref-setid="<?= htmlspecialchars($bp_ref_sid) ?>">
                    <thead><tr>
                      <th class="col-bp-rn">rn</th>
                      <th class="col-bp-name">name</th>
                      <th class="col-bp-setid" title="setid — always ref set">setid <span style="color:var(--r-lt);font-size:.6rem">★</span></th>
                      <th class="col-loc-vol">vol</th>
                      <th class="col-loc-sec">sec</th>
                      <th class="col-loc-pfl">deb-p</th>
                      <th class="col-loc-ff">deb-f</th>
                      <th class="col-loc-pfl">deb-l</th>
                      <th class="col-del"></th>
                    </tr></thead>
                    <tbody id="bp-tbody-<?= $tid ?>">
                    <?php foreach ($ci['bampos'] as $bi => $bp): ?>
                    <tr>
                      <td class="col-bp-rn">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($bp_prefix) ?>[rn][<?= $bi ?>]"
                               value="<?= htmlspecialchars($bp['rn']) ?>" placeholder="1">
                      </td>
                      <td class="col-bp-name">
                        <input type="text" class="form-control tib"
                               name="<?= htmlspecialchars($bp_prefix) ?>[name][<?= $bi ?>]"
                               value="<?= htmlspecialchars($bp['name']) ?>">
                      </td>
                      <td class="col-bp-setid">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($bp_prefix) ?>[setid][<?= $bi ?>]"
                               value="<?= htmlspecialchars($bp['setid']) ?>" placeholder="MW…"
                               style="background:#ddeeff;color:var(--r-dk);">
                      </td>
                      <td class="col-loc-vol">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($bp_prefix) ?>[vol][<?= $bi ?>]"
                               value="<?= htmlspecialchars($bp['vol']) ?>">
                      </td>
                      <td class="col-loc-sec">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($bp_prefix) ?>[sec][<?= $bi ?>]"
                               value="<?= htmlspecialchars($bp['sec']) ?>">
                      </td>
                      <td class="col-loc-pfl">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($bp_prefix) ?>[debp][<?= $bi ?>]"
                               value="<?= htmlspecialchars($bp['debp']) ?>">
                      </td>
                      <td class="col-loc-ff">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($bp_prefix) ?>[debf][<?= $bi ?>]"
                               value="<?= htmlspecialchars($bp['debf']) ?>" placeholder="a/b">
                      </td>
                      <td class="col-loc-pfl">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($bp_prefix) ?>[debl][<?= $bi ?>]"
                               value="<?= htmlspecialchars($bp['debl']) ?>">
                      </td>
                      <td class="col-del text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger p-0 px-1"
                                onclick="deleteRow(this)"><i class="bi bi-x"></i></button>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ci['bampos'])): ?>
                    <tr id="bp-empty-<?= $tid ?>">
                      <td colspan="9" class="text-muted text-center py-2"
                          style="font-size:.77rem;font-style:italic;">
                        No bampos — click "Add bampo"
                      </td>
                    </tr>
                    <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <!-- Chapters: editable mini-table ───────────────────── -->
              <?php
                $ch_prefix  = "cat_items[{$sigla}][{$ref}][chapters]";
                $ch_ref_sid = $ci['effective_setids'][0] ?? '';
              ?>
              <div class="mt-3">
                <div class="d-flex align-items-center justify-content-between mb-1">
                  <label class="mb-0 chapter-label">
                    <i class="bi bi-list-ol me-1"></i>Chapters
                    <span style="font-size:.67rem;font-weight:400;color:#0e7490;margin-left:.3rem">&lt;chapter&gt;</span>
                  </label>
                  <button type="button" class="btn btn-sm btn-add-row"
                          onclick="addChapter('<?= $tid ?>', '<?= htmlspecialchars($ch_prefix) ?>')">
                    <i class="bi bi-plus me-1"></i>Add chapter
                  </button>
                </div>
                <div class="table-responsive">
                  <table class="mini-table" id="ch-table-<?= $tid ?>"
                         data-ref-setid="<?= htmlspecialchars($ch_ref_sid) ?>">
                    <thead><tr>
                      <th class="col-ch-rn">rn</th>
                      <th class="col-ch-name">name</th>
                      <th class="col-ch-setid" title="setid — always ref set">setid <span style="color:var(--r-lt);font-size:.6rem">★</span></th>
                      <th class="col-loc-vol">vol</th>
                      <th class="col-loc-sec">sec</th>
                      <th class="col-loc-pfl">deb-p</th>
                      <th class="col-loc-ff">deb-f</th>
                      <th class="col-loc-pfl">deb-l</th>
                      <th class="col-del"></th>
                    </tr></thead>
                    <tbody id="ch-tbody-<?= $tid ?>">
                    <?php foreach ($ci['chapters'] as $ci_idx => $ch): ?>
                    <tr>
                      <td class="col-ch-rn">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($ch_prefix) ?>[rn][<?= $ci_idx ?>]"
                               value="<?= htmlspecialchars($ch['rn']) ?>" placeholder="1">
                      </td>
                      <td class="col-ch-name">
                        <input type="text" class="form-control tib"
                               name="<?= htmlspecialchars($ch_prefix) ?>[name][<?= $ci_idx ?>]"
                               value="<?= htmlspecialchars($ch['name']) ?>">
                      </td>
                      <td class="col-ch-setid">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($ch_prefix) ?>[setid][<?= $ci_idx ?>]"
                               value="<?= htmlspecialchars($ch['setid']) ?>" placeholder="MW…"
                               style="background:#ddeeff;color:var(--r-dk);">
                      </td>
                      <td class="col-loc-vol">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($ch_prefix) ?>[vol][<?= $ci_idx ?>]"
                               value="<?= htmlspecialchars($ch['vol']) ?>">
                      </td>
                      <td class="col-loc-sec">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($ch_prefix) ?>[sec][<?= $ci_idx ?>]"
                               value="<?= htmlspecialchars($ch['sec']) ?>">
                      </td>
                      <td class="col-loc-pfl">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($ch_prefix) ?>[debp][<?= $ci_idx ?>]"
                               value="<?= htmlspecialchars($ch['debp']) ?>">
                      </td>
                      <td class="col-loc-ff">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($ch_prefix) ?>[debf][<?= $ci_idx ?>]"
                               value="<?= htmlspecialchars($ch['debf']) ?>" placeholder="a/b">
                      </td>
                      <td class="col-loc-pfl">
                        <input type="text" class="form-control"
                               name="<?= htmlspecialchars($ch_prefix) ?>[debl][<?= $ci_idx ?>]"
                               value="<?= htmlspecialchars($ch['debl']) ?>">
                      </td>
                      <td class="col-del text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger p-0 px-1"
                                onclick="deleteRow(this)"><i class="bi bi-x"></i></button>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ci['chapters'])): ?>
                    <tr id="ch-empty-<?= $tid ?>">
                      <td colspan="9" class="text-muted text-center py-2"
                          style="font-size:.77rem;font-style:italic;">
                        No chapters — click "Add chapter"
                      </td>
                    </tr>
                    <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>

            </div><!-- /.catalog-edit-panel -->

            <!-- Note -->
              <?php $note_name = "cat_items[{$sigla}][{$ref}][note]"; ?>
              <div class="catalog-edit-panel mt-2">
                <label class="mb-1 note-label" for="note-<?= $tid ?>">
                  <i class="bi bi-sticky me-1"></i>Note
                  <span style="font-size:.67rem;font-weight:400;color:#065f46;margin-left:.3rem">&lt;note&gt;</span>
                </label>
                <textarea class="form-control" id="note-<?= $tid ?>"
                          name="<?= htmlspecialchars($note_name) ?>"
                          rows="3"
                          placeholder="Free-text editorial note…"><?= htmlspecialchars($ci['note'] ?? '') ?></textarea>
              </div>

            <!-- Collection rows: display only -->
            <p class="section-label">
              <i class="bi bi-table me-1"></i>
              Collection rows for <strong><?= $safe_ref ?></strong>
              <span class="cat-badge cat-badge-<?= htmlspecialchars($cat_c) ?> ms-1"><?= htmlspecialchars($cat_c) ?></span>
              <span class="fw-normal ms-2">→ <code>collections/<?= $safe_sigla ?>/<?= $safe_sigla ?>.xml</code></span>
              <span class="readonly-note ms-2">
                <i class="bi bi-lock me-1"></i>all sets · auto-derived from catalog locations · written on save
              </span>
            </p>
            <div class="table-responsive mb-1">
              <table class="table table-sm table-bordered coll-display-table mb-0">
                <?php
                  // Build vol→setid lookup from catalog locations for display
                  $vol_to_setid = [];
                  foreach ($ci['locations'] as $loc) {
                      if ($loc['vol'] !== '') $vol_to_setid[$loc['vol']] = $loc['setid'];
                  }
                ?>
                <thead><tr>
                  <th>ref</th><th>rkts</th><th>cat</th>
                  <th title="setid — ★ = isreferenceset=1">setid</th>
                  <th>vol</th><th>sec</th>
                  <th>deb-p</th><th>deb-f</th><th>deb-l</th>
                  <th>fin-p</th><th>fin-f</th><th>fin-l</th>
                  <th>mng</th><th>tib</th>
                </tr></thead>
                <tbody>
                <?php if ($ci['coll_rows']): ?>
                  <?php foreach ($ci['coll_rows'] as $row):
                    $row_setid  = $vol_to_setid[$row['vol']] ?? '';
                    $is_ref_row = isset($eff_flip[$row_setid]);
                  ?>
                  <tr class="<?= $is_ref_row ? 'coll-ref-row' : '' ?>">
                    <td><?= htmlspecialchars($row['ref'])  ?></td>
                    <td><?= htmlspecialchars($row['rkts']) ?></td>
                    <td><?= htmlspecialchars($row['cat'])  ?></td>
                    <td style="font-family:monospace;font-size:.72rem">
                      <?= htmlspecialchars($row_setid) ?>
                      <?= $is_ref_row ? '<span title="isreferenceset=1" style="color:var(--r-lt)">★</span>' : '' ?>
                    </td>
                    <td><?= htmlspecialchars($row['vol'])  ?></td>
                    <td><?= htmlspecialchars($row['sec'])  ?></td>
                    <td><?= htmlspecialchars($row['debp']) ?></td>
                    <td><?= htmlspecialchars($row['debf']) ?></td>
                    <td><?= htmlspecialchars($row['debl']) ?></td>
                    <td><?= htmlspecialchars($row['finp']) ?></td>
                    <td><?= htmlspecialchars($row['finf']) ?></td>
                    <td><?= htmlspecialchars($row['finl']) ?></td>
                    <td class="tib-cell"><?= htmlspecialchars($row['mng'] ?? '') ?></td>
                    <td class="tib-cell"><?= htmlspecialchars($row['tib'] ?? '') ?></td>
                  </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="14" class="text-muted text-center py-2"
                          style="font-style:italic;font-size:.78rem">
                    No locations found in catalog item
                  </td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div><!-- /.p-3 -->
          <?php endforeach; /* items */ ?>

          <!-- Save -->
          <div class="px-3 pb-3">
            <div class="info-bar mb-2">
              <i class="bi bi-info-circle me-1"></i>
              Saves catalog metadata to <strong>mongol/<?= htmlspecialchars($cat_c) ?>/<?= htmlspecialchars($rkts_id) ?>.xml</strong>
              and auto-derived rows to <strong>collections/<?= $safe_sigla ?>/<?= $safe_sigla ?>.xml</strong>
              (ref ∈ {<?= htmlspecialchars(implode(', ', $all_refs)) ?>}, cat=<?= htmlspecialchars($cat_c) ?>).
            </div>
            <div class="d-flex justify-content-end">
              <button type="submit" class="btn btn-rkts">
                <i class="bi bi-floppy me-1"></i>
                Save <?= $safe_sigla ?>.xml &amp; <?= htmlspecialchars($rkts_id) ?>.xml
              </button>
            </div>
          </div>
        </form>

      </div><!-- /.accordion-body -->
    </div><!-- /.accordion-collapse -->
  </div><!-- /.coll-card -->

  <?php
    // Accumulate counter data — emitted once after the loop
    foreach ($items as $ci) {
        $tid = preg_replace('/[^a-z0-9]/i', '_', $sigla . '__' . $ci['ref']);
        $js_tr_counters[$tid]       = count($ci['translators']);
        $js_loc_counters[$tid]      = count($ci['locations']);
        $js_effective_setids[$tid]  = $ci['effective_setids'];
        $js_bp_counters[$tid]       = count($ci['bampos']);
        $js_ch_counters[$tid]       = count($ci['chapters']);
    }
  ?>
  <?php endforeach; /* catalog_by_sigla */ ?>
  <?php endif; ?>

</div><!-- /.container-xxl -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($js_tr_counters)): ?>
<script>
window.trCounters      = <?= json_encode($js_tr_counters) ?>;
window.locCounters     = <?= json_encode($js_loc_counters) ?>;
window.effectiveSetids = <?= json_encode($js_effective_setids) ?>;
window.bpCounters      = <?= json_encode($js_bp_counters) ?>;
window.chCounters      = <?= json_encode($js_ch_counters) ?>;
</script>
<?php endif; ?>
<script>
// counters seeded by window.* above

/* ── Persons lookup map ── */
(function () {
  const raw = <?= json_encode($persons_data, JSON_UNESCAPED_UNICODE) ?>;
  window.personsMap = Object.create(null);
  for (const [name, rid, stdname] of raw) {
    const key = name.toLowerCase();
    if (!window.personsMap[key]) window.personsMap[key] = { rid, stdname, name };
  }
})();

function personsHint(inp) {
  const td = inp.closest('td');
  if (!td) return;
  let hint = td.querySelector('.tr-persons-hint');
  if (!hint) {
    hint = document.createElement('div');
    hint.className = 'tr-persons-hint';
    td.appendChild(hint);
  }
  const val = inp.value.trim();
  const match = val ? window.personsMap[val.toLowerCase()] : null;
  if (match) {
    hint.textContent = (match.stdname || match.name) + '  ·  ' + match.rid;
    hint.classList.add('show');
  } else {
    hint.classList.remove('show');
  }
}

/* ── Translator rows ── */
function addTranslator(tid, prefix) {
    const tbody = document.getElementById('tr-tbody-' + tid);
    if (!tbody) return;
    const emptyRow = document.getElementById('tr-empty-' + tid);
    if (emptyRow) emptyRow.remove();

    if (trCounters[tid] === undefined)
        trCounters[tid] = tbody.querySelectorAll('tr').length;
    const idx = trCounters[tid]++;

    // Determine prefix: from argument or from table data attribute
    if (!prefix) {
        const tbl = document.getElementById('tr-table-' + tid);
        prefix = tbl ? tbl.dataset.prefix : '';
    }

    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="col-tr-name">
        <input type="text" class="form-control tib"
               name="${prefix}[tr_name][${idx}]" placeholder="name"
               list="rkts-persons" autocomplete="off" oninput="personsHint(this)">
      </td>
      <td class="col-tr-role">
        <input type="text" class="form-control"
               name="${prefix}[tr_role][${idx}]" value="translator">
      </td>
      <td class="col-tr-rid">
        <input type="text" class="form-control"
               name="${prefix}[tr_rid][${idx}]" placeholder="bdr:P…">
      </td>
      <td class="col-del text-center">
        <button type="button" class="btn btn-sm btn-outline-danger p-0 px-1"
                onclick="deleteRow(this)"><i class="bi bi-x"></i></button>
      </td>`;
    tbody.appendChild(tr);
    tr.querySelector('input')?.focus();
}

/* ── Location rows ── */
// tid     : table id suffix  (for existing entries: "D__D59"; for new entry: "new")
// prefix  : POST name prefix (e.g. "cat_items[D][D59][locations]" or "new_item[locations]")
function addLocation(tid, prefix) {
    const emptyRow = document.getElementById('loc-empty-' + tid);
    if (emptyRow) emptyRow.remove();

    if (locCounters[tid] === undefined)
        locCounters[tid] = document.querySelectorAll('#loc-tbody-' + tid + ' tr').length;
    const idx   = locCounters[tid]++;
    const tbody = document.getElementById('loc-tbody-' + tid);
    if (!tbody) return;

    const lf = ['setid','vol','sec','debp','debf','debl','finp','finf','finl'];
    const ph  = { setid: 'MW…', debf: 'a/b', finf: 'a/b' };

    const tr = document.createElement('tr');
    lf.forEach(f => {
        const td  = document.createElement('td');
        const inp = document.createElement('input');
        inp.type        = 'text';
        inp.className   = 'form-control';
        inp.name        = `${prefix}[${f}][${idx}]`;
        inp.placeholder = ph[f] || '';
        if (f === 'setid') inp.setAttribute('oninput', 'highlightLocRow(this)');
        td.appendChild(inp); tr.appendChild(td);
    });
    const tdD = document.createElement('td');
    tdD.className = 'col-del text-center';
    tdD.innerHTML =
        '<button type="button" class="btn btn-sm btn-outline-danger p-0 px-1" '
        + 'onclick="deleteRow(this)" title="Remove location"><i class="bi bi-x"></i></button>';
    tr.appendChild(tdD);
    tbody.appendChild(tr);
    tr.querySelector('input')?.focus();
}

/* ── Highlight location rows whose setid matches a known reference set ── */
// effectiveSetids is populated per-table via PHP inline script below.

function highlightLocRow(input) {
    const tr  = input.closest('tr');
    if (!tr) return;
    // Find the table id from the tbody
    const tbody = tr.closest('tbody');
    if (!tbody) return;
    const tid = tbody.id.replace(/^loc-tbody-/, '');
    const refs = effectiveSetids[tid] || [];
    tr.classList.toggle('loc-ref-row', refs.includes(input.value.trim()));
}

function deleteRow(btn) {
    const tr = btn.closest('tr');
    if (tr && confirm('Remove this row?')) tr.remove();
}

/* ── Bampo rows ── */
function addBampo(tid, prefix) {
    const emptyRow = document.getElementById('bp-empty-' + tid);
    if (emptyRow) emptyRow.remove();

    if (bpCounters[tid] === undefined)
        bpCounters[tid] = document.querySelectorAll('#bp-tbody-' + tid + ' tr').length;
    const idx   = bpCounters[tid]++;
    const tbody = document.getElementById('bp-tbody-' + tid);
    if (!tbody) return;

    // Read ref setid from table data attribute
    const tbl      = document.getElementById('bp-table-' + tid);
    const refSetid = tbl ? (tbl.dataset.refSetid || '') : '';

    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="col-bp-rn">
        <input type="text" class="form-control" name="${prefix}[rn][${idx}]" placeholder="1">
      </td>
      <td class="col-bp-name">
        <input type="text" class="form-control tib" name="${prefix}[name][${idx}]">
      </td>
      <td class="col-bp-setid">
        <input type="text" class="form-control" name="${prefix}[setid][${idx}]"
               value="${refSetid}" placeholder="MW…"
               style="background:#ddeeff;color:var(--r-dk);">
      </td>
      <td class="col-loc-vol">
        <input type="text" class="form-control" name="${prefix}[vol][${idx}]">
      </td>
      <td class="col-loc-sec">
        <input type="text" class="form-control" name="${prefix}[sec][${idx}]">
      </td>
      <td class="col-loc-pfl">
        <input type="text" class="form-control" name="${prefix}[debp][${idx}]">
      </td>
      <td class="col-loc-ff">
        <input type="text" class="form-control" name="${prefix}[debf][${idx}]" placeholder="a/b">
      </td>
      <td class="col-loc-pfl">
        <input type="text" class="form-control" name="${prefix}[debl][${idx}]">
      </td>
      <td class="col-del text-center">
        <button type="button" class="btn btn-sm btn-outline-danger p-0 px-1"
                onclick="deleteRow(this)"><i class="bi bi-x"></i></button>
      </td>`;
    tbody.appendChild(tr);
    tr.querySelector('input')?.focus();
}

/* ── Chapter rows ── */
function addChapter(tid, prefix) {
    const emptyRow = document.getElementById('ch-empty-' + tid);
    if (emptyRow) emptyRow.remove();

    if (chCounters[tid] === undefined)
        chCounters[tid] = document.querySelectorAll('#ch-tbody-' + tid + ' tr').length;
    const idx   = chCounters[tid]++;
    const tbody = document.getElementById('ch-tbody-' + tid);
    if (!tbody) return;

    // Read ref setid from table data attribute
    const tbl      = document.getElementById('ch-table-' + tid);
    const refSetid = tbl ? (tbl.dataset.refSetid || '') : '';

    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="col-ch-rn">
        <input type="text" class="form-control" name="${prefix}[rn][${idx}]" placeholder="1">
      </td>
      <td class="col-ch-name">
        <input type="text" class="form-control tib" name="${prefix}[name][${idx}]">
      </td>
      <td class="col-ch-setid">
        <input type="text" class="form-control" name="${prefix}[setid][${idx}]"
               value="${refSetid}" placeholder="MW…"
               style="background:#ddeeff;color:var(--r-dk);">
      </td>
      <td class="col-loc-vol">
        <input type="text" class="form-control" name="${prefix}[vol][${idx}]">
      </td>
      <td class="col-loc-sec">
        <input type="text" class="form-control" name="${prefix}[sec][${idx}]">
      </td>
      <td class="col-loc-pfl">
        <input type="text" class="form-control" name="${prefix}[debp][${idx}]">
      </td>
      <td class="col-loc-ff">
        <input type="text" class="form-control" name="${prefix}[debf][${idx}]" placeholder="a/b">
      </td>
      <td class="col-loc-pfl">
        <input type="text" class="form-control" name="${prefix}[debl][${idx}]">
      </td>
      <td class="col-del text-center">
        <button type="button" class="btn btn-sm btn-outline-danger p-0 px-1"
                onclick="deleteRow(this)"><i class="bi bi-x"></i></button>
      </td>`;
    tbody.appendChild(tr);
    tr.querySelector('input')?.focus();
}

/* ── Chevron toggle for new-entry card ── */
document.addEventListener('DOMContentLoaded', () => {
    const body = document.getElementById('new-entry-body');
    const chev = document.getElementById('new-entry-chevron');
    if (body && chev) {
        body.addEventListener('show.bs.collapse',   () => { chev.className = 'bi bi-chevron-up'; });
        body.addEventListener('hide.bs.collapse',   () => { chev.className = 'bi bi-chevron-down'; });
    }

    // Expand/collapse all existing entries
    document.getElementById('btn-expand-all')?.addEventListener('click', () =>
        document.querySelectorAll('.accordion-collapse').forEach(el =>
            bootstrap.Collapse.getOrCreateInstance(el).show()));
    document.getElementById('btn-collapse-all')?.addEventListener('click', () =>
        document.querySelectorAll('.accordion-collapse').forEach(el =>
            bootstrap.Collapse.getOrCreateInstance(el).hide()));

    const panels = document.querySelectorAll('.accordion-collapse');
    if (panels.length === 1) bootstrap.Collapse.getOrCreateInstance(panels[0]).show();

    // Store prefix on translator tables for JS fallback
    document.querySelectorAll('.mini-table[data-prefix]').forEach(tbl => {
        const first = tbl.querySelector('input[type="text"]');
        if (first && !tbl.dataset.prefix) {
            tbl.dataset.prefix = first.name.replace(/\[tr_name\]\[\d+\]$/, '');
        }
    });
});
</script>
<script>
/* ── Auto-convert shortcuts for [data-autoconv] fields ──────────────────────
   Keydown (single key):  D → ḍ   T → ṭ   N → ṇ
   Digraphs (on input):   sh → š   d* → ḍ   s* → ṭh
────────────────────────────────────────────────────────────────────────────── */
(function () {
    const KEYMAP = { 'D': 'ḍ', 'T': 'ṭ', 'N': 'ṇ', 'G': 'γ', 'C' : 'č', 'J' : 'ǰ', 'M' : 'ṃ', 'H' : 'ḥ' };

    // Digraph table: typed two-char sequence → replacement string
    const DIGRAPHS = [
        { seq: 'sh', rep: 'š'  },
        { seq: 'd*', rep: 'ḍ'  },
        { seq: 's*', rep: 'ṭh' },
        { seq: 'čh', rep: 'ch' },
        { seq: 'ny', rep: 'ñ' },
        { seq: 'zh', rep: 'ẓ' },
        { seq: 'd#', rep: 'ḓ' },
        { seq: 't#', rep: 'ṱ' },
    ];

    function replaceAtCursor(el, replacement) {
        const start = el.selectionStart;
        const end   = el.selectionEnd;
        el.value = el.value.slice(0, start) + replacement + el.value.slice(end);
        const pos = start + replacement.length;
        el.setSelectionRange(pos, pos);
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function wireAutoconv(el) {
        if (el.dataset.autoconvWired) return;
        el.dataset.autoconvWired = '1';

        el.addEventListener('keydown', function (e) {
            if (e.ctrlKey || e.altKey || e.metaKey) return;
            const sub = KEYMAP[e.key];
            if (sub) { e.preventDefault(); replaceAtCursor(this, sub); }
        });

        el.addEventListener('input', function () {
            const pos = this.selectionStart;
            if (pos < 2) return;
            const tail = this.value.slice(pos - 2, pos);
            for (const { seq, rep } of DIGRAPHS) {
                if (tail === seq) {
                    this.value = this.value.slice(0, pos - 2) + rep + this.value.slice(pos);
                    const np = pos - 2 + rep.length;
                    this.setSelectionRange(np, np);
                    return;   // only one digraph can match at a time
                }
            }
        });
    }

    function wireAll() {
        document.querySelectorAll('[data-autoconv]').forEach(wireAutoconv);
    }

    document.addEventListener('DOMContentLoaded', wireAll);
    document.addEventListener('show.bs.collapse', () => setTimeout(wireAll, 50));
})();
</script>
</body>
</html>