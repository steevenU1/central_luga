<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idUsuario         = (int)$_SESSION['id_usuario'];
$idSucursalOrigen  = (int)$_SESSION['id_sucursal'];

// 🔹 Sucursales destino (todas menos la propia)
$sqlSucursales = "SELECT id, nombre FROM sucursales WHERE id != ?";
$stmt = $conn->prepare($sqlSucursales);
$stmt->bind_param("i", $idSucursalOrigen);
$stmt->execute();
$res = $stmt->get_result();
$sucursales = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 🔹 Cajas disponibles en origen (solo SIMs disponibles)
$sqlCajas = "
    SELECT caja_id, COUNT(*) as total_sims
    FROM inventario_sims
    WHERE id_sucursal = ? AND estatus = 'Disponible'
    GROUP BY caja_id
    ORDER BY caja_id
";
$stmt = $conn->prepare($sqlCajas);
$stmt->bind_param("i", $idSucursalOrigen);
$stmt->execute();
$res = $stmt->get_result();
$cajas = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// KPIs (solo front)
$totalCajas = count($cajas);
$totalSIMs  = 0;
foreach ($cajas as $c) { $totalSIMs += (int)$c['total_sims']; }

$mensaje = '';

// 🔹 Procesar formulario (misma lógica)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['caja_id'], $_POST['id_sucursal_destino'])) {
    $cajaId            = trim($_POST['caja_id']);
    $idSucursalDestino = (int)$_POST['id_sucursal_destino'];

    // Validar existencia de SIMs disponibles en esa caja
    $stmtValidar = $conn->prepare("
        SELECT id FROM inventario_sims
        WHERE id_sucursal = ? AND estatus = 'Disponible' AND caja_id = ?
    ");
    $stmtValidar->bind_param("is", $idSucursalOrigen, $cajaId);
    $stmtValidar->execute();
    $resultSims = $stmtValidar->get_result();
    $stmtValidar->close();

    if ($resultSims->num_rows == 0) {
        $mensaje = "<div class='alert alert-danger card-surface mt-3'>❌ No hay SIMs disponibles en la caja <b>".htmlspecialchars($cajaId,ENT_QUOTES)."</b>.</div>";
    } else {
        // 1️⃣ Crear traspaso
        $stmtTraspaso = $conn->prepare("
            INSERT INTO traspasos_sims (id_sucursal_origen, id_sucursal_destino, usuario_creo, estatus, fecha_traspaso)
            VALUES (?, ?, ?, 'Pendiente', NOW())
        ");
        $stmtTraspaso->bind_param("iii", $idSucursalOrigen, $idSucursalDestino, $idUsuario);
        $stmtTraspaso->execute();
        $idTraspaso = $stmtTraspaso->insert_id;
        $stmtTraspaso->close();

        // 2️⃣ Pasar todas las SIMs de la caja
        while ($sim = $resultSims->fetch_assoc()) {
            $idSim = (int)$sim['id'];

            $stmtDetalle = $conn->prepare("INSERT INTO detalle_traspaso_sims (id_traspaso, id_sim) VALUES (?, ?)");
            $stmtDetalle->bind_param("ii", $idTraspaso, $idSim);
            $stmtDetalle->execute();
            $stmtDetalle->close();

            $stmtUpdate = $conn->prepare("UPDATE inventario_sims SET estatus='En tránsito' WHERE id=?");
            $stmtUpdate->bind_param("i", $idSim);
            $stmtUpdate->execute();
            $stmtUpdate->close();
        }

        $mensaje = "<div class='alert alert-success card-surface mt-3'>✅ Traspaso generado con éxito. Caja <b>".htmlspecialchars($cajaId,ENT_QUOTES)."</b> enviada a la sucursal destino.</div>";
    }
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Generar Traspaso de SIMs</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{ --surface:#ffffff; --muted:#6b7280; }
    body{ background:#f6f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .small-muted{ color:var(--muted); font-size:.92rem; }
    .card-surface{ background:var(--surface); border:1px solid rgba(0,0,0,.05); box-shadow:0 6px 16px rgba(16,24,40,.06); border-radius:18px; }
    .stat{ display:flex; align-items:center; gap:.75rem; }
    .stat .icon{ width:40px; height:40px; border-radius:12px; display:grid; place-items:center; background:#eef2ff; }
    .chip{ display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem; border-radius:999px; font-weight:600; font-size:.85rem; border:1px solid transparent; }
    .chip-info{ background:#e8f0fe; color:#1a56db; border-color:#cbd8ff; }
    .btn-soft{ border:1px solid rgba(0,0,0,.08); background:#fff; }
    .btn-soft:hover{ background:#f9fafb; }
    .filters .form-control, .filters .form-select{ height:42px; }
    .tbl-wrap{ overflow:auto; border-radius:14px; }
    .table thead th{ position:sticky; top:0; z-index:1; }
    .caja-row{ cursor:pointer; }
    .caja-row.active{ outline:2px solid #1a56db33; background:#f3f6ff; }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container py-3">
  <!-- Encabezado -->
  <div class="page-header">
    <div>
      <h1 class="page-title">🚚 Generar Traspaso de SIMs <span class="text-muted">(por caja)</span></h1>
      <div class="small-muted">Sucursal origen: <strong>#<?= (int)$idSucursalOrigen ?></strong></div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="chip chip-info"><i class="bi bi-box-seam"></i> Cajas con SIM: <?= (int)$totalCajas ?></span>
      <span class="chip chip-info"><i class="bi bi-sim"></i> SIMs disponibles: <?= (int)$totalSIMs ?></span>
    </div>
  </div>

  <?= $mensaje ?>

  <!-- Formulario -->
  <form id="formTraspaso" method="POST" class="card card-surface p-3 mt-3">
    <div class="row g-3 filters">
      <div class="col-12 col-lg-5">
        <label class="small-muted">Selecciona Caja</label>
        <select name="caja_id" id="cajaSelect" class="form-select" required <?= $totalCajas===0?'disabled':'' ?>>
          <option value="">-- Selecciona una caja --</option>
          <?php foreach ($cajas as $c): ?>
            <option value="<?= h($c['caja_id']) ?>">
              <?= h($c['caja_id']) ?> (<?= (int)$c['total_sims'] ?> SIMs)
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($totalCajas===0): ?>
          <div class="form-text text-warning">No hay cajas con SIMs disponibles en esta sucursal.</div>
        <?php endif; ?>
      </div>

      <div class="col-12 col-lg-5">
        <label class="small-muted">Selecciona Sucursal Destino</label>
        <select name="id_sucursal_destino" id="sucursalSelect" class="form-select" required <?= empty($sucursales)?'disabled':'' ?>>
          <option value="">-- Selecciona sucursal --</option>
          <?php foreach ($sucursales as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (empty($sucursales)): ?>
          <div class="form-text text-warning">No hay otras sucursales configuradas.</div>
        <?php endif; ?>
      </div>

      <div class="col-12 col-lg-2 d-flex align-items-end">
        <button type="button" id="btnConfirmar" class="btn btn-primary w-100" <?= ($totalCajas===0 || empty($sucursales))?'disabled':'' ?>>
          <i class="bi bi-arrow-right-circle"></i> Generar
        </button>
      </div>
    </div>

    <!-- Ayuda -->
    <div class="small-muted mt-2">
      Tip: abajo puedes ver y filtrar las cajas disponibles; al hacer clic en una fila, se selecciona automáticamente.
    </div>
  </form>

  <!-- Listado de cajas (visual, no cambia lógica) -->
  <div class="card card-surface mt-3 mb-5">
    <div class="p-3 pb-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h5 class="m-0"><i class="bi bi-table me-2"></i>Cajas disponibles</h5>
      <div class="d-flex align-items-center gap-2">
        <input id="qFront" class="form-control" placeholder="Buscar por ID de caja…" style="height:42px; width:220px;">
      </div>
    </div>
    <div class="p-3 pt-2 tbl-wrap">
      <table id="tablaCajas" class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="min-width:160px;">ID Caja</th>
            <th style="min-width:140px;">SIMs disponibles</th>
            <th style="min-width:220px;">Seleccionar</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cajas as $c): ?>
            <tr class="caja-row" data-caja="<?= h($c['caja_id']) ?>">
              <td class="fw-semibold"><span class="badge text-bg-secondary"><?= h($c['caja_id']) ?></span></td>
              <td><?= (int)$c['total_sims'] ?></td>
              <td>
                <button type="button" class="btn btn-soft btn-sm pick-caja" data-caja="<?= h($c['caja_id']) ?>">
                  <i class="bi bi-check2-circle"></i> Usar esta caja
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($totalCajas===0): ?>
            <tr><td colspan="3" class="text-center small-muted">Sin cajas disponibles.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Modal confirmación -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirmar traspaso</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">Vas a generar un traspaso con los siguientes datos:</p>
        <ul class="mb-0">
          <li><strong>Caja:</strong> <span id="confCaja">—</span></li>
          <li><strong>Destino:</strong> <span id="confSucursal">—</span></li>
        </ul>
        <div class="alert alert-warning mt-3 mb-0">
          <i class="bi bi-info-circle"></i> Todas las SIMs disponibles de la caja seleccionada cambiarán a <b>“En tránsito”</b>.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button id="btnSubmit" class="btn btn-primary">
          <i class="bi bi-arrow-right-circle"></i> Confirmar y generar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
(() => {
  const cajaSelect     = document.getElementById('cajaSelect');
  const sucursalSelect = document.getElementById('sucursalSelect');
  const btnConfirmar   = document.getElementById('btnConfirmar');
  const form           = document.getElementById('formTraspaso');

  // Selección rápida desde la tabla
  document.querySelectorAll('.pick-caja').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-caja');
      cajaSelect.value = id;
      // Resaltar fila
      document.querySelectorAll('#tablaCajas tbody tr').forEach(tr => tr.classList.remove('active'));
      btn.closest('tr')?.classList.add('active');
      cajaSelect.focus();
    });
  });

  // Búsqueda rápida por ID de caja (front)
  const q = document.getElementById('qFront');
  const rows = Array.from(document.querySelectorAll('#tablaCajas tbody tr'));
  const norm = s => (s||'').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,'');
  q?.addEventListener('input', () => {
    const val = norm(q.value);
    rows.forEach(tr => {
      const match = !val || norm(tr.getAttribute('data-caja')).includes(val);
      tr.style.display = match ? '' : 'none';
    });
  });

  // Modal de confirmación
  const modalEl = document.getElementById('confirmModal');
  const modal   = new bootstrap.Modal(modalEl);
  btnConfirmar?.addEventListener('click', () => {
    if (!cajaSelect.value || !sucursalSelect.value) {
      // feedback simple
      [cajaSelect, sucursalSelect].forEach(el => el.classList.toggle('is-invalid', !el.value));
      return;
    }
    document.getElementById('confCaja').textContent     = cajaSelect.value;
    document.getElementById('confSucursal').textContent = sucursalSelect.options[sucursalSelect.selectedIndex].text;
    modal.show();
  });

  // Enviar después de confirmar
  document.getElementById('btnSubmit')?.addEventListener('click', () => {
    form.submit();
  });
})();
</script>
</body>
</html>
