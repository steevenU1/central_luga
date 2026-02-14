<?php
// solicitar_vacaciones.php — Usuario solicita vacaciones (rango) -> Admin aprueba/rechaza
ob_start();
session_start();

if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
if (!in_array($ROL, ['Ejecutivo','Gerente','Admin'], true)) { // Admin opcional para probar
  header("Location: 403.php"); exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function table_exists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $q = $conn->query("SHOW TABLES LIKE '{$t}'");
  return $q && $q->num_rows > 0;
}

$msg=''; $cls='info';

if (!table_exists($conn,'vacaciones_solicitudes')) {
  $msg = "❌ No existe la tabla vacaciones_solicitudes. Corre el SQL primero.";
  $cls = "danger";
}

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);

// Traer datos del usuario (validamos que sea tienda propia, igual que tu admin_asistencias)
$userRow = null;
$stU = $conn->prepare("
  SELECT u.id, u.nombre, u.id_sucursal, s.nombre AS sucursal
  FROM usuarios u
  JOIN sucursales s ON s.id=u.id_sucursal
  WHERE u.id=? AND u.activo=1 AND u.rol IN ('Gerente','Ejecutivo')
    AND s.tipo_sucursal='tienda' AND s.subtipo='propia'
  LIMIT 1
");
$stU->bind_param('i',$idUsuario);
$stU->execute();
$userRow = $stU->get_result()->fetch_assoc();
$stU->close();

if (!$userRow) {
  $msg = "❌ Usuario no elegible para solicitar vacaciones (o no pertenece a tienda propia).";
  $cls = "danger";
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $userRow && table_exists($conn,'vacaciones_solicitudes')) {
  $fini = trim($_POST['fecha_inicio'] ?? '');
  $ffin = trim($_POST['fecha_fin'] ?? '');
  $coment = trim($_POST['comentario'] ?? '');

  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fini) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$ffin) || $ffin < $fini) {
    $msg = "Rango de fechas inválido.";
    $cls = "danger";
  } else {
    // Anti-spam: evita duplicar solicitudes PENDIENTES idénticas
    $stDup = $conn->prepare("
      SELECT id
      FROM vacaciones_solicitudes
      WHERE id_usuario=? AND fecha_inicio=? AND fecha_fin=? AND status='Pendiente'
      LIMIT 1
    ");
    $stDup->bind_param('iss',$idUsuario,$fini,$ffin);
    $stDup->execute();
    $dup = (bool)$stDup->get_result()->fetch_assoc();
    $stDup->close();

    if ($dup) {
      $msg = "Ya existe una solicitud pendiente con ese mismo rango.";
      $cls = "warning";
    } else {
      $st = $conn->prepare("
        INSERT INTO vacaciones_solicitudes
          (id_usuario,id_sucursal,fecha_inicio,fecha_fin,motivo,comentario,status,creado_por,creado_en)
        VALUES
          (?,?,?,?, 'Vacaciones', ?, 'Pendiente', ?, NOW())
      ");
      $sid = (int)$userRow['id_sucursal'];
      $creadoPor = $idUsuario;
      $st->bind_param('iisssi', $idUsuario, $sid, $fini, $ffin, $coment, $creadoPor);
      try {
        $st->execute();
        $msg = "✅ Solicitud enviada. Un Admin la revisará.";
        $cls = "success";
      } catch(Throwable $e) {
        $msg = "❌ Error al enviar: ".$e->getMessage();
        $cls = "danger";
      }
      $st->close();
    }
  }
}

// Historial del usuario
$hist = [];
if ($userRow && table_exists($conn,'vacaciones_solicitudes')) {
  $stH = $conn->prepare("
    SELECT id, fecha_inicio, fecha_fin, status, comentario, creado_en, resuelto_en, comentario_resolucion
    FROM vacaciones_solicitudes
    WHERE id_usuario=?
    ORDER BY id DESC
    LIMIT 30
  ");
  $stH->bind_param('i',$idUsuario);
  $stH->execute();
  $res = $stH->get_result();
  while($r=$res->fetch_assoc()) $hist[]=$r;
  $stH->close();
}

require_once __DIR__ . '/navbar.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Solicitar vacaciones</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body{ background:#f8fafc; }
    .card-elev{border:0;border-radius:1rem;box-shadow:0 10px 24px rgba(15,23,42,.06),0 2px 6px rgba(15,23,42,.05);}
  </style>
</head>
<body>
<div class="container my-4" style="max-width: 920px;">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0"><i class="bi bi-sunglasses me-2"></i>Solicitar vacaciones</h3>
    <?php if($userRow): ?>
      <span class="badge text-bg-secondary"><?= h($userRow['sucursal'].' · '.$userRow['nombre']) ?></span>
    <?php endif; ?>
  </div>

  <?php if($msg): ?>
    <div class="alert alert-<?= h($cls) ?>"><?= h($msg) ?></div>
  <?php endif; ?>

  <div class="card card-elev mb-4">
    <div class="card-body">
      <h5 class="fw-bold mb-3">Nueva solicitud</h5>
      <form method="post" class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Fecha inicio *</label>
          <input type="date" name="fecha_inicio" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Fecha fin *</label>
          <input type="date" name="fecha_fin" class="form-control" required>
        </div>
        <div class="col-12">
          <label class="form-label">Comentario (opcional)</label>
          <textarea name="comentario" rows="2" class="form-control" placeholder="Ej: Viaje familiar, etc."></textarea>
        </div>
        <div class="col-12 d-flex justify-content-end">
          <button class="btn btn-primary">
            <i class="bi bi-send me-1"></i>Enviar solicitud
          </button>
        </div>
        <div class="small text-muted">
          Esto queda en <strong>Pendiente</strong> hasta que un Admin lo apruebe o rechace.
        </div>
      </form>
    </div>
  </div>

  <div class="card card-elev">
    <div class="card-body">
      <h5 class="fw-bold mb-3">Mis solicitudes (últimas 30)</h5>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-dark">
            <tr>
              <th>#</th>
              <th>Rango</th>
              <th>Status</th>
              <th>Comentario</th>
              <th>Creada</th>
              <th>Resolución</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$hist): ?>
            <tr><td colspan="6" class="text-muted">Aún no tienes solicitudes.</td></tr>
          <?php else: foreach($hist as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= h($r['fecha_inicio'].' → '.$r['fecha_fin']) ?></td>
              <td>
                <?php
                  $st = $r['status'];
                  $badge = $st==='Aprobado'?'bg-success':($st==='Rechazado'?'bg-danger':($st==='Cancelado'?'bg-secondary':'bg-warning text-dark'));
                ?>
                <span class="badge <?= $badge ?>"><?= h($st) ?></span>
              </td>
              <td><?= h($r['comentario'] ?? '—') ?></td>
              <td><?= h($r['creado_en'] ?? '—') ?></td>
              <td>
                <?php if(!empty($r['resuelto_en'])): ?>
                  <div class="small"><?= h($r['resuelto_en']) ?></div>
                  <div class="small text-muted"><?= h($r['comentario_resolucion'] ?? '') ?></div>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>
