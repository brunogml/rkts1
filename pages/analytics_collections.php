<?php
// ============================================================
// COLLECTION POOL
// Keyed by {CAT}{Sigla}, e.g. "KD", "TDe", "KNg"
// Built at runtime from ../data/collections.json
// ============================================================
$POOL = [];
$poolRaw = @json_decode(@file_get_contents('../data/general/collections.json'), true) ?? [];
foreach ($poolRaw as $entry) {
    $sigla = $entry['sigla'] ?? '';
    $genre = $entry['genre'] ?? '';
    if (!$sigla) continue;
    switch ($genre) {
        case 'Kanjur':           $cat = 'K';  break;
        case 'Tanjur':           $cat = 'T';  break;
        case 'Old Tantra':       $cat = 'OT'; break;
        case 'Bon-Kanjur':       $cat = 'BK'; break;
        case 'Bon-Tanjur':       $cat = 'BT'; break;
        case 'Canonical Collection': $cat = 'K'; break;
        default:                 $cat = 'K';  break;
    }
    $key = $cat . $sigla;
    if (!array_key_exists($key, $POOL)) {
        $POOL[$key] = [
            'key'   => $key,
            'sigla' => $sigla,
            'cat'   => $cat,
            'title' => $entry['title'] ?? $sigla,
            'genre' => $genre,
            'date'  => $entry['date_created'] ?? '',
            'medium'=> $entry['medium'] ?? '',
        ];
    }
}
ksort($POOL);

// ============================================================
// AJAX HANDLER  ?action=compare&cols[]=KD&cols[]=KNg ...
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'compare') {
    ob_start();
    error_reporting(0);
    header('Content-Type: application/json; charset=utf-8');

    $requested = (array)($_GET['cols'] ?? []);
    $selected  = array_values(array_filter($requested, fn($k) => isset($POOL[$k])));
    $selected  = array_slice($selected, 0, 5);

    if (empty($selected)) {
        echo json_encode(['error' => 'No valid collections selected.']);
        exit;
    }

    $allTexts   = [];   // textId => [rkts, cat, sec, tib, ref]
    $presence   = [];   // key => [textId => true]
    $sectionMap = [];   // key => [sec => count]
    $meta       = [];   // key => [title, sigla, cat, count, error?]
    $order      = [];   // key => [textId, ...] in XML appearance order

    // Load standard.xml as fallback for missing tib titles
    $stdTib = [];
    $stdXml = @simplexml_load_file('../data/general/standard.xml');
    if ($stdXml) {
        foreach ($stdXml->item as $item) {
            $stdKey = trim((string)$item->rktsTyp) . (string)$item->rkts;
            $stdTib[$stdKey] = (string)$item->tib;
        }
    }

    foreach ($selected as $key) {
        $entry   = $POOL[$key];
        $sigla   = $entry['sigla'];
        $cat     = $entry['cat'];
        $xmlPath = "../data/collections/{$sigla}/{$sigla}.xml";

        if (!file_exists($xmlPath)) {
            $meta[$key] = ['key'=>$key,'title'=>$entry['title'],'sigla'=>$sigla,'cat'=>$cat,'count'=>0,'error'=>"File not found"];
            $presence[$key] = [];
            $sectionMap[$key] = [];
            $order[$key] = [];
            continue;
        }

        $xml = @simplexml_load_file($xmlPath);
        if (!$xml) {
            $meta[$key] = ['key'=>$key,'title'=>$entry['title'],'sigla'=>$sigla,'cat'=>$cat,'count'=>0,'error'=>"XML parse error"];
            $presence[$key] = [];
            $sectionMap[$key] = [];
            $order[$key] = [];
            continue;
        }

        $presence[$key]   = [];
        $sectionMap[$key] = [];
        $order[$key]      = [];

        foreach ($xml->item as $item) {
            $itemCat = (string)$item->cat;
            if ($itemCat !== $cat) continue;

            $rkts   = (string)$item->rkts;
            $sec    = trim((string)$item->sec);
            $tib    = trim((string)$item->tib);
            $ref    = (string)$item->ref;
            $textId = $rkts . '|' . $cat;

            // Fallback to standard.xml tib if empty
            if ($tib === '') {
                $stdKey = $itemCat . $rkts;
                $tib = $stdTib[$stdKey] ?? '';
            }

            // Register text info (first occurrence wins)
            if (!isset($allTexts[$textId])) {
                $allTexts[$textId] = [
                    'id'   => $textId,
                    'rkts' => (int)$rkts,
                    'cat'  => $itemCat,
                    'sec'  => $sec,
                    'tib'  => $tib,
                    'ref'  => $ref,
                ];
            }
            // Track order within this collection (first occurrence only)
            if (!isset($presence[$key][$textId])) {
                $order[$key][] = $textId;
            }
            $presence[$key][$textId] = true;
        }

        // Count unique texts per section
        foreach ($presence[$key] as $textId => $_) {
            $sec = $allTexts[$textId]['sec'] ?? '(unknown)';
            $sectionMap[$key][$sec] = ($sectionMap[$key][$sec] ?? 0) + 1;
        }

        $meta[$key] = [
            'key'   => $key,
            'title' => $entry['title'],
            'sigla' => $sigla,
            'cat'   => $cat,
            'count' => count($presence[$key]),
        ];
    }

    // Sort texts by rkts number
    uasort($allTexts, fn($a, $b) => $a['rkts'] <=> $b['rkts']);

    // Collect sections in canonical order (by appearance order in allTexts)
    $allSections = [];
    foreach ($allTexts as $t) {
        if (!in_array($t['sec'], $allSections, true)) {
            $allSections[] = $t['sec'];
        }
    }

    // Build presence arrays for JSON (arrays of textIds per collection)
    $presenceLists = [];
    foreach ($selected as $key) {
        $presenceLists[$key] = array_keys($presence[$key] ?? []);
    }

    echo json_encode([
        'collections' => array_values($meta),
        'sections'    => $allSections,
        'sectionMap'  => $sectionMap,
        'texts'       => array_values($allTexts),
        'presence'    => $presenceLists,
        'order'       => $order,
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>rKTs - About</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
    
    <link rel="icon" type="image/png" sizes="32x32" href="../images/favicon-32x32.png">
    <script src="../js/navbar.js" defer></script>

    <style>
        /* ── layout ── */
        body { font-size: .9rem; }
        #results { display: none; }

        /* ── selector panel ── */
        #pool-search { max-width: 340px; }
        #pool-list   { max-height: 320px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: .375rem; }
        .pool-item   { cursor: pointer; user-select: none; padding: .35rem .75rem; display: flex; align-items: center; gap: .5rem; transition: background .1s; }
        .pool-item:hover  { background: #f0f4ff; }
        .pool-item.active { background: #dbe9ff; }
        .pool-item.disabled-sel { opacity: .45; cursor: not-allowed; }
        .pool-badge  { font-size: .68rem; padding: .15em .45em; border-radius: 3px; flex-shrink: 0; }
        .genre-K     { background:#2196f322; color:#1565c0; }
        .genre-T     { background:#8bc34a22; color:#558b2f; }
        .genre-OT    { background:#ff572222; color:#bf360c; }
        .genre-BK    { background:#9c27b022; color:#6a1b9a; }
        .genre-BT    { background:#ff980022; color:#e65100; }
        .sel-chip    { display:inline-flex; align-items:center; gap:.3rem; padding:.25rem .55rem; border-radius:20px; font-size:.78rem; font-weight:500; color:#fff; margin:.2rem; }
        .sel-chip .rm { cursor:pointer; opacity:.8; font-size:1rem; line-height:1; }
        .sel-chip .rm:hover { opacity:1; }

        /* ── summary cards ── */
        .coll-card   { border-left: 4px solid var(--cc, #888); }
        .coll-card .card-title { font-size:.82rem; font-weight:600; }
        .coll-card .count-num  { font-size:1.6rem; font-weight:700; line-height:1; }

        /* ── legend ── */
        #sec-legend  { display:flex; flex-wrap:wrap; gap:.35rem .6rem; font-size:.75rem; }
        .leg-item    { display:flex; align-items:center; gap:.3rem; }
        .leg-swatch  { width:12px; height:12px; border-radius:2px; flex-shrink:0; }

        /* ── table ── */
        #text-table  { font-size:.78rem; }
        #text-table thead th { position:sticky; top:0; background:#fff; z-index:1; cursor:pointer; white-space:nowrap; }
        #text-table thead th:hover { background:#f0f4ff; }
        .tib-cell    { font-size:.76rem; color:#333; }
        .presence-cell { text-align:center; }
        .p-yes       { color:#2e7d32; font-weight:600; }
        .p-no        { color:#ccc; }
        .row-all     { background:#f0fff4; }
        .row-some    { background:#fffde7; }
        .row-none    { background:#fafafa; }

        /* ── pagination ── */
        #tbl-info    { font-size:.78rem; color:#666; }
        #filter-row  { gap:.5rem; }
        .filter-btn  { font-size:.74rem; }

        /* ── overlap bar ── */
        .overlap-bars .ob-row  { margin-bottom:.4rem; }
        .overlap-bars .ob-label{ font-size:.75rem; width:110px; flex-shrink:0; text-align:right; padding-right:.5rem; }
        .overlap-bars .ob-track{ flex:1; background:#e9ecef; border-radius:4px; height:18px; overflow:hidden; }
        .overlap-bars .ob-fill { height:100%; border-radius:4px; transition:width .6s; }
    </style>
</head>
<body>
    <div data-navbar></div>

<div class="container-fluid mt-4" style="max-width:1400px">

    <!-- ── HEADER ── -->
    <div class="d-flex align-items-center gap-3 mb-3">
        <h4 class="mb-0">Collection Comparison</h4>
        <span class="text-muted" style="font-size:.82rem">Select up to 5 collections to compare their structure and textual sequence</span>
    </div>

    <!-- error panel -->
    <div id="err-panel" class="alert alert-danger d-none mb-3" style="font-family:monospace;font-size:.8rem;white-space:pre-wrap"></div>

    <!-- ── SELECTOR ── -->
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-3 align-items-start">
                <!-- left: pool browser -->
                <div class="col-lg-5">
                    <label class="form-label fw-semibold mb-1" style="font-size:.82rem">Browse collections</label>
                    <div class="d-flex gap-2 mb-2" id="filter-row">
                        <input id="pool-search" class="form-control form-control-sm" placeholder="Search by name or sigla…">
                        <select id="genre-filter" class="form-select form-select-sm" style="max-width:160px">
                            <option value="">All genres</option>
                            <option value="K">Kanjur</option>
                            <option value="T">Tanjur</option>
                            <option value="OT">Old Tantra</option>
                            <option value="BK">Bon-Kanjur</option>
                            <option value="BT">Bon-Tanjur</option>
                        </select>
                    </div>
                    <div id="pool-list"></div>
                    <div class="text-muted mt-1" style="font-size:.72rem">Click to add • <span id="pool-count">0</span> collections available</div>
                </div>

                <!-- right: selected chips + compare button -->
                <div class="col-lg-7">
                    <label class="form-label fw-semibold mb-1" style="font-size:.82rem">Selected <span class="text-muted fw-normal">(max 5)</span></label>
                    <div id="sel-chips" class="mb-3" style="min-height:38px">
                        <span class="text-muted" id="sel-placeholder" style="font-size:.82rem">No collections selected yet</span>
                    </div>
                    <button id="btn-compare" class="btn btn-primary btn-sm px-4" disabled>
                        <span id="btn-label">Compare</span>
                        <span id="btn-spinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status"></span>
                    </button>
                    <button id="btn-clear" class="btn btn-outline-secondary btn-sm ms-2">Clear</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── RESULTS ── -->
    <div id="results">

        <!-- presence strip chart -->
        <div class="card mb-3">
            <div class="card-header py-2 d-flex align-items-center justify-content-between">
                <span class="fw-semibold" style="font-size:.85rem">Text Presence Overview</span>
                <button class="btn btn-outline-secondary btn-sm" onclick="openChartFullscreen()" title="Open in full screen">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1h4a.5.5 0 0 1 0 1H2.707L6.5 5.793l-.707.707L2 2.707V5.5a.5.5 0 0 1-1 0v-4A.5.5 0 0 1 1.5 1zm13 0a.5.5 0 0 1 .5.5v4a.5.5 0 0 1-1 0V2.707L9.707 6.5 9 5.793 12.793 2H10.5a.5.5 0 0 1 0-1h4zm-13 13a.5.5 0 0 1-.5-.5v-4a.5.5 0 0 1 1 0v2.793L6 9.207l.707.707L3.207 13.5H5.5a.5.5 0 0 1 0 1h-4zm13 0h-4a.5.5 0 0 1 0-1h2.293L9 9.207l.707-.707 3.793 3.793V10.5a.5.5 0 0 1 1 0v4a.5.5 0 0 1-.5.5z"/></svg>
                    Full screen
                </button>
                <span class="text-muted" style="font-size:.75rem">Each column = one text · X axis = ref # of first collection · hover for title</span>
            </div>
            <div class="card-body p-2">
                <canvas id="presence-canvas" style="width:100%;display:block;cursor:crosshair"></canvas>
                <div id="presence-tooltip" style="position:fixed;background:#333;color:#fff;font-size:.72rem;padding:5px 9px;border-radius:4px;pointer-events:none;display:none;z-index:9999;max-width:340px;white-space:pre;line-height:1.6;font-family:system-ui,sans-serif"></div>
            </div>
        </div>

        <!-- summary cards -->
        <div id="cards-row" class="row g-2 mb-3"></div>

        <!-- overlap summary -->
        <div class="card mb-3" id="overlap-card">
            <div class="card-header py-2">
                <span class="fw-semibold" style="font-size:.85rem">Overlap Summary</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div id="venn-stats" class="mb-2"></div>
                    </div>
                    <div class="col-md-6">
                        <div class="overlap-bars" id="overlap-bars"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- text table -->
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between py-2 flex-wrap gap-2">
                <span class="fw-semibold" style="font-size:.85rem">Text List</span>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="btn-group btn-group-sm" id="overlap-filter">
                        <button class="btn btn-outline-secondary active filter-btn" data-filter="all">All</button>
                        <button class="btn btn-outline-secondary filter-btn" data-filter="common">Common to all</button>
                        <button class="btn btn-outline-secondary filter-btn" data-filter="unique">Unique to one</button>
                    </div>
                    <input id="tbl-search" class="form-control form-control-sm" placeholder="Filter texts…" style="max-width:180px">
                    <span id="tbl-info"></span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:520px; overflow-y:auto">
                    <table class="table table-hover table-bordered mb-0" id="text-table">
                        <thead id="tbl-head"></thead>
                        <tbody id="tbl-body"></tbody>
                    </table>
                </div>
                <div class="d-flex align-items-center justify-content-between px-3 py-2 border-top">
                    <button class="btn btn-outline-secondary btn-sm" id="btn-prev">← Prev</button>
                    <span id="page-info" style="font-size:.78rem"></span>
                    <button class="btn btn-outline-secondary btn-sm" id="btn-next">Next →</button>
                </div>
            </div>
        </div>

    </div><!-- /results -->
</div><!-- /container -->

<!-- Footer -->
<footer class="footer mt-5 py-3 bg-light">
    <div class="container text-center">
        <span class="text-muted">&copy; <script>document.write(new Date().getFullYear())</script> Resources for Kanjur and Tanjur Studies (rKTs). Licensed under <a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank">CC BY-SA 4.0</a>.</span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ============================================================
// POOL (injected from PHP)
// ============================================================
const POOL = <?php echo json_encode(array_values($POOL), JSON_UNESCAPED_UNICODE); ?>;

// ============================================================
// COLOUR HELPERS
// ============================================================
const COLLECTION_COLORS = ['#1976D2','#388E3C','#D32F2F','#7B1FA2','#F57C00'];
const SEC_PALETTE = [
    '#2196F3','#4CAF50','#9C27B0','#FF5722','#009688',
    '#FF9800','#795548','#607D8B','#E91E63','#00BCD4',
    '#8BC34A','#FFC107','#3F51B5','#F44336','#CDDC39',
    '#673AB7','#03A9F4','#4DB6AC','#FF8A65','#A5D6A7',
];
const KNOWN_SEC_COLORS = {
    "'dul ba"     : '#8D6E63',
    "'bum"        : '#1565C0',
    'nyi khri'    : '#1976D2',
    'khri brgyad' : '#1E88E5',
    'khri pa'     : '#42A5F5',
    'brgyad stong': '#90CAF9',
    'sher phyin'  : '#4FC3F7',
    'phal chen'   : '#009688',
    'dkon brtsegs': '#4CAF50',
    'mdo'         : '#8BC34A',
    'mdo sde'     : '#8BC34A',
    'rgyud'       : '#9C27B0',
    "rgyud 'bum"  : '#CE93D8',
    "rnying rgyud": '#FF5722',
    "gzungs 'dus" : '#FF9800',
    'gzungs'      : '#FFB74D',
    'bstod tshogs': '#FFEE58',
    'skyes rabs'  : '#A1887F',
    'sna tshogs'  : '#B0BEC5',
};
const _secColorCache = {};
let _secColorIdx = 0;
function secColor(sec) {
    if (KNOWN_SEC_COLORS[sec]) return KNOWN_SEC_COLORS[sec];
    if (!_secColorCache[sec]) {
        _secColorCache[sec] = SEC_PALETTE[_secColorIdx++ % SEC_PALETTE.length];
    }
    return _secColorCache[sec];
}

// ============================================================
// STATE
// ============================================================
let selected = [];
let currentData = null;
let filteredTexts = [];
let tableFilter = 'all';
let tblSearch = '';
let page = 0;
const PAGE_SIZE = 50;
let sortCol = null, sortDir = 1;

// ============================================================
// POOL RENDERER
// ============================================================
function renderPool() {
    var q    = document.getElementById('pool-search').value.trim().toLowerCase();
    var g    = document.getElementById('genre-filter').value;
    var list = document.getElementById('pool-list');
    var html = '', count = 0;
    for (var i = 0; i < POOL.length; i++) {
        var item = POOL[i];
        var matchQ = !q || item.title.toLowerCase().indexOf(q) >= 0
                        || item.sigla.toLowerCase().indexOf(q) >= 0
                        || item.key.toLowerCase().indexOf(q) >= 0;
        var matchG = !g || item.cat === g;
        if (!matchQ || !matchG) continue;
        count++;
        var isSel  = selected.indexOf(item.key) >= 0;
        var isDis  = !isSel && selected.length >= 5;
        var cls    = 'pool-item' + (isSel ? ' active' : '') + (isDis ? ' disabled-sel' : '');
        var onclick = isDis ? '' : ' onclick="toggleSelection(\'' + item.key + '\')"';
        html += '<div class="' + cls + '"' + onclick + '>'
              + '<span class="pool-badge genre-' + item.cat + '">' + item.cat + '</span>'
              + '<span class="flex-grow-1">' + escHtml(item.title) + '</span>'
              + '<span class="text-muted" style="font-size:.7rem">' + escHtml(item.sigla) + '</span>'
              + (isSel ? '<span style="color:#1976d2">✓</span>' : '')
              + '</div>';
    }
    if (!html) html = '<div class="text-muted px-3 py-2" style="font-size:.8rem">No results</div>';
    list.innerHTML = html;
    document.getElementById('pool-count').textContent = count;
}

function toggleSelection(key) {
    var idx = selected.indexOf(key);
    if (idx >= 0) {
        selected.splice(idx, 1);
    } else {
        if (selected.length >= 5) return;
        selected.push(key);
    }
    renderChips();
    renderPool();
    document.getElementById('btn-compare').disabled = (selected.length < 1);
}

function renderChips() {
    var wrap = document.getElementById('sel-chips');
    if (!selected.length) {
        wrap.innerHTML = '<span class="text-muted" style="font-size:.82rem">No collections selected yet</span>';
        return;
    }
    var html = '';
    for (var i = 0; i < selected.length; i++) {
        var k    = selected[i];
        var item = null;
        for (var j = 0; j < POOL.length; j++) { if (POOL[j].key === k) { item = POOL[j]; break; } }
        var bg = COLLECTION_COLORS[i % COLLECTION_COLORS.length];
        html += '<span class="sel-chip" style="background:' + bg + '" title="' + escHtml(item ? item.title : k) + '">'
              + '<span class="pool-badge" style="background:rgba(255,255,255,.25);color:#fff">' + (item ? item.cat : '') + '</span>'
              + escHtml(item ? item.sigla : k)
              + ' <span style="cursor:pointer;font-size:1.1rem;line-height:1" onclick="toggleSelection(\'' + k + '\')">×</span>'
              + '</span>';
    }
    wrap.innerHTML = html;
}


// ============================================================
// ERROR DISPLAY
// ============================================================
function showError(msg) {
    var p = document.getElementById('err-panel');
    p.textContent = msg;
    p.classList.remove('d-none');
    console.error('compare error:', msg);
}
function clearError() {
    document.getElementById('err-panel').classList.add('d-none');
}

// ============================================================
// COMPARE — plain XHR, no async/await
// ============================================================
function runCompare() {
    clearError();
    var btn = document.getElementById('btn-compare');
    var sp  = document.getElementById('btn-spinner');
    var lb  = document.getElementById('btn-label');
    btn.disabled = true;
    sp.classList.remove('d-none');
    lb.textContent = 'Loading\u2026';

    var params = 'action=compare';
    for (var i = 0; i < selected.length; i++) {
        params += '&cols%5B%5D=' + encodeURIComponent(selected[i]);
    }
    var url = location.pathname + '?' + params;
    console.log('Fetching:', url);

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        btn.disabled = false;
        sp.classList.add('d-none');
        lb.textContent = 'Compare';
        console.log('XHR status:', xhr.status);
        console.log('Response (first 300):', xhr.responseText.substring(0, 300));
        if (xhr.status !== 200) { showError('HTTP error ' + xhr.status); return; }
        var text = xhr.responseText;
        var start = text.indexOf('{');
        if (start < 0) { showError('No JSON in response:\n' + text.substring(0, 400)); return; }
        var data;
        try { data = JSON.parse(text.substring(start)); }
        catch(e) { showError('JSON parse error: ' + e.message + '\n' + text.substring(start, start+200)); return; }
        if (data.error) { showError(data.error); return; }
        console.log('Data OK — collections:', data.collections.length, 'texts:', data.texts.length);
        currentData = data;
        renderResults(data);
    };
    xhr.onerror = function() {
        btn.disabled = false; sp.classList.add('d-none'); lb.textContent = 'Compare';
        showError('Network error');
    };
    xhr.send();
}

// ============================================================
// RESULTS RENDERER
// ============================================================
function renderResults(data) {
    console.log('renderResults start');
    try { document.getElementById('results').style.display = 'block'; console.log('results visible'); } catch(e) { showError('results div: ' + e.message); return; }
    try { renderPresenceChart(data); console.log('renderPresenceChart done'); } catch(e) { showError('renderPresenceChart: ' + e.message + '\n' + e.stack); return; }
    try { renderCards(data);   console.log('renderCards done'); } catch(e) { showError('renderCards: ' + e.message + '\n' + e.stack); return; }
    try { renderOverlap(data); console.log('renderOverlap done'); } catch(e) { showError('renderOverlap: ' + e.message + '\n' + e.stack); return; }
    try { setupTable(data);    console.log('setupTable done'); } catch(e) { showError('setupTable: ' + e.message + '\n' + e.stack); return; }
    console.log('renderResults complete');
    document.getElementById('results').scrollIntoView({behavior:'smooth', block:'start'});
}

// — open chart in full-screen window —
function openChartFullscreen() {
    if (!currentData) return;
    var win = window.open('', '_blank');
    var colors = JSON.stringify(COLLECTION_COLORS);
    var data   = JSON.stringify(currentData);

    win.document.write('<!DOCTYPE html><html lang="en"><head>'
        + '<meta charset="UTF-8">'
        + '<title>rKTs — Text Presence Chart</title>'
        + '<style>'
        + '*{margin:0;padding:0;box-sizing:border-box}'
        + 'body{background:#fff;font-family:system-ui,sans-serif;display:flex;flex-direction:column;height:100vh;overflow:hidden}'
        + '#topbar{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;border-bottom:1px solid #e0e0e0;flex-shrink:0;gap:12px;flex-wrap:wrap}'
        + '#topbar h1{font-size:.95rem;font-weight:600;color:#333}'
        + '#legend{display:flex;gap:16px;flex-wrap:wrap;font-size:.78rem;align-items:center}'
        + '.leg-entry{display:flex;align-items:center;gap:5px}'
        + '.leg-line{width:24px;height:3px;border-radius:2px}'
        + '#canvaswrap{flex:1;position:relative;overflow:hidden}'
        + '#chart{width:100%;height:100%;display:block;cursor:crosshair}'
        + '#tt{position:fixed;background:rgba(30,30,30,.93);color:#fff;font-size:.72rem;padding:6px 10px;border-radius:5px;pointer-events:none;display:none;z-index:9999;white-space:pre;line-height:1.7}'
        + '</style>'
        + '</head><body>'
        + '<div id="topbar">'
        + '<h1>rKTs — Text Presence &amp; Alignment</h1>'
        + '<div id="legend"></div>'
        + '</div>'
        + '<div id="canvaswrap"><canvas id="chart"></canvas></div>'
        + '<div id="tt"></div>'
        + '<script>'
        + 'var COLLECTION_COLORS=' + colors + ';'
        + 'var data=' + data + ';'
        + 'var colls=data.collections;'
        + 'var texts=data.texts;'

        // build legend
        + 'var legEl=document.getElementById("legend");'
        + 'colls.forEach(function(c,i){'
        +   'var col=COLLECTION_COLORS[i%COLLECTION_COLORS.length];'
        +   'legEl.innerHTML+="<div class=\'leg-entry\'><div class=\'leg-line\' style=\'background:"+col+"\'></div>"+c.sigla+" ("+c.cat+")</div>";'
        + '});'

        // pre-compute: firstOrd, textRefIdx, collPos (1-based), maxPos
        + 'var firstKey=colls[0].key;'
        + 'var firstOrd=data.order[firstKey]||[];'
        + 'var xCount=firstOrd.length;'
        + 'var xMax=Math.max(xCount-1,1);'

        + 'var collPos={};'
        + 'colls.forEach(function(c){'
        +   'collPos[c.key]={};'
        +   '(data.order[c.key]||[]).forEach(function(id,pos){collPos[c.key][id]=pos+1;});'
        + '});'

        + 'var maxPos=0;'
        + 'colls.forEach(function(c){var l=(data.order[c.key]||[]).length;if(l>maxPos)maxPos=l;});'
        + 'if(!maxPos)maxPos=1;'

        // textId -> ref label string
        + 'var textRefLabel={};'
        + 'firstOrd.forEach(function(id){'
        +   'var t=texts.find(function(tx){return tx.id===id;});'
        +   'if(t&&t.ref)textRefLabel[id]=t.ref;'
        + '});'

        + 'function draw(){'
        +   'var canvas=document.getElementById("chart");'
        +   'var wrap=document.getElementById("canvaswrap");'
        +   'var dpr=window.devicePixelRatio||1;'
        +   'var cssW=wrap.clientWidth, cssH=wrap.clientHeight;'
        +   'canvas.style.width=cssW+"px"; canvas.style.height=cssH+"px";'
        +   'canvas.width=Math.round(cssW*dpr); canvas.height=Math.round(cssH*dpr);'
        +   'var ctx=canvas.getContext("2d"); ctx.scale(dpr,dpr);'
        +   'var PL=48,PR=12,PT=14,PB=52;'
        +   'var pw=cssW-PL-PR, ph=cssH-PT-PB;'
        +   'ctx.fillStyle="#fafafa"; ctx.fillRect(0,0,cssW,cssH);'
        +   'ctx.fillStyle="#fff"; ctx.fillRect(PL,PT,pw,ph);'

        // Y axis grid + labels
        +   'ctx.font="10px system-ui,sans-serif"; ctx.textBaseline="middle"; ctx.textAlign="right";'
        +   'var yTicks=6;'
        +   'for(var ti=0;ti<=yTicks;ti++){'
        +     'var pv=Math.round((ti/yTicks)*maxPos); if(pv===0)pv=1;'
        +     'var py=PT+ph-((pv-1)/(maxPos-1))*ph;'
        +     'ctx.strokeStyle="rgba(0,0,0,0.06)"; ctx.lineWidth=1;'
        +     'ctx.beginPath();ctx.moveTo(PL,py);ctx.lineTo(PL+pw,py);ctx.stroke();'
        +     'ctx.strokeStyle="#bbb";'
        +     'ctx.beginPath();ctx.moveTo(PL-4,py);ctx.lineTo(PL,py);ctx.stroke();'
        +     'ctx.fillStyle="#888"; ctx.fillText(pv,PL-6,py);'
        +   '}'
        // Y axis title
        +   'ctx.save();ctx.translate(12,PT+ph/2);ctx.rotate(-Math.PI/2);'
        +   'ctx.textAlign="center";ctx.textBaseline="middle";ctx.fillStyle="#aaa";ctx.font="10px system-ui,sans-serif";'
        +   'ctx.fillText("position",0,0);ctx.restore();'

        // section dividers (firstOrd sequential X)
        +   'ctx.strokeStyle="rgba(0,0,0,0.07)"; ctx.lineWidth=1;'
        +   'var lastSec=null;'
        +   'firstOrd.forEach(function(id,idx){'
        +     'var t=texts.find(function(tx){return tx.id===id;});'
        +     'var sec=t?t.sec:null;'
        +     'if(sec!==lastSec&&lastSec!==null){'
        +       'var bx=PL+(idx/xMax)*pw;'
        +       'ctx.beginPath();ctx.moveTo(bx,PT);ctx.lineTo(bx,PT+ph);ctx.stroke();'
        +     '}'
        +     'lastSec=sec;'
        +   '});'

        // lines: X = sequential index in firstOrd, Y = position in each collection
        +   'colls.forEach(function(c,ci){'
        +     'var color=COLLECTION_COLORS[ci%COLLECTION_COLORS.length];'
        +     'ctx.strokeStyle=color; ctx.lineWidth=ci===0?1.8:1.3;'
        +     'ctx.globalAlpha=ci===0?0.9:0.8;'
        +     'ctx.beginPath(); var penDown=false;'
        +     'firstOrd.forEach(function(id,idx){'
        +       'var yVal=collPos[c.key][id];'
        +       'if(yVal===undefined){penDown=false;return;}'
        +       'var px=PL+(idx/xMax)*pw;'
        +       'var py=PT+ph-((yVal-1)/(maxPos-1))*ph;'
        +       'if(!penDown){ctx.moveTo(px,py);penDown=true;}else{ctx.lineTo(px,py);}'
        +     '});'
        +     'ctx.stroke(); ctx.globalAlpha=1;'
        +   '});'

        // border
        +   'ctx.strokeStyle="#ddd"; ctx.lineWidth=1; ctx.strokeRect(PL,PT,pw,ph);'

        // X axis ref labels
        +   'if(xCount>1){'
        +     'ctx.font="9px system-ui,sans-serif"; ctx.fillStyle="#888";'
        +     'ctx.textAlign="center"; ctx.textBaseline="top";'
        +     'var tickCount=Math.min(30,xCount);'
        +     'var step=Math.max(1,Math.floor(xCount/tickCount));'
        +     'for(var i=0;i<xCount;i+=step){'
        +       'var id2=firstOrd[i];'
        +       'var t2=texts.find(function(tx){return tx.id===id2;});'
        +       'var lbl=t2?(t2.ref||(t2.cat+t2.rkts)):(i+1);'
        +       'var px2=PL+(i/xMax)*pw;'
        +       'ctx.strokeStyle="#ccc"; ctx.lineWidth=1;'
        +       'ctx.beginPath();ctx.moveTo(px2,PT+ph);ctx.lineTo(px2,PT+ph+4);ctx.stroke();'
        +       'ctx.fillText(lbl,px2,PT+ph+5);'
        +     '}'
        +   '}'

        +   'canvas._pw=pw;canvas._ph=ph;canvas._PL=PL;canvas._PT=PT;'
        + '}'

        // tooltip
        + 'var tt=document.getElementById("tt");'
        + 'document.getElementById("chart").addEventListener("mousemove",function(e){'
        +   'var c=this,r=c.getBoundingClientRect();'
        +   'var mx=e.clientX-r.left,my=e.clientY-r.top;'
        +   'var pw=c._pw,ph=c._ph,PL=c._PL,PT=c._PT;'
        +   'if(!pw||mx<PL||mx>PL+pw||my<PT||my>PT+ph){tt.style.display="none";return;}'
        +   'var idx=Math.round(((mx-PL)/pw)*xMax);'
        +   'idx=Math.max(0,Math.min(xCount-1,idx));'
        +   'var bestId=firstOrd[idx];'
        +   'if(!bestId){tt.style.display="none";return;}'
        +   'var t=texts.find(function(tx){return tx.id===bestId;});'
        +   'if(!t){tt.style.display="none";return;}'
        +   'var lines=[t.cat+t.rkts+(t.ref?" · ref "+t.ref:"")+" · "+t.sec,t.tib||"(no title)",""];'
        +   'colls.forEach(function(c){'
        +     'var pos=collPos[c.key][bestId];'
        +     'var total=(data.order[c.key]||[]).length;'
        +     'lines.push(c.sigla+": "+(pos!==undefined?"pos "+pos+" / "+total:"absent"));'
        +   '});'
        +   'tt.textContent=lines.join("\\n");'
        +   'tt.style.display="block";'
        +   'tt.style.left=(e.clientX+14)+"px";tt.style.top=(e.clientY-10)+"px";'
        + '});'
        + 'document.getElementById("chart").addEventListener("mouseleave",function(){tt.style.display="none";});'
        + 'draw();'
        + 'window.addEventListener("resize",draw);'
        + '</sc' + 'ript>'
        + '</body></html>');
    win.document.close();
}

// — presence alignment line chart —
function renderPresenceChart(data) {
    var canvas  = document.getElementById('presence-canvas');
    var tooltip = document.getElementById('presence-tooltip');
    var colls   = data.collections;
    var texts   = data.texts;          // rKTs-sorted union (X axis reference)
    var n       = texts.length;
    if (!n || !colls.length) return;

    var dpr     = window.devicePixelRatio || 1;
    var PAD_T   = 10;
    var PAD_B   = 58;  // space for legend + x-axis ref labels
    var PAD_L   = 46;  // space for Y axis position labels
    var PAD_R   = 8;
    var cssW    = canvas.parentElement.clientWidth || 900;
    var cssH    = 260;

    canvas.style.height = cssH + 'px';
    canvas.width  = Math.round(cssW * dpr);
    canvas.height = Math.round(cssH * dpr);
    var ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);

    var plotW = cssW - PAD_L - PAD_R;
    var plotH = cssH - PAD_T - PAD_B;

    // Background
    ctx.fillStyle = '#fafafa';
    ctx.fillRect(0, 0, cssW, cssH);
    ctx.fillStyle = '#fff';
    ctx.fillRect(PAD_L, PAD_T, plotW, plotH);

    // Build textId -> ref number of first collection (for labels only)
    var firstKey = colls[0].key;
    var firstOrd = data.order[firstKey] || [];
    var textRefNum = {};   // textId -> numeric ref label (display only)
    var textRefIdx = {};   // textId -> sequential index in firstOrd (0-based, used for X position)
    firstOrd.forEach(function(id, idx) {
        textRefIdx[id] = idx;
        var t = texts.find(function(tx) { return tx.id === id; });
        if (t && t.ref) {
            var n2 = parseInt(String(t.ref).replace(/\D/g, ''), 10);
            if (!isNaN(n2)) textRefNum[id] = t.ref;  // keep display label
        }
    });

    // X domain: 0 .. firstOrd.length-1  (uniform spacing)
    var xCount = firstOrd.length;
    var xMax   = Math.max(xCount - 1, 1);

    // Section boundary lines (firstOrd sequential index on X)
    ctx.strokeStyle = 'rgba(0,0,0,0.07)';
    ctx.lineWidth = 1;
    var lastSec = null;
    firstOrd.forEach(function(id, idx) {
        var t = texts.find(function(tx) { return tx.id === id; });
        var sec = t ? t.sec : null;
        if (sec !== lastSec && lastSec !== null) {
            var bx = PAD_L + (idx / xMax) * plotW;
            ctx.beginPath(); ctx.moveTo(bx, PAD_T); ctx.lineTo(bx, PAD_T + plotH); ctx.stroke();
        }
        lastSec = sec;
    });

    // For each collection: textId -> its 1-based position within that collection
    var collPos = {};
    colls.forEach(function(c) {
        collPos[c.key] = {};
        var ord = data.order[c.key] || [];
        ord.forEach(function(id, pos) { collPos[c.key][id] = pos + 1; }); // 1-based
    });

    // Max position across all collections (for Y scaling)
    var maxPos = 0;
    colls.forEach(function(c) {
        var ord = data.order[c.key] || [];
        if (ord.length > maxPos) maxPos = ord.length;
    });
    if (!maxPos) maxPos = 1;

    // Y axis: position labels + horizontal grid lines
    ctx.font = '9px system-ui,sans-serif';
    ctx.textBaseline = 'middle';
    ctx.textAlign = 'right';
    var yTickCount = 6;
    for (var ti = 0; ti <= yTickCount; ti++) {
        var posVal = Math.round((ti / yTickCount) * maxPos);
        if (posVal === 0) posVal = 1;
        var py = PAD_T + plotH - ((posVal - 1) / (maxPos - 1)) * plotH;
        // grid line
        ctx.strokeStyle = 'rgba(0,0,0,0.06)';
        ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(PAD_L, py); ctx.lineTo(PAD_L + plotW, py); ctx.stroke();
        // tick
        ctx.strokeStyle = '#bbb';
        ctx.beginPath(); ctx.moveTo(PAD_L - 4, py); ctx.lineTo(PAD_L, py); ctx.stroke();
        // label
        ctx.fillStyle = '#888';
        ctx.fillText(posVal, PAD_L - 6, py);
    }
    // Y axis title
    ctx.save();
    ctx.translate(10, PAD_T + plotH / 2);
    ctx.rotate(-Math.PI / 2);
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = '#aaa';
    ctx.font = '9px system-ui,sans-serif';
    ctx.fillText('position', 0, 0);
    ctx.restore();

    // Draw each collection as a line
    // X = sequential index in firstOrd (uniform spacing), Y = position in each collection
    // Missing texts (not in this collection) lift the pen
    colls.forEach(function(c, ci) {
        var color = COLLECTION_COLORS[ci % COLLECTION_COLORS.length];

        ctx.strokeStyle = color;
        ctx.lineWidth   = ci === 0 ? 1.5 : 1.2;
        ctx.globalAlpha = ci === 0 ? 0.9 : 0.8;
        ctx.beginPath();
        var penDown = false;

        firstOrd.forEach(function(id, idx) {
            var yVal = collPos[c.key][id];
            if (yVal === undefined) { penDown = false; return; }  // absent → skip

            var px = PAD_L + (idx / xMax) * plotW;
            var py = PAD_T + plotH - ((yVal - 1) / (maxPos - 1)) * plotH;

            if (!penDown) { ctx.moveTo(px, py); penDown = true; }
            else          { ctx.lineTo(px, py); }
        });
        ctx.stroke();
        ctx.globalAlpha = 1;
    });

    // Border
    ctx.strokeStyle = '#ddd';
    ctx.lineWidth = 1;
    ctx.strokeRect(PAD_L, PAD_T, plotW, plotH);

    // X-axis: ref labels from first collection (evenly spaced ticks, uniform X)
    if (xCount > 1) {
        ctx.font = '9px system-ui,sans-serif';
        ctx.fillStyle = '#888';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        var tickCount = Math.min(20, xCount);
        var step = Math.max(1, Math.floor(xCount / tickCount));
        for (var i = 0; i < xCount; i += step) {
            var id2 = firstOrd[i];
            var t2  = texts.find(function(tx) { return tx.id === id2; });
            var refLabel = t2 ? (t2.ref || (t2.cat + t2.rkts)) : (i + 1);
            var px2 = PAD_L + (i / xMax) * plotW;
            ctx.strokeStyle = '#ccc';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(px2, PAD_T + plotH);
            ctx.lineTo(px2, PAD_T + plotH + 4);
            ctx.stroke();
            ctx.fillText(refLabel, px2, PAD_T + plotH + 5);
        }
    }

    // Legend
    var legX = PAD_L;
    var legY = PAD_T + plotH + 36;
    ctx.font = '11px system-ui,sans-serif';
    ctx.textBaseline = 'middle';
    colls.forEach(function(c, ci) {
        var color = COLLECTION_COLORS[ci % COLLECTION_COLORS.length];
        ctx.fillStyle = color;
        ctx.fillRect(legX, legY - 5, 22, 3);
        ctx.fillStyle = '#444';
        ctx.textAlign = 'left';
        ctx.fillText(c.sigla, legX + 26, legY);
        legX += ctx.measureText(c.sigla).width + 50;
    });

    canvas._chartData = { texts: texts, colls: colls, collPos: collPos,
                          firstOrd: firstOrd, xMax: xMax,
                          maxPos: maxPos, plotW: plotW, plotH: plotH, PAD_L: PAD_L, PAD_T: PAD_T };

    canvas.onmousemove = function(e) {
        var d = canvas._chartData;
        var rect = canvas.getBoundingClientRect();
        var mx = e.clientX - rect.left;
        var my = e.clientY - rect.top;
        if (mx < d.PAD_L || mx > d.PAD_L + d.plotW || my < d.PAD_T || my > d.PAD_T + d.plotH) {
            tooltip.style.display = 'none'; return;
        }
        // Find nearest text by sequential X index
        var frac = (mx - d.PAD_L) / d.plotW;
        var idx  = Math.round(frac * d.xMax);
        idx = Math.max(0, Math.min(d.firstOrd.length - 1, idx));
        var bestId = d.firstOrd[idx];
        if (!bestId) { tooltip.style.display = 'none'; return; }
        var t = d.texts.find(function(tx) { return tx.id === bestId; });
        if (!t) { tooltip.style.display = 'none'; return; }

        var lines = [t.cat + t.rkts + (t.ref ? ' · ref ' + t.ref : '') + ' · ' + t.sec, t.tib || '(no title)', ''];
        d.colls.forEach(function(c) {
            var pos = d.collPos[c.key][bestId];
            lines.push(c.sigla + ': ' + (pos !== undefined ? 'pos ' + pos + ' / ' + (data.order[c.key] ? data.order[c.key].length : '?') : 'absent'));
        });
        tooltip.textContent = lines.join('\n');
        tooltip.style.display = 'block';
        tooltip.style.left = (e.clientX + 14) + 'px';
        tooltip.style.top  = (e.clientY - 10) + 'px';
    };
    canvas.onmouseleave = function() { tooltip.style.display = 'none'; };
}

// — summary cards —
function renderCards(data) {
    const row = document.getElementById('cards-row');
    row.innerHTML = data.collections.map((c, i) => {
        const bg = COLLECTION_COLORS[i % COLLECTION_COLORS.length];
        const err = c.error ? `<div class="text-danger" style="font-size:.72rem">⚠ ${escHtml(c.error)}</div>` : '';
        return `<div class="col-sm-6 col-md-4 col-xl-2">
            <div class="card coll-card h-100" style="--cc:${bg}">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="pool-badge genre-${c.cat}">${c.cat}</span>
                        <span class="text-muted" style="font-size:.72rem">${escHtml(c.sigla)}</span>
                    </div>
                    <div class="card-title text-truncate" title="${escHtml(c.title)}">${escHtml(c.title)}</div>
                    <div class="count-num" style="color:${bg}">${c.count.toLocaleString()}</div>
                    <div class="text-muted" style="font-size:.72rem">unique texts</div>
                    ${err}
                </div>
            </div>
        </div>`;
    }).join('');
}

// — chart —
// — overlap summary —
function renderOverlap(data) {
    const colls = data.collections;
    const n = colls.length;
    if (n < 2) {
        document.getElementById('overlap-card').style.display = 'none';
        return;
    }
    document.getElementById('overlap-card').style.display = '';

    // Build presence sets
    const sets = {};
    data.collections.forEach(c => {
        sets[c.key] = new Set(data.presence[c.key] ?? []);
    });

    // Common to all
    let common = new Set(data.presence[colls[0].key] ?? []);
    colls.forEach(c => { common = new Set([...common].filter(x => sets[c.key].has(x))); });

    // Unique per collection
    const stats = document.getElementById('venn-stats');
    stats.innerHTML = `<div class="mb-2"><strong>${common.size.toLocaleString()}</strong> texts common to all ${n} collections 
        <span class="text-muted">(${colls[0].count ? Math.round(common.size*100/colls[0].count) : 0}% of ${colls[0].sigla})</span></div>`;

    // Pairwise overlaps
    if (n === 2) {
        const a = sets[colls[0].key], b = sets[colls[1].key];
        const union = new Set([...a, ...b]);
        const jaccard = union.size ? Math.round([...a].filter(x => b.has(x)).length * 100 / union.size) : 0;
        stats.innerHTML += `<div class="text-muted" style="font-size:.8rem">Jaccard similarity: <strong>${jaccard}%</strong></div>`;
    }

    // Overlap bars
    const barsEl = document.getElementById('overlap-bars');
    barsEl.innerHTML = colls.map((c, i) => {
        const inAll  = [...sets[c.key]].filter(x => colls.every(cc => sets[cc.key].has(x))).length;
        const inSome = [...sets[c.key]].filter(x => colls.some(cc => cc.key !== c.key && sets[cc.key].has(x))).length;
        const total  = sets[c.key].size || 1;
        const pAll   = Math.round(inAll  * 100 / total);
        const pSome  = Math.round(inSome * 100 / total);
        const col    = COLLECTION_COLORS[i % COLLECTION_COLORS.length];
        return `<div class="ob-row d-flex align-items-center">
            <div class="ob-label text-truncate" title="${escHtml(c.title)}">${escHtml(c.sigla)}</div>
            <div class="ob-track">
                <div class="ob-fill" style="width:${pAll}%;background:${col}" title="In all: ${pAll}%"></div>
            </div>
            <span class="ms-2 text-muted" style="font-size:.72rem;white-space:nowrap">${pAll}% shared</span>
        </div>`;
    }).join('');
}

// — table —
function setupTable(data) {
    filteredTexts = data.texts.slice();
    tableFilter = 'all'; tblSearch = ''; page = 0; sortCol = 'ref'; sortDir = 1;

    // Update filter buttons
    document.querySelectorAll('#overlap-filter .filter-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.filter === 'all');
    });
    document.getElementById('tbl-search').value = '';

    // Build presence sets
    const presenceSets = {};
    data.collections.forEach(c => {
        presenceSets[c.key] = new Set(data.presence[c.key] ?? []);
    });

    // Build header
    const firstSigla = data.collections.length ? escHtml(data.collections[0].sigla) : '';
    const head = document.getElementById('tbl-head');
    head.innerHTML = `<tr>
        <th data-col="ref" class="col-sort" title="Ref number in ${firstSigla}">Ref <span class="sort-icon">⇅</span></th>
        <th data-col="rkts" class="col-sort"># <span class="sort-icon">⇅</span></th>
        <th data-col="sec" class="col-sort">Section <span class="sort-icon">⇅</span></th>
        <th>Tibetan Title</th>
        ${data.collections.map((c,i) => `<th class="presence-cell" style="color:${COLLECTION_COLORS[i%COLLECTION_COLORS.length]}" title="${escHtml(c.title)}">${escHtml(c.sigla)}</th>`).join('')}
    </tr>`;
    head.querySelectorAll('.col-sort').forEach(th => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            if (sortCol === th.dataset.col) sortDir *= -1;
            else { sortCol = th.dataset.col; sortDir = 1; }
            updateSortIcons(head);
            applyTableFilters(data, presenceSets);
        });
    });
    updateSortIcons(head);

    applyTableFilters(data, presenceSets);

    // filter buttons
    document.querySelectorAll('#overlap-filter .filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            tableFilter = btn.dataset.filter;
            document.querySelectorAll('#overlap-filter .filter-btn').forEach(b => b.classList.toggle('active', b === btn));
            page = 0;
            applyTableFilters(data, presenceSets);
        });
    });

    // search
    document.getElementById('tbl-search').addEventListener('input', e => {
        tblSearch = e.target.value.toLowerCase();
        page = 0;
        applyTableFilters(data, presenceSets);
    });

    document.getElementById('btn-prev').addEventListener('click', () => { if (page > 0) { page--; renderPage(data, presenceSets); } });
    document.getElementById('btn-next').addEventListener('click', () => {
        const maxPage = Math.ceil(filteredTexts.length / PAGE_SIZE) - 1;
        if (page < maxPage) { page++; renderPage(data, presenceSets); }
    });
}

function applyTableFilters(data, presenceSets) {
    const keys = data.collections.map(c => c.key);
    filteredTexts = data.texts.filter(t => {
        // overlap filter
        const presentIn = keys.filter(k => presenceSets[k].has(t.id)).length;
        if (tableFilter === 'common' && presentIn < keys.length) return false;
        if (tableFilter === 'unique' && presentIn !== 1) return false;
        // text search
        if (tblSearch) {
            const haystack = (t.rkts + t.cat + t.sec + t.tib + t.ref).toLowerCase();
            if (!haystack.includes(tblSearch)) return false;
        }
        return true;
    });

    // sort
    if (sortCol) {
        filteredTexts.sort((a, b) => {
            let va = sortCol === 'rkts' ? a.rkts : a[sortCol];
            let vb = sortCol === 'rkts' ? b.rkts : b[sortCol];
            // For ref: try numeric sort on trailing digits, fallback to localeCompare
            if (sortCol === 'ref') {
                const na = parseInt((va || '').replace(/\D/g, ''), 10);
                const nb = parseInt((vb || '').replace(/\D/g, ''), 10);
                if (!isNaN(na) && !isNaN(nb)) return (na - nb) * sortDir;
                return String(va ?? '').localeCompare(String(vb ?? '')) * sortDir;
            }
            if (typeof va === 'string') return va.localeCompare(vb) * sortDir;
            return (va - vb) * sortDir;
        });
    }

    page = 0;
    renderPage(data, presenceSets);
}

function renderPage(data, presenceSets) {
    const keys  = data.collections.map(c => c.key);
    const total = filteredTexts.length;
    const maxPage = Math.max(0, Math.ceil(total / PAGE_SIZE) - 1);
    if (page > maxPage) page = maxPage;
    const slice = filteredTexts.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE);

    const tbody = document.getElementById('tbl-body');
    tbody.innerHTML = slice.map(t => {
        const presentIn = keys.filter(k => presenceSets[k].has(t.id)).length;
        const rowCls = presentIn === keys.length ? 'row-all' : (presentIn > 0 ? 'row-some' : 'row-none');
        const cells = keys.map(k =>
            `<td class="presence-cell">${presenceSets[k].has(t.id) ? '<span class="p-yes">✓</span>' : '<span class="p-no">–</span>'}</td>`
        ).join('');
        return `<tr class="${rowCls}">
            <td class="text-nowrap" style="font-size:.75rem">${escHtml(t.ref || '–')}</td>
            <td class="text-nowrap"><a href="detail.php?id=${encodeURIComponent(t.cat + t.rkts)}" style="font-size:.75rem">${escHtml(t.cat + t.rkts)}</a></td>
            <td class="text-nowrap"><span class="leg-item"><span class="leg-swatch" style="background:${secColor(t.sec)}"></span>${escHtml(t.sec)}</span></td>
            <td class="tib-cell">${escHtml(t.tib)}</td>
            ${cells}
        </tr>`;
    }).join('');

    document.getElementById('tbl-info').textContent = `${total.toLocaleString()} texts`;
    document.getElementById('page-info').textContent = total
        ? `${page * PAGE_SIZE + 1}–${Math.min((page + 1) * PAGE_SIZE, total)} of ${total.toLocaleString()}`
        : 'No results';
    document.getElementById('btn-prev').disabled = page <= 0;
    document.getElementById('btn-next').disabled = page >= maxPage;
}

// ============================================================
// UTILS
// ============================================================
function updateSortIcons(head) {
    head.querySelectorAll('.col-sort').forEach(th => {
        const icon = th.querySelector('.sort-icon');
        if (!icon) return;
        if (th.dataset.col === sortCol) {
            icon.textContent = sortDir === 1 ? '↑' : '↓';
            th.style.fontWeight = '700';
        } else {
            icon.textContent = '⇅';
            th.style.fontWeight = '';
        }
    });
}

function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ============================================================
// INIT
// ============================================================
document.getElementById('pool-search').addEventListener('input', renderPool);
document.getElementById('genre-filter').addEventListener('change', renderPool);
document.getElementById('btn-compare').addEventListener('click', runCompare);
document.getElementById('btn-clear').addEventListener('click', function() {
    selected = [];
    renderChips();
    renderPool();
    document.getElementById('btn-compare').disabled = true;
    document.getElementById('results').style.display = 'none';
});
renderPool();
renderChips();
</script>

<script>
    (function() {
        var el = document.getElementById('navbar-placeholder');
        var base = el.getAttribute('data-base');
        fetch(base + '/navbar.html')
            .then(function(r) { return r.text(); })
            .then(function(html) { el.outerHTML = html.replace(/\{BASE\}/g, base); });
    })();
</script>
</body>
</html>