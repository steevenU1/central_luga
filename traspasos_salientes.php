<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idSucursalUsuario = $_SESSION['id_sucursal'];
$rolUsuario = $_SESSION['rol'];
$mensaje = "";

// Mostrar mensaje si viene por eliminación exitosa
if (isset($_GET['msg']) && $_GET['msg'] === 'eliminado') {
    $mensaje = "<div class='alert alert-success'>✅ Traspaso eliminado correctamente.</div>";
}

// 🔹 Consultar traspasos salientes pendientes
$sql = "
    SELECT t.id, t.fecha_traspaso, s.nombre AS sucursal_destino, u.nombre AS usuario_creo
    FROM traspasos t
    INNER JOIN sucursales s ON s.id = t.id_sucursal_destino
    INNER JOIN usuarios u ON u.id = t.usuario_creo
    WHERE t.id_sucursal_origen = ? AND t.estatus='Pendiente'
    ORDER BY t.fecha_traspaso ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idSucursalUsuario);
$stmt->execute();
$traspasos = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Traspasos Salientes Pendientes</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📦 Traspasos Salientes Pendientes</h2>
    <p class="text-muted">Estos son los traspasos que tu sucursal ha enviado y que aún no han sido confirmados por la sucursal destino.</p>
    <?= $mensaje ?>

    <?php if ($traspasos->num_rows > 0): ?>
        <?php while($traspaso = $traspasos->fetch_assoc()): ?>
            <?php
            $idTraspaso = $traspaso['id'];
            $detalles = $conn->query("
                SELECT i.id, p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2
                FROM detalle_traspaso dt
                INNER JOIN inventario i ON i.id = dt.id_inventario
                INNER JOIN productos p ON p.id = i.id_producto
                WHERE dt.id_traspaso = $idTraspaso
            ");
            ?>
            <div class="card mb-4 shadow">
                <div class="card-header bg-secondary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Traspaso #<?= $idTraspaso ?> | Destino: <?= $traspaso['sucursal_destino'] ?> | Fecha: <?= $traspaso['fecha_traspaso'] ?></span>
                        <span>Creado por: <?= $traspaso['usuario_creo'] ?></span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped table-bordered table-sm mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID Inv</th>
                                <th>Marca</th>
                                <th>Modelo</th>
                                <th>Color</th>
                                <th>Capacidad</th>
                                <th>IMEI1</th>
                                <th>IMEI2</th>
                                <th>Estatus</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $detalles->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $row['id'] ?></td>
                                    <td><?= $row['marca'] ?></td>
                                    <td><?= $row['modelo'] ?></td>
                                    <td><?= $row['color'] ?></td>
                                    <td><?= $row['capacidad'] ?: '-' ?></td>
                                    <td><?= $row['imei1'] ?></td>
                                    <td><?= $row['imei2'] ?: '-' ?></td>
                                    <td>En tránsito</td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-muted d-flex justify-content-between align-items-center">
                    <span>Esperando confirmación de <b><?= $traspaso['sucursal_destino'] ?></b>...</span>

                    <!-- 🔴 Botón para eliminar traspaso -->
                    <form method="POST" action="eliminar_traspaso.php" onsubmit="return confirm('¿Estás seguro de eliminar este traspaso? Esta acción no se puede deshacer.')">
                        <input type="hidden" name="id_traspaso" value="<?= $idTraspaso ?>">
                        <button type="submit" class="btn btn-sm btn-danger">🗑️ Eliminar Traspaso</button>
                    </form>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="alert alert-info">No hay traspasos salientes pendientes para tu sucursal.</div>
    <?php endif; ?>
</div>

</body>
</html>
