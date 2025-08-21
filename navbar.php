<?php
// navbar.php (LUGA) — versión compacta + "Gestionar usuarios" en Operación (solo Admin/Super)

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
include 'db.php';
date_default_timezone_set('America/Mexico_City');

$rolUsuario    = $_SESSION['rol'] ?? 'Ejecutivo';
$nombreUsuario = trim($_SESSION['nombre'] ?? 'Usuario');
$idUsuario     = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal    = (int)($_SESSION['id_sucursal'] ?? 0);

// Helpers
if (!function_exists('str_starts_with')) {
  function str_starts_with($h, $n)
  {
    return (string)$n !== '' && strncmp($h, $n, strlen($n)) === 0;
  }
}
function e($s)
{
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
function initials($name)
{
  $name = trim((string)$name);
  if ($name === '') return 'U';
  $parts = preg_split('/\s+/', $name);
  $first = mb_substr($parts[0] ?? '', 0, 1, 'UTF-8');
  $last  = mb_substr($parts[count($parts) - 1] ?? '', 0, 1, 'UTF-8');
  $ini = mb_strtoupper($first . $last, 'UTF-8');
  return $ini ?: 'U';
}
function first_name($name)
{
  $name = trim((string)$name);
  if ($name === '') return 'Usuario';
  $parts = preg_split('/\s+/', $name);
  return $parts[0] ?? $name;
}

/** Convierte usuarios_expediente.foto a una URL servible */
function resolveAvatarUrl(?string $fotoBD): ?string
{
  $f = trim((string)$fotoBD);
  if ($f === '') return null;
  if (preg_match('#^(https?://|data:image/)#i', $f)) return $f;
  $f = str_replace('\\', '/', $f);

  $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
  $appDir  = rtrim(str_replace('\\', '/', __DIR__), '/');
  $baseUri = '';
  if ($docroot && str_starts_with($appDir, $docroot)) {
    $baseUri = substr($appDir, strlen($docroot));
  }

  if (preg_match('#^[A-Za-z]:/|^/#', $f)) {
    if ($docroot && str_starts_with($f, $docroot . '/')) return substr($f, strlen($docroot));
    $base = basename($f);
    foreach (['uploads/expedientes', 'expedientes', 'uploads', 'uploads/usuarios', 'usuarios', 'uploads/perfiles', 'perfiles'] as $d) {
      $abs = $appDir . '/' . $d . '/' . $base;
      if (is_file($abs)) return $baseUri . '/' . $d . '/' . $base;
    }
    return null;
  }
  if (str_starts_with($f, '/')) {
    if ($docroot && is_file($docroot . $f)) return $f;
    return $f;
  }
  if (is_file($appDir . '/' . $f)) return $baseUri . '/' . ltrim($f, '/');
  if ($docroot && is_file($docroot . '/' . $f)) return '/' . ltrim($f, '/');
  $base = basename($f);
  foreach (['uploads/expedientes', 'expedientes', 'uploads', 'uploads/usuarios', 'usuarios', 'uploads/perfiles', 'perfiles'] as $d) {
    $abs = $appDir . '/' . $d . '/' . $base;
    if (is_file($abs)) return $baseUri . '/' . $d . '/' . $base;
  }
  return null;
}

// Avatar
$avatarUrl = null;
if ($idUsuario > 0) {
  $st = $conn->prepare("SELECT foto FROM usuarios_expediente WHERE usuario_id=? ORDER BY id DESC LIMIT 1");
  $st->bind_param("i", $idUsuario);
  $st->execute();
  $st->bind_result($fotoBD);
  if ($st->fetch()) {
    $avatarUrl = resolveAvatarUrl($fotoBD);
  }
  $st->close();
}

// Sucursal (solo en desplegable)
$sucursalNombre = '';
if ($idSucursal > 0) {
  $stmt = $conn->prepare("SELECT nombre FROM sucursales WHERE id=?");
  $stmt->bind_param("i", $idSucursal);
  $stmt->execute();
  $stmt->bind_result($sucursalNombre);
  $stmt->fetch();
  $stmt->close();
}

// Badge traspasos
$badgeTraspasos = 0;
if ($idSucursal > 0) {
  $stmt = $conn->prepare("SELECT COUNT(*) FROM traspasos WHERE id_sucursal_destino=? AND estatus='Pendiente'");
  $stmt->bind_param("i", $idSucursal);
  $stmt->execute();
  $stmt->bind_result($badgeTraspasos);
  $stmt->fetch();
  $stmt->close();
}

$esAdmin       = in_array($rolUsuario, ['Admin', 'Super']);
$primerNombre  = first_name($nombreUsuario);

// ============ ACTIVO POR URL ============
$current = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

$grpDashboard  = ['dashboard_unificado.php', 'dashboard_mensual.php', 'productividad_dia.php'];
$grpVentas     = ['nueva_venta.php', 'venta_sim_prepago.php', 'venta_sim_pospago.php', 'historial_ventas.php', 'historial_ventas_sims.php'];
$grpInventario = ['panel.php', 'inventario_subdistribuidor.php', 'inventario_global.php', 'inventario_resumen.php', 'inventario_eulalia.php', 'inventario_retiros.php', 'inventario_historico.php'];
$grpCompras    = ['compras_nueva.php', 'compras_resumen.php', 'modelos.php', 'proveedores.php', 'compras_ingreso.php'];
$grpTraspasos  = ['generar_traspaso.php', 'generar_traspaso_sims.php', 'traspasos_sims_pendientes.php', 'traspasos_sims_salientes.php', 'traspasos_pendientes.php', 'traspasos_salientes.php', 'traspaso_nuevo.php'];
$grpEfectivo   = ['cobros.php', 'cortes_caja.php', 'generar_corte.php', 'depositos_sucursal.php', 'depositos.php', 'recoleccion_comisiones.php'];
$grpOperacion  = [
  'lista_precios.php',
  'prospectos.php',
  'insumos_pedido.php',
  'insumos_admin.php',
  'mantenimiento_solicitar.php',
  'mantenimiento_admin.php',
  'gestionar_usuarios.php'
];
$grpRH         = ['reporte_nomina.php', 'reporte_nomina_gerentes_zona.php', 'admin_expedientes.php'];
$grpOperativos = ['insumos_catalogo.php', 'actualizar_precios_modelo.php', 'cuotas_mensuales.php', 'cuotas_mensuales_ejecutivos.php', 'cuotas_sucursales.php', 'cargar_cuotas_semanales.php', 'esquemas_comisiones_ejecutivos.php', 'esquemas_comisiones_gerentes.php', 'esquemas_comisiones_pospago.php', 'comisiones_especiales_equipos.php', 'carga_masiva_productos.php', 'carga_masiva_sims.php', 'alta_usuario.php', 'alta_sucursal.php'];
$grpCeleb      = ['cumples_aniversarios.php'];

function parent_active(array $group, string $current): bool
{
  return in_array($current, $group, true);
}
function item_active(string $file, string $current): string
{
  return $current === $file ? 'active' : '';
}
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<style>
  /* ========== Escalas compactas (fáciles de ajustar) ========== */
  :root {
    --brand-font: .88rem;
    /* Título "Central 2.0" */
    --nav-font: .84rem;
    /* Texto de pestañas del navbar */
    --drop-font: .86rem;
    /* Texto de items en dropdown */
    --icon-em: .90em;
    /* Escala de íconos en pestañas */
    --pad-y: .32rem;
    /* Padding vertical de pestañas */
    --pad-x: .48rem;
    /* Padding horizontal de pestañas */
  }

  /* —— Fondo y línea ——————————————————————— */
  .navbar-luga {
    background: radial-gradient(1200px 600px at 10% -20%, rgba(255, 255, 255, .18), rgba(255, 255, 255, 0)),
      linear-gradient(90deg, #0b0f14, #0f141a 60%, #121922);
    border-bottom: 1px solid rgba(255, 255, 255, .08);
    backdrop-filter: blur(6px);
  }

  /* —— Branding compacto —————————————————— */
  .brand-title {
    font-weight: 900;
    letter-spacing: .1px;
    line-height: 1;
    font-size: var(--brand-font);
    background: linear-gradient(92deg, #eaf2ff 0%, #cfe0ff 45%, #9ec5ff 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    text-shadow: 0 1px 0 rgba(0, 0, 0, .25);
    white-space: nowrap;
  }

  .navbar-brand img {
    width: 26px;
    height: 26px;
  }

  /* —— Links compactos ——————————————————— */
  .navbar-luga .nav-link {
    padding: var(--pad-y) var(--pad-x);
    font-size: var(--nav-font);
    border-radius: .6rem;
    color: #e7eef7 !important;
    line-height: 1.1;
  }

  .navbar-luga .nav-link i {
    font-size: var(--icon-em);
    margin-right: .35rem;
  }

  .navbar-luga .nav-link:hover {
    background: rgba(255, 255, 255, .06);
  }

  /* —— Dropdowns legibles (modo oscuro) —— */
  .navbar-luga .dropdown-menu {
    --bs-dropdown-bg: #0f141a;
    --bs-dropdown-color: #e7eef7;
    --bs-dropdown-link-color: #e7eef7;
    --bs-dropdown-link-hover-color: #ffffff;
    --bs-dropdown-link-hover-bg: rgba(255, 255, 255, .06);
    --bs-dropdown-link-active-bg: rgba(255, 255, 255, .12);
    --bs-dropdown-border-color: rgba(255, 255, 255, .08);
    --bs-dropdown-header-color: #aab8c7;
    --bs-dropdown-divider-bg: rgba(255, 255, 255, .12);
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 14px;
    box-shadow: 0 16px 40px rgba(0, 0, 0, .35);
    overflow: hidden;
    font-size: var(--drop-font);
  }

  .navbar-luga .dropdown-item {
    padding: .48rem .76rem;
    line-height: 1.15;
  }

  .navbar-luga .nav-link.active-parent {
    background: rgba(255, 255, 255, .10);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .12);
  }

  .navbar-luga .dropdown-item.active {
    background: rgba(255, 255, 255, .18);
    font-weight: 600;
  }

  /* —— Avatar / nombre ——————————————————— */
  .nav-avatar,
  .nav-initials {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: .92rem;
    object-fit: cover;
  }

  .nav-initials {
    background: #25303a;
    color: #e8f0f8;
  }

  .dropdown-avatar,
  .dropdown-initials {
    width: 54px;
    height: 54px;
    border-radius: 16px;
    object-fit: cover;
  }

  .dropdown-initials {
    background: #25303a;
    color: #e8f0f8;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
  }

  .user-chip {
    color: #e7eef7;
    font-weight: 600;
  }

  .user-chip small {
    color: #a7b4c2;
    font-weight: 500;
  }

  .badge-soft-danger {
    background: rgba(220, 53, 69, .18);
    color: #ffadb7;
    border: 1px solid rgba(220, 53, 69, .35);
  }

  /* —— Ajuste fino para laptops 1200–1440 —— */
  @media (min-width:1200px) and (max-width:1440px) {
    :root {
      --brand-font: .84rem;
      --nav-font: .82rem;
      --drop-font: .84rem;
      --pad-y: .30rem;
      --pad-x: .44rem;
      --icon-em: .88em;
    }

    .navbar-brand img {
      width: 24px;
      height: 24px;
    }
  }
</style>

<nav class="navbar navbar-expand-lg navbar-dark navbar-luga sticky-top">
  <div class="container-fluid">

    <!-- LOGO -->
    <a class="navbar-brand d-flex align-items-center" href="dashboard_unificado.php">
      <img src="https://i.ibb.co/DDw7yjYV/43f8e23a-8877-4928-9407-32d18fb70f79.png" alt="Logo" class="me-2 rounded-circle" style="object-fit:cover;">
      <span class="brand-title">Central&nbsp;<strong>2.0</strong></span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarMain">
      <!-- IZQUIERDA -->
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">

        <!-- DASHBOARD -->
        <?php $pActive = parent_active($grpDashboard, $current); ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
            <i class="bi bi-speedometer2"></i>Dashboard
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= item_active('productividad_dia.php', $current) ?>" href="productividad_dia.php">Dashboard diario</a></li>
            <li><a class="dropdown-item <?= item_active('dashboard_unificado.php', $current) ?>" href="dashboard_unificado.php">Dashboard semanal</a></li>
            <li><a class="dropdown-item <?= item_active('dashboard_mensual.php', $current) ?>" href="dashboard_mensual.php">Dashboard mensual</a></li>
          </ul>
        </li>

        <!-- VENTAS -->
        <?php $pActive = parent_active($grpVentas, $current); ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
            <i class="bi bi-bag-check"></i>Ventas
          </a>
          <ul class="dropdown-menu">
            <?php if ($rolUsuario === 'Logistica'): ?>
              <li><a class="dropdown-item <?= item_active('historial_ventas.php', $current) ?>" href="historial_ventas.php">Historial de ventas</a></li>
              <li><a class="dropdown-item <?= item_active('historial_ventas_sims.php', $current) ?>" href="historial_ventas_sims.php">Historial ventas SIM</a></li>
            <?php else: ?>
              <li><a class="dropdown-item <?= item_active('nueva_venta.php', $current) ?>" href="nueva_venta.php">Venta equipos</a></li>
              <li><a class="dropdown-item <?= item_active('venta_sim_prepago.php', $current) ?>" href="venta_sim_prepago.php">Venta SIM prepago</a></li>
              <li><a class="dropdown-item <?= item_active('venta_sim_pospago.php', $current) ?>" href="venta_sim_pospago.php">Venta SIM pospago</a></li>
              <li><a class="dropdown-item <?= item_active('historial_ventas.php', $current) ?>" href="historial_ventas.php">Historial de ventas</a></li>
              <li><a class="dropdown-item <?= item_active('historial_ventas_sims.php', $current) ?>" href="historial_ventas_sims.php">Historial ventas SIM</a></li>
            <?php endif; ?>
          </ul>
        </li>

        <!-- INVENTARIO -->
        <?php $pActive = parent_active($grpInventario, $current); ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
            <i class="bi bi-box-seam"></i>Inventario
          </a>
          <ul class="dropdown-menu">
            <?php if ($rolUsuario === 'Logistica'): ?>
              <li><a class="dropdown-item <?= item_active('inventario_global.php', $current) ?>" href="inventario_global.php">Inventario global</a></li>
              <li><a class="dropdown-item <?= item_active('inventario_historico.php', $current) ?>" href="inventario_historico.php">Inventario histórico</a></li>
            <?php else: ?>
              <?php if (in_array($rolUsuario, ['Ejecutivo', 'Gerente'])): ?>
                <li><a class="dropdown-item <?= item_active('panel.php', $current) ?>" href="panel.php">Inventario sucursal</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Admin', 'Subdistribuidor', 'Super'])): ?>
                <li><a class="dropdown-item <?= item_active('inventario_subdistribuidor.php', $current) ?>" href="inventario_subdistribuidor.php">Inventario subdistribuidor</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Admin', 'GerenteZona', 'Super'])): ?>
                <li><a class="dropdown-item <?= item_active('inventario_global.php', $current) ?>" href="inventario_global.php">Inventario global</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Admin', 'Super'])): ?>
                <li>
                  <hr class="dropdown-divider">
                </li>
                <li class="dropdown-header">Administrador</li>
                <li><a class="dropdown-item <?= item_active('inventario_resumen.php', $current) ?>" href="inventario_resumen.php">Resumen Global</a></li>
                <li><a class="dropdown-item <?= item_active('inventario_eulalia.php', $current) ?>" href="inventario_eulalia.php">Inventario Eulalia</a></li>
                <li><a class="dropdown-item <?= item_active('inventario_retiros.php', $current) ?>" href="inventario_retiros.php">🛑 Retiros de Inventario</a></li>
              <?php endif; ?>
            <?php endif; ?>
          </ul>
        </li>

        <!-- COMPRAS -->
        <?php if (in_array($rolUsuario, ['Admin', 'Super', 'Logistica'])): ?>
          <?php $pActive = parent_active($grpCompras, $current); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
              <i class="bi bi-cart-check"></i>Compras
            </a>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item <?= item_active('compras_nueva.php', $current) ?>" href="compras_nueva.php">Nueva factura</a></li>
              <li><a class="dropdown-item <?= item_active('compras_resumen.php', $current) ?>" href="compras_resumen.php">Resumen de compras</a></li>
              <li><a class="dropdown-item <?= item_active('modelos.php', $current) ?>" href="modelos.php">Catálogo de modelos</a></li>
              <li><a class="dropdown-item <?= item_active('proveedores.php', $current) ?>" href="proveedores.php">Proveedores</a></li>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li><a class="dropdown-item" href="compras_resumen.php?estado=Pendiente">Ingreso a almacén (pendientes)</a></li>
              <li><a class="dropdown-item disabled" href="#" tabindex="-1" aria-disabled="true" title="Se accede desde el Resumen">compras_ingreso.php (directo)</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <!-- TRASPASOS -->
        <?php if (in_array($rolUsuario, ['Gerente', 'Admin', 'Super'])): ?>
          <?php $pActive = parent_active($grpTraspasos, $current); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
              <i class="bi bi-arrow-left-right"></i>Traspasos
              <?php if ((int)$badgeTraspasos > 0): ?>
                <span class="badge badge-soft-danger ms-1"><?= (int)$badgeTraspasos ?></span>
              <?php endif; ?>
            </a>
            <ul class="dropdown-menu">
              <?php if (in_array($rolUsuario, ['Admin', 'Super'])): ?>
                <li><a class="dropdown-item <?= item_active('generar_traspaso.php', $current) ?>" href="generar_traspaso.php">Generar traspaso desde Eulalia</a></li>
              <?php endif; ?>
              <li><a class="dropdown-item <?= item_active('generar_traspaso_sims.php', $current) ?>" href="generar_traspaso_sims.php">Generar traspaso SIMs</a></li>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="dropdown-header">SIMs</li>
              <li><a class="dropdown-item <?= item_active('traspasos_sims_pendientes.php', $current) ?>" href="traspasos_sims_pendientes.php">SIMs pendientes</a></li>
              <li><a class="dropdown-item <?= item_active('traspasos_sims_salientes.php', $current) ?>" href="traspasos_sims_salientes.php">SIMs salientes</a></li>
              <?php if ($rolUsuario === 'Gerente'): ?>
                <li>
                  <hr class="dropdown-divider">
                </li>
                <li class="dropdown-header">Equipos</li>
                <li><a class="dropdown-item <?= item_active('traspaso_nuevo.php', $current) ?>" href="traspaso_nuevo.php">Generar traspaso entre sucursales</a></li>
              <?php endif; ?>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="dropdown-header">Historial de equipos</li>
              <li><a class="dropdown-item <?= item_active('traspasos_pendientes.php', $current) ?>" href="traspasos_pendientes.php">Historial traspasos entrantes</a></li>
              <li><a class="dropdown-item <?= item_active('traspasos_salientes.php', $current) ?>" href="traspasos_salientes.php">Historial traspasos salientes</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <!-- EFECTIVO -->
        <?php if ($rolUsuario !== 'Logistica'): ?>
          <?php $pActive = parent_active($grpEfectivo, $current); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
              <i class="bi bi-cash-coin"></i>Efectivo
            </a>
            <ul class="dropdown-menu">
              <?php if ($rolUsuario === 'GerenteZona'): ?>
                <!-- SOLO esta opción para GerenteZona -->
                <li><a class="dropdown-item <?= item_active('recoleccion_comisiones.php', $current) ?>" href="recoleccion_comisiones.php">Recolección comisiones</a></li>
              <?php else: ?>
                <!-- Para todos los demás roles -->
                <li><a class="dropdown-item <?= item_active('cobros.php', $current) ?>" href="cobros.php">Generar cobro</a></li>
                <li><a class="dropdown-item <?= item_active('cortes_caja.php', $current) ?>" href="cortes_caja.php">Corte de caja</a></li>
                <li><a class="dropdown-item <?= item_active('generar_corte.php', $current) ?>" href="generar_corte.php">Generar corte sucursal</a></li>
                <li><a class="dropdown-item <?= item_active('depositos_sucursal.php', $current) ?>" href="depositos_sucursal.php">Depósitos sucursal</a></li>
                <?php if ($esAdmin): ?>
                  <li><a class="dropdown-item <?= item_active('depositos.php', $current) ?>" href="depositos.php">Validar depósitos</a></li>
                <?php endif; ?>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <!-- OPERACIÓN -->
        <?php $pActive = parent_active($grpOperacion, $current); ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
            <i class="bi bi-gear-wide-connected"></i>Operación
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= item_active('lista_precios.php', $current) ?>" href="lista_precios.php">Lista de precios</a></li>
            <?php if (in_array($rolUsuario, ['Ejecutivo', 'Gerente'])): ?>
              <li><a class="dropdown-item <?= item_active('prospectos.php', $current) ?>" href="prospectos.php">Prospectos</a></li>
            <?php endif; ?>
            <?php if ($rolUsuario === 'Gerente'): ?>
              <li><a class="dropdown-item <?= item_active('insumos_pedido.php', $current) ?>" href="insumos_pedido.php">Pedido de insumos</a></li>
            <?php endif; ?>

            <?php if ($esAdmin): ?>
              <li><a class="dropdown-item <?= item_active('insumos_admin.php', $current) ?>" href="insumos_admin.php">Administrar insumos</a></li>
              <!-- Acceso directo solo para Admin/Super -->
              <li><a class="dropdown-item <?= item_active('gestionar_usuarios.php', $current) ?>" href="gestionar_usuarios.php">Gestionar usuarios</a></li>
            <?php endif; ?>

            <?php if (in_array($rolUsuario, ['Gerente', 'GerenteZona', 'GerenteSucursal', 'Admin', 'Super'])): ?>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="dropdown-header">Mantenimiento</li>
              <?php if (in_array($rolUsuario, ['Gerente', 'GerenteZona', 'GerenteSucursal'])): ?>
                <li><a class="dropdown-item <?= item_active('mantenimiento_solicitar.php', $current) ?>" href="mantenimiento_solicitar.php">Solicitar mantenimiento</a></li>
              <?php endif; ?>
              <?php if ($esAdmin): ?>
                <li><a class="dropdown-item <?= item_active('mantenimiento_admin.php', $current) ?>" href="mantenimiento_admin.php">Administrar solicitudes</a></li>
              <?php endif; ?>
            <?php endif; ?>
          </ul>
        </li>

        <!-- RH -->
        <?php if ($esAdmin): ?>
          <?php $pActive = parent_active($grpRH, $current); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
              <i class="bi bi-people"></i>RH
            </a>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item <?= item_active('reporte_nomina.php', $current) ?>" href="reporte_nomina.php">Reporte semanal</a></li>
              <li><a class="dropdown-item <?= item_active('reporte_nomina_gerentes_zona.php', $current) ?>" href="reporte_nomina_gerentes_zona.php">Gerentes zona</a></li>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="dropdown-header">Expedientes</li>
              <li><a class="dropdown-item <?= item_active('admin_expedientes.php', $current) ?>" href="admin_expedientes.php">Panel de expedientes</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <!-- OPERATIVOS -->
        <?php if ($esAdmin): ?>
          <?php $pActive = parent_active($grpOperativos, $current); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
              <i class="bi bi-wrench-adjustable-circle"></i>Operativos
            </a>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item <?= item_active('insumos_catalogo.php', $current) ?>" href="insumos_catalogo.php">Catálogo de insumos</a></li>
              <li><a class="dropdown-item <?= item_active('actualizar_precios_modelo.php', $current) ?>" href="actualizar_precios_modelo.php">Actualizar precios</a></li>
              <li><a class="dropdown-item <?= item_active('cuotas_mensuales.php', $current) ?>" href="cuotas_mensuales.php">Cuotas mensuales</a></li>
              <li><a class="dropdown-item <?= item_active('cuotas_mensuales_ejecutivos.php', $current) ?>" href="cuotas_mensuales_ejecutivos.php">Cuotas ejecutivos</a></li>
              <li><a class="dropdown-item <?= item_active('cuotas_sucursales.php', $current) ?>" href="cuotas_sucursales.php">Cuotas sucursales</a></li>
              <li><a class="dropdown-item <?= item_active('cargar_cuotas_semanales.php', $current) ?>" href="cargar_cuotas_semanales.php">Cuotas semanales</a></li>
              <li><a class="dropdown-item <?= item_active('esquemas_comisiones_ejecutivos.php', $current) ?>" href="esquemas_comisiones_ejecutivos.php">Esquema ejecutivos</a></li>
              <li><a class="dropdown-item <?= item_active('esquemas_comisiones_gerentes.php', $current) ?>" href="esquemas_comisiones_gerentes.php">Esquema gerentes</a></li>
              <li><a class="dropdown-item <?= item_active('esquemas_comisiones_pospago.php', $current) ?>" href="esquemas_comisiones_pospago.php">Esquema pospago</a></li>
              <li><a class="dropdown-item <?= item_active('comisiones_especiales_equipos.php', $current) ?>" href="comisiones_especiales_equipos.php">Comisiones escalables</a></li>
              <li><a class="dropdown-item <?= item_active('carga_masiva_productos.php', $current) ?>" href="carga_masiva_productos.php">Carga masiva equipos</a></li>
              <li><a class="dropdown-item <?= item_active('carga_masiva_sims.php', $current) ?>" href="carga_masiva_sims.php">Carga masiva SIMs</a></li>
              <li><a class="dropdown-item <?= item_active('alta_usuario.php', $current) ?>" href="alta_usuario.php">Alta usuario</a></li>
              <li><a class="dropdown-item <?= item_active('alta_sucursal.php', $current) ?>" href="alta_sucursal.php">Alta sucursal</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <!-- CELEBRACIONES (oculto para Logística) -->
        <?php if ($rolUsuario !== 'Logistica'): ?>
          <?php $pActive = parent_active(array_merge($grpCeleb, ['cuadro_honor.php']), $current); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
              <i class="bi bi-balloon-heart"></i>Celebraciones
            </a>
            <ul class="dropdown-menu">
              <li>
                <a class="dropdown-item <?= item_active('cumples_aniversarios.php', $current) ?>" href="cumples_aniversarios.php">
                  🎉 Cumpleaños & Aniversarios
                </a>
              </li>
              <li>
                <a class="dropdown-item <?= item_active('cuadro_honor.php', $current) ?>" href="cuadro_honor.php">
                  🏅 Cuadro de Honor
                </a>
              </li>
            </ul>
          </li>
        <?php endif; ?>

      </ul>

      <!-- DERECHA: Perfil -->
      <ul class="navbar-nav ms-auto">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
            <span class="me-2 position-relative">
              <?php if ($avatarUrl): ?>
                <img src="<?= e($avatarUrl) ?>" alt="avatar" class="nav-avatar"
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                <span class="nav-initials" style="display:none;"><?= e(initials($nombreUsuario)) ?></span>
              <?php else: ?>
                <span class="nav-initials"><?= e(initials($nombreUsuario)) ?></span>
              <?php endif; ?>
            </span>
            <span class="user-chip"><?= e($primerNombre) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li class="px-3 py-3">
              <?php if ($avatarUrl): ?>
                <img src="<?= e($avatarUrl) ?>" class="dropdown-avatar me-3"
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                <span class="dropdown-initials me-3" style="display:none;"><?= e(initials($nombreUsuario)) ?></span>
              <?php else: ?>
                <span class="dropdown-initials me-3"><?= e(initials($nombreUsuario)) ?></span>
              <?php endif; ?>
              <div class="d-inline-block align-middle">
                <div class="fw-semibold"><?= e($nombreUsuario) ?></div>
                <?php if ($sucursalNombre): ?>
                  <div class="text-secondary small"><i class="bi bi-shop me-1"></i><?= e($sucursalNombre) ?></div>
                <?php endif; ?>
                <div class="text-secondary small"><i class="bi bi-person-badge me-1"></i><?= e($rolUsuario) ?></div>
              </div>
            </li>
            <li>
              <hr class="dropdown-divider">
            </li>
            <li><a class="dropdown-item" href="mi_expediente.php"><i class="bi bi-folder-person me-2"></i>Mi expediente</a></li>
            <li><a class="dropdown-item" href="documentos_historial.php"><i class="bi bi-files me-2"></i>Mis documentos</a></li>
            <li>
              <hr class="dropdown-divider">
            </li>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Salir</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>