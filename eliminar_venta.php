<?php
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

$idVenta    = (int)($_POST['id_venta'] ?? 0);
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario = $_SESSION['rol'] ?? '';

if ($idVenta <= 0) {
    header("Location: historial_ventas.php?msg=" . urlencode("❌ Venta inválida"));
    exit();
}

/** Semana actual martes-lunes */
function obtenerSemanaActual() : array {
    $hoy = new DateTime();
    $n   = (int)$hoy->format('N'); // 1=lun..7=dom
    $dif = $n - 2;                 // martes=2
    if ($dif < 0) $dif += 7;
    $inicio = (new DateTime())->modify("-$dif days")->setTime(0,0,0);
    $fin    = (clone $inicio)->modify("+6 days")->setTime(23,59,59);
    return [$inicio, $fin];
}
list($inicioSemana, $finSemana) = obtenerSemanaActual();

/* 1) Cargar venta */
$sqlVenta = "SELECT id, id_usuario, fecha_venta FROM ventas WHERE id=? LIMIT 1";
$st = $conn->prepare($sqlVenta);
$st->bind_param("i", $idVenta);
$st->execute();
$venta = $st->get_result()->fetch_assoc();
$st->close();

if (!$venta) {
    header("Location: historial_ventas.php?msg=" . urlencode("❌ Venta no encontrada"));
    exit();
}

/* 2) Validar ventana (solo semana actual) */
$fechaVenta = new DateTime($venta['fecha_venta']);
if ($fechaVenta < $inicioSemana || $fechaVenta > $finSemana) {
    header("Location: historial_ventas.php?msg=" . urlencode("❌ Solo puedes eliminar ventas de esta semana"));
    exit();
}

/* 3) Permisos
   - Admin: puede eliminar cualquier venta (de esta semana)
   - Ejecutivo/Gerente: solo sus propias ventas (de esta semana)
*/
$puedeEliminar = false;
if ($rolUsuario === 'Admin') {
    $puedeEliminar = true;
} elseif (in_array($rolUsuario, ['Ejecutivo','Gerente'], true) && (int)$venta['id_usuario'] === $idUsuario) {
    $puedeEliminar = true;
}

if (!$puedeEliminar) {
    header("Location: historial_ventas.php?msg=" . urlencode("❌ No tienes permiso para eliminar esta venta"));
    exit();
}

/* 4) Operación: devolver equipos y borrar venta + detalle */
$conn->begin_transaction();

try {
    // Devolver productos al inventario (ajusta columnas/tablas si en tu esquema difiere)
    $sqlDet = "SELECT id_producto FROM detalle_venta WHERE id_venta=?";
    $st = $conn->prepare($sqlDet);
    $st->bind_param("i", $idVenta);
    $st->execute();
    $res = $st->get_result();

    $upd = $conn->prepare("UPDATE inventario SET estatus='Disponible' WHERE id_producto=?");
    while ($row = $res->fetch_assoc()) {
        $idProd = (int)$row['id_producto'];
        $upd->bind_param("i", $idProd);
        $upd->execute();
    }
    $upd->close();
    $st->close();

    // Borrar detalle
    $delDet = $conn->prepare("DELETE FROM detalle_venta WHERE id_venta=?");
    $delDet->bind_param("i", $idVenta);
    $delDet->execute();
    $delDet->close();

    // Borrar venta
    $delVen = $conn->prepare("DELETE FROM ventas WHERE id=?");
    $delVen->bind_param("i", $idVenta);
    $delVen->execute();
    $delVen->close();

    $conn->commit();

    header("Location: historial_ventas.php?msg=" . urlencode("✅ Venta eliminada correctamente"));
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    // Log opcional: error_log($e->getMessage());
    header("Location: historial_ventas.php?msg=" . urlencode("❌ Ocurrió un error al eliminar la venta"));
    exit();
}
