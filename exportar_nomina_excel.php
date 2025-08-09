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

/* ========================
   Encabezado sección RESUMEN
======================== */
fputcsv($output, ['REPORTE DE NÓMINA SEMANAL']);
fputcsv($output, ['Semana', $inicioSemanaObj->format('d/m/Y') . ' - ' . $finSemanaObj->format('d/m/Y')]);
fputcsv($output, []); // línea en blanco

// Encabezados resumen por empleado (incluye conteos)
fputcsv($output, [
    'Empleado', 'Rol', 'Sucursal',
    'Sueldo Base',
    '# Equipos', 'Com. Equipos',
    '# SIMs', 'Com. SIMs',
    '# Pospago', 'Com. Pospago',
    'Com. Gerente',
    'Total a Pagar'
]);

$totalGlobal = 0;

/* ========================
   Consulta de usuarios (excluye almacén)
======================== */
$sqlUsuarios = "
    SELECT u.id, u.nombre, u.rol, u.sueldo, s.nombre AS sucursal, u.id_sucursal
    FROM usuarios u
    INNER JOIN sucursales s ON s.id=u.id_sucursal
    WHERE s.tipo_sucursal <> 'Almacen'
    ORDER BY s.nombre, FIELD(u.rol,'Gerente','Ejecutivo'), u.nombre
";
$resUsuarios = $conn->query($sqlUsuarios);

/* Acumulador para luego escribir el detalle de cada persona */
$detalleFilas = []; // array de arrays: cada elemento es una fila para el “Detalle por venta”

while ($u = $resUsuarios->fetch_assoc()) {
    $id_usuario   = (int)$u['id'];
    $id_sucursal  = (int)$u['id_sucursal'];
    $rol          = $u['rol'];

    /* ========================
       1) Equipos - sumas y conteos
    ======================== */
    $sqlEquiposTot = "
        SELECT 
            COUNT(dv.id) AS cnt,
            SUM(dv.comision_regular) AS com_reg,
            SUM(dv.comision_especial) AS com_esp
        FROM detalle_venta dv
        INNER JOIN ventas v   ON dv.id_venta=v.id
        INNER JOIN productos p ON dv.id_producto=p.id
        WHERE v.id_usuario=?
          AND v.fecha_venta BETWEEN ? AND ?
          AND LOWER(p.tipo_producto) NOT IN ('sim','chip','pospago')
    ";
    $stmtE1 = $conn->prepare($sqlEquiposTot);
    $stmtE1->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtE1->execute();
    $rowE1 = $stmtE1->get_result()->fetch_assoc() ?: [];
    $equipos_cnt   = (int)($rowE1['cnt'] ?? 0);
    $equipos_com   = (float)($rowE1['com_reg'] ?? 0) + (float)($rowE1['com_esp'] ?? 0);

    // Detalle equipos por venta (una fila por renglón de detalle_venta)
    $sqlEquiposDet = "
        SELECT 
            v.id AS venta_id,
            v.fecha_venta,
            p.marca, p.modelo, p.color, p.imei1,
            dv.precio_unitario,
            dv.comision_regular,
            dv.comision_especial,
            v.comision_gerente
        FROM detalle_venta dv
        INNER JOIN ventas v     ON dv.id_venta=v.id
        INNER JOIN productos p  ON dv.id_producto=p.id
        WHERE v.id_usuario=?
          AND v.fecha_venta BETWEEN ? AND ?
          AND LOWER(p.tipo_producto) NOT IN ('sim','chip','pospago')
        ORDER BY v.fecha_venta, v.id
    ";
    $stmtE2 = $conn->prepare($sqlEquiposDet);
    $stmtE2->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtE2->execute();
    $resE2 = $stmtE2->get_result();
    while ($d = $resE2->fetch_assoc()) {
        $detalleFilas[] = [
            $u['nombre'],
            $rol,
            $u['sucursal'],
            'Equipo',
            $d['venta_id'],
            (new DateTime($d['fecha_venta']))->format('Y-m-d H:i:s'),
            trim(($d['marca'] ?? '') . ' ' . ($d['modelo'] ?? '') . ' ' . ($d['color'] ?? '')),
            $d['imei1'],
            number_format((float)$d['precio_unitario'], 2, '.', ''),
            number_format((float)$d['comision_regular'], 2, '.', ''),
            number_format((float)$d['comision_especial'], 2, '.', ''),
            number_format(((float)$d['comision_regular'] + (float)$d['comision_especial']), 2, '.', ''),
            // Para el ejecutivo no sumamos aquí com_ger; el gerente lo verá en su bloque
            ''
        ];
    }

    /* ========================
       2) SIMs - sumas y conteos (Nueva/Portabilidad/Regalo)
    ======================== */
    $sims_cnt = 0; $sims_com = 0.0;
    $pos_cnt = 0;  $pos_com  = 0.0;

    if ($rol != 'Gerente') {
        $sqlSimsTot = "
            SELECT 
                COUNT(dvs.id) AS cnt,
                SUM(dvs.precio_unitario * 0.20) AS com_sims
            FROM detalle_venta_sims dvs
            INNER JOIN ventas_sims vs ON dvs.id_venta=vs.id
            WHERE vs.id_usuario=?
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta IN ('Nueva','Portabilidad','Regalo')
        ";
        $stmtS1 = $conn->prepare($sqlSimsTot);
        $stmtS1->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtS1->execute();
        $rowS1 = $stmtS1->get_result()->fetch_assoc() ?: [];
        $sims_cnt = (int)($rowS1['cnt'] ?? 0);
        $sims_com = (float)($rowS1['com_sims'] ?? 0);

        // Detalle SIMs
        $sqlSimsDet = "
            SELECT 
                vs.id AS venta_id,
                vs.fecha_venta,
                vs.tipo_venta,
                dvs.precio_unitario
            FROM detalle_venta_sims dvs
            INNER JOIN ventas_sims vs ON dvs.id_venta=vs.id
            WHERE vs.id_usuario=?
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta IN ('Nueva','Portabilidad','Regalo')
            ORDER BY vs.fecha_venta, vs.id
        ";
        $stmtS2 = $conn->prepare($sqlSimsDet);
        $stmtS2->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtS2->execute();
        $resS2 = $stmtS2->get_result();
        while ($d = $resS2->fetch_assoc()) {
            $com = (float)$d['precio_unitario'] * 0.20;
            $detalleFilas[] = [
                $u['nombre'],
                $rol,
                $u['sucursal'],
                'SIM (' . $d['tipo_venta'] . ')',
                $d['venta_id'],
                (new DateTime($d['fecha_venta']))->format('Y-m-d H:i:s'),
                'SIM ' . $d['tipo_venta'],
                '', // IMEI no aplica
                number_format((float)$d['precio_unitario'], 2, '.', ''),
                number_format(0, 2, '.', ''), // com_regular no aplica
                number_format(0, 2, '.', ''), // com_especial no aplica
                number_format($com, 2, '.', ''), // total comisión SIM
                ''
            ];
        }

        /* ========================
           3) Pospago - sumas y conteos
        ======================== */
        $sqlPosTot = "
            SELECT 
                COUNT(dvs.id) AS cnt,
                SUM(dvs.precio_unitario * 0.20) AS com_pos
            FROM detalle_venta_sims dvs
            INNER JOIN ventas_sims vs ON dvs.id_venta=vs.id
            WHERE vs.id_usuario=?
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta='Pospago'
        ";
        $stmtP1 = $conn->prepare($sqlPosTot);
        $stmtP1->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtP1->execute();
        $rowP1 = $stmtP1->get_result()->fetch_assoc() ?: [];
        $pos_cnt = (int)($rowP1['cnt'] ?? 0);
        $pos_com = (float)($rowP1['com_pos'] ?? 0);

        // Detalle Pospago
        $sqlPosDet = "
            SELECT 
                vs.id AS venta_id,
                vs.fecha_venta,
                dvs.precio_unitario
            FROM detalle_venta_sims dvs
            INNER JOIN ventas_sims vs ON dvs.id_venta=vs.id
            WHERE vs.id_usuario=?
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta='Pospago'
            ORDER BY vs.fecha_venta, vs.id
        ";
        $stmtP2 = $conn->prepare($sqlPosDet);
        $stmtP2->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtP2->execute();
        $resP2 = $stmtP2->get_result();
        while ($d = $resP2->fetch_assoc()) {
            $com = (float)$d['precio_unitario'] * 0.20;
            $detalleFilas[] = [
                $u['nombre'],
                $rol,
                $u['sucursal'],
                'Pospago',
                $d['venta_id'],
                (new DateTime($d['fecha_venta']))->format('Y-m-d H:i:s'),
                'Pospago',
                '',
                number_format((float)$d['precio_unitario'], 2, '.', ''),
                number_format(0, 2, '.', ''),
                number_format(0, 2, '.', ''),
                number_format($com, 2, '.', ''),
                ''
            ];
        }
    }

    /* ========================
       4) Comisión de Gerente (sumada desde ventas.comision_gerente)
          y detalle por venta (para el gerente)
    ======================== */
    $com_ger = 0.0;
    if ($rol == 'Gerente') {
        $sqlGerTot = "
            SELECT IFNULL(SUM(v.comision_gerente),0) AS com_ger
            FROM ventas v
            WHERE v.id_sucursal=? 
              AND v.fecha_venta BETWEEN ? AND ?
        ";
        $stmtG1 = $conn->prepare($sqlGerTot);
        $stmtG1->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtG1->execute();
        $com_ger = (float)($stmtG1->get_result()->fetch_assoc()['com_ger'] ?? 0);

        // Detalle de gerente: una fila por venta con comision_gerente > 0
        $sqlGerDet = "
            SELECT 
                v.id AS venta_id,
                v.fecha_venta,
                v.comision_gerente
            FROM ventas v
            WHERE v.id_sucursal=?
              AND v.fecha_venta BETWEEN ? AND ?
              AND v.comision_gerente > 0
            ORDER BY v.fecha_venta, v.id
        ";
        $stmtG2 = $conn->prepare($sqlGerDet);
        $stmtG2->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtG2->execute();
        $resG2 = $stmtG2->get_result();
        while ($d = $resG2->fetch_assoc()) {
            $detalleFilas[] = [
                $u['nombre'],
                $rol,
                $u['sucursal'],
                'Gerente (venta sucursal)',
                $d['venta_id'],
                (new DateTime($d['fecha_venta']))->format('Y-m-d H:i:s'),
                'Comisión gerente por venta',
                '',
                number_format(0, 2, '.', ''), // precio unitario no aplica
                number_format(0, 2, '.', ''), // com_regular no aplica
                number_format(0, 2, '.', ''), // com_especial no aplica
                number_format(0, 2, '.', ''), // total comisión de renglón no aplica
                number_format((float)$d['comision_gerente'], 2, '.', '') // columna exclusiva gerente
            ];
        }
    }

    /* ========================
       5) Total empleado y fila RESUMEN
    ======================== */
    $total = (float)$u['sueldo'] + $equipos_com + $sims_com + $pos_com + $com_ger;
    $totalGlobal += $total;

    fputcsv($output, [
        $u['nombre'],
        $rol,
        $u['sucursal'],
        number_format((float)$u['sueldo'], 2, '.', ''),
        $equipos_cnt,
        number_format($equipos_com, 2, '.', ''),
        $sims_cnt,
        number_format($sims_com, 2, '.', ''),
        $pos_cnt,
        number_format($pos_com, 2, '.', ''),
        number_format($com_ger, 2, '.', ''),
        number_format($total, 2, '.', '')
    ]);
}

/* ========================
   Fila de total global
======================== */
fputcsv($output, ['', '', '', '', '', '', '', '', '', 'Total Global', number_format($totalGlobal,2,'.','')]);

/* ========================
   Sección DETALLE POR VENTA
======================== */
fputcsv($output, []); // línea en blanco
fputcsv($output, ['DETALLE POR VENTA (Comisiones pagadas en la semana)']);
fputcsv($output, [
    'Empleado', 'Rol', 'Sucursal',
    'Tipo', 'ID Venta', 'Fecha/Hora',
    'Producto/Concepto', 'IMEI',
    'Precio Unitario',
    'Com. Regular', 'Com. Especial',
    'Comisión Total (rubro)', // Equipos o SIM/Pospago
    'Comisión Gerente (venta)' // solo llenamos cuando es gerente y aplica
]);

foreach ($detalleFilas as $fila) {
    fputcsv($output, $fila);
}

fclose($output);
exit();
