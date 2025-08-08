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

// 🔹 Planes pospago
$planesPospago = [
    "Plan Bait 199" => 199,
    "Plan Bait 249" => 249,
    "Plan Bait 289" => 289,
    "Plan Bait 339" => 339
];

// ===============================
// 🔹 FUNCIONES AUXILIARES
// ===============================

// 1️⃣ Obtener esquema vigente de comisiones pospago
function obtenerEsquemaPospago($conn) {
    $fechaHoy = date('Y-m-d');
    $sql = "SELECT * FROM esquema_pospago
            WHERE fecha_inicio <= ?
            ORDER BY fecha_inicio DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $fechaHoy);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res;
}

// 2️⃣ Verificar cumplimiento de cuota del ejecutivo
function cubreCuotaEjecutivo($conn, $idUsuario) {
    $sql = "SELECT COUNT(*) AS unidades
            FROM detalle_venta dv
            INNER JOIN ventas v ON dv.id_venta=v.id
            INNER JOIN productos p ON dv.id_producto=p.id
            WHERE v.id_usuario=?
              AND YEARWEEK(v.fecha_venta,3)=YEARWEEK(NOW(),3)
              AND LOWER(p.tipo_producto) NOT IN ('mifi','modem')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $idUsuario);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($res['unidades'] ?? 0) >= 6; // Ajusta si tienes cuota dinámica
}

// 3️⃣ Calcular comisión según plan, modalidad y cuota
function calcularComisionPospago($esquema, $planPrecio, $modalidad, $cubreCuota) {
    // Modalidad con o sin equipo
    $modalidadKey = (stripos($modalidad,'con')!==false) ? '_con' : '_sin';
    $cuotaKey = $cubreCuota ? '_cuota' : '_sin_cuota';

    switch($planPrecio) {
        case 199: return $esquema['plan_199'.$modalidadKey.$cuotaKey] ?? 0;
        case 249: return $esquema['plan_249'.$modalidadKey.$cuotaKey] ?? 0;
        case 289: return $esquema['plan_289'.$modalidadKey.$cuotaKey] ?? 0;
        case 339: return $esquema['plan_339'.$modalidadKey.$cuotaKey] ?? 0;
        default: return 0;
    }
}

// ===============================
// 🔹 PROCESAR VENTA
// ===============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $esEsim = isset($_POST['es_esim']) ? 1 : 0;
    $idSim = $_POST['id_sim'] ?? null;
    $plan = $_POST['plan'];
    $precio = $planesPospago[$plan] ?? 0;
    $modalidad = $_POST['modalidad'];
    $idVentaEquipo = !empty($_POST['id_venta_equipo']) ? $_POST['id_venta_equipo'] : null;
    $nombreCliente = trim($_POST['nombre_cliente']);
    $numeroCliente = trim($_POST['numero_cliente']);
    $comentarios = trim($_POST['comentarios']);

    // 1️⃣ Validar SIM física
    if (!$esEsim && $idSim) {
        $sql = "SELECT id, iccid FROM inventario_sims 
                WHERE id=? AND estatus='Disponible' AND id_sucursal=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $idSim, $idSucursal);
        $stmt->execute();
        $sim = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sim) {
            $mensaje = '<div class="alert alert-danger">La SIM seleccionada no está disponible.</div>';
        }
    }

    if ($mensaje == '') {
        // 2️⃣ Obtener esquema y calcular comisiones
        $esquema = obtenerEsquemaPospago($conn);
        $cubreCuota = cubreCuotaEjecutivo($conn, $idUsuario);
        $comisionEjecutivo = calcularComisionPospago($esquema, $precio, $modalidad, $cubreCuota);

        // 🔹 Comision gerente simple (puedes ajustar según tu tabla)
        $comisionGerente = $cubreCuota ? 30 : 10;

        // 3️⃣ Insertar en ventas_sims
        $sqlVenta = "INSERT INTO ventas_sims 
            (tipo_venta, comentarios, precio_total, comision_ejecutivo, comision_gerente, 
             id_usuario, id_sucursal, es_esim, modalidad, id_venta_equipo, numero_cliente, nombre_cliente)
            VALUES ('Pospago', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sqlVenta);
        $stmt->bind_param(
            "sdddiisiiss",
            $comentarios, 
            $precio, 
            $comisionEjecutivo, 
            $comisionGerente, 
            $idUsuario, 
            $idSucursal,
            $esEsim,
            $modalidad,
            $idVentaEquipo,
            $numeroCliente,
            $nombreCliente
        );
        $stmt->execute();
        $idVenta = $stmt->insert_id;
        $stmt->close();

        // 4️⃣ Si es SIM física: insertar detalle y actualizar inventario
        if (!$esEsim && $idSim) {
            // Insertar en detalle
            $sqlDetalle = "INSERT INTO detalle_venta_sims (id_venta, id_sim, precio_unitario) VALUES (?,?,?)";
            $stmt = $conn->prepare($sqlDetalle);
            $stmt->bind_param("iid", $idVenta, $idSim, $precio);
            $stmt->execute();
            $stmt->close();

            // Actualizar inventario
            $sqlUpdate = "UPDATE inventario_sims 
                          SET estatus='Vendida', 
                              id_usuario_venta=?, 
                              fecha_venta=NOW() 
                          WHERE id=?";
            $stmt = $conn->prepare($sqlUpdate);
            $stmt->bind_param("ii", $idUsuario, $idSim);
            $stmt->execute();
            $stmt->close();
        }

        $mensaje = '<div class="alert alert-success">✅ Venta pospago registrada correctamente con comisión calculada.</div>';
    }
}

// ===============================
// 🔹 LISTAR SIMs Y VENTAS EQUIPOS
// ===============================
$sql = "SELECT id, iccid, caja_id, fecha_ingreso 
        FROM inventario_sims 
        WHERE estatus='Disponible' AND id_sucursal=? 
        ORDER BY fecha_ingreso ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idSucursal);
$stmt->execute();
$disponibles = $stmt->get_result();
$stmt->close();

$sqlEquipos = "SELECT id, tag, fecha_venta 
               FROM ventas 
               WHERE id_sucursal=? AND id_usuario=? 
               ORDER BY fecha_venta DESC 
               LIMIT 50";
$stmt = $conn->prepare($sqlEquipos);
$stmt->bind_param("ii", $idSucursal, $idUsuario);
$stmt->execute();
$ventasEquipos = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Venta SIM Pospago</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script>
        function toggleSimSelect() {
            const isEsim = document.getElementById('es_esim').checked;
            document.getElementById('sim_fisica').style.display = isEsim ? 'none' : 'block';
        }
        function toggleEquipo() {
            const modalidad = document.getElementById('modalidad').value;
            document.getElementById('venta_equipo').style.display = (modalidad === 'Con equipo') ? 'block' : 'none';
        }
        function setPrecio() {
            const plan = document.getElementById('plan').value;
            const precios = {
                "Plan Bait 199":199,
                "Plan Bait 249":249,
                "Plan Bait 289":289,
                "Plan Bait 339":339
            };
            document.getElementById('precio').value = precios[plan] || 0;
        }
    </script>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📱 Venta de SIM Pospago</h2>
    <?= $mensaje ?>

    <form method="POST" class="card shadow p-3 mb-4">
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="es_esim" name="es_esim" onchange="toggleSimSelect()">
            <label class="form-check-label">Es eSIM (no afecta inventario)</label>
        </div>

        <!-- SIM Física -->
        <div class="row mb-3" id="sim_fisica">
            <div class="col-md-4">
                <label class="form-label">SIM física disponible</label>
                <select name="id_sim" class="form-select">
                    <option value="">-- Selecciona SIM --</option>
                    <?php while($row = $disponibles->fetch_assoc()): ?>
                        <option value="<?= $row['id'] ?>">
                            <?= $row['iccid'] ?> | Caja: <?= $row['caja_id'] ?> | Ingreso: <?= $row['fecha_ingreso'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>

        <!-- Datos de la venta -->
        <div class="row mb-3">
            <div class="col-md-3">
                <label class="form-label">Plan pospago</label>
                <select name="plan" id="plan" class="form-select" onchange="setPrecio()" required>
                    <option value="">-- Selecciona plan --</option>
                    <?php foreach($planesPospago as $plan => $precioPlan): ?>
                        <option value="<?= $plan ?>"><?= $plan ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Precio venta</label>
                <input type="number" step="0.01" id="precio" name="precio" class="form-control" readonly>
            </div>
            <div class="col-md-3">
                <label class="form-label">Modalidad</label>
                <select name="modalidad" id="modalidad" class="form-select" onchange="toggleEquipo()" required>
                    <option value="Sin equipo">Sin equipo</option>
                    <option value="Con equipo">Con equipo</option>
                </select>
            </div>
            <div class="col-md-4" id="venta_equipo" style="display:none;">
                <label class="form-label">Selecciona venta de equipo</label>
                <select name="id_venta_equipo" class="form-select">
                    <option value="">-- Selecciona venta --</option>
                    <?php while($row = $ventasEquipos->fetch_assoc()): ?>
                        <option value="<?= $row['id'] ?>">
                            Venta #<?= $row['id'] ?> | Fecha: <?= $row['fecha_venta'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>

        <!-- Datos del cliente -->
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Nombre del cliente</label>
                <input type="text" name="nombre_cliente" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Número telefónico</label>
                <input type="text" name="numero_cliente" class="form-control" required>
            </div>
            <div class="col-md-5">
                <label class="form-label">Comentarios</label>
                <input type="text" name="comentarios" class="form-control">
            </div>
        </div>

        <button type="submit" class="btn btn-success">Registrar Venta Pospago</button>
    </form>
</div>

<script>toggleSimSelect(); toggleEquipo();</script>

</body>
</html>

