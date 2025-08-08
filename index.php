<?php
session_start();
include 'db.php';

$mensaje = '';

// Mostrar mensaje si el usuario fue dado de baja (redirigido desde verificar_sesion.php)
if (isset($_GET['error']) && $_GET['error'] === 'baja') {
    $mensaje = "⚠️ Tu cuenta ha sido dada de baja. Contacta al administrador.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = $_POST['usuario'] ?? '';
    $password = $_POST['password'] ?? '';

    // Consulta usuario
    $sql = "SELECT * FROM usuarios WHERE usuario = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $usuario);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Validar si el usuario está activo (activo = 1)
        if ((int)$row['activo'] !== 1) {
            $mensaje = "⚠️ Tu cuenta ha sido dada de baja.";
        } elseif ($password === $row['password']) {
            // Login correcto
            $_SESSION['id_usuario'] = $row['id'];
            $_SESSION['nombre'] = $row['nombre'];
            $_SESSION['id_sucursal'] = $row['id_sucursal'];
            $_SESSION['rol'] = $row['rol'];
            header("Location: panel.php");
            exit();
        } else {
            $mensaje = "❌ Contraseña incorrecta";
        }
    } else {
        $mensaje = "❌ Usuario no encontrado";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login - Sistema de Ventas</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card shadow">
                <div class="card-body">
                    <h3 class="text-center mb-4">Iniciar Sesión</h3>
                    <?php if ($mensaje): ?>
                        <div class="alert alert-danger text-center"><?= $mensaje ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label>Usuario</label>
                            <input type="text" name="usuario" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Contraseña</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button class="btn btn-primary w-100">Ingresar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
