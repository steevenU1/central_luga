<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

/* =========================================================
   CONTEXTO
========================================================= */
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ROL         = trim((string)($_SESSION['rol'] ?? ''));
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

/* =========================================================
   PERMISOS
========================================================= */
$ROLES_PERMITIDOS = [
    'Admin', 'Administrador', 'Auditor', 'Logistica', 'GerenteZona',
    'Gerente', 'Supervisor'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para conciliar auditorías.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmtFecha(?string $f): string {
    if (!$f || $f === '0000-00-00' || $f === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($f);
    return $ts ? date('d/m/Y H:i', $ts) : $f;
}

function diffColor($d): string {
    if ($d === null) return '#6b7280';
    $d = (int)$d;
    if ($d > 0) return '#065f46';
    if ($d < 0) return '#991b1b';
    return '#111827';
}

/* =========================================================
   ID AUDITORIA
========================================================= */
$id_auditoria = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id_auditoria'] ?? 0);
if ($id_auditoria <= 0) {
    exit('Auditoría no válida.');
}

/* =========================================================
   CARGAR AUDITORIA
========================================================= */
$stmtAud = $conn->prepare("
    SELECT 
        a.*,
        s.nombre AS sucursal_nombre,
        s.zona AS sucursal_zona,
        s.tipo_sucursal,
        u1.nombre AS auditor_nombre,
        u2.nombre AS gerente_nombre
    FROM auditorias a
    INNER JOIN sucursales s ON s.id = a.id_sucursal
    INNER JOIN usuarios u1 ON u1.id = a.id_auditor
    LEFT JOIN usuarios u2 ON u2.id = a.id_gerente
    WHERE a.id = ?
    LIMIT 1
");
$stmtAud->bind_param("i", $id_auditoria);
$stmtAud->execute();
$auditoria = $stmtAud->get_result()->fetch_assoc();

if (!$auditoria) {
    exit('La auditoría no existe.');
}

/* Restricción básica por sucursal */
if (in_array($ROL, ['Gerente', 'Supervisor'], true) && (int)$auditoria['id_sucursal'] !== $ID_SUCURSAL) {
    http_response_code(403);
    exit('No puedes consultar una auditoría de otra sucursal.');
}

$errores = [];
$ok = '';

/* =========================================================
   CIERRE DE AUDITORIA
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cerrar_auditoria'])) {
    if (($auditoria['estatus'] ?? '') === 'Cerrada') {
        $errores[] = 'La auditoría ya se encuentra cerrada.';
    } else {
        $observaciones_cierre = trim((string)($_POST['observaciones_cierre'] ?? ''));

        try {
            $conn->begin_transaction();

            /* ============================================
               1) GENERAR / REFRESCAR FALTANTES UNITARIOS
            ============================================ */
            $stmtDelFal = $conn->prepare("
                DELETE FROM auditorias_faltantes
                WHERE id_auditoria = ?
            ");
            $stmtDelFal->bind_param("i", $id_auditoria);
            $stmtDelFal->execute();

            $stmtInsFal = $conn->prepare("
                INSERT INTO auditorias_faltantes (
                    id_auditoria,
                    id_snapshot,
                    id_inventario,
                    id_producto,
                    imei1,
                    imei2,
                    codigo_producto,
                    marca,
                    modelo,
                    color,
                    capacidad,
                    estatus,
                    observaciones
                )
                SELECT
                    s.id_auditoria,
                    s.id,
                    s.id_inventario,
                    s.id_producto,
                    s.imei1,
                    s.imei2,
                    s.codigo_producto,
                    s.marca,
                    s.modelo,
                    s.color,
                    s.capacidad,
                    'Pendiente',
                    'No escaneado al momento del cierre'
                FROM auditorias_snapshot s
                WHERE s.id_auditoria = ?
                  AND s.escaneado = 0
            ");
            $stmtInsFal->bind_param("i", $id_auditoria);
            $stmtInsFal->execute();

            /* ============================================
               2) RECALCULAR TOTALES UNITARIOS
            ============================================ */
            $stmtTotUnit = $conn->prepare("
                SELECT
                    COUNT(*) AS total_esperados,
                    SUM(CASE WHEN escaneado = 1 THEN 1 ELSE 0 END) AS total_ok,
                    SUM(CASE WHEN escaneado = 0 THEN 1 ELSE 0 END) AS total_faltantes
                FROM auditorias_snapshot
                WHERE id_auditoria = ?
            ");
            $stmtTotUnit->bind_param("i", $id_auditoria);
            $stmtTotUnit->execute();
            $totUnit = $stmtTotUnit->get_result()->fetch_assoc();

            $totalSnapshot  = (int)($totUnit['total_esperados'] ?? 0);
            $totalOk        = (int)($totUnit['total_ok'] ?? 0);
            $totalFaltantes = (int)($totUnit['total_faltantes'] ?? 0);

            /* ============================================
               3) RECALCULAR TOTALES DE CANTIDADES
            ============================================ */
            $stmtTotCant = $conn->prepare("
                SELECT
                    COUNT(*) AS total_lineas,
                    SUM(CASE WHEN cantidad_contada IS NOT NULL THEN 1 ELSE 0 END) AS total_contadas,
                    SUM(CASE WHEN diferencia IS NOT NULL AND diferencia <> 0 THEN 1 ELSE 0 END) AS total_con_diferencia
                FROM auditorias_snapshot_cantidades
                WHERE id_auditoria = ?
            ");
            $stmtTotCant->bind_param("i", $id_auditoria);
            $stmtTotCant->execute();
            $totCant = $stmtTotCant->get_result()->fetch_assoc();

            $totalLineasAccesorios      = (int)($totCant['total_lineas'] ?? 0);
            $totalAccesoriosContados    = (int)($totCant['total_contadas'] ?? 0);
            $totalAccesoriosDiferencia  = (int)($totCant['total_con_diferencia'] ?? 0);

            /* ============================================
               4) CONTAR ESCANEOS / INCIDENCIAS
            ============================================ */
            $stmtEsc = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM auditorias_escaneos
                WHERE id_auditoria = ?
            ");
            $stmtEsc->bind_param("i", $id_auditoria);
            $stmtEsc->execute();
            $totalEscaneados = (int)($stmtEsc->get_result()->fetch_assoc()['total'] ?? 0);

            $stmtInc = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM auditorias_incidencias
                WHERE id_auditoria = ?
            ");
            $stmtInc->bind_param("i", $id_auditoria);
            $stmtInc->execute();
            $totalIncidencias = (int)($stmtInc->get_result()->fetch_assoc()['total'] ?? 0);

            /* ============================================
               5) CERRAR CABECERA
            ============================================ */
            $stmtUpdAud = $conn->prepare("
                UPDATE auditorias
                SET
                    estatus = 'Cerrada',
                    fecha_cierre = NOW(),
                    observaciones_cierre = ?,
                    total_snapshot = ?,
                    total_escaneados = ?,
                    total_ok = ?,
                    total_faltantes = ?,
                    total_incidencias = ?,
                    total_lineas_accesorios = ?,
                    total_accesorios_contados = ?,
                    total_accesorios_con_diferencia = ?,
                    cerrada_por = ?
                WHERE id = ?
            ");
            $stmtUpdAud->bind_param(
                "siiiiiiiiii",
                $observaciones_cierre,
                $totalSnapshot,
                $totalEscaneados,
                $totalOk,
                $totalFaltantes,
                $totalIncidencias,
                $totalLineasAccesorios,
                $totalAccesoriosContados,
                $totalAccesoriosDiferencia,
                $ID_USUARIO,
                $id_auditoria
            );
            $stmtUpdAud->execute();

            /* ============================================
               6) BITÁCORA
            ============================================ */
            $accion = 'Cerrar auditoria';
            $detalle = 'Se cerró la auditoría y se consolidaron los resultados finales.';
            $datosExtra = json_encode([
                'total_snapshot' => $totalSnapshot,
                'total_escaneados' => $totalEscaneados,
                'total_ok' => $totalOk,
                'total_faltantes' => $totalFaltantes,
                'total_incidencias' => $totalIncidencias,
                'total_lineas_accesorios' => $totalLineasAccesorios,
                'total_accesorios_contados' => $totalAccesoriosContados,
                'total_accesorios_con_diferencia' => $totalAccesoriosDiferencia
            ], JSON_UNESCAPED_UNICODE);

            $stmtBit = $conn->prepare("
                INSERT INTO auditorias_bitacora (
                    id_auditoria, accion, detalle, datos_extra, realizado_por, fecha_evento
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmtBit->bind_param("isssi", $id_auditoria, $accion, $detalle, $datosExtra, $ID_USUARIO);
            $stmtBit->execute();

            $conn->commit();
            $ok = 'La auditoría fue cerrada correctamente.';

        } catch (Throwable $e) {
            $conn->rollback();
            $errores[] = 'Error al cerrar la auditoría: ' . $e->getMessage();
        }

        /* Recargar auditoría */
        $stmtAud->execute();
        $auditoria = $stmtAud->get_result()->fetch_assoc();
    }
}

/* =========================================================
   KPIS UNITARIOS
========================================================= */
$stmtKpiUnit = $conn->prepare("
    SELECT
        COUNT(*) AS total_esperados,
        SUM(CASE WHEN escaneado = 1 THEN 1 ELSE 0 END) AS encontrados,
        SUM(CASE WHEN escaneado = 0 THEN 1 ELSE 0 END) AS pendientes
    FROM auditorias_snapshot
    WHERE id_auditoria = ?
");
$stmtKpiUnit->bind_param("i", $id_auditoria);
$stmtKpiUnit->execute();
$kpiUnit = $stmtKpiUnit->get_result()->fetch_assoc() ?: [
    'total_esperados' => 0,
    'encontrados' => 0,
    'pendientes' => 0
];

/* =========================================================
   KPIS CANTIDADES
========================================================= */
$stmtKpiCant = $conn->prepare("
    SELECT
        COUNT(*) AS total_lineas,
        SUM(CASE WHEN cantidad_contada IS NOT NULL THEN 1 ELSE 0 END) AS contadas,
        SUM(CASE WHEN diferencia IS NOT NULL AND diferencia = 0 THEN 1 ELSE 0 END) AS sin_diferencia,
        SUM(CASE WHEN diferencia IS NOT NULL AND diferencia <> 0 THEN 1 ELSE 0 END) AS con_diferencia
    FROM auditorias_snapshot_cantidades
    WHERE id_auditoria = ?
");
$stmtKpiCant->bind_param("i", $id_auditoria);
$stmtKpiCant->execute();
$kpiCant = $stmtKpiCant->get_result()->fetch_assoc() ?: [
    'total_lineas' => 0,
    'contadas' => 0,
    'sin_diferencia' => 0,
    'con_diferencia' => 0
];

/* =========================================================
   FALTANTES UNITARIOS
========================================================= */
$stmtFaltantes = $conn->prepare("
    SELECT *
    FROM auditorias_snapshot
    WHERE id_auditoria = ?
      AND escaneado = 0
    ORDER BY marca ASC, modelo ASC, color ASC, capacidad ASC
");
$stmtFaltantes->bind_param("i", $id_auditoria);
$stmtFaltantes->execute();
$faltantes = $stmtFaltantes->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   DIFERENCIAS EN CANTIDADES
========================================================= */
$stmtDiff = $conn->prepare("
    SELECT *
    FROM auditorias_snapshot_cantidades
    WHERE id_auditoria = ?
      AND diferencia IS NOT NULL
      AND diferencia <> 0
    ORDER BY marca ASC, modelo ASC, color ASC, capacidad ASC
");
$stmtDiff->bind_param("i", $id_auditoria);
$stmtDiff->execute();
$diferencias = $stmtDiff->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   INCIDENCIAS
========================================================= */
$stmtIncList = $conn->prepare("
    SELECT *
    FROM auditorias_incidencias
    WHERE id_auditoria = ?
    ORDER BY id DESC
");
$stmtIncList->bind_param("i", $id_auditoria);
$stmtIncList->execute();
$incidencias = $stmtIncList->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   BITÁCORA RECIENTE
========================================================= */
$stmtBitList = $conn->prepare("
    SELECT b.*, u.nombre AS usuario_nombre
    FROM auditorias_bitacora b
    LEFT JOIN usuarios u ON u.id = b.realizado_por
    WHERE b.id_auditoria = ?
    ORDER BY b.id DESC
    LIMIT 12
");
$stmtBitList->bind_param("i", $id_auditoria);
$stmtBitList->execute();
$bitacora = $stmtBitList->get_result()->fetch_all(MYSQLI_ASSOC);

$estaCerrada = (($auditoria['estatus'] ?? '') === 'Cerrada');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Conciliación de Auditoría</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{
            margin:0;
            background:#f5f7fb;
            font-family:Arial, Helvetica, sans-serif;
            color:#1f2937;
        }
        .wrap{
            max-width:1450px;
            margin:28px auto;
            padding:0 16px 40px;
        }
        .header{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:18px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }
        .title{
            margin:0;
            font-size:30px;
            font-weight:800;
        }
        .subtitle{
            margin-top:6px;
            color:#6b7280;
            font-size:14px;
        }
        .badges{
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            margin-top:10px;
        }
        .badge{
            display:inline-block;
            padding:7px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            background:#eef2ff;
            color:#3730a3;
        }
        .badge.gray{ background:#f3f4f6; color:#374151; }
        .badge.green{ background:#ecfdf5; color:#065f46; }
        .badge.red{ background:#fef2f2; color:#991b1b; }
        .actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }
        .btn{
            border:none;
            border-radius:14px;
            padding:13px 18px;
            font-weight:800;
            cursor:pointer;
            font-size:14px;
        }
        .btn-primary{
            background:#111827;
            color:#fff;
        }
        .btn-danger{
            background:#991b1b;
            color:#fff;
        }
        .btn-secondary{
            background:#e5e7eb;
            color:#111827;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
        }
        .card{
            background:#fff;
            border-radius:18px;
            box-shadow:0 10px 24px rgba(0,0,0,.06);
            padding:20px;
            margin-bottom:18px;
        }
        .section-title{
            margin:0 0 14px;
            font-size:20px;
            font-weight:800;
        }
        .grid{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
            gap:16px;
        }
        .mini-grid{
            display:grid;
            grid-template-columns:repeat(2, 1fr);
            gap:16px;
        }
        .kpi{
            background:linear-gradient(180deg,#ffffff 0%, #f9fafb 100%);
            border:1px solid #e5e7eb;
            border-radius:16px;
            padding:18px;
        }
        .kpi-label{
            font-size:13px;
            color:#6b7280;
            margin-bottom:8px;
        }
        .kpi-value{
            font-size:30px;
            font-weight:800;
            color:#111827;
        }
        .alert{
            border-radius:14px;
            padding:14px 16px;
            margin-bottom:16px;
        }
        .alert-danger{
            background:#fef2f2;
            border:1px solid #fecaca;
            color:#991b1b;
        }
        .alert-success{
            background:#ecfdf5;
            border:1px solid #a7f3d0;
            color:#065f46;
        }
        .alert-warn{
            background:#fff7ed;
            border:1px solid #fdba74;
            color:#9a3412;
        }
        .table-wrap{
            overflow:auto;
            border:1px solid #e5e7eb;
            border-radius:16px;
        }
        table{
            width:100%;
            border-collapse:collapse;
            min-width:1000px;
            background:#fff;
        }
        thead th{
            background:#111827;
            color:#fff;
            font-size:13px;
            text-align:left;
            padding:12px 10px;
            white-space:nowrap;
        }
        tbody td{
            border-bottom:1px solid #eef2f7;
            padding:10px;
            font-size:13px;
            vertical-align:top;
        }
        tbody tr:hover{
            background:#f9fafb;
        }
        .pill{
            display:inline-block;
            padding:6px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            white-space:nowrap;
        }
        .pill-red{ background:#fef2f2; color:#991b1b; }
        .pill-green{ background:#ecfdf5; color:#065f46; }
        .pill-gray{ background:#f3f4f6; color:#374151; }
        .field{
            display:flex;
            flex-direction:column;
            gap:8px;
        }
        textarea{
            width:100%;
            min-height:110px;
            box-sizing:border-box;
            border:1px solid #d1d5db;
            border-radius:14px;
            padding:12px 14px;
            resize:vertical;
            font-size:14px;
        }
        .notice{
            margin-top:12px;
            padding:14px 16px;
            border:1px dashed #cbd5e1;
            border-radius:14px;
            background:#f8fafc;
            color:#334155;
            font-size:13px;
        }
        .bit-item{
            padding:12px 0;
            border-bottom:1px dashed #e5e7eb;
        }
        .bit-item:last-child{ border-bottom:none; }
        .bit-title{
            font-weight:700;
            margin-bottom:4px;
        }
        .bit-meta{
            color:#6b7280;
            font-size:12px;
        }
        @media (max-width: 1100px){
            .grid{ grid-template-columns:repeat(2,1fr); }
            .mini-grid{ grid-template-columns:1fr; }
        }
        @media (max-width: 700px){
            .grid{ grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">

    <div class="header">
        <div>
            <h1 class="title">Conciliación final de auditoría</h1>
            <div class="subtitle">
                Folio <strong><?= h($auditoria['folio']) ?></strong> · Sucursal <strong><?= h($auditoria['sucursal_nombre']) ?></strong>
            </div>
            <div class="badges">
                <span class="badge <?= $estaCerrada ? 'green' : '' ?>"><?= h($auditoria['estatus']) ?></span>
                <span class="badge gray">Inicio: <?= h(fmtFecha($auditoria['fecha_inicio'])) ?></span>
                <span class="badge gray">Auditor: <?= h($auditoria['auditor_nombre']) ?></span>
                <?php if (!empty($auditoria['gerente_nombre'])): ?>
                    <span class="badge gray">Gerente: <?= h($auditoria['gerente_nombre']) ?></span>
                <?php endif; ?>
                <?php if (!empty($auditoria['fecha_cierre'])): ?>
                    <span class="badge gray">Cierre: <?= h(fmtFecha($auditoria['fecha_cierre'])) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="actions">
            <a href="auditorias_captura.php?id=<?= (int)$id_auditoria ?>" class="btn btn-secondary">Volver a captura</a>
            <a href="auditorias_escanear.php?id=<?= (int)$id_auditoria ?>" class="btn btn-secondary">Volver a escaneo</a>
            <?php if ($estaCerrada): ?>
                <a href="generar_acta_auditoria.php?id=<?= (int)$id_auditoria ?>" class="btn btn-primary">Generar acta</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($errores): ?>
        <div class="alert alert-danger">
            <strong>No se pudo completar la operación:</strong>
            <ul style="margin:8px 0 0 18px;">
                <?php foreach ($errores as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($ok): ?>
        <div class="alert alert-success">
            <strong><?= h($ok) ?></strong>
        </div>
    <?php endif; ?>

    <?php if (!$estaCerrada): ?>
        <div class="alert alert-warn">
            Estás revisando la conciliación final. Todavía puedes volver a captura o escaneo antes de cerrar la auditoría.
        </div>
    <?php endif; ?>

    <div class="mini-grid">
        <div class="card">
            <h2 class="section-title">Resumen unitarios</h2>
            <div class="grid">
                <div class="kpi">
                    <div class="kpi-label">Esperados</div>
                    <div class="kpi-value"><?= (int)$kpiUnit['total_esperados'] ?></div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Encontrados</div>
                    <div class="kpi-value"><?= (int)$kpiUnit['encontrados'] ?></div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Faltantes</div>
                    <div class="kpi-value"><?= (int)$kpiUnit['pendientes'] ?></div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Incidencias</div>
                    <div class="kpi-value"><?= count($incidencias) ?></div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2 class="section-title">Resumen cantidades</h2>
            <div class="grid">
                <div class="kpi">
                    <div class="kpi-label">Líneas esperadas</div>
                    <div class="kpi-value"><?= (int)$kpiCant['total_lineas'] ?></div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Líneas contadas</div>
                    <div class="kpi-value"><?= (int)$kpiCant['contadas'] ?></div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Sin diferencia</div>
                    <div class="kpi-value"><?= (int)$kpiCant['sin_diferencia'] ?></div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Con diferencia</div>
                    <div class="kpi-value"><?= (int)$kpiCant['con_diferencia'] ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Equipos faltantes</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Código</th>
                        <th>Marca</th>
                        <th>Modelo</th>
                        <th>Color</th>
                        <th>Capacidad</th>
                        <th>IMEI 1</th>
                        <th>IMEI 2</th>
                        <th>Estatus</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$faltantes): ?>
                    <tr>
                        <td colspan="9" style="text-align:center; padding:24px;">
                            No hay equipos faltantes. 🎉
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $n=1; foreach ($faltantes as $row): ?>
                        <tr>
                            <td><?= $n++ ?></td>
                            <td><?= h($row['codigo_producto']) ?></td>
                            <td><?= h($row['marca']) ?></td>
                            <td><?= h($row['modelo']) ?></td>
                            <td><?= h($row['color']) ?></td>
                            <td><?= h($row['capacidad']) ?></td>
                            <td><?= h($row['imei1']) ?></td>
                            <td><?= h($row['imei2']) ?></td>
                            <td><span class="pill pill-red">Faltante</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Diferencias en productos por cantidad</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Código</th>
                        <th>Marca</th>
                        <th>Modelo</th>
                        <th>Color</th>
                        <th>Capacidad</th>
                        <th>Sistema</th>
                        <th>Contado</th>
                        <th>Diferencia</th>
                        <th>Estatus</th>
                        <th>Observaciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$diferencias): ?>
                    <tr>
                        <td colspan="11" style="text-align:center; padding:24px;">
                            No hay diferencias en productos por cantidad.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $n=1; foreach ($diferencias as $row): ?>
                        <tr>
                            <td><?= $n++ ?></td>
                            <td><?= h($row['codigo_producto']) ?></td>
                            <td><?= h($row['marca']) ?></td>
                            <td><?= h($row['modelo']) ?></td>
                            <td><?= h($row['color']) ?></td>
                            <td><?= h($row['capacidad']) ?></td>
                            <td><strong><?= (int)$row['cantidad_sistema'] ?></strong></td>
                            <td><strong><?= (int)$row['cantidad_contada'] ?></strong></td>
                            <td style="font-weight:800; color:<?= h(diffColor($row['diferencia'])) ?>">
                                <?= ((int)$row['diferencia'] > 0 ? '+' : '') . (int)$row['diferencia'] ?>
                            </td>
                            <td><span class="pill pill-red"><?= h($row['estatus_revision']) ?></span></td>
                            <td><?= nl2br(h($row['observaciones'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Incidencias registradas</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>IMEI</th>
                        <th>Tipo</th>
                        <th>Detalle</th>
                        <th>Referencia</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$incidencias): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:24px;">
                            No hay incidencias registradas.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $n=1; foreach ($incidencias as $inc): ?>
                        <tr>
                            <td><?= $n++ ?></td>
                            <td><?= h($inc['imei_escaneado']) ?></td>
                            <td><?= h($inc['tipo_incidencia']) ?></td>
                            <td><?= h($inc['detalle']) ?></td>
                            <td>
                                <?= h($inc['referencia_tabla']) ?>
                                <?php if (!empty($inc['referencia_id'])): ?>
                                    #<?= (int)$inc['referencia_id'] ?>
                                <?php endif; ?>
                            </td>
                            <td><?= h(fmtFecha($inc['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Cierre de auditoría</h2>

        <?php if ($estaCerrada): ?>
            <div class="alert alert-success">
                Esta auditoría ya se encuentra cerrada desde el <strong><?= h(fmtFecha($auditoria['fecha_cierre'])) ?></strong>.
            </div>
            <div class="notice">
                Ya no deberían registrarse más escaneos ni modificar conteos, salvo que después definamos una reapertura controlada para admins.
            </div>
        <?php else: ?>
            <div class="notice">
                Al cerrar la auditoría se consolidarán los faltantes unitarios, las diferencias en cantidades y las incidencias registradas. Después de esto ya quedará lista para generar el acta.
            </div>

            <form method="POST" action="" onsubmit="return confirm('¿Seguro que deseas cerrar la auditoría? Después del cierre ya no debería seguirse capturando información.');">
                <input type="hidden" name="id_auditoria" value="<?= (int)$id_auditoria ?>">

                <div class="field" style="margin-top:16px;">
                    <label for="observaciones_cierre" style="font-weight:700;">Observaciones finales de cierre</label>
                    <textarea
                        name="observaciones_cierre"
                        id="observaciones_cierre"
                        placeholder="Ej. Se concluye la auditoría con 2 faltantes y 1 diferencia en accesorios. El gerente queda enterado de los hallazgos."
                    ><?= h($_POST['observaciones_cierre'] ?? '') ?></textarea>
                </div>

                <div class="actions" style="margin-top:16px;">
                    <button type="submit" name="cerrar_auditoria" value="1" class="btn btn-danger">
                        Cerrar auditoría
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 class="section-title">Bitácora reciente</h2>
        <?php if (!$bitacora): ?>
            <div class="notice">Todavía no hay eventos registrados.</div>
        <?php else: ?>
            <?php foreach ($bitacora as $b): ?>
                <div class="bit-item">
                    <div class="bit-title"><?= h($b['accion']) ?></div>
                    <div><?= h($b['detalle'] ?? '') ?></div>
                    <div class="bit-meta">
                        <?= h(fmtFecha($b['fecha_evento'])) ?>
                        <?php if (!empty($b['usuario_nombre'])): ?>
                            · <?= h($b['usuario_nombre']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>
</body>
</html>