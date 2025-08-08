<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// Obtener sucursal del usuario y rol
$idSucursalUsuario = $_SESSION['id_sucursal'];
$rolUsuario = $_SESSION['rol'];

// 🔹 Condición para filtrar traspasos por sucursal
$whereSucursal = "id_sucursal_destino = $idSucursalUsuario";

$mensaje = '';

// 🔹 Confirmar traspaso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_traspaso'])) {
    $idTraspaso = (int)$_POST['id_traspaso'];

    // Obtener los equipos de este traspaso
    $equipos = $conn->query("
        SELECT dt.id_inventario
        FROM detalle_traspaso dt
        INNER JOIN inventario i ON i.id = dt.id_inventario
        INNER JOIN traspasos t ON t.id = dt.id_traspaso
        WHERE dt.id_traspaso = $idTraspaso 
          AND t.$whereSucursal 
          AND t.estatus = 'Pendiente'
    ");

    // Actualizar inventario: asignar sucursal destino y poner Disponible
    while ($row = $equipos->fetch_assoc()) {
        $idInventario = $row['id_inventario'];
        $stmt = $conn->prepare("UPDATE inventario SET id_sucursal=?, estatus='Disponible' WHERE id=?");
        $stmt->bind_param("ii", $idSucursalUsuario, $idInventario);
        $stmt->execute();
        $stmt->close();
    }

    // Marcar traspaso como completado
    $conn->query("UPDATE traspasos SET estatus='Completado' WHERE id=$idTraspaso");

    $mensaje = "<div class='alert alert-success mt-3'>✅ Traspaso #$idTraspaso confirmado. Los equipos ya están en tu inventario.</div>";
}

// 🔹 Consultar traspasos pendientes de esta sucursal
$sql = "
    SELECT t.id, t.fecha_traspaso, s.nombre AS sucursal_origen, u.nombre AS usuario_creo
    FROM traspasos t
    INNER JOIN sucursales s ON s.id = t.id_sucursal_origen
    INNER JOIN usuarios u ON u.id = t.usuario_creo
    WHERE t.$whereSucursal AND t.estatus='Pendiente'
    ORDER BY t.fecha_traspaso ASC
";
$traspasos = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Traspasos Pendientes</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📦 Traspasos Pendientes</h2>
    <?= $mensaje ?>

    <?php if ($traspasos->num_rows > 0): ?>
        <?php while($traspaso = $traspasos->fetch_assoc()): ?>
            <?php
            $idTraspaso = $traspaso['id'];
            $detalles = $conn->query("
                SELECT i.id, p.marca, p.modelo, p.color, p.imei1, p.imei2
                FROM detalle_traspaso dt
                INNER JOIN inventario i ON i.id = dt.id_inventario
                INNER JOIN productos p ON p.id = i.id_producto
                WHERE dt.id_traspaso = $idTraspaso
            ");
            ?>
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Traspaso #<?= $idTraspaso ?> | Origen: <?= $traspaso['sucursal_origen'] ?> | Fecha: <?= $traspaso['fecha_traspaso'] ?></span>
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
                                <th>IMEI1</th>
                                <th>IMEI2</th>
                                <th>Estatus Actual</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $detalles->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $row['id'] ?></td>
                                    <td><?= $row['marca'] ?></td>
                                    <td><?= $row['modelo'] ?></td>
                                    <td><?= $row['color'] ?></td>
                                    <td><?= $row['imei1'] ?></td>
                                    <td><?= $row['imei2'] ?: '-' ?></td>
                                    <td>En tránsito</td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-end">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="id_traspaso" value="<?= $idTraspaso ?>">
                        <button type="submit" name="confirmar_traspaso" class="btn btn-success btn-sm">Confirmar Recepción</button>
                    </form>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="alert alert-info mt-3">No hay traspasos pendientes para tu sucursal.</div>
    <?php endif; ?>
</div>

</body>
</html>
