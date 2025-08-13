<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: 403.php");
    exit();
}
$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','GerenteZona'];
if (!in_array($ROL, $ALLOWED, true)) {
    header("Location: 403.php");
    exit();
}

include 'db.php';

/* ===== Filtros (mismos que la vista) ===== */
$filtroImei       = $_GET['imei']        ?? '';
$filtroSucursal   = $_GET['sucursal']    ?? '';
$filtroEstatus    = $_GET['estatus']     ?? '';
$filtroAntiguedad = $_GET['antiguedad']  ?? '';
$filtroPrecioMin  = $_GET['precio_min']  ?? '';
$filtroPrecioMax  = $_GET['precio_max']  ?? '';

/* ===== Consulta ===== */
$sql = "
    SELECT i.id AS id_inventario,
           s.nombre AS sucursal,
           p.marca, p.modelo, p.color, p.capacidad,
           p.codigo_producto,           -- código en BD
           p.proveedor,                 -- proveedor
           p.imei1, p.imei2,
           p.costo, p.precio_lista,
           (p.precio_lista - p.costo) AS profit,
           p.tipo_producto,             -- para fallback del código
           i.estatus, i.fecha_ingreso,
           TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) AS antiguedad_dias
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    INNER JOIN sucursales s ON s.id = i.id_sucursal
    WHERE i.estatus IN ('Disponible','En tránsito')
";

$params = [];
$types  = "";

if ($filtroSucursal !== '') {
    $sql .= " AND s.id = ?";
    $params[] = (int)$filtroSucursal;
    $types .= "i";
}
if ($filtroImei !== '') {
    $sql .= " AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)";
    $like = "%$filtroImei%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}
if ($filtroEstatus !== '') {
    $sql .= " AND i.estatus = ?";
    $params[] = $filtroEstatus;
    $types .= "s";
}
if ($filtroAntiguedad == '<30') {
    $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) < 30";
} elseif ($filtroAntiguedad == '30-90') {
    $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) BETWEEN 30 AND 90";
} elseif ($filtroAntiguedad == '>90') {
    $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) > 90";
}
if ($filtroPrecioMin !== '') {
    $sql .= " AND p.precio_lista >= ?";
    $params[] = (float)$filtroPrecioMin;
    $types .= "d";
}
if ($filtroPrecioMax !== '') {
    $sql .= " AND p.precio_lista <= ?";
    $params[] = (float)$filtroPrecioMax;
    $types .= "d";
}

$sql .= " ORDER BY s.nombre, i.fecha_ingreso DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

/* ===== Cabeceras: Excel abre HTML como libro ===== */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=inventario_global.xls");
header("Pragma: no-cache");
header("Expires: 0");

// BOM para UTF-8
echo "\xEF\xBB\xBF";

/* Helpers */
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nf($n) { return number_format((float)$n, 2, '.', ''); }
function codigo_fallback($row) {
    $partes = array_filter([
        $row['tipo_producto'] ?? '',
        $row['marca'] ?? '',
        $row['modelo'] ?? '',
        $row['color'] ?? '',
        $row['capacidad'] ?? ''
    ], fn($x) => $x !== '');
    if (!$partes) return '-';
    $code = strtoupper(implode('-', $partes));
    return preg_replace('/\s+/', '', $code);
}

/* ===== HTML Table ===== */
echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<table border='1' cellspacing='0' cellpadding='4'>";
echo "<tr style='background:#222;color:#fff;font-weight:bold'>"
    ."<td>ID</td>"
    ."<td>Sucursal</td>"
    ."<td>Marca</td>"
    ."<td>Modelo</td>"
    ."<td>Código</td>"
    ."<td>Color</td>"
    ."<td>Capacidad</td>"
    ."<td>IMEI1</td>"
    ."<td>IMEI2</td>"
    ."<td>Proveedor</td>"
    ."<td>Costo ($)</td>"
    ."<td>Precio Lista ($)</td>"
    ."<td>Profit ($)</td>"
    ."<td>Estatus</td>"
    ."<td>Fecha Ingreso</td>"
    ."<td>Antigüedad (días)</td>"
    ."</tr>";

while ($row = $result->fetch_assoc()) {
    $codigo = $row['codigo_producto'] ?? '';
    if ($codigo === '' || $codigo === null) {
        $codigo = codigo_fallback($row);
    }

    echo "<tr>"
        ."<td>".h($row['id_inventario'])."</td>"
        ."<td>".h($row['sucursal'])."</td>"
        ."<td>".h($row['marca'])."</td>"
        ."<td>".h($row['modelo'])."</td>"
        ."<td>".h($codigo)."</td>"
        ."<td>".h($row['color'])."</td>"
        ."<td>".h($row['capacidad'] ?? '-')."</td>"
        // Prefijo ' para forzar texto y no se trunque IMEI largo en Excel
        ."<td>'".h($row['imei1'] ?? '-')."</td>"
        ."<td>'".h($row['imei2'] ?? '-')."</td>"
        ."<td>".h($row['proveedor'] ?? '-')."</td>"
        ."<td>".nf($row['costo'])."</td>"
        ."<td>".nf($row['precio_lista'])."</td>"
        ."<td>".nf($row['profit'])."</td>"
        ."<td>".h($row['estatus'])."</td>"
        ."<td>".h($row['fecha_ingreso'])."</td>"
        ."<td>".h($row['antiguedad_dias'])."</td>"
        ."</tr>";
}
echo "</table></body></html>";

$stmt->close();
$conn->close();
