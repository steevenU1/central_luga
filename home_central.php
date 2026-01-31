<?php
// home_central.php — Home / Índice touch de Central 2.0
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php'; // 👈 opcional pero recomendado: mantiene tu topbar

date_default_timezone_set('America/Mexico_City');

$rolUsuario    = $_SESSION['rol'] ?? 'Ejecutivo';
$nombreUsuario = trim($_SESSION['nombre'] ?? 'Usuario');
$idUsuario     = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal    = (int)($_SESSION['id_sucursal'] ?? 0);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===== Badges (reusar idea del navbar) ===== */
$badgeEquip = 0;
$badgeSims  = 0;

if ($idSucursal > 0) {
  if ($st = $conn->prepare("SELECT COUNT(*) FROM traspasos WHERE id_sucursal_destino=? AND estatus='Pendiente'")) {
    $st->bind_param("i", $idSucursal);
    $st->execute();
    $st->bind_result($badgeEquip);
    $st->fetch();
    $st->close();
  }
  if ($st = $conn->prepare("SELECT COUNT(*) FROM traspasos_sims WHERE id_sucursal_destino=? AND estatus='Pendiente'")) {
    $st->bind_param("i", $idSucursal);
    $st->execute();
    $st->bind_result($badgeSims);
    $st->fetch();
    $st->close();
  }
}

$esAdmin = in_array($rolUsuario, ['Admin','Super'], true);
$esLog   = ($rolUsuario === 'Logistica');
$esGZ    = ($rolUsuario === 'GerenteZona');

/* ===== Definición de tiles (cards) =====
   - show: función que decide si se muestra
   - group: para agrupar visualmente (Favoritos / Ventas / Inventario / Operación / Admin)
*/
$tiles = [];

// Helper para agregar tiles
$add = function($id, $title, $subtitle, $href, $icon, $group, $show=true, $badge=0, $tags=[]) use (&$tiles) {
  $tiles[] = [
    'id'=>$id,
    'title'=>$title,
    'subtitle'=>$subtitle,
    'href'=>$href,
    'icon'=>$icon,
    'group'=>$group,
    'show'=>$show,
    'badge'=>(int)$badge,
    'tags'=>implode(' ', $tags),
  ];
};

/* ===== Favoritos por rol (arriba) ===== */
if (in_array($rolUsuario, ['Ejecutivo','Gerente'], true)) {
  $add('fav_venta','Venta equipos','Captura rápida de equipos','nueva_venta.php','bi-phone','Favoritos', true, 0, ['ventas','equipos']);
  $add('fav_hist','Historial','Tus ventas y filtros','historial_ventas.php','bi-receipt-cutoff','Favoritos', true, 0, ['historial','ventas']);
  $add('fav_inv','Inventario sucursal','Disponible en tu tienda','panel.php','bi-box-seam','Favoritos', true, 0, ['inventario','sucursal']);
  $add('fav_nom','Mi nómina','Semana Mar→Lun','nomina_mi_semana_v2.php','bi-cash-stack','Favoritos', true, 0, ['nomina']);
} else {
  $add('fav_dash','Dashboard semanal','Vista central','dashboard_unificado.php','bi-speedometer2','Favoritos', true, 0, ['dashboard']);
  if ($esAdmin) $add('fav_rh','Reporte nómina','Admin / RH','reporte_nomina_v2.php','bi-people','Favoritos', true, 0, ['rh','nomina']);
  if ($esAdmin || $esLog) $add('fav_tickets','Tickets Central','Operación','tickets_nuevo_luga.php','bi-ticket-detailed','Favoritos', true, 0, ['tickets']);
}

/* ===== Dashboard ===== */
$add('dash_diario','Dashboard diario','Productividad del día','productividad_dia.php','bi-graph-up','Dashboard', true, 0, ['dashboard','diario']);
$add('dash_semanal','Dashboard semanal','Mar→Lun','dashboard_unificado.php','bi-speedometer2','Dashboard', true, 0, ['dashboard','semanal']);
$add('dash_mensual','Dashboard mensual','Cumplimiento mensual','dashboard_mensual.php','bi-calendar3','Dashboard', true, 0, ['dashboard','mensual']);

/* ===== Ventas ===== */
if (!$esLog) {
  $add('v_equipos','Venta equipos','Nueva venta de equipo','nueva_venta.php','bi-bag-check','Ventas', true, 0, ['venta','equipos']);
  $add('v_prepago','Venta SIM prepago','Captura SIM prepago','venta_sim_prepago.php','bi-sim','Ventas', true, 0, ['venta','sim','prepago']);
  $add('v_pospago','Venta SIM pospago','Captura SIM pospago','venta_sim_pospago.php','bi-sim-fill','Ventas', true, 0, ['venta','sim','pospago']);
  $add('v_payjoy','PayJoy TC','Nueva venta TC','payjoy_tc_nueva.php','bi-credit-card-2-front','Ventas', true, 0, ['payjoy','tc']);
  $add('v_acc','Venta accesorios','Accesorios','venta_accesorios.php','bi-bag-plus','Ventas', true, 0, ['venta','accesorios']);
}
$add('h_ventas','Historial ventas','Filtros y export','historial_ventas.php','bi-clock-history','Ventas', true, 0, ['historial','ventas']);
$add('h_sims','Historial SIMs','Prepago/Pospago','historial_ventas_sims.php','bi-clock','Ventas', true, 0, ['historial','sims']);
$add('h_payjoy','Historial PayJoy TC','Reporte y export','historial_payjoy_tc.php','bi-journal-text','Ventas', true, 0, ['historial','payjoy']);
$add('h_acc','Historial accesorios','Reporte','historial_ventas_accesorios.php','bi-list-check','Ventas', true, 0, ['historial','accesorios']);

/* ===== Inventario ===== */
if (in_array($rolUsuario, ['Ejecutivo','Gerente'], true)) {
  $add('inv_suc','Inventario sucursal','Tu tienda','panel.php','bi-box-seam','Inventario', true, 0, ['inventario','sucursal']);
  $add('inv_res','Resumen global','Modelos / sucursales','inventario_resumen.php','bi-diagram-3','Inventario', true, 0, ['inventario','resumen']);
}
if (in_array($rolUsuario, ['Gerente','Admin','Logistica'], true)) {
  $add('inv_sims','Inventario SIMs','Resumen por operador','inventario_sims_resumen.php','bi-sd-card','Inventario', true, 0, ['inventario','sims']);
}
if (in_array($rolUsuario, ['Admin','GerenteZona','Super'], true)) {
  $add('inv_global','Inventario global','Vista completa','inventario_global.php','bi-globe','Inventario', true, 0, ['inventario','global']);
}
if ($esAdmin) {
  $add('inv_eul','Inventario Eulalia','Almacén','inventario_eulalia.php','bi-building','Inventario', true, 0, ['eulalia']);
  $add('inv_ret','Retiros inventario','Control admin','inventario_retiros.php','bi-exclamation-octagon','Inventario', true, 0, ['retiros']);
}

/* ===== Traspasos ===== */
$puedeTraspasos = in_array($rolUsuario, ['Gerente','GerenteSucursal','Admin','Super'], true) || ($rolUsuario === 'Ejecutivo'); // (tu navbar tiene regla extra; aquí lo dejamos básico)
$add('t_pend','Traspasos entrantes','Pendientes de equipos','traspasos_pendientes.php','bi-arrow-left-right','Traspasos', $puedeTraspasos, $badgeEquip, ['traspasos','pendientes','equipos']);
$add('t_sim_pend','SIMs pendientes','Pendientes SIM','traspasos_sims_pendientes.php','bi-sim','Traspasos', $puedeTraspasos, $badgeSims, ['traspasos','sims','pendientes']);
$add('t_sim_sal','SIMs salientes','Historial salidas','traspasos_sims_salientes.php','bi-box-arrow-up-right','Traspasos', $puedeTraspasos, 0, ['traspasos','sims']);
$add('t_sal','Traspasos salientes','Historial','traspasos_salientes.php','bi-box-arrow-right','Traspasos', $puedeTraspasos, 0, ['traspasos','salientes']);
$add('t_gen_sims','Generar traspaso SIMs','Enviar a sucursal','generar_traspaso_sims.php','bi-send','Traspasos', $puedeTraspasos, 0, ['traspasos','generar','sims']);
if ($esAdmin) $add('t_gen_eul','Traspaso desde Eulalia','Admin','generar_traspaso.php','bi-truck','Traspasos', true, 0, ['traspasos','eulalia']);

/* ===== Operación ===== */
$add('op_precios','Lista de precios','Consulta por modelo','lista_precios.php','bi-tags','Operación', true, 0, ['precios']);
$add('op_rec','Recargas Promo','Portal recargas','recargas_portal.php','bi-lightning','Operación', true, 0, ['recargas']);
if (in_array($rolUsuario, ['Ejecutivo','Gerente'], true)) {
  $add('op_pros','Prospectos','Seguimiento','prospectos.php','bi-person-lines-fill','Operación', true, 0, ['prospectos']);
  $add('op_nom','Mi nómina','Semana','nomina_mi_semana_v2.php','bi-cash-coin','Operación', true, 0, ['nomina']);
}
if ($esAdmin || $esLog) $add('op_panelop','Panel Operador','Operación central','panel_operador.php','bi-person-gear','Operación', true, 0, ['operador']);

/* ===== Admin / RH / Operativos ===== */
if ($esAdmin) {
  $add('adm_compras','Compras','Facturas y entradas','compras_resumen.php','bi-cart-check','Admin', true, 0, ['compras']);
  $add('adm_modelos','Modelos','Catálogo','modelos.php','bi-collection','Admin', true, 0, ['modelos']);
  $add('adm_prov','Proveedores','Catálogo','proveedores.php','bi-truck-flatbed','Admin', true, 0, ['proveedores']);

  $add('rh_nom','Reporte nómina','Semana Mar→Lun','reporte_nomina_v2.php','bi-people','RH', true, 0, ['rh','nomina']);
  $add('rh_asist','Asistencias (Admin)','Matriz','admin_asistencias.php','bi-calendar2-check','RH', true, 0, ['rh','asistencias']);
  $add('rh_exp','Expedientes','Panel','admin_expedientes.php','bi-folder2-open','RH', true, 0, ['rh','expedientes']);

  $add('op_tickets','Tickets Central','Soporte','tickets_nuevo_luga.php','bi-ticket-detailed','Operativos', true, 0, ['tickets']);
  $add('op_insumos','Catálogo insumos','Admin','insumos_catalogo.php','bi-box2-heart','Operativos', true, 0, ['insumos']);
  $add('op_cargas','Carga masiva SIMs','Admin','carga_masiva_sims.php','bi-upload','Operativos', true, 0, ['carga','sims']);
  $add('op_cargas2','Carga masiva productos','Admin','carga_masiva_productos.php','bi-cloud-upload','Operativos', true, 0, ['carga','productos']);
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Central 2.0 · Inicio</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{
      background:
        radial-gradient(900px 420px at 10% -10%, rgba(13,110,253,.20), rgba(0,0,0,0)),
        radial-gradient(900px 420px at 90% 0%, rgba(255,255,255,.08), rgba(0,0,0,0)),
        #0b0f14;
      color:#eaf2ff;
    }
    .page-wrap{ padding: 18px 14px 44px; }
    .home-title{
      font-weight: 900;
      letter-spacing:.2px;
      margin-bottom: .25rem;
    }
    .home-sub{ color: rgba(231,238,247,.78); }

    .searchbox .form-control{
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.12);
      color:#eaf2ff;
      border-radius: 14px;
      padding: .9rem 1rem;
    }
    .searchbox .form-control:focus{
      box-shadow: 0 0 0 .2rem rgba(13,110,253,.20);
      border-color: rgba(13,110,253,.55);
    }

    .group-title{
      margin-top: 18px;
      margin-bottom: 10px;
      font-weight: 800;
      color: rgba(231,238,247,.86);
      letter-spacing:.2px;
    }

    .tile{
      display:block;
      text-decoration:none;
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.10);
      border-radius: 18px;
      padding: 14px 14px;
      transition: transform .08s ease, background .12s ease, border-color .12s ease;
      min-height: 96px;
      position:relative;
      overflow:hidden;
    }
    .tile:hover{
      background: rgba(255,255,255,.085);
      border-color: rgba(255,255,255,.16);
      transform: translateY(-1px);
    }
    .tile .icon{
      width: 42px;
      height: 42px;
      border-radius: 14px;
      display:flex;
      align-items:center;
      justify-content:center;
      background: rgba(13,110,253,.16);
      border: 1px solid rgba(13,110,253,.25);
      flex: 0 0 auto;
    }
    .tile .icon i{ font-size: 1.2rem; color:#a6d1ff; }
    .tile .t-title{
      font-weight: 800;
      margin:0;
      color:#eaf2ff;
      line-height:1.1;
    }
    .tile .t-sub{
      margin:0;
      margin-top:4px;
      color: rgba(231,238,247,.75);
      font-size: .88rem;
      line-height:1.2;
    }

    .badge-float{
      position:absolute;
      top:10px;
      right:10px;
      font-weight:800;
      border-radius: 999px;
      padding: .25rem .55rem;
      font-size: .78rem;
      background: rgba(220,53,69,.18);
      border: 1px solid rgba(220,53,69,.35);
      color:#ffadb7;
    }

    /* Touch friendly: 2 cols en móvil, 4 en desktop */
    @media (min-width: 576px){
      .tile{ min-height: 104px; }
    }
  </style>
</head>
<body>

<div class="page-wrap container-fluid">
  <div class="d-flex flex-wrap align-items-end justify-content-between gap-2">
    <div>
      <h3 class="home-title mb-1">Inicio</h3>
      <div class="home-sub">Accesos rápidos para <strong><?= h($rolUsuario) ?></strong></div>
    </div>
    <div class="text-end small home-sub">
      <?= h($nombreUsuario) ?>
    </div>
  </div>

  <div class="searchbox mt-3">
    <input id="q" type="search" class="form-control" placeholder="Buscar: ventas, traspasos, nómina, inventario, tickets…">
  </div>

  <?php
  // Agrupar tiles visibles
  $groups = [];
  foreach ($tiles as $t) {
    if (empty($t['show'])) continue;
    $groups[$t['group']][] = $t;
  }

  // Orden sugerido
  $order = ['Favoritos','Dashboard','Ventas','Inventario','Traspasos','Operación','Admin','RH','Operativos'];
  foreach ($order as $g) {
    if (empty($groups[$g])) continue;
    echo '<div class="group-title">'.h($g).'</div>';
    echo '<div class="row g-3" data-group="'.h($g).'">';
    foreach ($groups[$g] as $t) {
      $badge = (int)$t['badge'];
      ?>
      <div class="col-12 col-sm-6 col-lg-3 tile-wrap"
           data-search="<?= h(mb_strtolower($t['title'].' '.$t['subtitle'].' '.$t['tags'], 'UTF-8')) ?>">
        <a class="tile" href="<?= h($t['href']) ?>">
          <?php if ($badge > 0): ?>
            <span class="badge-float"><?= $badge ?></span>
          <?php endif; ?>

          <div class="d-flex gap-3 align-items-start">
            <div class="icon"><i class="bi <?= h($t['icon']) ?>"></i></div>
            <div>
              <p class="t-title"><?= h($t['title']) ?></p>
              <p class="t-sub"><?= h($t['subtitle']) ?></p>
            </div>
          </div>
        </a>
      </div>
      <?php
    }
    echo '</div>';
  }
  ?>

  <div class="mt-4 small text-center" style="color:rgba(231,238,247,.55);">
    Central 2.0 · Home touch
  </div>
</div>

<script>
  (function(){
    const q = document.getElementById('q');
    const items = Array.from(document.querySelectorAll('.tile-wrap'));
    function apply(){
      const term = (q.value || '').trim().toLowerCase();
      let any = false;
      items.forEach(el => {
        const hay = (el.getAttribute('data-search') || '');
        const ok = !term || hay.includes(term);
        el.style.display = ok ? '' : 'none';
        if (ok) any = true;
      });

      // Ocultar grupos vacíos
      document.querySelectorAll('[data-group]').forEach(groupRow => {
        const visible = Array.from(groupRow.querySelectorAll('.tile-wrap'))
          .some(x => x.style.display !== 'none');
        const title = groupRow.previousElementSibling;
        if (title && title.classList.contains('group-title')) {
          title.style.display = visible ? '' : 'none';
        }
        groupRow.style.display = visible ? '' : 'none';
      });
    }
    q.addEventListener('input', apply);
    apply();
  })();
</script>

</body>
</html>
