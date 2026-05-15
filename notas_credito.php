<?php
// notas_credito.php
// Módulo de Notas de Crédito de Proveedores
// Paso 1: Alta + listado + filtros + KPIs
// Compatible con Luga / Subdistribuidor

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';


date_default_timezone_set('America/Mexico_City');

/* =========================================================
   SESIÓN / ROLES
========================================================= */
$ROL         = trim((string)($_SESSION['rol'] ?? 'Ejecutivo'));
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUC_SES  = (int)($_SESSION['id_sucursal'] ?? 0);
$ID_SUBDIS   = (int)($_SESSION['id_subdis'] ?? 0);

$isSubdisAdmin = in_array($ROL, ['Subdis_Admin', 'subdis_admin'], true);
$isAdminLike   = in_array($ROL, ['Admin', 'Logistica'], true);
$isGerente     = in_array($ROL, ['Gerente'], true);

$ALLOW = ['Admin', 'Logistica', 'Gerente', 'Subdis_Admin', 'subdis_admin'];
if (!in_array($ROL, $ALLOW, true)) {
    header("Location: 403.php");
    exit();
}

$permEscritura = $isAdminLike || $isSubdisAdmin;

/* =========================================================
   HELPERS
========================================================= */
function esc($s) {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}
function cap($s, $n) {
    return substr(trim((string)($s ?? '')), 0, $n);
}
function money_in($s): float {
    $s = trim((string)$s);
    if ($s === '') return 0.0;

    if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $s)) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '', $s);
    }
    return is_numeric($s) ? round((float)$s, 2) : 0.0;
}
function estatusBadgeClass(string $estatus): string {
    switch ($estatus) {
        case 'Disponible': return 'badge-soft-primary';
        case 'Parcial':    return 'badge-soft-warning';
        case 'Aplicada':   return 'badge-soft-success';
        case 'Cancelada':  return 'badge-soft-danger';
        default:           return 'badge-soft-secondary';
    }
}

/* =========================================================
   FLASH
========================================================= */
$flash = $_SESSION['flash_nc'] ?? '';
unset($_SESSION['flash_nc']);

/* =========================================================
   POST: GUARDAR NOTA DE CRÉDITO
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_nc') {
    if (!$permEscritura) {
        $_SESSION['flash_nc'] = "<div class='alert alert-danger'>No tienes permisos para registrar notas de crédito.</div>";
        header("Location: notas_credito.php");
        exit();
    }

    $id_proveedor = (int)($_POST['id_proveedor'] ?? 0);
    $folio        = cap($_POST['folio'] ?? '', 120);
    $uuid         = cap($_POST['uuid'] ?? '', 120);
    $fecha        = cap($_POST['fecha'] ?? date('Y-m-d'), 10);
    $subtotal     = money_in($_POST['subtotal'] ?? '0');
    $iva          = money_in($_POST['iva'] ?? '0');
    $total        = money_in($_POST['total'] ?? '0');
    $notas        = trim((string)($_POST['notas'] ?? ''));

    // Propiedad / subdis / sucursal
    if ($isSubdisAdmin) {
        $propiedad   = 'Subdistribuidor';
        $id_subdis   = $ID_SUBDIS > 0 ? $ID_SUBDIS : null;
        $id_sucursal = $ID_SUC_SES;
    } else {
        $propiedad   = 'Luga';
        $id_subdis   = null;
        $id_sucursal = (int)($_POST['id_sucursal'] ?? $ID_SUC_SES);
        if ($id_sucursal <= 0) $id_sucursal = $ID_SUC_SES;
    }

    // Validaciones
    $errores = [];

    if ($id_proveedor <= 0) $errores[] = "Debes seleccionar un proveedor.";
    if ($folio === '')      $errores[] = "Debes capturar el folio.";
    if ($fecha === '')      $errores[] = "Debes capturar la fecha.";
    if ($total <= 0)        $errores[] = "El total debe ser mayor a 0.";
    if ($id_sucursal <= 0)  $errores[] = "No se pudo determinar la sucursal.";
    if ($isSubdisAdmin && $ID_SUBDIS <= 0) $errores[] = "Falta id_subdis en sesión.";

    // Validar proveedor existente
    if ($id_proveedor > 0) {
        $stProv = $conn->prepare("SELECT id, nombre FROM proveedores WHERE id=? LIMIT 1");
        $stProv->bind_param("i", $id_proveedor);
        $stProv->execute();
        $provOk = $stProv->get_result()->fetch_assoc();
        $stProv->close();
        if (!$provOk) $errores[] = "El proveedor seleccionado no existe.";
    }

    // Validación ligera de folio duplicado por proveedor
    if ($id_proveedor > 0 && $folio !== '') {
        if ($isSubdisAdmin) {
            $sqlDup = "SELECT id FROM compras_notas_credito WHERE id_proveedor=? AND folio=? AND propiedad='Subdistribuidor' AND id_subdis=? LIMIT 1";
            $stDup = $conn->prepare($sqlDup);
            $stDup->bind_param("isi", $id_proveedor, $folio, $ID_SUBDIS);
        } else {
            $sqlDup = "SELECT id FROM compras_notas_credito WHERE id_proveedor=? AND folio=? AND propiedad='Luga' LIMIT 1";
            $stDup = $conn->prepare($sqlDup);
            $stDup->bind_param("is", $id_proveedor, $folio);
        }
        $stDup->execute();
        $dup = $stDup->get_result()->fetch_assoc();
        $stDup->close();

        if ($dup) $errores[] = "Ya existe una nota de crédito con ese folio para este proveedor.";
    }

    if (!empty($errores)) {
        $_SESSION['flash_nc'] = "<div class='alert alert-warning mb-0'><strong>Ojo:</strong><br>• " . implode("<br>• ", array_map('esc', $errores)) . "</div>";
        header("Location: notas_credito.php");
        exit();
    }

    $saldo_disponible = $total;
    $estatus          = 'Disponible';

    $sqlIns = "INSERT INTO compras_notas_credito
        (id_proveedor, id_sucursal, propiedad, id_subdis, folio, uuid, fecha, subtotal, iva, total, saldo_disponible, estatus, notas, creado_por)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stIns = $conn->prepare($sqlIns);
    if (!$stIns) {
        $_SESSION['flash_nc'] = "<div class='alert alert-danger'>Error al preparar inserción: " . esc($conn->error) . "</div>";
        header("Location: notas_credito.php");
        exit();
    }

    // i i s i s s s d d d d s s i
    $stIns->bind_param(
        "iisisssddddssi",
        $id_proveedor,
        $id_sucursal,
        $propiedad,
        $id_subdis,
        $folio,
        $uuid,
        $fecha,
        $subtotal,
        $iva,
        $total,
        $saldo_disponible,
        $estatus,
        $notas,
        $ID_USUARIO
    );

    $ok = $stIns->execute();
    $stIns->close();

    if ($ok) {
        $_SESSION['flash_nc'] = "<div class='alert alert-success mb-0'>Nota de crédito registrada correctamente.</div>";
    } else {
        $_SESSION['flash_nc'] = "<div class='alert alert-danger mb-0'>Error al guardar la nota de crédito.</div>";
    }

    header("Location: notas_credito.php");
    exit();
}

/* =========================================================
   FILTROS
========================================================= */
$estado   = cap($_GET['estado'] ?? 'todos', 20);
$prov_id  = (int)($_GET['proveedor'] ?? 0);
$desde    = cap($_GET['desde'] ?? '', 10);
$hasta    = cap($_GET['hasta'] ?? '', 10);
$q        = cap($_GET['q'] ?? '', 80);

$where  = [];
$params = [];
$types  = '';

if ($isSubdisAdmin) {
    $where[]  = "nc.propiedad='Subdistribuidor' AND nc.id_subdis=?";
    $params[] = $ID_SUBDIS;
    $types   .= 'i';
} else {
    $where[] = "nc.propiedad='Luga'";
}

if ($estado !== 'todos') {
    $where[]  = "nc.estatus=?";
    $params[] = $estado;
    $types   .= 's';
}
if ($prov_id > 0) {
    $where[]  = "nc.id_proveedor=?";
    $params[] = $prov_id;
    $types   .= 'i';
}
if ($desde !== '') {
    $where[]  = "nc.fecha >= ?";
    $params[] = $desde;
    $types   .= 's';
}
if ($hasta !== '') {
    $where[]  = "nc.fecha <= ?";
    $params[] = $hasta;
    $types   .= 's';
}
if ($q !== '') {
    $where[]  = "(nc.folio LIKE ? OR nc.uuid LIKE ? OR p.nombre LIKE ? OR nc.notas LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ssss';
}

$sqlWhere = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

/* =========================================================
   CATÁLOGOS
========================================================= */
$proveedores = $conn->query("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre");

if ($isSubdisAdmin) {
    $stSuc = $conn->prepare("SELECT id, nombre FROM sucursales WHERE id=? LIMIT 1");
    $stSuc->bind_param("i", $ID_SUC_SES);
    $stSuc->execute();
    $sucursales = $stSuc->get_result();
    $stSuc->close();
} else {
    $sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
}

/* =========================================================
   KPIs
========================================================= */
$whereKpi = [];
$paramsKpi = [];
$typesKpi = '';

if ($isSubdisAdmin) {
    $whereKpi[] = "propiedad='Subdistribuidor' AND id_subdis=?";
    $paramsKpi[] = $ID_SUBDIS;
    $typesKpi .= 'i';
} else {
    $whereKpi[] = "propiedad='Luga'";
}

$sqlWhereKpi = count($whereKpi) ? ('WHERE ' . implode(' AND ', $whereKpi)) : '';

$sqlKpi = "
    SELECT
        COUNT(*) AS total_registros,
        COALESCE(SUM(total),0) AS total_notas,
        COALESCE(SUM(saldo_disponible),0) AS saldo_disponible,
        COALESCE(SUM(total - saldo_disponible),0) AS total_aplicado
    FROM compras_notas_credito
    $sqlWhereKpi
";
$stKpi = $conn->prepare($sqlKpi);
if ($typesKpi !== '') $stKpi->bind_param($typesKpi, ...$paramsKpi);
$stKpi->execute();
$kpis = $stKpi->get_result()->fetch_assoc() ?: [
    'total_registros'  => 0,
    'total_notas'      => 0,
    'saldo_disponible' => 0,
    'total_aplicado'   => 0
];
$stKpi->close();

/* =========================================================
   LISTADO
========================================================= */
$sql = "
    SELECT
        nc.id,
        nc.folio,
        nc.uuid,
        nc.fecha,
        nc.subtotal,
        nc.iva,
        nc.total,
        nc.saldo_disponible,
        nc.estatus,
        nc.propiedad,
        nc.id_subdis,
        nc.notas,
        nc.creado_en,
        p.nombre AS proveedor,
        s.nombre AS sucursal,
        u.nombre AS usuario_creo,
        (nc.total - nc.saldo_disponible) AS aplicado
    FROM compras_notas_credito nc
    INNER JOIN proveedores p ON p.id = nc.id_proveedor
    INNER JOIN sucursales s  ON s.id = nc.id_sucursal
    LEFT JOIN usuarios u     ON u.id = nc.creado_por
    $sqlWhere
    ORDER BY nc.fecha DESC, nc.id DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) die("Error en prepare: " . $conn->error);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}
$stmt->close();

$countRows = count($rows);
$ownerChip = $isSubdisAdmin ? 'Subdistribuidor' : 'Luga';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Notas de Crédito · Central</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="./img/favicon.ico">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root{
            --brand1:#0ea5e9;
            --brand2:#6366f1;
            --brand3:#8b5cf6;
            --bg:#f6f8fc;
            --card:#ffffff;
            --text:#0f172a;
            --muted:#64748b;
            --line:#e5e7eb;
            --shadow:0 10px 28px rgba(2,8,20,.06), 0 2px 8px rgba(2,8,20,.05);
        }

        body{
            background:
                radial-gradient(1100px 420px at 110% -80%, rgba(99,102,241,.09), transparent),
                radial-gradient(1000px 380px at -10% 120%, rgba(14,165,233,.09), transparent),
                var(--bg);
        }

        .page-head{
            border:0;
            border-radius:1.25rem;
            background: linear-gradient(135deg, var(--brand1) 0%, var(--brand2) 55%, var(--brand3) 100%);
            color:#fff;
            box-shadow: 0 20px 45px rgba(2,8,20,.12), 0 3px 10px rgba(2,8,20,.06);
            overflow:hidden;
            position:relative;
        }

        .page-head::after{
            content:'';
            position:absolute;
            inset:auto -60px -60px auto;
            width:220px; height:220px;
            border-radius:50%;
            background:rgba(255,255,255,.08);
            filter:blur(2px);
        }

        .page-head .icon-wrap{
            width:54px; height:54px;
            display:grid; place-items:center;
            background:rgba(255,255,255,.16);
            border-radius:16px;
            backdrop-filter: blur(4px);
        }

        .chip{
            color:#111 !important;
            background:rgba(255,255,255,.93) !important;
            border:1px solid rgba(0,0,0,.08) !important;
            padding:.4rem .7rem;
            border-radius:999px;
            font-weight:700;
            font-size:.88rem;
        }

        .card-soft{
            border:0;
            border-radius:1rem;
            background:var(--card);
            box-shadow:var(--shadow);
        }

        .kpi-card{
            border:0;
            border-radius:1rem;
            background:#fff;
            box-shadow:var(--shadow);
            overflow:hidden;
            height:100%;
        }

        .kpi-top{
            height:6px;
            background:linear-gradient(90deg, var(--brand1), var(--brand2), var(--brand3));
        }

        .kpi-title{
            color:var(--muted);
            font-size:.92rem;
            margin-bottom:.25rem;
            font-weight:600;
        }

        .kpi-value{
            font-size:1.55rem;
            font-weight:800;
            color:var(--text);
            line-height:1.1;
        }

        .filters .form-control,
        .filters .form-select{
            border-radius:.85rem;
            border:1px solid #dbe2ea;
        }

        .filters .form-control:focus,
        .filters .form-select:focus{
            border-color:#93c5fd;
            box-shadow:0 0 0 .2rem rgba(59,130,246,.12);
        }

        .table-wrap{
            border-radius:1rem;
            overflow:hidden;
        }

        .table thead th{
            background:#f8fafc;
            border-bottom:1px solid #e5e7eb;
            text-transform:uppercase;
            letter-spacing:.4px;
            font-size:.76rem;
            color:#475569;
            white-space:nowrap;
        }

        .table td{
            vertical-align:middle;
        }

        .badge-soft-primary{
            background:#dbeafe;
            color:#1d4ed8;
            border:1px solid #bfdbfe;
        }
        .badge-soft-warning{
            background:#fef3c7;
            color:#b45309;
            border:1px solid #fde68a;
        }
        .badge-soft-success{
            background:#dcfce7;
            color:#15803d;
            border:1px solid #bbf7d0;
        }
        .badge-soft-danger{
            background:#fee2e2;
            color:#b91c1c;
            border:1px solid #fecaca;
        }
        .badge-soft-secondary{
            background:#e5e7eb;
            color:#374151;
            border:1px solid #d1d5db;
        }

        .mini{
            font-size:.82rem;
            color:var(--muted);
        }

        .empty-state{
            padding:3rem 1rem;
            text-align:center;
            color:var(--muted);
        }

        .modal-content{
            border:0;
            border-radius:1.1rem;
            box-shadow:0 30px 60px rgba(2,8,20,.18);
            overflow:hidden;
        }

        .modal-header{
            border-bottom:0;
            background:linear-gradient(135deg, #eef2ff 0%, #ecfeff 100%);
        }

        .modal-footer{
            border-top:0;
        }

        .money{
            white-space:nowrap;
            font-variant-numeric: tabular-nums;
        }

        @media (max-width: 768px){
            .kpi-value{ font-size:1.3rem; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/navbar.php'; ?>

<div class="container-fluid px-3 px-lg-4 my-4">

    <!-- HERO -->
    <div class="page-head p-4 p-md-5 mb-4">
        <div class="d-flex flex-wrap align-items-center gap-3 position-relative" style="z-index:2;">
            <div class="icon-wrap">
                <i class="bi bi-receipt-cutoff fs-4"></i>
            </div>

            <div class="flex-grow-1">
                <h2 class="fw-bold mb-1">Notas de Crédito</h2>
                <div class="opacity-75">
                    Controla las notas de crédito de proveedores y su saldo disponible.
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <span class="chip"><i class="bi bi-building me-1"></i> <?= esc($ownerChip) ?></span>
                <span class="chip"><i class="bi bi-collection me-1"></i> <?= (int)$countRows ?> visibles</span>
                <span class="chip"><i class="bi bi-clock-history me-1"></i> <?= date('d/m/Y H:i') ?></span>
            </div>
        </div>
    </div>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="kpi-card">
                <div class="kpi-top"></div>
                <div class="p-3">
                    <div class="kpi-title">Total de notas</div>
                    <div class="kpi-value"><?= number_format((float)$kpis['total_registros']) ?></div>
                    <div class="mini">Registros acumulados</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="kpi-card">
                <div class="kpi-top"></div>
                <div class="p-3">
                    <div class="kpi-title">Monto total</div>
                    <div class="kpi-value money">$<?= number_format((float)$kpis['total_notas'], 2) ?></div>
                    <div class="mini">Importe total registrado</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="kpi-card">
                <div class="kpi-top"></div>
                <div class="p-3">
                    <div class="kpi-title">Saldo disponible</div>
                    <div class="kpi-value money">$<?= number_format((float)$kpis['saldo_disponible'], 2) ?></div>
                    <div class="mini">Pendiente por aplicar</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="kpi-card">
                <div class="kpi-top"></div>
                <div class="p-3">
                    <div class="kpi-title">Total aplicado</div>
                    <div class="kpi-value money">$<?= number_format((float)$kpis['total_aplicado'], 2) ?></div>
                    <div class="mini">Ya usado en facturas</div>
                </div>
            </div>
        </div>
    </div>

    <!-- FLASH -->
    <?php if ($flash): ?>
        <div class="mb-3"><?= $flash ?></div>
    <?php endif; ?>

    <!-- FILTROS -->
    <div class="card card-soft mb-4">
        <div class="card-body filters">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="mb-0"><i class="bi bi-funnel me-2 text-primary"></i>Filtros</h5>
                <div class="d-flex gap-2">
                    <?php if ($permEscritura): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNC">
                            <i class="bi bi-plus-lg me-1"></i> Nueva nota
                        </button>
                    <?php endif; ?>
                    <a href="notas_credito.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Limpiar
                    </a>
                </div>
            </div>

            <form class="row g-3 align-items-end" method="get">
                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">Estatus</label>
                    <select name="estado" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $estado==='todos'?'selected':'' ?>>Todos</option>
                        <option value="Disponible" <?= $estado==='Disponible'?'selected':'' ?>>Disponible</option>
                        <option value="Parcial" <?= $estado==='Parcial'?'selected':'' ?>>Parcial</option>
                        <option value="Aplicada" <?= $estado==='Aplicada'?'selected':'' ?>>Aplicada</option>
                        <option value="Cancelada" <?= $estado==='Cancelada'?'selected':'' ?>>Cancelada</option>
                    </select>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">Proveedor</label>
                    <select name="proveedor" class="form-select">
                        <option value="0">Todos</option>
                        <?php if ($proveedores): while ($p = $proveedores->fetch_assoc()): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $prov_id===(int)$p['id']?'selected':'' ?>>
                                <?= esc($p['nombre']) ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">Desde</label>
                    <input type="date" name="desde" class="form-control" value="<?= esc($desde) ?>">
                </div>

                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">Hasta</label>
                    <input type="date" name="hasta" class="form-control" value="<?= esc($hasta) ?>">
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">Buscar</label>
                    <input type="text" name="q" class="form-control" value="<?= esc($q) ?>" placeholder="Folio, UUID, proveedor, notas...">
                </div>

                <div class="col-12">
                    <button class="btn btn-outline-primary">
                        <i class="bi bi-search me-1"></i> Aplicar filtros
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- TABLA -->
    <div class="card card-soft">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="mb-0"><i class="bi bi-table me-2 text-primary"></i>Listado</h5>
                <div class="mini">Mostrando <?= (int)$countRows ?> registro(s)</div>
            </div>

            <?php if (empty($rows)): ?>
                <div class="empty-state">
                    <div class="mb-3"><i class="bi bi-inbox fs-1"></i></div>
                    <h5 class="mb-2">No hay notas de crédito</h5>
                    <div>No encontramos registros con los filtros actuales.</div>
                </div>
            <?php else: ?>
                <div class="table-responsive table-wrap">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Fecha</th>
                                <th>Folio</th>
                                <th>Proveedor</th>
                                <th>Sucursal</th>
                                <th>Total</th>
                                <th>Aplicado</th>
                                <th>Saldo disponible</th>
                                <th>Estatus</th>
                                <th>Capturó</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td class="fw-semibold"><?= (int)$r['id'] ?></td>
                                    <td><?= esc($r['fecha']) ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= esc($r['folio']) ?></div>
                                        <?php if (!empty($r['uuid'])): ?>
                                            <div class="mini">UUID: <?= esc($r['uuid']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= esc($r['proveedor']) ?></div>
                                        <?php if (!empty($r['notas'])): ?>
                                            <div class="mini text-truncate" style="max-width:260px;">
                                                <?= esc($r['notas']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= esc($r['sucursal']) ?></td>
                                    <td class="money fw-semibold">$<?= number_format((float)$r['total'], 2) ?></td>
                                    <td class="money">$<?= number_format((float)$r['aplicado'], 2) ?></td>
                                    <td class="money text-primary fw-bold">$<?= number_format((float)$r['saldo_disponible'], 2) ?></td>
                                    <td>
                                        <span class="badge <?= estatusBadgeClass((string)$r['estatus']) ?>">
                                            <?= esc($r['estatus']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= esc($r['usuario_creo'] ?: 'N/D') ?>
                                        <div class="mini"><?= esc($r['creado_en']) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php if ($permEscritura): ?>
<!-- MODAL NUEVA NOTA -->
<div class="modal fade" id="modalNC" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="notas_credito.php" id="formNC">
                <input type="hidden" name="accion" value="guardar_nc">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold mb-1">
                            <i class="bi bi-receipt-cutoff me-2 text-primary"></i>Nueva nota de crédito
                        </h5>
                        <div class="mini">Captura el documento. El saldo disponible se inicializa igual al total.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Proveedor <span class="text-danger">*</span></label>
                            <select name="id_proveedor" class="form-select" required>
                                <option value="">Selecciona</option>
                                <?php
                                $proveedoresModal = $conn->query("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre");
                                if ($proveedoresModal):
                                    while ($p = $proveedoresModal->fetch_assoc()):
                                ?>
                                    <option value="<?= (int)$p['id'] ?>"><?= esc($p['nombre']) ?></option>
                                <?php
                                    endwhile;
                                endif;
                                ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label">Sucursal <span class="text-danger">*</span></label>
                            <?php if ($isSubdisAdmin): ?>
                                <?php
                                $sucNom = '';
                                $stTmp = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
                                $stTmp->bind_param("i", $ID_SUC_SES);
                                $stTmp->execute();
                                $tmp = $stTmp->get_result()->fetch_assoc();
                                $stTmp->close();
                                $sucNom = $tmp['nombre'] ?? 'Sucursal actual';
                                ?>
                                <input type="text" class="form-control" value="<?= esc($sucNom) ?>" readonly>
                            <?php else: ?>
                                <select name="id_sucursal" class="form-select" required>
                                    <option value="">Selecciona</option>
                                    <?php
                                    $sucModal = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
                                    if ($sucModal):
                                        while ($s = $sucModal->fetch_assoc()):
                                    ?>
                                        <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $ID_SUC_SES ? 'selected' : '') ?>>
                                            <?= esc($s['nombre']) ?>
                                        </option>
                                    <?php
                                        endwhile;
                                    endif;
                                    ?>
                                </select>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Propiedad</label>
                            <input type="text" class="form-control" value="<?= $isSubdisAdmin ? 'Subdistribuidor' : 'Luga' ?>" readonly>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Folio <span class="text-danger">*</span></label>
                            <input type="text" name="folio" class="form-control" maxlength="120" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">UUID</label>
                            <input type="text" name="uuid" class="form-control" maxlength="120">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Fecha <span class="text-danger">*</span></label>
                            <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Subtotal</label>
                            <input type="number" step="0.01" min="0" name="subtotal" id="subtotal" class="form-control text-end" value="0.00">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">IVA</label>
                            <input type="number" step="0.01" min="0" name="iva" id="iva" class="form-control text-end" value="0.00">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label">Total <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" name="total" id="total" class="form-control text-end fw-bold" value="0.00" required>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label">Saldo disponible inicial</label>
                            <input type="text" id="saldo_preview" class="form-control text-end" value="$0.00" readonly>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Notas</label>
                            <textarea name="notas" class="form-control" rows="3" maxlength="5000" placeholder="Comentarios, referencia interna, observaciones, etc."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer d-flex justify-content-between">
                    <div class="mini">
                        Al guardar, el saldo disponible iniciará igual que el total.
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cerrar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check2-circle me-1"></i> Guardar nota
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
    const subtotal = document.getElementById('subtotal');
    const iva = document.getElementById('iva');
    const total = document.getElementById('total');
    const saldoPreview = document.getElementById('saldo_preview');

    function n(v){
        const x = parseFloat(v || 0);
        return isNaN(x) ? 0 : x;
    }

    function fmt(v){
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN'
        }).format(v || 0);
    }

    function updatePreview(){
        if (!total || !saldoPreview) return;
        saldoPreview.value = fmt(n(total.value));
    }

    function autoTotal(){
        if (!subtotal || !iva || !total) return;
        const s = n(subtotal.value);
        const i = n(iva.value);
        const t = +(s + i).toFixed(2);
        total.value = t.toFixed(2);
        updatePreview();
    }

    if (subtotal) subtotal.addEventListener('input', autoTotal);
    if (iva) iva.addEventListener('input', autoTotal);
    if (total) total.addEventListener('input', updatePreview);

    updatePreview();
})();
</script>

</body>
</html>