<?php
// ajax_promo_regalo_combos.php
// Devuelve <option> para #equipo2, filtrado por promo_regalo (regalos permitidos)
// POST: promo_id, id_sucursal, exclude_inventario (opcional), q (opcional para buscador)

// Seguridad mínima
session_start();
if (!isset($_SESSION['id_usuario'])) { http_response_code(401); exit('No auth'); }

require_once __DIR__ . '/db.php';

$promo_id = (int)($_POST['promo_id'] ?? 0);
$id_sucursal = (int)($_POST['id_sucursal'] ?? 0);
$exclude = (int)($_POST['exclude_inventario'] ?? 0);

if ($promo_id <= 0 || $id_sucursal <= 0) {
  echo '<option value="">(Promo o sucursal inválida)</option>';
  exit;
}

// Ajusta el nombre de la tabla/columna si en tu BD se llama diferente:
$table = 'promos_regalo_regalos';            // <- tabla donde guardas regalos permitidos
$colPromo = 'id_promo';                      // <- columna del id promo
$colCodigo = 'codigo_producto_regalo';       // <- columna del codigo_producto permitido

// Check rápido existencia para no romper
$chk = $conn->prepare("
  SELECT COUNT(*) AS c
  FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = ?
");
$chk->bind_param("s", $table);
$chk->execute();
$exists = (int)($chk->get_result()->fetch_assoc()['c'] ?? 0);
$chk->close();

if ($exists <= 0) {
  echo '<option value="">(No existe tabla de regalos: '.$table.')</option>';
  exit;
}

// Traer inventario disponible en la sucursal cuyos productos tengan codigo permitido
$sql = "
  SELECT
    i.id AS id_inventario,
    p.marca, p.modelo, p.color, p.capacidad,
    p.imei1, p.imei2,
    p.precio_lista,
    p.precio_combo,
    p.codigo_producto
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  INNER JOIN {$table} prg ON prg.{$colCodigo} = p.codigo_producto
  WHERE i.estatus = 'Disponible'
    AND i.id_sucursal = ?
    AND prg.{$colPromo} = ?
";

if ($exclude > 0) {
  $sql .= " AND i.id <> ?";
}

$sql .= " ORDER BY p.marca, p.modelo, p.capacidad, p.color, p.imei1 LIMIT 500";

if ($exclude > 0) {
  $st = $conn->prepare($sql);
  $st->bind_param("iii", $id_sucursal, $promo_id, $exclude);
} else {
  $st = $conn->prepare($sql);
  $st->bind_param("ii", $id_sucursal, $promo_id);
}

$st->execute();
$res = $st->get_result();

echo '<option value="">Seleccione...</option>';

while ($r = $res->fetch_assoc()) {
  $idInv = (int)$r['id_inventario'];
  $marca = htmlspecialchars($r['marca'] ?? '', ENT_QUOTES, 'UTF-8');
  $modelo = htmlspecialchars($r['modelo'] ?? '', ENT_QUOTES, 'UTF-8');
  $color = htmlspecialchars($r['color'] ?? '', ENT_QUOTES, 'UTF-8');
  $cap = htmlspecialchars($r['capacidad'] ?? '', ENT_QUOTES, 'UTF-8');
  $imei1 = htmlspecialchars($r['imei1'] ?? '', ENT_QUOTES, 'UTF-8');
  $imei2 = htmlspecialchars($r['imei2'] ?? '', ENT_QUOTES, 'UTF-8');

  $precioLista = (float)($r['precio_lista'] ?? 0);
  $precioCombo = (float)($r['precio_combo'] ?? 0);

  $label = trim("$marca $modelo $cap $color") . " | IMEI1:$imei1" . ($imei2 ? " | IMEI2:$imei2" : "");

  echo '<option value="'.$idInv.'" '
     . 'data-precio-lista="'.htmlspecialchars(number_format($precioLista,2,'.',''), ENT_QUOTES, 'UTF-8').'" '
     . 'data-precio-combo="'.htmlspecialchars(number_format($precioCombo,2,'.',''), ENT_QUOTES, 'UTF-8').'"'
     . '>'
     . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
     . "</option>";
}

$st->close();
