<?php
// gerente_multisucursal.php — Asignación rápida de gerente a múltiples sucursales (Nano)
// Requiere tabla: gerente_sucursales(id_gerente, id_sucursal, activo)
// Acceso: Admin

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

$ROL = $_SESSION['rol'] ?? '';
if ($ROL !== 'Admin') { header("Location: 403.php"); exit(); }

// Normaliza collation (por si tu stack lo ocupa)
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function tableExists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $sql = "SELECT 1 FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = '$t' LIMIT 1";
  $res = $conn->query($sql);
  return (bool)($res && $res->num_rows > 0);
}

function columnExists(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = '$t' AND column_name = '$c' LIMIT 1";
  $res = $conn->query($sql);
  return (bool)($res && $res->num_rows > 0);
}

$errors = [];
$okMsg = '';

/* ===== Asegura tabla gerente_sucursales ===== */
if (!tableExists($conn, 'gerente_sucursales')) {
  $conn->query("
    CREATE TABLE gerente_sucursales (
      id INT AUTO_INCREMENT PRIMARY KEY,
      id_gerente INT NOT NULL,
      id_sucursal INT NOT NULL,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_ger_suc (id_gerente, id_sucursal),
      INDEX idx_ger (id_gerente),
      INDEX idx_suc (id_sucursal)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");
}

/* ===== Descubrir nombres de roles de gerente =====
   En Nano a veces rol = 'Gerente' o 'GERENTE' o similar.
   Aquí agarramos usuarios cuyo rol contenga 'gerente' (case-insensitive).
*/
$hasRol = columnExists($conn, 'usuarios', 'rol');
$hasNombre = columnExists($conn, 'usuarios', 'nombre');
$hasCorreo = columnExists($conn, 'usuarios', 'correo');
$hasIdSuc = columnExists($conn, 'usuarios', 'id_sucursal');

if (!$hasRol || !$hasNombre) {
  $errors[] = "La tabla 'usuarios' debe tener columnas 'rol' y 'nombre'.";
}

/* ===== Procesar acciones ===== */
$action = $_POST['action'] ?? '';
$selectedGerente = (int)($_REQUEST['id_gerente'] ?? 0);

try {
  if ($action === 'save_bulk') {
    $id_gerente = (int)($_POST['id_gerente'] ?? 0);
    $sucs = $_POST['sucursales'] ?? []; // array
    if ($id_gerente <= 0) throw new Exception("Selecciona un gerente válido.");
    if (!is_array($sucs)) $sucs = [];

    // Convertir a ints únicos
    $sucIds = [];
    foreach ($sucs as $sid) {
      $sid = (int)$sid;
      if ($sid > 0) $sucIds[$sid] = true;
    }
    $sucIds = array_keys($sucIds);

    // 1) Marcar activo=0 para todas las asignaciones del gerente
    $stmt = $conn->prepare("UPDATE gerente_sucursales SET activo=0 WHERE id_gerente=?");
    $stmt->bind_param("i", $id_gerente);
    $stmt->execute();

    // 2) Activar/insertar las seleccionadas
    if (!empty($sucIds)) {
      $stmtIns = $conn->prepare("
        INSERT INTO gerente_sucursales (id_gerente, id_sucursal, activo)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE activo=1
      ");
      foreach ($sucIds as $sid) {
        $stmtIns->bind_param("ii", $id_gerente, $sid);
        $stmtIns->execute();
      }
    }

    $okMsg = "Asignaciones actualizadas.";
    $selectedGerente = $id_gerente;
  }

  if ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $to = (int)($_POST['to'] ?? 0); // 0|1
    if ($id <= 0) throw new Exception("ID inválido.");
    $to = $to ? 1 : 0;

    $stmt = $conn->prepare("UPDATE gerente_sucursales SET activo=? WHERE id=?");
    $stmt->bind_param("ii", $to, $id);
    $stmt->execute();

    $okMsg = "Actualizado.";
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) throw new Exception("ID inválido.");

    $stmt = $conn->prepare("DELETE FROM gerente_sucursales WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $okMsg = "Eliminado.";
  }

} catch (Throwable $e) {
  $errors[] = $e->getMessage();
}

/* ===== Data: Gerentes ===== */
$gerentes = [];
if (empty($errors)) {
  // rol LIKE '%gerente%' (case-insensitive)
  $sqlG = "SELECT id, nombre"
        . ($hasCorreo ? ", correo" : "")
        . ($hasIdSuc ? ", id_sucursal" : "")
        . " FROM usuarios
           WHERE LOWER(rol) LIKE '%gerente%'
           ORDER BY nombre";
  $gerentes = $conn->query($sqlG)->fetch_all(MYSQLI_ASSOC);
}

/* ===== Data: Sucursales ===== */
$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

/* ===== Data: Asignaciones del gerente seleccionado ===== */
$asignadas = []; // sucursal_id => activo
$asignRows = [];
if ($selectedGerente > 0) {
  $stmt = $conn->prepare("
    SELECT gs.id, gs.id_sucursal, gs.activo, s.nombre AS sucursal_nombre
    FROM gerente_sucursales gs
    JOIN sucursales s ON s.id = gs.id_sucursal
    WHERE gs.id_gerente = ?
    ORDER BY s.nombre
  ");
  $stmt->bind_param("i", $selectedGerente);
  $stmt->execute();
  $res = $stmt->get_result();
  $asignRows = $res->fetch_all(MYSQLI_ASSOC);
  foreach ($asignRows as $r) $asignadas[(int)$r['id_sucursal']] = (int)$r['activo'];
}

/* ===== Nombre gerente seleccionado ===== */
$gerenteNombre = '';
if ($selectedGerente > 0 && !empty($gerentes)) {
  foreach ($gerentes as $g) {
    if ((int)$g['id'] === $selectedGerente) { $gerenteNombre = (string)$g['nombre']; break; }
  }
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gerente Multi-sucursal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f6f7fb; }
    .card { border:0; border-radius:16px; box-shadow:0 10px 25px rgba(0,0,0,.06); }
    .badge-soft { background:#eef2ff; color:#3730a3; }
    .sticky-actions { position: sticky; top: 12px; z-index: 5; }
    .small-muted { font-size:.92rem; color:#6b7280; }
  </style>
</head>
<body>
<?php
// Si tienes navbar.php úsalo. Si no, comenta estas 2 líneas.
if (file_exists(__DIR__.'/navbar.php')) require_once __DIR__.'/navbar.php';
?>
<div class="container py-4">

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
      <h3 class="mb-0">Gerente Multi-sucursal</h3>
      <div class="small-muted">Asigna qué sucursales le suman comisión de gerente (Eq/SIMs/Posp/TC) en la nómina.</div>
    </div>
    <span class="badge badge-soft px-3 py-2 rounded-pill">Nano</span>
  </div>

  <?php if (!empty($okMsg)): ?>
    <div class="alert alert-success"><?=h($okMsg)?></div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach($errors as $er): ?>
          <li><?=h($er)?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card p-3">
        <h5 class="mb-2">1) Selecciona gerente</h5>

        <form method="get" class="d-flex gap-2">
          <select name="id_gerente" class="form-select" onchange="this.form.submit()">
            <option value="0">-- Elige un gerente --</option>
            <?php foreach($gerentes as $g): ?>
              <?php $idg = (int)$g['id']; ?>
              <option value="<?=$idg?>" <?=($idg===$selectedGerente?'selected':'')?>>
                <?=h($g['nombre'])?>
                <?php if (isset($g['correo'])): ?> (<?=h($g['correo'])?>)<?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-primary">Ver</button></noscript>
        </form>

        <hr class="my-3">

        <div class="small-muted">
          Tip: si tu rol de gerente no contiene la palabra “gerente”, dime cuál es (ej: “GerenteZona”, “Gerente_Tienda”, etc.) y ajusto el filtro en 10 segundos.
        </div>
      </div>

      <?php if ($selectedGerente > 0): ?>
        <div class="card p-3 mt-3">
          <h6 class="mb-1">Gerente seleccionado</h6>
          <div class="fw-semibold"><?=h($gerenteNombre ?: ('ID '.$selectedGerente))?></div>
          <div class="small-muted">Marca sus sucursales y guarda.</div>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-lg-8">
      <div class="card p-3">
        <div class="d-flex align-items-center justify-content-between gap-2">
          <div>
            <h5 class="mb-0">2) Sucursales asignadas</h5>
            <div class="small-muted">Lo que marques aquí será lo que se sume al gerente en nómina.</div>
          </div>
          <div class="sticky-actions">
            <?php if ($selectedGerente > 0): ?>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll(true)">Marcar todo</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll(false)">Limpiar</button>
            <?php endif; ?>
          </div>
        </div>

        <hr class="my-3">

        <?php if ($selectedGerente <= 0): ?>
          <div class="alert alert-info mb-0">Selecciona un gerente para configurar sus sucursales.</div>
        <?php else: ?>

          <form method="post">
            <input type="hidden" name="action" value="save_bulk">
            <input type="hidden" name="id_gerente" value="<?= (int)$selectedGerente ?>">

            <div class="row g-2">
              <?php foreach($sucursales as $s): ?>
                <?php
                  $sid = (int)$s['id'];
                  $checked = isset($asignadas[$sid]) ? ((int)$asignadas[$sid] === 1) : false;
                ?>
                <div class="col-md-6">
                  <label class="border rounded-3 p-2 w-100 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                      <input class="form-check-input suc-check" type="checkbox" name="sucursales[]" value="<?=$sid?>" <?=($checked?'checked':'')?>>
                      <span><?=h($s['nombre'])?></span>
                    </div>
                    <?php if (isset($asignadas[$sid])): ?>
                      <span class="badge <?=((int)$asignadas[$sid]===1?'text-bg-success':'text-bg-secondary')?> rounded-pill">
                        <?=((int)$asignadas[$sid]===1?'Activa':'Inactiva')?>
                      </span>
                    <?php else: ?>
                      <span class="badge text-bg-light rounded-pill">Sin asignación</span>
                    <?php endif; ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button class="btn btn-primary">Guardar asignación</button>
            </div>

          </form>

          <hr class="my-4">

          <h6 class="mb-2">Asignaciones registradas (detalle)</h6>
          <?php if (empty($asignRows)): ?>
            <div class="alert alert-warning mb-0">Aún no hay asignaciones registradas para este gerente.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Sucursal</th>
                    <th style="width:140px">Estado</th>
                    <th style="width:220px" class="text-end">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($asignRows as $r): ?>
                    <?php
                      $rid = (int)$r['id'];
                      $act = (int)$r['activo'];
                    ?>
                    <tr>
                      <td><?=h($r['sucursal_nombre'])?></td>
                      <td>
                        <span class="badge <?=($act===1?'text-bg-success':'text-bg-secondary')?> rounded-pill">
                          <?=($act===1?'Activa':'Inactiva')?>
                        </span>
                      </td>
                      <td class="text-end">
                        <form method="post" class="d-inline">
                          <input type="hidden" name="action" value="toggle">
                          <input type="hidden" name="id" value="<?=$rid?>">
                          <input type="hidden" name="to" value="<?=($act===1?0:1)?>">
                          <button class="btn btn-outline-primary btn-sm">
                            <?=($act===1?'Desactivar':'Activar')?>
                          </button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar asignación?')">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?=$rid?>">
                          <button class="btn btn-outline-danger btn-sm">Eliminar</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="small-muted mt-3">
    Nota: La nómina debe leer esta tabla para sumar comisiones de gerente por sucursal (lo que ya dejamos listo en el reporte).
  </div>

</div>

<script>
function selectAll(on){
  document.querySelectorAll('.suc-check').forEach(cb => cb.checked = !!on);
}
</script>
</body>
</html>
