<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Matho – Volume List</title>
<style>
  body { font-family: sans-serif; font-size: 14px; margin: 2rem; }
  h1 { font-size: 1.2rem; margin-bottom: 1rem; }
  table { border-collapse: collapse; }
  th, td { padding: .25rem .75rem; text-align: left; border-bottom: 1px solid #ddd; }
  th { background: #f4f4f4; font-weight: 600; }
  td:last-child { color: #555; }
  #status { color: #888; }
</style>
</head>
<body>
<h1>Matho – Volume Pages</h1>
<p id="status">Loading…</p>
<table id="tbl" style="display:none">
  <thead><tr><th>#</th><th>Volume</th><th>Last page</th></tr></thead>
  <tbody id="tbody"></tbody>
</table>
<script>
const BASE = '';
const START = 453, END = 885;

function lastPagination(data) {
  const keys = Object.keys(data);
  if (!keys.length) return '—';
  const last = keys.reduce((a, b) => (+a > +b ? a : b));
  return data[last].pagination || '—';
}

async function load() {
  const tbody = document.getElementById('tbody');
  const status = document.getElementById('status');

  const promises = [];
  for (let n = START; n <= END; n++) {
    const vol = '1BL' + n;
    promises.push(
      fetch(BASE + vol + '.json')
        .then(r => r.ok ? r.json() : null)
        .then(data => ({ vol, page: data ? lastPagination(data) : null }))
        .catch(() => ({ vol, page: null }))
    );
  }

  const results = await Promise.all(promises);
  let n = 0;
  for (const { vol, page } of results) {
    if (page === null) continue;
    n++;
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${n}</td><td>${vol}</td><td>${page}</td>`;
    tbody.appendChild(tr);
  }

  status.style.display = 'none';
  document.getElementById('tbl').style.display = '';
}

load();
</script>
</body>
</html>