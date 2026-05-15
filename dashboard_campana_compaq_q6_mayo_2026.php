<?php
/**
 * dashboard_campana_compaq_q6_mayo_2026.php
 * Tablero de campaña Compaq Q6 | Mayo 2026
 *
 * Reglas nuevas:
 * - Vigencia: 12 al 18 de mayo de 2026.
 * - Gana 1 Ejecutivo por EMPRESA por volumen de equipos vendidos.
 * - Gana 1 Gerente por EMPRESA por monto de venta de su sucursal asignada.
 * - Condición para ambos: su sucursal debe llegar mínimo al 100% de su cuota semanal.
 * - Solo se cuentan equipos.
 * - No se cuentan combos: detalle_venta.es_combo = 0.
 * - No se cuentan regalos: detalle_venta.es_regalo = 0.
 * - No se cuentan sucursales Subdis ni roles Subdis_*.
 */

session_start();

if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');
@mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

/* ==========================================================
   CONFIGURACIÓN EDITABLE
========================================================== */

$CAMPANA_NOMBRE       = 'Campaña Compaq Q6 Mayo 2026';
$CAMPANA_SUBTITULO    = 'El mejor ejecutivo por volumen y el mejor gerente por monto de venta se llevan un Compaq Q6';
$FECHA_INICIO         = '2026-05-12';
$FECHA_FIN            = '2026-05-18';
$RUTA_PREMIO          = 'img/compaq_q6.webp';
$ZENTRAL_LOGO         = 'img/Logo_Zentral.png';
$EMPRESA_NOMBRE       = 'Zentral';
$totalPremios         = 2;

$ROLES_PERMITIDOS = [
  'Admin',
  'Logistica',
  'Logística',
  'Sistemas',
  'Supervisor',
  'GerenteZona',
  'Gerente',
  'Ejecutivo'
];

$rolSesion = (string)($_SESSION['rol'] ?? '');
if (!in_array($rolSesion, $ROLES_PERMITIDOS, true)) {
  header("Location: 403.php");
  exit();
}

/* ==========================================================
   HELPERS
========================================================== */

function h($v) {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v) {
  return '$' . number_format((float)$v, 2);
}

function pct($v) {
  return number_format((float)$v, 1) . '%';
}

function inicialesNombre($nombre) {
  $nombre = trim((string)$nombre);
  if ($nombre === '') return '??';

  $partes = preg_split('/\s+/', $nombre);
  $ini = '';

  foreach ($partes as $p) {
    if ($p !== '') {
      $ini .= mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8');
    }
    if (mb_strlen($ini, 'UTF-8') >= 2) break;
  }

  return $ini ?: '??';
}

function fotoEmpleado($foto) {
  $foto = trim((string)$foto);
  if ($foto === '') return '';
  if (preg_match('/^https?:\/\//i', $foto)) return $foto;
  return ltrim($foto, '/');
}

function diasRestantesCampana($fechaFin) {
  $hoy = new DateTime('today', new DateTimeZone('America/Mexico_City'));
  $fin = new DateTime($fechaFin, new DateTimeZone('America/Mexico_City'));
  $diff = (int)$hoy->diff($fin)->format('%r%a');

  if ($diff < 0) return 'Finalizada';
  if ($diff === 0) return 'Último día';
  return 'Faltan ' . $diff . ' días';
}

function cmpEjecutivoCampana($a, $b) {
  $u = ((int)$b['unidades']) <=> ((int)$a['unidades']);
  if ($u !== 0) return $u;

  $m = ((float)$b['monto']) <=> ((float)$a['monto']);
  if ($m !== 0) return $m;

  return strcmp((string)$a['empleado'], (string)$b['empleado']);
}

function cmpGerenteCampana($a, $b) {
  $m = ((float)$b['monto']) <=> ((float)$a['monto']);
  if ($m !== 0) return $m;

  $u = ((int)$b['unidades']) <=> ((int)$a['unidades']);
  if ($u !== 0) return $u;

  return strcmp((string)$a['empleado'], (string)$b['empleado']);
}

function badgeCumplimiento($cumplimiento) {
  $cumplimiento = (float)$cumplimiento;
  if ($cumplimiento >= 100) {
    return '<span class="badge rounded-pill text-bg-success"><i class="bi bi-check-circle-fill me-1"></i>Elegible</span>';
  }
  return '<span class="badge rounded-pill text-bg-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i>No elegible</span>';
}

/* ==========================================================
   DETECCIÓN DE COLUMNA TIPO PRODUCTO
========================================================== */

$colTipoProd = 'tipo_producto';
try {
  $rsTipo = $conn->query("SHOW COLUMNS FROM productos LIKE 'tipo'");
  if ($rsTipo && $rsTipo->num_rows > 0) {
    $colTipoProd = 'tipo';
  } else {
    $rsTipo2 = $conn->query("SHOW COLUMNS FROM productos LIKE 'tipo_producto'");
    if ($rsTipo2 && $rsTipo2->num_rows > 0) {
      $colTipoProd = 'tipo_producto';
    }
  }
} catch (Throwable $e) {
  $colTipoProd = 'tipo_producto';
}

/* ==========================================================
   FILTROS BASE
========================================================== */

$scopeSucursales = "
  s.tipo_sucursal = 'Tienda'
  AND s.activo = 1
  AND (
    s.propiedad IS NULL
    OR s.propiedad = ''
    OR LOWER(s.propiedad) = 'luga'
    OR LOWER(s.propiedad) = 'propia'
  )
  AND (s.id_subdis IS NULL OR s.id_subdis = 0)
";

$scopeUsuarios = "
  u.activo = 1
  AND LOWER(u.rol) NOT LIKE 'subdis%'
";

$scopeProductos = "
  LOWER(COALESCE(p.$colTipoProd,'')) = 'equipo'
  AND COALESCE(dv.es_combo,0) = 0
  AND COALESCE(dv.es_regalo,0) = 0
";

/* ==========================================================
   SUCURSALES Y CUMPLIMIENTO
   Base tomada del dashboard semanal: cuota de cuotas_sucursales
   y monto desde ventas.precio_venta por venta.
========================================================== */

$sqlSucursales = "
  SELECT
    s.id AS id_sucursal,
    s.nombre AS sucursal,
    s.zona,
    COALESCE((
      SELECT cs.cuota_monto
      FROM cuotas_sucursales cs
      WHERE cs.id_sucursal = s.id
        AND cs.fecha_inicio <= ?
      ORDER BY cs.fecha_inicio DESC
      LIMIT 1
    ), 0) AS cuota_semanal,
    COALESCE(SUM(vx.unidades), 0) AS unidades,
    COALESCE(SUM(vx.monto), 0) AS total_ventas
  FROM sucursales s
  LEFT JOIN (
    SELECT
      v.id,
      v.id_sucursal,
      COUNT(dv.id) AS unidades,
      v.precio_venta AS monto
    FROM ventas v
    INNER JOIN detalle_venta dv ON dv.id_venta = v.id
    INNER JOIN productos p ON p.id = dv.id_producto
    WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
      AND $scopeProductos
    GROUP BY v.id, v.id_sucursal, v.precio_venta
  ) vx ON vx.id_sucursal = s.id
  WHERE $scopeSucursales
  GROUP BY s.id, s.nombre, s.zona
  ORDER BY total_ventas DESC, sucursal ASC
";

$sucursales = [];
$sucursalMap = [];
$totalSucursales = 0;
$totalSucursalesElegibles = 0;
$totalUnidadesSucursales = 0;
$totalVentasGlobal = 0.0;
$totalCuotaGlobal = 0.0;

$stmtSuc = $conn->prepare($sqlSucursales);
if ($stmtSuc) {
  $stmtSuc->bind_param('sss', $FECHA_INICIO, $FECHA_INICIO, $FECHA_FIN);
  $stmtSuc->execute();
  $resSuc = $stmtSuc->get_result();

  while ($row = $resSuc->fetch_assoc()) {
    $row['id_sucursal'] = (int)$row['id_sucursal'];
    $row['cuota_semanal'] = (float)($row['cuota_semanal'] ?? 0);
    $row['unidades'] = (int)($row['unidades'] ?? 0);
    $row['total_ventas'] = (float)($row['total_ventas'] ?? 0);
    $row['cumplimiento'] = $row['cuota_semanal'] > 0 ? (($row['total_ventas'] / $row['cuota_semanal']) * 100) : 0;
    $row['elegible'] = $row['cumplimiento'] >= 100;

    $sucursales[] = $row;
    $sucursalMap[$row['id_sucursal']] = $row;

    $totalSucursales++;
    if ($row['elegible']) $totalSucursalesElegibles++;
    $totalUnidadesSucursales += $row['unidades'];
    $totalVentasGlobal += $row['total_ventas'];
    $totalCuotaGlobal += $row['cuota_semanal'];
  }

  $stmtSuc->close();
}

$porcentajeGlobal = $totalCuotaGlobal > 0 ? (($totalVentasGlobal / $totalCuotaGlobal) * 100) : 0;

/* ==========================================================
   EJECUTIVOS
   Ranking por ventas propias del ejecutivo.
   Ganador: mayor volumen, siempre que su sucursal cumpla >=100%.
========================================================== */

$sqlEjecutivos = "
  SELECT
    u.id AS id_usuario,
    u.nombre AS empleado,
    u.rol,
    s.id AS id_sucursal,
    s.nombre AS sucursal,
    s.zona,
    ue.foto,
    COUNT(dv.id) AS unidades,
    COALESCE(SUM(dv.precio_unitario), 0) AS monto
  FROM detalle_venta dv
  INNER JOIN ventas v ON v.id = dv.id_venta
  INNER JOIN productos p ON p.id = dv.id_producto
  INNER JOIN usuarios u ON u.id = v.id_usuario
  INNER JOIN sucursales s ON s.id = v.id_sucursal
  LEFT JOIN usuarios_expediente ue ON ue.usuario_id = u.id
  WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    AND $scopeProductos
    AND $scopeSucursales
    AND $scopeUsuarios
    AND u.rol = 'Ejecutivo'
  GROUP BY u.id, s.id
  ORDER BY unidades DESC, monto DESC, empleado ASC
";

$ejecutivos = [];
$totalUnidadesEjecutivos = 0;
$totalMontoEjecutivos = 0.0;

$stmtEj = $conn->prepare($sqlEjecutivos);
if ($stmtEj) {
  $stmtEj->bind_param('ss', $FECHA_INICIO, $FECHA_FIN);
  $stmtEj->execute();
  $resEj = $stmtEj->get_result();

  while ($row = $resEj->fetch_assoc()) {
    $idSucursal = (int)($row['id_sucursal'] ?? 0);
    $sInfo = $sucursalMap[$idSucursal] ?? null;

    $row['id_usuario'] = (int)$row['id_usuario'];
    $row['id_sucursal'] = $idSucursal;
    $row['unidades'] = (int)($row['unidades'] ?? 0);
    $row['monto'] = (float)($row['monto'] ?? 0);
    $row['foto_url'] = fotoEmpleado($row['foto'] ?? '');
    $row['cuota_sucursal'] = $sInfo ? (float)$sInfo['cuota_semanal'] : 0;
    $row['ventas_sucursal'] = $sInfo ? (float)$sInfo['total_ventas'] : 0;
    $row['cumplimiento_sucursal'] = $sInfo ? (float)$sInfo['cumplimiento'] : 0;
    $row['sucursal_elegible'] = $row['cumplimiento_sucursal'] >= 100;

    $ejecutivos[] = $row;
    $totalUnidadesEjecutivos += $row['unidades'];
    $totalMontoEjecutivos += $row['monto'];
  }

  $stmtEj->close();
}

usort($ejecutivos, 'cmpEjecutivoCampana');

$ganadorEjecutivo = null;
foreach ($ejecutivos as $row) {
  if ((int)$row['unidades'] > 0 && !empty($row['sucursal_elegible'])) {
    $ganadorEjecutivo = $row;
    break;
  }
}

/* ==========================================================
   GERENTES
   Ranking por ventas de la SUCURSAL asignada al gerente.
   Monto usa ventas.precio_venta para respetar descuentos/cupones.
   Ganador: mayor monto, siempre que su sucursal cumpla >=100%.
========================================================== */

$sqlGerentes = "
  SELECT
    u.id AS id_usuario,
    u.nombre AS empleado,
    u.rol,
    s.id AS id_sucursal,
    s.nombre AS sucursal,
    s.zona,
    ue.foto,
    COALESCE(SUM(vg.unidades), 0) AS unidades,
    COALESCE(SUM(vg.monto), 0) AS monto
  FROM usuarios u
  INNER JOIN sucursales s ON s.id = u.id_sucursal
  LEFT JOIN usuarios_expediente ue ON ue.usuario_id = u.id
  LEFT JOIN (
    SELECT
      v.id,
      v.id_sucursal,
      COUNT(dv.id) AS unidades,
      v.precio_venta AS monto
    FROM ventas v
    INNER JOIN detalle_venta dv ON dv.id_venta = v.id
    INNER JOIN productos p ON p.id = dv.id_producto
    WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
      AND $scopeProductos
    GROUP BY v.id, v.id_sucursal, v.precio_venta
  ) vg ON vg.id_sucursal = s.id
  WHERE $scopeSucursales
    AND $scopeUsuarios
    AND u.rol = 'Gerente'
  GROUP BY u.id, s.id
  ORDER BY monto DESC, unidades DESC, empleado ASC
";

$gerentes = [];
$totalUnidadesGerentes = 0;
$totalMontoGerentes = 0.0;

$stmtGer = $conn->prepare($sqlGerentes);
if ($stmtGer) {
  $stmtGer->bind_param('ss', $FECHA_INICIO, $FECHA_FIN);
  $stmtGer->execute();
  $resGer = $stmtGer->get_result();

  while ($row = $resGer->fetch_assoc()) {
    $idSucursal = (int)($row['id_sucursal'] ?? 0);
    $sInfo = $sucursalMap[$idSucursal] ?? null;

    $row['id_usuario'] = (int)$row['id_usuario'];
    $row['id_sucursal'] = $idSucursal;
    $row['unidades'] = (int)($row['unidades'] ?? 0);
    $row['monto'] = (float)($row['monto'] ?? 0);
    $row['foto_url'] = fotoEmpleado($row['foto'] ?? '');
    $row['cuota_sucursal'] = $sInfo ? (float)$sInfo['cuota_semanal'] : 0;
    $row['ventas_sucursal'] = $sInfo ? (float)$sInfo['total_ventas'] : 0;
    $row['cumplimiento_sucursal'] = $sInfo ? (float)$sInfo['cumplimiento'] : 0;
    $row['sucursal_elegible'] = $row['cumplimiento_sucursal'] >= 100;

    $gerentes[] = $row;
    $totalUnidadesGerentes += $row['unidades'];
    $totalMontoGerentes += $row['monto'];
  }

  $stmtGer->close();
}

usort($gerentes, 'cmpGerenteCampana');

$ganadorGerente = null;
foreach ($gerentes as $row) {
  if (((float)$row['monto'] > 0 || (int)$row['unidades'] > 0) && !empty($row['sucursal_elegible'])) {
    $ganadorGerente = $row;
    break;
  }
}

$ganadoresDefinidos = ($ganadorEjecutivo ? 1 : 0) + ($ganadorGerente ? 1 : 0);
$periodoTxt = date('d/m/Y', strtotime($FECHA_INICIO)) . ' al ' . date('d/m/Y', strtotime($FECHA_FIN));
$estadoCampana = diasRestantesCampana($FECHA_FIN);
$fechaFinCountdown = date('Y-m-d', strtotime($FECHA_FIN . ' +1 day')) . 'T00:00:00-06:00';

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Zentral | <?= h($CAMPANA_NOMBRE) ?></title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

  <style>
    :root{
      --z-bg:#07111f;
      --z-blue:#2563eb;
      --z-cyan:#06b6d4;
      --z-navy:#0f172a;
      --z-muted:#64748b;
      --z-border:rgba(15,23,42,.10);
      --z-card:#ffffff;
      --z-gold:#f59e0b;
      --z-gold-soft:#fff7ed;
      --z-green:#16a34a;
      --z-red:#dc2626;
      --z-radius:24px;
    }

    body.zentral-body{
      min-height:100vh;
      background:
        radial-gradient(circle at 10% 0%, rgba(37,99,235,.28), transparent 32%),
        radial-gradient(circle at 85% 8%, rgba(6,182,212,.22), transparent 28%),
        linear-gradient(180deg,#07111f 0%,#eef4fb 38%,#f8fafc 100%);
      color:#0f172a;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    .zentral-shell{ max-width:1500px; }

    .campaign-hero{
      position:relative;
      overflow:hidden;
      border-radius:32px;
      padding:30px;
      background:
        linear-gradient(135deg, rgba(15,23,42,.98), rgba(30,64,175,.95)),
        radial-gradient(circle at top right, rgba(6,182,212,.22), transparent 35%);
      box-shadow:0 28px 80px rgba(2,6,23,.32);
      border:1px solid rgba(255,255,255,.16);
    }

    .campaign-hero::before{
      content:"";
      position:absolute;
      right:-90px;
      top:-100px;
      width:360px;
      height:360px;
      border-radius:999px;
      background:rgba(6,182,212,.23);
      filter:blur(8px);
    }

    .campaign-hero::after{
      content:"";
      position:absolute;
      left:36%;
      bottom:-160px;
      width:460px;
      height:260px;
      border-radius:999px;
      background:rgba(245,158,11,.16);
      filter:blur(18px);
    }

    .logo-card{
      position:relative;
      z-index:2;
      width:82px;
      height:82px;
      border-radius:25px;
      background:rgba(255,255,255,.96);
      display:flex;
      align-items:center;
      justify-content:center;
      box-shadow:0 18px 42px rgba(0,0,0,.24);
      flex:0 0 auto;
    }

    .logo-card img{
      max-width:68px;
      max-height:68px;
      object-fit:contain;
    }

    .hero-content,.hero-prize{ position:relative; z-index:2; }

    .hero-kicker{
      display:inline-flex;
      align-items:center;
      gap:.45rem;
      padding:.36rem .75rem;
      border-radius:999px;
      background:rgba(255,255,255,.12);
      color:#bfdbfe;
      font-size:.78rem;
      font-weight:900;
      letter-spacing:.12em;
      text-transform:uppercase;
    }

    .hero-title{
      color:#fff;
      font-size:clamp(2.1rem,4.2vw,4.4rem);
      line-height:.96;
      letter-spacing:-.055em;
      font-weight:950;
      margin:14px 0 10px;
    }

    .hero-subtitle{
      color:rgba(255,255,255,.78);
      font-size:1.05rem;
      max-width:870px;
    }

    .hero-meta{
      display:flex;
      flex-wrap:wrap;
      gap:.65rem;
      margin-top:18px;
    }

    .hero-pill{
      display:inline-flex;
      align-items:center;
      gap:.5rem;
      padding:.68rem .9rem;
      border-radius:16px;
      background:rgba(255,255,255,.10);
      color:#fff;
      border:1px solid rgba(255,255,255,.14);
      backdrop-filter:blur(10px);
      font-weight:800;
    }

    .prize-card{
      background:rgba(255,255,255,.96);
      border-radius:28px;
      padding:20px;
      width:min(360px,100%);
      box-shadow:0 22px 60px rgba(0,0,0,.22);
      border:1px solid rgba(255,255,255,.6);
    }

    .prize-img{
      width:100%;
      height:210px;
      object-fit:contain;
      border-radius:22px;
      background:radial-gradient(circle at center, #ffffff, #e0f2fe);
      padding:14px;
    }

    .prize-badge{
      display:inline-flex;
      align-items:center;
      gap:.4rem;
      padding:.45rem .75rem;
      border-radius:999px;
      background:#fff7ed;
      color:#9a3412;
      font-size:.78rem;
      font-weight:900;
      margin-bottom:10px;
      border:1px solid #fed7aa;
    }

    .kpi-card{
      border:0;
      border-radius:24px;
      box-shadow:0 16px 42px rgba(15,23,42,.10);
      overflow:hidden;
      height:100%;
      background:#fff;
      transition: transform .18s ease, box-shadow .18s ease;
    }

    .kpi-card:hover{
      transform:translateY(-4px);
      box-shadow:0 24px 60px rgba(15,23,42,.16);
    }

    .kpi-card .card-body{ padding:22px; }

    .kpi-label{
      color:#64748b;
      font-weight:800;
      text-transform:uppercase;
      letter-spacing:.08em;
      font-size:.78rem;
    }

    .kpi-value{
      color:#0f172a;
      font-weight:950;
      letter-spacing:-.045em;
      font-size:2.2rem;
      line-height:1;
      margin-top:8px;
    }

    .section-title{
      font-weight:950;
      letter-spacing:-.04em;
      color:#0f172a;
    }

    .section-subtitle{
      color:#64748b;
      font-weight:600;
    }

    .winner-card{
      position:relative;
      border:0;
      border-radius:30px;
      overflow:hidden;
      background:#fff;
      box-shadow:0 22px 65px rgba(15,23,42,.14);
      height:100%;
    }

    .winner-card::before{
      content:"";
      position:absolute;
      inset:0 0 auto 0;
      height:8px;
      background:linear-gradient(90deg,var(--z-gold),#fde68a,var(--z-cyan));
    }

    .winner-card.no-winner::before{
      background:linear-gradient(90deg,#cbd5e1,#e2e8f0,#cbd5e1);
    }

    .winner-card .card-body{ padding:24px; }

    .winner-type-chip{
      display:inline-flex;
      align-items:center;
      gap:.38rem;
      padding:.42rem .72rem;
      border-radius:999px;
      background:#eff6ff;
      color:#1d4ed8;
      font-size:.78rem;
      font-weight:900;
      border:1px solid #bfdbfe;
    }

    .winner-photo-wrap{
      width:112px;
      height:112px;
      border-radius:32px;
      padding:4px;
      background:linear-gradient(135deg,#f59e0b,#fde68a,#06b6d4);
      flex:0 0 auto;
    }

    .winner-photo,.avatar-fallback{
      width:100%;
      height:100%;
      border-radius:28px;
      object-fit:cover;
      background:#e2e8f0;
      display:flex;
      align-items:center;
      justify-content:center;
      font-weight:950;
      font-size:1.75rem;
      color:#334155;
    }

    .no-winner-avatar{
      background:linear-gradient(135deg,#e2e8f0,#f8fafc);
      color:#64748b;
      border:1px dashed #cbd5e1;
    }

    .winner-name{
      font-size:1.55rem;
      line-height:1.08;
      font-weight:950;
      letter-spacing:-.04em;
      color:#0f172a;
    }

    .winner-branch{
      color:#64748b;
      font-weight:750;
      font-size:.96rem;
      margin-top:6px;
    }

    .metric-box{
      border-radius:20px;
      background:#f8fafc;
      border:1px solid #e2e8f0;
      padding:14px;
      height:100%;
    }

    .metric-label{
      font-size:.72rem;
      color:#64748b;
      font-weight:900;
      text-transform:uppercase;
      letter-spacing:.08em;
    }

    .metric-value{
      font-weight:950;
      font-size:1.55rem;
      letter-spacing:-.035em;
      color:#0f172a;
    }

    .condition-box{
      border-radius:22px;
      background:linear-gradient(135deg,#ecfdf5,#f0fdf4);
      border:1px solid #bbf7d0;
      padding:14px;
      color:#166534;
      font-weight:850;
    }

    .condition-box.warn{
      background:linear-gradient(135deg,#fff7ed,#fffbeb);
      border-color:#fed7aa;
      color:#9a3412;
    }

    .table-card{
      border:0;
      border-radius:24px;
      box-shadow:0 16px 45px rgba(15,23,42,.10);
      overflow:hidden;
    }

    .table-card .card-header{
      background:linear-gradient(135deg,#0f172a,#1d4ed8);
      color:#fff;
      border:0;
      font-weight:900;
      padding:16px 20px;
    }

    .table{
      margin:0;
      --bs-table-bg:transparent;
      border-color:#e2e8f0;
    }

    .table thead th{
      background:#0f172a !important;
      color:#e2e8f0 !important;
      border-color:#1e293b !important;
      font-size:.76rem;
      text-transform:uppercase;
      letter-spacing:.045em;
      white-space:nowrap;
    }

    .table td,.table th{ vertical-align:middle; }

    .rank-badge{
      width:34px;
      height:34px;
      border-radius:13px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-weight:950;
      background:#e2e8f0;
      color:#0f172a;
    }

    .rank-1{
      background:#fff7ed;
      color:#9a3412;
      border:1px solid #fdba74;
    }

    .mini-person{
      display:flex;
      align-items:center;
      gap:.65rem;
      min-width:220px;
    }

    .mini-photo,.mini-avatar{
      width:42px;
      height:42px;
      border-radius:15px;
      object-fit:cover;
      background:#e2e8f0;
      color:#334155;
      display:flex;
      align-items:center;
      justify-content:center;
      font-weight:950;
      flex:0 0 auto;
    }

    .mini-name{
      font-weight:900;
      line-height:1.05;
      max-width:260px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }

    .mini-sub{
      color:#64748b;
      font-size:.82rem;
      font-weight:700;
    }

    .winner-row{ background:#fff7ed !important; }
    .not-eligible-row{ opacity:.78; }

    .confetti-dot{
      position:absolute;
      width:9px;
      height:9px;
      border-radius:999px;
      background:#f59e0b;
      opacity:.72;
      animation:floatDot 4s ease-in-out infinite;
    }
    .dot-1{top:22px;right:20%;animation-delay:.1s;background:#06b6d4;}
    .dot-2{top:76px;right:8%;animation-delay:.5s;background:#f59e0b;}
    .dot-3{bottom:38px;right:28%;animation-delay:1s;background:#93c5fd;}
    .dot-4{bottom:88px;left:46%;animation-delay:1.5s;background:#fde68a;}

    @keyframes floatDot{
      0%,100%{ transform:translateY(0) scale(1); }
      50%{ transform:translateY(-14px) scale(1.15); }
    }

    .btn-zentral{
      border:0;
      border-radius:16px;
      padding:.72rem 1rem;
      background:linear-gradient(135deg,var(--z-blue),var(--z-cyan));
      color:#fff;
      font-weight:900;
      box-shadow:0 12px 28px rgba(37,99,235,.25);
    }

    .btn-zentral:hover{
      color:#fff;
      transform:translateY(-1px);
      box-shadow:0 16px 34px rgba(37,99,235,.32);
    }

    @media(max-width: 768px){
      .campaign-hero{ padding:22px; border-radius:26px; }
      .logo-card{ width:66px; height:66px; border-radius:20px; }
      .logo-card img{ max-width:54px; max-height:54px; }
      .hero-title{ font-size:2.25rem; }
      .winner-photo-wrap{ width:88px; height:88px; border-radius:26px; }
      .winner-photo,.avatar-fallback{ border-radius:22px; font-size:1.35rem; }
      .winner-name{ font-size:1.25rem; }
      .table{ font-size:.84rem; }
    }

    @media print{
      body.zentral-body{ background:#fff !important; }
      .btn,.no-print{ display:none !important; }
      .campaign-hero,.winner-card,.kpi-card,.table-card{ box-shadow:none !important; }
      .campaign-hero{ background:#0f172a !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    }
  </style>
</head>
<body class="zentral-body">

<?php if (file_exists(__DIR__ . '/navbar.php')): ?>
  <?php require_once __DIR__ . '/navbar.php'; ?>
<?php endif; ?>

<div class="container-fluid zentral-shell py-4 py-lg-5">

  <section class="campaign-hero mb-4">
    <span class="confetti-dot dot-1"></span>
    <span class="confetti-dot dot-2"></span>
    <span class="confetti-dot dot-3"></span>
    <span class="confetti-dot dot-4"></span>

    <div class="row g-4 align-items-center">
      <div class="col-12 col-lg-8 hero-content">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="logo-card">
            <img src="<?= h($ZENTRAL_LOGO) ?>" alt="Zentral">
          </div>
          <div>
            <div class="hero-kicker">
              <i class="bi bi-trophy-fill"></i>
              Concurso por empresa
            </div>
          </div>
        </div>

        <h1 class="hero-title"><?= h($CAMPANA_NOMBRE) ?></h1>
        <p class="hero-subtitle mb-0">
          <?= h($CAMPANA_SUBTITULO) ?>. Para poder ganar, la sucursal del participante debe llegar mínimo al <strong>100% de su cuota semanal</strong>.
        </p>

        <div class="hero-meta">
          <div class="hero-pill">
            <i class="bi bi-calendar-event"></i>
            <?= h($periodoTxt) ?>
          </div>
          <div class="hero-pill">
            <i class="bi bi-hourglass-split"></i>
            <span id="q6CountdownText"><?= h($estadoCampana) ?></span>
          </div>
          <div class="hero-pill">
            <i class="bi bi-gift-fill"></i>
            <?= (int)$totalPremios ?> premios
          </div>
          <div class="hero-pill">
            <i class="bi bi-check2-circle"></i>
            <?= (int)$totalSucursalesElegibles ?> sucursales al 100%
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-4 hero-prize">
        <div class="prize-card ms-lg-auto">
          <div class="prize-badge">
            <i class="bi bi-stars"></i>
            Premio de campaña
          </div>
          <img src="<?= h($RUTA_PREMIO) ?>" class="prize-img" alt="Compaq Q6">
          <div class="mt-3">
            <div class="fw-black h4 mb-1" style="font-weight:950; letter-spacing:-.04em;">Compaq Q6</div>
            <div class="text-muted fw-semibold small">
              1 para ejecutivo por volumen y 1 para gerente por monto de venta.
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
      <div class="kpi-card">
        <div class="card-body">
          <div class="kpi-label">Premios definidos</div>
          <div class="kpi-value"><?= (int)$ganadoresDefinidos ?>/<?= (int)$totalPremios ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="kpi-card">
        <div class="card-body">
          <div class="kpi-label">Unidades campaña</div>
          <div class="kpi-value"><?= number_format((int)$totalUnidadesSucursales) ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="kpi-card">
        <div class="card-body">
          <div class="kpi-label">Venta global</div>
          <div class="kpi-value" style="font-size:1.72rem;"><?= money($totalVentasGlobal) ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="kpi-card">
        <div class="card-body">
          <div class="kpi-label">Cumplimiento global</div>
          <div class="kpi-value"><?= pct($porcentajeGlobal) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-2 mb-3">
    <div>
      <h2 class="section-title mb-1">Ganadores parciales por empresa</h2>
      <div class="section-subtitle">
        El tablero solo marca ganador si su sucursal ya llegó al 100% de cuota. Si no, aparece en ranking pero no se lleva la corona todavía.
      </div>
    </div>

    <button class="btn btn-zentral no-print" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>
      Imprimir / guardar PDF
    </button>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-12 col-xl-6">
      <?php $sinEjecutivo = !$ganadorEjecutivo; ?>
      <div class="winner-card <?= $sinEjecutivo ? 'no-winner' : '' ?>">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <span class="winner-type-chip"><i class="bi bi-person-badge-fill"></i> Ejecutivo campeón</span>
            <?php if ($sinEjecutivo): ?>
              <span class="badge rounded-pill text-bg-secondary">Sin ganador elegible</span>
            <?php else: ?>
              <span class="badge rounded-pill text-bg-warning"><i class="bi bi-trophy-fill me-1"></i>Líder actual</span>
            <?php endif; ?>
          </div>

          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="winner-photo-wrap">
              <?php if (!$sinEjecutivo && !empty($ganadorEjecutivo['foto_url'])): ?>
                <img class="winner-photo" src="<?= h($ganadorEjecutivo['foto_url']) ?>" alt="<?= h($ganadorEjecutivo['empleado']) ?>">
              <?php elseif (!$sinEjecutivo): ?>
                <div class="avatar-fallback"><?= h(inicialesNombre($ganadorEjecutivo['empleado'])) ?></div>
              <?php else: ?>
                <div class="avatar-fallback no-winner-avatar"><i class="bi bi-hourglass-split"></i></div>
              <?php endif; ?>
            </div>

            <div class="min-w-0">
              <?php if ($sinEjecutivo): ?>
                <div class="winner-name text-muted">Aún no hay ejecutivo elegible</div>
                <div class="winner-branch text-muted">Debe existir venta y la sucursal debe alcanzar 100% de cuota.</div>
              <?php else: ?>
                <div class="winner-name"><?= h($ganadorEjecutivo['empleado']) ?></div>
                <div class="winner-branch"><i class="bi bi-shop me-1"></i><?= h($ganadorEjecutivo['sucursal']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6 col-lg-3">
              <div class="metric-box">
                <div class="metric-label">Unidades</div>
                <div class="metric-value"><?= $sinEjecutivo ? 0 : (int)$ganadorEjecutivo['unidades'] ?></div>
              </div>
            </div>
            <div class="col-6 col-lg-3">
              <div class="metric-box">
                <div class="metric-label">Monto propio</div>
                <div class="metric-value" style="font-size:1.02rem;"><?= $sinEjecutivo ? money(0) : money($ganadorEjecutivo['monto']) ?></div>
              </div>
            </div>
            <div class="col-6 col-lg-3">
              <div class="metric-box">
                <div class="metric-label">Venta sucursal</div>
                <div class="metric-value" style="font-size:1.02rem;"><?= $sinEjecutivo ? money(0) : money($ganadorEjecutivo['ventas_sucursal']) ?></div>
              </div>
            </div>
            <div class="col-6 col-lg-3">
              <div class="metric-box">
                <div class="metric-label">Cumplimiento</div>
                <div class="metric-value" style="font-size:1.28rem;"><?= $sinEjecutivo ? '0.0%' : pct($ganadorEjecutivo['cumplimiento_sucursal']) ?></div>
              </div>
            </div>
          </div>

          <div class="condition-box <?= $sinEjecutivo ? 'warn' : '' ?>">
            <i class="bi <?= $sinEjecutivo ? 'bi-info-circle-fill' : 'bi-check-circle-fill' ?> me-1"></i>
            <?= $sinEjecutivo ? 'La corona se activa hasta que el mejor ejecutivo pertenezca a una sucursal con mínimo 100% de cuota.' : 'Cumple la condición: su sucursal ya está al 100% o más.' ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-6">
      <?php $sinGerente = !$ganadorGerente; ?>
      <div class="winner-card <?= $sinGerente ? 'no-winner' : '' ?>">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <span class="winner-type-chip"><i class="bi bi-briefcase-fill"></i> Gerente campeón</span>
            <?php if ($sinGerente): ?>
              <span class="badge rounded-pill text-bg-secondary">Sin ganador elegible</span>
            <?php else: ?>
              <span class="badge rounded-pill text-bg-warning"><i class="bi bi-trophy-fill me-1"></i>Líder actual</span>
            <?php endif; ?>
          </div>

          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="winner-photo-wrap">
              <?php if (!$sinGerente && !empty($ganadorGerente['foto_url'])): ?>
                <img class="winner-photo" src="<?= h($ganadorGerente['foto_url']) ?>" alt="<?= h($ganadorGerente['empleado']) ?>">
              <?php elseif (!$sinGerente): ?>
                <div class="avatar-fallback"><?= h(inicialesNombre($ganadorGerente['empleado'])) ?></div>
              <?php else: ?>
                <div class="avatar-fallback no-winner-avatar"><i class="bi bi-hourglass-split"></i></div>
              <?php endif; ?>
            </div>

            <div class="min-w-0">
              <?php if ($sinGerente): ?>
                <div class="winner-name text-muted">Aún no hay gerente elegible</div>
                <div class="winner-branch text-muted">Debe existir venta y su sucursal debe alcanzar 100% de cuota.</div>
              <?php else: ?>
                <div class="winner-name"><?= h($ganadorGerente['empleado']) ?></div>
                <div class="winner-branch"><i class="bi bi-shop me-1"></i><?= h($ganadorGerente['sucursal']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6 col-lg-3">
              <div class="metric-box">
                <div class="metric-label">Monto sucursal</div>
                <div class="metric-value" style="font-size:1.02rem;"><?= $sinGerente ? money(0) : money($ganadorGerente['monto']) ?></div>
              </div>
            </div>
            <div class="col-6 col-lg-3">
              <div class="metric-box">
                <div class="metric-label">Unidades</div>
                <div class="metric-value"><?= $sinGerente ? 0 : (int)$ganadorGerente['unidades'] ?></div>
              </div>
            </div>
            <div class="col-6 col-lg-3">
              <div class="metric-box">
                <div class="metric-label">Cuota</div>
                <div class="metric-value" style="font-size:1.02rem;"><?= $sinGerente ? money(0) : money($ganadorGerente['cuota_sucursal']) ?></div>
              </div>
            </div>
            <div class="col-6 col-lg-3">
              <div class="metric-box">
                <div class="metric-label">Cumplimiento</div>
                <div class="metric-value" style="font-size:1.28rem;"><?= $sinGerente ? '0.0%' : pct($ganadorGerente['cumplimiento_sucursal']) ?></div>
              </div>
            </div>
          </div>

          <div class="condition-box <?= $sinGerente ? 'warn' : '' ?>">
            <i class="bi <?= $sinGerente ? 'bi-info-circle-fill' : 'bi-check-circle-fill' ?> me-1"></i>
            <?= $sinGerente ? 'La corona se activa hasta que el mejor gerente pertenezca a una sucursal con mínimo 100% de cuota.' : 'Cumple la condición: su sucursal ya está al 100% o más.' ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-12 col-xl-6">
      <div class="card table-card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center gap-2">
          <span><i class="bi bi-person-badge-fill me-1"></i>Ranking ejecutivos por volumen</span>
          <span class="badge text-bg-light"><?= count($ejecutivos) ?> participantes</span>
        </div>
        <div class="table-responsive">
          <table class="table table-hover table-striped align-middle">
            <thead>
              <tr>
                <th>#</th>
                <th>Ejecutivo</th>
                <th>Sucursal</th>
                <th class="text-end">Unidades</th>
                <th class="text-end">Monto</th>
                <th class="text-end">Cumpl.</th>
                <th>Estatus</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($ejecutivos)): ?>
                <tr><td colspan="7" class="text-center text-muted fw-bold py-4">Sin ventas registradas en el periodo.</td></tr>
              <?php else: ?>
                <?php foreach ($ejecutivos as $idx => $r): ?>
                  <?php
                    $isWinner = $ganadorEjecutivo && (int)$ganadorEjecutivo['id_usuario'] === (int)$r['id_usuario'] && (int)$ganadorEjecutivo['id_sucursal'] === (int)$r['id_sucursal'];
                    $eligible = !empty($r['sucursal_elegible']);
                  ?>
                  <tr class="<?= $isWinner ? 'winner-row' : (!$eligible ? 'not-eligible-row' : '') ?>">
                    <td><span class="rank-badge <?= $idx === 0 ? 'rank-1' : '' ?>"><?= (int)($idx + 1) ?></span></td>
                    <td>
                      <div class="mini-person">
                        <?php if (!empty($r['foto_url'])): ?>
                          <img class="mini-photo" src="<?= h($r['foto_url']) ?>" alt="<?= h($r['empleado']) ?>">
                        <?php else: ?>
                          <div class="mini-avatar"><?= h(inicialesNombre($r['empleado'])) ?></div>
                        <?php endif; ?>
                        <div>
                          <div class="mini-name"><?= h($r['empleado']) ?></div>
                          <div class="mini-sub"><?= $isWinner ? 'Ganador parcial' : 'Participante' ?></div>
                        </div>
                      </div>
                    </td>
                    <td><?= h($r['sucursal']) ?></td>
                    <td class="text-end fw-black" style="font-weight:950;"><?= (int)$r['unidades'] ?></td>
                    <td class="text-end fw-bold"><?= money($r['monto']) ?></td>
                    <td class="text-end fw-bold"><?= pct($r['cumplimiento_sucursal']) ?></td>
                    <td><?= badgeCumplimiento($r['cumplimiento_sucursal']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-6">
      <div class="card table-card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center gap-2">
          <span><i class="bi bi-briefcase-fill me-1"></i>Ranking gerentes por monto</span>
          <span class="badge text-bg-light"><?= count($gerentes) ?> participantes</span>
        </div>
        <div class="table-responsive">
          <table class="table table-hover table-striped align-middle">
            <thead>
              <tr>
                <th>#</th>
                <th>Gerente</th>
                <th>Sucursal</th>
                <th class="text-end">Monto</th>
                <th class="text-end">Unidades</th>
                <th class="text-end">Cumpl.</th>
                <th>Estatus</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($gerentes)): ?>
                <tr><td colspan="7" class="text-center text-muted fw-bold py-4">Sin gerentes o ventas registradas en el periodo.</td></tr>
              <?php else: ?>
                <?php foreach ($gerentes as $idx => $r): ?>
                  <?php
                    $isWinner = $ganadorGerente && (int)$ganadorGerente['id_usuario'] === (int)$r['id_usuario'] && (int)$ganadorGerente['id_sucursal'] === (int)$r['id_sucursal'];
                    $eligible = !empty($r['sucursal_elegible']);
                  ?>
                  <tr class="<?= $isWinner ? 'winner-row' : (!$eligible ? 'not-eligible-row' : '') ?>">
                    <td><span class="rank-badge <?= $idx === 0 ? 'rank-1' : '' ?>"><?= (int)($idx + 1) ?></span></td>
                    <td>
                      <div class="mini-person">
                        <?php if (!empty($r['foto_url'])): ?>
                          <img class="mini-photo" src="<?= h($r['foto_url']) ?>" alt="<?= h($r['empleado']) ?>">
                        <?php else: ?>
                          <div class="mini-avatar"><?= h(inicialesNombre($r['empleado'])) ?></div>
                        <?php endif; ?>
                        <div>
                          <div class="mini-name"><?= h($r['empleado']) ?></div>
                          <div class="mini-sub"><?= $isWinner ? 'Ganador parcial' : 'Participante' ?></div>
                        </div>
                      </div>
                    </td>
                    <td><?= h($r['sucursal']) ?></td>
                    <td class="text-end fw-black" style="font-weight:950;"><?= money($r['monto']) ?></td>
                    <td class="text-end fw-bold"><?= (int)$r['unidades'] ?></td>
                    <td class="text-end fw-bold"><?= pct($r['cumplimiento_sucursal']) ?></td>
                    <td><?= badgeCumplimiento($r['cumplimiento_sucursal']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="card table-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center gap-2">
      <span><i class="bi bi-shop-window me-1"></i>Cumplimiento por sucursal</span>
      <span class="badge text-bg-light"><?= (int)$totalSucursalesElegibles ?>/<?= (int)$totalSucursales ?> al 100%</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover table-striped align-middle">
        <thead>
          <tr>
            <th>Sucursal</th>
            <th>Zona</th>
            <th class="text-end">Unidades</th>
            <th class="text-end">Venta</th>
            <th class="text-end">Cuota</th>
            <th class="text-end">Cumplimiento</th>
            <th>Estatus</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sucursales)): ?>
            <tr><td colspan="7" class="text-center text-muted fw-bold py-4">No hay sucursales activas para mostrar.</td></tr>
          <?php else: ?>
            <?php foreach ($sucursales as $s): ?>
              <tr>
                <td class="fw-bold"><?= h($s['sucursal']) ?></td>
                <td><?= h($s['zona'] ?? 'Sin zona') ?></td>
                <td class="text-end fw-bold"><?= (int)$s['unidades'] ?></td>
                <td class="text-end fw-bold"><?= money($s['total_ventas']) ?></td>
                <td class="text-end"><?= money($s['cuota_semanal']) ?></td>
                <td class="text-end fw-bold"><?= pct($s['cumplimiento']) ?></td>
                <td><?= badgeCumplimiento($s['cumplimiento']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="text-center text-muted small fw-semibold py-3">
    Zentral | Campaña Compaq Q6 | Periodo <?= h($periodoTxt) ?>
  </div>

</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
document.addEventListener('DOMContentLoaded', function(){
  const countdownEl = document.getElementById('q6CountdownText');
  if (!countdownEl) return;

  const fechaFin = new Date('<?= h($fechaFinCountdown) ?>').getTime();

  function actualizarCountdown(){
    const ahora = new Date().getTime();
    const distancia = fechaFin - ahora;

    if (distancia <= 0) {
      countdownEl.textContent = 'FINALIZADO';
      return;
    }

    const dias = Math.floor(distancia / (1000 * 60 * 60 * 24));
    const horas = Math.floor((distancia % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutos = Math.floor((distancia % (1000 * 60 * 60)) / (1000 * 60));

    if (dias > 0) {
      countdownEl.textContent = dias + 'd ' + horas + 'h restantes';
    } else {
      countdownEl.textContent = horas + 'h ' + minutos + 'm restantes';
    }
  }

  actualizarCountdown();
  setInterval(actualizarCountdown, 60000);
});
</script>

</body>
</html>
