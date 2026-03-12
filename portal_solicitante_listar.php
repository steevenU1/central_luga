<?php
// portal_solicitante_listado.php — Portal Solicitante (NANO/MIPLAN/LUGA)
// UI tipo portal cliente: tabs + buscador + cards + acceso a detalle.
// Esta versión NO usa BD local: consume API de LUGA por Bearer.

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

// Si quieres navbar acá, ok, pero NO hagas header() después de incluirlo.
require_once __DIR__ . '/navbar.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ======================
// CONFIG (AJUSTA ESTO)
// ======================
const ORIGEN_UI = 'NANO'; // 'NANO' | 'MIPLAN' | 'LUGA'
const API_BASE  = 'https://lugaph.site'; // dominio LUGA donde está la BD maestra
const API_TOKEN = '1Sp2gd3pa*1Fba23a326*'; // token del ORIGEN actual (NANO o MIPLAN)
// Endpoints (en LUGA)
const API_LIST  = '/api/portal_solicitante_listar.php'; // GET ?tab=&q=

// ====== Parámetros UI ======
$q   = trim((string)($_GET['q'] ?? ''));
$tab = strtoupper(trim((string)($_GET['tab'] ?? 'PENDIENTES')));

$TABS_ORDER = ['PENDIENTES','EN_PROCESO','AUTORIZADOS','RECHAZADOS','TODOS'];
if (!in_array($tab, $TABS_ORDER, true)) $tab = 'PENDIENTES';

$ESTATUS_LABEL = [
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

function badgeClass($st){
  if ($st === 'EN_AUTORIZACION_SOLICITANTE') return 'text-bg-warning';
  if ($st === 'AUTORIZADO') return 'text-bg-success';
  if ($st === 'RECHAZADO' || $st === 'CANCELADO') return 'text-bg-danger';
  if ($st === 'EN_EJECUCION') return 'text-bg-primary';
  if ($st === 'FINALIZADO') return 'text-bg-dark';
  if (in_array($st, ['EN_COSTEO','EN_VALIDACION_COSTO_SISTEMAS','EN_VALORACION_SISTEMAS'], true)) return 'text-bg-secondary';
  return 'text-bg-secondary';
}

function short($s, $n=120){
  $s = trim((string)$s);
  if (mb_strlen($s) <= $n) return $s;
  return mb_substr($s,0,$n-1).'…';
}

// ======================
// Consumir API (server-side con file_get_contents)
// ======================
$apiError = '';
$data = [
  'ok' => false,
  'counts' => ['PENDIENTES'=>0,'EN_PROCESO'=>0,'AUTORIZADOS'=>0,'RECHAZADOS'=>0,'TODOS'=>0],
  'rows' => []
];

$qs = http_build_query(['tab'=>$tab, 'q'=>$q]);
$url = rtrim(API_BASE,'/') . API_LIST . '?' . $qs;

$opts = [
  'http' => [
    'method' => 'GET',
    'timeout' => 12,
    'ignore_errors' => true,
    'header' => "Authorization: Bearer ".API_TOKEN."\r\n".
                "Accept: application/json\r\n"
  ]
];

$ctx = stream_context_create($opts);
$raw = @file_get_contents($url, false, $ctx);

$httpCode = 0;
if (isset($http_response_header) && is_array($http_response_header)) {
  foreach ($http_response_header as $hdr) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdr, $m)) { $httpCode = (int)$m[1]; break; }
  }
}

if ($raw === false) {
  $apiError = "No se pudo conectar con el API (".h(API_BASE).").";
} else {
  $j = json_decode($raw, true);
  if (!is_array($j)) {
    $apiError = "Respuesta inválida del API (HTTP $httpCode).";
  } elseif (empty($j['ok'])) {
    $apiError = "API rechazó: ".h($j['error'] ?? ('http_'.$httpCode));
  } else {
    $data = $j;
    // normaliza counts
    if (!isset($data['counts']) || !is_array($data['counts'])) {
      $data['counts'] = ['PENDIENTES'=>0,'EN_PROCESO'=>0,'AUTORIZADOS'=>0,'RECHAZADOS'=>0,'TODOS'=>0];
    }
    if (!isset($data['rows']) || !is_array($data['rows'])) $data['rows'] = [];
  }
}

$counts = $data['counts'];
$rows   = $data['rows'];

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Portal Solicitante • Proyectos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --radius:18px; }
    body{ background:#f5f7fb; }
    .card{ border-radius: var(--radius); }
    .soft-shadow{ box-shadow: 0 10px 30px rgba(20,20,40,.06); }
    .small-muted{ font-size:12px; color:#6c757d; }
    .label{ font-size:12px; color:#6c757d; }
    .pill{
      border:1px solid rgba(0,0,0,.08);
      border-radius:999px;
      padding:.25rem .65rem;
      font-size:12px;
      background:#fff;
    }
    .tabbtn{
      border-radius: 999px !important;
      padding:.4rem .8rem;
    }
    .project-card{
      transition: transform .08s ease, box-shadow .08s ease;
      border: 1px solid rgba(0,0,0,.06);
    }
    .project-card:hover{
      transform: translateY(-1px);
      box-shadow: 0 14px 40px rgba(20,20,40,.08);
    }
    .mono{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }
    a.cleanlink{ text-decoration:none; color:inherit; }
  </style>
</head>
<body>

<div class="container py-4">

  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <div class="small-muted">
        <span class="pill"><?= h(ORIGEN_UI) ?></span>
        <span class="mx-2">•</span>
        <span>Portal Solicitante</span>
      </div>
      <h3 class="m-0">Mis proyectos</h3>
      <div class="small-muted">Aquí autorizas o rechazas costos cuando aplique.</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-outline-dark" href="portal_solicitante_nuevo.php">+ Nueva solicitud</a>

      <form class="d-flex gap-2" method="get">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <input class="form-control" style="min-width:240px" type="search" name="q" value="<?= h($q) ?>" placeholder="Buscar folio, título, texto…">
        <button class="btn btn-primary">Buscar</button>
        <?php if($q !== ''): ?>
          <a class="btn btn-light" href="?tab=<?= h($tab) ?>">Limpiar</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <?php if($apiError): ?>
    <div class="alert alert-danger soft-shadow">
      <div class="fw-semibold">No se pudo cargar el listado.</div>
      <div class="small-muted mt-1"><?= $apiError ?></div>
      <div class="small-muted mt-2">
        Debug tip: revisa token, dominio, y que el endpoint exista en LUGA.
      </div>
    </div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php
      foreach($TABS_ORDER as $t){
        $active = ($tab === $t);
        $cls = $active ? 'btn btn-dark tabbtn' : 'btn btn-outline-secondary tabbtn';
        $label = $t;
        if ($t==='PENDIENTES') $label='Pendientes';
        if ($t==='EN_PROCESO') $label='En proceso';
        if ($t==='AUTORIZADOS') $label='Autorizados';
        if ($t==='RECHAZADOS') $label='Rechazados';
        if ($t==='TODOS') $label='Todos';

        $url = '?tab='.$t.($q!=='' ? '&q='.urlencode($q) : '');
        $c = (int)($counts[$t] ?? 0);
        echo '<a class="'.$cls.'" href="'.$url.'">'.$label.' <span class="badge text-bg-light ms-1">'.$c.'</span></a>';
      }
    ?>
  </div>

  <?php if(!$rows): ?>
    <div class="card soft-shadow">
      <div class="card-body">
        <div class="fw-semibold">No hay proyectos aquí todavía.</div>
        <div class="small-muted mt-1">
          Tip: crea una solicitud con el botón <b>“Nueva solicitud”</b> o cambia de tab.
        </div>
      </div>
    </div>
  <?php else: ?>

    <div class="row g-3">
      <?php foreach($rows as $r): ?>
        <?php
          $st = (string)($r['estatus'] ?? '');
          $badge = badgeClass($st);
          $stLabel = $ESTATUS_LABEL[$st] ?? $st;
          $costo = (array_key_exists('costo_mxn',$r) && $r['costo_mxn'] !== null) ? ('$'.number_format((float)$r['costo_mxn'],2).' MXN') : '—';
          $created = substr((string)($r['created_at'] ?? ''),0,16);
          $prio = (string)($r['prioridad'] ?? '');
          $tipo = (string)($r['tipo'] ?? '');
          $isNeedAuth = ($st === 'EN_AUTORIZACION_SOLICITANTE');
        ?>
        <div class="col-12 col-lg-6">
          <a class="cleanlink" href="portal_solicitante_detalle.php?id=<?= (int)($r['id'] ?? 0) ?>">
            <div class="card project-card soft-shadow h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div>
                    <div class="mono fw-semibold"><?= h($r['folio'] ?? '') ?></div>
                    <div class="fs-5 fw-semibold"><?= h($r['titulo'] ?? '') ?></div>
                    <div class="small-muted mt-1">
                      Creado: <?= h($created) ?>
                      <?= $tipo ? ' • Tipo: <b>'.h($tipo).'</b>' : '' ?>
                      <?= $prio ? ' • Prioridad: <b>'.h($prio).'</b>' : '' ?>
                    </div>
                  </div>
                  <div class="text-end">
                    <span class="badge <?= h($badge) ?>"><?= h($stLabel) ?></span>
                    <div class="mt-2">
                      <div class="label">Costo</div>
                      <div class="fw-bold"><?= h($costo) ?></div>
                    </div>
                  </div>
                </div>

                <hr>

                <div class="small-muted">
                  <?= h(short($r['descripcion'] ?? '', 160)) ?>
                </div>

                <?php if($isNeedAuth): ?>
                  <div class="mt-3">
                    <span class="badge text-bg-warning">⚠️ Requiere tu autorización</span>
                  </div>
                <?php endif; ?>

              </div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="small-muted mt-3">
      Nota: Este listado muestra máximo 200 resultados por performance.
    </div>

  <?php endif; ?>

</div>

</body>
</html>