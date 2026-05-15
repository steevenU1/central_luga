<?php
// exportar_dashboard_historico_excel.php — Export Ejecutivo Dashboard Histórico Zentral
// Genera XLSX con: Resumen, Serie semanal, Sucursales, Ejecutivos y Heatmap

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

/* =========================================================
   CARGAR PHPSPREADSHEET
   Requiere:
   composer require phpoffice/phpspreadsheet
========================================================= */
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
  http_response_code(500);
  die('No se encontró vendor/autoload.php. Instala PhpSpreadsheet con: composer require phpoffice/phpspreadsheet');
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/* ==============================
   Helpers
================================= */
function h($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function normalizarZona($raw) {
  $t = trim((string)$raw);
  if ($t === '') return null;
  $t = preg_replace('/\s+/', ' ', $t);

  if (preg_match('/^Zona\s+/i', $t)) {
    return ucwords(strtolower($t));
  }

  if (!preg_match('/\d+/', $t)) {
    return 'Zona ' . ucwords(strtolower($t));
  }

  if (preg_match('/(\d+)/', $t, $m)) {
    return 'Zona ' . (int)$m[1];
  }

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

  if ($offset > 0) {
    $inicio->modify('-' . (7 * $offset) . ' days');
  }

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

function tendenciaLabelExcel($actual, $previo) {
  if ($previo <= 0 && $actual > 0) return 'Nueva actividad';
  if ($previo <= 0 && $actual <= 0) return 'Sin movimiento';

  $delta = (($actual - $previo) / $previo) * 100;

  if ($delta >= 12) return 'Creciendo';
  if ($delta <= -12) return 'Bajando';
  return 'Estable';
}

function safeSheetTitle($title) {
  $title = preg_replace('/[\\\/\?\*\[\]\:]/', '', (string)$title);
  $title = trim($title);
  return mb_substr($title !== '' ? $title : 'Hoja', 0, 31);
}

function styleHeader($sheet, $range) {
  $sheet->getStyle($range)->applyFromArray([
    'font' => [
      'bold' => true,
      'color' => ['rgb' => 'FFFFFF'],
    ],
    'fill' => [
      'fillType' => Fill::FILL_SOLID,
      'startColor' => ['rgb' => '0F172A'],
    ],
    'alignment' => [
      'horizontal' => Alignment::HORIZONTAL_CENTER,
      'vertical' => Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
      'allBorders' => [
        'borderStyle' => Border::BORDER_THIN,
        'color' => ['rgb' => 'CBD5E1'],
      ],
    ],
  ]);
}

function styleTable($sheet, $highestRow, $highestColumn) {
  $range = 'A1:' . $highestColumn . $highestRow;
  $sheet->getStyle($range)->applyFromArray([
    'borders' => [
      'allBorders' => [
        'borderStyle' => Border::BORDER_THIN,
        'color' => ['rgb' => 'E2E8F0'],
      ],
    ],
    'alignment' => [
      'vertical' => Alignment::VERTICAL_CENTER,
    ],
  ]);

  $sheet->freezePane('A2');
  $sheet->setAutoFilter('A1:' . $highestColumn . '1');
}

function autosizeSheet($sheet) {
  $highestColumn = $sheet->getHighestColumn();
  $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
  for ($i = 1; $i <= $highestColumnIndex; $i++) {
    $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
  }
}

/* ==============================
   Scope Luga / Subdis
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
    if ($rs && ($row = $rs->fetch_assoc())) {
      $idSubdis = (int)($row['id_subdis'] ?? 0);
    }
    $st->close();
  }
}

$subdisNombre = '';
if ($isSubdisUser && $idSubdis > 0) {
  if ($st = $conn->prepare("SELECT nombre_comercial FROM subdistribuidores WHERE id=? LIMIT 1")) {
    $st->bind_param("i", $idSubdis);
    $st->execute();
    $rs = $st->get_result();
    if ($rs && ($r = $rs->fetch_assoc())) {
      $subdisNombre = trim((string)($r['nombre_comercial'] ?? ''));
    }
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
   Detectar columna tipo producto
================================= */
$colTipoProd = 'tipo_producto';
try {
  if (hasColumn($conn, 'productos', 'tipo')) {
    $colTipoProd = 'tipo';
  } elseif (hasColumn($conn, 'productos', 'tipo_producto')) {
    $colTipoProd = 'tipo_producto';
  }
} catch (Exception $e) {}

/* ==============================
   Subquery ventas agregadas
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
  while ($rs && ($r = $rs->fetch_assoc())) {
    $sucIds[] = (int)$r['id'];
  }
  $st->close();
}

/* ==============================
   Serie semanal global
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
    SELECT
      x.*,
      DATE_SUB(x.dia, INTERVAL ((DAYOFWEEK(x.dia)+4) % 7) DAY) AS semana_inicio
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
      THEN 0
      ELSE 1 END) AS sim_pre
  FROM ventas_sims vs
  INNER JOIN sucursales sc ON sc.id = vs.id_sucursal
  WHERE DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    AND $scopeScWhere
  GROUP BY semana_inicio
  ORDER BY semana_inicio ASC
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
      if ($rs && ($r = $rs->fetch_assoc())) {
        $cuotaSemana += (float)($r['cuota_monto'] ?? 0);
      }
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
  SELECT
    IFNULL(SUM(va.unidades),0) AS unidades,
    IFNULL(SUM(va.monto),0) AS ventas
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
      THEN 0
      ELSE 1 END) AS sim_pre
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
      'sim_pre' => 0,
      'sim_pos' => 0,
      'semanas_cumplidas' => 0,
      'efectividad' => 0.0,
    ];
  }
  $st->close();
}

$sqlSimsUser = "
  SELECT
    vs.id_usuario,
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
      THEN 0
      ELSE 1 END) AS sim_pre
  FROM ventas_sims vs
  INNER JOIN sucursales sc ON sc.id = vs.id_sucursal
  WHERE DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    AND $scopeScWhere
  GROUP BY vs.id_usuario
";
if ($st = $conn->prepare($sqlSimsUser)) {
  if ($isSubdisUser) $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  else $st->bind_param('ss', $inicio, $fin);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $id = (int)$r['id_usuario'];
    if (isset($ejecutivos[$id])) {
      $ejecutivos[$id]['sim_pre'] = (int)$r['sim_pre'];
      $ejecutivos[$id]['sim_pos'] = (int)$r['sim_pos'];
    }
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
      if ($rQ && ($rowQ = $rQ->fetch_assoc())) {
        $cuotaU = (int)($rowQ['cuota_unidades'] ?? 6);
      }
      $q->close();
    }

    if ($cuotaU > 0 && $unidades >= $cuotaU) {
      $ejecutivos[$idUsuario]['semanas_cumplidas']++;
    }
  }
  $st->close();
}

foreach ($ejecutivos as &$e) {
  $e['efectividad'] = $periodo > 0 ? ($e['semanas_cumplidas'] / $periodo) * 100 : 0;
}
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
  $heatmapWeeks[$wk] = [
    'inicio' => $wk,
    'label' => $iniW->format('d/m/Y') . ' al ' . $finW->format('d/m/Y'),
    'short' => $iniW->format('d/m'),
  ];
}

$heatmap = [];
foreach ($sucursales as $s) {
  $idS = (int)$s['id_sucursal'];
  $heatmap[$idS] = [
    'sucursal' => $s['sucursal'],
    'zona' => normalizarZona($s['zona'] ?? '') ?? 'Sin zona',
    'ventas_total' => (float)$s['ventas'],
    'weeks' => [],
  ];
  foreach ($heatmapWeeks as $wk => $infoW) {
    $heatmap[$idS]['weeks'][$wk] = [
      'ventas' => 0.0,
      'unidades' => 0,
      'cuota' => 0.0,
      'cumplimiento' => 0.0,
      'estado' => 'Sin cuota / sin actividad',
    ];
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
    $cumplW = $cuotaSemanaSuc > 0 ? ($ventasW / $cuotaSemanaSuc) * 100 : 0;

    $hm['weeks'][$wk]['cuota'] = $cuotaSemanaSuc;
    $hm['weeks'][$wk]['cumplimiento'] = $cumplW;

    if ($cuotaSemanaSuc <= 0 && $ventasW <= 0) $hm['weeks'][$wk]['estado'] = 'Sin cuota / sin actividad';
    elseif ($cumplW >= 100) $hm['weeks'][$wk]['estado'] = 'Cumplido';
    elseif ($cumplW >= 60) $hm['weeks'][$wk]['estado'] = 'Medio';
    else $hm['weeks'][$wk]['estado'] = 'Bajo';
  }
}
unset($hm);

uasort($heatmap, fn($a, $b) => ($b['ventas_total'] <=> $a['ventas_total']));

/* ==============================
   Subdis agregado, solo Luga
================================= */
$subdisAgg = [];
if (!$isSubdisUser) {
  $sqlSubdis = "
    SELECT
      s.id_subdis,
      COALESCE(sd.nombre_comercial, CONCAT('Subdis ', s.id_subdis)) AS subdis,
      COUNT(DISTINCT s.id) AS sucursales,
      IFNULL(SUM(va.unidades),0) AS unidades,
      IFNULL(SUM(va.monto),0) AS ventas
    FROM sucursales s
    LEFT JOIN subdistribuidores sd ON sd.id = s.id_subdis
    LEFT JOIN ($subVentasAgg) va ON va.id_sucursal = s.id
    WHERE s.tipo_sucursal='Tienda'
      AND s.activo=1
      AND (TRIM(LOWER(IFNULL(s.propiedad,'')))='subdistribuidor' OR (s.id_subdis IS NOT NULL AND s.id_subdis>0))
    GROUP BY s.id_subdis
    ORDER BY ventas DESC
  ";
  if ($st = $conn->prepare($sqlSubdis)) {
    $st->bind_param('ss', $inicio, $fin);
    $st->execute();
    $rs = $st->get_result();
    while ($rs && ($r = $rs->fetch_assoc())) {
      $r['unidades'] = (int)$r['unidades'];
      $r['ventas'] = (float)$r['ventas'];
      $r['prom_ventas'] = $periodo > 0 ? $r['ventas'] / $periodo : 0;
      $subdisAgg[] = $r;
    }
    $st->close();
  }
}

/* =========================================================
   CREAR EXCEL
========================================================= */
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
  ->setCreator('Zentral')
  ->setLastModifiedBy('Zentral')
  ->setTitle('Dashboard Histórico Zentral')
  ->setSubject('Reporte ejecutivo histórico')
  ->setDescription('Exportación ejecutiva del Dashboard Histórico Zentral');

/* ================= Hoja 1: Resumen ================= */
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Resumen');

$sheet->setCellValue('A1', 'Dashboard Histórico Zentral');
$sheet->mergeCells('A1:D1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('0F172A');
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->fromArray([
  ['Empresa', $empresaZentral],
  ['Periodo', $periodo . ' semanas'],
  ['Rango analizado', $inicioObj->format('d/m/Y') . ' al ' . $finObj->format('d/m/Y')],
  ['Comparativo anterior', $inicioPrevObj->format('d/m/Y') . ' al ' . $finPrevObj->format('d/m/Y')],
  ['Generado', date('d/m/Y H:i:s')],
], null, 'A3');

$sheet->fromArray([
  ['Indicador', 'Valor'],
  ['Ventas acumuladas', $totalActual['ventas']],
  ['Unidades acumuladas', $totalActual['unidades']],
  ['Cuota acumulada', $totalActual['cuota']],
  ['Cumplimiento global %', $totalActual['cumplimiento']],
  ['Promedio semanal ventas', $totalActual['promedio_ventas']],
  ['Promedio semanal unidades', $totalActual['promedio_unidades']],
  ['SIM Prepago', $totalActual['sim_pre']],
  ['SIM Pospago', $totalActual['sim_pos']],
  ['Variación ventas vs periodo anterior', $deltaVentas],
  ['Variación ventas %', $deltaVentasPct],
  ['Variación unidades vs periodo anterior', $deltaUnidades],
  ['Variación unidades %', $deltaUnidadesPct],
], null, 'A10');

if ($mejorSemana) {
  $mwIni = new DateTime($mejorSemana['semana_inicio']);
  $mwFin = clone $mwIni;
  $mwFin->modify('+6 days');
  $sheet->setCellValue('D10', 'Mejor semana');
  $sheet->setCellValue('E10', $mwIni->format('d/m/Y') . ' al ' . $mwFin->format('d/m/Y'));
  $sheet->setCellValue('D11', 'Ventas mejor semana');
  $sheet->setCellValue('E11', $mejorSemana['ventas']);
  $sheet->setCellValue('D12', 'Unidades mejor semana');
  $sheet->setCellValue('E12', $mejorSemana['unidades']);
}

if ($peorSemana) {
  $pwIni = new DateTime($peorSemana['semana_inicio']);
  $pwFin = clone $pwIni;
  $pwFin->modify('+6 days');
  $sheet->setCellValue('D14', 'Semana más baja');
  $sheet->setCellValue('E14', $pwIni->format('d/m/Y') . ' al ' . $pwFin->format('d/m/Y'));
  $sheet->setCellValue('D15', 'Ventas semana más baja');
  $sheet->setCellValue('E15', $peorSemana['ventas']);
  $sheet->setCellValue('D16', 'Unidades semana más baja');
  $sheet->setCellValue('E16', $peorSemana['unidades']);
}

styleHeader($sheet, 'A10:B10');
$sheet->getStyle('A10:B22')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
$sheet->getStyle('B11:B22')->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('E11:E16')->getNumberFormat()->setFormatCode('#,##0.00');

/* ================= Hoja 2: Serie semanal ================= */
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('Serie semanal');
$headers = ['Semana inicio', 'Semana fin', 'Ventas', 'Unidades', 'Cuota', 'Cumplimiento %', 'SIM Prepago', 'SIM Pospago'];
$sheet2->fromArray($headers, null, 'A1');
$r = 2;
foreach ($seriesGlobal as $wk => $sg) {
  $iniW = new DateTime($wk);
  $finW = clone $iniW;
  $finW->modify('+6 days');
  $cumpl = $sg['cuota'] > 0 ? ($sg['ventas'] / $sg['cuota']) * 100 : 0;
  $sheet2->fromArray([
    $iniW->format('Y-m-d'),
    $finW->format('Y-m-d'),
    $sg['ventas'],
    $sg['unidades'],
    $sg['cuota'],
    $cumpl,
    $sg['sim_pre'],
    $sg['sim_pos'],
  ], null, 'A' . $r);
  $r++;
}
styleHeader($sheet2, 'A1:H1');
styleTable($sheet2, max(1, $r - 1), 'H');
$sheet2->getStyle('C2:C' . ($r - 1))->getNumberFormat()->setFormatCode('$#,##0.00');
$sheet2->getStyle('E2:E' . ($r - 1))->getNumberFormat()->setFormatCode('$#,##0.00');
$sheet2->getStyle('F2:F' . ($r - 1))->getNumberFormat()->setFormatCode('0.0');

/* ================= Hoja 3: Sucursales ================= */
$sheet3 = $spreadsheet->createSheet();
$sheet3->setTitle('Sucursales');
$headers = ['Sucursal', 'Zona', 'Ventas', 'Unidades', 'Promedio ventas semanal', 'Promedio unidades semanal', 'Cuota', 'Cumplimiento %', 'SIM Prepago', 'SIM Pospago', 'Tendencia'];
$sheet3->fromArray($headers, null, 'A1');
$r = 2;
foreach ($sucursales as $s) {
  $sheet3->fromArray([
    $s['sucursal'],
    normalizarZona($s['zona']) ?? '',
    $s['ventas'],
    $s['unidades'],
    $s['prom_ventas'],
    $s['prom_unidades'],
    $s['cuota'],
    $s['cumplimiento'],
    $s['sim_pre'],
    $s['sim_pos'],
    tendenciaLabelExcel((float)$s['tendencia_actual'], (float)$s['tendencia_previa']),
  ], null, 'A' . $r);
  $r++;
}
styleHeader($sheet3, 'A1:K1');
styleTable($sheet3, max(1, $r - 1), 'K');
$sheet3->getStyle('C2:C' . ($r - 1))->getNumberFormat()->setFormatCode('$#,##0.00');
$sheet3->getStyle('E2:E' . ($r - 1))->getNumberFormat()->setFormatCode('$#,##0.00');
$sheet3->getStyle('G2:G' . ($r - 1))->getNumberFormat()->setFormatCode('$#,##0.00');
$sheet3->getStyle('F2:F' . ($r - 1))->getNumberFormat()->setFormatCode('0.0');
$sheet3->getStyle('H2:H' . ($r - 1))->getNumberFormat()->setFormatCode('0.0');

/* ================= Hoja 4: Ejecutivos ================= */
$sheet4 = $spreadsheet->createSheet();
$sheet4->setTitle('Ejecutivos');
$headers = ['Ejecutivo', 'Sucursal', 'Rol', 'Ventas', 'Unidades', 'Promedio ventas semanal', 'Promedio unidades semanal', 'Semanas cumplidas', 'Periodo semanas', 'Efectividad %', 'SIM Prepago', 'SIM Pospago'];
$sheet4->fromArray($headers, null, 'A1');
$r = 2;
foreach ($ejecutivos as $e) {
  $sheet4->fromArray([
    $e['nombre'],
    $e['sucursal'],
    $e['rol'],
    $e['ventas'],
    $e['unidades'],
    $e['prom_ventas'],
    $e['prom_unidades'],
    $e['semanas_cumplidas'],
    $periodo,
    $e['efectividad'],
    $e['sim_pre'],
    $e['sim_pos'],
  ], null, 'A' . $r);
  $r++;
}
styleHeader($sheet4, 'A1:L1');
styleTable($sheet4, max(1, $r - 1), 'L');
$sheet4->getStyle('D2:D' . ($r - 1))->getNumberFormat()->setFormatCode('$#,##0.00');
$sheet4->getStyle('F2:F' . ($r - 1))->getNumberFormat()->setFormatCode('$#,##0.00');
$sheet4->getStyle('G2:G' . ($r - 1))->getNumberFormat()->setFormatCode('0.0');
$sheet4->getStyle('J2:J' . ($r - 1))->getNumberFormat()->setFormatCode('0.0');

/* ================= Hoja 5: Heatmap ================= */
$sheet5 = $spreadsheet->createSheet();
$sheet5->setTitle('Heatmap');
$sheet5->setCellValue('A1', 'Sucursal');
$sheet5->setCellValue('B1', 'Zona');

$col = 3;
foreach ($heatmapWeeks as $wk => $infoW) {
  $sheet5->setCellValueByColumnAndRow($col, 1, $infoW['short']);
  $col++;
}

$r = 2;
foreach ($heatmap as $hm) {
  $sheet5->setCellValueByColumnAndRow(1, $r, $hm['sucursal']);
  $sheet5->setCellValueByColumnAndRow(2, $r, $hm['zona']);
  $col = 3;
  foreach ($heatmapWeeks as $wk => $infoW) {
    $cell = $hm['weeks'][$wk];
    $sheet5->setCellValueByColumnAndRow($col, $r, round((float)$cell['cumplimiento'], 1));

    $coord = Coordinate::stringFromColumnIndex($col) . $r;
    $fill = 'E2E8F0';
    $font = '475569';
    if ($cell['estado'] === 'Cumplido') {
      $fill = 'DCFCE7';
      $font = '166534';
    } elseif ($cell['estado'] === 'Medio') {
      $fill = 'FEF3C7';
      $font = '92400E';
    } elseif ($cell['estado'] === 'Bajo') {
      $fill = 'FFE4E6';
      $font = '9F1239';
    }

    $sheet5->getStyle($coord)->applyFromArray([
      'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => $fill],
      ],
      'font' => [
        'bold' => true,
        'color' => ['rgb' => $font],
      ],
      'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
      ],
    ]);

    $sheet5->getComment($coord)->getText()->createTextRun(
      $infoW['label'] . "\n" .
      'Ventas: $' . number_format((float)$cell['ventas'], 2) . "\n" .
      'Unidades: ' . number_format((int)$cell['unidades']) . "\n" .
      'Cuota: $' . number_format((float)$cell['cuota'], 2) . "\n" .
      'Cumplimiento: ' . number_format((float)$cell['cumplimiento'], 1) . "%\n" .
      'Estado: ' . $cell['estado']
    );

    $col++;
  }
  $r++;
}

$lastHeatCol = Coordinate::stringFromColumnIndex(2 + count($heatmapWeeks));
styleHeader($sheet5, 'A1:' . $lastHeatCol . '1');
styleTable($sheet5, max(1, $r - 1), $lastHeatCol);
$sheet5->freezePane('C2');
$sheet5->getStyle('C2:' . $lastHeatCol . max(2, $r - 1))->getNumberFormat()->setFormatCode('0.0');

/* ================= Hoja 6: Subdis, si aplica ================= */
if (!$isSubdisUser) {
  $sheet6 = $spreadsheet->createSheet();
  $sheet6->setTitle('Subdis');
  $headers = ['Subdis', 'Sucursales', 'Unidades', 'Ventas', 'Promedio ventas semanal'];
  $sheet6->fromArray($headers, null, 'A1');
  $r = 2;
  foreach ($subdisAgg as $sd) {
    $sheet6->fromArray([
      $sd['subdis'],
      $sd['sucursales'],
      $sd['unidades'],
      $sd['ventas'],
      $sd['prom_ventas'],
    ], null, 'A' . $r);
    $r++;
  }
  styleHeader($sheet6, 'A1:E1');
  styleTable($sheet6, max(1, $r - 1), 'E');
  $sheet6->getStyle('D2:E' . ($r - 1))->getNumberFormat()->setFormatCode('$#,##0.00');
}

/* ================= Ajustes finales ================= */
foreach ($spreadsheet->getAllSheets() as $ws) {
  autosizeSheet($ws);
  $ws->getDefaultRowDimension()->setRowHeight(20);
}

$spreadsheet->setActiveSheetIndex(0);

$empresaFile = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $empresaZentral));
$empresaFile = trim($empresaFile, '_');
if ($empresaFile === '') $empresaFile = 'zentral';

$filename = 'dashboard_historico_' . $empresaFile . '_' . $periodo . 'semanas_' . $inicio . '_al_' . $fin . '.xlsx';

while (ob_get_level()) {
  ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
