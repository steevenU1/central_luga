<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Gerente','Admin'])) {
    header("Location: 403.php"); exit();
}

include 'db.php';
include 'navbar.php';

$idUsuario  = (int)$_SESSION['id_usuario'];
$idSucursal = (int)$_SESSION['id_sucursal'];
$rolUsuario = $_SESSION['rol'];

$msg = '';
$MAX_BYTES = 10 * 1024 * 1024; // 10MB
$ALLOWED   = [
  'application/pdf' => 'pdf',
  'image/jpeg'      => 'jpg',
  'image/png'       => 'png',
];

/* ------- helper: guardar comprobante para un depósito ------- */
function guardar_comprobante(mysqli $conn, int $deposito_id, array $file, int $idUsuario, int $MAX_BYTES, array $ALLOWED, &$errMsg): bool {
  if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
    $errMsg = 'Debes adjuntar el comprobante.'; return false;
  }
  if ($file['error'] !== UPLOAD_ERR_OK) {
    $errMsg = 'Error al subir archivo (código '.$file['error'].').'; return false;
  }
  if ($file['size'] <= 0 || $file['size'] > $MAX_BYTES) {
    $errMsg = 'El archivo excede 10 MB o está vacío.'; return false;
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
  if (!isset($ALLOWED[$mime])) {
    $errMsg = 'Tipo de archivo no permitido. Solo PDF/JPG/PNG.'; return false;
  }
  $ext = $ALLOWED[$mime];

  // Carpeta destino
  $baseDir = __DIR__ . '/uploads/depositos/' . $deposito_id;
  if (!is_dir($baseDir)) {
    @mkdir($baseDir, 0775, true);
    if (!file_exists($baseDir.'/.htaccess')) {
      file_put_contents($baseDir.'/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phar|phtml|shtml|cgi|pl)$\">\nDeny from all\n</FilesMatch>\n");
    }
  }

  $storedName = 'comprobante.' . $ext;
  $fullPath   = $baseDir . '/' . $storedName;
  if (file_exists($fullPath)) @unlink($fullPath);

  if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
    $errMsg = 'No se pudo guardar el archivo en el servidor.'; return false;
  }

  $relPath = 'uploads/depositos/' . $deposito_id . '/' . $storedName;
  $orig    = substr(basename($file['name']), 0, 200);

  $stmt = $conn->prepare("
    UPDATE depositos_sucursal SET
      comprobante_archivo = ?, comprobante_nombre = ?, comprobante_mime = ?,
      comprobante_size = ?, comprobante_subido_en = NOW(), comprobante_subido_por = ?
    WHERE id = ?
  ");
  $size = (int)$file['size'];
  $stmt->bind_param('sssiii', $relPath, $orig, $mime, $size, $idUsuario, $deposito_id);
  $ok = $stmt->execute();
  $stmt->close();

  if (!$ok) {
    @unlink($fullPath);
    $errMsg = 'Error al actualizar el depósito con el comprobante.';
    return false;
  }
  return true;
}

/* ------- Registrar DEPÓSITO (AHORA con comprobante OBLIGATORIO) ------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion']==='registrar') {
  $id_corte        = (int)($_POST['id_corte'] ?? 0);
  $fecha_deposito  = $_POST['fecha_deposito'] ?? date('Y-m-d');
  $banco           = trim($_POST['banco'] ?? '');
  $monto           = (float)($_POST['monto_depositado'] ?? 0);
  $referencia      = trim($_POST['referencia'] ?? '');
  $motivo          = trim($_POST['motivo'] ?? '');

  // 1) Validar archivo obligatorio (antes de tocar BD)
  if (!isset($_FILES['comprobante']) || $_FILES['comprobante']['error'] === UPLOAD_ERR_NO_FILE) {
    $msg = "<div class='alert alert-warning shadow-sm'>⚠ Debes adjuntar el comprobante del depósito.</div>";
  } elseif ($_FILES['comprobante']['error'] !== UPLOAD_ERR_OK) {
    $msg = "<div class='alert alert-danger shadow-sm'>❌ Error al subir el archivo (código ".$_FILES['comprobante']['error'].").</div>";
  } elseif ($_FILES['comprobante']['size'] <= 0 || $_FILES['comprobante']['size'] > $MAX_BYTES) {
    $msg = "<div class='alert alert-warning shadow-sm'>⚠ El comprobante debe pesar hasta 10 MB.</div>";
  } else {
    // Validar MIME permitido
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['comprobante']['tmp_name']) ?: 'application/octet-stream';
    if (!isset($ALLOWED[$mime])) {
      $msg = "<div class='alert alert-warning shadow-sm'>⚠ Tipo de archivo no permitido. Solo PDF/JPG/PNG.</div>";
    } else {
      // 2) Validar datos y pendiente del corte
      if ($id_corte>0 && $monto>0 && $banco!=='') {
        $sqlCheck = "SELECT cc.total_efectivo, IFNULL(SUM(ds.monto_depositado),0) AS suma_actual
                     FROM cortes_caja cc
                     LEFT JOIN depositos_sucursal ds ON ds.id_corte = cc.id
                     WHERE cc.id = ? GROUP BY cc.id";
        $stmt = $conn->prepare($sqlCheck);
        $stmt->bind_param("i", $id_corte);
        $stmt->execute();
        $corte = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($corte) {
          $pendiente = (float)$corte['total_efectivo'] - (float)$corte['suma_actual'];
          if ($monto > $pendiente + 0.0001) {
            $msg = "<div class='alert alert-danger shadow-sm'>❌ El depósito excede el monto pendiente del corte. Solo queda $".number_format($pendiente,2)."</div>";
          } else {
            // 3) Insertar y adjuntar (si adjuntar falla, revertimos)
            $stmtIns = $conn->prepare("
              INSERT INTO depositos_sucursal
                (id_sucursal, id_corte, fecha_deposito, monto_depositado, banco, referencia, observaciones, estado, creado_en)
              VALUES (?, ?, ?, ?, ?, ?, ?, 'Pendiente', NOW())
            ");
            // (Conservar firma original)
            $stmtIns->bind_param("iisddss", $idSucursal, $id_corte, $fecha_deposito, $monto, $banco, $referencia, $motivo);
            if ($stmtIns->execute()) {
              $deposito_id = $stmtIns->insert_id;
              $stmtIns->close();

              $errUp = '';
              if (guardar_comprobante($conn, $deposito_id, $_FILES['comprobante'], $idUsuario, $MAX_BYTES, $ALLOWED, $errUp)) {
                $msg = "<div class='alert alert-success shadow-sm'>✅ Depósito registrado y comprobante adjuntado.</div>";
              } else {
                // revertir
                $del = $conn->prepare("DELETE FROM depositos_sucursal WHERE id=?");
                $del->bind_param('i', $deposito_id);
                $del->execute();
                $del->close();
                $msg = "<div class='alert alert-danger shadow-sm'>❌ No se guardó el depósito porque falló el comprobante: ".htmlspecialchars($errUp)."</div>";
              }
            } else {
              $msg = "<div class='alert alert-danger shadow-sm'>❌ Error al registrar depósito.</div>";
            }
          }
        } else {
          $msg = "<div class='alert alert-danger shadow-sm'>❌ Corte no encontrado.</div>";
        }
      } else {
        $msg = "<div class='alert alert-warning shadow-sm'>⚠ Debes llenar todos los campos obligatorios.</div>";
      }
    }
  }
}

/* ------- Subir/Reemplazar comprobante DESDE historial (seguimos permitiendo) ------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion']==='subir_comprobante') {
  $deposito_id = (int)($_POST['deposito_id'] ?? 0);

  $stmt = $conn->prepare("SELECT id_sucursal, estado FROM depositos_sucursal WHERE id=?");
  $stmt->bind_param('i', $deposito_id);
  $stmt->execute();
  $dep = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$dep) {
    $msg = "<div class='alert alert-danger shadow-sm'>❌ Depósito no encontrado.</div>";
  } elseif ($rolUsuario!=='Admin' && (int)$dep['id_sucursal'] !== $idSucursal) {
    $msg = "<div class='alert alert-danger shadow-sm'>❌ No tienes permiso para adjuntar a este depósito.</div>";
  } elseif (!in_array($dep['estado'], ['Pendiente','Parcial'], true)) {
    $msg = "<div class='alert alert-warning shadow-sm'>⚠ No se puede modificar un depósito ya validado.</div>";
  } else {
    $errUp = '';
    if (guardar_comprobante($conn, $deposito_id, $_FILES['comprobante'] ?? [], $idUsuario, $MAX_BYTES, $ALLOWED, $errUp)) {
      $msg = "<div class='alert alert-success shadow-sm'>✅ Comprobante adjuntado.</div>";
    } else {
      $msg = "<div class='alert alert-danger shadow-sm'>❌ ".$errUp."</div>";
    }
  }
}

/* ------- Consultas para render ------- */
// Cortes pendientes
$sqlPendientes = "
  SELECT cc.id, cc.fecha_corte, cc.total_efectivo,
         IFNULL(SUM(ds.monto_depositado),0) AS total_depositado
  FROM cortes_caja cc
  LEFT JOIN depositos_sucursal ds ON ds.id_corte = cc.id
  WHERE cc.id_sucursal = ? AND cc.estado='Pendiente'
  GROUP BY cc.id
  ORDER BY cc.fecha_corte ASC";
$stmtPend = $conn->prepare($sqlPendientes);
$stmtPend->bind_param("i", $idSucursal);
$stmtPend->execute();
$cortesPendientes = $stmtPend->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtPend->close();

// Historial
$sqlHistorial = "
  SELECT ds.*, cc.fecha_corte
  FROM depositos_sucursal ds
  INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
  WHERE ds.id_sucursal = ?
  ORDER BY ds.fecha_deposito DESC, ds.id DESC";
$stmtHist = $conn->prepare($sqlHistorial);
$stmtHist->bind_param("i", $idSucursal);
$stmtHist->execute();
$historial = $stmtHist->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtHist->close();

/* ---- Totales para tarjetas ---- */
$totalPendiente = 0.0;
foreach ($cortesPendientes as $c) {
  $totalPendiente += ((float)$c['total_efectivo'] - (float)$c['total_depositado']);
}
$numCortes = count($cortesPendientes);
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Depósitos Sucursal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{
      --surface: #ffffff;
      --muted: #6b7280;
    }
    body{ background: #f6f7fb; }
    .page-header{
      display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem;
    }
    .page-title{
      font-weight:700; letter-spacing:.2px; margin:0;
    }
    .card-surface{
      background: var(--surface);
      border: 1px solid rgba(0,0,0,.05);
      box-shadow: 0 6px 16px rgba(16,24,40,.06);
      border-radius: 18px;
    }
    .stat{
      display:flex; align-items:center; gap:.75rem;
    }
    .stat .icon{
      width:40px; height:40px; border-radius:12px; display:grid; place-items:center;
      background:#eef2ff;
    }
    .table thead th{
      position: sticky; top: 0; z-index: 1;
    }
    .chip{
      display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem; border-radius:999px; font-weight:600; font-size:.85rem;
    }
    .chip-success{ background:#e7f8ef; color:#0f7a3d; border:1px solid #b7f1cf; }
    .chip-warn{ background:#fff6e6; color:#9a6200; border:1px solid #ffe1a8; }
    .chip-pending{ background:#eef2ff; color:#3f51b5; border:1px solid #dfe3ff; }
    .small-muted{ color: var(--muted); font-size:.9rem; }
    .tbl-wrap{ overflow:auto; border-radius:14px; }
    .input-help{ font-size:.8rem; color:#6b7280; }
    .btn-soft{
      border:1px solid rgba(0,0,0,.08);
      background:#ffffff;
    }
    .btn-soft:hover{ background:#f9fafb; }
    .form-mini .form-control,
    .form-mini .form-select{
      height: 40px;
    }
    .form-mini .form-control[type="file"]{
      height:auto;
    }
  </style>
</head>
<body>
<div class="container py-3">

  <div class="page-header">
    <div>
      <h1 class="page-title">🏦 Depósitos de Sucursal</h1>
      <div class="small-muted">Usuario: <strong><?= htmlspecialchars($_SESSION['nombre']) ?></strong> · Rol: <strong><?= htmlspecialchars($rolUsuario) ?></strong></div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="chip chip-pending"><i class="bi bi-cash-coin"></i> Pendiente: $<?= number_format($totalPendiente,2) ?></span>
      <span class="chip chip-warn"><i class="bi bi-clipboard-check"></i> Cortes: <?= (int)$numCortes ?></span>
    </div>
  </div>

  <?= $msg ?>

  <!-- Tarjetas resumen -->
  <div class="row g-3 my-2">
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card card-surface p-3">
        <div class="stat">
          <div class="icon"><i class="bi bi-wallet2"></i></div>
          <div>
            <div class="small-muted">Pendiente por depositar</div>
            <div class="h4 m-0">$<?= number_format($totalPendiente,2) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card card-surface p-3">
        <div class="stat">
          <div class="icon"><i class="bi bi-journal-text"></i></div>
          <div>
            <div class="small-muted">Cortes pendientes</div>
            <div class="h4 m-0"><?= (int)$numCortes ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Cortes pendientes -->
  <div class="card card-surface p-3 mt-3">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h4 class="m-0"><i class="bi bi-list-check me-2"></i>Cortes pendientes de depósito</h4>
      <span class="small-muted">Adjunta comprobante (PDF/JPG/PNG, máx 10MB)</span>
    </div>

    <?php if (count($cortesPendientes) == 0): ?>
      <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i>No hay cortes pendientes de depósito.</div>
    <?php else: ?>
      <div class="tbl-wrap">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="min-width: 120px;">ID Corte</th>
              <th>Fecha Corte</th>
              <th>Efectivo a Depositar</th>
              <th>Total Depositado</th>
              <th>Pendiente</th>
              <th style="min-width: 560px;">Registrar Depósito</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cortesPendientes as $c):
              $pendiente = (float)$c['total_efectivo'] - (float)$c['total_depositado']; ?>
              <tr>
                <td><span class="badge text-bg-secondary">#<?= (int)$c['id'] ?></span></td>
                <td><?= htmlspecialchars($c['fecha_corte']) ?></td>
                <td>$<?= number_format($c['total_efectivo'],2) ?></td>
                <td>$<?= number_format($c['total_depositado'],2) ?></td>
                <td class="fw-bold text-danger">$<?= number_format($pendiente,2) ?></td>
                <td>
                  <form method="POST" class="row g-2 form-mini deposito-form" enctype="multipart/form-data"
                        novalidate
                        data-pendiente="<?= htmlspecialchars($pendiente) ?>"
                        data-idcorte="<?= (int)$c['id'] ?>"
                        data-fechacorte="<?= htmlspecialchars($c['fecha_corte']) ?>">
                    <input type="hidden" name="accion" value="registrar">
                    <input type="hidden" name="id_corte" value="<?= (int)$c['id'] ?>">

                    <div class="col-6 col-md-3">
                      <label class="form-label mb-1 small">Fecha depósito</label>
                      <input type="date" name="fecha_deposito" class="form-control" required>
                      <div class="invalid-feedback">Requerido.</div>
                    </div>

                    <div class="col-6 col-md-2">
                      <label class="form-label mb-1 small">Monto</label>
                      <input type="number" step="0.01" name="monto_depositado" class="form-control" placeholder="0.00" required>
                      <div class="invalid-feedback">Ingresa un monto válido.</div>
                    </div>

                    <div class="col-6 col-md-2">
                      <label class="form-label mb-1 small">Banco</label>
                      <input type="text" name="banco" class="form-control" placeholder="Banco" required>
                      <div class="invalid-feedback">Requerido.</div>
                    </div>

                    <div class="col-6 col-md-2">
                      <label class="form-label mb-1 small">Referencia</label>
                      <input type="text" name="referencia" class="form-control" placeholder="Referencia">
                    </div>

                    <div class="col-12 col-md-3">
                      <label class="form-label mb-1 small">Motivo (opcional)</label>
                      <input type="text" name="motivo" class="form-control" placeholder="Motivo">
                    </div>

                    <div class="col-12">
                      <label class="form-label mb-1 small">Comprobante</label>
                      <input type="file" name="comprobante" class="form-control form-control-sm"
                             accept=".pdf,.jpg,.jpeg,.png" required>
                      <div class="input-help">Solo PDF / JPG / PNG. Máx 10 MB.</div>
                      <div class="invalid-feedback">Adjunta el comprobante.</div>
                    </div>

                    <div class="col-12">
                      <button type="button" class="btn btn-success btn-sm w-100 btn-confirmar-deposito">
                        <i class="bi bi-shield-check me-1"></i> Validar y registrar
                      </button>
                    </div>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Historial -->
  <div class="card card-surface p-3 mt-4 mb-5">
    <h4 class="mb-3"><i class="bi bi-clock-history me-2"></i>Historial de Depósitos</h4>
    <div class="tbl-wrap">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>ID Depósito</th>
            <th>ID Corte</th>
            <th>Fecha Corte</th>
            <th>Fecha Depósito</th>
            <th>Monto</th>
            <th>Banco</th>
            <th>Referencia</th>
            <th>Comprobante</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historial as $h): ?>
            <tr>
              <td><span class="badge text-bg-secondary">#<?= (int)$h['id'] ?></span></td>
              <td><?= (int)$h['id_corte'] ?></td>
              <td><?= htmlspecialchars($h['fecha_corte']) ?></td>
              <td><?= htmlspecialchars($h['fecha_deposito']) ?></td>
              <td>$<?= number_format($h['monto_depositado'],2) ?></td>
              <td><?= htmlspecialchars($h['banco']) ?></td>
              <td><?= htmlspecialchars($h['referencia']) ?></td>
              <td>
                <?php if (!empty($h['comprobante_archivo'])): ?>
                  <a class="btn btn-soft btn-sm" target="_blank" href="deposito_comprobante.php?id=<?= (int)$h['id'] ?>">
                    <i class="bi bi-file-earmark-arrow-down"></i> Ver
                  </a>
                  <?php if (in_array($h['estado'], ['Pendiente','Parcial'], true)): ?>
                    <small class="text-muted d-block mt-1">Puedes reemplazarlo abajo.</small>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="small-muted">Sin archivo</span>
                <?php endif; ?>

                <?php if (in_array($h['estado'], ['Pendiente','Parcial'], true)): ?>
                  <form class="mt-2" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="accion" value="subir_comprobante">
                    <input type="hidden" name="deposito_id" value="<?= (int)$h['id'] ?>">
                    <div class="input-group input-group-sm">
                      <input type="file" name="comprobante" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                      <button class="btn btn-outline-<?= empty($h['comprobante_archivo']) ? 'success' : 'warning' ?>">
                        <i class="bi <?= empty($h['comprobante_archivo']) ? 'bi-cloud-upload' : 'bi-arrow-repeat' ?>"></i>
                        <?= empty($h['comprobante_archivo']) ? 'Subir' : 'Reemplazar' ?>
                      </button>
                    </div>
                  </form>
                <?php endif; ?>
              </td>
              <td>
                <?php
                  $estado = htmlspecialchars($h['estado']);
                  if ($estado === 'Validado') {
                    echo '<span class="chip chip-success"><i class="bi bi-check2-circle"></i> Validado</span>';
                  } elseif ($estado === 'Parcial') {
                    echo '<span class="chip chip-warn"><i class="bi bi-hourglass-split"></i> Parcial</span>';
                  } else {
                    echo '<span class="chip chip-pending"><i class="bi bi-hourglass"></i> Pendiente</span>';
                  }
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- MODAL CONFIRMACIÓN -->
<div class="modal fade" id="modalConfirmarDeposito" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-shield-lock me-2"></i>Confirmar registro de depósito</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <div class="card card-surface p-3">
              <div class="small-muted">ID Corte</div>
              <div id="confCorteId" class="h5 m-0">—</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="card card-surface p-3">
              <div class="small-muted">Fecha Corte</div>
              <div id="confFechaCorte" class="h5 m-0">—</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="card card-surface p-3">
              <div class="small-muted">Fecha Depósito</div>
              <div id="confFechaDeposito" class="h5 m-0">—</div>
            </div>
          </div>

          <div class="col-12 col-md-4">
            <div class="card card-surface p-3">
              <div class="small-muted">Monto</div>
              <div id="confMonto" class="h5 m-0">—</div>
              <div id="confPendienteHelp" class="small text-danger mt-1 d-none"><i class="bi bi-exclamation-triangle me-1"></i>El monto supera el pendiente.</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="card card-surface p-3">
              <div class="small-muted">Banco</div>
              <div id="confBanco" class="h5 m-0">—</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="card card-surface p-3">
              <div class="small-muted">Referencia</div>
              <div id="confReferencia" class="h5 m-0">—</div>
            </div>
          </div>

          <div class="col-12">
            <div class="card card-surface p-3">
              <div class="small-muted">Motivo (opcional)</div>
              <div id="confMotivo" class="m-0">—</div>
            </div>
          </div>

          <div class="col-12">
            <div class="card card-surface p-3">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="small-muted">Comprobante</div>
                  <div id="confArchivo" class="m-0">—</div>
                </div>
                <div id="confPreview" class="ms-3"></div>
              </div>
              <div class="small text-muted mt-2">Se validará tamaño (≤10MB) y tipo (PDF/JPG/PNG).</div>
            </div>
          </div>

          <div id="confErrors" class="col-12 d-none">
            <div class="alert alert-danger mb-0"><i class="bi bi-x-octagon me-1"></i><span class="conf-errors-text">Hay errores en los datos. Corrige antes de continuar.</span></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button id="btnModalCancelar" type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Volver y corregir</button>
        <button id="btnModalConfirmar" type="button" class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Confirmar y registrar</button>
      </div>
    </div>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
(() => {
  const modalEl   = document.getElementById('modalConfirmarDeposito');
  const modal     = new bootstrap.Modal(modalEl);
  let formToSubmit = null;

  const formatMXN = (n) => new Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN' }).format(n);

  // Validación de archivo (tipo y tamaño)
  function validateFile(file){
    if(!file) return {ok:false, msg:'Adjunta el comprobante.'};
    const allowed = ['application/pdf','image/jpeg','image/png'];
    if (!allowed.includes(file.type)) {
      // fallback: validar por extensión si el navegador no establece type
      const name = (file.name||'').toLowerCase();
      const extOk = name.endsWith('.pdf') || name.endsWith('.jpg') || name.endsWith('.jpeg') || name.endsWith('.png');
      if(!extOk) return {ok:false, msg:'Tipo de archivo no permitido.'};
    }
    if (file.size <= 0 || file.size > (10 * 1024 * 1024)) return {ok:false, msg:'El archivo excede 10 MB o está vacío.'};
    return {ok:true};
  }

  // Hook a cada formulario de depósito
  document.querySelectorAll('.deposito-form').forEach(form => {
    form.querySelector('.btn-confirmar-deposito').addEventListener('click', () => {
      // Bootstrap validation básica
      form.classList.add('was-validated');
      if (!form.checkValidity()) {
        // Si falla algún required del navegador, no seguimos
        return;
      }
      const pendiente  = parseFloat(form.dataset.pendiente || '0');
      const idCorte    = form.dataset.idcorte || '';
      const fechaCorte = form.dataset.fechacorte || '';

      const fechaDep   = form.querySelector('input[name="fecha_deposito"]').value;
      const monto      = parseFloat(form.querySelector('input[name="monto_depositado"]').value || '0');
      const banco      = form.querySelector('input[name="banco"]').value.trim();
      const referencia = form.querySelector('input[name="referencia"]').value.trim();
      const motivo     = form.querySelector('input[name="motivo"]').value.trim();
      const fileInput  = form.querySelector('input[name="comprobante"]');
      const file       = fileInput?.files?.[0];

      // Validaciones custom
      let errors = [];
      if(!(monto > 0)) errors.push('Ingresa un monto mayor a 0.');
      if(monto > (pendiente + 0.0001)) errors.push('El monto supera el pendiente del corte.');
      if(!banco) errors.push('Banco es requerido.');
      const fileRes = validateFile(file);
      if(!fileRes.ok) errors.push(fileRes.msg);

      // Llenar modal
      document.getElementById('confCorteId').textContent = '#' + idCorte;
      document.getElementById('confFechaCorte').textContent = fechaCorte || '—';
      document.getElementById('confFechaDeposito').textContent = fechaDep || '—';
      document.getElementById('confMonto').textContent = formatMXN(isFinite(monto) ? monto : 0);
      document.getElementById('confPendienteHelp').classList.toggle('d-none', !(monto > (pendiente + 0.0001)));
      document.getElementById('confBanco').textContent = banco || '—';
      document.getElementById('confReferencia').textContent = referencia || '—';
      document.getElementById('confMotivo').textContent = motivo || '—';

      const archivoTxt = file ? `${file.name} · ${(file.size/1024/1024).toFixed(2)} MB` : '—';
      document.getElementById('confArchivo').textContent = archivoTxt;

      // Preview (si es imagen)
      const prev = document.getElementById('confPreview');
      prev.innerHTML = '';
      if (file && file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.style.maxHeight = '80px';
        img.style.borderRadius = '8px';
        img.style.border = '1px solid rgba(0,0,0,.1)';
        prev.appendChild(img);
      }

      const errorsBox = document.getElementById('confErrors');
      const errorsText = errorsBox.querySelector('.conf-errors-text');
      if (errors.length) {
        errorsText.textContent = 'Hay errores: ' + errors.join(' ');
        errorsBox.classList.remove('d-none');
      } else {
        errorsBox.classList.add('d-none');
      }

      // Guardar form y abrir modal
      formToSubmit = errors.length ? null : form;
      document.getElementById('btnModalConfirmar').disabled = !!errors.length;
      modal.show();
    });
  });

  // Confirmar en modal
  document.getElementById('btnModalConfirmar').addEventListener('click', () => {
    if (formToSubmit) {
      // Enviar
      formToSubmit.submit();
      formToSubmit = null;
      modal.hide();
    }
  });
})();
</script>
</body>
</html>
