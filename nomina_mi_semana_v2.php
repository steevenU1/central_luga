<?php
// mi_nomina_semana_v2.php — Vista de nómina semanal del EJECUTIVO/GERENTE (solo su propia info)
// - Semana operativa fija Mar→Lun (selector simple por "semana actual / pasada / hace N semanas",
//   default = semana ANTERIOR).
// - KPIs + desglose de ventas del usuario logueado.
// - Botón Confirmar / Reabrir usando nomina_confirmaciones.
// - KPI "Comisión gerente":
//     * Ejec: NO se muestra y siempre se cuenta como 0.
//     * Gerente: es el ACUMULADO de comision_gerente generado por su sucursal (DV + SIMs + TC).
// - Confirmación SOLO a partir del JUEVES 00:00 (después del miércoles).
// - Unidades: combo=2 si ventas.tipo_venta LIKE '%combo%'
// - Detalle de ventas: si hay detalle_venta → un renglón por producto (comisión por producto).

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';
date_default_timezone_set('America/Mexico_City');

/* ============ Helpers ============ */
function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function money($n)
{
  return '$' . number_format((float)$n, 2);
}
function hasColumn(mysqli $conn, string $table, string $column): bool
{
  $tableEsc  = $conn->real_escape_string($table);
  $columnEsc = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME='{$tableEsc}' AND COLUMN_NAME='{$columnEsc}' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}
function tableExists(mysqli $conn, string $table): bool
{
  $t = $conn->real_escape_string($table);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='{$t}' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

/* Detección de columna FK a sucursal en usuarios */
function userSucursalCol(mysqli $conn): ?string
{
  if (hasColumn($conn, 'usuarios', 'id_sucursal')) return 'id_sucursal';
  if (hasColumn($conn, 'usuarios', 'sucursal'))     return 'sucursal';
  return null;
}

/* Semana operativa Mar→Lun (offset 0=actual, 1=pasada, etc.) */
function semanaOperativa(int $offset = 0): array
{
  $tz = new DateTimeZone('America/Mexico_City');
  $hoy = new DateTime('now', $tz);
  $dow = (int)$hoy->format('N');             // 1=Lun..7=Dom
  $dif = $dow - 2;
  if ($dif < 0) $dif += 7;  // 2 = Martes
  $ini = new DateTime('now', $tz);
  $ini->modify("-{$dif} days")->setTime(0, 0, 0);
  if ($offset > 0) $ini->modify('-' . (7 * $offset) . ' days');
  $fin = clone $ini;
  $fin->modify('+6 days')->setTime(23, 59, 59);
  return [$ini, $fin];
}

/* Texto humano para el rango de la semana (en español) */
function rangoSemanaHumano(DateTime $ini, DateTime $fin): string
{
  $meses = [
    1 => 'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre'
  ];
  $d1 = (int)$ini->format('j');
  $d2 = (int)$fin->format('j');
  $m1 = (int)$ini->format('n');
  $m2 = (int)$fin->format('n');
  $y1 = (int)$ini->format('Y');
  $y2 = (int)$fin->format('Y');

  if ($y1 === $y2 && $m1 === $m2) {
    // Mismo mes y año
    return "del {$d1} al {$d2} de {$meses[$m1]} de {$y1}";
  } else {
    // Cruza mes o año
    return "del {$d1} de {$meses[$m1]} de {$y1} al {$d2} de {$meses[$m2]} de {$y2}";
  }
}

/* Conteos/Sumas para unidades y comisiones propias */
function notCanceledFilter(mysqli $conn, string $alias = 'v'): string
{
  return hasColumn($conn, 'ventas', 'estatus')
    ? " AND ({$alias}.estatus IS NULL OR {$alias}.estatus NOT IN ('Cancelada','Cancelado','cancelada','cancelado'))"
    : "";
}

/* Resumen individual (comisiones propias) */
function resumenNominaUsuario(
  mysqli $conn,
  int $idUsuario,
  string $ini,
  string $fin
): array {
  $resumen = [
    'sueldo_base' => 0.0,
    'com_regular' => 0.0,
    'com_especial' => 0.0,
    'com_gerente' => 0.0,
    'bonos' => 0.0,
    'descuentos' => 0.0,
    'total' => 0.0,
    'ventas' => 0.0,
    'unidades' => 0
  ];

  // Sueldo base en usuarios (si existe)
  if (tableExists($conn, 'usuarios')) {
    $colSueldo = null;
    if (hasColumn($conn, 'usuarios', 'sueldo')) {
      $colSueldo = 'sueldo';
    } elseif (hasColumn($conn, 'usuarios', 'sueldo_base')) {
      $colSueldo = 'sueldo_base';
    }

    if ($colSueldo !== null) {
      $id = (int)$idUsuario;
      $sql = "SELECT COALESCE({$colSueldo},0) sb FROM usuarios WHERE id={$id} LIMIT 1";
      if ($q = $conn->query($sql)) {
        if ($row = $q->fetch_assoc()) {
          $resumen['sueldo_base'] = (float)$row['sb'];
        }
      }
    }
  }
  // Ventas / unidades
  $tieneVentas = tableExists($conn, 'ventas');
  $tieneDet    = tableExists($conn, 'detalle_venta');

  if ($tieneVentas) {
    $colFechaVta = hasColumn($conn, 'ventas', 'fecha_venta') ? 'fecha_venta'
      : (hasColumn($conn, 'ventas', 'created_at') ? 'created_at' : 'fecha');
    $tieneTipoVenta = hasColumn($conn, 'ventas', 'tipo_venta');
    $filtroEstatus = notCanceledFilter($conn, 'v');

    if ($tieneTipoVenta) {
      $sqlV = "
        SELECT
          COALESCE(SUM(CASE WHEN LOWER(tipo_venta) LIKE '%combo%' THEN 2 ELSE 1 END),0) AS unidades,
          COALESCE(SUM(precio_venta),0) AS total_vta
        FROM ventas v
        WHERE v.id_usuario = {$idUsuario}
          AND v.{$colFechaVta} BETWEEN '{$ini}' AND '{$fin}'
          {$filtroEstatus}
      ";
    } else {
      $sqlV = "
        SELECT COUNT(*) AS unidades, COALESCE(SUM(precio_venta),0) AS total_vta
        FROM ventas v
        WHERE v.id_usuario = {$idUsuario}
          AND v.{$colFechaVta} BETWEEN '{$ini}' AND '{$fin}'
          {$filtroEstatus}
      ";
    }
    if ($q = $conn->query($sqlV)) {
      if ($r = $q->fetch_assoc()) {
        $resumen['ventas']   = (float)$r['total_vta'];
        $resumen['unidades'] = (int)$r['unidades'];
      }
    }
  }

  // Comisiones propias desde detalle_venta
  if ($tieneDet && $tieneVentas) {
    $colComEje = hasColumn($conn, 'detalle_venta', 'comision_ejecutivo') ? 'comision_ejecutivo'
      : (hasColumn($conn, 'detalle_venta', 'comision') ? 'comision' : null);
    $colComEsp = hasColumn($conn, 'detalle_venta', 'comision_especial') ? 'comision_especial' : null;

    $colFechaJoin = hasColumn($conn, 'ventas', 'fecha_venta') ? 'v.fecha_venta'
      : (hasColumn($conn, 'ventas', 'created_at') ? 'v.created_at' : 'v.fecha');
    $filtroEstatusJoin = notCanceledFilter($conn, 'v');

    $sel = [];
    if ($colComEje) $sel[] = "COALESCE(SUM(d.{$colComEje}),0) com_regular";
    if ($colComEsp) $sel[] = "COALESCE(SUM(d.{$colComEsp}),0) com_especial";
    if (empty($sel)) $sel[] = "0 com_regular";

    $sqlC = "
      SELECT " . implode(',', $sel) . "
      FROM detalle_venta d
      JOIN ventas v ON v.id = d.id_venta
      WHERE v.id_usuario = {$idUsuario}
        AND {$colFechaJoin} BETWEEN '{$ini}' AND '{$fin}'
        {$filtroEstatusJoin}
    ";
    if ($q = $conn->query($sqlC)) {
      if ($r = $q->fetch_assoc()) {
        if (isset($r['com_regular']))  $resumen['com_regular']  = (float)$r['com_regular'];
        if (isset($r['com_especial'])) $resumen['com_especial'] = (float)$r['com_especial'];
      }
    }
  }

  // Bonos / Descuentos (tablas v2 si existen)
  $iniDate = substr($ini, 0, 10);
  $finDate = substr($fin, 0, 10);
  if (tableExists($conn, 'nomina_ajustes_v2')) {
    $stmt = $conn->prepare("SELECT tipo, COALESCE(SUM(monto),0) s FROM nomina_ajustes_v2 WHERE id_usuario=? AND semana_inicio=? AND semana_fin=? GROUP BY tipo");
    $stmt->bind_param("iss", $idUsuario, $iniDate, $finDate);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
      if ($row['tipo'] === 'bono')   $resumen['bonos']     = (float)$row['s'];
      if ($row['tipo'] === 'ajuste') $resumen['ajuste_v2'] = (float)$row['s'];
    }
    $stmt->close();
  } else {
    $resumen['ajuste_v2'] = 0.0;
  }

  if (tableExists($conn, 'descuentos_nomina')) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(monto),0) s FROM descuentos_nomina WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?");
    $stmt->bind_param("iss", $idUsuario, $iniDate, $finDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $resumen['descuentos'] = (float)($row['s'] ?? 0);
  }

  // Total (sin com_gerente; se añade abajo si el rol del usuario es Gerente)
  $resumen['total'] = $resumen['sueldo_base'] + $resumen['com_regular'] + $resumen['com_especial']
    + ($resumen['bonos'] ?? 0) + ($resumen['ajuste_v2'] ?? 0) - $resumen['descuentos'];

  return $resumen;
}

/* ============ Entrada ============ */
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rol        = trim($_SESSION['rol'] ?? '');
$nombre     = trim($_SESSION['nombre'] ?? 'Usuario');
$offsetSel  = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 1; // default = semana pasada

list($dtIni, $dtFin) = semanaOperativa($offsetSel);
$iniStr   = $dtIni->format('Y-m-d 00:00:00');
$finStr   = $dtFin->format('Y-m-d 23:59:59');
$iniLabel = $dtIni->format('Y-m-d');
$finLabel = $dtFin->format('Y-m-d');
$textoSemanaHumana = rangoSemanaHumano($dtIni, $dtFin);

/* Candado: confirmar solo DESPUÉS del miércoles (=> jueves 00:00) */
$dtJueves = (clone $dtIni)->modify('+2 days')->setTime(0, 0, 0); // Mar +2 = Jueves 00:00
$ahora    = new DateTime('now', new DateTimeZone('America/Mexico_City'));
$puedeConfirmar = ($ahora >= $dtJueves);

/* Confirmación existente */
$ya = ['confirmado' => 0, 'comentario' => null, 'confirmado_en' => null, 'ip_confirmacion' => null];
if (tableExists($conn, 'nomina_confirmaciones')) {
  $sqlC = sprintf(
    "SELECT confirmado, comentario, confirmado_en, ip_confirmacion
     FROM nomina_confirmaciones
     WHERE id_usuario=%d AND semana_inicio='%s' AND semana_fin='%s' LIMIT 1",
    $idUsuario,
    $conn->real_escape_string($iniLabel),
    $conn->real_escape_string($finLabel)
  );
  if ($q = $conn->query($sqlC)) if ($r = $q->fetch_assoc()) $ya = $r;
}

/* Datos base del usuario: sucursal para cálculo de CG acumulada */
$colSuc = userSucursalCol($conn);
$idSucursalUsuario = null;
if ($colSuc) {
  $stmt = $conn->prepare("SELECT {$colSuc} AS id_sucursal FROM usuarios WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $idUsuario);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  $idSucursalUsuario = $row ? (int)$row['id_sucursal'] : null;
}

/* Resumen de comisiones PROPIAS */
$res = resumenNominaUsuario($conn, $idUsuario, $iniStr, $finStr);

/* ======= Acumulado de 'comision_gerente' por sucursal (solo si el usuario es GERENTE) ======= */
$comGerAcum = 0.0;
if (strcasecmp($rol, 'Gerente') === 0 && $idSucursalUsuario !== null) {
  $pIni = $iniStr;
  $pFin = $finStr;

  // Equipos (detalle_venta)
  if (tableExists($conn, 'detalle_venta') && tableExists($conn, 'ventas') && tableExists($conn, 'usuarios') && hasColumn($conn, 'detalle_venta', 'comision_gerente')) {
    $colFecha = hasColumn($conn, 'ventas', 'fecha_venta') ? 'v.fecha_venta'
      : (hasColumn($conn, 'ventas', 'created_at') ? 'v.created_at' : 'v.fecha');
    $filtroEstatus = notCanceledFilter($conn, 'v');
    $sql = "
      SELECT COALESCE(SUM(d.comision_gerente),0) s
      FROM detalle_venta d
      JOIN ventas v   ON v.id = d.id_venta
      JOIN usuarios u ON u.id = v.id_usuario
      WHERE u.{$colSuc} = ?
        AND {$colFecha} BETWEEN ? AND ?
        {$filtroEstatus}
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $idSucursalUsuario, $pIni, $pFin);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $comGerAcum += (float)($r['s'] ?? 0);
  }

  // SIMs (prepago/pospago)
  if (tableExists($conn, 'ventas_sims') && hasColumn($conn, 'ventas_sims', 'comision_gerente') && hasColumn($conn, 'ventas_sims', 'fecha_venta')) {
    $sql = "
      SELECT COALESCE(SUM(vs.comision_gerente),0) s
      FROM ventas_sims vs
      JOIN usuarios u ON u.id = vs.id_usuario
      WHERE u.{$colSuc} = ?
        AND vs.fecha_venta BETWEEN ? AND ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $idSucursalUsuario, $pIni, $pFin);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $comGerAcum += (float)($r['s'] ?? 0);
  }

  // Tarjeta / PayJoy TC
  if (tableExists($conn, 'ventas_payjoy_tc') && hasColumn($conn, 'ventas_payjoy_tc', 'comision_gerente') && hasColumn($conn, 'ventas_payjoy_tc', 'fecha_venta')) {
    $sql = "
      SELECT COALESCE(SUM(t.comision_gerente),0) s
      FROM ventas_payjoy_tc t
      JOIN usuarios u ON u.id = t.id_usuario
      WHERE u.{$colSuc} = ?
        AND t.fecha_venta BETWEEN ? AND ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $idSucursalUsuario, $pIni, $pFin);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $comGerAcum += (float)($r['s'] ?? 0);
  }

  // Reflejar en el resumen SOLO si es gerente
  $res['com_gerente'] = $comGerAcum;
  $res['total']      += $comGerAcum; // El gerente sí cobra su CG acumulada
} else {
  // Ejecutivos: CG siempre 0 en su vista
  $res['com_gerente'] = 0.0;
}

/* ============ UI ============ */
?>
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
      <h3 class="m-0">Mi nómina semanal <small class="text-muted">(<?= h($nombre) ?>)</small></h3>
      <div class="small text-muted">
        Semana operativa (Mar→Lun): <strong><?= h($textoSemanaHumana) ?></strong><br>
        <span class="text-muted">Rango real: <?= h($iniLabel) ?> → <?= h($finLabel) ?></span>
      </div>
    </div>
    <form class="d-flex align-items-center gap-2" method="get">
      <label class="form-label m-0">Semana (Mar→Lun):</label>
      <select name="offset" class="form-select" style="min-width:260px">
        <?php
        for ($i = 0; $i <= 7; $i++) {
          list($wIni, $wFin) = semanaOperativa($i);
          $lbl = rangoSemanaHumano($wIni, $wFin);
          if ($i === 0)        $titulo = "Semana actual";
          elseif ($i === 1)    $titulo = "Semana pasada";
          else                 $titulo = "Hace {$i} semanas";
          $sel = ($i === $offsetSel) ? ' selected' : '';
          echo '<option value="' . $i . '"' . $sel . '>' . $titulo . ' — ' . $lbl . '</option>';
        }
        ?>
      </select>
      <button class="btn btn-outline-primary">Ver</button>
    </form>
  </div>
  <p class="text-muted mb-3">
    <?php if (!$puedeConfirmar): ?>
      <span class="badge text-bg-warning mt-1">Confirmación disponible a partir del jueves 00:00</span>
    <?php endif; ?>
  </p>

  <div class="row g-3">
    <div class="col-6 col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Sueldo base</div>
          <div class="fs-5 fw-bold"><?= money($res['sueldo_base']) ?></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Comisión regular</div>
          <div class="fs-5 fw-bold"><?= money($res['com_regular']) ?></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Comisión especial</div>
          <div class="fs-5 fw-bold"><?= money($res['com_especial']) ?></div>
        </div>
      </div>
    </div>

    <?php if (strcasecmp($rol, 'Gerente') === 0): ?>
      <div class="col-6 col-md-3">
        <div class="card shadow-sm border-warning">
          <div class="card-body">
            <div class="text-muted small">Comisión gerente (sucursal)</div>
            <div class="fs-5 fw-bold"><?= money($res['com_gerente']) ?></div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="col-6 col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Bonos</div>
          <div class="fs-5 fw-bold"><?= money($res['bonos'] ?? 0) ?></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Descuentos</div>
          <div class="fs-5 fw-bold text-danger">-<?= money($res['descuentos']) ?></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="card shadow-sm border-success">
        <div class="card-body">
          <div class="text-muted small">Total a pagar</div>
          <div class="fs-4 fw-bold text-success"><?= money($res['total']) ?></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Unidades</div>
          <div class="fs-5 fw-bold"><?= $res['unidades'] ?></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Suma de ventas</div>
          <div class="fs-5 fw-bold"><?= money($res['ventas']) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-4 d-flex align-items-center justify-content-between">
    <h5 class="m-0">Confirmación de nómina</h5>
    <?php if ((int)($ya['confirmado'] ?? 0) === 1): ?>
      <span class="badge text-bg-success">Confirmada el <?= h($ya['confirmado_en'] ?? '') ?></span>
    <?php else: ?>
      <span class="badge text-bg-warning">Pendiente de confirmar</span>
    <?php endif; ?>
  </div>

  <form class="mt-3" method="post" action="guardar_confirmacion_nomina.php">
    <input type="hidden" name="semana_inicio" value="<?= h($iniLabel) ?>">
    <input type="hidden" name="semana_fin" value="<?= h($finLabel) ?>">
    <div class="mb-2">
      <label class="form-label">Comentario (opcional)</label>
      <textarea class="form-control" name="comentario" rows="2" maxlength="255"
        placeholder="Escribe una nota si deseas..."><?= h($ya['comentario'] ?? '') ?></textarea>
    </div>
    <?php if ((int)($ya['confirmado'] ?? 0) === 1): ?>
      <button class="btn btn-outline-secondary" type="submit" name="accion" value="reabrir">Reabrir confirmación</button>
    <?php else: ?>
      <button class="btn btn-primary" type="submit" name="accion" value="confirmar" <?= $puedeConfirmar ? '' : 'disabled' ?>>
        Confirmar mi nómina de la semana
      </button>
    <?php endif; ?>
  </form>

  <hr class="my-4">

  <?php
  // ============= TABLA DETALLE (un renglón por producto si existe detalle_venta) =============
  if (tableExists($conn, 'ventas')) {
    $colFecha = hasColumn($conn, 'ventas', 'fecha_venta') ? 'fecha_venta'
      : (hasColumn($conn, 'ventas', 'created_at') ? 'created_at' : 'fecha');

    $tieneTipoVenta = hasColumn($conn, 'ventas', 'tipo_venta');
    $filtroEstatusTabla = notCanceledFilter($conn, 'v');

    $tieneDet = tableExists($conn, 'detalle_venta');
    $colComRow = null;
    if ($tieneDet) {
      if (hasColumn($conn, 'detalle_venta', 'comision_ejecutivo')) $colComRow = 'comision_ejecutivo';
      elseif (hasColumn($conn, 'detalle_venta', 'comision'))       $colComRow = 'comision';
    }

    // Detectar columna de nombre/descripcion de producto en detalle_venta
    $colProdSel = '';
    $tieneProducto = false;
    if ($tieneDet) {
      if (hasColumn($conn, 'detalle_venta', 'producto')) {
        $colProdSel = 'd.producto AS producto';
        $tieneProducto = true;
      } elseif (hasColumn($conn, 'detalle_venta', 'nombre_producto')) {
        $colProdSel = 'd.nombre_producto AS producto';
        $tieneProducto = true;
      } elseif (hasColumn($conn, 'detalle_venta', 'descripcion')) {
        $colProdSel = 'd.descripcion AS producto';
        $tieneProducto = true;
      } elseif (hasColumn($conn, 'detalle_venta', 'id_producto')) {
        $colProdSel = 'CAST(d.id_producto AS CHAR) AS producto';
        $tieneProducto = true;
      }
    }

    if ($tieneDet && $colComRow) {
      // Un renglón POR PRODUCTO (INNER JOIN para omitir ventas sin detalle)
      $selectCols = "
        v.id,
        v.tag,
        v.nombre_cliente,
        {$colFecha} AS fecha,
        COALESCE(d.{$colComRow},0) AS comision
      ";
      if ($tieneTipoVenta)  $selectCols .= ", v.tipo_venta";
      if ($tieneProducto && $colProdSel) $selectCols .= ", {$colProdSel}";

      $sql = "
        SELECT {$selectCols}
        FROM ventas v
        JOIN detalle_venta d ON d.id_venta = v.id
        WHERE v.id_usuario = {$idUsuario}
          AND v.{$colFecha} BETWEEN '{$iniStr}' AND '{$finStr}'
          {$filtroEstatusTabla}
        ORDER BY {$colFecha} ASC, v.id ASC
      ";
    } else {
      // Sin detalle_venta: un renglón por venta (comisión 0)
      $selectCols = "
        v.id,
        v.tag,
        v.nombre_cliente,
        {$colFecha} AS fecha,
        0 AS comision
      ";
      if ($tieneTipoVenta) $selectCols .= ", v.tipo_venta";

      $sql = "
        SELECT {$selectCols}
        FROM ventas v
        WHERE v.id_usuario = {$idUsuario}
          AND v.{$colFecha} BETWEEN '{$iniStr}' AND '{$finStr}'
          {$filtroEstatusTabla}
        ORDER BY {$colFecha} ASC, v.id ASC
      ";
    }

    $rs = $conn->query($sql);

    echo '<h6>Detalle de ventas de la semana</h6>';
    echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>Fecha</th><th>TAG</th><th>Cliente</th>';
    if ($tieneTipoVenta)  echo '<th>Tipo venta</th>';
    if ($tieneDet && $tieneProducto) echo '<th>Producto</th>';
    echo '<th>Comisión</th></tr></thead><tbody>';

    if ($rs && $rs->num_rows > 0) {
      while ($row = $rs->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . h($row['id']) . '</td>';
        echo '<td>' . h(substr($row['fecha'], 0, 19)) . '</td>';
        echo '<td>' . h($row['tag'] ?? '') . '</td>';
        echo '<td>' . h($row['nombre_cliente'] ?? '') . '</td>';
        if ($tieneTipoVenta)  echo '<td>' . h($row['tipo_venta'] ?? '') . '</td>';
        if ($tieneDet && $tieneProducto) echo '<td>' . h($row['producto'] ?? '') . '</td>';
        echo '<td>' . money($row['comision'] ?? 0) . '</td>';
        echo '</tr>';
      }
    } else {
      $extraCols = 0;
      if ($tieneTipoVenta) $extraCols++;
      if ($tieneDet && $tieneProducto) $extraCols++;
      $colspan = 5 + $extraCols; // ID, Fecha, TAG, Cliente, Comisión + extras
      echo '<tr><td colspan="' . $colspan . '" class="text-muted">Sin ventas en el rango.</td></tr>';
    }
    echo '</tbody></table></div>';
  }
  ?>
</div>