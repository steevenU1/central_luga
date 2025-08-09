<?php
session_start();
if(!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin'){
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$mensaje = "";

// 🔹 Procesar formulario de actualización
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $modeloCapacidad = $_POST['modelo'] ?? '';
    $nuevoPrecioLista = floatval($_POST['precio_lista'] ?? 0);
    $nuevoPrecioCombo = floatval($_POST['precio_combo'] ?? 0);

    if($modeloCapacidad){
        list($marca, $modelo, $capacidad) = explode('|', $modeloCapacidad);

        if($nuevoPrecioLista > 0){
            // 🔹 Actualizar productos
            $sql = "
                UPDATE productos p
                INNER JOIN inventario i ON i.id_producto = p.id
                SET p.precio_lista = ?
                WHERE p.marca = ? AND p.modelo = ? AND (p.capacidad = ? OR IFNULL(p.capacidad,'') = ?)
                  AND TRIM(i.estatus) IN ('Disponible','En tránsito')
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("dssss", $nuevoPrecioLista, $marca, $modelo, $capacidad, $capacidad);
            $stmt->execute();
            $afectados = $stmt->affected_rows;
            $mensaje .= "✅ Se actualizó precio de lista a $" . number_format($nuevoPrecioLista,2) . " ($afectados registros).<br>";
        }

        if($nuevoPrecioCombo > 0){
            // 🔹 Insertar o actualizar en precios_combo
            $sql = "
                INSERT INTO precios_combo (marca, modelo, capacidad, precio_combo)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE precio_combo = VALUES(precio_combo)
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssd", $marca, $modelo, $capacidad, $nuevoPrecioCombo);
            $stmt->execute();
            $mensaje .= "✅ Se actualizó precio combo a $" . number_format($nuevoPrecioCombo,2) . ".";
        }

        if ($nuevoPrecioLista <= 0 && $nuevoPrecioCombo <= 0) {
            $mensaje = "⚠️ Debes ingresar al menos un precio válido.";
        }

    } else {
        $mensaje = "⚠️ Selecciona un modelo válido.";
    }
}

// 🔹 Obtener modelos únicos de productos con inventario disponible o en tránsito
$modelos = $conn->query("
    SELECT 
        p.marca, 
        p.modelo, 
        IFNULL(p.capacidad,'') AS capacidad
    FROM productos p
    WHERE p.tipo_producto = 'Equipo'
      AND p.id IN (
            SELECT DISTINCT i.id_producto
            FROM inventario i
            WHERE TRIM(i.estatus) IN ('Disponible','En tránsito')
      )
    GROUP BY p.marca, p.modelo, p.capacidad
    ORDER BY LOWER(p.marca), LOWER(p.modelo), LOWER(p.capacidad)
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Actualizar Precios por Modelo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>💰 Actualizar Precios por Modelo</h2>
    <p>Selecciona un modelo y asigna nuevos precios. Afecta equipos <b>Disponibles</b> o <b>En tránsito</b>.</p>

    <?php if($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <form method="POST" class="card p-3 shadow-sm bg-white" style="max-width:550px;">
        <div class="mb-3">
            <label class="form-label">Modelo y Capacidad</label>
            <select name="modelo" class="form-select" required>
                <option value="">Seleccione un modelo...</option>
                <?php while($m = $modelos->fetch_assoc()): 
                    $valor = $m['marca'].'|'.$m['modelo'].'|'.$m['capacidad'];
                    $texto = trim($m['marca'].' '.$m['modelo'].' '.$m['capacidad']);
                ?>
                <option value="<?= htmlspecialchars($valor) ?>"><?= htmlspecialchars($texto) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Nuevo Precio de Lista ($)</label>
            <input type="number" step="0.01" name="precio_lista" class="form-control" placeholder="Ej. 2500.00">
        </div>

        <div class="mb-3">
            <label class="form-label">Nuevo Precio Combo ($)</label>
            <input type="number" step="0.01" name="precio_combo" class="form-control" placeholder="Ej. 2199.00">
        </div>

        <button class="btn btn-primary">Actualizar Precios</button>
        <a href="lista_precios.php" class="btn btn-secondary">Ver Lista</a>
    </form>
</div>

</body>
</html>
