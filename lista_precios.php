<?php
// lista_precios.php (buscador+filtros, promos con color, sort por promoción por defecto)
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol        = $_SESSION['rol'] ?? '';
$puedeEditar = in_array($rol, ['Admin'], true);

// --------- Query agrupada por marca/modelo/capacidad ---------
$sql = "
  SELECT 
    p.marca,
    p.modelo,
    COALESCE(p.capacidad, '') AS capacidad,
    COUNT(*) AS disponibles_global,
    SUM(CASE WHEN i.id_sucursal = ? THEN 1 ELSE 0 END) AS disponibles_sucursal,
    MAX(p.precio_lista) AS precio_lista,
    MAX(pc.precio_combo) AS precio_combo,
    MAX(pc.promocion)    AS promocion
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  LEFT JOIN precios_combo pc
    ON pc.marca = p.marca AND pc.modelo = p.modelo 
   AND COALESCE(pc.capacidad,'') = COALESCE(p.capacidad,'')
  WHERE i.estatus = 'Disponible'
  GROUP BY p.marca, p.modelo, COALESCE(p.capacidad,'')
  ORDER BY p.marca ASC, p.modelo ASC, capacidad ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $idSucursal);
$stmt->execute();
$res = $stmt->get_result();
$datos = $res->fetch_all(MYSQLI_ASSOC);

// Helpers
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return is_null($n) ? null : number_format((float)$n, 2); }

/** Colores para la promo según texto */
function promoBadgeClass(string $txt): string {
  $t = mb_strtolower(trim($txt), 'UTF-8');
  if ($t === '') return 'promo-none';
  if (preg_match('/%|desc|descuento/', $t)) return 'promo-green';
  if (preg_match('/combo|kit/', $t))       return 'promo-orange';
  if (preg_match('/gratis|regalo/', $t))   return 'promo-purple';
  if (preg_match('/liquidaci[oó]n|remate/', $t)) return 'promo-red';
  return 'promo-blue';
}

// Opciones filtros
$marcas = []; $capacidades = [];
foreach ($datos as $r){
  $marcas[$r['marca']] = true;
  $capacidades[$r['capacidad'] === '' ? '—' : $r['capacidad']] = true;
}
ksort($marcas, SORT_NATURAL | SORT_FLAG_CASE);
ksort($capacidades, SORT_NATURAL | SORT_FLAG_CASE);

$ultima = date('Y-m-d H:i');
?>
<!DOCTYPE html>
<html lang="es-MX">
<head>
  <meta charset="UTF-8">
  <title>Lista de Precios — Luga</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{ background:#F5F7FA; color:#0B1220; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; }
    .page-title{ display:flex; align-items:center; gap:.75rem; }
    .page-title .emoji{ font-size:1.6rem; }

    .card-soft{ background:#fff; border:1px solid #E5E7EB; border-radius:14px; box-shadow:0 6px 18px rgba(17,24,39,.06); }
    .filters .form-select, .filters .form-control{ background:#fff; color:#0B1220; border-color:#D1D5DB; }

    .chip{ display:inline-flex; align-items:center; gap:.5rem; background:#E5E7EB; color:#000; padding:.38rem .7rem; border-radius:999px; font-size:.86rem; font-weight:600; border:1px solid #D1D5DB; }

    /* Pills / badges con texto NEGRO */
    .badge-muted{ background:#F3F4F6; color:#000 !important; border:1px solid #E5E7EB; font-weight:600; }
    .pill-ok{ background:#DCFCE7; color:#000 !important; border:1px solid #86EFAC; font-weight:700; }
    .pill-warn{ background:#FEF3C7; color:#000 !important; border:1px solid #FDE68A; font-weight:700; }

    /* Promos por tipo – texto negro */
    .promo-blue   { background:#DBEAFE; color:#000 !important; border:1px solid #BFDBFE; font-weight:600; }
    .promo-green  { background:#BBF7D0; color:#000 !important; border:1px solid #86EFAC; font-weight:600; }
    .promo-orange { background:#FED7AA; color:#000 !important; border:1px solid #FDBA74; font-weight:600; }
    .promo-purple { background:#E9D5FF; color:#000 !important; border:1px solid #D8B4FE; font-weight:600; }
    .promo-red    { background:#FECACA; color:#000 !important; border:1px solid #FCA5A5; font-weight:600; }
    .promo-none   { color:#6B7280 !important; }

    .table thead th{ position:sticky; top:0; z-index:5; background:#fff; border-bottom:1px solid #D1D5DB; font-weight:700; white-space:nowrap; }
    .table-hover tbody tr:hover{ background:#F9FAFB; }
    .th-sort{ cursor:pointer; white-space:nowrap; }
    .actions .btn{ white-space:nowrap; }

    .table-wrap{ overflow:auto; }
  </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>

<div class="container py-4">
  <div class="page-header mb-3">
    <div class="page-title">
      <span class="emoji">📋</span>
      <div>
        <h3 class="mb-0">Lista de precios por modelo</h3>
        <div class="text-muted small">Mostrando solo equipos <strong>Disponibles</strong>. Última actualización: <?= esc($ultima) ?></div>
      </div>
    </div>
    <div class="controls-right no-print d-flex gap-2">
      <button id="btnExport" class="btn btn-outline-primary btn-sm"><i class="bi bi-filetype-csv me-1"></i> Exportar CSV</button>
      <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i> Imprimir</button>
    </div>
  </div>

  <!-- Filtros y buscador -->
  <div class="card-soft p-3 mb-3">
    <div class="filters row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label mb-1">Marca</label>
        <select id="fMarca" class="form-select form-select-sm">
          <option value="">Todas</option>
          <?php foreach(array_keys($marcas) as $m): ?>
            <option value="<?= esc($m) ?>"><?= esc($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label mb-1">Capacidad</label>
        <select id="fCapacidad" class="form-select form-select-sm">
          <option value="">Todas</option>
          <?php foreach(array_keys($capacidades) as $c): ?>
            <option value="<?= esc($c) ?>"><?= esc($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label mb-1">Buscar</label>
        <input id="fSearch" type="search" class="form-control form-control-sm" placeholder="Modelo, marca, promo…">
      </div>
      <div class="col-6 col-md-1">
        <div class="form-check form-switch mt-4">
          <input class="form-check-input" type="checkbox" id="onlySucursal">
          <label class="form-check-label small" for="onlySucursal">Solo mi sucursal</label>
        </div>
      </div>
      <div class="col-6 col-md-2">
        <div class="form-check form-switch mt-4">
          <input class="form-check-input" type="checkbox" id="onlyCombo">
          <label class="form-check-label small" for="onlyCombo">Solo con combo</label>
        </div>
      </div>
    </div>
  </div>

  <div class="card-soft p-0">
    <div class="table-wrap">
      <table id="tabla" class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th class="th-sort" data-key="marca">Marca <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="th-sort" data-key="modelo">Modelo <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="th-sort" data-key="capacidad">Capacidad <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="precio_lista_num">Precio lista ($) <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="precio_combo_num">Precio combo ($) <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="th-sort" data-key="promo">Promoción <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-center th-sort" data-key="dispo_global_num">Disp. Global <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-center th-sort" data-key="dispo_suc_num">En mi sucursal <i class="bi bi-arrow-down-up ms-1"></i></th>
            
          </tr>
        </thead>
        <tbody>
          <?php if (!$datos): ?>
            <tr><td colspan="<?= $puedeEditar? '9':'8' ?>" class="text-center text-muted py-4">
              No hay equipos disponibles para mostrar.
            </td></tr>
          <?php else: foreach($datos as $r):
            $marca = $r['marca'];
            $modelo = $r['modelo'];
            $capacidad = $r['capacidad'] === '' ? '—' : $r['capacidad'];
            $pl = money($r['precio_lista']);
            $pc = is_null($r['precio_combo']) ? null : money($r['precio_combo']);
            $promo = trim((string)$r['promocion']);
            $dg = (int)$r['disponibles_global'];
            $ds = (int)$r['disponibles_sucursal'];

            $promoClass = promoBadgeClass($promo);
            $promoTxt = $promo === '' ? '—' : $promo;
            $promoKey = ($promo === '' ? '0|' : '1|') . mb_strtolower($promoTxt,'UTF-8'); // con promo primero
          ?>
          <tr
            data-marca="<?= esc($marca) ?>"
            data-capacidad="<?= esc($capacidad) ?>"
            data-haycombo="<?= $pc===null ? '0' : '1' ?>"
            data-dsuc="<?= $ds ?>"
            data-precio_lista_num="<?= $pl !== null ? (float)$r['precio_lista'] : 0 ?>"
            data-precio_combo_num="<?= $pc !== null ? (float)$r['precio_combo'] : 0 ?>"
            data-dispo_global_num="<?= $dg ?>"
            data-dispo_suc_num="<?= $ds ?>"
            data-promo="<?= esc($promoKey) ?>"
          >
            <td><span class="chip"><?= esc($marca) ?></span></td>
            <td class="fw-semibold"><?= esc($modelo) ?></td>
            <td><span class="badge badge-muted rounded-pill"><?= esc($capacidad) ?></span></td>
            <td class="text-end"><?= $pl===null ? '<span class="text-muted">—</span>' : '$'.$pl ?></td>
            <td class="text-end"><?= $pc===null ? '<span class="text-muted">—</span>' : '$'.$pc ?></td>
            <td>
              <?php if ($promo===''): ?>
                <span class="promo-none">—</span>
              <?php else: ?>
                <span class="badge rounded-pill <?= esc($promoClass) ?>">
                  <i class="bi bi-megaphone me-1"></i><?= esc($promo) ?>
                </span>
              <?php endif; ?>
            </td>
            <td class="text-center"><span class="badge rounded-pill <?= $dg>0 ? 'pill-ok':'badge-muted' ?>"><?= $dg ?></span></td>
            <td class="text-center"><span class="badge rounded-pill <?= $ds>0 ? 'pill-ok':'pill-warn' ?>"><?= $ds ?></span></td>

            <?php if ($puedeEditar): ?>
            <td class="no-print text-center actions">
              <!-- <button class="btn btn-sm btn-outline-primary"
                onclick="openComboModal('<?= esc($marca) ?>','<?= esc($modelo) ?>','<?= esc($capacidad) ?>','<?= $pc===null?'':'$'.$pc ?>','<?= esc($promo) ?>')">
                <i class="bi bi-pencil-square me-1"></i> Editar
              </button> -->
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-3 d-flex flex-wrap gap-2">
    <span class="chip"><i class="bi bi-collection me-1"></i> Modelos: <strong id="statModelos">0</strong></span>
    <span class="chip"><i class="bi bi-box-seam me-1"></i> Total disp. global: <strong id="statGlobal">0</strong></span>
    <span class="chip"><i class="bi bi-shop me-1"></i> Total en mi sucursal: <strong id="statSucursal">0</strong></span>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
  // ---------- Filtros / búsqueda ----------
  const fMarca = document.getElementById('fMarca');
  const fCapacidad = document.getElementById('fCapacidad');
  const fSearch = document.getElementById('fSearch');
  const onlySucursal = document.getElementById('onlySucursal');
  const onlyCombo = document.getElementById('onlyCombo');
  const tbody = document.querySelector('#tabla tbody');

  function textOf(el){ return (el.textContent || '').toLowerCase(); }

  function applyFilters(){
    const marca = (fMarca.value || '').toLowerCase();
    const cap = (fCapacidad.value || '').toLowerCase();
    const q = (fSearch.value || '').toLowerCase();
    const suc = !!(onlySucursal && onlySucursal.checked);
    const combo = !!(onlyCombo && onlyCombo.checked);

    let modelos=0, sumG=0, sumS=0;

    [...tbody.rows].forEach(tr=>{
      const trMarca = (tr.dataset.marca||'').toLowerCase();
      const trCap = (tr.dataset.capacidad||'').toLowerCase();
      const haycombo = tr.dataset.haycombo === '1';
      const dsuc = parseInt(tr.dataset.dsuc||'0',10);
      const full = textOf(tr);

      let ok = true;
      if (marca && trMarca !== marca) ok=false;
      if (cap && trCap !== cap) ok=false;
      if (suc && dsuc <= 0) ok=false;
      if (combo && !haycombo) ok=false;
      if (q && !full.includes(q)) ok=false;

      tr.style.display = ok ? '' : 'none';
      if (ok){
        modelos++;
        sumG += parseInt(tr.dataset.dispo_global_num||'0',10);
        sumS += parseInt(tr.dataset.dispo_suc_num||'0',10);
      }
    });

    document.getElementById('statModelos').textContent = modelos;
    document.getElementById('statGlobal').textContent = sumG;
    document.getElementById('statSucursal').textContent = sumS;
  }

  [fMarca, fCapacidad, fSearch, onlySucursal, onlyCombo].forEach(el=>{
    if (!el) return;
    el.addEventListener('input', applyFilters);
    el.addEventListener('change', applyFilters);
  });

  // ---------- Ordenamiento ----------
  let sortState = { key: null, dir: 1 };
  document.querySelectorAll('.th-sort').forEach(th=>{
    th.addEventListener('click', ()=>{
      const key = th.dataset.key;
      sortState.dir = (sortState.key === key) ? -sortState.dir : 1;
      sortState.key = key;
      sortRows(key, sortState.dir);
      applyFilters(); // conserva filtros
    });
  });

  function sortRows(key, dir){
    const rows = [...tbody.rows];
    rows.sort((a,b)=>{
      if (key === 'promo'){
        const ap = a.dataset.promo || '0|';
        const bp = b.dataset.promo || '0|';
        return ap.localeCompare(bp, 'es', {numeric:true, sensitivity:'base'}) * -dir; // con promo primero
      }
      const va = a.dataset[key] || a.textContent;
      const vb = b.dataset[key] || b.textContent;
      const na = Number(va), nb = Number(vb);
      if (!Number.isNaN(na) && !Number.isNaN(nb)) return (na - nb) * dir;
      return va.localeCompare(vb, 'es', {numeric:true, sensitivity:'base'}) * dir;
    });
    rows.forEach(r=>tbody.appendChild(r));
  }

  // ---------- Export CSV (solo visibles) ----------
  document.getElementById('btnExport').addEventListener('click', ()=>{
    const headers = [];
    document.querySelectorAll('#tabla thead th').forEach(th=>{
      if (!th.classList.contains('no-print')) headers.push(th.innerText.trim());
    });
    const rows = [];
    [...tbody.rows].forEach(tr=>{
      if (tr.style.display === 'none') return;
      const tds = [...tr.cells];
      const vals = [];
      tds.forEach(td=>{
        const isLastActions = td.querySelector('button') !== null;
        if (isLastActions) return;
        vals.push(td.innerText.replace(/\s+/g,' ').trim());
      });
      rows.push(vals);
    });
    const csv = [headers, ...rows].map(r=>r.map(v=>{
      v = v.replace(/"/g,'""'); return `"${v}"`;
    }).join(',')).join('\n');

    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'lista_precios.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  });

  // ---------- Inicial: ordenar por Promoción y aplicar filtros ----------
  sortState = { key:'promo', dir:1 };
  sortRows('promo', 1);
  applyFilters();

  // (Opcional) función para abrir modal combo si habilitas edición
  window.openComboModal = window.openComboModal || function(){};
</script>
</body>
</html>
