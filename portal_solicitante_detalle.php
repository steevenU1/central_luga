<?php
// portal_solicitante_detalle.php — Portal Solicitante (LUGA/NANO/MIPLAN)
// Vista tipo portal cliente: resumen + costo + acciones (autorizar/rechazar) + timeline.
// Consume API: /api/portal_proyectos_autorizar.php

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ====== CONFIG ORIGEN (cámbialo por central) ======
$ORIGEN_UI = 'LUGA'; // 'NANO' | 'MIPLAN'

// ====== Cargar solicitud ======
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: portal_proyectos_listado.php"); exit(); }

$stmt = $conn->prepare("
  SELECT s.*, e.clave empresa_clave, e.nombre empresa_nombre
  FROM portal_proyectos_solicitudes s
  JOIN portal_empresas e ON e.id = s.empresa_id
  WHERE s.id=? LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$sol = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sol) { header("Location: portal_proyectos_listado.php"); exit(); }

// ====== Seguridad por origen ======
if ((string)$sol['empresa_clave'] !== $ORIGEN_UI) {
  http_response_code(403);
  exit("403");
}

// ====== Timeline / Bitácora ======
$hist = [];
$stmtH = $conn->prepare("
  SELECT h.*, u.nombre nombre_usuario
  FROM portal_proyectos_historial h
  LEFT JOIN usuarios u ON u.id = h.usuario_id
  WHERE h.solicitud_id=?
  ORDER BY h.created_at DESC
  LIMIT 50
");
$stmtH->bind_param("i", $id);
$stmtH->execute();
$hist = $stmtH->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtH->close();

// ====== Labels ======
$ESTATUS = [
  'EN_VALORACION_SISTEMAS'        => 'En valoración (Sistemas)',
  'EN_COSTEO'                     => 'En costeo (Costos)',
  'EN_VALIDACION_COSTO_SISTEMAS'  => 'Validación de costo (Sistemas)',
  'EN_AUTORIZACION_SOLICITANTE'   => 'Autorización requerida',
  'AUTORIZADO'                    => 'Autorizado',
  'EN_EJECUCION'                  => 'En ejecución',
  'FINALIZADO'                    => 'Finalizado',
  'RECHAZADO'                     => 'Rechazado',
  'CANCELADO'                     => 'Cancelado',
];

$estatusActual = (string)$sol['estatus'];
$estatusLabel  = $ESTATUS[$estatusActual] ?? $estatusActual;

// Badge por estatus (bonito)
function badgeClass($st){
  if ($st === 'EN_AUTORIZACION_SOLICITANTE') return 'text-bg-warning';
  if ($st === 'AUTORIZADO') return 'text-bg-success';
  if ($st === 'RECHAZADO' || $st === 'CANCELADO') return 'text-bg-danger';
  if ($st === 'EN_EJECUCION') return 'text-bg-primary';
  if ($st === 'FINALIZADO') return 'text-bg-dark';
  return 'text-bg-secondary';
}

$canDecide = ($estatusActual === 'EN_AUTORIZACION_SOLICITANTE' && $sol['costo_mxn'] !== null && (float)$sol['costo_mxn'] > 0);
$costoTxt  = ($sol['costo_mxn'] !== null) ? ('$'.number_format((float)$sol['costo_mxn'],2).' MXN') : '—';

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= h($sol['folio']) ?> • Portal Solicitante</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root{
      --radius: 18px;
    }
    body{ background:#f5f7fb; }
    .card{ border-radius: var(--radius); }
    .soft-shadow{ box-shadow: 0 10px 30px rgba(20,20,40,.06); }
    .small-muted{ font-size:12px; color:#6c757d; }
    .label{ font-size:12px; color:#6c757d; }
    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .hero{
      border-radius: calc(var(--radius) + 6px);
      background: linear-gradient(135deg, rgba(13,110,253,.12), rgba(25,135,84,.08));
      border: 1px solid rgba(0,0,0,.06);
    }
    .kpi{
      border-radius: 16px;
      border: 1px solid rgba(0,0,0,.06);
      background: rgba(255,255,255,.7);
    }
    .timeline-item{
      border: 1px solid rgba(0,0,0,.06);
      border-radius: 14px;
      background: #fff;
    }
    .pill{
      border:1px solid rgba(0,0,0,.08);
      border-radius: 999px;
      padding:.25rem .65rem;
      font-size:12px;
      background:#fff;
    }
    .btn-loading{
      pointer-events:none;
      opacity:.85;
    }
  </style>
</head>
<body>

<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <div class="small-muted">
        <a href="portal_proyectos_listado.php">← Volver</a>
        <span class="mx-2">•</span>
        <span class="pill"><?= h($ORIGEN_UI) ?></span>
      </div>

      <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
        <h3 class="m-0"><?= h($sol['folio']) ?></h3>
        <span class="badge <?= h(badgeClass($estatusActual)) ?>"><?= h($estatusLabel) ?></span>
      </div>

      <div class="small-muted mt-1">
        Empresa: <b><?= h($sol['empresa_nombre']) ?></b>
        • Creado: <?= h(substr((string)$sol['created_at'],0,16)) ?>
      </div>
    </div>
  </div>

  <!-- ALERTA FEEDBACK -->
  <div id="uiAlert" class="alert d-none soft-shadow" role="alert"></div>

  <!-- HERO -->
  <div class="hero p-3 p-md-4 soft-shadow mb-3">
    <div class="row g-3 align-items-stretch">
      <div class="col-12 col-lg-8">
        <div class="label">Título</div>
        <div class="fs-4 fw-semibold"><?= h($sol['titulo']) ?></div>

        <div class="row g-2 mt-2">
          <div class="col-6 col-md-4">
            <div class="label">Tipo</div>
            <div><?= h($sol['tipo']) ?></div>
          </div>
          <div class="col-6 col-md-4">
            <div class="label">Prioridad</div>
            <div><?= h($sol['prioridad']) ?></div>
          </div>
          <div class="col-12 col-md-4">
            <div class="label">Solicitante</div>
            <div><?= !empty($sol['usuario_solicitante_id']) ? 'Interno' : 'Externo' ?></div>
          </div>
        </div>

        <div class="mt-3">
          <div class="label">Descripción</div>
          <div class="mt-1" style="white-space:pre-wrap;"><?= h($sol['descripcion']) ?></div>
        </div>
      </div>

      <div class="col-12 col-lg-4">
        <div class="kpi p-3 h-100 soft-shadow">
          <div class="label">Costo final</div>
          <div class="display-6 fw-bold"><?= h($costoTxt) ?></div>

          <div class="small-muted mt-2">
            Para autorizar/rechazar, el proyecto debe estar en <b>“Autorización requerida”</b>.
          </div>

          <hr>

          <?php if($canDecide): ?>
            <div class="d-grid gap-2">
              <button id="btnAutorizar" class="btn btn-success btn-lg">
                ✅ Autorizar costo
              </button>

              <button class="btn btn-outline-danger btn-lg" data-bs-toggle="modal" data-bs-target="#modalRechazar">
                ❌ Rechazar (con comentario)
              </button>
            </div>
          <?php else: ?>
            <div class="text-muted">
              Acciones deshabilitadas. Estatus actual: <b><?= h($estatusLabel) ?></b>
              <?php if($sol['costo_mxn'] === null || (float)$sol['costo_mxn'] <= 0): ?>
                <div class="small-muted mt-2">Aún no hay costo capturado/validado.</div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <!-- Detalle extra (contacto externo) -->
    <div class="col-12 col-lg-7">
      <div class="card soft-shadow">
        <div class="card-body">
          <h6 class="mb-2">Datos del solicitante</h6>

          <?php if(!empty($sol['usuario_solicitante_id'])): ?>
            <div class="small-muted">Solicitante interno</div>
            <div class="mono">ID <?= (int)$sol['usuario_solicitante_id'] ?></div>
          <?php else: ?>
            <div class="small-muted">Solicitante externo</div>
            <div class="fw-semibold"><?= h($sol['solicitante_nombre'] ?? '—') ?></div>
            <div class="small-muted"><?= h($sol['solicitante_correo'] ?? '—') ?></div>
          <?php endif; ?>

          <hr>

          <div class="small-muted">
            Nota: al autorizar o rechazar se registra en bitácora como <b><?= h($ORIGEN_UI) ?> API</b>.
          </div>
        </div>
      </div>
    </div>

    <!-- Timeline -->
    <div class="col-12 col-lg-5">
      <div class="card soft-shadow">
        <div class="card-body">
          <h6 class="mb-2">Timeline / Bitácora</h6>

          <?php if(!$hist): ?>
            <div class="text-muted">Sin movimientos aún.</div>
          <?php else: ?>
            <div class="d-grid gap-2">
              <?php foreach($hist as $row): ?>
                <?php
                  $accion = (string)($row['accion'] ?? '');
                  $who = $row['nombre_usuario'] ?? $row['actor'] ?? '—';
                  $prev = (string)($row['estatus_anterior'] ?? '');
                  $next = (string)($row['estatus_nuevo'] ?? '');
                  $isResp = in_array($accion, ['COSTO_AUTORIZADO_SOLICITANTE','COSTO_RECHAZADO_SOLICITANTE'], true);
                  $b = $isResp ? ($accion==='COSTO_AUTORIZADO_SOLICITANTE' ? 'border-success' : 'border-danger') : 'border-light';
                ?>
                <div class="timeline-item p-3 <?= h($b) ?>">
                  <div class="d-flex justify-content-between gap-2">
                    <div class="fw-semibold"><?= h($accion) ?></div>
                    <div class="small-muted"><?= h(substr((string)$row['created_at'],0,16)) ?></div>
                  </div>
                  <div class="small-muted">Por: <?= h($who) ?></div>
                  <?php if($prev || $next): ?>
                    <div class="small-muted">Estatus: <?= h($prev) ?> → <?= h($next) ?></div>
                  <?php endif; ?>
                  <?php if(!empty($row['comentario'])): ?>
                    <div class="mt-2" style="white-space:pre-wrap;"><?= h($row['comentario']) ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>

</div>

<!-- MODAL RECHAZAR -->
<div class="modal fade" id="modalRechazar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px;">
      <div class="modal-header">
        <h5 class="modal-title">Rechazar costo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="small-muted mb-2">
          Explícanos breve por qué se rechaza (obligatorio).
        </div>
        <textarea id="rechazoComentario" class="form-control" rows="4" placeholder="Ej: Ajustar alcance / costo muy alto / falta detalle..."></textarea>
        <div class="small-muted mt-2">
          Se registrará en bitácora.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
        <button id="btnConfirmRechazar" type="button" class="btn btn-danger">Rechazar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
  const SOL_ID = <?= (int)$id ?>;
  const API_URL = "api/portal_proyectos_autorizar.php"; // AJUSTA si tu endpoint se llama distinto

  const uiAlert = document.getElementById('uiAlert');
  const btnAutorizar = document.getElementById('btnAutorizar');
  const btnConfirmRechazar = document.getElementById('btnConfirmRechazar');
  const rechazoComentario = document.getElementById('rechazoComentario');

  function showAlert(type, msg){
    uiAlert.className = "alert soft-shadow alert-" + type;
    uiAlert.textContent = msg;
    uiAlert.classList.remove('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function setLoading(btn, on){
    if(!btn) return;
    if(on){
      btn.classList.add('btn-loading');
      btn.dataset._old = btn.innerHTML;
      btn.innerHTML = 'Procesando...';
      btn.disabled = true;
    }else{
      btn.classList.remove('btn-loading');
      btn.disabled = false;
      if(btn.dataset._old) btn.innerHTML = btn.dataset._old;
    }
  }

  async function sendDecision(accion, comentario){
    try{
      // Loading
      if(accion === 'AUTORIZAR') setLoading(btnAutorizar, true);
      if(accion === 'RECHAZAR') setLoading(btnConfirmRechazar, true);

      const res = await fetch(API_URL, {
        method: "POST",
        headers: { "Content-Type":"application/json" },
        body: JSON.stringify({
          id: SOL_ID,
          accion: accion,
          comentario: comentario || ""
        })
      });

      const data = await res.json().catch(()=> ({}));

      if(!res.ok || !data.ok){
        const err = (data && data.error) ? data.error : ("http_" + res.status);
        throw new Error(err);
      }

      showAlert('success', accion === 'AUTORIZAR'
        ? "✅ Costo autorizado correctamente. Actualizando vista..."
        : "❌ Costo rechazado correctamente. Actualizando vista..."
      );

      // recarga para ver estatus + timeline actualizado
      setTimeout(()=> location.reload(), 900);

    }catch(e){
      showAlert('danger', "Error: " + (e.message || "no_definido"));
      setLoading(btnAutorizar, false);
      setLoading(btnConfirmRechazar, false);
    }
  }

  // Autorizar
  if(btnAutorizar){
    btnAutorizar.addEventListener('click', ()=>{
      if(!confirm("¿Confirmas autorizar el costo?")) return;
      sendDecision('AUTORIZAR', '');
    });
  }

  // Rechazar
  if(btnConfirmRechazar){
    btnConfirmRechazar.addEventListener('click', ()=>{
      const c = (rechazoComentario.value || '').trim();
      if(c.length < 5){
        showAlert('warning', "Pon un comentario un poquito más claro (mínimo 5 caracteres).");
        return;
      }
      sendDecision('RECHAZAR', c);
    });
  }
</script>

</body>
</html>
