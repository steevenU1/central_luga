<?php
/**
 * Helper global para exports Excel reales con PhpSpreadsheet
 * Estilo Zentral
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

if (!function_exists('zentralExcelAutoload')) {
    function zentralExcelAutoload(): void
    {
        $paths = [
            __DIR__ . '/../vendor/autoload.php',
            __DIR__ . '/../../vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }

        throw new Exception('No se encontró vendor/autoload.php. Verifica instalación de PhpSpreadsheet.');
    }
}

zentralExcelAutoload();

if (!function_exists('hExcel')) {
    function hExcel($value): string
    {
        return trim((string)($value ?? ''));
    }
}

if (!function_exists('zentralCrearSpreadsheet')) {
    function zentralCrearSpreadsheet(string $titulo = 'Reporte Zentral'): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Zentral')
            ->setLastModifiedBy('Zentral')
            ->setTitle($titulo)
            ->setSubject($titulo)
            ->setDescription('Reporte generado automáticamente desde Zentral');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($titulo, 0, 31));

        return $spreadsheet;
    }
}

if (!function_exists('zentralAgregarEncabezado')) {
    function zentralAgregarEncabezado(
        Worksheet $sheet,
        string $titulo,
        string $subtitulo = '',
        string $logoPath = ''
    ): int {
        $sheet->mergeCells('A1:H1');
        $sheet->mergeCells('A2:H2');
        $sheet->mergeCells('A3:H3');

        $sheet->setCellValue('A1', $titulo);
        $sheet->setCellValue('A2', $subtitulo ?: 'Reporte generado desde Zentral');
        $sheet->setCellValue('A3', 'Generado: ' . date('d/m/Y H:i'));

        $sheet->getRowDimension(1)->setRowHeight(34);
        $sheet->getRowDimension(2)->setRowHeight(22);
        $sheet->getRowDimension(3)->setRowHeight(20);

        $sheet->getStyle('A1:H3')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => '0B1220'],
            ],
            'font' => [
                'color' => ['rgb' => 'FFFFFF'],
                'name' => 'Arial',
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);
        $sheet->getStyle('A2')->getFont()->setSize(11);
        $sheet->getStyle('A3')->getFont()->setSize(10)->setItalic(true);

        $defaultLogo = __DIR__ . '/../img/LogoZentralShort.png';
        $finalLogo = $logoPath ?: $defaultLogo;

        if (file_exists($finalLogo)) {
            $drawing = new Drawing();
            $drawing->setName('Zentral');
            $drawing->setDescription('Logo Zentral');
            $drawing->setPath($finalLogo);
            $drawing->setHeight(52);
            $drawing->setCoordinates('G1');
            $drawing->setOffsetX(10);
            $drawing->setOffsetY(6);
            $drawing->setWorksheet($sheet);
        }

        return 5;
    }
}

if (!function_exists('zentralAgregarKPIs')) {
    function zentralAgregarKPIs(Worksheet $sheet, array $kpis, int $filaInicio = 5): int
    {
        if (empty($kpis)) {
            return $filaInicio;
        }

        $col = 1;

        foreach ($kpis as $label => $value) {
            $colLetra = Coordinate::stringFromColumnIndex($col);
            $nextColLetra = Coordinate::stringFromColumnIndex($col + 1);

            $sheet->mergeCells("{$colLetra}{$filaInicio}:{$nextColLetra}{$filaInicio}");
            $sheet->mergeCells("{$colLetra}" . ($filaInicio + 1) . ":{$nextColLetra}" . ($filaInicio + 1));

            $sheet->setCellValue("{$colLetra}{$filaInicio}", $label);
            $sheet->setCellValue("{$colLetra}" . ($filaInicio + 1), $value);

            $sheet->getStyle("{$colLetra}{$filaInicio}:{$nextColLetra}" . ($filaInicio + 1))->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'EAF3FF'],
                ],
                'font' => [
                    'name' => 'Arial',
                    'color' => ['rgb' => '0B1220'],
                ],
                'borders' => [
                    'outline' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'BFD7F5'],
                    ],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);

            $sheet->getStyle("{$colLetra}{$filaInicio}")->getFont()->setBold(true)->setSize(10);
            $sheet->getStyle("{$colLetra}" . ($filaInicio + 1))->getFont()->setBold(true)->setSize(14);

            $col += 3;
        }

        return $filaInicio + 3;
    }
}

if (!function_exists('zentralAgregarTabla')) {
    function zentralAgregarTabla(
        Worksheet $sheet,
        array $headers,
        array $rows,
        int $filaInicio = 8,
        array $moneyColumns = [],
        array $numberColumns = []
    ): int {
        $col = 1;

        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($col, $filaInicio, $header);
            $col++;
        }

        $totalCols = count($headers);
        $lastCol = Coordinate::stringFromColumnIndex($totalCols);
        $headerRange = "A{$filaInicio}:{$lastCol}{$filaInicio}";

        $sheet->getStyle($headerRange)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => '0B1220'],
            ],
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'name' => 'Arial',
                'size' => 10,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getRowDimension($filaInicio)->setRowHeight(24);

        $fila = $filaInicio + 1;

        foreach ($rows as $row) {
            $col = 1;

            foreach ($headers as $key => $header) {
                if (is_array($row)) {
                    $value = $row[$key] ?? $row[$header] ?? '';
                } else {
                    $value = '';
                }

                $sheet->setCellValueByColumnAndRow($col, $fila, $value);
                $col++;
            }

            $fila++;
        }

        $lastRow = max($fila - 1, $filaInicio);
        $tableRange = "A{$filaInicio}:{$lastCol}{$lastRow}";

        $sheet->getStyle($tableRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D9E2EC'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'font' => [
                'name' => 'Arial',
                'size' => 10,
            ],
        ]);

        if ($lastRow > $filaInicio) {
            $sheet->setAutoFilter("A{$filaInicio}:{$lastCol}{$lastRow}");
        }

        $sheet->freezePane('A' . ($filaInicio + 1));

        foreach ($moneyColumns as $colIndex) {
            $letter = is_numeric($colIndex)
                ? Coordinate::stringFromColumnIndex((int)$colIndex)
                : strtoupper((string)$colIndex);

            $sheet->getStyle("{$letter}" . ($filaInicio + 1) . ":{$letter}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('"$"#,##0.00');
        }

        foreach ($numberColumns as $colIndex) {
            $letter = is_numeric($colIndex)
                ? Coordinate::stringFromColumnIndex((int)$colIndex)
                : strtoupper((string)$colIndex);

            $sheet->getStyle("{$letter}" . ($filaInicio + 1) . ":{$letter}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        }

        return $lastRow + 2;
    }
}

if (!function_exists('zentralAutoSize')) {
    function zentralAutoSize(Worksheet $sheet, int $maxCol = 50): void
    {
        $highestColumn = $sheet->getHighestColumn();
        $highestIndex = Coordinate::columnIndexFromString($highestColumn);
        $limit = min($highestIndex, $maxCol);

        for ($i = 1; $i <= $limit; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }
}

if (!function_exists('zentralAplicarPie')) {
    function zentralAplicarPie(Worksheet $sheet, int $fila): void
    {
        $sheet->mergeCells("A{$fila}:H{$fila}");
        $sheet->setCellValue("A{$fila}", 'Generado automáticamente por Zentral');

        $sheet->getStyle("A{$fila}:H{$fila}")->applyFromArray([
            'font' => [
                'italic' => true,
                'size' => 9,
                'color' => ['rgb' => '64748B'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
            ],
        ]);
    }
}

if (!function_exists('zentralDescargarExcel')) {
    function zentralDescargarExcel(Spreadsheet $spreadsheet, string $filename): void
    {
        if (ob_get_length()) {
            ob_end_clean();
        }

        $filename = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename);

        if (!str_ends_with(strtolower($filename), '.xlsx')) {
            $filename .= '.xlsx';
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}