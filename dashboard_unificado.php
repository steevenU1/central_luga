<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
include 'db.php';

/* ==============================
   Semana actual y semana anterior
================================= */
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = (int)$hoy->format('N'); // 1=Lun..7=Dom
    $dif = $diaSemana - 2; // Martes=2
    if ($dif < 0) $dif += 7;
    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0,0,0);
    if ($offset > 0) $inicio->modify('-'.(7*$offset).' days');
    $fin = clone $inicio; $fin->modify('+6 days')->setTime(23,59,59);
    return [$inicio, $fin];
}

$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioObj, $finObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioObj->format('Y-m-d');
$finSemana    = $finObj->format('Y-m-d');

// Semana anterior respecto a la seleccionada
list($inicioPrevObj, $finPrevObj) = obtenerSemanaPorIndice($semanaSeleccionada + 1);
$inicioPrev = $inicioPrevObj->format('Y-m-d');
$finPrev    = $finPrevObj->format('Y-m-d');

/* ==============================
   Helpers de tendencia
================================= */
function arrowIcon($delta) {
    if ($delta > 0) return ['▲','text-success'];
    if ($delta < 0) return ['▼','text-danger'];
    return ['▬','text-secondary'];
}
function pctDelta($curr, $prev) {
    if ($prev == 0) return null; // evita % infinito
    return (($curr - $prev) / $prev) * 100.0;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ==============================
   Dashboard Ejecutivos (semanal)
================================= */
// Semana actual
$sqlEjecutivos = "
    SELECT 
        u.id, u.nombre, u.rol, s.nombre AS sucursal,
        (
            SELECT ec.cuota_ejecutivo
            FROM esquemas_comisiones ec
            WHERE ec.activo=1
              AND ec.fecha_inicio <= ?
              AND (ec.fecha_fin IS NULL OR ec.fecha_fin >= ?)
            ORDER BY ec.fecha_inicio DESC
            LIMIT 1
        ) AS cuota_ejecutivo,
        IFNULL(SUM(
            CASE 
                WHEN dv.id IS NULL THEN 0
                WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                WHEN v.tipo_venta='Financiamiento+Combo' 
                     AND dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id)
                     THEN 2
                ELSE 1
            END
        ),0) AS unidades,
        IFNULL(SUM(dv.precio_unitario),0) AS total_ventas
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN ventas v 
        ON v.id_usuario = u.id 
        AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE s.tipo_sucursal='Tienda' AND u.activo = 1
    GROUP BY u.id
    ORDER BY unidades DESC, total_ventas DESC
";
$stmt = $conn->prepare($sqlEjecutivos);
$stmt->bind_param("ssss", $inicioSemana, $finSemana, $inicioSemana, $finSemana);
$stmt->execute();
$resEjecutivos = $stmt->get_result();

$rankingEjecutivos = [];
while ($row = $resEjecutivos->fetch_assoc()) {
    $row['unidades']        = (int)$row['unidades'];
    $row['total_ventas']    = (float)$row['total_ventas'];
    $row['cuota_ejecutivo'] = (int)$row['cuota_ejecutivo'];
    $row['cumplimiento']    = $row['cuota_ejecutivo']>0 ? ($row['unidades']/$row['cuota_ejecutivo']*100) : 0;
    $rankingEjecutivos[]    = $row;
}
$top3Ejecutivos = array_slice(array_column($rankingEjecutivos, 'id'), 0, 3);

// Semana anterior (para tendencia ejecutivos)
$sqlEjecutivosPrev = "
    SELECT 
        u.id,
        (
            SELECT ec.cuota_ejecutivo
            FROM esquemas_comisiones ec
            WHERE ec.activo=1
              AND ec.fecha_inicio <= ?
              AND (ec.fecha_fin IS NULL OR ec.fecha_fin >= ?)
            ORDER BY ec.fecha_inicio DESC
            LIMIT 1
        ) AS cuota_prev,
        IFNULL(SUM(
            CASE 
                WHEN dv.id IS NULL THEN 0
                WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                WHEN v.tipo_venta='Financiamiento+Combo' 
                     AND dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id)
                     THEN 2
                ELSE 1
            END
        ),0) AS unidades_prev
    FROM usuarios u
    LEFT JOIN ventas v 
        ON v.id_usuario = u.id 
        AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE u.activo = 1
    GROUP BY u.id
";
$stmtP = $conn->prepare($sqlEjecutivosPrev);
$stmtP->bind_param("ssss", $inicioPrev, $finPrev, $inicioPrev, $finPrev);
$stmtP->execute();
$resEjPrev = $stmtP->get_result();
$prevEjMap = [];
while ($r = $resEjPrev->fetch_assoc()) {
    $prevEjMap[(int)$r['id']] = [
        'cuota_prev'     => (int)$r['cuota_prev'],
        'unidades_prev'  => (int)$r['unidades_prev']
    ];
}
foreach ($rankingEjecutivos as &$r) {
    $p = $prevEjMap[(int)$r['id']] ?? ['cuota_prev'=>0,'unidades_prev'=>0];
    $r['delta_unidades'] = $r['unidades'] - (int)$p['unidades_prev'];
}
unset($r);

/* ==============================
   Dashboard Sucursales (semanal)
================================= */
// Semana actual
$sqlSucursales = "
    SELECT s.id AS id_sucursal, s.nombre AS sucursal, s.zona,
           (
               SELECT cs.cuota_monto
               FROM cuotas_sucursales cs
               WHERE cs.id_sucursal = s.id AND cs.fecha_inicio <= ?
               ORDER BY cs.fecha_inicio DESC LIMIT 1
           ) AS cuota_semanal,
           IFNULL(SUM(
                CASE 
                    WHEN dv.id IS NULL THEN 0
                    WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                    WHEN v.tipo_venta='Financiamiento+Combo' 
                         AND dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta = v.id)
                         THEN 2
                    ELSE 1
                END
           ),0) AS unidades,
           IFNULL(SUM(CASE WHEN dv.id IS NULL OR LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE dv.precio_unitario END),0) AS total_ventas
    FROM sucursales s
    LEFT JOIN (
        SELECT v.id, v.id_sucursal, v.fecha_venta, v.tipo_venta
        FROM ventas v
        WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    ) v ON v.id_sucursal = s.id
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE s.tipo_sucursal='Tienda'
    GROUP BY s.id
    ORDER BY total_ventas DESC
";
$stmt2 = $conn->prepare($sqlSucursales);
$stmt2->bind_param("sss", $inicioSemana, $inicioSemana, $finSemana);
$stmt2->execute();
$resSucursales = $stmt2->get_result();

$sucursales = [];
$totalUnidades = 0; $totalVentasGlobal = 0; $totalCuotaGlobal = 0;

while ($row = $resSucursales->fetch_assoc()) {
    $row['unidades']      = (int)$row['unidades'];
    $row['total_ventas']  = (float)$row['total_ventas'];
    $row['cuota_semanal'] = (float)$row['cuota_semanal'];
    $row['cumplimiento']  = $row['cuota_semanal']>0 ? ($row['total_ventas']/$row['cuota_semanal']*100) : 0;
    $sucursales[] = $row;
    $totalUnidades     += $row['unidades'];
    $totalVentasGlobal += $row['total_ventas'];
    $totalCuotaGlobal  += $row['cuota_semanal'];
}
$porcentajeGlobal = $totalCuotaGlobal>0 ? ($totalVentasGlobal/$totalCuotaGlobal)*100 : 0;

// Semana anterior (para sucursales)
$sqlSucursalesPrev = "
    SELECT s.id AS id_sucursal,
           (
               SELECT cs.cuota_monto
               FROM cuotas_sucursales cs
               WHERE cs.id_sucursal = s.id AND cs.fecha_inicio <= ?
               ORDER BY cs.fecha_inicio DESC LIMIT 1
           ) AS cuota_prev,
           IFNULL(SUM(CASE WHEN dv.id IS NULL OR LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE dv.precio_unitario END),0) AS ventas_prev
    FROM sucursales s
    LEFT JOIN (
        SELECT v.id, v.id_sucursal, v.fecha_venta, v.tipo_venta
        FROM ventas v
        WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    ) v ON v.id_sucursal = s.id
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE s.tipo_sucursal='Tienda'
    GROUP BY s.id
";
$stmt2p = $conn->prepare($sqlSucursalesPrev);
$stmt2p->bind_param("sss", $inicioPrev, $inicioPrev, $finPrev);
$stmt2p->execute();
$resSucPrev = $stmt2p->get_result();
$prevSucMap = [];
while ($r = $resSucPrev->fetch_assoc()) {
    $prevSucMap[(int)$r['id_sucursal']] = [
        'cuota_prev'    => (float)$r['cuota_prev'],
        'ventas_prev'   => (float)$r['ventas_prev'],
    ];
}
// Inyecta deltas a sucursales (por monto)
foreach ($sucursales as &$s) {
    $p = $prevSucMap[(int)$s['id_sucursal']] ?? ['cuota_prev'=>0,'ventas_prev'=>0.0];
    $s['delta_monto'] = $s['total_ventas'] - (float)$p['ventas_prev'];
    $s['pct_delta_monto'] = ($p['ventas_prev'] > 0)
        ? (($s['total_ventas'] - (float)$p['ventas_prev']) / (float)$p['ventas_prev']) * 100
        : null;
}
unset($s);

/* ==============================
   Agrupación por Zonas
================================= */
$zonas = [];
foreach ($sucursales as $s) {
    $z = $s['zona'];
    if (!isset($zonas[$z])) $zonas[$z] = ['unidades'=>0,'ventas'=>0,'cuota'=>0];
    $zonas[$z]['unidades'] += $s['unidades'];
    $zonas[$z]['ventas']   += $s['total_ventas'];
    $zonas[$z]['cuota']    += $s['cuota_semanal'];
}
foreach ($zonas as $z => &$info) {
    $info['cumplimiento'] = $info['cuota']>0 ? ($info['ventas']/$info['cuota']*100) : 0;
}
unset($info);

/* ==============================
   Serie semanal (mar–lun)
================================= */
$labelsSemanaISO = [];
$labelsSemanaVis = [];
$diasES = [1=>'Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
$cur = clone $inicioObj;
for ($i=0; $i<7; $i++) {
    $labelsSemanaISO[] = $cur->format('Y-m-d');
    $labelsSemanaVis[] = $diasES[(int)$cur->format('N')] . ' ' . $cur->format('d/m');
    $cur->modify('+1 day');
}
$sqlWeek = "
SELECT s.nombre AS sucursal,
       DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
       SUM(CASE 
             WHEN dv.id IS NULL THEN 0
             WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
             WHEN v.tipo_venta='Financiamiento+Combo' 
                  AND dv.id = (SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta=v.id)
                  THEN 2
             ELSE 1
           END) AS unidades
FROM sucursales s
LEFT JOIN ventas v
  ON v.id_sucursal = s.id
 AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
LEFT JOIN productos p ON p.id = dv.id_producto
WHERE s.tipo_sucursal='Tienda'
GROUP BY s.id, dia
";
$stmtW = $conn->prepare($sqlWeek);
$stmtW->bind_param("ss", $inicioSemana, $finSemana);
$stmtW->execute();
$resW = $stmtW->get_result();
$weekSeries = [];
while ($r = $resW->fetch_assoc()) {
    $suc = $r['sucursal']; $dia = $r['dia']; $u = (int)$r['unidades'];
    if (!isset($weekSeries[$suc])) $weekSeries[$suc] = [];
    if ($dia) $weekSeries[$suc][$dia] = $u;
}
foreach ($sucursales as $s) { if (!isset($weekSeries[$s['sucursal']])) $weekSeries[$s['sucursal']] = []; }
$datasetsWeek = [];
foreach ($weekSeries as $sucursalNombre => $serie) {
    $row = [];
    foreach ($labelsSemanaISO as $d) { $row[] = isset($serie[$d]) ? (int)$serie[$d] : 0; }
    $datasetsWeek[] = ['label'=>$sucursalNombre,'data'=>$row,'tension'=>0.3,'borderWidth'=>2,'pointRadius'=>2];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1"> <!-- importante para móvil -->
<title>Dashboard Semanal Luga</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
  /* ===== Overrides del NAVBAR SOLO para esta vista ===== */
  /* Base local: asegura escala consistente */
  #topbar{ font-size:16px; }

  /* Móvil (≤576px): subimos legibilidad y toques de spacing */
  @media (max-width:576px){
    #topbar{
      font-size:16px;                 /* 1em interno = 16px */
      --brand-font:1.00em;            /* título un poco más grande */
      --nav-font:.95em;               /* items y dropdown más legibles */
      --drop-font:.95em;
      --icon-em:1.05em;
      --pad-y:.44em;                  /* más “tap area” */
      --pad-x:.62em;
    }
    #topbar .navbar-brand img{ width:1.8em; height:1.8em; }
    #topbar .brand-title{ letter-spacing:.2px; }
    #topbar .btn-asistencia{ font-size:.95em; padding:.5em .9em !important; border-radius:12px; }
    #topbar .nav-avatar, #topbar .nav-initials{ width:2.1em; height:2.1em; }
    #topbar .navbar-toggler{ padding:.45em .7em; }
  }

  /* Ultra-compacto (≤360px) */
  @media (max-width:360px){
    #topbar{ font-size:15px; }
  }

  /* Estilos propios de la página */
  .trend { font-size: .875rem; white-space: nowrap; }
  .trend .delta { font-weight: 600; }
  .w-120 { min-width:120px; }
</style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📊 Dashboard Semanal Luga</h2>

    <!-- Selector de semana -->
    <form method="GET" class="mb-3">
        <label><strong>Selecciona semana:</strong></label>
        <select name="semana" class="form-select w-auto d-inline-block" onchange="this.form.submit()">
            <?php for ($i=0; $i<8; $i++):
                list($ini, $fin) = obtenerSemanaPorIndice($i);
                $texto = "Semana del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
            ?>
            <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $texto ?></option>
            <?php endfor; ?>
        </select>
        <span class="ms-2 text-muted small">Comparando con: <?= $inicioPrevObj->format('d/m/Y') ?> → <?= $finPrevObj->format('d/m/Y') ?></span>
    </form>

    <!-- Tarjetas por zonas + global -->
    <div class="row mb-4">
        <?php foreach ($zonas as $zona => $info): ?>
            <div class="col-md-4 mb-3">
                <div class="card shadow text-center">
                    <div class="card-header bg-dark text-white">Zona <?= h($zona) ?></div>
                    <div class="card-body">
                        <h5><?= number_format($info['cumplimiento'],1) ?>% Cumplimiento</h5>
                        <p>
                            Unidades: <?= (int)$info['unidades'] ?><br>
                            Ventas: $<?= number_format($info['ventas'],2) ?><br>
                            Cuota: $<?= number_format($info['cuota'],2) ?>
                        </p>
                        <div class="progress" style="height:20px">
                            <div class="progress-bar <?= $info['cumplimiento']>=100?'bg-success':($info['cumplimiento']>=60?'bg-warning':'bg-danger') ?>"
                                 style="width:<?= min(100,$info['cumplimiento']) ?>%">
                                <?= number_format(min(100,$info['cumplimiento']),1) ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="col-md-4 mb-3">
            <div class="card shadow text-center">
                <div class="card-header bg-primary text-white">Global Compañía</div>
                <div class="card-body">
                    <h5><?= number_format($porcentajeGlobal,1) ?>% Cumplimiento</h5>
                    <p>
                        Unidades: <?= $totalUnidades ?><br>
                        Ventas: $<?= number_format($totalVentasGlobal,2) ?><br>
                        Cuota: $<?= number_format($totalCuotaGlobal,2) ?>
                    </p>
                    <div class="progress" style="height:20px">
                        <div class="progress-bar <?= $porcentajeGlobal>=100?'bg-success':($porcentajeGlobal>=60?'bg-warning':'bg-danger') ?>"
                             style="width:<?= min(100,$porcentajeGlobal) ?>%">
                            <?= number_format(min(100,$porcentajeGlobal),1) ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Gráfica semanal -->
    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white">Comportamiento Semanal por Sucursal (mar–lun)</div>
        <div class="card-body">
            <div style="position:relative; height:220px;">
                <canvas id="chartSemanal"></canvas>
            </div>
            <small class="text-muted d-block mt-2">* Toca los nombres en la leyenda para ocultar/mostrar sucursales.</small>
        </div>
    </div>

    <!-- Tablas -->
    <ul class="nav nav-tabs mb-3" id="dashboardTabs">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ejecutivos">Ejecutivos 👔</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sucursales">Sucursales 🏢</button></li>
    </ul>

    <div class="tab-content">
        <!-- Ejecutivos -->
        <div class="tab-pane fade show active" id="ejecutivos">
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white">Ranking de Ejecutivos</div>
                <div class="card-body">
                    <table class="table table-striped table-bordered align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Ejecutivo</th><th>Sucursal</th><th class="w-120">Unidades</th>
                                <th>Total Ventas ($)</th><th>% Cumpl.</th><th>Progreso</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rankingEjecutivos as $r):
                                $cumpl = round($r['cumplimiento'],1);
                                $estado = $cumpl>=100?"✅":($cumpl>=60?"⚠️":"❌");
                                $fila = $cumpl>=100?"table-success":($cumpl>=60?"table-warning":"table-danger");
                                $iconTop = in_array($r['id'],$top3Ejecutivos) ? ' 🏆' : '';
                                // Tendencia por UNIDADES
                                $dU = (int)$r['delta_unidades'];
                                [$icoU,$clsU] = arrowIcon($dU);
                                $pctU = pctDelta($r['unidades'], $r['unidades'] - $dU);
                            ?>
                            <tr class="<?= $fila ?>">
                                <td><?= h($r['nombre']).$iconTop ?></td>
                                <td><?= h($r['sucursal']) ?></td>
                                <td>
                                    <?= (int)$r['unidades'] ?>
                                    <div class="trend"><span class="<?= $clsU ?>"><?= $icoU ?></span>
                                      <span class="delta <?= $clsU ?>"><?= ($dU>0?'+':'').$dU ?> u.</span>
                                      <?php if ($pctU!==null): ?><span class="text-muted">(<?= ($pctU>=0?'+':'').number_format($pctU,1) ?>%)</span><?php endif; ?>
                                    </div>
                                </td>
                                <td>$<?= number_format($r['total_ventas'],2) ?></td>
                                <td><?= number_format($cumpl,1) ?>% <?= $estado ?></td>
                                <td>
                                    <div class="progress" style="height:20px">
                                        <div class="progress-bar <?= $cumpl>=100?'bg-success':($cumpl>=60?'bg-warning':'bg-danger') ?>" style="width:<?= min(100,$cumpl) ?>%">
                                            <?= $cumpl ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach;?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sucursales -->
        <div class="tab-pane fade" id="sucursales">
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white">Ranking de Sucursales</div>
                <div class="card-body">
                    <table class="table table-striped table-bordered align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Sucursal</th><th>Zona</th><th>Unidades</th>
                                <th>Cuota ($)</th><th class="w-120">Total Ventas ($)</th>
                                <th>% Cumpl.</th><th>Progreso</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sucursales as $s):
                                $cumpl = round($s['cumplimiento'],1);
                                $estado = $cumpl>=100?"✅":($cumpl>=60?"⚠️":"❌");
                                $fila = $cumpl>=100?"table-success":($cumpl>=60?"table-warning":"table-danger");
                                // TENDENCIA por MONTO
                                $dM = (float)$s['delta_monto'];
                                [$icoM, $clsM] = arrowIcon($dM);
                                $pctM = $s['pct_delta_monto']; // puede ser null
                            ?>
                            <tr class="<?= $fila ?>">
                                <td><?= h($s['sucursal']) ?></td>
                                <td>Zona <?= h($s['zona']) ?></td>
                                <td><?= (int)$s['unidades'] ?></td>
                                <td>$<?= number_format($s['cuota_semanal'],2) ?></td>
                                <td>
                                    $<?= number_format($s['total_ventas'],2) ?>
                                    <div class="trend">
                                      <span class="<?= $clsM ?>"><?= $icoM ?></span>
                                      <span class="delta <?= $clsM ?>"><?= ($dM>=0?'+':'-').'$'.number_format(abs($dM),2) ?></span>
                                      <?php if ($pctM !== null): ?>
                                        <span class="text-muted">(<?= ($pctM>=0?'+':'').number_format($pctM,1) ?>%)</span>
                                      <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= number_format($cumpl,1) ?>% <?= $estado ?></td>
                                <td>
                                    <div class="progress" style="height:20px">
                                        <div class="progress-bar <?= $cumpl>=100?'bg-success':($cumpl>=60?'bg-warning':'bg-danger') ?>" style="width:<?= min(100,$cumpl) ?>%">
                                            <?= $cumpl ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach;?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Datos para la gráfica semanal (todas las sucursales)
const labelsSemana = <?= json_encode($labelsSemanaVis, JSON_UNESCAPED_UNICODE) ?>;
const datasetsWeek = <?= json_encode($datasetsWeek, JSON_UNESCAPED_UNICODE) ?>;

new Chart(document.getElementById('chartSemanal').getContext('2d'), {
  type: 'line',
  data: { labels: labelsSemana, datasets: datasetsWeek },
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
