<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';

// Obtener ID de Eulalia
$idEulalia = $conn->query("SELECT id FROM sucursales WHERE nombre='Eulalia' LIMIT 1")->fetch_assoc()['id'] ?? 0;

// 🔹 Agregar producto nuevo
$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $marca = trim($_POST['marca']);
    $modelo = trim($_POST['modelo']);
    $color = trim($_POST['color']);
    $imei1 = trim($_POST['imei1']);
    $imei2 = trim($_POST['imei2']) ?: NULL;
    $costo = (float)$_POST['costo'];
    $precio_lista = (float)$_POST['precio_lista'];
    $tipo_producto = $_POST['tipo_producto'];

    if ($imei1 == '') {
        $mensaje = '<div class="alert alert-danger">El IMEI1 es obligatorio.</div>';
    } else {
        // Verificar que no exista el IMEI
        $stmt = $conn->prepare("SELECT COUNT(*) FROM productos WHERE imei1=? OR imei2=?");
        $stmt->bind_param("ss", $imei1, $imei1);
        $stmt->execute();
        $stmt->bind_result($existe);
        $stmt->fetch();
        $stmt->close();

        if ($existe > 0) {
            $mensaje = '<div class="alert alert-warning">Este IMEI ya existe en el sistema.</div>';
        } else {
            // Insertar producto
            $stmt = $conn->prepare("
                INSERT INTO productos (marca, modelo, color, imei1, imei2, costo, precio_lista, tipo_producto) 
                VALUES (?,?,?,?,?,?,?,?)
            ");
            $stmt->bind_param("ssssssss", $marca, $modelo, $color, $imei1, $imei2, $costo, $precio_lista, $tipo_producto);
            $stmt->execute();
            $idProducto = $stmt->insert_id;
            $stmt->close();

            // Insertar en inventario de Eulalia
            $stmt = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, estatus) VALUES (?, ?, 'Disponible')");
            $stmt->bind_param("ii", $idProducto, $idEulalia);
            $stmt->execute();
            $stmt->close();

            $mensaje = '<div class="alert alert-success">Producto agregado correctamente al inventario de Eulalia.</div>';
        }
    }
}

// 🔹 Consultar inventario actual de Eulalia
$sql = "
SELECT i.id, p.marca, p.modelo, p.color, p.imei1, p.imei2, p.costo, p.precio_lista, p.tipo_producto, i.estatus, i.fecha_ingreso
FROM inventario i
INNER JOIN productos p ON p.id = i.id_producto
WHERE i.id_sucursal=? 
ORDER BY i.fecha_ingreso DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idEulalia);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario Eulalia</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📦 Inventario - Almacén Eulalia</h2>
    <?= $mensaje ?>

    <!-- Formulario para agregar producto -->
    <div class="card mb-4 shadow">
        <div class="card-header bg-dark text-white">Agregar producto nuevo</div>
        <div class="card-body">
            <form method="POST">
                <div class="row mb-2">
                    <div class="col-md-2">
                        <input type="text" name="marca" class="form-control" placeholder="Marca" required>
                    </div>
                    <div class="col-md-2">
                        <input type="text" name="modelo" class="form-control" placeholder="Modelo" required>
                    </div>
                    <div class="col-md-2">
                        <input type="text" name="color" class="form-control" placeholder="Color" required>
                    </div>
                    <div class="col-md-2">
                        <input type="text" name="imei1" class="form-control" placeholder="IMEI 1" required>
                    </div>
                    <div class="col-md-2">
                        <input type="text" name="imei2" class="form-control" placeholder="IMEI 2 (opcional)">
                    </div>
                    <div class="col-md-2">
                        <select name="tipo_producto" class="form-select" required>
                            <option value="Equipo">Equipo</option>
                            <option value="Modem">Módem</option>
                            <option value="Accesorio">Accesorio</option>
                        </select>
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2">
                        <input type="number" step="0.01" name="costo" class="form-control" placeholder="Costo" required>
                    </div>
                    <div class="col-md-2">
                        <input type="number" step="0.01" name="precio_lista" class="form-control" placeholder="Precio" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-success">Agregar Producto</button>
            </form>
        </div>
    </div>

    <!-- Tabla de inventario -->
    <div class="card shadow">
        <div class="card-header bg-primary text-white">Inventario actual en Eulalia</div>
        <div class="card-body">
            <table class="table table-striped table-bordered table-sm">
                <thead class="table-dark">
                    <tr>
                        <th>ID Inv</th>
                        <th>Marca</th>
                        <th>Modelo</th>
                        <th>Color</th>
                        <th>IMEI1</th>
                        <th>IMEI2</th>
                        <th>Tipo</th>
                        <th>Costo</th>
                        <th>Precio Lista</th>
                        <th>Estatus</th>
                        <th>Fecha Ingreso</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td><?= $row['marca'] ?></td>
                            <td><?= $row['modelo'] ?></td>
                            <td><?= $row['color'] ?></td>
                            <td><?= $row['imei1'] ?></td>
                            <td><?= $row['imei2'] ?: '-' ?></td>
                            <td><?= $row['tipo_producto'] ?></td>
                            <td>$<?= number_format($row['costo'],2) ?></td>
                            <td>$<?= number_format($row['precio_lista'],2) ?></td>
                            <td><?= $row['estatus'] ?></td>
                            <td><?= $row['fecha_ingreso'] ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
