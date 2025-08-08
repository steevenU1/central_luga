<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
$mensaje = '';

// Obtener sucursales
$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $usuario = trim($_POST['usuario']);
    $password = trim($_POST['password']);
    $id_sucursal = (int)$_POST['id_sucursal'];
    $rol = $_POST['rol'];

    if ($nombre && $usuario && $password && $id_sucursal && $rol) {
        // Verificar si el usuario ya existe
        $stmt = $conn->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario=?");
        $stmt->bind_param("s", $usuario);
        $stmt->execute();
        $stmt->bind_result($existe);
        $stmt->fetch();
        $stmt->close();

        if ($existe > 0) {
            $mensaje = "<div class='alert alert-danger'>❌ El usuario <b>$usuario</b> ya existe.</div>";
        } else {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $conn->prepare("
                INSERT INTO usuarios (nombre, usuario, password, id_sucursal, rol)
                VALUES (?,?,?,?,?)
            ");
            $stmt->bind_param("sssds", $nombre, $usuario, $passwordHash, $id_sucursal, $rol);
            $stmt->execute();
            $stmt->close();

            $mensaje = "<div class='alert alert-success'>✅ Usuario <b>$usuario</b> registrado correctamente.</div>";
        }
    } else {
        $mensaje = "<div class='alert alert-danger'>❌ Todos los campos son obligatorios.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Alta de Usuarios</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>👤 Alta de Usuarios</h2>
    <?= $mensaje ?>

    <form method="POST" class="card p-4 shadow-sm bg-white">
        <div class="mb-3">
            <label class="form-label">Nombre Completo</label>
            <input type="text" name="nombre" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Usuario</label>
            <input type="text" name="usuario" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Contraseña</label>
            <input type="password" name="password" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Sucursal</label>
            <select name="id_sucursal" class="form-select" required>
                <option value="">-- Selecciona sucursal --</option>
                <?php while ($s = $sucursales->fetch_assoc()): ?>
                    <option value="<?= $s['id'] ?>"><?= $s['nombre'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Rol</label>
            <select name="rol" class="form-select" required>
                <option value="">-- Selecciona rol --</option>
                <option value="Ejecutivo">Ejecutivo</option>
                <option value="Gerente">Gerente</option>
                <option value="Supervisor">Supervisor</option>
                <option value="GerenteZona">Gerente de Zona</option>
                <option value="Admin">Administrador</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">Registrar Usuario</button>
    </form>
</div>

</body>
</html>
