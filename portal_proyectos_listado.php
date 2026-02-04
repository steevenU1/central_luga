<?php
// portal_proyectos_listado.php — Portal Intercentrales (LUGA)
// Listado con filtros + permisos por rol/usuario + KPIs + summary por estatus

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);
$ROL        = (string)($_SESSION['rol'] ?? '');

$isSistemas = ($ROL === 'Admin'); // tu regla: sistemas es Admin
$COSTOS_IDS = [66, 8, 7];         // Logística id 66, Gabriela 8, Guillermo 7
$isCostos   = in_array($ID_USUARIO, $COSTOS_IDS, true);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$ESTATUS = [
  'EN_VALORACION_SISTEMAS'        => 'En valoración por Sistemas',
  'EN_COSTEO'                     => 'En costeo (Costos)',
  'EN_VALIDACION_COSTO_SISTEMAS'  => 'Validación de costo (Sistemas)',
  'EN_AUTORIZACION_SOLICITANTE'   => 'Autorización del solicitante',
  'AUTORIZADO'                    => 'Autorizado',
  'EN_EJECUCION'                  => 'En ejecución',
  'FINALIZADO'                    => 'Finalizado',
  'RECHAZADO'                     => 'Rechazado',
  'CANCELADO'                     => 'Cancelado',
];

$STATUS_BADGE = [
  'EN_VALORACION_SISTEMAS'       => 'text-bg-info',
  'EN_COSTEO'                    => 'text-bg-warning',
  'EN_VALIDACION_COSTO_SISTEMAS' => 'text-bg-primary',
  'EN_AUTORIZACION_SOLICITANTE'  => 'text-bg-secondary',
  'AUTORIZADO'                   => 'text-bg-success',
  'EN_EJECUCION'                 => 'text-bg-dark',
  'FINALIZADO'                   => 'text-bg-success',
  'RECHAZADO'                    => 'text-bg-danger',
  'CANCELADO'                    => 'text-bg-danger',
];

$EMPRESAS = [];
$resE = $conn->query("SELECT id, clave, nombre FROM portal_empresas WHERE activa=1 ORDER BY nombre");
while($r = $resE->fetch_assoc()){
  $EMPRESAS[(int)$r['id']] = $r['clave'].' - '.$r['nombre'];
}

// ====== Filtros ======
$f_empresa = (int)($_GET['empresa'] ?? 0);
$f_estatus = trim((string)($_GET['estatus'] ?? ''));
$f_q       = trim((string)($_GET['q'] ?? ''));
$f_desde   = trim((string)($_GET['desde'] ?? ''));
$f_hasta   = trim((string)($_GET['hasta'] ?? ''));

// Paginación
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// ====== Reglas de visibilidad ======
// - Sistemas (Admin): ve todo
// - Costos: ve SOLO lo que está en EN_COSTEO o lo que ellos costearon (por trazabilidad)
// - Otros: por ahora no ven nada
$where = [];
$params = [];
$types  = '';

if ($isSistemas) {
  // sin restricción adicional
} elseif ($isCostos) {
  $where[] = "(s.estatus='EN_COSTEO' OR s.costo_capturado_por=?)";
  $types  .= "i";
  $params[] = $ID_USUARIO;
} else {
  $where[] = "1=0";
}

// Filtros opcionales (items)
if ($f_empresa > 0) { $where[] = "s.empresa_id=?"; $types.="i"; $params[]=$f_empresa; }
if ($f_estatus !== '' && isset($ESTATUS[$f_estatus])) { $where[] = "s.estatus=?"; $types.="s"; $params[]=$f_estatus; }
if ($f_q !== '') {
  $where[] = "(s.folio LIKE CONCAT('%',?,'%') OR s.titulo LIKE CONCAT('%',?,'%'))";
  $types.="ss"; $params[]=$f_q; $params[]=$f_q;
}
if ($f_desde !== '') { $where[] = "DATE(s.created_at) >= ?"; $types.="s"; $params[]=$f_desde; }
if ($f_hasta !== '') { $where[] = "DATE(s.created_at) <= ?"; $types.="s"; $params[]=$f_hasta; }

$whereSql = $where ? ("WHERE ".implode(" AND ", $where)) : "";

// ====== COUNT total (items) ======
$sqlCount = "SELECT COUNT(*) total
             FROM portal_proyectos_solicitudes s
             $whereSql";
$stmtC = $conn->prepare($sqlCount);
if ($types !== '') $stmtC->bind_param($types, ...$params);
$stmtC->execute();
$total = (int)$stmtC->get_result()->fetch_assoc()['total'];
$stmtC->close();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// ====== LIST ======
$sql = "SELECT s.id, s.folio, s.titulo, s.estatus, s.created_at, s.updated_at,
               s.empresa_id, e.clave empresa_clave,
               s.costo_mxn, s.costo_capturado_por, s.costo_validado_por
        FROM portal_proyectos_solicitudes s
        JOIN portal_empresas e ON e.id = s.empresa_id
        $whereSql
        ORDER BY s.created_at DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// =======================
// SUMMARY (por estatus + dinero)
// - Respeta: visibilidad + empresa + q + fechas
// - Ignora: f_estatus (para que el resumen sea útil)
// =======================
$whereSum = [];
$paramsSum = [];
$typesSum  = '';

// visibilidad
if ($isSistemas) {
  // sin restricción
} elseif ($isCostos) {
  $whereSum[] = "(s.estatus='EN_COSTEO' OR s.costo_capturado_por=?)";
  $typesSum  .= "i";
  $paramsSum[] = $ID_USUARIO;
} else {
  $whereSum[] = "1=0";
}

// filtros para summary (sin estatus)
if ($f_empresa > 0) { $whereSum[] = "s.empresa_id=?"; $typesSum.="i"; $paramsSum[]=$f_empresa; }
if ($f_q !== '') {
  $whereSum[] = "(s.folio LIKE CONCAT('%',?,'%') OR s.titulo LIKE CONCAT('%',?,'%'))";
  $typesSum.="ss"; $paramsSum[]=$f_q; $paramsSum[]=$f_q;
}
if ($f_desde !== '') { $whereSum[] = "DATE(s.created_at) >= ?"; $typesSum.="s"; $paramsSum[]=$f_desde; }
if ($f_hasta !== '') { $whereSum[] = "DATE(s.created_at) <= ?"; $typesSum.="s"; $paramsSum[]=$f_hasta; }

$whereSumSql = $whereSum ? ("WHERE ".implode(" AND ", $whereSum)) : "";

// Conteo por estatus
$summaryByStatus = [];
$summaryTotal = 0;

$sqlS = "SELECT s.estatus, COUNT(*) c
         FROM portal_proyectos_solicitudes s
         $whereSumSql
         GROUP BY s.estatus";
$stmtS = $conn->prepare($sqlS);
if ($typesSum !== '') $stmtS->bind_param($typesSum, ...$paramsSum);
$stmtS->execute();
$rsS = $stmtS->get_result();
while($r = $rsS->fetch_assoc()){
  $k = (string)$r['estatus'];
  $c = (int)$r['c'];
  $summaryByStatus[$k] = $c;
  $summaryTotal += $c;
}
$stmtS->close();

function sumStatus($arr, $keys){
  $t = 0;
  foreach($keys as $k){
    $t += (int)($arr[$k] ?? 0);
  }
  return $t;
}

$pendingCount = sumStatus($summaryByStatus, [
  'EN_VALORACION_SISTEMAS','EN_COSTEO','EN_VALIDACION_COSTO_SISTEMAS','EN_AUTORIZACION_SOLICITANTE'
]);

// Money summary
$money = [
  'total_cotizado'   => 0.0,
  'total_autorizado' => 0.0,
  'total_pagable'    => 0.0,
];

$sqlM = "SELECT
           COALESCE(SUM(s.costo_mxn),0) AS total_cotizado,
           COALESCE(SUM(CASE WHEN s.estatus IN ('AUTORIZADO','EN_EJECUCION','FINALIZADO')
                             THEN s.costo_mxn ELSE 0 END),0) AS total_autorizado
         FROM portal_proyectos_solicitudes s
         $whereSumSql";
$stmtM = $conn->prepare($sqlM);
if ($typesSum !== '') $stmtM->bind_param($typesSum, ...$paramsSum);
$stmtM->execute();
$rM = $stmtM->get_result()->fetch_assoc();
$stmtM->close();

$money['total_cotizado']   = (float)$rM['total_cotizado'];
$money['total_autorizado'] = (float)$rM['total_autorizado'];
$money['total_pagable']    = (float)$rM['total_autorizado'];

// helper para links manteniendo filtros
function buildLink(array $baseGet, array $overrides): string {
  $q = $baseGet;
  foreach($overrides as $k=>$v){
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  return '?'.http_build_query($q);
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Portal Proyectos Intercentrales</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .badge-status{ font-weight:600; }
    .table td{ vertical-align: middle; }
    .small-muted{ font-size:12px; color:#6c757d; }
    .card-kpi{ border-radius:16px; }
    .pill-link{ text-decoration:none; }
  </style>
</head>
<body class="bg-light">

<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h4 class="mb-0">Portal de Proyectos (Intercentrales)</h4>
      <div class="small-muted">
        <?php if($isSistemas): ?>
          Vista: Sistemas (Admin) • Ves todo
        <?php elseif($isCostos): ?>
          Vista: Costos • Ves EN_COSTEO y los que tú costees
        <?php else: ?>
          Vista restringida
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
      <div class="card card-kpi shadow-sm">
        <div class="card-body">
          <div class="small-muted">Total visibles</div>
          <div class="fs-4 fw-bold"><?= (int)$summaryTotal ?></div>
          <div class="small-muted mt-1">En página (filtro actual): <?= (int)$total ?></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="card card-kpi shadow-sm">
        <div class="card-body">
          <div class="small-muted">Cotizado</div>
          <div class="fs-4 fw-bold">$<?= number_format($money['total_cotizado'], 2) ?></div>
          <div class="small-muted mt-1">Suma de costos capturados</div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="card card-kpi shadow-sm">
        <div class="card-body">
          <div class="small-muted">Autorizado</div>
          <div class="fs-4 fw-bold">$<?= number_format($money['total_autorizado'], 2) ?></div>
          <div class="small-muted mt-1">Autorizado/Ejecución/Finalizado</div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="card card-kpi shadow-sm">
        <div class="card-body">
          <div class="small-muted">Pendientes</div>
          <div class="fs-4 fw-bold"><?= (int)$pendingCount ?></div>
          <div class="small-muted mt-1">Antes de ejecución</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Summary por estatus (pills clickeables) -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <div class="small-muted me-2">Resumen por estatus:</div>

        <?php
          $baseGet = $_GET;
          foreach($ESTATUS as $k=>$label){
            $count = (int)($summaryByStatus[$k] ?? 0);
            if ($count <= 0) continue;

            $badgeClass = $STATUS_BADGE[$k] ?? 'text-bg-secondary';
            $link = buildLink($baseGet, ['estatus'=>$k, 'page'=>1]);

            echo '<a class="pill-link" href="'.h($link).'">';
            echo '<span class="badge '.$badgeClass.' badge-status">'.h($label).' • '.$count.'</span>';
            echo '</a>';
          }

          // Botón "Todos"
          $linkAll = buildLink($baseGet, ['estatus'=>null, 'page'=>1]);
        ?>
        <a class="pill-link ms-auto" href="<?= h($linkAll) ?>">
          <span class="badge text-bg-light border text-dark badge-status">Ver todos</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-12 col-md-3">
          <label class="form-label small-muted">Empresa</label>
          <select name="empresa" class="form-select">
            <option value="0">Todas</option>
            <?php foreach($EMPRESAS as $id=>$label): ?>
              <option value="<?= (int)$id ?>" <?= $f_empresa===$id?'selected':'' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label small-muted">Estatus</label>
          <select name="estatus" class="form-select">
            <option value="">Todos</option>
            <?php foreach($ESTATUS as $k=>$label): ?>
              <option value="<?= h($k) ?>" <?= $f_estatus===$k?'selected':'' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label small-muted">Buscar</label>
          <input type="text" name="q" class="form-control" value="<?= h($f_q) ?>" placeholder="Folio o título">
        </div>

        <div class="col-6 col-md-1">
          <label class="form-label small-muted">Desde</label>
          <input type="date" name="desde" class="form-control" value="<?= h($f_desde) ?>">
        </div>
        <div class="col-6 col-md-1">
          <label class="form-label small-muted">Hasta</label>
          <input type="date" name="hasta" class="form-control" value="<?= h($f_hasta) ?>">
        </div>

        <div class="col-12 col-md-1 d-grid">
          <button class="btn btn-primary">Filtrar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Tabla -->
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Folio</th>
              <th>Empresa</th>
              <th>Título</th>
              <th>Estatus</th>
              <th class="text-end">Costo</th>
              <th>Fecha</th>
              <th class="text-end">Acción</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$rows): ?>
            <tr><td colspan="7" class="text-center py-4 text-muted">Sin registros con esos filtros.</td></tr>
          <?php endif; ?>

          <?php foreach($rows as $r): ?>
            <?php
              $estatusKey = (string)$r['estatus'];
              $estatusLabel = $ESTATUS[$estatusKey] ?? $estatusKey;
              $badgeClass = $STATUS_BADGE[$estatusKey] ?? 'text-bg-secondary';
              $costo = $r['costo_mxn'];
            ?>
            <tr>
              <td class="fw-semibold"><?= h($r['folio']) ?></td>
              <td><?= h($r['empresa_clave']) ?></td>
              <td style="max-width:520px">
                <div class="fw-semibold"><?= h($r['titulo']) ?></div>
                <div class="small-muted">ID #<?= (int)$r['id'] ?></div>
              </td>
              <td>
                <span class="badge <?= h($badgeClass) ?> badge-status"><?= h($estatusLabel) ?></span>
              </td>
              <td class="text-end">
                <?= ($costo !== null) ? ('$'.number_format((float)$costo,2)) : '<span class="text-muted">—</span>' ?>
              </td>
              <td>
                <div><?= h(substr((string)$r['created_at'],0,16)) ?></div>
                <div class="small-muted">Actualizado: <?= h(substr((string)$r['updated_at'],0,16)) ?></div>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary"
                   href="portal_proyectos_detalle.php?id=<?= (int)$r['id'] ?>">Ver</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
      <div class="small-muted">
        Página <?= (int)$page ?> de <?= (int)$totalPages ?>
      </div>
      <div class="d-flex gap-2">
        <?php
          $qs = $_GET;
          if ($page > 1) {
            $qs['page'] = $page - 1;
            echo '<a class="btn btn-sm btn-outline-secondary" href="?'.http_build_query($qs).'">Anterior</a>';
          }
          if ($page < $totalPages) {
            $qs['page'] = $page + 1;
            echo '<a class="btn btn-sm btn-outline-secondary" href="?'.http_build_query($qs).'">Siguiente</a>';
          }
        ?>
      </div>
    </div>
  </div>

</div>

</body>
</html>
