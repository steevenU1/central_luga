<?php
// admin_esquemas_comisiones_v2.php — Admin UI para editar reglas de comisiones (v2)
// LUGA/NANO compatible — NO toca columnas generated *_key

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ===================== Seguridad ===================== */
$ROL = trim((string)($_SESSION['rol'] ?? ''));
$ALLOW = ['Admin', 'Master Admin']; // <-- ajusta aquí si tu rol se llama distinto
if (!in_array($ROL, $ALLOW, true)) { header("Location: 403.php"); exit(); }

/* ===================== Helpers ===================== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function columnExists(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

function flash_set($k,$v){ $_SESSION['flash_'.$k] = $v; }
function flash_get($k){ $v = $_SESSION['flash_'.$k] ?? ''; unset($_SESSION['flash_'.$k]); return $v; }

/* CSRF simple */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_token'];

/* ===================== Validación de tabla ===================== */
if (!columnExists($conn, 'esquemas_comisiones_v2', 'id')) {
  die("No existe la tabla <b>esquemas_comisiones_v2</b> en esta BD.");
}

/* ===================== Acciones (POST) ===================== */
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($CSRF, (string)$token)) {
      throw new Exception('CSRF inválido, recarga la página e intenta de nuevo.');
    }

    $action = $_POST['action'] ?? '';

    /* ------- Toggle activo ------- */
    if ($action === 'toggle') {
      $id     = (int)($_POST['id'] ?? 0);
      $activo = (int)($_POST['activo'] ?? -1);
      if ($id <= 0 || !in_array($activo, [0,1], true)) throw new Exception('Datos inválidos.');

      $st = $conn->prepare("UPDATE esquemas_comisiones_v2 SET activo=? WHERE id=?");
      $st->bind_param("ii", $activo, $id);
      $st->execute();
      $st->close();

      flash_set('ok', $activo ? "Regla activada." : "Regla desactivada.");
      header("Location: ".$_SERVER['PHP_SELF'].'?'.http_build_query($_GET));
      exit();
    }

    /* ------- Duplicar ------- */
    if ($action === 'duplicar') {
      $id = (int)($_POST['id'] ?? 0);
      $vigente_desde = trim((string)($_POST['vigente_desde'] ?? ''));
      if ($id <= 0) throw new Exception('ID inválido.');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vigente_desde)) throw new Exception('vigente_desde inválido.');

      // Copiamos columnas reales (no generated)
      $sql = "
        INSERT INTO esquemas_comisiones_v2
        (vigente_desde, vigente_hasta, activo, id_sucursal, rol, categoria, subtipo, operador, componente,
         requiere_cuota_unidades, requiere_cuota_monto, precio_min, precio_max, monto_fijo, porcentaje, tope_comision,
         prioridad, nota)
        SELECT
          ?, NULL, 1, id_sucursal, rol, categoria, subtipo, operador, componente,
          requiere_cuota_unidades, requiere_cuota_monto, precio_min, precio_max, monto_fijo, porcentaje, tope_comision,
          prioridad, CONCAT('Duplicado de ID ', id, IF(nota IS NULL OR nota='', '', CONCAT(' | ', nota)))
        FROM esquemas_comisiones_v2
        WHERE id = ?
        LIMIT 1
      ";
      $st = $conn->prepare($sql);
      $st->bind_param("si", $vigente_desde, $id);
      $st->execute();
      $st->close();

      flash_set('ok', "Regla duplicada con vigente_desde = $vigente_desde.");
      header("Location: ".$_SERVER['PHP_SELF'].'?'.http_build_query($_GET));
      exit();
    }

    /* ------- Guardar (crear / editar) ------- */
    if ($action === 'guardar') {
      $id = (int)($_POST['id'] ?? 0);

      $vigente_desde = trim((string)($_POST['vigente_desde'] ?? ''));
      $vigente_hasta = trim((string)($_POST['vigente_hasta'] ?? ''));
      $activo        = isset($_POST['activo']) ? 1 : 0;

      $id_sucursal   = trim((string)($_POST['id_sucursal'] ?? ''));
      $id_sucursal   = ($id_sucursal === '' || $id_sucursal === '0') ? null : (int)$id_sucursal;

      $rol           = trim((string)($_POST['rol'] ?? ''));
      $categoria      = trim((string)($_POST['categoria'] ?? ''));
      $subtipo        = trim((string)($_POST['subtipo'] ?? ''));
      $subtipo        = ($subtipo === '' || $subtipo === '*') ? null : $subtipo;

      $operador       = trim((string)($_POST['operador'] ?? ''));
      $operador       = ($operador === '' || $operador === '*') ? null : $operador;

      $componente     = trim((string)($_POST['componente'] ?? 'comision'));
      $reqU           = isset($_POST['requiere_cuota_unidades']) ? 1 : 0;
      $reqM           = isset($_POST['requiere_cuota_monto']) ? 1 : 0;

      $precio_min     = trim((string)($_POST['precio_min'] ?? ''));
      $precio_max     = trim((string)($_POST['precio_max'] ?? ''));
      $monto_fijo     = trim((string)($_POST['monto_fijo'] ?? ''));
      $porcentaje     = trim((string)($_POST['porcentaje'] ?? ''));
      $tope_comision  = trim((string)($_POST['tope_comision'] ?? ''));
      $prioridad      = (int)($_POST['prioridad'] ?? 100);
      $nota           = trim((string)($_POST['nota'] ?? ''));

      // Normaliza numéricos
      $precio_min = ($precio_min === '') ? null : (float)$precio_min;
      $precio_max = ($precio_max === '') ? null : (float)$precio_max;
      $monto_fijo = ($monto_fijo === '') ? null : (float)$monto_fijo;
      $porcentaje = ($porcentaje === '') ? null : (float)$porcentaje;
      $tope_comision = ($tope_comision === '') ? null : (float)$tope_comision;
      $nota = ($nota === '') ? null : $nota;
      $vigente_hasta = ($vigente_hasta === '') ? null : $vigente_hasta;

      // Validaciones
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vigente_desde)) throw new Exception('vigente_desde inválido (YYYY-MM-DD).');
      if ($vigente_hasta !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vigente_hasta)) throw new Exception('vigente_hasta inválido (YYYY-MM-DD).');

      $enumRolOk = ['Ejecutivo','Gerente'];
      if (!in_array($rol, $enumRolOk, true)) throw new Exception('Rol inválido.');

      $enumCatOk = ['Equipo','SIM','Pospago','Tarjeta','Combo'];
      if (!in_array($categoria, $enumCatOk, true)) throw new Exception('Categoría inválida.');

      $enumCompOk = ['comision','comision_gerente'];
      if (!in_array($componente, $enumCompOk, true)) throw new Exception('Componente inválido.');

      if ($precio_min !== null && $precio_max !== null && $precio_min > $precio_max) throw new Exception('precio_min no puede ser mayor que precio_max.');

      // No permitir monto_fijo y porcentaje al mismo tiempo (para evitar dobles reglas)
      if ($monto_fijo !== null && $porcentaje !== null) throw new Exception('Usa monto_fijo O porcentaje, no ambos.');

      // Guardar
      if ($id > 0) {
        $sql = "
          UPDATE esquemas_comisiones_v2 SET
            vigente_desde=?,
            vigente_hasta=?,
            activo=?,
            id_sucursal=?,
            rol=?,
            categoria=?,
            subtipo=?,
            operador=?,
            componente=?,
            requiere_cuota_unidades=?,
            requiere_cuota_monto=?,
            precio_min=?,
            precio_max=?,
            monto_fijo=?,
            porcentaje=?,
            tope_comision=?,
            prioridad=?,
            nota=?
          WHERE id=?
          LIMIT 1
        ";
        $st = $conn->prepare($sql);

        // Tipos: s s i i s s s s s i i d d d d d i s i
        $vh = $vigente_hasta; // puede ser null
        $ids = $id_sucursal;  // puede ser null
        $st->bind_param(
          "ssissssssiidddddisi",
          $vigente_desde,
          $vh,
          $activo,
          $ids,
          $rol,
          $categoria,
          $subtipo,
          $operador,
          $componente,
          $reqU,
          $reqM,
          $precio_min,
          $precio_max,
          $monto_fijo,
          $porcentaje,
          $tope_comision,
          $prioridad,
          $nota,
          $id
        );
        $st->execute();
        $st->close();
        flash_set('ok', 'Regla actualizada.');
      } else {
        $sql = "
          INSERT INTO esquemas_comisiones_v2
          (vigente_desde, vigente_hasta, activo, id_sucursal, rol, categoria, subtipo, operador, componente,
           requiere_cuota_unidades, requiere_cuota_monto, precio_min, precio_max, monto_fijo, porcentaje,
           tope_comision, prioridad, nota)
          VALUES
          (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ";
        $st = $conn->prepare($sql);
        $vh = $vigente_hasta; // null ok
        $ids = $id_sucursal;  // null ok

        $st->bind_param(
          "ssissssssiidddddis",
          $vigente_desde,
          $vh,
          $activo,
          $ids,
          $rol,
          $categoria,
          $subtipo,
          $operador,
          $componente,
          $reqU,
          $reqM,
          $precio_min,
          $precio_max,
          $monto_fijo,
          $porcentaje,
          $tope_comision,
          $prioridad,
          $nota
        );
        $st->execute();
        $st->close();
        flash_set('ok', 'Regla creada.');
      }

      header("Location: ".$_SERVER['PHP_SELF'].'?'.http_build_query($_GET));
      exit();
    }

    throw new Exception('Acción no soportada.');
  }
} catch (Throwable $e) {
  flash_set('err', $e->getMessage());
  header("Location: ".$_SERVER['PHP_SELF'].'?'.http_build_query($_GET));
  exit();
}

/* ===================== Filtros (GET) ===================== */
$f_q         = trim((string)($_GET['q'] ?? ''));
$f_rol       = trim((string)($_GET['rol'] ?? ''));
$f_cat       = trim((string)($_GET['categoria'] ?? ''));
$f_comp      = trim((string)($_GET['componente'] ?? ''));
$f_activo    = trim((string)($_GET['activo'] ?? '')); // '', '1', '0'
$f_sucursal  = trim((string)($_GET['id_sucursal'] ?? ''));
$f_date      = trim((string)($_GET['fecha'] ?? '')); // para ver vigentes en fecha
$sort        = trim((string)($_GET['sort'] ?? 'vigente')); // 'vigente'|'prioridad'|'id'
$dir         = strtolower(trim((string)($_GET['dir'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';

$where = [];
$bindTypes = '';
$bindVals = [];

if ($f_rol !== '' && in_array($f_rol, ['Ejecutivo','Gerente'], true)) {
  $where[] = "e.rol = ?";
  $bindTypes .= 's'; $bindVals[] = $f_rol;
}
if ($f_cat !== '' && in_array($f_cat, ['Equipo','SIM','Pospago','Tarjeta','Combo'], true)) {
  $where[] = "e.categoria = ?";
  $bindTypes .= 's'; $bindVals[] = $f_cat;
}
if ($f_comp !== '' && in_array($f_comp, ['comision','comision_gerente'], true)) {
  $where[] = "e.componente = ?";
  $bindTypes .= 's'; $bindVals[] = $f_comp;
}
if ($f_activo !== '' && in_array($f_activo, ['0','1'], true)) {
  $where[] = "e.activo = ?";
  $bindTypes .= 'i'; $bindVals[] = (int)$f_activo;
}
if ($f_sucursal !== '' && ctype_digit($f_sucursal)) {
  $sid = (int)$f_sucursal;
  if ($sid === 0) {
    $where[] = "e.id_sucursal IS NULL";
  } else {
    $where[] = "e.id_sucursal = ?";
    $bindTypes .= 'i'; $bindVals[] = $sid;
  }
}
if ($f_q !== '') {
  $where[] = "(COALESCE(e.operador,'') LIKE ? OR COALESCE(e.nota,'') LIKE ?)";
  $qq = '%'.$f_q.'%';
  $bindTypes .= 'ss'; $bindVals[] = $qq; $bindVals[] = $qq;
}
if ($f_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
  $where[] = "(e.vigente_desde <= ? AND (e.vigente_hasta IS NULL OR e.vigente_hasta >= ?))";
  $bindTypes .= 'ss'; $bindVals[] = $f_date; $bindVals[] = $f_date;
}

$whereSQL = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$orderSQL = "ORDER BY e.id DESC";
if ($sort === 'prioridad') $orderSQL = "ORDER BY e.activo DESC, e.prioridad ASC, e.id DESC";
if ($sort === 'id')        $orderSQL = "ORDER BY e.id $dir";
if ($sort === 'vigente')   $orderSQL = "ORDER BY e.activo DESC, e.vigente_desde DESC, COALESCE(e.vigente_hasta,'9999-12-31') DESC, e.prioridad ASC, e.id DESC";

/* ===================== Catálogos para selects ===================== */
$sucursales = [];
if (columnExists($conn, 'sucursales', 'id') && columnExists($conn, 'sucursales', 'nombre')) {
  $rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
  while ($r = $rs->fetch_assoc()) $sucursales[] = $r;
  $rs->close();
}

/* ===================== Traer registros ===================== */
$items = [];
$sql = "
  SELECT e.*
  FROM esquemas_comisiones_v2 e
  $whereSQL
  $orderSQL
  LIMIT 2000
";
$st = $conn->prepare($sql);
if ($bindTypes !== '') {
  // bind dinámico
  $refs = [];
  $refs[] = &$bindTypes;
  foreach ($bindVals as $k => $v) { $refs[] = &$bindVals[$k]; }
  call_user_func_array([$st, 'bind_param'], $refs);
}
$st->execute();
$res = $st->get_result();
while ($row = $res->fetch_assoc()) $items[] = $row;
$st->close();

/* ===================== Flash ===================== */
$flash_ok  = flash_get('ok');
$flash_err = flash_get('err');

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Esquemas de comisiones v2</title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

  <style>
    body{ background:#f6f8fb; }
    .page-title{ font-weight:900; letter-spacing:.2px; }
    .card-elev{ border:0; border-radius:16px; box-shadow:0 18px 30px rgba(0,0,0,.05),0 2px 8px rgba(0,0,0,.04); }
    .toolbar{ gap:.5rem; flex-wrap:wrap; }
    .pill{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:.35rem .5rem; }
    .tbl-wrap{ overflow:auto; max-height:72vh; }
    .tbl{ width:100%; border-collapse:separate; border-spacing:0; font-size:.85rem; }
    .tbl thead th{ position:sticky; top:0; z-index:3; background:#fff; border-bottom:2px solid #e5e7eb; padding:.5rem .6rem; white-space:nowrap; }
    .tbl tbody td{ border-top:1px solid #eef1f5; padding:.45rem .6rem; vertical-align:middle; white-space:nowrap; }
    .tbl tbody tr:hover td{ background:#0d6efd08; }
    .mono{ font-variant-numeric:tabular-nums; }
    .badge-soft{ border:1px solid #e5e7eb; background:#fff; }
    .btn-round{ border-radius:12px; }
    .chip{ display:inline-flex; align-items:center; gap:.35rem; padding:.2rem .45rem; border-radius:999px; border:1px solid #e5e7eb; background:#fff; }
    .chip-off{ opacity:.55; }
    .muted{ color:#6b7280; }
    .w-120{ min-width:120px; }
    .w-160{ min-width:160px; }
  </style>
</head>
<body>

<?php if (file_exists(__DIR__.'/navbar.php')) include __DIR__.'/navbar.php'; ?>

<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h2 class="page-title mb-1"><i class="bi bi-sliders2-vertical me-2"></i>Esquemas de comisiones v2</h2>
      <div class="text-muted">Administra reglas de cálculo (sin borrar, solo activar/desactivar o duplicar).</div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-primary btn-round" id="btnNuevo">
        <i class="bi bi-plus-circle me-1"></i> Nueva regla
      </button>
    </div>
  </div>

  <?php if ($flash_ok): ?>
    <div class="alert alert-success py-2"><?= h($flash_ok) ?></div>
  <?php endif; ?>
  <?php if ($flash_err): ?>
    <div class="alert alert-danger py-2"><b>Error:</b> <?= h($flash_err) ?></div>
  <?php endif; ?>

  <!-- Filtros -->
  <div class="card card-elev mb-3">
    <div class="card-body">
      <form class="d-flex align-items-end toolbar" method="get">
        <div>
          <label class="form-label small muted mb-1">Buscar (operador/nota)</label>
          <input type="text" class="form-control" name="q" value="<?= h($f_q) ?>" placeholder="Ej. Telcel, AT&T, promo...">
        </div>

        <div>
          <label class="form-label small muted mb-1">Rol</label>
          <select class="form-select" name="rol">
            <option value="">Todos</option>
            <option value="Ejecutivo" <?= $f_rol==='Ejecutivo'?'selected':'' ?>>Ejecutivo</option>
            <option value="Gerente" <?= $f_rol==='Gerente'?'selected':'' ?>>Gerente</option>
          </select>
        </div>

        <div>
          <label class="form-label small muted mb-1">Categoría</label>
          <select class="form-select" name="categoria">
            <option value="">Todas</option>
            <?php foreach (['Equipo','SIM','Pospago','Tarjeta','Combo'] as $c): ?>
              <option value="<?= h($c) ?>" <?= $f_cat===$c?'selected':'' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="form-label small muted mb-1">Componente</label>
          <select class="form-select" name="componente">
            <option value="">Todos</option>
            <option value="comision" <?= $f_comp==='comision'?'selected':'' ?>>comision</option>
            <option value="comision_gerente" <?= $f_comp==='comision_gerente'?'selected':'' ?>>comision_gerente</option>
          </select>
        </div>

        <div>
          <label class="form-label small muted mb-1">Activo</label>
          <select class="form-select" name="activo">
            <option value="">Todos</option>
            <option value="1" <?= $f_activo==='1'?'selected':'' ?>>Activos</option>
            <option value="0" <?= $f_activo==='0'?'selected':'' ?>>Inactivos</option>
          </select>
        </div>

        <div>
          <label class="form-label small muted mb-1">Sucursal</label>
          <select class="form-select" name="id_sucursal">
            <option value="">Todas</option>
            <option value="0" <?= $f_sucursal==='0'?'selected':'' ?>>Global (NULL)</option>
            <?php foreach ($sucursales as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $f_sucursal===(string)$s['id']?'selected':'' ?>>
                <?= h($s['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="form-label small muted mb-1">Vigente en</label>
          <input type="date" class="form-control" name="fecha" value="<?= h($f_date) ?>">
        </div>

        <div>
          <label class="form-label small muted mb-1">Orden</label>
          <select class="form-select" name="sort">
            <option value="vigente" <?= $sort==='vigente'?'selected':'' ?>>Vigencia</option>
            <option value="prioridad" <?= $sort==='prioridad'?'selected':'' ?>>Prioridad</option>
            <option value="id" <?= $sort==='id'?'selected':'' ?>>ID</option>
          </select>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-outline-primary btn-round">
            <i class="bi bi-funnel me-1"></i> Filtrar
          </button>
          <a class="btn btn-outline-secondary btn-round" href="<?= h($_SERVER['PHP_SELF']) ?>">
            <i class="bi bi-eraser me-1"></i> Limpiar
          </a>
        </div>
      </form>

      <div class="mt-3 small muted">
        Mostrando <b><?= number_format(count($items)) ?></b> reglas (máximo 2000).
      </div>
    </div>
  </div>

  <!-- Tabla -->
  <div class="card card-elev">
    <div class="card-body p-0">
      <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>ID</th>
              <th>Estado</th>
              <th>Rol</th>
              <th>Categoría</th>
              <th>Subtipo</th>
              <th>Operador</th>
              <th>Componente</th>
              <th>Sucursal</th>
              <th>Cuota</th>
              <th>Precio</th>
              <th>Monto</th>
              <th>%</th>
              <th>Tope</th>
              <th>Prioridad</th>
              <th>Vigencia</th>
              <th>Nota</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $r): ?>
              <?php
                $id = (int)$r['id'];
                $activo = (int)$r['activo'] === 1;
                $sid = $r['id_sucursal'] ?? null;

                $sucName = 'Global';
                if ($sid !== null) {
                  foreach ($sucursales as $ss) { if ((int)$ss['id'] === (int)$sid) { $sucName = $ss['nombre']; break; } }
                }

                $reqU = !empty($r['requiere_cuota_unidades']);
                $reqM = !empty($r['requiere_cuota_monto']);
                $cuotaTxt = ($reqU && $reqM) ? 'Unid + Monto' : ($reqU ? 'Unidades' : ($reqM ? 'Monto' : 'No'));

                $pmin = $r['precio_min'] ?? null;
                $pmax = $r['precio_max'] ?? null;
                $precioTxt = ($pmin===null && $pmax===null) ? '—' : ('$'.number_format((float)($pmin ?? 0),2).' - $'.number_format((float)($pmax ?? 0),2));

                $vigD = $r['vigente_desde'] ?? '';
                $vigH = $r['vigente_hasta'] ?? null;
                $vigTxt = $vigD . ' → ' . ($vigH ?: '∞');

                $monto = $r['monto_fijo'] ?? null;
                $porc  = $r['porcentaje'] ?? null;
                $tope  = $r['tope_comision'] ?? null;

                $subtipo = $r['subtipo'] ?? '*';
                $operador = $r['operador'] ?? '*';
              ?>
              <tr class="<?= $activo ? '' : 'opacity-75' ?>">
                <td class="mono"><span class="chip <?= $activo?'':'chip-off' ?>">#<?= $id ?></span></td>
                <td>
                  <?php if ($activo): ?>
                    <span class="badge text-bg-success">Activo</span>
                  <?php else: ?>
                    <span class="badge text-bg-secondary">Inactivo</span>
                  <?php endif; ?>
                </td>
                <td><?= h($r['rol']) ?></td>
                <td><?= h($r['categoria']) ?></td>
                <td><?= h($subtipo) ?></td>
                <td><?= h($operador) ?></td>
                <td><span class="badge badge-soft"><?= h($r['componente']) ?></span></td>
                <td><?= h($sucName) ?></td>
                <td><?= h($cuotaTxt) ?></td>
                <td class="mono"><?= h($precioTxt) ?></td>
                <td class="mono"><?= $monto===null?'—':'$'.number_format((float)$monto,2) ?></td>
                <td class="mono"><?= $porc===null?'—':number_format((float)$porc,4) ?></td>
                <td class="mono"><?= $tope===null?'—':'$'.number_format((float)$tope,2) ?></td>
                <td class="mono"><?= number_format((int)$r['prioridad']) ?></td>
                <td class="mono"><?= h($vigTxt) ?></td>
                <td class="w-160 text-truncate" style="max-width:260px;" title="<?= h($r['nota'] ?? '') ?>"><?= h($r['nota'] ?? '—') ?></td>
                <td class="text-end">
                  <div class="d-inline-flex gap-1">
                    <button class="btn btn-outline-primary btn-sm btn-round btnEdit"
                      data-row='<?= h(json_encode($r, JSON_UNESCAPED_UNICODE)) ?>'
                      title="Editar">
                      <i class="bi bi-pencil"></i>
                    </button>

                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <input type="hidden" name="activo" value="<?= $activo ? 0 : 1 ?>">
                      <button class="btn btn-sm btn-round <?= $activo ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                        onclick="return confirm('¿Seguro que quieres <?= $activo ? 'desactivar' : 'activar' ?> esta regla?');"
                        title="<?= $activo ? 'Desactivar' : 'Activar' ?>">
                        <i class="bi <?= $activo ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
                      </button>
                    </form>

                    <button class="btn btn-outline-secondary btn-sm btn-round btnDup"
                      data-id="<?= $id ?>"
                      data-vdesde="<?= h(date('Y-m-d')) ?>"
                      title="Duplicar">
                      <i class="bi bi-files"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (!count($items)): ?>
              <tr><td colspan="17" class="text-center py-4 text-muted">Sin resultados.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="small text-muted">
        Tip: para cambiar una regla ya usada, mejor <b>Duplicar</b> y poner nueva vigencia. Así no rompes semanas pasadas.
      </div>
      <div class="small text-muted">
        Máximo 2000 filas por seguridad.
      </div>
    </div>
  </div>
</div>

<!-- ========================= Modal Crear/Editar ========================= -->
<div class="modal fade" id="modalRule" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <form method="post" autocomplete="off" id="formRule">
        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="guardar">
        <input type="hidden" name="id" id="f_id" value="0">

        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-wrench-adjustable-circle me-2"></i><span id="modalTitle">Nueva regla</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3">

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Vigente desde</label>
              <input type="date" class="form-control" name="vigente_desde" id="f_vigente_desde" required>
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Vigente hasta (opcional)</label>
              <input type="date" class="form-control" name="vigente_hasta" id="f_vigente_hasta">
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Sucursal</label>
              <select class="form-select" name="id_sucursal" id="f_id_sucursal">
                <option value="0">Global (NULL)</option>
                <?php foreach ($sucursales as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-lg-3 d-flex align-items-end">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="activo" id="f_activo" checked>
                <label class="form-check-label" for="f_activo">Activo</label>
              </div>
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Rol</label>
              <select class="form-select" name="rol" id="f_rol" required>
                <option value="Ejecutivo">Ejecutivo</option>
                <option value="Gerente">Gerente</option>
              </select>
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Categoría</label>
              <select class="form-select" name="categoria" id="f_categoria" required>
                <?php foreach (['Equipo','SIM','Pospago','Tarjeta','Combo'] as $c): ?>
                  <option value="<?= h($c) ?>"><?= h($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Subtipo (opcional)</label>
              <select class="form-select" name="subtipo" id="f_subtipo">
                <option value="*">*</option>
                <?php foreach (['Nueva','Portabilidad','Pospago','Prepago'] as $stp): ?>
                  <option value="<?= h($stp) ?>"><?= h($stp) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Usa <b>*</b> para “aplica a todos”.</div>
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Operador (opcional)</label>
              <input type="text" class="form-control" name="operador" id="f_operador" placeholder="Ej. Telcel, AT&T o *">
              <div class="form-text">Vacío o <b>*</b> = todos.</div>
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Componente</label>
              <select class="form-select" name="componente" id="f_componente" required>
                <option value="comision">comision</option>
                <option value="comision_gerente">comision_gerente</option>
              </select>
            </div>

            <div class="col-12 col-lg-3 d-flex align-items-end gap-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="requiere_cuota_unidades" id="f_reqU">
                <label class="form-check-label" for="f_reqU">Requiere cuota unidades</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="requiere_cuota_monto" id="f_reqM">
                <label class="form-check-label" for="f_reqM">Requiere cuota monto</label>
              </div>
            </div>

            <div class="col-12"><hr></div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Precio min (opcional)</label>
              <input type="number" step="0.01" class="form-control" name="precio_min" id="f_precio_min" placeholder="0.00">
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Precio max (opcional)</label>
              <input type="number" step="0.01" class="form-control" name="precio_max" id="f_precio_max" placeholder="0.00">
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Monto fijo (opcional)</label>
              <input type="number" step="0.01" class="form-control" name="monto_fijo" id="f_monto_fijo" placeholder="0.00">
              <div class="form-text">Usa monto fijo <b>o</b> porcentaje.</div>
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">% (opcional)</label>
              <input type="number" step="0.0001" class="form-control" name="porcentaje" id="f_porcentaje" placeholder="0.0000">
              <div class="form-text">Ej. 0.0500 = 5%.</div>
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Tope comisión (opcional)</label>
              <input type="number" step="0.01" class="form-control" name="tope_comision" id="f_tope">
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small muted">Prioridad</label>
              <input type="number" class="form-control" name="prioridad" id="f_prioridad" value="100">
              <div class="form-text">Menor = gana primero.</div>
            </div>

            <div class="col-12 col-lg-6">
              <label class="form-label small muted">Nota</label>
              <input type="text" class="form-control" name="nota" id="f_nota" maxlength="255" placeholder="Ej. Promo enero / ajuste por tienda...">
            </div>

            <div class="col-12">
              <div class="alert alert-light border small mb-0">
                <b>Reglas:</b> precio_min ≤ precio_max. Usa <b>monto_fijo</b> o <b>%</b> (no ambos).
                Para cambios históricos, mejor <b>Duplicar</b> con nueva vigencia.
              </div>
            </div>

          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-round" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary btn-round"><i class="bi bi-save me-1"></i> Guardar</button>
        </div>

      </form>
    </div>
  </div>
</div>

<!-- ========================= Modal Duplicar ========================= -->
<div class="modal fade" id="modalDup" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="formDup">
        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="duplicar">
        <input type="hidden" name="id" id="dup_id" value="0">

        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-files me-2"></i>Duplicar regla</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">
          <div class="mb-2 small muted">Se creará una nueva regla idéntica, activa, con <b>vigente_hasta = NULL</b>.</div>
          <label class="form-label small muted">Vigente desde</label>
          <input type="date" class="form-control" name="vigente_desde" id="dup_vdesde" required>
          <div class="form-text">Tip: usa el inicio de semana o el día que entra el cambio.</div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-round" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-secondary btn-round"><i class="bi bi-check2-circle me-1"></i> Duplicar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const modalRule = new bootstrap.Modal(document.getElementById('modalRule'));
const modalDup  = new bootstrap.Modal(document.getElementById('modalDup'));

const byId = (id)=>document.getElementById(id);

function setVal(id, v){
  const el = byId(id);
  if (!el) return;
  if (el.type === 'checkbox') el.checked = !!v;
  else el.value = (v === null || v === undefined) ? '' : v;
}

function normalizeOperador(v){
  v = (v ?? '').toString().trim();
  if (v === '' || v === '*') return '';
  return v;
}

function openNew(){
  byId('modalTitle').textContent = 'Nueva regla';

  setVal('f_id', 0);
  setVal('f_vigente_desde', new Date().toISOString().slice(0,10));
  setVal('f_vigente_hasta', '');
  setVal('f_activo', true);
  setVal('f_id_sucursal', '0');
  setVal('f_rol', 'Ejecutivo');
  setVal('f_categoria', 'Equipo');
  setVal('f_subtipo', '*');
  setVal('f_operador', '');
  setVal('f_componente', 'comision');
  setVal('f_reqU', false);
  setVal('f_reqM', false);
  setVal('f_precio_min', '');
  setVal('f_precio_max', '');
  setVal('f_monto_fijo', '');
  setVal('f_porcentaje', '');
  setVal('f_tope', '');
  setVal('f_prioridad', 100);
  setVal('f_nota', '');

  modalRule.show();
}

function openEdit(row){
  byId('modalTitle').textContent = 'Editar regla #'+row.id;

  setVal('f_id', row.id);
  setVal('f_vigente_desde', row.vigente_desde || '');
  setVal('f_vigente_hasta', row.vigente_hasta || '');
  setVal('f_activo', String(row.activo) === '1');

  setVal('f_id_sucursal', row.id_sucursal ? String(row.id_sucursal) : '0');

  setVal('f_rol', row.rol || 'Ejecutivo');
  setVal('f_categoria', row.categoria || 'Equipo');
  setVal('f_subtipo', row.subtipo ? row.subtipo : '*');
  setVal('f_operador', normalizeOperador(row.operador));
  setVal('f_componente', row.componente || 'comision');

  setVal('f_reqU', String(row.requiere_cuota_unidades) === '1');
  setVal('f_reqM', String(row.requiere_cuota_monto) === '1');

  setVal('f_precio_min', row.precio_min ?? '');
  setVal('f_precio_max', row.precio_max ?? '');
  setVal('f_monto_fijo', row.monto_fijo ?? '');
  setVal('f_porcentaje', row.porcentaje ?? '');
  setVal('f_tope', row.tope_comision ?? '');
  setVal('f_prioridad', row.prioridad ?? 100);
  setVal('f_nota', row.nota ?? '');

  modalRule.show();
}

function openDup(id){
  byId('dup_id').value = id;
  byId('dup_vdesde').value = new Date().toISOString().slice(0,10);
  modalDup.show();
}

/* Botones */
document.getElementById('btnNuevo').addEventListener('click', openNew);

document.querySelectorAll('.btnEdit').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const raw = btn.getAttribute('data-row');
    try{
      const row = JSON.parse(raw);
      openEdit(row);
    }catch(e){
      alert('No se pudo leer el registro para editar.');
    }
  });
});

document.querySelectorAll('.btnDup').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id = btn.getAttribute('data-id');
    openDup(id);
  });
});

/* Validación ligera front (no reemplaza backend) */
document.getElementById('formRule').addEventListener('submit', (e)=>{
  const pmin = parseFloat(byId('f_precio_min').value || 'NaN');
  const pmax = parseFloat(byId('f_precio_max').value || 'NaN');

  if (!Number.isNaN(pmin) && !Number.isNaN(pmax) && pmin > pmax) {
    e.preventDefault();
    alert('precio_min no puede ser mayor que precio_max');
    return;
  }

  const mf = byId('f_monto_fijo').value.trim();
  const pc = byId('f_porcentaje').value.trim();
  if (mf !== '' && pc !== '') {
    e.preventDefault();
    alert('Usa monto_fijo O porcentaje, no ambos.');
    return;
  }
});
</script>

</body>
</html>
