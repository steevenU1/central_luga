<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

$rolSesion = trim((string)($_SESSION['rol'] ?? ''));
$rolesPermitidos = ['Admin', 'Logistica'];

if (!in_array($rolSesion, $rolesPermitidos, true)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso restringido</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '</head><body class="bg-light">';
    echo '<div class="container py-5"><div class="alert alert-danger shadow-sm rounded-4">';
    echo '<h4 class="mb-2">Acceso restringido</h4><div>No tienes permisos para acceder a esta vista.</div>';
    echo '</div></div></body></html>';
    exit();
}

/* =========================================================
   Helpers
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function money($n): string {
    return '$' . number_format((float)$n, 2);
}

function pct($num, $den): float {
    $num = (float)$num;
    $den = (float)$den;
    if ($den <= 0) return 0.0;
    return ($num / $den) * 100;
}

function pctBadgeClass(float $pct): string {
    if ($pct >= 100) return 'bg-success-subtle text-success border border-success-subtle';
    if ($pct >= 80)  return 'bg-warning-subtle text-warning border border-warning-subtle';
    return 'bg-danger-subtle text-danger border border-danger-subtle';
}

function quarterData(int $anio, string $quarter): array {
    $quarter = strtoupper(trim($quarter));
    switch ($quarter) {
        case 'Q1':
            $meses = [1, 2, 3];
            $inicio = "{$anio}-01-01 00:00:00";
            $fin    = "{$anio}-03-31 23:59:59";
            break;
        case 'Q2':
            $meses = [4, 5, 6];
            $inicio = "{$anio}-04-01 00:00:00";
            $fin    = "{$anio}-06-30 23:59:59";
            break;
        case 'Q3':
            $meses = [7, 8, 9];
            $inicio = "{$anio}-07-01 00:00:00";
            $fin    = "{$anio}-09-30 23:59:59";
            break;
        case 'Q4':
        default:
            $meses = [10, 11, 12];
            $inicio = "{$anio}-10-01 00:00:00";
            $fin    = "{$anio}-12-31 23:59:59";
            $quarter = 'Q4';
            break;
    }

    return [$quarter, $meses, $inicio, $fin];
}

/* =========================================================
   Filtros
========================================================= */
$anioActual = (int)date('Y');
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : $anioActual;
if ($anio < 2020 || $anio > 2035) $anio = $anioActual;

$quarter = isset($_GET['quarter']) ? strtoupper(trim($_GET['quarter'])) : 'Q1';
if (!in_array($quarter, ['Q1', 'Q2', 'Q3', 'Q4'], true)) {
    $quarter = 'Q1';
}

[$quarter, $mesesQuarter, $fechaInicio, $fechaFin] = quarterData($anio, $quarter);
[$m1, $m2, $m3] = $mesesQuarter;

/* =========================================================
   Cuota general de ejecutivos / gerentes
========================================================= */
$cuotaGeneralUsuarios = [
    'cuota_unidades' => 0,
    'cuota_monto'    => 0.0,
];

$sqlCuotaGeneral = "
    SELECT 
        COALESCE(SUM(cuota_unidades), 0) AS cuota_unidades,
        COALESCE(SUM(cuota_monto), 0)    AS cuota_monto
    FROM cuotas_mensuales_ejecutivos
    WHERE anio = ?
      AND mes IN (?, ?, ?)
";

if ($stmt = $conn->prepare($sqlCuotaGeneral)) {
    $stmt->bind_param("iiii", $anio, $m1, $m2, $m3);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $cuotaGeneralUsuarios['cuota_unidades'] = (int)($row['cuota_unidades'] ?? 0);
        $cuotaGeneralUsuarios['cuota_monto']    = (float)($row['cuota_monto'] ?? 0);
    }
    $stmt->close();
}

/* =========================================================
   Resumen por sucursales
========================================================= */
$sqlSucursales = "
    SELECT
        s.id,
        s.nombre AS sucursal,
        s.zona,
        COALESCE(vu.unidades_vendidas, 0)     AS unidades_vendidas,
        COALESCE(vm.monto_vendido, 0)         AS monto_vendido,
        COALESCE(qs.cuota_unidades, 0)        AS cuota_unidades,
        COALESCE(qs.cuota_monto, 0)           AS cuota_monto
    FROM sucursales s
    LEFT JOIN (
        SELECT
            v.id_sucursal,
            COUNT(dv.id) AS unidades_vendidas
        FROM ventas v
        INNER JOIN detalle_venta dv ON dv.id_venta = v.id
        WHERE v.fecha_venta BETWEEN ? AND ?
        GROUP BY v.id_sucursal
    ) vu ON vu.id_sucursal = s.id
    LEFT JOIN (
        SELECT
            v.id_sucursal,
            SUM(v.precio_venta) AS monto_vendido
        FROM ventas v
        WHERE v.fecha_venta BETWEEN ? AND ?
        GROUP BY v.id_sucursal
    ) vm ON vm.id_sucursal = s.id
    LEFT JOIN (
        SELECT
            cm.id_sucursal,
            SUM(cm.cuota_unidades) AS cuota_unidades,
            SUM(cm.cuota_monto)    AS cuota_monto
        FROM cuotas_mensuales cm
        WHERE cm.anio = ?
          AND cm.mes IN (?, ?, ?)
        GROUP BY cm.id_sucursal
    ) qs ON qs.id_sucursal = s.id
    WHERE s.activo = 1
    ORDER BY s.zona ASC, s.nombre ASC
";

$sucursales = [];
if ($stmt = $conn->prepare($sqlSucursales)) {
    $stmt->bind_param(
        "ssssiiii",
        $fechaInicio,
        $fechaFin,
        $fechaInicio,
        $fechaFin,
        $anio,
        $m1,
        $m2,
        $m3
    );
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['unidades_vendidas'] = (int)$row['unidades_vendidas'];
        $row['monto_vendido']     = (float)$row['monto_vendido'];
        $row['cuota_unidades']    = (int)$row['cuota_unidades'];
        $row['cuota_monto']       = (float)$row['cuota_monto'];
        $sucursales[] = $row;
    }
    $stmt->close();
}

/* =========================================================
   Resumen por usuarios activos (Ejecutivos + Gerentes)
========================================================= */
$sqlUsuarios = "
    SELECT
        u.id,
        u.nombre AS usuario,
        u.rol,
        s.nombre AS sucursal,
        s.zona,
        COALESCE(uu.unidades_vendidas, 0) AS unidades_vendidas,
        COALESCE(um.monto_vendido, 0)     AS monto_vendido
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN (
        SELECT
            v.id_usuario,
            COUNT(dv.id) AS unidades_vendidas
        FROM ventas v
        INNER JOIN detalle_venta dv ON dv.id_venta = v.id
        WHERE v.fecha_venta BETWEEN ? AND ?
        GROUP BY v.id_usuario
    ) uu ON uu.id_usuario = u.id
    LEFT JOIN (
        SELECT
            v.id_usuario,
            SUM(v.precio_venta) AS monto_vendido
        FROM ventas v
        WHERE v.fecha_venta BETWEEN ? AND ?
        GROUP BY v.id_usuario
    ) um ON um.id_usuario = u.id
    WHERE u.activo = 1
      AND s.activo = 1
      AND u.rol IN ('Ejecutivo', 'Gerente')
    ORDER BY s.zona ASC, s.nombre ASC, u.nombre ASC
";

$ejecutivos = [];
$gerentes = [];

if ($stmt = $conn->prepare($sqlUsuarios)) {
    $stmt->bind_param("ssss", $fechaInicio, $fechaFin, $fechaInicio, $fechaFin);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['unidades_vendidas'] = (int)$row['unidades_vendidas'];
        $row['monto_vendido']     = (float)$row['monto_vendido'];
        $row['cuota_unidades']    = (int)$cuotaGeneralUsuarios['cuota_unidades'];
        $row['cuota_monto']       = (float)$cuotaGeneralUsuarios['cuota_monto'];

        if ($row['rol'] === 'Gerente') {
            $gerentes[] = $row;
        } else {
            $ejecutivos[] = $row;
        }
    }
    $stmt->close();
}

/* =========================================================
   KPIs generales
========================================================= */
$totalSucursalesActivas     = count($sucursales);
$totalEjecutivosActivos     = count($ejecutivos);
$totalGerentesActivos       = count($gerentes);

$totalUnidadesSucursales    = 0;
$totalMontoSucursales       = 0.0;
$totalCuotaUnidadesSuc      = 0;
$totalCuotaMontoSuc         = 0.0;

foreach ($sucursales as $r) {
    $totalUnidadesSucursales += (int)$r['unidades_vendidas'];
    $totalMontoSucursales    += (float)$r['monto_vendido'];
    $totalCuotaUnidadesSuc   += (int)$r['cuota_unidades'];
    $totalCuotaMontoSuc      += (float)$r['cuota_monto'];
}

$globalPctUnidadesSuc = pct($totalUnidadesSucursales, $totalCuotaUnidadesSuc);
$globalPctMontoSuc    = pct($totalMontoSucursales, $totalCuotaMontoSuc);

/* =========================================================
   Include navbar
========================================================= */
@include __DIR__ . '/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Análisis Trimestral</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php if (!file_exists(__DIR__ . '/navbar.php')): ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>

    <style>
        body {
            background: #f6f8fb;
        }
        .page-title {
            font-weight: 800;
            letter-spacing: -.02em;
        }
        .card-soft {
            border: 0;
            border-radius: 18px;
            box-shadow: 0 10px 25px rgba(16,24,40,.06);
        }
        .table thead th {
            white-space: nowrap;
            font-size: .90rem;
        }
        .table tbody td {
            vertical-align: middle;
            font-size: .93rem;
        }
        .badge-soft {
            border-radius: 999px;
            padding: .45rem .75rem;
            font-weight: 700;
        }
        .sticky-actions {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #f6f8fb;
            padding-top: .25rem;
            padding-bottom: .25rem;
        }
        .section-title {
            font-weight: 800;
            letter-spacing: -.01em;
        }
        .small-muted {
            font-size: .88rem;
            color: #6c757d;
        }
    </style>
</head>
<body>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="page-title mb-1">Análisis Trimestral</h1>
            <div class="text-muted">
                Vista histórica por sucursal, ejecutivos y gerentes · <?= h($quarter) ?> <?= h($anio) ?>
            </div>
        </div>

        <form class="card card-soft p-3" method="GET" action="">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-auto">
                    <label class="form-label mb-1">Año</label>
                    <select name="anio" class="form-select">
                        <?php for ($y = 2024; $y <= 2030; $y++): ?>
                            <option value="<?= $y ?>" <?= $y === $anio ? 'selected' : '' ?>>
                                <?= $y ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 col-md-auto">
                    <label class="form-label mb-1">Quarter</label>
                    <select name="quarter" class="form-select">
                        <?php foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $q): ?>
                            <option value="<?= $q ?>" <?= $q === $quarter ? 'selected' : '' ?>>
                                <?= $q ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-dark w-100">
                        Aplicar filtros
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card card-soft h-100">
                <div class="card-body">
                    <div class="small-muted mb-1">Unidades vendidas en sucursales</div>
                    <div class="fs-3 fw-bold"><?= number_format($totalUnidadesSucursales) ?></div>
                    <div class="mt-2">
                        <span class="badge badge-soft <?= pctBadgeClass($globalPctUnidadesSuc) ?>">
                            Cumplimiento <?= number_format($globalPctUnidadesSuc, 1) ?>%
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="card card-soft h-100">
                <div class="card-body">
                    <div class="small-muted mb-1">Monto vendido en sucursales</div>
                    <div class="fs-3 fw-bold"><?= h(money($totalMontoSucursales)) ?></div>
                    <div class="mt-2">
                        <span class="badge badge-soft <?= pctBadgeClass($globalPctMontoSuc) ?>">
                            Cumplimiento <?= number_format($globalPctMontoSuc, 1) ?>%
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="card card-soft h-100">
                <div class="card-body">
                    <div class="small-muted mb-1">Sucursales activas analizadas</div>
                    <div class="fs-3 fw-bold"><?= number_format($totalSucursalesActivas) ?></div>
                    <div class="mt-2 text-muted small">
                        Incluye sucursales activas con 0 ventas
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="card card-soft h-100">
                <div class="card-body">
                    <div class="small-muted mb-1">Usuarios activos analizados</div>
                    <div class="fs-3 fw-bold"><?= number_format($totalEjecutivosActivos + $totalGerentesActivos) ?></div>
                    <div class="mt-2 text-muted small">
                        Ejecutivos: <?= number_format($totalEjecutivosActivos) ?> · Gerentes: <?= number_format($totalGerentesActivos) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- RESUMEN SUCURSALES -->
    <div class="card card-soft mb-4">
        <div class="card-body">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
                <div>
                    <h3 class="section-title mb-1">Resumen por Sucursales</h3>
                    <div class="small-muted">Ordenado por zona · Solo sucursales activas</div>
                </div>
                <div class="small text-muted">
                    Periodo: <strong><?= h($quarter) ?> <?= h($anio) ?></strong>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Zona</th>
                            <th>Sucursal</th>
                            <th class="text-end">Unidades</th>
                            <th class="text-end">Monto</th>
                            <th class="text-end">Cuota Unidades</th>
                            <th class="text-end">Cuota Monto</th>
                            <th class="text-end">% Cumpl. Unid.</th>
                            <th class="text-end">% Cumpl. Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sucursales)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">No hay información para mostrar.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sucursales as $r): ?>
                                <?php
                                    $pctUn = pct($r['unidades_vendidas'], $r['cuota_unidades']);
                                    $pctMo = pct($r['monto_vendido'], $r['cuota_monto']);
                                ?>
                                <tr>
                                    <td><?= h($r['zona']) ?></td>
                                    <td><?= h($r['sucursal']) ?></td>
                                    <td class="text-end fw-semibold"><?= number_format($r['unidades_vendidas']) ?></td>
                                    <td class="text-end"><?= h(money($r['monto_vendido'])) ?></td>
                                    <td class="text-end"><?= number_format($r['cuota_unidades']) ?></td>
                                    <td class="text-end"><?= h(money($r['cuota_monto'])) ?></td>
                                    <td class="text-end">
                                        <span class="badge badge-soft <?= pctBadgeClass($pctUn) ?>">
                                            <?= number_format($pctUn, 1) ?>%
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <span class="badge badge-soft <?= pctBadgeClass($pctMo) ?>">
                                            <?= number_format($pctMo, 1) ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <tr class="table-secondary fw-bold">
                                <td colspan="2">Totales</td>
                                <td class="text-end"><?= number_format($totalUnidadesSucursales) ?></td>
                                <td class="text-end"><?= h(money($totalMontoSucursales)) ?></td>
                                <td class="text-end"><?= number_format($totalCuotaUnidadesSuc) ?></td>
                                <td class="text-end"><?= h(money($totalCuotaMontoSuc)) ?></td>
                                <td class="text-end"><?= number_format($globalPctUnidadesSuc, 1) ?>%</td>
                                <td class="text-end"><?= number_format($globalPctMontoSuc, 1) ?>%</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RESUMEN EJECUTIVOS -->
    <div class="card card-soft mb-4">
        <div class="card-body">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
                <div>
                    <h3 class="section-title mb-1">Resumen por Ejecutivos</h3>
                    <div class="small-muted">Solo usuarios activos con rol Ejecutivo</div>
                </div>
                <div class="small text-muted">
                    Cuota general trimestral aplicada a cada ejecutivo
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Zona</th>
                            <th>Sucursal</th>
                            <th>Ejecutivo</th>
                            <th class="text-end">Unidades</th>
                            <th class="text-end">Monto</th>
                            <th class="text-end">Cuota Unidades</th>
                            <th class="text-end">Cuota Monto</th>
                            <th class="text-end">% Cumpl. Unid.</th>
                            <th class="text-end">% Cumpl. Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ejecutivos)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">No hay ejecutivos para mostrar.</td>
                            </tr>
                        <?php else: ?>
                            <?php
                                $totUnEj = 0;
                                $totMoEj = 0.0;
                            ?>
                            <?php foreach ($ejecutivos as $r): ?>
                                <?php
                                    $pctUn = pct($r['unidades_vendidas'], $r['cuota_unidades']);
                                    $pctMo = pct($r['monto_vendido'], $r['cuota_monto']);
                                    $totUnEj += (int)$r['unidades_vendidas'];
                                    $totMoEj += (float)$r['monto_vendido'];
                                ?>
                                <tr>
                                    <td><?= h($r['zona']) ?></td>
                                    <td><?= h($r['sucursal']) ?></td>
                                    <td><?= h($r['usuario']) ?></td>
                                    <td class="text-end fw-semibold"><?= number_format($r['unidades_vendidas']) ?></td>
                                    <td class="text-end"><?= h(money($r['monto_vendido'])) ?></td>
                                    <td class="text-end"><?= number_format($r['cuota_unidades']) ?></td>
                                    <td class="text-end"><?= h(money($r['cuota_monto'])) ?></td>
                                    <td class="text-end">
                                        <span class="badge badge-soft <?= pctBadgeClass($pctUn) ?>">
                                            <?= number_format($pctUn, 1) ?>%
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <span class="badge badge-soft <?= pctBadgeClass($pctMo) ?>">
                                            <?= number_format($pctMo, 1) ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <tr class="table-secondary fw-bold">
                                <td colspan="3">Totales</td>
                                <td class="text-end"><?= number_format($totUnEj) ?></td>
                                <td class="text-end"><?= h(money($totMoEj)) ?></td>
                                <td colspan="4"></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RESUMEN GERENTES -->
    <div class="card card-soft mb-4">
        <div class="card-body">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
                <div>
                    <h3 class="section-title mb-1">Resumen por Gerentes</h3>
                    <div class="small-muted">Solo usuarios activos con rol Gerente</div>
                </div>
                <div class="small text-muted">
                    Cuota general trimestral aplicada a cada gerente
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Zona</th>
                            <th>Sucursal</th>
                            <th>Gerente</th>
                            <th class="text-end">Unidades</th>
                            <th class="text-end">Monto</th>
                            <th class="text-end">Cuota Unidades</th>
                            <th class="text-end">Cuota Monto</th>
                            <th class="text-end">% Cumpl. Unid.</th>
                            <th class="text-end">% Cumpl. Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($gerentes)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">No hay gerentes para mostrar.</td>
                            </tr>
                        <?php else: ?>
                            <?php
                                $totUnGe = 0;
                                $totMoGe = 0.0;
                            ?>
                            <?php foreach ($gerentes as $r): ?>
                                <?php
                                    $pctUn = pct($r['unidades_vendidas'], $r['cuota_unidades']);
                                    $pctMo = pct($r['monto_vendido'], $r['cuota_monto']);
                                    $totUnGe += (int)$r['unidades_vendidas'];
                                    $totMoGe += (float)$r['monto_vendido'];
                                ?>
                                <tr>
                                    <td><?= h($r['zona']) ?></td>
                                    <td><?= h($r['sucursal']) ?></td>
                                    <td><?= h($r['usuario']) ?></td>
                                    <td class="text-end fw-semibold"><?= number_format($r['unidades_vendidas']) ?></td>
                                    <td class="text-end"><?= h(money($r['monto_vendido'])) ?></td>
                                    <td class="text-end"><?= number_format($r['cuota_unidades']) ?></td>
                                    <td class="text-end"><?= h(money($r['cuota_monto'])) ?></td>
                                    <td class="text-end">
                                        <span class="badge badge-soft <?= pctBadgeClass($pctUn) ?>">
                                            <?= number_format($pctUn, 1) ?>%
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <span class="badge badge-soft <?= pctBadgeClass($pctMo) ?>">
                                            <?= number_format($pctMo, 1) ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <tr class="table-secondary fw-bold">
                                <td colspan="3">Totales</td>
                                <td class="text-end"><?= number_format($totUnGe) ?></td>
                                <td class="text-end"><?= h(money($totMoGe)) ?></td>
                                <td colspan="4"></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

</body>
</html>