<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit();
}

require_once __DIR__ . '/db.php';

function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$c}' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rol        = (string)($_SESSION['rol'] ?? '');
$propiedad  = (string)($_SESSION['propiedad'] ?? $_SESSION['empresa'] ?? 'Luga');
$propiedad  = ($propiedad === 'Nano' || $propiedad === 'Luga') ? $propiedad : 'Luga';
$idSubdis   = isset($_SESSION['id_subdis']) ? (int)$_SESSION['id_subdis'] : null;

$idTarea = isset($_POST['id_tarea']) && ctype_digit((string)$_POST['id_tarea']) ? (int)$_POST['id_tarea'] : 0;
$idU = isset($_POST['id_usuario']) && ctype_digit((string)$_POST['id_usuario']) ? (int)$_POST['id_usuario'] : 0;
$accion = trim((string)($_POST['accion'] ?? ''));

if ($idTarea<=0 || $idU<=0) { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit(); }
if (!in_array($accion, ['add','remove'], true)) { echo json_encode(['ok'=>false,'error'=>'Acción inválida']); exit(); }

$isAdmin = ($rol === 'Admin' || $rol === 'SuperAdmin');
$hasIdSubdis = hasColumn($conn, 'tablero_tareas', 'id_subdis');

try {
  // cargar tarea para validar permisos
  $sql = $hasIdSubdis
    ? "SELECT id, propiedad, id_subdis, id_creador, id_responsable FROM tablero_tareas WHERE id=? LIMIT 1"
    : "SELECT id, propiedad, id_creador, id_responsable FROM tablero_tareas WHERE id=? LIMIT 1";

  $st = $conn->prepare($sql);
  $st->bind_param("i", $idTarea);
  $st->execute();
  $t = $st->get_result()->fetch_assoc();
  if (!$t) { echo json_encode(['ok'=>false,'error'=>'No existe']); exit(); }
  if (($t['propiedad'] ?? '') !== $propiedad) { echo json_encode(['ok'=>false,'error'=>'No permitido']); exit(); }

  if ($hasIdSubdis && $idSubdis !== null && isset($t['id_subdis']) && $t['id_subdis'] !== null) {
    if ((int)$t['id_subdis'] !== $idSubdis) { echo json_encode(['ok'=>false,'error'=>'No permitido']); exit(); }
  }

  $puede = $isAdmin
    || ((int)$t['id_creador'] === $idUsuario)
    || ((int)($t['id_responsable'] ?? 0) === $idUsuario);

  if (!$puede) { echo json_encode(['ok'=>false,'error'=>'Sin permiso para editar participantes']); exit(); }

  if ($accion === 'add') {
    $ins = $conn->prepare("INSERT IGNORE INTO tablero_watchers (id_tarea, id_usuario) VALUES (?,?)");
    $ins->bind_param("ii", $idTarea, $idU);
    $ins->execute();
    echo json_encode(['ok'=>true]); exit();
  } else {
    $del = $conn->prepare("DELETE FROM tablero_watchers WHERE id_tarea=? AND id_usuario=? LIMIT 1");
    $del->bind_param("ii", $idTarea, $idU);
    $del->execute();
    echo json_encode(['ok'=>true]); exit();
  }

} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit();
}