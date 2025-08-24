<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';
include 'navbar.php';

/* ========================
   FUNCIONES AUXILIARES (solo UI de semanas)
======================== */
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=Lunes ... 7=Domingo
    $dif = $diaSemana - 2; // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0,0,0);
    if ($offset > 0) $inicio->modify("-" . (7*$offset) . " days");

    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23,59,59);

    return [$inicio, $fin];
}

$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d 00:00:00');
$finSemana    = $finSemanaObj->format('Y-m-d 23:59:59');

/* ========================
   CONSULTA DE USUARIOS
   (Excluye almacenes y, si existe la columna, subdistribuidores)
======================== */
// Detecta si existe una columna de subtipo; prueba varios nombres comunes.
$subdistCol = null;
foreach (['subtipo_sucursal','subtipo','sub_tipo','tipo_subsucursal'] as $c) {
    $rs = $conn->query("SHOW COLUMNS FROM sucursales LIKE '$c'");
    if ($rs && $rs->num_rows > 0) { $subdistCol = $c; break; }
}

$where = "s.tipo_sucursal <> 'Almacen'";
if ($subdistCol) {
    $where .= " AND (s.`$subdistCol` IS NULL OR s.`$subdistCol` <> 'Subdistribuidor')";
}

$sqlUsuarios = "
    SELECT u.id, u.nombre, u.rol, u.sueldo, s.nombre AS sucursal, u.id_sucursal
    FROM usuarios u
    INNER JOIN sucursales s ON s.id=u.id_sucursal
    WHERE $where
    ORDER BY s.nombre, FIELD(u.rol,'Gerente','Ejecutivo'), u.nombre
";
$resUsuarios = $conn->query($sqlUsuarios);

$nomina = [];

while ($u = $resUsuarios->fetch_assoc()) {
    $id_usuario  = (int)$u['id'];
    $id_sucursal = (int)$u['id_sucursal'];

    /* ========================
       1) Comisiones de EQUIPOS (ejecutivo)
======================== */
    $sqlEquipos = "
        SELECT IFNULL(SUM(v.comision),0) AS total_comision
        FROM ventas v
        WHERE v.id_usuario=? 
          AND v.fecha_venta BETWEEN ? AND ?
    ";
    $stmtEquip = $conn->prepare($sqlEquipos);
    $stmtEquip->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtEquip->execute();
    $com_equipos = (float)($stmtEquip->get_result()->fetch_assoc()['total_comision'] ?? 0);

    /* ========================
       2) Comisiones de SIMs PREPAGO (ejecutivo)
======================== */
    $com_sims = 0.0;
    if ($u['rol'] != 'Gerente') {
        $sqlSims = "
            SELECT IFNULL(SUM(vs.comision_ejecutivo),0) AS com_sims
            FROM ventas_sims vs
            WHERE vs.id_usuario=?
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta IN ('Nueva','Portabilidad')
        ";
        $stmtSims = $conn->prepare($sqlSims);
        $stmtSims->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtSims->execute();
        $com_sims = (float)($stmtSims->get_result()->fetch_assoc()['com_sims'] ?? 0);
    }

    /* ========================
       3) Comisiones de POSPAGO (ejecutivo)
======================== */
    $com_pospago = 0.0;
    if ($u['rol'] != 'Gerente') {
        $sqlPos = "
            SELECT IFNULL(SUM(vs.comision_ejecutivo),0) AS com_pos
            FROM ventas_sims vs
            WHERE vs.id_usuario=?
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta='Pospago'
        ";
        $stmtPos = $conn->prepare($sqlPos);
        $stmtPos->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtPos->execute();
        $com_pospago = (float)($stmtPos->get_result()->fetch_assoc()['com_pos'] ?? 0);
    }

    /* ========================
       4) Comisión de GERENTE (por sucursal)
======================== */
    $com_ger = 0.0;
    if ($u['rol'] == 'Gerente') {
        $sqlComGerV = "
            SELECT IFNULL(SUM(v.comision_gerente),0) AS com_ger_vtas
            FROM ventas v
            WHERE v.id_sucursal=? 
              AND v.fecha_venta BETWEEN ? AND ?
        ";
        $stmtGerV = $conn->prepare($sqlComGerV);
        $stmtGerV->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtGerV->execute();
        $com_ger_vtas = (float)($stmtGerV->get_result()->fetch_assoc()['com_ger_vtas'] ?? 0);

        $sqlComGerS = "
            SELECT IFNULL(SUM(vs.comision_gerente),0) AS com_ger_sims
            FROM ventas_sims vs
            WHERE vs.id_sucursal=? 
              AND vs.fecha_venta BETWEEN ? AND ?
        ";
        $stmtGerS = $conn->prepare($sqlComGerS);
        $stmtGerS->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtGerS->execute();
        $com_ger_sims = (float)($stmtGerS->get_result()->fetch_assoc()['com_ger_sims'] ?? 0);

        $com_ger = $com_ger_vtas + $com_ger_sims;
    }

    /* ========================
       5) Totales
======================== */
    $total_ejecutivo = (float)$u['sueldo'] + $com_equipos + $com_sims + $com_pospago;
    $total           = $total_ejecutivo + $com_ger;

    $nomina[] = [
        'id_usuario'   => $id_usuario,
        'id_sucursal'  => $id_sucursal,
        'nombre'       => $u['nombre'],
        'rol'          => $u['rol'],
        'sucursal'     => $u['sucursal'],
        'sueldo'       => (float)$u['sueldo'],
        'com_equipos'  => $com_equipos,
        'com_sims'     => $com_sims,
        'com_pospago'  => $com_pospago,
        'com_ger'      => $com_ger,
        'total'        => $total
    ];
}

// Totales para tarjetas-resumen (no afecta lógica)
$empleados = count($nomina);
$totalGlobalHeader = 0;
foreach($nomina as $n){ $totalGlobalHeader += $n['total']; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reporte de Nómina Semanal</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{
      --card-bg:#fff; --muted:#6b7280; --chip:#f1f5f9;
    }
    body{ background:#f7f7fb; }
    .page-header{ display:flex; gap:1rem; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .page-title{ display:flex; gap:.75rem; align-items:center; }
    .page-title .emoji{ font-size:1.6rem; }
    .card-soft{ background:var(--card-bg); border:1px solid #eef2f7; border-radius:1rem; box-shadow:0 6px 18px rgba(16,24,40,.06); }
    .chip{ display:inline-flex; gap:.5rem; align-items:center; background:var(--chip); border-radius:999px; padding:.4rem .7rem; font-size:.9rem; }
    .controls-right{ display:flex; gap:.5rem; flex-wrap:wrap; }
    .table thead th{ position:sticky; top:0; z-index:5; background:#fff; border-bottom:1px solid #e5e7eb; }
    .th-sort{ cursor:pointer; white-space:nowrap; }
    .badge-role{ background:#eef2ff; color:#3730a3; border:1px solid #e0e7ff; }
    .badge-suc{ background:#f0fdf4; color:#14532d; border:1px solid #bbf7d0; }
    .summary-cards .card-soft{ min-width: 220px; }
    @media print{
      .no-print{ display:none !important; }
      body{ background:#fff; }
      .card-soft{ box-shadow:none; border:0; }
      .table thead th{ position:static; }
    }
  </style>
</head>
<body>

<div class="container py-4">
  <!-- Header -->
  <div class="page-header mb-3">
    <div class="page-title">
      <span class="emoji">📋</span>
      <div>
        <h3 class="mb-0">Reporte de Nómina Semanal</h3>
        <div class="text-muted small">
          Semana del <strong><?= $inicioSemanaObj->format('d/m/Y') ?></strong> al <strong><?= $finSemanaObj->format('d/m/Y') ?></strong>
        </div>
      </div>
    </div>

    <div class="controls-right no-print">
      <form method="GET" class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 small text-muted">Semana</label>
        <select name="semana" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
          <?php for ($i=0; $i<8; $i++):
              list($ini, $fin) = obtenerSemanaPorIndice($i);
              $texto = "Del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
          ?>
            <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $texto ?></option>
          <?php endfor; ?>
        </select>
        <a href="recalculo_total_comisiones.php?semana=<?= $semanaSeleccionada ?>" 
           class="btn btn-warning btn-sm"
           onclick="return confirm('¿Seguro que deseas recalcular las comisiones de esta semana?');">
           <i class="bi bi-arrow-repeat me-1"></i> Recalcular
        </a>
        <a href="exportar_nomina_excel.php?semana=<?= $semanaSeleccionada ?>" class="btn btn-success btn-sm">
          <i class="bi bi-file-earmark-excel me-1"></i> Exportar Excel
        </a>
        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()">
          <i class="bi bi-printer me-1"></i> Imprimir
        </button>
      </form>
    </div>
  </div>

  <!-- Summary -->
  <div class="summary-cards d-flex flex-wrap gap-3 mb-3">
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Empleados</div>
      <div class="h4 mb-0"><?= number_format($empleados) ?></div>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Total Global</div>
      <div class="h4 mb-0">$<?= number_format($totalGlobalHeader,2) ?></div>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Rango</div>
      <div class="mb-0"><span class="chip"><i class="bi bi-calendar-week me-1"></i><?= $inicioSemanaObj->format('d M') ?> – <?= $finSemanaObj->format('d M Y') ?></span></div>
    </div>
    <div class="card-soft p-3 no-print" style="flex:1">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6">
          <label class="form-label mb-1 small text-muted">Filtrar por rol</label>
          <select id="fRol" class="form-select form-select-sm">
            <option value="">Todos</option>
            <option value="Ejecutivo">Ejecutivo</option>
            <option value="Gerente">Gerente</option>
          </select>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label mb-1 small text-muted">Buscar</label>
          <input id="fSearch" type="search" class="form-control form-control-sm" placeholder="Empleado, sucursal...">
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla -->
  <div class="card-soft p-0">
    <div class="table-responsive">
      <table id="tablaNomina" class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Empleado</th>
            <th class="th-sort" data-key="rol">Rol <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="th-sort" data-key="sucursal">Sucursal <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="sueldo">Sueldo Base <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="equipos">Com. Equipos <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="sims">Com. SIMs <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="pospago">Com. Pospago <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="gerente">Com. Gerente <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="total">Total a Pagar <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="no-print"></th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $totalGlobal = 0;
          foreach ($nomina as $n): 
              $totalGlobal += $n['total'];
              $isGer = ($n['rol'] === 'Gerente');
          ?>
          <tr
            data-rol="<?= htmlspecialchars($n['rol'], ENT_QUOTES, 'UTF-8') ?>"
            data-sucursal="<?= htmlspecialchars($n['sucursal'], ENT_QUOTES, 'UTF-8') ?>"
            data-sueldo="<?= (float)$n['sueldo'] ?>"
            data-equipos="<?= (float)$n['com_equipos'] ?>"
            data-sims="<?= (float)$n['com_sims'] ?>"
            data-pospago="<?= (float)$n['com_pospago'] ?>"
            data-gerente="<?= (float)$n['com_ger'] ?>"
            data-total="<?= (float)$n['total'] ?>"
          >
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($n['nombre'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="small text-muted">
                <?php if ($isGer): ?>
                  <a href="auditar_comisiones_gerente.php?semana=<?= $semanaSeleccionada ?>&id_sucursal=<?= (int)$n['id_sucursal'] ?>">🔍 Detalle</a>
                <?php else: ?>
                  <a href="auditar_comisiones_ejecutivo.php?semana=<?= $semanaSeleccionada ?>&id_usuario=<?= (int)$n['id_usuario'] ?>">🔍 Detalle</a>
                <?php endif; ?>
              </div>
            </td>
            <td><span class="badge badge-role rounded-pill"><?= htmlspecialchars($n['rol'], ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><span class="badge badge-suc rounded-pill"><?= htmlspecialchars($n['sucursal'], ENT_QUOTES, 'UTF-8') ?></span></td>
            <td class="text-end">$<?= number_format($n['sueldo'],2) ?></td>
            <td class="text-end">$<?= number_format($n['com_equipos'],2) ?></td>
            <td class="text-end">$<?= number_format($n['com_sims'],2) ?></td>
            <td class="text-end">$<?= number_format($n['com_pospago'],2) ?></td>
            <td class="text-end">$<?= number_format($n['com_ger'],2) ?></td>
            <td class="text-end fw-semibold">$<?= number_format($n['total'],2) ?></td>
            <td class="no-print"></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <td colspan="8" class="text-end"><strong>Total Global</strong></td>
            <td class="text-end"><strong>$<?= number_format($totalGlobal,2) ?></strong></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <div class="mt-2 text-muted small">
    * Los importes de comisiones ya consideran el recálculo semanal correspondiente.
  </div>
</div>

<script>
  // Buscar + filtro por rol
  const fRol = document.getElementById('fRol');
  const fSearch = document.getElementById('fSearch');
  const tbody = document.querySelector('#tablaNomina tbody');

  function applyFilters(){
    const rol = (fRol.value||'').toLowerCase();
    const q = (fSearch.value||'').toLowerCase();
    [...tbody.rows].forEach(tr=>{
      const trRol = (tr.dataset.rol||'').toLowerCase();
      const text = (tr.textContent||'').toLowerCase();
      let ok = true;
      if (rol && trRol !== rol) ok = false;
      if (q && !text.includes(q)) ok = false;
      tr.style.display = ok ? '' : 'none';
    });
  }
  [fRol,fSearch].forEach(el=>el && el.addEventListener('input', applyFilters));

  // Ordenamiento
  let sortState = { key:null, dir:1 };
  document.querySelectorAll('.th-sort').forEach(th=>{
    th.addEventListener('click', ()=>{
      const key = th.dataset.key;
      sortState.dir = (sortState.key===key) ? -sortState.dir : 1;
      sortState.key = key;
      sortRows(key, sortState.dir);
    });
  });

  function sortRows(key, dir){
    const rows = [...tbody.rows];
    rows.sort((a,b)=>{
      const va = a.dataset[key] ?? '';
      const vb = b.dataset[key] ?? '';
      const na = Number(va), nb = Number(vb);
      if(!Number.isNaN(na) && !Number.isNaN(nb)) return (na-nb)*dir;
      return String(va).localeCompare(String(vb), 'es', {numeric:true, sensitivity:'base'}) * dir;
    });
    rows.forEach(r=>tbody.appendChild(r));
  }
</script>
</body>
</html>
