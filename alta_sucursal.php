<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $zona = $_POST['zona'];
    $tipo_sucursal = $_POST['tipo_sucursal'];
    $cuota_semanal = (float)$_POST['cuota_semanal'];

    if ($nombre && $zona && $tipo_sucursal) {
        // Si es almacén, la cuota semanal será 0
        if ($tipo_sucursal == 'Almacen') {
            $cuota_semanal = 0;
        }

        $stmt = $conn->prepare("INSERT INTO sucursales (nombre, zona, cuota_semanal, tipo_sucursal) VALUES (?,?,?,?)");
        $stmt->bind_param("ssds", $nombre, $zona, $cuota_semanal, $tipo_sucursal);
        $stmt->execute();
        $stmt->close();

        $mensaje = "<div class='alert alert-success'>✅ Sucursal <b>$nombre</b> registrada correctamente.</div>";
    } else {
        $mensaje = "<div class='alert alert-danger'>❌ Debes completar todos los campos.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Alta de Sucursales</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>🏢 Alta de Sucursales</h2>
    <?= $mensaje ?>

    <form method="POST" class="card p-4 shadow-sm bg-white">
        <div class="mb-3">
            <label class="form-label">Nombre de la Sucursal</label>
            <input type="text" name="nombre" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Zona</label>
            <select name="zona" class="form-select" required>
                <option value="">-- Selecciona Zona --</option>
                <option value="Zona 1">Zona 1</option>
                <option value="Zona 2">Zona 2</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Tipo de Sucursal</label>
            <select name="tipo_sucursal" class="form-select" required>
                <option value="">-- Selecciona Tipo --</option>
                <option value="Tienda">Tienda</option>
                <option value="Almacen">Almacén</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Cuota Semanal ($)</label>
            <input type="number" name="cuota_semanal" class="form-control" value="0" min="0" step="0.01">
            <small class="text-muted">Para almacenes, dejar en 0.</small>
        </div>

        <button type="submit" class="btn btn-primary">Registrar Sucursal</button>
    </form>
</div>

</body>
</html>

