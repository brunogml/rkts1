<?php
/**
 * edit_matho.php — rKTs Matho Fragment Collection Editor
 *
 * Edits  ../collections/Ma/Ma.xml
 * Login: ../general/login.txt  (format: "user";"pass";"email")
 *
 * Autofill: if vol or sec is empty when opening an entry, they are
 * pre-filled from the nearest preceding entry that has a value.
 */

session_start();

define('XML_PATH',   __DIR__ . '/../collections/Ma/Ma.xml');
define('LOGIN_PATH', __DIR__ . '/../general/login.txt');

function xp(string $s): string {
    return str_replace(["'", '"'], ["&apos;", "&quot;"], $s);
}
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function is_logged_in(): bool {
    return !empty($_SESSION['matho_user']);
}
function load_xml(): ?DOMDocument {
    if (!file_exists(XML_PATH)) return null;
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true; $dom->preserveWhiteSpace = false;
    if (!$dom->load(XML_PATH)) return null;
    return $dom;
}
function save_xml(DOMDocument $dom): bool { return $dom->save(XML_PATH) !== false; }
function child(DOMElement $el, string $tag): string {
    $n = $el->getElementsByTagName($tag)->item(0);
    return $n ? $n->textContent : '';
}
function set_child(DOMDocument $dom, DOMElement $el, string $tag, string $val): void {
    $n = $el->getElementsByTagName($tag)->item(0);
    if (!$n) { $n = $dom->createElement($tag); $el->appendChild($n); }
    while ($n->firstChild) $n->removeChild($n->firstChild);
    $n->appendChild($dom->createTextNode($val));
}
function all_items(DOMDocument $dom): array {
    $out = [];
    foreach ($dom->getElementsByTagName('item') as $item) {
        $out[] = [
            'ref'=>child($item,'ref'), 'rnb'=>child($item,'rnb'),
            'rkts'=>child($item,'rkts'), 'cat'=>child($item,'cat'),
            'vol'=>child($item,'vol'), 'sec'=>child($item,'sec'),
            'debp'=>child($item,'debp'), 'debf'=>child($item,'debf'), 'debl'=>child($item,'debl'),
            'finp'=>child($item,'finp'), 'finf'=>child($item,'finf'), 'finl'=>child($item,'finl'),
            'tib'=>child($item,'tib'),
        ];
    }
    return $out;
}

$msg = ''; $msg_type = 'success';
$action = $_POST['action'] ?? '';

if ($action === 'login') {
    $u = trim($_POST['username'] ?? ''); $p = trim($_POST['password'] ?? '');
    $ok = false;
    if (file_exists(LOGIN_PATH)) {
        foreach (file(LOGIN_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $parts = str_getcsv($line, ';');
            if (count($parts) >= 2 && trim($parts[0],'"') === $u && trim($parts[1],'"') === $p) { $ok = true; break; }
        }
    }
    if ($ok) { $_SESSION['matho_user'] = $u; }
    else { $msg = 'Invalid credentials.'; $msg_type = 'danger'; }
}

if ($action === 'logout') { session_destroy(); header('Location: '.$_SERVER['PHP_SELF']); exit; }

if ($action === 'save' && is_logged_in()) {
    $ref = trim($_POST['ref'] ?? '');
    $dom = load_xml();
    if ($dom && $ref !== '') {
        $found = false;
        foreach ($dom->getElementsByTagName('item') as $item) {
            if (child($item,'ref') === $ref) {
                $found = true;
                foreach (['rkts','cat','vol','sec','debp','debf','debl','finp','finf','finl','tib'] as $f)
                    set_child($dom, $item, $f, trim($_POST[$f] ?? ''));
                break;
            }
        }
        if ($found && save_xml($dom)) $msg = "Saved <strong>".h($ref)."</strong>.";
        else { $msg = "Could not save — item not found or write error."; $msg_type = 'danger'; }
    }
}

if ($action === 'add' && is_logged_in()) {
    $ref = trim($_POST['ref'] ?? '');
    $dom = load_xml();
    if ($dom && $ref !== '') {
        $exists = false;
        foreach ($dom->getElementsByTagName('item') as $item)
            if (child($item,'ref') === $ref) { $exists = true; break; }
        if ($exists) { $msg = "Ref <strong>".h($ref)."</strong> already exists."; $msg_type = 'warning'; }
        else {
            $nl = $dom->getElementsByTagName('item'); $last_rnb = 0;
            for ($i = $nl->length-1; $i >= 0; $i--) { $v=(int)child($nl->item($i),'rnb'); if ($v>0){$last_rnb=$v;break;} }
            $rnb = $last_rnb + 1;
            $newItem = $dom->createElement('item');
            $dom->documentElement->appendChild($dom->createTextNode("\n  "));
            $dom->documentElement->appendChild($newItem);
            $dom->documentElement->appendChild($dom->createTextNode("\n"));
            foreach (['ref'=>$ref,'rnb'=>(string)$rnb,'rkts','cat','vol','sec','debp','debf','debl','finp','finf','finl','tib'] as $k=>$v) {
                if (is_int($k)) { $tag=$v; $val=trim($_POST[$v]??''); } else { $tag=$k; $val=$v; }
                $el=$dom->createElement($tag); $el->appendChild($dom->createTextNode($val)); $newItem->appendChild($el);
            }
            if (save_xml($dom)) $msg = "Added <strong>".h($ref)."</strong> (rnb $rnb).";
            else { $msg = "Write error."; $msg_type = 'danger'; }
        }
    } else { $msg = "Ref is required."; $msg_type = 'warning'; }
}

if ($action === 'delete' && is_logged_in()) {
    $ref = trim($_POST['ref'] ?? '');
    $dom = load_xml();
    if ($dom && $ref !== '') {
        $found = false;
        foreach ($dom->getElementsByTagName('item') as $item)
            if (child($item,'ref') === $ref) { $item->parentNode->removeChild($item); $found = true; break; }
        if ($found && save_xml($dom)) $msg = "Deleted <strong>".h($ref)."</strong>.";
        else { $msg = "Item not found or write error."; $msg_type = 'danger'; }
    }
}

$dom   = load_xml();
$items = $dom ? all_items($dom) : [];
$total = count($items);
$cat_label = ['K'=>'Kanjur','T'=>'Tanjur','G'=>'Old Tantra','B'=>'Bon',''=>'Unidentified'];
$focus_ref = trim($_GET['ref'] ?? ($_POST['ref'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>rKTs — Edit Matho (Ma)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="icon" type="image/png" sizes="32x32" href="../configuration/favicon-32x32.png">
    <style>
        body { background: #f4f6fa; }
        .navbar { background: #e8edf2 !important; }
        .sidebar {
            position: sticky; top: 0; height: 100vh; overflow-y: auto;
            background: #fff; border-right: 1px solid #dee2e6; font-size: .82rem;
        }
        .sidebar-item {
            display: flex; align-items: baseline; gap: .4rem;
            padding: .2rem .75rem; cursor: pointer;
            border-left: 3px solid transparent; color: #444;
        }
        .sidebar-item:hover  { background: #f0f4ff; }
        .sidebar-item.active { background: #e7efff; border-left-color: #1d447a; font-weight: 600; }
        .sidebar-ref { font-weight: 600; color: #1d447a; white-space: nowrap; min-width: 4.5rem; }
        .sidebar-tib { color: #777; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 16rem; }
        .sec-header {
            background: #e9ecef; font-size: .75rem; font-weight: 600;
            letter-spacing: .04em; text-transform: uppercase;
            color: #6c757d; padding: .25rem .75rem; margin-top: .25rem;
        }
        .editor-panel { max-width: 860px; }
        .field-label  { font-size: .8rem; font-weight: 600; color: #555; margin-bottom: .15rem; }
        .folio-group  { display: flex; gap: .4rem; }
        .folio-group input { width: 5rem; }
        .folio-group input.line { width: 3.5rem; }
        .tib-area { font-family: 'Jomolhari','Tibetan Machine Uni',serif; font-size: .95rem; min-height: 5rem; }
        .ref-badge { font-size: .95rem; font-weight: 700; color: #1d447a; }
        .rkts-link  { font-size: .8rem; }
        #sidebar-search { font-size: .8rem; }
        .btn-delete { color: #dc3545; }
        .btn-delete:hover { background: #fff0f0; }
        .empty-state { color: #aaa; font-style: italic; font-size: .9rem; }
        /* autofill */
        .autofill-hint { font-size: .72rem; color: #0d6efd; margin-top: .15rem; display: none; }
        input.autofilled, select.autofilled { border-color: #9ec5fe !important; background-color: #f0f5ff !important; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light px-3 py-2">
    <a class="navbar-brand" href="index.html">
        <img src="../configuration/rkts-logo.png" alt="rKTs" height="48">
    </a>
    <span class="fw-bold ms-2" style="color:#1d447a">Edit — Matho Collection (Ma)</span>
    <div class="ms-auto d-flex align-items-center gap-3">
        <span class="text-muted small"><?= $total ?> entries</span>
        <?php if (is_logged_in()): ?>
            <span class="badge bg-success"><i class="bi bi-person-check me-1"></i><?= h($_SESSION['matho_user']) ?></span>
            <form method="post" class="mb-0">
                <input type="hidden" name="action" value="logout">
                <button class="btn btn-sm btn-outline-secondary">Logout</button>
            </form>
        <?php else: ?>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#loginModal">
                <i class="bi bi-lock me-1"></i>Login
            </button>
        <?php endif; ?>
    </div>
</nav>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg_type ?> alert-dismissible fade show mx-3 mt-2 mb-0 py-2" role="alert">
    <?= $msg ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="container-fluid px-0">
<div class="row g-0" style="min-height:calc(100vh - 60px)">

    <!-- Sidebar -->
    <div class="col-auto sidebar" style="width:280px">
        <div class="p-2 border-bottom">
            <input type="search" id="sidebar-search" class="form-control form-control-sm"
                   placeholder="Filter by ref or title…">
        </div>
        <?php
        $prev_cat = null;
        foreach ($items as $it):
            $cat_key = $it['cat'] ?: '';
            if ($cat_key !== $prev_cat): $prev_cat = $cat_key; $lbl = $cat_label[$cat_key] ?? $cat_key; ?>
            <div class="sec-header"><?= h($lbl) ?></div>
        <?php endif; ?>
            <div class="sidebar-item<?= ($it['ref']===$focus_ref)?' active':'' ?>"
                 data-ref="<?= h($it['ref']) ?>"
                 data-tib="<?= h(mb_strtolower($it['tib'])) ?>"
                 onclick="loadItem(<?= htmlspecialchars(json_encode($it), ENT_QUOTES) ?>)">
                <span class="sidebar-ref"><?= h($it['ref']) ?></span>
                <span class="sidebar-tib" title="<?= h($it['tib']) ?>"><?= h(mb_substr($it['tib'],0,60)) ?></span>
            </div>
        <?php endforeach; ?>
        <?php if (is_logged_in()): ?>
        <div class="p-2 mt-2 border-top">
            <button class="btn btn-sm btn-outline-success w-100" onclick="openAddForm()">
                <i class="bi bi-plus-circle me-1"></i>Add new entry
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Main panel -->
    <div class="col p-4">
        <div class="editor-panel mx-auto">

            <div id="empty-state" class="text-center py-5 empty-state">
                <i class="bi bi-collection fs-1 text-muted d-block mb-2"></i>
                Select an entry from the sidebar to edit it,<br>
                or use "Add new entry" to create one.
            </div>

            <div id="edit-form" style="display:none">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="ref-badge" id="display-ref"></span>
                    <span id="rkts-link-area"></span>
                    <span class="ms-auto text-muted small">rnb: <span id="display-rnb"></span></span>
                </div>

                <form method="post" id="save-form">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="ref"    id="field-ref">

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <div class="field-label">rKTs #</div>
                            <input type="text" name="rkts" id="field-rkts"
                                   class="form-control form-control-sm" placeholder="e.g. 1">
                        </div>
                        <div class="col-md-3">
                            <div class="field-label">Cat</div>
                            <select name="cat" id="field-cat" class="form-select form-select-sm">
                                <option value="">—</option>
                                <option value="K">K — Kanjur</option>
                                <option value="T">T — Tanjur</option>
                                <option value="G">G — Old Tantra</option>
                                <option value="B">B — Bon</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <div class="field-label">Vol (BDRC ID)</div>
                            <input type="text" name="vol" id="field-vol"
                                   class="form-control form-control-sm" placeholder="e.g. MW1BL9">
                            <div class="autofill-hint" id="hint-vol"></div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <div class="field-label">Section</div>
                            <input type="text" name="sec" id="field-sec"
                                   class="form-control form-control-sm" placeholder="e.g. 1">
                            <div class="autofill-hint" id="hint-sec"></div>
                        </div>
                        <div class="col-md-4">
                            <div class="field-label">Start folio</div>
                            <div class="folio-group">
                                <input type="text" name="debp" id="field-debp"
                                       class="form-control form-control-sm" placeholder="p">
                                <select name="debf" id="field-debf" class="form-select form-select-sm" style="width:4.5rem">
                                    <option value="">—</option>
                                    <option value="a">a</option>
                                    <option value="b">b</option>
                                </select>
                                <input type="text" name="debl" id="field-debl"
                                       class="form-control form-control-sm line" placeholder="l">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="field-label">End folio</div>
                            <div class="folio-group">
                                <input type="text" name="finp" id="field-finp"
                                       class="form-control form-control-sm" placeholder="p">
                                <select name="finf" id="field-finf" class="form-select form-select-sm" style="width:4.5rem">
                                    <option value="">—</option>
                                    <option value="a">a</option>
                                    <option value="b">b</option>
                                </select>
                                <input type="text" name="finl" id="field-finl"
                                       class="form-control form-control-sm line" placeholder="l">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="field-label">Description / Notes (tib)</div>
                        <textarea name="tib" id="field-tib" rows="4"
                                  class="form-control tib-area"></textarea>
                    </div>

                    <?php if (is_logged_in()): ?>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy me-1"></i>Save
                        </button>
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="clearForm()">Cancel</button>
                        <button type="button" class="btn btn-outline-danger ms-auto btn-delete"
                                onclick="confirmDelete()">
                            <i class="bi bi-trash me-1"></i>Delete
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning py-2 small mb-0">
                        <i class="bi bi-lock me-1"></i>Read-only — log in to edit.
                    </div>
                    <?php endif; ?>
                </form>

                <form method="post" id="delete-form" style="display:none">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="ref"    id="delete-ref">
                </form>
            </div><!-- /#edit-form -->

            <div id="add-form" style="display:none">
                <h5 class="mb-3"><i class="bi bi-plus-circle me-2 text-success"></i>Add new entry</h5>
                <form method="post">
                    <input type="hidden" name="action" value="add">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <div class="field-label">Ref <span class="text-danger">*</span></div>
                            <input type="text" name="ref" required
                                   class="form-control form-control-sm" placeholder="Ma1.2">
                        </div>
                        <div class="col-md-3">
                            <div class="field-label">rKTs #</div>
                            <input type="text" name="rkts" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <div class="field-label">Cat</div>
                            <select name="cat" class="form-select form-select-sm">
                                <option value="">—</option>
                                <option value="K">K — Kanjur</option>
                                <option value="T">T — Tanjur</option>
                                <option value="G">G — Old Tantra</option>
                                <option value="B">B — Bon</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <div class="field-label">Vol</div>
                            <input type="text" name="vol" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <div class="field-label">Section</div>
                            <input type="text" name="sec" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <div class="field-label">Start folio</div>
                            <div class="folio-group">
                                <input type="text" name="debp" class="form-control form-control-sm" placeholder="p">
                                <select name="debf" class="form-select form-select-sm" style="width:4.5rem">
                                    <option value="">—</option>
                                    <option value="a">a</option><option value="b">b</option>
                                </select>
                                <input type="text" name="debl" class="form-control form-control-sm line" placeholder="l">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="field-label">End folio</div>
                            <div class="folio-group">
                                <input type="text" name="finp" class="form-control form-control-sm" placeholder="p">
                                <select name="finf" class="form-select form-select-sm" style="width:4.5rem">
                                    <option value="">—</option>
                                    <option value="a">a</option><option value="b">b</option>
                                </select>
                                <input type="text" name="finl" class="form-control form-control-sm line" placeholder="l">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="field-label">Description / Notes (tib)</div>
                        <textarea name="tib" rows="4" class="form-control tib-area"></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-plus-circle me-1"></i>Add entry
                        </button>
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="clearForm()">Cancel</button>
                    </div>
                </form>
            </div><!-- /#add-form -->

        </div>
    </div>
</div>
</div>

<!-- Login modal -->
<div class="modal fade" id="loginModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0"><i class="bi bi-lock me-1"></i>Login</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-2">
                        <input type="text" name="username" class="form-control form-control-sm"
                               placeholder="Username" autofocus required>
                    </div>
                    <div class="mb-3">
                        <input type="password" name="password" class="form-control form-control-sm"
                               placeholder="Password" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Login</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Full ordered item list emitted by PHP ─────────────────────────────────────
const ITEMS = <?= json_encode(array_values($items), JSON_UNESCAPED_UNICODE) ?>;

// ── Sidebar filter ────────────────────────────────────────────────────────────
document.getElementById('sidebar-search').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.sidebar-item').forEach(el => {
        const ref = (el.dataset.ref || '').toLowerCase();
        const tib = (el.dataset.tib || '').toLowerCase();
        el.style.display = (!q || ref.includes(q) || tib.includes(q)) ? '' : 'none';
    });
    document.querySelectorAll('.sec-header').forEach(hdr => {
        let sib = hdr.nextElementSibling, anyVis = false;
        while (sib && !sib.classList.contains('sec-header')) {
            if (sib.style.display !== 'none' && sib.classList.contains('sidebar-item')) anyVis = true;
            sib = sib.nextElementSibling;
        }
        hdr.style.display = anyVis ? '' : 'none';
    });
});

// ── Autofill helpers ──────────────────────────────────────────────────────────
// Walk ITEMS backwards from currentRef to find nearest predecessor with a value.
function findPrev(currentRef, field) {
    const idx = ITEMS.findIndex(it => it.ref === currentRef);
    if (idx <= 0) return null;
    for (let i = idx - 1; i >= 0; i--) {
        const v = (ITEMS[i][field] || '').trim();
        if (v !== '') return { value: v, fromRef: ITEMS[i].ref };
    }
    return null;
}

// Increment the trailing integer of a vol ID by 1.
// "MW1BL453" -> "MW1BL454",  "I1BL495" -> "I1BL496"
function incrVol(vol) {
    const m = vol.match(/^(.*?)(\d+)$/);
    if (!m) return vol;
    const next = parseInt(m[2], 10) + 1;
    const nextStr = (m[2][0] === '0') ? String(next).padStart(m[2].length, '0') : String(next);
    return m[1] + nextStr;
}

// Fill fieldId from predecessor if the field is currently empty.
// vol: predecessor value +1 (trailing number incremented).
// sec: copied verbatim from predecessor.
// Shows a small hint and light-blue border; clears on first user input.
function applyAutofill(fieldId, hintId, currentRef, field) {
    const el   = document.getElementById(fieldId);
    const hint = document.getElementById(hintId);

    el.classList.remove('autofilled');
    hint.style.display = 'none';
    hint.textContent   = '';

    if (el.value.trim() !== '') return;   // already has a value — leave it alone

    const prev = findPrev(currentRef, field);
    if (!prev) return;

    const filled = (field === 'vol') ? incrVol(prev.value) : prev.value;

    el.value = filled;
    el.classList.add('autofilled');
    if (field === 'vol') {
        hint.innerHTML = '<i class="bi bi-arrow-up-short"></i> '
                       + escH(prev.value) + ' → ' + escH(filled)
                       + ' <span class="text-muted">(' + escH(prev.fromRef) + ')</span>';
    } else {
        hint.innerHTML = '<i class="bi bi-arrow-up-short"></i> from ' + escH(prev.fromRef);
    }
    hint.style.display = 'block';

    el.addEventListener('input', function clear() {
        el.classList.remove('autofilled');
        hint.style.display = 'none';
        el.removeEventListener('input', clear);
    }, { once: true });
}

// ── Load item ─────────────────────────────────────────────────────────────────
function loadItem(it) {
    document.getElementById('empty-state').style.display = 'none';
    document.getElementById('add-form').style.display    = 'none';
    document.getElementById('edit-form').style.display   = '';

    document.getElementById('display-ref').textContent = it.ref;
    document.getElementById('display-rnb').textContent = it.rnb;
    document.getElementById('field-ref').value  = it.ref;
    document.getElementById('field-rkts').value = it.rkts;
    document.getElementById('field-vol').value  = it.vol;
    document.getElementById('field-sec').value  = it.sec;
    document.getElementById('field-debp').value = it.debp;
    document.getElementById('field-debf').value = it.debf;
    document.getElementById('field-debl').value = it.debl;
    document.getElementById('field-finp').value = it.finp;
    document.getElementById('field-finf').value = it.finf;
    document.getElementById('field-finl').value = it.finl;
    document.getElementById('field-tib').value  = it.tib;

    const catSel = document.getElementById('field-cat');
    catSel.value = it.cat;
    catSel.classList.remove('autofilled');

    // Autofill vol and sec if empty
    applyAutofill('field-vol', 'hint-vol', it.ref, 'vol');
    applyAutofill('field-sec', 'hint-sec', it.ref, 'sec');

    // Autofill start folio to 1a if all three fields are empty
    const debpEl = document.getElementById('field-debp');
    const debfEl = document.getElementById('field-debf');
    const deblEl = document.getElementById('field-debl');
    if (debpEl.value.trim() === '' && debfEl.value === '' && deblEl.value.trim() === '') {
        debpEl.value = '1';
        debfEl.value = 'a';
        [debpEl, debfEl].forEach(el => {
            el.classList.add('autofilled');
            el.addEventListener('input', function clear() {
                el.classList.remove('autofilled');
                el.removeEventListener('input', clear);
            }, { once: true });
        });
    }

    // Autofill end folio face to b if empty; focus finp for the page number
    const finpEl = document.getElementById('field-finp');
    const finfEl = document.getElementById('field-finf');
    if (finfEl.value === '') {
        finfEl.value = 'b';
        finfEl.classList.add('autofilled');
        finfEl.addEventListener('input', function clear() {
            finfEl.classList.remove('autofilled');
            finfEl.removeEventListener('input', clear);
        }, { once: true });
    }
    if (finpEl.value.trim() === '') {
        setTimeout(() => finpEl.focus(), 50);
    }

    // rKTs link badge
    const lnkArea = document.getElementById('rkts-link-area');
    if (it.rkts && it.cat) {
        lnkArea.innerHTML = `<a href="../rkts/detail.html?id=${encodeURIComponent(it.cat + it.rkts)}"
            target="_blank" class="badge bg-light border text-secondary text-decoration-none rkts-link">
            ${escH(it.cat)}-${escH(it.rkts)}&nbsp;<i class="bi bi-box-arrow-up-right"></i></a>`;
    } else {
        lnkArea.innerHTML = '';
    }

    document.querySelectorAll('.sidebar-item').forEach(el =>
        el.classList.toggle('active', el.dataset.ref === it.ref));

    document.getElementById('delete-ref').value = it.ref;
}

function openAddForm() {
    document.getElementById('empty-state').style.display = 'none';
    document.getElementById('edit-form').style.display   = 'none';
    document.getElementById('add-form').style.display    = '';
    document.querySelectorAll('.sidebar-item').forEach(el => el.classList.remove('active'));
}

function clearForm() {
    document.getElementById('edit-form').style.display = 'none';
    document.getElementById('add-form').style.display  = 'none';
    document.getElementById('empty-state').style.display = '';
    document.querySelectorAll('.sidebar-item').forEach(el => el.classList.remove('active'));
}

function confirmDelete() {
    const ref = document.getElementById('field-ref').value;
    if (confirm('Delete entry "' + ref + '"? This cannot be undone.'))
        document.getElementById('delete-form').submit();
}

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

<?php if ($focus_ref): ?>
(function() {
    const target = <?= json_encode($focus_ref) ?>;
    const el = document.querySelector(`.sidebar-item[data-ref="${CSS.escape(target)}"]`);
    if (el) el.click();
})();
<?php endif; ?>
</script>
</body>
</html>