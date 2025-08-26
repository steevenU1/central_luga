<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php"); exit();
}
include 'db.php';
include 'navbar.php';

date_default_timezone_set('America/Mexico_City');
$idUsuario = (int)$_SESSION['id_usuario'];
$rolSesion = $_SESSION['rol'] ?? '';

/* ========================
   Semanas mar→lun
======================== */
function obtenerSemanaPorIndice($offset = 0) {
  $tz = new DateTimeZone('America/Mexico_City');
  $hoy = new DateTime('now', $tz);
  $diaSemana = (int)$hoy->format('N'); // 1=Lun..7=Dom
  $dif = $diaSemana - 2; if ($dif < 0) $dif += 7; // martes=2
  $inicio = new DateTime('now', $tz);
  $inicio->modify('-'.$dif.' days')->setTime(0,0,0);
  if ($offset > 0) $inicio->modify('-'.(7*$offset).' days');
  $fin = (clone $inicio)->modify('+6 days')->setTime(23,59,59);
  return [$inicio, $fin];
}

$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($iniObj, $finObj) = obtenerSemanaPorIndice($semana);
$inicioSemana = $iniObj->format('Y-m-d 00:00:00');
$finSemana    = $finObj->format('Y-m-d 23:59:59');
$iniISO       = $iniObj->format('Y-m-d');
$finISO       = $finObj->format('Y-m-d');

/* ========================
   Acciones: Confirmar
======================== */
$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='confirmar') {
  // seguridad: solo puede confirmarse a sí mismo
  $uidPost = (int)($_POST['id_usuario'] ?? 0);
  if ($uidPost !== $idUsuario) { http_response_code(403); exit('Forbidden'); }

  // validar ventana (solo desde martes siguiente)
  $abreConfirm = (clone $finObj)->modify('+1 day')->setTime(0,0,0); // martes 00:00
  $ahora = new DateTime('now', new DateTimeZone('America/Mexico_City'));
  if ($ahora < $abreConfirm) {
    $msg = "La confirmación se habilita el martes posterior al cierre de la semana.";
  } else {
    $comentario = trim($_POST['comentario'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    // upsert "suave": intenta insertar; si existe, actualiza
    $stmt = $conn->prepare("
      INSERT INTO nomina_confirmaciones (id_usuario, semana_inicio, semana_fin, confirmado, comentario, confirmado_en, ip_confirmacion)
      VALUES (?,?,?,?,?,NOW(),?)
      ON DUPLICATE KEY UPDATE confirmado=VALUES(confirmado),
                              comentario=VALUES(comentario),
                              confirmado_en=VALUES(confirmado_en),
                              ip_confirmacion=VALUES(ip_confirmacion)
    ");
    $uno = 1;
    $stmt->bind_param("ississ", $idUsuario, $iniISO, $finISO, $uno, $comentario, $ip);
    if ($stmt->execute()) $msg = "✅ Confirmación registrada.";
    else $msg = "Error: ".$conn->error;
  }
}

/* ========================
   Datos del usuario
======================== */
$stmtU = $conn->prepare("
  SELECT u.id, u.nombre, u.rol, u.sueldo, s.nombre AS sucursal, u.id_sucursal
  FROM usuarios u
  INNER JOIN sucursales s ON s.id=u.id_sucursal
  WHERE u.id=?
  LIMIT 1
");
$stmtU->bind_param("i", $idUsuario);
$stmtU->execute();
$u = $stmtU->get_result()->fetch_assoc();
if (!$u) { echo "Usuario no encontrado."; exit; }

$id_sucursal = (int)$u['id_sucursal'];

/* ========================
   Cálculos (idénticos al reporte)
======================== */
// Equipos (comision)
$stmt = $conn->prepare("SELECT IFNULL(SUM(v.comision),0) AS total_comision FROM ventas v WHERE v.id_usuario=? AND v.fecha_venta BETWEEN ? AND ?");
$stmt->bind_param("iss", $idUsuario, $inicioSemana, $finSemana);
$stmt->execute();
$com_equipos = (float)($stmt->get_result()->fetch_assoc()['total_comision'] ?? 0);

// SIMs prepago
$com_sims = 0.0;
if ($u['rol'] != 'Gerente') {
  $stmt = $conn->prepare("SELECT IFNULL(SUM(vs.comision_ejecutivo),0) AS com_sims FROM ventas_sims vs WHERE vs.id_usuario=? AND vs.fecha_venta BETWEEN ? AND ? AND vs.tipo_venta IN ('Nueva','Portabilidad')");
  $stmt->bind_param("iss", $idUsuario, $inicioSemana, $finSemana);
  $stmt->execute();
  $com_sims = (float)($stmt->get_result()->fetch_assoc()['com_sims'] ?? 0);
}

// Pospago
$com_pospago = 0.0;
if ($u['rol'] != 'Gerente') {
  $stmt = $conn->prepare("SELECT IFNULL(SUM(vs.comision_ejecutivo),0) AS com_pos FROM ventas_sims vs WHERE vs.id_usuario=? AND vs.fecha_venta BETWEEN ? AND ? AND vs.tipo_venta='Pospago'");
  $stmt->bind_param("iss", $idUsuario, $inicioSemana, $finSemana);
  $stmt->execute();
  $com_pospago = (float)($stmt->get_result()->fetch_assoc()['com_pos'] ?? 0);
}

// Gerente por sucursal
$com_ger = 0.0;
if ($u['rol'] == 'Gerente') {
  $stmt = $conn->prepare("SELECT IFNULL(SUM(v.comision_gerente),0) AS com_ger_vtas FROM ventas v WHERE v.id_sucursal=? AND v.fecha_venta BETWEEN ? AND ?");
  $stmt->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
  $stmt->execute();
  $com_ger_vtas = (float)($stmt->get_result()->fetch_assoc()['com_ger_vtas'] ?? 0);

  $stmt = $conn->prepare("SELECT IFNULL(SUM(vs.comision_gerente),0) AS com_ger_sims FROM ventas_sims vs WHERE vs.id_sucursal=? AND vs.fecha_venta BETWEEN ? AND ?");
  $stmt->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
  $stmt->execute();
  $com_ger_sims = (float)($stmt->get_result()->fetch_assoc()['com_ger_sims'] ?? 0);
  $com_ger = $com_ger_vtas + $com_ger_sims;
}

// Descuentos
$stmt = $conn->prepare("SELECT IFNULL(SUM(monto),0) AS total FROM descuentos_nomina WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?");
$stmt->bind_param("iss", $idUsuario, $iniISO, $finISO);
$stmt->execute();
$descuentos = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

// Totales
$total_bruto = (float)$u['sueldo'] + $com_equipos + $com_sims + $com_pospago + $com_ger;
$total_neto  = $total_bruto - $descuentos;

/* ========================
   Estado de confirmación
======================== */
$stmt = $conn->prepare("SELECT confirmado, comentario, confirmado_en FROM nomina_confirmaciones WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?");
$stmt->bind_param("iss", $idUsuario, $iniISO, $finISO);
$stmt->execute();
$conf = $stmt->get_result()->fetch_assoc();

$confirmado = (int)($conf['confirmado'] ?? 0) === 1;
$confirmado_en = $conf['confirmado_en'] ?? null;
$comentario_prev = $conf['comentario'] ?? '';

$abreConfirm = (clone $finObj)->modify('+1 day')->setTime(0,0,0); // Martes 00:00 posterior
$ahora = new DateTime('now', new DateTimeZone('America/Mexico_City'));
$puedeConfirmar = ($ahora >= $abreConfirm);

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mi Nómina de la Semana</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{ --card-bg:#fff; --muted:#6b7280; --chip:#f1f5f9; }
    body{ background:#f7f7fb; }
    .card-soft{ background:var(--card-bg); border:1px solid #eef2f7; border-radius:1rem; box-shadow:0 6px 18px rgba(16,24,40,.06); }
    .chip{ display:inline-flex; gap:.5rem; align-items:center; background:var(--chip); border-radius:999px; padding:.35rem .7rem; font-size:.9rem; }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
      <span style="font-size:1.4rem">🧾</span>
      <div>
        <h4 class="mb-0">Mi Nómina</h4>
        <div class="text-muted small">Semana del <strong><?= $iniObj->format('d/m/Y') ?></strong> al <strong><?= $finObj->format('d/m/Y') ?></strong></div>
      </div>
    </div>
    <form method="get" class="d-flex align-items-center gap-2">
      <label class="form-label mb-0 small text-muted">Semana</label>
      <select name="semana" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
        <?php for ($i=0; $i<8; $i++):
            list($iniT, $finT) = obtenerSemanaPorIndice($i);
            $texto = "Del {$iniT->format('d/m/Y')} al {$finT->format('d/m/Y')}";
        ?>
          <option value="<?= $i ?>" <?= $i==$semana?'selected':'' ?>><?= $texto ?></option>
        <?php endfor; ?>
      </select>
    </form>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-info py-2"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <!-- Cards -->
  <div class="d-flex flex-wrap gap-3 mb-3">
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Colaborador</div>
      <div class="h6 mb-0"><?= htmlspecialchars($u['nombre']) ?> <span class="badge bg-secondary ms-2"><?= htmlspecialchars($u['rol']) ?></span></div>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Sucursal</div>
      <div class="h6 mb-0"><?= htmlspecialchars($u['sucursal']) ?></div>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Total Descuentos</div>
      <div class="h5 mb-0 text-danger">-$<?= number_format($descuentos,2) ?></div>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Total a Pagar (Neto)</div>
      <div class="h5 mb-0">$<?= number_format($total_neto,2) ?></div>
    </div>
  </div>

  <!-- Tabla desglose -->
  <div class="card-soft p-0 mb-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Concepto</th>
            <th class="text-end">Importe</th>
          </tr>
        </thead>
        <tbody>
          <tr><td>Sueldo Base</td><td class="text-end">$<?= number_format((float)$u['sueldo'],2) ?></td></tr>
          <tr><td>Comisiones Equipos</td><td class="text-end">$<?= number_format($com_equipos,2) ?></td></tr>
          <tr><td>Comisiones SIMs</td><td class="text-end">$<?= number_format($com_sims,2) ?></td></tr>
          <tr><td>Comisiones Pospago</td><td class="text-end">$<?= number_format($com_pospago,2) ?></td></tr>
          <tr><td>Comisión Gerente</td><td class="text-end">$<?= number_format($com_ger,2) ?></td></tr>
          <tr class="table-danger"><td>Descuentos</td><td class="text-end">-$<?= number_format($descuentos,2) ?></td></tr>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <td class="text-end"><strong>Total a Pagar (Neto)</strong></td>
            <td class="text-end"><strong>$<?= number_format($total_neto,2) ?></strong></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <!-- Confirmación -->
  <div class="card-soft p-3">
    <?php if ($confirmado): ?>
      <div class="d-flex align-items-center gap-2">
        <span class="text-success" style="font-size:1.2rem">✔️</span>
        <div>
          <div><strong>Confirmado</strong> el <?= $confirmado_en ? date('d/m/Y H:i', strtotime($confirmado_en)) : '' ?></div>
          <?php if ($comentario_prev): ?>
            <div class="text-muted small">Comentario: <?= htmlspecialchars($comentario_prev, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="mb-2 text-muted small">
        La confirmación se habilita el <strong>martes</strong> posterior al cierre de la semana (mar→lun).  
        Apertura para esta nómina: <span class="chip"><?= $abreConfirm->format('d/m/Y H:i') ?></span>
      </div>
      <form method="post" class="row g-2">
        <input type="hidden" name="action" value="confirmar">
        <input type="hidden" name="id_usuario" value="<?= $idUsuario ?>">
        <div class="col-12 col-md-8">
          <label class="form-label small text-muted">Comentario (opcional)</label>
          <input name="comentario" class="form-control form-control-sm" maxlength="255" placeholder="Estoy de acuerdo / tengo duda en ...">
        </div>
        <div class="col-12 col-md-4 d-flex align-items-end justify-content-end">
          <button class="btn btn-primary btn-sm" <?= $puedeConfirmar ? '' : 'disabled' ?>>
            <i class="bi bi-check2-circle me-1"></i> Confirmar nómina
          </button>
        </div>
      </form>
      <?php if (!$puedeConfirmar): ?>
        <div class="mt-2 text-muted small">Aún no es martes de confirmación.</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
