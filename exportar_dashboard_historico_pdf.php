<?php
// exportar_dashboard_historico_pdf.php — PDF Ejecutivo Dashboard Histórico Zentral
// Requiere: composer require dompdf/dompdf

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
  http_response_code(500);
  die('No se encontró vendor/autoload.php. Instala Dompdf con: composer require dompdf/dompdf');
}
require_once $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

/* ==============================
   Helpers
================================= */
function h($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function moneyPdf($n) {
  return '$' . number_format((float)$n, 2);
}

function pctPdf($n) {
  return number_format((float)$n, 1) . '%';
}

function normalizarZona($raw) {
  $t = trim((string)$raw);
  if ($t === '') return null;
  $t = preg_replace('/\s+/', ' ', $t);

  if (preg_match('/^Zona\s+/i', $t)) return ucwords(strtolower($t));
  if (!preg_match('/\d+/', $t)) return 'Zona ' . ucwords(strtolower($t));
  if (preg_match('/(\d+)/', $t, $m)) return 'Zona ' . (int)$m[1];

  return null;
}

function sucursalCorta($nombre) {
  $n = preg_replace('/^\s*Luga\s+/i', '', (string)$nombre);
  return trim($n);
}

function obtenerSemanaPorIndice($offset = 0) {
  $tz = new DateTimeZone('America/Mexico_City');
  $hoy = new DateTime('now', $tz);
  $diaSemana = (int)$hoy->format('N');
  $dif = $diaSemana - 2;
  if ($dif < 0) $dif += 7;

  $inicio = new DateTime('now', $tz);
  $inicio->modify("-$dif days")->setTime(0, 0, 0);

  if ($offset > 0) $inicio->modify('-' . (7 * $offset) . ' days');

  $fin = clone $inicio;
  $fin->modify('+6 days')->setTime(23, 59, 59);

  return [$inicio, $fin];
}

function rangoHistorico($semanas, $offsetSemana = 0) {
  [$inicioBase, $finBase] = obtenerSemanaPorIndice($offsetSemana);
  $inicio = clone $inicioBase;
  $inicio->modify('-' . (7 * ((int)$semanas - 1)) . ' days')->setTime(0, 0, 0);
  return [$inicio, $finBase];
}

function rangoHistoricoAnterior($semanas, $offsetSemana = 0) {
  [$inicioActual, $finActual] = rangoHistorico($semanas, $offsetSemana);
  $finPrev = clone $inicioActual;
  $finPrev->modify('-1 day')->setTime(23, 59, 59);
  $inicioPrev = clone $finPrev;
  $inicioPrev->modify('-' . (7 * (int)$semanas - 1) . ' days')->setTime(0, 0, 0);
  return [$inicioPrev, $finPrev];
}

function hasColumn(mysqli $conn, string $table, string $column): bool {
  $tableEsc = $conn->real_escape_string($table);
  $columnEsc = $conn->real_escape_string($column);
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = '{$tableEsc}'
      AND COLUMN_NAME = '{$columnEsc}'
    LIMIT 1
  ";
  $rs = $conn->query($sql);
  return $rs && $rs->num_rows > 0;
}

function tendenciaTexto($actual, $previo) {
  if ($previo <= 0 && $actual > 0) return 'Nueva actividad';
  if ($previo <= 0 && $actual <= 0) return 'Sin movimiento';
  $delta = (($actual - $previo) / $previo) * 100;
  if ($delta >= 12) return 'Creciendo';
  if ($delta <= -12) return 'Bajando';
  return 'Estable';
}

function heatClassPdf($cumplimiento, $cuota, $ventas) {
  if ($cuota <= 0 && $ventas <= 0) return 'heat-none';
  if ($cumplimiento >= 100) return 'heat-good';
  if ($cumplimiento >= 60) return 'heat-mid';
  return 'heat-low';
}

/* ==============================
   Scope
================================= */
$ROL_RAW = $_SESSION['rol'] ?? '';
$ROL = strtolower(trim((string)$ROL_RAW));
$isSubdisUser = (strpos($ROL, 'subdis') === 0);
$idSubdis = (int)($_SESSION['id_subdis'] ?? 0);

if ($isSubdisUser && $idSubdis <= 0) {
  $idSucUser = (int)($_SESSION['id_sucursal'] ?? 0);
  if ($idSucUser > 0 && ($st = $conn->prepare("SELECT id_subdis FROM sucursales WHERE id=? LIMIT 1"))) {
    $st->bind_param("i", $idSucUser);
    $st->execute();
    $rs = $st->get_result();
    if ($rs && ($row = $rs->fetch_assoc())) $idSubdis = (int)($row['id_subdis'] ?? 0);
    $st->close();
  }
}

$subdisNombre = '';
if ($isSubdisUser && $idSubdis > 0) {
  if ($st = $conn->prepare("SELECT nombre_comercial FROM subdistribuidores WHERE id=? LIMIT 1")) {
    $st->bind_param("i", $idSubdis);
    $st->execute();
    $rs = $st->get_result();
    if ($rs && ($r = $rs->fetch_assoc())) $subdisNombre = trim((string)($r['nombre_comercial'] ?? ''));
    $st->close();
  }
}

$scopeSucursalesWhere = $isSubdisUser
  ? "s.id_subdis = ?"
  : "((s.propiedad IS NULL OR s.propiedad='' OR LOWER(s.propiedad)='luga') AND (s.id_subdis IS NULL OR s.id_subdis=0))";

$scopeScWhere = $isSubdisUser
  ? "sc.id_subdis = ?"
  : "((sc.propiedad IS NULL OR sc.propiedad='' OR LOWER(sc.propiedad)='luga') AND (sc.id_subdis IS NULL OR sc.id_subdis=0))";

$rolesUsuariosIn = $isSubdisUser
  ? "('subdis_ejecutivo','subdis_gerente')"
  : "('Ejecutivo','Gerente')";

/* ==============================
   Parámetros
================================= */
$periodosValidos = [4, 8, 13, 26, 52];
$periodo = isset($_GET['periodo']) ? (int)$_GET['periodo'] : 13;
if (!in_array($periodo, $periodosValidos, true)) $periodo = 13;

$semanaSeleccionada = isset($_GET['semana']) ? max(0, (int)$_GET['semana']) : 0;

[$inicioObj, $finObj] = rangoHistorico($periodo, $semanaSeleccionada);
[$inicioPrevObj, $finPrevObj] = rangoHistoricoAnterior($periodo, $semanaSeleccionada);

$inicio = $inicioObj->format('Y-m-d');
$fin = $finObj->format('Y-m-d');
$inicioPrev = $inicioPrevObj->format('Y-m-d');
$finPrev = $finPrevObj->format('Y-m-d');

$empresaZentral = 'Luga';
if ($isSubdisUser && $subdisNombre !== '') {
  $empresaZentral = $subdisNombre;
} elseif (defined('BRAND_NAME')) {
  $empresaZentral = BRAND_NAME;
}

/* ==============================
   Producto tipo
================================= */
$colTipoProd = 'tipo_producto';
try {
  if (hasColumn($conn, 'productos', 'tipo')) $colTipoProd = 'tipo';
  elseif (hasColumn($conn, 'productos', 'tipo_producto')) $colTipoProd = 'tipo_producto';
} catch (Exception $e) {}

/* ==============================
   Ventas agregadas
================================= */
$subVentasAgg = "
  SELECT
    v.id,
    v.id_usuario,
    v.id_sucursal,
    DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
    CASE
      WHEN LOWER(v.tipo_venta)='financiamiento+combo' THEN 2
      ELSE COALESCE(d.has_non_modem,1)
    END AS unidades,
    CASE
      WHEN COALESCE(d.has_non_modem,1)=1 THEN v.precio_venta
      ELSE 0
    END AS monto
  FROM ventas v
  LEFT JOIN (
    SELECT dv.id_venta,
           MAX(CASE
                 WHEN LOWER(COALESCE(p.$colTipoProd,'')) IN ('modem','mifi') THEN 0
                 ELSE 1
               END) AS has_non_modem
    FROM detalle_venta dv
    LEFT JOIN productos p ON p.id = dv.id_producto
    GROUP BY dv.id_venta
  ) d ON d.id_venta = v.id
  WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY v.id
";

/* ==============================
   Sucursales base
================================= */
$sucIds = [];
$sqlSucBase = "
  SELECT s.id
  FROM sucursales s
  WHERE s.tipo_sucursal='Tienda'
    AND s.activo=1
    AND $scopeSucursalesWhere
";
if ($st = $conn->prepare($sqlSucBase)) {
  if ($isSubdisUser) $st->bind_param('i', $idSubdis);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) $sucIds[] = (int)$r['id'];
  $st->close();
}

/* ==============================
   Serie semanal
================================= */
$seriesGlobal = [];
$cursor = clone $inicioObj;
for ($i = 0; $i < $periodo; $i++) {
  $wk = $cursor->format('Y-m-d');
  $seriesGlobal[$wk] = [
    'semana_inicio' => $wk,
    'unidades' => 0,
    'ventas' => 0.0,
    'sim_pre' => 0,
    'sim_pos' => 0,
    'cuota' => 0.0,
  ];
  $cursor->modify('+7 days');
}

$sqlSeries = "
  SELECT
    va.semana_inicio,
    IFNULL(SUM(va.unidades),0) AS unidades,
    IFNULL(SUM(va.monto),0) AS ventas
  FROM (
    SELECT x.*, DATE_SUB(x.dia, INTERVAL ((DAYOFWEEK(x.dia)+4) % 7) DAY) AS semana_inicio
    FROM ($subVentasAgg) x
  ) va
  INNER JOIN sucursales s ON s.id = va.id_sucursal
  WHERE s.tipo_sucursal='Tienda'
    AND s.activo=1
    AND $scopeSucursalesWhere
  GROUP BY va.semana_inicio
  ORDER BY va.semana_inicio ASC
";
if ($st = $conn->prepare($sqlSeries)) {
  if ($isSubdisUser) $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  else $st->bind_param('ss', $inicio, $fin);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $wk = (string)$r['semana_inicio'];
    if (!isset($seriesGlobal[$wk])) continue;
    $seriesGlobal[$wk]['unidades'] = (int)$r['unidades'];
    $seriesGlobal[$wk]['ventas'] = (float)$r['ventas'];
  }
  $st->close();
}

$sqlSimsSemana = "
  SELECT
    DATE_SUB(DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')), INTERVAL ((DAYOFWEEK(DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')))+4) % 7) DAY) AS semana_inicio,
    SUM(CASE
      WHEN (LOWER(vs.tipo_venta) REGEXP 'pospago|postpago|\\bpos\\b'
         OR LOWER(vs.tipo_sim) REGEXP 'pospago|postpago|\\bpos\\b'
         OR LOWER(IFNULL(vs.comentarios,'')) REGEXP 'pospago|postpago|\\bpos\\b')
      THEN 1 ELSE 0 END) AS sim_pos,
    SUM(CASE
      WHEN (LOWER(vs.tipo_venta) REGEXP 'pospago|postpago|\\bpos\\b'
         OR LOWER(vs.tipo_sim) REGEXP 'pospago|postpago|\\bpos\\b'
         OR LOWER(IFNULL(vs.comentarios,'')) REGEXP 'pospago|postpago|\\bpos\\b')
      THEN 0
      WHEN (LOWER(vs.tipo_venta) LIKE '%regalo%'
         OR LOWER(vs.tipo_sim) LIKE '%regalo%'
         OR LOWER(IFNULL(vs.comentarios,'')) LIKE '%regalo%')
      THEN 0 ELSE 1 END) AS sim_pre
  FROM ventas_sims vs
  INNER JOIN sucursales sc ON sc.id = vs.id_sucursal
  WHERE DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    AND $scopeScWhere
  GROUP BY semana_inicio
";
if ($st = $conn->prepare($sqlSimsSemana)) {
  if ($isSubdisUser) $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  else $st->bind_param('ss', $inicio, $fin);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $wk = (string)$r['semana_inicio'];
    if (!isset($seriesGlobal[$wk])) continue;
    $seriesGlobal[$wk]['sim_pre'] = (int)$r['sim_pre'];
    $seriesGlobal[$wk]['sim_pos'] = (int)$r['sim_pos'];
  }
  $st->close();
}

foreach ($seriesGlobal as $wk => &$sg) {
  $cuotaSemana = 0.0;
  foreach ($sucIds as $idSuc) {
    if ($st = $conn->prepare("SELECT cuota_monto FROM cuotas_sucursales WHERE id_sucursal=? AND fecha_inicio <= ? ORDER BY fecha_inicio DESC LIMIT 1")) {
      $st->bind_param('is', $idSuc, $wk);
      $st->execute();
      $rs = $st->get_result();
      if ($rs && ($r = $rs->fetch_assoc())) $cuotaSemana += (float)($r['cuota_monto'] ?? 0);
      $st->close();
    }
  }
  $sg['cuota'] = $cuotaSemana;
}
unset($sg);

$totalActual = [
  'sucursales' => count($sucIds),
  'unidades' => 0,
  'ventas' => 0.0,
  'cuota' => 0.0,
  'sim_pre' => 0,
  'sim_pos' => 0,
];
foreach ($seriesGlobal as $sg) {
  $totalActual['unidades'] += (int)$sg['unidades'];
  $totalActual['ventas'] += (float)$sg['ventas'];
  $totalActual['cuota'] += (float)$sg['cuota'];
  $totalActual['sim_pre'] += (int)$sg['sim_pre'];
  $totalActual['sim_pos'] += (int)$sg['sim_pos'];
}
$totalActual['cumplimiento'] = $totalActual['cuota'] > 0 ? ($totalActual['ventas'] / $totalActual['cuota']) * 100 : 0;
$totalActual['promedio_ventas'] = $periodo > 0 ? $totalActual['ventas'] / $periodo : 0;
$totalActual['promedio_unidades'] = $periodo > 0 ? $totalActual['unidades'] / $periodo : 0;

$mejorSemana = null;
$peorSemana = null;
foreach ($seriesGlobal as $sg) {
  if ($mejorSemana === null || $sg['ventas'] > $mejorSemana['ventas']) $mejorSemana = $sg;
  if ($peorSemana === null || $sg['ventas'] < $peorSemana['ventas']) $peorSemana = $sg;
}

$totalPrev = ['unidades' => 0, 'ventas' => 0.0];
$sqlPrev = "
  SELECT IFNULL(SUM(va.unidades),0) AS unidades, IFNULL(SUM(va.monto),0) AS ventas
  FROM sucursales s
  LEFT JOIN ($subVentasAgg) va ON va.id_sucursal = s.id
  WHERE s.tipo_sucursal='Tienda'
    AND s.activo=1
    AND $scopeSucursalesWhere
";
if ($st = $conn->prepare($sqlPrev)) {
  if ($isSubdisUser) $st->bind_param('ssi', $inicioPrev, $finPrev, $idSubdis);
  else $st->bind_param('ss', $inicioPrev, $finPrev);
  $st->execute();
  $rs = $st->get_result();
  if ($rs && ($r = $rs->fetch_assoc())) {
    $totalPrev['unidades'] = (int)$r['unidades'];
    $totalPrev['ventas'] = (float)$r['ventas'];
  }
  $st->close();
}
$deltaVentas = $totalActual['ventas'] - $totalPrev['ventas'];
$deltaVentasPct = $totalPrev['ventas'] > 0 ? (($deltaVentas / $totalPrev['ventas']) * 100) : null;
$deltaUnidades = $totalActual['unidades'] - $totalPrev['unidades'];
$deltaUnidadesPct = $totalPrev['unidades'] > 0 ? (($deltaUnidades / $totalPrev['unidades']) * 100) : null;

/* ==============================
   Sucursales
================================= */
$sucursales = [];
$sqlSucursales = "
  SELECT
    s.id AS id_sucursal,
    s.nombre AS sucursal,
    s.zona,
    IFNULL(SUM(va.unidades),0) AS unidades,
    IFNULL(SUM(va.monto),0) AS ventas
  FROM sucursales s
  LEFT JOIN ($subVentasAgg) va ON va.id_sucursal = s.id
  WHERE s.tipo_sucursal='Tienda'
    AND s.activo=1
    AND $scopeSucursalesWhere
  GROUP BY s.id
  ORDER BY ventas DESC, unidades DESC
";
if ($st = $conn->prepare($sqlSucursales)) {
  if ($isSubdisUser) $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  else $st->bind_param('ss', $inicio, $fin);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $idS = (int)$r['id_sucursal'];
    $sucursales[$idS] = [
      'id_sucursal' => $idS,
      'sucursal' => $r['sucursal'],
      'zona' => $r['zona'],
      'unidades' => (int)$r['unidades'],
      'ventas' => (float)$r['ventas'],
      'prom_ventas' => $periodo > 0 ? ((float)$r['ventas'] / $periodo) : 0,
      'prom_unidades' => $periodo > 0 ? ((int)$r['unidades'] / $periodo) : 0,
      'sim_pre' => 0,
      'sim_pos' => 0,
      'cuota' => 0.0,
      'cumplimiento' => 0.0,
      'tendencia_actual' => 0.0,
      'tendencia_previa' => 0.0,
    ];
  }
  $st->close();
}

$sqlSimsSuc = "
  SELECT
    vs.id_sucursal,
    SUM(CASE
      WHEN (LOWER(vs.tipo_venta) REGEXP 'pospago|postpago|\\bpos\\b'
         OR LOWER(vs.tipo_sim) REGEXP 'pospago|postpago|\\bpos\\b'
         OR LOWER(IFNULL(vs.comentarios,'')) REGEXP 'pospago|postpago|\\bpos\\b')
      THEN 1 ELSE 0 END) AS sim_pos,
    SUM(CASE
      WHEN (LOWER(vs.tipo_venta) REGEXP 'pospago|postpago|\\bpos\\b'
         OR LOWER(vs.tipo_sim) REGEXP 'pospago|postpago|\\bpos\\b'
         OR LOWER(IFNULL(vs.comentarios,'')) REGEXP 'pospago|postpago|\\bpos\\b')
      THEN 0
      WHEN (LOWER(vs.tipo_venta) LIKE '%regalo%'
         OR LOWER(vs.tipo_sim) LIKE '%regalo%'
         OR LOWER(IFNULL(vs.comentarios,'')) LIKE '%regalo%')
      THEN 0 ELSE 1 END) AS sim_pre
  FROM ventas_sims vs
  INNER JOIN sucursales sc ON sc.id = vs.id_sucursal
  WHERE DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    AND $scopeScWhere
  GROUP BY vs.id_sucursal
";
if ($st = $conn->prepare($sqlSimsSuc)) {
  if ($isSubdisUser) $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  else $st->bind_param('ss', $inicio, $fin);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $id = (int)$r['id_sucursal'];
    if (isset($sucursales[$id])) {
      $sucursales[$id]['sim_pre'] = (int)$r['sim_pre'];
      $sucursales[$id]['sim_pos'] = (int)$r['sim_pos'];
    }
  }
  $st->close();
}

foreach ($sucursales as $idSuc => &$suc) {
  foreach ($seriesGlobal as $wk => $sg) {
    $cuotaSemanaSuc = 0.0;
    if ($st = $conn->prepare("SELECT cuota_monto FROM cuotas_sucursales WHERE id_sucursal=? AND fecha_inicio <= ? ORDER BY fecha_inicio DESC LIMIT 1")) {
      $st->bind_param('is', $idSuc, $wk);
      $st->execute();
      $rs = $st->get_result();
      if ($rs && ($r = $rs->fetch_assoc())) $cuotaSemanaSuc = (float)($r['cuota_monto'] ?? 0);
      $st->close();
    }
    $suc['cuota'] += $cuotaSemanaSuc;
  }
  $suc['cumplimiento'] = $suc['cuota'] > 0 ? ($suc['ventas'] / $suc['cuota']) * 100 : 0;
}
unset($suc);

$seriesArr = array_values($seriesGlobal);
$mitad = max(1, (int)floor(count($seriesArr) / 2));
$fechaCorteTend = $seriesArr[$mitad - 1]['semana_inicio'] ?? $inicio;
$sqlSucTrend = "
  SELECT
    va.id_sucursal,
    CASE WHEN va.dia <= ? THEN 'previa' ELSE 'actual' END AS bloque,
    IFNULL(SUM(va.monto),0) AS ventas
  FROM ($subVentasAgg) va
  INNER JOIN sucursales s ON s.id = va.id_sucursal
  WHERE s.tipo_sucursal='Tienda'
    AND s.activo=1
    AND $scopeSucursalesWhere
  GROUP BY va.id_sucursal, bloque
";
if ($st = $conn->prepare($sqlSucTrend)) {
  if ($isSubdisUser) $st->bind_param('sssi', $fechaCorteTend, $inicio, $fin, $idSubdis);
  else $st->bind_param('sss', $fechaCorteTend, $inicio, $fin);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $id = (int)$r['id_sucursal'];
    if (!isset($sucursales[$id])) continue;
    if ($r['bloque'] === 'previa') $sucursales[$id]['tendencia_previa'] = (float)$r['ventas'];
    else $sucursales[$id]['tendencia_actual'] = (float)$r['ventas'];
  }
  $st->close();
}

$sucursales = array_values($sucursales);
usort($sucursales, fn($a, $b) => ($b['ventas'] <=> $a['ventas']) ?: ($b['unidades'] <=> $a['unidades']));

/* ==============================
   Ejecutivos
================================= */
$ejecutivos = [];
$sqlEjecutivos = "
  SELECT
    u.id,
    u.nombre,
    u.rol,
    s.nombre AS sucursal,
    s.id AS id_sucursal,
    IFNULL(SUM(va.unidades),0) AS unidades,
    IFNULL(SUM(va.monto),0) AS ventas
  FROM usuarios u
  INNER JOIN sucursales s ON s.id = u.id_sucursal
  LEFT JOIN ($subVentasAgg) va ON va.id_usuario = u.id AND va.id_sucursal = u.id_sucursal
  WHERE u.activo=1
    AND s.tipo_sucursal='Tienda'
    AND s.activo=1
    AND $scopeSucursalesWhere
    AND u.rol IN $rolesUsuariosIn
  GROUP BY u.id
  ORDER BY unidades DESC, ventas DESC
";
if ($st = $conn->prepare($sqlEjecutivos)) {
  if ($isSubdisUser) $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  else $st->bind_param('ss', $inicio, $fin);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $id = (int)$r['id'];
    $ejecutivos[$id] = [
      'id' => $id,
      'nombre' => $r['nombre'],
      'rol' => $r['rol'],
      'sucursal' => $r['sucursal'],
      'id_sucursal' => (int)$r['id_sucursal'],
      'unidades' => (int)$r['unidades'],
      'ventas' => (float)$r['ventas'],
      'prom_unidades' => $periodo > 0 ? ((int)$r['unidades'] / $periodo) : 0,
      'prom_ventas' => $periodo > 0 ? ((float)$r['ventas'] / $periodo) : 0,
      'semanas_cumplidas' => 0,
      'efectividad' => 0.0,
    ];
  }
  $st->close();
}

$sqlEjSem = "
  SELECT
    va.id_usuario,
    va.id_sucursal,
    DATE_SUB(va.dia, INTERVAL ((DAYOFWEEK(va.dia)+4) % 7) DAY) AS semana_inicio,
    SUM(va.unidades) AS unidades
  FROM ($subVentasAgg) va
  INNER JOIN usuarios u ON u.id = va.id_usuario
  INNER JOIN sucursales s ON s.id = va.id_sucursal
  WHERE u.activo=1
    AND s.tipo_sucursal='Tienda'
    AND s.activo=1
    AND $scopeSucursalesWhere
    AND u.rol IN $rolesUsuariosIn
  GROUP BY va.id_usuario, va.id_sucursal, semana_inicio
";
if ($st = $conn->prepare($sqlEjSem)) {
  if ($isSubdisUser) $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  else $st->bind_param('ss', $inicio, $fin);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $idUsuario = (int)$r['id_usuario'];
    $idSucursal = (int)$r['id_sucursal'];
    $wk = $r['semana_inicio'];
    $unidades = (int)$r['unidades'];
    if (!isset($ejecutivos[$idUsuario])) continue;

    $cuotaU = 6;
    if ($q = $conn->prepare("SELECT cuota_unidades FROM cuotas_semanales_sucursal WHERE id_sucursal=? AND semana_inicio <= ? AND semana_fin >= ? ORDER BY semana_inicio DESC LIMIT 1")) {
      $finWkObj = new DateTime($wk, new DateTimeZone('America/Mexico_City'));
      $finWkObj->modify('+6 days');
      $finWk = $finWkObj->format('Y-m-d');
      $q->bind_param('iss', $idSucursal, $wk, $finWk);
      $q->execute();
      $rQ = $q->get_result();
      if ($rQ && ($rowQ = $rQ->fetch_assoc())) $cuotaU = (int)($rowQ['cuota_unidades'] ?? 6);
      $q->close();
    }

    if ($cuotaU > 0 && $unidades >= $cuotaU) $ejecutivos[$idUsuario]['semanas_cumplidas']++;
  }
  $st->close();
}
foreach ($ejecutivos as &$e) $e['efectividad'] = $periodo > 0 ? ($e['semanas_cumplidas'] / $periodo) * 100 : 0;
unset($e);
$ejecutivos = array_values($ejecutivos);
usort($ejecutivos, fn($a, $b) => ($b['unidades'] <=> $a['unidades']) ?: ($b['ventas'] <=> $a['ventas']));

/* ==============================
   Heatmap
================================= */
$heatmapWeeks = [];
foreach ($seriesGlobal as $wk => $sg) {
  $iniW = new DateTime($wk, new DateTimeZone('America/Mexico_City'));
  $finW = clone $iniW;
  $finW->modify('+6 days');
  $heatmapWeeks[$wk] = ['label' => $iniW->format('d/m') . '-' . $finW->format('d/m'), 'short' => $iniW->format('d/m')];
}

$heatmap = [];
foreach ($sucursales as $s) {
  $idS = (int)$s['id_sucursal'];
  $heatmap[$idS] = ['sucursal' => $s['sucursal'], 'zona' => normalizarZona($s['zona'] ?? '') ?? 'Sin zona', 'ventas_total' => (float)$s['ventas'], 'weeks' => []];
  foreach ($heatmapWeeks as $wk => $infoW) {
    $heatmap[$idS]['weeks'][$wk] = ['ventas' => 0.0, 'unidades' => 0, 'cuota' => 0.0, 'cumplimiento' => 0.0];
  }
}

$sqlHeatmapVentas = "
  SELECT
    va.id_sucursal,
    DATE_SUB(va.dia, INTERVAL ((DAYOFWEEK(va.dia)+4) % 7) DAY) AS semana_inicio,
    IFNULL(SUM(va.unidades),0) AS unidades,
    IFNULL(SUM(va.monto),0) AS ventas
  FROM ($subVentasAgg) va
  INNER JOIN sucursales s ON s.id = va.id_sucursal
  WHERE s.tipo_sucursal='Tienda'
    AND s.activo=1
    AND $scopeSucursalesWhere
  GROUP BY va.id_sucursal, semana_inicio
";
if ($st = $conn->prepare($sqlHeatmapVentas)) {
  if ($isSubdisUser) $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  else $st->bind_param('ss', $inicio, $fin);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $idS = (int)$r['id_sucursal'];
    $wk = (string)$r['semana_inicio'];
    if (!isset($heatmap[$idS]['weeks'][$wk])) continue;
    $heatmap[$idS]['weeks'][$wk]['ventas'] = (float)$r['ventas'];
    $heatmap[$idS]['weeks'][$wk]['unidades'] = (int)$r['unidades'];
  }
  $st->close();
}

foreach ($heatmap as $idS => &$hm) {
  foreach ($heatmapWeeks as $wk => $infoW) {
    $cuotaSemanaSuc = 0.0;
    if ($st = $conn->prepare("SELECT cuota_monto FROM cuotas_sucursales WHERE id_sucursal=? AND fecha_inicio <= ? ORDER BY fecha_inicio DESC LIMIT 1")) {
      $st->bind_param('is', $idS, $wk);
      $st->execute();
      $rs = $st->get_result();
      if ($rs && ($r = $rs->fetch_assoc())) $cuotaSemanaSuc = (float)($r['cuota_monto'] ?? 0);
      $st->close();
    }
    $ventasW = (float)$hm['weeks'][$wk]['ventas'];
    $hm['weeks'][$wk]['cuota'] = $cuotaSemanaSuc;
    $hm['weeks'][$wk]['cumplimiento'] = $cuotaSemanaSuc > 0 ? ($ventasW / $cuotaSemanaSuc) * 100 : 0;
  }
}
unset($hm);
uasort($heatmap, fn($a, $b) => ($b['ventas_total'] <=> $a['ventas_total']));

/* ==============================
   Construir HTML PDF
================================= */
$topSucursales = array_slice($sucursales, 0, 12);
$topEjecutivos = array_slice($ejecutivos, 0, 12);
$heatmapLimit = array_slice($heatmap, 0, 18, true);
$heatmapWeekLimit = $periodo > 26 ? array_slice($heatmapWeeks, -26, 26, true) : $heatmapWeeks;

$mejorTxt = 'Sin datos';
if ($mejorSemana) {
  $mwIni = new DateTime($mejorSemana['semana_inicio']);
  $mwFin = clone $mwIni;
  $mwFin->modify('+6 days');
  $mejorTxt = $mwIni->format('d/m/Y') . ' al ' . $mwFin->format('d/m/Y') . ' - ' . moneyPdf($mejorSemana['ventas']);
}

$peorTxt = 'Sin datos';
if ($peorSemana) {
  $pwIni = new DateTime($peorSemana['semana_inicio']);
  $pwFin = clone $pwIni;
  $pwFin->modify('+6 days');
  $peorTxt = $pwIni->format('d/m/Y') . ' al ' . $pwFin->format('d/m/Y') . ' - ' . moneyPdf($peorSemana['ventas']);
}

$html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
  @page { margin: 28px 28px 42px 28px; }
  body { font-family: DejaVu Sans, Arial, sans-serif; color:#0f172a; font-size:10px; }
  .cover { background: linear-gradient(135deg,#0f172a,#1d4ed8); color:#fff; padding:28px; border-radius:18px; margin-bottom:18px; }
  .brand { font-size:12px; letter-spacing:2px; text-transform:uppercase; color:#bfdbfe; font-weight:bold; }
  h1 { font-size:30px; margin:8px 0 4px 0; line-height:1; }
  .subtitle { color:#dbeafe; font-size:12px; }
  .range { margin-top:16px; background:rgba(255,255,255,.12); padding:12px; border-radius:12px; }
  .grid { width:100%; border-collapse:collapse; margin-bottom:12px; }
  .grid td { width:25%; padding:8px; vertical-align:top; }
  .kpi { border:1px solid #e2e8f0; border-radius:12px; padding:10px; background:#ffffff; }
  .kpi .label { color:#64748b; font-size:8px; text-transform:uppercase; letter-spacing:1px; font-weight:bold; }
  .kpi .value { font-size:17px; font-weight:bold; margin-top:5px; }
  .kpi .sub { color:#64748b; font-size:8px; margin-top:4px; }
  h2 { font-size:15px; color:#0f172a; margin:18px 0 8px; border-bottom:2px solid #2563eb; padding-bottom:4px; }
  table { width:100%; border-collapse:collapse; margin-bottom:10px; }
  th { background:#0f172a; color:#fff; padding:6px; font-size:8px; text-align:left; }
  td { border:1px solid #e2e8f0; padding:5px; font-size:8px; }
  .num { text-align:right; white-space:nowrap; }
  .muted { color:#64748b; }
  .badge { display:inline-block; padding:3px 6px; border-radius:9px; font-weight:bold; font-size:7px; }
  .good { background:#dcfce7; color:#166534; }
  .mid { background:#fef3c7; color:#92400e; }
  .low { background:#ffe4e6; color:#9f1239; }
  .none { background:#e2e8f0; color:#475569; }
  .heat td, .heat th { text-align:center; padding:3px; font-size:6.5px; }
  .heat .branch { text-align:left; width:90px; }
  .heat-good { background:#dcfce7; color:#166534; font-weight:bold; }
  .heat-mid { background:#fef3c7; color:#92400e; font-weight:bold; }
  .heat-low { background:#ffe4e6; color:#9f1239; font-weight:bold; }
  .heat-none { background:#e2e8f0; color:#475569; }
  .footer { position:fixed; bottom:-24px; left:0; right:0; text-align:center; color:#64748b; font-size:8px; }
  .page-break { page-break-before: always; }
</style></head><body>';

$html .= '<div class="footer">Zentral - Dashboard Histórico Ejecutivo - Generado ' . h(date('d/m/Y H:i')) . '</div>';

$html .= '<section class="cover">
  <div class="brand">Reporte Ejecutivo</div>
  <h1>Dashboard Histórico Zentral</h1>
  <div class="subtitle">' . h($empresaZentral) . ' - Análisis comercial acumulado</div>
  <div class="range">
    <strong>Periodo:</strong> ' . (int)$periodo . ' semanas<br>
    <strong>Rango analizado:</strong> ' . $inicioObj->format('d/m/Y') . ' al ' . $finObj->format('d/m/Y') . '<br>
    <strong>Comparativo anterior:</strong> ' . $inicioPrevObj->format('d/m/Y') . ' al ' . $finPrevObj->format('d/m/Y') . '
  </div>
</section>';

$html .= '<table class="grid"><tr>
  <td><div class="kpi"><div class="label">Ventas acumuladas</div><div class="value">' . moneyPdf($totalActual['ventas']) . '</div><div class="sub">Var: ' . moneyPdf($deltaVentas) . ($deltaVentasPct !== null ? ' (' . pctPdf($deltaVentasPct) . ')' : '') . '</div></div></td>
  <td><div class="kpi"><div class="label">Unidades</div><div class="value">' . number_format((int)$totalActual['unidades']) . '</div><div class="sub">Var: ' . (($deltaUnidades > 0 ? '+' : '') . number_format((int)$deltaUnidades)) . ($deltaUnidadesPct !== null ? ' (' . pctPdf($deltaUnidadesPct) . ')' : '') . '</div></div></td>
  <td><div class="kpi"><div class="label">Cumplimiento</div><div class="value">' . pctPdf($totalActual['cumplimiento']) . '</div><div class="sub">Cuota: ' . moneyPdf($totalActual['cuota']) . '</div></div></td>
  <td><div class="kpi"><div class="label">Promedio semanal</div><div class="value">' . moneyPdf($totalActual['promedio_ventas']) . '</div><div class="sub">' . number_format((float)$totalActual['promedio_unidades'], 1) . ' unidades</div></div></td>
</tr></table>';

$html .= '<table class="grid"><tr>
  <td><div class="kpi"><div class="label">Mejor semana</div><div class="sub">' . h($mejorTxt) . '</div></div></td>
  <td><div class="kpi"><div class="label">Semana más baja</div><div class="sub">' . h($peorTxt) . '</div></div></td>
  <td><div class="kpi"><div class="label">SIM Prepago</div><div class="value">' . number_format((int)$totalActual['sim_pre']) . '</div></div></td>
  <td><div class="kpi"><div class="label">SIM Pospago</div><div class="value">' . number_format((int)$totalActual['sim_pos']) . '</div></div></td>
</tr></table>';

$html .= '<h2>Serie semanal</h2><table><thead><tr><th>Semana</th><th class="num">Ventas</th><th class="num">Unidades</th><th class="num">Cuota</th><th class="num">Cumpl.</th><th class="num">SIM Pre</th><th class="num">SIM Pos</th></tr></thead><tbody>';
foreach ($seriesGlobal as $wk => $sg) {
  $iniW = new DateTime($wk);
  $finW = clone $iniW;
  $finW->modify('+6 days');
  $cumpl = $sg['cuota'] > 0 ? ($sg['ventas'] / $sg['cuota']) * 100 : 0;
  $html .= '<tr><td>' . $iniW->format('d/m') . ' - ' . $finW->format('d/m') . '</td><td class="num">' . moneyPdf($sg['ventas']) . '</td><td class="num">' . number_format((int)$sg['unidades']) . '</td><td class="num">' . moneyPdf($sg['cuota']) . '</td><td class="num">' . pctPdf($cumpl) . '</td><td class="num">' . number_format((int)$sg['sim_pre']) . '</td><td class="num">' . number_format((int)$sg['sim_pos']) . '</td></tr>';
}
$html .= '</tbody></table>';

$html .= '<div class="page-break"></div><h2>Top sucursales</h2><table><thead><tr><th>Sucursal</th><th>Zona</th><th class="num">Ventas</th><th class="num">Unidades</th><th class="num">Cuota</th><th class="num">Cumpl.</th><th>Tendencia</th></tr></thead><tbody>';
foreach ($topSucursales as $s) {
  $html .= '<tr><td>' . h($s['sucursal']) . '</td><td>' . h(normalizarZona($s['zona']) ?? '-') . '</td><td class="num">' . moneyPdf($s['ventas']) . '</td><td class="num">' . number_format((int)$s['unidades']) . '</td><td class="num">' . moneyPdf($s['cuota']) . '</td><td class="num">' . pctPdf($s['cumplimiento']) . '</td><td>' . h(tendenciaTexto((float)$s['tendencia_actual'], (float)$s['tendencia_previa'])) . '</td></tr>';
}
$html .= '</tbody></table>';

$html .= '<h2>Top ejecutivos</h2><table><thead><tr><th>Ejecutivo</th><th>Sucursal</th><th>Rol</th><th class="num">Ventas</th><th class="num">Unidades</th><th class="num">Sem. cumplidas</th><th class="num">Efectividad</th></tr></thead><tbody>';
foreach ($topEjecutivos as $e) {
  $html .= '<tr><td>' . h($e['nombre']) . '</td><td>' . h($e['sucursal']) . '</td><td>' . h($e['rol']) . '</td><td class="num">' . moneyPdf($e['ventas']) . '</td><td class="num">' . number_format((int)$e['unidades']) . '</td><td class="num">' . (int)$e['semanas_cumplidas'] . ' / ' . (int)$periodo . '</td><td class="num">' . pctPdf($e['efectividad']) . '</td></tr>';
}
$html .= '</tbody></table>';

$html .= '<div class="page-break"></div><h2>Heatmap de cumplimiento</h2><p class="muted">Se muestran hasta 18 sucursales y máximo 26 semanas en PDF para mantener legibilidad. El Excel conserva el detalle completo.</p>';
$html .= '<table class="heat"><thead><tr><th class="branch">Sucursal</th>';
foreach ($heatmapWeekLimit as $wk => $infoW) $html .= '<th>' . h($infoW['short']) . '</th>';
$html .= '</tr></thead><tbody>';
foreach ($heatmapLimit as $hm) {
  $html .= '<tr><td class="branch">' . h(sucursalCorta($hm['sucursal'])) . '</td>';
  foreach ($heatmapWeekLimit as $wk => $infoW) {
    $cell = $hm['weeks'][$wk];
    $cls = heatClassPdf((float)$cell['cumplimiento'], (float)$cell['cuota'], (float)$cell['ventas']);
    $txt = $cell['cuota'] <= 0 && $cell['ventas'] <= 0 ? '-' : number_format((float)$cell['cumplimiento'], 0);
    $html .= '<td class="' . $cls . '">' . h($txt) . '</td>';
  }
  $html .= '</tr>';
}
$html .= '</tbody></table>';

$html .= '<p><span class="badge good">100%+</span> <span class="badge mid">60%-99%</span> <span class="badge low">Menor a 60%</span> <span class="badge none">Sin cuota / sin actividad</span></p>';

$html .= '</body></html>';

/* ==============================
   Render PDF
================================= */
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('chroot', __DIR__);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('letter', 'landscape');
$dompdf->render();

$empresaFile = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $empresaZentral));
$empresaFile = trim($empresaFile, '_');
if ($empresaFile === '') $empresaFile = 'zentral';

$filename = 'dashboard_historico_' . $empresaFile . '_' . $periodo . 'semanas_' . $inicio . '_al_' . $fin . '.pdf';

while (ob_get_level()) {
  ob_end_clean();
}

$dompdf->stream($filename, ['Attachment' => true]);
exit;
