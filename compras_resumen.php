<?php
// compras_resumen.php
// Resumen de facturas de compra: filtros + acciones (Ver, Abonar, Ingresar a almacén)

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';
include 'navbar.php';

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$permEscritura = in_array($ROL, ['Admin','Gerente']);

// ====== Filtros ======
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function cap($s,$n){ return substr(trim($s ?? ''),0,$n); }

$estado   = cap($_GET['estado'] ?? 'todos', 20);           // todos|Pendiente|Parcial|Pagada|Cancelada
$prov_id  = (int)($_GET['proveedor'] ?? 0);
$suc_id   = (int)($_GET['sucursal'] ?? 0);
$desde    = cap($_GET['desde'] ?? '', 10);                 // YYYY-MM-DD
$hasta    = cap($_GET['hasta'] ?? '', 10);                 // YYYY-MM-DD
$q        = cap($_GET['q'] ?? '', 60);                     // búsqueda por # factura

$where = [];
$params = [];
$types = '';

if ($estado !== 'todos') { $where[] = "c.estatus = ?"; $params[] = $estado; $types.='s'; }
if ($prov_id > 0)        { $where[] = "c.id_proveedor = ?"; $params[] = $prov_id; $types.='i'; }
if ($suc_id > 0)         { $where[] = "c.id_sucursal = ?";  $params[] = $suc_id;  $types.='i'; }
if ($desde !== '')       { $where[] = "c.fecha_factura >= ?"; $params[] = $desde; $types.='s'; }
if ($hasta !== '')       { $where[] = "c.fecha_factura <= ?"; $params[] = $hasta; $types.='s'; }
if ($q !== '')           { $where[] = "c.num_factura LIKE ?"; $params[] = "%$q%"; $types.='s'; }

$sqlWhere = count($where) ? ('WHERE '.implode(' AND ', $where)) : '';

// Catálogos para filtros
$proveedores = $conn->query("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre");
$sucursales  = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");

// ====== Consulta principal ======
// - pagado: SUM(pagos)
// - saldo: total - pagado
// - pendientes_ingreso: suma por renglón (cantidad - ingresadas)
// - primer_detalle_pendiente: el id_detalle con pendiente para botón "Ingresar"
$sql = "
  SELECT
    c.id,
    c.num_factura,
    c.fecha_factura,
    c.fecha_vencimiento,
    c.subtotal,
    c.iva,
    c.total,
    c.estatus,
    p.nombre AS proveedor,
    s.nombre AS sucursal,
    IFNULL(pg.pagado, 0) AS pagado,
    (c.total - IFNULL(pg.pagado, 0)) AS saldo,
    IFNULL(ing.pendientes_ingreso, 0) AS pendientes_ingreso,
    ing.primer_detalle_pendiente
  FROM compras c
  INNER JOIN proveedores p ON p.id = c.id_proveedor
  INNER JOIN sucursales  s ON s.id = c.id_sucursal
  LEFT JOIN (
    SELECT id_compra, SUM(monto) AS pagado
    FROM compras_pagos
    GROUP BY id_compra
  ) pg ON pg.id_compra = c.id
  LEFT JOIN (
    SELECT
      d.id_compra,
      SUM( GREATEST(d.cantidad - IFNULL(x.ing,0), 0) ) AS pendientes_ingreso,
      MIN( CASE WHEN GREATEST(d.cantidad - IFNULL(x.ing,0), 0) > 0 THEN d.id END ) AS primer_detalle_pendiente
    FROM compras_detalle d
    LEFT JOIN (
      SELECT id_detalle, COUNT(*) AS ing
      FROM compras_detalle_ingresos
      GROUP BY id_detalle
    ) x ON x.id_detalle = d.id
    GROUP BY d.id_compra
  ) ing ON ing.id_compra = c.id
  $sqlWhere
  ORDER BY c.fecha_factura DESC, c.id DESC
";

// Ejecutar con binds si aplica
$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Error en prepare: ".$conn->error); }
if (strlen($types) > 0) {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$hoy = date('Y-m-d');
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container my-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <h3 class="mb-2">Resumen de compras</h3>
    <div>
      <a href="compras_nueva.php" class="btn btn-sm btn-primary">+ Nueva compra</a>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header">Filtros</div>
    <div class="card-body">
      <form class="row g-2">
        <div class="col-md-2">
          <label class="form-label">Estatus</label>
          <select name="estado" class="form-select" onchange="this.form.submit()">
            <?php
              $estados = ['todos'=>'Todos','Pendiente'=>'Pendiente','Parcial'=>'Parcial','Pagada'=>'Pagada','Cancelada'=>'Cancelada'];
              foreach ($estados as $val=>$txt): ?>
              <option value="<?= $val ?>" <?= $estado===$val?'selected':'' ?>><?= $txt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Proveedor</label>
          <select name="proveedor" class="form-select">
            <option value="0">Todos</option>
            <?php if($proveedores) while($p=$proveedores->fetch_assoc()): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $prov_id===(int)$p['id']?'selected':'' ?>>
                <?= esc($p['nombre']) ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Sucursal</label>
          <select name="sucursal" class="form-select">
            <option value="0">Todas</option>
            <?php if($sucursales) while($s=$sucursales->fetch_assoc()): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $suc_id===(int)$s['id']?'selected':'' ?>>
                <?= esc($s['nombre']) ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Desde</label>
          <input type="date" name="desde" value="<?= esc($desde) ?>" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Hasta</label>
          <input type="date" name="hasta" value="<?= esc($hasta) ?>" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label"># Factura</label>
          <input type="text" name="q" value="<?= esc($q) ?>" class="form-control" placeholder="Buscar por número">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-outline-primary w-100">Aplicar</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Proveedor</th>
              <th>Factura</th>
              <th>Sucursal</th>
              <th>Fecha</th>
              <th>Vence</th>
              <th class="text-end">Total</th>
              <th class="text-end">Pagado</th>
              <th class="text-end">Saldo</th>
              <th class="text-center">Pend. ingreso</th>
              <th class="text-center">Estatus</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $i=1;
          while($r = $res->fetch_assoc()):
            $saldo = (float)$r['saldo'];
            $vence = $r['fecha_vencimiento'];
            $rowClass = '';
            if ($r['estatus'] !== 'Pagada' && $saldo > 0 && $vence) {
              if ($vence < $hoy) $rowClass = 'table-danger';
              else {
                // Por vencer en ≤ 7 días
                if ($vence <= date('Y-m-d', strtotime('+7 days'))) $rowClass = 'table-warning';
              }
            }
          ?>
            <tr class="<?= $rowClass ?>">
              <td><?= $i++ ?></td>
              <td><?= esc($r['proveedor']) ?></td>
              <td><?= esc($r['num_factura']) ?></td>
              <td><?= esc($r['sucursal']) ?></td>
              <td><?= esc($r['fecha_factura']) ?></td>
              <td><?= esc($r['fecha_vencimiento'] ?: '-') ?></td>
              <td class="text-end">$<?= number_format($r['total'],2) ?></td>
              <td class="text-end">$<?= number_format($r['pagado'],2) ?></td>
              <td class="text-end fw-semibold">$<?= number_format($saldo,2) ?></td>
              <td class="text-center">
                <?php if ((int)$r['pendientes_ingreso'] > 0): ?>
                  <span class="badge bg-warning text-dark"><?= (int)$r['pendientes_ingreso'] ?></span>
                <?php else: ?>
                  <span class="badge bg-success">0</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php
                  $badge = 'secondary';
                  if ($r['estatus']==='Pagada') $badge='success';
                  elseif ($r['estatus']==='Parcial') $badge='warning text-dark';
                  elseif ($r['estatus']==='Pendiente') $badge='danger';
                ?>
                <span class="badge bg-<?= $badge ?>"><?= esc($r['estatus']) ?></span>
              </td>
              <td class="text-end">
                <div class="btn-group">
                  <a class="btn btn-sm btn-outline-secondary" href="compras_ver.php?id=<?= (int)$r['id'] ?>">Ver</a>
                  <a class="btn btn-sm btn-success" href="compras_pagos.php?id=<?= (int)$r['id'] ?>">Abonar</a>
                  <?php if ((int)$r['pendientes_ingreso'] > 0 && (int)$r['primer_detalle_pendiente'] > 0): ?>
                    <a class="btn btn-sm btn-primary"
                       href="compras_ingreso.php?detalle=<?= (int)$r['primer_detalle_pendiente'] ?>&compra=<?= (int)$r['id'] ?>">
                       Ingresar
                    </a>
                  <?php else: ?>
                    <button class="btn btn-sm btn-outline-secondary" disabled>Ingresar</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
