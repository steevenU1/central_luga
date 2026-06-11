<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/export_excel_helper.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->set_charset("utf8mb4");

$ROL         = $_SESSION['rol'] ?? '';
$ROL_N       = strtolower(trim((string)$ROL));
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);
$ID_SUBDIS   = (int)($_SESSION['id_subdis'] ?? 0);

$PERM_DELETE = ($ROL === 'Admin');

function role_in(string $rolLower, array $listLower): bool {
  return in_array($rolLower, $listLower, true);
}

function column_exists(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param('ss', $table, $column);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}

function table_exists(mysqli $conn, string $table): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
          LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param('s', $table);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}

/* =======================
   Compatibilidad columnas
======================= */
$VENTA_HAS_PROPIEDAD  = column_exists($conn, 'ventas_accesorios', 'propiedad');
$VENTA_HAS_ID_SUBDIS  = column_exists($conn, 'ventas_accesorios', 'id_subdis');
$VENTA_HAS_TAG_EQUIPO = column_exists($conn, 'ventas_accesorios', 'tag_equipo');

$SUBDIS_TAB_OK = table_exists($conn, 'subdistribuidores')
  && column_exists($conn, 'subdistribuidores', 'id')
  && column_exists($conn, 'subdistribuidores', 'nombre_comercial');

/* =======================
   Tabla detalle
======================= */
$DETALLE_TAB = null;
foreach (['detalle_venta_accesorios','detalle_venta_accesorio','detalle_venta_acc','detalle_venta'] as $cand) {
  if (
    table_exists($conn, $cand)
    && column_exists($conn, $cand, 'id_venta')
    && column_exists($conn, $cand, 'id_producto')
    && column_exists($conn, $cand, 'cantidad')
  ) {
    $DETALLE_TAB = $cand;
    break;
  }
}

/* =======================
   Columna fecha
======================= */
$DATE_COL = 'created_at';
foreach (['created_at','fecha_venta','fecha','fecha_registro'] as $c) {
  if (column_exists($conn, 'ventas_accesorios', $c)) {
    $DATE_COL = $c;
    break;
  }
}

/* =======================
   Scope por rol
======================= */
$scopeWhere = [];
$scopeParams = [];
$scopeTypes = '';

$isSubdisAdmin     = role_in($ROL_N, ['subdis_admin','subdistribuidor_admin','subdisadmin','subdis-admin']);
$isSubdisGerente   = role_in($ROL_N, ['subdis_gerente','subdisgerente','subdis-gerente']);
$isSubdisEjecutivo = role_in($ROL_N, ['subdis_ejecutivo','subdisejecutivo','subdis-ejecutivo']);
$isSubdisRole      = ($isSubdisAdmin || $isSubdisGerente || $isSubdisEjecutivo);

if ($isSubdisRole) {
  if ($VENTA_HAS_PROPIEDAD) {
    $scopeWhere[] = "v.propiedad = ?";
    $scopeParams[] = "Subdistribuidor";
    $scopeTypes .= "s";
  }

  if ($VENTA_HAS_ID_SUBDIS && $ID_SUBDIS > 0) {
    $scopeWhere[] = "v.id_subdis = ?";
    $scopeParams[] = $ID_SUBDIS;
    $scopeTypes .= "i";
  } elseif ($VENTA_HAS_ID_SUBDIS) {
    $scopeWhere[] = "v.id_usuario = ?";
    $scopeParams[] = $ID_USUARIO;
    $scopeTypes .= "i";
  }

  if ($isSubdisGerente) {
    $scopeWhere[] = "v.id_sucursal = ?";
    $scopeParams[] = $ID_SUCURSAL;
    $scopeTypes .= "i";
  } elseif ($isSubdisEjecutivo) {
    $scopeWhere[] = "v.id_usuario = ?";
    $scopeParams[] = $ID_USUARIO;
    $scopeTypes .= "i";
  }
} else {
  switch ($ROL) {
    case 'Ejecutivo':
      $scopeWhere[] = "v.id_usuario = ?";
      $scopeParams[] = $ID_USUARIO;
      $scopeTypes .= "i";
      break;

    case 'Gerente':
      $scopeWhere[] = "v.id_sucursal = ?";
      $scopeParams[] = $ID_SUCURSAL;
      $scopeTypes .= "i";
      break;
  }
}

/* =======================
   Filtros
======================= */
$hoy = date('Y-m-d');
$inicioDefault = date('Y-m-01');

$fecha_ini  = $_GET['fecha_ini'] ?? $inicioDefault;
$fecha_fin  = $_GET['fecha_fin'] ?? $hoy;
$q          = trim($_GET['q'] ?? '');
$tag_equipo = trim($_GET['tag_equipo'] ?? '');
$forma      = $_GET['forma'] ?? '';
$orden      = $_GET['orden'] ?? 'fecha_desc';
$propiedad  = $_GET['propiedad'] ?? '';
$subdis_id  = (int)($_GET['subdis_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_ini)) $fecha_ini = $inicioDefault;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin)) $fecha_fin = $hoy;
if (!in_array($propiedad, ['', 'Luga', 'Subdistribuidor'], true)) $propiedad = '';

$fecha_fin_inclusive = $fecha_fin . ' 23:59:59';

$filtrosWhere = [];
$filtrosParams = [];
$filtrosTypes = '';

$filtrosWhere[] = "v.`$DATE_COL` >= ?";
$filtrosParams[] = $fecha_ini . ' 00:00:00';
$filtrosTypes .= 's';

$filtrosWhere[] = "v.`$DATE_COL` <= ?";
$filtrosParams[] = $fecha_fin_inclusive;
$filtrosTypes .= 's';

if ($forma !== '' && in_array($forma, ['Efectivo','Tarjeta','Mixto'], true)) {
  $filtrosWhere[] = "v.forma_pago = ?";
  $filtrosParams[] = $forma;
  $filtrosTypes .= 's';
}

if ($q !== '') {
  $filtrosWhere[] = "(v.tag LIKE ? OR v.nombre_cliente LIKE ? OR v.telefono LIKE ? OR u.nombre LIKE ? OR s.nombre LIKE ?)";
  $like = '%' . $q . '%';
  array_push($filtrosParams, $like, $like, $like, $like, $like);
  $filtrosTypes .= 'sssss';
}

if ($tag_equipo !== '' && $VENTA_HAS_TAG_EQUIPO) {
  $filtrosWhere[] = "v.tag_equipo LIKE ?";
  $filtrosParams[] = '%' . $tag_equipo . '%';
  $filtrosTypes .= 's';
}

if ($PERM_DELETE && $VENTA_HAS_PROPIEDAD && $propiedad !== '') {
  $filtrosWhere[] = "v.propiedad = ?";
  $filtrosParams[] = $propiedad;
  $filtrosTypes .= 's';
}

if ($PERM_DELETE && $VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK && $subdis_id > 0) {
  $filtrosWhere[] = "v.id_subdis = ?";
  $filtrosParams[] = $subdis_id;
  $filtrosTypes .= 'i';
}

/* =======================
   WHERE final
======================= */
$where = [];
$params = [];
$types = '';

if ($scopeWhere) {
  $where[] = '(' . implode(' AND ', $scopeWhere) . ')';
  $params = array_merge($params, $scopeParams);
  $types .= $scopeTypes;
}

if ($filtrosWhere) {
  $where[] = '(' . implode(' AND ', $filtrosWhere) . ')';
  $params = array_merge($params, $filtrosParams);
  $types .= $filtrosTypes;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* =======================
   Orden
======================= */
$ordenMap = [
  'fecha_desc'   => "v.`$DATE_COL` DESC",
  'fecha_asc'    => "v.`$DATE_COL` ASC",
  'total_desc'   => "v.total DESC",
  'total_asc'    => "v.total ASC",
  'cliente_asc'  => "v.nombre_cliente ASC",
  'cliente_desc' => "v.nombre_cliente DESC"
];

$orderBy = $ordenMap[$orden] ?? $ordenMap['fecha_desc'];

/* =======================
   Joins
======================= */
$joinsBase = "
  LEFT JOIN usuarios u ON u.id = v.id_usuario
  LEFT JOIN sucursales s ON s.id = v.id_sucursal
";

if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) {
  $joinsBase .= "
  LEFT JOIN subdistribuidores sd ON sd.id = v.id_subdis
  ";
}

/* =======================
   Encabezados iguales al CSV
======================= */
$headers = [
  'Folio',
  'TAG',
  'TAG EQUIPO',
  'Cliente',
  'Teléfono',
  'Usuario',
  'Sucursal',
  'Forma de pago'
];

if ($VENTA_HAS_PROPIEDAD) $headers[] = 'Propiedad';
if ($VENTA_HAS_ID_SUBDIS) $headers[] = 'ID Subdis';
if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) $headers[] = 'Subdis';

$headers = array_merge($headers, [
  'Accesorio',
  'Nombre comercial',
  'Cantidad',
  'Precio unitario',
  'Subtotal renglón',
  'Total venta',
  'Fecha',
  'IMEI1',
  'IMEI2'
]);

/* =======================
   SELECT dinámico
======================= */
$selExtra = "";
if ($VENTA_HAS_PROPIEDAD) $selExtra .= ", v.propiedad";
if ($VENTA_HAS_ID_SUBDIS) $selExtra .= ", v.id_subdis";
if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) $selExtra .= ", sd.nombre_comercial AS subdis_nombre";

if ($DETALLE_TAB) {
  $sql = "
    SELECT
      v.id AS folio,
      v.tag,
      " . ($VENTA_HAS_TAG_EQUIPO ? "v.tag_equipo" : "'' AS tag_equipo") . ",
      v.nombre_cliente,
      v.telefono,
      COALESCE(u.nombre, CONCAT('Usuario #', v.id_usuario)) AS usuario,
      COALESCE(s.nombre, CONCAT('Sucursal #', v.id_sucursal)) AS sucursal,
      v.forma_pago
      {$selExtra},
      v.total AS total_venta,
      v.`$DATE_COL` AS fecha,
      d.id_producto,
      d.cantidad,
      d.precio_unitario,
      (d.cantidad * d.precio_unitario) AS subtotal_renglon,
      TRIM(CONCAT(p.marca, ' ', p.modelo, ' ', COALESCE(p.color, ''))) AS accesorio,
      p.nombre_comercial,
      p.imei1,
      p.imei2
    FROM ventas_accesorios v
    $joinsBase
    INNER JOIN {$DETALLE_TAB} d ON d.id_venta = v.id
    LEFT JOIN productos p ON p.id = d.id_producto
    $whereSQL
    ORDER BY $orderBy, v.id ASC, d.id ASC
  ";
} else {
  $sql = "
    SELECT
      v.id AS folio,
      v.tag,
      " . ($VENTA_HAS_TAG_EQUIPO ? "v.tag_equipo" : "'' AS tag_equipo") . ",
      v.nombre_cliente,
      v.telefono,
      COALESCE(u.nombre, CONCAT('Usuario #', v.id_usuario)) AS usuario,
      COALESCE(s.nombre, CONCAT('Sucursal #', v.id_sucursal)) AS sucursal,
      v.forma_pago
      {$selExtra},
      v.total AS total_venta,
      v.`$DATE_COL` AS fecha,
      '' AS id_producto,
      0 AS cantidad,
      0 AS precio_unitario,
      0 AS subtotal_renglon,
      '—' AS accesorio,
      '' AS nombre_comercial,
      '' AS imei1,
      '' AS imei2
    FROM ventas_accesorios v
    $joinsBase
    $whereSQL
    ORDER BY $orderBy, v.id ASC
  ";
}

$st = $conn->prepare($sql);
if ($types !== '') {
  $st->bind_param($types, ...$params);
}
$st->execute();
$rs = $st->get_result();

$data = [];

while ($r = $rs->fetch_assoc()) {
  $row = [
    $r['folio'],
    $r['tag'],
    $r['tag_equipo'] ?? '',
    $r['nombre_cliente'],
    $r['telefono'],
    $r['usuario'],
    $r['sucursal'],
    $r['forma_pago']
  ];

  if ($VENTA_HAS_PROPIEDAD) $row[] = $r['propiedad'] ?? '';
  if ($VENTA_HAS_ID_SUBDIS) $row[] = $r['id_subdis'] ?? '';
  if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) $row[] = $r['subdis_nombre'] ?? '';

  $row = array_merge($row, [
    $r['accesorio'] ?: '—',
    $r['nombre_comercial'] ?? '',
    (int)($r['cantidad'] ?? 0),
    (float)($r['precio_unitario'] ?? 0),
    (float)($r['subtotal_renglon'] ?? 0),
    (float)($r['total_venta'] ?? 0),
    $r['fecha'],
    $r['imei1'] ?? '',
    $r['imei2'] ?? ''
  ]);

  $data[] = $row;
}

$st->close();

/* =======================
   Exportar con helper Zentral
======================= */
$titulo = 'Historial de Ventas de Accesorios';
$subtitulo = 'Periodo: ' . date('d/m/Y', strtotime($fecha_ini)) . ' al ' . date('d/m/Y', strtotime($fecha_fin));

zentralExportarExcelSimple(
  $titulo,
  $subtitulo,
  $headers,
  $data,
  'ventas_accesorios.xlsx'
);

exit;