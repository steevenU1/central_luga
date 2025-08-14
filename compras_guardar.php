<?php
// compras_guardar.php
// Guarda encabezado y renglones por MODELO del catálogo

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
include 'db.php';

$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);

// ---------- Encabezado ----------
$id_proveedor   = (int)($_POST['id_proveedor'] ?? 0);
$num_factura    = trim($_POST['num_factura'] ?? '');
$id_sucursal    = (int)($_POST['id_sucursal'] ?? 0);
$fecha_factura  = $_POST['fecha_factura'] ?? date('Y-m-d');
$fecha_venc     = $_POST['fecha_vencimiento'] ?? null;
$notas          = trim($_POST['notas'] ?? '');

// ---------- Detalle (indexados por fila) ----------
$id_modelo   = $_POST['id_modelo'] ?? [];         // [idx] => id
$color       = $_POST['color'] ?? [];             // [idx] => str
$capacidad   = $_POST['capacidad'] ?? [];         // [idx] => str
$cantidad    = $_POST['cantidad'] ?? [];          // [idx] => int
$precio      = $_POST['precio_unitario'] ?? [];   // [idx] => float
$iva_pct     = $_POST['iva_porcentaje'] ?? [];    // [idx] => float
$requiereMap = $_POST['requiere_imei'] ?? [];     // [idx] => "0" | "1"

if ($id_proveedor<=0 || $num_factura==='' || $id_sucursal<=0) {
  die("Parámetros inválidos.");
}
if (empty($id_modelo)) {
  die("Debes incluir al menos un renglón.");
}

$subtotal = 0.0; $iva = 0.0; $total = 0.0;
$rows = [];

foreach ($id_modelo as $idx => $idmRaw) {
  $idm = (int)$idmRaw;
  if ($idm<=0) continue;

  // Trae snapshot del catálogo
  $st = $conn->prepare("SELECT marca, modelo, codigo_producto FROM catalogo_modelos WHERE id=? AND activo=1");
  $st->bind_param("i", $idm);
  $st->execute();
  $st->bind_result($marca, $modelo, $codigoCat);
  $ok = $st->fetch(); $st->close();
  if (!$ok) continue;

  $col = substr(trim($color[$idx] ?? ''), 0, 40);
  $cap = substr(trim($capacidad[$idx] ?? ''), 0, 40);
  $qty = max(0, (int)($cantidad[$idx] ?? 0));
  $pu  = max(0, (float)($precio[$idx] ?? 0));
  $ivp = max(0, (float)($iva_pct[$idx] ?? 0));
  $req = (int)($requiereMap[$idx] ?? 1); // default 1

  if ($marca==='' || $modelo==='' || $col==='' || $cap==='' || $qty<=0) continue;

  $rsub = $qty * $pu;
  $riva = $rsub * ($ivp/100.0);
  $rtot = $rsub + $riva;

  $subtotal += $rsub; $iva += $riva; $total += $rtot;

  $rows[] = [
    'id_modelo'=>$idm, 'marca'=>$marca, 'modelo'=>$modelo,
    'color'=>$col, 'capacidad'=>$cap, 'cantidad'=>$qty,
    'precio_unitario'=>$pu, 'iva_porcentaje'=>$ivp,
    'subtotal'=>$rsub, 'iva'=>$riva, 'total'=>$rtot,
    'requiere_imei'=>$req,
    'codigo_producto'=>$codigoCat
  ];
}

if (empty($rows)) { die("Debes incluir al menos un renglón válido."); }

// ---------- Transacción ----------
$conn->begin_transaction();
try {
  // Encabezado
  $sqlC = "INSERT INTO compras
            (num_factura, id_proveedor, id_sucursal, fecha_factura, fecha_vencimiento,
             subtotal, iva, total, estatus, notas, creado_por)
           VALUES (?,?,?,?,?,?,?,?,'Pendiente',?,?)";
  $stmtC = $conn->prepare($sqlC);
  if (!$stmtC) { throw new Exception("Prepare compras: ".$conn->error); }
  $stmtC->bind_param("siissddssi",
    $num_factura, $id_proveedor, $id_sucursal, $fecha_factura, $fecha_venc,
    $subtotal, $iva, $total, $notas, $ID_USUARIO
  );
  if (!$stmtC->execute()) { throw new Exception("Insert compras: ".$stmtC->error); }
  $id_compra = $stmtC->insert_id;
  $stmtC->close();

  // Detalle (sin id_producto; con id_modelo)
  $sqlD = "INSERT INTO compras_detalle
            (id_compra, id_modelo, marca, modelo, color, capacidad, requiere_imei, descripcion,
             cantidad, precio_unitario, iva_porcentaje, subtotal, iva, total)
           VALUES
            (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)";
  $stmtD = $conn->prepare($sqlD);
  if (!$stmtD) { throw new Exception("Prepare detalle: ".$conn->error); }

  foreach ($rows as $r) {
    $stmtD->bind_param("iissssiiddddd",
      $id_compra,
      $r['id_modelo'],
      $r['marca'], $r['modelo'], $r['color'], $r['capacidad'],
      $r['requiere_imei'],
      $r['cantidad'], $r['precio_unitario'], $r['iva_porcentaje'],
      $r['subtotal'], $r['iva'], $r['total']
    );
    if (!$stmtD->execute()) { throw new Exception("Insert detalle: ".$stmtD->error); }
  }
  $stmtD->close();

  $conn->commit();
  header("Location: compras_ver.php?id=".$id_compra);
  exit();

} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo "Error al guardar la compra: ".$e->getMessage();
}
