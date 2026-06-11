<?php
// reporte_nomina_v2.php — Nómina semanal v2 (Mar→Lun, UI con cards de KPIs)
// Ajustes:
// 1) La columna "Comisión Equipos" del ejecutivo ya incluye comision_especial
//    SOLO si el ejecutivo llegó a su cuota de unidades de la semana.
// 2) Nueva columna "Cuota":
//    - Ejecutivos: muestra cuota_unidades de su sucursal.
//    - Gerentes: muestra cuota_monto de su sucursal.
// 3) Las comisiones de gerente se muestran solo en el renglón del gerente
//    (redistribución visual, sin tocar BD).
// 4) Bono provisional semana especial:
//    - Ejecutivo: $75 por venta elegible (Equipo principal, sin módem, sin combo) si tiene 10 o más ventas elegibles.
//    - Gerente:  $50 por unidad elegible de la sucursal si la sucursal llega a cuota_monto (se mantiene).

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/export_excel_helper.php';

/* ---------------- Utils ---------------- */
function columnExists(mysqli $conn, string $table, string $column): bool
{
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

function dateColVentas(mysqli $conn): string
{
  if (columnExists($conn, 'ventas', 'fecha_venta')) return 'fecha_venta';
  if (columnExists($conn, 'ventas', 'created_at'))  return 'created_at';
  return 'fecha';
}

/** Regresa [inicio(martes 00:00), fin(lunes 23:59:59)] de la semana que contiene $anchor (Y-m-d) */
function weekBoundsFrom(string $anchor): array
{
  $d = DateTime::createFromFormat('Y-m-d', $anchor) ?: new DateTime('now');
  $dow = (int)$d->format('N'); // 1=Lun..7=Dom
  $diff = $dow >= 2 ? $dow - 2 : 7 - (2 - $dow); // ir hacia el martes
  $ini = clone $d;
  $ini->modify("-$diff day")->setTime(0, 0, 0);
  $fin = clone $ini;
  $fin->modify("+6 day")->setTime(23, 59, 59);
  return [$ini, $fin];
}

function defaultWeek(): array
{
  $today = (new DateTime('now'))->format('Y-m-d');
  return weekBoundsFrom($today);
}

/* ---------------- Semana seleccionada (Mar→Lun) ---------------- */
$anchor = !empty($_GET['ini']) ? $_GET['ini'] : (!empty($_GET['fin']) ? $_GET['fin'] : null);
if ($anchor) {
  [$ini, $fin] = weekBoundsFrom($anchor);
} else {
  [$ini, $fin] = defaultWeek();
}

$iniStr  = $ini->format('Y-m-d');
$finStr  = $fin->format('Y-m-d');
$dtIni0  = $ini->format('Y-m-d 00:00:00');
$dtFin0  = $fin->format('Y-m-d 23:59:59');

/* =========================
   NUEVO: Crear descuento desde modal (solo Admin/RH)
   ========================= */
$flash_ok  = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crear_descuento_nomina') {
  $rol = $_SESSION['rol'] ?? '';
  if (!in_array($rol, ['Admin', 'RH'], true)) {
    $_SESSION['flash_err'] = "No autorizado para capturar descuentos.";
    header("Location: reporte_nomina_v2.php?ini=" . urlencode($iniStr) . "&fin=" . urlencode($finStr));
    exit();
  }

  $idU      = (int)($_POST['id_usuario'] ?? 0);
  $concepto = trim((string)($_POST['concepto'] ?? ''));
  $monto    = (float)($_POST['monto'] ?? 0);

  if ($idU <= 0 || $concepto === '' || $monto <= 0) {
    $_SESSION['flash_err'] = "Completa usuario, concepto y monto (> 0).";
    header("Location: reporte_nomina_v2.php?ini=" . urlencode($iniStr) . "&fin=" . urlencode($finStr));
    exit();
  }

  $stmt = $conn->prepare("INSERT INTO descuentos_nomina (id_usuario, semana_inicio, semana_fin, concepto, monto, creado_por) VALUES (?,?,?,?,?,?)");
  $creadoPor = (int)($_SESSION['id_usuario'] ?? 0);
  $stmt->bind_param("isssdi", $idU, $iniStr, $finStr, $concepto, $monto, $creadoPor);

  if ($stmt->execute()) {
    $_SESSION['flash_ok'] = "Descuento registrado.";
  } else {
    $_SESSION['flash_err'] = "Error al registrar descuento: " . $conn->error;
  }
  $stmt->close();

  header("Location: reporte_nomina_v2.php?ini=" . urlencode($iniStr) . "&fin=" . urlencode($finStr));
  exit();
}

/* ---------------- Helper: condición para NO contar módems ---------------- */
// Filtro robusto: usa productos.tipo si existe; si no, analiza texto en varias columnas
function notModemSQL(mysqli $conn): string
{
  if (columnExists($conn, 'productos', 'tipo')) {
    return "LOWER(p.tipo) NOT IN ('modem','módem','mifi','mi-fi','hotspot','router')";
  }
  $cols = [];
  foreach (['marca', 'modelo', 'descripcion', 'nombre_comercial', 'categoria', 'codigo_producto'] as $col) {
    if (columnExists($conn, 'productos', $col)) $cols[] = "LOWER(COALESCE(p.$col,''))";
  }
  if (!$cols) return '1=1';
  $hay = "CONCAT(" . implode(", ' ', ", $cols) . ")";
  return "$hay NOT LIKE '%modem%'
          AND $hay NOT LIKE '%módem%'
          AND $hay NOT LIKE '%mifi%'
          AND $hay NOT LIKE '%mi-fi%'
          AND $hay NOT LIKE '%hotspot%'
          AND $hay NOT LIKE '%router%'";
}

/* ------------- Sumas detalle_venta (genérico) ------------- */
function sumDetalleVentaCampo(mysqli $conn, int $idUsuario, string $ini, string $fin, string $campo = 'comision'): float
{
  $colFecha = dateColVentas($conn);
  $sql = "
    SELECT COALESCE(SUM(d.$campo),0) AS s
    FROM detalle_venta d
    INNER JOIN ventas v ON v.id = d.id_venta
    WHERE v.id_usuario = ? AND v.$colFecha BETWEEN ? AND ?
  ";
  $stmt = $conn->prepare($sql);
  $pIni = $ini . ' 00:00:00';
  $pFin = $fin . ' 23:59:59';
  $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}

/**
 * Comisión de equipos del EJECUTIVO:
 *  - Si $aplicaEspecial = true y existe columna comision_especial:
 *      SUM(comision + comision_especial)
 *  - Si no:
 *      SUM(comision)
 */
function sumDetalleVentaEquiposEjecutivo(mysqli $conn, int $idUsuario, string $ini, string $fin, bool $aplicaEspecial): float
{
  $colFecha  = dateColVentas($conn);
  $hasComEsp = columnExists($conn, 'detalle_venta', 'comision_especial');
  $pIni = $ini . ' 00:00:00';
  $pFin = $fin . ' 23:59:59';

  if ($aplicaEspecial && $hasComEsp) {
    $sql = "
      SELECT COALESCE(SUM(d.comision + d.comision_especial),0) AS s
      FROM detalle_venta d
      INNER JOIN ventas v ON v.id = d.id_venta
      WHERE v.id_usuario = ? AND v.$colFecha BETWEEN ? AND ?
    ";
  } else {
    $sql = "
      SELECT COALESCE(SUM(d.comision),0) AS s
      FROM detalle_venta d
      INNER JOIN ventas v ON v.id = d.id_venta
      WHERE v.id_usuario = ? AND v.$colFecha BETWEEN ? AND ?
    ";
  }

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}

/** Conteo de equipos totales (Eq #)
 *  - Con detalle_venta: cuenta renglones del detalle EXCLUYENDO módem/MiFi => combo con 2 productos (no módem) cuenta 2.
 *  - Sin detalle_venta: si hay v.tipo_venta, SUM(CASE WHEN tipo_venta LIKE '%combo%' THEN 2 ELSE 1 END); si no, COUNT(*).
 */
function countEquipos(mysqli $conn, int $idUsuario, string $ini, string $fin): int
{
  $colFecha = dateColVentas($conn);
  $pIni = $ini . ' 00:00:00';
  $pFin = $fin . ' 23:59:59';
  $tieneDet = columnExists($conn, 'detalle_venta', 'id');

  if ($tieneDet) {
    $joinProd = columnExists($conn, 'productos', 'id') ? "LEFT JOIN productos p ON p.id = d.id_producto" : "";
    $condNoModem = notModemSQL($conn);
    $sql = "
      SELECT COUNT(d.id) AS c
      FROM detalle_venta d
      INNER JOIN ventas v ON v.id = d.id_venta
      $joinProd
      WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? AND ($condNoModem)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
  }

  $tieneTipoVenta = columnExists($conn, 'ventas', 'tipo_venta');
  $estatusCond = columnExists($conn, 'ventas', 'estatus')
    ? " AND (v.estatus IS NULL OR v.estatus NOT IN ('Cancelada','Cancelado','cancelada','cancelado'))"
    : "";

  if ($tieneTipoVenta) {
    $sql = "
      SELECT COALESCE(SUM(CASE WHEN LOWER(v.tipo_venta) LIKE '%combo%' THEN 2 ELSE 1 END),0) AS c
      FROM ventas v
      WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? $estatusCond
    ";
  } else {
    $sql = "
      SELECT COUNT(*) AS c
      FROM ventas v
      WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? $estatusCond
    ";
  }

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int)($row['c'] ?? 0);
}

/**
 * Unidades elegibles para bono provisional del Ejecutivo:
 * - Deben ser ventas de EQUIPO principal: sin módem y sin combo.
 * - Se cuentan como VENTAS (COUNT DISTINCT v.id), no como renglones de detalle.
 * - Si hay columna detalle_venta.es_combo, usamos es_combo = 0; si no, usamos ventas.tipo_venta NOT LIKE '%combo%'.
 */
function countEligibleUnitsForBonus(mysqli $conn, int $idUsuario, string $ini, string $fin): int
{
  $colFecha   = dateColVentas($conn);
  $pIni       = $ini . ' 00:00:00';
  $pFin       = $fin . ' 23:59:59';
  $tieneDet   = columnExists($conn, 'detalle_venta', 'id');
  $tieneTipo  = columnExists($conn, 'ventas', 'tipo_venta');
  $tieneCombo = columnExists($conn, 'detalle_venta', 'es_combo');

  if ($tieneDet) {
    $joinProd = columnExists($conn, 'productos', 'id') ? "LEFT JOIN productos p ON p.id = d.id_producto" : "";
    $condNoModem = notModemSQL($conn);

    // Condición de "no combo"
    if ($tieneCombo) {
      $condNoCombo = "(d.es_combo = 0 OR d.es_combo IS NULL)";
    } elseif ($tieneTipo) {
      $condNoCombo = "(LOWER(v.tipo_venta) NOT LIKE '%combo%')";
    } else {
      $condNoCombo = "1=1";
    }

    $sql = "
      SELECT COUNT(DISTINCT v.id) AS c
      FROM detalle_venta d
      INNER JOIN ventas v ON v.id = d.id_venta
      $joinProd
      WHERE v.id_usuario = ?
        AND v.$colFecha BETWEEN ? AND ?
        AND ($condNoCombo)
        AND ($condNoModem)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
  }

  // Fallback sin detalle_venta: contamos ventas (no combo si se puede)
  if ($tieneTipo) {
    $sql = "
      SELECT COUNT(*) AS c
      FROM ventas v
      WHERE v.id_usuario = ?
        AND v.$colFecha BETWEEN ? AND ?
        AND (LOWER(v.tipo_venta) NOT LIKE '%combo%')
    ";
  } else {
    $sql = "
      SELECT COUNT(*) AS c
      FROM ventas v
      WHERE v.id_usuario = ?
        AND v.$colFecha BETWEEN ? AND ?
    ";
  }
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int)($row['c'] ?? 0);
}

function sumSims(mysqli $conn, int $idUsuario, string $ini, string $fin, bool $soloPospago, string $campo = 'comision_ejecutivo'): float
{
  $pIni = $ini . ' 00:00:00';
  $pFin = $fin . ' 23:59:59';
  if ($soloPospago) {
    $sql = "SELECT COALESCE(SUM($campo),0) AS s
            FROM ventas_sims
            WHERE id_usuario=? AND tipo_venta='Pospago' AND fecha_venta BETWEEN ? AND ?";
  } else {
    $sql = "SELECT COALESCE(SUM($campo),0) AS s
            FROM ventas_sims
            WHERE id_usuario=? AND tipo_venta<>'Pospago' AND fecha_venta BETWEEN ? AND ?";
  }
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}

function sumPayjoyTC(mysqli $conn, int $idUsuario, string $ini, string $fin, string $campo = 'comision'): float
{
  $sql = "SELECT COALESCE(SUM($campo),0) AS s
          FROM ventas_payjoy_tc WHERE id_usuario=? AND fecha_venta BETWEEN ? AND ?";
  $stmt = $conn->prepare($sql);
  $pIni = $ini . ' 00:00:00';
  $pFin = $fin . ' 23:59:59';
  $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}

/* ---------------- Comisiones Accesorios (cálculo dinámico, sin tocar BD) ----------------
   Regla actual:
   - Ejecutivo: 4% sobre ventas_accesorios.total
   - Gerente:   2% sobre ventas_accesorios.total de la sucursal
   - Excluye promociones tipo_promocion = 'EMPLEADO_50'
   - No depende de cumplimiento de cuota
*/
function accesoriosPromoExclusionSQL(mysqli $conn): string
{
  if (columnExists($conn, 'ventas_accesorios', 'tipo_promocion')) {
    return " AND (tipo_promocion IS NULL OR tipo_promocion <> 'EMPLEADO_50')";
  }
  return "";
}

function sumAccesoriosEjecutivo(mysqli $conn, int $idUsuario, string $ini, string $fin): float
{
  if (!columnExists($conn, 'ventas_accesorios', 'id')) return 0.0;
  if (!columnExists($conn, 'ventas_accesorios', 'total')) return 0.0;
  if (!columnExists($conn, 'ventas_accesorios', 'id_usuario')) return 0.0;
  if (!columnExists($conn, 'ventas_accesorios', 'fecha_venta')) return 0.0;

  $pIni = $ini . ' 00:00:00';
  $pFin = $fin . ' 23:59:59';
  $extra = accesoriosPromoExclusionSQL($conn);

  $sql = "
    SELECT COALESCE(SUM(total),0) * 0.04 AS s
    FROM ventas_accesorios
    WHERE id_usuario = ?
      AND fecha_venta BETWEEN ? AND ?
      $extra
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('iss', $idUsuario, $pIni, $pFin);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}

function sumGerenteAccesoriosPorSucursal(mysqli $conn, int $idSucursal, string $ini, string $fin): float
{
  if (!columnExists($conn, 'ventas_accesorios', 'id')) return 0.0;
  if (!columnExists($conn, 'ventas_accesorios', 'total')) return 0.0;
  if (!columnExists($conn, 'ventas_accesorios', 'id_sucursal')) return 0.0;
  if (!columnExists($conn, 'ventas_accesorios', 'fecha_venta')) return 0.0;

  $pIni = $ini . ' 00:00:00';
  $pFin = $fin . ' 23:59:59';
  $extra = accesoriosPromoExclusionSQL($conn);

  $sql = "
    SELECT COALESCE(SUM(total),0) * 0.02 AS s
    FROM ventas_accesorios
    WHERE id_sucursal = ?
      AND fecha_venta BETWEEN ? AND ?
      $extra
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('iss', $idSucursal, $pIni, $pFin);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}

/* =========================
   NUEVO: Sumas por SUCURSAL (a prueba de usuarios inactivos)
   - Objetivo: que G. Eq / G. SIMs / G. Posp / G. TC en el renglón del gerente
     sea la suma REAL de la sucursal en la semana, sin depender del listado de
     usuarios activos (ni de quién vendió).
   ========================= */

function sumGerenteEquiposPorSucursal(mysqli $conn, int $idSucursal, string $ini, string $fin): float
{
  // Requiere: ventas.id_sucursal + detalle_venta.comision_gerente
  $colFecha = dateColVentas($conn);
  $pIni = $ini . ' 00:00:00';
  $pFin = $fin . ' 23:59:59';

  if (!columnExists($conn, 'ventas', 'id_sucursal')) return 0.0;
  if (!columnExists($conn, 'detalle_venta', 'comision_gerente')) return 0.0;

  $sql = "
    SELECT COALESCE(SUM(d.comision_gerente),0) AS s
    FROM detalle_venta d
    INNER JOIN ventas v ON v.id = d.id_venta
    WHERE v.id_sucursal = ?
      AND v.$colFecha BETWEEN ? AND ?
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('iss', $idSucursal, $pIni, $pFin);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}

function sumGerenteSimsPorSucursal(mysqli $conn, int $idSucursal, string $ini, string $fin, bool $soloPospago): float
{
  // Preferencia: ventas_sims.id_sucursal si existe.
  // Fallback: join con usuarios (incluye inactivos) por id_sucursal.
  $pIni = $ini . ' 00:00:00';
  $pFin = $fin . ' 23:59:59';

  if (!columnExists($conn, 'ventas_sims', 'comision_gerente')) return 0.0;

  $whereTipo = $soloPospago ? "tipo_venta='Pospago'" : "tipo_venta<>'Pospago'";

  if (columnExists($conn, 'ventas_sims', 'id_sucursal')) {
    $sql = "
      SELECT COALESCE(SUM(comision_gerente),0) AS s
      FROM ventas_sims
      WHERE id_sucursal=?
        AND $whereTipo
        AND fecha_venta BETWEEN ? AND ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $idSucursal, $pIni, $pFin);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['s'] ?? 0);
  }

  // Fallback sin id_sucursal en ventas_sims
  if (columnExists($conn, 'usuarios', 'id_sucursal')) {
    $sql = "
      SELECT COALESCE(SUM(vs.comision_gerente),0) AS s
      FROM ventas_sims vs
      INNER JOIN usuarios u ON u.id = vs.id_usuario
      WHERE u.id_sucursal=?
        AND vs.$whereTipo
        AND vs.fecha_venta BETWEEN ? AND ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $idSucursal, $pIni, $pFin);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['s'] ?? 0);
  }

  return 0.0;
}

function sumGerentePayjoyTCPorSucursal(mysqli $conn, int $idSucursal, string $ini, string $fin): float
{
  // Preferencia: ventas_payjoy_tc.id_sucursal si existe.
  // Fallback: join con usuarios (incluye inactivos) por id_sucursal.
  $pIni = $ini . ' 00:00:00';
  $pFin = $fin . ' 23:59:59';

  if (!columnExists($conn, 'ventas_payjoy_tc', 'comision_gerente')) return 0.0;

  if (columnExists($conn, 'ventas_payjoy_tc', 'id_sucursal')) {
    $sql = "
      SELECT COALESCE(SUM(comision_gerente),0) AS s
      FROM ventas_payjoy_tc
      WHERE id_sucursal=?
        AND fecha_venta BETWEEN ? AND ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $idSucursal, $pIni, $pFin);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['s'] ?? 0);
  }

  // Fallback sin id_sucursal en ventas_payjoy_tc
  if (columnExists($conn, 'usuarios', 'id_sucursal')) {
    $sql = "
      SELECT COALESCE(SUM(vt.comision_gerente),0) AS s
      FROM ventas_payjoy_tc vt
      INNER JOIN usuarios u ON u.id = vt.id_usuario
      WHERE u.id_sucursal=?
        AND vt.fecha_venta BETWEEN ? AND ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $idSucursal, $pIni, $pFin);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['s'] ?? 0);
  }

  return 0.0;
}

function sumDescuentos(mysqli $conn, int $idUsuario, string $ini, string $fin): float
{
  $sql = "SELECT COALESCE(SUM(monto),0) AS s
          FROM descuentos_nomina
          WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("iss", $idUsuario, $ini, $fin);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}

function sumAjusteTipo(mysqli $conn, int $idUsuario, string $ini, string $fin, string $tipo): float
{
  $sql = "SELECT COALESCE(SUM(monto),0) AS s
          FROM nomina_ajustes_v2
          WHERE id_usuario=? AND semana_inicio=? AND semana_fin=? AND tipo=?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("isss", $idUsuario, $ini, $fin, $tipo);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}

/* ---------------- Usuarios activos + sucursales Tienda/Propia ---------------- */
$colUserSuc = null;
if (columnExists($conn, 'usuarios', 'sucursal'))    $colUserSuc = 'sucursal';
if (columnExists($conn, 'usuarios', 'id_sucursal')) $colUserSuc = 'id_sucursal';

$usuarios = [];

if ($colUserSuc) {
  $Q = "
    SELECT
      u.id, u.nombre, u.rol, u.sueldo, COALESCE(u.activo,1) AS activo,
      u.$colUserSuc AS id_sucursal, s.nombre AS sucursal_nombre,
      CASE WHEN u.rol='Gerente' THEN 0 WHEN u.rol='Ejecutivo' THEN 1 ELSE 2 END AS rol_orden
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.$colUserSuc
    WHERE (u.activo IS NULL OR u.activo=1)
      AND s.tipo_sucursal='Tienda'
      AND s.subtipo='Propia'
    ORDER BY s.nombre ASC, rol_orden ASC, u.nombre ASC
  ";
} else {
  $Q = "
    SELECT
      u.id, u.nombre, u.rol, u.sueldo, COALESCE(u.activo,1) AS activo,
      NULL AS id_sucursal, '(Sin sucursal)' AS sucursal_nombre,
      CASE WHEN u.rol='Gerente' THEN 0 WHEN u.rol='Ejecutivo' THEN 1 ELSE 2 END AS rol_orden
    FROM usuarios u
    WHERE (u.activo IS NULL || u.activo=1)
    ORDER BY sucursal_nombre ASC, rol_orden ASC, u.nombre ASC
  ";
}

$resU = $conn->query($Q);
while ($r = $resU->fetch_assoc()) $usuarios[] = $r;
$resU->close();

/* ---------------- Confirmaciones por semana (batch) ---------------- */
$confirmMap = []; // id_usuario => ['confirmado'=>0/1, 'confirmado_en'=>'...']
if (columnExists($conn, 'nomina_confirmaciones', 'id')) {
  $qi = $conn->real_escape_string($iniStr);
  $qf = $conn->real_escape_string($finStr);
  $qc = "
    SELECT id_usuario, confirmado, confirmado_en
    FROM nomina_confirmaciones
    WHERE semana_inicio='$qi' AND semana_fin='$qf'
  ";
  if ($resC = $conn->query($qc)) {
    while ($row = $resC->fetch_assoc()) {
      $confirmMap[(int)$row['id_usuario']] = [
        'confirmado'    => (int)$row['confirmado'],
        'confirmado_en' => $row['confirmado_en']
      ];
    }
    $resC->close();
  }
}

/* ---------------- Cuotas por sucursal (unidades y monto) ---------------- */
$cuotaUnidPorSuc  = []; // id_sucursal => cuota_unidades (int|null)
$cuotaMontoPorSuc = []; // id_sucursal => cuota_monto (float|null)

if ($colUserSuc) {
  // Cuota de unidades semanal
  if ($stmtCU = $conn->prepare("
        SELECT cuota_unidades
        FROM cuotas_semanales_sucursal
        WHERE id_sucursal=? AND semana_inicio<=? AND semana_fin>=?
        ORDER BY id DESC LIMIT 1
      ")) {
    // Cuota de monto sucursal
    $stmtCM = $conn->prepare("
        SELECT cuota_monto
        FROM cuotas_sucursales
        WHERE id_sucursal=? AND fecha_inicio<=?
        ORDER BY fecha_inicio DESC, id DESC
        LIMIT 1
      ");

    foreach ($usuarios as $u) {
      $sid = (int)($u['id_sucursal'] ?? 0);
      if ($sid <= 0) continue;

      if (!array_key_exists($sid, $cuotaUnidPorSuc)) {
        $stmtCU->bind_param("iss", $sid, $iniStr, $finStr);
        $stmtCU->execute();
        $row = $stmtCU->get_result()->fetch_assoc();
        $cuotaUnidPorSuc[$sid] = $row ? (int)$row['cuota_unidades'] : null;
      }
      if (!array_key_exists($sid, $cuotaMontoPorSuc)) {
        $stmtCM->bind_param("is", $sid, $finStr);
        $stmtCM->execute();
        $row2 = $stmtCM->get_result()->fetch_assoc();
        $cuotaMontoPorSuc[$sid] = $row2 ? (float)$row2['cuota_monto'] : null;
      }
    }
    $stmtCU->close();
    $stmtCM->close();
  }
}

/* ---------------- Cálculo por usuario (primer pase, RAW) ---------------- */
$data = [];
$eligibleUnitsSucursal = [];   // acumula unidades elegibles por sucursal (para bono Gerente)

foreach ($usuarios as $u) {
  $uid  = (int)$u['id'];
  $base = (float)($u['sueldo'] ?? 0);
  $sid  = (int)($u['id_sucursal'] ?? 0);
  $rol  = (string)$u['rol'];

  $cuota_unid  = $sid ? ($cuotaUnidPorSuc[$sid]  ?? null) : null;
  $cuota_monto = $sid ? ($cuotaMontoPorSuc[$sid] ?? null) : null;

  // Conteo de equipos de la semana (combo=2, sin módem)
  $eq_cnt  = countEquipos($conn, $uid, $iniStr, $finStr);

  // ¿Cumple cuota de unidades? (solo ejecutivos)
  $aplicaEspecial = (strcasecmp($rol, 'Ejecutivo') === 0 && $cuota_unid !== null && $eq_cnt >= $cuota_unid);

  // Eq ejecutivo: ya incluye comision_especial si aplica
  $eq_eje  = sumDetalleVentaEquiposEjecutivo($conn, $uid, $iniStr, $finStr, $aplicaEspecial);

  // SIMs / pospago / TC
  $sim_eje = sumSims($conn, $uid, $iniStr, $finStr, false, 'comision_ejecutivo');
  $pos_eje = sumSims($conn, $uid, $iniStr, $finStr, true,  'comision_ejecutivo');
  $tc_eje  = sumPayjoyTC($conn, $uid, $iniStr, $finStr, 'comision');
  $acc_eje = sumAccesoriosEjecutivo($conn, $uid, $iniStr, $finStr);

  // Comisiones gerente (raw, se redistribuyen después)
  $eq_ger  = sumDetalleVentaCampo($conn, $uid, $iniStr, $finStr, 'comision_gerente');
  $sim_ger = sumSims($conn, $uid, $iniStr, $finStr, false, 'comision_gerente');
  $pos_ger = sumSims($conn, $uid, $iniStr, $finStr, true,  'comision_gerente');
  $tc_ger  = sumPayjoyTC($conn, $uid, $iniStr, $finStr, 'comision_gerente');

  // Ajustes / descuentos
  $bonos   = sumAjusteTipo($conn, $uid, $iniStr, $finStr, 'bono');
  $ajuste  = sumAjusteTipo($conn, $uid, $iniStr, $finStr, 'ajuste');
  $descs   = sumDescuentos($conn, $uid, $iniStr, $finStr);

  // Unidades elegibles para bono provisional (ventas principales, sin módem y sin combo)
  $eligible_units = countEligibleUnitsForBonus($conn, $uid, $iniStr, $finStr);
  if ($sid > 0) {
    if (!array_key_exists($sid, $eligibleUnitsSucursal)) {
      $eligibleUnitsSucursal[$sid] = 0;
    }
    $eligibleUnitsSucursal[$sid] += $eligible_units;
  }

  // Bono provisional del ejecutivo:
  // $50 por venta elegible SI tiene al menos 10 ventas elegibles (no módem, no combo).
  $bono_prov_eje = 0.0;
  if (strcasecmp($rol, 'Ejecutivo') === 0 && $eligible_units >= 10) {
    $bono_prov_eje = $eligible_units * 50.0;
  }

  $total_raw = $base
    + $eq_eje + $sim_eje + $pos_eje + $tc_eje + $acc_eje
    + $eq_ger + $sim_ger + $pos_ger + $tc_ger
    + $bonos - $descs + $ajuste;

  $conf = $confirmMap[$uid] ?? ['confirmado' => 0, 'confirmado_en' => null];

  $data[] = [
    'id' => $uid,
    'nombre' => $u['nombre'],
    'rol' => $rol,
    'id_sucursal' => $sid,
    'sucursal_nombre' => $u['sucursal_nombre'],
    'eq_cnt' => $eq_cnt,
    'cuota_unid' => $cuota_unid,
    'cuota_monto' => $cuota_monto,
    'base' => $base,
    // ejecutivo
    'eq_eje' => $eq_eje,
    'sim_eje' => $sim_eje,
    'pos_eje' => $pos_eje,
    'tc_eje' => $tc_eje,
    'acc_eje' => $acc_eje,
    // gerente (raw)
    'eq_ger_raw' => $eq_ger,
    'sim_ger_raw' => $sim_ger,
    'pos_ger_raw' => $pos_ger,
    'tc_ger_raw' => $tc_ger,
    // mostrados (se rellenan en segundo pase)
    'eq_ger' => 0,
    'sim_ger' => 0,
    'pos_ger' => 0,
    'tc_ger' => 0,
    'acc_ger' => 0,
    // ajustes
    'bonos' => $bonos,
    'descuentos' => $descs,
    'ajuste' => $ajuste,
    // informativo
    'eligible_units' => $eligible_units,
    'bono_prov_eje' => $bono_prov_eje,
    'bono_provisional' => 0.0, // se define en segundo pase (ejecutivo + gerente)
    'total_raw' => $total_raw,
    'total' => 0,
    'confirmado' => $conf['confirmado'],
    'confirmado_en' => $conf['confirmado_en']
  ];
}

/* ---------------- Monto de ventas por sucursal (para cuota de gerente) ---------------- */
$montoSucPorSemana = [];
if (columnExists($conn, 'ventas', 'id_sucursal')) {
  $colFechaVentas = dateColVentas($conn);
  $pIniV = $iniStr . ' 00:00:00';
  $pFinV = $finStr . ' 23:59:59';

  $sqlMontoSuc = "
    SELECT id_sucursal, COALESCE(SUM(precio_venta),0) AS total
    FROM ventas
    WHERE $colFechaVentas BETWEEN ? AND ?
    GROUP BY id_sucursal
  ";
  if ($stMS = $conn->prepare($sqlMontoSuc)) {
    $stMS->bind_param("ss", $pIniV, $pFinV);
    $stMS->execute();
    $resMS = $stMS->get_result();
    while ($row = $resMS->fetch_assoc()) {
      $montoSucPorSemana[(int)$row['id_sucursal']] = (float)($row['total'] ?? 0);
    }
    $stMS->close();
  }
}

/* ---------------- Redistribución para mostrar: gerente por sucursal ----------------
   FIX: a prueba de usuarios inactivos (y de cualquier vendedor fuera del listado).
   Aquí NO usamos $data para sumar, sino queries por sucursal.
*/
$gerPorSucursal = []; // [id_sucursal] => ['eq'=>..,'sim'=>..,'pos'=>..,'tc'=>..,'acc'=>..]

// Prepara buckets solo para las sucursales presentes en esta nómina
foreach ($data as $r) {
  $sid = (int)($r['id_sucursal'] ?? 0);
  if ($sid > 0 && !isset($gerPorSucursal[$sid])) {
    $gerPorSucursal[$sid] = ['eq' => 0.0, 'sim' => 0.0, 'pos' => 0.0, 'tc' => 0.0, 'acc' => 0.0];
  }
}

// Calcula comisiones gerente reales por sucursal (incluye vendedores inactivos)
foreach ($gerPorSucursal as $sid => $_bucket) {
  $sid = (int)$sid;
  $gerPorSucursal[$sid]['eq']  = sumGerenteEquiposPorSucursal($conn, $sid, $iniStr, $finStr);
  $gerPorSucursal[$sid]['sim'] = sumGerenteSimsPorSucursal($conn, $sid, $iniStr, $finStr, false);
  $gerPorSucursal[$sid]['pos'] = sumGerenteSimsPorSucursal($conn, $sid, $iniStr, $finStr, true);
  $gerPorSucursal[$sid]['tc']  = sumGerentePayjoyTCPorSucursal($conn, $sid, $iniStr, $finStr);
  $gerPorSucursal[$sid]['acc'] = sumGerenteAccesoriosPorSucursal($conn, $sid, $iniStr, $finStr);
}

// Segundo pase: set de columnas mostradas, BONO PROVISIONAL final y TOTAL calculado con lo mostrado
foreach ($data as &$r) {
  $sid  = (int)($r['id_sucursal'] ?? 0);
  $rolR = (string)$r['rol'];

  // Comisiones de gerente visibles (eq/sim/pos/tc) concentradas en el renglón del gerente
  if (strcasecmp($rolR, 'Gerente') === 0) {
    $r['eq_ger']  = (float)($gerPorSucursal[$sid]['eq']  ?? 0);
    $r['sim_ger'] = (float)($gerPorSucursal[$sid]['sim'] ?? 0);
    $r['pos_ger'] = (float)($gerPorSucursal[$sid]['pos'] ?? 0);
    $r['tc_ger']  = (float)($gerPorSucursal[$sid]['tc']  ?? 0);
    $r['acc_ger'] = (float)($gerPorSucursal[$sid]['acc'] ?? 0);
  } else {
    $r['eq_ger'] = $r['sim_ger'] = $r['pos_ger'] = $r['tc_ger'] = $r['acc_ger'] = 0.0;
  }

  // Bono provisional:
  // - Ejecutivos: 'bono_prov_eje' calculado en el primer pase.
  // - Gerentes: $50 por cada unidad elegible de la sucursal,
  //             solo si la sucursal llegó a su cuota_monto.
  $bono = (float)($r['bono_prov_eje'] ?? 0);

  if (strcasecmp($rolR, 'Gerente') === 0 && $sid > 0) {
    $cuota_monto = $r['cuota_monto'];
    $montoSuc    = $montoSucPorSemana[$sid] ?? null;
    $unidadesSuc = (int)($eligibleUnitsSucursal[$sid] ?? 0);

    if ($cuota_monto !== null && $montoSuc !== null && $montoSuc >= $cuota_monto && $unidadesSuc > 0) {
      $bono += $unidadesSuc * 0.0;
    }
  }

  $r['bono_provisional'] = $bono;

  // Recalcular total usando valores mostrados (no incluye bono_provisional, sigue siendo informativo)
  $r['total'] = (float)$r['base']
    + (float)$r['eq_eje'] + (float)$r['sim_eje'] + (float)$r['pos_eje'] + (float)$r['tc_eje'] + (float)$r['acc_eje']
    + (float)$r['eq_ger'] + (float)$r['sim_ger'] + (float)$r['pos_ger'] + (float)$r['tc_ger'] + (float)$r['acc_ger']
    + (float)$r['bonos'] - (float)$r['descuentos'] + (float)$r['ajuste'];
}
unset($r);

/* ---------------- Totales y KPIs (con valores mostrados) ---------------- */
$tot = [
  'base' => 0,
  'eq_cnt' => 0,
  'eq_eje' => 0,
  'sim_eje' => 0,
  'pos_eje' => 0,
  'tc_eje' => 0,
  'acc_eje' => 0,
  'eq_ger' => 0,
  'sim_ger' => 0,
  'pos_ger' => 0,
  'tc_ger' => 0,
  'acc_ger' => 0,
  'bonos' => 0,
  'descuentos' => 0,
  'ajuste' => 0,
  'total' => 0,
  'bono_provisional' => 0
];

foreach ($data as $r) {
  foreach ($tot as $k => $_) {
    $tot[$k] += (float)($r[$k] ?? 0);
  }
}

$empleadosActivos = count($data);
$comisiones_ejecutivo = $tot['eq_eje'] + $tot['sim_eje'] + $tot['pos_eje'] + $tot['tc_eje'] + $tot['acc_eje'];
$comisiones_gerente   = $tot['eq_ger'] + $tot['sim_ger'] + $tot['pos_ger'] + $tot['tc_ger'] + $tot['acc_ger']; // ahora solo las que se muestran a gerentes
$comisiones_totales   = $comisiones_ejecutivo + $comisiones_gerente;
$confirmados          = array_sum(array_map(fn($r) => (int)($r['confirmado'] ?? 0), $data));

/* ---------------- Export Excel XLSX Zentral ---------------- */

$headers = [
  'Nombre',
  'Rol',
  'Sucursal',
  'Eq #',
  'Cuota',
  'Base',
  'Eq',
  'SIMs',
  'Posp',
  'TC',
  'Acc',
  'G. Eq',
  'G. SIMs',
  'G. Posp',
  'G. TC',
  'G. Acc',
  'Bono prov.',
  'Bonos',
  'Desc',
  'Ajuste',
  'Total',
  'Confirmación'
];

$rows = [];

foreach ($data as $r) {
  $esGerente = (strcasecmp((string)$r['rol'], 'Gerente') === 0);

  if ($esGerente) {
    $cuota = $r['cuota_monto'] !== null ? (float)$r['cuota_monto'] : '';
  } else {
    $cuota = $r['cuota_unid'] !== null ? (int)$r['cuota_unid'] : '';
  }

  $confirmacion = ((int)($r['confirmado'] ?? 0) === 1)
    ? ('Confirmada' . (!empty($r['confirmado_en']) ? ' · ' . $r['confirmado_en'] : ''))
    : 'Pendiente';

  $rows[] = [
    $r['nombre'] ?? '',
    $r['rol'] ?? '',
    $r['sucursal_nombre'] ?? '',
    (int)($r['eq_cnt'] ?? 0),
    $cuota,
    (float)($r['base'] ?? 0),
    (float)($r['eq_eje'] ?? 0),
    (float)($r['sim_eje'] ?? 0),
    (float)($r['pos_eje'] ?? 0),
    (float)($r['tc_eje'] ?? 0),
    (float)($r['acc_eje'] ?? 0),
    (float)($r['eq_ger'] ?? 0),
    (float)($r['sim_ger'] ?? 0),
    (float)($r['pos_ger'] ?? 0),
    (float)($r['tc_ger'] ?? 0),
    (float)($r['acc_ger'] ?? 0),
    (float)($r['bono_provisional'] ?? 0),
    (float)($r['bonos'] ?? 0),
    (float)($r['descuentos'] ?? 0),
    (float)($r['ajuste'] ?? 0),
    (float)($r['total'] ?? 0),
    $confirmacion
  ];
}

/* Fila de totales */
$rows[] = [
  'TOTAL',
  '',
  '',
  (int)($tot['eq_cnt'] ?? 0),
  '',
  (float)($tot['base'] ?? 0),
  (float)($tot['eq_eje'] ?? 0),
  (float)($tot['sim_eje'] ?? 0),
  (float)($tot['pos_eje'] ?? 0),
  (float)($tot['tc_eje'] ?? 0),
  (float)($tot['acc_eje'] ?? 0),
  (float)($tot['eq_ger'] ?? 0),
  (float)($tot['sim_ger'] ?? 0),
  (float)($tot['pos_ger'] ?? 0),
  (float)($tot['tc_ger'] ?? 0),
  (float)($tot['acc_ger'] ?? 0),
  (float)($tot['bono_provisional'] ?? 0),
  (float)($tot['bonos'] ?? 0),
  (float)($tot['descuentos'] ?? 0),
  (float)($tot['ajuste'] ?? 0),
  (float)($tot['total'] ?? 0),
  ''
];

$spreadsheet = zentralCrearSpreadsheet('Nómina semanal');
$sheet = $spreadsheet->getActiveSheet();

$fila = zentralAgregarEncabezado(
  $sheet,
  'Nómina semanal',
  'Semana: ' . date('d/m/Y', strtotime($iniStr)) . ' al ' . date('d/m/Y', strtotime($finStr)) . ' · Mar→Lun'
);

$fila = zentralAgregarKPIs($sheet, [
  'Empleados' => number_format($empleadosActivos),
  'Confirmados' => number_format($confirmados) . '/' . number_format($empleadosActivos),
  'Equipos' => number_format($tot['eq_cnt']),
  'Com. Ejecutivos' => '$' . number_format($comisiones_ejecutivo, 2),
  'Com. Gerentes' => '$' . number_format($comisiones_gerente, 2),
  'Total nómina' => '$' . number_format($tot['total'], 2),
], $fila);

$moneyColumns = [];
foreach ($headers as $i => $header) {
  if (in_array($header, [
    'Cuota',
    'Base',
    'Eq',
    'SIMs',
    'Posp',
    'TC',
    'Acc',
    'G. Eq',
    'G. SIMs',
    'G. Posp',
    'G. TC',
    'G. Acc',
    'Bono prov.',
    'Bonos',
    'Desc',
    'Ajuste',
    'Total'
  ], true)) {
    $moneyColumns[] = $i + 1;
  }
}

$filaTabla = $fila;
$fila = zentralAgregarTabla($sheet, $headers, $rows, $filaTabla, $moneyColumns);

/* Destacar fila total */
$lastRow = $fila - 2;
$lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
$sheet->getStyle("A{$lastRow}:{$lastCol}{$lastRow}")->getFont()->setBold(true);
$sheet->getStyle("A{$lastRow}:{$lastCol}{$lastRow}")->getFill()
  ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
  ->getStartColor()->setRGB('EAF3FF');

zentralAplicarPie($sheet, $fila);
zentralAutoSize($sheet);

zentralDescargarExcel($spreadsheet, 'nomina_' . $iniStr . '_al_' . $finStr . '.xlsx');
