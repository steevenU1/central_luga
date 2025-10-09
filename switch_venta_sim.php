<?php
// switch_venta_sim.php — Cambia venta a Portabilidad y deja comisiones en 0
// Reglas: solo semana actual (Mar→Lun), solo Ejecutivo, solo ventas propias, solo si hoy es "Nueva".
declare(strict_types=1);

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

date_default_timezone_set('America/Mexico_City');
require_once __DIR__ . '/db.php';

/* ===== Helpers ===== */
function limitesSemanaActualMX(): array {
    // Semana operativa Mar→Lun con TZ MX
    $now = new DateTime('now');
    $n   = (int)$now->format('N'); // 1=lun...7=dom
    $dif = $n - 2; if ($dif < 0) $dif += 7; // martes=2
    $ini = (clone $now)->modify("-{$dif} days")->setTime(0,0,0);
    $fin = (clone $ini)->modify("+6 days")->setTime(23,59,59);
    return [$ini, $fin];
}
function back_to(?string $url) {
    if (!$url) $url = 'historial_ventas_sims.php';
    header("Location: $url"); exit();
}

/* ===== Validaciones base ===== */
$rol       = (string)($_SESSION['rol'] ?? '');
$idUsuario = (int)   ($_SESSION['id_usuario'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') back_to('historial_ventas_sims.php');

$csrf    = $_POST['csrf']     ?? '';
$back    = $_POST['back']     ?? '';
$idVenta = (int)($_POST['id_venta'] ?? 0);

if (!$csrf || !hash_equals($_SESSION['csrf_sim'] ?? '', $csrf)) {
    $_SESSION['flash_error'] = 'Solicitud inválida (CSRF).';
    back_to($back);
}
if (strcasecmp($rol, 'Ejecutivo') !== 0) {
    $_SESSION['flash_error'] = 'Solo Ejecutivos pueden usar el switch.';
    back_to($back);
}

/* ===== Cargar venta ===== */
$sql = "SELECT vs.id, vs.id_usuario, vs.id_sucursal, vs.tipo_venta, vs.fecha_venta
        FROM ventas_sims vs
        WHERE vs.id=? LIMIT 1";
$st = $conn->prepare($sql);
$st->bind_param("i", $idVenta);
$st->execute();
$venta = $st->get_result()->fetch_assoc();
$st->close();

if (!$venta) {
    $_SESSION['flash_error'] = 'Venta no encontrada.';
    back_to($back);
}
if ((int)$venta['id_usuario'] !== $idUsuario) {
    $_SESSION['flash_error'] = 'Solo puedes modificar tus propias ventas.';
    back_to($back);
}

/* ===== Elegibilidad ===== */
$tipoActual = (string)($venta['tipo_venta'] ?? '');
if ($tipoActual !== 'Nueva') {
    $_SESSION['flash_error'] = 'Solo se pueden convertir ventas de tipo “Nueva”.';
    back_to($back);
}

// Semana actual (Mar→Lun)
[$iniAct, $finAct] = limitesSemanaActualMX();
$fechaVentaDT = new DateTime((string)$venta['fecha_venta']);
if ($fechaVentaDT < $iniAct || $fechaVentaDT > $finAct) {
    $_SESSION['flash_error'] = 'El switch solo está disponible durante la semana actual.';
    back_to($back);
}

/* ===== Actualizar: Portabilidad + comisiones 0 ===== */
$nota = " [switch->Portabilidad ".date('d/m')."]";
$upd  = "UPDATE ventas_sims
         SET tipo_venta='Portabilidad',
             comision_ejecutivo=0,
             comision_gerente=0,
             comentarios=CONCAT(COALESCE(comentarios,''), ?)
         WHERE id=? LIMIT 1";
$st = $conn->prepare($upd);
$st->bind_param("si", $nota, $idVenta);

try {
    $st->execute();
    $st->close();
    $_SESSION['flash_ok'] = '✅ Venta convertida a Portabilidad.';
} catch (mysqli_sql_exception $e) {
    $_SESSION['flash_error'] = 'No se pudo actualizar: '.$e->getMessage();
}

back_to($back);
