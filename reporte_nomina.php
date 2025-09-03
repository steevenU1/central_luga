<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';
include 'navbar.php';
include 'helpers_nomina.php';

/* ========================
   Semanas (mar→lun)
======================== */
function obtenerSemanaPorIndice($offset = 0) {
    $tz = new DateTimeZone('America/Mexico_City');
    $hoy = new DateTime('now', $tz);
    $diaSemana = (int)$hoy->format('N'); // 1=Lun ... 7=Dom
    $dif = $diaSemana - 2; // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime('now', $tz);
    $inicio->modify('-'.$dif.' days')->setTime(0,0,0);
    if ($offset > 0) $inicio->modify('-'.(7*$offset).' days');

    $fin = clone $inicio;
    $fin->modify('+6 days')->setTime(23,59,59);
    return [$inicio, $fin];
}

$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d 00:00:00');
$finSemana    = $finSemanaObj->format('Y-m-d 23:59:59');
$iniISO       = $inicioSemanaObj->format('Y-m-d');
$finISO       = $finSemanaObj->format('Y-m-d');

/* ========================
   Usuarios (excluye almacén y subdistribuidor si existe)
======================== */
$subdistCol = null;
foreach (['subtipo_sucursal','subtipo','sub_tipo','tipo_subsucursal'] as $c) {
    $rs = $conn->query("SHOW COLUMNS FROM sucursales LIKE '$c'");
    if ($rs && $rs->num_rows > 0) { $subdistCol = $c; break; }
}
$where = "s.tipo_sucursal <> 'Almacen'";
if ($subdistCol) {
    $where .= " AND (s.`$subdistCol` IS NULL OR LOWER(s.`$subdistCol`) <> 'subdistribuidor')";
}

$sqlUsuarios = "
    SELECT u.id, u.nombre, u.rol, u.sueldo, s.nombre AS sucursal, u.id_sucursal
    FROM usuarios u
    INNER JOIN sucursales s ON s.id=u.id_sucursal
    WHERE $where
    ORDER BY s.nombre, FIELD(u.rol,'Gerente','Ejecutivo'), u.nombre
";
$resUsuarios = $conn->query($sqlUsuarios);

/* ========================
   Confirmaciones (semana)
======================== */
$confMap = []; // id_usuario => ['confirmado'=>0/1,'confirmado_en'=>..., 'comentario'=>...]
$stmtC = $conn->prepare("
  SELECT id_usuario, confirmado, confirmado_en, comentario
  FROM nomina_confirmaciones
  WHERE semana_inicio=? AND semana_fin=?
");
$stmtC->bind_param("ss", $iniISO, $finISO);
$stmtC->execute();
$rC = $stmtC->get_result();
while ($row = $rC->fetch_assoc()) {
    $confMap[(int)$row['id_usuario']] = $row;
}

/* ========================
   Helpers
======================== */
function obtenerDescuentosSemana($conn, $idUsuario, DateTime $ini, DateTime $fin): float {
    $sql = "SELECT IFNULL(SUM(monto),0) AS total
            FROM descuentos_nomina
            WHERE id_usuario=?
              AND semana_inicio=? AND semana_fin=?";
    $stmt = $conn->prepare($sql);
    $iniISO = $ini->format('Y-m-d');
    $finISO = $fin->format('Y-m-d');
    $stmt->bind_param("iss", $idUsuario, $iniISO, $finISO);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (float)($row['total'] ?? 0);
}

/* ========================
   Armar nómina (con desgloses extra para GERENTE)
======================== */
$nomina = [];
while ($u = $resUsuarios->fetch_assoc()) {
    $id_usuario  = (int)$u['id'];
    $id_sucursal = (int)$u['id_sucursal'];

    // Comisiones EQUIPOS (ventas propias del usuario)
    $stmt = $conn->prepare("SELECT IFNULL(SUM(v.comision),0) AS total_comision FROM ventas v WHERE v.id_usuario=? AND v.fecha_venta BETWEEN ? AND ?");
    $stmt->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmt->execute();
    $com_equipos = (float)($stmt->get_result()->fetch_assoc()['total_comision'] ?? 0);
    $stmt->close();

    // SIMs PREPAGO (ejecutivo)
    $com_sims = 0.0;
    if ($u['rol'] != 'Gerente') {
        $stmt = $conn->prepare("
            SELECT IFNULL(SUM(vs.comision_ejecutivo),0) AS com_sims
            FROM ventas_sims vs
            WHERE vs.id_usuario=? AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta IN ('Nueva','Portabilidad')
        ");
        $stmt->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmt->execute();
        $com_sims = (float)($stmt->get_result()->fetch_assoc()['com_sims'] ?? 0);
        $stmt->close();
    }

    // POSPAGO (ejecutivo)
    $com_pospago = 0.0;
    if ($u['rol'] != 'Gerente') {
        $stmt = $conn->prepare("
            SELECT IFNULL(SUM(vs.comision_ejecutivo),0) AS com_pos
            FROM ventas_sims vs
            WHERE vs.id_usuario=? AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta='Pospago'
        ");
        $stmt->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmt->execute();
        $com_pospago = (float)($stmt->get_result()->fetch_assoc()['com_pos'] ?? 0);
        $stmt->close();
    }

    // ===== GERENTE: desglose =====
    $com_ger_dir  = 0.0; // ventas directas realizadas por el propio gerente (en 'ventas')
    $com_ger_esc  = 0.0; // comisiones del gerente por ventas de la sucursal (vendedor != gerente)
    $com_ger_prep = 0.0; // prepago gerente (ventas_sims Nueva/Porta)
    $com_ger_pos  = 0.0; // pospago gerente (ventas_sims Pospago)
    $com_ger_base = 0.0; // base = dir + esc + prepago
    $com_ger      = 0.0; // total gerente para nómina (base + posg)

    if ($u['rol'] == 'Gerente') {
        // Ventas directas del gerente (tabla ventas)
        $stmt = $conn->prepare("
            SELECT IFNULL(SUM(v.comision_gerente),0) AS dir
            FROM ventas v
            WHERE v.id_usuario=? AND v.fecha_venta BETWEEN ? AND ?
        ");
        $stmt->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmt->execute();
        $com_ger_dir = (float)($stmt->get_result()->fetch_assoc()['dir'] ?? 0);
        $stmt->close();

        // Escalonado de equipos de la sucursal (vendedor NO gerente)
        $stmt = $conn->prepare("
            SELECT IFNULL(SUM(v.comision_gerente),0) AS esc
            FROM ventas v
            WHERE v.id_sucursal=? AND v.id_usuario<>? AND v.fecha_venta BETWEEN ? AND ?
        ");
        $stmt->bind_param("iiss", $id_sucursal, $id_usuario, $inicioSemana, $finSemana);
        $stmt->execute();
        $com_ger_esc = (float)($stmt->get_result()->fetch_assoc()['esc'] ?? 0);
        $stmt->close();

        // Prepago gerente (ventas_sims, Nueva/Porta)
        $stmt = $conn->prepare("
            SELECT IFNULL(SUM(vs.comision_gerente),0) AS com_ger_prepago
            FROM ventas_sims vs
            WHERE vs.id_sucursal=? AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta IN ('Nueva','Portabilidad')
        ");
        $stmt->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmt->execute();
        $com_ger_prep = (float)($stmt->get_result()->fetch_assoc()['com_ger_prepago'] ?? 0);
        $stmt->close();

        // Pospago gerente (ventas_sims, Pospago)
        $stmt = $conn->prepare("
            SELECT IFNULL(SUM(vs.comision_gerente),0) AS com_ger_pos
            FROM ventas_sims vs
            WHERE vs.id_sucursal=? AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta='Pospago'
        ");
        $stmt->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmt->execute();
        $com_ger_pos = (float)($stmt->get_result()->fetch_assoc()['com_ger_pos'] ?? 0);
        $stmt->close();

        $com_ger_base = $com_ger_dir + $com_ger_esc + $com_ger_prep; // lo que mostramos en "Ger."
        $com_ger      = $com_ger_base + $com_ger_pos;                // total para nómina
    }

    // Descuentos
    $descuentos = obtenerDescuentosSemana($conn, $id_usuario, $inicioSemanaObj, $finSemanaObj);

    /* ========================
       Overrides RH por semana
    ======================== */
    $ov = fetchOverridesSemana($conn, $id_usuario, $iniISO, $finISO);

    // Copia originales (por trazabilidad/UI)
    $orig_sueldo   = (float)$u['sueldo'];
    $orig_equipos  = $com_equipos;
    $orig_sims     = $com_sims;
    $orig_pospago  = $com_pospago;
    $orig_ger_base = $com_ger_base;
    $orig_ger_pos  = $com_ger_pos;
    $orig_desc     = $descuentos;

    // Aplicar overrides (NULL => respetar cálculo)
    $sueldo_forzado = applyOverride($ov['sueldo_override']     ?? null, $orig_sueldo);
    $com_equipos    = applyOverride($ov['equipos_override']    ?? null, $orig_equipos);
    $com_sims       = applyOverride($ov['sims_override']       ?? null, $orig_sims);
    $com_pospago    = applyOverride($ov['pospago_override']    ?? null, $orig_pospago);
    $com_ger_base   = applyOverride($ov['ger_base_override']   ?? null, $orig_ger_base);
    $com_ger_pos    = applyOverride($ov['ger_pos_override']    ?? null, $orig_ger_pos);
    $descuentos     = applyOverride($ov['descuentos_override'] ?? null, $orig_desc);

    $ajuste_neto_extra = (float)($ov['ajuste_neto_extra'] ?? 0.00);
    $estado_override   = $ov['estado'] ?? null;
    $nota_override     = $ov['nota']   ?? null;

    // Recalcular totales con forzados
    $com_ger     = $com_ger_base + $com_ger_pos;
    $total_bruto = $sueldo_forzado + $com_equipos + $com_sims + $com_pospago + $com_ger;
    $total_neto  = $total_bruto - $descuentos + $ajuste_neto_extra;

    // Confirmación
    $confRow = $confMap[$id_usuario] ?? null;
    $confirmado = $confRow ? (int)$confRow['confirmado'] : 0;
    $confirmado_en = $confRow['confirmado_en'] ?? null;
    $comentario = $confRow['comentario'] ?? '';

    // Flags para chips “forzado”
    $flag_forz = [
      'sueldo'     => isset($ov['sueldo_override']),
      'equipos'    => isset($ov['equipos_override']),
      'sims'       => isset($ov['sims_override']),
      'pospago'    => isset($ov['pospago_override']),
      'ger_base'   => isset($ov['ger_base_override']),
      'ger_pos'    => isset($ov['ger_pos_override']),
      'descuentos' => isset($ov['descuentos_override']),
      'ajuste'     => abs($ajuste_neto_extra) > 0.0001,
      'estado'     => $estado_override,
      'nota'       => $nota_override
    ];

    $nomina[] = [
        'id_usuario'     => $id_usuario,
        'id_sucursal'    => $id_sucursal,
        'nombre'         => $u['nombre'],
        'rol'            => $u['rol'],
        'sucursal'       => $u['sucursal'],
        'sueldo'         => $sueldo_forzado, // forzado si aplica
        'com_equipos'    => $com_equipos,
        'com_sims'       => $com_sims,
        'com_pospago'    => $com_pospago,     // ejecutivo
        'com_ger'        => $com_ger,         // total gerente (para neto)
        'com_ger_base'   => $com_ger_base,    // Ger. base (puede estar forzado)
        'com_ger_pos'    => $com_ger_pos,     // PosG. (puede estar forzado)
        // desgloses informativos (no forzados)
        'com_ger_dir'    => $com_ger_dir,
        'com_ger_esc'    => $com_ger_esc,
        'descuentos'     => $descuentos,
        'total_neto'     => $total_neto,
        'confirmado'     => $confirmado,
        'confirmado_en'  => $confirmado_en,
        'comentario'     => $comentario,
        '_forz_flags'    => $flag_forz,
        '_ajuste_extra'  => $ajuste_neto_extra,
        '_nota'          => $nota_override,
        '_estado'        => $estado_override,
    ];
}

/* ========================
   Totales y métricas
======================== */
$empleados        = count($nomina);
$totalGlobalNeto  = 0;
$totalGlobalDesc  = 0;
$confirmados      = 0;
foreach($nomina as $n){
  $totalGlobalNeto += $n['total_neto'];
  $totalGlobalDesc += $n['descuentos'];
  if ((int)$n['confirmado'] === 1) $confirmados++;
}
$pendientes = max($empleados - $confirmados, 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reporte de Nómina Semanal</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{ --card-bg:#fff; --muted:#6b7280; --chip:#f1f5f9; }
    body{ background:#f7f7fb; }
    .page-header{ display:flex; gap:1rem; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .page-title{ display:flex; gap:.75rem; align-items:center; }
    .page-title .emoji{ font-size:1.6rem; }
    .card-soft{ background:var(--card-bg); border:1px solid #eef2f7; border-radius:1rem; box-shadow:0 6px 18px rgba(16,24,40,.06); }
    .chip{ display:inline-flex; gap:.4rem; align-items:center; background:var(--chip); border-radius:999px; padding:.25rem .55rem; font-size:.72rem; }
    .chip-warn{ background:#fff3cd; color:#8a6d3b; border:1px solid #ffe9a9; }
    .chip-note{ background:#eef2ff; color:#3730a3; border:1px solid #e0e7ff; }
    .controls-right{ display:flex; gap:.5rem; flex-wrap:wrap; }
    .table thead th{ position:sticky; top:0; z-index:5; background:#fff; border-bottom:1px solid #e5e7eb; }
    .table-nomina{ font-size:.82rem; }
    .table-nomina th, .table-nomina td{ padding:.35rem .4rem; white-space:nowrap; vertical-align:middle; }
    .num{ text-align:right; font-variant-numeric: tabular-nums; }
    .th-sort{ cursor:pointer; white-space:nowrap; }
    .status-pill{ border-radius:999px; font-size:.7rem; padding:.18rem .45rem; }
    .header-mini{ font-size:.85rem; }
    .no-x-scroll .table-responsive{ overflow-x:visible; }
    .badge-role{ background:#eef2ff; color:#3730a3; border:1px solid #e0e7ff; }
    .badge-suc{ background:#f0fdf4; color:#14532d; border:1px solid #bbf7d0; }
    @media (max-width:1200px){ .table-nomina{ font-size:.78rem; } .status-pill{ font-size:.66rem; } }
    @media print{
      .no-print{ display:none !important; }
      body{ background:#fff; }
      .card-soft{ box-shadow:none; border:0; }
      .table thead th{ position:static; }
    }
  </style>
</head>
<body>

<div class="container py-4">
  <!-- Header -->
  <div class="page-header mb-3">
    <div class="page-title">
      <span class="emoji">📋</span>
      <div>
        <h3 class="mb-0">Reporte de Nómina Semanal</h3>
        <div class="text-muted small header-mini">
          Semana del <strong><?= $inicioSemanaObj->format('d/m/Y') ?></strong> al <strong><?= $finSemanaObj->format('d/m/Y') ?></strong>
        </div>
      </div>
    </div>

    <div class="controls-right no-print">
      <form method="GET" class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 small text-muted">Semana</label>
        <select name="semana" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
          <?php for ($i=0; $i<8; $i++):
              list($ini, $fin) = obtenerSemanaPorIndice($i);
              $texto = "Del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
          ?>
            <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $texto ?></option>
          <?php endfor; ?>
        </select>
        <a href="recalculo_total_comisiones.php?semana=<?= $semanaSeleccionada ?>" 
           class="btn btn-warning btn-sm"
           onclick="return confirm('¿Seguro que deseas recalcular las comisiones de esta semana?');">
           <i class="bi bi-arrow-repeat me-1"></i> Recalcular
        </a>
        <a href="exportar_nomina_excel.php?semana=<?= $semanaSeleccionada ?>" class="btn btn-success btn-sm">
          <i class="bi bi-file-earmark-excel me-1"></i> Exportar Excel
        </a>
        <a href="descuentos_nomina_admin.php?semana=<?= $semanaSeleccionada ?>" class="btn btn-outline-primary btn-sm">
          <i class="bi bi-cash-coin me-1"></i> Descuentos
        </a>
        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()">
          <i class="bi bi-printer me-1"></i> Imprimir
        </button>
      </form>
    </div>
  </div>

  <!-- Summary cards -->
  <div class="d-flex flex-wrap gap-3 mb-3">
    <div class="card-soft p-3"><div class="text-muted small mb-1">Empleados</div><div class="h5 mb-0"><?= number_format($empleados) ?></div></div>
    <div class="card-soft p-3"><div class="text-muted small mb-1">Global (Neto)</div><div class="h5 mb-0">$<?= number_format($totalGlobalNeto,2) ?></div></div>
    <div class="card-soft p-3"><div class="text-muted small mb-1">Descuentos</div><div class="h5 mb-0 text-danger">-$<?= number_format($totalGlobalDesc,2) ?></div></div>
    <div class="card-soft p-3"><div class="text-muted small mb-1">Confirmados</div><div class="h5 mb-0 text-success"><?= number_format($confirmados) ?></div></div>
    <div class="card-soft p-3"><div class="text-muted small mb-1">Pendientes</div><div class="h5 mb-0 text-danger"><?= number_format($pendientes) ?></div></div>

    <!-- Filtros -->
    <div class="card-soft p-3 no-print" style="flex:1">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label mb-1 small text-muted">Rol</label>
          <select id="fRol" class="form-select form-select-sm">
            <option value="">Todos</option>
            <option value="Ejecutivo">Ejecutivo</option>
            <option value="Gerente">Gerente</option>
          </select>
        </div>
        <div class="col-12 col-md-8">
          <label class="form-label mb-1 small text-muted">Buscar</label>
          <input id="fSearch" type="search" class="form-control form-control-sm" placeholder="Empleado, sucursal...">
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla -->
  <div class="card-soft p-0 no-x-scroll">
    <div class="table-responsive">
      <table id="tablaNomina" class="table table-hover table-sm table-nomina mb-0">
        <thead>
          <tr>
            <th>Empleado</th>
            <th class="th-sort" data-key="rol">Rol</th>
            <th class="th-sort" data-key="sucursal">Sucursal</th>
            <th class="th-sort num" data-key="sueldo">Sueldo</th>
            <th class="th-sort num" data-key="equipos">Eq.</th>
            <th class="th-sort num" data-key="sims">SIMs</th>
            <th class="th-sort num" data-key="pospago">Pos.</th>
            <th class="th-sort num" data-key="posg">PosG.</th>
            <th class="th-sort num" data-key="ger_dir">DirG.</th>
            <th class="th-sort num" data-key="ger_esc">Esc.Eq.</th>
            <th class="th-sort num" data-key="gerente">Ger.</th>
            <th class="th-sort num" data-key="descuentos">Desc.</th>
            <th class="th-sort num" data-key="neto">Neto</th>
            <th class="th-sort" data-key="confirmado">Conf.</th>
            <th class="no-print"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($nomina as $n):
              $isGer = ($n['rol'] === 'Gerente');
              $isOk  = (int)$n['confirmado'] === 1;
              $valGerenteTabla = $isGer ? (float)$n['com_ger_base'] : 0.0; // Ger. SIN PosG
              $valPosG         = $isGer ? (float)$n['com_ger_pos']  : 0.0; // Solo PosG
              $valGerDir       = $isGer ? (float)$n['com_ger_dir']  : 0.0; // DirG.
              $valGerEsc       = $isGer ? (float)$n['com_ger_esc']  : 0.0; // Esc.Eq.
              $ff = $n['_forz_flags'] ?? [];
          ?>
          <tr
            data-rol="<?= htmlspecialchars($n['rol'], ENT_QUOTES, 'UTF-8') ?>"
            data-sucursal="<?= htmlspecialchars($n['sucursal'], ENT_QUOTES, 'UTF-8') ?>"
            data-sueldo="<?= (float)$n['sueldo'] ?>"
            data-equipos="<?= (float)$n['com_equipos'] ?>"
            data-sims="<?= (float)$n['com_sims'] ?>"
            data-pospago="<?= (float)$n['com_pospago'] ?>"
            data-posg="<?= $valPosG ?>"
            data-ger_dir="<?= $valGerDir ?>"
            data-ger_esc="<?= $valGerEsc ?>"
            data-gerente="<?= $valGerenteTabla ?>"
            data-descuentos="<?= (float)$n['descuentos'] ?>"
            data-neto="<?= (float)$n['total_neto'] ?>"
            data-confirmado="<?= $isOk ? 1 : 0 ?>"
          >
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($n['nombre'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="small text-muted">
                <?php if ($isGer): ?>
                  <a href="auditar_comisiones_gerente.php?semana=<?= $semanaSeleccionada ?>&id_sucursal=<?= (int)$n['id_sucursal'] ?>" title="Detalle gerente">🔍</a>
                <?php else: ?>
                  <a href="auditar_comisiones_ejecutivo.php?semana=<?= $semanaSeleccionada ?>&id_usuario=<?= (int)$n['id_usuario'] ?>" title="Detalle ejecutivo">🔍</a>
                <?php endif; ?>
                <?php if (!empty($n['_estado'])): ?>
                  <span class="chip chip-note ms-1" title="Estado overrides">OV: <?= htmlspecialchars($n['_estado']) ?></span>
                <?php endif; ?>
                <?php if (!empty($n['_nota'])): ?>
                  <span class="chip chip-note ms-1" title="Nota RH">“<?= htmlspecialchars($n['_nota']) ?>”</span>
                <?php endif; ?>
              </div>
            </td>
            <td><span class="badge-role rounded-pill"><?= htmlspecialchars($n['rol'], ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><span class="badge-suc rounded-pill"><?= htmlspecialchars($n['sucursal'], ENT_QUOTES, 'UTF-8') ?></span></td>

            <td class="num">
              $<?= number_format($n['sueldo'],2) ?>
              <?php if (!empty($ff['sueldo'])): ?><span class="chip chip-warn ms-1" title="Forzado por RH">forzado</span><?php endif; ?>
            </td>
            <td class="num">
              $<?= number_format($n['com_equipos'],2) ?>
              <?php if (!empty($ff['equipos'])): ?><span class="chip chip-warn ms-1">forzado</span><?php endif; ?>
            </td>
            <td class="num">
              $<?= number_format($n['com_sims'],2) ?>
              <?php if (!empty($ff['sims'])): ?><span class="chip chip-warn ms-1">forzado</span><?php endif; ?>
            </td>
            <td class="num">
              $<?= number_format($n['com_pospago'],2) ?>
              <?php if (!empty($ff['pospago'])): ?><span class="chip chip-warn ms-1">forzado</span><?php endif; ?>
            </td>

            <td class="num">
              $<?= number_format($valPosG,2) ?>
              <?php if (!empty($ff['ger_pos'])): ?><span class="chip chip-warn ms-1">forzado</span><?php endif; ?>
            </td>

            <td class="num">$<?= number_format($valGerDir,2) ?></td>
            <td class="num">$<?= number_format($valGerEsc,2) ?></td>

            <td class="num">
              $<?= number_format($valGerenteTabla,2) ?>
              <?php if (!empty($ff['ger_base'])): ?><span class="chip chip-warn ms-1">forzado</span><?php endif; ?>
            </td>
            <td class="num text-danger">
              -$<?= number_format($n['descuentos'],2) ?>
              <?php if (!empty($ff['descuentos'])): ?><span class="chip chip-warn ms-1">forzado</span><?php endif; ?>
            </td>
            <td class="num fw-semibold">
              $<?= number_format($n['total_neto'],2) ?>
              <?php if (!empty($ff['ajuste'])): ?>
                <span class="chip chip-note ms-1" title="Ajuste neto extra"><?= $n['_ajuste_extra']>=0?'+':'' ?>$<?= number_format($n['_ajuste_extra'],2) ?></span>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($isOk): ?>
                <span class="status-pill bg-success text-white" title="<?= $n['confirmado_en'] ? date('d/m/Y H:i', strtotime($n['confirmado_en'])) : '' ?>">✔</span>
              <?php else: ?>
                <span class="status-pill bg-warning">Pend.</span>
              <?php endif; ?>
            </td>
            <td class="no-print">
              <a class="btn btn-outline-primary btn-sm" 
                 href="descuentos_nomina_admin.php?semana=<?= $semanaSeleccionada ?>&id_usuario=<?= (int)$n['id_usuario'] ?>"
                 title="Capturar descuentos">
                 <i class="bi bi-pencil-square"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <!-- Aumenta el colspan por las 2 nuevas columnas (DirG. y Esc.Eq.) -->
            <td colspan="11" class="text-end"><strong>Totales</strong></td>
            <td class="num text-danger"><strong>-$<?= number_format($totalGlobalDesc,2) ?></strong></td>
            <td class="num"><strong>$<?= number_format($totalGlobalNeto,2) ?></strong></td>
            <td class="text-start">
              <span class="status-pill bg-success text-white me-1">Conf: <?= number_format($confirmados) ?></span>
              <span class="status-pill bg-danger text-white">Pend: <?= number_format($pendientes) ?></span>
            </td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <div class="mt-2 text-muted small">
    * “Ger.” = DirG. + Esc.Eq. + Prepago Ger. (si no hay override). PosG. se muestra aparte.  
    * Totales netos = sueldo + comisiones – descuentos ± ajustes de RH. Confirmación visible en <em>Mi Nómina</em> desde el martes posterior al cierre (mar→lun).
  </div>
</div>

<script>
  // Buscar + rol
  const fRol = document.getElementById('fRol');
  const fSearch = document.getElementById('fSearch');
  const tbody = document.querySelector('#tablaNomina tbody');

  function applyFilters(){
    const rol = (fRol?.value||'').toLowerCase();
    const q = (fSearch?.value||'').toLowerCase();
    [...tbody.rows].forEach(tr=>{
      const trRol = (tr.dataset.rol||'').toLowerCase();
      const text = (tr.textContent||'').toLowerCase();
      let ok = true;
      if (rol && trRol !== rol) ok = false;
      if (q && !text.includes(q)) ok = false;
      tr.style.display = ok ? '' : 'none';
    });
  }
  [fRol,fSearch].forEach(el=>el && el.addEventListener('input', applyFilters));

  // Ordenamiento
  let sortState = { key:null, dir:1 };
  document.querySelectorAll('.th-sort').forEach(th=>{
    th.addEventListener('click', ()=>{
      const key = th.dataset.key;
      sortState.dir = (sortState.key===key) ? -sortState.dir : 1;
      sortState.key = key;
      sortRows(key, sortState.dir);
    });
  });

  function sortRows(key, dir){
    const tbody = document.querySelector('#tablaNomina tbody');
    const rows = [...tbody.rows];
    rows.sort((a,b)=>{
      const va = a.dataset[key] ?? '';
      const vb = b.dataset[key] ?? '';
      const na = Number(va), nb = Number(vb);
      if(!Number.isNaN(na) && !Number.isNaN(nb)) return (na-nb)*dir;
      return String(va).localeCompare(String(vb), 'es', {numeric:true, sensitivity:'base'}) * dir;
    });
    rows.forEach(r=>tbody.appendChild(r));
  }
</script>
</body>
</html>
