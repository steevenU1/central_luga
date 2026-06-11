<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/export_excel_helper.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

$KARDEX_VIEW = 'kardex_movimientos';

function ymd($s) {
    $s = trim((string)$s);
    if ($s === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d ? $d->format('Y-m-d') : null;
}

$q           = trim($_GET['q'] ?? '');
$desde       = ymd($_GET['desde'] ?? '');
$hasta       = ymd($_GET['hasta'] ?? '');
$tipo        = trim($_GET['tipo'] ?? '');
$suc_origen  = isset($_GET['suc_o']) && $_GET['suc_o'] !== '' ? (int)$_GET['suc_o'] : null;
$suc_destino = isset($_GET['suc_d']) && $_GET['suc_d'] !== '' ? (int)$_GET['suc_d'] : null;

$TIPOS_VALIDOS = [
    'COMPRA_INGRESO',
    'INGRESO_INVENTARIO',
    'TRASPASO_SALIDA',
    'TRASPASO_ENTRADA',
    'VENTA',
    'RETIRO'
];

if ($tipo !== '' && !in_array($tipo, $TIPOS_VALIDOS, true)) {
    $tipo = '';
}

$esc = fn($s) => $conn->real_escape_string($s);

$conds = [];

if ($q !== '') {
    if (ctype_digit($q)) {
        $conds[] = "(km.imei = '".$esc($q)."' OR km.id_producto = ".(int)$q.")";
    } else {
        $conds[] = "km.imei = '".$esc($q)."'";
    }
}

if ($desde) {
    $conds[] = "km.fecha >= '".$esc($desde)." 00:00:00'";
}

if ($hasta) {
    $conds[] = "km.fecha <= '".$esc($hasta)." 23:59:59'";
}

if ($tipo !== '') {
    $conds[] = "km.tipo_movimiento = '".$esc($tipo)."'";
}

if ($suc_origen !== null && $suc_origen > 0) {
    $conds[] = "km.sucursal_origen = ".(int)$suc_origen;
}

if ($suc_destino !== null && $suc_destino > 0) {
    $conds[] = "km.sucursal_destino = ".(int)$suc_destino;
}

$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$sqlMeta = "
    SELECT 
        COUNT(*) AS registros,
        SUM(CASE WHEN km.cantidad > 0 THEN km.cantidad ELSE 0 END) AS entradas,
        SUM(CASE WHEN km.cantidad < 0 THEN -km.cantidad ELSE 0 END) AS salidas
    FROM {$KARDEX_VIEW} km
    {$where}
";

$meta = $conn->query($sqlMeta)->fetch_assoc();

$registros = (int)($meta['registros'] ?? 0);
$entradas  = (int)($meta['entradas'] ?? 0);
$salidas   = (int)($meta['salidas'] ?? 0);
$saldo     = $entradas - $salidas;

$sql = "
    SELECT
        km.fecha,
        km.tipo_movimiento,
        km.fuente,
        km.movimiento_id,
        km.referencia_id,
        km.id_producto,
        p.codigo_producto,
        p.marca,
        p.modelo,
        p.color,
        p.ram,
        p.capacidad,
        km.imei,
        km.cantidad,
        so.nombre AS suc_origen,
        sd.nombre AS suc_destino,
        km.precio_unitario,
        km.usuario_id,
        km.notas
    FROM {$KARDEX_VIEW} km
    LEFT JOIN productos p   ON p.id = km.id_producto
    LEFT JOIN sucursales so ON so.id = km.sucursal_origen
    LEFT JOIN sucursales sd ON sd.id = km.sucursal_destino
    {$where}
    ORDER BY km.fecha DESC, km.movimiento_id DESC
";

$rows = [];

$res = $conn->query($sql);
while ($r = $res->fetch_assoc()) {
    $producto = trim(
        ($r['marca'] ?? '') . ' ' .
        ($r['modelo'] ?? '') . ' ' .
        ($r['color'] ?? '') . ' ' .
        ($r['ram'] ?? '') . ' ' .
        ($r['capacidad'] ?? '')
    );

    $rows[] = [
        'fecha'           => $r['fecha'],
        'tipo'            => $r['tipo_movimiento'],
        'fuente'          => $r['fuente'],
        'mov_id'          => $r['movimiento_id'],
        'ref_id'          => $r['referencia_id'],
        'id_producto'     => $r['id_producto'],
        'codigo'          => $r['codigo_producto'],
        'producto'        => $producto,
        'imei'            => $r['imei'],
        'cantidad'        => (int)$r['cantidad'],
        'sucursal_origen' => $r['suc_origen'],
        'sucursal_destino'=> $r['suc_destino'],
        'precio_unitario' => (float)$r['precio_unitario'],
        'usuario_id'      => $r['usuario_id'],
        'notas'           => $r['notas'],
    ];
}
$res->free();

$spreadsheet = zentralCrearSpreadsheet('Kardex de Productos');
$sheet = $spreadsheet->getActiveSheet();

$subtitulo = 'Movimientos de inventario';
if ($desde || $hasta) {
    $subtitulo .= ' | Periodo: ' . ($desde ?: 'Inicio') . ' al ' . ($hasta ?: 'Actual');
}

$fila = zentralAgregarEncabezado($sheet, 'Kardex de Productos', $subtitulo);

$fila = zentralAgregarKPIs($sheet, [
    'Registros' => number_format($registros),
    'Entradas'  => number_format($entradas),
    'Salidas'   => number_format($salidas),
    'Saldo'     => number_format($saldo),
], $fila);

$headers = [
    'fecha'            => 'Fecha',
    'tipo'             => 'Tipo',
    'fuente'           => 'Fuente',
    'mov_id'           => 'Mov. ID',
    'ref_id'           => 'Referencia',
    'id_producto'      => 'ID Producto',
    'codigo'           => 'Código',
    'producto'         => 'Producto',
    'imei'             => 'IMEI',
    'cantidad'         => 'Cantidad',
    'sucursal_origen'  => 'Sucursal origen',
    'sucursal_destino' => 'Sucursal destino',
    'precio_unitario'  => 'Precio unitario',
    'usuario_id'       => 'Usuario ID',
    'notas'            => 'Notas',
];

$fila = zentralAgregarTabla(
    $sheet,
    $headers,
    $rows,
    $fila,
    [13],
    [4, 5, 6, 10, 14]
);

zentralAutoSize($sheet);
zentralAplicarPie($sheet, $fila);

zentralDescargarExcel($spreadsheet, 'kardex_' . date('Ymd_His') . '.xlsx');