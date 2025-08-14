<?php
// compras_ingreso.php
// Ingreso de unidades a inventario por renglón (captura IMEI y PRECIO DE LISTA por modelo)
// Guarda proveedor en productos.proveedor y valida IMEIs de 15 dígitos

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';

$detalleId = (int)($_GET['detalle'] ?? 0);
$compraId  = (int)($_GET['compra'] ?? 0);
if ($detalleId<=0 || $compraId<=0) die("Parámetros inválidos.");

/* ============================
   Helpers
============================ */
function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

function parse_money($s) {
  $s = trim((string)$s);
  if ($s === '') return null;
  if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $s)) { // 1.234,56
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else { // 1,234.56
    $s = str_replace(',', '', $s);
  }
  return is_numeric($s) ? round((float)$s, 2) : null;
}

/**
 * Sugiere precio de lista por modelo.
 *  1) último precio por codigo_producto
 *  2) último precio por marca+modelo+capacidad
 *  3) costo + IVA
 */
function sugerirPrecioLista(mysqli $conn, ?string $codigoProd, string $marca, string $modelo, string $capacidad, float $costoConIva) {
  if ($codigoProd) {
    $q = $conn->prepare("SELECT precio_lista FROM productos
                         WHERE codigo_producto=? AND precio_lista IS NOT NULL AND precio_lista>0
                         ORDER BY id DESC LIMIT 1");
    $q->bind_param("s", $codigoProd);
    $q->execute(); $q->bind_result($pl);
    if ($q->fetch()) { $q->close(); return ['precio'=>(float)$pl, 'fuente'=>'último por código']; }
    $q->close();
  }
  $q2 = $conn->prepare("SELECT precio_lista FROM productos
                        WHERE marca=? AND modelo=? AND capacidad=? AND precio_lista IS NOT NULL AND precio_lista>0
                        ORDER BY id DESC LIMIT 1");
  $q2->bind_param("sss", $marca, $modelo, $capacidad);
  $q2->execute(); $q2->bind_result($pl2);
  if ($q2->fetch()) { $q2->close(); return ['precio'=>(float)$pl2, 'fuente'=>'último por modelo']; }
  $q2->close();

  return ['precio'=>$costoConIva, 'fuente'=>'costo + IVA'];
}

/* ============================
   Consultas base
============================ */
// Encabezado de compra (trae sucursal y proveedor)
$enc = $conn->query("
  SELECT c.*, s.nombre AS sucursal_nombre, p.nombre AS proveedor_nombre
  FROM compras c
  INNER JOIN sucursales s ON s.id=c.id_sucursal
  LEFT JOIN proveedores p ON p.id=c.id_proveedor
  WHERE c.id=$compraId
")->fetch_assoc();

$det = $conn->query("
  SELECT d.*
       , (SELECT COUNT(*) FROM compras_detalle_ingresos x WHERE x.id_detalle=d.id) AS ingresadas
  FROM compras_detalle d
  WHERE d.id=$detalleId AND d.id_compra=$compraId
")->fetch_assoc();

if (!$enc || !$det) die("Registro no encontrado.");

$pendientes   = max(0, (int)$det['cantidad'] - (int)$det['ingresadas']);
$requiereImei = (int)$det['requiere_imei'] === 1;
$proveedorCompra = trim((string)($enc['proveedor_nombre'] ?? ''));
if ($proveedorCompra !== '') { $proveedorCompra = mb_substr($proveedorCompra, 0, 120, 'UTF-8'); }

/* ============================
   Precálculos por renglón
============================ */
// Traer código del catálogo (si existe)
$codigoCat = null;
if (!empty($det['id_modelo'])) {
  $stm = $conn->prepare("SELECT codigo_producto FROM catalogo_modelos WHERE id=?");
  $stm->bind_param("i", $det['id_modelo']);
  $stm->execute(); $stm->bind_result($codigoCat); $stm->fetch(); $stm->close();
}

// Costos del detalle
$costo       = (float)$det['precio_unitario'];      // sin IVA
$ivaPct      = (float)$det['iva_porcentaje'];       // %
$costoConIva = round($costo * (1 + $ivaPct/100), 2);

// Sugerencia para el input precio_lista
$marcaDet  = (string)$det['marca'];
$modeloDet = (string)$det['modelo'];
$capDet    = (string)$det['capacidad'];
$sugerencia = sugerirPrecioLista($conn, $codigoCat, $marcaDet, $modeloDet, $capDet, $costoConIva);
$precioSugerido = $sugerencia['precio'];
$fuenteSugerido = $sugerencia['fuente'];

$errorMsg = "";
$precioListaForm = number_format($precioSugerido, 2, '.', ''); // valor por defecto del input

/* ============================
   POST: guardar ingresos
============================ */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $n = max(0, (int)($_POST['n'] ?? 0));
  if ($n <= 0) { header("Location: compras_ver.php?id=".$compraId); exit(); }
  if ($n > $pendientes) $n = $pendientes;

  // Precio de lista capturado (aplica a TODAS las unidades del renglón)
  $precioListaForm = trim($_POST['precio_lista'] ?? '');
  $precioListaCapturado = parse_money($precioListaForm);
  if ($precioListaCapturado === null || $precioListaCapturado <= 0) {
    $errorMsg = "Precio de lista inválido. Usa números, ejemplo: 3999.00";
  }

  if ($errorMsg === "") {
    $conn->begin_transaction();
    try {
      for ($i=0; $i<$n; $i++) {
        // --- IMEIs: limpiar a solo dígitos y validar 15 dígitos ---
        $imei1_raw = trim($_POST['imei1'][$i] ?? '');
        $imei2_raw = trim($_POST['imei2'][$i] ?? '');

        $imei1 = preg_replace('/\D+/', '', $imei1_raw);
        $imei2 = preg_replace('/\D+/', '', $imei2_raw);

        if ($requiereImei) {
          if ($imei1 === '' || !preg_match('/^\d{15}$/', $imei1)) {
            throw new Exception("IMEI1 inválido en la fila ".($i+1)." (deben ser 15 dígitos).");
          }
        } else {
          if ($imei1 !== '' && !preg_match('/^\d{15}$/', $imei1)) {
            throw new Exception("IMEI1 inválido en la fila ".($i+1)." (si lo capturas deben ser 15 dígitos).");
          }
          if ($imei1 === '') $imei1 = null;
        }

        if ($imei2 !== '' && !preg_match('/^\d{15}$/', $imei2)) {
          throw new Exception("IMEI2 inválido en la fila ".($i+1)." (si lo capturas deben ser 15 dígitos).");
        }
        if ($imei2 === '') $imei2 = null;

        // Duplicados: contra imei1 o imei2 existentes
        if ($imei1 !== null) {
          $st = $conn->prepare("SELECT COUNT(*) c FROM productos WHERE imei1=? OR imei2=?");
          $st->bind_param("ss", $imei1, $imei1);
          $st->execute(); $st->bind_result($cdup1); $st->fetch(); $st->close();
          if ($cdup1 > 0) throw new Exception("IMEI duplicado: $imei1");
        }
        if ($imei2 !== null) {
          $st = $conn->prepare("SELECT COUNT(*) c FROM productos WHERE imei1=? OR imei2=?");
          $st->bind_param("ss", $imei2, $imei2);
          $st->execute(); $st->bind_result($cdup2); $st->fetch(); $st->close();
          if ($cdup2 > 0) throw new Exception("IMEI duplicado: $imei2");
        }

        // Crear producto (una unidad) con precio_lista CAPTURADO y proveedor de la compra
        $stmtP = $conn->prepare("
          INSERT INTO productos (
            codigo_producto, marca, modelo, color, capacidad,
            imei1, imei2, costo, costo_con_iva, proveedor, precio_lista
          ) VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $marca = $det['marca']; $modelo = $det['modelo']; $color = $det['color']; $cap = $det['capacidad'];
        $prov  = ($proveedorCompra !== '') ? $proveedorCompra : null;

        // tipos: 7*s + 2*d + 1*s + 1*d  = "sssssssddsd"
        $stmtP->bind_param(
          "sssssssddsd",
          $codigoCat, $marca, $modelo, $color, $cap,
          $imei1, $imei2, $costo, $costoConIva, $prov, $precioListaCapturado
        );
        $stmtP->execute();
        $idProducto = $stmtP->insert_id;
        $stmtP->close();

        // Alta a inventario (sucursal de la compra)
        $stmtI = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, estatus) VALUES (?, ?, 'Disponible')");
        $stmtI->bind_param("ii", $idProducto, $enc['id_sucursal']);
        $stmtI->execute(); $stmtI->close();

        // Registrar ingreso (vincular la unidad al detalle de compra)
        $stmtR = $conn->prepare("INSERT INTO compras_detalle_ingresos (id_detalle, imei1, imei2, id_producto) VALUES (?,?,?,?)");
        $stmtR->bind_param("issi", $detalleId, $imei1, $imei2, $idProducto);
        $stmtR->execute(); $stmtR->close();
      }

      $conn->commit();
      header("Location: compras_ver.php?id=".$compraId);
      exit();

    } catch (Exception $e) {
      $conn->rollback();
      $errorMsg = $e->getMessage();
    }
  }
}

// ===== A partir de aquí ya podemos imprimir HTML =====
include 'navbar.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container my-4">
  <h4>Ingreso a inventario</h4>
  <p class="text-muted">
    <strong>Factura:</strong> <?= esc($enc['num_factura']) ?> ·
    <strong>Sucursal destino:</strong> <?= esc($enc['sucursal_nombre']) ?><br>
    <strong>Modelo:</strong> <?= esc($det['marca'].' '.$det['modelo'].' '.$det['capacidad'].' '.$det['color']) ?> ·
    <strong>Requiere IMEI:</strong> <?= $requiereImei ? 'Sí' : 'No' ?><br>
    <strong>Proveedor (compra):</strong> <?= esc($proveedorCompra ?: '—') ?>
  </p>

  <?php if (!empty($errorMsg)): ?>
    <div class="alert alert-danger"><?= esc($errorMsg) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <p><strong>Cantidad total:</strong> <?= (int)$det['cantidad'] ?> ·
         <strong>Ingresadas:</strong> <?= (int)$det['ingresadas'] ?> ·
         <strong>Pendientes:</strong> <?= $pendientes ?></p>

      <?php if ($pendientes <= 0): ?>
        <div class="alert alert-success">Este renglón ya está completamente ingresado.</div>
      <?php else: ?>
        <form method="POST">
          <input type="hidden" name="n" value="<?= $pendientes ?>">

          <!-- Precio de lista por modelo (aplica a TODAS las unidades) -->
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label">Precio de lista (por modelo)</label>
              <input
                type="text"
                name="precio_lista"
                class="form-control"
                inputmode="decimal"
                placeholder="Ej. 3999.00"
                value="<?= esc($precioListaForm) ?>"
                required
              >
              <small class="text-muted">
                Sugerido: $<?= number_format((float)$precioSugerido, 2) ?> (<?= esc($fuenteSugerido) ?>).
                Se aplicará a todas las unidades de este renglón.
              </small>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>#</th>
                  <th>IMEI1 <?= $requiereImei ? '*' : '' ?></th>
                  <th>IMEI2 (opcional)</th>
                </tr>
              </thead>
              <tbody>
                <?php for ($i=0;$i<$pendientes;$i++): ?>
                  <tr>
                    <td><?= $i+1 ?></td>
                    <td>
                      <input
                        class="form-control"
                        name="imei1[]"
                        <?= $requiereImei ? 'required' : '' ?>
                        inputmode="numeric"
                        minlength="15"
                        maxlength="15"
                        pattern="[0-9]{15}"
                        placeholder="15 dígitos"
                        title="Debe contener exactamente 15 dígitos"
                      >
                    </td>
                    <td>
                      <input
                        class="form-control"
                        name="imei2[]"
                        inputmode="numeric"
                        minlength="15"
                        maxlength="15"
                        pattern="[0-9]{15}"
                        placeholder="15 dígitos (opcional)"
                        title="Si lo capturas, deben ser 15 dígitos"
                      >
                    </td>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>

          <div class="text-end">
            <button class="btn btn-success">Ingresar a inventario</button>
            <a href="compras_ver.php?id=<?= (int)$compraId ?>" class="btn btn-outline-secondary">Cancelar</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
