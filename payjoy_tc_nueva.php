<?php
// payjoy_tc_nueva.php — Captura de TC PayJoy (comisión fija $100, móvil first) con cliente ligado
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';        // navbar global
require_once __DIR__ . '/config_features.php'; // feature flags

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUser  = trim($_SESSION['nombre'] ?? 'Usuario');

$isAdminLike = in_array($ROL, ['Admin','Super','SuperAdmin','RH'], true);

// Flag efectivo: abierto o, si está cerrado, permitir preview a Admin
$flagOpen = PAYJOY_TC_CAPTURE_OPEN || ($isAdminLike && PAYJOY_TC_ADMIN_PREVIEW);

// Banner cuando está apagado
$bannerMsg = PAYJOY_TC_CAPTURE_OPEN
  ? null
  : '⚠️ Aún no está disponible para captura. Esta pantalla es informativa; cuando se habilite podrás guardar.';

// Sucursales para selector (solo roles de gestión)
$sucursales = [];
if (in_array($ROL, ['Admin','Gerente','Gerente General','GerenteZona','GerenteSucursal'], true)) {
  $rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
  while ($r = $rs->fetch_assoc()) { $sucursales[] = $r; }
}

// Mapa id=>nombre (por si lo quieres usar luego)
$mapSuc = [];
if (!empty($sucursales)) {
  foreach ($sucursales as $s) {
    $mapSuc[(int)$s['id']] = $s['nombre'];
  }
} else {
  // si no tiene lista global, asumimos solo su sucursal actual
  $mapSuc[$idSucursal] = 'Sucursal actual';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Nueva venta TC PayJoy</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<style>
  :root{
    --card-radius: 18px;
  }
  body { background:#f7f8fa; }
  .page-wrap{ padding: 16px; padding-bottom: 100px; }
  .card-custom{
    border:none; border-radius: var(--card-radius);
    box-shadow: 0 8px 20px rgba(0,0,0,.06);
    background:#fff;
  }
  .section-title{ font-weight:700; letter-spacing:.2px; font-size:1rem; color:#334155; text-transform:uppercase; }
  .form-label{ font-weight:600; }
  .help-text{ font-size:.9rem; color:#6c757d; }

  .form-control, .form-select, .btn{
    border-radius:12px;
    padding:.8rem .95rem;
  }

  .action-bar{
    position: fixed; left:0; right:0; bottom:0;
    background:#ffffff; border-top:1px solid rgba(0,0,0,.08);
    padding:10px 16px; z-index:1030;
    box-shadow: 0 -6px 18px rgba(0,0,0,.06);
  }
  .action-bar .btn{ border-radius: 14px; padding:.9rem 1rem; font-weight:600; }

  @media (min-width: 992px){
    .page-wrap{ padding-bottom: 24px; }
    .action-bar{ position: static; box-shadow:none; border-top:none; margin-top: 8px; }
  }

  .header-chip{
    display:inline-flex; gap:.5rem; align-items:center;
    background:#eef5ff; color:#0a58ca; padding:.4rem .75rem; border-radius:999px;
    font-size:.9rem; font-weight:600;
  }

  .is-invalid + .invalid-feedback{ display:block; }

  .cliente-summary-label {
    font-size: .82rem;
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

  .badge-soft {
    background: #eef2ff;
    color: #1e40af;
    border: 1px solid #dbeafe;
  }
</style>
</head>
<body>
<div class="page-wrap container">
  <div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="header-chip">💳 PayJoy · Tarjeta de crédito</span>
    <span class="badge rounded-pill badge-soft">
      <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($nombreUser) ?> · TC PayJoy
    </span>
  </div>

  <?php if ($bannerMsg): ?>
    <div class="alert <?= $isAdminLike ? 'alert-warning' : 'alert-secondary' ?> mb-3">
      <?= htmlspecialchars($bannerMsg) ?>
      <?php if ($isAdminLike && PAYJOY_TC_ADMIN_PREVIEW): ?>
        <div class="small text-muted">Vista para Administrador habilitada por <code>PAYJOY_TC_ADMIN_PREVIEW</code>.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card card-custom p-3 p-md-4">
    <h3 class="section-title mb-2">Nueva venta</h3>
    <p class="help-text mb-3">
      Esta captura solo registra la venta de <strong>Tarjeta de Crédito PayJoy</strong> para comisiones. 
      La tarjeta quedará ligada al cliente que selecciones.
    </p>

    <?php if (isset($_GET['err'])): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($_GET['err']) ?></div>
    <?php endif; ?>

    <form id="formPayjoy" method="post" action="<?= $flagOpen ? 'payjoy_tc_guardar.php' : '#' ?>" class="row g-3 needs-validation" novalidate>
      <fieldset <?= $flagOpen ? '' : 'disabled' ?>>

        <!-- Hidden de cliente: igual que nueva_venta -->
        <input type="hidden" name="id_cliente" id="id_cliente" value="">
        <input type="hidden" name="nombre_cliente" id="nombre_cliente" value="">
        <input type="hidden" name="telefono_cliente" id="telefono_cliente" value="">
        <input type="hidden" name="correo_cliente" id="correo_cliente" value="">

        <!-- Sucursal -->
        <div class="col-12 col-lg-6">
          <label class="form-label">Sucursal</label>
          <?php if (!empty($sucursales)): ?>
            <select name="id_sucursal" id="id_sucursal" class="form-select" required>
              <option value="">— Selecciona —</option>
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ($idSucursal===(int)$s['id']?'selected':'') ?>>
                  <?= htmlspecialchars($s['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback">Selecciona una sucursal.</div>
          <?php else: ?>
            <input type="hidden" name="id_sucursal" id="id_sucursal" value="<?= $idSucursal ?>">
            <input type="text" class="form-control" value="Sucursal actual (ID: <?= $idSucursal ?>)" disabled>
          <?php endif; ?>
          <div class="form-text">La venta contará para la sucursal seleccionada.</div>
        </div>

        <!-- Usuario (informativo) -->
        <div class="col-12 col-lg-6">
          <label class="form-label">Usuario</label>
          <input type="text" class="form-control" value="<?= htmlspecialchars($nombreUser) ?> (ID: <?= $idUsuario ?>)" disabled>
        </div>

        <!-- Bloque de cliente (igual concepto que nueva_venta) -->
        <div class="col-12 mt-2">
          <div class="section-title mb-2"><i class="bi bi-people me-1"></i> Cliente</div>
          <div class="row g-3 align-items-center">
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
          <div class="form-text mt-1">
            La venta de PayJoy quedará ligada al cliente para futuros reportes y programas de lealtad.
          </div>
        </div>

        <!-- TAG -->
        <div class="col-12 col-lg-6 mt-3">
          <label class="form-label">TAG (ID / referencia PayJoy)</label>
          <input
            type="text"
            name="tag"
            id="tag"
            class="form-control"
            maxlength="60"
            inputmode="text"
            autocomplete="off"
            required
            placeholder="Ej. PJ-123ABC"
          >
          <div class="invalid-feedback">El TAG es obligatorio.</div>
        </div>

        <!-- Comentarios -->
        <div class="col-12 col-lg-6 mt-3">
          <label class="form-label">Comentarios (opcional)</label>
          <textarea
            name="comentarios"
            class="form-control"
            maxlength="255"
            rows="2"
            placeholder="Observaciones..."
          ></textarea>
        </div>
      </fieldset>

      <!-- Acciones -->
      <div class="action-bar d-flex gap-2">
        <a href="historial_payjoy_tc.php" class="btn btn-outline-secondary w-50">
          <i class="bi bi-clock-history me-1"></i> Historial
        </a>
        <button id="btnSubmit" type="submit" class="btn btn-success w-50" <?= $flagOpen ? '' : 'disabled' ?>>
          <?= $flagOpen ? 'Guardar venta' : 'No disponible' ?>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal de clientes: buscar / seleccionar / crear (copiado de nueva_venta) -->
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
        <!-- Buscador -->
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

        <!-- Resultados -->
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
                <th></th>
              </tr>
            </thead>
            <tbody id="tbody_clientes">
              <!-- JS -->
            </tbody>
          </table>
        </div>

        <hr>

        <!-- Crear nuevo cliente -->
        <div class="mb-2">
          <button class="btn btn-outline-success btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNuevoCliente">
            <i class="bi bi-person-plus me-1"></i> Crear nuevo cliente
          </button>
        </div>
        <div class="collapse" id="collapseNuevoCliente">
          <div class="border rounded-3 p-3 bg-light">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Nombre completo *</label>
                <input type="text" class="form-control" id="nuevo_nombre">
              </div>
              <div class="col-md-4">
                <label class="form-label">Teléfono (10 dígitos) *</label>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($flagOpen): ?>
<script>
(function(){
  const form   = document.getElementById('formPayjoy');
  const btn    = document.getElementById('btnSubmit');

  const inputIdCliente    = document.getElementById('id_cliente');
  const inputNomCliente   = document.getElementById('nombre_cliente');
  const inputTelCliente   = document.getElementById('telefono_cliente');
  const inputCorreoCliente= document.getElementById('correo_cliente');

  const lblNombre   = document.getElementById('cliente_resumen_nombre');
  const lblDetalle  = document.getElementById('cliente_resumen_detalle');
  const badgeCliente= document.getElementById('badge_tipo_cliente');

  const modalClientesEl = document.getElementById('modalClientes');
  const modalClientes   = new bootstrap.Modal(modalClientesEl);

  const idSucursalEl    = document.getElementById('id_sucursal');

  function limpiarCliente(){
    inputIdCliente.value      = '';
    inputNomCliente.value     = '';
    inputTelCliente.value     = '';
    inputCorreoCliente.value  = '';

    lblNombre.textContent = 'Ninguno seleccionado';
    lblDetalle.innerHTML  = 'Usa el botón <strong>Buscar / crear cliente</strong> para seleccionar uno.';
    badgeCliente.classList.remove('text-bg-success');
    badgeCliente.classList.add('text-bg-secondary');
    badgeCliente.innerHTML = '<i class="bi bi-person-dash me-1"></i> Sin cliente';
  }

  function setClienteSeleccionado(c){
    inputIdCliente.value      = c.id || '';
    inputNomCliente.value     = c.nombre || '';
    inputTelCliente.value     = c.telefono || '';
    inputCorreoCliente.value  = c.correo || '';

    const nombre = c.nombre || '(Sin nombre)';
    const detParts = [];
    if (c.telefono) detParts.push('Tel: ' + c.telefono);
    if (c.codigo_cliente) detParts.push('Código: ' + c.codigo_cliente);
    if (c.correo) detParts.push('Correo: ' + c.correo);

    lblNombre.textContent = nombre;
    lblDetalle.textContent = detParts.join(' · ') || 'Sin más datos.';

    badgeCliente.classList.remove('text-bg-secondary');
    badgeCliente.classList.add('text-bg-success');
    badgeCliente.innerHTML = '<i class="bi bi-person-check me-1"></i> Cliente seleccionado';
  }

  // Abrir modal clientes
  document.getElementById('btn_open_modal_clientes').addEventListener('click', function(){
    document.getElementById('cliente_buscar_q').value = '';
    document.getElementById('tbody_clientes').innerHTML = '';
    document.getElementById('lbl_resultados_clientes').textContent = 'Sin buscar aún.';
    document.getElementById('collapseNuevoCliente').classList.remove('show');
    modalClientes.show();
  });

  // Buscar clientes
  document.getElementById('btn_buscar_modal').addEventListener('click', function(){
    const q = document.getElementById('cliente_buscar_q').value.trim();
    const idSucursal = idSucursalEl ? idSucursalEl.value : '';

    if (!q) {
      alert('Escribe algo para buscar (nombre, teléfono o código).');
      return;
    }

    $.post('ajax_clientes_buscar_modal.php', {
      q: q,
      id_sucursal: idSucursal
    }, function(res){
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

      clientes.forEach(function(c){
        const $tr = $('<tr>');
        $tr.append($('<td>').text(c.codigo_cliente || '—'));
        $tr.append($('<td>').text(c.nombre || ''));
        $tr.append($('<td>').text(c.telefono || ''));
        $tr.append($('<td>').text(c.correo || ''));
        $tr.append($('<td>').text(c.fecha_alta || ''));
        const $btnSel = $('<button type="button" class="btn btn-sm btn-primary">')
          .html('<i class="bi bi-check2-circle me-1"></i> Seleccionar')
          .data('cliente', c)
          .on('click', function(){
            const cliente = $(this).data('cliente');
            setClienteSeleccionado(cliente);
            modalClientes.hide();
          });
        $tr.append($('<td>').append($btnSel));
        $tbody.append($tr);
      });
    }, 'json').fail(function(){
      alert('Error al buscar en la base de clientes.');
    });
  });

  // Crear cliente desde modal
  document.getElementById('btn_guardar_nuevo_cliente').addEventListener('click', function(){
    const nombre = document.getElementById('nuevo_nombre').value.trim();
    let tel      = document.getElementById('nuevo_telefono').value.trim();
    const correo = document.getElementById('nuevo_correo').value.trim();
    const idSucursal = idSucursalEl ? idSucursalEl.value : '';

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
    }, function(res){
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
    }, 'json').fail(function(xhr){
      alert('Error al guardar el cliente: ' + (xhr.responseText || 'desconocido'));
    });
  });

  // Limpieza inicial
  limpiarCliente();

  // Validación de formulario + anti doble clic
  form.addEventListener('submit', function(e){
    // validar cliente
    const idCli = parseInt(inputIdCliente.value || '0', 10);
    if (!idCli || idCli <= 0) {
      e.preventDefault();
      e.stopPropagation();
      alert('Debes seleccionar o crear un cliente antes de guardar la venta de PayJoy.');
      return;
    }

    if (!form.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
    } else {
      btn.disabled = true;
      btn.innerText = 'Guardando...';
    }
    form.classList.add('was-validated');
  }, false);

})();
</script>
<?php endif; ?>
</body>
</html>
