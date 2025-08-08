<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

// Mostrar errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db.php';

$msg = '';
$previewData = [];

// ============================================
// 🔹 Paso 1: Vista previa del CSV
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'preview' && isset($_FILES['archivo'])) {
    $archivoTmp = $_FILES['archivo']['tmp_name'];
    if (($handle = fopen($archivoTmp, 'r')) !== FALSE) {
        $fila = 0;
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $fila++;
            if ($fila == 1) continue; // Saltar encabezado

            // Esperamos columnas: Marca,Modelo,Color,Capacidad,IMEI1,IMEI2,Costo,Precio_Lista,Tipo_Producto,Fecha_Ingreso,Sucursal
            list($marca, $modelo, $color, $capacidad, $imei1, $imei2, $costo, $precio_lista, $tipo_producto, $fecha_ingreso, $sucursal_nombre) = array_pad($data, 11, '');

            $marca = trim($marca);
            $modelo = trim($modelo);
            $color = trim($color);
            $capacidad = trim($capacidad);
            $imei1 = trim($imei1);
            $imei2 = trim($imei2);
            $costo = (float)$costo;
            $precio_lista = (float)$precio_lista;
            $tipo_producto = ucfirst(strtolower(trim($tipo_producto))) ?: 'Equipo';
            $fecha_ingreso = $fecha_ingreso ?: date('Y-m-d');
            $sucursal_nombre = trim($sucursal_nombre);

            // Validar tipo permitido
            $tipos_validos = ['Equipo','Modem','Módem','Accesorio'];
            if (!in_array($tipo_producto, $tipos_validos)) {
                $tipo_producto = 'Equipo';
            }

            // Buscar ID de sucursal
            $idSucursal = null;
            if ($sucursal_nombre) {
                $stmtSuc = $conn->prepare("SELECT id FROM sucursales WHERE nombre=? LIMIT 1");
                $stmtSuc->bind_param("s", $sucursal_nombre);
                $stmtSuc->execute();
                $resSuc = $stmtSuc->get_result()->fetch_assoc();
                $idSucursal = $resSuc['id'] ?? null;
                $stmtSuc->close();
            }

            $estatus = 'OK';
            $motivo = 'Listo para insertar';

            // Validar sucursal
            if (!$idSucursal) {
                $estatus = 'Ignorada';
                $motivo = 'Sucursal no encontrada';
            }

            // Validar IMEI obligatorio
            if (!$imei1) {
                $estatus = 'Ignorada';
                $motivo = 'IMEI1 vacío';
            } else {
                // Validar duplicado en base
                $stmt = $conn->prepare("SELECT id FROM productos WHERE imei1=? OR imei2=? OR imei1=? OR imei2=? LIMIT 1");
                $stmt->bind_param("ssss", $imei1, $imei1, $imei2, $imei2);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $estatus = 'Ignorada';
                    $motivo = 'Duplicado en base';
                }
                $stmt->close();
            }

            $previewData[] = [
                'marca' => $marca,
                'modelo' => $modelo,
                'color' => $color,
                'capacidad' => $capacidad,
                'imei1' => $imei1,
                'imei2' => $imei2,
                'costo' => $costo,
                'precio_lista' => $precio_lista,
                'tipo_producto' => $tipo_producto,
                'fecha_ingreso' => $fecha_ingreso,
                'sucursal' => $sucursal_nombre,
                'id_sucursal' => $idSucursal,
                'estatus' => $estatus,
                'motivo' => $motivo
            ];
        }
        fclose($handle);
    } else {
        $msg = "❌ Error al abrir el archivo CSV.";
    }
}

// ============================================
// 🔹 Paso 2: Confirmar e insertar
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'insertar' && isset($_POST['data'])) {
    $data = json_decode(base64_decode($_POST['data']), true);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_carga_productos.csv"');
    $output = fopen('php://output', 'w');

    // Encabezado CSV
    fputcsv($output, ['marca','modelo','color','capacidad','imei1','imei2','sucursal','estatus_final','motivo']);

    foreach ($data as $prod) {
        $estatusFinal = $prod['estatus'];
        $motivo = $prod['motivo'];

        if ($prod['estatus'] == 'OK') {
            $sqlInsert = "INSERT INTO productos (marca, modelo, color, capacidad, imei1, imei2, costo, precio_lista, tipo_producto)
                          VALUES (?,?,?,?,?,?,?,?,?)";
            $stmt = $conn->prepare($sqlInsert);
            $stmt->bind_param(
                "ssssssdds",
                $prod['marca'], $prod['modelo'], $prod['color'], $prod['capacidad'],
                $prod['imei1'], $prod['imei2'], $prod['costo'], $prod['precio_lista'], $prod['tipo_producto']
            );

            if ($stmt->execute()) {
                $idProducto = $stmt->insert_id;

                // Insertar en inventario
                $stmtInv = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, estatus, fecha_ingreso) VALUES (?, ?, 'Disponible', ?)");
                $stmtInv->bind_param("iis", $idProducto, $prod['id_sucursal'], $prod['fecha_ingreso']);
                $stmtInv->execute();
                $stmtInv->close();

                $estatusFinal = 'Insertada';
                $motivo = 'OK';
            } else {
                $estatusFinal = 'Ignorada';
                $motivo = 'Error en inserción';
            }
            $stmt->close();
        }

        // Escribir en CSV
        fputcsv($output, [
            $prod['marca'],
            $prod['modelo'],
            $prod['color'],
            $prod['capacidad'],
            $prod['imei1'],
            $prod['imei2'],
            $prod['sucursal'],
            $estatusFinal,
            $motivo
        ]);
    }

    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Carga Masiva de Productos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📥 Carga Masiva de Productos al Inventario</h2>
    <p>Sube un archivo <strong>CSV</strong> con columnas:</p>
    <pre>Marca, Modelo, Color, Capacidad, IMEI1, IMEI2, Costo, Precio_Lista, Tipo_Producto, Fecha_Ingreso(YYYY-MM-DD), Sucursal</pre>

    <?php if($msg) echo "<div class='alert alert-info'>$msg</div>"; ?>

    <?php if(empty($previewData) && (!isset($_POST['action']) || $_POST['action'] != 'insertar')): ?>
        <div class="card shadow mb-4">
            <div class="card-header bg-dark text-white">Subir archivo CSV</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="preview">
                    <input type="file" name="archivo" accept=".csv" class="form-control mb-3" required>
                    <button type="submit" class="btn btn-primary">👀 Vista Previa</button>
                </form>
            </div>
        </div>
    <?php elseif(!empty($previewData) && $_POST['action'] == 'preview'): ?>
        <div class="card shadow p-3 mb-4 bg-white">
            <h5>Vista Previa</h5>
            <form method="POST">
                <input type="hidden" name="action" value="insertar">
                <input type="hidden" name="data" value='<?= base64_encode(json_encode($previewData)) ?>'>
                <table class="table table-bordered table-sm mt-3">
                    <thead class="table-light">
                        <tr>
                            <th>Marca</th><th>Modelo</th><th>Color</th><th>Capacidad</th><th>IMEI1</th><th>IMEI2</th><th>Fecha Ingreso</th><th>Sucursal</th><th>Estatus</th><th>Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($previewData as $p): ?>
                        <tr class="<?= $p['estatus']=='OK'?'':'table-warning' ?>">
                            <td><?= htmlspecialchars($p['marca']) ?></td>
                            <td><?= htmlspecialchars($p['modelo']) ?></td>
                            <td><?= htmlspecialchars($p['color']) ?></td>
                            <td><?= htmlspecialchars($p['capacidad']) ?></td>
                            <td><?= htmlspecialchars($p['imei1']) ?></td>
                            <td><?= htmlspecialchars($p['imei2']) ?></td>
                            <td><?= htmlspecialchars($p['fecha_ingreso']) ?></td>
                            <td><?= htmlspecialchars($p['sucursal']) ?></td>
                            <td><?= $p['estatus'] ?></td>
                            <td><?= $p['motivo'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn btn-success mt-3">✅ Confirmar y Descargar CSV Resumen</button>
            </form>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
