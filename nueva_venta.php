<?php
// nueva_venta.php — Central (con: cupón + promo regalo + ✅ promo 2do con descuento)

// ✅ Iniciamos sesión aquí porque moveremos el include del navbar más abajo
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guard_corte.php'; // ⬅️ Helper del candado

$id_usuario           = (int)($_SESSION['id_usuario'] ?? 0);
$id_sucursal_usuario  = (int)($_SESSION['id_sucursal'] ?? 0);
$nombre_usuario       = trim($_SESSION['nombre'] ?? 'Usuario');

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
// ✅ Roles Subdis_* pueden editar el precio
$isSubdis = (stripos($ROL, 'Subdis_') === 0);

// Traer sucursales
$sql_suc = "SELECT id, nombre FROM sucursales ORDER BY nombre";
$sucursales = $conn->query($sql_suc)->fetch_all(MYSQLI_ASSOC);

// Mapa id=>nombre para uso en JS
$mapSuc = [];
foreach ($sucursales as $s) {
  $mapSuc[(int)$s['id']] = $s['nombre'];
}

// 🔓 Regla NUEVA: solo Subdis_* puede editar manualmente el precio_venta
$SUCURSALES_PRECIO_LIBRE = []; // ya no se usa, lo dejamos vacío para no romper data-attr existente
$editablePrecioInicial = $isSubdis;

// 🔒 Evaluación del candado para la sucursal por defecto (la del usuario)
list($bloquearInicial, $motivoBloqueoInicial, $ayerCandado) = debe_bloquear_captura($conn, $id_sucursal_usuario);
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nueva Venta</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico?v=2">

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <!-- Select2 -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

  <style>
    :root {
      --brand: #0d6efd;
      --brand-100: rgba(13, 110, 253, .08);
    }

    body.bg-light {
      background:
        radial-gradient(1200px 400px at 100% -50%, var(--brand-100), transparent),
        radial-gradient(1200px 400px at -10% 120%, rgba(25, 135, 84, .06), transparent),
        #f8fafc;
    }

    #topbar,
    .navbar-luga {
      font-size: 16px;
    }

    @media (max-width:576px) {

      #topbar,
      .navbar-luga {
        font-size: 16px;
        --brand-font: 1.00em;
        --nav-font: .95em;
        --drop-font: .95em;
        --icon-em: 1.05em;
        --pad-y: .44em;
        --pad-x: .62em;
      }

      #topbar .navbar-brand img,
      .navbar-luga .navbar-brand img {
        width: 1.8em;
        height: 1.8em;
      }

      #topbar .btn-asistencia,
      .navbar-luga .btn-asistencia {
        font-size: .95em;
        padding: .5em .9em !important;
        border-radius: 12px;
      }

      #topbar .nav-avatar,
      #topbar .nav-initials,
      .navbar-luga .nav-avatar,
      .navbar-luga .nav-initials {
        width: 2.1em;
        height: 2.1em;
      }

      #topbar .navbar-toggler,
      .navbar-luga .navbar-toggler {
        padding: .45em .7em;
      }
    }

    @media (max-width:360px) {

      #topbar,
      .navbar-luga {
        font-size: 15px;
      }
    }

    .page-title {
      font-weight: 700;
      letter-spacing: .3px;
    }

    .card-elev {
      border: 0;
      box-shadow: 0 10px 24px rgba(2, 8, 20, 0.06), 0 2px 6px rgba(2, 8, 20, 0.05);
      border-radius: 1rem;
    }

    .section-title {
      font-size: .95rem;
      font-weight: 700;
      color: #334155;
      text-transform: uppercase;
      letter-spacing: .8px;
      margin-bottom: .75rem;
      display: flex;
      align-items: center;
      gap: .5rem;
    }

    .section-title .bi {
      opacity: .85;
    }

    .req::after {
      content: " *";
      color: #dc3545;
      font-weight: 600;
    }

    .help-text {
      font-size: .85rem;
      color: #64748b;
    }

    .select2-container--default .select2-selection--single {
      height: 38px;
      border-radius: .5rem;
    }

    .select2-container--default .select2-selection__rendered {
      line-height: 38px;
    }

    .select2-container--default .select2-selection__arrow {
      height: 38px
    }

    .alert-sucursal {
      border-left: 4px solid #f59e0b;
    }

    .btn-gradient {
      background: linear-gradient(90deg, #16a34a, #22c55e);
      border: 0;
    }

    .btn-gradient:disabled {
      opacity: .7;
    }

    .badge-soft {
      background: #eef2ff;
      color: #1e40af;
      border: 1px solid #dbeafe;
    }

    .list-compact {
      margin: 0;
      padding-left: 1rem;
    }

    .list-compact li {
      margin-bottom: .25rem;
    }

    .alert-candado {
      border-left: 6px solid #dc3545;
    }

    .cliente-summary-label {
      font-size: .85rem;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: #64748b;
      margin-bottom: .25rem;
    }

    .cliente-summary-main {
      font-weight: 600;
      font-size: 1.05rem;
      color: #111827;
    }

    .cliente-summary-sub {
      font-size: .9rem;
      color: #6b7280;
    }

    .text-success-soft {
      color: #15803d;
    }

    /* ✅ Bloqueo visual sin perder POST */
    .select-lock {
      background: #e9ecef !important;
      cursor: not-allowed !important;
    }

    .select-lock+.select2-container .select2-selection {
      background: #e9ecef !important;
      cursor: not-allowed !important;
    }

    .promo-pill {
      display: inline-flex;
      gap: .5rem;
      align-items: center;
      padding: .35rem .6rem;
      border-radius: 999px;
      font-size: .85rem;
      border: 1px solid rgba(2, 8, 20, .08);
      background: #fff;
    }
  </style>
</head>

<body class="bg-light">

  <?php include __DIR__ . '/navbar.php'; ?>

  <?php
  $mensajeError = isset($_GET['err']) ? trim($_GET['err']) : '';
  $mensajeOk    = isset($_GET['msg']) ? trim($_GET['msg']) : '';
  ?>

  <div class="container my-4">

    <?php if ($mensajeError !== ''): ?>
      <div class="alert alert-danger">
        <?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($mensajeOk !== ''): ?>
      <div class="alert alert-success">
        <?= htmlspecialchars($mensajeOk, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <div class="container my-4">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
          <h2 class="page-title mb-1"><i class="bi bi-bag-plus me-2"></i>Registrar Nueva Venta</h2>
          <div class="help-text">Selecciona primero el <strong>Tipo de Venta</strong> y confirma en el modal antes de enviar.</div>
        </div>
        <a href="panel.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver al Panel</a>
      </div>

      <!-- 🔒 Banner de candado -->
      <div id="banner_candado" class="alert alert-danger alert-candado d-<?= $bloquearInicial ? 'block' : 'none' ?>">
        <div class="d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-lock-fill fs-4"></i>
            <div id="candado_msg">
              <strong>Captura bloqueada.</strong>
              <?= htmlspecialchars($motivoBloqueoInicial) ?>
              <div class="small">Genera el corte de <strong><?= htmlspecialchars($ayerCandado) ?></strong> para continuar.</div>
            </div>
          </div>
          <a href="depositos.php#cortes" class="btn btn-outline-light btn-sm">
            <i class="bi bi-clipboard-data"></i> Ir a Cortes
          </a>
        </div>
      </div>

      <div class="mb-3">
        <div class="card border-0 shadow-sm">
          <div class="card-body d-flex flex-wrap align-items-center gap-2">
            <span class="badge rounded-pill text-bg-primary"><i class="bi bi-person-badge me-1"></i> Usuario: <?= htmlspecialchars($nombre_usuario) ?></span>
            <span class="badge rounded-pill text-bg-info"><i class="bi bi-shop me-1"></i> Tu sucursal: <?= htmlspecialchars($mapSuc[$id_sucursal_usuario] ?? '—') ?></span>
            <span class="badge rounded-pill badge-soft"><i class="bi bi-shield-check me-1"></i> Sesión activa</span>
          </div>
        </div>
      </div>

      <div id="alerta_sucursal" class="alert alert-warning alert-sucursal d-none">
        <i class="bi bi-exclamation-triangle me-1"></i><strong>Atención:</strong> Estás eligiendo una sucursal diferente a la tuya. La venta contará para tu usuario en esa sucursal.
      </div>

      <div id="errores" class="alert alert-danger d-none"></div>

      <form method="POST" action="procesar_venta.php" id="form_venta" novalidate data-locked="<?= $bloquearInicial ? '1' : '0' ?>">
        <input type="text" name="username" autocomplete="username" style="display:none">
        <input type="password" name="password" autocomplete="current-password" style="display:none">
        <input type="hidden" name="id_usuario" value="<?= $id_usuario ?>">

        <!-- 🔗 Cliente seleccionado -->
        <input type="hidden" name="id_cliente" id="id_cliente" value="">
        <input type="hidden" name="nombre_cliente" id="nombre_cliente" value="">
        <input type="hidden" name="telefono_cliente" id="telefono_cliente" value="">
        <input type="hidden" name="correo_cliente" id="correo_cliente" value="">

        <!-- 🔗 Cupón -->
        <input type="hidden" name="monto_cupon" id="monto_cupon" value="0">

        <!-- 🎁 Promo regalo -->
        <input type="hidden" name="es_regalo" id="es_regalo" value="0">
        <input type="hidden" name="id_promo_regalo" id="id_promo_regalo" value="">
        <input type="hidden" name="promo_regalo_aplicado" id="promo_regalo_aplicado" value="0">
        <input type="hidden" name="promo_regalo_id" id="promo_regalo_id" value="0">

        <!-- ✅ Promo 2do con descuento -->
        <input type="hidden" name="promo_desc_aplicado" id="promo_desc_aplicado" value="0">
        <input type="hidden" name="promo_desc_id" id="promo_desc_id" value="0">
        <input type="hidden" name="promo_desc_pct" id="promo_desc_pct" value="0">
        <input type="hidden" name="tag_venta_principal" id="tag_venta_principal" value="">

        <div class="card card-elev mb-4">
          <div class="card-body">

            <div class="section-title"><i class="bi bi-phone"></i> Tipo de venta</div>
            <div class="row g-3 mb-3">
              <div class="col-md-4">
                <label class="form-label req">Tipo de Venta</label>
                <select name="tipo_venta" id="tipo_venta" class="form-control" required>
                  <option value="">Seleccione...</option>
                  <option value="Contado">Contado</option>
                  <option value="Financiamiento">Financiamiento</option>
                  <option value="Financiamiento+Combo">Financiamiento + Combo</option>
                </select>
              </div>
            </div>

            <hr class="my-4">

            <div class="section-title"><i class="bi bi-geo-alt"></i> Datos de operación</div>
            <div class="row g-3 mb-3">
              <div class="col-md-4">
                <label class="form-label req">Sucursal de la Venta</label>
                <select name="id_sucursal" id="id_sucursal" class="form-control" required>
                  <?php foreach ($sucursales as $sucursal): ?>
                    <option value="<?= (int)$sucursal['id'] ?>" <?= (int)$sucursal['id'] === $id_sucursal_usuario ? 'selected' : '' ?>>
                      <?= htmlspecialchars($sucursal['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Puedes registrar en otra sucursal si operaste ahí.</div>
              </div>
            </div>

            <hr class="my-4">

            <div class="section-title"><i class="bi bi-people"></i> Datos del cliente</div>

            <div class="row g-3 mb-3">
              <div class="col-md-8">
                <div class="border rounded-3 p-3 bg-light">
                  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                      <div class="cliente-summary-label">Cliente seleccionado</div>
                      <div class="cliente-summary-main" id="cliente_resumen_nombre">
                        Ninguno seleccionado
                      </div>
                      <div class="cliente-summary-sub" id="cliente_resumen_detalle">
                        Usa el botón <strong>Buscar / crear cliente</strong> para seleccionar uno.
                      </div>
                    </div>
                    <div class="text-end">
                      <span class="badge rounded-pill text-bg-secondary" id="badge_tipo_cliente">
                        <i class="bi bi-person-dash me-1"></i> Sin cliente
                      </span>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-md-4 d-flex align-items-center justify-content-md-end">
                <button type="button" class="btn btn-outline-primary w-100" id="btn_open_modal_clientes">
                  <i class="bi bi-search me-1"></i> Buscar / crear cliente
                </button>
              </div>
            </div>

            <div class="row g-3 mb-2">
              <div class="col-md-4" id="tag_field">
                <label for="tag" class="form-label">TAG (ID del crédito)</label>
                <input type="text" name="tag" id="tag" class="form-control" placeholder="Ej. PJ-123ABC">
              </div>

              <!-- ✅ Tag venta principal SOLO cuando es "2do con descuento" y tipo=Financiamiento -->
              <div class="col-md-4 d-none" id="tag_principal_field">
                <label for="tag_principal" class="form-label req">TAG de la venta principal</label>
                <input type="text" id="tag_principal" class="form-control" placeholder="Ej. PJ-123ABC (venta principal)">
                <div class="form-text">Se valida en backend que la venta principal tenga un equipo participante.</div>
              </div>
            </div>

            <hr class="my-4">

            <div class="section-title"><i class="bi bi-device-ssd"></i> Equipos</div>
            <div class="row g-3 mb-2">
              <div class="col-md-4">
                <label class="form-label req">Equipo Principal</label>
                <select name="equipo1" id="equipo1" class="form-control select2-equipo" required></select>
                <div class="form-text">Puedes buscar por modelo, <strong>IMEI1</strong> o <strong>IMEI2</strong>.</div>
              </div>
              <div class="col-md-4" id="combo" style="display:none;">
                <label class="form-label">Equipo Combo</label>
                <select name="equipo2" id="equipo2" class="form-control select2-equipo"></select>
              </div>
            </div>

            <!-- 🎁 UI Promo regalo -->
            <div id="wrap_promo_regalo" class="mt-3 d-none">
              <div class="alert alert-success mb-2">
                <div class="d-flex align-items-start gap-2">
                  <i class="bi bi-gift-fill fs-5"></i>
                  <div class="w-100">
                    <div class="fw-semibold">
                      Promo detectada: <span id="promo_regalo_nombre">—</span>
                    </div>

                    <div class="form-check mt-2">
                      <input class="form-check-input" type="checkbox" id="chk_regalo">
                      <label class="form-check-label fw-semibold" for="chk_regalo">
                        Entregar equipo de regalo (promo)
                      </label>
                    </div>

                    <div class="small text-muted mt-1" id="promo_regalo_hint">
                      Si activas esto, se forzará el equipo combo y contará como <strong>$0.00</strong>.
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ✅ UI Promo 2do con descuento -->
            <div id="wrap_promo_desc" class="mt-3 d-none">
              <div class="alert alert-primary mb-2">
                <div class="d-flex align-items-start gap-2">
                  <i class="bi bi-percent fs-5"></i>
                  <div class="w-100">
                    <div class="fw-semibold d-flex flex-wrap align-items-center gap-2">
                      Promoción disponible para 2do equipo con descuento
                      <span class="promo-pill d-none" id="pill_promo_desc">
                        <i class="bi bi-badge-percent"></i>
                        <span id="pill_promo_desc_txt">—</span>
                      </span>
                    </div>

                    <div class="form-check mt-2">
                      <input class="form-check-input" type="checkbox" id="chk_descuento2">
                      <label class="form-check-label fw-semibold" for="chk_descuento2">
                        Aplicar promoción (2do con descuento)
                      </label>
                    </div>

                    <div class="small text-muted mt-1">
                      Si la activas, seleccionarás una promo y el sistema aplicará el % de descuento automáticamente.
                      <span class="d-block mt-1">
                        Nota: esta promo no se puede combinar con <strong>promo regalo</strong>.
                      </span>
                    </div>

                    <div class="mt-2 d-flex flex-wrap gap-2">
                      <button type="button" class="btn btn-outline-light btn-sm d-none" id="btn_elegir_promo_desc">
                        <i class="bi bi-list-check me-1"></i> Elegir promo
                      </button>
                      <button type="button" class="btn btn-outline-light btn-sm d-none" id="btn_quitar_promo_desc">
                        <i class="bi bi-x-circle me-1"></i> Quitar promo
                      </button>
                    </div>

                    <div class="small text-muted mt-2 d-none" id="hint_desc_tag_principal">
                      Si estás registrando el <strong>2do equipo como Financiamiento</strong>, captura el <strong>TAG de la venta principal</strong>.
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <hr class="my-4">

            <div class="section-title"><i class="bi bi-cash-coin"></i> Datos financieros</div>
            <div class="row g-3 mb-2">
              <div class="col-md-4">
                <label class="form-label req">Precio de Venta Total ($)</label>
                <input
                  type="number"
                  step="0.01"
                  min="0.01"
                  name="precio_venta"
                  id="precio_venta"
                  class="form-control"
                  placeholder="0.00"
                  required
                  <?= $editablePrecioInicial ? '' : 'readonly' ?>
                  data-precio-libre-rol="<?= $editablePrecioInicial ? '1' : '0' ?>"
                  data-sucursales-libre="<?= htmlspecialchars(implode(',', $SUCURSALES_PRECIO_LIBRE)) ?>">
                <div class="form-text <?= $editablePrecioInicial ? 'd-none' : '' ?>" id="txt_precio_auto">
                  Se calcula automáticamente según los equipos seleccionados.
                </div>
                <div class="form-text d-none" id="txt_precio_manual">
                  Puedes ajustar manualmente el precio de venta final (Subdistribuidor).
                </div>
                <div class="form-text text-success-soft d-none" id="txt_cupon_info">
                  Cupón aplicado: -$<span id="lbl_cupon_monto">0.00</span> MXN
                </div>
              </div>

              <div class="col-md-4" id="enganche_field">
                <label class="form-label">Enganche ($)</label>
                <input type="number" step="0.01" min="0" name="enganche" id="enganche" class="form-control" value="0" placeholder="0.00">
              </div>

              <div class="col-md-4">
                <label id="label_forma_pago" class="form-label req">Forma de Pago</label>
                <select name="forma_pago_enganche" id="forma_pago_enganche" class="form-control" required>
                  <option value="Efectivo">Efectivo</option>
                  <option value="Tarjeta">Tarjeta</option>
                  <option value="Mixto">Mixto</option>
                </select>
              </div>
            </div>

            <div class="row g-3 mb-2" id="mixto_detalle" style="display:none;">
              <div class="col-md-6">
                <label class="form-label">Enganche Efectivo ($)</label>
                <input type="number" step="0.01" min="0" name="enganche_efectivo" id="enganche_efectivo" class="form-control" value="0" placeholder="0.00">
              </div>
              <div class="col-md-6">
                <label class="form-label">Enganche Tarjeta ($)</label>
                <input type="number" step="0.01" min="0" name="enganche_tarjeta" id="enganche_tarjeta" class="form-control" value="0" placeholder="0.00">
              </div>
            </div>

            <div class="row g-3">
              <div class="col-md-4" id="plazo_field">
                <label class="form-label">Plazo en Semanas</label>
                <input type="number" min="1" name="plazo_semanas" id="plazo_semanas" class="form-control" value="0" placeholder="Ej. 52">
              </div>
              <div class="col-md-4" id="financiera_field">
                <label class="form-label">Financiera</label>
                <select name="financiera" id="financiera" class="form-control">
                  <option value="">N/A</option>
                  <option value="PayJoy">PayJoy</option>
                  <option value="Krediya">Krediya</option>
                  <option value="Innovación Movil">Innovación Movil</option>
                  <option value="Plata Card">Plata Card</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Comentarios</label>
                <input
                  type="text"
                  name="comentarios"
                  id="comentarios"
                  class="form-control"
                  placeholder="Notas adicionales (opcional)"
                  autocomplete="off"
                  autocapitalize="off"
                  autocorrect="off"
                  spellcheck="false" />
              </div>
            </div>
          </div>

          <div class="card-footer bg-white border-0 p-3">
            <button class="btn btn-gradient text-white w-100 py-2" id="btn_submit">
              <i class="bi bi-check2-circle me-2"></i> Registrar Venta
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- Modal de Confirmación -->
    <div class="modal fade" id="modalConfirmacion" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header bg-light">
            <h5 class="modal-title"><i class="bi bi-patch-question me-2 text-primary"></i>Confirma los datos antes de enviar</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-info mb-3">
              <i class="bi bi-info-circle me-1"></i>
              <strong>Validación de identidad:</strong> verifica que la venta se registrará con el <u>usuario correcto</u> y en la <u>sucursal correcta</u>.
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                  <div class="card-body">
                    <div class="section-title"><i class="bi bi-person-check"></i> Usuario y sucursal</div>
                    <ul class="list-compact">
                      <li><strong>Usuario:</strong> <span id="conf_usuario"><?= htmlspecialchars($nombre_usuario) ?></span></li>
                      <li><strong>Sucursal:</strong> <span id="conf_sucursal">—</span></li>
                    </ul>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                  <div class="card-body">
                    <div class="section-title"><i class="bi bi-receipt"></i> Venta</div>
                    <ul class="list-compact">
                      <li><strong>Tipo:</strong> <span id="conf_tipo">—</span></li>
                      <li><strong>Equipo principal:</strong> <span id="conf_equipo1">—</span></li>
                      <li class="d-none" id="li_equipo2"><strong>Equipo combo:</strong> <span id="conf_equipo2">—</span></li>
                      <li class="d-none" id="li_promo_desc"><strong>Promo descuento:</strong> <span id="conf_promo_desc">—</span></li>
                      <li class="d-none" id="li_tag_principal"><strong>TAG principal:</strong> <span id="conf_tag_principal">—</span></li>
                      <li><strong>Precio total:</strong> $<span id="conf_precio">0.00</span></li>
                      <li class="d-none" id="li_enganche"><strong>Enganche:</strong> $<span id="conf_enganche">0.00</span></li>
                      <li class="d-none" id="li_financiera"><strong>Financiera:</strong> <span id="conf_financiera">—</span></li>
                      <li class="d-none" id="li_tag"><strong>TAG:</strong> <span id="conf_tag">—</span></li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>

            <hr>

            <div class="mb-3">
              <label for="password_confirm" class="form-label">
                Confirma con tu contraseña de acceso
              </label>
              <input
                type="password"
                class="form-control"
                id="password_confirm"
                name="password_confirm"
                form="form_venta"
                placeholder="Escribe tu contraseña"
                autocomplete="off" />

              <div class="form-text">
                Esta venta se registrará a nombre de <strong><?= htmlspecialchars($nombre_usuario) ?></strong>.
                Para continuar, confirma que eres tú ingresando tu contraseña.
              </div>
            </div>

            <div class="help-text">
              Si detectas un error, cierra este modal y corrige los datos. Si todo es correcto, confirma para enviar.
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">
              <i class="bi bi-pencil-square me-1"></i> Corregir
            </button>
            <button class="btn btn-primary" id="btn_confirmar_envio">
              <i class="bi bi-send-check me-1"></i> Confirmar y enviar
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal de cupón -->
    <div class="modal fade" id="modalCupon" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-light">
            <h5 class="modal-title">
              <i class="bi bi-ticket-perforated text-success me-2"></i>
              Cupón de descuento disponible
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <p class="mb-2">
              El producto seleccionado tiene un <strong>cupón de descuento</strong> por:
            </p>
            <h4 class="text-success">
              $<span id="modal_cupon_monto">0.00</span> MXN
            </h4>
            <p class="mt-3 mb-0 small text-muted">
              Si aplicas el cupón, el total de la venta se actualizará con este descuento.
            </p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" id="btn_no_aplicar_cupon" data-bs-dismiss="modal">
              No aplicar
            </button>
            <button type="button" class="btn btn-success" id="btn_aplicar_cupon">
              Aplicar cupón
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal enganche -->
    <div class="modal fade" id="modalEngancheInfo" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-light py-2">
            <h6 class="modal-title">
              <i class="bi bi-exclamation-circle text-warning me-1"></i>
              Enganche
            </h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <p class="mb-0 small">
              <strong>Recuerda:</strong><br>
              El enganche que se captura debe ser el <strong>COBRADO AL CLIENTE</strong>, NO el solicitado por la financiera.
            </p>
          </div>
          <div class="modal-footer py-2">
            <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">
              Cerrar
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal clientes -->
    <div class="modal fade" id="modalClientes" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header bg-light">
            <h5 class="modal-title">
              <i class="bi bi-people me-2 text-primary"></i>Buscar o crear cliente
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Buscar por nombre, teléfono o código de cliente</label>
              <div class="input-group">
                <input type="text" class="form-control" id="cliente_buscar_q" placeholder="Ej. LUCIA, 5587967699 o CL-40-000001">
                <button class="btn btn-primary" type="button" id="btn_buscar_modal">
                  <i class="bi bi-search"></i> Buscar
                </button>
              </div>
              <div class="form-text">
                La búsqueda se realiza a nivel <strong>global.</strong>
              </div>
            </div>

            <hr>

            <div class="mb-2 d-flex justify-content-between align-items-center">
              <span class="fw-semibold">Resultados</span>
              <span class="text-muted small" id="lbl_resultados_clientes">Sin buscar aún.</span>
            </div>
            <div class="table-responsive mb-3">
              <table class="table table-sm align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Código</th>
                    <th>Nombre</th>
                    <th>Teléfono</th>
                    <th>Correo</th>
                    <th>Fecha alta</th>
                    <th>Sucursal</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody id="tbody_clientes"></tbody>
              </table>
            </div>

            <hr>

            <div class="mb-2">
              <button class="btn btn-outline-success btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNuevoCliente">
                <i class="bi bi-person-plus me-1"></i> Crear nuevo cliente
              </button>
            </div>
            <div class="collapse" id="collapseNuevoCliente">
              <div class="border rounded-3 p-3 bg-light">
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label req">Nombre completo</label>
                    <input type="text" class="form-control" id="nuevo_nombre">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label req">Teléfono (10 dígitos)</label>
                    <input type="text" class="form-control" id="nuevo_telefono">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Correo</label>
                    <input type="email" class="form-control" id="nuevo_correo">
                  </div>
                </div>
                <div class="mt-3 text-end">
                  <button type="button" class="btn btn-success" id="btn_guardar_nuevo_cliente">
                    <i class="bi bi-check2-circle me-1"></i> Guardar y seleccionar
                  </button>
                </div>
                <div class="form-text">
                  El cliente se creará en la sucursal seleccionada en el formulario.
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
              Cerrar
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ✅ Modal Promo 2do con descuento -->
    <div class="modal fade" id="modalPromoDesc" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-light">
            <h5 class="modal-title">
              <i class="bi bi-badge-percent text-primary me-2"></i>
              Seleccionar promoción
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <div class="mb-2">
              <div class="small text-muted">
                Elige la promo que aplicará el <strong>% de descuento</strong>.
              </div>
            </div>

            <label class="form-label req">Promoción</label>
            <select id="promo_desc_select" class="form-control">
              <option value="">Cargando...</option>
            </select>

            <div class="mt-3 small text-muted">
              <strong>Tip:</strong> si cambias el equipo principal o la sucursal, la promo se reinicia para evitar inconsistencias.
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" id="btn_cancel_promo_desc" data-bs-dismiss="modal">
              Cancelar
            </button>
            <button type="button" class="btn btn-primary" id="btn_aplicar_promo_desc">
              Aplicar
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Bootstrap JS (necesario para Modals) -->
    <!-- Si tu navbar ya incluye bootstrap.bundle, puedes dejar esto comentado.
         Si NO, descoméntalo para evitar "bootstrap is not defined". -->
    <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

    <script>
      $(document).ready(function() {
        const idSucursalUsuario = <?= $id_sucursal_usuario ?>;
        const mapaSucursales = <?= json_encode($mapSuc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        const modalConfirm = new bootstrap.Modal(document.getElementById('modalConfirmacion'));
        const modalClientes = new bootstrap.Modal(document.getElementById('modalClientes'));
        const modalCupon = new bootstrap.Modal(document.getElementById('modalCupon'));
        const modalEngancheInfo = new bootstrap.Modal(document.getElementById('modalEngancheInfo'));
        const modalPromoDesc = new bootstrap.Modal(document.getElementById('modalPromoDesc'));

        // ✅ Permiso por rol (Subdis_*) desde PHP (data-attribute)
        const precioLibrePorRol = ($('#precio_venta').data('precio-libre-rol') == 1);

        function puedeEditarPrecio() {
          return !!precioLibrePorRol;
        }

        const sucursalesPrecioLibre = (String($('#precio_venta').data('sucursales-libre') || ''))
          .split(',')
          .map(s => parseInt(s.trim(), 10))
          .filter(n => !isNaN(n));

        function esSucursalPrecioLibre(idSucursal) {
          const id = parseInt(idSucursal, 10);
          if (isNaN(id)) return false;
          return sucursalesPrecioLibre.includes(id);
        }

        let precioEditadoManualmente = false;
        $('#precio_venta').on('input', function() {
          if (puedeEditarPrecio()) precioEditadoManualmente = true;
        });

        // Enganche modal una vez
        let engancheModalShown = false;
        $('#enganche').on('focus', function() {
          if (!engancheModalShown) {
            engancheModalShown = true;
            modalEngancheInfo.show();
          }
        });

        // Cupón
        let cuponDisponible = 0;
        let cuponAplicado = false;

        // ===== 🎁 Promo regalo (2x1) =====
        let promoRegaloAplica = false;
        let promoRegaloId = null;
        let promoRegaloNombre = '';
        let tipoVentaPrevio = '';

        // ===== ✅ Promo 2do con descuento =====
        let promoDescEligible = false;          // si equipo1 está en equipo_descuento_principal (según backend)
        let promoDescList = [];                 // promos disponibles para elegir
        let promoDescNombre = '';
        let promoDescPct = 0;

        function resetPromoDescUI(hardOff = true) {
          promoDescEligible = false;
          promoDescList = [];
          promoDescNombre = '';
          promoDescPct = 0;

          $('#wrap_promo_desc').addClass('d-none');
          $('#chk_descuento2').prop('checked', false);
          $('#btn_elegir_promo_desc').addClass('d-none');
          $('#btn_quitar_promo_desc').addClass('d-none');
          $('#pill_promo_desc').addClass('d-none');
          $('#pill_promo_desc_txt').text('—');

          // hidden
          if (hardOff) {
            $('#promo_desc_aplicado').val('0');
            $('#promo_desc_id').val('0');
            $('#promo_desc_pct').val('0');
            $('#tag_venta_principal').val('');
          }

          // UI campo tag principal
          $('#tag_principal_field').addClass('d-none');
          $('#tag_principal').val('');
          $('#hint_desc_tag_principal').addClass('d-none');
        }

        function setPromoDescApplied(promoId, nombre, pct) {
          $('#promo_desc_aplicado').val('1');
          $('#promo_desc_id').val(String(promoId || 0));
          $('#promo_desc_pct').val(String(pct || 0));

          promoDescNombre = nombre || 'Promo';
          promoDescPct = parseFloat(pct) || 0;

          $('#pill_promo_desc_txt').text(`${promoDescNombre} (${promoDescPct.toFixed(0)}%)`);
          $('#pill_promo_desc').removeClass('d-none');

          $('#btn_elegir_promo_desc').removeClass('d-none');
          $('#btn_quitar_promo_desc').removeClass('d-none');

          // Si es Financiamiento (2da venta separada), pedir tag principal
          if ($('#tipo_venta').val() === 'Financiamiento') {
            $('#tag_principal_field').removeClass('d-none');
            $('#hint_desc_tag_principal').removeClass('d-none');
          } else {
            $('#tag_principal_field').addClass('d-none');
            $('#hint_desc_tag_principal').addClass('d-none');
          }

          recalcPrecioVenta();
        }

        function clearPromoDescAppliedOnly() {
          $('#promo_desc_aplicado').val('0');
          $('#promo_desc_id').val('0');
          $('#promo_desc_pct').val('0');
          $('#tag_venta_principal').val('');

          promoDescNombre = '';
          promoDescPct = 0;

          $('#pill_promo_desc').addClass('d-none');
          $('#pill_promo_desc_txt').text('—');

          $('#tag_principal_field').addClass('d-none');
          $('#hint_desc_tag_principal').addClass('d-none');
          $('#tag_principal').val('');

          recalcPrecioVenta();
        }

        // ===== Promo regalo: reset =====
        function resetPromoRegaloUI() {
          promoRegaloAplica = false;
          promoRegaloId = null;
          promoRegaloNombre = '';

          $('#wrap_promo_regalo').addClass('d-none');
          $('#promo_regalo_nombre').text('—');
          $('#chk_regalo').prop('checked', false);

          $('#es_regalo').val('0');
          $('#id_promo_regalo').val('');
          $('#promo_regalo_aplicado').val('0');
          $('#promo_regalo_id').val('0');

          $('#tipo_venta')
            .removeClass('select-lock')
            .removeAttr('data-locked');

          tipoVentaPrevio = '';
        }

        function aplicarEstadoRegaloUI(isOn) {
          if (isOn) {
            // ❌ No mezclar regalo con promo descuento
            if ($('#promo_desc_aplicado').val() === '1' || $('#chk_descuento2').is(':checked')) {
              $('#chk_regalo').prop('checked', false);
              alert('No puedes combinar Promo Regalo con Promo 2do con descuento.');
              return;
            }

            if ($('#tipo_venta').val() !== 'Financiamiento+Combo') {
              tipoVentaPrevio = $('#tipo_venta').val() || '';
            }

            $('#tipo_venta').val('Financiamiento+Combo').trigger('change');

            $('#tipo_venta')
              .addClass('select-lock')
              .attr('data-locked', '1');

            $('#combo').show();

            $('#es_regalo').val('1');
            $('#id_promo_regalo').val(promoRegaloId ? String(promoRegaloId) : '');
            $('#promo_regalo_aplicado').val('1');
            $('#promo_regalo_id').val(promoRegaloId ? String(promoRegaloId) : '0');

            const suc = $('#id_sucursal').val();
            const invPrincipal = $('#equipo1').val();

            $.ajax({
              url: 'ajax_promo_regalo_combos.php',
              method: 'POST',
              data: {
                promo_id: promoRegaloId,
                id_sucursal: suc,
                exclude_inventario: invPrincipal
              },
              success: function(html) {
                $('#equipo2').html(html).val('').trigger('change');
                refreshEquipoLocks();
                recalcPrecioVenta();
              },
              error: function(xhr) {
                console.warn('No se pudo filtrar combos promo:', xhr.responseText || xhr.statusText);
              }
            });

          } else {
            cargarEquipos($('#id_sucursal').val());

            $('#es_regalo').val('0');
            $('#id_promo_regalo').val('');
            $('#promo_regalo_aplicado').val('0');
            $('#promo_regalo_id').val('0');

            $('#tipo_venta')
              .removeClass('select-lock')
              .removeAttr('data-locked');

            if (tipoVentaPrevio) {
              $('#tipo_venta').val(tipoVentaPrevio).trigger('change');
            }
            tipoVentaPrevio = '';
          }

          refreshEquipoLocks();
          recalcPrecioVenta();
        }

        $('#chk_regalo').on('change', function() {
          const on = $(this).is(':checked');
          if (on) {
            if (!promoRegaloAplica || !promoRegaloId) {
              $(this).prop('checked', false);
              return;
            }
          }
          aplicarEstadoRegaloUI(on);
        });

        // ===== Candado UI =====
        function setLockedUI(locked, msgHtml) {
          const $banner = $('#banner_candado');
          const $form = $('#form_venta');
          const $btn = $('#btn_submit');

          if (locked) {
            $banner.removeClass('d-none').addClass('d-block');
            if (msgHtml) $banner.find('#candado_msg').html(msgHtml);
            $form.attr('data-locked', '1');
            $form.find('input,select,textarea,button').prop('disabled', true);
            $btn.prop('disabled', true).text('Bloqueado por corte pendiente');
          } else {
            $banner.removeClass('d-block').addClass('d-none');
            $form.attr('data-locked', '0');
            $form.find('input,select,textarea,button').prop('disabled', false);
            $btn.prop('disabled', false).html('<i class="bi bi-check2-circle me-2"></i> Registrar Venta');
          }
        }

        <?php if ($bloquearInicial): ?>
          setLockedUI(true);
        <?php else: ?>
          setLockedUI(false);
        <?php endif; ?>

        // Select2
        $('.select2-equipo').select2({
          placeholder: "Buscar por modelo, IMEI1 o IMEI2",
          allowClear: true,
          width: '100%'
        });

        function isFinanciamiento() {
          const tipo = $('#tipo_venta').val();
          return (tipo === 'Financiamiento' || tipo === 'Financiamiento+Combo');
        }

        function isFinanciamientoCombo() {
          return $('#tipo_venta').val() === 'Financiamiento+Combo';
        }

        // ===== Cliente =====
        function limpiarCliente() {
          $('#id_cliente').val('');
          $('#nombre_cliente').val('');
          $('#telefono_cliente').val('');
          $('#correo_cliente').val('');

          $('#cliente_resumen_nombre').text('Ninguno seleccionado');
          $('#cliente_resumen_detalle').html('Usa el botón <strong>Buscar / crear cliente</strong> para seleccionar uno.');
          $('#badge_tipo_cliente')
            .removeClass('text-bg-success')
            .addClass('text-bg-secondary')
            .html('<i class="bi bi-person-dash me-1"></i> Sin cliente');
        }

        function setClienteSeleccionado(c) {
          $('#id_cliente').val(c.id || '');
          $('#nombre_cliente').val(c.nombre || '');
          $('#telefono_cliente').val(c.telefono || '');
          $('#correo_cliente').val(c.correo || '');

          const nombre = c.nombre || '(Sin nombre)';
          const detParts = [];
          if (c.telefono) detParts.push('Tel: ' + c.telefono);
          if (c.codigo_cliente) detParts.push('Código: ' + c.codigo_cliente);
          if (c.correo) detParts.push('Correo: ' + c.correo);

          $('#cliente_resumen_nombre').text(nombre);
          $('#cliente_resumen_detalle').text(detParts.join(' · ') || 'Sin más datos.');

          $('#badge_tipo_cliente')
            .removeClass('text-bg-secondary')
            .addClass('text-bg-success')
            .html('<i class="bi bi-person-check me-1"></i> Cliente seleccionado');
        }

        $('#btn_open_modal_clientes').on('click', function() {
          $('#cliente_buscar_q').val('');
          $('#tbody_clientes').empty();
          $('#lbl_resultados_clientes').text('Sin buscar aún.');
          $('#collapseNuevoCliente').removeClass('show');
          modalClientes.show();
        });

        $('#btn_buscar_modal').on('click', function() {
          const q = $('#cliente_buscar_q').val().trim();
          const idSucursal = $('#id_sucursal').val();

          if (!q) {
            alert('Escribe algo para buscar (nombre, teléfono o código).');
            return;
          }

          $.post('ajax_clientes_buscar_modal.php', {
            q: q,
            id_sucursal: idSucursal
          }, function(res) {
            if (!res || !res.ok) {
              alert(res && res.message ? res.message : 'No se pudo buscar clientes.');
              return;
            }

            const clientes = res.clientes || [];
            const $tbody = $('#tbody_clientes');
            $tbody.empty();

            if (clientes.length === 0) {
              $('#lbl_resultados_clientes').text('Sin resultados. Puedes crear un cliente nuevo.');
              return;
            }

            $('#lbl_resultados_clientes').text('Se encontraron ' + clientes.length + ' cliente(s).');

            clientes.forEach(function(c) {
              const $tr = $('<tr>');

              if (parseInt(c.id_sucursal, 10) === idSucursalUsuario) {
                $tr.addClass('table-success');
              }

              $tr.append($('<td>').text(c.codigo_cliente || '—'));
              $tr.append($('<td>').text(c.nombre || ''));
              $tr.append($('<td>').text(c.telefono || ''));
              $tr.append($('<td>').text(c.correo || ''));
              $tr.append($('<td>').text(c.fecha_alta || ''));
              $tr.append($('<td>').text(c.sucursal_nombre || '—'));

              const $btnSel = $('<button type="button" class="btn btn-sm btn-primary">')
                .html('<i class="bi bi-check2-circle me-1"></i> Seleccionar')
                .data('cliente', c)
                .on('click', function() {
                  const cliente = $(this).data('cliente');
                  setClienteSeleccionado(cliente);
                  modalClientes.hide();
                });

              $tr.append($('<td>').append($btnSel));
              $tbody.append($tr);
            });
          }, 'json').fail(function() {
            alert('Error al buscar en la base de clientes.');
          });
        });

        $('#cliente_buscar_q').on('keydown', function(e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            $('#btn_buscar_modal').click();
          }
        });

        $('#btn_guardar_nuevo_cliente').on('click', function() {
          const nombre = $('#nuevo_nombre').val().trim();
          let tel = $('#nuevo_telefono').val().trim();
          const correo = $('#nuevo_correo').val().trim();
          const idSucursal = $('#id_sucursal').val();

          if (!nombre) {
            alert('Captura el nombre del cliente.');
            return;
          }
          tel = tel.replace(/\D+/g, '');
          if (!/^\d{10}$/.test(tel)) {
            alert('El teléfono debe tener exactamente 10 dígitos.');
            return;
          }

          $.post('ajax_crear_cliente.php', {
            nombre: nombre,
            telefono: tel,
            correo: correo,
            id_sucursal: idSucursal
          }, function(res) {
            if (!res || !res.ok) {
              alert(res && res.message ? res.message : 'No se pudo guardar el cliente.');
              return;
            }

            const c = res.cliente || {};
            setClienteSeleccionado(c);
            modalClientes.hide();

            $('#nuevo_nombre').val('');
            $('#nuevo_telefono').val('');
            $('#nuevo_correo').val('');
            $('#collapseNuevoCliente').removeClass('show');

            alert(res.message || 'Cliente creado y vinculado.');
          }, 'json').fail(function(xhr) {
            alert('Error al guardar el cliente: ' + (xhr.responseText || 'desconocido'));
          });
        });

        // ===== Cupón UI =====
        function actualizarInfoCupon() {
          if (cuponAplicado && cuponDisponible > 0) {
            $('#txt_cupon_info').removeClass('d-none');
            $('#lbl_cupon_monto').text(cuponDisponible.toFixed(2));
            $('#monto_cupon').val(cuponDisponible.toFixed(2));
          } else {
            $('#txt_cupon_info').addClass('d-none');
            $('#lbl_cupon_monto').text('0.00');
            $('#monto_cupon').val('0');
          }
        }

        function actualizarBloqueoPrecio() {
          const esLibre = puedeEditarPrecio();
          $('#precio_venta').prop('readonly', !esLibre);

          if (esLibre) {
            $('#txt_precio_auto').addClass('d-none');
            $('#txt_precio_manual').removeClass('d-none');
          } else {
            $('#txt_precio_auto').removeClass('d-none');
            $('#txt_precio_manual').addClass('d-none');
            precioEditadoManualmente = false;
          }
        }

        // ✅ Calcula precio base para un option (combo usa precio_combo si existe, si no precio_lista)
        function getBasePriceFromOption($opt, preferCombo) {
          if (!$opt || !$opt.length) return 0;
          const pLista = parseFloat($opt.data('precio-lista')) || 0;
          const pCombo = parseFloat($opt.data('precio-combo'));
          if (preferCombo && !isNaN(pCombo) && pCombo > 0) return pCombo;
          return pLista;
        }

        // Recalcular precio total
        function recalcPrecioVenta() {
          let total = 0;
          const tipo = $('#tipo_venta').val();
          const esRegalo = ($('#es_regalo').val() === '1');
          const aplicaDesc = ($('#promo_desc_aplicado').val() === '1');
          const pct = parseFloat($('#promo_desc_pct').val()) || 0;

          const $opt1 = $('#equipo1').find('option:selected');
          if ($opt1.length && $opt1.val()) {
            let p1 = getBasePriceFromOption($opt1, false);

            // ✅ Si tipo=Financiamiento y es "2do con descuento", aplicar descuento al EQUIPO1
            if (tipo === 'Financiamiento' && aplicaDesc && pct > 0) {
              p1 = p1 * (1 - (pct / 100));
            }
            total += p1;
          }

          if (isFinanciamientoCombo()) {
            const $opt2 = $('#equipo2').find('option:selected');
            if ($opt2.length && $opt2.val()) {
              if (esRegalo) {
                total += 0;
              } else {
                let p2 = getBasePriceFromOption($opt2, true);

                // ✅ Si tipo=Financiamiento+Combo y aplica descuento, descuento al EQUIPO2
                if (aplicaDesc && pct > 0) {
                  p2 = p2 * (1 - (pct / 100));
                }
                total += p2;
              }
            }
          }

          if (cuponAplicado && cuponDisponible > 0) total = total - cuponDisponible;
          if (total < 0) total = 0;

          const esLibre = puedeEditarPrecio();
          if (!esLibre || !precioEditadoManualmente) {
            $('#precio_venta').val(total > 0 ? total.toFixed(2) : '');
          }

          const precio = parseFloat($('#precio_venta').val()) || 0;
          $('#conf_precio').text(precio.toFixed(2));
        }

        // Locks equipo1 != equipo2
        function refreshEquipoLocks() {
          const v1 = $('#equipo1').val();
          const v2 = $('#equipo2').val();

          $('#equipo1 option, #equipo2 option').prop('disabled', false);

          if (v1) $('#equipo2 option[value="' + v1 + '"]').prop('disabled', true);
          if (v2) $('#equipo1 option[value="' + v2 + '"]').prop('disabled', true);

          if (v1 && v2 && v1 === v2) {
            $('#equipo2').val(null).trigger('change');
          }
        }

        // ======= Promo 2do con descuento: cargar elegibilidad + promos =======
        function checkPromoDescuentoForEquipo1(idInventario) {
          // endpoint esperado:
          // ajax_promo_descuento_check.php { id_inventario } -> {ok, aplica, promos:[{id,nombre,pct}]}
          $.ajax({
            url: 'ajax_promo_descuento_check.php',
            method: 'POST',
            dataType: 'json',
            data: { id_inventario: idInventario },
            success: function(res) {
              if (res && res.ok && res.aplica && Array.isArray(res.promos) && res.promos.length) {
                promoDescEligible = true;
                promoDescList = res.promos;

                $('#wrap_promo_desc').removeClass('d-none');
                $('#btn_elegir_promo_desc').removeClass('d-none');

                // si ya estaba aplicada una promo pero ya no existe, la quitamos
                const currentId = parseInt($('#promo_desc_id').val(), 10) || 0;
                if (currentId) {
                  const exists = promoDescList.some(p => parseInt(p.id,10) === currentId);
                  if (!exists) clearPromoDescAppliedOnly();
                }

              } else {
                // no aplica
                resetPromoDescUI(true);
              }
            },
            error: function() {
              // si falla, no mostramos para no romper UX
              resetPromoDescUI(true);
            }
          });
        }

        function abrirModalPromoDesc() {
          // llenar select con promoDescList
          const $sel = $('#promo_desc_select');
          $sel.empty();

          if (!promoDescList.length) {
            $sel.append('<option value="">Sin promos disponibles</option>');
            return;
          }

          $sel.append('<option value="">Seleccione...</option>');
          promoDescList.forEach(p => {
            const id = parseInt(p.id, 10) || 0;
            const nombre = String(p.nombre || 'Promo');
            const pct = parseFloat(p.pct) || 0;
            $sel.append(`<option value="${id}" data-pct="${pct}">${nombre} (${pct.toFixed(0)}%)</option>`);
          });

          // preselección si ya existe
          const cur = $('#promo_desc_id').val();
          if (cur && cur !== '0') $sel.val(cur);

          modalPromoDesc.show();
        }

        $('#chk_descuento2').on('change', function() {
          const on = $(this).is(':checked');

          // no permitir si regalo activo
          if (on && $('#es_regalo').val() === '1') {
            $(this).prop('checked', false);
            alert('No puedes combinar Promo Regalo con Promo 2do con descuento.');
            return;
          }

          if (on) {
            if (!promoDescEligible) {
              $(this).prop('checked', false);
              return;
            }

            $('#btn_elegir_promo_desc').removeClass('d-none');
            $('#btn_quitar_promo_desc').removeClass('d-none');

            abrirModalPromoDesc();
          } else {
            clearPromoDescAppliedOnly();
            $('#btn_elegir_promo_desc').addClass('d-none');
            $('#btn_quitar_promo_desc').addClass('d-none');
          }

          recalcPrecioVenta();
        });

        $('#btn_elegir_promo_desc').on('click', function() {
          if (!promoDescEligible) return;
          abrirModalPromoDesc();
        });

        $('#btn_quitar_promo_desc').on('click', function() {
          $('#chk_descuento2').prop('checked', false);
          clearPromoDescAppliedOnly();
          $('#btn_elegir_promo_desc').removeClass('d-none');
          $('#btn_quitar_promo_desc').addClass('d-none');
        });

        $('#btn_aplicar_promo_desc').on('click', function() {
          const $opt = $('#promo_desc_select').find('option:selected');
          const promoId = parseInt($('#promo_desc_select').val(), 10) || 0;
          if (!promoId) {
            alert('Selecciona una promoción.');
            return;
          }

          const pct = parseFloat($opt.data('pct')) || 0;
          const nombre = ($opt.text() || '').trim();

          setPromoDescApplied(promoId, nombre, pct);
          modalPromoDesc.hide();
        });

        // Tag principal (solo UI). Se manda hidden al backend
        $('#tag_principal').on('input', function() {
          const v = ($(this).val() || '').trim();
          $('#tag_venta_principal').val(v);
        });

        // ===== Tipo venta change =====
        $('#tipo_venta').on('change', function() {
          if ($(this).attr('data-locked') === '1') {
            $(this).val('Financiamiento+Combo');
            return;
          }

          $('#combo').toggle(isFinanciamientoCombo());
          if (!isFinanciamientoCombo()) {
            $('#equipo2').val(null).trigger('change');
            $('#equipo1 option, #equipo2 option').prop('disabled', false);

            if ($('#es_regalo').val() === '1') resetPromoRegaloUI();
          }

          // Si cambiamos de tipo, si hay promo desc aplicada, ajustar campo tag principal
          if ($('#promo_desc_aplicado').val() === '1') {
            if ($('#tipo_venta').val() === 'Financiamiento') {
              $('#tag_principal_field').removeClass('d-none');
              $('#hint_desc_tag_principal').removeClass('d-none');
            } else {
              $('#tag_principal_field').addClass('d-none');
              $('#hint_desc_tag_principal').addClass('d-none');
              $('#tag_principal').val('');
              $('#tag_venta_principal').val('');
            }
          }

          toggleVenta();
          refreshEquipoLocks();
          recalcPrecioVenta();
        });

        $('#forma_pago_enganche').on('change', function() {
          $('#mixto_detalle').toggle($(this).val() === 'Mixto' && isFinanciamiento());
        });

        function toggleVenta() {
          const esFin = isFinanciamiento();
          $('#tag_field, #enganche_field, #plazo_field, #financiera_field').toggle(esFin);
          $('#mixto_detalle').toggle(esFin && $('#forma_pago_enganche').val() === 'Mixto');
          $('#label_forma_pago').text(esFin ? 'Forma de Pago Enganche' : 'Forma de Pago');

          $('#tag').prop('required', esFin);
          $('#enganche').prop('required', esFin);
          $('#plazo_semanas').prop('required', esFin);
          $('#financiera').prop('required', esFin);

          $('#precio_venta').prop('required', true);
          $('#forma_pago_enganche').prop('required', true);

          if (!esFin) {
            $('#tag').val('');
            $('#enganche').val(0);
            $('#plazo_semanas').val(0);
            $('#financiera').val('');
            $('#enganche_efectivo').val(0);
            $('#enganche_tarjeta').val(0);

            // Si es contado, el tag principal no aplica
            $('#tag_principal_field').addClass('d-none');
            $('#tag_principal').val('');
            $('#tag_venta_principal').val('');
          }
        }
        toggleVenta();

        // ===== Cargar equipos (se redefine) =====
        function cargarEquipos(sucursalId) { /* redefinido */ }

        // Cambio sucursal: aviso + candado + reset promos
        $('#id_sucursal').on('change', function() {
          const seleccionada = parseInt($(this).val());
          if (seleccionada !== idSucursalUsuario) $('#alerta_sucursal').removeClass('d-none');
          else $('#alerta_sucursal').addClass('d-none');

          cargarEquipos(seleccionada);

          $.post('ajax_check_corte.php', { id_sucursal: seleccionada }, function(res) {
            if (!res || !res.ok) return;
            if (res.bloquear) {
              const html = `
                <strong>Captura bloqueada.</strong> ${res.motivo}
                <div class="small">Genera el corte de <strong>${res.ayer}</strong> para continuar.</div>
              `;
              setLockedUI(true, html);
            } else {
              setLockedUI(false);
            }
          }, 'json').fail(function() {
            console.warn('No se pudo verificar el candado por AJAX. El back-end seguirá validando.');
          });

          actualizarBloqueoPrecio();
          precioEditadoManualmente = false;

          // reset promos al cambiar sucursal
          resetPromoRegaloUI();
          resetPromoDescUI(true);

          recalcPrecioVenta();
        });

        // ========= Validación + modal =========
        let permitSubmit = false;

        function validarFormulario() {
          const errores = [];
          const esFin = isFinanciamiento();

          const idCliente = $('#id_cliente').val();
          const tel = $('#telefono_cliente').val().trim();
          const tag = $('#tag').val().trim();
          const tipo = $('#tipo_venta').val();

          const precio = parseFloat($('#precio_venta').val());
          const eng = parseFloat($('#enganche').val());
          const forma = $('#forma_pago_enganche').val();
          const plazo = parseInt($('#plazo_semanas').val(), 10);
          const finan = $('#financiera').val();

          if (!tipo) errores.push('Selecciona el tipo de venta.');
          if (!precio || precio <= 0) errores.push('El precio de venta debe ser mayor a 0.');
          if (!forma) errores.push('Selecciona la forma de pago.');
          if (!$('#equipo1').val()) errores.push('Selecciona el equipo principal.');

          if (!idCliente) errores.push('Debes seleccionar un cliente antes de registrar la venta.');
          if (!tel) errores.push('El cliente seleccionado debe tener teléfono.');
          else if (!/^\d{10}$/.test(tel)) errores.push('El teléfono del cliente debe tener 10 dígitos.');

          if (isFinanciamientoCombo()) {
            const v1 = $('#equipo1').val();
            const v2 = $('#equipo2').val();
            if (!v2) errores.push('Selecciona el equipo combo.');
            if (v1 && v2 && v1 === v2) errores.push('El equipo combo debe ser distinto del principal.');
          }

          const esRegalo = ($('#es_regalo').val() === '1');
          if (esRegalo && !$('#equipo2').val()) {
            errores.push('La promo de regalo requiere seleccionar el equipo combo (regalo).');
          }

          // ✅ Promo descuento: si checkbox encendido, debe existir promo elegida
          if ($('#chk_descuento2').is(':checked')) {
            const aplicado = ($('#promo_desc_aplicado').val() === '1');
            const promoId = parseInt($('#promo_desc_id').val(), 10) || 0;
            const pct = parseFloat($('#promo_desc_pct').val()) || 0;
            if (!aplicado || promoId <= 0 || pct <= 0) {
              errores.push('Selecciona una promo válida para "2do con descuento".');
            }

            // ✅ Si es Financiamiento (2da venta separada): pedir TAG principal
            if ($('#tipo_venta').val() === 'Financiamiento') {
              const tagP = ($('#tag_venta_principal').val() || '').trim();
              if (!tagP) errores.push('Captura el TAG de la venta principal para validar el descuento.');
            }
          }

          if (esFin) {
            if (!tag) errores.push('El TAG (ID del crédito) es obligatorio.');
            if (isNaN(eng) || eng < 0) errores.push('El enganche es obligatorio (puede ser 0, no negativo).');
            if (!plazo || plazo <= 0) errores.push('El plazo en semanas debe ser mayor a 0.');
            if (!finan) errores.push('Selecciona una financiera (no N/A).');

            if (forma === 'Mixto') {
              const ef = parseFloat($('#enganche_efectivo').val()) || 0;
              const tj = parseFloat($('#enganche_tarjeta').val()) || 0;
              if (ef <= 0 && tj <= 0) errores.push('En Mixto, al menos uno de los montos debe ser > 0.');
              if ((eng || 0).toFixed(2) !== (ef + tj).toFixed(2)) errores.push('Efectivo + Tarjeta debe igualar al Enganche.');
            }
          }

          return errores;
        }

        function poblarModal() {
          const idSucSel = $('#id_sucursal').val();
          $('#conf_sucursal').text(mapaSucursales[idSucSel] || '—');

          const tipo = $('#tipo_venta').val() || '—';
          $('#conf_tipo').text(tipo);

          const equipo1Text = $('#equipo1').find('option:selected').text() || '—';
          const equipo2Text = $('#equipo2').find('option:selected').text() || '';

          $('#conf_equipo1').text(equipo1Text);
          if ($('#combo').is(':visible') && $('#equipo2').val()) {
            $('#conf_equipo2').text(equipo2Text);
            $('#li_equipo2').removeClass('d-none');
          } else {
            $('#li_equipo2').addClass('d-none');
          }

          // Promo descuento en confirmación
          if ($('#promo_desc_aplicado').val() === '1') {
            $('#conf_promo_desc').text($('#pill_promo_desc_txt').text() || '—');
            $('#li_promo_desc').removeClass('d-none');
          } else {
            $('#li_promo_desc').addClass('d-none');
          }

          const tagP = ($('#tag_venta_principal').val() || '').trim();
          if (tagP) {
            $('#conf_tag_principal').text(tagP);
            $('#li_tag_principal').removeClass('d-none');
          } else {
            $('#li_tag_principal').addClass('d-none');
          }

          const precio = parseFloat($('#precio_venta').val()) || 0;
          $('#conf_precio').text(precio.toFixed(2));

          const esFin = isFinanciamiento();
          if (esFin) {
            const eng = parseFloat($('#enganche').val()) || 0;
            $('#conf_enganche').text(eng.toFixed(2));
            $('#li_enganche').removeClass('d-none');

            const finan = $('#financiera').val() || '—';
            $('#conf_financiera').text(finan);
            $('#li_financiera').removeClass('d-none');

            const tag = ($('#tag').val() || '').trim();
            if (tag) {
              $('#conf_tag').text(tag);
              $('#li_tag').removeClass('d-none');
            } else {
              $('#li_tag').addClass('d-none');
            }
          } else {
            $('#li_enganche, #li_financiera, #li_tag').addClass('d-none');
          }
        }

        $('#form_venta').on('submit', function(e) {
          if ($('#form_venta').attr('data-locked') === '1') {
            e.preventDefault();
            $('html, body').animate({ scrollTop: 0 }, 300);
            return;
          }

          if (permitSubmit) return;
          e.preventDefault();

          const errores = validarFormulario();
          if (errores.length > 0) {
            $('#errores').removeClass('d-none')
              .html('<strong>Corrige lo siguiente:</strong><ul class="mb-0"><li>' + errores.join('</li><li>') + '</li></ul>');
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
          }

          $('#errores').addClass('d-none').empty();
          poblarModal();

          $('#password_confirm').val('');
          $('#btn_confirmar_envio')
            .prop('disabled', false)
            .html('<i class="bi bi-send-check me-1"></i> Confirmar y enviar');

          modalConfirm.show();
        });

        $('#btn_confirmar_envio').on('click', function() {
          const pwd = $('#password_confirm').val().trim();
          if (!pwd) {
            alert('Para confirmar la venta, escribe tu contraseña.');
            $('#password_confirm').focus();
            return;
          }

          $('#btn_confirmar_envio').prop('disabled', true).text('Confirmando...');
          $('#btn_submit').prop('disabled', true).text('Enviando...');

          permitSubmit = true;
          modalConfirm.hide();
          $('#form_venta')[0].submit();
        });

        // ===== Init Equipos =====
        function initEquipos() {
          cargarEquipos = function(sucursalId) {
            $.ajax({
              url: 'ajax_productos_por_sucursal.php',
              method: 'POST',
              data: { id_sucursal: sucursalId },
              success: function(response) {
                $('#equipo1, #equipo2').html(response).val('').trigger('change');

                cuponDisponible = 0;
                cuponAplicado = false;
                actualizarInfoCupon();

                resetPromoRegaloUI();
                resetPromoDescUI(true);

                refreshEquipoLocks();
                recalcPrecioVenta();
              },
              error: function(xhr) {
                const msg = xhr.responseText || 'Error cargando inventario';
                $('#equipo1, #equipo2').html('<option value="">' + msg + '</option>').trigger('change');

                cuponDisponible = 0;
                cuponAplicado = false;
                actualizarInfoCupon();

                resetPromoRegaloUI();
                resetPromoDescUI(true);

                refreshEquipoLocks();
                recalcPrecioVenta();
              }
            });
          };

          cargarEquipos($('#id_sucursal').val());
          refreshEquipoLocks();
          recalcPrecioVenta();
        }
        initEquipos();

        actualizarBloqueoPrecio();
        limpiarCliente();

        // ===== Eventos equipos (cupón + promo regalo + promo descuento) =====
        $('#equipo1').on('change', function() {
          const idInv = $('#equipo1').val();

          // reset cupón
          cuponDisponible = 0;
          cuponAplicado = false;
          actualizarInfoCupon();

          // reset promos
          resetPromoRegaloUI();
          resetPromoDescUI(true);

          if (!idInv) {
            refreshEquipoLocks();
            recalcPrecioVenta();
            return;
          }

          // 1) Cupón
          $.ajax({
            url: 'ajax_cupon_producto.php',
            method: 'POST',
            dataType: 'json',
            data: { id_inventario: idInv },
            success: function(res) {
              let d = 0;
              if (res && res.ok) d = parseFloat(res.monto_cupon) || 0;
              if (d > 0) {
                cuponDisponible = d;
                $('#modal_cupon_monto').text(d.toFixed(2));
                modalCupon.show();
              }
              refreshEquipoLocks();
              recalcPrecioVenta();
            },
            error: function() {
              refreshEquipoLocks();
              recalcPrecioVenta();
            }
          });

          // 2) Promo regalo
          $.ajax({
            url: 'ajax_promo_regalo_check.php',
            method: 'POST',
            dataType: 'json',
            data: { id_inventario: idInv },
            success: function(res) {
              if (res && res.ok && res.aplica && res.promo_id) {
                promoRegaloAplica = true;
                promoRegaloId = parseInt(res.promo_id, 10);
                promoRegaloNombre = res.nombre || 'Promo';
                $('#promo_regalo_nombre').text(promoRegaloNombre);
                $('#wrap_promo_regalo').removeClass('d-none');
              } else {
                resetPromoRegaloUI();
              }
            },
            error: function() {
              resetPromoRegaloUI();
            }
          });

          // 3) ✅ Promo 2do con descuento (elegibilidad + promos)
          checkPromoDescuentoForEquipo1(idInv);
        });

        $('#equipo2').on('change', function() {
          refreshEquipoLocks();
          recalcPrecioVenta();
        });

        $('#equipo2').on('select2:select', function(e) {
          const v1 = $('#equipo1').val();
          const elegido = e.params.data.id;
          if (v1 && elegido === v1) {
            $(this).val(null).trigger('change');
          }
          refreshEquipoLocks();
          recalcPrecioVenta();
        });

        $('#equipo1').on('select2:select', function() {
          refreshEquipoLocks();
          recalcPrecioVenta();
        });

        // Cupón modal botones
        $('#btn_aplicar_cupon').on('click', function() {
          if (cuponDisponible > 0) {
            cuponAplicado = true;
            actualizarInfoCupon();
            recalcPrecioVenta();
          }
          modalCupon.hide();
        });

        $('#btn_no_aplicar_cupon').on('click', function() {
          cuponAplicado = false;
          actualizarInfoCupon();
          recalcPrecioVenta();
        });

      });
    </script>

</body>
</html>
