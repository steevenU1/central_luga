<?php
// /api/api_portal_proyectos_ver.php
// Solo lectura: consultar solicitud por id o folio (y solo si pertenece a tu ORIGEN)

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'method_not_allowed'], JSON_UNESCAPED_UNICODE);
  exit;
}

function out($ok, $data=[]){
  echo json_encode(array_merge(['ok'=>$ok], $data), JSON_UNESCAPED_UNICODE);
  exit;
}
function bad($msg, $code=400){
  http_response_code($code);
  out(false, ['error'=>$msg]);
}

// 1) Auth
$origen = require_origen(['NANO','MIPLAN','LUGA']);

// 2) Parámetros: id o folio
$id    = (int)($_GET['id'] ?? 0);
$folio = trim((string)($_GET['folio'] ?? ''));

if ($id <= 0 && $folio === '') bad('missing_params');

// 3) Resolver empresa_id del ORIGEN
$stmtE = $conn->prepare("SELECT id FROM portal_empresas WHERE clave=? AND activa=1 LIMIT 1");
$stmtE->bind_param("s", $origen);
$stmtE->execute();
$rowE = $stmtE->get_result()->fetch_assoc();
$stmtE->close();
if (!$rowE) bad('empresa_no_configurada', 500);
$empresaId = (int)$rowE['id'];

try {

  if ($id > 0) {
    $stmt = $conn->prepare("
      SELECT s.id, s.folio, s.titulo, s.tipo, s.prioridad, s.estatus,
             s.costo_mxn, s.costo_capturado_at, s.costo_validado_at,
             s.created_at, s.updated_at
      FROM portal_proyectos_solicitudes s
      WHERE s.id=? AND s.empresa_id=? LIMIT 1
    ");
    $stmt->bind_param("ii", $id, $empresaId);
  } else {
    // normalizamos folio
    if (mb_strlen($folio) > 30) $folio = mb_substr($folio, 0, 30);

    $stmt = $conn->prepare("
      SELECT s.id, s.folio, s.titulo, s.tipo, s.prioridad, s.estatus,
             s.costo_mxn, s.costo_capturado_at, s.costo_validado_at,
             s.created_at, s.updated_at
      FROM portal_proyectos_solicitudes s
      WHERE s.folio=? AND s.empresa_id=? LIMIT 1
    ");
    $stmt->bind_param("si", $folio, $empresaId);
  }

  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) {
    http_response_code(404);
    out(false, ['error'=>'not_found']);
  }

  // 4) Respuesta
  out(true, [
    'origen' => $origen,
    'data' => [
      'id' => (int)$row['id'],
      'folio' => (string)$row['folio'],
      'titulo' => (string)$row['titulo'],
      'tipo' => (string)$row['tipo'],
      'prioridad' => (string)$row['prioridad'],
      'estatus' => (string)$row['estatus'],
      'costo_mxn' => ($row['costo_mxn'] === null) ? null : (float)$row['costo_mxn'],
      'costo_capturado_at' => $row['costo_capturado_at'],
      'costo_validado_at' => $row['costo_validado_at'],
      'created_at' => $row['created_at'],
      'updated_at' => $row['updated_at'],
    ]
  ]);

} catch (Throwable $e) {
  bad('error', 500);
}
