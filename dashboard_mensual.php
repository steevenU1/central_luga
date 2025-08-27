<?php
session_start();
if (empty($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

// 🔹 Nombre de mes en español
function nombreMes($mes) {
    $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    return $meses[$mes] ?? '';
}

// 🔹 Mes/Año seleccionados
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');

// 🔹 Rango del mes
$inicioMes = sprintf('%04d-%02d-01', $anio, $mes);
$finMes    = date('Y-m-t', strtotime($inicioMes));
$diasMes   = (int)date('t', strtotime($inicioMes));
$factorSem = 7 / max(1,$diasMes); // semanas “efectivas” del mes

/* ======================================================
   0) Cuota mensual ejecutivos (POR EJECUTIVO)
====================================================== */
$cuotaMesU_porEj = 0.0;  // unidades / ejecutivo / mes
$cuotaMesM_porEj = 0.0;  // monto $ / ejecutivo / mes
$qe = $conn->prepare("
    SELECT cuota_unidades, cuota_monto
    FROM cuotas_mensuales_ejecutivos
    WHERE anio=? AND mes=?
    ORDER BY id DESC LIMIT 1
");
$qe->bind_param("ii", $anio, $mes);
$qe->execute();
if ($rowQ = $qe->get_result()->fetch_assoc()) {
    $cuotaMesU_porEj = (float)$rowQ['cuota_unidades'];
    $cuotaMesM_porEj = (float)$rowQ['cuota_monto'];
}
$qe->close();

$cuotaSemU_porEj = $cuotaMesU_porEj * $factorSem; // (informativo)

/* ==================================================================
   SUBCONSULTA REUTILIZABLE: ventas agregadas POR VENTA en el mes
================================================================== */
$subVentasAggMes = "
  SELECT
      v.id,
      v.id_usuario,
      v.id_sucursal,
      DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
      CASE
        WHEN LOWER(v.tipo_venta)='financiamiento+combo' THEN 2
        ELSE SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE 1 END)
      END AS unidades,
      SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE dv.precio_unitario END) AS monto
  FROM ventas v
  LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
  LEFT JOIN productos p      ON p.id       = dv.id_producto
  WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY v.id
";

/* ======================================================
   1) Sucursales: ventas, unidades, cuotas mensuales
====================================================== */
$sqlSuc = "
    SELECT
      s.id AS id_sucursal, s.nombre AS sucursal, s.zona,
      IFNULL(SUM(va.unidades),0) AS unidades,
      IFNULL(SUM(va.monto),0)    AS ventas
    FROM sucursales s
    LEFT JOIN ( $subVentasAggMes ) va ON va.id_sucursal = s.id
    WHERE s.tipo_sucursal='Tienda'
    GROUP BY s.id
    ORDER BY ventas DESC
";
$stmt = $conn->prepare($sqlSuc);
$stmt->bind_param("ss", $inicioMes, $finMes);
$stmt->execute();
$res = $stmt->get_result();

$sucursales = [];
$totalGlobalUnidades = 0;
$totalGlobalVentas   = 0;
$totalGlobalCuota    = 0;

// Cuotas mensuales por sucursal
$cuotasSuc = [];
$q = $conn->prepare("SELECT id_sucursal, cuota_unidades, cuota_monto FROM cuotas_mensuales WHERE anio=? AND mes=?");
$q->bind_param("ii", $anio, $mes);
$q->execute();
$r = $q->get_result();
while ($row = $r->fetch_assoc()) {
    $cuotasSuc[(int)$row['id_sucursal']] = [
        'cuota_unidades' => (int)$row['cuota_unidades'],
        'cuota_monto'    => (float)$row['cuota_monto']
    ];
}
$q->close();

while ($row = $res->fetch_assoc()) {
    $id_suc = (int)$row['id_sucursal'];
    $cuotaUnidades = $cuotasSuc[$id_suc]['cuota_unidades'] ?? 0;
    $cuotaMonto    = $cuotasSuc[$id_suc]['cuota_monto']    ?? 0;

    $cumpl = $cuotaMonto > 0 ? ($row['ventas']/$cuotaMonto*100) : 0;

    $sucursales[] = [
        'id_sucursal'     => $id_suc,
        'sucursal'        => $row['sucursal'],
        'zona'            => $row['zona'],
        'unidades'        => (int)$row['unidades'],
        'ventas'          => (float)$row['ventas'],
        'cuota_unidades'  => (int)$cuotaUnidades,
        'cuota_monto'     => (float)$cuotaMonto,
        'cumplimiento'    => $cumpl
    ];

    $totalGlobalUnidades += (int)$row['unidades'];
    $totalGlobalVentas   += (float)$row['ventas'];
    $totalGlobalCuota    += (float)$cuotaMonto;
}
$stmt->close();

/* ======================================================
   2) Zonas (agregados)
====================================================== */
$zonas = [];
foreach ($sucursales as $s) {
    $z = trim((string)($s['zona'] ?? ''));
    if ($z === '') $z = 'Sin zona';
    if (!isset($zonas[$z])) $zonas[$z] = ['unidades'=>0,'ventas'=>0,'cuota'=>0];
    $zonas[$z]['unidades'] += $s['unidades'];
    $zonas[$z]['ventas']   += $s['ventas'];
    $zonas[$z]['cuota']    += $s['cuota_monto'];
}
$porcentajeGlobal = $totalGlobalCuota > 0 ? ($totalGlobalVentas/$totalGlobalCuota*100) : 0;

/* ======================================================
   3) Ejecutivos (+ Gerentes): ventas y cumplimiento por UNIDADES
====================================================== */
$sqlEj = "
    SELECT 
        u.id,
        u.nombre,
        s.nombre AS sucursal,
        IFNULL(SUM(va.unidades),0) AS unidades,
        IFNULL(SUM(va.monto),0)    AS ventas
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN ( $subVentasAggMes ) va ON va.id_usuario = u.id
    WHERE u.activo = 1 AND u.rol IN ('Ejecutivo','Gerente')
    GROUP BY u.id
    ORDER BY unidades DESC, ventas DESC
";
$stEj = $conn->prepare($sqlEj);
$stEj->bind_param("ss", $inicioMes, $finMes);
$stEj->execute();
$resEj = $stEj->get_result();

$ejecutivos = [];
while ($row = $resEj->fetch_assoc()) {
    $cumpl_uni = $cuotaMesU_porEj>0 ? ($row['unidades']/$cuotaMesU_porEj*100) : null;

    $ejecutivos[] = [
        'id'             => (int)$row['id'],
        'nombre'         => $row['nombre'],
        'sucursal'       => $row['sucursal'],
        'unidades'       => (int)$row['unidades'],
        'ventas'         => (float)$row['ventas'],
        'cuota_unidades' => $cuotaMesU_porEj,
        'cumpl_uni'      => $cumpl_uni,
    ];
}
$stEj->close();

function badgeFila($pct) {
    if ($pct === null) return '';
    return $pct>=100 ? 'table-success' : ($pct>=60 ? 'table-warning' : 'table-danger');
}

/* ======================================================
   4) 📈 Serie MENSUAL por SEMANAS (mar–lun)
====================================================== */
function inicioSemanaMartes(DateTime $dt): DateTime {
    $dow = (int)$dt->format('N'); // 1=Lun..7=Dom
    $diff = $dow - 2;            // Martes=2
    if ($diff < 0) $diff += 7;
    $start = clone $dt;
    $start->modify("-{$diff} days")->setTime(0,0,0);
    return $start;
}

$inicioMesDT = new DateTime($inicioMes.' 00:00:00');
$finMesDT    = new DateTime($finMes.' 23:59:59');

$wkStart = inicioSemanaMartes(clone $inicioMesDT);
$semanas = [];
$idx = 1;
while ($wkStart <= $finMesDT) {
    $wkFin = (clone $wkStart)->modify('+6 days')->setTime(23,59,59);
    $visIni = ($wkStart < $inicioMesDT) ? $inicioMesDT : $wkStart;
    $visFin = ($wkFin   > $finMesDT)    ? $finMesDT    : $wkFin;
    $semanas[] = [
        'ini'   => $wkStart->format('Y-m-d'),
        'fin'   => $wkFin->format('Y-m-d'),
        'label' => sprintf('Sem %d (%s–%s)', $idx, $visIni->format('d/m'), $visFin->format('d/m'))
    ];
    $idx++;
    $wkStart->modify('+7 days')->setTime(0,0,0);
}

function findWeekIndex(string $dia, array $semanas): ?int {
    foreach ($semanas as $i => $sem) {
        if ($dia >= $sem['ini'] && $dia <= $sem['fin']) return $i;
    }
    return null;
}

$sqlMonthDaily = "
  SELECT s.nombre AS sucursal, va.dia, IFNULL(SUM(va.unidades),0) AS unidades
  FROM sucursales s
  LEFT JOIN ( $subVentasAggMes ) va ON va.id_sucursal = s.id
  WHERE s.tipo_sucursal='Tienda'
  GROUP BY s.id, va.dia
";
$stMd = $conn->prepare($sqlMonthDaily);
$stMd->bind_param("ss", $inicioMes, $finMes);
$stMd->execute();
$resMd = $stMd->get_result();

$weeklySeries = [];
while ($r = $resMd->fetch_assoc()) {
    $suc = $r['sucursal'];
    $dia = $r['dia'];
    if (empty($dia)) continue;
    $u   = (int)$r['unidades'];
    $i   = findWeekIndex($dia, $semanas);
    if ($i === null) continue;
    if (!isset($weeklySeries[$suc])) $weeklySeries[$suc] = [];
    if (!isset($weeklySeries[$suc][$i])) $weeklySeries[$suc][$i] = 0;
    $weeklySeries[$suc][$i] += $u;
}
$stMd->close();

$labelsSemanas = array_column($semanas, 'label');
$k = count($labelsSemanas);
foreach ($sucursales as $s) {
    $name = $s['sucursal'];
    if (!isset($weeklySeries[$name])) $weeklySeries[$name] = [];
    for ($i=0; $i<$k; $i++) {
        if (!isset($weeklySeries[$name][$i])) $weeklySeries[$name][$i] = 0;
    }
    ksort($weeklySeries[$name]);
}

$datasetsMonth = [];
foreach ($weeklySeries as $sucursalNombre => $serie) {
    $row = [];
    for ($i=0; $i<$k; $i++) $row[] = (int)$serie[$i];
    $datasetsMonth[] = [
        'label'        => $sucursalNombre,
        'data'         => $row,
        'tension'      => 0.3,
        'borderWidth'  => 2,
        'pointRadius'  => 2
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard Mensual</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    /* Utilidades comunes */
    .w-120 { min-width:120px; }
    .clip { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .num  { font-variant-numeric: tabular-nums; letter-spacing: -.2px; }
    .progress{height:18px}
    .progress-bar{font-size:.75rem}
    .tab-pane{padding-top:10px}

    /* En móvil el nombre de sucursal NO se recorta: se envuelve */
    @media (max-width:576px){
      .suc-name{
        max-width: none !important;
        white-space: normal !important;
        overflow: visible !important;
        text-overflow: unset !important;
        word-break: break-word;
      }
    }

    #topbar{ font-size:16px; }
    @media (max-width:576px){
      /* Compactación en móvil */
      body { font-size: 14px; }
      .container { padding-left: 8px; padding-right: 8px; }
      .card .card-header { padding: .5rem .65rem; font-size: .95rem; }
      .card .card-body   { padding: .65rem; }

      .table { font-size: 12px; table-layout: fixed; }
      .table thead th { font-size: 11px; }
      .table td, .table th { padding: .30rem .40rem; }

      .clip { max-width: 120px; }

      /* Topbar */
      #topbar{
        font-size:16px;
        --brand-font:1.00em; --nav-font:.95em; --drop-font:.95em;
        --icon-em:1.05em; --pad-y:.44em; --pad-x:.62em;
      }
      #topbar .navbar-brand img{ width:1.8em; height:1.8em; }
      #topbar .btn-asistencia{ font-size:.95em; padding:.5em .9em !important; border-radius:12px; }
      #topbar .nav-avatar, #topbar .nav-initials{ width:2.1em; height:2.1em; }
      #topbar .navbar-toggler{ padding:.45em .7em; }
    }
    @media (max-width:360px){
      .table { font-size: 11px; }
      .table td, .table th { padding: .28rem .35rem; }
      .clip { max-width: 96px; }
    }
  </style>
</head>
<body class="bg-light">

<?php include __DIR__ . '/navbar.php'; ?>

<div class="container mt-4">
  <h2>📊 Dashboard Mensual - <?= nombreMes($mes)." $anio" ?></h2>

  <!-- Filtros -->
  <form method="GET" class="row g-2 mb-4">
    <div class="col-md-2">
      <select name="mes" class="form-select">
        <?php for ($m=1;$m<=12;$m++): ?>
          <option value="<?= $m ?>" <?= $m==$mes?'selected':'' ?>><?= nombreMes($m) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="anio" class="form-select">
        <?php for ($a=date('Y')-1;$a<=date('Y')+1;$a++): ?>
          <option value="<?= $a ?>" <?= $a==$anio?'selected':'' ?>><?= $a ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary">Filtrar</button>
    </div>
  </form>

  <!-- Tarjetas Zonas + Global -->
  <div class="row mb-4">
    <?php foreach ($zonas as $zona => $info): 
      $cumpl = $info['cuota']>0 ? ($info['ventas']/$info['cuota']*100) : 0;
    ?>
      <div class="col-md-4 mb-3">
        <div class="card shadow text-center">
          <div class="card-header bg-dark text-white">Zona <?= htmlspecialchars($zona) ?></div>
          <div class="card-body">
            <h5><?= number_format($cumpl,1) ?>% Cumplimiento</h5>
            <p class="mb-0">
              Unidades: <?= (int)$info['unidades'] ?><br>
              Ventas: $<?= number_format($info['ventas'],2) ?><br>
              Cuota: $<?= number_format($info['cuota'],2) ?>
            </p>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="col-md-4 mb-3">
      <div class="card shadow text-center">
        <div class="card-header bg-primary text-white">🌎 Global Compañía</div>
        <div class="card-body">
          <h5><?= number_format($porcentajeGlobal,1) ?>% Cumplimiento</h5>
          <p class="mb-0">
            Unidades: <?= (int)$totalGlobalUnidades ?><br>
            Ventas: $<?= number_format($totalGlobalVentas,2) ?><br>
            Cuota: $<?= number_format($totalGlobalCuota,2) ?>
          </p>
        </div>
      </div>
    </div>
  </div>

  <!-- Gráfica mensual por semanas -->
  <div class="card shadow mb-4">
    <div class="card-header bg-dark text-white">Comportamiento por Semanas del Mes (mar–lun) — Sucursales</div>
    <div class="card-body">
      <div style="position:relative; height:220px;">
        <canvas id="chartMensualSemanas"></canvas>
      </div>
      <small class="text-muted d-block mt-2">
        * Toca los nombres en la leyenda para ocultar/mostrar sucursales.
      </small>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs" role="tablist">
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-suc">Sucursales</button></li>
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-ej">Ejecutivos</button></li>
  </ul>

  <div class="tab-content">
    <!-- Sucursales -->
    <div class="tab-pane fade" id="tab-suc" role="tabpanel">
      <div class="card shadow mt-3">
        <div class="card-header bg-primary text-white">Sucursales</div>
        <div class="card-body p-0">
          <div class="table-responsive-sm"><!-- scroll solo en ≤576px si hace falta -->
            <table class="table table-bordered table-striped table-sm mb-0 align-middle">
              <thead class="table-dark">
                <tr>
                  <th>Sucursal</th>
                  <!-- Oculto en móvil -->
                  <th class="d-none d-sm-table-cell">Zona</th>
                  <!-- Oculto en móvil -->
                  <th class="d-none d-sm-table-cell">Unidades</th>
                  <th>Cuota Unid.</th>
                  <th class="w-120">Ventas $</th>
                  <th>Cuota $</th>
                  <th>% Cumpl.</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($sucursales as $s): 
                  $fila  = badgeFila($s['cumplimiento']);
                  $estado= $s['cumplimiento']>=100?"✅":($s['cumplimiento']>=60?"⚠️":"❌");
                ?>
                <tr class="<?= $fila ?>">
                  <!-- Nombre completo en móvil (sin elipsis) -->
                  <td class="clip suc-name" title="<?= htmlspecialchars($s['sucursal']) ?>"><?= htmlspecialchars($s['sucursal']) ?></td>
                  <!-- Oculto en móvil -->
                  <td class="d-none d-sm-table-cell"><?= htmlspecialchars($s['zona'] ?: 'Sin zona') ?></td>
                  <!-- Oculto en móvil -->
                  <td class="d-none d-sm-table-cell num"><?= (int)$s['unidades'] ?></td>
                  <td class="num"><?= (int)$s['cuota_unidades'] ?></td>
                  <td class="num">$<?= number_format($s['ventas'],2) ?></td>
                  <td class="num">$<?= number_format($s['cuota_monto'],2) ?></td>
                  <td class="num"><?= round($s['cumplimiento'],1) ?>% <?= $estado ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Ejecutivos + Gerentes -->
    <div class="tab-pane fade show active" id="tab-ej" role="tabpanel">
      <div class="card shadow mt-3">
        <div class="card-header bg-dark text-white">Productividad mensual por Ejecutivo</div>
        <div class="card-body p-0">
          <div class="table-responsive-sm">
            <table class="table table-striped table-bordered table-sm mb-0 align-middle">
              <thead class="table-dark">
                <tr>
                  <th>Ejecutivo</th>
                  <th>Sucursal</th>
                  <th>Unidades</th>
                  <!-- Oculto en móvil -->
                  <th class="d-none d-sm-table-cell">Ventas $</th>
                  <th>Cuota Mes (u)</th>
                  <th>% Cumpl. (Unid.)</th>
                  <!-- Oculto en móvil -->
                  <th class="d-none d-sm-table-cell">Progreso</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ejecutivos as $e):
                  $pct = $e['cumpl_uni'];
                  $pctRound = ($pct===null) ? null : round($pct,1);
                  $fila = badgeFila($pct);
                  $barClass = ($pct===null) ? 'bg-secondary' : ($pct>=100?'bg-success':($pct>=60?'bg-warning':'bg-danger'));
                ?>
                <tr class="<?= $fila ?>">
                  <td class="clip" title="<?= htmlspecialchars($e['nombre']) ?>"><?= htmlspecialchars($e['nombre']) ?></td>
                  <td class="clip" title="<?= htmlspecialchars($e['sucursal']) ?>"><?= htmlspecialchars($e['sucursal']) ?></td>
                  <td class="num"><?= (int)$e['unidades'] ?></td>
                  <!-- Oculto en móvil -->
                  <td class="d-none d-sm-table-cell num">$<?= number_format($e['ventas'],2) ?></td>
                  <td class="num"><?= number_format($e['cuota_unidades'],2) ?></td>
                  <td class="num"><?= $pct===null ? '–' : ($pctRound.'%') ?></td>
                  <!-- Oculto en móvil -->
                  <td class="d-none d-sm-table-cell" style="min-width:160px">
                    <div class="progress">
                      <div class="progress-bar <?= $barClass ?>" role="progressbar"
                           style="width: <?= $pct===null? 0 : min(100,$pctRound) ?>%">
                           <?= $pct===null ? 'Sin cuota' : ($pctRound.'%') ?>
                      </div>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Datos para la gráfica mensual por semanas
const labelsSemanas = <?= json_encode($labelsSemanas, JSON_UNESCAPED_UNICODE) ?>;
const datasetsMonth = <?= json_encode($datasetsMonth, JSON_UNESCAPED_UNICODE) ?>;

new Chart(document.getElementById('chartMensualSemanas').getContext('2d'), {
  type: 'line',
  data: { labels: labelsSemanas, datasets: datasetsMonth },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: { title: { display: false }, legend: { position: 'bottom' } },
    scales: { y: { beginAtZero: true, title: { display: true, text: 'Unidades' } } }
  }
});
</script>
</body>
</html>
