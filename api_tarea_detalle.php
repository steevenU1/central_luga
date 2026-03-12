<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME='{$t}'
            AND COLUMN_NAME='{$c}'
          LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

$propiedad = (string)($_SESSION['propiedad'] ?? $_SESSION['empresa'] ?? 'Luga');
$propiedad = ($propiedad === 'Nano' || $propiedad === 'Luga') ? $propiedad : 'Luga';

$idSubdis = isset($_SESSION['id_subdis']) ? (int)$_SESSION['id_subdis'] : null;

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit(); }

$hasIdSubdis = hasColumn($conn, 'tablero_tareas', 'id_subdis');

try {
  // 1) cargar tarea
  $sql = "
    SELECT
      t.*,
      DATE_FORMAT(t.fecha_inicio, '%Y-%m-%d') AS f_inicio,
      DATE_FORMAT(t.fecha_estimada, '%Y-%m-%d') AS f_estimada,
      DATE_FORMAT(t.fecha_fin, '%Y-%m-%d') AS f_fin,
      u.nombre AS responsable_nombre
    FROM tablero_tareas t
    LEFT JOIN usuarios u ON u.id = t.id_responsable
    WHERE t.id = ? LIMIT 1
  ";
  $st = $conn->prepare($sql);
  $st->bind_param("i", $id);
  $st->execute();
  $t = $st->get_result()->fetch_assoc();

  if (!$t) { echo json_encode(['ok'=>false,'error'=>'No existe']); exit(); }

  // seguridad multi-empresa
  if (($t['propiedad'] ?? '') !== $propiedad) {
    echo json_encode(['ok'=>false,'error'=>'No permitido']); exit();
  }
  if ($hasIdSubdis && $idSubdis !== null && isset($t['id_subdis']) && $t['id_subdis'] !== null) {
    if ((int)$t['id_subdis'] !== (int)$idSubdis) {
      echo json_encode(['ok'=>false,'error'=>'No permitido']); exit();
    }
  }

  $idCreador     = (int)($t['id_creador'] ?? 0);
  $idResponsable = (int)($t['id_responsable'] ?? 0);
  $vis           = (string)($t['visibilidad'] ?? 'Privada');
  $tSuc          = (int)($t['id_sucursal'] ?? 0);

  // 2) verificar si es participante (watcher)
  $isWatcher = false;
  $sw = $conn->prepare("SELECT 1 FROM tablero_watchers WHERE id_tarea=? AND id_usuario=? LIMIT 1");
  $sw->bind_param("ii", $id, $idUsuario);
  $sw->execute();
  $w = $sw->get_result()->fetch_assoc();
  if ($w) $isWatcher = true;

  // 3) permiso de ver (sin admin)
  $canSee = ($idCreador === $idUsuario) || ($idResponsable === $idUsuario) || $isWatcher;

  if (!$canSee) {
    if ($vis === 'Sucursal' && $tSuc === $idSucursal) $canSee = true;
    if ($vis === 'Empresa') $canSee = true;
  }

  if (!$canSee) {
    echo json_encode(['ok'=>false,'error'=>'Sin acceso a esta tarea']); exit();
  }

  // 4) permisos de acciones (sin admin)
  $puedeEditarParticipantes = ($idCreador === $idUsuario) || ($idResponsable === $idUsuario);
  $puedeComentar = $canSee;
  $puedeMover = ($idCreador === $idUsuario) || ($idResponsable === $idUsuario) || $isWatcher;

  // 5) participantes (watchers)
  $parts = [];
  $sp = $conn->prepare("
    SELECT w.id_usuario, u.nombre, u.rol
    FROM tablero_watchers w
    JOIN usuarios u ON u.id = w.id_usuario
    WHERE w.id_tarea = ?
    ORDER BY u.nombre ASC
  ");
  $sp->bind_param("i", $id);
  $sp->execute();
  $rp = $sp->get_result();
  while($r = $rp->fetch_assoc()) $parts[] = $r;

  // 6) comentarios
  $coms = [];
  $sc = $conn->prepare("
    SELECT c.id, c.comentario, DATE_FORMAT(c.created_at, '%Y-%m-%d %H:%i') AS created_at, u.nombre
    FROM tablero_comentarios c
    JOIN usuarios u ON u.id = c.id_usuario
    WHERE c.id_tarea = ?
    ORDER BY c.created_at DESC
    LIMIT 200
  ");
  $sc->bind_param("i", $id);
  $sc->execute();
  $rc = $sc->get_result();
  while($r = $rc->fetch_assoc()) $coms[] = $r;

  echo json_encode([
    'ok'=>true,
    'tarea'=>[
      'id'=>$t['id'],
      'titulo'=>$t['titulo'],
      'descripcion'=>$t['descripcion'],
      'estatus'=>$t['estatus'],
      'prioridad'=>$t['prioridad'],
      'visibilidad'=>$t['visibilidad'],
      'fecha_inicio'=>$t['f_inicio'],
      'fecha_estimada'=>$t['f_estimada'],
      'fecha_fin'=>$t['f_fin'],
      'depende_de'=>$t['depende_de'],
      'responsable_nombre'=>$t['responsable_nombre']
    ],
    'participantes'=>$parts,
    'comentarios'=>$coms,
    'permisos'=>[
      'puede_editar_participantes'=>$puedeEditarParticipantes,
      'puede_comentar'=>$puedeComentar,
      'puede_mover'=>$puedeMover
    ]
  ]);
  exit();

} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit();
}