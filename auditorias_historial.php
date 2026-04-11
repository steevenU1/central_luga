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
    exit('Sin permiso para consultar auditorías.');
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

function badgeClass(string $estatus): string {
    $estatus = trim($estatus);
    if ($estatus === 'Cerrada') return 'badge-green';
    if ($estatus === 'En proceso') return 'badge-blue';
    if ($estatus === 'Pendiente revision') return 'badge-amber';
    if ($estatus === 'Cancelada') return 'badge-red';
    return 'badge-gray';
}

/* =========================================================
   FILTROS
========================================================= */
$f_folio        = trim((string)($_GET['folio'] ?? ''));
$f_sucursal     = (int)($_GET['id_sucursal'] ?? 0);
$f_estatus      = trim((string)($_GET['estatus'] ?? ''));
$f_auditor      = trim((string)($_GET['auditor'] ?? ''));
$f_fecha_ini    = trim((string)($_GET['fecha_ini'] ?? ''));
$f_fecha_fin    = trim((string)($_GET['fecha_fin'] ?? ''));

/* =========================================================
   LISTA DE SUCURSALES PARA FILTRO
========================================================= */
$sucursales = [];
if (in_array($ROL, ['Admin', 'Administrador', 'Auditor', 'Logistica', 'GerenteZona'], true)) {
    $rsSuc = $conn->query("SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre ASC");
    $sucursales = $rsSuc ? $rsSuc->fetch_all(MYSQLI_ASSOC) : [];
} else {
    $stmtSuc = $conn->prepare("SELECT id, nombre FROM sucursales WHERE id = ? LIMIT 1");
    $stmtSuc->bind_param("i", $ID_SUCURSAL);
    $stmtSuc->execute();
    $resSuc = $stmtSuc->get_result();
    $sucursales = $resSuc ? $resSuc->fetch_all(MYSQLI_ASSOC) : [];
    if (!$f_sucursal) $f_sucursal = $ID_SUCURSAL;
}

/* =========================================================
   QUERY PRINCIPAL
========================================================= */
$sql = "
    SELECT
        a.*,
        s.nombre AS sucursal_nombre,
        s.zona AS sucursal_zona,
        u1.nombre AS auditor_nombre,
        u2.nombre AS gerente_nombre
    FROM auditorias a
    INNER JOIN sucursales s ON s.id = a.id_sucursal
    INNER JOIN usuarios u1 ON u1.id = a.id_auditor
    LEFT JOIN usuarios u2 ON u2.id = a.id_gerente
    WHERE 1=1
";

$params = [];
$types  = "";

/* Restricción por rol */
if (in_array($ROL, ['Gerente', 'Supervisor'], true)) {
    $sql .= " AND a.id_sucursal = ? ";
    $params[] = $ID_SUCURSAL;
    $types .= "i";
}

/* Filtros */
if ($f_folio !== '') {
    $sql .= " AND a.folio LIKE ? ";
    $params[] = '%' . $f_folio . '%';
    $types .= "s";
}

if ($f_sucursal > 0) {
    $sql .= " AND a.id_sucursal = ? ";
    $params[] = $f_sucursal;
    $types .= "i";
}

if ($f_estatus !== '') {
    $sql .= " AND a.estatus = ? ";
    $params[] = $f_estatus;
    $types .= "s";
}

if ($f_auditor !== '') {
    $sql .= " AND u1.nombre LIKE ? ";
    $params[] = '%' . $f_auditor . '%';
    $types .= "s";
}

if ($f_fecha_ini !== '') {
    $sql .= " AND DATE(a.fecha_inicio) >= ? ";
    $params[] = $f_fecha_ini;
    $types .= "s";
}

if ($f_fecha_fin !== '') {
    $sql .= " AND DATE(a.fecha_inicio) <= ? ";
    $params[] = $f_fecha_fin;
    $types .= "s";
}

$sql .= " ORDER BY a.id DESC ";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$auditorias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   KPIS
========================================================= */
$totalAuditorias = count($auditorias);
$totalCerradas = 0;
$totalProceso = 0;
$totalConIncidencias = 0;

foreach ($auditorias as $a) {
    if (($a['estatus'] ?? '') === 'Cerrada') $totalCerradas++;
    if (($a['estatus'] ?? '') === 'En proceso') $totalProceso++;
    if ((int)($a['total_incidencias'] ?? 0) > 0) $totalConIncidencias++;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial de Auditorías</title>
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
        .filter-grid{
            display:grid;
            grid-template-columns:repeat(6, 1fr);
            gap:14px;
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
        .field{
            display:flex;
            flex-direction:column;
            gap:7px;
        }
        label{
            font-weight:700;
            font-size:13px;
        }
        input, select{
            border:1px solid #d1d5db;
            border-radius:12px;
            padding:11px 12px;
            font-size:14px;
            outline:none;
            background:#fff;
        }
        input:focus, select:focus{
            border-color:#2563eb;
            box-shadow:0 0 0 3px rgba(37,99,235,.12);
        }
        .actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-top:16px;
        }
        .btn{
            border:none;
            border-radius:12px;
            padding:12px 18px;
            font-weight:700;
            font-size:14px;
            cursor:pointer;
        }
        .btn-primary{
            background:#111827;
            color:#fff;
        }
        .btn-secondary{
            background:#e5e7eb;
            color:#111827;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
        }
        .table-wrap{
            overflow:auto;
            border:1px solid #e5e7eb;
            border-radius:16px;
        }
        table{
            width:100%;
            border-collapse:collapse;
            min-width:1450px;
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
            vertical-align:middle;
        }
        tbody tr:hover{
            background:#f9fafb;
        }
        .badge{
            display:inline-block;
            padding:6px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            white-space:nowrap;
        }
        .badge-blue{ background:#eef2ff; color:#3730a3; }
        .badge-green{ background:#ecfdf5; color:#065f46; }
        .badge-red{ background:#fef2f2; color:#991b1b; }
        .badge-amber{ background:#fff7ed; color:#9a3412; }
        .badge-gray{ background:#f3f4f6; color:#374151; }
        .btn-mini{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:7px 10px;
            border-radius:10px;
            font-size:12px;
            font-weight:700;
            text-decoration:none;
            margin:2px;
            background:#e5e7eb;
            color:#111827;
        }
        .btn-mini-dark{
            background:#111827;
            color:#fff;
        }
        .notice{
            margin-top:10px;
            padding:14px 16px;
            border:1px dashed #cbd5e1;
            border-radius:14px;
            background:#f8fafc;
            color:#334155;
            font-size:13px;
        }
        @media (max-width: 1200px){
            .filter-grid{ grid-template-columns:repeat(3, 1fr); }
            .grid{ grid-template-columns:repeat(2,1fr); }
        }
        @media (max-width: 700px){
            .filter-grid{ grid-template-columns:1fr; }
            .grid{ grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">

    <div class="header">
        <div>
            <h1 class="title">Historial de auditorías</h1>
            <div class="subtitle">
                Consulta, seguimiento y acceso rápido a captura, conciliación y acta final.
            </div>
        </div>
        <div class="actions">
            <a href="auditorias_nueva.php" class="btn btn-primary">Nueva auditoría</a>
            <a href="dashboard.php" class="btn btn-secondary">Dashboard</a>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Resumen</h2>
        <div class="grid">
            <div class="kpi">
                <div class="kpi-label">Auditorías listadas</div>
                <div class="kpi-value"><?= (int)$totalAuditorias ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">En proceso</div>
                <div class="kpi-value"><?= (int)$totalProceso ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Cerradas</div>
                <div class="kpi-value"><?= (int)$totalCerradas ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Con incidencias</div>
                <div class="kpi-value"><?= (int)$totalConIncidencias ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Filtros</h2>

        <form method="GET" action="">
            <div class="filter-grid">
                <div class="field">
                    <label for="folio">Folio</label>
                    <input type="text" name="folio" id="folio" value="<?= h($f_folio) ?>" placeholder="AUD-LUGA-2026-0001">
                </div>

                <div class="field">
                    <label for="id_sucursal">Sucursal</label>
                    <select name="id_sucursal" id="id_sucursal">
                        <option value="">Todas</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= ($f_sucursal === (int)$s['id']) ? 'selected' : '' ?>>
                                <?= h($s['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="estatus">Estatus</label>
                    <select name="estatus" id="estatus">
                        <option value="">Todos</option>
                        <?php
                        $estatuses = ['Borrador','En proceso','Pendiente revision','Cerrada','Cancelada'];
                        foreach ($estatuses as $est):
                        ?>
                            <option value="<?= h($est) ?>" <?= ($f_estatus === $est) ? 'selected' : '' ?>>
                                <?= h($est) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="auditor">Auditor</label>
                    <input type="text" name="auditor" id="auditor" value="<?= h($f_auditor) ?>" placeholder="Nombre del auditor">
                </div>

                <div class="field">
                    <label for="fecha_ini">Fecha inicio desde</label>
                    <input type="date" name="fecha_ini" id="fecha_ini" value="<?= h($f_fecha_ini) ?>">
                </div>

                <div class="field">
                    <label for="fecha_fin">Fecha inicio hasta</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" value="<?= h($f_fecha_fin) ?>">
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">Filtrar</button>
                <a href="auditorias_historial.php" class="btn btn-secondary">Limpiar</a>
            </div>
        </form>

        <div class="notice">
            Los roles Gerente y Supervisor solo visualizan auditorías de su propia sucursal.
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Listado de auditorías</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Folio</th>
                        <th>Sucursal</th>
                        <th>Zona</th>
                        <th>Auditor</th>
                        <th>Gerente</th>
                        <th>Inicio</th>
                        <th>Cierre</th>
                        <th>Estatus</th>
                        <th>Unitarios</th>
                        <th>Escaneados</th>
                        <th>Faltantes</th>
                        <th>Cantidades</th>
                        <th>Dif. cantidades</th>
                        <th>Incidencias</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$auditorias): ?>
                    <tr>
                        <td colspan="16" style="text-align:center; padding:28px;">
                            No se encontraron auditorías con los filtros seleccionados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $n = 1; foreach ($auditorias as $a): ?>
                        <tr>
                            <td><?= $n++ ?></td>
                            <td><strong><?= h($a['folio']) ?></strong></td>
                            <td><?= h($a['sucursal_nombre']) ?></td>
                            <td><?= h($a['sucursal_zona'] ?: '-') ?></td>
                            <td><?= h($a['auditor_nombre']) ?></td>
                            <td><?= h($a['gerente_nombre'] ?: '-') ?></td>
                            <td><?= h(fmtFecha($a['fecha_inicio'])) ?></td>
                            <td><?= h(fmtFecha($a['fecha_cierre'])) ?></td>
                            <td>
                                <span class="badge <?= h(badgeClass((string)($a['estatus'] ?? ''))) ?>">
                                    <?= h($a['estatus']) ?>
                                </span>
                            </td>
                            <td><?= (int)($a['total_snapshot'] ?? 0) ?></td>
                            <td><?= (int)($a['total_escaneados'] ?? 0) ?></td>
                            <td><?= (int)($a['total_faltantes'] ?? 0) ?></td>
                            <td><?= (int)($a['total_lineas_accesorios'] ?? 0) ?></td>
                            <td><?= (int)($a['total_accesorios_con_diferencia'] ?? 0) ?></td>
                            <td><?= (int)($a['total_incidencias'] ?? 0) ?></td>
                            <td>
                                <a class="btn-mini" href="auditorias_captura.php?id=<?= (int)$a['id'] ?>">Captura</a>
                                <a class="btn-mini" href="auditorias_escanear.php?id=<?= (int)$a['id'] ?>">Escaneo</a>
                                <a class="btn-mini" href="auditorias_conciliar.php?id=<?= (int)$a['id'] ?>">Conciliar</a>
                                <?php if (($a['estatus'] ?? '') === 'Cerrada'): ?>
                                    <a class="btn-mini btn-mini-dark" href="generar_acta_auditoria.php?id=<?= (int)$a['id'] ?>">Acta</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</body>
</html>