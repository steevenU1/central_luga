<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';
include 'navbar.php';

/* ========================
   FUNCIONES AUXILIARES
======================== */
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=Lunes ... 7=Domingo
    $dif = $diaSemana - 2; // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0,0,0);

    if ($offset > 0) $inicio->modify("-" . (7*$offset) . " days");

    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23,59,59);

    return [$inicio, $fin];
}

$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d 00:00:00');
$finSemana = $finSemanaObj->format('Y-m-d 23:59:59');

/* ========================
   CONSULTA DE USUARIOS
   (Excluye sucursales tipo Almacén)
======================== */
$sqlUsuarios = "
    SELECT u.id, u.nombre, u.rol, u.sueldo, s.nombre AS sucursal, u.id_sucursal
    FROM usuarios u
    INNER JOIN sucursales s ON s.id=u.id_sucursal
    WHERE s.tipo_sucursal <> 'Almacen'
    ORDER BY s.nombre, u.rol DESC, u.nombre
";
$resUsuarios = $conn->query($sqlUsuarios);

$nomina = [];

while ($u = $resUsuarios->fetch_assoc()) {
    $id_usuario = $u['id'];
    $id_sucursal = $u['id_sucursal'];

    /* ========================
       1️⃣ Comisiones de equipos
       ✅ Ahora suma directo desde ventas.comision
    ======================== */
    $sqlEquipos = "
        SELECT IFNULL(SUM(v.comision),0) AS total_comision
        FROM ventas v
        WHERE v.id_usuario=? 
          AND v.fecha_venta BETWEEN ? AND ?
    ";
    $stmtEquip = $conn->prepare($sqlEquipos);
    $stmtEquip->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtEquip->execute();
    $com_equipos = (float)$stmtEquip->get_result()->fetch_assoc()['total_comision'] ?? 0;

    /* ========================
       2️⃣ Comisiones de SIMs
    ======================== */
    $com_sims = 0;
    if ($u['rol'] != 'Gerente') {
        $sqlSims = "
            SELECT SUM(dvs.precio_unitario * 0.20) AS com_sims
            FROM detalle_venta_sims dvs
            INNER JOIN ventas_sims vs ON dvs.id_venta=vs.id
            WHERE vs.id_usuario=? 
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta IN ('Nueva','Portabilidad','Regalo')
        ";
        $stmtSims = $conn->prepare($sqlSims);
        $stmtSims->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtSims->execute();
        $com_sims = (float)$stmtSims->get_result()->fetch_assoc()['com_sims'] ?? 0;
    }

    /* ========================
       3️⃣ Comisiones de pospago
    ======================== */
    $com_pospago = 0;
    if ($u['rol'] != 'Gerente') {
        $sqlPos = "
            SELECT SUM(dvs.precio_unitario * 0.20) AS com_pos
            FROM detalle_venta_sims dvs
            INNER JOIN ventas_sims vs ON dvs.id_venta=vs.id
            WHERE vs.id_usuario=? 
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta='Pospago'
        ";
        $stmtPos = $conn->prepare($sqlPos);
        $stmtPos->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtPos->execute();
        $com_pospago = (float)$stmtPos->get_result()->fetch_assoc()['com_pos'] ?? 0;
    }

    /* ========================
       4️⃣ Comisión de Gerente
       ✅ Se deja como estaba, porque viene del recalculo
    ======================== */
    $com_ger = 0;
    if ($u['rol'] == 'Gerente') {
        $sqlComGer = "
            SELECT IFNULL(SUM(v.comision_gerente),0) AS com_ger
            FROM ventas v
            WHERE v.id_sucursal=? 
              AND v.fecha_venta BETWEEN ? AND ?
        ";
        $stmtGer = $conn->prepare($sqlComGer);
        $stmtGer->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtGer->execute();
        $com_ger = (float)$stmtGer->get_result()->fetch_assoc()['com_ger'] ?? 0;
    }

    /* ========================
       5️⃣ Total ejecutivo
    ======================== */
    $total_ejecutivo = $u['sueldo'] + $com_equipos + $com_sims + $com_pospago;
    $total = $total_ejecutivo + $com_ger;

    $nomina[] = [
        'nombre' => $u['nombre'],
        'rol' => $u['rol'],
        'sucursal' => $u['sucursal'],
        'sueldo' => $u['sueldo'],
        'com_equipos' => $com_equipos,
        'com_sims' => $com_sims,
        'com_pospago' => $com_pospago,
        'com_ger' => $com_ger,
        'total' => $total
    ];
}

/* ========================
   VISTA HTML
======================== */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Nómina Semanal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>📋 Reporte de Nómina Semanal</h2>

    <form method="GET" class="mb-3 d-inline-block">
        <label><strong>Selecciona semana:</strong></label>
        <select name="semana" class="form-select w-auto d-inline-block" onchange="this.form.submit()">
            <?php for ($i=0; $i<8; $i++):
                list($ini, $fin) = obtenerSemanaPorIndice($i);
                $texto = "Semana del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
            ?>
            <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $texto ?></option>
            <?php endfor; ?>
        </select>
    </form>

    <a href="recalculo_total_comisiones.php?semana=<?= $semanaSeleccionada ?>" 
       class="btn btn-warning ms-2"
       onclick="return confirm('¿Seguro que deseas recalcular las comisiones de esta semana?');">
       🔄 Recalcular Comisiones
    </a>

    <a href="exportar_nomina_excel.php?semana=<?= $semanaSeleccionada ?>" 
       class="btn btn-success ms-2">
       📥 Exportar a Excel
    </a>

    <table class="table table-striped table-bordered mt-3">
        <thead class="table-dark">
            <tr>
                <th>Empleado</th>
                <th>Rol</th>
                <th>Sucursal</th>
                <th>Sueldo Base</th>
                <th>Com. Equipos</th>
                <th>Com. SIMs</th>
                <th>Com. Pospago</th>
                <th>Com. Gerente</th>
                <th>Total a Pagar</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $totalGlobal = 0;
            foreach ($nomina as $n): 
                $totalGlobal += $n['total'];
            ?>
            <tr>
                <td><?= $n['nombre'] ?></td>
                <td><?= $n['rol'] ?></td>
                <td><?= $n['sucursal'] ?></td>
                <td>$<?= number_format($n['sueldo'],2) ?></td>
                <td>$<?= number_format($n['com_equipos'],2) ?></td>
                <td>$<?= number_format($n['com_sims'],2) ?></td>
                <td>$<?= number_format($n['com_pospago'],2) ?></td>
                <td>$<?= number_format($n['com_ger'],2) ?></td>
                <td><strong>$<?= number_format($n['total'],2) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="table-secondary">
            <tr>
                <td colspan="8" class="text-end"><strong>Total Global</strong></td>
                <td><strong>$<?= number_format($totalGlobal,2) ?></strong></td>
            </tr>
        </tfoot>
    </table>
</div>

</body>
</html>
