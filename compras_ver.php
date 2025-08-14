<?php
// compras_ver.php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
include 'db.php';
include 'navbar.php';

$id = (int)($_GET['id'] ?? 0);
if ($id<=0) die("ID inválido.");

$enc = $conn->query("
  SELECT c.*, p.nombre AS proveedor, s.nombre AS sucursal
  FROM compras c
  INNER JOIN proveedores p ON p.id=c.id_proveedor
  INNER JOIN sucursales s ON s.id=c.id_sucursal
  WHERE c.id=$id
")->fetch_assoc();
if (!$enc) die("Compra no encontrada.");

$det = $conn->query("
  SELECT d.*
       , (SELECT COUNT(*) FROM compras_detalle_ingresos x WHERE x.id_detalle=d.id) AS ingresadas
  FROM compras_detalle d
  WHERE d.id_compra=$id
");
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<div class="container my-4">
  <h4>Factura #<?= htmlspecialchars($enc['num_factura']) ?></h4>
  <p class="text-muted mb-1"><strong>Proveedor:</strong> <?= htmlspecialchars($enc['proveedor']) ?></p>
  <p class="text-muted mb-1"><strong>Sucursal destino:</strong> <?= htmlspecialchars($enc['sucursal']) ?></p>
  <p class="text-muted mb-3"><strong>Fechas:</strong> Factura <?= $enc['fecha_factura'] ?> · Vence <?= $enc['fecha_vencimiento'] ?: '-' ?></p>

  <div class="table-responsive">
    <table class="table table-bordered align-middle">
      <thead>
        <tr>
          <th>Marca</th><th>Modelo</th><th>Color</th><th>Capacidad</th>
          <th class="text-center">Req. IMEI</th>
          <th class="text-end">Cant.</th><th class="text-end">Ingresadas</th>
          <th class="text-end">P.Unit</th><th class="text-end">IVA%</th>
          <th class="text-end">Subtotal</th><th class="text-end">IVA</th><th class="text-end">Total</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php while($r=$det->fetch_assoc()): 
        $pend = max(0, (int)$r['cantidad'] - (int)$r['ingresadas']); ?>
        <tr class="<?= $pend>0 ? 'table-warning' : 'table-success' ?>">
          <td><?= htmlspecialchars($r['marca']) ?></td>
          <td><?= htmlspecialchars($r['modelo']) ?></td>
          <td><?= htmlspecialchars($r['color']) ?></td>
          <td><?= htmlspecialchars($r['capacidad']) ?></td>
          <td class="text-center"><?= $r['requiere_imei'] ? 'Sí' : 'No' ?></td>
          <td class="text-end"><?= (int)$r['cantidad'] ?></td>
          <td class="text-end"><?= (int)$r['ingresadas'] ?></td>
          <td class="text-end">$<?= number_format($r['precio_unitario'],2) ?></td>
          <td class="text-end"><?= number_format($r['iva_porcentaje'],2) ?></td>
          <td class="text-end">$<?= number_format($r['subtotal'],2) ?></td>
          <td class="text-end">$<?= number_format($r['iva'],2) ?></td>
          <td class="text-end">$<?= number_format($r['total'],2) ?></td>
          <td class="text-end">
            <?php if ($pend>0): ?>
              <a class="btn btn-sm btn-primary" href="compras_ingreso.php?detalle=<?= (int)$r['id'] ?>&compra=<?= $id ?>">Ingresar</a>
            <?php else: ?>
              <span class="badge bg-success">Completado</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="9" class="text-end">Subtotal</th><th class="text-end">$<?= number_format($enc['subtotal'],2) ?></th><th></th><th></th></tr>
        <tr><th colspan="10" class="text-end">IVA</th><th class="text-end">$<?= number_format($enc['iva'],2) ?></th><th></th></tr>
        <tr class="table-light"><th colspan="11" class="text-end fs-5">Total</th><th class="text-end fs-5">$<?= number_format($enc['total'],2) ?></th></tr>
      </tfoot>
    </table>
  </div>

  <a href="compras_nueva.php" class="btn btn-outline-secondary">Nueva compra</a>
</div>
