<?php
// portal_solicitante_nuevo.php — Portal Solicitante (Crear solicitud)
// UI tipo portal cliente + POST al endpoint API (JSON)

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

// Si tienes navbar para solicitante, puedes incluirla.
// OJO: si tu navbar imprime HTML antes de headers, aquí ya no hacemos header() después.
require_once __DIR__ . '/navbar.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ====== CONFIG ======
$ORIGEN_UI  = 'LUGA'; // 'NANO' | 'MIPLAN'
$API_CREATE = 'api/portal_proyectos_crear.php'; // ajusta ruta real
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Nueva solicitud • Portal Proyectos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --radius:18px; }
    body{ background:#f5f7fb; }
    .card{ border-radius: var(--radius); }
    .soft-shadow{ box-shadow: 0 10px 30px rgba(20,20,40,.06); }
    .small-muted{ font-size:12px; color:#6c757d; }
    .pill{
      border:1px solid rgba(0,0,0,.08);
      border-radius:999px;
      padding:.25rem .65rem;
      font-size:12px;
      background:#fff;
      display:inline-flex;
      align-items:center;
      gap:.35rem;
    }
    .mono{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }
  </style>
</head>
<body>

<div class="container py-4">

  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <div class="small-muted">
        <span class="pill"><span class="mono">Central</span> <b><?= h($ORIGEN_UI) ?></b></span>
        <span class="mx-2">•</span>
        <a href="portal_solicitante_listado.php" class="text-decoration-none">← Volver</a>
      </div>
      <h3 class="m-0">Nueva solicitud</h3>
      <div class="small-muted">Describe lo que necesitas y lo mandamos a valoración.</div>
    </div>
  </div>

  <div id="alertBox" class="mb-3"></div>

  <div class="row g-3">
    <!-- Form -->
    <div class="col-12 col-lg-7">
      <div class="card soft-shadow">
        <div class="card-body">
          <form id="frm">

            <div class="mb-3">
              <label class="form-label fw-semibold">Título</label>
              <input type="text" class="form-control" name="titulo" id="titulo" maxlength="180" placeholder="Ej. Flujo de traspasos para Subdis" required>
              <div class="small-muted mt-1">Mínimo 5 caracteres.</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Descripción</label>
              <textarea class="form-control" name="descripcion" id="descripcion" rows="7" maxlength="20000" placeholder="Describe el alcance, pantallas, validaciones, reportes, etc." required></textarea>
              <div class="small-muted mt-1">Mínimo 10 caracteres.</div>
            </div>

            <div class="row g-2">
              <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Tipo</label>
                <select class="form-select" name="tipo" id="tipo">
                  <option value="Implementacion">Implementación</option>
                  <option value="Correccion">Corrección</option>
                  <option value="Mejora">Mejora</option>
                  <option value="Reporte">Reporte</option>
                  <option value="Otro">Otro</option>
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Prioridad</label>
                <select class="form-select" name="prioridad" id="prioridad">
                  <option value="Baja">Baja</option>
                  <option value="Media" selected>Media</option>
                  <option value="Alta">Alta</option>
                  <option value="Urgente">Urgente</option>
                </select>
              </div>
            </div>

            <hr class="my-4">

            <div class="d-flex justify-content-between flex-wrap gap-2">
              <div class="small-muted">
                Se creará en estatus: <b>EN_VALORACION_SISTEMAS</b>
              </div>

              <button id="btnEnviar" class="btn btn-primary" type="submit">
                Enviar solicitud
              </button>
            </div>

          </form>
        </div>
      </div>
    </div>

    <!-- Preview -->
    <div class="col-12 col-lg-5">
      <div class="card soft-shadow">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="small-muted">Preview</div>
              <div class="fw-semibold">Así se verá en el listado</div>
            </div>
            <span class="badge text-bg-secondary">Borrador</span>
          </div>

          <hr>

          <div class="small-muted">Título</div>
          <div id="pvTitulo" class="fs-5 fw-semibold">—</div>

          <div class="mt-3 d-flex gap-2 flex-wrap">
            <span class="pill">Tipo: <b id="pvTipo">Implementación</b></span>
            <span class="pill">Prioridad: <b id="pvPrio">Media</b></span>
            <span class="pill">Central: <b><?= h($ORIGEN_UI) ?></b></span>
          </div>

          <div class="mt-3 small-muted">Descripción</div>
          <div id="pvDesc" style="white-space:pre-wrap;">—</div>

          <div class="mt-3 small-muted">
            Al enviar: se genera folio + timeline inicial.
          </div>
        </div>
      </div>
    </div>

  </div>

</div>

<script>
  const API_CREATE = <?= json_encode($API_CREATE) ?>;

  const el = (id)=>document.getElementById(id);

  function setAlert(type, msg){
    const cls = type === 'ok' ? 'alert-success' : (type === 'warn' ? 'alert-warning' : 'alert-danger');
    el('alertBox').innerHTML = `<div class="alert ${cls} soft-shadow">${msg}</div>`;
  }

  function short(s, n=220){
    s = (s||'').trim();
    if(!s) return '—';
    if(s.length<=n) return s;
    return s.slice(0,n-1) + '…';
  }

  function syncPreview(){
    const titulo = el('titulo').value.trim();
    const desc = el('descripcion').value.trim();
    const tipo = el('tipo').value;
    const prio = el('prioridad').value;

    el('pvTitulo').textContent = titulo || '—';
    el('pvTipo').textContent = tipo === 'Implementacion' ? 'Implementación' : tipo;
    el('pvPrio').textContent = prio;
    el('pvDesc').textContent = short(desc, 320);
  }

  ['titulo','descripcion','tipo','prioridad'].forEach(id=>{
    el(id).addEventListener('input', syncPreview);
    el(id).addEventListener('change', syncPreview);
  });
  syncPreview();

  el('frm').addEventListener('submit', async (ev)=>{
    ev.preventDefault();

    const titulo = el('titulo').value.trim();
    const descripcion = el('descripcion').value.trim();
    const tipo = el('tipo').value;
    const prioridad = el('prioridad').value;

    if(titulo.length < 5) return setAlert('bad', 'El título está muy corto (mínimo 5).');
    if(descripcion.length < 10) return setAlert('bad', 'La descripción está muy corta (mínimo 10).');

    // Si quieres capturar solicitante externo aquí también, lo agregamos luego.
    const payload = {
      titulo,
      descripcion,
      tipo: (tipo === 'Implementacion') ? 'Implementacion' : tipo,
      prioridad
    };

    const btn = el('btnEnviar');
    btn.disabled = true;
    btn.textContent = 'Enviando…';

    try{
      const res = await fetch(API_CREATE, {
        method: 'POST',
        headers: { 'Content-Type':'application/json' },
        body: JSON.stringify(payload)
      });

      const data = await res.json().catch(()=> ({}));

      if(!res.ok || !data.ok){
        const err = (data && data.error) ? data.error : ('http_'+res.status);
        throw new Error(err);
      }

      setAlert('ok', `Listo ✅ Se creó <b>${data.folio}</b>. Redirigiendo al detalle…`);

      // Si ya tienes vista solicitante detalle:
      setTimeout(()=>{
        window.location.href = `portal_solicitante_detalle.php?id=${encodeURIComponent(data.id)}`;
      }, 650);

    }catch(e){
      setAlert('bad', `No se pudo crear: <b>${String(e.message||e)}</b>`);
      btn.disabled = false;
      btn.textContent = 'Enviar solicitud';
    }
  });
</script>

</body>
</html>
