<?php
/**
 * api_mundial_ventas.php
 * API de ventas válidas para Copa Mundial Zentral
 *
 * Colocar este archivo en:
 *   /api/api_mundial_ventas.php
 *
 * En LUGA:
 *   $EMPRESA_API = 'LUGA';
 *
 * En NANO:
 *   $EMPRESA_API = 'NANO';
 *
 * Parámetros esperados:
 *   ?token=TU_TOKEN&fecha_inicio=YYYY-MM-DD&fecha_fin=YYYY-MM-DD
 *
 * Reglas:
 * - Solo sucursales propias: sucursales.subtipo = 'Propia'
 * - Solo ventas de propiedad propia cuando exista columna ventas.propietario
 * - No cuenta detalle_venta.es_combo = 1
 * - No cuenta productos tipo Modem/MiFi
 * - 1 producto válido vendido = 1 gol
 * - Monto vendido para desempate: SUM proporcional de detalle válido
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('America/Mexico_City');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =========================================================
   CONFIGURACIÓN
========================================================= */

/*
   Cambiar según el sistema:
   LUGA en lugaph.site
   NANO en nanored.site
*/
$EMPRESA_API = 'LUGA';

/*
   Cambia este token por uno fuerte y diferente al subir a producción.
   El consumidor FIFA/Zentral deberá enviar el mismo token.
*/
$API_TOKEN = 'MundialZentral2026_LugaNano';

/*
   Ruta del db.php principal del sistema.
   Si este archivo vive en /api, normalmente db.php está un nivel arriba.
*/
require_once __DIR__ . '/../db.php';

/* =========================================================
   RESPUESTAS
========================================================= */

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function failResponse(string $message, int $status = 400, array $extra = []): void
{
    jsonResponse(array_merge([
        'ok' => false,
        'error' => $message,
    ], $extra), $status);
}

/* =========================================================
   VALIDACIÓN TOKEN
========================================================= */

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');

if (!hash_equals($API_TOKEN, $token)) {
    failResponse('Token inválido.', 401);
}

/* =========================================================
   VALIDACIÓN FECHAS
========================================================= */

$fechaInicio = trim((string)($_GET['fecha_inicio'] ?? $_POST['fecha_inicio'] ?? ''));
$fechaFin    = trim((string)($_GET['fecha_fin'] ?? $_POST['fecha_fin'] ?? ''));

if ($fechaInicio === '' || $fechaFin === '') {
    failResponse('Debes enviar fecha_inicio y fecha_fin en formato YYYY-MM-DD.');
}

$dtInicio = DateTime::createFromFormat('Y-m-d', $fechaInicio);
$dtFin    = DateTime::createFromFormat('Y-m-d', $fechaFin);

if (!$dtInicio || !$dtFin || $dtInicio->format('Y-m-d') !== $fechaInicio || $dtFin->format('Y-m-d') !== $fechaFin) {
    failResponse('Formato de fechas inválido. Usa YYYY-MM-DD.');
}

if ($fechaInicio > $fechaFin) {
    failResponse('fecha_inicio no puede ser mayor que fecha_fin.');
}

/*
   Rango completo del día en hora México.
   Como fecha_venta es TIMESTAMP, usamos límites inclusivo/exclusivo.
*/
$inicioSql = $fechaInicio . ' 00:00:00';

$dtFinExclusive = clone $dtFin;
$dtFinExclusive->modify('+1 day');
$finSql = $dtFinExclusive->format('Y-m-d') . ' 00:00:00';

/* =========================================================
   HELPERS BD
========================================================= */

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $table);
    $stmt->execute();

    return (bool)$stmt->get_result()->fetch_assoc();
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    return (bool)$stmt->get_result()->fetch_assoc();
}

/* =========================================================
   VALIDAR TABLAS BASE
========================================================= */

foreach (['ventas', 'detalle_venta', 'productos', 'sucursales'] as $tabla) {
    if (!tableExists($conn, $tabla)) {
        failResponse("No existe la tabla requerida: {$tabla}.", 500);
    }
}

/* =========================================================
   DETECCIÓN DE COLUMNAS OPCIONALES
========================================================= */

$hasVentasPropietario = columnExists($conn, 'ventas', 'propietario');
$hasVentasIdSubdis    = columnExists($conn, 'ventas', 'id_subdis');
$hasSucSubtipo        = columnExists($conn, 'sucursales', 'subtipo');
$hasSucPropiedad      = columnExists($conn, 'sucursales', 'propiedad');
$hasSucActivo         = columnExists($conn, 'sucursales', 'activo');
$hasProdTipoProducto  = columnExists($conn, 'productos', 'tipo_producto');
$hasProdModelo        = columnExists($conn, 'productos', 'modelo');
$hasProdMarca         = columnExists($conn, 'productos', 'marca');
$hasProdNombreCom     = columnExists($conn, 'productos', 'nombre_comercial');
$hasProdDescripcion   = columnExists($conn, 'productos', 'descripcion');

/* =========================================================
   FILTROS DINÁMICOS
========================================================= */

$where = [];
$where[] = "v.fecha_venta >= ?";
$where[] = "v.fecha_venta < ?";

/*
   Solo tiendas propias.
   En LUGA y NANO existe sucursales.subtipo.
   Nano no trae Subdis, pero igual se filtra por Propia.
*/
if ($hasSucSubtipo) {
    $where[] = "LOWER(COALESCE(s.subtipo, '')) = 'propia'";
}

/*
   Refuerzo para LUGA, donde sí hay Subdis.
*/
if ($hasSucPropiedad) {
    $where[] = "LOWER(COALESCE(s.propiedad, 'luga')) = 'luga'";
}

if ($hasSucActivo) {
    $where[] = "COALESCE(s.activo, 1) = 1";
}

if ($hasVentasPropietario) {
    $where[] = "LOWER(COALESCE(v.propietario, 'luga')) = 'luga'";
}

if ($hasVentasIdSubdis) {
    $where[] = "(v.id_subdis IS NULL OR v.id_subdis = 0)";
}

/*
   No contar combos.
*/
$where[] = "COALESCE(dv.es_combo, 0) = 0";

/*
   No contar Modem/MiFi.
   Usamos varias columnas por compatibilidad entre LUGA/NANO.
*/
$tipoChecks = [];

if ($hasProdTipoProducto) {
    $tipoChecks[] = "LOWER(COALESCE(p.tipo_producto, '')) NOT REGEXP 'modem|mifi|mi-fi'";
}

if ($hasProdModelo) {
    $tipoChecks[] = "LOWER(COALESCE(p.modelo, '')) NOT REGEXP 'modem|mifi|mi-fi'";
}

if ($hasProdMarca) {
    $tipoChecks[] = "LOWER(COALESCE(p.marca, '')) NOT REGEXP 'modem|mifi|mi-fi'";
}

if ($hasProdNombreCom) {
    $tipoChecks[] = "LOWER(COALESCE(p.nombre_comercial, '')) NOT REGEXP 'modem|mifi|mi-fi'";
}

if ($hasProdDescripcion) {
    $tipoChecks[] = "LOWER(COALESCE(p.descripcion, '')) NOT REGEXP 'modem|mifi|mi-fi'";
}

if (!empty($tipoChecks)) {
    $where[] = '(' . implode(' AND ', $tipoChecks) . ')';
}

$whereSql = implode("\n          AND ", $where);

/*
   Monto:
   - Para no inflar ventas combo, sumamos dv.precio_unitario de los productos válidos.
   - Si precio_unitario viniera 0, usamos v.precio_venta como fallback prorrateado no disponible.
   - Para la práctica actual, dv.precio_unitario debe traer el precio del equipo válido.
*/
$sql = "
    SELECT
        s.id AS id_sucursal_origen,
        s.nombre AS nombre_sucursal,
        ? AS empresa,
        CONCAT(?, '_', s.id) AS clave_global,
        COUNT(dv.id) AS goles,
        SUM(
            CASE
                WHEN COALESCE(dv.precio_unitario, 0) > 0 THEN dv.precio_unitario
                ELSE 0
            END
        ) AS monto
    FROM ventas v
    INNER JOIN sucursales s
        ON s.id = v.id_sucursal
    INNER JOIN detalle_venta dv
        ON dv.id_venta = v.id
    INNER JOIN productos p
        ON p.id = dv.id_producto
    WHERE {$whereSql}
    GROUP BY
        s.id,
        s.nombre
    ORDER BY
        s.nombre ASC
";

try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssss', $EMPRESA_API, $EMPRESA_API, $inicioSql, $finSql);
    $stmt->execute();

    $res = $stmt->get_result();

    $rows = [];
    $totalGoles = 0;
    $totalMonto = 0.00;

    while ($r = $res->fetch_assoc()) {
        $goles = (int)($r['goles'] ?? 0);
        $monto = (float)($r['monto'] ?? 0);

        $rows[] = [
            'empresa' => $EMPRESA_API,
            'id_sucursal_origen' => (int)$r['id_sucursal_origen'],
            'nombre_sucursal' => (string)$r['nombre_sucursal'],
            'clave_global' => (string)$r['clave_global'],
            'goles' => $goles,
            'monto' => round($monto, 2),
        ];

        $totalGoles += $goles;
        $totalMonto += $monto;
    }

    jsonResponse([
        'ok' => true,
        'empresa' => $EMPRESA_API,
        'fecha_inicio' => $fechaInicio,
        'fecha_fin' => $fechaFin,
        'generado_en' => date('Y-m-d H:i:s'),
        'total_goles' => $totalGoles,
        'total_monto' => round($totalMonto, 2),
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    failResponse('Error al generar información mundialista.', 500, [
        'detalle' => $e->getMessage(),
    ]);
}
