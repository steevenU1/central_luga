<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';

// 🔹 Filtros desde GET
$filtroImei = $_GET['imei'] ?? '';
$filtroSucursal = $_GET['sucursal'] ?? '';
$filtroEstatus = $_GET['estatus'] ?? '';

// 🔹 Consulta base
$sql = "
    SELECT i.id AS id_inventario,
           s.nombre AS sucursal,
           p.marca, p.modelo, p.color, p.capacidad,
           p.imei1, p.imei2, p.costo, p.precio_lista,
           (p.precio_lista - p.costo) AS profit,
           i.estatus, i.fecha_ingreso
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    INNER JOIN sucursales s ON s.id = i.id_sucursal
    WHERE i.estatus IN ('Disponible','En tránsito')
";

$params = [];
$types = "";

// 🔹 Filtro por sucursal
if ($filtroSucursal !== '') {
    $sql .= " AND s.id = ?";
    $params[] = $filtroSucursal;
    $types .= "i";
}

// 🔹 Filtro por IMEI
if ($filtroImei !== '') {
    $sql .= " AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)";
    $like = "%$filtroImei%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

// 🔹 Filtro por estatus
if ($filtroEstatus !== '') {
    $sql .= " AND i.estatus = ?";
    $params[] = $filtroEstatus;
    $types .= "s";
}

$sql .= " ORDER BY s.nombre, i.fecha_ingreso DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// 🔹 Cabeceras para Excel
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=inventario_global.xls");
header("Pragma: no-cache");
header("Expires: 0");

// 🔹 Encabezado de columnas
echo "ID\tSucursal\tMarca\tModelo\tColor\tCapacidad\tIMEI1\tIMEI2\tCosto ($)\tPrecio Lista ($)\tProfit ($)\tEstatus\tFecha Ingreso\n";

// 🔹 Filas de datos
while ($row = $result->fetch_assoc()) {
    echo $row['id_inventario']."\t".
         $row['sucursal']."\t".
         $row['marca']."\t".
         $row['modelo']."\t".
         $row['color']."\t".
         ($row['capacidad'] ?? '-')."\t".
         ($row['imei1'] ?? '-')."\t".
         ($row['imei2'] ?? '-')."\t".
         number_format($row['costo'],2)."\t".
         number_format($row['precio_lista'],2)."\t".
         number_format($row['profit'],2)."\t".
         $row['estatus']."\t".
         $row['fecha_ingreso']."\n";
}

$stmt->close();
$conn->close();
?>
