<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';

// Reset contraseña
if (isset($_POST['reset_password'])) {
    $idUsuario = (int)$_POST['id_usuario'];
    $newPass = password_hash('123456', PASSWORD_DEFAULT); // Contraseña default
    $stmt = $conn->prepare("UPDATE usuarios SET password=? WHERE id=?");
    $stmt->bind_param("si", $newPass, $idUsuario);
    $stmt->execute();
    $stmt->close();
    $msg = "Contraseña reseteada a 123456 para el usuario ID $idUsuario";
}

// Actualizar rol o sucursal
if (isset($_POST['update_user'])) {
    $idUsuario = (int)$_POST['id_usuario'];
    $rol = $_POST['rol'];
    $idSucursal = (int)$_POST['id_sucursal'];
    $activo = isset($_POST['activo']) ? 1 : 0;

    $stmt = $conn->prepare("UPDATE usuarios SET rol=?, id_sucursal=?, activo=? WHERE id=?");
    $stmt->bind_param("siii", $rol, $idSucursal, $activo, $idUsuario);
    $stmt->execute();
    $stmt->close();
    $msg = "Usuario ID $idUsuario actualizado correctamente.";
}

// Obtener usuarios
$sql = "SELECT u.*, s.nombre AS sucursal 
        FROM usuarios u
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        ORDER BY u.id ASC";
$usuarios = $conn->query($sql);

// Obtener sucursales para selector
$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre ASC");
$sucursalesArray = [];
while ($s = $sucursales->fetch_assoc()) {
    $sucursalesArray[$s['id']] = $s['nombre'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>👥 Gestión de Usuarios</h2>
    <?php if(!empty($msg)): ?>
        <div class="alert alert-info"><?= $msg ?></div>
    <?php endif; ?>

    <table class="table table-bordered table-striped">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Usuario</th>
                <th>Sucursal</th>
                <th>Rol</th>
                <th>Activo</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($u = $usuarios->fetch_assoc()): ?>
                <tr>
                    <form method="POST">
                        <td><?= $u['id'] ?></td>
                        <td><?= $u['nombre'] ?></td>
                        <td><?= $u['usuario'] ?></td>
                        <td>
                            <select name="id_sucursal" class="form-select form-select-sm">
                                <?php foreach ($sucursalesArray as $id => $nombre): ?>
                                    <option value="<?= $id ?>" <?= $id==$u['id_sucursal']?'selected':'' ?>>
                                        <?= $nombre ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="rol" class="form-select form-select-sm">
                                <?php foreach (['Ejecutivo','Gerente','Supervisor','Admin','GerenteZona'] as $rol): ?>
                                    <option value="<?= $rol ?>" <?= $rol==$u['rol']?'selected':'' ?>><?= $rol ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="text-center">
                            <input type="checkbox" name="activo" <?= $u['activo']?'checked':'' ?>>
                        </td>
                        <td>
                            <input type="hidden" name="id_usuario" value="<?= $u['id'] ?>">
                            <button type="submit" name="update_user" class="btn btn-primary btn-sm">💾 Guardar</button>
                            <button type="submit" name="reset_password" class="btn btn-warning btn-sm">🔑 Reset Pass</button>
                        </td>
                    </form>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
</body>
</html>
