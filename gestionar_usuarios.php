<?php
// gestionar_usuarios.php — UNIFICADO (Alta + Gestión) + Multi-tenant Luga/Subdis
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';

$ROL         = $_SESSION['rol'] ?? '';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);
$MI_SUBDIS   = (int)($_SESSION['id_subdis'] ?? 0); // ✅ en tu tabla usuarios ya existe (NULL o int)

// =================== PERMISOS (núcleo) ===================
// Admin (Luga): rol Admin y SIN subdis
$permAdminLuga   = ($ROL === 'Admin' && $MI_SUBDIS === 0);
// Subdis Admin: rol Subdis_Admin y con subdis asignado
$permAdminSubdis = ($ROL === 'Subdis_Admin' && $MI_SUBDIS > 0);

// Solo lectura para otros subdis
$permLecturaSubdis = (in_array($ROL, ['Subdis_Gerente','Subdis_Ejecutivo','Subdis_Administrativo'], true) && $MI_SUBDIS > 0);

// Gerente Luga (si lo quieres conservar con permisos limitados como tu archivo anterior)
$permGerenteLuga = ($ROL === 'Gerente' && $MI_SUBDIS === 0);

// Gate de acceso general a la vista
$puedeVer = ($permAdminLuga || $permAdminSubdis || $permLecturaSubdis || $permGerenteLuga);
if (!$puedeVer) { header("Location: 403.php"); exit(); }

// =================== Helpers ===================
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function generarTemporal() {
  $alf = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^*()-_+=';
  $len = random_int(12, 16);
  $out = '';
  for ($i=0; $i<$len; $i++) $out .= $alf[random_int(0, strlen($alf)-1)];
  return $out;
}
function log_usuario($conn, $actor_id, $target_id, $accion, $detalles = '') {
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;
  $stmt = $conn->prepare("INSERT INTO usuarios_log (actor_id, target_id, accion, detalles, ip) VALUES (?,?,?,?,?)");
  $stmt->bind_param("iisss", $actor_id, $target_id, $accion, $detalles, $ip);
  $stmt->execute();
  $stmt->close();
}

// =================== Bitácora: crear tabla si no existe ===================
$conn->query("
  CREATE TABLE IF NOT EXISTS usuarios_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NOT NULL,
    target_id INT NOT NULL,
    accion ENUM('alta','baja','reactivar','cambiar_rol','reset_password','cambiar_sucursal') NOT NULL,
    detalles TEXT,
    ip VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (target_id),
    INDEX (actor_id),
    INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// =================== CSRF ===================
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];

$mensaje = "";

// =================== Catálogos base ===================
$suc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre ASC")->fetch_all(MYSQLI_ASSOC);
$subdisList = $conn->query("SELECT id, nombre_comercial FROM subdistribuidores WHERE estatus='Activo' ORDER BY nombre_comercial ASC")->fetch_all(MYSQLI_ASSOC);

// Roles permitidos por "ámbito"
$rolesLuga   = ['Ejecutivo','Gerente','GerenteZona','Supervisor','Admin','Logistica','Sistemas'];
$rolesSubdis = ['Subdis_Admin','Subdis_Gerente','Subdis_Ejecutivo','Subdis_Administrativo'];

// =================== Funciones de SCOPE (candados) ===================
function scopeWhereUsuarios($permAdminLuga, $permAdminSubdis, $permLecturaSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL){
  // Devuelve [sqlExtra, params, types]
  if ($permAdminLuga) {
    return ["", [], ""];
  }
  if ($permAdminSubdis || $permLecturaSubdis) {
    // Solo su subdis
    return [" AND IFNULL(u.id_subdis,0)=? ", [$MI_SUBDIS], "i"];
  }
  if ($permGerenteLuga) {
    // Gerente Luga: solo su sucursal (como tu lógica previa)
    return [" AND u.id_sucursal=? AND IFNULL(u.id_subdis,0)=0 ", [$ID_SUCURSAL], "i"];
  }
  return [" AND 1=0 ", [], ""];
}

function puedeOperarTarget($permAdminLuga, $permAdminSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL, $target){
  // Admin Luga puede operar todo
  if ($permAdminLuga) return true;

  // Subdis Admin solo usuarios de su subdis
  if ($permAdminSubdis) {
    return ((int)($target['id_subdis'] ?? 0) === $MI_SUBDIS);
  }

  // Gerente Luga: solo ejecutivos de su sucursal (tu regla original)
  if ($permGerenteLuga) {
    return (($target['rol'] ?? '') === 'Ejecutivo' && (int)$target['id_sucursal'] === $ID_SUCURSAL && (int)($target['id_subdis'] ?? 0) === 0);
  }

  return false;
}

// =================== EXPORT CSV (scopeado) ===================
if (isset($_GET['export']) && $_GET['export'] === 'activos_csv') {
  // Solo quien puede ver
  if (!$puedeVer) { header("Location: 403.php"); exit(); }

  [$extra, $p, $t] = scopeWhereUsuarios($permAdminLuga, $permAdminSubdis, $permLecturaSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL);

  $sql = "
    SELECT u.id, u.nombre, u.usuario, u.rol, u.id_sucursal, s.nombre AS sucursal_nombre,
           IFNULL(u.id_subdis,0) AS id_subdis,
           sd.nombre_comercial AS subdis_nombre
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN subdistribuidores sd ON sd.id = u.id_subdis
    WHERE u.activo=1
    $extra
    ORDER BY IFNULL(sd.nombre_comercial,''), s.nombre ASC, u.nombre ASC
  ";

  $stmt = $conn->prepare($sql);
  if ($t !== "") $stmt->bind_param($t, ...$p);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $filename = 'usuarios_activos_' . date('Ymd_His') . '.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  echo "\xEF\xBB\xBF";

  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','Nombre','Usuario','Rol','ID Sucursal','Sucursal','ID Subdis','Subdistribuidor']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['id'],
      $r['nombre'],
      $r['usuario'],
      $r['rol'],
      $r['id_sucursal'],
      $r['sucursal_nombre'] ?? '',
      $r['id_subdis'],
      $r['subdis_nombre'] ?? ''
    ]);
  }
  fclose($out);
  exit;
}

// =================== POST Actions (Alta + Operaciones) ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {

  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $mensaje = "<div class='alert alert-danger'>❌ Token inválido. Recarga la página.</div>";
  } else {

    $accion = $_POST['accion'];

    try {
      $conn->begin_transaction();

      // ===== ACCIÓN: ALTA DE USUARIO =====
      if ($accion === 'crear_usuario') {

        // Solo Admin Luga o Subdis_Admin pueden crear
        if (!$permAdminLuga && !$permAdminSubdis) {
          throw new Exception("No tienes permisos para crear usuarios.");
        }

        $nombre      = trim($_POST['nombre'] ?? '');
        $usuario     = trim($_POST['usuario'] ?? '');
        $password    = trim($_POST['password'] ?? '');
        $id_sucursal = (int)($_POST['id_sucursal'] ?? 0);
        $rolNuevo    = trim($_POST['rol'] ?? '');
        $sueldo      = (float)($_POST['sueldo'] ?? 0);

        // Determinar a qué ámbito pertenece el nuevo usuario (Luga/Subdis)
        $tipoCuenta = $_POST['tipo_cuenta'] ?? '';   // 'PROPIO' | 'SUBDIS' (solo Admin Luga puede elegir)
        $idSubdisForm = (int)($_POST['id_subdis'] ?? 0);

        // Validaciones
        if ($nombre === '' || $usuario === '' || $id_sucursal <= 0 || $rolNuevo === '' || $sueldo <= 0) {
          throw new Exception("Todos los campos son obligatorios y el sueldo debe ser mayor a 0.");
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $usuario)) {
          throw new Exception("El usuario solo puede contener letras, números, punto, guion y guion bajo (3 a 32 caracteres).");
        }

        // Password: si viene vacío, generamos temporal
        $tempGen = null;
        if ($password === '') {
          $tempGen = generarTemporal();
          $password = $tempGen;
        }

        // Duplicado global
        $stmt = $conn->prepare("SELECT COUNT(*) FROM usuarios WHERE LOWER(usuario)=LOWER(?)");
        $stmt->bind_param("s", $usuario);
        $stmt->execute();
        $stmt->bind_result($existe);
        $stmt->fetch();
        $stmt->close();
        if ($existe > 0) throw new Exception("El usuario <b>" . h($usuario) . "</b> ya existe.");

        // Resolver id_subdis a asignar
        $id_subdis_nuevo = 0;

        if ($permAdminSubdis) {
          // Subdis_Admin SOLO puede crear dentro de su subdis
          $id_subdis_nuevo = $MI_SUBDIS;

          // Y SOLO puede usar roles Subdis
          if (!in_array($rolNuevo, $rolesSubdis, true)) {
            throw new Exception("Como Subdis, solo puedes crear roles SUBDIS.");
          }
        } else {
          // Admin Luga puede crear Luga o Subdis
          if ($tipoCuenta !== 'PROPIO' && $tipoCuenta !== 'SUBDIS') {
            throw new Exception("Selecciona Tipo de cuenta (PROPIO o SUBDIS).");
          }

          if ($tipoCuenta === 'PROPIO') {
            $id_subdis_nuevo = 0;
            if (!in_array($rolNuevo, $rolesLuga, true)) {
              throw new Exception("Para cuenta PROPIA solo puedes asignar roles de LUGA.");
            }
          } else {
            // SUBDIS
            if ($idSubdisForm <= 0) throw new Exception("Selecciona el Subdistribuidor.");
            $id_subdis_nuevo = $idSubdisForm;

            if (!in_array($rolNuevo, $rolesSubdis, true)) {
              throw new Exception("Para cuenta SUBDIS solo puedes asignar roles SUBDIS.");
            }
          }
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $must_change_password = 1;

        // Insert
        if ($id_subdis_nuevo > 0) {
          $stmt = $conn->prepare("
            INSERT INTO usuarios (nombre, usuario, password, id_sucursal, rol, sueldo, must_change_password, id_subdis, activo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
          ");
          $stmt->bind_param("sssissii", $nombre, $usuario, $hash, $id_sucursal, $rolNuevo, $sueldo, $must_change_password, $id_subdis_nuevo);
        } else {
          $stmt = $conn->prepare("
            INSERT INTO usuarios (nombre, usuario, password, id_sucursal, rol, sueldo, must_change_password, id_subdis, activo)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 1)
          ");
          $stmt->bind_param("sssissi", $nombre, $usuario, $hash, $id_sucursal, $rolNuevo, $sueldo, $must_change_password);
        }

        if (!$stmt->execute()) {
          throw new Exception("Error al registrar usuario: " . h($stmt->error));
        }
        $nuevoId = (int)$stmt->insert_id;
        $stmt->close();

        log_usuario($conn, $ID_USUARIO, $nuevoId, 'alta', "Rol: $rolNuevo | Sucursal: $id_sucursal | id_subdis: ".($id_subdis_nuevo?:'NULL'));

        $conn->commit();

        $extraTemp = $tempGen ? "<br>🔐 Contraseña temporal: <code style='user-select:all'>".h($tempGen)."</code>" : "";
        $mensaje = "<div class='alert alert-success'>✅ Usuario <b>".h($usuario)."</b> creado correctamente. Se le pedirá cambiar la contraseña al ingresar.$extraTemp</div>";
      }

      // ===== ACCIONES SOBRE USUARIOS EXISTENTES =====
      else {

        // Lectura Subdis NO puede ejecutar acciones
        if ($permLecturaSubdis) {
          throw new Exception("No tienes permisos para ejecutar acciones sobre usuarios.");
        }

        $usuario_id = (int)($_POST['usuario_id'] ?? 0);
        if ($usuario_id <= 0) throw new Exception("Usuario inválido.");

        // Cargar target
        $stmt = $conn->prepare("SELECT id, nombre, rol, id_sucursal, activo, IFNULL(id_subdis,0) AS id_subdis FROM usuarios WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$target) throw new Exception("Usuario no encontrado.");

        // No operar sobre sí mismo (excepto cambiar rol si quieres, aquí lo bloqueamos para todo)
        if ((int)$target['id'] === $ID_USUARIO) {
          throw new Exception("No puedes operar sobre tu propia cuenta.");
        }

        // Candado por scope
        if (!puedeOperarTarget($permAdminLuga, $permAdminSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL, $target)) {
          throw new Exception("Permisos insuficientes para operar a este usuario (scope).");
        }

        // Acciones
        if ($accion === 'baja') {

          $stmt = $conn->prepare("UPDATE usuarios SET activo=0 WHERE id=?");
          $stmt->bind_param("i", $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'baja', "Baja de usuario");
          $conn->commit();
          $mensaje = "<div class='alert alert-success'>✅ Usuario dado de baja.</div>";

        } elseif ($accion === 'reactivar') {

          $stmt = $conn->prepare("UPDATE usuarios SET activo=1 WHERE id=?");
          $stmt->bind_param("i", $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'reactivar', "Reactivación de cuenta");
          $conn->commit();
          $mensaje = "<div class='alert alert-success'>✅ Usuario reactivado.</div>";

        } elseif ($accion === 'cambiar_rol') {

          // Solo el "admin del ámbito" puede cambiar rol:
          // - Admin Luga puede cambiar todo
          // - Subdis_Admin solo usuarios de su subdis
          if (!$permAdminLuga && !$permAdminSubdis) throw new Exception("Solo un admin puede cambiar roles.");

          $nuevo_rol = trim($_POST['nuevo_rol'] ?? '');
          if ($nuevo_rol === '') throw new Exception("Selecciona un rol válido.");

          // Validar rol según ámbito del target
          $targetSub = (int)$target['id_subdis'];

          if ($targetSub === 0) {
            if (!in_array($nuevo_rol, $rolesLuga, true)) throw new Exception("Rol no válido para usuario PROPIO.");
          } else {
            if (!in_array($nuevo_rol, $rolesSubdis, true)) throw new Exception("Rol no válido para usuario SUBDIS.");
          }

          $stmt = $conn->prepare("UPDATE usuarios SET rol=? WHERE id=?");
          $stmt->bind_param("si", $nuevo_rol, $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'cambiar_rol', "Nuevo rol: $nuevo_rol (antes: {$target['rol']})");
          $conn->commit();
          $mensaje = "<div class='alert alert-success'>✅ Rol actualizado a <b>".h($nuevo_rol)."</b>.</div>";

        } elseif ($accion === 'cambiar_sucursal') {

          // Solo Admin del ámbito (Admin Luga o Subdis_Admin). Gerente NO cambia sucursal aquí para evitar cruces.
          if (!$permAdminLuga && !$permAdminSubdis) throw new Exception("Solo un admin puede cambiar sucursal.");

          $nueva_sucursal = (int)($_POST['nueva_sucursal'] ?? 0);
          if ($nueva_sucursal <= 0) throw new Exception("Selecciona una sucursal válida.");

          // Validar que sucursal exista
          $stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE id=? LIMIT 1");
          $stmt->bind_param("i", $nueva_sucursal);
          $stmt->execute();
          $sucRow = $stmt->get_result()->fetch_assoc();
          $stmt->close();
          if (!$sucRow) throw new Exception("La sucursal seleccionada no existe.");

          $stmt = $conn->prepare("UPDATE usuarios SET id_sucursal=? WHERE id=?");
          $stmt->bind_param("ii", $nueva_sucursal, $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'cambiar_sucursal', "Nueva sucursal: {$sucRow['nombre']} (ID $nueva_sucursal)");
          $conn->commit();
          $mensaje = "<div class='alert alert-success'>🏬 Sucursal actualizada.</div>";

        } elseif ($accion === 'reset_password') {

          // Admin del ámbito o Gerente Luga (solo ejecutivos de su sucursal)
          $ok = $permAdminLuga || $permAdminSubdis || $permGerenteLuga;
          if (!$ok) throw new Exception("Permisos insuficientes para resetear contraseña.");

          // Si es gerente Luga, reforzar regla
          if ($permGerenteLuga) {
            if (!(($target['rol'] ?? '')==='Ejecutivo' && (int)$target['id_sucursal']===$ID_SUCURSAL && (int)$target['id_subdis']===0)) {
              throw new Exception("Como Gerente solo puedes resetear ejecutivos de tu sucursal (PROPIO).");
            }
          }

          $temp = generarTemporal();
          $hash = password_hash($temp, PASSWORD_DEFAULT);

          $stmt = $conn->prepare("UPDATE usuarios SET password=?, must_change_password=1, last_password_reset_at=NOW() WHERE id=?");
          $stmt->bind_param("si", $hash, $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'reset_password', "Se generó contraseña temporal");
          $conn->commit();

          $mensaje = "<div class='alert alert-warning'>🔐 Contraseña temporal generada:
                      <code style='user-select:all'>".h($temp)."</code><br>
                      * Se le pedirá cambiarla al iniciar sesión.
                      </div>";
        } else {
          throw new Exception("Acción no válida.");
        }
      }

    } catch (Throwable $e) {
      $conn->rollback();
      $mensaje = "<div class='alert alert-danger'>❌ ".$e->getMessage()."</div>";
    }
  }
}

// =================== Listados (scopeados) ===================
$busq = trim($_GET['q'] ?? '');
$frol = trim($_GET['rol'] ?? '');
$fsuc = (int)($_GET['sucursal'] ?? 0);

function cargarUsuarios($conn, $activo, $busq, $frol, $fsuc, $extra, $params, $types) {
  $sql = "
    SELECT u.id, u.nombre, u.usuario, u.rol, u.id_sucursal, u.activo,
           IFNULL(u.id_subdis,0) AS id_subdis,
           s.nombre AS sucursal_nombre,
           sd.nombre_comercial AS subdis_nombre
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN subdistribuidores sd ON sd.id = u.id_subdis
    WHERE u.activo=? $extra
  ";
  $p = [$activo];
  $t = "i";

  if ($busq !== '') {
    $sql .= " AND (u.nombre LIKE CONCAT('%',?,'%') OR u.usuario LIKE CONCAT('%',?,'%'))";
    $p[] = $busq; $p[] = $busq; $t .= "ss";
  }
  if ($frol !== '') {
    $sql .= " AND u.rol=?";
    $p[] = $frol; $t .= "s";
  }
  if ($fsuc > 0) {
    $sql .= " AND u.id_sucursal=?";
    $p[] = $fsuc; $t .= "i";
  }

  // Scope params al final
  if ($types !== "") { $t .= $types; $p = array_merge($p, $params); }

  $sql .= " ORDER BY IFNULL(sd.nombre_comercial,''), s.nombre ASC, u.nombre ASC";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($t, ...$p);
  $stmt->execute();
  $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return $data;
}

[$scopeExtra, $scopeParams, $scopeTypes] = scopeWhereUsuarios($permAdminLuga, $permAdminSubdis, $permLecturaSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL);

$usuariosActivos   = cargarUsuarios($conn, 1, $busq, $frol, $fsuc, $scopeExtra, $scopeParams, $scopeTypes);
$usuariosInactivos = cargarUsuarios($conn, 0, $busq, $frol, $fsuc, $scopeExtra, $scopeParams, $scopeTypes);

// Logs (scopeados: si Subdis, solo logs donde target pertenece a su subdis)
$logLimit = 250;
if ($permAdminLuga) {
  $stmt = $conn->prepare("
    SELECT l.id, l.created_at, l.accion, l.detalles, l.ip,
           a.nombre AS actor_nombre,
           t.nombre AS target_nombre, t.usuario AS target_user
    FROM usuarios_log l
    LEFT JOIN usuarios a ON a.id = l.actor_id
    LEFT JOIN usuarios t ON t.id = l.target_id
    ORDER BY l.id DESC
    LIMIT ?
  ");
  $stmt->bind_param("i", $logLimit);
} else {
  $stmt = $conn->prepare("
    SELECT l.id, l.created_at, l.accion, l.detalles, l.ip,
           a.nombre AS actor_nombre,
           t.nombre AS target_nombre, t.usuario AS target_user
    FROM usuarios_log l
    LEFT JOIN usuarios a ON a.id = l.actor_id
    LEFT JOIN usuarios t ON t.id = l.target_id
    WHERE IFNULL(t.id_subdis,0)=?
    ORDER BY l.id DESC
    LIMIT ?
  ");
  $stmt->bind_param("ii", $MI_SUBDIS, $logLimit);
}
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Roles para filtros (según ámbito)
$rolesFiltro = $permAdminLuga ? array_merge($rolesLuga, $rolesSubdis) : $rolesSubdis;

// KPIs
$kpiActivos    = count($usuariosActivos);
$kpiInactivos  = count($usuariosInactivos);
$kpiSucursales = count($suc);
$kpiLogs       = count($logs);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Usuarios (Alta + Gestión)</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{ --brand:#0d6efd; --brand-100: rgba(13,110,253,.08); }
    body.bg-light{
      background:
        radial-gradient(1100px 420px at 110% -80%, var(--brand-100), transparent),
        radial-gradient(1100px 420px at -10% 120%, rgba(25,135,84,.06), transparent),
        #f8fafc;
    }
    .page-title{
      border:0; border-radius:1rem;
      background: linear-gradient(135deg, #22c55e 0%, #0ea5e9 55%, #6366f1 100%);
      color:#fff; padding:1rem 1.25rem;
      box-shadow: 0 20px 45px rgba(2,8,20,.12), 0 3px 10px rgba(2,8,20,.06);
    }
    .card-elev{ border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(2,8,20,.06), 0 2px 8px rgba(2,8,20,.05); }
    .kpi-card{ border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(2,8,20,.06), 0 2px 8px rgba(2,8,20,.05); }
    .kpi-icon{ width:42px; height:42px; display:inline-grid; place-items:center; border-radius:12px; background:#eef2ff; color:#1e40af; }
    .badge-role{
      background:#e9eefb;
      color:#111 !important;
      border:1px solid #cbd5e1;
      font-weight:600;
      padding:.35rem .6rem;
    }
    .table-sm td, .table-sm th{vertical-align: middle;}
  </style>
</head>
<body class="bg-light">

<?php if (file_exists(__DIR__.'/navbar.php')) include __DIR__.'/navbar.php'; ?>

<div class="container my-4">

  <div class="page-title mb-3 d-flex flex-wrap justify-content-between align-items-end">
    <div>
      <h2 class="mb-1">👥 Usuarios</h2>
      <div class="opacity-75">
        Alta + Gestión (unificado). Tu rol: <b><?= h($ROL) ?></b>
        <?php if ($MI_SUBDIS>0): ?>
          <span class="ms-2 badge bg-dark">SUBDIS #<?= (int)$MI_SUBDIS ?></span>
        <?php else: ?>
          <span class="ms-2 badge bg-dark">LUGA</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-success" href="?export=activos_csv">
        <i class="bi bi-download me-1"></i> Exportar activos (CSV)
      </a>
    </div>
  </div>

  <?= $mensaje ?>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="kpi-card p-3 bg-white h-100">
        <div class="d-flex align-items-center gap-3">
          <div class="kpi-icon"><i class="bi bi-people"></i></div>
          <div><div class="text-muted small">Activos</div><div class="h5 mb-0"><?= $kpiActivos ?></div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi-card p-3 bg-white h-100">
        <div class="d-flex align-items-center gap-3">
          <div class="kpi-icon" style="background:#fff7ed; color:#9a3412;"><i class="bi bi-person-dash"></i></div>
          <div><div class="text-muted small">Inactivos</div><div class="h5 mb-0"><?= $kpiInactivos ?></div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi-card p-3 bg-white h-100">
        <div class="d-flex align-items-center gap-3">
          <div class="kpi-icon" style="background:#ecfeff; color:#155e75;"><i class="bi bi-shop"></i></div>
          <div><div class="text-muted small">Sucursales</div><div class="h5 mb-0"><?= $kpiSucursales ?></div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi-card p-3 bg-white h-100">
        <div class="d-flex align-items-center gap-3">
          <div class="kpi-icon" style="background:#f0fdf4; color:#166534;"><i class="bi bi-activity"></i></div>
          <div><div class="text-muted small">Movimientos</div><div class="h5 mb-0"><?= $kpiLogs ?></div></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs card-elev bg-white px-3 pt-3" role="tablist" style="border-bottom:0;">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-alta" type="button" role="tab">
        ➕ Alta de usuario
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-activos" type="button" role="tab">
        Activos (<?= $kpiActivos ?>)
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-inactivos" type="button" role="tab">
        Inactivos (<?= $kpiInactivos ?>)
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-bitacora" type="button" role="tab">
        Bitácora
      </button>
    </li>
  </ul>

  <div class="tab-content card-elev bg-white mt-2">

    <!-- =================== ALTA =================== -->
    <div class="tab-pane fade show active p-3" id="tab-alta" role="tabpanel">
      <?php if (!$permAdminLuga && !$permAdminSubdis): ?>
        <div class="alert alert-warning mb-0">⚠️ Solo un admin puede crear usuarios.</div>
      <?php else: ?>
        <form method="post" class="row g-3">
          <input type="hidden" name="accion" value="crear_usuario">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

          <div class="col-md-6">
            <label class="form-label">Nombre completo</label>
            <input type="text" name="nombre" class="form-control" required>
          </div>

          <div class="col-md-3">
            <label class="form-label">Usuario</label>
            <input type="text" name="usuario" class="form-control" required placeholder="ej. e.fernandez">
            <div class="form-text">3–32, letras/números/punto/guion/guion bajo.</div>
          </div>

          <div class="col-md-3">
            <label class="form-label">Contraseña</label>
            <input type="text" name="password" class="form-control" placeholder="(vacío = generar temporal)">
            <div class="form-text">Si la dejas vacía, se genera una temporal.</div>
          </div>

          <div class="col-md-3">
            <label class="form-label">Sucursal</label>
            <select name="id_sucursal" class="form-select" required>
              <option value="">-- Selecciona --</option>
              <?php foreach ($suc as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Sueldo (MXN)</label>
            <input type="number" step="0.01" min="0" name="sueldo" class="form-control" required>
          </div>

          <?php if ($permAdminLuga): ?>
            <div class="col-md-3">
              <label class="form-label">Tipo de cuenta</label>
              <select name="tipo_cuenta" id="tipo_cuenta" class="form-select" required>
                <option value="">-- Selecciona --</option>
                <option value="PROPIO">PROPIO (Luga)</option>
                <option value="SUBDIS">SUBDIS</option>
              </select>
            </div>

            <div class="col-md-3" id="wrap_subdis" style="display:none;">
              <label class="form-label">Subdistribuidor</label>
              <select name="id_subdis" class="form-select">
                <option value="">-- Selecciona --</option>
                <?php foreach ($subdisList as $sd): ?>
                  <option value="<?= (int)$sd['id'] ?>"><?= h($sd['nombre_comercial']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Rol</label>
              <select name="rol" id="rol_select" class="form-select" required>
                <option value="">-- Selecciona --</option>
                <?php foreach (array_merge($rolesLuga,$rolesSubdis) as $r): ?>
                  <option value="<?= h($r) ?>"><?= h($r) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">El rol se valida según PROPIO/SUBDIS.</div>
            </div>
          <?php else: ?>
            <input type="hidden" name="tipo_cuenta" value="SUBDIS">
            <input type="hidden" name="id_subdis" value="<?= (int)$MI_SUBDIS ?>">
            <div class="col-md-6">
              <label class="form-label">Rol (SUBDIS)</label>
              <select name="rol" class="form-select" required>
                <option value="">-- Selecciona --</option>
                <?php foreach ($rolesSubdis as $r): ?>
                  <option value="<?= h($r) ?>"><?= h($r) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Se asigna automático al SUBDIS #<?= (int)$MI_SUBDIS ?>.</div>
            </div>
          <?php endif; ?>

          <div class="col-12 d-grid d-md-flex justify-content-end">
            <button class="btn btn-primary">
              <i class="bi bi-person-plus me-1"></i> Crear usuario
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <!-- =================== ACTIVOS =================== -->
    <div class="tab-pane fade p-3" id="tab-activos" role="tabpanel">
      <form class="row g-2 mb-3" method="get">
        <div class="col-md-4">
          <input class="form-control" type="text" name="q" placeholder="Buscar nombre o usuario" value="<?=h($busq)?>">
        </div>
        <div class="col-md-3">
          <select name="rol" class="form-select">
            <option value="">Todos los roles</option>
            <?php foreach ($rolesFiltro as $r): ?>
              <option value="<?=h($r)?>" <?=($frol===$r?'selected':'')?>><?=h($r)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <select name="sucursal" class="form-select">
            <option value="0">Todas las sucursales</option>
            <?php foreach ($suc as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?=($fsuc==(int)$s['id']?'selected':'')?>>
                <?=h($s['nombre'])?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-grid">
          <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Sucursal</th><th>Subdis</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$usuariosActivos): ?>
              <tr><td colspan="7" class="text-center py-4 text-muted">Sin usuarios activos.</td></tr>
            <?php else: foreach ($usuariosActivos as $u):
              $puedeOperar = puedeOperarTarget($permAdminLuga, $permAdminSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL, $u);
              $soloLectura = $permLecturaSubdis;
              $bloq = (!$puedeOperar || $soloLectura);

              // Botones por acción
              $btnBaja   = $bloq;
              $btnRol    = !($permAdminLuga || $permAdminSubdis) || !$puedeOperar;
              $btnSuc    = !($permAdminLuga || $permAdminSubdis) || !$puedeOperar;
              $btnReset  = (!$puedeOperar || $soloLectura) ? true : false;
            ?>
              <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= h($u['nombre']) ?></td>
                <td><?= h($u['usuario']) ?></td>
                <td><span class="badge badge-role rounded-pill"><?= h($u['rol']) ?></span></td>
                <td><?= h($u['sucursal_nombre'] ?? '-') ?></td>
                <td><?= h(($u['id_subdis'] ?? 0) ? ($u['subdis_nombre'] ?? ('SUBDIS #'.$u['id_subdis'])) : '—') ?></td>
                <td class="text-end">
                  <button class="btn btn-outline-danger btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalBaja"
                          data-id="<?=$u['id']?>" data-nombre="<?=h($u['nombre'])?>"
                          <?= $btnBaja ? 'disabled' : '' ?>>
                    <i class="bi bi-person-x me-1"></i> Baja
                  </button>

                  <button class="btn btn-outline-secondary btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalRol"
                          data-id="<?=$u['id']?>" data-nombre="<?=h($u['nombre'])?>"
                          data-rol="<?=$u['rol']?>"
                          data-id-subdis="<?= (int)$u['id_subdis'] ?>"
                          <?= $btnRol ? 'disabled' : '' ?>>
                    <i class="bi bi-person-gear me-1"></i> Rol
                  </button>

                  <button class="btn btn-outline-primary btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalSucursal"
                          data-id="<?=$u['id']?>" data-nombre="<?=h($u['nombre'])?>"
                          data-sucursal-id="<?= (int)$u['id_sucursal'] ?>"
                          <?= $btnSuc ? 'disabled' : '' ?>>
                    <i class="bi bi-shop-window me-1"></i> Sucursal
                  </button>

                  <button class="btn btn-outline-warning btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalResetPass"
                          data-id="<?=$u['id']?>" data-nombre="<?=h($u['nombre'])?>"
                          <?= $btnReset ? 'disabled' : '' ?>>
                    <i class="bi bi-key me-1"></i> Reset
                  </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- =================== INACTIVOS =================== -->
    <div class="tab-pane fade p-3" id="tab-inactivos" role="tabpanel">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Sucursal</th><th>Subdis</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$usuariosInactivos): ?>
              <tr><td colspan="7" class="text-center py-4 text-muted">Sin usuarios inactivos.</td></tr>
            <?php else: foreach ($usuariosInactivos as $u):
              $puedeOperar = puedeOperarTarget($permAdminLuga, $permAdminSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL, $u);
              $soloLectura = $permLecturaSubdis;
              $btnReact = (!$puedeOperar || $soloLectura);
              $btnReset = (!$puedeOperar || $soloLectura);
            ?>
              <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= h($u['nombre']) ?></td>
                <td><?= h($u['usuario']) ?></td>
                <td><span class="badge badge-role rounded-pill"><?= h($u['rol']) ?></span></td>
                <td><?= h($u['sucursal_nombre'] ?? '-') ?></td>
                <td><?= h(($u['id_subdis'] ?? 0) ? ($u['subdis_nombre'] ?? ('SUBDIS #'.$u['id_subdis'])) : '—') ?></td>
                <td class="text-end">
                  <button class="btn btn-outline-success btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalReactivar"
                          data-id="<?=$u['id']?>" data-nombre="<?=h($u['nombre'])?>"
                          <?= $btnReact ? 'disabled' : '' ?>>
                    <i class="bi bi-person-check me-1"></i> Reactivar
                  </button>

                  <button class="btn btn-outline-warning btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalResetPass"
                          data-id="<?=$u['id']?>" data-nombre="<?=h($u['nombre'])?>"
                          <?= $btnReset ? 'disabled' : '' ?>>
                    <i class="bi bi-key me-1"></i> Reset
                  </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- =================== BITÁCORA =================== -->
    <div class="tab-pane fade p-3" id="tab-bitacora" role="tabpanel">
      <h6 class="mb-2">Últimos <?= (int)$logLimit ?> movimientos</h6>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Fecha</th><th>Acción</th><th>Actor</th><th>Usuario afectado</th><th>Detalles</th><th>IP</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($logs)): ?>
              <tr><td colspan="7" class="text-center py-3 text-muted">Sin registros.</td></tr>
            <?php else: foreach ($logs as $l): ?>
              <tr>
                <td><?= (int)$l['id'] ?></td>
                <td><?= h($l['created_at']) ?></td>
                <td><span class="badge bg-secondary"><?= h($l['accion']) ?></span></td>
                <td><?= h($l['actor_nombre'] ?: '-') ?></td>
                <td><?= h(($l['target_nombre'] ?: '-') . ' (' . ($l['target_user'] ?: '-') . ')') ?></td>
                <td><?= h($l['detalles'] ?: '-') ?></td>
                <td><?= h($l['ip'] ?: '-') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- =================== MODALES =================== -->
<div class="modal fade" id="modalBaja" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Dar de baja usuario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="baja">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="usuario_id" id="baja_usuario_id">
        <label class="form-label">Usuario</label>
        <input type="text" id="baja_usuario_nombre" class="form-control" readonly>
        <div class="alert alert-warning mt-3 mb-0">Esta acción desactiva el acceso inmediatamente.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger" type="submit">Confirmar baja</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalReactivar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Reactivar usuario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="reactivar">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="usuario_id" id="react_usuario_id">
        <label class="form-label">Usuario</label>
        <input type="text" id="react_usuario_nombre" class="form-control" readonly>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success" type="submit">Reactivar</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalRol" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Cambiar rol</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="cambiar_rol">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="usuario_id" id="rol_usuario_id">
        <label class="form-label">Usuario</label>
        <input type="text" id="rol_usuario_nombre" class="form-control" readonly>

        <div class="mt-2">
          <label class="form-label">Rol actual</label>
          <input type="text" id="rol_actual" class="form-control" readonly>
        </div>

        <div class="mt-2">
          <label class="form-label">Nuevo rol</label>
          <select name="nuevo_rol" id="rol_nuevo_select" class="form-select" required></select>
          <div class="form-text">Se listan roles según si el usuario es PROPIO o SUBDIS.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar cambio</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalSucursal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Cambiar sucursal</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="cambiar_sucursal">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="usuario_id" id="suc_usuario_id">
        <label class="form-label">Usuario</label>
        <input type="text" id="suc_usuario_nombre" class="form-control" readonly>

        <div class="mt-2">
          <label class="form-label">Nueva sucursal</label>
          <select name="nueva_sucursal" id="suc_select_nueva" class="form-select" required>
            <option value="">Selecciona sucursal…</option>
            <?php foreach ($suc as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalResetPass" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Resetear contraseña</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="reset_password">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="usuario_id" id="reset_usuario_id">
        <label class="form-label">Usuario</label>
        <input type="text" id="reset_usuario_nombre" class="form-control" readonly>
        <div class="alert alert-info mt-3 mb-0">
          Se generará una contraseña temporal y se forzará cambio al iniciar sesión.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-warning" type="submit">Generar temporal</button>
      </div>
    </form>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
  // Alta: mostrar selector de subdis solo si tipo_cuenta=SUBDIS (Admin Luga)
  const tipo = document.getElementById('tipo_cuenta');
  const wrap = document.getElementById('wrap_subdis');
  if (tipo && wrap){
    tipo.addEventListener('change', () => {
      wrap.style.display = (tipo.value === 'SUBDIS') ? '' : 'none';
    });
  }

  // Modal Baja
  const modalBaja = document.getElementById('modalBaja');
  if (modalBaja) {
    modalBaja.addEventListener('show.bs.modal', e => {
      const b = e.relatedTarget;
      document.getElementById('baja_usuario_id').value = b.getAttribute('data-id');
      document.getElementById('baja_usuario_nombre').value = b.getAttribute('data-nombre');
    });
  }

  // Modal Reactivar
  const modalReactivar = document.getElementById('modalReactivar');
  if (modalReactivar) {
    modalReactivar.addEventListener('show.bs.modal', e => {
      const b = e.relatedTarget;
      document.getElementById('react_usuario_id').value = b.getAttribute('data-id');
      document.getElementById('react_usuario_nombre').value = b.getAttribute('data-nombre');
    });
  }

  // Modal Rol: poblar roles según si target es PROPIO o SUBDIS
  const rolesLuga = <?= json_encode($rolesLuga, JSON_UNESCAPED_UNICODE) ?>;
  const rolesSub  = <?= json_encode($rolesSubdis, JSON_UNESCAPED_UNICODE) ?>;

  const modalRol = document.getElementById('modalRol');
  if (modalRol) {
    modalRol.addEventListener('show.bs.modal', e => {
      const b = e.relatedTarget;
      const id = b.getAttribute('data-id');
      const nombre = b.getAttribute('data-nombre');
      const rol = b.getAttribute('data-rol');
      const idSubdis = parseInt(b.getAttribute('data-id-subdis') || '0', 10);

      document.getElementById('rol_usuario_id').value = id;
      document.getElementById('rol_usuario_nombre').value = nombre;
      document.getElementById('rol_actual').value = rol;

      const sel = document.getElementById('rol_nuevo_select');
      sel.innerHTML = '';
      const lista = (idSubdis > 0) ? rolesSub : rolesLuga;

      lista.forEach(r => {
        const opt = document.createElement('option');
        opt.value = r;
        opt.textContent = r;
        if (r === rol) opt.selected = true;
        sel.appendChild(opt);
      });
    });
  }

  // Modal Sucursal
  const modalSuc = document.getElementById('modalSucursal');
  if (modalSuc) {
    modalSuc.addEventListener('show.bs.modal', e => {
      const b = e.relatedTarget;
      document.getElementById('suc_usuario_id').value = b.getAttribute('data-id');
      document.getElementById('suc_usuario_nombre').value = b.getAttribute('data-nombre');

      const actualId = parseInt(b.getAttribute('data-sucursal-id') || '0', 10);
      const sel = document.getElementById('suc_select_nueva');
      if (sel && sel.options && sel.options.length > 0) {
        for (let i=0; i<sel.options.length; i++){
          if (parseInt(sel.options[i].value,10) === actualId) { sel.selectedIndex = i; break; }
        }
      }
    });
  }

  // Modal Reset
  const modalResetPass = document.getElementById('modalResetPass');
  if (modalResetPass) {
    modalResetPass.addEventListener('show.bs.modal', e => {
      const b = e.relatedTarget;
      document.getElementById('reset_usuario_id').value = b.getAttribute('data-id');
      document.getElementById('reset_usuario_nombre').value = b.getAttribute('data-nombre');
    });
  }
</script>
</body>
</html>
