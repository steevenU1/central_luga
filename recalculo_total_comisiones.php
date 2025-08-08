<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

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

echo "<h3>🔄 Recalculo total de comisiones - Semana seleccionada</h3>";
echo "<p>Del {$inicioSemanaObj->format('d/m/Y')} al {$finSemanaObj->format('d/m/Y')}</p>";

/* ========================
   CARGAR ESQUEMAS
======================== */
$esquemaEje = $conn->query("SELECT * FROM esquemas_comisiones_ejecutivos ORDER BY fecha_inicio DESC LIMIT 1")->fetch_assoc();
$esquemaGer = $conn->query("SELECT * FROM esquemas_comisiones_gerentes ORDER BY fecha_inicio DESC LIMIT 1")->fetch_assoc();

function comisionEjecutivoEquipo($precio, $tipoProducto, $cumpleCuota, $esquema) {
    $tipo = strtolower($tipoProducto);

    // Mifi/Modem
    if (in_array($tipo, ['mifi','modem'])) {
        return $cumpleCuota ? (float)$esquema['comision_mifi_con'] : (float)$esquema['comision_mifi_sin'];
    }

    // Clasificación por precio
    if ($precio <= 3499) return $cumpleCuota ? (float)$esquema['comision_c_con'] : (float)$esquema['comision_c_sin'];
    if ($precio <= 5499) return $cumpleCuota ? (float)$esquema['comision_b_con'] : (float)$esquema['comision_b_sin'];
    return $cumpleCuota ? (float)$esquema['comision_a_con'] : (float)$esquema['comision_a_sin'];
}

function comisionEjecutivoSIM($tipo, $portabilidad, $cumpleCuota, $esquema) {
    $port = strtolower($portabilidad);
    $sim = strtolower($tipo);

    if ($sim == 'bait') {
        if ($port == 'portabilidad') return $cumpleCuota ? (float)$esquema['comision_sim_bait_port_con'] : (float)$esquema['comision_sim_bait_port_sin'];
        return $cumpleCuota ? (float)$esquema['comision_sim_bait_nueva_con'] : (float)$esquema['comision_sim_bait_nueva_sin'];
    } else {
        if ($port == 'portabilidad') return $cumpleCuota ? (float)$esquema['comision_sim_att_port_con'] : (float)$esquema['comision_sim_att_port_sin'];
        return $cumpleCuota ? (float)$esquema['comision_sim_att_nueva_con'] : (float)$esquema['comision_sim_att_nueva_sin'];
    }
}

/* ========================
   CONSULTA DE USUARIOS
======================== */
$sqlUsuarios = "
    SELECT u.id, u.nombre, u.rol, u.id_sucursal
    FROM usuarios u
    INNER JOIN sucursales s ON s.id=u.id_sucursal
    WHERE u.rol IN ('Ejecutivo','Gerente')
    ORDER BY s.nombre, FIELD(u.rol,'Gerente','Ejecutivo'), u.nombre
";
$resUsuarios = $conn->query($sqlUsuarios);

$totalProcesados = 0;

/* ========================
   RECORRER USUARIOS
======================== */
while ($u = $resUsuarios->fetch_assoc()) {
    $id_usuario = $u['id'];
    $id_sucursal = $u['id_sucursal'];
    $rol = $u['rol'];

    /* ========================
       1️⃣ OBTENER UNIDADES
    ======================== */
    $sqlUnidades = "
        SELECT COUNT(*) AS unidades
        FROM detalle_venta dv
        INNER JOIN ventas v ON dv.id_venta=v.id
        INNER JOIN productos p ON dv.id_producto=p.id
        WHERE v.id_usuario=? 
          AND v.fecha_venta BETWEEN ? AND ?
          AND LOWER(p.tipo_producto) NOT IN ('mifi','modem','sim','chip','pospago')
    ";
    $stmtU = $conn->prepare($sqlUnidades);
    $stmtU->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtU->execute();
    $unidades = (int)($stmtU->get_result()->fetch_assoc()['unidades'] ?? 0);

    // Cuota semanal por sucursal
    $sqlCuota = "
        SELECT cuota_unidades
        FROM cuotas_semanales_sucursal
        WHERE id_sucursal=? 
          AND semana_inicio <= ? 
          AND semana_fin >= ?
        LIMIT 1
    ";
    $stmtC = $conn->prepare($sqlCuota);
    $stmtC->bind_param("iss", $id_sucursal, $inicioSemana, $inicioSemana);
    $stmtC->execute();
    $cuota = (int)($stmtC->get_result()->fetch_assoc()['cuota_unidades'] ?? 6);

    $cumpleCuota = $unidades >= $cuota;

    /* ========================
       2️⃣ RECORRER VENTAS DEL USUARIO
    ======================== */
    $sqlVentas = "
        SELECT id
        FROM ventas
        WHERE id_usuario=? AND fecha_venta BETWEEN ? AND ?
    ";
    $stmtV = $conn->prepare($sqlVentas);
    $stmtV->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtV->execute();
    $resVentas = $stmtV->get_result();

    while ($venta = $resVentas->fetch_assoc()) {
        $id_venta = $venta['id'];
        $totalVenta = 0;

        $sqlDV = "
            SELECT dv.id, dv.id_producto, dv.precio_unitario, dv.comision_especial, p.tipo_producto
            FROM detalle_venta dv
            INNER JOIN productos p ON dv.id_producto=p.id
            WHERE dv.id_venta=?
        ";
        $stmtDV = $conn->prepare($sqlDV);
        $stmtDV->bind_param("i", $id_venta);
        $stmtDV->execute();
        $detalles = $stmtDV->get_result();

        while ($det = $detalles->fetch_assoc()) {
            $comisionEspecial = (float)$det['comision_especial'];
            $comisionRegular = comisionEjecutivoEquipo($det['precio_unitario'], $det['tipo_producto'], $cumpleCuota, $esquemaEje);
            $comisionTotal = $comisionRegular + $comisionEspecial;

            // Actualizar detalle
            $stmtUpdDV = $conn->prepare("
                UPDATE detalle_venta 
                SET comision_regular=?, comision=? 
                WHERE id=?
            ");
            $stmtUpdDV->bind_param("ddi", $comisionRegular, $comisionTotal, $det['id']);
            $stmtUpdDV->execute();

            $totalVenta += $comisionTotal;
        }

        $stmtUpdV = $conn->prepare("UPDATE ventas SET comision=? WHERE id=?");
        $stmtUpdV->bind_param("di", $totalVenta, $id_venta);
        $stmtUpdV->execute();
    }

    echo "<p>".($rol=="Gerente"?"🟩":"🟦")." {$rol} {$u['nombre']} - Unidades: {$unidades}, Cuota: {$cuota}, Cumple: ".($cumpleCuota?"✅":"❌")."</p>";
    $totalProcesados++;
}

echo "<hr><h4>✅ Recalculo completado. Usuarios procesados: {$totalProcesados}</h4>";
echo '<a href="reporte_nomina.php?semana='.$semanaSeleccionada.'" class="btn btn-primary mt-3">← Volver al Reporte</a>';
?>
