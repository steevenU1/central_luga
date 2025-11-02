<?php
// accesorios_regalo_admin.php — Admin/Logística: modelos elegibles para REGALO
// FIX: endpoints AJAX se atienden ANTES de cualquier salida (sin navbar) para evitar "headers already sent"

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit(); }

require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
if (!in_array($ROL, ['Admin','Logistica'], true)) { header('Location: 403.php'); exit(); }

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function jexit($arr){
  // limpiar buffers por si acaso
  while (ob_get_level() > 0) { ob_end_clean(); }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}
function ok($extra=[]){ jexit(array_merge(['ok'=>true], $extra)); }
function bad($msg){ jexit(['ok'=>false, 'error'=>$msg]); }
function column_exists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $rs = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $rs && $rs->num_rows > 0;
}

/* ===== DDL segura (crea tabla whitelist si no existe) ===== */
$conn->query("
  CREATE TABLE IF NOT EXISTS accesorios_regalo_modelos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_producto INT NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_id_producto (id_producto)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ===== Endpoints AJAX (antes de cualquier HTML) ===== */
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action === 'search_products') {
  $q = trim($_GET['q'] ?? '');
  if ($q === '') bad('Término vacío');

  $hasMarca  = column_exists($conn, 'productos', 'marca');
  $hasModelo = column_exists($conn, 'productos', 'modelo');
  $hasColor  = column_exists($conn, 'productos', 'color');
  $hasTipo   = column_exists($conn, 'productos', 'tipo_producto');
  $hasImei1  = column_exists($conn, 'productos', 'imei1');
  $hasImei2  = column_exists($conn, 'productos', 'imei2');

  $nombreExprParts = [];
  if ($hasMarca)  $nombreExprParts[] = "p.marca";
  if ($hasModelo) $nombreExprParts[] = "p.modelo";
  if ($hasColor)  $nombreExprParts[] = "COALESCE(p.color,'')";
  $nombreExpr = empty($nombreExprParts)
    ? "CONCAT('Producto #', p.id)"
    : "TRIM(CONCAT(" . implode(", ' ', ", $nombreExprParts) . "))";

  $filtrosTipo = [];
  if ($hasTipo) { $filtrosTipo[] = "p.tipo_producto='Accesorio'"; }
  if ($hasImei1 && $hasImei2) {
    $filtrosTipo[] = "(COALESCE(p.imei1,'')='' AND COALESCE(p.imei2,'')='')";
  } elseif ($hasImei1) {
    $filtrosTipo[] = "(COALESCE(p.imei1,'')='')";
  }
  $whereTipo = empty($filtrosTipo) ? "1=1" : "(" . implode(" OR ", $filtrosTipo) . ")";

  $likes = [];
  if ($hasMarca)  $likes[] = "p.marca  LIKE CONVERT(? USING utf8mb4)";
  if ($hasModelo) $likes[] = "p.modelo LIKE CONVERT(? USING utf8mb4)";
  if ($hasColor)  $likes[] = "p.color  LIKE CONVERT(? USING utf8mb4)";

  if (empty($likes)) {
    $sql = "
      SELECT p.id AS id_producto, $nombreExpr AS nombre
        FROM productos p
       WHERE $whereTipo AND p.id = ?
       ORDER BY nombre ASC
       LIMIT 25";
    $st = $conn->prepare($sql);
    if (!$st) bad('Error SQL (prep/fallback id): '.$conn->error);
    $idExacto = (int)$q;
    $st->bind_param('i', $idExacto);
  } else {
    $sql = "
      SELECT p.id AS id_producto, $nombreExpr AS nombre
        FROM productos p
       WHERE $whereTipo
         AND (" . implode(" OR ", $likes) . ")
       ORDER BY nombre ASC
       LIMIT 25";
    $st = $conn->prepare($sql);
    if (!$st) bad('Error SQL (prepare): '.$conn->error);
    $qLike = '%'.$q.'%';
    $binds = [];
    for ($i=0; $i<count($likes); $i++) $binds[] = $qLike;
    $types = str_repeat('s', count($binds));
    $st->bind_param($types, ...$binds);
  }

  if (!$st->execute()) bad('Error SQL (exec): '.$st->error);
  $rs = $st->get_result();
  if (!$rs) bad('Error SQL (result): '.$st->error);
  $items = $rs->fetch_all(MYSQLI_ASSOC);
  ok(['items'=>$items]);
}

if ($action === 'add_or_activate') {
  $pid = (int)($_POST['id_producto'] ?? 0);
  if ($pid <= 0) bad('Producto inválido');
  $st = $conn->prepare("INSERT INTO accesorios_regalo_modelos (id_producto, activo)
                        VALUES (?,1)
                        ON DUPLICATE KEY UPDATE activo=VALUES(activo), updated_at=CURRENT_TIMESTAMP");
  if (!$st) bad('Error preparando alta: '.$conn->error);
  $st->bind_param('i', $pid);
  if (!$st->execute()) bad('Error al guardar: '.$st->error);
  ok();
}

if ($action === 'toggle') {
  $pid = (int)($_POST['id_producto'] ?? 0);
  $val = (int)($_POST['activo'] ?? -1);
  if ($pid<=0 || ($val!==0 && $val!==1)) bad('Parámetros inválidos');
  $st = $conn->prepare("UPDATE accesorios_regalo_modelos
                           SET activo=?, updated_at=CURRENT_TIMESTAMP
                         WHERE id_producto=?");
  if (!$st) bad('Error preparando toggle: '.$conn->error);
  $st->bind_param('ii', $val, $pid);
  if (!$st->execute()) bad('Error al actualizar: '.$st->error);
  ok();
}

if ($action === 'remove') {
  $pid = (int)($_POST['id_producto'] ?? 0);
  if ($pid<=0) bad('Producto inválido');
  $st = $conn->prepare("DELETE FROM accesorios_regalo_modelos WHERE id_producto=?");
  if (!$st) bad('Error preparando eliminación: '.$conn->error);
  $st->bind_param('i', $pid);
  if (!$st->execute()) bad('Error al eliminar: '.$st->error);
  ok();
}

if ($action === 'list') {
  $only = isset($_GET['solo_activos']) ? (int)$_GET['solo_activos'] : -1;
  $where = ($only === 1) ? " WHERE arm.activo=1 " : "";
  $sql = "
    SELECT arm.id_producto,
           arm.activo,
           TRIM(CONCAT(COALESCE(p.marca,''),' ',COALESCE(p.modelo,''),' ',COALESCE(p.color,''))) AS nombre
      FROM accesorios_regalo_modelos arm
      LEFT JOIN productos p ON p.id = arm.id_producto
      $where
     ORDER BY arm.activo DESC, nombre ASC";
  $rs = $conn->query($sql);
  $rows = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
  ok(['rows'=>$rows]);
}

/* ===== Si llegamos aquí, es render HTML (no AJAX) ===== */
require_once __DIR__.'/navbar.php';

/* Render inicial */
$primerListado = [];
$init = $conn->query("
  SELECT arm.id_producto, arm.activo,
         TRIM(CONCAT(COALESCE(p.marca,''),' ',COALESCE(p.modelo,''),' ',COALESCE(p.color,''))) AS nombre
    FROM accesorios_regalo_modelos arm
    LEFT JOIN productos p ON p.id = arm.id_producto
   ORDER BY arm.activo DESC, nombre ASC
");
if ($init) { $primerListado = $init->fetch_all(MYSQLI_ASSOC); }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Modelos elegibles para regalo</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:linear-gradient(135deg,#f6f9fc 0%,#edf2f7 100%)}
    .card-ghost{backdrop-filter:saturate(140%) blur(6px); border:1px solid rgba(0,0,0,.06); box-shadow:0 10px 25px rgba(0,0,0,.06)}
    .badge-soft{background:#0d6efd14;border:1px solid #0d6efd2e}
    .muted{color:#6c757d}
    .portal{position:absolute; z-index:1000; background:#fff; border:1px solid #dee2e6; border-radius:.5rem; box-shadow:0 12px 24px rgba(0,0,0,.12); display:none; max-height:260px; overflow:auto;}
    .portal-item{padding:.45rem .6rem; cursor:pointer}
    .portal-item:hover{background:#0d6efd10}
    .status-dot{display:inline-block; width:.6rem; height:.6rem; border-radius:50%; margin-right:.35rem}
    .dot-on{background:#16a34a}
    .dot-off{background:#dc2626}
    .table-sticky thead th{position:sticky; top:0; background:#fff; z-index:1}
    .w-40{width:40%}
    .w-15{width:15%}
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0">Modelos elegibles para <span class="text-success">regalo</span></h3>
      <span class="badge rounded-pill text-secondary badge-soft">Acceso: <?= h($ROL) ?></span>
    </div>
    <div><a href="venta_accesorios.php" class="btn btn-outline-secondary btn-sm">Ir a venta</a></div>
  </div>

  <div class="card card-ghost p-3 mb-3">
    <h5 class="mb-3">Agregar modelo</h5>
    <div class="row g-2 align-items-end position-relative" id="searchRow">
      <div class="col-md-8 position-relative">
        <label class="form-label">Buscar accesorio (marca, modelo o color)</label>
        <input type="text" id="q" class="form-control" autocomplete="off" placeholder="Ej. 'cable lightning'">
        <div id="portal" class="portal"></div>
        <div class="form-text">Solo se listan productos tipo <b>Accesorio</b> o sin IMEI.</div>
      </div>
      <div class="col-md-2">
        <button id="btnAdd" class="btn btn-primary w-100" type="button" disabled>Agregar</button>
      </div>
      <div class="col-md-2">
        <div class="form-check mt-4">
          <input class="form-check-input" type="checkbox" id="cbSoloActivos">
          <label class="form-check-label" for="cbSoloActivos">Ver solo activos</label>
        </div>
      </div>
      <input type="hidden" id="selIdProducto" value="">
    </div>
  </div>

  <div class="card card-ghost p-3">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h5 class="mb-0">Listado</h5>
      <small class="muted">Click en los botones para activar/desactivar o quitar</small>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle table-sticky" id="tbl">
        <thead class="table-light">
          <tr>
            <th class="w-40">Modelo</th>
            <th class="w-15">Estatus</th>
            <th class="w-15 text-center">Acción</th>
            <th class="w-15 text-center">Quitar</th>
          </tr>
        </thead>
        <tbody id="tbody">
          <?php if (empty($primerListado)): ?>
            <tr><td colspan="4" class="text-center text-muted">Sin modelos configurados todavía.</td></tr>
          <?php else: foreach ($primerListado as $r): ?>
            <tr data-id="<?= (int)$r['id_producto'] ?>">
              <td><?= h(trim($r['nombre']) !== '' ? $r['nombre'] : ('Prod #'.(int)$r['id_producto'])) ?></td>
              <td>
                <?php if ((int)$r['activo']===1): ?>
                  <span class="status-dot dot-on"></span> Activo
                <?php else: ?>
                  <span class="status-dot dot-off"></span> Inactivo
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ((int)$r['activo']===1): ?>
                  <button class="btn btn-outline-warning btn-sm btnToggle" data-val="0">Desactivar</button>
                <?php else: ?>
                  <button class="btn btn-outline-success btn-sm btnToggle" data-val="1">Activar</button>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <button class="btn btn-outline-danger btn-sm btnRemove">Quitar</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const $ = sel => document.querySelector(sel);
async function jfetch(url, opt){
  const r = await fetch(url, opt);
  const t = await r.text();
  try { return JSON.parse(t); } catch(e){ throw new Error(t || 'Respuesta inválida'); }
}
function rowHTML(r){
  const activo = Number(r.activo)===1;
  const nombre = (r.nombre && r.nombre.trim()) ? r.nombre : ('Prod #'+r.id_producto);
  return `
    <tr data-id="${r.id_producto}">
      <td>${nombre}</td>
      <td>${activo ? '<span class="status-dot dot-on"></span> Activo' : '<span class="status-dot dot-off"></span> Inactivo'}</td>
      <td class="text-center">
        ${activo
          ? '<button class="btn btn-outline-warning btn-sm btnToggle" data-val="0">Desactivar</button>'
          : '<button class="btn btn-outline-success btn-sm btnToggle" data-val="1">Activar</button>'}
      </td>
      <td class="text-center">
        <button class="btn btn-outline-danger btn-sm btnRemove">Quitar</button>
      </td>
    </tr>`;
}

// Typeahead
const q = $('#q');
const portal = $('#portal');
const btnAdd = $('#btnAdd');
const selHidden = $('#selIdProducto');
let debounceTimer = null;

function closePortal(){ portal.style.display='none'; }
function openPortal(){
  const r = q.getBoundingClientRect();
  portal.style.minWidth = r.width+'px';
  portal.style.left = r.left+'px';
  portal.style.top = (r.bottom + window.scrollY)+'px';
  portal.style.display='block';
}

q.addEventListener('input', ()=>{
  selHidden.value=''; btnAdd.disabled = true;
  const term = q.value.trim();
  clearTimeout(debounceTimer);
  if (term.length < 2){ closePortal(); return; }
  debounceTimer = setTimeout(async ()=>{
    try{
      const res = await jfetch('accesorios_regalo_admin.php?action=search_products&q='+encodeURIComponent(term));
      const items = res.items || [];
      if (items.length===0){
        portal.innerHTML = '<div class="portal-item text-muted">Sin coincidencias</div>'; openPortal(); return;
      }
      portal.innerHTML = items.map(it => `<div class="portal-item" data-id="${it.id_producto}">${it.nombre}</div>`).join('');
      openPortal();
    }catch(e){
      portal.innerHTML = `<div class="portal-item text-danger">${e.message || 'Error en búsqueda'}</div>`; openPortal();
    }
  }, 250);
});

document.addEventListener('click', (ev)=>{
  if (portal.contains(ev.target)){
    const it = ev.target.closest('.portal-item[data-id]');
    if (it){
      selHidden.value = it.dataset.id;
      q.value = it.textContent.trim();
      btnAdd.disabled = false;
      closePortal();
    }
  } else if (ev.target !== q){
    closePortal();
  }
});

// Agregar / activar
btnAdd.addEventListener('click', async ()=>{
  const idp = Number(selHidden.value||0);
  if (!idp){ alert('Selecciona un producto de la lista.'); return; }
  btnAdd.disabled = true;
  try{
    const fd = new FormData();
    fd.append('action','add_or_activate');
    fd.append('id_producto', String(idp));
    const res = await jfetch('accesorios_regalo_admin.php', { method:'POST', body: fd });
    if (!res.ok) throw new Error(res.error||'No se pudo agregar');
    q.value=''; selHidden.value=''; await reloadList();
  }catch(e){ alert(e.message); }
  finally{ btnAdd.disabled = false; }
});

// Listado
const cbSoloActivos = $('#cbSoloActivos');
const tbody = document.querySelector('#tbody');

async function reloadList(){
  try{
    const url = 'accesorios_regalo_admin.php?action=list' + (cbSoloActivos.checked ? '&solo_activos=1' : '');
    const res = await jfetch(url);
    const rows = res.rows || [];
    if (rows.length===0){
      tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Sin modelos configurados.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(rowHTML).join('');
  }catch(e){
    alert('Error cargando listado: '+e.message);
  }
}
cbSoloActivos.addEventListener('change', reloadList);

// Acciones por fila
tbody.addEventListener('click', async (e)=>{
  const tr = e.target.closest('tr[data-id]');
  if (!tr) return;
  const idp = Number(tr.dataset.id||0);

  if (e.target.classList.contains('btnToggle')){
    const val = Number(e.target.dataset.val||0);
    const fd = new FormData();
    fd.append('action','toggle');
    fd.append('id_producto', String(idp));
    fd.append('activo', String(val));
    try{
      const res = await jfetch('accesorios_regalo_admin.php', { method:'POST', body: fd });
      if (!res.ok) throw new Error(res.error||'No se pudo cambiar el estado');
      await reloadList();
    }catch(err){ alert(err.message); }
  }

  if (e.target.classList.contains('btnRemove')){
    if (!confirm('¿Quitar este modelo de la lista de regalo?')) return;
    const fd = new FormData();
    fd.append('action','remove');
    fd.append('id_producto', String(idp));
    try{
      const res = await jfetch('accesorios_regalo_admin.php', { method:'POST', body: fd });
      if (!res.ok) throw new Error(res.error||'No se pudo quitar');
      await reloadList();
    }catch(err){ alert(err.message); }
  }
});
</script>
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
