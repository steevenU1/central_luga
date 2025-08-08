<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','Gerente'])) {
    header("Location: 403.php");
    exit();
}

include 'db.php';

// Obtener ID de Eulalia
$idEulalia = $conn->query("SELECT id FROM sucursales WHERE nombre='Eulalia' LIMIT 1")->fetch_assoc()['id'] ?? 0;

$mensaje = '';

// 🔹 Procesar traspaso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['equipos'])) {
    $equiposSeleccionados = $_POST['equipos'];
    $idSucursalDestino = (int)$_POST['sucursal_destino'];
    $idUsuario = $_SESSION['id_usuario'];

    if (count($equiposSeleccionados) > 0) {
        // Crear registro en traspasos
        $stmt = $conn->prepare("
            INSERT INTO traspasos (id_sucursal_origen, id_sucursal_destino, usuario_creo, estatus)
            VALUES (?,?,?, 'Pendiente')
        ");
        $stmt->bind_param("iii", $idEulalia, $idSucursalDestino, $idUsuario);
        $stmt->execute();
        $idTraspaso = $stmt->insert_id;
        $stmt->close();

        // Insertar detalle y actualizar inventario
        $stmtDetalle = $conn->prepare("INSERT INTO detalle_traspaso (id_traspaso, id_inventario) VALUES (?, ?)");
        $stmtUpdate = $conn->prepare("UPDATE inventario SET estatus='En tránsito' WHERE id=?");

        foreach ($equiposSeleccionados as $idInventario) {
            // Registrar detalle
            $stmtDetalle->bind_param("ii", $idTraspaso, $idInventario);
            $stmtDetalle->execute();

            // Actualizar estatus
            $stmtUpdate->bind_param("i", $idInventario);
            $stmtUpdate->execute();
        }

        $stmtDetalle->close();
        $stmtUpdate->close();

        $mensaje = "<div class='alert alert-success'>Traspaso #$idTraspaso generado con éxito. Los equipos ahora están en tránsito.</div>";
    } else {
        $mensaje = "<div class='alert alert-warning'>No seleccionaste ningún equipo para traspasar.</div>";
    }
}

// 🔹 Consultar inventario disponible en Eulalia
$sql = "
SELECT i.id, p.marca, p.modelo, p.color, p.imei1, p.imei2
FROM inventario i
INNER JOIN productos p ON p.id = i.id_producto
WHERE i.id_sucursal=? AND i.estatus='Disponible'
ORDER BY i.fecha_ingreso ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idEulalia);
$stmt->execute();
$result = $stmt->get_result();

// 🔹 Consultar sucursales destino (solo tipo Tienda)
$sucursales = $conn->query("SELECT id, nombre FROM sucursales WHERE tipo_sucursal='Tienda' ORDER BY nombre ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar Traspaso</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>🚚 Generar Traspaso desde Eulalia</h2>
    <?= $mensaje ?>

    <div class="card mb-4 shadow">
        <div class="card-header bg-dark text-white">Seleccionar sucursal destino</div>
        <div class="card-body">
            <form method="POST">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <select name="sucursal_destino" class="form-select" required>
                            <option value="">-- Selecciona Sucursal --</option>
                            <?php while($row = $sucursales->fetch_assoc()): ?>
                                <option value="<?= $row['id'] ?>"><?= $row['nombre'] ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <!-- 🔹 Buscador de IMEI -->
                <div class="mb-2">
                    <input type="text" id="buscadorIMEI" class="form-control" placeholder="Buscar por IMEI...">
                </div>

                <!-- 🔹 Tabla de inventario con filtro -->
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">Inventario disponible en Eulalia</div>
                    <div class="card-body p-0">
                        <table class="table table-striped table-bordered table-sm mb-0" id="tablaInventario">
                            <thead class="table-dark">
                                <tr>
                                    <th>Seleccionar</th>
                                    <th>ID Inv</th>
                                    <th>Marca</th>
                                    <th>Modelo</th>
                                    <th>Color</th>
                                    <th>IMEI1</th>
                                    <th>IMEI2</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><input type="checkbox" name="equipos[]" value="<?= $row['id'] ?>"></td>
                                        <td><?= $row['id'] ?></td>
                                        <td><?= $row['marca'] ?></td>
                                        <td><?= $row['modelo'] ?></td>
                                        <td><?= $row['color'] ?></td>
                                        <td><?= $row['imei1'] ?></td>
                                        <td><?= $row['imei2'] ?: '-' ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-success">Generar Traspaso</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 🔹 Script para filtrar IMEI en tiempo real -->
<script>
document.getElementById('buscadorIMEI').addEventListener('keyup', function() {
    let filtro = this.value.toLowerCase();
    document.querySelectorAll('#tablaInventario tbody tr').forEach(function(row) {
        let imei1 = row.cells[5].textContent.toLowerCase();
        let imei2 = row.cells[6].textContent.toLowerCase();
        row.style.display = (imei1.includes(filtro) || imei2.includes(filtro)) ? '' : 'none';
    });
});
</script>

</body>
</html>
