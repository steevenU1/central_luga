<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/navbar.php')) require_once __DIR__ . '/navbar.php';

/* =======================
   Filtros (GET)
======================= */
$fSucursal  = isset($_GET['sucursal'])  ? (int)$_GET['sucursal'] : 0;   // 0 = Todas
$fMarca     = isset($_GET['marca'])     ? trim($_GET['marca'])    : '';
$fModelo    = isset($_GET['modelo'])    ? trim($_GET['modelo'])   : '';
$fCapacidad = isset($_GET['capacidad']) ? trim($_GET['capacidad']): '';

/* =======================
   Catálogos para filtros
======================= */
$catSuc = [];
$rs = $conn->query("
  SELECT id, nombre
  FROM sucursales
  WHERE COALESCE(subtipo,'') NOT IN ('Subdistribuidor','Master Admin')
  ORDER BY nombre
");
while($r = $rs->fetch_assoc()){
  $catSuc[(int)$r['id']] = $r['nombre'];
}

/* Marcas/Modelos/Capacidades existentes con filtros base */
$whereCat   = [];
$whereCat[] = "i.estatus IN ('Disponible','En tránsito')";
$whereCat[] = "p.tipo_producto = 'Equipo'";
if ($fSucursal > 0)     $whereCat[] = "i.id_sucursal = " . (int)$fSucursal;
if ($fMarca !== '')     $whereCat[] = "p.marca = '" . $conn->real_escape_string($fMarca) . "'";
if ($fCapacidad !== '') $whereCat[] = "p.capacidad = '" . $conn->real_escape_string($fCapacidad) . "'";
$whereCatSql = $whereCat ? "WHERE " . implode(" AND ", $whereCat) : '';

$catMarcas = $catModelos = $catCaps = [];
$sqlCat = "
  SELECT DISTINCT p.marca, p.modelo, p.capacidad
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  $whereCatSql
";
$rs = $conn->query($sqlCat);
while($r=$rs->fetch_assoc()){
  if ($r['marca']     !== null && $r['marca']     !== '') $catMarcas[$r['marca']] = true;
  if ($r['modelo']    !== null && $r['modelo']    !== '') $catModelos[$r['modelo']] = true;
  if ($r['capacidad'] !== null && $r['capacidad'] !== '') $catCaps[$r['capacidad']] = true;
}
$catMarcas  = array_keys($catMarcas);
$catModelos = array_keys($catModelos);
$catCaps    = array_keys($catCaps);

/* =======================
   Query agregado (pivot)
======================= */
$where   = [];
$where[] = "i.estatus IN ('Disponible','En tránsito')";
$where[] = "p.tipo_producto = 'Equipo'";
if ($fSucursal > 0)     $where[] = "i.id_sucursal = " . (int)$fSucursal;
if ($fMarca !== '')     $where[] = "p.marca = '" . $conn->real_escape_string($fMarca) . "'";
if ($fModelo !== '')    $where[] = "p.modelo = '" . $conn->real_escape_string($fModelo) . "'";
if ($fCapacidad !== '') $where[] = "p.capacidad = '" . $conn->real_escape_string($fCapacidad) . "'";
$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : '';

$sql = "
  SELECT 
    p.marca, p.modelo, p.capacidad,
    s.id   AS suc_id, 
    s.nombre AS sucursal,
    COUNT(*) AS qty
  FROM inventario i
  INNER JOIN productos  p ON p.id = i.id_producto
  INNER JOIN sucursales s ON s.id = i.id_sucursal
  $whereSql
  GROUP BY p.marca, p.modelo, p.capacidad, s.id, s.nombre
  ORDER BY p.marca, p.modelo, p.capacidad, s.nombre
";
$res = $conn->query($sql);

/* Pivot en PHP */
$rows   = [];   // ['marca','modelo','capacidad','sucs'=>[sid=>qty],'total']
$sucSet = [];   // sucursales presentes en el resultado

while($r = $res->fetch_assoc()){
  $key = $r['marca'].'|'.$r['modelo'].'|'.$r['capacidad'];
  if (!isset($rows[$key])) {
    $rows[$key] = [
      'marca'     => $r['marca'],
      'modelo'    => $r['modelo'],
      'capacidad' => $r['capacidad'],
      'sucs'      => [],
      'total'     => 0
    ];
  }
  $sid = (int)$r['suc_id'];
  $qty = (int)$r['qty'];
  $rows[$key]['sucs'][$sid] = ($rows[$key]['sucs'][$sid] ?? 0) + $qty;
  $rows[$key]['total']     += $qty;

  $sucSet[$sid] = $r['sucursal'];
}

/* Sucursales para columnas */
$useSuc = [];
if ($fSucursal > 0) {
  if (isset($catSuc[$fSucursal])) $useSuc[$fSucursal] = $catSuc[$fSucursal];
} else {
  $useSuc = $sucSet;
}
asort($useSuc, SORT_NATURAL | SORT_FLAG_CASE);

/* Orden filas por marca->modelo->capacidad */
uksort($rows, function($a,$b){
  [$ma,$mo,$ca]   = explode('|',$a,3);
  [$mb,$mob,$cb]  = explode('|',$b,3);
  return strnatcasecmp($ma,$mb) ?: strnatcasecmp($mo,$mob) ?: strnatcasecmp($ca,$cb);
});

/* Totales por columna y gran total */
$colTotals  = [];
$grandTotal = 0;
foreach ($useSuc as $sid => $name) $colTotals[$sid] = 0;

foreach ($rows as $row) {
  foreach ($useSuc as $sid => $name) {
    $q = $row['sucs'][$sid] ?? 0;
    $colTotals[$sid] += $q;
  }
  $grandTotal += (int)$row['total'];
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Resumen de inventario</title>
<link rel="icon" href="/img/favicon.ico?v=5" sizes="any">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --header-bg:#fff;
    --z-body-fixed: 6;
    --z-head: 8;
    --z-head-fixed-1: 14;
    --z-head-fixed-2: 13;
    --z-head-fixed-3: 12;
  }
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f8fafc}
  .container{max-width:1300px;margin:14px auto;padding:0 12px}
  h2{margin:0 0 10px 0}

  .filters{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:8px 0 12px}
  .filters .group{display:flex;flex-direction:column;gap:4px}
  .filters select{padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px;background:#fff}
  .btn{padding:8px 12px;border:1px solid #2563eb;background:#2563eb;color:#fff;border-radius:8px;cursor:pointer}
  .btn.secondary{background:#fff;color:#111;border-color:#cbd5e1}

  /* Contenedor con scroll (referencia para sticky) */
  .table-scroll{position:relative;max-height:70vh;overflow:auto;border:1px solid #e5e7eb;background:#fff;border-radius:8px}

  table{border-collapse:separate;border-spacing:0;width:100%;white-space:nowrap}
  th,td{border-bottom:1px solid #edf2f7;padding:8px 10px;text-align:right}
  thead th{background:var(--header-bg);font-weight:600;border-bottom:2px solid #e5e7eb;position:sticky;top:0;z-index:var(--z-head)}
  tbody td:first-child, tbody td:nth-child(2), tbody td:nth-child(3){text-align:left}
  .num{font-variant-numeric:tabular-nums}

  /* Columnas fijas (izquierda) */
  .fixed{position:sticky;background:#fff}
  .fixed-1{left:0}
  .fixed-2{left:var(--left-col-2,120px)}
  .fixed-3{left:var(--left-col-3,300px)}
  .fixed-1, .fixed-2, .fixed-3{box-shadow:1px 0 0 0 #e5e7eb inset}

  /* Z-index específico para que el header fijo (top+left) domine */
  thead th.fixed-1{z-index:var(--z-head-fixed-1)}
  thead th.fixed-2{z-index:var(--z-head-fixed-2)}
  thead th.fixed-3{z-index:var(--z-head-fixed-3)}
  tbody td.fixed{z-index:var(--z-body-fixed)}
  tfoot th.fixed{z-index:var(--z-body-fixed)}

  /* Encabezados verticales de sucursal */
  .suc-th{
    writing-mode:vertical-rl;
    transform: rotate(180deg);
    text-align:left;
    vertical-align:bottom;
    min-width:28px;
    padding:6px 4px;
  }

  /* Hover fila completa */
  tbody tr:hover td, tbody tr:hover .fixed{background:#eef4ff !important}

  /* Columna Total */
  .total-th, .total-td{background:#fafafa;font-weight:600}

  /* Totales por columna (tfoot) */
  tfoot th, tfoot td{background:#f6f7fb;font-weight:700;border-top:2px solid #e5e7eb}
  tfoot .fixed{background:#f6f7fb}
  .muted{color:#64748b;font-size:12px}
</style>

</head>
<body>
<div class="container">
  <h2>Resumen de inventario</h2>
  <!-- <div class="muted">Cuenta equipos en <strong>Disponible</strong> y <strong>En tránsito</strong>. Columnas de <b>Marca</b>, <b>Modelo</b> y <b>Capacidad</b> fijas a la izquierda y <b>encabezado fijo</b> arriba. Sucursales en vertical.</div> -->

  <form class="filters" method="get">
    <div class="group">
      <label for="sucursal">Sucursal</label>
      <select name="sucursal" id="sucursal">
        <option value="0">Todas</option>
        <?php foreach($catSuc as $id=>$nom): ?>
          <option value="<?=$id?>" <?=$id===$fSucursal?'selected':''?>><?=h($nom)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="group">
      <label for="marca">Marca</label>
      <select name="marca" id="marca">
        <option value="">Todas</option>
        <?php foreach($catMarcas as $m): ?>
          <option value="<?=h($m)?>" <?=($m===$fMarca)?'selected':''?>><?=h($m)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="group">
      <label for="modelo">Modelo</label>
      <select name="modelo" id="modelo">
        <option value="">Todos</option>
        <?php foreach($catModelos as $m): ?>
          <option value="<?=h($m)?>" <?=($m===$fModelo)?'selected':''?>><?=h($m)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="group">
      <label for="capacidad">Capacidad</label>
      <select name="capacidad" id="capacidad">
        <option value="">Todas</option>
        <?php foreach($catCaps as $c): ?>
          <option value="<?=h($c)?>" <?=($c===$fCapacidad)?'selected':''?>><?=h($c)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="group">
      <label>&nbsp;</label>
      <button class="btn" type="submit">Filtrar</button>
    </div>
    <?php if ($fSucursal||$fMarca||$fModelo||$fCapacidad): ?>
    <div class="group">
      <label>&nbsp;</label>
      <a class="btn secondary" href="inventario_resumen.php">Limpiar</a>
    </div>
    <?php endif; ?>
  </form>

  <div class="table-scroll" id="tblWrap">
    <table id="resumenTbl">
      <thead>
        <tr>
          <th class="fixed fixed-1" style="text-align:left">Marca</th>
          <th class="fixed fixed-2" style="text-align:left">Modelo</th>
          <th class="fixed fixed-3" style="text-align:left">Capacidad</th>

          <?php if (empty($useSuc)): ?>
            <th class="suc-th">—</th>
          <?php else: foreach($useSuc as $sid=>$sNom): ?>
            <th class="suc-th"><?=h($sNom)?></th>
          <?php endforeach; endif; ?>

          <th class="total-th">Total</th>
        </tr>
      </thead>

      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td class="fixed fixed-1" colspan="<?=3 + max(1,count($useSuc)) + 1?>" style="text-align:center;color:#64748b">
              Sin resultados con los filtros seleccionados.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach($rows as $row): ?>
            <tr>
              <td class="fixed fixed-1"><?=h($row['marca'])?></td>
              <td class="fixed fixed-2"><?=h($row['modelo'])?></td>
              <td class="fixed fixed-3"><?=h($row['capacidad'])?></td>

              <?php if (empty($useSuc)): ?>
                <td class="num">0</td>
              <?php else: foreach($useSuc as $sid=>$sNom): 
                      $q = $row['sucs'][$sid] ?? 0; ?>
                <td class="num"><?= $q ?: '0' ?></td>
              <?php endforeach; endif; ?>

              <td class="total-td num"><?= (int)$row['total'] ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>

      <tfoot>
        <tr>
          <th class="fixed fixed-1" style="text-align:left">Totales</th>
          <th class="fixed fixed-2"></th>
          <th class="fixed fixed-3"></th>

          <?php if (empty($useSuc)): ?>
            <th class="num">0</th>
          <?php else: foreach($useSuc as $sid=>$sNom): ?>
            <th class="num"><?= (int)$colTotals[$sid] ?></th>
          <?php endforeach; endif; ?>

          <th class="num"><?= (int)$grandTotal ?></th>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="muted" style="margin-top:8px">
    * Los totales reflejan los filtros aplicados.  
  </div>
</div>

<script>
/* Calcular offsets para las 3 columnas fijas con sus anchos reales */
function setStickyOffsets(){
  const tbl = document.getElementById('resumenTbl');
  if (!tbl) return;

  const c1 = tbl.querySelector('thead .fixed-1');
  const c2 = tbl.querySelector('thead .fixed-2');
  if (!c1 || !c2) return;

  const w1 = c1.getBoundingClientRect().width;
  const w2 = c2.getBoundingClientRect().width;

  const left2 = Math.round(w1);
  const left3 = Math.round(w1 + w2);

  tbl.style.setProperty('--left-col-2', left2 + 'px');
  tbl.style.setProperty('--left-col-3', left3 + 'px');
}

window.addEventListener('load', setStickyOffsets);
window.addEventListener('resize', setStickyOffsets);
const ro = new ResizeObserver(() => setStickyOffsets());
ro.observe(document.getElementById('tblWrap'));
</script>
</body>
</html>
