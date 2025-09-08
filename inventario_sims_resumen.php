<?php
// inventario_sims_resumen.php — Central 2.0 (completo)
// - Export CSV sin romper headers (antes de cualquier salida).
// - Navbar en vista normal (omitido solo al exportar).
// - Admin: Global (todas) o filtrar por sucursal.
// - Otros roles: solo su sucursal.
// - Filtros: operador, tipo_plan, búsqueda por ICCID/DN.
// - UI: KPIs + cards por sucursal + tabla detalle por sucursal.

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

// ===== Helpers =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function selectOptions(array $opts, $sel){
  $html=''; foreach($opts as $val=>$txt){
    $html.='<option value="'.h($val).'"'.($val===$sel?' selected':'').'>'.h($txt).'</option>';
  } return $html;
}
function isAdmin($rol){ return in_array($rol, ['Admin'], true); }

// ===== Parámetros / filtros =====
$scope        = isAdmin($ROL) ? ($_GET['scope'] ?? 'global') : 'sucursal';  // global | sucursal
$selSucursal  = isAdmin($ROL) ? (int)($_GET['sucursal'] ?? 0) : $ID_SUCURSAL; // 0 = todas (solo admin global)
$operador     = $_GET['operador']  ?? 'ALL';  // ALL | Bait | AT&T | ...
$tipoPlan     = $_GET['tipo_plan'] ?? 'ALL';  // ALL | Prepago | Pospago
$q            = trim((string)($_GET['q'] ?? '')); // búsqueda ICCID/DN

if (!isAdmin($ROL)) { $scope='sucursal'; $selSucursal=$ID_SUCURSAL; }
if ($scope!=='global') { $scope='sucursal'; }

// ===== WHERE base (solo disponibles) =====
$where = ["i.estatus='Disponible'"];
$params=[]; $types='';
if ($operador!=='ALL'){ $where[]="i.operador=?"; $params[]=$operador; $types.='s'; }
if ($tipoPlan!=='ALL'){ $where[]="i.tipo_plan=?"; $params[]=$tipoPlan; $types.='s'; }
if ($q!==''){ $where[]="(i.iccid LIKE ? OR i.dn LIKE ?)"; $like="%$q%"; $params[]=$like; $params[]=$like; $types.='ss'; }
$whereSql = $where ? 'WHERE '.implode(' AND ',$where) : '';

// ===== EXPORT CSV (antes de cualquier salida) =====
if (isset($_GET['export']) && $_GET['export']==='1') {
  // SQL según scope/filtros
  $extraWhere=''; $p=$params; $t=$types;
  if ($scope==='sucursal') {
    $extraWhere = ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
    $p[]=$selSucursal; $t.='i';
    $sql = "SELECT s.nombre AS sucursal, i.operador, i.tipo_plan, i.iccid, i.dn, i.fecha_ingreso
            FROM inventario_sims i
            LEFT JOIN sucursales s ON s.id=i.id_sucursal
            $whereSql $extraWhere
            ORDER BY i.operador, i.tipo_plan, i.iccid";
  } else {
    if ($selSucursal>0){ $extraWhere = ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?"; $p[]=$selSucursal; $t.='i'; }
    $sql = "SELECT s.nombre AS sucursal, i.operador, i.tipo_plan, i.iccid, i.dn, i.fecha_ingreso
            FROM inventario_sims i
            LEFT JOIN sucursales s ON s.id=i.id_sucursal
            $whereSql $extraWhere
            ORDER BY s.nombre, i.operador, i.tipo_plan, i.iccid";
  }

  // Limpiar buffers y enviar CSV
  if (ob_get_level()) { while (ob_get_level()) { ob_end_clean(); } }
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="inventario_sims_disponibles.csv"');

  $stmt=$conn->prepare($sql);
  if ($p){ $stmt->bind_param($t, ...$p); }
  $stmt->execute();
  $res=$stmt->get_result();

  $out=fopen('php://output','w');
  fputcsv($out, ['Sucursal','Operador','Tipo Plan','ICCID','DN','Fecha Ingreso']);
  while($r=$res->fetch_assoc()){
    fputcsv($out, [
      $r['sucursal'] ?? '',
      $r['operador'] ?? '',
      $r['tipo_plan'] ?? '',
      $r['iccid'] ?? '',
      $r['dn'] ?? '',
      $r['fecha_ingreso'] ?? ''
    ]);
  }
  fclose($out);
  exit;
}

// ===== Cargar sucursales (selector Admin) =====
$sucursales=[];
if (isAdmin($ROL)) {
  $rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
  $sucursales = $rs->fetch_all(MYSQLI_ASSOC);
}

// ===== KPIs =====
function kpisGlobal(mysqli $conn, $whereSql, $params, $types, $selSucursal, $scope){
  $extra=''; $p=$params; $t=$types;
  if ($scope==='sucursal' || ($scope==='global' && $selSucursal>0)) {
    $extra = ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?"; $p[]=$selSucursal; $t.='i';
  }
  $sql="SELECT COUNT(*) total,
               SUM(i.operador='Bait') bait,
               SUM(i.operador='AT&T') att
        FROM inventario_sims i $whereSql $extra";
  $st=$conn->prepare($sql); if ($p){ $st->bind_param($t, ...$p); } $st->execute();
  $row=$st->get_result()->fetch_assoc() ?: [];
  return ['total'=>(int)($row['total']??0), 'bait'=>(int)($row['bait']??0), 'att'=>(int)($row['att']??0)];
}
$kpis = kpisGlobal($conn,$whereSql,$params,$types,$selSucursal,$scope);

// ===== Cards por sucursal (solo global) =====
$cards=[];
if ($scope==='global'){
  $extra=''; $p=$params; $t=$types;
  if ($selSucursal>0){ $extra = ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?"; $p[]=$selSucursal; $t.='i'; }
  $sql="SELECT s.id id_suc, s.nombre,
               COUNT(i.id) disponibles,
               SUM(i.operador='Bait') bait,
               SUM(i.operador='AT&T') att
        FROM sucursales s
        LEFT JOIN inventario_sims i ON i.id_sucursal=s.id
        $whereSql $extra
        GROUP BY s.id
        HAVING disponibles > 0
        ORDER BY s.nombre";
  $st=$conn->prepare($sql); if ($p){ $st->bind_param($t, ...$p); } $st->execute();
  $res=$st->get_result(); while($row=$res->fetch_assoc()){ $cards[]=$row; }
}

// ===== Detalle de una sucursal =====
$detalle=[]; $sucursalNombre='';
if ($scope==='sucursal'){
  $st=$conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
  $st->bind_param('i', $selSucursal); $st->execute();
  $sucursalNombre = (string)($st->get_result()->fetch_column() ?: '');

  $extra = ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
  $sql="SELECT i.id, i.iccid, i.dn, i.operador, i.tipo_plan, i.fecha_ingreso, i.caja_id
        FROM inventario_sims i
        $whereSql $extra
        ORDER BY i.operador, i.tipo_plan, i.iccid";
  $p=array_merge($params,[$selSucursal]); $t=$types.'i';
  $st=$conn->prepare($sql); $st->bind_param($t, ...$p); $st->execute();
  $res=$st->get_result(); while($row=$res->fetch_assoc()){ $detalle[]=$row; }
}

// ===== Vista normal (con navbar) =====
require_once __DIR__ . '/navbar.php';
?>
<!doctype html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Inventario SIMs — Central 2.0</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .kpi-card{ border:0; border-radius:1rem; box-shadow:0 6px 20px rgba(0,0,0,.08); }
    .kpi-value{ font-size: clamp(1.8rem, 2.5vw, 2.4rem); font-weight:800; line-height:1; }
    .kpi-sub{ opacity:.75; font-weight:600; }
    .suc-card{ border:0; border-radius:1rem; box-shadow:0 4px 16px rgba(0,0,0,.06); transition:.2s transform; }
    .suc-card:hover{ transform: translateY(-3px); }
    .badge-soft{ background:rgba(13,110,253,.1); color:#0d6efd; }
    .grid{ display:grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap:1rem; }
    .sticky-top-lite{ position: sticky; top: .5rem; z-index: 100; }
    .table thead th{ position:sticky; top:0; background:var(--bs-body-bg); z-index:5; }
    .searchbar{ max-width: 380px; }
  </style>
</head>
<body class="bg-body-tertiary">
<div class="container py-3 py-md-4">

  <!-- Encabezado + Filtros -->
  <div class="sticky-top-lite mb-3">
    <div class="card border-0 shadow-sm rounded-4">
      <div class="card-body">
        <div class="d-flex flex-column flex-lg-row gap-3 align-items-lg-end justify-content-between">
          <div>
            <h2 class="mb-1">Inventario de SIMs <span class="text-secondary">— Disponibles</span></h2>
            <div class="text-secondary small">Central 2.0 · Vista <?= h($scope==='global'?'global':'por sucursal'); ?></div>
          </div>

          <form class="row g-2 align-items-end" method="get">
            <?php if (isAdmin($ROL)): ?>
              <div class="col-auto">
                <label class="form-label mb-1">Ámbito</label>
                <select name="scope" class="form-select">
                  <option value="global"   <?= $scope==='global'?'selected':''; ?>>Global</option>
                  <option value="sucursal" <?= $scope==='sucursal'?'selected':''; ?>>Por sucursal</option>
                </select>
              </div>
              <div class="col-auto">
                <label class="form-label mb-1">Sucursal</label>
                <select name="sucursal" class="form-select" <?= $scope==='global'?'':'disabled'; ?>>
                  <option value="0">— Todas —</option>
                  <?php foreach($sucursales as $s): ?>
                    <option value="<?= (int)$s['id']; ?>" <?= (int)$s['id']===$selSucursal?'selected':''; ?>>
                      <?= h($s['nombre']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <input type="hidden" name="scope" value="sucursal">
              <input type="hidden" name="sucursal" value="<?= (int)$selSucursal; ?>">
            <?php endif; ?>

            <div class="col-auto">
              <label class="form-label mb-1">Operador</label>
              <select name="operador" class="form-select">
                <?= selectOptions(['ALL'=>'Todos','Bait'=>'Bait','AT&T'=>'AT&T'], $operador); ?>
              </select>
            </div>
            <div class="col-auto">
              <label class="form-label mb-1">Tipo plan</label>
              <select name="tipo_plan" class="form-select">
                <?= selectOptions(['ALL'=>'Todos','Prepago'=>'Prepago','Pospago'=>'Pospago'], $tipoPlan); ?>
              </select>
            </div>
            <div class="col-auto">
              <label class="form-label mb-1">Buscar</label>
              <input type="text" class="form-control searchbar" name="q" value="<?= h($q); ?>" placeholder="ICCID o DN…">
            </div>
            <div class="col-auto d-flex gap-2">
              <button class="btn btn-primary">Aplicar</button>
              <a class="btn btn-outline-secondary" href="inventario_sims_resumen.php">Limpiar</a>
              <a class="btn btn-success" href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'1']))); ?>">Exportar CSV</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
      <div class="card kpi-card">
        <div class="card-body">
          <div class="kpi-sub text-secondary">SIMs disponibles</div>
          <div class="kpi-value"><?= number_format($kpis['total']); ?></div>
          <div class="small text-secondary">Inventario actual según filtros</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card kpi-card">
        <div class="card-body">
          <div class="kpi-sub text-secondary">Bait</div>
          <div class="kpi-value"><?= number_format($kpis['bait']); ?></div>
          <div class="small text-secondary">Por operador</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card kpi-card">
        <div class="card-body">
          <div class="kpi-sub text-secondary">AT&amp;T</div>
          <div class="kpi-value"><?= number_format($kpis['att']); ?></div>
          <div class="small text-secondary">Por operador</div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($scope==='global'): ?>
    <!-- Cards por sucursal -->
    <div class="grid mb-5">
      <?php if (!$cards): ?>
        <div class="text-center text-secondary py-5">No hay SIMs disponibles que coincidan con los filtros.</div>
      <?php else: foreach($cards as $c): ?>
        <div class="card suc-card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <h5 class="mb-0"><?= h($c['nombre']); ?></h5>
              <span class="badge text-bg-light">ID <?= (int)$c['id_suc']; ?></span>
            </div>
            <div class="display-6 fw-bold mb-2"><?= number_format((int)$c['disponibles']); ?></div>
            <div class="d-flex gap-2 mb-3">
              <span class="badge badge-soft">Bait: <?= (int)$c['bait']; ?></span>
              <span class="badge badge-soft">AT&amp;T: <?= (int)$c['att']; ?></span>
            </div>
            <div class="d-flex gap-2">
              <a class="btn btn-sm btn-outline-primary"
                 href="?<?= h(http_build_query(array_merge($_GET, ['scope'=>'sucursal','sucursal'=>(int)$c['id_suc']]))); ?>">
                Ver detalle
              </a>
              <a class="btn btn-sm btn-outline-success"
                 href="?<?= h(http_build_query(array_merge($_GET, ['scope'=>'global','sucursal'=>(int)$c['id_suc'],'export'=>'1']))); ?>">
                Exportar CSV
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  <?php else: ?>
    <!-- Detalle de sucursal -->
    <div class="card border-0 shadow-sm rounded-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
          <div>
            <h4 class="mb-0">Sucursal: <?= h($sucursalNombre ?: ('#'.$selSucursal)); ?></h4>
            <div class="text-secondary small">Listado de SIMs disponibles</div>
          </div>
          <?php if (isAdmin($ROL)): ?>
            <a class="btn btn-outline-secondary btn-sm"
               href="?<?= h(http_build_query(array_merge($_GET, ['scope'=>'global','sucursal'=>0]))); ?>">
              Volver a Global
            </a>
          <?php endif; ?>
        </div>

        <div class="table-responsive" style="max-height: 70vh;">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th class="text-secondary">#</th>
                <th>ICCID</th>
                <th>DN</th>
                <th>Operador</th>
                <th>Plan</th>
                <th>Caja</th>
                <th>Ingreso</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$detalle): ?>
                <tr><td colspan="7" class="text-center text-secondary">Sin registros</td></tr>
              <?php else:
                $i=1; foreach($detalle as $r): ?>
                <tr>
                  <td class="text-secondary"><?= $i++; ?></td>
                  <td class="fw-semibold"><?= h($r['iccid']); ?></td>
                  <td><?= h($r['dn']); ?></td>
                  <td><?= h($r['operador']); ?></td>
                  <td><?= h($r['tipo_plan']); ?></td>
                  <td><?= h($r['caja_id']); ?></td>
                  <td class="text-nowrap"><?= h($r['fecha_ingreso']); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <div class="d-flex gap-2 mt-3">
          <a class="btn btn-success"
             href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'1']))); ?>">
            Exportar CSV
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>

</div>
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
