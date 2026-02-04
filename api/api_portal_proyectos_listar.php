<?php
// /api/api_portal_proyectos_listar.php
// Listar solicitudes por empresa/origen (solo lectura) con filtros, paginación y resumen por estatus.

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
function clamp_int($v, $min, $max, $default){
  $x = (int)$v;
  if ($x < $min) return $default;
  if ($x > $max) return $max;
  return $x;
}
function norm_date($s){
  $s = trim((string)$s);
  if ($s === '') return '';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return '';
  return $s;
}

// =======================
// 1) Auth + Empresa por origen
// =======================
$origen = require_origen(['NANO','MIPLAN','LUGA']);

$stmtE = $conn->prepare("SELECT id FROM portal_empresas WHERE clave=? AND activa=1 LIMIT 1");
$stmtE->bind_param("s", $origen);
$stmtE->execute();
$rowE = $stmtE->get_result()->fetch_assoc();
$stmtE->close();
if (!$rowE) bad('empresa_no_configurada', 500);
$empresaId = (int)$rowE['id'];

// =======================
// 2) Params GET
// =======================
$estatus = trim((string)($_GET['estatus'] ?? '')); // filtro para items
$q       = trim((string)($_GET['q'] ?? ''));
$desde   = norm_date($_GET['desde'] ?? '');
$hasta   = norm_date($_GET['hasta'] ?? '');

$page    = clamp_int($_GET['page'] ?? 1, 1, 9999, 1);
$perPage = clamp_int($_GET['per_page'] ?? 20, 5, 100, 20);
$offset  = ($page - 1) * $perPage;

// =======================
// 3) WHERE para ITEMS (incluye estatus)
// =======================
$whereItems = ["s.empresa_id=?"];
$paramsItems = [$empresaId];
$typesItems  = "i";

if ($estatus !== '') {
  if (mb_strlen($estatus) > 50) $estatus = mb_substr($estatus, 0, 50);
  $whereItems[] = "s.estatus=?";
  $typesItems  .= "s";
  $paramsItems[] = $estatus;
}

if ($q !== '') {
  if (mb_strlen($q) > 80) $q = mb_substr($q, 0, 80);
  $whereItems[] = "(s.folio LIKE CONCAT('%',?,'%') OR s.titulo LIKE CONCAT('%',?,'%'))";
  $typesItems  .= "ss";
  $paramsItems[] = $q;
  $paramsItems[] = $q;
}

if ($desde !== '') {
  $whereItems[] = "DATE(s.created_at) >= ?";
  $typesItems  .= "s";
  $paramsItems[] = $desde;
}

if ($hasta !== '') {
  $whereItems[] = "DATE(s.created_at) <= ?";
  $typesItems  .= "s";
  $paramsItems[] = $hasta;
}

$whereItemsSql = "WHERE " . implode(" AND ", $whereItems);

// =======================
// 4) Total (para paginación de items)
// =======================
$stmtC = $conn->prepare("SELECT COUNT(*) total FROM portal_proyectos_solicitudes s $whereItemsSql");
$stmtC->bind_param($typesItems, ...$paramsItems);
$stmtC->execute();
$total = (int)$stmtC->get_result()->fetch_assoc()['total'];
$stmtC->close();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// =======================
// 5) Items
// =======================
$sqlItems = "
  SELECT s.id, s.folio, s.titulo, s.tipo, s.prioridad, s.estatus,
         s.costo_mxn, s.created_at, s.updated_at
  FROM portal_proyectos_solicitudes s
  $whereItemsSql
  ORDER BY s.created_at DESC
  LIMIT $perPage OFFSET $offset
";

$stmt = $conn->prepare($sqlItems);
$stmt->bind_param($typesItems, ...$paramsItems);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$outItems = [];
foreach ($items as $r) {
  $outItems[] = [
    'id' => (int)$r['id'],
    'folio' => (string)$r['folio'],
    'titulo' => (string)$r['titulo'],
    'tipo' => (string)$r['tipo'],
    'prioridad' => (string)$r['prioridad'],
    'estatus' => (string)$r['estatus'],
    'costo_mxn' => ($r['costo_mxn'] === null) ? null : (float)$r['costo_mxn'],
    'created_at' => $r['created_at'],
    'updated_at' => $r['updated_at'],
  ];
}

// =======================
// 6) SUMMARY por estatus
//    - Respeta empresa + q + fechas
//    - IGNORA estatus (para que sea útil)
// =======================
$whereSum = ["s.empresa_id=?"];
$paramsSum = [$empresaId];
$typesSum  = "i";

if ($q !== '') {
  $whereSum[] = "(s.folio LIKE CONCAT('%',?,'%') OR s.titulo LIKE CONCAT('%',?,'%'))";
  $typesSum  .= "ss";
  $paramsSum[] = $q;
  $paramsSum[] = $q;
}
if ($desde !== '') { $whereSum[] = "DATE(s.created_at) >= ?"; $typesSum.="s"; $paramsSum[]=$desde; }
if ($hasta !== '') { $whereSum[] = "DATE(s.created_at) <= ?"; $typesSum.="s"; $paramsSum[]=$hasta; }

$whereSumSql = "WHERE " . implode(" AND ", $whereSum);

$summary = [
  'total' => 0,
  'by_status' => [],
  'key' => [
    'EN_VALORACION_SISTEMAS' => 0,
    'EN_COSTEO' => 0,
    'EN_VALIDACION_COSTO_SISTEMAS' => 0,
    'EN_AUTORIZACION_SOLICITANTE' => 0,
    'AUTORIZADO' => 0,
    'EN_EJECUCION' => 0,
    'FINALIZADO' => 0,
    'RECHAZADO' => 0,
    'CANCELADO' => 0,
  ],
];

$stmtS = $conn->prepare("
  SELECT s.estatus, COUNT(*) c
  FROM portal_proyectos_solicitudes s
  $whereSumSql
  GROUP BY s.estatus
");
$stmtS->bind_param($typesSum, ...$paramsSum);
$stmtS->execute();
$rs = $stmtS->get_result();
while ($row = $rs->fetch_assoc()) {
  $k = (string)$row['estatus'];
  $c = (int)$row['c'];
  $summary['by_status'][$k] = $c;
  if (array_key_exists($k, $summary['key'])) $summary['key'][$k] = $c;
  $summary['total'] += $c;
}
$stmtS->close();

// =======================
// 6.5) MONEY SUMMARY
//    - Respeta empresa + q + fechas
//    - IGNORA estatus
// =======================
$money = [
  'total_cotizado' => 0.0,
  'total_autorizado' => 0.0,
  'total_pagable' => 0.0,
];

$stmtM = $conn->prepare("
  SELECT
    COALESCE(SUM(s.costo_mxn),0) AS total_cotizado,
    COALESCE(SUM(
      CASE
        WHEN s.estatus IN ('AUTORIZADO','EN_EJECUCION','FINALIZADO')
        THEN s.costo_mxn
        ELSE 0
      END
    ),0) AS total_autorizado
  FROM portal_proyectos_solicitudes s
  $whereSumSql
");
$stmtM->bind_param($typesSum, ...$paramsSum);
$stmtM->execute();
$rowM = $stmtM->get_result()->fetch_assoc();
$stmtM->close();

$money['total_cotizado']   = (float)$rowM['total_cotizado'];
$money['total_autorizado'] = (float)$rowM['total_autorizado'];
$money['total_pagable']    = (float)$rowM['total_autorizado'];


// =======================
// 7) Response
// =======================
out(true, [
  'origen' => $origen,
  'filters' => [
    'estatus' => $estatus ?: null,
    'q' => $q ?: null,
    'desde' => $desde ?: null,
    'hasta' => $hasta ?: null,
  ],
  'pagination' => [
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'total_pages' => $totalPages,
  ],
  'summary' => $summary,
  'money' => $money,
  'items' => $outItems
]);
