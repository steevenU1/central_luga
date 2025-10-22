<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once 'db.php';

/* ========= Normaliza collation ========= */
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* ========= Diagnóstico ========= */
$ping  = isset($_GET['ping']);
$debug = isset($_GET['debug']);
if ($ping) { header("Content-Type: text/plain; charset=UTF-8"); echo "pong"; exit; }

/* ========= Harden ========= */
@ini_set('zlib.output_compression','Off');
@ini_set('output_buffering','0');
@ini_set('memory_limit','1024M');
@set_time_limit(300);

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function semana_martes_lunes($offset=0){
  $hoy=new DateTime(); $dif=(int)$hoy->format('N')-2; if($dif<0)$dif+=7;
  $ini=(new DateTime())->modify("-{$dif} days")->setTime(0,0,0);
  if($offset>0)$ini->modify('-'.(7*$offset).' days');
  $fin=(clone $ini)->modify('+6 days')->setTime(23,59,59);
  return [$ini->format('Y-m-d'),$fin->format('Y-m-d')];
}
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = '{$t}'
            AND COLUMN_NAME  = '{$c}' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

/* ========= Filtros ========= */
$rol   = $_SESSION['rol'] ?? '';
$idSuc = (int)($_SESSION['id_sucursal'] ?? 0);
$idUsr = (int)($_SESSION['id_usuario'] ?? 0);

$ROLES_CON_SUCURSAL_LIBRE = ['Admin','GerenteZona','Super'];
$sucursalSel = 0;
if (in_array($rol, $ROLES_CON_SUCURSAL_LIBRE, true)) {
  $sucursalSel = (int)($_GET['sucursal'] ?? 0);
} elseif ($rol === 'Gerente') {
  $sucursalSel = $idSuc;
} else {
  $sucursalSel = 0;
}

$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($fechaInicio, $fechaFin) = semana_martes_lunes($semana);

$where  = " WHERE DATE(v.fecha_venta) BETWEEN ? AND ? ";
$params = [$fechaInicio,$fechaFin];
$types  = "ss";

if ($rol === 'Ejecutivo'){
  $where.=" AND v.id_usuario = ? ";
  $params[]=$idUsr; $types.="i";
}
if ($rol === 'Gerente' && $idSuc > 0){
  $where.=" AND v.id_sucursal = ? ";
  $params[]=$idSuc; $types.="i";
}
if (in_array($rol, $ROLES_CON_SUCURSAL_LIBRE, true) && $sucursalSel > 0){
  $where.=" AND v.id_sucursal = ? ";
  $params[]=$sucursalSel; $types.="i";
}
if (!empty($_GET['tipo_venta'])){
  $where.=" AND v.tipo_venta = ? ";
  $params[]=(string)$_GET['tipo_venta']; $types.="s";
}
if (!empty($_GET['usuario'])){
  $where.=" AND v.id_usuario = ? ";
  $params[]=(int)$_GET['usuario']; $types.="i";
}
if (!empty($_GET['buscar'])) {
  $q = "%".$_GET['buscar']."%";
  $where .= " AND (v.nombre_cliente LIKE ? OR v.telefono_cliente LIKE ? OR v.tag LIKE ?
                   OR EXISTS(SELECT 1 FROM detalle_venta dv2 WHERE dv2.id_venta=v.id AND dv2.imei1 LIKE ?))";
  array_push($params,$q,$q,$q,$q); $types.="ssss";
}

/* ========= Referencias ========= */
$hasR1N = hasColumn($conn,'ventas','referencia1_nombre');
$hasR1T = hasColumn($conn,'ventas','referencia1_telefono');
$hasR2N = hasColumn($conn,'ventas','referencia2_nombre');
$hasR2T = hasColumn($conn,'ventas','referencia2_telefono');

$selectRefs = implode(",\n  ", [
  $hasR1N ? "v.referencia1_nombre AS referencia1_nombre" : "'' AS referencia1_nombre",
  $hasR1T ? "v.referencia1_telefono AS referencia1_telefono" : "'' AS referencia1_telefono",
  $hasR2N ? "v.referencia2_nombre AS referencia2_nombre" : "'' AS referencia2_nombre",
  $hasR2T ? "v.referencia2_telefono AS referencia2_telefono" : "'' AS referencia2_telefono",
]);

/* ========= Consulta con IMEI2 ========= */
$sql = "
SELECT
  v.id AS id_venta, v.fecha_venta, v.tag, v.nombre_cliente, v.telefono_cliente,
  s.nombre AS sucursal, u.nombre AS usuario,
  v.tipo_venta, v.precio_venta, p.precio_lista,
  v.comision AS comision_venta, v.enganche, v.forma_pago_enganche,
  v.enganche_efectivo, v.enganche_tarjeta, v.comentarios,
  {$selectRefs},
  p.marca, p.modelo, p.color,
  COALESCE(cm1.codigo_producto, cm2.codigo_producto, p.codigo_producto) AS codigo,
  COALESCE(cm1.descripcion, cm2.descripcion) AS descripcion,
  COALESCE(cm1.nombre_comercial, cm2.nombre_comercial) AS nombre_comercial,
  dv.id AS id_detalle, dv.imei1, p.imei2,
  dv.comision_regular, dv.comision_especial, dv.comision AS comision_equipo,
  ROW_NUMBER() OVER (PARTITION BY v.id ORDER BY dv.id) AS rn
FROM ventas v
INNER JOIN usuarios u ON v.id_usuario = u.id
INNER JOIN sucursales s ON v.id_sucursal = s.id
LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
LEFT JOIN productos p ON dv.id_producto = p.id
LEFT JOIN catalogo_modelos cm1
  ON CONVERT(cm1.codigo_producto USING utf8mb4) COLLATE utf8mb4_general_ci =
     CONVERT(p.codigo_producto USING utf8mb4) COLLATE utf8mb4_general_ci
LEFT JOIN catalogo_modelos cm2
  ON ((p.codigo_producto IS NULL OR p.codigo_producto = '')
      AND CONVERT(cm2.marca USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(p.marca USING utf8mb4) COLLATE utf8mb4_general_ci
      AND CONVERT(cm2.modelo USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(p.modelo USING utf8mb4) COLLATE utf8mb4_general_ci
      AND (CONVERT(cm2.color USING utf8mb4) COLLATE utf8mb4_general_ci <=> CONVERT(p.color USING utf8mb4) COLLATE utf8mb4_general_ci)
      AND (CONVERT(cm2.ram USING utf8mb4) COLLATE utf8mb4_general_ci <=> CONVERT(p.ram USING utf8mb4) COLLATE utf8mb4_general_ci)
      AND (CONVERT(cm2.capacidad USING utf8mb4) COLLATE utf8mb4_general_ci <=> CONVERT(p.capacidad USING utf8mb4) COLLATE utf8mb4_general_ci))
{$where}
ORDER BY v.fecha_venta DESC, v.id DESC, dv.id ASC
";

$stmt = $conn->prepare($sql);
if ($params){ $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();

/* ========= Headers ========= */
if (!$debug) {
  while (ob_get_level()) { ob_end_clean(); }
  header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
  header("Content-Disposition: attachment; filename=historial_ventas.xls");
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Pragma: no-cache");
} else {
  header("Content-Type: text/html; charset=UTF-8");
}

/* ========= Salida ========= */
echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<table border='1'><thead><tr style='background:#f2f2f2'>
  <th>ID Venta</th><th>Fecha</th><th>TAG</th><th>Cliente</th><th>Teléfono</th><th>Sucursal</th><th>Usuario</th>
  <th>Tipo Venta</th><th>Precio Venta</th><th>Precio Lista</th><th>Comisión Total Venta</th>
  <th>Enganche</th><th>Forma Enganche</th><th>Enganche Efectivo</th><th>Enganche Tarjeta</th>
  <th>Comentarios</th>
  <th>Ref1 Nombre</th><th>Ref1 Teléfono</th><th>Ref2 Nombre</th><th>Ref2 Teléfono</th>
  <th>Marca</th><th>Modelo</th><th>Color</th>
  <th>Código</th><th>Descripción</th><th>Nombre comercial</th>
  <th>IMEI</th><th>IMEI2</th>
  <th>Comisión Regular</th><th>Comisión Especial</th><th>Total Comisión Equipo</th>
</tr></thead><tbody>";

while ($r = $res->fetch_assoc()) {
  $imei1 = $r['imei1'] ? '="'.e($r['imei1']).'"' : '';
  $imei2 = $r['imei2'] ? '="'.e($r['imei2']).'"' : '';
  $soloPrimera = ((int)$r['rn'] === 1);
  $precioVenta = $soloPrimera ? e($r['precio_venta']) : '';
  $precioLista = $soloPrimera ? e($r['precio_lista']) : '';

  echo "<tr>
    <td>".e($r['id_venta'])."</td>
    <td>".e($r['fecha_venta'])."</td>
    <td>".e($r['tag'])."</td>
    <td>".e($r['nombre_cliente'])."</td>
    <td>".e($r['telefono_cliente'])."</td>
    <td>".e($r['sucursal'])."</td>
    <td>".e($r['usuario'])."</td>
    <td>".e($r['tipo_venta'])."</td>
    <td>{$precioVenta}</td>
    <td>{$precioLista}</td>
    <td>".e($r['comision_venta'])."</td>
    <td>".e($r['enganche'])."</td>
    <td>".e($r['forma_pago_enganche'])."</td>
    <td>".e($r['enganche_efectivo'])."</td>
    <td>".e($r['enganche_tarjeta'])."</td>
    <td>".e($r['comentarios'])."</td>
    <td>".e($r['referencia1_nombre'])."</td>
    <td>".e($r['referencia1_telefono'])."</td>
    <td>".e($r['referencia2_nombre'])."</td>
    <td>".e($r['referencia2_telefono'])."</td>
    <td>".e($r['marca'])."</td>
    <td>".e($r['modelo'])."</td>
    <td>".e($r['color'])."</td>
    <td>".e($r['codigo'])."</td>
    <td>".e($r['descripcion'])."</td>
    <td>".e($r['nombre_comercial'])."</td>
    <td>{$imei1}</td>
    <td>{$imei2}</td>
    <td>".e($r['comision_regular'])."</td>
    <td>".e($r['comision_especial'])."</td>
    <td>".e($r['comision_equipo'])."</td>
  </tr>";
}

echo "</tbody></table></body></html>";
exit;
?>
