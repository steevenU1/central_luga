<?php
// proveedores.php
// Requiere tabla `proveedores` con columnas:
// id, nombre, rfc, contacto, telefono, email, direccion, activo, creado_en
// Si aún no la tienes, puedes crearla con:
// CREATE TABLE IF NOT EXISTS proveedores (
//   id INT AUTO_INCREMENT PRIMARY KEY,
//   nombre VARCHAR(120) NOT NULL,
//   rfc VARCHAR(20),
//   contacto VARCHAR(120),
//   telefono VARCHAR(30),
//   email VARCHAR(120),
//   direccion TEXT,
//   activo TINYINT(1) DEFAULT 1,
//   creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';
include 'navbar.php';

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
// Permisos: Admin y Gerente pueden crear/editar; otros solo ven.
$permEscritura = in_array($ROL, ['Admin','Gerente']);

// ------- Helpers -------
function texto($s, $len) { return substr(trim($s ?? ''), 0, $len); }
$mensaje = "";

// ------- Acciones POST (crear/editar) -------
if ($permEscritura && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $modo = $_POST['modo'] ?? 'crear';
  $nombre   = texto($_POST['nombre'] ?? '', 120);
  $rfc      = texto($_POST['rfc'] ?? '', 20);
  $contacto = texto($_POST['contacto'] ?? '', 120);
  $telefono = texto($_POST['telefono'] ?? '', 30);
  $email    = texto($_POST['email'] ?? '', 120);
  $direccion= texto($_POST['direccion'] ?? '', 1000);

  if ($nombre === '') {
    $mensaje = "<div class='alert alert-danger'>El nombre es obligatorio.</div>";
  } else {
    if ($modo === 'editar') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $stmt = $conn->prepare("UPDATE proveedores SET nombre=?, rfc=?, contacto=?, telefono=?, email=?, direccion=? WHERE id=?");
        $stmt->bind_param("ssssssi", $nombre, $rfc, $contacto, $telefono, $email, $direccion, $id);
        $ok = $stmt->execute();
        $stmt->close();
        $mensaje = $ok
          ? "<div class='alert alert-success'>Proveedor actualizado correctamente.</div>"
          : "<div class='alert alert-danger'>Error al actualizar proveedor.</div>";
      }
    } else {
      // Validación simple de duplicado por nombre (opcional)
      $du = $conn->prepare("SELECT COUNT(*) c FROM proveedores WHERE nombre=?");
      $du->bind_param("s", $nombre);
      $du->execute();
      $du->bind_result($cdup);
      $du->fetch();
      $du->close();

      if ($cdup > 0) {
        $mensaje = "<div class='alert alert-warning'>Ya existe un proveedor con ese nombre.</div>";
      } else {
        $stmt = $conn->prepare("INSERT INTO proveedores (nombre, rfc, contacto, telefono, email, direccion, activo) VALUES (?,?,?,?,?,?,1)");
        $stmt->bind_param("ssssss", $nombre, $rfc, $contacto, $telefono, $email, $direccion);
        $ok = $stmt->execute();
        $stmt->close();
        $mensaje = $ok
          ? "<div class='alert alert-success'>Proveedor creado correctamente.</div>"
          : "<div class='alert alert-danger'>Error al crear proveedor.</div>";
      }
    }
  }
}

// ------- Acciones GET (activar/inactivar y cargar para edición) -------
if ($permEscritura && isset($_GET['accion'], $_GET['id'])) {
  $id = (int)$_GET['id'];
  if ($_GET['accion'] === 'toggle' && $id > 0) {
    $conn->query("UPDATE proveedores SET activo = IF(activo=1,0,1) WHERE id = $id");
    header("Location: proveedores.php");
    exit();
  }
}

// Para cargar un proveedor a editar
$editItem = null;
if ($permEscritura && isset($_GET['editar'])) {
  $idEd = (int)$_GET['editar'];
  if ($idEd > 0) {
    $res = $conn->query("SELECT * FROM proveedores WHERE id = $idEd");
    $editItem = $res ? $res->fetch_assoc() : null;
  }
}

// ------- Filtros de listado -------
$filtroEstado = $_GET['estado'] ?? 'activos'; // activos|inactivos|todos
$busqueda = texto($_GET['q'] ?? '', 80);

$where = [];
if ($filtroEstado === 'activos')   $where[] = "activo = 1";
if ($filtroEstado === 'inactivos') $where[] = "activo = 0";
if ($busqueda !== '') {
  $q = $conn->real_escape_string($busqueda);
  $where[] = "(nombre LIKE '%$q%' OR rfc LIKE '%$q%' OR contacto LIKE '%$q%' OR telefono LIKE '%$q%' OR email LIKE '%$q%')";
}
$sqlWhere = count($where) ? ("WHERE " . implode(" AND ", $where)) : "";

// ------- Consulta principal -------
$sql = "SELECT id, nombre, rfc, contacto, telefono, email, activo, DATE_FORMAT(creado_en,'%Y-%m-%d') AS creado FROM proveedores $sqlWhere ORDER BY nombre ASC";
$proveedores = $conn->query($sql);
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->

<div class="container my-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <h3 class="mb-2">Proveedores</h3>
    <?php if ($permEscritura): ?>
      <a href="proveedores.php" class="btn btn-outline-secondary btn-sm">Nuevo</a>
    <?php endif; ?>
  </div>

  <?= $mensaje ?>

  <div class="row g-3">
    <?php if ($permEscritura): ?>
    <!-- ====== Formulario (crear / editar) ====== -->
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-header"><?= $editItem ? 'Editar proveedor' : 'Nuevo proveedor' ?></div>
        <div class="card-body">
          <form method="POST" class="row g-2">
            <input type="hidden" name="modo" value="<?= $editItem ? 'editar' : 'crear' ?>">
            <?php if ($editItem): ?><input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>"><?php endif; ?>

            <div class="col-12">
              <label class="form-label">Nombre *</label>
              <input type="text" name="nombre" class="form-control" required
                     value="<?= htmlspecialchars($editItem['nombre'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">RFC</label>
              <input type="text" name="rfc" class="form-control"
                     value="<?= htmlspecialchars($editItem['rfc'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Contacto</label>
              <input type="text" name="contacto" class="form-control"
                     value="<?= htmlspecialchars($editItem['contacto'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Teléfono</label>
              <input type="text" name="telefono" class="form-control"
                     value="<?= htmlspecialchars($editItem['telefono'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control"
                     value="<?= htmlspecialchars($editItem['email'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Dirección</label>
              <textarea name="direccion" class="form-control" rows="2"><?= htmlspecialchars($editItem['direccion'] ?? '') ?></textarea>
            </div>

            <div class="col-12 text-end">
              <button class="btn btn-success"><?= $editItem ? 'Actualizar' : 'Guardar' ?></button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ====== Listado ====== -->
    <div class="<?= $permEscritura ? 'col-lg-7' : 'col-12' ?>">
      <div class="card shadow-sm">
        <div class="card-header">
          <form class="row g-2 align-items-center">
            <div class="col-md-4">
              <select name="estado" class="form-select" onchange="this.form.submit()">
                <option value="activos"   <?= $filtroEstado==='activos'?'selected':'' ?>>Activos</option>
                <option value="inactivos" <?= $filtroEstado==='inactivos'?'selected':'' ?>>Inactivos</option>
                <option value="todos"     <?= $filtroEstado==='todos'?'selected':'' ?>>Todos</option>
              </select>
            </div>
            <div class="col-md-6">
              <input type="text" name="q" class="form-control" placeholder="Buscar por nombre, RFC, contacto..."
                     value="<?= htmlspecialchars($busqueda) ?>">
            </div>
            <div class="col-md-2 text-end">
              <button class="btn btn-primary w-100">Buscar</button>
            </div>
          </form>
        </div>

        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th>RFC</th>
                  <th>Contacto</th>
                  <th>Teléfono</th>
                  <th>Email</th>
                  <th>Alta</th>
                  <th class="text-center">Estatus</th>
                  <th class="text-end">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($proveedores && $proveedores->num_rows): ?>
                  <?php while($p = $proveedores->fetch_assoc()): ?>
                    <tr>
                      <td><?= htmlspecialchars($p['nombre']) ?></td>
                      <td><?= htmlspecialchars($p['rfc']) ?></td>
                      <td><?= htmlspecialchars($p['contacto']) ?></td>
                      <td><?= htmlspecialchars($p['telefono']) ?></td>
                      <td><?= htmlspecialchars($p['email']) ?></td>
                      <td><?= htmlspecialchars($p['creado']) ?></td>
                      <td class="text-center">
                        <?php if ((int)$p['activo'] === 1): ?>
                          <span class="badge bg-success">Activo</span>
                        <?php else: ?>
                          <span class="badge bg-secondary">Inactivo</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-end">
                        <div class="btn-group">
                          <a class="btn btn-sm btn-outline-primary" href="proveedores.php?editar=<?= (int)$p['id'] ?>">Editar</a>
                          <?php if ($permEscritura): ?>
                            <a class="btn btn-sm btn-outline-<?= $p['activo'] ? 'danger' : 'success' ?>"
                               href="proveedores.php?accion=toggle&id=<?= (int)$p['id'] ?>"
                               onclick="return confirm('¿Seguro que deseas <?= $p['activo']?'inactivar':'activar' ?> este proveedor?');">
                               <?= $p['activo'] ? 'Inactivar' : 'Activar' ?>
                            </a>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="8" class="text-center text-muted py-4">Sin proveedores</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>
