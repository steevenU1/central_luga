<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Gerente','Admin'])) {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$idUsuario = $_SESSION['id_usuario'];
$idSucursal = $_SESSION['id_sucursal'];
$rolUsuario = $_SESSION['rol'];
$msg = '';

// 🔹 Guardar depósito si se envió el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_corte'])) {
    $id_corte = intval($_POST['id_corte']);
    $fecha_deposito = $_POST['fecha_deposito'] ?? date('Y-m-d');
    $banco = trim($_POST['banco'] ?? '');
    $monto = floatval($_POST['monto_depositado'] ?? 0);
    $referencia = trim($_POST['referencia'] ?? '');
    $motivo = trim($_POST['motivo'] ?? '');

    if ($id_corte > 0 && $monto > 0 && $banco != '') {
        // 🔹 Verificar total efectivo y suma de depósitos existentes
        $sqlCheck = "
            SELECT cc.total_efectivo, 
                   IFNULL(SUM(ds.monto_depositado),0) AS suma_actual
            FROM cortes_caja cc
            LEFT JOIN depositos_sucursal ds ON ds.id_corte = cc.id
            WHERE cc.id = ?
            GROUP BY cc.id
        ";
        $stmt = $conn->prepare($sqlCheck);
        $stmt->bind_param("i", $id_corte);
        $stmt->execute();
        $corte = $stmt->get_result()->fetch_assoc();

        if ($corte) {
            $total_efectivo = $corte['total_efectivo'];
            $suma_actual = $corte['suma_actual'];
            $pendiente = $total_efectivo - $suma_actual;

            if ($monto > $pendiente) {
                $msg = "<div class='alert alert-danger'>❌ El depósito excede el monto pendiente del corte. Solo queda $".number_format($pendiente,2)."</div>";
            } else {
                // Insertar depósito
                $stmtIns = $conn->prepare("
                    INSERT INTO depositos_sucursal
                    (id_sucursal, id_corte, fecha_deposito, monto_depositado, banco, referencia, observaciones, estado, creado_en)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Pendiente', NOW())
                ");
                $stmtIns->bind_param("iisddss", $idSucursal, $id_corte, $fecha_deposito, $monto, $banco, $referencia, $motivo);
                if ($stmtIns->execute()) {
                    $msg = "<div class='alert alert-success'>✅ Depósito registrado correctamente.</div>";
                } else {
                    $msg = "<div class='alert alert-danger'>❌ Error al registrar depósito.</div>";
                }
            }
        } else {
            $msg = "<div class='alert alert-danger'>❌ Corte no encontrado.</div>";
        }
    } else {
        $msg = "<div class='alert alert-warning'>⚠ Debes llenar todos los campos obligatorios.</div>";
    }
}

// 🔹 Obtener cortes pendientes de depósito
$sqlPendientes = "
    SELECT cc.id, cc.fecha_corte, cc.total_efectivo,
           IFNULL(SUM(ds.monto_depositado),0) AS total_depositado
    FROM cortes_caja cc
    LEFT JOIN depositos_sucursal ds ON ds.id_corte = cc.id
    WHERE cc.id_sucursal = ? AND cc.estado='Pendiente'
    GROUP BY cc.id
    ORDER BY cc.fecha_corte ASC
";
$stmtPend = $conn->prepare($sqlPendientes);
$stmtPend->bind_param("i", $idSucursal);
$stmtPend->execute();
$cortesPendientes = $stmtPend->get_result()->fetch_all(MYSQLI_ASSOC);

// 🔹 Obtener historial de depósitos
$sqlHistorial = "
    SELECT ds.*, cc.fecha_corte
    FROM depositos_sucursal ds
    INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
    WHERE ds.id_sucursal = ?
    ORDER BY ds.fecha_deposito DESC
";
$stmtHist = $conn->prepare($sqlHistorial);
$stmtHist->bind_param("i", $idSucursal);
$stmtHist->execute();
$historial = $stmtHist->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Depósitos Sucursal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>🏦 Depósitos Bancarios - <?= htmlspecialchars($_SESSION['nombre']) ?> (<?= $rolUsuario ?>)</h2>
    <?= $msg ?>

    <h4 class="mt-4">Cortes pendientes de depósito</h4>
    <?php if (count($cortesPendientes) == 0): ?>
        <div class="alert alert-info">No hay cortes pendientes de depósito.</div>
    <?php else: ?>
        <table class="table table-bordered table-sm">
            <thead class="table-dark">
                <tr>
                    <th>ID Corte</th>
                    <th>Fecha Corte</th>
                    <th>Efectivo a Depositar</th>
                    <th>Total Depositado</th>
                    <th>Pendiente</th>
                    <th>Registrar Depósito</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cortesPendientes as $c): 
                    $pendiente = $c['total_efectivo'] - $c['total_depositado'];
                ?>
                <tr>
                    <td><?= $c['id'] ?></td>
                    <td><?= $c['fecha_corte'] ?></td>
                    <td>$<?= number_format($c['total_efectivo'],2) ?></td>
                    <td>$<?= number_format($c['total_depositado'],2) ?></td>
                    <td class="fw-bold text-danger">$<?= number_format($pendiente,2) ?></td>
                    <td>
                        <form method="POST" class="row g-2">
                            <input type="hidden" name="id_corte" value="<?= $c['id'] ?>">
                            <div class="col-md-4">
                                <input type="date" name="fecha_deposito" class="form-control" required>
                            </div>
                            <div class="col-md-3">
                                <input type="number" step="0.01" name="monto_depositado" class="form-control" placeholder="Monto" required>
                            </div>
                            <div class="col-md-2">
                                <input type="text" name="banco" class="form-control" placeholder="Banco" required>
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="referencia" class="form-control" placeholder="Referencia">
                            </div>
                            <div class="col-md-12 mt-1">
                                <input type="text" name="motivo" class="form-control" placeholder="Motivo (opcional)">
                            </div>
                            <div class="col-md-12 mt-1">
                                <button class="btn btn-success btn-sm w-100">💾 Guardar</button>
                            </div>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h4 class="mt-4">Historial de Depósitos</h4>
    <table class="table table-bordered table-sm">
        <thead class="table-dark">
            <tr>
                <th>ID Depósito</th>
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
                <td><?= $h['id'] ?></td>
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
</div>

</body>
</html>
