<?php
session_start();
include 'db.php';

$mensaje      = '';
$showWelcome  = false;
$fotoUsuario  = '';
$fotoUrl      = '';
$nombreSesion = '';
$saludo       = '';

/* ================= Helpers ================= */

// Iniciales (fallback)
function iniciales($nombreCompleto) {
  $p = preg_split('/\s+/', trim((string)$nombreCompleto));
  $ini = '';
  foreach ($p as $w) {
    if ($w !== '') { $ini .= mb_substr($w, 0, 1, 'UTF-8'); }
    if (mb_strlen($ini, 'UTF-8') >= 2) break;
  }
  return mb_strtoupper($ini, 'UTF-8') ?: 'U';
}

// Base web absoluta de la app (soporta subcarpeta)
function appBaseWebAbs() {
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $script = $_SERVER['SCRIPT_NAME'] ?? '/';
  $base   = rtrim(str_replace('\\','/', dirname($script)), '/');
  $base   = ($base === '') ? '/' : $base . '/';
  return $scheme . '://' . $host . $base;
}

// Normaliza ruta de foto desde BD a URL absoluta servible
function normalizarFoto($rawPath) {
  $rawPath = trim((string)$rawPath);
  if ($rawPath === '') return '';
  if (preg_match('#^https?://#i', $rawPath)) return $rawPath;

  $path   = str_replace('\\', '/', $rawPath);
  $baseAbs = appBaseWebAbs();

  $candidatos = [
    $path,
    "uploads/$path",
    "uploads/usuarios/$path",
    "uploads/fotos_usuarios/$path",
    "documentos/$path",
    "expediente/$path",
  ];
  foreach ($candidatos as $rel) {
    $abs = __DIR__ . '/' . $rel;
    if (file_exists($abs)) {
      $v = @filemtime($abs) ?: time();
      return $baseAbs . ltrim($rel, '/') . '?v=' . $v;
    }
  }
  return $baseAbs . ltrim($path, '/');
}

// Detecta la columna que relaciona usuarios_expediente con usuarios.id
function detectarColumnaExpediente($conn) {
  $candidatas = ['id_usuario','usuario_id','user_id','idUser','id_empleado','id_usuario_fk'];
  $cols = [];
  if ($res = $conn->query("SHOW COLUMNS FROM usuarios_expediente")) {
    while ($row = $res->fetch_assoc()) $cols[] = $row['Field'];
    $res->close();
  }
  foreach ($candidatas as $c) {
    if (in_array($c, $cols, true)) return $c;
  }
  return null;
}

// Obtiene la foto del usuario desde usuarios_expediente
function obtenerFotoUsuario($conn, $idUsuario) {
  $col = detectarColumnaExpediente($conn);
  if ($col) {
    $sql = "SELECT foto FROM usuarios_expediente WHERE $col = ? ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $idUsuario);
    $stmt->execute();
    $stmt->bind_result($foto);
    if ($stmt->fetch()) {
      $stmt->close();
      return trim((string)$foto);
    }
    $stmt->close();
  } else {
    $sql = "SELECT foto FROM usuarios_expediente WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $idUsuario);
    if ($stmt->execute()) {
      $stmt->bind_result($foto2);
      if ($stmt->fetch()) {
        $stmt->close();
        return trim((string)$foto2);
      }
    }
    $stmt->close();
  }
  return '';
}

/* ============== Lógica de login ============== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $usuario  = $_POST['usuario'] ?? '';
  $password = $_POST['password'] ?? '';

  $sql  = "SELECT id, nombre, id_sucursal, rol, password, activo, must_change_password 
           FROM usuarios WHERE usuario = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $usuario);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($row = $result->fetch_assoc()) {
    if ((int)$row['activo'] !== 1) {
      $mensaje = "⚠️ Tu cuenta ha sido dada de baja.";
    } else {
      $hashInfo = password_get_info($row['password']);
      $ok = !empty($hashInfo['algo']) ? password_verify($password, $row['password'])
                                      : hash_equals($row['password'], $password);

      if ($ok) {
        session_regenerate_id(true);
        $_SESSION['id_usuario']  = (int)$row['id'];
        $_SESSION['nombre']      = $row['nombre'];
        $_SESSION['id_sucursal'] = (int)$row['id_sucursal'];
        $_SESSION['rol']         = $row['rol'];
        $_SESSION['must_change_password'] = (int)$row['must_change_password'] === 1;

        if (!empty($_SESSION['must_change_password'])) {
          header("Location: cambiar_password.php?force=1");
          exit();
        } else {
          $fotoUsuario = obtenerFotoUsuario($conn, $_SESSION['id_usuario']);
          $fotoUrl     = normalizarFoto($fotoUsuario);

          $dt = new DateTime('now', new DateTimeZone('America/Mexico_City'));
          $h  = (int)$dt->format('G');
          if     ($h < 12) $saludo = "Buenos días";
          elseif ($h < 19) $saludo = "Buenas tardes";
          else             $saludo = "Buenas noches";

          $nombreSesion = $_SESSION['nombre'];
          $showWelcome  = true;
        }
      } else {
        $mensaje = "❌ Contraseña incorrecta";
      }
    }
  } else {
    $mensaje = "❌ Usuario no encontrado";
  }
}

$inits = iniciales($nombreSesion ?: 'Usuario');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Login - Central Luga 2.0</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- Favicon -->
<link rel="icon" type="image/png" href="./img/favicon.ico">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{ --brand:#1e90ff; --brand-600:#1877cf; }
  html,body{height:100%}
  body{
    margin:0;
    background: linear-gradient(-45deg,#0f2027,#203a43,#2c5364,#1c2b33);
    background-size: 400% 400%;
    animation: bgshift 15s ease infinite;
    display:flex; align-items:center; justify-content:center;
  }
  @keyframes bgshift{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
  .login-card{
    width:min(420px,92vw);
    background:#fff; color:#333;
    border-radius:16px;
    box-shadow:0 12px 28px rgba(0,0,0,.18);
    padding:26px 22px;
  }
  .brand-logo{display:block; margin:0 auto 12px; width:88px}
  .title{ text-align:center; font-weight:800; font-size:1.6rem; margin-bottom:.25rem }
  .subtitle{ text-align:center; color:#56606a; margin-bottom:1.1rem }
  .pwd-field{position:relative}
  .pwd-field .form-control{padding-right:2.8rem}
  .btn-eye{
    position:absolute; inset:0 .6rem 0 auto;
    display:flex; align-items:center; justify-content:center;
    width:34px; background:transparent; border:0; color:#6c757d; cursor:pointer;
  }
  .btn-eye svg{width:20px;height:20px}
  .btn-brand{background:var(--brand); border:none; font-weight:700}
  .btn-brand:hover{background:var(--brand-600)}
  .welcome-avatar{
    width:96px; height:96px; border-radius:50%; overflow:hidden;
    margin:0 auto 8px; border:1px solid rgba(0,0,0,.08);
    background:#eef5ff; display:flex; align-items:center; justify-content:center;
  }
  .welcome-avatar img{width:100%; height:100%; object-fit:cover}
  .welcome-inits{font-weight:800; font-size:34px; color:#2b3d59}
  .progress{ height:8px; }
</style>
</head>
<body>

<div class="login-card" id="card">
  <img class="brand-logo" src="https://i.ibb.co/Jwgbnjdv/Captura-de-pantalla-2025-05-29-230425.png" alt="Logo Luga">
  <div class="title">Central Luga <span style="color:var(--brand)">2.0</span></div>
  <div class="subtitle" id="welcomeMsg">Bienvenido</div>

  <?php if ($mensaje): ?>
    <div class="alert alert-danger text-center"><?= htmlspecialchars($mensaje) ?></div>
  <?php endif; ?>

  <form id="loginForm" method="POST" novalidate>
    <div class="mb-3">
      <label class="form-label">Usuario</label>
      <input type="text" name="usuario" class="form-control" autocomplete="username" required autofocus>
    </div>
    <div class="mb-3">
      <label class="form-label">Contraseña</label>
      <div class="pwd-field">
        <input type="password" name="password" id="password" class="form-control" autocomplete="current-password" required>
        <button type="button" class="btn-eye" id="togglePwd" aria-label="Mostrar/ocultar contraseña">
          <svg viewBox="0 0 24 24" fill="none">
            <path d="M12 5C7 5 2.73 8.11 1 12c1.73 3.89 6 7 11 7s9.27-3.11 11-7c-1.73-3.89-6-7-11-7Z" stroke="currentColor"/>
            <circle cx="12" cy="12" r="3" stroke="currentColor"/>
          </svg>
        </button>
      </div>
    </div>
    <button class="btn btn-brand w-100 btn-lg" id="submitBtn">Ingresar</button>
  </form>
</div>

<!-- Modal Bienvenida -->
<div class="modal fade" id="welcomeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-body text-center p-4">
        <div class="welcome-avatar mb-2">
          <?php if (!empty($fotoUrl)): ?>
            <img src="<?= htmlspecialchars($fotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Foto de perfil">
          <?php else: ?>
            <div class="welcome-inits"><?= htmlspecialchars($inits, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
        </div>
        <h5 class="fw-bold mb-1"><?= htmlspecialchars($saludo) ?>, <?= htmlspecialchars($nombreSesion) ?>.</h5>
        <div class="text-muted mb-3">Bienvenido de nuevo 👋</div>
        <div class="progress mb-1">
          <div class="progress-bar" role="progressbar" style="width:0%" id="pb"></div>
        </div>
        <small class="text-muted">Entrando a tu panel…</small>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Saludo del login
(function(){
  const h=new Date().getHours();
  document.getElementById('welcomeMsg').textContent =
    h<12?"Buenos días, ingresa tus credenciales para continuar."
      :h<19?"Buenas tardes, ingresa tus credenciales para continuar."
            :"Buenas noches, ingresa tus credenciales para continuar.";
})();

// Toggle contraseña
(function(){
  const pwd=document.getElementById('password');
  const btn=document.getElementById('togglePwd');
  btn.addEventListener('click',()=>{pwd.type = pwd.type==="text" ? "password" : "text";});
})();

// Mostrar modal y redirigir si $showWelcome
<?php if ($showWelcome): ?>
(function(){
  const modal=new bootstrap.Modal(document.getElementById('welcomeModal'),{backdrop:'static',keyboard:false});
  modal.show();
  const pb=document.getElementById('pb'); const total=1500; const t0=performance.now();
  function tick(now){ const p=Math.min(1,(now-t0)/total); pb.style.width=(p*100)+'%'; if(p<1) requestAnimationFrame(tick); }
  requestAnimationFrame(tick);
  setTimeout(()=>{ window.location.href='dashboard_unificado.php'; }, total+120);
})();
<?php endif; ?>
</script>
</body>
</html>
