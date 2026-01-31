<?php
// tarea_nueva.php — Crear nueva tarea (Central) [FIX pantalla en blanco]
// - Debug: agrega ?debug=1 a la URL para mostrar errores.
// Requiere: db.php, navbar.php, tablas: areas, tareas, tarea_usuarios, tarea_dependencias, usuarios

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

// Debug opcional
$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($DEBUG) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

// Conexión y navbar
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');

// MySQLi strict (muy útil para detectar el error exacto)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function trimS($s){ return trim((string)$s); }

function toMysqlDT($input){
  $input = trimS($input);
  if ($input === '') return null;

  // datetime-local: 2026-01-30T14:30
  $input = str_replace('T',' ', $input);

  // si no trae segundos, agrega :00
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $input)) $input .= ':00';

  if (!preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $input)) return null;
  return $input;
}

function uniqInts($arr){
  $out = [];
  foreach((array)$arr as $v){
    $n = (int)$v;
    if ($n > 0) $out[$n] = true;
  }
  return array_keys($out);
}

// ================== CSRF ==================
if (empty($_SESSION['csrf_tareas'])) $_SESSION['csrf_tareas'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_tareas'];

// ================== Catálogos ==================
$areas = [];
$resA = $conn->query("SELECT id, nombre FROM areas WHERE activa=1 ORDER BY nombre");
while($row = $resA->fetch_assoc()) $areas[] = $row;

$usuarios = [];
$resU = $conn->query("SELECT id, nombre, rol, id_sucursal FROM usuarios WHERE activa=1 ORDER BY nombre");
while($row = $resU->fetch_assoc()) $usuarios[] = $row;

// Dependencias: tareas recientes (no terminadas/canceladas)
$deps = [];
$resD = $conn->query("
  SELECT t.id, t.titulo, a.nombre AS area_nombre, t.estatus, t.fecha_fin_compromiso
  FROM tareas t
  JOIN areas a ON a.id=t.id_area
  WHERE t.estatus <> 'Terminada' AND t.estatus <> 'Cancelada'
  ORDER BY t.id DESC
  LIMIT 250
");
while($row = $resD->fetch_assoc()) $deps[] = $row;

// ================== Valores por defecto ==================
$val = [
  'titulo' => '',
  'descripcion' => '',
  'id_area' => 0,
  'prioridad' => 'Media',
  'fecha_inicio_planeada' => '',
  'fecha_fin_compromiso' => '',
  'responsables' => [],
  'colaboradores' => [],
  'observadores' => [],
  'aprobadores' => [],
  'dependencias' => [],
];

$mensaje = '';
$tipoMsg = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrfPost = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($CSRF, $csrfPost)) {
    $mensaje = "Sesión inválida (CSRF). Recarga la página e inténtalo de nuevo.";
    $tipoMsg = "danger";
  } else {
    $val['titulo'] = trimS($_POST['titulo'] ?? '');
    $val['descripcion'] = trimS($_POST['descripcion'] ?? '');
    $val['id_area'] = (int)($_POST['id_area'] ?? 0);
    $val['prioridad'] = trimS($_POST['prioridad'] ?? 'Media');
    $val['fecha_inicio_planeada'] = (string)($_POST['fecha_inicio_planeada'] ?? '');
    $val['fecha_fin_compromiso']  = (string)($_POST['fecha_fin_compromiso'] ?? '');

    $val['responsables']  = uniqInts($_POST['responsables'] ?? []);
    $val['colaboradores'] = uniqInts($_POST['colaboradores'] ?? []);
    $val['observadores']  = uniqInts($_POST['observadores'] ?? []);
    $val['aprobadores']   = uniqInts($_POST['aprobadores'] ?? []);
    $val['dependencias']  = uniqInts($_POST['dependencias'] ?? []);

    $errs = [];
    if ($val['titulo'] === '') $errs[] = "El título es obligatorio.";
    if ($val['id_area'] <= 0) $errs[] = "Selecciona un área.";
    if (!in_array($val['prioridad'], ['Baja','Media','Alta'], true)) $errs[] = "Prioridad inválida.";
    if (count($val['responsables']) < 1) $errs[] = "Debes asignar al menos 1 responsable.";

    $inicioPlan = toMysqlDT($val['fecha_inicio_planeada']);
    $finComp = toMysqlDT($val['fecha_fin_compromiso']);

    if (!$finComp) $errs[] = "La fecha de compromiso (fin) es obligatoria y debe ser válida.";
    if ($inicioPlan && $finComp && strtotime($inicioPlan) > strtotime($finComp)) {
      $errs[] = "La fecha de inicio planeada no puede ser mayor a la fecha de compromiso.";
    }

    if ($errs) {
      $mensaje = implode("<br>", array_map('h', $errs));
      $tipoMsg = "danger";
    } else {

      $conn->begin_transaction();

      try {
        $desc = ($val['descripcion'] !== '') ? $val['descripcion'] : null;

        // Insert principal: si NO hay inicioPlan, metemos NULL directo para evitar broncas
        if ($inicioPlan === null) {
          $stmt = $conn->prepare("
            INSERT INTO tareas (titulo, descripcion, id_area, prioridad, estatus, fecha_inicio_planeada, fecha_fin_compromiso, creado_por)
            VALUES (?, ?, ?, ?, 'Nueva', NULL, ?, ?)
          ");
          // titulo(s), desc(s nullable), id_area(i), prioridad(s), finComp(s), creado_por(i)
          $stmt->bind_param("ssissi", $val['titulo'], $desc, $val['id_area'], $val['prioridad'], $finComp, $ID_USUARIO);
        } else {
          $stmt = $conn->prepare("
            INSERT INTO tareas (titulo, descripcion, id_area, prioridad, estatus, fecha_inicio_planeada, fecha_fin_compromiso, creado_por)
            VALUES (?, ?, ?, ?, 'Nueva', ?, ?, ?)
          ");
          // titulo(s), desc(s nullable), id_area(i), prioridad(s), inicio(s), fin(s), creado_por(i)
          $stmt->bind_param("ssisssi", $val['titulo'], $desc, $val['id_area'], $val['prioridad'], $inicioPlan, $finComp, $ID_USUARIO);
        }

        $stmt->execute();
        $idTarea = (int)$conn->insert_id;
        $stmt->close();

        // Usuarios asignados
        $insTU = $conn->prepare("INSERT IGNORE INTO tarea_usuarios (id_tarea, id_usuario, rol_en_tarea) VALUES (?, ?, ?)");

        $pushUsers = function(array $ids, string $rol) use ($insTU, $idTarea){
          foreach($ids as $idU){
            $idUser = (int)$idU;
            $rolLocal = $rol;
            $insTU->bind_param("iis", $idTarea, $idUser, $rolLocal);
            $insTU->execute();
          }
        };

        $pushUsers($val['responsables'],  'responsable');
        $pushUsers($val['colaboradores'], 'colaborador');
        $pushUsers($val['observadores'],  'observador');
        $pushUsers($val['aprobadores'],   'aprobador');

        $insTU->close();

        // Dependencias
        if (!empty($val['dependencias'])) {
          $insTD = $conn->prepare("INSERT IGNORE INTO tarea_dependencias (id_tarea, depende_de) VALUES (?, ?)");
          foreach($val['dependencias'] as $depId){
            $depId = (int)$depId;
            if ($depId > 0 && $depId !== $idTarea){
              $insTD->bind_param("ii", $idTarea, $depId);
              $insTD->execute();
            }
          }
          $insTD->close();
        }

        $conn->commit();

        header("Location: tarea_ver.php?id=".$idTarea."&created=1");
        exit();

      } catch (Throwable $e) {
        $conn->rollback();
        $mensaje = "No se pudo guardar la tarea. " . ($DEBUG ? ("Error: ".h($e->getMessage())) : "Activa debug=1 para ver el detalle.");
        $tipoMsg = "danger";
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nueva tarea</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .cardx{ border:1px solid rgba(0,0,0,.08); border-radius:16px; }
    .soft{ color:#6c757d; }
    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .hint{ font-size:.85rem; color:#6c757d; }
  </style>
</head>
<body class="bg-light">
<div class="container py-3">

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
      <h4 class="mb-0">Nueva tarea</h4>
      <div class="hint">Crea un pendiente con responsables, observadores y dependencias.</div>
      <?php if ($DEBUG): ?>
        <div class="hint mt-1 text-danger">DEBUG activo: mostrando errores PHP/MySQLi.</div>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="tablero_tareas.php">← Volver</a>
    </div>
  </div>

  <?php if ($mensaje): ?>
    <div class="alert alert-<?=$tipoMsg?>"><?=$mensaje?></div>
  <?php endif; ?>

  <form method="post" class="cardx bg-white p-3 shadow-sm">
    <input type="hidden" name="csrf" value="<?=h($CSRF)?>">

    <div class="row g-3">
      <div class="col-12 col-lg-8">
        <label class="form-label fw-semibold">Título *</label>
        <input class="form-control" name="titulo" value="<?=h($val['titulo'])?>" maxlength="160" required placeholder="Ej. Resurtido de tiendas (Semana 6)">
      </div>

      <div class="col-12 col-lg-4">
        <label class="form-label fw-semibold">Área *</label>
        <select class="form-select" name="id_area" required>
          <option value="0">Selecciona...</option>
          <?php foreach($areas as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $val['id_area']===(int)$a['id']?'selected':'' ?>>
              <?= h($a['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="hint mt-1">El área define seguimiento y jefes (cuando los configuremos).</div>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Descripción</label>
        <textarea class="form-control" rows="3" name="descripcion" placeholder="Detalles, contexto, qué se ocupa, etc."><?=h($val['descripcion'])?></textarea>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label fw-semibold">Prioridad</label>
        <select class="form-select" name="prioridad">
          <?php foreach(['Baja','Media','Alta'] as $p): ?>
            <option value="<?=h($p)?>" <?= $val['prioridad']===$p?'selected':'' ?>><?=h($p)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label fw-semibold">Inicio planeado</label>
        <input class="form-control" type="datetime-local" name="fecha_inicio_planeada" value="<?=h($val['fecha_inicio_planeada'])?>">
        <div class="hint mt-1">Opcional.</div>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label fw-semibold">Compromiso (fin) *</label>
        <input class="form-control" type="datetime-local" name="fecha_fin_compromiso" value="<?=h($val['fecha_fin_compromiso'])?>" required>
        <div class="hint mt-1">Para alertas por vencimiento.</div>
      </div>

      <hr class="my-1">

      <div class="col-12 col-lg-6">
        <label class="form-label fw-semibold">Responsables * <span class="soft">(quién lo ejecuta)</span></label>
        <select class="form-select" name="responsables[]" multiple size="8" required>
          <?php foreach($usuarios as $u): ?>
            <?php
              $id = (int)$u['id'];
              $txt = $u['nombre']." • ".$u['rol']." • Suc ".$u['id_sucursal'];
              $sel = in_array($id, $val['responsables'], true) ? 'selected' : '';
            ?>
            <option value="<?=$id?>" <?=$sel?>><?=h($txt)?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint mt-1">Tip: Ctrl/Shift para seleccionar varios.</div>
      </div>

      <div class="col-12 col-lg-6">
        <label class="form-label fw-semibold">Colaboradores <span class="soft">(apoyan)</span></label>
        <select class="form-select" name="colaboradores[]" multiple size="8">
          <?php foreach($usuarios as $u): ?>
            <?php
              $id = (int)$u['id'];
              $txt = $u['nombre']." • ".$u['rol']." • Suc ".$u['id_sucursal'];
              $sel = in_array($id, $val['colaboradores'], true) ? 'selected' : '';
            ?>
            <option value="<?=$id?>" <?=$sel?>><?=h($txt)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-6">
        <label class="form-label fw-semibold">Observadores <span class="soft">(solo seguimiento)</span></label>
        <select class="form-select" name="observadores[]" multiple size="7">
          <?php foreach($usuarios as $u): ?>
            <?php
              $id = (int)$u['id'];
              $txt = $u['nombre']." • ".$u['rol']." • Suc ".$u['id_sucursal'];
              $sel = in_array($id, $val['observadores'], true) ? 'selected' : '';
            ?>
            <option value="<?=$id?>" <?=$sel?>><?=h($txt)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-6">
        <label class="form-label fw-semibold">Aprobadores <span class="soft">(validan/cierre)</span></label>
        <select class="form-select" name="aprobadores[]" multiple size="7">
          <?php foreach($usuarios as $u): ?>
            <?php
              $id = (int)$u['id'];
              $txt = $u['nombre']." • ".$u['rol']." • Suc ".$u['id_sucursal'];
              $sel = in_array($id, $val['aprobadores'], true) ? 'selected' : '';
            ?>
            <option value="<?=$id?>" <?=$sel?>><?=h($txt)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <hr class="my-1">

      <div class="col-12">
        <label class="form-label fw-semibold">Dependencias <span class="soft">(esta tarea depende de otras)</span></label>
        <select class="form-select" name="dependencias[]" multiple size="8">
          <?php foreach($deps as $d): ?>
            <?php
              $id = (int)$d['id'];
              $fin = $d['fecha_fin_compromiso'] ? date('d/m/Y H:i', strtotime($d['fecha_fin_compromiso'])) : '—';
              $txt = "#".$id." • ".$d['titulo']." • ".$d['area_nombre']." • ".$d['estatus']." • Fin: ".$fin;
              $sel = in_array($id, $val['dependencias'], true) ? 'selected' : '';
            ?>
            <option value="<?=$id?>" <?=$sel?>><?=h($txt)?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint mt-1">Si una dependencia no está “Terminada”, esta tarea se verá como bloqueada en el tablero.</div>
      </div>

      <div class="col-12 d-flex flex-wrap gap-2 justify-content-end mt-2">
        <a class="btn btn-outline-secondary" href="tablero_tareas.php">Cancelar</a>
        <button class="btn btn-primary">Guardar tarea</button>
      </div>
    </div>
  </form>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
