<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idUsuario = $_SESSION['id_usuario'];
$idSucursal = $_SESSION['id_sucursal'];
$mensaje = "";

// 🔹 Procesar traspaso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['equipos']) && isset($_POST['sucursal_destino'])) {
    $sucursalDestino = (int)$_POST['sucursal_destino'];
    $equiposSeleccionados = $_POST['equipos'];

    if (!empty($equiposSeleccionados)) {
        // 1️⃣ Insertar traspaso
        $stmt = $conn->prepare("INSERT INTO traspasos (id_sucursal_origen, id_sucursal_destino, fecha_traspaso, estatus, usuario_creo)
                                VALUES (?, ?, NOW(), 'Pendiente', ?)");
        $stmt->bind_param("iii", $idSucursal, $sucursalDestino, $idUsuario);
        $stmt->execute();
        $idTraspaso = $stmt->insert_id;
        $stmt->close();

        // 2️⃣ Insertar detalle y actualizar inventario
        $stmtDetalle = $conn->prepare("INSERT INTO detalle_traspaso (id_traspaso, id_inventario) VALUES (?, ?)");
        $stmtUpdateInv = $conn->prepare("UPDATE inventario SET estatus='En tránsito' WHERE id=?");

        foreach ($equiposSeleccionados as $idInventario) {
            $idInventario = (int)$idInventario;
            $stmtDetalle->bind_param("ii", $idTraspaso, $idInventario);
            $stmtDetalle->execute();
            $stmtUpdateInv->bind_param("i", $idInventario);
            $stmtUpdateInv->execute();
        }

        $stmtDetalle->close();
        $stmtUpdateInv->close();

        $mensaje = "<div class='alert alert-success'>✅ Traspaso #$idTraspaso generado correctamente. 
                    Los equipos seleccionados ahora están en tránsito.</div>";
    } else {
        $mensaje = "<div class='alert alert-warning'>⚠️ Debes seleccionar al menos un equipo.</div>";
    }
}

// 🔹 Sucursales destino (todas menos la actual)
$sqlSucursales = "SELECT id, nombre FROM sucursales WHERE id != ? ORDER BY nombre";
$stmtSuc = $conn->prepare($sqlSucursales);
$stmtSuc->bind_param("i", $idSucursal);
$stmtSuc->execute();
$sucursales = $stmtSuc->get_result();

// 🔹 Inventario disponible
$sqlInventario = "
    SELECT i.id, p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id_sucursal = ? AND i.estatus = 'Disponible'
    ORDER BY p.marca, p.modelo
";
$stmtInv = $conn->prepare($sqlInventario);
$stmtInv->bind_param("i", $idSucursal);
$stmtInv->execute();
$inventario = $stmtInv->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nuevo Traspaso</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script>
        // 🔹 Búsqueda en tiempo real
        function buscarEquipo() {
            const input = document.getElementById('buscarIMEI').value.toLowerCase();
            const filas = document.querySelectorAll('#tablaInventario tbody tr');

            filas.forEach(fila => {
                const textoFila = fila.innerText.toLowerCase();
                fila.style.display = textoFila.includes(input) ? '' : 'none';
            });
        }
    </script>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>🚚 Generar Traspaso de Equipos</h2>
    <?= $mensaje ?>

    <form method="POST" class="card p-3 mb-4 shadow-sm bg-white">
        <div class="mb-3">
            <label><strong>Sucursal destino:</strong></label>
            <select name="sucursal_destino" class="form-select w-auto" required>
                <option value="">-- Selecciona --</option>
                <?php while ($s = $sucursales->fetch_assoc()): ?>
                    <option value="<?= $s['id'] ?>"><?= $s['nombre'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <!-- 🔹 Buscador -->
        <div class="mb-3">
            <input type="text" id="buscarIMEI" class="form-control" placeholder="Buscar por IMEI, Marca o Modelo..." onkeyup="buscarEquipo()">
        </div>

        <h5>Selecciona equipos a traspasar:</h5>
        <table class="table table-bordered table-striped table-hover align-middle" id="tablaInventario">
            <thead class="table-dark">
                <tr>
                    <th></th>
                    <th>ID Inv</th>
                    <th>Marca</th>
                    <th>Modelo</th>
                    <th>Color</th>
                    <th>Capacidad</th>
                    <th>IMEI1</th>
                    <th>IMEI2</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($inventario->num_rows > 0): ?>
                    <?php while ($row = $inventario->fetch_assoc()): ?>
                        <tr>
                            <td><input type="checkbox" name="equipos[]" value="<?= $row['id'] ?>"></td>
                            <td><?= $row['id'] ?></td>
                            <td><?= $row['marca'] ?></td>
                            <td><?= $row['modelo'] ?></td>
                            <td><?= $row['color'] ?></td>
                            <td><?= $row['capacidad'] ?: '-' ?></td>
                            <td><?= $row['imei1'] ?></td>
                            <td><?= $row['imei2'] ?: '-' ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="text-center">No hay equipos disponibles en esta sucursal</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="text-end mt-3">
            <button type="submit" class="btn btn-success">Generar Traspaso</button>
        </div>
    </form>
</div>

</body>
</html>
