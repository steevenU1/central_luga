<?php
// compras_nueva.php
// Captura de factura de compra por renglones de MODELO (catálogo formal)

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';
include 'navbar.php';

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

// Permisos (ajusta si quieres permitir a más roles)
if (!in_array($ROL, ['Admin','Gerente'])) {
  header("Location: 403.php"); exit();
}

// Proveedores
$proveedores = [];
$res = $conn->query("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre");
while ($row = $res->fetch_assoc()) { $proveedores[] = $row; }

// Sucursales
$sucursales = [];
$res2 = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
while ($row = $res2->fetch_assoc()) { $sucursales[] = $row; }

// Catálogo de modelos (activos)
$modelos = [];
$res3 = $conn->query("SELECT id, marca, modelo, codigo_producto FROM catalogo_modelos WHERE activo=1 ORDER BY marca, modelo");
while ($row = $res3->fetch_assoc()) { $modelos[] = $row; }
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->

<div class="container my-4">
  <h3 class="mb-3">Nueva factura de compra</h3>

  <form action="compras_guardar.php" method="POST" id="formCompra">
    <div class="card shadow-sm mb-3">
      <div class="card-body row g-3">
        <div class="col-md-4">
          <label class="form-label">Proveedor *</label>
          <select name="id_proveedor" class="form-select" required>
            <option value="">-- Selecciona --</option>
            <?php foreach ($proveedores as $p): ?>
              <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label"># Factura *</label>
          <input type="text" name="num_factura" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Sucursal destino *</label>
          <select name="id_sucursal" class="form-select" required>
            <?php foreach ($sucursales as $s): ?>
              <option value="<?= $s['id'] ?>" <?= $s['id']==$ID_SUCURSAL ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">IVA % (default)</label>
          <input type="number" step="0.01" value="16" id="ivaDefault" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">Fecha factura *</label>
          <input type="date" name="fecha_factura" class="form-control" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Fecha vencimiento</label>
          <input type="date" name="fecha_vencimiento" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label">Notas</label>
          <input type="text" name="notas" class="form-control" maxlength="250" placeholder="Opcional">
        </div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Detalle por modelo</h5>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="modelos.php" target="_blank">➕ Nuevo modelo</a>
            <button type="button" class="btn btn-sm btn-primary" id="btnAgregar">+ Agregar renglón</button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-striped align-middle" id="tablaDetalle">
            <thead>
              <tr>
                <th style="min-width:260px;">Marca + Modelo</th>
                <th style="min-width:140px;">Color</th>
                <th style="min-width:140px;">Capacidad</th>
                <th style="width:110px;">Cantidad</th>
                <th style="width:140px;">P. Unitario</th>
                <th style="width:100px;">IVA %</th>
                <th style="width:150px;">Subtotal</th>
                <th style="width:120px;">IVA</th>
                <th style="width:150px;">Total</th>
                <th style="width:150px;">Requiere IMEI</th>
                <th style="width:60px;"></th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <div class="row justify-content-end mt-3">
          <div class="col-md-4">
            <div class="card border-0 bg-light">
              <div class="card-body">
                <div class="d-flex justify-content-between mb-1">
                  <strong>Subtotal</strong><strong id="lblSubtotal">$0.00</strong>
                </div>
                <div class="d-flex justify-content-between mb-1">
                  <strong>IVA</strong><strong id="lblIVA">$0.00</strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between fs-5">
                  <strong>Total</strong><strong id="lblTotal">$0.00</strong>
                </div>
              </div>
            </div>
          </div>
        </div>

        <input type="hidden" name="subtotal" id="inpSubtotal">
        <input type="hidden" name="iva" id="inpIVA">
        <input type="hidden" name="total" id="inpTotal">
      </div>
    </div>

    <div class="text-end mt-3">
      <button type="submit" class="btn btn-success">Guardar factura</button>
    </div>
  </form>
</div>

<script>
const modelos = <?= json_encode($modelos) ?>;
const tbody = document.querySelector('#tablaDetalle tbody');
const ivaDefault = document.getElementById('ivaDefault');

let rowIdx = 0;

function formato(n){ return new Intl.NumberFormat('es-MX',{minimumFractionDigits:2, maximumFractionDigits:2}).format(n||0); }

function calcTotales(){
  let sub = 0, iva = 0, tot = 0;
  document.querySelectorAll('tr.renglon').forEach(tr => {
    const qty = parseFloat(tr.querySelector('.qty').value) || 0;
    const pu  = parseFloat(tr.querySelector('.pu').value)  || 0;
    const ivp = parseFloat(tr.querySelector('.ivp').value) || 0;
    const rsub = qty * pu;
    const riva = rsub * (ivp/100.0);
    const rtot = rsub + riva;
    tr.querySelector('.rsub').textContent = '$'+formato(rsub);
    tr.querySelector('.riva').textContent = '$'+formato(riva);
    tr.querySelector('.rtot').textContent = '$'+formato(rtot);
    sub += rsub; iva += riva; tot += rtot;
  });
  document.getElementById('lblSubtotal').textContent = '$'+formato(sub);
  document.getElementById('lblIVA').textContent      = '$'+formato(iva);
  document.getElementById('lblTotal').textContent    = '$'+formato(tot);
  document.getElementById('inpSubtotal').value = sub.toFixed(2);
  document.getElementById('inpIVA').value      = iva.toFixed(2);
  document.getElementById('inpTotal').value    = tot.toFixed(2);
}

function optionModelos(){
  return `
    <option value="">-- Selecciona --</option>
    ${modelos.map(m => `<option value="${m.id}">${m.marca} ${m.modelo}${m.codigo_producto ? ' · '+m.codigo_producto : ''}</option>`).join('')}
  `;
}

function agregarRenglon(){
  const idx = rowIdx++;
  const tr = document.createElement('tr');
  tr.className = 'renglon';
  tr.innerHTML = `
    <td>
      <select name="id_modelo[${idx}]" class="form-select selMM" required>
        ${optionModelos()}
      </select>
    </td>
    <td><input type="text" class="form-control color" name="color[${idx}]" placeholder="p. ej. Negro" required></td>
    <td><input type="text" class="form-control capacidad" name="capacidad[${idx}]" placeholder="p. ej. 128GB" required></td>
    <td><input type="number" min="1" value="1" class="form-control qty" name="cantidad[${idx}]" required></td>
    <td><input type="number" step="0.01" min="0" value="0" class="form-control pu" name="precio_unitario[${idx}]" required></td>
    <td><input type="number" step="0.01" min="0" class="form-control ivp" name="iva_porcentaje[${idx}]" value="${ivaDefault.value || 16}"></td>
    <td class="rsub">$0.00</td>
    <td class="riva">$0.00</td>
    <td class="rtot">$0.00</td>
    <td class="text-center">
      <input type="hidden" name="requiere_imei[${idx}]" value="0">
      <input type="checkbox" class="form-check-input reqi" name="requiere_imei[${idx}]" value="1" checked>
    </td>
    <td><button type="button" class="btn btn-sm btn-outline-danger btnQuitar">&times;</button></td>
  `;
  tbody.appendChild(tr);
  tr.querySelectorAll('input,select').forEach(el => el.addEventListener('input', calcTotales));
  tr.querySelector('.btnQuitar').addEventListener('click', () => { tr.remove(); calcTotales(); });
  calcTotales();
}

document.getElementById('btnAgregar').addEventListener('click', agregarRenglon);
ivaDefault.addEventListener('input', () => {
  document.querySelectorAll('.ivp').forEach(i => i.value = ivaDefault.value || 16);
  calcTotales();
});

// arranca con 1 renglón
agregarRenglon();

// Validación mínima
document.getElementById('formCompra').addEventListener('submit', function(e){
  if (!tbody.querySelector('tr')) {
    e.preventDefault();
    alert('Agrega al menos un renglón');
  }
});
</script>
