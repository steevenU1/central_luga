<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idUsuario = $_SESSION['id_usuario'];
$idSucursal = $_SESSION['id_sucursal'];
$mensaje = '';

/* =========================
   FUNCIONES AUXILIARES
========================= */

// Obtener esquema vigente según fecha
function obtenerEsquemaVigente($conn, $fechaVenta) {
    $sql = "SELECT * FROM esquemas_comisiones
            WHERE fecha_inicio <= ?
              AND (fecha_fin IS NULL OR fecha_fin >= ?)
              AND activo = 1
            ORDER BY fecha_inicio DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $fechaVenta, $fechaVenta);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Verificar cumplimiento de cuota semanal de sucursal
function cumpleCuotaSucursal($conn, $idSucursal, $fechaVenta) {
    // Buscar cuota vigente de la sucursal
    $sql = "SELECT cuota_monto
            FROM cuotas_sucursales
            WHERE id_sucursal=? AND fecha_inicio <= ?
            ORDER BY fecha_inicio DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $idSucursal, $fechaVenta);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $cuota = $row['cuota_monto'] ?? 0;

    // Calcular total vendido de la semana
    $inicioSemana = new DateTime($fechaVenta);
    $diaSemana = $inicioSemana->format('N');
    $dif = $diaSemana - 2; // martes=2
    if ($dif < 0) $dif += 7;
    $inicioSemana->modify("-$dif days")->setTime(0,0,0);
    $finSemana = clone $inicioSemana;
    $finSemana->modify("+6 days")->setTime(23,59,59);

    $sql2 = "SELECT SUM(precio_total) AS monto
             FROM ventas_sims
             WHERE id_sucursal=? AND fecha_venta BETWEEN ? AND ?";
    $stmt2 = $conn->prepare($sql2);
    $inicio = $inicioSemana->format('Y-m-d H:i:s');
    $fin = $finSemana->format('Y-m-d H:i:s');
    $stmt2->bind_param("iss", $idSucursal, $inicio, $fin);
    $stmt2->execute();
    $row2 = $stmt2->get_result()->fetch_assoc();
    $monto = $row2['monto'] ?? 0;

    return $monto >= $cuota;
}

// Calcular comisión según tipo de SIM y esquema vigente
function calcularComisionesSIM($esquema, $tipoSim, $tipoVenta, $cumpleCuota) {
    $tipoSim = strtolower($tipoSim);
    $tipoVenta = strtolower($tipoVenta);

    $columna = null;

    if ($tipoSim == 'bait') {
        $columna = ($tipoVenta == 'portabilidad') 
            ? ($cumpleCuota ? 'comision_sim_bait_port_con' : 'comision_sim_bait_port_sin')
            : ($cumpleCuota ? 'comision_sim_bait_nueva_con' : 'comision_sim_bait_nueva_sin');
    } elseif ($tipoSim == 'att') {
        $columna = ($tipoVenta == 'portabilidad') 
            ? ($cumpleCuota ? 'comision_sim_att_port_con' : 'comision_sim_att_port_sin')
            : ($cumpleCuota ? 'comision_sim_att_nueva_con' : 'comision_sim_att_nueva_sin');
    }

    return (float)($esquema[$columna] ?? 0);
}

/* =========================
   PROCESAR VENTA SIM
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idSim = (int)$_POST['id_sim'];
    $tipoVenta = $_POST['tipo_venta'];
    $tipoSim = $_POST['tipo_sim']; // Nuevo campo
    $precio = (float)$_POST['precio'];
    $comentarios = trim($_POST['comentarios']);
    $fechaVenta = date('Y-m-d');

    // 1️⃣ Verificar que la SIM está disponible
    $sql = "SELECT id, iccid FROM inventario_sims 
            WHERE id=? AND estatus='Disponible' AND id_sucursal=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $idSim, $idSucursal);
    $stmt->execute();
    $sim = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sim) {
        $mensaje = '<div class="alert alert-danger">La SIM seleccionada no está disponible.</div>';
    } else {
        // 2️⃣ Obtener esquema vigente y calcular comisiones
        $esquema = obtenerEsquemaVigente($conn, $fechaVenta);
        $cumpleCuota = cumpleCuotaSucursal($conn, $idSucursal, $fechaVenta);

        $comisionEjecutivo = calcularComisionesSIM($esquema, $tipoSim, $tipoVenta, $cumpleCuota);
        $comisionGerente = $comisionEjecutivo > 0 
            ? ($cumpleCuota ? $esquema['comision_gerente_sim_con'] : $esquema['comision_gerente_sim_sin'])
            : 0;

        // 3️⃣ Insertar en ventas_sims
        $sqlVenta = "INSERT INTO ventas_sims 
            (tipo_venta, tipo_sim, comentarios, precio_total, comision_ejecutivo, comision_gerente, id_usuario, id_sucursal, fecha_venta) 
            VALUES (?,?,?,?,?,?,?,?,NOW())";
        $stmt = $conn->prepare($sqlVenta);
        $stmt->bind_param("sssddiii", $tipoVenta, $tipoSim, $comentarios, $precio, $comisionEjecutivo, $comisionGerente, $idUsuario, $idSucursal);
        $stmt->execute();
        $idVenta = $stmt->insert_id;
        $stmt->close();

        // 4️⃣ Insertar en detalle_venta_sims
        $sqlDetalle = "INSERT INTO detalle_venta_sims (id_venta, id_sim, precio_unitario) VALUES (?,?,?)";
        $stmt = $conn->prepare($sqlDetalle);
        $stmt->bind_param("iid", $idVenta, $idSim, $precio);
        $stmt->execute();
        $stmt->close();

        // 5️⃣ Actualizar inventario_sims
        $sqlUpdate = "UPDATE inventario_sims 
                      SET estatus='Vendida', id_usuario_venta=?, fecha_venta=NOW() 
                      WHERE id=?";
        $stmt = $conn->prepare($sqlUpdate);
        $stmt->bind_param("ii", $idUsuario, $idSim);
        $stmt->execute();
        $stmt->close();

        $mensaje = '<div class="alert alert-success">✅ Venta de SIM registrada correctamente.</div>';
    }
}

// 🔹 Listar SIMs disponibles de la sucursal
$sql = "SELECT id, iccid, caja_id, fecha_ingreso 
        FROM inventario_sims 
        WHERE estatus='Disponible' AND id_sucursal=? 
        ORDER BY fecha_ingreso ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idSucursal);
$stmt->execute();
$disponibles = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Venta SIM Prepago</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📱 Venta de SIM Prepago</h2>
    <?= $mensaje ?>

    <form method="POST" class="card shadow p-3 mb-4">
        <div class="row mb-3">
            <div class="col-md-3">
                <label class="form-label">SIM disponible</label>
                <select name="id_sim" class="form-select" required>
                    <option value="">-- Selecciona SIM --</option>
                    <?php while($row = $disponibles->fetch_assoc()): ?>
                        <option value="<?= $row['id'] ?>">
                            <?= $row['iccid'] ?> | Caja: <?= $row['caja_id'] ?> | Ingreso: <?= $row['fecha_ingreso'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Tipo de SIM</label>
                <select name="tipo_sim" class="form-select" required>
                    <option value="Bait">Bait</option>
                    <option value="ATT">AT&T</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Tipo de venta</label>
                <select name="tipo_venta" class="form-select" required>
                    <option value="Nueva">Nueva</option>
                    <option value="Portabilidad">Portabilidad</option>
                    <option value="Regalo">Regalo (costo 0)</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Precio venta</label>
                <input type="number" step="0.01" name="precio" class="form-control" value="0" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Comentarios</label>
                <input type="text" name="comentarios" class="form-control">
            </div>
        </div>
        <button type="submit" class="btn btn-success">Registrar Venta</button>
    </form>
</div>

</body>
</html>
