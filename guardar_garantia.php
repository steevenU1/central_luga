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
$ROLES_PERMITIDOS = [
    'Admin', 'Administrador',
    'Gerente', 'GerenteZona',
    'Ejecutivo', 'Logistica',
    'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para guardar garantías.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

/* =========================================================
   HELPERS
========================================================= */
function htrim($v): string {
    return trim((string)$v);
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

function yn_to_nullable_int($v): ?int {
    if ($v === '' || $v === null) return null;
    return ((string)$v === '1') ? 1 : 0;
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

function diff_days_from_today(?string $dateStr): ?int {
    if (!$dateStr) return null;
    $base = strtotime($dateStr . ' 00:00:00');
    if (!$base) return null;
    $today = strtotime(date('Y-m-d') . ' 00:00:00');
    return (int)floor(($today - $base) / 86400);
}

function bindParamsDynamic(mysqli_stmt $stmt, string $types, array &$params): void {
    $refs = [];
    $refs[] = $types;

    foreach ($params as $k => $v) {
        $refs[] = &$params[$k];
    }

    array_unshift($refs, $stmt);
    call_user_func_array('mysqli_stmt_bind_param', $refs);
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
        throw new Exception("Error al insertar en garantias_eventos: " . $st->error);
    }

    $st->close();
}

function generar_folio_garantia(mysqli $conn): string {
    $prefijo = 'GAR-' . date('Ymd') . '-';

    $sql = "SELECT folio
            FROM garantias_casos
            WHERE folio LIKE CONCAT(?, '%')
            ORDER BY id DESC
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) {
        throw new Exception("Error en prepare() al generar folio: " . $conn->error);
    }

    $st->bind_param("s", $prefijo);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $next = 1;
    if ($row && !empty($row['folio']) && preg_match('/(\d{4})$/', $row['folio'], $m)) {
        $next = ((int)$m[1]) + 1;
    }

    return $prefijo . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function dictaminar_backend(array $data): array {
    $origen = (string)($data['origen'] ?? '');
    $fechaCompra = $data['fecha_compra'] ?? null;
    $garantiaAbiertaId = $data['garantia_abierta_id'] ?? null;

    $danoFisico = $data['check_dano_fisico'] ?? null;
    $humedad = $data['check_humedad'] ?? null;
    $bloqueo = $data['check_bloqueo_patron_google'] ?? null;
    $appFin = $data['check_app_financiera'] ?? null;

    $diasCompra = diff_days_from_today($fechaCompra);

    if (!$origen && empty($data['imei_original'])) {
        return [
            'dictamen_preliminar' => 'revision_logistica',
            'motivo_no_procede'   => null,
            'detalle_no_procede'  => 'No existe información suficiente para calcular el dictamen.',
            'estado'              => 'capturada',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0
        ];
    }

    if (!empty($garantiaAbiertaId)) {
        return [
            'dictamen_preliminar' => 'no_procede',
            'motivo_no_procede'   => 'GARANTIA_PREVIA_ABIERTA',
            'detalle_no_procede'  => 'El IMEI ya cuenta con una garantía activa en proceso.',
            'estado'              => 'capturada',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0
        ];
    }

    if ($origen === 'manual') {
        return [
            'dictamen_preliminar' => 'imei_no_localizado',
            'motivo_no_procede'   => 'IMEI_NO_LOCALIZADO',
            'detalle_no_procede'  => 'No se encontró el IMEI en ventas ni en reemplazos previos. Requiere validación manual.',
            'estado'              => 'capturada',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0
        ];
    }

    if ((string)$danoFisico === '1') {
        return [
            'dictamen_preliminar' => 'no_procede',
            'motivo_no_procede'   => 'DANO_FISICO',
            'detalle_no_procede'  => 'Se detectó daño físico imputable al cliente.',
            'estado'              => 'capturada',
            'es_reparable'        => 1,
            'requiere_cotizacion' => 1
        ];
    }

    if ((string)$humedad === '1') {
        return [
            'dictamen_preliminar' => 'no_procede',
            'motivo_no_procede'   => 'HUMEDAD',
            'detalle_no_procede'  => 'Se detectó humedad en el equipo.',
            'estado'              => 'capturada',
            'es_reparable'        => 1,
            'requiere_cotizacion' => 1
        ];
    }

    if ((string)$bloqueo === '1') {
        return [
            'dictamen_preliminar' => 'no_procede',
            'motivo_no_procede'   => 'BLOQUEO_CUENTA',
            'detalle_no_procede'  => 'El bloqueo por patrón, PIN o cuenta no forma parte de la garantía.',
            'estado'              => 'capturada',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0
        ];
    }

    if ((string)$appFin === '0') {
        return [
            'dictamen_preliminar' => 'revision_logistica',
            'motivo_no_procede'   => null,
            'detalle_no_procede'  => 'La app financiera no está presente y requiere validación adicional por logística.',
            'estado'              => 'en_revision_logistica',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0
        ];
    }

    if ($diasCompra !== null && $diasCompra > 90) {
        return [
            'dictamen_preliminar' => 'no_procede',
            'motivo_no_procede'   => 'GARANTIA_VENCIDA',
            'detalle_no_procede'  => "El equipo supera el periodo de cobertura sugerido ({$diasCompra} días desde compra).",
            'estado'              => 'capturada',
            'es_reparable'        => 1,
            'requiere_cotizacion' => 1
        ];
    }

    if ($diasCompra !== null && $diasCompra <= 30 && (string)$danoFisico !== '1' && (string)$humedad !== '1') {
        return [
            'dictamen_preliminar' => 'procede',
            'motivo_no_procede'   => null,
            'detalle_no_procede'  => 'Cumple condiciones iniciales para garantía. Requiere validación final de logística.',
            'estado'              => 'en_revision_logistica',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0
        ];
    }

    return [
        'dictamen_preliminar' => 'revision_logistica',
        'motivo_no_procede'   => null,
        'detalle_no_procede'  => 'No se detectó rechazo automático, pero el caso requiere validación logística.',
        'estado'              => 'en_revision_logistica',
        'es_reparable'        => 0,
        'requiere_cotizacion' => 0
    ];
}

/* =========================================================
   VALIDAR TABLAS
========================================================= */
if (!table_exists($conn, 'garantias_casos') || !table_exists($conn, 'garantias_eventos')) {
    exit('No existen las tablas del módulo de garantías. Ejecuta primero el SQL del módulo.');
}

/* =========================================================
   LEER POST
========================================================= */
$idUsuarioSesion = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursalSesion = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUsuarioSesion = (string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario');
$rolUsuarioSesion = $ROL;

$imeiBusqueda = sanitize_imei($_POST['imei_busqueda'] ?? '');
$origen = htrim($_POST['origen'] ?? 'manual');

$idVenta = int_or_null($_POST['id_venta'] ?? null);
$idDetalleVenta = int_or_null($_POST['id_detalle_venta'] ?? null);
$idProducto = int_or_null($_POST['id_producto'] ?? null);
$idGarantiaPadre = int_or_null($_POST['id_garantia_padre'] ?? null);
$idGarantiaRaiz = int_or_null($_POST['id_garantia_raiz'] ?? null);

$clienteNombre = htrim($_POST['cliente_nombre'] ?? '');
$clienteTelefono = null_if_empty($_POST['cliente_telefono'] ?? null);
$clienteCorreo = null_if_empty($_POST['cliente_correo'] ?? null);

$marca = null_if_empty($_POST['marca'] ?? null);
$modelo = null_if_empty($_POST['modelo'] ?? null);
$color = null_if_empty($_POST['color'] ?? null);
$capacidad = null_if_empty($_POST['capacidad'] ?? null);

$imeiOriginal = sanitize_imei($_POST['imei_original'] ?? $imeiBusqueda);
$imei2Original = sanitize_imei($_POST['imei2_original'] ?? '');
if ($imei2Original === '') {
    $imei2Original = null;
}

$fechaCompra = null_if_empty($_POST['fecha_compra'] ?? null);
$tagVenta = null_if_empty($_POST['tag_venta'] ?? null);
$modalidadVenta = null_if_empty($_POST['modalidad_venta'] ?? null);
$financiera = null_if_empty($_POST['financiera_hidden'] ?? $_POST['financiera'] ?? null);

$fechaRecepcion = null_if_empty($_POST['fecha_recepcion'] ?? date('Y-m-d'));
$tipoAtencion = null_if_empty($_POST['tipo_atencion'] ?? 'garantia');

$descripcionFalla = htrim($_POST['descripcion_falla'] ?? '');
$observacionesTienda = null_if_empty($_POST['observaciones_tienda'] ?? null);

$checkEncendido = yn_to_nullable_int($_POST['check_encendido'] ?? null);
$checkDanoFisico = yn_to_nullable_int($_POST['check_dano_fisico'] ?? null);
$checkHumedad = yn_to_nullable_int($_POST['check_humedad'] ?? null);
$checkPantalla = yn_to_nullable_int($_POST['check_pantalla'] ?? null);
$checkCamara = yn_to_nullable_int($_POST['check_camara'] ?? null);
$checkBocinaMicrofono = yn_to_nullable_int($_POST['check_bocina_microfono'] ?? null);
$checkPuertoCarga = yn_to_nullable_int($_POST['check_puerto_carga'] ?? null);
$checkAppFinanciera = yn_to_nullable_int($_POST['check_app_financiera'] ?? null);
$checkBloqueoPatronGoogle = yn_to_nullable_int($_POST['check_bloqueo_patron_google'] ?? null);

$requiereCotizacionPost = isset($_POST['requiere_cotizacion']) ? (int)$_POST['requiere_cotizacion'] : 0;
$prioridad = null_if_empty($_POST['prioridad'] ?? 'normal');
$garantiaAbiertaId = int_or_null($_POST['garantia_abierta_id'] ?? null);

/* =========================================================
   VALIDACIONES
========================================================= */
$errores = [];

if ($imeiOriginal === '') {
    $errores[] = 'No se recibió un IMEI válido.';
}

if ($descripcionFalla === '') {
    $errores[] = 'Debes capturar la descripción de la falla.';
}

if ($fechaRecepcion === null) {
    $errores[] = 'La fecha de recepción es obligatoria.';
}

if ($origen !== 'manual' && $clienteNombre === '') {
    $errores[] = 'No se recuperó el nombre del cliente desde la venta. Revisa el IMEI consultado.';
}

if ($garantiaAbiertaId) {
    $errores[] = 'El IMEI ya cuenta con una garantía abierta.';
}

if (!empty($errores)) {
    http_response_code(422);
    echo "<h3>No se pudo guardar la garantía</h3><ul>";
    foreach ($errores as $e) {
        echo "<li>" . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . "</li>";
    }
    echo "</ul><p><a href='javascript:history.back()'>Volver</a></p>";
    exit();
}

/* =========================================================
   CALCULAR NIVEL / RAIZ
========================================================= */
$nivelReincidencia = 0;

if ($idGarantiaPadre) {
    $sqlPadre = "SELECT id, id_garantia_raiz, nivel_reincidencia
                 FROM garantias_casos
                 WHERE id = ?
                 LIMIT 1";
    $stPadre = $conn->prepare($sqlPadre);
    if (!$stPadre) {
        throw new Exception("Error en prepare() de consulta padre: " . $conn->error);
    }

    $stPadre->bind_param("i", $idGarantiaPadre);
    $stPadre->execute();
    $padre = $stPadre->get_result()->fetch_assoc();
    $stPadre->close();

    if ($padre) {
        $nivelReincidencia = ((int)$padre['nivel_reincidencia']) + 1;
        if (!$idGarantiaRaiz) {
            $idGarantiaRaiz = !empty($padre['id_garantia_raiz']) ? (int)$padre['id_garantia_raiz'] : (int)$padre['id'];
        }
    }
}

/* =========================================================
   DICTAMEN BACKEND
========================================================= */
$dictamen = dictaminar_backend([
    'origen' => $origen,
    'fecha_compra' => $fechaCompra,
    'garantia_abierta_id' => $garantiaAbiertaId,
    'imei_original' => $imeiOriginal,
    'check_dano_fisico' => $checkDanoFisico,
    'check_humedad' => $checkHumedad,
    'check_bloqueo_patron_google' => $checkBloqueoPatronGoogle,
    'check_app_financiera' => $checkAppFinanciera,
]);

$dictamenPreliminar = $dictamen['dictamen_preliminar'];
$motivoNoProcede = $dictamen['motivo_no_procede'];
$detalleNoProcede = $dictamen['detalle_no_procede'];
$estadoInicial = $dictamen['estado'];
$esReparable = (int)$dictamen['es_reparable'];
$requiereCotizacion = $requiereCotizacionPost === 1 ? 1 : (int)$dictamen['requiere_cotizacion'];

/* =========================================================
   TIPO ORIGEN
========================================================= */
$tipoOrigen = 'manual';
if ($origen === 'venta') {
    $tipoOrigen = 'venta';
} elseif ($origen === 'reemplazo_garantia') {
    $tipoOrigen = 'reemplazo_garantia';
}

/* =========================================================
   INSERT
========================================================= */
$conn->begin_transaction();

try {
    $folio = generar_folio_garantia($conn);

    $sql = "INSERT INTO garantias_casos (
                folio,
                tipo_origen,
                id_venta,
                id_detalle_venta,
                id_garantia_padre,
                id_garantia_raiz,
                nivel_reincidencia,
                id_sucursal,
                id_usuario_captura,
                cliente_nombre,
                cliente_telefono,
                cliente_correo,
                id_producto_original,
                marca,
                modelo,
                color,
                capacidad,
                imei_original,
                imei2_original,
                fecha_compra,
                tag_venta,
                modalidad_venta,
                financiera,
                descripcion_falla,
                observaciones_tienda,
                check_encendido,
                check_dano_fisico,
                check_humedad,
                check_pantalla,
                check_camara,
                check_bocina_microfono,
                check_puerto_carga,
                check_app_financiera,
                check_bloqueo_patron_google,
                dictamen_preliminar,
                motivo_no_procede,
                detalle_no_procede,
                estado,
                es_reparable,
                requiere_cotizacion,
                fecha_recepcion,
                fecha_dictamen,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, NOW(), NOW(), NOW()
            )";

    $st = $conn->prepare($sql);
    if (!$st) {
        throw new Exception("Error en prepare() de garantias_casos: " . $conn->error);
    }

    $params = [
        $folio,
        $tipoOrigen,
        $idVenta,
        $idDetalleVenta,
        $idGarantiaPadre,
        $idGarantiaRaiz,
        $nivelReincidencia,
        $idSucursalSesion,
        $idUsuarioSesion,

        $clienteNombre,
        $clienteTelefono,
        $clienteCorreo,

        $idProducto,
        $marca,
        $modelo,
        $color,
        $capacidad,
        $imeiOriginal,
        $imei2Original,

        $fechaCompra,
        $tagVenta,
        $modalidadVenta,
        $financiera,

        $descripcionFalla,
        $observacionesTienda,

        $checkEncendido,
        $checkDanoFisico,
        $checkHumedad,
        $checkPantalla,
        $checkCamara,
        $checkBocinaMicrofono,
        $checkPuertoCarga,
        $checkAppFinanciera,
        $checkBloqueoPatronGoogle,

        $dictamenPreliminar,
        $motivoNoProcede,
        $detalleNoProcede,
        $estadoInicial,

        $esReparable,
        $requiereCotizacion,

        $fechaRecepcion
    ];

    $types = '';
    foreach ($params as $p) {
        $types .= is_int($p) ? 'i' : 's';
    }

    bindParamsDynamic($st, $types, $params);

    if (!$st->execute()) {
        throw new Exception("Error al ejecutar INSERT en garantias_casos: " . $st->error);
    }

    $idGarantia = (int)$st->insert_id;
    $st->close();

    if (!$idGarantiaRaiz) {
        $sqlRaiz = "UPDATE garantias_casos
                    SET id_garantia_raiz = ?
                    WHERE id = ?";
        $stRaiz = $conn->prepare($sqlRaiz);
        if (!$stRaiz) {
            throw new Exception("Error en prepare() de update raíz: " . $conn->error);
        }

        $stRaiz->bind_param("ii", $idGarantia, $idGarantia);
        if (!$stRaiz->execute()) {
            throw new Exception("Error al actualizar id_garantia_raiz: " . $stRaiz->error);
        }
        $stRaiz->close();

        $idGarantiaRaiz = $idGarantia;
    }

    registrar_evento(
        $conn,
        $idGarantia,
        'creacion',
        null,
        $estadoInicial,
        'Se creó la solicitud de garantía / reparación.',
        [
            'folio' => $folio,
            'tipo_origen' => $tipoOrigen,
            'tipo_atencion' => $tipoAtencion,
            'prioridad' => $prioridad,
            'imei_original' => $imeiOriginal,
            'imei2_original' => $imei2Original,
            'dictamen_preliminar' => $dictamenPreliminar
        ],
        $idUsuarioSesion,
        $nombreUsuarioSesion,
        $rolUsuarioSesion
    );

    registrar_evento(
        $conn,
        $idGarantia,
        'recepcion',
        null,
        $estadoInicial,
        'Se registró la recepción inicial del equipo en tienda.',
        [
            'fecha_recepcion' => $fechaRecepcion,
            'cliente_nombre' => $clienteNombre,
            'descripcion_falla' => $descripcionFalla
        ],
        $idUsuarioSesion,
        $nombreUsuarioSesion,
        $rolUsuarioSesion
    );

    registrar_evento(
        $conn,
        $idGarantia,
        'dictamen_preliminar',
        null,
        $estadoInicial,
        'El sistema calculó el dictamen preliminar del caso.',
        [
            'dictamen_preliminar' => $dictamenPreliminar,
            'motivo_no_procede' => $motivoNoProcede,
            'detalle_no_procede' => $detalleNoProcede,
            'es_reparable' => $esReparable,
            'requiere_cotizacion' => $requiereCotizacion
        ],
        $idUsuarioSesion,
        $nombreUsuarioSesion,
        $rolUsuarioSesion
    );

    $conn->commit();

    header("Location: garantias_detalle.php?id=" . $idGarantia . "&ok=1");
    exit();

} catch (Throwable $e) {
    $conn->rollback();

    http_response_code(500);
    echo "<h3>Error al guardar la garantía</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
    echo "<p><a href='javascript:history.back()'>Volver</a></p>";
    exit();
}