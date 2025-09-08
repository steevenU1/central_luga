<?php
session_start();
if (empty($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

/* --------------------------
   Utilidades
---------------------------*/
function nombreMes($mes) {
    $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    return $meses[$mes] ?? '';
}
/** Normaliza cualquier valor de zona a "Zona N" */
function normalizarZona($raw){
    $t = trim((string)$raw);
    if ($t === '') return null;
    $t = preg_replace('/^(?:\s*Zona\s+)+/i', 'Zona ', $t);
    if (preg_match('/(\d+)/', $t, $m)) return 'Zona '.(int)$m[1];
    if (preg_match('/^Zona\s+\S+/i', $t)) return preg_replace('/\s+/', ' ', $t);
    return $t; // deja otros nombres tal cual
}
function badgeFila($pct) {
    if ($pct === null) return '';
    return $pct>=100 ? 'table-success' : ($pct>=60 ? 'table-warning' : 'table-danger');
}

/* --------------------------
   Mes/Año seleccionados
---------------------------*/
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');

$inicioMes = sprintf('%04d-%02d-01', $anio, $mes);
$finMes    = date('Y-m-t', strtotime($inicioMes));
$diasMes   = (int)date('t', strtotime($inicioMes));
$factorSem = 7 / max(1,$diasMes); // semanas “efectivas” del mes

/* --------------------------
   Cuota mensual ejecutivos
---------------------------*/
$cuotaMesU_porEj = 0.0;
$cuotaMesM_porEj = 0.0;
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

/* --------------------------
   Subconsulta: ventas por VENTA (mes)
   Reglas:
   - Monto = v.precio_venta (cabecera) UNA sola vez por venta.
   - 'Financiamiento+Combo' = 2 unidades, monto una vez.
   - Si la venta solo tiene modem/mifi, NO suma unidades ni monto.
---------------------------*/
$subVentasAggMes = "
  SELECT
      v.id,
      v.id_usuario,
      v.id_sucursal,
      DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
      /* Unidades: combo=2; si no, cuenta items no-modem */
      CASE
        WHEN LOWER(v.tipo_venta)='financiamiento+combo' THEN 2
        ELSE SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE 1 END)
      END AS unidades,
      /* Monto: cabecera solo si hay al menos 1 no-modem, o si es combo */
      CASE
        WHEN LOWER(v.tipo_venta)='financiamiento+combo' THEN COALESCE(MAX(v.precio_venta),0)
        WHEN SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE 1 END) > 0
          THEN COALESCE(MAX(v.precio_venta),0)
        ELSE 0
      END AS monto
  FROM ventas v
  LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
  LEFT JOIN productos p      ON p.id       = dv.id_producto
  WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY v.id
";

/* --------------------------
   SIMs (ventas_sims) — mapas por usuario y por sucursal
   Reglas:
   - POSPAGO si tipo_venta/tipo_sim/comentarios contienen 'pospago'|'postpago'|'pos'
   - PREPAGO si no es pospago NI 'regalo'
---------------------------*/
$mapSimsByUser = [];
$mapSimsBySuc  = [];

// Por usuario
$sqlSimsUser = "
  SELECT id_usuario,
         SUM(CASE
               WHEN (LOWER(tipo_venta) REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(tipo_sim)   REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(IFNULL(comentarios,'')) REGEXP 'pospago|postpago|\\bpos\\b')
               THEN 1 ELSE 0 END) AS sim_pos,
         SUM(CASE
               WHEN (LOWER(tipo_venta) REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(tipo_sim)   REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(IFNULL(comentarios,'')) REGEXP 'pospago|postpago|\\bpos\\b')
               THEN 0
               WHEN (LOWER(tipo_venta) LIKE '%regalo%'
                  OR LOWER(tipo_sim)   LIKE '%regalo%'
                  OR LOWER(IFNULL(comentarios,'')) LIKE '%regalo%')
               THEN 0
               ELSE 1 END) AS sim_pre
  FROM ventas_sims
  WHERE DATE(CONVERT_TZ(fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY id_usuario
";
$stSU = $conn->prepare($sqlSimsUser);
$stSU->bind_param("ss", $inicioMes, $finMes);
$stSU->execute();
$resSU = $stSU->get_result();
while ($r = $resSU->fetch_assoc()) {
  $mapSimsByUser[(int)$r['id_usuario']] = ['pre'=>(int)$r['sim_pre'], 'pos'=>(int)$r['sim_pos']];
}
$stSU->close();

// Por sucursal
$sqlSimsSuc = "
  SELECT id_sucursal,
         SUM(CASE
               WHEN (LOWER(tipo_venta) REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(tipo_sim)   REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(IFNULL(comentarios,'')) REGEXP 'pospago|postpago|\\bpos\\b')
               THEN 1 ELSE 0 END) AS sim_pos,
         SUM(CASE
               WHEN (LOWER(tipo_venta) REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(tipo_sim)   REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(IFNULL(comentarios,'')) REGEXP 'pospago|postpago|\\bpos\\b')
               THEN 0
               WHEN (LOWER(tipo_venta) LIKE '%regalo%'
                  OR LOWER(tipo_sim)   LIKE '%regalo%'
                  OR LOWER(IFNULL(comentarios,'')) LIKE '%regalo%')
               THEN 0
               ELSE 1 END) AS sim_pre
  FROM ventas_sims
  WHERE DATE(CONVERT_TZ(fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY id_sucursal
";
$stSS = $conn->prepare($sqlSimsSuc);
$stSS->bind_param("ss", $inicioMes, $finMes);
$stSS->execute();
$resSS = $stSS->get_result();
while ($r = $resSS->fetch_assoc()) {
  $mapSimsBySuc[(int)$r['id_sucursal']] = ['pre'=>(int)$r['sim_pre'], 'pos'=>(int)$r['sim_pos']];
}
$stSS->close();

/* --------------------------
   Sucursales: unidades/ventas y cuotas del mes
---------------------------*/
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
/* 👇 Totales globales de SIMs para la fila global */
$totalSimPre         = 0;
$totalSimPos         = 0;

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

    // SIMs por sucursal
    $simS = $mapSimsBySuc[$id_suc] ?? ['pre'=>0,'pos'=>0];

    $sucursales[] = [
        'id_sucursal'     => $id_suc,
        'sucursal'        => $row['sucursal'],
        'zona'            => $row['zona'],
        'unidades'        => (int)$row['unidades'],
        'ventas'          => (float)$row['ventas'],
        'cuota_unidades'  => (int)$cuotaUnidades,
        'cuota_monto'     => (float)$cuotaMonto,
        'cumplimiento'    => $cumpl,
        'sim_prepago'     => (int)$simS['pre'],
        'sim_pospago'     => (int)$simS['pos'],
    ];

    $totalGlobalUnidades += (int)$row['unidades'];
    $totalGlobalVentas   += (float)$row['ventas'];
    $totalGlobalCuota    += (float)$cuotaMonto;

    /* 👇 Acumular SIMs para la fila global */
    $totalSimPre         += (int)$simS['pre'];
    $totalSimPos         += (int)$simS['pos'];
}
$stmt->close();

/* --------------------------
   Zonas (cards)
---------------------------*/
$zonas = [];
foreach ($sucursales as $s) {
    $z = normalizarZona($s['zona'] ?? '') ?? 'Sin zona';
    if (!isset($zonas[$z])) $zonas[$z] = ['unidades'=>0,'ventas'=>0.0,'cuota'=>0.0];
    $zonas[$z]['unidades'] += $s['unidades'];
    $zonas[$z]['ventas']   += $s['ventas'];
    $zonas[$z]['cuota']    += $s['cuota_monto'];
}
$porcentajeGlobal = $totalGlobalCuota > 0 ? ($totalGlobalVentas/$totalGlobalCuota*100) : 0;

/* --------------------------
   Ejecutivos (mensual)
---------------------------*/
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

    // SIMs por usuario
    $simU = $mapSimsByUser[(int)$row['id']] ?? ['pre'=>0,'pos'=>0];

    $ejecutivos[] = [
        'id'             => (int)$row['id'],
        'nombre'         => $row['nombre'],
        'sucursal'       => $row['sucursal'],
        'unidades'       => (int)$row['unidades'],
        'ventas'         => (float)$row['ventas'],
        'cuota_unidades' => $cuotaMesU_porEj,
        'cumpl_uni'      => $cumpl_uni,
        'sim_prepago'    => (int)$simU['pre'],
        'sim_pospago'    => (int)$simU['pos'],
    ];
}
$stEj->close();

/* --------------------------
   Serie para gráfica de barras (una barra por sucursal)
---------------------------*/
$seriesSucursales = [];
foreach ($sucursales as $row) {
    $seriesSucursales[] = [
        'label'    => $row['sucursal'],
        'unidades' => (int)$row['unidades'],
        'ventas'   => round((float)$row['ventas'], 2),
    ];
}
$TOP_BARS = 15;

/* --------------------------
   Agrupar sucursales por zona (para la TABLA)
---------------------------*/
$gruposZona = []; // 'Zona N' => ['rows'=>[], 'tot'=>...]
foreach ($sucursales as $s) {
    $zonaNorm = normalizarZona($s['zona'] ?? '') ?? 'Sin zona';
    if (!isset($gruposZona[$zonaNorm])) {
        $gruposZona[$zonaNorm] = [
            'rows' => [], 'tot'  => ['unidades'=>0, 'ventas'=>0.0, 'cuota'=>0.0, 'cumpl'=>0.0, 'sim_pre'=>0, 'sim_pos'=>0]
        ];
    }
    $gruposZona[$zonaNorm]['rows'][] = $s;
    $gruposZona[$zonaNorm]['tot']['unidades'] += (int)$s['unidades'];
    $gruposZona[$zonaNorm]['tot']['ventas']   += (float)$s['ventas'];
    $gruposZona[$zonaNorm]['tot']['cuota']    += (float)$s['cuota_monto'];
    $gruposZona[$zonaNorm]['tot']['sim_pre']  += (int)$s['sim_prepago'];
    $gruposZona[$zonaNorm]['tot']['sim_pos']  += (int)$s['sim_pospago'];
}
foreach ($gruposZona as &$g) {
    usort($g['rows'], function($a,$b){ return $b['ventas'] <=> $a['ventas']; });
    $g['tot']['cumpl'] = $g['tot']['cuota']>0 ? ($g['tot']['ventas']/$g['tot']['cuota']*100) : 0.0;
}
unset($g);
uksort($gruposZona, function($za,$zb) use ($gruposZona){
    return $gruposZona[$zb]['tot']['ventas'] <=> $gruposZona[$za]['tot']['ventas'];
});
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard Mensual</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .clip  { max-width: 160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .num   { font-variant-numeric: tabular-nums; letter-spacing:-.2px; }
    .w-120 { min-width:120px; }
    .progress{ height:18px } .progress-bar{ font-size:.75rem }
    .tab-pane{ padding-top:10px }
    .table .progress{ width:100%; } /* asegura barra full-width */

    /* Móvil compacto */
    @media (max-width:576px){
      body { font-size:14px; }
      .container { padding:0 8px; }
      .card .card-header{ padding:.5rem .65rem; font-size:.95rem; }
      .card .card-body{ padding:.65rem; }
      .table{ font-size:12px; }
      .table thead th{ font-size:11px; }
      .table td,.table th{ padding:.35rem .45rem; }
      .clip { max-width:120px; }
    }
    @media (max-width:360px){
      .table{ font-size:11px; }
      .table td,.table th{ padding:.30rem .40rem; }
      .clip { max-width:96px; }
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

  <!-- Cards Zonas + Global -->
  <div class="row mb-4">
    <?php foreach ($zonas as $zona => $info): 
      $cumpl = $info['cuota']>0 ? ($info['ventas']/$info['cuota']*100) : 0;
      $barra = $cumpl>=100 ? 'bg-success' : ($cumpl>=60 ? 'bg-warning' : 'bg-danger');
    ?>
      <div class="col-md-4 mb-3">
        <div class="card shadow text-center">
          <div class="card-header bg-dark text-white"><?= htmlspecialchars($zona) ?></div>
          <div class="card-body">
            <h5><?= number_format($cumpl,1) ?>% Cumplimiento</h5>
            <p class="mb-2">
              Unidades: <?= (int)$info['unidades'] ?><br>
              Ventas: $<?= number_format($info['ventas'],2) ?><br>
              Cuota:  $<?= number_format($info['cuota'],2) ?>
            </p>
            <div class="progress"><div class="progress-bar <?= $barra ?>" style="width:<?= min(100,$cumpl) ?>%"><?= number_format(min(100,$cumpl),1) ?>%</div></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php
      $barraG = $porcentajeGlobal>=100?'bg-success':($porcentajeGlobal>=60?'bg-warning':'bg-danger');
    ?>
    <div class="col-md-4 mb-3">
      <div class="card shadow text-center">
        <div class="card-header bg-primary text-white">🌎 Global Compañía</div>
        <div class="card-body">
          <h5><?= number_format($porcentajeGlobal,1) ?>% Cumplimiento</h5>
          <p class="mb-2">
            Unidades: <?= (int)$totalGlobalUnidades ?><br>
            Ventas: $<?= number_format($totalGlobalVentas,2) ?><br>
            Cuota:  $<?= number_format($totalGlobalCuota,2) ?>
          </p>
          <div class="progress"><div class="progress-bar <?= $barraG ?>" style="width:<?= min(100,$porcentajeGlobal) ?>%"><?= number_format(min(100,$porcentajeGlobal),1) ?>%</div></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Gráfica mensual: UNA BARRA POR SUCURSAL -->
  <div class="card shadow mb-4">
    <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between">
      <span>Resumen mensual por sucursal</span>
      <div class="btn-group btn-group-sm">
        <button id="btnUnidades" class="btn btn-primary" type="button">Unidades</button>
        <button id="btnVentas"   class="btn btn-outline-light" type="button">Ventas ($)</button>
      </div>
    </div>
    <div class="card-body">
      <div style="position:relative; height:380px;">
        <canvas id="chartMensualSuc"></canvas>
      </div>
      <small class="text-muted d-block mt-2">* Se muestran Top-<?= $TOP_BARS ?> sucursales + “Otras”.</small>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-suc">Sucursales</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ej">Ejecutivos</button></li>
  </ul>

  <div class="tab-content">
    <!-- Sucursales (agrupado por zona) -->
    <div class="tab-pane fade show active" id="tab-suc" role="tabpanel">
      <div class="card shadow mt-3">
        <div class="card-header bg-primary text-white">Ranking de Sucursales (agrupado por zona)</div>
        <div class="card-body">
          <div class="table-responsive-sm">
            <table class="table table-striped table-bordered table-sm align-middle">
              <thead class="table-dark">
                <tr>
                  <th>Sucursal</th>
                  <th class="d-none d-md-table-cell">Zona</th>
                  <th class="d-none d-md-table-cell">Unidades</th>
                  <th class="d-none d-md-table-cell col-fit">SIM Prep.</th>
                  <th class="d-none d-md-table-cell col-fit">SIM Pos.</th>
                  <th class="d-none d-lg-table-cell col-fit">Cuota ($)</th>
                  <th class="col-fit">Total Ventas ($)</th>
                  <th class="col-fit">% Cumpl.</th>
                  <th class="d-none d-lg-table-cell">Progreso</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($gruposZona as $zona => $grp): ?>
                <!-- Encabezado de grupo (colspans responsivos) -->
                <!-- XS/SM: 3 columnas -->
                <tr class="table-secondary d-table-row d-md-none">
                  <th colspan="3" class="text-start"><?= htmlspecialchars($zona) ?></th>
                </tr>
                <!-- MD (>=768 & <992): 7 columnas -->
                <tr class="table-secondary d-none d-md-table-row d-lg-none">
                  <th colspan="7" class="text-start"><?= htmlspecialchars($zona) ?></th>
                </tr>
                <!-- LG+ (>=992): 9 columnas -->
                <tr class="table-secondary d-none d-lg-table-row">
                  <th colspan="9" class="text-start"><?= htmlspecialchars($zona) ?></th>
                </tr>

                <?php foreach ($grp['rows'] as $s):
                  $cumpl = round($s['cumplimiento'],1);
                  $estado = $cumpl>=100?"✅":($cumpl>=60?"⚠️":"❌");
                  $fila = $cumpl>=100?"table-success":($cumpl>=60?"table-warning":"table-danger");
                ?>
                  <tr class="<?= $fila ?>">
                    <td class="clip" title="<?= htmlspecialchars($s['sucursal']) ?>"><?= htmlspecialchars($s['sucursal']) ?></td>
                    <td class="d-none d-md-table-cell"><?= htmlspecialchars(normalizarZona($s['zona'] ?? '') ?? '—') ?></td>
                    <td class="d-none d-md-table-cell num"><?= (int)$s['unidades'] ?></td>

                    <td class="d-none d-md-table-cell num col-fit"><?= (int)$s['sim_prepago'] ?></td>
                    <td class="d-none d-md-table-cell num col-fit"><?= (int)$s['sim_pospago'] ?></td>

                    <td class="d-none d-lg-table-cell num col-fit">$<?= number_format($s['cuota_monto'],2) ?></td>

                    <td class="num col-fit">$<?= number_format($s['ventas'],2) ?></td>

                    <td class="num col-fit"><?= number_format($cumpl,1) ?>% <?= $estado ?></td>

                    <td class="d-none d-lg-table-cell">
                      <div class="progress" style="height:20px">
                        <div class="progress-bar <?= $cumpl>=100?'bg-success':($cumpl>=60?'bg-warning':'bg-danger') ?>" style="width:<?= min(100,$cumpl) ?>%"><?= $cumpl ?>%</div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>

                <!-- Totales por zona -->
                <?php
                  $tzU = (int)$grp['tot']['unidades'];
                  $tzV = (float)$grp['tot']['ventas'];
                  $tzC = (float)$grp['tot']['cuota'];
                  $tzP = (float)$grp['tot']['cumpl'];
                  $tzPre = (int)$grp['tot']['sim_pre'];
                  $tzPos = (int)$grp['tot']['sim_pos'];
                  $cls = $tzP>=100?'bg-success':($tzP>=60?'bg-warning':'bg-danger');
                ?>
                <!-- XS/SM -->
                <tr class="table-light fw-semibold d-table-row d-md-none">
                  <td class="text-end">Total <?= htmlspecialchars($zona) ?>:</td>
                  <td class="num col-fit">$<?= number_format($tzV,2) ?></td>
                  <td class="num col-fit"><?= number_format($tzP,1) ?>%</td>
                </tr>
                <!-- MD+ (fila sirve para MD y LG; celdas LG llevan d-none d-lg-table-cell) -->
                <tr class="table-light fw-semibold d-none d-md-table-row">
                  <td colspan="2" class="text-end">Total <?= htmlspecialchars($zona) ?>:</td>
                  <td class="num"><?= $tzU ?></td>
                  <td class="d-none d-md-table-cell num col-fit"><?= $tzPre ?></td>
                  <td class="d-none d-md-table-cell num col-fit"><?= $tzPos ?></td>
                  <td class="d-none d-lg-table-cell num col-fit">$<?= number_format($tzC,2) ?></td>
                  <td class="num col-fit">$<?= number_format($tzV,2) ?></td>
                  <td class="num col-fit"><?= number_format($tzP,1) ?>%</td>
                  <td class="d-none d-lg-table-cell">
                    <div class="progress" style="height:20px">
                      <div class="progress-bar <?= $cls ?>" style="width:<?= min(100,$tzP) ?>%"><?= number_format(min(100,$tzP),1) ?>%</div>
                    </div>
                  </td>
                </tr>

              <?php endforeach; ?>

              <?php
                /* ====== TOTAL GLOBAL (móvil y escritorio) ====== */
                $clsG = $porcentajeGlobal >= 100 ? 'bg-success' : ($porcentajeGlobal >= 60 ? 'bg-warning' : 'bg-danger');
              ?>
              <!-- XS/SM -->
              <tr class="table-primary fw-bold d-table-row d-md-none">
                <td class="text-end">Total global:</td>
                <td class="num col-fit">$<?= number_format($totalGlobalVentas, 2) ?></td>
                <td class="num col-fit"><?= number_format($porcentajeGlobal, 1) ?>%</td>
              </tr>
              <!-- MD+ -->
              <tr class="table-primary fw-bold d-none d-md-table-row">
                <td colspan="2" class="text-end">Total global:</td>
                <td class="num"><?= (int)$totalGlobalUnidades ?></td>
                <td class="d-none d-md-table-cell num col-fit"><?= (int)$totalSimPre ?></td>
                <td class="d-none d-md-table-cell num col-fit"><?= (int)$totalSimPos ?></td>
                <td class="d-none d-lg-table-cell num col-fit">$<?= number_format($totalGlobalCuota, 2) ?></td>
                <td class="num col-fit">$<?= number_format($totalGlobalVentas, 2) ?></td>
                <td class="num col-fit"><?= number_format($porcentajeGlobal, 1) ?>%</td>
                <td class="d-none d-lg-table-cell">
                  <div class="progress" style="height:20px">
                    <div class="progress-bar <?= $clsG ?>" style="width:<?= min(100, $porcentajeGlobal) ?>%">
                      <?= number_format(min(100, $porcentajeGlobal), 1) ?>%
                    </div>
                  </div>
                </td>
              </tr>
              <!-- ====== /TOTAL GLOBAL ====== -->

              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Ejecutivos -->
    <div class="tab-pane fade" id="tab-ej" role="tabpanel">
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
                  <th class="d-none d-md-table-cell col-fit">SIM Prep.</th>
                  <th class="d-none d-md-table-cell col-fit">SIM Pos.</th>
                  <th class="d-none d-sm-table-cell">Ventas $</th>
                  <th>Cuota Mes (u)</th>
                  <th>% Cumpl. (Unid.)</th>
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
                  <td class="d-none d-md-table-cell num col-fit"><?= (int)$e['sim_prepago'] ?></td>
                  <td class="d-none d-md-table-cell num col-fit"><?= (int)$e['sim_pospago'] ?></td>
                  <td class="d-none d-sm-table-cell num">$<?= number_format($e['ventas'],2) ?></td>
                  <td class="num"><?= number_format($e['cuota_unidades'],2) ?></td>
                  <td class="num"><?= $pct===null ? '–' : ($pctRound.'%') ?></td>
                  <td class="d-none d-sm-table-cell" style="min-width:160px">
                    <div class="progress"><div class="progress-bar <?= $barClass ?>" style="width: <?= $pct===null? 0 : min(100,$pctRound) ?>%"><?= $pct===null ? 'Sin cuota' : ($pctRound.'%') ?></div></div>
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
/* ===== Chart mensual por sucursal (barras) ===== */
const ALL_SUC = <?= json_encode($seriesSucursales, JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK) ?>;
const TOP_BARS = <?= (int)$TOP_BARS ?>;

function palette(i){
  const colors = ['#2563eb','#16a34a','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#f97316','#22c55e','#0ea5e9','#e11d48','#7c3aed','#10b981','#eab308','#dc2626','#06b6d4','#a3e635'];
  return colors[i % colors.length];
}
function buildTop(metric){
  const arr = [...ALL_SUC].sort((a,b)=>(b[metric]||0)-(a[metric]||0));
  const labels=[], data=[]; let otras=0;
  arr.forEach((r,idx)=>{ if(idx<TOP_BARS){ labels.push(r.label); data.push(r[metric]||0); } else { otras += (r[metric]||0); }});
  if(otras>0){ labels.push('Otras'); data.push(otras); }
  return {labels,data};
}
let currentMetric='unidades'; let chart=null;
function renderChart(){
  const series=buildTop(currentMetric);
  const ctx=document.getElementById('chartMensualSuc').getContext('2d');
  const bg=series.labels.map((_,i)=>palette(i));
  const isMoney=(currentMetric==='ventas');
  const data={ labels:series.labels, datasets:[{label:isMoney?'Ventas ($)':'Unidades (mes)', data:series.data, backgroundColor:bg, borderWidth:0}] };
  const options={
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:(ctx)=> isMoney ? ' $'+Number(ctx.parsed.y).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2}) : ' '+ctx.parsed.y.toLocaleString('es-MX')+' u.' } } },
    scales:{
      x:{ title:{display:true,text:'Sucursales'}, ticks:{ autoSkip:false, maxRotation:45, minRotation:0, callback:(v,i)=>{ const l=series.labels[i]||''; return l.length>14?l.slice(0,12)+'…':l; } }, grid:{display:false} },
      y:{ beginAtZero:true, title:{display:true, text:isMoney?'Ventas ($)':'Unidades'} }
    },
    elements:{ bar:{ borderRadius:4, barThickness:'flex', maxBarThickness:42 } }
  };
  if(chart) chart.destroy();
  chart=new Chart(ctx,{type:'bar', data, options});
}
renderChart();
const btnUnidades=document.getElementById('btnUnidades');
const btnVentas=document.getElementById('btnVentas');
btnUnidades.addEventListener('click',()=>{ currentMetric='unidades'; btnUnidades.className='btn btn-primary'; btnVentas.className='btn btn-outline-light'; renderChart(); });
btnVentas.addEventListener('click',()=>{ currentMetric='ventas'; btnVentas.className='btn btn-primary'; btnUnidades.className='btn btn-outline-light'; renderChart(); });
</script>
</body>
</html>
