<?php
// tickets_dashboard_productividad.php — Dashboard de Productividad (Tickets)
// KPIs + Tendencias + Backlog por origen + SLA (primera respuesta) + Tiempo de resolución (aprox)
// Requiere tablas: tickets, ticket_mensajes, sucursales
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','Logistica','Gerente'];
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__.'/navbar.php')) require_once __DIR__.'/navbar.php';

date_default_timezone_set('America/Mexico_City');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===========================
   Config / Filtros Dashboard
   =========================== */
$rangeDays  = (int)($_GET['range'] ?? 30);
if ($rangeDays <= 0) $rangeDays = 30;
if ($rangeDays > 365) $rangeDays = 365;

$origen     = $_GET['origen'] ?? ''; // '', 'NANO', 'LUGA', 'OTRO'
$sucursalId = (int)($_GET['sucursal'] ?? 0);
$prioridad  = $_GET['prioridad'] ?? ''; // '', baja/media/alta/critica

// IMPORTANT: Ajusta esto si tu cliente se identifica distinto en ticket_mensajes.autor_sistema
$autorCliente = $_GET['autor_cliente'] ?? 'CLIENTE';

$desdeDash = (new DateTime())->modify("-{$rangeDays} days")->setTime(0,0,0)->format('Y-m-d H:i:s');
$hastaDash = (new DateTime())->setTime(23,59,59)->format('Y-m-d H:i:s');

/* ===========================
   Map de sucursales
   =========================== */
$sucursales = [];
$qSuc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
if ($qSuc) { while($r=$qSuc->fetch_assoc()){ $sucursales[(int)$r['id']] = $r['nombre']; } }

/* ===========================
   WHERE dinámico (para dashboard)
   =========================== */
$where = ["t.created_at >= ?", "t.created_at <= ?"];
$args  = [$desdeDash, $hastaDash];
$types = "ss";

if ($origen !== '')     { $where[] = "t.sistema_origen = ?";   $args[] = $origen;     $types .= "s"; }
if ($sucursalId > 0)    { $where[] = "t.sucursal_origen_id=?"; $args[] = $sucursalId; $types .= "i"; }
if ($prioridad !== '')  { $where[] = "t.prioridad = ?";        $args[] = $prioridad;  $types .= "s"; }

$whereSql = implode(" AND ", $where);

/* ============================================================
   Subquery: Primera respuesta (FRT)
   - Para cada ticket, toma el primer mensaje cuyo autor_sistema != $autorCliente
   ============================================================ */
$subFirstResp = "
  SELECT tm.ticket_id, MIN(tm.created_at) AS first_resp_at
  FROM ticket_mensajes tm
  WHERE tm.autor_sistema <> ?
  GROUP BY tm.ticket_id
";

/* ===========================
   1) KPIs generales
   =========================== */
$sqlKpi = "
  SELECT
    COUNT(*) AS creados,
    SUM(CASE WHEN t.estado IN ('resuelto','cerrado') THEN 1 ELSE 0 END) AS resueltos,
    SUM(CASE WHEN t.estado IN ('abierto','en_progreso','en_espera','en_espera_cliente','en_espera_proveedor') THEN 1 ELSE 0 END) AS backlog,

    AVG(CASE
      WHEN fr.first_resp_at IS NULL THEN NULL
      ELSE TIMESTAMPDIFF(MINUTE, t.created_at, fr.first_resp_at)
    END) AS avg_min_primera_respuesta,

    SUM(CASE
      WHEN fr.first_resp_at IS NOT NULL
       AND TIMESTAMPDIFF(MINUTE, t.created_at, fr.first_resp_at) <= 15
      THEN 1 ELSE 0 END) AS sla_15,

    SUM(CASE
      WHEN fr.first_resp_at IS NOT NULL
       AND TIMESTAMPDIFF(MINUTE, t.created_at, fr.first_resp_at) <= 60
      THEN 1 ELSE 0 END) AS sla_60,

    AVG(CASE
      WHEN t.estado IN ('resuelto','cerrado') THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)
      ELSE NULL
    END) AS avg_horas_resolucion

  FROM tickets t
  LEFT JOIN ($subFirstResp) fr ON fr.ticket_id = t.id
  WHERE $whereSql
";

$kpi = [
  'creados'=>0,'resueltos'=>0,'backlog'=>0,
  'avg_min_primera_respuesta'=>null,
  'avg_horas_resolucion'=>null,
  'sla_15'=>0,'sla_60'=>0,
  'pct_sla_15'=>0,'pct_sla_60'=>0,
  'pct_resueltos'=>0,
];

$st = $conn->prepare($sqlKpi);
if (!$st) { die("Error prepare KPI: ".$conn->error); }
$bindArgs = array_merge([$autorCliente], $args);
$bindTypes = "s".$types;
$st->bind_param($bindTypes, ...$bindArgs);
$st->execute();
$r = $st->get_result();
if ($r && ($row = $r->fetch_assoc())) {
  $kpi['creados'] = (int)($row['creados'] ?? 0);
  $kpi['resueltos'] = (int)($row['resueltos'] ?? 0);
  $kpi['backlog'] = (int)($row['backlog'] ?? 0);

  $kpi['avg_min_primera_respuesta'] = ($row['avg_min_primera_respuesta'] !== null) ? (int)round($row['avg_min_primera_respuesta']) : null;
  $kpi['avg_horas_resolucion'] = ($row['avg_horas_resolucion'] !== null) ? (int)round($row['avg_horas_resolucion']) : null;

  $kpi['sla_15'] = (int)($row['sla_15'] ?? 0);
  $kpi['sla_60'] = (int)($row['sla_60'] ?? 0);
}
$st->close();

$kpi['pct_sla_15'] = ($kpi['creados'] > 0) ? (int)round(($kpi['sla_15'] / $kpi['creados']) * 100) : 0;
$kpi['pct_sla_60'] = ($kpi['creados'] > 0) ? (int)round(($kpi['sla_60'] / $kpi['creados']) * 100) : 0;
$kpi['pct_resueltos'] = ($kpi['creados'] > 0) ? (int)round(($kpi['resueltos'] / $kpi['creados']) * 100) : 0;

/* ===========================
   2) Backlog por origen
   =========================== */
$sqlBacklogOrigen = "
  SELECT t.sistema_origen, COUNT(*) AS c
  FROM tickets t
  WHERE
    t.estado IN ('abierto','en_progreso','en_espera','en_espera_cliente','en_espera_proveedor')
    AND $whereSql
  GROUP BY t.sistema_origen
";
$backlogOrigen = ['NANO'=>0,'LUGA'=>0,'OTRO'=>0];
$st = $conn->prepare($sqlBacklogOrigen);
if ($st) {
  $st->bind_param($types, ...$args);
  $st->execute();
  $r = $st->get_result();
  if ($r) {
    while($row = $r->fetch_assoc()){
      $o = (string)($row['sistema_origen'] ?? '');
      if ($o !== '' && isset($backlogOrigen[$o])) $backlogOrigen[$o] = (int)$row['c'];
    }
  }
  $st->close();
}

/* ===========================
   3) Serie por día (creados vs resueltos) + promedios
   =========================== */
$sqlSerie = "
  SELECT
    DATE(t.created_at) AS dia,
    COUNT(*) AS creados,
    SUM(CASE WHEN t.estado IN ('resuelto','cerrado') THEN 1 ELSE 0 END) AS resueltos,
    AVG(CASE
      WHEN fr.first_resp_at IS NULL THEN NULL
      ELSE TIMESTAMPDIFF(MINUTE, t.created_at, fr.first_resp_at)
    END) AS avg_min_primera_respuesta,
    AVG(CASE
      WHEN t.estado IN ('resuelto','cerrado') THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)
      ELSE NULL
    END) AS avg_horas_resolucion
  FROM tickets t
  LEFT JOIN ($subFirstResp) fr ON fr.ticket_id = t.id
  WHERE $whereSql
  GROUP BY DATE(t.created_at)
  ORDER BY dia ASC
";

$serie = [];
$st = $conn->prepare($sqlSerie);
if (!$st) { die("Error prepare serie: ".$conn->error); }
$bindArgs = array_merge([$autorCliente], $args);
$bindTypes = "s".$types;
$st->bind_param($bindTypes, ...$bindArgs);
$st->execute();
$r = $st->get_result();
if ($r) $serie = $r->fetch_all(MYSQLI_ASSOC);
$st->close();

$labels = [];
$dataCreados = [];
$dataResueltos = [];
foreach ($serie as $d) {
  $labels[] = $d['dia'];
  $dataCreados[] = (int)$d['creados'];
  $dataResueltos[] = (int)$d['resueltos'];
}

/* ===========================
   4) Top sucursales por backlog (para lucirte)
   =========================== */
$sqlTopSuc = "
  SELECT t.sucursal_origen_id, COUNT(*) AS c
  FROM tickets t
  WHERE
    t.estado IN ('abierto','en_progreso','en_espera','en_espera_cliente','en_espera_proveedor')
    AND $whereSql
  GROUP BY t.sucursal_origen_id
  ORDER BY c DESC
  LIMIT 8
";
$topSuc = [];
$st = $conn->prepare($sqlTopSuc);
if ($st) {
  $st->bind_param($types, ...$args);
  $st->execute();
  $r = $st->get_result();
  if ($r) $topSuc = $r->fetch_all(MYSQLI_ASSOC);
  $st->close();
}

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Dashboard Tickets · Productividad</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
  .kpi-card .label{font-size:.82rem;color:#6c757d}
  .kpi-card .val{font-size:1.8rem;font-weight:700;margin:0}
  .chip{display:inline-flex;align-items:center;gap:.4rem;padding:.25rem .55rem;border:1px solid #e9ecef;border-radius:999px;background:#fff;font-size:.82rem}
</style>
</head>
<body class="bg-light">
<div class="container-fluid py-3">

  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <div class="text-muted small">Panel ejecutivo</div>
      <h1 class="h4 m-0">Productividad del área · Tickets</h1>
      <div class="text-muted small">Rango: <strong>últimos <?= (int)$rangeDays ?> días</strong> (<?=h($desdeDash)?> → <?=h($hastaDash)?>)</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-outline-secondary" href="tickets_operador.php">Ir a operador</a>
    </div>
  </div>

  <!-- Filtros -->
  <form class="card shadow-sm mb-3" method="get">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
          <label class="form-label">Rango</label>
          <select name="range" class="form-select">
            <?php foreach ([30,60,90,180,365] as $d): ?>
              <option value="<?=$d?>" <?=$rangeDays===$d?'selected':''?>><?=$d?> días</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Origen</label>
          <select name="origen" class="form-select">
            <option value="">(todos)</option>
            <?php foreach (['NANO','LUGA','OTRO'] as $o): ?>
              <option value="<?=$o?>" <?=$origen===$o?'selected':''?>><?=$o?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">Sucursal</label>
          <select name="sucursal" class="form-select">
            <option value="0">(todas)</option>
            <?php foreach ($sucursales as $id=>$nom): ?>
              <option value="<?=$id?>" <?=$sucursalId===$id?'selected':''?>><?=h($nom)?> (<?=$id?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Prioridad</label>
          <select name="prioridad" class="form-select">
            <option value="">(todas)</option>
            <?php foreach (['baja','media','alta','critica'] as $p): ?>
              <option value="<?=$p?>" <?=$prioridad===$p?'selected':''?>><?=$p?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label">Autor cliente (FRT)</label>
          <input name="autor_cliente" class="form-control" value="<?=h($autorCliente)?>" placeholder="CLIENTE">
          <div class="text-muted small">FRT = 1er mensaje cuyo autor_sistema != este valor</div>
        </div>
        <div class="col-12 d-grid d-md-flex justify-content-md-end mt-1">
          <button class="btn btn-primary">Aplicar</button>
        </div>
      </div>
    </div>
  </form>

  <!-- KPIs -->
  <div class="row g-2 mb-3">
    <div class="col-6 col-lg-2">
      <div class="card shadow-sm kpi-card"><div class="card-body">
        <div class="label">Tickets creados</div>
        <p class="val"><?= (int)$kpi['creados'] ?></p>
        <div class="chip">Resueltos: <?= (int)$kpi['pct_resueltos'] ?>%</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-2">
      <div class="card shadow-sm kpi-card"><div class="card-body">
        <div class="label">Tickets resueltos</div>
        <p class="val"><?= (int)$kpi['resueltos'] ?></p>
        <div class="chip">Backlog: <?= (int)$kpi['backlog'] ?></div>
      </div></div>
    </div>
    <div class="col-6 col-lg-2">
      <div class="card shadow-sm kpi-card"><div class="card-body">
        <div class="label">Backlog total</div>
        <p class="val"><?= (int)$kpi['backlog'] ?></p>
        <div class="text-muted small">Pendientes (abierto/progreso/espera)</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card shadow-sm kpi-card"><div class="card-body">
        <div class="label">Primera respuesta (prom)</div>
        <p class="val"><?= $kpi['avg_min_primera_respuesta']===null ? '—' : ((int)$kpi['avg_min_primera_respuesta']).' min' ?></p>
        <div class="d-flex gap-2 flex-wrap">
          <div class="chip">SLA ≤15m: <?= (int)$kpi['pct_sla_15'] ?>%</div>
          <div class="chip">SLA ≤60m: <?= (int)$kpi['pct_sla_60'] ?>%</div>
        </div>
      </div></div>
    </div>
    <div class="col-12 col-lg-3">
      <div class="card shadow-sm kpi-card"><div class="card-body">
        <div class="label">Resolución (prom)</div>
        <p class="val"><?= $kpi['avg_horas_resolucion']===null ? '—' : ((int)$kpi['avg_horas_resolucion']).' h' ?></p>
        <div class="text-muted small">Estimado: created_at → updated_at cuando resuelto/cerrado</div>
      </div></div>
    </div>
  </div>

  <div class="row g-2 mb-3">
    <!-- Backlog por origen -->
    <div class="col-12 col-xl-4">
      <div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Carga pendiente</div>
        <div class="h6 mb-2">Backlog por origen</div>

        <?php
          $sumBO = max(1, array_sum($backlogOrigen));
          $pNano = (int)round(($backlogOrigen['NANO'] / $sumBO) * 100);
          $pLuga = (int)round(($backlogOrigen['LUGA'] / $sumBO) * 100);
          $pOtro = (int)round(($backlogOrigen['OTRO'] / $sumBO) * 100);
        ?>

        <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2 bg-light">
          <div><strong>NANO</strong></div>
          <div class="d-flex align-items-center gap-2">
            <span class="text-muted small"><?=$pNano?>%</span>
            <span class="badge bg-dark"><?= (int)$backlogOrigen['NANO'] ?></span>
          </div>
        </div>
        <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2 bg-light">
          <div><strong>LUGA</strong></div>
          <div class="d-flex align-items-center gap-2">
            <span class="text-muted small"><?=$pLuga?>%</span>
            <span class="badge bg-dark"><?= (int)$backlogOrigen['LUGA'] ?></span>
          </div>
        </div>
        <div class="d-flex justify-content-between align-items-center border rounded p-2 bg-light">
          <div><strong>OTRO</strong></div>
          <div class="d-flex align-items-center gap-2">
            <span class="text-muted small"><?=$pOtro?>%</span>
            <span class="badge bg-dark"><?= (int)$backlogOrigen['OTRO'] ?></span>
          </div>
        </div>

        <div class="text-muted small mt-3">
          Esto es perfecto para juntas: muestra de dónde viene la presión operativa.
        </div>
      </div></div>
    </div>

    <!-- Chart -->
    <div class="col-12 col-xl-8">
      <div class="card shadow-sm"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <div class="text-muted small">Tendencia</div>
            <div class="h6 mb-0">Creados vs Resueltos por día</div>
          </div>
          <div class="text-muted small">Objetivo: que resueltos alcance/supere creados ✅</div>
        </div>
        <div style="height:320px" class="mt-2">
          <canvas id="chartTrend"></canvas>
        </div>
      </div></div>
    </div>
  </div>

  <!-- Tabla diaria -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="h6 mb-0">Detalle diario</div>
        <div class="text-muted small">FRT usa el primer mensaje distinto a <strong><?=h($autorCliente)?></strong></div>
      </div>

      <div class="table-responsive mt-2">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>Día</th>
              <th class="text-end">Creados</th>
              <th class="text-end">Resueltos</th>
              <th class="text-end">Avg 1ra resp (min)</th>
              <th class="text-end">Avg resolución (h)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$serie): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Sin datos en este rango.</td></tr>
            <?php else: foreach ($serie as $d): ?>
              <tr>
                <td><?=h($d['dia'])?></td>
                <td class="text-end"><?= (int)$d['creados'] ?></td>
                <td class="text-end"><?= (int)$d['resueltos'] ?></td>
                <td class="text-end"><?= $d['avg_min_primera_respuesta']===null ? '—' : (int)round($d['avg_min_primera_respuesta']) ?></td>
                <td class="text-end"><?= $d['avg_horas_resolucion']===null ? '—' : (int)round($d['avg_horas_resolucion']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Top sucursales backlog -->
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="h6 mb-2">Top sucursales con más backlog</div>
      <div class="row g-2">
        <?php if (!$topSuc): ?>
          <div class="text-muted">Sin backlog (o sin datos) en este rango/filtros.</div>
        <?php else: foreach ($topSuc as $x): ?>
          <?php
            $sid = (int)($x['sucursal_origen_id'] ?? 0);
            $nom = $sucursales[$sid] ?? ('Sucursal #'.$sid);
            $c   = (int)($x['c'] ?? 0);
          ?>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="border rounded p-2 bg-light d-flex justify-content-between align-items-center">
              <div class="small fw-semibold"><?=h($nom)?></div>
              <span class="badge bg-dark"><?=$c?></span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="text-muted small mt-3">
        Tip: aquí puedes justificar prioridades de atención por sucursal.
      </div>
    </div>
  </div>

</div>

<script>
const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
const creados = <?= json_encode($dataCreados, JSON_UNESCAPED_UNICODE) ?>;
const resueltos = <?= json_encode($dataResueltos, JSON_UNESCAPED_UNICODE) ?>;

const ctx = document.getElementById('chartTrend');
new Chart(ctx, {
  type: 'line',
  data: {
    labels,
    datasets: [
      { label: 'Creados', data: creados, tension: 0.25 },
      { label: 'Resueltos', data: resueltos, tension: 0.25 }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'top' }
    },
    scales: {
      y: { beginAtZero: true }
    }
  }
});
</script>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
