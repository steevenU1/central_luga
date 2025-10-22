<?php
// reporte_nomina_v2.php — Nómina semanal v2 (Mar→Lun, UI con cards de KPIs)
// Ajuste: Las columnas de "Comisión Gerente" se muestran en 0 para Ejecutivos y
// se concentran en el renglón del Gerente de la misma sucursal (suma de todos los ejecutivos).
// La BD NO se modifica; solo es redistribución en el render.
// Este archivo incluye una columna informativa "$ Bono prov." (no persiste en BD).

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
require_once __DIR__ . '/db.php';

/* ---------------- Utils ---------------- */
function columnExists(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

function dateColVentas(mysqli $conn): string {
  if (columnExists($conn,'ventas','fecha_venta')) return 'fecha_venta';
  if (columnExists($conn,'ventas','created_at'))  return 'created_at';
  return 'fecha';
}

/** Regresa [inicio(martes 00:00), fin(lunes 23:59:59)] de la semana que contiene $anchor (Y-m-d) */
function weekBoundsFrom(string $anchor): array {
  $d = DateTime::createFromFormat('Y-m-d', $anchor) ?: new DateTime('now');
  $dow = (int)$d->format('N'); // 1=Lun..7=Dom
  $diff = $dow >= 2 ? $dow - 2 : 7 - (2 - $dow); // ir hacia el martes
  $ini = clone $d; $ini->modify("-$diff day")->setTime(0,0,0);
  $fin = clone $ini; $fin->modify("+6 day")->setTime(23,59,59);
  return [$ini, $fin];
}
function defaultWeek(): array { $today=(new DateTime('now'))->format('Y-m-d'); return weekBoundsFrom($today); }

/* ---------------- Semana seleccionada (Mar→Lun) ---------------- */
$anchor = !empty($_GET['ini']) ? $_GET['ini'] : (!empty($_GET['fin']) ? $_GET['fin'] : null);
if ($anchor) { [$ini, $fin] = weekBoundsFrom($anchor); } else { [$ini, $fin] = defaultWeek(); }
$iniStr = $ini->format('Y-m-d'); $finStr = $fin->format('Y-m-d');
$dtIni0 = $ini->format('Y-m-d 00:00:00'); $dtFin0 = $fin->format('Y-m-d 23:59:59');

/* ---------------- Helper: condición para NO contar módems ---------------- */
// Filtro robusto: usa productos.tipo si existe; si no, analiza texto en varias columnas
function notModemSQL(mysqli $conn): string {
  if (columnExists($conn,'productos','tipo')) {
    return "LOWER(p.tipo) NOT IN ('modem','módem','mifi','mi-fi','hotspot','router')";
  }
  $cols = [];
  foreach (['marca','modelo','descripcion','nombre_comercial','categoria','codigo_producto'] as $col) {
    if (columnExists($conn,'productos',$col)) $cols[] = "LOWER(COALESCE(p.$col,''))";
  }
  if (!$cols) return '1=1';
  $hay = "CONCAT(" . implode(", ' ', ", $cols) . ")";
  return "$hay NOT LIKE '%modem%'
          AND $hay NOT LIKE '%módem%'
          AND $hay NOT LIKE '%mifi%'
          AND $hay NOT LIKE '%mi-fi%'
          AND $hay NOT LIKE '%hotspot%'
          AND $hay NOT LIKE '%router%'";
}

/* ---------------- Sumas / Conteos por usuario ---------------- */
function sumDetalleVenta(mysqli $conn, int $idUsuario, string $ini, string $fin, string $campo = 'comision'): float {
  $colFecha = dateColVentas($conn);
  $sql = "
    SELECT COALESCE(SUM(d.$campo),0) AS s
    FROM detalle_venta d
    INNER JOIN ventas v ON v.id = d.id_venta
    WHERE v.id_usuario = ? AND v.$colFecha BETWEEN ? AND ?
  ";
  $stmt = $conn->prepare($sql);
  $pIni = $ini . ' 00:00:00'; $pFin = $fin . ' 23:59:59';
  $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
  $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
  return (float)($row['s'] ?? 0);
}

/** Conteo de unidades totales (Eq #)
 *  - Con detalle_venta: cuenta renglones del detalle EXCLUYENDO módem/MiFi => combo con 2 productos (no módem) cuenta 2.
 *  - Sin detalle_venta: si hay v.tipo_venta, SUM(CASE WHEN tipo_venta LIKE '%combo%' THEN 2 ELSE 1 END); si no, COUNT(*).
 */
function countEquipos(mysqli $conn, int $idUsuario, string $ini, string $fin): int {
  $colFecha = dateColVentas($conn);
  $pIni = $ini . ' 00:00:00'; $pFin = $fin . ' 23:59:59';
  $tieneDet = columnExists($conn,'detalle_venta','id');
  if ($tieneDet) {
    $joinProd = columnExists($conn,'productos','id') ? "LEFT JOIN productos p ON p.id = d.id_producto" : "";
    $condNoModem = notModemSQL($conn);
    $sql = "
      SELECT COUNT(d.id) AS c
      FROM detalle_venta d
      INNER JOIN ventas v ON v.id = d.id_venta
      $joinProd
      WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? AND ($condNoModem)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
    $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0);
  } else {
    $tieneTipoVenta = columnExists($conn,'ventas','tipo_venta');
    $estatusCond = columnExists($conn,'ventas','estatus')
      ? " AND (v.estatus IS NULL OR v.estatus NOT IN ('Cancelada','Cancelado','cancelada','cancelado'))"
      : "";
    if ($tieneTipoVenta) {
      $sql = "
        SELECT COALESCE(SUM(CASE WHEN LOWER(v.tipo_venta) LIKE '%combo%' THEN 2 ELSE 1 END),0) AS c
        FROM ventas v
        WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? $estatusCond
      ";
    } else {
      $sql = "
        SELECT COUNT(*) AS c
        FROM ventas v
        WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? $estatusCond
      ";
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
    $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0);
  }
}

/** Unidades elegibles para bono:
 * - No-combo: cuenta renglones de detalle EXCLUYENDO módem/MiFi (1 por renglón).
 * - Combo: cuenta **1 por venta** (si existe al menos un detalle no-módem en esa venta).
 * - Sin detalle_venta: cuenta 1 por venta; si no hay tipo_venta, devolvemos 0 (no podemos distinguir combos ni módems).
 */
function countEligibleUnitsForBonus(mysqli $conn, int $idUsuario, string $ini, string $fin): int {
  $colFecha = dateColVentas($conn);
  $pIni = $ini . ' 00:00:00'; 
  $pFin = $fin . ' 23:59:59';

  $tieneDet  = columnExists($conn,'detalle_venta','id');
  $tieneTipo = columnExists($conn,'ventas','tipo_venta');

  if ($tieneDet) {
    $joinProd = columnExists($conn,'productos','id') ? "LEFT JOIN productos p ON p.id = d.id_producto" : "";
    $condNoModem = notModemSQL($conn);

    // A) No-combo: cuenta unidades no-módem (1 por renglón del detalle)
    $sqlA = "
      SELECT COUNT(d.id) AS c
      FROM detalle_venta d
      INNER JOIN ventas v ON v.id = d.id_venta
      $joinProd
      WHERE v.id_usuario=? 
        AND v.$colFecha BETWEEN ? AND ?
        ".($tieneTipo ? "AND (LOWER(v.tipo_venta) NOT LIKE '%combo%')" : "")."
        AND ($condNoModem)
    ";
    $stmtA = $conn->prepare($sqlA);
    $stmtA->bind_param("iss", $idUsuario, $pIni, $pFin);
    $stmtA->execute(); 
    $rowA = $stmtA->get_result()->fetch_assoc(); 
    $stmtA->close();
    $countA = (int)($rowA['c'] ?? 0);

    // B) Combo: cuenta 1 por venta con al menos un detalle no-módem
    if ($tieneTipo) {
      $sqlB = "
        SELECT COUNT(DISTINCT v.id) AS c
        FROM ventas v
        INNER JOIN detalle_venta d ON d.id_venta = v.id
        $joinProd
        WHERE v.id_usuario=?
          AND v.$colFecha BETWEEN ? AND ?
          AND (LOWER(v.tipo_venta) LIKE '%combo%')
          AND ($condNoModem)
      ";
      $stmtB = $conn->prepare($sqlB);
      $stmtB->bind_param("iss", $idUsuario, $pIni, $pFin);
      $stmtB->execute(); 
      $rowB = $stmtB->get_result()->fetch_assoc(); 
      $stmtB->close();
      $countB = (int)($rowB['c'] ?? 0);
    } else {
      $countB = 0;
    }

    return $countA + $countB;
  }

  // Fallback sin detalle_venta:
  if ($tieneTipo) {
    $sql = "
      SELECT COALESCE(SUM(1),0) AS c
      FROM ventas v
      WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
    $stmt->execute(); 
    $row = $stmt->get_result()->fetch_assoc(); 
    $stmt->close();
    return (int)($row['c'] ?? 0); // combo=1 también aquí
  }

  return 0;
}

function sumSims(mysqli $conn, int $idUsuario, string $ini, string $fin, bool $soloPospago, string $campo = 'comision_ejecutivo'): float {
  $pIni = $ini . ' 00:00:00'; $pFin = $fin . ' 23:59:59';
  if ($soloPospago) {
    $sql = "SELECT COALESCE(SUM($campo),0) AS s
            FROM ventas_sims WHERE id_usuario=? AND tipo_venta='Pospago' AND fecha_venta BETWEEN ? AND ?";
  } else {
    $sql = "SELECT COALESCE(SUM($campo),0) AS s
            FROM ventas_sims WHERE id_usuario=? AND tipo_venta<>'Pospago' AND fecha_venta BETWEEN ? AND ?";
  }
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
  $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
  return (float)($row['s'] ?? 0);
}
function sumPayjoyTC(mysqli $conn, int $idUsuario, string $ini, string $fin, string $campo = 'comision'): float {
  $sql = "SELECT COALESCE(SUM($campo),0) AS s
          FROM ventas_payjoy_tc WHERE id_usuario=? AND fecha_venta BETWEEN ? AND ?";
  $stmt = $conn->prepare($sql);
  $pIni = $ini . ' 00:00:00'; $pFin = $fin . ' 23:59:59';
  $stmt->bind_param("iss", $idUsuario, $pIni, $pFin);
  $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
  return (float)($row['s'] ?? 0);
}
function sumDescuentos(mysqli $conn, int $idUsuario, string $ini, string $fin): float {
  $sql = "SELECT COALESCE(SUM(monto),0) AS s
          FROM descuentos_nomina WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("iss", $idUsuario, $ini, $fin);
  $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
  return (float)($row['s'] ?? 0);
}
function sumAjusteTipo(mysqli $conn, int $idUsuario, string $ini, string $fin, string $tipo): float {
  $sql = "SELECT COALESCE(SUM(monto),0) AS s
          FROM nomina_ajustes_v2 WHERE id_usuario=? AND semana_inicio=? AND semana_fin=? AND tipo=?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("isss", $idUsuario, $ini, $fin, $tipo);
  $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
  return (float)($row['s'] ?? 0);
}

/* ---------------- Usuarios activos + sucursales Tienda/Propia ---------------- */
$colUserSuc = null;
if (columnExists($conn, 'usuarios', 'sucursal'))     $colUserSuc = 'sucursal';
if (columnExists($conn, 'usuarios', 'id_sucursal'))  $colUserSuc = 'id_sucursal';

$usuarios = [];
if ($colUserSuc) {
  $Q = "
    SELECT
      u.id, u.nombre, u.rol, u.sueldo, COALESCE(u.activo,1) AS activo,
      u.$colUserSuc AS id_sucursal, s.nombre AS sucursal_nombre,
      CASE WHEN u.rol='Gerente' THEN 0 WHEN u.rol='Ejecutivo' THEN 1 ELSE 2 END AS rol_orden
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.$colUserSuc
    WHERE (u.activo IS NULL OR u.activo=1)
      AND s.tipo_sucursal='Tienda'
      AND s.subtipo='Propia'
    ORDER BY s.nombre ASC, rol_orden ASC, u.nombre ASC
  ";
} else {
  $Q = "
    SELECT
      u.id, u.nombre, u.rol, u.sueldo, COALESCE(u.activo,1) AS activo,
      NULL AS id_sucursal, '(Sin sucursal)' AS sucursal_nombre,
      CASE WHEN u.rol='Gerente' THEN 0 WHEN u.rol='Ejecutivo' THEN 1 ELSE 2 END AS rol_orden
    FROM usuarios u
    WHERE (u.activo IS NULL OR u.activo=1)
    ORDER BY sucursal_nombre ASC, rol_orden ASC, u.nombre ASC
  ";
}
$resU = $conn->query($Q);
while ($r = $resU->fetch_assoc()) $usuarios[] = $r;
$resU->close();

/* ---------------- Confirmaciones por semana (batch) ---------------- */
$confirmMap = []; // id_usuario => ['confirmado'=>0/1, 'confirmado_en'=>'...']
if (columnExists($conn,'nomina_confirmaciones','id')) {
  $qi = $conn->real_escape_string($iniStr);
  $qf = $conn->real_escape_string($finStr);
  $qc = "
    SELECT id_usuario, confirmado, confirmado_en
    FROM nomina_confirmaciones
    WHERE semana_inicio='$qi' AND semana_fin='$qf'
  ";
  if ($resC = $conn->query($qc)) {
    while ($row = $resC->fetch_assoc()) {
      $confirmMap[(int)$row['id_usuario']] = [
        'confirmado'   => (int)$row['confirmado'],
        'confirmado_en'=> $row['confirmado_en']
      ];
    }
    $resC->close();
  }
}

/* ---------------- Cálculo por usuario (primer pase, RAW) ---------------- */
$data = [];
foreach ($usuarios as $u) {
  $uid  = (int)$u['id'];
  $base = (float)($u['sueldo'] ?? 0);

  $eq_eje  = sumDetalleVenta($conn, $uid, $iniStr, $finStr, 'comision');
  $eq_cnt  = countEquipos($conn, $uid, $iniStr, $finStr); // combo=2, sin módem
  $eligible_units = countEligibleUnitsForBonus($conn, $uid, $iniStr, $finStr); // combo=1, sin módem
  $bono_provisional = ($eligible_units > 10) ? ($eligible_units * 50) : 0;

  $sim_eje = sumSims($conn, $uid, $iniStr, $finStr, false, 'comision_ejecutivo');
  $pos_eje = sumSims($conn, $uid, $iniStr, $finStr, true,  'comision_ejecutivo');
  $tc_eje  = sumPayjoyTC($conn, $uid, $iniStr, $finStr, 'comision');

  $eq_ger  = sumDetalleVenta($conn, $uid, $iniStr, $finStr, 'comision_gerente');
  $sim_ger = sumSims($conn, $uid, $iniStr, $finStr, false, 'comision_gerente');
  $pos_ger = sumSims($conn, $uid, $iniStr, $finStr, true,  'comision_gerente');
  $tc_ger  = sumPayjoyTC($conn, $uid, $iniStr, $finStr, 'comision_gerente');

  $bonos   = sumAjusteTipo($conn, $uid, $iniStr, $finStr, 'bono');
  $ajuste  = sumAjusteTipo($conn, $uid, $iniStr, $finStr, 'ajuste');
  $descs   = sumDescuentos($conn, $uid, $iniStr, $finStr);

  $total_raw = $base
         + $eq_eje + $sim_eje + $pos_eje + $tc_eje
         + $eq_ger + $sim_ger + $pos_ger + $tc_ger
         + $bonos - $descs + $ajuste;

  $conf = $confirmMap[$uid] ?? ['confirmado'=>0,'confirmado_en'=>null];

  $data[] = [
    'id'=>$uid, 'nombre'=>$u['nombre'], 'rol'=>$u['rol'], 'id_sucursal'=>$u['id_sucursal'],
    'sucursal_nombre'=>$u['sucursal_nombre'],
    'eq_cnt'=>$eq_cnt,
    'base'=>$base,
    // ejecutivo
    'eq_eje'=>$eq_eje, 'sim_eje'=>$sim_eje, 'pos_eje'=>$pos_eje, 'tc_eje'=>$tc_eje,
    // gerente (raw)
    'eq_ger_raw'=>$eq_ger, 'sim_ger_raw'=>$sim_ger, 'pos_ger_raw'=>$pos_ger, 'tc_ger_raw'=>$tc_ger,
    // mostrados (se rellenan en segundo pase)
    'eq_ger'=>0, 'sim_ger'=>0, 'pos_ger'=>0, 'tc_ger'=>0,
    // ajustes
    'bonos'=>$bonos, 'descuentos'=>$descs, 'ajuste'=>$ajuste,
    // informativo
    'bono_provisional'=>$bono_provisional,
    'total_raw'=>$total_raw, 'total'=>0,
    'confirmado'=>$conf['confirmado'], 'confirmado_en'=>$conf['confirmado_en']
  ];
}

/* ---------------- Redistribución para mostrar: gerente por sucursal ---------------- */
$gerPorSucursal = []; // [id_sucursal] => ['eq'=>..,'sim'=>..,'pos'=>..,'tc'=>..]
foreach ($data as $r) {
  $sid = (int)($r['id_sucursal'] ?? 0);
  if (!isset($gerPorSucursal[$sid])) $gerPorSucursal[$sid] = ['eq'=>0,'sim'=>0,'pos'=>0,'tc'=>0];
  if (strcasecmp($r['rol'],'Gerente') !== 0) {
    $gerPorSucursal[$sid]['eq']  += (float)$r['eq_ger_raw'];
    $gerPorSucursal[$sid]['sim'] += (float)$r['sim_ger_raw'];
    $gerPorSucursal[$sid]['pos'] += (float)$r['pos_ger_raw'];
    $gerPorSucursal[$sid]['tc']  += (float)$r['tc_ger_raw'];
  }
}

// Segundo pase: set de columnas mostradas y TOTAL calculado con lo mostrado
foreach ($data as &$r) {
  $sid = (int)($r['id_sucursal'] ?? 0);
  if (strcasecmp($r['rol'],'Gerente') === 0) {
    $r['eq_ger']  = (float)($gerPorSucursal[$sid]['eq']  ?? 0);
    $r['sim_ger'] = (float)($gerPorSucursal[$sid]['sim'] ?? 0);
    $r['pos_ger'] = (float)($gerPorSucursal[$sid]['pos'] ?? 0);
    $r['tc_ger']  = (float)($gerPorSucursal[$sid]['tc']  ?? 0);
  } else {
    $r['eq_ger'] = $r['sim_ger'] = $r['pos_ger'] = $r['tc_ger'] = 0.0;
  }

  // Recalcular total usando valores mostrados (no raw)
  $r['total'] = (float)$r['base']
              + (float)$r['eq_eje'] + (float)$r['sim_eje'] + (float)$r['pos_eje'] + (float)$r['tc_eje']
              + (float)$r['eq_ger'] + (float)$r['sim_ger'] + (float)$r['pos_ger'] + (float)$r['tc_ger']
              + (float)$r['bonos'] - (float)$r['descuentos'] + (float)$r['ajuste'];
}
unset($r);

/* ---------------- Totales y KPIs (con valores mostrados) ---------------- */
$tot = [
  'base'=>0,'eq_cnt'=>0,
  'eq_eje'=>0,'sim_eje'=>0,'pos_eje'=>0,'tc_eje'=>0,
  'eq_ger'=>0,'sim_ger'=>0,'pos_ger'=>0,'tc_ger'=>0,
  'bonos'=>0,'descuentos'=>0,'ajuste'=>0,'total'=>0,
  'bono_provisional'=>0
];
foreach ($data as $r) { foreach ($tot as $k=>$_) { $tot[$k] += (float)($r[$k] ?? 0); } }

$empleadosActivos = count($data);
$comisiones_ejecutivo = $tot['eq_eje'] + $tot['sim_eje'] + $tot['pos_eje'] + $tot['tc_eje'];
$comisiones_gerente   = $tot['eq_ger'] + $tot['sim_ger'] + $tot['pos_ger'] + $tot['tc_ger']; // ahora solo las que se muestran a gerentes
$comisiones_totales   = $comisiones_ejecutivo + $comisiones_gerente;
$confirmados          = array_sum(array_map(fn($r)=> (int)($r['confirmado'] ?? 0), $data));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nómina semanal v2</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  :root{ --pad-y:.30rem; --pad-x:.5rem; --fs-xs:.8rem; }
  body{background:#f6f8fb;}
  .page-title{font-weight:800; letter-spacing:.2px;}
  .card-elev{border:0; border-radius:16px; box-shadow:0 18px 30px rgba(0,0,0,.05),0 2px 8px rgba(0,0,0,.04);}

  .toolbar{gap:.5rem; flex-wrap:wrap;}
  .week-pill{display:flex; align-items:center; gap:.5rem; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:.35rem .5rem;}
  .week-pill input[type="date"]{border:0; outline:0; font-size:.92rem; padding:.35rem .4rem; background:transparent;}
  .week-pill .sep{color:#94a3b8; font-size:.9rem;}
  .toolbar .btn{border-radius:10px;}

  /* KPI Cards */
  .kpi{border:0; border-radius:14px; box-shadow:0 10px 22px rgba(0,0,0,.05);}
  .kpi .icon{width:38px;height:38px; display:inline-flex; align-items:center; justify-content:center; border-radius:10px;}
  .kpi h6{margin:0; font-size:.78rem; color:#6b7280; text-transform:uppercase; letter-spacing:.08em;}
  .kpi .val{font-variant-numeric:tabular-nums; font-size:1.05rem; font-weight:800;}

  /* Tabla compacta y visible */
  .tbl-wrap{position:relative; overflow:auto; max-height:70vh;}
  .tbl{width:100%; border-collapse:separate; border-spacing:0; font-size:var(--fs-xs);}
  .tbl thead th{position:sticky; top:0; z-index:3; background:#fff; font-weight:700;
    padding:.4rem var(--pad-x); white-space:nowrap; border-bottom:2px solid #e5e7eb;}
  .tbl tbody td{padding:var(--pad-y) var(--pad-x); border-top:1px solid #eef1f5; vertical-align:middle;}
  .tbl tfoot td{padding:.45rem var(--pad-x); font-weight:700; background:#0d6efd0d; border-top:2px solid #dde3ee;}
  .col-emp{position:sticky; left:0; z-index:2; background:#fff; box-shadow:1px 0 0 #eef1f5 inset;}
  .emp-nom{font-weight:700; line-height:1.05;} .emp-rol{color:#6b7280; font-size:.78em;}
  .money{font-variant-numeric:tabular-nums; white-space:nowrap;} .text-end{ text-align:right; }
  .tbl-wrap::after{content:""; position:sticky; right:0; top:0; bottom:0; width:16px; background:linear-gradient(90deg, transparent, rgba(0,0,0,.06)); pointer-events:none; z-index:1;}
  .badge-week{background:#eef2ff;color:#1e40af;border:1px solid #dbeafe;}
  .editable{min-width:92px;} .link-col{color:#0d6efd; text-decoration:none;} .link-col:hover{text-decoration:underline;}

  .badge-confirm{font-weight:600;}
  .badge-ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
  .badge-pend{background:#111;color:#9a3412;border:1px solid #fed7aa;}

  /* Estilo bono provisional */
  .bono-prov-aplica { color:#16a34a; font-weight:600; }
  .bono-prov-noaplica { color:#6b7280; }
</style>
</head>
<body>

<?php include_once __DIR__.'/navbar.php'; ?>

<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h2 class="page-title mb-1"><i class="bi bi-cash-coin me-2"></i>Nómina semanal v2</h2>
      <div class="text-muted">Semana <span class="badge rounded-pill badge-week"><?= htmlspecialchars($iniStr) ?> → <?= htmlspecialchars($finStr) ?></span> (Mar→Lun)</div>
    </div>

    <!-- Toolbar de semana -->
    <form class="d-flex align-items-center toolbar" method="get" id="weekForm">
      <button class="btn btn-outline-secondary" type="button" id="btnPrev" title="Semana anterior"><i class="bi bi-chevron-left"></i></button>
      <div class="week-pill">
        <input type="date" id="inpIni" name="ini" value="<?= htmlspecialchars($iniStr) ?>" aria-label="Inicio de semana">
        <span class="sep">→</span>
        <input type="date" id="inpFin" name="fin" value="<?= htmlspecialchars($finStr) ?>" aria-label="Fin de semana">
      </div>
      <button class="btn btn-outline-secondary" type="button" id="btnToday" title="Ir a esta semana"><i class="bi bi-crosshair"></i></button>
      <button class="btn btn-outline-secondary" type="button" id="btnNext" title="Semana siguiente"><i class="bi bi-chevron-right"></i></button>

      <!-- Botón para aplicar cuotas y recalcular -->
      <button class="btn btn-success" type="button" id="btnRecalc" title="Aplicar cuotas y recalcular comisiones">
        <i class="bi bi-magic me-1"></i> Aplicar cuotas y recalcular
      </button>

      <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Actualizar</button>
    </form>
  </div>

  <!-- ===== KPI CARDS ===== -->
  <div class="row g-3 mb-3">
    <div class="col-6 col-sm-4 col-lg-3 col-xxl-2">
      <div class="card kpi p-3">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6>Empleados</h6>
            <div class="val"><?= number_format($empleadosActivos) ?></div>
          </div>
          <div class="icon bg-primary-subtle text-primary"><i class="bi bi-people"></i></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-sm-4 col-lg-3 col-xxl-2">
      <div class="card kpi p-3">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6>Confirmados</h6>
            <div class="val"><?= number_format($confirmados) ?>/<?= number_format($empleadosActivos) ?></div>
          </div>
          <div class="icon bg-success-subtle text-success"><i class="bi bi-check2-circle"></i></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-sm-4 col-lg-3 col-xxl-2">
      <div class="card kpi p-3">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6>Equipos (unid.)</h6>
            <div class="val"><?= number_format($tot['eq_cnt']) ?></div>
          </div>
          <div class="icon bg-secondary-subtle text-secondary"><i class="bi bi-phone"></i></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-sm-4 col-lg-3 col-xxl-2">
      <div class="card kpi p-3">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6>Comisiones (Eje)</h6>
            <div class="val">$<?= number_format($comisiones_ejecutivo,2) ?></div>
          </div>
          <div class="icon bg-success-subtle text-success"><i class="bi bi-graph-up-arrow"></i></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-sm-4 col-lg-3 col-xxl-2">
      <div class="card kpi p-3">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6>Comisiones (G.)</h6>
            <div class="val">$<?= number_format($comisiones_gerente,2) ?></div>
          </div>
          <div class="icon bg-warning-subtle text-warning"><i class="bi bi-diagram-3"></i></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-4 col-xxl-3">
      <div class="card kpi p-3">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <h6>Total nómina</h6>
            <div class="val">$<?= number_format($tot['total'],2) ?></div>
          </div>
          <div class="icon bg-primary text-white"><i class="bi bi-receipt-cutoff"></i></div>
        </div>
      </div>
    </div>
  </div>
  <!-- ===== /KPI CARDS ===== -->

  <div class="card card-elev">
    <div class="card-body p-0">
      <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th class="col-emp">Empleado</th>
              <th>Sucursal</th>
              <th class="text-end"><abbr title="Conteo de ventas de equipos (combo=2; sin módem/mifi si hay detalle)">Eq #</abbr></th>
              <th><abbr title="Sueldo base">Base</abbr></th>
              <th class="text-end"><abbr title="Comisión Equipos">Eq</abbr></th>
              <th class="text-end"><abbr title="Comisión SIMs (prepago)">SIMs</abbr></th>
              <th class="text-end"><abbr title="Comisión Pospago">Posp</abbr></th>
              <th class="text-end"><abbr title="Comisión PayJoy TC">TC</abbr></th>
              <th class="text-end"><abbr title="Comisión Gerente Equipos">G. Eq</abbr></th>
              <th class="text-end"><abbr title="Comisión Gerente SIMs">G. SIMs</abbr></th>
              <th class="text-end"><abbr title="Comisión Gerente Pospago">G. Posp</abbr></th>
              <th class="text-end"><abbr title="Comisión Gerente TC">G. TC</abbr></th>
              <!-- ★ NUEVO: columna informativa bono provisional -->
              <th class="text-end"><abbr title="Bono provisional automático ($50 por unidad elegible si >10; combo cuenta 1; sin módem)">$ Bono prov.</abbr></th>
              <th class="text-end">Bonos</th>
              <th class="text-end"><abbr title="Descuentos">Desc</abbr></th>
              <th class="text-end">Ajuste</th>
              <th class="text-end">Total</th>
              <th><abbr title="Confirmación del ejecutivo en la semana">Confirmación</abbr></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($data as $r): ?>
            <tr data-uid="<?= (int)$r['id'] ?>">
              <td class="col-emp">
                <div class="emp-nom"><?= htmlspecialchars($r['nombre']) ?></div>
                <div class="emp-rol"><?= htmlspecialchars($r['rol']) ?></div>
              </td>
              <td><?= htmlspecialchars($r['sucursal_nombre']) ?></td>
              <td class="text-end"><?= number_format($r['eq_cnt']) ?></td>
              <td class="money">$<?= number_format($r['base'],2) ?></td>
              <td class="money text-end">$<?= number_format($r['eq_eje'],2) ?></td>
              <td class="money text-end">$<?= number_format($r['sim_eje'],2) ?></td>
              <td class="money text-end">$<?= number_format($r['pos_eje'],2) ?></td>
              <td class="money text-end">$<?= number_format($r['tc_eje'],2) ?></td>
              <!-- columnas de gerente ya redistribuidas -->
              <td class="money text-end"><a class="link-col" title="Detalle G. Equipos">$<?= number_format($r['eq_ger'],2) ?></a></td>
              <td class="money text-end"><a class="link-col" title="Detalle G. SIMs">$<?= number_format($r['sim_ger'],2) ?></a></td>
              <td class="money text-end"><a class="link-col" title="Detalle G. Pospago">$<?= number_format($r['pos_ger'],2) ?></a></td>
              <td class="money text-end"><a class="link-col" title="Detalle G. TC">$<?= number_format($r['tc_ger'],2) ?></a></td>

              <?php $aplica = ($r['bono_provisional'] > 0); ?>
              <td class="money text-end <?= $aplica ? 'bono-prov-aplica' : 'bono-prov-noaplica' ?>">
                $<?= number_format($r['bono_provisional'],2) ?>
              </td>

              <td class="text-end">
                <input type="number" step="0.01" class="form-control form-control-sm money editable"
                  value="<?= htmlspecialchars(number_format($r['bonos'],2,'.','')) ?>" data-field="bono">
              </td>
              <td class="money text-end">$<?= number_format($r['descuentos'],2) ?></td>
              <td class="text-end">
                <input type="number" step="0.01" class="form-control form-control-sm money editable"
                  value="<?= htmlspecialchars(number_format($r['ajuste'],2,'.','')) ?>" data-field="ajuste">
              </td>
              <td class="money text-end">$<?= number_format($r['total'],2) ?></td>
              <td>
                <?php if ((int)($r['confirmado'] ?? 0) === 1): ?>
                  <span class="badge badge-confirm badge-ok">Confirmada<?= $r['confirmado_en'] ? ' · '.htmlspecialchars($r['confirmado_en']) : '' ?></span>
                <?php else: ?>
                  <span class="badge badge-confirm badge-pend">Pendiente</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td>Total</td>
              <td></td>
              <td class="text-end"><?= number_format($tot['eq_cnt']) ?></td>
              <td class="money">$<?= number_format($tot['base'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['eq_eje'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['sim_eje'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['pos_eje'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['tc_eje'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['eq_ger'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['sim_ger'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['pos_ger'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['tc_ger'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['bono_provisional'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['bonos'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['descuentos'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['ajuste'],2) ?></td>
              <td class="money text-end">$<?= number_format($tot['total'],2) ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <div class="card-footer d-flex justify-content-between align-items-center">
      <div class="small text-muted">
        Semana Mar→Lun. <b>Bonos</b> y <b>Ajuste</b> en <code>nomina_ajustes_v2</code>. <b>Descuentos</b> desde <code>descuentos_nomina</code>. <b>Confirmación</b> en <code>nomina_confirmaciones</code>.
      </div>
      <div class="d-flex gap-2">
        <button id="btn_export" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-file-earmark-spreadsheet me-1"></i>Exportar CSV
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// ===== Helpers semana (Mar→Lun) =====
function fmt(d){ const p=n=>n.toString().padStart(2,'0'); return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}`; }
function fromYMD(s){ const [y,m,d]=s.split('-').map(Number); return new Date(y, m-1, d); }
function weekBounds(dateStr){
  const d = dateStr ? fromYMD(dateStr) : new Date();
  const day = d.getDay(); const target=2; // 0=Dom..6=Sab, target=Martes
  const diff = (day - target + 7) % 7;
  const start=new Date(d); start.setDate(d.getDate()-diff);
  const end=new Date(start); end.setDate(end.getDate()+6);
  return {ini:fmt(start), fin:fmt(end)};
}
function shiftWeek(dateStr, delta){ const d=fromYMD(dateStr); d.setDate(d.getDate() + (delta*7)); return weekBounds(fmt(d)); }

const inpIni  = document.getElementById('inpIni');
const inpFin  = document.getElementById('inpFin');
const form    = document.getElementById('weekForm');
function snapFrom(anchor){ const {ini, fin}=weekBounds(anchor); inpIni.value=ini; inpFin.value=fin; }
inpIni.addEventListener('change', e=> snapFrom(e.target.value));
inpFin.addEventListener('change', e=> snapFrom(e.target.value));
document.getElementById('btnPrev').addEventListener('click', ()=>{ const {ini,fin}=shiftWeek(inpIni.value||inpFin.value,-1); inpIni.value=ini; inpFin.value=fin; form.submit(); });
document.getElementById('btnNext').addEventListener('click', ()=>{ const {ini,fin}=shiftWeek(inpIni.value||inpFin.value, 1); inpIni.value=ini; inpFin.value=fin; form.submit(); });
document.getElementById('btnToday').addEventListener('click', ()=>{ const {ini,fin}=weekBounds(); inpIni.value=ini; inpFin.value=fin; form.submit(); });
snapFrom(inpIni.value || inpFin.value);

// Guardado inline Bono/Ajuste
document.querySelectorAll('input.editable').forEach(inp=>{
  inp.addEventListener('change', async (e)=>{
    const tr = e.target.closest('tr');
    const uid = tr.dataset.uid;
    const tipo = e.target.dataset.field;
    const theMonto = parseFloat(e.target.value||'0') || 0;
    const params = new URLSearchParams({ uid, tipo, monto: theMonto, ini: inpIni.value, fin: inpFin.value });
    e.target.classList.add('is-valid');
    const resp = await fetch('guardar_ajuste_nomina_v2.php', {
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params.toString()
    });
    e.target.classList.remove('is-valid');
    if (!resp.ok) { alert('No se pudo guardar.'); return; }
    location.reload();
  });
});

// Export CSV
document.getElementById('btn_export').addEventListener('click', ()=>{
  const rows = [...document.querySelectorAll('.tbl tr')].map(tr=>[...tr.children].map(td=>{
    const inp = td.querySelector('input'); return (inp ? inp.value : td.innerText).replace(/\s+/g,' ').trim();
  }));
  const csv = rows.map(r=>r.map(v=>`"${v.replaceAll('"','""')}"`).join(',')).join('\n');
  const blob = new Blob([csv],{type:'text/csv;charset=utf-8;'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `nomina_${document.getElementById('inpIni').value}_${document.getElementById('inpFin').value}.csv`;
  document.body.appendChild(a); a.click(); a.remove();
});

// Recalcular con cuotas
document.getElementById('btnRecalc').addEventListener('click', async ()=>{
  const ini = document.getElementById('inpIni').value;
  const fin = document.getElementById('inpFin').value;
  const btn = document.getElementById('btnRecalc');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Recalculando...';

  try{
    const body = new URLSearchParams({ini, fin});
    const resp = await fetch('recalculo_comisiones_v2.php', {
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString()
    });
    const text = await resp.text();
    let j;
    try { j = JSON.parse(text); } catch { throw new Error('El servidor respondió con HTML/Texto:\n\n' + text); }
    if (j.status !== 'ok') throw new Error(j.message || 'Recalculo con observaciones');
    location.reload();
  }catch(err){
    alert('No se pudo recalcular: ' + err.message);
  }finally{
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-magic me-1"></i> Aplicar cuotas y recalcular';
  }
});
</script>
</body>
</html>
