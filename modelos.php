<?php
// modelos.php — Catálogo de modelos (UI Pro)
// Fuente para Compras → Productos. Mantiene POST/GET existentes y agrega DataTables + UI moderna.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$permEscritura = in_array($ROL, ['Admin','Gerente']);

// ============ Helpers ============
function texto($s,$n){ return substr(trim($s ?? ''), 0, $n); }
function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function toNull($s){ $s=trim((string)$s); return $s===''? null : $s; }
function toDec($s){ $x=trim((string)$s); return $x===''? null : number_format((float)$x, 2, '.', ''); }

$mensaje = "";

// ============ Crear / editar ============
if ($permEscritura && $_SERVER['REQUEST_METHOD']==='POST') {
  $modo   = $_POST['modo'] ?? 'crear';
  $id     = (int)($_POST['id'] ?? 0);

  // Campos base
  $marca    = texto($_POST['marca']    ?? '', 80);
  $modelo   = texto($_POST['modelo']   ?? '', 80);
  $color    = texto($_POST['color']    ?? '', 50);
  $ram      = texto($_POST['ram']      ?? '', 50);
  $cap      = texto($_POST['capacidad']?? '', 50);
  $codigo   = texto($_POST['codigo_producto'] ?? '', 50);

  // Alineados a productos
  $descripcion       = toNull($_POST['descripcion'] ?? '');
  $nombre_comercial  = texto($_POST['nombre_comercial'] ?? '', 255);
  $compania          = texto($_POST['compania'] ?? '', 100);
  $financiera        = texto($_POST['financiera'] ?? '', 100);
  $fecha_lanzamiento = toNull($_POST['fecha_lanzamiento'] ?? '');
  $precio_lista      = toDec($_POST['precio_lista'] ?? '');
  $tipo_producto     = toNull($_POST['tipo_producto'] ?? '');
  $subtipo           = texto($_POST['subtipo'] ?? '', 50);
  $gama              = toNull($_POST['gama'] ?? '');
  $ciclo_vida        = toNull($_POST['ciclo_vida'] ?? '');
  $abc               = toNull($_POST['abc'] ?? '');
  $operador          = texto($_POST['operador'] ?? '', 50);
  $resurtible        = toNull($_POST['resurtible'] ?? '');

  if ($marca==='' || $modelo==='') {
    $mensaje = "<div class='alert alert-danger'>Marca y Modelo son obligatorios.</div>";
  } else {
    if ($modo==='editar' && $id>0) {
      $stmt = $conn->prepare("
        UPDATE catalogo_modelos
           SET marca=?, modelo=?, color=?, ram=?, capacidad=?, codigo_producto=?,
               descripcion=?, nombre_comercial=?, compania=?, financiera=?, fecha_lanzamiento=?,
               precio_lista=?, tipo_producto=?, subtipo=?, gama=?, ciclo_vida=?, abc=?, operador=?, resurtible=?
         WHERE id=?
      ");
      if (!$stmt) {
        $mensaje = "<div class='alert alert-danger'>Error de preparación: ".$conn->error."</div>";
      } else {
        $stmt->bind_param(
          "sssssssssssssssssssi",
          $marca,$modelo,$color,$ram,$cap,$codigo,
          $descripcion,$nombre_comercial,$compania,$financiera,$fecha_lanzamiento,
          $precio_lista,$tipo_producto,$subtipo,$gama,$ciclo_vida,$abc,$operador,$resurtible,
          $id
        );
        $ok = $stmt->execute();
        $errno = $stmt->errno; $stmt->close();

        if ($ok) {
          $mensaje = "<div class='alert alert-success'>Modelo actualizado.</div>";
        } else {
          if ($errno === 1062) {
            $mensaje = "<div class='alert alert-danger'>
              Duplicado: combinación Marca+Modelo+Color+RAM+Capacidad o Código de producto ya existe.
            </div>";
          } else {
            $mensaje = "<div class='alert alert-danger'>Error al actualizar.</div>";
          }
        }
      }
    } else {
      $stmt = $conn->prepare("
        INSERT INTO catalogo_modelos
          (marca, modelo, color, ram, capacidad, codigo_producto,
           descripcion, nombre_comercial, compania, financiera, fecha_lanzamiento,
           precio_lista, tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible, activo)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
      ");
      if (!$stmt) {
        $mensaje = "<div class='alert alert-danger'>Error de preparación: ".$conn->error."</div>";
      } else {
        $stmt->bind_param(
          "sssssssssssssssssss",
          $marca,$modelo,$color,$ram,$cap,$codigo,
          $descripcion,$nombre_comercial,$compania,$financiera,$fecha_lanzamiento,
          $precio_lista,$tipo_producto,$subtipo,$gama,$ciclo_vida,$abc,$operador,$resurtible
        );
        $ok = $stmt->execute();
        $errno = $stmt->errno; $stmt->close();

        if ($ok) {
          $mensaje = "<div class='alert alert-success'>Modelo creado.</div>";
        } else {
          if ($errno === 1062) {
            $mensaje = "<div class='alert alert-danger'>
              Duplicado: revisa Marca+Modelo+Color+RAM+Capacidad o el Código de producto.
            </div>";
          } else {
            $mensaje = "<div class='alert alert-danger'>Error al crear.</div>";
          }
        }
      }
    }
  }
}

// ============ Activar / inactivar ============
if ($permEscritura && isset($_GET['accion'], $_GET['id']) && $_GET['accion']==='toggle') {
  $id = (int)$_GET['id'];
  if ($id > 0) { $conn->query("UPDATE catalogo_modelos SET activo=IF(activo=1,0,1) WHERE id=$id"); }
  header("Location: modelos.php"); exit();
}

// ============ Cargar para edición ============
$edit = null;
if ($permEscritura && isset($_GET['editar'])) {
  $id = (int)$_GET['editar'];
  if ($id > 0) {
    $res = $conn->query("SELECT * FROM catalogo_modelos WHERE id=$id");
    $edit = $res ? $res->fetch_assoc() : null;
  }
}

// ============ Filtros ============
$estado = $_GET['estado'] ?? 'activos';
$q = texto($_GET['q'] ?? '', 120);

$w = [];
if ($estado==='activos')   $w[]="activo=1";
if ($estado==='inactivos') $w[]="activo=0";
if ($q!=='') {
  $x = $conn->real_escape_string($q);
  $w[] = "(
      marca LIKE '%$x%' OR modelo LIKE '%$x%' OR color LIKE '%$x%'
   OR ram LIKE '%$x%' OR capacidad LIKE '%$x%' OR codigo_producto LIKE '%$x%'
   OR nombre_comercial LIKE '%$x%' OR compania LIKE '%$x%' OR financiera LIKE '%$x%'
   OR operador LIKE '%$x%'
  )";
}
$where = count($w) ? "WHERE ".implode(" AND ",$w) : "";

$list = $conn->query("SELECT * FROM catalogo_modelos $where ORDER BY marca, modelo, color, ram, capacidad");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Catálogo · Modelos — Central 2.0</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/img/favicon.ico?v=7" sizes="any">

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />

  <!-- DataTables -->
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/responsive/2.5.1/css/responsive.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">

  <style>
    body{ background:#f6f7fb; }
    .page-head{ display:flex; align-items:center; justify-content:space-between; gap:16px; margin:18px auto 8px; padding:6px 4px; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .role-chip{ font-size:.8rem; padding:.2rem .55rem; border-radius:999px; background:#eef2ff; color:#3743a5; border:1px solid #d9e0ff; }
    .card-soft{ border:1px solid #e9ecf1; border-radius:16px; box-shadow:0 2px 12px rgba(16,24,40,.06); }
    .table-wrap{ background:#fff; border:1px solid #e9ecf1; border-radius:16px; padding:8px 8px 16px; box-shadow:0 2px 10px rgba(16,24,40,.06); }
    .chip{ display:inline-flex; align-items:center; gap:6px; padding:2px 10px; border-radius:999px; background:#f1f5f9; color:#0f172a; font-size:.8rem; border:1px solid #e2e8f0; }
    .cell-tight{ white-space:nowrap; }
    .name-secondary{ font-size:.8rem; color:#6b7280; }
    .sku{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size:.85rem; }
  </style>
</head>
<body>
<div class="container-fluid px-3 px-lg-4">

  <!-- Encabezado -->
  <div class="page-head">
    <div>
      <h2 class="page-title">📚 Catálogo de Modelos</h2>
      <div class="mt-1"><span class="role-chip"><?= esc($ROL) ?></span></div>
    </div>
    <div class="d-flex gap-2">
      <?php if ($permEscritura): ?>
        <button class="btn btn-success btn-sm rounded-pill" data-bs-toggle="modal" data-bs-target="#mdlModelo" id="btnNuevo">
          <i class="bi bi-plus-circle me-1"></i>Nuevo
        </button>
        <a href="modelos_carga.php" class="btn btn-outline-primary btn-sm rounded-pill">
          <i class="bi bi-upload me-1"></i>Carga masiva CSV
        </a>
      <?php endif; ?>
      <a href="compras_nueva.php" class="btn btn-light btn-sm rounded-pill border">
        <i class="bi bi-bag-plus me-1"></i>Ir a compras
      </a>
    </div>
  </div>

  <!-- Filtros -->
  <div class="card card-soft mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <span class="fw-semibold"><i class="bi bi-sliders me-1"></i>Filtros</span>
      <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#filtrosBody">Mostrar/Ocultar</button>
    </div>
    <div id="filtrosBody" class="card-body collapse show">
      <form class="row g-2 align-items-center">
        <div class="col-md-3">
          <label class="form-label">Estatus</label>
          <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="activos"   <?= $estado==='activos'?'selected':'' ?>>Activos</option>
            <option value="inactivos" <?= $estado==='inactivos'?'selected':'' ?>>Inactivos</option>
            <option value="todos"     <?= $estado==='todos'?'selected':'' ?>>Todos</option>
          </select>
        </div>
        <div class="col-md-7">
          <label class="form-label">Búsqueda</label>
          <input name="q" class="form-control form-control-sm" placeholder="Marca, modelo, código, compañía, financiera u operador" value="<?= esc($q) ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Buscar</button>
        </div>
      </form>
    </div>
  </div>

  <?= $mensaje ?>

  <!-- Tabla -->
  <div class="table-wrap">
    <div class="d-flex justify-content-between align-items-center p-2">
      <h6 class="m-0">Modelos</h6>
      <div class="d-flex gap-2">
        <button id="btnExportExcel" class="btn btn-success btn-sm rounded-pill"><i class="bi bi-file-earmark-excel me-1"></i>Exportar Excel</button>
        <button id="btnExportCSV" class="btn btn-light btn-sm rounded-pill border"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
        <button id="btnColVis" class="btn btn-light btn-sm rounded-pill border"><i class="bi bi-view-list me-1"></i>Columnas</button>
      </div>
    </div>

    <div class="table-responsive px-2 pb-2">
      <table id="tablaModelos" class="table table-hover align-middle nowrap" style="width:100%;">
        <thead class="table-light">
          <tr>
            <th class="cell-tight">Marca</th>
            <th>Modelo</th>
            <th class="cell-tight">Color</th>
            <th class="cell-tight">RAM</th>
            <th class="cell-tight">Cap.</th>
            <th class="cell-tight">Código</th>
            <th class="cell-tight">Tipo</th>
            <th class="cell-tight">Gama</th>
            <th class="text-end cell-tight">$ Lista</th>
            <th class="cell-tight">Compañía</th>
            <th class="cell-tight">Financiera</th>
            <th class="text-center cell-tight">Estatus</th>
            <th class="text-end cell-tight">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if($list && $list->num_rows): while($r=$list->fetch_assoc()): ?>
            <tr>
              <td class="cell-tight"><?= esc($r['marca']) ?></td>
              <td style="min-width:220px">
                <div class="fw-semibold"><?= esc($r['modelo']) ?></div>
                <?php if(!empty($r['nombre_comercial'])): ?>
                  <div class="name-secondary"><?= esc($r['nombre_comercial']) ?></div>
                <?php endif; ?>
              </td>
              <td class="cell-tight"><?= esc($r['color']) ?></td>
              <td class="cell-tight"><?= esc($r['ram']) ?></td>
              <td class="cell-tight"><?= esc($r['capacidad']) ?></td>
              <td class="cell-tight">
                <?php if(!empty($r['codigo_producto'])): ?>
                  <span class="sku"><?= esc($r['codigo_producto']) ?></span>
                  <button class="btn btn-link btn-sm py-0 px-1" title="Copiar" onclick="copyText('<?= esc($r['codigo_producto']) ?>');return false;">
                    <i class="bi bi-clipboard"></i>
                  </button>
                <?php endif; ?>
              </td>
              <td class="cell-tight">
                <?php if (!empty($r['tipo_producto'])): ?>
                  <span class="chip"><i class="bi bi-tag"></i>&nbsp;<?= esc($r['tipo_producto']) ?></span>
                <?php endif; ?>
              </td>
              <td class="cell-tight"><?= esc($r['gama'] ?? '') ?></td>
              <td class="text-end cell-tight"><?= $r['precio_lista']!==null ? number_format((float)$r['precio_lista'],2) : '' ?></td>
              <td class="cell-tight"><?= esc($r['compania'] ?? '') ?></td>
              <td class="cell-tight"><?= esc($r['financiera'] ?? '') ?></td>
              <td class="text-center cell-tight">
                <?= ((int)$r['activo'] === 1)
                      ? '<span class="badge bg-success">Activo</span>'
                      : '<span class="badge bg-secondary">Inactivo</span>' ?>
              </td>
              <td class="text-end cell-tight">
                <div class="btn-group">
                  <a class="btn btn-outline-primary btn-sm" href="modelos.php?editar=<?= (int)$r['id'] ?>">
                    <i class="bi bi-pencil-square"></i> Editar
                  </a>
                  <?php if($permEscritura): ?>
                    <a class="btn btn-outline-<?= ((int)$r['activo']===1)?'danger':'success' ?> btn-sm"
                       href="modelos.php?accion=toggle&id=<?= (int)$r['id'] ?>"
                       onclick="return confirm('¿Seguro que deseas <?= ((int)$r['activo']===1)?'inactivar':'activar' ?> este modelo?');">
                       <?= ((int)$r['activo']===1)?'<i class="bi bi-slash-circle"></i> Inactivar':'<i class="bi bi-check-circle"></i> Activar' ?>
                    </a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="13" class="text-center text-muted py-4">Sin modelos</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ================= Modal Crear/Editar ================= -->
<div class="modal fade" id="mdlModelo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?= $edit ? 'Editar modelo' : 'Nuevo modelo' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <form method="POST" id="frmModelo" class="row g-2">
          <input type="hidden" name="modo" value="<?= $edit ? 'editar' : 'crear' ?>">
          <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

          <div class="col-md-6">
            <label class="form-label">Marca *</label>
            <input class="form-control" name="marca" required value="<?= esc($edit['marca'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Modelo *</label>
            <input class="form-control" name="modelo" required value="<?= esc($edit['modelo'] ?? '') ?>">
          </div>

          <div class="col-md-4">
            <label class="form-label">Color</label>
            <input class="form-control" name="color" value="<?= esc($edit['color'] ?? '') ?>" placeholder="Negro">
          </div>
          <div class="col-md-4">
            <label class="form-label">RAM</label>
            <input class="form-control" name="ram" value="<?= esc($edit['ram'] ?? '') ?>" placeholder="4GB">
          </div>
          <div class="col-md-4">
            <label class="form-label">Capacidad</label>
            <input class="form-control" name="capacidad" value="<?= esc($edit['capacidad'] ?? '') ?>" placeholder="128GB">
          </div>

          <div class="col-12">
            <label class="form-label">Código de producto</label>
            <input class="form-control" name="codigo_producto" value="<?= esc($edit['codigo_producto'] ?? '') ?>">
            <div class="form-text">Debe ser único si lo usas como SKU.</div>
          </div>

          <div class="col-12">
            <label class="form-label">Nombre comercial</label>
            <input class="form-control" name="nombre_comercial" value="<?= esc($edit['nombre_comercial'] ?? '') ?>" placeholder="Galaxy S24 Ultra">
          </div>

          <div class="col-md-6">
            <label class="form-label">Compañía</label>
            <input class="form-control" name="compania" value="<?= esc($edit['compania'] ?? '') ?>" placeholder="AT&T, Telcel...">
          </div>
          <div class="col-md-6">
            <label class="form-label">Financiera</label>
            <input class="form-control" name="financiera" value="<?= esc($edit['financiera'] ?? '') ?>" placeholder="PayJoy, Krediya...">
          </div>

          <div class="col-md-6">
            <label class="form-label">Fecha de lanzamiento</label>
            <input type="date" class="form-control" name="fecha_lanzamiento" value="<?= esc($edit['fecha_lanzamiento'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Precio lista (sugerido)</label>
            <input class="form-control" name="precio_lista" value="<?= esc($edit['precio_lista'] ?? '') ?>" placeholder="0.00">
          </div>

          <div class="col-md-6">
            <label class="form-label">Tipo de producto</label>
            <select class="form-select" name="tipo_producto">
              <?php $tp = $edit['tipo_producto'] ?? 'Equipo'; ?>
              <option value="">(sin definir)</option>
              <option value="Equipo"    <?= $tp==='Equipo'?'selected':'' ?>>Equipo</option>
              <option value="Modem"     <?= $tp==='Modem'?'selected':'' ?>>Modem</option>
              <option value="Accesorio" <?= $tp==='Accesorio'?'selected':'' ?>>Accesorio</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Subtipo</label>
            <input class="form-control" name="subtipo" value="<?= esc($edit['subtipo'] ?? '') ?>" placeholder="p. ej. Smartphone">
          </div>

          <div class="col-md-6">
            <label class="form-label">Gama</label>
            <?php $gm = $edit['gama'] ?? ''; ?>
            <select class="form-select" name="gama">
              <option value="">(sin definir)</option>
              <?php
                $gamas = ['Ultra baja','Baja','Media baja','Media','Media alta','Alta','Premium'];
                foreach ($gamas as $g) {
                  $sel = ($gm===$g)?'selected':''; echo "<option value=\"".esc($g)."\" $sel>".esc($g)."</option>";
                }
              ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Ciclo de vida</label>
            <?php $cv = $edit['ciclo_vida'] ?? ''; ?>
            <select class="form-select" name="ciclo_vida">
              <option value="">(sin definir)</option>
              <option value="Nuevo"        <?= $cv==='Nuevo'?'selected':'' ?>>Nuevo</option>
              <option value="Linea"        <?= $cv==='Linea'?'selected':'' ?>>Línea</option>
              <option value="Fin de vida"  <?= $cv==='Fin de vida'?'selected':'' ?>>Fin de vida</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">ABC</label>
            <?php $abcv = $edit['abc'] ?? ''; ?>
            <select class="form-select" name="abc">
              <option value="">(sin definir)</option>
              <option value="A" <?= $abcv==='A'?'selected':'' ?>>A</option>
              <option value="B" <?= $abcv==='B'?'selected':'' ?>>B</option>
              <option value="C" <?= $abcv==='C'?'selected':'' ?>>C</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Operador</label>
            <input class="form-control" name="operador" value="<?= esc($edit['operador'] ?? '') ?>" placeholder="AT&T, Telcel...">
          </div>
          <div class="col-md-4">
            <label class="form-label">Resurtible</label>
            <?php $rs = $edit['resurtible'] ?? 'Sí'; ?>
            <select class="form-select" name="resurtible">
              <option value="Sí" <?= $rs==='Sí'?'selected':'' ?>>Sí</option>
              <option value="No" <?= $rs==='No'?'selected':'' ?>>No</option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Descripción</label>
            <textarea class="form-control" name="descripcion" rows="3" placeholder="Notas o especificaciones del modelo..."><?= esc($edit['descripcion'] ?? '') ?></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <?php if ($permEscritura): ?>
          <button form="frmModelo" class="btn btn-success"><?= $edit ? 'Actualizar' : 'Guardar' ?></button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

<!-- DataTables core + addons -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.1/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.1/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

<script>
  // Título
  try { document.title = 'Catálogo · Modelos — Central 2.0'; } catch(e){}

  // Copiar al portapapeles
  function copyText(txt) {
    navigator.clipboard?.writeText(txt).then(()=> {
      // toast ligero
      const el = document.createElement('div');
      el.textContent = 'Copiado: ' + txt;
      el.className = 'position-fixed top-0 start-50 translate-middle-x bg-dark text-white px-3 py-1 rounded-3 mt-2';
      el.style.zIndex = 9999;
      document.body.appendChild(el);
      setTimeout(()=>el.remove(), 1500);
    });
  }
  window.copyText = copyText;

  // DataTable
  let dt = null;
  $(function(){
    dt = $('#tablaModelos').DataTable({
      pageLength: 25,
      order: [[ 0, 'asc' ], [1, 'asc'] ],
      fixedHeader: true,
      responsive: true,
      language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
      dom: "<'row align-items-center mb-2'<'col-sm-12 col-md-6'B><'col-sm-12 col-md-6'f>>" +
           "tr" +
           "<'row mt-2'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
      buttons: [
        { extend: 'csvHtml5',   className: 'btn btn-light btn-sm rounded-pill border buttons-csv',   text: '<i class="bi bi-filetype-csv me-1"></i>CSV' },
        { extend: 'excelHtml5', className: 'btn btn-light btn-sm rounded-pill border buttons-excel', text: '<i class="bi bi-file-earmark-excel me-1"></i>Excel' },
        { extend: 'colvis',     className: 'btn btn-light btn-sm rounded-pill border buttons-colvis', text: '<i class="bi bi-view-list me-1"></i>Columnas' }
      ],
      columnDefs: [
        { targets: [8], render: $.fn.dataTable.render.number('.', ',', 2, '$') },
        { targets: [0,2,3,4,5,6,7,8,9,10,11,12], className: 'cell-tight' }
      ]
    });

    // Botones externos (toolbar)
    $('#btnExportExcel').on('click', ()=> dt.button('.buttons-excel').trigger());
    $('#btnExportCSV').on('click',   ()=> dt.button('.buttons-csv').trigger());
    $('#btnColVis').on('click',      ()=> dt.button('.buttons-colvis').trigger());
  });

  // Auto abrir modal si venimos con editar o tras POST
  (function () {
    <?php if ($permEscritura && ($edit || ($_SERVER['REQUEST_METHOD']==='POST'))): ?>
      const mdl = new bootstrap.Modal(document.getElementById('mdlModelo'));
      mdl.show();
    <?php endif; ?>
  })();
</script>
</body>
</html>
