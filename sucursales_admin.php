<?php
// sucursales_admin.php — Admin de Sucursales (editar / activar-inactivar / cambiar zona)
// Requisitos: db.php, navbar.php, sesión con rol
// Columnas esperadas en sucursales:
// id, nombre, zona, cuota_semanal, tipo_sucursal, subtipo, activo

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4");

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';

// Ajusta aquí los roles con permiso
$permAdmin = in_array($ROL, ['Admin','Gerente General'], true);
if (!$permAdmin) { http_response_code(403); echo "Sin permiso."; exit(); }

// Helpers
function esc($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function post($k, $def=''){ return $_POST[$k] ?? $def; }

$mensaje = '';
$error   = '';

/* =========================
   Acciones POST
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $accion = post('accion');

  try {

    if ($accion === 'guardar') {
      $id           = (int)post('id', 0);
      $nombre       = trim(post('nombre'));
      $zona         = trim(post('zona'));
      $cuota        = trim(post('cuota_semanal'));
      $tipo_sucursal= trim(post('tipo_sucursal'));
      $subtipo      = trim(post('subtipo'));
      $activo       = (int)post('activo', 1);

      if ($id <= 0) throw new Exception("ID inválido.");
      if ($nombre === '') throw new Exception("El nombre no puede ir vacío.");
      if ($zona === '') throw new Exception("La zona no puede ir vacía.");
      if ($tipo_sucursal === '') throw new Exception("Tipo sucursal requerido.");
      if ($subtipo === '') throw new Exception("Subtipo requerido.");

      // cuota puede ser 0.00
      if ($cuota === '') $cuota = '0';
      if (!is_numeric($cuota)) throw new Exception("Cuota semanal inválida.");

      $cuota = (float)$cuota;
      $activo = ($activo === 1) ? 1 : 0;

      $sql = "UPDATE sucursales
              SET nombre=?, zona=?, cuota_semanal=?, tipo_sucursal=?, subtipo=?, activo=?
              WHERE id=? LIMIT 1";
      $st = $conn->prepare($sql);
      $st->bind_param("ssdssii", $nombre, $zona, $cuota, $tipo_sucursal, $subtipo, $activo, $id);
      $st->execute();

      $mensaje = "Sucursal actualizada ✅";
    }

    if ($accion === 'toggle') {
      $id = (int)post('id', 0);
      if ($id <= 0) throw new Exception("ID inválido.");

      // flip
      $st = $conn->prepare("UPDATE sucursales SET activo = IF(activo=1,0,1) WHERE id=? LIMIT 1");
      $st->bind_param("i", $id);
      $st->execute();

      $mensaje = "Estatus actualizado ✅";
    }

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

/* =========================
   Filtros GET
========================= */
$q       = trim($_GET['q'] ?? '');
$zonaF   = trim($_GET['zona'] ?? '');
$activoF = trim($_GET['activo'] ?? ''); // '', '1', '0'

$where = [];
$params = [];
$types  = '';

if ($q !== '') {
  $where[] = "nombre LIKE ?";
  $params[] = "%{$q}%";
  $types .= "s";
}
if ($zonaF !== '') {
  $where[] = "zona = ?";
  $params[] = $zonaF;
  $types .= "s";
}
if ($activoF === '1' || $activoF === '0') {
  $where[] = "activo = ?";
  $params[] = (int)$activoF;
  $types .= "i";
}

$sql = "SELECT id, nombre, zona, cuota_semanal, tipo_sucursal, subtipo, activo
        FROM sucursales";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY zona, nombre";

$st = $conn->prepare($sql);
if ($params) {
  $st->bind_param($types, ...$params);
}
$st->execute();
$sucursales = $st->get_result()->fetch_all(MYSQLI_ASSOC);

// Para dropdowns:
$zonas = [];
$stz = $conn->prepare("SELECT DISTINCT zona FROM sucursales ORDER BY zona");
$stz->execute();
$r = $stz->get_result();
while($row = $r->fetch_assoc()){
  $zonas[] = $row['zona'];
}

// Catálogos simples (ajusta si quieres otros valores)
$tipos = ['Tienda','Almacen'];
$subtipos = ['Propia','Subdistribuidor','Otro'];

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Sucursales</title>

  <!-- Bootstrap (si ya lo cargas global, puedes quitarlo) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { background:#f6f7fb; }
    .card { border:0; border-radius:16px; box-shadow: 0 10px 30px rgba(20,20,60,.08); }
    .badge-soft { background: rgba(13,110,253,.12); color:#0d6efd; }
    .muted { color:#6c757d; }
    .table thead th { font-size:.85rem; color:#6c757d; }
    .pill { border-radius:999px; }
  </style>
</head>
<body>

<div class="container py-4">

  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <div>
      <h4 class="mb-0">Sucursales</h4>
      <div class="muted">Editar, cambiar zona y activar/inactivar sin borrar historial.</div>
    </div>
  </div>

  <?php if ($mensaje): ?>
    <div class="alert alert-success"><?= esc($mensaje) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
  <?php endif; ?>

  <div class="card p-3 mb-3">
    <form class="row g-2" method="get">
      <div class="col-12 col-md-5">
        <input class="form-control" name="q" value="<?= esc($q) ?>" placeholder="Buscar por nombre…">
      </div>
      <div class="col-6 col-md-3">
        <select class="form-select" name="zona">
          <option value="">Todas las zonas</option>
          <?php foreach($zonas as $z): ?>
            <option value="<?= esc($z) ?>" <?= ($zonaF===$z?'selected':'') ?>><?= esc($z) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select class="form-select" name="activo">
          <option value="">Activas e inactivas</option>
          <option value="1" <?= ($activoF==='1'?'selected':'') ?>>Solo activas</option>
          <option value="0" <?= ($activoF==='0'?'selected':'') ?>>Solo inactivas</option>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-primary">Filtrar</button>
      </div>
    </form>
  </div>

  <div class="card p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light">
          <tr>
            <th style="width:70px">ID</th>
            <th>Nombre</th>
            <th style="width:140px">Zona</th>
            <th style="width:140px">Tipo</th>
            <th style="width:160px">Subtipo</th>
            <th style="width:140px">Cuota semanal</th>
            <th style="width:110px">Estatus</th>
            <th style="width:260px" class="text-end">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if(!$sucursales): ?>
            <tr><td colspan="8" class="text-center py-4 muted">Sin resultados</td></tr>
          <?php endif; ?>

          <?php foreach($sucursales as $s): ?>
            <?php
              $id = (int)$s['id'];
              $activo = (int)$s['activo'] === 1;
            ?>
            <tr>
              <td class="fw-semibold"><?= $id ?></td>
              <td>
                <div class="fw-semibold"><?= esc($s['nombre']) ?></div>
                <div class="muted" style="font-size:.85rem;">
                  <?= $activo ? '<span class="badge badge-soft pill">Visible</span>' : '<span class="badge text-bg-secondary pill">Oculta</span>' ?>
                </div>
              </td>
              <td><?= esc($s['zona']) ?></td>
              <td><?= esc($s['tipo_sucursal']) ?></td>
              <td><?= esc($s['subtipo']) ?></td>
              <td>$<?= number_format((float)$s['cuota_semanal'], 2) ?></td>
              <td>
                <?= $activo
                  ? '<span class="badge text-bg-success pill">Activa</span>'
                  : '<span class="badge text-bg-danger pill">Inactiva</span>' ?>
              </td>
              <td class="text-end">
                <button
                  class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="modal"
                  data-bs-target="#modalEditar"
                  data-id="<?= $id ?>"
                  data-nombre="<?= esc($s['nombre']) ?>"
                  data-zona="<?= esc($s['zona']) ?>"
                  data-tipo="<?= esc($s['tipo_sucursal']) ?>"
                  data-subtipo="<?= esc($s['subtipo']) ?>"
                  data-cuota="<?= esc($s['cuota_semanal']) ?>"
                  data-activo="<?= $activo ? '1' : '0' ?>"
                >Editar</button>

                <form method="post" class="d-inline">
                  <input type="hidden" name="accion" value="toggle">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button class="btn btn-sm <?= $activo ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                          onclick="return confirm('¿Seguro que quieres <?= $activo?'inactivar':'activar' ?> esta sucursal?');">
                    <?= $activo ? 'Inactivar' : 'Activar' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Modal Editar -->
<div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content" method="post">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" id="m_id" value="0">

      <div class="modal-header">
        <h5 class="modal-title">Editar sucursal</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Nombre</label>
            <input class="form-control" name="nombre" id="m_nombre" required>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Zona</label>
            <input class="form-control" name="zona" id="m_zona" placeholder="Ej: Zona 1" required>
            <div class="muted" style="font-size:.85rem;">Tip: respeta el formato que ya usas (Zona 1, Zona 2...)</div>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Tipo sucursal</label>
            <select class="form-select" name="tipo_sucursal" id="m_tipo" required>
              <?php foreach($tipos as $t): ?>
                <option value="<?= esc($t) ?>"><?= esc($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Subtipo</label>
            <select class="form-select" name="subtipo" id="m_subtipo" required>
              <?php foreach($subtipos as $stp): ?>
                <option value="<?= esc($stp) ?>"><?= esc($stp) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Cuota semanal (monto)</label>
            <input type="number" step="0.01" class="form-control" name="cuota_semanal" id="m_cuota" value="0.00" required>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Activo</label>
            <select class="form-select" name="activo" id="m_activo">
              <option value="1">Activa</option>
              <option value="0">Inactiva</option>
            </select>
          </div>

        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
  const modal = document.getElementById('modalEditar');
  modal.addEventListener('show.bs.modal', (event) => {
    const btn = event.relatedTarget;
    if (!btn) return;

    document.getElementById('m_id').value      = btn.getAttribute('data-id') || '0';
    document.getElementById('m_nombre').value  = btn.getAttribute('data-nombre') || '';
    document.getElementById('m_zona').value    = btn.getAttribute('data-zona') || '';
    document.getElementById('m_cuota').value   = btn.getAttribute('data-cuota') || '0.00';

    const tipo = btn.getAttribute('data-tipo') || '';
    const subtipo = btn.getAttribute('data-subtipo') || '';
    const activo = btn.getAttribute('data-activo') || '1';

    // Selects
    const selTipo = document.getElementById('m_tipo');
    const selSub  = document.getElementById('m_subtipo');
    const selAct  = document.getElementById('m_activo');

    if (tipo)   [...selTipo.options].forEach(o => o.selected = (o.value === tipo));
    if (subtipo)[...selSub.options].forEach(o => o.selected = (o.value === subtipo));
    [...selAct.options].forEach(o => o.selected = (o.value === activo));
  });
</script>

</body>
</html>
