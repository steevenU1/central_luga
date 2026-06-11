<?php
/**
 * mundial_generar_snapshots_auto.php
 * Genera snapshots automáticos de ventas para Copa Mundial Zentral
 *
 * Ubicación:
 *   /public_html/mundial_generar_snapshots_auto.php
 *
 * Este archivo vive junto a:
 *   db_fifa.php
 *   mundial_dashboard.php
 *   mundial_grupos.php
 *   mundial_bracket.php
 *
 * Flujo:
 *   1. Recibe id_torneo, semana, fecha_inicio, fecha_fin.
 *   2. Consulta APIs de LUGA y NANO.
 *   3. Guarda/actualiza mundial_snapshots_ventas.
 *   4. Redirige al dashboard de la semana.
 */

session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_fifa.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

/* =========================================================
   ACCESO
========================================================= */

$rol = $_SESSION['rol'] ?? '';
$rolesPermitidos = ['Admin', 'Administrador', 'Logistica'];

if (!in_array($rol, $rolesPermitidos, true)) {
    die("Acceso no autorizado.");
}

/* =========================================================
   CONFIGURACIÓN DE APIS
========================================================= */

/*
   Debe ser el mismo token configurado en:
   /api/api_mundial_ventas.php de LUGA y NANO
*/
$API_TOKEN = 'MundialZentral2026_LugaNano';

$FUENTES = [
    [
        'empresa' => 'LUGA',
        'url' => 'https://lugaph.site/api/api_mundial_ventas.php',
    ],
    [
        'empresa' => 'NANO',
        'url' => 'https://nanored.site/api/api_mundial_ventas.php',
    ],
];

/* =========================================================
   INPUTS
========================================================= */

$id_torneo = (int)($_GET['id_torneo'] ?? $_POST['id_torneo'] ?? 0);
$semana    = (int)($_GET['semana'] ?? $_POST['semana'] ?? 1);

if ($semana < 1 || $semana > 7) {
    $semana = 1;
}

if ($id_torneo <= 0) {
    $resActivo = $connFifa->query("
        SELECT id
        FROM mundial_torneos
        WHERE activo = 1
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($row = $resActivo->fetch_assoc()) {
        $id_torneo = (int)$row['id'];
    }
}

if ($id_torneo <= 0) {
    redirigirError(0, 1, "No hay torneo activo para generar snapshots.");
}

/* =========================================================
   OBTENER TORNEO
========================================================= */

$stmtTorneo = $connFifa->prepare("
    SELECT *
    FROM mundial_torneos
    WHERE id = ?
    LIMIT 1
");
$stmtTorneo->bind_param("i", $id_torneo);
$stmtTorneo->execute();
$torneo = $stmtTorneo->get_result()->fetch_assoc();

if (!$torneo) {
    redirigirError($id_torneo, $semana, "El torneo no existe.");
}

if (($torneo['fase_actual'] ?? '') === 'FINALIZADO') {
    redirigirError($id_torneo, $semana, "El torneo ya está finalizado. No se pueden generar snapshots.");
}

/* =========================================================
   FECHAS
========================================================= */

$fecha_inicio = trim((string)($_GET['fecha_inicio'] ?? $_POST['fecha_inicio'] ?? ''));
$fecha_fin    = trim((string)($_GET['fecha_fin'] ?? $_POST['fecha_fin'] ?? ''));

if ($fecha_inicio === '' || $fecha_fin === '') {
    [$fecha_inicio, $fecha_fin] = obtenerFechasSugeridasSemana($torneo, $semana);
}

if (!validarFecha($fecha_inicio) || !validarFecha($fecha_fin)) {
    redirigirError($id_torneo, $semana, "Formato de fechas inválido. Usa YYYY-MM-DD.");
}

if ($fecha_inicio > $fecha_fin) {
    redirigirError($id_torneo, $semana, "La fecha inicio no puede ser mayor que la fecha fin.");
}

/* =========================================================
   PROCESAR
========================================================= */

try {
    $connFifa->begin_transaction();

    /*
       Insert/update por clave_global y semana.
       Requiere UNIQUE KEY ideal:
       UNIQUE(id_torneo, semana, clave_global)
    */
    $stmtUpsert = $connFifa->prepare("
        INSERT INTO mundial_snapshots_ventas
        (
            id_torneo,
            semana,
            empresa,
            id_sucursal_origen,
            clave_global,
            goles,
            monto,
            fecha_inicio,
            fecha_fin
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            empresa = VALUES(empresa),
            id_sucursal_origen = VALUES(id_sucursal_origen),
            goles = VALUES(goles),
            monto = VALUES(monto),
            fecha_inicio = VALUES(fecha_inicio),
            fecha_fin = VALUES(fecha_fin)
    ");

    $totalFuentes = 0;
    $totalFilas = 0;
    $totalGoles = 0;
    $totalMonto = 0.00;
    $erroresFuentes = [];

    foreach ($FUENTES as $fuente) {
        $empresaEsperada = (string)$fuente['empresa'];
        $url = construirUrlApi(
            (string)$fuente['url'],
            $API_TOKEN,
            $fecha_inicio,
            $fecha_fin
        );

        $respuesta = consultarApiMundial($url);

        if (!$respuesta['ok']) {
            $erroresFuentes[] = "{$empresaEsperada}: " . $respuesta['error'];
            continue;
        }

        $data = $respuesta['data'];

        if (!($data['ok'] ?? false)) {
            $erroresFuentes[] = "{$empresaEsperada}: respuesta API no válida.";
            continue;
        }

        $empresaApi = strtoupper(trim((string)($data['empresa'] ?? '')));

        if ($empresaApi !== $empresaEsperada) {
            $erroresFuentes[] = "{$empresaEsperada}: la API respondió empresa '{$empresaApi}'.";
            continue;
        }

        $rows = $data['rows'] ?? [];

        if (!is_array($rows)) {
            $erroresFuentes[] = "{$empresaEsperada}: rows no es un arreglo.";
            continue;
        }

        $totalFuentes++;

        foreach ($rows as $row) {
            $empresa = strtoupper(trim((string)($row['empresa'] ?? $empresaEsperada)));
            $idSucursalOrigen = (int)($row['id_sucursal_origen'] ?? 0);
            $claveGlobal = trim((string)($row['clave_global'] ?? ''));
            $goles = max(0, (int)($row['goles'] ?? 0));
            $monto = max(0, (float)($row['monto'] ?? 0));

            if ($empresa !== $empresaEsperada) {
                continue;
            }

            if ($idSucursalOrigen <= 0 || $claveGlobal === '') {
                continue;
            }

            /*
               Seguridad extra:
               Solo guardar snapshots de tiendas registradas en este torneo.
               Evita que entren sucursales que no participan.
            */
            if (!tiendaParticipa($connFifa, $id_torneo, $claveGlobal)) {
                continue;
            }

            $stmtUpsert->bind_param(
                "iisisiiss",
                $id_torneo,
                $semana,
                $empresa,
                $idSucursalOrigen,
                $claveGlobal,
                $goles,
                $monto,
                $fecha_inicio,
                $fecha_fin
            );
            $stmtUpsert->execute();

            $totalFilas++;
            $totalGoles += $goles;
            $totalMonto += $monto;
        }
    }

    if ($totalFuentes === 0) {
        throw new Exception(
            "No se pudo leer ninguna fuente. " .
            implode(" | ", $erroresFuentes)
        );
    }

    $connFifa->commit();

    $mensaje = "Snapshots automáticos generados. Fuentes OK: {$totalFuentes}. Tiendas actualizadas: {$totalFilas}. Goles: {$totalGoles}. Monto: $" . number_format($totalMonto, 2);

    if (!empty($erroresFuentes)) {
        $mensaje .= " Advertencias: " . implode(" | ", $erroresFuentes);
    }

    redirigirOk($id_torneo, $semana, $mensaje);

} catch (Throwable $e) {
    if (isset($connFifa) && $connFifa instanceof mysqli) {
        $connFifa->rollback();
    }

    redirigirError($id_torneo, $semana, $e->getMessage());
}

/* =========================================================
   FUNCIONES
========================================================= */

function construirUrlApi(string $baseUrl, string $token, string $fecha_inicio, string $fecha_fin): string
{
    $query = http_build_query([
        'token' => $token,
        'fecha_inicio' => $fecha_inicio,
        'fecha_fin' => $fecha_fin,
    ]);

    return $baseUrl . '?' . $query;
}

function consultarApiMundial(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 25,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);

    if ($raw === false || trim($raw) === '') {
        return [
            'ok' => false,
            'error' => 'No se pudo conectar o la respuesta está vacía.',
        ];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return [
            'ok' => false,
            'error' => 'La respuesta no es JSON válido.',
        ];
    }

    return [
        'ok' => true,
        'data' => $data,
    ];
}

function tiendaParticipa(mysqli $connFifa, int $id_torneo, string $claveGlobal): bool
{
    static $cache = [];

    $key = $id_torneo . '|' . $claveGlobal;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $connFifa->prepare("
        SELECT 1
        FROM mundial_tiendas
        WHERE id_torneo = ?
          AND clave_global = ?
          AND activa = 1
        LIMIT 1
    ");
    $stmt->bind_param("is", $id_torneo, $claveGlobal);
    $stmt->execute();

    $exists = (bool)$stmt->get_result()->fetch_assoc();

    $cache[$key] = $exists;

    return $exists;
}

function obtenerFechasSugeridasSemana(array $torneo, int $semana): array
{
    /*
       Semana operativa martes-lunes.
       Se calcula desde fecha_inicio del torneo.
       Si fecha_inicio no está disponible, usa hoy.
    */
    $base = trim((string)($torneo['fecha_inicio'] ?? ''));

    if ($base === '' || !validarFecha($base)) {
        $base = date('Y-m-d');
    }

    $inicio = new DateTime($base);
    $inicio->modify('+' . (($semana - 1) * 7) . ' days');

    $fin = clone $inicio;
    $fin->modify('+6 days');

    return [
        $inicio->format('Y-m-d'),
        $fin->format('Y-m-d'),
    ];
}

function validarFecha(string $fecha): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    return $dt && $dt->format('Y-m-d') === $fecha;
}

function redirigirOk(int $id_torneo, int $semana, string $mensaje): void
{
    $url = "mundial_dashboard.php?id_torneo={$id_torneo}&semana={$semana}&msg=" . urlencode($mensaje);
    header("Location: {$url}");
    exit();
}

function redirigirError(int $id_torneo, int $semana, string $error): void
{
    $url = "mundial_dashboard.php?id_torneo={$id_torneo}&semana={$semana}&error=" . urlencode($error);
    header("Location: {$url}");
    exit();
}
