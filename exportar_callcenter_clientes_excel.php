<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['id_usuario'])) {
    exit('Acceso no autorizado');
}

$ROL = trim((string)($_SESSION['rol'] ?? ''));
if ($ROL !== 'Admin') {
    exit('Acceso no autorizado');
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

if (!function_exists('fmtDate')) {
    function fmtDate($f): string
    {
        if (empty($f) || $f === '0000-00-00' || $f === '0000-00-00 00:00:00') return '';
        $ts = strtotime($f);
        return $ts ? date('d/m/Y', $ts) : '';
    }
}

if (!function_exists('fmtDateTime')) {
    function fmtDateTime($f): string
    {
        if (empty($f) || $f === '0000-00-00' || $f === '0000-00-00 00:00:00') return '';
        $ts = strtotime($f);
        return $ts ? date('d/m/Y H:i', $ts) : '';
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
   SQL EXPORT
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

    s.nombre AS sucursal_nombre,
    u.nombre AS ejecutivo_nombre,

    CASE
        WHEN v.primer_pago IS NOT NULL
             AND v.primer_pago <> '0000-00-00'
             AND v.plazo_semanas IS NOT NULL
        THEN DATE_ADD(v.primer_pago, INTERVAL v.plazo_semanas WEEK)
        ELSE NULL
    END AS fecha_estimada_liquidacion,

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
        WHEN v.primer_pago IS NULL OR v.primer_pago = '0000-00-00' THEN 1
        ELSE 0
    END,
    fecha_estimada_liquidacion ASC,
    v.fecha_venta DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    exit('Error preparando exportación: ' . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();

/* =========================================================
   HEADERS EXCEL
========================================================= */
$filename = 'callcenter_clientes_' . date('Ymd_His') . '.xls';

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";
?>
<table border="1">
    <thead>
        <tr style="background:#d9eaf7; font-weight:bold;">
            <th>TAG</th>
            <th>Cliente</th>
            <th>Teléfono</th>
            <th>Sucursal</th>
            <th>Ejecutivo</th>
            <th>Financiera</th>
            <th>Tipo venta</th>
            <th>Precio venta</th>
            <th>Fecha venta</th>
            <th>Primer pago</th>
            <th>Plazo semanas</th>
            <th>Liq. aprox.</th>
            <th>Últ. resultado</th>
            <th>Fecha últ. seg.</th>
            <th>Últ. comentario</th>
            <th>Código</th>
            <th>Estatus código</th>
            <th>Fecha código</th>
            <th>Vigencia código</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($r = $res->fetch_assoc()): ?>
            <tr>
                <td><?= h($r['tag']) ?></td>
                <td><?= h($r['nombre_cliente']) ?></td>
                <td style="mso-number-format:'\@';"><?= h($r['telefono_cliente']) ?></td>
                <td><?= h($r['sucursal_nombre']) ?></td>
                <td><?= h($r['ejecutivo_nombre']) ?></td>
                <td><?= h($r['financiera']) ?></td>
                <td><?= h($r['tipo_venta']) ?></td>
                <td><?= number_format((float)($r['precio_venta'] ?? 0), 2) ?></td>
                <td><?= h(fmtDate($r['fecha_venta'])) ?></td>
                <td><?= h(fmtDate($r['primer_pago'])) ?></td>
                <td><?= (int)($r['plazo_semanas'] ?? 0) ?></td>
                <td><?= h(fmtDate($r['fecha_estimada_liquidacion'])) ?></td>
                <td><?= h($r['ultimo_resultado']) ?></td>
                <td><?= h(fmtDateTime($r['ultima_fecha_contacto'])) ?></td>
                <td><?= h($r['ultimo_comentario']) ?></td>
                <td><?= h($r['codigo_callcenter']) ?></td>
                <td><?= h($r['estatus_codigo']) ?></td>
                <td><?= h(fmtDate($r['fecha_codigo'])) ?></td>
                <td><?= h(fmtDate($r['fecha_vigencia_codigo'])) ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>