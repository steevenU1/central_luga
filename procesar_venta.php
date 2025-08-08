<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

/* ========================
   FUNCIONES AUXILIARES
======================== */

// Obtener inicio de semana (martes-lunes)
function obtenerInicioSemana() {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); 
    $offset = $diaSemana - 2; // Martes = 2
    if ($offset < 0) $offset += 7;
    $inicio = new DateTime();
    $inicio->modify("-$offset days")->setTime(0,0,0);
    return $inicio;
}

// Calcular comisión regular de equipos
function calcularComisionEquipo($precio, $esCombo, $cubreCuota, $esMiFi = false) {
    if ($esCombo) return 75; 
    if ($esMiFi) return $cubreCuota ? 100 : 75;

    if ($precio >= 1 && $precio <= 3500) return $cubreCuota ? 100 : 75; 
    if ($precio >= 3501 && $precio <= 5500) return $cubreCuota ? 200 : 100;
    if ($precio >= 5501) return $cubreCuota ? 250 : 150;

    return 0;
}

// Obtener comisión especial por producto
function obtenerComisionEspecial($id_producto, $conn) {
    $hoy = date('Y-m-d');

    $sql = "SELECT marca, modelo, capacidad FROM productos WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id_producto);
    $stmt->execute();
    $prod = $stmt->get_result()->fetch_assoc();
    if (!$prod) return 0;

    $sql2 = "SELECT monto 
             FROM comisiones_especiales
             WHERE marca=? AND modelo=? AND (capacidad=? OR capacidad='' OR capacidad IS NULL)
               AND fecha_inicio <= ?
               AND (fecha_fin IS NULL OR fecha_fin >= ?)
               AND activo=1
             ORDER BY fecha_inicio DESC
             LIMIT 1";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param("sssss", $prod['marca'], $prod['modelo'], $prod['capacidad'], $hoy, $hoy);
    $stmt2->execute();
    $res = $stmt2->get_result()->fetch_assoc();

    return (float)($res['monto'] ?? 0);
}

// Registrar un equipo vendido en detalle_venta
function venderEquipo($id_venta, $id_inventario, $conn, $esCombo, $cubreCuota) {
    $sqlProd = "SELECT i.id_producto, p.imei1, p.precio_lista, LOWER(p.tipo_producto) AS tipo
                FROM inventario i
                INNER JOIN productos p ON i.id_producto = p.id
                WHERE i.id = ? AND i.estatus = 'Disponible'";
    $stmtProd = $conn->prepare($sqlProd);
    $stmtProd->bind_param("i", $id_inventario);
    $stmtProd->execute();
    $row = $stmtProd->get_result()->fetch_assoc();
    if (!$row) return 0;

    $esMiFi = ($row['tipo'] == 'modem' || $row['tipo'] == 'mifi');
    $comisionRegular = calcularComisionEquipo($row['precio_lista'], $esCombo, $cubreCuota, $esMiFi);
    $comisionEspecial = obtenerComisionEspecial($row['id_producto'], $conn);
    $comisionFinal = $comisionRegular + $comisionEspecial;

    // Insertar detalle de venta
    $sqlDetalle = "INSERT INTO detalle_venta 
        (id_venta, id_producto, imei1, precio_unitario, comision, comision_regular, comision_especial)
        VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmtDetalle = $conn->prepare($sqlDetalle);
    $stmtDetalle->bind_param(
        "iisdddd", 
        $id_venta, 
        $row['id_producto'], 
        $row['imei1'], 
        $row['precio_lista'], 
        $comisionFinal,
        $comisionRegular,
        $comisionEspecial
    );
    $stmtDetalle->execute();

    // Marcar inventario como vendido
    $sqlUpdate = "UPDATE inventario SET estatus='Vendido' WHERE id=?";
    $stmtUpdate = $conn->prepare($sqlUpdate);
    $stmtUpdate->bind_param("i", $id_inventario);
    $stmtUpdate->execute();

    return $comisionFinal;
}

/* ========================
   1️⃣ RECIBIR DATOS POST
======================== */
$tag = $_POST['tag'];
$nombre_cliente = $_POST['nombre_cliente'];
$telefono_cliente = $_POST['telefono_cliente'];
$tipo_venta = $_POST['tipo_venta'];
$equipo1 = $_POST['equipo1'];
$equipo2 = $_POST['equipo2'] ?? null;
$precio_venta = $_POST['precio_venta'];
$enganche = $_POST['enganche'] ?? 0;
$forma_pago_enganche = $_POST['forma_pago_enganche'] ?? 'Efectivo';
$enganche_efectivo = $_POST['enganche_efectivo'] ?? 0;
$enganche_tarjeta = $_POST['enganche_tarjeta'] ?? 0;
$plazo_semanas = $_POST['plazo_semanas'] ?? 0;
$financiera = $_POST['financiera'] ?? 'N/A';
$comentarios = $_POST['comentarios'] ?? '';

$id_usuario = $_SESSION['id_usuario'];
$id_sucursal = isset($_POST['id_sucursal']) ? intval($_POST['id_sucursal']) : $_SESSION['id_sucursal'];

if ($tipo_venta === "Contado") {
    $financiera = 'N/A';
}

/* ========================
   2️⃣ INSERTAR VENTA
======================== */
$sqlVenta = "INSERT INTO ventas 
(tag, nombre_cliente, telefono_cliente, tipo_venta, precio_venta, id_usuario, id_sucursal, comision, enganche, forma_pago_enganche, enganche_efectivo, enganche_tarjeta, plazo_semanas, financiera, comentarios)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

$stmtVenta = $conn->prepare($sqlVenta);
$comisionInicial = 0;

$stmtVenta->bind_param(
    "ssssdiiddsddiss",
    $tag,
    $nombre_cliente,
    $telefono_cliente,
    $tipo_venta,
    $precio_venta,
    $id_usuario,
    $id_sucursal,
    $comisionInicial,
    $enganche,
    $forma_pago_enganche,
    $enganche_efectivo,
    $enganche_tarjeta,
    $plazo_semanas,
    $financiera,
    $comentarios
);
$stmtVenta->execute();
$id_venta = $stmtVenta->insert_id;

/* ========================
   3️⃣ CALCULAR UNIDADES SEMANA
======================== */
$inicioSemana = obtenerInicioSemana()->format('Y-m-d H:i:s');
$hoy = date('Y-m-d 23:59:59');

$sqlUnidades = "SELECT COUNT(*) AS unidades
                FROM detalle_venta dv
                INNER JOIN ventas v ON dv.id_venta = v.id
                INNER JOIN productos p ON dv.id_producto = p.id
                WHERE v.id_usuario=? 
                AND v.fecha_venta BETWEEN ? AND ?
                AND LOWER(p.tipo_producto) NOT IN ('mifi','modem')";
$stmtUni = $conn->prepare($sqlUnidades);
$stmtUni->bind_param("iss", $id_usuario, $inicioSemana, $hoy);
$stmtUni->execute();
$resUni = $stmtUni->get_result()->fetch_assoc();
$unidadesSemana = $resUni['unidades'] ?? 0;

$cubreCuota = ($unidadesSemana >= 6);

/* ========================
   4️⃣ REGISTRAR EQUIPOS
======================== */
$totalComision = 0;
$totalComision += venderEquipo($id_venta, $equipo1, $conn, false, $cubreCuota);

if ($tipo_venta === "Financiamiento+Combo" && $equipo2) {
    $totalComision += venderEquipo($id_venta, $equipo2, $conn, true, $cubreCuota);
}

/* ========================
   5️⃣ ACTUALIZAR COMISION TOTAL
======================== */
$sqlUpdateVenta = "UPDATE ventas SET comision=? WHERE id=?";
$stmtUpdateVenta = $conn->prepare($sqlUpdateVenta);
$stmtUpdateVenta->bind_param("di", $totalComision, $id_venta);
$stmtUpdateVenta->execute();

header("Location: historial_ventas.php?msg=Venta registrada con comisión $" . number_format($totalComision,2));
exit();
?>
