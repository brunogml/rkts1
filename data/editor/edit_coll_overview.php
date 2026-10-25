<?php
// edit_coll_overview.php — place in ../data/editor/
// Handles both:
//   GET        → serve the editor HTML
//   POST save  → write XML to ../general/collections_overview.xml

$TARGET = __DIR__ . '/../general/collections_overview.xml';

// ── POST: save XML ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save') {
    header('Content-Type: application/json');

    $body = file_get_contents('php://input');
    if (!$body) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Empty body']); exit; }

    if (strpos($body, '<list>') === false || strpos($body, '</list>') === false) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid XML: <list> not found']); exit;
    }

    libxml_use_internal_errors(true);
    if (simplexml_load_string($body) === false) {
        $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>implode('; ',$errs)]); exit;
    }

    $tmp = $TARGET . '.tmp';
    if (file_put_contents($tmp, $body) === false) {
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Cannot write temp file']); exit;
    }
    if (!rename($tmp, $TARGET)) {
        @unlink($tmp);
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Cannot replace target file']); exit;
    }

    echo json_encode(['ok'=>true,'bytes'=>strlen($body)]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>rKTs – Collections Overview Editor</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../configuration/style.css">
    <link rel="icon" type="image/png" sizes="32x32" href="../../configuration/favicon-32x32.png">
    <style>
        .col-sigla  { font-family: monospace; font-weight: 600; }
        .status-sel { width: 110px; }
        select.true     { background-color: #d1f0d8; color: #1a6630; }
        select.false    { background-color: #fad7d7; color: #7a1f1f; }
        select.progress { background-color: #fde8c8; color: #7a4a00; }
        .dirty-indicator { width: 8px; height: 8px; border-radius: 50%;
                           background: #dc3545; display: inline-block; margin-right: 4px; }
        #save-bar { position: sticky; top: 0; z-index: 100;
                    background: #fff; border-bottom: 1px solid #dee2e6; padding: 0.5rem 0; }
        .type-badge { font-size: 0.75em; font-family: monospace; font-weight: 700;
                      padding: 0.2em 0.5em; border-radius: 0.3em;
                      background: #e9ecef; color: #495057; }
        .add-row-form { background: #f8f9fa; border: 1px solid #dee2e6;
                        border-radius: 0.4em; padding: 1rem; margin-top: 1rem; }
        #msg { min-height: 1.5em; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-light" style="background-color: var(--light-gray)">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="../../pages/index.html">
                <img src="../../configuration/rkts-logo.png" alt="rKTs logo" height="50">
            </a>
            <span style="color:#1d447a;font-weight:bold;">Collections Overview Editor</span>
            <a class="btn btn-sm btn-outline-secondary ms-auto" href="../general/collections_overview.xml" target="_blank">
                <i class="bi bi-file-code me-1"></i>View XML
            </a>
        </div>
    </nav>

    <div class="container-fluid px-4 mt-3">

        <!-- Save bar -->
        <div id="save-bar" class="d-flex align-items-center gap-2">
            <button class="btn btn-primary btn-sm" id="btn-save" disabled>
                <i class="bi bi-floppy me-1"></i>Save
            </button>
            <button class="btn btn-outline-secondary btn-sm" id="btn-reload">
                <i class="bi bi-arrow-clockwise me-1"></i>Reload
            </button>
            <span id="msg" class="text-muted small ms-2"></span>
        </div>

        <!-- Filter -->
        <div class="d-flex gap-2 align-items-center my-3" style="max-width:500px">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="filter-input" class="form-control border-start-0" placeholder="Filter by sigla or type…">
                <button class="btn btn-outline-secondary" id="filter-clear"><i class="bi bi-x"></i></button>
            </div>
            <span id="filter-count" class="text-muted small text-nowrap"></span>
        </div>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle" id="main-table">
                <thead class="table-light">
                    <tr>
                        <th style="width:90px">Sigla</th>
                        <th style="width:180px">Type</th>
                        <th>Images</th>
                        <th>Handlist</th>
                        <th>E-texts</th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <tr><td colspan="6" class="text-muted py-3">Loading…</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Add row form -->
        <div class="add-row-form" style="max-width:640px">
            <h6 class="mb-3"><i class="bi bi-plus-circle me-1"></i>Add entry</h6>
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label form-label-sm">Sigla</label>
                    <input type="text" id="new-col" class="form-control form-control-sm" style="width:90px" placeholder="e.g. D">
                </div>
                <div class="col-auto">
                    <label class="form-label form-label-sm">Type</label>
                    <select id="new-type" class="form-select form-select-sm" style="width:190px">
                        <option value="K">K – Kanjur</option>
                        <option value="T">T – Tanjur</option>
                        <option value="G">G – Old Tantra</option>
                        <option value="B">B – Bon Canon</option>
                        <option value="M">M – Mongolian Canon</option>
                        <option value="C">C – Canonical Collections</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label form-label-sm">Images</label>
                    <select id="new-images" class="form-select form-select-sm status-sel">
                        <option value="false">false</option>
                        <option value="true">true</option>
                        <option value="progress">progress</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label form-label-sm">Handlist</label>
                    <select id="new-handlist" class="form-select form-select-sm status-sel">
                        <option value="false">false</option>
                        <option value="true">true</option>
                        <option value="progress">progress</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label form-label-sm">E-texts</label>
                    <select id="new-etext" class="form-select form-select-sm status-sel">
                        <option value="false">false</option>
                        <option value="true">true</option>
                        <option value="progress">progress</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-success btn-sm" id="btn-add">
                        <i class="bi bi-plus-lg me-1"></i>Add
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const XML_URL  = '../general/collections_overview.xml';
    const SAVE_URL = 'edit_coll_overview.php?action=save';
    const TYPE_LABELS = { K:'Kanjur', T:'Tanjur', G:'Old Tantra', B:'Bon Canon', M:'Mongolian Canon', C:'Canonical Collections' };
    const STATUS_VALS = ['true','false','progress'];

    let items = [];
    let dirty = false;

    // ── XML parse / serialise ─────────────────────────────────────────────────

    function parseXml(text) {
        const doc = new DOMParser().parseFromString(text, 'application/xml');
        return [...doc.querySelectorAll('item')].map(el => ({
            col:      el.querySelector('col')?.textContent.trim()      || '',
            type:     el.querySelector('type')?.textContent.trim()     || '',
            images:   el.querySelector('images')?.textContent.trim()   || 'false',
            handlist: el.querySelector('handlist')?.textContent.trim() || 'false',
            etext:    el.querySelector('etext')?.textContent.trim()    || 'false',
        }));
    }

    function serialiseXml(arr) {
        const lines = ['<list>'];
        for (const it of arr) {
            lines.push('',
                '<item>',
                `<col>${esc(it.col)}</col>`,
                `<type>${esc(it.type)}</type>`,
                `<images>${esc(it.images)}</images>`,
                `<handlist>${esc(it.handlist)}</handlist>`,
                `<etext>${esc(it.etext)}</etext>`,
                '</item>');
        }
        lines.push('', '</list>', '');
        return lines.join('\n');
    }

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── Render ────────────────────────────────────────────────────────────────

    function statusSelect(field, idx, val) {
        const opts = STATUS_VALS.map(v =>
            `<option value="${v}"${v === val ? ' selected' : ''}>${v}</option>`).join('');
        return `<select class="form-select form-select-sm status-sel ${val}"
                        data-idx="${idx}" data-field="${field}"
                        onchange="onStatusChange(this)">${opts}</select>`;
    }

    function renderTable() {
        const q     = document.getElementById('filter-input').value.trim().toLowerCase();
        const tbody = document.getElementById('table-body');
        tbody.innerHTML = '';
        let shown = 0;
        items.forEach((it, idx) => {
            if (q && !it.col.toLowerCase().includes(q)
                  && !it.type.toLowerCase().includes(q)
                  && !(TYPE_LABELS[it.type] || '').toLowerCase().includes(q)) return;
            shown++;
            const tr = document.createElement('tr');
            tr.dataset.idx = idx;
            tr.innerHTML = `
                <td class="col-sigla">${esc(it.col)}</td>
                <td>
                    <span class="type-badge">${esc(it.type)}</span>
                    <span class="text-muted small ms-1">${esc(TYPE_LABELS[it.type] || it.type)}</span>
                </td>
                <td>${statusSelect('images',   idx, it.images)}</td>
                <td>${statusSelect('handlist', idx, it.handlist)}</td>
                <td>${statusSelect('etext',    idx, it.etext)}</td>
                <td>
                    <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete"
                            onclick="deleteRow(${idx})"><i class="bi bi-trash"></i></button>
                </td>`;
            tbody.appendChild(tr);
        });
        document.getElementById('filter-count').textContent =
            q ? `${shown} / ${items.length} entries` : `${items.length} entries`;
    }

    // ── Event handlers ────────────────────────────────────────────────────────

    function onStatusChange(sel) {
        items[parseInt(sel.dataset.idx)][sel.dataset.field] = sel.value;
        sel.className = `form-select form-select-sm status-sel ${sel.value}`;
        markDirty();
    }

    function deleteRow(idx) {
        if (!confirm(`Delete entry "${items[idx].col} (${items[idx].type})"?`)) return;
        items.splice(idx, 1);
        markDirty();
        renderTable();
    }

    document.getElementById('btn-add').addEventListener('click', () => {
        const col      = document.getElementById('new-col').value.trim();
        const type     = document.getElementById('new-type').value;
        const images   = document.getElementById('new-images').value;
        const handlist = document.getElementById('new-handlist').value;
        const etext    = document.getElementById('new-etext').value;
        if (!col) { setMsg('Sigla is required.', 'danger'); return; }
        items.push({ col, type, images, handlist, etext });
        document.getElementById('new-col').value = '';
        markDirty();
        renderTable();
        setMsg(`Added ${col} (${type}).`, 'success');
    });

    document.getElementById('btn-save').addEventListener('click', saveXml);
    document.getElementById('btn-reload').addEventListener('click', () => {
        if (dirty && !confirm('Discard unsaved changes and reload?')) return;
        loadData();
    });

    document.getElementById('filter-input').addEventListener('input', renderTable);
    document.getElementById('filter-clear').addEventListener('click', () => {
        document.getElementById('filter-input').value = '';
        renderTable();
    });

    // ── Dirty state ───────────────────────────────────────────────────────────

    function markDirty() {
        dirty = true;
        document.getElementById('btn-save').disabled = false;
        setMsg('<span class="dirty-indicator"></span>Unsaved changes', 'warning');
    }

    function setMsg(html, type) {
        const el = document.getElementById('msg');
        el.className = `small ms-2 text-${type}`;
        el.innerHTML = html;
    }

    // ── Load ──────────────────────────────────────────────────────────────────

    async function loadData() {
        setMsg('Loading…', 'muted');
        try {
            const res = await fetch(XML_URL + '?_=' + Date.now());
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            items = parseXml(await res.text());
            dirty = false;
            document.getElementById('btn-save').disabled = true;
            renderTable();
            setMsg(`Loaded ${items.length} entries.`, 'muted');
        } catch(e) {
            setMsg('Could not load XML: ' + e.message, 'danger');
            console.error(e);
        }
    }

    // ── Save ──────────────────────────────────────────────────────────────────

    async function saveXml() {
        const xml = serialiseXml(items);
        setMsg('Saving…', 'muted');
        try {
            const res = await fetch(SAVE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/xml' },
                body: xml
            });
            const json = await res.json();
            if (!res.ok || !json.ok) throw new Error(json.error || `HTTP ${res.status}`);
            dirty = false;
            document.getElementById('btn-save').disabled = true;
            setMsg(`Saved — ${json.bytes} bytes written.`, 'success');
        } catch(e) {
            setMsg('Save failed: ' + e.message, 'danger');
            console.error(e);
        }
    }

    // ── Boot ──────────────────────────────────────────────────────────────────
    loadData();
    </script>
</body>
</html>