<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Gerente','Admin'])) {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$id_usuario = $_SESSION['id_usuario'];
$id_sucursal = $_SESSION['id_sucursal'];
$fechaHoy = date('Y-m-d');
$msg = '';

// 🔹 1️⃣ Obtener días con cobros realmente pendientes (sin corte y no generados)
$sqlDiasPendientes = "
    SELECT DATE(fecha_cobro) AS fecha, COUNT(*) AS total
    FROM cobros
    WHERE id_sucursal = ? 
      AND id_corte IS NULL 
      AND corte_generado = 0
    GROUP BY DATE(fecha_cobro)
    ORDER BY fecha ASC
";
$stmt = $conn->prepare($sqlDiasPendientes);
$stmt->bind_param("i", $id_sucursal);
$stmt->execute();
$diasPendientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pendientes = [];
foreach ($diasPendientes as $d) {
    $pendientes[$d['fecha']] = $d['total'];
}

// 🔹 Detectar la fecha pendiente más antigua
$fechaPendiente = !empty($pendientes) ? array_key_first($pendientes) : null;

// Fecha de operación por defecto
$fechaOperacion = $fechaPendiente ?: $fechaHoy;

// 🔹 2️⃣ Procesar generación de corte
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fecha_operacion'])) {
    $fecha_operacion = $_POST['fecha_operacion'] ?? $fechaOperacion;

    // Obtener cobros de esa fecha sin corte
    $sqlCobros = "
        SELECT *
        FROM cobros
        WHERE id_sucursal = ?
          AND DATE(fecha_cobro) = ?
          AND id_corte IS NULL
          AND corte_generado = 0
    ";
    $stmt = $conn->prepare($sqlCobros);
    $stmt->bind_param("is", $id_sucursal, $fecha_operacion);
    $stmt->execute();
    $cobros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (count($cobros) === 0) {
        $msg = "<div class='alert alert-info'>
            ⚠ No hay cobros pendientes para generar corte en la fecha $fecha_operacion.
        </div>";
    } else {
        // Calcular totales
        $total_efectivo = 0;
        $total_tarjeta = 0;
        $total_comision_especial = 0;

        foreach ($cobros as $c) {
            $total_efectivo += $c['monto_efectivo'];
            $total_tarjeta  += $c['monto_tarjeta'];
            $total_comision_especial += $c['comision_especial'];
        }

        $total_general = $total_efectivo + $total_tarjeta;

        // Insertar corte
        $stmtCorte = $conn->prepare("
            INSERT INTO cortes_caja
            (id_sucursal, id_usuario, fecha_operacion, fecha_corte, estado,
             total_efectivo, total_tarjeta, total_comision_especial, total_general,
             depositado, monto_depositado, observaciones)
            VALUES (?, ?, ?, NOW(), 'Pendiente', ?, ?, ?, ?, 0, 0, '')
        ");
        $stmtCorte->bind_param(
            "issdddd",
            $id_sucursal,
            $id_usuario,
            $fecha_operacion,
            $total_efectivo,
            $total_tarjeta,
            $total_comision_especial,
            $total_general
        );
        $stmtCorte->execute();
        $id_corte = $stmtCorte->insert_id;

        // Asociar cobros al corte
        $stmtUpd = $conn->prepare("
            UPDATE cobros
            SET id_corte = ?, corte_generado = 1
            WHERE id_sucursal = ? AND DATE(fecha_cobro) = ? AND id_corte IS NULL
        ");
        $stmtUpd->bind_param("iis", $id_corte, $id_sucursal, $fecha_operacion);
        $stmtUpd->execute();

        $msg = "<div class='alert alert-success'>
            ✅ Corte generado correctamente (ID: $id_corte) para $fecha_operacion.
        </div>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar Corte de Caja</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>🧾 Generar Corte de Caja</h2>
    <?= $msg ?>

    <div class="mb-3">
        <h5>Días pendientes de corte:</h5>
        <?php if (empty($pendientes)): ?>
            <div class="alert alert-info">No hay días pendientes.</div>
        <?php else: ?>
            <ul>
                <?php foreach ($pendientes as $fecha => $total): ?>
                    <li><?= $fecha ?> → <?= $total ?> cobros</li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if (!empty($pendientes)): ?>
        <form method="POST" class="card p-3 shadow mb-4">
            <label class="form-label">Fecha de operación</label>
            <input type="date" name="fecha_operacion" class="form-control"
                   value="<?= $fechaOperacion ?>"
                   max="<?= $fechaHoy ?>"
                   <?= ($fechaPendiente && $fechaPendiente < $fechaHoy) ? 'readonly' : '' ?>
                   required>
            <button class="btn btn-primary mt-3 w-100"
                    onclick="return confirm('¿Confirmas generar el corte de caja para la fecha seleccionada?');">
                📤 Generar Corte
            </button>
        </form>
    <?php endif; ?>

    <h4>Cobros pendientes para la fecha seleccionada</h4>
    <?php
    $sqlCobrosPend = "
        SELECT c.*, u.nombre AS usuario
        FROM cobros c
        INNER JOIN usuarios u ON u.id = c.id_usuario
        WHERE c.id_sucursal = ? 
          AND DATE(c.fecha_cobro) = ?
          AND c.id_corte IS NULL
          AND c.corte_generado = 0
        ORDER BY c.fecha_cobro ASC
    ";
    $stmt = $conn->prepare($sqlCobrosPend);
    $stmt->bind_param("is", $id_sucursal, $fechaOperacion);
    $stmt->execute();
    $cobrosFecha = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (count($cobrosFecha) === 0): ?>
        <div class="alert alert-info">No hay cobros pendientes para la fecha <?= $fechaOperacion ?>.</div>
    <?php else: ?>
        <table class="table table-sm table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Motivo</th>
                    <th>Tipo Pago</th>
                    <th>Total</th>
                    <th>Efectivo</th>
                    <th>Tarjeta</th>
                    <th>Comisión Esp.</th>
                    <th>Eliminar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($cobrosFecha as $p): ?>
                <tr>
                    <td><?= $p['fecha_cobro'] ?></td>
                    <td><?= $p['usuario'] ?></td>
                    <td><?= $p['motivo'] ?></td>
                    <td><?= $p['tipo_pago'] ?></td>
                    <td>$<?= number_format($p['monto_total'],2) ?></td>
                    <td>$<?= number_format($p['monto_efectivo'],2) ?></td>
                    <td>$<?= number_format($p['monto_tarjeta'],2) ?></td>
                    <td>$<?= number_format($p['comision_especial'],2) ?></td>
                    <td>
                        <a href="eliminar_cobro.php?id=<?= $p['id'] ?>" 
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('¿Seguro de eliminar este cobro?');">🗑</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>


    <!-- 🔹 Nuevo Historial de Cortes -->
    <h3 class="mt-5">📜 Historial de Cortes</h3>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-4">
            <label class="form-label">Desde</label>
            <input type="date" name="desde" class="form-control" value="<?= $_GET['desde'] ?? date('Y-m-01') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Hasta</label>
            <input type="date" name="hasta" class="form-control" value="<?= $_GET['hasta'] ?? date('Y-m-d') ?>">
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <button class="btn btn-primary w-100">Filtrar</button>
        </div>
    </form>

    <?php
    $desde = $_GET['desde'] ?? date('Y-m-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-d');

    $sqlHistCortes = "
        SELECT cc.*, u.nombre AS usuario
        FROM cortes_caja cc
        INNER JOIN usuarios u ON u.id = cc.id_usuario
        WHERE cc.id_sucursal = ?
          AND DATE(cc.fecha_corte) BETWEEN ? AND ?
        ORDER BY cc.fecha_corte DESC
    ";
    $stmtHistCortes = $conn->prepare($sqlHistCortes);
    $stmtHistCortes->bind_param("iss", $id_sucursal, $desde, $hasta);
    $stmtHistCortes->execute();
    $histCortes = $stmtHistCortes->get_result()->fetch_all(MYSQLI_ASSOC);
    ?>

    <?php if (empty($histCortes)): ?>
        <div class="alert alert-info">No hay cortes en el rango seleccionado.</div>
    <?php else: ?>
        <table class="table table-bordered table-sm">
            <thead class="table-dark">
                <tr>
                    <th>ID Corte</th>
                    <th>Fecha Corte</th>
                    <th>Usuario</th>
                    <th>Efectivo</th>
                    <th>Tarjeta</th>
                    <th>Total</th>
                    <th>Estado</th>
                    <th>Monto Depositado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($histCortes as $c): ?>
                <tr class="<?= $c['estado']=='Cerrado'?'table-success':'table-warning' ?>">
                    <td><?= $c['id'] ?></td>
                    <td><?= $c['fecha_corte'] ?></td>
                    <td><?= $c['usuario'] ?></td>
                    <td>$<?= number_format($c['total_efectivo'],2) ?></td>
                    <td>$<?= number_format($c['total_tarjeta'],2) ?></td>
                    <td>$<?= number_format($c['total_general'],2) ?></td>
                    <td><?= $c['estado'] ?></td>
                    <td>$<?= number_format($c['monto_depositado'],2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</div>

</body>
</html>
