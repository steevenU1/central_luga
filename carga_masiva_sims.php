<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';

// 🔹 ID de sucursal Eulalia (almacén)
$idEulalia = 0;
$resEulalia = $conn->query("SELECT id FROM sucursales WHERE nombre='Eulalia' LIMIT 1");
if ($resEulalia && $rowE = $resEulalia->fetch_assoc()) {
    $idEulalia = $rowE['id'];
}

$msg = '';
$previewData = [];

// =====================================================
// 🔹 Paso 1: Subida de CSV para Preview
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'preview' && isset($_FILES['archivo_csv'])) {
    $archivoTmp = $_FILES['archivo_csv']['tmp_name'];
    $handle = fopen($archivoTmp, 'r');

    if ($handle) {
        $fila = 0;
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $fila++;
            if ($fila == 1) continue; // Saltar encabezado

            $iccid = trim($data[0] ?? '');
            $dn = trim($data[1] ?? '');
            $caja = trim($data[2] ?? '');
            $nombre_sucursal = trim($data[3] ?? '');

            // Sucursal por defecto: Eulalia
            if ($nombre_sucursal == '') {
                $id_sucursal = $idEulalia;
                $nombre_sucursal = 'Eulalia (por defecto)';
            } else {
                $stmtSucursal = $conn->prepare("SELECT id FROM sucursales WHERE nombre=? LIMIT 1");
                $stmtSucursal->bind_param("s", $nombre_sucursal);
                $stmtSucursal->execute();
                $id_sucursal = $stmtSucursal->get_result()->fetch_assoc()['id'] ?? 0;
            }

            // Validación inicial
            $estatus = 'OK';
            $motivo = 'Listo para insertar';

            if (!$iccid) {
                $estatus = 'Ignorada';
                $motivo = 'ICCID vacío';
            } elseif ($id_sucursal == 0) {
                $estatus = 'Ignorada';
                $motivo = 'Sucursal no encontrada';
            } else {
                // Validar duplicado en base
                $stmtDup = $conn->prepare("SELECT id FROM inventario_sims WHERE iccid=?");
                $stmtDup->bind_param("s", $iccid);
                $stmtDup->execute();
                $stmtDup->store_result();
                if ($stmtDup->num_rows > 0) {
                    $estatus = 'Ignorada';
                    $motivo = 'Duplicado en base';
                }
            }

            // Guardar fila en preview
            $previewData[] = [
                'iccid' => $iccid,
                'dn' => $dn,
                'caja' => $caja,
                'sucursal' => $nombre_sucursal,
                'id_sucursal' => $id_sucursal,
                'estatus' => $estatus,
                'motivo' => $motivo
            ];
        }
        fclose($handle);
    } else {
        $msg = "❌ Error al abrir el archivo CSV.";
    }
}

// =====================================================
// 🔹 Paso 2: Confirmar e insertar + generar CSV resumen
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'insertar' && isset($_POST['data'])) {
    $data = json_decode($_POST['data'], true);

    // Crear CSV resumen
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_carga_sims.csv"');
    $output = fopen('php://output', 'w');

    // Encabezado del CSV
    fputcsv($output, ['iccid', 'dn', 'caja', 'sucursal', 'estatus_final', 'motivo']);

    $insertadas = 0;
    foreach ($data as $sim) {
        $estatusFinal = $sim['estatus'];
        $motivo = $sim['motivo'];

        // Insertar solo las que estén OK
        if ($sim['estatus'] == 'OK') {
            $iccid = $sim['iccid'];
            $dn = $sim['dn'];
            $caja = $sim['caja'];
            $id_sucursal = $sim['id_sucursal'];
            $estatus = 'Disponible';

            $sqlInsert = "INSERT INTO inventario_sims 
                          (iccid, dn, caja_id, id_sucursal, estatus, fecha_ingreso) 
                          VALUES (?, ?, ?, ?, ?, NOW())";

            $stmtInsert = $conn->prepare($sqlInsert);
            $stmtInsert->bind_param("sssis", $iccid, $dn, $caja, $id_sucursal, $estatus);

            if ($stmtInsert->execute()) {
                $insertadas++;
                $estatusFinal = 'Insertada';
                $motivo = 'OK';
            } else {
                $estatusFinal = 'Ignorada';
                $motivo = 'Error en inserción';
            }
        }

        // Escribir fila en el CSV
        fputcsv($output, [
            $sim['iccid'],
            $sim['dn'],
            $sim['caja'],
            $sim['sucursal'],
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
    <title>Carga Masiva de SIMs</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>Carga Masiva de SIMs</h2>
    <a href="dashboard_unificado.php" class="btn btn-secondary mb-3">← Volver al Dashboard</a>

    <?php if ($msg): ?>
        <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if (empty($previewData) && (!isset($_POST['action']) || $_POST['action'] != 'insertar')): ?>
        <!-- Paso 1: Subir CSV -->
        <div class="card p-4 shadow-sm bg-white">
            <h5>Subir Archivo CSV</h5>
            <p>Columnas requeridas: <b>iccid, dn, caja_id, sucursal</b>  
            <br>Si <b>sucursal</b> está vacía, se asigna automáticamente a <b>Eulalia</b>.</p>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="preview">
                <div class="mb-3">
                    <input type="file" name="archivo_csv" class="form-control" accept=".csv" required>
                </div>
                <button type="submit" class="btn btn-primary">👀 Vista Previa</button>
            </form>
        </div>

    <?php elseif (!empty($previewData) && $_POST['action'] == 'preview'): ?>
        <!-- Paso 2: Vista previa -->
        <div class="card p-4 shadow-sm bg-white">
            <h5>Vista Previa de Carga</h5>
            <form method="POST">
                <input type="hidden" name="action" value="insertar">
                <input type="hidden" name="data" value='<?= json_encode($previewData) ?>'>
                <table class="table table-bordered table-sm mt-3">
                    <thead class="table-light">
                        <tr>
                            <th>ICCID</th>
                            <th>DN</th>
                            <th>Caja</th>
                            <th>Sucursal</th>
                            <th>Estatus</th>
                            <th>Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($previewData as $sim): ?>
                        <tr class="<?= $sim['estatus']=='OK'?'':'table-warning' ?>">
                            <td><?= htmlspecialchars($sim['iccid']) ?></td>
                            <td><?= htmlspecialchars($sim['dn']) ?></td>
                            <td><?= htmlspecialchars($sim['caja']) ?></td>
                            <td><?= htmlspecialchars($sim['sucursal']) ?></td>
                            <td><?= $sim['estatus'] ?></td>
                            <td><?= $sim['motivo'] ?></td>
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
