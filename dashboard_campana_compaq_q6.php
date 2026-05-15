<?php
/**
 * dashboard_campana_compaq_q6.php
 * Tablero de campaña Compaq Q6
 *
 * Compatible Luga / Nano si comparten estructura:
 * ventas, detalle_venta, productos, usuarios, usuarios_expediente, sucursales.
 *
 * Reglas:
 * - Gana 1 Ejecutivo por zona por volumen de equipos.
 * - Gana 1 Gerente por zona por monto de venta.
 * - Solo se cuentan equipos.
 * - No se cuentan combos: detalle_venta.es_combo = 0.
 * - No se cuentan regalos: detalle_venta.es_regalo = 0.
 * - No se cuentan subdis / roles Subdis_*.
 * - Fechas editables en variables.
 */

session_start();

if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');

/* ==========================================================
   CONFIGURACIÓN EDITABLE
========================================================== */

$CAMPANA_NOMBRE       = 'Campaña Compaq Q6';
$CAMPANA_SUBTITULO    = 'Los mejores de cada zona se llevan un Compaq Q6 de regalo';
$FECHA_INICIO         = '2026-05-01';
$FECHA_FIN            = '2026-05-11';
$RUTA_PREMIO          = 'img/compaq_q6.webp';
$ZENTRAL_LOGO         = 'img/Logo_Zentral.png';

/**
 * Si quieres mostrar un nombre distinto por central:
 * Luga: 'Luga'
 * Nano: 'Nano'
 */
$EMPRESA_NOMBRE = 'Zentral';

/**
 * Roles permitidos para ver el tablero.
 */
$ROLES_PERMITIDOS = [
  'Admin',
  'Logistica',
  'Sistemas',
  'Supervisor',
  'GerenteZona',
  'Gerente',
  'Ejecutivo'
];

/* ==========================================================
   SEGURIDAD / HELPERS
========================================================== */

$rolSesion = (string)($_SESSION['rol'] ?? '');

if (!in_array($rolSesion, $ROLES_PERMITIDOS, true)) {
  header("Location: 403.php");
  exit();
}

function h($v) {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v) {
  return '$' . number_format((float)$v, 2);
}

function normalizarZonaCampana($raw) {
  $t = trim((string)$raw);
  if ($t === '') return 'Sin zona';

  $t = preg_replace('/\s+/', ' ', $t);

  if (preg_match('/^Zona\s+/i', $t)) {
    $partes = explode(' ', strtolower($t));
    $partes = array_map('ucfirst', $partes);
    return implode(' ', $partes);
  }

  return 'Zona ' . ucfirst(strtolower($t));
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

  // Si viene URL absoluta
  if (preg_match('/^https?:\/\//i', $foto)) return $foto;

  // Si viene ruta relativa ya usable
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

/* ==========================================================
   FILTROS BASE
========================================================== */

/**
 * Exclusión de subdis:
 * - sucursales propiedad Subdistribuidor
 * - sucursales con id_subdis
 * - usuarios con rol Subdis_*
 */
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

/**
 * Solo equipos no combo y no regalo.
 */
$scopeProductos = "
  LOWER(COALESCE(p.tipo_producto,'')) = 'equipo'
  AND COALESCE(dv.es_combo,0) = 0
  AND COALESCE(dv.es_regalo,0) = 0
";

/* ==========================================================
   DATOS: EJECUTIVOS POR ZONA
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
  WHERE DATE(v.fecha_venta) BETWEEN ? AND ?
    AND $scopeProductos
    AND $scopeSucursales
    AND $scopeUsuarios
    AND u.rol = 'Ejecutivo'
  GROUP BY u.id, s.zona
  ORDER BY s.zona ASC, unidades DESC, monto DESC, empleado ASC
";

$ejecutivos = [];
$ganadoresEjecutivos = [];
$totalUnidadesEjecutivos = 0;

$stmt = $conn->prepare($sqlEjecutivos);
if ($stmt) {
  $stmt->bind_param("ss", $FECHA_INICIO, $FECHA_FIN);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($row = $res->fetch_assoc()) {
    $row['zona_norm'] = normalizarZonaCampana($row['zona'] ?? '');
    $row['unidades'] = (int)$row['unidades'];
    $row['monto'] = (float)$row['monto'];
    $row['foto_url'] = fotoEmpleado($row['foto'] ?? '');

    $ejecutivos[] = $row;
    $totalUnidadesEjecutivos += (int)$row['unidades'];

    $z = $row['zona_norm'];
    if (!isset($ganadoresEjecutivos[$z])) {
      $ganadoresEjecutivos[$z] = $row;
    }
  }

  $stmt->close();
}

/* ==========================================================
   DATOS: GERENTES POR ZONA
   Nota: se mide el monto de la sucursal asignada al gerente.
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
    COUNT(dv.id) AS unidades,
    COALESCE(SUM(dv.precio_unitario), 0) AS monto
  FROM usuarios u
  INNER JOIN sucursales s ON s.id = u.id_sucursal
  LEFT JOIN usuarios_expediente ue ON ue.usuario_id = u.id
  LEFT JOIN ventas v
    ON v.id_sucursal = s.id
    AND DATE(v.fecha_venta) BETWEEN ? AND ?
  LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
  LEFT JOIN productos p ON p.id = dv.id_producto
  WHERE $scopeSucursales
    AND $scopeUsuarios
    AND u.rol = 'Gerente'
    AND (
      dv.id IS NULL
      OR ($scopeProductos)
    )
  GROUP BY u.id, s.zona
  ORDER BY s.zona ASC, monto DESC, unidades DESC, empleado ASC
";

$gerentes = [];
$ganadoresGerentes = [];
$totalMontoGerentes = 0.0;

$stmt = $conn->prepare($sqlGerentes);
if ($stmt) {
  $stmt->bind_param("ss", $FECHA_INICIO, $FECHA_FIN);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($row = $res->fetch_assoc()) {
    $row['zona_norm'] = normalizarZonaCampana($row['zona'] ?? '');
    $row['unidades'] = (int)($row['unidades'] ?? 0);
    $row['monto'] = (float)($row['monto'] ?? 0);
    $row['foto_url'] = fotoEmpleado($row['foto'] ?? '');

    $gerentes[] = $row;
    $totalMontoGerentes += (float)$row['monto'];

    $z = $row['zona_norm'];
    if (!isset($ganadoresGerentes[$z])) {
      $ganadoresGerentes[$z] = $row;
    }
  }

  $stmt->close();
}

/* ==========================================================
   ZONAS DISPONIBLES
========================================================== */

$zonas = [];

foreach ($ejecutivos as $r) {
  $zonas[$r['zona_norm']] = true;
}
foreach ($gerentes as $r) {
  $zonas[$r['zona_norm']] = true;
}

$zonas = array_keys($zonas);
sort($zonas, SORT_NATURAL | SORT_FLAG_CASE);

$totalZonas = count($zonas);
$totalPremios = ($totalZonas * 2);

/* ==========================================================
   DATOS PARA TABLAS AGRUPADAS
========================================================== */

$ejecutivosPorZona = [];
foreach ($ejecutivos as $r) {
  $ejecutivosPorZona[$r['zona_norm']][] = $r;
}

$gerentesPorZona = [];
foreach ($gerentes as $r) {
  $gerentesPorZona[$r['zona_norm']][] = $r;
}

$periodoTxt = date('d/m/Y', strtotime($FECHA_INICIO)) . ' al ' . date('d/m/Y', strtotime($FECHA_FIN));
$estadoCampana = diasRestantesCampana($FECHA_FIN);

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

    .zentral-shell{
      max-width:1500px;
    }

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

    .hero-content,
    .hero-prize{
      position:relative;
      z-index:2;
    }

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
      font-size:clamp(2.2rem,4.4vw,4.6rem);
      line-height:.96;
      letter-spacing:-.055em;
      font-weight:950;
      margin:14px 0 10px;
    }

    .hero-subtitle{
      color:rgba(255,255,255,.76);
      font-size:1.05rem;
      max-width:850px;
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
      background:
        radial-gradient(circle at center, #ffffff, #e0f2fe);
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
    }

    .kpi-card .card-body{
      padding:22px;
    }

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
      border-radius:28px;
      overflow:hidden;
      background:#fff;
      box-shadow:0 18px 55px rgba(15,23,42,.12);
      height:100%;
    }

    .winner-card::before{
      content:"";
      position:absolute;
      inset:0 0 auto 0;
      height:7px;
      background:linear-gradient(90deg,var(--z-gold),#fde68a,var(--z-cyan));
    }

    .winner-card .card-body{
      padding:22px;
    }

    .zone-chip{
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      padding:.38rem .7rem;
      border-radius:999px;
      background:#eff6ff;
      color:#1d4ed8;
      font-size:.78rem;
      font-weight:900;
      border:1px solid #bfdbfe;
    }

    .winner-photo-wrap{
      width:94px;
      height:94px;
      border-radius:28px;
      padding:4px;
      background:linear-gradient(135deg,#f59e0b,#fde68a,#06b6d4);
      flex:0 0 auto;
    }

    .winner-photo,
    .avatar-fallback{
      width:100%;
      height:100%;
      border-radius:24px;
      object-fit:cover;
      background:#e2e8f0;
      display:flex;
      align-items:center;
      justify-content:center;
      font-weight:950;
      font-size:1.5rem;
      color:#334155;
    }

    .winner-name{
      font-size:1.25rem;
      line-height:1.1;
      font-weight:950;
      letter-spacing:-.03em;
      color:#0f172a;
    }

    .winner-branch{
      color:#64748b;
      font-weight:700;
      font-size:.92rem;
      margin-top:4px;
    }

    .metric-box{
      border-radius:20px;
      background:#f8fafc;
      border:1px solid #e2e8f0;
      padding:14px;
      height:100%;
    }

    .metric-label{
      font-size:.74rem;
      color:#64748b;
      font-weight:900;
      text-transform:uppercase;
      letter-spacing:.08em;
    }

    .metric-value{
      font-weight:950;
      font-size:1.6rem;
      letter-spacing:-.035em;
      color:#0f172a;
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

    .table td,
    .table th{
      vertical-align:middle;
    }

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

    .mini-photo,
    .mini-avatar{
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

    .winner-row{
      background:#fff7ed !important;
    }

    .nav-tabs{
      border:0;
      gap:.5rem;
      background:rgba(255,255,255,.78);
      border:1px solid rgba(15,23,42,.10);
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
      background:linear-gradient(135deg,var(--z-blue),var(--z-cyan)) !important;
      box-shadow:0 12px 26px rgba(37,99,235,.30);
    }

    .empty-state{
      border-radius:24px;
      padding:32px;
      background:#fff;
      border:1px dashed #cbd5e1;
      text-align:center;
      color:#64748b;
      font-weight:700;
    }

    .confetti-dot{
      position:absolute;
      width:9px;
      height:9px;
      border-radius:999px;
      background:#f59e0b;
      opacity:.7;
    }

    .dot-1{ right:18%; top:20%; background:#06b6d4; }
    .dot-2{ right:28%; bottom:22%; background:#f59e0b; }
    .dot-3{ left:52%; top:22%; background:#22c55e; }

    @media(max-width: 992px){
      .prize-card{
        width:100%;
      }

      .prize-img{
        height:180px;
      }
    }

    @media(max-width: 576px){
      .campaign-hero{
        padding:22px;
        border-radius:24px;
      }

      .logo-card{
        width:68px;
        height:68px;
        border-radius:20px;
      }

      .logo-card img{
        max-width:56px;
        max-height:56px;
      }

      .hero-title{
        font-size:2.35rem;
      }

      .hero-pill{
        width:100%;
        justify-content:center;
      }

      .winner-photo-wrap{
        width:78px;
        height:78px;
        border-radius:23px;
      }

      .winner-photo,
      .avatar-fallback{
        border-radius:20px;
      }

      .mini-person{
        min-width:180px;
      }
    }

    @media print{
      body.zentral-body{
        background:#fff !important;
      }

      .navbar,
      .nav-tabs,
      .btn{
        display:none !important;
      }

      .campaign-hero,
      .card,
      .winner-card,
      .kpi-card{
        box-shadow:none !important;
      }
    }
  </style>
</head>

<body class="zentral-body">

<?php
if (file_exists(__DIR__ . '/navbar.php')) {
  include __DIR__ . '/navbar.php';
}
?>

<div class="container-fluid zentral-shell px-3 px-lg-4 py-4">

  <section class="campaign-hero mb-4">
    <span class="confetti-dot dot-1"></span>
    <span class="confetti-dot dot-2"></span>
    <span class="confetti-dot dot-3"></span>

    <div class="row g-4 align-items-center">
      <div class="col-12 col-lg-8 hero-content">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="logo-card">
            <img src="<?= h($ZENTRAL_LOGO) ?>" alt="Zentral">
          </div>
          <div>
            <span class="hero-kicker">
              <i class="bi bi-trophy-fill"></i>
              <?= h($EMPRESA_NOMBRE) ?>
            </span>
          </div>
        </div>

        <h1 class="hero-title"><?= h($CAMPANA_NOMBRE) ?></h1>
        <p class="hero-subtitle mb-0">
          <?= h($CAMPANA_SUBTITULO) ?>. Ranking actualizado con ventas de equipos no combo por zona.
        </p>

        <div class="hero-meta">
          <div class="hero-pill">
            <i class="bi bi-calendar-event"></i>
            <?= h($periodoTxt) ?>
          </div>
          <div class="hero-pill">
            <i class="bi bi-hourglass-split"></i>
            <?= h($estadoCampana) ?>
          </div>
          <div class="hero-pill">
            <i class="bi bi-gift-fill"></i>
            <?= (int)$totalPremios ?> premios estimados
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-4 hero-prize">
        <div class="prize-card ms-lg-auto">
          <div class="prize-badge">
            <i class="bi bi-stars"></i>
            Premio por zona
          </div>
          <img src="<?= h($RUTA_PREMIO) ?>" class="prize-img" alt="Compaq Q6">
          <div class="mt-3">
            <div class="fw-black h4 mb-1" style="font-weight:950; letter-spacing:-.04em;">Compaq Q6</div>
            <div class="text-muted fw-semibold small">
              Para el mejor ejecutivo por volumen y el mejor gerente por monto.
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <div class="row g-3 mb-4">
    <div class="col-12 col-md-3">
      <div class="kpi-card">
        <div class="card-body">
          <div class="kpi-label">Zonas en competencia</div>
          <div class="kpi-value"><?= (int)$totalZonas ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-3">
      <div class="kpi-card">
        <div class="card-body">
          <div class="kpi-label">Unidades ejecutivos</div>
          <div class="kpi-value"><?= number_format((int)$totalUnidadesEjecutivos) ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-3">
      <div class="kpi-card">
        <div class="card-body">
          <div class="kpi-label">Monto gerentes</div>
          <div class="kpi-value" style="font-size:1.75rem;"><?= money($totalMontoGerentes) ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-3">
      <div class="kpi-card">
        <div class="card-body">
          <div class="kpi-label">Premios</div>
          <div class="kpi-value"><?= (int)$totalPremios ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-2 mb-3">
    <div>
      <h2 class="section-title mb-1">Ganadores parciales por zona</h2>
      <div class="section-subtitle">
        Foto, sucursal, zona y métrica principal para encender el tablero.
      </div>
    </div>

    <button class="btn btn-primary" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>
      Imprimir / guardar PDF
    </button>
  </div>

  <ul class="nav nav-tabs mb-4" id="campanaTabs">
    <li class="nav-item">
      <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabEjecutivos" type="button">
        <i class="bi bi-person-badge me-1"></i>
        Ejecutivos
      </button>
    </li>
    <li class="nav-item">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabGerentes" type="button">
        <i class="bi bi-briefcase me-1"></i>
        Gerentes
      </button>
    </li>
  </ul>

  <div class="tab-content">

    <!-- TAB EJECUTIVOS -->
    <div class="tab-pane fade show active" id="tabEjecutivos">

      <?php if (empty($ganadoresEjecutivos)): ?>
        <div class="empty-state mb-4">
          <div class="h4 fw-bold">Aún no hay ventas de equipos para ejecutivos.</div>
          <div>Cuando empiecen a registrar ventas, el tablero cobrará vida.</div>
        </div>
      <?php else: ?>
        <div class="row g-4 mb-4">
          <?php foreach ($zonas as $zona): ?>
            <?php if (!isset($ganadoresEjecutivos[$zona])) continue; ?>
            <?php $w = $ganadoresEjecutivos[$zona]; ?>
            <div class="col-12 col-md-6 col-xl-4">
              <div class="winner-card">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <span class="zone-chip"><i class="bi bi-geo-alt-fill"></i><?= h($zona) ?></span>
                    <span class="badge rounded-pill text-bg-warning">
                      <i class="bi bi-trophy-fill"></i> Líder
                    </span>
                  </div>

                  <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="winner-photo-wrap">
                      <?php if (!empty($w['foto_url'])): ?>
                        <img class="winner-photo" src="<?= h($w['foto_url']) ?>" alt="<?= h($w['empleado']) ?>">
                      <?php else: ?>
                        <div class="avatar-fallback"><?= h(inicialesNombre($w['empleado'])) ?></div>
                      <?php endif; ?>
                    </div>

                    <div class="min-w-0">
                      <div class="winner-name"><?= h($w['empleado']) ?></div>
                      <div class="winner-branch">
                        <i class="bi bi-shop me-1"></i><?= h($w['sucursal']) ?>
                      </div>
                    </div>
                  </div>

                  <div class="row g-2">
                    <div class="col-6">
                      <div class="metric-box">
                        <div class="metric-label">Unidades</div>
                        <div class="metric-value"><?= (int)$w['unidades'] ?></div>
                      </div>
                    </div>
                    <div class="col-6">
                      <div class="metric-box">
                        <div class="metric-label">Monto</div>
                        <div class="metric-value" style="font-size:1.15rem;"><?= money($w['monto']) ?></div>
                      </div>
                    </div>
                  </div>

                  <div class="mt-3 small fw-bold text-muted">
                    <i class="bi bi-gift-fill text-warning me-1"></i>
                    Va ganando el Compaq Q6 por volumen.
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php foreach ($zonas as $zona): ?>
        <?php $rows = $ejecutivosPorZona[$zona] ?? []; ?>
        <?php if (empty($rows)) continue; ?>

        <div class="card table-card mb-4">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-geo-alt-fill me-1"></i><?= h($zona) ?> | Ranking ejecutivos</span>
            <span class="badge text-bg-light"><?= count($rows) ?> participantes</span>
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
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php $pos = 1; foreach ($rows as $r): ?>
                  <tr class="<?= $pos === 1 ? 'winner-row' : '' ?>">
                    <td>
                      <span class="rank-badge <?= $pos === 1 ? 'rank-1' : '' ?>">
                        <?= $pos === 1 ? '🏆' : $pos ?>
                      </span>
                    </td>
                    <td>
                      <div class="mini-person">
                        <?php if (!empty($r['foto_url'])): ?>
                          <img class="mini-photo" src="<?= h($r['foto_url']) ?>" alt="<?= h($r['empleado']) ?>">
                        <?php else: ?>
                          <div class="mini-avatar"><?= h(inicialesNombre($r['empleado'])) ?></div>
                        <?php endif; ?>
                        <div>
                          <div class="mini-name"><?= h($r['empleado']) ?></div>
                          <div class="mini-sub"><?= h($r['rol']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td><?= h($r['sucursal']) ?></td>
                    <td class="text-end fw-black" style="font-weight:950;"><?= (int)$r['unidades'] ?></td>
                    <td class="text-end"><?= money($r['monto']) ?></td>
                    <td>
                      <?php if ($pos === 1): ?>
                        <span class="badge rounded-pill text-bg-warning">Ganador parcial</span>
                      <?php else: ?>
                        <span class="badge rounded-pill text-bg-secondary">En competencia</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php $pos++; endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>

    </div>

    <!-- TAB GERENTES -->
    <div class="tab-pane fade" id="tabGerentes">

      <?php if (empty($ganadoresGerentes)): ?>
        <div class="empty-state mb-4">
          <div class="h4 fw-bold">Aún no hay datos para gerentes.</div>
          <div>Cuando las sucursales comiencen a mover venta, aparecerán los líderes por zona.</div>
        </div>
      <?php else: ?>
        <div class="row g-4 mb-4">
          <?php foreach ($zonas as $zona): ?>
            <?php if (!isset($ganadoresGerentes[$zona])) continue; ?>
            <?php $w = $ganadoresGerentes[$zona]; ?>
            <div class="col-12 col-md-6 col-xl-4">
              <div class="winner-card">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <span class="zone-chip"><i class="bi bi-geo-alt-fill"></i><?= h($zona) ?></span>
                    <span class="badge rounded-pill text-bg-warning">
                      <i class="bi bi-trophy-fill"></i> Líder
                    </span>
                  </div>

                  <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="winner-photo-wrap">
                      <?php if (!empty($w['foto_url'])): ?>
                        <img class="winner-photo" src="<?= h($w['foto_url']) ?>" alt="<?= h($w['empleado']) ?>">
                      <?php else: ?>
                        <div class="avatar-fallback"><?= h(inicialesNombre($w['empleado'])) ?></div>
                      <?php endif; ?>
                    </div>

                    <div class="min-w-0">
                      <div class="winner-name"><?= h($w['empleado']) ?></div>
                      <div class="winner-branch">
                        <i class="bi bi-shop me-1"></i><?= h($w['sucursal']) ?>
                      </div>
                    </div>
                  </div>

                  <div class="row g-2">
                    <div class="col-6">
                      <div class="metric-box">
                        <div class="metric-label">Monto</div>
                        <div class="metric-value" style="font-size:1.15rem;"><?= money($w['monto']) ?></div>
                      </div>
                    </div>
                    <div class="col-6">
                      <div class="metric-box">
                        <div class="metric-label">Unidades</div>
                        <div class="metric-value"><?= (int)$w['unidades'] ?></div>
                      </div>
                    </div>
                  </div>

                  <div class="mt-3 small fw-bold text-muted">
                    <i class="bi bi-gift-fill text-warning me-1"></i>
                    Va ganando el Compaq Q6 por monto.
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php foreach ($zonas as $zona): ?>
        <?php $rows = $gerentesPorZona[$zona] ?? []; ?>
        <?php if (empty($rows)) continue; ?>

        <div class="card table-card mb-4">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-geo-alt-fill me-1"></i><?= h($zona) ?> | Ranking gerentes</span>
            <span class="badge text-bg-light"><?= count($rows) ?> participantes</span>
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
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php $pos = 1; foreach ($rows as $r): ?>
                  <tr class="<?= $pos === 1 ? 'winner-row' : '' ?>">
                    <td>
                      <span class="rank-badge <?= $pos === 1 ? 'rank-1' : '' ?>">
                        <?= $pos === 1 ? '🏆' : $pos ?>
                      </span>
                    </td>
                    <td>
                      <div class="mini-person">
                        <?php if (!empty($r['foto_url'])): ?>
                          <img class="mini-photo" src="<?= h($r['foto_url']) ?>" alt="<?= h($r['empleado']) ?>">
                        <?php else: ?>
                          <div class="mini-avatar"><?= h(inicialesNombre($r['empleado'])) ?></div>
                        <?php endif; ?>
                        <div>
                          <div class="mini-name"><?= h($r['empleado']) ?></div>
                          <div class="mini-sub"><?= h($r['rol']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td><?= h($r['sucursal']) ?></td>
                    <td class="text-end fw-black" style="font-weight:950;"><?= money($r['monto']) ?></td>
                    <td class="text-end"><?= (int)$r['unidades'] ?></td>
                    <td>
                      <?php if ($pos === 1): ?>
                        <span class="badge rounded-pill text-bg-warning">Ganador parcial</span>
                      <?php else: ?>
                        <span class="badge rounded-pill text-bg-secondary">En competencia</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php $pos++; endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>

    </div>

  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>