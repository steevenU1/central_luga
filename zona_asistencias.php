<?php
// zona_asistencias.php  (Panel Gerente de Zona: Asistencias + Permisos + Export CSV)
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array(($_SESSION['rol'] ?? ''), ['GerenteZona','Admin'], true)) {
  header("Location: 403.php"); exit();
}

date_default_timezone_set('America/Mexico_City');
require_once __DIR__.'/db.php';

$idUsuario  = (int)$_SESSION['id_usuario'];
$rolUser    = $_SESSION['rol'] ?? 'GerenteZona';
$nombreUser = trim($_SESSION['nombre'] ?? 'Gerente Zona');

$isExport = isset($_GET['export']); // <-- clave: si exportamos, NO incluimos navbar ni HTML

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ========= Helpers: Semana operativa Mar→Lun =========
function opWeekStartFromWeekInput(string $iso): ?DateTime {
  if (!preg_match('/^(\d{4})-W(\d{2})$/', $iso, $m)) return null;
  $dt = new DateTime(); $dt->setISODate((int)$m[1], (int)$m[2]); // Lunes ISO
  $dt->modify('+1 day'); // Martes (inicio operativo)
  $dt->setTime(0,0,0); return $dt;
}
function currentOpWeekIso(): string {
  $today = new DateTime('today');
  $dow = (int)$today->format('N'); // 1..7
  $offset = ($dow >= 2) ? $dow - 2 : 6 + $dow; // hasta martes reciente
  $tue = (clone $today)->modify("-{$offset} days");
  $mon = (clone $tue)->modify('-1 day'); // lunes anterior (para <input type=week>)
  return $mon->format('o-\WW');
}
function fmtBadgeRango(DateTime $tueStart): string {
  $dias = ['Mar','Mié','Jue','Vie','Sáb','Dom','Lun'];
  $ini = (clone $tueStart);
  $fin = (clone $tueStart)->modify('+6 day');
  return $dias[0].' '.$ini->format('d/m').' → '.$dias[6].' '.$fin->format('d/m');
}

$msg = '';

// ========= Zona del Gerente (tomada de su sucursal) =========
$zonaAsignada = null;
$stmt = $conn->prepare("
  SELECT s.zona
  FROM usuarios u
  INNER JOIN sucursales s ON s.id = u.id_sucursal
  WHERE u.id=? LIMIT 1
");
$stmt->bind_param('i', $idUsuario);
$stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) {
  $zonaAsignada = trim($row['zona'] ?? '');
}
$stmt->close();

$tieneZona = ($zonaAsignada !== null && $zonaAsignada !== '');

// ========= Filtros =========
$weekIso      = $_GET['week'] ?? currentOpWeekIso();
$tuesdayStart = opWeekStartFromWeekInput($weekIso) ?: new DateTime('tuesday this week');
$start = $tuesdayStart->format('Y-m-d');
$end   = (clone $tuesdayStart)->modify('+6 day')->format('Y-m-d');

// Sucursales visibles (en su zona, sin Eulalia)
if ($tieneZona) {
  $stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE zona=? AND nombre <> 'Eulalia' ORDER BY nombre");
  $stmt->bind_param('s', $zonaAsignada);
  $stmt->execute();
  $sucursalesZona = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
} else {
  $sucursalesZona = $conn->query("SELECT id, nombre FROM sucursales WHERE nombre <> 'Eulalia' ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
}

$sucursal_id = isset($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : 0;
$idsPermitidos = array_map(fn($r)=>(int)$r['id'], $sucursalesZona);
if ($sucursal_id>0 && !in_array($sucursal_id, $idsPermitidos, true)) $sucursal_id = 0;

// QS export (para los links)
$qsExport = http_build_query(['week'=>$weekIso, 'sucursal_id'=>$sucursal_id]);

// ========= Acciones: APROBACIÓN / RECHAZO de PERMISOS (EXISTS; sin LIMIT) =========
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && in_array($_POST['action'], ['perm_aprobar','perm_rechazar'], true)) {
  $pid = (int)($_POST['perm_id'] ?? 0);
  $obs = trim($_POST['perm_obs'] ?? '');
  $st  = ($_POST['action']==='perm_aprobar') ? 'Aprobado' : 'Rechazado';

  if ($pid) {
    if ($tieneZona) {
      $stmt = $conn->prepare("
        UPDATE permisos_solicitudes p
        SET p.status = ?, 
            p.aprobado_por = ?, 
            p.aprobado_en = NOW(), 
            p.comentario_aprobador = ?
        WHERE p.id = ? 
          AND p.status = 'Pendiente'
          AND EXISTS (
            SELECT 1 
            FROM sucursales s 
            WHERE s.id = p.id_sucursal 
              AND s.zona = ? 
              AND s.nombre <> 'Eulalia'
          )
      ");
      $stmt->bind_param('sisis', $st, $idUsuario, $obs, $pid, $zonaAsignada);
    } else {
      $stmt = $conn->prepare("
        UPDATE permisos_solicitudes p
        SET p.status = ?, 
            p.aprobado_por = ?, 
            p.aprobado_en = NOW(), 
            p.comentario_aprobador = ?
        WHERE p.id = ? 
          AND p.status = 'Pendiente'
          AND EXISTS (
            SELECT 1 
            FROM sucursales s 
            WHERE s.id = p.id_sucursal 
              AND s.nombre <> 'Eulalia'
          )
      ");
      $stmt->bind_param('sisi', $st, $idUsuario, $obs, $pid);
    }
    $stmt->execute();
    $rows = $stmt->affected_rows;
    $stmt->close();
    $msg = $rows>0
      ? "<div class='alert alert-success mb-3'>✅ Permiso $st.</div>"
      : "<div class='alert alert-danger mb-3'>No tienes permiso para esa solicitud o ya fue atendida.</div>";
  }
}

// ========= Usuarios activos (en zona y filtro) =========
$paramsU=[]; $typesU='';
$whereU = " WHERE u.activo=1 AND s.nombre <> 'Eulalia' ";
if ($tieneZona) { $whereU .= " AND s.zona=? "; $typesU.='s'; $paramsU[]=$zonaAsignada; }
if ($sucursal_id>0) { $whereU .= " AND s.id=? "; $typesU.='i'; $paramsU[]=$sucursal_id; }

$sqlUsers = "
  SELECT u.id, u.nombre, u.id_sucursal, s.nombre AS sucursal
  FROM usuarios u
  JOIN sucursales s ON s.id = u.id_sucursal
  $whereU
  ORDER BY s.nombre, u.nombre
";
$stmt = $conn->prepare($sqlUsers);
if ($typesU) $stmt->bind_param($typesU, ...$paramsU);
$stmt->execute();
$usuarios = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$userIds = array_map(fn($u)=> (int)$u['id'], $usuarios);
if (!$userIds) $userIds = [0]; // evitar IN() vacío

// ========= Horarios por sucursal =========
$horarios = []; // [id_suc][dow] => [abre,cierra,cerrado]
$resH = $conn->query("SELECT id_sucursal, dia_semana, abre, cierra, cerrado FROM sucursales_horario");
while ($r = $resH->fetch_assoc()) {
  $horarios[(int)$r['id_sucursal']][(int)$r['dia_semana']] = [
    'abre'=>$r['abre'],'cierra'=>$r['cierra'],'cerrado'=>(int)$r['cerrado']
  ];
}

// ========= Descansos programados (semana) =========
$inList = implode(',', array_fill(0, count($userIds), '?'));
$typesD = str_repeat('i', count($userIds)).'ss';
$stmt = $conn->prepare("SELECT id_usuario, fecha FROM descansos_programados WHERE id_usuario IN ($inList) AND fecha BETWEEN ? AND ?");
$stmt->bind_param($typesD, ...array_merge($userIds, [$start,$end]));
$stmt->execute();
$resD = $stmt->get_result();
$descansos = [];
while ($r = $resD->fetch_assoc()) { $descansos[(int)$r['id_usuario']][$r['fecha']] = true; }
$stmt->close();

// ========= PERMISOS (aprobados + pendientes de la semana) =========
$typesPA = str_repeat('i', count($userIds)).'ss';
$stmt = $conn->prepare("SELECT id_usuario, fecha FROM permisos_solicitudes WHERE id_usuario IN ($inList) AND status='Aprobado' AND fecha BETWEEN ? AND ?");
$stmt->bind_param($typesPA, ...array_merge($userIds, [$start,$end]));
$stmt->execute(); $resPA = $stmt->get_result();
$permAprob = [];
while ($r = $resPA->fetch_assoc()) { $permAprob[(int)$r['id_usuario']][$r['fecha']] = true; }
$stmt->close();

$typesPP = str_repeat('i', count($userIds)).'ss';
$stmt = $conn->prepare("SELECT id_usuario, fecha FROM permisos_solicitudes WHERE id_usuario IN ($inList) AND status='Pendiente' AND fecha BETWEEN ? AND ?");
$stmt->bind_param($typesPP, ...array_merge($userIds, [$start,$end]));
$stmt->execute(); $resPP = $stmt->get_result();
$permPend = [];
while ($r = $resPP->fetch_assoc()) { $permPend[(int)$r['id_usuario']][$r['fecha']] = true; }
$stmt->close();

// ========= Asistencias (detalle) =========
$typesA = str_repeat('i', count($userIds)).'ss';
$stmt = $conn->prepare("
  SELECT a.*, s.nombre AS sucursal
  FROM asistencias a
  JOIN sucursales s ON s.id=a.id_sucursal
  WHERE a.id_usuario IN ($inList) AND a.fecha BETWEEN ? AND ?
  ORDER BY a.fecha ASC, a.hora_entrada ASC, a.id ASC
");
$stmt->bind_param($typesA, ...array_merge($userIds, [$start,$end]));
$stmt->execute();
$asistDet = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Index por usuario/día
$asistByUserDay = [];
foreach ($asistDet as $a) {
  $uid = (int)$a['id_usuario'];
  $f   = $a['fecha'];
  if (!isset($asistByUserDay[$uid][$f])) $asistByUserDay[$uid][$f] = $a;
}

// ========= Permisos pendientes (para aprobar) =========
$pendPerm = [];
$typesP = 'ss'; $paramsP = [$start,$end];
$whereP = " WHERE p.status='Pendiente' AND p.fecha BETWEEN ? AND ? ";
if ($tieneZona) { $whereP .= " AND s.zona = ? "; $typesP.='s'; $paramsP[]=$zonaAsignada; }
$whereP .= " AND s.nombre <> 'Eulalia' ";
if ($sucursal_id>0) { $whereP .= " AND s.id = ? "; $typesP.='i'; $paramsP[]=$sucursal_id; }

$sqlP = "
  SELECT p.*, u.nombre AS usuario, s.nombre AS sucursal
  FROM permisos_solicitudes p
  JOIN usuarios u ON u.id=p.id_usuario
  JOIN sucursales s ON s.id=p.id_sucursal
  $whereP
  ORDER BY s.nombre, u.nombre, p.fecha ASC
";
$stmt = $conn->prepare($sqlP);
$stmt->bind_param($typesP, ...$paramsP);
$stmt->execute();
$pendPerm = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ========= Permisos de la semana (todos, para tabla y export) =========
$permisosSemana = [];
$typesPS = 'ss'; $paramsPS = [$start,$end];
$wherePS = " WHERE p.fecha BETWEEN ? AND ? ";
if ($tieneZona) { $wherePS .= " AND s.zona=? "; $typesPS.='s'; $paramsPS[]=$zonaAsignada; }
$wherePS .= " AND s.nombre <> 'Eulalia' ";
if ($sucursal_id>0) { $wherePS .= " AND s.id=? "; $typesPS.='i'; $paramsPS[]=$sucursal_id; }

$sqlPS = "
  SELECT p.*, u.nombre AS usuario, s.nombre AS sucursal
  FROM permisos_solicitudes p
  JOIN usuarios u ON u.id=p.id_usuario
  JOIN sucursales s ON s.id=p.id_sucursal
  $wherePS
  ORDER BY s.nombre, u.nombre, p.fecha DESC
";
$stmt = $conn->prepare($sqlPS);
$stmt->bind_param($typesPS, ...$paramsPS);
$stmt->execute();
$permisosSemana = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ========= Construcción de MATRIZ y RESUMEN POR SUCURSAL =========
$days=[]; for($i=0;$i<7;$i++){ $d=clone $tuesdayStart; $d->modify("+$i day"); $days[]=$d; }
$weekNames=['Mar','Mié','Jue','Vie','Sáb','Dom','Lun'];

$matriz=[];
foreach($usuarios as $u){
  $uid=(int)$u['id']; $sid=(int)$u['id_sucursal'];
  $fila=['usuario'=>$u['nombre'],'sucursal'=>$u['sucursal'],'dias'=>[],'asis'=>0,'ret'=>0,'fal'=>0,'perm'=>0,'desc'=>0,'min'=>0];

  foreach($days as $d){
    $f=$d->format('Y-m-d'); $dow=(int)$d->format('N');
    $hor=$horarios[$sid][$dow]??null; $cerrado=$hor?((int)$hor['cerrado']===1):false;
    $isDesc=!empty($descansos[$uid][$f]);
    $isPermA=!empty($permAprob[$uid][$f]);
    $isPermP=!empty($permPend[$uid][$f]);
    $a=$asistByUserDay[$uid][$f]??null;

    if($a){
      $ret=(int)($a['retardo']??0); $retMin=(int)($a['retardo_minutos']??0); $dur=(int)($a['duracion_minutos']??0);
      $fila['min'] += $dur;
      if($ret===1){ $estado='RETARDO'; $fila['ret']++; } else { $estado='ASISTIÓ'; $fila['asis']++; }
      $fila['dias'][]=['fecha'=>$f,'estado'=>$estado,'entrada'=>$a['hora_entrada'],'salida'=>$a['hora_salida'],'retardo_min'=>$retMin,'dur'=>$dur];
    } else {
      if($isDesc){ $estado='DESCANSO'; $fila['desc']++; }
      elseif($cerrado){ $estado='CERRADA'; }
      elseif($isPermA){ $estado='PERMISO'; $fila['perm']++; }
      elseif($isPermP){ $estado='PEND. PERM'; $fila['fal']++; } // pendiente aún cuenta como falta
      else { $estado='FALTA'; $fila['fal']++; }
      $fila['dias'][]=['fecha'=>$f,'estado'=>$estado,'entrada'=>null,'salida'=>null,'retardo_min'=>0,'dur'=>0];
    }
  }
  $matriz[]=$fila;
}

// Resumen por sucursal (agregado a partir de la matriz)
$resumenSuc = []; // sucursal => totales
foreach ($matriz as $fila) {
  $suc = $fila['sucursal'];
  if (!isset($resumenSuc[$suc])) {
    $resumenSuc[$suc] = ['asis'=>0,'ret'=>0,'fal'=>0,'perm'=>0,'desc'=>0,'min'=>0,'falta_retardos'=>0];
  }
  $resumenSuc[$suc]['asis'] += (int)$fila['asis'];
  $resumenSuc[$suc]['ret']  += (int)$fila['ret'];
  $resumenSuc[$suc]['fal']  += (int)$fila['fal'];
  $resumenSuc[$suc]['perm'] += (int)$fila['perm'];
  $resumenSuc[$suc]['desc'] += (int)$fila['desc'];
  $resumenSuc[$suc]['min']  += (int)$fila['min'];
  if ((int)$fila['ret'] >= 3) $resumenSuc[$suc]['falta_retardos'] += 1; // por persona/semana
}

// ========= EXPORT CSV (importante: antes de cualquier HTML y SIN navbar) =========
if ($isExport) {
  // Evita que cualquier output previo arruine los headers
  while (ob_get_level()) { ob_end_clean(); }
  header("Content-Type: text/csv; charset=UTF-8");
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  echo "\xEF\xBB\xBF"; // BOM UTF-8

  $type = $_GET['export'] ?? '';
  // Labels de días
  $labels=[]; foreach($days as $d){ $labels[]=$d->format('D d/m'); }

  if ($type==='matrix') {
    header("Content-Disposition: attachment; filename=zona_matriz_{$weekIso}.csv");
    $out=fopen('php://output','w');
    $head=['Sucursal','Colaborador']; foreach($labels as $l)$head[]=$l;
    $head=array_merge($head,['Asistencias','Retardos','Faltas','Permisos','Descansos','Minutos','Horas','Falta_por_retardos']);
    fputcsv($out,$head);
    foreach($matriz as $fila){
      $row=[$fila['sucursal'],$fila['usuario']];
      foreach($fila['dias'] as $d){
        $estado=$d['estado'];
        if($estado==='RETARDO'){ $row[]='RETARDO +'.($d['retardo_min']??0).'m'; }
        else { $row[]=$estado; }
      }
      $hrs=$fila['min']>0?round($fila['min']/60,2):0;
      $faltaRet = ($fila['ret']>=3)?1:0;
      fputcsv($out, array_merge($row,[$fila['asis'],$fila['ret'],$fila['fal'],$fila['perm'],$fila['desc'],$fila['min'],$hrs,$faltaRet]));
    }
    fclose($out); exit;
  }

  if ($type==='detalles') {
    header("Content-Disposition: attachment; filename=zona_detalles_{$weekIso}.csv");
    $out=fopen('php://output','w');
    fputcsv($out,['Sucursal','Usuario','Fecha','Entrada','Salida','Duración(min)','Estado','Retardo(min)','Lat','Lng','IP']);
    foreach($asistDet as $a){
      $estado=((int)($a['retardo']??0)===1)?'RETARDO':'OK';
      fputcsv($out,[$a['sucursal'],$a['id_usuario'],$a['fecha'],$a['hora_entrada'],$a['hora_salida'],
        (int)($a['duracion_minutos']??0),$estado,(int)($a['retardo_minutos']??0),
        $a['latitud']??'',$a['longitud']??'',$a['ip']??'']);
    }
    fclose($out); exit;
  }

  if ($type==='permisos') {
    header("Content-Disposition: attachment; filename=zona_permisos_{$weekIso}.csv");
    $out=fopen('php://output','w');
    fputcsv($out,['Sucursal','Colaborador','Fecha','Motivo','Comentario','Status','Aprobado por','Aprobado en','Obs.aprobador']);
    foreach($permisosSemana as $p){
      fputcsv($out,[$p['sucursal'],$p['usuario'],$p['fecha'],$p['motivo'],$p['comentario']??'',
        $p['status'],$p['aprobado_por']??'',$p['aprobado_en']??'',$p['comentario_aprobador']??'']);
    }
    fclose($out); exit;
  }

  if ($type==='resumen') {
    header("Content-Disposition: attachment; filename=zona_resumen_sucursal_{$weekIso}.csv");
    $out=fopen('php://output','w');
    fputcsv($out,['Sucursal','Asistencias','Retardos','Faltas','Permisos','Descansos','Minutos','Horas','Falta_por_retardos']);
    foreach($resumenSuc as $suc=>$tot){
      $hrs = $tot['min']>0? round($tot['min']/60,2) : 0;
      fputcsv($out,[$suc,$tot['asis'],$tot['ret'],$tot['fal'],$tot['perm'],$tot['desc'],$tot['min'],$hrs,$tot['falta_retardos']]);
    }
    fclose($out); exit;
  }

  // Tipo no reconocido
  header("Content-Disposition: attachment; filename=export_{$weekIso}.csv");
  $out=fopen('php://output','w'); fputcsv($out,['Sin datos']); fclose($out); exit;
}

// ============== A partir de aquí SOLO UI (no se ejecuta en export) ==============
require_once __DIR__.'/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Gerente de Zona · Asistencias & Permisos</title>
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
    .pill-pending{ background:#f0eefc; color:#4c1d95; border:1px solid #e0d9ff; }
    .thead-sticky th{ position:sticky; top:0; background:#111827; color:#fff; z-index:2; }
  </style>
</head>
<body>
<div class="container my-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h3 class="mb-0"><i class="bi bi-diagram-3-fill me-2"></i>Panel Gerente de Zona</h3>
    <div class="d-flex align-items-center gap-2">
      <?php if ($tieneZona): ?>
        <span class="badge text-bg-primary">Zona: <?= h($zonaAsignada) ?></span>
      <?php else: ?>
        <span class="badge text-bg-warning text-dark">Zona no definida</span>
      <?php endif; ?>
      <span class="badge text-bg-secondary"><?= h(fmtBadgeRango($tuesdayStart)) ?></span>
    </div>
  </div>

  <?= $msg ?>

  <div class="card card-elev mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-sm-4 col-md-3">
          <label class="form-label mb-0">Semana (Mar→Lun)</label>
          <input type="week" name="week" value="<?= h($weekIso) ?>" class="form-control">
        </div>
        <div class="col-sm-5 col-md-4">
          <label class="form-label mb-0">Sucursal (zona)</label>
          <select name="sucursal_id" class="form-select">
            <option value="0">Todas (sin Eulalia)</option>
            <?php foreach($sucursalesZona as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $sucursal_id===(int)$s['id']?'selected':'' ?>>
                <?= h($s['nombre']) ?>
              </option>
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
    <a class="btn btn-outline-info btn-sm" href="?export=resumen&<?= $qsExport ?>"><i class="bi bi-bar-chart-line me-1"></i> Exportar resumen sucursal</a>
  </div>

  <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="p-asist-tab" data-bs-toggle="pill" data-bs-target="#p-asist" type="button" role="tab">
        <i class="bi bi-people me-1"></i> Asistencias
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="p-perm-tab" data-bs-toggle="pill" data-bs-target="#p-perm" type="button" role="tab">
        <i class="bi bi-inboxes"></i> Permisos (pendientes)
        <?php if(count($pendPerm)>0): ?><span class="badge bg-danger ms-1"><?= count($pendPerm) ?></span><?php endif; ?>
      </button>
    </li>
  </ul>

  <div class="tab-content">
    <!-- ========== TAB ASISTENCIAS (Resumen + Matriz + Detalle) ========== -->
    <div class="tab-pane fade show active" id="p-asist" role="tabpanel">

      <!-- Resumen por sucursal -->
      <div class="card card-elev mb-3">
        <div class="card-header fw-bold">Resumen por sucursal (semana Mar→Lun)</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped table-xs align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Sucursal</th>
                  <th class="text-end">Asistencias</th>
                  <th class="text-end">Retardos</th>
                  <th class="text-end">Faltas</th>
                  <th class="text-end">Permisos</th>
                  <th class="text-end">Descansos</th>
                  <th class="text-end">Minutos</th>
                  <th class="text-end">Horas</th>
                  <th class="text-center">Falta por retardos</th>
                </tr>
              </thead>
              <tbody>
              <?php if(!$resumenSuc): ?>
                <tr><td colspan="9" class="text-muted">Sin datos.</td></tr>
              <?php else: foreach($resumenSuc as $suc=>$tot):
                $hrs = $tot['min']>0 ? number_format($tot['min']/60,2) : '0.00'; ?>
                <tr>
                  <td class="fw-semibold"><?= h($suc) ?></td>
                  <td class="text-end"><?= (int)$tot['asis'] ?></td>
                  <td class="text-end"><?= (int)$tot['ret'] ?></td>
                  <td class="text-end"><?= (int)$tot['fal'] ?></td>
                  <td class="text-end"><?= (int)$tot['perm'] ?></td>
                  <td class="text-end"><?= (int)$tot['desc'] ?></td>
                  <td class="text-end"><?= (int)$tot['min'] ?></td>
                  <td class="text-end"><?= $hrs ?></td>
                  <td class="text-center">
                    <?= $tot['falta_retardos']>0 ? '<span class="badge text-bg-danger">'.(int)$tot['falta_retardos'].'</span>' : '<span class="badge text-bg-secondary">0</span>' ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Matriz semanal -->
      <div class="card card-elev mb-3">
        <div class="card-header fw-bold">Matriz semanal (Mar→Lun) por persona</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped table-xs align-middle mb-0">
              <thead class="table-dark thead-sticky">
                <tr>
                  <th>Sucursal</th><th>Colaborador</th>
                  <?php foreach ($days as $idx=>$d): ?>
                    <th class="text-center"><?= $weekNames[$idx] ?><br><small><?= $d->format('d/m') ?></small></th>
                  <?php endforeach; ?>
                  <th class="text-end">Asis.</th><th class="text-end">Ret.</th><th class="text-end">Faltas</th><th class="text-end">Perm.</th><th class="text-end">Desc.</th><th class="text-end">Min</th><th class="text-end">Horas</th><th class="text-center">Falta por retardos</th>
                </tr>
              </thead>
              <tbody>
              <?php $colspan = 2 + count($days) + 8; ?>
              <?php if(!$matriz): ?>
                <tr><td colspan="<?= $colspan ?>" class="text-muted">Sin datos.</td></tr>
              <?php else: foreach($matriz as $fila):
                $hrs = $fila['min']>0? number_format($fila['min']/60,2):'0.00';
                $faltaRet = ($fila['ret']>=3) ? '<span class="badge text-bg-danger">1</span>' : '<span class="badge text-bg-secondary">0</span>';
              ?>
                <tr>
                  <td><?= h($fila['sucursal']) ?></td>
                  <td class="fw-semibold"><?= h($fila['usuario']) ?></td>
                  <?php foreach ($fila['dias'] as $d):
                    $estado=$d['estado']; $pill='pill-ok'; $txt=$estado;
                    if($estado==='RETARDO'){ $pill='pill-ret'; $txt='Retardo'.($d['retardo_min']>0?' +'.$d['retardo_min'].'m':''); }
                    elseif($estado==='FALTA'){ $pill='pill-warn'; }
                    elseif($estado==='DESCANSO'){ $pill='pill-rest'; }
                    elseif($estado==='CERRADA'){ $pill='pill-closed'; }
                    elseif($estado==='PERMISO'){ $pill='pill-perm'; }
                    elseif($estado==='PEND. PERM'){ $pill='pill-pending'; }
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

      <!-- Detalle de asistencias -->
      <div class="card card-elev">
        <div class="card-header fw-bold">Detalle de asistencias (Mar→Lun)</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-xs align-middle mb-0">
              <thead class="table-dark">
                <tr><th>Sucursal</th><th>Usuario(ID)</th><th>Fecha</th><th>Entrada</th><th>Salida</th><th class="text-end">Duración (min)</th><th>Estado</th><th>Retardo(min)</th><th>Mapa</th><th>IP</th></tr>
              </thead>
              <tbody>
              <?php if(!$asistDet): ?>
                <tr><td colspan="10" class="text-muted">Sin registros.</td></tr>
              <?php else: foreach($asistDet as $a): $estado=((int)($a['retardo']??0)===1)?'RETARDO':'OK'; ?>
                <tr class="<?= $a['hora_salida'] ? '' : 'table-warning' ?>">
                  <td><?= h($a['sucursal']) ?></td>
                  <td><?= (int)$a['id_usuario'] ?></td>
                  <td><?= h($a['fecha']) ?></td>
                  <td><?= h($a['hora_entrada']) ?></td>
                  <td><?= $a['hora_salida']?h($a['hora_salida']):'<span class="text-muted">—</span>' ?></td>
                  <td class="text-end"><?= (int)($a['duracion_minutos']??0) ?></td>
                  <td><?= $estado==='RETARDO'?'<span class="pill pill-ret">RETARDO</span>':'<span class="pill pill-ok">OK</span>' ?></td>
                  <td><?= (int)($a['retardo_minutos']??0) ?></td>
                  <td>
                    <?php if($a['latitud']!==null && $a['longitud']!==null):
                      $url='https://maps.google.com/?q='.urlencode($a['latitud'].','.$a['longitud']); ?>
                      <a href="<?= h($url) ?>" target="_blank" class="btn btn-sm btn-outline-primary">Mapa</a>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                  </td>
                  <td><code><?= h($a['ip']??'—') ?></code></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div><!-- /tab asistencias -->

    <!-- ========== TAB PERMISOS (Pendientes + histórico semana) ========== -->
    <div class="tab-pane fade" id="p-perm" role="tabpanel">
      <div class="card card-elev mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <b>Permisos pendientes de aprobación</b>
          <span class="badge bg-danger"><?= count($pendPerm) ?></span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-xs align-middle mb-0">
              <thead class="table-dark">
                <tr><th>Sucursal</th><th>Colaborador</th><th>Fecha</th><th>Motivo</th><th>Comentario</th><th class="text-end">Acciones</th></tr>
              </thead>
              <tbody>
              <?php if(!$pendPerm): ?>
                <tr><td colspan="6" class="text-muted">Sin pendientes.</td></tr>
              <?php else: foreach($pendPerm as $p): ?>
                <tr>
                  <td><?= h($p['sucursal']) ?></td>
                  <td><?= h($p['usuario']) ?></td>
                  <td><?= h($p['fecha']) ?></td>
                  <td><?= h($p['motivo']) ?></td>
                  <td><?= h($p['comentario'] ?? '—') ?></td>
                  <td class="text-end">
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="perm_aprobar">
                      <input type="hidden" name="perm_id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="perm_obs" value="">
                      <button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Aprobar</button>
                    </form>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="perm_rechazar">
                      <input type="hidden" name="perm_id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="perm_obs" value="">
                      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Rechazar</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Permisos de la semana (todos los estados) -->
      <div class="card card-elev">
        <div class="card-header fw-bold">Permisos en la semana</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped table-xs align-middle mb-0">
              <thead class="table-dark">
                <tr><th>Sucursal</th><th>Colaborador</th><th>Fecha</th><th>Motivo</th><th>Comentario</th><th>Status</th><th>Resuelto por</th><th>Obs.</th></tr>
              </thead>
              <tbody>
              <?php if(!$permisosSemana): ?>
                <tr><td colspan="8" class="text-muted">Sin permisos en esta semana.</td></tr>
              <?php else: foreach($permisosSemana as $p): ?>
                <tr>
                  <td><?= h($p['sucursal']) ?></td>
                  <td><?= h($p['usuario']) ?></td>
                  <td><?= h($p['fecha']) ?></td>
                  <td><?= h($p['motivo']) ?></td>
                  <td><?= h($p['comentario'] ?? '—') ?></td>
                  <td><span class="badge <?= $p['status']==='Aprobado'?'bg-success':($p['status']==='Rechazado'?'bg-danger':'bg-warning text-dark') ?>"><?= h($p['status']) ?></span></td>
                  <td><?= $p['aprobado_por'] ? (int)$p['aprobado_por'] : '—' ?></td>
                  <td><?= h($p['comentario_aprobador'] ?? '—') ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div><!-- /tab permisos -->
  </div><!-- /tab-content -->
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
