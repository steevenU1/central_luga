<?php
// promos_equipos_descuento_admin.php
// Admin de Promos: Equipo 2 con % descuento (permite COMBO y/o DOBLE_VENTA)
// - Arreglo pantalla en blanco: ob_start + navbar después de POST (para que header(Location) funcione)
// - Botones: Editar + Activar/Inactivar + Configurar equipos

ob_start();

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
// navbar se incluye DESPUÉS del POST para no romper redirects.

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$perm = in_array($ROL, ['Admin', 'Master Admin'], true);
if (!$perm) { http_response_code(403); echo "Sin permiso"; exit(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function toNullDate($s){
  $s = trim((string)$s);
  return $s === '' ? null : $s;
}

$err = '';
$msg = '';

/* ============================
   Acciones POST
   ============================ */
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_promo') {
      $id     = (int)($_POST['id'] ?? 0);
      $nombre = trim((string)($_POST['nombre'] ?? ''));
      $pct    = (float)($_POST['porcentaje_descuento'] ?? 50);

      // switch activa (checkbox)
      $activa = isset($_POST['activa']) ? 1 : 0;

      // modos permitidos (checkbox)
      $permite_combo      = isset($_POST['permite_combo']) ? 1 : 0;
      $permite_dobleventa = isset($_POST['permite_doble_venta']) ? 1 : 0;

      $fi = toNullDate($_POST['fecha_inicio'] ?? '');
      $ff = toNullDate($_POST['fecha_fin'] ?? '');

      if ($nombre === '') throw new Exception("El nombre es obligatorio.");
      if ($pct <= 0 || $pct >= 100) throw new Exception("El porcentaje debe ser mayor a 0 y menor a 100.");
      if ($permite_combo === 0 && $permite_dobleventa === 0) {
        throw new Exception("Debes habilitar al menos un modo (COMBO o DOBLE_VENTA).");
      }

      $fi2 = $fi ?? '';
      $ff2 = $ff ?? '';

      if ($id > 0) {
        $sql = "UPDATE promos_equipos_descuento
                SET nombre=?,
                    porcentaje_descuento=?,
                    activa=?,
                    permite_combo=?,
                    permite_doble_venta=?,
                    fecha_inicio = NULLIF(?, ''),
                    fecha_fin    = NULLIF(?, '')
                WHERE id=?";
        $st = $conn->prepare($sql);
        $st->bind_param("sdiiissi", $nombre, $pct, $activa, $permite_combo, $permite_dobleventa, $fi2, $ff2, $id);
        $st->execute();
        $msg = "Promo actualizada.";
      } else {
        $sql = "INSERT INTO promos_equipos_descuento
                (nombre, porcentaje_descuento, activa, permite_combo, permite_doble_venta, fecha_inicio, fecha_fin)
                VALUES
                (?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''))";
        $st = $conn->prepare($sql);
        $st->bind_param("sdiiiss", $nombre, $pct, $activa, $permite_combo, $permite_dobleventa, $fi2, $ff2);
        $st->execute();
        $id = (int)$conn->insert_id;
        $msg = "Promo creada.";
      }

      header("Location: promos_equipos_descuento_admin.php?edit=".$id."&msg=".urlencode($msg));
      exit();
    }

    if ($action === 'toggle_promo') {
      $id = (int)($_POST['id'] ?? 0);
      $v  = (int)($_POST['activa'] ?? 0);

      if ($id <= 0) throw new Exception("ID inválido.");

      $st = $conn->prepare("UPDATE promos_equipos_descuento SET activa=? WHERE id=?");
      $st->bind_param("ii", $v, $id);
      $st->execute();

      header("Location: promos_equipos_descuento_admin.php?msg=".urlencode("Estado actualizado."));
      exit();
    }

    if ($action === 'add_item') {
      $promo_id = (int)($_POST['promo_id'] ?? 0);
      $tipo     = trim((string)($_POST['tipo'] ?? 'principal')); // principal | combo
      $codigo   = trim((string)($_POST['codigo_producto'] ?? ''));

      if ($promo_id <= 0) throw new Exception("Promo inválida.");
      if ($codigo === '') throw new Exception("Código obligatorio.");
      if (!in_array($tipo, ['principal','combo'], true)) throw new Exception("Tipo inválido.");

      $tabla = ($tipo === 'principal') ? 'promos_equipos_descuento_principal' : 'promos_equipos_descuento_combo';

      $sql = "INSERT INTO {$tabla} (promo_id, codigo_producto, activo)
              VALUES (?, ?, 1)
              ON DUPLICATE KEY UPDATE activo=1";
      $st = $conn->prepare($sql);
      $st->bind_param("is", $promo_id, $codigo);
      $st->execute();

      header("Location: promos_equipos_descuento_admin.php?edit=".$promo_id."&msg=".urlencode("Código agregado."));
      exit();
    }

    if ($action === 'remove_item') {
      $promo_id = (int)($_POST['promo_id'] ?? 0);
      $tipo     = trim((string)($_POST['tipo'] ?? 'principal'));
      $item_id  = (int)($_POST['item_id'] ?? 0);

      if ($promo_id <= 0 || $item_id <= 0) throw new Exception("Datos inválidos.");
      if (!in_array($tipo, ['principal','combo'], true)) throw new Exception("Tipo inválido.");

      $tabla = ($tipo === 'principal') ? 'promos_equipos_descuento_principal' : 'promos_equipos_descuento_combo';

      $st = $conn->prepare("DELETE FROM {$tabla} WHERE id=? AND promo_id=?");
      $st->bind_param("ii", $item_id, $promo_id);
      $st->execute();

      header("Location: promos_equipos_descuento_admin.php?edit=".$promo_id."&msg=".urlencode("Código eliminado."));
      exit();
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

/* ============================
   Datos para UI
   ============================ */
$editId = (int)($_GET['edit'] ?? 0);
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];
if (isset($_GET['err'])) $err = (string)$_GET['err'];

$promoEdit = null;
if ($editId > 0) {
  $st = $conn->prepare("SELECT * FROM promos_equipos_descuento WHERE id=? LIMIT 1");
  $st->bind_param("i", $editId);
  $st->execute();
  $promoEdit = $st->get_result()->fetch_assoc();
}

$promos = $conn->query("
  SELECT p.*,
    (SELECT COUNT(*) FROM promos_equipos_descuento_principal x WHERE x.promo_id=p.id) AS cnt_principal,
    (SELECT COUNT(*) FROM promos_equipos_descuento_combo y WHERE y.promo_id=p.id) AS cnt_combo
  FROM promos_equipos_descuento p
  ORDER BY p.activa DESC, p.id DESC
")->fetch_all(MYSQLI_ASSOC);

$itemsPrincipal = [];
$itemsCombo = [];
if ($promoEdit) {
  $st = $conn->prepare("SELECT id, codigo_producto, activo, creado_en FROM promos_equipos_descuento_principal WHERE promo_id=? ORDER BY id DESC");
  $st->bind_param("i", $editId);
  $st->execute();
  $itemsPrincipal = $st->get_result()->fetch_all(MYSQLI_ASSOC);

  $st = $conn->prepare("SELECT id, codigo_producto, activo, creado_en FROM promos_equipos_descuento_combo WHERE promo_id=? ORDER BY id DESC");
  $st->bind_param("i", $editId);
  $st->execute();
  $itemsCombo = $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* Selector opcional: traer códigos desde catalogo_modelos si existe */
$catalogoCodigos = [];
try {
  $chk = $conn->query("SHOW TABLES LIKE 'catalogo_modelos'");
  if ($chk && $chk->num_rows > 0) {
    $chk2 = $conn->query("SHOW COLUMNS FROM catalogo_modelos LIKE 'codigo_producto'");
    if ($chk2 && $chk2->num_rows > 0) {
      $catalogoCodigos = $conn->query("
        SELECT DISTINCT codigo_producto
        FROM catalogo_modelos
        WHERE NULLIF(TRIM(codigo_producto),'') IS NOT NULL
        ORDER BY codigo_producto
        LIMIT 2000
      ")->fetch_all(MYSQLI_ASSOC);
    }
  }
} catch (Throwable $e) {
  $catalogoCodigos = [];
}

function modosHuman($p){
  $mods = [];
  if ((int)($p['permite_combo'] ?? 0) === 1) $mods[] = 'COMBO';
  if ((int)($p['permite_doble_venta'] ?? 0) === 1) $mods[] = 'DOBLE_VENTA';
  return count($mods) ? implode(' + ', $mods) : '—';
}

// navbar al final (para no romper redirects)
require_once __DIR__ . '/navbar.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Configurar Promo Descuento Equipos</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico?v=2">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body { background:#f8fafc; }
    .card-elev { border:0; border-radius:16px; box-shadow:0 10px 24px rgba(2,8,20,.06), 0 2px 6px rgba(2,8,20,.05); }
    .mini { font-size:.9rem; color:#64748b; }
    .badge-soft { background:#eef2ff; color:#1e40af; border:1px solid #dbeafe; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .btn-actions .btn { margin-left: .25rem; }
  </style>
</head>
<body>
<div class="container my-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1"><i class="bi bi-sliders2 me-2"></i>Promo: Equipo 2 con % descuento</h3>
      <div class="mini">Configura varias promos al mismo tiempo, con varios equipos principales y combos elegibles.</div>
    </div>
    <a class="btn btn-outline-secondary" href="panel.php"><i class="bi bi-arrow-left"></i> Volver</a>
  </div>

  <?php if ($err !== ''): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= h($err) ?></div>
  <?php endif; ?>
  <?php if ($msg !== ''): ?>
    <div class="alert alert-success"><i class="bi bi-check2-circle me-1"></i><?= h($msg) ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card card-elev">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="fw-semibold">Crear / Editar promo</div>
              <div class="mini">Habilita uno o ambos modos (COMBO / DOBLE_VENTA). % y fechas opcionales.</div>
            </div>
            <?php if ($promoEdit): ?>
              <span class="badge text-bg-primary">Editando #<?= (int)$promoEdit['id'] ?></span>
            <?php else: ?>
              <span class="badge badge-soft">Nueva</span>
            <?php endif; ?>
          </div>

          <hr>

          <form method="post">
            <input type="hidden" name="action" value="save_promo">
            <input type="hidden" name="id" value="<?= (int)($promoEdit['id'] ?? 0) ?>">

            <div class="mb-2">
              <label class="form-label">Nombre</label>
              <input class="form-control" name="nombre" value="<?= h($promoEdit['nombre'] ?? '') ?>" placeholder="Ej. Samsung seleccionados: 2do al 50%">
            </div>

            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">% descuento</label>
                <input class="form-control" name="porcentaje_descuento" type="number" step="0.01" min="0.01" max="99.99"
                       value="<?= h($promoEdit['porcentaje_descuento'] ?? '50.00') ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Modos permitidos</label>
                <?php
                  $pc = (int)($promoEdit['permite_combo'] ?? 1);
                  $pd = (int)($promoEdit['permite_doble_venta'] ?? 1);
                ?>
                <div class="border rounded-3 p-2">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="permite_combo" name="permite_combo" value="1" <?= $pc ? 'checked':'' ?>>
                    <label class="form-check-label" for="permite_combo">COMBO (una venta)</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="permite_doble_venta" name="permite_doble_venta" value="1" <?= $pd ? 'checked':'' ?>>
                    <label class="form-check-label" for="permite_doble_venta">DOBLE_VENTA (2 créditos)</label>
                  </div>
                </div>
              </div>
            </div>

            <div class="row g-2 mt-1">
              <div class="col-md-6">
                <label class="form-label">Fecha inicio</label>
                <input class="form-control" type="date" name="fecha_inicio" value="<?= h($promoEdit['fecha_inicio'] ?? '') ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Fecha fin</label>
                <input class="form-control" type="date" name="fecha_fin" value="<?= h($promoEdit['fecha_fin'] ?? '') ?>">
              </div>
            </div>

            <div class="form-check form-switch mt-3">
              <?php $a = (int)($promoEdit['activa'] ?? 1); ?>
              <input class="form-check-input" type="checkbox" role="switch" id="activa" name="activa" value="1" <?= $a? 'checked':'' ?>>
              <label class="form-check-label" for="activa">Activa</label>
            </div>

            <div class="d-grid mt-3">
              <button class="btn btn-primary">
                <i class="bi bi-save me-1"></i> Guardar promo
              </button>
              <?php if ($promoEdit): ?>
                <a class="btn btn-link" href="promos_equipos_descuento_admin.php">Crear nueva</a>
              <?php endif; ?>
            </div>
          </form>

          <?php if ($promoEdit): ?>
            <div class="alert alert-info mt-3 mb-0">
              <div class="fw-semibold"><i class="bi bi-info-circle me-1"></i> Tip</div>
              <div class="mini mb-0">
                Para que aplique en ventas, esta promo debe tener al menos 1 <span class="mono">principal</span> y 1 <span class="mono">combo</span>.
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card card-elev">
        <div class="card-body">
          <div class="fw-semibold">Promos existentes</div>
          <div class="mini">Activa/Inactiva, editar y ver conteo de principales y combos.</div>
          <hr>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead class="table-light">
                <tr>
                  <th>ID</th>
                  <th>Nombre</th>
                  <th>Modos</th>
                  <th>%</th>
                  <th>Fechas</th>
                  <th>Principal</th>
                  <th>Combo</th>
                  <th>Estado</th>
                  <th class="text-end">Acciones</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($promos as $p): ?>
                <tr>
                  <td class="mono"><?= (int)$p['id'] ?></td>
                  <td><?= h($p['nombre']) ?></td>
                  <td><span class="badge text-bg-secondary"><?= h(modosHuman($p)) ?></span></td>
                  <td><?= number_format((float)$p['porcentaje_descuento'], 2) ?>%</td>
                  <td class="mini">
                    <?= h($p['fecha_inicio'] ?: '—') ?> a <?= h($p['fecha_fin'] ?: '—') ?>
                  </td>
                  <td><span class="badge badge-soft"><?= (int)$p['cnt_principal'] ?></span></td>
                  <td><span class="badge badge-soft"><?= (int)$p['cnt_combo'] ?></span></td>
                  <td>
                    <span class="badge <?= ((int)$p['activa']) ? 'text-bg-success' : 'text-bg-secondary' ?>">
                      <?= ((int)$p['activa']) ? 'Activa' : 'Inactiva' ?>
                    </span>
                  </td>
                  <td class="text-end btn-actions">
                    <!-- Editar (para extender fechas / cambiar %) -->
                    <a class="btn btn-sm btn-primary"
                       href="promos_equipos_descuento_admin.php?edit=<?= (int)$p['id'] ?>">
                      <i class="bi bi-pencil-square"></i> Editar
                    </a>

                    <!-- Configurar equipos -->
                    <a class="btn btn-sm btn-outline-primary"
                       href="promos_equipos_descuento_admin.php?edit=<?= (int)$p['id'] ?>#equipos">
                      <i class="bi bi-gear"></i> Equipos
                    </a>

                    <!-- Activar/Inactivar -->
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="toggle_promo">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="activa" value="<?= (int)!((int)$p['activa']) ?>">
                      <button class="btn btn-sm <?= ((int)$p['activa']) ? 'btn-outline-danger' : 'btn-success' ?>"
                              onclick="return confirm('¿Seguro que deseas <?= ((int)$p['activa']) ? 'inactivar' : 'activar' ?> esta promo?')">
                        <?= ((int)$p['activa']) ? '<i class="bi bi-pause-circle"></i> Inactivar' : '<i class="bi bi-play-circle"></i> Activar' ?>
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (count($promos) === 0): ?>
                <tr><td colspan="9" class="text-center mini">Aún no hay promos.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>

      <?php if ($promoEdit): ?>
        <div class="row g-3 mt-0" id="equipos">
          <div class="col-md-6">
            <div class="card card-elev">
              <div class="card-body">
                <div class="fw-semibold"><i class="bi bi-lightning-charge me-1"></i>Equipos principales (activan)</div>
                <div class="mini">Códigos que al venderse activan la promo.</div>
                <hr>

                <form method="post" class="row g-2">
                  <input type="hidden" name="action" value="add_item">
                  <input type="hidden" name="promo_id" value="<?= (int)$promoEdit['id'] ?>">
                  <input type="hidden" name="tipo" value="principal">

                  <div class="col-8">
                    <?php if (count($catalogoCodigos) > 0): ?>
                      <select class="form-select mono" name="codigo_producto" required>
                        <option value="">Selecciona código</option>
                        <?php foreach ($catalogoCodigos as $c): ?>
                          <option value="<?= h($c['codigo_producto']) ?>"><?= h($c['codigo_producto']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                      <input class="form-control mono" name="codigo_producto" placeholder="CODIGO_PRODUCTO" required>
                      <div class="mini mt-1">No detecté catalogo_modelos.codigo_producto, captura manual.</div>
                    <?php endif; ?>
                  </div>
                  <div class="col-4 d-grid">
                    <button class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i> Agregar</button>
                  </div>
                </form>

                <div class="table-responsive mt-3">
                  <table class="table table-sm align-middle">
                    <thead class="table-light">
                      <tr><th>Código</th><th></th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($itemsPrincipal as $it): ?>
                        <tr>
                          <td class="mono"><?= h($it['codigo_producto']) ?></td>
                          <td class="text-end">
                            <form method="post" class="d-inline">
                              <input type="hidden" name="action" value="remove_item">
                              <input type="hidden" name="promo_id" value="<?= (int)$promoEdit['id'] ?>">
                              <input type="hidden" name="tipo" value="principal">
                              <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                              <button class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar este código?')">
                                <i class="bi bi-trash"></i>
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (count($itemsPrincipal) === 0): ?>
                        <tr><td colspan="2" class="mini text-center">Sin códigos aún.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="card card-elev">
              <div class="card-body">
                <div class="fw-semibold"><i class="bi bi-percent me-1"></i>Equipos combo (con descuento)</div>
                <div class="mini">Códigos que pueden ir con % descuento como equipo 2.</div>
                <hr>

                <form method="post" class="row g-2">
                  <input type="hidden" name="action" value="add_item">
                  <input type="hidden" name="promo_id" value="<?= (int)$promoEdit['id'] ?>">
                  <input type="hidden" name="tipo" value="combo">

                  <div class="col-8">
                    <?php if (count($catalogoCodigos) > 0): ?>
                      <select class="form-select mono" name="codigo_producto" required>
                        <option value="">Selecciona código</option>
                        <?php foreach ($catalogoCodigos as $c): ?>
                          <option value="<?= h($c['codigo_producto']) ?>"><?= h($c['codigo_producto']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                      <input class="form-control mono" name="codigo_producto" placeholder="CODIGO_PRODUCTO" required>
                      <div class="mini mt-1">No detecté catalogo_modelos.codigo_producto, captura manual.</div>
                    <?php endif; ?>
                  </div>
                  <div class="col-4 d-grid">
                    <button class="btn btn-outline-success"><i class="bi bi-plus-lg"></i> Agregar</button>
                  </div>
                </form>

                <div class="table-responsive mt-3">
                  <table class="table table-sm align-middle">
                    <thead class="table-light">
                      <tr><th>Código</th><th></th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($itemsCombo as $it): ?>
                        <tr>
                          <td class="mono"><?= h($it['codigo_producto']) ?></td>
                          <td class="text-end">
                            <form method="post" class="d-inline">
                              <input type="hidden" name="action" value="remove_item">
                              <input type="hidden" name="promo_id" value="<?= (int)$promoEdit['id'] ?>">
                              <input type="hidden" name="tipo" value="combo">
                              <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                              <button class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar este código?')">
                                <i class="bi bi-trash"></i>
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (count($itemsCombo) === 0): ?>
                        <tr><td colspan="2" class="mini text-center">Sin códigos aún.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </div>

</div>
</body>
</html>
<?php ob_end_flush(); ?>
