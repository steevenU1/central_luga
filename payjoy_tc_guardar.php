<?php
// payjoy_tc_guardar.php — Guardar venta PayJoy TC (comisiones iniciales en 0)
// - comision = 0.00
// - comision_gerente = 0.00
// - id_cliente para amarrar la TC al cliente

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_features.php';

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal  = (int)($_POST['id_sucursal'] ?? ($_SESSION['id_sucursal'] ?? 0));

$isAdminLike = in_array($ROL, ['Admin','Super','SuperAdmin','RH'], true);

// Bandera efectiva
$flagOpen = PAYJOY_TC_CAPTURE_OPEN || ($isAdminLike && PAYJOY_TC_ADMIN_PREVIEW);

// Bloquea si no está habilitado
if (!$flagOpen) {
  header("Location: payjoy_tc_nueva.php?err=" . urlencode("❌ La captura de PayJoy TC aún no está habilitada."));
  exit();
}

/* ===== Utils ===== */
function columnExists(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = '$t'
      AND COLUMN_NAME  = '$c'
    LIMIT 1
  ";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

/* ===== Asegurar columnas comision_gerente e id_cliente ===== */
if (!columnExists($conn, 'ventas_payjoy_tc', 'comision_gerente')) {
  @$conn->query("ALTER TABLE ventas_payjoy_tc
                 ADD COLUMN comision_gerente DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER comision");
}
if (!columnExists($conn, 'ventas_payjoy_tc', 'id_cliente')) {
  @$conn->query("ALTER TABLE ventas_payjoy_tc
                 ADD COLUMN id_cliente INT NOT NULL DEFAULT 0 AFTER id_sucursal");
}

$hasColComisionGerente = columnExists($conn, 'ventas_payjoy_tc', 'comision_gerente');
$hasColIdCliente       = columnExists($conn, 'ventas_payjoy_tc', 'id_cliente');

// Datos
$idCliente       = (int)($_POST['id_cliente'] ?? 0);
$nombreCliente   = trim($_POST['nombre_cliente'] ?? '');
$tag             = trim($_POST['tag'] ?? '');
$comentarios     = trim($_POST['comentarios'] ?? '');

// Comisiones iniciales en 0
$comision        = 0.00;
$comisionGerente = 0.00;

if ($idSucursal <= 0 || $tag === '') {
  header("Location: payjoy_tc_nueva.php?err=" . urlencode("Faltan datos obligatorios (sucursal o TAG)."));
  exit();
}
if ($idCliente <= 0) {
  header("Location: payjoy_tc_nueva.php?err=" . urlencode("Debes seleccionar o registrar un cliente para amarrar la tarjeta."));
  exit();
}
if ($nombreCliente === '') {
  // Por seguridad, aunque viene del JS
  header("Location: payjoy_tc_nueva.php?err=" . urlencode("No se recibió el nombre del cliente. Intenta seleccionar de nuevo."));
  exit();
}

/* ===== Insert con combinaciones de columnas (compatibilidad) ===== */
if ($hasColIdCliente && $hasColComisionGerente) {
  $sql = "INSERT INTO ventas_payjoy_tc
            (id_usuario, id_sucursal, id_cliente, nombre_cliente, tag, comision, comision_gerente, comentarios, fecha_venta)
          VALUES (?,?,?,?,?,?,?, ?, NOW())";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param(
    "iiissdds",
    $idUsuario,
    $idSucursal,
    $idCliente,
    $nombreCliente,
    $tag,
    $comision,
    $comisionGerente,
    $comentarios
  );
} elseif ($hasColIdCliente && !$hasColComisionGerente) {
  $sql = "INSERT INTO ventas_payjoy_tc
            (id_usuario, id_sucursal, id_cliente, nombre_cliente, tag, comision, comentarios, fecha_venta)
          VALUES (?,?,?,?,?,?,?, NOW())";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param(
    "iiissds",
    $idUsuario,
    $idSucursal,
    $idCliente,
    $nombreCliente,
    $tag,
    $comision,
    $comentarios
  );
} elseif (!$hasColIdCliente && $hasColComisionGerente) {
  $sql = "INSERT INTO ventas_payjoy_tc
            (id_usuario, id_sucursal, nombre_cliente, tag, comision, comision_gerente, comentarios, fecha_venta)
          VALUES (?,?,?,?,?,?,?, NOW())";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param(
    "iissdds",
    $idUsuario,
    $idSucursal,
    $nombreCliente,
    $tag,
    $comision,
    $comisionGerente,
    $comentarios
  );
} else {
  $sql = "INSERT INTO ventas_payjoy_tc
            (id_usuario, id_sucursal, nombre_cliente, tag, comision, comentarios, fecha_venta)
          VALUES (?,?,?,?,?,?, NOW())";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param(
    "iissds",
    $idUsuario,
    $idSucursal,
    $nombreCliente,
    $tag,
    $comision,
    $comentarios
  );
}

$stmt->execute();
$stmt->close();

header("Location: historial_payjoy_tc.php?msg=" . urlencode("✅ Venta PayJoy TC registrada, ligada al cliente y con comisiones iniciales en $0.00."));
exit();
