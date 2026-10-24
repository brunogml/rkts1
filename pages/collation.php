<?php
/**
 * Tibetan Manuscript Collation Tool
 * Configuration is driven by ?ref=F113 (or any col+number ref).
 * The witness coordinates (volume, begin, end) are looked up from
 * the col XML (e.g. F.xml) and D.xml, then fed into the collation engine.
 *
 * Usage:  collate.php?ref=F113[&page=1&fpp=1]
 *
 * Save corrections: POST action=save_corrections with confirmed_ops JSON array.
 * Result saved to data/{ref}.txt; existing files are updated in-place.
 * Login required to save; credentials stored in ../data/general/login.txt (user;pass;email).
 */

// ─── SESSION / AUTH ───────────────────────────────────────────────────────────

session_start();

/**
 * Load users from ../data/general/login.txt.
 * Each non-empty line: username;password;email
 * Returns array keyed by username → ['password'=>…,'email'=>…]
 */
function loadUsers(): array
{
    $path = '../data/general/login.txt';
    if (!file_exists($path)) return [];
    $users = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $parts = explode(';', $line, 3);
        if (count($parts) < 2) continue;
        // Strip surrounding whitespace and optional double-quotes from each field
        $strip = fn(string $s): string => trim(trim($s), '"');
        $users[$strip($parts[0])] = [
            'password' => $strip($parts[1]),
            'email'    => $strip($parts[2] ?? ''),
        ];
    }
    return $users;
}

// Handle logout
if (($_POST['action'] ?? '') === 'logout') {
    $_SESSION['collation_user'] = null;
    unset($_SESSION['collation_user']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')
           . '?ref=' . urlencode($_POST['ref'] ?? ''));
    exit;
}

// Handle login attempt
$loginError = '';
if (($_POST['action'] ?? '') === 'login') {
    $users    = loadUsers();
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($username !== '' && isset($users[$username])
        && $users[$username]['password'] === $password) {
        $_SESSION['collation_user'] = $username;
        // Redirect to GET to prevent re-POST on refresh
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } else {
        $loginError = 'Invalid username or password.';
    }
}

$loggedInUser = $_SESSION['collation_user'] ?? null;

// ─── COLLATION-ELIGIBLE COLLECTIONS ──────────────────────────────────────────
// Format: {genreCode}{Sigla}  — 1=Kanjur, 2=Tanjur, 3=Old Tantra, 5=Bon
// images.php reads this list (mirrored as a JS array) to decide whether
// to show an "Open in Collation" link in the info panel.
const COLLATION_COLLECTIONS = ['1F', '1Go', '1H', '1J', '1L', '1N', '1Ng', '1Q', '1S', '1Ty', '2G', '2N', '2Q'];

// ─── WITNESS LOOKUP ───────────────────────────────────────────────────────────

$ref = $_GET['ref'] ?? ($_POST['ref'] ?? '');
if ($ref === '') {
    die("<p style='font-family:sans-serif;color:red'>Missing <code>?ref=</code> parameter.</p>");
}

$col = substr($ref, 0, strcspn($ref, '0123456789'));

/**
 * Given a ref value (e.g. "F113"), look up volume and folio pagination
 * for both the col witness and D witness. Common match key is <rkts>.
 */
function lookupWitnesses(string $fRef, string $colXmlPath, string $dXmlPath): ?array
{
    $folio = fn(string $p, string $f, string $l): string => $p . $f . $l;

    $fXml = simplexml_load_file($colXmlPath);
    if ($fXml === false) {
        die("<p style='font-family:sans-serif;color:red'>Cannot load col XML: "
            . htmlspecialchars($colXmlPath) . "</p>");
    }

    $fItem = null;
    foreach ($fXml->item as $item) {
        if ((string)$item->ref === $fRef) { $fItem = $item; break; }
    }
    if ($fItem === null) return null;

    $rkts  = (string)$fItem->rkts;
    $fData = [
        'ref'   => (string)$fItem->ref,
        'rkts'  => $rkts,
        'vol'   => (string)$fItem->vol,
        'cat'   => (string)$fItem->cat,   // e.g. 'K', 'T', 'O', 'B'
        'begin' => $folio((string)$fItem->debp, (string)$fItem->debf, (string)$fItem->debl),
        'end'   => $folio((string)$fItem->finp, (string)$fItem->finf, (string)$fItem->finl),
        'title' => (string)$fItem->tib,
    ];

    $dXml = simplexml_load_file($dXmlPath);
    if ($dXml === false) {
        die("<p style='font-family:sans-serif;color:red'>Cannot load D.xml: "
            . htmlspecialchars($dXmlPath) . "</p>");
    }

    $dItem = null;
    foreach ($dXml->item as $item) {
        if ((string)$item->rkts === $rkts) { $dItem = $item; break; }
    }

    $dData = null;
    if ($dItem !== null) {
        $dData = [
            'ref'   => (string)$dItem->ref,
            'rkts'  => (string)$dItem->rkts,
            'vol'   => (string)$dItem->vol,
            'begin' => $folio((string)$dItem->debp, (string)$dItem->debf, (string)$dItem->debl),
            'end'   => $folio((string)$dItem->finp, (string)$dItem->finf, (string)$dItem->finl),
            'title' => (string)$dItem->tib,
        ];
    }

    return ['F' => $fData, 'D' => $dData];
}

$resultw = lookupWitnesses(
    fRef:       $ref,
    colXmlPath: '../data/collections/' . $col . '/' . $col . '.xml',
    dXmlPath:   '../data/collections/D/D.xml'
);

if ($resultw === null) {
    die("<p style='font-family:sans-serif;color:red'>Ref <strong>"
        . htmlspecialchars($ref) . "</strong> not found in " . htmlspecialchars($col) . ".xml</p>");
}
if ($resultw['D'] === null) {
    die("<p style='font-family:sans-serif;color:red'>No matching D witness found for rkts "
        . htmlspecialchars($resultw['F']['rkts']) . ".</p>");
}

$f = $resultw['F'];
$d = $resultw['D'];

// ─── VOLUME LOOKUP FROM collections.json ─────────────────────────────────────

/**
 * Find a volume number (plain integer, no zero-padding) by sigla + volref.
 * Returns the integer volno (e.g. 13), or null if not found.
 */
function lookupVolno(string $sigla, string $volref, array $collections): ?int
{
    foreach ($collections as $entry) {
        if ($entry['sigla'] !== $sigla) continue;
        foreach ($entry['constituents'] as $constituent) {
            foreach ($constituent['structure'] as $vol) {
                if ($vol['volref'] === $volref) return (int)$vol['volno'];
            }
        }
    }
    return null;
}

/**
 * Find the constituent title (e.g. "MW1KG13607") for a sigla + volref.
 * This is the folder name used for image JSON and manifest.txt, matching
 * the `title` URL parameter in images.php.
 */
function lookupConstituentTitle(string $sigla, string $volref, array $collections): ?string
{
    foreach ($collections as $entry) {
        if ($entry['sigla'] !== $sigla) continue;
        foreach ($entry['constituents'] as $constituent) {
            foreach ($constituent['structure'] as $vol) {
                if ($vol['volref'] === $volref) return $constituent['title'];
            }
        }
    }
    return null;
}

$collectionsData = json_decode(
    file_get_contents('../data/general/collections.json'), true
);
if ($collectionsData === null) {
    die("<p style='font-family:sans-serif;color:red'>Cannot load ../data/general/collections.json</p>");
}

$volfno = lookupVolno($col, $f['vol'], $collectionsData);
$voldno = lookupVolno('D',  $d['vol'], $collectionsData);

if ($volfno === null) {
    die("<p style='font-family:sans-serif;color:red'>Cannot resolve volume number for "
        . htmlspecialchars($col) . " volref " . htmlspecialchars($f['vol']) . "</p>");
}
if ($voldno === null) {
    die("<p style='font-family:sans-serif;color:red'>Cannot resolve volume number for "
        . "D volref " . htmlspecialchars($d['vol']) . "</p>");
}

// Numeric category prefix for etext filenames (from <cat> in the XML)
// K=1 (Kanjur), T=2 (Tanjur), O=3 (Old Tantra), B=5 (Bon canon)
$catNum = match($f['cat']) {
    'K' => 1,
    'T' => 2,
    'O' => 3,
    'B' => 5,
    default => 1,
};

// Constituent title for image JSON/manifest path (e.g. "MW1KG13607")
// Mirrors the `title` URL parameter used in images.php.
$constituentTitle = lookupConstituentTitle($col, $f['vol'], $collectionsData);
if ($constituentTitle === null) {
    die("<p style='font-family:sans-serif;color:red'>Cannot resolve constituent title for sigla "
        . htmlspecialchars($col) . " volref " . htmlspecialchars($f['vol']) . " in collections.json</p>");
}

// Path to the per-volume folio-image JSON
// ../data/collections/{Sigla}/{constituentTitle}/{volref}.json
$chemjson = '../data/collections/' . $col . '/' . $constituentTitle . '/' . $f['vol'] . '.json';

// ─── CONFIGURATION ────────────────────────────────────────────────────────────

// Use the saved corrected file from collation/ if it exists, otherwise the repository original
$dataFile1    = '../collation/' . $ref . '.txt';
$repoFile1    = '../data/etexts/' . $catNum . $col . '/' . str_pad($volfno, 3, '0', STR_PAD_LEFT) . '.txt';
$file1Default = file_exists($dataFile1) ? $dataFile1 : $repoFile1;
$file1IsData  = ($file1Default === $dataFile1);

$config = [
    'ref'              => $ref,
    'file1'            => $_GET['file1']  ?? $file1Default,
    'file1_is_data'    => $file1IsData,
    'start1'           => $_GET['start1'] ?? $f['begin'],
    'end1'             => $_GET['end1']   ?? $f['end'],
    'file2'            => $_GET['file2']  ?? ('../data/etexts/' . $catNum . 'D/' . str_pad($voldno, 3, '0', STR_PAD_LEFT) . '.txt'),
    'start2'           => $_GET['start2'] ?? $d['begin'],
    'end2'             => $_GET['end2']   ?? $d['end'],
    'page'             => max(1, intval($_GET['page'] ?? $_POST['page_num'] ?? 1)),
    'folios_per_page'  => max(1, intval($_GET['fpp']  ?? 1)),
    'label1'           => $f['ref'] . ' [' . $f['vol'] . ']',
    'label2'           => $d['ref'] . ' [' . $d['vol'] . ']',
    'title'            => $f['title'],
    'rkts'             => $f['rkts'],
];

// ─── FILE PARSING ─────────────────────────────────────────────────────────────

function folioToInt(string $ref): int {
    if (!preg_match('/^(\d+)(\'?)([ab])(\d+)$/', $ref, $m)) return -1;
    $prime = $m[2] === "'" ? 6000 : 0;          // 191'a → …+6000, 191'b → …+7000
    return (int)$m[1] * 10000 + $prime + ($m[3] === 'b' ? 1000 : 0) + (int)$m[4];
}

function parseLine(string $raw): ?array {
    $raw = rtrim($raw, "\r\n");
    if (!preg_match('/^(\d+)(\'?)([ab])(\d+)\(([^)]+)\):\s*(.*)$/', $raw, $m)) return null;
    return [
        'folio_line' => $m[1].$m[2].$m[3].$m[4],   // e.g. 191'a3
        'folio'      => $m[1].$m[2].$m[3],          // e.g. 191'a
        'number'     => (int)$m[1],
        'side'       => $m[3],
        'line'       => (int)$m[4],
        'section'    => $m[5],
        'text'       => $m[6],
    ];
}

function extractRange(string $filepath, string $startRef, string $endRef): array {
    if (!file_exists($filepath)) {
        die("<p style='font-family:sans-serif;color:red'>File not found: "
            . htmlspecialchars($filepath) . "</p>");
    }
    $startInt = folioToInt($startRef);
    $endInt   = folioToInt($endRef);
    $lines    = file($filepath);
    $result   = [];
    $inside   = false;

    foreach ($lines as $raw) {
        $parsed = parseLine($raw);
        if (!$parsed) continue;
        $val = folioToInt($parsed['folio_line']);
        if ($val < 0) continue;
        if (!$inside && $val >= $startInt) $inside = true;
        if ($inside) {
            $result[] = $parsed;
            if ($val >= $endInt) break;
        }
    }
    return $result;
}

function tokenize(array $lines): array {
    $tokens = [];
    foreach ($lines as $idx => $line) {
        $words = preg_split('/\s+/', trim($line['text']), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $w) {
            if (preg_match('/^[\/\\\\@#!]+$/', $w)) continue;
            $tokens[] = ['word' => $w, 'line_idx' => $idx];
        }
    }
    return $tokens;
}

// ─── DIFF ENGINE ──────────────────────────────────────────────────────────────

function normalizeWord(string $w): string {
    return trim(strtolower($w), '/\\@#!');
}

function wordDiff(array $t1, array $t2): array {
    $n1 = count($t1);
    $n2 = count($t2);
    $w1 = array_map(fn($t) => normalizeWord($t['word']), $t1);
    $w2 = array_map(fn($t) => normalizeWord($t['word']), $t2);

    $BAND = 350;
    $W    = 2 * $BAND + 1;
    $prev = array_fill(0, $W, 0);
    $choice = [0 => str_repeat("\x00", $W)];

    for ($i = 1; $i <= $n1; $i++) {
        $curr = array_fill(0, $W, 0);
        $ch   = str_repeat("\x00", $W);
        $jMin = max(1, $i - $BAND);
        $jMax = min($n2, $i + $BAND);

        for ($j = $jMin; $j <= $jMax; $j++) {
            $off     = $j - $i + $BAND;
            $offPrev = $j - ($i - 1) + $BAND;
            $leftOff = $off - 1;

            $vLeft = ($leftOff >= 0 && ($j-1) >= max(1, $i - $BAND) - 1)
                     ? $curr[$leftOff] : -1;
            $vUp   = ($offPrev >= 0 && $offPrev < $W) ? $prev[$offPrev] : -1;
            $diagOff = $j - 1 - ($i - 1) + $BAND;
            $vDiag = ($diagOff >= 0 && $diagOff < $W) ? $prev[$diagOff] : -1;

            if ($w1[$i-1] === $w2[$j-1] && $vDiag !== -1) {
                $curr[$off] = $vDiag + 1;
                $ch[$off]   = "\x00";
            } else {
                $best = -1; $dir = "\x01";
                if ($vUp    !== -1 && $vUp    > $best) { $best = $vUp;   $dir = "\x01"; }
                if ($vLeft  !== -1 && $vLeft  > $best) { $best = $vLeft; $dir = "\x02"; }
                if ($vDiag  !== -1 && $vDiag  > $best) { $best = $vDiag; $dir = "\x00"; }
                $curr[$off] = max(0, $best);
                $ch[$off]   = $dir;
            }
        }
        $choice[$i] = $ch;
        $prev = $curr;
    }

    $raw_ops = [];
    $i = $n1; $j = $n2;
    while ($i > 0 || $j > 0) {
        $off = $j - $i + $BAND;
        if ($i <= 0) { array_unshift($raw_ops, ['del', null, $j-1]); $j--; continue; }
        if ($j <= 0) { array_unshift($raw_ops, ['ins', $i-1, null]); $i--; continue; }
        if ($off < 0 || $off >= $W || !isset($choice[$i])) {
            array_unshift($raw_ops, ['ins', $i-1, null]); $i--; continue;
        }
        $dir = ord($choice[$i][$off]);
        if ($dir === 0) {
            if ($w1[$i-1] === $w2[$j-1]) {
                array_unshift($raw_ops, ['eq', $i-1, $j-1]);
            } else {
                array_unshift($raw_ops, ['ins', $i-1, null]); $i--; continue;
            }
            $i--; $j--;
        } elseif ($dir === 1) {
            array_unshift($raw_ops, ['ins', $i-1, null]); $i--;
        } else {
            array_unshift($raw_ops, ['del', null, $j-1]); $j--;
        }
    }

    $ops = [];
    $k   = 0;
    $cnt = count($raw_ops);

    while ($k < $cnt) {
        $op = $raw_ops[$k];

        if ($op[0] === 'eq') {
            $i1 = $op[1];
            $ops[] = [
                'op'       => 'eq',
                't1_word'  => $i1 !== null ? $t1[$i1]['word'] : '',
                't1_idx'   => $i1 !== null ? $t1[$i1]['line_idx'] : null,
                't2_words' => $op[2] !== null ? [$t2[$op[2]]['word']] : [],
                't2_idxs'  => $op[2] !== null ? [$t2[$op[2]]['line_idx']] : [],
            ];
            $k++;
            continue;
        }

        $ins_run = []; $del_run = [];
        while ($k < $cnt && in_array($raw_ops[$k][0], ['ins','del'])) {
            if ($raw_ops[$k][0] === 'ins') $ins_run[] = $raw_ops[$k][1];
            else                           $del_run[] = $raw_ops[$k][2];
            $k++;
        }

        if (empty($ins_run)) {
            foreach ($del_run as $j2) {
                $ops[] = ['op'=>'del','t1_word'=>null,'t1_idx'=>null,
                          't2_words'=>[$t2[$j2]['word']],'t2_idxs'=>[$t2[$j2]['line_idx']]];
            }
        } elseif (empty($del_run)) {
            foreach ($ins_run as $i1) {
                $ops[] = ['op'=>'ins','t1_word'=>$t1[$i1]['word'],'t1_idx'=>$t1[$i1]['line_idx'],
                          't2_words'=>[],'t2_idxs'=>[]];
            }
        } else {
            $i_ptr = 0; $j_ptr = 0;
            $n_ins = count($ins_run); $n_del = count($del_run);
            while ($i_ptr < $n_ins || $j_ptr < $n_del) {
                if ($i_ptr < $n_ins && $j_ptr < $n_del) {
                    $t1w = normalizeWord($t1[$ins_run[$i_ptr]]['word']);
                    $merged = false;
                    for ($look = 2; $look <= min(5, $n_del - $j_ptr); $look++) {
                        $concat = ''; $cw = []; $ci = [];
                        for ($jj = $j_ptr; $jj < $j_ptr + $look; $jj++) {
                            $concat .= normalizeWord($t2[$del_run[$jj]]['word']);
                            $cw[] = $t2[$del_run[$jj]]['word'];
                            $ci[] = $t2[$del_run[$jj]]['line_idx'];
                        }
                        if ($t1w === $concat || str_contains($t1w, substr($concat, 0, 4))) {
                            $ops[] = ['op'=>'merge','t1_word'=>$t1[$ins_run[$i_ptr]]['word'],
                                      't1_idx'=>$t1[$ins_run[$i_ptr]]['line_idx'],
                                      't2_words'=>$cw,'t2_idxs'=>$ci];
                            $i_ptr++; $j_ptr += $look; $merged = true; break;
                        }
                    }
                    if (!$merged) {
                        $ops[] = ['op'=>'sub','t1_word'=>$t1[$ins_run[$i_ptr]]['word'],
                                  't1_idx'=>$t1[$ins_run[$i_ptr]]['line_idx'],
                                  't2_words'=>[$t2[$del_run[$j_ptr]]['word']],
                                  't2_idxs'=>[$t2[$del_run[$j_ptr]]['line_idx']]];
                        $i_ptr++; $j_ptr++;
                    }
                } elseif ($i_ptr < $n_ins) {
                    $ops[] = ['op'=>'ins','t1_word'=>$t1[$ins_run[$i_ptr]]['word'],
                              't1_idx'=>$t1[$ins_run[$i_ptr]]['line_idx'],
                              't2_words'=>[],'t2_idxs'=>[]];
                    $i_ptr++;
                } else {
                    $ops[] = ['op'=>'del','t1_word'=>null,'t1_idx'=>null,
                              't2_words'=>[$t2[$del_run[$j_ptr]]['word']],
                              't2_idxs'=>[$t2[$del_run[$j_ptr]]['line_idx']]];
                    $j_ptr++;
                }
            }
        }
    }
    return $ops;
}

// ─── RENDERING HELPERS ────────────────────────────────────────────────────────

function groupOpsByLine(array $ops): array {
    $byLine = [];
    foreach ($ops as $op) {
        if ($op['t1_idx'] !== null) {
            $byLine[$op['t1_idx']][] = $op;
        } elseif ($op['op'] === 'del') {
            $lastLine = empty($byLine) ? 0 : array_key_last($byLine);
            $byLine[$lastLine][] = $op;
        }
    }
    return $byLine;
}

function groupByFolio(array $lines): array {
    $folios = [];
    foreach ($lines as $idx => $line) $folios[$line['folio']][$idx] = $line;
    return $folios;
}

function renderWord(array $op): string {
    $w   = htmlspecialchars($op['t1_word'] ?? '');
    $idx = (int)$op['idx'];
    // Embed t2 words as JSON attribute so JS can swap text on confirmation
    $t2j = htmlspecialchars(json_encode($op['t2_words'] ?? []), ENT_QUOTES);

    switch ($op['op']) {
        case 'eq':
            return "<span class=\"eq eq-word\" data-op-idx=\"$idx\" data-op=\"eq\" data-t1=\"$w\" title=\"double-click to edit\">$w</span> ";
        case 'sub':
            $alt = htmlspecialchars(implode(' ', $op['t2_words']));
            return "<span class=\"sub diff-op\" data-op-idx=\"$idx\" data-op=\"sub\" data-t1=\"$w\" data-t2=\"$t2j\" title=\"D: $alt — click to confirm, double-click to edit\">$w</span> ";
        case 'ins':
            return "<span class=\"ins diff-op\" data-op-idx=\"$idx\" data-op=\"ins\" data-t1=\"$w\" data-t2=\"$t2j\" title=\"Not in D — click to confirm removal, double-click to edit\">$w</span> ";
        case 'merge':
            $alt = htmlspecialchars(implode(' ', $op['t2_words']));
            return "<span class=\"merge diff-op\" data-op-idx=\"$idx\" data-op=\"merge\" data-t1=\"$w\" data-t2=\"$t2j\" title=\"D reads: $alt — click to confirm, double-click to edit\">$w</span> ";
        case 'del':
            $missing = htmlspecialchars(implode(' ', $op['t2_words']));
            return "<span class=\"del diff-op\" data-op-idx=\"$idx\" data-op=\"del\" data-t1=\"\" data-t2=\"$t2j\" title=\"Missing from F: $missing — click to confirm insertion, double-click to edit\">[$missing]</span> ";
        default:
            return "<span>$w</span> ";
    }
}

/**
 * Split a plain (no-op) line into individually editable word spans.
 * Key format: l{lineIdx}w{wordPos}
 */
function renderPlainLine(string $text, int $li): string {
    $words = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out   = '';
    $pos   = 0;
    foreach ($words as $tok) {
        if (preg_match('/^\s+$/', $tok)) { $out .= ' '; continue; }
        if (preg_match('/^[\/\\\\@#!]+$/', $tok)) {
            $out .= '<span class="slash-tok">' . htmlspecialchars($tok) . '</span> ';
            continue;
        }
        $w    = htmlspecialchars($tok);
        $key  = 'l' . $li . 'w' . $pos;
        $out .= "<span class=\"plain-word\" data-plain-key=\"$key\" data-orig=\"$w\" title=\"double-click to edit\">$w</span> ";
        $pos++;
    }
    return $out;
}

/**
 * Render a line that has diff ops, but re-insert slash tokens from the
 * original text so they appear in the display even though tokenize() skips them.
 * Non-slash words are consumed from $ops in order.
 */
function renderLineWithOps(string $text, array $ops): string {
    $tokens  = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    $out     = '';
    $opQueue = $ops;   // copy to consume

    foreach ($tokens as $tok) {
        if (preg_match('/^[\/\\\\@#!]+$/', $tok)) {
            // Slash-only token: display as-is, not part of diff
            $out .= '<span class="slash-tok">' . htmlspecialchars($tok) . '</span> ';
        } else {
            // Real word: consume the next op (they are in the same order)
            $op = array_shift($opQueue);
            if ($op !== null) {
                $out .= renderWord($op);
            } else {
                // Safety fallback: no op left, show plain
                $out .= '<span class="eq">' . htmlspecialchars($tok) . '</span> ';
            }
        }
    }
    // Any remaining ops (e.g. trailing 'del' ops with no t1 word) — append them
    foreach ($opQueue as $op) {
        $out .= renderWord($op);
    }
    return $out;
}

function getT2LinesForT1Range(array $t1LineIdxs, array $t1LineToT2Lines): array {
    $t2set = [];
    foreach ($t1LineIdxs as $t1li) {
        foreach ($t1LineToT2Lines[$t1li] ?? [] as $t2li) $t2set[$t2li] = true;
    }
    ksort($t2set);
    return array_keys($t2set);
}

function buildUrl(array $config, array $override = []): string {
    $params = array_merge([
        'ref'  => $config['ref'],
        'fpp'  => $config['folios_per_page'],
    ], $override);
    return '?' . http_build_query($params);
}

// ─── MAIN COMPUTATION ─────────────────────────────────────────────────────────

$lines1  = extractRange($config['file1'], $config['start1'], $config['end1']);
$lines2  = extractRange($config['file2'], $config['start2'], $config['end2']);
$tokens1 = tokenize($lines1);
$tokens2 = tokenize($lines2);
$ops     = wordDiff($tokens1, $tokens2);

// Assign a stable global index to every op
foreach ($ops as $idx => &$op) { $op['idx'] = $idx; }
unset($op);

$byLine1 = groupOpsByLine($ops);
$folios1 = groupByFolio($lines1);

$folioKeys   = array_keys($folios1);
$totalFolios = count($folioKeys);
$fpp         = $config['folios_per_page'];
$totalPages  = max(1, (int)ceil($totalFolios / $fpp));
$currentPage = min($config['page'], $totalPages);
$pageFolios  = array_slice($folioKeys, ($currentPage - 1) * $fpp, $fpp);

// Build t2 line HTML map
$lineHtmlMap = array_fill(0, count($lines2), '');
foreach ($ops as $op) {
    switch ($op['op']) {
        case 'eq':
            foreach ($op['t2_idxs'] as $k => $li) {
                $w = htmlspecialchars($op['t2_words'][$k]);
                $lineHtmlMap[$li] .= "<span class=\"eq\">$w</span> ";
            }
            break;
        case 'sub':
            foreach ($op['t2_idxs'] as $k => $li) {
                $w   = htmlspecialchars($op['t2_words'][$k]);
                $alt = htmlspecialchars($op['t1_word'] ?? '');
                $lineHtmlMap[$li] .= "<span class=\"sub2\" title=\"F: $alt\">$w</span> ";
            }
            break;
        case 'merge':
            foreach ($op['t2_idxs'] as $k => $li) {
                $w   = htmlspecialchars($op['t2_words'][$k]);
                $alt = htmlspecialchars($op['t1_word'] ?? '');
                $lineHtmlMap[$li] .= "<span class=\"merge2\" title=\"Merged in F as: $alt\">$w</span> ";
            }
            break;
        case 'del':
            foreach ($op['t2_idxs'] as $k => $li) {
                $w = htmlspecialchars($op['t2_words'][$k]);
                $lineHtmlMap[$li] .= "<span class=\"del2\" title=\"Missing from F\">$w</span> ";
            }
            break;
    }
}

// Build t1 line → t2 line mapping
$t1LineToT2Lines = [];
$lastT1Idx = 0;
foreach ($ops as $op) {
    if ($op['t1_idx'] !== null) $lastT1Idx = $op['t1_idx'];
    foreach ($op['t2_idxs'] as $t2li) {
        $t1LineToT2Lines[$lastT1Idx][$t2li] = true;
    }
}
foreach ($t1LineToT2Lines as $t1li => $set) {
    ksort($set);
    $t1LineToT2Lines[$t1li] = array_keys($set);
}

// Stats
$n_eq = $n_sub = $n_ins = $n_del = $n_merge = 0;
foreach ($ops as $op) {
    match($op['op']) {
        'eq'    => $n_eq++,
        'sub'   => $n_sub++,
        'ins'   => $n_ins++,
        'del'   => $n_del++,
        'merge' => $n_merge++,
        default => null,
    };
}
$total = max(1, $n_eq + $n_sub + $n_ins + $n_del + $n_merge);
$pct   = fn($n) => number_format($n / $total * 100, 1);

// ─── SAVE-CORRECTIONS POST HANDLER ───────────────────────────────────────────
// Must come AFTER all computation so $ops / $lines1 / $pageFolios are available.

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_corrections') {

    // Reject unauthenticated save attempts
    if (!$loggedInUser) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
        exit;
    }

    $confirmed       = json_decode($_POST['confirmed_ops']    ?? '[]', true);
    $manualOverrides = json_decode($_POST['manual_overrides'] ?? '{}', true);
    $plainOverrides  = json_decode($_POST['plain_overrides']  ?? '{}', true);
    if (!is_array($confirmed))       $confirmed       = [];
    if (!is_array($manualOverrides)) $manualOverrides = [];
    if (!is_array($plainOverrides))  $plainOverrides  = [];

    $confirmedSet = array_flip($confirmed);   // op_idx → true

    $pageNum = intval($_POST['page_num'] ?? $currentPage);

    // Collect which line indices belong to the current page
    $pageLineIdxs = [];
    foreach ($pageFolios as $folio) {
        foreach ($folios1[$folio] as $li => $line) {
            $pageLineIdxs[$li] = true;
        }
    }

    /**
     * Reconstruct corrected text for a single line.
     * Priority: manualOverrides > confirmedSet > keep original F text.
     * For eq ops, manualOverrides by op idx overrides the unchanged word.
     */
    $reconstructLine = function(int $li, array $lineOps)
        use ($confirmedSet, $manualOverrides): string
    {
        if (empty($lineOps)) return '';
        $words = [];
        foreach ($lineOps as $op) {
            $idx         = (string)$op['idx'];
            $isManual    = array_key_exists($idx, $manualOverrides);
            $isConfirmed = isset($confirmedSet[$op['idx']]);

            if ($isManual) {
                $customText = trim($manualOverrides[$idx]);
                if ($customText !== '') $words[] = $customText;
                continue;
            }

            switch ($op['op']) {
                case 'eq':
                    if ($op['t1_word'] !== null) $words[] = $op['t1_word'];
                    break;
                case 'sub':
                    $words[] = $isConfirmed
                        ? implode(' ', $op['t2_words'])
                        : $op['t1_word'];
                    break;
                case 'ins':
                    if (!$isConfirmed) $words[] = $op['t1_word'];
                    break;
                case 'del':
                    if ($isConfirmed) {
                        foreach ($op['t2_words'] as $tw) $words[] = $tw;
                    }
                    break;
                case 'merge':
                    if ($isConfirmed) {
                        foreach ($op['t2_words'] as $tw) $words[] = $tw;
                    } else {
                        $words[] = $op['t1_word'];
                    }
                    break;
            }
        }
        return implode(' ', array_filter($words, fn($w) => $w !== null && $w !== ''));
    };

    /**
     * Reconstruct a plain (no-op) line applying any per-word plain overrides.
     * Keys are "l{li}w{wordPos}" matching what renderPlainLine() emits.
     */
    $reconstructPlainLine = function(int $li, string $originalText)
        use ($plainOverrides): string
    {
        $tokens = preg_split('/(\s+)/', $originalText, -1, PREG_SPLIT_DELIM_CAPTURE);
        $words  = [];
        $pos    = 0;
        foreach ($tokens as $tok) {
            if (preg_match('/^\s+$/', $tok)) continue;
            $key     = 'l' . $li . 'w' . $pos;
            $words[] = array_key_exists($key, $plainOverrides)
                       ? trim($plainOverrides[$key])
                       : $tok;
            $pos++;
        }
        return implode(' ', array_filter($words, fn($w) => $w !== ''));
    };

    // Build corrected output lines for the ENTIRE text range.
    // Lines on the current page get corrections applied; others keep original text.
    $outputLines = [];
    foreach ($lines1 as $li => $line) {
        if (isset($pageLineIdxs[$li])) {
            $lineOps = $byLine1[$li] ?? [];
            $text    = empty($lineOps)
                       ? $reconstructPlainLine($li, $line['text'])
                       : $reconstructLine($li, $lineOps);
        } else {
            $text = $line['text'];
        }
        $outputLines[$li] = [
            'folio_line' => $line['folio_line'],
            'section'    => $line['section'],
            'text'       => $text,
            'raw'        => $line['folio_line'] . '(' . $line['section'] . '): ' . $text,
        ];
    }

    $saveDir  = '../collation';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
    $savePath = $saveDir . '/' . $config['ref'] . '.txt';
    $logEntry = '# LOG: ' . date('Y-m-d H:i:s')
                . ' | user=' . $loggedInUser
                . ' | ref=' . $config['ref']
                . ' | page=' . $pageNum
                . ' | confirmed=' . count($confirmed)
                . ' | manual=' . count($manualOverrides)
                . ' | plain=' . count($plainOverrides)
                . ' | folios=' . implode(',', $pageFolios);

    if (file_exists($savePath)) {
        // ── UPDATE existing file ──────────────────────────────────────────
        // Split into data lines and existing log lines.
        $existing = file($savePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $dataLines = [];
        $logLines  = [];
        foreach ($existing as $el) {
            if (str_starts_with(ltrim($el), '# LOG:')) $logLines[] = $el;
            else                                        $dataLines[] = $el;
        }

        // Index existing data lines by folio_line token
        $existingIdx = [];
        foreach ($dataLines as $eli => $el) {
            if (preg_match('/^(\d+\'?[ab]\d+)\(/', $el, $m)) {
                $existingIdx[$m[1]] = $eli;
            }
        }

        // Overwrite only the page lines; append any that don't exist yet
        foreach ($outputLines as $li => $ln) {
            if (!isset($pageLineIdxs[$li])) continue;   // not on this page → skip
            $fl = $ln['folio_line'];
            if (isset($existingIdx[$fl])) {
                $dataLines[$existingIdx[$fl]] = $ln['raw'];
            } else {
                // Insert in correct folio order at end of data section
                $dataLines[] = $ln['raw'];
            }
        }

        $logLines[] = $logEntry;
        $content    = implode("\n", $dataLines) . "\n" . implode("\n", $logLines) . "\n";

    } else {
        // ── CREATE new file with all lines ────────────────────────────────
        $content = implode("\n", array_column($outputLines, 'raw'))
                   . "\n" . $logEntry . "\n";
    }

    if (file_put_contents($savePath, $content) === false) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Cannot write to ' . $savePath]);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'status'     => 'ok',
        'saved'      => $savePath,
        'lines'      => count($outputLines),
        'page_lines' => count($pageLineIdxs),
        'confirmed'  => count($confirmed),
        'manual'     => count($manualOverrides),
        'plain'      => count($plainOverrides),
        'log'        => $logEntry,
    ]);
    exit;
}

// ─── HTML OUTPUT ─────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Correction Tool – <?= htmlspecialchars($config['ref']) ?> (rkts <?= htmlspecialchars($config['rkts']) ?>)</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Noto Sans', 'Segoe UI', sans-serif; font-size: 14px;
         background: #f4f3f0; color: #222; line-height: 1.7; }
  h1   { font-size: 1.05rem; font-weight: 600; }

  #header { background: #08088a; color: #ecf0f1; padding: 10px 0;
             display: flex; align-items: baseline; gap: 20px; flex-wrap: wrap; }
  #header h1 { color: #fff; }
  #header .meta { font-size: .78rem; color: #aab; }
  #header .title-tib { font-size: .82rem; color: #cde; font-style: italic; }

  #legend { background: #fff; border-bottom: 1px solid #ddd;
            padding: 7px 0; display: flex; gap: 14px; flex-wrap: wrap;
            font-size: .78rem; align-items: center; }
  .leg { display: flex; align-items: center; gap: 5px; }
  .leg-dot { display: inline-block; width: 12px; height: 12px; border-radius: 2px; }
  .leg-sep { color: #ccc; }

  #main { padding: 14px 0; display: grid; gap: 10px;
          padding-bottom: 80px; }

  #stats { background: #fff; border: 1px solid #ddd; border-radius: 6px;
           padding: 9px 14px; font-size: .78rem; color: #555;
           display: flex; gap: 20px; flex-wrap: wrap; }
  #stats strong { color: #222; }

  .pager { display: flex; align-items: center; gap: 5px; font-size: .8rem; flex-wrap: wrap; }
  .pager span { color: #555; }
  .page-link { display: inline-block; padding: 3px 9px; border: 1px solid #ccc;
               border-radius: 3px; color: #2c3e50; text-decoration: none; font-size: .78rem; }
  .page-link:hover { background: #e8f0f7; }
  .page-link.active { background: #2c3e50; color: #fff; border-color: #2c3e50; }

  :root { --t1-pct: 50; }

  .col-headers { display: flex;
                 background: #2c3e50; border-radius: 6px 6px 0 0; overflow: hidden; }
  .col-head { padding: 7px 14px; font-size: .8rem; font-weight: 700;
              color: #fff; display: flex; align-items: center; gap: 8px; overflow: hidden; }
  .col-head-1 { width: calc(var(--t1-pct) * 1%); flex-shrink: 0; }
  .col-head-2 { flex: 1; background: #34495e; border-left: 1px solid #4a6278; }
  .range-badge { background: rgba(255,255,255,.15); border-radius: 3px;
                 padding: 1px 6px; font-size: .7rem; font-weight: 400; font-family: monospace; }

  /* ── Resize handle ── */
  .resize-handle {
    width: 6px; flex-shrink: 0; cursor: col-resize;
    background: #4a6278;
    transition: background .15s;
    position: relative; z-index: 10;
    user-select: none;
  }
  .resize-handle:hover, .resize-handle.dragging { background: #5dade2; }
  .resize-handle::after {
    content: '';
    position: absolute; top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    width: 2px; height: 32px; border-radius: 2px;
    background: rgba(255,255,255,.35);
  }
  .col-headers .resize-handle { background: #3d5166; }
  .col-headers .resize-handle:hover,
  .col-headers .resize-handle.dragging { background: #5dade2; }

  .folio-block { background: #fff; border: 1px solid #ddd; border-radius: 6px;
                 overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
  .folio-header { background: #ecf0f1; padding: 5px 14px; font-size: .76rem; font-weight: 600;
                  color: #2c3e50; border-bottom: 1px solid #ddd;
                  display: flex; justify-content: space-between; align-items: center; }
  .side-label { background: #2c3e50; color: #fff; border-radius: 3px;
                padding: 1px 7px; font-size: .71rem; font-family: monospace; }
  .side-word  { color: #666; font-weight: 400; }
  .t2-folio-label { font-size: .7rem; color: #546e7a; background: #dce8ee;
                    border-radius: 3px; padding: 1px 7px; font-family: monospace; }

  .panel-row { display: flex; }
  .panel { padding: 7px 11px; }
  .panel-t1 { width: calc(var(--t1-pct) * 1%); flex-shrink: 0; overflow: hidden; }
  .panel-t2 { flex: 1; background: #fafbfc; overflow: hidden; }

  .ms-line { display: flex; gap: 8px; padding: 2px 0;
             border-bottom: 1px dotted #eee; font-family: 'Noto Sans', monospace; font-size: .86rem; }
  .ms-line:last-child { border-bottom: none; }
  .line-no   { flex-shrink: 0; font-size: .68rem; color: #b0b8c0; min-width: 18px;
               padding-top: 3px; font-family: monospace; text-align: right; }
  .line-text { flex: 1; word-break: normal; overflow-wrap: break-word; }

  .t2-folio-divider { margin: 4px 0 2px; }
  .t2-folio-tag { font-size: .67rem; font-weight: 700; font-family: monospace;
                  color: #546e7a; background: #e8f0f4; border-radius: 3px;
                  padding: 0 6px; display: inline-block; }
  .no-match { font-size: .76rem; color: #bbb; padding: 8px 0; font-style: italic; }

  /* ── Diff spans ── */
  span.eq    { color: #222; }
  span.sub   { background: #fff3cd; color: #856404; border-bottom: 2px solid #ffc107;
               border-radius: 2px; padding: 0 1px; cursor: pointer; }
  span.ins   { background: #f8d7da; color: #721c24; border-bottom: 2px solid #dc3545;
               border-radius: 2px; padding: 0 1px; cursor: pointer; }
  span.merge { background: #cfe2ff; color: #084298; border-bottom: 2px dashed #0d6efd;
               border-radius: 2px; padding: 0 1px; cursor: pointer; }
  span.del   { background: #d4edda; color: #155724; font-style: italic;
               border: 1px dashed #28a745; border-radius: 2px;
               padding: 0 2px; cursor: pointer; font-size: .82em; }
  span.sub2  { background: #fff3cd; color: #856404; border-bottom: 2px solid #ffc107;
               border-radius: 2px; padding: 0 1px; cursor: help; }
  span.del2  { background: #d4edda; color: #155724; border-bottom: 2px solid #28a745;
               border-radius: 2px; padding: 0 1px; cursor: help; }
  span.merge2 { background: #cfe2ff; color: #084298; border-bottom: 2px dashed #0d6efd;
                border-radius: 2px; padding: 0 1px; cursor: help; }

  /* ── Slash / punctuation tokens (displayed but excluded from diff) ── */
  span.slash-tok {
    color: #94a3b8;
    font-size: .82em;
    padding: 0 1px;
    user-select: none;
  }

  /* ── Editable plain/eq words ── */
  span.eq-word, span.plain-word {
    cursor: text;
    border-radius: 2px;
  }
  span.eq-word:hover, span.plain-word:hover {
    background: #f0f4ff;
    outline: 1px dashed #93c5fd;
    outline-offset: 1px;
  }
  span.plain-manual {
    background: #ede9fe; color: #4c1d95;
    border-bottom: 2px solid #7c3aed;
    border-radius: 2px; padding: 0 2px; cursor: text;
  }
  span.plain-manual::after {
    content: ' ✎';
    font-size: .6em; vertical-align: super;
    color: #7c3aed; font-weight: 700;
  }
  span.diff-op { transition: opacity .12s; }
  span.diff-op:hover { opacity: .78; }

  /* confirmed sub/del/merge → green = accepted D reading */
  span.confirmed-accept {
    background: #dcfce7; color: #14532d;
    border-bottom: 2px solid #16a34a;
    border-radius: 2px; padding: 0 2px; cursor: pointer;
    font-style: normal;
  }
  span.confirmed-accept::after {
    content: ' ✓';
    font-size: .6em; vertical-align: super;
    color: #16a34a; font-weight: 700;
  }
  /* confirmed ins → red strikethrough = will be removed */
  span.confirmed-remove {
    background: #fee2e2; color: #991b1b;
    text-decoration: line-through;
    border-bottom: 2px solid #dc3545;
    border-radius: 2px; padding: 0 1px; cursor: pointer;
    opacity: .75;
  }
  span.confirmed-remove::after {
    content: ' ✗';
    font-size: .6em; vertical-align: super;
    color: #dc3545; font-weight: 700;
    text-decoration: none; display: inline-block;
  }
  /* manual override → purple */
  span.manual-edit {
    background: #ede9fe; color: #4c1d95;
    border-bottom: 2px solid #7c3aed;
    border-radius: 2px; padding: 0 2px; cursor: pointer;
    font-style: normal;
  }
  span.manual-edit::after {
    content: ' ✎';
    font-size: .6em; vertical-align: super;
    color: #7c3aed; font-weight: 700;
  }
  /* inline edit input */
  span.editing { background: #faf5ff; outline: 2px solid #7c3aed;
                 border-radius: 3px; padding: 0 2px; }
  .edit-input {
    font-family: inherit; font-size: inherit; line-height: inherit;
    border: none; outline: none;
    background: transparent; color: #4c1d95; font-weight: 600;
    min-width: 50px; max-width: 220px; width: auto;
    padding: 0; margin: 0; vertical-align: baseline;
  }

  /* ── Save bar ── */
  #save-bar {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    background: #fff;
    border-top: 2px solid #08088a;
    padding: 9px 0;
    display: flex;
    align-items: center;
    gap: 16px;
    z-index: 200;
    box-shadow: 0 -2px 10px rgba(0,0,0,.10);
    flex-wrap: wrap;
  }
  #save-bar .hint {
    font-size: .76rem; color: #888; flex: 1;
  }
  .btn-save {
    background: #08088a; color: #fff; border: none;
    padding: 7px 20px; border-radius: 4px;
    cursor: pointer; font-size: .88rem; font-weight: 600;
    white-space: nowrap;
  }
  .btn-save:hover:not(:disabled) { background: #0b0bc2; }
  .btn-save:disabled { background: #9ba3af; cursor: not-allowed; }
  .btn-clear {
    background: transparent; color: #666; border: 1px solid #ccc;
    padding: 6px 14px; border-radius: 4px;
    cursor: pointer; font-size: .82rem;
  }
  .btn-clear:hover { background: #f5f5f5; }
  #confirmed-badge {
    background: #08088a; color: #fff;
    border-radius: 12px; padding: 2px 10px;
    font-size: .78rem; font-weight: 700;
    min-width: 28px; text-align: center;
  }
  #save-status {
    font-size: .8rem; font-weight: 600;
  }
  #save-status.ok  { color: #198754; }
  #save-status.err { color: #dc3545; }
  #save-status.working { color: #0d6efd; }

  #footer { text-align: center; font-size: .73rem; color: #999;
            padding: 14px; border-top: 1px solid #ddd; margin-top: 4px; }

  /* ── Login overlay ── */
  #login-overlay {
    position: fixed; inset: 0; z-index: 500;
    background: rgba(0,0,0,.45); backdrop-filter: blur(2px);
    display: flex; align-items: center; justify-content: center;
  }
  #login-box {
    background: #fff; border-radius: 8px;
    box-shadow: 0 8px 32px rgba(0,0,0,.25);
    padding: 28px 32px; width: 320px; max-width: 92vw;
  }
  #login-box h2 { font-size: .95rem; font-weight: 700;
                  color: #08088a; margin-bottom: 18px; }
  #login-box label { display: block; font-size: .78rem;
                     color: #555; margin-bottom: 4px; }
  #login-box input[type=text],
  #login-box input[type=password] {
    width: 100%; padding: 7px 10px; border: 1px solid #ced4da;
    border-radius: 4px; font-size: .88rem; margin-bottom: 14px;
    font-family: inherit;
  }
  #login-box input:focus { outline: none; border-color: #08088a;
                           box-shadow: 0 0 0 2px rgba(8,8,138,.12); }
  #login-box .btn-login {
    width: 100%; background: #08088a; color: #fff; border: none;
    padding: 9px; border-radius: 4px; font-size: .9rem; font-weight: 600;
    cursor: pointer;
  }
  #login-box .btn-login:hover { background: #0b0bc2; }
  #login-box .login-error {
    font-size: .78rem; color: #dc3545; margin-bottom: 10px;
    background: #fff5f5; border: 1px solid #f5c6cb;
    border-radius: 3px; padding: 5px 8px;
  }
  /* login trigger in save bar */
  #btn-login-trigger {
    background: #08088a; color: #fff; border: none;
    padding: 7px 18px; border-radius: 4px; font-size: .88rem;
    font-weight: 600; cursor: pointer; white-space: nowrap;
  }
  #btn-login-trigger:hover { background: #0b0bc2; }
  .user-badge {
    font-size: .78rem; color: #555;
    background: #e8f0f8; border: 1px solid #c8d6e5;
    border-radius: 12px; padding: 2px 10px;
  }
  .btn-logout {
    background: transparent; color: #888; border: 1px solid #ccc;
    padding: 4px 11px; border-radius: 4px; font-size: .78rem; cursor: pointer;
  }
  .btn-logout:hover { background: #f5f5f5; color: #dc3545; border-color: #dc3545; }

  /* ── Hide text2 ── */
  body.t2-hidden .panel-t2,
  body.t2-hidden .panel-resize-handle,
  body.t2-hidden .col-head-2,
  body.t2-hidden #col-resize-handle { display: none; }
  body.t2-hidden .panel-t1,
  body.t2-hidden .col-head-1 { width: 100%; }

  .btn-toggle-t2 {
    margin-left: auto; flex-shrink: 0;
    background: rgba(255,255,255,.13); color: #ecf0f1;
    border: 1px solid rgba(255,255,255,.25); border-radius: 3px;
    padding: 2px 9px; font-size: .72rem; font-weight: 600;
    cursor: pointer; white-space: nowrap; line-height: 1.6;
  }
  .btn-toggle-t2:hover { background: rgba(255,255,255,.25); }

  @media (max-width: 860px) {
    .panel-row    { flex-direction: column; }
    .col-headers  { flex-direction: column; }
    .col-head-1   { width: 100%; }
    .col-head-2   { border-left: none; border-top: 1px solid #4a6278; }
    .panel-t1, .panel-t2 { width: 100%; }
    .resize-handle { display: none; }
  }

  /* ── IIIF image viewer, attached to its folio block ── */
  .iiif-viewer-wrap {
    margin-top: -1px;          /* attach flush below folio-block border */
    border: 1px solid #08088a;
    border-top: none;
    border-radius: 0 0 6px 6px;
    overflow: hidden;
    background: #000;
  }
</style>
</head>
<body>

<div id="header">
  <h1>rKTs OCR Correction Tool</h1>
  <div class="meta">
    ref <strong><?= htmlspecialchars($config['ref']) ?></strong>
    &nbsp;·&nbsp; rkts <strong><?= htmlspecialchars($config['rkts']) ?></strong>
    &emsp;
    <strong><?= htmlspecialchars($config['label1']) ?></strong>
    <?= htmlspecialchars($config['start1']) ?>–<?= htmlspecialchars($config['end1']) ?>
    &emsp;vs&emsp;
    <strong><?= htmlspecialchars($config['label2']) ?></strong>
    <?= htmlspecialchars($config['start2']) ?>–<?= htmlspecialchars($config['end2']) ?>
  </div>
  <?php if ($config['title']): ?>
  <div class="title-tib"><?= htmlspecialchars($config['title']) ?></div>
  <?php endif; ?>
</div>

<div id="legend">
  <span class="leg"><span class="leg-dot" style="background:#ffc107"></span> Substitution</span>
  <span class="leg"><span class="leg-dot" style="background:#0d6efd"></span> Merged words</span>
  <span class="leg"><span class="leg-dot" style="background:#dc3545"></span> Extra in <?= htmlspecialchars($config['label1']) ?></span>
  <span class="leg"><span class="leg-dot" style="background:#28a745"></span> Missing from <?= htmlspecialchars($config['label1']) ?></span>
  <span class="leg-sep">|</span>
  <span class="leg" style="font-size:.76rem;color:#555;">
    <strong>Click</strong> highlighted word → confirm D reading &nbsp;
    <strong>Dbl-click</strong> → enter custom correction &nbsp;
    <span style="background:#dcfce7;color:#14532d;border-radius:3px;padding:0 4px;font-size:.74rem;">✓</span> accepted &nbsp;
    <span style="background:#ede9fe;color:#4c1d95;border-radius:3px;padding:0 4px;font-size:.74rem;">✎</span> manual
  </span>
</div>

<div id="main">

<div id="stats">
  <div>Words in <?= htmlspecialchars($config['label1']) ?>: <strong><?= count($tokens1) ?></strong></div>
  <div>Words in <?= htmlspecialchars($config['label2']) ?>: <strong><?= count($tokens2) ?></strong></div>
  <div>Matches: <strong><?= $n_eq ?></strong> (<?= $pct($n_eq) ?>%)</div>
  <div>Substitutions: <strong><?= $n_sub ?></strong></div>
  <div>Merged: <strong><?= $n_merge ?></strong></div>
  <div>Extra in F: <strong><?= $n_ins ?></strong></div>
  <div>Missing from F: <strong><?= $n_del ?></strong></div>
</div>

<?php
$pagerHtml = '<div class="pager"><span>Folio page:</span>';
for ($p = 1; $p <= $totalPages; $p++) {
    $cls    = $p === $currentPage ? 'page-link active' : 'page-link';
    $url    = buildUrl($config, ['page' => $p]);
    $fStart = $folioKeys[($p-1) * $fpp] ?? '?';
    $fEnd   = $folioKeys[min($p * $fpp, $totalFolios) - 1] ?? '?';
    $label  = $fStart === $fEnd ? $fStart : "$fStart–$fEnd";
    $pagerHtml .= "<a href=\"$url\" class=\"$cls\" title=\"Page $p\">$label</a> ";
}
$pagerHtml .= '</div>';
echo $pagerHtml;
?>

<div class="col-headers">
  <div class="col-head col-head-1">
    📄 <?= htmlspecialchars($config['label1']) ?>
    <span class="range-badge"><?= htmlspecialchars($config['start1']) ?>–<?= htmlspecialchars($config['end1']) ?></span>
    <?php if ($config['file1_is_data']): ?>
      <span class="range-badge" style="background:rgba(134,239,172,.25);color:#bbf7d0;" title="<?= htmlspecialchars($dataFile1) ?>">📂 from (partially) reviewed text</span>
    <?php endif; ?>
    <button class="btn-toggle-t2" id="btn-toggle-t2" onclick="toggleT2()" title="Hide / show D panel">Hide Derge ✕</button>
  </div>
  <div class="resize-handle" id="col-resize-handle" title="Drag to resize panels"></div>
  <div class="col-head col-head-2">
    📄 <?= htmlspecialchars($config['label2']) ?>
    <span class="range-badge"><?= htmlspecialchars($config['start2']) ?>–<?= htmlspecialchars($config['end2']) ?></span>
  </div>
</div>

<?php
$osdIndex = 0;   // unique OSD div id per folio on the page — incremented inside foreach
foreach ($pageFolios as $folio):
    $folio_lines     = $folios1[$folio];
    $t1LineIdxs      = array_keys($folio_lines);
    $t2LineIdxs      = getT2LinesForT1Range($t1LineIdxs, $t1LineToT2Lines);
    $sideLabel       = str_ends_with($folio, 'a') ? 'recto' : 'verso';
    $t2LinesForFolio = [];
    foreach ($t2LineIdxs as $t2li) {
        if (isset($lines2[$t2li])) $t2LinesForFolio[$t2li] = $lines2[$t2li];
    }
    $t2FolioSet = [];
    foreach ($t2LinesForFolio as $ln) $t2FolioSet[$ln['folio']] = true;
    $t2FolioRange = implode(', ', array_keys($t2FolioSet));
?>
<div class="folio-block">
  <div class="folio-header">
    <div>
      <span class="side-label"><?= htmlspecialchars($folio) ?></span>
      <span class="side-word"><?= $sideLabel ?></span>
    </div>
    <div>
      <?php if ($t2FolioRange): ?>
        <span class="t2-folio-label"><?= htmlspecialchars($config['label2']) ?>: <?= htmlspecialchars($t2FolioRange) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="panel-row">

    <div class="panel panel-t1">
      <?php foreach ($folio_lines as $li => $line): ?>
      <div class="ms-line">
        <span class="line-no"><?= $line['line'] ?></span>
        <span class="line-text"><?php
          $lineOps = $byLine1[$li] ?? [];
          if (empty($lineOps)) echo renderPlainLine($line['text'], $li);
          else echo renderLineWithOps($line['text'], $lineOps);
        ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="resize-handle panel-resize-handle" title="Drag to resize panels"></div>

    <div class="panel panel-t2">
      <?php if (empty($t2LinesForFolio)): ?>
        <div class="no-match">— no matching lines found —</div>
      <?php else:
        $currentT2Folio = null;
        foreach ($t2LinesForFolio as $t2li => $t2line):
          if ($t2line['folio'] !== $currentT2Folio):
            $currentT2Folio = $t2line['folio'];
            $sL = str_ends_with($currentT2Folio, 'a') ? 'recto' : 'verso';
      ?>
        <div class="t2-folio-divider">
          <span class="t2-folio-tag"><?= htmlspecialchars($currentT2Folio) ?> (<?= $sL ?>)</span>
        </div>
      <?php endif; ?>
      <div class="ms-line">
        <span class="line-no"><?= $t2line['line'] ?></span>
        <span class="line-text"><?= $lineHtmlMap[$t2li] ?: htmlspecialchars($t2line['text']) ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>

  </div>
</div>

<?php
// ─── IMAGE VIEWER (per folio) ─────────────────────────────────────────────────
// Reads volume JSON ($chemjson) and manifest.txt to build the IIIF info.json URL,
// then fetches it server-side and inlines the tile source for OpenSeadragon.
// Supports BDRC (default) and EAP manifest.txt templates (yyy=vol, xxx=fileId).
if (file_exists($chemjson)):
    // Lazy-load volume JSON once per page render
    if (!isset($GLOBALS['_chemjson_data'])) {
        $GLOBALS['_chemjson_data'] = json_decode(file_get_contents($chemjson), true) ?? [];
    }
    // Lazy-load manifest.txt once for the IIIF URL template (EAP / custom sources)
    if (!array_key_exists('_iiif_template', $GLOBALS)) {
        $manifestPath = '../data/collections/' . $col . '/' . $constituentTitle . '/manifest.txt';
        $GLOBALS['_iiif_template'] = file_exists($manifestPath)
            ? trim(file_get_contents($manifestPath))
            : null;
    }
    $img = null;
    foreach ($GLOBALS['_chemjson_data'] as $ligne) {
        if (isset($ligne['pagination']) && $ligne['pagination'] === $folio) {
            $img = $ligne['file'];
            break;
        }
    }
    if ($img !== null):
        // Build info.json URL — mirrors images.php iiifUrl() logic, server-side
        $tmpl = $GLOBALS['_iiif_template'];
        if ($tmpl) {
            // EAP:  template contains 'yyy' (vol) and 'xxx' (fileId) tokens
            // BDRC: template contains only 'xxx' (fileId) token
            $volSafe  = str_replace('-', '_', $f['vol']);
            $infoUrl  = str_replace(['yyy', 'xxx'], [rawurlencode($volSafe), rawurlencode($img)], $tmpl);
        } elseif (preg_match('/^https?:\/\//i', $img)) {
            $infoUrl = rtrim($img, '/');
            if (!str_ends_with($infoUrl, '/info.json')) $infoUrl .= '/info.json';
        } else {
            $infoUrl = 'https://iiif.bdrc.io/' . $img . '/info.json';
        }
        $jsonimg = @file_get_contents($infoUrl);
        if ($jsonimg !== false):
            $osdId = 'osd-viewer-' . (++$osdIndex);
?>
<div class="iiif-viewer-wrap">
  <div id="<?= $osdId ?>" style="width:100%;height:400px;"></div>
</div>
<?php if ($osdIndex === 1): // load OSD script only once ?>
<script src="https://cdn.jsdelivr.net/npm/openseadragon@4.1/build/openseadragon/openseadragon.min.js"></script>
<?php endif; ?>
<script>
(function () {
    OpenSeadragon({
        id:          <?= json_encode($osdId) ?>,
        prefixUrl:   'https://cdn.jsdelivr.net/npm/openseadragon@4.1/build/openseadragon/images/',
        maxZoomLevel: 5.0,
        sequenceMode: true,
        tileSources: [<?= $jsonimg ?>]
    });
})();
</script>
<?php endif; endif; endif; ?>

<?php endforeach; ?>

<?= $pagerHtml ?>

</div><!-- #main -->

<!-- ── STICKY SAVE BAR ──────────────────────────────────────────────────── -->
<div id="save-bar">
<?php if ($loggedInUser): ?>
  <button class="btn-save" id="btn-save" onclick="saveCorrections()">
    💾 Save Confirmed Corrections
  </button>
  <button class="btn-clear" onclick="clearConfirmed()" title="Deselect all confirmed ops">
    ✕ Clear
  </button>
  <span>
    Changes: <span id="confirmed-badge">0</span>
  </span>
  <span class="hint">
    <strong>Click</strong> any highlighted word to confirm the D reading.
    <strong>Double-click</strong> to enter a custom correction.
    Saved to <code>../collation/<?= htmlspecialchars($config['ref']) ?>.txt</code>.
  </span>
  <span id="save-status"></span>
  <span class="user-badge">👤 <?= htmlspecialchars($loggedInUser) ?></span>
  <form method="post" style="display:inline;margin:0">
    <input type="hidden" name="action" value="logout">
    <input type="hidden" name="ref"    value="<?= htmlspecialchars($config['ref']) ?>">
    <button type="submit" class="btn-logout">Logout</button>
  </form>
<?php else: ?>
  <button id="btn-login-trigger" onclick="document.getElementById('login-overlay').style.display='flex'">
    🔒 Login to Save Corrections
  </button>
  <span class="hint" style="color:#aaa;">Login required to save corrections.</span>
<?php endif; ?>
</div>

<?php if (!$loggedInUser): ?>
<!-- ── LOGIN OVERLAY ─────────────────────────────────────────────────────── -->
<div id="login-overlay" style="display:<?= $loginError ? 'flex' : 'none' ?>">
  <div id="login-box">
    <h2>🔒 Login to Save Corrections</h2>
    <form method="post" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
      <input type="hidden" name="action" value="login">
      <?php if ($loginError): ?>
        <div class="login-error"><?= htmlspecialchars($loginError) ?></div>
      <?php endif; ?>
      <label for="lf-user">Username</label>
      <input type="text" id="lf-user" name="username" autocomplete="username"
             autofocus required>
      <label for="lf-pass">Password</label>
      <input type="password" id="lf-pass" name="password" autocomplete="current-password" required>
      <button type="submit" class="btn-login">Login</button>
    </form>
    <div style="text-align:right;margin-top:10px">
      <a href="#" style="font-size:.75rem;color:#aaa"
         onclick="document.getElementById('login-overlay').style.display='none';return false">
        Cancel
      </a>
    </div>
  </div>
</div>
<?php endif; ?>

<div id="footer">
  rKTs Collation &mdash;
  <?= htmlspecialchars($config['label1']) ?> (<?= htmlspecialchars($config['start1']) ?>–<?= htmlspecialchars($config['end1']) ?>)
  vs.
  <?= htmlspecialchars($config['label2']) ?> (<?= htmlspecialchars($config['start2']) ?>–<?= htmlspecialchars($config['end2']) ?>)
  &mdash; <?= count($lines1) ?> lines / <?= count($lines2) ?> lines
</div>

<script>
// ─── State ────────────────────────────────────────────────────────────────────
const confirmedOps    = new Set();
const manualOverrides = new Map();
const plainOverrides  = new Map();
const loggedIn        = <?= $loggedInUser ? 'true' : 'false' ?>;

function totalChanges() {
    return confirmedOps.size + manualOverrides.size + plainOverrides.size;
}

function updateBadge() {
    if (!loggedIn) return;
    document.getElementById('confirmed-badge').textContent = totalChanges();
    document.getElementById('btn-save').disabled = totalChanges() === 0;
}
function clearStatus() {
    const s = document.getElementById('save-status');
    s.textContent = ''; s.className = '';
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function t2Text(span) {
    try { return (JSON.parse(span.dataset.t2 || '[]')).join(' '); }
    catch(e) { return ''; }
}

// ─── Apply visual state for diff-op / eq-word spans ──────────────────────────
function applyDecision(span) {
    const idx = parseInt(span.dataset.opIdx, 10);
    const op  = span.dataset.op;
    const t1  = span.dataset.t1 || '';

    span.classList.remove('sub','ins','merge','del','eq',
        'confirmed-accept','confirmed-remove','manual-edit');

    if (manualOverrides.has(idx)) {
        span.classList.add('manual-edit');
        span.textContent = manualOverrides.get(idx);
    } else if (confirmedOps.has(idx)) {
        if (op === 'ins') {
            span.classList.add('confirmed-remove');
            span.textContent = t1;
        } else {
            span.classList.add('confirmed-accept');
            span.textContent = op === 'eq' ? t1 : t2Text(span);
        }
    } else {
        revertSpan(span);
    }
}

function revertSpan(span) {
    const op = span.dataset.op;
    const t1 = span.dataset.t1 || '';
    span.classList.remove('confirmed-accept','confirmed-remove','manual-edit');
    span.classList.add(op);
    if (op === 'del') {
        try { span.textContent = '[' + JSON.parse(span.dataset.t2||'[]').join(' ') + ']'; }
        catch(e) { span.textContent = '[?]'; }
    } else {
        span.textContent = t1;
    }
    // Restore eq-word / plain-word helper class
    if (op === 'eq') span.classList.add('eq-word');
}

// ─── Inline editor (shared by diff-op, eq-word, plain-word) ──────────────────
let activeEditor = null;

function openEditor(span, prefill, onCommit) {
    if (activeEditor) activeEditor.commit(false);

    const input = document.createElement('input');
    input.type      = 'text';
    input.value     = prefill;
    input.className = 'edit-input';
    input.size      = Math.max(6, prefill.length + 2);

    span.textContent = '';
    span.classList.add('editing');
    span.appendChild(input);

    const commit = (save) => {
        if (!activeEditor) return;
        activeEditor = null;
        const val = input.value.trim();
        span.removeChild(input);
        span.classList.remove('editing');
        onCommit(save, val);
    };

    activeEditor = { span, input, commit };
    input.focus(); input.select();

    input.addEventListener('input', () => { input.size = Math.max(6, input.value.length + 2); });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter')  { e.preventDefault(); commit(true); }
        if (e.key === 'Escape') { commit(false); }
    });
    input.addEventListener('blur', () => setTimeout(() => { if (activeEditor) commit(true); }, 120));
}

// ─── diff-op spans (sub, ins, merge, del) ────────────────────────────────────
document.querySelectorAll('.panel-t1 span.diff-op').forEach(span => {

    span.addEventListener('click', function(e) {
        if (activeEditor) return;
        const idx = parseInt(this.dataset.opIdx, 10);
        if (manualOverrides.has(idx)) { manualOverrides.delete(idx); }
        else if (confirmedOps.has(idx)) { confirmedOps.delete(idx); }
        else { confirmedOps.add(idx); }
        applyDecision(this);
        updateBadge(); clearStatus();
    });

    span.addEventListener('dblclick', function(e) {
        e.preventDefault(); e.stopPropagation();
        const idx    = parseInt(this.dataset.opIdx, 10);
        const op     = this.dataset.op;
        const prefill = manualOverrides.has(idx)
            ? manualOverrides.get(idx)
            : (op === 'ins' ? this.dataset.t1 : (t2Text(this) || this.dataset.t1 || ''));
        const self = this;
        openEditor(this, prefill, (save, val) => {
            if (save && val !== '') {
                confirmedOps.delete(idx);
                manualOverrides.set(idx, val);
            } else if (save && val === '' && op === 'ins') {
                manualOverrides.delete(idx);
                confirmedOps.add(idx);
            } else if (!save) { /* no change */ }
            else {
                manualOverrides.delete(idx);
                confirmedOps.delete(idx);
            }
            applyDecision(self);
            updateBadge(); clearStatus();
        });
    });
});

// ─── eq-word spans (unchanged words in diff lines) ───────────────────────────
document.querySelectorAll('.panel-t1 span.eq-word').forEach(span => {

    span.addEventListener('dblclick', function(e) {
        e.preventDefault(); e.stopPropagation();
        const idx     = parseInt(this.dataset.opIdx, 10);
        const prefill = manualOverrides.has(idx)
            ? manualOverrides.get(idx)
            : (this.dataset.t1 || this.textContent.trim());
        const self = this;
        openEditor(this, prefill, (save, val) => {
            if (save && val !== '' && val !== (self.dataset.t1 || '')) {
                confirmedOps.delete(idx);
                manualOverrides.set(idx, val);
            } else {
                // Revert if same as original or empty
                manualOverrides.delete(idx);
            }
            applyDecision(self);
            updateBadge(); clearStatus();
        });
    });
});

// ─── plain-word spans (no-op lines) ──────────────────────────────────────────
document.querySelectorAll('.panel-t1 span.plain-word').forEach(span => {

    span.addEventListener('dblclick', function(e) {
        e.preventDefault(); e.stopPropagation();
        const key     = this.dataset.plainKey;
        const orig    = this.dataset.orig || this.textContent.trim();
        const prefill = plainOverrides.has(key) ? plainOverrides.get(key) : orig;
        const self    = this;
        openEditor(this, prefill, (save, val) => {
            self.classList.remove('plain-manual','editing');
            if (save && val !== '' && val !== orig) {
                plainOverrides.set(key, val);
                self.textContent = val;
                self.classList.add('plain-manual');
            } else {
                plainOverrides.delete(key);
                self.textContent = orig;
            }
            updateBadge(); clearStatus();
        });
    });
});

updateBadge();

// ─── Clear all ────────────────────────────────────────────────────────────────
function clearConfirmed() {
    if (activeEditor) activeEditor.commit(false);
    confirmedOps.clear();
    manualOverrides.clear();
    plainOverrides.clear();
    document.querySelectorAll('.panel-t1 span.diff-op').forEach(s => revertSpan(s));
    document.querySelectorAll('.panel-t1 span.eq-word').forEach(s => revertSpan(s));
    document.querySelectorAll('.panel-t1 span.plain-word').forEach(s => {
        s.textContent = s.dataset.orig || s.textContent;
        s.classList.remove('plain-manual');
    });
    updateBadge(); clearStatus();
}

// ─── Save ─────────────────────────────────────────────────────────────────────
async function saveCorrections() {
    if (totalChanges() === 0) return;

    const btn    = document.getElementById('btn-save');
    const status = document.getElementById('save-status');
    btn.disabled = true;
    status.textContent = '⏳ Saving…';
    status.className   = 'working';

    const manualObj = {};
    manualOverrides.forEach((text, idx) => { manualObj[idx] = text; });
    const plainObj = {};
    plainOverrides.forEach((text, key) => { plainObj[key] = text; });

    const body = new FormData();
    body.append('action',           'save_corrections');
    body.append('ref',              <?= json_encode($config['ref']) ?>);
    body.append('page_num',         <?= json_encode($currentPage) ?>);
    body.append('confirmed_ops',    JSON.stringify([...confirmedOps]));
    body.append('manual_overrides', JSON.stringify(manualObj));
    body.append('plain_overrides',  JSON.stringify(plainObj));
    <?php foreach (['file1','start1','end1','file2','start2','end2','fpp'] as $gk): ?>
    <?php if (isset($_GET[$gk])): ?>
    body.append(<?= json_encode($gk) ?>, <?= json_encode($_GET[$gk]) ?>);
    <?php endif; ?>
    <?php endforeach; ?>

    try {
        const resp = await fetch(
            window.location.pathname
            + '?ref=<?= urlencode($config['ref']) ?>&page=<?= $currentPage ?>&fpp=<?= $fpp ?>',
            { method: 'POST', body }
        );
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();

        if (data.status === 'ok') {
            const parts = [];
            if (data.confirmed > 0) parts.push(`${data.confirmed} confirmed`);
            if (data.manual    > 0) parts.push(`${data.manual} manual`);
            if (data.plain     > 0) parts.push(`${data.plain} plain edits`);
            status.textContent = `✓ Saved → ${data.saved}  (${parts.join(', ')}, page <?= $currentPage ?>, ${data.page_lines} lines)`;
            status.className   = 'ok';
        } else {
            status.textContent = '✗ ' + (data.message ?? 'Unknown error');
            status.className   = 'err';
        }
    } catch(e) {
        status.textContent = '✗ Network error: ' + e.message;
        status.className   = 'err';
    }

    btn.disabled = totalChanges() === 0;
}

// ─── Hide / show text2 panel ──────────────────────────────────────────────────
function toggleT2() {
    const hidden = document.body.classList.toggle('t2-hidden');
    document.getElementById('btn-toggle-t2').textContent = hidden ? 'Show Derge ▸' : 'Hide Derge ✕';
}

// ─── Panel resize ─────────────────────────────────────────────────────────────
(function () {
    let dragging = false;
    let startX   = 0;
    let startPct = 50;

    function getPct() {
        return parseFloat(getComputedStyle(document.documentElement)
            .getPropertyValue('--t1-pct')) || 50;
    }
    function setPct(pct) {
        pct = Math.min(85, Math.max(15, pct));
        document.documentElement.style.setProperty('--t1-pct', pct.toFixed(2));
    }

    function onMouseDown(e) {
        dragging = true;
        startX   = e.clientX;
        startPct = getPct();
        document.querySelectorAll('.resize-handle').forEach(h => h.classList.add('dragging'));
        document.body.style.cursor    = 'col-resize';
        document.body.style.userSelect = 'none';
        e.preventDefault();
    }

    function onMouseMove(e) {
        if (!dragging) return;
        const totalW = document.body.clientWidth;
        const delta  = ((e.clientX - startX) / totalW) * 100;
        setPct(startPct + delta);
    }

    function onMouseUp() {
        if (!dragging) return;
        dragging = false;
        document.querySelectorAll('.resize-handle').forEach(h => h.classList.remove('dragging'));
        document.body.style.cursor     = '';
        document.body.style.userSelect = '';
    }

    document.querySelectorAll('.resize-handle').forEach(h => {
        h.addEventListener('mousedown', onMouseDown);
    });
    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup',   onMouseUp);

    // Double-click any handle → reset to 50/50
    document.querySelectorAll('.resize-handle').forEach(h => {
        h.addEventListener('dblclick', () => setPct(50));
    });
})();

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        if (totalChanges() > 0) saveCorrections();
    }
});
</script>
</body>
</html>