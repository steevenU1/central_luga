<?php
// procesar_venta_accesorios.php — LUGA / Central 2.0
// Opción A: descuenta SOLO cantidad sin tocar estatus.
// Inserta detalle en la tabla existente: detalle_venta_accesorio (singular) o detalle_venta_accesorios (plural).
// NUEVO:
//   - Recibe id_cliente, nombre_cliente y telefono (oculto).
//   - Si existe columna id_cliente en ventas_accesorios, la llena.
//   - Mantiene lógica de regalo / whitelist / stock.

session_start();
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit(); }
require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_POST['id_sucursal'] ?? ($_SESSION['id_sucursal'] ?? 0));

/* ---------------- Helpers ---------------- */
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function column_exists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $rs = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $rs && $rs->num_rows > 0;
}
function table_exists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $rs = $conn->query("SHOW TABLES LIKE '{$t}'");
  return $rs && $rs->num_rows > 0;
}
function normalizar_tag($s){
  $s = strtoupper(trim((string)$s));
  return preg_replace('/\s+/', ' ', $s);
}
function buscar_venta_equipo_por_tag(mysqli $conn, string $tag): array {
  $tag = normalizar_tag($tag);
  if ($tag === '') return [0,0];
  $sql = "SELECT v.id, v.id_sucursal FROM ventas v
          WHERE UPPER(TRIM(v.tag)) = UPPER(TRIM(?)) LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return [0,0];
  $st->bind_param('s', $tag);
  if (!$st->execute()) return [0,0];
  $r = $st->get_result()->fetch_row();
  return $r ? [(int)$r[0], (int)$r[1]] : [0,0];
}
function parse_codes_to_array(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return [];
  if (preg_match('/^\s*\[/', $raw)) {
    $arr = json_decode($raw, true);
    if (!is_array($arr)) return [];
    $arr = array_map(fn($x)=>trim((string)$x), $arr);
  } else {
    $raw = str_replace(["\r\n","\r"], "\n", $raw);
    $arr = preg_split('/[\n,]+/', $raw);
    $arr = array_map('trim', $arr);
  }
  return array_values(array_unique(array_filter($arr, fn($x)=>$x!=='')));
}
function nombre_producto(mysqli $conn, int $idProducto): string {
  $st = $conn->prepare("SELECT TRIM(CONCAT(marca,' ',modelo,' ',COALESCE(color,''))) AS n FROM productos WHERE id=?");
  $st->bind_param('i', $idProducto);
  $st->execute();
  $r = $st->get_result()->fetch_assoc();
  return trim((string)($r['n'] ?? 'Producto #'.$idProducto));
}
// NUEVO: checar si el producto es "solo regalo" (vender=0)
function es_solo_regalo(mysqli $conn, int $idProducto): bool {
  $tbl = $conn->query("SHOW TABLES LIKE 'accesorios_regalo_modelos'");
  if (!$tbl || $tbl->num_rows === 0) return false;
  $st = $conn->prepare("SELECT 1 FROM accesorios_regalo_modelos WHERE id_producto=? AND activo=1 AND vender=0 LIMIT 1");
  $st->bind_param('i', $idProducto);
  $st->execute();
  return (bool)$st->get_result()->fetch_row();
}

/* ---------------- Entradas ---------------- */
$TAG_ACCES    = normalizar_tag($_POST['tag'] ?? '');
$ID_CLIENTE   = (int)($_POST['id_cliente'] ?? 0);
$NOMBRE       = trim((string)($_POST['nombre_cliente'] ?? ''));
$TELEFONO     = trim((string)($_POST['telefono'] ?? ''));
$FORMA_PAGO   = trim((string)($_POST['forma_pago'] ?? 'Efectivo'));
$EFECTIVO     = (float)($_POST['efectivo'] ?? 0);
$TARJETA      = (float)($_POST['tarjeta'] ?? 0);
$COMENTARIOS  = trim((string)($_POST['comentarios'] ?? ''));
$ES_REGALO    = (int)($_POST['es_regalo'] ?? 0);
$TAG_EQUIPO   = normalizar_tag($_POST['tag_equipo'] ?? ($_POST['tag_venta_equipo'] ?? ''));

$IDS  = array_map('intval', (array)($_POST['linea_id_producto'] ?? []));
$CANT = array_map('intval', (array)($_POST['linea_cantidad'] ?? []));
$PREC = array_map('floatval', (array)($_POST['linea_precio'] ?? []));

/* ---------------- Validaciones básicas ---------------- */
if ($ID_SUCURSAL <= 0)                   die('Sucursal inválida.');
if ($TAG_ACCES === '')                   die('TAG de la venta de accesorios es requerido.');
if ($ID_CLIENTE <= 0)                    die('Cliente inválido (id_cliente faltante).');
if ($NOMBRE === '' || $TELEFONO === '')  die('Nombre y teléfono son requeridos.');
$telNorm = preg_replace('/\D+/', '', $TELEFONO);
if (!preg_match('/^\d{10}$/', $telNorm)) die('El teléfono del cliente debe tener exactamente 10 dígitos.');
$TELEFONO = $telNorm;

if (count($IDS) === 0)                   die('Debes agregar al menos una línea.');
if (count($IDS) !== count($CANT) || count($IDS) !== count($PREC)) die('Líneas mal formadas.');

$lineas = [];
for ($i=0; $i<count($IDS); $i++){
  $pid = (int)$IDS[$i]; if ($pid<=0) continue;
  $qty = max(1, (int)$CANT[$i]);
  $prc = max(0.0, (float)$PREC[$i]);
  $lineas[] = ['id_producto'=>$pid, 'cantidad'=>$qty, 'precio'=>$prc];
}
if (empty($lineas)) die('Líneas inválidas.');

/* ---------------- Bloqueo por vender=0 (si NO es regalo) ---------------- */
if ($ES_REGALO === 0){
  foreach ($lineas as $ln){
    if (es_solo_regalo($conn, (int)$ln['id_producto'])) {
      die('Este accesorio es solo para regalo.');
    }
  }
}

/* ---------------- Modo REGALO ---------------- */
$has_es_regalo_col  = column_exists($conn, 'ventas_accesorios', 'es_regalo');
$has_tag_equipo_col = column_exists($conn, 'ventas_accesorios', 'tag_equipo');

if ($ES_REGALO === 1) {
  if (count($lineas) !== 1) die('En regalo solo se permite 1 accesorio.');
  $lineas[0]['cantidad'] = 1;
  $lineas[0]['precio']   = 0.00;

  if ($TAG_EQUIPO === '') die('Debes capturar el TAG de la venta de equipo.');
  [$ID_VENTA_EQUIPO] = buscar_venta_equipo_por_tag($conn, $TAG_EQUIPO);
  if ($ID_VENTA_EQUIPO <= 0) die('No se encontró una venta de equipo con ese TAG.');

  if ($has_tag_equipo_col){
    $st = $conn->prepare("SELECT 1 FROM ventas_accesorios WHERE tag_equipo=? LIMIT 1");
    $st->bind_param('s', $TAG_EQUIPO);
    $st->execute();
    if ($st->get_result()->fetch_row()) die('Ese TAG ya usó su accesorio de regalo.');
  }

  // Whitelist
  $ID_ACC = (int)$lineas[0]['id_producto'];
  $st = $conn->prepare("SELECT codigos_equipos
                          FROM accesorios_regalo_modelos
                         WHERE id_producto=? AND activo=1 LIMIT 1");
  if (!$st) die('Error preparando whitelist: '.$conn->error);
  $st->bind_param('i', $ID_ACC);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  if (!$row) die('El accesorio no está habilitado para regalo.');

  $codes = parse_codes_to_array((string)$row['codigos_equipos']);
  if (empty($codes)) die('No hay códigos habilitadores configurados para este accesorio.');

  $marks = implode(',', array_fill(0, count($codes), '?'));
  $types = str_repeat('s', count($codes));
  $sql = "SELECT 1 FROM detalle_venta dv
          JOIN productos p ON p.id = dv.id_producto
          WHERE dv.id_venta = ? AND p.codigo_producto IN ($marks) LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) die('Error validando códigos habilitadores: '.$conn->error);
  $bind_types = 'i'.$types;
  $bind_vals  = array_merge([$ID_VENTA_EQUIPO], $codes);
  $st->bind_param($bind_types, ...$bind_vals);
  $st->execute();
  if (!$st->get_result()->fetch_row()){
    die('La venta de equipo no contiene un modelo habilitador para este accesorio.');
  }

  // Forzar pagos $0
  $FORMA_PAGO = 'Efectivo';
  $EFECTIVO   = 0.00;
  $TARJETA    = 0.00;
}

/* ---------------- Stock (solo 'Disponible') ---------------- */
function stock_disponible(mysqli $conn, int $idSucursal, int $idProducto): int {
  $st = $conn->prepare("
    SELECT COALESCE(SUM(CASE WHEN estatus='Disponible'
                             THEN COALESCE(cantidad,1) ELSE 0 END),0)
    FROM inventario WHERE id_sucursal=? AND id_producto=?");
  $st->bind_param('ii', $idSucursal, $idProducto);
  $st->execute();
  return (int)$st->get_result()->fetch_row()[0];
}
function descontar_inventario(mysqli $conn, int $idSucursal, int $idProducto, int $qty){
  $st = $conn->prepare("
    SELECT id, COALESCE(cantidad,1) AS cant
    FROM inventario
    WHERE id_sucursal=? AND id_producto=? AND estatus='Disponible'
    ORDER BY id ASC");
  $st->bind_param('ii', $idSucursal, $idProducto);
  $st->execute();
  $res = $st->get_result();

  $pend = $qty;
  while ($pend > 0 && ($row = $res->fetch_assoc())){
    $iid = (int)$row['id'];
    $c   = (int)$row['cant'];
    if ($c <= 0) continue;
    $take = min($pend, $c);
    // Opción A: solo restar cantidad; NO tocar estatus.
    $up = $conn->prepare("UPDATE inventario SET cantidad = GREATEST(cantidad-?,0) WHERE id=?");
    $up->bind_param('ii', $take, $iid);
    if (!$up->execute()) throw new Exception('No se pudo actualizar inventario: '.$conn->error);
    $pend -= $take;
  }
  if ($pend > 0) throw new Exception('Stock insuficiente en inventario.');
}

/* ---------------- Totales / Pagos ---------------- */
$total = 0.0;
if ($ES_REGALO === 0){
  foreach ($lineas as $ln){ $total += $ln['cantidad'] * $ln['precio']; }
  $total = (float)round($total, 2);

  if ($FORMA_PAGO === 'Efectivo'){
    if (abs($EFECTIVO - $total) > 0.01) die('Efectivo debe igualar el Total.');
    $TARJETA = 0.00;
  } elseif ($FORMA_PAGO === 'Tarjeta'){
    if (abs($TARJETA - $total) > 0.01) die('Tarjeta debe igualar el Total.');
    $EFECTIVO = 0.00;
  } else { // Mixto
    if (abs(($EFECTIVO + $TARJETA) - $total) > 0.01) die('En Mixto, Efectivo + Tarjeta debe igualar el Total.');
  }
}

/* ---------------- Validar stock ---------------- */
foreach ($lineas as $ln){
  $disp = stock_disponible($conn, $ID_SUCURSAL, $ln['id_producto']);
  if ($ln['cantidad'] > $disp){
    die('Stock insuficiente para el producto '.$ln['id_producto'].' (solicitado '.$ln['cantidad'].', disponible '.$disp.').');
  }
}

/* ---------------- Transacción ---------------- */
$conn->begin_transaction();
try{
  // Encabezado
  $cols = "tag, nombre_cliente, telefono, id_sucursal, id_usuario, forma_pago, efectivo, tarjeta, total, comentarios";
  $vals = "?,   ?,              ?,        ?,           ?,          ?,          ?,       ?,       ?,     ?";
  $bind = "sssii sddd s"; $bind = str_replace(' ','',$bind);
  $params = [$TAG_ACCES, $NOMBRE, $TELEFONO, $ID_SUCURSAL, $ID_USUARIO, $FORMA_PAGO, $EFECTIVO, $TARJETA, $total, $COMENTARIOS];

  // NUEVO: id_cliente si existe la columna
  if (column_exists($conn,'ventas_accesorios','id_cliente')) {
    $cols  .= ", id_cliente";
    $vals  .= ", ?";
    $bind  .= "i";
    $params[] = $ID_CLIENTE;
  }

  if ($has_es_regalo_col){
    $cols.=", es_regalo";  $vals.=", ?"; $bind.="i"; $params[]=(int)$ES_REGALO;
  }
  if ($has_tag_equipo_col){
    $cols.=", tag_equipo"; $vals.=", ?"; $bind.="s"; $params[]=$TAG_EQUIPO;
  }

  $sql = "INSERT INTO ventas_accesorios ($cols) VALUES ($vals)";
  $st = $conn->prepare($sql);
  if (!$st) throw new Exception('No se pudo preparar INSERT de venta: '.$conn->error);
  $st->bind_param($bind, ...$params);
  if (!$st->execute()) throw new Exception('No se pudo guardar la venta: '.$st->error);
  $ID_VENTA_ACC = (int)$st->insert_id;

  // Tabla de detalle (detecta singular/plural)
  $tblDetalle = table_exists($conn,'detalle_venta_accesorio') ? 'detalle_venta_accesorio'
              : (table_exists($conn,'detalle_venta_accesorios') ? 'detalle_venta_accesorios' : '');

  if ($tblDetalle === '') throw new Exception('No existe tabla de detalle para accesorios.');

  // Columnas disponibles: descripcion_snapshot, cantidad, precio_unitario, subtotal
  $tieneDescripcion = column_exists($conn, $tblDetalle, 'descripcion_snapshot');
  $tieneSubtotal    = column_exists($conn, $tblDetalle, 'subtotal');

  $sqlDet = "INSERT INTO `$tblDetalle` (id_venta, id_producto"
          . ($tieneDescripcion? ", descripcion_snapshot":"")
          . ", cantidad, precio_unitario"
          . ($tieneSubtotal? ", subtotal":"")
          . ") VALUES (?,?,?,?,?"
          . ($tieneSubtotal? ",?":"")
          . ")";
  $stDet = $conn->prepare($sqlDet);
  if (!$stDet) throw new Exception('No se pudo preparar detalle: '.$conn->error);

  foreach ($lineas as $ln){
    $desc = $tieneDescripcion ? nombre_producto($conn, (int)$ln['id_producto']) : null;
    $subt = $tieneSubtotal    ? (float)($ln['cantidad'] * $ln['precio']) : null;

    if ($tieneDescripcion && $tieneSubtotal){
      $stDet->bind_param('iisidd', $ID_VENTA_ACC, $ln['id_producto'], $desc, $ln['cantidad'], $ln['precio'], $subt);
    } elseif ($tieneDescripcion && !$tieneSubtotal){
      $stDet->bind_param('iisid',  $ID_VENTA_ACC, $ln['id_producto'], $desc, $ln['cantidad'], $ln['precio']);
    } elseif (!$tieneDescripcion && $tieneSubtotal){
      $stDet->bind_param('iiidd',  $ID_VENTA_ACC, $ln['id_producto'],        $ln['cantidad'], $ln['precio'], $subt);
    } else {
      // fallback por si fuera una estructura mínima
      $stDet->close();
      $sqlDetMin = "INSERT INTO `$tblDetalle` (id_venta, id_producto, cantidad, precio_unitario) VALUES (?,?,?,?)";
      $stDet = $conn->prepare($sqlDetMin);
      $stDet->bind_param('iiid', $ID_VENTA_ACC, $ln['id_producto'], $ln['cantidad'], $ln['precio']);
    }

    if (!$stDet->execute()) throw new Exception('No se pudo guardar detalle: '.$stDet->error);
  }

  // Descontar inventario (opción A)
  foreach ($lineas as $ln){
    descontar_inventario($conn, $ID_SUCURSAL, $ln['id_producto'], $ln['cantidad']);
  }

  $conn->commit();
  header("Location: venta_accesorios_ticket.php?id=".$ID_VENTA_ACC);
  exit;

} catch (Throwable $e){
  $conn->rollback();
  http_response_code(400);
  echo "Error al procesar la venta de accesorios: ".h($e->getMessage());
  exit;
}
