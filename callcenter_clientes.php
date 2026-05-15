<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['id_usuario'])) {
    header("Location: 403.php");
    exit();
}

$ROL = trim((string)($_SESSION['rol'] ?? ''));
if ($ROL !== 'Admin') {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';

/* =========================================================
   HELPERS
========================================================= */
if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money')) {
    function money($n): string
    {
        return '$' . number_format((float)$n, 2);
    }
}

if (!function_exists('fmtDate')) {
    function fmtDate($f): string
    {
        if (empty($f) || $f === '0000-00-00' || $f === '0000-00-00 00:00:00') return '—';
        $ts = strtotime($f);
        return $ts ? date('d/m/Y', $ts) : '—';
    }
}

if (!function_exists('fmtDateTime')) {
    function fmtDateTime($f): string
    {
        if (empty($f) || $f === '0000-00-00' || $f === '0000-00-00 00:00:00') return '—';
        $ts = strtotime($f);
        return $ts ? date('d/m/Y H:i', $ts) : '—';
    }
}

if (!function_exists('shortText')) {
    function shortText(string $text, int $limit = 70): string
    {
        $text = trim($text);
        if ($text === '') return '';
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 3) . '...' : $text;
        }
        return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
    }
}

if (!function_exists('buildWhatsAppUrl')) {
    function buildWhatsAppUrl(string $telefono, string $mensaje = ''): string
    {
        $telefono = preg_replace('/\D+/', '', $telefono);
        if ($telefono === '') return '#';

        if (strlen($telefono) === 10) {
            $telefono = '52' . $telefono;
        }

        $base = 'https://wa.me/' . $telefono;
        if ($mensaje !== '') {
            $base .= '?text=' . rawurlencode($mensaje);
        }
        return $base;
    }
}

if (!function_exists('badgeResultadoClass')) {
    function badgeResultadoClass(string $r): string
    {
        $r = strtoupper(trim($r));
        return match ($r) {
            'INTERESADO'               => 'bg-success-subtle text-success border border-success-subtle',
            'CODIGO_ENVIADO'           => 'bg-primary-subtle text-primary border border-primary-subtle',
            'VISITA_TIENDA_PENDIENTE'  => 'bg-info-subtle text-info border border-info-subtle',
            'NO_INTERESADO'            => 'bg-danger-subtle text-danger border border-danger-subtle',
            'NO_CONTESTO'              => 'bg-warning-subtle text-warning border border-warning-subtle',
            'VOLVER_A_LLAMAR'          => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
            'VENTA_CERRADA'            => 'bg-dark-subtle text-dark border border-dark-subtle',
            'SIN_CONTACTO'             => 'bg-light text-dark border',
            default                    => 'bg-light text-dark border'
        };
    }
}

if (!function_exists('badgeCodigoClass')) {
    function badgeCodigoClass(string $r): string
    {
        $r = strtoupper(trim($r));
        return match ($r) {
            'USADO'      => 'bg-success-subtle text-success border border-success-subtle',
            'GENERADO'   => 'bg-warning-subtle text-warning border border-warning-subtle',
            'ENVIADO'    => 'bg-primary-subtle text-primary border border-primary-subtle',
            'VENCIDO'    => 'bg-danger-subtle text-danger border border-danger-subtle',
            'CANCELADO'  => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
            default      => 'bg-light text-dark border'
        };
    }
}

/* =========================================================
   FILTROS
========================================================= */
$f_venta_ini  = trim((string)($_GET['f_venta_ini'] ?? ''));
$f_venta_fin  = trim((string)($_GET['f_venta_fin'] ?? ''));
$f_pago_ini   = trim((string)($_GET['f_pago_ini'] ?? ''));
$f_pago_fin   = trim((string)($_GET['f_pago_fin'] ?? ''));
$f_sucursal   = (int)($_GET['f_sucursal'] ?? 0);
$f_financiera = trim((string)($_GET['f_financiera'] ?? ''));
$f_q          = trim((string)($_GET['f_q'] ?? ''));
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 50;
$offset       = ($page - 1) * $perPage;

/* =========================================================
   SUCURSALES
========================================================= */
$sucursales = [];
$sqlSuc = "SELECT id, nombre FROM sucursales ORDER BY nombre ASC";
$resSuc = $conn->query($sqlSuc);
if ($resSuc) {
    while ($row = $resSuc->fetch_assoc()) {
        $sucursales[] = $row;
    }
}

/* =========================================================
   FINANCIERAS
========================================================= */
$financieras = [];
$sqlFin = "SELECT DISTINCT financiera
           FROM ventas
           WHERE financiera IS NOT NULL AND financiera <> ''
           ORDER BY financiera ASC";
$resFin = $conn->query($sqlFin);
if ($resFin) {
    while ($row = $resFin->fetch_assoc()) {
        $financieras[] = $row['financiera'];
    }
}

/* =========================================================
   WHERE
========================================================= */
$where = [];
$params = [];
$types  = '';

$where[] = "1=1";

if ($f_venta_ini !== '') {
    $where[] = "DATE(v.fecha_venta) >= ?";
    $types .= 's';
    $params[] = $f_venta_ini;
}
if ($f_venta_fin !== '') {
    $where[] = "DATE(v.fecha_venta) <= ?";
    $types .= 's';
    $params[] = $f_venta_fin;
}
if ($f_pago_ini !== '') {
    $where[] = "DATE(v.primer_pago) >= ?";
    $types .= 's';
    $params[] = $f_pago_ini;
}
if ($f_pago_fin !== '') {
    $where[] = "DATE(v.primer_pago) <= ?";
    $types .= 's';
    $params[] = $f_pago_fin;
}
if ($f_sucursal > 0) {
    $where[] = "v.id_sucursal = ?";
    $types .= 'i';
    $params[] = $f_sucursal;
}
if ($f_financiera !== '') {
    $where[] = "v.financiera = ?";
    $types .= 's';
    $params[] = $f_financiera;
}
if ($f_q !== '') {
    $where[] = "(
        v.tag LIKE ?
        OR v.nombre_cliente LIKE ?
        OR v.telefono_cliente LIKE ?
        OR s.nombre LIKE ?
        OR u.nombre LIKE ?
        OR v.financiera LIKE ?
    )";
    $qLike = '%' . $f_q . '%';
    $types .= 'ssssss';
    array_push($params, $qLike, $qLike, $qLike, $qLike, $qLike, $qLike);
}

$whereSql = implode(' AND ', $where);

/* =========================================================
   TOTAL REGISTROS PARA PAGINACIÓN
========================================================= */
$sqlCount = "
SELECT COUNT(*) AS total
FROM ventas v
LEFT JOIN sucursales s ON s.id = v.id_sucursal
LEFT JOIN usuarios u   ON u.id = v.id_usuario
WHERE $whereSql
";

$stmtCount = $conn->prepare($sqlCount);
if (!$stmtCount) {
    die("Error preparando conteo: " . $conn->error);
}
if (!empty($params)) {
    $stmtCount->bind_param($types, ...$params);
}
$stmtCount->execute();
$resCount = $stmtCount->get_result();
$totalRows = (int)($resCount->fetch_assoc()['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/* =========================================================
   SQL PRINCIPAL
========================================================= */
$sql = "
SELECT
    v.id,
    v.tag,
    v.nombre_cliente,
    v.telefono_cliente,
    v.fecha_venta,
    v.primer_pago,
    v.plazo_semanas,
    v.financiera,
    v.tipo_venta,
    v.precio_venta,
    v.id_sucursal,
    v.id_usuario,

    s.nombre AS sucursal_nombre,
    u.nombre AS ejecutivo_nombre,

    CASE
        WHEN v.primer_pago IS NOT NULL
             AND v.primer_pago <> '0000-00-00'
             AND v.plazo_semanas IS NOT NULL
        THEN DATE_ADD(v.primer_pago, INTERVAL v.plazo_semanas WEEK)

        WHEN v.fecha_venta IS NOT NULL
             AND v.plazo_semanas IS NOT NULL
        THEN DATE_ADD(DATE(v.fecha_venta), INTERVAL v.plazo_semanas WEEK)

        ELSE NULL
    END AS fecha_estimada_liquidacion,

    CASE
        WHEN v.primer_pago IS NOT NULL
             AND v.primer_pago <> '0000-00-00'
        THEN 'PRIMER_PAGO'

        WHEN v.fecha_venta IS NOT NULL
        THEN 'FECHA_VENTA'

        ELSE 'SIN_BASE'
    END AS base_calculo_liquidacion,

    seg.resultado AS ultimo_resultado,
    seg.fecha_contacto AS ultima_fecha_contacto,
    seg.comentarios AS ultimo_comentario,

    cc.codigo AS codigo_callcenter,
    cc.estatus AS estatus_codigo,
    cc.fecha_generado AS fecha_codigo,
    cc.fecha_vigencia AS fecha_vigencia_codigo

FROM ventas v
LEFT JOIN sucursales s
    ON s.id = v.id_sucursal
LEFT JOIN usuarios u
    ON u.id = v.id_usuario

LEFT JOIN (
    SELECT cs1.*
    FROM callcenter_seguimientos cs1
    INNER JOIN (
        SELECT id_venta, MAX(id) AS max_id
        FROM callcenter_seguimientos
        GROUP BY id_venta
    ) z1 ON z1.max_id = cs1.id
) seg
    ON seg.id_venta = v.id

LEFT JOIN (
    SELECT cc1.*
    FROM callcenter_codigos cc1
    INNER JOIN (
        SELECT id_venta_origen, MAX(id) AS max_id
        FROM callcenter_codigos
        GROUP BY id_venta_origen
    ) z2 ON z2.max_id = cc1.id
) cc
    ON cc.id_venta_origen = v.id

WHERE $whereSql

ORDER BY
    CASE
        WHEN fecha_estimada_liquidacion IS NULL THEN 1
        ELSE 0
    END,
    fecha_estimada_liquidacion ASC,
    v.fecha_venta DESC

LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error preparando consulta: " . $conn->error);
}

$paramsMain = $params;
$typesMain  = $types . 'ii';
$paramsMain[] = $perPage;
$paramsMain[] = $offset;

$stmt->bind_param($typesMain, ...$paramsMain);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}

/* =========================================================
   KPIs
========================================================= */
$kpi_total = $totalRows;
$kpi_con_telefono = 0;
$kpi_interesados = 0;
$kpi_codigos_generados = 0;
$kpi_codigos_usados = 0;

foreach ($rows as $r) {
    if (!empty(trim((string)($r['telefono_cliente'] ?? '')))) {
        $kpi_con_telefono++;
    }

    if (strtoupper((string)($r['ultimo_resultado'] ?? '')) === 'INTERESADO') {
        $kpi_interesados++;
    }

    if (!empty($r['codigo_callcenter'])) {
        $kpi_codigos_generados++;
    }

    if (strtoupper((string)($r['estatus_codigo'] ?? '')) === 'USADO') {
        $kpi_codigos_usados++;
    }
}

/* =========================================================
   HELPERS PAGINACIÓN
========================================================= */
$queryBase = $_GET;
unset($queryBase['page']);

function pageUrl(array $queryBase, int $pageNum): string {
    $queryBase['page'] = $pageNum;
    return 'callcenter_clientes.php?' . http_build_query($queryBase);
}

$desde = $totalRows > 0 ? ($offset + 1) : 0;
$hasta = min($offset + $perPage, $totalRows);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Call Center | Clientes</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            background: #f6f8fb;
        }
        .page-wrap{
            padding: 24px;
        }
        .hero-card{
            border: 0;
            border-radius: 18px;
            background: linear-gradient(135deg, #0d6efd 0%, #3d8bfd 100%);
            color: #fff;
            box-shadow: 0 10px 30px rgba(13,110,253,.15);
        }
        .filter-card,
        .kpi-card,
        .table-card{
            border: 0;
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(16,24,40,.06);
        }
        .kpi-value{
            font-size: 1.8rem;
            font-weight: 800;
            line-height: 1;
        }
        .kpi-label{
            color: #667085;
            font-size: .92rem;
        }
        .table thead th{
            font-size: .80rem;
            text-transform: uppercase;
            letter-spacing: .03em;
            white-space: nowrap;
        }
        .table td{
            vertical-align: middle;
            font-size: .92rem;
        }
        .pill-soft{
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: .38rem .68rem;
            font-size: .79rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .client-name{
            font-weight: 700;
            color: #101828;
        }
        .subtext{
            color: #667085;
            font-size: .84rem;
            line-height: 1.25;
        }
        .table-responsive{
            border-radius: 18px;
        }
        .mini-note{
            font-size: .8rem;
            color: #667085;
        }
        .sticky-col{
            position: sticky;
            right: 0;
            z-index: 3;
            background: #fff;
            box-shadow: -8px 0 12px rgba(16,24,40,.05);
        }
        thead .sticky-col{
            background: #f8f9fa;
            z-index: 4;
        }
        .actions-col{
            min-width: 170px;
            text-align: center;
        }
        .btn-stack .btn{
            min-width: 130px;
        }
        .compact-col{
            min-width: 105px;
        }
        .client-col{
            min-width: 210px;
        }
        .modal-xl-custom{
            max-width: 95vw;
        }
        .modal-iframe-wrap{
            height: 78vh;
        }
        .modal-iframe{
            width: 100%;
            height: 100%;
            border: 0;
            display: block;
            background: #fff;
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/navbar.php'; ?>

<div class="page-wrap container-fluid">

    <div class="card hero-card mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="bi bi-headset me-2"></i>Módulo Call Center
                    </h2>
                    <div class="opacity-75">
                        Seguimiento de clientes, liquidación estimada y oportunidad de recompra.
                    </div>
                </div>
                <div class="text-end">
                    <div class="small opacity-75">Acceso actual</div>
                    <div class="fw-semibold">Solo Admin</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card filter-card mb-4">
        <div class="card-body p-4">
            <form method="GET" class="row g-3">
                <div class="col-12">
                    <h5 class="mb-0 fw-bold">Filtros</h5>
                    <div class="mini-note">Se muestran 50 registros por página para que la vista cargue más ligera.</div>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Venta desde</label>
                    <input type="date" name="f_venta_ini" class="form-control" value="<?= h($f_venta_ini) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Venta hasta</label>
                    <input type="date" name="f_venta_fin" class="form-control" value="<?= h($f_venta_fin) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">1er pago desde</label>
                    <input type="date" name="f_pago_ini" class="form-control" value="<?= h($f_pago_ini) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">1er pago hasta</label>
                    <input type="date" name="f_pago_fin" class="form-control" value="<?= h($f_pago_fin) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Sucursal</label>
                    <select name="f_sucursal" class="form-select">
                        <option value="0">Todas</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= ((int)$f_sucursal === (int)$s['id']) ? 'selected' : '' ?>>
                                <?= h($s['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Financiera</label>
                    <select name="f_financiera" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($financieras as $fin): ?>
                            <option value="<?= h($fin) ?>" <?= ($f_financiera === $fin) ? 'selected' : '' ?>>
                                <?= h($fin) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Buscar</label>
                    <input
                        type="text"
                        name="f_q"
                        class="form-control"
                        value="<?= h($f_q) ?>"
                        placeholder="TAG, cliente, teléfono, sucursal, ejecutivo o financiera"
                    >
                </div>

                <div class="col-md-6 d-flex align-items-end justify-content-end gap-2">
                    <a href="callcenter_clientes.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Limpiar
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i>Aplicar filtros
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="kpi-value"><?= number_format($kpi_total) ?></div>
                    <div class="kpi-label mt-2">Clientes en reporte</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="kpi-value"><?= number_format($kpi_con_telefono) ?></div>
                    <div class="kpi-label mt-2">Con teléfono en esta página</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="kpi-value"><?= number_format($kpi_interesados) ?></div>
                    <div class="kpi-label mt-2">Interesados en esta página</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="kpi-value"><?= number_format($kpi_codigos_generados) ?></div>
                    <div class="kpi-label mt-2">Con código en esta página</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card table-card">
        <div class="card-body p-0">
            <div class="p-4 pb-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="mb-1 fw-bold">Reporte de clientes Call Center</h5>
                    <div class="mini-note">
                        La fecha aproximada de liquidación usa 1er pago y, si no existe, toma fecha de venta como respaldo.
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <div class="mini-note">
                        Mostrando <strong><?= number_format($desde) ?></strong> a <strong><?= number_format($hasta) ?></strong>
                        de <strong><?= number_format($totalRows) ?></strong> registros
                    </div>

                    <a href="exportar_callcenter_clientes_excel.php?<?= h(http_build_query($_GET)) ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-file-earmark-excel me-1"></i>Exportar Excel
                    </a>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="client-col">Cliente</th>
                            <th class="compact-col">Sucursal</th>
                            <th class="compact-col">Financiera</th>
                            <th class="compact-col">Venta</th>
                            <th class="compact-col">1er pago</th>
                            <th class="compact-col">Plazo</th>
                            <th class="compact-col">Liq. aprox.</th>
                            <th>Últ. seg.</th>
                            <th class="compact-col">Código</th>
                            <th class="sticky-col actions-col">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                No se encontraron registros con los filtros seleccionados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $telefono = trim((string)($r['telefono_cliente'] ?? ''));
                                $codigo   = trim((string)($r['codigo_callcenter'] ?? ''));
                                $estatusCodigo = trim((string)($r['estatus_codigo'] ?? ''));
                                $resultado = trim((string)($r['ultimo_resultado'] ?? ''));

                                $mensajeWa = "Hola " . trim((string)($r['nombre_cliente'] ?? '')) . ", te contactamos de Luga para dar seguimiento a tu plan y compartirte información sobre tu próxima compra.";
                                if ($codigo !== '') {
                                    $mensajeWa .= " Tu código de atención es: " . $codigo . ".";
                                }
                                $waUrl = buildWhatsAppUrl($telefono, $mensajeWa);
                            ?>
                            <tr>
                                <td>
                                    <div class="client-name"><?= h($r['nombre_cliente'] ?: 'Sin nombre') ?></div>
                                    <div class="subtext">TAG: <?= h($r['tag'] ?: '—') ?></div>
                                    <div class="subtext">Tel: <?= h($telefono ?: '—') ?></div>
                                    <div class="subtext">Ejecutivo: <?= h($r['ejecutivo_nombre'] ?: '—') ?></div>
                                </td>

                                <td>
                                    <div class="fw-semibold"><?= h($r['sucursal_nombre'] ?: '—') ?></div>
                                </td>

                                <td>
                                    <span class="pill-soft bg-light border text-dark">
                                        <i class="bi bi-bank"></i>
                                        <?= h($r['financiera'] ?: '—') ?>
                                    </span>
                                    <div class="subtext mt-1">Tipo: <?= h($r['tipo_venta'] ?: '—') ?></div>
                                </td>

                                <td>
                                    <div class="fw-semibold"><?= fmtDate($r['fecha_venta']) ?></div>
                                    <div class="subtext"><?= money($r['precio_venta'] ?? 0) ?></div>
                                </td>

                                <td>
                                    <div class="fw-semibold"><?= fmtDate($r['primer_pago']) ?></div>
                                </td>

                                <td>
                                    <span class="pill-soft bg-light border text-dark">
                                        <?= (int)($r['plazo_semanas'] ?? 0) ?> semanas
                                    </span>
                                </td>

                                <td>
                                    <div class="fw-semibold"><?= fmtDate($r['fecha_estimada_liquidacion']) ?></div>
                                    <div class="subtext">
                                        Base:
                                        <?php
                                            if (($r['base_calculo_liquidacion'] ?? '') === 'PRIMER_PAGO') {
                                                echo '1er pago';
                                            } elseif (($r['base_calculo_liquidacion'] ?? '') === 'FECHA_VENTA') {
                                                echo 'fecha venta';
                                            } else {
                                                echo 'sin base';
                                            }
                                        ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if ($resultado !== ''): ?>
                                        <span class="pill-soft <?= badgeResultadoClass($resultado) ?>">
                                            <?= h($resultado) ?>
                                        </span>
                                        <div class="subtext mt-1"><?= fmtDateTime($r['ultima_fecha_contacto']) ?></div>
                                        <?php if (!empty($r['ultimo_comentario'])): ?>
                                            <div class="subtext mt-1"><?= h(shortText((string)$r['ultimo_comentario'], 70)) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="pill-soft bg-light text-dark border">Sin seguimiento</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($codigo !== ''): ?>
                                        <div class="fw-semibold"><?= h($codigo) ?></div>
                                        <div class="mt-1">
                                            <span class="pill-soft <?= badgeCodigoClass($estatusCodigo) ?>">
                                                <?= h($estatusCodigo ?: '—') ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span class="pill-soft bg-light text-dark border">Sin código</span>
                                    <?php endif; ?>
                                </td>

                                <td class="sticky-col actions-col">
                                    <div class="btn-stack d-flex flex-column gap-1 align-items-center">
                                        <?php if ($telefono !== '' && $waUrl !== '#'): ?>
                                            <a href="<?= h($waUrl) ?>" target="_blank" class="btn btn-sm btn-success">
                                                <i class="bi bi-whatsapp me-1"></i>WhatsApp
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled>
                                                <i class="bi bi-whatsapp me-1"></i>Sin teléfono
                                            </button>
                                        <?php endif; ?>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary btn-modal-view"
                                            data-url="callcenter_generar_codigo.php?id_venta=<?= (int)$r['id'] ?>"
                                            data-title="Generar código"
                                        >
                                            <i class="bi bi-ticket-perforated me-1"></i>Código
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-dark btn-modal-view"
                                            data-url="callcenter_seguimiento.php?id_venta=<?= (int)$r['id'] ?>"
                                            data-title="Seguimiento"
                                        >
                                            <i class="bi bi-journal-text me-1"></i>Seguimiento
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-top">
                <nav aria-label="Paginación">
                    <ul class="pagination mb-0 flex-wrap">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= ($page <= 1) ? '#' : h(pageUrl($queryBase, $page - 1)) ?>">Anterior</a>
                        </li>

                        <?php
                        $start = max(1, $page - 2);
                        $end   = min($totalPages, $page + 2);

                        if ($start > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= h(pageUrl($queryBase, 1)) ?>">1</a></li>
                            <?php if ($start > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start; $i <= $end; $i++): ?>
                            <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= h(pageUrl($queryBase, $i)) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($end < $totalPages): ?>
                            <?php if ($end < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?= h(pageUrl($queryBase, $totalPages)) ?>"><?= $totalPages ?></a></li>
                        <?php endif; ?>

                        <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= ($page >= $totalPages) ? '#' : h(pageUrl($queryBase, $page + 1)) ?>">Siguiente</a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>

</div>

<!-- MODAL GLOBAL -->
<div class="modal fade" id="modalCallcenter" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl modal-xl-custom">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalCallcenterTitle">Detalle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-0 modal-iframe-wrap">
                <iframe id="modalCallcenterIframe" class="modal-iframe" src=""></iframe>
            </div>
        </div>
    </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('modalCallcenter');
    const modal = new bootstrap.Modal(modalEl);
    const iframe = document.getElementById('modalCallcenterIframe');
    const title = document.getElementById('modalCallcenterTitle');

    document.querySelectorAll('.btn-modal-view').forEach(btn => {
        btn.addEventListener('click', function () {
            const url = this.getAttribute('data-url') || '';
            const text = this.getAttribute('data-title') || 'Detalle';

            title.textContent = text;
            iframe.src = url;
            modal.show();
        });
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        iframe.src = '';
        window.location.reload();
    });
});
</script>
</body>
</html>