<?php
// dashboard_historico.php — Dashboard Histórico Zentral
// Análisis acumulado por ventanas operativas de 4, 8, 13, 26 y 52 semanas

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

/* ==============================
   Helpers base
================================= */
function h($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function money($n) {
  return '$' . number_format((float)$n, 2);
}

function pct($n) {
  return number_format((float)$n, 1) . '%';
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

function arrowIcon($delta) {
  if ($delta > 0) return ['▲', 'text-success'];
  if ($delta < 0) return ['▼', 'text-danger'];
  return ['▬', 'text-secondary'];
}

function tendenciaLabel($actual, $previo) {
  if ($previo <= 0 && $actual > 0) return ['Nueva actividad', 'badge text-bg-info', 'bi-stars'];
  if ($previo <= 0 && $actual <= 0) return ['Sin movimiento', 'badge text-bg-secondary', 'bi-dash-circle'];

  $delta = (($actual - $previo) / $previo) * 100;

  if ($delta >= 12) return ['Creciendo', 'badge text-bg-success', 'bi-graph-up-arrow'];
  if ($delta <= -12) return ['Bajando', 'badge text-bg-danger', 'bi-graph-down-arrow'];
  return ['Estable', 'badge text-bg-warning', 'bi-activity'];
}

function obtenerSemanaPorIndice($offset = 0) {
  $tz = new DateTimeZone('America/Mexico_City');
  $hoy = new DateTime('now', $tz);
  $diaSemana = (int)$hoy->format('N'); // 1=Lun..7=Dom
  $dif = $diaSemana - 2;               // Martes=2
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

function weekStartFromDate($dateStr) {
  $tz = new DateTimeZone('America/Mexico_City');
  $d = new DateTime($dateStr, $tz);
  $n = (int)$d->format('N');
  $dif = $n - 2;
  if ($dif < 0) $dif += 7;
  $d->modify("-$dif days")->setTime(0, 0, 0);
  return $d->format('Y-m-d');
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

/* ==============================
   Scope Luga / Subdis
================================= */
$ROL_RAW = $_SESSION['rol'] ?? '';
$ROL = strtolower(trim((string)$ROL_RAW));

$isSubdisUser  = (strpos($ROL, 'subdis') === 0);
$isSubdisAdmin = ($ROL === 'subdis_admin');
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
   Parámetros de análisis
================================= */
$periodosValidos = [4, 8, 13, 26, 52];
$periodo = isset($_GET['periodo']) ? (int)$_GET['periodo'] : 13;
if (!in_array($periodo, $periodosValidos, true)) $periodo = 13;

$semanaSeleccionada = isset($_GET['semana']) ? max(0, (int)$_GET['semana']) : 0;
$vista = $_GET['vista'] ?? 'resumen';
$vistasValidas = ['resumen', 'sucursales', 'ejecutivos', 'subdis'];
if (!in_array($vista, $vistasValidas, true)) $vista = 'resumen';
if ($isSubdisUser && $vista === 'subdis') $vista = 'resumen';

[$inicioObj, $finObj] = rangoHistorico($periodo, $semanaSeleccionada);
[$inicioPrevObj, $finPrevObj] = rangoHistoricoAnterior($periodo, $semanaSeleccionada);
[$semanaBaseIni, $semanaBaseFin] = obtenerSemanaPorIndice($semanaSeleccionada);

$inicio = $inicioObj->format('Y-m-d');
$fin = $finObj->format('Y-m-d');
$inicioPrev = $inicioPrevObj->format('Y-m-d');
$finPrev = $finPrevObj->format('Y-m-d');

$ZENTRAL_LOGO = 'img/Logo_Zentral.png';
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
   Totales globales periodo actual
================================= */
$totalActual = [
  'sucursales' => 0,
  'unidades' => 0,
  'ventas' => 0.0,
  'cuota' => 0.0,
  'sim_pre' => 0,
  'sim_pos' => 0,
];

$sqlGlobal = "
  SELECT
    COUNT(DISTINCT s.id) AS sucursales,
    IFNULL(SUM(va.unidades),0) AS unidades,
    IFNULL(SUM(va.monto),0) AS ventas,
    IFNULL(SUM(COALESCE(cs.cuota_monto,0)),0) AS cuota
  FROM sucursales s
  LEFT JOIN ($subVentasAgg) va ON va.id_sucursal = s.id
  LEFT JOIN cuotas_sucursales cs
    ON cs.id_sucursal = s.id
   AND cs.fecha_inicio = va.dia
  WHERE s.tipo_sucursal='Tienda'
    AND s.activo=1
    AND $scopeSucursalesWhere
";

// Nota: si cuotas_sucursales no tiene una fila por día/semana exacta, se recalcula cuota en consulta semanal más abajo.
// Este global se corrige con suma semanal para evitar inflar o perder cuotas.

/* ==============================
   Serie semanal global
================================= */
$seriesGlobal = [];

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
  if ($isSubdisUser) {
    $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  } else {
    $st->bind_param('ss', $inicio, $fin);
  }
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $seriesGlobal[$r['semana_inicio']] = [
      'semana_inicio' => $r['semana_inicio'],
      'unidades' => (int)$r['unidades'],
      'ventas' => (float)$r['ventas'],
      'sim_pre' => 0,
      'sim_pos' => 0,
      'cuota' => 0.0,
    ];
  }
  $st->close();
}

// Inicializar semanas sin ventas para que la gráfica no quede mordida.
$cursor = clone $inicioObj;
for ($i = 0; $i < $periodo; $i++) {
  $wk = $cursor->format('Y-m-d');
  if (!isset($seriesGlobal[$wk])) {
    $seriesGlobal[$wk] = [
      'semana_inicio' => $wk,
      'unidades' => 0,
      'ventas' => 0.0,
      'sim_pre' => 0,
      'sim_pos' => 0,
      'cuota' => 0.0,
    ];
  }
  $cursor->modify('+7 days');
}
ksort($seriesGlobal);

/* ==============================
   SIMs por semana
================================= */
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
  if ($isSubdisUser) {
    $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  } else {
    $st->bind_param('ss', $inicio, $fin);
  }
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $wk = $r['semana_inicio'];
    if (!isset($seriesGlobal[$wk])) continue;
    $seriesGlobal[$wk]['sim_pre'] = (int)$r['sim_pre'];
    $seriesGlobal[$wk]['sim_pos'] = (int)$r['sim_pos'];
  }
  $st->close();
}

/* ==============================
   Cuotas por semana/sucursal
   Usa la cuota vigente <= semana_inicio.
================================= */
$sqlSucBase = "
  SELECT s.id
  FROM sucursales s
  WHERE s.tipo_sucursal='Tienda'
    AND s.activo=1
    AND $scopeSucursalesWhere
";
$sucIds = [];
if ($st = $conn->prepare($sqlSucBase)) {
  if ($isSubdisUser) {
    $st->bind_param('i', $idSubdis);
  }
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $sucIds[] = (int)$r['id'];
  }
  $st->close();
}

if (!empty($sucIds)) {
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
}

foreach ($seriesGlobal as $sg) {
  $totalActual['unidades'] += (int)$sg['unidades'];
  $totalActual['ventas'] += (float)$sg['ventas'];
  $totalActual['cuota'] += (float)$sg['cuota'];
  $totalActual['sim_pre'] += (int)$sg['sim_pre'];
  $totalActual['sim_pos'] += (int)$sg['sim_pos'];
}
$totalActual['sucursales'] = count($sucIds);
$totalActual['cumplimiento'] = $totalActual['cuota'] > 0 ? ($totalActual['ventas'] / $totalActual['cuota']) * 100 : 0;
$totalActual['promedio_ventas'] = $periodo > 0 ? $totalActual['ventas'] / $periodo : 0;
$totalActual['promedio_unidades'] = $periodo > 0 ? $totalActual['unidades'] / $periodo : 0;

$mejorSemana = null;
$peorSemana = null;
foreach ($seriesGlobal as $sg) {
  if ($mejorSemana === null || $sg['ventas'] > $mejorSemana['ventas']) $mejorSemana = $sg;
  if ($peorSemana === null || $sg['ventas'] < $peorSemana['ventas']) $peorSemana = $sg;
}

$seriesArr = array_values($seriesGlobal);
$mitad = max(1, (int)floor(count($seriesArr) / 2));
$primerBloque = array_slice($seriesArr, 0, $mitad);
$segundoBloque = array_slice($seriesArr, $mitad);
$ventasPrimerBloque = array_sum(array_column($primerBloque, 'ventas'));
$ventasSegundoBloque = array_sum(array_column($segundoBloque, 'ventas'));
[$txtTendGlobal, $clsTendGlobal, $icoTendGlobal] = tendenciaLabel($ventasSegundoBloque, $ventasPrimerBloque);

/* ==============================
   Totales periodo anterior
================================= */
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
  if ($isSubdisUser) {
    $st->bind_param('ssi', $inicioPrev, $finPrev, $idSubdis);
  } else {
    $st->bind_param('ss', $inicioPrev, $finPrev);
  }
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
   Sucursales histórico
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
  if ($isSubdisUser) {
    $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  } else {
    $st->bind_param('ss', $inicio, $fin);
  }
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $r['unidades'] = (int)$r['unidades'];
    $r['ventas'] = (float)$r['ventas'];
    $r['prom_ventas'] = $periodo > 0 ? $r['ventas'] / $periodo : 0;
    $r['prom_unidades'] = $periodo > 0 ? $r['unidades'] / $periodo : 0;
    $r['sim_pre'] = 0;
    $r['sim_pos'] = 0;
    $r['cuota'] = 0.0;
    $r['cumplimiento'] = 0.0;
    $r['semanas_cumplidas'] = 0;
    $r['tendencia_actual'] = 0.0;
    $r['tendencia_previa'] = 0.0;
    $sucursales[(int)$r['id_sucursal']] = $r;
  }
  $st->close();
}

// SIMs por sucursal
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
  if ($isSubdisUser) {
    $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  } else {
    $st->bind_param('ss', $inicio, $fin);
  }
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

// Cuota acumulada y semanas cumplidas por sucursal
foreach ($sucursales as $idSuc => &$suc) {
  foreach ($seriesGlobal as $wk => $sg) {
    $cuotaSemanaSuc = 0.0;
    if ($st = $conn->prepare("SELECT cuota_monto FROM cuotas_sucursales WHERE id_sucursal=? AND fecha_inicio <= ? ORDER BY fecha_inicio DESC LIMIT 1")) {
      $st->bind_param('is', $idSuc, $wk);
      $st->execute();
      $rs = $st->get_result();
      if ($rs && ($r = $rs->fetch_assoc())) {
        $cuotaSemanaSuc = (float)($r['cuota_monto'] ?? 0);
      }
      $st->close();
    }
    $suc['cuota'] += $cuotaSemanaSuc;
  }
  $suc['cumplimiento'] = $suc['cuota'] > 0 ? ($suc['ventas'] / $suc['cuota']) * 100 : 0;
}
unset($suc);

// Tendencia por sucursal primera mitad vs segunda mitad
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
$fechaCorteTend = $seriesArr[$mitad - 1]['semana_inicio'] ?? $inicio;
if ($st = $conn->prepare($sqlSucTrend)) {
  if ($isSubdisUser) {
    $st->bind_param('sssi', $fechaCorteTend, $inicio, $fin, $idSubdis);
  } else {
    $st->bind_param('sss', $fechaCorteTend, $inicio, $fin);
  }
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
   Ejecutivos histórico
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
  if ($isSubdisUser) {
    $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  } else {
    $st->bind_param('ss', $inicio, $fin);
  }
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) {
    $r['unidades'] = (int)$r['unidades'];
    $r['ventas'] = (float)$r['ventas'];
    $r['prom_unidades'] = $periodo > 0 ? $r['unidades'] / $periodo : 0;
    $r['prom_ventas'] = $periodo > 0 ? $r['ventas'] / $periodo : 0;
    $r['sim_pre'] = 0;
    $r['sim_pos'] = 0;
    $r['semanas_cumplidas'] = 0;
    $r['efectividad'] = 0.0;
    $ejecutivos[(int)$r['id']] = $r;
  }
  $st->close();
}

// SIMs por usuario
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
  if ($isSubdisUser) {
    $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  } else {
    $st->bind_param('ss', $inicio, $fin);
  }
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

// Semanas cumplidas por ejecutivo: usa cuota_unidades vigente de la sucursal.
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
  if ($isSubdisUser) {
    $st->bind_param('ssi', $inicio, $fin, $idSubdis);
  } else {
    $st->bind_param('ss', $inicio, $fin);
  }
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
   Zonas agregado
================================= */
$zonas = [];
foreach ($sucursales as $s) {
  $z = normalizarZona($s['zona'] ?? '') ?? 'Sin zona';
  if (!isset($zonas[$z])) {
    $zonas[$z] = [
      'zona' => $z,
      'sucursales' => 0,
      'unidades' => 0,
      'ventas' => 0.0,
      'cuota' => 0.0,
      'sim_pre' => 0,
      'sim_pos' => 0,
    ];
  }
  $zonas[$z]['sucursales']++;
  $zonas[$z]['unidades'] += (int)$s['unidades'];
  $zonas[$z]['ventas'] += (float)$s['ventas'];
  $zonas[$z]['cuota'] += (float)$s['cuota'];
  $zonas[$z]['sim_pre'] += (int)$s['sim_pre'];
  $zonas[$z]['sim_pos'] += (int)$s['sim_pos'];
}
foreach ($zonas as &$z) {
  $z['cumplimiento'] = $z['cuota'] > 0 ? ($z['ventas'] / $z['cuota']) * 100 : 0;
}
unset($z);
$zonas = array_values($zonas);
usort($zonas, fn($a, $b) => $b['ventas'] <=> $a['ventas']);

/* ==============================
   Subdis agregados, solo vista Luga
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

/* ==============================
   Datos para Chart.js
================================= */
$chartLabels = [];
$chartVentas = [];
$chartUnidades = [];
$chartSimPre = [];
$chartSimPos = [];
$chartCumpl = [];

foreach ($seriesGlobal as $wk => $sg) {
  $iniW = new DateTime($wk, new DateTimeZone('America/Mexico_City'));
  $finW = clone $iniW;
  $finW->modify('+6 days');
  $chartLabels[] = $iniW->format('d/m') . '→' . $finW->format('d/m');
  $chartVentas[] = round((float)$sg['ventas'], 2);
  $chartUnidades[] = (int)$sg['unidades'];
  $chartSimPre[] = (int)$sg['sim_pre'];
  $chartSimPos[] = (int)$sg['sim_pos'];
  $chartCumpl[] = ((float)$sg['cuota'] > 0) ? round(((float)$sg['ventas'] / (float)$sg['cuota']) * 100, 2) : 0;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Zentral | Dashboard Histórico</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{
      --z-bg:#07111f;
      --z-card:#ffffff;
      --z-primary:#2563eb;
      --z-primary-2:#06b6d4;
      --z-dark:#0f172a;
      --z-muted:#64748b;
      --z-border:rgba(15,23,42,.10);
      --z-radius:22px;
    }

    body.zentral-body{
      min-height:100vh;
      background:
        radial-gradient(circle at top left, rgba(37,99,235,.22), transparent 34%),
        radial-gradient(circle at top right, rgba(6,182,212,.18), transparent 30%),
        linear-gradient(180deg,#07111f 0%,#eef4fb 36%,#f8fafc 100%);
      color:#0f172a;
    }

    .zentral-shell{ max-width:1480px; }

    .zentral-hero{
      position:relative;
      overflow:hidden;
      border-radius:30px;
      padding:28px;
      background:linear-gradient(135deg,rgba(15,23,42,.98),rgba(30,64,175,.94));
      box-shadow:0 24px 70px rgba(2,6,23,.28);
      border:1px solid rgba(255,255,255,.14);
    }

    .zentral-hero::after{
      content:"";
      position:absolute;
      width:300px;
      height:300px;
      right:-100px;
      top:-120px;
      border-radius:999px;
      background:rgba(6,182,212,.28);
      filter:blur(8px);
    }

    .zentral-logo-card{
      width:82px;
      height:82px;
      border-radius:24px;
      background:rgba(255,255,255,.96);
      display:flex;
      align-items:center;
      justify-content:center;
      box-shadow:0 20px 40px rgba(0,0,0,.22);
      z-index:1;
      flex-shrink:0;
    }

    .zentral-logo{ max-width:68px; max-height:68px; object-fit:contain; }

    .zentral-title{
      color:#fff;
      font-weight:900;
      letter-spacing:-.04em;
      font-size:clamp(2rem,4vw,3.6rem);
      line-height:1;
    }

    .zentral-subtitle{ color:rgba(255,255,255,.74); font-size:1rem; }

    .zentral-kicker{
      display:inline-flex;
      align-items:center;
      gap:.4rem;
      padding:.35rem .7rem;
      border-radius:999px;
      background:rgba(255,255,255,.12);
      color:#bfdbfe;
      font-size:.78rem;
      font-weight:800;
      text-transform:uppercase;
      letter-spacing:.12em;
      margin-bottom:.5rem;
    }

    .zentral-range-card{
      position:relative;
      z-index:1;
      min-width:min(100%,380px);
      border-radius:22px;
      padding:18px 20px;
      color:#fff;
      background:rgba(255,255,255,.10);
      border:1px solid rgba(255,255,255,.16);
      backdrop-filter:blur(16px);
    }

    .card{
      border:0 !important;
      border-radius:var(--z-radius) !important;
      box-shadow:0 16px 45px rgba(15,23,42,.10) !important;
      overflow:hidden;
    }

    .card-header{
      border-bottom:1px solid rgba(255,255,255,.12) !important;
      font-weight:850;
      letter-spacing:-.01em;
    }

    .card-header.bg-dark,
    .card-header.bg-primary,
    .card-header.bg-secondary{
      background:linear-gradient(135deg,#0f172a,#1d4ed8) !important;
    }

    .card-body{ background:rgba(255,255,255,.96); }

    .filter-panel{
      background:rgba(255,255,255,.92);
      border:1px solid var(--z-border);
      border-radius:22px;
      padding:14px 16px;
      box-shadow:0 14px 35px rgba(15,23,42,.08);
    }

    .btn-periodo{
      border:0;
      border-radius:999px;
      padding:.65rem 1rem;
      font-weight:900;
      background:#e2e8f0;
      color:#0f172a;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      gap:.35rem;
    }

    .btn-periodo.active{
      color:#fff;
      background:linear-gradient(135deg,var(--z-primary),var(--z-primary-2));
      box-shadow:0 12px 26px rgba(37,99,235,.30);
    }

    .kpi-card{
      transition:transform .18s ease, box-shadow .18s ease;
      height:100%;
    }

    .kpi-card:hover{ transform:translateY(-4px); }

    .kpi-label{
      color:#64748b;
      font-weight:800;
      font-size:.82rem;
      text-transform:uppercase;
      letter-spacing:.08em;
    }

    .kpi-value{
      font-size:clamp(1.6rem,2.7vw,2.55rem);
      line-height:1;
      font-weight:950;
      letter-spacing:-.05em;
      color:#0f172a;
    }

    .kpi-sub{
      font-size:.86rem;
      color:#64748b;
      font-weight:700;
    }

    .soft-chip{
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      padding:.35rem .6rem;
      border-radius:999px;
      background:#eef6ff;
      color:#1d4ed8;
      font-weight:900;
      font-size:.78rem;
    }

    .nav-tabs{
      border:0;
      gap:.5rem;
      background:rgba(255,255,255,.74);
      border:1px solid var(--z-border);
      padding:.55rem;
      border-radius:20px;
      box-shadow:0 14px 35px rgba(15,23,42,.08);
    }

    .nav-tabs .nav-link{
      border:0 !important;
      border-radius:15px !important;
      color:#475569;
      font-weight:900;
      padding:.75rem 1rem;
    }

    .nav-tabs .nav-link.active{
      color:#fff !important;
      background:linear-gradient(135deg,var(--z-primary),var(--z-primary-2)) !important;
      box-shadow:0 12px 26px rgba(37,99,235,.30);
    }

    .table{ --bs-table-bg:transparent; border-color:#e2e8f0; }
    .table thead th{
      background:#0f172a !important;
      color:#e2e8f0 !important;
      border-color:#1e293b !important;
      font-size:.78rem;
      text-transform:uppercase;
      letter-spacing:.04em;
      white-space:nowrap;
      cursor:pointer;
      user-select:none;
    }
    .table tbody td,.table tbody th{ vertical-align:middle; border-color:#e2e8f0; }
    .table-striped>tbody>tr:nth-of-type(odd)>*{ --bs-table-bg-type:rgba(248,250,252,.9); }

    .num{ font-variant-numeric:tabular-nums; letter-spacing:-.2px; white-space:nowrap; }
    .clip{ max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .progress{ height:14px !important; border-radius:999px; background:#e2e8f0; }
    .progress-bar{ border-radius:999px; font-size:.72rem; font-weight:900; }

    #chartWrap{
      background:linear-gradient(180deg,#ffffff,#f8fafc);
      border:1px solid #e2e8f0;
      border-radius:22px;
      padding:16px;
      position:relative;
      height:390px;
    }

    .heat-cell{
      width:26px;
      height:26px;
      border-radius:8px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-size:.7rem;
      font-weight:900;
    }
    .heat-good{ background:#dcfce7; color:#166534; }
    .heat-mid{ background:#fef3c7; color:#92400e; }
    .heat-low{ background:#ffe4e6; color:#9f1239; }
    .heat-none{ background:#e2e8f0; color:#475569; }

    @media(max-width:768px){
      .zentral-hero{ padding:22px; border-radius:24px; }
      .zentral-logo-card{ width:68px; height:68px; border-radius:20px; }
      .zentral-logo{ max-width:56px; max-height:56px; }
      .zentral-range-card{ width:100%; }
      #chartWrap{ height:340px; }
    }

    @media(max-width:576px){
      .zentral-shell{ padding-left:6px !important; padding-right:6px !important; }
      .card{ border-radius:18px !important; }
      .card-body{ padding:.75rem !important; }
      .table{ font-size:12px; }
      .table-responsive{ overflow-x:auto !important; }
      .btn-periodo{ padding:.55rem .8rem; font-size:.86rem; }
    }
  </style>
</head>
<body class="zentral-body">
<?php include __DIR__ . '/navbar.php'; ?>

<div class="container-fluid zentral-shell px-3 px-lg-4 py-4">

  <section class="zentral-hero mb-4">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-4">
      <div class="d-flex align-items-center gap-3">
        <div class="zentral-logo-card">
          <img src="<?= h($ZENTRAL_LOGO) ?>" alt="Zentral" class="zentral-logo">
        </div>
        <div>
          <div class="zentral-kicker"><i class="bi bi-clock-history"></i> Histórico comercial</div>
          <h1 class="zentral-title mb-1">Zentral <?= h($empresaZentral) ?></h1>
          <p class="zentral-subtitle mb-0">Análisis acumulado por ventanas operativas de 4, 8, 13, 26 y 52 semanas</p>
        </div>
      </div>

      <div class="zentral-range-card">
        <div class="small text-white-50 mb-1">Periodo analizado</div>
        <div class="fw-bold fs-5"><?= $inicioObj->format('d/m/Y') ?> → <?= $finObj->format('d/m/Y') ?></div>
        <div class="small text-white-50 mt-1">
          <?= (int)$periodo ?> semanas · Comparativo anterior: <?= $inicioPrevObj->format('d/m/Y') ?> → <?= $finPrevObj->format('d/m/Y') ?>
        </div>
      </div>
    </div>
  </section>

  <form method="GET" class="filter-panel mb-4">
    <input type="hidden" name="vista" value="<?= h($vista) ?>">
    <div class="row g-3 align-items-end">
      <div class="col-12 col-lg-6">
        <label class="fw-bold mb-2 d-block">Ventana de análisis</label>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($periodosValidos as $p): ?>
            <?php
              $url = '?periodo=' . $p . '&semana=' . $semanaSeleccionada . '&vista=' . urlencode($vista);
              $lbl = $p === 13 ? '13 sem. trimestre' : $p . ' sem.';
            ?>
            <a href="<?= h($url) ?>" class="btn-periodo <?= $periodo === $p ? 'active' : '' ?>">
              <i class="bi bi-calendar-range"></i><?= h($lbl) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-12 col-md-6 col-lg-3">
        <label class="fw-bold mb-2">Semana base</label>
        <select name="semana" class="form-select" onchange="this.form.submit()">
          <?php for ($i = 0; $i < 52; $i++):
            [$ini, $fi] = obtenerSemanaPorIndice($i);
            $txt = $ini->format('d/m/Y') . ' al ' . $fi->format('d/m/Y');
          ?>
            <option value="<?= $i ?>" <?= $i === $semanaSeleccionada ? 'selected' : '' ?>><?= h($txt) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-12 col-md-6 col-lg-3">
        <button class="btn btn-primary w-100 fw-bold" type="submit">
          <i class="bi bi-funnel"></i> Aplicar análisis
        </button>
      </div>
    </div>
  </form>

  <div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card kpi-card"><div class="card-body">
        <div class="kpi-label">Ventas acumuladas</div>
        <div class="kpi-value mt-2"><?= money($totalActual['ventas']) ?></div>
        <div class="kpi-sub mt-2">
          <?php [$ico, $cls] = arrowIcon($deltaVentas); ?>
          <span class="<?= $cls ?> fw-bold"><?= $ico ?> <?= money($deltaVentas) ?></span>
          <?php if ($deltaVentasPct !== null): ?> vs periodo anterior (<?= number_format($deltaVentasPct, 1) ?>%)<?php else: ?> vs periodo anterior<?php endif; ?>
        </div>
      </div></div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="card kpi-card"><div class="card-body">
        <div class="kpi-label">Unidades acumuladas</div>
        <div class="kpi-value mt-2"><?= number_format((int)$totalActual['unidades']) ?></div>
        <div class="kpi-sub mt-2">
          <?php [$icoU, $clsU] = arrowIcon($deltaUnidades); ?>
          <span class="<?= $clsU ?> fw-bold"><?= $icoU ?> <?= ($deltaUnidades > 0 ? '+' : '') . number_format($deltaUnidades) ?> u.</span>
          <?php if ($deltaUnidadesPct !== null): ?>(<?= number_format($deltaUnidadesPct, 1) ?>%)<?php endif; ?>
        </div>
      </div></div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="card kpi-card"><div class="card-body">
        <div class="kpi-label">Cumplimiento global</div>
        <div class="kpi-value mt-2"><?= pct($totalActual['cumplimiento']) ?></div>
        <div class="kpi-sub mt-2">Cuota acumulada: <strong><?= money($totalActual['cuota']) ?></strong></div>
        <div class="progress mt-3">
          <div class="progress-bar <?= $totalActual['cumplimiento'] >= 100 ? 'bg-success' : ($totalActual['cumplimiento'] >= 60 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= min(100, (float)$totalActual['cumplimiento']) ?>%"></div>
        </div>
      </div></div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="card kpi-card"><div class="card-body">
        <div class="kpi-label">Tendencia</div>
        <div class="mt-3"><span class="<?= h($clsTendGlobal) ?> fs-6"><i class="bi <?= h($icoTendGlobal) ?>"></i> <?= h($txtTendGlobal) ?></span></div>
        <div class="kpi-sub mt-3">Promedio semanal: <strong><?= money($totalActual['promedio_ventas']) ?></strong> · <?= number_format($totalActual['promedio_unidades'], 1) ?> u.</div>
      </div></div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-12 col-lg-4">
      <div class="card h-100"><div class="card-body">
        <div class="kpi-label">Mejor semana</div>
        <?php if ($mejorSemana): ?>
          <?php $mwIni = new DateTime($mejorSemana['semana_inicio']); $mwFin = clone $mwIni; $mwFin->modify('+6 days'); ?>
          <div class="fs-4 fw-black mt-2"><strong><?= money($mejorSemana['ventas']) ?></strong></div>
          <div class="text-muted fw-semibold"><?= $mwIni->format('d/m/Y') ?> → <?= $mwFin->format('d/m/Y') ?> · <?= (int)$mejorSemana['unidades'] ?> unidades</div>
        <?php endif; ?>
      </div></div>
    </div>
    <div class="col-12 col-lg-4">
      <div class="card h-100"><div class="card-body">
        <div class="kpi-label">Semana más baja</div>
        <?php if ($peorSemana): ?>
          <?php $pwIni = new DateTime($peorSemana['semana_inicio']); $pwFin = clone $pwIni; $pwFin->modify('+6 days'); ?>
          <div class="fs-4 fw-black mt-2"><strong><?= money($peorSemana['ventas']) ?></strong></div>
          <div class="text-muted fw-semibold"><?= $pwIni->format('d/m/Y') ?> → <?= $pwFin->format('d/m/Y') ?> · <?= (int)$peorSemana['unidades'] ?> unidades</div>
        <?php endif; ?>
      </div></div>
    </div>
    <div class="col-12 col-lg-4">
      <div class="card h-100"><div class="card-body">
        <div class="kpi-label">SIMs acumuladas</div>
        <div class="d-flex gap-2 flex-wrap mt-3">
          <span class="soft-chip"><i class="bi bi-sim"></i> Prepago: <?= number_format((int)$totalActual['sim_pre']) ?></span>
          <span class="soft-chip"><i class="bi bi-phone"></i> Pospago: <?= number_format((int)$totalActual['sim_pos']) ?></span>
        </div>
      </div></div>
    </div>
  </div>

  <ul class="nav nav-tabs mb-3">
    <?php
      $tabs = [
        'resumen' => ['Resumen', 'bi-speedometer2'],
        'sucursales' => ['Sucursales y zonas', 'bi-buildings'],
        'ejecutivos' => ['Ejecutivos', 'bi-person-badge'],
      ];
      if (!$isSubdisUser) $tabs['subdis'] = ['Subdis', 'bi-diagram-3'];
    ?>
    <?php foreach ($tabs as $key => [$label, $icon]): ?>
      <li class="nav-item">
        <a class="nav-link <?= $vista === $key ? 'active' : '' ?>" href="?periodo=<?= (int)$periodo ?>&semana=<?= (int)$semanaSeleccionada ?>&vista=<?= h($key) ?>">
          <i class="bi <?= h($icon) ?>"></i> <?= h($label) ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($vista === 'resumen'): ?>
    <div class="card shadow mb-4">
      <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span>Tendencia semanal del periodo</span>
        <div class="btn-group btn-group-sm">
          <button id="btnVentas" class="btn btn-primary" type="button">Ventas</button>
          <button id="btnUnidades" class="btn btn-outline-light" type="button">Unidades</button>
          <button id="btnSims" class="btn btn-outline-light" type="button">SIMs</button>
          <button id="btnCumpl" class="btn btn-outline-light" type="button">Cumplimiento</button>
        </div>
      </div>
      <div class="card-body">
        <div id="chartWrap"><canvas id="chartHistorico"></canvas></div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-12 col-xl-6">
        <div class="card h-100">
          <div class="card-header bg-dark text-white">Top sucursales del periodo</div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-sm table-striped align-middle js-sortable">
                <thead><tr><th>Sucursal</th><th>Zona</th><th class="text-end">Ventas</th><th class="text-end">Unidades</th><th class="text-end">Cumpl.</th></tr></thead>
                <tbody>
                  <?php foreach (array_slice($sucursales, 0, 10) as $s): ?>
                    <tr>
                      <td class="clip" title="<?= h($s['sucursal']) ?>"><?= h(sucursalCorta($s['sucursal'])) ?></td>
                      <td><?= h(normalizarZona($s['zona']) ?? '—') ?></td>
                      <td class="text-end num"><?= money($s['ventas']) ?></td>
                      <td class="text-end num"><?= number_format((int)$s['unidades']) ?></td>
                      <td class="text-end num"><?= pct($s['cumplimiento']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (empty($sucursales)): ?><tr><td colspan="5" class="text-center text-muted">Sin datos.</td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <div class="col-12 col-xl-6">
        <div class="card h-100">
          <div class="card-header bg-dark text-white">Top ejecutivos del periodo</div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-sm table-striped align-middle js-sortable">
                <thead><tr><th>Ejecutivo</th><th>Sucursal</th><th class="text-end">Unidades</th><th class="text-end">Ventas</th><th class="text-end">Efect.</th></tr></thead>
                <tbody>
                  <?php foreach (array_slice($ejecutivos, 0, 10) as $e): ?>
                    <tr>
                      <td class="clip" title="<?= h($e['nombre']) ?>"><?= h($e['nombre']) ?></td>
                      <td class="clip" title="<?= h($e['sucursal']) ?>"><?= h(sucursalCorta($e['sucursal'])) ?></td>
                      <td class="text-end num"><?= number_format((int)$e['unidades']) ?></td>
                      <td class="text-end num"><?= money($e['ventas']) ?></td>
                      <td class="text-end num"><?= pct($e['efectividad']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (empty($ejecutivos)): ?><tr><td colspan="5" class="text-center text-muted">Sin datos.</td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($vista === 'sucursales'): ?>
    <div class="row g-3 mb-4">
      <?php foreach ($zonas as $z): ?>
        <div class="col-12 col-md-6 col-xl-4">
          <div class="card kpi-card">
            <div class="card-header bg-dark text-white"><?= h($z['zona']) ?></div>
            <div class="card-body">
              <div class="kpi-value"><?= pct($z['cumplimiento']) ?></div>
              <div class="kpi-sub mt-2">
                <?= number_format((int)$z['unidades']) ?> unidades · <?= money($z['ventas']) ?><br>
                Cuota: <?= money($z['cuota']) ?> · Sucursales: <?= (int)$z['sucursales'] ?>
              </div>
              <div class="progress mt-3"><div class="progress-bar <?= $z['cumplimiento'] >= 100 ? 'bg-success' : ($z['cumplimiento'] >= 60 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= min(100, (float)$z['cumplimiento']) ?>%"></div></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card shadow mb-4">
      <div class="card-header bg-dark text-white">Ranking histórico de sucursales</div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm table-striped table-hover align-middle js-sortable">
            <thead>
              <tr>
                <th>Sucursal</th>
                <th>Zona</th>
                <th class="text-end">Ventas</th>
                <th class="text-end">Unidades</th>
                <th class="text-end">Prom. semanal</th>
                <th class="text-end">Cuota</th>
                <th class="text-end">% Cumpl.</th>
                <th class="text-end">SIM Pre</th>
                <th class="text-end">SIM Pos</th>
                <th>Tendencia</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sucursales as $s): ?>
                <?php [$tTxt, $tCls, $tIcon] = tendenciaLabel((float)$s['tendencia_actual'], (float)$s['tendencia_previa']); ?>
                <tr>
                  <td class="clip" title="<?= h($s['sucursal']) ?>"><?= h($s['sucursal']) ?></td>
                  <td><?= h(normalizarZona($s['zona']) ?? '—') ?></td>
                  <td class="text-end num"><?= money($s['ventas']) ?></td>
                  <td class="text-end num"><?= number_format((int)$s['unidades']) ?></td>
                  <td class="text-end num"><?= money($s['prom_ventas']) ?></td>
                  <td class="text-end num"><?= money($s['cuota']) ?></td>
                  <td class="text-end num"><?= pct($s['cumplimiento']) ?></td>
                  <td class="text-end num"><?= number_format((int)$s['sim_pre']) ?></td>
                  <td class="text-end num"><?= number_format((int)$s['sim_pos']) ?></td>
                  <td><span class="<?= h($tCls) ?>"><i class="bi <?= h($tIcon) ?>"></i> <?= h($tTxt) ?></span></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($sucursales)): ?><tr><td colspan="10" class="text-center text-muted">Sin datos para este periodo.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($vista === 'ejecutivos'): ?>
    <div class="card shadow mb-4">
      <div class="card-header bg-dark text-white">Ranking histórico de ejecutivos</div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm table-striped table-hover align-middle js-sortable">
            <thead>
              <tr>
                <th>Ejecutivo</th>
                <th>Sucursal</th>
                <th>Rol</th>
                <th class="text-end">Unidades</th>
                <th class="text-end">Ventas</th>
                <th class="text-end">Prom. semanal</th>
                <th class="text-end">Semanas cumplidas</th>
                <th class="text-end">Efectividad</th>
                <th class="text-end">SIM Pre</th>
                <th class="text-end">SIM Pos</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ejecutivos as $e): ?>
                <tr>
                  <td class="clip" title="<?= h($e['nombre']) ?>"><?= h($e['nombre']) ?></td>
                  <td class="clip" title="<?= h($e['sucursal']) ?>"><?= h($e['sucursal']) ?></td>
                  <td><?= h($e['rol']) ?></td>
                  <td class="text-end num"><?= number_format((int)$e['unidades']) ?></td>
                  <td class="text-end num"><?= money($e['ventas']) ?></td>
                  <td class="text-end num"><?= number_format((float)$e['prom_unidades'], 1) ?> u.</td>
                  <td class="text-end num"><?= (int)$e['semanas_cumplidas'] ?> / <?= (int)$periodo ?></td>
                  <td class="text-end num">
                    <?= pct($e['efectividad']) ?>
                    <div class="progress mt-1"><div class="progress-bar <?= $e['efectividad'] >= 80 ? 'bg-success' : ($e['efectividad'] >= 50 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= min(100, (float)$e['efectividad']) ?>%"></div></div>
                  </td>
                  <td class="text-end num"><?= number_format((int)$e['sim_pre']) ?></td>
                  <td class="text-end num"><?= number_format((int)$e['sim_pos']) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($ejecutivos)): ?><tr><td colspan="10" class="text-center text-muted">Sin datos para este periodo.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($vista === 'subdis' && !$isSubdisUser): ?>
    <div class="card shadow mb-4">
      <div class="card-header bg-dark text-white">Subdistribuidores histórico</div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm table-striped table-hover align-middle js-sortable">
            <thead>
              <tr>
                <th>Subdis</th>
                <th class="text-end">Sucursales</th>
                <th class="text-end">Unidades</th>
                <th class="text-end">Ventas</th>
                <th class="text-end">Prom. semanal</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($subdisAgg as $sd): ?>
                <tr>
                  <td><?= h($sd['subdis']) ?></td>
                  <td class="text-end num"><?= number_format((int)$sd['sucursales']) ?></td>
                  <td class="text-end num"><?= number_format((int)$sd['unidades']) ?></td>
                  <td class="text-end num"><?= money($sd['ventas']) ?></td>
                  <td class="text-end num"><?= money($sd['prom_ventas']) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($subdisAgg)): ?><tr><td colspan="5" class="text-center text-muted">Sin datos de Subdis en este periodo.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const chartLabels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const chartVentas = <?= json_encode($chartVentas, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const chartUnidades = <?= json_encode($chartUnidades, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const chartSimPre = <?= json_encode($chartSimPre, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const chartSimPos = <?= json_encode($chartSimPos, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
const chartCumpl = <?= json_encode($chartCumpl, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;

let chartHistorico = null;
let metric = 'ventas';

function moneyFmt(n){
  return '$' + Number(n || 0).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function renderHistorico(){
  const canvas = document.getElementById('chartHistorico');
  if (!canvas) return;

  let datasets = [];
  let yTitle = 'Ventas ($)';
  let type = 'bar';

  if (metric === 'ventas') {
    datasets = [{ label:'Ventas ($)', data:chartVentas, borderWidth:0, borderRadius:8 }];
    yTitle = 'Ventas ($)';
  } else if (metric === 'unidades') {
    datasets = [{ label:'Unidades', data:chartUnidades, borderWidth:0, borderRadius:8 }];
    yTitle = 'Unidades';
  } else if (metric === 'sims') {
    datasets = [
      { label:'SIM Prepago', data:chartSimPre, borderWidth:0, borderRadius:8 },
      { label:'SIM Pospago', data:chartSimPos, borderWidth:0, borderRadius:8 }
    ];
    yTitle = 'SIMs';
  } else if (metric === 'cumpl') {
    type = 'line';
    datasets = [{ label:'% Cumplimiento', data:chartCumpl, borderWidth:3, tension:.3, pointRadius:4, fill:false }];
    yTitle = '% Cumplimiento';
  }

  const options = {
    responsive:true,
    maintainAspectRatio:false,
    plugins:{
      legend:{ display: metric === 'sims' },
      tooltip:{
        callbacks:{
          label:function(ctx){
            if (metric === 'ventas') return ' ' + moneyFmt(ctx.parsed.y);
            if (metric === 'cumpl') return ' ' + Number(ctx.parsed.y).toFixed(1) + '%';
            return ' ' + Number(ctx.parsed.y || 0).toLocaleString('es-MX');
          }
        }
      }
    },
    scales:{
      x:{ ticks:{ maxRotation:45, minRotation:0 }, grid:{ display:false } },
      y:{ beginAtZero:true, title:{ display:true, text:yTitle } }
    }
  };

  if (chartHistorico) chartHistorico.destroy();
  chartHistorico = new Chart(canvas.getContext('2d'), { type, data:{ labels:chartLabels, datasets }, options });
}

function setMetric(next, btnId){
  metric = next;
  ['btnVentas','btnUnidades','btnSims','btnCumpl'].forEach(id => {
    const b = document.getElementById(id);
    if (!b) return;
    b.className = id === btnId ? 'btn btn-primary' : 'btn btn-outline-light';
  });
  renderHistorico();
}

document.getElementById('btnVentas')?.addEventListener('click', () => setMetric('ventas', 'btnVentas'));
document.getElementById('btnUnidades')?.addEventListener('click', () => setMetric('unidades', 'btnUnidades'));
document.getElementById('btnSims')?.addEventListener('click', () => setMetric('sims', 'btnSims'));
document.getElementById('btnCumpl')?.addEventListener('click', () => setMetric('cumpl', 'btnCumpl'));
renderHistorico();

// Ordenamiento ligero de tablas
(function(){
  function parseVal(str){
    const s = (str || '').replace(/\s+/g,' ').trim();
    if (s.includes('$')) return parseFloat(s.replace(/[^0-9.\-]/g,'')) || 0;
    if (s.includes('%')) return parseFloat(s.replace(/[^0-9.\-]/g,'')) || 0;
    const n = s.replace(/,/g,'').match(/-?\d+(\.\d+)?/);
    if (n && n[0]) return parseFloat(n[0]) || 0;
    return s.toLowerCase();
  }
  function sortTable(table, idx, dir){
    const tbody = table.tBodies[0];
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a,b) => {
      let av = parseVal(a.children[idx]?.innerText || '');
      let bv = parseVal(b.children[idx]?.innerText || '');
      if (typeof av === 'string' || typeof bv === 'string') {
        av = String(av); bv = String(bv);
        return dir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
      }
      return dir === 'asc' ? av - bv : bv - av;
    });
    rows.forEach(r => tbody.appendChild(r));
    table.dataset.sortedCol = idx;
    table.dataset.sortedDir = dir;
  }
  document.querySelectorAll('.js-sortable').forEach(table => {
    table.querySelectorAll('thead th').forEach((th, idx) => {
      th.addEventListener('click', () => {
        const currentCol = parseInt(table.dataset.sortedCol || '-1', 10);
        const currentDir = table.dataset.sortedDir || 'desc';
        const nextDir = currentCol === idx && currentDir === 'desc' ? 'asc' : 'desc';
        sortTable(table, idx, nextDir);
      });
    });
  });
})();
</script>
</body>
</html>
