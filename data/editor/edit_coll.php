<?php
/**
 * edit_coll.php  –  rKTs Collection Editor
 * Reads credentials from ../general/login.txt
 * Reads/writes ../general/collections.json
 *
 * login.txt format (semicolon-separated, quoted):
 *   "username";"password";"email"
 */

session_start();

// ── Paths ────────────────────────────────────────────────────────────────────
$base        = dirname(__FILE__);
$loginFile   = $base . '/../general/login.txt';
$collectFile = $base . '/../general/collections.json';

// ── Load credentials ─────────────────────────────────────────────────────────
function loadCredentials(string $path): array {
    $users = [];
    if (!file_exists($path)) return $users;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        // Format: "username";"password";"email"
        $parts = str_getcsv($line, ';', '"');
        if (count($parts) >= 2) {
            $users[] = [
                'username' => $parts[0],
                'password' => $parts[1],
                'email'    => $parts[2] ?? '',
            ];
        }
    }
    return $users;
}

// ── Auth helpers ──────────────────────────────────────────────────────────────
$credentials = loadCredentials($loginFile);
$loginError  = '';

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $u = trim($_POST['username'] ?? '');
    $p = trim($_POST['password'] ?? '');
    foreach ($credentials as $cred) {
        if ($cred['username'] === $u && $cred['password'] === $p) {
            $_SESSION['ec_user'] = $u;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
    $loginError = 'Invalid username or password.';
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$loggedIn = isset($_SESSION['ec_user']);

// ── JSON save handler (AJAX) ──────────────────────────────────────────────────
if ($loggedIn && isset($_POST['action']) && $_POST['action'] === 'save') {
    header('Content-Type: application/json');
    $payload = $_POST['data'] ?? '';
    $decoded = json_decode($payload, true);
    if ($decoded === null) {
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
        exit;
    }
    $result = file_put_contents($collectFile, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => $result !== false]);
    exit;
}

// ── Load collections for the editor ──────────────────────────────────────────
$collectionsJson = '[]';
if ($loggedIn && file_exists($collectFile)) {
    $raw = file_get_contents($collectFile);
    // Validate
    $test = json_decode($raw);
    $collectionsJson = ($test !== null) ? $raw : '[]';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>rKTs – Collection Editor</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  /* ── rKTs navbar palette ── */
  :root {
    --rkts-bar:    #e8e8e8;
    --rkts-border: #c8c8c8;
    --rkts-accent: #5a6a7a;
    --rkts-hover:  #d0d8e0;
  }

  body { background: #f4f6f8; font-family: 'Segoe UI', sans-serif; }

  /* ── Top bar ── */
  .top-bar {
    background: var(--rkts-bar);
    border-bottom: 1px solid var(--rkts-border);
    padding: .45rem 1rem;
    display: flex;
    align-items: center;
    gap: .75rem;
  }
  .top-bar .brand { font-weight: 600; color: #333; font-size: 1.05rem; }
  .top-bar .user-info { margin-left: auto; font-size: .85rem; color: #555; }

  /* ── Login card ── */
  .login-wrap {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .login-card {
    background: #fff;
    border: 1px solid var(--rkts-border);
    border-radius: 8px;
    padding: 2rem 2.5rem;
    width: 340px;
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
  }
  .login-card h5 { font-weight: 600; margin-bottom: 1.2rem; }

  /* ── Layout ── */
  .editor-layout {
    display: flex;
    height: calc(100vh - 46px);
  }
  .sidebar {
    width: 270px;
    min-width: 220px;
    background: #fff;
    border-right: 1px solid var(--rkts-border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .sidebar-header {
    padding: .6rem .75rem;
    background: var(--rkts-bar);
    border-bottom: 1px solid var(--rkts-border);
    font-size: .8rem;
    font-weight: 600;
    color: #444;
    display: flex;
    align-items: center;
    gap: .4rem;
  }
  .coll-list {
    flex: 1;
    overflow-y: auto;
    padding: .35rem 0;
  }
  .coll-item {
    padding: .38rem .75rem;
    cursor: pointer;
    font-size: .82rem;
    border-left: 3px solid transparent;
    transition: background .12s;
  }
  .coll-item:hover  { background: var(--rkts-hover); }
  .coll-item.active {
    background: var(--rkts-hover);
    border-left-color: var(--rkts-accent);
    font-weight: 600;
  }
  .coll-item .sigla {
    display: inline-block;
    min-width: 28px;
    font-weight: 700;
    color: var(--rkts-accent);
  }
  .sidebar-footer {
    padding: .5rem .75rem;
    border-top: 1px solid var(--rkts-border);
    display: flex;
    gap: .4rem;
  }

  /* ── Form panel ── */
  .form-panel {
    flex: 1;
    overflow-y: auto;
    padding: 1.2rem 1.5rem;
  }
  .form-panel h6 {
    font-size: .75rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #888;
    margin: 1.2rem 0 .4rem;
    border-bottom: 1px solid #e0e0e0;
    padding-bottom: .2rem;
  }
  .form-panel h6:first-child { margin-top: 0; }

  /* Rich-text toolbar */
  .rte-bar {
    background: var(--rkts-bar);
    border: 1px solid var(--rkts-border);
    border-bottom: none;
    border-radius: 4px 4px 0 0;
    padding: .2rem .4rem;
    display: flex;
    gap: .15rem;
    flex-wrap: wrap;
  }
  .rte-bar button {
    background: none;
    border: 1px solid transparent;
    border-radius: 3px;
    padding: .15rem .35rem;
    font-size: .78rem;
    cursor: pointer;
    color: #444;
  }
  .rte-bar button:hover { background: #fff; border-color: var(--rkts-border); }
  [contenteditable] {
    border: 1px solid var(--rkts-border);
    border-radius: 0 0 4px 4px;
    padding: .5rem .6rem;
    min-height: 120px;
    background: #fff;
    font-size: .85rem;
    outline: none;
  }
  [contenteditable]:focus { border-color: var(--rkts-accent); box-shadow: 0 0 0 2px rgba(90,106,122,.15); }

  /* Notes checkboxes */
  .notes-grid { display: flex; gap: 1.5rem; flex-wrap: wrap; }

  /* Status bar */
  .status-bar {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    background: #fff;
    border-top: 1px solid var(--rkts-border);
    padding: .35rem 1rem;
    font-size: .8rem;
    color: #555;
    display: flex;
    align-items: center;
    gap: .5rem;
    z-index: 100;
  }
  .status-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #aaa;
    display: inline-block;
  }
  .status-dot.ok  { background: #3cb55e; }
  .status-dot.err { background: #e05c5c; }

  /* Constituents table */
  .const-table th { font-size: .75rem; background: var(--rkts-bar); }
  .const-table td { font-size: .82rem; vertical-align: middle; }
  .const-table input { font-size: .82rem; }

  /* Collapsible volumes */
  details.vol-details summary {
    cursor: pointer;
    font-size: .78rem;
    color: var(--rkts-accent);
    padding: .2rem 0;
  }
  .vol-table { font-size: .78rem; margin-top: .4rem; }
  .vol-table th { background: var(--rkts-bar); font-size: .72rem; }

  .btn-sm { font-size: .78rem; }
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- ══════════════════ LOGIN ══════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <h5><i class="bi bi-shield-lock me-2"></i>rKTs Collection Editor</h5>
    <?php if ($loginError): ?>
      <div class="alert alert-danger py-2 px-3" style="font-size:.85rem"><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <div class="mb-3">
        <label class="form-label" style="font-size:.85rem">Username</label>
        <input type="text" name="username" class="form-control form-control-sm" autofocus required>
      </div>
      <div class="mb-3">
        <label class="form-label" style="font-size:.85rem">Password</label>
        <input type="password" name="password" class="form-control form-control-sm" required>
      </div>
      <button type="submit" class="btn btn-sm w-100" style="background:var(--rkts-accent);color:#fff">Log in</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════ EDITOR ══════════════════ -->

<!-- Top bar -->
<div class="top-bar">
  <span class="bi bi-collection" style="color:var(--rkts-accent)"></span>
  <span class="brand">rKTs Collection Editor</span>
  <span class="user-info">
    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['ec_user']) ?>
    &nbsp;|&nbsp;
    <a href="?logout=1" class="text-decoration-none text-secondary" style="font-size:.82rem">Log out</a>
  </span>
</div>

<div class="editor-layout">

  <!-- Sidebar: collection list -->
  <div class="sidebar">
    <div class="sidebar-header">
      <i class="bi bi-list-ul"></i> Collections
      <span id="collCount" class="ms-auto text-muted"></span>
    </div>
    <div class="coll-list" id="collList"></div>
    <div class="sidebar-footer">
      <button class="btn btn-sm btn-outline-secondary flex-fill" onclick="newCollection()">
        <i class="bi bi-plus-lg"></i> New
      </button>
      <button class="btn btn-sm btn-outline-danger" id="btnDelete" onclick="deleteCollection()" disabled>
        <i class="bi bi-trash"></i>
      </button>
    </div>
  </div>

  <!-- Form panel -->
  <div class="form-panel" id="formPanel">
    <p class="text-muted mt-4" style="font-size:.9rem">← Select a collection or create a new one.</p>
  </div>

</div>

<!-- Status bar -->
<div class="status-bar">
  <span class="status-dot" id="statusDot"></span>
  <span id="statusMsg">Ready</span>
  <button class="btn btn-sm ms-auto" style="background:var(--rkts-accent);color:#fff" id="btnSave" onclick="saveAll()" disabled>
    <i class="bi bi-floppy"></i> Save all changes
  </button>
</div>

<script>
// ── Data ─────────────────────────────────────────────────────────────────────
let collections = <?= $collectionsJson ?>;
let currentIdx  = null;
let dirty       = false;

// ── Init ──────────────────────────────────────────────────────────────────────
renderList();

function renderList() {
  const ul = document.getElementById('collList');
  ul.innerHTML = '';
  document.getElementById('collCount').textContent = collections.length;
  collections.forEach((c, i) => {
    const d = document.createElement('div');
    d.className = 'coll-item' + (i === currentIdx ? ' active' : '');
    d.innerHTML = `<span class="sigla">${esc(c.sigla || '?')}</span> ${esc(c.title || '(untitled)')}`;
    d.onclick = () => selectCollection(i);
    ul.appendChild(d);
  });
}

// ── Select ────────────────────────────────────────────────────────────────────
function selectCollection(idx) {
  if (dirty && currentIdx !== null) {
    flushCurrent();          // auto-save edits back to array before switching
  }
  currentIdx = idx;
  renderList();
  document.getElementById('btnDelete').disabled = false;
  renderForm(collections[idx]);
}

// ── Form renderer ─────────────────────────────────────────────────────────────
function renderForm(c) {
  const panel = document.getElementById('formPanel');

  // Notes flags
  const notesKeys = ['handlist_Handlist Available','images_Images Available','text_Text Available'];
  const notesLabels = ['Handlist','Images','Text'];
  const notesHtml = notesKeys.map((k,i) => `
    <div class="form-check form-check-inline">
      <input class="form-check-input" type="checkbox" id="note_${i}" data-notekey="${k}"
        ${(c.notes||{})[k]==='true' ? 'checked' : ''} onchange="markDirty()">
      <label class="form-check-label" style="font-size:.83rem" for="note_${i}">${notesLabels[i]}</label>
    </div>`).join('');

  panel.innerHTML = `
    <h6>Identifiers</h6>
    <div class="row g-2 mb-2">
      <div class="col-2">
        <label class="form-label form-label-sm">Sigla</label>
        <input type="text" class="form-control form-control-sm" id="f_sigla" value="${esc(c.sigla||'')}" oninput="markDirty()">
      </div>
      <div class="col-5">
        <label class="form-label form-label-sm">Title</label>
        <input type="text" class="form-control form-control-sm" id="f_title" value="${esc(c.title||'')}" oninput="markDirty()">
      </div>
      <div class="col-5">
        <label class="form-label form-label-sm">Alternative title (Tibetan/other)</label>
        <input type="text" class="form-control form-control-sm" id="f_alt_title"
          value="${esc(c.alternative_title||'')}" oninput="markDirty()">
      </div>
    </div>
    <div class="row g-2 mb-2">
      <div class="col-4">
        <label class="form-label form-label-sm">File name</label>
        <input type="text" class="form-control form-control-sm" id="f_file_name" value="${esc(c.file_name||'')}" oninput="markDirty()">
      </div>
      <div class="col-3">
        <label class="form-label form-label-sm">Genre</label>
        <input type="text" class="form-control form-control-sm" id="f_genre" value="${esc(c.genre||'')}" oninput="markDirty()">
      </div>
      <div class="col-2">
        <label class="form-label form-label-sm">Medium</label>
        <input type="text" class="form-control form-control-sm" id="f_medium" value="${esc(c.medium||'')}" oninput="markDirty()">
      </div>
      <div class="col-3">
        <label class="form-label form-label-sm">Date created</label>
        <input type="text" class="form-control form-control-sm" id="f_date_created" value="${esc(c.date_created||'')}" oninput="markDirty()">
      </div>
    </div>
    <div class="row g-2 mb-2">
      <div class="col-3">
        <label class="form-label form-label-sm">Collection base</label>
        <input type="text" class="form-control form-control-sm" id="f_collection_base" value="${esc(c.collection_base||'')}" oninput="markDirty()">
      </div>
      <div class="col-3">
        <label class="form-label form-label-sm">Collection base URL</label>
        <input type="text" class="form-control form-control-sm" id="f_collection_base_url" value="${esc(c.collection_base_url||'')}" oninput="markDirty()">
      </div>
    </div>
    <div class="row g-2 mb-2">
      <div class="col-6">
        <label class="form-label form-label-sm">Place of production</label>
        <input type="text" class="form-control form-control-sm" id="f_place" value="${esc(c.place_of_production||'')}" oninput="markDirty()">
      </div>
      <div class="col-3">
        <label class="form-label form-label-sm">Coordinates</label>
        <input type="text" class="form-control form-control-sm" id="f_coords" value="${esc(c.coordinates||'')}" oninput="markDirty()">
      </div>
      <div class="col-3">
        <label class="form-label form-label-sm">Lines per page</label>
        <input type="number" class="form-control form-control-sm" id="f_lines"
          value="${c.number_of_lines != null ? c.number_of_lines : ''}" oninput="markDirty()">
      </div>
    </div>
    <div class="row g-2 mb-2">
      <div class="col-12">
        <label class="form-label form-label-sm">Classifications (comma-separated)</label>
        <input type="text" class="form-control form-control-sm" id="f_class"
          value="${esc((c.classifications||[]).join(', '))}" oninput="markDirty()">
      </div>
    </div>

    <h6>Abstract</h6>
    <div class="rte-bar">
      <button onclick="rte('bold')"><b>B</b></button>
      <button onclick="rte('italic')"><i>I</i></button>
      <button onclick="rte('insertHTML','<h4>Heading</h4>')">H4</button>
      <button onclick="rte('insertHTML','<br>')">BR</button>
    </div>
    <div id="f_abstract" contenteditable="true" oninput="markDirty()">${c.abstract||''}</div>

    <h6>Availability</h6>
    <div class="notes-grid mb-3">${notesHtml}</div>

    <h6>Constituents</h6>
    <div id="constituentSection"></div>
    <button class="btn btn-sm btn-outline-secondary mt-1" onclick="addConstituent()">
      <i class="bi bi-plus"></i> Add constituent
    </button>
  `;

  renderConstituents(c.constituents || []);
}

// ── Constituents ──────────────────────────────────────────────────────────────
function renderConstituents(list) {
  const sec = document.getElementById('constituentSection');
  if (!list.length) { sec.innerHTML = '<p class="text-muted" style="font-size:.82rem">No constituents.</p>'; return; }
  sec.innerHTML = list.map((con, ci) => `
    <div class="card mb-2" id="con_${ci}">
      <div class="card-header py-1 px-2 d-flex align-items-center" style="font-size:.8rem;background:var(--rkts-bar)">
        <span class="fw-semibold">Constituent ${ci+1}</span>
        <button class="btn btn-sm btn-outline-danger ms-auto py-0 px-1" onclick="removeConstituent(${ci})">
          <i class="bi bi-x"></i>
        </button>
      </div>
      <div class="card-body py-2 px-2">
        <div class="row g-1 mb-1">
          <div class="col-2"><label class="form-label form-label-sm">ID</label>
            <input class="form-control form-control-sm" data-ci="${ci}" data-f="id" value="${esc(con.id||'')}" oninput="updateConField(this)"></div>
          <div class="col-4"><label class="form-label form-label-sm">Title (BDRC ID)</label>
            <input class="form-control form-control-sm" data-ci="${ci}" data-f="title" value="${esc(con.title||'')}" oninput="updateConField(this)"></div>
          <div class="col-6"><label class="form-label form-label-sm">URL</label>
            <input class="form-control form-control-sm" data-ci="${ci}" data-f="url" value="${esc(con.url||'')}" oninput="updateConField(this)"></div>
        </div>
        <div class="row g-1 mb-1">
          <div class="col-3"><label class="form-label form-label-sm">Medium</label>
            <input class="form-control form-control-sm" data-ci="${ci}" data-f="medium" value="${esc(con.medium||'')}" oninput="updateConField(this)"></div>
          <div class="col-3"><label class="form-label form-label-sm">Script</label>
            <input class="form-control form-control-sm" data-ci="${ci}" data-f="script" value="${esc(con.script||'')}" oninput="updateConField(this)"></div>
          <div class="col-3"><label class="form-label form-label-sm">Extent</label>
            <input class="form-control form-control-sm" data-ci="${ci}" data-f="extent" value="${esc(con.extent||'')}" oninput="updateConField(this)"></div>
          <div class="col-3"><label class="form-label form-label-sm">Integrated</label>
            <select class="form-select form-select-sm" data-ci="${ci}" data-f="integratedrkts" onchange="updateConField(this)">
              <option value="1" ${con.integratedrkts=='1'?'selected':''}>Yes</option>
              <option value="0" ${con.integratedrkts!='1'?'selected':''}>No</option>
            </select></div>
        </div>
        <div class="row g-1 mb-1">
          <div class="col-3">
            <label class="form-label form-label-sm">Is reference set</label>
            <select class="form-select form-select-sm" data-ci="${ci}" data-f="isreferenceset" onchange="updateConField(this)">
              <option value="1" ${con.isreferenceset=='1'?'selected':''}>Yes</option>
              <option value="0" ${con.isreferenceset!='1'?'selected':''}>No</option>
            </select>
          </div>
        </div>
        <div class="mt-2 mb-1">
          <label class="form-label form-label-sm">Description</label>
          <div class="rte-bar">
            <button onclick="rteC(${ci},'bold')"><b>B</b></button>
            <button onclick="rteC(${ci},'italic')"><i>I</i></button>
            <button onclick="rteC(${ci},'insertHTML','<h4>Heading</h4>')">H4</button>
            <button onclick="rteC(${ci},'insertHTML','<br>')">BR</button>
          </div>
          <div id="con_desc_${ci}" contenteditable="true" oninput="updateConDesc(${ci})"
            style="min-height:60px">${con.general_description||''}</div>
        </div>
        <details class="vol-details">
          <summary><i class="bi bi-table me-1"></i>Volume structure (${(con.structure||[]).length} vols)</summary>
          <div class="mt-1">
            <table class="table table-sm table-bordered vol-table">
              <thead><tr><th style="width:70px">Vol#</th><th>Vol name</th><th style="width:110px">Volref</th><th>Description</th><th></th></tr></thead>
              <tbody id="vols_${ci}"></tbody>
            </table>
            <button class="btn btn-sm btn-outline-secondary" onclick="addVol(${ci})">
              <i class="bi bi-plus"></i> Add volume
            </button>
          </div>
        </details>
      </div>
    </div>`).join('');

  list.forEach((con, ci) => renderVols(ci, con.structure || []));
}

function renderVols(ci, structure) {
  const tbody = document.getElementById('vols_' + ci);
  if (!tbody) return;
  tbody.innerHTML = structure.map((v, vi) => `
    <tr>
      <td><input class="form-control form-control-sm" data-ci="${ci}" data-vi="${vi}" data-vf="volno"
        value="${esc(v.volno||'')}" oninput="updateVolField(this)"></td>
      <td><input class="form-control form-control-sm" data-ci="${ci}" data-vi="${vi}" data-vf="volname"
        value="${esc(v.volname||'')}" oninput="updateVolField(this)"></td>
      <td><input class="form-control form-control-sm" data-ci="${ci}" data-vi="${vi}" data-vf="volref"
        value="${esc(v.volref||'')}" oninput="updateVolField(this)"></td>
      <td><textarea class="form-control form-control-sm" rows="1" data-ci="${ci}" data-vi="${vi}" data-vf="vol_description"
        oninput="updateVolField(this)" style="min-height:31px">${esc(v.vol_description||'')}</textarea></td>
      <td><button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeVol(${ci},${vi})">
        <i class="bi bi-x"></i></button></td>
    </tr>`).join('');
}

function updateConField(el) {
  markDirty();
  const ci = +el.dataset.ci, f = el.dataset.f;
  collections[currentIdx].constituents[ci][f] = el.value;
}
function updateConDesc(ci) {
  markDirty();
  const el = document.getElementById('con_desc_' + ci);
  if (el) collections[currentIdx].constituents[ci].general_description = el.innerHTML;
}
function rteC(ci, cmd, val) {
  document.getElementById('con_desc_' + ci).focus();
  document.execCommand(cmd, false, val || null);
  updateConDesc(ci);
}
function updateVolField(el) {
  markDirty();
  const ci = +el.dataset.ci, vi = +el.dataset.vi, f = el.dataset.vf;
  collections[currentIdx].constituents[ci].structure[vi][f] = el.value;
}
function addConstituent() {
  markDirty();
  collections[currentIdx].constituents = collections[currentIdx].constituents || [];
  collections[currentIdx].constituents.push({ id: '', title: '', url: '', medium: '', script: '', extent: '', integratedrkts: '0', isreferenceset: '0', general_description: '', structure: [] });
  renderForm(collections[currentIdx]);
}
function removeConstituent(ci) {
  markDirty();
  collections[currentIdx].constituents.splice(ci, 1);
  renderForm(collections[currentIdx]);
}
function addVol(ci) {
  markDirty();
  collections[currentIdx].constituents[ci].structure.push({ volno: '', volname: '', volref: '', vol_description: '' });
  renderVols(ci, collections[currentIdx].constituents[ci].structure);
}
function removeVol(ci, vi) {
  markDirty();
  collections[currentIdx].constituents[ci].structure.splice(vi, 1);
  renderVols(ci, collections[currentIdx].constituents[ci].structure);
}

// ── Flush current form → array ────────────────────────────────────────────────
function flushCurrent() {
  if (currentIdx === null) return;
  const c = collections[currentIdx];
  const g = id => document.getElementById(id);
  const v = id => (g(id) ? g(id).value.trim() : '');

  c.sigla              = v('f_sigla');
  c.title              = v('f_title');
  c.alternative_title  = v('f_alt_title');
  c.file_name          = v('f_file_name');
  c.genre              = v('f_genre');
  c.medium             = v('f_medium');
  c.date_created       = v('f_date_created');
  c.collection_base     = v('f_collection_base');
  c.collection_base_url = v('f_collection_base_url');
  c.place_of_production= v('f_place');
  c.coordinates        = v('f_coords');
  c.number_of_lines    = v('f_lines') === '' ? null : +v('f_lines');
  c.classifications    = v('f_class').split(',').map(s => s.trim()).filter(Boolean);
  c.abstract           = g('f_abstract') ? g('f_abstract').innerHTML : (c.abstract || '');

  // Notes
  c.notes = c.notes || {};
  document.querySelectorAll('[data-notekey]').forEach(cb => {
    c.notes[cb.dataset.notekey] = cb.checked ? 'true' : 'false';
  });

  // Constituent descriptions (belt-and-suspenders; also updated live)
  (c.constituents || []).forEach((con, ci) => {
    const el = document.getElementById('con_desc_' + ci);
    if (el) con.general_description = el.innerHTML;
  });
}

// ── New / Delete ──────────────────────────────────────────────────────────────
function newCollection() {
  if (dirty && currentIdx !== null) flushCurrent();
  const blank = {
    file_name: '', sigla: '', title: '', alternative_title: '',
    abstract: '', genre: '', date_created: '', place_of_production: '',
    coordinates: '', classifications: [], medium: '', number_of_lines: null,
    collection_base: '', collection_base_url: '',
    notes: {
      'handlist_Handlist Available': 'false',
      'images_Images Available': 'false',
      'text_Text Available': 'false'
    },
    constituents: []
  };
  collections.push(blank);
  currentIdx = collections.length - 1;
  renderList();
  document.getElementById('btnDelete').disabled = false;
  renderForm(blank);
  markDirty();
}

function deleteCollection() {
  if (currentIdx === null) return;
  const name = collections[currentIdx].title || collections[currentIdx].sigla || 'this collection';
  if (!confirm(`Delete "${name}"? This cannot be undone until saved.`)) return;
  collections.splice(currentIdx, 1);
  currentIdx = collections.length > 0 ? Math.min(currentIdx, collections.length - 1) : null;
  renderList();
  if (currentIdx !== null) {
    renderForm(collections[currentIdx]);
    document.getElementById('btnDelete').disabled = false;
  } else {
    document.getElementById('formPanel').innerHTML = '<p class="text-muted mt-4" style="font-size:.9rem">← Select a collection or create a new one.</p>';
    document.getElementById('btnDelete').disabled = true;
  }
  markDirty();
}

// ── Save ──────────────────────────────────────────────────────────────────────
async function saveAll() {
  if (currentIdx !== null) flushCurrent();
  setStatus('Saving…', '');
  try {
    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('data', JSON.stringify(collections, null, 2));
    const r = await fetch('', { method: 'POST', body: fd });
    const j = await r.json();
    if (j.ok) {
      dirty = false;
      document.getElementById('btnSave').disabled = true;
      setStatus('Saved successfully.', 'ok');
      renderList();
    } else {
      setStatus('Save failed: ' + (j.error || 'unknown error'), 'err');
    }
  } catch(e) {
    setStatus('Network error: ' + e.message, 'err');
  }
}

// ── Dirty tracking ────────────────────────────────────────────────────────────
function markDirty() {
  dirty = true;
  document.getElementById('btnSave').disabled = false;
  setStatus('Unsaved changes', '');
}

function setStatus(msg, cls) {
  document.getElementById('statusMsg').textContent = msg;
  const dot = document.getElementById('statusDot');
  dot.className = 'status-dot' + (cls ? ' ' + cls : '');
}

// ── RTE helper ────────────────────────────────────────────────────────────────
function rte(cmd, val) {
  document.getElementById('f_abstract').focus();
  document.execCommand(cmd, false, val || null);
  markDirty();
}

// ── HTML escape ───────────────────────────────────────────────────────────────
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Warn on unload ────────────────────────────────────────────────────────────
window.addEventListener('beforeunload', e => {
  if (dirty) { e.preventDefault(); e.returnValue = ''; }
});
</script>

<?php endif; ?>
</body>
</html>