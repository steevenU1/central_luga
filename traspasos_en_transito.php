<?php
// traspasos_en_transito.php — Resumen global de traspasos en tránsito (Pendiente / Parcial)
// Visible para Admin y Logística

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

$rol = $_SESSION['rol'] ?? '';
$rolesPermitidos = ['Admin', 'Logistica', 'Logística'];
if (!in_array($rol, $rolesPermitidos, true)) {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ========================
//   Filtro opcional de estatus
// ========================
$estatus = $_GET['estatus'] ?? 'PendienteParcial'; // PendienteParcial | Pendiente | Parcial | Todos
$whereEstatus = "t.estatus IN ('Pendiente','Parcial')";

if ($estatus === 'Pendiente') {
    $whereEstatus = "t.estatus = 'Pendiente'";
} elseif ($estatus === 'Parcial') {
    $whereEstatus = "t.estatus = 'Parcial'";
} elseif ($estatus === 'Todos') {
    $whereEstatus = "t.estatus IN ('Pendiente','Parcial','Completado','Rechazado')";
}

// ========================
//   Consulta principal
// ========================
// Agrupamos por traspaso para saber cuántas piezas totales / pendientes / recibidas / rechazadas
$sql = "
    SELECT
        t.id,
        t.id_sucursal_origen,
        t.id_sucursal_destino,
        t.fecha_traspaso,
        t.fecha_recepcion,
        t.estatus,
        t.usuario_creo,
        t.usuario_recibio,

        so.nombre AS sucursal_origen,
        sd.nombre AS sucursal_destino,
        uc.nombre AS usuario_creo_nombre,
        ur.nombre AS usuario_recibio_nombre,

        COUNT(d.id) AS piezas_totales,
        SUM(CASE WHEN d.resultado = 'Pendiente' THEN 1 ELSE 0 END) AS piezas_pendientes,
        SUM(CASE WHEN d.resultado = 'Recibido'  THEN 1 ELSE 0 END) AS piezas_recibidas,
        SUM(CASE WHEN d.resultado = 'Rechazado' THEN 1 ELSE 0 END) AS piezas_rechazadas
    FROM traspasos t
    LEFT JOIN detalle_traspaso d ON d.id_traspaso = t.id
    LEFT JOIN sucursales so ON so.id = t.id_sucursal_origen
    LEFT JOIN sucursales sd ON sd.id = t.id_sucursal_destino
    LEFT JOIN usuarios   uc ON uc.id = t.usuario_creo
    LEFT JOIN usuarios   ur ON ur.id = t.usuario_recibio
    WHERE $whereEstatus
    GROUP BY t.id
    ORDER BY t.fecha_traspaso DESC, t.id DESC
";

$res = $conn->query($sql);
$traspasos = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Traspasos en tránsito</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Auto refresh cada 60s para que sea "en vivo" -->
  <meta http-equiv="refresh" content="60">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f5f5f5; }
    .page-title {
        font-size: 1.4rem;
        font-weight: 600;
    }
    .badge-status {
        font-size: .75rem;
    }
    .table-smaller td, .table-smaller th {
        padding: .35rem .4rem;
        font-size: .85rem;
        vertical-align: middle;
    }
  </style>
</head>
<body>

<div class="container-fluid mt-3 mb-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <div class="page-title">Traspasos en tránsito</div>
      <small class="text-muted">
        Vista global para logística / admin · Se actualiza cada 60 segundos
      </small>
    </div>
    <form class="d-flex align-items-center" method="get">
      <label class="me-2 small text-muted">Estatus:</label>
      <select name="estatus" class="form-select form-select-sm me-2" onchange="this.form.submit()">
        <option value="PendienteParcial" <?php if($estatus==='PendienteParcial') echo 'selected'; ?>>
          Solo en tránsito (Pendiente + Parcial)
        </option>
        <option value="Pendiente" <?php if($estatus==='Pendiente') echo 'selected'; ?>>
          Solo Pendiente
        </option>
        <option value="Parcial" <?php if($estatus==='Parcial') echo 'selected'; ?>>
          Solo Parcial
        </option>
        <option value="Todos" <?php if($estatus==='Todos') echo 'selected'; ?>>
          Todos (incluye Completado / Rechazado)
        </option>
      </select>
    </form>
  </div>

  <?php if (empty($traspasos)): ?>
    <div class="alert alert-success">
      No hay traspasos con el estatus seleccionado. 🌈
    </div>
  <?php else: ?>

    <div class="card shadow-sm">
      <div class="card-body p-2 p-sm-3">
        <div class="table-responsive">
          <table class="table table-striped table-hover table-smaller align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Origen</th>
                <th>Destino</th>
                <th>Fecha traspaso</th>
                <th>Edad</th>
                <th>Pzas</th>
                <th>Pend.</th>
                <th>Recib.</th>
                <th>Rech.</th>
                <th>Estatus</th>
                <th>Creó</th>
                <th>Recibió</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($traspasos as $t): ?>
              <?php
                $fechaTraspaso = $t['fecha_traspaso'];
                $tsTraspaso    = strtotime($fechaTraspaso);
                $ahora         = time();
                $diffSeg       = max(0, $ahora - $tsTraspaso);
                $dias          = floor($diffSeg / 86400);
                $horas         = floor(($diffSeg % 86400) / 3600);

                $edadTexto = $dias > 0
                    ? "{$dias} día(s) {$horas} h"
                    : "{$horas} h";

                // Semáforo simple según días en tránsito
                $rowClass = '';
                if ($dias >= 3) {
                    $rowClass = 'table-danger';
                } elseif ($dias >= 1) {
                    $rowClass = 'table-warning';
                }

                $badgeClass = 'bg-secondary';
                if ($t['estatus'] === 'Pendiente') $badgeClass = 'bg-warning text-dark';
                elseif ($t['estatus'] === 'Parcial') $badgeClass = 'bg-info text-dark';
                elseif ($t['estatus'] === 'Completado') $badgeClass = 'bg-success';
                elseif ($t['estatus'] === 'Rechazado') $badgeClass = 'bg-danger';
              ?>
              <tr class="<?php echo $rowClass; ?>">
                <td><?php echo (int)$t['id']; ?></td>
                <td><?php echo h($t['sucursal_origen'] ?: 'N/D'); ?></td>
                <td><?php echo h($t['sucursal_destino'] ?: 'N/D'); ?></td>
                <td>
                  <?php echo h(date('Y-m-d H:i', strtotime($fechaTraspaso))); ?>
                </td>
                <td><?php echo h($edadTexto); ?></td>
                <td><?php echo (int)$t['piezas_totales']; ?></td>
                <td><?php echo (int)$t['piezas_pendientes']; ?></td>
                <td><?php echo (int)$t['piezas_recibidas']; ?></td>
                <td><?php echo (int)$t['piezas_rechazadas']; ?></td>
                <td>
                  <span class="badge badge-status <?php echo $badgeClass; ?>">
                    <?php echo h($t['estatus']); ?>
                  </span>
                </td>
                <td><?php echo h($t['usuario_creo_nombre'] ?: 'N/D'); ?></td>
                <td><?php echo h($t['usuario_recibio_nombre'] ?: 'N/D'); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
