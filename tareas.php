<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

/* ========= Permisos ========= */
$stmtU = $conn->prepare("SELECT usuario, rol, nombre FROM usuarios WHERE id=? LIMIT 1");
$stmtU->bind_param("i", $_SESSION['id_usuario']);
$stmtU->execute();
$me = $stmtU->get_result()->fetch_assoc();
$stmtU->close();

$miUsuario = strtolower($me['usuario'] ?? '');
$miRol     = $me['rol'] ?? '';
$canEdit   = ($miUsuario === 'efernandez');
$canView   = ($canEdit || $miRol === 'Admin');
if (!$canView) { header("Location: index.php"); exit(); }

/* ========= Helpers ========= */
function dtlocal_to_mysql(?string $s): ?string {
  if (!$s) return null;
  $s = str_replace('T', ' ', $s);
  return $s . (strlen($s) === 16 ? ':00' : '');
}
function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Crear tarea
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($canEdit) && ($_POST['action'] ?? '') === 'create') {
  $titulo         = trim($_POST['titulo'] ?? '');
  $prioridad      = $_POST['prioridad'] ?? 'Media';
  $fecha_trabajo  = dtlocal_to_mysql($_POST['fecha_trabajo'] ?? null);
  $descripcion    = trim($_POST['descripcion'] ?? '');
  $estatus        = $_POST['estatus'] ?? 'Pendiente';
  $fecha_estimada = dtlocal_to_mysql($_POST['fecha_estimada'] ?? null);
  $creado_por     = (int)$_SESSION['id_usuario'];

  if ($titulo !== '') {
    $stmt = $conn->prepare("
      INSERT INTO tarea_log (titulo, prioridad, fecha_trabajo, descripcion, estatus, fecha_estimada, creado_por)
      VALUES (?,?,?,?,?,?,?)
    ");
    $stmt->bind_param("ssssssi", $titulo, $prioridad, $fecha_trabajo, $descripcion, $estatus, $fecha_estimada, $creado_por);
    $stmt->execute();
    $stmt->close();
  }
  header("Location: tareas.php"); exit();
}

// Actualizar (estatus / estimada)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($canEdit) && ($_POST['action'] ?? '') === 'update') {
  $id             = (int)($_POST['id'] ?? 0);
  $estatus        = $_POST['estatus'] ?? 'Pendiente';
  $fecha_estimada = dtlocal_to_mysql($_POST['fecha_estimada'] ?? null);
  if ($id > 0) {
    $stmt = $conn->prepare("UPDATE tarea_log SET estatus=?, fecha_estimada=? WHERE id=?");
    $stmt->bind_param("ssi", $estatus, $fecha_estimada, $id);
    $stmt->execute();
    $stmt->close();
  }
  header("Location: tareas.php"); exit();
}

/* ========= Datos para UI ========= */
$totales = [];
$res = $conn->query("SELECT estatus, COUNT(*) c FROM tarea_log GROUP BY estatus");
while ($r = $res->fetch_assoc()) { $totales[$r['estatus']] = (int)$r['c']; }

$hoy = date('Y-m-d');
$stmtHoy = $conn->prepare("
  SELECT * FROM tarea_log
  WHERE DATE(COALESCE(fecha_trabajo, actualizado_en)) = ?
     OR (estatus='En curso' AND DATE(actualizado_en)=?)
  ORDER BY actualizado_en DESC, fecha_trabajo DESC
  LIMIT 10
");
$stmtHoy->bind_param("ss", $hoy, $hoy);
$stmtHoy->execute();
$hoyRows = $stmtHoy->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtHoy->close();

$resNext = $conn->query("
  SELECT * FROM tarea_log
  WHERE fecha_estimada IS NOT NULL
  ORDER BY fecha_estimada ASC
  LIMIT 6
");
$nextRows = $resNext->fetch_all(MYSQLI_ASSOC);

$resList = $conn->query("SELECT * FROM tarea_log ORDER BY id DESC LIMIT 300");

$badgeEstatus = [
  'Pendiente'   => 'secondary',
  'En curso'    => 'info',
  'En QA'       => 'warning',
  'Producción'  => 'primary',
  'Completado'  => 'success',
  'Cancelado'   => 'dark'
];
$badgePrio = ['Baja'=>'secondary','Media'=>'primary','Alta'=>'danger','Urgente'=>'dark'];

$active = 'tareas';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Bitácora / Tareas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- (Opcional) CSS adicional de Bootstrap; navbar.php ya incluye Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{ --nav-offset-sm:64px; --nav-offset-lg:72px; }
    body{ margin:0; } /* elimina margen por defecto del navegador */

    .bg-hero{
      background: linear-gradient(135deg,#111827 0%,#1f2937 55%,#0ea5e9 110%);
      color:#fff;
    }
    .truncate-2{
      display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
    }
    .card-kpi{ border:none; box-shadow:0 2px 10px rgba(0,0,0,.06); }
    .sticky-actions{ position: sticky; right: 0; background: var(--bs-body-bg); z-index: 1; }
    @media (max-width: 576px){
      .table thead th { font-size: .8rem; }
      .table td { font-size: .875rem; }
      .btn-icon-only{ padding: .25rem .5rem; }
    }
  </style>
</head>
<body class="bg-light">
  <?php require_once __DIR__ . '/navbar.php'; ?>

  <header class="bg-hero py-4 mb-3">
    <div class="container">
      <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
        <div>
          <h3 class="mb-1">📝 Bitácora de tareas</h3>
          <div class="opacity-75">Visión general de cambios, pendientes y avances</div>
        </div>
        <?php if ($canEdit): ?>
          <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalNueva">
            <i class="bi bi-plus-lg me-1"></i> Nueva tarea
          </button>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main class="container pb-5">
    <!-- KPIs -->
    <div class="row g-3 mb-4">
      <?php
        $keys = ['Pendiente','En curso','En QA','Producción','Completado'];
        $icons= ['Pendiente'=>'hourglass-split','En curso'=>'rocket','En QA'=>'bug','Producción'=>'box-seam','Completado'=>'check2-circle'];
        foreach ($keys as $k):
          $c = $totales[$k] ?? 0; $ico = $icons[$k];
      ?>
      <div class="col-6 col-md">
        <div class="card card-kpi h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div class="text-muted small"><?php echo esc($k); ?></div>
              <i class="bi bi-<?php echo $ico; ?>"></i>
            </div>
            <div class="h3 mb-0"><?php echo $c; ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="col-12 col-md">
        <div class="card card-kpi h-100">
          <div class="card-body">
            <div class="text-muted small">Próximas 48h</div>
            <div class="h3 mb-0">
              <?php
                $res48 = $conn->query("SELECT COUNT(*) c FROM tarea_log WHERE fecha_estimada BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 2 DAY)");
                echo (int)($res48->fetch_assoc()['c'] ?? 0);
              ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Hoy / Próximos -->
    <div class="row g-3 mb-4">
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header d-flex align-items-center justify-content-between">
            <span>🟦 ¿En qué estoy hoy?</span>
            <span class="text-muted small">hoy: <?php echo date('d/m/Y'); ?></span>
          </div>
          <div class="list-group list-group-flush">
            <?php if (!$hoyRows): ?>
              <div class="list-group-item text-muted">Sin movimientos hoy.</div>
            <?php else: foreach ($hoyRows as $t): ?>
              <div class="list-group-item">
                <div class="d-flex justify-content-between flex-wrap gap-2">
                  <div class="fw-semibold"><?php echo esc($t['titulo']); ?></div>
                  <span class="badge text-bg-<?php echo $badgeEstatus[$t['estatus']] ?? 'secondary'; ?>">
                    <?php echo esc($t['estatus']); ?>
                  </span>
                </div>
                <div class="small text-muted truncate-2 mt-1"><?php echo nl2br(esc($t['descripcion'])); ?></div>
                <div class="small mt-1 d-flex flex-wrap gap-2 align-items-center">
                  <span class="badge rounded-pill text-bg-<?php echo $badgePrio[$t['prioridad']] ?? 'secondary'; ?>">
                    <?php echo esc($t['prioridad']); ?>
                  </span>
                  <?php if ($t['fecha_trabajo']): ?>
                    <span class="text-muted"><i class="bi bi-calendar-check"></i> <?php echo esc($t['fecha_trabajo']); ?></span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-header">🟨 Próximos vencimientos</div>
          <div class="list-group list-group-flush">
            <?php if (!$nextRows): ?>
              <div class="list-group-item text-muted">Sin fechas estimadas.</div>
            <?php else: foreach ($nextRows as $t): ?>
              <div class="list-group-item d-flex justify-content-between align-items-start">
                <div class="pe-3">
                  <div class="fw-semibold"><?php echo esc($t['titulo']); ?></div>
                  <div class="small text-muted truncate-2"><?php echo nl2br(esc($t['descripcion'])); ?></div>
                </div>
                <div class="text-end">
                  <div class="badge text-bg-<?php echo $badgePrio[$t['prioridad']] ?? 'secondary'; ?>"><?php echo esc($t['prioridad']); ?></div>
                  <div class="small mt-1"><i class="bi bi-clock"></i> <?php echo esc($t['fecha_estimada']); ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabla principal -->
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span>📋 Listado de tareas</span>
        <?php if ($canEdit): ?>
          <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalNueva">
            <i class="bi bi-plus-lg me-1"></i> Agregar
          </button>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="text-nowrap">#</th>
              <th style="min-width:320px">Título / Descripción</th>
              <th class="text-nowrap">Prioridad</th>
              <th class="d-none d-lg-table-cell text-nowrap">Solicitud</th>
              <th class="d-none d-md-table-cell text-nowrap">Trabajo</th>
              <th class="text-nowrap">Estatus</th>
              <th class="d-none d-sm-table-cell text-nowrap">Estimada</th>
              <th class="sticky-actions text-nowrap">Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $resList->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int)$row['id']; ?></td>
                <td style="max-width:420px">
                  <div class="fw-semibold"><?php echo esc($row['titulo']); ?></div>
                  <div class="small text-muted truncate-2"><?php echo nl2br(esc($row['descripcion'] ?? '')); ?></div>
                </td>
                <td>
                  <span class="badge text-bg-<?php echo $badgePrio[$row['prioridad']] ?? 'secondary'; ?>">
                    <?php echo esc($row['prioridad']); ?>
                  </span>
                </td>
                <td class="small text-muted d-none d-lg-table-cell"><?php echo esc($row['fecha_solicitud']); ?></td>
                <td class="small text-muted d-none d-md-table-cell"><?php echo esc($row['fecha_trabajo'] ?: '-'); ?></td>
                <td>
                  <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                    <select name="estatus" class="form-select form-select-sm w-auto" <?php echo $canEdit?'':'disabled'; ?>>
                      <?php
                        $opts = ['Pendiente','En curso','En QA','Producción','Completado','Cancelado'];
                        foreach ($opts as $o) {
                          $sel = ($o === $row['estatus']) ? 'selected' : '';
                          echo "<option $sel>".esc($o)."</option>";
                        }
                      ?>
                    </select>
                </td>
                <td class="d-none d-sm-table-cell" style="min-width:180px">
                  <?php $val = $row['fecha_estimada'] ? date('Y-m-d\TH:i', strtotime($row['fecha_estimada'])) : ''; ?>
                  <input type="datetime-local" name="fecha_estimada" class="form-control form-control-sm"
                         value="<?php echo esc($val); ?>" <?php echo $canEdit?'':'disabled'; ?>>
                </td>
                <td class="sticky-actions">
                  <?php if ($canEdit): ?>
                    <button class="btn btn-sm btn-outline-primary">
                      <i class="bi bi-save me-1 d-none d-sm-inline"></i>
                      <span class="d-sm-inline d-none">Actualizar</span>
                      <i class="bi bi-save d-sm-none btn-icon-only"></i>
                    </button>
                  <?php else: ?>
                    <span class="text-muted small">Solo lectura</span>
                  <?php endif; ?>
                  </form>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>

  <!-- Modal Nueva Tarea -->
  <div class="modal fade" id="modalNueva" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="action" value="create">
          <div class="modal-header">
            <h5 class="modal-title">Nueva tarea</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <?php if (!$canEdit): ?>
              <div class="alert alert-warning mb-3">
                Solo <strong>efernandez</strong> puede crear/editar. Estás en modo lectura.
              </div>
            <?php endif; ?>
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label">Título</label>
                <input name="titulo" class="form-control" placeholder="Ajuste en recálculo de comisiones..." <?php echo $canEdit?'':'disabled'; ?>>
              </div>
              <div class="col-md-4">
                <label class="form-label">Prioridad</label>
                <select name="prioridad" class="form-select" <?php echo $canEdit?'':'disabled'; ?>>
                  <option>Baja</option><option>Media</option><option selected>Alta</option><option>Urgente</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Fecha de trabajo</label>
                <input type="datetime-local" name="fecha_trabajo" class="form-control"
                       value="<?php echo date('Y-m-d\TH:i'); ?>" <?php echo $canEdit?'':'disabled'; ?>>
              </div>
              <div class="col-md-4">
                <label class="form-label">Estatus</label>
                <select name="estatus" class="form-select" <?php echo $canEdit?'':'disabled'; ?>>
                  <option selected>Pendiente</option>
                  <option>En curso</option>
                  <option>En QA</option>
                  <option>Producción</option>
                  <option>Completado</option>
                  <option>Cancelado</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Fecha estimada</label>
                <input type="datetime-local" name="fecha_estimada" class="form-control" <?php echo $canEdit?'':'disabled'; ?>>
              </div>
              <div class="col-12">
                <label class="form-label">Descripción</label>
                <textarea name="descripcion" rows="4" class="form-control"
                          placeholder="Qué se pidió / Qué se cambió / Cómo se validó..." <?php echo $canEdit?'':'disabled'; ?>></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <?php if ($canEdit): ?>
              <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Guardar</button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Ajuste de offset solo si el navbar fuera fixed-top -->
  <script>
    (function () {
      const nb = document.querySelector('.navbar');
      if (!nb) return;
      if (nb.classList.contains('fixed-top')) {
        document.body.style.paddingTop = nb.getBoundingClientRect().height + 'px';
      } else {
        document.body.style.paddingTop = '0px';
      }
    })();
  </script>
</body>
</html>

