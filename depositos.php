<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$msg = '';

// 🔹 1️⃣ Validar un depósito
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_deposito'], $_POST['accion'])) {
    $idDeposito = intval($_POST['id_deposito']);
    $accion = $_POST['accion'];

    if ($accion === 'Validar') {
        // Marcar depósito como validado
        $stmt = $conn->prepare("
            UPDATE depositos_sucursal
            SET estado='Validado', id_admin_valida=?, actualizado_en=NOW()
            WHERE id=? AND estado='Pendiente'
        ");
        $stmt->bind_param("ii", $_SESSION['id_usuario'], $idDeposito);
        $stmt->execute();

        // 🔹 Verificar si ya se completó el corte para cerrarlo
        $sqlCorte = "
            SELECT ds.id_corte, cc.total_efectivo,
                   IFNULL(SUM(ds2.monto_depositado),0) AS suma_depositos
            FROM depositos_sucursal ds
            INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
            INNER JOIN depositos_sucursal ds2 ON ds2.id_corte = ds.id_corte AND ds2.estado='Validado'
            WHERE ds.id = ?
            GROUP BY ds.id_corte
        ";
        $stmtCorte = $conn->prepare($sqlCorte);
        $stmtCorte->bind_param("i", $idDeposito);
        $stmtCorte->execute();
        $corteData = $stmtCorte->get_result()->fetch_assoc();

        if ($corteData && $corteData['suma_depositos'] >= $corteData['total_efectivo']) {
            // Cerrar corte automáticamente
            $stmtClose = $conn->prepare("
                UPDATE cortes_caja
                SET estado='Cerrado', depositado=1, monto_depositado=?, fecha_deposito=NOW()
                WHERE id=?
            ");
            $stmtClose->bind_param("di", $corteData['suma_depositos'], $corteData['id_corte']);
            $stmtClose->execute();
        }

        $msg = "<div class='alert alert-success'>✅ Depósito validado correctamente.</div>";
    }
}

// 🔹 2️⃣ Depósitos pendientes (agrupados por corte)
$sqlPendientes = "
    SELECT ds.id AS id_deposito,
           s.nombre AS sucursal,
           ds.id_corte,
           cc.fecha_corte,
           cc.total_efectivo,
           ds.monto_depositado,
           ds.banco,
           ds.referencia,
           ds.estado
    FROM depositos_sucursal ds
    INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
    INNER JOIN sucursales s ON s.id = ds.id_sucursal
    WHERE ds.estado = 'Pendiente'
    ORDER BY cc.fecha_corte ASC, ds.id_corte ASC, ds.id ASC
";
$pendientes = $conn->query($sqlPendientes)->fetch_all(MYSQLI_ASSOC);

// 🔹 3️⃣ Historial de depósitos
$sqlHistorial = "
    SELECT ds.id AS id_deposito,
           s.nombre AS sucursal,
           ds.id_corte,
           cc.fecha_corte,
           ds.fecha_deposito,
           ds.monto_depositado,
           ds.banco,
           ds.referencia,
           ds.estado
    FROM depositos_sucursal ds
    INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
    INNER JOIN sucursales s ON s.id = ds.id_sucursal
    ORDER BY ds.fecha_deposito DESC
";
$historial = $conn->query($sqlHistorial)->fetch_all(MYSQLI_ASSOC);

// 🔹 4️⃣ Saldos por sucursal (corregido)
$sqlSaldos = "
    SELECT 
        s.id,
        s.nombre AS sucursal,
        IFNULL(SUM(c.monto_efectivo),0) AS total_efectivo,
        IFNULL((
            SELECT SUM(d.monto_depositado) 
            FROM depositos_sucursal d 
            WHERE d.id_sucursal = s.id AND d.estado='Validado'
        ),0) AS total_depositado,
        GREATEST(
            IFNULL(SUM(c.monto_efectivo),0) - IFNULL((
                SELECT SUM(d.monto_depositado) 
                FROM depositos_sucursal d 
                WHERE d.id_sucursal = s.id AND d.estado='Validado'
            ),0), 
        0) AS saldo_pendiente
    FROM sucursales s
    LEFT JOIN cobros c 
        ON c.id_sucursal = s.id 
       AND c.corte_generado = 1
    GROUP BY s.id
    ORDER BY saldo_pendiente DESC
";
$saldos = $conn->query($sqlSaldos)->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Validación de Depósitos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>🏦 Validación de Depósitos - Admin</h2>
    <?= $msg ?>

    <h4 class="mt-4">Depósitos Pendientes de Validación</h4>
    <?php if (count($pendientes) === 0): ?>
        <div class="alert alert-info">No hay depósitos pendientes.</div>
    <?php else: ?>
        <table class="table table-bordered table-sm">
            <thead class="table-dark">
                <tr>
                    <th>ID Depósito</th>
                    <th>Sucursal</th>
                    <th>ID Corte</th>
                    <th>Fecha Corte</th>
                    <th>Monto Depósito</th>
                    <th>Banco</th>
                    <th>Referencia</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $lastCorte = null;
                foreach ($pendientes as $p): 
                    if ($lastCorte !== $p['id_corte']): ?>
                        <tr class="table-secondary">
                            <td colspan="8">
                                Corte #<?= $p['id_corte'] ?> - <?= $p['sucursal'] ?> 
                                (Fecha: <?= $p['fecha_corte'] ?> | Total Efectivo: $<?= number_format($p['total_efectivo'],2) ?>)
                            </td>
                        </tr>
                    <?php endif; ?>
                <tr>
                    <td><?= $p['id_deposito'] ?></td>
                    <td><?= $p['sucursal'] ?></td>
                    <td><?= $p['id_corte'] ?></td>
                    <td><?= $p['fecha_corte'] ?></td>
                    <td>$<?= number_format($p['monto_depositado'],2) ?></td>
                    <td><?= $p['banco'] ?></td>
                    <td><?= $p['referencia'] ?></td>
                    <td>
                        <form method="POST">
                            <input type="hidden" name="id_deposito" value="<?= $p['id_deposito'] ?>">
                            <button name="accion" value="Validar" class="btn btn-success btn-sm">✅ Validar</button>
                        </form>
                    </td>
                </tr>
                <?php 
                    $lastCorte = $p['id_corte'];
                endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h4 class="mt-4">Historial de Depósitos</h4>
    <table class="table table-bordered table-sm">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Sucursal</th>
                <th>ID Corte</th>
                <th>Fecha Corte</th>
                <th>Fecha Depósito</th>
                <th>Monto</th>
                <th>Banco</th>
                <th>Referencia</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($historial as $h): ?>
            <tr class="<?= $h['estado']=='Validado'?'table-success':'table-warning' ?>">
                <td><?= $h['id_deposito'] ?></td>
                <td><?= $h['sucursal'] ?></td>
                <td><?= $h['id_corte'] ?></td>
                <td><?= $h['fecha_corte'] ?></td>
                <td><?= $h['fecha_deposito'] ?></td>
                <td>$<?= number_format($h['monto_depositado'],2) ?></td>
                <td><?= $h['banco'] ?></td>
                <td><?= $h['referencia'] ?></td>
                <td><?= $h['estado'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h4 class="mt-5">📊 Saldos por Sucursal</h4>
    <table class="table table-bordered table-sm mt-3">
        <thead class="table-dark">
            <tr>
                <th>Sucursal</th>
                <th>Total Efectivo Cobrado</th>
                <th>Total Depositado</th>
                <th>Saldo Pendiente</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($saldos as $s): ?>
            <tr class="<?= $s['saldo_pendiente']>0?'table-warning':'table-success' ?>">
                <td><?= $s['sucursal'] ?></td>
                <td>$<?= number_format($s['total_efectivo'],2) ?></td>
                <td>$<?= number_format($s['total_depositado'],2) ?></td>
                <td>$<?= number_format($s['saldo_pendiente'],2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
