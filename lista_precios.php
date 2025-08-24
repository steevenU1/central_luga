<?php
// lista_precios.php
session_start();
require_once __DIR__ . '/db.php';

// Asegura sesión
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php"); exit();
}

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
       ON pc.marca = p.marca AND pc.modelo = p.modelo AND COALESCE(pc.capacidad,'') = COALESCE(p.capacidad,'')
  WHERE i.estatus = 'Disponible'
  GROUP BY p.marca, p.modelo, COALESCE(p.capacidad,'')
  ORDER BY p.marca ASC, p.modelo ASC, capacidad ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $idSucursal);
$stmt->execute();
$res = $stmt->get_result();
$datos = $res->fetch_all(MYSQLI_ASSOC);

// Helpers (sin e() para evitar colisión con navbar)
function money($n){ return is_null($n) ? null : number_format((float)$n, 2); }

// Opciones para filtros
$marcas = [];
$capacidades = [];
foreach ($datos as $r){
  $marcas[$r['marca']] = true;
  $cap = $r['capacidad'] === '' ? '—' : $r['capacidad'];
  $capacidades[$cap] = true;
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
    :root{
      --card-bg: #ffffff;
      --chip-bg: #f1f5f9;
      --muted:#6b7280;
    }
    body{ background: #f7f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; }
    .page-title{ display:flex; align-items:center; gap:.75rem; }
    .page-title .emoji{ font-size: 1.6rem; }
    .card-soft{
      background: var(--card-bg);
      border: 1px solid #eef2f7;
      border-radius: 1rem;
      box-shadow: 0 6px 18px rgba(16,24,40,.06);
    }
    .filters .form-select, .filters .form-control{ background:#fff; }
    .chip{
      display:inline-flex; align-items:center; gap:.5rem;
      background: var(--chip-bg); padding:.4rem .7rem; border-radius:999px; font-size:.85rem;
    }
    .table thead th{
      position: sticky; top: 0; z-index: 5;
      background: #ffffff;
      border-bottom: 1px solid #e5e7eb;
    }
    .table-hover tbody tr:hover{ background: #fafafa; }
    .th-sort{ cursor:pointer; white-space:nowrap; }
    .badge-soft{ background:#e8f1ff; color:#1d4ed8; border:1px solid #dbeafe; }
    .badge-muted{ background:#f3f4f6; color:#6b7280; border:1px solid #e5e7eb; }
    .pill-ok{ background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .pill-warn{ background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; }
    .controls-right{ display:flex; gap:.5rem; flex-wrap:wrap; }
    .table-wrap{ overflow:auto; }
    .actions .btn{ white-space:nowrap; }
    @media print{
      .no-print{ display:none !important; }
      .table thead th{ position: static; }
      body{ background:#fff; }
      .card-soft{ border: none; box-shadow:none; }
    }
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
        <div class="text-muted small">Mostrando solo equipos <strong>Disponibles</strong>. Última actualización: <?= e($ultima) ?></div>
      </div>
    </div>
    <div class="controls-right no-print">
      <button id="btnExport" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-filetype-csv me-1"></i> Exportar CSV
      </button>
      <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-printer me-1"></i> Imprimir
      </button>
      <?php if ($puedeEditar): ?>
      <!-- <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCombo"
              onclick="openComboModal('', '', '', '', '')">
        <i class="bi bi-tags me-1"></i> Nuevo combo
      </button> -->
      <?php endif; ?>
    </div>
  </div>

  <div class="card-soft p-3 mb-3">
    <div class="filters row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label mb-1">Marca</label>
        <select id="fMarca" class="form-select form-select-sm">
          <option value="">Todas</option>
          <?php foreach(array_keys($marcas) as $m): ?>
            <option value="<?= e($m) ?>"><?= e($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label mb-1">Capacidad</label>
        <select id="fCapacidad" class="form-select form-select-sm">
          <option value="">Todas</option>
          <?php foreach(array_keys($capacidades) as $c): ?>
            <option value="<?= e($c) ?>"><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label mb-1">Buscar</label>
        <input id="fSearch" type="search" class="form-control form-control-sm" placeholder="Modelo, marca, promo…">
      </div>
      <div class="col-6 col-md-1 d-flex align-items-center gap-2">
        <div class="form-check form-switch mt-3">
          <input class="form-check-input" type="checkbox" id="onlySucursal">
          <label class="form-check-label small" for="onlySucursal">Solo con stock en mi sucursal</label>
        </div>
      </div>
      <div class="col-6 col-md-2 d-flex align-items-center gap-2">
        <div class="form-check form-switch mt-3">
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
            <th>Promoción</th>
            <th class="text-center th-sort" data-key="dispo_global_num">Disp. Global <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-center th-sort" data-key="dispo_suc_num">En mi sucursal <i class="bi bi-arrow-down-up ms-1"></i></th>
            <?php if ($puedeEditar): ?><th class="no-print text-center">Acciones</th><?php endif; ?>
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
          ?>
          <tr
            data-marca="<?= e($marca) ?>"
            data-capacidad="<?= e($capacidad) ?>"
            data-haycombo="<?= $pc===null ? '0' : '1' ?>"
            data-dsuc="<?= $ds ?>"
            data-precio_lista_num="<?= $pl !== null ? (float)$r['precio_lista'] : 0 ?>"
            data-precio_combo_num="<?= $pc !== null ? (float)$r['precio_combo'] : 0 ?>"
            data-dispo_global_num="<?= $dg ?>"
            data-dispo_suc_num="<?= $ds ?>"
          >
            <td><span class="chip"><?= e($marca) ?></span></td>
            <td class="fw-semibold"><?= e($modelo) ?></td>
            <td><span class="badge badge-muted rounded-pill"><?= e($capacidad) ?></span></td>
            <td class="text-end"><?= $pl===null ? '<span class="text-muted">—</span>' : '$'.$pl ?></td>
            <td class="text-end">
              <?php if ($pc===null): ?>
                <span class="text-muted">—</span>
              <?php else: ?>
                <span class="fw-semibold">$<?= $pc ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($promo===''): ?>
                <span class="text-muted">—</span>
              <?php else: ?>
                <span class="badge badge-soft rounded-pill"><i class="bi bi-megaphone me-1"></i><?= e($promo) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <span class="badge rounded-pill <?= $dg>0 ? 'pill-ok':'badge-muted' ?>"><?= $dg ?></span>
            </td>
            <td class="text-center">
              <span class="badge rounded-pill <?= $ds>0 ? 'pill-ok':'pill-warn' ?>"><?= $ds ?></span>
            </td>
            <?php if ($puedeEditar): ?>
            <td class="no-print text-center actions">
              <button class="btn btn-sm btn-outline-primary"
                      onclick="openComboModal('<?= e($marca) ?>','<?= e($modelo) ?>','<?= e($capacidad) ?>','<?= $pc===null?'':'$'.$pc ?>','<?= e($promo) ?>')">
                <i class="bi bi-pencil-square me-1"></i> Editar
              </button>
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

<?php if ($puedeEditar): ?>
<!-- Modal Nuevo/Editar Combo -->
<div class="modal fade" id="modalCombo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form id="formCombo" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-tags me-2"></i><span id="comboTitle">Editar combo</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <label class="form-label mb-1">Marca</label>
            <input name="marca" id="cmbMarca" class="form-control" required>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label mb-1">Modelo</label>
            <input name="modelo" id="cmbModelo" class="form-control" required>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label mb-1">Capacidad</label>
            <input name="capacidad" id="cmbCapacidad" class="form-control" placeholder="— (vacío)">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label mb-1">Precio combo ($)</label>
            <input name="precio_combo" id="cmbPrecio" class="form-control" inputmode="decimal" pattern="^\d+(\.\d{1,2})?$" placeholder="0.00">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label mb-1">Promoción</label>
            <input name="promocion" id="cmbPromo" class="form-control" maxlength="60" placeholder="Texto corto (opcional)">
          </div>
        </div>
        <div class="form-text mt-2">Capacidad “—” se guarda como vacío para empatar con productos sin capacidad.</div>
        <div id="comboMsg" class="mt-2 small"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
  // --------- Filtros y búsqueda ----------
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
    const suc = onlySucursal.checked;
    const combo = onlyCombo.checked;

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
    el && el.addEventListener('input', applyFilters);
    el && el.addEventListener('change', applyFilters);
  });

  // Inicializar stats
  applyFilters();

  // --------- Ordenamiento ----------
  let sortState = { key: null, dir: 1 };
  document.querySelectorAll('.th-sort').forEach(th=>{
    th.addEventListener('click', ()=>{
      const key = th.dataset.key;
      sortState.dir = (sortState.key === key) ? -sortState.dir : 1;
      sortState.key = key;
      sortRows(key, sortState.dir);
    });
  });

  function sortRows(key, dir){
    const rows = [...tbody.rows];
    rows.sort((a,b)=>{
      const va = a.dataset[key] || a.textContent;
      const vb = b.dataset[key] || b.textContent;

      const na = Number(va);
      const nb = Number(vb);
      if (!Number.isNaN(na) && !Number.isNaN(nb)) {
        return (na - nb) * dir;
      }
      return va.localeCompare(vb, 'es', {numeric:true, sensitivity:'base'}) * dir;
    });
    rows.forEach(r=>tbody.appendChild(r));
  }

  // --------- Export CSV (filtrado visible) ----------
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
      tds.forEach((td)=>{
        const isLastActions = td.querySelector('button') !== null;
        if (isLastActions) return;
        vals.push(td.innerText.replace(/\s+/g,' ').trim());
      });
      rows.push(vals);
    });
    const csv = [headers, ...rows].map(r=>r.map(v=>{
      v = v.replace(/"/g,'""'); return `"${v}"`;
    }).join(',')).join('\n');

    const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'lista_precios.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  });

  // --------- Modal Combos (Admin) ----------
  <?php if ($puedeEditar): ?>
  const modalEl = document.getElementById('modalCombo');
  const comboTitle = document.getElementById('comboTitle');
  const formCombo = document.getElementById('formCombo');
  const msg = document.getElementById('comboMsg');

  function openComboModal(marca, modelo, capacidad, precioComboTxt, promo){
    comboTitle.textContent = (marca || modelo || capacidad) ? 'Editar combo' : 'Nuevo combo';
    document.getElementById('cmbMarca').value = marca;
    document.getElementById('cmbModelo').value = modelo;
    document.getElementById('cmbCapacidad').value = (capacidad==='—' ? '' : capacidad);
    document.getElementById('cmbPrecio').value = (precioComboTxt || '').replace('$','');
    document.getElementById('cmbPromo').value = promo || '';
    msg.textContent = '';
  }
  window.openComboModal = openComboModal;

  formCombo.addEventListener('submit', async (e)=>{
    e.preventDefault();
    msg.textContent = 'Guardando…';
    msg.className = 'mt-2 small text-muted';

    const fd = new FormData(formCombo);
    if ((fd.get('capacidad')||'').trim() === '—') fd.set('capacidad','');

    try{
      const resp = await fetch('precios_combo_guardar.php', {
        method: 'POST',
        body: fd,
        headers: {'X-Requested-With':'fetch'}
      });
      const data = await resp.json().catch(()=>({ok:false, error:'Respuesta inválida'}));
      if (!resp.ok || !data.ok){
        throw new Error(data.error || ('HTTP '+resp.status));
      }
      msg.textContent = '¡Guardado!';
      msg.className = 'mt-2 small text-success';

      const marca = (fd.get('marca')||'').toLowerCase();
      const modelo = (fd.get('modelo')||'').toLowerCase();
      const capacidad = (fd.get('capacidad')||'') || '—';
      const precio = (fd.get('precio_combo')||'').trim();
      const promo = (fd.get('promocion')||'').trim();

      [...tbody.rows].forEach(tr=>{
        const okMarca = (tr.dataset.marca||'').toLowerCase() === marca;
        const okCap = (tr.dataset.capacidad||'').toLowerCase() === capacidad.toLowerCase();
        const modeloTxt = (tr.cells[1]?.innerText || '').trim().toLowerCase();
        const okModelo = modeloTxt === modelo;
        if (okMarca && okModelo && okCap){
          const tdPc = tr.cells[4];
          const tdPromo = tr.cells[5];
          if (precio){
            tdPc.innerHTML = '<span class="fw-semibold">$'+ Number(precio).toFixed(2) +'</span>';
            tr.dataset.haycombo = '1';
            tr.dataset.precio_combo_num = String(Number(precio));
          }else{
            tdPc.innerHTML = '<span class="text-muted">—</span>';
            tr.dataset.haycombo = '0';
            tr.dataset.precio_combo_num = '0';
          }
          if (promo){
            tdPromo.innerHTML = '<span class="badge badge-soft rounded-pill"><i class="bi bi-megaphone me-1"></i>'+ escapeHtml(promo) +'</span>';
          }else{
            tdPromo.innerHTML = '<span class="text-muted">—</span>';
          }
        }
      });

      applyFilters();
      setTimeout(()=>{ const m = bootstrap.Modal.getInstance(modalEl); m && m.hide(); }, 600);
    }catch(err){
      msg.textContent = 'Error: '+ err.message;
      msg.className = 'mt-2 small text-danger';
    }
  });

  function escapeHtml(s){
    return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
  }
  <?php endif; ?>
</script>
</body>
</html>
