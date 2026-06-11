<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

$id_auditoria = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id_auditoria <= 0) {
    exit('Auditoría no válida.');
}

/* ==========================================
   DATOS GENERALES
========================================== */
$stmtAud = $conn->prepare("
    SELECT a.*, s.nombre AS sucursal_nombre
    FROM auditorias a
    INNER JOIN sucursales s ON s.id = a.id_sucursal
    WHERE a.id = ?
    LIMIT 1
");
$stmtAud->bind_param("i", $id_auditoria);
$stmtAud->execute();
$auditoria = $stmtAud->get_result()->fetch_assoc();

if (!$auditoria) {
    exit('Auditoría no encontrada.');
}

/* ==========================================
   FALTANTES SERIALIZADOS
========================================== */
$stmt = $conn->prepare("
    SELECT codigo_producto, marca, modelo, color, capacidad,
           imei1, imei2
    FROM auditorias_snapshot
    WHERE id_auditoria = ?
      AND escaneado = 0
    ORDER BY marca, modelo
");
$stmt->bind_param("i", $id_auditoria);
$stmt->execute();
$faltantes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ==========================================
   DIFERENCIAS NO SERIALIZADOS
========================================== */
$stmt = $conn->prepare("
    SELECT codigo_producto, marca, modelo, color, capacidad,
           cantidad_sistema, cantidad_contada, diferencia,
           observaciones
    FROM auditorias_snapshot_cantidades
    WHERE id_auditoria = ?
      AND diferencia IS NOT NULL
      AND diferencia <> 0
    ORDER BY marca, modelo
");
$stmt->bind_param("i", $id_auditoria);
$stmt->execute();
$diferencias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ==========================================
   INCIDENCIAS
========================================== */
$stmt = $conn->prepare("
    SELECT imei_escaneado, tipo_incidencia, detalle,
           referencia_tabla, referencia_id, created_at
    FROM auditorias_incidencias
    WHERE id_auditoria = ?
    ORDER BY id DESC
");
$stmt->bind_param("i", $id_auditoria);
$stmt->execute();
$incidencias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ==========================================
   CREAR EXCEL
========================================== */
$spreadsheet = new Spreadsheet();

/* ==========================================
   HOJA 1: EQUIPOS FALTANTES
========================================== */
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Equipos Faltantes');

$headers = [
    'Código', 'Marca', 'Modelo', 'Color',
    'Capacidad', 'IMEI 1', 'IMEI 2'
];

$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '1', $header);
    $col++;
}

$row = 2;
foreach ($faltantes as $item) {
    $sheet->setCellValue("A$row", $item['codigo_producto']);
    $sheet->setCellValue("B$row", $item['marca']);
    $sheet->setCellValue("C$row", $item['modelo']);
    $sheet->setCellValue("D$row", $item['color']);
    $sheet->setCellValue("E$row", $item['capacidad']);
    $sheet->setCellValue("F$row", $item['imei1']);
    $sheet->setCellValue("G$row", $item['imei2']);
    $row++;
}

/* ==========================================
   HOJA 2: NO SERIALIZADOS
========================================== */
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('No Serializados');

$headers2 = [
    'Código', 'Marca', 'Modelo', 'Color',
    'Capacidad', 'Sistema', 'Contado',
    'Diferencia', 'Observaciones'
];

$col = 'A';
foreach ($headers2 as $header) {
    $sheet2->setCellValue($col . '1', $header);
    $col++;
}

$row = 2;
foreach ($diferencias as $item) {
    $sheet2->setCellValue("A$row", $item['codigo_producto']);
    $sheet2->setCellValue("B$row", $item['marca']);
    $sheet2->setCellValue("C$row", $item['modelo']);
    $sheet2->setCellValue("D$row", $item['color']);
    $sheet2->setCellValue("E$row", $item['capacidad']);
    $sheet2->setCellValue("F$row", $item['cantidad_sistema']);
    $sheet2->setCellValue("G$row", $item['cantidad_contada']);
    $sheet2->setCellValue("H$row", $item['diferencia']);
    $sheet2->setCellValue("I$row", $item['observaciones']);
    $row++;
}

/* ==========================================
   HOJA 3: INCIDENCIAS
========================================== */
$sheet3 = $spreadsheet->createSheet();
$sheet3->setTitle('Incidencias');

$headers3 = [
    'IMEI', 'Tipo', 'Detalle',
    'Referencia', 'Fecha'
];

$col = 'A';
foreach ($headers3 as $header) {
    $sheet3->setCellValue($col . '1', $header);
    $col++;
}

$row = 2;
foreach ($incidencias as $item) {
    $referencia = $item['referencia_tabla'];
    if (!empty($item['referencia_id'])) {
        $referencia .= ' #' . $item['referencia_id'];
    }

    $sheet3->setCellValue("A$row", $item['imei_escaneado']);
    $sheet3->setCellValue("B$row", $item['tipo_incidencia']);
    $sheet3->setCellValue("C$row", $item['detalle']);
    $sheet3->setCellValue("D$row", $referencia);
    $sheet3->setCellValue("E$row", $item['created_at']);
    $row++;
}

/* ==========================================
   AUTOAJUSTAR COLUMNAS
========================================== */
foreach ($spreadsheet->getAllSheets() as $sheetObj) {
    foreach (range('A', 'Z') as $col) {
        $sheetObj->getColumnDimension($col)->setAutoSize(true);
    }
}

/* ==========================================
   DESCARGA
========================================== */
$folio = preg_replace('/[^A-Za-z0-9_-]/', '_', $auditoria['folio']);
$filename = "Conciliacion_Auditoria_{$folio}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;