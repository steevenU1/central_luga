<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* =========================================================
   PERMISOS
========================================================= */
$ROL = (string)($_SESSION['rol'] ?? '');
$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);
$NOMBRE_USUARIO = (string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario');

$ROLES_PERMITIDOS = [
    'Admin', 'Administrador', 'Logistica',
    'Gerente', 'Ejecutivo',
    'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para capturar reemplazos.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function sanitize_imei($v): string {
    $v = preg_replace('/\s+/', '', (string)$v);
    $v = preg_replace('/[^0-9A-Za-z]/', '', $v);
    return strtoupper(trim($v));
}

function null_if_empty($v): ?string {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function int_or_null($v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function decimal_or_null($v): ?float {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (float)$v;
    $v = str_replace(['$', ',', ' '], '', (string)$v);
    return is_numeric($v) ? (float)$v : null;
}

function money_fmt($v): string {
    $n = decimal_or_null($v);
    return $n === null ? 'No disponible' : '$' . number_format($n, 2);
}

function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param("s", $table);
    $st->execute();
    $ok = ($st->get_result()->num_rows > 0);
    $st->close();
    return $ok;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param("ss", $table, $column);
    $st->execute();
    $ok = ($st->get_result()->num_rows > 0);
    $st->close();
    return $ok;
}

function first_existing_column(mysqli $conn, string $table, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (column_exists($conn, $table, $c)) return $c;
    }
    return null;
}

function bindParamsDynamic(mysqli_stmt $stmt, string $types, array &$params): void {
    $refs = [];
    $refs[] = $types;
    foreach ($params as $k => $v) {
        $refs[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function normalize_status(?string $status): string {
    $s = trim((string)$status);
    $s = mb_strtolower($s, 'UTF-8');
    $map = [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
    ];
    $s = strtr($s, $map);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

function registrar_evento(
    mysqli $conn,
    int $idGarantia,
    string $tipoEvento,
    ?string $estadoAnterior,
    ?string $estadoNuevo,
    ?string $descripcion,
    ?array $datosJson,
    ?int $idUsuario,
    ?string $nombreUsuario,
    ?string $rolUsuario
): void {
    $datos = $datosJson ? json_encode($datosJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $sql = "INSERT INTO garantias_eventos
            (id_garantia, tipo_evento, estado_anterior, estado_nuevo, descripcion, datos_json, id_usuario, nombre_usuario, rol_usuario, fecha_evento)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $st = $conn->prepare($sql);
    if (!$st) {
        throw new Exception("Error en prepare() de garantias_eventos: " . $conn->error);
    }

    $st->bind_param(
        "isssssiss",
        $idGarantia,
        $tipoEvento,
        $estadoAnterior,
        $estadoNuevo,
        $descripcion,
        $datos,
        $idUsuario,
        $nombreUsuario,
        $rolUsuario
    );

    if (!$st->execute()) {
        throw new Exception("Error al insertar evento: " . $st->error);
    }
    $st->close();
}

function puede_operar_caso(array $caso, string $rol, int $idUsuario, int $idSucursal): bool {
    if (in_array($rol, ['Admin', 'Administrador', 'Logistica'], true)) {
        return true;
    }
    if (in_array($rol, ['Gerente', 'Subdis_Gerente'], true)) {
        return ((int)($caso['id_sucursal'] ?? 0) === $idSucursal);
    }
    if (in_array($rol, ['Ejecutivo', 'Subdis_Ejecutivo'], true)) {
        return ((int)($caso['id_usuario_captura'] ?? 0) === $idUsuario);
    }
    if ($rol === 'Subdis_Admin') {
        return true;
    }
    return false;
}

function actualizar_excepcion_como_aplicada_si_existe(
    mysqli $conn,
    ?array $excepcionActual,
    int $idGarantia,
    int $idUsuario,
    string $nombreUsuario,
    string $rolUsuario,
    string $imeiAplicado,
    ?string $observacionesAplicacion = null
): bool {
    if (!$excepcionActual || empty($excepcionActual['id'])) {
        return false;
    }
    if (!table_exists($conn, 'garantias_excepciones_reemplazo')) {
        return false;
    }

    $tabla = 'garantias_excepciones_reemplazo';
    $idExc = (int)$excepcionActual['id'];

    $sets = [];
    $params = [];
    $types = '';

    if (column_exists($conn, $tabla, 'estatus')) {
        $sets[] = "estatus = ?";
        $params[] = 'aplicada';
        $types .= 's';
    }

    foreach (['fecha_aplicacion', 'aplicado_at', 'updated_at'] as $colFecha) {
        if (column_exists($conn, $tabla, $colFecha)) {
            $sets[] = "{$colFecha} = NOW()";
            break;
        }
    }

    foreach (['id_usuario_aplicacion', 'id_usuario_aplico', 'aplicado_por'] as $colUsrId) {
        if (column_exists($conn, $tabla, $colUsrId)) {
            $sets[] = "{$colUsrId} = ?";
            $params[] = $idUsuario;
            $types .= 'i';
            break;
        }
    }

    foreach (['nombre_usuario_aplicacion', 'nombre_usuario_aplico'] as $colUsrNom) {
        if (column_exists($conn, $tabla, $colUsrNom)) {
            $sets[] = "{$colUsrNom} = ?";
            $params[] = $nombreUsuario;
            $types .= 's';
            break;
        }
    }

    foreach (['rol_usuario_aplicacion', 'rol_usuario_aplico'] as $colRol) {
        if (column_exists($conn, $tabla, $colRol)) {
            $sets[] = "{$colRol} = ?";
            $params[] = $rolUsuario;
            $types .= 's';
            break;
        }
    }

    foreach (['imei_aplicado', 'imei_reemplazo_aplicado'] as $colImei) {
        if (column_exists($conn, $tabla, $colImei)) {
            $sets[] = "{$colImei} = ?";
            $params[] = $imeiAplicado;
            $types .= 's';
            break;
        }
    }

    foreach (['observaciones_aplicacion', 'comentarios_aplicacion'] as $colObs) {
        if (column_exists($conn, $tabla, $colObs)) {
            $sets[] = "{$colObs} = ?";
            $params[] = $observacionesAplicacion;
            $types .= 's';
            break;
        }
    }

    if (!$sets) {
        return false;
    }

    $sql = "UPDATE {$tabla} SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1";
    $params[] = $idExc;
    $types .= 'i';

    $st = $conn->prepare($sql);
    if (!$st) {
        throw new Exception("Error al preparar actualización de excepción aplicada: " . $conn->error);
    }
    bindParamsDynamic($st, $types, $params);
    if (!$st->execute()) {
        throw new Exception("Error al marcar excepción como aplicada: " . $st->error);
    }
    $st->close();

    return true;
}

/* =========================================================
   VALIDAR TABLAS
========================================================= */
$required = ['garantias_casos', 'garantias_reemplazos', 'garantias_eventos', 'inventario', 'productos', 'detalle_venta'];
foreach ($required as $tb) {
    if (!table_exists($conn, $tb)) {
        exit("No existe la tabla requerida: " . h($tb));
    }
}

/* =========================================================
   MAPEO DINÁMICO inventario / productos
========================================================= */
$invCols = [
    'id'          => first_existing_column($conn, 'inventario', ['id']),
    'id_producto' => first_existing_column($conn, 'inventario', ['id_producto']),
    'id_sucursal' => first_existing_column($conn, 'inventario', ['id_sucursal']),
    'estatus'     => first_existing_column($conn, 'inventario', ['estatus', 'estado']),
];

$prodCols = [
    'id'           => first_existing_column($conn, 'productos', ['id']),
    'marca'        => first_existing_column($conn, 'productos', ['marca']),
    'modelo'       => first_existing_column($conn, 'productos', ['modelo']),
    'color'        => first_existing_column($conn, 'productos', ['color']),
    'capacidad'    => first_existing_column($conn, 'productos', ['capacidad', 'almacenamiento']),
    'imei1'        => first_existing_column($conn, 'productos', ['imei1']),
    'imei2'        => first_existing_column($conn, 'productos', ['imei2']),
    'precio_lista' => first_existing_column($conn, 'productos', ['precio_lista']),
];

if (!$invCols['id'] || !$invCols['id_producto'] || !$invCols['id_sucursal'] || !$invCols['estatus']) {
    exit('La tabla inventario no tiene las columnas mínimas requeridas.');
}
if (!$prodCols['id'] || !$prodCols['imei1']) {
    exit('La tabla productos no tiene las columnas mínimas requeridas.');
}
if (!$prodCols['precio_lista']) {
    exit('La tabla productos debe tener la columna precio_lista para validar reemplazos.');
}

/* =========================================================
   CARGAR CASO
========================================================= */
$idGarantia = (int)($_GET['id'] ?? $_POST['id_garantia'] ?? 0);
if ($idGarantia <= 0) {
    exit('ID de garantía inválido.');
}

$sqlCaso = "SELECT
                gc.*,
                s.nombre AS sucursal_nombre,
                dv.precio_unitario AS precio_original_venta
            FROM garantias_casos gc
            LEFT JOIN sucursales s
                ON s.id = gc.id_sucursal
            LEFT JOIN detalle_venta dv
                ON dv.imei1 = gc.imei_original
            WHERE gc.id = ?
            LIMIT 1";

$st = $conn->prepare($sqlCaso);
if (!$st) {
    exit("Error consultando caso: " . h($conn->error));
}
$st->bind_param("i", $idGarantia);
$st->execute();
$caso = $st->get_result()->fetch_assoc();
$st->close();

if (!$caso) {
    exit('No se encontró el caso.');
}

$precioOriginal = decimal_or_null($caso['precio_original_venta'] ?? null);
$caso['precio_original_resuelto'] = $precioOriginal;

if (!puede_operar_caso($caso, $ROL, $ID_USUARIO, $ID_SUCURSAL)) {
    http_response_code(403);
    exit('No tienes permiso para operar este caso.');
}

/* =========================================================
   VALIDAR ESTADO DEL CASO
========================================================= */
$estadosPermitidos = ['garantia_autorizada', 'reemplazo_capturado'];
if (!in_array((string)$caso['estado'], $estadosPermitidos, true)) {
    exit('Este caso no está listo para captura de reemplazo. Estado actual: ' . h($caso['estado']));
}

/* =========================================================
   REEMPLAZO ACTUAL
========================================================= */
$reemplazoActual = null;
$sqlR = "SELECT *
         FROM garantias_reemplazos
         WHERE id_garantia = ?
         ORDER BY id DESC
         LIMIT 1";
$st = $conn->prepare($sqlR);
if ($st) {
    $st->bind_param("i", $idGarantia);
    $st->execute();
    $reemplazoActual = $st->get_result()->fetch_assoc();
    $st->close();
}

/* =========================================================
   EXCEPCIÓN ACTUAL
========================================================= */
$excepcionActual = null;
$puedeSolicitarExcepcion = in_array($ROL, [
    'Ejecutivo', 'Gerente',
    'Subdis_Ejecutivo', 'Subdis_Gerente',
    'Admin', 'Administrador'
], true);

$excCols = [
    'id'               => null,
    'estatus'          => null,
    'imei_propuesto'   => null,
    'imei_autorizado'  => null,
    'motivo_solicitud' => null,
    'motivo_respuesta' => null,
    'comentarios'      => null,
];

if (table_exists($conn, 'garantias_excepciones_reemplazo')) {
    $excCols['id']               = first_existing_column($conn, 'garantias_excepciones_reemplazo', ['id']);
    $excCols['estatus']          = first_existing_column($conn, 'garantias_excepciones_reemplazo', ['estatus']);
    $excCols['imei_propuesto']   = first_existing_column($conn, 'garantias_excepciones_reemplazo', ['imei_propuesto', 'imei_solicitado']);
    $excCols['imei_autorizado']  = first_existing_column($conn, 'garantias_excepciones_reemplazo', ['imei_autorizado', 'imei_aprobado']);
    $excCols['motivo_solicitud'] = first_existing_column($conn, 'garantias_excepciones_reemplazo', ['motivo_solicitud', 'motivo']);
    $excCols['motivo_respuesta'] = first_existing_column($conn, 'garantias_excepciones_reemplazo', ['motivo_respuesta', 'respuesta', 'comentarios_respuesta']);
    $excCols['comentarios']      = first_existing_column($conn, 'garantias_excepciones_reemplazo', ['comentarios', 'observaciones']);

    $sqlExc = "SELECT *
               FROM garantias_excepciones_reemplazo
               WHERE id_garantia = ?
               ORDER BY id DESC
               LIMIT 1";
    $st = $conn->prepare($sqlExc);
    if ($st) {
        $st->bind_param("i", $idGarantia);
        $st->execute();
        $excepcionActual = $st->get_result()->fetch_assoc();
        $st->close();
    }
}

$estatusExcepcion = trim((string)($excepcionActual['estatus'] ?? ''));
$hayExcepcionPendiente  = $excepcionActual && ($estatusExcepcion === 'solicitada');
$hayExcepcionAutorizada = $excepcionActual && ($estatusExcepcion === 'autorizada');
$hayExcepcionRechazada  = $excepcionActual && ($estatusExcepcion === 'rechazada');

$imeiExcepcionAutorizada = '';
if ($hayExcepcionAutorizada) {
    $imeiExcepcionAutorizada = sanitize_imei(
        $excepcionActual[$excCols['imei_autorizado'] ?? ''] ??
        $excepcionActual[$excCols['imei_propuesto'] ?? ''] ??
        ''
    );
}

/* =========================================================
   BUSCAR EQUIPO REEMPLAZO
========================================================= */
$imeiBusqueda = sanitize_imei($_GET['imei_buscar'] ?? $_POST['imei_buscar'] ?? '');
$equipoNuevo = null;
$error = null;
$ok = isset($_GET['ok']) ? (int)$_GET['ok'] : 0;
$okexc = isset($_GET['okexc']) ? (int)$_GET['okexc'] : 0;

if ($imeiBusqueda !== '') {
    $whereImei = [];
    $paramsImei = [];
    $typesImei = '';

    $whereImei[] = "p.`{$prodCols['imei1']}` = ?";
    $paramsImei[] = $imeiBusqueda;
    $typesImei .= 's';

    if ($prodCols['imei2']) {
        $whereImei[] = "p.`{$prodCols['imei2']}` = ?";
        $paramsImei[] = $imeiBusqueda;
        $typesImei .= 's';
    }

    $sqlEq = "SELECT
                i.`{$invCols['id']}` AS inventario_id,
                i.`{$invCols['id_producto']}` AS id_producto,
                i.`{$invCols['id_sucursal']}` AS id_sucursal,
                i.`{$invCols['estatus']}` AS estatus_inventario,
                p.`{$prodCols['id']}` AS producto_id,
                " . ($prodCols['marca'] ? "p.`{$prodCols['marca']}`" : "NULL") . " AS marca,
                " . ($prodCols['modelo'] ? "p.`{$prodCols['modelo']}`" : "NULL") . " AS modelo,
                " . ($prodCols['color'] ? "p.`{$prodCols['color']}`" : "NULL") . " AS color,
                " . ($prodCols['capacidad'] ? "p.`{$prodCols['capacidad']}`" : "NULL") . " AS capacidad,
                p.`{$prodCols['imei1']}` AS imei1,
                " . ($prodCols['imei2'] ? "p.`{$prodCols['imei2']}`" : "NULL") . " AS imei2,
                p.`{$prodCols['precio_lista']}` AS precio_reemplazo,
                s.nombre AS sucursal_nombre
              FROM inventario i
              INNER JOIN productos p
                  ON p.`{$prodCols['id']}` = i.`{$invCols['id_producto']}`
              LEFT JOIN sucursales s
                  ON s.id = i.`{$invCols['id_sucursal']}`
              WHERE (" . implode(" OR ", $whereImei) . ")
              LIMIT 1";

    $st = $conn->prepare($sqlEq);
    if (!$st) {
        $error = "Error en búsqueda del equipo: " . $conn->error;
    } else {
        bindParamsDynamic($st, $typesImei, $paramsImei);
        $st->execute();
        $equipoNuevo = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$equipoNuevo) {
            $error = "No se encontró el equipo de reemplazo con ese IMEI.";
        }
    }
}

/* =========================================================
   DATOS DE VISTA / FLAGS
========================================================= */
$precioOriginalVista  = decimal_or_null($caso['precio_original_resuelto'] ?? null);
$precioReemplazoVista = decimal_or_null($equipoNuevo['precio_reemplazo'] ?? null);

$cumplePrecio = false;
$requiereExcepcion = false;
$puedeAplicarPorExcepcion = false;
$mensajeAutorizacionExcepcion = null;

if ($equipoNuevo && $precioOriginalVista !== null && $precioReemplazoVista !== null) {
    $cumplePrecio = ($precioReemplazoVista <= $precioOriginalVista);
    $requiereExcepcion = !$cumplePrecio;

    if ($requiereExcepcion && $hayExcepcionAutorizada) {
        $imeiEquipoEncontrado1 = sanitize_imei($equipoNuevo['imei1'] ?? '');
        $imeiEquipoEncontrado2 = sanitize_imei($equipoNuevo['imei2'] ?? '');

        if ($imeiExcepcionAutorizada !== '') {
            if ($imeiExcepcionAutorizada === $imeiEquipoEncontrado1 || $imeiExcepcionAutorizada === $imeiEquipoEncontrado2) {
                $puedeAplicarPorExcepcion = true;
                $mensajeAutorizacionExcepcion = 'Este equipo supera el valor permitido, pero coincide con la excepción autorizada y ya puede aplicarse desde esta pantalla.';
            } else {
                $puedeAplicarPorExcepcion = false;
                $mensajeAutorizacionExcepcion = 'Existe una excepción autorizada, pero fue aprobada para otro IMEI. Solo puedes aplicar aquí el equipo autorizado.';
            }
        } else {
            $puedeAplicarPorExcepcion = true;
            $mensajeAutorizacionExcepcion = 'Existe una excepción autorizada para este caso. Se permite aplicar este reemplazo desde esta pantalla.';
        }
    }
}

/* =========================================================
   GUARDAR REEMPLAZO
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_reemplazo'])) {
    $imeiBusquedaPost = sanitize_imei($_POST['imei_buscar'] ?? '');
    $observaciones = null_if_empty($_POST['observaciones'] ?? null);

    if ($imeiBusquedaPost === '') {
        $error = 'Debes capturar el IMEI del equipo de reemplazo.';
    } else {
        $whereImei = [];
        $paramsImei = [];
        $typesImei = '';

        $whereImei[] = "p.`{$prodCols['imei1']}` = ?";
        $paramsImei[] = $imeiBusquedaPost;
        $typesImei .= 's';

        if ($prodCols['imei2']) {
            $whereImei[] = "p.`{$prodCols['imei2']}` = ?";
            $paramsImei[] = $imeiBusquedaPost;
            $typesImei .= 's';
        }

        $sqlEq = "SELECT
                    i.`{$invCols['id']}` AS inventario_id,
                    i.`{$invCols['id_producto']}` AS id_producto,
                    i.`{$invCols['id_sucursal']}` AS id_sucursal,
                    i.`{$invCols['estatus']}` AS estatus_inventario,
                    p.`{$prodCols['id']}` AS producto_id,
                    " . ($prodCols['marca'] ? "p.`{$prodCols['marca']}`" : "NULL") . " AS marca,
                    " . ($prodCols['modelo'] ? "p.`{$prodCols['modelo']}`" : "NULL") . " AS modelo,
                    " . ($prodCols['color'] ? "p.`{$prodCols['color']}`" : "NULL") . " AS color,
                    " . ($prodCols['capacidad'] ? "p.`{$prodCols['capacidad']}`" : "NULL") . " AS capacidad,
                    p.`{$prodCols['imei1']}` AS imei1,
                    " . ($prodCols['imei2'] ? "p.`{$prodCols['imei2']}`" : "NULL") . " AS imei2,
                    p.`{$prodCols['precio_lista']}` AS precio_reemplazo
                  FROM inventario i
                  INNER JOIN productos p
                      ON p.`{$prodCols['id']}` = i.`{$invCols['id_producto']}`
                  WHERE (" . implode(" OR ", $whereImei) . ")
                  LIMIT 1";

        $st = $conn->prepare($sqlEq);
        if (!$st) {
            $error = "Error al preparar la búsqueda del reemplazo: " . $conn->error;
        } else {
            bindParamsDynamic($st, $typesImei, $paramsImei);
            $st->execute();
            $equipoNuevo = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$equipoNuevo) {
                $error = 'No se encontró el equipo de reemplazo.';
            }
        }

        if (!$error) {
            $estatusInvRaw = (string)($equipoNuevo['estatus_inventario'] ?? '');
            $estatusInv = normalize_status($estatusInvRaw);

            $permitidos = ['disponible', 'stock', 'activo'];
            $bloqueados = ['garantia', 'vendido', 'retirado', 'en transito'];

            if (in_array($estatusInv, $bloqueados, true)) {
                if ($estatusInv === 'garantia') {
                    $error = 'Ese equipo ya está asignado a una garantía.';
                } else {
                    $error = 'El equipo seleccionado no está disponible en inventario. Estatus actual: ' . ($equipoNuevo['estatus_inventario'] ?? '-');
                }
            } elseif (!in_array($estatusInv, $permitidos, true)) {
                $error = 'El equipo seleccionado no está disponible en inventario. Estatus actual: ' . ($equipoNuevo['estatus_inventario'] ?? '-');
            }
        }

        if (!$error) {
            if ((int)$equipoNuevo['id_sucursal'] !== (int)$caso['id_sucursal']) {
                $error = 'El equipo pertenece a otra sucursal y no puede usarse como reemplazo.';
            }
        }

        if (!$error) {
            if (
                sanitize_imei($equipoNuevo['imei1'] ?? '') === sanitize_imei($caso['imei_original'] ?? '') ||
                sanitize_imei($equipoNuevo['imei1'] ?? '') === sanitize_imei($caso['imei2_original'] ?? '') ||
                sanitize_imei($equipoNuevo['imei2'] ?? '') === sanitize_imei($caso['imei_original'] ?? '') ||
                sanitize_imei($equipoNuevo['imei2'] ?? '') === sanitize_imei($caso['imei2_original'] ?? '')
            ) {
                $error = 'No puedes asignar el mismo equipo original como reemplazo.';
            }
        }

        if (!$error) {
            $sqlUsed = "SELECT id, id_garantia
                        FROM garantias_reemplazos
                        WHERE imei_reemplazo = ? OR imei2_reemplazo = ?
                        LIMIT 1";
            $st = $conn->prepare($sqlUsed);
            if (!$st) {
                $error = "Error validando IMEI de reemplazo: " . $conn->error;
            } else {
                $imeiA = sanitize_imei($equipoNuevo['imei1'] ?? '');
                $imeiB = sanitize_imei($equipoNuevo['imei2'] ?? '');
                $st->bind_param("ss", $imeiA, $imeiB);
                $st->execute();
                $used = $st->get_result()->fetch_assoc();
                $st->close();

                if ($used && (int)$used['id_garantia'] !== $idGarantia) {
                    $error = 'Ese equipo ya fue registrado como reemplazo en otro caso.';
                }
            }
        }

        $aplicadoConExcepcion = false;

        if (!$error) {
            $precioOriginal = decimal_or_null($caso['precio_original_resuelto'] ?? null);
            $precioReemplazo = decimal_or_null($equipoNuevo['precio_reemplazo'] ?? null);

            if ($precioOriginal === null) {
                $error = 'No se pudo obtener el precio real del equipo original desde detalle_venta.';
            } elseif ($precioReemplazo === null) {
                $error = 'El equipo de reemplazo no tiene precio lista.';
            } elseif ($precioReemplazo > $precioOriginal) {
                if ($hayExcepcionAutorizada) {
                    $imeiEq1 = sanitize_imei($equipoNuevo['imei1'] ?? '');
                    $imeiEq2 = sanitize_imei($equipoNuevo['imei2'] ?? '');

                    if ($imeiExcepcionAutorizada !== '' && $imeiExcepcionAutorizada !== $imeiEq1 && $imeiExcepcionAutorizada !== $imeiEq2) {
                        $error = 'La excepción autorizada no corresponde a este equipo. Debes aplicar el IMEI autorizado.';
                    } else {
                        $aplicadoConExcepcion = true;
                    }
                } else {
                    $error = 'El equipo de reemplazo es más caro que el original. '
                        . 'Original: ' . money_fmt($precioOriginal)
                        . ' | Reemplazo: ' . money_fmt($precioReemplazo);
                }
            }
        }
    }

    if (!$error && $equipoNuevo) {
        $conn->begin_transaction();

        try {
            $estadoAnterior = (string)$caso['estado'];

            if (
                $reemplazoActual &&
                !empty($reemplazoActual['id_inventario_reemplazo']) &&
                (int)$reemplazoActual['id_inventario_reemplazo'] !== (int)$equipoNuevo['inventario_id']
            ) {
                $sqlLiberar = "UPDATE inventario
                               SET `{$invCols['estatus']}` = ?
                               WHERE `{$invCols['id']}` = ?";
                $st = $conn->prepare($sqlLiberar);
                if (!$st) {
                    throw new Exception("Error al preparar liberación de inventario anterior: " . $conn->error);
                }
                $estatusDisponible = 'Disponible';
                $idInvAnterior = (int)$reemplazoActual['id_inventario_reemplazo'];
                $st->bind_param("si", $estatusDisponible, $idInvAnterior);
                if (!$st->execute()) {
                    throw new Exception("Error al liberar inventario anterior: " . $st->error);
                }
                $st->close();

                registrar_evento(
                    $conn,
                    $idGarantia,
                    'inventario_liberado_reemplazo_anterior',
                    'garantia',
                    'disponible',
                    'Se liberó el inventario del reemplazo anterior al editar el caso.',
                    [
                        'id_inventario_anterior' => $idInvAnterior,
                        'imei_reemplazo_anterior' => $reemplazoActual['imei_reemplazo'] ?? null
                    ],
                    $ID_USUARIO,
                    $NOMBRE_USUARIO,
                    $ROL
                );
            }

            $sqlFind = "SELECT id
                        FROM garantias_reemplazos
                        WHERE id_garantia = ?
                        ORDER BY id DESC
                        LIMIT 1";
            $st = $conn->prepare($sqlFind);
            if (!$st) {
                throw new Exception("Error buscando reemplazo actual: " . $conn->error);
            }
            $st->bind_param("i", $idGarantia);
            $st->execute();
            $rowFind = $st->get_result()->fetch_assoc();
            $st->close();

            $idReemplazo = (int)($rowFind['id'] ?? 0);

            $data = [
                'id_garantia'                 => $idGarantia,
                'id_producto_original'        => int_or_null($caso['id_producto_original'] ?? null),
                'id_producto_reemplazo'       => (int)$equipoNuevo['id_producto'],
                'imei_original'               => $caso['imei_original'],
                'imei2_original'              => $caso['imei2_original'],
                'imei_reemplazo'              => $equipoNuevo['imei1'],
                'imei2_reemplazo'             => null_if_empty($equipoNuevo['imei2'] ?? null),
                'marca_reemplazo'             => null_if_empty($equipoNuevo['marca'] ?? null),
                'modelo_reemplazo'            => null_if_empty($equipoNuevo['modelo'] ?? null),
                'color_reemplazo'             => null_if_empty($equipoNuevo['color'] ?? null),
                'capacidad_reemplazo'         => null_if_empty($equipoNuevo['capacidad'] ?? null),
                'id_inventario_reemplazo'     => (int)$equipoNuevo['inventario_id'],
                'estatus_inventario_anterior' => $equipoNuevo['estatus_inventario'],
                'estatus_inventario_nuevo'    => 'Garantia',
                'id_usuario_registro'         => $ID_USUARIO,
                'observaciones'               => $observaciones,
            ];

            if ($idReemplazo > 0) {
                $sets = [];
                $params = [];
                $types = '';

                foreach ($data as $col => $val) {
                    if ($col === 'id_garantia') continue;
                    $sets[] = "{$col} = ?";
                    $params[] = $val;
                    $types .= is_int($val) ? 'i' : 's';
                }

                $sqlUpdateR = "UPDATE garantias_reemplazos
                               SET " . implode(", ", $sets) . "
                               WHERE id = ?";
                $params[] = $idReemplazo;
                $types .= 'i';

                $st = $conn->prepare($sqlUpdateR);
                if (!$st) {
                    throw new Exception("Error en update de reemplazo: " . $conn->error);
                }
                bindParamsDynamic($st, $types, $params);
                if (!$st->execute()) {
                    throw new Exception("Error al actualizar reemplazo: " . $st->error);
                }
                $st->close();
            } else {
                $cols = array_keys($data);
                $place = array_fill(0, count($cols), '?');
                $params = array_values($data);
                $types = '';

                foreach ($params as $val) {
                    $types .= is_int($val) ? 'i' : 's';
                }

                $sqlInsertR = "INSERT INTO garantias_reemplazos
                               (" . implode(',', $cols) . ", fecha_registro)
                               VALUES (" . implode(',', $place) . ", NOW())";
                $st = $conn->prepare($sqlInsertR);
                if (!$st) {
                    throw new Exception("Error en insert de reemplazo: " . $conn->error);
                }
                bindParamsDynamic($st, $types, $params);
                if (!$st->execute()) {
                    throw new Exception("Error al insertar reemplazo: " . $st->error);
                }
                $st->close();
            }

            $sqlInv = "UPDATE inventario
                       SET `{$invCols['estatus']}` = ?
                       WHERE `{$invCols['id']}` = ?";
            $st = $conn->prepare($sqlInv);
            if (!$st) {
                throw new Exception("Error en update de inventario: " . $conn->error);
            }
            $nuevoEstatusInv = 'Garantia';
            $idInv = (int)$equipoNuevo['inventario_id'];
            $st->bind_param("si", $nuevoEstatusInv, $idInv);
            if (!$st->execute()) {
                throw new Exception("Error al actualizar inventario: " . $st->error);
            }
            $st->close();

            registrar_evento(
                $conn,
                $idGarantia,
                'inventario_asignado_garantia',
                $equipoNuevo['estatus_inventario'] ?? null,
                'Garantia',
                'El equipo de reemplazo fue marcado en inventario como Garantia.',
                [
                    'inventario_id' => $idInv,
                    'imei_reemplazo' => $equipoNuevo['imei1'] ?? null,
                    'imei2_reemplazo' => $equipoNuevo['imei2'] ?? null,
                    'precio_original_venta' => $caso['precio_original_resuelto'] ?? null,
                    'precio_reemplazo_lista' => $equipoNuevo['precio_reemplazo'] ?? null,
                ],
                $ID_USUARIO,
                $NOMBRE_USUARIO,
                $ROL
            );

            $sqlCasoUp = "UPDATE garantias_casos
                          SET estado = 'reemplazo_capturado',
                              updated_at = NOW()
                          WHERE id = ?";
            $st = $conn->prepare($sqlCasoUp);
            if (!$st) {
                throw new Exception("Error actualizando caso: " . $conn->error);
            }
            $st->bind_param("i", $idGarantia);
            if (!$st->execute()) {
                throw new Exception("Error al actualizar estado del caso: " . $st->error);
            }
            $st->close();

            if ($aplicadoConExcepcion) {
                actualizar_excepcion_como_aplicada_si_existe(
                    $conn,
                    $excepcionActual,
                    $idGarantia,
                    $ID_USUARIO,
                    $NOMBRE_USUARIO,
                    $ROL,
                    sanitize_imei($equipoNuevo['imei1'] ?? ''),
                    $observaciones
                );

                registrar_evento(
                    $conn,
                    $idGarantia,
                    'excepcion_reemplazo_aplicada',
                    $estadoAnterior,
                    'reemplazo_capturado',
                    'Se aplicó un reemplazo usando una excepción previamente autorizada.',
                    [
                        'id_excepcion' => (int)($excepcionActual['id'] ?? 0),
                        'imei_autorizado' => $imeiExcepcionAutorizada ?: null,
                        'imei_aplicado' => $equipoNuevo['imei1'] ?? null,
                        'precio_original_venta' => $caso['precio_original_resuelto'] ?? null,
                        'precio_reemplazo_lista' => $equipoNuevo['precio_reemplazo'] ?? null,
                        'observaciones' => $observaciones,
                    ],
                    $ID_USUARIO,
                    $NOMBRE_USUARIO,
                    $ROL
                );
            }

            registrar_evento(
                $conn,
                $idGarantia,
                'reemplazo_registrado',
                $estadoAnterior,
                'reemplazo_capturado',
                'Se registró el equipo de reemplazo para la garantía.',
                [
                    'imei_original' => $caso['imei_original'],
                    'imei_reemplazo' => $equipoNuevo['imei1'],
                    'imei2_reemplazo' => $equipoNuevo['imei2'] ?? null,
                    'inventario_id' => $equipoNuevo['inventario_id'],
                    'producto_id' => $equipoNuevo['id_producto'],
                    'observaciones' => $observaciones,
                    'reemplazo_editado' => $reemplazoActual ? 1 : 0,
                    'precio_original_venta' => $caso['precio_original_resuelto'] ?? null,
                    'precio_reemplazo_lista' => $equipoNuevo['precio_reemplazo'] ?? null,
                    'aplicado_con_excepcion' => $aplicadoConExcepcion ? 1 : 0,
                ],
                $ID_USUARIO,
                $NOMBRE_USUARIO,
                $ROL
            );

            registrar_evento(
                $conn,
                $idGarantia,
                'documento_garantia_pendiente',
                'reemplazo_capturado',
                'reemplazo_capturado',
                'El reemplazo fue capturado. El documento de garantía deberá generarse manualmente desde el detalle.',
                [
                    'requiere_generacion_manual' => 1
                ],
                $ID_USUARIO,
                $NOMBRE_USUARIO,
                $ROL
            );

            $conn->commit();

            $redir = "garantias_detalle.php?id={$idGarantia}&oklog=1";
            header("Location: {$redir}");
            exit();

        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

/* =========================================================
   NAVBAR DESPUÉS DEL POST
========================================================= */
require_once __DIR__ . '/navbar.php';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Capturar reemplazo | <?= h($caso['folio']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            background: linear-gradient(180deg, #f8fbff 0%, #f3f6fb 100%);
        }
        .page-wrap{
            max-width: 1200px;
            margin: 24px auto 40px;
            padding: 0 14px;
        }
        .hero{
            border-radius:22px;
            border:1px solid #e8edf3;
            background: linear-gradient(135deg, rgba(13,110,253,.08), rgba(25,135,84,.08));
            box-shadow:0 10px 28px rgba(17,24,39,.06);
        }
        .soft-card{
            border:1px solid #e8edf3;
            border-radius:20px;
            box-shadow:0 8px 24px rgba(17,24,39,.05);
            background:#fff;
        }
        .section-title{
            font-size:1rem;
            font-weight:700;
            margin-bottom:.9rem;
            display:flex;
            align-items:center;
            gap:.55rem;
        }
        .section-title i{
            color:#0d6efd;
        }
        .kv-label{
            font-size:.82rem;
            color:#6b7280;
            font-weight:600;
            margin-bottom:.2rem;
        }
        .kv-value{
            font-size:.95rem;
            font-weight:500;
            word-break:break-word;
        }
    </style>
</head>
<body>

<div class="page-wrap">

    <?php if ($error): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle me-1"></i><?= h($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($ok === 1): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check-circle me-1"></i> Reemplazo capturado correctamente.
        </div>
    <?php endif; ?>

    <?php if ($okexc === 1): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check-circle me-1"></i> Solicitud de excepción enviada correctamente.
        </div>
    <?php endif; ?>

    <div class="hero p-4 p-md-5 mb-4">
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
            <div>
                <h3 class="fw-bold mb-1">
                    <i class="bi bi-arrow-repeat me-2"></i>Capturar equipo de reemplazo
                </h3>
                <div class="text-muted">
                    Folio <strong><?= h($caso['folio']) ?></strong> • Cliente <strong><?= h($caso['cliente_nombre']) ?></strong>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <a href="garantias_detalle.php?id=<?= (int)$caso['id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Volver al detalle
                </a>
                <a href="garantias_mis_casos.php" class="btn btn-outline-primary">
                    <i class="bi bi-list me-1"></i>Listado
                </a>
            </div>
        </div>
    </div>

    <?php if ($hayExcepcionPendiente): ?>
        <div class="alert alert-warning border-0 shadow-sm mb-4">
            <i class="bi bi-hourglass-split me-1"></i>
            Ya existe una <strong>solicitud de excepción pendiente</strong> para este caso.
            Espera la respuesta de logística antes de enviar otra.
        </div>
    <?php elseif ($hayExcepcionAutorizada): ?>
        <div class="alert alert-success border-0 shadow-sm mb-4">
            <i class="bi bi-check-circle me-1"></i>
            Ya existe una <strong>excepción autorizada</strong> para este caso.
            Desde esta pantalla puedes aplicar el reemplazo autorizado.
            <?php if ($imeiExcepcionAutorizada !== ''): ?>
                <div class="mt-2">
                    <strong>IMEI autorizado:</strong> <?= h($imeiExcepcionAutorizada) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($excCols['motivo_respuesta']) && !empty($excepcionActual[$excCols['motivo_respuesta']])): ?>
                <div class="mt-2 small text-dark">
                    <strong>Respuesta:</strong> <?= nl2br(h($excepcionActual[$excCols['motivo_respuesta']])) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php elseif ($hayExcepcionRechazada): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4">
            <i class="bi bi-x-circle me-1"></i>
            La última solicitud de excepción fue <strong>rechazada</strong>.
            Puedes buscar otro equipo o volver a solicitar una nueva excepción con otro equipo si es necesario.
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-upc-scan"></i>
                    <span>Equipo original</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="kv-label">Marca / Modelo</div>
                        <div class="kv-value"><?= h(trim(($caso['marca'] ?? '') . ' ' . ($caso['modelo'] ?? ''))) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">Color / Capacidad</div>
                        <div class="kv-value">
                            <?= h($caso['color']) ?><?= !empty($caso['capacidad']) ? ' • ' . h($caso['capacidad']) : '' ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="kv-label">IMEI 1 original</div>
                        <div class="kv-value"><?= h($caso['imei_original']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">IMEI 2 original</div>
                        <div class="kv-value"><?= !empty($caso['imei2_original']) ? h($caso['imei2_original']) : '-' ?></div>
                    </div>

                    <div class="col-md-6">
                        <div class="kv-label">Sucursal del caso</div>
                        <div class="kv-value"><?= h($caso['sucursal_nombre'] ?? '') ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">Estado del caso</div>
                        <div class="kv-value"><?= h($caso['estado']) ?></div>
                    </div>

                    <div class="col-md-6">
                        <div class="kv-label">Precio venta original</div>
                        <div class="kv-value"><?= h(money_fmt($caso['precio_original_resuelto'] ?? null)) ?></div>
                    </div>
                </div>
            </div>

            <?php if ($reemplazoActual): ?>
                <div class="soft-card p-4">
                    <div class="section-title">
                        <i class="bi bi-box-seam"></i>
                        <span>Reemplazo actual registrado</span>
                    </div>

                    <div class="alert alert-warning border-0 shadow-sm">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Este caso ya tiene reemplazo registrado. Si guardas uno nuevo, se reemplazará el anterior.
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="kv-label">IMEI reemplazo</div>
                            <div class="kv-value"><?= h($reemplazoActual['imei_reemplazo']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="kv-label">IMEI 2 reemplazo</div>
                            <div class="kv-value"><?= !empty($reemplazoActual['imei2_reemplazo']) ? h($reemplazoActual['imei2_reemplazo']) : '-' ?></div>
                        </div>

                        <div class="col-md-6">
                            <div class="kv-label">Equipo</div>
                            <div class="kv-value">
                                <?= h(trim(($reemplazoActual['marca_reemplazo'] ?? '') . ' ' . ($reemplazoActual['modelo_reemplazo'] ?? ''))) ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="kv-label">Color / Capacidad</div>
                            <div class="kv-value">
                                <?= h($reemplazoActual['color_reemplazo']) ?><?= !empty($reemplazoActual['capacidad_reemplazo']) ? ' • ' . h($reemplazoActual['capacidad_reemplazo']) : '' ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-6">
            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-search"></i>
                    <span>Buscar equipo de reemplazo</span>
                </div>

                <form method="get" class="row g-3">
                    <input type="hidden" name="id" value="<?= (int)$caso['id'] ?>">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">IMEI del equipo nuevo</label>
                        <input type="text" name="imei_buscar" class="form-control" value="<?= h($imeiBusqueda) ?>" placeholder="Captura IMEI 1 o IMEI 2" required>
                    </div>
                    <div class="col-md-4 d-grid">
                        <label class="form-label fw-semibold d-none d-md-block">&nbsp;</label>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search me-1"></i>Buscar
                        </button>
                    </div>
                </form>
            </div>

            <div class="soft-card p-4">
                <div class="section-title">
                    <i class="bi bi-box"></i>
                    <span>Equipo encontrado</span>
                </div>

                <?php if ($equipoNuevo): ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="kv-label">Marca / Modelo</div>
                            <div class="kv-value"><?= h(trim(($equipoNuevo['marca'] ?? '') . ' ' . ($equipoNuevo['modelo'] ?? ''))) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="kv-label">Color / Capacidad</div>
                            <div class="kv-value">
                                <?= h($equipoNuevo['color']) ?><?= !empty($equipoNuevo['capacidad']) ? ' • ' . h($equipoNuevo['capacidad']) : '' ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="kv-label">IMEI 1</div>
                            <div class="kv-value"><?= h($equipoNuevo['imei1']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="kv-label">IMEI 2</div>
                            <div class="kv-value"><?= !empty($equipoNuevo['imei2']) ? h($equipoNuevo['imei2']) : '-' ?></div>
                        </div>

                        <div class="col-md-4">
                            <div class="kv-label">Inventario ID</div>
                            <div class="kv-value"><?= h($equipoNuevo['inventario_id']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Sucursal</div>
                            <div class="kv-value"><?= h($equipoNuevo['sucursal_nombre'] ?? '') ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Estatus inventario</div>
                            <div class="kv-value"><?= h($equipoNuevo['estatus_inventario']) ?></div>
                        </div>

                        <div class="col-md-6">
                            <div class="kv-label">Precio venta original</div>
                            <div class="kv-value"><?= h(money_fmt($precioOriginalVista)) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="kv-label">Precio lista reemplazo</div>
                            <div class="kv-value"><?= h(money_fmt($precioReemplazoVista)) ?></div>
                        </div>

                        <div class="col-12">
                            <?php if ($precioOriginalVista === null): ?>
                                <div class="alert alert-warning border-0 shadow-sm mb-0">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    No se pudo obtener el precio real del equipo original desde detalle_venta.
                                </div>
                            <?php elseif ($precioReemplazoVista === null): ?>
                                <div class="alert alert-warning border-0 shadow-sm mb-0">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    El equipo de reemplazo no tiene precio lista registrado.
                                </div>
                            <?php elseif ($cumplePrecio): ?>
                                <div class="alert alert-success border-0 shadow-sm mb-0">
                                    <i class="bi bi-check-circle me-1"></i>
                                    El reemplazo cumple la política de garantía: su precio lista es igual o menor al precio real de venta del original.
                                </div>
                            <?php elseif ($puedeAplicarPorExcepcion): ?>
                                <div class="alert alert-success border-0 shadow-sm mb-0">
                                    <i class="bi bi-patch-check me-1"></i>
                                    <?= h($mensajeAutorizacionExcepcion) ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-danger border-0 shadow-sm mb-0">
                                    <i class="bi bi-x-circle me-1"></i>
                                    El reemplazo no cumple la política: su precio lista es mayor al precio real del equipo original.
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!$cumplePrecio && $hayExcepcionAutorizada && $mensajeAutorizacionExcepcion): ?>
                            <div class="col-12">
                                <div class="alert <?= $puedeAplicarPorExcepcion ? 'alert-info' : 'alert-warning' ?> border-0 shadow-sm mb-0">
                                    <i class="bi bi-info-circle me-1"></i>
                                    <?= h($mensajeAutorizacionExcepcion) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($cumplePrecio || $puedeAplicarPorExcepcion): ?>
                        <form method="post" class="mt-3">
                            <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                            <input type="hidden" name="imei_buscar" value="<?= h($imeiBusqueda) ?>">
                            <input type="hidden" name="guardar_reemplazo" value="1">

                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Observaciones</label>
                                    <textarea name="observaciones" class="form-control" rows="3" placeholder="Notas sobre el reemplazo, condición del equipo, autorización, etc."></textarea>
                                </div>

                                <div class="col-12 d-grid">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="bi bi-check2-circle me-1"></i>
                                        <?= $puedeAplicarPorExcepcion && !$cumplePrecio ? 'Aplicar reemplazo autorizado' : 'Guardar reemplazo' ?>
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php else: ?>
                        <?php if ($hayExcepcionPendiente): ?>
                            <div class="mt-3">
                                <div class="alert alert-warning border-0 shadow-sm mb-0">
                                    <i class="bi bi-hourglass-split me-1"></i>
                                    Ya existe una solicitud de excepción pendiente para este caso. No puedes enviar otra por ahora.
                                </div>
                            </div>
                        <?php elseif ($hayExcepcionAutorizada): ?>
                            <div class="mt-3">
                                <div class="alert alert-warning border-0 shadow-sm mb-0">
                                    <i class="bi bi-shield-lock me-1"></i>
                                    Ya existe una excepción autorizada, pero no corresponde al equipo buscado. Debes aplicar el IMEI autorizado.
                                </div>
                            </div>
                        <?php else: ?>
                            <form method="post" action="garantias_solicitar_excepcion.php" class="mt-3">
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="imei_propuesto" value="<?= h($equipoNuevo['imei1'] ?? $imeiBusqueda) ?>">

                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Motivo de la solicitud</label>
                                        <textarea
                                            name="motivo_solicitud"
                                            class="form-control"
                                            rows="3"
                                            placeholder="Explica por qué solicitas autorización para este equipo de mayor valor"
                                            required
                                        ></textarea>
                                    </div>

                                    <div class="col-12">
                                        <div class="alert alert-warning border-0 shadow-sm mb-0">
                                            <i class="bi bi-shield-exclamation me-1"></i>
                                            Este equipo requiere autorización porque supera el valor permitido. Puedes enviar la solicitud desde aquí.
                                        </div>
                                    </div>

                                    <div class="col-12 d-grid gap-2">
                                        <button type="submit" class="btn btn-warning btn-lg" <?= !$puedeSolicitarExcepcion ? 'disabled' : '' ?>>
                                            <i class="bi bi-send me-1"></i>Solicitar excepción
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="text-muted">
                        Busca un IMEI para localizar el equipo de reemplazo disponible.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>