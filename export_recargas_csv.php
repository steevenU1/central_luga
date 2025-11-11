<?php
// export_recargas_csv.php — Export CSV de recargas promocionales
// Uso: export_recargas_csv.php?promo=ID[&status=pending|r1|r2|completas|all][&q=texto]
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

// ===== Permisos =====
$ROL         = $_SESSION['rol'] ?? '';
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
if (!in_array($ROL, ['Ejecutivo','Gerente','Admin','Logistica'], true)) {
  http_response_code(403);
  exit('403');
}

// ===== Parámetros =====
$promoId = (int)($_GET['promo'] ?? 0);
if ($promoId <= 0) {
  http_response_code(400);
  exit('Falta parámetro promo');
}
$status = strtolower(trim($_GET['status'] ?? 'all')); // all|pendientes|r1|r2|completas
$q      = trim($_GET['q'] ?? '');

// ===== Filtros SQL =====
$where = "rpc.id_promo = $promoId";
if (!in_array($ROL, ['Admin','Logistica'], true)) {
  $where .= " AND rpc.id_sucursal = " . (int)$ID_SUCURSAL;
}
if ($q !== '') {
  $qEsc = $conn->real_escape_string($q);
  $where .= " AND (rpc.telefono_cliente LIKE '%$qEsc%' OR rpc.nombre_cliente LIKE '%$qEsc%')";
}
switch ($status) {
  case 'pendientes':
    $where .= " AND ( (rpc.rec1_status IS NULL OR rpc.rec1_status <> 'confirmada') OR (rpc.rec2_status IS NULL OR rpc.rec2_status <> 'confirmada') )";
    break;
  case 'r1':
    $where .= " AND rpc.rec1_status = 'confirmada' AND (rpc.rec2_status IS NULL OR rpc.rec2_status <> 'confirmada')";
    break;
  case 'r2':
    $where .= " AND rpc.rec2_status = 'confirmada' AND (rpc.rec1_status IS NULL OR rpc.rec1_status <> 'confirmada')";
    break;
  case 'completas':
    $where .= " AND rpc.rec1_status = 'confirmada' AND rpc.rec2_status = 'confirmada'";
    break;
  case 'all':
  default:
    // sin filtro extra
    break;
}

// ===== Datos de la promo (para nombre del archivo) =====
$promo = $conn->query("SELECT nombre, origen, fecha_inicio, fecha_fin FROM recargas_promo WHERE id=$promoId")->fetch_assoc();
$promoName   = $promo ? preg_replace('/[^A-Za-z0-9_-]+/','_', $promo['nombre']) : 'promo';
$promoOrigen = $promo['origen'] ?? '';
$rango       = $promo ? ($promo['fecha_inicio'].'_a_'.$promo['fecha_fin']) : date('Y-m-d');

// ===== Consulta (con nombres resueltos) =====
$sql = "
  SELECT
    rpc.id,
    rpc.id_promo,
    rpc.id_venta,

    rpc.id_sucursal,
    COALESCE(s.nombre, CONCAT('Sucursal #', rpc.id_sucursal)) AS sucursal_nombre,

    rpc.nombre_cliente,
    rpc.telefono_cliente,
    rpc.fecha_venta,

    rpc.rec1_status,
    rpc.rec1_at,
    rpc.rec1_by,
    COALESCE(u1.nombre, CASE WHEN rpc.rec1_by IS NULL THEN NULL ELSE CONCAT('Usuario #', rpc.rec1_by) END) AS rec1_by_nombre,
    rpc.rec1_comprobante_path,

    rpc.rec2_status,
    rpc.rec2_at,
    rpc.rec2_by,
    COALESCE(u2.nombre, CASE WHEN rpc.rec2_by IS NULL THEN NULL ELSE CONCAT('Usuario #', rpc.rec2_by) END) AS rec2_by_nombre,
    rpc.rec2_comprobante_path,

    p.nombre  AS promo_nombre,
    p.origen  AS promo_origen,
    p.fecha_inicio,
    p.fecha_fin
  FROM recargas_promo_clientes rpc
  JOIN recargas_promo p ON p.id = rpc.id_promo
  LEFT JOIN sucursales s ON s.id = rpc.id_sucursal
  LEFT JOIN usuarios  u1 ON u1.id = rpc.rec1_by
  LEFT JOIN usuarios  u2 ON u2.id = rpc.rec2_by
  WHERE $where
  ORDER BY rpc.id ASC
";

// ===== Salida CSV (streaming) =====
$fname = "recargas_{$promoName}_{$rango}";
if (!in_array($ROL, ['Admin','Logistica'], true)) {
  $fname .= "_suc{$ID_SUCURSAL}";
}
$fname .= "_".date('Ymd_His').".csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');
header('Pragma: no-cache');
header('Expires: 0');

// BOM para Excel (UTF-8)
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Encabezados (incluye nombres “resueltos” y también IDs por trazabilidad)
$headers = [
  'id',
  'id_promo', 'promo_nombre', 'promo_origen',
  'id_venta',
  'id_sucursal', 'sucursal_nombre',
  'nombre_cliente', 'telefono_cliente', 'fecha_venta',
  'rec1_status', 'rec1_at', 'rec1_by', 'rec1_by_nombre', 'rec1_comprobante_path',
  'rec2_status', 'rec2_at', 'rec2_by', 'rec2_by_nombre', 'rec2_comprobante_path',
  // extras útiles
  'completa', 'solo_r1', 'solo_r2', 'pendiente'
];
fputcsv($out, $headers);

// Stream filas
if ($res = $conn->query($sql, MYSQLI_USE_RESULT)) {
  while ($row = $res->fetch_assoc()) {
    $r1ok = (isset($row['rec1_status']) && $row['rec1_status'] === 'confirmada');
    $r2ok = (isset($row['rec2_status']) && $row['rec2_status'] === 'confirmada');

    $completa  = ($r1ok && $r2ok) ? 1 : 0;
    $solo_r1   = ($r1ok && !$r2ok) ? 1 : 0;
    $solo_r2   = ($r2ok && !$r1ok) ? 1 : 0;
    $pendiente = (!$r1ok || !$r2ok) ? 1 : 0;

    fputcsv($out, [
      $row['id'],
      $row['id_promo'], $row['promo_nombre'], $row['promo_origen'],
      $row['id_venta'],
      $row['id_sucursal'], $row['sucursal_nombre'],
      $row['nombre_cliente'], $row['telefono_cliente'], $row['fecha_venta'],
      $row['rec1_status'], $row['rec1_at'], $row['rec1_by'], $row['rec1_by_nombre'], $row['rec1_comprobante_path'],
      $row['rec2_status'], $row['rec2_at'], $row['rec2_by'], $row['rec2_by_nombre'], $row['rec2_comprobante_path'],
      $completa, $solo_r1, $solo_r2, $pendiente
    ]);
  }
  $res->close();
}
fclose($out);
exit;
