<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
include 'db.php';

$rolUsuario    = $_SESSION['rol'] ?? 'Ejecutivo';
$nombreUsuario = $_SESSION['nombre'] ?? 'Usuario';
$idSucursal    = (int)($_SESSION['id_sucursal'] ?? 0);

// Obtener nombre de la sucursal
$sucursalNombre = '';
if ($idSucursal > 0) {
  $stmt = $conn->prepare("SELECT nombre FROM sucursales WHERE id=?");
  $stmt->bind_param("i", $idSucursal);
  $stmt->execute();
  $stmt->bind_result($sucursalNombre);
  $stmt->fetch();
  $stmt->close();
}
?>

<!-- Bootstrap CSS y JS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<nav class="navbar navbar-expand-lg navbar-dark bg-black sticky-top shadow">
  <div class="container-fluid">

    <!-- LOGO -->
    <a class="navbar-brand d-flex align-items-center" href="panel.php">
      <img src="https://i.ibb.co/DDw7yjYV/43f8e23a-8877-4928-9407-32d18fb70f79.png" alt="Logo" width="35" height="35" class="me-2" style="object-fit: contain;">
      <span>Central2.0</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarMain">
      <!-- IZQUIERDA -->
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">

        <!-- DASHBOARD -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Dashboard</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="dashboard_unificado.php">Dashboard semanal</a></li>
            <li><a class="dropdown-item" href="dashboard_mensual.php">Dashboard mensual</a></li>
          </ul>
        </li>

        <!-- VENTAS -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Ventas</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="nueva_venta.php">Venta equipos</a></li>
            <li><a class="dropdown-item" href="venta_sim_prepago.php">Venta SIM prepago</a></li>
            <li><a class="dropdown-item" href="venta_sim_pospago.php">Venta SIM pospago</a></li>
            <li><a class="dropdown-item" href="historial_ventas.php">Historial de ventas</a></li>
            <li><a class="dropdown-item" href="historial_ventas_sims.php">Historial ventas SIM</a></li>
          </ul>
        </li>

        <!-- INVENTARIO -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Inventario</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="panel.php">Inventario sucursal</a></li>
            <?php if ($rolUsuario === 'Admin'): ?>
              <li><a class="dropdown-item" href="inventario_global.php">Inventario global</a></li>
              <li><a class="dropdown-item" href="inventario_resumen.php">Resumen Global</a></li> <!-- NUEVO -->
              <li><a class="dropdown-item" href="inventario_eulalia.php">Inventario Eulalia</a></li>
              <li><a class="dropdown-item" href="inventario_subdistribuidor.php">Inventario subdistribuidor</a></li>
            <?php endif; ?>
          </ul>
        </li>

        <?php if ($rolUsuario !== 'Ejecutivo'): ?>
          <!-- TRASPASOS -->
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Traspasos</a>
            <ul class="dropdown-menu dropdown-menu-dark">
              <?php if ($rolUsuario === 'Admin'): ?>
                <li><a class="dropdown-item" href="generar_traspaso.php">Generar traspaso desde Eulalia</a></li>
              <?php endif; ?>

              <?php if (in_array($rolUsuario, ['Gerente', 'Admin'])): ?>
                <li><a class="dropdown-item" href="generar_traspaso_sims.php">Generar traspaso SIMs</a></li>
                <li><hr class="dropdown-divider"></li>
                <li class="dropdown-header">SIMs</li>
                <li><a class="dropdown-item" href="traspasos_sims_pendientes.php">SIMs pendientes</a></li> <!-- NUEVO -->
                <li><a class="dropdown-item" href="traspasos_sims_salientes.php">SIMs salientes</a></li>  <!-- NUEVO -->
              <?php endif; ?>

              <?php if ($rolUsuario === 'Gerente'): ?>
                <li><hr class="dropdown-divider"></li>
                <li class="dropdown-header">Equipos</li>
                <li><a class="dropdown-item" href="traspaso_nuevo.php">Generar traspaso entre sucursales</a></li>
              <?php endif; ?>

              <li><hr class="dropdown-divider"></li>
              <li class="dropdown-header">Historial de equipos</li>
              <li><a class="dropdown-item" href="traspasos_pendientes.php">Historial traspasos entrantes</a></li>
              <li><a class="dropdown-item" href="traspasos_salientes.php">Historial traspasos salientes</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <!-- EFECTIVO -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Efectivo</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="cobros.php">Generar cobro</a></li>
            <li><a class="dropdown-item" href="cortes_caja.php">Corte de caja</a></li>
            <li><a class="dropdown-item" href="generar_corte.php">Generar corte sucursal</a></li>
            <li><a class="dropdown-item" href="depositos_sucursal.php">Depósitos sucursal</a></li>
            <?php if ($rolUsuario === 'Admin'): ?>
              <li><a class="dropdown-item" href="depositos.php">Validar depósitos</a></li>
            <?php endif; ?>

            <?php if ($rolUsuario === 'GerenteZona'): ?>
              <li><hr class="dropdown-divider"></li>
              <li class="dropdown-header">Comisiones</li>
              <li><a class="dropdown-item" href="recoleccion_comisiones.php">Recolección comisiones</a></li>
            <?php endif; ?>
          </ul>
        </li>

        <!-- OPERACIÓN -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Operación</a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="lista_precios.php">Lista de precios</a></li>
            <?php if ($rolUsuario === 'Gerente'): ?>
              <li><a class="dropdown-item" href="insumos_pedido.php">Pedido de insumos</a></li>
            <?php endif; ?>
            <?php if ($rolUsuario === 'Admin'): ?>
              <li><a class="dropdown-item" href="insumos_admin.php">Administrar insumos</a></li>
            <?php endif; ?>
          </ul>
        </li>

        <?php if ($rolUsuario === 'Admin'): ?>
          <!-- RH (antes Nómina) -->
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">RH</a>
            <ul class="dropdown-menu dropdown-menu-dark">
              <!-- Reportes de nómina -->
              <li><a class="dropdown-item" href="reporte_nomina.php">Reporte semanal</a></li>
              <li><a class="dropdown-item" href="reporte_nomina_gerentes_zona.php">Gerentes zona</a></li>

              <li><hr class="dropdown-divider"></li>
              <li class="dropdown-header">Expedientes</li>
              <li><a class="dropdown-item" href="admin_expedientes.php">Panel de expedientes</a></li>
            </ul>
          </li>

          <!-- OPERATIVOS -->
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Operativos</a>
            <ul class="dropdown-menu dropdown-menu-dark">
              <li><a class="dropdown-item" href="insumos_catalogo.php">Catálogo de insumos</a></li>
              <li><a class="dropdown-item" href="actualizar_precios_modelo.php">Actualizar precios</a></li>
              <li><a class="dropdown-item" href="cuotas_mensuales.php">Cuotas mensuales</a></li>
              <li><a class="dropdown-item" href="cuotas_mensuales_ejecutivos.php">Cuotas ejecutivos</a></li>
              <li><a class="dropdown-item" href="cuotas_sucursales.php">Cuotas sucursales</a></li>
              <li><a class="dropdown-item" href="cargar_cuotas_semanales.php">Cuotas semanales</a></li>
              <li><a class="dropdown-item" href="esquemas_comisiones_ejecutivos.php">Esquema ejecutivos</a></li>
              <li><a class="dropdown-item" href="esquemas_comisiones_gerentes.php">Esquema gerentes</a></li>
              <li><a class="dropdown-item" href="esquemas_comisiones_pospago.php">Esquema pospago</a></li>
              <li><a class="dropdown-item" href="carga_masiva_productos.php">Carga masiva equipos</a></li>
              <li><a class="dropdown-item" href="carga_masiva_sims.php">Carga masiva SIMs</a></li>
              <li><a class="dropdown-item" href="alta_usuario.php">Alta usuario</a></li>
              <li><a class="dropdown-item" href="alta_sucursal.php">Alta sucursal</a></li>
            </ul>
          </li>
        <?php endif; ?>
      </ul>

      <!-- DERECHA: Perfil -->
      <ul class="navbar-nav ms-auto">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
            <span class="me-1">👤 <?= htmlspecialchars($nombreUsuario) ?></span>
            <?php if ($sucursalNombre): ?>
              <small class="text-secondary d-none d-lg-inline">| <?= htmlspecialchars($sucursalNombre) ?></small>
            <?php endif; ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
            <?php if ($sucursalNombre): ?>
              <li class="dropdown-header"><?= htmlspecialchars($sucursalNombre) ?></li>
            <?php endif; ?>
            <li><a class="dropdown-item" href="expediente_usuario.php">Mi expediente</a></li>
            <li><a class="dropdown-item" href="documentos_historial.php">Mis documentos</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="logout.php">Salir</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
