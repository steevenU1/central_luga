<?php
// export_detalle_nomina_usuario.php
// Descarga CSV con el detalle de comisiones de un ejecutivo:
// - Equipos (detalle_venta/ventas) con comision + (comision_especial si aplica)
// - SIMs (ventas_sims) con comision_ejecutivo
// - Tarjeta / PayJoy TC (ventas_payjoy_tc) con comision

session_start();
if (!isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo "No autenticado";
    exit;
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* === helpers básicos (copiados/light del reporte) === */
function columnExists(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME='$t' AND COLUMN_NAME='$c'
            LIMIT 1";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function dateColVentas(mysqli $conn): string {
    if (columnExists($conn,'ventas','fecha_venta')) return 'fecha_venta';
    if (columnExists($conn,'ventas','created_at'))  return 'created_at';
    return 'fecha';
}

// Para conteo de equipos (igual que en el reporte)
function notModemSQL(mysqli $conn): string {
    if (columnExists($conn, 'productos', 'tipo')) {
        return "LOWER(p.tipo) NOT IN ('modem','módem','mifi','mi-fi','hotspot','router')";
    }
    $cols = [];
    foreach (['marca','modelo','descripcion','nombre_comercial','categoria','codigo_producto'] as $col) {
        if (columnExists($conn,'productos',$col)) $cols[] = "LOWER(COALESCE(p.$col,''))";
    }
    if (!$cols) return '1=1';
    $hay = "CONCAT(" . implode(", ' ', ", $cols) . ")";
    return "$hay NOT LIKE '%modem%'
            AND $hay NOT LIKE '%módem%'
            AND $hay NOT LIKE '%mifi%'
            AND $hay NOT LIKE '%mi-fi%'
            AND $hay NOT LIKE '%hotspot%'
            AND $hay NOT LIKE '%router%'";
}

function countEquipos(mysqli $conn, int $idUsuario, string $ini, string $fin): int {
    $colFecha = dateColVentas($conn);
    $pIni = $ini . ' 00:00:00';
    $pFin = $fin . ' 23:59:59';

    $tieneDet = columnExists($conn,'detalle_venta','id');
    if ($tieneDet) {
        $joinProd = columnExists($conn,'productos','id') ? "LEFT JOIN productos p ON p.id = d.id_producto" : "";
        $condNoModem = notModemSQL($conn);
        $sql = "
          SELECT COUNT(d.id) AS c
          FROM detalle_venta d
          INNER JOIN ventas v ON v.id = d.id_venta
          $joinProd
          WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? AND ($condNoModem)
        ";
        $st = $conn->prepare($sql);
        $st->bind_param("iss", $idUsuario, $pIni, $pFin);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return (int)($row['c'] ?? 0);
    } else {
        $tieneTipoVenta = columnExists($conn,'ventas','tipo_venta');
        $estatusCond = columnExists($conn,'ventas','estatus')
          ? " AND (v.estatus IS NULL OR v.estatus NOT IN ('Cancelada','Cancelado','cancelada','cancelado'))"
          : "";
        if ($tieneTipoVenta) {
            $sql = "
              SELECT COALESCE(SUM(CASE WHEN LOWER(v.tipo_venta) LIKE '%combo%' THEN 2 ELSE 1 END),0) AS c
              FROM ventas v
              WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? $estatusCond
            ";
        } else {
            $sql = "
              SELECT COUNT(*) AS c
              FROM ventas v
              WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? $estatusCond
            ";
        }
        $st = $conn->prepare($sql);
        $st->bind_param("iss", $idUsuario, $pIni, $pFin);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return (int)($row['c'] ?? 0);
    }
}

/* === recibir parámetros === */
$uid = (int)($_GET['uid'] ?? 0);
$ini = $_GET['ini'] ?? '';
$fin = $_GET['fin'] ?? '';

if ($uid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
    http_response_code(400);
    echo "Parámetros inválidos";
    exit;
}

$dtIni = $ini . ' 00:00:00';
$dtFin = $fin . ' 23:59:59';

/* === traer datos del usuario (rol, sucursal) === */
$colUserSuc = null;
if (columnExists($conn, 'usuarios', 'id_sucursal')) $colUserSuc = 'id_sucursal';
if (!$colUserSuc && columnExists($conn, 'usuarios', 'sucursal')) $colUserSuc = 'sucursal';

$sqlU = "
  SELECT u.id, u.nombre, u.rol, u.sueldo,
         " . ($colUserSuc ? "u.$colUserSuc" : "NULL") . " AS id_sucursal,
         s.nombre AS sucursal_nombre
  FROM usuarios u
  LEFT JOIN sucursales s ON s.id = " . ($colUserSuc ? "u.$colUserSuc" : "0") . "
  WHERE u.id = ?
  LIMIT 1
";
$stU = $conn->prepare($sqlU);
$stU->bind_param("i", $uid);
$stU->execute();
$usr = $stU->get_result()->fetch_assoc();
$stU->close();

if (!$usr) {
    http_response_code(404);
    echo "Usuario no encontrado";
    exit;
}

$rolUsuario = (string)$usr['rol'];
$idSucursal = (int)($usr['id_sucursal'] ?? 0);

/* === cuota_unidades para este usuario (como en reporte) === */
$cuota_unid = null;
if ($idSucursal > 0 && columnExists($conn,'cuotas_semanales_sucursal','cuota_unidades')) {
    $sqlC = "
      SELECT cuota_unidades
      FROM cuotas_semanales_sucursal
      WHERE id_sucursal=? AND semana_inicio<=? AND semana_fin>=?
      ORDER BY id DESC
      LIMIT 1
    ";
    $stC = $conn->prepare($sqlC);
    $stC->bind_param("iss", $idSucursal, $ini, $fin);
    $stC->execute();
    $rowC = $stC->get_result()->fetch_assoc();
    $stC->close();
    if ($rowC) $cuota_unid = (int)$rowC['cuota_unidades'];
}

/* === eq_cnt y aplicaEspecial (igual que reporte) === */
$eq_cnt = countEquipos($conn, $uid, $ini, $fin);
$aplicaEspecial = (strcasecmp($rolUsuario,'Ejecutivo')===0
                   && $cuota_unid !== null
                   && $eq_cnt >= $cuota_unid);

/* === preparar salida CSV === */
$nombreFile = 'detalle_nomina_' . $uid . '_' . $ini . '_' . $fin . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$nombreFile.'"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// BOM UTF-8 para Excel
fwrite($out, "\xEF\xBB\xBF");

/* Encabezado general */
fputcsv($out, [
    'tipo_registro',      // EQUIPO / SIM / TC
    'fecha',
    'sucursal',
    'folio_tag',
    'detalle',
    'tipo_venta',
    'monto_venta',
    'comision_contabilizada'
]);

/* === 1) EQUIPOS (detalle_venta + ventas) === */
$colFechaV = dateColVentas($conn);
$hasComEsp = columnExists($conn,'detalle_venta','comision_especial');
$hasTag    = columnExists($conn,'ventas','tag');

$joinProd = columnExists($conn,'productos','id')
  ? "LEFT JOIN productos p ON p.id = d.id_producto"
  : "";

$colsProd = [];
if (columnExists($conn,'productos','marca'))      $colsProd[] = "p.marca";
if (columnExists($conn,'productos','modelo'))     $colsProd[] = "p.modelo";
if (columnExists($conn,'productos','color'))      $colsProd[] = "p.color";
if (columnExists($conn,'productos','capacidad'))  $colsProd[] = "p.capacidad";
$descProd = $colsProd ? ("TRIM(CONCAT_WS(' ', ".implode(',', $colsProd)."))") : "NULL";

$sqlEq = "
  SELECT
    v.id            AS id_venta,
    v.$colFechaV    AS fecha,
    s.nombre        AS sucursal,
    ".($hasTag ? "v.tag" : "v.id")." AS folio_tag,
    $descProd       AS producto,
    ".(columnExists($conn,'ventas','tipo_venta') ? "v.tipo_venta" : "NULL")." AS tipo_venta,
    v.precio_venta  AS monto_venta,
    d.comision      AS comision,
    ".($hasComEsp ? "d.comision_especial" : "0")." AS comision_especial
  FROM detalle_venta d
  INNER JOIN ventas v    ON v.id = d.id_venta
  LEFT JOIN sucursales s ON s.id = v.id_sucursal
  $joinProd
  WHERE v.id_usuario = ?
    AND v.$colFechaV BETWEEN ? AND ?
  ORDER BY v.$colFechaV ASC, v.id ASC, d.id ASC
";

$stEq = $conn->prepare($sqlEq);
$stEq->bind_param("iss", $uid, $dtIni, $dtFin);
$stEq->execute();
$resEq = $stEq->get_result();

while ($row = $resEq->fetch_assoc()) {
    $com = (float)$row['comision'];
    if ($aplicaEspecial && $hasComEsp) {
        $com += (float)$row['comision_especial'];
    }

    $detalle = $row['producto'] ?: 'Equipo';

    fputcsv($out, [
        'EQUIPO',
        $row['fecha'],
        $row['sucursal'] ?? '',
        $row['folio_tag'] ?? '',
        $detalle,
        $row['tipo_venta'] ?? '',
        $row['monto_venta'] ?? 0,
        $com
    ]);
}
$stEq->close();

/* === 2) SIMs (ventas_sims) === */
if (columnExists($conn, 'ventas_sims', 'id')) {

    // columnas dinámicas
    $hasTipoVenta = columnExists($conn,'ventas_sims','tipo_venta');
    $hasTagS      = columnExists($conn,'ventas_sims','tag');      // por si en otra central lo usas
    $hasTipoSim   = columnExists($conn,'ventas_sims','tipo_sim');
    $hasOper      = columnExists($conn,'ventas_sims','operador');

    // monto: en Luga se llama precio_total
    if (columnExists($conn, 'ventas_sims', 'precio_total')) {
        $colMontoSIM = "vs.precio_total";
    } elseif (columnExists($conn, 'ventas_sims', 'precio_venta')) {
        $colMontoSIM = "vs.precio_venta";
    } else {
        $colMontoSIM = "0";
    }

    // etiqueta SIM (operador / tipo_sim)
    $colDetalleSIM = "NULL";
    if ($hasTipoSim) {
        $colDetalleSIM = "vs.tipo_sim";
    } elseif ($hasOper) {
        $colDetalleSIM = "vs.operador";
    }

    $sqlSim = "
      SELECT
        vs.id,
        vs.fecha_venta      AS fecha,
        s.nombre            AS sucursal,
        ".($hasTagS ? "vs.tag" : "vs.id")." AS folio_tag,
        $colDetalleSIM      AS sim_info,
        ".($hasTipoVenta ? "vs.tipo_venta" : "NULL")." AS tipo_venta,
        $colMontoSIM        AS monto_venta,
        vs.comision_ejecutivo AS comision
      FROM ventas_sims vs
      LEFT JOIN sucursales s ON s.id = vs.id_sucursal
      WHERE vs.id_usuario = ?
        AND vs.fecha_venta BETWEEN ? AND ?
      ORDER BY vs.fecha_venta ASC, vs.id ASC
    ";

    $stSim = $conn->prepare($sqlSim);
    $stSim->bind_param("iss", $uid, $dtIni, $dtFin);
    $stSim->execute();
    $resSim = $stSim->get_result();

    while ($row = $resSim->fetch_assoc()) {
        $detalle = 'SIM';
        if (!empty($row['sim_info'])) {
            $detalle .= ' ' . $row['sim_info'];
        }

        fputcsv($out, [
            'SIM',
            $row['fecha'],
            $row['sucursal'] ?? '',
            $row['folio_tag'] ?? '',
            $detalle,
            $row['tipo_venta'] ?? '',
            $row['monto_venta'] ?? 0,
            $row['comision'] ?? 0
        ]);
    }
    $stSim->close();
}

/* === 3) Tarjeta / PayJoy TC (ventas_payjoy_tc) === */
if (columnExists($conn, 'ventas_payjoy_tc', 'id')) {

    $hasTagT = columnExists($conn,'ventas_payjoy_tc','tag');

    $sqlTC = "
      SELECT
        vtc.id,
        vtc.fecha_venta     AS fecha,
        s.nombre            AS sucursal,
        ".($hasTagT ? "vtc.tag" : "vtc.id")." AS folio_tag,
        vtc.comision        AS comision
      FROM ventas_payjoy_tc vtc
      LEFT JOIN sucursales s ON s.id = vtc.id_sucursal
      WHERE vtc.id_usuario = ?
        AND vtc.fecha_venta BETWEEN ? AND ?
      ORDER BY vtc.fecha_venta ASC, vtc.id ASC
    ";

    $stTC = $conn->prepare($sqlTC);
    $stTC->bind_param("iss", $uid, $dtIni, $dtFin);
    $stTC->execute();
    $resTC = $stTC->get_result();

    while ($row = $resTC->fetch_assoc()) {
        fputcsv($out, [
            'TC',
            $row['fecha'],
            $row['sucursal'] ?? '',
            $row['folio_tag'] ?? '',
            'Tarjeta / PayJoy TC',
            'TC',
            0,                            // no tenemos monto de venta en esa tabla
            $row['comision'] ?? 0
        ]);
    }
    $stTC->close();
}

fclose($out);
exit;
