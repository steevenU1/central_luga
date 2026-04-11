<?php
// ajax_promo_descuento_check.php
// Detecta si un inventario (equipo principal) tiene promo de descuento activa.
// Soporta promos por porcentaje y promos de precio fijo limitadas por financiera.

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['ok' => false, 'message' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once __DIR__ . '/db.php';

function respond(array $arr): void {
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

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

// Normaliza código para que no falle por espacios raros / guiones distintos / mayúsculas
function normCodigo(string $s): string {
  $s = str_replace("\xC2\xA0", " ", $s);    // NBSP -> space
  $s = str_replace(["–", "—", "-"], "-", $s);
  $s = preg_replace('/\s+/', ' ', $s);
  $s = trim($s);
  if (function_exists('mb_strtoupper')) {
    $s = mb_strtoupper($s, 'UTF-8');
  } else {
    $s = strtoupper($s);
  }
  return $s;
}

$idInv      = (int)($_POST['id_inventario'] ?? 0);
$financiera = trim((string)($_POST['financiera'] ?? ''));

if ($idInv <= 0) {
  respond(['ok' => false, 'message' => 'id_inventario inválido']);
}

$hoy = date('Y-m-d');

// 1) Obtener codigo_producto del inventario
$st = $conn->prepare("
  SELECT COALESCE(p.codigo_producto,'') AS codigo
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  WHERE i.id = ?
  LIMIT 1
");
$st->bind_param("i", $idInv);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

$codigoRaw = (string)($row['codigo'] ?? '');
$codigo    = normCodigo($codigoRaw);

if ($codigo === '') {
  respond(['ok' => true, 'aplica' => false, 'message' => 'Sin código de producto']);
}

// 2) Armar SQL según columnas disponibles
$hasTipoPrecio   = columnExists($conn, 'promos_equipos_descuento', 'tipo_precio_combo');
$hasPrecioFijo   = columnExists($conn, 'promos_equipos_descuento', 'precio_combo_fijo');
$hasMensajeModal = columnExists($conn, 'promos_equipos_descuento', 'mensaje_modal');
$hasFinanciera   = columnExists($conn, 'promos_equipos_descuento', 'financiera');

$colTipoPrecio   = $hasTipoPrecio   ? "COALESCE(pr.tipo_precio_combo,'porcentaje') AS tipo_precio_combo" : "'porcentaje' AS tipo_precio_combo";
$colPrecioFijo   = $hasPrecioFijo   ? "COALESCE(pr.precio_combo_fijo,0) AS precio_combo_fijo" : "0 AS precio_combo_fijo";
$colMensajeModal = $hasMensajeModal ? "COALESCE(pr.mensaje_modal,'') AS mensaje_modal" : "'' AS mensaje_modal";
$colFinanciera   = $hasFinanciera   ? "COALESCE(pr.financiera,'') AS financiera" : "'' AS financiera";

$filtroFinanciera = "";
if ($hasFinanciera) {
  $filtroFinanciera = "
    AND (
      pr.financiera IS NULL
      OR TRIM(pr.financiera) = ''
      OR UPPER(TRIM(pr.financiera)) = UPPER(TRIM(?))
    )
  ";
}

$sql = "
  SELECT
    pr.id,
    pr.nombre,
    pr.modo,
    pr.porcentaje_descuento,
    pr.permite_combo,
    pr.permite_doble_venta,
    $colTipoPrecio,
    $colPrecioFijo,
    $colMensajeModal,
    $colFinanciera,
    COALESCE(pp.codigo_producto,'') AS codigo_pp
  FROM promos_equipos_descuento pr
  INNER JOIN promos_equipos_descuento_principal pp ON pp.promo_id = pr.id
  WHERE pr.activa = 1
    AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio <= ?)
    AND (pr.fecha_fin    IS NULL OR pr.fecha_fin    >= ?)
    AND pp.activo = 1
    AND UPPER(TRIM(REPLACE(pp.codigo_producto, CHAR(194,160), ' '))) = ?
    $filtroFinanciera
  ORDER BY pr.id DESC
  LIMIT 1
";

$st2 = $conn->prepare($sql);
if (!$st2) {
  respond(['ok' => false, 'message' => 'Error preparando consulta de promo']);
}

if ($hasFinanciera) {
  $st2->bind_param("ssss", $hoy, $hoy, $codigo, $financiera);
} else {
  $st2->bind_param("sss", $hoy, $hoy, $codigo);
}

$st2->execute();
$promo = $st2->get_result()->fetch_assoc();
$st2->close();

if (!$promo) {
  respond([
    'ok' => true,
    'aplica' => false,
    'principal_codigo' => $codigo,
    'financiera' => $financiera
  ]);
}

// 3) Contar combos configurados para esa promo
$promoId = (int)$promo['id'];
$n = 0;
if ($rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promos_equipos_descuento_combo' LIMIT 1")) {
  if ($rs->num_rows > 0) {
    $st3 = $conn->prepare("
      SELECT COUNT(*) AS n
      FROM promos_equipos_descuento_combo
      WHERE promo_id = ? AND activo = 1
    ");
    $st3->bind_param("i", $promoId);
    $st3->execute();
    $n = (int)($st3->get_result()->fetch_assoc()['n'] ?? 0);
    $st3->close();
  }
}

respond([
  'ok' => true,
  'aplica' => true,
  'principal_codigo' => $codigo,
  'financiera' => $financiera,
  'promo' => [
    'id' => $promoId,
    'nombre' => (string)$promo['nombre'],
    'modo' => (string)($promo['modo'] ?? ''),
    'porcentaje_descuento' => (float)($promo['porcentaje_descuento'] ?? 0),
    'permite_combo' => (int)($promo['permite_combo'] ?? 0),
    'permite_doble_venta' => (int)($promo['permite_doble_venta'] ?? 0),
    'tipo_precio_combo' => (string)($promo['tipo_precio_combo'] ?? 'porcentaje'),
    'precio_combo_fijo' => (float)($promo['precio_combo_fijo'] ?? 0),
    'mensaje_modal' => (string)($promo['mensaje_modal'] ?? ''),
    'financiera' => (string)($promo['financiera'] ?? ''),
    'combos_configurados' => $n
  ]
]);
?>