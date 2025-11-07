<?php
// tickets_nuevo_luga.php — Crear ticket dentro de LUGA (origen = LUGA)
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
  <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"> -->
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Nuevo ticket (LUGA)</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="tickets_operador.php">← Operador</a>
    </div>
  </div>

  <?php if ($flash_ok): ?><div class="alert alert-success"><?=h($flash_ok)?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="alert alert-danger"><?=h($flash_err)?></div><?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post" action="tickets_guardar_luga.php" id="formTicket" novalidate>
        <input type="hidden" name="csrf" value="<?=h($_SESSION['ticket_csrf_luga'])?>">
        <div class="mb-3">
          <label class="form-label">Asunto <span class="text-danger">*</span></label>
          <input type="text" name="asunto" class="form-control" maxlength="255" required placeholder="Ej. Alta de usuario en sistema X">
          <div class="invalid-feedback">Escribe el asunto.</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Mensaje inicial <span class="text-danger">*</span></label>
          <textarea name="mensaje" class="form-control" rows="6" required placeholder="Describe el problema o solicitud con el mayor detalle posible."></textarea>
          <div class="invalid-feedback">Escribe el detalle del ticket.</div>
        </div>

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Prioridad</label>
            <select name="prioridad" class="form-select">
              <option value="media" selected>Media</option>
              <option value="baja">Baja</option>
              <option value="alta">Alta</option>
              <option value="critica">Crítica</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Sucursal origen</label>
            <select name="sucursal_origen_id" class="form-select">
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
          <a class="btn btn-outline-secondary" href="tickets_operador.php">Cancelar</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const form = document.getElementById('formTicket');
  const btn  = document.getElementById('btnEnviar');

  form.addEventListener('submit', function(e){
    if (!form.checkValidity()) {
      e.preventDefault(); e.stopPropagation();
      form.classList.add('was-validated');
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Guardando...';
  });
})();
</script>
</body>
</html>
