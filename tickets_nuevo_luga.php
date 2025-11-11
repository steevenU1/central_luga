<?php
// tickets_nuevo_luga.php — Crear ticket dentro de LUGA (origen = LUGA)
// - Form en modal con bloqueo de cierre accidental (backdrop estático)
// - Autoguardado de borrador en localStorage
// - Badges de estado reutilizables (pills con color)
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','Logistica','Gerente','Ejecutivo']; // ajusta si quieres limitarlo
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/navbar.php')) require_once __DIR__ . '/navbar.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
date_default_timezone_set('America/Mexico_City');

// CSRF anti doble-submit
if (empty($_SESSION['ticket_csrf_luga'])) {
  $_SESSION['ticket_csrf_luga'] = bin2hex(random_bytes(16));
}

// datos de sesión
$idUsuario   = (int)($_SESSION['id_usuario']  ?? 0);
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUser  = (string)($_SESSION['nombre'] ?? 'Usuario');

// catálogo de sucursales (por si permites elegir otra)
$sucursales = [];
$q = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
if ($q) { while($r = $q->fetch_assoc()){ $sucursales[(int)$r['id']] = $r['nombre']; } }

// flash
$flash_ok  = $_SESSION['flash_ok']  ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Nuevo ticket (LUGA)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    /* ===== Pills (badges) de estado reutilizables ===== */
    .badge-estado { font-weight: 600; padding: .5rem .6rem; border-radius: 50rem; }
    .badge-estado.abierto     { background:#0d6efd; color:#fff; }
    .badge-estado.enproceso   { background:#ffc107; color:#212529; }
    .badge-estado.espera      { background:#6c757d; color:#fff; } /* esperando info/cliente */
    .badge-estado.resuelto    { background:#198754; color:#fff; }
    .badge-estado.cerrado     { background:#212529; color:#fff; }
    .badge-estado.cancelado   { background:#dc3545; color:#fff; }

    /* Área de texto con altura cómoda */
    textarea.form-control { min-height: 180px; }

    /* Evitar scroll-jumps del body al abrir el modal */
    body.modal-open { overflow-y: auto; padding-right: 0 !important; }
  </style>
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Tickets LUGA</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="tickets_operador.php">← Operador</a>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoTicket">
        + Nuevo ticket
      </button>
    </div>
  </div>

  <?php if ($flash_ok): ?><div class="alert alert-success"><?=h($flash_ok)?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="alert alert-danger"><?=h($flash_err)?></div><?php endif; ?>

  <!-- Leyenda de estados (puedes quitarla de esta vista y dejar solo los estilos) -->
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <span class="text-muted small me-2">Leyenda de estados:</span>
        <span class="badge-estado abierto">Abierto</span>
        <span class="badge-estado enproceso">En proceso</span>
        <span class="badge-estado espera">En espera</span>
        <span class="badge-estado resuelto">Resuelto</span>
        <span class="badge-estado cerrado">Cerrado</span>
        <span class="badge-estado cancelado">Cancelado</span>
      </div>
    </div>
  </div>

  <!-- Aquí podrías listar tickets, etc. -->

</div>

<!-- ============================= -->
<!-- Modal: Nuevo Ticket (backdrop estático) -->
<!-- ============================= -->
<div class="modal fade" id="modalNuevoTicket" tabindex="-1" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5">Nuevo ticket (LUGA)</h2>
        <!-- ÚNICA forma de cerrar manual: la X -->
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="tickets_guardar_luga.php" id="formTicket" novalidate>
          <input type="hidden" name="csrf" value="<?=h($_SESSION['ticket_csrf_luga'])?>">

          <div class="mb-3">
            <label class="form-label">Asunto <span class="text-danger">*</span></label>
            <input type="text" name="asunto" id="asunto" class="form-control" maxlength="255" required
                   placeholder="Ej. Alta de usuario en sistema X" autocomplete="off">
            <div class="invalid-feedback">Escribe el asunto.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Mensaje inicial <span class="text-danger">*</span></label>
            <textarea name="mensaje" id="mensaje" class="form-control" rows="8" required
                      placeholder="Describe el problema o solicitud con el mayor detalle posible."></textarea>
            <div class="invalid-feedback">Escribe el detalle del ticket.</div>
          </div>

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Prioridad</label>
              <select name="prioridad" id="prioridad" class="form-select">
                <option value="baja">Baja</option>
                <option value="media" selected>Media</option>
                <option value="alta">Alta</option>
                <option value="critica">Crítica</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Sucursal origen</label>
              <select name="sucursal_origen_id" id="sucursal_origen_id" class="form-select">
                <?php foreach ($sucursales as $id => $nom): ?>
                  <option value="<?=$id?>" <?=$id==$idSucursal?'selected':''?>><?=h($nom)?> (<?=$id?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Usuario</label>
              <input type="text" class="form-control" value="<?=h($nombreUser)?>" disabled>
              <div class="form-text">ID: <?=h((string)$idUsuario)?></div>
            </div>
          </div>

          <div class="d-flex gap-2 mt-4">
            <button class="btn btn-primary" id="btnEnviar" type="submit">Crear ticket</button>
            <!-- Botón cancelar también cierra el modal, pero confirma si hay cambios -->
            <button type="button" class="btn btn-outline-secondary" id="btnCancelar" data-bs-dismiss="modal">Cancelar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ===== Autoguardado de borrador =====
(function(){
  const form          = document.getElementById('formTicket');
  const asunto        = document.getElementById('asunto');
  const mensaje       = document.getElementById('mensaje');
  const prioridad     = document.getElementById('prioridad');
  const sucursalSel   = document.getElementById('sucursal_origen_id');
  const btnEnviar     = document.getElementById('btnEnviar');
  const btnCancelar   = document.getElementById('btnCancelar');
  const modalEl       = document.getElementById('modalNuevoTicket');
  const modal         = new bootstrap.Modal(modalEl, { backdrop:'static', keyboard:false });
  const STORAGE_KEY   = 'draft_ticket_luga';

  // Abrir modal de inmediato (opcional). Si prefieres que lo abran con el botón, comenta la línea:
  // modal.show();

  // Restaurar borrador si existe
  function restoreDraft(){
    try{
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      const d = JSON.parse(raw);
      if (typeof d !== 'object' || d === null) return;
      if (d.asunto) asunto.value = d.asunto;
      if (d.mensaje) mensaje.value = d.mensaje;
      if (d.prioridad) prioridad.value = d.prioridad;
      if (d.sucursal_origen_id && sucursalSel.querySelector(`option[value="${d.sucursal_origen_id}"]`)) {
        sucursalSel.value = d.sucursal_origen_id;
      }
    }catch(e){}
  }

  // Guardar borrador
  function saveDraft(){
    const payload = {
      asunto: asunto.value || '',
      mensaje: mensaje.value || '',
      prioridad: prioridad.value || 'media',
      sucursal_origen_id: sucursalSel.value || ''
    };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
  }

  // Limpiar borrador
  function clearDraft(){ localStorage.removeItem(STORAGE_KEY); }

  // Eventos de autoguardado
  [asunto, mensaje].forEach(el => {
    el.addEventListener('input', saveDraft);
    el.addEventListener('change', saveDraft);
  });
  [prioridad, sucursalSel].forEach(el => el.addEventListener('change', saveDraft));

  // Restaurar al mostrar el modal
  modalEl.addEventListener('shown.bs.modal', restoreDraft);

  // Confirmación al cerrar si hay contenido
  function hasChanges(){
    return (asunto.value.trim() !== '' || mensaje.value.trim() !== '');
  }

  modalEl.addEventListener('hide.bs.modal', function (ev) {
    // Se permite cerrar solo por X o por botón Cancelar (ambos disparan hide)
    // Si hay cambios, confirmamos
    if (hasChanges()) {
      const ok = confirm('Hay texto sin guardar. ¿Deseas cerrar el ticket y perder el borrador?');
      if (!ok) { ev.preventDefault(); }
      else { /* mantenemos borrador por si reabren */ }
    }
  });

  // Cancelar: mismo comportamiento que X
  btnCancelar?.addEventListener('click', function(e){
    // El cierre real lo maneja el evento hide.bs.modal (arriba)
  });

  // Envío con validación + bloqueo de doble submit + limpieza de borrador
  form.addEventListener('submit', function(e){
    if (!form.checkValidity()) {
      e.preventDefault(); e.stopPropagation();
      form.classList.add('was-validated');
      return;
    }
    btnEnviar.disabled = true;
    btnEnviar.textContent = 'Guardando...';
    clearDraft();
  });

  // Evitar cierre por tecla ESC (ya bloqueado con keyboard:false, esto refuerza)
  modalEl.addEventListener('keydown', function(e){
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); }
  });

  // Blindaje extra: ignorar clics en backdrop (Bootstrap ya lo bloquea con backdrop:'static')
  modalEl.addEventListener('click', function(e){
    const dialog = modalEl.querySelector('.modal-dialog');
    if (!dialog.contains(e.target)) {
      e.stopPropagation();
    }
  });

  // Advertencia si intentan salir de la página con texto sin enviar
  window.addEventListener('beforeunload', function (e) {
    if (hasChanges()) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
})();
</script>
</body>
</html>
