<?php
// admin_asistencias.php  ·  Panel Admin con KPIs + Matriz + Detalle + Export CSV
ob_start(); // buffer para evitar "headers already sent"
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php"); exit();
}

require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

/* ===== Debug opcional ===== */
$DEBUG = isset($_GET['debug']);
if ($DEBUG) {
  ini_set('display_errors','1');
  ini_set('display_startup_errors','1');
  error_reporting(E_ALL);
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function opWeekStartFromWeekInput(string $iso): ?DateTime {
  if (!preg_match('/^(\d{4})-W(\d{2})$/',$iso,$m)) return null;
  $dt=new DateTime(); $dt->setISODate((int)$m[1],(int)$m[2]); $dt->modify('+1 day'); $dt->setTime(0,0,0); return $dt;
}
function currentOpWeekIso(): string {
  $t=new DateTime('today'); $dow=(int)$t->format('N'); $off=($dow>=2)?$dow-2:6+$dow; $tue=(clone $t)->modify("-{$off} days"); $mon=(clone $tue)->modify('-1 day'); return $mon->format('o-\WW');
}
function fmtBadgeRango(DateTime $tueStart): string {
  $dias=['Mar','Mié','Jue','Vie','Sáb','Dom','Lun']; $ini=(clone $tueStart); $fin=(clone $tueStart)->modify('+6 day'); return $dias[0].' '.$ini->format('d/m').' → '.$dias[6].' '.$fin->format('d/m');
}
function diaCortoEs(DateTime $d): string {
  static $map=[1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
  return $map[(int)$d->format('N')] ?? $d->format('D');
}

/* ================== Compatibilidad de BD (producción) ================== */
function table_exists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $q = $conn->query("SHOW TABLES LIKE '{$t}'");
  return $q && $q->num_rows > 0;
}
function column_exists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $q = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $q && $q->num_rows > 0;
}
function pickDateCol(mysqli $conn, string $table, array $candidates=['fecha','creado_en','fecha_evento','dia','timestamp']): string {
  foreach ($candidates as $c) {
    if (column_exists($conn, $table, $c)) return $c;
  }
  return 'fecha';
}
function pickDateColWithAlias(mysqli $conn, string $table, string $alias, array $candidates=['fecha','creado_en','fecha_evento','dia','timestamp']): string {
  $raw = pickDateCol($conn, $table, $candidates);
  return "{$alias}.`{$raw}`";
}

/* ===== Helper: obtener todas las filas de un stmt SIN mysqlnd ===== */
function stmt_all_assoc(mysqli_stmt $stmt): array {
  $rows = [];
  $meta = $stmt->result_metadata();
  if (!$meta) return $rows;
  $fields = $meta->fetch_fields();
  $row = [];
  $bind = [];
  foreach ($fields as $f) { $row[$f->name] = null; $bind[] = &$row[$f->name]; }
  call_user_func_array([$stmt, 'bind_result'], $bind);
  while ($stmt->fetch()) {
    $rows[] = array_combine(array_keys($row), array_map(function($v){ return $v; }, array_values($row)));
  }
  return $rows;
}

/* ================== Filtros ================== */
$isExport = isset($_GET['export']);
$weekIso = $_GET['week'] ?? currentOpWeekIso();
$tuesdayStart = opWeekStartFromWeekInput($weekIso) ?: new DateTime('tuesday this week');
$start = $tuesdayStart->format('Y-m-d');
$end   = (clone $tuesdayStart)->modify('+6 day')->format('Y-m-d');
$today = (new DateTime('today'))->format('Y-m-d'); // para no contar faltas en días futuros

/* ===== Sucursales 'tienda' 'propia' ===== */
$sucursales = [];
$resSuc = $conn->query("SELECT id,nombre FROM sucursales WHERE tipo_sucursal='tienda' AND subtipo='propia' ORDER BY nombre");
if ($resSuc) { while ($r = $resSuc->fetch_assoc()) $sucursales[] = $r; }

$sucursal_id = isset($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : 0;
$qsExport = http_build_query(['week'=>$weekIso,'sucursal_id'=>$sucursal_id]);

/* ===== Usuarios activos — SOLO Gerente/Ejecutivo ===== */
$paramsU=[]; $typesU='';
$whereU=" WHERE u.activo=1 
          AND s.tipo_sucursal='tienda' 
          AND s.subtipo='propia'
          AND u.rol IN ('Gerente','Ejecutivo') ";
if ($sucursal_id>0){ $whereU.=' AND u.id_sucursal=? '; $typesU.='i'; $paramsU[]=$sucursal_id; }
$sqlUsers="SELECT u.id,u.nombre,u.id_sucursal,s.nombre AS sucursal 
           FROM usuarios u 
           JOIN sucursales s ON s.id=u.id_sucursal 
           $whereU 
           ORDER BY s.nombre,u.nombre";
$stmt=$conn->prepare($sqlUsers);
if($typesU) $stmt->bind_param($typesU, ...$paramsU);
$stmt->execute();
$usuarios = stmt_all_assoc($stmt);
$stmt->close();
$userIds=array_map(fn($u)=>(int)$u['id'],$usuarios); if(!$userIds)$userIds=[0];

/* ===== Horarios por sucursal ===== */
$horarios=[];
$horTable = table_exists($conn,'sucursales_horario') ? 'sucursales_horario' : (table_exists($conn,'horarios_sucursal') ? 'horarios_sucursal' : null);
if ($horTable === 'sucursales_horario') {
  $resH = $conn->query("SELECT id_sucursal,dia_semana,abre,cierra,cerrado FROM sucursales_horario");
  if ($resH) while($r=$resH->fetch_assoc()){
    $horarios[(int)$r['id_sucursal']][(int)$r['dia_semana']] = ['abre'=>$r['abre'],'cierra'=>$r['cierra'],'cerrado'=>(int)$r['cerrado']];
  }
} elseif ($horTable === 'horarios_sucursal') {
  $resH = $conn->query("SELECT id_sucursal,dia_semana,apertura AS abre,cierre AS cierra,IF(activo=1,0,1) AS cerrado FROM horarios_sucursal");
  if ($resH) while($r=$resH->fetch_assoc()){
    $horarios[(int)$r['id_sucursal']][(int)$r['dia_semana']] = ['abre'=>$r['abre'],'cierra'=>$r['cierra'],'cerrado'=>(int)$r['cerrado']];
  }
}

/* ===== Descansos semana ===== */
$descansos=[];
if (table_exists($conn,'descansos_programados')) {
  $inList=implode(',',array_fill(0,count($userIds),'?'));
  $typesD=str_repeat('i',count($userIds)).'ss';
  $descansoDateCol = pickDateCol($conn, 'descansos_programados', ['fecha','dia','fecha_programada']);
  $sqlD = "SELECT id_usuario, `{$descansoDateCol}` AS fecha FROM descansos_programados WHERE id_usuario IN ($inList) AND `{$descansoDateCol}` BETWEEN ? AND ?";
  $stmt=$conn->prepare($sqlD);
  $stmt->bind_param($typesD, ...array_merge($userIds,[$start,$end]));
  $stmt->execute();
  $rows=stmt_all_assoc($stmt);
  foreach($rows as $r){ $descansos[(int)$r['id_usuario']][$r['fecha']] = true; }
  $stmt->close();
}

/* ===== Permisos aprobados ===== */
$permAprob=[];
if (table_exists($conn,'permisos_solicitudes')) {
  $permDateCol = pickDateCol($conn, 'permisos_solicitudes', ['fecha','dia','fecha_solicitada','fecha_permiso','creado_en']);
  $inList=implode(',',array_fill(0,count($userIds),'?'));
  $typesPA=str_repeat('i',count($userIds)).'ss';
  $sqlPA = "SELECT id_usuario, `{$permDateCol}` AS fecha FROM permisos_solicitudes WHERE id_usuario IN ($inList) AND status='Aprobado' AND `{$permDateCol}` BETWEEN ? AND ?";
  $stmt=$conn->prepare($sqlPA);
  $stmt->bind_param($typesPA, ...array_merge($userIds,[$start,$end]));
  $stmt->execute();
  $rows=stmt_all_assoc($stmt);
  foreach($rows as $r){ $permAprob[(int)$r['id_usuario']][$r['fecha']] = true; }
  $stmt->close();
}

/* ===== Asistencias detalle — con nombre de usuario ===== */
$typesA=str_repeat('i',count($userIds)).'ss';
$asisDateRaw = pickDateColWithAlias($conn, 'asistencias', 'a', ['fecha','creado_en','fecha_evento','dia','timestamp']);
$asistDet=[];
$sqlA="
  SELECT a.*, {$asisDateRaw} AS fecha, s.nombre AS sucursal, u.nombre AS usuario
  FROM asistencias a
  JOIN sucursales s ON s.id=a.id_sucursal
  JOIN usuarios u   ON u.id=a.id_usuario
  WHERE a.id_usuario IN (%s) AND DATE({$asisDateRaw}) BETWEEN ? AND ?
  ORDER BY {$asisDateRaw} ASC, a.hora_entrada ASC, a.id ASC
";
$inList=implode(',',array_fill(0,count($userIds),'?'));
$sqlA = sprintf($sqlA, $inList);
$stmt=$conn->prepare($sqlA);
$stmt->bind_param($typesA, ...array_merge($userIds,[$start,$end]));
$stmt->execute();
$asistDet = stmt_all_assoc($stmt);
$stmt->close();

/* Index asistencia por usuario/día */
$asistByUserDay=[];
foreach($asistDet as $a){
  $uid=(int)$a['id_usuario']; $f=$a['fecha'];
  if(!isset($asistByUserDay[$uid][$f])) $asistByUserDay[$uid][$f]=$a;
}

/* ===== Permisos de la semana (tabla informativa) ===== */
$permisosSemana=[];
if (table_exists($conn,'permisos_solicitudes')) {
  $permDateRaw = pickDateColWithAlias($conn, 'permisos_solicitudes', 'p', ['fecha','dia','fecha_solicitada','fecha_permiso','creado_en']);
  $typesPS='ss'; $paramsPS=[$start,$end];
  $wherePS = " AND s.tipo_sucursal='tienda' AND s.subtipo='propia' ";
  if ($sucursal_id>0){ $typesPS.='i'; $paramsPS[]=$sucursal_id; $wherePS.=' AND s.id=? '; }
  $sqlPS="
    SELECT p.*, {$permDateRaw} AS fecha, u.nombre AS usuario, s.nombre AS sucursal
    FROM permisos_solicitudes p
    JOIN usuarios u ON u.id=p.id_usuario
    JOIN sucursales s ON s.id=p.id_sucursal
    WHERE DATE({$permDateRaw}) BETWEEN ? AND ? $wherePS
    ORDER BY s.nombre,u.nombre, {$permDateRaw} DESC
  ";
  $stmt=$conn->prepare($sqlPS);
  $stmt->bind_param($typesPS, ...$paramsPS);
  $stmt->execute();
  $permisosSemana = stmt_all_assoc($stmt);
  $stmt->close();
}

/* ====== Construcción de matriz + KPIs ====== */
$days=[]; for($i=0;$i<7;$i++){ $d=clone $tuesdayStart; $d->modify("+$i day"); $days[]=$d; }
$weekNames=['Mar','Mié','Jue','Vie','Sáb','Dom','Lun'];

$matriz=[];
$totAsis=0;$totRet=0;$totFal=0;$totPerm=0;$totDesc=0;$totMin=0;$faltasPorRetardos=0;$laborables=0;$presentes=0;

foreach($usuarios as $u){
  $uid=(int)$u['id']; $sid=(int)$u['id_sucursal'];
  $fila=['usuario'=>$u['nombre'],'sucursal'=>$u['sucursal'],'dias'=>[],'asis'=>0,'ret'=>0,'fal'=>0,'perm'=>0,'desc'=>0,'min'=>0];
  $retSemanaUsuario=0;

  foreach($days as $d){
    $f=$d->format('Y-m-d');
    $isFuture = ($f > $today); // no contar faltas en futuro
    $dow=(int)$d->format('N');
    $hor=$horarios[$sid][$dow]??null; $cerrado=$hor?((int)$hor['cerrado']===1):false;
    $isDesc=!empty($descansos[$uid][$f]); $isPerm=!empty($permAprob[$uid][$f]);
    $a=$asistByUserDay[$uid][$f]??null;
    $esLaborable = !$cerrado && !$isDesc && !$isPerm;

    if ($isFuture) {
      $fila['dias'][]=['fecha'=>$f,'estado'=>'PENDIENTE','entrada'=>null,'salida'=>null,'retardo_min'=>0,'dur'=>0];
      continue;
    }

    if($a){
      $ret=(int)($a['retardo']??0); $retMin=(int)($a['retardo_minutos']??0); $dur=(int)($a['duracion_minutos']??0);
      $fila['min'] += $dur; $totMin += $dur;
      if($ret===1){ $estado='RETARDO'; $fila['ret']++; $retSemanaUsuario++; $totRet++; }
      else { $estado='ASISTIÓ'; $fila['asis']++; $totAsis++; }
      $presentes++; if($esLaborable) $laborables++;
      $fila['dias'][]=['fecha'=>$f,'estado'=>$estado,'entrada'=>$a['hora_entrada'],'salida'=>$a['hora_salida'],'retardo_min'=>$retMin,'dur'=>$dur];
    } else {
      if($isDesc){ $estado='DESCANSO'; $fila['desc']++; $totDesc++; }
      elseif($cerrado){ $estado='CERRADA'; }
      elseif($isPerm){ $estado='PERMISO'; $fila['perm']++; $totPerm++; }
      else { $estado='FALTA'; $fila['fal']++; if($esLaborable) $laborables++; $totFal++; }
      $fila['dias'][]=['fecha'=>$f,'estado'=>$estado,'entrada'=>null,'salida'=>null,'retardo_min'=>0,'dur'=>0];
    }
  }
  if ($retSemanaUsuario >= 3) { $faltasPorRetardos++; }
  $matriz[]=$fila;
}

/* ====== EXPORTACIONES (antes de imprimir cualquier HTML/ navbar) ====== */
if ($isExport) {
  ini_set('display_errors','0'); // evita que warnings contaminen el CSV
  while (ob_get_level()) { ob_end_clean(); }

  header("Content-Type: text/csv; charset=UTF-8");
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  echo "\xEF\xBB\xBF"; // BOM UTF-8

  $type = $_GET['export'];
  $labels=[]; foreach($days as $d){ $labels[] = diaCortoEs($d).' '.$d->format('d/m'); }

  if ($type==='matrix') {
    header("Content-Disposition: attachment; filename=asistencias_matriz_{$weekIso}.csv");
    $out=fopen('php://output','w');
    $head=['Sucursal','Colaborador']; foreach($labels as $l)$head[]=$l;
    $head=array_merge($head,['Asistencias','Retardos','Faltas','Permisos','Descansos','Minutos','Horas','Falta_por_retardos']);
    fputcsv($out,$head);
    foreach($matriz as $fila){
      $row=[$fila['sucursal'],$fila['usuario']];
      foreach($fila['dias'] as $d){
        $estado=$d['estado'];
        if ($estado==='PENDIENTE'){ $row[]='—'; }
        elseif($estado==='RETARDO'){ $row[]='RETARDO +'.($d['retardo_min']??0).'m'; }
        else { $row[]=$estado; }
      }
      $hrs=$fila['min']>0?round($fila['min']/60,2):0;
      $faltaRet = ($fila['ret']>=3)?1:0;
      fputcsv($out, array_merge($row,[$fila['asis'],$fila['ret'],$fila['fal'],$fila['perm'],$fila['desc'],$fila['min'],$hrs,$faltaRet]));
    }
    fclose($out); exit;
  }

  if ($type==='detalles') {
    header("Content-Disposition: attachment; filename=asistencias_detalle_{$weekIso}.csv");
    $out=fopen('php://output','w');
    fputcsv($out, ['Sucursal','Usuario','Fecha','Entrada','Salida','Duración(min)','Estado','Retardo(min)','Lat','Lng','IP']);
    foreach($asistDet as $a){
      $estado=((int)($a['retardo']??0)===1)?'RETARDO':'OK';
      fputcsv($out,[
        $a['sucursal'],
        $a['usuario'], // nombre en CSV
        $a['fecha'],
        $a['hora_entrada'],
        $a['hora_salida'],
        (int)($a['duracion_minutos']??0),
        $estado,
        (int)($a['retardo_minutos']??0),
        $a['latitud']??'',
        $a['longitud']??'',
        $a['ip']??''
      ]);
    }
    fclose($out); exit;
  }

  if ($type==='permisos' && $permisosSemana) {
    header("Content-Disposition: attachment; filename=permisos_semana_{$weekIso}.csv");
    $out=fopen('php://output','w');
    fputcsv($out,['Sucursal','Colaborador','Fecha','Motivo','Comentario','Status','Aprobado por','Aprobado en','Obs.aprobador']);
    foreach($permisosSemana as $p){
      fputcsv($out,[
        $p['sucursal'],$p['usuario'],$p['fecha'],$p['motivo'],$p['comentario']??'',$p['status'],
        $p['aprobado_por']??'',$p['aprobado_en']??'',$p['comentario_aprobador']??''
      ]);
    }
    fclose($out); exit;
  }

  // fallback
  header("Content-Disposition: attachment; filename=export_{$weekIso}.csv");
  $out=fopen('php://output','w'); fputcsv($out,['Sin datos']); fclose($out); exit;
}

/* ====== KPIs ====== */
$empleadosActivos = ($userIds===[0]) ? 0 : count($usuarios);
$puntualidad = ($totAsis+$totRet)>0 ? round(($totAsis/($totAsis+$totRet))*100,1) : 0.0;
$cumplimiento = ($laborables>0) ? round(($presentes / $laborables)*100,1) : 0.0;
$horasTot = $totMin>0 ? round($totMin/60,2) : 0.0;

/* ============ UI ============ */
require_once __DIR__.'/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin · Asistencias (Mar→Lun)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body{ background:#f8fafc; }
    .card-elev{border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.05);}
    .table-xs td, .table-xs th{ padding:.45rem .6rem; font-size:.92rem; }
    .pill{ display:inline-block; padding:.15rem .5rem; border-radius:999px; font-weight:600; font-size:.78rem; }
    .pill-ret{ background:#fff3cd; color:#8a6d3b; border:1px solid #ffeeba; }
    .pill-ok{ background:#e7f5ff; color:#0b7285; border:1px solid #c5e3f6; }
    .pill-warn{ background:#fde2e1; color:#a61e4d; border:1px solid #f8b4b4; }
    .pill-rest{ background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; }
    .pill-closed{ background:#ede9fe; color:#5b21b6; border:1px solid #ddd6fe; }
    .pill-perm{ background:#e2f0d9; color:#2b6a2b; border:1px solid #c7e3be; }
    .pill-future{ background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; } /* PENDIENTE */
    .thead-sticky th{ position:sticky; top:0; background:#111827; color:#fff; z-index:2; }
    .kpi{ border:0; border-radius:1rem; padding:1rem 1.25rem; display:flex; gap:.9rem; align-items:center; }
    .kpi i{ font-size:1.3rem; opacity:.9; }
    .kpi .big{ font-weight:800; font-size:1.35rem; line-height:1; }
    .bg-soft-blue{ background:#e7f5ff; }
    .bg-soft-green{ background:#e6fcf5; }
    .bg-soft-yellow{ background:#fff9db; }
    .bg-soft-red{ background:#ffe3e3; }
    .bg-soft-purple{ background:#f3f0ff; }
    .bg-soft-slate{ background:#f1f5f9; }
  </style>
</head>
<body>
<div class="container my-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h3 class="mb-0"><i class="bi bi-building-fill-gear me-2"></i>Panel Admin · Asistencias</h3>
    <span class="badge text-bg-secondary"><?= h(fmtBadgeRango($tuesdayStart)) ?></span>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-slate"><i class="bi bi-people"></i><div><div class="text-muted small">Colaboradores</div><div class="big"><?= (int)$empleadosActivos ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-green"><i class="bi bi-person-check"></i><div><div class="text-muted small">Presentes</div><div class="big"><?= (int)($totAsis+$totRet) ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-yellow"><i class="bi bi-alarm"></i><div><div class="text-muted small">Retardos</div><div class="big"><?= (int)$totRet ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-red"><i class="bi bi-person-x"></i><div><div class="text-muted small">Faltas</div><div class="big"><?= (int)$totFal ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-purple"><i class="bi bi-clipboard-check"></i><div><div class="text-muted small">Permisos</div><div class="big"><?= (int)$totPerm ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-slate"><i class="bi bi-moon-stars"></i><div><div class="text-muted small">Descansos</div><div class="big"><?= (int)$totDesc ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-blue"><i class="bi bi-clock-history"></i><div><div class="text-muted small">Horas</div><div class="big"><?= number_format($horasTot,2) ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-green"><i class="bi bi-graph-up"></i><div><div class="text-muted small">Puntualidad</div><div class="big"><?= $puntualidad ?>%</div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-3">
      <div class="kpi bg-soft-blue"><i class="bi bi-bullseye"></i><div><div class="text-muted small">Cumplimiento</div><div class="big"><?= $cumplimiento ?>%</div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-3">
      <div class="kpi bg-soft-yellow"><i class="bi bi-exclamation-diamond"></i><div><div class="text-muted small">Falta por 3+ retardos</div><div class="big"><?= (int)$faltasPorRetardos ?></div></div></div>
    </div>
  </div>

  <div class="card card-elev mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-sm-4 col-md-3">
          <label class="form-label mb-0">Semana (Mar→Lun)</label>
          <input type="week" name="week" value="<?= h($weekIso) ?>" class="form-control">
        </div>
        <div class="col-sm-5 col-md-4">
          <label class="form-label mb-0">Sucursal</label>
          <select name="sucursal_id" class="form-select">
            <option value="0">Todas</option>
            <?php foreach($sucursales as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $sucursal_id===(int)$s['id']?'selected':'' ?>><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-3 col-md-2">
          <button class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Export -->
  <div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-outline-success btn-sm" href="?export=matrix&<?= $qsExport ?>"><i class="bi bi-grid-3x3-gap me-1"></i> Exportar matriz</a>
    <a class="btn btn-outline-primary btn-sm" href="?export=detalles&<?= $qsExport ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Exportar detalles</a>
    <a class="btn btn-outline-secondary btn-sm" href="?export=permisos&<?= $qsExport ?>"><i class="bi bi-clipboard-check me-1"></i> Exportar permisos</a>
  </div>

  <!-- MATRIZ -->
  <div class="card card-elev mb-4">
    <div class="card-header fw-bold">Matriz semanal (Mar→Lun) por persona</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-xs align-middle mb-0">
          <thead class="table-dark thead-sticky">
            <tr>
              <th>Sucursal</th><th>Colaborador</th>
              <?php $weekNames=['Mar','Mié','Jue','Vie','Sáb','Dom','Lun']; foreach($days as $idx=>$d): ?>
                <th class="text-center"><?= $weekNames[$idx] ?><br><small><?= $d->format('d/m') ?></small></th>
              <?php endforeach; ?>
              <th class="text-end">Asis.</th><th class="text-end">Ret.</th><th class="text-end">Faltas</th><th class="text-end">Perm.</th><th class="text-end">Desc.</th><th class="text-end">Min</th><th class="text-end">Horas</th><th class="text-center">Falta por retardos</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$matriz): ?>
            <tr><td colspan="<?= 2 + count($days) + 8 ?>" class="text-muted">Sin datos.</td></tr>
          <?php else: foreach($matriz as $fila):
            $hrs=$fila['min']>0? number_format($fila['min']/60,2):'0.00';
            $faltaRet = ($fila['ret']>=3) ? '<span class="badge text-bg-danger">1</span>' : '<span class="badge text-bg-secondary">0</span>';
          ?>
            <tr>
              <td><?= h($fila['sucursal']) ?></td>
              <td class="fw-semibold"><?= h($fila['usuario']) ?></td>
              <?php foreach($fila['dias'] as $d):
                $estado=$d['estado']; $pill='pill-ok'; $txt=$estado;
                if($estado==='RETARDO'){ $pill='pill-ret'; $txt='Retardo'.($d['retardo_min']>0?' +'.$d['retardo_min'].'m':''); }
                elseif($estado==='FALTA'){ $pill='pill-warn'; }
                elseif($estado==='DESCANSO'){ $pill='pill-rest'; }
                elseif($estado==='CERRADA'){ $pill='pill-closed'; }
                elseif($estado==='PERMISO'){ $pill='pill-perm'; }
                elseif($estado==='PENDIENTE'){ $pill='pill-future'; $txt='Pendiente'; }
              ?>
                <td class="text-center">
                  <span class="pill <?= $pill ?>" title="<?= 'Entrada: '.($d['entrada']??'—').' | Salida: '.($d['salida']??'—').' | Dur: '.$d['dur'].'m' ?>"><?= h($txt) ?></span>
                </td>
              <?php endforeach; ?>
              <td class="text-end"><?= (int)$fila['asis'] ?></td>
              <td class="text-end"><?= (int)$fila['ret'] ?></td>
              <td class="text-end"><?= (int)$fila['fal'] ?></td>
              <td class="text-end"><?= (int)$fila['perm'] ?></td>
              <td class="text-end"><?= (int)$fila['desc'] ?></td>
              <td class="text-end"><?= (int)$fila['min'] ?></td>
              <td class="text-end"><?= $hrs ?></td>
              <td class="text-center"><?= $faltaRet ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Detalle asistencias -->
  <div class="card card-elev mb-4">
    <div class="card-header fw-bold">Detalle de asistencias (Mar→Lun)</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-xs align-middle mb-0">
          <thead class="table-dark">
            <tr><th>Sucursal</th><th>Usuario</th><th>Fecha</th><th>Entrada</th><th>Salida</th><th class="text-end">Duración (min)</th><th>Estado</th><th>Retardo(min)</th><th>Mapa</th><th>IP</th></tr>
          </thead>
          <tbody>
          <?php if(!$asistDet): ?>
            <tr><td colspan="10" class="text-muted">Sin registros.</td></tr>
          <?php else: foreach($asistDet as $a): $estado=((int)($a['retardo']??0)===1)?'RETARDO':'OK'; ?>
            <tr class="<?= $a['hora_salida'] ? '' : 'table-warning' ?>">
              <td><?= h($a['sucursal']) ?></td>
              <td><?= h($a['usuario']) ?></td>
              <td><?= h($a['fecha']) ?></td>
              <td><?= h($a['hora_entrada']) ?></td>
              <td><?= $a['hora_salida']?h($a['hora_salida']):'<span class="text-muted">—</span>' ?></td>
              <td class="text-end"><?= (int)($a['duracion_minutos']??0) ?></td>
              <td><?= $estado==='RETARDO'?'<span class="pill pill-ret">RETARDO</span>':'<span class="pill pill-ok">OK</span>' ?></td>
              <td><?= (int)($a['retardo_minutos']??0) ?></td>
              <td><?php if($a['latitud']!==null && $a['longitud']!==null): $url='https://maps.google.com/?q='.urlencode($a['latitud'].','.$a['longitud']); ?>
                <a href="<?= h($url) ?>" target="_blank" class="btn btn-sm btn-outline-primary">Mapa</a>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
              <td><code><?= h($a['ip']??'—') ?></code></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Permisos semana -->
  <div class="card card-elev">
    <div class="card-header fw-bold">Permisos en la semana</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-xs align-middle mb-0">
          <thead class="table-dark"><tr><th>Sucursal</th><th>Colaborador</th><th>Fecha</th><th>Motivo</th><th>Comentario</th><th>Status</th><th>Resuelto por</th><th>Obs.</th></tr></thead>
          <tbody>
          <?php if(!$permisosSemana): ?>
            <tr><td colspan="8" class="text-muted">Sin permisos en esta semana.</td></tr>
          <?php else: foreach($permisosSemana as $p): ?>
            <tr>
              <td><?= h($p['sucursal']) ?></td><td><?= h($p['usuario']) ?></td><td><?= h($p['fecha']) ?></td>
              <td><?= h($p['motivo']) ?></td><td><?= h($p['comentario']??'—') ?></td>
              <td><span class="badge <?= $p['status']==='Aprobado'?'bg-success':($p['status']==='Rechazado'?'bg-danger':'bg-warning text-dark') ?>"><?= h($p['status']) ?></span></td>
              <td><?= $p['aprobado_por'] ? (int)$p['aprobado_por'] : '—' ?></td>
              <td><?= h($p['comentario_aprobador']??'—') ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="d-flex flex-wrap gap-2 mt-3">
    <a class="btn btn-outline-success btn-sm" href="?export=matrix&<?= $qsExport ?>"><i class="bi bi-download me-1"></i> Matriz (CSV)</a>
    <a class="btn btn-outline-primary btn-sm" href="?export=detalles&<?= $qsExport ?>"><i class="bi bi-download me-1"></i> Detalles (CSV)</a>
    <a class="btn btn-outline-secondary btn-sm" href="?export=permisos&<?= $qsExport ?>"><i class="bi bi-download me-1"></i> Permisos (CSV)</a>
  </div>

</div>
</body>
</html>
