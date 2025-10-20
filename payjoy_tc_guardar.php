<?php
// payjoy_tc_guardar.php — Guardar venta PayJoy TC (comisiones iniciales en 0)
// - comision = 0.00
// - comision_gerente = 0.00 (nueva columna; se crea si no existe)

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

/* ===== Asegurar columna comision_gerente (si no existe) ===== */
if (!columnExists($conn, 'ventas_payjoy_tc', 'comision_gerente')) {
  // Intento de migración rápida; si falla, seguimos sin romper flujo.
  @$conn->query("ALTER TABLE ventas_payjoy_tc
                 ADD COLUMN comision_gerente DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER comision");
}

// Datos
$nombreCliente = trim($_POST['nombre_cliente'] ?? '');
$tag           = trim($_POST['tag'] ?? '');
$comentarios   = trim($_POST['comentarios'] ?? '');

// Comisiones iniciales en 0
$comision          = 0.00;
$comisionGerente   = 0.00;

if ($nombreCliente === '' || $tag === '') {
  header("Location: payjoy_tc_nueva.php?err=" . urlencode("Faltan datos obligatorios"));
  exit();
}

/* ===== Insert con o sin columna comision_gerente (compat) ===== */
if (columnExists($conn, 'ventas_payjoy_tc', 'comision_gerente')) {
  $sql = "INSERT INTO ventas_payjoy_tc
            (id_usuario, id_sucursal, nombre_cliente, tag, comision, comision_gerente, comentarios, fecha_venta)
          VALUES (?,?,?,?,?,?,?,NOW())";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("iissdds", $idUsuario, $idSucursal, $nombreCliente, $tag, $comision, $comisionGerente, $comentarios);
} else {
  // Fallback por si no se pudo crear la columna; mantiene comision=0.00
  $sql = "INSERT INTO ventas_payjoy_tc
            (id_usuario, id_sucursal, nombre_cliente, tag, comision, comentarios, fecha_venta)
          VALUES (?,?,?,?,?,?,NOW())";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("iissds", $idUsuario, $idSucursal, $nombreCliente, $tag, $comision, $comentarios);
}

$stmt->execute();
$stmt->close();

header("Location: historial_payjoy_tc.php?msg=" . urlencode("✅ Venta PayJoy TC registrada con comisiones iniciales en $0.00"));
exit();
