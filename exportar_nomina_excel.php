<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

/* ========================
   Funciones auxiliares
======================== */
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=Lunes ... 7=Domingo
    $dif = $diaSemana - 2; // Martes=2
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
   Configuración CSV
======================== */
$filename = "nomina_semana_" . $semanaSeleccionada . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
$output = fopen('php://output', 'w');

fputcsv($output, [
    'Empleado', 'Rol', 'Sucursal', 'Sueldo Base',
    'Com. Equipos', 'Com. SIMs', 'Com. Pospago',
    'Com. Gerente', 'Total a Pagar'
]);

$totalGlobal = 0;

/* ========================
   Consulta de usuarios
   (Excluye sucursales tipo Almacén)
======================== */
$sqlUsuarios = "
    SELECT u.id, u.nombre, u.rol, u.sueldo, s.nombre AS sucursal, u.id_sucursal
    FROM usuarios u
    INNER JOIN sucursales s ON s.id=u.id_sucursal
    WHERE s.tipo_sucursal <> 'Almacen'
    ORDER BY s.nombre, FIELD(u.rol,'Gerente','Ejecutivo'), u.nombre
";
$resUsuarios = $conn->query($sqlUsuarios);

while ($u = $resUsuarios->fetch_assoc()) {
    $id_usuario = $u['id'];
    $id_sucursal = $u['id_sucursal'];
    $rol = $u['rol'];

    /* ========================
       1️⃣ Comisiones de equipos (ejecutivo)
    ======================== */
    $sqlEquipos = "
        SELECT 
            SUM(dv.comision_regular) AS com_reg,
            SUM(dv.comision_especial) AS com_esp
        FROM detalle_venta dv
        INNER JOIN ventas v ON dv.id_venta=v.id
        INNER JOIN productos p ON dv.id_producto=p.id
        WHERE v.id_usuario=? 
          AND v.fecha_venta BETWEEN ? AND ?
          AND LOWER(p.tipo_producto) NOT IN ('sim','chip','pospago')
    ";
    $stmtEquip = $conn->prepare($sqlEquipos);
    $stmtEquip->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtEquip->execute();
    $rowEquipos = $stmtEquip->get_result()->fetch_assoc();
    $com_equipos = (float)($rowEquipos['com_reg'] ?? 0) + (float)($rowEquipos['com_esp'] ?? 0);

    /* ========================
       2️⃣ Comisiones de SIMs
    ======================== */
    $com_sims = 0;
    if ($rol != 'Gerente') {
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
        $com_sims = (float)($stmtSims->get_result()->fetch_assoc()['com_sims'] ?? 0);
    }

    /* ========================
       3️⃣ Comisiones de Pospago
    ======================== */
    $com_pospago = 0;
    if ($rol != 'Gerente') {
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
        $com_pospago = (float)($stmtPos->get_result()->fetch_assoc()['com_pos'] ?? 0);
    }

    /* ========================
       4️⃣ Comisión de Gerente
    ======================== */
    $com_ger = 0;
    if ($rol == 'Gerente') {
        // Obtener cuota dinámica de la semana
        $sqlCuota = "
            SELECT cuota_monto 
            FROM cuotas_sucursales
            WHERE id_sucursal=? 
              AND fecha_inicio <= ? 
            ORDER BY fecha_inicio DESC 
            LIMIT 1
        ";
        $stmtC = $conn->prepare($sqlCuota);
        $stmtC->bind_param("is", $id_sucursal, $inicioSemana);
        $stmtC->execute();
        $cuota_monto = (float)($stmtC->get_result()->fetch_assoc()['cuota_monto'] ?? 0);

        // Ventas de la sucursal (excluye SIMs y Pospago)
        $sqlVentasSuc = "
            SELECT COUNT(*) AS unidades, SUM(dv.precio_unitario) AS monto
            FROM detalle_venta dv
            INNER JOIN ventas v ON dv.id_venta=v.id
            INNER JOIN productos p ON dv.id_producto=p.id
            WHERE v.id_sucursal=? 
              AND v.fecha_venta BETWEEN ? AND ?
              AND LOWER(p.tipo_producto) NOT IN ('sim','chip','pospago')
        ";
        $stmtVS = $conn->prepare($sqlVentasSuc);
        $stmtVS->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtVS->execute();
        $rowSuc = $stmtVS->get_result()->fetch_assoc();

        $unidSuc = (int)($rowSuc['unidades'] ?? 0);
        $montoSuc = (float)($rowSuc['monto'] ?? 0);

        $cumpleCuotaGerente = $montoSuc >= $cuota_monto;
        $com_ger = $cumpleCuotaGerente ? ($unidSuc * 50) : ($unidSuc * 25);
    }

    /* ========================
       5️⃣ Total empleado
    ======================== */
    $total = $u['sueldo'] + $com_equipos + $com_sims + $com_pospago + $com_ger;
    $totalGlobal += $total;

    fputcsv($output, [
        $u['nombre'],
        $rol,
        $u['sucursal'],
        number_format($u['sueldo'],2,'.',''),
        number_format($com_equipos,2,'.',''),
        number_format($com_sims,2,'.',''),
        number_format($com_pospago,2,'.',''),
        number_format($com_ger,2,'.',''),
        number_format($total,2,'.','')
    ]);
}

// Fila de total global
fputcsv($output, ['', '', '', '', '', '', '', 'Total Global', number_format($totalGlobal,2,'.','')]);
fclose($output);
exit();
?>
