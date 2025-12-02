<?php
// catalogo_clientes.php — Catálogo de clientes con resumen de comportamiento y filtros

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');

// =========================
// Filtros de búsqueda
// =========================
$q               = trim($_GET['q'] ?? '');
$soloActivos     = isset($_GET['solo_activos']) ? 1 : 0;
$idSucursalFiltro= (int)($_GET['id_sucursal'] ?? 0);
$soloDormidos    = isset($_GET['solo_dormidos']) ? 1 : 0;
$ultimaDesde     = trim($_GET['ultima_desde'] ?? '');
$ultimaHasta     = trim($_GET['ultima_hasta'] ?? '');

// Normalizamos fechas a objetos DateTime (solo a nivel PHP)
$ultimaDesdeDt = $ultimaDesde !== '' ? new DateTime($ultimaDesde . ' 00:00:00') : null;
$ultimaHastaDt = $ultimaHasta !== '' ? new DateTime($ultimaHasta . ' 23:59:59') : null;

// Escapar búsqueda para LIKE
$qEsc = $conn->real_escape_string($q);

// Traer sucursales para el filtro
$sqlSuc = "SELECT id, nombre FROM sucursales ORDER BY nombre";
$rsSuc  = $conn->query($sqlSuc);
$sucursales = $rsSuc ? $rsSuc->fetch_all(MYSQLI_ASSOC) : [];

// =========================
// Query principal
// =========================
//
// Agregamos por cliente desde las distintas tablas de ventas:
// - ventas (equipos)
// - ventas_sims
// - ventas_accesorios
// - ventas_payjoy_tc
//
$sql = "
SELECT
    c.id,
    c.codigo_cliente,
    c.nombre,
    c.telefono,
    c.correo,
    c.fecha_alta,
    c.activo,
    c.id_sucursal,
    s.nombre AS sucursal_nombre,

    COALESCE(e.compras_equipos, 0)      AS compras_equipos,
    COALESCE(sv.compras_sims, 0)        AS compras_sims,
    COALESCE(a.compras_accesorios, 0)   AS compras_accesorios,
    COALESCE(p.compras_payjoy, 0)       AS compras_payjoy,

    (COALESCE(e.compras_equipos, 0)
     + COALESCE(sv.compras_sims, 0)
     + COALESCE(a.compras_accesorios, 0)
     + COALESCE(p.compras_payjoy, 0))   AS total_compras,

    (COALESCE(e.monto_equipos, 0)
     + COALESCE(sv.monto_sims, 0)
     + COALESCE(a.monto_accesorios, 0)) AS monto_total,

    GREATEST(
        COALESCE(e.ultima_equipo,   '1970-01-01 00:00:00'),
        COALESCE(sv.ultima_sim,     '1970-01-01 00:00:00'),
        COALESCE(a.ultima_accesorio,'1970-01-01 00:00:00'),
        COALESCE(p.ultima_payjoy,   '1970-01-01 00:00:00')
    ) AS ultima_compra
FROM clientes c
LEFT JOIN sucursales s ON s.id = c.id_sucursal

-- Equipos
LEFT JOIN (
    SELECT 
        v.id_cliente,
        COUNT(*)                        AS compras_equipos,
        COALESCE(SUM(v.precio_venta),0) AS monto_equipos,
        MAX(v.fecha_venta)              AS ultima_equipo
    FROM ventas v
    WHERE v.id_cliente IS NOT NULL AND v.id_cliente > 0
    GROUP BY v.id_cliente
) AS e ON e.id_cliente = c.id

-- SIMs
LEFT JOIN (
    SELECT 
        vs.id_cliente,
        COUNT(*)                          AS compras_sims,
        COALESCE(SUM(vs.precio_total),0)  AS monto_sims,
        MAX(vs.fecha_venta)               AS ultima_sim
    FROM ventas_sims vs
    WHERE vs.id_cliente IS NOT NULL AND vs.id_cliente > 0
    GROUP BY vs.id_cliente
) AS sv ON sv.id_cliente = c.id

-- Accesorios
LEFT JOIN (
    SELECT 
        va.id_cliente,
        COUNT(*)                        AS compras_accesorios,
        COALESCE(SUM(va.total),0)       AS monto_accesorios,
        MAX(va.fecha_venta)             AS ultima_accesorio
    FROM ventas_accesorios va
    WHERE va.id_cliente IS NOT NULL AND va.id_cliente > 0
    GROUP BY va.id_cliente
) AS a ON a.id_cliente = c.id

-- PayJoy / TC
LEFT JOIN (
    SELECT 
        vp.id_cliente,
        COUNT(*)            AS compras_payjoy,
        MAX(vp.fecha_venta) AS ultima_payjoy
    FROM ventas_payjoy_tc vp
    WHERE vp.id_cliente IS NOT NULL AND vp.id_cliente > 0
    GROUP BY vp.id_cliente
) AS p ON p.id_cliente = c.id

WHERE 1 = 1
";

// Filtro activos
if ($soloActivos) {
    $sql .= " AND c.activo = 1 ";
}

// Filtro por sucursal base
if ($idSucursalFiltro > 0) {
    $sql .= " AND c.id_sucursal = {$idSucursalFiltro} ";
}

// Filtro de búsqueda por nombre / teléfono / código
if ($qEsc !== '') {
    $like = "%{$qEsc}%";
    $like = $conn->real_escape_string($like);
    $sql .= "
      AND (
          c.nombre         LIKE '{$like}'
       OR c.telefono       LIKE '{$like}'
       OR c.codigo_cliente LIKE '{$like}'
      )
    ";
}

$sql .= " ORDER BY ultima_compra DESC, c.nombre ASC ";

$res = $conn->query($sql);
if (!$res) {
    die("Error en consulta: " . $conn->error);
}

// =========================
// Agregados globales (ya con filtros de PHP)
// =========================
$totalClientes        = 0;
$clientesActivos      = 0;
$clientesConCompras   = 0;
$clientesRecientes    = 0; // última compra <= 30 días
$clientesDormidos     = 0; // última compra > 90 días o nunca
$montoGlobal          = 0.0;
$totalComprasGlobal   = 0; // suma de total_compras (solo clientes con compras)

$hoy  = new DateTime();
$rows = [];

while ($row = $res->fetch_assoc()) {
    // Calculamos ultima_compra como DateTime (si tiene)
    $ultimaRaw = $row['ultima_compra'];
    $ultimaDt  = null;
    $diasSinCompra = null;
    $esDormido = false;

    if ($ultimaRaw && $ultimaRaw !== '1970-01-01 00:00:00') {
        $ultimaDt = new DateTime($ultimaRaw);
        $diff     = $hoy->diff($ultimaDt);
        $diasSinCompra = $diff->days;

        // Criterio dormido: > 90 días sin compra
        if ($diasSinCompra > 90) {
            $esDormido = true;
        }
    } else {
        // Nunca ha comprado => lo consideramos dormido para segmentación
        $esDormido = true;
    }

    // =========================
    // Filtros a nivel PHP
    // =========================

    // Solo dormidos
    if ($soloDormidos && !$esDormido) {
        continue;
    }

    // Filtro por rango de fecha de última compra
    // Si tenemos fechaDesde/fechaHasta y el cliente NO tiene ultima_compra, lo saltamos
    if (($ultimaDesdeDt || $ultimaHastaDt) && !$ultimaDt) {
        continue;
    }
    if ($ultimaDesdeDt && $ultimaDt && $ultimaDt < $ultimaDesdeDt) {
        continue;
    }
    if ($ultimaHastaDt && $ultimaDt && $ultimaDt > $ultimaHastaDt) {
        continue;
    }

    // Si pasa filtros, lo contamos
    $totalClientes++;

    if ((int)$row['activo'] === 1) {
        $clientesActivos++;
    }

    if ((int)$row['total_compras'] > 0) {
        $clientesConCompras++;
        $montoGlobal        += (float)$row['monto_total'];
        $totalComprasGlobal += (int)$row['total_compras'];

        if ($diasSinCompra !== null) {
            if ($diasSinCompra <= 30) {
                $clientesRecientes++;
            } elseif ($diasSinCompra > 90) {
                $clientesDormidos++;
            }
        }
    } else {
        // Sin compras pero pasa filtros -> cuenta como dormido
        if ($esDormido) {
            $clientesDormidos++;
        }
    }

    $row['dias_sin_compra'] = $diasSinCompra;
    $rows[] = $row;
}

// KPIs globales (basados en subset filtrado)
$porcConCompras          = ($totalClientes > 0) ? ($clientesConCompras / $totalClientes * 100) : 0;
$ticketPromedioGlobal    = ($totalComprasGlobal > 0) ? ($montoGlobal / $totalComprasGlobal) : 0;
$comprasPromedioCliente  = ($clientesConCompras > 0) ? ($totalComprasGlobal / $clientesConCompras) : 0;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Catálogo de clientes</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        .badge-estado {
            font-size: 0.75rem;
        }
        .table-sm td, .table-sm th {
            padding: 0.35rem 0.5rem;
        }
    </style>
</head>
<body>

<div class="container-fluid mt-3">

    <h3 class="mb-3">Catálogo de clientes</h3>

    <!-- Filtros -->
    <form class="row g-2 mb-3" method="get">
        <div class="col-md-3 col-lg-3">
            <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>"
                   class="form-control" placeholder="Buscar por nombre, teléfono o código...">
        </div>

        <div class="col-md-3 col-lg-2">
            <select name="id_sucursal" class="form-select">
                <option value="0">Todas las sucursales</option>
                <?php foreach ($sucursales as $s): ?>
                    <option value="<?= (int)$s['id']; ?>"
                        <?= $idSucursalFiltro == (int)$s['id'] ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($s['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2 col-lg-2">
            <input type="date" name="ultima_desde" class="form-control"
                   value="<?= htmlspecialchars($ultimaDesde, ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="Última compra desde">
        </div>

        <div class="col-md-2 col-lg-2">
            <input type="date" name="ultima_hasta" class="form-control"
                   value="<?= htmlspecialchars($ultimaHasta, ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="Última compra hasta">
        </div>

        <div class="col-md-2 col-lg-3 d-flex align-items-center flex-wrap">
            <div class="form-check me-3">
                <input class="form-check-input" type="checkbox" value="1" id="solo_activos" name="solo_activos"
                    <?= $soloActivos ? 'checked' : ''; ?>>
                <label class="form-check-label" for="solo_activos">
                    Solo activos
                </label>
            </div>
            <div class="form-check me-3">
                <input class="form-check-input" type="checkbox" value="1" id="solo_dormidos" name="solo_dormidos"
                    <?= $soloDormidos ? 'checked' : ''; ?>>
                <label class="form-check-label" for="solo_dormidos">
                    Solo dormidos
                </label>
            </div>
            <button class="btn btn-primary btn-sm mt-2 mt-md-0" type="submit">Aplicar filtros</button>
        </div>
    </form>

    <!-- Resumen rápido (KPIs globales) -->
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Clientes en vista</div>
                    <div class="h5 mb-0"><?= number_format($totalClientes); ?></div>
                    <div class="small text-muted mt-1">
                        Activos: <?= number_format($clientesActivos); ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Clientes con compras</div>
                    <div class="h5 mb-0"><?= number_format($clientesConCompras); ?></div>
                    <div class="small text-muted mt-1">
                        <?= number_format($porcConCompras, 1); ?>% de la vista
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Monto total vendido</div>
                    <div class="h5 mb-0">$<?= number_format($montoGlobal, 2); ?></div>
                    <div class="small text-muted mt-1">
                        Equipos + SIMs + accesorios
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Ticket promedio global</div>
                    <div class="h5 mb-0">
                        $<?= number_format($ticketPromedioGlobal, 2); ?>
                    </div>
                    <div class="small text-muted mt-1">
                        Por operación (solo con compras)
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Compras promedio por cliente</div>
                    <div class="h5 mb-0">
                        <?= number_format($comprasPromedioCliente, 1); ?> compras
                    </div>
                    <div class="small text-muted mt-1">
                        Solo clientes que ya compraron
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Clientes recientes</div>
                    <div class="h5 mb-0"><?= number_format($clientesRecientes); ?></div>
                    <div class="small text-muted mt-1">
                        Última compra ≤ 30 días
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Clientes dormidos</div>
                    <div class="h5 mb-0"><?= number_format($clientesDormidos); ?></div>
                    <div class="small text-muted mt-1">
                        > 90 días sin compra o nunca
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de clientes -->
    <div class="table-responsive">
        <table class="table table-striped table-hover table-sm align-middle">
            <thead class="table-light">
            <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Teléfono</th>
                <th>Sucursal</th>
                <th class="text-center">Compras</th>
                <th class="text-end">Monto total</th>
                <th class="text-center">Equipos</th>
                <th class="text-center">SIMs</th>
                <th class="text-center">Acc.</th>
                <th class="text-center">PayJoy</th>
                <th>Última compra</th>
                <th class="text-center">Días sin compra</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="13" class="text-center text-muted py-4">
                        No se encontraron clientes con los filtros seleccionados.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $c): ?>
                    <?php
                    $ultima    = $c['ultima_compra'];
                    $ultimaTxt = '—';
                    if ($ultima && $ultima !== '1970-01-01 00:00:00') {
                        $ultimaTxt = date('Y-m-d H:i', strtotime($ultima));
                    }

                    $diasSin = $c['dias_sin_compra'];
                    $badgeDias = '—';
                    if ($diasSin !== null) {
                        if ($diasSin <= 30) {
                            $badgeClass = 'bg-success';
                        } elseif ($diasSin <= 90) {
                            $badgeClass = 'bg-warning text-dark';
                        } else {
                            $badgeClass = 'bg-danger';
                        }
                        $badgeDias = "<span class=\"badge {$badgeClass} badge-estado\">{$diasSin} días</span>";
                    }

                    $badgeActivo = $c['activo'] ? 
                        '<span class="badge bg-success badge-estado">Activo</span>' :
                        '<span class="badge bg-secondary badge-estado">Inactivo</span>';
                    ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($c['codigo_cliente'] ?: '-', ENT_QUOTES, 'UTF-8'); ?><br>
                            <?= $badgeActivo; ?>
                        </td>
                        <td><?= htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($c['telefono'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($c['sucursal_nombre'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="text-center"><?= (int)$c['total_compras']; ?></td>
                        <td class="text-end">$<?= number_format((float)$c['monto_total'], 2); ?></td>
                        <td class="text-center"><?= (int)$c['compras_equipos']; ?></td>
                        <td class="text-center"><?= (int)$c['compras_sims']; ?></td>
                        <td class="text-center"><?= (int)$c['compras_accesorios']; ?></td>
                        <td class="text-center"><?= (int)$c['compras_payjoy']; ?></td>
                        <td><?= $ultimaTxt; ?></td>
                        <td class="text-center"><?= $badgeDias; ?></td>
                        <td class="text-end">
                            <a href="cliente_detalle.php?id_cliente=<?= (int)$c['id']; ?>"
                               class="btn btn-sm btn-outline-primary">
                                Detalle
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>
