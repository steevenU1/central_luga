<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

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

/* =========================================================
   VALIDAR TABLAS
========================================================= */
$required = ['garantias_casos', 'garantias_reemplazos', 'garantias_eventos', 'inventario', 'productos'];
foreach ($required as $tb) {
    if (!table_exists($conn, $tb)) {
        exit("No existe la tabla requerida: " . h($tb));
    }
}

/* =========================================================
   MAPEO DINÁMICO inventario / productos
========================================================= */
$invCols = [
    'id'         => first_existing_column($conn, 'inventario', ['id']),
    'id_producto'=> first_existing_column($conn, 'inventario', ['id_producto']),
    'id_sucursal'=> first_existing_column($conn, 'inventario', ['id_sucursal']),
    'estatus'    => first_existing_column($conn, 'inventario', ['estatus', 'estado']),
];

$prodCols = [
    'id'         => first_existing_column($conn, 'productos', ['id']),
    'marca'      => first_existing_column($conn, 'productos', ['marca']),
    'modelo'     => first_existing_column($conn, 'productos', ['modelo']),
    'color'      => first_existing_column($conn, 'productos', ['color']),
    'capacidad'  => first_existing_column($conn, 'productos', ['capacidad', 'almacenamiento']),
    'imei1'      => first_existing_column($conn, 'productos', ['imei1']),
    'imei2'      => first_existing_column($conn, 'productos', ['imei2']),
];

if (!$invCols['id'] || !$invCols['id_producto'] || !$invCols['id_sucursal'] || !$invCols['estatus']) {
    exit('La tabla inventario no tiene las columnas mínimas requeridas.');
}
if (!$prodCols['id'] || !$prodCols['imei1']) {
    exit('La tabla productos no tiene las columnas mínimas requeridas.');
}

/* =========================================================
   CARGAR CASO
========================================================= */
$idGarantia = (int)($_GET['id'] ?? $_POST['id_garantia'] ?? 0);
if ($idGarantia <= 0) {
    exit('ID de garantía inválido.');
}

$sqlCaso = "SELECT gc.*, s.nombre AS sucursal_nombre
            FROM garantias_casos gc
            LEFT JOIN sucursales s ON s.id = gc.id_sucursal
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
   BUSCAR EQUIPO REEMPLAZO
========================================================= */
$imeiBusqueda = sanitize_imei($_GET['imei_buscar'] ?? $_POST['imei_buscar'] ?? '');
$equipoNuevo = null;
$error = null;
$ok = isset($_GET['ok']) ? (int)$_GET['ok'] : 0;

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
   GUARDAR
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_reemplazo'])) {
    $imeiBusquedaPost = sanitize_imei($_POST['imei_buscar'] ?? '');
    $observaciones = null_if_empty($_POST['observaciones'] ?? null);

    if ($imeiBusquedaPost === '') {
        $error = 'Debes capturar el IMEI del equipo de reemplazo.';
    } else {
        // buscar de nuevo seguro
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
                    " . ($prodCols['imei2'] ? "p.`{$prodCols['imei2']}`" : "NULL") . " AS imei2
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
            $estatusInv = strtolower(trim((string)($equipoNuevo['estatus_inventario'] ?? '')));
            $permitidos = ['disponible', 'stock', 'activo'];

            if (!in_array($estatusInv, $permitidos, true)) {
                $error = 'El equipo seleccionado no está disponible en inventario. Estatus actual: ' . ($equipoNuevo['estatus_inventario'] ?? '-');
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
            // validar que no esté usado ya en otro reemplazo
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
    }

    if (!$error && $equipoNuevo) {
        $conn->begin_transaction();

        try {
            $estadoAnterior = (string)$caso['estado'];

            // upsert en garantias_reemplazos
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
                'id_garantia'                => $idGarantia,
                'id_producto_original'       => int_or_null($caso['id_producto_original'] ?? null),
                'id_producto_reemplazo'      => (int)$equipoNuevo['id_producto'],
                'imei_original'              => $caso['imei_original'],
                'imei2_original'             => $caso['imei2_original'],
                'imei_reemplazo'             => $equipoNuevo['imei1'],
                'imei2_reemplazo'            => null_if_empty($equipoNuevo['imei2'] ?? null),
                'marca_reemplazo'            => null_if_empty($equipoNuevo['marca'] ?? null),
                'modelo_reemplazo'           => null_if_empty($equipoNuevo['modelo'] ?? null),
                'color_reemplazo'            => null_if_empty($equipoNuevo['color'] ?? null),
                'capacidad_reemplazo'        => null_if_empty($equipoNuevo['capacidad'] ?? null),
                'id_inventario_reemplazo'    => (int)$equipoNuevo['inventario_id'],
                'estatus_inventario_anterior'=> $equipoNuevo['estatus_inventario'],
                'estatus_inventario_nuevo'   => 'Garantia',
                'id_usuario_registro'        => $ID_USUARIO,
                'observaciones'              => $observaciones,
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

            // actualizar inventario a Garantia
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

            // actualizar caso
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
                ],
                $ID_USUARIO,
                $NOMBRE_USUARIO,
                $ROL
            );

            $conn->commit();
            header("Location: garantias_detalle.php?id={$idGarantia}&oklog=1");
            exit();

        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}
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
                        <div class="kv-value"><?= h($caso['imei2_original']) ?: '-' ?></div>
                    </div>

                    <div class="col-md-6">
                        <div class="kv-label">Sucursal del caso</div>
                        <div class="kv-value"><?= h($caso['sucursal_nombre'] ?? '') ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">Estado del caso</div>
                        <div class="kv-value"><?= h($caso['estado']) ?></div>
                    </div>
                </div>
            </div>

            <?php if ($reemplazoActual): ?>
                <div class="soft-card p-4">
                    <div class="section-title">
                        <i class="bi bi-box-seam"></i>
                        <span>Reemplazo actual registrado</span>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="kv-label">IMEI reemplazo</div>
                            <div class="kv-value"><?= h($reemplazoActual['imei_reemplazo']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="kv-label">IMEI 2 reemplazo</div>
                            <div class="kv-value"><?= h($reemplazoActual['imei2_reemplazo']) ?: '-' ?></div>
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
                    <form method="post">
                        <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                        <input type="hidden" name="imei_buscar" value="<?= h($imeiBusqueda) ?>">
                        <input type="hidden" name="guardar_reemplazo" value="1">

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
                                <div class="kv-value"><?= h($equipoNuevo['imei2']) ?: '-' ?></div>
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

                            <div class="col-12">
                                <label class="form-label fw-semibold">Observaciones</label>
                                <textarea name="observaciones" class="form-control" rows="3" placeholder="Notas sobre el reemplazo, condición del equipo, autorización, etc."></textarea>
                            </div>

                            <div class="col-12 d-grid">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bi bi-check2-circle me-1"></i>Guardar reemplazo
                                </button>
                            </div>
                        </div>
                    </form>
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