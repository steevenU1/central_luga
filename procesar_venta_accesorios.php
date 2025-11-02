<?php
// procesar_venta_accesorios.php — Venta de accesorios con parser robusto de líneas y descuento FIFO por sucursal

session_start();
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit(); }
require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_POST['id_sucursal'] ?? ($_SESSION['id_sucursal'] ?? 0));

/* Helpers */
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fail($m){ http_response_code(400); echo h($m); exit; }
function n2($v){ return number_format((float)$v, 2, '.', ''); }
function toInt($v){ return (is_numeric($v) ? (int)$v : 0); }
function toFloat($v){ return (is_numeric($v) ? (float)$v : -1); }

/* POST base */
$tag            = trim($_POST['tag'] ?? '');
$nombre_cliente = trim($_POST['nombre_cliente'] ?? '');
$telefono       = trim($_POST['telefono'] ?? '');
$comentarios    = trim($_POST['comentarios'] ?? '');
$forma_pago     = $_POST['forma_pago'] ?? 'Efectivo';
$efectivo       = (float)($_POST['efectivo'] ?? 0);
$tarjeta        = (float)($_POST['tarjeta'] ?? 0);

/* Validaciones base */
if ($tag === '') fail('TAG requerido');
if ($nombre_cliente === '') fail('Nombre del cliente requerido');
if (!preg_match('/^[0-9]{10}$/', $telefono)) fail('Teléfono inválido (10 dígitos)');
if ($ID_SUCURSAL <= 0) fail('Sucursal inválida');
if (!in_array($forma_pago, ['Efectivo','Tarjeta','Mixto'], true)) fail('Forma de pago inválida');

/* ----------------------------------------------------------------
   PARSEO ROBUSTO DE LÍNEAS
   Acepta cualquiera de estas estructuras:
   A) linea[][id_producto], linea[][cantidad], linea[][precio]   (array de filas)
   B) linea[id_producto][], linea[cantidad][], linea[precio][]   (array de columnas)
   C) linea[id_producto], linea[cantidad], linea[precio]         (una sola fila)
   D) linea_id_producto[], linea_cantidad[], linea_precio[]      (campos sueltos en columnas)
   E) linea_id_producto,  linea_cantidad,  linea_precio          (una sola fila suelta)
------------------------------------------------------------------*/
$norm = [];
$raw = $_POST['linea'] ?? null;

$push = function($idp,$cant,$precio) use (&$norm){
  $idp = toInt($idp); $cant = toInt($cant); $precio = toFloat($precio);
  if ($idp<=0 && $cant==0 && ($precio<0 || $precio===0.0)) { return; } // fila totalmente vacía → omitir
  if ($idp<=0 || $cant<=0 || $precio<0) {
    // indicamos el índice real hasta el final (lo revisamos más abajo)
    $norm[] = ['_invalid'=>true, 'id_producto'=>$idp, 'cantidad'=>$cant, 'precio'=>$precio];
  } else {
    $norm[] = ['id_producto'=>$idp,'cantidad'=>$cant,'precio'=>$precio];
  }
};

/* Caso A: array de filas */
if (is_array($raw) && isset($raw[0]) && is_array($raw[0])) {
  foreach ($raw as $ln) { $push($ln['id_producto'] ?? null, $ln['cantidad'] ?? null, $ln['precio'] ?? null); }
/* Caso B: array de columnas */
} elseif (is_array($raw) && isset($raw['id_producto']) && isset($raw['cantidad']) && isset($raw['precio'])
          && (is_array($raw['id_producto']) || is_array($raw['cantidad']) || is_array($raw['precio']))) {
  $N = max(count((array)$raw['id_producto']), count((array)$raw['cantidad']), count((array)$raw['precio']));
  for ($i=0;$i<$N;$i++){
    $push($raw['id_producto'][$i] ?? null, $raw['cantidad'][$i] ?? null, $raw['precio'][$i] ?? null);
  }
/* Caso C: una sola fila dentro de linea[...] */
} elseif (is_array($raw) && isset($raw['id_producto']) && isset($raw['cantidad']) && isset($raw['precio'])) {
  $push($raw['id_producto'], $raw['cantidad'], $raw['precio']);
/* Caso D: campos sueltos en columnas */
} elseif (isset($_POST['linea_id_producto']) && isset($_POST['linea_cantidad']) && isset($_POST['linea_precio'])) {
  $ips = (array)$_POST['linea_id_producto'];
  $cns = (array)$_POST['linea_cantidad'];
  $prs = (array)$_POST['linea_precio'];
  $N = max(count($ips),count($cns),count($prs));
  for ($i=0;$i<$N;$i++){ $push($ips[$i] ?? null, $cns[$i] ?? null, $prs[$i] ?? null); }
/* Caso E: una sola fila suelta */
} elseif (isset($_POST['linea_id_producto']) && isset($_POST['linea_cantidad']) && isset($_POST['linea_precio'])) {
  $push($_POST['linea_id_producto'], $_POST['linea_cantidad'], $_POST['linea_precio']);
} else {
  fail('No se recibió ninguna línea.');
}

/* Validar que haya al menos una fila válida y marcar exactamente cuál está mal si aplica */
if (empty($norm)) fail('No hay líneas válidas.');
$idx = 1;
foreach ($norm as $ln){
  if (!empty($ln['_invalid'])) fail('Línea sin datos o inválida en la fila '.$idx.'. Selecciona accesorio, cantidad y precio.');
  $idx++;
}

/* Total y pagos */
$total = 0.0;
foreach ($norm as $ln) $total += $ln['cantidad'] * $ln['precio'];
$total = (float)n2($total);

if ($forma_pago==='Efectivo' && (float)n2($efectivo) !== $total) fail('Efectivo debe igualar el total.');
if ($forma_pago==='Tarjeta'  && (float)n2($tarjeta)  !== $total) fail('Tarjeta debe igualar el total.');
if ($forma_pago==='Mixto'    && (float)n2($efectivo+$tarjeta) !== $total) fail('En pago Mixto, Efectivo + Tarjeta debe igualar el total.');

/* Transacción */
$conn->begin_transaction();
try {
  // TAG único
  $chk = $conn->prepare("SELECT id FROM ventas_accesorios WHERE tag=? LIMIT 1");
  if (!$chk) throw new Exception('Error al preparar verificación de TAG.');
  $chk->bind_param('s', $tag);
  $chk->execute();
  $r = $chk->get_result();
  if ($r && $r->num_rows > 0) throw new Exception('El TAG ya existe.');

  // Encabezado
  $stmt = $conn->prepare("INSERT INTO ventas_accesorios
    (tag,nombre_cliente,telefono,id_sucursal,id_usuario,forma_pago,efectivo,tarjeta,total,comentarios)
    VALUES (?,?,?,?,?,?,?,?,?,?)");
  if (!$stmt) throw new Exception('Error al preparar encabezado.');
  $stmt->bind_param('sssiisddds', $tag,$nombre_cliente,$telefono,$ID_SUCURSAL,$ID_USUARIO,$forma_pago,$efectivo,$tarjeta,$total,$comentarios);
  $stmt->execute();
  $idVenta = (int)$conn->insert_id;

  // Helpers
  $getP = $conn->prepare("SELECT TRIM(CONCAT(marca,' ',modelo,' ',COALESCE(color,''))) AS nombre FROM productos WHERE id=? LIMIT 1");
  if (!$getP) throw new Exception('Error al preparar snapshot de producto.');
  $insD = $conn->prepare("INSERT INTO detalle_venta_accesorio (id_venta,id_producto,descripcion_snapshot,cantidad,precio_unitario,subtotal)
                          VALUES (?,?,?,?,?,?)");
  if (!$insD) throw new Exception('Error al preparar detalle.');
  $qDisp = $conn->prepare("SELECT SUM(CASE WHEN estatus IN ('Disponible','Parcial','En stock') THEN COALESCE(cantidad,1) ELSE 0 END) AS disp
                           FROM inventario WHERE id_producto=? AND id_sucursal=?");
  if (!$qDisp) throw new Exception('Error al preparar consulta de stock.');
  $qFIFO = $conn->prepare("SELECT id, COALESCE(cantidad,1) AS qty
                            FROM inventario
                           WHERE id_producto=? AND id_sucursal=? AND estatus IN ('Disponible','Parcial','En stock')
                        ORDER BY fecha_ingreso ASC, id ASC");
  if (!$qFIFO) throw new Exception('Error al preparar consulta FIFO.');
  $updRestar = $conn->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE id=?");
  $updAgotar = $conn->prepare("UPDATE inventario SET cantidad = 0, estatus='Agotado' WHERE id=?");

  foreach ($norm as $ln){
    $pid    = (int)$ln['id_producto'];
    $cant   = (int)$ln['cantidad'];
    $precio = (float)$ln['precio'];

    // Stock en sucursal
    $qDisp->bind_param('ii', $pid, $ID_SUCURSAL);
    $qDisp->execute();
    $disp = (int)($qDisp->get_result()->fetch_assoc()['disp'] ?? 0);
    if ($disp < $cant) throw new Exception('Stock insuficiente para producto '.$pid.' (disp: '.$disp.', req: '.$cant.').');

    // Snapshot
    $getP->bind_param('i', $pid);
    $getP->execute();
    $rP = $getP->get_result();
    $nombre = ($rP && $rP->num_rows) ? ($rP->fetch_assoc()['nombre'] ?? ('Prod #'.$pid)) : ('Prod #'.$pid);

    // Detalle
    $sub = (float)n2($cant * $precio);
    $insD->bind_param('iisidd', $idVenta, $pid, $nombre, $cant, $precio, $sub);
    $insD->execute();

    // FIFO
    $qFIFO->bind_param('ii', $pid, $ID_SUCURSAL);
    $qFIFO->execute();
    $rows = $qFIFO->get_result()->fetch_all(MYSQLI_ASSOC);

    $porConsumir = $cant;
    foreach ($rows as $rw){
      if ($porConsumir <= 0) break;
      $filaId = (int)$rw['id'];
      $tiene  = (int)$rw['qty'];
      if ($tiene <= 0) continue;

      if ($tiene > $porConsumir){
        $updRestar->bind_param('ii', $porConsumir, $filaId);
        $updRestar->execute();
        $porConsumir = 0;
      } else {
        $updAgotar->bind_param('i', $filaId);
        $updAgotar->execute();
        $porConsumir -= $tiene;
      }
    }
    if ($porConsumir > 0) throw new Exception('No se pudo completar la salida de inventario (FIFO) para producto '.$pid);
  }

  $conn->commit();
  header('Location: venta_accesorios_ticket.php?id='.$idVenta);
  exit;

} catch (Throwable $e){
  $conn->rollback();
  http_response_code(500);
  echo 'Error al guardar la venta: '.h($e->getMessage());
}
