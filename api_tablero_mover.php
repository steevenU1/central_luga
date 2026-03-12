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
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$t}'
            AND COLUMN_NAME = '{$c}'
          LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}
function tableExists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$t}'
          LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rol        = (string)($_SESSION['rol'] ?? '');
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

$propiedad = (string)($_SESSION['propiedad'] ?? $_SESSION['empresa'] ?? 'Luga');
$propiedad = ($propiedad === 'Nano' || $propiedad === 'Luga') ? $propiedad : 'Luga';

$idSubdis = isset($_SESSION['id_subdis']) ? (int)$_SESSION['id_subdis'] : null;

$id = (isset($_POST['id']) && ctype_digit((string)$_POST['id'])) ? (int)$_POST['id'] : 0;
$estatus = trim((string)($_POST['estatus'] ?? ''));

if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit(); }

$valid = ['Pendiente','En proceso','Bloqueado','Terminado'];
if (!in_array($estatus, $valid, true)) { echo json_encode(['ok'=>false,'error'=>'Estatus inválido']); exit(); }

try {
  $hasIdSubdis = hasColumn($conn, 'tablero_tareas', 'id_subdis');

  // Tablas participantes (según tu implementación)
  $tblPart = null;
  if (tableExists($conn, 'tablero_participantes')) $tblPart = 'tablero_participantes';
  else if (tableExists($conn, 'tablero_watchers')) $tblPart = 'tablero_watchers';

  // Cargar tarea
  if ($hasIdSubdis) {
    $sql = "SELECT id, id_creador, id_responsable, visibilidad, id_sucursal, propiedad, id_subdis, depende_de
            FROM tablero_tareas WHERE id=? LIMIT 1";
  } else {
    $sql = "SELECT id, id_creador, id_responsable, visibilidad, id_sucursal, propiedad, depende_de
            FROM tablero_tareas WHERE id=? LIMIT 1";
  }

  $st = $conn->prepare($sql);
  $st->bind_param("i", $id);
  $st->execute();
  $t = $st->get_result()->fetch_assoc();

  if (!$t) { echo json_encode(['ok'=>false,'error'=>'No existe']); exit(); }
  if (($t['propiedad'] ?? '') !== $propiedad) { echo json_encode(['ok'=>false,'error'=>'No permitido']); exit(); }

  if ($hasIdSubdis && $idSubdis !== null && isset($t['id_subdis']) && $t['id_subdis'] !== null) {
    if ((int)$t['id_subdis'] !== $idSubdis) {
      echo json_encode(['ok'=>false,'error'=>'No permitido']); exit();
    }
  }

  $esCreador = ((int)$t['id_creador'] === $idUsuario);
  $esResp    = ((int)($t['id_responsable'] ?? 0) === $idUsuario);

  // ¿Es participante?
  $esParticipante = false;
  if ($tblPart) {
    $qp = $conn->prepare("SELECT 1 FROM {$tblPart} WHERE id_tarea=? AND id_usuario=? LIMIT 1");
    $qp->bind_param("ii", $id, $idUsuario);
    $qp->execute();
    $esParticipante = (bool)$qp->get_result()->fetch_row();
  }

  // ✅ Permisos para mover: SOLO creador o responsable o participante
  $allowed = $esCreador || $esResp || $esParticipante;

  if (!$allowed) {
    echo json_encode(['ok'=>false,'error'=>'Sin permiso para mover esta tarea']); exit();
  }

  // Dependencia: si intenta terminar, la dependencia debe estar terminada
  if ($estatus === 'Terminado' && !empty($t['depende_de'])) {
    $depId = (int)$t['depende_de'];
    $sd = $conn->prepare("SELECT estatus FROM tablero_tareas WHERE id=? LIMIT 1");
    $sd->bind_param("i", $depId);
    $sd->execute();
    $dep = $sd->get_result()->fetch_assoc();
    if ($dep && ($dep['estatus'] ?? '') !== 'Terminado') {
      echo json_encode(['ok'=>false,'error'=>'No se puede terminar: depende de una tarea no terminada']); exit();
    }
  }

  $fechaFin = ($estatus === 'Terminado') ? date('Y-m-d') : null;
  $up = $conn->prepare("UPDATE tablero_tareas SET estatus=?, fecha_fin=? WHERE id=? LIMIT 1");
  $up->bind_param("ssi", $estatus, $fechaFin, $id);
  $up->execute();

  echo json_encode(['ok'=>true]); exit();

} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit();
}