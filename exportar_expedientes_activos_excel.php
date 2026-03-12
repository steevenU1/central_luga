<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

$mi_rol = $_SESSION['rol'] ?? 'Ejecutivo';
if (!in_array($mi_rol, ['Admin', 'Gerente'], true)) {
    http_response_code(403);
    exit('Sin permiso.');
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

/* =========================================================
   Helpers
========================================================= */
function clean_value($v): string {
    if ($v === null) return '';
    if ($v === '0000-00-00') return '';
    return trim((string)$v);
}

/* =========================================================
   Consulta de empleados activos
========================================================= */
$sql = "
    SELECT
        u.id AS numero_empleado,
        u.nombre,
        u.usuario,
        u.correo,
        u.rol AS puesto,
        u.activo,
        s.nombre AS sucursal,
        s.zona AS zona,

        ue.tel_contacto,
        ue.fecha_nacimiento,
        ue.fecha_ingreso,
        ue.fecha_baja,
        ue.motivo_baja,
        ue.curp,
        ue.nss,
        ue.rfc,
        ue.genero,
        ue.contacto_emergencia,
        ue.tel_emergencia,
        ue.clabe,
        ue.banco,
        ue.edad_years,
        ue.antiguedad_meses,
        ue.antiguedad_years,
        ue.contrato_status,
        ue.registro_patronal,
        ue.fecha_alta_imss,
        ue.talla_uniforme,
        ue.payjoy_status,
        ue.krediya_status,
        ue.lespago_status,
        ue.innovm_status,
        ue.central_status,
        ue.created_at,
        ue.updated_at
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN usuarios_expediente ue ON ue.usuario_id = u.id
    WHERE u.activo = 1
      AND (s.subtipo IS NULL OR s.subtipo NOT IN ('Subdistribuidor','Master Admin'))
    ORDER BY s.nombre ASC, u.nombre ASC
";

$res = $conn->query($sql);
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}

/* =========================================================
   Encabezados del archivo
========================================================= */
$headers = [
    'Número de empleado',
    'Nombre',
    'Usuario',
    'Correo',
    'Puesto',
    'Sucursal',
    'Zona',
    'Teléfono',
    'Fecha nacimiento',
    'Fecha ingreso',
    'Fecha baja',
    'Motivo baja',
    'CURP',
    'NSS',
    'RFC',
    'Género',
    'Contacto emergencia',
    'Teléfono emergencia',
    'CLABE',
    'Banco',
    'Edad',
    'Antigüedad meses',
    'Antigüedad años',
    'Contrato',
    'Registro patronal',
    'Fecha alta IMSS',
    'Talla uniforme',
    'PayJoy',
    'Krediya',
    'LesPago',
    'InnovM',
    'Central',
    'Creado en',
    'Actualizado en'
];

/* =========================================================
   Intentar XLSX con PhpSpreadsheet
========================================================= */
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

$autoloadFound = null;
foreach ($autoloadPaths as $ap) {
    if (is_file($ap)) {
        $autoloadFound = $ap;
        break;
    }
}

if ($autoloadFound) {
    require_once $autoloadFound;

    if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Empleados Activos');

        // Encabezados
        $col = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($col, 1, $header);
            $col++;
        }

        // Datos
        $rowNum = 2;
        foreach ($rows as $r) {
            $sheet->setCellValueByColumnAndRow(1,  $rowNum, clean_value($r['numero_empleado']));
            $sheet->setCellValueByColumnAndRow(2,  $rowNum, clean_value($r['nombre']));
            $sheet->setCellValueByColumnAndRow(3,  $rowNum, clean_value($r['usuario']));
            $sheet->setCellValueByColumnAndRow(4,  $rowNum, clean_value($r['correo']));
            $sheet->setCellValueByColumnAndRow(5,  $rowNum, clean_value($r['puesto']));
            $sheet->setCellValueByColumnAndRow(6,  $rowNum, clean_value($r['sucursal']));
            $sheet->setCellValueByColumnAndRow(7,  $rowNum, clean_value($r['zona']));
            $sheet->setCellValueByColumnAndRow(8,  $rowNum, clean_value($r['tel_contacto']));
            $sheet->setCellValueByColumnAndRow(9,  $rowNum, clean_value($r['fecha_nacimiento']));
            $sheet->setCellValueByColumnAndRow(10, $rowNum, clean_value($r['fecha_ingreso']));
            $sheet->setCellValueByColumnAndRow(11, $rowNum, clean_value($r['fecha_baja']));
            $sheet->setCellValueByColumnAndRow(12, $rowNum, clean_value($r['motivo_baja']));
            $sheet->setCellValueByColumnAndRow(13, $rowNum, clean_value($r['curp']));
            $sheet->setCellValueByColumnAndRow(14, $rowNum, clean_value($r['nss']));
            $sheet->setCellValueByColumnAndRow(15, $rowNum, clean_value($r['rfc']));
            $sheet->setCellValueByColumnAndRow(16, $rowNum, clean_value($r['genero']));
            $sheet->setCellValueByColumnAndRow(17, $rowNum, clean_value($r['contacto_emergencia']));
            $sheet->setCellValueByColumnAndRow(18, $rowNum, clean_value($r['tel_emergencia']));
            $sheet->setCellValueByColumnAndRow(19, $rowNum, clean_value($r['clabe']));
            $sheet->setCellValueByColumnAndRow(20, $rowNum, clean_value($r['banco']));
            $sheet->setCellValueByColumnAndRow(21, $rowNum, clean_value($r['edad_years']));
            $sheet->setCellValueByColumnAndRow(22, $rowNum, clean_value($r['antiguedad_meses']));
            $sheet->setCellValueByColumnAndRow(23, $rowNum, clean_value($r['antiguedad_years']));
            $sheet->setCellValueByColumnAndRow(24, $rowNum, clean_value($r['contrato_status']));
            $sheet->setCellValueByColumnAndRow(25, $rowNum, clean_value($r['registro_patronal']));
            $sheet->setCellValueByColumnAndRow(26, $rowNum, clean_value($r['fecha_alta_imss']));
            $sheet->setCellValueByColumnAndRow(27, $rowNum, clean_value($r['talla_uniforme']));
            $sheet->setCellValueByColumnAndRow(28, $rowNum, clean_value($r['payjoy_status']));
            $sheet->setCellValueByColumnAndRow(29, $rowNum, clean_value($r['krediya_status']));
            $sheet->setCellValueByColumnAndRow(30, $rowNum, clean_value($r['lespago_status']));
            $sheet->setCellValueByColumnAndRow(31, $rowNum, clean_value($r['innovm_status']));
            $sheet->setCellValueByColumnAndRow(32, $rowNum, clean_value($r['central_status']));
            $sheet->setCellValueByColumnAndRow(33, $rowNum, clean_value($r['created_at']));
            $sheet->setCellValueByColumnAndRow(34, $rowNum, clean_value($r['updated_at']));
            $rowNum++;
        }

        // Estilos encabezado
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $headerRange = "A1:{$lastCol}1";

        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1D4ED8');

        $sheet->freezePane('A2');
        $sheet->setAutoFilter($headerRange);

        // Auto width
        for ($i = 1; $i <= count($headers); $i++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        $filename = 'empleados_activos_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}

/* =========================================================
   Fallback CSV compatible con Excel
========================================================= */
$filename = 'empleados_activos_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// BOM UTF-8 para Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Encabezados
fputcsv($output, $headers);

// Filas
foreach ($rows as $r) {
    fputcsv($output, [
        clean_value($r['numero_empleado']),
        clean_value($r['nombre']),
        clean_value($r['usuario']),
        clean_value($r['correo']),
        clean_value($r['puesto']),
        clean_value($r['sucursal']),
        clean_value($r['zona']),
        clean_value($r['tel_contacto']),
        clean_value($r['fecha_nacimiento']),
        clean_value($r['fecha_ingreso']),
        clean_value($r['fecha_baja']),
        clean_value($r['motivo_baja']),
        clean_value($r['curp']),
        clean_value($r['nss']),
        clean_value($r['rfc']),
        clean_value($r['genero']),
        clean_value($r['contacto_emergencia']),
        clean_value($r['tel_emergencia']),
        clean_value($r['clabe']),
        clean_value($r['banco']),
        clean_value($r['edad_years']),
        clean_value($r['antiguedad_meses']),
        clean_value($r['antiguedad_years']),
        clean_value($r['contrato_status']),
        clean_value($r['registro_patronal']),
        clean_value($r['fecha_alta_imss']),
        clean_value($r['talla_uniforme']),
        clean_value($r['payjoy_status']),
        clean_value($r['krediya_status']),
        clean_value($r['lespago_status']),
        clean_value($r['innovm_status']),
        clean_value($r['central_status']),
        clean_value($r['created_at']),
        clean_value($r['updated_at']),
    ]);
}

fclose($output);
exit;