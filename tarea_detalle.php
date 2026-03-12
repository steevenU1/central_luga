<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo "ID inválido"; exit(); }

// Nombre del usuario (para comentario automático)
$miNombre = '';
try {
  $idU = (int)($_SESSION['id_usuario'] ?? 0);
  if ($idU > 0) {
    $stMe = $conn->prepare("SELECT nombre FROM usuarios WHERE id=? LIMIT 1");
    $stMe->bind_param("i", $idU);
    $stMe->execute();
    $me = $stMe->get_result()->fetch_assoc();
    $miNombre = $me['nombre'] ?? '';
  }
} catch(Throwable $e){ $miNombre = ''; }

// lista de usuarios para agregar participantes
$usuarios = [];
try {
  $res = $conn->query("SELECT id, nombre, rol FROM usuarios WHERE activo=1 ORDER BY nombre ASC");
  while($r = $res->fetch_assoc()) $usuarios[] = $r;
} catch(Throwable $e) { $usuarios = []; }

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Tarea #<?= (int)$id ?> · Tablero</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{ background:#f6f7fb; }
    .cardx{
      border:1px solid rgba(0,0,0,.08);
      border-radius: 16px;
      box-shadow: 0 10px 24px rgba(0,0,0,.05);
    }
    .pill{
      border: 1px solid rgba(0,0,0,.1);
      border-radius: 999px;
      padding: 3px 10px;
      font-size: .78rem;
      background: #fafafa;
      color:#444;
      display:inline-flex;
      gap:6px;
      align-items:center;
      margin-right:6px;
      margin-bottom:6px;
    }
    .msg{
      background:#fff;
      border:1px solid rgba(0,0,0,.08);
      border-radius: 14px;
      padding: 10px 12px;
    }
    .msg .meta{ color:#777; font-size:.82rem; }
    .btn-round{ border-radius: 12px; }
    .topbar{
      position: sticky;
      top: 0;
      z-index: 20;
      background: rgba(246,247,251,.92);
      backdrop-filter: blur(8px);
      border-bottom: 1px solid rgba(0,0,0,.06);
    }
  </style>
</head>
<body>

<div class="topbar">
  <div class="container-fluid py-3">
    <div class="d-flex align-items-center justify-content-between gap-2">
      <div>
        <div class="text-muted small">Tablero de Operación</div>
        <h4 class="mb-0 fw-bold">Tarea #<?= (int)$id ?></h4>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-round" href="tablero_operacion.php">← Volver</a>
        <button class="btn btn-outline-secondary btn-round" id="btnRefresh">Refrescar</button>
      </div>
    </div>
  </div>
</div>

<div class="container-fluid py-3">
  <div class="row g-3">

    <!-- Info tarea -->
    <div class="col-12 col-lg-7">
      <div class="cardx bg-white p-3">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
          <div>
            <h5 class="fw-bold mb-1" id="tTitulo">Cargando…</h5>
            <div class="text-muted" id="tDesc"></div>
          </div>

          <div style="min-width:240px;">
            <label class="form-label small mb-1">Estatus</label>
            <select class="form-select" id="selEstatus">
              <option>Pendiente</option>
              <option>En proceso</option>
              <option>Bloqueado</option>
              <option>Terminado</option>
            </select>
            <div class="form-text" id="helpMover">Mover estatus aplica reglas (dependencias).</div>
            <div class="text-danger small mt-1 d-none" id="msgNoMover">
              No puedes mover esta tarea porque no figuras como creador/responsable/participante.
            </div>
          </div>
        </div>

        <hr>

        <div id="tPills"></div>

        <div class="alert alert-light border small mb-0">
          <b>Tip:</b> si cambias a <b>Bloqueado</b>, te pedirá el motivo y lo guardará como comentario automático.
        </div>
      </div>
    </div>

    <!-- Participantes -->
    <div class="col-12 col-lg-5">
      <div class="cardx bg-white p-3">
        <div class="d-flex align-items-center justify-content-between">
          <h6 class="fw-bold mb-0">Participantes</h6>
          <span class="text-muted small" id="pCount"></span>
        </div>

        <div class="mt-3" id="listaParticipantes">
          <div class="text-muted small">Cargando…</div>
        </div>

        <hr>

        <div id="boxAddPart">
          <label class="form-label small">Agregar participante</label>
          <div class="d-flex gap-2">
            <select class="form-select" id="selUser">
              <option value="">Selecciona usuario</option>
              <?php foreach($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h($u['nombre']) ?> (<?=h($u['rol'])?>)</option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-round" id="btnAdd">Agregar</button>
          </div>
          <div class="form-text">Solo creador/responsable (y quien tu sistema permita) pueden editar participantes.</div>
        </div>
      </div>
    </div>

    <!-- Comentarios -->
    <div class="col-12">
      <div class="cardx bg-white p-3">
        <div class="d-flex align-items-center justify-content-between">
          <h6 class="fw-bold mb-0">Comentarios</h6>
          <span class="text-muted small" id="cCount"></span>
        </div>

        <div class="mt-3 d-grid gap-2" id="listaComentarios">
          <div class="text-muted small">Cargando…</div>
        </div>

        <hr>

        <div id="boxComentar">
          <label class="form-label small">Agregar comentario</label>
          <textarea class="form-control" id="txtComentario" rows="3" placeholder="Escribe aquí el avance, bloqueos, acuerdos…"></textarea>
          <div class="d-flex justify-content-end mt-2">
            <button class="btn btn-primary btn-round" id="btnComentar">Enviar comentario</button>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modal bloqueo -->
<div class="modal fade" id="modalBloqueo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title fw-bold">Motivo de bloqueo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="text-muted small mb-2">Describe qué falta, de quién depende y, si puedes, una fecha estimada.</div>
        <textarea id="txtMotivoBloqueo" class="form-control" rows="3"
          placeholder="Ej: Falta autorización del Gerente de Zona para publicar vacante. ETA mañana 12pm."></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary btn-round" id="btnCancelBloqueo" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-warning btn-round" id="btnConfirmBloqueo">Guardar bloqueo</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
  <div id="toastMsg" class="toast align-items-center text-bg-dark border-0" role="alert" aria-live="polite" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toastBody">Listo</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const tareaId = <?= (int)$id ?>;
const miNombre = <?= json_encode($miNombre) ?>;

function toast(msg){
  const el = document.getElementById('toastMsg');
  document.getElementById('toastBody').textContent = msg;
  const t = bootstrap.Toast.getOrCreateInstance(el, { delay: 2200 });
  t.show();
}
function esc(s){
  return (s ?? '').toString()
    .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
    .replaceAll('"','&quot;').replaceAll("'","&#039;");
}

function renderPills(t){
  const pills = [];
  pills.push(`<span class="pill">⚡ ${esc(t.prioridad||'Media')}</span>`);
  pills.push(`<span class="pill">👁 ${esc(t.visibilidad||'Privada')}</span>`);
  pills.push(`<span class="pill">👤 Responsable: ${esc(t.responsable_nombre||'Sin asignar')}</span>`);
  if (t.fecha_inicio) pills.push(`<span class="pill">📍 Inicio: ${esc(t.fecha_inicio)}</span>`);
  if (t.fecha_estimada) pills.push(`<span class="pill">📅 Estimada: ${esc(t.fecha_estimada)}</span>`);
  if (t.fecha_fin) pills.push(`<span class="pill">✅ Fin: ${esc(t.fecha_fin)}</span>`);
  if (t.depende_de) pills.push(`<span class="pill">🔒 Depende: #${esc(t.depende_de)}</span>`);
  document.getElementById('tPills').innerHTML = pills.join('');
}

function renderParticipantes(arr, canEdit){
  document.getElementById('pCount').textContent = `${arr.length} participantes`;
  const box = document.getElementById('listaParticipantes');
  if (!arr.length){
    box.innerHTML = `<div class="text-muted small">Aún no hay participantes extra.</div>`;
    return;
  }
  box.innerHTML = arr.map(p => {
    const btn = canEdit
      ? `<button class="btn btn-sm btn-outline-danger btn-round" data-remove="${esc(p.id_usuario)}">Quitar</button>`
      : '';
    return `
      <div class="d-flex align-items-center justify-content-between gap-2 msg">
        <div>
          <div class="fw-semibold">${esc(p.nombre)}</div>
          <div class="meta">${esc(p.rol || '')}</div>
        </div>
        ${btn}
      </div>
    `;
  }).join('');

  box.querySelectorAll('[data-remove]').forEach(b => {
    b.addEventListener('click', async () => {
      const uid = b.getAttribute('data-remove');
      if (!uid) return;
      const form = new FormData();
      form.append('id_tarea', tareaId);
      form.append('id_usuario', uid);
      form.append('accion', 'remove');
      const r = await fetch('api_tarea_participantes.php', { method:'POST', body: form, credentials:'same-origin' });
      const j = await r.json();
      if (!j.ok){ toast(j.error || 'No se pudo quitar'); return; }
      toast('Participante removido');
      loadAll();
    });
  });
}

function renderComentarios(arr){
  document.getElementById('cCount').textContent = `${arr.length} comentarios`;
  const box = document.getElementById('listaComentarios');
  if (!arr.length){
    box.innerHTML = `<div class="text-muted small">Sin comentarios aún.</div>`;
    return;
  }
  box.innerHTML = arr.map(c => `
    <div class="msg">
      <div class="d-flex align-items-center justify-content-between gap-2">
        <div class="fw-semibold">${esc(c.nombre)}</div>
        <div class="meta">${esc(c.created_at)}</div>
      </div>
      <div class="mt-2">${esc(c.comentario).replaceAll('\n','<br>')}</div>
    </div>
  `).join('');
}

let puedeMoverLocal = false;

async function loadAll(){
  const r = await fetch(`api_tarea_detalle.php?id=${tareaId}`, { credentials:'same-origin' });
  const j = await r.json();
  if (!j.ok){
    document.getElementById('tTitulo').textContent = 'Error';
    document.getElementById('tDesc').innerHTML = `<span class="text-danger">${esc(j.error||'No se pudo cargar')}</span>`;
    return;
  }

  const t = j.tarea;
  document.getElementById('tTitulo').textContent = t.titulo || '(sin título)';
  document.getElementById('tDesc').textContent = t.descripcion || '';
  document.getElementById('selEstatus').value = t.estatus || 'Pendiente';
  renderPills(t);

  renderParticipantes(j.participantes || [], !!j.permisos?.puede_editar_participantes);
  renderComentarios(j.comentarios || []);

  // Permisos
  puedeMoverLocal = !!j.permisos?.puede_mover;
  document.getElementById('boxAddPart').style.display = j.permisos?.puede_editar_participantes ? '' : 'none';
  document.getElementById('boxComentar').style.display = j.permisos?.puede_comentar ? '' : 'none';
  document.getElementById('selEstatus').disabled = !puedeMoverLocal;

  // Mensaje UI
  const msg = document.getElementById('msgNoMover');
  msg.classList.toggle('d-none', puedeMoverLocal);
}

document.getElementById('btnRefresh').addEventListener('click', loadAll);

/* ====== Estatus con Motivo de Bloqueo ====== */
let estatusPrevio = null;

document.getElementById('selEstatus').addEventListener('focus', function(){
  estatusPrevio = this.value;
});

async function cambiarEstatus(est){
  const form = new FormData();
  form.append('id', tareaId);
  form.append('estatus', est);

  const r = await fetch('api_tablero_mover.php', { method:'POST', body: form, credentials:'same-origin' });
  const j = await r.json();

  if (!j.ok){
    toast(j.error || 'No se pudo cambiar estatus');
    await loadAll();
    return false;
  }
  return true;
}

document.getElementById('selEstatus').addEventListener('change', async (e) => {
  if (!puedeMoverLocal){
    toast('No tienes permiso para mover esta tarea');
    if (estatusPrevio) e.target.value = estatusPrevio;
    return;
  }

  const est = e.target.value;

  if (est === 'Bloqueado') {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalBloqueo'));
    document.getElementById('txtMotivoBloqueo').value = '';
    modal.show();
    return;
  }

  const ok = await cambiarEstatus(est);
  if (ok) {
    toast('Estatus actualizado');
    loadAll();
  } else {
    if (estatusPrevio) document.getElementById('selEstatus').value = estatusPrevio;
  }
});

document.getElementById('btnCancelBloqueo').addEventListener('click', () => {
  if (estatusPrevio) document.getElementById('selEstatus').value = estatusPrevio;
});

document.getElementById('btnConfirmBloqueo').addEventListener('click', async () => {
  if (!puedeMoverLocal){
    toast('No tienes permiso para mover esta tarea');
    if (estatusPrevio) document.getElementById('selEstatus').value = estatusPrevio;
    const modal = bootstrap.Modal.getInstance(document.getElementById('modalBloqueo'));
    if (modal) modal.hide();
    return;
  }

  const motivo = document.getElementById('txtMotivoBloqueo').value.trim();
  if (!motivo){
    toast('Escribe el motivo del bloqueo');
    return;
  }

  const ok = await cambiarEstatus('Bloqueado');
  if (!ok){
    if (estatusPrevio) document.getElementById('selEstatus').value = estatusPrevio;
    return;
  }

  const who = (miNombre && miNombre.trim()) ? miNombre.trim() : 'Usuario';
  const comentarioAuto = `🔒 BLOQUEADO por ${who}: ${motivo}`;

  const form = new FormData();
  form.append('id_tarea', tareaId);
  form.append('comentario', comentarioAuto);

  const rc = await fetch('api_tarea_comentar.php', { method:'POST', body: form, credentials:'same-origin' });
  const jc = await rc.json();

  if (!jc.ok){
    toast(jc.error || 'Se bloqueó, pero no se pudo guardar comentario');
  } else {
    toast('Bloqueo guardado');
  }

  const modal = bootstrap.Modal.getInstance(document.getElementById('modalBloqueo'));
  modal.hide();

  loadAll();
});

/* ====== Participantes ====== */
document.getElementById('btnAdd').addEventListener('click', async () => {
  const uid = document.getElementById('selUser').value;
  if (!uid){ toast('Selecciona un usuario'); return; }
  const form = new FormData();
  form.append('id_tarea', tareaId);
  form.append('id_usuario', uid);
  form.append('accion', 'add');
  const r = await fetch('api_tarea_participantes.php', { method:'POST', body: form, credentials:'same-origin' });
  const j = await r.json();
  if (!j.ok){ toast(j.error || 'No se pudo agregar'); return; }
  toast('Participante agregado');
  document.getElementById('selUser').value = '';
  loadAll();
});

/* ====== Comentarios ====== */
document.getElementById('btnComentar').addEventListener('click', async () => {
  const txt = document.getElementById('txtComentario').value.trim();
  if (!txt){ toast('Escribe un comentario'); return; }
  const form = new FormData();
  form.append('id_tarea', tareaId);
  form.append('comentario', txt);
  const r = await fetch('api_tarea_comentar.php', { method:'POST', body: form, credentials:'same-origin' });
  const j = await r.json();
  if (!j.ok){ toast(j.error || 'No se pudo comentar'); return; }
  toast('Comentario enviado');
  document.getElementById('txtComentario').value = '';
  loadAll();
});

loadAll();
</script>
</body>
</html>